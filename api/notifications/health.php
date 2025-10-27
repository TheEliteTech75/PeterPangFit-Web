<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$tenantId = ppf_current_tenant_id();
$unread = 0;
try {
    $unread = ppf_notifications_unread_count($conn, $tenantId, $userId, null);
} catch (Throwable $e) {
    $unread = 0;
}

echo json_encode([
    'ok' => true,
    'unread' => $unread,
]);
exit;
