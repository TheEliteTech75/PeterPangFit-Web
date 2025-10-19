<?php
// workout_plans.php — Plans index w/ expand, assign, create, edit, delete

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_trainer_admin($role){ return in_array($role ?? 'guest', ['trainer','admin'], true); }

// Small helpers ---------------------------------------------------------------
function parse_duration_to_seconds($input) {
  $s = trim((string)$input);
  if ($s === '') return null;
  if (preg_match('/^\d+:\d{1,2}$/', $s)) { [$m,$sec] = array_map('intval', explode(':', $s, 2)); return $m*60 + $sec; }
  if (ctype_digit($s)) return (int)$s;
  return null;
}
function parse_weight_to_float($input) {
  $s = trim((string)$input);
  if ($s === '') return null;
  if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
  return (float)$s;
}

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

if (!is_trainer_admin($USER_ROLE ?? null)) { http_response_code(403); echo 'Forbidden'; exit; }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$flash = null; $flash_type = 'ok';

// Detect optional columns on workout_plans
$HAS_CREATED_AT = column_exists($conn, 'workout_plans', 'created_at');
$HAS_CREATED_BY = column_exists($conn, 'workout_plans', 'created_by');
$HAS_UPDATED_AT = column_exists($conn, 'workout_plans', 'updated_at');
$HAS_UPDATED_BY = column_exists($conn, 'workout_plans', 'updated_by');
// Detect optional columns on exercises (some installs may not have all)
$EX_HAS_CREATED_AT  = column_exists($conn, 'exercises', 'created_at');
$EX_HAS_CREATED_BY  = column_exists($conn, 'exercises', 'created_by');
$EX_HAS_UPDATED_AT  = column_exists($conn, 'exercises', 'updated_at');
$EX_HAS_UPDATED_BY  = column_exists($conn, 'exercises', 'updated_by');

$EX_HAS_VIDEO_URL        = column_exists($conn, 'exercises', 'video_url');
$EX_HAS_VIDEO_POSTER_URL = column_exists($conn, 'exercises', 'video_poster_url');
$EX_HAS_VIDEO_DURATION   = column_exists($conn, 'exercises', 'video_duration_sec');
$EX_HAS_CAPTIONS_VTT     = column_exists($conn, 'exercises', 'captions_vtt_url');

// ----------------------------------------------------------------------------------
// AUTO-MIGRATE: ensure user_notes column on user_plan_exercises (safe/idempotent)
// ----------------------------------------------------------------------------------
if (!function_exists('ensure_user_notes_column')) {
  function ensure_user_notes_column(mysqli $conn): void {
    if (column_exists($conn, 'user_plan_exercises', 'user_notes')) return;
    $after = column_exists($conn, 'user_plan_exercises', 'duration_seconds') ? " AFTER `duration_seconds`" : "";
    // best-effort; ignore errors if perms restricted
    @$conn->query("ALTER TABLE `user_plan_exercises` ADD COLUMN `user_notes` TEXT NULL{$after}");
  }
}
ensure_user_notes_column($conn);
$HAS_USER_NOTES_COL = column_exists($conn, 'user_plan_exercises', 'user_notes');

// ----------------------------------------------------------------------------------
// POST actions (do BEFORE any output)
// ----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Invalid session. Please try again.'; $flash_type = 'err';
  } else {
    $action = $_POST['action'] ?? '';
    try {

      // CREATE PLAN (modal)
      if ($action === 'create_plan_modal') {
        $title = trim($_POST['plan_title'] ?? '');
        $raw   = $_POST['selected_exercises'] ?? [];
        if (!is_array($raw)) $raw = [$raw];
        $exercise_ids = array_values(array_filter(array_map('intval', $raw), fn($n)=>$n>0));

        if ($title === '') throw new Exception('Plan name is required.');
        $conn->begin_transaction();

        if ($HAS_CREATED_BY && $HAS_CREATED_AT && $HAS_UPDATED_AT && $HAS_UPDATED_BY) {
          $stmt = $conn->prepare("INSERT INTO workout_plans (name, created_by, created_at, updated_by, updated_at) VALUES (?, ?, NOW(), ?, NOW())");
          $by = (int)($USER_ID ?? 0);
          $stmt->bind_param("sii", $title, $by, $by);
        } elseif ($HAS_CREATED_BY && $HAS_CREATED_AT) {
          $stmt = $conn->prepare("INSERT INTO workout_plans (name, created_by, created_at) VALUES (?, ?, NOW())");
          $by = (int)($USER_ID ?? 0);
          $stmt->bind_param("si", $title, $by);
        } else {
          $stmt = $conn->prepare("INSERT INTO workout_plans (name) VALUES (?)");
          $stmt->bind_param("s", $title);
        }
        if (!$stmt->execute()) { $conn->rollback(); throw new Exception('Failed to create plan.'); }
        $plan_id = $stmt->insert_id; $stmt->close();

        if ($exercise_ids) {
          $pos = 1;
          $stmt = $conn->prepare("INSERT INTO plan_exercises (plan_id, exercise_id, position) VALUES (?, ?, ?)");
          foreach ($exercise_ids as $eid) {
            $stmt->bind_param("iii", $plan_id, $eid, $pos);
            if (!$stmt->execute()) { $conn->rollback(); throw new Exception('Failed to add exercise to plan.'); }
            $pos++;
          }
          $stmt->close();
        }
        $conn->commit();
        if (function_exists('ppf_log')) {
          $details = json_encode([
            'name' => $title,
            'exercise_ids' => $exercise_ids,
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          @ppf_log($conn, null, null, null, 'workout_plan_created', 'workout_plan', (string)$plan_id, $details ?: '');
        }
        header('Location: workout_plans.php?created=1'); exit;
      }

      // EDIT PLAN (modal) — change name + reorder/replace exercises
      if ($action === 'edit_plan_modal') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $title   = trim($_POST['plan_title'] ?? '');
        $raw     = $_POST['selected_exercises'] ?? [];
        if (!is_array($raw)) $raw = [$raw];
        $exercise_ids = array_values(array_filter(array_map('intval', $raw), fn($n)=>$n>0));

        $plan_before_name = null;
        $plan_before_exercises = [];
        if ($stmt = $conn->prepare("SELECT name FROM workout_plans WHERE id = ?")) {
          $stmt->bind_param("i", $plan_id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $plan_before_name = $row['name'] ?? null;
            }
          }
          $stmt->close();
        }
        if ($stmt = $conn->prepare("SELECT exercise_id FROM plan_exercises WHERE plan_id = ? ORDER BY position ASC, exercise_id ASC")) {
          $stmt->bind_param("i", $plan_id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_assoc()) {
              $plan_before_exercises[] = (int)$row['exercise_id'];
            }
          }
          $stmt->close();
        }

        // Robust fallback: parse "selected_order" (comma-separated "1,2,3")
        if (!$exercise_ids) {
          $order = trim((string)($_POST['selected_order'] ?? ''));
          if ($order !== '') {
            $exercise_ids = array_values(array_filter(array_map('intval', explode(',', $order)), fn($n)=>$n>0));
          }
        }

        if ($plan_id <= 0) throw new Exception('Invalid plan.');
        if ($title === '') throw new Exception('Plan name is required.');

        $conn->begin_transaction();

        if ($HAS_UPDATED_AT && $HAS_UPDATED_BY) {
          $stmt = $conn->prepare("UPDATE workout_plans SET name = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
          $by = (int)($USER_ID ?? 0);
          $stmt->bind_param("sii", $title, $by, $plan_id);
        } else {
          $stmt = $conn->prepare("UPDATE workout_plans SET name = ? WHERE id = ?");
          $stmt->bind_param("si", $title, $plan_id);
        }
        if (!$stmt->execute()) { $conn->rollback(); throw new Exception('Failed to update plan.'); }
        $stmt->close();

        // replace exercises
        $stmt = $conn->prepare("DELETE FROM plan_exercises WHERE plan_id = ?");
        $stmt->bind_param("i", $plan_id);
        if (!$stmt->execute()) { $conn->rollback(); throw new Exception('Failed to reset exercises.'); }
        $stmt->close();

        if ($exercise_ids) {
          $pos = 1;
          $stmt = $conn->prepare("INSERT INTO plan_exercises (plan_id, exercise_id, position) VALUES (?, ?, ?)");
          foreach ($exercise_ids as $eid) {
            $stmt->bind_param("iii", $plan_id, $eid, $pos);
            if (!$stmt->execute()) { $conn->rollback(); throw new Exception('Failed to insert exercise.'); }
            $pos++;
          }
          $stmt->close();
        }

        $conn->commit();
        if (function_exists('ppf_log')) {
          $details = json_encode([
            'plan_id' => $plan_id,
            'changes' => ppf_changed_fields(
              [
                'name' => $plan_before_name,
                'exercise_ids' => $plan_before_exercises,
              ],
              [
                'name' => $title,
                'exercise_ids' => $exercise_ids,
              ]
            ),
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          @ppf_log($conn, null, null, null, 'workout_plan_updated', 'workout_plan', (string)$plan_id, $details ?: '');
        }
        header('Location: workout_plans.php?updated=1'); exit;
      }

      // DELETE PLAN (modal)
      if ($action === 'delete_plan_modal') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        if ($plan_id <= 0) throw new Exception('Invalid plan.');
        $plan_delete_name = null;
        $plan_delete_exercise_count = 0;
        $plan_delete_user_count = 0;
        if ($stmt = $conn->prepare("SELECT name FROM workout_plans WHERE id = ?")) {
          $stmt->bind_param("i", $plan_id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $plan_delete_name = $row['name'] ?? null;
            }
          }
          $stmt->close();
        }
        if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM plan_exercises WHERE plan_id = ?")) {
          $stmt->bind_param("i", $plan_id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $plan_delete_exercise_count = (int)($row['c'] ?? 0);
            }
          }
          $stmt->close();
        }
        if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM user_plans WHERE plan_id = ?")) {
          $stmt->bind_param("i", $plan_id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $plan_delete_user_count = (int)($row['c'] ?? 0);
            }
          }
          $stmt->close();
        }
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM user_plans WHERE plan_id = ?");
        $stmt->bind_param("i", $plan_id); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("DELETE FROM plan_exercises WHERE plan_id = ?");
        $stmt->bind_param("i", $plan_id); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("DELETE FROM workout_plans WHERE id = ?");
        $stmt->bind_param("i", $plan_id);
        if (!$stmt->execute()) { $conn->rollback(); throw new Exception('Failed to delete plan.'); }
        $stmt->close();
        $conn->commit();
        if (function_exists('ppf_log')) {
          $details = json_encode([
            'plan_id' => $plan_id,
            'name' => $plan_delete_name,
            'exercise_count' => $plan_delete_exercise_count,
            'assigned_user_count' => $plan_delete_user_count,
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          @ppf_log($conn, null, null, null, 'workout_plan_deleted', 'workout_plan', (string)$plan_id, $details ?: '');
        }
        header('Location: workout_plans.php?deleted=1'); exit;
      }

      // ASSIGN PLAN (modal) w/ per-user per-exercise settings (+ User Notes)
      if ($action === 'assign_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $user_ids = array_map('intval', $_POST['assign_users'] ?? []);
        if ($plan_id<=0) throw new Exception('Invalid plan.');
        if (!$user_ids) throw new Exception('Pick at least one user.');

        // plan exercises
        $pe = [];
        $stmt = $conn->prepare("SELECT exercise_id, position FROM plan_exercises WHERE plan_id=? ORDER BY position ASC, exercise_id ASC");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) $pe[] = $row;
        $stmt->close();
        if (!$pe) throw new Exception('This plan has no exercises.');

        $who = (int)($USER_ID ?? 0);
        // Inputs are nested arrays: sets[UID][EID], reps[UID][EID], duration[UID][EID], weight[UID][EID], user_notes[UID][EID]
        $SETS = $_POST['sets'] ?? [];
        $REPS = $_POST['reps'] ?? [];
        $DURS = $_POST['duration'] ?? [];
        $WGTS = $_POST['weight'] ?? [];
        $NOTS = $_POST['user_notes'] ?? [];

        // Figure out correct weight column name
        $WEIGHT_COL = null;
        if (column_exists($conn, 'user_plan_exercises', 'weight_lbs')) {
          $WEIGHT_COL = 'weight_lbs';
        } elseif (column_exists($conn, 'user_plan_exercises', 'weight')) {
          $WEIGHT_COL = 'weight';
        }

        $stmtCheck = $conn->prepare("SELECT id FROM user_plans WHERE user_id=? AND plan_id=? LIMIT 1");
        $stmtUP    = $conn->prepare("INSERT INTO user_plans (user_id, plan_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");

        // Prepare insert for user_plan_exercises (with/without weight + user_notes)
        $bindCase = null; // 'WN' (weight+notes), 'W' (weight only), 'N' (notes only), 'B' (bare)
        if ($WEIGHT_COL && $HAS_USER_NOTES_COL) {
          $sqlUPE = "INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, duration_seconds, {$WEIGHT_COL}, user_notes, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
          $bindCase = 'WN';
        } elseif ($WEIGHT_COL && !$HAS_USER_NOTES_COL) {
          $sqlUPE = "INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, duration_seconds, {$WEIGHT_COL}, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
          $bindCase = 'W';
        } elseif (!$WEIGHT_COL && $HAS_USER_NOTES_COL) {
          $sqlUPE = "INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, duration_seconds, user_notes, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
          $bindCase = 'N';
        } else {
          $sqlUPE = "INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, duration_seconds, position)
                     VALUES (?, ?, ?, ?, ?, ?)";
          $bindCase = 'B';
        }
        $stmtUPE = $conn->prepare($sqlUPE);

        $conn->begin_transaction();
        try {
          foreach ($user_ids as $uidAssign) {
            if ($uidAssign <= 0) continue;

            // avoid duplicate parent
            $stmtCheck->bind_param("ii", $uidAssign, $plan_id);
            $stmtCheck->execute();
            $exists = $stmtCheck->get_result()->fetch_assoc();
            if ($exists) continue;

            $stmtUP->bind_param("iii", $uidAssign, $plan_id, $who);
            if (!$stmtUP->execute()) throw new Exception('Failed to assign plan.');
            $user_plan_id = $stmtUP->insert_id;

            foreach ($pe as $row) {
              $eid = (int)$row['exercise_id'];
              $pos = (int)$row['position'];

              $sets_in = $SETS[$uidAssign][$eid] ?? '';
              $reps_in = $REPS[$uidAssign][$eid] ?? '';
              $dur_in  = $DURS[$uidAssign][$eid] ?? '';
              $wgt_in  = $WGTS[$uidAssign][$eid] ?? '';
              $notes_in= $NOTS[$uidAssign][$eid] ?? '';

              $sets = (strlen(trim((string)$sets_in)) ? (int)$sets_in : null);
              $reps = (strlen(trim((string)$reps_in)) ? (int)$reps_in : null);
              $dur  = (strlen(trim((string)$dur_in))  ? parse_duration_to_seconds($dur_in) : null);
              if ($dur_in !== '' && $dur === null) throw new Exception('Invalid duration (use mm:ss or seconds).');
              $wgt  = (strlen(trim((string)$wgt_in))  ? parse_weight_to_float($wgt_in) : null);
              if ($wgt_in !== '' && $wgt === null) throw new Exception('Invalid weight (numbers only).');
              $notes = (trim((string)$notes_in) !== '') ? (string)$notes_in : null;

              if ($bindCase === 'WN') {
                // i i i i d d s i
                $stmtUPE->bind_param("iiiiddsi", $user_plan_id, $eid, $sets, $reps, $dur, $wgt, $notes, $pos);
              } elseif ($bindCase === 'W') {
                // i i i i d d i
                $stmtUPE->bind_param("iiiiddi", $user_plan_id, $eid, $sets, $reps, $dur, $wgt, $pos);
              } elseif ($bindCase === 'N') {
                // i i i i d s i
                $stmtUPE->bind_param("iiiidsi", $user_plan_id, $eid, $sets, $reps, $dur, $notes, $pos);
              } else { // 'B'
                // i i i i d i
                $stmtUPE->bind_param("iiiidi", $user_plan_id, $eid, $sets, $reps, $dur, $pos);
              }
              if (!$stmtUPE->execute()) throw new Exception('Failed to save exercise settings.');
            }
          }
          $conn->commit();
        } catch (Throwable $e) {
          $conn->rollback();
          throw $e;
        } finally {
          $stmtCheck->close();
          $stmtUP->close();
          $stmtUPE->close();
        }

        header('Location: workout_plans.php?assigned=1'); exit;
      }

    } catch (Throwable $e) {
      $flash = $e->getMessage(); $flash_type = 'err';
    }
  }
}

// ----------------------------------------------------------------------------------
// Load data for page
// ----------------------------------------------------------------------------------

// Exercises (map)
$exercises = [];
$res = $conn->query("SELECT id, name, notes FROM exercises ORDER BY name ASC");
if ($res) { while ($r = $res->fetch_assoc()) $exercises[] = $r; }

// Plans summary
$sel = "
  SELECT p.id, p.name,
";
$sel .= $HAS_CREATED_AT ? "       p.created_at, " : "       NULL AS created_at, ";
$sel .= $HAS_CREATED_BY ? "       p.created_by, " : "       NULL AS created_by, ";
$sel .= $HAS_UPDATED_AT ? "       p.updated_at, " : "       NULL AS updated_at, ";
$sel .= $HAS_UPDATED_BY ? "       p.updated_by, " : "       NULL AS updated_by, ";
$sel .= "
         COUNT(DISTINCT pe.exercise_id) AS exercise_count,
         COUNT(DISTINCT up.user_id)     AS assigned_count
  FROM workout_plans p
  LEFT JOIN plan_exercises pe ON pe.plan_id = p.id
  LEFT JOIN user_plans up     ON up.plan_id = p.id
  GROUP BY p.id, p.name, created_at, created_by, updated_at, updated_by
  ORDER BY p.id DESC
";
$res = $conn->query($sel);
if ($res) { while ($r = $res->fetch_assoc()) $plans[] = $r; }

// Plan -> exercises (ids in order) and exercise details for expansion
$plan_ex_map  = []; // plan_id => [eid, ...]
$plan_ex_rows = []; // plan_id => [{exercise_id,name,notes,position, ... extra cols}, ...]

if (!empty($plans)) {
  $ids = array_column($plans, 'id');
  $in  = implode(',', array_map('intval', $ids));
  if ($in !== '') {

    // Build a safe SELECT list based on what columns exist on exercises
    $selEx = "e.name, e.notes";
    $selEx .= $EX_HAS_CREATED_AT  ? ", e.created_at"       : ", NULL AS created_at";
    $selEx .= $EX_HAS_CREATED_BY  ? ", e.created_by"       : ", NULL AS created_by";
    $selEx .= $EX_HAS_UPDATED_AT  ? ", e.updated_at"       : ", NULL AS updated_at";
    $selEx .= $EX_HAS_UPDATED_BY  ? ", e.updated_by"       : ", NULL AS updated_by";
    $selEx .= $EX_HAS_VIDEO_URL        ? ", e.video_url"        : ", NULL AS video_url";
    $selEx .= $EX_HAS_VIDEO_POSTER_URL ? ", e.video_poster_url" : ", NULL AS video_poster_url";
    $selEx .= $EX_HAS_VIDEO_DURATION   ? ", e.video_duration_sec" : ", NULL AS video_duration_sec";
    $selEx .= $EX_HAS_CAPTIONS_VTT     ? ", e.captions_vtt_url" : ", NULL AS captions_vtt_url";

    // Used-in-plans count (works regardless of flags)
    $selEx .= ",
      (SELECT COUNT(DISTINCT pe2.plan_id)
         FROM plan_exercises pe2
        WHERE pe2.exercise_id = e.id) AS used_in_plans";

    $sql = "
      SELECT
        pe.plan_id, pe.exercise_id, pe.position,
        {$selEx}
      FROM plan_exercises pe
      JOIN exercises e ON e.id = pe.exercise_id
      WHERE pe.plan_id IN ($in)
      ORDER BY pe.plan_id ASC, pe.position ASC, e.name ASC
    ";
    if ($res = $conn->query($sql)) {
      while ($r = $res->fetch_assoc()) {
        $pid = (int)$r['plan_id'];
        $eid = (int)$r['exercise_id'];
        $plan_ex_map[$pid][] = $eid;
        $plan_ex_rows[$pid][] = [
          'exercise_id'       => $eid,
          'name'              => $r['name'],
          'notes'             => $r['notes'],
          'position'          => (int)$r['position'],
          'created_at'        => $r['created_at'] ?? null,
          'created_by'        => $r['created_by'] ?? null,
          'updated_at'        => $r['updated_at'] ?? null,
          'updated_by'        => $r['updated_by'] ?? null,
          'video_url'         => $r['video_url'] ?? '',
          'video_poster_url'  => $r['video_poster_url'] ?? '',
          'video_duration_sec'=> $r['video_duration_sec'] ?? null,
          'captions_vtt_url'  => $r['captions_vtt_url'] ?? '',
          'used_in_plans'     => (int)($r['used_in_plans'] ?? 0),
        ];
      }
    }
  }
}

// Users (clients incl. is_client)
$clients = [];
$res = $conn->query("SELECT id, first_name, last_name, email FROM users WHERE role='client' OR is_client=1 ORDER BY last_name, first_name");
if ($res) { while ($r = $res->fetch_assoc()) $clients[] = $r; }

// Build helper maps for JS
$EXERCISE_MAP = [];
foreach ($exercises as $ex) { $EXERCISE_MAP[(int)$ex['id']] = $ex['name']; }

// Cache user display names so we don't re-query the same ids repeatedly
$_USER_NAME_CACHE = [];
function user_display_name(mysqli $conn, ?int $uid): string {
  if (!$uid) return '—';
  static $cache = [];
  if (isset($cache[$uid])) return $cache[$uid];
  $stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $rs = $stmt->get_result();
  $name = '—';
  if ($u = $rs->fetch_assoc()) {
    $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
    $name = $nm !== '' ? $nm : ($u['email'] ?? '—');
  }
  $stmt->close();
  return $cache[$uid] = $name;
}

require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Workout Plans · Peter Pang Fit</title>
<style>
  :root{
    --bg:#0b0c10; --panel:#12141a; --text:#e6e8ee; --muted:#9aa3b2; --brand:#3b82f6;
    --line:#1c212b; --chip:#1f2430; --ok:#10b981; --warn:#ef4444;
  }
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}

  /* Subheader (persistent) */
.subheader{
  position: sticky;
  top: 0;
  z-index: 40;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px 12px;
  margin-bottom: 14px;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.subheader .left{display:flex;align-items:center;gap:10px}
.brand{font-weight:800;font-size:20px;letter-spacing:.2px}
.btnset{display:flex;gap:8px;flex-wrap:wrap}

.btn{
  display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
  color:#e6e8ee;padding:8px 12px;border-radius:10px;cursor:pointer;text-decoration:none
}
.btn:hover{filter:brightness(1.06)}
.btn.brand{background:#1f2f55;border-color:#284072}
.btn.warn{background:#2a1617;border-color:#5b1b20;color:#ffb4b4}
.btn.small{padding:6px 10px;font-size:13px}

  .wrap{max-width:none;width:95%;margin:18px auto;padding:0 16px}
  .card{background:#151923;border:1px solid var(--line);border-radius:14px;padding:14px}
  .muted{color:var(--muted)}
  .flash{margin:16px auto 0 auto;max-width:none;width:calc(100% - 32px);padding:12px;border-radius:10px;border:1px solid;background:#10161a}
  .flash.ok{border-color:#204a36;color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}

  table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:12px;overflow:hidden;border:1px solid var(--line)}
  th,td{padding:12px 14px;border-bottom:1px solid var(--line);vertical-align:top}
  th{background:#0f1218;text-align:left;color:#c3c9d4;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
  tr:last-child td{border-bottom:none}

  /* Hover to highlight plan row; click to expand */
  .plan-row{cursor:pointer}
  .plan-row:hover{background:#141a25}
  .plan-row.focused{
    outline: 2px solid var(--brand);
    outline-offset: -2px;
    background:#141a25;
    transition: background .4s ease;
  }
  .plan-row.expanded{
    background:#141a25;
    outline:2px solid var(--brand);
    outline-offset:-2px;
    transition: background .2s ease, outline-color .2s ease;
  }
  .expand{display:none;background:#0f1218}
  .expand td{border-top:1px solid var(--line)}
  .row-actions{display:flex;gap:8px;flex-wrap:wrap}

  .ex-click{cursor:pointer}
  .ex-click:hover{background:#141a25}
  .ex-click:active{filter:brightness(1.06)}

  .thumb-mini{height:54px;width:96px;overflow:hidden;border-radius:6px;border:1px solid var(--line);background:#0f1218}
  .thumb-mini img{height:100%;width:100%;object-fit:cover;display:block}
  @media (min-width:1400px){ .thumb-mini{height:68px;width:120px} }

  .chip{
    display:inline-flex;align-items:center;gap:6px;background:var(--chip);
    border:1px solid var(--line);padding:3px 7px;border-radius:999px;font-size:12px;color:#c3c9d4;
  }

  /* Modals */
  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000}
  .modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(860px,94vw);
    background:#151923;border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001}
  .modal h3{margin:0 0 10px 0;font-size:16px}
  .modal .row{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}
  .modal .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}
  .input, select, textarea{
    width:100%;background:#0f1218;border:1px solid var(--line);color:#e6e8ee;
    padding:10px 12px;border-radius:10px;box-sizing:border-box
  }
  .listbox{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .box{border:1px solid var(--line);border-radius:10px;padding:10px;min-height:220px;max-height:320px;overflow:auto}
  .pill{display:inline-flex;align-items:center;gap:8px;background:var(--chip);
    border:1px solid var(--line);border-radius:999px;padding:6px 10px}
  .handle{cursor:grab;opacity:.85}
  .ex-row{display:flex;align-items:center;gap:8px;padding:6px;border:1px dashed var(--line);border-radius:8px;margin-bottom:6px}
  .ex-row:hover{background:#0f1218}
  .xlink{color:#fca5a5;cursor:pointer;text-decoration:none}
  .fine{font-size:12px;color:#9aa3b2}

  /* Assign modal per-user editor */
  .user-item{border-top:1px solid var(--line);padding:8px 0}
  .user-item:first-child{border-top:0}
  .plan-ex-table{width:100%;border-collapse:collapse;background:#111521;border:1px solid var(--line);border-radius:8px;overflow:hidden}
  .plan-ex-table th, .plan-ex-table td{padding:8px 10px;border-bottom:1px solid var(--line)}
  .plan-ex-table tr:last-child td{border-bottom:0}
</style>
</head>
<body>

<?php if ($flash): ?>
  <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>"><?php echo h($flash); ?></div>
<?php endif; ?>

<!-- Sticky subheader (matches clients.php) -->
<div class="subheader">
  <div class="left">
    <div class="brand">Workout Plans</div>
    <span class="muted">Build, assign, and manage plans</span>
  </div>
  <div class="btnset">
    <a class="btn" href="dashboard.php">Back to Dashboard</a>
    <a class="btn" href="exercises.php">Exercises</a>
    <a class="btn" href="invites.php">Manage Invites</a>
    <button class="btn brand" type="button" id="btnOpenCreatePlan">Create Plan</button>
  </div>
</div>

<main class="wrap">

  <div class="card">
    <h2 style="margin:6px 0 12px 0">Workout Plans</h2>
    <table>
      <thead>
        <tr>
          <th>Plan ID</th>
          <th>Plan Name</th>
          <th>Created</th>
          <th>Created By</th>
          <th>Edited</th>
          <th>Edited By</th>
          <th># Exercises</th>
          <th># Clients</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($plans)): ?>
        <tr><td colspan="9" class="muted">No plans yet.</td></tr>
      <?php else: foreach ($plans as $p):
        $pid = (int)$p['id'];
        $createdAt = $p['created_at'] ?? null;
        $createdBy = $p['created_by'] ?? null;
        $creator = '—';
        if ($createdBy) {
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
        $updatedAt = $p['updated_at'] ?? null;
        $updatedBy = $p['updated_by'] ?? null;
        $editor = '—';
        if ($updatedBy) {
          $q = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
          $q->bind_param("i", $updatedBy);
          $q->execute();
          $rs = $q->get_result();
          if ($u = $rs->fetch_assoc()) {
            $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
            $editor = $nm !== '' ? $nm : ($u['email'] ?? '—');
          }
          $q->close();
        }
      ?>
        <tr class="plan-row" id="plan-<?php echo $pid; ?>" data-plan="<?php echo $pid; ?>">
          <td><?php echo $pid; ?></td>
          <td><strong><?php echo h($p['name']); ?></strong></td>
          <td class="muted"><?php echo $createdAt ? h(date('M j, Y g:i A', strtotime($createdAt))) : '—'; ?></td>
          <td class="muted"><?php echo h($creator); ?></td>
          <td class="muted"><?php echo $updatedAt ? h(date('M j, Y g:i A', strtotime($updatedAt))) : '—'; ?></td>
          <td class="muted"><?php echo h($editor); ?></td>
          <td><?php echo (int)$p['exercise_count']; ?></td>
          <td><?php echo (int)$p['assigned_count']; ?></td>
          <td class="row-actions" data-actions>
            <button class="btn small" type="button" data-assign data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($p['name']); ?>">Assign</button>
            <button class="btn small" type="button" data-edit data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($p['name']); ?>">Edit</button>
            <button class="btn small warn" type="button" data-delete data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($p['name']); ?>">Delete</button>
          </td>
        </tr>
        <tr class="expand" id="exp-<?php echo $pid; ?>">
          <td colspan="9">
            <?php if (empty($plan_ex_rows[$pid])): ?>
              <div class="muted">No exercises in this plan.</div>
            <?php else: ?>
  <table style="width:100%;border-collapse:collapse;margin-top:6px">
    <thead>
      <tr>
        <th style="background:#0f1218">Exercise ID</th>
        <th style="background:#0f1218">Exercise</th>
        <th style="background:#0f1218">Description</th> <!-- Renamed from Notes -->
        <th style="background:#0f1218">Media</th>
        <th style="background:#0f1218">Created</th>
        <th style="background:#0f1218">Created By</th>
        <th style="background:#0f1218">Edited</th>
        <th style="background:#0f1218">Edited By</th>
        <th style="background:#0f1218">Used In # Plans</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($plan_ex_rows[$pid] as $row): ?>
      <?php
        $hasVideo   = !empty($row['video_url']);
        $hasCC      = !empty($row['captions_vtt_url']);
        $createdAt  = $row['created_at'] ?? null;
        $updatedAt  = $row['updated_at'] ?? null;

        // Display names (cached)
        $creator = user_display_name($conn, isset($row['created_by']) ? (int)$row['created_by'] : null);
        $editor  = user_display_name($conn, isset($row['updated_by']) ? (int)$row['updated_by'] : null);
      ?>
      <tr class="ex-line ex-click" data-ex-id="<?php echo (int)$row['exercise_id']; ?>" tabindex="0" title="Open on Exercises">
        <td style="width:120px"><?php echo (int)$row['exercise_id']; ?></td>
        <td><strong><?php echo h($row['name']); ?></strong></td>
        <td class="muted"><?php echo $row['notes'] ? nl2br(h($row['notes'])) : '—'; ?></td>

        <!-- MEDIA (match exercises.php appearance) -->
        <td title="<?php
          $tip = !empty($row['video_url'])
            ? ('Video' . (!empty($row['video_duration_sec']) ? ' • ' . (int)$row['video_duration_sec'] . 's' : ''))
            : 'No video';
          echo h($tip);
        ?>">
          <?php
            $hasVideo = !empty($row['video_url']);
            $hasCC    = !empty($row['captions_vtt_url']);
            $poster   = $row['video_poster_url'] ?? '';

            if ($hasVideo) {
              if ($poster) {
                echo '<div class="thumb-mini"><img src="'.h($poster).'" alt="thumb"></div>';
              } else {
                echo '<span class="chip">▶ Video'.($hasCC ? ' · CC' : '').'</span>';
              }
            } else {
              echo '<span class="muted">—</span>';
            }
          ?>
        </td>

        <!-- CREATED -->
        <td class="muted">
          <?php echo $createdAt ? h(date('M j, Y g:i A', strtotime($createdAt))) : '—'; ?>
        </td>
        <td class="muted"><?php echo h($creator); ?></td>

        <!-- EDITED -->
        <td class="muted">
          <?php echo $updatedAt ? h(date('M j, Y g:i A', strtotime($updatedAt))) : '—'; ?>
        </td>
        <td class="muted"><?php echo h($editor); ?></td>

        <!-- USED IN # PLANS -->
        <td><?php echo (int)$row['used_in_plans']; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- Backdrops -->
<div class="backdrop" id="bdCreate"></div>
<div class="backdrop" id="bdAssign"></div>
<div class="backdrop" id="bdEdit"></div>
<div class="backdrop" id="bdDelete"></div>

<!-- CREATE PLAN MODAL -->
<div class="modal" id="mdCreate" role="dialog" aria-modal="true" aria-labelledby="cpTitle">
  <h3 id="cpTitle">Create Workout Plan</h3>
  <form method="post" id="cpForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="create_plan_modal">
    <div class="row">
      <div style="grid-column:span 12">
        <label class="fine" for="cp_name">Plan Name</label>
        <input class="input" id="cp_name" name="plan_title" type="text" required>
      </div>

      <div class="listbox" style="grid-column:span 12">
        <!-- Available exercises -->
        <div class="box">
          <div class="fine" style="margin-bottom:6px">Available Exercises</div>
          <?php if (empty($exercises)): ?>
            <div class="muted">No exercises yet.</div>
          <?php else: foreach ($exercises as $ex): ?>
            <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:6px">
              <input type="checkbox" class="cpPick" value="<?php echo (int)$ex['id']; ?>">
              <span>
                <strong><?php echo h($ex['name']); ?></strong>
                <?php if (!empty($ex['notes'])): ?>
                  <div class="fine"><?php echo nl2br(h($ex['notes'])); ?></div>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; endif; ?>
        </div>

        <!-- Selected (ordered) -->
        <div class="box">
          <div class="fine" style="margin-bottom:6px">Plan Exercises (drag to re-order)</div>
          <div id="cpSelected"></div>
        </div>
      </div>
    </div>
    <div class="actions">
      <button class="btn" type="button" data-close="#mdCreate" data-backdrop="#bdCreate">Cancel</button>
      <button class="btn brand" type="submit">Create Plan</button>
      <button class="btn" type="button" id="cpAddSel">Add Selected</button>
      <button class="btn" type="button" id="cpClearSel">Clear</button>
    </div>
  </form>
</div>

<!-- ASSIGN MODAL -->
<div class="modal" id="mdAssign" role="dialog" aria-modal="true" aria-labelledby="apTitle">
  <h3 id="apTitle">Assign Plan to Users</h3>
  <form method="post" id="apForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="assign_plan">
    <input type="hidden" id="apPlanId" name="plan_id" value="">
    <div class="fine" id="apPlanName" style="margin-bottom:8px"></div>

    <div class="box" style="max-height:420px">
      <div class="user-list" id="apUserList">
        <?php if (empty($clients)): ?>
          <div class="muted">No clients found.</div>
        <?php else: foreach ($clients as $c):
          $label = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''));
          if ($label === '') $label = $c['email'];
          $uid = (int)$c['id'];
        ?>
          <div class="user-item" data-uid="<?php echo $uid; ?>">
            <label style="display:flex;gap:8px;align-items:flex-start">
              <input type="checkbox" class="apUserCheck" value="<?php echo $uid; ?>" name="assign_users[]">
              <span><?php echo h($label); ?> <span class="fine">(ID <?php echo $uid; ?>)</span></span>
            </label>
            <div class="ap-user-editor" style="display:none; margin:8px 0 0 24px"></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="actions">
      <button class="btn" type="button" data-close="#mdAssign" data-backdrop="#bdAssign">Cancel</button>
      <button class="btn brand" type="submit">Assign</button>
    </div>
  </form>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="mdEdit" role="dialog" aria-modal="true" aria-labelledby="epTitle">
  <h3 id="epTitle">Edit Workout Plan</h3>
  <form method="post" id="epForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="edit_plan_modal">
    <input type="hidden" id="epPlanId" name="plan_id" value="">
    <!-- Authoritative order field (robust fallback) -->
    <input type="hidden" id="epOrder" name="selected_order" value="">
    <div class="row">
      <div style="grid-column:span 12">
        <label class="fine" for="ep_name">Plan Name</label>
        <input class="input" id="ep_name" name="plan_title" type="text" required>
      </div>

      <div class="listbox" style="grid-column:span 12">
        <div class="box">
          <div class="fine" style="margin-bottom:6px">Available Exercises</div>
          <?php if (empty($exercises)): ?>
            <div class="muted">No exercises yet.</div>
          <?php else: foreach ($exercises as $ex): ?>
            <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:6px">
              <input type="checkbox" class="epPick" value="<?php echo (int)$ex['id']; ?>">
              <span>
                <strong><?php echo h($ex['name']); ?></strong>
                <?php if (!empty($ex['notes'])): ?>
                  <div class="fine"><?php echo nl2br(h($ex['notes'])); ?></div>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; endif; ?>
        </div>
        <div class="box">
          <div class="fine" style="margin-bottom:6px">Plan Exercises (drag to re-order)</div>
          <div id="epSelected"></div>
        </div>
      </div>
    </div>
    <div class="actions">
      <button class="btn" type="button" data-close="#mdEdit" data-backdrop="#bdEdit">Cancel</button>
      <button class="btn brand" type="submit">Save Changes</button>
      <button class="btn" type="button" id="epAddSel">Add Selected</button>
      <button class="btn" type="button" id="epClearSel">Clear</button>
    </div>
  </form>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="mdDelete" role="dialog" aria-modal="true" aria-labelledby="dpTitle">
  <h3 id="dpTitle">Delete Plan</h3>
  <form method="post" id="dpForm" onsubmit="return confirm('Delete this plan? It will be removed from clients and cannot be undone.');">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="delete_plan_modal">
    <input type="hidden" id="dpPlanId" name="plan_id" value="">
    <div class="fine" id="dpText" style="margin-bottom:10px"></div>
    <div class="actions">
      <button class="btn" type="button" data-close="#mdDelete" data-backdrop="#bdDelete">Cancel</button>
      <button type="submit" class="btn warn">Delete</button>
    </div>
  </form>
</div>

<script>
(function(){
  // -------------------- Row expand/collapse --------------------
  document.querySelectorAll('.plan-row').forEach(tr=>{
    tr.addEventListener('click', (e)=>{
      if (e.target.closest('[data-actions]')) return; // ignore clicks on action buttons
      const pid = tr.getAttribute('data-plan');
      const exp = document.getElementById('exp-'+pid);
      if (!exp) return;

      const isOpen = (exp.style.display === 'table-row');
      // toggle expansion
      exp.style.display = isOpen ? 'none' : 'table-row';
      // keep header row highlighted while open
      tr.classList.toggle('expanded', !isOpen);
    });
  });

  // --- If linked with ?focus_plan=123, scroll to, highlight, and expand that plan
  const params = new URLSearchParams(location.search);
  const focusId = params.get('focus_plan');
  if (focusId) {
    const row = document.querySelector(`.plan-row[data-plan="${CSS.escape(focusId)}"]`);
    const exp = document.getElementById(`exp-${focusId}`);
    if (row) {
      if (exp && exp.style.display !== 'table-row') exp.style.display = 'table-row';
      row.classList.add('expanded');    // persistent highlight while open
      row.classList.add('focused');     // optional momentary flash
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(()=> row.classList.remove('focused'), 2500); // keep expanded, drop flash
    }
  }

  // -------------------- Modal helpers -------------------------
  function openModal(modalSel, bdSel){ const m=document.querySelector(modalSel), b=document.querySelector(bdSel); if(!m||!b) return; m.style.display='block'; b.style.display='block'; document.body.style.overflow='hidden'; }
  function closeModal(modalSel, bdSel){ const m=document.querySelector(modalSel), b=document.querySelector(bdSel); if(!m||!b) return; m.style.display='none'; b.style.display='none'; document.body.style.overflow=''; }
  document.querySelectorAll('[data-close]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      closeModal(btn.getAttribute('data-close'), btn.getAttribute('data-backdrop'));
    });
  });

  // -------------------- Generic helpers -----------------------
  function getCurrentIds(container){
    return Array.from(container.querySelectorAll('.ex-row'))
      .map(x=>parseInt(x.dataset.id,10))
      .filter(n=>!isNaN(n) && n>0);
  }

  // Rebuild hidden inputs inside the *form that contains container*
  function syncSelected(container){
    const form = container.closest('form');
    if (!form) return;

    // clear old
    form.querySelectorAll('input[name="selected_exercises[]"]').forEach(el=>el.remove());

    // add fresh fields
    getCurrentIds(container).forEach(id=>{
      const inp = document.createElement('input');
      inp.type='hidden'; inp.name='selected_exercises[]'; inp.value=String(id);
      form.appendChild(inp);
    });

    // for the Edit form, also fill the authoritative order string
    if (form.id === 'epForm') {
      const orderField = document.getElementById('epOrder');
      if (orderField) orderField.value = getCurrentIds(container).join(',');
    }
  }

  function renderSelectedList(container, ids){
    container.innerHTML = '';
    ids.forEach((id)=>{
      const row = document.createElement('div');
      row.className = 'ex-row';
      row.draggable = true;
      row.dataset.id = id;
      row.innerHTML = `
        <span class="handle">☰</span>
        <span><strong>${escapeHtml(window.__EXERCISE_MAP[id]||('Exercise #'+id))}</strong>
          <span class="fine"> (ID ${id})</span>
        </span>
        <a class="xlink" style="margin-left:auto" href="#" data-remove>Remove</a>
      `;
      container.appendChild(row);
    });

    // drag + drop reordering
    container.querySelectorAll('.ex-row').forEach(item=>{
      item.addEventListener('dragstart', e=>{ e.dataTransfer.setData('text/plain', item.dataset.id); item.classList.add('dragging'); });
      item.addEventListener('dragend', ()=> { item.classList.remove('dragging'); syncSelected(container); });
    });
    container.addEventListener('dragover', e=>{
      e.preventDefault();
      const dragging = container.querySelector('.dragging');
      if (!dragging) return;
      const after = Array.from(container.querySelectorAll('.ex-row:not(.dragging)')).find(el=>{
        const rect = el.getBoundingClientRect();
        return e.clientY < rect.top + rect.height/2;
      });
      if (after) container.insertBefore(dragging, after);
      else container.appendChild(dragging);
      syncSelected(container); // keep order current while dragging
    });

    container.querySelectorAll('[data-remove]').forEach(a=>{
      a.addEventListener('click', (e)=>{ e.preventDefault(); a.closest('.ex-row')?.remove(); syncSelected(container); });
    });

    syncSelected(container); // initial sync
  }

  // -------------------- Create Plan modal ---------------------
  const btnOpenCreate = document.getElementById('btnOpenCreatePlan');
  btnOpenCreate?.addEventListener('click', ()=> openModal('#mdCreate','#bdCreate'));

  const cpSel = document.getElementById('cpSelected');
  const cpPicks = document.querySelectorAll('.cpPick');
  const cpAddSel = document.getElementById('cpAddSel');
  const cpClearSel = document.getElementById('cpClearSel');

  cpAddSel?.addEventListener('click', ()=>{
    const chosen = Array.from(cpPicks).filter(x=>x.checked).map(x=>parseInt(x.value,10));
    const cur = new Set(getCurrentIds(cpSel));
    chosen.forEach(id=>{ if(!cur.has(id)) cur.add(id); });
    renderSelectedList(cpSel, Array.from(cur));
  });
  cpClearSel?.addEventListener('click', ()=>{
    cpSel.innerHTML=''; syncSelected(cpSel);
    document.querySelectorAll('.cpPick').forEach(c=>c.checked=false);
  });

  // -------------------- Edit modal ---------------------------
  const epSel = document.getElementById('epSelected');
  const epAddSel = document.getElementById('epAddSel');
  const epClearSel = document.getElementById('epClearSel');
  const epForm = document.getElementById('epForm');

  document.querySelectorAll('[data-edit]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const pid = parseInt(btn.getAttribute('data-plan-id'),10);
      const pname = btn.getAttribute('data-plan-name') || '';
      document.getElementById('epPlanId').value = pid;
      document.getElementById('ep_name').value = pname;

      const ids = (window.__PLAN_EXERCISES[pid] || []);
      renderSelectedList(epSel, ids);
      document.querySelectorAll('.epPick').forEach(c=>c.checked=false);

      openModal('#mdEdit','#bdEdit');
    });
  });

  // force final sync right before submit
  epForm?.addEventListener('submit', ()=>{ syncSelected(epSel); });

  epAddSel?.addEventListener('click', ()=>{
    const chosen = Array.from(document.querySelectorAll('.epPick')).filter(x=>x.checked).map(x=>parseInt(x.value,10));
    const cur = new Set(getCurrentIds(epSel));
    chosen.forEach(id=>{ if(!cur.has(id)) cur.add(id); });
    renderSelectedList(epSel, Array.from(cur));
  });
  epClearSel?.addEventListener('click', ()=>{
    epSel.innerHTML=''; syncSelected(epSel);
    document.querySelectorAll('.epPick').forEach(c=>c.checked=false);
  });

  // -------------------- Delete modal -------------------------
  document.querySelectorAll('[data-delete]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const pid = btn.getAttribute('data-plan-id');
      const pname = btn.getAttribute('data-plan-name') || ('Plan #'+pid);
      document.getElementById('dpPlanId').value = pid;
      document.getElementById('dpText').textContent = `Delete "${pname}"? This action cannot be undone.`;
      openModal('#mdDelete','#bdDelete');
    });
  });

  // -------------------- Assign modal -------------------------
  const apPlanId = document.getElementById('apPlanId');
  const apPlanName = document.getElementById('apPlanName');
  const apUserList = document.getElementById('apUserList');

  document.querySelectorAll('[data-assign]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const pid = parseInt(btn.getAttribute('data-plan-id'),10);
      const pname = btn.getAttribute('data-plan-name') || '';
      apPlanId.value = pid;
      apPlanName.textContent = `Plan: ${pname} (ID ${pid})`;
      // clear any previous checks/editors
      apUserList.querySelectorAll('.apUserCheck').forEach(chk=>{
        chk.checked = false;
        const ed = chk.closest('.user-item').querySelector('.ap-user-editor');
        if (ed){ ed.style.display='none'; ed.innerHTML=''; }
      });
      openModal('#mdAssign','#bdAssign');
    });
  });

  // Build per-user editor when checked
  apUserList?.addEventListener('change', (e)=>{
    const chk = e.target.closest('.apUserCheck'); if(!chk) return;
    const uid = parseInt(chk.value,10);
    const sect = chk.closest('.user-item').querySelector('.ap-user-editor');
    if (!sect) return;
    if (chk.checked){
      sect.style.display = 'block';
      if (!sect.hasChildNodes()) buildUserEditor(uid, parseInt(apPlanId.value,10));
    } else {
      sect.style.display = 'none';
      sect.innerHTML = '';
    }
  });

  function buildUserEditor(uid, planId){
    const container = document.querySelector(`.user-item[data-uid="${uid}"] .ap-user-editor`);
    if (!container) return;
    const exIds = (window.__PLAN_EXERCISES[planId] || []);
    if (!exIds.length) {
      container.innerHTML = `<div class="muted">This plan has no exercises.</div>`;
      return;
    }
    let html = `
      <table class="plan-ex-table" style="margin-top:6px">
        <thead>
          <tr>
            <th style="width:110px">Exercise ID</th>
            <th>Exercise</th>
            <th style="width:90px">Sets</th>
            <th style="width:90px">Reps</th>
            <th style="width:160px">Duration</th>
            <th style="width:110px">Weight</th>
            <th style="width:220px">Notes</th>
          </tr>
        </thead>
        <tbody>
    `;
    exIds.forEach(eid=>{
      const name = (window.__EXERCISE_MAP && window.__EXERCISE_MAP[eid]) ? window.__EXERCISE_MAP[eid] : ('Exercise #'+eid);
      html += `
        <tr>
          <td>${eid}</td>
          <td>${escapeHtml(name)}</td>
          <td><input class="input" type="number" min="0" name="sets[${uid}][${eid}]" placeholder=""></td>
          <td><input class="input" type="number" min="0" name="reps[${uid}][${eid}]" placeholder=""></td>
          <td><input class="input" type="text"   name="duration[${uid}][${eid}]" placeholder="mm:ss or sec"></td>
          <td><input class="input" type="text"   name="weight[${uid}][${eid}]" placeholder="e.g., 95 or 2.5"></td>
          <td><textarea class="input" name="user_notes[${uid}][${eid}]" rows="2" placeholder="Any user-specific instructions..."></textarea></td>
        </tr>
      `;
    });
    html += `</tbody></table>`;
    container.innerHTML = html;
  }

  // Click on exercise row -> open exercises.php with focus
  document.addEventListener('click', (e)=>{
    const row = e.target.closest('tr.ex-click');
    if (!row) return;
    if (e.target.closest('a,button,input,textarea,select,label')) return;
    const exId = row.getAttribute('data-ex-id');
    if (exId)
      location.href = 'exercises.php?focus_exercise=' + encodeURIComponent(exId) + '#ex-' + encodeURIComponent(exId);
  });

  document.addEventListener('keydown', (e)=>{
    const row = e.target.closest('tr.ex-click');
    if (!row) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      const exId = row.getAttribute('data-ex-id');
      if (exId) location.href = 'exercises.php?focus_exercise=' + encodeURIComponent(exId);
    }
  });

  // -------------------- Data for JS --------------------------
  window.__EXERCISE_MAP = <?php echo json_encode($EXERCISE_MAP, JSON_UNESCAPED_SLASHES); ?>;
  window.__PLAN_EXERCISES = <?php echo json_encode($plan_ex_map, JSON_UNESCAPED_SLASHES); ?>;

  // --- Auto-open Assign modal if plan_id & assign_to_user are in the URL
  (function(){
    const p = new URLSearchParams(location.search);
    const planId = p.get('plan_id');
    const assignUid = p.get('assign_to_user');

    if (!planId) return;
    // Open the Assign modal for that plan
    const assignBtn = document.querySelector(`[data-assign][data-plan-id="${CSS.escape(planId)}"]`);
    if (!assignBtn) return;

    // Trigger the same flow as clicking "Assign"
    assignBtn.click();

    // After modal opens, pre-check the given user (if present) and build their editor
    if (assignUid) {
      setTimeout(()=>{
        const chk = document.querySelector(`#mdAssign .apUserCheck[value="${CSS.escape(assignUid)}"]`);
        if (chk && !chk.checked) {
          chk.checked = true;
          // Fire change to build editor section
          chk.dispatchEvent(new Event('change', {bubbles:true}));
          // Scroll them into view for convenience
          chk.closest('.user-item')?.scrollIntoView({block:'center'});
        }
      }, 0);
    }
  })();

  // --- Auto-open Edit modal if ?plan_id=...&edit_plan=1 is in the URL
  (function(){
    const p = new URLSearchParams(location.search);
    const planId = p.get('plan_id');
    const wantEdit = p.get('edit_plan');
    if (!planId || !wantEdit) return;

    // Click the same button the user would click
    const editBtn = document.querySelector(`[data-edit][data-plan-id="${CSS.escape(planId)}"]`);
    if (editBtn) editBtn.click();
  })();

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
})();
</script>
</body>
</html>