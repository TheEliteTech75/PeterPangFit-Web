<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/totp.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) throw new RuntimeException('Not authenticated.');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session expired. Please refresh and try again.');
    }

    ppf_ensure_twofa_columns($conn);

    $action = (string)($_POST['action'] ?? '');

    $email = (string)($_SESSION['email'] ?? '');
    $first = (string)($_SESSION['first_name'] ?? '');
    $last  = (string)($_SESSION['last_name'] ?? '');
    $name  = trim("$first $last") ?: $email;
    $role  = (string)($_SESSION['role'] ?? '');

    if ($action === 'request_enable') {
        $secret = strtoupper(ppf_totp_new_secret(20));
        $_SESSION['twofa_app_pending'] = [
            'secret' => $secret,
            'created' => time(),
        ];

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

        if (!$st = $conn->prepare('UPDATE users SET twofa_app_token=?, twofa_app_expires=? WHERE id=?')) {
            throw new RuntimeException('Database error.');
        }
        $st->bind_param('ssi', $code, $expiresAt, $uid);
        $st->execute();
        $st->close();

        $bodyLines = [
            'A request was received to add an authenticator app to your account.',
            "Verification code: {$code}",
            '',
            'This code will expire in 15 minutes.',
            '',
            'If you did not request this, please contact support immediately.'
        ];
        @send_plain_email($email, $name, 'Authenticator App Setup Verification', implode("\n", $bodyLines));

        if (function_exists('ppf_log')) {
            ppf_log($conn, $uid, $email ?: null, $role ?: null, 'twofa_app_code_sent', 'user', (string)$uid, null);
        }

        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'verify_code') {
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== 6) throw new RuntimeException('Enter the 6-digit verification code.');

        if (!$st = $conn->prepare('SELECT twofa_app_token, twofa_app_expires FROM users WHERE id=? LIMIT 1')) {
            throw new RuntimeException('Database error.');
        }
        $st->bind_param('i', $uid);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();

        $expires = strtotime((string)($row['twofa_app_expires'] ?? ''));
        if (!$row || !$expires || $expires < time() || !hash_equals((string)$row['twofa_app_token'], $code)) {
            throw new RuntimeException('Invalid or expired verification code.');
        }

        // keep token until confirmation step but refresh session timestamp
        $_SESSION['twofa_app_pending']['verified'] = time();

        $pending = $_SESSION['twofa_app_pending'] ?? null;
        if (!$pending || empty($pending['secret'])) {
            throw new RuntimeException('Setup session expired. Start again.');
        }

        $account = $email !== '' ? $email : ('user' . $uid . '@peterpangfit');
        $otpauth = ppf_otpauth_url('Peter Pang Fit', $account, $pending['secret']);
        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($otpauth);

        echo json_encode([
            'ok' => true,
            'secret' => $pending['secret'],
            'qr' => $qr,
        ]);
        return;
    }

    if ($action === 'confirm_enable') {
        $pending = $_SESSION['twofa_app_pending'] ?? null;
        if (!$pending || empty($pending['secret'])) {
            throw new RuntimeException('Setup session expired. Start again.');
        }
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (strlen($code) !== 6 || $password === '') {
            throw new RuntimeException('Enter the authenticator code and your password.');
        }

        if (!$st = $conn->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1')) {
            throw new RuntimeException('Database error.');
        }
        $st->bind_param('i', $uid);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();

        if (!$row || !password_verify($password, (string)$row['password_hash'])) {
            throw new RuntimeException('Incorrect password.');
        }

        if (!ppf_totp_verify($pending['secret'], $code, 30, 6, 1)) {
            throw new RuntimeException('Authenticator code was not recognized.');
        }

        if (!$up = $conn->prepare('UPDATE users SET twofa_app_enabled=1, twofa_secret=?, twofa_app_token=NULL, twofa_app_expires=NULL WHERE id=?')) {
            throw new RuntimeException('Database error.');
        }
        $up->bind_param('si', $pending['secret'], $uid);
        $up->execute();
        $up->close();

        unset($_SESSION['twofa_app_pending']);

        $_SESSION['settings_flash'] = [
            'type' => 'success',
            'message' => 'Authenticator app was enabled successfully.'
        ];

        @send_plain_email($email, $name, 'Authenticator App Enabled', 'An authenticator app was enabled on your account. If this was not you, please contact support immediately.');

        if (function_exists('ppf_log')) {
            ppf_log($conn, $uid, $email ?: null, $role ?: null, 'twofa_app_enabled', 'user', (string)$uid, null);
        }

        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'disable') {
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (strlen($code) !== 6 || $password === '') {
            throw new RuntimeException('Enter the authenticator code and your password.');
        }

        if (!$st = $conn->prepare('SELECT password_hash, twofa_secret FROM users WHERE id=? LIMIT 1')) {
            throw new RuntimeException('Database error.');
        }
        $st->bind_param('i', $uid);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();

        $secret = strtoupper(preg_replace('/\s+/', '', (string)($row['twofa_secret'] ?? '')));
        if (!$row || $secret === '' || !password_verify($password, (string)$row['password_hash'])) {
            throw new RuntimeException('Incorrect password or authenticator not enabled.');
        }

        if (!ppf_totp_verify($secret, $code, 30, 6, 1)) {
            throw new RuntimeException('Authenticator code was not recognized.');
        }

        if (!$up = $conn->prepare('UPDATE users SET twofa_app_enabled=0, twofa_secret=NULL, twofa_app_token=NULL, twofa_app_expires=NULL WHERE id=?')) {
            throw new RuntimeException('Database error.');
        }
        $up->bind_param('i', $uid);
        $up->execute();
        $up->close();

        $_SESSION['settings_flash'] = [
            'type' => 'success',
            'message' => 'Authenticator app was disabled successfully.'
        ];

        @send_plain_email($email, $name, 'Authenticator App Disabled', 'An authenticator app was disabled on your account. If this was not you, please contact support immediately.');

        if (function_exists('ppf_log')) {
            ppf_log($conn, $uid, $email ?: null, $role ?: null, 'twofa_app_disabled', 'user', (string)$uid, null);
        }

        echo json_encode(['ok' => true]);
        return;
    }

    throw new RuntimeException('Unsupported request.');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
