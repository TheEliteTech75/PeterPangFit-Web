<?php
// profile.php — user self-service profile editor with avatar upload, per-user folders, photo picker modal
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/send_email.php'; // for send_plain_email()
require_once __DIR__ . '/logs.php';       // logging (expects ppf_log())

if (!isset($USER_ID) || !$USER_ID) { header('Location: login.php'); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safe_str_date(?string $s): ?string {
  if (!$s) return null;
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

// ---------- logging wrapper ----------
function ppf_log_safe(mysqli $conn, ?int $user_id, ?string $email, ?string $role, string $event, string $details=''): void {
  if (function_exists('ppf_log')) {
    @ppf_log($conn, $user_id, $email, $role, $event, 'user', $user_id !== null ? (string)$user_id : null, $details);
  }
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

// ---------- cross-platform permission helper ----------
/**
 * Ensure reasonable permissions for uploads.
 * - Windows: use `icacls` to grant Administrators:(F) and IIS_IUSRS:(M), enable inheritance.
 * - *nix:    chmod 0755 for directories, 0644 for files.
 */

// Optional photo column
$PHOTO_COL = column_exists($conn, 'users', 'photo_url') ? 'photo_url' : null;

// CSRF
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$uid = (int)$USER_ID;
$me = [
  'first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => '',
  'phone' => '', 'birthdate' => '', 'gender' => '', 'photo_url' => '',
  'height_ft'=>null, 'height_in'=>null, 'weight_lbs'=>null
];

$q = "SELECT first_name, middle_name, last_name, email, phone, birthdate, gender, height_ft, height_in, weight_lbs"
   . ($PHOTO_COL ? ", {$PHOTO_COL} AS photo_url" : "")
   . " FROM users WHERE id = ?";
if ($stmt = $conn->prepare($q)) {
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  if ($res = $stmt->get_result()) {
    if ($row = $res->fetch_assoc()) $me = array_merge($me, $row);
  }
  $stmt->close();
}

$userEmail = (string)($me['email'] ?? '');
$userRole  = (string)($USER_ROLE ?? '');
$profileMeasurementSystem = ppf_measurement_user_system();
$profileHeightMetricInput = '';
if ($profileMeasurementSystem === 'metric') {
  $profileHeightMetricInput = ppf_measurement_height_metric_value($me['height_ft'] ?? null, $me['height_in'] ?? null);
}

// Normalize gender for default selection (case-insensitive)
$graw = trim((string)($me['gender'] ?? ''));
$gci  = mb_strtolower($graw);
$genderNormalized = ($gci === 'male') ? 'Male' : (($gci === 'female') ? 'Female' : '');

$flash = null; $flash_type = 'ok';

// --- helpers for per-user avatar dir + listing ---
function user_avatar_dir(int $uid): string {
  return __DIR__ . '/uploads/' . $uid . '/avatars/';
}
function ensure_user_avatar_dir(int $uid): string {
  $dir = user_avatar_dir($uid);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  ppf_fix_permissions($dir, true);
  return $dir;
}
function rel_from_abs(string $abs): string {
  $root = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  $absN = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $abs);
  if (stripos($absN, $root) === 0) {
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($absN, strlen($root)));
    return $rel;
  }
  return $abs;
}
function list_user_avatars(int $uid): array {
  $dir = ensure_user_avatar_dir($uid);
  $files = glob($dir . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
  $out = [];
  if ($files) {
    foreach ($files as $f) {
      $out[] = ['abs'=>$f, 'rel'=>rel_from_abs($f), 'mtime'=>@filemtime($f) ?: 0];
    }
    usort($out, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
  }
  return $out;
}

// Password policy
function password_meets_requirements(string $pwd, array $user): ?string {
  if (strlen($pwd) < 12) return 'Password must be at least 12 characters.';
  if (!preg_match('/[A-Z]/', $pwd) || !preg_match('/\d/', $pwd) || !preg_match('/[^A-Za-z0-9]/', $pwd)) {
    return 'Password must include at least one capital letter, one number, and one special character.';
  }
  $lowerPwd = mb_strtolower($pwd);
  $email = mb_strtolower((string)($user['email'] ?? ''));
  $first = mb_strtolower((string)($user['first_name'] ?? ''));
  $last  = mb_strtolower((string)($user['last_name'] ?? ''));
  $fragments = [];
  $add = function(string $token) use (&$fragments){
    $t = preg_replace('/[^a-z0-9]+/i', '', $token);
    $n = mb_strlen($t);
    if ($n < 3) return;
    for ($i=0; $i <= $n-3; $i++) {
      for ($len=3; $len <= $n-$i; $len++) {
        $frag = mb_substr($t, $i, $len);
        if (mb_strlen($frag) > 16) break;
        $fragments[$frag] = true;
      }
    }
  };
  if ($email !== '') foreach (preg_split('/[^a-z0-9]+/i', $email) as $tok) if ($tok!=='') $add($tok);
  foreach ([$first,$last] as $nm) if ($nm!=='') foreach (preg_split('/[^a-z0-9]+/i', $nm) as $tok) if ($tok!=='') $add($tok);
  foreach ($fragments as $frag => $_) {
    if ($frag !== '' && mb_strpos($lowerPwd, $frag) !== false) {
      return 'Password cannot contain your name or email (even partial matches).';
    }
  }
  return null;
}

// --------------------- POST actions ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'save_profile';

  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Your session expired. Please try again.'; $flash_type = 'err';
  } else {

    // CHANGE PASSWORD
    if ($action === 'change_password') {
      try {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $conf= (string)($_POST['confirm_password'] ?? '');

        if ($old === '' || $new === '' || $conf === '') { throw new Exception('Please fill out Old Password, New Password, and Confirm Password.'); }
        if ($new !== $conf) { throw new Exception('New Password and Confirm Password do not match.'); }

        $hash = null;
        if ($stmt = $conn->prepare("SELECT password_hash, email, first_name, last_name FROM users WHERE id = ?")) {
          $stmt->bind_param("i", $uid);
          $stmt->execute();
          $res = $stmt->get_result();
          if ($row = $res->fetch_assoc()) {
            $hash = $row['password_hash'] ?? null;
            $me['email'] = $row['email'] ?? $me['email'];
            $me['first_name'] = $row['first_name'] ?? $me['first_name'];
            $me['last_name']  = $row['last_name']  ?? $me['last_name'];
          }
          $stmt->close();
        }
        if (!$hash || !password_verify($old, $hash)) { throw new Exception('Old Password is incorrect.'); }

        $msg = password_meets_requirements($new, [
          'email' => $me['email'] ?? '',
          'first_name' => $me['first_name'] ?? '',
          'last_name' => $me['last_name'] ?? '',
        ]);
        if ($msg !== null) { throw new Exception($msg); }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        if (!$stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?")) {
          throw new Exception('Failed to prepare password update.');
        }
        $stmt->bind_param("si", $newHash, $uid);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to update password.'); }
        $stmt->close();

        $toName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
        $toMail = (string)($me['email'] ?? '');
        $sent = false;
        if ($toMail !== '') {
          $subject = 'Your Peter Pang Fit password was changed';
          $body = "Hi {$toName},\n\nYour password was just changed.\nIf you did not make this change, please contact support immediately.\n\n— Peter Pang Fit";
          $sent = @send_plain_email($toMail, $toName, $subject, $body);
        }
        $flash = $sent ? 'Password updated.' : 'Password updated (email notification could not be sent).';
        $flash_type = 'ok';
        ppf_log_safe($conn, $uid, $userEmail, $userRole, 'password_changed', 'self_service=1');
        if (isset($conn) && $conn instanceof mysqli) {
          ppf_notifications_record($conn, $uid, [
            'type_key' => 'security.password_changed',
            'message' => 'Your password was changed on ' . ppf_format_user_datetime(date('c'), ['fallback' => date('Y-m-d H:i:s')]),
            'send_email' => true,
          ]);
        }

      } catch (Throwable $e) {
        $flash = $e->getMessage(); $flash_type = 'err';
        ppf_log_safe($conn, $uid, $userEmail, $userRole, 'profile_error', $flash);
      }
    }

    // SAVE PROFILE FIELDS (no direct file input here)
    if ($action === 'save_profile') {
      try {
        $first_name  = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name   = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $birthdate   = safe_str_date($_POST['birthdate'] ?? null);

        $gender_in   = trim($_POST['gender'] ?? '');
        $gci_in      = mb_strtolower($gender_in);
        $gender      = ($gci_in === 'male') ? 'Male' : (($gci_in === 'female') ? 'Female' : '');

        $measurementSystem = $profileMeasurementSystem;
        $height_ft  = isset($_POST['height_ft']) ? trim($_POST['height_ft']) : '';
        $height_in  = isset($_POST['height_in']) ? trim($_POST['height_in']) : '';
        $height_cm_input = ($measurementSystem === 'metric') ? trim($_POST['height_cm'] ?? '') : '';
        $weight_input = isset($_POST['weight_lbs']) ? trim($_POST['weight_lbs']) : '';

        if ($measurementSystem === 'metric') {
          $profileHeightMetricInput = $height_cm_input;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Exception('Please enter a valid email.'); }
        if ($birthdate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) { throw new Exception('Birthdate must be YYYY-MM-DD.'); }

        // Unique email check (exclude self)
        if ($chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?")) {
          $chk->bind_param("si", $email, $uid);
          $chk->execute(); $chk->store_result();
          if ($chk->num_rows > 0) { $chk->close(); throw new Exception('That email is already in use.'); }
          $chk->close();
        }

        if ($measurementSystem === 'metric') {
          if ($height_cm_input === '') {
            $hf = null;
            $hi = null;
          } else {
            if (!is_numeric($height_cm_input)) {
              throw new Exception('Height must be numeric.');
            }
            $cmValue = (float)$height_cm_input;
            if ($cmValue < 0) {
              throw new Exception('Height cannot be negative.');
            }
            [$hf, $hi] = ppf_measurement_height_components_from_cm($cmValue);
          }
        } else {
          $hf = ($height_ft === '' ? null : (int)$height_ft);
          $hi = ($height_in === '' ? null : (int)$height_in);
        }
        $wl = ppf_measurement_parse_weight_input($weight_input);
        if ($hf !== null) { if ($hf < 0) $hf = 0; if ($hf > 8) $hf = 8; }
        if ($hi !== null) { if ($hi < 0) $hi = 0; if ($hi > 11) $hi = 11; }

        $beforeRow = [];
        if ($stmt = $conn->prepare("SELECT email, phone, birthdate, gender, first_name, middle_name, last_name, height_ft, height_in, weight_lbs FROM users WHERE id = ?")) {
          $stmt->bind_param("i", $uid);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $beforeRow = $row;
            }
          }
          $stmt->close();
        }

        $q = "UPDATE users SET email=?, phone=?, birthdate=?, gender=?, first_name=?, middle_name=?, last_name=?, height_ft=?, height_in=?, weight_lbs=? WHERE id=?";
        if (!$stmt = $conn->prepare($q)) throw new Exception('Failed to prepare update.');
        $stmt->bind_param("sssssssiddi", $email, $phone, $birthdate, $gender, $first_name, $middle_name, $last_name, $hf, $hi, $wl, $uid);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to update profile.'); }
        $stmt->close();

        $flash = 'Profile updated.'; $flash_type = 'ok';
        $afterRow = [
          'email' => $email,
          'phone' => $phone,
          'birthdate' => $birthdate,
          'gender' => $gender,
          'first_name' => $first_name,
          'middle_name' => $middle_name,
          'last_name' => $last_name,
          'height_ft' => $hf,
          'height_in' => $hi,
          'weight_lbs' => $wl,
        ];
        $changes = ppf_changed_fields($beforeRow, $afterRow);
        $details = json_encode([
          'self_service' => true,
          'changed' => $changes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ppf_log_safe($conn, $uid, $email, $userRole, 'profile_updated', $details ?: '');

        // Refresh $me
        $q = "SELECT first_name, middle_name, last_name, email, phone, birthdate, gender, height_ft, height_in, weight_lbs"
           . ($PHOTO_COL ? ", {$PHOTO_COL} AS photo_url" : "")
           . " FROM users WHERE id = ?";
        if ($stmt = $conn->prepare($q)) {
          $stmt->bind_param("i", $uid);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) $me = array_merge($me, $row);
          }
          $stmt->close();
          $graw = trim((string)($me['gender'] ?? ''));
          $gci  = mb_strtolower($graw);
          $genderNormalized = ($gci === 'male') ? 'Male' : (($gci === 'female') ? 'Female' : '');
        }
        if ($measurementSystem === 'metric') {
          $profileHeightMetricInput = ppf_measurement_height_metric_value($hf, $hi);
        }
      } catch (Throwable $e) {
        $flash = $e->getMessage(); $flash_type = 'err';
        ppf_log_safe($conn, $uid, $userEmail, $userRole, 'profile_error', $flash);
      }
    }

    // SELECT EXISTING PHOTO (no duplication)
    if ($action === 'select_photo' && $PHOTO_COL) {
      try {
        $photo_rel = trim($_POST['photo_rel'] ?? '');
        $mustPrefix = 'uploads/' . $uid . '/avatars/';
        if ($photo_rel === '' || stripos($photo_rel, $mustPrefix) !== 0) {
          throw new Exception('Invalid photo selection.');
        }
        $abs = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $photo_rel);
        if (!is_file($abs)) throw new Exception('Selected file not found.');

        $sql = "UPDATE users SET {$PHOTO_COL} = ? WHERE id = ?";
        if (!$stmt = $conn->prepare($sql)) throw new Exception('Failed to save photo path.');
        $stmt->bind_param("si", $photo_rel, $uid);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to update photo.'); }
        $stmt->close();

        $_SESSION['photo_url'] = $photo_rel;
        $_SESSION['photo_ver'] = time();

        $me['photo_url'] = $photo_rel;
        $flash = 'Profile photo updated.'; $flash_type = 'ok';
        ppf_log_safe($conn, $uid, $userEmail, $userRole, 'photo_selected_existing', "path={$photo_rel}");
      } catch (Throwable $e) {
        $flash = $e->getMessage(); $flash_type = 'err';
        ppf_log_safe($conn, $uid, $userEmail, $userRole, 'profile_error', $flash);
      }
    }

    // UPLOAD NEW PHOTO (from modal hidden input)
    if ($action === 'upload_photo' && $PHOTO_COL) {
      try {
        if (!isset($_FILES['new_photo']) || !is_uploaded_file($_FILES['new_photo']['tmp_name'])) {
          throw new Exception('No file uploaded.');
        }
        $maxBytes = 5 * 1024 * 1024;
        if (($_FILES['new_photo']['size'] ?? 0) > $maxBytes) throw new Exception('Photo must be 5MB or less.');
        $tmp  = $_FILES['new_photo']['tmp_name'];
        $info = @getimagesize($tmp);
        if (!$info) throw new Exception('Uploaded file is not a valid image.');
        $mime = $info['mime'] ?? '';
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
        if (!isset($allowed[$mime])) throw new Exception('Allowed image types: JPG, PNG, WEBP, GIF.');
        if (!extension_loaded('gd')) throw new Exception('Image processing (GD) is not available.');

        $dir = ensure_user_avatar_dir($uid);
        $w = (int)$info[0]; $h = (int)$info[1];
        $maxDim = 1024;
        $scale = ($w > $maxDim || $h > $maxDim) ? ($maxDim / max($w, $h)) : 1.0;
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        // Load source
        $src = @imagecreatefromstring(file_get_contents($tmp));
        if (!$src) throw new Exception('Could not read the uploaded image.');

        // Build normalized resized image in memory to compute a stable hash
        $dst = imagecreatetruecolor($nw, $nh);
        // preserve alpha for png/webp/gif
        if (in_array($mime, ['image/png','image/webp','image/gif'], true)) {
          imagealphablending($dst, false);
          imagesavealpha($dst, true);
          $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
          imagefilledrectangle($dst, 0, 0, $nw, $nh, $trans);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // Compute content hash from a normalized PNG buffer
        ob_start();
        imagepng($dst, null, 6);
        $normalized = (string)ob_get_clean();
        $sha = sha1($normalized);

        // If this hash already exists, reuse it
        $existing = glob($dir . $sha . '.*');
        if ($existing && isset($existing[0]) && is_file($existing[0])) {
          $abs = $existing[0];
          $rel = rel_from_abs($abs);

          // Point profile to existing file
          $sql = "UPDATE users SET {$PHOTO_COL} = ? WHERE id = ?";
          if (!$stmt = $conn->prepare($sql)) throw new Exception('Failed to save photo path.');
          $stmt->bind_param("si", $rel, $uid);
          if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to update photo.'); }
          $stmt->close();

          imagedestroy($dst);
          imagedestroy($src);

          $_SESSION['photo_url'] = $rel;
          $_SESSION['photo_ver'] = time();

          $me['photo_url'] = $rel;
          $flash = 'Profile photo updated (reused existing image).'; $flash_type = 'ok';
          ppf_log_safe($conn, $uid, $userEmail, $userRole, 'photo_uploaded_dedup_reused', "path={$rel}");
        } else {
          // Save new file with hash-based name using original format
          $ext = $allowed[$mime];
          $abs = $dir . $sha . '.' . $ext;

          // Re-encode from our resized $dst to the desired format
          $ok = false;
          if     ($mime === 'image/jpeg') { $ok = imagejpeg($dst, $abs, 86); }
          elseif ($mime === 'image/png')  { $ok = imagepng($dst, $abs, 6); }
          elseif ($mime === 'image/webp') { $ok = imagewebp($dst, $abs, 86); }
          elseif ($mime === 'image/gif')  { $ok = imagegif($dst, $abs); }

          imagedestroy($dst);
          imagedestroy($src);

          if (!$ok) throw new Exception('Failed to save processed image.');
          ppf_fix_permissions($abs, false);

          $rel = rel_from_abs($abs);

          $sql = "UPDATE users SET {$PHOTO_COL} = ? WHERE id = ?";
          if (!$stmt = $conn->prepare($sql)) throw new Exception('Failed to save photo path.');
          $stmt->bind_param("si", $rel, $uid);
          if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed to update photo.'); }
          $stmt->close();

          $_SESSION['photo_url'] = $rel;
          $_SESSION['photo_ver'] = time();

          $me['photo_url'] = $rel;
          $flash = 'Profile photo uploaded.'; $flash_type = 'ok';
          ppf_log_safe($conn, $uid, $userEmail, $userRole, 'photo_uploaded', "path={$rel}");
        }

      } catch (Throwable $e) {
        $flash = $e->getMessage(); $flash_type = 'err';
        ppf_log_safe($conn, $uid, $userEmail, $userRole, 'profile_error', $flash);
      }
    }
  }
}

// Avatar URL (fallback silhouette)
$avatarUrl = !empty($me['photo_url']) ? $me['photo_url'] : '';
$profileWeightInput = ppf_measurement_weight_value_for_input($me['weight_lbs'] ?? null);
$profileWeightLabel = ppf_measurement_weight_label();

// Include header/nav AFTER processing so session photo is fresh
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

// Build gallery list for modal (thumbnails)
$gallery = list_user_avatars($uid);
$photoVer = (string)($_SESSION['photo_ver'] ?? ''); // cache-buster
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profile · Peter Pang Fit</title>
  <style>
    
    html,body{margin:0;padding:0;background: var(--page-canvas);
    color:var(--text);
      font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
    a{color:var(--brand);text-decoration:none}
    a:hover{text-decoration:underline}
    .wrap{max-width:900px;margin:20px auto;padding:0 18px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px}
    .row{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
    .span-12{grid-column:span 12}.span-6{grid-column:span 6}.span-4{grid-column:span 4}.span-3{grid-column:span 3}
    .inline-input{background:rgba(8,13,23,0.88);border:1px solid var(--line);color:var(--text);padding:8px 10px;border-radius:8px;width:100%}
    .btn{background:rgba(30,41,59,0.65);border:1px solid var(--line);padding:8px 12px;border-radius:10px;color:var(--text);cursor:pointer}
    .btn.brand{background:var(--brand);border-color:var(--brand);color:#fff}
    .muted{color:var(--muted)}
    .flash{padding:10px 12px;border-radius:10px;border:1px solid var(--line);margin-bottom:14px}
    .flash.ok{border-left:3px solid var(--ok)}
    .flash.err{border-left:3px solid var(--warn)}

    /* Avatar with hover pencil */
    .avatar-wrap{position:relative;width:88px;height:88px;border-radius:50%;overflow:hidden;border:1px solid var(--line);background:rgba(8,13,23,0.88);color:#cbd5f5;display:flex;align-items:center;justify-content:center}
    .avatar-wrap img{display:block;width:100%;height:100%;object-fit:cover}
    .edit-badge{
      position:absolute;right:4px;bottom:4px;width:26px;height:26px;border-radius:999px;
      background:rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.2);
      display:flex;align-items:center;justify-content:center;color:#f8fafc;
      opacity:0;transition:opacity .18s ease;cursor:pointer
    }
    .avatar-wrap:hover .edit-badge{opacity:1}

    /* Modal */
    .ppf-modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:none; z-index:3000; }
    .ppf-modal{
      position:fixed; left:50%; top:50%; transform:translate(-50%,-50%);
      width:min(720px, 94vw); background:rgba(9,14,28,0.72); border:1px solid var(--line);
      border-radius:14px; padding:16px; display:none; z-index:3001;
    }
    .ppf-modal h4{margin:0 0 10px;font-size:16px}
    .ppf-modal .actions{display:flex;gap:10px;margin-top:12px;justify-content:flex-end;flex-wrap:wrap}
    .thumbs{
      display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:8px
    }
    @media (max-width:780px){ .thumbs{grid-template-columns:repeat(4,1fr);} }
    @media (max-width:520px){ .thumbs{grid-template-columns:repeat(3,1fr);} }
    .thumb{
      border:1px solid var(--line); border-radius:10px; overflow:hidden; background:rgba(8,13,23,0.95);
      cursor:pointer; position:relative; aspect-ratio:1/1; display:flex; align-items:center; justify-content:center;
    }
    .thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .thumb .mark{
      position:absolute; right:6px; top:6px; background:rgba(0,0,0,.45);
      border:1px solid rgba(255,255,255,.2); border-radius:999px; width:20px; height:20px;
      display:flex;align-items:center;justify-content:center;font-size:12px;color:#f8fafc
    }

    /* Password modal */
    .req{font-size:13px;margin:10px 0 0 0}
    .req li{margin:6px 0;list-style:none;padding-left:22px;position:relative;color:#fca5a5}
    .req li::before{content:'•';position:absolute;left:8px;top:0.2rem;opacity:.7}
    .req li.ok{color:#a7f3d0}
  </style>
</head>
<body>

<main class="wrap">
  <h1 style="margin:0 0 10px 0;">Profile</h1>
  <p class="muted" style="margin:0 0 18px 0;">Update your account details and profile photo.</p>

  <?php if ($flash): ?>
    <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>">
      <?php echo h($flash); ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3>Account</h3>
    <form method="post" class="row" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_profile">

      <!-- Avatar preview & controls -->
      <div class="span-12" style="display:flex;align-items:center;gap:14px;margin-bottom:6px">
        <div class="avatar-wrap" id="avatarWrap" title="Change profile photo">
          <?php if (!empty($avatarUrl)): ?>
            <img src="<?php echo h(($avatarUrl[0]==='/'?$avatarUrl:('/'.$avatarUrl)) . ($photoVer ? ('?v=' . $photoVer) : '')); ?>" alt="Profile Photo">
          <?php else: ?>
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:52px;height:52px;opacity:.85">
              <circle cx="12" cy="8" r="4"></circle>
              <path d="M4 20a8 8 0 0 1 16 0"></path>
            </svg>
          <?php endif; ?>
          <button type="button" class="edit-badge" id="btnOpenPhotoModal" aria-label="Change profile photo">
            <!-- pencil icon -->
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 20h9"></path>
              <path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4 12.5-12.5z"></path>
            </svg>
          </button>
        </div>
        <div>
          <div class="muted" style="margin:0 0 6px">Profile Photo</div>
          <button class="btn" type="button" id="btnOpenPhotoModalText">Change Profile Photo</button>
          <div class="muted" style="margin-top:6px">Max 5MB. JPG/PNG/WEBP/GIF.</div>
        </div>
      </div>

      <div class="span-6">
        <label for="first_name">First Name</label>
        <input class="inline-input" id="first_name" name="first_name" type="text" value="<?php echo h($me['first_name']); ?>">
      </div>

      <div class="span-6">
        <label for="middle_name">Middle Name</label>
        <input class="inline-input" id="middle_name" name="middle_name" type="text" value="<?php echo h($me['middle_name']); ?>">
      </div>

      <div class="span-6">
        <label for="last_name">Last Name</label>
        <input class="inline-input" id="last_name" name="last_name" type="text" value="<?php echo h($me['last_name']); ?>">
      </div>

      <div class="span-6">
        <label for="email">Email</label>
        <input class="inline-input" id="email" name="email" type="email" value="<?php echo h($me['email']); ?>" required>
      </div>

      <div class="span-6">
        <label for="phone">Phone</label>
        <input class="inline-input" id="phone" name="phone" type="text" value="<?php echo h($me['phone']); ?>">
      </div>

      <div class="span-3">
        <label for="birthdate">Birthdate</label>
        <input class="inline-input" id="birthdate" name="birthdate" type="date" value="<?php echo h($me['birthdate']); ?>">
      </div>

      <div class="span-3">
        <label for="gender">Gender</label>
        <select class="inline-input" id="gender" name="gender">
          <option value="" <?php echo ($genderNormalized===''?'selected':''); ?>>—</option>
          <option value="Male" <?php echo ($genderNormalized==='Male'?'selected':''); ?>>Male</option>
          <option value="Female" <?php echo ($genderNormalized==='Female'?'selected':''); ?>>Female</option>
        </select>
      </div>

      <?php if ($profileMeasurementSystem === 'metric'): ?>
        <div class="span-6">
          <label for="height_cm">Height (cm)</label>
          <input class="inline-input" id="height_cm" name="height_cm" type="number" min="0" step="0.1" value="<?php echo h($profileHeightMetricInput); ?>">
        </div>
      <?php else: ?>
        <div class="span-3">
          <label for="height_ft">Height (ft)</label>
          <input class="inline-input" id="height_ft" name="height_ft" type="number" min="0" max="8" step="1" value="<?php echo h($me['height_ft']); ?>">
        </div>

        <div class="span-3">
          <label for="height_in">Height (in)</label>
          <input class="inline-input" id="height_in" name="height_in" type="number" min="0" max="11" step="1" value="<?php echo h($me['height_in']); ?>">
        </div>
      <?php endif; ?>

      <div class="span-3">
        <label for="weight_lbs"><?php echo h($profileWeightLabel); ?></label>
        <input class="inline-input" id="weight_lbs" name="weight_lbs" type="number" min="0" step="0.1" value="<?php echo h($profileWeightInput); ?>" placeholder="<?php echo h(ppf_measurement_weight_placeholder()); ?>">
      </div>

      <div class="span-12" style="display:flex;gap:10px;margin-top:6px;flex-wrap:wrap">
        <button class="btn brand" type="submit">Save Changes</button>
        <a class="btn" href="#" onclick="window.history.back(); return false;">Cancel</a>
        <button class="btn" type="button" id="btnChangePassword">Change Password</button>
      </div>
    </form>
  </div>
</main>

<!-- Profile Photo Modal -->
<div class="ppf-modal-backdrop" id="photoBackdrop" aria-hidden="true"></div>
<div class="ppf-modal" id="photoModal" role="dialog" aria-modal="true" aria-labelledby="photoTitle">
  <h4 id="photoTitle">Change Profile Photo</h4>

  <!-- Select existing -->
  <?php if (!empty($gallery)): ?>
    <div class="muted">Choose a previous photo:</div>
    <div class="thumbs">
      <?php foreach ($gallery as $g): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="select_photo">
          <input type="hidden" name="photo_rel" value="<?php echo h($g['rel']); ?>">
          <button type="submit" class="thumb" title="Use this photo">
            <img src="<?php echo h('/' . $g['rel']); ?>" alt="Previous photo">
            <?php if (!empty($me['photo_url']) && $g['rel'] === $me['photo_url']): ?>
              <div class="mark" aria-label="Current">✓</div>
            <?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="muted">You haven't uploaded any photos yet.</div>
  <?php endif; ?>

  <!-- Upload new -->
  <form method="post" enctype="multipart/form-data" id="modalUploadForm" style="margin-top:12px">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="upload_photo">
    <input type="file" id="modalUploadInput" name="new_photo" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none">
    <div class="actions">
      <button class="btn" type="button" id="photoCancel">Close</button>
      <button class="btn brand" type="button" id="btnModalUpload">Upload Photo</button>
    </div>
  </form>
</div>

<!-- Password Change Modal -->
<div class="ppf-modal-backdrop" id="pwBackdrop" aria-hidden="true"></div>
<div class="ppf-modal" id="pwModal" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
  <h4 id="pwTitle">Change Password</h4>
  <form method="post" autocomplete="off" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="change_password">
    <div class="row" style="gap:10px">
      <div class="span-12">
        <label for="old_password">Old Password</label>
        <input class="inline-input" id="old_password" name="old_password" type="password" required>
      </div>
      <div class="span-12">
        <label for="new_password">New Password</label>
        <input class="inline-input" id="new_password" name="new_password" type="password" required>
      </div>
      <div class="span-12">
        <label for="confirm_password">Confirm Password</label>
        <input class="inline-input" id="confirm_password" name="confirm_password" type="password" required>
      </div>
    </div>

    <ul class="req" id="reqList">
      <li id="rule-match">Passwords must match</li>
      <li id="rule-length">Password must be at least 12 characters.</li>
      <li id="rule-mix">Password must include at least one capital letter, one number, and one special character.</li>
      <li id="rule-personal">Password cannot contain your name or email.</li>
    </ul>

    <div class="actions">
      <button class="btn brand" type="submit">Save</button>
      <button class="btn" type="button" id="pwCancel">Cancel</button>
    </div>
  </form>
</div>

<script>
(function(){
  // -------- Photo modal open/close ----------
  const photoModal = document.getElementById('photoModal');
  const photoBack  = document.getElementById('photoBackdrop');
  const openBtns   = [document.getElementById('btnOpenPhotoModal'), document.getElementById('btnOpenPhotoModalText'), document.getElementById('avatarWrap')];
  const closeBtn   = document.getElementById('photoCancel');

  function openPhotoM(){
    photoModal.style.display = 'block';
    photoBack.style.display  = 'block';
    document.body.style.overflow = 'hidden';
  }
  function closePhotoM(){
    photoModal.style.display = 'none';
    photoBack.style.display  = 'none';
    document.body.style.overflow = '';
  }
  openBtns.forEach(b => b?.addEventListener('click', openPhotoM));
  photoBack?.addEventListener('click', closePhotoM);
  closeBtn?.addEventListener('click', closePhotoM);
  window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && photoModal.style.display === 'block') closePhotoM(); });

  // -------- Upload from modal (hidden file input) ----------
  const upBtn   = document.getElementById('btnModalUpload');
  const upInput = document.getElementById('modalUploadInput');
  const upForm  = document.getElementById('modalUploadForm');
  upBtn?.addEventListener('click', ()=> upInput?.click());
  upInput?.addEventListener('change', ()=> {
    if (upInput.files && upInput.files.length) {
      upForm.submit();
    }
  });

  // -------- Password modal behavior ----------
  const openPwBtn = document.getElementById('btnChangePassword');
  const pwModal   = document.getElementById('pwModal');
  const pwBack    = document.getElementById('pwBackdrop');
  const pwCancel  = document.getElementById('pwCancel');

  function openPwM(){
    pwModal.style.display = 'block';
    pwBack.style.display  = 'block';
    document.body.style.overflow = 'hidden';
    document.getElementById('old_password')?.focus();
  }
  function closePwM(){
    pwModal.style.display = 'none';
    pwBack.style.display  = 'none';
    document.body.style.overflow = '';
    ['old_password','new_password','confirm_password'].forEach(id => {
      const el = document.getElementById(id); if (el) el.value = '';
    });
    document.querySelectorAll('#reqList li').forEach(li => li.classList.remove('ok'));
  }
  openPwBtn?.addEventListener('click', openPwM);
  pwBack?.addEventListener('click', closePwM);
  pwCancel?.addEventListener('click', closePwM);
  window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && pwModal.style.display === 'block') closePwM(); });

  // -------- Live password rules ----------
  const np = document.getElementById('new_password');
  const cp = document.getElementById('confirm_password');
  const ruleMatch   = document.getElementById('rule-match');
  const ruleLength  = document.getElementById('rule-length');
  const ruleMix     = document.getElementById('rule-mix');
  const rulePersonal= document.getElementById('rule-personal');

  const userEmail = <?php echo json_encode(mb_strtolower($me['email'] ?? '')); ?>;
  const userFirst = <?php echo json_encode(mb_strtolower($me['first_name'] ?? '')); ?>;
  const userLast  = <?php echo json_encode(mb_strtolower($me['last_name'] ?? '')); ?>;

  function buildFragments() {
    const out = new Set();
    const addToken = (tok) => {
      const t = (tok || '').toLowerCase().replace(/[^a-z0-9]/g,'');
      if (t.length < 3) return;
      for (let i=0; i<=t.length-3; i++){
        for (let len=3; len<=t.length-i; len++){
          const frag = t.substring(i, i+len);
          if (frag.length > 16) break;
          out.add(frag);
        }
      }
    };
    if (userEmail) userEmail.split(/[^a-z0-9]+/i).forEach(addToken);
    if (userFirst) userFirst.split(/[^a-z0-9]+/i).forEach(addToken);
    if (userLast)  userLast.split(/[^a-z0-9]+/i).forEach(addToken);
    return out;
  }
  const personalFragments = buildFragments();
  const toggle = (el, ok) => { if (!el) return; el.classList.toggle('ok', !!ok); };

  function meetsRules() {
    const a = (np?.value || '').toLowerCase();
    const b = (cp?.value || '').toLowerCase();
    toggle(ruleMatch, a !== '' && a === b);
    toggle(ruleLength, a.length >= 12);
    const raw = np?.value || '';
    toggle(ruleMix, /[A-Z]/.test(raw) && /\d/.test(raw) && /[^A-Za-z0-9]/.test(raw));
    let personalOK = true;
    for (const frag of personalFragments) { if (frag && a.includes(frag)) { personalOK = false; break; } }
    toggle(rulePersonal, personalOK);
  }
  np?.addEventListener('input', meetsRules);
  cp?.addEventListener('input', meetsRules);
})();
</script>
</body>
</html>