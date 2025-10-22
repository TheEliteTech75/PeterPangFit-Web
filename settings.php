<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/ppf_passkeys.php';
require_once __DIR__ . '/ppf_trusted.php';
require_once __DIR__ . '/ppf_lockout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$uid   = (int)($_SESSION['user_id'] ?? 0);
$email = (string)($_SESSION['email'] ?? '');
$role  = (string)($_SESSION['role'] ?? 'client');

if ($uid <= 0) { header('Location: login.php'); exit; }

$roleLower = strtolower($role);
$isAdmin   = ($roleLower === 'admin');

ppf_ensure_twofa_columns($conn);
ppf_td_ensure_table($conn);
ppf_seed_lockout_defaults($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ---------- helpers ----------
function ensure_system_settings_table(mysqli $conn): void {
    @$conn->query(
        "CREATE TABLE IF NOT EXISTS system_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

ensure_system_settings_table($conn);

function settings_flash(?string $type = null, ?string $message = null): ?array {
    if ($type !== null && $message !== null) {
        $_SESSION['settings_flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    if (!empty($_SESSION['settings_flash'])) {
        $flash = $_SESSION['settings_flash'];
        unset($_SESSION['settings_flash']);
        return $flash;
    }
    return null;
}

function redirect_with_flash(string $type, string $message, string $anchor = ''): void {
    settings_flash($type, $message);
    $dest = 'settings.php';
    if ($anchor !== '') $dest .= '#' . rawurlencode($anchor);
    header('Location: ' . $dest);
    exit;
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $st->store_result();
    $exists = $st->num_rows > 0;
    $st->close();
    return $exists;
}

function ppf_get_session_timeout_minutes(mysqli $conn): int {
    $def = 120;
    try {
        if ($st = $conn->prepare("SELECT value FROM settings WHERE `key`='session_timeout_minutes' LIMIT 1")) {
            $st->execute();
            $res = $st->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $st->close();
            $val = (int)($row['value'] ?? 0);
            if ($val > 0 && $val <= 14400) {
                return $val;
            }
        }
    } catch (Throwable $e) {}
    return $def;
}

function ss_get(mysqli $conn, string $key, ?string $default = null): ?string {
    ensure_system_settings_table($conn);
    return ppf_ss_get($conn, $key, $default);
}

function ss_set(mysqli $conn, string $key, string $value): bool {
    ensure_system_settings_table($conn);
    return ppf_ss_set($conn, $key, $value);
}

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        redirect_with_flash('error', 'Invalid or expired session. Please try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
        case 'system_settings': {
            if (!$isAdmin) {
                redirect_with_flash('error', 'You are not allowed to update system settings.');
            }
            $minsDefault = max(1, min(1440, (int)($_POST['lockout_default'] ?? 30)));
            $minsClient  = max(1, min(1440, (int)($_POST['lockout_client'] ?? $minsDefault)));
            $minsTrainer = max(1, min(1440, (int)($_POST['lockout_trainer'] ?? $minsDefault)));
            $minsAdmin   = max(1, min(1440, (int)($_POST['lockout_admin'] ?? $minsDefault)));

            ss_set($conn, 'lockout_default_minutes', (string)$minsDefault);
            ss_set($conn, 'lockout_minutes_client', (string)$minsClient);
            ss_set($conn, 'lockout_minutes_trainer', (string)$minsTrainer);
            ss_set($conn, 'lockout_minutes_admin', (string)$minsAdmin);

            $testEnabled = ($_POST['test_token_enabled'] ?? '') === '1' ? '1' : '0';
            $testValue   = trim((string)($_POST['test_token_value'] ?? ''));
            if (isset($_POST['generate_test_token']) && $_POST['generate_test_token'] === '1') {
                $testValue = bin2hex(random_bytes(16));
            }
            if ($testEnabled === '1' && $testValue === '') {
                $testValue = bin2hex(random_bytes(16));
            }
            ss_set($conn, 'test_register_token_enabled', $testEnabled);
            ss_set($conn, 'test_register_token_value', $testValue);

            if (function_exists('ppf_log')) {
                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'system_settings_updated', 'admin', (string)$uid, null);
            }
            redirect_with_flash('success', 'System settings saved.', 'system');
        }
    }

    redirect_with_flash('error', 'Unknown action.');
}

$flash = settings_flash();

$msgKey = $_GET['msg'] ?? '';
if (!$flash && $msgKey !== '') {
    switch ($msgKey) {
        case 'passkey_deleted':
            $name = isset($_GET['name']) ? urldecode((string)$_GET['name']) : '';
            $message = 'Passkey ' . ($name !== '' ? ('"' . $name . '" ') : '') . 'was deleted.';
            $flash = ['type' => 'success', 'message' => $message];
            break;
        case 'passkey_renamed':
            $name = isset($_GET['name']) ? urldecode((string)$_GET['name']) : '';
            $message = 'Passkey name updated' . ($name !== '' ? (' to "' . $name . '".') : '.');
            $flash = ['type' => 'success', 'message' => $message];
            break;
        case 'ok':
            $flash = ['type' => 'success', 'message' => 'Changes saved.'];
            break;
        case 'err':
            $detail = urldecode((string)($_GET['detail'] ?? 'Request could not be processed.'));
            $flash = ['type' => 'error', 'message' => $detail];
            break;
    }
}

// ---------- load user + security data ----------
$userRow = null;
if ($st = $conn->prepare("SELECT email, first_name, last_name, role, twofa_email_enabled, twofa_app_enabled, twofa_secret FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $userRow = $res ? $res->fetch_assoc() : null;
    $st->close();
}

$twofaEmailEnabled = (int)($userRow['twofa_email_enabled'] ?? 0) === 1;
$twofaAppEnabled   = (int)($userRow['twofa_app_enabled'] ?? 0) === 1;
$twofaSecret       = strtoupper(preg_replace('/\s+/', '', (string)($userRow['twofa_secret'] ?? '')));

// Passkeys
$passkeys = [];
if (table_exists($conn, 'passkeys')) {
    if ($st = $conn->prepare("SELECT id, name, created_at, last_used_at FROM passkeys WHERE user_id=? ORDER BY created_at DESC")) {
        $st->bind_param('i', $uid);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $passkeys[] = $row;
        }
        $st->close();
    }
}

// Trusted devices
$trustedDevices = ppf_td_list_for_user($conn, $uid);

// Sessions
$sessions = [];
$sessionCounts = ['current' => 0, 'active' => 0, 'inactive' => 0, 'expired' => 0, 'revoked' => 0];
if (table_exists($conn, 'user_sessions')) {
    $currentSid = session_id();
    $inactiveCut = time() - (30 * 60);
    $expiredCut  = time() - (ppf_get_session_timeout_minutes($conn) * 60);

    $sql = "SELECT session_id, created_at, last_seen_at, revoked, ip, city, region, platform, browser, user_agent FROM user_sessions WHERE user_id=? ORDER BY last_seen_at DESC";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $uid);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (string)$row['session_id'];
            $lastSeenTs = strtotime((string)($row['last_seen_at'] ?? '')) ?: null;
            $createdTs  = strtotime((string)($row['created_at'] ?? '')) ?: null;
            $revoked    = (int)($row['revoked'] ?? 0) === 1;
            $isCurrent  = ($sid !== '' && $sid === $currentSid);
            $seenRecently = $lastSeenTs && $lastSeenTs >= $inactiveCut;
            $isExpired = $lastSeenTs && $lastSeenTs < $expiredCut;

            $status = 'inactive';
            if ($revoked) {
                $status = 'revoked';
            } elseif ($isCurrent) {
                $status = 'current';
            } elseif ($isExpired) {
                $status = 'expired';
            } elseif ($seenRecently) {
                $status = 'active';
            }
            $sessionCounts[$status] = ($sessionCounts[$status] ?? 0) + 1;

            $platform = trim((string)($row['platform'] ?? ''));
            $browser  = trim((string)($row['browser'] ?? ''));
            $ua       = (string)($row['user_agent'] ?? '');
            if ($platform === '' && $ua !== '') $platform = ppf_detect_platform($ua);
            if ($browser === '' && $ua !== '')  $browser  = ppf_detect_browser($ua);

            $row['status']      = $status;
            $row['is_current']  = $isCurrent;
            $row['created_ts']  = $createdTs;
            $row['last_seen_ts']= $lastSeenTs;
            $row['platform']    = $platform;
            $row['browser']     = $browser;
            $row['ip']          = (string)($row['ip'] ?? '');
            $row['city']        = (string)($row['city'] ?? '');
            $row['region']      = (string)($row['region'] ?? '');
            $sessions[] = $row;
        }
        $st->close();
    }
}

function rel_time(?int $ts): string {
    if (!$ts) return 'Unknown';
    $diff = time() - $ts;
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    if ($diff < 604800) return round($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

function fmt_datetime(?int $ts): string {
    if (!$ts) return '—';
    return date('M j, Y g:i a', $ts);
}

function fmt_badge_class(string $status): string {
    return match ($status) {
        'current' => 'status current',
        'active'  => 'status active',
        'expired' => 'status expired',
        'revoked' => 'status revoked',
        default   => 'status idle',
    };
}

$lockoutDefault = (int)(ss_get($conn, 'lockout_default_minutes', '30') ?? 30);
$lockoutClient  = (int)(ss_get($conn, 'lockout_minutes_client', (string)$lockoutDefault) ?? $lockoutDefault);
$lockoutTrainer = (int)(ss_get($conn, 'lockout_minutes_trainer', (string)$lockoutDefault) ?? $lockoutDefault);
$lockoutAdmin   = (int)(ss_get($conn, 'lockout_minutes_admin', (string)$lockoutDefault) ?? $lockoutDefault);
$testTokenEnabled = ss_get($conn, 'test_register_token_enabled', '0') === '1';
$testTokenValue   = ss_get($conn, 'test_register_token_value', '');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Security Settings · Peter Pang Fit</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #05070d;
      --bg-alt: #03040a;
      --surface: rgba(9, 14, 28, 0.92);
      --surface-alt: rgba(15, 23, 42, 0.78);
      --border: rgba(148, 163, 184, 0.18);
      --border-strong: rgba(56, 189, 248, 0.35);
      --text: #f8fafc;
      --muted: rgba(203, 213, 225, 0.78);
      --accent: #38bdf8;
      --accent-soft: rgba(56, 189, 248, 0.16);
      --danger: #f87171;
      --warning: #fbbf24;
      --success: #34d399;
      --shadow: 0 30px 70px rgba(2, 6, 23, 0.55);
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
      background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 55%),
        radial-gradient(circle at bottom right, rgba(110, 231, 183, 0.12), transparent 60%),
        linear-gradient(155deg, var(--bg), var(--bg-alt));
      color: var(--text);
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }

    main.settings {
      max-width: 1180px;
      margin: 64px auto 120px auto;
      padding: 0 24px 80px;
      display: grid;
      gap: 32px;
    }

    .page-intro h1 {
      margin: 0;
      font-size: clamp(2.2rem, 2vw + 1.2rem, 3rem);
      letter-spacing: -0.02em;
    }
    .page-intro p {
      margin: 12px 0 0 0;
      color: var(--muted);
      max-width: 620px;
    }

    .flash {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px 20px;
      background: rgba(15, 23, 42, 0.75);
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .flash.success { border-color: rgba(52, 211, 153, 0.45); color: #a7f3d0; }
    .flash.error   { border-color: rgba(248, 113, 113, 0.45); color: #fecaca; }
    .flash.info    { border-color: rgba(56, 189, 248, 0.35); color: #bfdbfe; }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 24px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
    }

    .card h2 {
      margin: 0 0 14px 0;
      font-size: 1.8rem;
      letter-spacing: -0.01em;
    }
    .card h3 {
      margin: 0 0 12px 0;
      font-size: 1.2rem;
      color: var(--accent);
      letter-spacing: .01em;
    }

    .section-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 24px;
    }

    .switch-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px;
      border: 1px solid var(--border);
      border-radius: 16px;
      background: rgba(15, 23, 42, 0.6);
    }
    .switch-row + .switch-row { margin-top: 16px; }
    .switch-row .meta { max-width: 70%; }
    .switch-row strong { font-size: 1.05rem; display: block; }
    .switch-row span { color: var(--muted); font-size: .92rem; }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border-radius: 999px;
      border: 1px solid var(--border);
      padding: 10px 18px;
      cursor: pointer;
      font-weight: 600;
      background: rgba(56, 189, 248, 0.1);
      color: var(--text);
    }
    .btn:hover { border-color: var(--border-strong); background: rgba(56,189,248,0.18); }
    .btn.secondary { background: rgba(15,23,42,0.75); }
    .btn.danger { background: rgba(248, 113, 113, 0.15); border-color: rgba(248,113,113,0.45); color: #fecaca; }

    form.inline { display: inline; }

    .input {
      width: 100%;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: rgba(8, 12, 24, 0.85);
      color: var(--text);
      font-size: 1rem;
    }
    .input:focus { outline: 2px solid rgba(56,189,248,0.45); }

    .table-wrapper {
      position: relative;
      border: 1px solid var(--border);
      border-radius: 18px;
      background: rgba(9, 14, 28, 0.92);
      overflow-x: auto;
      overflow-y: hidden;
      box-shadow: inset 0 1px 0 rgba(148,163,184,0.08);
    }
    .table-wrapper::-webkit-scrollbar { height: 8px; }
    .table-wrapper::-webkit-scrollbar-thumb {
      background: rgba(148,163,184,0.35);
      border-radius: 999px;
    }
    table.data-table {
      width: 100%;
      min-width: 640px;
      border-collapse: collapse;
    }
    table.data-table colgroup col.actions-col { width: 180px; }
    table.data-table th,
    table.data-table td {
      padding: 16px 18px;
      text-align: left;
      border-bottom: 1px solid rgba(148,163,184,0.12);
      font-size: .95rem;
      vertical-align: middle;
    }
    table.data-table thead th {
      font-size: .78rem;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: rgba(203, 213, 225, 0.85);
      background: rgba(8, 12, 24, 0.92);
      position: sticky;
      top: 0;
      z-index: 5;
    }
    table.data-table tbody tr { transition: background .15s ease, border-color .15s ease; }
    table.data-table tbody tr:nth-child(odd) { background: rgba(12, 18, 32, 0.65); }
    table.data-table tbody tr:nth-child(even) { background: rgba(12, 18, 32, 0.5); }
    table.data-table tbody tr:last-child td { border-bottom: 0; }
    table.data-table tbody tr:hover { background: rgba(56, 189, 248, 0.12); }
    .table-primary {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .table-primary strong { font-size: 1rem; color: var(--text); }
    .table-primary .inline-editor {
      margin-top: 10px;
      padding: 12px;
      border-radius: 14px;
      border: 1px solid rgba(148,163,184,0.25);
      background: rgba(8, 12, 24, 0.78);
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .inline-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .table-display[hidden] { display: none !important; }
    .table-subtext {
      font-size: .82rem;
      color: var(--muted);
      display: block;
    }
    .actions-cell { display: inline-flex; gap: 8px; flex-wrap: wrap; }
    .btn.small { padding: 6px 12px; font-size: .8rem; border-radius: 10px; }
    .btn.ghost { background: rgba(15,23,42,0.7); border-color: rgba(148,163,184,0.28); }
    .btn.ghost:hover { border-color: rgba(56,189,248,0.45); color: #e0f2fe; }
    .table-empty { padding: 22px; text-align: center; color: var(--muted); font-size: .95rem; }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(3, 6, 14, 0.78);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 999;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
      width: 100%;
      max-width: 480px;
      background: rgba(9, 14, 28, 0.95);
      border: 1px solid rgba(148,163,184,0.25);
      border-radius: 20px;
      padding: 26px;
      color: var(--text);
      box-shadow: 0 24px 48px rgba(15, 23, 42, 0.6);
    }
    .modal header h3 {
      margin: 0 0 4px 0;
      font-size: 1.25rem;
    }
    .modal header p { margin: 0; color: var(--muted); }
    .modal footer {
      margin-top: 24px;
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      flex-wrap: wrap;
    }
    .modal .step-content { margin-top: 18px; display: flex; flex-direction: column; gap: 14px; }
    .modal .code-inputs { display: flex; gap: 10px; }
    .modal .code-inputs input { flex: 1; text-align: center; font-size: 1.1rem; letter-spacing: .2em; }
    .modal .error { color: #fca5a5; background: rgba(127,29,29,0.25); border: 1px solid rgba(239,68,68,0.35); padding: 10px 12px; border-radius: 12px; font-size: .9rem; }
    .modal .success { color: #bbf7d0; background: rgba(22,101,52,0.25); border: 1px solid rgba(34,197,94,0.35); padding: 10px 12px; border-radius: 12px; font-size: .9rem; }
    .modal .qr-preview {
      padding: 16px;
      border-radius: 16px;
      border: 1px dashed rgba(148,163,184,0.35);
      background: rgba(8,12,24,0.82);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .modal .qr-preview img { max-width: 220px; border-radius: 12px; }
    .modal .small-text { color: var(--muted); font-size: .88rem; }

    .status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: .75rem;
      letter-spacing: .05em;
      text-transform: uppercase;
    }
    .status.current { background: rgba(56,189,248,0.2); color: #bae6fd; }
    .status.active { background: rgba(52,211,153,0.18); color: #bbf7d0; }
    .status.idle   { background: rgba(148,163,184,0.18); color: #e2e8f0; }
    .status.expired{ background: rgba(251,191,36,0.15); color: #fde68a; }
    .status.revoked{ background: rgba(248,113,113,0.18); color: #fecaca; }

    .pill {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      background: rgba(56,189,248,0.1);
      border: 1px solid rgba(56,189,248,0.25);
      font-size: .75rem;
    }

    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 20px 0 0;
    }
    .chips .chip {
      border-radius: 999px;
      padding: 8px 14px;
      background: rgba(15,23,42,0.75);
      border: 1px solid var(--border);
      font-size: .85rem;
    }

    .actions-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 16px;
    }

    .muted { color: var(--muted); }

    .two-col {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 24px;
    }

    .section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .small-text { font-size: .82rem; color: var(--muted); }

    .divider {
      height: 1px;
      background: var(--border);
      margin: 24px 0;
    }

    .empty-state {
      border: 1px dashed var(--border);
      border-radius: 18px;
      padding: 24px;
      text-align: center;
      color: var(--muted);
    }

    @media (max-width: 768px) {
      main.settings { padding: 0 16px 80px; margin-top: 40px; }
      .switch-row { flex-direction: column; align-items: flex-start; gap: 12px; }
      .switch-row .meta { max-width: 100%; }
    }
  </style>
</head>
<body>
  <?php
    $USER_ROLE = $role;
    $USER_ID = $uid;
    $USER_EMAIL = $email;
    $USER_FIRST_NAME = $userRow['first_name'] ?? ($_SESSION['first_name'] ?? '');
    $USER_LAST_NAME = $userRow['last_name'] ?? ($_SESSION['last_name'] ?? '');
    require __DIR__ . '/ppf_header.php';
    require __DIR__ . '/ppf_nav.php';
  ?>

  <main class="settings">
    <section class="page-intro">
      <h1>Account Security</h1>
      <p>Manage your two-factor authentication, passkeys, trusted devices, and active login sessions. Administrators can also adjust system safeguards for the whole team.</p>
    </section>

    <?php if ($flash): ?>
      <div class="flash <?php echo h($flash['type']); ?>">
        <?php echo h($flash['message']); ?>
      </div>
    <?php endif; ?>

    <section id="twofa" class="card">
      <div class="section-title">
        <div>
          <h2>Two-Factor Authentication</h2>
          <p class="muted">Layer email codes or an authenticator app on top of your password.</p>
        </div>
      </div>

      <div class="switch-row">
        <div class="meta">
          <strong>Email Authentication</strong>
          <span><?php echo $twofaEmailEnabled ? 'Codes can be sent to your email when needed.' : 'A backup code will be sent only after you enable this option.'; ?></span>
        </div>
        <button class="btn<?php echo $twofaEmailEnabled ? ' secondary' : ''; ?>" id="btnEmailToggle" data-state="<?php echo $twofaEmailEnabled ? 'disable' : 'enable'; ?>">
          <?php echo $twofaEmailEnabled ? 'Disable' : 'Enable'; ?>
        </button>
      </div>

      <div class="switch-row">
        <div class="meta">
          <strong>Authenticator App</strong>
          <span><?php echo $twofaAppEnabled ? 'Logins require a 6-digit code from your authenticator.' : 'Pair an authenticator app like Google Authenticator or Authy for stronger protection.'; ?></span>
        </div>
        <?php if ($twofaAppEnabled): ?>
          <button class="btn danger" id="btnDisableApp">Disable</button>
        <?php else: ?>
          <button class="btn" id="btnEnableApp">Enable</button>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" id="passkeys">
      <div class="section-title">
        <div>
          <h2>Passkeys</h2>
          <p class="muted">Use biometric sign-in on supported devices for passwordless logins.</p>
        </div>
        <button class="btn" id="btnAddPasskey">Add passkey</button>
      </div>

      <div class="table-wrapper">
        <?php if (!$passkeys): ?>
          <div class="table-empty">No passkeys yet. Add one to sign in with Face ID, Touch ID, or Windows Hello.</div>
        <?php else: ?>
          <table class="data-table" id="passkeysTable">
            <colgroup>
              <col>
              <col>
              <col>
              <col class="actions-col">
            </colgroup>
            <thead>
              <tr>
                <th>Name</th>
                <th>Added</th>
                <th>Last Used</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($passkeys as $pk): ?>
                <?php
                  $pkId = (int)$pk['id'];
                  $pkName = trim((string)$pk['name']);
                  $pkAdded = strtotime((string)($pk['created_at'] ?? '')) ?: null;
                  $pkLast = strtotime((string)($pk['last_used_at'] ?? '')) ?: null;
                ?>
                <tr data-passkey-id="<?php echo $pkId; ?>">
                  <td data-field="name">
                    <div class="table-primary">
                      <strong class="table-display" data-field="name-display"><?php echo h($pkName !== '' ? $pkName : 'Unnamed passkey'); ?></strong>
                      <div class="inline-editor" data-role="passkey" data-id="<?php echo $pkId; ?>" hidden>
                        <input class="input" type="text" maxlength="100" value="<?php echo h($pkName); ?>" placeholder="Passkey name">
                        <div class="inline-actions">
                          <button class="btn small" data-action="save">Save</button>
                          <button class="btn small secondary" data-action="cancel">Cancel</button>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td data-field="created">
                    <div class="table-primary">
                      <strong><?php echo fmt_datetime($pkAdded); ?></strong>
                    </div>
                  </td>
                  <td data-field="last-used">
                    <div class="table-primary">
                      <strong><?php echo fmt_datetime($pkLast); ?></strong>
                      <?php if ($pkLast): ?><span class="table-subtext"><?php echo rel_time($pkLast); ?></span><?php endif; ?>
                    </div>
                  </td>
                  <td class="actions-cell">
                    <button class="btn small ghost btn-edit-passkey" data-passkey-id="<?php echo $pkId; ?>">Edit</button>
                    <button class="btn small danger btn-delete-passkey" data-passkey-id="<?php echo $pkId; ?>" data-passkey-name="<?php echo h($pkName !== '' ? $pkName : 'Unnamed passkey'); ?>">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" id="trusted">
      <div class="section-title">
        <div>
          <h2>Trusted Devices</h2>
          <p class="muted">Devices that skip two-factor for 30 days after you trust them.</p>
        </div>
      </div>

      <div class="table-wrapper">
        <?php if (!$trustedDevices): ?>
          <div class="table-empty">No trusted devices yet. You can trust a device during login after passing two-factor.</div>
        <?php else: ?>
          <table class="data-table" id="trustedDevicesTable">
            <colgroup>
              <col>
              <col>
              <col>
              <col class="actions-col">
            </colgroup>
            <thead>
              <tr>
                <th>Name</th>
                <th>Added</th>
                <th>Last Used</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($trustedDevices as $td): ?>
                <?php
                  $tdId = (int)$td['id'];
                  $tdName = trim((string)$td['device_name']);
                  $tdAdded = strtotime((string)($td['created_at'] ?? '')) ?: null;
                  $tdLast  = strtotime((string)($td['last_used_at'] ?? '')) ?: null;
                  $tdExpires = strtotime((string)($td['expires_at'] ?? '')) ?: null;
                ?>
                <tr data-device-id="<?php echo $tdId; ?>">
                  <td data-field="name">
                    <div class="table-primary">
                      <strong class="table-display" data-field="name-display"><?php echo h($tdName !== '' ? $tdName : 'Unnamed device'); ?></strong>
                      <div class="inline-editor" data-role="trusted" data-id="<?php echo $tdId; ?>" hidden>
                        <input class="input" type="text" maxlength="100" value="<?php echo h($tdName); ?>" placeholder="Device name">
                        <div class="inline-actions">
                          <button class="btn small" data-action="save">Save</button>
                          <button class="btn small secondary" data-action="cancel">Cancel</button>
                        </div>
                      </div>
                      <?php if ($tdExpires): ?>
                        <span class="table-subtext">Expires <?php echo fmt_datetime($tdExpires); ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td data-field="created">
                    <div class="table-primary">
                      <strong><?php echo fmt_datetime($tdAdded); ?></strong>
                    </div>
                  </td>
                  <td data-field="last-used">
                    <div class="table-primary">
                      <strong><?php echo fmt_datetime($tdLast); ?></strong>
                      <?php if ($tdLast): ?><span class="table-subtext"><?php echo rel_time($tdLast); ?></span><?php endif; ?>
                    </div>
                  </td>
                  <td class="actions-cell">
                    <button class="btn small ghost btn-edit-device" data-device-id="<?php echo $tdId; ?>">Edit</button>
                    <form method="post" action="trusted_devices_actions.php" class="inline">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo $tdId; ?>">
                      <button class="btn small danger" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" id="sessions">
      <div class="section-title">
        <div>
          <h2>Login Sessions</h2>
          <p class="muted">Review where you're signed in and sign out devices you no longer recognize.</p>
        </div>
        <form method="post" action="sessions_actions.php" class="inline">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="signout_all_others">
          <button class="btn danger" type="submit">Sign out others</button>
        </form>
      </div>

      <div class="chips">
        <span class="chip">Current: <?php echo $sessionCounts['current'] ?? 0; ?></span>
        <span class="chip">Active: <?php echo $sessionCounts['active'] ?? 0; ?></span>
        <span class="chip">Inactive: <?php echo $sessionCounts['inactive'] ?? 0; ?></span>
        <span class="chip">Expired: <?php echo $sessionCounts['expired'] ?? 0; ?></span>
        <span class="chip">Revoked: <?php echo $sessionCounts['revoked'] ?? 0; ?></span>
      </div>

      <div class="table-wrapper">
        <?php if (!$sessions): ?>
          <div class="table-empty">No recent sessions found.</div>
        <?php else: ?>
          <table class="data-table" id="sessionsTable">
            <colgroup>
              <col>
              <col>
              <col>
              <col>
              <col class="actions-col">
            </colgroup>
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Location</th>
                <th>Browser</th>
                <th>OS</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <?php
                  $location = trim(($s['city'] ? $s['city'] . ', ' : '') . $s['region']);
                  $lastSeenText = fmt_datetime($s['last_seen_ts']);
                  $startedText = fmt_datetime($s['created_ts']);
                ?>
                <tr data-session-id="<?php echo h($s['session_id']); ?>" data-status="<?php echo h($s['status']); ?>">
                  <td>
                    <div class="table-primary">
                      <strong><?php echo $lastSeenText; ?></strong>
                      <div class="table-subtext">Started <?php echo $startedText; ?> · Last seen <?php echo rel_time($s['last_seen_ts']); ?></div>
                      <div><span class="<?php echo fmt_badge_class($s['status']); ?>"><?php echo ucfirst($s['status']); ?></span></div>
                    </div>
                  </td>
                  <td>
                    <div class="table-primary">
                      <strong><?php echo h($location !== '' ? $location : 'Unknown'); ?></strong>
                    </div>
                  </td>
                  <td>
                    <div class="table-primary">
                      <strong><?php echo h($s['browser'] ?: 'Unknown'); ?></strong>
                      <?php if ($s['is_current']): ?><span class="table-subtext">This browser</span><?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="table-primary">
                      <strong><?php echo h($s['platform'] ?: 'Unknown'); ?></strong>
                    </div>
                  </td>
                  <td class="actions-cell">
                    <?php if (in_array($s['status'], ['active', 'inactive'], true)): ?>
                      <form method="post" action="sessions_actions.php" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="signout_one">
                        <input type="hidden" name="session_id" value="<?php echo h($s['session_id']); ?>">
                        <button class="btn small danger" type="submit">Sign Out</button>
                      </form>
                    <?php elseif ($s['is_current']): ?>
                      <span class="table-subtext">Current session</span>
                    <?php else: ?>
                      <span class="table-subtext">No actions available</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($isAdmin): ?>
      <section class="card" id="system">
        <h2>System Settings</h2>
        <p class="muted">Customize lockout durations for each role and manage the registration test token.</p>

        <form method="post" class="two-col" style="margin-top:18px;">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="system_settings">

          <div>
            <h3>Account Lockout (minutes)</h3>
            <label class="small-text" for="lockout_default">Default</label>
            <input class="input" id="lockout_default" name="lockout_default" type="number" min="1" max="1440" value="<?php echo h($lockoutDefault); ?>">
            <label class="small-text" for="lockout_client">Clients</label>
            <input class="input" id="lockout_client" name="lockout_client" type="number" min="1" max="1440" value="<?php echo h($lockoutClient); ?>">
            <label class="small-text" for="lockout_trainer">Trainers</label>
            <input class="input" id="lockout_trainer" name="lockout_trainer" type="number" min="1" max="1440" value="<?php echo h($lockoutTrainer); ?>">
            <label class="small-text" for="lockout_admin">Admins</label>
            <input class="input" id="lockout_admin" name="lockout_admin" type="number" min="1" max="1440" value="<?php echo h($lockoutAdmin); ?>">
          </div>

          <div>
            <h3>Registration Test Token</h3>
            <label class="small-text" style="display:flex;align-items:center;gap:8px;">
              <input type="checkbox" name="test_token_enabled" value="1" <?php echo $testTokenEnabled ? 'checked' : ''; ?>> Enable unique test token bypass
            </label>
            <label class="small-text" for="test_token_value">Current token</label>
            <input class="input" id="test_token_value" name="test_token_value" value="<?php echo h($testTokenValue); ?>" placeholder="Leave blank to keep or generate">
            <label class="small-text" style="display:flex;align-items:center;gap:8px; margin-top:10px;">
              <input type="checkbox" name="generate_test_token" value="1"> Generate a new token
            </label>
            <p class="small-text">Share this value privately with testers who should bypass invites via register.php.</p>
          </div>

          <div style="grid-column:1 / -1;">
            <button class="btn" type="submit">Save system settings</button>
          </div>
        </form>
      </section>
    <?php endif; ?>
  </main>

  <div class="modal-backdrop" id="securityModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true">
      <header>
        <h3 id="modalTitle">Security Verification</h3>
        <p id="modalSubtitle">Complete the following steps to continue.</p>
      </header>
      <div class="step-content" id="modalContent"></div>
      <footer id="modalFooter"></footer>
    </div>
  </div>

  <script>
    const csrfToken = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;

    function setButtonBusy(button, busy, busyText = 'Processing...') {
      if (!button) return;
      if (busy) {
        if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
        button.disabled = true;
        button.textContent = busyText;
        button.style.opacity = '0.6';
      } else {
        button.disabled = false;
        if (button.dataset.originalText) {
          button.textContent = button.dataset.originalText;
          delete button.dataset.originalText;
        }
        button.style.opacity = '';
      }
    }

    const securityModal = (() => {
      const backdrop = document.getElementById('securityModal');
      const titleEl = document.getElementById('modalTitle');
      const subtitleEl = document.getElementById('modalSubtitle');
      const contentEl = document.getElementById('modalContent');
      const footerEl = document.getElementById('modalFooter');

      function open({ title, subtitle, html, actions = [] }) {
        titleEl.textContent = title || 'Security Check';
        subtitleEl.textContent = subtitle || '';
        contentEl.innerHTML = html || '';
        footerEl.innerHTML = '';

        actions.forEach(action => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn' + (action.variant ? ' ' + action.variant : '');
          btn.textContent = action.label;
          if (action.role) btn.dataset.role = action.role;
          btn.addEventListener('click', action.onClick);
          footerEl.appendChild(btn);
        });

        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
      }

      function close() {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
        contentEl.innerHTML = '';
        footerEl.innerHTML = '';
      }

      function showError(message) {
        const box = contentEl.querySelector('[data-role="error"]');
        if (!box) return;
        if (!message) {
          box.hidden = true;
          box.textContent = '';
          return;
        }
        box.hidden = false;
        box.textContent = message;
      }

      function setBusy(role, busy, label = 'Processing...') {
        const btn = footerEl.querySelector(role ? `[data-role="${role}"]` : 'button');
        if (!btn) return;
        if (busy) {
          if (!btn.dataset.originalText) btn.dataset.originalText = btn.textContent;
          btn.disabled = true;
          btn.textContent = label;
        } else {
          btn.disabled = false;
          if (btn.dataset.originalText) {
            btn.textContent = btn.dataset.originalText;
            delete btn.dataset.originalText;
          }
        }
      }

      function getContent() {
        return contentEl;
      }

      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) close();
      });
      window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && backdrop.classList.contains('open')) close();
      });

      return { open, close, showError, setBusy, getContent };
    })();

    function hexToArrayBuffer(hex) {
      if (!hex) return new ArrayBuffer(0);
      const len = hex.length / 2;
      const arr = new Uint8Array(len);
      for (let i = 0; i < len; i++) arr[i] = parseInt(hex.substr(i * 2, 2), 16);
      return arr.buffer;
    }

    function bufferToBase64url(buffer) {
      const bytes = new Uint8Array(buffer);
      let str = '';
      for (let i = 0; i < bytes.byteLength; i++) str += String.fromCharCode(bytes[i]);
      return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    async function beginPasskey(name) {
      const formData = new FormData();
      formData.append('name', name);
      formData.append('csrf_token', csrfToken);
      const res = await fetch('passkey_begin_register.php', { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Unable to start passkey registration.');
      return data.publicKey;
    }

    async function finalizePasskey(attestation, password) {
      const formData = new FormData();
      formData.append('clientDataJSON', attestation.clientDataJSON);
      formData.append('attestationObject', attestation.attestationObject);
      formData.append('password', password);
      formData.append('csrf_token', csrfToken);
      const res = await fetch('passkey_finish_register.php', { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Passkey registration failed.');
    }

    async function renamePasskey(id, name) {
      const formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('passkey_id', id);
      formData.append('name', name);
      formData.append('ajax', '1');
      const res = await fetch('passkey_rename.php', { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Rename failed.');
    }

    async function renameDevice(id, name) {
      const formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('action', 'rename');
      formData.append('id', id);
      formData.append('name', name);
      const res = await fetch('trusted_devices_actions.php', { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Rename failed.');
    }

    function setupInlineEditing() {
      document.querySelectorAll('.inline-editor').forEach(editor => {
        const role = editor.dataset.role;
        const row = editor.closest('tr');
        const display = row?.querySelector('[data-field="name-display"]');
        const input = editor.querySelector('input');
        const saveBtn = editor.querySelector('[data-action="save"]');
        const cancelBtn = editor.querySelector('[data-action="cancel"]');
        const editBtn = role === 'passkey' ? row?.querySelector('.btn-edit-passkey') : row?.querySelector('.btn-edit-device');
        if (!display || !input || !saveBtn || !editBtn) return;

        editBtn.addEventListener('click', (event) => {
          event.preventDefault();
          display.hidden = true;
          editor.hidden = false;
          input.value = display.textContent.trim();
          input.focus();
          input.select();
        });

        cancelBtn?.addEventListener('click', (event) => {
          event.preventDefault();
          editor.hidden = true;
          display.hidden = false;
        });

        saveBtn.addEventListener('click', async (event) => {
          event.preventDefault();
          const name = input.value.trim();
          if (name === '') { alert('Name cannot be empty.'); input.focus(); return; }
          const id = editor.dataset.id || row?.dataset.passkeyId || row?.dataset.deviceId;
          if (!id) return;
          const original = saveBtn.textContent;
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving...';
          try {
            if (role === 'passkey') {
              await renamePasskey(id, name);
            } else {
              await renameDevice(id, name);
            }
            display.textContent = name;
            editor.hidden = true;
            display.hidden = false;
          } catch (err) {
            alert(err.message || err);
          } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = original;
          }
        });
      });
    }

    setupInlineEditing();

    const emailButton = document.getElementById('btnEmailToggle');
    if (emailButton) {
      emailButton.addEventListener('click', () => handleEmailToggle(emailButton));
    }

    async function handleEmailToggle(button) {
      const state = button.dataset.state === 'disable' ? 'disable' : 'enable';
      setButtonBusy(button, true);
      try {
        const formData = new FormData();
        formData.append('action', 'request');
        formData.append('state', state);
        formData.append('csrf_token', csrfToken);
        const res = await fetch('twofa_email_actions.php', { method: 'POST', body: formData, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to send verification email.');
        setButtonBusy(button, false, 'Processing...');
        openEmailModal(state);
      } catch (err) {
        alert(err.message || err);
        setButtonBusy(button, false);
      }
    }

    function openEmailModal(state) {
      securityModal.open({
        title: state === 'enable' ? 'Enable Email Authentication' : 'Disable Email Authentication',
        subtitle: 'Enter the 6-digit code sent to your email and confirm with your current password. Codes expire in 15 minutes.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="emailToggleForm" autocomplete="off">
            <label class="small-text">6-digit code</label>
            <input class="input" name="code" maxlength="6" inputmode="numeric" required>
            <label class="small-text">Current password</label>
            <input class="input" name="password" type="password" autocomplete="current-password" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); } },
          { label: state === 'enable' ? 'Enable' : 'Disable', role: 'submit', onClick: (e) => submitEmailToggle(e, state) }
        ]
      });
      const codeField = securityModal.getContent().querySelector('input[name="code"]');
      if (codeField) codeField.focus();
    }

    async function submitEmailToggle(event, state) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#emailToggleForm');
      if (!form) return;
      const code = form.code.value.trim();
      const password = form.password.value;
      if (code.length !== 6) { securityModal.showError('Enter the 6-digit code.'); return; }
      if (!password) { securityModal.showError('Enter your current password.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true);
      try {
        const body = new FormData();
        body.append('action', 'confirm');
        body.append('state', state);
        body.append('code', code);
        body.append('password', password);
        body.append('csrf_token', csrfToken);
        const res = await fetch('twofa_email_actions.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to update email authentication.');
        securityModal.close();
        location.reload();
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    const enableAppBtn = document.getElementById('btnEnableApp');
    if (enableAppBtn) enableAppBtn.addEventListener('click', () => startAppEnable(enableAppBtn));
    const disableAppBtn = document.getElementById('btnDisableApp');
    if (disableAppBtn) disableAppBtn.addEventListener('click', () => openAppDisableModal());

    async function startAppEnable(button) {
      setButtonBusy(button, true);
      try {
        const body = new FormData();
        body.append('action', 'request_enable');
        body.append('csrf_token', csrfToken);
        const res = await fetch('twofa_app_actions.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to start authenticator setup.');
        setButtonBusy(button, false);
        openAppCodeStep();
      } catch (err) {
        alert(err.message || err);
        setButtonBusy(button, false);
      }
    }

    function openAppCodeStep() {
      securityModal.open({
        title: 'Enable Authenticator App',
        subtitle: 'Enter the 6-digit code we emailed to confirm this setup request.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="appCodeForm" autocomplete="off">
            <label class="small-text">6-digit code</label>
            <input class="input" name="code" maxlength="6" inputmode="numeric" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); } },
          { label: 'Verify Code', role: 'submit', onClick: submitAppCode }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="code"]');
      if (input) input.focus();
    }

    async function submitAppCode(event) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#appCodeForm');
      if (!form) return;
      const code = form.code.value.trim();
      if (code.length !== 6) { securityModal.showError('Enter the 6-digit code.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Verifying...');
      try {
        const body = new FormData();
        body.append('action', 'verify_code');
        body.append('code', code);
        body.append('csrf_token', csrfToken);
        const res = await fetch('twofa_app_actions.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Invalid or expired code.');
        openAppQrStep(data);
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    function chunkSecret(secret) {
      return secret.replace(/(.{4})/g, '$1 ').trim();
    }

    function openAppQrStep(data) {
      const qrUrl = data.qr || '';
      const secret = data.secret || '';
      securityModal.open({
        title: 'Scan the QR Code',
        subtitle: 'Scan this code with your authenticator app, then continue.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <div class="qr-preview">
            <img src="${qrUrl}" alt="Authenticator QR">
            <div class="small-text">Secret: <code>${chunkSecret(secret)}</code></div>
          </div>
          <p class="small-text">If you cannot scan the QR code, enter the secret manually in your authenticator app.</p>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); } },
          { label: 'Next', role: 'submit', onClick: (e) => { e.preventDefault(); openAppConfirmStep(); } }
        ]
      });
    }

    function openAppConfirmStep() {
      securityModal.open({
        title: 'Confirm Authenticator App',
        subtitle: 'Enter a code from your authenticator app and your current password to finish.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="appConfirmForm" autocomplete="off">
            <label class="small-text">Authenticator code</label>
            <input class="input" name="code" maxlength="6" inputmode="numeric" required>
            <label class="small-text">Current password</label>
            <input class="input" name="password" type="password" autocomplete="current-password" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); } },
          { label: 'Enable App', role: 'submit', onClick: submitAppConfirm }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="code"]');
      if (input) input.focus();
    }

    async function submitAppConfirm(event) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#appConfirmForm');
      if (!form) return;
      const code = form.code.value.trim();
      const password = form.password.value;
      if (code.length !== 6) { securityModal.showError('Enter the 6-digit code from your authenticator app.'); return; }
      if (!password) { securityModal.showError('Enter your current password.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Enabling...');
      try {
        const body = new FormData();
        body.append('action', 'confirm_enable');
        body.append('code', code);
        body.append('password', password);
        body.append('csrf_token', csrfToken);
        const res = await fetch('twofa_app_actions.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to enable authenticator app.');
        securityModal.close();
        location.reload();
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    function openAppDisableModal() {
      securityModal.open({
        title: 'Disable Authenticator App',
        subtitle: 'Enter a current authenticator code and your password to disable the app requirement.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="appDisableForm" autocomplete="off">
            <label class="small-text">Authenticator code</label>
            <input class="input" name="code" maxlength="6" inputmode="numeric" required>
            <label class="small-text">Current password</label>
            <input class="input" name="password" type="password" autocomplete="current-password" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); } },
          { label: 'Disable App', role: 'submit', variant: 'danger', onClick: submitAppDisable }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="code"]');
      if (input) input.focus();
    }

    async function submitAppDisable(event) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#appDisableForm');
      if (!form) return;
      const code = form.code.value.trim();
      const password = form.password.value;
      if (code.length !== 6) { securityModal.showError('Enter the 6-digit code from your authenticator app.'); return; }
      if (!password) { securityModal.showError('Enter your current password.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Disabling...');
      try {
        const body = new FormData();
        body.append('action', 'disable');
        body.append('code', code);
        body.append('password', password);
        body.append('csrf_token', csrfToken);
        const res = await fetch('twofa_app_actions.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to disable authenticator app.');
        securityModal.close();
        location.reload();
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    const addPasskeyBtn = document.getElementById('btnAddPasskey');
    if (addPasskeyBtn) addPasskeyBtn.addEventListener('click', () => startPasskeyFlow(addPasskeyBtn));

    let pendingAttestation = null;

    async function startPasskeyFlow(button) {
      if (!window.PublicKeyCredential) {
        alert('This browser does not support passkeys.');
        return;
      }
      setButtonBusy(button, true);
      try {
        const res = await fetch('passkey_email_request.php', { method: 'POST', credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to send verification code.');
        setButtonBusy(button, false);
        openPasskeyCodeStep();
      } catch (err) {
        alert(err.message || err);
        setButtonBusy(button, false);
      }
    }

    function openPasskeyCodeStep() {
      pendingAttestation = null;
      securityModal.open({
        title: 'Add a Passkey',
        subtitle: 'Enter the 6-digit code sent to your email to begin passkey registration.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="passkeyCodeForm" autocomplete="off">
            <label class="small-text">6-digit code</label>
            <input class="input" name="code" maxlength="6" inputmode="numeric" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); pendingAttestation = null; } },
          { label: 'Verify Code', role: 'submit', onClick: submitPasskeyCode }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="code"]');
      if (input) input.focus();
    }

    async function submitPasskeyCode(event) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#passkeyCodeForm');
      if (!form) return;
      const code = form.code.value.trim();
      if (code.length !== 6) { securityModal.showError('Enter the 6-digit code.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Verifying...');
      try {
        const body = new FormData();
        body.append('code', code);
        body.append('csrf_token', csrfToken);
        const res = await fetch('passkey_email_verify.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Invalid or expired code.');
        openPasskeyNameStep();
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    function openPasskeyNameStep() {
      securityModal.open({
        title: 'Name Your Passkey',
        subtitle: 'Choose a name so you recognize this passkey in the future.',
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="passkeyNameForm" autocomplete="off">
            <label class="small-text">Passkey name</label>
            <input class="input" name="name" maxlength="100" value="My Passkey" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); pendingAttestation = null; } },
          { label: 'Create Passkey', role: 'submit', onClick: submitPasskeyCreate }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="name"]');
      if (input) input.select();
    }

    async function submitPasskeyCreate(event) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#passkeyNameForm');
      if (!form) return;
      const name = (form.name.value || '').trim() || 'My Passkey';
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Waiting for device...');
      try {
        const pubKey = await beginPasskey(name);
        const creationOptions = { ...pubKey };
        creationOptions.challenge = hexToArrayBuffer(pubKey.challengeHex);
        delete creationOptions.challengeHex;
        if (creationOptions.user && creationOptions.user.idHex) {
          creationOptions.user.id = hexToArrayBuffer(creationOptions.user.idHex);
          delete creationOptions.user.idHex;
        }
        const credential = await navigator.credentials.create({ publicKey: creationOptions });
        if (!credential) throw new Error('Credential creation was cancelled.');
        pendingAttestation = {
          clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
          attestationObject: bufferToBase64url(credential.response.attestationObject)
        };
        openPasskeyConfirmStep(name);
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    function openPasskeyConfirmStep(name) {
      securityModal.open({
        title: 'Confirm Passkey',
        subtitle: `Enter your current password to finish adding "${name}".`,
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="passkeyConfirmForm" autocomplete="off">
            <label class="small-text">Current password</label>
            <input class="input" name="password" type="password" autocomplete="current-password" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); pendingAttestation = null; } },
          { label: 'Save Passkey', role: 'submit', onClick: submitPasskeyFinalize }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="password"]');
      if (input) input.focus();
    }

    async function submitPasskeyFinalize(event) {
      event.preventDefault();
      if (!pendingAttestation) { securityModal.showError('Passkey data missing. Restart the flow.'); return; }
      const form = securityModal.getContent().querySelector('#passkeyConfirmForm');
      if (!form) return;
      const password = form.password.value;
      if (!password) { securityModal.showError('Enter your current password.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Saving...');
      try {
        await finalizePasskey(pendingAttestation, password);
        pendingAttestation = null;
        securityModal.close();
        location.reload();
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }

    document.querySelectorAll('.btn-delete-passkey').forEach(btn => {
      btn.addEventListener('click', () => openPasskeyDeleteModal(btn));
    });

    function openPasskeyDeleteModal(button) {
      const id = button.getAttribute('data-passkey-id');
      if (!id) return;
      const label = button.getAttribute('data-passkey-name') || 'this passkey';
      securityModal.open({
        title: 'Delete Passkey',
        subtitle: `Enter your current password to delete ${label}.`,
        html: `
          <div class="error" data-role="error" hidden></div>
          <form id="passkeyDeleteForm" autocomplete="off">
            <input type="hidden" name="id" value="${id}">
            <label class="small-text">Current password</label>
            <input class="input" name="password" type="password" autocomplete="current-password" required>
          </form>
        `,
        actions: [
          { label: 'Cancel', variant: 'secondary', role: 'cancel', onClick: (e) => { e.preventDefault(); securityModal.close(); } },
          { label: 'Delete Passkey', role: 'submit', variant: 'danger', onClick: submitPasskeyDelete }
        ]
      });
      const input = securityModal.getContent().querySelector('input[name="password"]');
      if (input) input.focus();
    }

    async function submitPasskeyDelete(event) {
      event.preventDefault();
      const form = securityModal.getContent().querySelector('#passkeyDeleteForm');
      if (!form) return;
      const id = form.id.value;
      const password = form.password.value;
      if (!password) { securityModal.showError('Enter your current password.'); return; }
      securityModal.showError('');
      securityModal.setBusy('submit', true, 'Deleting...');
      try {
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('passkey_id', id);
        body.append('password', password);
        body.append('ajax', '1');
        const res = await fetch('passkey_delete.php', { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Unable to delete passkey.');
        securityModal.close();
        location.reload();
      } catch (err) {
        securityModal.setBusy('submit', false);
        securityModal.showError(err.message || err);
      }
    }
  </script>
</body>
</html>
