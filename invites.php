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
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

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
if ($roleKey !== 'trainer' && !ppf_is_admin_role($USER_ROLE ?? null)) {
    require_once __DIR__ . '/access_denied.php';
    exit;
}

$flash = null;

// Handle cancel action (uses hidden ID; not displayed)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        $invite_id = (int)($_POST['invite_id'] ?? 0);
        if ($invite_id > 0) {
            if ($stmt = $conn->prepare("UPDATE invites SET cancelled_at = NOW() WHERE id = ? AND cancelled_at IS NULL")) {
                $stmt->bind_param("i", $invite_id);
                if ($stmt->execute()) {
                    $flash = $stmt->affected_rows > 0
                        ? "Invite was cancelled."
                        : "No change — invite may already be cancelled.";
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
    }
}

// Load invites; do not expose IDs in UI
$invites = [];
$sql = "
  SELECT id, email, token, created_at, accepted_at, completed_at,
         expires_at, cancelled_at, created_by, COALESCE(used,0) AS used
  FROM invites
  ORDER BY created_at DESC
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
    /* Sticky subheader like clients/exercises */
.subheader{
  position: sticky;
  top: 0;               /* if your main header is sticky with height, bump this (e.g., 64px) */
  z-index: 40;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px 12px;
  margin-bottom: 14px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.subheader .left{display:flex;align-items:center;gap:10px}
.brand{font-weight:800;font-size:20px;letter-spacing:.2px}
.btnset{display:flex;gap:8px;flex-wrap:wrap}

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
    th{background:rgba(8,13,23,0.95);text-align:left;color:#c3c9d4;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
    tr:last-child td{border-bottom:none}
    .sort-btn{all:unset;display:flex;align-items:center;gap:6px;justify-content:flex-start;width:100%;cursor:pointer;padding-right:18px;color:inherit;font:inherit}
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
  </style>
</head>
<body>

<main class="wrap">
	<!-- Persistent subheader -->
<div class="subheader">
  <div class="left">
    <div class="brand">Invites</div>
    <span class="muted">Create and manage invitations</span>
  </div>
  <div class="btnset">
    <!-- If your create page is named differently, adjust the href -->
    <a class="btn brand" href="create_invite_form.php" id="btnCreateInvite">Create Invite</a>
    <a class="btn" href="dashboard.php">Back to Dashboard</a>
    <a class="btn" href="clients.php?tab=active">View Clients</a>
  </div>
</div>
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
          <td><span class="status <?php echo h($row['status']); ?>"><?php echo h($row['status']); ?></span></td>
          <td>
            <div class="controls">
              <?php if ($row['status'] === 'Pending'): ?>
                <form method="post" onsubmit="return confirm('Cancel this invite?');" style="display:inline">
                  <input type="hidden" name="action" value="cancel">
                  <!-- ID stays backend-only -->
                  <input type="hidden" name="invite_id" value="<?php echo (int)$row['id']; ?>">
                  <button type="submit" class="btn warn">Cancel</button>
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
