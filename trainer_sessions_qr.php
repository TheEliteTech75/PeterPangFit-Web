<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$size = max(160, min(512, (int)($_GET['size'] ?? 320)));

if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Missing token';
    exit;
}

$payload = json_encode([
    'type' => 'trainer_session',
    'token' => $token,
], JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    $payload = $token;
}

$chartUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . '&chl=' . rawurlencode($payload);

$image = null;
if (function_exists('curl_init')) {
    $ch = curl_init($chartUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $image = curl_exec($ch);
    curl_close($ch);
} else {
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $image = @file_get_contents($chartUrl, false, $context);
}

if (!$image) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'Failed to generate QR';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
echo $image;
