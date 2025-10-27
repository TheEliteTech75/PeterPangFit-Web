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

if (!class_exists('PPF_Notifications_Test_Response', false)) {
    class PPF_Notifications_Test_Response extends Exception {
        public int $status;
        public array $payload;

        public function __construct(int $status, array $payload) {
            parent::__construct('notifications_test_response', $status);
            $this->status = $status;
            $this->payload = $payload;
        }
    }
}

if (!function_exists('ppf_notifications_rate_limit')) {
    function ppf_notifications_rate_limit(string $bucketKey, int $maxPerMinute = 60): void {
        if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
            return;
        }
        $minute = (int)floor(time() / 60);
        if (!isset($_SESSION['notifications_rate'][$bucketKey])) {
            $_SESSION['notifications_rate'][$bucketKey] = ['minute' => $minute, 'count' => 0];
        }
        $bucket = &$_SESSION['notifications_rate'][$bucketKey];
        if ($bucket['minute'] !== $minute) {
            $bucket = ['minute' => $minute, 'count' => 0];
        }
        if ($bucket['count'] >= $maxPerMinute) {
            ppf_notifications_response([
                'error' => 'Rate limit exceeded',
                'retry_after' => 60,
            ], 429);
        }
        $bucket['count']++;
    }
}

if (!function_exists('ppf_notifications_parse_json_body')) {
    function ppf_notifications_parse_json_body(): array {
        if (isset($GLOBALS['PPF_NOTIFICATIONS_TEST_BODY'])) {
            $raw = (string)$GLOBALS['PPF_NOTIFICATIONS_TEST_BODY'];
            unset($GLOBALS['PPF_NOTIFICATIONS_TEST_BODY']);
        } else {
            $raw = file_get_contents('php://input');
        }
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            ppf_notifications_response(['error' => 'Invalid JSON payload'], 400);
        }
        return $decoded;
    }
}

if (!function_exists('ppf_notifications_response')) {
    function ppf_notifications_response(array $payload, int $status = 200): void {
        $payload['request_id'] = ppf_notifications_request_id();
        if (defined('PPF_NOTIFICATIONS_TEST_MODE') && PPF_NOTIFICATIONS_TEST_MODE) {
            throw new PPF_Notifications_Test_Response($status, $payload);
        }
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }
}

if (!function_exists('ppf_notifications_dispatch')) {
    function ppf_notifications_dispatch(): void {
        global $conn;

        if (!defined('PPF_NOTIFICATIONS_TEST_MODE') || !PPF_NOTIFICATIONS_TEST_MODE) {
            header('Content-Type: application/json');
        }

        if (!isset($conn) || !($conn instanceof mysqli)) {
            ppf_notifications_response(['error' => 'Database unavailable'], 500);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            ppf_notifications_response(['error' => 'Unauthenticated'], 401);
        }

        $tenantId = ppf_current_tenant_id();
        $role = strtolower((string)($_SESSION['role'] ?? ''));
        $isAdmin = in_array($role, ['admin', 'owner', 'superadmin', 'super_admin'], true);
        $isStaff = in_array($role, ['staff', 'trainer'], true);

        ppf_notifications_bootstrap($conn);

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            if (!defined('PPF_NOTIFICATIONS_TEST_MODE') || !PPF_NOTIFICATIONS_TEST_MODE) {
                header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
                exit;
            }
            return;
        }

        $pathInfo = '';
        if (isset($_SERVER['PATH_INFO'])) {
            $pathInfo = trim((string)$_SERVER['PATH_INFO'], '/');
        } else {
            $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            $relative = substr($uriPath, strlen($scriptDir));
            $pathInfo = trim($relative, '/');
        }
        $segments = $pathInfo === '' ? [] : explode('/', $pathInfo);

        $targetUserId = $userId;
        if ($isAdmin && isset($_GET['user_id'])) {
            $candidate = (int)$_GET['user_id'];
            if ($candidate > 0) {
                $targetUserId = $candidate;
            }
        } elseif ($isStaff && isset($_GET['user_id'])) {
            $candidate = (int)$_GET['user_id'];
            if ($candidate > 0 && ppf_notifications_staff_can_manage($conn, $userId, $candidate)) {
                $targetUserId = $candidate;
            }
        }

        switch ($method) {
            case 'GET':
                if (count($segments) === 0) {
                    $filters = [
                        'status' => $_GET['status'] ?? null,
                        'type' => $_GET['type'] ?? null,
                        'priority' => $_GET['priority'] ?? null,
                        'q' => $_GET['q'] ?? null,
                        'date_from' => $_GET['date_from'] ?? null,
                        'date_to' => $_GET['date_to'] ?? null,
                        'actor' => $_GET['actor'] ?? null,
                    ];
                    $options = [
                        'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
                        'per_page' => isset($_GET['per_page']) ? (int)$_GET['per_page'] : null,
                        'sort' => $_GET['sort'] ?? null,
                    ];
                    $data = ppf_notifications_query($conn, $tenantId, $targetUserId, $filters, $options);
                    $data['types'] = ppf_notifications_types();
                    $data['priorities'] = ppf_notifications_priorities();
                    $data['target_user_id'] = $targetUserId;
                    $data['unread'] = ppf_notifications_unread_count($conn, $tenantId, $targetUserId, $data['settings'] ?? null);
                    ppf_notifications_response($data);
                }

                if (count($segments) === 1 && is_numeric($segments[0])) {
                    $notificationId = (int)$segments[0];
                    $allowTenantScope = $isAdmin || ($isStaff && ppf_notifications_staff_can_manage($conn, $userId, $targetUserId));
                    $record = ppf_notifications_get($conn, $tenantId, $targetUserId, $notificationId, $allowTenantScope);
                    if (!$record) {
                        ppf_notifications_response(['error' => 'Not found'], 404);
                    }
                    ppf_notifications_response(['data' => $record]);
                }

                if (count($segments) === 1 && $segments[0] === 'settings') {
                    $settings = ppf_notifications_settings_get($conn, $tenantId, $targetUserId);
                    ppf_notifications_response(['data' => $settings]);
                }

                ppf_notifications_response(['error' => 'Not found'], 404);
                break;

            case 'POST':
                if (count($segments) === 0) {
                    ppf_notifications_rate_limit('create');
                    $body = ppf_notifications_parse_json_body();
                    $title = trim((string)($body['title'] ?? ''));
                    $content = trim((string)($body['body'] ?? ''));
                    if ($title === '' && $content === '') {
                        ppf_notifications_response(['error' => 'Title or body required'], 422);
                    }
                    $type = strtolower((string)($body['type'] ?? 'info'));
                    if (!isset(ppf_notifications_types()[$type])) {
                        ppf_notifications_response(['error' => 'Unsupported notification type'], 422);
                    }
                    $priority = (int)($body['priority'] ?? 0);
                    if (!isset(ppf_notifications_priorities()[$priority])) {
                        $priority = 0;
                    }
                    $category = isset($body['category']) ? ppf_notifications_valid_category((string)$body['category']) : 'system';
                    $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
                    $metadata['category'] = $category;
                    $metadata['actor'] = $isAdmin ? 'admin' : ($isStaff ? 'staff' : 'user');
                    $metadata['actor_user_id'] = $userId;
                    $actions = is_array($body['actions'] ?? null) ? $body['actions'] : [];
                    $url = trim((string)($body['url'] ?? ''));
                    $sendEmail = !empty($body['send_email']);
                    $targetMode = $body['target_mode'] ?? 'self';
                    $created = [];

                    $fanoutUsers = [$targetUserId];
                    if ($isAdmin && $targetMode !== 'self') {
                        if ($targetMode === 'user' && !empty($body['user_id'])) {
                            $candidate = (int)$body['user_id'];
                            if ($candidate > 0) {
                                if ($stmt = $conn->prepare('SELECT id FROM users WHERE tenant_id = ? AND id = ? LIMIT 1')) {
                                    $stmt->bind_param('ii', $tenantId, $candidate);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    if ($res && $row = $res->fetch_assoc()) {
                                        $fanoutUsers = [(int)$row['id']];
                                    }
                                    $stmt->close();
                                }
                            }
                        } elseif ($targetMode === 'role' && !empty($body['role'])) {
                            $roleValue = (string)$body['role'];
                            $fanoutUsers = [];
                            if ($stmt = $conn->prepare('SELECT id FROM users WHERE tenant_id = ? AND role = ?')) {
                                $stmt->bind_param('is', $tenantId, $roleValue);
                                $stmt->execute();
                                $res = $stmt->get_result();
                                while ($res && ($row = $res->fetch_assoc())) {
                                    $fanoutUsers[] = (int)$row['id'];
                                }
                                $stmt->close();
                            }
                        } elseif ($targetMode === 'all') {
                            $fanoutUsers = [];
                            if ($stmt = $conn->prepare('SELECT id FROM users WHERE tenant_id = ?')) {
                                $stmt->bind_param('i', $tenantId);
                                $stmt->execute();
                                $res = $stmt->get_result();
                                while ($res && ($row = $res->fetch_assoc())) {
                                    $fanoutUsers[] = (int)$row['id'];
                                }
                                $stmt->close();
                            }
                        }
                    }

                    $fanoutUsers = array_values(array_unique(array_filter($fanoutUsers, fn($id) => $id > 0)));
                    if (empty($fanoutUsers)) {
                        $fanoutUsers = [$targetUserId];
                    }

                    foreach ($fanoutUsers as $fanoutUserId) {
                        $id = ppf_notifications_upsert($conn, $fanoutUserId, [
                            'title' => $title,
                            'body' => $content,
                            'type' => $type,
                            'priority' => $priority,
                            'url' => $url,
                            'metadata' => $metadata,
                            'actions' => $actions,
                            'send_email' => $sendEmail,
                            'category' => $category,
                        ]);
                        if ($id) {
                            $created[] = $id;
                        }
                    }

                    ppf_notifications_response(['ok' => true, 'created_ids' => $created], empty($created) ? 500 : 201);
                }

                if (count($segments) === 1 && $segments[0] === 'settings') {
                    ppf_notifications_rate_limit('settings');
                    $body = ppf_notifications_parse_json_body();
                    $success = ppf_notifications_settings_put($conn, $tenantId, $targetUserId, $body);
                    ppf_notifications_response(['ok' => $success]);
                }

                ppf_notifications_response(['error' => 'Not found'], 404);
                break;

            case 'PUT':
                if (count($segments) === 1 && is_numeric($segments[0])) {
                    ppf_notifications_rate_limit('update');
                    $notificationId = (int)$segments[0];
                    $allowTenantScope = $isAdmin || ($isStaff && ppf_notifications_staff_can_manage($conn, $userId, $targetUserId));
                    $record = ppf_notifications_get($conn, $tenantId, $targetUserId, $notificationId, $allowTenantScope);
                    if (!$record) {
                        ppf_notifications_response(['error' => 'Not found'], 404);
                    }
                    $body = ppf_notifications_parse_json_body();
                    $payload = [
                        'title' => $body['title'] ?? $record['title'],
                        'body' => $body['body'] ?? $record['body'],
                        'type' => $body['type'] ?? $record['type'],
                        'url' => $body['url'] ?? $record['url'],
                        'priority' => $body['priority'] ?? $record['priority'],
                        'metadata' => $body['metadata'] ?? array_merge($record['metadata'] ?? [], [
                            'actor' => $isAdmin ? 'admin' : ($isStaff ? 'staff' : 'user'),
                            'actor_user_id' => $userId,
                        ]),
                        'actions' => $body['actions'] ?? $record['actions'],
                        'category' => $body['category'] ?? ($record['metadata']['category'] ?? 'system'),
                        'send_email' => $body['send_email'] ?? ($record['metadata']['send_email'] ?? false),
                    ];
                    $saved = ppf_notifications_upsert($conn, $targetUserId, $payload, $notificationId);
                    if (!$saved) {
                        ppf_notifications_response(['error' => 'Unable to update'], 500);
                    }
                    $updated = ppf_notifications_get($conn, $tenantId, $targetUserId, $notificationId, true);
                    ppf_notifications_response(['ok' => true, 'data' => $updated]);
                }
                ppf_notifications_response(['error' => 'Not found'], 404);
                break;

            case 'PATCH':
                if (count($segments) === 2 && is_numeric($segments[0]) && $segments[1] === 'read') {
                    ppf_notifications_rate_limit('mark');
                    $notificationId = (int)$segments[0];
                    $ok = ppf_notifications_set_read($conn, $targetUserId, $notificationId, true);
                    ppf_notifications_response(['ok' => $ok, 'unread' => ppf_notifications_unread_count($conn, $tenantId, $targetUserId)]);
                }
                if (count($segments) === 2 && is_numeric($segments[0]) && $segments[1] === 'unread') {
                    ppf_notifications_rate_limit('mark');
                    $notificationId = (int)$segments[0];
                    $ok = ppf_notifications_set_read($conn, $targetUserId, $notificationId, false);
                    ppf_notifications_response(['ok' => $ok, 'unread' => ppf_notifications_unread_count($conn, $tenantId, $targetUserId)]);
                }
                if (count($segments) === 2 && is_numeric($segments[0]) && $segments[1] === 'archive') {
                    ppf_notifications_rate_limit('archive');
                    $notificationId = (int)$segments[0];
                    $body = ppf_notifications_parse_json_body();
                    $archive = isset($body['archived']) ? (bool)$body['archived'] : true;
                    $ok = ppf_notifications_set_archived($conn, $tenantId, $targetUserId, $notificationId, $archive);
                    ppf_notifications_response(['ok' => $ok]);
                }
                if (count($segments) === 1 && $segments[0] === 'bulk') {
                    ppf_notifications_rate_limit('bulk');
                    $body = ppf_notifications_parse_json_body();
                    $ids = array_filter(array_map('intval', $body['ids'] ?? []), fn($id) => $id > 0);
                    $operation = $body['operation'] ?? '';
                    $results = ['processed' => []];
                    if (($body['scope'] ?? '') === 'all' && $operation === 'read') {
                        $ok = ppf_notifications_mark_all_read($conn, $targetUserId);
                        $results['processed'][] = ['scope' => 'all', 'ok' => $ok];
                        $results['unread'] = ppf_notifications_unread_count($conn, $tenantId, $targetUserId);
                        ppf_notifications_response($results);
                    }
                    foreach ($ids as $id) {
                        $ok = false;
                        switch ($operation) {
                            case 'read':
                                $ok = ppf_notifications_set_read($conn, $targetUserId, $id, true);
                                break;
                            case 'unread':
                                $ok = ppf_notifications_set_read($conn, $targetUserId, $id, false);
                                break;
                            case 'archive':
                                $ok = ppf_notifications_set_archived($conn, $tenantId, $targetUserId, $id, true);
                                break;
                            case 'delete':
                                $ok = $isAdmin ? ppf_notifications_delete($conn, $targetUserId, $id, true) : ppf_notifications_delete($conn, $targetUserId, $id, false);
                                break;
                        }
                        $results['processed'][] = ['id' => $id, 'ok' => $ok];
                    }
                    $results['unread'] = ppf_notifications_unread_count($conn, $tenantId, $targetUserId);
                    ppf_notifications_response($results);
                }
                ppf_notifications_response(['error' => 'Not found'], 404);
                break;

            case 'DELETE':
                if (count($segments) === 1 && is_numeric($segments[0])) {
                    ppf_notifications_rate_limit('delete');
                    $notificationId = (int)$segments[0];
                    $hard = $isAdmin && !empty($_GET['hard']);
                    $ok = ppf_notifications_delete($conn, $targetUserId, $notificationId, $hard);
                    ppf_notifications_response(['ok' => $ok]);
                }
                ppf_notifications_response(['error' => 'Not found'], 404);
                break;

            default:
                ppf_notifications_response(['error' => 'Method not allowed'], 405);
        }
    }
}

if (!defined('PPF_NOTIFICATIONS_TEST_MODE') || !PPF_NOTIFICATIONS_TEST_MODE) {
    ppf_notifications_dispatch();
}
