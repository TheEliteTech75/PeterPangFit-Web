<?php
// totp.php — TOTP helpers + safe schema ensure

// ---------- Random/Encoding helpers ----------
if (!function_exists('ppf_random_hex')) {
  function ppf_random_hex(int $bytes=32): string { return bin2hex(random_bytes($bytes)); }
}

if (!function_exists('ppf_base32_encode')) {
  function ppf_base32_encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    if (strlen($bits) % 5 !== 0) $bits .= str_repeat('0', 5 - (strlen($bits) % 5));
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec($chunk)];
    while (strlen($out) % 8 !== 0) $out .= '=';
    return $out;
  }
}

if (!function_exists('ppf_base32_decode')) {
  function ppf_base32_decode(string $str): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $str = strtoupper($str);
    $str = preg_replace('/[^A-Z2-7=]/', '', $str);
    $pad = substr_count($str, '=');
    $str = rtrim($str, '=');
    $bits = '';
    $len = strlen($str);
    for ($i=0; $i<$len; $i++) {
      $v = strpos($alphabet, $str[$i]);
      if ($v === false) continue;
      $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    if ($pad) $bits = substr($bits, 0, strlen($bits) - $pad*5);
    $out = '';
    foreach (str_split($bits, 8) as $oct) {
      if (strlen($oct) === 8) $out .= chr(bindec($oct));
    }
    return $out;
  }
}

// ---------- TOTP (RFC 6238) ----------
if (!function_exists('ppf_hmac_sha1')) {
  function ppf_hmac_sha1(string $key, string $data): string {
    return hash_hmac('sha1', $data, $key, true);
  }
}

if (!function_exists('ppf_hotp')) {
  function ppf_hotp(string $secret_b32, int $counter, int $digits=6): string {
    $key = ppf_base32_decode($secret_b32);
    $binCounter = pack('N*', 0) . pack('N*', $counter); // 64-bit BE
    $hmac = ppf_hmac_sha1($key, $binCounter);
    $offset = ord($hmac[19]) & 0x0F;
    $code = (
      ((ord($hmac[$offset])   & 0x7F) << 24) |
      ((ord($hmac[$offset+1]) & 0xFF) << 16) |
      ((ord($hmac[$offset+2]) & 0xFF) <<  8) |
      ( ord($hmac[$offset+3]) & 0xFF)
    ) % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('ppf_totp')) {
  function ppf_totp(string $secret_b32, int $time = null, int $period=30, int $digits=6): string {
    if ($time === null) $time = time();
    $counter = (int) floor($time / $period);
    return ppf_hotp($secret_b32, $counter, $digits);
  }
}

/**
 * Try to match a TOTP code across a window of steps and return the offset.
 * Returns an integer offset (e.g., 0, -1, +2) when matched, or null if no match.
 */
if (!function_exists('ppf_totp_match_offset')) {
  function ppf_totp_match_offset(string $secret_b32, string $code, int $period=30, int $digits=6, int $window=8): ?int {
    $code = preg_replace('/\D/', '', $code ?? '');
    if ($code === '' || strlen($code) > 8) return null;
    $now = time();
    for ($i=-$window; $i <= $window; $i++) {
      if (hash_equals(ppf_totp($secret_b32, $now + ($i*$period), $period, $digits), $code)) {
        return $i;
      }
    }
    return null;
  }
}

/** Convenience boolean wrapper. Default window widened to ±8 (±240s). */
if (!function_exists('ppf_totp_verify')) {
  function ppf_totp_verify(string $secret_b32, string $code, int $period=30, int $digits=6, int $window=8): bool {
    return ppf_totp_match_offset($secret_b32, $code, $period, $digits, $window) !== null;
  }
}

if (!function_exists('ppf_totp_new_secret')) {
  function ppf_totp_new_secret(int $bytes = 20): string {
    return ppf_base32_encode(random_bytes($bytes));
  }
}

if (!function_exists('ppf_otpauth_url')) {
  function ppf_otpauth_url(string $issuer, string $accountName, string $secret_b32, int $period=30, int $digits=6): string {
    $label  = rawurlencode($issuer) . ':' . rawurlencode($accountName);
    $params = http_build_query([
      'secret'    => $secret_b32,
      'issuer'    => $issuer,
      'period'    => $period,
      'digits'    => $digits,
      'algorithm' => 'SHA1',
    ]);
    return "otpauth://totp/{$label}?{$params}";
  }
}

// ---------- DB helpers ----------
if (!function_exists('ppf_column_exists')) {
  function ppf_column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS c
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return (int)($row['c'] ?? 0) > 0;
  }
}

/** Safe, idempotent schema ensure (ignores 1060 duplicate column). */
if (!function_exists('ppf_ensure_twofa_columns')) {
  function ppf_ensure_twofa_columns(mysqli $conn): void {
    $columns = [
      'twofa_email_enabled' => "ALTER TABLE `users` ADD COLUMN `twofa_email_enabled` TINYINT(1) NOT NULL DEFAULT 0",
      'twofa_app_enabled'   => "ALTER TABLE `users` ADD COLUMN `twofa_app_enabled`   TINYINT(1) NOT NULL DEFAULT 0",
      'twofa_secret'        => "ALTER TABLE `users` ADD COLUMN `twofa_secret`        VARCHAR(64) NULL",
      'twofa_email_code'    => "ALTER TABLE `users` ADD COLUMN `twofa_email_code`    VARCHAR(16) NULL",
      'twofa_email_expires' => "ALTER TABLE `users` ADD COLUMN `twofa_email_expires` DATETIME NULL",
      'twofa_app_token'     => "ALTER TABLE `users` ADD COLUMN `twofa_app_token`     VARCHAR(128) NULL",
      'twofa_app_expires'   => "ALTER TABLE `users` ADD COLUMN `twofa_app_expires`   DATETIME NULL",
      // New: passkey add verification via email code
      'passkey_email_code'    => "ALTER TABLE `users` ADD COLUMN `passkey_email_code`    VARCHAR(16) NULL",
      'passkey_email_expires' => "ALTER TABLE `users` ADD COLUMN `passkey_email_expires` DATETIME NULL",
    ];
    foreach ($columns as $col => $ddl) {
      if (ppf_column_exists($conn, 'users', $col)) continue;
      try { $conn->query($ddl); }
      catch (mysqli_sql_exception $e) { if ((int)$e->getCode() !== 1060) throw $e; }
    }
  }
}