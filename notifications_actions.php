<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid session.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$response = ['ok' => false];

if ($action === 'mark') {
    $notificationId = (int)($_POST['id'] ?? 0);
    $read = ((string)($_POST['read'] ?? '') === '1');
    if ($notificationId > 0) {
        $response['ok'] = ppf_notifications_set_read($conn, $userId, $notificationId, $read);
    }
} elseif ($action === 'mark_all') {
    $response['ok'] = ppf_notifications_mark_all_read($conn, $userId);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
    exit;
}

if ($response['ok']) {
    $summary = ppf_notifications_fetch_recent($conn, $userId, 10);
    $unread = (int)($summary['unread'] ?? 0);
    $subtitle = $unread === 0
        ? 'You are all caught up.'
        : ($unread === 1 ? '1 unread notification' : ($unread . ' unread notifications'));
    $response['unread'] = $unread;
    $response['subtitle'] = $subtitle;
} else {
    if (!isset($response['error'])) {
        $response['error'] = 'Unable to update notifications.';
    }
}

echo json_encode($response);
exit;
