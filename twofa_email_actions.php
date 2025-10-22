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

    if ($action === 'request') {
        $state = (string)($_POST['state'] ?? '');
        if (!in_array($state, ['enable', 'disable'], true)) {
            throw new RuntimeException('Unsupported action.');
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

        if (!$st = $conn->prepare('UPDATE users SET twofa_email_code=?, twofa_email_expires=? WHERE id=?')) {
            throw new RuntimeException('Database error.');
        }
        $st->bind_param('ssi', $code, $expiresAt, $uid);
        $st->execute();
        $st->close();

        $email = (string)($_SESSION['email'] ?? '');
        $first = (string)($_SESSION['first_name'] ?? '');
        $last  = (string)($_SESSION['last_name'] ?? '');
        $name  = trim("$first $last") ?: $email;

        $subject = $state === 'enable' ? 'Enable Email Authentication' : 'Disable Email Authentication';
        $bodyLines = [
            "Your verification code is {$code}.",
            '',
            'This code will expire in 15 minutes.',
            '',
            'If you did not request this change, please contact support immediately.'
        ];
        @send_plain_email($email, $name, $subject, implode("\n", $bodyLines));

        if (function_exists('ppf_log')) {
            $event = $state === 'enable' ? 'twofa_email_code_sent_enable' : 'twofa_email_code_sent_disable';
            ppf_log($conn, $uid, $email ?: null, ($_SESSION['role'] ?? null), $event, 'user', (string)$uid, null);
        }

        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'confirm') {
        $state = (string)($_POST['state'] ?? '');
        if (!in_array($state, ['enable', 'disable'], true)) {
            throw new RuntimeException('Unsupported action.');
        }

        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (strlen($code) !== 6 || $password === '') {
            throw new RuntimeException('Enter the verification code and your password.');
        }

        if (!$st = $conn->prepare('SELECT password_hash, twofa_email_code, twofa_email_expires, email, first_name, last_name, role FROM users WHERE id=? LIMIT 1')) {
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

        $expires = strtotime((string)($row['twofa_email_expires'] ?? ''));
        if (!$expires || $expires < time() || !hash_equals((string)$row['twofa_email_code'], $code)) {
            throw new RuntimeException('Invalid or expired verification code.');
        }

        $enabled = $state === 'enable' ? 1 : 0;
        if (!$up = $conn->prepare('UPDATE users SET twofa_email_enabled=?, twofa_email_code=NULL, twofa_email_expires=NULL WHERE id=?')) {
            throw new RuntimeException('Database error.');
        }
        $up->bind_param('ii', $enabled, $uid);
        $up->execute();
        $up->close();

        $email = (string)$row['email'];
        $name  = trim(((string)$row['first_name']) . ' ' . ((string)$row['last_name'])) ?: $email;
        $role  = (string)$row['role'];

        $_SESSION['settings_flash'] = [
            'type' => 'success',
            'message' => $state === 'enable'
                ? 'Email authentication was enabled successfully.'
                : 'Email authentication was disabled successfully.'
        ];

        $notifySubject = $state === 'enable'
            ? 'Email Authentication Enabled'
            : 'Email Authentication Disabled';
        $notifyBody = $state === 'enable'
            ? "Email-based authentication codes are now required for your account. If this was not you, please contact support immediately."
            : "Email-based authentication codes were disabled for your account. If this was not you, please contact support immediately.";
        @send_plain_email($email, $name, $notifySubject, $notifyBody);

        if (function_exists('ppf_log')) {
            $event = $state === 'enable' ? 'twofa_email_enabled' : 'twofa_email_disabled';
            ppf_log($conn, $uid, $email ?: null, $role ?: null, $event, 'user', (string)$uid, null);
        }

        echo json_encode(['ok' => true]);
        return;
    }

    throw new RuntimeException('Unsupported request.');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
