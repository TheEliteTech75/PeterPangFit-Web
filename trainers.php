<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/ppf_lockout.php';
require_once __DIR__ . '/send_email.php';

$roleKey = ppf_role_key($USER_ROLE ?? null);
if (!ppf_is_admin_role($roleKey) && $roleKey !== 'trainer_admin') {
    require_once __DIR__ . '/access_denied.php';
    exit;
}

function trainers_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function trainers_password_meets_requirements(string $password, string $email, string $first, string $last): ?string
{
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one capital letter, one number, and one special character.';
    }

    $lower = mb_strtolower($password);
    $fragments = [];
    $collect = static function (string $token) use (&$fragments): void {
        $clean = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($token));
        $len = mb_strlen($clean);
        if ($len < 3) {
            return;
        }
        for ($i = 0; $i <= $len - 3; $i++) {
            for ($span = 3; $span <= $len - $i; $span++) {
                $frag = mb_substr($clean, $i, $span);
                if (mb_strlen($frag) > 16) {
                    break;
                }
                $fragments[$frag] = true;
            }
        }
    };

    foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($email)) as $piece) {
        if ($piece !== '') {
            $collect($piece);
        }
    }
    foreach ([$first, $last] as $namePart) {
        foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($namePart)) as $piece) {
            if ($piece !== '') {
                $collect($piece);
            }
        }
    }

    foreach ($fragments as $frag => $_) {
        if ($frag !== '' && mb_strpos($lower, $frag) !== false) {
            return 'Password cannot contain your name or email (even partial matches).';
        }
    }

    return null;
}

function trainers_ensure_is_active(mysqli $conn): void
{
    if (!column_exists($conn, 'users', 'is_active')) {
        @$conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_client");
        @$conn->query("ALTER TABLE users ADD INDEX idx_is_active (is_active)");
    }
}

function trainers_calc_age(?string $birthdate): ?int
{
    if (!$birthdate) {
        return null;
    }
    try {
        $dob = new DateTime($birthdate);
        $now = new DateTime('now');
        if ($dob > $now) {
            return null;
        }
        return (int)$dob->diff($now)->y;
    } catch (Throwable $e) {
        return null;
    }
}

function trainers_format_phone(?string $raw): string
{
    if (!$raw) {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) >= 11 && $digits[0] === '1') {
        $digits = substr($digits, -10);
    }
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }
    return $raw;
}

function trainers_format_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    try {
        $dt = new DateTime($iso);
        return $dt->format('m/d/Y');
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

function trainers_format_gender(?string $gender): string
{
    if ($gender === null || $gender === '') {
        return '';
    }
    $gender = trim($gender);
    return mb_strtoupper(mb_substr($gender, 0, 1)) . mb_substr($gender, 1);
}

trainers_ensure_is_active($conn);
ensure_invite_columns($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = null;
$flashType = 'ok';

function trainers_flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null || $message !== null) {
        $_SESSION['trainers_flash'] = ['type' => $type, 'message' => $message];
        return null;
    }

    if (!empty($_SESSION['trainers_flash'])) {
        $flash = $_SESSION['trainers_flash'];
        unset($_SESSION['trainers_flash']);
        return is_array($flash) ? $flash : null;
    }

    return null;
}

if ($storedFlash = trainers_flash()) {
    $flashType = ($storedFlash['type'] ?? 'ok') === 'err' ? 'err' : 'ok';
    $flash = (string)($storedFlash['message'] ?? '');
}
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'inactive') ? 'inactive' : 'active';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

function trainers_redirect_tab(string $tab): void
{
    header('Location: trainers.php?tab=' . urlencode($tab));
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            throw new Exception('Your session expired. Please try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'send_invite') {
            $rawEmails = $_POST['emails'] ?? [];
            if (!is_array($rawEmails)) {
                $rawEmails = [$rawEmails];
            }

            $hasTypedEmail = false;
            foreach ($rawEmails as $value) {
                if (trim((string)$value) !== '') {
                    $hasTypedEmail = true;
                    break;
                }
            }

            if (!$hasTypedEmail) {
                $fallback = trim((string)($_POST['invite_email'] ?? ''));
                if ($fallback !== '') {
                    $rawEmails = preg_split('/[\s,;]+/', $fallback) ?: [];
                }
            }

            $emails = [];
            foreach ($rawEmails as $raw) {
                $email = trim((string)$raw);
                if ($email === '') {
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address: ' . $email);
                }
                $lower = mb_strtolower($email);
                if (!isset($emails[$lower])) {
                    $emails[$lower] = $email;
                }
            }

            if (!$emails) {
                throw new Exception('Please enter at least one valid email address.');
            }

            $sent = [];

            foreach ($emails as $email) {
                $conn->begin_transaction();
                try {
                    $userId = null;
                    $stmt = $conn->prepare('SELECT id, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $userId = (int)$row['id'];
                        $roleKey = ppf_role_key($row['role'] ?? '');
                        if ($roleKey !== 'trainer') {
                            throw new Exception('That email (' . $email . ') is already in use for a different role.');
                        }
                    }
                    $stmt->close();

                    if (!$userId) {
                        $sql = 'INSERT INTO users (email, role, is_client, is_active, created_at) VALUES (?, \'trainer\', 0, 1, NOW())';
                        $ins = $conn->prepare($sql);
                        $ins->bind_param('s', $email);
                        if (!$ins->execute()) {
                            throw new Exception('Failed to prepare trainer account for ' . $email . '.');
                        }
                        $userId = $ins->insert_id;
                        $ins->close();
                    }

                    $token = bin2hex(random_bytes(32));
                    $expiresAt = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

                    $inviteSql = 'INSERT INTO invites (user_id, email, token, expires_at, cancelled_at, used, created_by, created_at) VALUES (?, ?, ?, ?, NULL, 0, ?, NOW())';
                    $invite = $conn->prepare($inviteSql);
                    $createdBy = (int)($USER_ID ?? 0);
                    $invite->bind_param('isssi', $userId, $email, $token, $expiresAt, $createdBy);
                    if (!$invite->execute()) {
                        throw new Exception('Failed to create invite for ' . $email . '.');
                    }
                    $invite->close();

                    $conn->commit();

                    $baseUrl = 'https://peterpang.pwncore.net';
                    $link = $baseUrl . '/register.php?token=' . urlencode($token);
                    $subject = "You're invited to join Peter Pang Fit as a Trainer";
                    $body = "Hello,\n\n"
                        . "You have been invited to register as a trainer. This link expires in 48 hours.\n\n"
                        . $link . "\n\n"
                        . "If it expires, please ask an administrator for a new invite.\n\n— Peter Pang Fit";
                    @send_plain_email($email, $email, $subject, $body);

                    if (function_exists('ppf_log')) {
                        $details = json_encode(['email' => $email, 'expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES);
                        ppf_log($conn, $USER_ID ?? null, $USER_EMAIL ?? null, $USER_ROLE ?? null, 'trainer_invite_created', 'user', (string)$userId, $details);
                    }

                    $sent[] = $email;
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
            $flashType = 'ok';
            trainers_flash($flashType, $flash);

            if (count($sent) === 1) {
                $flash = 'Invite sent to ' . $sent[0] . '. Expires in 48 hours.';
            } else {
                $flash = 'Invites sent to ' . implode(', ', $sent) . '. Expires in 48 hours.';
            }
            $flashType = 'ok';
            trainers_flash($flashType, $flash);

            trainers_redirect_tab($tab);
        }

        if ($action === 'add_trainer') {
            $first = trim($_POST['add_first_name'] ?? '');
            $last = trim($_POST['add_last_name'] ?? '');
            $email = trim($_POST['add_email'] ?? '');
            $phone = trim($_POST['add_phone'] ?? '');
            $password = (string)($_POST['add_password'] ?? '');
            $confirm = (string)($_POST['add_password_confirm'] ?? '');

            if ($first === '' || $last === '') {
                throw new Exception('First and last name are required.');
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            if ($password === '' || $confirm === '') {
                throw new Exception('Password and confirmation are required.');
            }
            if ($password !== $confirm) {
                throw new Exception('Passwords do not match.');
            }

            $msg = trainers_password_meets_requirements($password, $email, $first, $last);
            if ($msg !== null) {
                throw new Exception($msg);
            }

            $check = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $check->close();
                throw new Exception('That email address is already registered.');
            }
            $check->close();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!$hash) {
                throw new Exception('Failed to hash password.');
            }

            $sql = 'INSERT INTO users (first_name, last_name, email, phone, role, password_hash, is_client, is_active, created_at)
                    VALUES (?, ?, ?, ?, \'trainer\', ?, 0, 1, NOW())';
            $ins = $conn->prepare($sql);
            $ins->bind_param('ssssss', $first, $last, $email, $phone, $hash);
            if (!$ins->execute()) {
                throw new Exception('Failed to create trainer.');
            }
            $trainerId = $ins->insert_id;
            $ins->close();

            $toName = trim($first . ' ' . $last) ?: $email;
            $subject = 'Welcome to Peter Pang Fit — Trainer Access';
            $body = "Hi {$toName},\n\n"
                . "Your trainer account has been created. You can sign in here:\n"
                . "https://peterpang.pwncore.net/login.php\n\n"
                . "If you have any trouble accessing your account, please contact an administrator.\n\n— Peter Pang Fit";
            @send_plain_email($email, $toName, $subject, $body);

            if (function_exists('ppf_log')) {
                $details = json_encode(['email' => $email], JSON_UNESCAPED_SLASHES);
                ppf_log($conn, $USER_ID ?? null, $USER_EMAIL ?? null, $USER_ROLE ?? null, 'trainer_created', 'user', (string)$trainerId, $details);
            }

            $flash = 'Trainer added successfully.';
            $flashType = 'ok';
            trainers_flash($flashType, $flash);
            trainers_redirect_tab('active');
        }

        if ($action === 'update_trainer') {
            $trainerId = (int)($_POST['trainer_id'] ?? 0);
            if ($trainerId <= 0) {
                throw new Exception('Invalid trainer selection.');
            }

            $first = trim($_POST['first_name'] ?? '');
            $middle = trim($_POST['middle_name'] ?? '');
            $last = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $birthdate = trim($_POST['birthdate'] ?? '');
            $gender = trim($_POST['gender'] ?? '');

            if ($first === '' || $last === '') {
                throw new Exception('First and last name are required.');
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please provide a valid email.');
            }
            if ($birthdate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
                throw new Exception('Birthdate must be YYYY-MM-DD.');
            }

            $system = ppf_measurement_user_system();
            if ($system === 'metric') {
                $heightInput = trim($_POST['height_cm'] ?? '');
                [$heightFt, $heightIn] = ppf_measurement_height_components_from_cm($heightInput);
            } else {
                $heightFt = trim($_POST['height_ft'] ?? '');
                $heightIn = trim($_POST['height_in'] ?? '');
            }

            $weightInput = $_POST['weight_lbs'] ?? null;
            $weightLbs = ppf_measurement_parse_weight_input($weightInput);
            if ($weightInput !== null && $weightInput !== '' && $weightLbs === null) {
                throw new Exception('Please enter a valid weight.');
            }

            $chk = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
            $chk->bind_param('si', $email, $trainerId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->close();
                throw new Exception('Another account already uses that email.');
            }
            $chk->close();

            $sql = 'UPDATE users
                    SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, birthdate = ?, gender = ?, height_ft = ?, height_in = ?, weight_lbs = ?
                    WHERE id = ? AND role = \'trainer\'';
            $stmt = $conn->prepare($sql);

            $firstVal = $first;
            $middleVal = ($middle !== '') ? $middle : null;
            $lastVal = $last;
            $emailVal = $email;
            $phoneVal = $phone !== '' ? $phone : null;
            $birthVal = $birthdate !== '' ? $birthdate : null;
            $genderVal = $gender !== '' ? $gender : null;
            $heightFtVal = ($heightFt === '' || $heightFt === null) ? null : (int)$heightFt;
            $heightInVal = ($heightIn === '' || $heightIn === null) ? null : (int)$heightIn;
            $weightVal = $weightLbs;

            $stmt->bind_param(
                'sssssssiidi',
                $firstVal,
                $middleVal,
                $lastVal,
                $emailVal,
                $phoneVal,
                $birthVal,
                $genderVal,
                $heightFtVal,
                $heightInVal,
                $weightVal,
                $trainerId
            );

            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to update trainer.');
            }
            $stmt->close();

            if (function_exists('ppf_log')) {
                $details = json_encode(['trainer_id' => $trainerId], JSON_UNESCAPED_SLASHES);
                ppf_log($conn, $USER_ID ?? null, $USER_EMAIL ?? null, $USER_ROLE ?? null, 'trainer_updated', 'user', (string)$trainerId, $details);
            }

            $flash = 'Trainer updated successfully.';
            $flashType = 'ok';
            trainers_flash($flashType, $flash);
            trainers_redirect_tab($tab);

        }

        if ($action === 'deactivate_trainer') {
            $trainerId = (int)($_POST['trainer_id'] ?? 0);
            if ($trainerId <= 0) {
                throw new Exception('Invalid trainer selection.');
            }

            $sql = "UPDATE users SET is_active = 0, locked_until = DATE_ADD(NOW(), INTERVAL 100 YEAR) WHERE id = ? AND role = 'trainer'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $trainerId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to deactivate trainer.');
            }
            $stmt->close();

            if (function_exists('ppf_log')) {
                $details = json_encode(['trainer_id' => $trainerId], JSON_UNESCAPED_SLASHES);
                ppf_log($conn, $USER_ID ?? null, $USER_EMAIL ?? null, $USER_ROLE ?? null, 'trainer_deactivated', 'user', (string)$trainerId, $details);
            }

            $flash = 'Trainer deactivated.';
            $flashType = 'ok';
            trainers_flash($flashType, $flash);
            trainers_redirect_tab('inactive');
        }

        if ($action === 'reactivate_trainer') {
            $trainerId = (int)($_POST['trainer_id'] ?? 0);
            if ($trainerId <= 0) {
                throw new Exception('Invalid trainer selection.');
            }

            $sql = "UPDATE users SET is_active = 1, locked_until = NULL, failed_login_count = 0 WHERE id = ? AND role = 'trainer'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $trainerId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to reactivate trainer.');
            }
            $stmt->close();

            if (function_exists('ppf_log')) {
                $details = json_encode(['trainer_id' => $trainerId], JSON_UNESCAPED_SLASHES);
                ppf_log($conn, $USER_ID ?? null, $USER_EMAIL ?? null, $USER_ROLE ?? null, 'trainer_reactivated', 'user', (string)$trainerId, $details);
            }

            $flash = 'Trainer reactivated.';
            $flashType = 'ok';
            trainers_flash($flashType, $flash);
            trainers_redirect_tab('active');
        }

    }
}
catch (Throwable $e) {
    $flash = $e->getMessage();
    $flashType = 'err';
}

function trainers_render_table(array $rows, string $csrf, string $tab, int $editId, bool $isMetric, string $heightColumnLabel, string $weightColumnLabel, string $weightPlaceholder): void
{
    $colspan = 13;
    ?>
    <table class="trainers-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>First</th>
                <th>Middle</th>
                <th>Last</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Birthdate</th>
                <th>Age</th>
                <th>Gender</th>
                <th><?php echo trainers_h($heightColumnLabel); ?></th>
                <th><?php echo trainers_h($weightColumnLabel); ?></th>
                <th>Plans</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr>
                <td colspan="<?php echo $colspan; ?>" class="muted">No trainers found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $row):
                $id = (int)($row['id'] ?? 0);
                $editing = ($editId === $id && $tab === ((int)($row['is_active'] ?? 1) === 1 ? 'active' : 'inactive'));
                $age = trainers_calc_age($row['birthdate'] ?? null);
                $heightDisplay = ppf_measurement_format_height($row['height_ft'] ?? null, $row['height_in'] ?? null);
                $weightDisplay = ppf_measurement_format_weight($row['weight_lbs'] ?? null);
                $heightInputMetric = ppf_measurement_height_metric_value($row['height_ft'] ?? null, $row['height_in'] ?? null);
                $weightInput = ppf_measurement_weight_value_for_input($row['weight_lbs'] ?? null);
                $lockedUntil = $row['locked_until'] ?? null;
                $isLocked = $lockedUntil && strtotime($lockedUntil) > time();
                $formId = 'edit-trainer-' . $id;
                if ($editing): ?>
                    <form method="post" id="<?php echo trainers_h($formId); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo trainers_h($csrf); ?>">
                        <input type="hidden" name="action" value="update_trainer">
                        <input type="hidden" name="trainer_id" value="<?php echo $id; ?>">
                    </form>
                <?php endif; ?>
                <tr class="trainer-row<?php echo $editing ? ' editing' : ''; ?>">
                    <td data-label="ID"><?php echo $id; ?></td>
                    <td data-label="First">
                        <?php if ($editing): ?>
                            <input class="input" type="text" name="first_name" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['first_name'] ?? ''); ?>" required>
                        <?php else: ?>
                            <?php echo trainers_h($row['first_name'] ?? ''); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Middle">
                        <?php if ($editing): ?>
                            <input class="input" type="text" name="middle_name" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['middle_name'] ?? ''); ?>">
                        <?php else: ?>
                            <?php echo trainers_h($row['middle_name'] ?? ''); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Last">
                        <?php if ($editing): ?>
                            <input class="input" type="text" name="last_name" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['last_name'] ?? ''); ?>" required>
                        <?php else: ?>
                            <?php echo trainers_h($row['last_name'] ?? ''); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Email">
                        <?php if ($editing): ?>
                            <input class="input" type="email" name="email" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['email'] ?? ''); ?>" required>
                        <?php else: ?>
                            <?php echo trainers_h($row['email'] ?? ''); ?>
                            <?php if ($isLocked): ?>
                                <span class="badge warn">Locked</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Phone">
                        <?php if ($editing): ?>
                            <input class="input" type="text" name="phone" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['phone'] ?? ''); ?>">
                        <?php else: ?>
                            <?php echo trainers_h(trainers_format_phone($row['phone'] ?? '')); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Birthdate">
                        <?php if ($editing): ?>
                            <input class="input" type="date" name="birthdate" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['birthdate'] ?? ''); ?>">
                        <?php else: ?>
                            <?php echo trainers_h(trainers_format_date($row['birthdate'] ?? '')); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Age"><?php echo $age === null ? '—' : $age; ?></td>
                    <td data-label="Gender">
                        <?php if ($editing): ?>
                            <input class="input" type="text" name="gender" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($row['gender'] ?? ''); ?>">
                        <?php else: ?>
                            <?php echo trainers_h(trainers_format_gender($row['gender'] ?? '')); ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Height">
                        <?php if ($editing): ?>
                            <?php if ($isMetric): ?>
                                <input class="input" type="number" step="0.1" min="0" name="height_cm" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($heightInputMetric); ?>" placeholder="Height (cm)">
                            <?php else: ?>
                                <div class="height-inputs">
                                    <input class="input" type="number" name="height_ft" form="<?php echo trainers_h($formId); ?>" min="0" max="8" value="<?php echo trainers_h($row['height_ft'] ?? ''); ?>" placeholder="ft">
                                    <input class="input" type="number" name="height_in" form="<?php echo trainers_h($formId); ?>" min="0" max="11" value="<?php echo trainers_h($row['height_in'] ?? ''); ?>" placeholder="in">
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php echo $heightDisplay ? trainers_h($heightDisplay) : '—'; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Weight">
                        <?php if ($editing): ?>
                            <input class="input" type="number" step="0.1" min="0" name="weight_lbs" form="<?php echo trainers_h($formId); ?>" value="<?php echo trainers_h($weightInput); ?>" placeholder="<?php echo trainers_h($weightPlaceholder); ?>">
                        <?php else: ?>
                            <?php echo $weightDisplay ? trainers_h($weightDisplay) : '—'; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Plans">—</td>
                    <td data-label="Actions">
                        <div class="actions">
                            <?php if ($editing): ?>
                                <button class="btn small brand" type="submit" form="<?php echo trainers_h($formId); ?>">Save</button>
                                <a class="btn small" href="trainers.php?tab=<?php echo trainers_h($tab); ?>">Cancel</a>
                            <?php else: ?>
                                <a class="btn small" href="trainers.php?tab=<?php echo trainers_h($tab); ?>&amp;edit=<?php echo $id; ?>">Edit</a>
                                <?php if ($tab === 'active'): ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Deactivate this trainer?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo trainers_h($csrf); ?>">
                                        <input type="hidden" name="action" value="deactivate_trainer">
                                        <input type="hidden" name="trainer_id" value="<?php echo $id; ?>">
                                        <button class="btn small warn" type="submit">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo trainers_h($csrf); ?>">
                                        <input type="hidden" name="action" value="reactivate_trainer">
                                        <input type="hidden" name="trainer_id" value="<?php echo $id; ?>">
                                        <button class="btn small brand" type="submit">Reactivate</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
}

$measurementSystem = ppf_measurement_user_system();
$measurementIsMetric = ($measurementSystem === 'metric');
$heightColumnLabel = $measurementIsMetric ? 'Height (cm)' : 'Height';
$weightColumnLabel = ppf_measurement_weight_label();
$weightPlaceholder = ppf_measurement_weight_placeholder();

$activeTrainers = [];
$inactiveTrainers = [];
$sql = "SELECT id, first_name, middle_name, last_name, email, phone, birthdate, gender, height_ft, height_in, weight_lbs, is_active, locked_until FROM users WHERE role = 'trainer' ORDER BY last_name, first_name, id";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        if ((int)($row['is_active'] ?? 1) === 1) {
            $activeTrainers[] = $row;
        } else {
            $inactiveTrainers[] = $row;
        }
    }
    $res->free();
}

require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trainers · Peter Pang Fit</title>
    <style>
        html,body{margin:0;padding:0;background:var(--page-canvas);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Inter,sans-serif;}
        a{color:var(--text);text-decoration:none}
        .wrap{width:100%;max-width:100%;margin:24px auto;padding:0 clamp(14px,3vw,28px);box-sizing:border-box}
        .subheader{position:sticky;top:0;z-index:40;background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;backdrop-filter:blur(8px)}
        .subheader .left{display:flex;align-items:center;gap:10px}
        .brand{font-weight:700;font-size:20px;letter-spacing:.2px}
        .muted{color:var(--muted);font-size:13px}
        .btnset{display:flex;gap:8px;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:rgba(30,41,59,0.65);border:1px solid var(--line);color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer;text-decoration:none;white-space:nowrap;min-height:34px;line-height:1.1}
        .btn.small{padding:6px 10px;font-size:13px;min-height:30px}
        .btn.brand{background:var(--brand);border-color:var(--brand);color:#fff}
        .btn.warn{background:#2a1617;border-color:rgba(248,113,113,0.45);color:#f87171}
        .tabs{display:flex;gap:8px;margin-bottom:14px}
        .tab{padding:8px 12px;border-radius:999px;border:1px solid var(--line);background:rgba(15,23,42,0.68);color:#cbd5f5}
        .tab.active{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35);color:#fff}
        .panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
        thead th{background:rgba(8,13,23,0.95);color:#c3c9d4;font-size:13px;letter-spacing:.3px}
        tbody tr:last-child td{border-bottom:none}
        .trainers-table{min-width:900px;width:100%}
        .table-wrapper{overflow-x:auto}
        .input{background:rgba(8,13,23,0.88);border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px;width:100%}
        .height-inputs{display:flex;gap:6px}
        .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .inline-form{margin:0}
        .badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid transparent;margin-left:6px}
        .badge.warn{background:rgba(248,113,113,0.15);border-color:rgba(248,113,113,0.3);color:#fda4af}
        .flash{margin-bottom:16px;padding:10px 12px;border-radius:12px}
        .flash.ok{background:#122016;border:1px solid #1f3b2a;color:#86efac}
        .flash.err{background:#2a1214;border:1px solid #3f1b1e;color:#fca5a5}
        .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.55);display:none;z-index:3000}
        .modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(420px,94vw);background:rgba(9,14,28,0.92);border:1px solid var(--line);border-radius:14px;padding:18px;display:none;z-index:3001}
        .modal h3{margin:0 0 12px 0;font-size:18px}
        .modal .field{margin-bottom:12px;display:flex;flex-direction:column;gap:6px}
        .modal .actions{justify-content:flex-end}
        .modal.open{display:block}
        .modal-backdrop.open{display:block}
        .trainer-row.editing{background:rgba(56,189,248,0.08)}
        @media (max-width:900px){th,td{padding:8px;font-size:13px}.btn.small{font-size:12px}}
        @media (max-width:720px){th,td{padding:6px;font-size:12px}.brand{font-size:18px}}
    
/* === PPF: Trainers modal enhancements === */
.add-trainer-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}
.add-trainer-grid .field{margin-bottom:0}
.add-trainer-grid .field.full{grid-column:1 / -1}
@media (max-width:720px){.add-trainer-grid{grid-template-columns:1fr}}
.password-reqs{font-size:12px;line-height:1.4}
.password-reqs-wrap{display:block;margin-top:-6px}
.password-reqs-wrap .password-reqs{margin-top:0}
.password-reqs .ok{color:#22c55e}.password-reqs .bad{color:#ef4444}.password-reqs .hint{color:var(--muted,#9ba4c2)}
.tagbox{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:8px;border:1px solid var(--input-border,rgba(148,163,184,.28));border-radius:10px;background:var(--input-bg,rgba(15,23,42,.6))}
.tagbox-tags{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.tagbox input{border:none !important;outline:none;background:transparent!important;box-shadow:none!important;flex:1;min-width:140px;padding:6px 0;color:var(--text,#f8fafc)}
.tag{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:var(--badge-muted,rgba(148,163,184,.16));color:var(--text,#f8fafc);font-size:13px;font-weight:600}
.tag .x{cursor:pointer;font-weight:700;opacity:.8;display:inline-flex;align-items:center;justify-content:center;min-width:14px}.tag .x:hover{opacity:1}
.tag .x:focus{outline:2px solid var(--brand,#38bdf8);outline-offset:2px}

#invite-hidden{display:none}

.field-hint{font-size:12px;line-height:1.4;margin-top:4px}


/* === PPF fix: widen Add Trainer modal === */
.modal#addModal {
  width: 760px !important;
  max-width: 92vw !important;
  margin-left: auto !important;
  margin-right: auto !important;
}
.modal#addModal form { width: 100%; }

</style>
</head>
<body>
<main class="wrap">
    <div class="subheader">
        <div class="left">
            <div class="brand">Trainers</div>
            <span class="muted">Manage trainer invites and accounts</span>
        </div>
        <div class="btnset">
            <button class="btn brand" type="button" data-modal-open="inviteModal">Send Invite</button>
            <button class="btn" type="button" data-modal-open="addModal">Add Trainer</button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?php echo $flashType === 'ok' ? 'ok' : 'err'; ?>"><?php echo trainers_h($flash); ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a class="tab <?php echo $tab === 'active' ? 'active' : ''; ?>" href="trainers.php?tab=active">Active</a>
        <a class="tab <?php echo $tab === 'inactive' ? 'active' : ''; ?>" href="trainers.php?tab=inactive">Inactive</a>
    </div>

    <div class="panel">
        <div class="table-wrapper">
            <?php
            if ($tab === 'active') {
                trainers_render_table($activeTrainers, $csrf, 'active', $editId, $measurementIsMetric, $heightColumnLabel, $weightColumnLabel, $weightPlaceholder);
            } else {
                trainers_render_table($inactiveTrainers, $csrf, 'inactive', $editId, $measurementIsMetric, $heightColumnLabel, $weightColumnLabel, $weightPlaceholder);
            }
            ?>
        </div>
    </div>
</main>

<div class="modal-backdrop" data-modal-backdrop></div>
<div class="modal" id="inviteModal" role="dialog" aria-modal="true" aria-labelledby="inviteTitle">
    <h3 id="inviteTitle">Send Trainer Invite</h3>
    <form method="post" data-modal-form>
        <input type="hidden" name="csrf_token" value="<?php echo trainers_h($csrf); ?>">
        <input type="hidden" name="action" value="send_invite">
        <div class="field">
            <label for="invite-input">Trainer Emails</label>
            <div class="tagbox" id="invite-tagbox">
                <div class="tagbox-tags" id="invite-tags"></div>
                <input id="invite-input" name="invite_email" type="text" placeholder="Type an email and press space, enter, comma, or semicolon">
            </div>
            <div id="invite-hidden"></div>
            <div class="field-hint muted">Type an email and press space, enter, comma, or semicolon to add it.</div>
        </div>
        <div class="actions">
            <button class="btn" type="button" data-modal-close>Cancel</button>
            <button class="btn brand" type="submit" data-processing-text="Processing...">Send Invite</button>
        </div>
    </form>
</div>

<div class="modal" id="addModal" role="dialog" aria-modal="true" aria-labelledby="addTitle">
    <h3 id="addTitle">Add Trainer</h3>
    <form method="post" data-modal-form>
        <input type="hidden" name="csrf_token" value="<?php echo trainers_h($csrf); ?>">
        <input type="hidden" name="action" value="add_trainer">
        <div class="add-trainer-grid">
            <div class="field">
                <label for="add_first_name">First Name</label>
                <input class="input" id="add_first_name" name="add_first_name" type="text" required>
            </div>
            <div class="field">
                <label for="add_last_name">Last Name</label>
                <input class="input" id="add_last_name" name="add_last_name" type="text" required>
            </div>
            <div class="field full">
                <label for="add_email">Email</label>
                <input class="input" id="add_email" name="add_email" type="email" placeholder="name@example.com" required>
            </div>
            <div class="field full">
                <label for="add_phone">Phone Number</label>
                <input class="input" id="add_phone" name="add_phone" type="text" placeholder="(555) 123-4567">
            </div>
            <div class="field full">
                <label for="add_password">Password</label>
                <input class="input" id="add_password" name="add_password" type="password" required>
            </div>
            <div class="field full">
                <label for="add_password_confirm">Confirm Password</label>
                <input class="input" id="add_password_confirm" name="add_password_confirm" type="password" required>
            </div>
            <div class="field full password-reqs-wrap">
                <div class="password-reqs" id="add-trainer-password-reqs">
                    <div><span data-req="len" class="bad">• At least 12 characters</span></div>
                    <div><span data-req="upper" class="bad">• At least one uppercase letter</span></div>
                    <div><span data-req="lower" class="bad">• At least one lowercase letter</span></div>
                    <div><span data-req="digit" class="bad">• At least one number</span></div>
                    <div><span data-req="special" class="bad">• At least one special character</span></div>
                </div>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="button" data-modal-close>Cancel</button>
            <button class="btn brand" type="submit" data-processing-text="Processing...">Add Trainer</button>
        </div>
    </form>
</div>

<script>
(function(){
    const backdrop = document.querySelector('[data-modal-backdrop]');
    function closeModals(){
        document.querySelectorAll('.modal.open').forEach(modal => modal.classList.remove('open'));
        if (backdrop) backdrop.classList.remove('open');
    }
    document.querySelectorAll('[data-modal-open]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-modal-open');
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('open');
            if (backdrop) backdrop.classList.add('open');
            const firstInput = modal.querySelector('input, select, textarea');
            if (firstInput) firstInput.focus();
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', closeModals);
    });
    if (backdrop) {
        backdrop.addEventListener('click', closeModals);
    }
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') {
            closeModals();
        }
    });

    document.querySelectorAll('[data-modal-form]').forEach(form => {
        form.addEventListener('submit', () => {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) return;
            const original = submitBtn.textContent;
            const processing = submitBtn.getAttribute('data-processing-text') || 'Processing...';
            submitBtn.dataset.originalText = original;
            submitBtn.textContent = processing;
            submitBtn.disabled = true;
        });
    });
})();
</script>

<script>
(function(){
  const modal = document.getElementById('addModal');
  if (!modal) return;
  const pwd = modal.querySelector('#add_password');
  const cpw = modal.querySelector('#add_password_confirm');
  const reqBox = modal.querySelector('#add-trainer-password-reqs');
  if (!pwd || !cpw || !reqBox) return;
  const req = {
    len: reqBox.querySelector('[data-req="len"]'),
    upper: reqBox.querySelector('[data-req="upper"]'),
    lower: reqBox.querySelector('[data-req="lower"]'),
    digit: reqBox.querySelector('[data-req="digit"]'),
    special: reqBox.querySelector('[data-req="special"]')
  };
  function update(){
    const v = pwd.value || '';
    const tests = {
      len: v.length >= 12,
      upper: /[A-Z]/.test(v),
      lower: /[a-z]/.test(v),
      digit: /[0-9]/.test(v),
      special: /[!@#$%^&*()\-_=+\[\]{};:'",.<>/?`~|\\]/.test(v)
    };
    Object.keys(tests).forEach(k => {
      const el = req[k]; if (!el) return;
      el.classList.toggle('ok', tests[k]);
      el.classList.toggle('bad', !tests[k]);
    });
    cpw.setCustomValidity(cpw.value && cpw.value !== v ? 'Passwords do not match.' : '');
  }
  pwd.addEventListener('input', update);
  cpw.addEventListener('input', update);
  update();
})();

(function(){
  const tagBox = document.getElementById('invite-tagbox');
  const input = document.getElementById('invite-input');
  const tagsEl = document.getElementById('invite-tags');
  const hidden = document.getElementById('invite-hidden');
  if (!tagBox || !input || !hidden || !tagsEl) return;

  const emails = new Set(); // stores lowercase emails for uniqueness
  const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function sanitize(raw){
    return (raw || '').trim().replace(/[\s,;]+$/g, '');
  }

  function createTag(email){
    const tag = document.createElement('span');
    tag.className = 'tag';
    tag.dataset.email = email;

    const text = document.createElement('span');
    text.textContent = email;

    const remove = document.createElement('span');
    remove.className = 'x';
    remove.setAttribute('role', 'button');
    remove.setAttribute('tabindex', '0');
    remove.setAttribute('aria-label', 'Remove ' + email);
    remove.textContent = '×';
    remove.addEventListener('click', () => removeEmail(email, tag));
    remove.addEventListener('keydown', (evt) => {
      if (evt.key === 'Enter' || evt.key === ' '){
        evt.preventDefault();
        removeEmail(email, tag);
      }
    });

    tag.appendChild(text);
    tag.appendChild(remove);
    return tag;
  }

  function addEmail(raw){
    const email = sanitize(raw);
    if (!email) return false;
    if (!emailPattern.test(email)) return false;
    const key = email.toLowerCase();
    if (emails.has(key)) return false;

    emails.add(key);
    const tag = createTag(email);
    tagsEl.appendChild(tag);

    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'emails[]';
    hiddenInput.value = email;
    hiddenInput.dataset.email = key;
    hidden.appendChild(hiddenInput);
    return true;
  }

  function commitBuffer(keepPartial){
    const value = input.value;
    if (!value) return '';
    const segments = value.split(/[\s,;]+/);
    const endsWithDelimiter = /[\s,;]$/.test(value);
    let remainderSegment = '';
    if (keepPartial && !endsWithDelimiter){
      remainderSegment = segments.pop() || '';
    }
    let addedAny = false;
    segments.filter(Boolean).forEach(part => {
      if (addEmail(part)){
        addedAny = true;
      }
    });
    if (addedAny){
      if (keepPartial && !endsWithDelimiter){
        return remainderSegment;
      }
      return '';
    }
    return remainderSegment || sanitize(value);
  }

  function removeEmail(email, tagEl){
    const key = (email || '').toLowerCase();
    emails.delete(key);
    if (tagEl && tagEl.parentNode){
      tagEl.parentNode.removeChild(tagEl);
    }
    hidden.querySelectorAll('input[name="emails[]"]').forEach(inp => {
      if ((inp.dataset.email && inp.dataset.email === key) || inp.value.toLowerCase() === key){
        inp.remove();
      }
    });
  }

  tagBox.addEventListener('click', () => input.focus());

  input.addEventListener('keydown', (e) => {
    const key = e.key;
    const isDelimiter = key === ' ' || key === 'Spacebar' || key === ',' || key === 'Comma' || key === ';' || key === 'Semicolon';
    if (key === 'Enter' || isDelimiter){
      e.preventDefault();
      const remainder = commitBuffer(false);
      if (input.value !== remainder){
        input.value = remainder;
      }
    } else if (key === 'Backspace' && !input.value){
      const last = tagsEl.lastElementChild;
      if (last){
        removeEmail(last.dataset.email || '', last);
      }
    }
  });

  input.addEventListener('input', () => {
    const remainder = commitBuffer(true);
    if (input.value !== remainder){
      input.value = remainder;
    }
  });

  input.addEventListener('blur', () => {
    const remainder = commitBuffer(false);
    if (input.value !== remainder){
      input.value = remainder;
    }
  });

  const form = input.closest('form');
  if (form){
    form.addEventListener('submit', (e) => {
      const remainder = commitBuffer(false);
      if (input.value !== remainder){
        input.value = remainder;
      }
      if (emails.size === 0){
        e.preventDefault();
        alert('Please add at least one valid email address.');
      }
    });
  }
})();
</script>

</body>
</html>
