<?php
// trainer_sessions_helpers.php — session package data helpers for trainers/admins.

require_once __DIR__ . '/helpers.php';

if (!function_exists('ppf_trainer_sessions_rate_card')) {
    /**
     * Returns the trainer session rate card tiers.
     * Each tier contains: min_sessions, max_sessions (nullable), price_per_session, label.
     */
    function ppf_trainer_sessions_rate_card(): array {
        return [
            [
                'label' => 'Single Session',
                'min_sessions' => 1,
                'max_sessions' => 1,
                'price_per_session' => 120.00,
            ],
            [
                'label' => 'Starter Pack (2-4)',
                'min_sessions' => 2,
                'max_sessions' => 4,
                'price_per_session' => 110.00,
            ],
            [
                'label' => 'Focused Pack (5-9)',
                'min_sessions' => 5,
                'max_sessions' => 9,
                'price_per_session' => 100.00,
            ],
            [
                'label' => 'Transformation Pack (10+)',
                'min_sessions' => 10,
                'max_sessions' => null,
                'price_per_session' => 95.00,
            ],
        ];
    }
}

if (!function_exists('ppf_trainer_sessions_rate_for_quantity')) {
    /**
     * Resolve the default per-session rate for a quantity using the rate card.
     */
    function ppf_trainer_sessions_rate_for_quantity(int $sessions): float {
        $sessions = max(1, $sessions);
        foreach (ppf_trainer_sessions_rate_card() as $tier) {
            $min = (int)($tier['min_sessions'] ?? 1);
            $max = $tier['max_sessions'];
            if ($sessions >= $min && ($max === null || $sessions <= (int)$max)) {
                return (float)$tier['price_per_session'];
            }
        }
        $tiers = ppf_trainer_sessions_rate_card();
        $last = end($tiers);
        return (float)($last['price_per_session'] ?? 0.0);
    }
}

if (!function_exists('ppf_trainer_sessions_pricing_mode')) {
    function ppf_trainer_sessions_pricing_mode(mysqli $conn): string {
        $default = 'trainer';
        $value = null;
        try {
            if (function_exists('ppf_ss_get')) {
                $value = ppf_ss_get($conn, 'trainer_sessions_pricing_mode', $default);
            } else {
                $sql = "SELECT value FROM system_settings WHERE `key`='trainer_sessions_pricing_mode' LIMIT 1";
                if ($res = $conn->query($sql)) {
                    if ($row = $res->fetch_assoc()) {
                        $value = $row['value'] ?? null;
                    }
                    $res->free();
                }
            }
        } catch (Throwable $e) {
            $value = $default;
        }
        $value = strtolower(trim((string)$value));
        return $value === 'admin' ? 'admin' : $default;
    }
}

if (!function_exists('ppf_trainer_sessions_format_catalog_expiration')) {
    function ppf_trainer_sessions_format_catalog_expiration(array $row): string {
        $type = strtolower((string)($row['expires_type'] ?? 'none'));
        if ($type === 'date') {
            $date = $row['expires_on'] ?? null;
            if (!$date) {
                return 'Expires on purchase date';
            }
            try {
                $dt = new DateTime($date);
                return 'Expires ' . $dt->format('M j, Y');
            } catch (Throwable $e) {
                return 'Expires ' . $date;
            }
        }
        if ($type === 'duration') {
            $value = (int)($row['expires_value'] ?? 0);
            $unit = strtolower((string)($row['expires_unit'] ?? ''));
            if ($value <= 0 || !in_array($unit, ['days','weeks','months','years'], true)) {
                return 'Sessions expire after purchase';
            }
            $unitLabel = $unit;
            if ($value === 1) {
                $unitLabel = rtrim($unitLabel, 's');
            }
            return 'Expires after ' . $value . ' ' . $unitLabel;
        }
        return 'Sessions do not expire';
    }
}

if (!function_exists('ppf_trainer_sessions_fetch_catalog_packages')) {
    function ppf_trainer_sessions_fetch_catalog_packages(mysqli $conn, array $options = []): array {
        ppf_trainer_sessions_ensure_schema($conn);
        $mode = strtolower((string)($options['mode'] ?? 'trainer'));
        $trainerId = (int)($options['trainer_id'] ?? 0);
        $scope = $mode === 'admin' ? 'global' : 'trainer';
        $params = [$scope];
        $types = 's';
        $where = 'WHERE scope = ?';
        if ($scope === 'trainer') {
            $where .= ' AND trainer_id = ?';
            $params[] = $trainerId;
            $types .= 'i';
        }
        $sql = "SELECT id, scope, trainer_id, title, session_count, price_mode, price_per_session, total_price, expires_type, expires_unit, expires_value, expires_on, created_at, updated_at FROM trainer_session_price_packages {$where} ORDER BY created_at DESC, id DESC";
        if (!$stmt = $conn->prepare($sql)) {
            return [];
        }
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $idx => $val) {
                $bind[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $packages = [];
        while ($row = $res->fetch_assoc()) {
            $sessionCount = max(1, (int)($row['session_count'] ?? 1));
            $pricePer = (float)($row['price_per_session'] ?? 0);
            $total = $row['total_price'] !== null ? (float)$row['total_price'] : $pricePer * $sessionCount;
            $packages[] = [
                'id' => (int)($row['id'] ?? 0),
                'scope' => (string)($row['scope'] ?? $scope),
                'trainer_id' => (int)($row['trainer_id'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
                'session_count' => $sessionCount,
                'price_mode' => (string)($row['price_mode'] ?? 'per_session'),
                'price_per_session' => $pricePer,
                'total_price' => $total,
                'expires_type' => (string)($row['expires_type'] ?? 'none'),
                'expires_unit' => $row['expires_unit'] ?? null,
                'expires_value' => $row['expires_value'] ?? null,
                'expires_on' => $row['expires_on'] ?? null,
                'expires_label' => ppf_trainer_sessions_format_catalog_expiration($row),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
        $stmt->close();
        return $packages;
    }
}

if (!function_exists('ppf_trainer_sessions_parse_amount')) {
    /**
     * Parse a money string to float. Returns 0.0 when empty/invalid.
     */
    function ppf_trainer_sessions_parse_amount($raw): float {
        if ($raw === null) return 0.0;
        if (is_numeric($raw)) return (float)$raw;
        $clean = preg_replace('/[^0-9.\-]+/', '', (string)$raw);
        if ($clean === '' || $clean === '-' || !is_numeric($clean)) return 0.0;
        return (float)$clean;
    }
}

if (!function_exists('ppf_trainer_sessions_ensure_schema')) {
    /**
     * Ensure supporting tables exist (idempotent).
     */
    function ppf_trainer_sessions_ensure_schema(mysqli $conn): void {
    @$conn->query(
        "CREATE TABLE IF NOT EXISTS trainer_session_packages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            trainer_id INT NOT NULL,
            package_name VARCHAR(191) NOT NULL,
            purchased_sessions INT NOT NULL DEFAULT 0,
                price_per_session DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_trainer (trainer_id),
                INDEX idx_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS trainer_session_price_packages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scope ENUM('trainer','global') NOT NULL DEFAULT 'trainer',
                trainer_id INT UNSIGNED NOT NULL DEFAULT 0,
                title VARCHAR(191) NOT NULL,
                session_count INT UNSIGNED NOT NULL DEFAULT 1,
                price_mode ENUM('per_session') NOT NULL DEFAULT 'per_session',
                price_per_session DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                expires_type ENUM('none','duration','date') NOT NULL DEFAULT 'none',
                expires_unit ENUM('days','weeks','months','years') DEFAULT NULL,
                expires_value INT UNSIGNED DEFAULT NULL,
                expires_on DATE DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_scope (scope),
                INDEX idx_trainer (trainer_id),
                INDEX idx_scope_trainer (scope, trainer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS trainer_sessions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                package_id INT UNSIGNED NOT NULL,
                scheduled_start DATETIME NULL,
                scheduled_end DATETIME NULL,
                actual_start_at DATETIME NULL,
                actual_end_at DATETIME NULL,
                status ENUM('scheduled','active','completed','canceled','cancelled','refunded','rescheduled','in_progress') NOT NULL DEFAULT 'scheduled',
                completed_at DATETIME NULL,
                completion_marked_by INT NULL,
                timer_started_by INT NULL,
                timer_ended_by INT NULL,
                duration_seconds INT NULL,
                notes TEXT NULL,
                public_token CHAR(36) NULL,
                token_verified_at DATETIME NULL,
                verified_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_package (package_id),
                INDEX idx_start (scheduled_start),
                INDEX idx_status (status),
                UNIQUE KEY uq_trainer_sessions_token (public_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Ensure new columns exist for deployments that created the table before the
        // stopwatch functionality was introduced. We rely on column_exists from
        // helpers.php if available, otherwise fire-and-forget ALTERs.
        $hasColumnFn = function_exists('column_exists') ? 'column_exists' : null;
        $alterStatements = [
            ['actual_start_at', "ALTER TABLE trainer_sessions ADD COLUMN actual_start_at DATETIME NULL AFTER scheduled_end"],
            ['actual_end_at', "ALTER TABLE trainer_sessions ADD COLUMN actual_end_at DATETIME NULL AFTER actual_start_at"],
            ['timer_started_by', "ALTER TABLE trainer_sessions ADD COLUMN timer_started_by INT NULL AFTER completion_marked_by"],
            ['timer_ended_by', "ALTER TABLE trainer_sessions ADD COLUMN timer_ended_by INT NULL AFTER timer_started_by"],
            ['duration_seconds', "ALTER TABLE trainer_sessions ADD COLUMN duration_seconds INT NULL AFTER timer_ended_by"],
            ['public_token', "ALTER TABLE trainer_sessions ADD COLUMN public_token CHAR(36) NULL AFTER notes"],
            ['token_verified_at', "ALTER TABLE trainer_sessions ADD COLUMN token_verified_at DATETIME NULL AFTER public_token"],
            ['verified_by', "ALTER TABLE trainer_sessions ADD COLUMN verified_by INT NULL AFTER token_verified_at"],
        ];
        foreach ($alterStatements as [$column, $sql]) {
            $shouldAlter = true;
            if ($hasColumnFn) {
                try {
                    $shouldAlter = !column_exists($conn, 'trainer_sessions', $column);
                } catch (Throwable $e) {
                    $shouldAlter = true;
                }
            }
            if (!$shouldAlter) {
                continue;
            }

            try {
                @$conn->query($sql);
            } catch (Throwable $e) {
                // Ignore duplicate column errors so repeated deployments remain idempotent
                // even on environments where column_exists cannot probe INFORMATION_SCHEMA.
                $code = method_exists($e, 'getCode') ? $e->getCode() : null;
                if ((int)$code !== 1060) {
                    throw $e;
                }
            }
        }

        // Ensure the status ENUM contains the states used by the redesigned workflow.
        if ($res = @$conn->query("SHOW COLUMNS FROM trainer_sessions LIKE 'status'")) {
            if ($row = $res->fetch_assoc()) {
                $type = $row['Type'] ?? '';
                $expectedStates = [
                    "'scheduled'",
                    "'active'",
                    "'completed'",
                    "'canceled'",
                    "'cancelled'",
                    "'refunded'",
                    "'rescheduled'",
                    "'in_progress'",
                ];
                $missing = false;
                foreach ($expectedStates as $stateFragment) {
                    if (stripos($type, $stateFragment) === false) {
                        $missing = true;
                        break;
                    }
                }
                if ($missing) {
                    @$conn->query("ALTER TABLE trainer_sessions MODIFY COLUMN status ENUM('scheduled','active','completed','canceled','cancelled','refunded','rescheduled','in_progress') NOT NULL DEFAULT 'scheduled'");
                }
            }
            $res->free();
        }

        // Ensure the unique constraint for the token exists.
        try {
            @$conn->query("ALTER TABLE trainer_sessions ADD UNIQUE KEY uq_trainer_sessions_token (public_token)");
        } catch (Throwable $e) {
            $code = method_exists($e, 'getCode') ? (int)$e->getCode() : 0;
            if ($code !== 1061) {
                throw $e;
            }
        }

        try {
            @$conn->query("ALTER TABLE trainer_sessions MODIFY COLUMN scheduled_start DATETIME NULL");
        } catch (Throwable $e) {
            // ignore errors when column already nullable
        }

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS trainer_session_transactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                package_id INT UNSIGNED NOT NULL,
                txn_type ENUM('payment','refund') NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                description VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by INT NULL,
                INDEX idx_package (package_id),
                INDEX idx_type (txn_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('ppf_trainer_sessions_fetch_clients')) {
    function ppf_trainer_sessions_fetch_clients(mysqli $conn): array {
        $out = [];
        $sql = "SELECT id, first_name, last_name, email FROM users WHERE role='client' OR is_client=1 ORDER BY last_name, first_name, id";
        if ($res = $conn->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
            $res->free();
        }
        return $out;
    }
}

if (!function_exists('ppf_trainer_sessions_build_row')) {
    function ppf_trainer_sessions_build_row(array $row): array {
        $purchased = (int)($row['purchased_sessions'] ?? 0);
        $completed = (int)($row['completed_count'] ?? 0);
        $remaining = max(0, $purchased - $completed);
        $payments = (float)($row['payments_total'] ?? 0);
        $refunds = (float)($row['refunds_total'] ?? 0);
        $balance = $payments - $refunds;
        $price = (float)($row['price_per_session'] ?? 0);
        $value = $purchased * $price;
        $row['remaining_sessions'] = $remaining;
        $row['balance'] = $balance;
        $row['package_value'] = $value;
        $row['payments_total'] = $payments;
        $row['refunds_total'] = $refunds;
        return $row;
    }
}

if (!function_exists('ppf_trainer_sessions_fetch_package_summary')) {
    function ppf_trainer_sessions_fetch_package_summary(mysqli $conn, int $packageId): ?array {
        ppf_trainer_sessions_ensure_schema($conn);
        $sql = "
            SELECT
                p.id,
                p.client_id,
                p.trainer_id,
                p.package_name,
                p.purchased_sessions,
                p.price_per_session,
                p.notes,
                p.created_at,
                p.updated_at,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                agg.scheduled_open,
                agg.completed_count,
                agg.cancelled_count,
                agg.next_session_at,
                agg.last_completed_at,
                pay.payments_total,
                pay.refunds_total
            FROM trainer_session_packages p
            JOIN users u ON u.id = p.client_id
            LEFT JOIN (
                SELECT
                    package_id,
                    SUM(status IN ('scheduled','in_progress')) AS scheduled_open,
                    SUM(status='completed') AS completed_count,
                    SUM(status='cancelled') AS cancelled_count,
                    MIN(CASE WHEN status IN ('scheduled','in_progress') THEN scheduled_start END) AS next_session_at,
                    MAX(COALESCE(actual_end_at, completed_at)) AS last_completed_at
                FROM trainer_sessions
                GROUP BY package_id
            ) agg ON agg.package_id = p.id
            LEFT JOIN (
                SELECT
                    package_id,
                    SUM(CASE WHEN txn_type='payment' THEN amount ELSE 0 END) AS payments_total,
                    SUM(CASE WHEN txn_type='refund' THEN amount ELSE 0 END) AS refunds_total
                FROM trainer_session_transactions
                GROUP BY package_id
            ) pay ON pay.package_id = p.id
            WHERE p.id = ?
            LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            return null;
        }
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return null;
        return ppf_trainer_sessions_build_row($row);
    }
}

if (!function_exists('ppf_trainer_sessions_fetch_packages')) {
    function ppf_trainer_sessions_fetch_packages(mysqli $conn, ?int $trainerId, ?int $clientId = null): array {
        ppf_trainer_sessions_ensure_schema($conn);
        $params = [];
        $types = '';
        $where = ' WHERE 1=1';
        if ($trainerId !== null && $trainerId > 0) {
            $where .= ' AND p.trainer_id = ?';
            $params[] = $trainerId;
            $types .= 'i';
        }
        if ($clientId !== null && $clientId > 0) {
            $where .= ' AND p.client_id = ?';
            $params[] = $clientId;
            $types .= 'i';
        }
        $sql = "
            SELECT
                p.id,
                p.client_id,
                p.trainer_id,
                p.package_name,
                p.purchased_sessions,
                p.price_per_session,
                p.notes,
                p.created_at,
                p.updated_at,
                u.first_name,
                u.last_name,
                u.email,
                agg.scheduled_open,
                agg.completed_count,
                agg.cancelled_count,
                agg.next_session_at,
                agg.last_completed_at,
                pay.payments_total,
                pay.refunds_total
            FROM trainer_session_packages p
            JOIN users u ON u.id = p.client_id
            LEFT JOIN (
                SELECT
                    package_id,
                    SUM(status IN ('scheduled','in_progress')) AS scheduled_open,
                    SUM(status='completed') AS completed_count,
                    SUM(status='cancelled') AS cancelled_count,
                    MIN(CASE WHEN status IN ('scheduled','in_progress') THEN scheduled_start END) AS next_session_at,
                    MAX(COALESCE(actual_end_at, completed_at)) AS last_completed_at
                FROM trainer_sessions
                GROUP BY package_id
            ) agg ON agg.package_id = p.id
            LEFT JOIN (
                SELECT
                    package_id,
                    SUM(CASE WHEN txn_type='payment' THEN amount ELSE 0 END) AS payments_total,
                    SUM(CASE WHEN txn_type='refund' THEN amount ELSE 0 END) AS refunds_total
                FROM trainer_session_transactions
                GROUP BY package_id
            ) pay ON pay.package_id = p.id
            {$where}
            ORDER BY u.last_name, u.first_name, p.created_at DESC";
        if (!$stmt = $conn->prepare($sql)) {
            return [];
        }
        if ($params) {
            $bind = [$types];
            foreach ($params as $idx => $val) {
                $bind[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $packages = [];
        while ($row = $res->fetch_assoc()) {
            $packages[] = ppf_trainer_sessions_build_row($row);
        }
        $stmt->close();
        return $packages;
    }
}

if (!function_exists('ppf_trainer_sessions_fetch_sessions_for_package')) {
    function ppf_trainer_sessions_fetch_sessions_for_package(mysqli $conn, int $packageId): array {
        $sql = "SELECT id, package_id, scheduled_start, scheduled_end, actual_start_at, actual_end_at, status, completed_at, completion_marked_by, timer_started_by, timer_ended_by, duration_seconds, notes, created_at, updated_at, public_token FROM trainer_sessions WHERE package_id = ? ORDER BY scheduled_start ASC, id ASC";
        if (!$stmt = $conn->prepare($sql)) {
            return [];
        }
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ppf_trainer_sessions_fetch_transactions_for_package')) {
    function ppf_trainer_sessions_fetch_transactions_for_package(mysqli $conn, int $packageId): array {
        $sql = "SELECT id, package_id, txn_type, amount, description, created_at, created_by FROM trainer_session_transactions WHERE package_id = ? ORDER BY created_at DESC, id DESC";
        if (!$stmt = $conn->prepare($sql)) {
            return [];
        }
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ppf_trainer_sessions_format_money')) {
    function ppf_trainer_sessions_format_money($amount): string {
        $value = (float)$amount;
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);
        return $sign . '$' . number_format($abs, 2);
    }
}

if (!function_exists('ppf_trainer_sessions_generate_token')) {
    function ppf_trainer_sessions_generate_token(mysqli $conn): string {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $bytes = random_bytes(16);
            $hex = bin2hex($bytes);
            $token = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
            $sql = "SELECT 1 FROM trainer_sessions WHERE public_token = ? LIMIT 1";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $stmt->store_result();
                $exists = $stmt->num_rows > 0;
                $stmt->close();
                if (!$exists) {
                    return $token;
                }
            }
        }
        throw new RuntimeException('Failed to generate unique session token.');
    }
}

if (!function_exists('ppf_trainer_sessions_assign_token')) {
    function ppf_trainer_sessions_assign_token(mysqli $conn, int $sessionId): ?string {
        if ($sessionId <= 0) {
            return null;
        }
        $token = ppf_trainer_sessions_generate_token($conn);
        $sql = "UPDATE trainer_sessions SET public_token = ?, updated_at = NOW() WHERE id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('si', $token, $sessionId);
            if ($stmt->execute()) {
                $stmt->close();
                return $token;
            }
            $stmt->close();
        }
        return null;
    }
}

if (!function_exists('ppf_trainer_sessions_find_by_token')) {
    function ppf_trainer_sessions_find_by_token(mysqli $conn, string $token): ?array {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $sql = "
            SELECT s.*, p.package_name, p.trainer_id, p.client_id,
                   u.first_name AS client_first, u.last_name AS client_last,
                   t.first_name AS trainer_first, t.last_name AS trainer_last
            FROM trainer_sessions s
            JOIN trainer_session_packages p ON p.id = s.package_id
            LEFT JOIN users u ON u.id = p.client_id
            LEFT JOIN users t ON t.id = p.trainer_id
            WHERE s.public_token = ?
            LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            return null;
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $session = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $session ?: null;
    }
}

if (!function_exists('ppf_trainer_sessions_collect_client_overview')) {
    function ppf_trainer_sessions_collect_client_overview(mysqli $conn, array $options = []): array {
        ppf_trainer_sessions_ensure_schema($conn);
        $trainerId = isset($options['trainer_id']) ? (int)$options['trainer_id'] : 0;
        $includeUnassigned = !empty($options['include_unassigned']);

        $params = [];
        $types = '';
        $conditions = [];
        $conditions[] = "(u.role='client' OR u.is_client=1)";
        if ($trainerId > 0) {
            $conditions[] = '(u.assigned_trainer_id = ? OR EXISTS (SELECT 1 FROM trainer_session_packages sp WHERE sp.client_id = u.id AND sp.trainer_id = ?))';
            $params[] = $trainerId;
            $params[] = $trainerId;
            $types .= 'ii';
        }
        if (!$includeUnassigned) {
            $conditions[] = '(u.assigned_trainer_id IS NOT NULL OR EXISTS (SELECT 1 FROM trainer_session_packages sp WHERE sp.client_id = u.id))';
        }
        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $sql = "
            SELECT
                u.id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.email,
                u.phone,
                u.birthdate,
                u.height_ft,
                u.height_in,
                u.weight_lbs,
                u.assigned_trainer_id,
                agg.total_packages,
                agg.total_sessions,
                agg.completed_sessions,
                agg.canceled_sessions,
                agg.refunded_sessions,
                agg.latest_purchase,
                agg.latest_session,
                agg.total_payments,
                agg.total_refunds
            FROM users u
            LEFT JOIN (
                SELECT
                    p.client_id,
                    COUNT(DISTINCT p.id) AS total_packages,
                    SUM(p.purchased_sessions) AS total_sessions,
                    SUM(IFNULL(stat.completed_sessions, 0)) AS completed_sessions,
                    SUM(IFNULL(stat.canceled_sessions, 0)) AS canceled_sessions,
                    SUM(IFNULL(stat.refunded_sessions, 0)) AS refunded_sessions,
                    MAX(p.created_at) AS latest_purchase,
                    MAX(stat.latest_session) AS latest_session,
                    SUM(IFNULL(tx.payments_total, 0)) AS total_payments,
                    SUM(IFNULL(tx.refunds_total, 0)) AS total_refunds,
                    MAX(p.trainer_id) AS last_trainer_id
                FROM trainer_session_packages p
                LEFT JOIN (
                    SELECT
                        s.package_id,
                        SUM(s.status IN ('completed')) AS completed_sessions,
                        SUM(s.status IN ('canceled','cancelled')) AS canceled_sessions,
                        SUM(s.status IN ('refunded')) AS refunded_sessions,
                        MAX(s.scheduled_start) AS latest_session
                    FROM trainer_sessions s
                    GROUP BY s.package_id
                ) stat ON stat.package_id = p.id
                LEFT JOIN (
                    SELECT
                        package_id,
                        SUM(CASE WHEN txn_type='payment' THEN amount ELSE 0 END) AS payments_total,
                        SUM(CASE WHEN txn_type='refund' THEN amount ELSE 0 END) AS refunds_total
                    FROM trainer_session_transactions
                    GROUP BY package_id
                ) tx ON tx.package_id = p.id
                GROUP BY p.client_id
            ) agg ON agg.client_id = u.id
            {$where}
            GROUP BY u.id
            ORDER BY u.last_name, u.first_name, u.id
        ";

        if (!$stmt = $conn->prepare($sql)) {
            return [];
        }
        if ($params) {
            $bind = [$types];
            foreach ($params as $idx => $val) {
                $bind[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ppf_trainer_sessions_collect_client_packages')) {
    function ppf_trainer_sessions_collect_client_packages(mysqli $conn, int $clientId, ?int $trainerId = null): array {
        $packages = ppf_trainer_sessions_fetch_packages($conn, $trainerId, $clientId);
        foreach ($packages as &$package) {
            $pid = (int)($package['id'] ?? 0);
            $package['sessions'] = ppf_trainer_sessions_fetch_sessions_for_package($conn, $pid);
            $package['transactions'] = ppf_trainer_sessions_fetch_transactions_for_package($conn, $pid);
        }
        unset($package);
        return $packages;
    }
}

if (!function_exists('ppf_trainer_sessions_find_active_session_for_client')) {
    function ppf_trainer_sessions_find_active_session_for_client(mysqli $conn, int $clientId): ?array {
        if ($clientId <= 0) {
            return null;
        }
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            SELECT s.*, p.package_name, p.trainer_id, p.client_id,
                   t.first_name AS trainer_first, t.last_name AS trainer_last
            FROM trainer_sessions s
            JOIN trainer_session_packages p ON p.id = s.package_id
            LEFT JOIN users t ON t.id = p.trainer_id
            WHERE p.client_id = ?
              AND s.status IN ('scheduled','rescheduled','active','in_progress')
              AND s.scheduled_start IS NOT NULL
              AND s.scheduled_start <= ?
              AND (s.scheduled_end IS NULL OR s.scheduled_end >= ?)
            ORDER BY s.scheduled_start ASC, s.id ASC
            LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            return null;
        }
        $stmt->bind_param('iss', $clientId, $now, $now);
        $stmt->execute();
        $res = $stmt->get_result();
        $session = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$session) {
            return null;
        }
        if (empty($session['public_token'])) {
            $newToken = ppf_trainer_sessions_assign_token($conn, (int)$session['id']);
            if ($newToken) {
                $session['public_token'] = $newToken;
            }
        }
        return $session;
    }
}

if (!function_exists('ppf_trainer_sessions_find_active_session_for_trainer')) {
    function ppf_trainer_sessions_find_active_session_for_trainer(mysqli $conn, int $trainerId): ?array {
        if ($trainerId <= 0) {
            return null;
        }
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            SELECT s.*, p.package_name, p.trainer_id, p.client_id,
                   u.first_name AS client_first, u.last_name AS client_last
            FROM trainer_sessions s
            JOIN trainer_session_packages p ON p.id = s.package_id
            JOIN users u ON u.id = p.client_id
            WHERE p.trainer_id = ?
              AND s.status IN ('scheduled','rescheduled','active','in_progress')
              AND s.scheduled_start IS NOT NULL
              AND s.scheduled_start <= ?
              AND (s.scheduled_end IS NULL OR s.scheduled_end >= ?)
            ORDER BY s.scheduled_start ASC, s.id ASC
            LIMIT 1";
        if (!$stmt = $conn->prepare($sql)) {
            return null;
        }
        $stmt->bind_param('iss', $trainerId, $now, $now);
        $stmt->execute();
        $res = $stmt->get_result();
        $session = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$session) {
            return null;
        }
        if (empty($session['public_token'])) {
            $newToken = ppf_trainer_sessions_assign_token($conn, (int)$session['id']);
            if ($newToken) {
                $session['public_token'] = $newToken;
            }
        }
        return $session;
    }
}

if (!function_exists('ppf_trainer_sessions_dashboard_rollup')) {
    /**
     * Aggregate session package metrics for dashboard widgets.
     * Returns totals and up to $limit client rows (ordered by upcoming session).
     */
    function ppf_trainer_sessions_dashboard_rollup(mysqli $conn, ?int $trainerId = null, ?int $clientId = null, int $limit = 6): array {
        ppf_trainer_sessions_ensure_schema($conn);

        $summary = [
            'totals' => [
                'purchased' => 0,
                'scheduled' => 0,
                'completed' => 0,
                'remaining' => 0,
            ],
            'clients' => [],
            'total_clients' => 0,
        ];

        if (!function_exists('table_exists') || !table_exists($conn, 'trainer_session_packages')) {
            return $summary;
        }

        $where = ' WHERE 1=1';
        $params = [];
        $types = '';

        if ($trainerId !== null && $trainerId > 0) {
            $where .= ' AND p.trainer_id = ?';
            $params[] = $trainerId;
            $types .= 'i';
        }
        if ($clientId !== null && $clientId > 0) {
            $where .= ' AND p.client_id = ?';
            $params[] = $clientId;
            $types .= 'i';
        }

        $sql = "
            SELECT
                pkg.client_id,
                pkg.first_name,
                pkg.last_name,
                pkg.email,
                SUM(pkg.purchased_sessions) AS purchased_total,
                SUM(pkg.scheduled_open) AS scheduled_total,
                SUM(pkg.completed_count) AS completed_total,
                SUM(pkg.remaining_sessions) AS remaining_total,
                MIN(pkg.next_session_at) AS next_session_at
            FROM (
                SELECT
                    p.client_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    p.purchased_sessions,
                    COALESCE(ts.scheduled_open, 0) AS scheduled_open,
                    COALESCE(ts.completed_count, 0) AS completed_count,
                    GREATEST(p.purchased_sessions - COALESCE(ts.completed_count, 0), 0) AS remaining_sessions,
                    ts.next_session_at
                FROM trainer_session_packages p
                JOIN users u ON u.id = p.client_id
                LEFT JOIN (
                    SELECT
                        package_id,
                        SUM(status IN ('scheduled','in_progress')) AS scheduled_open,
                        SUM(status='completed') AS completed_count,
                        MIN(CASE WHEN status IN ('scheduled','in_progress') THEN scheduled_start END) AS next_session_at
                    FROM trainer_sessions
                    GROUP BY package_id
                ) ts ON ts.package_id = p.id
                {$where}
            ) pkg
            GROUP BY pkg.client_id, pkg.first_name, pkg.last_name, pkg.email
            ORDER BY (MIN(pkg.next_session_at) IS NULL), MIN(pkg.next_session_at), pkg.last_name, pkg.first_name
        ";

        if (!$stmt = $conn->prepare($sql)) {
            return $summary;
        }

        if ($params) {
            $bind = [$types];
            foreach ($params as $idx => $val) {
                $bind[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return $summary;
        }

        $res = $stmt->get_result();
        $allClients = [];
        while ($row = $res->fetch_assoc()) {
            $purchased = (int)($row['purchased_total'] ?? 0);
            $scheduled = (int)($row['scheduled_total'] ?? 0);
            $completed = (int)($row['completed_total'] ?? 0);
            $remaining = (int)($row['remaining_total'] ?? max(0, $purchased - $completed));
            if ($remaining < 0) {
                $remaining = 0;
            }

            $first = trim((string)($row['first_name'] ?? ''));
            $last  = trim((string)($row['last_name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = $email !== '' ? $email : 'Client #' . (int)($row['client_id'] ?? 0);
            }

            $client = [
                'client_id' => (int)($row['client_id'] ?? 0),
                'name' => $name,
                'purchased' => $purchased,
                'scheduled' => $scheduled,
                'completed' => $completed,
                'remaining' => $remaining,
                'next_session_at' => $row['next_session_at'] ?? null,
            ];

            $summary['totals']['purchased'] += $purchased;
            $summary['totals']['scheduled'] += $scheduled;
            $summary['totals']['completed'] += $completed;
            $summary['totals']['remaining'] += $remaining;

            $allClients[] = $client;
        }

        $stmt->close();

        if (!empty($allClients)) {
            usort($allClients, static function (array $a, array $b): int {
                $aRaw = $a['next_session_at'] ?? null;
                $bRaw = $b['next_session_at'] ?? null;

                $aTime = $aRaw ? strtotime((string)$aRaw) : false;
                $bTime = $bRaw ? strtotime((string)$bRaw) : false;
                $aTime = ($aTime !== false) ? $aTime : null;
                $bTime = ($bTime !== false) ? $bTime : null;

                $aHas = ($aTime !== null);
                $bHas = ($bTime !== null);

                if ($aHas && !$bHas) {
                    return -1;
                }
                if (!$aHas && $bHas) {
                    return 1;
                }
                if ($aHas && $bHas && $aTime !== $bTime) {
                    return $aTime <=> $bTime;
                }

                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });
        }

        $summary['total_clients'] = count($allClients);
        if ($limit > 0 && $summary['total_clients'] > $limit) {
            $summary['clients'] = array_slice($allClients, 0, $limit);
        } else {
            $summary['clients'] = $allClients;
        }

        return $summary;
    }
}

if (!function_exists('ppf_trainer_sessions_within_window')) {
    /**
     * Helper to determine if now is within the scheduled window.
     */
    function ppf_trainer_sessions_within_window(array $session): bool {
        $now = new DateTimeImmutable('now');
        try {
            $start = new DateTimeImmutable($session['scheduled_start'] ?? '');
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
                // Ignore invalid end dates; treat as open-ended after window start
            }
        }

        return true;
    }
}
