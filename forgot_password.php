<?php
// forgot_password.php
// Lets a user request a password reset link which expires in 1 hour.
// Uses send_email.php -> send_plain_email(...).
//
// Behavior:
// - Honeypot trips: log forgot_password_honeypot
// - CAPTCHA fails: show red banner, log forgot_password_captcha_failed
// - Rate limited: log forgot_password_rate_limited
// - Nonexistent email: show green, log forgot_password_failed
// - Existing email: log forgot_password_success
// - Email send OK: log password_reset_email_sent
// - Email send fail: log password_reset_email_failed

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/logs.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ua_snippet(): string {
  return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$flash = null;
$flash_type = 'err';

// ----------------------------------------------
// Rate limit helpers (session-based)
// ----------------------------------------------
const RATE_LIMIT_WINDOW_SEC = 15 * 60; // 15 minutes
const RATE_LIMIT_MAX_FAILS  = 5;       // attempts before block
const HONEYPOT_FIELDS       = ['website', 'url', 'homepage'];

function rate_limit_init(): void {
  if (!isset($_SESSION['fp_failures'])) $_SESSION['fp_failures'] = [];
}
function rate_limit_record_failure(): void {
  rate_limit_init();
  $_SESSION['fp_failures'][] = time();
}
function rate_limit_is_blocked(): bool {
  rate_limit_init();
  $now = time();
  $_SESSION['fp_failures'] = array_values(array_filter(
    $_SESSION['fp_failures'],
    fn($ts) => ($now - (int)$ts) <= RATE_LIMIT_WINDOW_SEC
  ));
  return count($_SESSION['fp_failures']) >= RATE_LIMIT_MAX_FAILS;
}
function rate_limit_reset(): void {
  $_SESSION['fp_failures'] = [];
}

// ----------------------------------------------
// Cloudflare Turnstile verification
// ----------------------------------------------
const TURNSTILE_SECRET   = '0x4AAAAAAB4Apg9dUnPxk6T8QYYlPFZsXoo';
const TURNSTILE_DISABLED = false;

function verify_turnstile(string $token, string $remoteIp, mysqli $conn, ?string $email): bool {
  if (TURNSTILE_DISABLED) return true;
  if ($token === '') return false;

  $post = http_build_query([
    'secret'   => TURNSTILE_SECRET,
    'response' => $token,
    'remoteip' => $remoteIp,
  ]);

  $resp = null; $err = null;
  if (function_exists('curl_init')) {
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $post,
      CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) $err = curl_error($ch);
    curl_close($ch);
  }

  if ($err || !$resp) return false;
  $data = json_decode($resp, true);
  return !empty($data['success']);
}

// ----------------------------------------------
// DB helper
// ----------------------------------------------
function find_user_by_email(mysqli $conn, string $email) : ?array {
  $stmt = $conn->prepare("SELECT id, email, first_name, last_name FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

// ----------------------------------------------
// Mail failure probe (best effort)
// ----------------------------------------------
function best_effort_mail_reason(): string {
  return 'Mailer reported failure (check server mail logs/PHPMailer ErrorInfo).';
}
function smtp_probe_reason(): ?string {
  if (!defined('MAIL_HOST') || !defined('MAIL_PORT')) return null;
  $errno = 0; $errstr = '';
  $fp = @fsockopen(MAIL_HOST, (int)MAIL_PORT, $errno, $errstr, 4.0);
  if ($fp) { fclose($fp); return null; }
  if ($errstr) return "SMTP connect error: $errstr";
  if ($errno)  return "SMTP connect error code: $errno";
  return null;
}

// ----------------------------------------------
$baseUrl = 'https://peterpang.pwncore.net';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $ip    = $_SERVER['REMOTE_ADDR'] ?? '';

  // Honeypot
  foreach (HONEYPOT_FIELDS as $hp) {
    if (!empty($_POST[$hp] ?? '')) {
      ppf_log($conn, null, $email, null, 'forgot_password_honeypot', 'auth', null,
        "reason=honeypot; field={$hp}; ua=" . ua_snippet());
      $flash = 'Verification failed. Please try again.';
      $flash_type = 'err';
      exit;
    }
  }

  // CAPTCHA
  $captchaToken = $_POST['cf-turnstile-response'] ?? '';
  if (!verify_turnstile($captchaToken, $ip, $conn, $email)) {
    ppf_log($conn, null, $email, null, 'forgot_password_captcha_failed', 'auth', null,
      "CAPTCHA verification failed.; ua=" . ua_snippet());
    $flash = 'Verification failed. Please try again.';
    $flash_type = 'err';
  }
  // Rate limiting
  elseif (rate_limit_is_blocked()) {
    ppf_log($conn, null, $email, null, 'forgot_password_rate_limited', 'auth', null,
      "window_sec=" . RATE_LIMIT_WINDOW_SEC . "; max_fails=" . RATE_LIMIT_MAX_FAILS
      . "; count=" . count($_SESSION['fp_failures'] ?? []) . "; ua=" . ua_snippet());
    $flash = 'Verification failed. Please try again.';
    $flash_type = 'err';
  }
  // Email field check
  elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $flash = 'Please enter a valid email address.';
    $flash_type = 'err';
  }
  else {
    $user = find_user_by_email($conn, $email);

    if (!$user) {
      $flash = 'If an account is found under the provided email, you will receive a password reset email shortly.';
      $flash_type = 'ok';
      ppf_log($conn, null, $email, null, 'forgot_password_failed', 'auth', null,
        "Email does not exist.; ua=" . ua_snippet());
    } else {
      ppf_log($conn, (int)$user['id'], $user['email'], null, 'forgot_password_success', 'auth', null,
        "Email found.; ua=" . ua_snippet());

      $token      = bin2hex(random_bytes(32));
      $token_hash = hash('sha256', $token);
      $uid        = (int)$user['id'];
      $expires_at_dt = new DateTime('+1 hour');
      $expires_at = $expires_at_dt->format('Y-m-d H:i:s');

      $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
      $stmt->bind_param("iss", $uid, $token_hash, $expires_at);
      $ok = $stmt->execute();
      $stmt->close();

      if ($ok) {
        $link = $baseUrl . "/reset_password.php?token=" . urlencode($token) . "&uid=" . $uid;
        $first  = trim($user['first_name'] ?? '');
        $last   = trim($user['last_name'] ?? '');
        $toName = trim($first . ' ' . $last) ?: $user['email'];

        $subject = "Reset Password - Peter Pang Fit";
        $body = "Hi $toName,\n\n"
              . "We received a request to reset your password. This link expires in 1 hour.\n\n"
              . "$link\n\n"
              . "If you did not request this, you can ignore this email.\n\n— Peter Pang Fit";

        if (send_plain_email($user['email'], $toName, $subject, $body)) {
          $flash = 'If an account is found under the provided email, you will receive a password reset email shortly.';
          $flash_type = 'ok';
          $expiresNice = $expires_at_dt->format('Y-m-d H:i:s');
          ppf_log($conn, $uid, $user['email'], null, 'password_reset_email_sent', 'auth', null,
            "Password reset e-mail sent successfully. Link expires {$expiresNice}.; ua=" . ua_snippet());
        } else {
          $probe = smtp_probe_reason();
          $reason = $probe ?: best_effort_mail_reason();
          $flash = 'If an account is found under the provided email, you will receive a password reset email shortly.';
          $flash_type = 'ok';
          ppf_log($conn, $uid, $user['email'], null, 'password_reset_email_failed', 'auth', null,
            "Password reset e-mail failed to send. {$reason}; ua=" . ua_snippet());
        }
      } else {
        $flash = 'Could not create reset link. Please try again.';
        $flash_type = 'err';
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password · Peter Pang Fit</title>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<style>
  :root{
    --bg:#0b0c10; --panel:#12141a; --text:#e6e8ee; --muted:#9aa3b2; --brand:#3b82f6; --line:#1c212b;
  }
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{max-width:420px;margin:48px auto;padding:0 16px}
  .card{background:#151923;border:1px solid var(--line);border-radius:14px;padding:18px}
  .card h1{margin:0 0 10px 0;font-size:18px}
  .label{font-size:13px;color:#c8d1de}
  .input{
    width:100%;background:#0f1218;border:1px solid var(--line);color:#e6e8ee;
    padding:12px 14px;border-radius:10px;outline:none;box-sizing:border-box;
  }
  .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
    color:#e6e8ee;padding:10px 14px;border-radius:10px;cursor:pointer;text-decoration:none}
  .btn.brand{background:#1f2f55;border-color:#284072}
  .flash{margin:16px 0;padding:12px;border-radius:10px;border:1px solid;background:#10161a}
  .flash.ok{border-color:#204a36;color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}
</style>
</head>
<body>
  <main class="wrap">
    <div class="card">
      <h1>Forgot your password?</h1>
      <p class="muted" style="color:#9aa3b2;margin-top:6px">Enter your email and we’ll send you a reset link.</p>

      <?php if ($flash): ?>
        <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>">
          <?php echo h($flash); ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

        <div style="display:none">
          <label>Leave this blank</label>
          <input type="text" name="website" autocomplete="off" tabindex="-1">
        </div>

        <div style="display:flex;flex-direction:column;gap:8px;margin:10px 0 12px 0">
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="email" placeholder="you@example.com" required>
        </div>

        <!-- CAPTCHA -->
        <div style="text-align:center; margin:20px 0;">
          <div class="cf-turnstile"
               data-sitekey="0x4AAAAAAB4App4tTAa8fWau"
               data-theme="dark"></div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn brand" type="submit">Submit</button>
          <a class="btn" href="login.php">Back to Login</a>
        </div>
      </form>
    </div>
  </main>
</body>
</html>