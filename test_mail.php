<?php
// TEMP: show errors to browser for setup only
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre>Loading mailer...\n";

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