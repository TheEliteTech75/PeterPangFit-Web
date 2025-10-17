<?php
// ppf_passkeys.php — shared helpers for passkeys + sessions
if (!defined('PPF_PASSKEYS_INCLUDED')) define('PPF_PASSKEYS_INCLUDED', 1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/geo.php'; // provides ppf_geo_city_region, ppf_detect_platform, ppf_detect_browser

// --- begin: session table auto-migration guard ---
if (!function_exists('ppf_column_exists')) {
  function ppf_column_exists(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param("ss", $table, $col);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return (int)($row['c'] ?? 0) > 0;
  }
}

if (!function_exists('ppf_sessions_ensure_columns')) {
  function ppf_sessions_ensure_columns(mysqli $conn): void {
    $adds = [];
    if (!ppf_column_exists($conn, 'user_sessions', 'city'))       $adds[] = "ADD COLUMN city VARCHAR(80) NULL";
    if (!ppf_column_exists($conn, 'user_sessions', 'region'))     $adds[] = "ADD COLUMN region VARCHAR(80) NULL";
    if (!ppf_column_exists($conn, 'user_sessions', 'platform'))   $adds[] = "ADD COLUMN platform VARCHAR(40) NULL";
    if (!ppf_column_exists($conn, 'user_sessions', 'browser'))    $adds[] = "ADD COLUMN browser VARCHAR(40) NULL";
    if (!ppf_column_exists($conn, 'user_sessions', 'user_agent')) $adds[] = "ADD COLUMN user_agent TEXT NULL";

    if ($adds) {
      $sql = "ALTER TABLE user_sessions " . implode(", ", $adds);
      @$conn->query($sql); // best-effort
    }
  }
}
ppf_sessions_ensure_columns($conn);
// --- end: auto-migration guard ---

/** Create a session row at login (call immediately after session_regenerate_id) */
function ppf_sessions_create_on_login(mysqli $conn, int $uid): void {
  if ($uid <= 0) return;
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  $sid = session_id() ?: bin2hex(random_bytes(16));
  $ip  = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
  $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

  // Enrich (ASSOCIATIVE)
  $geo      = ppf_geo_city_region($conn, $ip);    // ['city'=>..., 'region'=>...]
  $city     = $geo['city']   ?? 'Unknown';
  $region   = $geo['region'] ?? 'Unknown';
  $platform = ppf_detect_platform($ua);
  $browser  = ppf_detect_browser($ua);

  // Insert-or-update (keeps a single row per session_id)
  $sql = "INSERT INTO user_sessions
            (user_id, session_id, created_at, last_seen_at, revoked, ip, city, region, user_agent, platform, browser)
          VALUES
            (?, ?, NOW(), NOW(), 0, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            last_seen_at = NOW(),
            ip          = VALUES(ip),
            user_agent  = VALUES(user_agent),
            platform    = VALUES(platform),
            browser     = VALUES(browser),
            city        = IF(user_sessions.city   IS NULL OR user_sessions.city   = '', VALUES(city),   user_sessions.city),
            region      = IF(user_sessions.region IS NULL OR user_sessions.region = '', VALUES(region), user_sessions.region)";

  if ($st = $conn->prepare($sql)) {
    // i s s s s s s s  -> 1 int + 7 strings = "isssssss"
    $st->bind_param("isssssss", $uid, $sid, $ip, $city, $region, $ua, $platform, $browser);
    $st->execute();
    $st->close();
  }
}

/** Update the current session record with latest activity + details */
function ppf_sessions_touch(mysqli $conn, int $uid): void {
  if ($uid <= 0) return;
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  $sid = session_id();
  if ($sid === '') return;

  $ip  = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
  $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

  // If city/region missing, fetch once
  $needGeo = false; $city = null; $region = null;
  if ($st = $conn->prepare("SELECT city,region FROM user_sessions WHERE user_id=? AND session_id=? LIMIT 1")) {
    $st->bind_param("is", $uid, $sid);
    $st->execute();
    $rs = $st->get_result();
    if ($row = $rs->fetch_assoc()) {
      $needGeo = (empty($row['city']) || empty($row['region']));
    } else {
      // no row (edge case) — create it
      $st->close();
      ppf_sessions_create_on_login($conn, $uid);
      return;
    }
    $st->close();
  }

  if ($needGeo) {
    $geo    = ppf_geo_city_region($conn, $ip);
    $city   = $geo['city'] ?? null;
    $region = $geo['region'] ?? null;
  }

  $platform = ppf_detect_platform($ua);
  $browser  = ppf_detect_browser($ua);

  $sql = "UPDATE user_sessions
             SET last_seen_at = NOW(),
                 ip          = ?,
                 user_agent  = ?,
                 platform    = ?,
                 browser     = ?"
         . ($needGeo ? ", city = ?, region = ?" : "")
         . " WHERE user_id = ? AND session_id = ?";

  if ($st = $conn->prepare($sql)) {
    if ($needGeo) {
      // s s s s s s i s
      $st->bind_param("ssssssis", $ip, $ua, $platform, $browser, $city, $region, $uid, $sid);
    } else {
      // s s s s i s
      $st->bind_param("ssssis", $ip, $ua, $platform, $browser, $uid, $sid);
    }
    $st->execute();
    $st->close();
  }
}

/** Is the current session revoked? If yes, kill it. */
function ppf_sessions_enforce_revocation(mysqli $conn, int $uid): void {
  if ($uid <= 0) return;
  $sid = session_id();
  if ($sid === '') return;

  if ($st = $conn->prepare("SELECT revoked FROM user_sessions WHERE user_id=? AND session_id=? LIMIT 1")) {
    $st->bind_param("is", $uid, $sid);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    if ($row && (int)$row['revoked'] === 1) {
      ppf_log($conn, $uid, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'session_revoked_logout', 'user', (string)$uid, 'revoked=1');
      $_SESSION = [];
      if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
      }
      session_destroy();
      header('Location: login.php?msg=inactive');
      exit;
    }
  }
}

/** Sign out all user sessions except current */
function ppf_sessions_signout_all_others(mysqli $conn, int $uid): int {
  $sid = session_id();
  if ($sid === '') return 0;
  if ($st = $conn->prepare("UPDATE user_sessions SET revoked=1 WHERE user_id=? AND session_id<>? AND revoked=0")) {
    $st->bind_param("is",$uid,$sid);
    $st->execute();
    $aff = $st->affected_rows;
    $st->close();
    return (int)$aff;
  }
  return 0;
}

/** Sign out a specific session_id for a user */
function ppf_sessions_signout_one(mysqli $conn, int $uid, string $sessionId): bool {
  if ($sessionId === session_id()) return false;
  if ($st = $conn->prepare("UPDATE user_sessions SET revoked=1 WHERE user_id=? AND session_id=? AND revoked=0")) {
    $st->bind_param("is",$uid,$sessionId);
    $ok = $st->execute();
    $st->close();
    return $ok;
  }
  return false;
}