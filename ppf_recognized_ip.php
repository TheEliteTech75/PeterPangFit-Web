<?php
// ppf_recognized_ip.php — invisible "recognized locations" (per-IP) for users without 2FA
// Stores IPv4/IPv6 in VARBINARY(16) (ip_bin) and keeps IPs recognized for 90 days.
// Backwards-compatible with legacy tables that also have `ip_address` (VARCHAR) NOT NULL.
// Logs via ppf_log() when available.

if (!function_exists('ppf_ip_to_bin')) {
  function ppf_ip_to_bin(string $ip): string {
    $packed = @inet_pton($ip);
    if ($packed === false || $packed === null) {
      $packed = inet_pton('127.0.0.1'); // fallback
    }
    return $packed;
  }
}

if (!function_exists('ppf_bin_to_ip')) {
  function ppf_bin_to_ip(string $bin): string {
    $ip = @inet_ntop($bin);
    return $ip !== false ? $ip : '127.0.0.1';
  }
}

if (!function_exists('ppf_db_col_exists')) {
  function ppf_db_col_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
  }
}

if (!function_exists('ppf_db_index_exists')) {
  function ppf_db_index_exists(mysqli $conn, string $table, string $index): bool {
    $sql = "SHOW INDEX FROM `$table` WHERE Key_name = ?";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param("s", $index);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
  }
}

if (!function_exists('ppf_silently')) {
  function ppf_silently(mysqli $conn, string $sql): void {
    // Best-effort statement; ignore errors (older MySQL may lack IF NOT EXISTS, etc.)
    @$conn->query($sql);
  }
}

if (!function_exists('ppf_rec_ips_ensure_table')) {
  function ppf_rec_ips_ensure_table(mysqli $conn): void {
    // Baseline schema (modern)
    ppf_silently($conn, "
      CREATE TABLE IF NOT EXISTS user_recognized_ips (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip_bin VARBINARY(16) NOT NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_ip (user_id, ip_bin),
        KEY idx_user_last_seen (user_id, last_seen_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Ensure ip_bin exists (for legacy tables)
    if (!ppf_db_col_exists($conn, 'user_recognized_ips', 'ip_bin')) {
      ppf_silently($conn, "ALTER TABLE user_recognized_ips ADD COLUMN ip_bin VARBINARY(16) NULL AFTER user_id");
    }

    // Timestamps if missing
    if (!ppf_db_col_exists($conn, 'user_recognized_ips', 'last_seen_at')) {
      ppf_silently($conn, "ALTER TABLE user_recognized_ips ADD COLUMN last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    if (!ppf_db_col_exists($conn, 'user_recognized_ips', 'created_at')) {
      ppf_silently($conn, "ALTER TABLE user_recognized_ips ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }

    // If legacy ip_address exists, try to migrate ip_bin from it
    if (ppf_db_col_exists($conn, 'user_recognized_ips', 'ip_address')) {
      ppf_silently($conn, "
        UPDATE user_recognized_ips
        SET ip_bin = INET6_ATON(ip_address)
        WHERE (ip_bin IS NULL OR ip_bin = '')
          AND ip_address IS NOT NULL AND ip_address <> ''
      ");
    }

    // Enforce NOT NULL on ip_bin (best effort)
    ppf_silently($conn, "ALTER TABLE user_recognized_ips MODIFY COLUMN ip_bin VARBINARY(16) NOT NULL");

    // Ensure indexes
    if (!ppf_db_index_exists($conn, 'user_recognized_ips', 'uq_user_ip')) {
      ppf_silently($conn, "ALTER TABLE user_recognized_ips ADD UNIQUE KEY uq_user_ip (user_id, ip_bin)");
    }
    if (!ppf_db_index_exists($conn, 'user_recognized_ips', 'idx_user_last_seen')) {
      ppf_silently($conn, "ALTER TABLE user_recognized_ips ADD KEY idx_user_last_seen (user_id, last_seen_at)");
    }
  }
}

if (!function_exists('ppf_rec_ips_prune')) {
  // Delete entries older than 90 days (call at login time)
  function ppf_rec_ips_prune(mysqli $conn, int $userId): void {
    ppf_rec_ips_ensure_table($conn);
    if ($st = $conn->prepare("DELETE FROM user_recognized_ips WHERE user_id=? AND last_seen_at < (NOW() - INTERVAL 90 DAY)")) {
      $st->bind_param("i", $userId);
      $st->execute();
      $deleted = $st->affected_rows;
      $st->close();
      if ($deleted > 0 && function_exists('ppf_log')) {
        ppf_log($conn, $userId, null, null, 'recognized_ip_pruned', 'security', null, "count={$deleted}");
      }
    }
  }
}

if (!function_exists('ppf_rec_ips_is_recognized')) {
  // Is the given IP recognized in the last 90 days?
  function ppf_rec_ips_is_recognized(mysqli $conn, int $userId, string $ip): bool {
    ppf_rec_ips_ensure_table($conn);
    $bin = ppf_ip_to_bin($ip);
    $ok = false;
    if ($st = $conn->prepare("SELECT 1 FROM user_recognized_ips WHERE user_id=? AND ip_bin=? AND last_seen_at >= (NOW() - INTERVAL 90 DAY) LIMIT 1")) {
      $st->bind_param("is", $userId, $bin);
      $st->execute();
      $st->store_result();
      $ok = $st->num_rows > 0;
      $st->close();
    }
    return $ok;
  }
}

if (!function_exists('ppf_rec_ips_touch')) {
  // Upsert the IP as recognized (update last_seen_at or insert); fills legacy `ip_address` when present.
  function ppf_rec_ips_touch(mysqli $conn, int $userId, string $ip): void {
    ppf_rec_ips_ensure_table($conn);
    $bin = ppf_ip_to_bin($ip);
    $hasIpAddress = ppf_db_col_exists($conn, 'user_recognized_ips', 'ip_address'); // legacy column?

    // Update if exists
    if ($hasIpAddress) {
      if ($u = $conn->prepare("UPDATE user_recognized_ips SET last_seen_at=NOW(), ip_address=? WHERE user_id=? AND ip_bin=?")) {
        $u->bind_param("sis", $ip, $userId, $bin);
        $u->execute();
        $rows = $u->affected_rows;
        $u->close();
        if ($rows > 0) {
          if (function_exists('ppf_log')) ppf_log($conn, $userId, null, null, 'recognized_ip_refreshed', 'security', null, null);
          return;
        }
      }
    } else {
      if ($u = $conn->prepare("UPDATE user_recognized_ips SET last_seen_at=NOW() WHERE user_id=? AND ip_bin=?")) {
        $u->bind_param("is", $userId, $bin);
        $u->execute();
        $rows = $u->affected_rows;
        $u->close();
        if ($rows > 0) {
          if (function_exists('ppf_log')) ppf_log($conn, $userId, null, null, 'recognized_ip_refreshed', 'security', null, null);
          return;
        }
      }
    }

    // Insert if not existing
    if ($hasIpAddress) {
      if ($i = $conn->prepare("INSERT INTO user_recognized_ips (user_id, ip_bin, last_seen_at, ip_address) VALUES (?, ?, NOW(), ?)")) {
        $i->bind_param("iss", $userId, $bin, $ip);
        $i->execute();
        $i->close();
        if (function_exists('ppf_log')) ppf_log($conn, $userId, null, null, 'recognized_ip_added', 'security', null, null);
      }
    } else {
      if ($i = $conn->prepare("INSERT INTO user_recognized_ips (user_id, ip_bin, last_seen_at) VALUES (?, ?, NOW())")) {
        $i->bind_param("is", $userId, $bin);
        $i->execute();
        $i->close();
        if (function_exists('ppf_log')) ppf_log($conn, $userId, null, null, 'recognized_ip_added', 'security', null, null);
      }
    }
  }
}