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
    --muted-soft: rgba(148, 163, 184, 0.72);
    --shadow-lg: 0 34px 60px rgba(2, 6, 23, 0.55);
    font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  *, *::before, *::after {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    font-family: inherit;
    color: var(--text);
    background:
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.18), transparent 55%),
      radial-gradient(circle at bottom right, rgba(110, 231, 183, 0.12), transparent 60%),
      linear-gradient(160deg, var(--bg), var(--bg-alt));
    -webkit-font-smoothing: antialiased;
  }

  body.modal-open {
    overflow: hidden;
  }

  .wrap {
    max-width: 960px;
    margin: clamp(40px, 5vw, 72px) auto;
    background: var(--surface);
    padding: clamp(28px, 5vw, 48px);
    border-radius: 28px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg);
    backdrop-filter: blur(22px);
  }

  h1 {
    margin-top: 0;
    color: var(--accent);
    font-size: clamp(1.65rem, 1.2vw + 1.2rem, 2.1rem);
    letter-spacing: -0.01em;
  }

  label {
    display: block;
    margin: 12px 0 6px;
    color: var(--muted-strong);
    font-weight: 600;
    font-size: 0.92rem;
  }

  input,
  select {
    width: 100%;
    padding: 13px 14px;
    border-radius: 16px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    background: rgba(15, 23, 42, 0.65);
    color: var(--text);
    font-size: 1rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  input:focus,
  select:focus {
    outline: none;
    border-color: var(--border-strong);
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 16px;
  }

  .span-12 { grid-column: span 12; }
  .span-6 { grid-column: span 6; }
  .span-4 { grid-column: span 4; }
  .span-3 { grid-column: span 3; }

  .btn {
    margin-top: 16px;
    padding: 14px 18px;
    border: none;
    border-radius: 18px;
    font-weight: 700;
    cursor: pointer;
    background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
    color: #02131f;
    box-shadow: 0 16px 40px rgba(56, 189, 248, 0.35);
    transition: transform 0.25s ease, box-shadow 0.25s ease, opacity 0.2s ease;
  }

  .btn:hover,
  .btn:focus {
    transform: translateY(-1px);
    box-shadow: 0 20px 48px rgba(110, 231, 183, 0.38);
  }

  .btn[disabled] {
    cursor: not-allowed;
    opacity: 0.6;
    background: rgba(148, 163, 184, 0.22);
    color: var(--muted);
    box-shadow: none;
    transform: none;
  }

  .btn-link {
    background: transparent;
    color: var(--muted-strong);
    box-shadow: none;
    padding: 10px 16px;
  }

  .btn-link:hover,
  .btn-link:focus {
    color: var(--text);
    box-shadow: none;
    transform: none;
  }

  .hint {
    color: var(--muted);
    font-size: 0.95rem;
    line-height: 1.55;
  }

  .flash.err {
    margin: 0 0 18px 0;
    padding: 14px 16px;
    border-radius: 18px;
    border: 1px solid rgba(248, 113, 113, 0.32);
    color: #fecaca;
    background: rgba(248, 113, 113, 0.12);
    font-size: 0.92rem;
    font-weight: 600;
  }

  .badge {
    display: inline-block;
    margin-bottom: 14px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 0.78rem;
    letter-spacing: 0.04em;
    background: var(--surface-soft);
    color: var(--muted-strong);
    border: 1px solid rgba(148, 163, 184, 0.32);
  }

  /* Password requirements list (same look/feel as profile.php) */
  ul.req{font-size:13px;margin:8px 0 0 0;padding-left:0}
  ul.req li{margin:6px 0;list-style:none;padding-left:22px;position:relative;color:var(--danger);transition:color 0.2s ease}
  ul.req li::before{content:'•';position:absolute;left:8px;top:0.2rem;opacity:.7}
  ul.req li.ok{color:#bbf7d0}

  .modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.78);
    backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    z-index: 1000;
  }

  .modal-backdrop[hidden] { display: none; }

  .modal {
    max-width: min(840px, 92vw);
    max-height: min(80vh, 720px);
    width: 100%;
    background: var(--surface-strong);
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    box-shadow: 0 30px 60px rgba(2, 6, 23, 0.55);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .modal header {
    padding: 24px clamp(24px, 4vw, 36px) 12px;
  }

  .modal header h2 {
    margin: 0;
    font-size: 1.2rem;
    color: var(--accent);
  }

  .modal .modal-body {
    padding: 0 clamp(24px, 4vw, 36px);
    overflow-y: auto;
    flex: 1 1 auto;
    scroll-behavior: smooth;
  }

  .modal .modal-body p,
  .modal .modal-body li {
    color: var(--muted);
    line-height: 1.6;
  }

  .modal .modal-body h3 {
    color: var(--muted-strong);
    margin-top: 24px;
    font-size: 1.05rem;
  }

  .modal footer {
    padding: 20px clamp(24px, 4vw, 36px) 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: flex-end;
    background: rgba(15, 23, 42, 0.45);
    border-top: 1px solid rgba(148, 163, 184, 0.2);
  }

  .modal footer .btn {
    margin-top: 0;
  }

  @media (max-width: 900px) {
    .wrap {
      margin: clamp(24px, 6vw, 48px) 16px;
      border-radius: 22px;
    }

    .grid {
      gap: 14px;
    }
  }

  @media (max-width: 768px) {
    .grid {
      grid-template-columns: repeat(12, 1fr);
    }

    .span-6,
    .span-4,
    .span-3 {
      grid-column: span 12;
    }
  }
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
        <button class="btn" type="submit" id="reg_submit_btn">Register</button>
      </div>
    </form>

    <div class="modal-backdrop" id="privacy_modal" role="dialog" aria-modal="true" aria-labelledby="privacy_modal_title" hidden>
      <div class="modal">
        <header>
          <h2 id="privacy_modal_title">Privacy Policy</h2>
          <p class="hint" style="margin-top:8px">Please review the full policy below. You must scroll to the end before registering.</p>
        </header>
        <div class="modal-body" id="privacy_modal_body" tabindex="0">
          <p><strong>Last Updated:</strong> October 29, 2025<br><strong>Effective Date:</strong> October 29, 2025</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>1. Introduction</h3>
          <p>Welcome to Pangsanity Personal Training (&ldquo;Company,&rdquo; &ldquo;we,&rdquo; &ldquo;our,&rdquo; or &ldquo;us&rdquo;). We respect your privacy and are committed to protecting your personal data. This Privacy Policy explains how we collect, use, store, and safeguard your information when you register for and use our platform, PeterPangFit (&ldquo;Service&rdquo;).</p>
          <p>By creating an account or using our Service, you agree to the terms of this Privacy Policy.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>2. Information We Collect</h3>
          <p><strong>a. Personal Information</strong><br>When you register for an account or update your profile, we collect:</p>
          <ul>
            <li>First Name, Middle Name or Initial, and Last Name</li>
            <li>Phone Number</li>
            <li>Email Address</li>
            <li>Date of Birth</li>
            <li>Gender</li>
            <li>Height and Weight</li>
          </ul>
          <p>If the account is for a minor, this information is collected only with the consent of a parent or legal guardian.</p>
          <p><strong>b. Device &amp; Login Information</strong><br>For account security and fraud prevention, we automatically collect:</p>
          <ul>
            <li>IP Address</li>
            <li>Browser Type and Version</li>
            <li>Operating System and Device Type</li>
            <li>User Agent String</li>
            <li>Login Activity (timestamps, location approximation, and device information)</li>
          </ul>
          <p>We also support enhanced authentication options such as Passkeys, Email Verification, and App-Based Two-Factor Authentication (2FA).</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>3. How We Use Your Information</h3>
          <p>We use collected information to:</p>
          <ul>
            <li>Create and maintain user accounts</li>
            <li>Authenticate logins and provide security features</li>
            <li>Deliver personalized training and tracking services</li>
            <li>Send necessary account, verification, or security-related emails</li>
            <li>Monitor platform performance and security</li>
            <li>Comply with applicable laws and enforce our Terms of Service</li>
          </ul>
          <p>We do not sell or rent your personal data.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>4. Communications and Emails</h3>
          <p>We send emails using PHPMailer through Proton Mail, including:</p>
          <ul>
            <li>Account verification or authentication links</li>
            <li>Password resets and passkey setup emails</li>
            <li>Security and administrative notifications</li>
          </ul>
          <p>These are essential system communications and cannot be opted out of. Marketing or promotional emails will only be sent with your express consent.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>5. Data Storage and Security</h3>
          <p>All data is hosted on secure, virtualized servers maintained by the developer during beta testing. Both web and database servers are separate and protected with:</p>
          <ul>
            <li>Encryption at rest for stored data and backups</li>
            <li>TLS/SSL encryption (Let&rsquo;s Encrypt) for data in transit</li>
            <li>Firewalls, access controls, and audit logs for intrusion prevention</li>
          </ul>
          <p>Upon public release, hosting will migrate to a commercial cloud service meeting or exceeding these standards.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>6. Data Retention</h3>
          <p>We retain your data only as long as necessary for the purposes described or as required by law. If you delete your account, your data will be securely deleted or anonymized within a reasonable time, unless retention is required by law or for dispute resolution.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>7. Sharing and Disclosure</h3>
          <p>We may share limited information in the following cases:</p>
          <ul>
            <li>With service providers who assist in operations (e.g., email or hosting services)</li>
            <li>When required by law enforcement or legal obligations</li>
            <li>To prevent fraud, ensure security, or protect user safety</li>
          </ul>
          <p>All service providers are bound by confidentiality and data protection agreements.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>8. Children and Minors</h3>
          <p>We recognize that some users of our Service are minors who train under the supervision of a certified personal trainer.</p>
          <ul>
            <li>We collect information from minors only with verifiable parental or guardian consent.</li>
            <li>Parents or guardians who register their child are considered the account holders and responsible for managing their child&rsquo;s information.</li>
            <li>We comply with the Children&rsquo;s Online Privacy Protection Act (COPPA), GDPR Article 8, and relevant state laws such as the California Consumer Privacy Act (CCPA).</li>
            <li>If we learn that a minor&rsquo;s information was collected without proper consent, it will be deleted promptly.</li>
          </ul>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>9. User Rights</h3>
          <p><strong>a. GDPR (EU/EEA Residents)</strong><br>You may:</p>
          <ul>
            <li>Access, correct, or delete your personal data</li>
            <li>Withdraw consent for processing (where applicable)</li>
            <li>Request data portability</li>
            <li>Lodge a complaint with a supervisory authority</li>
          </ul>
          <p><strong>b. CCPA (California Residents)</strong><br>You may:</p>
          <ul>
            <li>Request details about personal data collected and its use</li>
            <li>Request deletion of your personal information</li>
            <li>Opt out of data sale (we do not sell data)</li>
          </ul>
          <p>Requests may be sent to <a href="mailto:pangsanity.personaltraining@gmail.com">pangsanity.personaltraining@gmail.com</a>.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>10. International Data Transfers</h3>
          <p>If you access the Service from outside the United States, note that your information will be transferred to and processed in the U.S. under appropriate legal safeguards consistent with GDPR standards.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>11. Updates to This Policy</h3>
          <p>We may revise this Privacy Policy periodically. Any material changes will be posted here, and where required, we will notify you via email or in-app notice. Continued use of the Service after changes take effect constitutes acceptance of the updated policy.</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>12. Contact Us</h3>
          <p>If you have questions about this Privacy Policy or your data rights, contact us at:</p>
          <p>Email: <a href="mailto:pangsanity.personaltraining@gmail.com">pangsanity.personaltraining@gmail.com</a><br>Location: Bakersfield, CA, United States</p>
          <hr style="border: none; border-top: 1px solid rgba(148, 163, 184, 0.25); margin: 24px 0;">
          <h3>13. Consent</h3>
          <p>By registering an account or allowing a minor to use the PeterPangFit platform, you acknowledge that you have read, understood, and agree to this Privacy Policy and that, if registering on behalf of a minor, you are the lawful parent or guardian providing consent.</p>
        </div>
        <footer>
          <button type="button" class="btn btn-link" id="privacy_modal_cancel">Cancel</button>
          <button type="button" class="btn" id="privacy_modal_confirm" disabled>Register</button>
        </footer>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  // Elements
  const form        = document.getElementById('reg_form');
  const first       = document.getElementById('reg_first');
  const last        = document.getElementById('reg_last');
  const email       = document.getElementById('reg_email');
  const pwd         = document.getElementById('reg_password');
  const conf        = document.getElementById('reg_confirm');
  const submitBtn   = document.getElementById('reg_submit_btn');
  const modal       = document.getElementById('privacy_modal');
  const modalBody   = document.getElementById('privacy_modal_body');
  const modalCancel = document.getElementById('privacy_modal_cancel');
  const modalConfirm= document.getElementById('privacy_modal_confirm');

  const ruleLen   = document.getElementById('reg_rule_length');
  const ruleMix   = document.getElementById('reg_rule_mix');
  const rulePers  = document.getElementById('reg_rule_personal');
  const ruleMatch = document.getElementById('reg_rule_match');

  let hasAcknowledgedPolicy = false;
  let isProcessing = false;

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

  function focusFirstIssue(){
    if (!(pwd?.value || '').length) { pwd?.focus(); return; }
    if (!/[A-Z]/.test(pwd.value) || !/\d/.test(pwd.value) || !/[^A-Za-z0-9]/.test(pwd.value)) { pwd?.focus(); return; }
    if ((conf?.value || '') !== (pwd?.value || '')) { conf?.focus(); return; }
    pwd?.focus();
  }

  function closeModal(){
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  function checkModalScroll(){
    if (!modalBody || !modalConfirm) return false;
    const atBottom = (modalBody.scrollHeight - (modalBody.scrollTop + modalBody.clientHeight)) <= 2;
    if (atBottom) {
      modalConfirm.disabled = false;
    }
    return atBottom;
  }

  function openModal(){
    if (!modal) return;
    hasAcknowledgedPolicy = false;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    modalBody?.scrollTo({ top: 0 });
    if (modalConfirm) {
      modalConfirm.disabled = true;
      modalConfirm.textContent = 'Register';
    }
    setTimeout(() => {
      modalBody?.focus();
      checkModalScroll(); // in case content fits without scrolling
    }, 20);
  }

  // Live updates
  [first,last,email,pwd,conf].forEach(el => el && el.addEventListener('input', evaluate));

  // Gate submit if requirements fail
  form?.addEventListener('submit', function(e){
    if (!evaluate()){
      e.preventDefault();
      focusFirstIssue();
      return;
    }

    if (!hasAcknowledgedPolicy){
      e.preventDefault();
      openModal();
      return;
    }

    if (isProcessing){
      e.preventDefault();
      return;
    }

    isProcessing = true;
    if (submitBtn){
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';
    }
  });

  modalBody?.addEventListener('scroll', checkModalScroll);

  modalCancel?.addEventListener('click', () => {
    closeModal();
    if (submitBtn){ submitBtn.focus(); }
  });

  modal?.addEventListener('click', (ev) => {
    if (ev.target === modal) {
      closeModal();
      if (submitBtn){ submitBtn.focus(); }
    }
  });

  modalConfirm?.addEventListener('click', () => {
    if (modalConfirm.disabled) return;
    modalConfirm.disabled = true;
    modalConfirm.textContent = 'Processing...';
    hasAcknowledgedPolicy = true;
    closeModal();
    setTimeout(() => {
      if (form) {
        form.requestSubmit();
      }
    }, 20);
  });

  // initial paint
  evaluate();
})();
</script>
</body>
</html>