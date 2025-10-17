<?php
/**
 * send_email.php — hardened PHPMailer bootstrap + helper (no fatals)
 *
 * Requires config.mail.php with:
 *   MAIL_HOST, MAIL_PORT, MAIL_AUTH (bool), MAIL_USERNAME, MAIL_PASSWORD,
 *   MAIL_FROM, MAIL_FROM_NAME, MAIL_SECURE ('tls'|'ssl'|false),
 *   MAIL_AUTOTLS (optional bool), MAIL_DEBUG (optional bool),
 *   PHPMailer_HINTS (optional array | JSON string | serialized array)
 */

require_once __DIR__ . '/config.mail.php';

/* ---------------------------------------
   Loader utilities
----------------------------------------*/
function _ppf_try_require(string $path, array &$tried): bool {
    $tried[] = $path;
    if (is_file($path)) { require_once $path; return true; }
    return false;
}

/**
 * Attempt to make PHPMailer available (Composer, local src, or hints).
 * Returns true iff PHPMailer\PHPMailer\PHPMailer exists after loading.
 * Never throws; we log once and let callers handle fallback.
 */
function _ppf_bootstrap_phpmailer(): bool {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;

    $tried = [];

    // 1) Composer autoloads in common locations
    $composer = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        'C:/inetpub/wwwroot/vendor/autoload.php', // common IIS global
    ];
    foreach ($composer as $c) {
        if (_ppf_try_require($c, $tried) && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
    }

    // 2) Local bundled library: ./PHPMailer/src/...
    $local = __DIR__ . '/PHPMailer/src/';
    if (
        _ppf_try_require($local . 'Exception.php', $tried) &&
        _ppf_try_require($local . 'PHPMailer.php',  $tried) &&
        _ppf_try_require($local . 'SMTP.php',       $tried) &&
        class_exists('PHPMailer\\PHPMailer\\PHPMailer')
    ) return true;

    // 3) Hinted absolute paths from config (array, JSON, or serialized)
    $hintsRaw = defined('PHPMailer_HINTS') ? PHPMailer_HINTS : null;
    $hints = [];
    if (is_array($hintsRaw)) {
        $hints = $hintsRaw;
    } elseif (is_string($hintsRaw) && $hintsRaw !== '') {
        $tmp = json_decode($hintsRaw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            $hints = $tmp;
        } else {
            $tmp = @unserialize($hintsRaw);
            if (is_array($tmp)) $hints = $tmp;
        }
    }

    foreach ($hints as $base) {
        $base = rtrim((string)$base, "/\\") . DIRECTORY_SEPARATOR;
        if (
            _ppf_try_require($base . 'Exception.php', $tried) &&
            _ppf_try_require($base . 'PHPMailer.php',  $tried) &&
            _ppf_try_require($base . 'SMTP.php',       $tried) &&
            class_exists('PHPMailer\\PHPMailer\\PHPMailer')
        ) return true;
    }

    // Log once for diagnostics (but don't fatal)
    static $logged = false;
    if (!$logged) {
        $logged = true;
        $msg = "PHPMailer not available. Tried: " . implode(' | ', $tried)
             . " | Expect: vendor/autoload.php OR PHPMailer/src/{Exception.php,PHPMailer.php,SMTP.php}.";
        error_log($msg);
    }
    return false;
}

// Try to load PHPMailer now; non-fatal if missing
_ppf_bootstrap_phpmailer();

// Only alias classes if PHPMailer actually exists (prevents autoload quirks)
if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    class_alias('PHPMailer\\PHPMailer\\PHPMailer', 'PPF_PHPMailer');
    class_alias('PHPMailer\\PHPMailer\\Exception',  'PPF_PHPMailer_Exception');
}

/* ---------------------------------------
   Diagnostics
----------------------------------------*/
function _ppf_mail_transport_summary(): string {
    return sprintf(
        'host=%s; port=%s; auth=%s; secure=%s; autostarttls=%s; user=%s',
        defined('MAIL_HOST') ? MAIL_HOST : '(unset)',
        defined('MAIL_PORT') ? MAIL_PORT : '(unset)',
        defined('MAIL_AUTH') ? (MAIL_AUTH ? '1' : '0') : '(unset)',
        (defined('MAIL_SECURE') && MAIL_SECURE) ? MAIL_SECURE : 'none',
        (defined('MAIL_SECURE') && MAIL_SECURE) ? '1' : (defined('MAIL_AUTOTLS') ? (MAIL_AUTOTLS ? '1' : '0') : '0'),
        defined('MAIL_USERNAME') ? MAIL_USERNAME : '(unset)'
    );
}

/* ---------------------------------------
   Public API
----------------------------------------*/
/**
 * Send a plain-text email. Returns true on success; false on failure.
 * On failure we log the exact reason (loader or SMTP error).
 */
function send_plain_email(string $toEmail, string $toName, string $subject, string $body): bool {
    $debug = defined('MAIL_DEBUG') ? (bool)MAIL_DEBUG : false;

    if (class_exists('PPF_PHPMailer')) {
        $mail = new PPF_PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = (string)MAIL_HOST;
            $mail->Port       = (int)MAIL_PORT;
            $mail->SMTPAuth   = (bool)MAIL_AUTH;
            $mail->Username   = (string)MAIL_USERNAME;
            $mail->Password   = (string)MAIL_PASSWORD;

            // TLS/SSL behavior
            if (defined('MAIL_SECURE') && MAIL_SECURE) {
                // 'tls' (STARTTLS) or 'ssl' (implicit TLS)
                $mail->SMTPSecure  = MAIL_SECURE;
                $mail->SMTPAutoTLS = true; // safe for explicit tls/ssl
            } else {
                // No TLS — e.g., local relay/Proton Bridge
                $mail->SMTPSecure  = false;
                $mail->SMTPAutoTLS = defined('MAIL_AUTOTLS') ? (bool)MAIL_AUTOTLS : false;
            }

            if ($debug) {
                $mail->SMTPDebug   = 2;            // verbose
                $mail->Debugoutput = 'error_log';  // don't echo HTML into responses
                error_log('[MAIL DEBUG begin] ' . _ppf_mail_transport_summary());
            }

            $mail->setFrom((string)MAIL_FROM, (string)MAIL_FROM_NAME);
            $mail->addAddress($toEmail, $toName);

            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body = $body;

            $mail->send();
            if ($debug) error_log('[MAIL DEBUG] send ok');
            return true;

        } catch (\Throwable $e) {
            // PHPMailer keeps a human-readable ErrorInfo
            error_log('[MAIL ERROR] ' . $mail->ErrorInfo . ' | ' . _ppf_mail_transport_summary());
            return false;
        }
    }

    // PHPMailer not available — log and fail gracefully (no fatal)
    error_log('[MAIL ERROR] PHPMailer class not available. ' . _ppf_mail_transport_summary());
    return false;
}