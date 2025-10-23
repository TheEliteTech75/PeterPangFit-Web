<?php
// clients.php — Active/Inactive tabs, inline edit, invite/resend, deactivate/reactivate, row-expand plans + unassign.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/ppf_lockout.php'; // unlock action

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_trainer_admin($role){ return in_array($role ?? 'guest', ['trainer','admin'], true); }
if (!is_trainer_admin($USER_ROLE ?? null)) { http_response_code(403); echo 'Forbidden'; exit; }

if (!function_exists('ppf_clients_log_encode')) {
  function ppf_clients_log_encode(array $details): ?string {
    if (!$details) return json_encode((object)[], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ($json === false) ? null : $json;
  }
}

if (!function_exists('ppf_log_user_admin_action')) {
  function ppf_log_user_admin_action(mysqli $conn, string $action, ?int $targetUserId, array $details = []): void {
    if (!function_exists('ppf_log')) return;
    $json = ppf_clients_log_encode($details ?? []);
    @ppf_log(
      $conn,
      null,
      null,
      null,
      $action,
      'user',
      ($targetUserId && $targetUserId > 0) ? (string)$targetUserId : null,
      $json
    );
  }
}

if (!function_exists('ppf_clients_normalize_log_value')) {
  function ppf_clients_normalize_log_value($value) {
    if ($value === null) return null;
    $str = trim((string)$value);
    return $str === '' ? null : $str;
  }
}

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
  if (!ppf_column_exists_uncached($conn, 'user_plan_exercises', 'set_details_json')) {
    @$conn->query("ALTER TABLE user_plan_exercises ADD COLUMN set_details_json LONGTEXT NULL");
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

function ppf_clients_decode_set_details(?string $json): array {
  if ($json === null || trim($json) === '') return [];
  $decoded = json_decode($json, true);
  if (!is_array($decoded)) return [];
  $out = [];
  foreach ($decoded as $entry) {
    if (!is_array($entry)) continue;
    $reps = isset($entry['reps']) ? trim((string)$entry['reps']) : '';
    $weight = $entry['weight_lbs'] ?? ($entry['weight'] ?? null);
    $duration = $entry['duration_seconds'] ?? ($entry['duration'] ?? null);

    $repsVal = ($reps === '') ? null : $reps;
    $weightVal = (is_numeric($weight)) ? (float)$weight : null;
    $durationVal = (is_numeric($duration)) ? (int)$duration : null;

    $out[] = [
      'set_number' => count($out) + 1,
      'reps' => $repsVal,
      'weight_lbs' => $weightVal,
      'duration_seconds' => $durationVal,
    ];
  }
  return $out;
}

function ppf_clients_build_legacy_set_details($sets, $reps, $weight, $duration): array {
  $count = null;
  if ($sets !== null && $sets !== '') {
    $count = (int)$sets;
  }
  if ($count === null || $count <= 0) {
    if (($reps !== null && $reps !== '') || $weight !== null || $duration !== null) {
      $count = 1;
    } else {
      $count = 0;
    }
  }

  $repsVal = ($reps !== null && $reps !== '') ? (string)$reps : null;
  $weightVal = null;
  if ($weight !== null && $weight !== '') {
    $weightVal = is_numeric($weight) ? (float)$weight : null;
  }
  $durationVal = null;
  if ($duration !== null && $duration !== '') {
    $durationVal = is_numeric($duration) ? (int)$duration : null;
  }

  $rows = [];
  for ($i = 0; $i < $count; $i++) {
    $rows[] = [
      'set_number' => $i + 1,
      'reps' => $repsVal,
      'weight_lbs' => $weightVal,
      'duration_seconds' => $durationVal,
    ];
  }
  return $rows;
}

function ppf_clients_get_set_details($json, $sets, $reps, $weight, $duration): array {
  $fromJson = ppf_clients_decode_set_details($json);
  if ($fromJson) return $fromJson;
  return ppf_clients_build_legacy_set_details($sets, $reps, $weight, $duration);
}

function ppf_clients_enrich_set_details(array $rows): array {
  $out = [];
  foreach ($rows as $idx => $row) {
    $weightVal = array_key_exists('weight_lbs', $row) ? $row['weight_lbs'] : null;
    $durationVal = array_key_exists('duration_seconds', $row) ? $row['duration_seconds'] : null;
    $out[] = [
      'set_number' => ($row['set_number'] ?? ($idx + 1)),
      'reps' => $row['reps'] ?? null,
      'weight_value' => ($weightVal !== null) ? (float)$weightVal : null,
      'weight_display' => ($weightVal !== null) ? ppf_format_weight_lbs((float)$weightVal) : null,
      'duration_seconds' => ($durationVal !== null) ? (int)$durationVal : null,
      'duration_display' => ($durationVal !== null) ? ppf_format_duration_display((int)$durationVal) : null,
    ];
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
        try {
          if ($uid <= 0) throw new Exception('Invalid client.');

          $existingStmt = $conn->prepare("SELECT email, phone, birthdate, gender, first_name, middle_name, last_name, height_ft, height_in, weight_lbs FROM users WHERE id = ? AND (role='client' OR is_client=1) LIMIT 1");
          if (!$existingStmt) throw new Exception('Failed to load client.');
          $existingStmt->bind_param("i", $uid);
          $existingStmt->execute();
          $existingRes = $existingStmt->get_result();
          $existingRow = $existingRes ? $existingRes->fetch_assoc() : null;
          $existingStmt->close();
          if (!$existingRow) throw new Exception('Client not found.');

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

          $normalize = 'ppf_clients_normalize_log_value';
          $beforeSnapshot = [
            'email' => $normalize($existingRow['email'] ?? null),
            'phone' => $normalize($existingRow['phone'] ?? null),
            'birthdate' => $normalize($existingRow['birthdate'] ?? null),
            'gender' => $normalize($existingRow['gender'] ?? null),
            'first_name' => $normalize($existingRow['first_name'] ?? null),
            'middle_name' => $normalize($existingRow['middle_name'] ?? null),
            'last_name' => $normalize($existingRow['last_name'] ?? null),
            'height_ft' => $normalize($existingRow['height_ft'] ?? null),
            'height_in' => $normalize($existingRow['height_in'] ?? null),
            'weight_lbs' => $normalize($existingRow['weight_lbs'] ?? null),
          ];
          $afterSnapshot = [
            'email' => $normalize($email),
            'phone' => $normalize($phone),
            'birthdate' => $normalize($bdate),
            'gender' => $normalize($gend),
            'first_name' => $normalize($first_name),
            'middle_name' => $normalize($middle_name),
            'last_name' => $normalize($last_name),
            'height_ft' => $normalize($hf),
            'height_in' => $normalize($hi),
            'weight_lbs' => $normalize($wl),
          ];
          $changes = [];
          foreach ($afterSnapshot as $field => $newVal) {
            $oldVal = $beforeSnapshot[$field] ?? null;
            if ($oldVal !== $newVal) {
              $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
          }

          ppf_log_user_admin_action($conn, 'user_profile_updated_admin', $uid, [
            'changed_fields' => array_keys($changes),
            'changes' => $changes,
            'client_id' => $uid,
            'values_before' => $beforeSnapshot,
            'values_after' => $afterSnapshot,
          ]);

          $flash = 'Client updated.'; $flash_type = 'ok';
        } catch (Throwable $e) {
          ppf_log_user_admin_action($conn, 'user_profile_update_failed', $uid, [
            'client_id' => $uid,
            'error' => $e->getMessage(),
            'input_email' => isset($email) ? $email : null,
          ]);
          throw $e;
        }
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
          $plan_id     = (int)($_POST['plan_id'] ?? 0);
          $exercise_id = (int)($_POST['exercise_id'] ?? 0);
          $up_id = null;
          $existingRow = null;
          try {
            if (!in_array(($USER_ROLE ?? ''), ['admin','trainer'], true)) {
              throw new Exception('You do not have permission to edit exercise settings.');
            }

            if ($uid <= 0 || $plan_id <= 0 || $exercise_id <= 0) {
              throw new Exception('Invalid input.');
            }

            $setsInput      = trim($_POST['sets'] ?? '');
            $repsInput      = trim($_POST['reps'] ?? '');
            $weightInput    = trim($_POST['weight_lbs'] ?? '');
            $durationInput  = trim($_POST['duration_seconds'] ?? '');
            $setPayloadRaw  = $_POST['set_payload'] ?? '';
            $user_notes     = isset($_POST['user_notes']) ? trim($_POST['user_notes']) : '';

            $notesVal = ($user_notes === '' ? null : $user_notes);

            $wtValLegacy = null;
            if ($weightInput !== '') {
              $wtValLegacy = ppf_parse_weight_to_float($weightInput);
              if ($wtValLegacy === null) {
                throw new Exception('Weight must be numeric (digits with optional decimal).');
              }
            }

            $durValLegacy = null;
            if ($durationInput !== '') {
              $durValLegacy = ppf_parse_duration_to_seconds($durationInput);
              if ($durValLegacy === null) {
                throw new Exception('Duration must be seconds or mm:ss (for example 90 or 1:30).');
              }
            }

            $parsedSetRows = [];
            if (is_string($setPayloadRaw) && trim($setPayloadRaw) !== '') {
              $decoded = json_decode($setPayloadRaw, true);
              if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid set details payload.');
              }
              if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                  if (!is_array($entry)) continue;
                  $repStr = isset($entry['reps']) ? trim((string)$entry['reps']) : '';
                  $weightStr = isset($entry['weight']) ? trim((string)$entry['weight']) : '';
                  $durationStr = isset($entry['duration']) ? trim((string)$entry['duration']) : '';

                  $weightVal = null;
                  if ($weightStr !== '') {
                    $weightVal = ppf_parse_weight_to_float($weightStr);
                    if ($weightVal === null) {
                      throw new Exception('Each set weight must be numeric (digits with optional decimal).');
                    }
                  }

                  $durationVal = null;
                  if ($durationStr !== '') {
                    $durationVal = ppf_parse_duration_to_seconds($durationStr);
                    if ($durationVal === null) {
                      throw new Exception('Each set duration must be seconds or mm:ss (for example 90 or 1:30).');
                    }
                  }

                  if ($repStr === '' && $weightVal === null && $durationVal === null) {
                    continue;
                  }

                  $parsedSetRows[] = [
                    'set_number' => count($parsedSetRows) + 1,
                    'reps' => $repStr === '' ? null : $repStr,
                    'weight_lbs' => $weightVal,
                    'duration_seconds' => $durationVal,
                  ];
                }
              }
            }

            if (!$parsedSetRows) {
              $setsLegacy = ($setsInput === '' ? null : $setsInput);
              $repsLegacy = ($repsInput === '' ? null : $repsInput);
              $parsedSetRows = ppf_clients_build_legacy_set_details($setsLegacy, $repsLegacy, $wtValLegacy, $durValLegacy);
            }

            $normalizedSetRows = [];
            foreach ($parsedSetRows as $row) {
              if (!is_array($row)) continue;
              $repVal = isset($row['reps']) ? trim((string)$row['reps']) : '';
              $weightVal = $row['weight_lbs'] ?? null;
              $durationVal = $row['duration_seconds'] ?? null;

              $repOut = ($repVal === '') ? null : $repVal;
              $weightOut = ($weightVal !== null && $weightVal !== '') ? (float)$weightVal : null;
              $durationOut = ($durationVal !== null && $durationVal !== '') ? (int)$durationVal : null;

              if ($repOut === null && $weightOut === null && $durationOut === null) {
                continue;
              }

              $normalizedSetRows[] = [
                'set_number' => count($normalizedSetRows) + 1,
                'reps' => $repOut,
                'weight_lbs' => $weightOut,
                'duration_seconds' => $durationOut,
              ];
            }

            $setDetailsJson = json_encode(array_map(function($row){
              return [
                'set_number' => $row['set_number'],
                'reps' => $row['reps'],
                'weight_lbs' => $row['weight_lbs'],
                'duration_seconds' => $row['duration_seconds'],
              ];
            }, $normalizedSetRows));
            if ($setDetailsJson === false) {
              throw new Exception('Failed to encode set details.');
            }

            $setsCount = count($normalizedSetRows);
            $setsVal = $setsCount > 0 ? (string)$setsCount : null;
            $firstSet = $normalizedSetRows[0] ?? null;
            $repsVal = $firstSet['reps'] ?? null;
            $wtVal = $firstSet['weight_lbs'] ?? null;
            $durVal = $firstSet['duration_seconds'] ?? null;

            // Find the user_plans.id for (user, plan)
            $q1 = $conn->prepare("SELECT id FROM user_plans WHERE user_id=? AND plan_id=? LIMIT 1");
            $q1->bind_param("ii", $uid, $plan_id);
            $q1->execute();
            $res1 = $q1->get_result();
            if ($row = $res1->fetch_assoc()) $up_id = (int)$row['id'];
            $q1->close();

            if (!$up_id) throw new Exception('Plan is not assigned to this user.');

            // Does a row exist for this exercise?
            $upe_id = null;
            $q2 = $conn->prepare("SELECT id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json FROM user_plan_exercises WHERE user_plan_id=? AND exercise_id=? LIMIT 1");
            $q2->bind_param("ii", $up_id, $exercise_id);
            $q2->execute();
            $res2 = $q2->get_result();
            if ($row = $res2->fetch_assoc()) {
              $upe_id = (int)$row['id'];
              $existingRow = $row;
            }
            $q2->close();

            $updaterId = isset($USER_ID) ? (int)$USER_ID : null;

            if ($upe_id) {
              if ($HAS_UPE_UPDATED_AT && $HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, set_details_json=?, updated_at=NOW(), updated_by=?
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdissii", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $updaterId, $upe_id);
                } else {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, set_details_json=?, updated_at=NOW(), updated_by=NULL
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdissi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $upe_id);
                }
              } elseif ($HAS_UPE_UPDATED_AT) {
                $q3 = $conn->prepare("
                  UPDATE user_plan_exercises
                  SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, set_details_json=?, updated_at=NOW()
                  WHERE id=?
                ");
                $q3->bind_param("ssdissi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $upe_id);
              } elseif ($HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, set_details_json=?, updated_by=?
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdissii", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $updaterId, $upe_id);
                } else {
                  $q3 = $conn->prepare("
                    UPDATE user_plan_exercises
                    SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, set_details_json=?, updated_by=NULL
                    WHERE id=?
                  ");
                  $q3->bind_param("ssdissi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $upe_id);
                }
              } else {
                $q3 = $conn->prepare("
                  UPDATE user_plan_exercises
                  SET sets=?, reps=?, weight_lbs=?, duration_seconds=?, user_notes=?, set_details_json=?
                  WHERE id=?
                ");
                $q3->bind_param("ssdissi", $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $upe_id);
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
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                  ");
                  $q4->bind_param("iissdissi", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $updaterId);
                } else {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)
                  ");
                  $q4->bind_param("iissdiss", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson);
                }
              } elseif ($HAS_UPE_UPDATED_AT) {
                $q4 = $conn->prepare("
                  INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $q4->bind_param("iissdiss", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson);
              } elseif ($HAS_UPE_UPDATED_BY) {
                if ($updaterId) {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ");
                  $q4->bind_param("iissdissi", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson, $updaterId);
                } else {
                  $q4 = $conn->prepare("
                    INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
                  ");
                  $q4->bind_param("iissdiss", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson);
                }
              } else {
                $q4 = $conn->prepare("
                  INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, weight_lbs, duration_seconds, user_notes, set_details_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $q4->bind_param("iissdiss", $up_id, $exercise_id, $setsVal, $repsVal, $wtVal, $durVal, $notesVal, $setDetailsJson);
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
            $setDetailsForOutput = ppf_clients_enrich_set_details($normalizedSetRows);

            $normalize = 'ppf_clients_normalize_log_value';
            $beforeSnapshot = $existingRow ? [
              'sets' => $normalize($existingRow['sets'] ?? null),
              'reps' => $normalize($existingRow['reps'] ?? null),
              'weight_lbs' => $normalize($existingRow['weight_lbs'] ?? null),
              'duration_seconds' => $normalize($existingRow['duration_seconds'] ?? null),
              'user_notes' => $normalize($existingRow['user_notes'] ?? null),
              'set_details_json' => $normalize($existingRow['set_details_json'] ?? null),
            ] : [];
            $afterSnapshot = [
              'sets' => $normalize($setsVal),
              'reps' => $normalize($repsVal),
              'weight_lbs' => $normalize($wtVal),
              'duration_seconds' => $normalize($durVal),
              'user_notes' => $normalize($notesVal),
              'set_details_json' => $normalize($setDetailsJson),
            ];

            $changes = [];
            foreach ($afterSnapshot as $field => $newVal) {
              $oldVal = $beforeSnapshot[$field] ?? null;
              if ($oldVal !== $newVal) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
              }
            }

            ppf_log_user_admin_action($conn, 'user_exercise_settings_updated', $uid, [
              'client_id' => $uid,
              'plan_id' => $plan_id,
              'user_plan_id' => $up_id,
              'exercise_id' => $exercise_id,
              'operation' => $existingRow ? 'update' : 'insert',
              'changed_fields' => array_keys($changes),
              'changes' => $changes,
              'values_before' => $existingRow ? $beforeSnapshot : null,
              'values_after' => $afterSnapshot,
              'meta_updated_at' => $metaUpdatedAt,
              'meta_updated_by' => $metaUpdatedById,
            ]);

            header('Content-Type: application/json');
            echo json_encode([
              'ok' => true,
              'data' => [
                'sets' => $setsVal,
                'sets_count' => $setsCount,
                'reps' => $repsVal,
                'weight_value' => $wtVal,
                'weight_display' => $weightDisplay,
                'duration_seconds' => $durVal,
                'duration_display' => $durationDisplay,
                'notes' => $notesVal,
                'updated_at' => $editedAtDisp,
                'updated_by_name' => $editedByName,
                'set_details' => $setDetailsForOutput,
                'set_details_json' => $setDetailsJson
              ]
            ]);
            exit;
          } catch (Throwable $e) {
            ppf_log_user_admin_action($conn, 'user_exercise_settings_failed', $uid, [
              'client_id' => $uid,
              'plan_id' => $plan_id,
              'user_plan_id' => $up_id,
              'exercise_id' => $exercise_id,
              'error' => $e->getMessage(),
              'input_sets' => isset($setsVal) ? $setsVal : null,
              'input_reps' => isset($repsVal) ? $repsVal : null,
              'input_weight' => isset($weight_lbs) ? $weight_lbs : null,
              'input_duration' => isset($duration_seconds) ? $duration_seconds : null,
              'input_set_payload_raw' => isset($setPayloadRaw) ? $setPayloadRaw : null,
              'normalized_set_details_json' => isset($setDetailsJson) ? $setDetailsJson : null,
              'normalized_set_count' => isset($setsCount) ? $setsCount : null,
            ]);
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
    upe.set_details_json AS set_details_json,
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

    $setRows = ppf_clients_get_set_details(
      $r['set_details_json'] ?? null,
      $r['sets'] ?? null,
      $r['reps'] ?? null,
      $r['weight_value'] ?? null,
      $r['duration_seconds'] ?? null
    );
    $enrichedSets = ppf_clients_enrich_set_details($setRows);
    $setsCount = count($enrichedSets);
    $firstSet = $enrichedSets[0] ?? null;

    $weightValue = $firstSet['weight_value'] ?? null;
    $weightDisplay = $firstSet['weight_display'] ?? null;
    $durationSeconds = $firstSet['duration_seconds'] ?? null;
    $durationDisplay = $firstSet['duration_display'] ?? null;
    $repsDisplay = $firstSet['reps'] ?? null;

    $userExByUserPlan[$u][$p][$ex] = [
      'sets'             => $setsCount > 0 ? (string)$setsCount : null,
      'sets_count'       => $setsCount,
      'reps'             => $repsDisplay,
      'weight_value'     => $weightValue,
      'weight_display'   => $weightDisplay,
      'duration_seconds' => $durationSeconds,
      'duration_display' => $durationDisplay,
      'set_details'      => $enrichedSets,
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
            <td colspan="<?php echo $colspan; ?>" style="background:rgba(8,13,23,0.95)">
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
  
  html,body{margin:0;padding:0;background: var(--page-canvas);
    color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
  a{color:var(--text);text-decoration:none}

  .wrap{width:100%;max-width:100%;margin:24px auto;padding:0 var(--page-pad);box-sizing:border-box}
  .panel{background:var(--panel);border:1px solid var(--line);border-radius:14px}
  .row{display:flex;gap:16px;align-items:center}
  .btn{ background:rgba(30,41,59,0.65); border:1px solid var(--line); padding:8px 12px; border-radius:10px; color: var(--text); }
  .btn.small{padding:6px 10px;font-size:12px}
  .btn.brand{background:var(--brand);border-color:var(--brand);color:white}
  .tabs{display:flex;gap:8px;margin-bottom:14px}
  .tab{padding:8px 12px;border-radius:9999px;border:1px solid var(--line);background:rgba(15,23,42,0.68);color:#cbd5f5}
  .tab.active{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35);color:#fff}

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
  thead th{position:sticky;top:0;background:rgba(8,13,23,0.95);z-index:1}
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
  .input{background:rgba(8,13,23,0.88);border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px;width:100%}
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

  .video-tooltip { position:absolute; z-index:4000; background:rgba(8,13,23,0.88); border:1px solid rgba(99,102,241,0.4);
    border-radius:10px; padding:10px; width:240px; box-shadow:0 20px 50px rgba(0,0,0,0.55); display:none; pointer-events:none; }
  .video-tooltip.visible { display:block; }
  .video-tooltip video { width:100%; border-radius:8px; display:block; background:#000; }

  .video-modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:4500; }
  .video-modal.open { display:flex; }
  .video-modal__backdrop { position:absolute; inset:0; background:rgba(0,0,0,0.72); }
  .video-modal__content { position:relative; background:rgba(8,13,23,0.88); border:1px solid var(--line); border-radius:16px; padding:22px;
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
            background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="ppTitle" style="margin:0 0 10px 0;font-size:16px">Assign Plan to User</h3>
  <div class="fine" id="ppUserText" style="margin-bottom:8px;color:#cbd5f5"></div>
  <div>
    <label class="fine" for="ppPlanSel" style="display:block;margin-bottom:6px;color:#cbd5f5">Choose a plan</label>
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
            background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;display:none;z-index:3001">
  <h3 id="aeTitle" style="margin:0 0 10px 0;font-size:16px">Add Exercises to Plan</h3>
  <div class="fine" id="aePlanText" style="margin-bottom:8px;color:#cbd5f5"></div>
  <div class="box" style="border:1px solid var(--line);border-radius:10px;padding:10px;max-height:360px;overflow:auto">
    <div id="aeList"></div>
  </div>
  <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
    <button class="btn" type="button" id="aeCancel">Cancel</button>
    <button class="btn brand" type="button" id="aeAdd">Add Selected</button>
  </div>
</div>

<!-- Edit per-user exercise modal -->
<div class="backdrop" id="bdEditExercise" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:3000"></div>
<div class="modal" id="mdEditExercise" role="dialog" aria-modal="true" aria-labelledby="eeTitle"
     style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(560px,96vw);
            background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:18px;display:none;z-index:3001">
  <h3 id="eeTitle" style="margin:0 0 8px 0;font-size:16px">Edit Exercise</h3>
  <div class="fine" id="eeContext" style="margin-bottom:12px;color:#cbd5f5"></div>
  <div class="box" style="border:1px solid var(--line);border-radius:10px;padding:12px;max-height:360px;overflow:auto">
    <div id="eeSetList"></div>
    <button class="btn small" type="button" id="eeAddSet" style="margin-top:10px">Add Set</button>
  </div>
  <div style="margin-top:12px">
    <label class="fine" for="eeNotes" style="display:block;margin-bottom:6px;color:#cbd5f5">User Notes</label>
    <textarea class="input" id="eeNotes" rows="3" placeholder="Any user-specific instructions…"></textarea>
  </div>
  <div id="eeError" class="muted" style="color:#ff6b6b;margin-top:8px;display:none"></div>
  <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap">
    <button class="btn" type="button" id="eeCancel">Cancel</button>
    <button class="btn brand" type="button" id="eeSave">Save Changes</button>
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
            per.sets_count != null ? String(per.sets_count) : '',
            per.reps || '',
            per.weight_display || per.weight_value || '',
            per.duration_display || per.duration_seconds || '',
            per.notes || '',
            per.updated_by_name || '',
            per.updated_at || ''
          );
          if (Array.isArray(per.set_details)) {
            per.set_details.forEach(detail => {
              if (!detail || typeof detail !== 'object') return;
              const reps = detail.reps || '';
              const w = detail.weight_display || (detail.weight_value != null ? String(detail.weight_value) : '');
              const d = detail.duration_display || (detail.duration_seconds != null ? String(detail.duration_seconds) : '');
              parts.push(reps, w, d);
            });
          }
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
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px;width:44px;text-align:center">Select</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Plan ID</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Name</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Assigned</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Created</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Created By</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Updated</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Updated By</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Exercises</th>
            <th style="background:rgba(8,13,23,0.95);padding:8px 10px">Actions</th>
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
                      style="border-color:#ef4444;color:#ef4444;background:rgba(30,41,59,0.65)">Unassign</button>
            </form>
          </td>
        </tr>
      `;

      const exs = (window.__EX_BY_PLAN && window.__EX_BY_PLAN[p.id]) || [];
      let exHtml = `
        <div style="padding:8px 4px">
          <div class="section-title">Exercises in this Plan</div>
          <table style="width:100%;border-collapse:collapse;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:rgba(8,13,23,0.95)">
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
          const setDetails = Array.isArray(per?.set_details) ? per.set_details : [];
          let setsCount = Number.isFinite(per?.sets_count) ? per.sets_count : null;
          if (setsCount === null && per?.sets != null && per.sets !== '') {
            const parsed = Number(per.sets);
            if (!Number.isNaN(parsed)) setsCount = parsed;
          }
          if (setsCount === null) setsCount = setDetails.length;
          const fallback = {
            setsCount,
            reps: per?.reps ?? null,
            weightValue: toNumberOrNull(per?.weight_value ?? (per?.weight ?? null)),
            weightDisplay: per?.weight_display ?? (typeof per?.weight === 'string' ? per.weight : null),
            durationSeconds: toNumberOrNull(per?.duration_seconds ?? (per?.duration ?? null)),
            durationDisplay: per?.duration_display ?? (typeof per?.duration === 'string' ? per.duration : null)
          };
          const display = computeExerciseSetDisplay(setDetails, fallback);
          const setsSummary = display.setsHtml;
          const repsHtml = display.repsHtml;
          const weightHtml = display.weightHtml;
          const durationHtml = display.durationHtml;
          const setsAttr = display.setsRaw != null ? ` data-raw="${escapeHtml(String(display.setsRaw))}"` : '';
          const repsAttr = display.repsValue != null ? ` data-raw="${escapeHtml(String(display.repsValue))}"` : '';
          const weightAttr = display.weightRaw != null ? ` data-raw="${escapeHtml(String(display.weightRaw))}"` : '';
          const durAttr = display.durationRaw != null ? ` data-raw="${escapeHtml(String(display.durationRaw))}"` : '';

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
          exHtml += `
            <tr class="mini-ex-row" data-ex-id="${ex.ex_id}" data-user-id="${uid}" data-plan-id="${p.id}">
              <td style="padding:8px 10px">${ex.ex_id}</td>
              <td style="padding:8px 10px"><a href="${exLink}" class="mini-ex-link"><strong>${escapeHtml(ex.name||'')}</strong></a></td>
              <td class="muted" style="padding:8px 10px" data-cell="notes">${escapeHtml(showNotes)}</td>
              <td style="padding:8px 10px" data-cell="categories">${catDisplay}</td>
              <td style="padding:8px 10px">${videoCell}</td>
              <td style="padding:8px 10px" data-cell="sets"${setsAttr}>${setsSummary}</td>
              <td style="padding:8px 10px" data-cell="reps"${repsAttr}>${repsHtml}</td>
              <td style="padding:8px 10px" data-cell="weight"${weightAttr}>${weightHtml}</td>
              <td style="padding:8px 10px" data-cell="duration"${durAttr}>${durationHtml}</td>
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

const ExerciseEditor = {
  row: null,
  userId: null,
  planId: null,
  exerciseId: null
};

const eeModal = document.getElementById('mdEditExercise');
const eeBackdrop = document.getElementById('bdEditExercise');
const eeSetList = document.getElementById('eeSetList');
const eeAddSetBtn = document.getElementById('eeAddSet');
const eeNotes = document.getElementById('eeNotes');
const eeTitle = document.getElementById('eeTitle');
const eeContext = document.getElementById('eeContext');
const eeSaveBtn = document.getElementById('eeSave');
const eeCancelBtn = document.getElementById('eeCancel');
const eeErrorBox = document.getElementById('eeError');

function eeClearError(){
  if (!eeErrorBox) return;
  eeErrorBox.textContent = '';
  eeErrorBox.style.display = 'none';
}

function eeShowError(message){
  if (!eeErrorBox) return;
  eeErrorBox.textContent = message || '';
  eeErrorBox.style.display = message ? 'block' : 'none';
}

function eeUpdateSetNumbers(){
  if (!eeSetList) return;
  const rows = Array.from(eeSetList.querySelectorAll('.ee-set-row'));
  rows.forEach((row, idx) => {
    const num = row.querySelector('[data-set-index]');
    if (num) num.textContent = String(idx + 1);
  });
}

function eeBuildSetRow(detail = {}){
  const row = document.createElement('div');
  row.className = 'ee-set-row';
  row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap';

  const repsVal = detail.reps != null ? String(detail.reps) : '';
  const weightVal = detail.weight_value != null ? String(detail.weight_value) : '';
  const durationVal = detail.duration_seconds != null ? formatDurationForInput(detail.duration_seconds) : '';

  row.innerHTML = `
    <span class="fine" style="min-width:48px;margin-top:6px">Set <span data-set-index></span></span>
    <input class="input ee-set-input" data-field="reps" type="text" placeholder="Reps" value="${escapeHtml(repsVal)}" style="width:90px">
    <input class="input ee-set-input" data-field="weight" type="number" step="0.1" min="0" placeholder="Weight (lbs)" value="${escapeHtml(weightVal)}" style="width:130px">
    <input class="input ee-set-input" data-field="duration" type="text" placeholder="Duration (e.g., 1:30)" value="${escapeHtml(durationVal)}" style="width:140px">
    <button class="btn small" type="button" data-remove-set style="margin-top:4px">Remove</button>
  `;

  const removeBtn = row.querySelector('[data-remove-set]');
  if (removeBtn) {
    removeBtn.addEventListener('click', () => {
      row.remove();
      eeUpdateSetNumbers();
    });
  }

  return row;
}

function eeRenderSets(details){
  if (!eeSetList) return;
  eeSetList.innerHTML = '';
  const list = ensureArray(details);
  if (!list.length) {
    eeSetList.appendChild(eeBuildSetRow({}));
  } else {
    list.forEach(detail => {
      eeSetList.appendChild(eeBuildSetRow(detail));
    });
  }
  eeUpdateSetNumbers();
}

function eeCollectSets(){
  if (!eeSetList) return [];
  const rows = Array.from(eeSetList.querySelectorAll('.ee-set-row'));
  return rows.map(row => {
    const reps = row.querySelector('[data-field="reps"]').value.trim();
    const weight = row.querySelector('[data-field="weight"]').value.trim();
    const duration = row.querySelector('[data-field="duration"]').value.trim();
    return { reps, weight, duration };
  }).filter(entry => entry.reps !== '' || entry.weight !== '' || entry.duration !== '');
}

function eeOpen(tr){
  if (!tr) return;
  const uid = parseInt(tr.dataset.userId, 10);
  const planId = parseInt(tr.dataset.planId, 10);
  const exId = parseInt(tr.dataset.exId, 10);
  if (!uid || !planId || !exId) return;

  ExerciseEditor.row = tr;
  ExerciseEditor.userId = uid;
  ExerciseEditor.planId = planId;
  ExerciseEditor.exerciseId = exId;

  const stored = (window.__USER_EX && window.__USER_EX[uid] && window.__USER_EX[uid][planId] && window.__USER_EX[uid][planId][exId]) || {};

  const nameCell = tr.querySelector('td:nth-child(2)');
  const nameLink = nameCell ? nameCell.querySelector('a') : null;
  const titleName = nameLink ? nameLink.textContent.trim() : `Exercise ${exId}`;
  if (eeTitle) eeTitle.textContent = `Edit ${titleName}`;
  if (eeContext) eeContext.textContent = `User ID: ${uid} · Plan ID: ${planId} · Exercise ID: ${exId}`;

  eeRenderSets(stored.set_details || []);
  if (eeNotes) eeNotes.value = stored.notes != null ? String(stored.notes) : '';
  eeClearError();

  if (eeModal) eeModal.style.display = 'block';
  if (eeBackdrop) eeBackdrop.style.display = 'block';
  document.body.style.overflow = 'hidden';
}

function eeClose(){
  ExerciseEditor.row = null;
  ExerciseEditor.userId = null;
  ExerciseEditor.planId = null;
  ExerciseEditor.exerciseId = null;
  if (eeSetList) eeSetList.innerHTML = '';
  if (eeNotes) eeNotes.value = '';
  eeClearError();
  if (eeModal) eeModal.style.display = 'none';
  if (eeBackdrop) eeBackdrop.style.display = 'none';
  document.body.style.overflow = '';
  if (eeSaveBtn) {
    eeSaveBtn.disabled = false;
    eeSaveBtn.textContent = 'Save Changes';
  }
}

function eeComputeAggregates(payload){
  const count = payload.length;
  const first = payload[0] || {};
  return {
    sets: count ? String(count) : '',
    reps: first.reps || '',
    weight: first.weight || '',
    duration: first.duration || ''
  };
}

if (eeAddSetBtn) {
  eeAddSetBtn.addEventListener('click', () => {
    if (!eeSetList) return;
    eeSetList.appendChild(eeBuildSetRow({}));
    eeUpdateSetNumbers();
  });
}

if (eeCancelBtn) eeCancelBtn.addEventListener('click', eeClose);
if (eeBackdrop) eeBackdrop.addEventListener('click', eeClose);

if (eeSaveBtn) {
  eeSaveBtn.addEventListener('click', async () => {
    if (!ExerciseEditor.row) return;
    const payload = eeCollectSets();
    const notes = eeNotes ? eeNotes.value.trim() : '';

    const agg = eeComputeAggregates(payload);
    const fd = new FormData();
    fd.append('csrf_token', window.__CSRF);
    fd.append('action', 'save_user_exercise');
    fd.append('user_id', String(ExerciseEditor.userId));
    fd.append('plan_id', String(ExerciseEditor.planId));
    fd.append('exercise_id', String(ExerciseEditor.exerciseId));
    fd.append('sets', agg.sets);
    fd.append('reps', agg.reps);
    fd.append('weight_lbs', agg.weight);
    fd.append('duration_seconds', agg.duration);
    fd.append('user_notes', notes);
    fd.append('set_payload', JSON.stringify(payload));

    eeClearError();
    eeSaveBtn.disabled = true;
    const prevText = eeSaveBtn.textContent;
    eeSaveBtn.textContent = 'Saving…';

    try {
      const res = await fetch('clients.php', { method: 'POST', body: fd });
      const json = await res.json();
      if (!json || !json.ok) throw new Error((json && json.error) || 'Save failed');
      applyUserExerciseData(ExerciseEditor.row, json.data);
      setActionsToEdit(ExerciseEditor.row);
      eeClose();
    } catch (err) {
      eeShowError(err.message || 'Failed to save changes.');
      eeSaveBtn.disabled = false;
      eeSaveBtn.textContent = prevText;
    }
  });
}

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape' && eeModal && eeModal.style.display === 'block') {
    eeClose();
  }
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-ex-edit]');
  if (!btn) return;
  e.preventDefault();
  const tr = btn.closest('tr.mini-ex-row');
  if (!tr) return;
  eeOpen(tr);
});

function normalizeEmpty(value){
  return (value === null || value === undefined || value === '') ? null : value;
}

function toNumberOrNull(value){
  if (value === null || value === undefined || value === '') return null;
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
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

function formatDurationForInput(seconds){
  if (seconds === null || seconds === undefined) return '';
  const total = Number(seconds);
  if (!Number.isFinite(total) || total <= 0) return '';
  const mins = Math.floor(total / 60);
  const secs = total % 60;
  return secs === 0 ? String(mins) + ':00' : `${mins}:${secs.toString().padStart(2,'0')}`;
}

function ensureArray(value){
  return Array.isArray(value) ? value : [];
}

function formatSetLine(detail){
  if (!detail || typeof detail !== 'object') return null;
  const parts = [];
  const reps = detail.reps != null && detail.reps !== '' ? String(detail.reps) : '';
  const weightDisplay = detail.weight_display || (detail.weight_value != null ? computeWeightDisplay(detail.weight_value) : null);
  const durationDisplay = detail.duration_display || (detail.duration_seconds != null ? computeDurationDisplay(detail.duration_seconds) : null);
  if (reps) parts.push(`${reps} rep${reps === '1' ? '' : 's'}`);
  if (weightDisplay) parts.push(weightDisplay);
  if (durationDisplay) parts.push(durationDisplay);
  if (!parts.length) return null;
  const label = detail.set_number != null ? `Set ${detail.set_number}` : 'Set';
  return `${label}: ${parts.join(' · ')}`;
}

function computeExerciseSetDisplay(details, fallback = {}){
  const list = ensureArray(details).map((row, idx) => {
    const detail = row && typeof row === 'object' ? row : {};
    let setNumber = detail.set_number;
    if (setNumber === null || setNumber === undefined || setNumber === '') {
      setNumber = idx + 1;
    }
    setNumber = Number(setNumber);
    if (!Number.isFinite(setNumber)) setNumber = idx + 1;

    const reps = detail.reps != null && detail.reps !== '' ? String(detail.reps) : null;

    let weightValue = toNumberOrNull(detail.weight_value);
    if (weightValue === null) weightValue = toNumberOrNull(detail.weight_lbs);
    let weightDisplay = detail.weight_display != null && detail.weight_display !== '' ? String(detail.weight_display) : null;
    if (!weightDisplay && weightValue !== null) {
      weightDisplay = computeWeightDisplay(weightValue);
    }

    let durationValue = toNumberOrNull(detail.duration_seconds);
    if (durationValue === null) durationValue = toNumberOrNull(detail.duration);
    let durationDisplay = detail.duration_display != null && detail.duration_display !== '' ? String(detail.duration_display) : null;
    if (!durationDisplay && durationValue !== null) {
      durationDisplay = computeDurationDisplay(durationValue);
    }

    return {
      set_number: setNumber,
      reps,
      weight_value: weightValue,
      weight_display: weightDisplay,
      duration_seconds: durationValue,
      duration_display: durationDisplay
    };
  });

  const fallbackSetsRaw = toNumberOrNull(fallback && fallback.setsCount !== undefined ? fallback.setsCount : null);
  const fallbackReps = fallback && fallback.reps != null && fallback.reps !== '' ? String(fallback.reps) : null;
  let fallbackWeightValue = toNumberOrNull(fallback && Object.prototype.hasOwnProperty.call(fallback, 'weightValue') ? fallback.weightValue : null);
  if (fallbackWeightValue === null && fallback && Object.prototype.hasOwnProperty.call(fallback, 'weight_value')) {
    fallbackWeightValue = toNumberOrNull(fallback.weight_value);
  }
  let fallbackWeightDisplay = fallback && fallback.weightDisplay != null && fallback.weightDisplay !== '' ? String(fallback.weightDisplay) : null;
  if (!fallbackWeightDisplay && fallbackWeightValue !== null) {
    fallbackWeightDisplay = computeWeightDisplay(fallbackWeightValue);
  }

  let fallbackDurationValue = toNumberOrNull(fallback && Object.prototype.hasOwnProperty.call(fallback, 'durationSeconds') ? fallback.durationSeconds : null);
  if (fallbackDurationValue === null && fallback && Object.prototype.hasOwnProperty.call(fallback, 'duration_seconds')) {
    fallbackDurationValue = toNumberOrNull(fallback.duration_seconds);
  }
  let fallbackDurationDisplay = fallback && fallback.durationDisplay != null && fallback.durationDisplay !== '' ? String(fallback.durationDisplay) : null;
  if (!fallbackDurationDisplay && fallbackDurationValue !== null) {
    fallbackDurationDisplay = computeDurationDisplay(fallbackDurationValue);
  }

  const count = list.length;
  const hasDetails = count > 0;
  const setsRaw = hasDetails ? (fallbackSetsRaw !== null ? fallbackSetsRaw : count) : fallbackSetsRaw;
  const mutedDash = '<span class="muted">—</span>';

  if (!hasDetails) {
    return {
      uniform: true,
      setsHtml: setsRaw !== null ? `<span>${escapeHtml(String(setsRaw))}</span>` : `<span class="muted">—</span>`,
      setsRaw,
      repsHtml: fallbackReps != null ? `<span>${escapeHtml(String(fallbackReps))}</span>` : `<span class="muted">—</span>`,
      weightHtml: fallbackWeightDisplay ? `<span>${escapeHtml(String(fallbackWeightDisplay))}</span>` : `<span class="muted">—</span>`,
      durationHtml: fallbackDurationDisplay ? `<span>${escapeHtml(String(fallbackDurationDisplay))}</span>` : `<span class="muted">—</span>`,
      weightRaw: fallbackWeightValue,
      weightDisplay: fallbackWeightDisplay,
      durationRaw: fallbackDurationValue,
      durationDisplay: fallbackDurationDisplay,
      repsValue: fallbackReps
    };
  }

  const first = list[0] || {};
  const uniform = list.every((row, idx) => {
    if (idx === 0) return true;
    const repsEqual = (row.reps ?? null) === (first.reps ?? null);
    const weightEqual = (row.weight_value ?? null) === (first.weight_value ?? null);
    const durationEqual = (row.duration_seconds ?? null) === (first.duration_seconds ?? null);
    return repsEqual && weightEqual && durationEqual;
  });

  if (uniform) {
    const repsValue = first.reps != null && first.reps !== '' ? String(first.reps) : fallbackReps;
    const weightValue = first.weight_value !== null && first.weight_value !== undefined ? first.weight_value : fallbackWeightValue;
    let weightDisplay = first.weight_display != null && first.weight_display !== '' ? String(first.weight_display) : null;
    if (!weightDisplay && weightValue !== null) {
      weightDisplay = computeWeightDisplay(weightValue);
    }
    if (!weightDisplay && fallbackWeightDisplay) {
      weightDisplay = fallbackWeightDisplay;
    }

    const durationValue = first.duration_seconds !== null && first.duration_seconds !== undefined ? first.duration_seconds : fallbackDurationValue;
    let durationDisplay = first.duration_display != null && first.duration_display !== '' ? String(first.duration_display) : null;
    if (!durationDisplay && durationValue !== null) {
      durationDisplay = computeDurationDisplay(durationValue);
    }
    if (!durationDisplay && fallbackDurationDisplay) {
      durationDisplay = fallbackDurationDisplay;
    }

    const setsValue = setsRaw !== null ? setsRaw : count;
    return {
      uniform: true,
      setsHtml: `<span>${escapeHtml(String(setsValue))}</span>`,
      setsRaw: setsRaw !== null ? setsRaw : count,
      repsHtml: repsValue != null ? `<span>${escapeHtml(String(repsValue))}</span>` : `<span class="muted">—</span>`,
      weightHtml: weightDisplay ? `<span>${escapeHtml(String(weightDisplay))}</span>` : `<span class="muted">—</span>`,
      durationHtml: durationDisplay ? `<span>${escapeHtml(String(durationDisplay))}</span>` : `<span class="muted">—</span>`,
      weightRaw: weightValue !== undefined ? weightValue : null,
      weightDisplay: weightDisplay || null,
      durationRaw: durationValue !== undefined ? durationValue : null,
      durationDisplay: durationDisplay || null,
      repsValue
    };
  }

  const setsLines = list.map((row, idx) => {
    const num = row.set_number != null && row.set_number !== '' ? row.set_number : (idx + 1);
    return `<div>${escapeHtml(String(num))}</div>`;
  });

  const repsLines = list.map(row => {
    const value = row.reps;
    return value != null && value !== '' ? `<div>${escapeHtml(String(value))}</div>` : `<div>${mutedDash}</div>`;
  });

  const weightLines = list.map(row => {
    const display = row.weight_display || (row.weight_value != null ? computeWeightDisplay(row.weight_value) : null);
    return display ? `<div>${escapeHtml(String(display))}</div>` : `<div>${mutedDash}</div>`;
  });

  const durationLines = list.map(row => {
    const display = row.duration_display || (row.duration_seconds != null ? computeDurationDisplay(row.duration_seconds) : null);
    return display ? `<div>${escapeHtml(String(display))}</div>` : `<div>${mutedDash}</div>`;
  });

  const setsHtml = setsLines.length ? setsLines.join('') : `<span class="muted">—</span>`;
  const repsHtml = repsLines.length ? repsLines.join('') : `<div>${mutedDash}</div>`;
  const weightHtml = weightLines.length ? weightLines.join('') : `<div>${mutedDash}</div>`;
  const durationHtml = durationLines.length ? durationLines.join('') : `<div>${mutedDash}</div>`;

  return {
    uniform: false,
    setsHtml,
    setsRaw: setsRaw !== null ? setsRaw : count,
    repsHtml,
    weightHtml,
    durationHtml,
    weightRaw: null,
    weightDisplay: null,
    durationRaw: null,
    durationDisplay: null,
    repsValue: null
  };
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

  const setDetailsIncoming = ensureArray(data.set_details).map((row, idx) => ({
    set_number: row && row.set_number != null ? row.set_number : (idx + 1),
    reps: row && row.reps != null ? row.reps : null,
    weight_value: row && row.weight_value != null ? Number(row.weight_value) : null,
    weight_display: row && row.weight_display != null ? row.weight_display : null,
    duration_seconds: row && row.duration_seconds != null ? Number(row.duration_seconds) : null,
    duration_display: row && row.duration_display != null ? row.duration_display : null,
  }));
  const setsCountIncoming = ('sets_count' in data && data.sets_count != null)
    ? Number(data.sets_count)
    : (('sets' in data && data.sets != null && data.sets !== '') ? Number(data.sets) : setDetailsIncoming.length);
  const fallback = {
    setsCount: !Number.isNaN(setsCountIncoming) && setsCountIncoming !== null ? setsCountIncoming : null,
    reps: ('reps' in data) ? data.reps : null,
    weightValue: toNumberOrNull(
      ('weight_value' in data) ? data.weight_value
        : (('weight' in data && typeof data.weight === 'number') ? data.weight : null)
    ),
    weightDisplay: ('weight_display' in data) ? data.weight_display
      : (('weight' in data && typeof data.weight === 'string') ? data.weight : null),
    durationSeconds: toNumberOrNull(
      ('duration_seconds' in data) ? data.duration_seconds
        : (('duration' in data && typeof data.duration === 'number') ? data.duration : null)
    ),
    durationDisplay: ('duration_display' in data) ? data.duration_display
      : (('duration' in data && typeof data.duration === 'string') ? data.duration : null)
  };
  const display = computeExerciseSetDisplay(setDetailsIncoming, fallback);
  const {
    repsValue,
    weightRaw,
    weightDisplay,
    durationRaw,
    durationDisplay
  } = display;

  if (cells.sets) {
    cells.sets.innerHTML = display.setsHtml;
    if (display.setsRaw != null) {
      cells.sets.dataset.raw = String(display.setsRaw);
    } else {
      delete cells.sets.dataset.raw;
    }
  }

  if (cells.reps) {
    cells.reps.innerHTML = display.repsHtml;
    if (display.repsValue != null) {
      cells.reps.dataset.raw = String(display.repsValue);
    } else {
      delete cells.reps.dataset.raw;
    }
  }

  if (cells.weight) {
    cells.weight.innerHTML = display.weightHtml;
    if (display.weightRaw != null) {
      cells.weight.dataset.raw = String(display.weightRaw);
    } else {
      delete cells.weight.dataset.raw;
    }
  }

  if (cells.duration) {
    cells.duration.innerHTML = display.durationHtml;
    if (display.durationRaw != null) {
      cells.duration.dataset.raw = String(display.durationRaw);
    } else {
      delete cells.duration.dataset.raw;
    }
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

  const normalizedSets = !Number.isNaN(setsCountIncoming) && setsCountIncoming !== null ? String(setsCountIncoming) : (existing.sets ?? null);
  const normalizedReps = (repsValue !== undefined) ? (repsValue === '' ? null : repsValue ?? null) : (existing.reps ?? null);

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
    sets_count: !Number.isNaN(setsCountIncoming) ? setsCountIncoming : (existing.sets_count ?? null),
    set_details: setDetailsIncoming,
    set_details_json: ('set_details_json' in data) ? (data.set_details_json ?? null) : (existing.set_details_json ?? null),
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

function getExerciseRowContext(tr){
  if (!tr) return null;
  const uid = parseInt(tr.dataset.userId, 10);
  const planId = parseInt(tr.dataset.planId, 10);
  const exId = parseInt(tr.dataset.exId, 10);
  if (!uid || !planId || !exId) return null;
  return { uid, planId, exId };
}

function getStoredExerciseData(context){
  if (!context) return {};
  const cache = window.__USER_EX || {};
  const userBucket = cache[context.uid];
  if (!userBucket) return {};
  const planBucket = userBucket[context.planId];
  if (!planBucket) return {};
  return planBucket[context.exId] || {};
}

function detailWeightValue(detail){
  if (!detail || typeof detail !== 'object') return '';
  if (detail.weight_value !== undefined && detail.weight_value !== null && detail.weight_value !== '') {
    return detail.weight_value;
  }
  if (detail.weight_lbs !== undefined && detail.weight_lbs !== null && detail.weight_lbs !== '') {
    return detail.weight_lbs;
  }
  if (detail.weight !== undefined && detail.weight !== null && detail.weight !== '') {
    return detail.weight;
  }
  return '';
}

function detailDurationValue(detail){
  if (!detail || typeof detail !== 'object') return '';
  if (detail.duration_seconds !== undefined && detail.duration_seconds !== null && detail.duration_seconds !== '') {
    return detail.duration_seconds;
  }
  if (detail.duration !== undefined && detail.duration !== null && detail.duration !== '') {
    return detail.duration;
  }
  return '';
}

function stringOrEmpty(value){
  if (value === null || value === undefined) return '';
  return String(value).trim();
}

function extractExerciseAggregates(stored){
  const details = ensureArray(stored && stored.set_details);
  let count = details.length;
  const setsCount = toNumberOrNull(stored && stored.sets_count);
  if (!count && setsCount !== null) count = setsCount;
  const legacySets = toNumberOrNull(stored && stored.sets);
  if (!count && legacySets !== null) count = legacySets;
  if (!Number.isFinite(count) || count < 0) count = 0;

  const first = details[0] || {};
  const reps = (stored && stored.reps != null && stored.reps !== '')
    ? String(stored.reps)
    : (first.reps != null && first.reps !== '' ? String(first.reps) : '');
  const weightVal = (stored && stored.weight_value != null && stored.weight_value !== '')
    ? String(stored.weight_value)
    : stringOrEmpty(detailWeightValue(first));
  const durationVal = (stored && stored.duration_seconds != null && stored.duration_seconds !== '')
    ? String(stored.duration_seconds)
    : stringOrEmpty(detailDurationValue(first));

  return {
    details,
    count,
    reps,
    weight: weightVal,
    duration: durationVal
  };
}

function isSetDetailsUniform(details){
  const list = ensureArray(details);
  if (list.length <= 1) return true;
  const first = list[0] || {};
  const firstReps = stringOrEmpty(first.reps);
  const firstWeight = stringOrEmpty(detailWeightValue(first));
  const firstDuration = stringOrEmpty(detailDurationValue(first));
  return list.every(item => (
    stringOrEmpty(item && item.reps) === firstReps &&
    stringOrEmpty(detailWeightValue(item)) === firstWeight &&
    stringOrEmpty(detailDurationValue(item)) === firstDuration
  ));
}

function getInitialInlineValue(field, td, stored, aggregates){
  const rawAttr = td && td.hasAttribute('data-raw') ? td.getAttribute('data-raw') : null;
  if (field === 'notes') {
    return stored && stored.notes != null ? String(stored.notes) : '';
  }
  if (field === 'sets') {
    if (rawAttr !== null && rawAttr !== undefined) return rawAttr;
    return aggregates.count ? String(aggregates.count) : '';
  }
  if (field === 'reps') {
    if (rawAttr !== null && rawAttr !== undefined) return rawAttr;
    return aggregates.reps || '';
  }
  if (field === 'weight') {
    if (rawAttr !== null && rawAttr !== undefined) return rawAttr;
    return aggregates.weight || '';
  }
  if (field === 'duration') {
    let base = (rawAttr !== null && rawAttr !== undefined) ? rawAttr : aggregates.duration;
    if (base === null || base === undefined || base === '') return '';
    const num = Number(base);
    if (!Number.isNaN(num) && num > 0) {
      return formatDurationForInput(num) || String(base);
    }
    return String(base);
  }
  return '';
}

function normalizeInlineInput(field, value){
  if (value === null || value === undefined) return '';
  const str = String(value).trim();
  if (field === 'duration') {
    return str.replace(/\s+/g, '');
  }
  return str;
}

function buildInlineSetPayload(stored, overrides = {}){
  const aggregates = extractExerciseAggregates(stored || {});
  let count = overrides.sets !== undefined ? parseInt(overrides.sets, 10) : aggregates.count;
  if (!Number.isFinite(count) || count < 0) count = 0;

  const preserveExisting = overrides.preserveExisting && aggregates.details.length && (
    overrides.sets === undefined || parseInt(overrides.sets, 10) === aggregates.details.length
  );

  if (preserveExisting) {
    return aggregates.details.map(detail => ({
      reps: overrides.reps !== undefined ? overrides.reps : stringOrEmpty(detail && detail.reps),
      weight: overrides.weight !== undefined ? overrides.weight : stringOrEmpty(detailWeightValue(detail)),
      duration: overrides.duration !== undefined ? overrides.duration : stringOrEmpty(detailDurationValue(detail))
    }));
  }

  const repsValue = overrides.reps !== undefined ? overrides.reps : aggregates.reps;
  const weightValue = overrides.weight !== undefined ? overrides.weight : aggregates.weight;
  const durationValue = overrides.duration !== undefined ? overrides.duration : aggregates.duration;

  const payload = [];
  if (count > 0) {
    for (let i = 0; i < count; i++) {
      payload.push({
        reps: stringOrEmpty(repsValue),
        weight: stringOrEmpty(weightValue),
        duration: stringOrEmpty(durationValue)
      });
    }
  }
  return payload;
}

let activeCellEditor = null;

async function saveInlineField(editor, newValue){
  const { field, context, stored, aggregates, tr } = editor;
  if (!context) throw new Error('Missing context');

  const overrides = {};
  if (field === 'notes') overrides.notes = newValue;
  if (field === 'sets') overrides.sets = newValue;
  if (field === 'reps') overrides.reps = newValue;
  if (field === 'weight') overrides.weight = newValue;
  if (field === 'duration') overrides.duration = newValue;

  const setsField = overrides.sets !== undefined ? overrides.sets : (aggregates.count ? String(aggregates.count) : '');
  const repsField = overrides.reps !== undefined ? overrides.reps : aggregates.reps;
  const weightField = overrides.weight !== undefined ? overrides.weight : aggregates.weight;
  const durationField = overrides.duration !== undefined ? overrides.duration : aggregates.duration;
  const notesField = overrides.notes !== undefined
    ? overrides.notes
    : (stored && stored.notes != null ? String(stored.notes) : '');

  const payload = buildInlineSetPayload(stored, {
    ...overrides,
    preserveExisting: field === 'notes'
  });

  const fd = new FormData();
  fd.append('csrf_token', window.__CSRF || '');
  fd.append('action', 'save_user_exercise');
  fd.append('user_id', String(context.uid));
  fd.append('plan_id', String(context.planId));
  fd.append('exercise_id', String(context.exId));
  fd.append('sets', setsField != null ? String(setsField).trim() : '');
  fd.append('reps', repsField != null ? String(repsField).trim() : '');
  fd.append('weight_lbs', weightField != null ? String(weightField).trim() : '');
  fd.append('duration_seconds', durationField != null ? String(durationField).trim() : '');
  fd.append('user_notes', notesField != null ? String(notesField).trim() : '');
  fd.append('set_payload', JSON.stringify(payload));

  const res = await fetch('clients.php', { method: 'POST', body: fd });
  const json = await res.json();
  if (!json || !json.ok) {
    const msg = (json && json.error) ? json.error : 'Save failed';
    throw new Error(msg);
  }
  applyUserExerciseData(tr, json.data);
  setActionsToEdit(tr);
}

function openCellEditor(td){
  if (!td || td.dataset.editing === '1') return;
  const field = td.getAttribute('data-cell');
  if (!field || !['notes','sets','reps','weight','duration'].includes(field)) return;
  const tr = td.closest('tr.mini-ex-row');
  const context = getExerciseRowContext(tr);
  if (!context) return;
  const stored = getStoredExerciseData(context);
  const aggregates = extractExerciseAggregates(stored);

  if (field !== 'notes' && !isSetDetailsUniform(stored && stored.set_details)) {
    eeOpen(tr);
    return;
  }

  const initialValue = getInitialInlineValue(field, td, stored, aggregates);
  const normalizedOriginal = normalizeInlineInput(field, initialValue);

  if (activeCellEditor && activeCellEditor.input) {
    activeCellEditor.input.blur();
  }

  const previousHTML = td.innerHTML;
  td.dataset.editing = '1';
  td.innerHTML = '';

  let input;
  if (field === 'notes') {
    input = document.createElement('textarea');
    input.className = 'input';
    input.rows = 3;
  } else {
    input = document.createElement('input');
    input.className = 'input';
    if (field === 'sets') {
      input.type = 'number';
      input.step = '1';
      input.min = '0';
    } else if (field === 'reps') {
      input.type = 'text';
    } else if (field === 'weight') {
      input.type = 'number';
      input.step = '0.1';
      input.min = '0';
    } else {
      input.type = 'text';
      input.placeholder = 'e.g., 1:30';
    }
  }
  input.style.width = '100%';
  input.value = initialValue || '';
  td.appendChild(input);
  input.focus();
  input.select();

  const editor = {
    td,
    tr,
    field,
    input,
    context,
    stored,
    aggregates,
    previousHTML,
    normalizedOriginal,
    closed: false,
    saving: false
  };
  activeCellEditor = editor;

  const revert = () => {
    if (editor.closed) return;
    editor.closed = true;
    input.removeEventListener('blur', onBlur);
    td.dataset.editing = '';
    td.innerHTML = editor.previousHTML;
    if (activeCellEditor === editor) activeCellEditor = null;
  };

  const commit = async () => {
    if (editor.closed || editor.saving) return;
    const normalizedNew = normalizeInlineInput(field, input.value);
    if (normalizedNew === editor.normalizedOriginal) {
      revert();
      return;
    }
    editor.saving = true;
    input.disabled = true;
    try {
      await saveInlineField(editor, input.value.trim());
      editor.closed = true;
      input.removeEventListener('blur', onBlur);
      td.dataset.editing = '';
      if (activeCellEditor === editor) activeCellEditor = null;
    } catch (err) {
      window.alert(err.message || 'Failed to save changes.');
      revert();
    }
  };

  const onBlur = () => {
    setTimeout(commit, 0);
  };

  input.addEventListener('blur', onBlur);
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') {
      ev.preventDefault();
      revert();
      return;
    }
    if (ev.key === 'Enter') {
      if (field === 'notes') {
        if (ev.ctrlKey || ev.metaKey) {
          ev.preventDefault();
          commit();
        }
      } else {
        ev.preventDefault();
        commit();
      }
    }
  });
}


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