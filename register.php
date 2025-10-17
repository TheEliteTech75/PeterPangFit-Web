<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php'; // for ppf_log() and ppf_client_ip()
require_once __DIR__ . '/ppf_header_guest.php';

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// --- system_settings helpers (same as in settings.php, tiny duplicates for isolation) ---
function ensure_system_settings_table(mysqli $conn): void {
  @$conn->query("
    CREATE TABLE IF NOT EXISTS system_settings (
      `key` VARCHAR(100) NOT NULL PRIMARY KEY,
      `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}
function get_setting(mysqli $conn, string $key, ?string $default=null): ?string {
  ensure_system_settings_table($conn);
  if (!$stmt = $conn->prepare("SELECT `value` FROM system_settings WHERE `key`=? LIMIT 1")) return $default;
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ? (string)$row['value'] : $default;
}

// ----------------------------------------------
// Validate/resolve token
// ----------------------------------------------
$token = $_GET['token'] ?? '';
$inv   = null;
$error = null;

// Allow test registration token (admin-configured)
$testEnabled = get_setting($conn, 'test_register_token_enabled', '0') === '1';
$testToken   = get_setting($conn, 'test_register_token_value', '') ?: '';

$isTestBypass = $testEnabled && $token !== '' && hash_equals($testToken, $token);

if ($isTestBypass) {
  // Populate a pseudo invite shape so the form renders
  $inv = [
    'first_name' => '',
    'middle_name'=> '',
    'last_name'  => '',
    'phone'      => '',
    'email'      => '',
    'birthdate'  => '',
    'gender'     => '',
    'height_ft'  => '',
    'height_in'  => '',
    'weight_lbs' => '',
  ];
} else {
  // Normal flow: strict token format required (64 hex)
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid or expired invite token. Please request a new invite from your trainer.';
  } else {
    // Fetch invite + user
    $sql = "SELECT i.id AS invite_id, i.used, i.expires_at, i.cancelled_at, i.token, u.*
            FROM invites i
            JOIN users u ON u.id = i.user_id
            WHERE i.token = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
	  
	  // after: $isExpired / $isUsed / $isCancelled checks pass
ensure_invite_columns($conn);

// Set accepted_at only once (idempotent)
if ($upd = $conn->prepare("UPDATE invites SET accepted_at = COALESCE(accepted_at, NOW()) WHERE token = ? AND accepted_at IS NULL")) {
  $upd->bind_param("s", $token);
  $upd->execute();
  $upd->close();
}

    if ($res->num_rows === 0) {
      $error = 'Invalid or expired invite token. Please request a new invite from your trainer.';
    } else {
      $inv = $res->fetch_assoc();

      // Expired?
      $isExpired   = (!empty($inv['expires_at']) && strtotime($inv['expires_at']) <= time());
      $isUsed      = ((int)($inv['used'] ?? 0) === 1);
      $isCancelled = !empty($inv['cancelled_at']);

      if ($isExpired) {
        // Log invite_link_expired once user hits the page
        $emailForLog = (string)($inv['email'] ?? '');
        $details = "token={$inv['token']}; original_expires_at={$inv['expires_at']}";
        ppf_log($conn, null, $emailForLog ?: null, null, 'invite_link_expired', 'invite', null, $details);
      }

      if ($isExpired || $isUsed || $isCancelled) {
        $error = 'Invalid or expired invite token. Please request a new invite from your trainer.';
      }
    }
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Complete Registration - Peter Pang Fit</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b0c10; --panel:#12141a; --text:#e6e8ee; --brand:#3b82f6;
    --line:#1c212b; --err-border:#4a2020; --err-text:#fca5a5;
  }
  body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:var(--bg); color:var(--text); }
  .wrap { max-width: 900px; margin: 60px auto; background:var(--panel); padding:32px; border-radius:8px; }
  h1 { margin-top:0; color:var(--brand); }
  label { display:block; margin:12px 0 6px; }
  input, select {
    width:100%; padding:12px; border:0; border-radius:6px; background:#1a1a1a; color:#fff; box-sizing:border-box;
  }
  .grid { display:grid; grid-template-columns: repeat(12, 1fr); gap:12px; }
  .span-12{grid-column:span 12} .span-6{grid-column:span 6} .span-4{grid-column:span 4} .span-3{grid-column:span 3}
  .btn { margin-top:16px; background:#00BFFF; color:#000; padding:12px 16px; border:0; border-radius:6px; font-weight:bold; cursor:pointer; }
  .btn:hover { background:#32CD32; }
  .hint { color:#9aa3b2; font-size:.9rem; }
  .flash.err {
    margin: 0 0 18px 0; padding:12px; border-radius:10px;
    border:1px solid var(--err-border); color:var(--err-text); background:#10161a; font-size:14px;
  }
  .badge { display:inline-block; margin-bottom:10px; padding:4px 8px; border-radius:999px; font-size:12px; background:#1c2838; color:#a5c4ff; border:1px solid #223048; }

  /* Password requirements list (same look/feel as profile.php) */
  ul.req{font-size:13px;margin:8px 0 0 0;padding-left:0}
  ul.req li{margin:6px 0;list-style:none;padding-left:22px;position:relative;color:#fca5a5}
  ul.req li::before{content:'•';position:absolute;left:8px;top:0.2rem;opacity:.7}
  ul.req li.ok{color:#a7f3d0}
</style>
</head>
<body>
<div class="wrap">
  <h1>Complete Your Registration</h1>

  <?php if ($error): ?>
    <div class="flash err"><?php echo h($error); ?></div>
    <p class="hint">Please contact your trainer to request a new registration link.</p>
  <?php else: ?>
    <?php if ($isTestBypass): ?>
      <div class="badge">Test Token Mode (no invite)</div>
    <?php endif; ?>

    <p class="hint">Review and update your details, then set a password.</p>

    <form action="register_submit.php" method="POST" autocomplete="off" class="grid" id="reg_form">
      <input type="hidden" name="token" value="<?php echo h($token); ?>">

      <!-- Honeypot (bots fill visible-ish, humans never) -->
      <div style="display:none">
        <label>Leave this field blank</label>
        <input type="text" name="website" autocomplete="off" tabindex="-1">
      </div>

      <div class="span-6">
        <label for="reg_first">First Name</label>
        <input id="reg_first" name="first_name" value="<?php echo h($inv['first_name']); ?>" required>
      </div>

      <div class="span-6">
        <label for="reg_middle">Middle Name</label>
        <input id="reg_middle" name="middle_name" value="<?php echo h($inv['middle_name'] ?? ''); ?>">
      </div>

      <div class="span-6">
        <label for="reg_last">Last Name</label>
        <input id="reg_last" name="last_name" value="<?php echo h($inv['last_name']); ?>" required>
      </div>

      <div class="span-6">
        <label for="reg_phone">Phone</label>
        <input id="reg_phone" name="phone" value="<?php echo h($inv['phone'] ?? ''); ?>">
      </div>

      <div class="span-6">
        <label for="reg_email">Email</label>
        <input id="reg_email" type="email" name="email" value="<?php echo h($inv['email']); ?>" required>
      </div>

      <div class="span-3">
        <label for="reg_birthdate">Birthdate</label>
        <input id="reg_birthdate" type="date" name="birthdate" value="<?php echo h($inv['birthdate'] ?? ''); ?>">
      </div>

      <div class="span-3">
        <label for="reg_gender">Gender</label>
        <select id="reg_gender" name="gender">
          <option value="">--</option>
          <option value="male"   <?php echo (($inv['gender'] ?? '')==='male')?'selected':''; ?>>Male</option>
          <option value="female" <?php echo (($inv['gender'] ?? '')==='female')?'selected':''; ?>>Female</option>
        </select>
      </div>

      <div class="span-3">
        <label for="reg_height_ft">Height (ft)</label>
        <input id="reg_height_ft" type="number" name="height_ft" min="0" max="8" value="<?php echo h($inv['height_ft'] ?? ''); ?>">
      </div>
      <div class="span-3">
        <label for="reg_height_in">Height (in)</label>
        <input id="reg_height_in" type="number" name="height_in" min="0" max="11" value="<?php echo h($inv['height_in'] ?? ''); ?>">
      </div>

      <div class="span-3">
        <label for="reg_weight">Weight (lbs)</label>
        <input id="reg_weight" type="number" name="weight_lbs" step="0.01" value="<?php echo h($inv['weight_lbs'] ?? ''); ?>">
      </div>

      <div class="span-6">
        <label for="reg_password">Create Password</label>
        <input type="password" name="password" id="reg_password" required>
        <ul class="req" id="reg_req_main">
          <li id="reg_rule_length">Must be at least 12 characters.</li>
          <li id="reg_rule_mix">Must contain at least one capital letter, one number, and one special character.</li>
          <li id="reg_rule_personal">Does not contain your name or email.</li>
        </ul>
      </div>

      <div class="span-6">
        <label for="reg_confirm">Confirm Password</label>
        <input type="password" name="password_confirm" id="reg_confirm" required>
        <ul class="req" style="margin-top:8px">
          <li id="reg_rule_match">Passwords must match</li>
        </ul>
      </div>

      <div class="span-12">
        <button class="btn" type="submit">Create Account</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  // Elements
  const form    = document.getElementById('reg_form');
  const first   = document.getElementById('reg_first');
  const last    = document.getElementById('reg_last');
  const email   = document.getElementById('reg_email');
  const pwd     = document.getElementById('reg_password');
  const conf    = document.getElementById('reg_confirm');

  const ruleLen   = document.getElementById('reg_rule_length');
  const ruleMix   = document.getElementById('reg_rule_mix');
  const rulePers  = document.getElementById('reg_rule_personal');
  const ruleMatch = document.getElementById('reg_rule_match');

  function toggle(el, ok){ if(!el) return; el.classList.toggle('ok', !!ok); }

  // Build all 3+ char fragments from tokens (same logic as profile.php, with a 16-char cap)
  function buildFragmentsFrom(token){
    const out = new Set();
    const t = (token || '').toLowerCase().replace(/[^a-z0-9]/g,'');
    const n = t.length;
    if (n < 3) return out;
    for (let i=0; i<=n-3; i++){
      for (let len=3; len<=Math.min(16, n-i); len++){
        out.add(t.substring(i, i+len));
      }
    }
    return out;
  }

  function personalFragments(){
    const set = new Set();
    const addAll = (s) => buildFragmentsFrom(s).forEach(x => set.add(x));
    addAll(first?.value);
    addAll(last?.value);
    // email: split on non-alnum and add each part
    const e = (email?.value || '').toLowerCase();
    e.split(/[^a-z0-9]+/g).forEach(addAll);
    return set;
  }

  function evaluate(){
    const pRaw = pwd?.value || '';
    const p    = pRaw.toLowerCase();
    const c    = (conf?.value || '').toLowerCase();

    // length
    const okLen = pRaw.length >= 12;
    toggle(ruleLen, okLen);

    // mix (capital, digit, special) — check against RAW
    const okMix = /[A-Z]/.test(pRaw) && /\d/.test(pRaw) && /[^A-Za-z0-9]/.test(pRaw);
    toggle(ruleMix, okMix);

    // personal fragments
    let okPersonal = true;
    const frags = personalFragments();
    for (const frag of frags){ if (frag && p.includes(frag)) { okPersonal = false; break; } }
    toggle(rulePers, okPersonal);

    // match
    const okMatch = p !== '' && p === c;
    toggle(ruleMatch, okMatch);

    return (okLen && okMix && okPersonal && okMatch);
  }

  // Live updates
  [first,last,email,pwd,conf].forEach(el => el && el.addEventListener('input', evaluate));

  // Gate submit if requirements fail
  form?.addEventListener('submit', function(e){
    if (!evaluate()){
      e.preventDefault();
      // Focus the most likely offending field
      if (!(pwd?.value || '').length) { pwd?.focus(); return; }
      if (!/[A-Z]/.test(pwd.value) || !/\d/.test(pwd.value) || !/[^A-Za-z0-9]/.test(pwd.value)) { pwd?.focus(); return; }
      if ((conf?.value || '') !== (pwd?.value || '')) { conf?.focus(); return; }
      pwd?.focus();
    }
  });

  // initial paint
  evaluate();
})();
</script>
</body>
</html>