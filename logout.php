<?php
// logout.php — manual logout + system_logs entry

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Capture who is logging out BEFORE we destroy the session
$uid   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$email = $_SESSION['email'] ?? null;
$role  = $_SESSION['role']  ?? null;

// Bring up DB + logger (safe: logs.php does not render or redirect when included)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';

// Write log entry (best-effort; skip silently if $conn not available)
if (isset($conn) && $conn instanceof mysqli) {
    // action: logout_manual, target_type: user, target_id: current user id
    ppf_log(
        $conn,
        $uid,                  // user_id
        $email,                // actor_email
        $role,                 // actor_role
        'logout_manual',       // action
        'user',                // target_type
        $uid !== null ? (string)$uid : null, // target_id
        'User logged out.'  // details
    );
}

// Now actually log the user out
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

// Optional: send them to login with a friendly message
header('Location: login.php?msg=logout');
exit;