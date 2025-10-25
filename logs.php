<?php
// logs.php — reusable logger + (direct-access) admin viewer for PeterPangFit
//
// DB table: system_logs
// Columns:
//   id BIGINT UNSIGNED PK AI
//   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
//   user_id INT NULL
//   actor_email VARCHAR(255) NULL
//   actor_role VARCHAR(32) NULL
//   ip_address VARCHAR(64) NULL
//   action VARCHAR(100) NOT NULL
//   target_type VARCHAR(64) NULL
//   target_id VARCHAR(64) NULL   <-- no longer displayed/filtered in UI
//   details TEXT NULL

// -------------- Reusable helpers (NO auth/db required for inclusion) --------------

// Only enforce admin gate if accessed directly in the browser
$is_direct_request = (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($is_direct_request) {
  session_start();
  $role = strtolower(trim((string)($_SESSION['role'] ?? ($USER_ROLE ?? ''))));
  if ($role !== 'admin') {
    require_once __DIR__ . '/access_denied.php';
    exit;
  }
}

if (!function_exists('ppf_client_ip')) {
  function ppf_client_ip(): string {
    // 1) The immediate peer that connected to PHP
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    // Helpers
    $is_private = function(string $ip): bool {
      if ($ip === '' || $ip === '::1') return true;
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return true;
      return false;
    };

    $first_forwarded = function(?string $xff): string {
      if (!$xff) return '';
      // take first non-empty token
      foreach (explode(',', $xff) as $p) {
        $p = trim($p);
        if ($p !== '') return $p;
      }
      return '';
    };

    // 2) If REMOTE_ADDR looks like a trusted proxy, read forward headers
    if ($is_private($remote)) {
      // Cloudflare first (exact real client header)
      if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) &&
          filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
      }

      // Generic reverse-proxy headers (IIS ARR / Nginx / Traefik)
      $xff = $first_forwarded($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
      if ($xff && filter_var($xff, FILTER_VALIDATE_IP)) return $xff;

      if (!empty($_SERVER['HTTP_X_REAL_IP']) &&
          filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_X_REAL_IP'];
      }
    }

    // 3) Fallback
    if ($remote === '' || $remote === '::1') return '127.0.0.1';
    return $remote;
  }
}

if (!function_exists('ppf_ensure_system_logs_table')) {
  function ppf_ensure_system_logs_table(mysqli $conn): void {
    // Creates the table if absent (idempotent)
    @$conn->query("
      CREATE TABLE IF NOT EXISTS system_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_id INT NULL,
        actor_email VARCHAR(255) NULL,
        actor_role VARCHAR(32) NULL,
        ip_address VARCHAR(64) NULL,
        action VARCHAR(100) NOT NULL,
        target_type VARCHAR(64) NULL,
        target_id VARCHAR(64) NULL,
        details TEXT NULL,
        INDEX (created_at),
        INDEX (action),
        INDEX (user_id),
        INDEX (actor_role),
        INDEX (target_type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
  }
}

if (!function_exists('ppf_log')) {
  /**
   * Write a structured log entry into system_logs.
   * Any of $actor_id / $actor_email / $actor_role you pass as null
   * will be auto-filled from the current session if available.
   */
  function ppf_log(
    mysqli $conn,
    ?int $actor_id,
    ?string $actor_email,
    ?string $actor_role,
    string $action,
    ?string $target_type = null,
    ?string $target_id = null,
    ?string $details = null
  ): void {
    static $checked = false;
    if (!$checked) { ppf_ensure_system_logs_table($conn); $checked = true; }

    // Auto-fill from session if not provided
    if ($actor_id === null   && isset($_SESSION['user_id']))  { $actor_id    = (int)$_SESSION['user_id']; }
    if ($actor_email === null&& isset($_SESSION['email']))    { $actor_email = (string)$_SESSION['email']; }
    if ($actor_role === null && isset($_SESSION['role']))     { $actor_role  = (string)$_SESSION['role']; }

    $ip = ppf_client_ip();

    $sql = "INSERT INTO system_logs
      (user_id, actor_email, actor_role, ip_address, action, target_type, target_id, details)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    if ($stmt = $conn->prepare($sql)) {
      $stmt->bind_param(
        "isssssss",
        $actor_id,
        $actor_email,
        $actor_role,
        $ip,
        $action,
        $target_type,
        $target_id,
        $details
      );
      $stmt->execute();
      $stmt->close();
    }
  }
}

// If this file is merely included for logging, STOP here (no auth, no HTML).
$is_direct_request = (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (!$is_direct_request) { return; }

// -------------- Viewer below (admin-only). Only runs on direct access. --------------

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

// Avoid redeclare if helpers.php already provides this
if (!function_exists('fmt_when')) {
  function fmt_when(?string $ts): string { return $ts ? date('Y-m-d H:i:s', strtotime($ts)) : ''; }
}

// Filters
$userFilter   = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$roleFilter   = isset($_GET['role']) ? trim($_GET['role']) : '';
$emailFilter  = isset($_GET['email']) ? trim($_GET['email']) : '';
$actionFilter = isset($_GET['action']) ? trim($_GET['action']) : '';
$typeFilter   = isset($_GET['type']) ? trim($_GET['type']) : '';
$qFilter      = isset($_GET['q']) ? trim($_GET['q']) : '';
$startDate    = isset($_GET['start']) ? trim($_GET['start']) : '';
$endDate      = isset($_GET['end']) ? trim($_GET['end']) : '';

// Pagination
$perPage = max(10, (int)($_GET['per'] ?? 50));
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// WHERE builder (Target ID removed)
$where  = [];
$params = [];
$types  = '';

if ($userFilter > 0)       { $where[] = "user_id = ?";             $params[] = $userFilter;  $types .= 'i'; }
if ($roleFilter !== '')    { $where[] = "actor_role = ?";           $params[] = $roleFilter;  $types .= 's'; }
if ($emailFilter !== '')   { $where[] = "actor_email LIKE ?";       $params[] = "%$emailFilter%"; $types .= 's'; }
if ($actionFilter !== '')  { $where[] = "action = ?";               $params[] = $actionFilter; $types .= 's'; }
if ($typeFilter !== '')    { $where[] = "target_type = ?";          $params[] = $typeFilter;   $types .= 's'; }
if ($qFilter !== '')       { $where[] = "(details LIKE ? OR ip_address LIKE ? OR actor_email LIKE ?)"; $like = "%$qFilter%"; $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss'; }
if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) { $where[] = "created_at >= ?"; $params[] = $startDate . " 00:00:00"; $types .= 's'; }
if ($endDate !== ''   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   { $where[] = "created_at <= ?"; $params[] = $endDate   . " 23:59:59"; $types .= 's'; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Count
$total = 0;
$sqlCount = "SELECT COUNT(*) AS c FROM system_logs $whereSql";
if ($stmt = $conn->prepare($sqlCount)) {
  if ($types) { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $row = $res->fetch_assoc()) { $total = (int)$row['c']; }
  $stmt->close();
}
$pages = max(1, (int)ceil($total / $perPage));

// Data (Target ID removed from SELECT)
$sql = "SELECT id, created_at, user_id, actor_email, actor_role, ip_address, action, target_type, details
        FROM system_logs
        $whereSql
        ORDER BY id DESC
        LIMIT ? OFFSET ?";
$types2 = $types . 'ii';
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;

$rows = [];
if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param($types2, ...$params2);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; }
  $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>System Logs · Peter Pang Fit</title>
  <style>
    
    html,body{margin:0;padding:0;background: var(--page-canvas);
    color:var(--text);
      font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
    a{color:var(--brand);text-decoration:none}
    a:hover{text-decoration:underline}
    .topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;
      background:var(--panel);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}
    .brand{font-weight:800;font-size:20px;letter-spacing:.2px}
    .wrap{
      width:100%; max-width:100%; margin:24px auto; padding:0 var(--page-pad); box-sizing:border-box;
    }
    .card{background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:18px}
    .card h3{margin:0 0 10px 0;font-size:16px}
    .row{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
    .span-12{grid-column:span 12}
    .inline-input{width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;
      padding:8px;border-radius:8px;outline:none;box-sizing:border-box;font-size:14px}
    label{display:block;margin:2px 0 6px 0;color:#c3c9d4;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
    .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
      color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer;text-decoration:none}
    .btn.brand{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
    th{color:#c3c9d4;font-size:12px;text-transform:uppercase;letter-spacing:.3px;background:rgba(8,13,23,0.95);text-align:left}
    .muted{color:var(--muted)}
    .pager{display:flex;gap:8px;align-items:center;justify-content:flex-end}
    .filters{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}
    .span-2{grid-column:span 2}
    .span-3{grid-column:span 3}
    .span-4{grid-column:span 4}
  </style>
</head>
<body>

<main class="wrap">
  <div class="card">
    <h3>Filters</h3>
    <form method="get" class="filters" action="logs.php">
      <div class="span-2">
        <label for="user">User ID</label>
        <input class="inline-input" type="number" name="user" id="user" value="<?php echo h($userFilter ?: ''); ?>">
      </div>
      <div class="span-2">
        <label for="role">Role</label>
        <input class="inline-input" type="text" name="role" id="role" placeholder="admin/trainer/client" value="<?php echo h($roleFilter); ?>">
      </div>
      <div class="span-3">
        <label for="email">Email</label>
        <input class="inline-input" type="text" name="email" id="email" placeholder="contains..." value="<?php echo h($emailFilter); ?>">
      </div>
      <div class="span-3">
        <label for="action">Action</label>
        <input class="inline-input" type="text" name="action" id="action" placeholder="e.g. login_success" value="<?php echo h($actionFilter); ?>">
      </div>
      <div class="span-2">
        <label for="type">Target Type</label>
        <input class="inline-input" type="text" name="type" id="type" placeholder="user/invite/plan" value="<?php echo h($typeFilter); ?>">
      </div>
      <!-- Target ID filter removed -->
      <div class="span-2">
        <label for="start">Start</label>
        <input class="inline-input" type="date" name="start" id="start" value="<?php echo h($startDate); ?>">
      </div>
      <div class="span-2">
        <label for="end">End</label>
        <input class="inline-input" type="date" name="end" id="end" value="<?php echo h($endDate); ?>">
      </div>
      <div class="span-3">
        <label for="q">Search (details/IP/email)</label>
        <input class="inline-input" type="text" name="q" id="q" placeholder="contains..." value="<?php echo h($qFilter); ?>">
      </div>
      <div class="span-2">
        <label for="per">Per Page</label>
        <input class="inline-input" type="number" min="10" max="500" name="per" id="per" value="<?php echo h($perPage); ?>">
      </div>
      <div class="span-12" style="display:flex;gap:10px;margin-top:6px">
        <button class="btn brand" type="submit">Apply</button>
        <a class="btn" href="logs.php">Reset</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Results <span class="muted">(<?php echo (int)$total; ?> total)</span></h3>
    <div style="overflow:auto">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>When</th>
            <th>User ID</th>
            <th>Email</th>
            <th>Role</th>
            <th>IP</th>
            <th>Action</th>
            <th>Target Type</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted">No logs found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo h(fmt_when($r['created_at'])); ?></td>
              <td><?php echo h($r['user_id'] ?? ''); ?></td>
              <td><?php echo h($r['actor_email'] ?? ''); ?></td>
              <td><?php echo h($r['actor_role'] ?? ''); ?></td>
              <td class="muted"><?php echo h($r['ip_address'] ?? ''); ?></td>
              <td><?php echo h($r['action']); ?></td>
              <td><?php echo h($r['target_type'] ?? ''); ?></td>
              <td><?php echo nl2br(h($r['details'] ?? '')); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pager" style="margin-top:10px;display:flex;gap:8px;align-items:center;justify-content:flex-end">
      <?php if ($page > 1): ?>
        <a class="btn" href="<?php $qs = $_GET; $qs['page'] = $page - 1; echo 'logs.php?' . http_build_query($qs); ?>">&laquo; Prev</a>
      <?php endif; ?>
      <span class="muted">Page <?php echo (int)$page; ?> of <?php echo (int)$pages; ?></span>
      <?php if ($page < $pages): ?>
        <a class="btn" href="<?php $qs = $_GET; $qs['page'] = $page + 1; echo 'logs.php?' . http_build_query($qs); ?>">Next &raquo;</a>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>