<?php
$host = '10.0.1.7';
$db   = 'PeterPangFit';
$user = 'Kung6020';
$pass = 'iyyFhM%umDuG&@mgL$5Cf75s765b*7*n';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>