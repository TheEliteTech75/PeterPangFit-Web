<?php
// categories.php — Create / Edit / Delete Categories + expand to show assigned exercises (trainer/admin only)
// Updated to support MANY-TO-MANY via exercise_categories + Edit modal with exercise checklist + filter.

// --- Start session BEFORE loading auth.php so $USER_ROLE is populated correctly ---
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_trainer_admin($role){
  return ppf_role_has_trainer_access($role);
}
if (!is_trainer_admin($USER_ROLE ?? null)) {
  require_once __DIR__ . '/access_denied.php';
  exit;
}

// -----------------------------------------------------------------------------
// CSRF
// -----------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$flash = null; $flash_type = 'ok';

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
if (!function_exists('column_exists')) {
  function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS cnt
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if (!$stmt = $conn->prepare($sql)) return false;
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return !empty($row) && (int)$row['cnt'] > 0;
  }
}

// -----------------------------------------------------------------------------
// AUTO-MIGRATE (safe, idempotent) — set to false if you prefer manual SQL
// -----------------------------------------------------------------------------
const PPF_AUTO_MIGRATE = true;

/*
Manual SQL (run once if you disable auto-migrate):

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL,
  INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exercise_categories (
  exercise_id INT NOT NULL,
  category_id INT NOT NULL,
  PRIMARY KEY (exercise_id, category_id),
  CONSTRAINT fk_ec_ex  FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ec_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_ec_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: legacy exercises.category_id (kept for compatibility):
-- INSERT IGNORE INTO exercise_categories (exercise_id, category_id)
--   SELECT id, category_id FROM exercises WHERE category_id IS NOT NULL;
*/

function ensure_category_schema(mysqli $conn): void {
  if (!PPF_AUTO_MIGRATE) return;

  // Create categories table if missing
  @$conn->query("
    CREATE TABLE IF NOT EXISTS categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      description TEXT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      created_by INT NULL,
      updated_at DATETIME NULL,
      updated_by INT NULL,
      INDEX (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Legacy single col (optional, noop if exists)
  if (!column_exists($conn, 'exercises', 'category_id')) {
    @$conn->query("ALTER TABLE exercises ADD COLUMN category_id INT NULL AFTER notes");
  }

  // Junction table
  @$conn->query("
    CREATE TABLE IF NOT EXISTS exercise_categories (
      exercise_id INT NOT NULL,
      category_id INT NOT NULL,
      PRIMARY KEY (exercise_id, category_id),
      CONSTRAINT fk_ec_ex  FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_ec_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE,
      INDEX idx_ec_cat (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Migrate legacy single category to junction (idempotent)
  @$conn->query("
    INSERT IGNORE INTO exercise_categories (exercise_id, category_id)
    SELECT id, category_id FROM exercises WHERE category_id IS NOT NULL
  ");
}
ensure_category_schema($conn);

// Optional: track columns on exercises (used only for expand view)
$EX_HAS_CREATED_AT = column_exists($conn, 'exercises', 'created_at');
$EX_HAS_UPDATED_AT = column_exists($conn, 'exercises', 'updated_at');

// -----------------------------------------------------------------------------
// Sync helpers (mirror of sync_exercise_categories but inverted: by category)
// -----------------------------------------------------------------------------
function sync_category_exercises(mysqli $conn, int $categoryId, array $exerciseIds): void {
  $categoryId = (int)$categoryId;
  $keep = array_values(array_unique(array_map('intval', array_filter($exerciseIds, fn($x)=> (int)$x > 0))));

  // Current set
  $existing = [];
  if ($res = $conn->query("SELECT exercise_id FROM exercise_categories WHERE category_id = {$categoryId}")) {
    while ($r = $res->fetch_assoc()) $existing[(int)$r['exercise_id']] = true;
  }

  // Insert new
  if ($keep) {
    $ins = $conn->prepare("INSERT IGNORE INTO exercise_categories (exercise_id, category_id) VALUES (?,?)");
    foreach ($keep as $eid) { $e = (int)$eid; $ins->bind_param("ii", $e, $categoryId); $ins->execute(); }
    $ins->close();
  }

  // Delete removed
  $toDelete = [];
  foreach ($existing as $eid => $_) {
    if (!in_array($eid, $keep, true)) $toDelete[] = (int)$eid;
  }
  if ($toDelete) {
    $in = implode(',', array_map('intval', $toDelete));
    $conn->query("DELETE FROM exercise_categories WHERE category_id={$categoryId} AND exercise_id IN ($in)");
  }

  // Optional legacy single-column "best effort": if an exercise has multiple cats, we leave the legacy col unchanged
  if (column_exists($conn, 'exercises', 'category_id')) {
    // clear legacy col for exercises removed from this category
    if (!empty($toDelete)) {
      $in = implode(',', array_map('intval', $toDelete));
      // only clear if legacy column equals THIS category (avoid clobbering other single-cat mapping)
      $conn->query("UPDATE exercises SET category_id=NULL WHERE id IN ($in) AND category_id={$categoryId}");
    }
    // for newly added, set legacy to this category if empty (first come wins; many-to-many is canonical)
    if (!empty($keep)) {
      $in = implode(',', array_map('intval', $keep));
      $conn->query("UPDATE exercises SET category_id={$categoryId} WHERE id IN ($in) AND (category_id IS NULL)");
    }
  }
}

// -----------------------------------------------------------------------------
// POST actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Invalid session. Please try again.'; $flash_type = 'err';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      // CREATE
      if ($action === 'create_category') {
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_desc'] ?? '');
        if ($name === '') throw new Exception('Category name is required.');
        $by = (int)($USER_ID ?? 0);
        $stmt = $conn->prepare("INSERT INTO categories (name, description, created_by) VALUES (?,?,?)");
        if (!$stmt) throw new Exception('Failed to prepare create.');
        $stmt->bind_param("ssi", $name, $desc, $by);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); throw new Exception('Failed to create. '.$err); }
        $newId = $stmt->insert_id;
        $stmt->close();
        ppf_log($conn, null, null, null, 'category_created', 'category', (string)$newId, json_encode(['name'=>$name]));
        header('Location: categories.php?created=1#cat-'.$newId); exit;
      }

      // EDIT (now also syncs exercise assignments)
      if ($action === 'edit_category') {
        $id   = (int)($_POST['cat_id'] ?? 0);
        $name = trim($_POST['cat_name'] ?? '');
        $desc = trim($_POST['cat_desc'] ?? '');
        $exerciseIds = array_map('intval', $_POST['exercise_ids'] ?? []); // NEW: many selection
        if ($id <= 0) throw new Exception('Invalid category.');
        if ($name === '') throw new Exception('Category name is required.');
        $by = (int)($USER_ID ?? 0);

        $stmt = $conn->prepare("UPDATE categories SET name=?, description=?, updated_at=NOW(), updated_by=? WHERE id=?");
        if (!$stmt) throw new Exception('Failed to prepare edit.');
        $stmt->bind_param("ssii", $name, $desc, $by, $id);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); throw new Exception('Failed to update. '.$err); }
        $stmt->close();

        // Sync exercises for this category
        sync_category_exercises($conn, $id, $exerciseIds);

        ppf_log($conn, null, null, null, 'category_edited', 'category', (string)$id, json_encode([
          'name'=>$name,
          'exercise_ids'=>array_values(array_unique($exerciseIds)),
        ]));
        header('Location: categories.php?updated=1#cat-'.$id); exit;
      }

      // DELETE
      if ($action === 'delete_category') {
        $id = (int)($_POST['cat_id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid category.');
        $conn->begin_transaction();
        // Remove mappings (FK cascade would handle on category delete, but explicit is fine)
        $stmt = $conn->prepare("DELETE FROM exercise_categories WHERE category_id = ?");
        if ($stmt) { $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close(); }
        // Legacy unassign (noop if you later drop legacy column)
        if (column_exists($conn, 'exercises', 'category_id')) {
          $stmt = $conn->prepare("UPDATE exercises SET category_id = NULL WHERE category_id = ?");
          if ($stmt) { $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close(); }
        }
        // Delete category
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        if (!$stmt) { $conn->rollback(); throw new Exception('Failed to prepare delete.'); }
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $conn->rollback(); throw new Exception('Failed to delete. '.$err); }
        $stmt->close();
        $conn->commit();
        ppf_log($conn, null, null, null, 'category_deleted', 'category', (string)$id, null);
        header('Location: categories.php?deleted=1'); exit;
      }

    } catch (Throwable $e) {
      $flash = $e->getMessage(); $flash_type = 'err';
    }
  }
}

// -----------------------------------------------------------------------------
// Load categories + counts + creator/editor names (counts via junction table)
// -----------------------------------------------------------------------------
$cats = [];
$sql = "
  SELECT
    c.id, c.name, c.description, c.created_at, c.created_by, c.updated_at, c.updated_by,
    COUNT(ec.exercise_id) AS ex_count
  FROM categories c
  LEFT JOIN exercise_categories ec ON ec.category_id = c.id
  GROUP BY c.id
  ORDER BY c.name ASC
";
$res = $conn->query($sql);
if ($res) { while ($r = $res->fetch_assoc()) $cats[] = $r; }

// Preload user names (creator/editor) in batch for efficiency
$userIds = [];
foreach ($cats as $c) {
  if (!empty($c['created_by'])) $userIds[(int)$c['created_by']] = true;
  if (!empty($c['updated_by'])) $userIds[(int)$c['updated_by']] = true;
}
$userMap = [];
if ($userIds) {
  $in = implode(',', array_map('intval', array_keys($userIds)));
  $qr = $conn->query("SELECT id, first_name, last_name, email FROM users WHERE id IN ($in)");
  if ($qr) {
    while ($u = $qr->fetch_assoc()) {
      $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: ($u['email'] ?? '—');
      $userMap[(int)$u['id']] = $nm;
    }
  }
}

// Load ALL exercises (for edit checklist)
$allExercises = [];
if ($re = $conn->query("SELECT id, name FROM exercises ORDER BY name ASC")) {
  while ($row = $re->fetch_assoc()) $allExercises[] = $row;
}

// Load exercises per category for the expand sections (via junction table) + for precheck data
$exByCat = [];   // catId => list of rows (for expand)
$exIdsByCat = []; // catId => [ids... ] (for edit precheck)
if ($cats) {
  $catIds = array_column($cats, 'id');
  if ($catIds) {
    $in = implode(',', array_map('intval', $catIds));
    $selEx = "
      SELECT e.id, e.name, ec.category_id,
             ".($EX_HAS_CREATED_AT ? "e.created_at" : "NULL AS created_at").",
             ".($EX_HAS_UPDATED_AT ? "e.updated_at" : "NULL AS updated_at")."
      FROM exercise_categories ec
      JOIN exercises e ON e.id = ec.exercise_id
      WHERE ec.category_id IN ($in)
      ORDER BY e.name ASC
    ";
    if ($r = $conn->query($selEx)) {
      while ($row = $r->fetch_assoc()) {
        $cid = (int)$row['category_id'];
        $exByCat[$cid][] = $row;
        $exIdsByCat[$cid][] = (int)$row['id'];
      }
    }
  }
}

require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';
require_once __DIR__ . '/ppf_subheader.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Categories · Peter Pang Fit</title>
<style>
  
  html,body{margin:0;padding:0;background: var(--page-canvas);
    color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}


  .btn{
    display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
    color:#f8fafc;padding:8px 12px;border-radius:10px;cursor:pointer;text-decoration:none
  }
  .btn:hover{filter:brightness(1.06)}
  .btn.brand{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
  .btn.warn{background:#2a1617;border-color:rgba(248,113,113,0.45);color:#f87171}
  .btn.small{padding:6px 10px;font-size:13px}

  .wrap{max-width:none;width:95%;margin:18px auto;padding:0 16px}
  .card{background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:14px}
  .muted{color:var(--muted)}
  .chip{display:inline-flex;align-items:center;gap:6px;background:var(--chip);border:1px solid var(--line);padding:3px 7px;border-radius:999px;font-size:12px;color:#c3c9d4}

  .flash{margin:16px auto 0 auto;max-width:none;width:calc(100% - 32px);padding:12px;border-radius:10px;border:1px solid;background:rgba(8,13,23,0.85)}
  .flash.ok{border-color:rgba(34,197,94,0.45);color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}

  table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:12px;overflow:hidden;border:1px solid var(--line)}
  th,td{padding:12px 14px;border-bottom:1px solid var(--line);vertical-align:top}
  th{background:rgba(8,13,23,0.95);text-align:left;color:#c3c9d4;font-size:13px;letter-spacing:.3px}
  tr:last-child td{border-bottom:none}

  .cat-row{cursor:pointer}
  .cat-row:hover{background:#141a25}
  .cat-row.expanded{background:#141a25; outline:2px solid var(--brand); outline-offset:-2px; transition: background .2s ease, outline-color .2s ease;}
  .cat-row.focused{outline:2px solid var(--brand); outline-offset:-2px; background:#141a25; transition: background .4s ease, outline-color .4s ease;}

  .expand{display:none;background:rgba(8,13,23,0.95)}
  .expand td{border-top:1px solid var(--line)}

  .row-actions{display:flex;gap:8px;flex-wrap:wrap}

  /* New: exercise rows inside category expand */
  .mini-ex-row{cursor:pointer}
  .mini-ex-row:hover{background:#141a25}

  /* NEW: exercises checklist in Edit modal */
  .checkgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px}
  .check{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px}
  .check:hover{background:#101725}
  .check input{accent-color:#38bdf8}
  .table-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px}
  .table-tools__search{flex:1 1 260px;max-width:420px}
  .table-tools__search input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text)}
  .sort-btn{appearance:none;-webkit-appearance:none;-moz-appearance:none;background:none;background-color:transparent;border:none;border-radius:0;box-shadow:none;padding:0;margin:0;display:flex;align-items:center;gap:6px;justify-content:flex-start;width:100%;cursor:pointer;padding-right:18px;color:inherit;font:inherit;text-align:left}
  .sort-btn:focus{outline:none}
  .sort-btn::-moz-focus-inner{border:0;padding:0;margin:0}
  .sort-btn:hover .sort-indicator{opacity:0.8}
  .sort-btn:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
  .sort-indicator{font-size:11px;opacity:0.45;transition:opacity .2s ease}
  .sort-btn[data-state="asc"] .sort-indicator::before{content:'▲'}
  .sort-btn[data-state="desc"] .sort-indicator::before{content:'▼'}
  .sort-btn[data-state="off"] .sort-indicator::before{content:''}
  .sort-btn[data-state="asc"] .sort-indicator,
  .sort-btn[data-state="desc"] .sort-indicator{opacity:0.8}
  .col-resize-handle{position:absolute;top:0;right:-3px;width:8px;height:100%;cursor:col-resize}
  .col-resize-handle::after{content:'';position:absolute;top:0;bottom:0;left:3px;width:2px;background:rgba(148,163,184,0.2)}
</style>
</head>
<body>

<main class="wrap">

  <?php
  ppf_subheader([
    'title' => 'Categories',
    'subtitle' => 'Organize exercises by category',
    'actions' => function (): void {
      ?>
      <div class="btnset">
        <a class="btn" href="exercises.php">Exercises</a>
        <button class="btn brand" type="button" id="btnCreate">Add Category</button>
      </div>
      <?php
    },
  ]);
  ?>

  <?php if ($flash): ?>
    <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>"><?php echo h($flash); ?></div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin:6px 0 12px 0">Categories</h2>
    <div class="table-tools">
      <div class="table-tools__search">
        <input type="search" class="input search-input" id="categorySearch" placeholder="Search categories..." autocomplete="off">
      </div>
    </div>
    <div class="table-wrapper">
    <table id="categoriesTable">
      <colgroup>
        <col style="width:110px">
        <col style="min-width:220px">
        <col style="min-width:260px">
        <col style="min-width:200px">
        <col style="min-width:200px">
        <col style="min-width:200px">
        <col style="min-width:200px">
        <col style="width:160px">
        <col style="min-width:160px">
      </colgroup>
      <thead>
        <tr>
          <th data-sort-key="id"><button type="button" class="sort-btn" data-sort-key="id" data-state="off">Cat ID<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="name"><button type="button" class="sort-btn" data-sort-key="name" data-state="off">Name<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="description"><button type="button" class="sort-btn" data-sort-key="description" data-state="off">Description<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="created"><button type="button" class="sort-btn" data-sort-key="created" data-state="off">Created<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="created-by"><button type="button" class="sort-btn" data-sort-key="created-by" data-state="off">Created By<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="edited"><button type="button" class="sort-btn" data-sort-key="edited" data-state="off">Edited<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="edited-by"><button type="button" class="sort-btn" data-sort-key="edited-by" data-state="off">Edited By<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="exercises"><button type="button" class="sort-btn" data-sort-key="exercises" data-state="off"># Exercises Assigned<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$cats): ?>
        <tr><td colspan="9" class="muted">No categories yet.</td></tr>
      <?php else: foreach ($cats as $c):
        $cid = (int)$c['id'];
        $creator = !empty($c['created_by']) && isset($userMap[(int)$c['created_by']]) ? $userMap[(int)$c['created_by']] : '—';
        $editor  = !empty($c['updated_by']) && isset($userMap[(int)$c['updated_by']]) ? $userMap[(int)$c['updated_by']] : '—';
        $preIds  = $exIdsByCat[$cid] ?? [];
        $dataExIds = implode(',', array_map('intval', $preIds));
        $sortName = strtolower($c['name'] ?? '');
        $sortDescription = strtolower(strip_tags($c['description'] ?? ''));
        $sortCreated = !empty($c['created_at']) ? strtotime($c['created_at']) : '';
        $sortEdited = !empty($c['updated_at']) ? strtotime($c['updated_at']) : '';
        $sortCreator = strtolower($creator ?? '');
        $sortEditor = strtolower($editor ?? '');
        $sortExercises = (int)($c['ex_count'] ?? 0);
      ?>
        <tr
          class="cat-row"
          data-cat="<?php echo $cid; ?>"
          id="cat-<?php echo $cid; ?>"
          data-sort-id="<?php echo $cid; ?>"
          data-sort-name="<?php echo h($sortName); ?>"
          data-sort-description="<?php echo h($sortDescription); ?>"
          data-sort-created="<?php echo h($sortCreated); ?>"
          data-sort-created-by="<?php echo h($sortCreator); ?>"
          data-sort-edited="<?php echo h($sortEdited); ?>"
          data-sort-edited-by="<?php echo h($sortEditor); ?>"
          data-sort-exercises="<?php echo $sortExercises; ?>"
        >
          <td><?php echo $cid; ?></td>
          <td><strong><?php echo h($c['name']); ?></strong></td>
          <td class="muted"><?php echo $c['description'] ? nl2br(h($c['description'])) : '—'; ?></td>
          <td class="muted"><?php echo $c['created_at'] ? h(date('M j, Y g:i A', strtotime($c['created_at']))) : '—'; ?></td>
          <td class="muted"><?php echo h($creator); ?></td>
          <td class="muted"><?php echo $c['updated_at'] ? h(date('M j, Y g:i A', strtotime($c['updated_at']))) : '—'; ?></td>
          <td class="muted"><?php echo h($editor); ?></td>
          <td><?php echo (int)$c['ex_count']; ?></td>
          <td class="row-actions" data-actions>
            <button class="btn small" type="button"
              data-edit
              data-cat-id="<?php echo $cid; ?>"
              data-cat-name="<?php echo h($c['name']); ?>"
              data-cat-desc="<?php echo h($c['description']); ?>"
              data-cat-ex-ids="<?php echo h($dataExIds); ?>"
            >Edit</button>
            <button class="btn small warn" type="button"
              data-delete
              data-cat-id="<?php echo $cid; ?>"
              data-cat-name="<?php echo h($c['name']); ?>"
            >Delete</button>
          </td>
        </tr>
        <tr class="expand" id="exp-<?php echo $cid; ?>">
          <td colspan="9">
            <div class="card">
              <h3 style="margin:0 0 10px 0;font-size:15px">Exercises in “<?php echo h($c['name']); ?>”</h3>
              <?php
                $list = $exByCat[$cid] ?? [];
                if (!$list) {
                  echo '<div class="muted">No exercises are assigned to this category yet.</div>';
                } else {
                  echo '<table style="width:100%;border-collapse:collapse;margin-top:6px">';
                  echo '<thead><tr>';
                  echo '<th style="background:rgba(8,13,23,0.95)">Ex ID</th>';
                  echo '<th style="background:rgba(8,13,23,0.95)">Exercise</th>';
                  echo '<th style="background:rgba(8,13,23,0.95)">Created</th>';
                  echo '<th style="background:rgba(8,13,23,0.95)">Edited</th>';
                  echo '</tr></thead><tbody>';
                  foreach ($list as $ex) {
                    $eid = (int)$ex['id'];
                    echo '<tr class="mini-ex-row" data-ex-id="'.$eid.'">';
                    echo '<td>'.$eid.'</td>';
                    echo '<td><strong>'.h($ex['name']).'</strong></td>';
                    echo '<td class="muted">'.($ex['created_at'] ? h(date('M j, Y g:i A', strtotime($ex['created_at']))) : '—').'</td>';
                    echo '<td class="muted">'.($ex['updated_at'] ? h(date('M j, Y g:i A', strtotime($ex['updated_at']))) : '—').'</td>';
                    echo '</tr>';
                  }
                  echo '</tbody></table>';
                }
              ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</main>

<!-- Backdrops -->
<div class="backdrop" id="bdCreate" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000"></div>
<div class="backdrop" id="bdEdit" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000"></div>
<div class="backdrop" id="bdDelete" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000"></div>

<!-- CREATE CATEGORY MODAL -->
<div class="modal" id="mdCreate" role="dialog" aria-modal="true" aria-labelledby="ccTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(720px,94vw);background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="ccTitle" style="margin:0 0 10px 0;font-size:16px">Create Category</h3>
  <form method="post" id="ccForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="create_category">
    <div class="row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
      <div style="grid-column:span 12">
        <label class="muted" for="cc_name">Category Name</label>
        <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="cc_name" name="cat_name" type="text" required>
      </div>
      <div style="grid-column:span 12">
        <label class="muted" for="cc_desc">Description</label>
        <textarea style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px;min-height:120px" id="cc_desc" name="cat_desc"></textarea>
      </div>
    </div>
    <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
      <button class="btn" type="button" data-close="#mdCreate" data-backdrop="#bdCreate">Cancel</button>
      <button class="btn brand" type="submit">Create</button>
    </div>
  </form>
</div>

<!-- EDIT CATEGORY MODAL (with exercise checklist + filter) -->
<div class="modal" id="mdEdit" role="dialog" aria-modal="true" aria-labelledby="ecTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(820px,96vw);background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="ecTitle" style="margin:0 0 10px 0;font-size:16px">Edit Category</h3>
  <form method="post" id="ecForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="edit_category">
    <input type="hidden" id="ec_id" name="cat_id" value="">
    <div class="row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
      <div style="grid-column:span 6">
        <label class="muted" for="ec_name">Category Name</label>
        <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ec_name" name="cat_name" type="text" required>
      </div>
      <div style="grid-column:span 6">
        <label class="muted" for="ec_desc">Description</label>
        <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ec_desc" name="cat_desc" type="text">
      </div>

      <!-- NEW: Exercises checklist with filter -->
      <div style="grid-column:span 12; margin-top:6px">
        <label class="muted" for="ec_ex_filter">Exercises (check to assign)</label>
        <input id="ec_ex_filter" type="text" placeholder="Filter by name or ID..."
               style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:8px 10px;border-radius:8px;margin:6px 0">
        <div id="ec_ex_box" class="checkgrid" style="max-height:290px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:8px;background:rgba(8,13,23,0.95)">
          <?php foreach ($allExercises as $ex): ?>
            <label class="check" data-label="<?php echo h(strtolower($ex['name']).' '.$ex['id']); ?>">
              <input type="checkbox" name="exercise_ids[]" value="<?php echo (int)$ex['id']; ?>">
              <span><?php echo h($ex['name']); ?> <span class="muted">#<?php echo (int)$ex['id']; ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="muted" style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap">
          <a href="#" id="ec_clear_all">Clear all</a>
          <span>Tip: type part of a name or an ID to filter quickly.</span>
        </div>
      </div>
    </div>
    <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
      <button class="btn" type="button" data-close="#mdEdit" data-backdrop="#bdEdit">Cancel</button>
      <button class="btn brand" type="submit">Save Changes</button>
    </div>
  </form>
</div>

<!-- DELETE CATEGORY MODAL -->
<div class="modal" id="mdDelete" role="dialog" aria-modal="true" aria-labelledby="dcTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(560px,94vw);background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="dcTitle" style="margin:0 0 10px 0;font-size:16px">Delete Category</h3>
  <form method="post" id="dcForm" onsubmit="return confirm('Delete this category? Exercises will be unassigned.');">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="delete_category">
    <input type="hidden" id="dc_id" name="cat_id" value="">
    <div class="muted" id="dc_text" style="margin-bottom:10px"></div>
    <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
      <button class="btn" type="button" data-close="#mdDelete" data-backdrop="#bdDelete">Cancel</button>
      <button class="btn warn" type="submit">Delete</button>
    </div>
  </form>
</div>

<script src="table_enhancements.js"></script>
<script>
(function(){
  const categorySearchInput = document.getElementById('categorySearch');
  ppfEnhanceTable('#categoriesTable', {
    rowSelector: 'tbody tr.cat-row',
    searchInput: categorySearchInput,
    sortTypes: {
      id: 'number',
      created: 'number',
      edited: 'number',
      exercises: 'number'
    },
    noMatchesText: 'No matching categories.'
  });

  // Row expand/collapse
  document.querySelectorAll('.cat-row').forEach(tr=>{
    tr.addEventListener('click', (e)=>{
      if (e.target.closest('[data-actions]')) return;
      const cid = tr.getAttribute('data-cat');
      const exp = document.getElementById('exp-'+cid);
      if (!exp) return;
      const isOpen = (exp.style.display === 'table-row');
      exp.style.display = isOpen ? 'none' : 'table-row';
      tr.classList.toggle('expanded', !isOpen);
    });
  });

  // Modal helpers
  function openModal(modalSel, bdSel){
    const m = document.querySelector(modalSel), b = document.querySelector(bdSel);
    if (!m || !b) return;
    m.style.display='block'; b.style.display='block'; document.body.style.overflow='hidden';
  }
  function closeModal(modalSel, bdSel){
    const m = document.querySelector(modalSel), b = document.querySelector(bdSel);
    if (!m || !b) return;
    m.style.display='none'; b.style.display='none'; document.body.style.overflow='';
  }
  document.querySelectorAll('[data-close]').forEach(btn=>{
    btn.addEventListener('click', ()=> closeModal(btn.getAttribute('data-close'), btn.getAttribute('data-backdrop')));
  });

  // Open create
  document.getElementById('btnCreate')?.addEventListener('click', ()=> openModal('#mdCreate','#bdCreate'));

  // --------- Edit (pre-fill + pre-check exercises) ----------
  function precheckExercises(container, ids){
    if (!container) return;
    const set = new Set(ids.map(Number));
    container.querySelectorAll('input[type="checkbox"][name="exercise_ids[]"]').forEach(cb=>{
      cb.checked = set.has(parseInt(cb.value, 10));
    });
  }

  // Filter helper
  function bindFilter(input, container){
    if (!input || !container) return;
    input.addEventListener('input', ()=>{
      const q = input.value.trim().toLowerCase();
      container.querySelectorAll('.check').forEach(row=>{
        // Use prebuilt data-label (name + id)
        const label = (row.getAttribute('data-label') || row.textContent || '').toLowerCase();
        row.style.display = (!q || label.includes(q)) ? '' : 'none';
      });
    });
  }
  bindFilter(document.getElementById('ec_ex_filter'), document.getElementById('ec_ex_box'));

  // Clear all link
  document.getElementById('ec_clear_all')?.addEventListener('click', (e)=>{
    e.preventDefault();
    const box = document.getElementById('ec_ex_box');
    if (!box) return;
    box.querySelectorAll('input[type="checkbox"][name="exercise_ids[]"]').forEach(cb=> cb.checked = false);
  });

  // Wire Edit buttons
  document.querySelectorAll('[data-edit]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id     = btn.getAttribute('data-cat-id');
      const name   = btn.getAttribute('data-cat-name') || '';
      const desc   = btn.getAttribute('data-cat-desc') || '';
      const exCsv  = btn.getAttribute('data-cat-ex-ids') || '';
      const chosen = exCsv ? exCsv.split(',').map(x=>parseInt(x,10)).filter(x=>!isNaN(x)) : [];

      document.getElementById('ec_id').value = id;
      document.getElementById('ec_name').value = name;
      document.getElementById('ec_desc').value = desc;

      // Pre-check exercises
      precheckExercises(document.getElementById('ec_ex_box'), chosen);

      // Reset & focus filter
      const filter = document.getElementById('ec_ex_filter');
      if (filter) { filter.value=''; filter.dispatchEvent(new Event('input')); setTimeout(()=>filter.focus(), 50); }

      openModal('#mdEdit','#bdEdit');
    });
  });

  // Delete
  document.querySelectorAll('[data-delete]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id   = btn.getAttribute('data-cat-id');
      const name = btn.getAttribute('data-cat-name') || ('Category #'+id);
      document.getElementById('dc_id').value = id;
      document.getElementById('dc_text').textContent = `Delete "${name}"? All exercises in this category will be unassigned.`;
      openModal('#mdDelete','#bdDelete');
    });
  });

  // Click an exercise row -> go to exercises.php and focus/expand that exercise
  document.addEventListener('click', (e)=>{
    const row = e.target.closest('.mini-ex-row');
    if (!row) return;
    const exId = row.getAttribute('data-ex-id');
    if (!exId) return;
    location.href = `exercises.php?focus_exercise=${encodeURIComponent(exId)}#ex-${encodeURIComponent(exId)}`;
  });

  // Deep linking: ?focus_category=ID or #cat-ID
  (function(){
    const params = new URLSearchParams(location.search);
    let targetId = params.get('focus_category');
    if (!targetId && location.hash && /^#cat-(\d+)$/.test(location.hash)) {
      targetId = location.hash.slice(5);
    }
    if (!targetId) return;
    const row = document.getElementById('cat-'+targetId);
    const exp = document.getElementById('exp-'+targetId);
    if (!row || !exp) return;
    exp.style.display='table-row';
    row.classList.add('expanded','focused');
    requestAnimationFrame(()=>{
      row.scrollIntoView({behavior:'smooth', block:'center'});
      setTimeout(()=> row.classList.remove('focused'), 2200);
    });
  })();
})();
</script>
</body>
</html>