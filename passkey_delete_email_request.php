<?php
// passkey_delete_email_request.php — send a 6-digit code for passkey deletion
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/logs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

try {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) throw new RuntimeException('Not authenticated.');

  $id   = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));

  // If name not provided, look it up (optional, for email context)
  if ($id > 0 && $name === '') {
    if ($st = $conn->prepare("SELECT name FROM passkeys WHERE id=? AND user_id=? LIMIT 1")) {
      $st->bind_param("ii", $id, $uid);
      $st->execute();
      $r = $st->get_result(); $row = $r ? $r->fetch_assoc() : null; $st->close();
      if ($row) $name = (string)$row['name'];
    }
  }

  // Store code on the user record (expires in 15 min)
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $exp  = date('Y-m-d H:i:s', time() + 15*60);
  if ($u = $conn->prepare("UPDATE users SET twofa_email_code=?, twofa_email_expires=? WHERE id=?")) {
    $u->bind_param("ssi", $code, $exp, $uid);
    $u->execute(); $u->close();
  }

  // Send email
  $email = (string)($_SESSION['email'] ?? '');
  $to    = trim(($_SESSION['first_name'] ?? '').' '.($_SESSION['last_name'] ?? '')) ?: $email;
  $subject = 'Confirm passkey deletion';
  $body = "Your 6-digit code is: {$code}\n\n"
        . "Request: delete passkey" . ($name !== '' ? " “{$name}”" : '') . "\n"
        . "This code expires in soon.";
  @send_plain_email($email, $to, $subject, $body);

  ppf_log($conn, $uid, $email, ($_SESSION['role'] ?? null), 'passkey_delete_code_sent', 'user', (string)$uid, 'passkey_id='.$id);

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}