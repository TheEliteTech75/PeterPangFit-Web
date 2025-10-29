<?php
// geo.php — IP → (City, Region) with DB caching and optional MaxMind, falling back to ip-api (HTTP).
//
// Adds iCloud detection + caching and ensures iCloud does not get labeled as VPN.
// Caches: ip_cache.city,region,looked_up_at,is_vpn,vpn_checked_at,is_icloud,icloud_checked_at
//
// Optional MMDBs:
//   GeoLite2-City.mmdb, GeoIP2-Anonymous-IP.mmdb, GeoLite2-ASN.mmdb
//
// Composer: "geoip2/geoip2" or "maxmind-db/reader"

require_once __DIR__ . '/ppf_env.php';

if (!function_exists('ppf_geo_column_exists')) {
  function ppf_geo_column_exists(mysqli $conn, string $table, string $column): bool {
    $row = $conn->query("SELECT DATABASE()")->fetch_row();
    $db  = $row[0] ?? null;
    if (!$db) return false;
    if ($st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?")) {
      $st->bind_param("sss", $db, $table, $column);
      $st->execute();
      $st->store_result();
      $exists = $st->num_rows > 0;
      $st->close();
      return $exists;
    }
    return false;
  }
}

if (!function_exists('ppf_geo_ensure_cache_table')) {
  function ppf_geo_ensure_cache_table(mysqli $conn): void {
    @$conn->query(
      "CREATE TABLE IF NOT EXISTS ip_cache (
         ip_bin VARBINARY(16) PRIMARY KEY,
         city   VARCHAR(80)  NOT NULL DEFAULT '',
         region VARCHAR(80)  NOT NULL DEFAULT '',
         looked_up_at DATETIME NOT NULL
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!ppf_geo_column_exists($conn, 'ip_cache', 'is_vpn')) {
      @$conn->query("ALTER TABLE ip_cache ADD COLUMN is_vpn TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!ppf_geo_column_exists($conn, 'ip_cache', 'vpn_checked_at')) {
      @$conn->query("ALTER TABLE ip_cache ADD COLUMN vpn_checked_at DATETIME NULL");
    }
    // iCloud columns
    if (!ppf_geo_column_exists($conn, 'ip_cache', 'is_icloud')) {
      @$conn->query("ALTER TABLE ip_cache ADD COLUMN is_icloud TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!ppf_geo_column_exists($conn, 'ip_cache', 'icloud_checked_at')) {
      @$conn->query("ALTER TABLE ip_cache ADD COLUMN icloud_checked_at DATETIME NULL");
    }
  }
}

if (!function_exists('ppf_geo_is_meaningful')) {
  function ppf_geo_is_meaningful(string $s): bool {
    $s = trim($s);
    return $s !== '' && stripos($s, 'unknown') === false;
  }
}

/* -------- Cache read/write -------- */

if (!function_exists('ppf_geo_from_cache')) {
  /** @return array{city:string,region:string,looked_at:?string,is_vpn:?int,vpn_checked_at:?string} */
  function ppf_geo_from_cache(mysqli $conn, string $ip): array {
    ppf_geo_ensure_cache_table($conn);
    $out = ['city'=>'', 'region'=>'', 'looked_at'=>null, 'is_vpn'=>null, 'vpn_checked_at'=>null];
    if ($st = $conn->prepare("SELECT city, region, looked_up_at, is_vpn, vpn_checked_at FROM ip_cache WHERE ip_bin=INET6_ATON(?) LIMIT 1")) {
      $st->bind_param("s", $ip);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($row = $rs->fetch_assoc())) {
        $out['city']           = (string)($row['city'] ?? '');
        $out['region']         = (string)($row['region'] ?? '');
        $out['looked_at']      = (string)($row['looked_up_at'] ?? null);
        $out['is_vpn']         = ($row['is_vpn'] === null ? null : (int)$row['is_vpn']);
        $out['vpn_checked_at'] = (string)($row['vpn_checked_at'] ?? null);
      }
      $st->close();
    }
    return $out;
  }
}

if (!function_exists('ppf_geo_write_cache')) {
  function ppf_geo_write_cache(mysqli $conn, string $ip, string $city, string $region): void {
    ppf_geo_ensure_cache_table($conn);
    if ($st = $conn->prepare(
      "INSERT INTO ip_cache (ip_bin, city, region, looked_up_at)
       VALUES (INET6_ATON(?), ?, ?, NOW())
       ON DUPLICATE KEY UPDATE city=VALUES(city), region=VALUES(region), looked_up_at=NOW()"
    )) {
      $st->bind_param("sss", $ip, $city, $region);
      $st->execute();
      $st->close();
    }
  }
}

if (!function_exists('ppf_geo_write_vpn_cache')) {
  function ppf_geo_write_vpn_cache(mysqli $conn, string $ip, bool $isVpn): void {
    ppf_geo_ensure_cache_table($conn);
    if ($st = $conn->prepare(
      "INSERT INTO ip_cache (ip_bin, is_vpn, vpn_checked_at, looked_up_at)
       VALUES (INET6_ATON(?), ?, NOW(), NOW())
       ON DUPLICATE KEY UPDATE is_vpn=VALUES(is_vpn), vpn_checked_at=VALUES(vpn_checked_at)"
    )) {
      $v = $isVpn ? 1 : 0;
      $st->bind_param("si", $ip, $v);
      $st->execute();
      $st->close();
    }
  }
}

if (!function_exists('ppf_geo_write_icloud_cache')) {
  function ppf_geo_write_icloud_cache(mysqli $conn, string $ip, bool $isIcloud): void {
    ppf_geo_ensure_cache_table($conn);
    if ($st = $conn->prepare(
      "INSERT INTO ip_cache (ip_bin, is_icloud, icloud_checked_at, looked_up_at)
       VALUES (INET6_ATON(?), ?, NOW(), NOW())
       ON DUPLICATE KEY UPDATE is_icloud=VALUES(is_icloud), icloud_checked_at=VALUES(icloud_checked_at)"
    )) {
      $v = $isIcloud ? 1 : 0;
      $st->bind_param("si", $ip, $v);
      $st->execute();
      $st->close();
    }
  }
}

/* -------- IP helpers -------- */

if (!function_exists('ppf_geo_is_public_ip')) {
  function ppf_geo_is_public_ip(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
  }
}

if (!function_exists('ppf_geo_mmdb_find')) {
  function ppf_geo_mmdb_find(array $candidates): ?string {
    foreach ($candidates as $p) if (is_readable($p)) return $p;
    return null;
  }
}

/* -------- Geo (city/region) -------- */

if (!function_exists('ppf_geo_with_maxmind')) {
  /** @return array{city:string,region:string} */
  function ppf_geo_with_maxmind(string $ip): array {
    if (!ppf_geo_is_public_ip($ip)) return ['city'=>'', 'region'=>''];
    $candidates = [
      __DIR__ . '/data/GeoLite2-City.mmdb',
      __DIR__ . '/GeoLite2-City.mmdb',
    ];
    $linuxRoot = defined('PPF_LINUX_APP_ROOT') ? rtrim(PPF_LINUX_APP_ROOT, '/') : null;
    if ($linuxRoot) {
      $candidates[] = $linuxRoot . '/data/GeoLite2-City.mmdb';
      $candidates[] = $linuxRoot . '/GeoLite2-City.mmdb';
    } else {
      $candidates[] = '/var/www/html/peterpangfitness/data/GeoLite2-City.mmdb';
      $candidates[] = '/var/www/html/peterpangfitness/GeoLite2-City.mmdb';
    }
    $candidates[] = 'C:\\data\\GeoLite2-City.mmdb'; // legacy Windows fallback

    $mmdb = ppf_geo_mmdb_find($candidates);
    if (!$mmdb) return ['city'=>'', 'region'=>''];

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_readable($autoload)) { @include_once $autoload; }

    try {
      if (class_exists('\\GeoIp2\\Database\\Reader')) {
        $reader = new \GeoIp2\Database\Reader($mmdb);
        $r = $reader->city($ip);
        $city = (string)($r->city->name ?? '');
        $region = (!empty($r->subdivisions) && isset($r->subdivisions[0])) ? (string)($r->subdivisions[0]->name ?? '') : '';
        $reader->close();
        return ['city'=>$city ?: '', 'region'=>$region ?: ''];
      }
    } catch (\Throwable $e) {}

    try {
      if (class_exists('\\MaxMind\\Db\\Reader')) {
        $reader = new \MaxMind\Db\Reader($mmdb);
        $r = $reader->get($ip);
        $reader->close();
        if (is_array($r)) {
          $city   = (string)($r['city']['names']['en'] ?? '');
          $region = !empty($r['subdivisions'][0]['names']['en']) ? (string)$r['subdivisions'][0]['names']['en'] : '';
          return ['city'=>$city ?: '', 'region'=>$region ?: ''];
        }
      }
    } catch (\Throwable $e) {}

    return ['city'=>'', 'region'=>''];
  }
}

if (!function_exists('ppf_geo_with_ip_api')) {
  /** ip-api free tier; returns array{city,region} */
  function ppf_geo_with_ip_api(mysqli $conn, string $ip): array {
    if (!ppf_geo_is_public_ip($ip)) return ['city'=>'', 'region'=>''];
    $city = ''; $region = '';
    try {
      $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,message,city,regionName';
      $ctx = stream_context_create(['http' => ['timeout' => 5]]);
      $json = @file_get_contents($url, false, $ctx);
      if ($json !== false) {
        $j = json_decode($json, true);
        if (($j['status'] ?? '') === 'success') {
          $city   = trim((string)($j['city'] ?? '')) ?: '';
          $region = trim((string)($j['regionName'] ?? '')) ?: '';
        } else if (function_exists('ppf_log')) {
          ppf_log($conn, null, null, null, 'geo_lookup_failed', 'system', null, 'ip_api_error=' . ($j['message'] ?? 'unknown'));
        }
      } else if (function_exists('ppf_log')) {
        ppf_log($conn, null, null, null, 'geo_lookup_failed', 'system', null, 'http_fetch_failed');
      }
    } catch (\Throwable $e) {
      if (function_exists('ppf_log')) {
        ppf_log($conn, null, null, null, 'geo_lookup_failed', 'system', null, 'ex=' . $e->getMessage());
      }
    }
    return ['city'=>$city, 'region'=>$region];
  }
}

if (!function_exists('ppf_geo_city_region')) {
  function ppf_geo_city_region(mysqli $conn, string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !ppf_geo_is_public_ip($ip)) {
      return ['city'=>'Unknown', 'region'=>'Unknown'];
    }
    $c = ppf_geo_from_cache($conn, $ip);
    $fresh = false;
    if ($c['looked_at']) {
      $age = time() - strtotime((string)$c['looked_at']);
      $fresh = ($age <= 30 * 24 * 3600);
    }
    if ($fresh && (ppf_geo_is_meaningful($c['city']) || ppf_geo_is_meaningful($c['region']))) {
      return ['city'=>$c['city'] ?: 'Unknown', 'region'=>$c['region'] ?: 'Unknown'];
    }

    $geo = ppf_geo_with_maxmind($ip);
    if (!ppf_geo_is_meaningful($geo['city']) && !ppf_geo_is_meaningful($geo['region'])) {
      $geo = ppf_geo_with_ip_api($conn, $ip);
    }
    ppf_geo_write_cache($conn, $ip, $geo['city'], $geo['region']);
    return ['city'=>$geo['city'] ?: 'Unknown', 'region'=>$geo['region'] ?: 'Unknown'];
  }
}

/* -------- Platform & Browser -------- */

if (!function_exists('ppf_detect_platform')) {
  function ppf_detect_platform(?string $ua = null): string {
    $ua = strtolower($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return 'Unknown';

    // ----- Apple first (order matters) -----
    // Strong signals
    if (strpos($ua, 'iphone') !== false) return 'iPhone (iOS)';
    if (strpos($ua, 'ipad')   !== false) return 'iPad (iPadOS)';
    if (strpos($ua, 'ipod')   !== false) return 'iPod (iOS)';

    // Generic iOS hints that sometimes appear without explicit device tokens
    if (strpos($ua, 'cpu iphone os') !== false) return 'iPhone (iOS)';
    if (strpos($ua, 'cpu os') !== false && strpos($ua, 'like mac os x') !== false) {
      // Often iPad Safari pre-iPadOS 13 or iPhone Safari
      return (strpos($ua, 'mobile') !== false) ? 'iPhone (iOS)' : 'iPad (iPadOS)';
    }
    if (strpos($ua, 'like mac os x') !== false) {
      // Safety net for generic iOS when device token is suppressed
      return (strpos($ua, 'mobile') !== false) ? 'iPhone (iOS)' : 'iPad (iPadOS)';
    }

    // iPadOS desktop-class UA (iPadOS 13+) often includes "Macintosh" but is really iPad
    if ((strpos($ua, 'macintosh') !== false || strpos($ua, 'mac os x') !== false)) {
      if (
        strpos($ua, 'mobile') !== false ||
        strpos($ua, 'ipad')   !== false ||
        strpos($ua, 'cpu os') !== false ||
        strpos($ua, 'like mac os x') !== false
      ) {
        return 'iPad (iPadOS)';
      }
      // Otherwise, real macOS
      return 'macOS';
    }

    // ----- Windows -----
    if (strpos($ua, 'windows nt 10') !== false || strpos($ua,'windows nt 11')!==false) return 'Windows 10/11';
    if (strpos($ua, 'windows nt 6.3') !== false) return 'Windows 8.1';
    if (strpos($ua, 'windows nt 6.2') !== false) return 'Windows 8';
    if (strpos($ua, 'windows nt 6.1') !== false) return 'Windows 7';

    // ----- Mobile/Other -----
    if (strpos($ua, 'android') !== false) return 'Android';
    if (strpos($ua, 'cros')    !== false) return 'ChromeOS';
    if (strpos($ua, 'linux')   !== false) return 'Linux';

    return 'Unknown';
  }
}

if (!function_exists('ppf_detect_browser')) {
  function ppf_detect_browser(?string $ua = null): string {
    $ua = strtolower($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return 'Unknown';
    if (strpos($ua, 'edg') !== false) return 'Microsoft Edge';
    if (strpos($ua, 'chrome') !== false) return 'Google Chrome';
    if (strpos($ua, 'safari') !== false && strpos($ua, 'chrome') === false) return 'Safari';
    if (strpos($ua, 'firefox') !== false) return 'Firefox';
    if (strpos($ua, 'opr') !== false || strpos($ua, 'opera') !== false) return 'Opera';
    if (strpos($ua, 'msie') !== false || strpos($ua, 'trident') !== false) return 'Internet Explorer';
    return 'Unknown';
  }
}

/* -------- MMDB Anonymous-IP -------- */

if (!function_exists('ppf_geo_with_maxmind_anonymous')) {
  /** Authoritative check via GeoIP2 Anonymous-IP. Returns true/false; null if DB not usable. */
  function ppf_geo_with_maxmind_anonymous(string $ip): ?bool {
    if (!ppf_geo_is_public_ip($ip)) return false;
    $mmdb = ppf_geo_mmdb_find([
      __DIR__ . '/data/GeoIP2-Anonymous-IP.mmdb',
      __DIR__ . '/GeoIP2-Anonymous-IP.mmdb',
      __DIR__ . '/mmdb/GeoIP2-Anonymous-IP.mmdb',
      '/usr/share/GeoIP/GeoIP2-Anonymous-IP.mmdb',
      '/var/lib/GeoIP/GeoIP2-Anonymous-IP.mmdb'
    ]);
    if (!$mmdb) return null;

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_readable($autoload)) { @include_once $autoload; }

    try {
      if (class_exists('\\GeoIp2\\Database\\Reader')) {
        $reader = new \GeoIp2\Database\Reader($mmdb);
        $r = $reader->anonymousIp($ip);
        $reader->close();
        return (bool)(
          ($r->isAnonymous       ?? false) ||
          ($r->isAnonymousVpn    ?? false) ||
          ($r->isPublicProxy     ?? false) ||
          ($r->isTorExitNode     ?? false) ||
          ($r->isHostingProvider ?? false)
        );
      }
    } catch (\Throwable $e) {}

    try {
      if (class_exists('\\MaxMind\\Db\\Reader')) {
        $reader = new \MaxMind\Db\Reader($mmdb);
        $r = $reader->get($ip);
        $reader->close();
        if (is_array($r)) {
          foreach (['is_anonymous','is_anonymous_vpn','is_public_proxy','is_tor_exit_node','is_hosting_provider'] as $f) {
            if (!empty($r[$f])) return true;
          }
          return false;
        }
      }
    } catch (\Throwable $e) {}

    return null;
  }
}

/* -------- ASN Org -------- */

if (!function_exists('ppf_geo_as_org')) {
  /** Get ASN org via GeoLite2-ASN; fallback to ip-api isp/org/as if ASN DB missing. */
  function ppf_geo_as_org(string $ip): string {
    if (!ppf_geo_is_public_ip($ip)) return '';

    $mmdb = ppf_geo_mmdb_find([
      __DIR__ . '/data/GeoLite2-ASN.mmdb',
      __DIR__ . '/GeoLite2-ASN.mmdb',
      __DIR__ . '/mmdb/GeoLite2-ASN.mmdb',
      '/usr/share/GeoIP/GeoLite2-ASN.mmdb',
      '/var/lib/GeoIP/GeoLite2-ASN.mmdb'
    ]);

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_readable($autoload)) { @include_once $autoload; }

    if ($mmdb) {
      try {
        if (class_exists('\\GeoIp2\\Database\\Reader')) {
          $reader = new \GeoIp2\Database\Reader($mmdb);
          $rec = $reader->asn($ip);
          $org = (string)($rec->autonomousSystemOrganization ?? '');
          $reader->close();
          if ($org !== '') return $org;
        }
      } catch (\Throwable $e) {}

      try {
        if (class_exists('\\MaxMind\\Db\\Reader')) {
          $reader = new \MaxMind\Db\Reader($mmdb);
          $rec = $reader->get($ip);
          $reader->close();
          if (is_array($rec) && !empty($rec['autonomous_system_organization'])) {
            return (string)$rec['autonomous_system_organization'];
          }
        }
      } catch (\Throwable $e) {}
    }

    try {
      $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,message,isp,org,as';
      $ctx = stream_context_create(['http' => ['timeout' => 5]]);
      $json = @file_get_contents($url, false, $ctx);
      if ($json !== false) {
        $j = json_decode($json, true);
        if (($j['status'] ?? '') === 'success') {
          $parts = [];
          foreach (['org','isp','as'] as $k) {
            $v = trim((string)($j[$k] ?? ''));
            if ($v !== '') $parts[] = $v;
          }
          return trim(implode(' ', array_unique($parts)));
        }
      }
    } catch (\Throwable $e) {}

    return '';
  }
}

/* -------- iCloud detection -------- */

if (!function_exists('ppf_geo_is_icloud')) {
  /**
   * Detect iCloud Private Relay from ASN/Org string.
   * Caches result for 7 days similar to VPN.
   */
  function ppf_geo_is_icloud(mysqli $conn, string $ip): bool {
    if (!ppf_geo_is_public_ip($ip)) return false;
    ppf_geo_ensure_cache_table($conn);

    // cache fast path
    if ($st = $conn->prepare("SELECT is_icloud, icloud_checked_at FROM ip_cache WHERE ip_bin=INET6_ATON(?) LIMIT 1")){
      $st->bind_param("s",$ip); $st->execute(); $rs=$st->get_result();
      if ($rs && ($row=$rs->fetch_assoc())) {
        $checked = !empty($row['icloud_checked_at']) ? strtotime((string)$row['icloud_checked_at']) : 0;
        if ($checked && (time() - $checked) <= 7*24*3600) {
          return (int)($row['is_icloud'] ?? 0) === 1;
        }
      }
      $st->close();
    }

    $aso = strtolower(trim(ppf_geo_as_org($ip)));
    $is = false;
    if ($aso !== '') {
      $is = (strpos($aso,'private relay') !== false)
        || (strpos($aso,'icloud') !== false && strpos($aso,'relay') !== false)
        || (strpos($aso,'apple') !== false && strpos($aso,'relay') !== false);
    }
    ppf_geo_write_icloud_cache($conn, $ip, $is);
    // if icloud, also ensure VPN cache false to avoid stale "VPN"
    if ($is) ppf_geo_write_vpn_cache($conn, $ip, false);
    return $is;
  }
}

/* -------- VPN detection -------- */

if (!function_exists('ppf_geo_is_vpn')) {
  function ppf_geo_is_vpn(mysqli $conn, string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !ppf_geo_is_public_ip($ip)) return false;

    // Do not label iCloud as VPN
    try { if (ppf_geo_is_icloud($conn, $ip)) { ppf_geo_write_vpn_cache($conn, $ip, false); return false; } } catch (\Throwable $e) {}

    // Cache first (7d TTL)
    $c = ppf_geo_from_cache($conn, $ip);
    if (!empty($c['vpn_checked_at'])) {
      $age = time() - strtotime((string)$c['vpn_checked_at']);
      if ($age <= 7 * 24 * 3600 && $c['is_vpn'] !== null) return (bool)$c['is_vpn'];
    }

    // 1) Authoritative Anonymous-IP
    $flag = ppf_geo_with_maxmind_anonymous($ip);
    if ($flag !== null) {
      ppf_geo_write_vpn_cache($conn, $ip, (bool)$flag);
      return (bool)$flag;
    }

    // 2) Heuristic on org/isp/as
    $aso = strtolower(trim(ppf_geo_as_org($ip)));
    $isVpn = false;
    if ($aso !== '') {
      $keywords = [
        'vpn','proxy','host','hosting','cloud','colo','datacenter','data center','dc','vps',
        'amazon','aws','ec2','azure','microsoft','google','gcp','oracle','oci','alibaba','aliyun',
        'ovh','hetzner','linode','akamai','vultr','contabo','leaseweb','choopa','sharktech','m247','digitalocean',
        'packet','equinix','hivelocity','ionos','rackspace','upcloud','scaleway','wasabi','telehouse','soyoustart',
      ];
      foreach ($keywords as $k) {
        if (strpos($aso, $k) !== false) { $isVpn = true; break; }
      }
    }

    ppf_geo_write_vpn_cache($conn, $ip, $isVpn);
    return $isVpn;
  }
}