<?php
// clients.php — Active/Inactive tabs, inline edit, invite/resend, deactivate/reactivate, row-expand plans + unassign.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_lockout.php'; // unlock action

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_trainer_admin($role){ return in_array($role ?? 'guest', ['trainer','admin'], true); }
if (!is_trainer_admin($USER_ROLE ?? null)) { http_response_code(403); echo 'Forbidden'; exit; }

// --- Ensure is_active exists ---
function ensure_is_active_column(mysqli $conn): void {
  $sql = "SELECT COUNT(*) AS c
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'is_active'";
  $res = $conn->query($sql);
  $row = $res ? $res->fetch_assoc() : null;
  $exists = (int)($row['c'] ?? 0) > 0;

  if (!$exists) {
    @$conn->query("ALTER TABLE users
                  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
                  ADD INDEX idx_is_active (is_active)");
  }
}

function ppf_column_exists_uncached(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT COUNT(*) AS cnt
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?";
  if (!$stmt = $conn->prepare($sql)) return false;
  $stmt->bind_param("ss", $table, $column);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return (int)($row['cnt'] ?? 0) > 0;
}

function ensure_user_plan_exercise_tracking_columns(mysqli $conn): void {
  if (!ppf_column_exists_uncached($conn, 'user_plan_exercises', 'updated_at')) {
    @$conn->query("ALTER TABLE user_plan_exercises ADD COLUMN updated_at DATETIME NULL");
  }
  if (!ppf_column_exists_uncached($conn, 'user_plan_exercises', 'updated_by')) {
    @$conn->query("ALTER TABLE user_plan_exercises ADD COLUMN updated_by INT NULL");
  }
}

function ppf_parse_duration_to_seconds($input): ?int {
  $s = trim((string)$input);
  if ($s === '') return null;

  $lower = strtolower($s);

  // Handle mm:ss style values (e.g. 1:30, 01:45)
  if (preg_match('/^(\d+):(\d{1,2})$/', $lower, $m)) {
    $minutes = (int)$m[1];
    $seconds = (int)$m[2];
    return ($minutes * 60) + $seconds;
  }

  // Handle zero-padded mmss (e.g. 0045, 0100)
  if (ctype_digit($s)) {
    if (strlen($s) === 4 && $s[0] === '0') {
      $minutes = (int)substr($s, 0, 2);
      $seconds = (int)substr($s, 2, 2);
      return ($minutes * 60) + $seconds;
    }
    return (int)$s;
  }

  // Handle expressions like "1 min 30 sec", "45seconds", "1minute", etc.
  $normalized = preg_replace('/[^0-9a-z]+/i', ' ', $lower);
  $normalized = trim(preg_replace('/\s+/', ' ', $normalized));
  if ($normalized === '') return null;

  if (preg_match_all('/(\d+)\s*(hours?|hrs?|h|minutes?|mins?|min|m|seconds?|secs?|sec|s)/', $normalized, $matches, PREG_SET_ORDER)) {
    $total = 0;
    foreach ($matches as $match) {
      $value = (int)$match[1];
      $unit = $match[2];
      if (preg_match('/^h/', $unit)) {
        $total += $value * 3600;
      } elseif (preg_match('/^m/', $unit) || preg_match('/min/', $unit)) {
        $total += $value * 60;
      } else {
        $total += $value;
      }
    }
    return $total;
  }

  return null;
}

function ppf_trim_number(float $value, int $precision = 2): string {
  $formatted = number_format($value, $precision, '.', '');
  $formatted = rtrim(rtrim($formatted, '0'), '.');
  if ($formatted === '') $formatted = '0';
  return $formatted;
}

function ppf_format_weight_lbs($value): ?string {
  if ($value === null || $value === '') return null;
  if (!is_numeric($value)) return null;
  $num = (float)$value;
  return ppf_trim_number($num) . ' lbs';
}

function ppf_format_duration_display($seconds): ?string {
  if ($seconds === null || $seconds === '') return null;
  $total = (int)$seconds;
  if ($total < 0) $total = 0;
  $minutes = intdiv($total, 60);
  $secs = $total % 60;
  $parts = [];
  if ($minutes > 0) {
    $parts[] = $minutes . ' min' . ($minutes === 1 ? '' : 's');
  }
  if ($secs > 0 || !$parts) {
    $parts[] = $secs . ' sec' . ($secs === 1 ? '' : 's');
  }
  return implode(' ', $parts);
}

function ppf_parse_weight_to_float($input): ?float {
  $s = trim((string)$input);
  if ($s === '') return null;
  if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
  return (float)$s;
}

function ppf_parse_category_concat(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];

  $parts = explode('||', $raw);
  $out = [];
  foreach ($parts as $part) {
    $part = trim($part);
    if ($part === '') continue;

    $id = null;
    $name = $part;
    if (strpos($part, '::') !== false) {
      [$idRaw, $nameRaw] = explode('::', $part, 2);
      $nameRaw = trim($nameRaw ?? '');
      if ($nameRaw === '') continue;
      $id = is_numeric($idRaw) ? (int)$idRaw : null;
      $name = $nameRaw;
    }

    $name = trim($name);
    if ($name === '') continue;
    $out[] = ['id' => $id, 'name' => $name];
  }

  return $out;
}

// ---------- CSRF ----------
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'] ?? null;

// Which tab?
$tab = ($_GET['tab'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

ensure_is_active_column($conn);
ensure_user_plan_exercise_tracking_columns($conn);

$HAS_UPE_UPDATED_AT = ppf_column_exists_uncached($conn, 'user_plan_exercises', 'updated_at');
$HAS_UPE_UPDATED_BY = ppf_column_exists_uncached($conn, 'user_plan_exercises', 'updated_by');

// ---------- POST Actions ----------
$flash = null; $flash_type = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $t = $_POST['csrf_token'] ?? '';
  if (!$csrf || !hash_equals($csrf, $t)) {
    $flash = 'Your session expired or the page did not include a valid session token. Please try again.'; $flash_type = 'err';
  } else {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    try {
      if ($action === 'bulk_action') {
        if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
          throw new Exception('You do not have permission to perform bulk actions.');
        }

        $bulkType = trim($_POST['bulk_type'] ?? '');

        if ($bulkType === 'bulk_unassign') {
          $planRaw = $_POST['plan_ids'] ?? [];
          if (!is_array($planRaw)) $planRaw = [$planRaw];
          $planIds = [];
          foreach ($planRaw as $planVal) {
            $pid = (int)$planVal;
            if ($pid > 0) $planIds[$pid] = $pid;
          }
          $planIds = array_values($planIds);
          if (!$planIds) {
            throw new Exception('Select at least one plan to unassign.');
          }
          $planList = implode(',', array_map('intval', $planIds));

          $validPlans = [];
          $sqlPlan = "SELECT up.id FROM user_plans up JOIN users u ON u.id = up.user_id WHERE up.id IN ($planList) AND (u.role='client' OR u.is_client=1)";
          if ($resPlan = $conn->query($sqlPlan)) {
            while ($row = $resPlan->fetch_assoc()) {
              $validPlans[] = (int)$row['id'];
            }
          }
          if (!$validPlans) {
            throw new Exception('Selected plans could not be found.');
          }

          $validList = implode(',', array_map('intval', $validPlans));

          $conn->begin_transaction();
          try {
            $conn->query("DELETE FROM user_plan_exercises WHERE user_plan_id IN ($validList)");
            $conn->query("DELETE FROM user_plans WHERE id IN ($validList)");
            $conn->commit();
            $count = count($validPlans);
            $flash = $count === 1 ? 'Unassigned 1 plan.' : ('Unassigned ' . $count . ' plans.');
            $flash_type = 'ok';
          } catch (Throwable $e) {
            $conn->rollback();
            throw new Exception('Failed to unassign selected plans.');
          }
        } elseif ($bulkType === 'bulk_deactivate') {
          $idsRaw = $_POST['user_ids'] ?? [];
          if (!is_array($idsRaw)) $idsRaw = [$idsRaw];
          $ids = [];
          foreach ($idsRaw as $idRaw) {
            $idVal = (int)$idRaw;
            if ($idVal > 0) $ids[$idVal] = $idVal;
          }
          $ids = array_values($ids);
          if (!$ids) {
            throw new Exception('Select at least one client.');
          }
          $idList = implode(',', array_map('intval', $ids));

          $sql = "UPDATE users SET is_active=0 WHERE id IN ($idList) AND (role='client' OR is_client=1)";
          if (!$conn->query($sql)) {
            throw new Exception('Failed to deactivate selected clients.');
          }
          $flash = 'Selected clients deactivated.';
          $flash_type = 'ok';
        } else {
          throw new Exception('Choose a bulk action to run.');
        }
      }

      if ($action === 'update_client') {
        if ($uid <= 0) throw new Exception('Invalid client.');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $birthdate  = trim($_POST['birthdate'] ?? '');
        $gender     = trim($_POST['gender'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name= trim($_POST['middle_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');

        $height_ft  = isset($_POST['height_ft'])  ? trim($_POST['height_ft'])  : '';
        $height_in  = isset($_POST['height_in'])  ? trim($_POST['height_in'])  : '';
        $weight_lbs = isset($_POST['weight_lbs']) ? trim($_POST['weight_lbs']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Valid email required.');
        if ($birthdate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) throw new Exception('Birthdate must be YYYY-MM-DD.');

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $stmt->bind_param("si", $email, $uid);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); throw new Exception('Email already in use by another user.'); }
        $stmt->close();

        $hf = ($height_ft === '' ? null : (int)$height_ft);
        $hi = ($height_in === '' ? null : (int)$height_in);
        if ($hi !== null) { if ($hi < 0) $hi = 0; if ($hi > 11) $hi = 11; }
        if ($hf !== null) { if ($hf < 0) $hf = 0; if ($hf > 8) $hf = 8; }
        $wl = ($weight_lbs === '' ? null : (float)$weight_lbs);
        if ($wl !== null && $wl <= 0) $wl = null;

        $stmt = $conn->prepare("
          UPDATE users
          SET email=?, phone=?, birthdate=?, gender=?, first_name=?, middle_name=?, last_name=?, height_ft=?, height_in=?, weight_lbs=?
          WHERE id=? AND (role='client' OR is_client=1)
        ");
        if (!$stmt) throw new Exception('Failed to prepare update.');

        $bdate = ($birthdate ?: null);
        $gend  = ($gender ?: null);
        $fn    = ($first_name ?: null);
        $mn    = ($middle_name ?: null);
        $ln    = ($last_name ?: null);

        $stmt->bind_param("sssssssiidi", $email, $phone, $bdate, $gend, $fn, $mn, $ln, $hf, $hi, $wl, $uid);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to update client.'); }
        $stmt->close();

        $flash = 'Client updated.'; $flash_type = 'ok';
      }

      // Append one or more exercises to an existing plan (AJAX, no navigation)
      if ($action === 'add_exercises_to_plan') {
        if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
          throw new Exception('You do not have permission to edit plans.');
        }

        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $raw = $_POST['exercise_ids'] ?? [];
        if (is_string($raw)) {
          $tmp = json_decode($raw, true);
          if (is_array($tmp)) $raw = $tmp;
        }
        if (!is_array($raw)) $raw = [$raw];
        $exercise_ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($n)=>$n>0)));

        if ($plan_id <= 0 || !$exercise_ids) throw new Exception('Invalid input.');

        $maxPos = 0;
        if ($res = $conn->query("SELECT COALESCE(MAX(position),0) AS m FROM plan_exercises WHERE plan_id = ".(int)$plan_id)) {
          if ($row = $res->fetch_assoc()) $maxPos = (int)$row['m'];
        }

        $existing = [];
        if ($res = $conn->query("SELECT exercise_id FROM plan_exercises WHERE plan_id = ".(int)$plan_id)) {
          while ($r = $res->fetch_assoc()) $existing[(int)$r['exercise_id']] = true;
        }
        $toInsert = array_values(array_filter($exercise_ids, fn($eid)=>empty($existing[$eid])));

        if (!$toInsert) {
          header('Content-Type: application/json');
          echo json_encode(['ok'=>true, 'added'=>[]]); exit;
        }

        $stmt = $conn->prepare("INSERT INTO plan_exercises (plan_id, exercise_id, position) VALUES (?, ?, ?)");
        $conn->begin_transaction();
        try {
          foreach ($toInsert as $eid) {
            $maxPos++;
            $stmt->bind_param("iii", $plan_id, $eid, $maxPos);
            if (!$stmt->execute()) throw new Exception('Failed to insert exercise.');
          }
          $conn->commit();
        } catch (Throwable $e) {
          $conn->rollback(); $stmt->close(); throw $e;
        }
        $stmt->close();

        $idsIn = implode(',', array_map('intval', $toInsert));
        $newRows = [];
        if ($idsIn !== '') {
          $q = "
            SELECT
              e.id AS ex_id,
              e.name,
              e.notes,
              e.video_url,
              GROUP_CONCAT(DISTINCT CONCAT(c.id, '::', c.name) ORDER BY c.name SEPARATOR '||') AS categories
            FROM exercises e
            LEFT JOIN exercise_categories ec ON ec.exercise_id = e.id
            LEFT JOIN categories c ON c.id = ec.category_id
            WHERE e.id IN ($idsIn)
            GROUP BY e.id
          ";
          if ($res = $conn->query($q)) {
            while ($r = $res->fetch_assoc()) {
              $videoUrl = (string)($r['video_url'] ?? '');
              $catRaw = (string)($r['categories'] ?? '');
              $catList = ppf_parse_category_concat($catRaw);
              $newRows[] = [
                'ex_id' => (int)$r['ex_id'],
                'name'  => (string)$r['name'],
                'notes' => (string)($r['notes'] ?? ''),
                'has_video' => $videoUrl !== '',
                'video_url' => $videoUrl,
                'categories' => $catList,
                'updated_at' => null,
                'updated_by_name' => '—'
              ];
            }
          }
        }

        header('Content-Type: application/json');
        echo json_encode(['ok'=>true, 'added'=>$newRows]);
        exit;
      }

      if ($action === 'invite_client' || $action === 'resend_invite') {
        if ($uid <= 0) throw new Exception('Invalid client.');
        $stmt = $conn->prepare("SELECT id,email,first_name,last_name,password_hash FROM users WHERE id=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $rs = $stmt->get_result(); $row = $rs->fetch_assoc();
        $stmt->close();
        if (!$row) throw new Exception('Client not found.');
        if (!empty($row['password_hash'])) throw new Exception('This user already has a password.');
        $flash = ($action === 'invite_client') ? 'Invite sent.' : 'Invite re-sent.'; $flash_type = 'ok';
      }

      // Deactivate
      if ($action === 'deactivate_client') {
        if ($uid <= 0) throw new Exception('Invalid client.');
        $stmt = $conn->prepare("UPDATE users SET is_active=0 WHERE id=? AND role='client'");
        $stmt->bind_param("i", $uid);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to deactivate client.'); }
        $stmt->close();
        $flash = 'Client deactivated.'; $flash_type = 'ok';
      }

      // Reactivate
      if ($action === 'reactivate_client') {
        if ($uid <= 0) throw new Exception('Invalid client.');
        $stmt = $conn->prepare("UPDATE users SET is_active=1 WHERE id=? AND role='client'");
        $stmt->bind_param("i", $uid);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to reactivate client.'); }
        $stmt->close();
        $flash = 'Client reactivated.'; $flash_type = 'ok';
      }

      // Admin unlock (lockout feature)
      if ($action === 'unlock_user') {
        if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
          throw new Exception('You do not have permission to unlock accounts.');
        }
        if ($uid <= 0) throw new Exception('Invalid client.');
        if (!ppf_admin_unlock_user($conn, $uid, (int)$USER_ID, (string)$USER_EMAIL)) {
          throw new Exception('Unable to unlock account (it may already be unlocked).');
        }
        $flash = 'Account unlocked.'; $flash_type = 'ok';
      }

        // Save per-client exercise settings (AJAX) — NOW also supports user_notes
        if ($action === 'save_user_exercise') {
          try {
            if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
              throw new Exception('You do not have permission to edit exercise settings.');
            }

            $plan_id     = (int)($_POST['plan_id'] ?? 0);
            $exercise_id = (int)($_POST['exercise_id'] ?? 0);
            if ($uid <= 0 || $plan_id <= 0 || $exercise_id <= 0) {
              throw new Exception('Invalid input.');
            }

            $sets  = trim($_POST['sets'] ?? '');
            $reps  = trim($_POST['reps'] ?? '');
            $weight_lbs = trim($_POST['weight_lbs'] ?? '');
            $duration_seconds = trim($_POST['duration_seconds'] ?? '');
            $user_notes = isset($_POST['user_notes']) ? trim($_POST['user_notes']) : '';

            $setsVal = ($sets === '' ? null : $sets);
            $repsVal = ($reps === '' ? null : $reps);

            $wtVal = null;
            if ($weight_lbs !== '') {
              $wtVal = ppf_parse_weight_to_float($weight_lbs);
              if ($wtVal === null) {
                throw new Exception('Weight must be numeric (digits with optional decimal).');
              }
            }

            $durVal = null;
            if ($duration_seconds !== '') {
              $durVal = ppf_parse_duration_to_seconds($duration_seconds);
              if ($durVal === null) {
                throw new Exception('Duration must be seconds or mm:ss (for example 90 or 1:30).');
              }
            }

            $notesVal = ($user_notes === '' ? null : $user_notes);

            // Find the user_plans.id for (user, plan)
            $up_id = null;
            $q1 = $conn->prepare("SELECT id FROM user_plans WHERE user_id=? AND plan_id=? LIMIT 1");
            $q1->bind_param("ii", $uid, $plan_id);
            $q1->execute();
            $res1 = $q1->get_result();
            if ($row = $res1->fetch_assoc()) $up_id = (int)$row['id'];
            $q1->close();

            if (!$up_id) throw new Exception('Plan is not assigned to this user.');

            // Does a row exist for this exercise?
            $upe_id = null;
            $q2 = $conn->prepare("SELECT id FROM user_plan_exercises WHERE user_plan_id=? AND exercise_id=? LIMIT 1");
            $q2->bind_param("ii", $up_id, $exercise_id);
            $q2->execute();
            $res2 = $q2->get_result();
            if ($row = $res2->fetch_assoc()) $upe_id = (int)$row['id'];
            $q2->close();

            $updaterId = isset($USER_ID) ? (int)$USER_ID : null;

            if ($upe_id) {
              if ($HAS_UPE_UPDATED_AT && $HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, updated_at=NOW(), updated_by=?
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdisii", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $updaterId, $upe_id);
                } else {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, updated_at=NOW(), updated_by=NULL
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdisi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $upe_id);
                }
              } elseif ($HAS_UPE_UPDATED_AT) {
                $q3 = $conn->prepare("
                  UPDATE user_plan_exercises
                  SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, updated_at=NOW()
                  WHERE id=?
                ");
                $q3->bind_param("ssdisi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $upe_id);
              } elseif ($HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, updated_by=?
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdisii", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $updaterId, $upe_id);
                } else {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, updated_by=NULL
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdisi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $upe_id);
                }
              } else {
                $q3 = $conn->prepare("
                  UPDATE user_plan_exercises
                  SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?
                  WHERE id=?
                ");
                $q3->bind_param("ssdisi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $upe_id);
              }
              if (!$q3) throw new Exception('Failed to prepare update.');
              if (!$q3->execute()) {
                $err = $q3->error;
                $q3->close();
                throw new Exception('Failed to update settings. '.$err);
              }
              $q3->close();
            } else {
              if ($HAS_UPE_UPDATED_AT && $HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                  ");
                  $q4->bind_param("iissdisi", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $updaterId);
                } else {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NULL)
                  ");
                  $q4->bind_param("iissdis", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal);
                }
              } elseif ($HAS_UPE_UPDATED_AT) {
                $q4 = $conn->prepare("
                  INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $q4->bind_param("iissdis", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal);
              } elseif ($HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                  ");
                  $q4->bind_param("iissdisi", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $updaterId);
                } else {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
                  ");
                  $q4->bind_param("iissdis", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal);
                }
              } else {
                $q4 = $conn->prepare("
                  INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes)
                  VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $q4->bind_param("iissdis", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal);
              }
              if (!$q4) throw new Exception('Failed to prepare save.');
              if (!$q4->execute()) {
                $err = $q4->error;
                $q4->close();
                throw new Exception('Failed to save settings. '.$err);
              }
              $upe_id = (int)$q4->insert_id;
              $q4->close();
            }

            $metaUpdatedAt = null;
            $metaUpdatedById = null;
            if ($upe_id && ($HAS_UPE_UPDATED_AT || $HAS_UPE_UPDATED_BY)) {
              $cols = [];
              if ($HAS_UPE_UPDATED_AT) $cols[] = 'updated_at';
              if ($HAS_UPE_UPDATED_BY) $cols[] = 'updated_by';
              $selectCols = implode(', ', $cols);
              if ($selectCols !== '') {
                $metaStmt = $conn->prepare("SELECT {$selectCols} FROM user_plan_exercises WHERE id=? LIMIT 1");
                if ($metaStmt) {
                  $metaStmt->bind_param("i", $upe_id);
                  if ($metaStmt->execute()) {
                    $metaRes = $metaStmt->get_result();
                    if ($metaRes && ($metaRow = $metaRes->fetch_assoc())) {
                      if ($HAS_UPE_UPDATED_AT && array_key_exists('updated_at', $metaRow)) {
                        $metaUpdatedAt = $metaRow['updated_at'] ?? null;
                      }
                      if ($HAS_UPE_UPDATED_BY && array_key_exists('updated_by', $metaRow)) {
                        $metaUpdatedById = isset($metaRow['updated_by']) ? (int)$metaRow['updated_by'] : null;
                      }
                    }
                  }
                  $metaStmt->close();
                }
              }
            }

            $editedAtDisp = ($HAS_UPE_UPDATED_AT && $metaUpdatedAt) ? format_us_datetime($metaUpdatedAt) : null;
            $editedByName = ($HAS_UPE_UPDATED_BY && $metaUpdatedById) ? user_display_name($conn, $metaUpdatedById) : null;

            $weightDisplay = $wtVal !== null ? ppf_format_weight_lbs($wtVal) : null;
            $durationDisplay = $durVal !== null ? ppf_format_duration_display($durVal) : null;

            header('Content-Type: application/json');
            echo json_encode([
              'ok' => true,
              'data' => [
                'sets' => $setsVal,
                'reps' => $repsVal,
                'weight_value' => $wtVal,
                'weight_display' => $weightDisplay,
                'duration_seconds' => $durVal,
                'duration_display' => $durationDisplay,
                'notes' => $notesVal,
                'updated_at' => $editedAtDisp,
                'updated_by_name' => $editedByName
              ]
            ]);
            exit;
          } catch (Throwable $e) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
          }
        }

      // Assign a plan to a user (AJAX, no navigation)
      if ($action === 'assign_plan_to_user') {
        if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
          throw new Exception('You do not have permission to assign plans.');
        }

        $plan_id = (int)($_POST['plan_id'] ?? 0);
        if ($uid <= 0 || $plan_id <= 0) throw new Exception('Invalid input.');

        $st = $conn->prepare("SELECT id FROM user_plans WHERE user_id=? AND plan_id=? LIMIT 1");
        $st->bind_param("ii", $uid, $plan_id);
        $st->execute();
        $rs = $st->get_result();
        if ($rs && $rs->fetch_assoc()) {
          $st->close();
          header('Content-Type: application/json');
          echo json_encode(['ok'=>true, 'already_assigned'=>true]);
          exit;
        }
        $st->close();

        $ins = $conn->prepare("INSERT INTO user_plans (user_id, plan_id) VALUES (?, ?)");
        $ins->bind_param("ii", $uid, $plan_id);
        if (!$ins->execute()) { $ins->close(); throw new Exception('Failed to assign plan.'); }
        $ins->close();

        $pname = 'Plan #'.$plan_id;
        if ($r = $conn->query("SELECT name FROM workout_plans WHERE id=".(int)$plan_id)) {
          if ($row = $r->fetch_assoc()) $pname = (string)$row['name'];
        }

        $exCount = 0;
        if ($r = $conn->query("SELECT COUNT(*) AS c FROM plan_exercises WHERE plan_id=".(int)$plan_id)) {
          if ($row = $r->fetch_assoc()) $exCount = (int)$row['c'];
        }

        $assigned_at_fmt = null;
        if ($r = $conn->query("SELECT assigned_at FROM user_plans WHERE user_id=".(int)$uid." AND plan_id=".(int)$plan_id." ORDER BY id DESC LIMIT 1")) {
          if ($row = $r->fetch_assoc()) {
            $iso = $row['assigned_at'] ?? null;
            if ($iso) {
              try {
                $dt = new DateTime($iso);
                $assigned_at_fmt = $dt->format('M j, Y g:i A');
              } catch (Throwable $e) { $assigned_at_fmt = null; }
            }
          }
        }

        header('Content-Type: application/json');
        echo json_encode([
          'ok' => true,
          'plan' => [
            'id' => $plan_id,
            'name' => $pname,
            'assigned_at_fmt' => $assigned_at_fmt,
            'created_at_fmt' => null,
            'updated_at_fmt' => null,
            'created_by_name' => '—',
            'updated_by_name' => '—',
            'exercise_count' => $exCount
          ]
        ]);
        exit;
      }

      // Unassign plan from user
      if ($action === 'unassign_plan') {
        if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
          throw new Exception('You do not have permission to unassign plans.');
        }
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        if ($uid <= 0 || $plan_id <= 0) throw new Exception('Invalid request.');

        $conn->begin_transaction();
        try {
          $sql1 = "
            DELETE upe
            FROM user_plan_exercises AS upe
            INNER JOIN user_plans AS up ON up.id = upe.user_plan_id
            WHERE up.user_id = ? AND up.plan_id = ?
          ";
          $st1 = $conn->prepare($sql1);
          $st1->bind_param("ii", $uid, $plan_id);
          $st1->execute();
          $st1->close();

          $sql2 = "DELETE FROM user_plans WHERE user_id = ? AND plan_id = ?";
          $st2 = $conn->prepare($sql2);
          $st2->bind_param("ii", $uid, $plan_id);
          if (!$st2->execute()) { $st2->close(); throw new Exception('Failed to unassign plan.'); }
          $affected = $st2->affected_rows;
          $st2->close();

          if ($affected < 1) {
            $conn->rollback();
            throw new Exception('This plan is not assigned to that user.');
          }

          $conn->commit();
          $flash = 'Plan unassigned.'; $flash_type = 'ok';
        } catch (Throwable $e) {
          $conn->rollback();
          throw $e;
        }
      }

      if (in_array($action, ['update_client','invite_client','resend_invite','deactivate_client','reactivate_client','unlock_user','unassign_plan','bulk_action'], true)) {
        header('Location: clients.php?tab=' . urlencode($tab));
        exit;
      }

    } catch (Throwable $e) {
      $flash = $e->getMessage(); $flash_type = 'err';
    }
  }
}

// IMPORTANT: include header/nav *after* POST/redirects to avoid "headers already sent"
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

// ---------- Load clients (split active / inactive) ----------
$active = []; $inactive = [];
$q = "
  SELECT
    u.id, u.role, u.is_client, u.is_active, u.email, u.phone, u.birthdate, u.gender,
    u.first_name, u.middle_name, u.last_name,
    u.height_ft, u.height_in, u.weight_lbs,
    u.password_hash,
    u.locked_until,
    COALESCE((SELECT COUNT(*) FROM user_plans up WHERE up.user_id = u.id), 0) AS plans_count
  FROM users u
  WHERE u.role='client' OR u.is_client=1
  ORDER BY u.last_name, u.first_name, u.id
";
$res = $conn->query($q);
if ($res) {
  while ($r = $res->fetch_assoc()) {
    if ((int)($r['is_active'] ?? 1) === 1) $active[] = $r; else $inactive[] = $r;
  }
}

// Signed-in meta
$who = $USER_NAME ?? trim(($USER_FIRST_NAME ?? '') . ' ' . ($USER_LAST_NAME ?? ''));

// ---------- Helpers ----------
function calc_age_years(?string $birthdate): ?int {
  if (!$birthdate) return null;
  try { $dob = new DateTime($birthdate); } catch (Throwable $e) { return null; }
  $now = new DateTime('now'); if ($dob > $now) return null; return (int)$dob->diff($now)->y;
}
function format_gender_cap(?string $g): string {
  if ($g === null || $g === '') return '';
  $g = trim($g); return h(mb_strtoupper(mb_substr($g, 0, 1)) . mb_substr($g, 1));
}
function format_phone_display(?string $raw): string {
  if (!$raw) return '';
  $digits = preg_replace('/\D+/', '', $raw);
  if (strlen($digits) >= 11 && $digits[0] === '1') { $digits = substr($digits, -10); }
  if (strlen($digits) === 10) return sprintf('(%s) %s-%s', substr($digits,0,3), substr($digits,3,3), substr($digits,6,4));
  return $raw;
}
function format_us_date(?string $iso): string {
  if (!$iso) return '';
  try { $dt = new DateTime($iso); } catch (Throwable $e) { return (string)$iso; }
  return $dt->format('m/d/Y');
}

function format_us_datetime(?string $iso): string {
  if (!$iso) return '';
  try { $dt = new DateTime($iso); } catch (Throwable $e) { return (string)$iso; }
  return $dt->format('M j, Y g:i A'); // e.g., Oct 8, 2025 6:25 PM
}

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
    $name = ($nm !== '') ? $nm : ($u['email'] ?? '—');
  }
  $stmt->close();
  return $cache[$uid] = $name;
}

// Column exists helper (rely on existing helpers.php if available)
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
    return (int)($row['cnt'] ?? 0) > 0;
  }
}

// ---- Column/plans helpers for expansion ----
$HAS_PLAN_CREATED_AT = column_exists($conn, 'workout_plans', 'created_at');
$HAS_PLAN_CREATED_BY = column_exists($conn, 'workout_plans', 'created_by');
$HAS_PLAN_UPDATED_AT = column_exists($conn, 'workout_plans', 'updated_at');
$HAS_PLAN_UPDATED_BY = column_exists($conn, 'workout_plans', 'updated_by');
$HAS_UP_ASSIGNED_AT = column_exists($conn, 'user_plans', 'assigned_at');

// ---- Optional exercise columns (for nested exercise list) ----
$HAS_EX_CREATED_AT = column_exists($conn, 'exercises', 'created_at');
$HAS_EX_CREATED_BY = column_exists($conn, 'exercises', 'created_by');
$HAS_EX_UPDATED_AT = column_exists($conn, 'exercises', 'updated_at');
$HAS_EX_UPDATED_BY = column_exists($conn, 'exercises', 'updated_by');
$plansByUser = [];
$sqlPlans = "
  SELECT
    up.user_id,
    p.id   AS plan_id,
    p.name AS plan_name,
    " . ($HAS_PLAN_CREATED_AT ? "p.created_at" : "NULL AS created_at") . ",
    " . ($HAS_PLAN_CREATED_BY ? "p.created_by" : "NULL AS created_by") . ",
    " . ($HAS_PLAN_UPDATED_AT ? "p.updated_at" : "NULL AS updated_at") . ",
    " . ($HAS_PLAN_UPDATED_BY ? "p.updated_by" : "NULL AS updated_by") . ",
    " . ($HAS_UP_ASSIGNED_AT ? "up.assigned_at" : "NULL") . " AS assigned_at,
    COUNT(DISTINCT pe.exercise_id) AS exercise_count
  FROM user_plans up
  JOIN workout_plans p ON p.id = up.plan_id
  LEFT JOIN plan_exercises pe ON pe.plan_id = p.id
  GROUP BY
    up.user_id, p.id, p.name, " .
    ($HAS_PLAN_CREATED_AT ? "p.created_at" : "created_at") . ", " .
    ($HAS_PLAN_CREATED_BY ? "p.created_by" : "created_by") . ", " .
    ($HAS_PLAN_UPDATED_AT ? "p.updated_at" : "updated_at") . ", " .
    ($HAS_PLAN_UPDATED_BY ? "p.updated_by" : "updated_by") . ",
    assigned_at
  ORDER BY up.user_id ASC, p.id DESC
";

// ---- Exercises by plan ----
$exByPlan = [];
$sqlEx = "
  SELECT
    pe.plan_id,
    e.id AS ex_id,
    e.name,
    e.notes,
    e.video_url,
    GROUP_CONCAT(DISTINCT CONCAT(c.id, '::', c.name) ORDER BY c.name SEPARATOR '||') AS categories,
    " . ($HAS_EX_CREATED_AT ? "e.created_at" : "NULL AS created_at") . ",
    " . ($HAS_EX_CREATED_BY ? "e.created_by" : "NULL AS created_by") . ",
    " . ($HAS_EX_UPDATED_AT ? "e.updated_at" : "NULL AS updated_at") . ",
    " . ($HAS_EX_UPDATED_BY ? "e.updated_by" : "NULL AS updated_by") . ",
    COUNT(DISTINCT pe2.plan_id) AS used_in_plans
  FROM plan_exercises pe
  JOIN exercises e       ON e.id = pe.exercise_id
  LEFT JOIN exercise_categories ec ON ec.exercise_id = e.id
  LEFT JOIN categories c ON c.id = ec.category_id
  LEFT JOIN plan_exercises pe2 ON pe2.exercise_id = e.id
  GROUP BY pe.plan_id, e.id
  ORDER BY pe.plan_id ASC, e.name ASC
";

// --- Per-client exercise settings (with user notes) ---
$userExByUserPlan = [];
$sqlUserEx = "
  SELECT
    up.user_id,
    up.plan_id,
    upe.exercise_id,
    upe.sets,
    upe.reps,
    upe.weight_lbs AS weight_value,
    upe.duration_seconds AS duration_seconds,
    upe.user_notes AS notes,
    " . ($HAS_UPE_UPDATED_AT ? "upe.updated_at" : "NULL AS updated_at") . ",
    " . ($HAS_UPE_UPDATED_BY ? "upe.updated_by" : "NULL AS updated_by") . "
  FROM user_plans up
  JOIN user_plan_exercises upe ON upe.user_plan_id = up.id
";

// All plans
$allPlans = [];
if ($res = $conn->query("SELECT id, name FROM workout_plans ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) { $allPlans[] = ['id'=>(int)$r['id'], 'name'=>(string)$r['name']]; }
}
// All exercises
$allExercises = [];
if ($res = $conn->query("SELECT id, name FROM exercises ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) {
    $allExercises[] = ['id'=>(int)$r['id'], 'name'=>(string)$r['name']];
  }
}

if ($rs = $conn->query($sqlUserEx)) {
  while ($r = $rs->fetch_assoc()) {
    $u  = (int)$r['user_id'];
    $p  = (int)$r['plan_id'];
    $ex = (int)$r['exercise_id'];
    if (!isset($userExByUserPlan[$u])) $userExByUserPlan[$u] = [];
    if (!isset($userExByUserPlan[$u][$p])) $userExByUserPlan[$u][$p] = [];
    $updatedAtDisp = null;
    if ($HAS_UPE_UPDATED_AT && !empty($r['updated_at'])) {
      $updatedAtDisp = format_us_datetime($r['updated_at']);
    }
    $updatedByName = null;
    if ($HAS_UPE_UPDATED_BY && isset($r['updated_by']) && (int)$r['updated_by'] > 0) {
      $updatedByName = user_display_name($conn, (int)$r['updated_by']);
    }

    $weightValue = isset($r['weight_value']) && $r['weight_value'] !== null ? (float)$r['weight_value'] : null;
    $weightDisplay = $weightValue !== null ? ppf_format_weight_lbs($weightValue) : null;
    $durationSeconds = isset($r['duration_seconds']) && $r['duration_seconds'] !== null ? (int)$r['duration_seconds'] : null;
    $durationDisplay = $durationSeconds !== null ? ppf_format_duration_display($durationSeconds) : null;

    $userExByUserPlan[$u][$p][$ex] = [
      'sets'             => isset($r['sets'])     ? (string)$r['sets']     : null,
      'reps'             => isset($r['reps'])     ? (string)$r['reps']     : null,
      'weight_value'     => $weightValue,
      'weight_display'   => $weightDisplay,
      'duration_seconds' => $durationSeconds,
      'duration_display' => $durationDisplay,
      'notes'            => isset($r['notes'])    ? (string)$r['notes']    : null,
      'updated_at'       => $updatedAtDisp,
      'updated_by_name'  => $updatedByName,
    ];
  }
}
if ($rs = $conn->query($sqlEx)) {
  while ($r = $rs->fetch_assoc()) {
    $pid = (int)$r['plan_id'];

    $creator = '—';
    if ($HAS_EX_CREATED_BY && !empty($r['created_by'])) {
      $creator = user_display_name($conn, (int)$r['created_by']);
    }
    $editor = '—';
    if ($HAS_EX_UPDATED_BY && !empty($r['updated_by'])) {
      $editor = user_display_name($conn, (int)$r['updated_by']);
    }

    $videoUrl = (string)($r['video_url'] ?? '');
    $catRaw = (string)($r['categories'] ?? '');
    $catList = ppf_parse_category_concat($catRaw);

    $exByPlan[$pid][] = [
      'ex_id'            => (int)$r['ex_id'],
      'name'             => (string)$r['name'],
      'notes'            => (string)($r['notes'] ?? ''),
      'has_video'        => $videoUrl !== '',
      'video_url'        => $videoUrl,
      'categories'       => $catList,
      'created_at'       => $r['created_at'] ?? null,
      'created_by_name'  => $creator,
      'updated_at'       => $r['updated_at'] ?? null,
      'updated_by_name'  => $editor,
      'used_in_plans'    => (int)$r['used_in_plans'],
    ];
  }
}

if ($rs = $conn->query($sqlPlans)) {
  while ($r = $rs->fetch_assoc()) {
    $uid2 = (int)$r['user_id'];

    $created_by_name = '—';
    if ($HAS_PLAN_CREATED_BY && !empty($r['created_by'])) {
      $created_by_name = user_display_name($conn, (int)$r['created_by']);
    }

    $updated_by_name = '—';
    if ($HAS_PLAN_UPDATED_BY && !empty($r['updated_by'])) {
      $updated_by_name = user_display_name($conn, (int)$r['updated_by']);
    }

    if (!isset($plansByUser[$uid2])) $plansByUser[$uid2] = [];
    $plansByUser[$uid2][] = [
      'id'               => (int)$r['plan_id'],
      'name'             => (string)$r['plan_name'],
      'assigned_at'      => $r['assigned_at'] ?? null,
      'created_at'       => $r['created_at'] ?? null,
      'updated_at'       => $r['updated_at'] ?? null,
      'assigned_at_fmt'  => !empty($r['assigned_at']) ? format_us_datetime($r['assigned_at']) : null,
      'created_at_fmt'   => !empty($r['created_at'])  ? format_us_datetime($r['created_at'])  : null,
      'updated_at_fmt'   => !empty($r['updated_at'])  ? format_us_datetime($r['updated_at'])  : null,
      'created_by_name'  => $created_by_name,
      'updated_by_name'  => $updated_by_name,
      'exercise_count'   => (int)$r['exercise_count'],
    ];
  }
}

// --- Rendering helpers ---
function render_clients_table(array $clients, string $csrf, string $whichTab): void {
  global $USER_ROLE;
  $tableId = 'clientsTable-' . $whichTab;
  $searchId = 'clientSearch-' . $whichTab;
  $bulkSelectId = 'clientBulkSelect-' . $whichTab;
  $bulkButtonId = 'clientBulkApply-' . $whichTab;
  $bulkFormId = 'clientBulkForm-' . $whichTab;
  $selectAllId = 'clientSelectAll-' . $whichTab;
  $colspan = 14;
  ?>
  <div class="clients-table-container" data-clients-tab="<?php echo h($whichTab); ?>" data-bulk-form-id="<?php echo h($bulkFormId); ?>">
    <div class="table-tools">
      <div class="table-tools__search">
        <input type="search" class="input search-input" id="<?php echo h($searchId); ?>" data-client-search placeholder="Search clients..." autocomplete="off">
      </div>
      <div class="table-tools__bulk">
        <select class="input bulk-select" id="<?php echo h($bulkSelectId); ?>" data-bulk-select>
          <option value="">Bulk actions…</option>
          <option value="bulk_unassign">Bulk Unassign</option>
          <option value="bulk_deactivate">Bulk Deactivate</option>
        </select>
        <button type="button" class="btn small" id="<?php echo h($bulkButtonId); ?>" data-bulk-apply>Apply</button>
      </div>
    </div>
    <div class="table-wrapper">
      <table id="<?php echo h($tableId); ?>" class="clients-table" data-table="clients" data-tab="<?php echo h($whichTab); ?>">
        <colgroup>
          <col style="width:48px">
          <col style="width:90px">
          <col>
          <col>
          <col>
          <col style="min-width:220px">
          <col style="min-width:150px">
          <col style="min-width:150px">
          <col style="width:90px">
          <col style="width:110px">
          <col style="width:120px">
          <col style="width:120px">
          <col style="width:90px">
          <col style="min-width:240px">
        </colgroup>
        <thead>
          <tr>
            <th class="select-col"><input type="checkbox" data-select-all id="<?php echo h($selectAllId); ?>" aria-label="Select all clients"></th>
            <th data-sort-key="id"><button type="button" class="sort-btn" data-sort-key="id" data-state="off">ID<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="first"><button type="button" class="sort-btn" data-sort-key="first" data-state="off">First<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="middle"><button type="button" class="sort-btn" data-sort-key="middle" data-state="off">Middle<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="last"><button type="button" class="sort-btn" data-sort-key="last" data-state="off">Last<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="email"><button type="button" class="sort-btn" data-sort-key="email" data-state="off">Email<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="phone"><button type="button" class="sort-btn" data-sort-key="phone" data-state="off">Phone<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="birthdate"><button type="button" class="sort-btn" data-sort-key="birthdate" data-state="off">Birthdate<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="age"><button type="button" class="sort-btn" data-sort-key="age" data-state="off">Age<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="gender"><button type="button" class="sort-btn" data-sort-key="gender" data-state="off">Gender<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="height"><button type="button" class="sort-btn" data-sort-key="height" data-state="off">Height<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="weight"><button type="button" class="sort-btn" data-sort-key="weight" data-state="off">Weight<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th data-sort-key="plans"><button type="button" class="sort-btn" data-sort-key="plans" data-state="off">Plans<span class="sort-indicator" aria-hidden="true"></span></button></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$clients): ?>
          <tr><td colspan="<?php echo $colspan; ?>" class="muted" style="padding:24px">No clients found.</td></tr>
        <?php else: $index = 0; foreach ($clients as $c):
          $id   = (int)$c['id'];
          $pw   = (string)($c['password_hash'] ?? '');
          $has_password = ($pw !== null && $pw !== '');
          $editing = isset($_GET['edit']) && (int)$_GET['edit'] === $id;
          $is_real_client = ($c['role'] === 'client');
          $ageYears = calc_age_years($c['birthdate'] ?? null);
          $lockedUntil = $c['locked_until'] ?? null;
          $isLocked = !empty($lockedUntil) && (strtotime($lockedUntil) > time());
          $heightFt = isset($c['height_ft']) && $c['height_ft'] !== '' && is_numeric($c['height_ft']) ? (int)$c['height_ft'] : null;
          $heightIn = isset($c['height_in']) && $c['height_in'] !== '' && is_numeric($c['height_in']) ? (int)$c['height_in'] : null;
          $heightSort = ($heightFt !== null ? $heightFt * 12 : 0) + ($heightIn !== null ? $heightIn : 0);
          if ($heightFt === null && $heightIn === null) $heightSort = '';
          $weightSort = null;
          if (isset($c['weight_lbs']) && $c['weight_lbs'] !== '' && is_numeric($c['weight_lbs'])) {
            $weightSort = (float)$c['weight_lbs'];
          }
          $weightSortAttr = ($weightSort === null) ? '' : ppf_trim_number($weightSort, 6);
          $phoneDigits = preg_replace('/\D+/', '', (string)($c['phone'] ?? ''));
          $birthAttr = trim((string)($c['birthdate'] ?? ''));
          $firstSort = strtolower(trim((string)($c['first_name'] ?? '')));
          $middleSort = strtolower(trim((string)($c['middle_name'] ?? '')));
          $lastSort = strtolower(trim((string)($c['last_name'] ?? '')));
          $emailSort = strtolower(trim((string)($c['email'] ?? '')));
          $genderSort = strtolower(trim((string)($c['gender'] ?? '')));
          $plansCount = (int)($c['plans_count'] ?? 0);
          $orderIndex = $index++;
          $heightSortAttr = ($heightSort === '') ? '' : (string)$heightSort;
          $labelName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        ?>
          <tr class="client-row" data-uid="<?php echo $id; ?>" data-order="<?php echo $orderIndex; ?>" data-sort-id="<?php echo $id; ?>" data-sort-first="<?php echo h($firstSort); ?>" data-sort-middle="<?php echo h($middleSort); ?>" data-sort-last="<?php echo h($lastSort); ?>" data-sort-email="<?php echo h($emailSort); ?>" data-sort-phone="<?php echo h($phoneDigits); ?>" data-sort-birthdate="<?php echo h($birthAttr); ?>" data-sort-age="<?php echo $ageYears === null ? '' : (int)$ageYears; ?>" data-sort-gender="<?php echo h($genderSort); ?>" data-sort-height="<?php echo h($heightSortAttr); ?>" data-sort-weight="<?php echo h($weightSortAttr); ?>" data-sort-plans="<?php echo $plansCount; ?>">
            <td>
              <input type="checkbox" class="client-select" value="<?php echo $id; ?>" data-client-checkbox aria-label="Select <?php echo h($labelName !== '' ? $labelName : ('Client #' . $id)); ?>">
            </td>

            <td><?php echo $id; ?></td>

            <td>
              <?php if ($editing): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="update_client">
                  <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                  <input class="input" type="text" name="first_name" value="<?php echo h($c['first_name']); ?>">
              <?php else: ?>
                <?php echo h($c['first_name']); ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="text" name="middle_name" value="<?php echo h($c['middle_name']); ?>">
              <?php else: ?>
                <?php echo h($c['middle_name']); ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="text" name="last_name" value="<?php echo h($c['last_name']); ?>">
              <?php else: ?>
                <?php echo h($c['last_name']); ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="email" name="email" value="<?php echo h($c['email']); ?>" required>
              <?php else: ?>
                <?php
                  $emailHtml = h($c['email']) . ($isLocked ? ' <span class="badge bg-warning text-dark">Locked</span>' : '');
                  echo $emailHtml;
                ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="text" name="phone" value="<?php echo h($c['phone']); ?>">
              <?php else: ?>
                <?php echo h(format_phone_display($c['phone'])); ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="date" name="birthdate" value="<?php echo h($c['birthdate']); ?>">
              <?php else: ?>
                <?php echo h(format_us_date($c['birthdate'])); ?>
              <?php endif; ?>
            </td>

            <td><?php echo $ageYears === null ? '—' : (int)$ageYears; ?></td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="text" name="gender" value="<?php echo h($c['gender']); ?>">
              <?php else: ?>
                <?php echo format_gender_cap($c['gender']); ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <div style="display:flex;gap:6px;align-items:center">
                  <input class="input" type="number" min="0" max="8" step="1" name="height_ft" value="<?php echo h($c['height_ft']); ?>" style="width:60px"> <span>ft</span>
                  <input class="input" type="number" min="0" max="11" step="1" name="height_in" value="<?php echo h($c['height_in']); ?>" style="width:70px"> <span>in</span>
                </div>
              <?php else: ?>
                <?php
                  $ft = $c['height_ft'] ?? null;
                  $in = $c['height_in'] ?? null;
                  if (($ft === null || $ft === '') && ($in === null || $in === '')) echo '—';
                  else echo h((int)$ft . "'" . (int)$in . '"');
                ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($editing): ?>
                <input class="input" type="number" min="0" step="0.1" name="weight_lbs" value="<?php echo h($c['weight_lbs']); ?>" style="width:110px">
              <?php else: ?>
                <?php
                  $lbs = $c['weight_lbs'] ?? null;
                  if ($lbs === null || $lbs === '' || (is_numeric($lbs) && (float)$lbs <= 0)) echo '—';
                  else {
                    $val = is_numeric($lbs) ? (float)$lbs : null;
                    echo h(($val === null) ? $lbs : ((floor($val)===$val) ? (intval($val).' lbs') : ($val.' lbs')));
                  }
                ?>
              <?php endif; ?>
            </td>

            <td><?php echo $plansCount; ?></td>

            <td>
              <div class="actions">
                <?php if (in_array(($USER_ROLE ?? ''), ['admin','trainer'], true) && $isLocked): ?>
                  <form method="post" action="clients.php" style="display:inline" onsubmit="return confirm('Unlock this account? This will clear failed attempts and remove the lockout.');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="unlock_user">
                    <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                    <button class="btn small" type="submit" style="border-color:#f59e0b;color:#f59e0b">Unlock</button>
                  </form>
                <?php endif; ?>

                <?php if ($editing): ?>
                  <button class="btn small brand" type="submit">Save</button>
                  </form>
                  <a class="btn small" href="clients.php?tab=<?php echo urlencode($whichTab); ?>">Cancel</a>
                <?php else: ?>
                  <a class="btn small" href="clients.php?tab=<?php echo urlencode($whichTab); ?>&edit=<?php echo $id; ?>">Edit</a>
                <?php endif; ?>

                <?php if (!$has_password): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="invite_client">
                    <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                    <button class="btn small brand" type="submit">Invite</button>
                  </form>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="resend_invite">
                    <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                    <button class="btn small" type="submit">Resend</button>
                  </form>
                <?php endif; ?>

                <?php if ($is_real_client): ?>
                  <?php if ($whichTab === 'active'): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this client? They will be moved to Inactive Clients and will be unable to log in.');">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="deactivate_client">
                      <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                      <button class="btn small" type="submit" style="border-color:#ef4444;color:#ef4444">Deactivate</button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="reactivate_client">
                      <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                      <button class="btn small brand" type="submit">Reactivate</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <tr class="client-expand" id="exp-<?php echo $id; ?>" style="display:none">
            <td colspan="<?php echo $colspan; ?>" style="background:#0f1218">
              <div class="muted" data-exp-body>Loading plans…</div>
            </td>
          </tr>

        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <form id="<?php echo h($bulkFormId); ?>" method="post" style="display:none" data-bulk-form>
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="bulk_action">
    <input type="hidden" name="bulk_type" value="">
  </form>
  <?php
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clients · Peter Pang Fit</title>
<style>
  :root{
    --bg:#0b0c10; --panel:#12141a; --text:#e6e8ee; --muted:#9aa3b2; --brand:#3b82f6;
    --line:#1c212b; --chip:#1f2430;
    --page-pad: clamp(14px, 3vw, 28px);
    --support: #7dd3fc;
  }
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
  a{color:var(--text);text-decoration:none}

  .wrap{width:100%;max-width:100%;margin:24px auto;padding:0 var(--page-pad);box-sizing:border-box}
  .panel{background:var(--panel);border:1px solid var(--line);border-radius:14px}
  .row{display:flex;gap:16px;align-items:center}
  .btn{ background:#1a2232; border:1px solid var(--line); padding:8px 12px; border-radius:10px; color: var(--text); }
  .btn.small{padding:6px 10px;font-size:12px}
  .btn.brand{background:var(--brand);border-color:var(--brand);color:white}
  .tabs{display:flex;gap:8px;margin-bottom:14px}
  .tab{padding:8px 12px;border-radius:9999px;border:1px solid var(--line);background:#1a1f2a;color:#cbd5e1}
  .tab.active{background:#1f2f55;border-color:#284072;color:#fff}

  table thead th { color: var(--support); font-weight: 600; }
  [data-exp-body] > div > div:first-child,
  .plan-expand > td > div > div:first-child { color: #ffffff; font-weight: 600; }

  .subheader{
    position: sticky; top: 0; z-index: 40; background: var(--panel);
    border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px;
    margin-bottom: 14px; display:flex; align-items:center; justify-content:space-between; gap:12px;
  }
  .subheader .left{display:flex;align-items:center;gap:10px}
  .brand{font-weight:800;font-size:20px;letter-spacing:.2px}
  .btnset{display:flex;gap:8px;flex-wrap:wrap}
  .clients-table-container{display:flex;flex-direction:column;gap:12px}
  .table-tools{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between}
  .table-tools__search{flex:1 1 260px;max-width:420px}
  .table-tools__bulk{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .table-tools__bulk .input{width:auto;min-width:170px}
  .table-wrapper{position:relative;overflow-x:auto;overflow-y:hidden;border:1px solid var(--line);border-radius:12px;scrollbar-width:none}
  .table-wrapper::-webkit-scrollbar{height:0}
  .table-wrapper.is-scrolling{scrollbar-width:thin}
  .table-wrapper.is-scrolling::-webkit-scrollbar{height:8px}
  .table-wrapper.is-scrolling::-webkit-scrollbar-thumb{background:rgba(148,163,184,0.45);border-radius:999px}
  table{width:100%;border-collapse:collapse}
  .clients-table{min-width:960px}
  th,td{border-bottom:1px solid var(--line);padding:10px;text-align:left;vertical-align:middle}
  thead th{position:sticky;top:0;background:#0f121a;z-index:1}
  .clients-table thead th{z-index:2}
  .clients-table .select-col{text-align:center;width:48px}
  .clients-table td:first-child{text-align:center}
  .clients-table td:first-child input{margin:0 auto;display:block}
  .sort-btn{display:flex;align-items:center;gap:6px;justify-content:flex-start;width:100%;background:none;border:none;color:inherit;font:inherit;padding:0 18px 0 0;cursor:pointer}
  .sort-btn:hover .sort-indicator{opacity:0.8}
  .sort-btn:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
  .sort-indicator{font-size:10px;opacity:0.4;line-height:1}
  .sort-btn[data-state="asc"] .sort-indicator::before{content:'▲'}
  .sort-btn[data-state="desc"] .sort-indicator::before{content:'▼'}
  .sort-btn[data-state="off"] .sort-indicator::before{content:''}
  .sort-btn[data-state="asc"] .sort-indicator,
  .sort-btn[data-state="desc"] .sort-indicator{opacity:0.8}
  .col-resize-handle{position:absolute;top:0;right:-3px;width:8px;height:100%;cursor:col-resize}
  .col-resize-handle::after{content:'';position:absolute;top:0;bottom:0;left:3px;width:2px;background:rgba(148,163,184,0.2);}
  .input{background:#0e1320;border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px;width:100%}
  .muted{color:var(--muted)}

  .client-row { cursor: pointer; }
  .client-row:hover { background:#141a25; }
  .client-expand td { border-top:1px solid var(--line); }

  .client-row.expanded{ background:#141a25; outline:2px solid var(--brand); outline-offset:-2px; transition: background .2s ease, outline-color .2s ease; }

  @media (max-width:1100px){ th,td{padding:8px} }
  @media (max-width:900px){ th,td{padding:8px 6px;font-size:13px} .btn.small{font-size:11px} }
  @media (max-width:700px){
    th,td{padding:6px 4px;font-size:12px}
    .brand{font-size:18px}
    .subheader{padding:8px 10px}
    .table-tools{flex-direction:column;align-items:stretch;gap:10px}
    .table-tools__bulk{width:100%;justify-content:flex-start}
    .table-tools__bulk .input{width:100%;min-width:0}
  }
  .badge{ display:inline-block; padding:3px 8px; font-size:11px; border-radius:999px; }
  .bg-warning{ background:#f59e0b; color:#111; }

  .plan-row { cursor:pointer; }
  .plan-row:hover { background:#141a25; }
  .plan-row.expanded{ background:#141a25; outline:2px solid var(--brand); outline-offset:-2px; transition: background .2s ease, outline-color .2s ease; }
  .plan-expand td{ border-top:1px solid var(--line); background:#0e121a; }

  .add-plan-row, .add-ex-row { cursor: pointer; }
  .add-plan-row > td, .add-ex-row  > td { color:#d4af37; font-weight:600; }
  .add-plan-row:hover > td, .add-ex-row:hover  > td { background:#141a25; color:#ffd84d; }
  .add-plan-row .muted, .add-ex-row  .muted { opacity:.9; }

  .section-title { color:#fff; font-weight:600; margin-bottom:6px; }
  .empty-state { color:#f87171; font-weight:500; }

  .mini-ex-row { cursor:default; }
  .mini-ex-link { color:inherit; text-decoration:none; font-weight:600; }
  .mini-ex-link:hover { text-decoration:none; opacity:0.85; }
  .mini-ex-link:focus-visible { outline:2px solid var(--brand); outline-offset:3px; }
  .mini-ex-row:hover { background:#141a25; }
  .mini-ex-row > td { transition: background .15s ease; }
  .mini-ex-row:hover > td { background:#141a25; }

  .chip{ display:inline-flex;align-items:center;gap:6px; background:var(--chip);border:1px solid var(--line);
    padding:3px 7px;border-radius:999px;font-size:12px;color:#c3c9d4; text-decoration:none }
  .chip:hover, .chip:focus { text-decoration:none; }
  .mini-cat-chip:hover { opacity:0.85; }

  .video-chip { cursor:pointer; color:#f8fafc; background:rgba(63, 99, 221, 0.18); border-color:rgba(99, 102, 241, 0.5); }
  .video-chip:hover { background:rgba(99,102,241,0.28); }

  .video-tooltip { position:absolute; z-index:4000; background:#0e1320; border:1px solid rgba(99,102,241,0.4);
    border-radius:10px; padding:10px; width:240px; box-shadow:0 20px 50px rgba(0,0,0,0.55); display:none; pointer-events:none; }
  .video-tooltip.visible { display:block; }
  .video-tooltip video { width:100%; border-radius:8px; display:block; background:#000; }

  .video-modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:4500; }
  .video-modal.open { display:flex; }
  .video-modal__backdrop { position:absolute; inset:0; background:rgba(0,0,0,0.72); }
  .video-modal__content { position:relative; background:#0e1320; border:1px solid var(--line); border-radius:16px; padding:22px;
    width:min(900px, 92vw); max-height:90vh; display:flex; flex-direction:column; gap:18px; box-shadow:0 28px 60px rgba(0,0,0,0.6); }
  .video-modal__header { display:flex; align-items:flex-start; justify-content:space-between; }
  .video-modal__title { font-size:20px; margin:0; }
  .video-modal__body { display:flex; gap:18px; flex:1; overflow:hidden; }
  .video-modal__video { flex:0 0 420px; max-width:100%; }
  .video-modal__video video { width:100%; border-radius:12px; background:#000; display:block; }
  .video-modal__description { flex:1; color:#c3c9d4; white-space:pre-line; overflow-y:auto; max-height:60vh; padding-right:4px; }
  .video-modal__footer { display:flex; justify-content:flex-end; }

  @media (max-width:900px) {
    .video-modal__body { flex-direction:column; }
    .video-modal__video { flex:0 0 auto; }
    .video-modal__description { max-height:none; }
  }

  /* NEW: show cursor and subtle hint for inline editable cells */
  td[data-cell="sets"], td[data-cell="reps"], td[data-cell="weight"], td[data-cell="duration"], td[data-cell="notes"] {
    cursor: text;
  }
  td[data-cell].editing { background:#101626; }
</style>
</head>
<body>
<main class="wrap">

  <div class="subheader">
    <div class="left">
      <div class="brand">Clients</div>
      <span class="muted">Manage active & inactive clients</span>
    </div>
    <div class="btnset">
      <a class="btn" href="dashboard.php">Back to Dashboard</a>
      <a class="btn" href="invites.php">Manage Invites</a>
      <a class="btn" href="workout_plans.php">Workout Plans</a>
      <a class="btn" href="users.php">All Users</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="panel" style="padding:10px 12px;margin-bottom:14px;border-left:3px solid <?php echo $flash_type==='ok'?'#22c55e':'#ef4444'; ?>">
      <?php echo h($flash); ?>
    </div>
  <?php endif; ?>

  <div class="tabs">
    <a class="tab <?php echo $tab==='active'?'active':''; ?>" href="clients.php?tab=active">Active Clients</a>
    <a class="tab <?php echo $tab==='inactive'?'active':''; ?>" href="clients.php?tab=inactive">Inactive Clients</a>
  </div>

  <div class="panel" style="padding:16px">
    <?php
      if ($tab === 'active') render_clients_table($active, $csrf, 'active');
      else render_clients_table($inactive, $csrf, 'inactive');
    ?>
  </div>

</main>

<!-- Pick Plan modal -->
<div class="backdrop" id="bdPickPlan" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000"></div>
<div class="modal" id="mdPickPlan" role="dialog" aria-modal="true" aria-labelledby="ppTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(520px,94vw);
            background:#151923;border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="ppTitle" style="margin:0 0 10px 0;font-size:16px">Assign Plan to User</h3>
  <div class="fine" id="ppUserText" style="margin-bottom:8px;color:#9aa3b2"></div>
  <div>
    <label class="fine" for="ppPlanSel" style="display:block;margin-bottom:6px;color:#9aa3b2">Choose a plan</label>
    <select class="input" id="ppPlanSel"></select>
  </div>
  <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
    <button class="btn" type="button" id="ppCancel">Cancel</button>
    <button class="btn brand" type="button" id="ppContinue">Assign Plan</button>
  </div>
</div>

<!-- Add Exercises modal -->
<div class="backdrop" id="bdAddEx" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000"></div>
<div class="modal" id="mdAddEx" role="dialog" aria-modal="true" aria-labelledby="aeTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(520px,94vw);
            background:#151923;border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="aeTitle" style="margin:0 0 10px 0;font-size:16px">Add Exercises to Plan</h3>
  <div class="fine" id="aePlanText" style="margin-bottom:8px;color:#9aa3b2"></div>
  <div class="box" style="border:1px solid var(--line);border-radius:10px;padding:10px;max-height:360px;overflow:auto">
    <div id="aeList"></div>
  </div>
  <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
    <button class="btn" type="button" id="aeCancel">Cancel</button>
    <button class="btn brand" type="button" id="aeAdd">Add Selected</button>
  </div>
</div>

<!-- Exercise video tooltip -->
<div id="videoTooltip" class="video-tooltip" hidden>
  <video id="videoTooltipVideo" muted playsinline loop></video>
</div>

<!-- Exercise video modal -->
<div id="videoModal" class="video-modal" role="dialog" aria-modal="true" aria-labelledby="videoModalTitle" hidden>
  <div class="video-modal__backdrop" data-video-modal-close></div>
  <div class="video-modal__content">
    <div class="video-modal__header">
      <a id="videoModalTitle" class="mini-ex-link video-modal__title" href="#">Exercise Video</a>
    </div>
    <div class="video-modal__body">
      <div class="video-modal__video">
        <video id="videoModalVideo" muted playsinline controls loop></video>
      </div>
      <div class="video-modal__description" id="videoModalDescription"></div>
    </div>
    <div class="video-modal__footer">
      <button class="btn" type="button" data-video-modal-close>Close</button>
    </div>
  </div>
</div>

<script>
// phone input formatting (existing)
document.addEventListener('input', function(e){
  if (e.target && e.target.name === 'phone') {
    let v = e.target.value.replace(/\D+/g,'');
    if (v.length > 10) v = v.slice(-10);
    if (v.length >= 7) e.target.value = '('+v.slice(0,3)+') '+v.slice(3,6)+'-'+v.slice(6);
    else if (v.length >= 4) e.target.value = '('+v.slice(0,3)+') '+v.slice(3);
    else if (v.length >= 1) e.target.value = v;
    else e.target.value = '';
  }
}, {passive:true});

// ---- Plans + Exercises maps to JS ----
window.__PLANS_BY_USER = <?php echo json_encode($plansByUser, JSON_UNESCAPED_SLASHES); ?>;
window.__EX_BY_PLAN    = <?php echo json_encode($exByPlan,   JSON_UNESCAPED_SLASHES); ?>;
window.__USER_EX       = <?php echo json_encode($userExByUserPlan, JSON_UNESCAPED_SLASHES); ?>;
window.__CSRF = '<?php echo h($csrf); ?>';
window.__TAB  = '<?php echo h($tab); ?>';
window.__ALL_PLANS = <?php echo json_encode($allPlans, JSON_UNESCAPED_SLASHES); ?>;
window.__ALL_EXERCISES = <?php echo json_encode($allExercises, JSON_UNESCAPED_SLASHES); ?>;

function renderCategoryChips(list){
  if (!Array.isArray(list) || !list.length) {
    return '<span class="muted">—</span>';
  }

  const parts = list.map(cat => {
    if (!cat) return '';
    if (typeof cat === 'object') {
      const name = cat.name != null ? String(cat.name) : '';
      if (!name.trim()) return '';
      const safeName = escapeHtml(name);
      const id = cat.id;
      if (id !== null && id !== undefined && String(id).trim() !== '' && !Number.isNaN(Number(id))) {
        const cid = encodeURIComponent(id);
        return `<a class="chip mini-cat-chip" href="categories.php?focus_category=${cid}#cat-${cid}">${safeName}</a>`;
      }
      return `<span class="chip mini-cat-chip">${safeName}</span>`;
    }
    const name = String(cat).trim();
    if (!name) return '';
    return `<span class="chip mini-cat-chip">${escapeHtml(name)}</span>`;
  }).filter(Boolean);

  return parts.length ? parts.join(' ') : '<span class="muted">—</span>';
}

const CLIENT_SORT_TYPES = {
  id: 'number',
  first: 'string',
  middle: 'string',
  last: 'string',
  email: 'string',
  phone: 'string',
  birthdate: 'string',
  age: 'number',
  gender: 'string',
  height: 'number',
  weight: 'number',
  plans: 'number'
};

(function(){
  const table = document.querySelector('[data-table="clients"]');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  const container = table.closest('[data-clients-tab]');
  const searchInput = document.querySelector('[data-client-search]');
  const bulkSelect = document.querySelector('[data-bulk-select]');
  const bulkButton = document.querySelector('[data-bulk-apply]');
  const selectAll = table.querySelector('[data-select-all]');
  const bulkFormId = container?.dataset.bulkFormId;
  const bulkForm = bulkFormId ? document.getElementById(bulkFormId) : null;

  const rows = Array.from(tbody.querySelectorAll('tr.client-row'));
  const expansions = new Map();
  rows.forEach(row => {
    const exp = row.nextElementSibling;
    if (exp && exp.classList.contains('client-expand')) {
      expansions.set(row, exp);
    }
  });

  const searchCache = new Map();

  function computeSearch(uid){
    const parts = [];
    const row = table.querySelector(`tr.client-row[data-uid="${uid}"]`);
    if (row) {
      const rowText = Array.from(row.querySelectorAll('td'))
        .map(td => td.textContent || '')
        .join(' ');
      parts.push(rowText);
    }

    const plans = (window.__PLANS_BY_USER && window.__PLANS_BY_USER[uid]) || [];
    plans.forEach(plan => {
      parts.push(
        plan.name || '',
        plan.assigned_at_fmt || '',
        plan.created_at_fmt || '',
        plan.updated_at_fmt || '',
        plan.created_by_name || '',
        plan.updated_by_name || '',
        plan.exercise_count != null ? String(plan.exercise_count) : ''
      );

      const exs = (window.__EX_BY_PLAN && window.__EX_BY_PLAN[plan.id]) || [];
      exs.forEach(ex => {
        parts.push(
          ex.name || '',
          ex.notes || '',
          ex.created_by_name || '',
          ex.updated_by_name || '',
          ex.video_url ? 'video' : ''
        );
        if (Array.isArray(ex.categories)) {
          ex.categories.forEach(cat => {
            if (!cat) return;
            if (typeof cat === 'object') {
              if (cat.name) parts.push(String(cat.name));
            } else {
              parts.push(String(cat));
            }
          });
        }
        const per = window.__USER_EX
          && window.__USER_EX[uid]
          && window.__USER_EX[uid][plan.id]
          && window.__USER_EX[uid][plan.id][ex.ex_id];
        if (per) {
          parts.push(
            per.sets || '',
            per.reps || '',
            per.weight_display || per.weight_value || '',
            per.duration_display || per.duration_seconds || '',
            per.notes || '',
            per.updated_by_name || '',
            per.updated_at || ''
          );
        }
      });
    });

    return parts.join(' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function refreshSearchCache(uid){
    if (!uid) return;
    const key = String(uid);
    const text = computeSearch(key);
    searchCache.set(key, text);
  }

  rows.forEach(row => refreshSearchCache(row.dataset.uid));

  const noMatchesRow = document.createElement('tr');
  noMatchesRow.dataset.noMatches = '1';
  const emptyTd = document.createElement('td');
  emptyTd.colSpan = table.querySelectorAll('thead th').length;
  emptyTd.className = 'muted';
  emptyTd.style.padding = '18px';
  emptyTd.textContent = 'No matching clients.';
  noMatchesRow.appendChild(emptyTd);
  noMatchesRow.style.display = 'none';
  tbody.appendChild(noMatchesRow);

  function updateSelectAll(){
    if (!selectAll) return;
    const visible = rows.filter(row => row.style.display !== 'none');
    if (!visible.length) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
      return;
    }
    let checkedCount = 0;
    visible.forEach(row => {
      const cb = row.querySelector('[data-client-checkbox]');
      if (cb && cb.checked) checkedCount++;
    });
    selectAll.checked = checkedCount > 0 && checkedCount === visible.length;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < visible.length;
  }

  function applySearch(query){
    const q = (query || '').trim().toLowerCase();
    let matches = 0;
    rows.forEach(row => {
      const uid = row.dataset.uid;
      if (!uid) return;
      let haystack = searchCache.get(uid);
      if (haystack === undefined) {
        haystack = computeSearch(uid);
        searchCache.set(uid, haystack);
      }
      const isMatch = !q || (haystack && haystack.includes(q));
      if (isMatch) {
        row.style.display = '';
        matches++;
      } else {
        row.style.display = 'none';
        const exp = expansions.get(row);
        if (exp) exp.style.display = 'none';
        row.classList.remove('expanded');
      }
    });
    noMatchesRow.style.display = matches ? 'none' : '';
    updateSelectAll();
  }

  if (searchInput) {
    searchInput.addEventListener('input', (event) => {
      applySearch(event.target.value);
    });
  }

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      const visible = rows.filter(row => row.style.display !== 'none');
      visible.forEach(row => {
        const cb = row.querySelector('[data-client-checkbox]');
        if (cb) cb.checked = selectAll.checked;
      });
      updateSelectAll();
    });
  }

  tbody.addEventListener('change', (event) => {
    if (event.target && event.target.matches('[data-client-checkbox]')) {
      updateSelectAll();
    }
  });

  function getSelectedIds(){
    return Array.from(document.querySelectorAll('[data-client-checkbox]:checked'))
      .map(cb => cb.value)
      .filter(Boolean);
  }

  function getSelectedPlanIds(){
    const out = [];
    const seen = new Set();
    document.querySelectorAll('[data-plan-checkbox]:checked').forEach(cb => {
      const val = parseInt(cb.value, 10);
      if (!val || seen.has(val)) return;
      seen.add(val);
      out.push(val);
    });
    return out;
  }

  if (bulkButton) {
    bulkButton.addEventListener('click', () => {
      const action = bulkSelect ? bulkSelect.value : '';
      if (!action) {
        alert('Choose a bulk action to run.');
        return;
      }
      if (!bulkForm) {
        alert('Unable to run bulk action right now.');
        return;
      }
      const typeInput = bulkForm.querySelector('input[name="bulk_type"]');
      if (typeInput) typeInput.value = action;
      bulkForm.querySelectorAll('input[name="user_ids[]"], input[name="plan_ids[]"]').forEach(el => el.remove());

      if (action === 'bulk_unassign') {
        const plans = getSelectedPlanIds();
        if (!plans.length) {
          alert('Select at least one plan to unassign.');
          return;
        }
        plans.forEach(id => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'plan_ids[]';
          input.value = String(id);
          bulkForm.appendChild(input);
        });
      } else if (action === 'bulk_deactivate') {
        const selected = getSelectedIds();
        if (!selected.length) {
          alert('Select at least one client.');
          return;
        }
        selected.forEach(id => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'user_ids[]';
          input.value = id;
          bulkForm.appendChild(input);
        });
      } else {
        alert('Choose a bulk action to run.');
        return;
      }
      bulkForm.submit();
    });
  }

  const sortButtons = Array.from(table.querySelectorAll('.sort-btn'));
  const sortState = { key: null, dir: null };
  const originalOrder = rows.map(row => ({
    row,
    exp: expansions.get(row) || null,
    order: parseInt(row.dataset.order, 10) || 0
  }));

  function getSortValue(row, key){
    const prop = 'sort' + key.charAt(0).toUpperCase() + key.slice(1);
    return row.dataset[prop] ?? '';
  }

  function compareRows(aRow, bRow, key, dir){
    const type = CLIENT_SORT_TYPES[key] || 'string';
    const aVal = getSortValue(aRow, key);
    const bVal = getSortValue(bRow, key);

    if (type === 'number') {
      const aNum = aVal === '' ? NaN : parseFloat(aVal);
      const bNum = bVal === '' ? NaN : parseFloat(bVal);
      if (Number.isNaN(aNum) && Number.isNaN(bNum)) return 0;
      if (Number.isNaN(aNum)) return dir === 'asc' ? 1 : -1;
      if (Number.isNaN(bNum)) return dir === 'asc' ? -1 : 1;
      return dir === 'asc' ? aNum - bNum : bNum - aNum;
    }

    const aStr = String(aVal || '').toLowerCase();
    const bStr = String(bVal || '').toLowerCase();
    const cmp = aStr.localeCompare(bStr, undefined, { numeric: true, sensitivity: 'base' });
    return dir === 'asc' ? cmp : -cmp;
  }

  function applySort(key, dir){
    const items = originalOrder.map(item => ({ ...item }));
    if (key && dir) {
      items.sort((a, b) => {
        const primary = compareRows(a.row, b.row, key, dir);
        if (primary !== 0) return primary;
        return a.order - b.order;
      });
    } else {
      items.sort((a, b) => a.order - b.order);
    }
    items.forEach(item => {
      tbody.appendChild(item.row);
      if (item.exp) tbody.appendChild(item.exp);
    });
    tbody.appendChild(noMatchesRow);
  }

  function updateSortIndicators(activeKey, dir){
    sortButtons.forEach(btn => {
      const key = btn.dataset.sortKey;
      if (key === activeKey && dir) {
        btn.dataset.state = dir;
      } else {
        btn.dataset.state = 'off';
      }
    });
  }

  function cycleSort(btn){
    const key = btn.dataset.sortKey;
    if (!key) return;
    let nextDir = 'asc';
    if (sortState.key === key) {
      if (sortState.dir === 'asc') nextDir = 'desc';
      else if (sortState.dir === 'desc') nextDir = null;
    }
    sortState.key = nextDir ? key : null;
    sortState.dir = nextDir;
    updateSortIndicators(sortState.key, sortState.dir);
    applySort(sortState.key, sortState.dir);
  }

  sortButtons.forEach(btn => {
    btn.addEventListener('click', () => cycleSort(btn));
  });

  sortState.key = 'first';
  sortState.dir = 'asc';
  updateSortIndicators('first', 'asc');
  applySort('first', 'asc');

  function enableColumnResizing(tableEl){
    const colgroup = tableEl.querySelector('colgroup');
    if (!colgroup) return;
    const cols = Array.from(colgroup.children);
    const headers = Array.from(tableEl.querySelectorAll('thead th'));
    const MIN_WIDTH = 48;

    headers.forEach((th, index) => {
      th.style.position = 'relative';
      const handle = document.createElement('span');
      handle.className = 'col-resize-handle';
      handle.addEventListener('mousedown', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        const startX = ev.clientX;
        const col = cols[index];
        const startWidth = col && col.style.width
          ? parseFloat(col.style.width)
          : th.getBoundingClientRect().width;
        function onMove(e){
          const delta = e.clientX - startX;
          const newWidth = Math.max(MIN_WIDTH, startWidth + delta);
          if (col) {
            col.style.width = `${newWidth}px`;
            col.style.minWidth = `${newWidth}px`;
          }
        }
        function onUp(){
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          document.body.style.cursor = '';
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.body.style.cursor = 'col-resize';
      });
      handle.addEventListener('dblclick', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        const col = cols[index];
        if (!col) return;
        col.style.width = '';
        col.style.minWidth = '';
        const cells = tableEl.querySelectorAll(`tr > *:nth-child(${index + 1})`);
        let max = 0;
        cells.forEach(cell => {
          const style = window.getComputedStyle(cell);
          const width = cell.scrollWidth + parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
          if (width > max) max = width;
        });
        if (max > 0) {
          const finalWidth = Math.max(MIN_WIDTH, Math.ceil(max));
          col.style.width = `${finalWidth}px`;
          col.style.minWidth = `${finalWidth}px`;
        }
      });
      th.appendChild(handle);
    });
  }

  enableColumnResizing(table);

  const tableWrapper = container ? container.querySelector('.table-wrapper') : null;
  if (tableWrapper) {
    let scrollHideTimer = null;
    const revealScrollbar = () => {
      if (tableWrapper.scrollWidth <= tableWrapper.clientWidth) return;
      tableWrapper.classList.add('is-scrolling');
      if (scrollHideTimer) clearTimeout(scrollHideTimer);
      scrollHideTimer = setTimeout(() => {
        tableWrapper.classList.remove('is-scrolling');
        scrollHideTimer = null;
      }, 700);
    };
    tableWrapper.addEventListener('scroll', () => {
      revealScrollbar();
    }, { passive: true });
    tableWrapper.addEventListener('mouseleave', () => {
      tableWrapper.classList.remove('is-scrolling');
      if (scrollHideTimer) {
        clearTimeout(scrollHideTimer);
        scrollHideTimer = null;
      }
    });
  }

  window.__refreshClientSearchCache = function(uid){
    const key = String(uid || '');
    if (!key) return;
    refreshSearchCache(key);
    if (searchInput) {
      applySearch(searchInput.value);
    }
  };

  window.__reapplyClientSort = function(){
    updateSortIndicators(sortState.key, sortState.dir);
    applySort(sortState.key, sortState.dir);
  };

  applySearch(searchInput ? searchInput.value : '');
  updateSelectAll();
})();

// --- Assign Plan modal wiring ---
(function(){
  const btn = document.getElementById('ppContinue');
  const sel = document.getElementById('ppPlanSel');
  if (!btn || !sel) return;
  window.__PPick = window.__PPick || { uid: null };

  btn.onclick = async function(){
    const pid = parseInt(sel.value, 10);
    const uid = window.__PPick?.uid;
    if (!pid || !uid) return;

    const prev = btn.textContent;
    btn.textContent = 'Assigning…';
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('csrf_token', window.__CSRF);
      fd.append('action', 'assign_plan_to_user');
      fd.append('user_id', String(uid));
      fd.append('plan_id', String(pid));

      const res = await fetch('clients.php', { method:'POST', body: fd });
      const json = await res.json();
      if (!json || !json.ok) throw new Error((json && json.error) || 'Failed to assign plan');

      window.__PLANS_BY_USER = window.__PLANS_BY_USER || {};
      const arr = window.__PLANS_BY_USER[uid] || [];
      if (!json.already_assigned) {
        arr.push(json.plan);
        window.__PLANS_BY_USER[uid] = arr;
      }

      const mainRow = document.querySelector(`tr.client-row[data-uid="${uid}"]`);
      if (mainRow) {
        const plansCell = mainRow.querySelector('td:nth-child(13)');
        if (plansCell) {
          const n = parseInt(plansCell.textContent.trim(), 10);
          const nextVal = isNaN(n) ? 1 : (n + (json.already_assigned ? 0 : 1));
          plansCell.textContent = String(nextVal);
          mainRow.dataset.sortPlans = String(nextVal);
        }
      }

      if (typeof window.__refreshClientSearchCache === 'function') {
        window.__refreshClientSearchCache(uid);
      }
      if (typeof window.__reapplyClientSort === 'function') {
        window.__reapplyClientSort();
      }

      const exp = document.getElementById('exp-'+uid);
      const body = exp ? exp.querySelector('[data-exp-body]') : null;
      if (body && body.dataset.rendered === '1') {
        body.removeAttribute('data-rendered');
        body.innerHTML = '<div class="muted">Loading plans…</div>';
        exp.style.display = 'none';
        const hdr = exp.previousElementSibling;
        hdr?.classList.remove('expanded');
        hdr?.dispatchEvent(new MouseEvent('click', { bubbles:true }));
      }

      document.getElementById('ppCancel')?.click();
    } catch (err) {
      alert(err.message || 'Failed to assign plan.');
    } finally {
      btn.textContent = 'Assign Plan';
      btn.disabled = false;
    }
  };
})();

// --- Build a client's expansion (plans + nested exercises) ---
function renderClientExpansion(uid, body){
  const plans = (window.__PLANS_BY_USER && window.__PLANS_BY_USER[uid]) || [];
  const userEx = (window.__USER_EX && window.__USER_EX[uid]) || {};
  let html = '';

  html += `
    <div>
      <div class="section-title">Assigned Plans</div>
      <table style="width:100%;border-collapse:collapse;border:1px solid var(--line);border-radius:8px;overflow:hidden">
        <thead>
          <tr>
            <th style="background:#0f1218;padding:8px 10px;width:44px;text-align:center">Select</th>
            <th style="background:#0f1218;padding:8px 10px">Plan ID</th>
            <th style="background:#0f1218;padding:8px 10px">Name</th>
            <th style="background:#0f1218;padding:8px 10px">Assigned</th>
            <th style="background:#0f1218;padding:8px 10px">Created</th>
            <th style="background:#0f1218;padding:8px 10px">Created By</th>
            <th style="background:#0f1218;padding:8px 10px">Updated</th>
            <th style="background:#0f1218;padding:8px 10px">Updated By</th>
            <th style="background:#0f1218;padding:8px 10px">Exercises</th>
            <th style="background:#0f1218;padding:8px 10px">Actions</th>
          </tr>
        </thead>
        <tbody>
  `;

  if (!plans.length) {
    html += `<tr><td colspan="10" class="muted empty-state" style="padding:10px">No plans assigned.</td></tr>`;
  } else {
    plans.forEach(p=>{
      const assigned = p.assigned_at_fmt || '—';
      const created  = p.created_at_fmt  || '—';
      const updated  = p.updated_at_fmt  || '—';
      const createdBy= p.created_by_name || '—';
      const updatedBy= p.updated_by_name || '—';
      const exCount  = p.exercise_count ?? 0;

      html += `
        <tr class="plan-row" data-plan-id="${p.id}">
          <td style="padding:8px 10px;text-align:center">
            <input type="checkbox" class="plan-select" data-plan-checkbox data-plan-user="${uid}" value="${p.id}" aria-label="Select plan #${p.id}">
          </td>
          <td style="padding:8px 10px">${p.id}</td>
          <td style="padding:8px 10px"><strong>${escapeHtml(p.name||'')}</strong></td>
          <td class="muted" style="padding:8px 10px">${assigned}</td>
          <td class="muted" style="padding:8px 10px">${created}</td>
          <td class="muted" style="padding:8px 10px">${escapeHtml(createdBy)}</td>
          <td class="muted" style="padding:8px 10px">${updated}</td>
          <td class="muted" style="padding:8px 10px">${escapeHtml(updatedBy)}</td>
          <td style="padding:8px 10px">${exCount}</td>
          <td style="padding:8px 10px">
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Unassign this plan from this client? This will remove the plan and any per-exercise settings for this client.');">
              <input type="hidden" name="csrf_token" value="${window.__CSRF}">
              <input type="hidden" name="action" value="unassign_plan">
              <input type="hidden" name="user_id" value="${uid}">
              <input type="hidden" name="plan_id" value="${p.id}">
              <button class="btn small" type="submit"
                      style="border-color:#ef4444;color:#ef4444;background:#1a2232">Unassign</button>
            </form>
          </td>
        </tr>
      `;

      const exs = (window.__EX_BY_PLAN && window.__EX_BY_PLAN[p.id]) || [];
      let exHtml = `
        <div style="padding:8px 4px">
          <div class="section-title">Exercises in this Plan</div>
          <table style="width:100%;border-collapse:collapse;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#0f1218">
            <thead>
              <tr>
                <th style="padding:8px 10px">Ex ID</th>
                <th style="padding:8px 10px">Name</th>
                <th style="padding:8px 10px">Notes</th>
                <th style="padding:8px 10px">Categories</th>
                <th style="padding:8px 10px">Media</th>
                <th style="padding:8px 10px">Sets</th>
                <th style="padding:8px 10px">Reps</th>
                <th style="padding:8px 10px">Weight</th>
                <th style="padding:8px 10px">Duration</th>
                <th style="padding:8px 10px">Updated</th>
                <th style="padding:8px 10px">Updated By</th>
                <th style="padding:8px 10px">Actions</th>
              </tr>
            </thead>
            <tbody>
      `;
      if (!exs.length) {
        exHtml += `<tr><td colspan="12" class="muted empty-state" style="padding:10px">No exercises in this plan.</td></tr>`;
      } else {
        exs.forEach(ex=>{
          const per = (userEx[p.id] && userEx[p.id][ex.ex_id]) || {};
          const sets = per?.sets ?? '—';
          const reps = per?.reps ?? '—';
          const weightRaw = per?.weight_value ?? '';
          const weightDisp = per?.weight_display ?? '—';
          const durRaw = per?.duration_seconds ?? '';
          const durDisp = per?.duration_display ?? '—';

          // ONLY user-specific notes; show dash if empty
          const userNoteRaw = (per && per.notes != null) ? String(per.notes) : '';
          const showNotes   = userNoteRaw.replace(/\r\n/g, '\n').trim() || '—';
          const editedAt   = per && per.updated_at ? per.updated_at : null;
          const editedBy   = per && per.updated_by_name ? per.updated_by_name : null;

          const exLink = `exercises.php?focus_exercise=${encodeURIComponent(ex.ex_id)}#ex-${encodeURIComponent(ex.ex_id)}`;
          const catList = Array.isArray(ex.categories) ? ex.categories : [];
          const catDisplay = renderCategoryChips(catList);
          const videoUrl = ex.video_url || '';
          const hasVideo = !!videoUrl;
          const videoAttrs = hasVideo
            ? ` data-video-url="${escapeHtml(videoUrl)}" data-ex-name="${escapeHtml(ex.name||'')}"`
              + ` data-ex-desc="${escapeHtml(ex.notes||'')}" data-ex-link="${escapeHtml(exLink)}"`
            : '';
          const videoCell = hasVideo
            ? `<span class="chip video-chip"${videoAttrs}>▶ Video</span>`
            : '<span class="muted">—</span>';
          const weightAttr = weightRaw === null || weightRaw === undefined || weightRaw === '' ? '' : ` data-raw="${escapeHtml(String(weightRaw))}"`;
          const durAttr = durRaw === null || durRaw === undefined || durRaw === '' ? '' : ` data-raw="${escapeHtml(String(durRaw))}"`;

          exHtml += `
            <tr class="mini-ex-row" data-ex-id="${ex.ex_id}" data-user-id="${uid}" data-plan-id="${p.id}">
              <td style="padding:8px 10px">${ex.ex_id}</td>
              <td style="padding:8px 10px"><a href="${exLink}" class="mini-ex-link"><strong>${escapeHtml(ex.name||'')}</strong></a></td>
              <td class="muted" style="padding:8px 10px" data-cell="notes">${escapeHtml(showNotes)}</td>
              <td style="padding:8px 10px" data-cell="categories">${catDisplay}</td>
              <td style="padding:8px 10px">${videoCell}</td>
              <td style="padding:8px 10px" data-cell="sets"      class="editable">${sets}</td>
              <td style="padding:8px 10px" data-cell="reps"      class="editable">${reps}</td>
              <td style="padding:8px 10px" data-cell="weight"    class="editable"${weightAttr}>${escapeHtml(weightDisp)}</td>
              <td style="padding:8px 10px" data-cell="duration"  class="editable"${durAttr}>${escapeHtml(durDisp)}</td>
              <td class="muted" style="padding:8px 10px" data-cell="edited">${editedAt ? escapeHtml(editedAt) : '—'}</td>
              <td class="muted" style="padding:8px 10px" data-cell="edited_by">${editedBy ? escapeHtml(editedBy) : '—'}</td>
              <td style="padding:8px 10px" data-cell="actions">
                <button class="btn small" type="button" data-ex-edit>Edit</button>
              </td>
            </tr>
          `;
        });
      }
      exHtml += `
            <tr class="add-ex-row" data-plan-id="${p.id}">
              <td colspan="12" style="padding:10px">
                <div style="display:flex;align-items:center;gap:10px">
                  <span style="font-size:18px;line-height:1">+</span>
                  <strong>Add an exercise</strong>
                  <span class="muted" style="margin-left:6px">Open the plan editor to add an exercise…</span>
                </div>
              </td>
            </tr>
          </tbody></table>
        </div>
      `;
      html += `<tr class="plan-expand" id="pexp-${p.id}" data-user-id="${uid}" style="display:none"><td colspan="10">${exHtml}</td></tr>`;
    });
  }

  html += `
        <tr class="add-plan-row" data-add-for="${uid}">
          <td colspan="10" style="padding:10px">
            <div style="display:flex;align-items:center;gap:10px">
              <span style="font-size:18px;line-height:1">+</span>
              <strong>Assign a plan</strong>
              <span class="muted" style="margin-left:6px">Pick an existing plan to assign…</span>
            </div>
          </td>
        </tr>
      </tbody></table>
    </div>
  `;

  body.innerHTML = html;
  body.dataset.rendered = '1';
}

// --- Click: toggle a client row and render on first open ---
document.addEventListener('click', function(e){
  const row = e.target.closest('tr.client-row');
  if (!row) return;
  if (e.target.closest('a,button,input,select,textarea,label,form')) return;

  const uid = row.getAttribute('data-uid');
  const exp = document.getElementById('exp-'+uid);
  if (!exp) return;

  const isOpen = (exp.style.display === 'table-row');

  document.querySelectorAll('tr.client-expand').forEach(tr=>{
    tr.style.display = 'none';
    const hdr = tr.previousElementSibling;
    if (hdr && hdr.classList.contains('client-row')) hdr.classList.remove('expanded');
  });

  if (!isOpen) {
    const body = exp.querySelector('[data-exp-body]');
    if (body && !body.dataset.rendered) {
      renderClientExpansion(parseInt(uid,10), body);
    }
    exp.style.display = 'table-row';
    row.classList.add('expanded');
  } else {
    exp.style.display = 'none';
    row.classList.remove('expanded');
  }
});

// Toggle a PLAN row inside a client expansion (ignore controls)
document.addEventListener('click', function(e){
  const pr = e.target.closest('tr.plan-row');
  if (!pr) return;
  if (e.target.closest('a, button, input, select, textarea, label, form')) return;

  const pid = pr.getAttribute('data-plan-id');

  let pexp = pr.nextElementSibling;
  if (!pexp || !pexp.classList.contains('plan-expand') || pexp.id !== ('pexp-' + pid)) {
    pexp = document.getElementById('pexp-' + pid);
  }
  if (!pexp) return;

  const tbody = pr.parentElement;
  const isOpen = (pexp.style.display === 'table-row');

  tbody.querySelectorAll('tr.plan-expand').forEach(row => {
    row.style.display = 'none';
    const header = row.previousElementSibling;
    if (header && header.classList.contains('plan-row')) {
      header.classList.remove('expanded');
    }
  });

  if (!isOpen) {
    pexp.style.display = 'table-row';
    pr.classList.add('expanded');
  } else {
    pexp.style.display = 'none';
    pr.classList.remove('expanded');
  }
});

// ====== Legacy row-level edit (kept) ======
function makeInput(value, { type='text', step=null, min=null, placeholder='', width='120px' } = {}) {
  const input = document.createElement('input');
  input.className = 'input';
  input.style.width = width;
  input.type = type;
  if (step !== null) input.step = step;
  if (min !== null) input.min = min;
  input.placeholder = placeholder;
  input.value = value ?? '';
  return input;
}

function setActionsToEdit(tr){
  const cell = tr?.querySelector('[data-cell="actions"]');
  if (!cell) return;
  cell.innerHTML = '';
  const btn = document.createElement('button');
  btn.className = 'btn small';
  btn.type = 'button';
  btn.dataset.exEdit = '';
  btn.setAttribute('data-ex-edit', '');
  btn.textContent = 'Edit';
  cell.appendChild(btn);
}

function normalizeEmpty(value){
  return (value === null || value === undefined || value === '') ? null : value;
}

function computeWeightDisplay(raw, provided){
  if (provided) return String(provided);
  if (raw === null || raw === undefined || raw === '') return null;
  const num = Number(raw);
  if (!Number.isFinite(num)) return String(raw);
  const fixed = Number.isInteger(num) ? String(num) : num.toFixed(2).replace(/\.0+$/, '').replace(/\.$/, '');
  return `${fixed} lbs`;
}

function computeDurationDisplay(raw, provided){
  if (provided) return String(provided);
  if (raw === null || raw === undefined || raw === '') return null;
  const num = Number(raw);
  if (!Number.isFinite(num)) return String(raw);
  const total = Math.max(0, Math.round(num));
  const mins = Math.floor(total / 60);
  const secs = total % 60;
  const parts = [];
  if (mins > 0) parts.push(`${mins} min${mins === 1 ? '' : 's'}`);
  if (secs > 0 || parts.length === 0) parts.push(`${secs} sec${secs === 1 ? '' : 's'}`);
  return parts.join(' ');
}

function setCellValue(cell, raw, display, type = 'text'){
  if (!cell) return;

  const normalizedRaw = normalizeEmpty(raw);
  if (normalizedRaw === null) {
    delete cell.dataset.raw;
  } else {
    cell.dataset.raw = String(normalizedRaw);
  }

  let shown = null;
  if (type === 'weight') {
    shown = normalizeEmpty(display) ?? computeWeightDisplay(normalizedRaw, display);
  } else if (type === 'duration') {
    shown = normalizeEmpty(display) ?? computeDurationDisplay(normalizedRaw, display);
  } else if (type === 'notes') {
    shown = normalizeEmpty(display) ?? (normalizedRaw !== null ? String(normalizedRaw) : null);
  } else {
    shown = normalizeEmpty(display) ?? (normalizedRaw !== null ? String(normalizedRaw) : null);
  }

  cell.textContent = (shown === null || shown === undefined || shown === '') ? '—' : shown;
}

function applyUserExerciseData(tr, data){
  if (!tr || !data) return;

  const cells = {
    sets: tr.querySelector('[data-cell="sets"]'),
    reps: tr.querySelector('[data-cell="reps"]'),
    weight: tr.querySelector('[data-cell="weight"]'),
    duration: tr.querySelector('[data-cell="duration"]'),
    notes: tr.querySelector('[data-cell="notes"]'),
    edited: tr.querySelector('[data-cell="edited"]'),
    edited_by: tr.querySelector('[data-cell="edited_by"]')
  };

  if (cells.sets && 'sets' in data) setCellValue(cells.sets, data.sets, data.sets);
  if (cells.reps && 'reps' in data) setCellValue(cells.reps, data.reps, data.reps);

  const weightRaw = ('weight_value' in data) ? data.weight_value : (('weight' in data && typeof data.weight === 'number') ? data.weight : (('weight' in data) ? data.weight : undefined));
  const weightDisplay = ('weight_display' in data) ? data.weight_display : (('weight' in data && typeof data.weight === 'string') ? data.weight : undefined);
  if (cells.weight && (weightRaw !== undefined || weightDisplay !== undefined)) {
    setCellValue(cells.weight, weightRaw, weightDisplay, 'weight');
  }

  const durationRaw = ('duration_seconds' in data) ? data.duration_seconds : (('duration' in data && typeof data.duration === 'number') ? data.duration : (('duration' in data) ? data.duration : undefined));
  const durationDisplay = ('duration_display' in data) ? data.duration_display : (('duration' in data && typeof data.duration === 'string') ? data.duration : undefined);
  if (cells.duration && (durationRaw !== undefined || durationDisplay !== undefined)) {
    setCellValue(cells.duration, durationRaw, durationDisplay, 'duration');
  }

  if (cells.notes && 'notes' in data) {
    const notesVal = data.notes ?? null;
    setCellValue(cells.notes, notesVal, notesVal, 'notes');
  }

  if (cells.edited && 'updated_at' in data) {
    cells.edited.textContent = data.updated_at ? String(data.updated_at) : '—';
  }
  if (cells.edited_by && 'updated_by_name' in data) {
    cells.edited_by.textContent = data.updated_by_name ? String(data.updated_by_name) : '—';
  }

  const uid = parseInt(tr.dataset.userId, 10);
  const planId = parseInt(tr.dataset.planId, 10);
  const exId = parseInt(tr.dataset.exId, 10);
  if (!uid || !planId || !exId) return;

  window.__USER_EX = window.__USER_EX || {};
  if (!window.__USER_EX[uid]) window.__USER_EX[uid] = {};
  if (!window.__USER_EX[uid][planId]) window.__USER_EX[uid][planId] = {};
  const existing = window.__USER_EX[uid][planId][exId] || {};

  const normalizedSets = ('sets' in data) ? (data.sets === '' ? null : data.sets ?? null) : (existing.sets ?? null);
  const normalizedReps = ('reps' in data) ? (data.reps === '' ? null : data.reps ?? null) : (existing.reps ?? null);

  const normalizedWeightValue = (weightRaw !== undefined) ? normalizeEmpty(weightRaw) : (existing.weight_value ?? null);
  const normalizedWeightDisplay = (weightDisplay !== undefined)
    ? normalizeEmpty(weightDisplay) ?? computeWeightDisplay(normalizedWeightValue, weightDisplay)
    : (existing.weight_display ?? ((existing.weight_value !== undefined && existing.weight_value !== null) ? computeWeightDisplay(existing.weight_value, existing.weight_display) : null));

  const normalizedDurationValue = (durationRaw !== undefined) ? normalizeEmpty(durationRaw) : (existing.duration_seconds ?? null);
  const normalizedDurationDisplay = (durationDisplay !== undefined)
    ? normalizeEmpty(durationDisplay) ?? computeDurationDisplay(normalizedDurationValue, durationDisplay)
    : (existing.duration_display ?? ((existing.duration_seconds !== undefined && existing.duration_seconds !== null) ? computeDurationDisplay(existing.duration_seconds, existing.duration_display) : null));

  const normalizedNotes = ('notes' in data) ? (data.notes === '' ? null : data.notes ?? null) : (existing.notes ?? null);
  const normalizedUpdatedAt = ('updated_at' in data) ? (data.updated_at ?? null) : (existing.updated_at ?? null);
  const normalizedUpdatedBy = ('updated_by_name' in data) ? (data.updated_by_name ?? null) : (existing.updated_by_name ?? null);

  window.__USER_EX[uid][planId][exId] = {
    ...existing,
    sets: normalizedSets,
    reps: normalizedReps,
    weight_value: normalizedWeightValue,
    weight_display: normalizedWeightDisplay,
    duration_seconds: normalizedDurationValue,
    duration_display: normalizedDurationDisplay,
    notes: normalizedNotes,
    updated_at: normalizedUpdatedAt,
    updated_by_name: normalizedUpdatedBy
  };

  if (typeof window.__refreshClientSearchCache === 'function') {
    window.__refreshClientSearchCache(uid);
  }
}

function startRowEdit(tr){
  if (tr.dataset.editing === '1') return;
  tr.dataset.editing = '1';

  const getCell = name => tr.querySelector(`[data-cell="${name}"]`);
  const setsCell = getCell('sets');
  const repsCell = getCell('reps');
  const weightCell = getCell('weight');
  const durCell = getCell('duration');
  const notesCell = getCell('notes');
  const actionsCell = getCell('actions');

  const uid = parseInt(tr.dataset.userId, 10);
  const planId = parseInt(tr.dataset.planId, 10);
  const exId = parseInt(tr.dataset.exId, 10);
  const stored = (window.__USER_EX && window.__USER_EX[uid] && window.__USER_EX[uid][planId] && window.__USER_EX[uid][planId][exId]) || {};
  const toInputValue = (v) => (v === null || v === undefined ? '' : String(v));

  tr._origValues = {
    sets: toInputValue(stored.sets ?? (setsCell.dataset.raw ?? (setsCell.textContent.trim() === '—' ? '' : setsCell.textContent.trim()))),
    reps: toInputValue(stored.reps ?? (repsCell.dataset.raw ?? (repsCell.textContent.trim() === '—' ? '' : repsCell.textContent.trim()))),
    weight: toInputValue(stored.weight_value ?? stored.weight ?? (weightCell.dataset.raw ?? (weightCell.textContent.trim() === '—' ? '' : weightCell.textContent.trim()))),
    duration: toInputValue(stored.duration_seconds ?? stored.duration ?? (durCell.dataset.raw ?? (durCell.textContent.trim() === '—' ? '' : durCell.textContent.trim()))),
    notes: toInputValue(stored.notes ?? (notesCell.dataset.raw ?? (notesCell.textContent.trim() === '—' ? '' : notesCell.textContent.trim()))),
    actionsHTML: actionsCell.innerHTML
  };

  setsCell.innerHTML = '';
  repsCell.innerHTML = '';
  weightCell.innerHTML = '';
  durCell.innerHTML = '';
  notesCell.innerHTML = '';

  const iSets = makeInput(tr._origValues.sets,   { type:'text',    placeholder:'e.g. 3 or 3x' });
  const iReps = makeInput(tr._origValues.reps,   { type:'text',    placeholder:'e.g. 8-10' });
  const iWeight = makeInput(tr._origValues.weight,{ type:'number',  step:'0.1', min:'0', placeholder:'lbs' });
  const iDur = makeInput(tr._origValues.duration,{ type:'text',    placeholder:'e.g. 1:30 or 90 sec' });
  const iNotes = makeInput(tr._origValues.notes, { type:'text',    placeholder:'user notes', width:'240px' });

  iSets.name = 'sets';
  iReps.name = 'reps';
  iWeight.name = 'weight_lbs';
  iDur.name = 'duration_seconds';
  iNotes.name = 'user_notes';

  setsCell.appendChild(iSets);
  repsCell.appendChild(iReps);
  weightCell.appendChild(iWeight);
  durCell.appendChild(iDur);
  notesCell.appendChild(iNotes);

  actionsCell.innerHTML = '';
  const btnSave = document.createElement('button');
  btnSave.className = 'btn small brand';
  btnSave.type = 'button';
  btnSave.textContent = 'Save';
  btnSave.addEventListener('click', () => saveRowEdit(tr));

  const btnCancel = document.createElement('button');
  btnCancel.className = 'btn small';
  btnCancel.type = 'button';
  btnCancel.style.marginLeft = '6px';
  btnCancel.textContent = 'Cancel';
  btnCancel.addEventListener('click', () => cancelRowEdit(tr));

  actionsCell.appendChild(btnSave);
  actionsCell.appendChild(btnCancel);
}

function cancelRowEdit(tr){
  if (!tr._origValues) return;
  const toNull = v => (v === '' ? null : v);
  applyUserExerciseData(tr, {
    sets: toNull(tr._origValues.sets),
    reps: toNull(tr._origValues.reps),
    weight_value: toNull(tr._origValues.weight),
    duration_seconds: toNull(tr._origValues.duration),
    notes: toNull(tr._origValues.notes)
  });
  const actionsCell = tr.querySelector('[data-cell="actions"]');
  actionsCell.innerHTML = tr._origValues.actionsHTML;
  tr.dataset.editing = '0';
  delete tr._origValues;
}

async function saveRowEdit(tr){
  const uid = parseInt(tr.dataset.userId, 10);
  const planId = parseInt(tr.dataset.planId, 10);
  const exId = parseInt(tr.dataset.exId, 10);
  const sets = tr.querySelector('input[name="sets"]').value.trim();
  const reps = tr.querySelector('input[name="reps"]').value.trim();
  const weight = tr.querySelector('input[name="weight_lbs"]').value.trim();
  const duration = tr.querySelector('input[name="duration_seconds"]').value.trim();
  const notes = tr.querySelector('input[name="user_notes"]').value.trim();

  const fd = new FormData();
  fd.append('csrf_token', window.__CSRF);
  fd.append('action', 'save_user_exercise');
  fd.append('user_id', String(uid));
  fd.append('plan_id', String(planId));
  fd.append('exercise_id', String(exId));
  fd.append('sets', sets);
  fd.append('reps', reps);
  fd.append('weight_lbs', weight);
  fd.append('duration_seconds', duration);
  fd.append('user_notes', notes);

  const actionsCell = tr.querySelector('[data-cell="actions"]');
  const prevHTML = actionsCell.innerHTML;
  actionsCell.innerHTML = '<span class="muted">Saving…</span>';

  try {
    const res = await fetch('clients.php', { method:'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) throw new Error((json && json.error) || 'Save failed');

    applyUserExerciseData(tr, json.data);
    setActionsToEdit(tr);
    tr.dataset.editing = '0';
    delete tr._origValues;
  } catch (err) {
    alert(err.message || 'Failed to save.');
    actionsCell.innerHTML = prevHTML;
  }
}

// Start editing when clicking Edit (legacy)
document.addEventListener('click', function(e){
  const btn = e.target.closest('[data-ex-edit]');
  if (!btn) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  const tr = btn.closest('tr.mini-ex-row');
  if (!tr) return;
  startRowEdit(tr);
});

// --- Add Exercises modal logic (unchanged except notes cell in new rows) ---
let __AddEx = { planId: null, userId: null };
function aeOpen(planId, userId){
  __AddEx.planId = parseInt(planId,10);
  __AddEx.userId = parseInt(userId||0,10) || null;
  const existing = new Set(((window.__EX_BY_PLAN && window.__EX_BY_PLAN[planId]) || []).map(x=>x.ex_id));
  const box = document.getElementById('aeList');
  box.innerHTML = '';
  const all = window.__ALL_EXERCISES || [];
  let any = false;
  all.forEach(ex=>{
    if (!existing.has(ex.id)) {
      any = true;
      const id = `ae_${planId}_${ex.id}`;
      const row = document.createElement('label');
      row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-bottom:6px';
      row.innerHTML = `
        <input type="checkbox" value="${ex.id}" id="${id}">
        <span><strong>${escapeHtml(ex.name||('Exercise #'+ex.id))}</strong> <span class="fine">(ID ${ex.id})</span></span>
      `;
      box.appendChild(row);
    }
  });
  if (!any) box.innerHTML = '<div class="muted">All exercises are already in this plan.</div>';

  document.getElementById('aePlanText').textContent = `Plan ID: ${planId}`;
  document.getElementById('mdAddEx').style.display = 'block';
  document.getElementById('bdAddEx').style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function aeClose(){
  document.getElementById('mdAddEx').style.display = 'none';
  document.getElementById('bdAddEx').style.display = 'none';
  document.body.style.overflow = '';
  __AddEx = { planId:null, userId:null };
}
document.getElementById('aeCancel')?.addEventListener('click', aeClose);
document.getElementById('bdAddEx')?.addEventListener('click', aeClose);
document.addEventListener('click', function(e){
  const addEx = e.target.closest('.add-ex-row');
  if (!addEx) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  e.stopPropagation();
  const pid = addEx.getAttribute('data-plan-id');
  const expandTr = addEx.closest('tr.plan-expand');
  const planId = parseInt(pid, 10);
  aeOpen(planId, null);
});
document.getElementById('aeAdd')?.addEventListener('click', async ()=>{
  const planId = __AddEx.planId;
  if (!planId) return;

  const ids = Array.from(document.querySelectorAll('#aeList input[type="checkbox"]:checked'))
    .map(i=>parseInt(i.value,10)).filter(n=>!isNaN(n) && n>0);

  if (!ids.length) { aeClose(); return; }

  const fd = new FormData();
  fd.append('csrf_token', window.__CSRF);
  fd.append('action', 'add_exercises_to_plan');
  fd.append('plan_id', String(planId));
  fd.append('exercise_ids', JSON.stringify(ids));

  const btn = document.getElementById('aeAdd');
  const prev = btn.textContent;
  btn.textContent = 'Adding…'; btn.disabled = true;

  try {
    const res = await fetch('clients.php', { method:'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) throw new Error((json && json.error) || 'Failed to add exercises.');

    window.__EX_BY_PLAN = window.__EX_BY_PLAN || {};
    const arr = window.__EX_BY_PLAN[planId] || [];
    (json.added || []).forEach(ex=>{
      arr.push({
        ex_id: ex.ex_id,
        name: ex.name,
        notes: ex.notes || '',
        has_video: !!(ex.video_url || ex.has_video),
        video_url: ex.video_url || '',
        categories: Array.isArray(ex.categories) ? ex.categories : [],
        updated_at: ex.updated_at || null,
        updated_by_name: ex.updated_by_name || null
      });
    });
    window.__EX_BY_PLAN[planId] = arr;

    const exp = document.getElementById('pexp-'+planId);
    if (exp) {
      const tbody = exp.querySelector('tbody');
      const addRow = exp.querySelector('.add-ex-row');
      (json.added || []).forEach(ex=>{
        const tr = document.createElement('tr');
        tr.className = 'mini-ex-row';
        tr.setAttribute('data-ex-id', String(ex.ex_id));
        tr.setAttribute('data-user-id', __AddEx.userId ? String(__AddEx.userId) : '');
        tr.setAttribute('data-plan-id', String(planId));
        const exLink = `exercises.php?focus_exercise=${encodeURIComponent(ex.ex_id)}#ex-${encodeURIComponent(ex.ex_id)}`;
        const catList = Array.isArray(ex.categories) ? ex.categories : [];
        const catDisplay = renderCategoryChips(catList);
        const videoUrl = ex.video_url || '';
        const hasVideo = !!videoUrl;
        const videoAttrs = hasVideo
          ? ` data-video-url="${escapeHtml(videoUrl)}" data-ex-name="${escapeHtml(ex.name||'')}"`
            + ` data-ex-desc="${escapeHtml(ex.notes||'')}" data-ex-link="${escapeHtml(exLink)}"`
          : '';
        const videoChip = hasVideo
          ? `<span class="chip video-chip"${videoAttrs}>▶ Video</span>`
          : '<span class="muted">—</span>';
        tr.innerHTML = `
          <td style="padding:8px 10px">${ex.ex_id}</td>
          <td style="padding:8px 10px"><a href="${exLink}" class="mini-ex-link"><strong>${escapeHtml(ex.name||'')}</strong></a></td>
          <td style="padding:8px 10px" class="muted" data-cell="notes">—</td>
          <td style="padding:8px 10px" data-cell="categories">${catDisplay}</td>
          <td style="padding:8px 10px">${videoChip}</td>
          <td style="padding:8px 10px" data-cell="sets"     class="editable">—</td>
          <td style="padding:8px 10px" data-cell="reps"     class="editable">—</td>
          <td style="padding:8px 10px" data-cell="weight"   class="editable">—</td>
          <td style="padding:8px 10px" data-cell="duration" class="editable">—</td>
          <td style="padding:8px 10px" class="muted" data-cell="edited">—</td>
          <td style="padding:8px 10px" class="muted" data-cell="edited_by">—</td>
          <td style="padding:8px 10px" data-cell="actions">
            <button class="btn small" type="button" data-ex-edit>Edit</button>
          </td>
        `;
        if (addRow && addRow.parentNode) {
          addRow.parentNode.insertBefore(tr, addRow);
        } else if (tbody) {
          tbody.appendChild(tr);
        }
      });
    }

    aeClose();
  } catch (err) {
    alert(err.message || 'Failed to add exercises.');
  } finally {
    btn.textContent = prev; btn.disabled = false;
  }
});

// --- Add-plan picker open/close ---
function ppOpen(uid){
  window.__PPick = window.__PPick || { uid:null };
  window.__PPick.uid = parseInt(uid,10);
  const sel = document.getElementById('ppPlanSel');
  const txt = document.getElementById('ppUserText');
  sel.innerHTML = '';
  (window.__ALL_PLANS || []).forEach(p=>{
    const opt = document.createElement('option');
    opt.value = String(p.id);
    opt.textContent = `${p.name} (ID ${p.id})`;
    sel.appendChild(opt);
  });
  txt.textContent = `User ID: ${uid}`;
  document.getElementById('mdPickPlan').style.display = 'block';
  document.getElementById('bdPickPlan').style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function ppClose(){
  document.getElementById('mdPickPlan').style.display = 'none';
  document.getElementById('bdPickPlan').style.display = 'none';
  document.body.style.overflow = '';
}
document.addEventListener('click', function(e){
  const addRow = e.target.closest('.add-plan-row');
  if (!addRow) return;
  const uid = addRow.getAttribute('data-add-for');
  if (uid) ppOpen(uid);
});
document.getElementById('ppCancel')?.addEventListener('click', ppClose);
document.getElementById('bdPickPlan')?.addEventListener('click', ppClose);

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }

// ==== INLINE CELL EDIT (NEW) ====
// Only one cell editor active at a time
let __ActiveCell = null;

function openCellEditor(td){
  if (!td) return;
  // If another cell is active, try to save it first if changed; otherwise close it
  if (__ActiveCell && __ActiveCell !== td) {
    closeCellEditor(__ActiveCell, {saveIfChanged:true});
  }
  if (td.classList.contains('editing')) return;

  const tr = td.closest('tr.mini-ex-row');
  if (!tr) return;

  const col = td.getAttribute('data-cell'); // sets|reps|weight|duration|notes
  if (!['sets','reps','weight','duration','notes'].includes(col)) return;

  const displayBefore = td.textContent.trim();
  const rawBefore = td.dataset.raw !== undefined ? td.dataset.raw : (displayBefore === '—' ? '' : displayBefore);
  td.dataset.originalRaw = rawBefore;
  td.dataset.originalDisplay = displayBefore;
  td.classList.add('editing');

  const input = document.createElement('input');
  input.className = 'input';
  input.style.width = (col === 'notes') ? '240px' : '120px';
  input.placeholder = (col === 'sets' ? 'e.g. 3 or 3x'
                     : col === 'reps' ? 'e.g. 8-10'
                     : col === 'weight' ? 'lbs'
                     : col === 'duration' ? 'e.g. 1:30 or 90 sec'
                     : 'user notes');

  if (col === 'weight') { input.type = 'number'; input.step = '0.1'; input.min = '0'; }
  else if (col === 'duration') { input.type = 'text'; }
  else { input.type = 'text'; }

  input.value = rawBefore || '';
  td.innerHTML = '';
  td.appendChild(input);
  input.focus();
  input.select();

  // Enter saves, Esc cancels
  input.addEventListener('keydown', (ev)=>{
    if (ev.key === 'Enter') {
      ev.preventDefault();
      closeCellEditor(td, {saveIfChanged:true});
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      closeCellEditor(td, {save:false});
    }
  }, {capture:true});

  // Blur saves if changed
  input.addEventListener('blur', ()=>{
    // small timeout to allow click to move elsewhere without double-handling
    setTimeout(()=>closeCellEditor(td, {saveIfChanged:true}), 0);
  });

  __ActiveCell = td;
}

function getCellsPayload(tr){
  const get = name => {
    const cell = tr.querySelector(`[data-cell="${name}"]`);
    if (!cell) return '';
    // If cell is currently editing, read from input, else from text
    const inp = cell.querySelector('input');
    if (inp) {
      return inp.value.trim();
    }
    if (cell.dataset.raw !== undefined) {
      return cell.dataset.raw;
    }
    const val = cell.textContent.trim();
    return (val === '—') ? '' : val;
  };
  return {
    sets: get('sets'),
    reps: get('reps'),
    weight_lbs: get('weight'),
    duration_seconds: get('duration'),
    user_notes: get('notes')
  };
}

async function saveCell(tr){
  const uid = parseInt(tr.dataset.userId, 10);
  const planId = parseInt(tr.dataset.planId, 10);
  const exId = parseInt(tr.dataset.exId, 10);
  const payload = getCellsPayload(tr);

  const fd = new FormData();
  fd.append('csrf_token', window.__CSRF);
  fd.append('action', 'save_user_exercise');
  fd.append('user_id', String(uid));
  fd.append('plan_id', String(planId));
  fd.append('exercise_id', String(exId));
  fd.append('sets', payload.sets);
  fd.append('reps', payload.reps);
  fd.append('weight_lbs', payload.weight_lbs);
  fd.append('duration_seconds', payload.duration_seconds);
  fd.append('user_notes', payload.user_notes);

  // Temporary inline "Saving…" chip in Actions
  const actionsCell = tr.querySelector('[data-cell="actions"]');
  const prevHTML = actionsCell ? actionsCell.innerHTML : '';
  if (actionsCell) actionsCell.innerHTML = '<span class="muted">Saving…</span>';

  try {
    const res = await fetch('clients.php', { method:'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) throw new Error((json && json.error) || 'Save failed');

    applyUserExerciseData(tr, json.data);
    setActionsToEdit(tr);
  } catch (e) {
    alert(e.message || 'Failed to save.');
  } finally {
    if (actionsCell && !actionsCell.querySelector('[data-ex-edit]')) {
      setActionsToEdit(tr);
    }
  }
}

function closeCellEditor(td, {save=true, saveIfChanged=false}={}){
  if (!td || !td.classList.contains('editing')) return;
  const tr = td.closest('tr.mini-ex-row');
  const input = td.querySelector('input');
  const original = td.dataset.originalRaw ?? '';
  const curr = input ? input.value.trim() : '';
  const changed = (curr !== original);

  // Decide whether to save
  const shouldSave = save ? (saveIfChanged ? changed : true) : false;

  // Restore display first
  td.classList.remove('editing');
  if (shouldSave) {
    if (curr === '') {
      delete td.dataset.raw;
      td.textContent = '—';
    } else {
      td.dataset.raw = curr;
      td.innerHTML = escapeHtml(curr);
    }
  } else {
    if (original === '') {
      delete td.dataset.raw;
      td.textContent = '—';
    } else {
      td.dataset.raw = original;
      const originalDisplay = td.dataset.originalDisplay ?? original;
      td.innerHTML = escapeHtml(originalDisplay);
    }
  }
  delete td.dataset.originalRaw;
  delete td.dataset.originalDisplay;

  __ActiveCell = null;

  if (shouldSave && tr) {
    // Save entire row payload so we don't wipe other fields
    saveCell(tr);
  }
}

const videoTooltip = document.getElementById('videoTooltip');
const videoTooltipVideo = document.getElementById('videoTooltipVideo');
let videoTooltipTimer = null;

function hideVideoTooltip(immediate = false) {
  if (!videoTooltip) return;
  const execute = () => {
    videoTooltip.classList.remove('visible');
    videoTooltip.setAttribute('hidden', 'hidden');
    if (videoTooltipVideo) {
      videoTooltipVideo.pause();
      videoTooltipVideo.removeAttribute('src');
      videoTooltipVideo.load();
    }
  };
  clearTimeout(videoTooltipTimer);
  if (immediate) {
    execute();
  } else {
    videoTooltipTimer = setTimeout(execute, 120);
  }
}

function positionVideoTooltip(chip) {
  if (!videoTooltip) return;
  const rect = chip.getBoundingClientRect();
  const tooltipRect = videoTooltip.getBoundingClientRect();
  let top = rect.top + window.scrollY - tooltipRect.height - 10;
  const minTop = window.scrollY + 12;
  if (top < minTop) {
    top = rect.bottom + window.scrollY + 10;
  }
  let left = rect.left + window.scrollX + (rect.width / 2) - (tooltipRect.width / 2);
  const minLeft = window.scrollX + 12;
  const maxLeft = window.scrollX + window.innerWidth - tooltipRect.width - 12;
  left = Math.max(minLeft, Math.min(maxLeft, left));
  videoTooltip.style.top = `${top}px`;
  videoTooltip.style.left = `${left}px`;
}

function showVideoTooltip(chip) {
  if (!videoTooltip || !videoTooltipVideo || !chip) return;
  const url = chip.dataset.videoUrl;
  if (!url) return;
  clearTimeout(videoTooltipTimer);

  if (videoTooltipVideo.getAttribute('src') !== url) {
    videoTooltipVideo.src = url;
    videoTooltipVideo.load();
  }

  videoTooltip.removeAttribute('hidden');
  videoTooltip.classList.add('visible');

  requestAnimationFrame(() => {
    positionVideoTooltip(chip);
    videoTooltipVideo.play().catch(()=>{});
  });
}

const videoModal = document.getElementById('videoModal');
const videoModalVideo = document.getElementById('videoModalVideo');
const videoModalTitle = document.getElementById('videoModalTitle');
const videoModalDescription = document.getElementById('videoModalDescription');

function openVideoModal(chip) {
  if (!videoModal || !videoModalVideo || !chip) return;
  const url = chip.dataset.videoUrl;
  if (!url) return;

  const name = chip.dataset.exName || 'Exercise Video';
  const link = chip.dataset.exLink || '#';
  const desc = (chip.dataset.exDesc || '').trim();

  if (videoModalTitle) {
    videoModalTitle.textContent = name;
    videoModalTitle.href = link;
  }
  if (videoModalDescription) {
    videoModalDescription.textContent = desc !== '' ? desc : 'No description provided.';
    videoModalDescription.classList.toggle('muted', desc === '');
  }

  videoModalVideo.src = url;
  videoModalVideo.load();
  videoModalVideo.play().catch(()=>{});

  videoModal.removeAttribute('hidden');
  videoModal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeVideoModal() {
  if (!videoModal) return;
  videoModal.classList.remove('open');
  videoModal.setAttribute('hidden', 'hidden');
  if (videoModalVideo) {
    videoModalVideo.pause();
    videoModalVideo.removeAttribute('src');
    videoModalVideo.load();
  }
  document.body.style.overflow = '';
}

document.querySelectorAll('[data-video-modal-close]').forEach(el => {
  el.addEventListener('click', (ev) => {
    ev.preventDefault();
    closeVideoModal();
  });
});

document.addEventListener('keydown', (ev)=>{
  if (ev.key === 'Escape' && videoModal && videoModal.classList.contains('open')) {
    closeVideoModal();
  }
});

document.addEventListener('mouseover', (ev)=>{
  const chip = ev.target.closest('.video-chip');
  if (!chip) return;
  if (chip.contains(ev.relatedTarget)) return;
  showVideoTooltip(chip);
}, true);

document.addEventListener('mouseout', (ev)=>{
  const chip = ev.target.closest('.video-chip');
  if (!chip) return;
  if (chip.contains(ev.relatedTarget)) return;
  hideVideoTooltip();
}, true);

document.addEventListener('click', (ev)=>{
  const chip = ev.target.closest('.video-chip');
  if (!chip) return;
  ev.preventDefault();
  ev.stopPropagation();
  hideVideoTooltip(true);
  openVideoModal(chip);
}, true);

window.addEventListener('resize', ()=>hideVideoTooltip(true));
document.addEventListener('scroll', ()=>hideVideoTooltip(true), true);

// Activate cell editor on click
document.addEventListener('click', function(e){
  const td = e.target.closest('td[data-cell]');
  if (!td) return;
  const col = td.getAttribute('data-cell');
  if (!['sets','reps','weight','duration','notes'].includes(col)) return;

  // Prevent row navigation
  e.preventDefault();
  e.stopPropagation();

  openCellEditor(td);
});

// If user clicks anywhere outside current input, it will blur and trigger saveIfChanged via the blur handler

</script>
</body>
</html>
