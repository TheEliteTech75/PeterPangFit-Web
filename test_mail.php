<?php
// TEMP: show errors to browser for setup only
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre>Loading mailer...\n";

$phpIniLoaded = php_ini_loaded_file();
$phpIniHint = '/etc/php/8.4/apache2/php.ini';
if ($phpIniLoaded) {
    $ok = @is_readable($phpIniLoaded);
    echo 'php.ini: ' . htmlspecialchars($phpIniLoaded, ENT_QUOTES, 'UTF-8')
       . ($ok ? " [OK]\n" : " [NOT READABLE]\n");
} else {
    echo "php.ini: [not reported]\n";
}
if (!$phpIniLoaded || !@is_readable($phpIniLoaded)) {
    $hintReadable = @is_readable($phpIniHint);
    echo 'Hint: Ubuntu Apache builds use ' . htmlspecialchars($phpIniHint, ENT_QUOTES, 'UTF-8')
       . ($hintReadable ? " [OK]\n" : " [MISSING]\n");
}
$scanned = php_ini_scanned_files();
if ($scanned) {
    echo 'Additional INI files: ' . htmlspecialchars($scanned, ENT_QUOTES, 'UTF-8') . "\n";
}

$senderPath = __DIR__ . '/send_email.php';
echo "Looking for send_email.php at: $senderPath\n";
if (!file_exists($senderPath)) {
    die("ERROR: send_email.php not found.\n");
}

require_once $senderPath;

$to = isset($_GET['to']) ? $_GET['to'] : 'adickens@pwncore.net';
echo "Attempting to send to: $to\n";

$ok = send_plain_email($to, 'Test Recipient', 'Test from Proton Mail Bridge',
    "Hello from Peter Pang Fit!\nThis is a test email.");

echo $ok ? "OK - email queued via Bridge.\n" : "FAIL - check PHP error log.\n";