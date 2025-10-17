<?php
// sessions_ipinfo.php — Returns JSON with rich IP info for tooltip.
// POST: ip=1.2.3.4
// Output: { ok, ip, city, region, asn_org, source, is_vpn, is_icloud, anonymous_flags? }

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/geo.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'not_signed_in']); exit; }

$ip = trim((string)($_POST['ip'] ?? ''));
if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['ok'=>false,'error'=>'bad_ip']); exit; }

try {
  // Basic geo
  $geo = ppf_geo_city_region($conn, $ip); // writes city/region cache when needed

  // ASN/org (may trigger ip-api if MMDB missing)
  $asnOrg = ppf_geo_as_org($ip);

  // iCloud detection first; do NOT mark VPN if icloud
  $isIcloud = false;
  try { $isIcloud = ppf_geo_is_icloud($conn, $ip); } catch (\Throwable $e) {}

  $isVpn = false; $flags = null; $source = 'cache/mmdb/ip-api';
  if (!$isIcloud) {
    // Try MaxMind Anonymous-IP flags if DB exists
    $anon = ppf_geo_with_maxmind_anonymous($ip);
    if ($anon !== null) {
      // We can't extract individual bools when using GeoIp2 high-level API easily; set aggregate + mark as source
      $isVpn = (bool)$anon;
      $flags = null; // If you have low-level fields, you can populate here
      $source = 'anonymous-ip-mmdb';
      // Cache VPN
      ppf_geo_write_vpn_cache($conn, $ip, $isVpn);
    } else {
      // Heuristic VPN
      $isVpn = ppf_geo_is_vpn($conn, $ip);
      $source = 'asn-heuristic';
    }
  } else {
    // Ensure VPN cache is false when iCloud is true
    ppf_geo_write_vpn_cache($conn, $ip, false);
  }

  // Persist iCloud cache (and keep VPN in sync)
  ppf_geo_write_icloud_cache($conn, $ip, $isIcloud);

  echo json_encode([
    'ok' => true,
    'ip' => $ip,
    'city' => (string)($geo['city'] ?? ''),
    'region' => (string)($geo['region'] ?? ''),
    'asn_org' => $asnOrg,
    'source' => $source,
    'is_vpn' => (bool)$isVpn,
    'is_icloud' => (bool)$isIcloud,
    'anonymous_flags' => $flags
  ]);
} catch (\Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'exception']);
}