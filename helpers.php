<?php
// helpers.php — shared helpers for PeterPangFit

require_once __DIR__ . '/ppf_env.php';

/* -------------------- General safe helpers -------------------- */
if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fmt_when')) {
  function fmt_when(?string $ts): string {
    return $ts ? date('Y-m-d H:i:s', strtotime($ts)) : '';
  }
}

if (!function_exists('ppf_notifications_normalize_channels')) {
  function ppf_notifications_normalize_channels($channels, array $fallback = ['center' => false, 'email' => false]): array {
    $normalized = [
      'center' => isset($fallback['center']) ? (bool)$fallback['center'] : false,
      'email' => isset($fallback['email']) ? (bool)$fallback['email'] : false,
    ];
    if (is_array($channels)) {
      foreach ($channels as $key => $value) {
        if (!array_key_exists($key, $normalized)) {
          continue;
        }
        $normalized[$key] = (bool)$value;
      }
    }
    return $normalized;
  }
}

if (!function_exists('ppf_notification_rule_transform_row')) {
  function ppf_notification_rule_transform_row(array $row): array {
    $channelsRaw = null;
    if (!empty($row['channels'])) {
      $decoded = json_decode((string)$row['channels'], true);
      if (is_array($decoded)) {
        $channelsRaw = $decoded;
      }
    }
    $channels = ppf_notifications_normalize_channels($channelsRaw, ['center' => true, 'email' => false]);
    $metadata = [];
    if (!empty($row['metadata'])) {
      $decoded = json_decode((string)$row['metadata'], true);
      if (is_array($decoded)) {
        $metadata = $decoded;
      }
    }
    if (!isset($metadata['channels']) || !is_array($metadata['channels'])) {
      $metadata['channels'] = $channels;
    }
    $sendEmail = (bool)($row['send_email'] ?? !empty($channels['email']));
    $metadata['send_email'] = $sendEmail;
    $metadata['type_key'] = $row['type_key'] ?? ($metadata['type_key'] ?? null);
    $metadata['category'] = ppf_notifications_valid_category((string)($row['category'] ?? 'system'));
    $immutable = !empty($row['immutable']);
    if ($immutable) {
      $metadata['immutable'] = true;
    }
    return [
      'id' => (int)$row['id'],
      'tenant_id' => (int)$row['tenant_id'],
      'user_id' => (int)$row['user_id'],
      'type_key' => (string)($row['type_key'] ?? ''),
      'title' => (string)($row['title'] ?? ''),
      'body' => (string)($row['body'] ?? ''),
      'category' => ppf_notifications_valid_category((string)($row['category'] ?? 'system')),
      'channels' => $channels,
      'send_email' => $sendEmail,
      'priority' => (int)($row['priority'] ?? 0),
      'immutable' => $immutable,
      'metadata' => $metadata,
      'created_at' => $row['created_at'] ?? null,
      'updated_at' => $row['updated_at'] ?? null,
    ];
  }
}

if (!function_exists('ppf_notification_rule_apply_channels_override')) {
  function ppf_notification_rule_apply_channels_override(array $rule, array $channels, ?bool $forceMuted = null): array {
    $normalized = ppf_notifications_normalize_channels($channels, ['center' => true, 'email' => false]);
    $rule['channels'] = $normalized;
    $rule['send_email'] = !empty($normalized['email']);
    $metadata = [];
    if (!empty($rule['metadata']) && is_array($rule['metadata'])) {
      $metadata = $rule['metadata'];
    }
    $metadata['channels'] = $normalized;
    $metadata['send_email'] = !empty($normalized['email']);
    $muted = $forceMuted;
    if ($muted === null) {
      $muted = empty($normalized['center']) && empty($normalized['email']);
    }
    if ($muted) {
      $metadata['muted'] = true;
    } elseif (isset($metadata['muted'])) {
      unset($metadata['muted']);
    }
    $rule['metadata'] = $metadata;
    return $rule;
  }
}

if (!function_exists('ppf_notification_rule_state_map')) {
  function ppf_notification_rule_state_map(mysqli $conn, int $tenantId, int $userId): array {
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare('SELECT type_key, channels FROM notification_rule_states WHERE tenant_id = ? AND user_id = ?');
    if (!$stmt) {
      return [];
    }
    $stmt->bind_param('ii', $tenantId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $states = [];
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $key = (string)($row['type_key'] ?? '');
        if ($key === '') {
          continue;
        }
        $decoded = [];
        if (!empty($row['channels'])) {
          $parsed = json_decode((string)$row['channels'], true);
          if (is_array($parsed)) {
            $decoded = $parsed;
          }
        }
        $states[$key] = ppf_notifications_normalize_channels($decoded, ['center' => true, 'email' => false]);
      }
      $res->close();
    }
    $stmt->close();
    return $states;
  }
}

if (!function_exists('ppf_notification_rule_state_get')) {
  function ppf_notification_rule_state_get(mysqli $conn, int $tenantId, int $userId, string $typeKey): ?array {
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare('SELECT channels FROM notification_rule_states WHERE tenant_id = ? AND user_id = ? AND type_key = ? LIMIT 1');
    if (!$stmt) {
      return null;
    }
    $stmt->bind_param('iis', $tenantId, $userId, $typeKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row || empty($row['channels'])) {
      return null;
    }
    $decoded = json_decode((string)$row['channels'], true);
    if (!is_array($decoded)) {
      return null;
    }
    return ppf_notifications_normalize_channels($decoded, ['center' => true, 'email' => false]);
  }
}

if (!function_exists('ppf_notification_rule_state_put')) {
  function ppf_notification_rule_state_put(mysqli $conn, int $tenantId, int $userId, string $typeKey, array $channels): bool {
    ppf_notifications_bootstrap($conn);
    $typeKey = trim($typeKey);
    if ($typeKey === '') {
      return false;
    }
    $normalized = ppf_notifications_normalize_channels($channels, ['center' => true, 'email' => false]);
    $jsonChannels = json_encode($normalized);
    if ($jsonChannels === false) {
      return false;
    }
    $flag = !empty($normalized['email']) ? 1 : 0;
    $stmt = $conn->prepare('INSERT INTO notification_rule_states (tenant_id, user_id, type_key, channels, send_email) VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE channels = VALUES(channels), send_email = VALUES(send_email), updated_at = CURRENT_TIMESTAMP');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('iissi', $tenantId, $userId, $typeKey, $jsonChannels, $flag);
    $stmt->execute();
    $ok = ppf_notifications_stmt_affected_rows($stmt) >= 0;
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('ppf_notification_rule_state_delete')) {
  function ppf_notification_rule_state_delete(mysqli $conn, int $tenantId, int $userId, string $typeKey): void {
    ppf_notifications_bootstrap($conn);
    $typeKey = trim($typeKey);
    if ($typeKey === '') {
      return;
    }
    if ($stmt = $conn->prepare('DELETE FROM notification_rule_states WHERE tenant_id = ? AND user_id = ? AND type_key = ?')) {
      $stmt->bind_param('iis', $tenantId, $userId, $typeKey);
      $stmt->execute();
      $stmt->close();
    }
  }
}

if (!function_exists('ppf_notification_rule_states_apply_list')) {
  function ppf_notification_rule_states_apply_list(mysqli $conn, int $tenantId, int $userId, array $rules): array {
    if (!$rules) {
      return $rules;
    }
    $states = ppf_notification_rule_state_map($conn, $tenantId, $userId);
    if (!$states) {
      return $rules;
    }
    $seen = [];
    foreach ($rules as $idx => $rule) {
      $typeKey = (string)($rule['type_key'] ?? '');
      if ($typeKey === '') {
        continue;
      }
      if (!empty($rule['immutable']) && isset($states[$typeKey])) {
        ppf_notification_rule_state_delete($conn, $tenantId, $userId, $typeKey);
        unset($states[$typeKey]);
        continue;
      }
      if (isset($states[$typeKey])) {
        $rules[$idx] = ppf_notification_rule_apply_channels_override($rule, $states[$typeKey]);
        $seen[$typeKey] = true;
      }
    }
    if ($states) {
      foreach ($states as $typeKey => $_) {
        if (!isset($seen[$typeKey])) {
          ppf_notification_rule_state_delete($conn, $tenantId, $userId, (string)$typeKey);
        }
      }
    }
    return $rules;
  }
}

if (!function_exists('ppf_notification_rule_state_apply_single')) {
  function ppf_notification_rule_state_apply_single(mysqli $conn, int $tenantId, int $userId, array $rule): array {
    $typeKey = (string)($rule['type_key'] ?? '');
    if ($typeKey === '' || !empty($rule['immutable'])) {
      if ($typeKey !== '' && !empty($rule['immutable'])) {
        ppf_notification_rule_state_delete($conn, $tenantId, $userId, $typeKey);
      }
      return $rule;
    }
    $state = ppf_notification_rule_state_get($conn, $tenantId, $userId, $typeKey);
    if (!$state) {
      return $rule;
    }
    return ppf_notification_rule_apply_channels_override($rule, $state);
  }
}

if (!function_exists('ppf_notification_rules_list')) {
  function ppf_notification_rules_list(mysqli $conn, int $tenantId, int $userId): array {
    ppf_notifications_bootstrap($conn);
    ppf_notifications_seed_defaults($conn, $tenantId, $userId);
    $stmt = $conn->prepare('SELECT * FROM notification_rules WHERE tenant_id = ? AND user_id = ? ORDER BY category ASC, priority DESC, title ASC');
    if (!$stmt) {
      return [];
    }
    $stmt->bind_param('ii', $tenantId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
      $items[] = ppf_notification_rule_transform_row($row);
    }
    $stmt->close();
    return ppf_notification_rule_states_apply_list($conn, $tenantId, $userId, $items);
  }
}

if (!function_exists('ppf_notification_rules_get')) {
  function ppf_notification_rules_get(mysqli $conn, int $tenantId, int $userId, int $ruleId, bool $tenantScope = false): ?array {
    ppf_notifications_bootstrap($conn);
    $sql = 'SELECT * FROM notification_rules WHERE tenant_id = ? AND id = ?';
    $params = [$tenantId, $ruleId];
    $types = 'ii';
    if (!$tenantScope) {
      $sql .= ' AND user_id = ?';
      $params[] = $userId;
      $types .= 'i';
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      return null;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
      return null;
    }
    $rule = ppf_notification_rule_transform_row($row);
    return ppf_notification_rule_state_apply_single($conn, $tenantId, $userId, $rule);
  }
}

if (!function_exists('ppf_notification_rules_get_by_key')) {
  function ppf_notification_rules_get_by_key(mysqli $conn, int $tenantId, int $userId, string $typeKey): ?array {
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare('SELECT * FROM notification_rules WHERE tenant_id = ? AND user_id = ? AND type_key = ? LIMIT 1');
    if (!$stmt) {
      return null;
    }
    $stmt->bind_param('iis', $tenantId, $userId, $typeKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
      return null;
    }
    $rule = ppf_notification_rule_transform_row($row);
    return ppf_notification_rule_state_apply_single($conn, $tenantId, $userId, $rule);
  }
}

if (!function_exists('ppf_notification_rules_save')) {
  function ppf_notification_rules_save(mysqli $conn, int $tenantId, int $userId, array $data, ?int $ruleId = null): ?int {
    ppf_notifications_bootstrap($conn);
    $title = trim((string)($data['title'] ?? ''));
    $body = trim((string)($data['body'] ?? ''));
    $category = ppf_notifications_valid_category((string)($data['category'] ?? 'custom'));
    $typeKey = trim((string)($data['type_key'] ?? ''));
    $sendEmail = !empty($data['send_email']);
    $channels = ['center' => true, 'email' => $sendEmail];
    if (!empty($data['channels']) && is_array($data['channels'])) {
      foreach ($data['channels'] as $channel => $enabled) {
        if (!in_array($channel, ['center', 'email'], true)) {
          continue;
        }
        $channels[$channel] = (bool)$enabled;
      }
    }
    $sendEmail = !empty($channels['email']);
    if ($title === '' || $typeKey === '') {
      return null;
    }

    if ($ruleId !== null) {
      $existing = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
      if (!$existing) {
        return null;
      }
      if (!empty($existing['immutable'])) {
        return null;
      }
      $typeKey = $existing['type_key'];
    } else {
      if (strpos($typeKey, 'custom.') !== 0 && !isset(ppf_notifications_catalog()[$typeKey])) {
        $typeKey = 'custom.' . preg_replace('/[^a-z0-9]+/i', '.', strtolower($typeKey));
      }
    }

    $metadata = ['channels' => $channels, 'send_email' => !empty($channels['email']), 'type_key' => $typeKey, 'category' => $category];
    if (!empty($data['metadata']) && is_array($data['metadata'])) {
      $metadata = array_merge($data['metadata'], $metadata);
    }
    $jsonChannels = json_encode($channels);
    $jsonMetadata = json_encode($metadata);
    $priority = isset($data['priority']) ? (int)$data['priority'] : 0;
    if (!isset(ppf_notifications_priorities()[$priority])) {
      $priority = 0;
    }

    if ($ruleId !== null) {
      $stmt = $conn->prepare('UPDATE notification_rules SET title = ?, body = ?, category = ?, channels = ?, send_email = ?, priority = ?, metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?');
      if (!$stmt) {
        return null;
      }
      $sendFlag = !empty($channels['email']) ? 1 : 0;
      $stmt->bind_param('ssssiisiii', $title, $body, $category, $jsonChannels, $sendFlag, $priority, $jsonMetadata, $tenantId, $userId, $ruleId);
      $stmt->execute();
      $ok = ppf_notifications_stmt_affected_rows($stmt) >= 0;
      $stmt->close();
      if ($ok) {
        ppf_notification_rule_state_put($conn, $tenantId, $userId, $typeKey, $channels);
        return $ruleId;
      }
      return null;
    }

    $stmt = $conn->prepare('INSERT INTO notification_rules (tenant_id, user_id, type_key, title, body, category, channels, send_email, priority, metadata) VALUES (?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt) {
      return null;
    }
    $sendFlag = !empty($channels['email']) ? 1 : 0;
    $stmt->bind_param('iisssssiis', $tenantId, $userId, $typeKey, $title, $body, $category, $jsonChannels, $sendFlag, $priority, $jsonMetadata);
    $stmt->execute();
    $id = ppf_notifications_stmt_insert_id($stmt);
    $stmt->close();
    if ($id > 0) {
      if (empty($metadata['immutable'])) {
        ppf_notification_rule_state_put($conn, $tenantId, $userId, $typeKey, $channels);
      } else {
        ppf_notification_rule_state_delete($conn, $tenantId, $userId, $typeKey);
      }
      return $id;
    }
    return null;
  }
}

if (!function_exists('ppf_notification_rules_delete')) {
  function ppf_notification_rules_delete(mysqli $conn, int $tenantId, int $userId, int $ruleId): bool {
    $rule = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
    if (!$rule || !empty($rule['immutable'])) {
      return false;
    }
    $stmt = $conn->prepare('DELETE FROM notification_rules WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('iii', $tenantId, $userId, $ruleId);
    $stmt->execute();
    $rows = ppf_notifications_stmt_affected_rows($stmt);
    $stmt->close();
    if ($rows > 0) {
      ppf_notification_rule_state_delete($conn, $tenantId, $userId, (string)$rule['type_key']);
      return true;
    }
    return false;
  }
}

if (!function_exists('ppf_notification_rules_update_channels')) {
  function ppf_notification_rules_update_channels(mysqli $conn, int $tenantId, int $userId, int $ruleId, array $channelUpdates): ?array {
    $rule = ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
    if (!$rule || !empty($rule['immutable'])) {
      return null;
    }

    $channels = ppf_notifications_normalize_channels($rule['channels'] ?? null, ['center' => true, 'email' => false]);
    foreach ($channelUpdates as $key => $value) {
      if (!in_array($key, ['center', 'email'], true)) {
        continue;
      }
      $channels[$key] = (bool)$value;
    }

    $metadata = is_array($rule['metadata']) ? $rule['metadata'] : [];
    $metadata['channels'] = $channels;
    $metadata['send_email'] = !empty($channels['email']);

    $jsonChannels = json_encode($channels);
    $jsonMetadata = json_encode($metadata);
    if ($jsonChannels === false || $jsonMetadata === false) {
      return null;
    }

    $stmt = $conn->prepare('UPDATE notification_rules SET channels = ?, send_email = ?, metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return null;
    }
    $flag = !empty($channels['email']) ? 1 : 0;
    $stmt->bind_param('sisiii', $jsonChannels, $flag, $jsonMetadata, $tenantId, $userId, $ruleId);
    $stmt->execute();
    $ok = ppf_notifications_stmt_affected_rows($stmt) >= 0;
    $stmt->close();

    if ($ok) {
      ppf_notification_rule_state_put($conn, $tenantId, $userId, (string)$rule['type_key'], $channels);
      return ppf_notification_rules_get($conn, $tenantId, $userId, $ruleId);
    }
    return null;
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
    if (!file_exists($path)) {
      return; // nothing to do yet
    }

    if (PHP_OS_FAMILY === 'Windows') {
      if (!$canExec) return;
      $p = str_replace('/', DIRECTORY_SEPARATOR, $path);
      @exec('icacls "' . $p . '" /inheritance:e');
      @exec('icacls "' . $p . '" /grant Administrators:(F)');
      @exec('icacls "' . $p . '" /grant IIS_IUSRS:' . ($isDir ? '(OI)(CI)(M)' : '(M)'));
      // If you use a custom App Pool identity, uncomment + set:
      // $appPool = getenv('APP_POOL_ID') ?: 'DefaultAppPool';
      // @exec('icacls "' . $p . '" /grant "IIS AppPool\\'.$appPool.'":' . ($isDir ? '(OI)(CI)(M)' : '(M)'));
      return;
    }

    if (is_link($path)) {
      return; // avoid chmod on symlinks that may point outside our control
    }

    $canChmod = true;
    if (function_exists('posix_geteuid')) {
      $owner = @fileowner($path);
      $procUser = @posix_geteuid();
      if ($owner !== false && $procUser !== false && $owner !== $procUser && $procUser !== 0) {
        $canChmod = false;
      }
    }

    if (!$canChmod) {
      return; // we do not own the file, so skip best-effort chmod to avoid warnings
    }

    if ($isDir) {
      @chmod($path, 0755);
    } else {
      @chmod($path, 0644);
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

if (!function_exists('ppf_uuidv4')) {
  function ppf_uuidv4(): string {
    try {
      $bytes = random_bytes(16);
    } catch (Throwable $e) {
      $bytes = openssl_random_pseudo_bytes(16);
    }
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s',
      substr($hex, 0, 8),
      substr($hex, 8, 4),
      substr($hex, 12, 4),
      substr($hex, 16, 4),
      substr($hex, 20, 12)
    );
  }
}

if (!function_exists('ppf_current_tenant_id')) {
  function ppf_current_tenant_id(): int {
    $tenant = $_SESSION['tenant_id'] ?? getenv('PPF_TENANT_ID') ?: 1;
    $tenant = (int)$tenant;
    return $tenant > 0 ? $tenant : 1;
  }
}

if (!function_exists('ppf_notifications_request_id')) {
  function ppf_notifications_request_id(): string {
    static $id = null;
    if ($id !== null) {
      return $id;
    }
    $id = ppf_uuidv4();
    return $id;
  }
}

if (!function_exists('ppf_notification_categories')) {
  function ppf_notification_categories(): array {
    return [
      'security' => ['label' => 'Security', 'description' => 'Account safety and access alerts'],
      'workouts' => ['label' => 'Workouts', 'description' => 'Plans, sessions, and assignments'],
      'billing' => ['label' => 'Billing', 'description' => 'Purchases, refunds, and invoices'],
      'system' => ['label' => 'System', 'description' => 'Global notices and announcements'],
      'custom' => ['label' => 'Custom', 'description' => 'Personal reminders you create'],
    ];
  }
}

if (!function_exists('ppf_notifications_types')) {
  function ppf_notifications_types(): array {
    return [
      'info' => ['label' => 'Info', 'badge' => 'bg-sky-100 text-sky-800'],
      'success' => ['label' => 'Success', 'badge' => 'bg-emerald-100 text-emerald-800'],
      'warning' => ['label' => 'Warning', 'badge' => 'bg-amber-100 text-amber-800'],
      'error' => ['label' => 'Error', 'badge' => 'bg-rose-100 text-rose-800'],
      'system' => ['label' => 'System', 'badge' => 'bg-slate-200 text-slate-800'],
    ];
  }
}

if (!function_exists('ppf_notifications_priorities')) {
  function ppf_notifications_priorities(): array {
    return [
      0 => ['label' => 'Normal', 'icon' => ''],
      1 => ['label' => 'High', 'icon' => '!'],
    ];
  }
}

if (!function_exists('ppf_notifications_valid_category')) {
  function ppf_notifications_valid_category(string $category): string {
    $map = ppf_notification_categories();
    $key = strtolower(trim($category));
    return array_key_exists($key, $map) ? $key : 'system';
  }
}

if (!function_exists('ppf_notifications_default_settings')) {
  function ppf_notifications_default_settings(): array {
    return [
      'delivery_prefs' => [
        'auto_mark_on_open' => false,
        'badge_includes_muted' => false,
        'default_sort' => 'created_at:desc',
        'page_size' => 25,
      ],
      'types_muted' => [],
    ];
  }
}

if (!function_exists('ppf_notifications_json_column_type')) {
  function ppf_notifications_json_column_type(mysqli $conn): string {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (isset($cache[$key])) {
      return $cache[$key];
    }
    try {
      $result = @$conn->query('SELECT JSON_EXTRACT(\'{"t":1}\', \'$.t\') AS j');
      if ($result instanceof mysqli_result) {
        $result->close();
        return $cache[$key] = 'JSON';
      }
    } catch (Throwable $e) {
      // Fall through to LONGTEXT fallback below.
    }
    return $cache[$key] = 'LONGTEXT';
  }
}

if (!function_exists('ppf_notifications_json_column_defs')) {
  function ppf_notifications_json_column_defs(mysqli $conn): array {
    $type = ppf_notifications_json_column_type($conn);
    if ($type === 'JSON') {
      return [
        'nullable' => 'JSON',
        'not_null' => 'JSON NOT NULL',
      ];
    }
    return [
      'nullable' => 'LONGTEXT',
      'not_null' => 'LONGTEXT NOT NULL',
    ];
  }
}

if (!function_exists('ppf_notifications_bootstrap')) {
  function ppf_notifications_bootstrap(mysqli $conn): void {
    static $bootstrapped = false;
    if ($bootstrapped) {
      return;
    }
    if (property_exists($conn, 'ppfFakeNotificationsDriver') && $conn->ppfFakeNotificationsDriver) {
      $bootstrapped = true;
      return;
    }
    $bootstrapped = true;
    try {
      $jsonDefs = ppf_notifications_json_column_defs($conn);
      $jsonNullable = $jsonDefs['nullable'];
      $jsonNotNull = $jsonDefs['not_null'];
      if (table_exists($conn, 'notifications') && !table_exists($conn, 'notification_rules')) {
        @$conn->query('RENAME TABLE notifications TO notification_rules');
      }

      if (!table_exists($conn, 'notification_rules')) {
        @$conn->query("CREATE TABLE notification_rules (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          type_key VARCHAR(191) NOT NULL,
          title VARCHAR(160) NOT NULL,
          body TEXT NULL,
          category VARCHAR(48) NOT NULL,
          channels $jsonNullable,
          send_email TINYINT(1) NOT NULL DEFAULT 0,
          priority TINYINT(1) NOT NULL DEFAULT 0,
          immutable TINYINT(1) NOT NULL DEFAULT 0,
          metadata $jsonNullable,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_notification_rules_user_key (tenant_id, user_id, type_key),
          KEY idx_notification_rules_user (tenant_id, user_id, created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      } else {
        if (!column_exists($conn, 'notification_rules', 'type_key')) {
          @$conn->query("ALTER TABLE notification_rules ADD COLUMN type_key VARCHAR(191) NOT NULL AFTER user_id");
          @$conn->query("CREATE UNIQUE INDEX uq_notification_rules_user_key ON notification_rules (tenant_id, user_id, type_key)");
        }
        if (!column_exists($conn, 'notification_rules', 'category')) {
          @$conn->query("ALTER TABLE notification_rules ADD COLUMN category VARCHAR(48) NOT NULL DEFAULT 'system' AFTER body");
        }
        if (!column_exists($conn, 'notification_rules', 'channels')) {
          @$conn->query("ALTER TABLE notification_rules ADD COLUMN channels $jsonNullable AFTER category");
        }
        if (!column_exists($conn, 'notification_rules', 'send_email')) {
          @$conn->query("ALTER TABLE notification_rules ADD COLUMN send_email TINYINT(1) NOT NULL DEFAULT 0 AFTER channels");
        }
        if (!column_exists($conn, 'notification_rules', 'immutable')) {
          @$conn->query("ALTER TABLE notification_rules ADD COLUMN immutable TINYINT(1) NOT NULL DEFAULT 0 AFTER priority");
        }
      }

      if (!table_exists($conn, 'notification_messages')) {
        @$conn->query("CREATE TABLE notification_messages (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          rule_id BIGINT UNSIGNED NULL,
          type_key VARCHAR(191) NULL,
          title VARCHAR(160) NOT NULL,
          body TEXT NOT NULL,
          type ENUM('info','success','warning','error','system') NOT NULL DEFAULT 'info',
          url VARCHAR(512) NULL DEFAULT NULL,
          priority TINYINT(1) NOT NULL DEFAULT 0,
          is_read TINYINT(1) NOT NULL DEFAULT 0,
          read_at DATETIME NULL DEFAULT NULL,
          is_archived TINYINT(1) NOT NULL DEFAULT 0,
          archived_at DATETIME NULL DEFAULT NULL,
          metadata $jsonNullable,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_notification_messages_user (tenant_id, user_id, is_archived, created_at DESC),
          KEY idx_notification_messages_rule (tenant_id, rule_id, created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      } else {
        if (!column_exists($conn, 'notification_messages', 'tenant_id')) {
          @$conn->query("ALTER TABLE notification_messages ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
          @$conn->query("CREATE INDEX idx_notification_messages_user ON notification_messages (tenant_id, user_id, is_archived, created_at DESC)");
        }
        if (!column_exists($conn, 'notification_messages', 'type_key')) {
          @$conn->query("ALTER TABLE notification_messages ADD COLUMN type_key VARCHAR(191) NULL DEFAULT NULL AFTER rule_id");
        }
        if (!column_exists($conn, 'notification_messages', 'metadata')) {
          @$conn->query("ALTER TABLE notification_messages ADD COLUMN metadata $jsonNullable AFTER archived_at");
        }
      }

      if (!table_exists($conn, 'notification_rule_states')) {
        @$conn->query("CREATE TABLE notification_rule_states (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          type_key VARCHAR(191) NOT NULL,
          channels $jsonNotNull,
          send_email TINYINT(1) NOT NULL DEFAULT 0,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_notification_rule_states_user (tenant_id, user_id, type_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      }

      if (column_exists($conn, 'notification_rules', 'is_read')) {
        $res = @$conn->query('SELECT COUNT(*) AS c FROM notification_rules');
        if ($res && ($row = $res->fetch_assoc()) && (int)$row['c'] > 0) {
          @$conn->query("INSERT INTO notification_messages (tenant_id, user_id, type_key, title, body, type, url, priority, is_read, read_at, is_archived, archived_at, metadata, created_at, updated_at) SELECT tenant_id, user_id, COALESCE(NULLIF(type_key, ''), JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.type_key'))), title, body, type, url, priority, is_read, read_at, is_archived, archived_at, metadata, created_at, updated_at FROM notification_rules");
          @$conn->query('TRUNCATE TABLE notification_rules');
        }
        if ($res instanceof mysqli_result) {
          $res->close();
        }
      }

      if (!table_exists($conn, 'notification_settings')) {
        @$conn->query("CREATE TABLE notification_settings (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          delivery_prefs $jsonNotNull,
          types_muted $jsonNotNull,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_notification_settings_user (tenant_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      }

      if (!table_exists($conn, 'notification_events')) {
        @$conn->query("CREATE TABLE notification_events (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          notification_id BIGINT UNSIGNED NOT NULL,
          event_type VARCHAR(32) NOT NULL,
          actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
          context $jsonNullable,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_notification_events_user (tenant_id, user_id, created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      }

      if (!table_exists($conn, 'notification_migrations')) {
        @$conn->query("CREATE TABLE notification_migrations (
          migration_key VARCHAR(191) NOT NULL PRIMARY KEY,
          details $jsonNullable,
          applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      }

      $seedKey = '20240424_seed_default_rules';
      $seedApplied = false;
      if ($stmt = $conn->prepare('SELECT migration_key FROM notification_migrations WHERE migration_key = ? LIMIT 1')) {
        $stmt->bind_param('s', $seedKey);
        $stmt->execute();
        if ($res = $stmt->get_result()) {
          $seedApplied = (bool)$res->fetch_row();
          $res->close();
        }
        $stmt->close();
      }

      if (!$seedApplied) {
        $seededUsers = 0;
        $tenantColExists = column_exists($conn, 'users', 'tenant_id');
        $sql = $tenantColExists
          ? 'SELECT id, tenant_id FROM users'
          : 'SELECT id FROM users';
        if ($res = @$conn->query($sql)) {
          while ($row = $res->fetch_assoc()) {
            $userId = (int)($row['id'] ?? 0);
            if ($userId <= 0) {
              continue;
            }
            $tenantId = $tenantColExists ? (int)($row['tenant_id'] ?? 1) : 1;
            if ($tenantId <= 0) {
              $tenantId = 1;
            }
            try {
              ppf_notifications_seed_defaults($conn, $tenantId, $userId);
              $seededUsers++;
            } catch (Throwable $inner) {
              continue;
            }
          }
          if ($res instanceof mysqli_result) {
            $res->close();
          }
        }
        if ($stmt = $conn->prepare('INSERT INTO notification_migrations (migration_key, details) VALUES (?, ?)')) {
          $details = json_encode(['seeded_users' => $seededUsers]);
          $stmt->bind_param('ss', $seedKey, $details);
          $stmt->execute();
          $stmt->close();
        }
      }
    } catch (Throwable $e) {
      // schema bootstrap errors are non-fatal
    }
  }
}

if (!function_exists('ppf_notifications_stmt_affected_rows')) {
  function ppf_notifications_stmt_affected_rows(mysqli_stmt $stmt): int {
    if (method_exists($stmt, 'ppfFakeAffectedRows')) {
      return (int)$stmt->ppfFakeAffectedRows();
    }
    return (int)$stmt->affected_rows;
  }
}

if (!function_exists('ppf_notifications_stmt_insert_id')) {
  function ppf_notifications_stmt_insert_id(mysqli_stmt $stmt): int {
    if (method_exists($stmt, 'ppfFakeInsertId')) {
      return (int)$stmt->ppfFakeInsertId();
    }
    return (int)$stmt->insert_id;
  }
}

if (!function_exists('ppf_notifications_settings_get')) {
  function ppf_notifications_settings_get(mysqli $conn, int $tenantId, int $userId): array {
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare("SELECT delivery_prefs, types_muted FROM notification_settings WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('ii', $tenantId, $userId);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && $row = $res->fetch_assoc()) {
        $stmt->close();
        $defaults = ppf_notifications_default_settings();
        $prefs = json_decode((string)$row['delivery_prefs'], true) ?: [];
        $muted = json_decode((string)$row['types_muted'], true) ?: [];
        return [
          'delivery_prefs' => array_merge($defaults['delivery_prefs'], $prefs),
          'types_muted' => array_values(array_unique(array_map('strval', $muted))),
        ];
      }
      $stmt->close();
    }
    return ppf_notifications_default_settings();
  }
}

if (!function_exists('ppf_notifications_settings_put')) {
  function ppf_notifications_settings_put(mysqli $conn, int $tenantId, int $userId, array $payload): bool {
    ppf_notifications_bootstrap($conn);
    $current = ppf_notifications_settings_get($conn, $tenantId, $userId);
    $next = $current;
    if (isset($payload['delivery_prefs']) && is_array($payload['delivery_prefs'])) {
      foreach ($payload['delivery_prefs'] as $key => $value) {
        switch ($key) {
          case 'auto_mark_on_open':
          case 'badge_includes_muted':
            $next['delivery_prefs'][$key] = (bool)$value;
            break;
          case 'default_sort':
            $allowed = ['created_at:asc','created_at:desc','priority:asc','priority:desc','type:asc','type:desc','read_at:asc','read_at:desc'];
            $val = (string)$value;
            if (in_array($val, $allowed, true)) {
              $next['delivery_prefs'][$key] = $val;
            }
            break;
          case 'page_size':
            $size = (int)$value;
            if (in_array($size, [10,25,50], true)) {
              $next['delivery_prefs'][$key] = $size;
            }
            break;
        }
      }
    }
    if (isset($payload['types_muted'])) {
      $types = array_filter(array_map('strval', (array)$payload['types_muted']), function ($type) {
        return isset(ppf_notifications_types()[$type]);
      });
      $next['types_muted'] = array_values(array_unique($types));
    }
    $jsonPrefs = json_encode($next['delivery_prefs']);
    $jsonMuted = json_encode($next['types_muted']);
    $stmt = $conn->prepare("INSERT INTO notification_settings (tenant_id, user_id, delivery_prefs, types_muted) VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE delivery_prefs = VALUES(delivery_prefs), types_muted = VALUES(types_muted), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('iiss', $tenantId, $userId, $jsonPrefs, $jsonMuted);
    $stmt->execute();
    $ok = ppf_notifications_stmt_affected_rows($stmt) >= 0;
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('ppf_notifications_log_event')) {
  function ppf_notifications_log_event(mysqli $conn, int $tenantId, int $userId, int $notificationId, string $eventType, ?int $actorUserId = null, array $context = []): void {
    ppf_notifications_bootstrap($conn);
    if ($stmt = $conn->prepare("INSERT INTO notification_events (tenant_id, user_id, notification_id, event_type, actor_user_id, context) VALUES (?,?,?,?,?,?)")) {
      $ctx = $context ? json_encode($context) : null;
      $actor = $actorUserId;
      $stmt->bind_param('iiisis', $tenantId, $userId, $notificationId, $eventType, $actor, $ctx);
      $stmt->execute();
      $stmt->close();
    }
  }
}

if (!function_exists('ppf_notifications_should_filter_type')) {
  function ppf_notifications_should_filter_type(array $settings, string $type, bool $forBadge = false): bool {
    $muted = $settings['types_muted'] ?? [];
    if (!in_array($type, $muted, true)) {
      return false;
    }
    if ($forBadge) {
      return !(bool)($settings['delivery_prefs']['badge_includes_muted'] ?? false);
    }
    return true;
  }
}

if (!function_exists('ppf_notifications_transform_row')) {
  function ppf_notifications_transform_row(array $row): array {
    $types = ppf_notifications_types();
    $priorities = ppf_notifications_priorities();
    $type = isset($types[$row['type'] ?? '']) ? $row['type'] : 'info';
    $priority = (int)($row['priority'] ?? 0);
    if (!isset($priorities[$priority])) {
      $priority = 0;
    }
    $metadata = [];
    $actions = [];
    if (isset($row['metadata'])) {
      $decoded = json_decode((string)$row['metadata'], true);
      if (is_array($decoded)) {
        $metadata = $decoded;
      }
    }
    if (isset($row['actions'])) {
      $decoded = json_decode((string)$row['actions'], true);
      if (is_array($decoded)) {
        $actions = $decoded;
      }
    }
    return [
      'id' => (int)$row['id'],
      'tenant_id' => (int)$row['tenant_id'],
      'user_id' => (int)$row['user_id'],
      'title' => (string)$row['title'],
      'body' => (string)$row['body'],
      'type' => $type,
      'url' => $row['url'] ?? null,
      'priority' => $priority,
      'is_read' => (bool)$row['is_read'],
      'read_at' => $row['read_at'],
      'is_archived' => (bool)$row['is_archived'],
      'archived_at' => $row['archived_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
      'metadata' => $metadata,
      'actions' => $actions,
      'type_key' => isset($row['type_key']) && $row['type_key'] !== null
        ? (string)$row['type_key']
        : (isset($metadata['type_key']) ? (string)$metadata['type_key'] : ''),
      'rule_id' => isset($row['rule_id']) ? (int)$row['rule_id'] : (isset($metadata['rule_id']) ? (int)$metadata['rule_id'] : 0),
    ];
  }
}

if (!function_exists('ppf_notifications_is_rule_template_message')) {
  function ppf_notifications_is_rule_template_message(array $notification, ?array $catalog = null): bool {
    $metadata = isset($notification['metadata']) && is_array($notification['metadata']) ? $notification['metadata'] : [];
    if (empty($metadata['preconfigured'])) {
      return false;
    }
    $typeKey = '';
    if (!empty($notification['type_key'])) {
      $typeKey = (string)$notification['type_key'];
    } elseif (!empty($metadata['type_key'])) {
      $typeKey = (string)$metadata['type_key'];
    }
    if ($typeKey === '') {
      return false;
    }
    if ($catalog === null) {
      $catalog = ppf_notifications_catalog();
    }
    if (empty($catalog[$typeKey])) {
      return false;
    }
    $definition = $catalog[$typeKey];
    $expectedTitle = trim((string)($definition['title'] ?? ''));
    $expectedBody = trim((string)($definition['body'] ?? ''));
    if ($expectedTitle === '' || $expectedBody === '') {
      return false;
    }
    $title = trim((string)($notification['title'] ?? ''));
    $body = trim((string)($notification['body'] ?? ''));
    if ($title !== $expectedTitle || $body !== $expectedBody) {
      return false;
    }
    $ruleId = isset($notification['rule_id']) ? (int)$notification['rule_id'] : 0;
    if ($ruleId > 0) {
      return false;
    }
    return true;
  }
}

if (!function_exists('ppf_notifications_prune_rule_template_message')) {
  function ppf_notifications_prune_rule_template_message(mysqli $conn, int $tenantId, int $userId, int $notificationId): void {
    if ($notificationId <= 0) {
      return;
    }
    if ($stmt = $conn->prepare('DELETE FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND id = ?')) {
      $stmt->bind_param('iii', $tenantId, $userId, $notificationId);
      $stmt->execute();
      $stmt->close();
    }
  }
}

if (!function_exists('ppf_notifications_fetch_recent')) {
  function ppf_notifications_fetch_recent(mysqli $conn, int $userId, int $limit = 10, bool $forBadge = false): array {
    $tenantId = ppf_current_tenant_id();
    ppf_notifications_bootstrap($conn);
    ppf_notifications_seed_defaults($conn, $tenantId, $userId);
    ppf_notifications_prune_archived($conn, $tenantId, $userId);
    $settings = ppf_notifications_settings_get($conn, $tenantId, $userId);
    $limit = max(1, min(25, $limit));
    $stmt = $conn->prepare("SELECT * FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND is_archived = 0 ORDER BY created_at DESC LIMIT ?");
    if (!$stmt) {
      return ['items' => [], 'unread' => 0, 'settings' => $settings];
    }
    $stmt->bind_param('iii', $tenantId, $userId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    $unread = 0;
    while ($row = $res->fetch_assoc()) {
      $type = (string)($row['type'] ?? 'info');
      if (ppf_notifications_should_filter_type($settings, $type, $forBadge)) {
        continue;
      }
      $formatted = ppf_notifications_transform_row($row);
      if (ppf_notifications_is_rule_template_message($formatted)) {
        ppf_notifications_prune_rule_template_message($conn, $tenantId, $userId, (int)$formatted['id']);
        continue;
      }
      if (!$formatted['is_read']) {
        $unread++;
      }
      $items[] = $formatted;
    }
    $stmt->close();
    if ($forBadge) {
      $unread = ppf_notifications_unread_count($conn, $tenantId, $userId, $settings);
    }
    return ['items' => $items, 'unread' => $unread, 'settings' => $settings];
  }
}

if (!function_exists('ppf_notifications_unread_count')) {
  function ppf_notifications_unread_count(mysqli $conn, int $tenantId, int $userId, ?array $settings = null): int {
    ppf_notifications_bootstrap($conn);
    ppf_notifications_seed_defaults($conn, $tenantId, $userId);
    ppf_notifications_prune_archived($conn, $tenantId, $userId);
    if ($settings === null) {
      $settings = ppf_notifications_settings_get($conn, $tenantId, $userId);
    }
    $stmt = $conn->prepare("SELECT id, type_key, type, title, body, metadata, rule_id FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND is_archived = 0 AND is_read = 0");
    if (!$stmt) {
      return 0;
    }
    $stmt->bind_param('ii', $tenantId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    while ($row = $res->fetch_assoc()) {
      $type = (string)($row['type'] ?? 'info');
      if (ppf_notifications_should_filter_type($settings, $type, true)) {
        continue;
      }
      $metadata = [];
      if (!empty($row['metadata'])) {
        $decoded = json_decode((string)$row['metadata'], true);
        if (is_array($decoded)) {
          $metadata = $decoded;
        }
      }
      $notification = [
        'id' => (int)($row['id'] ?? 0),
        'title' => (string)($row['title'] ?? ''),
        'body' => (string)($row['body'] ?? ''),
        'metadata' => $metadata,
        'type_key' => isset($row['type_key']) ? (string)$row['type_key'] : '',
        'rule_id' => isset($row['rule_id']) ? (int)$row['rule_id'] : 0,
      ];
      if (ppf_notifications_is_rule_template_message($notification)) {
        ppf_notifications_prune_rule_template_message($conn, $tenantId, $userId, $notification['id']);
        continue;
      }
      $count++;
    }
    $stmt->close();
    return $count;
  }
}

if (!function_exists('ppf_notifications_staff_can_manage')) {
  function ppf_notifications_staff_can_manage(mysqli $conn, int $staffUserId, int $targetUserId): bool {
    // Stub hook: extend with real supervisory logic as roles evolve.
    if ($staffUserId === $targetUserId) {
      return true;
    }
    return false;
  }
}

if (!function_exists('ppf_notifications_query')) {
  function ppf_notifications_query(mysqli $conn, int $tenantId, int $userId, array $filters, array $options = []): array {
    ppf_notifications_bootstrap($conn);
    ppf_notifications_seed_defaults($conn, $tenantId, $userId);
    ppf_notifications_prune_archived($conn, $tenantId, $userId);
    $settings = ppf_notifications_settings_get($conn, $tenantId, $userId);
    $where = ['tenant_id = ?', 'user_id = ?'];
    $params = [$tenantId, $userId];
    $types = 'ii';

    $status = $filters['status'] ?? null;
    if ($status === 'read') {
      $where[] = 'is_read = 1 AND is_archived = 0';
    } elseif ($status === 'unread') {
      $where[] = 'is_read = 0 AND is_archived = 0';
    } elseif ($status === 'archived') {
      $where[] = 'is_archived = 1';
    } else {
      $where[] = 'is_archived = 0';
    }

    if (!empty($filters['category']) && strtolower((string)$filters['category']) !== 'all') {
      $category = ppf_notifications_valid_category((string)$filters['category']);
      $where[] = 'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.category\')), \'system\') = ?';
      $params[] = $category;
      $types .= 's';
    }

    if (!empty($filters['type']) && isset(ppf_notifications_types()[$filters['type']])) {
      $where[] = 'type = ?';
      $params[] = $filters['type'];
      $types .= 's';
    }

    if ($filters['priority'] !== '' && $filters['priority'] !== null) {
      $priority = (int)$filters['priority'];
      if (isset(ppf_notifications_priorities()[$priority])) {
        $where[] = 'priority = ?';
        $params[] = $priority;
        $types .= 'i';
      }
    }

    if (!empty($filters['date_from'])) {
      $where[] = 'created_at >= ?';
      $params[] = $filters['date_from'];
      $types .= 's';
    }
    if (!empty($filters['date_to'])) {
      $where[] = 'created_at <= ?';
      $params[] = $filters['date_to'];
      $types .= 's';
    }

    if (!empty($filters['q'])) {
      $where[] = "(title LIKE CONCAT('%', ?, '%') OR body LIKE CONCAT('%', ?, '%'))";
      $params[] = $filters['q'];
      $params[] = $filters['q'];
      $types .= 'ss';
    }

    if (!empty($filters['actor'])) {
      if ($filters['actor'] === 'system') {
        $where[] = "(JSON_EXTRACT(metadata, '$.actor') IS NULL OR JSON_EXTRACT(metadata, '$.actor') = 'system')";
      } elseif ($filters['actor'] === 'user') {
        $where[] = "JSON_EXTRACT(metadata, '$.actor') = 'user'";
      }
    }

    $whereSql = implode(' AND ', $where);

    $page = max(1, (int)($options['page'] ?? 1));
    $perPage = (int)($options['per_page'] ?? ($settings['delivery_prefs']['page_size'] ?? 25));
    if (!in_array($perPage, [10,25,50], true)) {
      $perPage = 25;
    }
    $offset = ($page - 1) * $perPage;

    $sort = (string)($options['sort'] ?? $settings['delivery_prefs']['default_sort'] ?? 'created_at:desc');
    $parts = explode(':', $sort);
    $allowed = ['created_at','priority','type','read_at'];
    $col = in_array($parts[0], $allowed, true) ? $parts[0] : 'created_at';
    $dir = strtolower($parts[1] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    if (property_exists($conn, 'ppfFakeNotificationsDriver') && $conn->ppfFakeNotificationsDriver && method_exists($conn, 'setQueryContext')) {
      $conn->setQueryContext([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'filters' => $filters,
        'options' => [
          'page' => $page,
          'per_page' => $perPage,
          'sort' => $col . ':' . strtolower($dir),
        ],
      ]);
    }

    $countSql = "SELECT COUNT(*) AS c FROM notification_messages WHERE $whereSql";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
      return ['data' => [], 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0], 'settings' => $settings];
    }
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $total = 0;
    if ($countRes && $row = $countRes->fetch_assoc()) {
      $total = (int)$row['c'];
    }
    $countStmt->close();

    $sql = "SELECT * FROM notification_messages WHERE $whereSql ORDER BY $col $dir, id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      return ['data' => [], 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total], 'settings' => $settings];
    }
    $bindTypes = $types . 'ii';
    $paramsWithPaging = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($bindTypes, ...$paramsWithPaging);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $templateFiltered = 0;
    while ($row = $res->fetch_assoc()) {
      $transformed = ppf_notifications_transform_row($row);
      if (ppf_notifications_is_rule_template_message($transformed)) {
        ppf_notifications_prune_rule_template_message($conn, $tenantId, $userId, (int)$transformed['id']);
        $templateFiltered++;
        continue;
      }
      if (ppf_notifications_should_filter_type($settings, $transformed['type'])) {
        continue;
      }
      $rows[] = $transformed;
    }
    if ($templateFiltered > 0) {
      $total = max(0, $total - $templateFiltered);
    }
    $stmt->close();

    return [
      'data' => $rows,
      'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
      ],
      'settings' => $settings,
    ];
  }
}

if (!function_exists('ppf_notifications_get')) {
  function ppf_notifications_get(mysqli $conn, int $tenantId, int $userId, int $notificationId, bool $tenantScope = false): ?array {
    ppf_notifications_bootstrap($conn);
    $sql = "SELECT * FROM notification_messages WHERE tenant_id = ? AND id = ?";
    $types = 'ii';
    $params = [$tenantId, $notificationId];
    if (!$tenantScope) {
      $sql .= ' AND user_id = ?';
      $params[] = $userId;
      $types .= 'i';
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      return null;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
      return null;
    }
    $transformed = ppf_notifications_transform_row($row);
    if (ppf_notifications_is_rule_template_message($transformed)) {
      ppf_notifications_prune_rule_template_message($conn, $tenantId, $userId, (int)$transformed['id']);
      return null;
    }
    return $transformed;
  }
}

if (!function_exists('ppf_notifications_delete')) {
  function ppf_notifications_delete(mysqli $conn, int $userId, int $notificationId, bool $hardDelete = false): bool {
    $tenantId = ppf_current_tenant_id();
    ppf_notifications_bootstrap($conn);
    if ($hardDelete) {
      $stmt = $conn->prepare('DELETE FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND id = ?');
      if (!$stmt) {
        return false;
      }
      $stmt->bind_param('iii', $tenantId, $userId, $notificationId);
      $stmt->execute();
      $rows = ppf_notifications_stmt_affected_rows($stmt);
      $stmt->close();
      return $rows > 0;
    }
    $stmt = $conn->prepare('UPDATE notification_messages SET is_archived = 1, archived_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('iii', $tenantId, $userId, $notificationId);
    $stmt->execute();
    $rows = ppf_notifications_stmt_affected_rows($stmt);
    $stmt->close();
    if ($rows > 0) {
      ppf_notifications_log_event($conn, $tenantId, $userId, $notificationId, 'archived', $userId);
    }
    return $rows > 0;
  }
}

if (!function_exists('ppf_notifications_prune_archived')) {
  function ppf_notifications_prune_archived(mysqli $conn, int $tenantId, int $userId, int $days = 30): int {
    ppf_notifications_bootstrap($conn);
    $days = max(1, (int)$days);
    static $pruned = [];
    $key = $tenantId . ':' . $userId . ':' . $days;
    if (isset($pruned[$key])) {
      return 0;
    }
    $pruned[$key] = true;
    $threshold = date('Y-m-d H:i:s', time() - ($days * 86400));
    $stmt = $conn->prepare('DELETE FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND is_archived = 1 AND archived_at IS NOT NULL AND archived_at < ?');
    if (!$stmt) {
      return 0;
    }
    $stmt->bind_param('iis', $tenantId, $userId, $threshold);
    $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
  }
}

if (!function_exists('ppf_notifications_archive_read')) {
  function ppf_notifications_archive_read(mysqli $conn, int $tenantId, int $userId): int {
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare('SELECT id FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND is_archived = 0 AND is_read = 1');
    if (!$stmt) {
      return 0;
    }
    $stmt->bind_param('ii', $tenantId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
      }
      $res->close();
    }
    $stmt->close();
    $updated = 0;
    foreach ($ids as $id) {
      if (ppf_notifications_set_archived($conn, $tenantId, $userId, $id, true)) {
        $updated++;
      }
    }
    return $updated;
  }
}

if (!function_exists('ppf_notifications_set_archived')) {
  function ppf_notifications_set_archived(mysqli $conn, int $tenantId, int $userId, int $notificationId, bool $archived): bool {
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare('UPDATE notification_messages SET is_archived = ?, archived_at = ? WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return false;
    }
    $flag = $archived ? 1 : 0;
    $ts = $archived ? date('Y-m-d H:i:s') : null;
    $stmt->bind_param('isiii', $flag, $ts, $tenantId, $userId, $notificationId);
    $stmt->execute();
    $rows = ppf_notifications_stmt_affected_rows($stmt);
    $stmt->close();
    if ($rows >= 0) {
      ppf_notifications_log_event($conn, $tenantId, $userId, $notificationId, $archived ? 'archived' : 'unarchived', $userId);
    }
    return $rows >= 0;
  }
}

if (!function_exists('ppf_notifications_set_read')) {
  function ppf_notifications_set_read(mysqli $conn, int $userId, int $notificationId, bool $read): bool {
    $tenantId = ppf_current_tenant_id();
    ppf_notifications_bootstrap($conn);
    $current = ppf_notifications_get($conn, $tenantId, $userId, $notificationId);
    if (!$current) {
      return false;
    }
    if ((bool)$current['is_read'] === $read) {
      if ($read && empty($current['read_at'])) {
        if ($stmt = $conn->prepare('UPDATE notification_messages SET read_at = IFNULL(read_at, CURRENT_TIMESTAMP) WHERE tenant_id = ? AND user_id = ? AND id = ?')) {
          $stmt->bind_param('iii', $tenantId, $userId, $notificationId);
          $stmt->execute();
          $stmt->close();
        }
      }
      return true;
    }
    $stmt = $conn->prepare('UPDATE notification_messages SET is_read = ?, read_at = ? WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return false;
    }
    $flag = $read ? 1 : 0;
    $timestamp = $read ? date('Y-m-d H:i:s') : null;
    $stmt->bind_param('isiii', $flag, $timestamp, $tenantId, $userId, $notificationId);
    $stmt->execute();
    $rows = ppf_notifications_stmt_affected_rows($stmt);
    $stmt->close();
    if ($rows > 0) {
      ppf_notifications_log_event($conn, $tenantId, $userId, $notificationId, $read ? 'read' : 'unread', $userId);
      return true;
    }
    return false;
  }
}

if (!function_exists('ppf_notifications_mark_all_read')) {
  function ppf_notifications_mark_all_read(mysqli $conn, int $userId): bool {
    $tenantId = ppf_current_tenant_id();
    ppf_notifications_bootstrap($conn);
    $stmt = $conn->prepare("UPDATE notification_messages SET is_read = 1, read_at = IFNULL(read_at, CURRENT_TIMESTAMP) WHERE tenant_id = ? AND user_id = ? AND is_archived = 0");
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('ii', $tenantId, $userId);
    $stmt->execute();
    $stmt->close();
    return true;
  }
}

if (!function_exists('ppf_notifications_toggle_email')) {
  function ppf_notifications_toggle_email(mysqli $conn, int $userId, int $notificationId, bool $sendEmail): bool {
    $tenantId = ppf_current_tenant_id();
    $notification = ppf_notifications_get($conn, $tenantId, $userId, $notificationId);
    if (!$notification) {
      return false;
    }
    $metadata = $notification['metadata'];
    $metadata['send_email'] = (bool)$sendEmail;
    $json = json_encode($metadata);
    $stmt = $conn->prepare('UPDATE notifications SET metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('siii', $json, $tenantId, $userId, $notificationId);
    $stmt->execute();
    $rows = ppf_notifications_stmt_affected_rows($stmt);
    $stmt->close();
    return $rows >= 0;
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
        'title' => 'Password change alerts',
        'body' => 'We will alert you whenever your password is changed.',
        'type' => 'system',
        'priority' => 1,
        'category' => 'security',
        'immutable' => true,
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'security.passkey_added' => [
        'title' => 'Passkey added alerts',
        'body' => 'Get notified when a passkey is added to your account.',
        'type' => 'system',
        'priority' => 1,
        'category' => 'security',
        'immutable' => true,
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'security.passkey_removed' => [
        'title' => 'Passkey removal alerts',
        'body' => 'Get notified when a passkey is removed from your account.',
        'type' => 'system',
        'priority' => 1,
        'category' => 'security',
        'immutable' => true,
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'security.passkey_renamed' => [
        'title' => 'Passkey renamed alerts',
        'body' => 'Get notified when a passkey on your account is renamed.',
        'type' => 'system',
        'priority' => 1,
        'category' => 'security',
        'immutable' => true,
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'security.account_locked' => [
        'title' => 'Account lock alerts',
        'body' => 'We will message you if your account becomes locked after failed sign-ins.',
        'type' => 'system',
        'priority' => 1,
        'category' => 'security',
        'immutable' => true,
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'billing.sessions_purchased' => [
        'title' => 'Session purchase alerts',
        'body' => 'Receive a confirmation whenever training sessions are purchased for you.',
        'type' => 'success',
        'priority' => 0,
        'category' => 'billing',
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'billing.sessions_refunded' => [
        'title' => 'Session refund alerts',
        'body' => 'Get notified if session credits are refunded or removed.',
        'type' => 'warning',
        'priority' => 0,
        'category' => 'billing',
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'billing.payment_recorded' => [
        'title' => 'Payment recorded alerts',
        'body' => 'Hear when a payment is recorded toward your sessions.',
        'type' => 'info',
        'priority' => 0,
        'category' => 'billing',
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'billing.refund_recorded' => [
        'title' => 'Refund recorded alerts',
        'body' => 'Receive an alert when a refund is recorded on your account.',
        'type' => 'warning',
        'priority' => 0,
        'category' => 'billing',
        'send_email' => true,
        'channels' => ['center' => true, 'email' => true],
        'preconfigured' => true,
      ],
      'workouts.plan_assigned' => [
        'title' => 'Plan assigned alerts',
        'body' => 'Get a heads-up when your trainer assigns you a new plan.',
        'type' => 'info',
        'priority' => 0,
        'category' => 'workouts',
        'send_email' => false,
        'channels' => ['center' => true, 'email' => false],
        'preconfigured' => true,
      ],
    ];
    return $catalog;
  }
}

if (!function_exists('ppf_notifications_reassign_rule_key')) {
  function ppf_notifications_reassign_rule_key(mysqli $conn, int $tenantId, int $userId, int $ruleId, string $oldKey, string $newKey, array $definition): bool {
    if ($newKey === '' || $oldKey === '' || $ruleId <= 0 || $oldKey === $newKey) {
      return false;
    }

    $stmt = $conn->prepare('SELECT channels, metadata, category FROM notification_rules WHERE tenant_id = ? AND user_id = ? AND id = ? LIMIT 1');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('iii', $tenantId, $userId, $ruleId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
      return false;
    }

    $channels = ppf_notifications_normalize_channels(isset($row['channels']) ? json_decode((string)$row['channels'], true) : null, ['center' => true, 'email' => false]);
    $metadata = [];
    if (!empty($row['metadata'])) {
      $decoded = json_decode((string)$row['metadata'], true);
      if (is_array($decoded)) {
        $metadata = $decoded;
      }
    }

    $metadata['type_key'] = $newKey;
    $category = ppf_notifications_valid_category((string)($row['category'] ?? ($definition['category'] ?? 'system')));
    $metadata['category'] = $category;
    if (!isset($metadata['channels']) || !is_array($metadata['channels'])) {
      $metadata['channels'] = $channels;
    }
    $metadata['send_email'] = !empty($channels['email']);
    if (!empty($definition['preconfigured'])) {
      $metadata['preconfigured'] = true;
    }
    if (!empty($definition['immutable'])) {
      $metadata['immutable'] = true;
    } elseif (isset($metadata['immutable']) && empty($definition['immutable'])) {
      unset($metadata['immutable']);
    }

    $jsonMetadata = json_encode($metadata);
    if ($jsonMetadata === false) {
      return false;
    }

    $stmt = $conn->prepare('UPDATE notification_rules SET type_key = ?, category = ?, metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?');
    if (!$stmt) {
      return false;
    }
    $stmt->bind_param('sssiii', $newKey, $category, $jsonMetadata, $tenantId, $userId, $ruleId);
    $stmt->execute();
    $ok = ppf_notifications_stmt_affected_rows($stmt) >= 0;
    $stmt->close();
    if (!$ok) {
      return false;
    }

    try {
      $state = ppf_notification_rule_state_get($conn, $tenantId, $userId, $oldKey);
    } catch (Throwable $e) {
      $state = null;
    }
    if ($state) {
      ppf_notification_rule_state_put($conn, $tenantId, $userId, $newKey, $state);
      ppf_notification_rule_state_delete($conn, $tenantId, $userId, $oldKey);
    }

    try {
      if ($stmt = $conn->prepare('UPDATE notification_messages SET type_key = ? WHERE tenant_id = ? AND user_id = ? AND type_key = ?')) {
        $stmt->bind_param('siis', $newKey, $tenantId, $userId, $oldKey);
        $stmt->execute();
        $stmt->close();
      }
    } catch (Throwable $e) {
      // Ignore message update failures; rule reassignment succeeded.
    }

    return true;
  }
}

if (!function_exists('ppf_notifications_seed_defaults')) {
  function ppf_notifications_seed_defaults(mysqli $conn, int $tenantId, int $userId): void {
    if ($userId <= 0) {
      return;
    }
    ppf_notifications_bootstrap($conn);
    $catalog = ppf_notifications_catalog();
    $preconfigured = [];
    foreach ($catalog as $typeKey => $definition) {
      if (!empty($definition['preconfigured'])) {
        $preconfigured[$typeKey] = $definition;
      }
    }
    if (empty($preconfigured)) {
      return;
    }

    $existing = [];
    $existingByTitle = [];
    if ($stmt = $conn->prepare('SELECT id, type_key, title FROM notification_rules WHERE tenant_id = ? AND user_id = ?')) {
      $stmt->bind_param('ii', $tenantId, $userId);
      $stmt->execute();
      if ($res = $stmt->get_result()) {
        while ($row = $res->fetch_assoc()) {
          $key = (string)($row['type_key'] ?? '');
          if ($key !== '') {
            $existing[$key] = (int)$row['id'];
          }
          $titleKey = strtolower(trim((string)($row['title'] ?? '')));
          if ($titleKey !== '') {
            $existingByTitle[$titleKey] = [
              'id' => (int)($row['id'] ?? 0),
              'type_key' => $key,
            ];
          }
        }
      }
      $stmt->close();
    }

    foreach ($preconfigured as $typeKey => $definition) {
      if (!isset($existing[$typeKey])) {
        $titleKey = strtolower(trim((string)($definition['title'] ?? '')));
        if ($titleKey !== '' && isset($existingByTitle[$titleKey])) {
          $match = $existingByTitle[$titleKey];
          $ruleId = (int)($match['id'] ?? 0);
          $currentKey = (string)($match['type_key'] ?? '');
          if ($ruleId > 0 && $currentKey !== '' && $currentKey !== $typeKey) {
            if (ppf_notifications_reassign_rule_key($conn, $tenantId, $userId, $ruleId, $currentKey, $typeKey, $definition)) {
              unset($existing[$currentKey]);
              $existing[$typeKey] = $ruleId;
              continue;
            }
          }
        }
      }
      if (isset($existing[$typeKey])) {
        continue;
      }
      $channels = ['center' => true, 'email' => false];
      if (!empty($definition['channels']) && is_array($definition['channels'])) {
        $channels = array_merge($channels, $definition['channels']);
      } elseif (!empty($definition['send_email'])) {
        $channels['email'] = true;
      }
      $sendEmail = isset($definition['send_email']) ? (bool)$definition['send_email'] : !empty($channels['email']);
      $immutable = !empty($definition['immutable']);
      $metadata = ['preconfigured' => true];
      if ($immutable) {
        $metadata['immutable'] = true;
      }
      $jsonChannels = json_encode($channels);
      $jsonMetadata = json_encode($metadata);
      try {
        if ($stmt = $conn->prepare('INSERT INTO notification_rules (tenant_id, user_id, type_key, title, body, category, channels, send_email, priority, immutable, metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?)')) {
          $title = $definition['title'] ?? 'Notification';
          $body = $definition['body'] ?? '';
          $category = ppf_notifications_valid_category((string)($definition['category'] ?? 'system'));
          $priority = (int)($definition['priority'] ?? 0);
          $stmt->bind_param('iisssssiiis', $tenantId, $userId, $typeKey, $title, $body, $category, $jsonChannels, $sendEmail ? 1 : 0, $priority, $immutable ? 1 : 0, $jsonMetadata);
          $stmt->execute();
          $stmt->close();
        }
      } catch (Throwable $e) {
        continue;
      }
    }
  }
}

if (!function_exists('ppf_notifications_upsert')) {
  function ppf_notifications_upsert(mysqli $conn, int $userId, array $data, ?int $notificationId = null): ?int {
    $tenantId = ppf_current_tenant_id();
    ppf_notifications_bootstrap($conn);
    $title = trim((string)($data['title'] ?? ''));
    $body = trim((string)($data['body'] ?? ($data['message'] ?? '')));
    if ($title === '' && $body === '') {
      return null;
    }
    $type = strtolower((string)($data['type'] ?? 'info'));
    if (!isset(ppf_notifications_types()[$type])) {
      $type = 'info';
    }
    $priority = isset($data['priority']) ? (int)$data['priority'] : 0;
    if (!isset(ppf_notifications_priorities()[$priority])) {
      $priority = 0;
    }
    $url = trim((string)($data['url'] ?? ''));
    if ($url === '') {
      $url = null;
    }
    $metadata = [];
    if (!empty($data['metadata']) && is_array($data['metadata'])) {
      $metadata = $data['metadata'];
    }
    if (isset($data['category'])) {
      $metadata['category'] = ppf_notifications_valid_category((string)$data['category']);
    }
    if (isset($data['type_key'])) {
      $metadata['type_key'] = (string)$data['type_key'];
    }
    if (isset($data['send_email'])) {
      $metadata['send_email'] = (bool)$data['send_email'];
    }
    if (isset($data['immutable'])) {
      $metadata['immutable'] = (bool)$data['immutable'];
    }
    if (isset($data['channels']) && is_array($data['channels'])) {
      $channels = [
        'center' => true,
        'email' => false,
      ];
      foreach ($data['channels'] as $channel => $enabled) {
        $channels[$channel] = (bool)$enabled;
      }
      $metadata['channels'] = $channels;
      if (!isset($metadata['send_email'])) {
        $metadata['send_email'] = !empty($channels['email']);
      }
    }
    $jsonMetadata = $metadata ? json_encode($metadata) : null;
    $ruleId = null;
    if (isset($data['rule_id'])) {
      $ruleId = (int)$data['rule_id'] ?: null;
    }
    $typeKey = null;
    if (isset($data['type_key'])) {
      $typeKey = (string)$data['type_key'];
    } elseif (isset($metadata['type_key'])) {
      $typeKey = (string)$metadata['type_key'];
    }

    $ruleParam = ($ruleId && $ruleId > 0) ? (int)$ruleId : 0;

    if ($notificationId !== null) {
      $stmt = $conn->prepare('UPDATE notification_messages SET title = ?, body = ?, type = ?, url = ?, priority = ?, metadata = ?, type_key = ?, rule_id = NULLIF(?,0), updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND user_id = ? AND id = ?');
      if (!$stmt) {
        return null;
      }
      $stmt->bind_param('ssssissiiii', $title, $body, $type, $url, $priority, $jsonMetadata, $typeKey, $ruleParam, $tenantId, $userId, $notificationId);
      $stmt->execute();
      $ok = ppf_notifications_stmt_affected_rows($stmt) >= 0;
      $stmt->close();
      if ($ok) {
        ppf_notifications_log_event($conn, $tenantId, $userId, $notificationId, 'updated', $userId);
        return $notificationId;
      }
      return null;
    }

    $stmt = $conn->prepare('INSERT INTO notification_messages (tenant_id, user_id, rule_id, type_key, title, body, type, url, priority, metadata) VALUES (?,?,NULLIF(?,0),?,?,?,?,?,?,?)');
    if (!$stmt) {
      return null;
    }
    $stmt->bind_param('iiisssssis', $tenantId, $userId, $ruleParam, $typeKey, $title, $body, $type, $url, $priority, $jsonMetadata);
    $stmt->execute();
    $id = ppf_notifications_stmt_insert_id($stmt);
    $stmt->close();
    if ($id > 0) {
      ppf_notifications_log_event($conn, $tenantId, $userId, $id, 'created', $userId);
      return (int)$id;
    }
    return null;
  }
}

if (!function_exists('ppf_notifications_record')) {
  function ppf_notifications_record(mysqli $conn, int $userId, array $data): ?int {
    $tenantId = ppf_current_tenant_id();
    ppf_notifications_seed_defaults($conn, $tenantId, $userId);
    $catalog = ppf_notifications_catalog();
    $payloadData = $data;
    $typeKey = '';
    if (isset($payloadData['type_key'])) {
      $typeKey = (string)$payloadData['type_key'];
    } elseif (isset($payloadData['type']) && is_string($payloadData['type']) && isset($catalog[$payloadData['type']])) {
      $typeKey = (string)$payloadData['type'];
      unset($payloadData['type']);
    }
    if ($typeKey === '') {
      $typeKey = 'custom.manual';
    }
    $defaults = $catalog[$typeKey] ?? [];
    $rule = ppf_notification_rules_get_by_key($conn, $tenantId, $userId, $typeKey);
    $channelFallback = ['center' => true, 'email' => false];
    $category = 'system';
    $ruleImmutable = false;
    if ($rule) {
      $channelFallback = ppf_notifications_normalize_channels($rule['channels'] ?? null, $channelFallback);
      $category = $rule['category'] ?? $category;
      $ruleImmutable = !empty($rule['immutable']);
    } elseif (!empty($defaults['channels']) && is_array($defaults['channels'])) {
      $channelFallback = ppf_notifications_normalize_channels($defaults['channels'], $channelFallback);
      if (!empty($defaults['category'])) {
        $category = (string)$defaults['category'];
      }
    } else {
      if (!empty($defaults['category'])) {
        $category = (string)$defaults['category'];
      }
      if (!empty($defaults['send_email'])) {
        $channelFallback['email'] = true;
      }
    }

    if (!$rule && $typeKey !== '') {
      try {
        $stateChannels = ppf_notification_rule_state_get($conn, $tenantId, $userId, $typeKey);
      } catch (Throwable $e) {
        $stateChannels = null;
      }
      if ($stateChannels) {
        $channelFallback = ppf_notifications_normalize_channels($stateChannels, $channelFallback);
      }
    }

    $payload = array_merge($defaults, $payloadData);

    $channels = $channelFallback;
    if (isset($payload['channels']) && is_array($payload['channels'])) {
      $channels = ppf_notifications_normalize_channels($payload['channels'], $channelFallback);
    }
    if ($ruleImmutable) {
      $channels['center'] = true;
      $channels['email'] = true;
    }
    $isCenterEnabled = !empty($channels['center']);
    $isEmailEnabled = !empty($channels['email']);
    if (!$isCenterEnabled && !$isEmailEnabled) {
      return null;
    }

    $payload['channels'] = $channels;

    if (!isset($payload['category']) || $payload['category'] === '') {
      $payload['category'] = $category;
    }

    if (array_key_exists('send_email', $payload)) {
      $payload['send_email'] = (bool)$payload['send_email'];
    }
    $payload['send_email'] = $isEmailEnabled;

    if ($ruleImmutable) {
      $payload['immutable'] = true;
      $payload['send_email'] = true;
    } elseif (isset($defaults['immutable']) && !isset($payload['immutable'])) {
      $payload['immutable'] = (bool)$defaults['immutable'];
    }
    if (!isset($payload['type']) && isset($payload['type_label'])) {
      $payload['type'] = $payload['type_label'];
    }
    if (isset($payload['message']) && !isset($payload['body'])) {
      $payload['body'] = $payload['message'];
    }
    $metadata = $payload['metadata'] ?? [];
    if (!is_array($metadata)) {
      $metadata = [];
    }
    $metadata = array_merge($metadata, [
      'type_key' => $typeKey,
      'category' => ppf_notifications_valid_category((string)$payload['category']),
      'channels' => $channels,
      'send_email' => $payload['send_email'],
    ]);
    if (!empty($payload['immutable'])) {
      $metadata['immutable'] = true;
    }
    if ($rule && !empty($rule['metadata']['preconfigured'])) {
      $metadata['preconfigured'] = true;
    }

    $payload['metadata'] = $metadata;
    $payload['type_key'] = $typeKey;
    if ($rule) {
      $payload['rule_id'] = $rule['id'];
    }

    $id = ppf_notifications_upsert($conn, $userId, $payload, $data['id'] ?? null);
    if ($id) {
      $tenantId = ppf_current_tenant_id();
      ppf_notifications_log_event($conn, $tenantId, $userId, $id, 'delivered', $data['actor_user_id'] ?? null, ['type_key' => $typeKey]);
    }
    return $id;
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
