<?php
// client_plans.php — Assigned plans & exercises (full-width, responsive, mobile-optimized)
//
// Key UX upgrades:
// - Full-bleed layout with safe edge padding using clamp() for all screen sizes
// - Per-plan tables stretch 100% width; sticky table headers on desktop
// - Zebra striping, larger touch targets, clearer spacing
// - Mobile: hides non-critical columns, adds horizontal scroll wrapper if needed
// - Global search, per-card collapse/expand, CSV export (unchanged)
// - Security: clients can ONLY view their own plans
//
// Requires:
//   auth.php       -> $USER_ID, $USER_ROLE
//   db.php         -> $conn (mysqli)
//   helpers.php    -> column_exists($conn, 'table', 'column')
//   ppf_header.php, ppf_nav.php
//
// Schema used:
//   users(id, email, first_name, last_name)
//   workout_plans(id, name)
//   user_plans(id, user_id, plan_id, assigned_at)
//   user_plan_exercises(user_plan_id, exercise_id, sets, reps, duration_seconds, position, weight_lbs|weight)
//   exercises(id, name, notes)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_trainer_admin($role){ return in_array(strtolower((string)($role ?? 'guest')), ['trainer','admin'], true); }

$VIEWER_ID   = (int)($_SESSION['user_id'] ?? $USER_ID ?? 0);
$VIEWER_ROLE = $_SESSION['role'] ?? ($USER_ROLE ?? 'guest');

$client_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($client_id <= 0) { http_response_code(400); echo "Missing or invalid user_id"; exit; }

// Security: non-admin/trainer can only view their own
if (!is_trainer_admin($VIEWER_ROLE) && $client_id !== $VIEWER_ID) {
  http_response_code(403); echo "Unauthorized"; exit;
}

// Client info
$stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) { http_response_code(404); echo "Client not found"; exit; }

// Plans
$plans = [];
$stmt = $conn->prepare("
  SELECT up.id AS user_plan_id, up.assigned_at,
         p.id AS plan_id, p.name AS plan_name
  FROM user_plans up
  JOIN workout_plans p ON p.id = up.plan_id
  WHERE up.user_id = ?
  ORDER BY up.assigned_at DESC, up.id DESC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $plans[] = $row;
$stmt->close();

// Weight column detection
$WEIGHT_COL = null;
if (column_exists($conn, 'user_plan_exercises', 'weight_lbs')) $WEIGHT_COL = 'weight_lbs';
elseif (column_exists($conn, 'user_plan_exercises', 'weight')) $WEIGHT_COL = 'weight';
$selectWeight = $WEIGHT_COL ? "upe.`{$WEIGHT_COL}` AS weight_val" : "NULL AS weight_val";

// Exercises (scoped by user_id; no IN list)
$plan_items = []; // [user_plan_id] => rows
if ($plans) {
  $sql = "
    SELECT
      upe.user_plan_id,
      upe.exercise_id,
      upe.sets,
      upe.reps,
      upe.duration_seconds,
      upe.position,
      {$selectWeight},
      e.name AS exercise_name,
      e.notes AS exercise_notes
    FROM user_plan_exercises AS upe
    JOIN exercises e    ON e.id = upe.exercise_id
    JOIN user_plans up  ON up.id = upe.user_plan_id
    WHERE up.user_id = ?
    ORDER BY upe.user_plan_id, COALESCE(upe.position, 999999) ASC, e.name ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $rs = $stmt->get_result();
  while ($row = $rs->fetch_assoc()) {
    $plan_items[(int)$row['user_plan_id']][] = $row;
  }
  $stmt->close();
}

// Helpers
function fmt_dur(?int $secs): string {
  if ($secs === null || $secs <= 0) return '';
  $m = intdiv($secs, 60);
  $s = $secs % 60;
  return sprintf('%d:%02d', $m, $s);
}
function total_duration_str(array $rows): string {
  $sum = 0;
  foreach ($rows as $r) $sum += (int)($r['duration_seconds'] ?? 0);
  if ($sum <= 0) return '—';
  $h = intdiv($sum, 3600); $m = intdiv($sum % 3600, 60);
  return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
}
$weightLabel = $WEIGHT_COL === 'weight_lbs' ? 'Weight (lb)' : ($WEIGHT_COL ? 'Weight' : 'Weight');

$pageTitle = ($client_id === $VIEWER_ID && !is_trainer_admin($VIEWER_ROLE))
  ? 'My Assigned Plans'
  : ('Assigned Plans — ' . h(trim(($client['first_name'] ?? '').' '.($client['last_name'] ?? '')) ?: 'Client #'.$client_id));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?php echo h($pageTitle); ?></title>
  <style>
    :root{
      --bg:#0b0e14; --card:#101521; --text:#e6eef8;
      --muted:#9bb2c8; --accent:#3c82f6; --accent2:#6c5bd6;
      --good:#17b169; --warn:#ffb443; --border:#1b2332;
      --pad-inline: clamp(14px, 3vw, 42px);
      --radius: 14px;
      --th-bg:#0f1524;
    }
    /* Page base */
    body{margin:0;background:var(--bg);color:var(--text);
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Helvetica,Arial,sans-serif}
    .page{position:sticky;top:0;z-index:50;background:#0d1220;border-bottom:1px solid var(--border)}
    .page .wrap{padding:12px var(--pad-inline)}
    h1{margin:0;font-size:22px}
    .subtitle{color:var(--muted);font-size:13px;margin-top:4px}

    /* Toolbar */
    .toolbar{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
    .search{flex:1;min-width:240px;display:flex;align-items:center;gap:8px;
      border:1px solid var(--border);border-radius:12px;background:var(--th-bg);padding:8px 10px}
    .search input{all:unset;flex:1;color:var(--text);font-size:14px}
    .chip{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;
      background:var(--th-bg);border:1px solid var(--border);font-size:13px;color:#cfe0f6}
    .chip .dot{width:8px;height:8px;border-radius:50%}
    .dot.blue{background:var(--accent)} .dot.green{background:var(--good)}
    .actions{margin-left:auto;display:flex;gap:8px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;border:1px solid var(--border);
      background:#121a2b;color:var(--text);text-decoration:none;cursor:pointer;font-size:14px}
    .btn:hover{background:#16243e}

    /* Content container: full-bleed width with safe edge padding */
    main .wrap{padding:14px var(--pad-inline) 28px}

    /* Cards — forced full-width so tables can use entire screen width */
    .cards{display:grid;grid-template-columns: 1fr; gap:14px}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
    .head{display:flex;gap:12px;align-items:flex-start;padding:16px;border-bottom:1px solid var(--border);
      background:linear-gradient(180deg,#11182a,transparent)}
    .plan-title{margin:0;font-size:18px;line-height:1.2}
    .plan-sub{color:var(--muted);font-size:12px}
    .pillbar{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 8px;border-radius:999px;border:1px solid var(--border);
      background:var(--th-bg);color:#cfe0f6;font-size:12px}
    .fold{margin-left:auto}
    .fold .toggle{all:unset;cursor:pointer;padding:6px 10px;border-radius:8px;border:1px solid var(--border);
      background:var(--th-bg);font-size:12px}
    .content{padding:0}

    /* Full-width table wrapper with subtle scroll hint on narrow screens */
    .table-wrap{width:100%; overflow-x:auto; /* allow scroll on phones when needed */ }
    .table-wrap::-webkit-scrollbar{height:8px}
    .table-wrap::-webkit-scrollbar-thumb{background:#22314c;border-radius:8px}

    /* Table */
    table{width:100%; border-collapse:collapse}
    thead th{position:sticky;top:0; z-index:1; background:var(--th-bg); color:#a8bbd4;
      text-transform:uppercase; letter-spacing:.03em; font-size:12px; text-align:left; border-bottom:1px solid var(--border)}
    th, td{padding:12px 14px; border-bottom:1px solid var(--border); font-size:15px}
    tbody tr:nth-child(odd){background:rgba(255,255,255,0.02)}
    .row-note{color:var(--muted);font-size:12px;margin-top:4px;line-height:1.35}

    .foot{display:flex;align-items:center;gap:8px;justify-content:flex-end;padding:12px 14px;border-top:1px solid var(--border);
      background:var(--th-bg)}
    .muted{color:var(--muted)}
    .empty{color:var(--muted);padding:16px}

    /* Mobile tuning */
    @media (max-width: 640px){
      h1{font-size:20px}
      th, td{padding:10px 12px; font-size:14px}
      .plan-title{font-size:16px}
      /* Hide less-critical cols on phones; users can still sideways-scroll if they want everything */
      .col-sets, .col-reps, .col-weight { display:none; }
      .pill{font-size:11px}
      .btn{padding:7px 10px; font-size:13px}
      .search{min-width:0}
    }
  </style>
</head>
<body>

<header class="page">
  <div class="wrap">
    <h1><?php echo h($pageTitle); ?></h1>
    <?php if (is_trainer_admin($VIEWER_ROLE)): ?>
      <div class="subtitle">Viewing plans for <?php echo h($client['first_name'].' '.$client['last_name']); ?> (<?php echo h($client['email']); ?>)</div>
    <?php else: ?>
      <div class="subtitle">These are the plans assigned to you.</div>
    <?php endif; ?>

    <div class="toolbar">
      <div class="search" title="Filter exercises by name on this page">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M21 21l-3.8-3.8m1.8-5.2a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"
                stroke="#9bb2c8" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <input id="globalSearch" type="text" placeholder="Search exercises..."/>
      </div>
      <span class="chip"><span class="dot blue"></span>Total plans <strong id="chipPlans"><?php echo count($plans); ?></strong></span>
      <span class="chip"><span class="dot green"></span>Total exercises <strong id="chipItems">0</strong></span>
      <div class="actions">
        <button class="btn" id="btnExpandAll" type="button">Expand all</button>
        <button class="btn" id="btnCollapseAll" type="button">Collapse all</button>
        <button class="btn" id="btnExportCSV" type="button">Export CSV</button>
      </div>
    </div>
  </div>
</header>

<main>
  <div class="wrap">
    <?php if (!$plans): ?>
      <p class="empty">No plans assigned yet.</p>
    <?php else: ?>
      <div class="cards" id="cards">
        <?php foreach ($plans as $p):
          $pid   = (int)$p['user_plan_id'];
          $items = $plan_items[$pid] ?? [];
          $exCount = count($items);
          $sumSets = 0; $sumReps = 0;
          foreach ($items as $it) { $sumSets += (int)($it['sets'] ?? 0); $sumReps += (int)($it['reps'] ?? 0); }
          $durStr = total_duration_str($items);
        ?>
        <section class="card" data-plan-id="<?php echo $pid; ?>">
          <div class="head">
            <div>
              <h2 class="plan-title"><?php echo h($p['plan_name']); ?></h2>
              <div class="plan-sub">Assigned: <?php echo h(date('M j, Y g:ia', strtotime($p['assigned_at']))); ?></div>
              <div class="pillbar" role="list">
                <span class="pill" role="listitem"><strong><?php echo $exCount; ?></strong> exercises</span>
                <span class="pill" role="listitem"><strong><?php echo $sumSets; ?></strong> total sets</span>
                <span class="pill" role="listitem"><strong><?php echo $sumReps; ?></strong> total reps</span>
                <span class="pill" role="listitem"><strong><?php echo h($durStr); ?></strong> est. duration</span>
              </div>
            </div>
            <div class="fold">
              <button type="button" class="toggle" data-target="#tbl-<?php echo $pid; ?>" aria-expanded="true">Collapse</button>
            </div>
          </div>

          <div class="content" id="tbl-<?php echo $pid; ?>">
            <?php if (!$items): ?>
              <div class="empty">No exercises in this plan.</div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="data-table" data-plan="<?php echo $pid; ?>">
                  <thead>
                    <tr>
                      <th style="width:64px">#</th>
                      <th>Exercise</th>
                      <th class="col-sets" style="width:100px">Sets</th>
                      <th class="col-reps" style="width:100px">Reps</th>
                      <th class="col-weight" style="width:140px"><?php echo h($weightLabel); ?></th>
                      <th style="width:130px">Duration</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $i=1; foreach ($items as $it): ?>
                      <?php
                        $weightOut = ($it['weight_val'] !== null && $it['weight_val'] !== '') ? (string)$it['weight_val'] : '';
                        $notes = trim((string)($it['exercise_notes'] ?? ''));
                      ?>
                      <tr>
                        <td><?php echo $i++; ?></td>
                        <td>
                          <strong class="ex-name"><?php echo h($it['exercise_name']); ?></strong>
                          <?php if ($notes !== ''): ?>
                            <div class="row-note"><?php echo nl2br(h($notes)); ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="col-sets"><?php echo ($it['sets'] !== null ? (int)$it['sets'] : ''); ?></td>
                        <td class="col-reps"><?php echo ($it['reps'] !== null ? (int)$it['reps'] : ''); ?></td>
                        <td class="col-weight"><?php echo h($weightOut); ?></td>
                        <td><?php echo h(fmt_dur($it['duration_seconds'] ?? null)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
            <div class="foot">
              <span class="muted">Tip: use the page search box to filter exercises by name.</span>
            </div>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
(function(){
  const q = document.getElementById('globalSearch');
  const tables = Array.from(document.querySelectorAll('table.data-table'));
  const chipItems = document.getElementById('chipItems');
  const btnExpand = document.getElementById('btnExpandAll');
  const btnCollapse = document.getElementById('btnCollapseAll');
  const btnCSV = document.getElementById('btnExportCSV');

  function countAllRows(){
    let n = 0;
    tables.forEach(t => n += t.tBodies[0]?.rows.length || 0);
    chipItems.textContent = n;
  }
  countAllRows();

  function setSectionVisible(el, show){
    if (!el) return;
    const btn = el.parentElement?.querySelector('.toggle');
    if (show){
      el.style.display = '';
      if (btn){ btn.textContent = 'Collapse'; btn.setAttribute('aria-expanded','true'); }
    } else {
      el.style.display = 'none';
      if (btn){ btn.textContent = 'Expand'; btn.setAttribute('aria-expanded','false'); }
    }
  }

  document.querySelectorAll('.toggle').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const sel = btn.getAttribute('data-target');
      const panel = document.querySelector(sel);
      const visible = panel && panel.style.display !== 'none';
      setSectionVisible(panel, !visible);
    });
  });

  btnExpand?.addEventListener('click', ()=>{
    document.querySelectorAll('.content').forEach(p=>setSectionVisible(p, true));
  });
  btnCollapse?.addEventListener('click', ()=>{
    document.querySelectorAll('.content').forEach(p=>setSectionVisible(p, false));
  });

  function normalize(s){ return (s||'').toString().toLowerCase(); }
  q?.addEventListener('input', ()=>{
    const needle = normalize(q.value);
    let totalShown = 0;
    tables.forEach(t=>{
      const rows = Array.from(t.tBodies[0]?.rows || []);
      let rowsShown = 0;
      rows.forEach(row=>{
        const name = normalize(row.querySelector('.ex-name')?.textContent);
        const note = normalize(row.querySelector('.row-note')?.textContent);
        const hit = !needle || name.includes(needle) || note.includes(needle);
        row.style.display = hit ? '' : 'none';
        if (hit) { rowsShown++; totalShown++; }
      });
      const section = t.closest('.card');
      const content  = section?.querySelector('.content');
      if (content){
        content.style.display = rowsShown ? '' : 'none';
        const toggle = section.querySelector('.toggle');
        if (toggle){
          toggle.textContent = rowsShown ? 'Collapse' : 'Expand';
          toggle.setAttribute('aria-expanded', rowsShown ? 'true' : 'false');
        }
      }
    });
    chipItems.textContent = totalShown;
  });

  function tableToCSV(){
    const lines = [];
    lines.push(['Plan Name','Assigned At','#','Exercise','Sets','Reps','Weight','Duration'].join(','));
    document.querySelectorAll('.card').forEach(card=>{
      const planName = card.querySelector('.plan-title')?.textContent?.trim() || '';
      const assigned = card.querySelector('.plan-sub')?.textContent?.replace(/^Assigned:\s*/,'').trim() || '';
      const table = card.querySelector('table.data-table');
      if (!table) return;
      const rows = Array.from(table.tBodies[0]?.rows || []);
      rows.forEach(row=>{
        const cells = row.cells;
        const num  = (cells[0]?.textContent||'').trim();
        const ex   = (row.querySelector('.ex-name')?.textContent || '').trim();
        const sets = (table.querySelector('.col-sets') ? (cells[2]?.textContent||'').trim() : '');
        const reps = (table.querySelector('.col-reps') ? (cells[3]?.textContent||'').trim() : '');
        const w    = (table.querySelector('.col-weight') ? (cells[4]?.textContent||'').trim() : '');
        const dur  = (cells[cells.length-1]?.textContent||'').trim();
        const esc = s => `"${String(s).replace(/"/g,'""')}"`;
        lines.push([planName, assigned, num, ex, sets, reps, w, dur].map(esc).join(','));
      });
    });
    return lines.join('\r\n');
  }

  btnCSV?.addEventListener('click', ()=>{
    const csv = tableToCSV();
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const stamp = new Date().toISOString().slice(0,19).replace(/[:T]/g,'-');
    a.href = url; a.download = `client_plans_<?php echo (int)$client_id; ?>_${stamp}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  });
})();
</script>

</body>
</html>