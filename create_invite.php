<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/helpers.php';

// Role gate (trainers & admins only)
$roleKey = ppf_role_key($USER_ROLE ?? 'guest');
if ($roleKey !== 'trainer' && !ppf_is_admin_role($USER_ROLE ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

function bad($msg, $code = 400) {
    http_response_code($code);
    exit($msg);
}

$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($first === '' || $last === '' || $email === '') {
    bad('Missing required fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bad('Invalid email address.');
}

// Generate 64-hex token expiring in 24h
$token      = bin2hex(random_bytes(32));
$expires_at = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

$conn->begin_transaction();
try {
    // 1) Ensure a user exists for this email (case-insensitive)
    $user_id = null;
    if ($stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1")) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $user_id = (int)$row['id'];
        }
        $stmt->close();
    } else {
        throw new Exception('Database error (prepare user lookup).');
    }

    if (!$user_id) {
        // Create minimal client user; store provided names
        $sqlU = "INSERT INTO users (first_name, last_name, email, role, created_at)
                 VALUES (?, ?, ?, 'client', NOW())";
        if (!$stmtU = $conn->prepare($sqlU)) {
            throw new Exception('Database error (prepare user insert).');
        }
        $stmtU->bind_param("sss", $first, $last, $email);
        if (!$stmtU->execute()) {
            $stmtU->close();
            throw new Exception('Failed to create user for invite.');
        }
        $user_id = $stmtU->insert_id;
        $stmtU->close();
    } else {
        // Optionally backfill first/last name if missing
        if ($stmtN = $conn->prepare("UPDATE users SET first_name = COALESCE(NULLIF(first_name,''), ?),
                                                     last_name  = COALESCE(NULLIF(last_name,''),  ?)
                                     WHERE id = ?")) {
            $stmtN->bind_param("ssi", $first, $last, $user_id);
            $stmtN->execute();
            $stmtN->close();
        }
    }

    // 2) Insert invite with created_by + created_at
    $sqlI = "INSERT INTO invites (user_id, email, token, expires_at, cancelled_at, used, created_by, created_at)
             VALUES (?, ?, ?, ?, NULL, 0, ?, NOW())";
    if (!$stmtI = $conn->prepare($sqlI)) {
        throw new Exception('Database error (prepare invite insert).');
    }
    $creator = (int)($USER_ID ?? 0);
    $stmtI->bind_param("isssi", $user_id, $email, $token, $expires_at, $creator);
    if (!$stmtI->execute()) {
        $stmtI->close();
        throw new Exception('Failed to create invite.');
    }
    $stmtI->close();

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    bad('Failed to create invite. ' . $e->getMessage(), 500);
}

// 3) Send email
$baseUrl = 'https://peterpang.pwncore.net'; // adjust if needed
$link = $baseUrl . "/register.php?token=" . urlencode($token);

$subject = "You're invited to join Peter Pang Fit";
$body = "Hi {$first},\n\n"
      . "You’ve been invited to complete your registration. This link expires in 24 hours.\n\n"
      . "{$link}\n\n"
      . "If it expires, your trainer can send a new one.\n\n— Peter Pang Fit";

if (!send_plain_email($email, "{$first} {$last}", $subject, $body)) {
    echo "Invite created, but email sending failed. Token expires: {$expires_at}";
} else {
    echo "Invite sent to {$email}. Token expires: {$expires_at}";
}
