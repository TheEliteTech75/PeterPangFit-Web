<?php
// reset_password.php
// Validates the token, then allows setting a new password with live requirements.

require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$uid   = isset($_GET['uid']) ? (int)$_GET['uid'] : (int)($_POST['uid'] ?? 0);
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token_hash = $token ? hash('sha256', $token) : '';

$valid = false; $user = null; $reset_row = null; $flash = null; $flash_type = 'err';

// Load token + user if present
if ($uid && $token_hash) {
  $stmt = $conn->prepare("
    SELECT pr.id, pr.user_id, pr.token_hash, pr.expires_at, pr.used_at, u.email, u.first_name, u.last_name
    FROM password_resets pr
    JOIN users u ON u.id = pr.user_id
    WHERE pr.user_id = ? AND pr.token_hash = ? 
    ORDER BY pr.id DESC
    LIMIT 1
  ");
  $stmt->bind_param("is", $uid, $token_hash);
  $stmt->execute();
  $res = $stmt->get_result();
  $reset_row = $res->fetch_assoc();
  $stmt->close();

  if ($reset_row) {
    $now = new DateTimeImmutable('now');
    $exp = new DateTimeImmutable($reset_row['expires_at']);
    $valid = empty($reset_row['used_at']) && ($exp > $now);
    $user = [
      'id' => (int)$reset_row['user_id'],
      'email' => $reset_row['email'],
      'first_name' => $reset_row['first_name'] ?? '',
      'last_name'  => $reset_row['last_name'] ?? '',
    ];
  }
}

function password_meets_requirements(string $pwd, array $user): ?string {
  if (strlen($pwd) < 12) return 'Password must be at least 12 characters.';
  if (!preg_match('/[A-Z]/', $pwd) || !preg_match('/\d/', $pwd) || !preg_match('/[^A-Za-z0-9]/', $pwd)) {
    return 'Password must include at least one capital letter, one number, and one special character.';
  }
  $lower = mb_strtolower($pwd);
  $email = mb_strtolower($user['email'] ?? '');
  $fn = mb_strtolower($user['first_name'] ?? '');
  $ln = mb_strtolower($user['last_name'] ?? '');
  if ($email && strpos($lower, $email) !== false) return 'Password cannot contain your email.';
  if ($fn && strpos($lower, $fn) !== false) return 'Password cannot contain your name.';
  if ($ln && strpos($lower, $ln) !== false) return 'Password cannot contain your name.';
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Invalid session. Please try again.'; $flash_type = 'err';
  } else {
    $new = (string)($_POST['new_password'] ?? '');
    $rep = (string)($_POST['confirm_password'] ?? '');
    if (!$valid) {
      $flash = 'Reset link is invalid or has expired.'; $flash_type = 'err';
    } elseif ($new !== $rep) {
      $flash = 'Passwords must match.'; $flash_type = 'err';
    } else {
      // Validate policy
      $msg = password_meets_requirements($new, $user);
      if ($msg !== null) {
        $flash = $msg; $flash_type = 'err';
      } else {
        // Update user's password. Adjust column name to your schema if needed.
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param("si", $hash, $user['id']);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
          // Mark token as used; optionally clear all other tokens for this user.
          $now = (new DateTime())->format('Y-m-d H:i:s');
          $stmt = $conn->prepare("UPDATE password_resets SET used_at = ? WHERE id = ? LIMIT 1");
          $stmt->bind_param("si", $now, $reset_row['id']);
          $stmt->execute();
          $stmt->close();

          // (Optional) destroy sessions for this user here.

          $flash = 'Your password has been reset. You can now log in with your new password.'; 
          $flash_type = 'ok';
          // After success, you might want to redirect to login:
          // header('Location: login.php?reset=1'); exit;
        } else {
          $flash = 'Failed to update password. Please try again.'; $flash_type = 'err';
        }
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
<title>Reset Password · Peter Pang Fit</title>
<style>
  :root{
    color-scheme:dark;
    --bg:#05070d; --bg-alt:#03040a; --panel:rgba(9,14,28,0.92); --text:#f8fafc; --muted:#cbd5f5; --brand:#38bdf8; --line:rgba(148,163,184,0.18); --ok:#10b981; --warn:#ef4444;
  }
  html,body{margin:0;padding:0;background:
      radial-gradient(circle at top left, rgba(56,189,248,0.18), transparent 55%),
      radial-gradient(circle at bottom right, rgba(110,231,183,0.12), transparent 60%),
      linear-gradient(155deg, var(--bg), var(--bg-alt));
    color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{max-width:480px;margin:48px auto;padding:0 16px}
  .card{background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:18px}
  .card h1{margin:0 0 10px 0;font-size:18px}
  .label{font-size:13px;color:#c8d1de}
  .input{width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:var(--text);padding:12px 14px;border-radius:10px;outline:none;box-sizing:border-box;}
  .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);color:var(--text);padding:10px 14px;border-radius:10px;cursor:pointer;text-decoration:none}
  .btn.brand{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
  .flash{margin:16px 0;padding:12px;border-radius:10px;border:1px solid;background:rgba(8,13,23,0.85)}
  .flash.ok{border-color:rgba(34,197,94,0.45);color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}
  .req{font-size:13px;margin:6px 0 0 0}
  .req li{margin:6px 0;list-style:none;padding-left:22px;position:relative;color:#fca5a5}
  .req li::before{content:'•';position:absolute;left:8px;top:0.2rem;opacity:.7}
  .req li.ok{color:#a7f3d0}
</style>
</head>
<body>
  <main class="wrap">
    <div class="card">
      <h1>Reset your password</h1>

      <?php if (!$token || !$uid || !$reset_row): ?>
        <div class="flash err">Reset link is invalid.</div>
      <?php elseif (!$valid && !$flash): ?>
        <div class="flash err">Reset link has expired or has already been used.</div>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>">
          <?php echo h($flash); ?>
        </div>
      <?php endif; ?>

      <?php if ($valid): ?>
        <form method="post" autocomplete="off" novalidate id="resetForm">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="uid" value="<?php echo (int)$uid; ?>">
          <input type="hidden" name="token" value="<?php echo h($token); ?>">

          <div style="display:flex;flex-direction:column;gap:8px;margin:10px 0 0 0">
            <label class="label" for="new_password">New Password</label>
            <input class="input" id="new_password" name="new_password" type="password" required>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;margin:10px 0 0 0">
            <label class="label" for="confirm_password">Confirm Password</label>
            <input class="input" id="confirm_password" name="confirm_password" type="password" required>
          </div>

          <ul class="req" id="reqList">
            <li id="rule-match">Passwords must match</li>
            <li id="rule-length">Password must be at least 12 characters.</li>
            <li id="rule-mix">Password must contain at least one capital letter, one number, and one special character.</li>
            <li id="rule-personal">Password cannot contain your name or email.</li>
          </ul>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
            <button class="btn brand" type="submit">Update Password</button>
            <a class="btn" href="login.php">Back to Login</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </main>

<script>
(function(){
  const np = document.getElementById('new_password');
  const cp = document.getElementById('confirm_password');
  const ruleMatch   = document.getElementById('rule-match');
  const ruleLength  = document.getElementById('rule-length');
  const ruleMix     = document.getElementById('rule-mix');
  const rulePersonal= document.getElementById('rule-personal');

  // Values used for rule 4
  const userEmail = <?php echo json_encode(mb_strtolower($user['email'] ?? '')); ?>;
  const userName  = <?php 
    $nm = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    echo json_encode(mb_strtolower($nm));
  ?>;

  function meetsRules() {
    const a = np.value || '';
    const b = cp.value || '';
    const lower = a.toLowerCase();

    // 1) match
    toggle(ruleMatch, a !== '' && a === b);

    // 2) length
    toggle(ruleLength, a.length >= 12);

    // 3) at least 1 uppercase, 1 number, 1 special
    const mix = /[A-Z]/.test(a) && /\d/.test(a) && /[^A-Za-z0-9]/.test(a);
    toggle(ruleMix, mix);

    // 4) not contain name or email
    let personalOK = true;
    if (userEmail && lower.includes(userEmail)) personalOK = false;
    if (userName && userName.trim() && lower.includes(userName)) personalOK = false;
    toggle(rulePersonal, personalOK);
  }

  function toggle(el, ok){
    if (!el) return;
    if (ok) el.classList.add('ok'); else el.classList.remove('ok');
  }

  if (np && cp) {
    np.addEventListener('input', meetsRules);
    cp.addEventListener('input', meetsRules);
  }
})();
</script>
</body>
</html>
