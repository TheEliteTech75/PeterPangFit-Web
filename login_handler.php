<?php
// login_handler.php — validates credentials, then branches to 2FA if required
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/ppf_trusted.php';
require_once __DIR__ . '/ppf_recognized_ip.php';
require_once __DIR__ . '/ppf_lockout.php';
require_once __DIR__ . '/ppf_theme.php';
require_once __DIR__ . '/helpers.php';

// ----------------------------------------------
const RATE_LIMIT_WINDOW_SEC = 15 * 60;
const RATE_LIMIT_MAX_FAILS  = 8;
const HONEYPOT_FIELDS       = ['website', 'url', 'homepage'];
const TURNSTILE_SECRET      = '0x4AAAAAAB4Apg9dUnPxk6T8QYYlPFZsXoo';
const TURNSTILE_DISABLED    = false;
const TURNSTILE_DEBUG_LOG   = true;

function ua_snippet(): string { return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200); }
function back_to_login_invalid_creds(): void { header('Location: login.php?err=1'); exit; }
function back_to_login_captcha(): void { header('Location: login.php?err=captcha'); exit; }

function rate_limit_init(): void { if (!isset($_SESSION['login_failures'])) $_SESSION['login_failures'] = []; }
function rate_limit_trim_window(): void {
  rate_limit_init(); $now = time();
  $_SESSION['login_failures'] = array_values(array_filter($_SESSION['login_failures'], fn($ts)=> ($now - (int)$ts) <= RATE_LIMIT_WINDOW_SEC));
}
function rate_limit_record_failure(): void { rate_limit_init(); $_SESSION['login_failures'][] = time(); rate_limit_trim_window(); }
function rate_limit_fail_count(): int { rate_limit_trim_window(); return count($_SESSION['login_failures']); }
function rate_limit_is_blocked(): bool { return rate_limit_fail_count() >= RATE_LIMIT_MAX_FAILS; }
function rate_limit_reset(): void { $_SESSION['login_failures'] = []; }
function force_captcha_on(): void { $_SESSION['force_captcha'] = true; }
function force_captcha_off(): void { unset($_SESSION['force_captcha']); }

function verify_turnstile(string $token, string $remoteIp, mysqli $conn, ?string $email): bool {
  if (TURNSTILE_DISABLED) return true;
  if ($token === '') {
    if (TURNSTILE_DEBUG_LOG) ppf_log($conn, null, $email, null, 'login_captcha_result', 'auth', null, 'missing_token; ua=' . ua_snippet());
    return false;
  }
  $post = http_build_query(['secret'=>TURNSTILE_SECRET,'response'=>$token,'remoteip'=>$remoteIp]);
  $resp = null; $err = null;
  if (function_exists('curl_init')) {
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $post,
      CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) $err = curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n", 'content'=>$post,'timeout'=>10]]);
    $resp = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if ($resp === false) $err = 'file_get_contents_failed';
  }
  if ($err || !$resp) {
    if (TURNSTILE_DEBUG_LOG) ppf_log($conn, null, $email, null, 'login_captcha_result', 'auth', null, 'transport_error=' . ($err ?: 'unknown') . '; ua=' . ua_snippet());
    return false;
  }
  $data = json_decode($resp, true);
  $ok   = !empty($data['success']);
  if (TURNSTILE_DEBUG_LOG) {
    $details = [
      'success'  => $ok ? '1' : '0',
      'hostname' => $data['hostname'] ?? '',
      'action'   => $data['action']   ?? '',
      'errors'   => isset($data['error-codes']) ? implode(',', (array)$data['error-codes']) : '',
    ];
    ppf_log($conn, null, $email ?: null, null, 'login_captcha_result', 'auth', null, json_encode($details) . '; ua=' . ua_snippet());
  }
  return $ok;
}

function log_honeypot(mysqli $conn, ?string $email, array $extra = []): void {
  $ip = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
  $pairs=[]; foreach ($extra as $k=>$v){ $pairs[]=$k.'='.(is_scalar($v)?(string)$v:json_encode($v)); }
  $details = 'reason=honeypot; ip=' . $ip . ( $pairs ? '; ' . implode('; ', $pairs) : '' ) . '; ua=' . ua_snippet();
  ppf_log($conn, null, $email ?: null, null, 'login_failed_honeypot', 'auth', null, $details);
}

if (!function_exists('column_exists')) {
  function column_exists(mysqli $conn, string $t, string $c): bool {
    $sql="SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
    if(!$st=$conn->prepare($sql)) return false; $st->bind_param("ss",$t,$c); $st->execute(); $r=$st->get_result(); $row=$r?$r->fetch_assoc():null; $st->close();
    return (int)($row['c']??0)>0;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back_to_login_invalid_creds();

$ip    = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

ppf_theme_ensure_column($conn);
ppf_time_ensure_columns($conn);

// Honeypot
foreach (HONEYPOT_FIELDS as $hp) {
  if (!empty($_POST[$hp] ?? '')) {
    log_honeypot($conn, $email, ['field'=>$hp]);
    rate_limit_record_failure(); force_captcha_on(); back_to_login_invalid_creds();
  }
}
// CAPTCHA if forced
$captchaRequired = !empty($_SESSION['force_captcha']);
if ($captchaRequired) {
  $captchaToken = $_POST['cf-turnstile-response'] ?? '';
  if (!verify_turnstile($captchaToken, $ip, $conn, $email)) {
    ppf_log($conn, null, $email ?: null, null, 'login_failed_captcha', 'auth', null, 'captcha_fail; ua=' . ua_snippet());
    rate_limit_record_failure(); force_captcha_on(); back_to_login_captcha();
  }
}
// Sliding-window rate limit
if (rate_limit_is_blocked()) {
  ppf_log($conn, null, $email ?: null, null, 'login_failed_captcha', 'auth', null, 'rate_limited; ua=' . ua_snippet());
  force_captcha_on(); back_to_login_invalid_creds();
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
  ppf_log($conn, null, $email ?: null, null, 'login_failed_email', 'auth', null, 'Invalid or missing email; ua=' . ua_snippet());
  rate_limit_record_failure(); if (rate_limit_fail_count()>=2) force_captcha_on(); back_to_login_invalid_creds();
}

// Fetch user (include locked_until so we can check lock state)
$st = $conn->prepare("SELECT id, email, password_hash, role, first_name, last_name, photo_url, theme,
                             timezone, time_format_24h,
                             twofa_email_enabled, twofa_app_enabled, is_active, locked_until
                      FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
if (!$st) { rate_limit_record_failure(); if (rate_limit_fail_count()>=2) force_captcha_on(); back_to_login_invalid_creds(); }
$st->bind_param("s",$email); $st->execute(); $rs=$st->get_result(); $user=$rs?$rs->fetch_assoc():null; $st->close();

if (!$user) {
  ppf_log($conn, null, $email, null, 'login_failed_email', 'user', null, 'Email not found; ua=' . ua_snippet());
  rate_limit_record_failure(); if (rate_limit_fail_count()>=2) force_captcha_on(); back_to_login_invalid_creds();
}

$timezonePref = ppf_time_normalize_timezone($user['timezone'] ?? '') ?? ppf_time_default_timezone();
$timeFormat24 = (int)($user['time_format_24h'] ?? 0) === 1;

// If already locked, bounce with banner (no new email here — email is sent at lock moment)
if ($user && ppf_is_account_locked($user)) {
  $remain = ppf_lockout_remaining_message($user);
  ppf_log($conn, (int)$user['id'], $user['email'], $user['role'], 'login_blocked_locked', 'security', null, "remaining={$remain}");
  header('Location: login.php?err=locked');
  exit;
}

// Verify password
$hash = $user['password_hash'] ?? '';
if (!is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
  // ✨ IMPORTANT: register the failure BEFORE redirecting so lock + email can fire.
  $uid = (int)$user['id'];
  $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  ppf_register_login_failure($conn, $uid, (string)$user['email'], (string)($user['role'] ?? null), $displayName);

  // Keep your existing logging / rate-limit / captcha behavior
  ppf_log($conn, $uid, $email, $user['role'] ?? null, 'login_failed_password', 'user', (string)$uid, 'Invalid password; ua=' . ua_snippet());
  rate_limit_record_failure(); if (rate_limit_fail_count()>=2) force_captcha_on();

  back_to_login_invalid_creds(); // header + exit
}

// (Removed: unreachable ppf_register_login_failure() call that used to sit here)

// Block inactive accounts (after correct password, before any 2FA/trusted-device)
if (column_exists($conn, 'users', 'is_active') && (int)($user['is_active'] ?? 1) === 0) {
  $uid = (int)$user['id'];
  ppf_log($conn, $uid, $user['email'], $user['role'] ?? null, 'login_blocked_inactive', 'user', (string)$uid, 'Attempted login to inactive account; ua=' . ua_snippet());
  rate_limit_reset(); force_captcha_off();
  header('Location: login.php?msg=account_inactive');
  exit;
}

// Clear failure counters on any successful password check
ppf_clear_lockout_on_success($conn, (int)$user['id'], (string)$user['email'], (string)($user['role'] ?? null));

require_once __DIR__ . '/ppf_trusted.php';
$uid = (int)$user['id'];
$twofaEmailOn = (int)($user['twofa_email_enabled'] ?? 0) === 1;
$twofaAppOn   = (int)($user['twofa_app_enabled']   ?? 0) === 1;

// --- Forced email challenge for users WITHOUT any 2FA when IP is unrecognized ---
$ip = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
if (!$twofaEmailOn && !$twofaAppOn) {
  // Prune old recognized IPs (rolling 90 days)
  ppf_rec_ips_prune($conn, $uid);

  $isRecognized = ppf_rec_ips_is_recognized($conn, $uid, $ip);

  if (!$isRecognized) {
    // Stage an email one-time code, but do NOT enable email 2FA permanently.
    // Reuse your existing email-code path that writes the code into `users.twofa_email_code`.
    $_SESSION['pending_user'] = [
      'id'    => $uid,
      'email' => $user['email'],
      'role'  => $user['role'],
      'first' => $user['first_name'] ?? '',
      'last'  => $user['last_name'] ?? '',
    ];
    $_SESSION['pending_2fa_method'] = 'email'; // direct to email code entry
    $_SESSION['pending_flags'] = ['force_email_unrecognized_ip' => 1];

    // Generate + send code just like your existing login path
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp  = date('Y-m-d H:i:s', time() + 15*60);
    if ($u = $conn->prepare("UPDATE users SET twofa_email_code=?, twofa_email_expires=? WHERE id=?")) {
      $u->bind_param("ssi", $code, $exp, $uid);
      $u->execute();
      $u->close();

      $name = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
      @send_plain_email($user['email'], $name, 'Your Login Code', "Your Peter Pang Fit login code is: {$code}\n\nIt expires in 15 minutes.");
      ppf_log($conn, $uid, $user['email'], $user['role'], 'force_email_code_unrecognized_ip', 'security', null, 'ua=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200));
    }

    header('Location: twofa.php');
    exit;
  }
}
// --- end forced email-on-unrecognized-ip block ---

// If 2FA would be required, allow skip when current cookie is a valid trusted device.
if (($twofaEmailOn || $twofaAppOn) && ppf_td_validate_for_user($conn, $uid)) {
  // Complete login immediately (same branch as "no 2FA")
  $resolvedTheme = ppf_theme_resolve((string)($user['theme'] ?? ''));

  $_SESSION['user_id']       = $uid;
  $_SESSION['email']         = $user['email'];
  $_SESSION['role']          = $user['role'];
  $_SESSION['first_name']    = $user['first_name'] ?? '';
  $_SESSION['middle_name']   = $user['middle_name'] ?? '';
  $_SESSION['last_name']     = $user['last_name'] ?? '';
  $_SESSION['photo_url']     = $user['photo_url'] ?? '';
  $_SESSION['theme']         = $resolvedTheme;
  $_SESSION['user_timezone'] = $timezonePref;
  $_SESSION['timezone']      = $timezonePref;
  $_SESSION['user_time_24h'] = $timeFormat24 ? 1 : 0;
  $_SESSION['time_format_24h'] = $_SESSION['user_time_24h'];
  $_SESSION['LAST_ACTIVITY'] = time();
  session_regenerate_id(true);

  require_once __DIR__ . '/ppf_passkeys.php';
  ppf_sessions_create_on_login($conn, $uid);

  // backfill geo/platform
  $sid      = session_id();
  $geo      = ppf_geo_city_region($conn, $ip);
  $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $platform = ppf_detect_platform($ua);
  if ($st = $conn->prepare("UPDATE user_sessions SET city=?, region=?, user_agent=?, platform=? WHERE user_id=? AND session_id=?")) {
    $st->bind_param("ssssis", $geo['city'], $geo['region'], $ua, $platform, $uid, $sid);
    $st->execute(); $st->close();
  }

  rate_limit_reset(); force_captcha_off();

  try {
    $hasLastLogin = column_exists($conn, 'users', 'last_login');
    $hasIpAddr    = column_exists($conn, 'users', 'ip_address');
    if ($hasLastLogin && $hasIpAddr) {
      if ($u = $conn->prepare("UPDATE users SET last_login=NOW(), ip_address=? WHERE id=?")) { $u->bind_param("si",$ip,$uid); $u->execute(); $u->close(); }
    } elseif ($hasLastLogin) {
      if ($u = $conn->prepare("UPDATE users SET last_login=NOW() WHERE id=?")) { $u->bind_param("i",$uid); $u->execute(); $u->close(); }
    } elseif ($hasIpAddr) {
      if ($u = $conn->prepare("UPDATE users SET ip_address=? WHERE id=?")) { $u->bind_param("si",$ip,$uid); $u->execute(); $u->close(); }
    }
  } catch (Throwable $e) {}

  ppf_log($conn, $uid, $user['email'], $user['role'], 'login_success', 'user', (string)$uid, '2FA skipped via trusted device');
  header('Location: dashboard.php'); exit;
}

// If we reach here, primary credentials are valid.
// 2FA branching:
$uid = (int)$user['id'];
$twofaEmailOn = (int)($user['twofa_email_enabled'] ?? 0) === 1;
$twofaAppOn   = (int)($user['twofa_app_enabled']   ?? 0) === 1;

if (!$twofaEmailOn && !$twofaAppOn) {
  // complete login immediately (no 2FA)
  $resolvedTheme = ppf_theme_resolve((string)($user['theme'] ?? ''));

  $_SESSION['user_id']       = $uid;
  $_SESSION['email']         = $user['email'];
  $_SESSION['role']          = $user['role'];
  $_SESSION['first_name']    = $user['first_name'] ?? '';
  $_SESSION['middle_name']   = $user['middle_name'] ?? '';
  $_SESSION['last_name']     = $user['last_name'] ?? '';
  $_SESSION['photo_url']     = $user['photo_url'] ?? '';
  $_SESSION['theme']         = $resolvedTheme;
  $_SESSION['user_timezone'] = $timezonePref;
  $_SESSION['timezone']      = $timezonePref;
  $_SESSION['user_time_24h'] = $timeFormat24 ? 1 : 0;
  $_SESSION['time_format_24h'] = $_SESSION['user_time_24h'];
  $_SESSION['LAST_ACTIVITY'] = time();
  session_regenerate_id(true);

  // create/record session row
  require_once __DIR__ . '/ppf_passkeys.php';
  ppf_sessions_create_on_login($conn, $uid);

  // backfill city/region for this session
  $sid      = session_id();
  $geo      = ppf_geo_city_region($conn, $ip);
  $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $platform = ppf_detect_platform($ua);

  if ($st = $conn->prepare("UPDATE user_sessions SET city=?, region=?, user_agent=?, platform=? WHERE user_id=? AND session_id=?")) {
    $st->bind_param("ssssis", $geo['city'], $geo['region'], $ua, $platform, $uid, $sid);
    $st->execute(); $st->close();
  }

  rate_limit_reset(); force_captcha_off();

  try {
    $hasLastLogin = column_exists($conn, 'users', 'last_login');
    $hasIpAddr    = column_exists($conn, 'users', 'ip_address');
    if ($hasLastLogin && $hasIpAddr) {
      if ($u = $conn->prepare("UPDATE users SET last_login=NOW(), ip_address=? WHERE id=?")) { $u->bind_param("si",$ip,$uid); $u->execute(); $u->close(); }
    } elseif ($hasLastLogin) {
      if ($u = $conn->prepare("UPDATE users SET last_login=NOW() WHERE id=?")) { $u->bind_param("i",$uid); $u->execute(); $u->close(); }
    } elseif ($hasIpAddr) {
      if ($u = $conn->prepare("UPDATE users SET ip_address=? WHERE id=?")) { $u->bind_param("si",$ip,$uid); $u->execute(); $u->close(); }
    }
  } catch (Throwable $e) {}

  ppf_log($conn, $uid, $user['email'], $user['role'], 'login_success', 'user', (string)$uid, 'User signed in; ua=' . ua_snippet());
  header('Location: dashboard.php'); exit;
}

// Stage 2FA (do NOT set user_id yet)
$_SESSION['pending_user'] = [
  'id'    => $uid,
  'email' => $user['email'],
  'role'  => $user['role'],
  'first' => $user['first_name'] ?? '',
  'last'  => $user['last_name'] ?? '',
  'photo' => $user['photo_url'] ?? '',
  'theme' => ppf_theme_resolve((string)($user['theme'] ?? '')),
  'timezone' => $timezonePref,
  'time_format_24h' => $timeFormat24 ? 1 : 0,
];

// If both enabled, ask which method; else auto-select
$method = 'select';
if ($twofaEmailOn && !$twofaAppOn) $method = 'email';
if (!$twofaEmailOn && $twofaAppOn) $method = 'app';
$_SESSION['pending_2fa_method'] = $method;

// If email method is chosen (alone or first step), send code now
if ($method === 'email') {
  $code = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
  $exp  = date('Y-m-d H:i:s', time() + 15*60);
  if ($u = $conn->prepare("UPDATE users SET twofa_email_code=?, twofa_email_expires=? WHERE id=?")) {
    $u->bind_param("ssi", $code, $exp, $uid);
    $u->execute(); $u->close();
    $body = "Your Peter Pang Fit login code is: {$code}\n\nIt expires soon.";
    @send_plain_email($user['email'], ($user['first_name'].' '.$user['last_name']), 'Your Login Code', $body);
    ppf_log($conn, $uid, $user['email'], $user['role'], 'twofa_email_code_sent_login', 'user', (string)$uid, null);
  }
}

header('Location: twofa.php');
exit;