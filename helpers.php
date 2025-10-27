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
