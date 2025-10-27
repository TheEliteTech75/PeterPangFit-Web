<?php
declare(strict_types=1);

define('PPF_NOTIFICATIONS_TEST_MODE', true);

require_once __DIR__ . '/notifications_fakes.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../api/notifications/index.php';
require_once __DIR__ . '/../api/notifications/unread_count.php';
require_once __DIR__ . '/../api/notifications/health.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** @var array<int, array{string, callable}> */
$tests = [];

function register_test(string $name, callable $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function build_conn(): PPFNotificationsFakeMysqli
{
    $conn = new PPFNotificationsFakeMysqli();
    $conn->users = [];
    return $conn;
}

function reset_session(array $overrides = []): void
{
    $_SESSION = array_merge([
        'user_id' => 10,
        'role' => 'admin',
        'tenant_id' => 1,
        'csrf_token' => 'test-token',
        'notifications_rate' => [],
    ], $overrides);
}

/**
 * @param PPFNotificationsFakeMysqli $conn
 * @param array<string,mixed> $server
 * @param array<string,mixed> $query
 * @param array|null $body
 * @return array{status:int, body:array}
 */
function call_notifications_api(PPFNotificationsFakeMysqli $conn, array $server, array $query = [], ?array $body = null): array
{
    global $GLOBALS;
    $GLOBALS['conn'] = $conn;
    $_SERVER = array_merge([
        'REQUEST_METHOD' => 'GET',
        'PATH_INFO' => '',
    ], $server);
    $_GET = $query;
    if ($body !== null) {
        $GLOBALS['PPF_NOTIFICATIONS_TEST_BODY'] = json_encode($body);
    } else {
        unset($GLOBALS['PPF_NOTIFICATIONS_TEST_BODY']);
    }
    try {
        ppf_notifications_dispatch();
    } catch (PPF_Notifications_Test_Response $response) {
        return ['status' => $response->status, 'body' => $response->payload];
    }
    return ['status' => 200, 'body' => []];
}

function call_unread_count_endpoint(PPFNotificationsFakeMysqli $conn): array
{
    global $GLOBALS;
    $GLOBALS['conn'] = $conn;
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    try {
        return ppf_notifications_unread_count_endpoint();
    } catch (PPF_Notifications_Test_Response $response) {
        return $response->payload;
    }
}

function call_health_endpoint(PPFNotificationsFakeMysqli $conn): array
{
    global $GLOBALS;
    $GLOBALS['conn'] = $conn;
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    try {
        return ppf_notifications_health_endpoint();
    } catch (PPF_Notifications_Test_Response $response) {
        return $response->payload;
    }
}

register_test('list_filters_with_pagination', function () {
    $conn = build_conn();
    reset_session();

    $id1 = $conn->insertNotification(1, 10, [
        'title' => 'Plan Assigned',
        'body' => 'Trainer assigned a new plan',
        'type' => 'success',
        'priority' => 1,
        'metadata' => ['actor' => 'system'],
    ]);
    $conn->notifications[$id1]['created_at'] = '2024-05-01 12:00:00';

    $id2 = $conn->insertNotification(1, 10, [
        'title' => 'Password Updated',
        'body' => 'Your password was changed',
        'type' => 'system',
        'priority' => 0,
        'metadata' => ['actor' => 'system'],
    ]);
    $conn->notifications[$id2]['created_at'] = '2024-04-01 09:00:00';
    $conn->notifications[$id2]['is_read'] = true;

    $id3 = $conn->insertNotification(1, 10, [
        'title' => 'Archived Item',
        'body' => 'Old notice',
        'type' => 'info',
        'priority' => 0,
        'metadata' => ['actor' => 'user'],
    ]);
    $conn->notifications[$id3]['created_at'] = '2024-03-01 09:00:00';
    $conn->notifications[$id3]['is_archived'] = true;

    $resp = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'GET',
        'PATH_INFO' => '',
    ], [
        'status' => 'unread',
        'type' => 'success',
        'priority' => '1',
        'date_from' => '2024-04-15',
        'date_to' => '2024-05-10',
        'q' => 'plan',
        'actor' => 'system',
        'per_page' => '10',
        'page' => '1',
    ]);

    assert_same(200, $resp['status'], 'Expected HTTP 200');
    $body = $resp['body'];
    assert_true(isset($body['data']) && count($body['data']) === 1, 'Expected exactly one filtered notification');
    assert_same($id1, $body['data'][0]['id'], 'Filtered notification ID should match');
    assert_same(1, $body['pagination']['total'], 'Total count should reflect filtered items');
    assert_same(1, $body['unread'], 'Unread count should match filtered unread notifications');
});

register_test('read_unread_idempotency', function () {
    $conn = build_conn();
    reset_session();

    $id = $conn->insertNotification(1, 10, [
        'title' => 'Security Alert',
        'body' => 'Suspicious login',
        'type' => 'warning',
        'priority' => 1,
        'metadata' => ['actor' => 'system'],
    ]);
    $conn->notifications[$id]['created_at'] = '2024-05-01 15:00:00';

    $resp1 = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/' . $id . '/read',
    ]);
    assert_same(200, $resp1['status'], 'First mark read should succeed');
    assert_true($resp1['body']['ok'], 'First mark read should return ok');
    assert_same(0, $resp1['body']['unread'], 'Unread count should be zero after marking read');
    assert_true($conn->notifications[$id]['is_read'], 'Notification should be marked read in storage');

    $resp2 = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/' . $id . '/read',
    ]);
    assert_same(200, $resp2['status'], 'Second mark read should succeed');
    assert_true($resp2['body']['ok'], 'Second mark read should still report ok');
    assert_same(0, $resp2['body']['unread'], 'Unread count should remain zero after idempotent call');

    $resp3 = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/' . $id . '/unread',
    ]);
    assert_same(200, $resp3['status'], 'Mark unread should succeed');
    assert_true($resp3['body']['ok'], 'Mark unread should return ok');
    assert_same(1, $resp3['body']['unread'], 'Unread count should increment after marking unread');
});

register_test('bulk_operations', function () {
    $conn = build_conn();
    reset_session();

    $ids = [];
    foreach ([['Workout Assigned', 'success'], ['Invoice Paid', 'success'], ['Session Cancelled', 'warning']] as $index => $meta) {
        $id = $conn->insertNotification(1, 10, [
            'title' => $meta[0],
            'body' => 'body',
            'type' => $meta[1],
            'priority' => $index % 2,
            'metadata' => ['actor' => 'system'],
        ]);
        $conn->notifications[$id]['created_at'] = '2024-05-0' . ($index + 1) . ' 08:00:00';
        $ids[] = $id;
    }

    $resp = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/bulk',
    ], [], [
        'ids' => [$ids[0], $ids[1]],
        'operation' => 'read',
    ]);
    assert_same(200, $resp['status'], 'Bulk read should succeed');
    assert_true($resp['body']['processed'][0]['ok'], 'First entry should be processed');
    assert_true($conn->notifications[$ids[0]]['is_read'], 'First notification should be read');
    assert_true($conn->notifications[$ids[1]]['is_read'], 'Second notification should be read');

    $respArchive = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/bulk',
    ], [], [
        'ids' => [$ids[1]],
        'operation' => 'archive',
    ]);
    assert_same(200, $respArchive['status'], 'Bulk archive should succeed');
    assert_true($conn->notifications[$ids[1]]['is_archived'], 'Notification should be archived');

    $respDelete = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/bulk',
    ], [], [
        'ids' => [$ids[2]],
        'operation' => 'delete',
    ]);
    assert_same(200, $respDelete['status'], 'Bulk delete should succeed');
    assert_true($respDelete['body']['processed'][0]['ok'], 'Delete processed flag should be ok');
    assert_true(!isset($conn->notifications[$ids[2]]), 'Notification should be removed after delete');
});

register_test('tenant_isolation', function () {
    $conn = build_conn();
    reset_session();

    $id1 = $conn->insertNotification(1, 10, [
        'title' => 'Tenant One',
        'body' => 'only tenant one should see',
        'type' => 'info',
        'metadata' => ['actor' => 'system'],
    ]);
    $conn->notifications[$id1]['created_at'] = '2024-05-01 08:00:00';

    $id2 = $conn->insertNotification(2, 20, [
        'title' => 'Tenant Two',
        'body' => 'different tenant',
        'type' => 'info',
        'metadata' => ['actor' => 'system'],
    ]);
    $conn->notifications[$id2]['created_at'] = '2024-05-01 09:00:00';

    $listResp = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'GET',
        'PATH_INFO' => '',
    ]);
    assert_same(200, $listResp['status'], 'Tenant scoped list should succeed');
    assert_same(1, count($listResp['body']['data']), 'Only tenant 1 data should be returned');
    assert_same($id1, $listResp['body']['data'][0]['id'], 'Returned notification should belong to tenant 1');

    $readResp = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'PATCH',
        'PATH_INFO' => '/' . $id2 . '/read',
    ]);
    assert_same(200, $readResp['status'], 'Cross-tenant read returns response');
    assert_true(!$readResp['body']['ok'], 'Cross-tenant read should not succeed');
});

register_test('rbac_staff_scope', function () {
    $conn = build_conn();
    reset_session(['user_id' => 30, 'role' => 'trainer']);

    $conn->insertNotification(1, 30, [
        'title' => 'Trainer Notice',
        'body' => 'for trainer',
        'type' => 'info',
        'metadata' => ['actor' => 'system'],
    ]);
    $otherId = $conn->insertNotification(1, 40, [
        'title' => 'Other User',
        'body' => 'should not be visible',
        'type' => 'info',
        'metadata' => ['actor' => 'system'],
    ]);

    $resp = call_notifications_api($conn, [
        'REQUEST_METHOD' => 'GET',
        'PATH_INFO' => '',
    ], ['user_id' => '40']);

    assert_same(200, $resp['status'], 'Staff scoped list should succeed');
    assert_same(1, count($resp['body']['data']), 'Staff should still only see their own notifications');
    assert_true($resp['body']['data'][0]['user_id'] === 30, 'Returned user ID should match staff user');
    assert_true(isset($conn->notifications[$otherId]), 'Other user notification remains untouched');
});

register_test('unread_count_and_health', function () {
    $conn = build_conn();
    reset_session();

    $id = $conn->insertNotification(1, 10, [
        'title' => 'Badge Test',
        'body' => 'badge count',
        'type' => 'info',
        'metadata' => ['actor' => 'system'],
    ]);
    $conn->notifications[$id]['created_at'] = '2024-05-02 12:00:00';

    $unread = call_unread_count_endpoint($conn);
    assert_same(1, $unread['count'], 'Unread endpoint should report unread notifications');

    $health = call_health_endpoint($conn);
    assert_true($health['ok'], 'Health endpoint should report ok');
    assert_same(1, $health['unread'], 'Health endpoint should include unread count');
});

$failures = 0;
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
    }
}

echo "\n" . count($tests) . " tests run, " . $failures . " failures.\n";

exit($failures > 0 ? 1 : 0);
