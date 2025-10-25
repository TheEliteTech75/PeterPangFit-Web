<?php
// delete_exercise_media.php — removes video/poster/captions for an Exercise
// Expects: POST { csrf_token, exercise_id, kind: 'video'|'poster'|'captions' }

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';

/** Small helper to write structured log JSON consistently (matches upload script) */
function ppf_log_detail(mysqli $conn, string $action, ?int $exercise_id, array $data): void {
  if (!function_exists('ppf_log')) return;
  $data += [
    'server_os'   => PHP_OS_FAMILY,
    'app_user'    => $_SESSION['email'] ?? null,
    'role'        => $_SESSION['role'] ?? null,
    'ua'          => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'req_id'      => bin2hex(random_bytes(6)),
  ];
  @ppf_log(
    $conn,
    null,                                     // actor id (ppf_log may autofill from session)
    null,                                     // actor email
    null,                                     // actor role
    $action,                                  // action
    'exercise',                               // target_type
    $exercise_id ? (string)$exercise_id : null, // target_id
    json_encode($data, JSON_UNESCAPED_SLASHES)
  );
}

function is_trainer_admin($role){
  $r = ppf_role_key($role);
  return $r === 'trainer' || ppf_is_admin_role($role);
}
if (!is_trainer_admin($USER_ROLE ?? ($_SESSION['role'] ?? null))) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'Forbidden']);
  exit;
}

$csrf_ok = hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
if (!$csrf_ok) {
  ppf_log_detail($conn, 'exercise_media_remove_failed', null, [
    'reason' => 'csrf_mismatch',
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Invalid session.']);
  exit;
}

$exercise_id = (int)($_POST['exercise_id'] ?? 0);
$kind = $_POST['kind'] ?? '';
if ($exercise_id <= 0 || !in_array($kind, ['video','poster','captions'], true)) {
  ppf_log_detail($conn, 'exercise_media_remove_failed', $exercise_id ?: null, [
    'reason' => 'bad_request',
    'kind'   => $kind,
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Bad request.']);
  exit;
}

// Confirm exercise exists (helps separate "no url" from "bad id")
$exists = false;
if ($stmt = $conn->prepare("SELECT id FROM exercises WHERE id=? LIMIT 1")) {
  $stmt->bind_param("i", $exercise_id);
  $stmt->execute();
  $stmt->bind_result($dummy);
  $exists = (bool)$stmt->fetch();
  $stmt->close();
}
if (!$exists) {
  ppf_log_detail($conn, 'exercise_media_remove_failed', $exercise_id, [
    'reason' => 'exercise_not_found',
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Exercise not found.']);
  exit;
}

// Column map
$cols = [
  'video'    => 'video_url',
  'poster'   => 'video_poster_url',
  'captions' => 'captions_vtt_url',
];
$col = $cols[$kind] ?? null;

// Load current URL
$url = null;
if ($col && ($stmt = $conn->prepare("SELECT $col FROM exercises WHERE id=? LIMIT 1"))) {
  $stmt->bind_param("i", $exercise_id);
  $stmt->execute();
  $stmt->bind_result($url);
  $stmt->fetch();
  $stmt->close();
}

// Compute absolute path if the URL is under our /uploads/exercise_media/
$absPath = null;
if ($url && preg_match('#^/uploads/exercise_media/#', $url)) {
  // Make it relative to app dir and normalize separators for Windows
  $rel = ltrim($url, '/');
  $absPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

$deleted = false;
$hadFile = false;
$existsBefore = false;

// Remove the file on disk first (best-effort, but we log failures)
if ($absPath) {
  $existsBefore = is_file($absPath);
  $hadFile = $existsBefore;
  if ($existsBefore) {
    // Try to unlink
    if (!@unlink($absPath)) {
      ppf_log_detail($conn, 'exercise_media_remove_failed', $exercise_id, [
        'reason'      => 'unlink_failed',
        'kind'        => $kind,
        'path'        => $absPath,
        'path_exists' => file_exists($absPath),
        'writable'    => is_writable($absPath) || is_writable(dirname($absPath)),
        'last_error'  => error_get_last(),
        'prev_url'    => $url,
      ]);
      echo json_encode(['ok'=>false, 'error'=>'Failed to remove file from disk.']);
      exit;
    }
    $deleted = true;
  }
}

// Clear DB column (and reset duration when removing video)
$q = "UPDATE exercises SET $col=NULL";
if ($kind === 'video') { $q .= ", video_duration_sec=NULL"; }
$q .= " WHERE id=?";
if (!$stmt = $conn->prepare($q)) {
  ppf_log_detail($conn, 'exercise_media_remove_failed', $exercise_id, [
    'reason'        => 'db_prepare_failed',
    'kind'          => $kind,
    'mysqli_error'  => $conn->error,
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Server error (prepare).']);
  exit;
}
$stmt->bind_param("i", $exercise_id);
if (!$stmt->execute()) {
  $stmt->close();
  ppf_log_detail($conn, 'exercise_media_remove_failed', $exercise_id, [
    'reason'        => 'db_execute_failed',
    'kind'          => $kind,
    'mysqli_error'  => $conn->error,
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Server error (save).']);
  exit;
}
$stmt->close();

// Nothing was on disk and URL was empty — still a valid "remove" from DB perspective
if (!$hadFile && empty($url)) {
  ppf_log_detail($conn, 'exercise_media_remove_failed', $exercise_id, [
    'reason' => 'nothing_to_remove',
    'kind'   => $kind,
  ]);
  // We still return ok=true to keep UX simple (idempotent)
}

// Success log
ppf_log_detail($conn, 'exercise_media_removed', $exercise_id, [
  'kind'        => $kind,
  'prev_url'    => $url,
  'abs_deleted' => $deleted,
  'path'        => $absPath,
]);

echo json_encode(['ok'=>true]);