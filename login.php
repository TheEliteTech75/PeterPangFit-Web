<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Show banners
$inactiveBanner = (!empty($_GET['msg']) && $_GET['msg'] === 'inactive');
$errCode        = $_GET['err'] ?? '';
$justRegistered = !empty($_GET['registered']); // show success banner after registration
$acctInactive   = (!empty($_GET['msg']) && $_GET['msg'] === 'account_inactive');

// Persisted requirement: show Turnstile only when forced by failures/honeypot
$forceCaptcha = !empty($_SESSION['force_captcha']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login - Peter Pang Fit</title>
  <?php if ($forceCaptcha): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
  <link rel="stylesheet" href="style.css">
  <style>
    .banner-inactive {
      margin: 12px auto; max-width: 420px; background: #2a1617; border: 1px solid #5b1b20;
      color: #ffb4b4; border-radius: 10px; padding: 10px 12px;
      font: 14px/1.4 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; text-align: center;
    }
    /* Success banner (for post-registration) */
    .banner-success {
      margin: 12px auto; max-width: 420px; background: #102016; border: 1px solid #1a3a2a;
      color: #a7f3d0; border-radius: 10px; padding: 10px 12px;
      font: 14px/1.4 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; text-align: center;
    }
    .login-container {
      background-color: #0F0F0F; max-width: 400px; margin: 100px auto; padding: 40px;
      border-radius: 8px; box-shadow: 0 0 10px #000;
    }
    .login-container h2 { text-align: center; color: #00BFFF; margin-bottom: 30px; }
    .login-container label { display: block; margin-bottom: 10px; color: #fff; font-weight: bold; }
    .login-container input[type="email"],
    .login-container input[type="password"] {
      width: 100%; padding: 12px; border: none; border-radius: 4px;
      margin-bottom: 20px; background-color: #1A1A1A; color: #fff;
    }
    .login-container input[type="submit"] {
      width: 100%; background-color: #00BFFF; color: #000; padding: 12px; border: none;
      border-radius: 4px; font-weight: bold; cursor: pointer; transition: background 0.3s ease;
    }
    .login-container input[type="submit"]:hover { background-color: #32CD32; }
    .login-container .error { color: #FF4C4C; margin-bottom: 15px; text-align: center; }
    .muted { color:#9aa3b2; font-size: 13px; text-align:center; margin-top: 8px; }
  </style>
</head>
<body>

<header>
  <div class="container">
    <h1 class="logo">Peter Pang Fit</h1>
    <nav><a href="index.php" class="login-btn">← Back to Home</a></nav>
  </div>
</header>

<?php if ($inactiveBanner): ?>
  <div class="banner-inactive">You were logged out due to inactivity.</div>
<?php endif; ?>

<?php if ($acctInactive): ?>
  <div class="banner-inactive">Your account is inactive. Please contact your trainer.</div>
<?php endif; ?>

<?php if ($justRegistered): ?>
  <div class="banner-success">Account created — please sign in.</div>
<?php endif; ?>

<div class="login-container">
  <h2>Client Login</h2>

  <?php if ($errCode === 'captcha'): ?>
    <div class="banner-inactive">CAPTCHA failed. Please try again.</div>
  <?php elseif ($errCode === 'locked'): ?>
    <div class="banner-inactive">Your account is locked due to too many invalid login attempts. Please try again later or contact your trainer.</div>
  <?php elseif (!empty($errCode)): ?>
    <div class="banner-inactive">Invalid email or password.</div>
  <?php endif; ?>

  <form action="login_handler.php" method="POST" id="pw-form">
    <label for="email">Email Address</label>
    <input id="login-email" type="email" name="email" required>

    <label for="password">Password</label>
    <input type="password" name="password" required>

    <!-- Honeypot: will be empty for humans -->
    <div style="display:none">
      <label>Leave this field blank</label>
      <input type="text" name="website" autocomplete="off" tabindex="-1">
    </div>

    <div style="margin-bottom:30px; text-align: center">
      <a href="forgot_password.php" style="color:#3b82f6;text-decoration:none">Forgot your password?</a>
    </div>

    <!-- Cloudflare Turnstile: shown ONLY after 2 failed attempts or honeypot -->
    <?php if ($forceCaptcha): ?>
      <div style="text-align:center; margin:20px 0;">
        <div class="cf-turnstile"
             data-sitekey="0x4AAAAAAB4App4tTAa8fWau"
             data-theme="dark"></div>
      </div>
    <?php endif; ?>

    <input type="submit" value="Log In">

    <div style="margin-top:12px">
      <button type="button" id="btn-passkey" style="width:100%; background:#374151; color:#fff; padding:12px; border:0; border-radius:4px; font-weight:600; cursor:pointer">
        Login with Passkey
      </button>
    </div>
    <div id="passkey-error" class="error" style="display:none;margin-top:10px">Invalid passkey.</div>
  </form>
</div>

<script>
// ===== Helpers =====
const $err = document.getElementById('passkey-error');
const $email = document.getElementById('login-email');

function b64urlToBytes(b64url) {
  const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/')
                    .padEnd(Math.ceil(b64url.length / 4) * 4, '=');
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes;
}
function bytesToB64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
  return btoa(s);
}
function bytesToB64url(bytes) {
  return bytesToB64(bytes).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}

// ===== Passkey login (email-first) =====
document.getElementById('btn-passkey')?.addEventListener('click', async ()=>{
  $err.style.display = 'none';
  try{
    const emailVal = ($email.value || '').trim().toLowerCase();
    if (!emailVal) throw new Error('Please enter your email address first.');

    const beginRes = await fetch('passkey_begin_login.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'email='+encodeURIComponent(emailVal)
    });
    const begin = await beginRes.json();
    if (!begin.ok) throw new Error(begin.error || 'init failed');

    const pubKey = begin.publicKey;
    pubKey.challenge = b64urlToBytes(pubKey.challenge);
    if (Array.isArray(pubKey.allowCredentials)) {
      pubKey.allowCredentials = pubKey.allowCredentials.map(c => ({
        type: c.type || 'public-key',
        id: b64urlToBytes(c.id),
        transports: c.transports || ['internal','hybrid','usb','nfc','ble']
      }));
    }

    pubKey.authenticatorSelection = { authenticatorAttachment: 'platform' };

    const cred = await navigator.credentials.get({ publicKey: pubKey });
    if (!cred) throw new Error('No credential selected.');

    const payload = new URLSearchParams();
    payload.set('clientDataJSON', bytesToB64(new Uint8Array(cred.response.clientDataJSON)));
    payload.set('authenticatorData', bytesToB64(new Uint8Array(cred.response.authenticatorData)));
    payload.set('signature', bytesToB64(new Uint8Array(cred.response.signature)));
    payload.set('credentialId', bytesToB64url(new Uint8Array(cred.rawId)));
    payload.set('userHandle', cred.response.userHandle ? bytesToB64(new Uint8Array(cred.response.userHandle)) : '');

    const finRes = await fetch('passkey_finish_login.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: payload
    });
    const fin = await finRes.json();
    if (!fin.ok) throw new Error(fin.error || 'verify failed');

    window.location = fin.redirect || 'dashboard.php';
  } catch(e){
    console.error(e);
    // surface the real message from the server so we know what's failing
    $err.textContent = e && e.message ? e.message : 'Invalid passkey.';
    $err.style.display = 'block';
  }
});
</script>
</body>
</html>