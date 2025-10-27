<?php
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$tenantId = ppf_current_tenant_id();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pathInfo = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
$segments = $pathInfo === '' ? [] : explode('/', $pathInfo);

$catalog = ppf_notifications_catalog();
$categories = ppf_notification_categories();
$types = ppf_notifications_types();
$priorities = ppf_notifications_priorities();

ppf_notifications_seed_defaults($conn, $tenantId, $userId);

function ppf_notifications_api_csrf_required(): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $headerToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid session token.']);
        exit;
    }
}

function ppf_notifications_api_unread(mysqli $conn, int $tenantId, int $userId, ?array $settings = null): int
{
    try {
        return ppf_notifications_unread_count($conn, $tenantId, $userId, $settings);
    } catch (Throwable $e) {
        return 0;
    }
}

function ppf_notifications_api_catalog_by_category(array $catalog, array $categories): array
{
    $grouped = [];
    foreach ($categories as $key => $meta) {
        $grouped[$key] = [];
    }
    foreach ($catalog as $typeKey => $definition) {
        $cat = strtolower((string)($definition['category'] ?? 'system'));
        if (!array_key_exists($cat, $grouped)) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = array_merge($definition, [
            'type_key' => $typeKey,
        ]);
    }
    return $grouped;
}

function ppf_notifications_api_decode_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ppf_notifications_api_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function ppf_notifications_api_success(array $payload): void
{
    echo json_encode(array_merge(['ok' => true], $payload));
    exit;
}

function ppf_notifications_api_get(mysqli $conn, int $tenantId, int $userId, int $notificationId): ?array
{
    try {
        return ppf_notifications_get($conn, $tenantId, $userId, $notificationId);
    } catch (Throwable $e) {
        return null;
    }
}

function ppf_notifications_api_is_immutable(?array $notification): bool
{
    if (!$notification) {
        return false;
    }
    $metadata = $notification['metadata'] ?? [];
    if (isset($metadata['immutable']) && $metadata['immutable']) {
        return true;
    }
    $typeKey = (string)($metadata['type_key'] ?? '');
    return str_starts_with($typeKey, 'security.');
}

$isRuleRequest = !empty($segments) && strtolower($segments[0]) === 'rules';
if ($isRuleRequest) {
    array_shift($segments);
    switch ($method) {
        case 'GET':
            if (count($segments) === 0) {
                $rules = ppf_notification_rules_list($conn, $tenantId, $userId);
                ppf_notifications_api_success([
                    'rules' => $rules,
                    'catalog' => ppf_notifications_api_catalog_by_category($catalog, $categories),
                ]);
            }
            if (count($segments) === 1 && ctype_digit($segments[0])) {
                $ruleId = (int)$segments[0];
                $rule = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
                if (!$rule) {
                    ppf_notifications_api_error(404, 'Notification rule not found.');
                }
                ppf_notifications_api_success(['data' => $rule]);
            }
            ppf_notifications_api_error(404, 'Resource not found.');
            break;

        case 'POST':
            ppf_notifications_api_csrf_required();
            $payload = ppf_notifications_api_decode_body();
            $actionKey = isset($payload['action']) ? (string)$payload['action'] : ((string)($payload['type_key'] ?? ''));
            if ($actionKey === '') {
                ppf_notifications_api_error(422, 'Action is required.');
            }
            $definition = $catalog[$actionKey] ?? null;
            if ($definition && !empty($definition['immutable'])) {
                ppf_notifications_api_error(403, 'Security rules are managed automatically.');
            }
            $categoryKey = isset($payload['category']) ? ppf_notifications_valid_category((string)$payload['category']) : ($definition['category'] ?? 'custom');
            $title = trim((string)($payload['title'] ?? ''));
            $body = trim((string)($payload['body'] ?? ''));
            if ($title === '' && $definition) {
                $title = (string)($definition['title'] ?? 'Notification');
            }
            if ($body === '' && $definition) {
                $body = (string)($definition['body'] ?? '');
            }
            if ($title === '') {
                ppf_notifications_api_error(422, 'Title is required.');
            }
            $sendEmail = !empty($payload['send_email']);
            if (!$sendEmail && $definition && !empty($definition['send_email']) && !empty($definition['immutable'])) {
                $sendEmail = true;
            }
            $channels = ['center' => true, 'email' => $sendEmail];
            if (!empty($payload['channels']) && is_array($payload['channels'])) {
                $channels['email'] = !empty($payload['channels']['email']);
            }
            $rulePayload = [
                'type_key' => $actionKey,
                'title' => $title,
                'body' => $body,
                'category' => $categoryKey,
                'send_email' => $channels['email'],
                'channels' => $channels,
                'priority' => $definition['priority'] ?? 0,
            ];
            $ruleId = ppf_notification_rules_save($conn, $tenantId, $userId, $rulePayload, null);
            if (!$ruleId) {
                ppf_notifications_api_error(500, 'Unable to create notification rule.');
            }
            $rule = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
            ppf_notifications_api_success(['data' => $rule]);
            break;

        case 'PATCH':
            if (count($segments) === 0) {
                ppf_notifications_api_error(404, 'Resource not found.');
            }
            ppf_notifications_api_csrf_required();
            $ruleId = ctype_digit($segments[0]) ? (int)$segments[0] : 0;
            if ($ruleId <= 0) {
                ppf_notifications_api_error(404, 'Notification rule not found.');
            }
            $rule = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
            if (!$rule) {
                ppf_notifications_api_error(404, 'Notification rule not found.');
            }
            if (count($segments) === 2) {
                $action = strtolower($segments[1]);
                if ($action === 'channels') {
                    $body = ppf_notifications_api_decode_body();
                    $email = !empty($body['email']);
                    $updated = ppf_notification_rules_toggle_email($conn, $tenantId, $userId, $ruleId, $email);
                    if (!$updated) {
                        ppf_notifications_api_error(500, 'Unable to update rule channels.');
                    }
                    ppf_notifications_api_success(['data' => $updated]);
                }
            }
            if (!empty($rule['immutable'])) {
                ppf_notifications_api_error(403, 'This notification rule cannot be modified.');
            }
            $body = ppf_notifications_api_decode_body();
            $category = isset($body['category']) ? ppf_notifications_valid_category((string)$body['category']) : $rule['category'];
            $title = array_key_exists('title', $body) ? trim((string)$body['title']) : $rule['title'];
            $message = array_key_exists('body', $body) ? trim((string)$body['body']) : $rule['body'];
            $sendEmail = array_key_exists('send_email', $body) ? (bool)$body['send_email'] : (bool)$rule['send_email'];
            $channels = $rule['channels'];
            $channels['email'] = $sendEmail;
            $updatePayload = [
                'type_key' => $rule['type_key'],
                'title' => $title,
                'body' => $message,
                'category' => $category,
                'send_email' => $sendEmail,
                'channels' => $channels,
                'priority' => $rule['priority'],
            ];
            $updatedId = ppf_notification_rules_save($conn, $tenantId, $userId, $updatePayload, $ruleId);
            if (!$updatedId) {
                ppf_notifications_api_error(500, 'Unable to update notification rule.');
            }
            $updated = ppf_notification_rules_get($conn, $tenantId, $userId, $updatedId);
            ppf_notifications_api_success(['data' => $updated]);
            break;

        case 'DELETE':
            if (count($segments) !== 1 || !ctype_digit($segments[0])) {
                ppf_notifications_api_error(404, 'Resource not found.');
            }
            ppf_notifications_api_csrf_required();
            $ruleId = (int)$segments[0];
            $rule = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
            if (!$rule) {
                ppf_notifications_api_error(404, 'Notification rule not found.');
            }
            if (!empty($rule['immutable'])) {
                ppf_notifications_api_error(403, 'Security rules cannot be deleted.');
            }
            $deleted = ppf_notification_rules_delete($conn, $tenantId, $userId, $ruleId);
            if (!$deleted) {
                ppf_notifications_api_error(500, 'Unable to delete notification rule.');
            }
            ppf_notifications_api_success(['deleted' => $ruleId]);
            break;

        default:
            ppf_notifications_api_error(405, 'Method not allowed.');
    }
}

switch ($method) {
    case 'GET':
        if (count($segments) === 1) {
            $segment = strtolower($segments[0]);
            if ($segment === 'catalog') {
                ppf_notifications_api_success([
                    'catalog' => ppf_notifications_api_catalog_by_category($catalog, $categories),
                ]);
            }
            if ($segment === 'settings') {
                $settings = ppf_notifications_settings_get($conn, $tenantId, $userId);
                ppf_notifications_api_success(['settings' => $settings]);
            }
            if (ctype_digit($segments[0])) {
                $notificationId = (int)$segments[0];
                $notification = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
                if (!$notification) {
                    ppf_notifications_api_error(404, 'Notification not found.');
                }
                ppf_notifications_api_success(['data' => $notification]);
            }
            ppf_notifications_api_error(404, 'Resource not found.');
        }

        if (count($segments) > 1) {
            ppf_notifications_api_error(404, 'Resource not found.');
        }

        $filters = [
            'status' => isset($_GET['status']) ? strtolower((string)$_GET['status']) : 'all',
            'type' => isset($_GET['type']) ? strtolower((string)$_GET['type']) : '',
            'priority' => $_GET['priority'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'q' => trim((string)($_GET['q'] ?? '')),
            'category' => isset($_GET['category']) ? strtolower((string)$_GET['category']) : 'all',
        ];
        $options = [
            'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1,
            'per_page' => isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25,
            'sort' => (string)($_GET['sort'] ?? ''),
        ];
        try {
            $result = ppf_notifications_query($conn, $tenantId, $userId, $filters, $options);
        } catch (Throwable $e) {
            $result = ['data' => [], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0], 'settings' => ppf_notifications_default_settings()];
        }
        $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, $result['settings'] ?? null);
        ppf_notifications_api_success([
            'data' => $result['data'] ?? [],
            'pagination' => $result['pagination'] ?? ['page' => 1, 'per_page' => 25, 'total' => 0],
            'filters' => $filters,
            'settings' => $result['settings'] ?? ppf_notifications_default_settings(),
            'unread' => $unread,
            'types' => $types,
            'priorities' => $priorities,
            'categories' => $categories,
            'catalog' => ppf_notifications_api_catalog_by_category($catalog, $categories),
        ]);
        break;

    case 'POST':
        if (count($segments) > 0) {
            ppf_notifications_api_error(404, 'Resource not found.');
        }
        ppf_notifications_api_csrf_required();
        $payload = ppf_notifications_api_decode_body();
        $categoryKey = isset($payload['category']) ? ppf_notifications_valid_category((string)$payload['category']) : 'custom';
        $actionKey = isset($payload['action']) ? (string)$payload['action'] : '';
        $sendEmail = !empty($payload['send_email']);
        $title = trim((string)($payload['title'] ?? ''));
        $body = trim((string)($payload['body'] ?? ''));
        $type = isset($payload['type']) ? strtolower((string)$payload['type']) : '';
        $priority = isset($payload['priority']) ? (int)$payload['priority'] : 0;

        $definition = null;
        if ($actionKey && isset($catalog[$actionKey])) {
            $definition = $catalog[$actionKey];
            if (!empty($definition['immutable'])) {
                ppf_notifications_api_error(403, 'Security notifications are pre-configured and cannot be created manually.');
            }
        }

        if ($definition) {
            if ($title === '') {
                $title = (string)($definition['title'] ?? 'Notification');
            }
            if ($body === '') {
                $body = (string)($definition['body'] ?? '');
            }
            if ($type === '' && isset($definition['type'])) {
                $type = (string)$definition['type'];
            }
            if (!isset($payload['priority']) && isset($definition['priority'])) {
                $priority = (int)$definition['priority'];
            }
            if ($categoryKey === 'custom' && !empty($definition['category'])) {
                $categoryKey = ppf_notifications_valid_category((string)$definition['category']);
            }
        }

        if ($title === '' && $body === '') {
            ppf_notifications_api_error(422, 'Title or body is required.');
        }

        if ($type === '' || !isset($types[$type])) {
            $type = 'info';
        }
        if (!isset($priorities[$priority])) {
            $priority = 0;
        }

        $data = [
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'priority' => $priority,
            'category' => $categoryKey,
            'send_email' => $sendEmail,
            'channels' => ['email' => $sendEmail],
            'metadata' => [],
            'type_key' => $actionKey ?: 'custom.manual',
        ];

        try {
            $notificationId = ppf_notifications_record($conn, $userId, array_merge($data, [
                'actor_user_id' => $userId,
            ]));
        } catch (Throwable $e) {
            $notificationId = null;
        }

        if (!$notificationId) {
            ppf_notifications_api_error(500, 'Unable to create notification.');
        }

        $record = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
        $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
        ppf_notifications_api_success([
            'data' => $record,
            'unread' => $unread,
        ]);
        break;

    case 'PATCH':
        if (count($segments) === 0) {
            ppf_notifications_api_error(404, 'Resource not found.');
        }
        ppf_notifications_api_csrf_required();
        if ($segments[0] === 'bulk') {
            $body = ppf_notifications_api_decode_body();
            $operation = strtolower((string)($body['operation'] ?? ''));
            $processed = [];
            if (($body['scope'] ?? '') === 'all' && $operation === 'read') {
                $ok = ppf_notifications_mark_all_read($conn, $userId);
                $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
                ppf_notifications_api_success(['processed' => $ok ? ['all'] : [], 'unread' => $unread]);
            }
            $ids = array_filter(array_map('intval', (array)($body['ids'] ?? [])), function ($id) {
                return $id > 0;
            });
            foreach ($ids as $id) {
                if ($operation === 'read') {
                    if (ppf_notifications_set_read($conn, $userId, $id, true)) {
                        $processed[] = $id;
                    }
                } elseif ($operation === 'unread') {
                    if (ppf_notifications_set_read($conn, $userId, $id, false)) {
                        $processed[] = $id;
                    }
                }
            }
            $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
            ppf_notifications_api_success(['processed' => $processed, 'unread' => $unread]);
        }

        $notificationId = ctype_digit($segments[0]) ? (int)$segments[0] : 0;
        if ($notificationId <= 0) {
            ppf_notifications_api_error(404, 'Notification not found.');
        }
        $target = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
        if (!$target) {
            ppf_notifications_api_error(404, 'Notification not found.');
        }

        if (count($segments) === 2) {
            $action = strtolower($segments[1]);
            if ($action === 'read' || $action === 'unread') {
                $shouldRead = $action === 'read';
                $ok = ppf_notifications_set_read($conn, $userId, $notificationId, $shouldRead);
                if (!$ok) {
                    ppf_notifications_api_error(500, 'Unable to update notification state.');
                }
                $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
                ppf_notifications_api_success([
                    'data' => ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId),
                    'unread' => $unread,
                ]);
            }
            if ($action === 'archive') {
                $body = ppf_notifications_api_decode_body();
                $archived = isset($body['archived']) ? (bool)$body['archived'] : true;
                $ok = ppf_notifications_set_archived($conn, $tenantId, $userId, $notificationId, $archived);
                if (!$ok) {
                    ppf_notifications_api_error(500, 'Unable to archive notification.');
                }
                $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
                ppf_notifications_api_success([
                    'data' => ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId),
                    'unread' => $unread,
                ]);
            }
            if ($action === 'channels') {
                $body = ppf_notifications_api_decode_body();
                $email = !empty($body['email']);
                $ok = ppf_notifications_toggle_email($conn, $userId, $notificationId, $email);
                if (!$ok) {
                    ppf_notifications_api_error(500, 'Unable to update channels.');
                }
                $record = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
                if ($record) {
                    $metadata = $record['metadata'] ?? [];
                    if (!isset($metadata['channels']) || !is_array($metadata['channels'])) {
                        $metadata['channels'] = ['center' => true];
                    }
                    $metadata['channels']['email'] = $email;
                    $metadata['send_email'] = $email;
                    $json = json_encode($metadata);
                    if ($stmt = $conn->prepare('UPDATE notifications SET metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?')) {
                        $stmt->bind_param('siii', $json, $tenantId, $userId, $notificationId);
                        $stmt->execute();
                        $stmt->close();
                        $record = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
                    }
                }
                $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
                ppf_notifications_api_success([
                    'data' => $record,
                    'unread' => $unread,
                ]);
            }
            ppf_notifications_api_error(404, 'Unsupported action.');
        }

        if (ppf_notifications_api_is_immutable($target)) {
            ppf_notifications_api_error(403, 'This notification cannot be modified.');
        }

        $body = ppf_notifications_api_decode_body();
        $title = array_key_exists('title', $body) ? trim((string)$body['title']) : $target['title'];
        $message = array_key_exists('body', $body) ? trim((string)$body['body']) : $target['body'];
        $type = array_key_exists('type', $body) ? strtolower((string)$body['type']) : $target['type'];
        $priority = array_key_exists('priority', $body) ? (int)$body['priority'] : (int)$target['priority'];
        $category = array_key_exists('category', $body) ? ppf_notifications_valid_category((string)$body['category']) : ($target['metadata']['category'] ?? 'system');
        $sendEmail = array_key_exists('send_email', $body) ? (bool)$body['send_email'] : (bool)($target['metadata']['send_email'] ?? false);
        $actionKey = (string)($target['metadata']['type_key'] ?? 'custom.manual');

        if ($title === '' && $message === '') {
            ppf_notifications_api_error(422, 'Title or body is required.');
        }
        if (!isset($types[$type])) {
            $type = 'info';
        }
        if (!isset($priorities[$priority])) {
            $priority = 0;
        }

        $channels = $target['metadata']['channels'] ?? ['center' => true];
        $channels['email'] = $sendEmail;

        $updateData = [
            'title' => $title,
            'body' => $message,
            'type' => $type,
            'priority' => $priority,
            'category' => $category,
            'send_email' => $sendEmail,
            'channels' => $channels,
            'metadata' => $target['metadata'] ?? [],
            'type_key' => $actionKey,
        ];

        $updatedId = ppf_notifications_upsert($conn, $userId, $updateData, $notificationId);
        if (!$updatedId) {
            ppf_notifications_api_error(500, 'Unable to update notification.');
        }
        $record = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
        $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
        ppf_notifications_api_success([
            'data' => $record,
            'unread' => $unread,
        ]);
        break;

    case 'DELETE':
        if (count($segments) !== 1) {
            ppf_notifications_api_error(404, 'Resource not found.');
        }
        ppf_notifications_api_csrf_required();
        if (!ctype_digit($segments[0])) {
            ppf_notifications_api_error(404, 'Notification not found.');
        }
        $notificationId = (int)$segments[0];
        $target = ppf_notifications_api_get($conn, $tenantId, $userId, $notificationId);
        if (!$target) {
            ppf_notifications_api_error(404, 'Notification not found.');
        }
        if (ppf_notifications_api_is_immutable($target)) {
            ppf_notifications_api_error(403, 'Security notifications cannot be deleted.');
        }
        $ok = ppf_notifications_delete($conn, $userId, $notificationId, false);
        if (!$ok) {
            ppf_notifications_api_error(500, 'Unable to delete notification.');
        }
        $unread = ppf_notifications_api_unread($conn, $tenantId, $userId, null);
        ppf_notifications_api_success(['deleted' => $notificationId, 'unread' => $unread]);
        break;

    default:
        http_response_code(405);
        header('Allow: GET, POST, PATCH, DELETE');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
}
