<?php
// passkey_delete.php — delete a passkey (requires password)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: login.php'); exit; }

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  header('Location: settings.php?msg=err&detail=' . urlencode('Invalid session.')); exit;
}

$pid  = (int)($_POST['passkey_id'] ?? 0);
$pass = (string)($_POST['password'] ?? '');
$isAjax = isset($_POST['ajax']);

if ($pid <= 0 || $pass === '') {
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Missing required fields.']);
    exit;
  }
  header('Location: settings.php?msg=err&detail=' . urlencode('Missing required fields.')); exit;
}

// Verify password and gather notification info
$st = $conn->prepare("SELECT password_hash, email, first_name, last_name, role FROM users WHERE id=? LIMIT 1");
$st->bind_param("i", $uid); $st->execute();
$r = $st->get_result(); $urow = $r ? $r->fetch_assoc() : null; $st->close();

if (!$urow || !password_verify($pass, (string)$urow['password_hash'])) {
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Incorrect password.']);
    exit;
  }
  header('Location: settings.php?msg=err&detail=' . urlencode('Incorrect password.')); exit;
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
    ppf_log($conn, $uid, ($_SESSION['email'] ?? null), ($_SESSION['role'] ?? null),
      'passkey_deleted', 'user', (string)$uid, 'id='.$pid.';name='.$name);

    $email = (string)($urow['email'] ?? '');
    $fullName = trim(((string)$urow['first_name']) . ' ' . ((string)$urow['last_name']));
    $recipientName = $fullName !== '' ? $fullName : $email;
    @send_plain_email($email, $recipientName, 'Passkey Deleted', "A passkey named '{$name}' was deleted from your account. If this was not you, please review your security settings.");

    $_SESSION['settings_flash'] = [
      'type' => 'success',
      'message' => 'Passkey ' . ($name !== '' ? '"' . $name . '" ' : '') . 'was deleted.'
    ];

    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => true]);
      exit;
    }

    header('Location: settings.php'); exit;
  }
}

if ($isAjax) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Delete failed.']);
  exit;
}

header('Location: settings.php?msg=err&detail=' . urlencode('Delete failed.'));