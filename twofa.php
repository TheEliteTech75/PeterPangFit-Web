<?php
// twofa.php — second-factor selection & verification
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/send_email.php'; // for email login codes
require_once __DIR__ . '/ppf_trusted.php';
require_once __DIR__ . '/ppf_recognized_ip.php';
require_once __DIR__ . '/ppf_theme.php';
require_once __DIR__ . '/helpers.php';

ppf_time_ensure_columns($conn);

$pending = $_SESSION['pending_user'] ?? null;

$themeCandidate = $pending['theme'] ?? ($_SESSION['theme'] ?? ppf_theme_default_key());
$themeKey = ppf_theme_resolve((string)$themeCandidate);
$_SESSION['theme'] = $themeKey;
$themeStyleTag = ppf_theme_render_style_block();
$themeInitScript = '<script>(function(){var theme=' . json_encode($themeKey, JSON_UNESCAPED_SLASHES) . ';function apply(){var d=document.documentElement;d.dataset.theme=theme;var b=document.body;if(b&&!b.classList.contains("ppf-themed")){b.classList.add("ppf-themed");}}if(document.readyState!=="loading"){apply();}else{document.addEventListener("DOMContentLoaded",apply);}})();</script>';
if (!$pending) { header('Location: login.php'); exit; }

$uid   = (int)$pending['id'];
$email = (string)$pending['email'];
$role  = (string)$pending['role'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$method = $_SESSION['pending_2fa_method'] ?? 'select';
$flash = null; $flash_type = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Invalid session. Please try again.'; $flash_type = 'err';
  } else {
    $action = $_POST['action'] ?? '';
    switch ($action) {
      case 'choose_method':
        $choice = $_POST['method'] ?? '';
        if (!in_array($choice, ['email','app'], true)) {
          $flash = 'Please choose a method.'; $flash_type = 'err';
        } else {
          $_SESSION['pending_2fa_method'] = $choice;
          $method = $choice;
          if ($choice === 'email') {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $exp  = date('Y-m-d H:i:s', time() + 15*60);
            if ($u = $conn->prepare("UPDATE users SET twofa_email_code=?, twofa_email_expires=? WHERE id=?")) {
              $u->bind_param("ssi", $code, $exp, $uid);
              $u->execute();
              $u->close();
            }
            $name = trim(($pending['first'] ?? '').' '.($pending['last'] ?? ''));
            @send_plain_email($email, $name, 'Your Login Code', "Your code: {$code}\n\nIt expires soon.");
            ppf_log($conn, $uid, $email, $role, 'twofa_email_code_sent_login', 'user', (string)$uid, 'method_select');
          }
        }
        break;

      case 'verify_email_code':
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $st = $conn->prepare("SELECT twofa_email_code, twofa_email_expires FROM users WHERE id=? LIMIT 1");
        $st->bind_param("i", $uid); $st->execute();
        $rs = $st->get_result(); $row = $rs ? $rs->fetch_assoc() : null; $st->close();
        $ok = false;
        if ($row) {
          $e = strtotime((string)$row['twofa_email_expires']);
          if ($e && $e > time() && hash_equals((string)$row['twofa_email_code'], $code)) $ok = true;
        }
        if (!$ok) {
          $flash = 'Invalid or expired code.'; $flash_type = 'err';
          ppf_log($conn, $uid, $email, $role, 'twofa_email_code_invalid_login', 'user', (string)$uid, null);
        } else {
          if ($u = $conn->prepare("UPDATE users SET twofa_email_code=NULL, twofa_email_expires=NULL WHERE id=?")) {
            $u->bind_param("i", $uid); $u->execute(); $u->close();
          }
			// If this email code was forced due to an unrecognized IP (no 2FA), mark the IP as recognized for 90 days.
$flags = $_SESSION['pending_flags'] ?? [];
if (!empty($flags['force_email_unrecognized_ip'])) {
  $ip = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
  // prune old and then touch this IP
  ppf_rec_ips_prune($conn, $uid);
  ppf_rec_ips_touch($conn, $uid, $ip);
  // clear the flag so it doesn't repeat
  unset($_SESSION['pending_flags']['force_email_unrecognized_ip']);
  ppf_log($conn, $uid, $email, $role, 'recognized_ip_saved', 'security', null, null);
}
			$trust = isset($_POST['trust']) && $_POST['trust'] === '1';
			complete_login_and_redirect($conn, $pending, $trust);

        }
        break;

      case 'verify_app_code':
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $st = $conn->prepare("SELECT twofa_secret FROM users WHERE id=? LIMIT 1");
        $st->bind_param("i", $uid); $st->execute();
        $rs = $st->get_result(); $row = $rs ? $rs->fetch_assoc() : null; $st->close();
        $secret = strtoupper(preg_replace('/\s+/', '', (string)($row['twofa_secret'] ?? '')));
        $offset = ($secret !== '') ? ppf_totp_match_offset($secret, $code, 30, 6, 8) : null; // ±240s window
        if ($offset === null) {
          $flash = 'Invalid authenticator code.'; $flash_type = 'err';
          ppf_log($conn, $uid, $email, $role, 'twofa_app_code_invalid_login', 'user', (string)$uid, null);
        } else {
          ppf_log($conn, $uid, $email, $role, 'twofa_app_code_valid_login', 'user', (string)$uid, 'offset='.$offset);
			$trust = isset($_POST['trust']) && $_POST['trust'] === '1';
			complete_login_and_redirect($conn, $pending, $trust);
        }
        break;
    }
  }
}

function complete_login_and_redirect(mysqli $conn, array $pending, bool $trust=false): void {
  $_SESSION['user_id']       = (int)$pending['id'];
  $_SESSION['email']         = $pending['email'];
  $_SESSION['role']          = $pending['role'];
  $_SESSION['first_name']    = $pending['first'] ?? '';
  $_SESSION['last_name']     = $pending['last'] ?? '';
  $_SESSION['photo_url']     = $pending['photo'] ?? '';
  $_SESSION['theme']         = ppf_theme_resolve((string)($pending['theme'] ?? ''));
  $timezonePref = ppf_time_normalize_timezone($pending['timezone'] ?? ($_SESSION['user_timezone'] ?? null)) ?? ppf_time_default_timezone();
  $timeFormat24 = (int)($pending['time_format_24h'] ?? ($_SESSION['user_time_24h'] ?? 0)) === 1;
  $_SESSION['user_timezone'] = $timezonePref;
  $_SESSION['timezone']      = $timezonePref;
  $_SESSION['user_time_24h'] = $timeFormat24 ? 1 : 0;
  $_SESSION['time_format_24h'] = $_SESSION['user_time_24h'];
  $_SESSION['LAST_ACTIVITY'] = time();
  unset($_SESSION['pending_user'], $_SESSION['pending_2fa_method']);
  session_regenerate_id(true);

  $ip  = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
  $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $uid = (int)$pending['id'];

  // optional: update last_login/ip (kept as-is)
  try {
    $hasLastLogin = column_exists($conn, 'users', 'last_login');
    $hasIpAddr    = column_exists($conn, 'users', 'ip_address');
    if ($hasLastLogin && $hasIpAddr) {
      if ($u = $conn->prepare("UPDATE users SET last_login = NOW(), ip_address = ? WHERE id = ?")) { $u->bind_param("si", $ip, $uid); $u->execute(); $u->close(); }
    } elseif ($hasLastLogin) {
      if ($u = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")) { $u->bind_param("i", $uid); $u->execute(); $u->close(); }
    } elseif ($hasIpAddr) {
      if ($u = $conn->prepare("UPDATE users SET ip_address = ? WHERE id = ?")) { $u->bind_param("si", $ip, $uid); $u->execute(); $u->close(); }
    }
  } catch (Throwable $e) {}

  if ($trust) {
    require_once __DIR__ . '/ppf_trusted.php';
    // default device name from UA
    $deviceName = 'Trusted device';
    if (stripos($ua, 'iphone') !== false)  $deviceName = 'iPhone';
    elseif (stripos($ua, 'ipad') !== false)$deviceName = 'iPad';
    elseif (stripos($ua, 'android') !== false) $deviceName = 'Android';
    elseif (stripos($ua, 'mac os') !== false || stripos($ua, 'macintosh') !== false) $deviceName = 'Mac';
    elseif (stripos($ua, 'windows') !== false) $deviceName = 'Windows';
    ppf_td_add($conn, $uid, $deviceName, $ua, $ip);
  }

  ppf_log($conn, $uid, $pending['email'], $pending['role'], 'login_success', 'user', (string)$uid, '2FA passed');
  header('Location: dashboard.php'); exit;
}

if (!function_exists('column_exists')) {
  function column_exists(mysqli $conn, string $t, string $c): bool {
    $sql="SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param("ss", $t, $c); $st->execute(); $r = $st->get_result(); $row = $r ? $r->fetch_assoc() : null; $st->close();
    return (int)($row['c'] ?? 0) > 0;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Two-Factor Authentication · Peter Pang Fit</title>
  <?php echo $themeStyleTag, "\n", $themeInitScript, "\n"; ?>
  <style>

    html,body{
      margin:0; padding:0; background: var(--page-canvas); color:var(--text);
      font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;
    }
    .wrap{ max-width:480px; margin:48px auto; padding:0 16px; box-sizing:border-box; }
    .card{
      background:rgba(9,14,28,0.72); border:1px solid var(--line); border-radius:14px;
      padding:18px; margin-bottom:18px;
    }
    .card h3{ margin:0 0 10px 0; font-size:16px }
    .inline-input{
      width:100%;
      max-width:100%;
      box-sizing:border-box; /* <-- prevents right overflow */
      background:rgba(8,13,23,0.95); border:1px solid var(--line); color:#f8fafc;
      padding:10px; border-radius:10px; font-size:16px; display:block;
    }
    .btn{
      display:inline-flex; align-items:center; gap:8px; background:#2a3446; border:1px solid var(--line);
      color:var(--text); padding:10px 14px; border-radius:10px; cursor:pointer; text-decoration:none
    }
    .btn.brand{ background:rgba(56,189,248,0.22); border-color:rgba(56,189,248,0.35) }
    .flash{ margin:0 0 16px 0; padding:12px; border-radius:10px; border:1px solid; background:rgba(8,13,23,0.85) }
    .flash.ok{ border-color:rgba(34,197,94,0.45); color:#a7f3d0 }
    .flash.err{ border-color:#4a2020; color:#fca5a5 }
    .row{ display:grid; grid-template-columns:1fr; gap:10px }
    form{ margin:0 } /* ensure no unexpected default margins */
  </style>
</head>
<body>
  <div class="wrap">
    <?php if ($flash): ?>
      <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>">
        <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <?php if ($method === 'select'): ?>
      <div class="card">
        <h3>Choose a verification method</h3>
        <form method="post" action="twofa.php">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="choose_method">
          <div class="row">
            <label><input type="radio" name="method" value="email"> Email Authentication (code sent by email)</label>
            <label><input type="radio" name="method" value="app"> Authenticator App</label>
            <div><button class="btn brand" type="submit">Continue</button></div>
          </div>
        </form>
      </div>

    <?php elseif ($method === 'email'): ?>
      <div class="card">
        <h3>Enter your email code</h3>
        <form method="post" action="twofa.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="verify_email_code">
          <label>6-digit code</label>
          <input class="inline-input" name="code" maxlength="6" inputmode="numeric" pattern="[0-9]*" required>
			<label style="margin-top:10px"><input type="checkbox" name="trust" value="1"> Trust this device for 30 days</label>
          <div style="margin-top:12px">
            <button class="btn brand" type="submit">Verify &amp; Sign In</button>
          </div>
        </form>
      </div>

    <?php elseif ($method === 'app'): ?>
      <div class="card">
        <h3>Enter your authenticator code</h3>
        <form method="post" action="twofa.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="verify_app_code">
          <label>Authenticator code</label>
          <input class="inline-input" name="code" maxlength="8" inputmode="numeric" required>
			<label style="margin-top:10px"><input type="checkbox" name="trust" value="1"> Trust this device for 30 days</label>
          <div style="margin-top:12px">
            <button class="btn brand" type="submit">Verify &amp; Sign In</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>