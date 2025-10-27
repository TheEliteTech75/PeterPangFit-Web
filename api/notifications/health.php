<?php
if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once __DIR__ . '/../../helpers.php';
} else {
    require_once __DIR__ . '/../../auth.php';
    require_once __DIR__ . '/../../helpers.php';
}

if (!function_exists('ppf_notifications_health_endpoint')) {
    function ppf_notifications_health_endpoint(): array
    {
        global $conn;
        $requestId = ppf_notifications_request_id();

        if (!isset($conn) || !($conn instanceof mysqli)) {
            $payload = ['ok' => false, 'reason' => 'db_unavailable', 'request_id' => $requestId];
            if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
                return $payload;
            }
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $payload = ['ok' => false, 'reason' => 'unauthenticated', 'request_id' => $requestId];
            if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
                return $payload;
            }
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }

        try {
            $tenantId = ppf_current_tenant_id();
            $count = ppf_notifications_unread_count($conn, $tenantId, $userId);
            $payload = ['ok' => true, 'unread' => $count, 'request_id' => $requestId];
        } catch (Throwable $e) {
            $payload = ['ok' => false, 'reason' => 'error', 'request_id' => $requestId];
            if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
                return $payload;
            }
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }

        if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
            return $payload;
        }

        header('Content-Type: application/json');
        echo json_encode($payload);
        return $payload;
    }
}

if (!defined('PPF_NOTIFICATIONS_TEST_MODE') || !PPF_NOTIFICATIONS_TEST_MODE) {
    ppf_notifications_health_endpoint();
    exit;
}
