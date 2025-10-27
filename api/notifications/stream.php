<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    exit;
}

$tenantId = ppf_current_tenant_id();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

echo "retry: 15000\n\n";
@ob_end_flush();
@flush();

session_write_close();

$lastCount = null;
$lastDigest = null;
$iterations = 0;
$maxIterations = 60; // ~5 minutes at 5s intervals

while ($iterations++ < $maxIterations) {
    if (connection_aborted()) {
        break;
    }
    $summary = ppf_notifications_fetch_recent($conn, $userId, 10, true);
    $count = ppf_notifications_unread_count($conn, $tenantId, $userId, $summary['settings']);
    $items = $summary['items'];
    $digest = md5(json_encode(array_map(function ($item) {
        return [
            'id' => $item['id'],
            'is_read' => $item['is_read'],
            'updated_at' => $item['updated_at'],
        ];
    }, $items)));

    if ($lastCount === null || $count !== $lastCount) {
        $payload = json_encode(['count' => $count]);
        echo "event: unread_count\n";
        echo "data: {$payload}\n\n";
        @flush();
        $lastCount = $count;
    }

    if ($lastDigest === null || $digest !== $lastDigest) {
        $payload = json_encode(['items' => $items]);
        echo "event: list_update\n";
        echo "data: {$payload}\n\n";
        @flush();
        $lastDigest = $digest;
    }

    sleep(5);
}

echo "event: stream_end\n";
echo "data: {\"reason\":\"timeout\"}\n\n";
@flush();
