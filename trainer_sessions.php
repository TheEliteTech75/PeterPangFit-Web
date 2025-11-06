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
if (($isTrainerAdmin || $isAdmin)) {
    $scopeCandidate = strtolower((string)($_GET['scope'] ?? 'my'));
    if (in_array($scopeCandidate, ['my', 'all', 'unassigned'], true)) {
        $rosterScope = $scopeCandidate;
    }
}

$rosterTrainerId = $trainerId;
if ($isAdmin && isset($_GET['trainer_id'])) {
    $rosterTrainerId = max(0, (int)$_GET['trainer_id']);
}

$rosterRows = [];
if ($isTrainerAdmin || $isAdmin) {
    switch ($rosterScope) {
        case 'all':
            $rosterRows = ppf_trainer_sessions_collect_client_overview($conn, ['include_unassigned' => true]);
            break;
        case 'unassigned':
            $rosterRows = array_values(array_filter(
                ppf_trainer_sessions_collect_client_overview($conn, ['include_unassigned' => true]),
                fn($row) => (int)($row['assigned_trainer_id'] ?? 0) === 0
            ));
            break;
        default:
            if ($rosterTrainerId > 0) {
                $rosterRows = ppf_trainer_sessions_collect_client_overview($conn, ['trainer_id' => $rosterTrainerId]);
            } else {
                $rosterRows = ppf_trainer_sessions_collect_client_overview($conn, ['include_unassigned' => false]);
            }
            break;
    }
} else {
    $options = [];
    if ($rosterTrainerId > 0) {
        $options['trainer_id'] = $rosterTrainerId;
    }
    $rosterRows = ppf_trainer_sessions_collect_client_overview($conn, $options);
}

$clientSummaries = [];
$clientDetails = [];
$availablePackagesForClients = [];
$clientRosterPayload = [];

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
        $packages = ppf_trainer_sessions_collect_client_packages($conn, $clientId, $rosterTrainerId > 0 ? $rosterTrainerId : null);
        $transactionsCount = 0;
        $sessionRows = [];
        $packageCards = [];
        $packageOptions = [];
        $packagePayload = [];
        $sessionPayload = [];

        foreach ($packages as $package) {
            $packageId = (int)($package['id'] ?? 0);
            $packageName = $package['package_name'] ?? 'Package';
            $pricePerSession = (float)($package['price_per_session'] ?? 0);
            $purchasedSessions = (int)($package['purchased_sessions'] ?? 0);
            $completedSessions = (int)($package['completed_count'] ?? 0);
            $cancelledSessions = (int)($package['cancelled_count'] ?? 0);
            $refundedSessions = 0;
            $packageCreatedAt = $package['created_at'] ?? null;

            $packageCards[] = [
                'id' => $packageId,
                'name' => $packageName,
                'purchased_sessions' => $purchasedSessions,
                'price_per_session' => ts_format_currency($pricePerSession),
                'created_at' => ts_format_date($packageCreatedAt),
            ];
            $packageOptions[] = [
                'id' => $packageId,
                'label' => $packageName . ' • ' . $purchasedSessions . ' sessions',
            ];
            foreach ($package['transactions'] as $txn) {
                $transactionsCount++;
            }

            $packageSessionPayload = [];
            foreach ($package['sessions'] as $session) {
                $status = strtolower((string)($session['status'] ?? 'scheduled'));
                if ($status === 'refunded') {
                    $refundedSessions++;
                }
                $sessionRow = [
                    'session_id' => (int)($session['id'] ?? 0),
                    'package_id' => $packageId,
                    'package_name' => $packageName,
                    'purchase_date' => ts_format_date($packageCreatedAt),
                    'scheduled_date' => ts_format_date($session['scheduled_start'] ?? null),
                    'start_time' => ts_format_time($session['scheduled_start'] ?? null),
                    'end_time' => ts_format_time($session['scheduled_end'] ?? null),
                    'status_label' => ts_session_status_label($session, $now),
                    'is_active' => ts_is_active_session($session, $now),
                    'raw' => $session,
                ];
                $sessionRows[] = $sessionRow;

                $sessionPayloadItem = [
                    'id' => (int)($session['id'] ?? 0),
                    'package_id' => $packageId,
                    'package_name' => $packageName,
                    'status' => $status,
                    'scheduled_start' => $session['scheduled_start'] ?? null,
                    'scheduled_end' => $session['scheduled_end'] ?? null,
                    'price_per_session' => $pricePerSession,
                    'purchase_date' => $packageCreatedAt,
                    'notes' => $session['notes'] ?? '',
                    'public_token' => $session['public_token'] ?? null,
                    'is_editable' => !in_array($status, ['completed', 'refunded'], true),
                    'is_removable' => !in_array($status, ['completed', 'refunded'], true),
                ];
                $packageSessionPayload[] = $sessionPayloadItem;
                $sessionPayload[] = $sessionPayloadItem;
            }

            $remainingSessions = $package['remaining_sessions'] ?? max(0, $purchasedSessions - $completedSessions - $cancelledSessions - $refundedSessions);

            $packagePayload[] = [
                'id' => $packageId,
                'name' => $packageName,
                'price_per_session' => $pricePerSession,
                'purchased_sessions' => $purchasedSessions,
                'completed_sessions' => $completedSessions,
                'cancelled_sessions' => $cancelledSessions,
                'refunded_sessions' => $refundedSessions,
                'remaining_sessions' => (int)$remainingSessions,
                'sessions' => $packageSessionPayload,
            ];
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
            'package_payload' => $packagePayload,
            'session_payload' => $sessionPayload,
        ];

        $clientRosterPayload[$clientId] = [
            'summary' => $clientSummaries[$clientId],
            'packages' => $packagePayload,
            'sessions' => $sessionPayload,
        ];
    }

$availablePackagesForClients = array_map(function ($pkg) {
    return [
        'id' => (int)($pkg['id'] ?? 0),
        'title' => $pkg['title'] ?? 'Package',
        'label' => ($pkg['title'] ?? 'Package') . ' • ' . ts_format_currency($pkg['total_price'] ?? 0) . ' (' . (int)($pkg['session_count'] ?? 1) . ' sessions)',
    ];
}, $catalogPackages);

?>
<style>
  html, body {
    margin: 0;
    padding: 0;
    background: var(--bg, #05070d);
    color: var(--text, #f8fafc);
    font: 14px/1.5 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }
  a {
    color: inherit;
    text-decoration: none;
  }
  .wrap {
    width: 100%;
    max-width: 100%;
    margin: 24px auto;
    padding: 0 clamp(14px, 3vw, 28px);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: rgba(30, 41, 59, 0.65);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    color: var(--text, #f8fafc);
    padding: 8px 12px;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    min-height: 34px;
    line-height: 1.1;
    font-size: 14px;
    transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
  }
  .btn:hover,
  .btn:focus-visible {
    background: rgba(56, 189, 248, 0.18);
    border-color: rgba(56, 189, 248, 0.35);
    box-shadow: 0 16px 32px rgba(14, 165, 233, 0.25);
    transform: translateY(-1px);
    outline: none;
  }
  .btn:focus-visible {
    outline: 2px solid rgba(56, 189, 248, 0.5);
    outline-offset: 2px;
  }
  .btn.small {
    padding: 6px 10px;
    font-size: 12px;
    min-height: 30px;
  }
  .btn.brand {
    background: var(--brand, #38bdf8);
    border-color: var(--brand, #38bdf8);
    color: #0b1120;
  }
  .btn.brand:hover,
  .btn.brand:focus-visible {
    background: color-mix(in srgb, var(--brand, #38bdf8) 80%, #ffffff 20%);
    border-color: transparent;
    color: #020617;
  }
  .btn.secondary {
    background: rgba(15, 23, 42, 0.7);
    border-color: rgba(148, 163, 184, 0.3);
    color: rgba(226, 232, 240, 0.92);
  }
  .btn.danger {
    background: rgba(239, 68, 68, 0.18);
    border-color: rgba(248, 113, 113, 0.35);
    color: #fee2e2;
  }
  .btn.danger:hover,
  .btn.danger:focus-visible {
    background: rgba(239, 68, 68, 0.28);
    border-color: rgba(248, 113, 113, 0.45);
    color: #fff1f2;
  }
  .btn.tertiary {
    background: rgba(8, 13, 23, 0.72);
    border-color: rgba(148, 163, 184, 0.24);
    color: rgba(203, 213, 225, 0.9);
  }
  .btn[disabled],
  .btn[disabled]:hover {
    opacity: 0.55;
    cursor: not-allowed;
    background: rgba(15, 23, 42, 0.45);
    border-color: rgba(148, 163, 184, 0.25);
    color: rgba(148, 163, 184, 0.75);
    transform: none;
    box-shadow: none;
  }
  .session-mode-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    background: rgba(15, 23, 42, 0.72);
    text-transform: uppercase;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.35px;
    color: rgba(203, 213, 225, 0.88);
  }
  .session-mode-chip.is-admin {
    background: rgba(56, 189, 248, 0.2);
    border-color: rgba(56, 189, 248, 0.35);
    color: #e0f2fe;
  }
  .session-mode-chip.is-trainer {
    background: rgba(16, 185, 129, 0.18);
    border-color: rgba(16, 185, 129, 0.32);
    color: #bbf7d0;
  }
  .session-layout {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
  .panel {
    background: var(--panel, rgba(15, 23, 42, 0.82));
    border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
    border-radius: 14px;
    box-shadow: 0 24px 48px rgba(2, 6, 23, 0.35);
    padding: 22px 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
  }
  .panel-title {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }
  .panel-title h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 0.2px;
  }
  .panel-subtitle {
    margin: 0;
    color: var(--muted, #9ba4c2);
    font-size: 13px;
    max-width: 520px;
  }
  .panel-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .panel-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .panel-body--flush {
    padding: 0;
    gap: 0;
  }
  .empty-state {
    border: 1px dashed rgba(148, 163, 184, 0.35);
    border-radius: 12px;
    padding: 24px 18px;
    text-align: center;
    color: var(--muted, #9ba4c2);
    font-size: 14px;
  }
  .empty-state p {
    margin: 0;
  }
  .package-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
  }
  .package-card {
    background: rgba(9, 14, 28, 0.92);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.24));
    border-radius: 12px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
  }
  .package-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 36px rgba(2, 6, 23, 0.38);
  }
  .package-card header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }
  .package-card h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
  }
  .package-card .package-price {
    font-size: 16px;
    font-weight: 700;
    color: var(--brand, #38bdf8);
  }
  .package-card dl {
    margin: 0;
    display: grid;
    gap: 10px;
  }
  .package-card dt {
    font-size: 12px;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: var(--muted, #9ba4c2);
  }
  .package-card dd {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
  }
  .filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
  }
  .muted {
    color: var(--muted, #9ba4c2);
    font-size: 12px;
  }
  .filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
  }
  .filter-group select,
  .filter-group input {
    background: rgba(8, 13, 23, 0.9);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 8px;
    padding: 8px 10px;
    color: var(--text, #f8fafc);
    font-size: 13px;
    min-width: 180px;
  }
  .filter-group.has-menu .column-toggle-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: rgba(9, 14, 28, 0.95);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 12px;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 180px;
    box-shadow: 0 18px 40px rgba(2, 6, 23, 0.45);
    z-index: 30;
  }
  .filter-group.has-menu .column-toggle-menu[hidden] {
    display: none !important;
  }
  .filter-group.has-menu .column-toggle-menu label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text, #f8fafc);
  }
  .table-wrapper {
    position: relative;
    overflow-x: auto;
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 12px;
    background: rgba(8, 13, 23, 0.75);
  }
  .table-wrapper table {
    width: 100%;
    border-collapse: collapse;
    min-width: 960px;
  }
  .table-wrapper thead th {
    background: rgba(7, 12, 23, 0.95);
    color: var(--muted, #9ba4c2);
    padding: 12px 14px;
    font-size: 12px;
    letter-spacing: 0.35px;
    text-transform: uppercase;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 1;
  }
  .table-wrapper tbody td {
    padding: 12px 14px;
    border-top: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    font-size: 13px;
    color: var(--text, #f8fafc);
  }
  .table-wrapper tbody tr {
    transition: background 0.18s ease, outline 0.18s ease;
    cursor: pointer;
  }
  .table-wrapper tbody tr:hover {
    background: #141a25;
  }
  .table-wrapper tbody tr.is-active,
  .table-wrapper tbody tr.expanded {
    background: #141a25;
    outline: 2px solid var(--brand, #38bdf8);
    outline-offset: -2px;
  }
  .table-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }
  .client-row {
    cursor: pointer;
  }
  .client-expansion {
    display: none;
  }
  .client-expansion.is-open {
    display: table-row;
  }
  .client-expansion > td {
    padding: 0;
    background: #0e121a;
    border-top: 1px solid var(--line, rgba(148, 163, 184, 0.26));
  }
  .client-detail-panel {
    padding: 18px;
    display: grid;
    gap: 16px;
    background: rgba(9, 14, 28, 0.94);
  }
  .detail-card {
    background: rgba(8, 13, 23, 0.9);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.24));
    border-radius: 12px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .detail-card header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }
  .metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
  }
  .metrics-grid .label {
    display: block;
    font-size: 11px;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: var(--muted, #9ba4c2);
  }
  .metrics-grid .value {
    font-size: 15px;
    font-weight: 600;
  }
  .inner-table {
    width: 100%;
    border-collapse: collapse;
    background: rgba(8, 13, 23, 0.82);
    border-radius: 10px;
    overflow: hidden;
  }
  .inner-table thead th {
    background: rgba(7, 12, 23, 0.95);
    color: var(--muted, #9ba4c2);
    padding: 10px 12px;
    font-size: 12px;
    letter-spacing: 0.35px;
    text-transform: uppercase;
  }
  .inner-table tbody td {
    padding: 10px 12px;
    border-top: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    font-size: 13px;
  }
  .inner-table tbody tr:hover {
    background: #141a25;
  }
  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(56, 189, 248, 0.18);
    color: #bae6fd;
    text-transform: capitalize;
  }
  .session-row.is-active .status-pill {
    background: rgba(16, 185, 129, 0.18);
    color: #bbf7d0;
  }
  .modal-backdrop {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(0, 0, 0, 0.6);
    z-index: 4000;
  }
  .modal-backdrop.hidden {
    display: none;
  }
  .modal {
    width: min(520px, 94vw);
    background: rgba(9, 14, 28, 0.96);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 14px;
    box-shadow: 0 24px 56px rgba(0, 0, 0, 0.55);
    display: flex;
    flex-direction: column;
    max-height: 90vh;
  }
  .modal-header {
    padding: 18px;
    border-bottom: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .modal-header h3 {
    margin: 0;
    font-size: 18px;
  }
  .modal-close {
    background: none;
    border: none;
    color: var(--muted, #9ba4c2);
    font-size: 24px;
    cursor: pointer;
  }
  .modal-body {
    padding: 18px;
    overflow-y: auto;
  }
  .form-grid {
    display: grid;
    gap: 14px;
  }
  .form-grid .field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .form-grid fieldset {
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 10px;
    padding: 12px 14px;
    margin: 0;
    background: rgba(8, 13, 23, 0.72);
  }
  .form-grid fieldset legend {
    font-size: 12px;
    font-weight: 700;
    padding: 0 4px;
    color: var(--muted, #94a3b8);
  }
  .form-grid label {
    font-size: 13px;
    font-weight: 600;
    color: var(--muted, #9ba4c2);
  }
  .form-grid .radio-group {
    display: grid;
    gap: 8px;
  }
  .form-grid .radio {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text, #f8fafc);
  }
  .form-grid .radio input[type="radio"] {
    width: 18px;
    height: 18px;
  }
  .form-grid .input,
  .form-grid select {
    background: rgba(8, 13, 23, 0.9);
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 8px;
    padding: 9px 12px;
    color: var(--text, #f8fafc);
    font-size: 14px;
  }
  .form-grid .split {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .form-grid .value {
    font-weight: 600;
  }
  .session-checklist {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 220px;
    overflow-y: auto;
    padding: 12px;
    border: 1px solid var(--line, rgba(148, 163, 184, 0.26));
    border-radius: 10px;
    background: rgba(8, 13, 23, 0.72);
  }
  .session-checklist label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text, #f8fafc);
  }
  .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 8px;
  }
  .form-actions .btn {
    min-width: 120px;
  }
  .sort-btn {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background: none;
    border: none;
    padding: 0;
    margin: 0;
    font: inherit;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    color: inherit;
  }
  .sort-btn:focus-visible {
    outline: 2px solid var(--brand, #38bdf8);
    outline-offset: 2px;
  }
  .sort-indicator {
    font-size: 10px;
    opacity: 0.45;
    line-height: 1;
  }
  .sort-btn[data-state="asc"] .sort-indicator::before {
    content: '▲';
  }
  .sort-btn[data-state="desc"] .sort-indicator::before {
    content: '▼';
  }
  .sort-btn[data-state="off"] .sort-indicator::before {
    content: '';
  }
  .table-wrapper::-webkit-scrollbar {
    height: 8px;
  }
  .table-wrapper::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.4);
    border-radius: 999px;
  }
  .visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    border: 0;
  }
  @media (max-width: 1100px) {
    .panel-actions {
      width: 100%;
      justify-content: flex-start;
    }
    .table-wrapper table {
      min-width: 840px;
    }
  }
  @media (max-width: 720px) {
    .panel {
      padding: 18px;
    }
    .panel-actions {
      flex-direction: column;
      align-items: flex-start;
    }
    .filter-group {
      width: 100%;
    }
    .filter-group input,
    .filter-group select {
      width: 100%;
    }
    .package-grid {
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    .client-detail-panel {
      padding: 16px;
    }
  }
  @media (max-width: 560px) {
    .btn {
      width: 100%;
    }
    .table-actions .btn {
      flex: 1 1 auto;
    }
  }
</style>
<main class="wrap" id="trainerSessions">
  <?php
  ppf_subheader([
    'title' => 'Trainer Sessions',
    'subtitle' => 'Coordinate pricing, packages, and scheduling for every client in one unified workspace.',
    'actions' => function () use ($pricingMode) {
        ?>
        <span class="session-mode-chip <?php echo $pricingMode === 'admin' ? 'is-admin' : 'is-trainer'; ?>">
          <?php echo $pricingMode === 'admin' ? 'Trainer Admin sets prices' : 'Trainers set own prices'; ?>
        </span>
        <?php
    },
  ]);
  ?>

  <?php
  $canCreateCatalogPackages = false;
  if ($pricingMode === 'trainer') {
      $canCreateCatalogPackages = in_array($roleKey, ['trainer', 'trainer_admin'], true) || $isAdmin;
  } else {
      $canCreateCatalogPackages = $isTrainerAdmin || $isAdmin;
  }
  ?>

  <div class="session-layout">
    <section class="panel session-panel" id="catalogCard">
      <header class="panel-header">
        <div class="panel-title">
          <h2>Packages</h2>
          <?php if ($pricingMode === 'trainer'): ?>
            <p class="panel-subtitle">Build personalised packages that reflect your coaching style and value.</p>
          <?php else: ?>
            <p class="panel-subtitle">Create global packages for your trainers to offer consistently across the team.</p>
          <?php endif; ?>
        </div>
        <div class="panel-actions">
          <?php if ($canCreateCatalogPackages): ?>
            <button class="btn brand" type="button" id="createPackageBtn">Create Package</button>
          <?php else: ?>
            <span class="muted" style="font-size:12px;">Contact a trainer admin to update packages.</span>
          <?php endif; ?>
        </div>
      </header>

      <div class="panel-body">
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
      </div>
    </section>

    <section class="panel session-panel" id="clientRoster">
      <header class="panel-header">
        <div class="panel-title">
          <h2>Clients</h2>
          <p class="panel-subtitle">Monitor client engagement, manage sessions, and keep financials balanced.</p>
        </div>
        <div class="panel-actions">
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
          <div class="filter-group has-menu">
            <button class="btn secondary" type="button" id="columnToggleBtn" aria-haspopup="true" aria-expanded="false">Columns</button>
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
      </header>

      <div class="panel-body panel-body--flush">
        <?php if (empty($clientSummaries)): ?>
          <div class="empty-state">
            <p>No clients are assigned yet. Once you add clients, they will appear here.</p>
          </div>
        <?php else: ?>
        <div class="table-wrapper">
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
            <th><button type="button" class="sort-btn" data-sort-key="first">First Name <span class="sort-indicator"></span></button></th>
            <th data-column="middle"><button type="button" class="sort-btn" data-sort-key="middle">Middle Name <span class="sort-indicator"></span></button></th>
            <th><button type="button" class="sort-btn" data-sort-key="last">Last Name <span class="sort-indicator"></span></button></th>
            <th><button type="button" class="sort-btn" data-sort-key="email">Email <span class="sort-indicator"></span></button></th>
            <th data-column="phone"><button type="button" class="sort-btn" data-sort-key="phone">Phone Number <span class="sort-indicator"></span></button></th>
            <th data-column="birthdate"><button type="button" class="sort-btn" data-sort-key="birthdate">Birthdate <span class="sort-indicator"></span></button></th>
            <th data-column="age"><button type="button" class="sort-btn" data-sort-key="age">Age <span class="sort-indicator"></span></button></th>
            <th data-column="height"><button type="button" class="sort-btn" data-sort-key="height">Height <span class="sort-indicator"></span></button></th>
            <th data-column="weight"><button type="button" class="sort-btn" data-sort-key="weight">Weight <span class="sort-indicator"></span></button></th>
            <th><button type="button" class="sort-btn" data-sort-key="purchased">Sessions Purchased <span class="sort-indicator"></span></button></th>
            <th><button type="button" class="sort-btn" data-sort-key="remaining">Sessions Remaining <span class="sort-indicator"></span></button></th>
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
                    <div class="table-wrapper">
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
                          <tr class="session-row<?php echo $session['is_active'] ? ' is-active' : ''; ?>" data-session-id="<?php echo h($session['session_id']); ?>" data-package-id="<?php echo h($session['package_id']); ?>" data-client-id="<?php echo h($cid); ?>">
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
        <?php endif; ?>
      </div>
    </section>
  </div>
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
  const clientRosterData = <?php echo json_encode($clientRosterPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

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

  const currencyFormatter = (typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function')
    ? new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })
    : null;

  function formatCurrency(value) {
    const amount = Number(value || 0);
    if (currencyFormatter) {
      try {
        return currencyFormatter.format(amount);
      } catch (err) {
        // fall through
      }
    }
    return '$' + amount.toFixed(2);
  }

  function splitDateTimeParts(iso) {
    if (!iso) return { date: '', time: '' };
    const normalized = String(iso).replace('T', ' ').trim();
    const parts = normalized.split(' ');
    if (parts.length === 1) {
      const segment = parts[0];
      const datePart = segment.slice(0, 10);
      const timePart = segment.length >= 16 ? segment.slice(11, 16) : '';
      return { date: datePart, time: timePart };
    }
    return { date: parts[0], time: (parts[1] || '').slice(0, 5) };
  }

  function toDateInputValue(iso) {
    return splitDateTimeParts(iso).date;
  }

  function toTimeInputValue(iso) {
    return splitDateTimeParts(iso).time;
  }

  function formatDisplayDateTime(iso) {
    if (!iso) return 'Unscheduled';
    const parts = splitDateTimeParts(iso);
    return parts.time ? `${parts.date} ${parts.time}` : parts.date;
  }

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
      <input type="hidden" name="price_mode" value="per_session">
      <div class="field">
        <label for="pkgTitle">Package Name</label>
        <input id="pkgTitle" name="title" class="input" required>
      </div>
      <fieldset class="field">
        <legend>Price Mode</legend>
        <label class="radio">
          <input type="radio" name="price_mode_display" value="per_session" checked disabled>
          Per Session
        </label>
        <small class="muted">Charge by the session. Additional pricing modes will be available soon.</small>
      </fieldset>
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
      <div class="field" data-expiration="duration" hidden aria-hidden="true">
        <label for="expiresValue">Duration</label>
        <div class="split">
          <input id="expiresValue" name="expires_value" type="number" min="1" class="input" value="30" disabled>
          <select id="expiresUnit" name="expires_unit" class="input" disabled>
            <option value="days">Days</option>
            <option value="weeks">Weeks</option>
            <option value="months">Months</option>
            <option value="years">Years</option>
          </select>
        </div>
      </div>
      <div class="field" data-expiration="date" hidden aria-hidden="true">
        <label for="expiresOn">Expiration Date</label>
        <input id="expiresOn" name="expires_on" type="date" class="input" disabled>
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
      totalLabel.textContent = formatCurrency(total);
    }

    function refreshExpiration(){
      const selected = wrapper.querySelector('input[name="expires_type"]:checked');
      const value = selected ? selected.value : 'none';
      expirationSections.forEach((section) => {
        const isActive = section.dataset.expiration === value;
        if (isActive) {
          section.removeAttribute('hidden');
        } else {
          section.setAttribute('hidden', '');
        }
        section.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        section.classList.toggle('hidden', !isActive);
        section.querySelectorAll('input, select, textarea').forEach((input) => {
          input.disabled = !isActive;
        });
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
    const setMenuVisibility = (shouldShow) => {
      columnToggleMenu.hidden = !shouldShow;
      columnToggleBtn.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
    };

    setMenuVisibility(false);

    columnToggleBtn.addEventListener('click', (event) => {
      event.preventDefault();
      const willOpen = columnToggleMenu.hidden;
      setMenuVisibility(willOpen);
    });

    document.addEventListener('click', (event) => {
      if (!columnToggleMenu.hidden && !columnToggleMenu.contains(event.target) && event.target !== columnToggleBtn) {
        setMenuVisibility(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !columnToggleMenu.hidden) {
        setMenuVisibility(false);
        columnToggleBtn.focus();
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
    row.addEventListener('click', (event) => {
      if (event && event.target instanceof HTMLElement) {
        if (event.target.closest('.table-actions') || event.target.closest('button') || event.target.closest('a')) {
          return;
        }
      }
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

  function getClientData(clientId){
    if (!clientId) return null;
    if (!clientRosterData || typeof clientRosterData !== 'object') return null;
    return clientRosterData[clientId] || null;
  }

  function findSessionDetail(sessionId){
    const sid = parseInt(sessionId, 10);
    if (!sid) return null;
    if (!clientRosterData || typeof clientRosterData !== 'object') return null;
    for (const [clientId, data] of Object.entries(clientRosterData)) {
      const sessions = (data && Array.isArray(data.sessions)) ? data.sessions : [];
      const match = sessions.find((session) => session.id === sid);
      if (match) {
        return { clientId, session: match };
      }
    }
    return null;
  }

  function buildAddSessionsForm(clientId){
    const form = document.createElement('form');
    form.className = 'form-grid';
    const hasPackages = Array.isArray(packagesForClients) && packagesForClients.length > 0;
    const optionsHtml = hasPackages
      ? packagesForClients.map((pkg) => `<option value="${pkg.id}">${pkg.label}</option>`).join('')
      : '<option value="">No packages available</option>';
    const helpText = hasPackages ? '' : '<small class="muted">Create a price package before adding sessions.</small>';

    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="manual_add_sessions">
      <input type="hidden" name="schedule_now" value="0">
      <div class="field">
        <label for="addCount">Number of Sessions</label>
        <input id="addCount" name="count" type="number" min="1" class="input" value="1" required>
      </div>
      <div class="field">
        <label for="addPackage">Price Package</label>
        <select id="addPackage" name="catalog_package_id" class="input" required>
          ${optionsHtml}
        </select>
        ${helpText}
      </div>
      <div class="field">
        <label for="addNotes">Notes</label>
        <textarea id="addNotes" class="input" name="schedule_notes" placeholder="Optional notes for this adjustment"></textarea>
      </div>
      <fieldset class="field">
        <legend>Schedule Options</legend>
        <label class="checkbox">
          <input type="checkbox" id="addScheduleToggle">
          Schedule these sessions now
        </label>
        <div class="schedule-fields" data-schedule-fields hidden>
          <div class="field">
            <label for="addScheduleDate">First Session Date</label>
            <input id="addScheduleDate" name="schedule_date" type="date" class="input">
          </div>
          <div class="field">
            <label for="addScheduleStart">Start Time</label>
            <input id="addScheduleStart" name="schedule_start_time" type="time" class="input">
          </div>
          <div class="field">
            <label for="addScheduleEnd">End Time</label>
            <input id="addScheduleEnd" name="schedule_end_time" type="time" class="input">
          </div>
          <div class="field">
            <label for="addScheduleFrequency">Frequency</label>
            <select id="addScheduleFrequency" name="schedule_frequency" class="input">
              <option value="weekly">Weekly</option>
              <option value="daily">Daily</option>
            </select>
            <small class="muted">Sessions will be scheduled sequentially using this cadence.</small>
          </div>
        </div>
      </fieldset>
      <div class="form-actions">
        <button type="submit" class="btn"${hasPackages ? '' : ' disabled'}>Add Sessions</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    const scheduleToggle = form.querySelector('#addScheduleToggle');
    const scheduleFields = form.querySelector('[data-schedule-fields]');
    const scheduleFlag = form.querySelector('input[name="schedule_now"]');
    scheduleToggle?.addEventListener('change', () => {
      const enabled = !!scheduleToggle.checked;
      if (scheduleFields) scheduleFields.hidden = !enabled;
      if (scheduleFlag) scheduleFlag.value = enabled ? '1' : '0';
    });

    form.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const pkgSelect = form.querySelector('#addPackage');
      if (!pkgSelect || !pkgSelect.value) {
        alert('Select a price package.');
        return;
      }
      if (scheduleToggle && scheduleToggle.checked) {
        const dateInput = form.querySelector('#addScheduleDate');
        const startInput = form.querySelector('#addScheduleStart');
        if (!dateInput || !dateInput.value || !startInput || !startInput.value) {
          alert('Provide a date and start time to schedule sessions.');
          return;
        }
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
        alert(payload.message || 'Unable to add sessions.');
      }
    });

    return form;
  }

  function buildRemoveSessionsForm(clientId){
    const form = document.createElement('form');
    form.className = 'form-grid';
    const clientData = getClientData(String(clientId));
    const packages = clientData && Array.isArray(clientData.packages) ? clientData.packages : [];
    const optionsHtml = packages.map((pkg) => `<option value="${pkg.id}">${pkg.name} • ${pkg.purchased_sessions} sessions</option>`).join('');
    const hasPackages = packages.length > 0;

    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="manual_remove_sessions">
      <div class="field">
        <label for="removePackage">Package</label>
        <select id="removePackage" name="package_id" class="input" required ${hasPackages ? '' : 'disabled'}>
          ${hasPackages ? optionsHtml : '<option value="">No packages available</option>'}
        </select>
        ${hasPackages ? '' : '<small class="muted">Add a package before removing sessions.</small>'}
      </div>
      <div class="field">
        <label>Sessions</label>
        <div class="session-checklist" data-session-list></div>
        <div class="muted" data-selected-summary>No sessions selected.</div>
      </div>
      <fieldset class="field">
        <legend>Refund Client?</legend>
        <label class="radio"><input type="radio" name="issue_refund" value="1"> Yes</label>
        <label class="radio"><input type="radio" name="issue_refund" value="0" checked> No</label>
      </fieldset>
      <div class="field">
        <label>Refund Amount</label>
        <div class="value" data-refund-amount>$0.00</div>
      </div>
      <div class="field">
        <label for="removeNotes">Notes</label>
        <textarea id="removeNotes" name="notes" class="input" placeholder="Explain the adjustment"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn danger"${hasPackages ? '' : ' disabled'}>Remove Sessions</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    const sessionList = form.querySelector('[data-session-list]');
    const packageSelect = form.querySelector('#removePackage');
    const summary = form.querySelector('[data-selected-summary]');
    const refundAmount = form.querySelector('[data-refund-amount]');
    const refundRadios = form.querySelectorAll('input[name="issue_refund"]');

    function renderSessions(pkgId){
      if (!sessionList) return;
      sessionList.innerHTML = '';
      const pkg = packages.find((p) => String(p.id) === String(pkgId));
      if (!pkg || !Array.isArray(pkg.sessions) || pkg.sessions.length === 0) {
        sessionList.innerHTML = '<div class="muted">No sessions are available to remove.</div>';
        if (summary) summary.textContent = 'No sessions selected.';
        if (refundAmount) refundAmount.textContent = '$0.00';
        return;
      }
      const removable = pkg.sessions.filter((session) => session.is_removable !== false);
      if (!removable.length) {
        sessionList.innerHTML = '<div class="muted">All sessions in this package are completed or refunded.</div>';
        if (summary) summary.textContent = 'No sessions selected.';
        if (refundAmount) refundAmount.textContent = '$0.00';
        return;
      }
      removable.forEach((session) => {
        const label = document.createElement('label');
        label.className = 'checkbox';
        label.innerHTML = `
          <input type="checkbox" name="session_ids[]" value="${session.id}">
          <span>
            ${formatDisplayDateTime(session.scheduled_start)} • ${session.package_name}
          </span>
        `;
        sessionList.appendChild(label);
      });
      updateRefund();
    }

    function updateRefund(){
      if (!sessionList || !refundAmount || !summary) return;
      const checked = sessionList.querySelectorAll('input[name="session_ids[]"]:checked');
      const pkg = packages.find((p) => String(p.id) === String(packageSelect?.value || ''));
      const count = checked.length;
      const refundYes = Array.from(refundRadios).some((radio) => radio.checked && radio.value === '1');
      const amount = refundYes && pkg ? Number(pkg.price_per_session || 0) * count : 0;
      refundAmount.textContent = formatCurrency(amount);
      summary.textContent = count ? `${count} session${count === 1 ? '' : 's'} selected.` : 'No sessions selected.';
    }

    packageSelect?.addEventListener('change', () => {
      renderSessions(packageSelect.value);
    });

    sessionList?.addEventListener('change', (event) => {
      if (event.target && event.target.matches('input[type="checkbox"]')) {
        updateRefund();
      }
    });

    refundRadios.forEach((radio) => radio.addEventListener('change', updateRefund));

    renderSessions(packageSelect ? packageSelect.value : null);

    form.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!packageSelect || !packageSelect.value) {
        alert('Choose a package to adjust.');
        return;
      }
      const selectedSessions = sessionList ? sessionList.querySelectorAll('input[name="session_ids[]"]:checked') : [];
      if (!selectedSessions.length) {
        alert('Select at least one session to remove.');
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

  function buildScheduleModal(clientId){
    const form = document.createElement('form');
    form.className = 'form-grid';
    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="save_client_schedule">
      <div class="field">
        <p class="muted">Enter a date and time for each session. Leave blank to keep a session unscheduled.</p>
      </div>
      <div class="field" data-schedule-table></div>
      <div class="form-actions">
        <button type="submit" class="btn">Schedule Sessions</button>
        <button type="button" class="btn secondary" data-close>Cancel</button>
      </div>
    `;

    const tableHost = form.querySelector('[data-schedule-table]');
    const clientData = getClientData(String(clientId));
    const sessions = clientData && Array.isArray(clientData.sessions) ? clientData.sessions : [];

    if (!sessions.length) {
      tableHost.innerHTML = '<div class="empty-state"><p>No purchased sessions are available yet.</p></div>';
    } else {
      const controls = document.createElement('div');
      controls.className = 'field';
      controls.innerHTML = `
        <button type="button" class="btn small tertiary" data-copy-first>Apply first populated row to empty rows</button>
      `;
      form.insertBefore(controls, tableHost);

      const table = document.createElement('table');
      table.className = 'inner-table';
      table.innerHTML = `
        <thead>
          <tr>
            <th>Package</th>
            <th>Status</th>
            <th>Date</th>
            <th>Start Time</th>
            <th>End Time</th>
          </tr>
        </thead>
        <tbody></tbody>
      `;
      const tbody = table.querySelector('tbody');

      sessions.forEach((session) => {
        const row = document.createElement('tr');
        row.dataset.sessionId = session.id;
        row.dataset.packageId = session.package_id;
        const editable = session.is_editable !== false;
        row.dataset.editable = editable ? '1' : '0';

        const dateInput = document.createElement('input');
        dateInput.type = 'date';
        dateInput.className = 'input';
        dateInput.value = toDateInputValue(session.scheduled_start);
        dateInput.dataset.field = 'date';
        dateInput.disabled = !editable;

        const startInput = document.createElement('input');
        startInput.type = 'time';
        startInput.className = 'input';
        startInput.value = toTimeInputValue(session.scheduled_start);
        startInput.dataset.field = 'start';
        startInput.disabled = !editable;

        const endInput = document.createElement('input');
        endInput.type = 'time';
        endInput.className = 'input';
        endInput.value = toTimeInputValue(session.scheduled_end);
        endInput.dataset.field = 'end';
        endInput.disabled = !editable;

        row.innerHTML = `
          <td>${session.package_name}</td>
          <td>${session.status ? session.status.charAt(0).toUpperCase() + session.status.slice(1) : 'Scheduled'}</td>
          <td></td>
          <td></td>
          <td></td>
        `;
        const cells = row.querySelectorAll('td');
        cells[2].appendChild(dateInput);
        cells[3].appendChild(startInput);
        cells[4].appendChild(endInput);

        if (!editable) {
          row.classList.add('is-disabled');
        }

        tbody.appendChild(row);
      });

      tableHost.appendChild(table);

      controls.addEventListener('click', (event) => {
        if (!(event.target instanceof HTMLElement)) return;
        if (event.target.matches('[data-copy-first], [data-copy-first] *')) {
          event.preventDefault();
          const rows = Array.from(table.querySelectorAll('tbody tr'));
          const firstRow = rows.find((r) => {
            const dateVal = r.querySelector('[data-field="date"]')?.value;
            const startVal = r.querySelector('[data-field="start"]')?.value;
            return dateVal && startVal;
          });
          if (!firstRow) {
            alert('Fill in at least one row before applying to others.');
            return;
          }
          const dateVal = firstRow.querySelector('[data-field="date"]').value;
          const startVal = firstRow.querySelector('[data-field="start"]').value;
          const endVal = firstRow.querySelector('[data-field="end"]')?.value || '';
          rows.forEach((row) => {
            if (row.dataset.editable === '0') return;
            const dateField = row.querySelector('[data-field="date"]');
            const startField = row.querySelector('[data-field="start"]');
            const endField = row.querySelector('[data-field="end"]');
            if (dateField && !dateField.value) dateField.value = dateVal;
            if (startField && !startField.value) startField.value = startVal;
            if (endField && !endField.value) endField.value = endVal;
          });
        }
      });
    }

    form.addEventListener('click', (event) => {
      if (event.target && event.target.matches('[data-close]')) {
        event.preventDefault();
        closeModal();
      }
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const payload = [];
      const rows = form.querySelectorAll('tbody tr');
      rows.forEach((row) => {
        const editable = row.dataset.editable !== '0';
        if (!editable) return;
        const sessionId = parseInt(row.dataset.sessionId || '0', 10);
        if (!sessionId) return;
        const date = row.querySelector('[data-field="date"]')?.value || '';
        const start = row.querySelector('[data-field="start"]')?.value || '';
        const end = row.querySelector('[data-field="end"]')?.value || '';
        payload.push({
          session_id: sessionId,
          date,
          start_time: start,
          end_time: end,
        });
      });
      if (!payload.length) {
        alert('No sessions are editable for scheduling.');
        return;
      }
      const formData = new FormData(form);
      formData.append('client_id', clientId);
      formData.append('session_updates', JSON.stringify(payload));
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: formData,
      });
      const data = await response.json();
      if (data.ok) {
        window.location.reload();
      } else {
        alert(data.message || 'Unable to schedule sessions.');
      }
    });

    return form;
  }

  function buildRescheduleForm(sessionId){
    const detail = findSessionDetail(sessionId);
    const form = document.createElement('form');
    form.className = 'form-grid';
    if (!detail) {
      form.innerHTML = '<p class="muted">This session could not be found.</p>';
      return form;
    }
    const { session } = detail;
    const dateValue = toDateInputValue(session.scheduled_start);
    const startValue = toTimeInputValue(session.scheduled_start);
    const endValue = toTimeInputValue(session.scheduled_end);

    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="reschedule_session">
      <input type="hidden" name="session_id" value="${session.id}">
      <div class="field">
        <strong>${session.package_name}</strong>
        <div class="muted">${formatDisplayDateTime(session.scheduled_start)}</div>
      </div>
      <div class="field">
        <label for="rescheduleDate">New Date</label>
        <input id="rescheduleDate" name="scheduled_date" type="date" class="input" value="${dateValue}" required>
      </div>
      <div class="field">
        <label for="rescheduleStart">New Start Time</label>
        <input id="rescheduleStart" name="scheduled_start_time" type="time" class="input" value="${startValue}" required>
      </div>
      <div class="field">
        <label for="rescheduleEnd">New End Time</label>
        <input id="rescheduleEnd" name="scheduled_end_time" type="time" class="input" value="${endValue}">
      </div>
      <div class="field">
        <label for="rescheduleNotes">Notes</label>
        <textarea id="rescheduleNotes" name="notes" class="input" placeholder="Optional message for the client"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn">Reschedule Session</button>
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
      const dateInput = form.querySelector('#rescheduleDate');
      const startInput = form.querySelector('#rescheduleStart');
      if (!dateInput || !dateInput.value || !startInput || !startInput.value) {
        alert('Provide a date and start time.');
        return;
      }
      const formData = new FormData(form);
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: formData,
      });
      const payload = await response.json();
      if (payload.ok) {
        window.location.reload();
      } else {
        alert(payload.message || 'Unable to reschedule session.');
      }
    });

    return form;
  }

  function buildCancelForm(sessionId){
    const detail = findSessionDetail(sessionId);
    const form = document.createElement('form');
    form.className = 'form-grid';
    if (!detail) {
      form.innerHTML = '<p class="muted">This session could not be found.</p>';
      return form;
    }
    const { session } = detail;
    const refundAmount = formatCurrency(session.price_per_session || 0);

    form.innerHTML = `
      <input type="hidden" name="csrf_token" value="${csrfToken}">
      <input type="hidden" name="action" value="cancel_session">
      <input type="hidden" name="session_id" value="${session.id}">
      <div class="field">
        <strong>${session.package_name}</strong>
        <div class="muted">${formatDisplayDateTime(session.scheduled_start)}</div>
      </div>
      <div class="field">
        <p>Are you sure you want to cancel this session?</p>
      </div>
      <fieldset class="field">
        <legend>Refund Client?</legend>
        <label class="radio"><input type="radio" name="issue_refund" value="1"> Yes — refund ${refundAmount}</label>
        <label class="radio"><input type="radio" name="issue_refund" value="0" checked> No refund</label>
      </fieldset>
      <div class="field">
        <label for="cancelNotes">Notes</label>
        <textarea id="cancelNotes" name="notes" class="input" placeholder="Optional explanation for the client"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn danger">Cancel Session</button>
        <button type="button" class="btn secondary" data-close>Go Back</button>
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
      const response = await fetch('trainer_sessions_actions.php', {
        method: 'POST',
        body: formData,
      });
      const payload = await response.json();
      if (payload.ok) {
        window.location.reload();
      } else {
        alert(payload.message || 'Unable to cancel session.');
      }
    });

    return form;
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const action = target.dataset.action;
    if (!action) return;

    const clientId = target.dataset.clientId || target.closest('[data-client-id]')?.dataset.clientId || '';
    const sessionRow = target.closest('.session-row');
    const sessionId = target.dataset.sessionId || sessionRow?.dataset.sessionId || '';

    if (['add','remove','schedule','reschedule-session','cancel-session'].includes(action)) {
      event.preventDefault();
      event.stopPropagation();
    }

    if (action === 'add') {
      openModal('Add Sessions', buildAddSessionsForm(clientId));
    }
    if (action === 'remove') {
      openModal('Remove Sessions', buildRemoveSessionsForm(clientId));
    }
    if (action === 'schedule') {
      openModal('Schedule Sessions', buildScheduleModal(clientId));
    }
    if (action === 'reschedule-session') {
      openModal('Reschedule Session', buildRescheduleForm(sessionId));
    }
    if (action === 'cancel-session') {
      openModal('Cancel Session', buildCancelForm(sessionId));
    }
  });
})();
;
</script>
