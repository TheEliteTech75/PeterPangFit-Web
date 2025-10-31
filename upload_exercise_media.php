<?php
// upload_exercise_media.php — handles video/poster/captions upload for an Exercise
// Expects: POST { csrf_token, exercise_id, kind: 'video'|'poster'|'captions', file }
// Returns: JSON { ok: true, ... } or { ok:false, error:"..." }

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';

// Fallback guard in case helpers.php didn't define it yet
if (!function_exists('ppf_fix_permissions')) {
  function ppf_fix_permissions(string $path, bool $isDir): void {
    // no-op fallback; real implementation should live in helpers.php
    if (PHP_OS_FAMILY !== 'Windows') {
      @chmod($path, $isDir ? 0755 : 0644);
    }
  }
}

/** Small helper to write structured log JSON consistently */
function ppf_log_detail(mysqli $conn, string $action, ?int $exercise_id, array $data): void {
  if (!function_exists('ppf_log')) return;
  // add some standard context
  $data += [
    'server_os'     => PHP_OS_FAMILY,
    'content_len'   => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
    'app_user'      => $_SESSION['email'] ?? null,
    'role'          => $_SESSION['role'] ?? null,
    'ua'            => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'req_id'        => bin2hex(random_bytes(6)),
  ];
  @ppf_log(
    $conn,
    null,                        // actor id (autofilled in ppf_log if session has it)
    null,                        // actor email
    null,                        // actor role
    $action,                     // action
    'exercise',                  // target_type
    $exercise_id ? (string)$exercise_id : null, // target_id
    json_encode($data, JSON_UNESCAPED_SLASHES)
  );
}

// Gate (trainer/admin)
function is_trainer_admin($role){
  return ppf_role_has_trainer_access($role);
}
if (!is_trainer_admin($USER_ROLE ?? ($_SESSION['role'] ?? null))) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'Forbidden']);
  exit;
}

// CSRF
$csrf_ok = hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
if (!$csrf_ok) {
  ppf_log_detail($conn, 'exercise_media_upload_failed', null, [
    'reason' => 'csrf_mismatch',
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Invalid session. Please refresh and try again.']);
  exit;
}

// Input
$exercise_id = (int)($_POST['exercise_id'] ?? 0);
$kind = $_POST['kind'] ?? '';
if ($exercise_id <= 0 || !in_array($kind, ['video','poster','captions'], true)) {
  ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id ?: null, [
    'reason' => 'bad_request',
    'kind'   => $kind,
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Bad request.']);
  exit;
}

// Ensure columns exist (when hitting endpoint directly)
if (!function_exists('column_exists')) {
  function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
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
$needCols = [
  'video_url','video_poster_url','video_duration_sec',
  'video_autoplay','video_loop','video_muted','captions_vtt_url'
];
foreach ($needCols as $c) {
  if (!column_exists($conn, 'exercises', $c)) {
    $ddlMap = [
      'video_url'         => "ALTER TABLE `exercises` ADD COLUMN `video_url` VARCHAR(512) NULL",
      'video_poster_url'  => "ALTER TABLE `exercises` ADD COLUMN `video_poster_url` VARCHAR(512) NULL",
      'video_duration_sec'=> "ALTER TABLE `exercises` ADD COLUMN `video_duration_sec` INT NULL",
      'video_autoplay'    => "ALTER TABLE `exercises` ADD COLUMN `video_autoplay` TINYINT(1) NOT NULL DEFAULT 0",
      'video_loop'        => "ALTER TABLE `exercises` ADD COLUMN `video_loop` TINYINT(1) NOT NULL DEFAULT 0",
      'video_muted'       => "ALTER TABLE `exercises` ADD COLUMN `video_muted` TINYINT(1) NOT NULL DEFAULT 1",
      'captions_vtt_url'  => "ALTER TABLE `exercises` ADD COLUMN `captions_vtt_url` VARCHAR(512) NULL",
    ];
    @$conn->query($ddlMap[$c] ?? '');
  }
}

// Exercise exists?
$exists = false;
if ($stmt = $conn->prepare("SELECT id FROM exercises WHERE id=? LIMIT 1")) {
  $stmt->bind_param("i", $exercise_id);
  $stmt->execute();
  $stmt->bind_result($dummy);
  $exists = $stmt->fetch();
  $stmt->close();
}
if (!$exists) {
  ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
    'reason' => 'exercise_not_found',
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Exercise not found.']);
  exit;
}

// Files
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
    'reason'     => 'no_file',
    'files_meta' => array_keys($_FILES),
  ]);
  echo json_encode(['ok'=>false, 'error'=>'No file uploaded.']);
  exit;
}
$f = $_FILES['file'];
$err = (int)$f['error'];
if ($err !== UPLOAD_ERR_OK) {
  $errMap = [
    UPLOAD_ERR_INI_SIZE   => 'UPLOAD_ERR_INI_SIZE',
    UPLOAD_ERR_FORM_SIZE  => 'UPLOAD_ERR_FORM_SIZE',
    UPLOAD_ERR_PARTIAL    => 'UPLOAD_ERR_PARTIAL',
    UPLOAD_ERR_NO_FILE    => 'UPLOAD_ERR_NO_FILE',
    UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
    UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
    UPLOAD_ERR_EXTENSION  => 'UPLOAD_ERR_EXTENSION',
  ];
  ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
    'reason'     => 'php_upload_error',
    'code'       => $err,
    'code_name'  => $errMap[$err] ?? 'UNKNOWN',
    'tmp_exists' => is_file($f['tmp_name']),
    'tmp_path'   => $f['tmp_name'],
  ]);
  echo json_encode(['ok'=>false, 'error'=>'Upload error.']);
  exit;
}

$size = (int)$f['size'];
$type = (string)($f['type'] ?? '');
$name = (string)($f['name'] ?? '');

// Constraints
$maxBytes = 200 * 1024 * 1024; // 200 MB
$destDir = __DIR__ . "/uploads/exercise_media/{$exercise_id}";
$webBase = "/uploads/exercise_media/{$exercise_id}";
if (!is_dir($destDir)) {
  if (!@mkdir($destDir, 0775, true)) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason' => 'mkdir_failed',
      'dir'    => $destDir,
      'parent_writable' => is_writable(dirname($destDir)),
      'last_error' => error_get_last(),
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Server cannot create media directory.']);
    exit;
  }
  ppf_fix_permissions($destDir, true);
} else {
  ppf_fix_permissions($destDir, true);
}

function safe_filename(string $n): string {
  $n = preg_replace('/[^A-Za-z0-9._-]/', '_', $n);
  return substr($n, -120);
}

$destPath = '';
$webUrl   = '';

if ($kind === 'video') {
  // Size guard
  if ($size > $maxBytes) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason' => 'too_large',
      'bytes'  => $size,
      'limit'  => $maxBytes,
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Video exceeds 200 MB limit.']);
    exit;
  }

  // Extension guard
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['mp4','m4v'], true)) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason'  => 'bad_extension',
      'ext'     => $ext,
      'name'    => $name,
      'mime'    => $type,
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Only MP4 videos are allowed.']);
    exit;
  }

  $fn = 'video_' . time() . '.mp4';
  $destPath = $destDir . '/' . $fn;
  $webUrl   = $webBase . '/' . $fn;

  if (!@move_uploaded_file($f['tmp_name'], $destPath)) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason'        => 'move_failed',
      'tmp'           => $f['tmp_name'],
      'dest'          => $destPath,
      'dir_writable'  => is_writable($destDir),
      'dest_exists'   => file_exists($destPath),
      'last_error'    => error_get_last(),
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Failed to save video file.']);
    exit;
  }
  ppf_fix_permissions($destPath, false);
  ppf_fix_permissions($destDir, true);

  // Update DB
  $stmt = $conn->prepare("UPDATE exercises SET video_url=? WHERE id=?");
  if (!$stmt) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason' => 'db_prepare_failed',
      'sql'    => 'UPDATE exercises SET video_url=? WHERE id=?',
      'mysqli_error' => $conn->error,
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Server error (prepare).']);
    exit;
  }
  $stmt->bind_param("si", $webUrl, $exercise_id);
  if (!$stmt->execute()) {
    $stmt->close();
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason' => 'db_execute_failed',
      'mysqli_error' => $conn->error,
      'url'    => $webUrl,
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Server error (save).']);
    exit;
  }
  $stmt->close();

  ppf_log_detail($conn, 'exercise_video_uploaded', $exercise_id, [
    'size' => $size,
    'url'  => $webUrl
  ]);

  echo json_encode(['ok'=>true, 'url'=>$webUrl]);
  exit;
}

if ($kind === 'poster') {
  // Poster image
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
    echo json_encode(['ok'=>false, 'error'=>'Poster must be jpg/png/webp.']);
    exit;
  }
  $fn = 'poster_' . time() . '.' . $ext;
  $destPath = $destDir . '/' . $fn;
  $webUrl   = $webBase . '/' . $fn;

  if (!@move_uploaded_file($f['tmp_name'], $destPath)) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason' => 'move_failed',
      'tmp'    => $f['tmp_name'],
      'dest'   => $destPath,
      'dir_writable' => is_writable($destDir),
      'dest_exists'  => file_exists($destPath),
      'last_error'   => error_get_last(),
      'kind'         => 'poster',
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Failed to save poster image.']);
    exit;
  }
  ppf_fix_permissions($destPath, false);
  ppf_fix_permissions($destDir, true);

  $stmt = $conn->prepare("UPDATE exercises SET video_poster_url=? WHERE id=?");
  $stmt->bind_param("si", $webUrl, $exercise_id);
  $stmt->execute();
  $stmt->close();

  ppf_log_detail($conn, 'exercise_poster_uploaded', $exercise_id, [
    'url' => $webUrl
  ]);

  echo json_encode(['ok'=>true, 'url'=>$webUrl]);
  exit;
}

if ($kind === 'captions') {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext !== 'vtt') {
    echo json_encode(['ok'=>false, 'error'=>'Captions must be .vtt.']);
    exit;
  }
  $fn = 'captions_' . time() . '.vtt';
  $destPath = $destDir . '/' . $fn;
  $webUrl   = $webBase . '/' . $fn;

  if (!@move_uploaded_file($f['tmp_name'], $destPath)) {
    ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
      'reason' => 'move_failed',
      'tmp'    => $f['tmp_name'],
      'dest'   => $destPath,
      'dir_writable' => is_writable($destDir),
      'dest_exists'  => file_exists($destPath),
      'last_error'   => error_get_last(),
      'kind'         => 'captions',
    ]);
    echo json_encode(['ok'=>false, 'error'=>'Failed to save captions.']);
    exit;
  }
  ppf_fix_permissions($destPath, false);
  ppf_fix_permissions($destDir, true);

  $stmt = $conn->prepare("UPDATE exercises SET captions_vtt_url=? WHERE id=?");
  $stmt->bind_param("si", $webUrl, $exercise_id);
  $stmt->execute();
  $stmt->close();

  ppf_log_detail($conn, 'exercise_captions_uploaded', $exercise_id, [
    'url' => $webUrl
  ]);

  echo json_encode(['ok'=>true, 'url'=>$webUrl]);
  exit;
}

// Fallback
ppf_log_detail($conn, 'exercise_media_upload_failed', $exercise_id, [
  'reason' => 'unsupported_kind',
  'kind'   => $kind,
]);
echo json_encode(['ok'=>false, 'error'=>'Unsupported kind.']);