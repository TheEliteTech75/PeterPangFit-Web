<?php
declare(strict_types=1);

class PPFNotificationsFakeResult
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;
    private int $cursor = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function fetch_assoc(): ?array
    {
        if ($this->cursor >= count($this->rows)) {
            return null;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetch_row(): ?array
    {
        $assoc = $this->fetch_assoc();
        return $assoc === null ? null : array_values($assoc);
    }
}

class PPFNotificationsFakeStmt extends mysqli_stmt
{
    public PPFNotificationsFakeMysqli $conn;
    public string $sql;
    public string $types = '';
    /** @var array<int, mixed> */
    public array $params = [];
    /** @var array<int, array<string, mixed>> */
    private array $resultRows = [];
    private int|string $affectedRowsValue = 0;
    private int|string $insertIdValue = 0;

    public function __construct(PPFNotificationsFakeMysqli $conn, string $sql)
    {
        $this->conn = $conn;
        $this->sql = trim($sql);
    }

    public function bind_param(string $types, &...$vars): bool
    {
        $this->types = $types;
        $this->params = [];
        $args = func_get_args();
        for ($i = 1; $i < count($args); $i++) {
            $this->params[] = $args[$i];
        }
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->affectedRowsValue = 0;
        $this->insertIdValue = 0;
        $this->resultRows = [];
        $sql = $this->sql;
        $upper = strtoupper($sql);

        if (strpos($upper, 'SELECT DELIVERY_PREFS') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $userId = (int)($this->params[1] ?? 0);
            $settings = $this->conn->getSettings($tenantId, $userId);
            if ($settings !== null) {
                $this->resultRows[] = [
                    'delivery_prefs' => json_encode($settings['delivery_prefs']),
                    'types_muted' => json_encode($settings['types_muted']),
                ];
            }
            return true;
        }

        if (strpos($upper, 'INSERT INTO NOTIFICATION_SETTINGS') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $userId = (int)($this->params[1] ?? 0);
            $prefs = json_decode((string)($this->params[2] ?? '[]'), true) ?: [];
            $muted = json_decode((string)($this->params[3] ?? '[]'), true) ?: [];
            $this->conn->setSettings($tenantId, $userId, [
                'delivery_prefs' => $prefs,
                'types_muted' => $muted,
            ]);
            $this->affectedRowsValue = 1;
            return true;
        }

        if (strpos($upper, 'INSERT INTO NOTIFICATION_EVENTS') === 0) {
            $this->conn->logEvent([
                'tenant_id' => (int)($this->params[0] ?? 0),
                'user_id' => (int)($this->params[1] ?? 0),
                'notification_id' => (int)($this->params[2] ?? 0),
                'event_type' => (string)($this->params[3] ?? ''),
                'actor_user_id' => $this->params[4] !== null ? (int)$this->params[4] : null,
                'context' => json_decode((string)($this->params[5] ?? ''), true) ?: [],
            ]);
            $this->affectedRowsValue = 1;
            return true;
        }

        if (strpos($upper, 'INSERT INTO NOTIFICATIONS') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $userId = (int)($this->params[1] ?? 0);
            $title = (string)($this->params[2] ?? '');
            $body = (string)($this->params[3] ?? '');
            $type = (string)($this->params[4] ?? 'info');
            $url = $this->params[5] !== null && $this->params[5] !== '' ? (string)$this->params[5] : null;
            $priority = (int)($this->params[6] ?? 0);
            $metadata = json_decode((string)($this->params[7] ?? '[]'), true) ?: [];
            $actions = json_decode((string)($this->params[8] ?? '[]'), true) ?: [];
            $this->insertIdValue = $this->conn->insertNotification($tenantId, $userId, [
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'url' => $url,
                'priority' => $priority,
                'metadata' => $metadata,
                'actions' => $actions,
            ]);
            $this->affectedRowsValue = 1;
            return true;
        }

        if (strpos($upper, 'SELECT COUNT(*)') === 0) {
            $context = $this->conn->getQueryContext();
            $rows = $this->conn->applyFilters($context);
            $this->resultRows[] = ['c' => count($rows)];
            return true;
        }

        if (strpos($upper, 'SELECT * FROM NOTIFICATIONS') === 0) {
            if (strpos($upper, 'LIMIT ? OFFSET ?') !== false) {
                $context = $this->conn->getQueryContext();
                $rows = $this->conn->applyFilters($context);
                $sort = (string)($context['options']['sort'] ?? 'created_at:desc');
                $rows = $this->conn->sortNotifications($rows, $sort);
                $limit = (int)($this->params[count($this->params) - 2] ?? count($rows));
                $offset = (int)($this->params[count($this->params) - 1] ?? 0);
                $slice = array_slice($rows, $offset, $limit);
                $this->resultRows = $this->conn->formatRows($slice);
                return true;
            }
            if (preg_match('/LIMIT \?/i', $sql) && strpos($upper, 'OFFSET') === false) {
                $tenantId = (int)($this->params[0] ?? 0);
                $userId = (int)($this->params[1] ?? 0);
                $limit = (int)($this->params[2] ?? 10);
                $rows = $this->conn->notificationsFor($tenantId, $userId, function (array $row): bool {
                    return !$row['is_archived'];
                });
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                $this->resultRows = $this->conn->formatRows(array_slice($rows, 0, $limit));
                return true;
            }
            if (preg_match('/WHERE\s+TENANT_ID\s*=\s*\?\s+AND\s+ID\s*=\s*\?/i', $sql)) {
                $tenantId = (int)($this->params[0] ?? 0);
                $id = (int)($this->params[1] ?? 0);
                $userId = isset($this->params[2]) ? (int)$this->params[2] : null;
                $row = $this->conn->getNotification($tenantId, $id);
                if ($row && ($userId === null || $row['user_id'] === $userId)) {
                    $this->resultRows[] = $this->conn->formatRow($row);
                }
                return true;
            }
        }

        if (strpos($upper, 'SELECT TYPE FROM NOTIFICATIONS') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $userId = (int)($this->params[1] ?? 0);
            $rows = $this->conn->notificationsFor($tenantId, $userId, function (array $row): bool {
                return !$row['is_archived'] && !$row['is_read'];
            });
            foreach ($rows as $row) {
                $this->resultRows[] = ['type' => $row['type']];
            }
            return true;
        }

        if (strpos($upper, 'UPDATE NOTIFICATIONS SET TITLE') === 0) {
            $tenantId = (int)($this->params[7] ?? 0);
            $userId = (int)($this->params[8] ?? 0);
            $id = (int)($this->params[9] ?? 0);
            $metadata = json_decode((string)($this->params[5] ?? '[]'), true) ?: [];
            $actions = json_decode((string)($this->params[6] ?? '[]'), true) ?: [];
            $this->affectedRowsValue = $this->conn->updateNotification($tenantId, $userId, $id, [
                'title' => (string)($this->params[0] ?? ''),
                'body' => (string)($this->params[1] ?? ''),
                'type' => (string)($this->params[2] ?? 'info'),
                'url' => $this->params[3] !== null && $this->params[3] !== '' ? (string)$this->params[3] : null,
                'priority' => (int)($this->params[4] ?? 0),
                'metadata' => $metadata,
                'actions' => $actions,
            ]);
            return true;
        }

        if (strpos($upper, 'UPDATE NOTIFICATIONS SET IS_READ') === 0) {
            $tenantId = (int)($this->params[2] ?? 0);
            $userId = (int)($this->params[3] ?? 0);
            $id = (int)($this->params[4] ?? 0);
            $this->affectedRowsValue = $this->conn->updateNotification($tenantId, $userId, $id, [
                'is_read' => (int)($this->params[0] ?? 0) === 1,
                'read_at' => $this->params[1] !== null ? (string)$this->params[1] : null,
            ]);
            return true;
        }

        if (strpos($upper, 'UPDATE NOTIFICATIONS SET IS_ARCHIVED') === 0) {
            if (strpos($upper, 'ARCHIVED_AT = CURRENT_TIMESTAMP') !== false) {
                $tenantId = (int)($this->params[0] ?? 0);
                $userId = (int)($this->params[1] ?? 0);
                $id = (int)($this->params[2] ?? 0);
                $this->affectedRowsValue = $this->conn->updateNotification($tenantId, $userId, $id, [
                    'is_archived' => true,
                    'archived_at' => $this->conn->now(),
                ]);
                return true;
            }
            $tenantId = (int)($this->params[2] ?? 0);
            $userId = (int)($this->params[3] ?? 0);
            $id = (int)($this->params[4] ?? 0);
            $archived = (int)($this->params[0] ?? 0) === 1;
            $archivedAt = $this->params[1] !== null ? (string)$this->params[1] : null;
            $this->affectedRowsValue = $this->conn->updateNotification($tenantId, $userId, $id, [
                'is_archived' => $archived,
                'archived_at' => $archivedAt,
            ]);
            return true;
        }

        if (strpos($upper, 'UPDATE NOTIFICATIONS SET METADATA') === 0) {
            $tenantId = (int)($this->params[1] ?? 0);
            $userId = (int)($this->params[2] ?? 0);
            $id = (int)($this->params[3] ?? 0);
            $metadata = json_decode((string)($this->params[0] ?? '[]'), true) ?: [];
            $this->affectedRowsValue = $this->conn->updateNotification($tenantId, $userId, $id, [
                'metadata' => $metadata,
            ]);
            return true;
        }

        if (strpos($upper, 'UPDATE NOTIFICATIONS SET IS_READ = 1') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $userId = (int)($this->params[1] ?? 0);
            $this->affectedRowsValue = $this->conn->markAllRead($tenantId, $userId);
            return true;
        }

        if (strpos($upper, 'DELETE FROM NOTIFICATIONS') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $userId = (int)($this->params[1] ?? 0);
            $id = (int)($this->params[2] ?? 0);
            $this->affectedRowsValue = $this->conn->deleteNotification($tenantId, $userId, $id);
            return true;
        }

        if (strpos($upper, 'SELECT ID FROM USERS WHERE TENANT_ID = ? AND ID = ?') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $id = (int)($this->params[1] ?? 0);
            if (isset($this->conn->users[$id]) && $this->conn->users[$id]['tenant_id'] === $tenantId) {
                $this->resultRows[] = ['id' => $id];
            }
            return true;
        }

        if (strpos($upper, 'SELECT ID FROM USERS WHERE TENANT_ID = ? AND ROLE = ?') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            $role = (string)($this->params[1] ?? '');
            foreach ($this->conn->users as $user) {
                if ($user['tenant_id'] === $tenantId && $user['role'] === $role) {
                    $this->resultRows[] = ['id' => $user['id']];
                }
            }
            return true;
        }

        if (strpos($upper, 'SELECT ID FROM USERS WHERE TENANT_ID = ?') === 0) {
            $tenantId = (int)($this->params[0] ?? 0);
            foreach ($this->conn->users as $user) {
                if ($user['tenant_id'] === $tenantId) {
                    $this->resultRows[] = ['id' => $user['id']];
                }
            }
            return true;
        }

        return true;
    }

    #[\ReturnTypeWillChange]
    public function get_result(): PPFNotificationsFakeResult
    {
        return new PPFNotificationsFakeResult($this->resultRows);
    }

    public function close(): true
    {
        $this->resultRows = [];
        return true;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'affected_rows') {
            return $this->affectedRowsValue;
        }
        if ($name === 'insert_id') {
            return $this->insertIdValue;
        }
        return null;
    }

    public function ppfFakeAffectedRows(): int
    {
        return (int)$this->affectedRowsValue;
    }

    public function ppfFakeInsertId(): int
    {
        return (int)$this->insertIdValue;
    }
}

class PPFNotificationsFakeMysqli extends mysqli
{
    public bool $ppfFakeNotificationsDriver = true;
    /** @var array<int, array<string, mixed>> */
    public array $notifications = [];
    /** @var array<int, array<int, array{delivery_prefs: array, types_muted: array}>> */
    private array $settings = [];
    /** @var array<int, array<string, mixed>> */
    public array $events = [];
    /** @var array<int, array{tenant_id:int, role:string, id:int}> */
    public array $users = [];
    private int $nextNotificationId = 1;
    private array $queryContext = [];

    public function __construct()
    {
    }

    public function prepare(string $query): PPFNotificationsFakeStmt|false
    {
        return new PPFNotificationsFakeStmt($this, $query);
    }

    public function setQueryContext(array $context): void
    {
        $this->queryContext = $context;
    }

    public function getQueryContext(): array
    {
        return $this->queryContext;
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function getSettings(int $tenantId, int $userId): ?array
    {
        return $this->settings[$tenantId][$userId] ?? null;
    }

    public function setSettings(int $tenantId, int $userId, array $settings): void
    {
        $this->settings[$tenantId][$userId] = $settings;
    }

    public function logEvent(array $event): void
    {
        $event['id'] = count($this->events) + 1;
        $event['created_at'] = $this->now();
        $this->events[] = $event;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertNotification(int $tenantId, int $userId, array $data): int
    {
        $id = $this->nextNotificationId++;
        $now = $this->now();
        $record = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => (string)$data['title'],
            'body' => (string)$data['body'],
            'type' => (string)$data['type'],
            'url' => $data['url'] ?? null,
            'priority' => (int)($data['priority'] ?? 0),
            'is_read' => false,
            'read_at' => null,
            'is_archived' => false,
            'archived_at' => null,
            'metadata' => $data['metadata'] ?? [],
            'actions' => $data['actions'] ?? [],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->notifications[$id] = $record;
        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function notificationsFor(int $tenantId, int $userId, ?callable $filter = null): array
    {
        $out = [];
        foreach ($this->notifications as $row) {
            if ($row['tenant_id'] !== $tenantId || $row['user_id'] !== $userId) {
                continue;
            }
            if ($filter && !$filter($row)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function getNotification(int $tenantId, int $id): ?array
    {
        $row = $this->notifications[$id] ?? null;
        if (!$row || $row['tenant_id'] !== $tenantId) {
            return null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function updateNotification(int $tenantId, int $userId, int $id, array $changes): int
    {
        if (!isset($this->notifications[$id])) {
            return 0;
        }
        $row = $this->notifications[$id];
        if ($row['tenant_id'] !== $tenantId || $row['user_id'] !== $userId) {
            return 0;
        }
        foreach ($changes as $key => $value) {
            if ($key === 'metadata' || $key === 'actions') {
                $row[$key] = $value ?? [];
            } elseif ($key === 'is_read') {
                $row['is_read'] = (bool)$value;
                if ($value && $row['read_at'] === null) {
                    $row['read_at'] = $this->now();
                }
            } elseif ($key === 'read_at') {
                $row['read_at'] = $value;
            } elseif ($key === 'is_archived') {
                $row['is_archived'] = (bool)$value;
            } elseif ($key === 'archived_at') {
                $row['archived_at'] = $value;
            } else {
                $row[$key] = $value;
            }
        }
        $row['updated_at'] = $this->now();
        $this->notifications[$id] = $row;
        return 1;
    }

    public function markAllRead(int $tenantId, int $userId): int
    {
        $count = 0;
        foreach ($this->notifications as $id => $row) {
            if ($row['tenant_id'] === $tenantId && $row['user_id'] === $userId && !$row['is_archived']) {
                if (!$row['is_read']) {
                    $count++;
                }
                $row['is_read'] = true;
                if ($row['read_at'] === null) {
                    $row['read_at'] = $this->now();
                }
                $row['updated_at'] = $this->now();
                $this->notifications[$id] = $row;
            }
        }
        return $count;
    }

    public function deleteNotification(int $tenantId, int $userId, int $id): int
    {
        if (!isset($this->notifications[$id])) {
            return 0;
        }
        $row = $this->notifications[$id];
        if ($row['tenant_id'] !== $tenantId || $row['user_id'] !== $userId) {
            return 0;
        }
        unset($this->notifications[$id]);
        return 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function applyFilters(array $context): array
    {
        $tenantId = (int)($context['tenant_id'] ?? 0);
        $userId = (int)($context['user_id'] ?? 0);
        $filters = $context['filters'] ?? [];
        $rows = $this->notificationsFor($tenantId, $userId);

        $status = $filters['status'] ?? null;
        if ($status === 'read') {
            $rows = array_values(array_filter($rows, fn($row) => $row['is_read'] && !$row['is_archived']));
        } elseif ($status === 'unread') {
            $rows = array_values(array_filter($rows, fn($row) => !$row['is_read'] && !$row['is_archived'])) ;
        } elseif ($status === 'archived') {
            $rows = array_values(array_filter($rows, fn($row) => $row['is_archived']));
        } else {
            $rows = array_values(array_filter($rows, fn($row) => !$row['is_archived']));
        }

        if (!empty($filters['type']) && isset(ppf_notifications_types()[$filters['type']])) {
            $type = (string)$filters['type'];
            $rows = array_values(array_filter($rows, fn($row) => $row['type'] === $type));
        }

        if ($filters['priority'] !== '' && $filters['priority'] !== null) {
            $priority = (int)$filters['priority'];
            $rows = array_values(array_filter($rows, fn($row) => (int)$row['priority'] === $priority));
        }

        if (!empty($filters['date_from'])) {
            $fromTs = strtotime((string)$filters['date_from']);
            if ($fromTs !== false) {
                $rows = array_values(array_filter($rows, fn($row) => strtotime($row['created_at']) >= $fromTs));
            }
        }

        if (!empty($filters['date_to'])) {
            $toTs = strtotime((string)$filters['date_to'] . ' 23:59:59');
            if ($toTs !== false) {
                $rows = array_values(array_filter($rows, fn($row) => strtotime($row['created_at']) <= $toTs));
            }
        }

        if (!empty($filters['q'])) {
            $needle = strtolower((string)$filters['q']);
            $rows = array_values(array_filter($rows, function ($row) use ($needle) {
                return strpos(strtolower((string)$row['title']), $needle) !== false
                    || strpos(strtolower((string)$row['body']), $needle) !== false;
            }));
        }

        if (!empty($filters['actor'])) {
            if ($filters['actor'] === 'system') {
                $rows = array_values(array_filter($rows, function ($row) {
                    $actor = $row['metadata']['actor'] ?? 'system';
                    return $actor === 'system' || $actor === '';
                }));
            } elseif ($filters['actor'] === 'user') {
                $rows = array_values(array_filter($rows, function ($row) {
                    $actor = $row['metadata']['actor'] ?? 'system';
                    return $actor === 'user';
                }));
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function sortNotifications(array $rows, string $sort): array
    {
        [$col, $dir] = array_pad(explode(':', $sort, 2), 2, 'desc');
        $col = in_array($col, ['created_at', 'priority', 'type', 'read_at'], true) ? $col : 'created_at';
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        usort($rows, function ($a, $b) use ($col, $dir) {
            $va = $a[$col] ?? null;
            $vb = $b[$col] ?? null;
            if ($col === 'priority') {
                $va = (int)$va;
                $vb = (int)$vb;
            } else {
                $va = (string)$va;
                $vb = (string)$vb;
            }
            $cmp = $va <=> $vb;
            return $dir === 'asc' ? $cmp : -$cmp;
        });
        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function formatRows(array $rows): array
    {
        return array_map(fn($row) => $this->formatRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function formatRow(array $row): array
    {
        $copy = $row;
        $copy['is_read'] = $row['is_read'] ? 1 : 0;
        $copy['is_archived'] = $row['is_archived'] ? 1 : 0;
        $copy['metadata'] = json_encode($row['metadata'] ?? []);
        $copy['actions'] = json_encode($row['actions'] ?? []);
        return $copy;
    }
}
