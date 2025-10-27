<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

@set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

define('PPF_NOTIFY_STREAM_LIMIT', 1);

function ppf_notifications_stream_event(string $event, array $payload = []): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    @ob_flush();
    @flush();
}

$tenantId = ppf_current_tenant_id();
$settings = null;
try {
    $settings = ppf_notifications_settings_get($conn, $tenantId, $userId);
} catch (Throwable $e) {
    $settings = ppf_notifications_default_settings();
}

try {
    $summary = ppf_notifications_fetch_recent($conn, $userId, 10);
    $unread = (int)($summary['unread'] ?? 0);
    ppf_notifications_stream_event('unread_count', ['count' => $unread]);
    $items = $summary['items'] ?? [];
    ppf_notifications_stream_event('list_update', ['items' => $items]);
} catch (Throwable $e) {
    ppf_notifications_stream_event('error', ['message' => 'stream unavailable']);
}

ppf_notifications_stream_event('stream_end');
exit;
