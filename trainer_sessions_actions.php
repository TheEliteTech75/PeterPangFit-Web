<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';
require_once __DIR__ . '/send_email.php';

header('Content-Type: application/json');

$role = strtolower((string)($USER_ROLE ?? ($_SESSION['role'] ?? 'guest')));
if (!in_array($role, ['trainer', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid session. Please reload.']);
    exit;
}

ppf_trainer_sessions_ensure_schema($conn);

$actorId = (int)($USER_ID ?? ($_SESSION['user_id'] ?? 0));
$action = (string)($_POST['action'] ?? '');

function ts_json($data): ?string {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function ts_format_email_datetime(?string $iso): string {
    if (!$iso) return 'unscheduled';
    try {
        $dt = new DateTime($iso);
        return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

function respond(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function ensure_package_access(mysqli $conn, int $packageId, string $role, int $actorId): array {
    $pkg = ppf_trainer_sessions_fetch_package_summary($conn, $packageId);
    if (!$pkg) {
        respond(false, 'Package not found.');
    }
    if ($role !== 'admin' && (int)($pkg['trainer_id'] ?? 0) !== $actorId) {
        respond(false, 'You do not have access to this package.');
    }
    return $pkg;
}

if ($action === 'create_package') {
    $clientId = max(0, (int)($_POST['client_id'] ?? 0));
    $trainerId = $role === 'admin' ? max(0, (int)($_POST['trainer_id'] ?? 0)) : $actorId;
    $name = trim((string)($_POST['package_name'] ?? ''));
    $purchased = max(1, (int)($_POST['purchased_sessions'] ?? 0));
    $pricePer = (float)($_POST['price_per_session'] ?? 0);
    if ($pricePer <= 0) {
        $pricePer = ppf_trainer_sessions_rate_for_quantity($purchased);
    }
    $notes = trim((string)($_POST['notes'] ?? ''));
    $initialPayment = ppf_trainer_sessions_parse_amount($_POST['initial_payment'] ?? 0);

    if ($clientId <= 0) respond(false, 'Client is required.');
    if ($trainerId <= 0) respond(false, 'Trainer is required.');
    if ($name === '') respond(false, 'Package name is required.');

    $sql = "INSERT INTO trainer_session_packages (client_id, trainer_id, package_name, purchased_sessions, price_per_session, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NOW(), NOW())";
    if (!$stmt = $conn->prepare($sql)) {
        respond(false, 'Failed to prepare statement.');
    }
    $notesParam = $notes !== '' ? $notes : '';
    $stmt->bind_param('iisids', $clientId, $trainerId, $name, $purchased, $pricePer, $notesParam);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        respond(false, 'Failed to create package. ' . $err);
    }
    $packageId = $stmt->insert_id;
    $stmt->close();

    if ($initialPayment > 0) {
        if ($txn = $conn->prepare("INSERT INTO trainer_session_transactions (package_id, txn_type, amount, description, created_at, created_by) VALUES (?, 'payment', ?, NULLIF(?, ''), NOW(), ?)") ) {
            $desc = '';
            $txn->bind_param('idsi', $packageId, $initialPayment, $desc, $actorId);
            $txn->execute();
            $txn->close();
        }
    }

    $details = ts_json([
        'package_id' => $packageId,
        'client_id' => $clientId,
        'trainer_id' => $trainerId,
        'name' => $name,
        'purchased_sessions' => $purchased,
        'price_per_session' => $pricePer,
        'initial_payment' => $initialPayment,
    ]);
    if (function_exists('ppf_log')) {
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_package_created', 'session_package', (string)$packageId, $details);
    }

    respond(true, 'Package created.', ['refresh' => true]);
}

if ($action === 'adjust_sessions') {
    $packageId = max(0, (int)($_POST['package_id'] ?? 0));
    $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
    if ($packageId <= 0) respond(false, 'Invalid package.');
    $pkg = ensure_package_access($conn, $packageId, $role, $actorId);

    if ($direction === 'add' || $direction === 'remove') {
        $count = max(1, (int)($_POST['count'] ?? 0));
        $current = (int)($pkg['purchased_sessions'] ?? 0);
        $completed = (int)($pkg['completed_count'] ?? 0);
        $scheduled = (int)($pkg['scheduled_open'] ?? 0);
        if ($direction === 'add') {
            $newTotal = $current + $count;
        } else {
            $newTotal = $current - $count;
            $minRequired = $completed + $scheduled;
            if ($newTotal < $minRequired) {
                respond(false, 'Cannot reduce below completed + scheduled sessions (' . $minRequired . ').');
            }
            if ($newTotal < 0) $newTotal = 0;
        }
        if ($stmt = $conn->prepare("UPDATE trainer_session_packages SET purchased_sessions = ?, updated_at = NOW() WHERE id = ?")) {
            $stmt->bind_param('ii', $newTotal, $packageId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                respond(false, 'Failed to update sessions. ' . $err);
            }
            $stmt->close();
        }
        $details = ts_json([
            'package_id' => $packageId,
            'direction' => $direction,
            'count' => $count,
            'from' => $current,
            'to' => $newTotal,
        ]);
        if (function_exists('ppf_log')) {
            $event = $direction === 'add' ? 'trainer_session_package_incremented' : 'trainer_session_package_decremented';
            ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, $event, 'session_package', (string)$packageId, $details);
        }

        if ($direction === 'remove') {
            $refundAmount = ppf_trainer_sessions_parse_amount($_POST['amount'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($refundAmount > 0) {
                if ($txn = $conn->prepare("INSERT INTO trainer_session_transactions (package_id, txn_type, amount, description, created_at, created_by) VALUES (?, 'refund', ?, NULLIF(?, ''), NOW(), ?)") ) {
                    $txn->bind_param('idsi', $packageId, $refundAmount, $notes, $actorId);
                    $txn->execute();
                    $txn->close();
                }
            }
        }

        respond(true, 'Sessions updated.', ['refresh' => true]);
    }

    if ($direction === 'payment' || $direction === 'refund') {
        $amount = ppf_trainer_sessions_parse_amount($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            respond(false, 'Amount must be greater than zero.');
        }
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($stmt = $conn->prepare("INSERT INTO trainer_session_transactions (package_id, txn_type, amount, description, created_at, created_by) VALUES (?, ?, ?, NULLIF(?, ''), NOW(), ?)") ) {
            $stmt->bind_param('isdsi', $packageId, $direction, $amount, $notes, $actorId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                respond(false, 'Failed to record transaction. ' . $err);
            }
            $stmt->close();
        }
        $details = ts_json([
            'package_id' => $packageId,
            'type' => $direction,
            'amount' => $amount,
            'notes' => $notes,
        ]);
        if (function_exists('ppf_log')) {
            $event = $direction === 'payment' ? 'trainer_session_payment_recorded' : 'trainer_session_refund_recorded';
            ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, $event, 'session_package', (string)$packageId, $details);
        }
        respond(true, ucfirst($direction) . ' recorded.', ['refresh' => true]);
    }

    respond(false, 'Unsupported adjustment.');
}

if ($action === 'schedule_session') {
    $packageId = max(0, (int)($_POST['package_id'] ?? 0));
    $pkg = ensure_package_access($conn, $packageId, $role, $actorId);
    $startRaw = trim((string)($_POST['scheduled_start'] ?? ''));
    $endRaw = trim((string)($_POST['scheduled_end'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($startRaw === '') respond(false, 'Start time is required.');
    try {
        $start = new DateTime($startRaw);
    } catch (Throwable $e) {
        respond(false, 'Invalid start time.');
    }
    $end = null;
    if ($endRaw !== '') {
        try {
            $end = new DateTime($endRaw);
            if ($end <= $start) respond(false, 'End time must be after start time.');
        } catch (Throwable $e) {
            respond(false, 'Invalid end time.');
        }
    }

    $sql = "INSERT INTO trainer_sessions (package_id, scheduled_start, scheduled_end, status, notes, created_at, updated_at) VALUES (?, ?, NULLIF(?, ''), 'scheduled', NULLIF(?, ''), NOW(), NOW())";
    if (!$stmt = $conn->prepare($sql)) {
        respond(false, 'Failed to prepare insert.');
    }
    $startStr = $start->format('Y-m-d H:i:s');
    $endStr = $end ? $end->format('Y-m-d H:i:s') : '';
    $notesParam = $notes !== '' ? $notes : '';
    $stmt->bind_param('isss', $packageId, $startStr, $endStr, $notesParam);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        respond(false, 'Failed to schedule session. ' . $err);
    }
    $sessionId = $stmt->insert_id;
    $stmt->close();

    $details = ts_json([
        'package_id' => $packageId,
        'session_id' => $sessionId,
        'start' => $startStr,
        'end' => $endStr,
        'notes' => $notes,
    ]);
    if (function_exists('ppf_log')) {
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_scheduled', 'session', (string)$sessionId, $details);
    }

    respond(true, 'Session scheduled.', ['refresh' => true]);
}

if ($action === 'reschedule_session') {
    $sessionId = max(0, (int)($_POST['session_id'] ?? 0));
    $sql = "SELECT s.*, p.trainer_id FROM trainer_sessions s JOIN trainer_session_packages p ON p.id = s.package_id WHERE s.id = ? LIMIT 1";
    if (!$stmt = $conn->prepare($sql)) respond(false, 'Failed to load session.');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$session) respond(false, 'Session not found.');
    if ($role !== 'admin' && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

    $startRaw = trim((string)($_POST['scheduled_start'] ?? ''));
    $endRaw = trim((string)($_POST['scheduled_end'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($startRaw === '') respond(false, 'Start time is required.');
    try {
        $start = new DateTime($startRaw);
    } catch (Throwable $e) {
        respond(false, 'Invalid start time.');
    }
    $end = null;
    if ($endRaw !== '') {
        try {
            $end = new DateTime($endRaw);
            if ($end <= $start) respond(false, 'End time must be after start time.');
        } catch (Throwable $e) {
            respond(false, 'Invalid end time.');
        }
    }

    if ($stmt = $conn->prepare("UPDATE trainer_sessions SET scheduled_start = ?, scheduled_end = NULLIF(?, ''), notes = NULLIF(?, ''), updated_at = NOW() WHERE id = ?")) {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end ? $end->format('Y-m-d H:i:s') : '';
        $notesParam = $notes !== '' ? $notes : '';
        $stmt->bind_param('sssi', $startStr, $endStr, $notesParam, $sessionId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(false, 'Failed to reschedule. ' . $err);
        }
        $stmt->close();

        $details = ts_json([
            'session_id' => $sessionId,
            'start' => $startStr,
            'end' => $endStr,
            'notes' => $notes,
        ]);
        if (function_exists('ppf_log')) {
            ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_rescheduled', 'session', (string)$sessionId, $details);
        }

        respond(true, 'Session updated.', ['refresh' => true]);
    }

    respond(false, 'Failed to reschedule session.');
}

if ($action === 'delete_session') {
    $sessionId = max(0, (int)($_POST['session_id'] ?? 0));
    $sql = "SELECT s.*, p.trainer_id FROM trainer_sessions s JOIN trainer_session_packages p ON p.id = s.package_id WHERE s.id = ? LIMIT 1";
    if (!$stmt = $conn->prepare($sql)) respond(false, 'Failed to load session.');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$session) respond(false, 'Session not found.');
    if ($role !== 'admin' && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

    if ($stmt = $conn->prepare("UPDATE trainer_sessions SET status = 'cancelled', updated_at = NOW() WHERE id = ?")) {
        $stmt->bind_param('i', $sessionId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(false, 'Failed to cancel session. ' . $err);
        }
        $stmt->close();

        if (function_exists('ppf_log')) {
            $details = ts_json(['session_id' => $sessionId]);
            ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_cancelled', 'session', (string)$sessionId, $details);
        }

        respond(true, 'Session cancelled.', ['refresh' => true]);
    }

    respond(false, 'Failed to cancel session.');
}

if ($action === 'toggle_completion') {
    $sessionId = max(0, (int)($_POST['session_id'] ?? 0));
    $complete = (int)($_POST['complete'] ?? 0) === 1;
    $sql = "SELECT s.*, p.package_name, p.client_id, p.trainer_id, u.email AS client_email, u.first_name AS client_first, u.last_name AS client_last, t.email AS trainer_email, t.first_name AS trainer_first, t.last_name AS trainer_last FROM trainer_sessions s JOIN trainer_session_packages p ON p.id = s.package_id JOIN users u ON u.id = p.client_id LEFT JOIN users t ON t.id = p.trainer_id WHERE s.id = ? LIMIT 1";
    if (!$stmt = $conn->prepare($sql)) respond(false, 'Failed to load session.');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$session) respond(false, 'Session not found.');
    if ($role !== 'admin' && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

    $status = strtolower((string)($session['status'] ?? 'scheduled'));
    if ($complete) {
        if ($status === 'completed') respond(true, 'Session already completed.', ['refresh' => true]);
        if ($status !== 'scheduled') respond(false, 'Only scheduled sessions can be completed.');
        if (!ppf_trainer_sessions_within_window($session)) {
            respond(false, 'Completion is only available during the scheduled window.');
        }
        $sql = "UPDATE trainer_sessions SET status='completed', completed_at = NOW(), completion_marked_by = ?, updated_at = NOW() WHERE id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ii', $actorId, $sessionId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                respond(false, 'Failed to mark complete. ' . $err);
            }
            $stmt->close();
        }

        $details = ts_json([
            'session_id' => $sessionId,
            'package_id' => $session['package_id'] ?? null,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        if (function_exists('ppf_log')) {
            ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_completed', 'session', (string)$sessionId, $details);
        }

        $start = ts_format_email_datetime($session['scheduled_start'] ?? null);
        $packageName = $session['package_name'] ?? 'Training Session';
        $trainerName = trim(($session['trainer_first'] ?? '') . ' ' . ($session['trainer_last'] ?? ''));
        $clientName = trim(($session['client_first'] ?? '') . ' ' . ($session['client_last'] ?? ''));
        $trainerEmail = $session['trainer_email'] ?? null;
        $clientEmail = $session['client_email'] ?? null;
        $emailBody = "Session Completed\n\nPackage: {$packageName}\nScheduled: {$start}\nTrainer: {$trainerName}\nClient: {$clientName}\n\nThis session has been marked complete.";
        if ($trainerEmail) { @send_plain_email($trainerEmail, $trainerName ?: 'Trainer', 'Session completed: ' . $packageName, $emailBody); }
        if ($clientEmail) { @send_plain_email($clientEmail, $clientName ?: 'Client', 'Session completed: ' . $packageName, $emailBody); }

        respond(true, 'Session marked complete.', ['refresh' => true]);
    }

    // Re-open session
    if ($status !== 'completed') {
        respond(true, 'Session already pending.', ['refresh' => true]);
    }
    if ($stmt = $conn->prepare("UPDATE trainer_sessions SET status='scheduled', completed_at = NULL, completion_marked_by = NULL, updated_at = NOW() WHERE id = ?")) {
        $stmt->bind_param('i', $sessionId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(false, 'Failed to reopen session. ' . $err);
        }
        $stmt->close();
    }
    if (function_exists('ppf_log')) {
        $details = ts_json(['session_id' => $sessionId]);
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_reopened', 'session', (string)$sessionId, $details);
    }

    respond(true, 'Session reopened.', ['refresh' => true]);
}

respond(false, 'Unknown action.');
