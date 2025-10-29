<?php
// passkey_email_request.php — stage a 6-digit email code for adding a passkey
require_once __DIR__ . '/ppf_debug.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/logs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

try {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $email = (string)($_SESSION['email'] ?? '');
  $first = (string)($_SESSION['first_name'] ?? '');
  $last  = (string)($_SESSION['last_name'] ?? '');
  $role  = (string)($_SESSION['role'] ?? '');

  if ($uid <= 0) throw new RuntimeException('Not authenticated.');

  // ensure columns exist
  ppf_ensure_twofa_columns($conn);

  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $exp  = date('Y-m-d H:i:s', time() + 15*60);

  if (!$st = $conn->prepare("UPDATE users SET passkey_email_code=?, passkey_email_expires=? WHERE id=?")) {
    throw new RuntimeException('DB error.');
  }
  $st->bind_param("ssi", $code, $exp, $uid);
  $ok = $st->execute();
  $st->close();
  if (!$ok) throw new RuntimeException('Failed to stage code.');

  $name = trim("$first $last") ?: $email;
  @send_plain_email($email, $name, 'Add Passkey: Confirmation Code', "Your code: {$code}\n\nThis code will expire shortly.");

  ppf_log($conn, $uid, $email, $role, 'passkey_email_code_sent', 'user', (string)$uid, null);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}