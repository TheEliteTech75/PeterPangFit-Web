<?php
// ppf_trusted.php — trusted devices (30-day 2FA skip) helpers

if (!function_exists('ppf_trusted_cookie_name')) {
  function ppf_trusted_cookie_name(): string { return 'ppf_td'; }
}

if (!function_exists('ppf_td_ensure_table')) {
  function ppf_td_ensure_table(mysqli $conn): void {
    @$conn->query("
      CREATE TABLE IF NOT EXISTS trusted_devices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARBINARY(24) NOT NULL,              -- 12 bytes hex -> 24 chars
        validator_hash VARBINARY(64) NOT NULL,        -- sha256 hex -> 64 chars
        device_name VARCHAR(100) NOT NULL,
        user_agent VARCHAR(255) NULL,
        ip_address VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uq_selector (selector),
        KEY idx_user (user_id),
        KEY idx_expires (expires_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
  }
}

if (!function_exists('ppf_td_set_cookie')) {
  function ppf_td_set_cookie(string $selectorHex, string $validatorHex): void {
    $name  = ppf_trusted_cookie_name();
    $value = $selectorHex . ':' . $validatorHex;
    $expire= time() + 30*24*3600;
    $secure= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($name, $value, [
      'expires'  => $expire,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}

if (!function_exists('ppf_td_clear_cookie')) {
  function ppf_td_clear_cookie(): void {
    $name  = ppf_trusted_cookie_name();
    $secure= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($name, '', [
      'expires'  => time() - 3600,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}

if (!function_exists('ppf_td_add')) {
  function ppf_td_add(mysqli $conn, int $userId, string $deviceName, ?string $ua, ?string $ip): void {
    ppf_td_ensure_table($conn);
    $selector  = bin2hex(random_bytes(12)); // 12B -> 24 hex chars
    $validator = bin2hex(random_bytes(32)); // 32B -> 64 hex chars
    $hash      = hash('sha256', $validator);
    $expires   = date('Y-m-d H:i:s', time() + 30*24*3600);

    if ($st = $conn->prepare("INSERT INTO trusted_devices (user_id, selector, validator_hash, device_name, user_agent, ip_address, expires_at) VALUES (?,?,?,?,?,?,?)")) {
      $st->bind_param("issssss", $userId, $selector, $hash, $deviceName, $ua, $ip, $expires);
      $st->execute();
      $st->close();
    }
    // set cookie
    ppf_td_set_cookie($selector, $validator);

    if (function_exists('ppf_log')) {
      // Keep 'trusted_device' here — only the *used* event must be 'auth'
      ppf_log($conn, $userId, null, null, 'trusted_device_added', 'trusted_device', null, 'name='.$deviceName);
    }
  }
}

/** Local helper: lookup user email (needed during login flow before session email is set) */
if (!function_exists('ppf_td_lookup_user_email')) {
  function ppf_td_lookup_user_email(mysqli $conn, int $userId): ?string {
    if ($userId <= 0) return null;
    if (!$st = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1")) return null;
    $st->bind_param("i", $userId);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
    return $row ? (string)$row['email'] : null;
  }
}

if (!function_exists('ppf_td_validate_for_user')) {
  /**
   * Validate cookie for this user, refresh last_used, and return true if valid.
   * Also logs use/expiry and prunes if expired.
   *
   * On success, writes:
   *   action      = 'trusted_device_used'
   *   target_type = 'auth'
   *   details     = "UserID=<id>;User=<email>;TrustedDevice=<ID>:<DeviceName>;<IPAddress>"
   */
  function ppf_td_validate_for_user(mysqli $conn, int $userId): bool {
    ppf_td_ensure_table($conn);
    $val = $_COOKIE[ppf_trusted_cookie_name()] ?? '';
    if (!$val || !str_contains($val, ':')) return false;

    [$selector, $validator] = explode(':', $val, 2);
    if (!preg_match('/^[a-f0-9]{24}$/i', $selector) || !preg_match('/^[a-f0-9]{64}$/i', $validator)) return false;

    // Need device_name for the details string
    $sql = "SELECT id, validator_hash, device_name, expires_at
              FROM trusted_devices
             WHERE user_id = ? AND selector = ?
             LIMIT 1";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param("is", $userId, $selector);
    $st->execute();
    $rs  = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
    if (!$row) return false;

    $expiresTs = strtotime((string)$row['expires_at']);
    if (!$expiresTs || $expiresTs <= time()) {
      // expired: delete + log + clear cookie
      if ($d = $conn->prepare("DELETE FROM trusted_devices WHERE id=?")) { $d->bind_param("i", $row['id']); $d->execute(); $d->close(); }
      if (function_exists('ppf_log')) { ppf_log($conn, $userId, null, null, 'trusted_device_expired', 'trusted_device', null, null); }
      ppf_td_clear_cookie();
      return false;
    }

    $hashOk = hash_equals((string)$row['validator_hash'], hash('sha256', $validator));
    if (!$hashOk) return false;

    // refresh last_used
    if ($u = $conn->prepare("UPDATE trusted_devices SET last_used_at=NOW() WHERE id=?")) {
      $u->bind_param("i", $row['id']); $u->execute(); $u->close();
    }

    // Build exact details + ensure email is present even if session doesn't have it yet
    $actorEmail = $_SESSION['email'] ?? ppf_td_lookup_user_email($conn, $userId) ?? '';
    $ip         = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $deviceId   = (int)$row['id'];
    $deviceName = (string)($row['device_name'] ?? 'Unknown');
    $details    = "UserID={$userId};User={$actorEmail};TrustedDevice={$deviceId}:{$deviceName};{$ip}";

    if (function_exists('ppf_log')) {
      // *** REQUIRED FIX ***
      // target_type must be 'auth'; include email and details as specified
      ppf_log($conn, $userId, $actorEmail, null, 'trusted_device_used', 'auth', null, $details);
    }

    return true;
  }
}

if (!function_exists('ppf_td_list_for_user')) {
  function ppf_td_list_for_user(mysqli $conn, int $userId): array {
    ppf_td_ensure_table($conn);
    // prune this user's expired rows (and log)
    @$conn->query("DELETE FROM trusted_devices WHERE user_id={$userId} AND expires_at < NOW()");
    // NOTE: bulk delete doesn’t log each row; individual expiry gets logged at validate time
    $rows = [];
    if ($st = $conn->prepare("SELECT id, device_name, created_at, last_used_at, expires_at FROM trusted_devices WHERE user_id=? ORDER BY created_at DESC")) {
      $st->bind_param("i", $userId); $st->execute();
      $rs = $st->get_result(); while ($r = $rs->fetch_assoc()) $rows[] = $r; $st->close();
    }
    return $rows;
  }
}

if (!function_exists('ppf_td_rename')) {
  function ppf_td_rename(mysqli $conn, int $userId, int $id, string $name): bool {
    ppf_td_ensure_table($conn);
    if (!$st = $conn->prepare("UPDATE trusted_devices SET device_name=? WHERE id=? AND user_id=?")) return false;
    $st->bind_param("sii", $name, $id, $userId);
    $ok = $st->execute(); $st->close();
    return $ok;
  }
}

if (!function_exists('ppf_td_delete')) {
  function ppf_td_delete(mysqli $conn, int $userId, int $id): bool {
    ppf_td_ensure_table($conn);
    // Optional: if this delete matches the cookie's selector, clear cookie too
    $ok = false;
    if ($st = $conn->prepare("DELETE FROM trusted_devices WHERE id=? AND user_id=?")) {
      $st->bind_param("ii", $id, $userId);
      $ok = $st->execute(); $st->close();
    }
    if ($ok && function_exists('ppf_log')) {
      ppf_log($conn, $userId, null, null, 'trusted_device_deleted', 'trusted_device', null, null);
    }
    return $ok;
  }
}