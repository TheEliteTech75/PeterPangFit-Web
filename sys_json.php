<?php
// sys_json.php — ultra-light system stats endpoint for dashboard polling
// No sessions, no DB, no header/nav includes.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function read_sys_stats_snapshot(): array {
  $os = PHP_OS_FAMILY ?? php_uname('s');
  $cpu_pct = null; $ram_used_pct = null; $disk_used_pct = null;

  // Disk
  if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $disk_total = @disk_total_space('C:'); $disk_free = @disk_free_space('C:');
  } else {
    $disk_total = @disk_total_space('/');  $disk_free = @disk_free_space('/');
  }
  if ($disk_total && $disk_total > 0 && $disk_free !== false) {
    $disk_used_pct = max(0, min(100, round((1 - ($disk_free / $disk_total)) * 100)));
  }

  if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // ---- Windows branch ----
    // RAM via WMIC
    $outT = @shell_exec('wmic OS get TotalVisibleMemorySize /value');
    $outF = @shell_exec('wmic OS get FreePhysicalMemory /value');
    if ($outT && preg_match('/TotalVisibleMemorySize=(\d+)/', $outT, $m1)) $total = (int)$m1[1] * 1024;
    if ($outF && preg_match('/FreePhysicalMemory=(\d+)/', $outF, $m2)) $free  = (int)$m2[1] * 1024;
    if (!empty($total) && isset($free)) $ram_used_pct = max(0, min(100, round((1 - ($free / $total)) * 100)));

    // CPU via typeperf -> PS -> WMIC
    $cpu_pct = null;
    $tp = @shell_exec('typeperf "\\Processor(_Total)\\% Processor Time" -sc 1 2>&1');
    if ($tp) {
      $lines = array_values(array_filter(array_map('trim', explode("\n", $tp))));
      foreach (array_reverse($lines) as $ln) {
        if (preg_match('/^\".*?\"\,\"([\d\.\,]+)\"$/', $ln, $m)) {
          $val = str_replace(',', '.', $m[1]);
          $pct = (float)$val;
          if (is_finite($pct)) { $cpu_pct = (int)round(min(100, max(0, $pct))); break; }
        }
      }
    }
    if ($cpu_pct === null) {
      $ps = @shell_exec("powershell -NoProfile -Command \"(Get-Counter '\\Processor(_Total)\\% Processor Time').CounterSamples[0].CookedValue\" 2>&1");
      if ($ps && preg_match('/([\d\.,]+)/', $ps, $mm)) {
        $val = (float) str_replace(',', '.', $mm[1]);
        if (is_finite($val)) $cpu_pct = (int)round(min(100, max(0, $val)));
      }
    }
    if ($cpu_pct === null) {
      $wm = @shell_exec('wmic cpu get loadpercentage /value');
      if ($wm && preg_match('/LoadPercentage=(\d+)/', $wm, $mc)) {
        $cpu_pct = max(0, min(100, (int)$mc[1]));
      }
    }

    // NET cumulative
    $rx = null; $tx = null;
    $out = @shell_exec('netstat -e');
    if ($out && preg_match('/Bytes\s+(\d+)\s+(\d+)/i', $out, $mm)) {
      $rx = (float)$mm[1]; $tx = (float)$mm[2];
    }
  } else {
    // ---- Linux branch ----
    // RAM
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
      if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) $tot = (int)$m[1] * 1024;
      if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $a)) $avail = (int)$a[1] * 1024;
      if (!empty($tot) && isset($avail)) $ram_used_pct = max(0, min(100, round((1 - ($avail / $tot)) * 100)));
    }
    // CPU via loadavg/cores
    $loads = @sys_getloadavg();
    $cores = null;
    $nproc = @trim((string)@shell_exec('nproc 2>/dev/null'));
    if (ctype_digit($nproc)) $cores = (int)$nproc;
    if (!$cores) {
      $cpuinfo = @file_get_contents('/proc/cpuinfo');
      if ($cpuinfo) $cores = substr_count($cpuinfo, "processor\t:");
    }
    if ($loads && $cores && $cores > 0) $cpu_pct = max(0, min(100, round(($loads[0] / $cores) * 100)));

    // NET cumulative
    $rx = null; $tx = null;
    $dev = @file_get_contents('/proc/net/dev');
    if ($dev) {
      $lines = explode("\n", $dev); $sum_rx = 0; $sum_tx = 0;
      foreach ($lines as $ln) {
        if (strpos($ln, ':') === false) continue;
        [$iface, $rest] = array_map('trim', explode(':', $ln, 2));
        if ($iface === 'lo' || $iface === 'lo0') continue;
        $cols = preg_split('/\s+/', trim($rest));
        if (isset($cols[0], $cols[8])) { $sum_rx += (float)$cols[0]; $sum_tx += (float)$cols[8]; }
      }
      $rx = $sum_rx; $tx = $sum_tx;
    }
  }

  return [
    'os' => $os,
    'cpu_pct' => $cpu_pct,
    'ram_used_pct' => $ram_used_pct,
    'disk_used_pct' => $disk_used_pct,
    'net' => ['rx_bytes' => $rx, 'tx_bytes' => $tx],
    'ts'  => microtime(true)
  ];
}

// Optional: super-light 1s cache to calm Windows shelling
// Requires APCu enabled; safe to remove if not available.
if (function_exists('apcu_fetch')) {
  $k = 'sys_json_cache';
  $cached = apcu_fetch($k);
  if ($cached && isset($cached['ts']) && (microtime(true) - $cached['ts'] <= 1.0)) {
    echo json_encode($cached); exit;
  }
  $snap = read_sys_stats_snapshot();
  apcu_store($k, $snap, 2);
  echo json_encode($snap); exit;
}

echo json_encode(read_sys_stats_snapshot());
