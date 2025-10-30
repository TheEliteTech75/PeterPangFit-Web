<?php
/**
 * Proton Mail Bridge settings
 * - If Bridge is on a different host, either:
 *   A) Create an SSH tunnel and keep HOST=127.0.0.1:1025 on the web server, or
 *   B) Point HOST to the mail server’s LAN IP and firewall it to only allow your web server.
 */

require_once __DIR__ . '/ppf_env.php';

// ===== REQUIRED: fill these from Proton Mail Bridge =====
define('MAIL_HOST', '127.0.0.1');          // '127.0.0.1' if tunneling, or the mail server IP
define('MAIL_PORT', 1025);                  // Bridge default
define('MAIL_USERNAME', 'p4wn3d5972@proton.me'); // From Bridge
define('MAIL_PASSWORD', 'Uh7Zaa9_f-z3lg82-vfsCA'); // From Bridge

// Your sender identity
define('MAIL_FROM', 'peterpangfit@pwncore.net'); // your Proton address or custom domain address routed to Proton
define('MAIL_FROM_NAME', 'Peter Pang Fitness');

// ===== OPTIONAL =====
// Usually blank for Bridge (no TLS between your app and Bridge). If you must force TLS, set to 'tls' or 'ssl'.
define('MAIL_SECURE', '');
define('MAIL_AUTH', true);

// Absolute paths for PHPMailer if Composer autoload is unavailable (Linux + Windows)
$phpmailerHints = [
    __DIR__ . '/vendor/phpmailer/phpmailer/src/',   // default Composer install
    __DIR__ . '/PHPMailer/src/',                    // bundled library fallback
];

if (defined('PPF_LINUX_APP_ROOT')) {
    $linuxRoot = rtrim(PPF_LINUX_APP_ROOT, '/') . '/';
    $phpmailerHints[] = $linuxRoot . 'vendor/phpmailer/phpmailer/src/';
    $phpmailerHints[] = $linuxRoot . 'PHPMailer/src/';
}

$phpmailerHints[] = 'C:\\php\\src\\';          // legacy Windows path
$phpmailerHints[] = 'C:\\php\\PHPMailer\\src\\'; // legacy Windows path

define('PHPMailer_HINTS', json_encode(array_values(array_unique($phpmailerHints)), JSON_UNESCAPED_SLASHES));
