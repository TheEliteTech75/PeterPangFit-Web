<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';
require_once __DIR__ . '/send_email.php';

header('Content-Type: application/json');

function client_sessions_respond(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function client_sessions_format_datetime(?string $iso): string {
    if (!$iso) return 'unscheduled';
    try {
        $dt = new DateTime($iso);
        return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

function client_sessions_within_timer_window(array $session): bool {
    $now = new DateTimeImmutable('now');
    $startRaw = $session['scheduled_start'] ?? null;
    if (!$startRaw) {
        return false;
    }
    try {
        $start = new DateTimeImmutable($startRaw);
    } catch (Throwable $e) {
        return false;
    }

    $windowStart = $start->sub(new DateInterval('PT30M'));
    if ($now < $windowStart) {
        return false;
    }

    $endRaw = $session['scheduled_end'] ?? null;
    if ($endRaw) {
        try {
            $end = new DateTimeImmutable($endRaw);
            $windowEnd = $end->add(new DateInterval('PT30M'));
            if ($now > $windowEnd) {
                return false;
            }
        } catch (Throwable $e) {
            // Ignore invalid end dates; treat as open ended after window start.
        }
    }

    return true;
}

function client_sessions_shape_session(array $session): array {
    return [
        'id' => (int)($session['id'] ?? 0),
        'status' => strtolower((string)($session['status'] ?? 'scheduled')),
        'scheduled_start' => $session['scheduled_start'] ?? null,
        'scheduled_end' => $session['scheduled_end'] ?? null,
        'actual_start_at' => $session['actual_start_at'] ?? null,
        'actual_end_at' => $session['actual_end_at'] ?? null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    client_sessions_respond(false, 'Method not allowed.');
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    client_sessions_respond(false, 'Security check failed. Please reload and try again.');
}

$actorId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower((string)($_SESSION['role'] ?? 'guest'));
if ($actorId <= 0) {
    client_sessions_respond(false, 'Please sign in to continue.');
}

ppf_trainer_sessions_ensure_schema($conn);

$action = (string)($_POST['action'] ?? '');
$allowedActions = ['start_session', 'end_session'];
if (!in_array($action, $allowedActions, true)) {
    client_sessions_respond(false, 'Unknown action.');
}

$sessionId = max(0, (int)($_POST['session_id'] ?? 0));
if ($sessionId <= 0) {
    client_sessions_respond(false, 'Missing session id.');
}

$sql = "SELECT s.*, p.package_name, p.client_id, p.trainer_id, p.purchased_sessions, p.price_per_session,
               u.email AS client_email, u.first_name AS client_first, u.last_name AS client_last,
               t.email AS trainer_email, t.first_name AS trainer_first, t.last_name AS trainer_last
        FROM trainer_sessions s
        JOIN trainer_session_packages p ON p.id = s.package_id
        LEFT JOIN users u ON u.id = p.client_id
        LEFT JOIN users t ON t.id = p.trainer_id
        WHERE s.id = ? LIMIT 1";

if (!$stmt = $conn->prepare($sql)) {
    client_sessions_respond(false, 'Unable to load the session.');
}
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$res = $stmt->get_result();
$session = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$session) {
    client_sessions_respond(false, 'Session not found.');
}

$clientId = (int)($session['client_id'] ?? 0);
$trainerId = (int)($session['trainer_id'] ?? 0);
$allowed = false;
if ($role === 'admin') {
    $allowed = true;
} elseif (in_array($role, ['trainer', 'coach'], true) && $trainerId === $actorId) {
    $allowed = true;
} elseif (in_array($role, ['client'], true) && $clientId === $actorId) {
    $allowed = true;
}
if (!$allowed) {
    client_sessions_respond(false, 'You do not have permission to update this session.');
}

$status = strtolower((string)($session['status'] ?? 'scheduled'));
$nowString = date('Y-m-d H:i:s');

if ($action === 'start_session') {
    if ($status === 'completed') {
        client_sessions_respond(true, 'Session already completed.', [
            'session' => client_sessions_shape_session($session),
        ]);
    }
    if ($status === 'cancelled') {
        client_sessions_respond(false, 'Cancelled sessions cannot be started.');
    }
    if ($status === 'in_progress' && !empty($session['actual_start_at'])) {
        client_sessions_respond(true, 'Session already in progress.', [
            'session' => client_sessions_shape_session($session),
        ]);
    }
    if (!client_sessions_within_timer_window($session)) {
        client_sessions_respond(false, 'Sessions can only be started within the allowed window.');
    }

    $updateSql = "UPDATE trainer_sessions
                  SET status='in_progress', actual_start_at = COALESCE(actual_start_at, NOW()), timer_started_by = IF(actual_start_at IS NULL, ?, timer_started_by), updated_at = NOW()
                  WHERE id = ? AND status IN ('scheduled','in_progress')";
    if (!$updateStmt = $conn->prepare($updateSql)) {
        client_sessions_respond(false, 'Unable to update the session.');
    }
    $updateStmt->bind_param('ii', $actorId, $sessionId);
    if (!$updateStmt->execute()) {
        $err = $updateStmt->error;
        $updateStmt->close();
        client_sessions_respond(false, 'Failed to start the session. ' . $err);
    }
    $affected = $updateStmt->affected_rows;
    $updateStmt->close();

    if ($affected <= 0 && $status !== 'in_progress') {
        client_sessions_respond(false, 'Session is no longer pending.');
    }

    $session['status'] = 'in_progress';
    if (empty($session['actual_start_at'])) {
        $session['actual_start_at'] = $nowString;
    }
    $session['timer_started_by'] = $actorId;

    if (function_exists('ppf_log')) {
        $details = json_encode([
            'session_id' => $sessionId,
            'package_id' => $session['package_id'] ?? null,
            'started_at' => $session['actual_start_at'],
            'started_by' => $actorId,
            'source' => 'client_plans',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_started', 'session', (string)$sessionId, $details);
    }

    client_sessions_respond(true, 'Session started.', [
        'session' => client_sessions_shape_session($session),
    ]);
}

// End session flow
if ($status === 'completed' && !empty($session['actual_end_at'])) {
    client_sessions_respond(true, 'Session already completed.', [
        'session' => client_sessions_shape_session($session),
    ]);
}
if ($status === 'cancelled') {
    client_sessions_respond(false, 'Cancelled sessions cannot be ended.');
}
if (!client_sessions_within_timer_window($session)) {
    client_sessions_respond(false, 'Sessions can only be ended within the allowed window.');
}
if (empty($session['actual_start_at']) && $status !== 'in_progress') {
    client_sessions_respond(false, 'Please start the session before ending it.');
}

$updateSql = "UPDATE trainer_sessions
              SET status='completed', actual_end_at = NOW(), completed_at = NOW(), completion_marked_by = ?, timer_ended_by = ?, duration_seconds = CASE WHEN actual_start_at IS NULL THEN duration_seconds ELSE TIMESTAMPDIFF(SECOND, actual_start_at, NOW()) END, updated_at = NOW()
              WHERE id = ? AND status IN ('scheduled','in_progress')";
if (!$updateStmt = $conn->prepare($updateSql)) {
    client_sessions_respond(false, 'Unable to update the session.');
}
$updateStmt->bind_param('iii', $actorId, $actorId, $sessionId);
if (!$updateStmt->execute()) {
    $err = $updateStmt->error;
    $updateStmt->close();
    client_sessions_respond(false, 'Failed to end the session. ' . $err);
}
$affected = $updateStmt->affected_rows;
$updateStmt->close();

if ($affected <= 0) {
    client_sessions_respond(false, 'Session is no longer pending.');
}

$session['status'] = 'completed';
$session['actual_end_at'] = $nowString;
$session['completed_at'] = $nowString;
$session['completion_marked_by'] = $actorId;

if (function_exists('ppf_log')) {
    $details = json_encode([
        'session_id' => $sessionId,
        'package_id' => $session['package_id'] ?? null,
        'completed_at' => $nowString,
        'completed_by' => $actorId,
        'source' => 'client_plans',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_completed', 'session', (string)$sessionId, $details);
}

$packageId = (int)($session['package_id'] ?? 0);
$packageTotals = null;
if ($packageId > 0) {
    $summary = ppf_trainer_sessions_fetch_package_summary($conn, $packageId);
    if ($summary) {
        $purchased = (int)($summary['purchased_sessions'] ?? 0);
        $used = (int)($summary['completed_count'] ?? 0);
        $remaining = max(0, $purchased - $used);
        $packageTotals = [
            'package_id' => $packageId,
            'purchased' => $purchased,
            'used' => $used,
            'remaining' => $remaining,
        ];
    }
}

$overallTotals = null;
if ($clientId > 0) {
    $packages = ppf_trainer_sessions_fetch_packages($conn, null, $clientId);
    $totalPurchased = 0;
    $totalUsed = 0;
    foreach ($packages as $pkg) {
        $totalPurchased += (int)($pkg['purchased_sessions'] ?? 0);
        $totalUsed += (int)($pkg['completed_count'] ?? 0);
    }
    $overallTotals = [
        'purchased' => $totalPurchased,
        'used' => $totalUsed,
        'remaining' => max(0, $totalPurchased - $totalUsed),
    ];
}

$startLabel = client_sessions_format_datetime($session['scheduled_start'] ?? null);
$packageName = $session['package_name'] ?? 'Training Session';
$trainerName = trim(($session['trainer_first'] ?? '') . ' ' . ($session['trainer_last'] ?? ''));
$clientName = trim(($session['client_first'] ?? '') . ' ' . ($session['client_last'] ?? ''));
$trainerEmail = $session['trainer_email'] ?? null;
$clientEmail = $session['client_email'] ?? null;
$emailBody = "Session Completed\n\nPackage: {$packageName}\nScheduled: {$startLabel}\nTrainer: {$trainerName}\nClient: {$clientName}\n\nThis session has been marked complete.";
if ($trainerEmail) {
    @send_plain_email($trainerEmail, $trainerName ?: 'Trainer', 'Session completed: ' . $packageName, $emailBody);
}
if ($clientEmail) {
    @send_plain_email($clientEmail, $clientName ?: 'Client', 'Session completed: ' . $packageName, $emailBody);
}

client_sessions_respond(true, 'Session completed.', [
    'package_totals' => $packageTotals,
    'overall_totals' => $overallTotals,
    'session' => client_sessions_shape_session($session),
]);
