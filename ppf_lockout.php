<?php
// ppf_lockout.php — Account lockout helpers (per-user counters + per-role lockout durations)
// Integrates with `system_settings` (schema: key VARCHAR(100) PK, value TEXT NULL)
//
// Depends on:
//   - users table having:
//       failed_login_count INT NOT NULL DEFAULT 0
//       locked_until DATETIME NULL
//   - system_settings table (key PK, value TEXT)
//   - logs.php:        ppf_log($conn, $userId, $email, $role, $event, $channel, $ip=null, $detail=null)
//   - send_email.php:  send_plain_email($toEmail, $toName, $subject, $body)
//
// Usage:
//   require_once __DIR__ . '/ppf_lockout.php';
//   ppf_seed_lockout_defaults($conn);  // (optional) call once at startup or in settings.php
//
//   In login_handler.php:
//     if ($user && ppf_is_account_locked($user)) { ... redirect with ?err=locked ... }
//     if (password mismatch)  ppf_register_login_failure($conn, $uid, $email, $role, $nameOptional);
//     if (password correct)   ppf_clear_lockout_on_success($conn, $uid, $email, $role);

if (!function_exists('ppf_ss_get')) {
  function ppf_ss_get(mysqli $conn, string $key, ?string $default=null): ?string {
    if ($st = $conn->prepare("SELECT value FROM system_settings WHERE `key`=? LIMIT 1")) {
      $st->bind_param("s", $key);
      $st->execute();
      $st->bind_result($val);
      if ($st->fetch()) {
        $st->close();
        return $val;
      }
      $st->close();
    }
    return $default;
  }
}

if (!function_exists('ppf_ss_set')) {
  function ppf_ss_set(mysqli $conn, string $key, string $value): bool {
    if ($st = $conn->prepare("INSERT INTO system_settings (`key`,`value`) VALUES (?,?)
                              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")) {
      $st->bind_param("ss", $key, $value);
      $ok = $st->execute();
      $st->close();
      return (bool)$ok;
    }
    return false;
  }
}

if (!function_exists('ppf_seed_lockout_defaults')) {
  // Call once (e.g., at settings load) to ensure the four keys exist.
  function ppf_seed_lockout_defaults(mysqli $conn): void {
    $defaults = [
      'lockout_default_minutes' => '30',
      'lockout_minutes_client'  => '30',
      'lockout_minutes_trainer' => '30',
      'lockout_minutes_admin'   => '30',
    ];
    foreach ($defaults as $k => $v) {
      if (ppf_ss_get($conn, $k, null) === null) {
        ppf_ss_set($conn, $k, $v);
      }
    }
  }
}

if (!function_exists('ppf_get_lockout_minutes_for_role')) {
  function ppf_get_lockout_minutes_for_role(mysqli $conn, ?string $role): int {
    $role = strtolower((string)($role ?? ''));
    $key = match ($role) {
      'client'       => 'lockout_minutes_client',
      'trainer'      => 'lockout_minutes_trainer',
      'admin'        => 'lockout_minutes_admin',
      'super_admin'  => 'lockout_minutes_admin',
      default        => 'lockout_default_minutes',
    };
    $val = ppf_ss_get($conn, $key, null);
    if ($val === null) $val = ppf_ss_get($conn, 'lockout_default_minutes', '30');
    $mins = (int)preg_replace('/[^\d]/', '', (string)$val);
    return max(1, $mins ?: 30);
  }
}

if (!function_exists('ppf_is_account_locked')) {
  function ppf_is_account_locked(?array $user): bool {
    if (!$user || empty($user['locked_until'])) return false;
    try {
      return (new DateTime($user['locked_until'])) > new DateTime('now');
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('ppf_lockout_remaining_message')) {
  function ppf_lockout_remaining_message(?array $user): string {
    if (!$user || empty($user['locked_until'])) return '';
    try {
      $until = new DateTime($user['locked_until']);
      $now   = new DateTime('now');
      if ($until <= $now) return '';
      $mins = (int)ceil(($until->getTimestamp() - $now->getTimestamp()) / 60);
      if ($mins >= 120) return round($mins/60, 1) . ' hours';
      if ($mins >= 60)  return '1 hour';
      return $mins . ' minutes';
    } catch (Throwable $e) {
      return '';
    }
  }
}

if (!function_exists('ppf_register_login_failure')) {
  // Call this when a password attempt fails.
  // $displayName is optional (used only for a nicer email greeting if you want).
  function ppf_register_login_failure(mysqli $conn, int $userId, string $email, ?string $role, ?string $displayName = ''): void {
    // Increment failure counter
    if ($u = $conn->prepare("UPDATE users SET failed_login_count = COALESCE(failed_login_count,0) + 1 WHERE id=?")) {
      $u->bind_param("i", $userId);
      $u->execute();
      $u->close();
    }

    // Read back current failure count and lock state
    if ($s = $conn->prepare("SELECT failed_login_count, locked_until FROM users WHERE id=?")) {
      $s->bind_param("i", $userId);
      $s->execute();
      $s->bind_result($cnt, $lockedUntil);
      if ($s->fetch()) {
        $s->close();

        // Already locked? Just log another blocked attempt
        if (ppf_is_account_locked(['locked_until' => $lockedUntil])) {
          ppf_log($conn, $userId, $email, $role, 'login_failed_while_locked', 'security', null, null);
          return;
        }

        // Trigger lock at 5 failed attempts
        if ((int)$cnt >= 5) {
          $mins  = ppf_get_lockout_minutes_for_role($conn, $role);
          $until = date('Y-m-d H:i:s', time() + ($mins * 60));
          if ($l = $conn->prepare("UPDATE users SET locked_until=?, failed_login_count=0 WHERE id=?")) {
            $l->bind_param("si", $until, $userId);
            $l->execute();
            $l->close();
          }

          // Email the user
          $human = $mins >= 120 ? (round($mins/60, 1) . ' hours') : ($mins >= 60 ? '1 hour' : ($mins . ' minutes'));
          $name  = trim((string)$displayName);
          $toName = $name !== '' ? $name : '';
          $subject = 'Your Peter Pang Fit account has been locked';
          $body = "Your Peter Pang Fit account has been locked due to too many invalid login attempts.\n"
                . "Please wait {$human} or contact your trainer for assistance.";
          @send_plain_email($email, $toName, $subject, $body);

          // Log event
          ppf_log($conn, $userId, $email, $role, 'account_locked', 'security', null, "duration_minutes={$mins}");
          $untilLabel = function_exists('ppf_format_user_datetime')
            ? ppf_format_user_datetime($until, ['fallback' => $until])
            : $until;
          ppf_notifications_record($conn, $userId, [
            'type' => 'security.account_locked',
            'message' => 'Your account was locked until ' . $untilLabel . '.',
            'send_email' => true,
          ]);
        } else {
          // Just log failed attempt with current count
          ppf_log($conn, $userId, $email, $role, 'login_failed', 'auth', null, "count={$cnt}");
        }
      } else {
        $s->close();
      }
    }
  }
}

if (!function_exists('ppf_clear_lockout_on_success')) {
  // Call this right after a successful password verification
  function ppf_clear_lockout_on_success(mysqli $conn, int $userId, string $email, ?string $role): void {
    if ($u = $conn->prepare("UPDATE users SET failed_login_count=0, locked_until=NULL WHERE id=?")) {
      $u->bind_param("i", $userId);
      $u->execute();
      $u->close();
    }
    ppf_log($conn, $userId, $email, $role, 'login_success', 'auth', null, null);
  }
}

if (!function_exists('ppf_admin_unlock_user')) {
  // Manual unlock by admin (used from clients.php action)
  function ppf_admin_unlock_user(mysqli $conn, int $userId, int $adminId, string $adminEmail): bool {
    $ok = false;
    if ($u = $conn->prepare("UPDATE users SET failed_login_count=0, locked_until=NULL WHERE id=?")) {
      $u->bind_param("i", $userId);
      $ok = $u->execute();
      $u->close();
    }
    if ($ok) {
      ppf_log($conn, $userId, null, null, 'account_unlocked_admin', 'security', null, "by_admin={$adminId}");
    }
    return $ok;
  }
}