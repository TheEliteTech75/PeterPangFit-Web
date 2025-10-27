<?php
// auth.php — enforce login, per-user inactivity timeout, and timeout logging (brace-free)

if (session_status() === PHP_SESSION_NONE):
    session_start();
endif;

require_once __DIR__ . '/db.php';        // must define $conn (mysqli)
require_once __DIR__ . '/logs.php';      // safe include (no redirects/output)
require_once __DIR__ . '/geo.php';       // <-- NEW (IP + geo helpers)
require_once __DIR__ . '/ppf_theme.php'; // Theme helpers (color palettes)
require_once __DIR__ . '/helpers.php';

ppf_time_ensure_columns($conn);
ppf_measurement_ensure_columns($conn);

// Capture any Demo Mode alerts and log them for auditing.
if (!empty($GLOBALS['PPF_DEMO_ALERTS_BUFFER']) && session_status() === PHP_SESSION_ACTIVE) {
    ppf_demo_store_alerts_in_session($GLOBALS['PPF_DEMO_ALERTS_BUFFER']);
    $GLOBALS['PPF_DEMO_ALERTS_BUFFER'] = [];
}

if (!empty($_SESSION['demo_alerts_unread']) && isset($conn) && $conn instanceof mysqli && function_exists('ppf_log')) {
    $alertsToLog = array_values(array_unique(array_map('strval', $_SESSION['demo_alerts_unread'])));
    $_SESSION['demo_alerts_unread'] = [];
    foreach ($alertsToLog as $alertMessage) {
        try {
            ppf_log(
                $conn,
                $_SESSION['user_id'] ?? null,
                $_SESSION['email'] ?? null,
                $_SESSION['role'] ?? null,
                'demo_mode_alert',
                'system',
                isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : null,
                $alertMessage
            );
        } catch (Throwable $e) {
            // Non-fatal: logging table may not exist yet.
        }
    }
}

/* 1) Require authentication */
if (empty($_SESSION['user_id'])):
    header('Location: login.php');
    exit;
endif;

/* 2) Resolve inactivity limit (seconds)
   Priority: session cache -> users.inactivity_timeout_seconds -> default (7200) */
$DEFAULT_INACTIVITY = 7200; // 2 hours default
$limit = $DEFAULT_INACTIVITY;

if (!empty($_SESSION['inactivity_limit']) && (int)$_SESSION['inactivity_limit'] > 0):
    $limit = (int)$_SESSION['inactivity_limit'];
else:
    $uid = (int)$_SESSION['user_id'];
    if (isset($conn) && $conn instanceof mysqli):
        $stmt = $conn->prepare('SELECT inactivity_timeout_seconds FROM users WHERE id = ? LIMIT 1');
        if ($stmt):
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())):
                $dbLimit = (int)($row['inactivity_timeout_seconds'] ?? 0);
                if ($dbLimit > 0):
                    $limit = $dbLimit;
                endif;
            endif;
            $stmt->close();
        endif;
    endif;
    $_SESSION['inactivity_limit'] = $limit; // cache for this session
endif;

/* 3) Inactivity timeout check */
$now  = time();
$last = $_SESSION['LAST_ACTIVITY'] ?? $now;

if (($now - $last) > $limit):
    // Log timeout BEFORE destroying session
    if (isset($conn) && $conn instanceof mysqli):
        ppf_log(
            $conn,
            $_SESSION['user_id'] ?? null,
            $_SESSION['email'] ?? null,
            $_SESSION['role'] ?? null,
            'logout_timeout',
            'user',
            isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : null,
            'User logged out due to inactivity.'
        );
    endif;

    // Clear session and redirect to login with inactive banner
    $_SESSION = [];
    if (ini_get('session.use_cookies')):
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    endif;
    session_destroy();
    header('Location: login.php?msg=inactive');
    exit;
endif;

/* 4) Refresh activity timestamp for active requests */
$_SESSION['LAST_ACTIVITY'] = $now;

require_once __DIR__ . '/ppf_passkeys.php';
ppf_sessions_enforce_revocation($conn, (int)($_SESSION['user_id'] ?? 0));
ppf_sessions_touch($conn, (int)($_SESSION['user_id'] ?? 0));

/* 4b) --- NEW: keep user_sessions fresh with geo + platform for this session --- */
try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && isset($conn) && $conn instanceof mysqli) {
        $sid      = session_id();
        $ip       = ppf_client_ip();
        $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $platform = ppf_detect_platform($ua);

        // Update last_seen + UA/platform (ppf_sessions_touch may already do some of this; this is safe)
        if ($st = $conn->prepare("UPDATE user_sessions SET last_seen_at=NOW(), user_agent=?, platform=? WHERE user_id=? AND session_id=?")) {
            $st->bind_param("ssis", $ua, $platform, $uid, $sid);
            $st->execute(); $st->close();
        }

        // If city/region empty, compute once and store
        if ($st = $conn->prepare("SELECT city,region FROM user_sessions WHERE user_id=? AND session_id=? LIMIT 1")) {
            $st->bind_param("is", $uid, $sid);
            $st->execute(); $rs = $st->get_result(); $row = $rs ? $rs->fetch_assoc() : null; $st->close();
            if ($row && (!$row['city'] && !$row['region'])) {
                $geo = ppf_geo_city_region($conn, $ip);
                if ($geo['city'] || $geo['region']) {
                    if ($u = $conn->prepare("UPDATE user_sessions SET city=?, region=? WHERE user_id=? AND session_id=?")) {
                        $u->bind_param("ssis", $geo['city'], $geo['region'], $uid, $sid);
                        $u->execute(); $u->close();
                    }
                }
            }
        }
    }
} catch (Throwable $e) { /* non-fatal */ }

// Ensure per-user time preferences are cached in the session
if (session_status() === PHP_SESSION_ACTIVE) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $sessionTzRaw = $_SESSION['user_timezone'] ?? ($_SESSION['timezone'] ?? null);
    $normalizedSessionTz = ppf_time_normalize_timezone($sessionTzRaw);
    $needsTimezone = ($normalizedSessionTz === null);
    $needsFormat = !isset($_SESSION['user_time_24h']);
    $needsMeasurement = !isset($_SESSION['user_measurement_system']);

    if (($needsTimezone || $needsFormat || $needsMeasurement) && $uid > 0 && isset($conn) && $conn instanceof mysqli) {
        try {
            if ($st = $conn->prepare('SELECT timezone, time_format_24h, measurement_system FROM users WHERE id=? LIMIT 1')) {
                $st->bind_param('i', $uid);
                $st->execute();
                $res = $st->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    if ($needsTimezone) {
                        $normalizedSessionTz = ppf_time_normalize_timezone($row['timezone'] ?? '') ?? $normalizedSessionTz;
                    }
                    if ($needsFormat && isset($row['time_format_24h'])) {
                        $_SESSION['user_time_24h'] = (int)$row['time_format_24h'] === 1 ? 1 : 0;
                    }
                    if ($needsMeasurement) {
                        $normalizedMeasurement = ppf_measurement_normalize_system($row['measurement_system'] ?? null);
                        if ($normalizedMeasurement !== null) {
                            ppf_measurement_set_session($normalizedMeasurement);
                        }
                    }
                }
                $st->close();
            }
        } catch (Throwable $e) {
            // Ignore — preferences remain default if unavailable
        }
    }

    if ($normalizedSessionTz === null) {
        $normalizedSessionTz = ppf_time_default_timezone();
    }
    $_SESSION['user_timezone'] = $normalizedSessionTz;
    $_SESSION['timezone'] = $normalizedSessionTz;

    if (!isset($_SESSION['user_time_24h'])) {
        $_SESSION['user_time_24h'] = 0;
    }
    $_SESSION['user_time_24h'] = (int)$_SESSION['user_time_24h'] === 1 ? 1 : 0;
    $_SESSION['time_format_24h'] = $_SESSION['user_time_24h'];

    if (!isset($_SESSION['user_measurement_system'])) {
        ppf_measurement_set_session(ppf_measurement_default_system());
    } else {
        ppf_measurement_set_session($_SESSION['user_measurement_system']);
    }
}

/* 5) Expose handy template variables (many pages rely on these) */
$USER_ID         = $_SESSION['user_id']    ?? null;
$USER_EMAIL      = $_SESSION['email']      ?? null;
$USER_ROLE       = $_SESSION['role']       ?? null;
$USER_FIRST_NAME = $_SESSION['first_name'] ?? null;
$USER_LAST_NAME  = $_SESSION['last_name']  ?? null;
$USER_PHOTO_URL  = $_SESSION['photo_url']  ?? null;
$USER_TIMEZONE   = $_SESSION['user_timezone'] ?? null;
$USER_TIME_24H   = (int)($_SESSION['user_time_24h'] ?? 0) === 1;
$USER_MEASUREMENT_SYSTEM = $_SESSION['user_measurement_system'] ?? ppf_measurement_default_system();
$USER_THEME      = null;

try {
    $currentTheme = $_SESSION['theme'] ?? ppf_theme_default_key();
    $resolvedTheme = ppf_theme_resolve((string)$currentTheme);
    $_SESSION['theme'] = $resolvedTheme;
    $USER_THEME = $resolvedTheme;
} catch (Throwable $e) {
    $_SESSION['theme'] = ppf_theme_default_key();
    $USER_THEME = ppf_theme_default_key();
}