<?php
// geo_test.php — quick sanity check for ppf_geo_city_region()

// Load DB + logs (ppf_log, ppf_client_ip) + geo helpers
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/geo.php';

// 1) Choose an IP to test
$ip = '';
if (!empty($_GET['ip'])) {
  $ip = trim($_GET['ip']);
} else {
  // Try client IP, else REMOTE_ADDR
  $ip = function_exists('ppf_client_ip') ? ppf_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
}

// 2) Call geolocator (guard against non-array returns)
$city = 'Unknown';
$region = 'Unknown';
try {
  $res = ppf_geo_city_region($conn, $ip);
  if (is_array($res) && count($res) >= 2) {
    $city   = (string)$res[0];
    $region = (string)$res[1];
  }
} catch (Throwable $e) {
  // Show helpful error for debugging
  header('Content-Type: text/plain; charset=utf-8');
  echo "Exception calling ppf_geo_city_region(): " . $e->getMessage() . "\n";
  exit;
}

// 3) Render simple output (HTML)
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Geo Test</title>
  <style>
    body{background:#0b0c10;color:#e6e8ee;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:24px}
    code{background:#111521;padding:2px 6px;border-radius:6px}
    .box{background:#151923;border:1px solid #1c212b;border-radius:12px;padding:16px;max-width:720px}
    a{color:#3b82f6;text-decoration:none}
    a:hover{text-decoration:underline}
  </style>
</head>
<body>
  <div class="box">
    <h2 style="margin-top:0">Geolocation Test</h2>
    <p><strong>IP:</strong> <code><?= htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') ?></code></p>
    <p><strong>City:</strong> <?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Region:</strong> <?= htmlspecialchars($region, ENT_QUOTES, 'UTF-8') ?></p>

    <hr>
    <p>Try another IP (public) like <code>8.8.8.8</code> or <code>1.1.1.1</code>:</p>
    <form method="get" action="geo_test.php" style="display:flex;gap:8px">
      <input name="ip" placeholder="Enter IP" style="flex:1;background:#0f1218;border:1px solid #1c212b;border-radius:8px;color:#e6e8ee;padding:8px">
      <button type="submit" style="background:#1f2f55;border:1px solid #284072;color:#e6e8ee;border-radius:8px;padding:8px 12px;cursor:pointer">Lookup</button>
    </form>
    <p style="margin-top:10px" class="muted">
      If this shows <em>Unknown</em> for a private or local IP (e.g. 10.x, 192.168.x, 127.0.0.1), that’s expected. Use a public IP for a meaningful result.
    </p>
  </div>
</body>
</html>