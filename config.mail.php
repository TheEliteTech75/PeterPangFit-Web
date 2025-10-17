<?php
/**
 * Proton Mail Bridge settings
 * - If Bridge is on a different host, either:
 *   A) Create an SSH tunnel and keep HOST=127.0.0.1:1025 on the web server, or
 *   B) Point HOST to the mail server’s LAN IP and firewall it to only allow your web server.
 */

// ===== REQUIRED: fill these from Proton Mail Bridge =====
define('MAIL_HOST', '127.0.0.1');          // '127.0.0.1' if tunneling, or the mail server IP
define('MAIL_PORT', 1025);                  // Bridge default
define('MAIL_USERNAME', 'p4wn3d5972@proton.me'); // From Bridge
define('MAIL_PASSWORD', 'pwa3kdggg-rOZxmpljbWSw'); // From Bridge

// Your sender identity
define('MAIL_FROM', 'peterpangfit@pwncore.net'); // your Proton address or custom domain address routed to Proton
define('MAIL_FROM_NAME', 'Peter Pang Fitness');

// ===== OPTIONAL =====
// Usually blank for Bridge (no TLS between your app and Bridge). If you must force TLS, set to 'tls' or 'ssl'.
define('MAIL_SECURE', '');
define('MAIL_AUTH', true);

// Absolute paths for PHPMailer on Windows (we’ll try these in order)
// Absolute paths for PHPMailer on Windows (we’ll try these in order)
define('PHPMailer_HINTS', serialize([
    'C:\\php\\src\\',             // e.g., C:\php\src\Exception.php
    'C:\\php\\PHPMailer\\src\\',  // e.g., C:\php\PHPMailer\src\Exception.php
]));