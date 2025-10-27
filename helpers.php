<?php
// helpers.php — shared helpers for PeterPangFit

/* -------------------- General safe helpers -------------------- */
if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fmt_when')) {
  function fmt_when(?string $ts): string {
    return $ts ? date('Y-m-d H:i:s', strtotime($ts)) : '';
  }
}

if (!function_exists('avatar_src')) {
  function avatar_src(?string $val): string {
    if (!$val) return '';
    if (preg_match('#^https?://#i', $val)) return $val;     // full URL
    if ($val[0] === '/') return $val;                       // absolute path
    if (stripos($val, 'uploads/') === 0) return '/'.$val;   // relative /uploads/...
    return '/uploads/avatars/' . ltrim($val, '/');          // bare filename
  }
}

/* -------------------- File/perm utilities -------------------- */
if (!function_exists('ppf_fix_permissions')) {
  /**
   * Ensure reasonable permissions for uploads.
   * - Windows (IIS): grant Administrators:(F), IIS_IUSRS:(M), enable inheritance.
   * - *nix:          chmod 0755 for dirs, 0644 for files.
   */
  function ppf_fix_permissions(string $path, bool $isDir): void {
    $canExec = function_exists('exec');
    if (PHP_OS_FAMILY === 'Windows') {
      if (!$canExec) return;
      $p = str_replace('/', DIRECTORY_SEPARATOR, $path);
      @exec('icacls "' . $p . '" /inheritance:e');
      @exec('icacls "' . $p . '" /grant Administrators:(F)');
      @exec('icacls "' . $p . '" /grant IIS_IUSRS:' . ($isDir ? '(OI)(CI)(M)' : '(M)'));
      // If you use a custom App Pool identity, uncomment + set:
      // $appPool = getenv('APP_POOL_ID') ?: 'DefaultAppPool';
      // @exec('icacls "' . $p . '" /grant "IIS AppPool\\'.$appPool.'":' . ($isDir ? '(OI)(CI)(M)' : '(M)'));
    } else {
      if ($isDir) { @chmod($path, 0755); } else { @chmod($path, 0644); }
    }
  }
}

/* -------------------- MEMOIZED schema probes -------------------- */
/* Single source of truth. Do not duplicate these anywhere else. */

if (!function_exists('table_exists')) {
  function table_exists(mysqli $conn, string $table): bool {
    static $cache = [];
    $k = strtolower($table);
    if (isset($cache[$k])) return $cache[$k];
    try {
      $sql = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
      if (!$stmt = $conn->prepare($sql)) return $cache[$k] = false;
      $stmt->bind_param("s", $table);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      return $cache[$k] = ((int)($row['c'] ?? 0) > 0);
    } catch (Throwable $e) {
      return $cache[$k] = false;
    }
  }
}

if (!function_exists('column_exists')) {
  function column_exists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $k = strtolower($table).':'.strtolower($column);
    if (isset($cache[$k])) return $cache[$k];
    try {
      $sql = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
      if (!$stmt = $conn->prepare($sql)) return $cache[$k] = false;
      $stmt->bind_param("ss", $table, $column);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      return $cache[$k] = ((int)($row['c'] ?? 0) > 0);
    } catch (Throwable $e) {
      return $cache[$k] = false;
    }
  }
}

/* -------------------- Time + formatting helpers -------------------- */

if (!function_exists('ppf_time_normalize_timezone')) {
  function ppf_time_normalize_timezone(?string $tz): ?string {
    if ($tz === null) return null;
    $tz = trim((string)$tz);
    if ($tz === '') return null;
    static $cache = [];
    if (array_key_exists($tz, $cache)) {
      return $cache[$tz];
    }
    try {
      $zone = new DateTimeZone($tz);
      return $cache[$tz] = $zone->getName();
    } catch (Throwable $e) {
      return $cache[$tz] = null;
    }
  }
}

if (!function_exists('ppf_time_default_timezone')) {
  function ppf_time_default_timezone(): string {
    static $resolved = null;
    if ($resolved !== null) {
      return $resolved;
    }
    $iniTz = trim((string)ini_get('date.timezone'));
    if ($iniTz !== '') {
      $norm = ppf_time_normalize_timezone($iniTz);
      if ($norm !== null) {
        return $resolved = $norm;
      }
    }
    return $resolved = 'UTC';
  }
}

if (!function_exists('ppf_time_ensure_columns')) {
  function ppf_time_ensure_columns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
      return;
    }
    $checked = true;
    try {
      if (!column_exists($conn, 'users', 'timezone')) {
        @$conn->query("ALTER TABLE users ADD COLUMN timezone VARCHAR(64) NULL DEFAULT NULL");
      }
      if (!column_exists($conn, 'users', 'time_format_24h')) {
        @$conn->query("ALTER TABLE users ADD COLUMN time_format_24h TINYINT(1) NOT NULL DEFAULT 0");
      }
    } catch (Throwable $e) {
      // Non-fatal; schema may be managed separately.
    }
  }
}

if (!function_exists('ppf_measurement_default_system')) {
  function ppf_measurement_default_system(): string {
    return 'imperial';
  }
}

if (!function_exists('ppf_measurement_normalize_system')) {
  function ppf_measurement_normalize_system($value): ?string {
    $raw = strtolower(trim((string)$value));
    if ($raw === '') {
      return null;
    }
    if (in_array($raw, ['imperial', 'sae', 'standard', 'us'], true)) {
      return 'imperial';
    }
    if (in_array($raw, ['metric', 'si'], true)) {
      return 'metric';
    }
    return null;
  }
}

if (!function_exists('ppf_measurement_ensure_columns')) {
  function ppf_measurement_ensure_columns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
      return;
    }
    $checked = true;
    try {
      if (!column_exists($conn, 'users', 'measurement_system')) {
        @$conn->query("ALTER TABLE users ADD COLUMN measurement_system VARCHAR(16) NULL DEFAULT NULL");
      }
    } catch (Throwable $e) {
      // Non-fatal; schema may be managed externally.
    }
  }
}

if (!function_exists('ppf_measurement_set_session')) {
  function ppf_measurement_set_session(string $system): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      return;
    }
    $normalized = ppf_measurement_normalize_system($system) ?? ppf_measurement_default_system();
    $_SESSION['user_measurement_system'] = $normalized;
    $_SESSION['measurement_system'] = $normalized;
  }
}

/* -------------------- Notification helpers -------------------- */

if (!function_exists('ppf_notification_categories')) {
  function ppf_notification_categories(): array {
    return [
      'security' => [
        'label' => 'Security',
        'description' => 'Account safety and access alerts',
      ],
      'workouts' => [
        'label' => 'Workouts',
        'description' => 'Workout assignments, updates, and coaching notes',
      ],
      'billing' => [
        'label' => 'Billing',
        'description' => 'Purchases, refunds, and payment activity',
      ],
      'system' => [
        'label' => 'System',
        'description' => 'Product announcements and global notices',
      ],
      'custom' => [
        'label' => 'Custom',
        'description' => 'Reminders you create for yourself',
      ],
    ];
  }
}

if (!function_exists('ppf_notifications_catalog')) {
  function ppf_notifications_catalog(): array {
    static $catalog = null;
    if ($catalog !== null) {
      return $catalog;
    }
    $catalog = [
      'security.password_changed' => [
        'category' => 'security',
        'title' => 'Password changed',
        'message' => 'Your account password was updated successfully.',
        'is_mutable' => false,
        'allow_email' => false,
        'send_email_default' => true,
        'email_subject' => 'Your Peter Pang Fit password was changed',
        'email_body' => "Your password was just changed. If this was not you, please secure your account immediately.",
      ],
      'security.passkey_added' => [
        'category' => 'security',
        'title' => 'New passkey added',
        'message' => 'A new passkey was registered on your account.',
        'is_mutable' => false,
        'allow_email' => false,
        'send_email_default' => true,
      ],
      'security.passkey_removed' => [
        'category' => 'security',
        'title' => 'Passkey removed',
        'message' => 'One of your passkeys was removed.',
        'is_mutable' => false,
        'allow_email' => false,
        'send_email_default' => true,
      ],
      'security.passkey_renamed' => [
        'category' => 'security',
        'title' => 'Passkey renamed',
        'message' => 'One of your passkeys was renamed.',
        'is_mutable' => false,
        'allow_email' => false,
      ],
      'security.account_locked' => [
        'category' => 'security',
        'title' => 'Account locked',
        'message' => 'Your account was locked after too many unsuccessful sign-in attempts.',
        'is_mutable' => false,
        'allow_email' => false,
        'send_email_default' => true,
      ],
      'billing.sessions_purchased' => [
        'category' => 'billing',
        'title' => 'Sessions purchased',
        'message' => 'New training sessions were added to your account.',
        'is_mutable' => false,
        'allow_email' => true,
        'send_email_default' => false,
      ],
      'billing.payment_recorded' => [
        'category' => 'billing',
        'title' => 'Payment recorded',
        'message' => 'A payment was recorded on your account.',
        'is_mutable' => false,
        'allow_email' => true,
        'send_email_default' => false,
      ],
      'billing.refund_recorded' => [
        'category' => 'billing',
        'title' => 'Refund processed',
        'message' => 'A refund was processed for one of your sessions.',
        'is_mutable' => false,
        'allow_email' => true,
        'send_email_default' => true,
      ],
      'workouts.plan_assigned' => [
        'category' => 'workouts',
        'title' => 'New workout plan assigned',
        'message' => 'A trainer assigned a new workout plan to you.',
        'is_mutable' => false,
        'allow_email' => true,
        'send_email_default' => false,
      ],
    ];
    return $catalog;
  }
}

if (!function_exists('ppf_notifications_valid_category')) {
  function ppf_notifications_valid_category(string $category): string {
    $category = strtolower(trim($category));
    $map = ppf_notification_categories();
    return array_key_exists($category, $map) ? $category : 'system';
  }
}

if (!function_exists('ppf_notifications_ensure_schema')) {
  function ppf_notifications_ensure_schema(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
      return;
    }
    $ensured = true;
    try {
      $ddl = "CREATE TABLE IF NOT EXISTS user_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type_key VARCHAR(100) NOT NULL DEFAULT '',
        category VARCHAR(40) NOT NULL DEFAULT 'system',
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_mutable TINYINT(1) NOT NULL DEFAULT 1,
        allow_email TINYINT(1) NOT NULL DEFAULT 1,
        send_email TINYINT(1) NOT NULL DEFAULT 0,
        email_sent_at DATETIME NULL DEFAULT NULL,
        context TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user_read (user_id, is_read),
        KEY idx_user_created (user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
      @$conn->query($ddl);

      $columns = [
        'type_key' => "ALTER TABLE user_notifications ADD COLUMN type_key VARCHAR(100) NOT NULL DEFAULT '' AFTER user_id",
        'category' => "ALTER TABLE user_notifications ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'system' AFTER type_key",
        'title' => "ALTER TABLE user_notifications ADD COLUMN title VARCHAR(255) NOT NULL AFTER category",
        'message' => "ALTER TABLE user_notifications ADD COLUMN message TEXT NULL AFTER title",
        'is_read' => "ALTER TABLE user_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER message",
        'is_mutable' => "ALTER TABLE user_notifications ADD COLUMN is_mutable TINYINT(1) NOT NULL DEFAULT 1 AFTER is_read",
        'allow_email' => "ALTER TABLE user_notifications ADD COLUMN allow_email TINYINT(1) NOT NULL DEFAULT 1 AFTER is_mutable",
        'send_email' => "ALTER TABLE user_notifications ADD COLUMN send_email TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_email",
        'email_sent_at' => "ALTER TABLE user_notifications ADD COLUMN email_sent_at DATETIME NULL DEFAULT NULL AFTER send_email",
        'context' => "ALTER TABLE user_notifications ADD COLUMN context TEXT NULL AFTER email_sent_at",
        'created_at' => "ALTER TABLE user_notifications ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER context",
        'updated_at' => "ALTER TABLE user_notifications ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
      ];
      foreach ($columns as $col => $sql) {
        if (!column_exists($conn, 'user_notifications', $col)) {
          @$conn->query($sql);
        }
      }
    } catch (Throwable $e) {
      // Non-fatal; schema may be managed externally.
    }
  }
}

if (!function_exists('ppf_notifications_fetch_recent')) {
  function ppf_notifications_fetch_recent(mysqli $conn, int $userId, int $limit = 10): array {
    $out = ['items' => [], 'unread' => 0];
    if ($userId <= 0) {
      return $out;
    }
    ppf_notifications_ensure_schema($conn);
    try {
      if ($stmt = $conn->prepare("SELECT id, title, message, category, is_read, send_email, created_at, type_key FROM user_notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?")) {
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
          $row['id'] = (int)$row['id'];
          $row['is_read'] = (int)($row['is_read'] ?? 0);
          $out['items'][] = $row;
          if ($row['is_read'] === 0) {
            $out['unread']++;
          }
        }
        $stmt->close();
      }
    } catch (Throwable $e) {
      // ignore
    }
    if ($out['unread'] === 0) {
      try {
        if ($stmt = $conn->prepare('SELECT COUNT(*) AS c FROM user_notifications WHERE user_id=? AND is_read=0')) {
          $stmt->bind_param('i', $userId);
          $stmt->execute();
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $out['unread'] = (int)($row['c'] ?? 0);
          }
          $stmt->close();
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
    return $out;
  }
}

if (!function_exists('ppf_notifications_fetch_all')) {
  function ppf_notifications_fetch_all(mysqli $conn, int $userId): array {
    $grouped = [];
    if ($userId <= 0) {
      return $grouped;
    }
    ppf_notifications_ensure_schema($conn);
    $cats = ppf_notification_categories();
    foreach ($cats as $key => $_) {
      $grouped[$key] = [];
    }
    try {
      if ($stmt = $conn->prepare("SELECT id, type_key, category, title, message, is_read, is_mutable, allow_email, send_email, email_sent_at, created_at, updated_at FROM user_notifications WHERE user_id=? ORDER BY created_at DESC")) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
          $cat = ppf_notifications_valid_category((string)($row['category'] ?? ''));
          $row['id'] = (int)$row['id'];
          $row['is_read'] = (int)($row['is_read'] ?? 0);
          $row['is_mutable'] = (int)($row['is_mutable'] ?? 0);
          $row['allow_email'] = (int)($row['allow_email'] ?? 0);
          $row['send_email'] = (int)($row['send_email'] ?? 0);
          $grouped[$cat][] = $row;
        }
        $stmt->close();
      }
    } catch (Throwable $e) {
      // ignore fetch issues
    }
    return $grouped;
  }
}

if (!function_exists('ppf_notifications_load_one')) {
  function ppf_notifications_load_one(mysqli $conn, int $userId, int $notificationId): ?array {
    if ($userId <= 0 || $notificationId <= 0) {
      return null;
    }
    ppf_notifications_ensure_schema($conn);
    try {
      if ($stmt = $conn->prepare("SELECT id, type_key, category, title, message, is_read, is_mutable, allow_email, send_email, email_sent_at, context, created_at, updated_at FROM user_notifications WHERE user_id=? AND id=? LIMIT 1")) {
        $stmt->bind_param('ii', $userId, $notificationId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
          $row['id'] = (int)$row['id'];
          $row['is_read'] = (int)($row['is_read'] ?? 0);
          $row['is_mutable'] = (int)($row['is_mutable'] ?? 0);
          $row['allow_email'] = (int)($row['allow_email'] ?? 0);
          $row['send_email'] = (int)($row['send_email'] ?? 0);
          return $row;
        }
      }
    } catch (Throwable $e) {
      // ignore
    }
    return null;
  }
}

if (!function_exists('ppf_notifications_delete')) {
  function ppf_notifications_delete(mysqli $conn, int $userId, int $notificationId): bool {
    if ($userId <= 0 || $notificationId <= 0) {
      return false;
    }
    ppf_notifications_ensure_schema($conn);
    try {
      if ($stmt = $conn->prepare('DELETE FROM user_notifications WHERE user_id=? AND id=? AND is_mutable=1')) {
        $stmt->bind_param('ii', $userId, $notificationId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
      }
    } catch (Throwable $e) {
      // ignore
    }
    return false;
  }
}

if (!function_exists('ppf_notifications_set_read')) {
  function ppf_notifications_set_read(mysqli $conn, int $userId, int $notificationId, bool $read): bool {
    if ($userId <= 0 || $notificationId <= 0) {
      return false;
    }
    ppf_notifications_ensure_schema($conn);
    try {
      if ($stmt = $conn->prepare('UPDATE user_notifications SET is_read=? WHERE user_id=? AND id=?')) {
        $flag = $read ? 1 : 0;
        $stmt->bind_param('iii', $flag, $userId, $notificationId);
        $stmt->execute();
        $ok = $stmt->affected_rows >= 0;
        $stmt->close();
        return $ok;
      }
    } catch (Throwable $e) {
      // ignore
    }
    return false;
  }
}

if (!function_exists('ppf_notifications_mark_all_read')) {
  function ppf_notifications_mark_all_read(mysqli $conn, int $userId): bool {
    if ($userId <= 0) {
      return false;
    }
    ppf_notifications_ensure_schema($conn);
    try {
      if ($stmt = $conn->prepare('UPDATE user_notifications SET is_read=1 WHERE user_id=? AND is_read=0')) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        return true;
      }
    } catch (Throwable $e) {
      // ignore
    }
    return false;
  }
}

if (!function_exists('ppf_notifications_upsert')) {
  function ppf_notifications_upsert(mysqli $conn, int $userId, array $data, ?int $notificationId = null): ?int {
    if ($userId <= 0) {
      return null;
    }
    ppf_notifications_ensure_schema($conn);
    $title = trim((string)($data['title'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));
    if ($title === '') {
      $title = 'Notification';
    }
    $category = ppf_notifications_valid_category((string)($data['category'] ?? 'system'));
    $sendEmail = !empty($data['send_email']);
    $allowEmail = isset($data['allow_email']) ? (bool)$data['allow_email'] : true;
    $context = isset($data['context']) ? (string)$data['context'] : null;
    $typeKey = strtolower(trim((string)($data['type_key'] ?? '')));
    if ($notificationId !== null) {
      $existing = ppf_notifications_load_one($conn, $userId, $notificationId);
      if (!$existing || (int)$existing['is_mutable'] !== 1) {
        return null;
      }
      $sendEmailChanged = $sendEmail && (int)($existing['send_email'] ?? 0) !== 1;
      try {
        if ($stmt = $conn->prepare('UPDATE user_notifications SET title=?, message=?, category=?, send_email=?, allow_email=?, context=?, updated_at=NOW() WHERE user_id=? AND id=? AND is_mutable=1')) {
          $send = $sendEmail ? 1 : 0;
          $allow = $allowEmail ? 1 : 0;
          $ctx = $context !== null && $context !== '' ? $context : null;
          $stmt->bind_param('sssiisii', $title, $message, $category, $send, $allow, $ctx, $userId, $notificationId);
          $stmt->execute();
          $stmt->close();
        }
      } catch (Throwable $e) {
        return null;
      }
      if ($sendEmailChanged) {
        ppf_notifications_send_email_if_requested($conn, $userId, $notificationId, [
          'title' => $title,
          'message' => $message,
          'email_subject' => $data['email_subject'] ?? null,
          'email_body' => $data['email_body'] ?? null,
        ]);
      }
      return $notificationId;
    }

    try {
      if ($stmt = $conn->prepare('INSERT INTO user_notifications (user_id, type_key, category, title, message, is_read, is_mutable, allow_email, send_email, context, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?, ?, NOW(), NOW())')) {
        $type = $typeKey;
        $allow = $allowEmail ? 1 : 0;
        $send = $sendEmail ? 1 : 0;
        $ctx = $context !== null && $context !== '' ? $context : null;
        $stmt->bind_param('issssiis', $userId, $type, $category, $title, $message, $allow, $send, $ctx);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        if ($sendEmail) {
          ppf_notifications_send_email_if_requested($conn, $userId, $id, [
            'title' => $title,
            'message' => $message,
            'email_subject' => $data['email_subject'] ?? null,
            'email_body' => $data['email_body'] ?? null,
          ]);
        }
        return $id;
      }
    } catch (Throwable $e) {
      return null;
    }
    return null;
  }
}

if (!function_exists('ppf_notifications_send_email_if_requested')) {
  function ppf_notifications_send_email_if_requested(mysqli $conn, int $userId, int $notificationId, array $meta): void {
    try {
      if ($stmt = $conn->prepare('SELECT send_email, email_sent_at FROM user_notifications WHERE id=? AND user_id=? LIMIT 1')) {
        $stmt->bind_param('ii', $notificationId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row || (int)($row['send_email'] ?? 0) !== 1 || !empty($row['email_sent_at'])) {
          return;
        }
      } else {
        return;
      }
    } catch (Throwable $e) {
      return;
    }

    try {
      if ($stmt = $conn->prepare('SELECT email, first_name, last_name FROM users WHERE id=? LIMIT 1')) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();
      } else {
        $user = null;
      }
    } catch (Throwable $e) {
      $user = null;
    }

    if (!$user || empty($user['email'])) {
      return;
    }

    $toEmail = (string)$user['email'];
    $toName = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
    if ($toName === '') {
      $toName = $toEmail;
    }

    $subject = trim((string)($meta['email_subject'] ?? $meta['title'] ?? 'Notification Update'));
    $body = trim((string)($meta['email_body'] ?? $meta['message'] ?? 'You have a new notification.'));

    if (!function_exists('send_plain_email')) {
      $sendPath = __DIR__ . '/send_email.php';
      if (file_exists($sendPath)) {
        require_once $sendPath;
      }
    }
    $sent = false;
    try {
      if (function_exists('send_plain_email')) {
        $sent = @send_plain_email($toEmail, $toName, $subject, $body);
      }
    } catch (Throwable $e) {
      $sent = false;
    }

    if ($sent) {
      try {
        if ($stmt = $conn->prepare('UPDATE user_notifications SET email_sent_at=NOW() WHERE id=? AND user_id=?')) {
          $stmt->bind_param('ii', $notificationId, $userId);
          $stmt->execute();
          $stmt->close();
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
  }
}

if (!function_exists('ppf_notifications_record')) {
  function ppf_notifications_record(mysqli $conn, int $userId, array $data): ?int {
    if ($userId <= 0) {
      return null;
    }
    ppf_notifications_ensure_schema($conn);
    $catalog = ppf_notifications_catalog();
    $typeKey = strtolower(trim((string)($data['type'] ?? ($data['type_key'] ?? ''))));
    $base = $typeKey !== '' && isset($catalog[$typeKey]) ? $catalog[$typeKey] : [];
    $category = ppf_notifications_valid_category((string)($data['category'] ?? ($base['category'] ?? 'system')));
    $title = trim((string)($data['title'] ?? ($base['title'] ?? 'Notification')));
    $message = trim((string)($data['message'] ?? $data['body'] ?? ($base['message'] ?? '')));
    $context = isset($data['context']) ? (string)$data['context'] : null;
    $isMutable = isset($data['is_mutable']) ? (bool)$data['is_mutable'] : (bool)($base['is_mutable'] ?? false);
    $allowEmail = isset($data['allow_email']) ? (bool)$data['allow_email'] : (bool)($base['allow_email'] ?? true);
    $sendEmail = isset($data['send_email']) ? (bool)$data['send_email'] : (bool)($base['send_email_default'] ?? true);
    $markRead = isset($data['is_read']) ? (bool)$data['is_read'] : false;
    if ($title === '') {
      $title = 'Notification';
    }

    $isRead = $markRead ? 1 : 0;
    try {
      if ($stmt = $conn->prepare('INSERT INTO user_notifications (user_id, type_key, category, title, message, is_read, is_mutable, allow_email, send_email, context, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')) {
        $type = $typeKey;
        $mutable = $isMutable ? 1 : 0;
        $allow = $allowEmail ? 1 : 0;
        $send = $sendEmail ? 1 : 0;
        $ctx = $context !== null && $context !== '' ? $context : null;
        $stmt->bind_param('issssiiiis', $userId, $type, $category, $title, $message, $isRead, $mutable, $allow, $send, $ctx);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
      } else {
        return null;
      }
    } catch (Throwable $e) {
      return null;
    }

    $id = $id ?? null;
    if ($id !== null && $sendEmail) {
      $subject = $data['email_subject'] ?? ($base['email_subject'] ?? $title);
      $body = $data['email_body'] ?? ($base['email_body'] ?? $message);
      ppf_notifications_send_email_if_requested($conn, $userId, $id, [
        'title' => $title,
        'message' => $message,
        'email_subject' => $subject,
        'email_body' => $body,
      ]);
    }
    return $id;
  }
}

if (!function_exists('ppf_notifications_toggle_email')) {
  function ppf_notifications_toggle_email(mysqli $conn, int $userId, int $notificationId, bool $sendEmail): bool {
    if ($userId <= 0 || $notificationId <= 0) {
      return false;
    }
    ppf_notifications_ensure_schema($conn);
    $existing = ppf_notifications_load_one($conn, $userId, $notificationId);
    if (!$existing) {
      return false;
    }
    if ((int)($existing['allow_email'] ?? 0) !== 1) {
      return false;
    }
    try {
      if ($stmt = $conn->prepare('UPDATE user_notifications SET send_email=?, email_sent_at=NULL WHERE user_id=? AND id=?')) {
        $flag = $sendEmail ? 1 : 0;
        $stmt->bind_param('iii', $flag, $userId, $notificationId);
        $stmt->execute();
        $stmt->close();
      }
    } catch (Throwable $e) {
      return false;
    }
    if ($sendEmail) {
      ppf_notifications_send_email_if_requested($conn, $userId, $notificationId, [
        'title' => $existing['title'] ?? 'Notification',
        'message' => $existing['message'] ?? 'You have a new notification.',
      ]);
    }
    return true;
  }
}

if (!function_exists('ppf_measurement_user_system')) {
  function ppf_measurement_user_system(): string {
    if (session_status() === PHP_SESSION_ACTIVE) {
      $sessVal = $_SESSION['user_measurement_system'] ?? ($_SESSION['measurement_system'] ?? null);
      $norm = ppf_measurement_normalize_system($sessVal);
      if ($norm !== null) {
        return $norm;
      }
    }
    return ppf_measurement_default_system();
  }
}

if (!function_exists('ppf_measurement_trim_number')) {
  function ppf_measurement_trim_number(float $value, int $precision = 2): string {
    $formatted = number_format($value, $precision, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
  }
}

if (!function_exists('ppf_measurement_weight_unit')) {
  function ppf_measurement_weight_unit(bool $plural = true): string {
    $system = ppf_measurement_user_system();
    if ($system === 'metric') {
      return 'kg';
    }
    return $plural ? 'lbs' : 'lb';
  }
}

if (!function_exists('ppf_measurement_weight_label')) {
  function ppf_measurement_weight_label(): string {
    $unit = ppf_measurement_user_system() === 'metric' ? 'kg' : 'lb';
    return 'Weight (' . $unit . ')';
  }
}

if (!function_exists('ppf_measurement_weight_placeholder')) {
  function ppf_measurement_weight_placeholder(): string {
    $unit = ppf_measurement_user_system() === 'metric' ? 'kg' : 'lbs';
    return 'Weight (' . $unit . ')';
  }
}

if (!function_exists('ppf_measurement_format_weight')) {
  function ppf_measurement_format_weight($lbsValue, bool $withUnits = true, int $precision = 1): ?string {
    if ($lbsValue === null || $lbsValue === '') {
      return null;
    }
    if (!is_numeric($lbsValue)) {
      return null;
    }
    $lbs = (float)$lbsValue;
    if ($lbs < 0) {
      return null;
    }
    $system = ppf_measurement_user_system();
    if ($system === 'metric') {
      $value = $lbs * 0.45359237;
      $text = ppf_measurement_trim_number($value, $precision);
      return $withUnits ? ($text . ' kg') : $text;
    }
    $text = ppf_measurement_trim_number($lbs, $precision);
    return $withUnits ? ($text . ' lbs') : $text;
  }
}

if (!function_exists('ppf_measurement_weight_value_for_input')) {
  function ppf_measurement_weight_value_for_input($lbsValue, int $precision = 1): string {
    $formatted = ppf_measurement_format_weight($lbsValue, false, $precision);
    return $formatted ?? '';
  }
}

if (!function_exists('ppf_measurement_parse_weight_input')) {
  function ppf_measurement_parse_weight_input($input): ?float {
    $raw = trim((string)$input);
    if ($raw === '') {
      return null;
    }
    if (!is_numeric($raw)) {
      return null;
    }
    $value = (float)$raw;
    if ($value < 0) {
      return null;
    }
    $system = ppf_measurement_user_system();
    if ($system === 'metric') {
      $value = $value / 0.45359237;
    }
    return $value;
  }
}

if (!function_exists('ppf_measurement_height_to_inches')) {
  function ppf_measurement_height_to_inches($feet, $inches): ?float {
    $ft = null;
    $in = null;
    if ($feet !== null && $feet !== '' && is_numeric($feet)) {
      $ft = (int)$feet;
    }
    if ($inches !== null && $inches !== '' && is_numeric($inches)) {
      $in = (int)$inches;
    }
    if ($ft === null && $in === null) {
      return null;
    }
    $total = 0;
    if ($ft !== null) {
      $total += $ft * 12;
    }
    if ($in !== null) {
      $total += $in;
    }
    return $total >= 0 ? (float)$total : null;
  }
}

if (!function_exists('ppf_measurement_height_metric_value')) {
  function ppf_measurement_height_metric_value($feet, $inches, int $precision = 1): string {
    $inchesTotal = ppf_measurement_height_to_inches($feet, $inches);
    if ($inchesTotal === null) {
      return '';
    }
    $cm = $inchesTotal * 2.54;
    return ppf_measurement_trim_number($cm, $precision);
  }
}

if (!function_exists('ppf_measurement_height_components_from_cm')) {
  function ppf_measurement_height_components_from_cm($cmInput): array {
    $raw = trim((string)$cmInput);
    if ($raw === '') {
      return [null, null];
    }
    if (!is_numeric($raw)) {
      return [null, null];
    }
    $cm = (float)$raw;
    if ($cm < 0) {
      return [null, null];
    }
    $totalInches = $cm / 2.54;
    if ($totalInches < 0) {
      return [null, null];
    }
    $feet = (int)floor($totalInches / 12);
    $remInches = round($totalInches - ($feet * 12));
    if ($remInches >= 12) {
      $feet += 1;
      $remInches -= 12;
    }
    return [$feet, (int)$remInches];
  }
}

if (!function_exists('ppf_measurement_format_height')) {
  function ppf_measurement_format_height($feet, $inches): ?string {
    $ft = ($feet !== null && $feet !== '' && is_numeric($feet)) ? (int)$feet : null;
    $in = ($inches !== null && $inches !== '' && is_numeric($inches)) ? (int)$inches : null;
    if ($ft === null && $in === null) {
      return null;
    }
    $system = ppf_measurement_user_system();
    if ($system === 'metric') {
      $cm = ppf_measurement_height_metric_value($ft, $in);
      return $cm === '' ? null : ($cm . ' cm');
    }
    $parts = [];
    if ($ft !== null) {
      $parts[] = $ft . ' ft';
    }
    if ($in !== null) {
      $parts[] = $in . ' in';
    }
    if (!$parts) {
      return null;
    }
    return implode(' ', $parts);
  }
}

if (!function_exists('ppf_measurement_js_config')) {
  function ppf_measurement_js_config(): array {
    $system = ppf_measurement_user_system();
    $unitPlural = ppf_measurement_weight_unit(true);
    $unitSingular = ppf_measurement_weight_unit(false);
    return [
      'system' => $system,
      'unitPlural' => $unitPlural,
      'unitSingular' => $unitSingular,
      'kgPerLb' => 0.45359237,
    ];
  }
}

if (!function_exists('ppf_time_user_timezone_id')) {
  function ppf_time_user_timezone_id(): string {
    $sessionTz = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
      $sessionTz = $_SESSION['user_timezone'] ?? ($_SESSION['timezone'] ?? null);
    }
    $norm = ppf_time_normalize_timezone($sessionTz);
    if ($norm !== null) {
      return $norm;
    }
    return ppf_time_default_timezone();
  }
}

if (!function_exists('ppf_time_user_timezone')) {
  function ppf_time_user_timezone(): DateTimeZone {
    static $tz = null;
    $id = ppf_time_user_timezone_id();
    if ($tz instanceof DateTimeZone && $tz->getName() === $id) {
      return $tz;
    }
    $tz = new DateTimeZone($id);
    return $tz;
  }
}

if (!function_exists('ppf_time_user_uses_24h')) {
  function ppf_time_user_uses_24h(): bool {
    $val = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
      if (isset($_SESSION['user_time_24h'])) {
        $val = $_SESSION['user_time_24h'];
      } elseif (isset($_SESSION['time_format_24h'])) {
        $val = $_SESSION['time_format_24h'];
      }
    }
    if ($val === null) {
      return false;
    }
    if (is_bool($val)) {
      return $val;
    }
    if (is_int($val)) {
      return $val === 1;
    }
    $str = strtolower(trim((string)$val));
    return in_array($str, ['1', 'true', 'yes', 'y', '24', '24h', 'h23'], true);
  }
}

if (!function_exists('ppf_time_user_now')) {
  function ppf_time_user_now(): DateTimeImmutable {
    static $now = null;
    if ($now instanceof DateTimeImmutable) {
      return $now;
    }
    $now = new DateTimeImmutable('now', ppf_time_user_timezone());
    return $now;
  }
}

if (!function_exists('ppf_time_datetime_from_value')) {
  function ppf_time_datetime_from_value($value, ?DateTimeZone $assumeTz = null): ?DateTimeImmutable {
    if ($value instanceof DateTimeImmutable) {
      $dt = $value;
    } elseif ($value instanceof DateTimeInterface) {
      $dt = DateTimeImmutable::createFromInterface($value);
    } elseif (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) {
      $timestamp = (int)$value;
      $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    } elseif (is_string($value)) {
      $value = trim($value);
      if ($value === '') {
        return null;
      }
      $tz = $assumeTz ?? new DateTimeZone(ppf_time_default_timezone());
      try {
        if ($value[0] === '@') {
          $dt = new DateTimeImmutable($value);
        } else {
          $dt = new DateTimeImmutable($value, $tz);
        }
      } catch (Throwable $e) {
        return null;
      }
    } else {
      return null;
    }

    if (!($assumeTz instanceof DateTimeZone)) {
      $assumeTz = new DateTimeZone(ppf_time_default_timezone());
    }

    try {
      return $dt->setTimezone($assumeTz);
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('ppf_format_user_datetime')) {
  function ppf_format_user_datetime($value, array $opts = []): string {
    $fallback = array_key_exists('fallback', $opts) ? (string)$opts['fallback'] : '—';

    $sourceTz = null;
    if (!empty($opts['source_timezone'])) {
      if ($opts['source_timezone'] instanceof DateTimeZone) {
        $sourceTz = $opts['source_timezone'];
      } else {
        $norm = ppf_time_normalize_timezone((string)$opts['source_timezone']);
        if ($norm !== null) {
          try {
            $sourceTz = new DateTimeZone($norm);
          } catch (Throwable $e) {
            $sourceTz = null;
          }
        }
      }
    }
    if (!($sourceTz instanceof DateTimeZone)) {
      $sourceTz = new DateTimeZone(ppf_time_default_timezone());
    }

    $dt = ppf_time_datetime_from_value($value, $sourceTz);
    if (!$dt) {
      return $fallback;
    }

    try {
      $dt = $dt->setTimezone(ppf_time_user_timezone());
    } catch (Throwable $e) {
      return $fallback;
    }

    $type = $opts['type'] ?? 'datetime';
    $withSeconds = !empty($opts['seconds']);
    $format = $opts['format'] ?? null;
    $use24 = ppf_time_user_uses_24h();

    if ($format === null) {
      switch ($type) {
        case 'time':
          $format = $use24 ? ($withSeconds ? 'H:i:s' : 'H:i') : ($withSeconds ? 'g:i:s A' : 'g:i A');
          break;
        case 'date_long':
          $format = 'l, F j, Y';
          break;
        case 'date':
          $format = 'M j, Y';
          break;
        case 'datetime_short':
          $format = $use24
            ? ($withSeconds ? 'm/d/Y H:i:s' : 'm/d/Y H:i')
            : ($withSeconds ? 'm/d/Y g:i:s A' : 'm/d/Y g:i A');
          break;
        default:
          $format = $use24
            ? ($withSeconds ? 'M j, Y H:i:s' : 'M j, Y H:i')
            : ($withSeconds ? 'M j, Y g:i:s A' : 'M j, Y g:i A');
          break;
      }
    }

    try {
      return $dt->format($format);
    } catch (Throwable $e) {
      return $fallback;
    }
  }
}

if (!function_exists('ppf_time_render_clock_bootstrap')) {
  function ppf_time_render_clock_bootstrap(): string {
    static $rendered = false;
    if ($rendered) {
      return '';
    }
    $rendered = true;

    $tz = ppf_time_user_timezone_id();
    $hourCycle = ppf_time_user_uses_24h() ? 'h23' : 'h12';
    $hour12 = $hourCycle !== 'h23';
    $iso = ppf_time_user_now()->format(DATE_ATOM);

    $script = <<<JS
<script>
(function(){
  try {
    var cfg = {
      timeZone: %s,
      hourCycle: %s,
      hour12: %s,
      iso: %s
    };
    if (!cfg.iso) { return; }
    if (typeof Intl === 'undefined' || typeof Intl.DateTimeFormat !== 'function') { return; }
    var start = new Date(cfg.iso);
    if (isNaN(start.getTime())) { return; }
    var locale = (navigator.languages && navigator.languages.length ? navigator.languages[0] : (navigator.language || 'en-US')) || 'en-US';
    var baseOffset = start.getTime() - Date.now();
    var optsTime = { timeZone: cfg.timeZone, hour: 'numeric', minute: '2-digit', hourCycle: cfg.hourCycle, hour12: cfg.hour12 };
    var formatters = {
      'time': new Intl.DateTimeFormat(locale, optsTime),
      'date-long': new Intl.DateTimeFormat(locale, { timeZone: cfg.timeZone, weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }),
      'date-short': new Intl.DateTimeFormat(locale, { timeZone: cfg.timeZone, month: 'short', day: 'numeric', year: 'numeric' }),
      'datetime': new Intl.DateTimeFormat(locale, { timeZone: cfg.timeZone, month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hourCycle: cfg.hourCycle, hour12: cfg.hour12 })
    };
    function render(){
      var now = new Date(Date.now() + baseOffset);
      var nodes = document.querySelectorAll('[data-live-clock]');
      nodes.forEach(function(el){
        var type = el.getAttribute('data-live-clock') || 'time';
        var fmt = formatters[type] || formatters['time'];
        try {
          el.textContent = fmt.format(now);
        } catch (err) {
          /* ignore */
        }
      });
    }
    render();
    setInterval(render, 1000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) render(); });
  } catch (err) {
    /* graceful degradation — server-rendered timestamps remain */
  }
})();
</script>
JS;

    return sprintf(
      $script,
      json_encode($tz, JSON_UNESCAPED_SLASHES),
      json_encode($hourCycle, JSON_UNESCAPED_SLASHES),
      $hour12 ? 'true' : 'false',
      json_encode($iso, JSON_UNESCAPED_SLASHES)
    );
  }
}

/* -------------------- Role helpers -------------------- */

if (!function_exists('ppf_role_values')) {
  function ppf_role_values(): array {
    return ['super_admin', 'admin', 'trainer', 'client'];
  }
}

if (!function_exists('ppf_role_key')) {
  function ppf_role_key($role): string {
    return strtolower(trim((string)($role ?? '')));
  }
}

if (!function_exists('ppf_is_super_admin')) {
  function ppf_is_super_admin($role): bool {
    return ppf_role_key($role) === 'super_admin';
  }
}

if (!function_exists('ppf_is_admin_role')) {
  function ppf_is_admin_role($role): bool {
    $key = ppf_role_key($role);
    return $key === 'admin' || $key === 'super_admin';
  }
}

if (!function_exists('ppf_role_display')) {
  function ppf_role_display($role): string {
    $key = ppf_role_key($role);
    $map = [
      'super_admin' => 'Super Admin',
      'admin'       => 'Admin',
      'trainer'     => 'Trainer',
      'client'      => 'Client',
    ];
    if (isset($map[$key])) {
      return $map[$key];
    }
    return ucfirst($key);
  }
}

if (!function_exists('ppf_ensure_super_admin_role')) {
  function ppf_ensure_super_admin_role(mysqli $conn): void {
    try {
      $res = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
      if (!$res) {
        return;
      }
      $column = $res->fetch_assoc();
      $res->free();
      if (!$column) {
        return;
      }

      $type = (string)($column['Type'] ?? '');
      if (stripos($type, 'enum(') !== 0) {
        return;
      }

      preg_match_all("/'([^']*)'/", $type, $matches);
      $current = $matches[1] ?? [];
      $current = array_values(array_map('strval', $current));

      $desiredOrder = ppf_role_values();
      $enumValues = $desiredOrder;

      foreach ($current as $val) {
        if (!in_array($val, $enumValues, true)) {
          $enumValues[] = $val;
        }
      }

      $needsAlter = false;
      foreach ($desiredOrder as $val) {
        if (!in_array($val, $current, true)) {
          $needsAlter = true;
          break;
        }
      }
      if (!$needsAlter) {
        return;
      }

      $enumSqlParts = [];
      foreach ($enumValues as $val) {
        $enumSqlParts[] = "'" . $conn->real_escape_string($val) . "'";
      }
      $enumSql = implode(',', $enumSqlParts);
      $default = in_array('client', $enumValues, true) ? "'client'" : $enumSqlParts[0];
      @$conn->query("ALTER TABLE users MODIFY role ENUM({$enumSql}) NOT NULL DEFAULT {$default}");
    } catch (Throwable $e) {
      // Non-fatal; schema may be managed separately.
    }
  }
}

if (!function_exists('ppf_promote_super_admin_account')) {
  function ppf_promote_super_admin_account(mysqli $conn, string $email): void {
    try {
      if ($stmt = $conn->prepare("UPDATE users SET role='super_admin' WHERE email=? AND role='admin'")) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
      }
    } catch (Throwable $e) {
      // Ignore failures; account may not exist yet.
    }
  }
}

/* -------------------- Convenience: invites schema guard -------------------- */
/**
 * Adds columns used by the dashboard’s invites donut if missing.
 * Safe to call at startup in admin areas if you want auto-migration.
 */
if (!function_exists('ensure_invite_columns')) {
  function ensure_invite_columns(mysqli $conn): void {
    // accepted_at
    if (!column_exists($conn,'invites','accepted_at')) {
      @$conn->query("ALTER TABLE invites ADD COLUMN accepted_at DATETIME NULL AFTER created_at");
    }
    // completed_at (was mistakenly named registered_at in older snippet)
    if (!column_exists($conn,'invites','completed_at')) {
      @$conn->query("ALTER TABLE invites ADD COLUMN completed_at DATETIME NULL AFTER accepted_at");
    }
    // used (bool-ish)
    if (!column_exists($conn,'invites','used')) {
      @$conn->query("ALTER TABLE invites ADD COLUMN used TINYINT(1) NOT NULL DEFAULT 0 AFTER cancelled_at");
    }
    // Optional: expires_at/cancelled_at should already exist in most schemas
  }
}
