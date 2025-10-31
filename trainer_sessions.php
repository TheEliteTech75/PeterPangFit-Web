<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

$role = ppf_role_key($USER_ROLE ?? ($_SESSION['role'] ?? 'guest'));
if (!in_array($role, ['trainer', 'trainer_admin', 'coach'], true) && !ppf_is_admin_role($role)) {
    require_once __DIR__ . '/access_denied.php';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$isAdmin   = ppf_is_admin_role($role);
$trainerId = (int)($USER_ID ?? ($_SESSION['user_id'] ?? 0));

$clientFilter = isset($_GET['client_id']) ? max(0, (int)$_GET['client_id']) : null;
if ($clientFilter === 0) $clientFilter = null;

$trainerFilter = $isAdmin ? (isset($_GET['trainer_id']) ? max(0, (int)$_GET['trainer_id']) : null) : $trainerId;
if (!$isAdmin) {
    $trainerFilter = $trainerId;
}

ppf_trainer_sessions_ensure_schema($conn);

$clients = ppf_trainer_sessions_fetch_clients($conn);

$trainerOptions = [];
if ($isAdmin) {
    $hasIsTrainer = function_exists('column_exists') ? column_exists($conn, 'users', 'is_trainer') : false;
    $where = "role='trainer'";
    if ($hasIsTrainer) {
        $where = "(role='trainer' OR is_trainer=1)";
    }
    $sqlTrainer = "SELECT id, first_name, last_name, email FROM users WHERE {$where} ORDER BY last_name, first_name, id";
    if ($rs = $conn->query($sqlTrainer)) {
        while ($row = $rs->fetch_assoc()) {
            $trainerOptions[] = $row;
        }
        $rs->free();
    }
}

$packages = ppf_trainer_sessions_fetch_packages($conn, $trainerFilter, $clientFilter);
foreach ($packages as &$pkg) {
    $pid = (int)($pkg['id'] ?? 0);
    $pkg['sessions'] = ppf_trainer_sessions_fetch_sessions_for_package($conn, $pid);
    $pkg['transactions'] = ppf_trainer_sessions_fetch_transactions_for_package($conn, $pid);
}
unset($pkg);

$rateCard = ppf_trainer_sessions_rate_card();

function ts_client_name(array $pkg): string {
    $first = trim((string)($pkg['first_name'] ?? ''));
    $last  = trim((string)($pkg['last_name'] ?? ''));
    $email = trim((string)($pkg['email'] ?? ''));
    $name = trim($first . ' ' . $last);
    return $name !== '' ? $name : ($email !== '' ? $email : 'Client #' . (int)($pkg['client_id'] ?? 0));
}

function ts_money($amount): string {
    $amount = (float)$amount;
    $sign = $amount < 0 ? '-' : '';
    $abs = abs($amount);
    return $sign . '$' . number_format($abs, 2);
}

function ts_datetime(?string $iso): string {
    if (!$iso) return '—';
    try {
        $dt = new DateTime($iso);
        return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

function ts_format_duration(?int $seconds): string {
    if ($seconds === null || $seconds < 0) {
        return '';
    }
    $total = (int)$seconds;
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $secs = $total % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function ts_status_badge(string $status): string {
    $status = strtolower($status);
    $map = [
        'scheduled' => ['label' => 'Scheduled', 'class' => 'good'],
        'in_progress' => ['label' => 'In progress', 'class' => 'progress'],
        'completed' => ['label' => 'Completed', 'class' => 'ok'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'warn'],
    ];
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => ''];
    $cls = $info['class'] ? ' status-pill ' . $info['class'] : ' status-pill';
    return '<span class="' . $cls . '">' . h($info['label']) . '</span>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Trainer Sessions</title>
  <style>
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Helvetica,Arial,sans-serif;background:var(--page-canvas);color:var(--text);}
    .ts-subheader{display:flex;align-items:center;justify-content:space-between;padding:18px 24px 12px 24px;border-bottom:1px solid var(--card-border);background:var(--panel-elevated);position:sticky;top:64px;z-index:2500;gap:16px;flex-wrap:wrap;}
    .ts-subheader .brand{font-size:20px;font-weight:700;color:var(--text);}
    .ts-subheader .muted{color:var(--muted);font-size:14px;}
    .ts-subheader .btnset{display:flex;gap:10px;flex-wrap:wrap;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border-radius:12px;border:1px solid var(--chip-border);background:var(--chip-bg);color:var(--text);text-decoration:none;font-size:14px;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease, background .2s ease;box-shadow:0 12px 24px color-mix(in srgb, var(--chip-border) 35%, transparent 65%);}
    .btn:hover{transform:translateY(-1px);box-shadow:0 16px 32px color-mix(in srgb, var(--chip-border) 45%, transparent 55%);}
    .btn.brand{background:color-mix(in srgb, var(--brand) 28%, transparent 72%);border-color:color-mix(in srgb, var(--brand-strong, var(--brand)) 55%, transparent 45%);}
    .btn.warn{background:color-mix(in srgb, var(--danger) 24%, transparent 76%);border-color:color-mix(in srgb, var(--danger) 55%, transparent 45%);color:color-mix(in srgb, var(--danger) 70%, var(--text) 30%);}
    .btn.small{padding:6px 10px;font-size:13px;}
    .ts-wrap{max-width:1200px;margin:18px auto;padding:0 20px 60px;display:flex;flex-direction:column;gap:18px;}
    .ts-card{background:var(--panel-elevated);border:1px solid var(--card-border);border-radius:18px;padding:18px;box-shadow:var(--card-shadow);}
    .ts-card h2{margin:0 0 12px 0;font-size:18px;}
    .ts-card h3{margin:16px 0 10px;font-size:16px;}
    .ts-card p{margin:0;color:var(--muted);}
    .ts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;padding-top:12px;}
    .ts-summary-item{padding:12px;border:1px solid var(--card-border);border-radius:14px;background:color-mix(in srgb, var(--panel) 88%, transparent 12%);}
    .ts-summary-item span{display:block;}
    .ts-summary-item .label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
    .ts-summary-item .value{font-size:18px;font-weight:600;color:var(--text);}
    .ts-summary-item .muted{font-size:13px;color:var(--muted);}
    .ts-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:18px;}
    .ts-rate-card{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:12px;}
    .ts-rate{border:1px dashed var(--card-border);border-radius:14px;padding:12px;background:color-mix(in srgb, var(--panel-muted) 86%, transparent 14%);} 
    .ts-rate strong{display:block;font-size:14px;margin-bottom:6px;color:var(--text);}
    .ts-rate span{display:block;font-size:13px;color:var(--muted);}
    .ts-filter-form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
    .ts-filter-form label{display:flex;flex-direction:column;font-size:13px;color:var(--muted);gap:4px;}
    .ts-filter-form select,.ts-filter-form input{padding:8px 10px;border-radius:10px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text);min-width:200px;}
    .ts-packages-empty{color:var(--muted);font-size:14px;text-align:center;padding:24px 0;}
    table.ts-sessions{width:100%;border-collapse:collapse;margin-top:12px;background:var(--panel);border-radius:14px;overflow:hidden;border:1px solid var(--card-border);}
    table.ts-sessions th, table.ts-sessions td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top;text-align:left;}
    table.ts-sessions th{background:color-mix(in srgb, var(--panel-elevated) 88%, var(--theme-swatch-2, var(--brand)) 12%);color:color-mix(in srgb, var(--muted) 80%, var(--text) 20%);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
    table.ts-sessions tr:last-child td{border-bottom:0;}
    .status-pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid color-mix(in srgb, var(--chip-border) 55%, transparent 45%);font-size:12px;color:var(--text);background:color-mix(in srgb, var(--panel-muted) 80%, transparent 20%);}
    .status-pill.good{background:color-mix(in srgb, var(--success) 20%, transparent 80%);border-color:color-mix(in srgb, var(--success) 55%, transparent 45%);color:color-mix(in srgb, var(--success) 70%, var(--text) 30%);}
    .status-pill.progress{background:color-mix(in srgb, var(--brand) 30%, transparent 70%);border-color:color-mix(in srgb, var(--brand) 55%, transparent 45%);color:color-mix(in srgb, var(--brand) 70%, var(--text) 30%);}
    .status-pill.ok{background:color-mix(in srgb, var(--brand) 24%, transparent 76%);border-color:color-mix(in srgb, var(--brand) 55%, transparent 45%);}
    .status-pill.warn{background:color-mix(in srgb, var(--danger) 24%, transparent 76%);border-color:color-mix(in srgb, var(--danger) 55%, transparent 45%);color:color-mix(in srgb, var(--danger) 70%, var(--text) 30%);}
    .ts-inline-form{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:flex-end;}
    .ts-inline-form label{display:flex;flex-direction:column;font-size:12px;color:var(--muted);gap:4px;min-width:180px;}
    .ts-inline-form input,.ts-inline-form textarea{padding:8px 10px;border-radius:10px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text);}
    .ts-inline-form textarea{min-height:70px;resize:vertical;}
    .ts-inline-form button{align-self:flex-end;}
    .ts-actions{--ts-actions-gap:14px;display:flex;flex-wrap:wrap;align-items:center;margin:-4px calc(var(--ts-actions-gap)/-2) 0;}
    .ts-actions>*{margin:4px calc(var(--ts-actions-gap)/2) 0;}
    .ts-actions form{display:flex;}
    table.ts-sessions .ts-actions{--ts-actions-gap:16px;}
    .ts-session-controls{--ts-session-gap:16px;display:flex;flex-wrap:wrap;align-items:center;margin:-4px calc(var(--ts-session-gap)/-2) 8px;}
    .ts-session-controls>*{margin:4px calc(var(--ts-session-gap)/2) 0;}
    .ts-session-controls form{display:flex;}
    .ts-session-btn{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:12px;font-size:13px;font-weight:600;border:1px solid color-mix(in srgb, var(--brand) 52%, transparent 48%);background:color-mix(in srgb, var(--brand) 24%, transparent 76%);color:var(--text);cursor:pointer;transition:transform .2s ease, box-shadow .2s ease, background .2s ease;box-shadow:0 12px 24px color-mix(in srgb, var(--brand) 20%, transparent 80%);}
    .ts-session-btn[data-ts-start]{background:color-mix(in srgb, var(--brand) 18%, transparent 82%);}
    .ts-session-btn[data-ts-start]:hover:not([disabled]),.ts-session-btn[data-ts-start]:focus-visible:not([disabled]){background:color-mix(in srgb, var(--brand) 32%, transparent 68%);transform:translateY(-1px);}
    .ts-session-btn[data-ts-end]:hover:not([disabled]),.ts-session-btn[data-ts-end]:focus-visible:not([disabled]){background:color-mix(in srgb, var(--brand) 36%, transparent 64%);transform:translateY(-1px);}
    .ts-session-btn[disabled]{opacity:.65;cursor:not-allowed;box-shadow:none;transform:none;}
    .ts-session-btn.is-processing{cursor:wait;background:color-mix(in srgb, var(--brand) 16%, var(--muted) 84%);box-shadow:none;}
    .ts-session-btn.is-started{background:color-mix(in srgb, var(--success) 22%, transparent 78%);border-color:color-mix(in srgb, var(--success) 55%, transparent 45%);color:color-mix(in srgb, var(--success) 72%, var(--text) 28%);box-shadow:none;}
    .ts-session-btn.is-complete{background:color-mix(in srgb, var(--success) 30%, transparent 70%);border-color:color-mix(in srgb, var(--success) 58%, transparent 42%);color:color-mix(in srgb, var(--success) 76%, var(--text) 24%);box-shadow:none;}
    .ts-session-btn.is-cancelled{background:color-mix(in srgb, var(--danger) 26%, transparent 74%);border-color:color-mix(in srgb, var(--danger) 58%, transparent 42%);color:color-mix(in srgb, var(--danger) 74%, var(--text) 26%);box-shadow:none;}
    .ts-session-timer{font-variant-numeric:tabular-nums;font-weight:600;padding:6px 12px;border-radius:999px;background:color-mix(in srgb, var(--success) 16%, transparent 84%);border:1px solid color-mix(in srgb, var(--success) 46%, transparent 54%);color:color-mix(in srgb, var(--success) 70%, var(--text) 30%);}
    .ts-muted{color:var(--muted);font-size:12px;}
    .ts-timeline{margin:12px 0 0;display:flex;flex-direction:column;gap:8px;}
    .ts-transaction{border:1px solid var(--card-border);border-radius:12px;padding:10px;background:color-mix(in srgb, var(--panel-muted) 84%, transparent 16%);}
    .ts-transaction strong{display:block;font-size:14px;margin-bottom:4px;}
    .ts-transaction span{font-size:12px;color:var(--muted);display:block;}
    .ts-modal-backdrop{position:fixed;inset:0;background:color-mix(in srgb, var(--theme-swatch-1, #05070d) 62%, rgba(2,6,23,0.55) 38%);display:none;align-items:center;justify-content:center;z-index:5100;}
    .ts-modal{background:var(--panel-elevated);border:1px solid var(--card-border);border-radius:16px;padding:18px;box-shadow:var(--card-shadow);width:min(480px,90vw);}
    .ts-modal h3{margin:0 0 12px 0;}
    .ts-modal .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
    .flash-inline{display:none;margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid var(--card-border);background:color-mix(in srgb, var(--panel-muted) 84%, transparent 16%);color:var(--text);font-size:13px;}
    .flash-inline.ok{border-color:color-mix(in srgb, var(--success) 55%, transparent 45%);color:color-mix(in srgb, var(--success) 75%, var(--text) 25%);}
    .flash-inline.err{border-color:color-mix(in srgb, var(--danger) 55%, transparent 45%);color:color-mix(in srgb, var(--danger) 75%, var(--text) 25%);}
    @media (max-width:720px){
      .ts-subheader{padding:14px 16px;top:56px;}
      .ts-wrap{padding:0 14px 40px;}
      .ts-inline-form label{min-width:140px;}
    }
  </style>
</head>
<body class="ppf-themed">
  <div class="ts-subheader">
    <div>
      <div class="brand">Trainer Sessions</div>
      <div class="muted">Packages, schedules, payments, and refunds</div>
    </div>
    <div class="btnset">
      <a class="btn" href="clients.php">Clients</a>
      <button class="btn brand" type="button" id="btnOpenPackageForm">New Package</button>
    </div>
  </div>

  <main class="ts-wrap">
    <div class="ts-card" id="tsFilters">
      <h2>Filters &amp; Rate Card</h2>
      <form class="ts-filter-form" method="get">
        <?php if ($isAdmin): ?>
          <label>Trainer
            <select name="trainer_id">
              <option value="">All Trainers</option>
              <?php foreach ($trainerOptions as $trainer):
                $tid = (int)$trainer['id'];
                $name = trim(($trainer['first_name'] ?? '') . ' ' . ($trainer['last_name'] ?? ''));
                $display = $name !== '' ? $name : ($trainer['email'] ?? ('Trainer #' . $tid));
                $sel = ($trainerFilter && $tid === (int)$trainerFilter) ? 'selected' : '';
              ?>
                <option value="<?php echo $tid; ?>" <?php echo $sel; ?>><?php echo h($display); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label>Client
          <select name="client_id">
            <option value="">All Clients</option>
            <?php foreach ($clients as $client):
              $cid = (int)$client['id'];
              $name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
              $display = $name !== '' ? $name : ($client['email'] ?? ('Client #' . $cid));
              $sel = ($clientFilter && $cid === (int)$clientFilter) ? 'selected' : '';
            ?>
              <option value="<?php echo $cid; ?>" <?php echo $sel; ?>><?php echo h($display); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn brand" type="submit">Apply</button>
        <a class="btn" href="trainer_sessions.php">Reset</a>
      </form>
      <div class="ts-rate-card">
        <?php foreach ($rateCard as $tier): ?>
          <div class="ts-rate">
            <strong><?php echo h($tier['label']); ?></strong>
            <span><?php echo h(($tier['min_sessions'] ?? 1) . (($tier['max_sessions'] ?? null) ? '–' . $tier['max_sessions'] : '+')); ?> sessions</span>
            <span><?php echo ts_money($tier['price_per_session']); ?> per session</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ts-card" id="tsPackageForm" style="display:none;">
      <h2>Create Session Package</h2>
      <form class="ts-inline-form js-ajax" data-refresh="1">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="create_package">
        <label>Client
          <select name="client_id" required>
            <option value="">Select Client</option>
            <?php foreach ($clients as $client):
              $cid = (int)$client['id'];
              $name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
              $display = $name !== '' ? $name : ($client['email'] ?? ('Client #' . $cid));
            ?>
              <option value="<?php echo $cid; ?>"><?php echo h($display); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($isAdmin): ?>
          <label>Trainer
            <select name="trainer_id" required>
              <option value="">Select Trainer</option>
              <?php foreach ($trainerOptions as $trainer):
                $tid = (int)$trainer['id'];
                $name = trim(($trainer['first_name'] ?? '') . ' ' . ($trainer['last_name'] ?? ''));
                $display = $name !== '' ? $name : ($trainer['email'] ?? ('Trainer #' . $tid));
                $sel = ($tid === $trainerId) ? 'selected' : '';
              ?>
                <option value="<?php echo $tid; ?>" <?php echo $sel; ?>><?php echo h($display); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php else: ?>
          <input type="hidden" name="trainer_id" value="<?php echo $trainerId; ?>">
        <?php endif; ?>
        <label>Package Name
          <input type="text" name="package_name" placeholder="e.g. Spring Strength" required>
        </label>
        <label>Sessions Purchased
          <input type="number" name="purchased_sessions" min="1" step="1" value="5" required>
        </label>
        <label>Price Per Session
          <input type="number" name="price_per_session" min="0" step="0.01" value="100">
        </label>
        <label>Initial Payment (optional)
          <input type="number" name="initial_payment" min="0" step="0.01" placeholder="0.00">
        </label>
        <label style="flex:1 1 100%">Notes
          <textarea name="notes" placeholder="Client goals, schedule preferences, etc."></textarea>
        </label>
        <button class="btn brand" type="submit">Create Package</button>
        <div class="flash-inline" data-role="flash"></div>
      </form>
    </div>

    <?php if (empty($packages)): ?>
      <div class="ts-card">
        <div class="ts-packages-empty">No session packages found. Use the “New Package” button to create one.</div>
      </div>
    <?php else: ?>
      <?php foreach ($packages as $pkg):
        $pid = (int)$pkg['id'];
        $clientName = ts_client_name($pkg);
        $remaining = (int)($pkg['remaining_sessions'] ?? 0);
        $completed = (int)($pkg['completed_count'] ?? 0);
        $scheduled = (int)($pkg['scheduled_open'] ?? 0);
        $purchased = (int)($pkg['purchased_sessions'] ?? 0);
        $balance = (float)($pkg['balance'] ?? 0);
        $price = (float)($pkg['price_per_session'] ?? 0);
        $value = (float)($pkg['package_value'] ?? 0);
        $payments = (float)($pkg['payments_total'] ?? 0);
        $refunds = (float)($pkg['refunds_total'] ?? 0);
      ?>
        <div class="ts-card" id="package-<?php echo $pid; ?>" data-package="<?php echo $pid; ?>">
          <div class="ts-section-title">
            <div>
              <h2><?php echo h($clientName); ?></h2>
              <p><?php echo h($pkg['package_name'] ?? 'Untitled Package'); ?></p>
            </div>
            <div class="ts-actions">
              <button class="btn small" type="button" data-add-sessions="<?php echo $pid; ?>">Add Sessions</button>
              <button class="btn small warn" type="button" data-remove-sessions="<?php echo $pid; ?>">Remove Sessions</button>
            </div>
          </div>

          <div class="ts-grid">
            <div class="ts-summary-item">
              <span class="label">Purchased</span>
              <span class="value"><?php echo $purchased; ?></span>
              <span class="muted"><?php echo ts_money($price); ?> per session</span>
            </div>
            <div class="ts-summary-item">
              <span class="label">Completed</span>
              <span class="value" data-ts-package-used="<?php echo $pid; ?>"><?php echo $completed; ?></span>
              <span class="muted">Remaining <span data-ts-package-remaining="<?php echo $pid; ?>"><?php echo $remaining; ?></span></span>
            </div>
            <div class="ts-summary-item">
              <span class="label">Scheduled</span>
              <span class="value" data-ts-package-scheduled="<?php echo $pid; ?>"><?php echo $scheduled; ?></span>
              <span class="muted">Next: <?php echo ts_datetime($pkg['next_session_at'] ?? null); ?></span>
            </div>
            <div class="ts-summary-item">
              <span class="label">Financials</span>
              <span class="value"><?php echo ts_money($balance); ?></span>
              <span class="muted">Paid <?php echo ts_money($payments); ?> · Refunded <?php echo ts_money($refunds); ?></span>
            </div>
          </div>

          <h3>Scheduled Sessions</h3>
          <table class="ts-sessions">
            <thead>
              <tr>
                <th>When</th>
                <th>Status</th>
                <th>Completion</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pkg['sessions'])): ?>
                <tr><td colspan="4" class="ts-muted">No sessions scheduled yet.</td></tr>
              <?php else: foreach ($pkg['sessions'] as $session):
                $sid = (int)$session['id'];
                $status = strtolower((string)($session['status'] ?? 'scheduled'));
                $scheduledStartIso = $session['scheduled_start'] ?? null;
                $scheduledEndIso = $session['scheduled_end'] ?? null;
                $scheduledRange = ts_datetime($scheduledStartIso);
                if (!empty($scheduledEndIso)) {
                    $scheduledRange .= ' – ' . ts_datetime($scheduledEndIso);
                }
                $actualStartAt = $session['actual_start_at'] ?? null;
                $actualEndAt = $session['actual_end_at'] ?? null;
                $durationSeconds = isset($session['duration_seconds']) ? (int)$session['duration_seconds'] : null;
                $actualStartLabel = $actualStartAt ? ts_datetime($actualStartAt) : null;
                $actualEndLabel = $actualEndAt ? ts_datetime($actualEndAt) : null;
                $durationLabel = $durationSeconds !== null ? ts_format_duration($durationSeconds) : '';
              ?>
                <tr data-session="<?php echo $sid; ?>"
                    data-ts-session="<?php echo $sid; ?>"
                    data-ts-status="<?php echo h($status); ?>"
                    data-ts-start="<?php echo h($scheduledStartIso ?? ''); ?>"
                    data-ts-end="<?php echo h($scheduledEndIso ?? ''); ?>"
                    data-ts-actual-start="<?php echo h($actualStartAt ?? ''); ?>"
                    data-ts-actual-end="<?php echo h($actualEndAt ?? ''); ?>"
                    data-ts-duration="<?php echo $durationSeconds !== null ? $durationSeconds : ''; ?>"
                    data-ts-package="<?php echo $pid; ?>">
                  <td>
                    <strong><?php echo h($scheduledRange); ?></strong>
                    <?php if (!empty($session['notes'])): ?>
                      <div class="ts-muted">Notes: <?php echo h($session['notes']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td data-ts-status-cell><?php echo ts_status_badge($status); ?></td>
                  <td>
                    <div data-ts-start-label>
                      <?php if ($actualStartLabel): ?>
                        Started <?php echo h($actualStartLabel); ?>
                      <?php else: ?>
                        <span class="ts-muted">Not started yet</span>
                      <?php endif; ?>
                    </div>
                    <div data-ts-end-label>
                      <?php if ($actualEndLabel): ?>
                        Ended <?php echo h($actualEndLabel); ?>
                        <?php if ($durationLabel !== ''): ?>
                          <span class="ts-muted">· <?php echo h($durationLabel); ?></span>
                        <?php endif; ?>
                      <?php elseif ($status === 'in_progress' && $actualStartAt): ?>
                        <span class="ts-muted">In progress</span>
                      <?php else: ?>
                        <span class="ts-muted">Awaiting completion</span>
                      <?php endif; ?>
                    </div>
                    <div class="ts-session-timer" data-ts-timer<?php echo ($status === 'in_progress' && $actualStartAt && !$actualEndAt) ? '' : ' hidden'; ?>>00:00:00</div>
                  </td>
                  <td>
                    <div class="ts-session-controls" data-ts-controls="<?php echo $sid; ?>">
                      <button type="button" class="ts-session-btn" data-ts-start data-ts-session-id="<?php echo $sid; ?>">Start Session</button>
                      <button type="button" class="ts-session-btn" data-ts-end data-ts-session-id="<?php echo $sid; ?>">End Session</button>
                    </div>
                    <div class="ts-actions">
                      <?php if ($status === 'completed'): ?>
                        <form class="js-ajax" data-refresh="1">
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="action" value="toggle_completion">
                          <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                          <input type="hidden" name="complete" value="0">
                          <button class="btn small" type="submit">Reopen</button>
                        </form>
                      <?php else: ?>
                        <button class="btn small" type="button" data-reschedule="<?php echo $sid; ?>">Reschedule</button>
                        <form class="js-ajax" data-refresh="1">
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="action" value="delete_session">
                          <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                          <button class="btn small warn" type="submit">Cancel</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <tr class="ts-reschedule-row" id="reschedule-<?php echo $sid; ?>" style="display:none;">
                  <td colspan="4">
                    <form class="ts-inline-form js-ajax" data-refresh="1">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="reschedule_session">
                      <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                      <label>Start
                        <input type="datetime-local" name="scheduled_start" value="<?php echo $session['scheduled_start'] ? date('Y-m-d\TH:i', strtotime($session['scheduled_start'])) : ''; ?>" required>
                      </label>
                      <label>End
                        <input type="datetime-local" name="scheduled_end" value="<?php echo $session['scheduled_end'] ? date('Y-m-d\TH:i', strtotime($session['scheduled_end'])) : ''; ?>">
                      </label>
                      <label>Notes
                        <input type="text" name="notes" value="<?php echo h($session['notes'] ?? ''); ?>" placeholder="Optional note">
                      </label>
                      <button class="btn brand" type="submit">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>

          <form class="ts-inline-form js-ajax" data-refresh="1">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="schedule_session">
            <input type="hidden" name="package_id" value="<?php echo $pid; ?>">
            <label>Start
              <input type="datetime-local" name="scheduled_start" required>
            </label>
            <label>End
              <input type="datetime-local" name="scheduled_end">
            </label>
            <label>Notes
              <input type="text" name="notes" placeholder="Optional note">
            </label>
            <button class="btn brand" type="submit">Schedule Session</button>
          </form>

          <div class="ts-section-title">
            <h3>Transactions</h3>
            <div class="ts-actions">
              <button class="btn small" type="button" data-record-payment="<?php echo $pid; ?>">Record Payment</button>
              <button class="btn small warn" type="button" data-record-refund="<?php echo $pid; ?>">Record Refund</button>
            </div>
          </div>
          <div class="ts-timeline" id="transactions-<?php echo $pid; ?>">
            <?php if (empty($pkg['transactions'])): ?>
              <div class="ts-muted">No payments or refunds recorded yet.</div>
            <?php else: foreach ($pkg['transactions'] as $txn): ?>
              <div class="ts-transaction">
                <strong><?php echo h(ucfirst($txn['txn_type'])); ?> · <?php echo ts_money($txn['amount']); ?></strong>
                <span><?php echo ts_datetime($txn['created_at']); ?></span>
                <?php if (!empty($txn['description'])): ?><span><?php echo h($txn['description']); ?></span><?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <div class="ts-modal-backdrop" id="tsModal" role="dialog" aria-modal="true">
    <div class="ts-modal" id="tsModalContent">
      <h3 id="tsModalTitle">Adjust Sessions</h3>
      <form class="ts-inline-form js-ajax" data-refresh="1" id="tsModalForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="adjust_sessions">
        <input type="hidden" name="package_id" value="">
        <input type="hidden" name="direction" value="">
        <label id="tsModalCountLabel">Sessions
          <input type="number" name="count" min="1" step="1" value="1" required>
        </label>
        <label id="tsModalAmountLabel">Amount
          <input type="number" name="amount" min="0" step="0.01" value="0.00">
        </label>
        <label style="flex:1 1 100%">Notes
          <textarea name="notes" placeholder="Optional notes"></textarea>
        </label>
        <div class="actions">
          <button class="btn" type="button" id="tsModalCancel">Cancel</button>
          <button class="btn brand" type="submit" id="tsModalSubmit">Save</button>
        </div>
        <div class="flash-inline" data-role="flash"></div>
      </form>
    </div>
  </div>

  <script>
    window.__CSRF = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;
    (function(){
      const packageForm = document.getElementById('tsPackageForm');
      const openBtn = document.getElementById('btnOpenPackageForm');
      if (openBtn && packageForm) {
        openBtn.addEventListener('click', () => {
          const visible = packageForm.style.display !== 'none';
          packageForm.style.display = visible ? 'none' : 'block';
        });
      }

      function showFlash(container, ok, message){
        if (!container) return;
        container.textContent = message;
        container.classList.remove('ok','err');
        container.classList.add(ok ? 'ok' : 'err');
        container.style.display = 'block';
      }

      async function submitAjax(form){
        const fd = new FormData(form);
        if (!fd.get('csrf_token')) {
          fd.set('csrf_token', window.__CSRF || '');
        }
        let data;
        try {
          const resp = await fetch('trainer_sessions_actions.php', {
            method: 'POST',
            body: fd,
          });
          data = await resp.json().catch(() => ({ ok: false, message: 'Unexpected response from server.' }));
        } catch (err) {
          const flash = form.querySelector('[data-role="flash"]');
          const msg = err instanceof Error ? err.message : 'Request failed.';
          if (flash) { showFlash(flash, false, msg); }
          else { alert(msg); }
          throw err;
        }
        const flash = form.querySelector('[data-role="flash"]');
        if (data && typeof data.message === 'string' && flash) {
          showFlash(flash, !!data.ok, data.message);
        }
        if (data && data.ok) {
          if (form.dataset.refresh === '1' || data.refresh) {
            setTimeout(() => window.location.reload(), 450);
          }
        }
        if (!data.ok && !flash) {
          alert(data.message || 'Action failed.');
        }
      }

      document.addEventListener('submit', function(e){
        const form = e.target;
        if (form.classList && form.classList.contains('js-ajax')) {
          e.preventDefault();
          submitAjax(form).catch(err => console.error(err));
        }
      });

      document.querySelectorAll('[data-reschedule]').forEach(btn => {
        btn.addEventListener('click', () => {
          const sid = btn.getAttribute('data-reschedule');
          const row = document.getElementById('reschedule-' + sid);
          if (row) {
            row.style.display = row.style.display === 'none' ? '' : 'none';
          }
        });
      });

      const csrfToken = window.__CSRF || '';
      const HALF_HOUR = 30 * 60 * 1000;
      const STATUS_BADGES = {
        scheduled: '<span class="status-pill good">Scheduled</span>',
        in_progress: '<span class="status-pill progress">In progress</span>',
        completed: '<span class="status-pill ok">Completed</span>',
        cancelled: '<span class="status-pill warn">Cancelled</span>',
      };

      function parseIsoTimestamp(iso) {
        if (!iso) return null;
        const value = Date.parse(iso);
        return Number.isFinite(value) ? value : null;
      }

      const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });

      function formatDateTime(iso) {
        const ts = parseIsoTimestamp(iso);
        if (ts === null) return '';
        return dateTimeFormatter.format(new Date(ts));
      }

      function formatDuration(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) return '';
        const total = Math.floor(seconds);
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;
        return [hours, minutes, secs].map(part => String(part).padStart(2, '0')).join(':');
      }

      const sessionRows = new Map();
      document.querySelectorAll('[data-ts-session]').forEach(row => {
        const sessionId = row.getAttribute('data-ts-session');
        if (!sessionId) return;
        const controls = row.querySelector('[data-ts-controls]');
        const startBtn = controls ? controls.querySelector('[data-ts-start]') : null;
        const endBtn = controls ? controls.querySelector('[data-ts-end]') : null;
        const timerEl = row.querySelector('[data-ts-timer]');
        const statusCell = row.querySelector('[data-ts-status-cell]');
        const startLabel = row.querySelector('[data-ts-start-label]');
        const endLabel = row.querySelector('[data-ts-end-label]');
        sessionRows.set(String(sessionId), { row, startBtn, endBtn, timerEl, statusCell, startLabel, endLabel });
      });

      const packageSummaries = new Map();
      document.querySelectorAll('[data-ts-package-used]').forEach(el => {
        const pkgId = el.getAttribute('data-ts-package-used');
        if (!pkgId) return;
        const entry = packageSummaries.get(pkgId) || {};
        entry.used = el;
        packageSummaries.set(pkgId, entry);
      });
      document.querySelectorAll('[data-ts-package-remaining]').forEach(el => {
        const pkgId = el.getAttribute('data-ts-package-remaining');
        if (!pkgId) return;
        const entry = packageSummaries.get(pkgId) || {};
        entry.remaining = el;
        packageSummaries.set(pkgId, entry);
      });
      document.querySelectorAll('[data-ts-package-scheduled]').forEach(el => {
        const pkgId = el.getAttribute('data-ts-package-scheduled');
        if (!pkgId) return;
        const entry = packageSummaries.get(pkgId) || {};
        entry.scheduled = el;
        packageSummaries.set(pkgId, entry);
      });

      function computeWindow(row) {
        const startIso = parseIsoTimestamp(row.dataset.tsStart || '');
        if (startIso === null) {
          return { within: false };
        }
        const now = Date.now();
        const windowStart = startIso - HALF_HOUR;
        if (now < windowStart) {
          return { within: false, windowStart };
        }
        const endIso = parseIsoTimestamp(row.dataset.tsEnd || '');
        const windowEnd = endIso !== null ? endIso + HALF_HOUR : null;
        if (windowEnd !== null && now > windowEnd) {
          return { within: false, windowStart, windowEnd };
        }
        return { within: true, windowStart, windowEnd };
      }

      function applySessionPayload(row, payload) {
        if (!row || !payload) return;
        if (typeof payload.status === 'string') {
          row.dataset.tsStatus = payload.status.toLowerCase();
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'scheduled_start')) {
          row.dataset.tsStart = payload.scheduled_start || '';
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'scheduled_end')) {
          row.dataset.tsEnd = payload.scheduled_end || '';
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'actual_start_at')) {
          row.dataset.tsActualStart = payload.actual_start_at || '';
          if (!payload.actual_end_at) {
            row.dataset.tsDuration = '';
          }
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'actual_end_at')) {
          row.dataset.tsActualEnd = payload.actual_end_at || '';
          row.dataset.tsDuration = '';
          const startTs = parseIsoTimestamp(row.dataset.tsActualStart || '');
          const endTs = parseIsoTimestamp(payload.actual_end_at || '');
          if (startTs !== null && endTs !== null && endTs >= startTs) {
            row.dataset.tsDuration = String(Math.floor((endTs - startTs) / 1000));
          }
        }
      }

      function updateStatusCell(entry) {
        if (!entry.statusCell || !entry.row) return;
        const status = (entry.row.dataset.tsStatus || '').toLowerCase();
        entry.statusCell.innerHTML = STATUS_BADGES[status] || `<span class="status-pill">${status || 'Unknown'}</span>`;
      }

      function updateLabels(entry) {
        if (!entry.row) return;
        const status = (entry.row.dataset.tsStatus || '').toLowerCase();
        const actualStartIso = entry.row.dataset.tsActualStart || '';
        const actualEndIso = entry.row.dataset.tsActualEnd || '';
        const durationSeconds = entry.row.dataset.tsDuration ? parseInt(entry.row.dataset.tsDuration, 10) : null;

        if (entry.startLabel) {
          entry.startLabel.innerHTML = '';
          if (actualStartIso) {
            entry.startLabel.appendChild(document.createTextNode(`Started ${formatDateTime(actualStartIso)}`));
          } else {
            const span = document.createElement('span');
            span.className = 'ts-muted';
            span.textContent = 'Not started yet';
            entry.startLabel.appendChild(span);
          }
        }

        if (entry.endLabel) {
          entry.endLabel.innerHTML = '';
          if (actualEndIso) {
            entry.endLabel.appendChild(document.createTextNode(`Ended ${formatDateTime(actualEndIso)}`));
            if (Number.isFinite(durationSeconds) && durationSeconds !== null) {
              const span = document.createElement('span');
              span.className = 'ts-muted';
              span.textContent = `· ${formatDuration(durationSeconds)}`;
              entry.endLabel.appendChild(document.createTextNode(' '));
              entry.endLabel.appendChild(span);
            }
          } else if (status === 'in_progress' && actualStartIso) {
            const span = document.createElement('span');
            span.className = 'ts-muted';
            span.textContent = 'In progress';
            entry.endLabel.appendChild(span);
          } else {
            const span = document.createElement('span');
            span.className = 'ts-muted';
            span.textContent = 'Awaiting completion';
            entry.endLabel.appendChild(span);
          }
        }
      }

      function updateButtons(entry) {
        if (!entry.row) return;
        const status = (entry.row.dataset.tsStatus || '').toLowerCase();
        const actualStart = parseIsoTimestamp(entry.row.dataset.tsActualStart || '');
        const actualEnd = parseIsoTimestamp(entry.row.dataset.tsActualEnd || '');
        const { within } = computeWindow(entry.row);

        if (entry.startBtn) {
          entry.startBtn.classList.remove('is-started', 'is-complete', 'is-cancelled');
          let label = 'Start Session';
          entry.startBtn.disabled = true;
          entry.startBtn.setAttribute('aria-disabled', 'true');
          if (status === 'completed') {
            label = 'Session Completed';
            entry.startBtn.classList.add('is-complete');
          } else if (status === 'cancelled') {
            label = 'Session Cancelled';
            entry.startBtn.classList.add('is-cancelled');
          } else if (status === 'in_progress' || actualStart !== null) {
            label = 'Session Started';
            entry.startBtn.classList.add('is-started');
          } else if (within && !entry.startBtn.classList.contains('is-processing')) {
            entry.startBtn.disabled = false;
            entry.startBtn.removeAttribute('aria-disabled');
          }
          entry.startBtn.textContent = label;
        }

        if (entry.endBtn) {
          entry.endBtn.classList.remove('is-complete', 'is-cancelled');
          let label = 'End Session';
          entry.endBtn.disabled = true;
          entry.endBtn.setAttribute('aria-disabled', 'true');
          if (status === 'completed' || actualEnd !== null) {
            label = 'Session Completed';
            entry.endBtn.classList.add('is-complete');
          } else if (status === 'cancelled') {
            label = 'Session Cancelled';
            entry.endBtn.classList.add('is-cancelled');
          } else if ((status === 'in_progress' || actualStart !== null) && within && !entry.endBtn.classList.contains('is-processing')) {
            entry.endBtn.disabled = false;
            entry.endBtn.removeAttribute('aria-disabled');
          }
          entry.endBtn.textContent = label;
        }
      }

      function updateTimer(entry) {
        if (!entry.timerEl || !entry.row) return;
        const status = (entry.row.dataset.tsStatus || '').toLowerCase();
        const actualStart = parseIsoTimestamp(entry.row.dataset.tsActualStart || '');
        const actualEnd = parseIsoTimestamp(entry.row.dataset.tsActualEnd || '');
        if (status === 'in_progress' && actualStart !== null && actualEnd === null) {
          const diffSeconds = Math.floor((Date.now() - actualStart) / 1000);
          entry.timerEl.textContent = formatDuration(diffSeconds);
          entry.timerEl.hidden = false;
        } else {
          entry.timerEl.hidden = true;
        }
      }

      function refreshSession(entry) {
        updateStatusCell(entry);
        updateLabels(entry);
        updateButtons(entry);
        updateTimer(entry);
      }

      function refreshAllSessions() {
        sessionRows.forEach(refreshSession);
      }

      refreshAllSessions();
      if (sessionRows.size) {
        window.setInterval(() => {
          sessionRows.forEach(updateTimer);
        }, 1000);
        window.setInterval(() => {
          sessionRows.forEach(updateButtons);
        }, 60000);
      }

      async function sendSessionAction(sessionId, action) {
        const params = new URLSearchParams();
        params.set('action', action);
        params.set('session_id', sessionId);
        if (csrfToken) {
          params.set('csrf_token', csrfToken);
        }
        const response = await fetch('trainer_sessions_actions.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString(),
          credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || !payload.ok) {
          const message = payload && payload.message ? payload.message : 'Request failed. Please try again.';
          throw new Error(message);
        }
        return payload;
      }

      function updatePackageTotals(pkgTotals) {
        if (!pkgTotals) return;
        const pkgId = String(pkgTotals.package_id || pkgTotals.packageId || '');
        if (!pkgId) return;
        const record = packageSummaries.get(pkgId);
        if (!record) return;
        if (record.used && typeof pkgTotals.used !== 'undefined') {
          record.used.textContent = pkgTotals.used;
        }
        if (record.remaining && typeof pkgTotals.remaining !== 'undefined') {
          record.remaining.textContent = pkgTotals.remaining;
        }
        if (record.scheduled && typeof pkgTotals.scheduled !== 'undefined') {
          record.scheduled.textContent = pkgTotals.scheduled;
        }
      }

      sessionRows.forEach(entry => {
        const sessionId = entry.row ? entry.row.dataset.tsSession : null;
        if (!sessionId) return;
        if (entry.startBtn) {
          entry.startBtn.addEventListener('click', async () => {
            if (entry.startBtn.disabled || entry.startBtn.classList.contains('is-processing')) return;
            const originalText = entry.startBtn.textContent;
            entry.startBtn.classList.add('is-processing');
            entry.startBtn.textContent = 'Starting…';
            entry.startBtn.disabled = true;
            entry.startBtn.setAttribute('aria-disabled', 'true');
            try {
              const payload = await sendSessionAction(sessionId, 'start_session');
              applySessionPayload(entry.row, payload.session || null);
              entry.startBtn.classList.remove('is-processing');
              refreshSession(entry);
            } catch (error) {
              entry.startBtn.classList.remove('is-processing');
              entry.startBtn.textContent = originalText || 'Start Session';
              refreshSession(entry);
              window.alert(error && error.message ? error.message : 'Unable to start the session.');
            }
          });
        }
        if (entry.endBtn) {
          entry.endBtn.addEventListener('click', async () => {
            if (entry.endBtn.disabled || entry.endBtn.classList.contains('is-processing')) return;
            const originalText = entry.endBtn.textContent;
            entry.endBtn.classList.add('is-processing');
            entry.endBtn.textContent = 'Ending…';
            entry.endBtn.disabled = true;
            entry.endBtn.setAttribute('aria-disabled', 'true');
            try {
              const payload = await sendSessionAction(sessionId, 'end_session');
              applySessionPayload(entry.row, payload.session || null);
              updatePackageTotals(payload.package_totals || null);
              entry.endBtn.classList.remove('is-processing');
              refreshSession(entry);
            } catch (error) {
              entry.endBtn.classList.remove('is-processing');
              entry.endBtn.textContent = originalText || 'End Session';
              refreshSession(entry);
              window.alert(error && error.message ? error.message : 'Unable to end the session.');
            }
          });
        }
      });

      const modal = document.getElementById('tsModal');
      const modalForm = document.getElementById('tsModalForm');
      const modalTitle = document.getElementById('tsModalTitle');
      const modalSubmit = document.getElementById('tsModalSubmit');
      const modalDir = modalForm.querySelector('input[name="direction"]');
      const modalPackage = modalForm.querySelector('input[name="package_id"]');
      const amountLabel = document.getElementById('tsModalAmountLabel');
      const countLabel = document.getElementById('tsModalCountLabel');

      function openModal(config){
        modalTitle.textContent = config.title;
        modalPackage.value = config.packageId;
        modalDir.value = config.direction;
        modalSubmit.textContent = config.submitText;
        modalForm.querySelector('input[name="count"]').value = config.defaultCount || 1;
        modalForm.querySelector('input[name="amount"]').value = config.defaultAmount || 0;
        modalForm.querySelector('textarea[name="notes"]').value = '';
        amountLabel.style.display = config.showAmount ? '' : 'none';
        countLabel.style.display = config.showCount === false ? 'none' : '';
        modal.style.display = 'flex';
        const flash = modalForm.querySelector('[data-role="flash"]');
        if (flash) { flash.style.display = 'none'; flash.textContent = ''; }
      }

      function closeModal(){ modal.style.display = 'none'; }

      document.getElementById('tsModalCancel').addEventListener('click', closeModal);
      modal.addEventListener('click', (e)=>{ if (e.target === modal) closeModal(); });

      document.querySelectorAll('[data-add-sessions]').forEach(btn => {
        btn.addEventListener('click', () => {
          const pid = btn.getAttribute('data-add-sessions');
          openModal({
            title: 'Add Sessions',
            packageId: pid,
            direction: 'add',
            submitText: 'Add Sessions',
            defaultCount: 1,
            defaultAmount: 0,
            showAmount: false,
            showCount: true
          });
        });
      });

      document.querySelectorAll('[data-remove-sessions]').forEach(btn => {
        btn.addEventListener('click', () => {
          const pid = btn.getAttribute('data-remove-sessions');
          openModal({
            title: 'Remove Sessions & Optional Refund',
            packageId: pid,
            direction: 'remove',
            submitText: 'Remove Sessions',
            defaultCount: 1,
            defaultAmount: 0,
            showAmount: true,
            showCount: true
          });
        });
      });

      document.querySelectorAll('[data-record-payment]').forEach(btn => {
        btn.addEventListener('click', () => {
          const pid = btn.getAttribute('data-record-payment');
          openModal({
            title: 'Record Payment',
            packageId: pid,
            direction: 'payment',
            submitText: 'Record Payment',
            defaultCount: 1,
            defaultAmount: 0,
            showAmount: true,
            showCount: false
          });
        });
      });

      document.querySelectorAll('[data-record-refund]').forEach(btn => {
        btn.addEventListener('click', () => {
          const pid = btn.getAttribute('data-record-refund');
          openModal({
            title: 'Record Refund',
            packageId: pid,
            direction: 'refund',
            submitText: 'Record Refund',
            defaultCount: 1,
            defaultAmount: 0,
            showAmount: true,
            showCount: false
          });
        });
      });
    })();
  </script>
</body>
</html>
