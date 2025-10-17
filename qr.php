<?php
// qr.php — server-side QR proxy for otpauth URIs to avoid browser/network blocks
// Usage: <img src="qr.php?data=<base64url(otpauth://...)>&s=240">
if (session_status() === PHP_SESSION_NONE) session_start();

$raw = $_GET['data'] ?? '';
$size = max(120, min(640, (int)($_GET['s'] ?? 240)));

if ($raw === '') {
  http_response_code(400);
  header('Content-Type: text/plain');
  echo 'Missing data';
  exit;
}

// Expect base64url (safer for querystrings)
$otpauth = base64_decode(strtr($raw, '-_', '+/'));
if ($otpauth === false || strpos($otpauth, 'otpauth://totp/') !== 0) {
  http_response_code(400);
  header('Content-Type: text/plain');
  echo 'Invalid data';
  exit;
}

// Build Google Chart URL (still generating the QR), but fetch on the server
$chart = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . '&chl=' . rawurlencode($otpauth);

// Fetch image
$img = null;
if (function_exists('curl_init')) {
  $ch = curl_init($chart);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $img = curl_exec($ch);
  curl_close($ch);
} else {
  $ctx = stream_context_create(['http'=>['timeout'=>10]]);
  $img = @file_get_contents($chart, false, $ctx);
}

if (!$img) {
  // fallback: tiny PNG placeholder
  header('Content-Type: image/png');
  echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAF0lEQVR4nO3BMQEAAADCoPdPbQ8HFAAAAAAAAAAAwB6T+QAAJ7p0TwAAAABJRU5ErkJggg==');
  exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
echo $img;