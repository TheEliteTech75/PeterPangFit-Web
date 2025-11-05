<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';
require_once __DIR__ . '/ppf_subheader.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$currentRole = $USER_ROLE ?? ($_SESSION['role'] ?? 'guest');
$roleKey = ppf_role_key($currentRole);
$actorId = (int)($USER_ID ?? ($_SESSION['user_id'] ?? 0));

if (!in_array($roleKey, ['trainer', 'trainer_admin', 'coach'], true) && !ppf_is_admin_role($currentRole)) {
    require_once __DIR__ . '/access_denied.php';
    exit;
}

$trainerId = $actorId;
$isAdmin = ppf_is_admin_role($currentRole);
$isTrainerAdmin = ($roleKey === 'trainer_admin');
$now = new DateTimeImmutable('now');

ppf_trainer_sessions_ensure_schema($conn);

$pricingMode = ppf_trainer_sessions_pricing_mode($conn);
$catalogPackages = ppf_trainer_sessions_fetch_catalog_packages($conn, [
    'mode' => $pricingMode,
    'trainer_id' => $trainerId,
]);

function ts_format_currency($amount): string
{
    return ppf_trainer_sessions_format_money($amount);
}

function ts_format_datetime(?string $iso, string $format = 'M j, Y g:i A'): string
{
    if (!$iso) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->format($format);
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

function ts_format_date(?string $iso): string
{
    return ts_format_datetime($iso, 'M j, Y');
}

function ts_format_time(?string $iso): string
{
    return ts_format_datetime($iso, 'g:i A');
}

function ts_format_height(?int $feet = null, ?int $inches = null): string
{
    if ($feet === null && $inches === null) {
        return '—';
    }
    $parts = [];
    if ($feet !== null && $feet > 0) {
        $parts[] = $feet . "'";
    }
    if ($inches !== null && $inches > 0) {
        $parts[] = $inches . '"';
    }
    return $parts ? implode(' ', $parts) : '—';
}

function ts_format_phone(?string $phone): string
{
    $normalized = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($normalized) === 10) {
        return sprintf('(%s) %s-%s', substr($normalized, 0, 3), substr($normalized, 3, 3), substr($normalized, 6));
    }
    if (strlen($normalized) === 11 && $normalized[0] === '1') {
        return sprintf('+1 (%s) %s-%s', substr($normalized, 1, 3), substr($normalized, 4, 3), substr($normalized, 7));
    }
    return $phone ? (string)$phone : '—';
}

function ts_calculate_age(?string $isoBirthdate): ?int
{
    if (!$isoBirthdate) {
        return null;
    }
    try {
        $birth = new DateTimeImmutable($isoBirthdate);
        $today = new DateTimeImmutable('today');
        return $birth->diff($today)->y;
    } catch (Throwable $e) {
        return null;
    }
}

function ts_session_status_label(array $session, DateTimeImmutable $now): string
{
    $status = strtolower((string)($session['status'] ?? 'scheduled'));
    $start = isset($session['scheduled_start']) ? new DateTimeImmutable($session['scheduled_start']) : null;
    $end = isset($session['scheduled_end']) && $session['scheduled_end'] ? new DateTimeImmutable($session['scheduled_end']) : null;

    if ($status === 'canceled' || $status === 'cancelled') {
        return 'Canceled';
    }
    if ($status === 'refunded') {
        return 'Refunded';
    }
    if ($status === 'completed') {
        return 'Completed';
    }
    if ($status === 'rescheduled') {
        return 'Rescheduled';
    }
    if ($start && $end && $now >= $start && $now <= $end) {
        return 'Active';
    }
    if ($start && $now > $end) {
        return 'Awaiting Completion';
    }
    return 'Scheduled';
}

function ts_is_active_session(array $session, DateTimeImmutable $now): bool
{
    $status = strtolower((string)($session['status'] ?? 'scheduled'));
    if (in_array($status, ['completed', 'refunded', 'canceled', 'cancelled'], true)) {
        return false;
    }
    $start = isset($session['scheduled_start']) ? new DateTimeImmutable($session['scheduled_start']) : null;
    $end = isset($session['scheduled_end']) && $session['scheduled_end'] ? new DateTimeImmutable($session['scheduled_end']) : null;
    if ($start && $end) {
        return $now >= $start && $now <= $end;
    }
    if ($start && !$end) {
        return $now->getTimestamp() >= $start->getTimestamp();
    }
    return false;
}

$catalogCards = array_map(function (array $pkg) {
    return [
        'id' => (int)($pkg['id'] ?? 0),
        'title' => $pkg['title'] ?? 'Package',
        'sessions' => (int)($pkg['session_count'] ?? 1),
        'price_per_session' => ts_format_currency($pkg['price_per_session'] ?? 0),
        'total_price' => ts_format_currency($pkg['total_price'] ?? 0),
        'expires' => $pkg['expires_label'] ?? 'Sessions do not expire',
        'raw' => $pkg,
    ];
}, $catalogPackages);

$rosterScope = 'my';
if ($pricingMode === 'admin' && ($isTrainerAdmin || $isAdmin)) {
    $scopeCandidate = strtolower((string)($_GET['scope'] ?? 'my'));
    if (in_array($scopeCandidate, ['my', 'all', 'unassigned'], true)) {
        $rosterScope = $scopeCandidate;
    }
}

$rosterRows = [];
if ($pricingMode === 'admin') {
    if ($isAdmin && isset($_GET['trainer_id'])) {
        $trainerId = max(0, (int)$_GET['trainer_id']);
    }
    switch ($rosterScope) {
        case 'all':
            $rosterRows = ppf_trainer_sessions_collect_client_overview($conn, ['include_unassigned' => true]);
            break;
        case 'unassigned':
            $rosterRows = array_filter(
                ppf_trainer_sessions_collect_client_overview($conn, ['include_unassigned' => true]),
                fn($row) => (int)($row['assigned_trainer_id'] ?? 0) === 0
            );
            break;
        default:
            $rosterRows = ppf_trainer_sessions_collect_client_overview($conn, ['trainer_id' => $trainerId]);
            break;
    }
}

$clientSummaries = [];
$clientDetails = [];
$availablePackagesForClients = [];

if ($pricingMode === 'admin') {
    foreach ($rosterRows as $row) {
        $clientId = (int)($row['id'] ?? 0);
        if ($clientId <= 0) {
            continue;
        }
        $totalSessions = (int)($row['total_sessions'] ?? 0);
        $completed = (int)($row['completed_sessions'] ?? 0);
        $canceled = (int)($row['canceled_sessions'] ?? 0);
        $refunded = (int)($row['refunded_sessions'] ?? 0);
        $remaining = max(0, $totalSessions - $completed - $canceled - $refunded);
        $age = ts_calculate_age($row['birthdate'] ?? null);
        $heightLabel = ts_format_height(
            isset($row['height_ft']) ? (int)$row['height_ft'] : null,
            isset($row['height_in']) ? (int)$row['height_in'] : null
        );
        $weightLabel = ($row['weight_lbs'] ?? null) !== null && $row['weight_lbs'] !== ''
            ? number_format((float)$row['weight_lbs'], 1) . ' lbs'
            : '—';

        $clientSummaries[$clientId] = [
            'id' => $clientId,
            'first_name' => $row['first_name'] ?? '',
            'middle_name' => $row['middle_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'email' => $row['email'] ?? '',
            'phone' => ts_format_phone($row['phone'] ?? ''),
            'birthdate' => ts_format_date($row['birthdate'] ?? null),
            'age' => $age !== null ? (string)$age : '—',
            'height' => $heightLabel,
            'weight' => $weightLabel,
            'sessions_purchased' => $totalSessions,
            'sessions_remaining' => $remaining,
            'payments_total' => ts_format_currency($row['total_payments'] ?? 0),
            'refunds_total' => ts_format_currency($row['total_refunds'] ?? 0),
        ];

        $packages = ppf_trainer_sessions_collect_client_packages($conn, $clientId, $trainerId > 0 ? $trainerId : null);
        $transactionsCount = 0;
        $sessionRows = [];
        $packageCards = [];
        $packageOptions = [];

        foreach ($packages as $package) {
            $packageId = (int)($package['id'] ?? 0);
            $packageName = $package['package_name'] ?? 'Package';
            $packageCards[] = [
                'id' => (int)($package['id'] ?? 0),
                'name' => $packageName,
                'purchased_sessions' => (int)($package['purchased_sessions'] ?? 0),
                'price_per_session' => ts_format_currency($package['price_per_session'] ?? 0),
                'created_at' => ts_format_date($package['created_at'] ?? null),
            ];
            $packageOptions[] = [
                'id' => $packageId,
                'label' => $packageName . ' • ' . (int)($package['purchased_sessions'] ?? 0) . ' sessions',
            ];
            foreach ($package['transactions'] as $txn) {
                $transactionsCount++;
            }
            foreach ($package['sessions'] as $session) {
                $sessionRows[] = [
                    'session_id' => (int)($session['id'] ?? 0),
                    'package_id' => $packageId,
                    'package_name' => $packageName,
                    'purchase_date' => ts_format_date($package['created_at'] ?? null),
                    'scheduled_date' => ts_format_date($session['scheduled_start'] ?? null),
                    'start_time' => ts_format_time($session['scheduled_start'] ?? null),
                    'end_time' => ts_format_time($session['scheduled_end'] ?? null),
                    'status_label' => ts_session_status_label($session, $now),
                    'is_active' => ts_is_active_session($session, $now),
                    'raw' => $session,
                ];
            }
        }

        usort($sessionRows, function ($a, $b) {
            $aTime = $a['raw']['scheduled_start'] ?? null;
            $bTime = $b['raw']['scheduled_start'] ?? null;
            return strcmp((string)$aTime, (string)$bTime);
        });

        $clientDetails[$clientId] = [
            'packages' => $packageCards,
            'sessions' => $sessionRows,
            'transactions_count' => $transactionsCount,
            'lifetime_paid' => ts_format_currency($row['total_payments'] ?? 0),
            'lifetime_refunded' => ts_format_currency($row['total_refunds'] ?? 0),
            'refund_due' => '$0.00',
            'package_options' => $packageOptions,
        ];
    }

    $availablePackagesForClients = array_map(function ($pkg) {
        return [
            'id' => (int)($pkg['id'] ?? 0),
            'title' => $pkg['title'] ?? 'Package',
            'label' => ($pkg['title'] ?? 'Package') . ' • ' . ts_format_currency($pkg['total_price'] ?? 0) . ' (' . (int)($pkg['session_count'] ?? 1) . ' sessions)',
        ];
    }, $catalogPackages);
}

?>
<main class="page" id="trainerSessions">
  <div class="page-header">
    <div>
      <h1>Trainer Sessions</h1>
      <p class="muted">Coordinate pricing, packages, and scheduling for every client in one unified workspace.</p>
    </div>
    <?php if ($pricingMode === 'admin'): ?>
    <div class="pricing-mode-indicator">
      <span class="badge">Trainer Admin sets prices</span>
    </div>
    <?php else: ?>
    <div class="pricing-mode-indicator">
      <span class="badge">Trainer sets own prices</span>
    </div>
    <?php endif; ?>
  </div>

  <section class="card session-card" id="catalogCard">
    <div class="card-header">
      <div>
        <h2>Packages</h2>
        <?php if ($pricingMode === 'trainer'): ?>
        <p class="muted">Build personalised packages that reflect your coaching style and value.</p>
        <?php else: ?>
        <p class="muted">Create global packages for your trainers to offer consistently across the team.</p>
        <?php endif; ?>
      </div>
      <div class="card-actions">
        <button class="btn" type="button" id="createPackageBtn">Create Package</button>
      </div>
    </div>

    <?php if (empty($catalogCards)): ?>
      <div class="empty-state">
        <p>No packages yet. Create your first offer to start selling sessions.</p>
      </div>
    <?php else: ?>
      <div class="package-grid">
        <?php foreach ($catalogCards as $pkg): ?>
          <article class="package-card" data-package-id="<?php echo h($pkg['id']); ?>">
            <header>
              <h3><?php echo h($pkg['title']); ?></h3>
              <span class="package-price"><?php echo h($pkg['total_price']); ?></span>
            </header>
            <dl>
              <div>
                <dt>Sessions</dt>
                <dd><?php echo h($pkg['sessions']); ?></dd>
              </div>
              <div>
                <dt>Price per Session</dt>
                <dd><?php echo h($pkg['price_per_session']); ?></dd>
              </div>
              <div>
                <dt>Expiration</dt>
                <dd><?php echo h($pkg['expires']); ?></dd>
              </div>
            </dl>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($pricingMode === 'admin'): ?>
  <section class="card session-card" id="clientRoster">
    <div class="card-header">
      <div>
        <h2>Clients</h2>
        <p class="muted">Monitor client engagement, manage sessions, and keep financials balanced.</p>
      </div>
      <div class="card-actions">
        <?php if ($isTrainerAdmin || $isAdmin): ?>
        <div class="filter-group">
          <label for="rosterScope" class="visually-hidden">Filter clients</label>
          <select id="rosterScope" class="input small">
            <option value="my" <?php echo $rosterScope === 'my' ? 'selected' : ''; ?>>My Clients</option>
            <option value="all" <?php echo $rosterScope === 'all' ? 'selected' : ''; ?>>All Clients</option>
            <option value="unassigned" <?php echo $rosterScope === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
          </select>
        </div>
        <?php endif; ?>
        <div class="filter-group">
          <input type="search" id="clientSearch" class="input" placeholder="Search clients">
        </div>
        <div class="filter-group">
          <button class="btn secondary" type="button" id="columnToggleBtn">Columns</button>
          <div class="column-toggle-menu" id="columnToggleMenu" hidden>
            <label><input type="checkbox" data-column="middle" checked> Middle Name</label>
            <label><input type="checkbox" data-column="phone" checked> Phone Number</label>
            <label><input type="checkbox" data-column="birthdate" checked> Birthdate</label>
            <label><input type="checkbox" data-column="age" checked> Age</label>
            <label><input type="checkbox" data-column="height" checked> Height</label>
            <label><input type="checkbox" data-column="weight" checked> Weight</label>
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="data-table" id="clientTable">
        <colgroup>
          <col span="1">
          <col span="1" data-column="middle">
          <col span="1">
          <col span="1">
          <col span="1" data-column="phone">
          <col span="1" data-column="birthdate">
          <col span="1" data-column="age">
          <col span="1" data-column="height">
          <col span="1" data-column="weight">
          <col span="1">
          <col span="1">
          <col span="1">
        </colgroup>
        <thead>
          <tr>
            <th><button type="button" class="sort-btn" data-sort-key="first">First Name</button></th>
            <th data-column="middle"><button type="button" class="sort-btn" data-sort-key="middle">Middle Name</button></th>
            <th><button type="button" class="sort-btn" data-sort-key="last">Last Name</button></th>
            <th><button type="button" class="sort-btn" data-sort-key="email">Email</button></th>
            <th data-column="phone"><button type="button" class="sort-btn" data-sort-key="phone">Phone Number</button></th>
            <th data-column="birthdate"><button type="button" class="sort-btn" data-sort-key="birthdate">Birthdate</button></th>
            <th data-column="age"><button type="button" class="sort-btn" data-sort-key="age">Age</button></th>
            <th data-column="height"><button type="button" class="sort-btn" data-sort-key="height">Height</button></th>
            <th data-column="weight"><button type="button" class="sort-btn" data-sort-key="weight">Weight</button></th>
            <th><button type="button" class="sort-btn" data-sort-key="purchased">Sessions Purchased</button></th>
            <th><button type="button" class="sort-btn" data-sort-key="remaining">Sessions Remaining</button></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($clientSummaries as $summary): ?>
          <?php $cid = $summary['id']; ?>
          <tr class="client-row" data-client-id="<?php echo h($cid); ?>"
              data-packages='<?php echo h(json_encode($clientDetails[$cid]['package_options'] ?? [])); ?>'
              data-sort-first="<?php echo h(strtolower($summary['first_name'])); ?>"
              data-sort-middle="<?php echo h(strtolower($summary['middle_name'])); ?>"
              data-sort-last="<?php echo h(strtolower($summary['last_name'])); ?>"
              data-sort-email="<?php echo h(strtolower($summary['email'])); ?>"
              data-sort-phone="<?php echo h(strtolower($summary['phone'])); ?>"
              data-sort-birthdate="<?php echo h(strtolower($summary['birthdate'])); ?>"
              data-sort-age="<?php echo h($summary['age']); ?>"
              data-sort-height="<?php echo h(strtolower($summary['height'])); ?>"
              data-sort-weight="<?php echo h(strtolower($summary['weight'])); ?>"
              data-sort-purchased="<?php echo h($summary['sessions_purchased']); ?>"
              data-sort-remaining="<?php echo h($summary['sessions_remaining']); ?>">
            <td><?php echo h($summary['first_name']); ?></td>
            <td data-column="middle"><?php echo h($summary['middle_name'] ?: '—'); ?></td>
            <td><?php echo h($summary['last_name']); ?></td>
            <td><?php echo h($summary['email']); ?></td>
            <td data-column="phone"><?php echo h($summary['phone']); ?></td>
            <td data-column="birthdate"><?php echo h($summary['birthdate']); ?></td>
            <td data-column="age"><?php echo h($summary['age']); ?></td>
            <td data-column="height"><?php echo h($summary['height']); ?></td>
            <td data-column="weight"><?php echo h($summary['weight']); ?></td>
            <td><?php echo h($summary['sessions_purchased']); ?></td>
            <td><?php echo h($summary['sessions_remaining']); ?></td>
            <td class="table-actions">
              <button type="button" class="btn tertiary" data-action="schedule" data-client-id="<?php echo h($cid); ?>">Schedule</button>
              <button type="button" class="btn tertiary" data-action="add" data-client-id="<?php echo h($cid); ?>">Add Sessions</button>
              <button type="button" class="btn tertiary" data-action="remove" data-client-id="<?php echo h($cid); ?>">Remove Sessions</button>
            </td>
          </tr>
          <tr class="client-expansion" data-client-id="<?php echo h($cid); ?>">
            <td colspan="12">
              <div class="client-detail-panel">
                <section class="detail-card" aria-label="Financials">
                  <header>
                    <h3>Financials</h3>
                  </header>
                  <div class="metrics-grid">
                    <div>
                      <span class="label">Lifetime Paid</span>
                      <span class="value"><?php echo h($clientDetails[$cid]['lifetime_paid'] ?? '$0.00'); ?></span>
                    </div>
                    <div>
                      <span class="label">Lifetime Refunded</span>
                      <span class="value"><?php echo h($clientDetails[$cid]['lifetime_refunded'] ?? '$0.00'); ?></span>
                    </div>
                    <div>
                      <span class="label">Packages Purchased</span>
                      <span class="value"><?php echo count($clientDetails[$cid]['packages'] ?? []); ?></span>
                    </div>
                    <div>
                      <span class="label">Transactions</span>
                      <span class="value"><?php echo h($clientDetails[$cid]['transactions_count'] ?? 0); ?></span>
                    </div>
                    <div>
                      <span class="label">Refund Due</span>
                      <span class="value"><?php echo h($clientDetails[$cid]['refund_due'] ?? '$0.00'); ?></span>
                    </div>
                  </div>
                </section>

                <section class="detail-card" aria-label="Sessions">
                  <header>
                    <h3>Purchased Sessions</h3>
                  </header>
                  <?php if (empty($clientDetails[$cid]['sessions'])): ?>
                    <div class="empty-state">
                      <p>No sessions have been scheduled yet.</p>
                    </div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="inner-table">
                        <thead>
                          <tr>
                            <th>Package</th>
                            <th>Purchase Date</th>
                            <th>Scheduled</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clientDetails[$cid]['sessions'] as $session): ?>
                          <tr class="session-row<?php echo $session['is_active'] ? ' is-active' : ''; ?>" data-session-id="<?php echo h($session['session_id']); ?>" data-package-id="<?php echo h($session['package_id']); ?>">
                            <td><?php echo h($session['package_name']); ?></td>
                            <td><?php echo h($session['purchase_date']); ?></td>
                            <td><?php echo h($session['scheduled_date']); ?></td>
                            <td><?php echo h($session['start_time']); ?></td>
                            <td><?php echo h($session['end_time']); ?></td>
                            <td><span class="status-pill"><?php echo h($session['status_label']); ?></span></td>
                            <td class="table-actions">
                              <button type="button" class="btn small tertiary" data-action="reschedule-session" data-session-id="<?php echo h($session['session_id']); ?>">Reschedule</button>
                              <button type="button" class="btn small tertiary" data-action="cancel-session" data-session-id="<?php echo h($session['session_id']); ?>">Cancel</button>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </section>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>
</main>

<div class="modal-backdrop hidden" id="sessionModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="sessionModalTitle">
    <div class="modal-header">
      <h3 id="sessionModalTitle">Create Package</h3>
      <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body"></div>
  </div>
</div>

<script>
(function(){
  const pricingMode = <?php echo json_encode($pricingMode, JSON_UNESCAPED_SLASHES); ?>;
  const csrfToken = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES); ?>;
  const packagesForClients = <?php echo json_encode($availablePackagesForClients, JSON_UNESCAPED_SLASHES); ?>;

  const modalBackdrop = document.getElementById('sessionModal');
  const modalBody = modalBackdrop?.querySelector('.modal-body');
  const modalTitle = document.getElementById('sessionModalTitle');
  const modalClose = modalBackdrop?.querySelector('.modal-close');
  const packageButton = document.getElementById('createPackageBtn');
  const columnToggleBtn = document.getElementById('columnToggleBtn');
  const columnToggleMenu = document.getElementById('columnToggleMenu');
  const rosterScope = document.getElementById('rosterScope');
  const clientSearch = document.getElementById('clientSearch');
  const clientTable = document.getElementById('clientTable');

  function openModal(title, content){
    if (!modalBackdrop || !modalBody || !modalTitle) return;
    modalTitle.textContent = title;
    modalBody.innerHTML = '';
    modalBody.appendChild(content);
    modalBackdrop.classList.remove('hidden');
    modalBackdrop.setAttribute('aria-hidden', 'false');
  }

  function closeModal(){
    if (!modalBackdrop) return;
    modalBackdrop.classList.add('hidden');
    modalBackdrop.setAttribute('aria-hidden', 'true');
  }

  modalBackdrop?.addEventListener('click', (event) => {
    if (event.target === modalBackdrop) {
      closeModal();
    }
  });
  modalClose?.addEventListener('click', closeModal);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
  });

  function buildPackageForm(){
    const wrapper = document.createElement('form');
    wrapper.className = 'form-grid';
    wrapper.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="create_price_package">
      <div class="field">
        <label for="pkgTitle">Package Name</label>
        <input id="pkgTitle" name="title" class="input" required>
      </div>
      <div class="field">
        <label for="pkgSessions">Sessions Included</label>
        <input id="pkgSessions" name="session_count" type="number" min="1" class="input" value="5" required>
      </div>
      <div class="field">
        <label for="pkgPrice">Price per Session</label>
        <input id="pkgPrice" name="price_per_session" type="text" class="input" placeholder="$120.00" required>
        <small class="muted">Total: <span id="pkgTotal">$0.00</span></small>
      </div>
      <fieldset class="field">
        <legend>Sessions Expire</legend>
        <div class="radio-group">
          <label><input type="radio" name="expires_type" value="none" checked> Sessions do not expire</label>
          <label><input type="radio" name="expires_type" value="duration"> After a duration</label>
          <label><input type="radio" name="expires_type" value="date"> On a specific date</label>
        </div>
      </fieldset>
      <div class="field" data-expiration="duration" hidden>
        <label for="expiresValue">Duration</label>
        <div class="split">
          <input id="expiresValue" name="expires_value" type="number" min="1" class="input" value="30">
          <select id="expiresUnit" name="expires_unit" class="input">
            <option value="days">Days</option>
            <option value="weeks">Weeks</option>
            <option value="months">Months</option>
            <option value="years">Years</option>
          </select>
        </div>
      </div>
      <div class="field" data-expiration="date" hidden>
        <label for="expiresOn">Expiration Date</label>
        <input id="expiresOn" name="expires_on" type="date" class="input">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn">Create Package</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    const priceInput = wrapper.querySelector('#pkgPrice');
    const sessionInput = wrapper.querySelector('#pkgSessions');
    const totalLabel = wrapper.querySelector('#pkgTotal');
    const expirationRadios = wrapper.querySelectorAll('input[name="expires_type"]');
    const expirationSections = wrapper.querySelectorAll('[data-expiration]');

    function updateTotal(){
      const sessions = Math.max(1, parseInt(sessionInput.value || '0', 10));
      const rawPrice = priceInput.value || '';
      const numeric = parseFloat(rawPrice.replace(/[^0-9.]/g, '')) || 0;
      const total = sessions * numeric;
      totalLabel.textContent = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(total);
    }

    function refreshExpiration(){
      const selected = wrapper.querySelector('input[name="expires_type"]:checked');
      const value = selected ? selected.value : 'none';
      expirationSections.forEach((section) => {
        section.hidden = section.dataset.expiration !== value;
      });
    }

    priceInput?.addEventListener('input', updateTotal);
    sessionInput?.addEventListener('input', updateTotal);
    expirationRadios.forEach((radio) => radio.addEventListener('change', refreshExpiration));
    wrapper.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    wrapper.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(wrapper);
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: formData,
      });
      const payload = await response.json();
      if (payload.ok) {
        window.location.reload();
      } else {
        alert(payload.message || 'Unable to create package.');
      }
    });

    updateTotal();
    refreshExpiration();
    return wrapper;
  }

  packageButton?.addEventListener('click', () => {
    const form = buildPackageForm();
    openModal('Create Package', form);
  });

  function handleRosterScopeChange(){
    if (!rosterScope) return;
    const url = new URL(window.location.href);
    url.searchParams.set('scope', rosterScope.value);
    window.location.href = url.toString();
  }

  rosterScope?.addEventListener('change', handleRosterScopeChange);

  function setupColumnToggle(){
    if (!columnToggleBtn || !columnToggleMenu) return;
    columnToggleBtn.addEventListener('click', () => {
      columnToggleMenu.hidden = !columnToggleMenu.hidden;
    });
    document.addEventListener('click', (event) => {
      if (!columnToggleMenu.hidden && !columnToggleMenu.contains(event.target) && event.target !== columnToggleBtn) {
        columnToggleMenu.hidden = true;
      }
    });
    columnToggleMenu.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        const column = checkbox.dataset.column;
        const shouldShow = checkbox.checked;
        document.querySelectorAll(`[data-column="${column}"]`).forEach((cell) => {
          cell.style.display = shouldShow ? '' : 'none';
        });
      });
    });
  }

  setupColumnToggle();

  function enhanceClientTable(){
    if (!clientTable || !window.ppfEnhanceTable) return;
    window.ppfEnhanceTable(clientTable, {
      searchInput: clientSearch,
      expansionSelector: '.client-expansion',
      sortTypes: {
        first: 'string',
        middle: 'string',
        last: 'string',
        email: 'string',
        phone: 'string',
        birthdate: 'string',
        age: 'number',
        height: 'string',
        weight: 'string',
        purchased: 'number',
        remaining: 'number'
      }
    });
  }

  enhanceClientTable();

  const clientRows = document.querySelectorAll('.client-row');
  clientRows.forEach((row) => {
    row.addEventListener('click', () => {
      const clientId = row.dataset.clientId;
      document.querySelectorAll('.client-row').forEach((r) => r.classList.remove('is-active'));
      document.querySelectorAll('.client-expansion').forEach((exp) => {
        if (exp.dataset.clientId !== clientId) exp.classList.remove('is-open');
      });
      row.classList.add('is-active');
      const expansion = document.querySelector(`.client-expansion[data-client-id="${clientId}"]`);
      if (expansion) {
        expansion.classList.toggle('is-open');
      }
    });
  });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.matches('.client-expansion, .client-expansion *')) {
      event.stopPropagation();
    }
  });

  function buildAddSessionsForm(clientId){
    const form = document.createElement('form');
    form.className = 'form-grid';
    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="manual_add_sessions">
      <div class="field">
        <label for="addCount">Number of Sessions</label>
        <input id="addCount" name="count" type="number" min="1" class="input" value="1" required>
      </div>
      <div class="field">
        <label for="addPackage">Price Package</label>
        <select id="addPackage" name="catalog_package_id" class="input">
          ${packagesForClients.map(pkg => `<option value="${pkg.id}">${pkg.label}</option>`).join('')}
        </select>
      </div>
      <div class="field">
        <label for="scheduleNow">Schedule Immediately</label>
        <textarea id="scheduleNow" class="input" name="schedule_notes" placeholder="Optional notes or proposed dates"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn">Add Sessions</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    form.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      const pkgSelect = form.querySelector('#addPackage');
      if (pkgSelect && !pkgSelect.value) {
        alert('Select a price package.');
        return;
      }
      formData.append('client_id', clientId);
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: formData,
      });
      const payload = await response.json();
      if (payload.ok) {
        window.location.reload();
      } else {
        alert(payload.message || 'Unable to add sessions.');
      }
    });

    return form;
  }

  function buildRemoveSessionsForm(clientId, packageOptions){
    const form = document.createElement('form');
    form.className = 'form-grid';
    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="manual_remove_sessions">
      <div class="field">
        <label for="removePackage">Package</label>
        <select id="removePackage" name="package_id" class="input" required>
          ${(packageOptions && packageOptions.length) ? packageOptions.map(pkg => `<option value="${pkg.id}">${pkg.label}</option>`).join('') : '<option value="">No packages available</option>'}
        </select>
        ${(!packageOptions || !packageOptions.length) ? '<small class="muted">Add a package before removing sessions.</small>' : ''}
      </div>
      <div class="field">
        <label for="removeCount">Sessions to remove</label>
        <input id="removeCount" name="count" type="number" min="1" class="input" value="1" required>
      </div>
      <div class="field">
        <label for="removeAmount">Refund Amount</label>
        <input id="removeAmount" name="amount" type="text" class="input" placeholder="$0.00" required>
      </div>
      <div class="field">
        <label for="removeNotes">Notes</label>
        <textarea id="removeNotes" name="notes" class="input" placeholder="Explain the adjustment"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn danger">Remove Sessions</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    form.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const pkgSelect = form.querySelector('#removePackage');
      if (!pkgSelect || !pkgSelect.value) {
        alert('Choose a package to adjust.');
        return;
      }
      const formData = new FormData(form);
      formData.append('client_id', clientId);
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: formData,
      });
      const payload = await response.json();
      if (payload.ok) {
        window.location.reload();
      } else {
        alert(payload.message || 'Unable to remove sessions.');
      }
    });
    return form;
  }

  function buildScheduleForm(clientId, packageOptions){
    const form = document.createElement('form');
    form.className = 'form-grid';
    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="schedule_session_batch">
      <input type="hidden" name="client_id" value="${clientId}">
      <div class="field">
        <label for="schedulePackage">Package</label>
        <select id="schedulePackage" name="package_id" class="input" required>
          ${(packageOptions && packageOptions.length) ? packageOptions.map(pkg => `<option value="${pkg.id}">${pkg.label}</option>`).join('') : '<option value="">No packages available</option>'}
        </select>
        ${(!packageOptions || !packageOptions.length) ? '<small class="muted">Create a package before scheduling sessions.</small>' : ''}
      </div>
      <div class="field">
        <label for="scheduleDate">Session Date</label>
        <input id="scheduleDate" name="session_date" type="date" class="input" required>
      </div>
      <div class="field">
        <label for="startTime">Start Time</label>
        <input id="startTime" name="start_time" type="time" class="input" required>
      </div>
      <div class="field">
        <label for="endTime">End Time</label>
        <input id="endTime" name="end_time" type="time" class="input">
      </div>
      <div class="field">
        <label for="sessionCount">Number of Sessions</label>
        <input id="sessionCount" name="session_count" type="number" min="1" class="input" value="1" required>
      </div>
      <div class="field">
        <label for="sessionNotes">Notes</label>
        <textarea id="sessionNotes" name="notes" class="input" placeholder="Optional notes for the client"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn">Schedule Sessions</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    form.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const pkgSelect = form.querySelector('#schedulePackage');
      if (!pkgSelect || !pkgSelect.value) {
        alert('Select a package to schedule against.');
        return;
      }
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: new FormData(form)
      });
      const payload = await response.json();
      if (payload.ok) {
        window.location.reload();
      } else {
        alert(payload.message || 'Unable to schedule sessions.');
      }
    });

    return form;
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.dataset.action === 'add') {
      const clientId = target.dataset.clientId;
      openModal('Add Sessions', buildAddSessionsForm(clientId));
    }
    if (target.dataset.action === 'remove') {
      const clientId = target.dataset.clientId;
      const hostRow = target.closest('.client-row');
      let packageOptions = [];
      if (hostRow && hostRow.dataset.packages) {
        try {
          packageOptions = JSON.parse(hostRow.dataset.packages) || [];
        } catch (err) {
          packageOptions = [];
        }
      }
      openModal('Remove Sessions', buildRemoveSessionsForm(clientId, packageOptions));
    }
    if (target.dataset.action === 'schedule') {
      const clientId = target.dataset.clientId;
      const hostRow = target.closest('.client-row');
      let packageOptions = [];
      if (hostRow && hostRow.dataset.packages) {
        try {
          packageOptions = JSON.parse(hostRow.dataset.packages) || [];
        } catch (err) {
          packageOptions = [];
        }
      }
      openModal('Schedule Sessions', buildScheduleForm(clientId, packageOptions));
    }
  });
})();
</script>
