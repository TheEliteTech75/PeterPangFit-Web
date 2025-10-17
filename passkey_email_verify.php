<?php
// passkey_email_verify.php — verify email code + current password for adding a passkey
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/totp.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

try {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) throw new RuntimeException('Not authenticated.');

  $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
  $pass = (string)($_POST['password'] ?? '');

  if ($code === '' || $pass === '') throw new RuntimeException('Missing code or password.');

  // password check
  $st = $conn->prepare("SELECT password_hash, passkey_email_code, passkey_email_expires, email, first_name, last_name, role FROM users WHERE id=? LIMIT 1");
  $st->bind_param("i", $uid);
  $st->execute();
  $rs = $st->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;
  $st->close();

  if (!$row || !password_verify($pass, (string)$row['password_hash'])) {
    throw new RuntimeException('Incorrect password.');
  }

  $e = strtotime((string)($row['passkey_email_expires'] ?? ''));
  $ok = $e && $e > time() && hash_equals((string)$row['passkey_email_code'], $code);
  if (!$ok) {
    ppf_log($conn, $uid, (string)$row['email'], (string)$row['role'], 'passkey_email_code_invalid', 'user', (string)$uid, null);
    throw new RuntimeException('Invalid or expired code.');
  }

  // clear used code
  if ($u = $conn->prepare("UPDATE users SET passkey_email_code=NULL, passkey_email_expires=NULL WHERE id=?")) {
    $u->bind_param("i", $uid);
    $u->execute();
    $u->close();
  }

  ppf_log($conn, $uid, (string)$row['email'], (string)$row['role'], 'passkey_email_code_verified', 'user', (string)$uid, null);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}