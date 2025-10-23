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
if (!in_array($USER_ROLE, ['trainer','admin'], true)) {
    http_response_code(403);
    echo 'Forbidden';
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
    table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:14px;overflow:hidden;border:1px solid var(--line)}
    th,td{padding:12px 12px;border-bottom:1px solid var(--line);vertical-align:top}
    th{background:rgba(8,13,23,0.95);text-align:left;color:#c3c9d4;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
    tr:last-child td{border-bottom:none}
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

  <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th>Email</th>
          <th>Created</th>
		  <th>Accepted</th>
		  <th>Registered</th>
          <th>Expires</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$invites): ?>
        <tr><td colspan="7" class="muted">No invites found.</td></tr>
      <?php else: foreach ($invites as $row): ?>
        <tr>
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
</main>
</body>
</html>