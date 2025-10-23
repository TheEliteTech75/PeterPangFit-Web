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
require_once __DIR__ . '/ppf_theme.php';

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
ppf_theme_ensure_column($conn);

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

        case 'set_theme': {
            $themeKey = ppf_theme_sanitize_key((string)($_POST['theme'] ?? ''));
            if (!ppf_theme_exists($themeKey)) {
                redirect_with_flash('error', 'Please choose a valid theme.', 'appearance');
            }
            if ($st = $conn->prepare("UPDATE users SET theme=? WHERE id=?")) {
                $st->bind_param('si', $themeKey, $uid);
                $st->execute();
                $st->close();
            } else {
                redirect_with_flash('error', 'Unable to save your theme right now. Please try again.', 'appearance');
            }
            $_SESSION['theme'] = $themeKey;
            if (function_exists('ppf_log')) {
                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'theme_updated', 'user', (string)$uid, $themeKey);
            }
            redirect_with_flash('success', 'Theme updated. Enjoy your new look!', 'appearance');
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
if ($st = $conn->prepare("SELECT email, first_name, last_name, role, theme, twofa_email_enabled, twofa_app_enabled, twofa_secret FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $userRow = $res ? $res->fetch_assoc() : null;
    $st->close();
}

$twofaEmailEnabled = (int)($userRow['twofa_email_enabled'] ?? 0) === 1;
$twofaAppEnabled   = (int)($userRow['twofa_app_enabled'] ?? 0) === 1;
$twofaSecret       = strtoupper(preg_replace('/\s+/', '', (string)($userRow['twofa_secret'] ?? '')));

$themeCatalog     = ppf_theme_catalog();
$currentThemeKey  = ppf_theme_sanitize_key((string)($userRow['theme'] ?? ($_SESSION['theme'] ?? '')));
if (!ppf_theme_exists($currentThemeKey)) {
    $currentThemeKey = ppf_theme_default_key();
}
$_SESSION['theme'] = $currentThemeKey;
$themeGroups      = ppf_theme_grouped_catalog();

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
  <title>Settings · Peter Pang Fit</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #05070d;
      --bg-alt: #03040a;
      --surface: rgba(9, 14, 28, 0.92);
      --surface-alt: rgba(15, 23, 42, 0.78);
      --surface-soft: rgba(15, 23, 42, 0.65);
      --surface-strong: rgba(11, 16, 32, 0.94);
      --border: rgba(148, 163, 184, 0.18);
      --border-strong: rgba(56, 189, 248, 0.35);
      --text: #f8fafc;
      --muted: rgba(203, 213, 225, 0.78);
      --muted-soft: rgba(148, 163, 184, 0.72);
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
      display: flex;
      flex-direction: column;
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

    .settings-subheader {
      position: sticky;
      top: 72px;
      padding: 12px 0 4px;
      z-index: 2200;
      background: linear-gradient(180deg, rgba(2, 6, 23, 0.94), rgba(2, 6, 23, 0.72));
      border-bottom: 1px solid var(--border);
      backdrop-filter: blur(18px);
    }

    .settings-tabs {
      display: inline-flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
    }

    .settings-tab {
      appearance: none;
      border: 1px solid transparent;
      background: rgba(15, 23, 42, 0.65);
      color: var(--muted);
      padding: 10px 18px;
      border-radius: 999px;
      font-weight: 600;
      font-size: .95rem;
      letter-spacing: .01em;
      cursor: pointer;
      transition: all .2s ease;
    }
    .settings-tab:hover,
    .settings-tab:focus-visible {
      color: var(--text);
      border-color: var(--border-strong);
      outline: none;
    }
    .settings-tab.is-active {
      background: rgba(56, 189, 248, 0.18);
      color: var(--text);
      border-color: var(--border-strong);
      box-shadow: 0 16px 36px rgba(2, 6, 23, 0.45);
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

    .tab-panel {
      display: none;
      flex-direction: column;
      gap: 24px;
    }
    .tab-panel.is-active {
      display: flex;
    }

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

    .theme-category + .theme-category { margin-top: 32px; }
    .theme-category h3 { font-size: 1.05rem; letter-spacing: .12em; text-transform: uppercase; color: var(--muted-soft); margin: 0 0 12px; }

    .theme-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
    }

    .theme-card {
      display: flex;
      flex-direction: column;
      gap: 16px;
      border-radius: 20px;
      border: 1px solid var(--border);
      padding: 18px;
      background: rgba(15, 23, 42, 0.68);
      box-shadow: var(--shadow);
      backdrop-filter: blur(14px);
      transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
      position: relative;
    }

    .theme-card:hover {
      transform: translateY(-2px);
      border-color: var(--border-strong);
    }

    .theme-card.is-active {
      border-color: rgba(34, 197, 94, 0.55);
      box-shadow: 0 24px 60px rgba(2, 6, 23, 0.55);
    }

    .theme-preview {
      height: 132px;
      border-radius: 16px;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
    }

    .theme-title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .theme-title-row h4 {
      margin: 0;
      font-size: 1.05rem;
      letter-spacing: .01em;
    }

    .theme-pill {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: .7rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      background: rgba(56, 189, 248, 0.18);
      color: var(--accent);
    }

    .theme-card.is-active .theme-pill {
      background: rgba(34, 197, 94, 0.18);
      color: var(--success);
    }

    .theme-info {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .theme-info p {
      margin: 8px 0 0;
      color: var(--muted);
      font-size: .9rem;
      line-height: 1.4;
    }

    .theme-swatches {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 10px;
    }

    .theme-swatches span {
      width: 18px;
      height: 18px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    }

    .theme-actions {
      margin-top: auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .theme-active-note {
      color: var(--muted);
      font-size: .85rem;
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
    .btn.is-loading,
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    form.inline { display: inline; }

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

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.72);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      z-index: 1000;
      opacity: 0;
      pointer-events: none;
      transition: opacity .2s ease;
    }
    .modal-backdrop.hidden { display: none; }
    .modal-backdrop.active {
      opacity: 1;
      pointer-events: auto;
    }
    .modal {
      width: min(520px, 100%);
      max-height: calc(100vh - 80px);
      overflow-y: auto;
      background: rgba(8, 12, 24, 0.98);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 24px 70px rgba(2,6,23,0.65);
    }
    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }
    .modal-title {
      font-size: 1.2rem;
      font-weight: 600;
      margin: 0;
    }
    .modal-close {
      border: none;
      background: transparent;
      color: var(--muted);
      font-size: 1.35rem;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      cursor: pointer;
    }
    .modal-close:hover { background: rgba(56,189,248,0.12); color: #e0f2fe; }
    .modal-body { font-size: .95rem; }
    .modal-body label { display: block; font-size: .85rem; color: var(--muted); margin-bottom: 6px; }
    .modal-body p { color: var(--muted); margin: 0 0 12px; }
    .modal-form { display: flex; flex-direction: column; gap: 14px; }
    .modal-error { color: #fca5a5; font-size: .85rem; }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }
    .modal-info {
      background: rgba(15,23,42,0.7);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px 14px;
      font-size: .9rem;
      color: var(--muted);
    }
    .modal-qr {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 18px;
    }
    .modal-secret {
      font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
      letter-spacing: 2px;
      font-size: 1rem;
      background: rgba(8, 12, 24, 0.92);
      border: 1px dashed var(--border);
      border-radius: 12px;
      padding: 12px 16px;
      display: inline-block;
    }

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
      margin: 20px 0 24px;
    }
    .chips .chip {
      border-radius: 999px;
      padding: 8px 14px;
      font-size: .85rem;
      border: 1px solid transparent;
    }
    .chips .chip-current { background: rgba(56,189,248,0.2); color: #bae6fd; }
    .chips .chip-active  { background: rgba(52,211,153,0.18); color: #bbf7d0; }
    .chips .chip-inactive{ background: rgba(148,163,184,0.18); color: #e2e8f0; }
    .chips .chip-expired { background: rgba(251,191,36,0.15); color: #fde68a; }
    .chips .chip-revoked { background: rgba(248,113,113,0.18); color: #fecaca; }

    .actions-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 16px;
    }

    .editable-text {
      cursor: pointer;
      border-radius: 6px;
      padding: 2px 4px;
      display: inline-block;
    }
    .editable-text:focus {
      outline: 2px solid rgba(56,189,248,0.45);
      outline-offset: 2px;
    }
    .inline-edit {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .inline-edit .input {
      flex: 1 1 220px;
      min-width: 160px;
    }
    .inline-edit .btn.small {
      flex: 0 0 auto;
    }
    .inline-edit-error {
      color: #fca5a5;
      font-size: .78rem;
      width: 100%;
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
      main.settings { padding: 0 16px 72px; margin-top: 40px; }
      .settings-subheader { top: 60px; padding: 10px 0 4px; }
      .settings-tabs { gap: 8px; }
      .switch-row { flex-direction: column; align-items: flex-start; gap: 12px; }
      .switch-row .meta { max-width: 100%; }
      .theme-grid { grid-template-columns: minmax(0, 1fr); }
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
      <h1>Settings</h1>
      <p>Personalize your account security, appearance, and administrative tools.</p>
    </section>

    <div class="settings-subheader">
      <nav class="settings-tabs" role="tablist">
        <button class="settings-tab is-active" type="button" id="tab-security" role="tab" aria-selected="true" aria-controls="settings-security" data-tab="security">Security</button>
        <button class="settings-tab" type="button" id="tab-appearance" role="tab" aria-selected="false" aria-controls="settings-appearance" data-tab="appearance" tabindex="-1">Appearance</button>
<?php if ($isAdmin): ?>
        <button class="settings-tab" type="button" id="tab-system" role="tab" aria-selected="false" aria-controls="settings-system" data-tab="system" tabindex="-1">System</button>
<?php endif; ?>
      </nav>
    </div>

<?php if ($flash): ?>
    <div class="flash <?php echo h($flash['type']); ?>">
      <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

    <div class="tab-panel is-active" data-panel="security" id="settings-security" role="tabpanel" aria-labelledby="tab-security">
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
              <button class="btn<?php echo $twofaEmailEnabled ? ' danger' : ''; ?>" type="button" id="btnToggleEmail" data-state="<?php echo $twofaEmailEnabled ? 'disable' : 'enable'; ?>">
                <?php echo $twofaEmailEnabled ? 'Disable' : 'Enable'; ?>
              </button>
            </div>

            <div class="switch-row">
              <div class="meta">
                <strong>Authenticator App</strong>
                <span><?php echo $twofaAppEnabled ? 'Logins require a 6-digit code from your authenticator.' : 'Pair an authenticator app like Google Authenticator or Authy for stronger protection.'; ?></span>
              </div>
              <button
                class="btn<?php echo $twofaAppEnabled ? ' danger' : ''; ?>"
                type="button"
                id="btnToggleApp"
                data-mode="<?php echo $twofaAppEnabled ? 'disable' : 'enable'; ?>"
              >
                <?php echo $twofaAppEnabled ? 'Disable' : 'Enable'; ?>
              </button>
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
                        <td>
                          <div class="table-primary">
                            <strong
                              class="editable-text"
                              tabindex="0"
                              data-edit-entity="passkey"
                              data-id="<?php echo $pkId; ?>"
                              data-placeholder="Unnamed passkey"
                            ><?php echo h($pkName !== '' ? $pkName : 'Unnamed passkey'); ?></strong>
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
                          <button
                            class="btn small danger btn-delete-passkey"
                            data-passkey-id="<?php echo $pkId; ?>"
                            data-passkey-name="<?php echo h($pkName !== '' ? $pkName : 'Unnamed passkey'); ?>"
                          >Delete</button>
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
                            <strong
                              class="editable-text"
                              tabindex="0"
                              data-edit-entity="trusted-device"
                              data-id="<?php echo $tdId; ?>"
                              data-placeholder="Unnamed device"
                            ><?php echo h($tdName !== '' ? $tdName : 'Unnamed device'); ?></strong>
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
              <form method="post" action="sessions_actions.php" class="inline" onsubmit="return confirm('Sign out all other sessions? This will keep only your current session active.');">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="signout_all_others">
                <button class="btn danger" type="submit">Sign Out Others</button>
              </form>
            </div>

            <div class="chips">
              <span class="chip chip-current">Current: <?php echo $sessionCounts['current'] ?? 0; ?></span>
              <span class="chip chip-active">Active: <?php echo $sessionCounts['active'] ?? 0; ?></span>
              <span class="chip chip-inactive">Inactive: <?php echo $sessionCounts['inactive'] ?? 0; ?></span>
              <span class="chip chip-expired">Expired: <?php echo $sessionCounts['expired'] ?? 0; ?></span>
              <span class="chip chip-revoked">Revoked: <?php echo $sessionCounts['revoked'] ?? 0; ?></span>
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

    </div>

    <div class="tab-panel" data-panel="appearance" id="settings-appearance" role="tabpanel" aria-labelledby="tab-appearance">
      <section class="card" id="appearance">
        <div class="section-title">
          <div>
            <h2>Theme &amp; Appearance</h2>
            <p class="muted">Switch themes to instantly refresh the interface across Peter Pang Fit.</p>
          </div>
        </div>

<?php foreach ($themeGroups as $category => $themes): ?>
        <div class="theme-category">
          <h3><?php echo h($category); ?></h3>
          <div class="theme-grid">
<?php foreach ($themes as $themeKey => $theme): ?>
<?php
  $isActiveTheme = ($themeKey === $currentThemeKey);
  $previewGradient = ppf_theme_preview_gradient($theme);
  $swatches = array_slice($theme['preview'] ?? [], 0, 4);
?>
            <form method="post" class="theme-card<?php echo $isActiveTheme ? ' is-active' : ''; ?>">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="set_theme">
              <input type="hidden" name="theme" value="<?php echo h($themeKey); ?>">
              <div class="theme-preview" style="background: <?php echo h($previewGradient); ?>;"></div>
              <div class="theme-info">
                <div class="theme-title-row">
                  <h4><?php echo h($theme['name'] ?? ucfirst($themeKey)); ?></h4>
                  <span class="theme-pill"><?php echo $isActiveTheme ? 'Active' : 'Available'; ?></span>
                </div>
<?php if (!empty($theme['description'])): ?>
                <p><?php echo h($theme['description']); ?></p>
<?php endif; ?>
<?php if ($swatches): ?>
                <div class="theme-swatches">
<?php foreach ($swatches as $color): ?>
                  <span style="background: <?php echo h($color); ?>;"></span>
<?php endforeach; ?>
                </div>
<?php endif; ?>
              </div>
              <div class="theme-actions">
<?php if ($isActiveTheme): ?>
                <span class="theme-active-note">This theme is currently applied.</span>
<?php else: ?>
                <button class="btn small" type="submit">Apply theme</button>
<?php endif; ?>
              </div>
            </form>
<?php endforeach; ?>
          </div>
        </div>
<?php endforeach; ?>
      </section>
    </div>

<?php if ($isAdmin): ?>
    <div class="tab-panel" data-panel="system" id="settings-system" role="tabpanel" aria-labelledby="tab-system">
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
    </div>
<?php endif; ?>
  </main>

  <div class="modal-backdrop hidden" id="modalBackdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle"></h3>
        <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
      </div>
      <div class="modal-body"></div>
    </div>
  </div>

  <script>
    const csrfToken = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;

    const tabButtons = Array.from(document.querySelectorAll('.settings-tab'));
    const tabPanels = Array.from(document.querySelectorAll('.tab-panel'));
    const tabStorageKey = 'ppf-settings-active-tab';

    function tabFromHash(hash) {
      if (!hash) return null;
      const normalized = hash.replace('#', '').trim();
      if (!normalized) return null;
      const directPanel = tabPanels.find((panel) => panel.dataset.panel === normalized || panel.id === normalized);
      if (directPanel) return directPanel.dataset.panel;
      const target = document.getElementById(normalized);
      if (target) {
        const hostPanel = target.closest('.tab-panel');
        if (hostPanel) return hostPanel.dataset.panel;
      }
      return null;
    }

    function activateTab(name, options = {}) {
      const desired = name || 'security';
      let matched = false;
      tabButtons.forEach((btn) => {
        const tabName = btn.dataset.tab;
        const isMatch = tabName === desired;
        if (isMatch) matched = true;
        btn.classList.toggle('is-active', isMatch);
        btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        btn.setAttribute('tabindex', isMatch ? '0' : '-1');
      });
      tabPanels.forEach((panel) => {
        const isMatch = panel.dataset.panel === desired;
        panel.classList.toggle('is-active', isMatch);
        panel.setAttribute('aria-hidden', isMatch ? 'false' : 'true');
      });
      if (matched && !options.skipStorage) {
        try { localStorage.setItem(tabStorageKey, desired); } catch (err) {}
      }
      if (matched && options.updateHash) {
        if (typeof history.replaceState === 'function') {
          history.replaceState(null, '', '#' + desired);
        } else {
          window.location.hash = '#' + desired;
        }
      }
      return matched;
    }

    (function initTabs() {
      if (!tabButtons.length) return;
      let stored = null;
      try { stored = localStorage.getItem(tabStorageKey); } catch (err) {}
      const hashTab = tabFromHash(window.location.hash);
      if (!activateTab(hashTab || stored || 'security', { skipStorage: true })) {
        activateTab('security', { skipStorage: true });
      }
      tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
          const name = btn.dataset.tab;
          if (name) {
            activateTab(name, { updateHash: true });
          }
        });
      });
      window.addEventListener('hashchange', () => {
        const next = tabFromHash(window.location.hash);
        if (next) {
          if (!activateTab(next, { skipStorage: true })) {
            activateTab('security', { skipStorage: true });
          }
        }
      });
    })();

    const modalBackdrop = document.getElementById('modalBackdrop');
    const modalBodyEl = modalBackdrop.querySelector('.modal-body');
    const modalTitleEl = modalBackdrop.querySelector('.modal-title');
    const modalCloseBtn = modalBackdrop.querySelector('.modal-close');
    let modalOnClose = null;
    let modalHideTimer = null;

    function openModal({ title, render, onClose }) {
      if (modalHideTimer) {
        clearTimeout(modalHideTimer);
        modalHideTimer = null;
      }
      modalOnClose = typeof onClose === 'function' ? onClose : null;
      modalTitleEl.textContent = title || '';
      modalBodyEl.innerHTML = '';
      modalBackdrop.classList.remove('hidden');
      modalBackdrop.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(() => modalBackdrop.classList.add('active'));
      render(modalBodyEl, {
        close: closeModal,
        setTitle: (text) => { modalTitleEl.textContent = text; }
      });
    }

    function closeModal() {
      if (modalBackdrop.classList.contains('hidden')) return;
      modalBackdrop.classList.remove('active');
      modalBackdrop.setAttribute('aria-hidden', 'true');
      modalHideTimer = setTimeout(() => {
        modalBackdrop.classList.add('hidden');
        modalHideTimer = null;
      }, 200);
      if (modalOnClose) {
        const cb = modalOnClose;
        modalOnClose = null;
        cb();
      } else {
        modalOnClose = null;
      }
    }

    modalBackdrop.addEventListener('click', (evt) => {
      if (evt.target === modalBackdrop) closeModal();
    });
    modalCloseBtn.addEventListener('click', closeModal);
    document.addEventListener('keydown', (evt) => {
      if (evt.key === 'Escape') closeModal();
    });

    function setButtonLoading(button, text) {
      if (!button) return () => {};
      const originalText = button.textContent;
      const originalDisabled = button.disabled;
      button.textContent = text;
      button.disabled = true;
      button.classList.add('is-loading');
      return (keepDisabled = false) => {
        button.textContent = originalText;
        button.classList.remove('is-loading');
        button.disabled = keepDisabled ? true : originalDisabled;
      };
    }

    function createFormData(fields = {}) {
      const form = new FormData();
      Object.entries(fields).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
          form.append(key, value);
        }
      });
      return form;
    }

    async function postJson(url, formData) {
      const res = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (err) {
        throw new Error('Unexpected server response.');
      }
      if (!data.ok) {
        throw new Error(data.error || 'Request failed.');
      }
      return data;
    }

    function makeInlineEditable(element, onSave) {
      if (!element) return;
      element.dataset.editing = '0';
      element.setAttribute('role', 'button');
      const begin = () => startInlineEdit(element, onSave);
      element.addEventListener('click', begin);
      element.addEventListener('keydown', (evt) => {
        if (evt.key === 'Enter' || evt.key === ' ') {
          evt.preventDefault();
          begin();
        }
      });
    }

    function startInlineEdit(element, onSave) {
      if (element.dataset.editing === '1') return;
      element.dataset.editing = '1';
      const placeholder = element.dataset.placeholder || '';
      const originalDisplay = element.textContent.trim();
      const originalValue = originalDisplay === placeholder ? '' : originalDisplay;
      const parent = element.parentNode;
      const container = document.createElement('div');
      container.className = 'inline-edit';
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'input';
      input.maxLength = 100;
      input.value = originalValue;
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'btn small';
      saveBtn.textContent = 'Save';
      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn small secondary';
      cancelBtn.textContent = 'Cancel';
      const errorEl = document.createElement('div');
      errorEl.className = 'inline-edit-error';
      container.append(input, saveBtn, cancelBtn, errorEl);
      parent.replaceChild(container, element);
      input.focus();
      input.select();

      const cleanup = (value, display) => {
        element.textContent = display !== undefined ? display : (value || placeholder);
        element.dataset.editing = '0';
        container.replaceWith(element);
        element.focus();
      };

      const cancel = () => {
        cleanup(originalValue, originalDisplay || placeholder);
      };

      const save = async () => {
        errorEl.textContent = '';
        const nextValue = input.value.trim();
        if (nextValue === '') {
          errorEl.textContent = 'Name cannot be empty.';
          input.focus();
          return;
        }
        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        try {
          await onSave(nextValue);
          cleanup(nextValue);
        } catch (err) {
          errorEl.textContent = err.message || err;
          saveBtn.disabled = false;
          cancelBtn.disabled = false;
          input.focus();
        }
      };

      saveBtn.addEventListener('click', save);
      cancelBtn.addEventListener('click', cancel);
      input.addEventListener('keydown', (evt) => {
        if (evt.key === 'Enter') {
          evt.preventDefault();
          save();
        } else if (evt.key === 'Escape') {
          evt.preventDefault();
          cancel();
        }
      });
      container.addEventListener('keydown', (evt) => {
        if (evt.key === 'Escape') {
          evt.preventDefault();
          cancel();
        }
      });
      container.addEventListener('focusout', (evt) => {
        if (!container.contains(evt.relatedTarget)) {
          cancel();
        }
      });
    }

    document.querySelectorAll('[data-edit-entity="passkey"]').forEach((el) => {
      makeInlineEditable(el, async (value) => {
        const id = el.getAttribute('data-id');
        if (!id) throw new Error('Missing passkey id.');
        const form = createFormData({
          csrf_token: csrfToken,
          passkey_id: id,
          name: value,
          ajax: '1'
        });
        await postJson('passkey_rename.php', form);
        const row = el.closest('tr');
        const deleteBtn = row ? row.querySelector('.btn-delete-passkey') : null;
        if (deleteBtn) deleteBtn.setAttribute('data-passkey-name', value);
      });
    });

    document.querySelectorAll('[data-edit-entity="trusted-device"]').forEach((el) => {
      makeInlineEditable(el, async (value) => {
        const id = el.getAttribute('data-id');
        if (!id) throw new Error('Missing device id.');
        const form = createFormData({
          csrf_token: csrfToken,
          action: 'rename',
          id,
          name: value
        });
        await postJson('trusted_devices_actions.php', form);
      });
    });

    const emailToggleBtn = document.getElementById('btnToggleEmail');
    if (emailToggleBtn) {
      emailToggleBtn.addEventListener('click', async () => {
        const state = emailToggleBtn.getAttribute('data-state');
        if (!state) return;
        const restore = setButtonLoading(emailToggleBtn, 'Processing...');
        try {
          const form = createFormData({ csrf_token: csrfToken, action: 'request', state });
          await postJson('twofa_email_actions.php', form);
          restore(true);
          openEmailModal(state, {
            onSuccess: () => {
              restore(false);
              location.reload();
            },
            onCancel: () => restore(false)
          });
        } catch (err) {
          restore(false);
          alert(err.message || err);
        }
      });
    }

    function openEmailModal(state, callbacks = {}) {
      let finished = false;
      openModal({
        title: state === 'enable' ? 'Enable Email Authentication' : 'Disable Email Authentication',
        onClose: () => {
          if (!finished && typeof callbacks.onCancel === 'function') callbacks.onCancel();
        },
        render: (body, controls) => {
          body.innerHTML = '';
          const intro = document.createElement('p');
          intro.textContent = 'Enter the 6-digit code sent to your email and confirm with your current password.';
          body.append(intro);
          const form = document.createElement('form');
          form.className = 'modal-form';

          const codeGroup = document.createElement('div');
          const codeLabel = document.createElement('label');
          codeLabel.setAttribute('for', 'emailCodeInput');
          codeLabel.textContent = 'Verification code';
          const codeInput = document.createElement('input');
          codeInput.id = 'emailCodeInput';
          codeInput.className = 'input';
          codeInput.name = 'code';
          codeInput.inputMode = 'numeric';
          codeInput.autocomplete = 'one-time-code';
          codeInput.maxLength = 6;
          codeInput.required = true;
          codeGroup.append(codeLabel, codeInput);

          const passGroup = document.createElement('div');
          const passLabel = document.createElement('label');
          passLabel.setAttribute('for', 'emailPasswordInput');
          passLabel.textContent = 'Current password';
          const passInput = document.createElement('input');
          passInput.id = 'emailPasswordInput';
          passInput.className = 'input';
          passInput.type = 'password';
          passInput.autocomplete = 'current-password';
          passInput.required = true;
          passGroup.append(passLabel, passInput);

          const errorEl = document.createElement('div');
          errorEl.className = 'modal-error';

          const actions = document.createElement('div');
          actions.className = 'modal-actions';
          const cancelBtn = document.createElement('button');
          cancelBtn.type = 'button';
          cancelBtn.className = 'btn small secondary';
          cancelBtn.textContent = 'Cancel';
          const submitBtn = document.createElement('button');
          submitBtn.type = 'submit';
          submitBtn.className = 'btn small';
          submitBtn.textContent = 'Confirm';
          actions.append(cancelBtn, submitBtn);

          form.append(codeGroup, passGroup, errorEl, actions);
          body.append(form);
          codeInput.focus();

          cancelBtn.addEventListener('click', () => controls.close());

          form.addEventListener('submit', async (evt) => {
            evt.preventDefault();
            errorEl.textContent = '';
            const code = codeInput.value.trim();
            const password = passInput.value;
            if (code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
              errorEl.textContent = 'Enter the 6-digit code.';
              codeInput.focus();
              return;
            }
            const restore = setButtonLoading(submitBtn, 'Verifying...');
            cancelBtn.disabled = true;
            try {
              const formData = createFormData({
                csrf_token: csrfToken,
                action: 'confirm',
                state,
                code,
                password
              });
              await postJson('twofa_email_actions.php', formData);
              finished = true;
              controls.close();
              if (typeof callbacks.onSuccess === 'function') callbacks.onSuccess();
            } catch (err) {
              errorEl.textContent = err.message || err;
              restore(false);
              cancelBtn.disabled = false;
            }
          });
        }
      });
    }

    const appToggleBtn = document.getElementById('btnToggleApp');
    if (appToggleBtn) {
      appToggleBtn.addEventListener('click', () => {
        const mode = appToggleBtn.getAttribute('data-mode');
        if (mode === 'enable') {
          startAppEnableFlow(appToggleBtn);
        } else if (mode === 'disable') {
          startAppDisableFlow(appToggleBtn);
        }
      });
    }

    async function startAppEnableFlow(button) {
      const restore = setButtonLoading(button, 'Processing...');
      try {
        const form = createFormData({ csrf_token: csrfToken, action: 'request_enable' });
        await postJson('twofa_app_actions.php', form);
        restore(true);
        openAuthenticatorEnableModal({
          onSuccess: () => {
            restore(false);
            location.reload();
          },
          onCancel: () => restore(false)
        });
      } catch (err) {
        restore(false);
        alert(err.message || err);
      }
    }

    function openAuthenticatorEnableModal(callbacks = {}) {
      let finished = false;
      let secretPayload = null;
      openModal({
        title: 'Enable Authenticator App',
        onClose: () => {
          if (!finished && typeof callbacks.onCancel === 'function') callbacks.onCancel();
        },
        render: (body, controls) => renderStep1(body, controls)
      });

      function renderStep1(body, controls) {
        body.innerHTML = '';
        const intro = document.createElement('p');
        intro.textContent = 'We emailed you a 6-digit code to verify this authenticator request.';
        body.append(intro);
        const form = document.createElement('form');
        form.className = 'modal-form';
        const group = document.createElement('div');
        const label = document.createElement('label');
        label.setAttribute('for', 'appVerifyCode');
        label.textContent = 'Verification code';
        const input = document.createElement('input');
        input.id = 'appVerifyCode';
        input.className = 'input';
        input.inputMode = 'numeric';
        input.autocomplete = 'one-time-code';
        input.maxLength = 6;
        input.required = true;
        group.append(label, input);
        const errorEl = document.createElement('div');
        errorEl.className = 'modal-error';
        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn small secondary';
        cancelBtn.textContent = 'Cancel';
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn small';
        submitBtn.textContent = 'Verify';
        actions.append(cancelBtn, submitBtn);
        form.append(group, errorEl, actions);
        body.append(form);
        input.focus();

        cancelBtn.addEventListener('click', () => controls.close());
        form.addEventListener('submit', async (evt) => {
          evt.preventDefault();
          errorEl.textContent = '';
          const code = input.value.trim();
          if (code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
            errorEl.textContent = 'Enter the 6-digit code.';
            input.focus();
            return;
          }
          const restore = setButtonLoading(submitBtn, 'Checking...');
          cancelBtn.disabled = true;
          try {
            const data = await postJson('twofa_app_actions.php', createFormData({
              csrf_token: csrfToken,
              action: 'verify_code',
              code
            }));
            secretPayload = data;
            renderStep2(body, controls);
          } catch (err) {
            errorEl.textContent = err.message || err;
            restore(false);
            cancelBtn.disabled = false;
          }
        });
      }

      function renderStep2(body, controls) {
        body.innerHTML = '';
        const info = document.createElement('p');
        info.textContent = 'Scan the QR code with your authenticator app or enter the secret manually.';
        const qrWrap = document.createElement('div');
        qrWrap.className = 'modal-qr';
        const qrFrame = document.createElement('div');
        qrFrame.className = 'qr-frame';
        const img = document.createElement('img');
        img.src = secretPayload.qr;
        img.alt = 'Authenticator QR code';
        img.width = 220;
        img.height = 220;
        qrFrame.append(img);
        const secretLabel = document.createElement('div');
        secretLabel.className = 'modal-secret';
        const segments = secretPayload.secret.match(/.{1,4}/g);
        secretLabel.textContent = segments ? segments.join(' ') : secretPayload.secret;
        qrWrap.append(qrFrame, secretLabel);

        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn small secondary';
        cancelBtn.textContent = 'Cancel';
        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'btn small';
        nextBtn.textContent = 'Next';
        actions.append(cancelBtn, nextBtn);

        body.append(info, qrWrap, actions);

        cancelBtn.addEventListener('click', () => controls.close());
        nextBtn.addEventListener('click', () => renderStep3(body, controls));
      }

      function renderStep3(body, controls) {
        body.innerHTML = '';
        const info = document.createElement('p');
        info.textContent = 'Enter a code from your authenticator app and your current password to finish.';
        const form = document.createElement('form');
        form.className = 'modal-form';

        const codeGroup = document.createElement('div');
        const codeLabel = document.createElement('label');
        codeLabel.setAttribute('for', 'appConfirmCode');
        codeLabel.textContent = 'Authenticator code';
        const codeInput = document.createElement('input');
        codeInput.id = 'appConfirmCode';
        codeInput.className = 'input';
        codeInput.inputMode = 'numeric';
        codeInput.maxLength = 6;
        codeInput.required = true;
        codeGroup.append(codeLabel, codeInput);

        const passGroup = document.createElement('div');
        const passLabel = document.createElement('label');
        passLabel.setAttribute('for', 'appConfirmPassword');
        passLabel.textContent = 'Current password';
        const passInput = document.createElement('input');
        passInput.id = 'appConfirmPassword';
        passInput.className = 'input';
        passInput.type = 'password';
        passInput.autocomplete = 'current-password';
        passInput.required = true;
        passGroup.append(passLabel, passInput);

        const errorEl = document.createElement('div');
        errorEl.className = 'modal-error';

        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn small secondary';
        cancelBtn.textContent = 'Cancel';
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn small';
        submitBtn.textContent = 'Enable';
        actions.append(cancelBtn, submitBtn);

        form.append(codeGroup, passGroup, errorEl, actions);
        body.append(info, form);
        codeInput.focus();

        cancelBtn.addEventListener('click', () => controls.close());
        form.addEventListener('submit', async (evt) => {
          evt.preventDefault();
          errorEl.textContent = '';
          const code = codeInput.value.trim();
          const password = passInput.value;
          if (code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
            errorEl.textContent = 'Enter the 6-digit code.';
            codeInput.focus();
            return;
          }
          const restore = setButtonLoading(submitBtn, 'Enabling...');
          cancelBtn.disabled = true;
          try {
            await postJson('twofa_app_actions.php', createFormData({
              csrf_token: csrfToken,
              action: 'confirm_enable',
              code,
              password
            }));
            finished = true;
            controls.close();
            if (typeof callbacks.onSuccess === 'function') callbacks.onSuccess();
          } catch (err) {
            errorEl.textContent = err.message || err;
            restore(false);
            cancelBtn.disabled = false;
          }
        });
      }
    }

    function startAppDisableFlow(button) {
      const restore = setButtonLoading(button, 'Processing...');
      restore(true);
      openAuthenticatorDisableModal({
        onSuccess: () => {
          restore(false);
          location.reload();
        },
        onCancel: () => restore(false)
      });
    }

    function openAuthenticatorDisableModal(callbacks = {}) {
      let finished = false;
      openModal({
        title: 'Disable Authenticator App',
        onClose: () => {
          if (!finished && typeof callbacks.onCancel === 'function') callbacks.onCancel();
        },
        render: (body, controls) => {
          body.innerHTML = '';
          const info = document.createElement('p');
          info.textContent = 'Enter a current authenticator code and your password to disable the authenticator app.';
          const form = document.createElement('form');
          form.className = 'modal-form';

          const codeGroup = document.createElement('div');
          const codeLabel = document.createElement('label');
          codeLabel.setAttribute('for', 'appDisableCode');
          codeLabel.textContent = 'Authenticator code';
          const codeInput = document.createElement('input');
          codeInput.id = 'appDisableCode';
          codeInput.className = 'input';
          codeInput.inputMode = 'numeric';
          codeInput.maxLength = 6;
          codeInput.required = true;
          codeGroup.append(codeLabel, codeInput);

          const passGroup = document.createElement('div');
          const passLabel = document.createElement('label');
          passLabel.setAttribute('for', 'appDisablePassword');
          passLabel.textContent = 'Current password';
          const passInput = document.createElement('input');
          passInput.id = 'appDisablePassword';
          passInput.className = 'input';
          passInput.type = 'password';
          passInput.autocomplete = 'current-password';
          passInput.required = true;
          passGroup.append(passLabel, passInput);

          const errorEl = document.createElement('div');
          errorEl.className = 'modal-error';
          const actions = document.createElement('div');
          actions.className = 'modal-actions';
          const cancelBtn = document.createElement('button');
          cancelBtn.type = 'button';
          cancelBtn.className = 'btn small secondary';
          cancelBtn.textContent = 'Cancel';
          const submitBtn = document.createElement('button');
          submitBtn.type = 'submit';
          submitBtn.className = 'btn small danger';
          submitBtn.textContent = 'Disable';
          actions.append(cancelBtn, submitBtn);

          form.append(codeGroup, passGroup, errorEl, actions);
          body.append(info, form);
          codeInput.focus();

          cancelBtn.addEventListener('click', () => controls.close());
          form.addEventListener('submit', async (evt) => {
            evt.preventDefault();
            errorEl.textContent = '';
            const code = codeInput.value.trim();
            const password = passInput.value;
            if (code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
              errorEl.textContent = 'Enter the 6-digit code.';
              codeInput.focus();
              return;
            }
            const restore = setButtonLoading(submitBtn, 'Disabling...');
            cancelBtn.disabled = true;
            try {
              await postJson('twofa_app_actions.php', createFormData({
                csrf_token: csrfToken,
                action: 'disable',
                code,
                password
              }));
              finished = true;
              controls.close();
              if (typeof callbacks.onSuccess === 'function') callbacks.onSuccess();
            } catch (err) {
              errorEl.textContent = err.message || err;
              restore(false);
              cancelBtn.disabled = false;
            }
          });
        }
      });
    }

    function hexToArrayBuffer(hex) {
      if (!hex) return new ArrayBuffer(0);
      const len = hex.length / 2;
      const arr = new Uint8Array(len);
      for (let i = 0; i < len; i += 1) {
        arr[i] = parseInt(hex.substr(i * 2, 2), 16);
      }
      return arr.buffer;
    }

    function bufferToBase64url(buffer) {
      const bytes = new Uint8Array(buffer);
      let str = '';
      for (let i = 0; i < bytes.byteLength; i += 1) {
        str += String.fromCharCode(bytes[i]);
      }
      return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    async function beginPasskey(name) {
      const data = await postJson('passkey_begin_register.php', createFormData({
        csrf_token: csrfToken,
        name
      }));
      return data.publicKey;
    }

    function prepareCredentialCreationOptions(pubKey) {
      const creationOptions = { ...pubKey };
      creationOptions.challenge = hexToArrayBuffer(pubKey.challengeHex);
      delete creationOptions.challengeHex;
      if (creationOptions.user && creationOptions.user.idHex) {
        creationOptions.user = { ...creationOptions.user, id: hexToArrayBuffer(creationOptions.user.idHex) };
        delete creationOptions.user.idHex;
      }
      return creationOptions;
    }

    async function completePasskeyRegistration(attestation, password) {
      const form = createFormData({
        csrf_token: csrfToken,
        clientDataJSON: attestation.clientDataJSON,
        attestationObject: attestation.attestationObject,
        password
      });
      await postJson('passkey_finish_register.php', form);
    }

    const addPasskeyBtn = document.getElementById('btnAddPasskey');
    if (addPasskeyBtn) {
      addPasskeyBtn.addEventListener('click', async () => {
        if (!window.PublicKeyCredential) {
          alert('This browser does not support passkeys.');
          return;
        }
        const restore = setButtonLoading(addPasskeyBtn, 'Processing...');
        try {
          await postJson('passkey_email_request.php', createFormData({ csrf_token: csrfToken }));
          restore(true);
          openPasskeyAddModal({
            onSuccess: () => {
              restore(false);
              location.reload();
            },
            onCancel: () => restore(false)
          });
        } catch (err) {
          restore(false);
          alert(err.message || err);
        }
      });
    }

    function openPasskeyAddModal(callbacks = {}) {
      let finished = false;
      let attestation = null;
      let passkeyName = 'My Passkey';
      openModal({
        title: 'Add Passkey',
        onClose: () => {
          if (!finished && typeof callbacks.onCancel === 'function') callbacks.onCancel();
        },
        render: (body, controls) => renderCodeStep(body, controls)
      });

      function renderCodeStep(body, controls) {
        body.innerHTML = '';
        const info = document.createElement('p');
        info.textContent = 'Enter the 6-digit code we emailed you to begin adding a new passkey.';
        body.append(info);
        const form = document.createElement('form');
        form.className = 'modal-form';
        const codeGroup = document.createElement('div');
        const codeLabel = document.createElement('label');
        codeLabel.setAttribute('for', 'passkeyEmailCode');
        codeLabel.textContent = 'Verification code';
        const codeInput = document.createElement('input');
        codeInput.id = 'passkeyEmailCode';
        codeInput.className = 'input';
        codeInput.inputMode = 'numeric';
        codeInput.autocomplete = 'one-time-code';
        codeInput.maxLength = 6;
        codeInput.required = true;
        codeGroup.append(codeLabel, codeInput);
        const errorEl = document.createElement('div');
        errorEl.className = 'modal-error';
        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn small secondary';
        cancelBtn.textContent = 'Cancel';
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn small';
        submitBtn.textContent = 'Continue';
        actions.append(cancelBtn, submitBtn);

        form.append(codeGroup, errorEl, actions);
        body.append(form);
        codeInput.focus();

        cancelBtn.addEventListener('click', () => controls.close());
        form.addEventListener('submit', async (evt) => {
          evt.preventDefault();
          errorEl.textContent = '';
          const code = codeInput.value.trim();
          if (code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
            errorEl.textContent = 'Enter the 6-digit code.';
            codeInput.focus();
            return;
          }
          const restore = setButtonLoading(submitBtn, 'Verifying...');
          cancelBtn.disabled = true;
          try {
            await postJson('passkey_email_verify.php', createFormData({
              csrf_token: csrfToken,
              code
            }));
            renderCreationStep(body, controls);
          } catch (err) {
            errorEl.textContent = err.message || err;
            restore(false);
            cancelBtn.disabled = false;
          }
        });
      }

      function renderCreationStep(body, controls) {
        body.innerHTML = '';
        const info = document.createElement('p');
        info.textContent = 'Name your passkey and complete the browser prompt to register it.';
        const form = document.createElement('form');
        form.className = 'modal-form';
        const nameGroup = document.createElement('div');
        const nameLabel = document.createElement('label');
        nameLabel.setAttribute('for', 'passkeyNameInput');
        nameLabel.textContent = 'Passkey name';
        const nameInput = document.createElement('input');
        nameInput.id = 'passkeyNameInput';
        nameInput.className = 'input';
        nameInput.maxLength = 100;
        nameInput.value = passkeyName;
        nameGroup.append(nameLabel, nameInput);
        const errorEl = document.createElement('div');
        errorEl.className = 'modal-error';
        const infoBox = document.createElement('div');
        infoBox.className = 'modal-info';
        infoBox.textContent = 'Your browser or device may prompt you to use biometrics or a device PIN to finish creating this passkey.';
        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn small secondary';
        cancelBtn.textContent = 'Cancel';
        const createBtn = document.createElement('button');
        createBtn.type = 'button';
        createBtn.className = 'btn small';
        createBtn.textContent = 'Create passkey';
        actions.append(cancelBtn, createBtn);
        form.append(nameGroup, errorEl, infoBox, actions);
        body.append(form);
        nameInput.focus();

        cancelBtn.addEventListener('click', () => controls.close());
        createBtn.addEventListener('click', async () => {
          errorEl.textContent = '';
          const chosenName = nameInput.value.trim() || 'My Passkey';
          const restore = setButtonLoading(createBtn, 'Waiting...');
          cancelBtn.disabled = true;
          nameInput.disabled = true;
          try {
            const options = await beginPasskey(chosenName);
            const publicKey = prepareCredentialCreationOptions(options);
            const credential = await navigator.credentials.create({ publicKey });
            if (!credential) throw new Error('Passkey creation was cancelled.');
            attestation = {
              clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
              attestationObject: bufferToBase64url(credential.response.attestationObject)
            };
            passkeyName = chosenName;
            renderPasswordStep(body, controls);
          } catch (err) {
            errorEl.textContent = err.message || err;
            restore(false);
            cancelBtn.disabled = false;
            nameInput.disabled = false;
          }
        });
      }

      function renderPasswordStep(body, controls) {
        body.innerHTML = '';
        const info = document.createElement('p');
        info.textContent = `Enter your current password to finish adding “${passkeyName}”.`;
        const form = document.createElement('form');
        form.className = 'modal-form';
        const passGroup = document.createElement('div');
        const passLabel = document.createElement('label');
        passLabel.setAttribute('for', 'passkeyPasswordInput');
        passLabel.textContent = 'Current password';
        const passInput = document.createElement('input');
        passInput.id = 'passkeyPasswordInput';
        passInput.className = 'input';
        passInput.type = 'password';
        passInput.autocomplete = 'current-password';
        passInput.required = true;
        passGroup.append(passLabel, passInput);
        const errorEl = document.createElement('div');
        errorEl.className = 'modal-error';
        const actions = document.createElement('div');
        actions.className = 'modal-actions';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn small secondary';
        cancelBtn.textContent = 'Cancel';
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn small';
        submitBtn.textContent = 'Finish';
        actions.append(cancelBtn, submitBtn);
        form.append(passGroup, errorEl, actions);
        body.append(info, form);
        passInput.focus();

        cancelBtn.addEventListener('click', () => controls.close());
        form.addEventListener('submit', async (evt) => {
          evt.preventDefault();
          errorEl.textContent = '';
          const password = passInput.value;
          if (password === '') {
            errorEl.textContent = 'Enter your password.';
            passInput.focus();
            return;
          }
          const restore = setButtonLoading(submitBtn, 'Saving...');
          cancelBtn.disabled = true;
          try {
            await completePasskeyRegistration(attestation, password);
            finished = true;
            controls.close();
            if (typeof callbacks.onSuccess === 'function') callbacks.onSuccess();
          } catch (err) {
            errorEl.textContent = err.message || err;
            restore(false);
            cancelBtn.disabled = false;
          }
        });
      }
    }

    document.querySelectorAll('.btn-delete-passkey').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-passkey-id');
        const name = btn.getAttribute('data-passkey-name') || 'this passkey';
        if (!id) return;
        openPasskeyDeleteModal(id, name);
      });
    });

    function openPasskeyDeleteModal(id, name) {
      openModal({
        title: 'Delete Passkey',
        onClose: () => {},
        render: (body, controls) => {
          body.innerHTML = '';
          const info = document.createElement('p');
          info.textContent = `Enter your current password to delete “${name}”. We'll send a confirmation email when it's removed.`;
          const form = document.createElement('form');
          form.className = 'modal-form';
          const passGroup = document.createElement('div');
          const passLabel = document.createElement('label');
          passLabel.setAttribute('for', 'deletePasskeyPassword');
          passLabel.textContent = 'Current password';
          const passInput = document.createElement('input');
          passInput.id = 'deletePasskeyPassword';
          passInput.className = 'input';
          passInput.type = 'password';
          passInput.autocomplete = 'current-password';
          passInput.required = true;
          passGroup.append(passLabel, passInput);
          const errorEl = document.createElement('div');
          errorEl.className = 'modal-error';
          const actions = document.createElement('div');
          actions.className = 'modal-actions';
          const cancelBtn = document.createElement('button');
          cancelBtn.type = 'button';
          cancelBtn.className = 'btn small secondary';
          cancelBtn.textContent = 'Cancel';
          const submitBtn = document.createElement('button');
          submitBtn.type = 'submit';
          submitBtn.className = 'btn small danger';
          submitBtn.textContent = 'Delete';
          actions.append(cancelBtn, submitBtn);
          form.append(passGroup, errorEl, actions);
          body.append(info, form);
          passInput.focus();

          cancelBtn.addEventListener('click', () => controls.close());
          form.addEventListener('submit', async (evt) => {
            evt.preventDefault();
            errorEl.textContent = '';
            const password = passInput.value;
            if (password === '') {
              errorEl.textContent = 'Enter your password.';
              passInput.focus();
              return;
            }
            const restore = setButtonLoading(submitBtn, 'Deleting...');
            cancelBtn.disabled = true;
            try {
              await postJson('passkey_delete.php', createFormData({
                csrf_token: csrfToken,
                passkey_id: id,
                password,
                ajax: '1'
              }));
              controls.close();
              location.reload();
            } catch (err) {
              errorEl.textContent = err.message || err;
              restore(false);
              cancelBtn.disabled = false;
            }
          });
        }
      });
    }
  </script>
</body>
</html>
