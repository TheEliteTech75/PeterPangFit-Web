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
