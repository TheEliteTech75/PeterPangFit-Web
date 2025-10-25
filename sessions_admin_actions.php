<?php
// sessions_admin_actions.php — Admin-only actions for Sessions page (AJAX-friendly JSON)
// Actions:
//   verify_password            → {ok:true}
//   revoke_one                 → {ok:true, affected:n}
//   revoke_all_global          → {ok:true, affected:n}
//
// Notes:
// - Works whether user_sessions has a revoked_at column or not.
// - Returns clean JSON on all errors.
// - Keeps logs + email behavior.

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/helpers.php';

$uid   = (int)($_SESSION['user_id'] ?? 0);
$role  = (string)($_SESSION['role'] ?? '');
$email = (string)($_SESSION['email'] ?? '');
$first = (string)($_SESSION['first_name'] ?? '');
$last  = (string)($_SESSION['last_name'] ?? '');

function jerr($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_SLASHES);
  exit;
}
function jok($arr=[]){
  echo json_encode(['ok'=>true] + $arr, JSON_UNESCAPED_SLASHES);
  exit;
}

if ($uid <= 0) jerr('Not signed in', 401);
if (!ppf_is_admin_role($role)) jerr('Forbidden', 403);

// ---------- Schema helper: check if a table.column exists (cached) ----------
function table_has_column(mysqli $conn, string $table, string $column): bool {
  static $cache = [];
  $key = strtolower($table).':'.strtolower($column);
  if (array_key_exists($key, $cache)) return $cache[$key];

  $db = null;
  try {
    $res = $conn->query("SELECT DATABASE() AS db");
    if ($res && ($row = $res->fetch_assoc())) $db = (string)$row['db'];
  } catch (\Throwable $e) {}
  if (!$db) return $cache[$key] = false;

  try {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    if (!$st = $conn->prepare($sql)) return $cache[$key] = false;
    $st->bind_param("sss", $db, $table, $column);
    $st->execute();
    $st->store_result();
    $exists = ($st->num_rows > 0);
    $st->close();
    return $cache[$key] = $exists;
  } catch (\Throwable $e) {
    return $cache[$key] = false;
  }
}

// Compute once so both revoke paths use the same decision
$HAS_REVOKED_AT = table_has_column($conn, 'user_sessions', 'revoked_at');

// ---------- CSRF (tolerant & safe) ----------
$csrf_post = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
$csrf_sess = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
if ($csrf_post === '' || $csrf_sess === '' || !hash_equals($csrf_sess, $csrf_post)) {
  jerr('Invalid or expired session. Reload this page and try again.', 400);
}

$action = (string)($_POST['action'] ?? '');

// ---------- Password verify ----------
function verify_password(mysqli $conn, int $uid, string $pass): bool {
  if ($uid <= 0 || $pass === '') return false;

  $hash = null;
  if ($st = $conn->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param("i", $uid);
    if (!$st->execute()) { $st->close(); return false; }
    $rs = $st->get_result();
    if ($rs && ($row = $rs->fetch_assoc())) $hash = (string)($row['password_hash'] ?? '');
    $st->close();
  } else {
    return false;
  }

  if (!$hash) return false;
  try { return (bool)password_verify($pass, $hash); } catch (\Throwable $e) { return false; }
}

// ---------- Revoke helpers ----------
function admin_revoke_session(mysqli $conn, string $session_id, bool $hasRevokedAt): int {
  if ($session_id === '') return 0;
  if ($hasRevokedAt) {
    $sql = "UPDATE user_sessions SET revoked=1, revoked_at=NOW() WHERE session_id=? AND revoked=0";
  } else {
    $sql = "UPDATE user_sessions SET revoked=1 WHERE session_id=? AND revoked=0";
  }
  if (!$st = $conn->prepare($sql)) return 0;
  $st->bind_param("s", $session_id);
  $st->execute();
  $n = $st->affected_rows;
  $st->close();
  return max(0, (int)$n);
}

function admin_revoke_all_except(mysqli $conn, string $excludeSid, bool $hasRevokedAt): int {
  if ($hasRevokedAt) {
    $sql = "UPDATE user_sessions SET revoked=1, revoked_at=NOW() WHERE revoked=0 AND session_id <> ?";
  } else {
    $sql = "UPDATE user_sessions SET revoked=1 WHERE revoked=0 AND session_id <> ?";
  }
  if (!$st = $conn->prepare($sql)) return 0;
  $st->bind_param("s", $excludeSid);
  $st->execute();
  $n = $st->affected_rows;
  $st->close();
  return max(0, (int)$n);
}

// ---------- Actions ----------
switch ($action) {
  case 'verify_password': {
    $pass = (string)($_POST['password'] ?? '');
    if (!verify_password($conn, $uid, $pass)) jerr('Incorrect password.');
    if (function_exists('ppf_log')) {
      ppf_log($conn, $uid, $email, 'admin', 'admin_password_verified', 'security', (string)$uid, null);
    }
    jok();
  }

  case 'revoke_one': {
    $pass = (string)($_POST['password'] ?? '');
    $sid  = (string)($_POST['session_id'] ?? '');
    if ($sid === '') jerr('Missing session_id.');
    if (!verify_password($conn, $uid, $pass)) jerr('Incorrect password.');
    if ($sid === session_id()) jerr('Cannot revoke the current session from here.');

    $n = admin_revoke_session($conn, $sid, $HAS_REVOKED_AT);

    // Log with target user context if available
    $tuid = null;
    if ($st = $conn->prepare("SELECT user_id FROM user_sessions WHERE session_id=? LIMIT 1")){
      $st->bind_param("s",$sid); $st->execute(); $st->bind_result($tuid); $st->fetch(); $st->close();
    }
    $ctx = 'target_sid='.$sid.($tuid?(';target_user_id='.$tuid):'');
    if (function_exists('ppf_log')) {
      ppf_log($conn, $uid, $email, 'admin', 'admin_session_revoked_one', 'security', (string)$uid, $ctx);
    }
    jok(['affected'=>$n]);
  }

  case 'revoke_all_global': {
    $pass = (string)($_POST['password'] ?? '');
    $app  = preg_replace('/\D/','', (string)($_POST['app_code'] ?? ''));

    if (!verify_password($conn, $uid, $pass)) jerr('Incorrect password.');

    // Ensure authenticator is enabled and verify TOTP
    $secret = '';
    if ($st = $conn->prepare("SELECT twofa_app_enabled, twofa_secret FROM users WHERE id=? LIMIT 1")){
      $st->bind_param("i",$uid); $st->execute(); $rs=$st->get_result();
      $row = $rs ? $rs->fetch_assoc() : null; $st->close();
      $enabled = (int)($row['twofa_app_enabled'] ?? 0) === 1;
      $secret  = strtoupper(preg_replace('/\s+/', '', (string)($row['twofa_secret'] ?? '')));
      if (!$enabled || $secret === '') jerr('Authenticator App is not enabled for your account.');
    } else {
      jerr('Unable to verify 2FA state.');
    }

    $off = ppf_totp_match_offset($secret, $app, 30, 6, 8);
    if ($off === null) jerr('Invalid authenticator code.');

    $currentSid = session_id();

    $affected = admin_revoke_all_except($conn, $currentSid, $HAS_REVOKED_AT);

    // Actor context for emails/logs
    $ip = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $geo = ppf_geo_city_region($conn, $ip);
    $browser = ppf_detect_browser($ua);
    $platform = ppf_detect_platform($ua);
    $actorName = trim(($first ? $first.' ' : '').$last);

    // Email all admins
    $admins = [];
    if ($rs = $conn->query("SELECT email, first_name, last_name FROM users WHERE role IN ('admin','super_admin') AND email IS NOT NULL AND email<>''")) {
      while ($r = $rs->fetch_assoc()) { $admins[] = $r; }
      $rs->close();
    }

    $subject = 'Peter Pang Fit: Global Session Sign-Out Executed';
    $body =
      "An administrator executed a global session revocation.\n\n".
      "Actor: {$actorName} <{$email}>\n".
      "IP: {$ip}\n".
      "Location: ".($geo['city'] ?: 'Unknown').", ".($geo['region'] ?: 'Unknown')."\n".
      "Browser: {$browser}\n".
      "OS: {$platform}\n".
      "Affected sessions: {$affected}\n".
      "Timestamp: ".date('Y-m-d H:i:s')."\n\n".
      "Note: The actor's current session remained active.";

    foreach ($admins as $a) {
      $nm = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? ''));
      @send_plain_email((string)$a['email'], $nm, $subject, $body);
    }

    $ctx = 'affected='.$affected.'; actor_ip='.$ip.'; actor_browser='.$browser.'; actor_os='.$platform;
    if (function_exists('ppf_log')) {
      ppf_log($conn, $uid, $email, 'admin', 'admin_global_sessions_revoked', 'security', (string)$uid, $ctx);
    }
    jok(['affected'=>$affected]);
  }

  default:
    jerr('Unknown action.');
}