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
            "CREATE TABLE IF NOT EXISTS trainer_sessions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                package_id INT UNSIGNED NOT NULL,
                scheduled_start DATETIME NOT NULL,
                scheduled_end DATETIME NULL,
                status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
                completed_at DATETIME NULL,
                completion_marked_by INT NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_package (package_id),
                INDEX idx_start (scheduled_start),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

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
                    SUM(status='scheduled') AS scheduled_open,
                    SUM(status='completed') AS completed_count,
                    SUM(status='cancelled') AS cancelled_count,
                    MIN(CASE WHEN status='scheduled' THEN scheduled_start END) AS next_session_at,
                    MAX(CASE WHEN status='completed' THEN completed_at END) AS last_completed_at
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
                    SUM(status='scheduled') AS scheduled_open,
                    SUM(status='completed') AS completed_count,
                    SUM(status='cancelled') AS cancelled_count,
                    MIN(CASE WHEN status='scheduled' THEN scheduled_start END) AS next_session_at,
                    MAX(CASE WHEN status='completed' THEN completed_at END) AS last_completed_at
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
        $sql = "SELECT id, package_id, scheduled_start, scheduled_end, status, completed_at, completion_marked_by, notes, created_at, updated_at FROM trainer_sessions WHERE package_id = ? ORDER BY scheduled_start ASC, id ASC";
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
                        SUM(status='scheduled') AS scheduled_open,
                        SUM(status='completed') AS completed_count,
                        MIN(CASE WHEN status='scheduled' THEN scheduled_start END) AS next_session_at
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
        $endRaw = $session['scheduled_end'] ?? null;
        $end = null;
        if ($endRaw) {
            try {
                $end = new DateTimeImmutable($endRaw);
            } catch (Throwable $e) {
                $end = null;
            }
        }
        if ($end) {
            return ($now >= $start && $now <= $end);
        }
        return ($now >= $start);
    }
}
