<?php
// demo_mode.php — manage Demo Mode database routing (primary + sandbox)
//
// Responsibilities:
//   • Connect to the primary database
//   • Read system_settings.demo_mode_enabled to determine active mode
//   • Lazily connect to a sandbox database when Demo Mode is enabled
//   • Expose helpers for other scripts to introspect the current state
//
// Configuration (via environment variables):
//   PPF_DB_HOST,  PPF_DB_NAME,  PPF_DB_USER,  PPF_DB_PASS,  PPF_DB_PORT
//   PPF_DEMO_DB_HOST, PPF_DEMO_DB_NAME, PPF_DEMO_DB_USER, PPF_DEMO_DB_PASS, PPF_DEMO_DB_PORT
//   PPF_DB_SOCKET and PPF_DEMO_DB_SOCKET are optional overrides for unix sockets.
//
// Notes:
//   - The helper tracks alerts when sandbox connectivity fails and exposes them
//     via ppf_demo_collect_alerts(). Callers can display or log them as needed.
//   - The helper automatically disables Demo Mode (system_settings.demo_mode_enabled)
//     when the sandbox cannot be reached, so that subsequent requests default to
//     the primary database.

if (!defined('PPF_DEMO_MODE_HELPER')) {
    define('PPF_DEMO_MODE_HELPER', 1);

    /** @var ?mysqli $PPF_DEMO_PRIMARY_CONN */
    $PPF_DEMO_PRIMARY_CONN = $PPF_DEMO_PRIMARY_CONN ?? null;
    /** @var ?mysqli $PPF_DEMO_SANDBOX_CONN */
    $PPF_DEMO_SANDBOX_CONN = $PPF_DEMO_SANDBOX_CONN ?? null;
    /** @var ?mysqli $PPF_DEMO_ACTIVE_CONN */
    $PPF_DEMO_ACTIVE_CONN = $PPF_DEMO_ACTIVE_CONN ?? null;
    /** @var bool $PPF_DEMO_ENABLED */
    $PPF_DEMO_ENABLED = (bool)($PPF_DEMO_ENABLED ?? false);
    /** @var array<int, string> $PPF_DEMO_ALERTS */
    $PPF_DEMO_ALERTS = $PPF_DEMO_ALERTS ?? [];
    /** @var array<string, mixed>|null $PPF_DEMO_SANDBOX_CFG */
    $PPF_DEMO_SANDBOX_CFG = $PPF_DEMO_SANDBOX_CFG ?? null;
    /** @var string|null $PPF_DEMO_LAST_ERROR */
    $PPF_DEMO_LAST_ERROR = $PPF_DEMO_LAST_ERROR ?? null;

    /**
     * Fetch an environment variable with optional default.
     * Returns the fallback when the variable is undefined or empty.
     */
    function ppf_demo_env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    /**
     * Return the primary (authoritative) database connection handle.
     */
    function ppf_demo_primary_conn(): ?mysqli
    {
        global $PPF_DEMO_PRIMARY_CONN;
        return ($PPF_DEMO_PRIMARY_CONN instanceof mysqli) ? $PPF_DEMO_PRIMARY_CONN : null;
    }

    /**
     * Return the currently active connection (sandbox when Demo Mode is enabled).
     */
    function ppf_demo_active_conn(): ?mysqli
    {
        global $PPF_DEMO_ACTIVE_CONN;
        if ($PPF_DEMO_ACTIVE_CONN instanceof mysqli) {
            return $PPF_DEMO_ACTIVE_CONN;
        }
        return ppf_demo_primary_conn();
    }

    /**
     * Whether Demo Mode is currently enabled (and sandbox connection succeeded).
     */
    function ppf_demo_is_enabled(): bool
    {
        global $PPF_DEMO_ENABLED;
        return (bool)$PPF_DEMO_ENABLED;
    }

    /**
     * Last database error encountered while attempting to open a connection.
     */
    function ppf_demo_last_error(): ?string
    {
        global $PPF_DEMO_LAST_ERROR;
        return $PPF_DEMO_LAST_ERROR ?: null;
    }

    /**
     * Collect and optionally clear accumulated Demo Mode alerts.
     */
    function ppf_demo_collect_alerts(bool $clear = true): array
    {
        global $PPF_DEMO_ALERTS;
        $alerts = $PPF_DEMO_ALERTS;
        if ($clear) {
            $PPF_DEMO_ALERTS = [];
        }
        return $alerts;
    }

    /**
     * Append an alert message (internal helper).
     */
    function ppf_demo_push_alert(string $message): void
    {
        global $PPF_DEMO_ALERTS;
        $PPF_DEMO_ALERTS[] = $message;
    }

    /**
     * Persist alerts into the active PHP session (deduplicated).
     */
    function ppf_demo_store_alerts_in_session(array $alerts): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !$alerts) {
            return;
        }

        if (!isset($_SESSION['demo_alerts']) || !is_array($_SESSION['demo_alerts'])) {
            $_SESSION['demo_alerts'] = [];
        }
        if (!isset($_SESSION['demo_alerts_unread']) || !is_array($_SESSION['demo_alerts_unread'])) {
            $_SESSION['demo_alerts_unread'] = [];
        }

        foreach ($alerts as $alert) {
            $alert = trim((string)$alert);
            if ($alert === '') {
                continue;
            }
            if (!in_array($alert, $_SESSION['demo_alerts'], true)) {
                $_SESSION['demo_alerts'][] = $alert;
            }
            if (!in_array($alert, $_SESSION['demo_alerts_unread'], true)) {
                $_SESSION['demo_alerts_unread'][] = $alert;
            }
        }
    }

    /**
     * Ensure alerts reach the session even when session_start() happens later.
     */
    function ppf_demo_dispatch_alerts(array $alerts): void
    {
        if (!$alerts) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            ppf_demo_store_alerts_in_session($alerts);
            return;
        }

        if (!isset($GLOBALS['PPF_DEMO_ALERTS_BUFFER']) || !is_array($GLOBALS['PPF_DEMO_ALERTS_BUFFER'])) {
            $GLOBALS['PPF_DEMO_ALERTS_BUFFER'] = [];
        }
        $GLOBALS['PPF_DEMO_ALERTS_BUFFER'] = array_merge($GLOBALS['PPF_DEMO_ALERTS_BUFFER'], $alerts);

        if (empty($GLOBALS['PPF_DEMO_ALERTS_SHUTDOWN'])) {
            $GLOBALS['PPF_DEMO_ALERTS_SHUTDOWN'] = true;
            register_shutdown_function(function (): void {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    return;
                }
                if (empty($GLOBALS['PPF_DEMO_ALERTS_BUFFER']) || !is_array($GLOBALS['PPF_DEMO_ALERTS_BUFFER'])) {
                    return;
                }
                $pending = $GLOBALS['PPF_DEMO_ALERTS_BUFFER'];
                $GLOBALS['PPF_DEMO_ALERTS_BUFFER'] = [];
                if ($pending) {
                    ppf_demo_store_alerts_in_session($pending);
                }
            });
        }
    }

    /** Flag key stored in system_settings. */
    function ppf_demo_flag_key(): string
    {
        return 'demo_mode_enabled';
    }

    /**
     * Build configuration array from environment variables for a connection.
     * When $prefix is 'PPF_DEMO_', sandbox-specific overrides are used.
     */
    function ppf_demo_build_config(string $prefix = 'PPF_DB_'): array
    {
        $map = [
            'host'   => $prefix . 'HOST',
            'name'   => $prefix . 'NAME',
            'user'   => $prefix . 'USER',
            'pass'   => $prefix . 'PASS',
            'port'   => $prefix . 'PORT',
            'socket' => $prefix . 'SOCKET',
        ];
        $cfg = [];
        foreach ($map as $key => $envKey) {
            $val = ppf_demo_env($envKey, null);
            if ($val !== null) {
                $cfg[$key] = $val;
            }
        }
        return $cfg;
    }

    /**
     * Open a mysqli connection using configuration array keys.
     * Accepts: host, name/db, user, pass, port, socket, charset.
     */
    function ppf_demo_connect(array $cfg): ?mysqli
    {
        global $PPF_DEMO_LAST_ERROR;

        $host   = (string)($cfg['host'] ?? '127.0.0.1');
        $user   = (string)($cfg['user'] ?? '');
        $pass   = (string)($cfg['pass'] ?? '');
        $dbName = (string)($cfg['name'] ?? ($cfg['db'] ?? ''));
        $port   = isset($cfg['port']) && $cfg['port'] !== '' ? (int)$cfg['port'] : null;
        $socket = $cfg['socket'] ?? null;

        $mysqli = null;
        if ($port !== null || $socket !== null) {
            $mysqli = @new mysqli(
                $host,
                $user,
                $pass,
                $dbName,
                $port !== null ? $port : ini_get('mysqli.default_port'),
                $socket !== null ? $socket : ini_get('mysqli.default_socket')
            );
        } else {
            $mysqli = @new mysqli($host, $user, $pass, $dbName);
        }

        if ($mysqli instanceof mysqli && !$mysqli->connect_errno) {
            $charset = $cfg['charset'] ?? 'utf8mb4';
            if ($charset) {
                @$mysqli->set_charset($charset);
            }
            $PPF_DEMO_LAST_ERROR = null;
            return $mysqli;
        }

        $PPF_DEMO_LAST_ERROR = $mysqli instanceof mysqli ? $mysqli->connect_error : 'Unknown connection error';
        return null;
    }

    /**
     * Read the Demo Mode flag from system_settings.
     */
    function ppf_demo_read_flag(mysqli $conn): bool
    {
        $key = ppf_demo_flag_key();
        try {
            if ($stmt = $conn->prepare('SELECT `value` FROM system_settings WHERE `key`=? LIMIT 1')) {
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $stmt->bind_result($val);
                if ($stmt->fetch()) {
                    $stmt->close();
                    $val = strtolower(trim((string)$val));
                    return in_array($val, ['1', 'true', 'yes', 'on'], true);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            global $PPF_DEMO_LAST_ERROR;
            $PPF_DEMO_LAST_ERROR = $e->getMessage();
        }
        return false;
    }

    /**
     * Persist the Demo Mode flag back to system_settings.
     */
    function ppf_demo_write_flag(mysqli $conn, bool $enabled): bool
    {
        $key = ppf_demo_flag_key();
        $val = $enabled ? '1' : '0';
        try {
            $sql = 'INSERT INTO system_settings (`key`,`value`) VALUES (?,?)
                    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)';
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('ss', $key, $val);
                $ok = $stmt->execute();
                $stmt->close();
                return (bool)$ok;
            }
        } catch (Throwable $e) {
            global $PPF_DEMO_LAST_ERROR;
            $PPF_DEMO_LAST_ERROR = $e->getMessage();
        }
        return false;
    }

    /**
     * Establish the primary connection and store handles globally.
     */
    function ppf_demo_bootstrap_primary(array $cfg): ?mysqli
    {
        global $PPF_DEMO_PRIMARY_CONN, $PPF_DEMO_ACTIVE_CONN;
        $conn = ppf_demo_connect($cfg);
        if ($conn instanceof mysqli) {
            $PPF_DEMO_PRIMARY_CONN = $conn;
            $PPF_DEMO_ACTIVE_CONN  = $conn;
        }
        return $conn;
    }

    /**
     * Refresh Demo Mode state and set the active connection handle.
     * When the sandbox fails to connect, Demo Mode is disabled and
     * callers continue using the primary connection.
     */
    function ppf_demo_refresh_state(mysqli $primary, ?array $sandboxCfg = null): void
    {
        global $PPF_DEMO_PRIMARY_CONN, $PPF_DEMO_SANDBOX_CONN, $PPF_DEMO_ACTIVE_CONN;
        global $PPF_DEMO_ENABLED, $PPF_DEMO_SANDBOX_CFG, $PPF_DEMO_LAST_ERROR;

        $PPF_DEMO_PRIMARY_CONN = $primary;
        $PPF_DEMO_ACTIVE_CONN  = $primary;
        $PPF_DEMO_ENABLED      = false;

        if ($sandboxCfg !== null) {
            $PPF_DEMO_SANDBOX_CFG = $sandboxCfg;
        } elseif (!is_array($PPF_DEMO_SANDBOX_CFG)) {
            $PPF_DEMO_SANDBOX_CFG = [];
        }

        $enabled = ppf_demo_read_flag($primary);
        if (!$enabled) {
            return;
        }

        $PPF_DEMO_ENABLED = true;

        $sandbox = $PPF_DEMO_SANDBOX_CONN;
        if (!($sandbox instanceof mysqli) || $sandbox->connect_errno) {
            $sandbox = ppf_demo_connect($PPF_DEMO_SANDBOX_CFG ?? []);
        }

        if ($sandbox instanceof mysqli) {
            $PPF_DEMO_SANDBOX_CONN = $sandbox;
            $PPF_DEMO_ACTIVE_CONN  = $sandbox;
            return;
        }

        // Sandbox connection failed — revert to primary and disable the flag.
        $PPF_DEMO_ENABLED     = false;
        $PPF_DEMO_ACTIVE_CONN = $primary;
        ppf_demo_write_flag($primary, false);

        $reason = 'Demo Mode disabled: sandbox database connection failed.';
        if ($err = ppf_demo_last_error()) {
            $reason .= ' (' . $err . ')';
        }
        ppf_demo_push_alert($reason);
    }

    /** Absolute path to the bundled demo seed file. */
    function ppf_demo_seed_path(): string
    {
        return __DIR__ . '/demo_seed.sql';
    }

    /**
     * Reset the sandbox database to the bundled demo seed.
     * Returns an array describing success, messages, and any errors.
     */
    function ppf_demo_reset(mysqli $primaryConn)
    {
        global $PPF_DEMO_SANDBOX_CFG, $PPF_DEMO_SANDBOX_CONN, $PPF_DEMO_ACTIVE_CONN;

        $result = [
            'success'  => false,
            'messages' => [],
            'errors'   => [],
            'logged'   => false,
        ];

        $seedFile = ppf_demo_seed_path();
        if (!is_file($seedFile) || !is_readable($seedFile)) {
            $msg = 'Demo seed file is missing or unreadable.';
            $result['errors'][] = $msg;
            ppf_demo_push_alert($msg);
            return $result;
        }

        if (!is_array($PPF_DEMO_SANDBOX_CFG) || !$PPF_DEMO_SANDBOX_CFG) {
            $msg = 'Sandbox configuration is unavailable.';
            $result['errors'][] = $msg;
            ppf_demo_push_alert($msg);
            return $result;
        }

        $seedSql = @file_get_contents($seedFile);
        if ($seedSql === false || trim($seedSql) === '') {
            $msg = 'Unable to read demo seed contents.';
            $result['errors'][] = $msg;
            ppf_demo_push_alert($msg);
            return $result;
        }

        $sandbox = $PPF_DEMO_SANDBOX_CONN;
        if (!($sandbox instanceof mysqli) || $sandbox->connect_errno) {
            $sandbox = ppf_demo_connect($PPF_DEMO_SANDBOX_CFG);
        }

        if (!($sandbox instanceof mysqli) || $sandbox->connect_errno) {
            $msg = 'Unable to connect to the sandbox database.';
            if ($sandbox instanceof mysqli && $sandbox->connect_error) {
                $msg .= ' (' . $sandbox->connect_error . ')';
            }
            $result['errors'][] = $msg;
            ppf_demo_push_alert($msg);
            return $result;
        }

        $start = microtime(true);
        $sandbox->begin_transaction();
        $sandbox->query('SET FOREIGN_KEY_CHECKS=0');

        if (!$sandbox->multi_query($seedSql)) {
            $sandbox->rollback();
            $sandbox->query('SET FOREIGN_KEY_CHECKS=1');
            $sandbox->autocommit(true);
            $err = $sandbox->error ?: 'Unknown error while seeding demo data.';
            $result['errors'][] = $err;
            ppf_demo_push_alert('Demo reset failed: ' . $err);
            if (function_exists('ppf_log')) {
                try {
                    ppf_log($primaryConn, null, null, null, 'demo_mode_reset_failed', 'system', 'demo', $err);
                    $result['logged'] = true;
                } catch (Throwable $e) {
                    // ignore logging failure
                }
            }
            return $result;
        }

        do {
            if ($rs = $sandbox->store_result()) {
                $rs->free();
            }
        } while ($sandbox->more_results() && $sandbox->next_result());

        $sandbox->query('SET FOREIGN_KEY_CHECKS=1');

        if (!$sandbox->commit()) {
            $err = $sandbox->error ?: 'Failed to commit sandbox reset.';
            $result['errors'][] = $err;
            ppf_demo_push_alert('Demo reset failed: ' . $err);
            if (function_exists('ppf_log')) {
                try {
                    ppf_log($primaryConn, null, null, null, 'demo_mode_reset_failed', 'system', 'demo', $err);
                    $result['logged'] = true;
                } catch (Throwable $e) {
                    // ignore logging failure
                }
            }
            $sandbox->rollback();
            $sandbox->autocommit(true);
            return $result;
        }

        $sandbox->autocommit(true);

        // Ensure sandbox flag mirrors the primary flag after the reset.
        $demoEnabled = null;
        try {
            $demoEnabled = ppf_demo_read_flag($primaryConn);
        } catch (Throwable $e) {
            $demoEnabled = null;
        }
        if ($demoEnabled !== null) {
            try {
                ppf_demo_write_flag($sandbox, (bool)$demoEnabled);
            } catch (Throwable $e) {
                // non-fatal
            }
        }

        // Refresh cached handles so future calls use the reset sandbox connection/data.
        $PPF_DEMO_SANDBOX_CONN = $sandbox;
        $PPF_DEMO_ACTIVE_CONN  = $sandbox;
        ppf_demo_refresh_state($primaryConn);

        $durationMs = (int)round((microtime(true) - $start) * 1000);
        $message = 'Demo sandbox reset completed in ' . $durationMs . ' ms.';
        $result['success'] = true;
        $result['messages'][] = $message;

        if (function_exists('ppf_log')) {
            try {
                $details = $message . ' Seed=' . basename($seedFile);
                ppf_log($primaryConn, null, null, null, 'demo_mode_reset', 'system', 'demo', $details);
                $result['logged'] = true;
            } catch (Throwable $e) {
                // ignore logging failure
            }
        }

        return $result;
    }
}
