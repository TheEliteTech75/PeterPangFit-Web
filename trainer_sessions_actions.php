<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';
require_once __DIR__ . '/send_email.php';

header('Content-Type: application/json');

$role = ppf_role_key($USER_ROLE ?? ($_SESSION['role'] ?? 'guest'));
if (!in_array($role, ['trainer', 'trainer_admin', 'coach'], true) && !ppf_is_admin_role($role)) {
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

function ts_shape_session(array $session): array {
    return [
        'id' => (int)($session['id'] ?? 0),
        'status' => strtolower((string)($session['status'] ?? 'scheduled')),
        'scheduled_start' => $session['scheduled_start'] ?? null,
        'scheduled_end' => $session['scheduled_end'] ?? null,
        'actual_start_at' => $session['actual_start_at'] ?? null,
        'actual_end_at' => $session['actual_end_at'] ?? null,
    ];
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
    if (!ppf_is_admin_role($role) && (int)($pkg['trainer_id'] ?? 0) !== $actorId) {
        respond(false, 'You do not have access to this package.');
    }
    return $pkg;
}

if ($action === 'create_price_package') {
    $mode = ppf_trainer_sessions_pricing_mode($conn);
    $roleKey = ppf_role_key($role);
    $scope = $mode === 'admin' ? 'global' : 'trainer';
    $isTrainerAdmin = $roleKey === 'trainer_admin';
    if ($scope === 'global') {
        if (!$isTrainerAdmin && !ppf_is_admin_role($role)) {
            respond(false, 'Only trainer admins or higher can create global packages.');
        }
    } else {
        if (!in_array($roleKey, ['trainer', 'trainer_admin'], true) && !ppf_is_admin_role($role)) {
            respond(false, 'You do not have permission to create trainer packages.');
        }
    }

    $title = trim((string)($_POST['title'] ?? $_POST['package_title'] ?? ''));
    if ($title === '') {
        respond(false, 'Enter a package title.');
    }

    $sessions = max(1, (int)($_POST['session_count'] ?? $_POST['sessions'] ?? 0));
    $pricePer = ppf_trainer_sessions_parse_amount($_POST['price_per_session'] ?? 0);
    if ($pricePer <= 0) {
        respond(false, 'Enter a price per session greater than 0.');
    }
    $total = round($sessions * $pricePer, 2);

    $expiresType = strtolower(trim((string)($_POST['expires_type'] ?? 'none')));
    $expiresType = in_array($expiresType, ['duration', 'date', 'none'], true) ? $expiresType : 'none';
    $expiresUnit = null;
    $expiresValue = null;
    $expiresOn = null;
    if ($expiresType === 'duration') {
        $expiresValue = max(1, (int)($_POST['expires_value'] ?? 0));
        $maybeUnit = strtolower(trim((string)($_POST['expires_unit'] ?? '')));
        if (!in_array($maybeUnit, ['days','weeks','months','years'], true)) {
            respond(false, 'Choose a valid expiration unit.');
        }
        $expiresUnit = $maybeUnit;
    } elseif ($expiresType === 'date') {
        $raw = trim((string)($_POST['expires_on'] ?? $_POST['expires_date'] ?? ''));
        if ($raw === '') {
            respond(false, 'Select an expiration date.');
        }
        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$dt || $dt->format('Y-m-d') !== $raw) {
            respond(false, 'Enter a valid expiration date.');
        }
        $expiresOn = $dt->format('Y-m-d');
    }

    $targetTrainer = $scope === 'trainer' ? $actorId : 0;
    if ($scope === 'trainer' && ppf_is_admin_role($role)) {
        $targetTrainer = max(0, (int)($_POST['trainer_id'] ?? $actorId));
        if ($targetTrainer <= 0) {
            respond(false, 'Choose a trainer for this package.');
        }
    }
    if ($scope === 'global') {
        $targetTrainer = 0;
    }

    $sql = "INSERT INTO trainer_session_price_packages (scope, trainer_id, title, session_count, price_mode, price_per_session, total_price, expires_type, expires_unit, expires_value, expires_on, created_at, updated_at, created_by) VALUES (?, ?, ?, ?, 'per_session', ?, ?, ?, NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, ''), NOW(), NOW(), ?)";
    if (!$stmt = $conn->prepare($sql)) {
        respond(false, 'Failed to prepare statement.');
    }
    $expiresUnitParam = $expiresUnit ?? '';
    $expiresValueParam = $expiresType === 'duration' ? (int)$expiresValue : 0;
    $expiresOnParam = $expiresType === 'date' ? $expiresOn : '';
    $trainerParam = max(0, (int)$targetTrainer);
    $stmt->bind_param(
        'sisiddssisi',
        $scope,
        $trainerParam,
        $title,
        $sessions,
        $pricePer,
        $total,
        $expiresType,
        $expiresUnitParam,
        $expiresValueParam,
        $expiresOnParam,
        $actorId
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        respond(false, 'Failed to create package. ' . $err);
    }
    $newId = $stmt->insert_id;
    $stmt->close();

    $details = ts_json([
        'catalog_id' => $newId,
        'scope' => $scope,
        'trainer_id' => $trainerParam,
        'title' => $title,
        'session_count' => $sessions,
        'price_per_session' => $pricePer,
        'total_price' => $total,
        'expires_type' => $expiresType,
        'expires_unit' => $expiresUnit,
        'expires_value' => $expiresValue,
        'expires_on' => $expiresOn,
    ]);
    if (function_exists('ppf_log')) {
        ppf_log(
            $conn,
            $actorId,
            $_SESSION['email'] ?? null,
            $_SESSION['role'] ?? null,
            'trainer_price_package_created',
            'session_price_package',
            (string)$newId,
            $details
        );
    }

    respond(true, 'Package created.', ['refresh' => true]);
}

if ($action === 'manual_add_sessions') {
    $clientId = max(0, (int)($_POST['client_id'] ?? 0));
    $catalogId = max(0, (int)($_POST['catalog_package_id'] ?? 0));
    $count = max(1, (int)($_POST['count'] ?? 0));
    $scheduleNotes = trim((string)($_POST['schedule_notes'] ?? ''));

    if ($clientId <= 0) {
        respond(false, 'Select a client to add sessions for.');
    }
    if ($catalogId <= 0) {
        respond(false, 'Choose a price package for accurate totals.');
    }

    $catalog = null;
    if ($stmt = $conn->prepare("SELECT id, title, session_count, price_per_session, total_price FROM trainer_session_price_packages WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $catalogId);
        $stmt->execute();
        $res = $stmt->get_result();
        $catalog = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
    if (!$catalog) {
        respond(false, 'Price package not found.');
    }

    $trainerIdTarget = $actorId;
    $clientRow = null;
    if ($stmt = $conn->prepare('SELECT assigned_trainer_id FROM users WHERE id = ? LIMIT 1')) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $res = $stmt->get_result();
        $clientRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
    if ($clientRow && (int)($clientRow['assigned_trainer_id'] ?? 0) > 0) {
        $trainerIdTarget = (int)$clientRow['assigned_trainer_id'];
    }
    if ($trainerIdTarget <= 0) {
        $trainerIdTarget = $actorId;
    }

    $packageName = trim((string)($catalog['title'] ?? 'Session Package'));
    $pricePer = (float)($catalog['price_per_session'] ?? 0);
    if ($pricePer <= 0) {
        $pricePer = (float)($catalog['total_price'] ?? 0) / max(1, (int)($catalog['session_count'] ?? 1));
    }
    if ($pricePer <= 0) {
        respond(false, 'Invalid pricing information for the selected package.');
    }

    $packageSql = "INSERT INTO trainer_session_packages (client_id, trainer_id, package_name, purchased_sessions, price_per_session, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NOW(), NOW())";
    if (!$stmt = $conn->prepare($packageSql)) {
        respond(false, 'Failed to create package.');
    }
    $notesParam = $scheduleNotes !== '' ? $scheduleNotes : '';
    $stmt->bind_param('iisids', $clientId, $trainerIdTarget, $packageName, $count, $pricePer, $notesParam);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        respond(false, 'Failed to create package. ' . $err);
    }
    $packageId = $stmt->insert_id;
    $stmt->close();

    $totalPrice = round($pricePer * $count, 2);
    if ($totalPrice > 0) {
        if ($txn = $conn->prepare("INSERT INTO trainer_session_transactions (package_id, txn_type, amount, description, created_at, created_by) VALUES (?, 'payment', ?, NULLIF(?, ''), NOW(), ?)") ) {
            $desc = 'Manual package addition';
            $txn->bind_param('idsi', $packageId, $totalPrice, $desc, $actorId);
            $txn->execute();
            $txn->close();
        }
    }

    $insertSessionSql = "INSERT INTO trainer_sessions (package_id, scheduled_start, scheduled_end, status, notes, public_token, created_at, updated_at) VALUES (?, NULL, NULL, 'scheduled', NULL, ?, NOW(), NOW())";
    if (!$sessionStmt = $conn->prepare($insertSessionSql)) {
        respond(false, 'Unable to add sessions.');
    }
    for ($i = 0; $i < $count; $i++) {
        $token = ppf_trainer_sessions_generate_token($conn);
        $sessionStmt->bind_param('is', $packageId, $token);
        if (!$sessionStmt->execute()) {
            $err = $sessionStmt->error;
            $sessionStmt->close();
            respond(false, 'Failed to create session. ' . $err);
        }
    }
    $sessionStmt->close();

    if (function_exists('ppf_log')) {
        $details = ts_json([
            'package_id' => $packageId,
            'client_id' => $clientId,
            'catalog_id' => $catalogId,
            'sessions_added' => $count,
            'price_per_session' => $pricePer,
            'total_price' => $totalPrice,
        ]);
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_sessions_manual_add', 'session_package', (string)$packageId, $details);
    }

    respond(true, 'Sessions added.', ['refresh' => true]);
}

if ($action === 'manual_remove_sessions') {
    $packageId = max(0, (int)($_POST['package_id'] ?? 0));
    $count = max(1, (int)($_POST['count'] ?? 0));
    $refundAmount = ppf_trainer_sessions_parse_amount($_POST['amount'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($packageId <= 0) {
        respond(false, 'Choose a package to adjust.');
    }
    if ($refundAmount < 0) {
        respond(false, 'Refund amount cannot be negative.');
    }
    $pkg = ensure_package_access($conn, $packageId, $role, $actorId);
    $currentTotal = (int)($pkg['purchased_sessions'] ?? 0);
    if ($count > $currentTotal) {
        respond(false, 'Cannot remove more sessions than were purchased.');
    }
    $sessionIds = [];
    if ($stmt = $conn->prepare("SELECT id FROM trainer_sessions WHERE package_id = ? AND status IN ('scheduled','rescheduled','active','in_progress') ORDER BY scheduled_start IS NULL DESC, scheduled_start ASC, id ASC LIMIT ?")) {
        $stmt->bind_param('ii', $packageId, $count);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sessionIds[] = (int)$row['id'];
        }
        $stmt->close();
    }

    if (count($sessionIds) < $count) {
        respond(false, 'Not enough scheduled sessions are available to remove.');
    }

    $newTotal = max(0, $currentTotal - $count);
    if ($stmt = $conn->prepare("UPDATE trainer_session_packages SET purchased_sessions = ?, updated_at = NOW() WHERE id = ?")) {
        $stmt->bind_param('ii', $newTotal, $packageId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            respond(false, 'Failed to update package. ' . $err);
        }
        $stmt->close();
    }

    foreach ($sessionIds as $sid) {
        if ($stmt = $conn->prepare("UPDATE trainer_sessions SET status = 'refunded', scheduled_start = NULL, scheduled_end = NULL, updated_at = NOW() WHERE id = ?")) {
            $stmt->bind_param('i', $sid);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($refundAmount > 0) {
        if ($txn = $conn->prepare("INSERT INTO trainer_session_transactions (package_id, txn_type, amount, description, created_at, created_by) VALUES (?, 'refund', ?, NULLIF(?, ''), NOW(), ?)") ) {
            $txn->bind_param('idsi', $packageId, $refundAmount, $notes, $actorId);
            $txn->execute();
            $txn->close();
        }
    }

    if (function_exists('ppf_log')) {
        $details = ts_json([
            'package_id' => $packageId,
            'sessions_removed' => $count,
            'refund_amount' => $refundAmount,
            'notes' => $notes,
        ]);
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_sessions_manual_remove', 'session_package', (string)$packageId, $details);
    }

    respond(true, 'Sessions removed.', ['refresh' => true]);
}

if ($action === 'schedule_session_batch') {
    $packageId = max(0, (int)($_POST['package_id'] ?? 0));
    $clientId = max(0, (int)($_POST['client_id'] ?? 0));
    $sessionDate = trim((string)($_POST['session_date'] ?? ''));
    $startTime = trim((string)($_POST['start_time'] ?? ''));
    $endTime = trim((string)($_POST['end_time'] ?? ''));
    $count = max(1, (int)($_POST['session_count'] ?? 1));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($packageId <= 0) {
        respond(false, 'Select a package.');
    }
    if ($sessionDate === '' || $startTime === '') {
        respond(false, 'Session date and start time are required.');
    }

    $pkg = ensure_package_access($conn, $packageId, $role, $actorId);
    if ($clientId > 0 && (int)($pkg['client_id'] ?? 0) !== $clientId) {
        respond(false, 'Package does not belong to this client.');
    }

    $startBase = DateTimeImmutable::createFromFormat('Y-m-d H:i', $sessionDate . ' ' . $startTime);
    if (!$startBase) {
        respond(false, 'Invalid start time.');
    }
    $endBase = null;
    if ($endTime !== '') {
        $endBase = DateTimeImmutable::createFromFormat('Y-m-d H:i', $sessionDate . ' ' . $endTime);
        if (!$endBase) {
            respond(false, 'Invalid end time.');
        }
        if ($endBase <= $startBase) {
            respond(false, 'End time must be after the start time.');
        }
    }

    $unscheduled = [];
    if ($stmt = $conn->prepare("SELECT id FROM trainer_sessions WHERE package_id = ? AND scheduled_start IS NULL ORDER BY id ASC LIMIT ?")) {
        $stmt->bind_param('ii', $packageId, $count);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $unscheduled[] = (int)$row['id'];
        }
        $stmt->close();
    }

    $scheduledIds = [];
    for ($i = 0; $i < $count; $i++) {
        $offsetDays = $i * 7;
        $startAt = $startBase->modify('+' . $offsetDays . ' days');
        $endAt = $endBase ? $endBase->modify('+' . $offsetDays . ' days') : null;
        $startStr = $startAt->format('Y-m-d H:i:s');
        $endStr = $endAt ? $endAt->format('Y-m-d H:i:s') : null;

        if (!empty($unscheduled)) {
            $sessionId = array_shift($unscheduled);
            if ($stmt = $conn->prepare("UPDATE trainer_sessions SET scheduled_start = ?, scheduled_end = NULLIF(?, ''), status = 'scheduled', notes = NULLIF(?, ''), updated_at = NOW() WHERE id = ?")) {
                $endParam = $endStr ?? '';
                $notesParam = $notes !== '' ? $notes : '';
                $stmt->bind_param('sssi', $startStr, $endParam, $notesParam, $sessionId);
                $stmt->execute();
                $stmt->close();
                $scheduledIds[] = $sessionId;
            }
            continue;
        }

        $token = ppf_trainer_sessions_generate_token($conn);
        if ($stmt = $conn->prepare("INSERT INTO trainer_sessions (package_id, scheduled_start, scheduled_end, status, notes, public_token, created_at, updated_at) VALUES (?, ?, NULLIF(?, ''), 'scheduled', NULLIF(?, ''), ?, NOW(), NOW())")) {
            $endParam = $endStr ?? '';
            $notesParam = $notes !== '' ? $notes : '';
            $stmt->bind_param('issss', $packageId, $startStr, $endParam, $notesParam, $token);
            if ($stmt->execute()) {
                $scheduledIds[] = $stmt->insert_id;
            }
            $stmt->close();
        }
    }

    if (function_exists('ppf_log')) {
        $details = ts_json([
            'package_id' => $packageId,
            'sessions_scheduled' => count($scheduledIds),
            'first_session' => $startBase->format('c'),
            'notes' => $notes,
        ]);
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_sessions_batch_schedule', 'session_package', (string)$packageId, $details);
    }

    respond(true, 'Sessions scheduled.', ['refresh' => true]);
}

if ($action === 'create_package') {
    $clientId = max(0, (int)($_POST['client_id'] ?? 0));
    $trainerId = ppf_is_admin_role($role) ? max(0, (int)($_POST['trainer_id'] ?? 0)) : $actorId;
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

    $sessionPhrase = $purchased === 1 ? '1 session' : ($purchased . ' sessions');
    ppf_notifications_record($conn, $clientId, [
        'type_key' => 'billing.sessions_purchased',
        'message' => $sessionPhrase . ' were added to the "' . $name . '" package.',
        'send_email' => false,
    ]);
    if ($initialPayment > 0) {
        $amountDisplay = '$' . number_format($initialPayment, 2);
        ppf_notifications_record($conn, $clientId, [
            'type_key' => 'billing.payment_recorded',
            'message' => 'An initial payment of ' . $amountDisplay . ' was recorded for "' . $name . '".',
            'send_email' => false,
        ]);
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
        $refundAmount = 0.0;
        if ($direction === 'remove') {
            $refundAmount = ppf_trainer_sessions_parse_amount($_POST['amount'] ?? 0);
        }
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

        $pkgName = (string)($pkg['package_name'] ?? 'Training package');
        if ($direction === 'add') {
            $sessionPhrase = $count === 1 ? '1 session' : ($count . ' sessions');
            $newTotalLabel = $newTotal === 1 ? '1 session total' : ($newTotal . ' sessions total');
            ppf_notifications_record($conn, (int)($pkg['client_id'] ?? 0), [
                'type_key' => 'billing.sessions_purchased',
                'message' => $sessionPhrase . ' were added to "' . $pkgName . '". You now have ' . $newTotalLabel . '.',
                'send_email' => false,
            ]);
        } elseif ($direction === 'remove') {
            $sessionPhrase = $count === 1 ? '1 session' : ($count . ' sessions');
            $totalLabel = $newTotal === 1 ? '1 session remaining' : ($newTotal . ' sessions remaining');
            ppf_notifications_record($conn, (int)($pkg['client_id'] ?? 0), [
                'type_key' => 'billing.sessions_refunded',
                'message' => $sessionPhrase . ' were removed from "' . $pkgName . '". You now have ' . $totalLabel . '.',
            ]);
            if ($refundAmount > 0) {
                $refundDisplay = '$' . number_format($refundAmount, 2);
                ppf_notifications_record($conn, (int)($pkg['client_id'] ?? 0), [
                    'type_key' => 'billing.refund_recorded',
                    'message' => 'A refund of ' . $refundDisplay . ' is being processed for "' . $pkgName . '".',
                ]);
            }
        }

        if ($direction === 'remove') {
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
        $pkgName = (string)($pkg['package_name'] ?? 'Training package');
        if ($direction === 'payment') {
            $amountDisplay = '$' . number_format($amount, 2);
            ppf_notifications_record($conn, (int)($pkg['client_id'] ?? 0), [
                'type_key' => 'billing.payment_recorded',
                'message' => 'A payment of ' . $amountDisplay . ' was recorded for "' . $pkgName . '".',
                'send_email' => false,
            ]);
        } else {
            $amountDisplay = '$' . number_format($amount, 2);
            ppf_notifications_record($conn, (int)($pkg['client_id'] ?? 0), [
                'type_key' => 'billing.refund_recorded',
                'message' => 'A refund of ' . $amountDisplay . ' was processed for "' . $pkgName . '".',
                'send_email' => true,
            ]);
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

    $token = ppf_trainer_sessions_generate_token($conn);
    $sql = "INSERT INTO trainer_sessions (package_id, scheduled_start, scheduled_end, status, notes, public_token, created_at, updated_at) VALUES (?, ?, NULLIF(?, ''), 'scheduled', NULLIF(?, ''), ?, NOW(), NOW())";
    if (!$stmt = $conn->prepare($sql)) {
        respond(false, 'Failed to prepare insert.');
    }
    $startStr = $start->format('Y-m-d H:i:s');
    $endStr = $end ? $end->format('Y-m-d H:i:s') : '';
    $notesParam = $notes !== '' ? $notes : '';
    $stmt->bind_param('issss', $packageId, $startStr, $endStr, $notesParam, $token);
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
    if (!ppf_is_admin_role($role) && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

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

    if ($stmt = $conn->prepare("UPDATE trainer_sessions SET scheduled_start = ?, scheduled_end = NULLIF(?, ''), notes = NULLIF(?, ''), status = 'rescheduled', updated_at = NOW() WHERE id = ?")) {
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
    if (!ppf_is_admin_role($role) && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

    if ($stmt = $conn->prepare("UPDATE trainer_sessions SET status = 'canceled', updated_at = NOW() WHERE id = ?")) {
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

if ($action === 'start_session' || $action === 'end_session') {
    $sessionId = max(0, (int)($_POST['session_id'] ?? 0));
    if ($sessionId <= 0) respond(false, 'Invalid session.');

    $sql = "SELECT s.*, p.package_name, p.trainer_id, p.client_id,
                   u.email AS client_email, u.first_name AS client_first, u.last_name AS client_last,
                   t.email AS trainer_email, t.first_name AS trainer_first, t.last_name AS trainer_last
            FROM trainer_sessions s
            JOIN trainer_session_packages p ON p.id = s.package_id
            LEFT JOIN users u ON u.id = p.client_id
            LEFT JOIN users t ON t.id = p.trainer_id
            WHERE s.id = ? LIMIT 1";
    if (!$stmt = $conn->prepare($sql)) respond(false, 'Failed to load session.');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$session) respond(false, 'Session not found.');
    if (!ppf_is_admin_role($role) && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

    $status = strtolower((string)($session['status'] ?? 'scheduled'));
    $nowString = date('Y-m-d H:i:s');

    if ($action === 'start_session') {
        if ($status === 'completed') {
            respond(true, 'Session already completed.', ['session' => ts_shape_session($session)]);
        }
        if (in_array($status, ['cancelled','canceled'], true)) {
            respond(false, 'Cancelled sessions cannot be started.');
        }
        if ($status === 'in_progress' && !empty($session['actual_start_at'])) {
            respond(true, 'Session already in progress.', ['session' => ts_shape_session($session)]);
        }
        if (!ppf_trainer_sessions_within_window($session)) {
            respond(false, 'Sessions can only be started within the allowed window.');
        }

        $updateSql = "UPDATE trainer_sessions
                      SET status='in_progress', actual_start_at = COALESCE(actual_start_at, NOW()), timer_started_by = IF(actual_start_at IS NULL, ?, timer_started_by), updated_at = NOW()
                      WHERE id = ? AND status IN ('scheduled','in_progress')";
        if (!$updateStmt = $conn->prepare($updateSql)) {
            respond(false, 'Unable to update the session.');
        }
        $updateStmt->bind_param('ii', $actorId, $sessionId);
        if (!$updateStmt->execute()) {
            $err = $updateStmt->error;
            $updateStmt->close();
            respond(false, 'Failed to start the session. ' . $err);
        }
        $affected = $updateStmt->affected_rows;
        $updateStmt->close();

        if ($affected <= 0 && $status !== 'in_progress') {
            respond(false, 'Session is no longer pending.');
        }

        $session['status'] = 'in_progress';
        if (empty($session['actual_start_at'])) {
            $session['actual_start_at'] = $nowString;
        }
        $session['timer_started_by'] = $actorId;

        if (function_exists('ppf_log')) {
            $details = ts_json([
                'session_id' => $sessionId,
                'package_id' => $session['package_id'] ?? null,
                'started_at' => $session['actual_start_at'],
                'started_by' => $actorId,
                'source' => 'trainer_dashboard',
            ]);
            ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_started', 'session', (string)$sessionId, $details);
        }

        respond(true, 'Session started.', [
            'session' => ts_shape_session($session),
        ]);
    }

    if ($status === 'completed' && !empty($session['actual_end_at'])) {
        respond(true, 'Session already completed.', ['session' => ts_shape_session($session)]);
    }
    if (in_array($status, ['cancelled','canceled'], true)) {
        respond(false, 'Cancelled sessions cannot be ended.');
    }
    if (!ppf_trainer_sessions_within_window($session)) {
        respond(false, 'Sessions can only be ended within the allowed window.');
    }
    if (empty($session['actual_start_at']) && $status !== 'in_progress') {
        respond(false, 'Please start the session before ending it.');
    }

    $updateSql = "UPDATE trainer_sessions
                  SET status='completed', actual_end_at = NOW(), completed_at = NOW(), completion_marked_by = ?, timer_ended_by = ?, duration_seconds = CASE WHEN actual_start_at IS NULL THEN duration_seconds ELSE TIMESTAMPDIFF(SECOND, actual_start_at, NOW()) END, updated_at = NOW()
                  WHERE id = ? AND status IN ('scheduled','in_progress')";
    if (!$updateStmt = $conn->prepare($updateSql)) {
        respond(false, 'Unable to update the session.');
    }
    $updateStmt->bind_param('iii', $actorId, $actorId, $sessionId);
    if (!$updateStmt->execute()) {
        $err = $updateStmt->error;
        $updateStmt->close();
        respond(false, 'Failed to end the session. ' . $err);
    }
    $affected = $updateStmt->affected_rows;
    $updateStmt->close();

    if ($affected <= 0) {
        respond(false, 'Session is no longer pending.');
    }

    $session['status'] = 'completed';
    $session['actual_end_at'] = $nowString;
    $session['completed_at'] = $nowString;
    $session['completion_marked_by'] = $actorId;

    $packageId = (int)($session['package_id'] ?? 0);
    $packageTotals = null;
    if ($packageId > 0) {
        $summary = ppf_trainer_sessions_fetch_package_summary($conn, $packageId);
        if ($summary) {
            $packageTotals = [
                'package_id' => $packageId,
                'purchased' => (int)($summary['purchased_sessions'] ?? 0),
                'used' => (int)($summary['completed_count'] ?? 0),
                'remaining' => max(0, (int)($summary['purchased_sessions'] ?? 0) - (int)($summary['completed_count'] ?? 0)),
                'scheduled' => (int)($summary['scheduled_open'] ?? 0),
            ];
        }
    }

    if (function_exists('ppf_log')) {
        $details = ts_json([
            'session_id' => $sessionId,
            'package_id' => $session['package_id'] ?? null,
            'completed_at' => $nowString,
            'completed_by' => $actorId,
            'source' => 'trainer_dashboard',
        ]);
        ppf_log($conn, $actorId, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null, 'trainer_session_completed', 'session', (string)$sessionId, $details);
    }

    $startLabel = ts_format_email_datetime($session['scheduled_start'] ?? null);
    $packageName = $session['package_name'] ?? 'Training Session';
    $trainerName = trim(($session['trainer_first'] ?? '') . ' ' . ($session['trainer_last'] ?? ''));
    $clientName = trim(($session['client_first'] ?? '') . ' ' . ($session['client_last'] ?? ''));
    $trainerEmail = $session['trainer_email'] ?? null;
    $clientEmail = $session['client_email'] ?? null;
    $emailBody = "Session Completed\n\nPackage: {$packageName}\nScheduled: {$startLabel}\nTrainer: {$trainerName}\nClient: {$clientName}\n\nThis session has been marked complete.";
    if ($trainerEmail) { @send_plain_email($trainerEmail, $trainerName ?: 'Trainer', 'Session completed: ' . $packageName, $emailBody); }
    if ($clientEmail) { @send_plain_email($clientEmail, $clientName ?: 'Client', 'Session completed: ' . $packageName, $emailBody); }

    respond(true, 'Session completed.', [
        'session' => ts_shape_session($session),
        'package_totals' => $packageTotals,
    ]);
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
    if (!ppf_is_admin_role($role) && (int)($session['trainer_id'] ?? 0) !== $actorId) respond(false, 'Access denied.');

    $status = strtolower((string)($session['status'] ?? 'scheduled'));
    if ($complete) {
        respond(false, 'Please use the Start/End controls to complete sessions.');
    }

    // Re-open session
    if ($status !== 'completed') {
        respond(true, 'Session already pending.', ['refresh' => true]);
    }
    if ($stmt = $conn->prepare("UPDATE trainer_sessions SET status='scheduled', completed_at = NULL, completion_marked_by = NULL, actual_start_at = NULL, actual_end_at = NULL, timer_started_by = NULL, timer_ended_by = NULL, duration_seconds = NULL, updated_at = NOW() WHERE id = ?")) {
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
