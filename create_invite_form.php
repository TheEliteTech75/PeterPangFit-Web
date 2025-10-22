<?php
// create_invite_form.php — Trainer/Admin creates a new invite by email
// Behavior:
//  - GET:  show a simple form (email + Invite button)
//  - POST: validate -> ensure user exists -> insert into invites (with created_by) -> send email -> show success flash
//
// Requires:
//   auth.php         -> sets $USER_ID, $USER_ROLE, $USER_NAME
//   db.php           -> sets $conn (mysqli)
//   send_email.php   -> exports send_plain_email($toEmail, $toName, $subject, $body)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Role gate (trainers & admins only)
if (!in_array($USER_ROLE ?? 'guest', ['trainer','admin'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// CSRF token
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Handle POST (create + send)
$flash = null;
$flash_type = 'ok'; // ok | err
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        $flash = 'Invalid session. Please try again.';
        $flash_type = 'err';
    } else {
        $email_value = trim($_POST['email'] ?? '');
        if ($email_value === '' || !filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Please enter a valid email address.';
            $flash_type = 'err';
        } else {
            // Generate token & expiry (+24h) — 64 hex chars
            $token = bin2hex(random_bytes(32));
            $expires_at = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

            $conn->begin_transaction();
            try {
                $user_id = null;

                // Lookup by email (case-insensitive)
                if ($stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1")) {
                    $stmt->bind_param("s", $email_value);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $user_id = (int)$row['id'];
                    }
                    $stmt->close();
                } else {
                    throw new Exception('Database error (prepare lookup).');
                }

                // Create minimal client user if none exists
                if (!$user_id) {
                    $role = 'client';
                    $now  = date('Y-m-d H:i:s');
                    $sqlU = "INSERT INTO users (email, role, created_at) VALUES (?, ?, ?)";
                    if (!$stmtU = $conn->prepare($sqlU)) {
                        throw new Exception('Database error (prepare user insert).');
                    }
                    $stmtU->bind_param("sss", $email_value, $role, $now);
                    if (!$stmtU->execute()) {
                        $stmtU->close();
                        throw new Exception('Failed to create user for invite.');
                    }
                    $user_id = $stmtU->insert_id;
                    $stmtU->close();
                }

                // Insert invite with created_by + created_at
                $sqlI = "INSERT INTO invites (user_id, email, token, expires_at, cancelled_at, used, created_by, created_at)
                         VALUES (?, ?, ?, ?, NULL, 0, ?, NOW())";
                if (!$stmtI = $conn->prepare($sqlI)) {
                    throw new Exception('Database error (prepare invite insert).');
                }
                $creator = (int)($USER_ID ?? 0);
                $stmtI->bind_param("isssi", $user_id, $email_value, $token, $expires_at, $creator);
                if (!$stmtI->execute()) {
                    $stmtI->close();
                    throw new Exception('Failed to create invite.');
                }
                $stmtI->close();

                $conn->commit();

                // Build registration link (adjust base URL if needed)
                $baseUrl = 'https://peterpang.pwncore.net';
                $link = $baseUrl . '/register.php?token=' . urlencode($token);

                // Send email
                $subject = "You're invited to join Peter Pang Fit";
                $body = "Hi,\n\n"
                      . "You’ve been invited to complete your registration. This link expires in 24 hours.\n\n"
                      . $link . "\n\n"
                      . "If it expires, your trainer can send a new one.\n\n— Peter Pang Fit";

                if (!send_plain_email($email_value, $email_value, $subject, $body)) {
                    $flash = "Invite created, but email sending failed. Token expires $expires_at.";
                    $flash_type = 'err';
                } else {
                    $flash = "Invite sent to {$email_value}. Token expires $expires_at.";
                    $flash_type = 'ok';
                    $email_value = '';
                }

            } catch (Throwable $e) {
                $conn->rollback();
                $flash = 'Failed to create invite. ' . $e->getMessage();
                $flash_type = 'err';
            }
        }
    }
}

// Signed-in meta (optional UI)
$who = $USER_NAME ?? trim(($USER_FIRST_NAME ?? '') . ' ' . ($USER_LAST_NAME ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Invite · Peter Pang Fit</title>
<style>
  :root{
    color-scheme:dark;
    --bg:#05070d; --bg-alt:#03040a; --panel:rgba(9,14,28,0.92); --text:#f8fafc; --muted:#cbd5f5; --brand:#38bdf8;
    --line:rgba(148,163,184,0.18); --chip:rgba(15,23,42,0.7); --ok:#10b981; --warn:#ef4444;
  }
  html,body{margin:0;padding:0;background:
      radial-gradient(circle at top left, rgba(56,189,248,0.18), transparent 55%),
      radial-gradient(circle at bottom right, rgba(110,231,183,0.12), transparent 60%),
      linear-gradient(155deg, var(--bg), var(--bg-alt));
    color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;
    background:var(--panel);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}
  .brand{font-weight:800;font-size:20px;letter-spacing:.2px}
  .meta{color:var(--muted);font-size:13px}
  .pill{display:inline-flex;align-items:center;gap:8px;background:var(--chip);
    border:1px solid var(--line);border-radius:999px;padding:6px 10px}
  .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
    color:var(--text);padding:10px 14px;border-radius:10px;cursor:pointer;text-decoration:none}
  .btn:hover{filter:brightness(1.06)}
  .btn.brand{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
  .btn.small{padding:6px 10px;font-size:13px}
  .wrap{max-width:700px;margin:24px auto;padding:0 16px}
  .card{background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:18px}
  .card h1{margin:0 0 10px 0;font-size:18px}
  .muted{color:var(--muted)}
  .form-row{display:flex;flex-direction:column;gap:8px;margin:10px 0 16px 0}
  .label{font-size:13px;color:#c8d1de}
  .input {
    width: 100%;
    background: rgba(8,13,23,0.95);
    border: 1px solid var(--line);
    color: var(--text);
    padding: 12px 14px;
    border-radius: 10px;
    outline: none;
    box-sizing: border-box;
  }
  .input:focus { border-color: #31508a; }
  .actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .flash{margin:16px 0;padding:12px;border-radius:10px;border:1px solid; background:rgba(8,13,23,0.85)}
  .flash.ok{border-color:rgba(34,197,94,0.45);color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}
</style>
</head>
<body>

<main class="wrap">
  <div class="card">
    <h1>Create Invite</h1>
    <p class="muted" style="margin-top:0">Enter the customer’s email address and click Invite. The link expires in 24 hours.</p>

    <?php if ($flash): ?>
      <div class="flash <?php echo $flash_type === 'ok' ? 'ok':'err'; ?>">
        <?php echo h($flash); ?>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <div class="form-row">
        <label class="label" for="email">Customer Email</label>
        <input class="input" type="email" id="email" name="email" placeholder="name@example.com" required value="<?php echo h($email_value); ?>">
      </div>
      <div class="actions">
        <button class="btn brand" type="submit">Invite</button>
        <a class="btn" href="invites.php">Manage Invites</a>
        <a class="btn" href="dashboard.php">Back to Dashboard</a>
      </div>
    </form>
  </div>
</main>
</body>
</html>
