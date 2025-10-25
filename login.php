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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - Peter Pang Fit</title>
  <?php if ($forceCaptcha): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: dark;
      --bg: #05070d;
      --bg-alt: #03040a;
      --surface: rgba(9, 14, 28, 0.92);
      --surface-strong: rgba(15, 23, 42, 0.94);
      --surface-soft: rgba(30, 41, 59, 0.35);
      --border: rgba(148, 163, 184, 0.26);
      --border-strong: rgba(56, 189, 248, 0.55);
      --primary: #6ee7b7;
      --primary-strong: #22d3a2;
      --accent: #38bdf8;
      --danger: #f87171;
      --text: #f8fafc;
      --muted: #9ba4c2;
      --muted-strong: #cbd5f5;
      --shadow-lg: 0 34px 60px rgba(2, 6, 23, 0.55);
      font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.18), transparent 55%),
        radial-gradient(circle at bottom right, rgba(110, 231, 183, 0.12), transparent 60%),
        linear-gradient(160deg, var(--bg), var(--bg-alt));
      color: var(--text);
      font-family: inherit;
      -webkit-font-smoothing: antialiased;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    header.site-header {
      position: sticky;
      top: 0;
      z-index: 50;
      backdrop-filter: blur(18px);
      background: rgba(2, 6, 23, 0.88);
      border-bottom: 1px solid var(--surface-soft);
    }

    .header-inner {
      max-width: 960px;
      margin: 0 auto;
      padding: 18px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .brand {
      font-size: clamp(1.35rem, 1.6vw + 1rem, 1.85rem);
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--text);
    }

    .header-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--surface-soft), rgba(30, 64, 175, 0.35));
      border: 1px solid rgba(148, 163, 184, 0.28);
      color: var(--muted-strong);
      font-weight: 600;
      transition: transform 0.25s ease, border-color 0.25s ease, color 0.25s ease;
    }

    .header-link:hover,
    .header-link:focus {
      transform: translateY(-1px);
      border-color: var(--border-strong);
      color: var(--text);
    }

    main {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(32px, 6vw, 72px) 24px;
      scroll-margin-top: 90px;
    }

    .auth-panel {
      width: min(420px, 100%);
      background: var(--surface);
      border: 1px solid rgba(148, 163, 184, 0.18);
      border-radius: 28px;
      padding: clamp(28px, 5vw, 48px);
      box-shadow: var(--shadow-lg);
      backdrop-filter: blur(24px);
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .auth-panel header {
      display: flex;
      flex-direction: column;
      gap: 8px;
      text-align: center;
    }

    .auth-panel h1 {
      font-size: clamp(1.55rem, 1.2vw + 1.2rem, 2rem);
      margin: 0;
      font-weight: 700;
      color: var(--text);
    }

    .auth-panel p {
      margin: 0;
      color: var(--muted);
      font-size: 0.95rem;
      line-height: 1.5;
    }

    .banner-inactive,
    .banner-success {
      background: rgba(248, 113, 113, 0.08);
      border: 1px solid rgba(248, 113, 113, 0.35);
      color: #fecaca;
      border-radius: 18px;
      padding: 12px 16px;
      font-size: 0.9rem;
      text-align: center;
      font-weight: 500;
    }

    .banner-success {
      background: rgba(34, 211, 162, 0.12);
      border-color: rgba(110, 231, 183, 0.45);
      color: #bbf7d0;
    }

    form {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    label {
      display: block;
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--muted-strong);
    }

    input[type="email"],
    input[type="password"] {
      width: 100%;
      padding: 14px 16px;
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      background: rgba(15, 23, 42, 0.65);
      color: var(--text);
      font-size: 1rem;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    input[type="email"]:focus,
    input[type="password"]:focus {
      outline: none;
      border-color: var(--border-strong);
      box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
    }

    .form-actions {
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-top: 10px;
    }

    .forgot-link {
      color: var(--accent);
      font-size: 0.9rem;
      font-weight: 600;
      text-align: center;
    }

    .forgot-link:hover,
    .forgot-link:focus {
      color: var(--primary);
    }

    input[type="submit"] {
      width: 100%;
      padding: 14px;
      border-radius: 18px;
      border: none;
      font-weight: 700;
      font-size: 1rem;
      cursor: pointer;
      background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
      color: #02131f;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    input[type="submit"]:hover,
    input[type="submit"]:focus {
      transform: translateY(-1px);
      box-shadow: 0 16px 40px rgba(56, 189, 248, 0.35);
    }

    #btn-passkey {
      width: 100%;
      padding: 14px;
      border-radius: 18px;
      border: 1px solid rgba(148, 163, 184, 0.3);
      background: rgba(148, 163, 184, 0.12);
      color: var(--muted-strong);
      font-weight: 700;
      cursor: pointer;
      transition: border-color 0.25s ease, color 0.25s ease, background 0.25s ease;
    }

    #btn-passkey:hover,
    #btn-passkey:focus {
      border-color: var(--border-strong);
      color: var(--text);
      background: rgba(56, 189, 248, 0.16);
    }

    .error {
      color: var(--danger);
      font-size: 0.9rem;
      text-align: center;
    }

    @media (max-width: 520px) {
      body {
        min-height: 100dvh;
      }

      header.site-header {
        position: static;
      }

      .header-inner {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
        gap: 12px;
        padding: 16px;
      }

      .header-link {
        justify-content: center;
      }

      main {
        align-items: stretch;
        justify-content: flex-start;
        padding: 24px 18px 48px;
      }

      .auth-panel {
        border-radius: 22px;
        padding: 24px 20px 32px;
        gap: 20px;
        box-shadow: 0 24px 50px rgba(2, 6, 23, 0.55);
      }

      .auth-panel header h1 {
        font-size: 1.55rem;
      }

      .auth-panel p {
        font-size: 0.92rem;
      }

      label {
        font-size: 0.88rem;
      }

      input[type="email"],
      input[type="password"] {
        font-size: 0.95rem;
        padding: 13px 14px;
      }

      input[type="submit"],
      #btn-passkey {
        font-size: 0.95rem;
        padding: 13px;
      }
    }
  </style>
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="index.php" class="brand">Peter Pang Fit</a>
    <a class="header-link" href="index.php">← Back to Home</a>
  </div>
</header>

<main>
  <div class="auth-panel">
    <header>
      <h1>Client Login</h1>
      <p>Access your personalized plans, progress tracking, and training updates.</p>
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

    <?php if ($errCode === 'captcha'): ?>
      <div class="banner-inactive">CAPTCHA failed. Please try again.</div>
    <?php elseif ($errCode === 'locked'): ?>
      <div class="banner-inactive">Your account is locked due to too many invalid login attempts. Please try again later or contact your trainer.</div>
    <?php elseif (!empty($errCode)): ?>
      <div class="banner-inactive">Invalid email or password.</div>
    <?php endif; ?>

    <form action="login_handler.php" method="POST" id="pw-form">
      <div>
        <label for="email">Email Address</label>
        <input id="login-email" type="email" name="email" required>
      </div>

      <div>
        <label for="password">Password</label>
        <input type="password" name="password" required>
      </div>

      <!-- Honeypot: will be empty for humans -->
      <div style="display:none">
        <label>Leave this field blank</label>
        <input type="text" name="website" autocomplete="off" tabindex="-1">
      </div>

      <div class="form-actions">
        <a class="forgot-link" href="forgot_password.php">Forgot your password?</a>

        <!-- Cloudflare Turnstile: shown ONLY after 2 failed attempts or honeypot -->
        <?php if ($forceCaptcha): ?>
          <div style="text-align:center;">
            <div class="cf-turnstile"
                 data-sitekey="0x4AAAAAAB4App4tTAa8fWau"
                 data-theme="dark"></div>
          </div>
        <?php endif; ?>

        <input type="submit" value="Log In">

        <button type="button" id="btn-passkey">Login with Passkey</button>
      </div>

      <div id="passkey-error" class="error" style="display:none;">Invalid passkey.</div>
    </form>
  </div>
</main>

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