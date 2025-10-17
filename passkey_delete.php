<?php
// passkey_delete.php — delete a passkey (requires password + 6-digit email code)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: login.php'); exit; }

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: settings.php?msg=err&detail=' . urlencode('Invalid session.')); exit;
}

$pid  = (int)($_POST['passkey_id'] ?? 0);
$pass = (string)($_POST['password'] ?? '');
$code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

if ($pid <= 0 || $pass === '' || $code === '') {
  header('Location: settings.php?msg=err&detail=' . urlencode('Missing required fields.')); exit;
}

// Verify password + email code
$st = $conn->prepare("SELECT password_hash, twofa_email_code, twofa_email_expires FROM users WHERE id=? LIMIT 1");
$st->bind_param("i", $uid); $st->execute();
$r = $st->get_result(); $urow = $r ? $r->fetch_assoc() : null; $st->close();

if (!$urow || !password_verify($pass, (string)$urow['password_hash'])) {
  header('Location: settings.php?msg=err&detail=' . urlencode('Incorrect password.')); exit;
}
$e = strtotime((string)($urow['twofa_email_expires'] ?? ''));
$valid = $e && $e > time() && hash_equals((string)$urow['twofa_email_code'], $code);
if (!$valid) {
  header('Location: settings.php?msg=err&detail=' . urlencode('Invalid or expired code.')); exit;
}

// Confirm passkey belongs to user; capture name for banner/log
$name = '';
if ($s = $conn->prepare("SELECT name FROM passkeys WHERE id=? AND user_id=? LIMIT 1")) {
  $s->bind_param("ii", $pid, $uid);
  $s->execute();
  $res = $s->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $s->close();
  if ($row) $name = (string)$row['name'];
}
if (!$row) { header('Location: settings.php?msg=err&detail=' . urlencode('Passkey not found.')); exit; }

// Delete passkey
if ($d = $conn->prepare("DELETE FROM passkeys WHERE id=? AND user_id=?")) {
  $d->bind_param("ii", $pid, $uid);
  $ok = $d->execute(); $d->close();
  if ($ok) {
    // Clear used code
    if ($c = $conn->prepare("UPDATE users SET twofa_email_code=NULL, twofa_email_expires=NULL WHERE id=?")) {
      $c->bind_param("i", $uid); $c->execute(); $c->close();
    }
    ppf_log($conn, $uid, ($_SESSION['email'] ?? null), ($_SESSION['role'] ?? null),
      'passkey_deleted', 'user', (string)$uid, 'id='.$pid.';name='.$name);
    header('Location: settings.php?msg=passkey_deleted&name=' . urlencode($name)); exit;
  }
}

header('Location: settings.php?msg=err&detail=' . urlencode('Delete failed.'));