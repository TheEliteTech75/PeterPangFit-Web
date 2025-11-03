<?php
// invites.php — Trainers/Admins manage invites (no client name or ID shown)
//
// Requires:
//   auth.php  -> defines $USER_ID, $USER_ROLE, $USER_NAME
//   db.php    -> defines $conn (mysqli)
// Schema assumed for `invites`:
//   id (PK), user_id (nullable), email (varchar 255), token (varchar 255),
//   created_at (datetime default now), expires_at (datetime), cancelled_at (datetime null),
//   created_by (int null)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/invite_helpers.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';
require_once __DIR__ . '/ppf_subheader.php';

ensure_invite_columns($conn);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_dt($s) {
    if (!$s) return '—';
    try {
        return (new DateTime($s))->format('M j, Y g:i A'); 
        // Example: Dec 25, 2005 3:46 PM
    } catch (Throwable $e) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
// Gate: trainers & admins only
$roleKey = ppf_role_key($USER_ROLE ?? 'guest');
if (!in_array($roleKey, ['trainer', 'trainer_admin'], true) && !ppf_is_admin_role($USER_ROLE ?? null)) {
    require_once __DIR__ . '/access_denied.php';
    exit;
}

$flash = null;

function ppf_determine_invite_type(array $row): string {
    $roleKey = ppf_role_key($row['user_role'] ?? '');
    if ($roleKey === 'trainer' || $roleKey === 'trainer_admin') {
        return 'trainer';
    }
    if ($roleKey === 'client') {
        return 'client';
    }

    $createdTs = !empty($row['created_at']) ? strtotime((string)$row['created_at']) : false;
    $expiresTs = !empty($row['expires_at']) ? strtotime((string)$row['expires_at']) : false;
    if ($createdTs && $expiresTs) {
        $hours = ($expiresTs - $createdTs) / 3600;
        if ($hours >= 36) {
            return 'trainer';
        }
    }

    return 'client';
}

// Handle cancel action (uses hidden ID; not displayed)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        $invite_id = (int)($_POST['invite_id'] ?? 0);
        if ($invite_id > 0) {
            $inviteRow = null;
            if ($fetch = $conn->prepare('SELECT id, user_id, email, expires_at, cancelled_at, created_at, COALESCE(used,0) AS used FROM invites WHERE id = ? LIMIT 1')) {
                $fetch->bind_param('i', $invite_id);
                if ($fetch->execute()) {
                    $res = $fetch->get_result();
                    if ($res && $res->num_rows === 1) {
                        $inviteRow = $res->fetch_assoc();
                    }
                }
                $fetch->close();
            }

            if ($stmt = $conn->prepare("UPDATE invites SET cancelled_at = NOW() WHERE id = ? AND cancelled_at IS NULL")) {
                $stmt->bind_param("i", $invite_id);
                if ($stmt->execute()) {
                    $flash = $stmt->affected_rows > 0
                        ? "Invite was cancelled."
                        : "No change — invite may already be cancelled.";
                    if ($stmt->affected_rows > 0 && $inviteRow) {
                        $inviteRow['status'] = 'Cancelled';
                        ppf_cleanup_invite_user_record($conn, $inviteRow);
                    }
                } else {
                    $flash = "Database error while cancelling invite.";
                }
                $stmt->close();
            } else {
                $flash = "Database error preparing cancel statement.";
            }
        } else {
            $flash = "Invalid invite reference.";
        }
    } elseif ($action === 'resend') {
        $invite_id = (int)($_POST['invite_id'] ?? 0);
        if ($invite_id <= 0) {
            $flash = 'Invalid invite reference.';
        } else {
            $row = null;
            $sql = "SELECT i.id, i.email, i.created_at, i.expires_at, i.cancelled_at, COALESCE(i.used,0) AS used, i.user_id, u.role AS user_role"
                 . " FROM invites i"
                 . " LEFT JOIN users u ON u.id = i.user_id"
                 . " WHERE i.id = ?"
                 . " LIMIT 1";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('i', $invite_id);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows === 1) {
                        $row = $res->fetch_assoc();
                    }
                }
                $stmt->close();
            }

            if (!$row) {
                $flash = 'Invite not found.';
            } else {
                $cancelled = !empty($row['cancelled_at']);
                $expired = false;
                if (!empty($row['expires_at'])) {
                    $expiresTs = strtotime((string)$row['expires_at']);
                    $expired = $expiresTs !== false && $expiresTs < time();
                }
                $used = ((int)($row['used'] ?? 0) === 1);

                if (!$cancelled && !$expired) {
                    $flash = 'Only expired or cancelled invites can be resent.';
                } elseif ($used) {
                    $flash = 'This invite has already been used.';
                } else {
                    $type = ppf_determine_invite_type($row);
                    $email = trim((string)($row['email'] ?? ''));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $flash = 'Invite email address is invalid.';
                    } else {
                        $token = bin2hex(random_bytes(32));
                        $expiresAt = ($type === 'trainer')
                            ? date('Y-m-d H:i:s', time() + 48 * 3600)
                            : date('Y-m-d H:i:s', time() + 24 * 3600);

                        $conn->begin_transaction();
                        try {
                            $userId = null;
                            if ($lookup = $conn->prepare('SELECT id, role, password_hash FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1')) {
                                $lookup->bind_param('s', $email);
                                $lookup->execute();
                                $res = $lookup->get_result();
                                if ($res && ($u = $res->fetch_assoc())) {
                                    if (!empty($u['password_hash'])) {
                                        throw new Exception('This user already has an active account.');
                                    }
                                    $userId = (int)$u['id'];
                                    $currentRole = ppf_role_key($u['role'] ?? '');
                                    $desiredRole = ($type === 'trainer') ? 'trainer' : 'client';
                                    if ($currentRole !== $desiredRole) {
                                        $roleValue = ($type === 'trainer') ? 'trainer' : 'client';
                                        if ($updateRole = $conn->prepare('UPDATE users SET role = ? WHERE id = ?')) {
                                            $updateRole->bind_param('si', $roleValue, $userId);
                                            $updateRole->execute();
                                            $updateRole->close();
                                        }
                                    }
                                }
                                $lookup->close();
                            }

                            if (!$userId) {
                                if ($type === 'trainer') {
                                    $sqlInsert = 'INSERT INTO users (email, role, is_client, is_active, created_at) VALUES (?, "trainer", 0, 1, NOW())';
                                    if (!$ins = $conn->prepare($sqlInsert)) {
                                        throw new Exception('Failed to prepare trainer account.');
                                    }
                                    $ins->bind_param('s', $email);
                                } else {
                                    $sqlInsert = 'INSERT INTO users (email, role, created_at) VALUES (?, "client", NOW())';
                                    if (!$ins = $conn->prepare($sqlInsert)) {
                                        throw new Exception('Failed to prepare client account.');
                                    }
                                    $ins->bind_param('s', $email);
                                }

                                if (!$ins->execute()) {
                                    $ins->close();
                                    throw new Exception('Failed to create user account for invite.');
                                }
                                $userId = $ins->insert_id;
                                $ins->close();
                            }

                            $creator = (int)($USER_ID ?? 0);
                            $sqlInsertInvite = 'INSERT INTO invites (user_id, email, token, expires_at, cancelled_at, used, created_by, created_at) VALUES (?, ?, ?, ?, NULL, 0, ?, NOW())';
                            if (!$newInvite = $conn->prepare($sqlInsertInvite)) {
                                throw new Exception('Failed to prepare invite insert.');
                            }
                            $newInvite->bind_param('isssi', $userId, $email, $token, $expiresAt, $creator);
                            if (!$newInvite->execute()) {
                                $newInvite->close();
                                throw new Exception('Failed to create invite.');
                            }
                            $newInvite->close();

                            $conn->commit();

                            $subject = ($type === 'trainer')
                                ? "You're invited to join Peter Pang Fit as a Trainer"
                                : "You're invited to join Peter Pang Fit";
                            $link = 'https://peterpang.pwncore.net/register.php?token=' . urlencode($token);
                            if ($type === 'trainer') {
                                $body = "Hello,\n\n"
                                  . "You have been invited to register as a trainer. This link expires in 48 hours.\n\n"
                                  . $link . "\n\n"
                                  . "If it expires, please ask an administrator for a new invite.\n\n— Peter Pang Fit";
                            } else {
                                $body = "Hi,\n\n"
                                  . "You’ve been invited to complete your registration. This link expires in 24 hours.\n\n"
                                  . $link . "\n\n"
                                  . "If it expires, your trainer can send a new one.\n\n— Peter Pang Fit";
                            }

                            if (!send_plain_email($email, $email, $subject, $body)) {
                                $flash = 'Invite was created, but email sending failed.';
                            } else {
                                $flash = 'Invite resent to ' . $email . '. Expires ' . $expiresAt . '.';
                            }
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $flash = 'Failed to resend invite: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Load invites; do not expose IDs in UI
$invites = [];
$sql = "
  SELECT i.id, i.email, i.token, i.created_at, i.accepted_at, i.completed_at,
         i.expires_at, i.cancelled_at, i.created_by, COALESCE(i.used,0) AS used,
         i.user_id, u.first_name AS user_first_name, u.last_name AS user_last_name,
         u.role AS user_role
  FROM invites i
  LEFT JOIN users u ON u.id = i.user_id
  ORDER BY i.created_at DESC
";
if ($res = $conn->query($sql)) {
    $now = new DateTimeImmutable('now');

    while ($row = $res->fetch_assoc()) {
        $cancelled = !empty($row['cancelled_at']);
        $expiresAt = !empty($row['expires_at']) ? new DateTimeImmutable($row['expires_at']) : null;
        $accepted  = !empty($row['accepted_at']);
        $registered= ((int)($row['used'] ?? 0) === 1) || !empty($row['completed_at']);

        if ($cancelled) {
            $row['status'] = 'Cancelled';
        } elseif ($expiresAt && $expiresAt < $now && !$registered) {
            $row['status'] = 'Expired';
        } elseif ($registered) {
            $row['status'] = 'Registered';
        } elseif ($accepted) {
            $row['status'] = 'Accepted';
        } else {
            $row['status'] = 'Pending';
        }

        $row['_invite_type'] = ppf_determine_invite_type($row);

        if (in_array($row['status'], ['Cancelled', 'Canceled', 'Expired'], true)) {
            ppf_cleanup_invite_user_record($conn, $row);
        }

        $invites[] = $row;
    }
    $res->free();
} else {
    // (optional) see why query failed:
    // error_log('Invites query failed: '.$conn->error);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Invites · Peter Pang Fit</title>
  <style>
    
    html,body{margin:0;padding:0;background: var(--page-canvas);
    color:var(--text);
      font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;}
    a{color:var(--brand);text-decoration:none}
    a:hover{text-decoration:underline}
/* Button look to match other pages */
.btn{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(30,41,59,0.65);border:1px solid var(--line);
  color:var(--text);padding:8px 12px;border-radius:10px;
  cursor:pointer;text-decoration:none
}
.btn.brand{background:#38bdf8;border-color:#38bdf8;color:#fff}
.btn.small{padding:6px 10px;font-size:12px}
    .brand{font-weight:700;letter-spacing:.2px}
    .meta{color:var(--muted);font-size:13px}
    :root{
  --page-pad: clamp(14px, 3vw, 28px); /* add this with your other vars */
}

.wrap{
  width:100%;
  max-width:100%;          /* expand to screen width */
  margin:24px auto;
  padding:0 var(--page-pad); /* responsive side gutter */
  box-sizing:border-box;
}
    .actions{display:flex;gap:10px;align-items:center}
    .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:var(--chip);
      color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer}
    .btn:hover{filter:brightness(1.05)}
    .btn.warn{border-color:#3a1418;background:#2a1113;color:#f87171}
    .btn.secondary{background:transparent}
    .flash{margin:16px 0;padding:10px 12px;border-radius:10px;background:#122016;border:1px solid #1a3a2a;color:#a7f3d0}
    .table-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:0 0 12px 0}
    .table-tools__search{flex:1 1 260px;max-width:420px}
    .table-tools__search input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text)}
    .table-wrapper{overflow:auto}
    table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:14px;overflow:hidden;border:1px solid var(--line)}
    th,td{padding:12px 12px;border-bottom:1px solid var(--line);vertical-align:top}
  th{background:rgba(8,13,23,0.95);text-align:left;color:#c3c9d4;font-size:13px;letter-spacing:.3px}
    tr:last-child td{border-bottom:none}
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
    .muted{color:var(--muted)}
    .status{font-weight:600}
    .status.Pending{color:#a7f3d0}
	.status.Accepted{color:#93c5fd}
	.status.Registered{color:#a7f3d0}
    .status.Expired{color:#fcd34d}
    .status.Cancelled{color:#fca5a5}
    .nowrap{white-space:nowrap}
    .controls{display:flex;gap:8px}
  
/* === PPF Invite Status Colors === */
.status-Registered { background: rgba(34,197,94,0.25); color: #22c55e; }    /* Green */
.status-Pending { background: rgba(251,146,60,0.25); color: #fb923c; }      /* Orange */
.status-Canceled { background: rgba(239,68,68,0.25); color: #ef4444; }      /* Red */
.status-Accepted { background: rgba(234,179,8,0.25); color: #eab308; }      /* Yellow */

</style>
</head>
<body>

<main class="wrap">
  <?php
  ppf_subheader([
    'title' => 'Invites',
    'subtitle' => 'Create and manage invitations',
    'actions' => function (): void {
      ?>
      <div class="btnset">
        <a class="btn" href="dashboard.php">Back to Dashboard</a>
        <a class="btn" href="clients.php?tab=active">View Clients</a>
      </div>
      <?php
    },
  ]);
  ?>
  <h1 style="margin:0 0 14px 0;">Invites</h1>
  <p class="muted" style="margin:0 0 18px 0;">Review and manage invitations.</p>

  <?php if ($flash): ?>
    <div class="flash"><?php echo h($flash); ?></div>
  <?php endif; ?>

  <div class="table-tools">
    <div class="table-tools__search">
      <input type="search" id="inviteSearch" placeholder="Search invites..." autocomplete="off">
    </div>
  </div>

  <div class="table-wrapper">
    <table id="invitesTable">
      <colgroup>
        <col style="min-width:220px">
        <col style="min-width:180px">
        <col style="min-width:180px">
        <col style="min-width:180px">
        <col style="min-width:180px">
        <col style="min-width:140px">
        <col style="min-width:180px">
      </colgroup>
      <thead>
        <tr>
          <th data-sort-key="email"><button type="button" class="sort-btn" data-sort-key="email" data-state="off">Email<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="created"><button type="button" class="sort-btn" data-sort-key="created" data-state="off">Created<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="accepted"><button type="button" class="sort-btn" data-sort-key="accepted" data-state="off">Accepted<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="registered"><button type="button" class="sort-btn" data-sort-key="registered" data-state="off">Registered<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="expires"><button type="button" class="sort-btn" data-sort-key="expires" data-state="off">Expires<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th data-sort-key="status"><button type="button" class="sort-btn" data-sort-key="status" data-state="off">Status<span class="sort-indicator" aria-hidden="true"></span></button></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$invites): ?>
        <tr><td colspan="7" class="muted">No invites found.</td></tr>
      <?php else: foreach ($invites as $row):
        $registeredFlag = ((int)($row['used'] ?? 0) === 1) || !empty($row['completed_at']);
        $sortEmail = strtolower($row['email'] ?? '');
        $sortCreated = 0;
        if (!empty($row['created_at'])) {
            $tmp = strtotime($row['created_at']);
            $sortCreated = $tmp === false ? 0 : $tmp;
        }
        $sortAccepted = 0;
        if (!empty($row['accepted_at'])) {
            $tmp = strtotime($row['accepted_at']);
            $sortAccepted = $tmp === false ? 0 : $tmp;
        }
        $sortRegistered = 0;
        if (!empty($row['completed_at'])) {
            $tmp = strtotime($row['completed_at']);
            $sortRegistered = $tmp === false ? 0 : $tmp;
        } elseif ($registeredFlag) {
            $sortRegistered = 1;
        }
        $sortExpires = 0;
        if (!empty($row['expires_at'])) {
            $tmp = strtotime($row['expires_at']);
            $sortExpires = $tmp === false ? 0 : $tmp;
        }
        $sortStatus = strtolower($row['status'] ?? '');
      ?>
        <tr
          data-sort-email="<?php echo h($sortEmail); ?>"
          data-sort-created="<?php echo h((string)$sortCreated); ?>"
          data-sort-accepted="<?php echo h((string)$sortAccepted); ?>"
          data-sort-registered="<?php echo h((string)$sortRegistered); ?>"
          data-sort-expires="<?php echo h((string)$sortExpires); ?>"
          data-sort-status="<?php echo h($sortStatus); ?>"
        >
          <td><?php echo h($row['email'] ?? '—'); ?></td>
          <td class="nowrap"><?php echo h(fmt_dt($row['created_at'])); ?></td>
          <td class="nowrap"><?php echo h(fmt_dt($row['accepted_at'] ?? null)); ?></td>
          <td class="nowrap"><?php echo h(fmt_dt($row['completed_at'] ?? null)); ?></td>
          <td class="nowrap"><?php echo h(fmt_dt($row['expires_at'])); ?></td>
          <?php
$_ppf_status = $row['status'] ?? '';
$_ppf_colors = [
  'Registered' => '#22c55e',
  'Pending'    => '#fb923c',
  'Canceled'   => '#ef4444',
  'Cancelled'  => '#ef4444',
  'Accepted'   => '#eab308',
];
$_ppf_style = isset($_ppf_colors[$_ppf_status]) ? ' style="color: ' . $_ppf_colors[$_ppf_status] . ' !important;"' : '';
?>
<td><span class="status <?php echo h($_ppf_status); ?>"<?php echo $_ppf_style; ?>><?php echo h($_ppf_status); ?></span></td>
          <td>
            <div class="controls">
              <?php if ($row['status'] === 'Pending'): ?>
                <form method="post" onsubmit="return confirm('Cancel this invite?');" style="display:inline">
                  <input type="hidden" name="action" value="cancel">
                  <!-- ID stays backend-only -->
                  <input type="hidden" name="invite_id" value="<?php echo (int)$row['id']; ?>">
                  <button type="submit" class="btn warn">Cancel</button>
                </form>
              <?php elseif (in_array($row['status'], ['Expired', 'Cancelled', 'Canceled'], true)): ?>
                <form method="post" onsubmit="return confirm('Send a new invite to this email?');" style="display:inline">
                  <input type="hidden" name="action" value="resend">
                  <input type="hidden" name="invite_id" value="<?php echo (int)$row['id']; ?>">
                  <button type="submit" class="btn">Resend</button>
                </form>
              <?php else: ?>
                <span class="muted">No actions</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <script src="table_enhancements.js"></script>
  <script>
  (function(){
    if (!window.ppfEnhanceTable) return;
    const table = document.getElementById('invitesTable');
    if (!table) return;
    const searchInput = document.getElementById('inviteSearch');
    ppfEnhanceTable(table, {
      searchInput: searchInput,
      sortTypes: {
        created: 'number',
        accepted: 'number',
        registered: 'number',
        expires: 'number'
      },
      noMatchesText: 'No invites match your search.'
    });
  })();
  </script>
</main>
</body>
</html>
