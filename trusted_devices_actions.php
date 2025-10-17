<?php
// trusted_devices_actions.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/ppf_trusted.php';

$uid  = (int)($_SESSION['user_id'] ?? 0);
$csrf = $_POST['csrf_token'] ?? '';
if ($uid <= 0 || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  $isAjax = (($_POST['action'] ?? '') === 'rename');
  if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Invalid session.']); }
  else { header('Location: settings.php?msg=err&detail=Invalid%20session'); }
  exit;
}

$action = $_POST['action'] ?? '';
switch ($action) {
  case 'rename':
    header('Content-Type: application/json');
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 100));
    if ($id <= 0 || $name === '') { echo json_encode(['ok'=>false,'error'=>'Invalid input.']); exit; }
    $ok = ppf_td_rename($conn, $uid, $id, $name);
    if ($ok) {
      ppf_log($conn, $uid, null, null, 'trusted_device_renamed', 'trusted_device', (string)$id, 'name='.$name);
      echo json_encode(['ok'=>true]); 
    } else {
      echo json_encode(['ok'=>false,'error'=>'Rename failed.']);
    }
    exit;

  case 'delete':
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { header('Location: settings.php?msg=err&detail=Invalid%20device'); exit; }
    if (ppf_td_delete($conn, $uid, $id)) {
      header('Location: settings.php?msg=ok'); // banner will not show; reuse patterns if desired
    } else {
      header('Location: settings.php?msg=err&detail=Delete%20failed'); 
    }
    exit;
}

header('Location: settings.php');