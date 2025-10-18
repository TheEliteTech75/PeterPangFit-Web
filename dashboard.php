<?php
// dashboard.php — PeterPangFit
// Updates:
// - Exercises card: donut + legend centered within card and moved up a bit; responsive size retained.
// - My Workout Plans: uses assignment date; rows clickable to client_plans.php.
// - System Resources: vertical bar graph (CPU/RAM/Storage) with improved visuals.
// - NEW: System Resources now shows live Network Download/Upload activity (Linux + Windows).
// - NEW: Invites adds Expired and Registered counts (donut now 4 segments).
// - FIX: Added JS poller for sys_json endpoint to compute Mbps & refresh CPU/RAM/Disk live.
// - FIX (Windows): CPU percentage now uses `typeperf` (WMIC fallback) for modern Windows.
//
// Requires:
//   auth.php -> $USER_ID, $USER_ROLE, $USER_NAME (optional)
//   db.php   -> $conn (mysqli)
//   ppf_header.php -> site header / nav
//   ppf_nav.php    -> nav

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

function safe_count_sql(mysqli $conn, string $sql, string $types = '', ...$params): ?int {
  try {
    if ($types === '') {
      $res = $conn->query($sql);
      if ($res) { $row = $res->fetch_row(); return isset($row[0]) ? (int)$row[0] : null; }
      return null;
    } else {
      $stmt = $conn->prepare($sql);
      if (!$stmt) return null;
      if ($types) $stmt->bind_param($types, ...$params);
      if (!$stmt->execute()) { $stmt->close(); return null; }
      $res = $stmt->get_result(); $row = $res?->fetch_row();
      $stmt->close();
      return isset($row[0]) ? (int)$row[0] : null;
    }
  } catch (Throwable $e) { return null; }
}

function safe_row_sql(mysqli $conn, string $sql, string $types = '', ...$params): ?array {
  try {
    if ($types === '') {
      $res = $conn->query($sql);
      if ($res) { $row = $res->fetch_assoc(); return $row ?: null; }
      return null;
    } else {
      $stmt = $conn->prepare($sql);
      if (!$stmt) return null;
      $stmt->bind_param($types, ...$params);
      if (!$stmt->execute()) { $stmt->close(); return null; }
      $res = $stmt->get_result(); $row = $res?->fetch_assoc();
      $stmt->close();
      return $row ?: null;
    }
  } catch (Throwable $e) { return null; }
}

/* ---------- Small sys JSON endpoint (CPU/RAM/Disk + NET RX/TX cumulative) ---------- */
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
  if (function_exists('usleep')) usleep(100000); // ~0.1s sample window
  $second = parse_linux_cpu_totals(@file_get_contents('/proc/stat'));
  if (!$second) return null;
  $totalDiff = $second['total'] - $first['total'];
  if (!($totalDiff > 0)) return null;
  $idleDiff = $second['idle'] - $first['idle'];
  $usage = 1 - ($idleDiff / $totalDiff);
  if (!is_finite($usage)) return null;
  return max(0.0, min(1.0, $usage));
}

function read_sys_stats_snapshot(): array {
  $os = PHP_OS_FAMILY ?? php_uname('s');
  $cpu_pct = null; $ram_used_pct = null; $disk_used_pct = null;

  // Disk
  if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $disk_total = @disk_total_space('C:'); $disk_free = @disk_free_space('C:');
  } else {
    $disk_total = @disk_total_space('/'); $disk_free = @disk_free_space('/');
  }
  if ($disk_total && $disk_total > 0 && $disk_free !== false) {
    $disk_used_pct = max(0, min(100, round((1 - ($disk_free / $disk_total)) * 100)));
  }

  // RAM + CPU
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
    // Fallback to load average per core
    $loads = @sys_getloadavg();
    $cores = null;
    $nproc = @trim((string)@shell_exec('nproc 2>/dev/null'));
    if (ctype_digit($nproc)) $cores = (int)$nproc;
    if (!$cores) {
      $cpuinfo = @file_get_contents('/proc/cpuinfo');
      if ($cpuinfo) $cores = substr_count($cpuinfo, "processor\t:");
    }
    if ($loads && $cores && $cores > 0) {
      $cpu_pct = max(0, min(100, round(($loads[0] / $cores) * 100)));
    }
  }
}


  // NET cumulative bytes
  $rx = null; $tx = null;
  if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // netstat -e (cumulative since boot)
    $out = @shell_exec('netstat -e');
    if ($out && preg_match('/Bytes\s+(\d+)\s+(\d+)/i', $out, $mm)) {
      $rx = (float)$mm[1]; $tx = (float)$mm[2];
    }
  } else {
    // Sum all non-loopback interfaces in /proc/net/dev
    $dev = @file_get_contents('/proc/net/dev');
    if ($dev) {
      $lines = explode("\n", $dev);
      $sum_rx = 0; $sum_tx = 0;
      foreach ($lines as $ln) {
        if (strpos($ln, ':') === false) continue;
        [$iface, $rest] = array_map('trim', explode(':', $ln, 2));
        if ($iface === 'lo' || $iface === 'lo0') continue;
        $cols = preg_split('/\s+/', trim($rest));
        if (isset($cols[0], $cols[8])) {
          $sum_rx += (float)$cols[0];
          $sum_tx += (float)$cols[8];
        }
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

if (isset($_GET['sys_json'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo json_encode(read_sys_stats_snapshot());
  exit;
}


/* ---------- Determine role / client flag ---------- */
$is_client = false;
$role = $USER_ROLE ?? '';
try {
  if (isset($USER_ID) && is_numeric($USER_ID)) {
    if ($stmt = $conn->prepare("SELECT COALESCE(is_client, 0) AS is_client, COALESCE(role, '') AS role FROM users WHERE id = ? LIMIT 1")) {
      $stmt->bind_param('i', $USER_ID);
      if ($stmt->execute() && ($res = $stmt->get_result()) && ($row = $res->fetch_assoc())) {
        $is_client = (intval($row['is_client'] ?? 0) === 1) || (strtolower((string)$row['role']) === 'client');
        $role = $row['role'] ?: $role;
      }
      $stmt->close();
    }
  }
} catch (Throwable $e) { /* non-fatal */ }

$can_admin = in_array(strtolower($role), ['admin','trainer'], true);
$is_admin  = (strtolower($role) === 'admin');

/* ---------- Topline metrics (admin/trainer) ---------- */
$total_clients   = safe_count_sql($conn, "SELECT COUNT(*) FROM users WHERE (role = 'client' OR is_client = 1)");
$workout_plans   = safe_count_sql($conn, "SELECT COUNT(*) FROM workout_plans");
$exercises_total = safe_count_sql($conn, "SELECT COUNT(*) FROM exercises");

/* ---------- Invites (now: accepted, pending, expired, registered) ---------- */
$now = date('Y-m-d H:i:s');
$invite_counts = safe_row_sql(
  $conn,
  "
  SELECT
    SUM(
      CASE
        WHEN cancelled_at IS NOT NULL THEN 0
        WHEN (used = 1 OR completed_at IS NOT NULL) THEN 0
        WHEN (expires_at IS NOT NULL AND expires_at < ?) THEN 0
        WHEN accepted_at IS NOT NULL THEN 1
        ELSE 0
      END
    ) AS accepted,
    SUM(
      CASE
        WHEN cancelled_at IS NOT NULL THEN 0
        WHEN (used = 1 OR completed_at IS NOT NULL) THEN 0
        WHEN (expires_at IS NOT NULL AND expires_at < ?) THEN 0
        WHEN accepted_at IS NULL THEN 1
        ELSE 0
      END
    ) AS pending,
    SUM(
      CASE
        WHEN cancelled_at IS NOT NULL THEN 0
        WHEN (used = 1 OR completed_at IS NOT NULL) THEN 0
        WHEN (expires_at IS NOT NULL AND expires_at < ?) THEN 1
        ELSE 0
      END
    ) AS expired,
    SUM(
      CASE
        WHEN cancelled_at IS NOT NULL THEN 0
        WHEN (used = 1 OR completed_at IS NOT NULL) THEN 1
        ELSE 0
      END
    ) AS registered
  FROM invites
  ",
  "sss",
  $now, $now, $now
) ?? ['accepted'=>0,'pending'=>0,'expired'=>0,'registered'=>0];

$pending_invites   = (int)($invite_counts['pending']    ?? 0);
$accepted_invites  = (int)($invite_counts['accepted']   ?? 0);
$expired_invites   = (int)($invite_counts['expired']    ?? 0);
$registered_invites= (int)($invite_counts['registered'] ?? 0);

/* ---------- Mapping helpers ---------- */
function find_assignment_mapping(mysqli $conn): array {
  $candidates = [
    ['user_plans',               'user_id', 'plan_id'],           // preferred (your schema)
    ['plan_assignments',         'user_id', 'plan_id'],
    ['client_plans',             'user_id', 'plan_id'],
    ['workout_plan_assignments', 'user_id', 'plan_id'],
  ];
  foreach ($candidates as [$t,$uc,$pc]) {
    if (table_exists($conn, $t) && column_exists($conn, $t, $uc) && column_exists($conn, $t, $pc)) {
      $assign_col = column_exists($conn,$t,'assigned_at') ? 'assigned_at' :
                    (column_exists($conn,$t,'created_at') ? 'created_at' : null);
      $id_col = column_exists($conn,$t,'id') ? 'id' : null;
      return ['table'=>$t,'id_col'=>$id_col,'user_col'=>$uc,'plan_col'=>$pc,'assign_col'=>$assign_col];
    }
  }
  return ['table'=>null,'id_col'=>null,'user_col'=>'user_id','plan_col'=>'plan_id','assign_col'=>null];
}
function find_plan_exercise_link(mysqli $conn): array {
  $candidates = [
    ['user_plan_exercises', 'user_plan_id', 'exercise_id'],
    ['plan_exercises',          'plan_id', 'exercise_id'],
    ['workout_plan_exercises',  'plan_id', 'exercise_id'],
    ['workout_plan_items',      'plan_id', 'exercise_id'],
  ];
  foreach ($candidates as [$t,$pc,$ec]) {
    if (table_exists($conn,$t) && column_exists($conn,$t,$pc) && column_exists($conn,$t,$ec)) {
      return ['table'=>$t,'plan_col'=>$pc,'exercise_col'=>$ec];
    }
  }
  return ['table'=>null,'plan_col'=>'plan_id','exercise_col'=>'exercise_id'];
}
function find_plan_table(mysqli $conn): array {
  $tables = ['workout_plans','plans','training_plans'];
  $nameCols = ['name','title','plan_name'];
  foreach ($tables as $t) {
    if (!table_exists($conn,$t)) continue;
    $idCol = column_exists($conn,$t,'id') ? 'id' : (column_exists($conn,$t,'plan_id') ? 'plan_id' : null);
    if (!$idCol) continue;
    foreach ($nameCols as $nc) {
      if (column_exists($conn,$t,$nc)) return ['table'=>$t,'id'=>$idCol,'name'=>$nc];
    }
    return ['table'=>$t,'id'=>$idCol,'name'=>null];
  }
  return ['table'=>null,'id'=>'id','name'=>null];
}
function find_category_source(mysqli $conn): array {
  if (table_exists($conn,'exercises')) {
    if (column_exists($conn,'exercises','category')) {
      return ['mode'=>'text','ex_table'=>'exercises','ex_cat_col'=>'category','cat_table'=>null,'cat_id'=>null,'cat_name'=>null];
    }
    if (column_exists($conn,'exercises','category_id')) {
      $candidates = ['exercise_categories','categories','exercise_types'];
      $nameCols = ['name','title','label'];
      foreach ($candidates as $ct) {
        if (!table_exists($conn,$ct)) continue;
        $idCol = column_exists($conn,$ct,'id') ? 'id' : (column_exists($conn,$ct,'category_id') ? 'category_id' : null);
        if (!$idCol) continue;
        foreach ($nameCols as $nc) {
          if (column_exists($conn,$ct,$nc)) {
            return ['mode'=>'join','ex_table'=>'exercises','ex_cat_col'=>'category_id','cat_table'=>$ct,'cat_id'=>$idCol,'cat_name'=>$nc];
          }
        }
      }
    }
  }
  return ['mode'=>null,'ex_table'=>null,'ex_cat_col'=>null,'cat_table'=>null,'cat_id'=>null,'cat_name'=>null];
}

/* ---------- User-specific counts ---------- */
$am = find_assignment_mapping($conn);
$pe = find_plan_exercise_link($conn);

$plans_assigned_count = null;
$total_exercises_in_my_plans = null;

if (isset($USER_ID) && is_numeric($USER_ID) && $am['table']) {
  $plans_assigned_count = safe_count_sql(
    $conn,
    "SELECT COUNT(*) FROM {$am['table']} WHERE {$am['user_col']} = ?",
    "i",
    $USER_ID
  );

  if ($pe['table']) {
    if ($pe['plan_col'] === 'user_plan_id' && $am['id_col']) {
      $total_exercises_in_my_plans = safe_count_sql(
        $conn,
        "SELECT COUNT(*) AS c
           FROM {$pe['table']} pe
           INNER JOIN {$am['table']} a ON a.{$am['id_col']} = pe.{$pe['plan_col']}
          WHERE a.{$am['user_col']} = ?",
        "i",
        $USER_ID
      );
    } else {
      $total_exercises_in_my_plans = safe_count_sql(
        $conn,
        "SELECT COUNT(*) AS c
           FROM {$pe['table']} pe
           INNER JOIN {$am['table']} a ON a.{$am['plan_col']} = pe.{$pe['plan_col']}
          WHERE a.{$am['user_col']} = ?",
        "i",
        $USER_ID
      );
    }
  }
}

/* ---------- Exercises by Category (per user) ---------- */
$category_rows = [];
$total_categories_for_user = 0;

if (isset($USER_ID) && is_numeric($USER_ID) && $am['table'] && $pe['table'] && table_exists($conn,'exercises')) {
  $cat = find_category_source($conn);
  if ($pe['plan_col'] === 'user_plan_id' && $am['id_col']) {
    $joinPlan = "pe.user_plan_id = a.{$am['id_col']}";
  } else {
    $joinPlan = "pe.{$pe['plan_col']} = a.{$am['plan_col']}";
  }

  if ($cat['mode'] === 'text') {
    $sql = "
      SELECT COALESCE(NULLIF(TRIM(e.{$cat['ex_cat_col']}),''), 'Uncategorized') AS cat_name,
             COUNT(DISTINCT e.id) AS c
      FROM {$am['table']} a
      JOIN {$pe['table']} pe ON $joinPlan
      JOIN exercises e ON e.id = pe.{$pe['exercise_col']}
      WHERE a.{$am['user_col']} = ?
      GROUP BY cat_name
      ORDER BY c DESC, cat_name ASC
    ";
  } elseif ($cat['mode'] === 'join' && $cat['cat_table']) {
    $sql = "
      SELECT COALESCE(ct.{$cat['cat_name']}, 'Uncategorized') AS cat_name,
             COUNT(DISTINCT e.id) AS c
      FROM {$am['table']} a
      JOIN {$pe['table']} pe ON $joinPlan
      JOIN exercises e ON e.id = pe.{$pe['exercise_col']}
      LEFT JOIN {$cat['cat_table']} ct ON ct.{$cat['cat_id']} = e.{$cat['ex_cat_col']}
      WHERE a.{$am['user_col']} = ?
      GROUP BY cat_name
      ORDER BY c DESC, cat_name ASC
    ";
  } else {
    $sql = null;
  }

  if ($sql && ($stmt = $conn->prepare($sql))) {
    $stmt->bind_param('i', $USER_ID);
    if ($stmt->execute() && ($res = $stmt->get_result())) {
      while ($row = $res->fetch_assoc()) {
        $category_rows[] = ['name'=>$row['cat_name'] ?? 'Uncategorized', 'count'=>(int)($row['c'] ?? 0)];
      }
    }
    $stmt->close();
  }

  $total_categories_for_user = count($category_rows);
}

/* ---------- My Workout Plans summary (client) using ASSIGNED date ---------- */
$my_plan_summaries = [];
$my_plan_max_ex_cnt = 0;
if ($is_client && isset($USER_ID) && is_numeric($USER_ID) && $am['table']) {
  $pt = find_plan_table($conn);
  if ($pt['table']) {
    $assignCol = $am['assign_col']; // assigned_at or created_at or null
    $nameExpr = $pt['name']
      ? "COALESCE(NULLIF(p.{$pt['name']},''), CONCAT('Plan #', p.{$pt['id']}))"
      : "CONCAT('Plan #', p.{$pt['id']})";

    if ($pe['table'] && $pe['plan_col'] === 'user_plan_id' && $am['id_col']) {
      $exCountExpr = "COUNT(DISTINCT pe.{$pe['exercise_col']})";
      $exJoin = "LEFT JOIN {$pe['table']} pe ON pe.user_plan_id = a.{$am['id_col']}";
    } elseif ($pe['table']) {
      $exCountExpr = "COUNT(DISTINCT pe.{$pe['exercise_col']})";
      $exJoin = "LEFT JOIN {$pe['table']} pe ON pe.{$pe['plan_col']} = p.{$pt['id']}";
    } else {
      $exCountExpr = "0";
      $exJoin = "";
    }

    $assignSelect = $assignCol ? "a.{$assignCol} AS assigned_at" : "NULL AS assigned_at";

    $sql = "
      SELECT
        a.{$am['id_col']} AS user_plan_id,
        p.{$pt['id']} AS plan_id,
        {$nameExpr} AS pname,
        {$assignSelect},
        {$exCountExpr} AS ex_cnt
      FROM {$am['table']} a
      JOIN {$pt['table']} p ON p.{$pt['id']} = a.{$am['plan_col']}
      {$exJoin}
      WHERE a.{$am['user_col']} = ?
      GROUP BY a.{$am['id_col']}, p.{$pt['id']}, pname, assigned_at
      ORDER BY COALESCE(assigned_at, '1970-01-01') DESC, a.{$am['id_col']} DESC
      LIMIT 5
    ";
    if ($stmt = $conn->prepare($sql)) {
      $stmt->bind_param('i', $USER_ID);
      if ($stmt->execute() && ($res = $stmt->get_result())) {
        while ($row = $res->fetch_assoc()) {
          $exCnt = (int)($row['ex_cnt'] ?? 0);
          $my_plan_summaries[] = [
            'user_plan_id' => (int)$row['user_plan_id'],
            'plan_id'      => (int)$row['plan_id'],
            'name'         => (string)($row['pname'] ?? ('Plan #'.(int)$row['plan_id'])),
            'ex_cnt'       => $exCnt,
            'assigned_at'  => $row['assigned_at'] ?? null,
          ];
          if ($exCnt > $my_plan_max_ex_cnt) $my_plan_max_ex_cnt = $exCnt;
        }
      }
      $stmt->close();
    }
  }
}

/* ---------- Security card metrics ---------- */
$SEC_MAX = 100;
$security_score = 0;
$security_score_pct = 0;
$security_segments = [];
$security_donut_segments = [];
$security_donut_svg = [];
$security_tips = [];
$security_summary = '';
$security_grade = '—';
$donutS = ['r' => 44, 'sw' => 12];
$donutS['C'] = 2 * M_PI * $donutS['r'];

if (isset($USER_ID) && is_numeric($USER_ID)) {
  $has_pwd_hash   = column_exists($conn, 'users', 'password_hash');
  $has_twofa_app  = column_exists($conn, 'users', 'twofa_app_enabled');
  $has_twofa_email= column_exists($conn, 'users', 'twofa_email_enabled');

  $selectCols = [];
  $selectCols[] = $has_pwd_hash ? 'password_hash' : "'' AS password_hash";
  $selectCols[] = $has_twofa_app ? 'COALESCE(twofa_app_enabled,0) AS twofa_app_enabled' : '0 AS twofa_app_enabled';
  $selectCols[] = $has_twofa_email ? 'COALESCE(twofa_email_enabled,0) AS twofa_email_enabled' : '0 AS twofa_email_enabled';
  $selectCols[] = 'NULL AS twofa_secret';

  $security_user_row = safe_row_sql(
    $conn,
    'SELECT ' . implode(', ', $selectCols) . ' FROM users WHERE id = ? LIMIT 1',
    'i',
    $USER_ID
  ) ?? [];

  $password_hash = (string)($security_user_row['password_hash'] ?? '');
  $twofa_app_enabled = $has_twofa_app && ((int)($security_user_row['twofa_app_enabled'] ?? 0) === 1);
  $twofa_email_enabled = $has_twofa_email && ((int)($security_user_row['twofa_email_enabled'] ?? 0) === 1);

  $passkey_count = 0;
  if (table_exists($conn, 'passkeys')) {
    $pkCnt = safe_count_sql($conn, 'SELECT COUNT(*) FROM passkeys WHERE user_id = ?', 'i', $USER_ID);
    if ($pkCnt !== null) $passkey_count = (int)$pkCnt;
  }

  $trusted_counts = ['total' => 0, 'recent' => 0, 'expired' => 0];
  if (table_exists($conn, 'trusted_devices')) {
    $recentExpr = column_exists($conn, 'trusted_devices', 'last_used_at')
      ? 'SUM(CASE WHEN last_used_at IS NOT NULL AND last_used_at >= (NOW() - INTERVAL 45 DAY) THEN 1 ELSE 0 END)'
      : '0';
    $trusted_row = safe_row_sql(
      $conn,
      'SELECT COUNT(*) AS total,
              SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN 1 ELSE 0 END) AS expired,
              ' . $recentExpr . ' AS recent
         FROM trusted_devices
        WHERE user_id = ?',
      'i',
      $USER_ID
    );
    if ($trusted_row) {
      $trusted_counts['total'] = (int)($trusted_row['total'] ?? 0);
      $trusted_counts['recent'] = (int)($trusted_row['recent'] ?? 0);
      $trusted_counts['expired'] = (int)($trusted_row['expired'] ?? 0);
    }
  }

  $status_labels = ['good' => 'Strong', 'ok' => 'Okay', 'warn' => 'Needs attention'];

  // Password strength contribution (max 30)
  $pwd_points = 0;
  $pwd_status = 'warn';
  $pwd_detail = '';
  if ($password_hash === '') {
    if ($passkey_count > 0) {
      $pwd_points = 12;
      $pwd_status = 'ok';
      $pwd_detail = 'Using passkeys without a fallback password';
      $security_tips[] = 'Add a fallback password in case you ever lose access to your passkeys.';
    } else {
      $pwd_points = 5;
      $pwd_detail = 'No password on file';
      $security_tips[] = 'Set a strong password to protect your account.';
    }
  } elseif (strpos($password_hash, '$argon2') === 0) {
    $pwd_points = 30;
    $pwd_status = 'good';
    $pwd_detail = 'Argon2 hashing in use';
  } elseif (strpos($password_hash, '$2y$') === 0 || strpos($password_hash, '$2b$') === 0) {
    $cost_part = substr($password_hash, 4, 2);
    $cost = ctype_digit($cost_part) ? (int)$cost_part : 0;
    $pwd_points = ($cost >= 12) ? 28 : 26;
    $pwd_status = 'good';
    $pwd_detail = 'Bcrypt hashing (cost ' . ($cost > 0 ? $cost : 'default') . ')';
  } else {
    $pwd_points = 20;
    $pwd_detail = 'Legacy hashing algorithm detected';
    $security_tips[] = 'Update your password to upgrade the hashing algorithm.';
  }
  $security_segments[] = [
    'key' => 'password',
    'label' => 'Password',
    'points' => min(30, max(0, $pwd_points)),
    'max' => 30,
    'status' => $pwd_status,
    'status_label' => $status_labels[$pwd_status] ?? 'Status',
    'detail' => $pwd_detail,
    'color' => '#60a5fa',
  ];
  $security_score += min(30, max(0, $pwd_points));

  // Authenticator app 2FA (max 30)
  $app_points = $twofa_app_enabled ? 30 : 0;
  $app_status = $twofa_app_enabled ? 'good' : 'warn';
  $app_detail = $twofa_app_enabled ? 'Authenticator app required at login' : 'Not enabled';
  if (!$twofa_app_enabled) {
    $security_tips[] = $twofa_email_enabled
      ? 'Add authenticator app 2FA for stronger protection than email codes.'
      : 'Turn on authenticator app 2FA to protect your logins.';
  }
  $security_segments[] = [
    'key' => 'twofa_app',
    'label' => 'Authenticator 2FA',
    'points' => min(30, max(0, $app_points)),
    'max' => 30,
    'status' => $app_status,
    'status_label' => $status_labels[$app_status] ?? 'Status',
    'detail' => $app_detail,
    'color' => '#34d399',
  ];
  $security_score += min(30, max(0, $app_points));

  // Email codes 2FA (max 10)
  $email_points = $twofa_email_enabled ? 10 : 0;
  $email_status = $twofa_email_enabled ? 'ok' : 'warn';
  $email_detail = $twofa_email_enabled ? 'Email login codes available as backup' : 'Not enabled';
  if (!$twofa_email_enabled && !$twofa_app_enabled) {
    $security_tips[] = 'Enable email login codes so sign-ins require a second step.';
  }
  $security_segments[] = [
    'key' => 'twofa_email',
    'label' => 'Email 2FA',
    'points' => min(10, max(0, $email_points)),
    'max' => 10,
    'status' => $email_status,
    'status_label' => $status_labels[$email_status] ?? 'Status',
    'detail' => $email_detail,
    'color' => '#fbbf24',
  ];
  $security_score += min(10, max(0, $email_points));

  // Passkeys (max 20)
  if ($passkey_count >= 2) {
    $pk_points = 20;
    $pk_status = 'good';
    $pk_detail = number_format($passkey_count) . ' passkeys saved';
  } elseif ($passkey_count === 1) {
    $pk_points = 14;
    $pk_status = 'ok';
    $pk_detail = '1 passkey saved';
    $security_tips[] = 'Add a second passkey so you have a backup device.';
  } else {
    $pk_points = 0;
    $pk_status = 'warn';
    $pk_detail = 'No passkeys yet';
    $security_tips[] = 'Add a passkey for fast, phishing-resistant sign-ins.';
  }
  $security_segments[] = [
    'key' => 'passkeys',
    'label' => 'Passkeys',
    'points' => min(20, max(0, $pk_points)),
    'max' => 20,
    'status' => $pk_status,
    'status_label' => $status_labels[$pk_status] ?? 'Status',
    'detail' => $pk_detail,
    'color' => '#c084fc',
  ];
  $security_score += min(20, max(0, $pk_points));

  // Trusted device hygiene (max 10)
  $trusted_points = 10;
  $trusted_detail_parts = [];
  if ($trusted_counts['total'] <= 0) {
    $trusted_detail = 'No trusted devices';
    $trusted_status = 'good';
  } else {
    $trusted_points = 6;
    $trusted_detail_parts[] = number_format($trusted_counts['total']) . ' saved';
    if ($trusted_counts['recent'] > 0) {
      $trusted_points += 2;
      $trusted_detail_parts[] = number_format($trusted_counts['recent']) . ' active';
    }
    if ($trusted_counts['expired'] > 0) {
      $trusted_points -= 4;
      $trusted_detail_parts[] = number_format($trusted_counts['expired']) . ' expired';
      $security_tips[] = 'Remove expired trusted devices to prevent bypassing 2FA.';
    }
    if ($trusted_counts['total'] > 5) {
      $trusted_points -= 1;
      $security_tips[] = 'Review trusted devices and prune any you no longer use.';
    }
    $trusted_points = max(0, min(10, $trusted_points));
    $trusted_status = ($trusted_points >= 8) ? 'good' : (($trusted_points >= 5) ? 'ok' : 'warn');
    $trusted_detail = implode(' · ', $trusted_detail_parts);
  }
  $security_segments[] = [
    'key' => 'trusted',
    'label' => 'Trusted devices',
    'points' => $trusted_points,
    'max' => 10,
    'status' => $trusted_status,
    'status_label' => $status_labels[$trusted_status] ?? 'Status',
    'detail' => $trusted_detail,
    'color' => '#f472b6',
  ];
  $security_score += $trusted_points;

  $security_score = max(0, min($SEC_MAX, $security_score));
  $security_score_pct = (int)round($security_score);

  if ($security_score_pct >= 90) {
    $security_grade = 'Excellent';
  } elseif ($security_score_pct >= 75) {
    $security_grade = 'Strong';
  } elseif ($security_score_pct >= 55) {
    $security_grade = 'Fair';
  } else {
    $security_grade = 'Needs attention';
  }

  if ($twofa_app_enabled && $passkey_count > 0) {
    $security_summary = 'Passkeys and authenticator 2FA are protecting this account.';
  } elseif ($twofa_app_enabled) {
    $security_summary = 'Authenticator 2FA is active. Add a passkey for passwordless access.';
  } elseif ($twofa_email_enabled) {
    $security_summary = 'Email codes provide some coverage. Enable an authenticator app next.';
  } else {
    $security_summary = 'Set up multi-factor authentication to dramatically boost security.';
  }

  $security_tips = array_values(array_unique(array_filter($security_tips)));

  $security_donut_segments = $security_segments;
  $gap_points = max(0, $SEC_MAX - $security_score);
  if ($gap_points > 0) {
    $security_donut_segments[] = [
      'key' => 'opportunity',
      'label' => 'Opportunity',
      'points' => $gap_points,
      'max' => $SEC_MAX,
      'status' => 'gap',
      'status_label' => '',
      'detail' => '',
      'color' => 'rgba(148,163,184,0.35)',
      'is_gap' => true,
    ];
  }

  $security_donut_svg = [];
  $accum = 0.0;
  foreach ($security_donut_segments as $idx => $seg) {
    $pct = $SEC_MAX > 0 ? max(0, min(1, ($seg['points'] ?? 0) / $SEC_MAX)) : 0;
    $len = $pct * $donutS['C'];
    if ($len <= 0.0001) continue;
    $security_donut_svg[] = [
      'key' => $seg['key'],
      'color' => $seg['color'],
      'dash' => $len,
      'gap' => max(0.0001, $donutS['C'] - $len),
      'offset' => ($idx === 0) ? null : ($donutS['C'] - $accum),
      'is_gap' => !empty($seg['is_gap']),
    ];
    $accum += $len;
  }
} else {
  $security_score_pct = 0;
}

/* ---------- Donut math (clients) ---------- */
$has_is_active    = column_exists($conn, 'users', 'is_active');
$has_locked_until = column_exists($conn, 'users', 'locked_until');

$active_clients = null;
$inactive_clients = null;
$locked_clients = null;

if ($total_clients !== null) {
  if ($has_is_active) {
    $active_clients   = safe_count_sql($conn, "SELECT COUNT(*) FROM users WHERE (role='client' OR is_client=1) AND is_active=1");
    $inactive_clients = safe_count_sql($conn, "SELECT COUNT(*) FROM users WHERE (role='client' OR is_client=1) AND is_active=0");
  } else {
    $active_clients = $total_clients;
    $inactive_clients = 0;
  }
  if ($has_locked_until) {
    $locked_clients = safe_count_sql($conn, "SELECT COUNT(*) FROM users WHERE (role='client' OR is_client=1) AND locked_until IS NOT NULL AND locked_until > NOW()");
  } else {
    $locked_clients = 0;
  }
}
$TC  = max(0, (int)($total_clients ?? 0));
$ACT = max(0, (int)($active_clients   ?? 0));
$INA = max(0, (int)($inactive_clients ?? 0));
$LCK = max(0, (int)($locked_clients   ?? 0));
$sumSeg = $ACT + $INA + $LCK;
if ($TC > 0 && $sumSeg > $TC) {
  $scale = $TC / $sumSeg;
  $ACT = (int)floor($ACT * $scale);
  $INA = (int)floor($INA * $scale);
  $LCK = max(0, $TC - $ACT - $INA);
  $sumSeg = $ACT + $INA + $LCK;
}
if ($TC > 0 && $sumSeg === 0) { $ACT = $TC; $sumSeg = $TC; }

$donutC = ['r'=>42, 'sw'=>12];
$donutC['C'] = 2 * M_PI * $donutC['r'];
$segACT = $sumSeg > 0 ? ($ACT / $sumSeg) * $donutC['C'] : 0;
$segINA = $sumSeg > 0 ? ($INA / $sumSeg) * $donutC['C'] : 0;
$segLCK = $sumSeg > 0 ? ($LCK / $sumSeg) * $donutC['C'] : 0;
$dashACT = $segACT; $gapACT = max(0.0001, $donutC['C'] - $dashACT);
$dashINA = $segINA; $gapINA = max(0.0001, $donutC['C'] - $dashINA);
$dashLCK = $segLCK; $gapLCK = max(0.0001, $donutC['C'] - $dashLCK);
$offsetINA = $donutC['C'] - $segACT;
$offsetLCK = $donutC['C'] - ($segACT + $segINA);

/* ---------- Invites donut (now 4 segments) ---------- */
$INV_A  = max(0, $accepted_invites);
$INV_P  = max(0, $pending_invites);
$INV_E  = max(0, $expired_invites);
$INV_R  = max(0, $registered_invites);
$INV_T  = $INV_A + $INV_P + $INV_E + $INV_R;

$donutI = ['r'=>42, 'sw'=>12];
$donutI['C'] = 2 * M_PI * $donutI['r'];

$segIA = $INV_T > 0 ? ($INV_A / $INV_T) * $donutI['C'] : 0;
$segIP = $INV_T > 0 ? ($INV_P / $INV_T) * $donutI['C'] : 0;
$segIE = $INV_T > 0 ? ($INV_E / $INV_T) * $donutI['C'] : 0;
$segIR = $INV_T > 0 ? ($INV_R / $INV_T) * $donutI['C'] : 0;

$dashIA = $segIA; $gapIA = max(0.0001, $donutI['C'] - $dashIA);
$dashIP = $segIP; $gapIP = max(0.0001, $donutI['C'] - $dashIP);
$dashIE = $segIE; $gapIE = max(0.0001, $donutI['C'] - $dashIE);
$dashIR = $segIR; $gapIR = max(0.0001, $donutI['C'] - $dashIR);

$offsetIP = $donutI['C'] - $segIA;
$offsetIE = $donutI['C'] - ($segIA + $segIP);
$offsetIR = $donutI['C'] - ($segIA + $segIP + $segIE);

/* ---------- Histograms (last N days) ---------- */
$HIST_DAYS = 56;
$HAS_WP_CREATED_AT = column_exists($conn, 'workout_plans', 'created_at');
$HAS_EX_CREATED_AT = column_exists($conn, 'exercises',     'created_at');

function histogram_last_n_days(mysqli $conn, string $table, string $dateCol, int $days): array {
  $dates = [];
  $counts = array_fill(0, $days, 0);
  $map = [];
  $today = new DateTime('today');
  for ($i = $days-1; $i >= 0; $i--) {
    $dt = clone $today;
    $dt->modify("-$i day");
    $key = $dt->format('Y-m-d');
    $dates[] = $key;
    $map[$key] = $days - 1 - $i;
  }
  $start = $dates[0] . ' 00:00:00';
  $sql = "SELECT DATE($dateCol) d, COUNT(*) c
            FROM $table
           WHERE $dateCol IS NOT NULL
             AND $dateCol >= ?
           GROUP BY DATE($dateCol)";
  if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $start);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $d = $row['d'] ?? null; $c = (int)($row['c'] ?? 0);
        if ($d && isset($map[$d])) {
          $idx = $map[$d];
          $counts[$idx] = $c;
        }
      }
    }
    $stmt->close();
  }
  return [$dates, $counts];
}

$wp_dates = []; $wp_counts = [];
$ex_dates = []; $ex_counts = [];
if ($HAS_WP_CREATED_AT) { [$wp_dates, $wp_counts] = histogram_last_n_days($conn, 'workout_plans', 'created_at', $HIST_DAYS); }
if ($HAS_EX_CREATED_AT) { [$ex_dates, $ex_counts] = histogram_last_n_days($conn, 'exercises',     'created_at', $HIST_DAYS); }

function compress_every_n(array $counts, int $step): array {
  if ($step <= 1) return $counts;
  $out = [];
  for ($i=0; $i<count($counts); $i += $step) {
    $sum = 0;
    for ($j=0; $j<$step && ($i+$j)<count($counts); $j++) $sum += (int)$counts[$i+$j];
    $out[] = $sum;
  }
  return $out;
}
$wp_bars = $wp_counts ? compress_every_n($wp_counts, 2) : [];
$ex_bars = $ex_counts ? compress_every_n($ex_counts, 2) : [];
$wp_max  = $wp_bars ? max($wp_bars) : 0;
$ex_max  = $ex_bars ? max($ex_bars) : 0;

/* ---------- Category donut palette ---------- */
$CAT_PALETTE = [
  '#4f8cf9','#8e7df0','#3fcf8e','#f5b800','#ef6c6c',
  '#29c1d2','#b794f4','#ffa94d','#5dd39e','#ff7aa2',
  '#8ecae6','#219ebc','#ffb703','#fb8500','#90be6d',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Dashboard</title>
  <style>
    :root{
      --c-bg:#0b0e14; --c-card:#101521; --c-text:#e6eef8;
      --c-muted:#9bb2c8;

      --c-accent:#3c82f6; --c-accent-2:#6c5bd6;
      --violet-2:#b794f4;

      --green-1:#22c55e; --green-2:#16a34a;
      --slate-1:#94a3b8; --slate-2:#64748b;
      --warn-1:#f59e0b; --warn-2:#ef4444;

      /* Invites palette (4 segments) */
      --inv-accepted-1:#60a5fa; --inv-accepted-2:#3b82f6;
      --inv-pending-1:#a78bfa;  --inv-pending-2:#7c3aed;
      --inv-expired-1:#f59e0b;  --inv-expired-2:#ef4444;
      --inv-registered-1:#10b981; --inv-registered-2:#059669;

      --sec-pass:#60a5fa;
      --sec-twofa-app:#34d399;
      --sec-twofa-email:#fbbf24;
      --sec-passkey:#c084fc;
      --sec-trusted:#f472b6;
      --sec-gap:rgba(148,163,184,0.35);

      --spark-fill:#1a2440;
      --spark-bar:#4f8cf9;
      --spark-bar-2:#8e7df0;

      --border:#1b2332; --radius:14px;
    }

    @media (max-width: 520px){
      .card{ padding:12px; }
      .donut-wrap{ gap:8px; }
      .legend .row{ justify-content: space-between; gap:10px; }
      .legend{ gap:6px; }
      .btn{ padding:7px 10px; font-size:13px; }
    }

    body{background:var(--c-bg); color:var(--c-text); margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Helvetica,Arial,sans-serif;}
    .wrap{ max-width: 100%; margin: 0 auto; padding: 14px; box-sizing: border-box; }
    h1{margin:0 0 6px;font-size:20px}
    .muted{color:var(--c-muted);font-size:13px}

    .cards{
      --card-gap:14px;
      --card-min: min(100%, 280px);
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(var(--card-min), 1fr));
      gap: var(--card-gap);
      align-items: stretch;
      position:relative;
    }
    .cards.cols-2{ --card-min: min(100%, calc((100% - var(--card-gap)) / 2)); }
    .cards.cols-3{ --card-min: min(100%, calc((100% - (var(--card-gap) * 2)) / 3)); }
    .cards.cols-4{ --card-min: min(100%, calc((100% - (var(--card-gap) * 3)) / 4)); }
    @media (max-width: 900px){
      .cards{ --card-min: min(100%, 320px); }
    }
    @media (max-width: 600px){
      .cards{ --card-min: min(100%, 360px); }
    }

    .card{
      --card-span: 1;
      display:flex; flex-direction:column; justify-content:flex-start;
      background:var(--c-card); border:1px solid var(--border); border-radius:var(--radius);
      padding:14px; min-height:110px; container-type:inline-size; position:relative;
      grid-column: span var(--card-span);
    }
    .card.is-draggable{ cursor: grab; }
    .card.is-dragging{ opacity:0.65; cursor: grabbing; box-shadow:0 12px 32px rgba(0,0,0,0.35); }
    .card.is-hidden{ display:none !important; }
    .card.is-resizing{ cursor: ew-resize; }
    .card.is-floating{ pointer-events:none; }
    .cards.drag-active{ position:relative; }
    .cards.drag-active::after{
      content:""; position:absolute; inset:-6px; border-radius:calc(var(--radius) + 6px);
      border:1px dashed rgba(99, 102, 241, 0.45);
      pointer-events:none;
    }
    .card[data-default-span="2"],
    .card[data-card-span="2"]{ --card-span: 2; }
    .card[data-card-span="3"]{ --card-span: 3; }
    .card[data-card-span="4"]{ --card-span: 4; }

    .card-placeholder{
      border:1px dashed rgba(99,102,241,0.55);
      border-radius:var(--radius);
      background:rgba(60,130,246,0.08);
      min-height:60px;
      grid-column: span var(--card-span);
      transition:border-color .2s ease, background .2s ease;
    }

    .card-resize-ghost{
      position:absolute;
      top:6px;
      right:6px;
      bottom:6px;
      left:6px;
      border-radius:calc(var(--radius) - 6px);
      background:linear-gradient(90deg, rgba(60,130,246,0.28), rgba(108,91,214,0.2));
      transform-origin:left center;
      transform:scaleX(var(--card-resize-preview, 0));
      opacity:0;
      pointer-events:none;
      transition:transform .08s ease, opacity .12s ease;
    }

    .card.show-resize-preview .card-resize-ghost{
      opacity:1;
    }

    .card-resize{
      position:absolute;
      right:10px;
      bottom:10px;
      width:24px;
      height:24px;
      border-radius:50%;
      border:1px solid var(--border);
      background:rgba(17,26,44,0.9);
      color:var(--c-muted);
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:ew-resize;
      padding:0;
      margin:0;
      touch-action:none;
      transition:border-color .2s ease, background .2s ease, color .2s ease, opacity .2s ease;
      z-index:5;
    }
    .card-resize:focus-visible{ outline:2px solid var(--c-accent); outline-offset:2px; }
    .card-resize svg{ width:12px; height:12px; pointer-events:none; }
    .card:not(:hover) .card-resize{ opacity:0.6; }
    .card-resize:is(:hover,:active){ border-color:#2a3650; background:#182338; color:#e5edff; }

    .card h3{margin:0 0 6px; font-size:16px}
    .metric{font-size:28px; font-weight:700; line-height:1.2; margin:2px 0 4px}
    .actions{display:flex; gap:8px; flex-wrap:wrap; margin-top:auto}
    .btn{ display:inline-block; padding:8px 12px; border-radius:10px; border:1px solid var(--border); background:#121a2b; color:var(--c-text); text-decoration:none; font-size:14px; }
    .btn.primary{ background: linear-gradient(90deg, var(--c-accent), var(--c-accent-2)); border-color: transparent; color: #fff; }

    .dashboard-head{
      margin:4px 0 12px;
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
    }
    .dashboard-head__title{ display:flex; flex-direction:column; gap:4px; min-width:0; }
    .dashboard-head__title h1{ margin:0; }

    .dash-settings-toggle{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 14px;
      border-radius:999px;
      border:1px solid var(--border);
      background:#111a2c;
      color:var(--c-text);
      font-size:13px;
      font-weight:600;
      letter-spacing:0.02em;
      text-transform:uppercase;
      cursor:pointer;
      transition:background .2s ease, border-color .2s ease, color .2s ease;
    }
    .dash-settings-toggle svg{ width:16px; height:16px; }
    .dash-settings-toggle:is(:hover,:focus-visible){ background:#182338; border-color:#2a3650; }
    .dash-settings-toggle:focus-visible{ outline:2px solid var(--c-accent); outline-offset:2px; }
    .dash-settings-toggle.is-active{ background:linear-gradient(90deg, var(--c-accent), var(--c-accent-2)); border-color:transparent; color:#fff; }

    .dash-settings-overlay{
      position:fixed;
      inset:0;
      background:rgba(5, 10, 20, 0.55);
      backdrop-filter:blur(2px);
      opacity:0;
      pointer-events:none;
      transition:opacity .3s ease;
      z-index:940;
    }
    .dash-settings-overlay.is-visible{ opacity:1; pointer-events:auto; }

    .dash-settings{
      position:fixed;
      top:0;
      right:0;
      width:320px;
      max-width:90vw;
      height:100vh;
      background:rgba(13,18,31,0.98);
      border-left:1px solid var(--border);
      box-shadow:-16px 0 48px rgba(0,0,0,0.45);
      transform:translateX(100%);
      transition:transform .3s ease;
      z-index:950;
      display:flex;
      flex-direction:column;
    }
    .dash-settings.is-open{ transform:translateX(0); }
    .dash-settings__inner{ padding:20px 20px 24px; display:flex; flex-direction:column; gap:20px; overflow-y:auto; flex:1; }
    .dash-settings__header{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .dash-settings__header h2{ margin:0; font-size:16px; }
    .dash-settings__close{
      border:1px solid var(--border);
      background:#111a2c;
      color:var(--c-text);
      border-radius:999px;
      padding:6px 12px;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:0.05em;
      cursor:pointer;
      transition:background .2s ease, border-color .2s ease;
    }
    .dash-settings__close:is(:hover,:focus-visible){ background:#182338; border-color:#2a3650; }
    .dash-settings__section{ display:flex; flex-direction:column; gap:12px; }
    .dash-settings__section h3{ margin:0; font-size:14px; text-transform:uppercase; letter-spacing:0.06em; color:#f3f6ff; }
    .dash-settings__columns{ display:flex; gap:10px; flex-wrap:wrap; }
    .dash-settings__columns label{
      position:relative;
      flex:1 1 80px;
      cursor:pointer;
    }
    .dash-settings__columns label input{
      position:absolute;
      inset:0;
      opacity:0;
      pointer-events:none;
    }
    .dash-settings__columns label span{
      display:flex;
      align-items:center;
      justify-content:center;
      padding:8px 10px;
      border:1px solid var(--border);
      border-radius:12px;
      background:#10192a;
      font-size:13px;
      font-weight:600;
      transition:border-color .2s ease, background .2s ease, color .2s ease;
      pointer-events:none;
    }
    .dash-settings__columns label:hover span{ border-color:#2a3650; background:#182338; }
    .dash-settings__columns label input:checked + span{
      background:linear-gradient(90deg, var(--c-accent), var(--c-accent-2));
      border-color:transparent;
      color:#fff;
    }
    .dash-settings__columns label input:focus-visible + span{ outline:2px solid var(--c-accent); outline-offset:2px; }

    .dash-settings__groups{ display:flex; flex-direction:column; gap:14px; }
    .dash-settings__group{ display:flex; flex-direction:column; gap:6px; }
    .dash-settings__group h4{ margin:12px 0 4px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:var(--c-muted); }
    .dash-settings__option{
      display:flex;
      align-items:center;
      gap:10px;
      padding:8px 10px;
      border:1px solid var(--border);
      border-radius:10px;
      background:#10192a;
      font-size:13px;
      cursor:pointer;
      transition:border-color .2s ease, background .2s ease, color .2s ease;
    }
    .dash-settings__option input{
      width:16px;
      height:16px;
      accent-color: var(--c-accent);
    }
    .dash-settings__option span{ flex:1; }
    .dash-settings__option .dash-settings__tag{
      font-size:11px;
      color:var(--c-muted);
      text-transform:uppercase;
      letter-spacing:0.08em;
      flex:0;
      white-space:nowrap;
    }
    .dash-settings__option:is(:hover,:focus-within){ border-color:#2a3650; background:#182338; }
    .dash-settings__option.is-locked{ opacity:0.6; cursor:not-allowed; }
    .dash-settings__option.is-locked input{ pointer-events:none; }
    .dash-settings__empty{ font-size:13px; color:var(--c-muted); }

    @media (max-width: 768px){
      .dashboard-head{ flex-direction:column; align-items:stretch; }
      .dash-settings-toggle{ width:100%; justify-content:center; }
      .dash-settings{ width:100%; max-width:100%; }
    }

    .section{ margin-top: 18px; }
    .section h2{ margin: 0 0 8px; font-size: 15px; color: var(--c-muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

    /* Donut layout */
    .donut-wrap{
      display:grid; grid-template-columns: 1fr 1.2fr; gap:12px; align-items:center;
    }
    @media (max-width: 520px){ .donut-wrap{ grid-template-columns: 1fr; } }

    /* Center pack for donut card */
    .center-pack{
      display:grid;
      align-content:center;
      row-gap:12px;
      min-height: 210px;
      margin-top: -4px;
    }

    .donut{
      position:relative;
      width: min(58cqw, 240px);
      aspect-ratio: 1 / 1;
      display:grid; place-items:center; margin-inline:auto;
    }
    .donut svg{ width:100%; height:100%; transform:rotate(-90deg); }
    .donut .center{ position:absolute; inset:0; display:grid; place-items:center; pointer-events:none; font-size:12px; color:var(--c-muted); text-align:center; }
    .legend{ display:flex; flex-direction:column; gap:8px; }
    .legend .row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .swatch{ width:12px; height:12px; border-radius:3px; }
    .legend .label{ font-size:12px; color: var(--c-muted); }
    .legend .value{ font-weight:600; }

    .security-card .donut-wrap{ gap:16px; }
    .security-card .donut .score{ font-size:32px; font-weight:700; color:#fff; }
    .security-card .donut .grade{ margin-top:4px; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--c-muted); }
    .security-card .legend{ gap:10px; }
    .security-card .legend .row{ align-items:flex-start; justify-content:space-between; }
    .security-card .legend-main{ flex:1; min-width:0; }
    .security-card .legend-title{ font-size:12px; font-weight:600; color:#f8fbff; }
    .security-card .legend-detail{ font-size:11px; color:var(--c-muted); margin-top:2px; }
    .security-card .legend-score{ display:flex; flex-direction:column; align-items:flex-end; gap:4px; font-size:11px; }
    .security-card .legend-score .value{ font-weight:600; color:#e2ecff; }
    .security-card .status-pill{ display:inline-flex; align-items:center; justify-content:center; padding:2px 6px; border-radius:999px; font-size:10px; font-weight:600; letter-spacing:.05em; text-transform:uppercase; }
    .security-card .status-pill.good{ background:rgba(34,197,94,0.18); border:1px solid rgba(34,197,94,0.4); color:#4ade80; }
    .security-card .status-pill.ok{ background:rgba(250,204,21,0.18); border:1px solid rgba(234,179,8,0.45); color:#facc15; }
    .security-card .status-pill.warn{ background:rgba(239,68,68,0.18); border:1px solid rgba(239,68,68,0.4); color:#f87171; }
    .security-card .security-summary{ margin:12px 0 0; font-size:12px; color:var(--c-muted); }
    .security-card .security-tips{ margin:10px 0 0; padding:0; list-style:none; display:flex; flex-direction:column; gap:6px; }
    .security-card .security-tips li{ position:relative; padding-left:16px; font-size:12px; color:#dbe7ff; line-height:1.4; }
    .security-card .security-tips li::before{ content:""; position:absolute; left:0; top:6px; width:8px; height:8px; border-radius:50%; background:linear-gradient(90deg, var(--c-accent), var(--violet-2)); box-shadow:0 0 0 2px rgba(60,130,246,0.25); }

    /* Spark bars */
    .spark{
      width:100%; height:60px; border:1px solid var(--border); border-radius:10px;
      background: var(--spark-fill); padding:6px; box-sizing:border-box; display:flex; align-items:flex-end; gap:3px;
    }
    .spark .bar{ flex:1 0 auto; min-width:2px; background: linear-gradient(180deg, var(--spark-bar), var(--spark-bar-2)); border-radius:3px 3px 0 0; height:6px; }
    .spark .bar.zero{ background: rgba(255,255,255,0.08); }
    .spark-label{ display:flex; align-items:center; gap:8px; margin-top:8px; }
    .spark-dot{ width:8px; height:8px; border-radius:50%; background: var(--spark-bar); }

    /* My Plans */
    .pill-row{ display:flex; gap:8px; flex-wrap:wrap; margin:2px 0 8px; }
    .pill{ padding:6px 10px; border-radius:999px; border:1px solid var(--border); background:#121a2b; font-size:12px; color:#dbe7ff; }
    .pill .num{ font-weight:700; margin-right:6px; }

    .plan-list{ display:flex; flex-direction:column; gap:10px; margin-top:6px; }
    .plan-item{
      border:1px solid var(--border); border-radius:12px; padding:10px; background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.00));
      transition: transform .12s ease, border-color .12s ease, background-color .12s ease;
      text-decoration:none; color:inherit; display:block;
    }
    .plan-item:hover{ transform: translateY(-1px); border-color:#2a3650; background:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.00)); }
    .pi-top{ display:flex; align-items:center; gap:8px; }
    .pi-name{ font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .badge{ margin-left:auto; font-size:12px; padding:3px 8px; border-radius:999px; border:1px solid rgba(255,255,255,0.08); background:linear-gradient(90deg, var(--c-accent), var(--violet-2)); color:#fff; font-weight:600; }
    .chip{ font-size:11px; color:#cdd9ee; border:1px solid var(--border); background:#0f1627; padding:2px 6px; border-radius:999px; }
    .bar{ position:relative; height:6px; border-radius:999px; background:rgba(255,255,255,0.08); overflow:hidden; margin-top:8px; }
    .bar > span{ position:absolute; inset:0 auto 0 0; width:0%; background:linear-gradient(90deg, var(--c-accent), var(--violet-2)); border-radius:999px; }

    /* System Resources — vertical bar graph */
    .vstats{
      margin-top:6px;
      display:grid;
      grid-template-columns: repeat(5, 1fr);
      gap:16px;
      align-items:end;
      min-height:200px;
      position:relative;
      padding:8px 6px 2px;
      border:1px solid var(--border);
      border-radius:12px;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.00));
      overflow:hidden;
    }
    .vstats::before{
      content:"";
      position:absolute; inset:0 0 0 0;
      background:
        repeating-linear-gradient(
          to top,
          rgba(255,255,255,0.06) 0px,
          rgba(255,255,255,0.06) 1px,
          transparent 1px,
          transparent 36px
        );
      pointer-events:none;
    }
    .vbar{ display:flex; flex-direction:column; align-items:center; gap:8px; min-width:0; }
    .vbar .cap{ font-weight:700; font-size:12px; color:#eaf1ff; background:rgba(60,130,246,0.15); border:1px solid rgba(60,130,246,0.25); padding:2px 6px; border-radius:8px; backdrop-filter: blur(2px); box-shadow: 0 0 0 1px rgba(0,0,0,0.15) inset; white-space:nowrap; }
    .vbar .col{ width: 42px; height: 140px; border-radius: 10px; background: rgba(255,255,255,0.06); border:1px solid var(--border); position:relative; overflow:hidden; box-shadow: inset 0 1px 6px rgba(0,0,0,0.35); display:flex; align-items:flex-end; justify-content:center; }
    .vbar .fill{ width: 100%; height: 0%; background: linear-gradient(180deg, var(--c-accent), var(--c-accent-2)); box-shadow: 0 10px 18px rgba(60,130,246,0.28), inset 0 0 10px rgba(255,255,255,0.12); border-radius:10px; transition: height .4s ease; }
    .vbar .fill.net{ background: linear-gradient(180deg, #22c55e, #16a34a); box-shadow: 0 10px 18px rgba(34,197,94,0.28), inset 0 0 10px rgba(255,255,255,0.12); }
    .vbar .lbl{ margin-top:6px; font-size:12px; color:var(--c-muted); text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  </style>
</head>
<body>
  <!-- header from ppf_header.php include -->

  <main class="wrap">
    <header class="dashboard-head">
      <div class="dashboard-head__title">
        <h1>Dashboard</h1>
        <div class="muted">Welcome back<?php echo isset($USER_NAME) ? ', '.h($USER_NAME) : ''; ?>.</div>
      </div>
      <button type="button" class="dash-settings-toggle" aria-expanded="false" aria-controls="dashboard-settings">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 8a4 4 0 100 8 4 4 0 000-8zm9 4a1 1 0 01-.64.93l-1.55.62c-.09.25-.19.49-.3.72l.74 1.53a1 1 0 01-.22 1.17l-1.42 1.42a1 1 0 01-1.17.22l-1.53-.74a7.2 7.2 0 01-.72.3l-.62 1.55a1 1 0 01-.93.64h-2a1 1 0 01-.93-.64l-.62-1.55a7.2 7.2 0 01-.72-.3l-1.53.74a1 1 0 01-1.17-.22L3.3 16.97a1 1 0 01-.22-1.17l.74-1.53a7.2 7.2 0 01-.3-.72l-1.55-.62A1 1 0 012 12v-2a1 1 0 01.64-.93l1.55-.62c.09-.25.19-.49.3-.72l-.74-1.53A1 1 0 013.3 5.03l1.42-1.42a1 1 0 011.17-.22l1.53.74c.23-.11.47-.21.72-.3l.62-1.55A1 1 0 0110.7 2h2a1 1 0 01.93.64l.62 1.55c.25.09.49.19.72.3l1.53-.74a1 1 0 011.17.22l1.42 1.42a1 1 0 01.22 1.17l-.74 1.53c.11.23.21.47.3.72l1.55.62A1 1 0 0121 10v2z" />
        </svg>
        <span>Dashboard Options</span>
      </button>
    </header>

    <section class="cards cols-4" aria-label="Overview cards">
      <article class="card security-card" data-card-key="security">
        <h3>Security</h3>

        <div class="donut-wrap" aria-label="Account security status">
          <div class="donut">
            <?php $rS = $donutS['r']; $swS = $donutS['sw']; ?>
            <svg viewBox="0 0 120 120" role="img" aria-labelledby="seclbl">
              <title id="seclbl">Account security score: <?php echo (int)$security_score_pct; ?> percent</title>
              <circle cx="60" cy="60" r="<?php echo $rS; ?>"
                      fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="<?php echo $swS; ?>" />
              <?php foreach ($security_donut_svg as $seg):
                $dash = round($seg['dash'], 2);
                $gap = round($seg['gap'], 2);
                $offset = $seg['offset'] !== null ? round($seg['offset'], 2) : null;
                $color = htmlspecialchars($seg['color'], ENT_QUOTES, 'UTF-8');
                $linecap = $seg['is_gap'] ? 'butt' : 'round';
              ?>
                <circle cx="60" cy="60" r="<?php echo $rS; ?>"
                        fill="none" stroke="<?php echo $color; ?>" stroke-width="<?php echo $swS; ?>"
                        stroke-dasharray="<?php echo $dash . ' ' . $gap; ?>"<?php if ($offset !== null): ?> stroke-dashoffset="<?php echo $offset; ?>"<?php endif; ?>
                        stroke-linecap="<?php echo $linecap; ?>" />
              <?php endforeach; ?>
            </svg>
            <div class="center">
              <div>
                <div class="score"><?php echo (int)$security_score_pct; ?>%</div>
                <div class="grade"><?php echo h($security_grade); ?></div>
              </div>
            </div>
          </div>

          <div class="legend">
            <?php foreach ($security_segments as $seg): ?>
              <div class="row">
                <span class="swatch" style="background:<?php echo htmlspecialchars($seg['color'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                <div class="legend-main">
                  <div class="legend-title"><?php echo h($seg['label']); ?></div>
                  <?php if (!empty($seg['detail'])): ?>
                    <div class="legend-detail"><?php echo h($seg['detail']); ?></div>
                  <?php endif; ?>
                </div>
                <div class="legend-score">
                  <span class="value"><?php echo (int)round($seg['points']); ?> / <?php echo (int)$seg['max']; ?></span>
                  <span class="status-pill <?php echo h($seg['status']); ?>"><?php echo h($seg['status_label']); ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (!empty($security_summary)): ?>
          <p class="security-summary"><?php echo h($security_summary); ?></p>
        <?php endif; ?>

        <?php if (!empty($security_tips)): ?>
          <ul class="security-tips">
            <?php foreach ($security_tips as $tip): ?>
              <li><?php echo h($tip); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="security-summary" style="margin-top:10px;">Everything looks locked down. Great job!</p>
        <?php endif; ?>
      </article>

      <?php if ($can_admin): ?>
        <!-- Admin/Trainer-only cards -->

        <!-- TOTAL CLIENTS — donut -->
        <article class="card" data-card-key="total-clients">
          <h3>Total Clients</h3>

          <div class="donut-wrap" aria-label="Total Clients donut">
            <div class="donut">
              <?php $rC = $donutC['r']; $swC = $donutC['sw']; ?>
              <svg viewBox="0 0 120 120" role="img" aria-labelledby="tclbl">
                <title id="tclbl">Donut chart: Active, Inactive, and Locked Clients</title>

                <!-- Track -->
                <circle cx="60" cy="60" r="<?php echo $rC; ?>"
                        fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="<?php echo $swC; ?>" />

                <!-- Gradients -->
                <defs>
                  <linearGradient id="gradACT" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--green-1)"/><stop offset="100%" stop-color="var(--green-2)"/>
                  </linearGradient>
                  <linearGradient id="gradINA" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--slate-2)"/><stop offset="100%" stop-color="var(--slate-1)"/>
                  </linearGradient>
                  <linearGradient id="gradLCK" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--warn-1)"/><stop offset="100%" stop-color="var(--warn-2)"/>
                  </linearGradient>
                </defs>

                <!-- Segments -->
                <circle cx="60" cy="60" r="<?php echo $rC; ?>" fill="none" stroke="url(#gradACT)" stroke-width="<?php echo $swC; ?>" stroke-dasharray="<?php echo $dashACT . ' ' . $gapACT; ?>" />
                <circle cx="60" cy="60" r="<?php echo $rC; ?>" fill="none" stroke="url(#gradINA)" stroke-width="<?php echo $swC; ?>" stroke-dasharray="<?php echo $dashINA . ' ' . $gapINA; ?>" stroke-dashoffset="<?php echo $offsetINA; ?>" />
                <circle cx="60" cy="60" r="<?php echo $rC; ?>" fill="none" stroke="url(#gradLCK)" stroke-width="<?php echo $swC; ?>" stroke-dasharray="<?php echo $dashLCK . ' ' . $gapLCK; ?>" stroke-dashoffset="<?php echo $offsetLCK; ?>" />
              </svg>

              <div class="center">
                <div>
                  <div style="font-weight:700; font-size:16px; color:#fff;">
                    <?php echo $total_clients !== null ? number_format($TC) : '—'; ?>
                  </div>
                  <div class="muted">Total</div>
                </div>
              </div>
            </div>

            <div class="legend">
              <div class="row"><span class="swatch" style="background:linear-gradient(90deg,var(--green-1),var(--green-2));"></span><span class="label">Active</span><span class="value"><?php echo $active_clients !== null ? number_format($active_clients) : '—'; ?></span></div>
              <div class="row"><span class="swatch" style="background:linear-gradient(90deg,var(--slate-2),var(--slate-1));"></span><span class="label">Inactive</span><span class="value"><?php echo $inactive_clients !== null ? number_format($inactive_clients) : '—'; ?></span></div>
              <div class="row"><span class="swatch" style="background:linear-gradient(90deg,var(--warn-1),var(--warn-2));"></span><span class="label">Locked</span><span class="value"><?php echo $locked_clients !== null ? number_format($locked_clients) : '—'; ?></span></div>
              <p class="muted" style="margin:6px 0 0;"><?php echo ($total_clients === null) ? 'Totals unavailable.' : 'Breakdown of your client base.'; ?></p>
            </div>
          </div>

          <div class="actions" style="margin-top:12px;">
            <a class="btn primary" href="clients.php?tab=active">Active Clients</a>
            <a class="btn" href="clients.php?tab=inactive">Inactive Clients</a>
          </div>
        </article>

        <!-- INVITES — donut (Accepted, Pending, Expired, Registered) -->
        <article class="card" data-card-key="invites">
          <h3>Invites</h3>
          <div class="donut-wrap" aria-label="Invites donut">
            <div class="donut">
              <?php $ri = $donutI['r']; $swi = $donutI['sw']; ?>
              <svg viewBox="0 0 120 120" role="img" aria-labelledby="invlbl">
                <title id="invlbl">Invites: Accepted, Pending, Expired, Registered</title>

                <circle cx="60" cy="60" r="<?php echo $ri; ?>" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="<?php echo $swi; ?>" />

                <defs>
                  <linearGradient id="gradIA" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--inv-accepted-1)"/><stop offset="100%" stop-color="var(--inv-accepted-2)"/>
                  </linearGradient>
                  <linearGradient id="gradIP" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--inv-pending-1)"/><stop offset="100%" stop-color="var(--inv-pending-2)"/>
                  </linearGradient>
                  <linearGradient id="gradIE" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--inv-expired-1)"/><stop offset="100%" stop-color="var(--inv-expired-2)"/>
                  </linearGradient>
                  <linearGradient id="gradIR" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"  stop-color="var(--inv-registered-1)"/><stop offset="100%" stop-color="var(--inv-registered-2)"/>
                  </linearGradient>
                </defs>

                <!-- Order: Accepted, Pending, Expired, Registered -->
                <circle cx="60" cy="60" r="<?php echo $ri; ?>" fill="none" stroke="url(#gradIA)" stroke-width="<?php echo $swi; ?>" stroke-dasharray="<?php echo $dashIA . ' ' . $gapIA; ?>" />
                <circle cx="60" cy="60" r="<?php echo $ri; ?>" fill="none" stroke="url(#gradIP)" stroke-width="<?php echo $swi; ?>" stroke-dasharray="<?php echo $dashIP . ' ' . $gapIP; ?>" stroke-dashoffset="<?php echo $offsetIP; ?>" />
                <circle cx="60" cy="60" r="<?php echo $ri; ?>" fill="none" stroke="url(#gradIE)" stroke-width="<?php echo $swi; ?>" stroke-dasharray="<?php echo $dashIE . ' ' . $gapIE; ?>" stroke-dashoffset="<?php echo $offsetIE; ?>" />
                <circle cx="60" cy="60" r="<?php echo $ri; ?>" fill="none" stroke="url(#gradIR)" stroke-width="<?php echo $swi; ?>" stroke-dasharray="<?php echo $dashIR . ' ' . $gapIR; ?>" stroke-dashoffset="<?php echo $offsetIR; ?>" />
              </svg>

              <div class="center">
                <div style="text-align:center">
                  <div style="font-weight:700; font-size:16px; color:#fff;"><?php echo number_format($INV_T); ?></div>
                  <div class="muted">Total</div>
                </div>
              </div>
            </div>

            <div class="legend">
              <div class="row">
                <span class="swatch" style="background:linear-gradient(90deg,var(--inv-accepted-1),var(--inv-accepted-2));"></span>
                <span class="label">Accepted</span>
                <span class="value"><?php echo number_format($accepted_invites); ?></span>
              </div>
              <div class="row">
                <span class="swatch" style="background:linear-gradient(90deg,var(--inv-pending-1),var(--inv-pending-2));"></span>
                <span class="label">Pending</span>
                <span class="value"><?php echo number_format($pending_invites); ?></span>
              </div>
              <div class="row">
                <span class="swatch" style="background:linear-gradient(90deg,var(--inv-expired-1),var(--inv-expired-2));"></span>
                <span class="label">Expired</span>
                <span class="value"><?php echo number_format($expired_invites); ?></span>
              </div>
              <div class="row">
                <span class="swatch" style="background:linear-gradient(90deg,var(--inv-registered-1),var(--inv-registered-2));"></span>
                <span class="label">Registered</span>
                <span class="value"><?php echo number_format($registered_invites); ?></span>
              </div>
              <p class="muted" style="margin:6px 0 0;">Invite lifecycle by status.</p>
            </div>
          </div>

          <div class="actions">
            <a class="btn primary" href="invites.php">Manage Invites</a>
            <a class="btn" href="create_invite_form.php">Send Invite</a>
          </div>
        </article>

        <!-- WORKOUT PLANS — sparkline -->
        <article class="card" data-card-key="overview-workout-plans">
          <h3>Workout Plans</h3>

          <?php if ($HAS_WP_CREATED_AT && $wp_bars): ?>
            <div class="spark" role="img" aria-label="Workout plans created over the last 8 weeks">
              <?php
                $bars = $wp_bars;
                $max  = max(1, (int)$wp_max);
                foreach ($bars as $val) {
                  $pct = max(6, (int)round(($val / $max) * 100));
                  $hpx = max(6, (int)round($pct * 0.6));
                  $cls = ($val === 0) ? 'bar zero' : 'bar';
                  echo '<div class="'.$cls.'" style="height:'.$hpx.'px" title="'.(int)$val.' plans"></div>';
                }
              ?>
            </div>
            <div class="spark-label">
              <span class="spark-dot"></span>
              <span class="muted">Last 8 weeks</span>
              <span style="margin-left:auto;font-weight:700"><?php echo number_format($workout_plans ?? 0); ?></span>
            </div>
          <?php else: ?>
            <div class="metric"><?php echo $workout_plans !== null ? number_format($workout_plans) : '—'; ?></div>
            <p class="muted" style="margin:0">Total plans</p>
          <?php endif; ?>

          <div class="actions" style="margin-top:12px;">
            <a class="btn primary" href="workout_plans.php">All Plans</a>
            <a class="btn" href="workout_plans.php?open=create">Create Plan</a>
          </div>
        </article>

        <!-- EXERCISES — sparkline (admin/trainer overview) -->
        <article class="card" data-card-key="overview-exercises">
          <h3>Exercises</h3>

          <?php if ($HAS_EX_CREATED_AT && $ex_bars): ?>
            <div class="spark" role="img" aria-label="Exercises created over the last 8 weeks">
              <?php
                $bars = $ex_bars;
                $max  = max(1, (int)$ex_max);
                foreach ($bars as $val) {
                  $pct = max(6, (int)round(($val / $max) * 100));
                  $hpx = max(6, (int)round($pct * 0.6));
                  $cls = ($val === 0) ? 'bar zero' : 'bar';
                  echo '<div class="'.$cls.'" style="height:'.$hpx.'px" title="'.(int)$val.' exercises"></div>';
                }
              ?>
            </div>
            <div class="spark-label">
              <span class="spark-dot"></span>
              <span class="muted">Last 8 weeks</span>
              <span style="margin-left:auto;font-weight:700"><?php echo number_format($exercises_total ?? 0); ?></span>
            </div>
          <?php else: ?>
            <div class="metric"><?php echo $exercises_total !== null ? number_format($exercises_total) : '—'; ?></div>
            <p class="muted" style="margin:0">Total exercises</p>
          <?php endif; ?>

          <div class="actions" style="margin-top:12px;">
            <a class="btn" href="exercises.php">Browse Exercises</a>
            <a class="btn" href="exercises.php?open=create">New Exercise</a>
          </div>
        </article>

        <?php if ($is_admin): ?>
  			<?php $SYS = read_sys_stats_snapshot(); ?>
        <!-- System Resources — vertical bar graph + LIVE Network -->
        <article class="card" data-card-key="system-resources" data-default-span="2">
          <h3>System Resources</h3>
          <p class="muted" style="margin:0 0 6px;">Server: <?php echo h((string)$SYS['os']); ?></p>

          <?php
            $cpu = (int)($SYS['cpu_pct'] ?? 0);
            $ram = (int)($SYS['ram_used_pct'] ?? 0);
            $dsk = (int)($SYS['disk_used_pct'] ?? 0);
            $cpuLbl = ($SYS['cpu_pct'] !== null ? $cpu.'%' : '—');
            $ramLbl = ($SYS['ram_used_pct'] !== null ? $ram.'%' : '—');
            $dskLbl = ($SYS['disk_used_pct'] !== null ? $dsk.'%' : '—');
          ?>
          <div class="vstats" role="img" aria-label="CPU, RAM, Storage, Download, Upload">
            <div class="vbar">
              <div class="cap" id="cpu-cap"><?php echo h($cpuLbl); ?></div>
              <div class="col"><div class="fill" id="cpu-fill" style="height:<?php echo $cpu; ?>%"></div></div>
              <div class="lbl">CPU</div>
            </div>
            <div class="vbar">
              <div class="cap" id="ram-cap"><?php echo h($ramLbl); ?></div>
              <div class="col"><div class="fill" id="ram-fill" style="height:<?php echo $ram; ?>%"></div></div>
              <div class="lbl">RAM</div>
            </div>
            <div class="vbar">
              <div class="cap" id="dsk-cap"><?php echo h($dskLbl); ?></div>
              <div class="col"><div class="fill" id="dsk-fill" style="height:<?php echo $dsk; ?>%"></div></div>
              <div class="lbl">Storage</div>
            </div>
            <div class="vbar">
              <div class="cap" id="down-cap">0 Mbps</div>
              <div class="col"><div class="fill net" id="down-fill" style="height:0%"></div></div>
              <div class="lbl">Download</div>
            </div>
            <div class="vbar">
              <div class="cap" id="up-cap">0 Mbps</div>
              <div class="col"><div class="fill net" id="up-fill" style="height:0%"></div></div>
              <div class="lbl">Upload</div>
            </div>
          </div>
        </article>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- For You (always rendered). Order: News, Exercises donut, My Workout Plans -->
    <div class="section">
      <h2>For You</h2>
      <section class="cards cols-4">
        <!-- NEWS -->
        <article class="card" data-card-key="news" data-lock-visibility="true">
          <h3>News</h3>
          <p class="muted" style="margin:0 0 8px;">Latest updates from your trainer.</p>
          <div class="actions">
            <a class="btn" href="news.php">View News</a>
          </div>
        </article>

        <!-- EXERCISES (BY CATEGORY) — responsive donut (centered pack) -->
        <article class="card" data-card-key="exercise-categories">
          <h3>Exercises by Category</h3>
          <div class="center-pack">
            <div class="donut-wrap" aria-label="Exercises by Category">
              <div class="donut">
                <?php
                  $rU = 42; $swU = 12; $CU = 2 * M_PI * $rU;
                  $sumCounts = max(0, (int)array_sum(array_column($category_rows,'count')));
                  $cursor = 0.0; // cumulative arc length drawn
                  $N = count($category_rows);
                ?>
                <svg viewBox="0 0 120 120" role="img" aria-labelledby="excatlbl">
                  <title id="excatlbl">Donut chart: Exercise categories assigned to you</title>
                  <circle cx="60" cy="60" r="<?php echo $rU; ?>" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="<?php echo $swU; ?>" />
                  <?php
                    if ($sumCounts > 0) {
                      $palette = $CAT_PALETTE; $palN = count($palette);
                      for ($i = 0; $i < $N; $i++) {
                        $row = $category_rows[$i];
                        $cnt = (int)$row['count'];
                        if ($cnt <= 0) continue;

                        // For the last slice, force it to consume the remainder to avoid any seam from FP error
                        if ($i === $N - 1) {
                          $dash = max(0.0, $CU - $cursor);
                        } else {
                          $dash = ($cnt / $sumCounts) * $CU;
                        }
                        $gap  = max(0.0001, $CU - $dash);
                        $dashOffset = max(0.0, $CU - $cursor); // position slice immediately after previous
                        $color = $palette[$i % $palN];

                        echo '<circle cx="60" cy="60" r="'.$rU.'" fill="none" stroke="'.$color.'" stroke-linecap="butt" stroke-width="'.$swU.'" stroke-dasharray="'.($dash.' '.$gap).'" stroke-dashoffset="'.($dashOffset).'" />'."\n";

                        $cursor += $dash;
                        // Clamp to CU to keep numbers tidy
                        if ($cursor > $CU) $cursor = $CU;
                      }
                    }
                  ?>
                </svg>

                <div class="center">
                  <div>
                    <div style="font-weight:700; font-size:16px; color:#fff;">
                      <?php echo $total_categories_for_user > 0 ? (int)$total_categories_for_user : '—'; ?>
                    </div>
                    <div class="muted">Categories</div>
                  </div>
                </div>
              </div>

              <div class="legend">
                <?php if ($total_categories_for_user === 0): ?>
                  <p class="muted" style="margin:0">No assigned exercises yet.</p>
                <?php else: ?>
                  <?php
                    $legendRows = array_slice($category_rows, 0, 6);
                    $i=0; $palN=count($CAT_PALETTE);
                    foreach ($legendRows as $row):
                      $color = $CAT_PALETTE[$i % $palN];
                  ?>
                    <div class="row">
                      <span class="swatch" style="background: <?php echo $color; ?>"></span>
                      <span class="label"><?php echo h($row['name']); ?></span>
                      <span class="value"><?php echo (int)$row['count']; ?></span>
                    </div>
                  <?php $i++; endforeach; ?>
                  <?php if ($total_categories_for_user > count($legendRows)): ?>
                    <div class="muted" style="font-size:12px;">+ <?php echo $total_categories_for_user - count($legendRows); ?> more</div>
                  <?php endif; ?>
                  <p class="muted" style="margin:6px 0 0;">Distribution of categories across your assigned exercises.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>

        <?php if ($is_client): ?>
        <!-- MY WORKOUT PLANS — clickable + shows assigned date -->
        <article class="card" data-card-key="my-workout-plans" data-lock-visibility="true">
          <h3>My Workout Plans</h3>

          <?php if ($plans_assigned_count === null): ?>
            <p class="muted" style="margin:0">Plan assignments are not configured.</p>
          <?php elseif ((int)$plans_assigned_count === 0): ?>
            <p class="muted" style="margin:0">No plans assigned yet.</p>
          <?php else: ?>
            <div class="pill-row">
              <span class="pill"><span class="num"><?php echo (int)$plans_assigned_count; ?></span>Assigned</span>
              <span class="pill"><span class="num"><?php echo (int)($total_exercises_in_my_plans ?? 0); ?></span>Total Exercises</span>
            </div>

            <?php if ($my_plan_summaries): ?>
              <div class="plan-list">
                <?php
                  $maxBar = max(1, (int)$my_plan_max_ex_cnt);
                  foreach ($my_plan_summaries as $p):
                    $pct = min(100, (int)round(($p['ex_cnt'] / $maxBar) * 100));
                    $assignedTxt = '';
                    if (!empty($p['assigned_at'])) {
                      $t = strtotime($p['assigned_at']);
                      if ($t) $assignedTxt = date('M j, Y', $t);
                    }
                    $link = 'client_plans.php?user_id='.(int)($USER_ID ?? 0).'&focus_up='.(int)$p['user_plan_id'];
                ?>
                  <a class="plan-item" href="<?php echo h($link); ?>">
                    <div class="pi-top">
                      <div class="pi-name" title="<?php echo h($p['name']); ?>"><?php echo h($p['name']); ?></div>
                      <span class="badge"><?php echo (int)$p['ex_cnt']; ?> ex</span>
                    </div>
                    <div class="bar" aria-hidden="true"><span style="width:<?php echo $pct; ?>%"></span></div>
                    <?php if ($assignedTxt): ?>
                      <div style="margin-top:8px; display:flex; gap:8px; align-items:center;">
                        <span class="chip">Assigned <?php echo h($assignedTxt); ?></span>
                      </div>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="muted" style="margin:8px 0 0;">Your assigned plans will appear here.</p>
            <?php endif; ?>
          <?php endif; ?>

          <div class="actions" style="margin-top:12px;">

            <a class="btn primary" href="client_plans.php?user_id=<?php echo (int)($USER_ID ?? 0); ?>">Open Plans</a>
          </div>
        </article>
        <?php endif; ?>
      </section>
    </div>

  </main>

  <div class="dash-settings-overlay" data-settings-overlay hidden></div>
  <aside class="dash-settings" id="dashboard-settings" aria-hidden="true">
    <div class="dash-settings__inner">
      <div class="dash-settings__header">
        <h2>Dashboard Options</h2>
        <button type="button" class="dash-settings__close" data-close-settings>Close</button>
      </div>
      <div class="dash-settings__section">
        <h3>Cards per row</h3>
        <div class="dash-settings__columns">
          <label>
            <input type="radio" name="dash-columns" value="2">
            <span>2 per row</span>
          </label>
          <label>
            <input type="radio" name="dash-columns" value="3">
            <span>3 per row</span>
          </label>
          <label>
            <input type="radio" name="dash-columns" value="4">
            <span>4 per row</span>
          </label>
        </div>
      </div>
      <div class="dash-settings__section">
        <h3>Visible cards</h3>
        <div class="dash-settings__groups" data-settings-card-list></div>
        <div class="dash-settings__empty" data-settings-empty hidden>No cards available.</div>
      </div>
    </div>
  </aside>

    <script>
    (function(){
      const userScope = <?php echo json_encode('uid-' . ($USER_ID ?? 'guest')); ?>;
      const containers = Array.from(document.querySelectorAll('.cards'));
      if (!containers.length) return;

      const layoutKey = `ppf-dashboard-layout::${userScope}`;
      const layoutState = loadLayoutState();
      const cardsMetadata = [];
      const containerLabels = new Map();
      let stateDirty = false;
      let overlayHideTimer = null;

      const settingsToggle = document.querySelector('.dash-settings-toggle');
      const settingsPanel = document.getElementById('dashboard-settings');
      const settingsOverlay = document.querySelector('[data-settings-overlay]');
      const settingsClose = settingsPanel ? settingsPanel.querySelector('[data-close-settings]') : null;
      const cardListEl = settingsPanel ? settingsPanel.querySelector('[data-settings-card-list]') : null;
      const emptyStateEl = settingsPanel ? settingsPanel.querySelector('[data-settings-empty]') : null;
      const columnRadios = settingsPanel ? Array.from(settingsPanel.querySelectorAll('input[name="dash-columns"]')) : [];

      const initialColumns = clampColumns(layoutState.columns);
      layoutState.columns = initialColumns;
      applyColumnClass(initialColumns);

      containers.forEach((container, containerIndex) => {
        const cards = Array.from(container.querySelectorAll(':scope > .card'));
        if (!cards.length) return;

        const seenKeys = new Set();
        cards.forEach((card, idx) => {
          const key = ensureCardKey(card, idx, seenKeys);
          seenKeys.add(key);
          card.classList.add('is-draggable');
          cardsMetadata.push({
            containerIndex,
            key,
            element: card,
            locked: card.hasAttribute('data-lock-visibility'),
            title: extractCardTitle(card)
          });
        });

        containerLabels.set(containerIndex, deriveContainerLabel(container, containerIndex));

        const state = ensureContainerState(containerIndex, cards);
        const migrated = migrateLegacyOrder(containerIndex, container, state);
        if (migrated) stateDirty = true;

        const currentOrder = captureDomOrder(container);
        if (!arraysEqual(currentOrder, state.order)) {
          applyOrder(container, state.order);
          stateDirty = true;
        }
        const actualOrder = captureDomOrder(container);
        if (!arraysEqual(state.order, actualOrder)) {
          state.order = actualOrder;
          stateDirty = true;
        }

        const actualHidden = applyVisibility(container, state.hidden);
        if (!arraysEqual(state.hidden, actualHidden)) {
          state.hidden = actualHidden;
          stateDirty = true;
        }

        const actualSizes = applySpans(container, state.sizes, containerIndex);
        if (!objectsShallowEqual(state.sizes, actualSizes)) {
          state.sizes = actualSizes;
          stateDirty = true;
        }

        setupPointerDrag(container, containerIndex);
      });

      if (stateDirty) saveLayoutState();

      renderCardOptions();
      bindSettingsPanel();
      syncVisibilityControls();

      function ensureCardKey(card, idx, seenKeys){
        let key = (card.getAttribute('data-card-key') || card.dataset.cardKey || '').trim();
        if (key && !seenKeys.has(key)) {
          card.dataset.cardKey = key;
          return key;
        }
        const heading = card.querySelector('h1, h2, h3, h4, h5, h6');
        const raw = heading ? heading.textContent.trim().toLowerCase() : '';
        const base = raw ? raw.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') : 'card';
        let candidate = base || `card-${idx}`;
        let attempt = 1;
        while (seenKeys.has(candidate) || !candidate) {
          attempt += 1;
          candidate = `${base || 'card'}-${attempt}`;
        }
        card.dataset.cardKey = candidate;
        return candidate;
      }

      function extractCardTitle(card){
        const heading = card.querySelector('h1, h2, h3, h4, h5, h6');
        return heading ? heading.textContent.trim() : (card.dataset.cardKey || 'Card');
      }

      function deriveContainerLabel(container, idx){
        const aria = (container.getAttribute('aria-label') || '').trim();
        if (aria) return aria;
        const section = container.closest('.section');
        if (section) {
          const heading = section.querySelector('h1, h2, h3, h4, h5, h6');
          if (heading) return heading.textContent.trim();
        }
        return `Section ${idx + 1}`;
      }

      function clampColumns(value){
        const num = Number(value);
        if (!isFinite(num)) return 4;
        return Math.min(4, Math.max(2, Math.round(num)));
      }

      function clampSpan(value, limit){
        const max = typeof limit === 'number' && isFinite(limit) ? limit : layoutState.columns || 4;
        const num = Number(value);
        if (!isFinite(num)) return 1;
        return Math.min(max, Math.max(1, Math.round(num)));
      }

      function getColumnMetrics(container){
        const columns = clampColumns(layoutState.columns);
        const rect = container ? container.getBoundingClientRect() : null;
        const styles = container ? window.getComputedStyle(container) : null;
        let gap = styles ? parseFloat(styles.columnGap) : NaN;
        if (!isFinite(gap) && styles) gap = parseFloat(styles.gap);
        if (!isFinite(gap)) gap = 0;
        const totalGap = gap * Math.max(0, columns - 1);
        const width = rect ? rect.width : 0;
        const available = width - totalGap;
        const columnWidth = columns > 0 && available > 0 ? available / columns : 0;
        const unit = columnWidth + gap;
        return { columns, gap, columnWidth, unit };
      }

      function getDefaultSpan(card){
        const attr = card ? Number(card.getAttribute('data-default-span')) : NaN;
        if (isFinite(attr) && attr >= 1) {
          return clampSpan(attr, layoutState.columns || 4);
        }
        return 1;
      }

      function loadLayoutState(){
        let raw = null;
        try {
          raw = window.localStorage ? window.localStorage.getItem(layoutKey) : null;
        } catch (err) {
          raw = null;
        }
        if (!raw) return { columns: 4, containers: {} };
        try {
          const parsed = JSON.parse(raw);
          if (!parsed || typeof parsed !== 'object') return { columns: 4, containers: {} };
          const state = {
            columns: clampColumns(parsed.columns),
            containers: {}
          };
          if (parsed.containers && typeof parsed.containers === 'object') {
            Object.keys(parsed.containers).forEach(key => {
              const entry = parsed.containers[key];
              if (!entry || typeof entry !== 'object') return;
              const order = Array.isArray(entry.order) ? entry.order.filter(Boolean) : [];
              const hidden = Array.isArray(entry.hidden) ? entry.hidden.filter(Boolean) : [];
              const sizes = {};
              if (entry.sizes && typeof entry.sizes === 'object') {
                Object.keys(entry.sizes).forEach(cardKey => {
                  const span = clampSpan(entry.sizes[cardKey], clampColumns(state.columns));
                  if (span >= 1) sizes[cardKey] = span;
                });
              }
              state.containers[key] = { order, hidden, sizes };
            });
          }
          return state;
        } catch (err) {
          return { columns: 4, containers: {} };
        }
      }

      function saveLayoutState(){
        try {
          if (!window.localStorage) return;
          const payload = JSON.stringify(layoutState);
          window.localStorage.setItem(layoutKey, payload);
        } catch (err) {
          /* non-fatal */
        }
      }

      function ensureContainerState(index, cards){
        const key = String(index);
        if (!layoutState.containers || typeof layoutState.containers !== 'object') {
          layoutState.containers = {};
        }
        let record = layoutState.containers[key];
        if (!record || typeof record !== 'object') {
          record = { order: [], hidden: [], sizes: {} };
          layoutState.containers[key] = record;
        }
        if (!Array.isArray(record.order)) record.order = [];
        if (!Array.isArray(record.hidden)) record.hidden = [];
        if (!record.sizes || typeof record.sizes !== 'object') record.sizes = {};
        if (cards) {
          const keys = cards.map(card => card.dataset.cardKey).filter(Boolean);
          const keySet = new Set(keys);
          record.order = record.order.filter(k => keySet.has(k));
          keys.forEach(k => {
            if (!record.order.includes(k)) record.order.push(k);
          });
          record.hidden = record.hidden.filter(k => keySet.has(k));
          const nextSizes = {};
          cards.forEach(card => {
            const keyName = card.dataset.cardKey;
            if (!keyName) return;
            const stored = Number(record.sizes[keyName]);
            const value = isFinite(stored) ? stored : getDefaultSpan(card);
            nextSizes[keyName] = clampSpan(value);
          });
          const currentKeys = Object.keys(record.sizes);
          currentKeys.forEach(k => {
            if (!nextSizes.hasOwnProperty(k)) {
              delete record.sizes[k];
              stateDirty = true;
            }
          });
          Object.keys(nextSizes).forEach(k => {
            if (record.sizes[k] !== nextSizes[k]) {
              record.sizes[k] = nextSizes[k];
              stateDirty = true;
            }
          });
        } else {
          Object.keys(record.sizes).forEach(k => {
            const clamped = clampSpan(record.sizes[k]);
            if (record.sizes[k] !== clamped) {
              record.sizes[k] = clamped;
              stateDirty = true;
            }
          });
        }
        return record;
      }

      function applyOrder(container, order){
        if (!Array.isArray(order) || !order.length) return;
        const map = new Map();
        Array.from(container.querySelectorAll(':scope > .card')).forEach(card => {
          const key = card.dataset.cardKey;
          if (key) map.set(key, card);
        });
        order.forEach(key => {
          const card = map.get(key);
          if (card) {
            container.appendChild(card);
            map.delete(key);
          }
        });
        map.forEach(card => container.appendChild(card));
      }

      function captureDomOrder(container){
        return Array.from(container.querySelectorAll(':scope > .card'))
          .map(card => card.dataset.cardKey)
          .filter(Boolean);
      }

      function applyVisibility(container, hiddenList){
        const hiddenSet = new Set(Array.isArray(hiddenList) ? hiddenList : []);
        const actualHidden = [];
        Array.from(container.querySelectorAll(':scope > .card')).forEach(card => {
          const key = card.dataset.cardKey;
          if (!key) return;
          const locked = card.hasAttribute('data-lock-visibility');
          const shouldHide = hiddenSet.has(key) && !locked;
          if (shouldHide){
            card.classList.add('is-hidden');
            card.setAttribute('aria-hidden', 'true');
            card.hidden = true;
            actualHidden.push(key);
          } else {
            card.classList.remove('is-hidden');
            card.removeAttribute('aria-hidden');
            if (card.hidden) card.hidden = false;
          }
        });
        return actualHidden;
      }

      function applySpans(container, sizeMap, containerIndex){
        const sizes = {};
        const cards = Array.from(container.querySelectorAll(':scope > .card'));
        cards.forEach(card => {
          const key = card.dataset.cardKey;
          if (!key) return;
          const stored = sizeMap && typeof sizeMap === 'object' ? Number(sizeMap[key]) : NaN;
          const span = clampSpan(isFinite(stored) ? stored : getDefaultSpan(card));
          sizes[key] = span;
          applySpanToCardElement(card, span);
          installResizeHandle(card, containerIndex, span);
        });
        return sizes;
      }

      function applySpanToCardElement(card, span){
        card.dataset.cardSpan = String(span);
        card.style.setProperty('--card-span', span);
        const handle = card.querySelector('[data-resize-handle]');
        if (handle) updateResizeHandleLabel(handle, span);
      }

      function installResizeHandle(card, containerIndex, span){
        if (!card) return;
        let handle = card.querySelector('[data-resize-handle]');
        if (!handle) {
          handle = document.createElement('button');
          handle.type = 'button';
          handle.className = 'card-resize';
          handle.setAttribute('data-resize-handle', 'true');
          handle.setAttribute('aria-label', 'Resize card width');
          handle.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5 6h10a1 1 0 000-2H5a1 1 0 100 2zm0 5h10a1 1 0 000-2H5a1 1 0 100 2zm0 5h10a1 1 0 000-2H5a1 1 0 100 2z"/></svg>';
          handle.addEventListener('pointerdown', evt => startResizePointer(evt, card, containerIndex));
          handle.addEventListener('click', evt => evt.preventDefault());
          handle.addEventListener('keydown', evt => handleResizeKey(evt, card, containerIndex));
          card.appendChild(handle);
        }
        handle.dataset.containerIndex = String(containerIndex);
        handle.dataset.cardKey = card.dataset.cardKey || '';
        updateResizeHandleLabel(handle, span);
      }

      function updateResizeHandleLabel(handle, span){
        if (!handle) return;
        const max = layoutState.columns || 4;
        handle.setAttribute('aria-label', `Resize card width (current ${span} of ${max} columns)`);
        handle.setAttribute('title', `Span ${span} of ${max}`);
      }

      function ensureResizeGhost(card){
        if (!card) return null;
        let ghost = card.querySelector('[data-resize-ghost]');
        if (!ghost) {
          ghost = document.createElement('div');
          ghost.className = 'card-resize-ghost';
          ghost.setAttribute('data-resize-ghost', '');
          card.appendChild(ghost);
        }
        return ghost;
      }

      function updateResizeGhost(card, rawSpan, columnsOverride){
        if (!card) return;
        const columns = clampColumns(columnsOverride != null ? columnsOverride : layoutState.columns);
        ensureResizeGhost(card);
        const ratio = columns > 0 ? Math.max(0, Math.min(1, rawSpan / columns)) : 0;
        card.style.setProperty('--card-resize-preview', String(ratio));
      }

      function startResizePointer(evt, card, containerIndex){
        if (!card || !containers[containerIndex]) return;
        if (evt.button !== undefined && evt.button !== 0 && evt.pointerType !== 'touch') return;
        evt.preventDefault();
        evt.stopPropagation();

        const container = containers[containerIndex];
        const key = card.dataset.cardKey;
        if (!key) return;

        const state = ensureContainerState(containerIndex);
        const currentSpan = getCardSpanFromState(state, key, card);
        const handle = evt.currentTarget;
        const pointerId = evt.pointerId;
        const baseMetrics = getColumnMetrics(container);
        const rect = card.getBoundingClientRect();
        const baseHeight = rect.height;
        const baseWidth = rect.width;
        const placeholder = document.createElement('div');
        placeholder.className = 'card-placeholder card-placeholder--resize';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.style.height = baseHeight + 'px';
        placeholder.style.setProperty('--card-span', card.dataset.cardSpan || card.getAttribute('data-card-span') || '1');

        const originalStyles = {
          position: card.style.position,
          left: card.style.left,
          top: card.style.top,
          width: card.style.width,
          height: card.style.height,
          zIndex: card.style.zIndex,
          pointerEvents: card.style.pointerEvents,
          transition: card.style.transition
        };

        const initialSpan = currentSpan;
        let activeSpan = currentSpan;
        let finished = false;

        card.classList.add('is-resizing', 'show-resize-preview', 'is-floating');
        updateResizeGhost(card, currentSpan, baseMetrics.columns);

        container.insertBefore(placeholder, card);
        container.removeChild(card);
        document.body.appendChild(card);

        card.style.position = 'fixed';
        const placeholderRect = placeholder.getBoundingClientRect();
        card.style.left = placeholderRect.left + 'px';
        card.style.top = placeholderRect.top + 'px';
        card.style.width = baseWidth + 'px';
        card.style.height = baseHeight + 'px';
        card.style.zIndex = '1300';
        card.style.pointerEvents = 'none';
        card.style.transition = 'none';

        if (handle && handle.setPointerCapture) {
          try { handle.setPointerCapture(pointerId); } catch (err) {}
        }

        const restoreOriginalStyles = () => {
          ['position','left','top','width','height','zIndex','pointerEvents','transition'].forEach(prop => {
            const val = originalStyles[prop];
            if (val) {
              card.style[prop] = val;
            } else {
              card.style[prop] = '';
            }
          });
        };

        const onMove = moveEvt => {
          if (pointerId != null && moveEvt.pointerId !== pointerId) return;
          moveEvt.preventDefault();
          const liveRect = placeholder.getBoundingClientRect();
          const liveMetrics = getColumnMetrics(container);
          const metricsResult = computeSpanFromPointer(moveEvt, liveMetrics, liveRect.left, baseWidth);
          if (!metricsResult) return;
          card.style.left = liveRect.left + 'px';
          card.style.top = liveRect.top + 'px';
          card.style.width = metricsResult.pixelWidth + 'px';
          updateResizeGhost(card, metricsResult.rawSpan, liveMetrics.columns);
          placeholder.style.setProperty('--card-span', String(metricsResult.span));
          const nextSpan = metricsResult.span;
          if (nextSpan && nextSpan !== activeSpan) {
            activeSpan = nextSpan;
            state.sizes[key] = activeSpan;
            applySpanToCardElement(card, activeSpan);
          }
        };

        const finish = endEvt => {
          if (pointerId != null && endEvt.pointerId !== pointerId) return;
          endEvt.preventDefault();
          cleanup(true);
        };

        const cleanup = (commit = false) => {
          if (finished) return;
          finished = true;
          window.removeEventListener('pointermove', onMove, true);
          window.removeEventListener('pointerup', finish, true);
          window.removeEventListener('pointercancel', finish, true);
          card.classList.remove('is-resizing', 'show-resize-preview');
          card.style.removeProperty('--card-resize-preview');
          if (handle && handle.releasePointerCapture) {
            try { handle.releasePointerCapture(pointerId); } catch (err) {}
          }

          const targetSpan = commit ? activeSpan : initialSpan;
          state.sizes[key] = targetSpan;
          applySpanToCardElement(card, targetSpan);

          restoreOriginalStyles();
          if (placeholder.parentNode) {
            placeholder.parentNode.insertBefore(card, placeholder);
            placeholder.remove();
          } else if (card.parentNode === document.body) {
            container.appendChild(card);
          }

          card.classList.remove('is-floating');
          saveLayoutState();
        };

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', finish, true);
        window.addEventListener('pointercancel', finish, true);
      }

      function handleResizeKey(evt, card, containerIndex){
        if (!card) return;
        const key = card.dataset.cardKey;
        if (!key) return;
        const state = ensureContainerState(containerIndex);
        const current = getCardSpanFromState(state, key, card);
        let next = current;
        if (evt.key === 'ArrowLeft' || evt.key === 'ArrowDown') {
          evt.preventDefault();
          next = clampSpan(current - 1);
        } else if (evt.key === 'ArrowRight' || evt.key === 'ArrowUp') {
          evt.preventDefault();
          next = clampSpan(current + 1);
        } else if (evt.key === 'Home') {
          evt.preventDefault();
          next = clampSpan(1);
        } else if (evt.key === 'End') {
          evt.preventDefault();
          next = clampSpan(layoutState.columns || 4);
        } else {
          return;
        }
        if (next === current) return;
        state.sizes[key] = next;
        applySpanToCardElement(card, next);
        saveLayoutState();
      }

      function computeSpanFromPointer(evt, metrics, baseLeft, fallbackWidth){
        const meta = metrics || {};
        const columns = meta.columns && meta.columns > 0 ? meta.columns : clampColumns(layoutState.columns);
        if (!(columns > 0)) return null;
        const unit = meta.unit;
        let rawSpan;
        if (unit && unit > 0) {
          rawSpan = (evt.clientX - baseLeft) / unit + 1;
        } else {
          rawSpan = 1;
        }
        if (!isFinite(rawSpan)) rawSpan = 1;
        rawSpan = Math.max(1, Math.min(columns, rawSpan));
        const span = clampSpan(Math.round(rawSpan), columns);
        let width = fallbackWidth;
        if (meta.columnWidth && meta.columnWidth > 0) {
          const cw = meta.columnWidth;
          const gap = meta.gap || 0;
          width = (cw * rawSpan) + gap * Math.max(0, rawSpan - 1);
        }
        if (!(width > 0)) width = fallbackWidth;
        return { span, rawSpan, pixelWidth: width };
      }

      function getCardSpanFromState(state, key, card){
        const sizes = state && state.sizes && typeof state.sizes === 'object' ? state.sizes : {};
        const stored = Number(sizes[key]);
        if (isFinite(stored)) return clampSpan(stored);
        return clampSpan(getDefaultSpan(card));
      }

      function refreshAllSpans(){
        let changed = false;
        containers.forEach((container, idx) => {
          const state = ensureContainerState(idx);
          const updated = applySpans(container, state.sizes, idx);
          if (!objectsShallowEqual(state.sizes, updated)) {
            state.sizes = updated;
            changed = true;
          }
        });
        return changed;
      }

      function setupPointerDrag(container, containerIndex){
        const placeholder = document.createElement('div');
        placeholder.className = 'card-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');

        let dragState = null;

        container.addEventListener('pointerdown', evt => {
          if (evt.button !== undefined && evt.button !== 0) return;
          if (!evt.isPrimary && evt.pointerType !== 'touch' && evt.pointerType !== 'pen') return;
          if (evt.target && evt.target.closest('[data-resize-handle]')) return;
          const card = evt.target && evt.target.closest('.card');
          if (!card || !container.contains(card) || card.classList.contains('is-hidden')) return;
          if (shouldIgnoreInteractive(evt.target)) return;

          dragState = {
            card,
            pointerId: evt.pointerId,
            startX: evt.clientX,
            startY: evt.clientY,
            started: false,
            container,
            containerIndex,
            offsetX: 0,
            offsetY: 0,
            originalStyles: {},
            placeholderActive: false
          };

          placeholder.style.setProperty('--card-span', card.dataset.cardSpan || card.getAttribute('data-card-span') || '1');
          placeholder.style.height = card.getBoundingClientRect().height + 'px';

          window.addEventListener('pointermove', onPointerMove, true);
          window.addEventListener('pointerup', onPointerUp, true);
          window.addEventListener('pointercancel', onPointerUp, true);
        });

        const onPointerMove = evt => {
          if (!dragState || evt.pointerId !== dragState.pointerId) return;
          const { card } = dragState;
          const moved = Math.hypot(evt.clientX - dragState.startX, evt.clientY - dragState.startY);
          if (!dragState.started) {
            if (moved < 6) return;
            startDrag(evt);
          }
          if (!dragState.started) return;
          evt.preventDefault();
          updateDrag(evt);
        };

        const onPointerUp = evt => {
          if (!dragState || evt.pointerId !== dragState.pointerId) return;
          if (dragState.started) {
            evt.preventDefault();
            finishDrag();
          }
          cleanup();
        };

        function startDrag(evt){
          const { card, container } = dragState;
          dragState.started = true;
          const rect = card.getBoundingClientRect();
          dragState.offsetX = evt.clientX - rect.left;
          dragState.offsetY = evt.clientY - rect.top;
          placeholder.style.height = rect.height + 'px';
          placeholder.style.setProperty('--card-span', card.dataset.cardSpan || card.getAttribute('data-card-span') || '1');
          if (!placeholder.parentNode) {
            container.insertBefore(placeholder, card);
          } else {
            container.insertBefore(placeholder, card);
          }
          dragState.placeholderActive = true;

          dragState.originalStyles = {
            position: card.style.position,
            left: card.style.left,
            top: card.style.top,
            width: card.style.width,
            height: card.style.height,
            zIndex: card.style.zIndex,
            pointerEvents: card.style.pointerEvents,
            transition: card.style.transition
          };

          card.classList.add('is-dragging', 'is-floating');
          container.classList.add('drag-active');

          card.style.position = 'fixed';
          card.style.left = rect.left + 'px';
          card.style.top = rect.top + 'px';
          card.style.width = rect.width + 'px';
          card.style.height = rect.height + 'px';
          card.style.zIndex = '1200';
          card.style.pointerEvents = 'none';
          card.style.transition = 'none';

          try { card.setPointerCapture(dragState.pointerId); } catch (err) {}
          document.body.appendChild(card);
          updateDrag(evt);
        }

        function updateDrag(evt){
          const { card, offsetX, offsetY, container } = dragState;
          card.style.left = `${evt.clientX - offsetX}px`;
          card.style.top = `${evt.clientY - offsetY}px`;
          movePlaceholder(container, placeholder, evt.clientX, evt.clientY, card);
        }

        function finishDrag(){
          const { card, container, containerIndex } = dragState;
          if (card.hasPointerCapture && dragState.pointerId != null) {
            try { card.releasePointerCapture(dragState.pointerId); } catch (err) {}
          }
          restoreCard(card);
          const targetParent = placeholder.parentNode || container;
          if (targetParent) {
            targetParent.insertBefore(card, placeholder);
          }
          placeholder.remove();
          card.classList.remove('is-dragging', 'is-floating');
          container.classList.remove('drag-active');
          persistOrder(container, containerIndex);
        }

        function restoreCard(card){
          const styles = dragState.originalStyles || {};
          card.style.position = styles.position || '';
          card.style.left = styles.left || '';
          card.style.top = styles.top || '';
          card.style.width = styles.width || '';
          card.style.height = styles.height || '';
          card.style.zIndex = styles.zIndex || '';
          card.style.pointerEvents = styles.pointerEvents || '';
          card.style.transition = styles.transition || '';
        }

        function cleanup(){
          window.removeEventListener('pointermove', onPointerMove, true);
          window.removeEventListener('pointerup', onPointerUp, true);
          window.removeEventListener('pointercancel', onPointerUp, true);
          if (dragState && dragState.started) {
            const { card } = dragState;
            if (card && card.hasPointerCapture && dragState.pointerId != null) {
              try { card.releasePointerCapture(dragState.pointerId); } catch (err) {}
            }
          }
          if (dragState && dragState.placeholderActive && placeholder.parentNode === null) {
            // ensure placeholder removed if drag aborted
            placeholder.remove();
          }
          dragState = null;
        }

      }

      function movePlaceholder(container, placeholder, clientX, clientY, floatingCard){
        if (!container || !placeholder) return;
        const reference = findInsertionReference(container, floatingCard, clientX, clientY);
        if (reference && reference !== placeholder) {
          container.insertBefore(placeholder, reference);
        } else if (!reference) {
          container.appendChild(placeholder);
        }
      }

      function shouldIgnoreInteractive(target){
        return !!(target && target.closest('a, button, input, textarea, select, label'));
      }

      function findInsertionReference(container, dragging, clientX, clientY){
        const cards = Array.from(container.querySelectorAll(':scope > .card:not(.is-dragging):not(.is-hidden)'));
        if (!cards.length) return null;

        const hovered = document.elementFromPoint(clientX, clientY);
        const hoverItem = hovered ? hovered.closest('.card, .card-placeholder') : null;
        if (hoverItem && hoverItem !== dragging && container.contains(hoverItem)){
          if (hoverItem.classList.contains('card-placeholder')) {
            return hoverItem;
          }
          if (!hoverItem.classList.contains('is-hidden')) {
            const rect = hoverItem.getBoundingClientRect();
            const before = clientX < rect.left + rect.width / 2;
            return before ? hoverItem : hoverItem.nextElementSibling;
          }
        }

        let closest = null;
        let closestDistance = Infinity;
        cards.forEach(card => {
          const rect = card.getBoundingClientRect();
          const dx = clientX - (rect.left + rect.width / 2);
          const dy = clientY - (rect.top + rect.height / 2);
          const distance = Math.hypot(dx, dy);
          if (distance < closestDistance) {
            closestDistance = distance;
            closest = card;
          }
        });
        if (!closest) return null;
        const rect = closest.getBoundingClientRect();
        const before = clientX < rect.left + rect.width / 2;
        return before ? closest : closest.nextElementSibling;
      }

      function persistOrder(container, containerIndex){
        const state = ensureContainerState(containerIndex);
        const order = captureDomOrder(container);
        if (!arraysEqual(state.order, order)) {
          state.order = order;
          saveLayoutState();
        }
      }

      function renderCardOptions(){
        if (!cardListEl) return;
        cardListEl.innerHTML = '';
        if (!cardsMetadata.length) {
          if (emptyStateEl) emptyStateEl.hidden = false;
          return;
        }
        const groups = new Map();
        cardsMetadata.forEach(meta => {
          if (!groups.has(meta.containerIndex)) {
            groups.set(meta.containerIndex, []);
          }
          groups.get(meta.containerIndex).push(meta);
        });

        const fragment = document.createDocumentFragment();
        Array.from(groups.keys()).sort((a, b) => a - b).forEach(idx => {
          const group = document.createElement('div');
          group.className = 'dash-settings__group';

          const heading = document.createElement('h4');
          heading.textContent = containerLabels.get(idx) || `Section ${idx + 1}`;
          group.appendChild(heading);

          groups.get(idx).forEach(meta => {
            const option = document.createElement('label');
            option.className = 'dash-settings__option';
            if (meta.locked) option.classList.add('is-locked');

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.dataset.containerIndex = String(meta.containerIndex);
            checkbox.dataset.cardKey = meta.key;
            checkbox.checked = !isCardHidden(meta.containerIndex, meta.key);
            checkbox.disabled = meta.locked;
            option.appendChild(checkbox);

            const nameSpan = document.createElement('span');
            nameSpan.textContent = meta.title || meta.key;
            option.appendChild(nameSpan);

            if (meta.locked){
              const tag = document.createElement('span');
              tag.className = 'dash-settings__tag';
              tag.textContent = 'Always visible';
              option.appendChild(tag);
            }

            checkbox.addEventListener('change', onCardToggle);
            group.appendChild(option);
          });

          fragment.appendChild(group);
        });

        cardListEl.appendChild(fragment);
        if (emptyStateEl) emptyStateEl.hidden = cardListEl.children.length > 0;
      }

      function onCardToggle(evt){
        const checkbox = evt.currentTarget;
        const index = Number(checkbox.dataset.containerIndex);
        const key = checkbox.dataset.cardKey;
        if (!key) return;
        const state = ensureContainerState(index);
        const hiddenSet = new Set(state.hidden);
        if (checkbox.checked) {
          hiddenSet.delete(key);
        } else {
          hiddenSet.add(key);
        }
        state.hidden = Array.from(hiddenSet);
        const container = containers[index];
        if (container) {
          const actualHidden = applyVisibility(container, state.hidden);
          if (!arraysEqual(state.hidden, actualHidden)) {
            state.hidden = actualHidden;
          }
        }
        saveLayoutState();
        syncVisibilityControls();
      }

      function bindSettingsPanel(){
        if (!settingsToggle || !settingsPanel) return;
        settingsToggle.addEventListener('click', () => {
          if (settingsPanel.classList.contains('is-open')) {
            closeSettings();
          } else {
            openSettings();
          }
        });
        if (settingsClose) settingsClose.addEventListener('click', closeSettings);
        if (settingsOverlay) settingsOverlay.addEventListener('click', closeSettings);
        document.addEventListener('keydown', evt => {
          if (evt.key === 'Escape' && settingsPanel.classList.contains('is-open')) {
            closeSettings();
          }
        });
        columnRadios.forEach(radio => {
          radio.checked = Number(radio.value) === layoutState.columns;
          radio.addEventListener('change', () => {
            if (radio.checked) updateColumns(Number(radio.value));
          });
        });
      }

      function openSettings(){
        if (!settingsPanel || !settingsToggle) return;
        if (overlayHideTimer) {
          clearTimeout(overlayHideTimer);
          overlayHideTimer = null;
        }
        settingsPanel.classList.add('is-open');
        settingsPanel.setAttribute('aria-hidden', 'false');
        settingsToggle.classList.add('is-active');
        settingsToggle.setAttribute('aria-expanded', 'true');
        if (settingsOverlay) {
          settingsOverlay.hidden = false;
          settingsOverlay.classList.add('is-visible');
        }
      }

      function closeSettings(){
        if (!settingsPanel || !settingsToggle) return;
        settingsPanel.classList.remove('is-open');
        settingsPanel.setAttribute('aria-hidden', 'true');
        settingsToggle.classList.remove('is-active');
        settingsToggle.setAttribute('aria-expanded', 'false');
        if (settingsOverlay) {
          settingsOverlay.classList.remove('is-visible');
          overlayHideTimer = window.setTimeout(() => {
            if (settingsOverlay) settingsOverlay.hidden = true;
            overlayHideTimer = null;
          }, 200);
        }
      }

      function updateColumns(value){
        const columns = clampColumns(value);
        if (layoutState.columns === columns) return;
        layoutState.columns = columns;
        applyColumnClass(columns);
        refreshAllSpans();
        saveLayoutState();
      }

      function applyColumnClass(columns){
        containers.forEach(container => {
          container.classList.remove('cols-2', 'cols-3', 'cols-4');
          container.classList.add(`cols-${columns}`);
        });
        columnRadios.forEach(radio => {
          radio.checked = Number(radio.value) === columns;
        });
      }

      function isCardHidden(containerIndex, key){
        const state = layoutState.containers && layoutState.containers[String(containerIndex)];
        if (!state || !Array.isArray(state.hidden)) return false;
        return state.hidden.includes(key);
      }

      function syncVisibilityControls(){
        if (!cardListEl) return;
        const checkboxes = cardListEl.querySelectorAll('input[type="checkbox"][data-card-key]');
        checkboxes.forEach(box => {
          const index = Number(box.dataset.containerIndex);
          const key = box.dataset.cardKey;
          box.checked = !isCardHidden(index, key);
        });
        if (emptyStateEl) {
          emptyStateEl.hidden = cardListEl.children.length > 0;
        }
      }

      function arraysEqual(a, b){
        if (!Array.isArray(a) || !Array.isArray(b)) return false;
        if (a.length != b.length) return false;
        for (let i = 0; i < a.length; i++) {
          if (a[i] !== b[i]) return false;
        }
        return true;
      }

      function objectsShallowEqual(a, b){
        const keysA = a && typeof a === 'object' ? Object.keys(a) : [];
        const keysB = b && typeof b === 'object' ? Object.keys(b) : [];
        if (keysA.length !== keysB.length) return false;
        for (let i = 0; i < keysA.length; i++) {
          const key = keysA[i];
          if (a[key] !== b[key]) return false;
        }
        return true;
      }

      function migrateLegacyOrder(containerIndex, container, state){
        const legacyKey = buildLegacyStorageKey(containerIndex);
        let raw = null;
        try {
          raw = window.localStorage ? window.localStorage.getItem(legacyKey) : null;
        } catch (err) {
          raw = null;
        }
        if (raw != null) {
          try {
            if (window.localStorage) window.localStorage.removeItem(legacyKey);
          } catch (err) {}
        }
        if (!raw) return false;
        let parsed;
        try {
          parsed = JSON.parse(raw);
        } catch (err) {
          return false;
        }
        if (!Array.isArray(parsed) || !parsed.length) return false;
        const merged = mergeOrders(parsed, state.order, container);
        if (!arraysEqual(state.order, merged)) {
          state.order = merged;
          applyOrder(container, state.order);
          return true;
        }
        return false;
      }

      function buildLegacyStorageKey(index){
        return `ppf-dashboard-order::${userScope}::${index}`;
      }

      function mergeOrders(preferred, fallback, container){
        const keys = captureDomOrder(container);
        const keySet = new Set(keys);
        const result = [];
        preferred.forEach(key => {
          if (keySet.has(key) && !result.includes(key)) result.push(key);
        });
        fallback.forEach(key => {
          if (keySet.has(key) && !result.includes(key)) result.push(key);
        });
        return result;
      }

    })();
    </script>


        <script>
(function(){
  const $ = (id) => document.getElementById(id);

  const cpuFill = $('cpu-fill'),  cpuCap = $('cpu-cap');
  const ramFill = $('ram-fill'),  ramCap = $('ram-cap');
  const dskFill = $('dsk-fill'),  dskCap = $('dsk-cap');
  const dwnFill = $('down-fill'), dwnCap = $('down-cap');
  const upFill  = $('up-fill'),   upCap  = $('up-cap');

  let prevRx = null, prevTx = null, prevTs = null;
  let peakDown = 1, peakUp = 1;

  function sysUrl(){ return 'sys_json.php'; }

  function clamp01(x){ return Math.max(0, Math.min(1, x)); }

  function fmtMbps(v){
    if (!isFinite(v) || v < 0) return '—';
    if (v >= 1000) return (v/1000).toFixed(2) + ' Gbps';
    if (v >= 100)  return v.toFixed(0) + ' Mbps';
    return v.toFixed(1) + ' Mbps';
  }

  function setBar(elFill, elCap, valuePct, label){
    if (!elFill || !elCap) return;
    const h = Math.max(0, Math.min(100, Math.round(valuePct)));
    elFill.style.height = h + '%';
    elCap.textContent = label;
  }

  async function poll(){
    try{
      const res = await fetch(sysUrl(), { cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const j = await res.json();

      const ts  = Number(j.ts);
      const cpu = Number(j.cpu_pct);
      const ram = Number(j.ram_used_pct);
      const dsk = Number(j.disk_used_pct);
      const rx  = j.net && j.net.rx_bytes != null ? Number(j.net.rx_bytes) : null;
      const tx  = j.net && j.net.tx_bytes != null ? Number(j.net.tx_bytes) : null;

      if (isFinite(cpu)) setBar(cpuFill, cpuCap, cpu, (Math.round(cpu) + '%'));
      else if (cpuCap) cpuCap.textContent = '—';

      if (isFinite(ram)) setBar(ramFill, ramCap, ram, (Math.round(ram) + '%'));
      else if (ramCap) ramCap.textContent = '—';

      if (isFinite(dsk)) setBar(dskFill, dskCap, dsk, (Math.round(dsk) + '%'));
      else if (dskCap) dskCap.textContent = '—';

      if (rx != null && tx != null && isFinite(ts)) {
        if (prevRx != null && prevTx != null && prevTs != null && ts > prevTs) {
          const dt = ts - prevTs;
          let dRx = rx - prevRx;
          let dTx = tx - prevTx;
          if (dRx < 0) dRx = 0;
          if (dTx < 0) dTx = 0;

          const downMbps = (dRx * 8) / (dt * 1e6);
          const upMbps   = (dTx * 8) / (dt * 1e6);

          if (isFinite(downMbps)) peakDown = Math.max(peakDown, downMbps);
          if (isFinite(upMbps))   peakUp   = Math.max(peakUp,   upMbps);

          const downPct = isFinite(downMbps) ? (downMbps / peakDown) * 100 : 0;
          const upPct   = isFinite(upMbps)   ? (upMbps   / peakUp)   * 100 : 0;

          setBar(dwnFill, dwnCap, downPct, fmtMbps(downMbps));
          setBar(upFill,  upCap,   upPct,  fmtMbps(upMbps));
        }
        prevRx = rx; prevTx = tx; prevTs = ts;
      } else {
        if (dwnCap) dwnCap.textContent = '—';
        if (upCap)  upCap.textContent  = '—';
      }
    } catch(e){
      /* non-fatal */
    }
  }

  poll();
  setInterval(poll, 5000);

})();
</script>

</body>
</html>