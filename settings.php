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
require_once __DIR__ . '/helpers.php';

$demoHelperPaths = [
    __DIR__ . '/ppf_demo_bootstrap.php',
    __DIR__ . '/ppf_demo.php',
    __DIR__ . '/demo_mode.php',
];
foreach ($demoHelperPaths as $demoHelperPath) {
    if (is_file($demoHelperPath)) {
        require_once $demoHelperPath;
        break;
    }
}

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$uid   = (int)($_SESSION['user_id'] ?? 0);
$email = (string)($_SESSION['email'] ?? '');
$role  = (string)($_SESSION['role'] ?? 'client');

if ($uid <= 0) { header('Location: login.php'); exit; }

$roleLower     = ppf_role_key($role);
$isAdmin       = ppf_is_admin_role($role);
$isSuperAdmin  = ppf_is_super_admin($role);

ppf_ensure_twofa_columns($conn);
ppf_td_ensure_table($conn);
ppf_seed_lockout_defaults($conn);
ppf_theme_ensure_column($conn);
ppf_time_ensure_columns($conn);

$demoPrimaryConn = null;
$demoModeEnabled = false;
$demoModeControlsAvailable = false;
$demoModeStatusError = null;
$demoSandboxCfg = $GLOBALS['demoSandboxCfg'] ?? null;

if (function_exists('ppf_demo_primary_conn')) {
    try {
        $maybePrimary = ppf_demo_primary_conn();
        if ($maybePrimary instanceof mysqli) {
            $demoPrimaryConn = $maybePrimary;
        } else {
            $demoModeStatusError = 'Unable to connect to the primary database for Demo Mode.';
        }
    } catch (Throwable $e) {
        $demoModeStatusError = $e->getMessage();
    }
} else {
    $demoModeStatusError = 'Demo Mode helpers are unavailable.';
}

if ($demoPrimaryConn instanceof mysqli) {
    try {
        ensure_system_settings_table($demoPrimaryConn);
        if (function_exists('ppf_demo_get_enabled')) {
            $demoModeEnabled = (bool)ppf_demo_get_enabled($demoPrimaryConn);
        } elseif (function_exists('ppf_demo_is_enabled')) {
            $demoModeEnabled = (bool)ppf_demo_is_enabled();
        } else {
            $demoModeEnabled = ss_get($demoPrimaryConn, 'demo_mode_enabled', '0') === '1';
        }
        $demoModeControlsAvailable = true;
        $demoModeStatusError = null;
    } catch (Throwable $e) {
        $demoModeControlsAvailable = false;
        $demoModeStatusError = $e->getMessage();
    }
}

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

function ppf_fetch_user_credentials(mysqli $conn, int $uid): ?array {
    if ($uid <= 0) return null;
    $sql = "SELECT id, email, first_name, last_name, role, password_hash, twofa_secret, twofa_app_enabled FROM users WHERE id=? LIMIT 1";
    if (!$st = $conn->prepare($sql)) {
        return null;
    }
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function ppf_collect_admin_recipients(mysqli $conn): array {
    $recipients = [];
    if (!table_exists($conn, 'users')) {
        return $recipients;
    }
    $sql = "SELECT DISTINCT email, first_name, last_name FROM users WHERE email <> '' AND LOWER(role) IN ('admin','super_admin')";
    if ($st = $conn->prepare($sql)) {
        $st->execute();
        $res = $st->get_result();
        while ($row = $res ? $res->fetch_assoc() : null) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email === '') continue;
            $key = strtolower($email);
            if (isset($recipients[$key])) continue;
            $first = trim((string)($row['first_name'] ?? ''));
            $last  = trim((string)($row['last_name'] ?? ''));
            $name  = trim($first . ' ' . $last);
            if ($name === '') {
                $name = $email;
            }
            $recipients[$key] = ['email' => $email, 'name' => $name];
        }
        $st->close();
    }
    return array_values($recipients);
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

        case 'save_formatting': {
            $timezoneInput = (string)($_POST['timezone'] ?? '');
            $normalizedTz = ppf_time_normalize_timezone($timezoneInput);
            if ($normalizedTz === null) {
                redirect_with_flash('error', 'Select a valid time zone.', 'formatting');
            }

            $modeInput = strtolower(trim((string)($_POST['time_mode'] ?? '')));
            if (!in_array($modeInput, ['12', '24'], true)) {
                redirect_with_flash('error', 'Choose a valid time format.', 'formatting');
            }
            $use24h = $modeInput === '24';

            if ($st = $conn->prepare("UPDATE users SET timezone=?, time_format_24h=? WHERE id=?")) {
                $timeFormat = $use24h ? 1 : 0;
                $st->bind_param('sii', $normalizedTz, $timeFormat, $uid);
                $st->execute();
                $st->close();
            } else {
                redirect_with_flash('error', 'We couldn\'t save your formatting preferences. Please try again.', 'formatting');
            }

            $_SESSION['user_timezone'] = $normalizedTz;
            $_SESSION['timezone']      = $normalizedTz;
            $_SESSION['user_time_24h'] = $use24h ? 1 : 0;
            $_SESSION['time_format_24h'] = $_SESSION['user_time_24h'];

            if (function_exists('ppf_log')) {
                $details = json_encode([
                    'timezone' => $normalizedTz,
                    'time_format_24h' => $use24h ? '1' : '0',
                ]);
                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'time_preferences_updated', 'user', (string)$uid, $details);
            }

            redirect_with_flash('success', 'Formatting preferences saved.', 'formatting');
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

            $statusType = 'success';
            $messageParts = [];
            $demoAjaxRequested = false;
            $ajaxHeader = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
            if ($ajaxHeader === 'xmlhttprequest') {
                $demoAjaxRequested = true;
            } elseif (isset($_POST['demo_ajax']) && $_POST['demo_ajax'] === '1') {
                $demoAjaxRequested = true;
            } elseif (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                $demoAjaxRequested = true;
            }

            $demoCurrentPassword = (string)($_POST['demo_current_password'] ?? '');
            $demoTotpCode = preg_replace('/\D/', '', (string)($_POST['demo_totp_code'] ?? ''));
            $cachedAuthRow = null;
            $getAuthRow = function () use (&$cachedAuthRow, $conn, $uid) {
                if ($cachedAuthRow === null) {
                    $cachedAuthRow = ppf_fetch_user_credentials($conn, $uid);
                }
                return $cachedAuthRow;
            };

            $demoToggleSubmitted = isset($_POST['demo_mode_enabled_present']);
            $desiredDemoEnabled = ($_POST['demo_mode_enabled'] ?? '') === '1';
            $demoToggleErrorMsg = '';
            $demoToggleSuccess = true;
            if ($demoToggleSubmitted && $desiredDemoEnabled !== $demoModeEnabled) {
                $demoToggleSuccess = false;
                if ($demoCurrentPassword === '') {
                    $demoToggleErrorMsg = 'Enter your current password to toggle Demo Mode.';
                } elseif (strlen($demoTotpCode) !== 6) {
                    $demoToggleErrorMsg = 'Enter the 6-digit authenticator code to toggle Demo Mode.';
                } else {
                    $authRow = $getAuthRow();
                    if (!$authRow || empty($authRow['password_hash'])) {
                        $demoToggleErrorMsg = 'Unable to verify your credentials. Please sign in again.';
                    } elseif (!password_verify($demoCurrentPassword, (string)$authRow['password_hash'])) {
                        $demoToggleErrorMsg = 'Incorrect current password.';
                    } else {
                        $secret = strtoupper(preg_replace('/\s+/', '', (string)($authRow['twofa_secret'] ?? '')));
                        $appEnabled = (int)($authRow['twofa_app_enabled'] ?? 0) === 1;
                        if (!$appEnabled || $secret === '') {
                            $demoToggleErrorMsg = 'Authenticator app must be enabled before toggling Demo Mode.';
                        } elseif (!ppf_totp_verify($secret, $demoTotpCode, 30, 6, 1)) {
                            $demoToggleErrorMsg = 'Authenticator code was not recognized.';
                        } elseif ($demoPrimaryConn instanceof mysqli) {
                            try {
                                if (function_exists('ppf_demo_set_enabled')) {
                                    $cfgOverride = is_array($demoSandboxCfg) ? $demoSandboxCfg : null;
                                    $result = ppf_demo_set_enabled($demoPrimaryConn, $desiredDemoEnabled, $cfgOverride);
                                    $demoToggleSuccess = ($result !== false);
                                    if (!$demoToggleSuccess && $demoToggleErrorMsg === '' && function_exists('ppf_demo_last_error')) {
                                        $err = (string)(ppf_demo_last_error() ?? '');
                                        if ($err !== '') {
                                            $demoToggleErrorMsg = $err;
                                        }
                                    }
                                } else {
                                    ensure_system_settings_table($demoPrimaryConn);
                                    $demoToggleSuccess = ss_set($demoPrimaryConn, 'demo_mode_enabled', $desiredDemoEnabled ? '1' : '0');
                                }
                            } catch (Throwable $e) {
                                $demoToggleErrorMsg = $e->getMessage();
                                $demoToggleSuccess = false;
                            }
                        } else {
                            $demoToggleErrorMsg = $demoModeStatusError ?: 'Demo Mode connection unavailable.';
                        }
                    }
                }

                if ($demoToggleSuccess) {
                    $demoModeEnabled = $desiredDemoEnabled;
                    $messageParts[] = $desiredDemoEnabled ? 'Demo Mode enabled.' : 'Demo Mode disabled.';
                    if (function_exists('ppf_log')) {
                        $action = $desiredDemoEnabled ? 'demo_mode_enabled' : 'demo_mode_disabled';
                        $details = $desiredDemoEnabled ? 'Demo Mode toggled on via settings.' : 'Demo Mode toggled off via settings.';
                        ppf_log($conn, $uid, $email ?: null, $role ?: null, $action, 'system', 'demo_mode', $details);
                    }
                    $notifyConn = $demoPrimaryConn instanceof mysqli ? $demoPrimaryConn : $conn;
                    $recipients = $notifyConn instanceof mysqli ? ppf_collect_admin_recipients($notifyConn) : [];
                    $stateWord = $desiredDemoEnabled ? 'enabled' : 'disabled';
                    $actorName = '';
                    $authRow = $getAuthRow();
                    if ($authRow) {
                        $first = trim((string)($authRow['first_name'] ?? ''));
                        $last  = trim((string)($authRow['last_name'] ?? ''));
                        $actorName = trim($first . ' ' . $last);
                    }
                    if ($actorName === '') {
                        $actorName = $email !== '' ? $email : ('Admin #' . $uid);
                    }
                    $timestamp = date('Y-m-d H:i:s T');
                    $ipAddress = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
                    $subject = 'Demo Mode ' . ucfirst($stateWord);
                    $bodyLines = [
                        'Hello Admin,',
                        '',
                        'Demo Mode was ' . $stateWord . ' on ' . $timestamp . ' by ' . $actorName . '.',
                    ];
                    if ($email !== '') {
                        $bodyLines[] = 'Account email: ' . $email;
                    }
                    if ($ipAddress !== '') {
                        $bodyLines[] = 'Originating IP: ' . $ipAddress;
                    }
                    $bodyLines[] = '';
                    $bodyLines[] = 'Review the System Logs for additional context if this was unexpected.';
                    $bodyLines[] = '';
                    $bodyLines[] = '— Peter Pang Fit';
                    $emailBody = implode("\n", $bodyLines);
                    $sentCount = 0;
                    $failedEmails = [];
                    if ($recipients) {
                        foreach ($recipients as $recipient) {
                            $ok = @send_plain_email($recipient['email'], $recipient['name'], $subject, $emailBody);
                            if ($ok) {
                                $sentCount++;
                            } else {
                                $failedEmails[] = $recipient['email'];
                            }
                        }
                        if ($sentCount > 0 && function_exists('ppf_log')) {
                            $details = 'Sent ' . $sentCount . ' Demo Mode ' . $stateWord . ' notification email(s).';
                            ppf_log($conn, $uid, $email ?: null, $role ?: null, 'demo_mode_notification_sent', 'system', 'demo_mode', $details);
                        }
                        if ($failedEmails) {
                            $statusType = 'error';
                            $failureList = implode(', ', $failedEmails);
                            $messageParts[] = 'Some admin notifications could not be delivered: ' . $failureList . '.';
                            if (function_exists('ppf_log')) {
                                $details = 'Failed to email: ' . $failureList;
                                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'demo_mode_notification_failed', 'system', 'demo_mode', $details);
                            }
                        }
                    } else {
                        $messageParts[] = 'No admin notification emails were sent because no admin email addresses were found.';
                        if (function_exists('ppf_log')) {
                            ppf_log($conn, $uid, $email ?: null, $role ?: null, 'demo_mode_notification_skipped', 'system', 'demo_mode', 'No admin recipients available.');
                        }
                    }
                } else {
                    $statusType = 'error';
                    $msg = 'Failed to ' . ($desiredDemoEnabled ? 'enable' : 'disable') . ' Demo Mode';
                    if ($demoToggleErrorMsg !== '') {
                        $msg .= ': ' . $demoToggleErrorMsg;
                    }
                    if (!preg_match('/[.!?]$/', $msg)) {
                        $msg .= '.';
                    }
                    $messageParts[] = $msg;
                    if (function_exists('ppf_log')) {
                        $details = $demoToggleErrorMsg !== '' ? $demoToggleErrorMsg : 'Unknown error toggling Demo Mode.';
                        ppf_log($conn, $uid, $email ?: null, $role ?: null, 'demo_mode_toggle_failed', 'system', 'demo_mode', $details);
                    }
                }
            }

            $demoResetRequested = isset($_POST['demo_reset']) && $_POST['demo_reset'] === '1';
            if ($demoResetRequested) {
                $demoResetSuccess = false;
                $demoResetErrorMsg = '';
                $demoResetMessages = [];
                $demoResetLogged = false;
                if ($demoCurrentPassword === '') {
                    $demoResetErrorMsg = 'Enter your current password to reset the demo data.';
                } else {
                    $authRow = $getAuthRow();
                    if (!$authRow || empty($authRow['password_hash'])) {
                        $demoResetErrorMsg = 'Unable to verify your credentials. Please sign in again.';
                    } elseif (!password_verify($demoCurrentPassword, (string)$authRow['password_hash'])) {
                        $demoResetErrorMsg = 'Incorrect current password.';
                    }
                }
                if ($demoResetErrorMsg === '') {
                    if ($demoPrimaryConn instanceof mysqli) {
                        try {
                            if (function_exists('ppf_demo_reset')) {
                                $result = ppf_demo_reset($demoPrimaryConn);
                                if (is_array($result)) {
                                    $demoResetSuccess = !empty($result['success']);
                                    $demoResetMessages = array_map('trim', (array)($result['messages'] ?? []));
                                    $errors = array_map('trim', (array)($result['errors'] ?? []));
                                    $errors = array_filter($errors, static function ($val) { return $val !== ''; });
                                    if ($errors) {
                                        $demoResetErrorMsg = trim(implode(' ', $errors));
                                    }
                                    $demoResetLogged = !empty($result['logged']);
                                } else {
                                    $demoResetSuccess = ($result !== false);
                                }
                            } else {
                                $demoResetSuccess = false;
                                $demoResetErrorMsg = 'Demo reset helper is unavailable.';
                            }
                        } catch (Throwable $e) {
                            $demoResetSuccess = false;
                            $demoResetErrorMsg = $e->getMessage();
                        }
                    } else {
                        $demoResetErrorMsg = $demoModeStatusError ?: 'Demo Mode connection unavailable.';
                    }
                }

                if ($demoResetSuccess) {
                    $messageParts[] = 'Demo data reset.';
                    if ($demoResetMessages) {
                        $messageParts = array_merge($messageParts, array_filter($demoResetMessages));
                    }
                    if (!$demoResetLogged && function_exists('ppf_log')) {
                        ppf_log($conn, $uid, $email ?: null, $role ?: null, 'demo_mode_reset', 'system', 'demo_mode', 'Demo data reset via settings.');
                    }
                } else {
                    $statusType = 'error';
                    $msg = 'Demo data reset failed';
                    if ($demoResetErrorMsg !== '') {
                        $msg .= ': ' . $demoResetErrorMsg;
                    }
                    if (!preg_match('/[.!?]$/', $msg)) {
                        $msg .= '.';
                    }
                    $messageParts[] = $msg;
                    if (!$demoResetLogged && function_exists('ppf_log')) {
                        $details = $demoResetErrorMsg !== '' ? $demoResetErrorMsg : 'Unknown error resetting Demo Mode data.';
                        ppf_log($conn, $uid, $email ?: null, $role ?: null, 'demo_mode_reset_failed', 'system', 'demo_mode', $details);
                    }
                }
            }

            if (function_exists('ppf_log')) {
                ppf_log($conn, $uid, $email ?: null, $role ?: null, 'system_settings_updated', 'admin', (string)$uid, null);
            }
            $baseMessage = $statusType === 'success'
                ? 'System settings saved.'
                : 'System settings saved, but some actions failed.';
            array_unshift($messageParts, $baseMessage);
            $messages = array_values(array_filter($messageParts));
            $message = trim(implode(' ', $messages));
            if ($message === '') {
                $message = $baseMessage;
                $messages = [$baseMessage];
            }

            if ($demoAjaxRequested) {
                $enabledState = $demoModeEnabled;
                if (function_exists('ppf_demo_get_enabled')) {
                    try {
                        $enabledState = (bool)ppf_demo_get_enabled($demoPrimaryConn instanceof mysqli ? $demoPrimaryConn : $conn);
                    } catch (Throwable $e) {
                        // ignore and fall back to computed state
                    }
                }

                $payload = [
                    'success'  => ($statusType === 'success'),
                    'status'   => $statusType,
                    'message'  => $message,
                    'messages' => $messages,
                    'enabled'  => (bool)$enabledState,
                    'redirect' => 'settings.php#system',
                ];

                if ($statusType !== 'success') {
                    $payload['errors'] = $messages;
                }

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload);
                exit;
            }

            redirect_with_flash($statusType, $message, 'system');
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
if ($st = $conn->prepare("SELECT email, first_name, last_name, role, theme, timezone, time_format_24h, twofa_email_enabled, twofa_app_enabled, twofa_secret FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();
    $userRow = $res ? $res->fetch_assoc() : null;
    $st->close();
}

$twofaEmailEnabled = (int)($userRow['twofa_email_enabled'] ?? 0) === 1;
$twofaAppEnabled   = (int)($userRow['twofa_app_enabled'] ?? 0) === 1;
$twofaSecret       = strtoupper(preg_replace('/\s+/', '', (string)($userRow['twofa_secret'] ?? '')));

$formattingTimezone = ppf_time_normalize_timezone($userRow['timezone'] ?? ($_SESSION['user_timezone'] ?? null)) ?? ppf_time_default_timezone();
$formattingUse24h   = (int)($userRow['time_format_24h'] ?? ($_SESSION['user_time_24h'] ?? 0)) === 1;
$_SESSION['user_timezone'] = $formattingTimezone;
$_SESSION['timezone']      = $formattingTimezone;
$_SESSION['user_time_24h'] = $formattingUse24h ? 1 : 0;
$_SESSION['time_format_24h'] = $_SESSION['user_time_24h'];
$formattingPreviewDate = ppf_format_user_datetime(ppf_time_user_now(), ['type' => 'date_long']);
$formattingPreviewTime = ppf_format_user_datetime(ppf_time_user_now(), ['type' => 'time']);

$themeCatalog     = ppf_theme_catalog();
$currentThemeKey  = ppf_theme_sanitize_key((string)($userRow['theme'] ?? ($_SESSION['theme'] ?? '')));
if (!ppf_theme_exists($currentThemeKey)) {
    $currentThemeKey = ppf_theme_default_key();
}
$_SESSION['theme'] = $currentThemeKey;
$themeGroups      = ppf_theme_grouped_catalog();

$timezoneOptions = [];
try {
    $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tzId) {
        try {
            $tzObj = new DateTimeZone($tzId);
        } catch (Throwable $e) {
            continue;
        }
        $offset = $tzObj->getOffset($utcNow);
        $hours = intdiv($offset, 3600);
        $minutes = abs(intdiv($offset % 3600, 60));
        $sign = $offset >= 0 ? '+' : '-';
        $label = sprintf('(UTC%s%02d:%02d) %s', $sign, abs($hours), $minutes, str_replace('_', ' ', $tzId));
        $timezoneOptions[] = ['id' => $tzId, 'label' => $label, 'offset' => $offset];
    }
    usort($timezoneOptions, function (array $a, array $b): int {
        if ($a['offset'] === $b['offset']) {
            return strcmp($a['id'], $b['id']);
        }
        return $a['offset'] <=> $b['offset'];
    });
    $hasCurrentTz = false;
    foreach ($timezoneOptions as $opt) {
        if ($opt['id'] === $formattingTimezone) {
            $hasCurrentTz = true;
            break;
        }
    }
    if (!$hasCurrentTz && $formattingTimezone) {
        $timezoneOptions[] = [
            'id' => $formattingTimezone,
            'label' => sprintf('(UTC) %s', str_replace('_', ' ', $formattingTimezone)),
            'offset' => 0,
        ];
    }
    usort($timezoneOptions, function (array $a, array $b): int {
        if ($a['offset'] === $b['offset']) {
            return strcmp($a['id'], $b['id']);
        }
        return $a['offset'] <=> $b['offset'];
    });
} catch (Throwable $e) {
    $timezoneOptions = [];
}

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
    return ppf_format_user_datetime($ts, ['type' => 'date']);
}

function fmt_datetime(?int $ts): string {
    if (!$ts) return '—';
    return ppf_format_user_datetime($ts, ['fallback' => '—']);
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

$demoModeStatusClass = 'is-error';
$demoModeStatusLabel = 'Unavailable';
$demoModeStatusMessage = '';
if ($demoModeControlsAvailable) {
    $demoModeStatusClass = $demoModeEnabled ? 'is-on' : 'is-off';
    $demoModeStatusLabel = $demoModeEnabled ? 'Enabled' : 'Disabled';
} else {
    if ($demoModeStatusError) {
        $demoModeStatusMessage = $demoModeStatusError;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings · Peter Pang Fit</title>
  <style>
    
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
      background: var(--page-canvas);
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
      padding: 0;
      z-index: 2200;
    }

    .settings-subheader::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.62));
      backdrop-filter: blur(20px);
      z-index: -2;
    }

    .settings-subheader::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      bottom: -18px;
      height: 18px;
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.55), transparent 85%);
      z-index: -3;
      pointer-events: none;
    }

    .settings-subheader .subheader-frame {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 32px;
      padding: 16px 24px;
      margin: 0 -24px;
      border-bottom: 1px solid rgba(148, 163, 184, 0.16);
      background:
        linear-gradient(135deg, rgba(56, 189, 248, 0.14), rgba(56, 189, 248, 0) 45%),
        linear-gradient(315deg, rgba(110, 231, 183, 0.12), rgba(110, 231, 183, 0) 45%),
        rgba(15, 23, 42, 0.72);
      box-shadow: 0 18px 40px rgba(2, 6, 23, 0.45);
      border-radius: 22px;
    }

    .settings-subheader .subheader-frame::after {
      content: "";
      position: absolute;
      inset: 1px;
      border-radius: 20px;
      border: 1px solid rgba(148, 163, 184, 0.14);
      pointer-events: none;
    }

    .settings-subheader .subheader-meta {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 180px;
    }

    .settings-subheader .subheader-eyebrow {
      font-size: .75rem;
      letter-spacing: .22em;
      text-transform: uppercase;
      color: rgba(226, 232, 240, 0.62);
    }

    .settings-subheader .subheader-copy {
      margin: 0;
      color: var(--muted);
      font-size: .92rem;
      max-width: 420px;
    }

    .settings-tabs {
      display: inline-flex;
      flex-wrap: wrap;
      gap: 14px;
      align-items: center;
      padding: 6px;
      border-radius: 999px;
      background: rgba(15, 23, 42, 0.75);
      border: 1px solid rgba(148, 163, 184, 0.18);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
    }

    .settings-tab {
      appearance: none;
      border: 1px solid transparent;
      background: rgba(15, 23, 42, 0.4);
      color: var(--muted);
      padding: 10px 20px;
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
      background:
        linear-gradient(135deg, rgba(56, 189, 248, 0.25), rgba(37, 99, 235, 0.25)),
        rgba(15, 23, 42, 0.8);
      color: var(--text);
      border-color: rgba(56, 189, 248, 0.45);
      box-shadow: 0 16px 44px rgba(2, 6, 23, 0.55);
      transform: translateY(-1px);
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

    .formatting-option {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(15, 23, 42, 0.45);
      margin-bottom: 10px;
      cursor: pointer;
      transition: border-color .2s ease, background .2s ease, transform .2s ease;
    }
    .formatting-option input {
      width: 18px;
      height: 18px;
      accent-color: var(--accent, #38bdf8);
    }
    .formatting-option span {
      flex: 1;
      font-size: .92rem;
      color: var(--text);
    }
    .formatting-option:hover {
      border-color: color-mix(in srgb, var(--border) 70%, var(--accent) 30%);
      background: rgba(15, 23, 42, 0.6);
      transform: translateY(-1px);
    }

    .formatting-preview {
      margin-top: 12px;
      padding: 14px 16px;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: rgba(12, 19, 35, 0.55);
      display: flex;
      flex-direction: column;
      gap: 6px;
      font-size: .95rem;
      color: var(--muted-soft);
    }
    .formatting-preview strong {
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: .04em;
      color: var(--text);
    }

    .demo-mode-content {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 16px 18px;
      background: rgba(15, 23, 42, 0.35);
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .system-side-panel {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 18px;
      align-items: start;
    }

    .demo-mode-status {
      display: inline-flex;
      align-items: center;
      padding: 4px 12px;
      border-radius: 999px;
      font-size: .75rem;
      font-weight: 600;
      letter-spacing: .02em;
      text-transform: uppercase;
      background: rgba(148, 163, 184, 0.18);
      color: #e2e8f0;
    }

    .demo-mode-status.is-on {
      background: rgba(52, 211, 153, 0.2);
      color: #bbf7d0;
    }

    .demo-mode-status.is-off {
      background: rgba(148, 163, 184, 0.2);
      color: #e2e8f0;
    }

    .demo-mode-status.is-error {
      background: rgba(248, 113, 113, 0.18);
      color: #fecaca;
    }

    .demo-mode-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    .demo-mode-credentials {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 12px;
    }

    .demo-mode-warning {
      color: #fca5a5;
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
      .settings-subheader { top: 60px; }
      .settings-subheader .subheader-frame { flex-direction: column; align-items: flex-start; padding: 16px 18px; margin: 0 -16px; gap: 18px; }
      .settings-subheader .subheader-copy { max-width: none; }
      .settings-tabs { gap: 8px; justify-content: flex-start; width: 100%; }
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
      <div class="subheader-frame">
        <div class="subheader-meta">
          <span class="subheader-eyebrow">Control Center</span>
          <p class="subheader-copy">Glide between security tools, visual themes, and (if you have the keys) system levers.</p>
        </div>
        <nav class="settings-tabs" role="tablist">
          <button class="settings-tab is-active" type="button" id="tab-security" role="tab" aria-selected="true" aria-controls="settings-security" data-tab="security">Security</button>
          <button class="settings-tab" type="button" id="tab-appearance" role="tab" aria-selected="false" aria-controls="settings-appearance" data-tab="appearance" tabindex="-1">Appearance</button>
<?php if ($isAdmin): ?>
          <button class="settings-tab" type="button" id="tab-system" role="tab" aria-selected="false" aria-controls="settings-system" data-tab="system" tabindex="-1">System</button>
<?php endif; ?>
        </nav>
      </div>
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
      <section class="card" id="formatting">
        <div class="section-title">
          <div>
            <h2>Formatting</h2>
            <p class="muted">Control your personal time zone and clock style across Peter Pang Fit.</p>
          </div>
        </div>

        <form method="post" class="two-col" style="margin-top:18px;">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_formatting">

          <div>
            <label class="small-text" for="user_timezone">Time zone</label>
            <select class="input" id="user_timezone" name="timezone">
              <?php foreach ($timezoneOptions as $tzOpt): ?>
                <option value="<?php echo h($tzOpt['id']); ?>"<?php if ($tzOpt['id'] === $formattingTimezone) echo ' selected'; ?>><?php echo h($tzOpt['label']); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="small-text muted" style="margin-top:8px;">Daylight saving time shifts are applied automatically.</p>
          </div>

          <div>
            <h3>Clock style</h3>
            <label class="formatting-option">
              <input type="radio" name="time_mode" value="12"<?php if (!$formattingUse24h) echo ' checked'; ?>>
              <span>12-hour (e.g., <?php echo h(ppf_format_user_datetime(ppf_time_user_now(), ['type' => 'time', 'format' => 'g:i A'])); ?>)</span>
            </label>
            <label class="formatting-option">
              <input type="radio" name="time_mode" value="24"<?php if ($formattingUse24h) echo ' checked'; ?>>
              <span>24-hour (e.g., <?php echo h(ppf_format_user_datetime(ppf_time_user_now(), ['type' => 'time', 'format' => 'H:i'])); ?>)</span>
            </label>
            <div class="formatting-preview">
              <strong><?php echo h($formattingPreviewDate); ?></strong>
              <div><?php echo h($formattingPreviewTime); ?> · <?php echo h(str_replace('_', ' ', $formattingTimezone)); ?></div>
            </div>
            <div style="margin-top:16px;">
              <button class="btn" type="submit">Save formatting</button>
            </div>
          </div>
        </form>
      </section>

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
        <div class="section-title">
          <div>
            <h2>System Settings</h2>
            <p class="muted">Customize lockout durations and maintain the registration test token.</p>
          </div>
        </div>

        <form method="post" class="two-col" style="margin-top:18px;" data-demo-mode-form="1">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="system_settings">
          <input type="hidden" name="demo_mode_enabled_present" value="1">
          <input type="hidden" name="demo_mode_enabled" id="demoModeEnabledInput" value="<?php echo $demoModeEnabled ? '1' : '0'; ?>">

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

          <div class="system-side-panel">
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

          </div>

          <div style="grid-column:1 / -1; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
            <button class="btn" type="submit">Save system settings</button>
          </div>
        </form>
      </section>

      <?php if ($isSuperAdmin): ?>
      <section class="card" id="demo-mode" data-demo-mode-card="1">
        <div class="section-title">
          <div>
            <h2>Demo Mode</h2>
            <p class="muted">Toggle the sanitized training environment for walkthroughs without touching live data.</p>
          </div>
          <span class="demo-mode-status <?php echo h($demoModeStatusClass); ?>">Demo Mode: <?php echo h($demoModeStatusLabel); ?></span>
        </div>

        <div class="demo-mode-content">
          <?php if (!$twofaAppEnabled): ?>
            <p class="demo-mode-warning small-text">Enable your authenticator app before attempting to toggle Demo Mode.</p>
          <?php endif; ?>
          <?php if ($demoModeStatusMessage): ?>
            <p class="demo-mode-warning small-text"><?php echo h($demoModeStatusMessage); ?></p>
          <?php endif; ?>
          <div class="demo-mode-actions">
            <?php if ($demoModeEnabled): ?>
            <button class="btn secondary" type="button" data-demo-action="disable" <?php echo !$demoModeControlsAvailable ? 'disabled' : ''; ?>>Disable Demo Mode</button>
            <?php else: ?>
            <button class="btn" type="button" data-demo-action="enable" <?php echo !$demoModeControlsAvailable ? 'disabled' : ''; ?>>Enable Demo Mode</button>
            <?php endif; ?>
            <button class="btn danger" type="button" data-demo-action="reset" <?php echo !$demoModeControlsAvailable ? 'disabled' : ''; ?>>Reset Demo Data</button>
            <span class="small-text">Restore demo accounts and seed content to their defaults.</span>
          </div>
        </div>
      </section>
      <?php endif; ?>
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

    (function initDemoModeModals() {
      const demoForm = document.querySelector('form[data-demo-mode-form="1"]');
      const demoCard = document.querySelector('[data-demo-mode-card="1"]');
      if (!demoForm || !demoCard) return;

      const enableBtn = demoCard.querySelector('[data-demo-action="enable"]');
      const disableBtn = demoCard.querySelector('[data-demo-action="disable"]');
      const resetBtn = demoCard.querySelector('[data-demo-action="reset"]');
      const hiddenEnabled = document.getElementById('demoModeEnabledInput');

      if (!hiddenEnabled) return;

      function sanitizeCode(value) {
        return (value || '').replace(/\D+/g, '').slice(0, 6);
      }

      function getCurrentState() {
        return hiddenEnabled.value === '1';
      }

      function redirectToSettingsAnchor() {
        const targetUrl = 'settings.php#system';
        const currentPath = window.location.pathname.replace(/\/+/g, '/');
        const normalizedPath = currentPath.endsWith('/') ? currentPath.slice(0, -1) : currentPath;
        if (normalizedPath !== '/settings.php') {
          window.location.assign(targetUrl);
          return;
        }
        if (window.location.hash !== '#system') {
          window.location.hash = 'system';
        }
        window.location.reload();
      }

      async function submitDemoAction(extraFields = {}, options = {}) {
        const { submitButton = null, cancelButton = null, loadingText = 'Submitting...' } = options;
        let restoreLoading = () => {};
        if (submitButton) {
          restoreLoading = setButtonLoading(submitButton, loadingText);
        }
        if (cancelButton) {
          cancelButton.disabled = true;
        }

        const actionUrl = demoForm.getAttribute('action') || window.location.pathname || 'settings.php';
        const formData = new FormData(demoForm);
        Object.entries(extraFields).forEach(([key, value]) => {
          if (value === undefined || value === null) {
            formData.delete(key);
          } else {
            formData.set(key, value);
          }
        });
        formData.set('demo_mode_enabled_present', '1');
        formData.set('demo_ajax', '1');

        try {
          const response = await fetch(actionUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          });
          if (!response.ok) {
            throw new Error('Request failed. Please try again.');
          }
          const contentType = response.headers.get('Content-Type') || '';
          let payload = null;
          if (contentType.toLowerCase().includes('application/json')) {
            try {
              payload = await response.json();
            } catch (parseErr) {
              payload = null;
            }
          }
          if (!payload || typeof payload !== 'object') {
            throw new Error('Unexpected response from the server.');
          }
          if (!payload.success) {
            const errMessage = (typeof payload.message === 'string' && payload.message.trim() !== '')
              ? payload.message.trim()
              : 'Request could not be completed. Please try again.';
            throw new Error(errMessage);
          }
          if (Object.prototype.hasOwnProperty.call(payload, 'enabled')) {
            hiddenEnabled.value = payload.enabled ? '1' : '0';
          }
          restoreLoading(true);
          if (cancelButton) {
            cancelButton.disabled = false;
          }
          return payload;
        } catch (err) {
          restoreLoading(false);
          if (cancelButton) {
            cancelButton.disabled = false;
          }
          throw err;
        }
      }

      function openToggleModal(targetEnabled) {
        const isEnable = targetEnabled === true;
        openModal({
          title: isEnable ? 'Enable Demo Mode' : 'Disable Demo Mode',
          render: (body, controls) => {
            const intro = document.createElement('p');
            intro.textContent = 'Enter the 6-digit authenticator app code and your current password to continue.';
            const formEl = document.createElement('form');
            formEl.className = 'modal-form';

            const codeGroup = document.createElement('div');
            const codeLabel = document.createElement('label');
            codeLabel.setAttribute('for', 'demoToggleTotp');
            codeLabel.textContent = 'Authenticator app code';
            const codeInput = document.createElement('input');
            codeInput.id = 'demoToggleTotp';
            codeInput.className = 'input';
            codeInput.type = 'text';
            codeInput.inputMode = 'numeric';
            codeInput.autocomplete = 'one-time-code';
            codeInput.required = true;
            codeInput.pattern = '\\d{6}';
            codeInput.maxLength = 6;
            codeInput.placeholder = '6-digit code';
            codeGroup.append(codeLabel, codeInput);

            const passGroup = document.createElement('div');
            const passLabel = document.createElement('label');
            passLabel.setAttribute('for', 'demoTogglePassword');
            passLabel.textContent = 'Current password';
            const passInput = document.createElement('input');
            passInput.id = 'demoTogglePassword';
            passInput.className = 'input';
            passInput.type = 'password';
            passInput.autocomplete = 'current-password';
            passInput.required = true;
            passGroup.append(passLabel, passInput);

            const errorEl = document.createElement('p');
            errorEl.className = 'modal-error';
            errorEl.setAttribute('role', 'alert');

            const actions = document.createElement('div');
            actions.className = 'modal-actions';
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn small secondary';
            cancelBtn.textContent = 'Cancel';
            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'submit';
            confirmBtn.className = 'btn small';
            confirmBtn.textContent = isEnable ? 'Enable Demo Mode' : 'Disable Demo Mode';
            actions.append(cancelBtn, confirmBtn);

            formEl.append(codeGroup, passGroup, errorEl, actions);
            body.append(intro, formEl);

            requestAnimationFrame(() => codeInput.focus());

            cancelBtn.addEventListener('click', () => {
              controls.close();
            });

            formEl.addEventListener('submit', (evt) => {
              evt.preventDefault();
              errorEl.textContent = '';
              const sanitizedCode = sanitizeCode(codeInput.value);
              codeInput.value = sanitizedCode;
              codeInput.setCustomValidity('');
              passInput.setCustomValidity('');
              if (!formEl.reportValidity()) {
                return;
              }
              if (sanitizedCode.length !== 6) {
                codeInput.setCustomValidity('Enter the 6-digit code.');
                codeInput.reportValidity();
                codeInput.setCustomValidity('');
                return;
              }
              if (passInput.value === '') {
                passInput.setCustomValidity('Enter your current password.');
                passInput.reportValidity();
                passInput.setCustomValidity('');
                return;
              }
              const previousValue = getCurrentState() ? '1' : '0';
              hiddenEnabled.value = isEnable ? '1' : '0';
              submitDemoAction({
                demo_mode_enabled: hiddenEnabled.value,
                demo_totp_code: sanitizedCode,
                demo_current_password: passInput.value,
                demo_reset: '0'
              }, {
                submitButton: confirmBtn,
                cancelButton: cancelBtn,
                loadingText: isEnable ? 'Enabling...' : 'Disabling...'
              }).then((payload) => {
                controls.close();
                if (payload && typeof payload.redirect === 'string' && payload.redirect !== '') {
                  window.location.assign(payload.redirect);
                } else {
                  redirectToSettingsAnchor();
                }
              }).catch((err) => {
                hiddenEnabled.value = previousValue;
                errorEl.textContent = (err && err.message) ? err.message : 'Unable to complete the request.';
              });
            });
          }
        });
      }

      function openResetModal() {
        openModal({
          title: 'Reset Demo Data',
          render: (body, controls) => {
            const intro = document.createElement('p');
            intro.textContent = 'Confirm with your current password to restore the demo seed.';
            const formEl = document.createElement('form');
            formEl.className = 'modal-form';

            const passGroup = document.createElement('div');
            const passLabel = document.createElement('label');
            passLabel.setAttribute('for', 'demoResetPassword');
            passLabel.textContent = 'Current password';
            const passInput = document.createElement('input');
            passInput.id = 'demoResetPassword';
            passInput.className = 'input';
            passInput.type = 'password';
            passInput.autocomplete = 'current-password';
            passInput.required = true;
            passGroup.append(passLabel, passInput);

            const errorEl = document.createElement('p');
            errorEl.className = 'modal-error';
            errorEl.setAttribute('role', 'alert');

            const actions = document.createElement('div');
            actions.className = 'modal-actions';
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn small secondary';
            cancelBtn.textContent = 'Cancel';
            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'submit';
            confirmBtn.className = 'btn small danger';
            confirmBtn.textContent = 'Reset Demo Data';
            actions.append(cancelBtn, confirmBtn);

            formEl.append(passGroup, errorEl, actions);
            body.append(intro, formEl);

            requestAnimationFrame(() => passInput.focus());

            cancelBtn.addEventListener('click', () => {
              controls.close();
            });

            formEl.addEventListener('submit', (evt) => {
              evt.preventDefault();
              errorEl.textContent = '';
              passInput.setCustomValidity('');
              if (!formEl.reportValidity()) {
                return;
              }
              if (passInput.value === '') {
                passInput.setCustomValidity('Enter your current password.');
                passInput.reportValidity();
                passInput.setCustomValidity('');
                return;
              }
              const currentValue = getCurrentState() ? '1' : '0';
              hiddenEnabled.value = currentValue;
              submitDemoAction({
                demo_mode_enabled: hiddenEnabled.value,
                demo_totp_code: '',
                demo_current_password: passInput.value,
                demo_reset: '1'
              }, {
                submitButton: confirmBtn,
                cancelButton: cancelBtn,
                loadingText: 'Resetting...'
              }).then((payload) => {
                controls.close();
                if (payload && typeof payload.redirect === 'string' && payload.redirect !== '') {
                  window.location.assign(payload.redirect);
                } else {
                  redirectToSettingsAnchor();
                }
              }).catch((err) => {
                hiddenEnabled.value = currentValue;
                errorEl.textContent = (err && err.message) ? err.message : 'Unable to complete the request.';
              });
            });
          }
        });
      }

      if (enableBtn) {
        enableBtn.addEventListener('click', () => {
          if (!enableBtn.disabled) {
            openToggleModal(true);
          }
        });
      }

      if (disableBtn) {
        disableBtn.addEventListener('click', () => {
          if (!disableBtn.disabled) {
            openToggleModal(false);
          }
        });
      }

      if (resetBtn) {
        resetBtn.addEventListener('click', () => {
          if (!resetBtn.disabled) {
            openResetModal();
          }
        });
      }
    })();
  </script>
</body>
</html>
