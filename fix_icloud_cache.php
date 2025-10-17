<?php
// fix_icloud_cache.php — Admin-only maintenance tool
// Refresh iCloud/VPN flags for cached and recently seen IPs.
// - Prioritizes iCloud detection (and clears VPN flag when iCloud=true)
// - Then runs VPN detection for remaining IPs
// - Paginates to avoid timeouts (use ?limit=500&offset=0,500,1000...)
//
// Security: requires logged-in admin.

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/logs.php';

$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
if ($uid <= 0 || strtolower($role) !== 'admin') {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

ignore_user_abort(true);
@set_time_limit(300);

// Params
$limit  = max(1, (int)($_GET['limit'] ?? 500));
$offset = max(0, (int)($_GET['offset'] ?? 0));

// 1) Collect IPs from user_sessions with their latest seen time
$ips = []; // ip => last_ts (int)

$sql1 = "
  SELECT ip, MAX(last_seen_at) AS last_seen
  FROM user_sessions
  WHERE ip IS NOT NULL AND ip <> ''
  GROUP BY ip
  ORDER BY last_seen DESC
  LIMIT ? OFFSET ?
";
if ($st = $conn->prepare($sql1)) {
  $st->bind_param("ii", $limit, $offset);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($row = $rs->fetch_assoc())) {
    $ip = trim((string)$row['ip']);
    if ($ip === '') continue;
    $ts = $row['last_seen'] ? strtotime((string)$row['last_seen']) : 0;
    $ips[$ip] = max($ips[$ip] ?? 0, $ts);
  }
  $st->close();
}

// 2) Also include IPs from ip_cache (so older cached entries can be corrected)
$sql2 = "
  SELECT INET6_NTOA(ip_bin) AS ip, MAX(looked_up_at) AS looked
  FROM ip_cache
  GROUP BY ip_bin
  ORDER BY looked DESC
  LIMIT ? OFFSET ?
";
if ($st = $conn->prepare($sql2)) {
  $st->bind_param("ii", $limit, $offset);
  $st->execute();
  $rs = $st->get_result();
  while ($rs && ($row = $rs->fetch_assoc())) {
    $ip = trim((string)$row['ip']);
    if ($ip === '') continue;
    $ts = $row['looked'] ? strtotime((string)$row['looked']) : 0;
    if (!isset($ips[$ip])) $ips[$ip] = $ts;
  }
  $st->close();
}

// Nothing to do?
if (!$ips) {
  header('Content-Type: text/plain');
  echo "No IPs found for this page (limit={$limit}, offset={$offset}).\n";
  exit;
}

// Process
$processed = 0; $icloudOn = 0; $icloudOff = 0; $vpnOn = 0; $vpnOff = 0; $errors = 0;
foreach ($ips as $ip => $_ts) {
  // Skip non-public
  if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;

  try {
    // 1) iCloud detection (also clears VPN flag when true)
    $isIcloud = ppf_geo_is_icloud($conn, $ip);
    if ($isIcloud) {
      $icloudOn++;
      // No further VPN labeling when iCloud true
      $processed++;
      continue;
    } else {
      $icloudOff++;
    }

    // 2) VPN detection (this function internally re-checks iCloud and returns false if iCloud)
    $isVpn = ppf_geo_is_vpn($conn, $ip);
    if ($isVpn) $vpnOn++; else $vpnOff++;

    $processed++;
  } catch (\Throwable $e) {
    $errors++;
    if (function_exists('ppf_log')) {
      ppf_log($conn, $uid, $_SESSION['email'] ?? null, 'admin', 'fix_icloud_cache_error', 'system', (string)$uid, 'ip='.$ip.';ex='.$e->getMessage());
    }
  }
}

// Output simple report
header('Content-Type: text/plain');
echo "Processed: {$processed}\n";
echo "iCloud true: {$icloudOn}\n";
echo "iCloud false: {$icloudOff}\n";
echo "VPN true: {$vpnOn}\n";
echo "VPN false: {$vpnOff}\n";
echo "Errors: {$errors}\n";
echo "\nNext page: add &offset=" . ($offset + $limit) . "\n";