<?php
// ppf_debug.php — central debug/error bootstrap for login & passkey flows

if (!defined('PPF_DEBUG_BOOTSTRAPPED')) {
    define('PPF_DEBUG_BOOTSTRAPPED', true);

    if (!function_exists('ppf_debug_emit')) {
        function ppf_debug_emit(string $entry): void {
            $entry = trim($entry);
            if ($entry === '') {
                return;
            }

            // Always write to the PHP error log for Apache diagnostics
            error_log($entry);

            if (PHP_SAPI === 'cli') {
                if (defined('STDERR')) {
                    fwrite(STDERR, $entry . PHP_EOL);
                } else {
                    echo $entry . PHP_EOL;
                }
                return;
            }

            $contentType = null;
            if (function_exists('headers_list')) {
                foreach (headers_list() as $header) {
                    if (stripos($header, 'Content-Type:') === 0) {
                        $contentType = trim(substr($header, strlen('Content-Type:')));
                        break;
                    }
                }
            }

            if ($contentType && stripos($contentType, 'application/json') === 0) {
                if (!headers_sent()) {
                    header('X-PHP-Debug: ' . substr($entry, 0, 512));
                }
                return;
            }

            echo '<pre class="ppf-php-debug">' . htmlspecialchars($entry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>\n";
        }
    }

    if (!function_exists('ppf_debug_label_for_level')) {
        function ppf_debug_label_for_level(int $severity): string {
            static $labels = [
                E_ERROR             => 'Fatal Error',
                E_WARNING           => 'Warning',
                E_PARSE             => 'Parse Error',
                E_NOTICE            => 'Notice',
                E_CORE_ERROR        => 'Core Error',
                E_CORE_WARNING      => 'Core Warning',
                E_COMPILE_ERROR     => 'Compile Error',
                E_COMPILE_WARNING   => 'Compile Warning',
                E_USER_ERROR        => 'User Error',
                E_USER_WARNING      => 'User Warning',
                E_USER_NOTICE       => 'User Notice',
                E_STRICT            => 'Strict Standards',
                E_RECOVERABLE_ERROR => 'Recoverable Error',
                E_DEPRECATED        => 'Deprecated',
                E_USER_DEPRECATED   => 'User Deprecated',
            ];
            return $labels[$severity] ?? 'Error';
        }
    }

    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    @ini_set('error_log', sys_get_temp_dir() . '/ppf_php_errors.log');
    error_reporting(E_ALL);

    set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) {
        $label = ppf_debug_label_for_level($severity);
        $entry = sprintf('%s: %s in %s on line %d', $label, $message, $file ?: 'unknown file', $line);
        ppf_debug_emit($entry);
        return false; // fall back to PHP internal handler as well
    });

    set_exception_handler(function (Throwable $e): void {
        $entry = sprintf(
            'Uncaught %s: %s in %s on line %d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        $trace = $e->getTraceAsString();
        ppf_debug_emit($trace ? $entry . "\n" . $trace : $entry);
        if (!headers_sent()) {
            http_response_code(500);
        }
    });

    register_shutdown_function(function (): void {
        $lastError = error_get_last();
        if (!$lastError) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
            return;
        }
        $entry = sprintf(
            'Fatal shutdown error [%d]: %s in %s on line %d',
            $lastError['type'],
            $lastError['message'] ?? '',
            $lastError['file'] ?? 'unknown file',
            $lastError['line'] ?? 0
        );
        ppf_debug_emit($entry);
        if (!headers_sent()) {
            http_response_code(500);
        }
    });
}
