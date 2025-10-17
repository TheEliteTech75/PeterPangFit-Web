<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';        // for ppf_log() and ppf_client_ip()
require_once __DIR__ . '/send_email.php';  // <-- added: for send_plain_email()

function bad($msg, $code = 400) {
    http_response_code($code);
    exit($msg);
}

// Strong password policy (same logic you use in profile/reset flows)
function password_meets_requirements(string $pwd, string $email, string $first, string $last): ?string {
    if (strlen($pwd) < 12) return 'Password must be at least 12 characters.';
    if (!preg_match('/[A-Z]/', $pwd) || !preg_match('/\d/', $pwd) || !preg_match('/[^A-Za-z0-9]/', $pwd)) {
        return 'Password must include at least one capital letter, one number, and one special character.';
    }
    $lowerPwd = mb_strtolower($pwd);

    // Build all fragments (>=3 chars) from email/first/last
    $frags = [];
    $add = function(string $tok) use (&$frags){
        $t = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($tok));
        $n = mb_strlen($t);
        if ($n < 3) return;
        for ($i=0; $i <= $n-3; $i++) {
            for ($len=3; $len <= $n-$i; $len++) {
                $frag = mb_substr($t, $i, $len);
                if (mb_strlen($frag) > 16) break;
                $frags[$frag] = true;
            }
        }
    };
    foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($email)) as $tok) { if ($tok !== '') $add($tok); }
    foreach ([$first, $last] as $nm) {
        foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($nm)) as $tok) { if ($tok !== '') $add($tok); }
    }
    foreach ($frags as $frag => $_) {
        if ($frag !== '' && mb_strpos($lowerPwd, $frag) !== false) {
            return 'Password cannot contain your name or email (even partial matches).';
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('Method not allowed', 405);
}

// Token from invite link (64 hex to match register.php)
$token = $_POST['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    bad('Invalid or missing token.');
}

// Look up invite
$inv = null;
if ($stmt = $conn->prepare("
    SELECT id, email, token, expires_at, cancelled_at, COALESCE(used, 0) AS used, user_id
    FROM invites
    WHERE token = ?
    LIMIT 1
")) {
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $inv = $res->fetch_assoc();
    }
    $stmt->close();
}

if (!$inv) {
    bad('Invite not found or already processed.', 410);
}

// If expired, log and block
if (!empty($inv['expires_at']) && strtotime($inv['expires_at']) <= time()) {
    $details = "token={$inv['token']}; original_expires_at={$inv['expires_at']}";
    ppf_log($conn, null, (string)$inv['email'], null, 'invite_link_expired', 'invite', null, $details);
    bad('This invite has expired.', 410);
}

if (!empty($inv['cancelled_at'])) {
    bad('This invite has been cancelled.', 410);
}
if ((int)$inv['used'] === 1) {
    bad('This invite has already been used.', 410);
}

// Gather form fields
$first      = trim($_POST['first_name']   ?? '');
$middle     = trim($_POST['middle_name']  ?? '');
$last       = trim($_POST['last_name']    ?? '');
$emailInput = trim($_POST['email']        ?? '');
$email      = trim($inv['email'] ?? $emailInput);

$password   = $_POST['password']          ?? '';
$confirm    = $_POST['password_confirm']  ?? ($_POST['confirm_password'] ?? '');

$ft         = isset($_POST['height_ft']) ? (int)$_POST['height_ft'] : null;
$in         = isset($_POST['height_in']) ? (int)$_POST['height_in'] : null;
$weight_lbs = isset($_POST['weight_lbs']) ? trim($_POST['weight_lbs']) : null;

$birthdate  = trim($_POST['birthdate'] ?? ($_POST['dob'] ?? ''));
$gender     = trim($_POST['gender']  ?? '');
$phone      = trim($_POST['phone']   ?? '');

// Honeypot trip → log, cancel token immediately, and fail
$honeypot = (string)($_POST['website'] ?? '');
if ($honeypot !== '') {
    // Cancel the invite immediately
    if ($upd = $conn->prepare("UPDATE invites SET cancelled_at = NOW() WHERE token = ? AND cancelled_at IS NULL")) {
        $upd->bind_param("s", $token);
        $upd->execute();
        $upd->close();
    }
    // Log with IP and UA and submitted identity fields
    $ip = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $details = "ip={$ip}; ua=" . $ua
             . "; first_name=" . $first
             . "; last_name="  . $last
             . "; email="      . $email
             . "; token_cancelled=1";
    ppf_log($conn, null, $email ?: null, null, 'registration_failed_honeypot', 'invite', null, $details);

    // Second log: explicit invite_expired record with token + original expiration
    $details2 = "token={$inv['token']}; original_expires_at={$inv['expires_at']}; reason=honeypot; ip={$ip}; ua={$ua}";
    ppf_log($conn, null, (string)$inv['email'], null, 'invite_expired', 'invite', null, $details2);

    bad('Invalid or expired invite token. Please request a new invite from your trainer.', 410);
}

// Validations
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bad('Valid email is required.');
}
if ($first === '' || $last === '') {
    bad('First and last name are required.');
}
if ($password === '' || $confirm === '') {
    bad('Password and confirmation are required.');
}
if ($password !== $confirm) {
    bad('Passwords do not match.');
}

// Strong password policy (same as profile/reset)
if ($msg = password_meets_requirements($password, $email, $first, $last)) {
    bad($msg);
}

$pwdHash = password_hash($password, PASSWORD_DEFAULT);
if ($pwdHash === false) {
    bad('Failed to hash password.', 500);
}

// Check if user already exists
$user = null;
if ($stmt = $conn->prepare("SELECT id, email, role, password_hash FROM users WHERE email = ? LIMIT 1")) {
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $user = $res->fetch_assoc();
    }
    $stmt->close();
}

$conn->begin_transaction();

try {
    if ($user) {
        if (!empty($user['password_hash'])) {
            $conn->rollback();
            bad('This email is already registered. Please log in.', 409);
        }

        $user_id = (int)$user['id'];
        $sql = "UPDATE users
                   SET first_name = ?, middle_name = ?, last_name = ?,
                       height_ft = ?, height_in = ?, weight_lbs = ?,
                       birthdate = ?, gender = ?, phone = ?,
                       password_hash = ?, role = COALESCE(role, 'client')
                 WHERE id = ?";
        $upd = $conn->prepare($sql);
        $upd->bind_param(
            "sssiiissssi",
            $first, $middle, $last,
            $ft, $in, $weight_lbs,
            $birthdate, $gender, $phone,
            $pwdHash, $user_id
        );
        if (!$upd->execute()) {
            throw new Exception('Failed to complete user record.');
        }
        $upd->close();
    } else {
        $sql = "INSERT INTO users
                    (first_name, middle_name, last_name, email, phone, gender, birthdate,
                     height_ft, height_in, weight_lbs,
                     role, password_hash, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'client', ?, NOW())";
        $ins = $conn->prepare($sql);
        $ins->bind_param(
            "sssssssiiis",
            $first, $middle, $last, $email, $phone, $gender, $birthdate,
            $ft, $in, $weight_lbs,
            $pwdHash
        );
        if (!$ins->execute()) {
            throw new Exception('Failed to create user.');
        }
        $user_id = $ins->insert_id;
        $ins->close();
    }

    // Mark invite as used
    ensure_invite_columns($conn);

if ($inv['user_id']) {
    $iu = $conn->prepare("UPDATE invites SET used = 1, registered_at = NOW() WHERE id = ?");
    $iu->bind_param("i", $inv['id']);
} else {
    $iu = $conn->prepare("UPDATE invites SET used = 1, user_id = ?, registered_at = NOW() WHERE id = ?");
    $iu->bind_param("ii", $user_id, $inv['id']);
}
if (!$iu->execute()) {
    throw new Exception('Failed to mark invite as used.');
}
$iu->close();

    $conn->commit();

    // ----------------------------
    // Post-commit: Send welcome email (best-effort) + log
    // ----------------------------
    $toName = trim($first . ' ' . $last) ?: $email;
    $subject = 'Welcome to Peter Pang Fit — Your account is ready';
    $body = "Hi {$toName},\n\n"
          . "Your Peter Pang Fit account has been created successfully.\n"
          . "You can now sign in here:\n"
          . "https://peterpang.pwncore.net/login.php\n\n"
          . "If you didn’t request this, please let us know.\n\n— Peter Pang Fit";

    $sent = @send_plain_email($email, $toName, $subject, $body);

    if ($sent) {
        ppf_log($conn, $user_id, $email, 'client', 'registration_email_sent', 'user', (string)$user_id, 'welcome_email=1');
    } else {
        ppf_log($conn, $user_id, $email, 'client', 'registration_email_failed', 'user', (string)$user_id, 'welcome_email=0');
    }

} catch (Throwable $e) {
    $conn->rollback();
    bad('Registration failed: ' . $e->getMessage(), 500);
}

// Redirect to login page (kept as-is)
header("Location: login.php?registered=1");
exit;