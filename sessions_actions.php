<?php
// sessions_actions.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ppf_passkeys.php';
require_once __DIR__ . '/logs.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: login.php'); exit; }

$action = $_POST['action'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: settings.php?err=csrf'); exit; }

if ($action === 'signout_one') {
  $sid = (string)($_POST['session_id'] ?? '');
  if ($sid === '' || $sid === session_id()) { header('Location: settings.php'); exit; }
  if (ppf_sessions_signout_one($conn, $uid, $sid)) {
    ppf_log($conn, $uid, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'session_revoked_by_user', 'user', (string)$uid, 'target='.$sid);
  }
  header('Location: settings.php'); exit;
}

if ($action === 'signout_all_others') {
  $n = ppf_sessions_signout_all_others($conn, $uid);
  ppf_log($conn, $uid, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'sessions_revoked_all_others', 'user', (string)$uid, 'count='.$n);
  header('Location: settings.php'); exit;
}

header('Location: settings.php');