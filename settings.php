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

$twofaSetup = $_SESSION['twofa_app_setup'] ?? null;

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        redirect_with_flash('error', 'Invalid or expired session. Please try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
        case 'toggle_email': {
            $state = $_POST['state'] ?? '';
            $enable = ($state === 'enable');
            $val = $enable ? 1 : 0;
            if ($st = $conn->prepare("UPDATE users SET twofa_email_enabled=?, twofa_email_code=NULL, twofa_email_expires=NULL WHERE id=?")) {
                $st->bind_param('ii', $val, $uid);
                $st->execute();
                $st->close();
            }
            if (function_exists('ppf_log')) {
                $event = $enable ? 'twofa_email_enabled' : 'twofa_email_disabled';
                ppf_log($conn, $uid, $email ?: null, $role ?: null, $event, 'user', (string)$uid, null);
            }
            redirect_with_flash('success', $enable ? 'Email authentication is now enabled.' : 'Email authentication has been disabled.', 'twofa');
        }

        case 'start_app_setup': {
            $secret = strtoupper(ppf_totp_new_secret(20));
            $_SESSION['twofa_app_setup'] = [
                'secret' => $secret,
                'created' => time(),
            ];
            redirect_with_flash('info', 'Authenticator setup started. Scan the QR code and confirm with a code to finish.', 'twofa');
        }

        case 'cancel_app_setup': {
            unset($_SESSION['twofa_app_setup']);
            redirect_with_flash('info', 'Authenticator setup cancelled.', 'twofa');
        }

        case 'confirm_app_setup': {
            $setup = $_SESSION['twofa_app_setup'] ?? null;
            $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
            if (!$setup || empty($setup['secret'])) {
                redirect_with_flash('error', 'Setup session expired. Start again.', 'twofa');
            }
            if ($code === '' || !ppf_totp_verify($setup['secret'], $code, 30, 6, 8)) {
                redirect_with_flash('error', 'Invalid authenticator code. Please try again.', 'twofa');
            }
            if ($st = $conn->prepare("UPDATE users SET twofa_app_enabled=1, twofa_secret=? WHERE id=?")) {
                $secret = strtoupper($setup['secret']);
                $st->bind_param('si', $secret, $uid);
                $st->execute();
                $st->close();
            }
            unset($_SESSION['twofa_app_setup']);
            if (function_exists('ppf_log')) {
                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'twofa_app_enabled', 'user', (string)$uid, null);
            }
            redirect_with_flash('success', 'Authenticator app protection is now enabled.', 'twofa');
        }

        case 'disable_app': {
            $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
            if ($code === '') {
                redirect_with_flash('error', 'Enter the current authenticator code to disable it.', 'twofa');
            }
            $secret = '';
            if ($st = $conn->prepare("SELECT twofa_secret FROM users WHERE id=? LIMIT 1")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
                $secret = strtoupper(preg_replace('/\s+/', '', (string)($row['twofa_secret'] ?? '')));
            }
            if ($secret === '' || !ppf_totp_verify($secret, $code, 30, 6, 8)) {
                redirect_with_flash('error', 'Authenticator code was not recognized. Try again.', 'twofa');
            }
            if ($st = $conn->prepare("UPDATE users SET twofa_app_enabled=0, twofa_secret=NULL WHERE id=?")) {
                $st->bind_param('i', $uid);
                $st->execute();
                $st->close();
            }
            if (function_exists('ppf_log')) {
                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'twofa_app_disabled', 'user', (string)$uid, null);
            }
            redirect_with_flash('info', 'Authenticator app requirement removed.', 'twofa');
        }

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

// expire stale setup state (10 minutes)
if ($twofaSetup && isset($twofaSetup['created']) && (time() - (int)$twofaSetup['created'] > 600)) {
    unset($_SESSION['twofa_app_setup']);
    $twofaSetup = null;
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

$accountName = $email !== '' ? $email : ('user' . $uid . '@peterpangfit');
$otpauthUrl  = ($twofaSetup && !empty($twofaSetup['secret'])) ? ppf_otpauth_url('Peter Pang Fit', $accountName, $twofaSetup['secret']) : null;

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

    .totp-setup {
      margin-top: 18px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      align-items: start;
    }
    .totp-setup pre {
      background: rgba(8, 12, 24, 0.92);
      border: 1px dashed var(--border);
      border-radius: 12px;
      padding: 16px;
      font-size: .95rem;
      overflow-x: auto;
    }
    .qr-frame {
      background: rgba(8, 12, 24, 0.92);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

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
        <form method="post" class="inline">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="toggle_email">
          <input type="hidden" name="state" value="<?php echo $twofaEmailEnabled ? 'disable' : 'enable'; ?>">
          <button class="btn<?php echo $twofaEmailEnabled ? ' secondary' : ''; ?>" type="submit">
            <?php echo $twofaEmailEnabled ? 'Disable' : 'Enable'; ?>
          </button>
        </form>
      </div>

      <div class="switch-row">
        <div class="meta">
          <strong>Authenticator App</strong>
          <span><?php echo $twofaAppEnabled ? 'Logins require a 6-digit code from your authenticator.' : 'Pair an authenticator app like Google Authenticator or Authy for stronger protection.'; ?></span>
        </div>
        <?php if ($twofaAppEnabled): ?>
          <form method="post" class="inline" style="display:flex;align-items:center;gap:10px;">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="disable_app">
            <input class="input" style="width:140px;" name="code" placeholder="123456" maxlength="6" inputmode="numeric" required>
            <button class="btn danger" type="submit">Disable</button>
          </form>
        <?php else: ?>
          <form method="post" class="inline">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="start_app_setup">
            <button class="btn" type="submit">Start setup</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($twofaSetup && $otpauthUrl): ?>
        <div class="totp-setup">
          <div>
            <div class="qr-frame">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&amp;data=<?php echo urlencode($otpauthUrl); ?>" alt="Authenticator QR">
            </div>
          </div>
          <div>
            <h3>Scan &amp; Confirm</h3>
            <p class="muted">Scan the QR code with your authenticator app, or enter this secret manually:</p>
            <pre><?php echo h(chunk_split($twofaSetup['secret'], 4, ' ')); ?></pre>
            <form method="post" class="actions-row">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="confirm_app_setup">
              <input class="input" style="max-width:180px;" name="code" placeholder="123456" inputmode="numeric" maxlength="6" required>
              <button class="btn" type="submit">Confirm</button>
            </form>
            <form method="post" class="inline" style="margin-top:12px; display:inline-block;">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="cancel_app_setup">
              <button class="btn secondary" type="submit">Cancel setup</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
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
                  <td>
                    <div class="table-primary">
                      <strong data-field="name"><?php echo h($pkName !== '' ? $pkName : 'Unnamed passkey'); ?></strong>
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
                    <button class="btn small ghost btn-edit-passkey" data-passkey-id="<?php echo $pkId; ?>" data-passkey-name="<?php echo h($pkName); ?>">Edit</button>
                    <button class="btn small danger btn-delete-passkey" data-passkey-id="<?php echo $pkId; ?>">Delete</button>
                    <form method="post" action="passkey_rename.php" class="rename-form" id="rename-passkey-<?php echo $pkId; ?>" style="display:none;">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="passkey_id" value="<?php echo $pkId; ?>">
                      <input type="hidden" name="name" value="<?php echo h($pkName); ?>">
                    </form>
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
                  <td>
                    <div class="table-primary">
                      <strong data-field="name"><?php echo h($tdName !== '' ? $tdName : 'Unnamed device'); ?></strong>
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
                    <button class="btn small ghost btn-edit-device" data-device-id="<?php echo $tdId; ?>" data-device-name="<?php echo h($tdName); ?>">Edit</button>
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
                <th>Operating System</th>
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
                      <?php if (!empty($s['ip'])): ?><span class="table-subtext">IP <?php echo h($s['ip']); ?></span><?php endif; ?>
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
                      <?php if ($s['user_agent']): ?><span class="table-subtext">UA fingerprint stored</span><?php endif; ?>
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

  <script>
    const csrfToken = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;

    async function beginPasskey(name) {
      const formData = new FormData();
      formData.append('name', name);
      const res = await fetch('passkey_begin_register.php', { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Unable to start passkey registration.');
      return data.publicKey;
    }

    function hexToArrayBuffer(hex) {
      if (!hex) return new ArrayBuffer(0);
      const len = hex.length / 2;
      const arr = new Uint8Array(len);
      for (let i = 0; i < len; i++) {
        arr[i] = parseInt(hex.substr(i * 2, 2), 16);
      }
      return arr.buffer;
    }

    function bufferToBase64url(buffer) {
      const bytes = new Uint8Array(buffer);
      let str = '';
      for (let i = 0; i < bytes.byteLength; i++) str += String.fromCharCode(bytes[i]);
      return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    async function completePasskey(attestation) {
      const formData = new FormData();
      formData.append('clientDataJSON', attestation.clientDataJSON);
      formData.append('attestationObject', attestation.attestationObject);
      const res = await fetch('passkey_finish_register.php', { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Passkey registration failed.');
    }

    document.getElementById('btnAddPasskey')?.addEventListener('click', async () => {
      try {
        if (!window.PublicKeyCredential) throw new Error('This browser does not support WebAuthn.');
        const name = prompt('Name this passkey', 'My Passkey');
        if (name === null || name.trim() === '') return;
        const pubKey = await beginPasskey(name.trim());

        const creationOptions = { ...pubKey };
        creationOptions.challenge = hexToArrayBuffer(pubKey.challengeHex);
        delete creationOptions.challengeHex;
        if (creationOptions.user && creationOptions.user.idHex) {
          creationOptions.user.id = hexToArrayBuffer(creationOptions.user.idHex);
          delete creationOptions.user.idHex;
        }

        const cred = await navigator.credentials.create({ publicKey: creationOptions });
        if (!cred) throw new Error('Credential creation was cancelled.');

        await completePasskey({
          clientDataJSON: bufferToBase64url(cred.response.clientDataJSON),
          attestationObject: bufferToBase64url(cred.response.attestationObject),
        });
        location.reload();
      } catch (err) {
        alert(err.message || err);
      }
    });

    document.querySelectorAll('.btn-edit-passkey').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-passkey-id');
        if (!id) return;
        const form = document.getElementById(`rename-passkey-${id}`);
        if (!form) return;
        const nameInput = form.querySelector('input[name="name"]');
        const current = btn.getAttribute('data-passkey-name') || (nameInput ? nameInput.value : '');
        const preset = current && current.trim() !== '' ? current : 'My Passkey';
        const next = prompt('Rename passkey', preset);
        if (next === null) return;
        const trimmed = next.trim();
        if (trimmed === '') { alert('Name cannot be empty.'); return; }
        if (nameInput) nameInput.value = trimmed;
        btn.setAttribute('data-passkey-name', trimmed);
        form.submit();
      });
    });

    // Passkey delete flow
    document.querySelectorAll('.btn-delete-passkey').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-passkey-id');
        if (!id) return;
        const password = prompt('Confirm your password to delete this passkey');
        if (password === null) return;
        try {
          const code = await requestPasskeyDeleteCode();
          const entered = prompt('Enter the 6-digit code sent to your email');
          if (entered === null) return;
          const form = new FormData();
          form.append('csrf_token', csrfToken);
          form.append('passkey_id', id);
          form.append('password', password);
          form.append('code', entered);
          const res = await fetch('passkey_delete.php', { method: 'POST', body: form, credentials: 'same-origin' });
          if (res.redirected) {
            location.href = res.url;
            return;
          }
          const text = await res.text();
          if (text) {
            console.error(text);
            alert('Delete failed.');
          }
        } catch (err) {
          alert(err.message || err);
        }
      });
    });

    async function requestPasskeyDeleteCode() {
      const res = await fetch('passkey_delete_email_request.php', { method: 'POST', credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Unable to send confirmation code.');
      return true;
    }

    // Trusted device rename
    document.querySelectorAll('.btn-edit-device').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        const row = btn.closest('tr');
        if (!row) return;
        const id = btn.getAttribute('data-device-id') || row.getAttribute('data-device-id');
        if (!id) return;
        const nameCell = row.querySelector('[data-field="name"]');
        const current = btn.getAttribute('data-device-name') || (nameCell ? nameCell.textContent.trim() : '');
        const preset = current && current.trim() !== '' ? current : 'Trusted device';
        const next = prompt('Rename trusted device', preset);
        if (next === null) return;
        const trimmed = next.trim();
        if (trimmed === '') { alert('Name cannot be empty.'); return; }
        try {
          const form = new FormData();
          form.append('csrf_token', csrfToken);
          form.append('action', 'rename');
          form.append('id', id);
          form.append('name', trimmed);
          const res = await fetch('trusted_devices_actions.php', { method: 'POST', body: form, credentials: 'same-origin' });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error || 'Rename failed.');
          if (nameCell) nameCell.textContent = trimmed;
          btn.setAttribute('data-device-name', trimmed);
        } catch (err) {
          alert(err.message || err);
        }
      });
    });
  </script>
</body>
</html>
