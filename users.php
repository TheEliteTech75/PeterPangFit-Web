<?php
// users.php — Admin-only: create users (no invite), per-row edit (click Edit), change roles,
// toggle "also acts as client", delete users, and show Created / Last Login / IP address.
//
// Table expected columns (no username):
//   users(id, role, is_client, email, password_hash, phone, birthdate, gender,
//         first_name, middle_name, last_name, created_at, last_login, ip_address)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logs.php';

function is_admin($role){ return ($role ?? 'guest') === 'admin'; }
if (!is_admin($USER_ROLE ?? null)) { http_response_code(403); echo 'Forbidden'; exit; }

require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function generate_temp_password($length = 12){
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
  $out = '';
  for ($i=0; $i<$length; $i++) { $out .= $alphabet[random_int(0, strlen($alphabet)-1)]; }
  return $out;
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

// Display helpers
function fmt_date_us($iso){
  if (!$iso) return '';
  $ts = strtotime($iso);
  if ($ts === false) return h($iso);
  return date('m/d/Y', $ts);
}
function fmt_phone_us($raw){
  if (!$raw) return '';
  $digits = preg_replace('/\D+/', '', (string)$raw);
  if (strlen($digits) === 10) {
    return sprintf('(%s) %s-%s', substr($digits,0,3), substr($digits,3,3), substr($digits,6,4));
  }
  if (strlen($digits) === 11 && $digits[0] === '1') {
    return sprintf('(%s) %s-%s', substr($digits,1,3), substr($digits,4,3), substr($digits,7,4));
  }
  return h($raw);
}
function fmt_gender_cap($g){
  if ($g === null || $g === '') return '';
  $g = trim((string)$g);
  return h(mb_strtoupper(mb_substr($g,0,1)) . mb_substr($g,1));
}
function calc_age($dob){
  if (!$dob) return '';
  $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
  if (!$dobObj) return '';
  $today = new DateTime('today');
  $age = $dobObj->diff($today)->y;
  return $age;
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$flash = null; $flash_type = 'ok'; $flash_extra = null;

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $flash = 'Invalid session token. Please try again.'; $flash_type = 'err';
  } else {
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    try {
      // Create user (no invite flow)
      if ($action === 'create_user') {
        $role       = trim($_POST['role'] ?? '');
        $is_client  = isset($_POST['is_client']) ? 1 : 0;
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $birthdate  = trim($_POST['birthdate'] ?? '');
        $gender     = trim($_POST['gender'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name= trim($_POST['middle_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $password   = (string)($_POST['password'] ?? '');
        $password2  = (string)($_POST['password2'] ?? '');

        $allowed_roles = ['admin','trainer','client'];
        if (!in_array($role, $allowed_roles, true)) throw new Exception('Please select a valid role.');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('A valid email is required.');
        if ($password !== '' && $password !== $password2) throw new Exception('Passwords do not match.');
        if ($birthdate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) throw new Exception('Birthdate must be YYYY-MM-DD.');

        // Unique email check
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param("s", $email);
        $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) { $chk->close(); throw new Exception('That email is already in use.'); }
        $chk->close();

        $temp_generated = false;
        if ($password === '') { $password = generate_temp_password(12); $temp_generated = true; }
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
          INSERT INTO users
            (role, is_client, email, password_hash, phone, birthdate, gender, first_name, middle_name, last_name)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception('Failed to prepare insert.');

        $bdate = ($birthdate ?: null); $gend = ($gender ?: null);
        $fn = ($first_name ?: null); $mn = ($middle_name ?: null); $ln = ($last_name ?: null);
        $stmt->bind_param("sissssssss", $role, $is_client, $email, $hash, $phone, $bdate, $gend, $fn, $mn, $ln);
        if (!$stmt->execute()) throw new Exception('Failed to create user.');
        $stmt->close();

        $flash = 'User created successfully.'; $flash_type = 'ok';
        if ($temp_generated) { $flash_extra = 'Temporary password: ' . $password; }
      }

      // Inline Save (when Edit mode is active)
      if ($action === 'update_user') {
        if ($user_id <= 0) throw new Exception('Invalid user to update.');
        $beforeRow = [];
        if ($stmt = $conn->prepare("SELECT email, phone, birthdate, gender, first_name, middle_name, last_name FROM users WHERE id = ?")) {
          $stmt->bind_param("i", $user_id);
          $stmt->execute();
          if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
              $beforeRow = $row;
            }
          }
          $stmt->close();
        }
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $birthdate  = trim($_POST['birthdate'] ?? '');
        $gender     = trim($_POST['gender'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name= trim($_POST['middle_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('A valid email is required.');
        if ($birthdate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) throw new Exception('Birthdate must be YYYY-MM-DD.');

        // Unique email check (exclude this user)
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); throw new Exception('That email is already in use by another user.'); }
        $stmt->close();

        $stmt = $conn->prepare("
          UPDATE users
          SET email = ?, phone = ?, birthdate = ?, gender = ?, first_name = ?, middle_name = ?, last_name = ?
          WHERE id = ?
        ");
        if (!$stmt) throw new Exception('Failed to prepare update.');
        $bdate = ($birthdate ?: null); $gend = ($gender ?: null);
        $fn = ($first_name ?: null); $mn = ($middle_name ?: null); $ln = ($last_name ?: null);
        $stmt->bind_param("sssssssi", $email, $phone, $bdate, $gend, $fn, $mn, $ln, $user_id);
        if (!$stmt->execute()) throw new Exception('Failed to update user.');
        $stmt->close();

        $afterRow = [
          'email' => $email,
          'phone' => $phone,
          'birthdate' => $bdate,
          'gender' => $gend,
          'first_name' => $fn,
          'middle_name' => $mn,
          'last_name' => $ln,
        ];
        $changes = ppf_changed_fields($beforeRow, $afterRow);
        $details = json_encode([
          'target_user_id' => $user_id,
          'changed' => $changes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (function_exists('ppf_log')) {
          @ppf_log($conn, null, null, null, 'admin_user_profile_updated', 'user', (string)$user_id, $details ?: '');
        }

        header('Location: users.php?updated=1'); // PRG: collapse edit mode
        exit;
      }

      // Change role
      if ($action === 'change_role') {
        $new_role = trim($_POST['new_role'] ?? '');
        $allowed  = ['admin','trainer','client'];
        if ($user_id <= 0 || !in_array($new_role, $allowed, true)) throw new Exception('Invalid role change request.');
        if ($user_id === (int)($USER_ID ?? 0) && $new_role !== 'admin') throw new Exception('You cannot change your own role from Admin here.');

        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $new_role, $user_id);
        if (!$stmt->execute()) throw new Exception('Failed to update role.');
        $stmt->close();
        $flash = 'Role updated.'; $flash_type = 'ok';
      }

      // Toggle "also acts as client"
      if ($action === 'toggle_is_client') {
        if ($user_id <= 0) throw new Exception('Invalid user.');
        $to = (int)($_POST['to'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET is_client = ? WHERE id = ?");
        $stmt->bind_param("ii", $to, $user_id);
        if (!$stmt->execute()) throw new Exception('Failed to update client flag.');
        $stmt->close();
        $flash = $to ? 'User will now appear in client lists.' : 'User removed from client lists.'; 
        $flash_type = 'ok';
      }

      // NEW: Change Password (admin only; already admin-gated by page)
      if ($action === 'change_password') {
        if ($user_id <= 0) throw new Exception('Invalid user to change password.');
        $np = (string)($_POST['new_password'] ?? '');
        $cp = (string)($_POST['confirm_password'] ?? '');
        if ($np === '' || $cp === '') throw new Exception('Please enter and confirm the new password.');
        if ($np !== $cp) throw new Exception('New password and confirmation do not match.');
        // (Optional) You can add strength checks here if you want; not enforcing beyond match per instructions.
        $hash = password_hash($np, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        if (!$stmt) throw new Exception('Failed to prepare password update.');
        $stmt->bind_param("si", $hash, $user_id);
        if (!$stmt->execute()) throw new Exception('Failed to change password.');
        $stmt->close();

        $flash = 'Password changed successfully.'; $flash_type = 'ok';
      }

      // Delete user
      if ($action === 'delete_user') {
        if ($user_id <= 0) throw new Exception('Invalid user to delete.');
        if ($user_id === (int)($USER_ID ?? 0)) throw new Exception('You cannot delete your own account.');
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) throw new Exception('Failed to delete user.');
        $stmt->close();
        $flash = 'User deleted.'; $flash_type = 'ok';
      }

    } catch (Throwable $e) {
      $flash = $e->getMessage(); $flash_type = 'err';
    }
  }
}

// ---------- Load users (with created_at, last_login, ip_address) ----------
$users = [];
$q = "SELECT id, role, is_client, email, phone, birthdate, gender, first_name, middle_name, last_name, created_at, last_login, ip_address
      FROM users
      ORDER BY last_name, first_name, id";
$res = $conn->query($q);
if ($res) { while ($row = $res->fetch_assoc()) { $users[] = $row; } }

$who = $USER_NAME ?? trim(($USER_FIRST_NAME ?? '') . ' ' . ($USER_LAST_NAME ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>All Users · Peter Pang Fit</title>
  <style>
    :root{
    color-scheme:dark;
      --bg:#05070d; --bg-alt:#03040a; --panel:rgba(9,14,28,0.92); --text:#f8fafc; --muted:#cbd5f5; --brand:#38bdf8;
      --line:rgba(148,163,184,0.18); --chip:rgba(15,23,42,0.7); --ok:#10b981; --warn:#ef4444;
      --page-pad: clamp(14px, 3vw, 28px);
      --maxw: 1600px;
    }
    html,body{margin:0;padding:0;background:
      radial-gradient(circle at top left, rgba(56,189,248,0.18), transparent 55%),
      radial-gradient(circle at bottom right, rgba(110,231,183,0.12), transparent 60%),
      linear-gradient(155deg, var(--bg), var(--bg-alt));
    color:var(--text);
      font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
    a{color:var(--brand);text-decoration:none}
    a:hover{text-decoration:underline}

    .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
      color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer;text-decoration:none;white-space:nowrap}
    .btn:hover{filter:brightness(1.06)}
    .btn.brand{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
    .btn.warn{background:#2a1617;border-color:rgba(248,113,113,0.45);color:#f87171}
    .btn.small{padding:6px 10px;font-size:13px}

    .wrap{
      width:100%;
      max-width:100%;
      margin:24px auto;
      padding:0 clamp(14px, 3vw, 28px);
      box-sizing:border-box;
    }

    .card{background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:18px}
    .card h2{margin:0 0 10px 0;font-size:16px}

    table{
      width:100%;
      border-collapse:collapse;
      background:var(--panel);
      border-radius:14px;
      overflow:hidden;
      border:1px solid var(--line);
      table-layout:auto;
    }
    th,td{padding:14px 16px;border-bottom:1px solid var(--line);vertical-align:top}
    th{background:rgba(8,13,23,0.95);text-align:left;color:#c3c9d4;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
    tr:last-child td{border-bottom:none}
    .muted{color:var(--muted)}
    .flash{margin:16px 0 0 0;padding:12px;border-radius:10px;border:1px solid; background:rgba(8,13,23,0.85)}
    .flash.ok{border-color:rgba(34,197,94,0.45);color:#a7f3d0}
    .flash.err{border-color:#4a2020;color:#fca5a5}
    .inline-input{width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#f8fafc;
      padding:6px 8px;border-radius:8px;outline:none;box-sizing:border-box;font-size:13px}

    .actions{display:flex;gap:8px;flex-wrap:wrap}

    /* Role cell layout */
    .role-form{display:flex;gap:10px;align-items:center;min-width:100px}
    .role-select{min-width:100px}
    .role-update{display:none}

    /* MODAL styles for Change Password */
    .modal-backdrop{
      position:fixed;inset:0;background:rgba(0,0,0,.5);
      display:none;align-items:center;justify-content:center;z-index:1000;
    }
    .modal{
      width:min(520px, calc(100vw - 32px));
      background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:16px;
      box-shadow:0 20px 60px rgba(0,0,0,.6);
    }
    .modal h3{margin:0 0 10px 0;font-size:16px}
    .modal .row{display:grid;grid-template-columns:1fr;gap:10px}
    .modal .btns{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}

    /* Keep long values on one line; wrap on small */
    td span, td{white-space:nowrap}
    @media (max-width: 920px){
      td span, td{white-space:normal}
      .role-form{flex-direction:column;align-items:flex-start}
      .role-select{width:100%}
    }
  </style>
</head>
<body>

<main class="wrap">
  <h1 style="margin:0 0 10px 0;">All Users</h1>
  <p class="muted" style="margin:0 0 18px 0;">Admins can add, edit, change roles, toggle “also acts as client,” delete users, or change passwords.</p>

  <?php if ($flash): ?>
    <div class="flash <?php echo $flash_type === 'ok' ? 'ok' : 'err'; ?>">
      <?php echo h($flash); ?>
      <?php if ($flash_extra): ?><div style="margin-top:6px"><?php echo h($flash_extra); ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Create User (no invite) -->
  <section class="card">
    <h2>Create User (Direct)</h2>
    <form method="post" class="row" autocomplete="off" style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="create_user">

      <div style="grid-column:span 4">
        <label class="muted" for="role">Role</label>
        <select id="role" name="role" class="inline-input" required>
          <option value="trainer">Trainer</option>
          <option value="client">Client</option>
          <option value="admin">Admin</option>
        </select>
        <label style="display:flex;gap:8px;align-items:center;margin-top:10px">
          <input type="checkbox" name="is_client" value="1" style="accent-color:#38bdf8">
          <span class="muted">Also acts as client (appears in client lists)</span>
        </label>
      </div>

      <div style="grid-column:span 4">
        <label class="muted" for="email">Email</label>
        <input class="inline-input" id="email" name="email" type="email" placeholder="user@example.com" required>
      </div>

      <div style="grid-column:span 4">
        <label class="muted" for="phone">Phone</label>
        <input class="inline-input" id="phone" name="phone" type="text" placeholder="+1 (555) 555-5555">
      </div>

      <div style="grid-column:span 4">
        <label class="muted" for="birthdate">Birthdate</label>
        <input class="inline-input" id="birthdate" name="birthdate" type="date" placeholder="YYYY-MM-DD">
      </div>

      <div style="grid-column:span 4">
        <label class="muted" for="gender">Gender</label>
        <input class="inline-input" id="gender" name="gender" type="text" placeholder="Gender">
      </div>
      <div style="grid-column:span 4"></div>

      <div style="grid-column:span 4">
        <label class="muted" for="first_name">First Name</label>
        <input class="inline-input" id="first_name" name="first_name" type="text">
      </div>

      <div style="grid-column:span 4">
        <label class="muted" for="middle_name">Middle Name</label>
        <input class="inline-input" id="middle_name" name="middle_name" type="text">
      </div>

      <div style="grid-column:span 4">
        <label class="muted" for="last_name">Last Name</label>
        <input class="inline-input" id="last_name" name="last_name" type="text">
      </div>

      <div style="grid-column:span 6">
        <label class="muted" for="password">Password</label>
        <input class="inline-input" id="password" name="password" type="password" placeholder="Leave blank to auto-generate">
      </div>

      <div style="grid-column:span 6">
        <label class="muted" for="password2">Confirm Password</label>
        <input class="inline-input" id="password2" name="password2" type="password" placeholder="Retype password">
      </div>

      <div style="grid-column:span 12;display:flex;gap:10px;align-items:center;margin-top:4px">
        <button class="btn brand" type="submit">Create User</button>
      </div>
    </form>
  </section>

  <!-- Users table -->
  <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Role</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Birthdate</th>
          <th>Age</th>
          <th>Gender</th>
          <th>First</th>
          <th>Middle</th>
          <th>Last</th>
          <th>Created</th>
          <th>Last Login</th>
          <th>IP Address</th>
          <th>Client Flag</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="15" class="muted" style="text-align:center;padding:24px">No users found.</td></tr>
      <?php else: foreach ($users as $u):
        $uid  = (int)$u['id'];
        $full = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $flag = (int)($u['is_client'] ?? 0);
        $editing = ($edit_id === $uid);
      ?>
        <tr>
          <td><?php echo $uid; ?></td>

          <!-- Role change -->
          <td>
            <form method="post" class="role-form">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
              <?php $roles = ['admin'=>'Admin','trainer'=>'Trainer','client'=>'Client']; ?>
              <select name="new_role" class="inline-input role-select" required
                      data-current="<?php echo h($u['role']); ?>">
                <?php foreach ($roles as $val => $label): ?>
                  <option value="<?php echo $val; ?>" <?php echo ($u['role']===$val ? 'selected' : ''); ?>>
                    <?php echo $label; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn small brand role-update" type="submit" title="Update role">Update</button>
            </form>
          </td>

          <!-- Email -->
          <td>
            <?php if ($editing): ?>
              <form method="post" id="form-<?php echo $uid; ?>">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="update_user">
              <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
              <input class="inline-input" type="email" name="email" value="<?php echo h($u['email']); ?>" required>
            <?php else: ?>
              <span><?php echo h($u['email']); ?></span>
            <?php endif; ?>
          </td>

          <!-- Phone -->
          <td>
            <?php if ($editing): ?>
              <input class="inline-input" type="text" name="phone" value="<?php echo h($u['phone']); ?>">
            <?php else: ?>
              <span><?php echo fmt_phone_us($u['phone']); ?></span>
            <?php endif; ?>
          </td>

          <!-- Birthdate -->
          <td>
            <?php if ($editing): ?>
              <input class="inline-input" type="date" name="birthdate" value="<?php echo h($u['birthdate']); ?>">
            <?php else: ?>
              <span><?php echo fmt_date_us($u['birthdate']); ?></span>
            <?php endif; ?>
          </td>

          <!-- Age -->
          <td>
            <?php if (!$editing): ?>
              <span><?php echo calc_age($u['birthdate']); ?></span>
            <?php endif; ?>
          </td>

          <!-- Gender -->
          <td>
            <?php if ($editing): ?>
              <input class="inline-input" type="text" name="gender" value="<?php echo h($u['gender']); ?>">
            <?php else: ?>
              <span><?php echo fmt_gender_cap($u['gender']); ?></span>
            <?php endif; ?>
          </td>

          <td><?php if ($editing): ?><input class="inline-input" type="text" name="first_name" value="<?php echo h($u['first_name']); ?>"><?php else: echo h($u['first_name']); endif; ?></td>
          <td><?php if ($editing): ?><input class="inline-input" type="text" name="middle_name" value="<?php echo h($u['middle_name']); ?>"><?php else: echo h($u['middle_name']); endif; ?></td>
          <td><?php if ($editing): ?><input class="inline-input" type="text" name="last_name" value="<?php echo h($u['last_name']); ?>"><?php else: echo h($u['last_name']); endif; ?></td>

          <!-- Created / Last Login / IP -->
          <td><?php echo $u['created_at'] ? date('M j, Y g:i A', strtotime($u['created_at'])) : ''; ?></td>
          <td><?php echo $u['last_login'] ? date('M j, Y g:i A', strtotime($u['last_login'])) : ''; ?></td>
          <td><?php echo h($u['ip_address']); ?></td>

          <!-- Client flag + Actions -->
          <td><?php echo $flag ? 'Yes' : 'No'; ?></td>
          <td>
            <div class="actions">
              <?php if ($editing): ?>
                <button class="btn small brand" type="submit" form="form-<?php echo $uid; ?>">Save</button>
                </form>
                <a class="btn small" href="users.php">Cancel</a>
              <?php else: ?>
                <a class="btn small" href="users.php?edit=<?php echo $uid; ?>">Edit</a>
              <?php endif; ?>

              <!-- Toggle "acts as client" -->
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="toggle_is_client">
                <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                <input type="hidden" name="to" value="<?php echo $flag ? 0 : 1; ?>">
                <button class="btn small" type="submit">
                  <?php echo $flag ? 'Remove from Clients' : 'Add to Clients'; ?>
                </button>
              </form>

              <!-- NEW: Change Password (opens modal) -->
              <button class="btn small" type="button"
                      onclick="openPwModal(<?php echo $uid; ?>, '<?php echo h($u['email']); ?>')">
                Change Password
              </button>

              <!-- Delete -->
              <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to delete <?php echo h($full ?: ($u['email'] ?? 'this user')); ?>?');">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                <button class="btn small warn" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn" href="dashboard.php">Back to Dashboard</a>
    <a class="btn" href="clients.php">View Clients</a>
    <a class="btn" href="invites.php">Manage Invites</a>
    <a class="btn" href="workout_plans.php">Workout Plans</a>
  </div>
</main>

<!-- NEW: Change Password Modal (single reusable instance) -->
<div id="pwModal" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="pwModalTitle">
    <h3 id="pwModalTitle">Change Password</h3>
    <div class="muted" id="pwModalUser" style="margin-bottom:8px"></div>
    <form method="post" id="pwForm" class="row" autocomplete="off" onsubmit="return validatePwForm()">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="pw_user_id" value="">
      <label class="muted" for="npw" style="margin-top:6px">New Password</label>
      <input class="inline-input" id="npw" name="new_password" type="password" required>
      <label class="muted" for="cpw">Confirm Password</label>
      <input class="inline-input" id="cpw" name="confirm_password" type="password" required>
      <div class="btns">
        <button type="button" class="btn" onclick="closePwModal()">Cancel</button>
        <button type="submit" class="btn brand">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
// Role Update button visibility logic
(function(){
  const rows = document.querySelectorAll('.role-form');
  rows.forEach(form => {
    const select = form.querySelector('.role-select');
    const btn = form.querySelector('.role-update');
    if (!select || !btn) return;
    const current = select.getAttribute('data-current') || '';
    btn.style.display = (select.value !== current) ? 'inline-flex' : 'none';
    select.addEventListener('change', () => {
      btn.style.display = (select.value !== current) ? 'inline-flex' : 'none';
    });
  });
})();

// ------- Change Password modal logic -------
const modal = document.getElementById('pwModal');
const userSpan = document.getElementById('pwModalUser');
const userInput = document.getElementById('pw_user_id');
const npw = document.getElementById('npw');
const cpw = document.getElementById('cpw');

function openPwModal(userId, email){
  userInput.value = userId;
  userSpan.textContent = email ? ('User: ' + email) : ('User ID: ' + userId);
  npw.value = '';
  cpw.value = '';
  modal.style.display = 'flex';
  setTimeout(()=>npw.focus(), 0);
}
function closePwModal(){
  modal.style.display = 'none';
  npw.value = '';
  cpw.value = '';
}
function validatePwForm(){
  if (npw.value === '' || cpw.value === '') { alert('Please fill out both password fields.'); return false; }
  if (npw.value !== cpw.value) { alert('Passwords do not match.'); return false; }
  // Optional: enforce min length here if desired
  return true;
}
// Close on Esc or clicking backdrop
modal.addEventListener('click', (e)=>{ if (e.target === modal) closePwModal(); });
document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && modal.style.display === 'flex') closePwModal(); });
</script>

</body>
</html>