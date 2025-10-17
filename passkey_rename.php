<?php
// passkey_rename.php — Rename a passkey (verify by end state, not affected_rows)
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
$name = trim((string)($_POST['name'] ?? ''));
$name = mb_substr($name, 0, 100);

if ($pid <= 0 || $name === '') {
  header('Location: settings.php?msg=err&detail=' . urlencode('Invalid input.')); exit;
}

// 1) Confirm ownership + get current name
$cur = null;
if ($st = $conn->prepare("SELECT name FROM passkeys WHERE id=? AND user_id=? LIMIT 1")) {
  $st->bind_param("ii", $pid, $uid);
  $st->execute();
  $res = $st->get_result();
  $cur = $res ? $res->fetch_assoc() : null;
  $st->close();
}
if (!$cur) {
  header('Location: settings.php?msg=err&detail=' . urlencode('Passkey not found.')); exit;
}

$currentName = (string)$cur['name'];

// 2) If exactly the same string, treat as no-op success
if ($name === $currentName) {
  header('Location: settings.php?msg=passkey_renamed&name=' . urlencode($name)); exit;
}

// 3) Attempt rename
if (!$st = $conn->prepare("UPDATE passkeys SET name=? WHERE id=? AND user_id=?")) {
  header('Location: settings.php?msg=err&detail=' . urlencode('DB error.')); exit;
}
$st->bind_param("sii", $name, $pid, $uid);
$ok = $st->execute();
$err = $st->error;
$st->close();

if (!$ok) {
  header('Location: settings.php?msg=err&detail=' . urlencode('Rename failed. '.($err ? "($err)" : ''))); exit;
}

// 4) Verify end state — only call it success if DB now shows the requested name
$after = null;
if ($st = $conn->prepare("SELECT name FROM passkeys WHERE id=? AND user_id=? LIMIT 1")) {
  $st->bind_param("ii", $pid, $uid);
  $st->execute();
  $res = $st->get_result();
  $after = $res ? $res->fetch_assoc() : null;
  $st->close();
}

if ($after && (string)$after['name'] === $name) {
  ppf_log($conn, $uid, ($_SESSION['email'] ?? null), ($_SESSION['role'] ?? null),
    'passkey_renamed', 'user', (string)$uid, 'id='.$pid.';old='.$currentName.';new='.$name);
  header('Location: settings.php?msg=passkey_renamed&name=' . urlencode($name)); exit;
}

// If we get here, the update executed but the value didn't end up as requested (e.g., collation blocked a case-only change)
header('Location: settings.php?msg=err&detail=' . urlencode('Rename did not change the name.'));