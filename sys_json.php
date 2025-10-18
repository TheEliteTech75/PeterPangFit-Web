<?php
// sys_json.php — ultra-light system stats endpoint for dashboard polling
// No sessions, no DB, no header/nav includes.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function parse_linux_cpu_totals(?string $contents): ?array {
  if (!$contents) return null;
  foreach (explode("\n", $contents) as $line) {
    $line = trim($line);
    if (strpos($line, 'cpu ') !== 0) continue;
    $parts = preg_split('/\s+/', trim(substr($line, 3)));
    if (!$parts || count($parts) < 4) return null;
    $values = array_map('floatval', $parts);
    $idle = $values[3] + ($values[4] ?? 0.0);
    $total = array_sum($values);
    if (!is_finite($idle) || !is_finite($total) || $total <= 0) return null;
    return ['idle' => $idle, 'total' => $total];
  }
  return null;
}

function linux_cpu_usage_ratio(): ?float {
  $first = parse_linux_cpu_totals(@file_get_contents('/proc/stat'));
  if (!$first) return null;
  if (function_exists('usleep')) usleep(100000);
  $second = parse_linux_cpu_totals(@file_get_contents('/proc/stat'));
  if (!$second) return null;
  $totalDiff = $second['total'] - $first['total'];
  if (!($totalDiff > 0)) return null;
  $idleDiff = $second['idle'] - $first['idle'];
  $usage = 1 - ($idleDiff / $totalDiff);
  if (!is_finite($usage)) return null;
  return max(0.0, min(1.0, $usage));
}

function windows_shell_available(): bool {
  if (!function_exists('shell_exec')) return false;
  $disabled = (string)ini_get('disable_functions');
  if ($disabled) {
    $list = array_map('trim', explode(',', strtolower($disabled)));
    if (in_array('shell_exec', $list, true)) return false;
  }
  return true;
}

function windows_wmi_service() {
  static $serviceInitialized = false;
  static $service = null;
  if ($serviceInitialized) return $service;
  $serviceInitialized = true;
  if (!class_exists('COM')) return $service = null;
  try {
    $locator = new COM('WbemScripting.SWbemLocator');
    $service = $locator->ConnectServer('.', 'root\\CIMV2');
  } catch (Throwable $e) {
    $service = null;
  }
  return $service;
}

function windows_memory_info_via_com(): ?array {
  $service = windows_wmi_service();
  if (!$service) return null;
  try {
    $items = $service->ExecQuery('SELECT TotalVisibleMemorySize, FreePhysicalMemory FROM Win32_OperatingSystem');
    if ($items) {
      foreach ($items as $item) {
        $total = isset($item->TotalVisibleMemorySize) ? (float)$item->TotalVisibleMemorySize : null;
        $free  = isset($item->FreePhysicalMemory) ? (float)$item->FreePhysicalMemory : null;
        if ($total !== null && $free !== null) {
          return [
            'total' => (int)round($total * 1024),
            'free'  => (int)round($free * 1024)
          ];
        }
      }
    }
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

function windows_cpu_usage_via_com(): ?float {
  $service = windows_wmi_service();
  if (!$service) return null;
  try {
    $items = $service->ExecQuery("SELECT Name, PercentProcessorTime FROM Win32_PerfFormattedData_PerfOS_Processor");
    $total = null; $sum = 0.0; $count = 0;
    if ($items) {
      foreach ($items as $item) {
        $name = isset($item->Name) ? (string)$item->Name : '';
        $val = isset($item->PercentProcessorTime) ? (float)$item->PercentProcessorTime : null;
        if ($val === null) continue;
        if ($name === '_Total') {
          $total = $val;
        } else {
          $sum += $val;
          $count++;
        }
      }
      if ($total === null && $count > 0) {
        $total = $sum / $count;
      }
      if ($total !== null && is_finite($total)) {
        return max(0.0, min(100.0, $total));
      }
    }
  } catch (Throwable $e) {
    // ignore and fallback
  }
  try {
    $items = $service->ExecQuery('SELECT LoadPercentage FROM Win32_Processor');
    if ($items) {
      $sum = 0.0; $count = 0;
      foreach ($items as $item) {
        $val = isset($item->LoadPercentage) ? (float)$item->LoadPercentage : null;
        if ($val === null) continue;
        $sum += $val; $count++;
      }
      if ($count > 0) {
        $avg = $sum / $count;
        if (is_finite($avg)) return max(0.0, min(100.0, $avg));
      }
    }
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

function read_sys_stats_snapshot(): array {
  $os = PHP_OS_FAMILY ?? php_uname('s');
  $cpu_pct = null; $ram_used_pct = null; $disk_used_pct = null;
  $rx = null; $tx = null;

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
    $total = null; $free = null;
    $mem = windows_memory_info_via_com();
    if ($mem) {
      $total = $mem['total'] ?? null;
      $free  = $mem['free'] ?? null;
    }
    if (!isset($total) || !isset($free)) {
      if (windows_shell_available()) {
        $outT = @shell_exec('wmic OS get TotalVisibleMemorySize /value');
        $outF = @shell_exec('wmic OS get FreePhysicalMemory /value');
        if ($outT && preg_match('/TotalVisibleMemorySize=(\d+)/', $outT, $m1)) $total = (int)$m1[1] * 1024;
        if ($outF && preg_match('/FreePhysicalMemory=(\d+)/', $outF, $m2)) $free  = (int)$m2[1] * 1024;
      }
    }
    if (!empty($total) && isset($free)) {
      $ram_used_pct = max(0, min(100, round((1 - ($free / $total)) * 100)));
    }

    $cpuFromCom = windows_cpu_usage_via_com();
    if ($cpuFromCom !== null) {
      $cpu_pct = (int)round(min(100, max(0, $cpuFromCom)));
    }

    if ($cpu_pct === null && windows_shell_available()) {
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
    }

    if ($cpu_pct === null && windows_shell_available()) {
      $ps = @shell_exec("powershell -NoProfile -Command \"(Get-Counter '\\Processor(_Total)\\% Processor Time').CounterSamples[0].CookedValue\" 2>&1");
      if ($ps && preg_match('/([\d\.,]+)/', $ps, $mm)) {
        $val = (float) str_replace(',', '.', $mm[1]);
        if (is_finite($val)) $cpu_pct = (int)round(min(100, max(0, $val)));
      }
    }

    if ($cpu_pct === null && windows_shell_available()) {
      $wm = @shell_exec('wmic cpu get loadpercentage /value');
      if ($wm && preg_match('/LoadPercentage=(\d+)/', $wm, $mc)) {
        $cpu_pct = max(0, min(100, (int)$mc[1]));
      }
    }

    if (windows_shell_available()) {
      $out = @shell_exec('netstat -e');
      if ($out && preg_match('/Bytes\s+(\d+)\s+(\d+)/i', $out, $mm)) {
        $rx = (float)$mm[1]; $tx = (float)$mm[2];
      }
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
    $ratio = linux_cpu_usage_ratio();
    if ($ratio !== null) {
      $cpu_pct = max(0, min(100, round($ratio * 100)));
    } else {
      // CPU via loadavg/cores fallback
      $loads = @sys_getloadavg();
      $cores = null;
      $nproc = @trim((string)@shell_exec('nproc 2>/dev/null'));
      if (ctype_digit($nproc)) $cores = (int)$nproc;
      if (!$cores) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo) $cores = substr_count($cpuinfo, "processor\t:");
      }
      if ($loads && $cores && $cores > 0) $cpu_pct = max(0, min(100, round(($loads[0] / $cores) * 100)));
    }

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
