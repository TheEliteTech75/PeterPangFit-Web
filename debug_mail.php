<?php
// Force plain text, no compression, visible errors
header('Content-Type: text/plain; charset=utf-8');
ini_set('zlib.output_compression', '0');
ini_set('output_buffering', '0');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "PHP reachable.\n";

$phpIniLoaded = php_ini_loaded_file();
$phpIniHint = '/etc/php/8.4/apache2/php.ini';
if ($phpIniLoaded) {
  $ok = @is_readable($phpIniLoaded);
  echo 'php.ini: ' . $phpIniLoaded . ($ok ? " [OK]\n" : " [NOT READABLE]\n");
} else {
  echo "php.ini: [not reported]" . "\n";
}
if (!$phpIniLoaded || !@is_readable($phpIniLoaded)) {
  $hintReadable = @is_readable($phpIniHint);
  echo 'Hint: Ubuntu Apache builds keep php.ini at ' . $phpIniHint
     . ($hintReadable ? " [OK]\n" : " [MISSING]\n");
}
$scanned = php_ini_scanned_files();
if ($scanned) {
  echo 'Additional INI files: ' . $scanned . "\n";
}

// Check presence of config + sender
$cfg = __DIR__ . '/config.mail.php';
$sender = __DIR__ . '/send_email.php';

echo "Looking for config.mail.php: $cfg " . (file_exists($cfg) ? "[OK]\n" : "[MISSING]\n");
echo "Looking for send_email.php : $sender " . (file_exists($sender) ? "[OK]\n" : "[MISSING]\n");

require_once $cfg;
require_once $sender;

echo "MAIL_HOST=" . MAIL_HOST . "  PORT=" . MAIL_PORT . "\n";

// Try sending
$to = isset($_GET['to']) ? $_GET['to'] : 'adickens@pwncore.net';
echo "Sending to: $to ...\n";

$ok = send_plain_email($to, 'Test Recipient', 'Bridge test', "Hello from Peter Pang Fit debug.");
echo $ok ? "RESULT: OK\n" : "RESULT: FAIL (check PHP error log)\n";
