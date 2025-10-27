<?php
// exercises.php — Create / Edit / Delete Exercises + Per-Exercise Media + Categories (trainer/admin only)
// Now supports MANY-TO-MANY exercise<->category via exercise_categories.

// --- Start session BEFORE loading auth.php so $USER_ROLE is populated correctly ---
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';

// Safe esc
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ppf_normalize_for_diff($value) {
  if ($value === '' || $value === null) return null;
  if (is_string($value)) {
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
  }
  if (is_numeric($value)) {
    return $value + 0;
  }
  if (is_array($value)) {
    return array_values($value);
  }
  return $value;
}

function ppf_changed_fields(array $before, array $after): array {
  $out = [];
  $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
  foreach ($keys as $key) {
    $b = ppf_normalize_for_diff($before[$key] ?? null);
    $a = ppf_normalize_for_diff($after[$key] ?? null);
    if ($b !== $a) {
      $out[$key] = ['from' => $b, 'to' => $a];
    }
  }
  return $out;
}

// Normalize role to avoid case/whitespace issues from DB/session
function is_trainer_admin($role){
  $r = ppf_role_key($role);
  return $r === 'trainer' || ppf_is_admin_role($role);
}

// Ensure column_exists exists (helpers.php already defines it; guard just in case)
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

// --- Role gate ---
if (!is_trainer_admin($USER_ROLE ?? null)) {
  require_once __DIR__ . '/access_denied.php';
  exit;
}

$EXERCISES_MEASUREMENT_SYSTEM = ppf_measurement_user_system();
$EXERCISES_MEASUREMENT_LABEL = $EXERCISES_MEASUREMENT_SYSTEM === 'metric' ? 'Metric (kg)' : 'Imperial (lbs)';
$EXERCISES_MEASUREMENT_JS = ppf_measurement_js_config();

// ----------------------------------------------------------------------------
// CSRF
// ----------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$flash = null; $flash_type = 'ok';

// ----------------------------------------------------------------------------
const PPF_AUTO_MIGRATE = true;

/*
Manual SQL (if you prefer to run yourself; otherwise auto-migrate runs best-effort):

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

-- New junction table for many-to-many:
CREATE TABLE IF NOT EXISTS exercise_categories (
  exercise_id INT NOT NULL,
  category_id INT NOT NULL,
  PRIMARY KEY (exercise_id, category_id),
  CONSTRAINT fk_ec_ex FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ec_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_ec_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional legacy column (kept for compatibility; no longer used by UI logic)
-- ALTER TABLE exercises ADD COLUMN category_id INT NULL AFTER notes;  -- if not already present
-- INSERT IGNORE INTO exercise_categories (exercise_id, category_id)
--   SELECT id, category_id FROM exercises WHERE category_id IS NOT NULL;
*/

function ensure_category_schema(mysqli $conn): void {
  if (!PPF_AUTO_MIGRATE) return;
  // categories table
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

  // Legacy single FK column remains (non-breaking), but not required:
  if (!column_exists($conn, 'exercises', 'category_id')) {
    // Keep best-effort; some installs don't want the legacy col at all
    @$conn->query("ALTER TABLE exercises ADD COLUMN category_id INT NULL AFTER notes");
  }

  // NEW: many-to-many junction
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

  // One-way migration from legacy exercises.category_id into junction (idempotent)
  @$conn->query("
    INSERT IGNORE INTO exercise_categories (exercise_id, category_id)
    SELECT id, category_id FROM exercises WHERE category_id IS NOT NULL
  ");
}
ensure_category_schema($conn);

function ensure_video_columns(mysqli $conn): void {
  if (!PPF_AUTO_MIGRATE) return;
  $cols = [
    'video_url'         => "ALTER TABLE `exercises` ADD COLUMN `video_url` VARCHAR(512) NULL AFTER `notes`",
    'video_poster_url'  => "ALTER TABLE `exercises` ADD COLUMN `video_poster_url` VARCHAR(512) NULL AFTER `video_url`",
    'video_duration_sec'=> "ALTER TABLE `exercises` ADD COLUMN `video_duration_sec` INT NULL AFTER `video_poster_url`",
    'video_autoplay'    => "ALTER TABLE `exercises` ADD COLUMN `video_autoplay` TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_duration_sec`",
    'video_loop'        => "ALTER TABLE `exercises` ADD COLUMN `video_loop` TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_autoplay`",
    'video_muted'       => "ALTER TABLE `exercises` ADD COLUMN `video_muted` TINYINT(1) NOT NULL DEFAULT 1 AFTER `video_loop`",
    'captions_vtt_url'  => "ALTER TABLE `exercises` ADD COLUMN `captions_vtt_url` VARCHAR(512) NULL AFTER `video_muted`",
    'updated_at'        => "ALTER TABLE `exercises` ADD COLUMN `updated_at` DATETIME NULL AFTER `created_by`",
    'updated_by'        => "ALTER TABLE `exercises` ADD COLUMN `updated_by` INT NULL AFTER `updated_at`",
  ];
  foreach ($cols as $col => $ddl) {
    if (!column_exists($conn, 'exercises', $col)) {
      @$conn->query($ddl); // best-effort
    }
  }
}
ensure_video_columns($conn);

// Detect optional columns on exercises
$HAS_CREATED_AT = column_exists($conn, 'exercises', 'created_at');
$HAS_CREATED_BY = column_exists($conn, 'exercises', 'created_by');
$HAS_UPDATED_AT = column_exists($conn, 'exercises', 'updated_at');
$HAS_UPDATED_BY = column_exists($conn, 'exercises', 'updated_by');

// ----------------------------------------------------------------------------
// Helpers: create a category inline (from Create/Edit Exercise forms)
// ----------------------------------------------------------------------------
function create_category_inline(mysqli $conn, int $userId, string $name, string $desc = ''): int {
  $stmt = $conn->prepare("INSERT INTO categories (name, description, created_by) VALUES (?,?,?)");
  if (!$stmt) throw new Exception('Failed to prepare new category.');
  $stmt->bind_param("ssi", $name, $desc, $userId);
  if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); throw new Exception('Failed to create category. '.$err); }
  $newId = $stmt->insert_id;
  $stmt->close();
  ppf_log($conn, null, null, null, 'category_created_inline', 'category', (string)$newId, json_encode(['name'=>$name]));
  return (int)$newId;
}

// Sync categories for an exercise (replace with provided IDs set)
function sync_exercise_categories(mysqli $conn, int $exerciseId, array $categoryIds): void {
  $exerciseId = (int)$exerciseId;
  $keep = array_values(array_unique(array_map('intval', array_filter($categoryIds, fn($x)=> (int)$x > 0))));
  // Fetch existing
  $existing = [];
  $res = $conn->query("SELECT category_id FROM exercise_categories WHERE exercise_id = {$exerciseId}");
  if ($res) { while ($r = $res->fetch_assoc()) $existing[(int)$r['category_id']] = true; }

  // Insert missing
  if ($keep) {
    $ins = $conn->prepare("INSERT IGNORE INTO exercise_categories (exercise_id, category_id) VALUES (?,?)");
    foreach ($keep as $cid) { $c = (int)$cid; $ins->bind_param("ii", $exerciseId, $c); $ins->execute(); }
    $ins->close();
  }

  // Delete removed
  $toDelete = [];
  foreach ($existing as $cid => $_) {
    if (!in_array($cid, $keep, true)) $toDelete[] = (int)$cid;
  }
  if ($toDelete) {
    $in = implode(',', array_map('intval', $toDelete));
    $conn->query("DELETE FROM exercise_categories WHERE exercise_id={$exerciseId} AND category_id IN ($in)");
  }

  // Optional: keep legacy single column roughly in sync
  if (column_exists($conn, 'exercises', 'category_id')) {
    if ($keep) {
      $first = (int)$keep[0];
      $stmt = $conn->prepare("UPDATE exercises SET category_id=? WHERE id=?");
      $stmt->bind_param("ii", $first, $exerciseId);
      $stmt->execute();
      $stmt->close();
    } else {
      $stmt = $conn->prepare("UPDATE exercises SET category_id=NULL WHERE id=?");
      $stmt->bind_param("i", $exerciseId);
      $stmt->execute();
      $stmt->close();
    }
  }
}

// ----------------------------------------------------------------------------
// POST actions (do BEFORE any output)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Invalid session. Please try again.'; $flash_type = 'err';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      // Extract possible categories fields (shared by create/edit)
      $selectedCatIds = array_map('intval', $_POST['category_ids'] ?? []); // many
      $newCatName     = trim($_POST['new_category_name'] ?? '');
      $newCatDesc     = trim($_POST['new_category_desc'] ?? '');

      if ($newCatName !== '') {
        $newId = create_category_inline($conn, (int)($USER_ID ?? 0), $newCatDesc === '' ? $newCatName : $newCatName, $newCatDesc);
        $selectedCatIds[] = $newId; // auto-select it
      }

      // CREATE EXERCISE
      if ($action === 'create_exercise_modal') {
        $name  = trim($_POST['ex_name'] ?? '');
        $notes = trim($_POST['ex_notes'] ?? ''); // field remains "notes" in DB; UI shows "Description"
        if ($name === '') throw new Exception('Exercise name is required.');

        $cols = ['name','notes'];
        $placeholders = ['?','?'];
        $types = 'ss';
        $params = [];
        $params[] = &$name;
        $params[] = &$notes;

        if ($HAS_CREATED_BY) {
          $by = (int)($USER_ID ?? 0);
          $cols[] = 'created_by';
          $placeholders[] = '?';
          $types .= 'i';
          $params[] = &$by;
        }
        if ($HAS_CREATED_AT) {
          $cols[] = 'created_at';
          $placeholders[] = 'NOW()';
        }

        $sql = "INSERT INTO exercises (".implode(',', $cols).") VALUES (".implode(',', $placeholders).")";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Failed to prepare create statement.');
        if ($types !== '') {
          $ok = $stmt->bind_param($types, ...$params);
          if (!$ok) { $stmt->close(); throw new Exception('Failed to bind parameters.'); }
        }
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); throw new Exception('Failed to create exercise. '.$err); }
        $newExId = $stmt->insert_id;
        $stmt->close();

        // Sync many-to-many categories
        sync_exercise_categories($conn, (int)$newExId, $selectedCatIds);

        $details = json_encode([
          'name' => $name,
          'notes' => $notes,
          'category_ids' => array_values(array_unique(array_map('intval', $selectedCatIds))),
          'created_by' => $USER_ID ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (function_exists('ppf_log')) {
          @ppf_log($conn, null, null, null, 'exercise_created', 'exercise', (string)$newExId, $details ?: '');
        }

        header('Location: exercises.php?created=1#ex-'.$newExId); exit;
      }

      // EDIT EXERCISE
      if ($action === 'edit_exercise_modal') {
        $id    = (int)$_POST['exercise_id'];
        $name  = trim($_POST['ex_name'] ?? '');
        $notes = trim($_POST['ex_notes'] ?? '');
        if ($id <= 0) throw new Exception('Invalid exercise.');
        if ($name === '') throw new Exception('Exercise name is required.');

        $before = ['name' => null, 'notes' => null];
        if ($stmt = $conn->prepare("SELECT name, notes FROM exercises WHERE id = ?")) {
          $stmt->bind_param("i", $id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $before['name'] = $row['name'] ?? null;
              $before['notes'] = $row['notes'] ?? null;
            }
          }
          $stmt->close();
        }
        $beforeCats = [];
        if ($stmt = $conn->prepare("SELECT category_id FROM exercise_categories WHERE exercise_id = ?")) {
          $stmt->bind_param("i", $id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
              $beforeCats[] = (int)$row['category_id'];
            }
          }
          $stmt->close();
        }

        $by = (int)($USER_ID ?? 0);
        if ($HAS_UPDATED_AT || $HAS_UPDATED_BY) {
          $sql = "UPDATE exercises SET name=?, notes=?";
          $types = "ss";
          $params = [$name, $notes];
          if ($HAS_UPDATED_BY) { $sql .= ", updated_by=?"; $types .= "i"; $params[] = $by; }
          if ($HAS_UPDATED_AT) { $sql .= ", updated_at=NOW()"; }
          $sql .= " WHERE id=?";
          $types .= "i";
          $params[] = $id;

          $stmt = $conn->prepare($sql);
          if (!$stmt) throw new Exception('Failed to prepare update.');
          $stmt->bind_param($types, ...$params);
        } else {
          $stmt = $conn->prepare("UPDATE exercises SET name=?, notes=? WHERE id=?");
          if (!$stmt) throw new Exception('Failed to prepare update.');
          $stmt->bind_param("ssi", $name, $notes, $id);
        }
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); throw new Exception('Failed to update exercise. '.$err); }
        $stmt->close();

        // Sync categories
        sync_exercise_categories($conn, $id, $selectedCatIds);

        $after = [
          'name' => $name,
          'notes' => $notes,
          'category_ids' => array_values(array_unique(array_map('intval', $selectedCatIds))),
        ];
        $changes = ppf_changed_fields(
          [
            'name' => $before['name'],
            'notes' => $before['notes'],
            'category_ids' => array_values(array_unique($beforeCats)),
          ],
          $after
        );
        $details = json_encode([
          'changes' => $changes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (function_exists('ppf_log')) {
          @ppf_log($conn, null, null, null, 'exercise_edited', 'exercise', (string)$id, $details ?: '');
        }

        header('Location: exercises.php?updated=1#ex-'.$id); exit;
      }

      // DELETE EXERCISE
      if ($action === 'delete_exercise_modal') {
        $id = (int)$_POST['exercise_id'];
        if ($id <= 0) throw new Exception('Invalid exercise.');
        $exercise_info = ['name' => null, 'notes' => null, 'category_ids' => [], 'plan_usage' => 0];
        if ($stmt = $conn->prepare("SELECT name, notes FROM exercises WHERE id = ?")) {
          $stmt->bind_param("i", $id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $exercise_info['name'] = $row['name'] ?? null;
              $exercise_info['notes'] = $row['notes'] ?? null;
            }
          }
          $stmt->close();
        }
        if ($stmt = $conn->prepare("SELECT category_id FROM exercise_categories WHERE exercise_id = ?")) {
          $stmt->bind_param("i", $id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
              $exercise_info['category_ids'][] = (int)$row['category_id'];
            }
          }
          $stmt->close();
        }
        if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM plan_exercises WHERE exercise_id = ?")) {
          $stmt->bind_param("i", $id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $exercise_info['plan_usage'] = (int)($row['c'] ?? 0);
            }
          }
          $stmt->close();
        }
        $conn->begin_transaction();
        // Remove from any plans first
        $stmt = $conn->prepare("DELETE FROM plan_exercises WHERE exercise_id = ?");
        if (!$stmt) { $conn->rollback(); throw new Exception('Failed to prepare plan cleanup.'); }
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        // Remove category mappings (FK would also handle this)
        $stmt = $conn->prepare("DELETE FROM exercise_categories WHERE exercise_id = ?");
        if ($stmt) { $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close(); }

        // Delete files on disk (best-effort)
        $mediaDir = __DIR__ . "/uploads/exercise_media/{$id}";
        if (is_dir($mediaDir)) {
          $files = @scandir($mediaDir) ?: [];
          foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($mediaDir . DIRECTORY_SEPARATOR . $f);
          }
          @rmdir($mediaDir);
        }

        // Delete exercise
        $stmt = $conn->prepare("DELETE FROM exercises WHERE id = ?");
        if (!$stmt) { $conn->rollback(); throw new Exception('Failed to prepare delete.'); }
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $conn->rollback(); throw new Exception('Failed to delete exercise. '.$err); }
        $stmt->close();

        $conn->commit();
        $details = json_encode([
          'name' => $exercise_info['name'],
          'notes' => $exercise_info['notes'],
          'category_ids' => array_values(array_unique($exercise_info['category_ids'])),
          'plan_usage' => $exercise_info['plan_usage'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (function_exists('ppf_log')) {
          @ppf_log($conn, null, null, null, 'exercise_deleted', 'exercise', (string)$id, $details ?: '');
        }
        header('Location: exercises.php?deleted=1'); exit;
      }

      // SAVE MEDIA TOGGLES
      if ($action === 'save_media_settings') {
        $id = (int)($_POST['exercise_id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid exercise.');
        $autoplay = isset($_POST['video_autoplay']) ? 1 : 0;
        $loop     = isset($_POST['video_loop']) ? 1 : 0;
        $muted    = isset($_POST['video_muted']) ? 1 : 0;

        if ($HAS_UPDATED_AT || $HAS_UPDATED_BY) {
          $by = (int)($USER_ID ?? 0);
          $sql = "UPDATE exercises SET video_autoplay=?, video_loop=?, video_muted=?";
          $types = "iii";
          $params = [$autoplay, $loop, $muted];

          if ($HAS_UPDATED_BY) { $sql .= ", updated_by=?"; $types .= "i"; $params[] = $by; }
          if ($HAS_UPDATED_AT) { $sql .= ", updated_at=NOW()"; }

          $sql .= " WHERE id=?";
          $types .= "i";
          $params[] = $id;

          $stmt = $conn->prepare($sql);
          if (!$stmt) throw new Exception('Failed to prepare media settings update.');
          $stmt->bind_param($types, ...$params);
        } else {
          $stmt = $conn->prepare("UPDATE exercises SET video_autoplay=?, video_loop=?, video_muted=? WHERE id=?");
          if (!$stmt) throw new Exception('Failed to prepare media settings update.');
          $stmt->bind_param("iiii", $autoplay, $loop, $muted, $id);
        }
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); throw new Exception('Failed to update media settings. '.$err); }
        $stmt->close();

        ppf_log($conn, null, null, null, 'exercise_media_settings_saved', 'exercise', (string)$id, json_encode([
          'autoplay' => $autoplay,
          'loop' => $loop,
          'muted' => $muted,
        ]));
        header('Location: exercises.php?saved_media=1#ex-'.$id); exit;
      }

    } catch (Throwable $e) {
      $flash = $e->getMessage(); $flash_type = 'err';
    }
  }
}

// ----------------------------------------------------------------------------
// Load categories for selects
// ----------------------------------------------------------------------------
$categories = [];
if ($res = $conn->query("SELECT id, name FROM categories ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) $categories[] = $r;
}

// ----------------------------------------------------------------------------
// Load data for page (without single-category join; we’ll map many-to-many)
// ----------------------------------------------------------------------------
$exercises = [];
$sel = "
  SELECT e.id, e.name, e.notes,
";
if ($HAS_CREATED_AT) $sel .= "       e.created_at, ";
else                 $sel .= "       NULL AS created_at, ";
if ($HAS_CREATED_BY) $sel .= "       e.created_by, ";
else                 $sel .= "       NULL AS created_by, ";
if ($HAS_UPDATED_AT) $sel .= "       e.updated_at, ";
else                 $sel .= "       NULL AS updated_at, ";
if ($HAS_UPDATED_BY) $sel .= "       e.updated_by, ";
else                 $sel .= "       NULL AS updated_by, ";
$sel .= "
         e.video_url, e.video_poster_url, e.video_duration_sec,
         e.video_autoplay, e.video_loop, e.video_muted, e.captions_vtt_url,
         COUNT(DISTINCT pe.plan_id) AS used_in_plans
  FROM exercises e
  LEFT JOIN plan_exercises pe ON pe.exercise_id = e.id
  GROUP BY e.id, e.name, e.notes, created_at, created_by, updated_at, updated_by,
           e.video_url, e.video_poster_url, e.video_duration_sec,
           e.video_autoplay, e.video_loop, e.video_muted, e.captions_vtt_url
  ORDER BY e.name ASC
";
$res = $conn->query($sel);
if ($res) { while ($r = $res->fetch_assoc()) $exercises[] = $r; }

// Load categories per exercise for chips + edit preselect
$exCatMap = []; // exercise_id => [ ['id'=>..., 'name'=>...], ... ]
if ($exercises) {
  $ids = implode(',', array_map('intval', array_column($exercises, 'id')));
  if ($ids !== '') {
    $sql = "
      SELECT ec.exercise_id, c.id AS cat_id, c.name AS cat_name
      FROM exercise_categories ec
      JOIN categories c ON c.id = ec.category_id
      WHERE ec.exercise_id IN ($ids)
      ORDER BY c.name ASC
    ";
    if ($rr = $conn->query($sql)) {
      while ($row = $rr->fetch_assoc()) {
        $exId = (int)$row['exercise_id'];
        $exCatMap[$exId][] = ['id'=>(int)$row['cat_id'], 'name'=>$row['cat_name']];
      }
    }
  }
}

// Expansion data (plans)
$exPlans = [];
$planSummaries = [];

if ($exercises) {
  $exerciseIds = array_column($exercises, 'id');
  $inEx = implode(',', array_map('intval', $exerciseIds));

  if ($inEx !== '') {
    $sqlMap = "SELECT pe.exercise_id, pe.plan_id FROM plan_exercises pe WHERE pe.exercise_id IN ($inEx)";
    if ($res = $conn->query($sqlMap)) {
      while ($r = $res->fetch_assoc()) {
        $exPlans[(int)$r['exercise_id']][] = (int)$r['plan_id'];
      }
    }

    $sqlSum = "
      SELECT 
        p.id, 
        p.name,
        p.created_at,
        p.created_by,
        p.updated_at,
        p.updated_by,
        COUNT(DISTINCT pe2.exercise_id) AS exercise_count,
        COUNT(DISTINCT up.user_id)      AS assigned_count
      FROM workout_plans p
      LEFT JOIN plan_exercises pe2 ON pe2.plan_id = p.id
      LEFT JOIN user_plans up      ON up.plan_id = p.id
      GROUP BY p.id, p.name
    ";
    if ($res = $conn->query($sqlSum)) {
      while ($r = $res->fetch_assoc()) {
        $creator = '—';
        $editor  = '—';
        if (!empty($r['created_by'])) {
          $q = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
          $q->bind_param("i", $r['created_by']);
          $q->execute();
          $u = $q->get_result()->fetch_assoc();
          if ($u) {
            $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
            $creator = $nm !== '' ? $nm : ($u['email'] ?? '—');
          }
          $q->close();
        }
        if (!empty($r['updated_by'])) {
          $q = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
          $q->bind_param("i", $r['updated_by']);
          $q->execute();
          $u = $q->get_result()->fetch_assoc();
          if ($u) {
            $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
            $editor = $nm !== '' ? $nm : ($u['email'] ?? '—');
          }
          $q->close();
        }
        $planSummaries[(int)$r['id']] = [
          'name'            => $r['name'],
          'exercise_count'  => (int)$r['exercise_count'],
          'assigned_count'  => (int)$r['assigned_count'],
          'created_at'      => $r['created_at'] ?? null,
          'created_by_name' => $creator,
          'updated_at'      => $r['updated_at'] ?? null,
          'updated_by_name' => $editor,
        ];
      }
    }
  }
}

require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Exercises · Peter Pang Fit</title>
<style>
  
  html,body{margin:0;padding:0;background: var(--page-canvas);
    color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}

  .subheader{
    position: sticky; top: 0; z-index: 40;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 10px 12px;
    margin-bottom: 14px;
    display:flex; align-items:center; justify-content:space-between; gap:12px;
  }
  .subheader .left{display:flex;align-items:center;gap:10px}
  .brand{font-weight:800;font-size:20px;letter-spacing:.2px}
  .btnset{display:flex;gap:8px;flex-wrap:wrap}

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
  th{background:rgba(8,13,23,0.95);text-align:left;color:#c3c9d4;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
  tr:last-child td{border-bottom:none}

  .exercise-row{cursor:pointer}
  .exercise-row:hover{background:#141a25}
  .exercise-row.expanded{background:#141a25; outline:2px solid var(--brand); outline-offset:-2px; transition: background .2s ease, outline-color .2s ease;}
  .exercise-row.focused{outline:2px solid var(--brand); outline-offset:-2px; background:#141a25; transition: background .4s ease, outline-color .4s ease;}

  .expand{display:none;background:rgba(8,13,23,0.95)}
  .expand td{border-top:1px solid var(--line)}

  .row-actions{display:flex;gap:8px;flex-wrap:wrap}

  .media-card{display:grid;grid-template-columns:2fr 1fr;gap:12px}
  @media (max-width: 860px){ .media-card{grid-template-columns:1fr} }
  .media-16x9{position:relative;width:100%;padding-top:56.25%;background:rgba(8,13,23,0.95);border:1px solid var(--line);border-radius:12px;overflow:hidden}
  .media-16x9 > *{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
  .dropzone{display:flex;align-items:center;justify-content:center;min-height:120px;border:1px dashed var(--line);border-radius:10px;background:rgba(8,13,23,0.95);cursor:pointer;text-align:center;padding:10px}
  .dropzone.dragover{background:#101725;border-color:#38bdf8}
  .progress{height:6px;background:rgba(8,13,23,0.95);border:1px solid var(--line);border-radius:999px;overflow:hidden}
  .progress > div{height:100%;width:0%;background:#38bdf8}
  .fine{font-size:12px;color:#cbd5f5}
  .media-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .media-grid{display:grid;gap:8px}
  .thumb-mini{height:54px;width:96px;overflow:hidden;border-radius:6px;border:1px solid var(--line);background:rgba(8,13,23,0.95)}
  .thumb-mini img{height:100%;width:100%;object-fit:cover;display:block}
  @media (min-width:1400px){ .thumb-mini{height:68px;width:120px} }

  .table-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px}
  .table-tools__search{flex:1 1 260px;max-width:420px}
  .table-tools__search input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text)}
  .sort-btn{display:flex;align-items:center;gap:6px;justify-content:flex-start;width:100%;background:transparent;border:none;color:inherit;font:inherit;padding:0 18px 0 0;cursor:pointer;box-shadow:none;-webkit-appearance:none;appearance:none;border-radius:0}
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

  /* NEW: categories checklist styling */
  .checkgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px}
  .check{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px}
  .check:hover{background:#101725}
  .check input{accent-color:#38bdf8}
</style>
</head>
<body>

<?php if ($flash): ?>
  <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>"><?php echo h($flash); ?></div>
<?php endif; ?>

<div class="subheader">
  <div class="left">
    <div class="brand">Exercises</div>
    <span class="muted">Create and manage the exercise library</span>
    <span class="chip" style="font-size:11px;letter-spacing:.2px">Measurements: <?php echo h($EXERCISES_MEASUREMENT_LABEL); ?></span>
  </div>
  <div class="btnset">
    <a class="btn" href="dashboard.php">Back to Dashboard</a>
    <a class="btn" href="workout_plans.php">Workout Plans</a>
    <a class="btn" href="invites.php">Manage Invites</a>
    <a class="btn" href="categories.php">Categories</a>
    <button class="btn brand" type="button" id="btnCreateExercise">Add Exercise</button>
  </div>
</div>

<main class="wrap">

  <div class="card">
    <h2 style="margin:6px 0 12px 0">Exercises</h2>
    <div class="table-tools">
      <div class="table-tools__search">
        <input type="search" class="input search-input" id="exerciseSearch" placeholder="Search exercises..." autocomplete="off">
      </div>
    </div>
    <div class="table-wrapper">
    <table id="exercisesTable">
      <colgroup>
        <col style="width:110px">
        <col style="min-width:220px">
        <col style="min-width:260px">
        <col style="min-width:200px">
        <col style="min-width:160px">
        <col style="min-width:200px">
        <col style="min-width:180px">
        <col style="min-width:200px">
        <col style="min-width:180px">
        <col style="width:150px">
        <col style="min-width:160px">
      </colgroup>
      <thead>
        <tr>
          <th data-sort-key="id"><button type="button" class="sort-btn" data-sort-key="id" data-state="off">Ex ID<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="name"><button type="button" class="sort-btn" data-sort-key="name" data-state="off">Name<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="description"><button type="button" class="sort-btn" data-sort-key="description" data-state="off">Description<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="categories"><button type="button" class="sort-btn" data-sort-key="categories" data-state="off">Categories<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="media"><button type="button" class="sort-btn" data-sort-key="media" data-state="off">Media<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="created"><button type="button" class="sort-btn" data-sort-key="created" data-state="off">Created<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="created-by"><button type="button" class="sort-btn" data-sort-key="created-by" data-state="off">Created By<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="edited"><button type="button" class="sort-btn" data-sort-key="edited" data-state="off">Edited<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="edited-by"><button type="button" class="sort-btn" data-sort-key="edited-by" data-state="off">Edited By<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="plans"><button type="button" class="sort-btn" data-sort-key="plans" data-state="off">Used In # Plans<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$exercises): ?>
        <tr><td colspan="11" class="muted">No exercises yet.</td></tr>
      <?php else: foreach ($exercises as $ex):
        $eid = (int)$ex['id'];
        $createdAt = $ex['created_at'] ?? null;
        $createdBy = $ex['created_by'] ?? null;
        $creator = '—';
        if ($HAS_CREATED_BY && $createdBy) {
          $q = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
          $q->bind_param("i", $createdBy);
          $q->execute();
          $rs = $q->get_result();
          if ($u = $rs->fetch_assoc()) {
            $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
            $creator = $nm !== '' ? $nm : ($u['email'] ?? '—');
          }
          $q->close();
        }
        $updatedAt = $ex['updated_at'] ?? null;
        $updatedBy = $ex['updated_by'] ?? null;
        $editor = '—';
        if ($HAS_UPDATED_BY && $updatedBy) {
          $qq = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
          $qq->bind_param("i", $updatedBy);
          $qq->execute();
          $rr = $qq->get_result();
          if ($eu = $rr->fetch_assoc()) {
            $enm = trim(($eu['first_name'] ?? '').' '.($eu['last_name'] ?? ''));
            $editor = $enm !== '' ? $enm : ($eu['email'] ?? '—');
          }
          $qq->close();
        }

        $catsForEx = $exCatMap[$eid] ?? [];

        $hasVideo = !empty($ex['video_url']);
        $hasCaptions = !empty($ex['captions_vtt_url']);
        $poster = $ex['video_poster_url'] ?? '';
        $thumbHtml = $poster ? '<div class="thumb-mini"><img src="'.h($poster).'" alt="thumb"></div>' : '';
      ?>
        <?php
          $sortName = strtolower($ex['name'] ?? '');
          $sortDescription = strtolower(strip_tags($ex['notes'] ?? ''));
          $categoryNames = [];
          foreach ($catsForEx as $cCat) {
            $categoryNames[] = strtolower($cCat['name'] ?? '');
          }
          $sortCategories = implode(' ', $categoryNames);
          $sortCreated = $createdAt ? strtotime($createdAt) : '';
          $sortUpdated = $updatedAt ? strtotime($updatedAt) : '';
          $sortCreator = strtolower($creator ?? '');
          $sortEditor = strtolower($editor ?? '');
          $sortMedia = $hasVideo ? '1' : '0';
          $sortPlans = (int)($ex['plan_count'] ?? $ex['used_in_plans'] ?? 0);
        ?>
        <tr
          class="exercise-row"
          data-ex="<?php echo $eid; ?>"
          id="ex-<?php echo $eid; ?>"
          data-sort-id="<?php echo $eid; ?>"
          data-sort-name="<?php echo h($sortName); ?>"
          data-sort-description="<?php echo h($sortDescription); ?>"
          data-sort-categories="<?php echo h($sortCategories); ?>"
          data-sort-media="<?php echo h($sortMedia); ?>"
          data-sort-created="<?php echo h($sortCreated); ?>"
          data-sort-created-by="<?php echo h($sortCreator); ?>"
          data-sort-edited="<?php echo h($sortUpdated); ?>"
          data-sort-edited-by="<?php echo h($sortEditor); ?>"
          data-sort-plans="<?php echo $sortPlans; ?>"
        >
          <td><?php echo $eid; ?></td>
          <td><strong><?php echo h($ex['name']); ?></strong></td>
          <td class="muted"><?php echo $ex['notes'] ? nl2br(h($ex['notes'])) : '—'; ?></td>

          <!-- Categories chips -->
          <td>
            <?php if (!$catsForEx): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <?php foreach ($catsForEx as $c): ?>
                <a class="chip" href="categories.php?focus_category=<?php echo (int)$c['id']; ?>#cat-<?php echo (int)$c['id']; ?>"
                   title="Open category">
                   <?php echo h($c['name']); ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>

          <!-- Media -->
          <td title="<?php
              $tip = $hasVideo ? ('Video'.($ex['video_duration_sec'] ? ' • '.$ex['video_duration_sec'].'s' : '')) : 'No video';
              echo h($tip);
            ?>">
            <?php
              if ($hasVideo) {
                echo $thumbHtml ?: '<span class="chip">▶ Video'.($hasCaptions?' · CC':'').'</span>';
              } else {
                echo '<span class="muted">—</span>';
              }
            ?>
          </td>

          <td class="muted"><?php echo $createdAt ? h(date('M j, Y g:i A', strtotime($createdAt))) : '—'; ?></td>
          <td class="muted"><?php echo h($creator); ?></td>
          <td class="muted"><?php echo $updatedAt ? h(date('M j, Y g:i A', strtotime($updatedAt))) : '—'; ?></td>
          <td class="muted"><?php echo h($editor); ?></td>
          <td><?php echo (int)$ex['used_in_plans']; ?></td>
          <td class="row-actions" data-actions>
            <?php
              // Prepare data attribute with comma-separated cat IDs for this exercise
              $dataCatIds = implode(',', array_map(fn($x)=> (string)(int)$x['id'], $catsForEx));
            ?>
            <button class="btn small" type="button"
              data-edit
              data-ex-id="<?php echo $eid; ?>"
              data-ex-name="<?php echo h($ex['name']); ?>"
              data-ex-notes="<?php echo h($ex['notes']); ?>"
              data-ex-cat-ids="<?php echo h($dataCatIds); ?>"
            >Edit</button>
            <button class="btn small warn" type="button"
              data-delete
              data-ex-id="<?php echo $eid; ?>"
              data-ex-name="<?php echo h($ex['name']); ?>"
            >Delete</button>
          </td>
        </tr>

        <!-- Expansion -->
        <tr class="expand" id="exp-<?php echo $eid; ?>">
          <td colspan="11">
            <?php
              // Plans card
              $planIds = $exPlans[$eid] ?? [];
              echo '<div class="card" style="margin-bottom:10px">';
              echo '<h3 style="margin:0 0 10px 0;font-size:15px">Used In Plans</h3>';
              if (!$planIds) {
                echo '<div class="muted">This exercise is not used in any workout plans.</div>';
              } else {
                echo '<table style="width:100%;border-collapse:collapse;margin-top:6px">';
                echo '<thead><tr>';
                echo '<th style="background:rgba(8,13,23,0.95)">Plan ID</th>';
                echo '<th style="background:rgba(8,13,23,0.95)">Plan Name</th>';
                echo '<th style="background:rgba(8,13,23,0.95)">Created</th>';
                echo '<th style="background:rgba(8,13,23,0.95)">Created By</th>';
                echo '<th style="background:rgba(8,13,23,0.95)">Edited</th>';
                echo '<th style="background:rgba(8,13,23,0.95)">Edited By</th>';
                echo '<th style="background:rgba(8,13,23,0.95)"># Exercises</th>';
                echo '<th style="background:rgba(8,13,23,0.95)"># Clients</th>';
                echo '</tr></thead><tbody>';
                foreach ($planIds as $pid) {
                  $sum = $planSummaries[$pid] ?? null;
                  if (!$sum) continue;
                  echo '<tr class="mini-plan-row" data-plan-id="'.(int)$pid.'">';
                  echo '<td style="width:120px">'.(int)$pid.'</td>';
                  echo '<td><strong>'.h($sum['name']).'</strong></td>';
                  echo '<td class="muted">'.($sum['created_at'] ? h(date('M j, Y g:i A', strtotime($sum['created_at']))) : '—').'</td>';
                  echo '<td class="muted">'.h($sum['created_by_name']).'</td>';
                  echo '<td class="muted">'.($sum['updated_at'] ? h(date('M j, Y g:i A', strtotime($sum['updated_at']))) : '—').'</td>';
                  echo '<td class="muted">'.h($sum['updated_by_name']).'</td>';
                  echo '<td>'.(int)$sum['exercise_count'].'</td>';
                  echo '<td>'.(int)$sum['assigned_count'].'</td>';
                  echo '</tr>';
                }
                echo '</tbody></table>';
              }
              echo '</div>';

              // Media panel
              $videoUrl   = $ex['video_url'] ?? '';
              $posterUrl  = $ex['video_poster_url'] ?? '';
              $duration   = $ex['video_duration_sec'] ?? null;
              $autoplay   = (int)($ex['video_autoplay'] ?? 0);
              $loop       = (int)($ex['video_loop'] ?? 0);
              $muted      = (int)($ex['video_muted'] ?? 1);
              $captions   = $ex['captions_vtt_url'] ?? '';
            ?>

            <div class="card">
              <h3 style="margin:0 0 10px 0;font-size:15px">Media</h3>
              <div class="media-card">
                <div>
                  <div class="media-16x9" data-preview="<?php echo $eid; ?>">
                    <?php if ($videoUrl): ?>
                      <video
                        <?php echo $autoplay ? 'autoplay ' : ''; ?>
                        <?php echo $muted ? 'muted ' : ''; ?>
                        <?php echo $loop ? 'loop ' : ''; ?>
                        controls
                        preload="none"
                        <?php echo $posterUrl ? 'poster="'.h($posterUrl).'"' : ''; ?>
                      >
                        <source src="<?php echo h($videoUrl); ?>" type="video/mp4">
                        <?php if ($captions): ?>
                          <track kind="captions" label="English" srclang="en" src="<?php echo h($captions); ?>" default>
                        <?php endif; ?>
                      </video>
                    <?php else: ?>
                      <div class="muted" style="display:flex;align-items:center;justify-content:center">No media yet</div>
                    <?php endif; ?>
                  </div>
                  <div class="fine" style="margin-top:6px">
                    MP4 (H.264/AAC), max 200 MB. <?php if ($duration) echo 'Duration: '.(int)$duration.'s.'; ?>
                  </div>
                </div>
                <div>
                  <div class="dropzone" data-dz="<?php echo $eid; ?>">
                    <div>
                      <div><strong>Upload / Replace video</strong></div>
                      <div class="fine">Drag & drop or click to browse</div>
                    </div>
                  </div>
                  <div class="progress" style="margin-top:8px;display:none" id="prog-<?php echo $eid; ?>">
                    <div></div>
                  </div>

                  <div class="media-grid" style="margin-top:10px">
                    <label class="fine">Poster image (optional)</label>
                    <input class="input" type="file" accept="image/*" data-poster="<?php echo $eid; ?>">

                    <label class="fine">Captions (.vtt, optional)</label>
                    <input class="input" type="file" accept=".vtt,text/vtt" data-captions="<?php echo $eid; ?>">

                    <form method="post" class="media-grid" style="margin-top:6px">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="save_media_settings">
                      <input type="hidden" name="exercise_id" value="<?php echo $eid; ?>">

                      <div class="media-tools">
                        <label class="fine"><input type="checkbox" name="video_autoplay" <?php echo $autoplay ? 'checked' : ''; ?>> Autoplay (muted)</label>
                        <label class="fine"><input type="checkbox" name="video_loop" <?php echo $loop ? 'checked' : ''; ?>> Loop</label>
                        <label class="fine"><input type="checkbox" name="video_muted" <?php echo $muted ? 'checked' : ''; ?>> Muted</label>
                      </div>

                      <div class="media-tools">
                        <?php if ($videoUrl): ?>
                          <button class="btn small warn" type="button" data-remove-video="<?php echo $eid; ?>">Remove Video</button>
                        <?php endif; ?>
                        <?php if ($posterUrl): ?>
                          <button class="btn small warn" type="button" data-remove-poster="<?php echo $eid; ?>">Remove Poster</button>
                        <?php endif; ?>
                        <?php if ($captions): ?>
                          <button class="btn small warn" type="button" data-remove-captions="<?php echo $eid; ?>">Remove Captions</button>
                        <?php endif; ?>
                        <button class="btn small brand" type="submit">Save Media Settings</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
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

<!-- CREATE EXERCISE MODAL -->
<div class="modal" id="mdCreate" role="dialog" aria-modal="true" aria-labelledby="ceTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(720px,94vw);background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="ceTitle" style="margin:0 0 10px 0;font-size:16px">Create Exercise</h3>
  <form method="post" id="ceForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="create_exercise_modal">

    <div class="row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
      <div style="grid-column:span 12">
        <label class="muted" for="ce_name">Exercise Name</label>
        <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ce_name" name="ex_name" type="text" required>
      </div>
      <div style="grid-column:span 12">
        <label class="muted" for="ce_notes">Description</label> <!-- renamed label -->
        <textarea style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px;min-height:120px" id="ce_notes" name="ex_notes" placeholder=""></textarea>
      </div>

      <!-- Categories checklist -->
      <div style="grid-column:span 12">
        <label class="muted" for="ce_cat_filter">Categories (select one or more)</label>
        <input id="ce_cat_filter" type="text" placeholder="Filter categories..."
               style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:8px 10px;border-radius:8px;margin:6px 0">
        <div id="ce_cat_box" class="checkgrid" style="max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:8px;background:rgba(8,13,23,0.95)">
          <?php foreach ($categories as $c): ?>
            <label class="check">
              <input type="checkbox" name="category_ids[]" value="<?php echo (int)$c['id']; ?>">
              <span><?php echo h($c['name']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="muted" style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap">
          <a href="#" id="ce_new_cat_toggle">+ New Category</a>
          <a href="#" data-clear="#ce_cat_box">Clear all</a>
        </div>
      </div>

      <!-- Inline new category -->
      <div id="ce_new_cat_wrap" style="grid-column:span 12; display:none; border:1px dashed var(--line); border-radius:10px; padding:10px; margin-top:4px">
        <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
          <div style="grid-column:span 6">
            <label class="muted" for="ce_new_cat_name">New Category Name</label>
            <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ce_new_cat_name" name="new_category_name" type="text" placeholder="e.g., Upper Body">
          </div>
          <div style="grid-column:span 6">
            <label class="muted" for="ce_new_cat_desc">Description (optional)</label>
            <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ce_new_cat_desc" name="new_category_desc" type="text" placeholder="">
          </div>
        </div>
        <div class="muted" style="margin-top:6px">If provided, a new category will be created and assigned to this exercise.</div>
      </div>

      <div class="muted" style="grid-column:span 12">
        After saving, open the row to manage media (video, poster, captions).
      </div>
    </div>

    <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
      <button class="btn" type="button" data-close="#mdCreate" data-backdrop="#bdCreate">Cancel</button>
      <button class="btn brand" type="submit">Create Exercise</button>
    </div>
  </form>
</div>

<!-- EDIT EXERCISE MODAL -->
<div class="modal" id="mdEdit" role="dialog" aria-modal="true" aria-labelledby="eeTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(720px,94vw);background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="eeTitle" style="margin:0 0 10px 0;font-size:16px">Edit Exercise</h3>
  <form method="post" id="eeForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="edit_exercise_modal">
    <input type="hidden" id="eeId" name="exercise_id" value="">

    <div class="row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
      <div style="grid-column:span 12">
        <label class="muted" for="ee_name">Exercise Name</label>
        <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ee_name" name="ex_name" type="text" required>
      </div>
      <div style="grid-column:span 12">
        <label class="muted" for="ee_notes">Description</label> <!-- renamed label -->
        <textarea style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px;min-height:120px" id="ee_notes" name="ex_notes"></textarea>
      </div>

      <!-- Categories checklist -->
      <div style="grid-column:span 12">
        <label class="muted" for="ee_cat_filter">Categories</label>
        <input id="ee_cat_filter" type="text" placeholder="Filter categories..."
               style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:8px 10px;border-radius:8px;margin:6px 0">
        <div id="ee_cat_box" class="checkgrid" style="max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:8px;background:rgba(8,13,23,0.95)">
          <?php foreach ($categories as $c): ?>
            <label class="check">
              <input type="checkbox" name="category_ids[]" value="<?php echo (int)$c['id']; ?>">
              <span><?php echo h($c['name']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="muted" style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap">
          <a href="#" id="ee_new_cat_toggle">+ New Category</a>
          <a href="#" data-clear="#ee_cat_box">Clear all</a>
        </div>
      </div>

      <!-- Inline new category -->
      <div id="ee_new_cat_wrap" style="grid-column:span 12; display:none; border:1px dashed var(--line); border-radius:10px; padding:10px; margin-top:4px">
        <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
          <div style="grid-column:span 6">
            <label class="muted" for="ee_new_cat_name">New Category Name</label>
            <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ee_new_cat_name" name="new_category_name" type="text" placeholder="e.g., Upper Body">
          </div>
          <div style="grid-column:span 6">
            <label class="muted" for="ee_new_cat_desc">Description (optional)</label>
            <input style="width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;padding:10px 12px;border-radius:10px" id="ee_new_cat_desc" name="new_category_desc" type="text" placeholder="">
          </div>
        </div>
        <div class="muted" style="margin-top:6px">If provided, a new category will be created and assigned to this exercise.</div>
      </div>

      <div class="muted" style="grid-column:span 12">
        Need to manage video? <a href="#" id="openMediaFromEdit">Open Media Manager</a>
      </div>
    </div>

    <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
      <button class="btn" type="button" data-close="#mdEdit" data-backdrop="#bdEdit">Cancel</button>
      <button class="btn brand" type="submit">Save Changes</button>
    </div>
  </form>
</div>

<!-- DELETE EXERCISE MODAL -->
<div class="modal" id="mdDelete" role="dialog" aria-modal="true" aria-labelledby="deTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(560px,94vw);background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="deTitle" style="margin:0 0 10px 0;font-size:16px">Delete Exercise</h3>
  <form method="post" id="deForm" onsubmit="return confirm('Delete this exercise? It will be removed from any plans.');">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="delete_exercise_modal">
    <input type="hidden" id="deId" name="exercise_id" value="">
    <div class="muted" id="deText" style="margin-bottom:10px"></div>
    <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
      <button class="btn" type="button" data-close="#mdDelete" data-backdrop="#bdDelete">Cancel</button>
      <button class="btn warn" type="submit">Delete</button>
    </div>
  </form>
</div>

<script src="table_enhancements.js"></script>
<script>
const measurementConfig = <?php echo json_encode($EXERCISES_MEASUREMENT_JS, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
window.ppfMeasurement = measurementConfig;
(function(){
  const exerciseSearchInput = document.getElementById('exerciseSearch');
  ppfEnhanceTable('#exercisesTable', {
    rowSelector: 'tbody tr.exercise-row',
    searchInput: exerciseSearchInput,
    sortTypes: {
      id: 'number',
      created: 'number',
      edited: 'number',
      plans: 'number',
      media: 'number'
    },
    noMatchesText: 'No matching exercises.'
  });

  // Remove any lingering measurement chips from the subheader.
  document.querySelectorAll('.subheader .chip').forEach(chip => {
    const text = (chip.textContent || '').toLowerCase();
    if (text.includes('measure') || text.includes('imperial') || text.includes('metric')) {
      chip.remove();
    }
  });

  // -------------------- Row expand/collapse --------------------
  document.querySelectorAll('.exercise-row').forEach(tr=>{
    tr.addEventListener('click', (e)=>{
      if (e.target.closest('[data-actions]')) return; // ignore action clicks
      const eid = tr.getAttribute('data-ex');
      const exp = document.getElementById('exp-'+eid);
      if (!exp) return;

      const isOpen = (exp.style.display === 'table-row');
      exp.style.display = isOpen ? 'none' : 'table-row';
      tr.classList.toggle('expanded', !isOpen);
    });
  });

  // -------------------- Modal helpers -------------------------
  function openModal(modalSel, bdSel){
    const m = document.querySelector(modalSel), b = document.querySelector(bdSel);
    if (!m || !b) return;
    m.style.display='block'; b.style.display='block';
    document.body.style.overflow='hidden';
  }
  function closeModal(modalSel, bdSel){
    const m = document.querySelector(modalSel), b = document.querySelector(bdSel);
    if (!m || !b) return;
    m.style.display='none'; b.style.display='none';
    document.body.style.overflow='';
  }
  document.querySelectorAll('[data-close]').forEach(btn=>{
    btn.addEventListener('click', ()=> closeModal(btn.getAttribute('data-close'), btn.getAttribute('data-backdrop')));
  });

  // Open Create
  document.getElementById('btnCreateExercise')?.addEventListener('click', ()=> openModal('#mdCreate','#bdCreate'));

  // Toggle inline new-category blocks
  const ceToggle = document.getElementById('ce_new_cat_toggle');
  const ceWrap   = document.getElementById('ce_new_cat_wrap');
  ceToggle?.addEventListener('click', (e)=>{ e.preventDefault(); ceWrap.style.display = (ceWrap.style.display==='none'||!ceWrap.style.display) ? 'block' : 'none'; });

  const eeToggle = document.getElementById('ee_new_cat_toggle');
  const eeWrap   = document.getElementById('ee_new_cat_wrap');
  eeToggle?.addEventListener('click', (e)=>{ e.preventDefault(); eeWrap.style.display = (eeWrap.style.display==='none'||!eeWrap.style.display) ? 'block' : 'none'; });

  // ----------- Helpers for category checklist -----------
  function precheck(container, ids){
    if (!container) return;
    const set = new Set(ids.map(Number));
    container.querySelectorAll('input[type="checkbox"][name="category_ids[]"]').forEach(cb=>{
      cb.checked = set.has(parseInt(cb.value,10));
    });
  }
  function bindFilter(input, container){
    if (!input || !container) return;
    input.addEventListener('input', ()=>{
      const q = input.value.trim().toLowerCase();
      container.querySelectorAll('.check').forEach(row=>{
        const txt = row.textContent.toLowerCase();
        row.style.display = (!q || txt.includes(q)) ? '' : 'none';
      });
    });
  }
  function bindClearAll(){
    document.querySelectorAll('[data-clear]').forEach(a=>{
      a.addEventListener('click', (e)=>{
        e.preventDefault();
        const box = document.querySelector(a.getAttribute('data-clear'));
        if (!box) return;
        box.querySelectorAll('input[type="checkbox"][name="category_ids[]"]').forEach(cb=> cb.checked = false);
      });
    });
  }
  bindClearAll();
  bindFilter(document.getElementById('ce_cat_filter'), document.getElementById('ce_cat_box'));
  bindFilter(document.getElementById('ee_cat_filter'), document.getElementById('ee_cat_box'));

  // -------------------- Edit ---------------------------
  document.querySelectorAll('[data-edit]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id    = btn.getAttribute('data-ex-id');
      const name  = btn.getAttribute('data-ex-name') || '';
      const notes = btn.getAttribute('data-ex-notes') || '';
      const catCsv= btn.getAttribute('data-ex-cat-ids') || '';
      const chosen = catCsv ? catCsv.split(',').map(x=>parseInt(x,10)).filter(x=>!isNaN(x)) : [];

      document.getElementById('eeId').value = id;
      document.getElementById('ee_name').value = name;
      document.getElementById('ee_notes').value = notes;

      // Pre-check categories in checklist
      precheck(document.getElementById('ee_cat_box'), chosen);

      // wire "Open Media Manager"
      const link = document.getElementById('openMediaFromEdit');
      if (link) {
        link.onclick = (e)=>{
          e.preventDefault();
          closeModal('#mdEdit','#bdEdit');
          const row = document.getElementById('ex-'+id);
          if (row) row.click(); // toggles expand
          // scroll to media panel
          const mediaCard = document.querySelector('#exp-'+id+' .media-card');
          if (mediaCard) mediaCard.scrollIntoView({behavior:'smooth', block:'center'});
        };
      }
      openModal('#mdEdit','#bdEdit');
    });
  });

  // -------------------- Delete ---------------------------
  document.querySelectorAll('[data-delete]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id   = btn.getAttribute('data-ex-id');
      const name = btn.getAttribute('data-ex-name') || ('Exercise #'+id);
      document.getElementById('deId').value = id;
      document.getElementById('deText').textContent = `Delete "${name}"? It will be removed from any workout plans.`;
      openModal('#mdDelete','#bdDelete');
    });
  });

  // --- Auto-open create when navigated with ?open=create
  const qs = new URLSearchParams(location.search);
  if (qs.get('open') === 'create') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', ()=> openModal('#mdCreate', '#bdCreate'));
    } else {
      openModal('#mdCreate', '#bdCreate');
    }
  }

  // -------------------- Media Upload/Replace -------------------
  function uploadWithProgress(file, eid, kind){ // kind: 'video' | 'poster' | 'captions'
    const prog = document.getElementById('prog-'+eid);

    // quick client-side guards
    if (kind === 'video') {
      const maxBytes = 200 * 1024 * 1024;
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (!['mp4','m4v'].includes(ext)) { alert('Only MP4 videos are allowed.'); return; }
      if (file.size > maxBytes) { alert('Video exceeds 200 MB limit.'); return; }
    }
    if (kind === 'poster') {
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (!['jpg','jpeg','png','webp'].includes(ext)) { alert('Poster must be jpg/png/webp.'); return; }
    }
    if (kind === 'captions') {
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (ext !== 'vtt') { alert('Captions must be .vtt.'); return; }
    }

    if (kind === 'video' && prog) {
      prog.style.display = 'block';
      prog.firstElementChild.style.width = '0%';
    }

    const fd = new FormData();
    fd.append('csrf_token', '<?php echo h($csrf); ?>');
    fd.append('exercise_id', eid);
    fd.append('kind', kind);
    fd.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload_exercise_media.php', true);
    xhr.timeout = 10 * 60 * 1000;
    try { xhr.responseType = 'json'; } catch (_) {}

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && kind === 'video' && prog) {
        const p = Math.round((e.loaded / e.total) * 100);
        prog.firstElementChild.style.width = p + '%';
      }
    };

    function hideProgress() {
      if (kind === 'video' && prog) {
        prog.firstElementChild.style.width = '100%';
        setTimeout(()=>{ prog.style.display='none'; }, 300);
      }
    }

    function parseErrorBody(xhr) {
      if (xhr.response && typeof xhr.response === 'object' && 'error' in xhr.response) {
        return String(xhr.response.error || 'Upload failed.');
      }
      const text = xhr.responseText || '';
      try { const j = JSON.parse(text); if (j && j.error) return String(j.error); } catch(_) {}
      return (text || 'Upload failed.').slice(0, 300);
    }

    xhr.onload = () => {
      hideProgress();
      if (xhr.status === 200) {
        const res = xhr.response && typeof xhr.response === 'object' ? xhr.response : (function(){ try { return JSON.parse(xhr.responseText || '{}'); } catch(_) { return {}; }})();
        if (res && res.ok) { location.reload(); return; }
        alert((res && res.error) ? res.error : 'Upload failed.');
        return;
      }
      if (xhr.status === 413) alert('Upload too large. Increase IIS/PHP limits or upload a smaller file.');
      else if (xhr.status === 403) alert('Forbidden. Your session/role may not allow this action.');
      else if (xhr.status === 500) alert('Server error while saving the file.');
      else { const bodyMsg = parseErrorBody(xhr); alert(`Upload failed (HTTP ${xhr.status}). ${bodyMsg}`); }
    };
    xhr.onerror = ()=>{ hideProgress(); alert('Network error during upload.'); };
    xhr.ontimeout = ()=>{ hideProgress(); alert('Upload timed out.'); };
    xhr.onabort = ()=>{ hideProgress(); alert('Upload was cancelled.'); };

    xhr.send(fd);
  }

  // Bind dropzones (video)
  document.querySelectorAll('[data-dz]').forEach(el=>{
    const eid = el.getAttribute('data-dz');
    const onClick = () => {
      const inp = document.createElement('input');
      inp.type = 'file'; inp.accept = 'video/mp4';
      inp.onchange = () => { if (inp.files && inp.files[0]) uploadWithProgress(inp.files[0], eid, 'video'); };
      inp.click();
    };
    el.addEventListener('click', onClick);
    el.addEventListener('dragover', e=>{ e.preventDefault(); el.classList.add('dragover'); });
    el.addEventListener('dragleave', ()=> el.classList.remove('dragover'));
    el.addEventListener('drop', e=>{
      e.preventDefault(); el.classList.remove('dragover');
      const f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) uploadWithProgress(f, eid, 'video');
    });
  });

  // Poster upload
  document.querySelectorAll('input[data-poster]').forEach(inp=>{
    const eid = inp.getAttribute('data-poster');
    inp.addEventListener('change', ()=>{ if (inp.files && inp.files[0]) uploadWithProgress(inp.files[0], eid, 'poster'); });
  });

  // Captions upload
  document.querySelectorAll('input[data-captions]').forEach(inp=>{
    const eid = inp.getAttribute('data-captions');
    inp.addEventListener('change', ()=>{ if (inp.files && inp.files[0]) uploadWithProgress(inp.files[0], eid, 'captions'); });
  });

  // Remove buttons
  function removeMedia(eid, kind){
    const fd = new FormData();
    fd.append('csrf_token', '<?php echo h($csrf); ?>');
    fd.append('exercise_id', eid);
    fd.append('kind', kind);
    fetch('delete_exercise_media.php', { method:'POST', body: fd })
      .then(r=>r.json()).then(res=>{
        if (res && res.ok) location.reload();
        else alert(res.error || 'Failed to remove.');
      }).catch(()=> alert('Failed to remove.'));
  }
  document.querySelectorAll('[data-remove-video]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ const eid = btn.getAttribute('data-remove-video'); if (confirm('Remove the video from this exercise?')) removeMedia(eid, 'video'); });
  });
  document.querySelectorAll('[data-remove-poster]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ const eid = btn.getAttribute('data-remove-poster'); if (confirm('Remove the poster image?')) removeMedia(eid, 'poster'); });
  });
  document.querySelectorAll('[data-remove-captions]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ const eid = btn.getAttribute('data-remove-captions'); if (confirm('Remove the captions?')) removeMedia(eid, 'captions'); });
  });

  // Click a plan row -> jump to workout_plans.php and focus/expand it
  document.addEventListener('click', (e)=>{
    const row = e.target.closest('.mini-plan-row');
    if (!row) return;
    const pid = row.getAttribute('data-plan-id');
    if (!pid) return;
    location.href = `workout_plans.php?focus_plan=${encodeURIComponent(pid)}#plan-${encodeURIComponent(pid)}`;
  });

  // Deep-link support: ?focus_exercise=ID or #ex-ID
  (function(){
    const params = new URLSearchParams(location.search);
    let targetId = params.get('focus_exercise');
    if (!targetId && location.hash && /^#ex-(\d+)$/.test(location.hash)) {
      targetId = location.hash.slice(4);
    }
    if (!targetId) return;
    const row = document.getElementById('ex-' + targetId);
    const exp = document.getElementById('exp-' + targetId);
    if (!row || !exp) return;
    exp.style.display = 'table-row';
    row.classList.add('expanded','focused');
    requestAnimationFrame(() => {
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => row.classList.remove('focused'), 2200);
    });
  })();

})();
</script>
</body>
</html>