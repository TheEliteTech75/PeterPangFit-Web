<?php
// sessions.php — Admin: view + manage ALL login sessions across the system
// Features (abridged): Current / Active / Inactive / Expired / Revoked, reveal SID, revoke one/all,
// cached VPN pill, GeoIP tooltip with left-aligned "Label: Value" lines.
// Expired obeys settings.session_timeout_minutes (fallback 120).

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$uid   = (int)($_SESSION['user_id'] ?? 0);
$role  = (string)($_SESSION['role'] ?? 'client');
$email = (string)($_SESSION['email'] ?? '');

if ($uid <= 0) { header('Location: login.php'); exit; }
if (strtolower($role) !== 'admin') { header('Location: dashboard.php'); exit; }

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------- Config ---------- */
$INACTIVE_SECS = 30 * 60;   // 30 minutes soft idle window

function ppf_get_session_timeout_minutes(mysqli $conn): int {
  $def = 120;
  try {
    if ($st = $conn->prepare("SELECT value FROM settings WHERE `key`='session_timeout_minutes' LIMIT 1")) {
      $st->execute();
      $rs = $st->get_result();
      $v = $rs ? ($rs->fetch_assoc()['value'] ?? null) : null;
      $st->close();
      $n = (int)$v;
      if ($n > 0 && $n <= 14400) return $n; // up to 10 days
    }
  } catch (\Throwable $e) {}
  return $def;
}

// cached-only VPN flag (fast)
function ppf_vpn_cached_only(mysqli $conn, ?string $ip): ?bool {
  if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
  try {
    if (!$st = $conn->prepare("SELECT is_vpn, vpn_checked_at FROM ip_cache WHERE ip_bin = INET6_ATON(?) LIMIT 1")) return null;
    $st->bind_param("s", $ip);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
    if (!$row) return null;
    $isVpn = isset($row['is_vpn']) ? (int)$row['is_vpn'] : null;
    $checked = isset($row['vpn_checked_at']) ? strtotime((string)$row['vpn_checked_at']) : 0;
    if ($isVpn === null || $checked <= 0) return null;
    if ((time() - $checked) > 7*24*3600) return null;
    return (bool)$isVpn;
  } catch (\Throwable $e) { return null; }
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// prune revoked older than 7d
@$conn->query("DELETE FROM user_sessions WHERE revoked=1 AND last_seen_at < (NOW() - INTERVAL 7 DAY)");

$currentSid = session_id();
$inactiveCut   = date('Y-m-d H:i:s', time() - $INACTIVE_SECS);

$timeoutMin = ppf_get_session_timeout_minutes($conn);
$expiredCut = date('Y-m-d H:i:s', time() - ($timeoutMin * 60));

// load sessions
$sessions = [];
$sql = "
  SELECT
    us.session_id, us.user_id, us.created_at, us.last_seen_at, us.revoked, us.ip, us.city, us.region,
    COALESCE(us.browser, '') AS browser, COALESCE(us.platform, '') AS platform,
    COALESCE(us.user_agent, '') AS user_agent,
    u.email, u.first_name, u.last_name, u.role
  FROM user_sessions us
  LEFT JOIN users u ON u.id = us.user_id
  ORDER BY us.last_seen_at DESC
";
if ($rs = $conn->query($sql)) {
  while ($row = $rs->fetch_assoc()) {
    $row['is_current'] = ($row['session_id'] === $currentSid);
    $row['is_revoked'] = ((int)$row['revoked'] === 1);

    $ua = (string)($row['user_agent'] ?? '');
    $row['browser_disp']  = $row['browser']  ?: ppf_detect_browser($ua);
    $row['platform_disp'] = $row['platform'] ?: ppf_detect_platform($ua);

    $lastSeen = (string)($row['last_seen_at'] ?? '');
    $seenRecently = ($lastSeen !== '' && strcmp($lastSeen, $inactiveCut) >= 0);
    $pastExpired  = ($lastSeen !== '' && strcmp($lastSeen, $expiredCut)  < 0);

    $row['is_active']   = (!$row['is_revoked'] && $seenRecently);
    $row['is_expired']  = (!$row['is_revoked'] && $pastExpired);
    $row['is_inactive'] = (!$row['is_current'] && !$row['is_revoked'] && !$row['is_active'] && !$row['is_expired']);

    $ip = trim((string)($row['ip'] ?? ''));
    $row['is_vpn_cached'] = (ppf_vpn_cached_only($conn, $ip) === true);

    $sessions[] = $row;
  }
  $rs->close();
}

$counts = ['total'=>0,'current'=>0,'active'=>0,'inactive'=>0,'expired'=>0,'revoked'=>0];
$counts['total'] = count($sessions);
foreach ($sessions as $s) {
  if (!empty($s['is_current']))  $counts['current']++;
  if (!empty($s['is_revoked']))  $counts['revoked']++;
  elseif (!empty($s['is_active']))   $counts['active']++;
  elseif (!empty($s['is_expired']))  $counts['expired']++;
  else                               $counts['inactive']++;
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sessions · Peter Pang Fit</title>
  <style>
  :root{
    color-scheme:dark;
    --bg:#05070d; --bg-alt:#03040a; --panel:rgba(9,14,28,0.92); --text:#f8fafc; --muted:#cbd5f5;
    --brand:#38bdf8; --line:rgba(148,163,184,0.18); --danger:#b91c1c; --danger-bg:#2a1617; --danger-line:rgba(248,113,113,0.45);
    --gold:#6b4e1b; --gold-bg:#3a2f1a; --gold-text:#ffd166;
    --inactive-bg:#1b1e26; --inactive-br:#2a2f3a; --inactive-text:#cbd5f5;
    --icloud-bg:#1b2430; --icloud-br:#2b3b55; --icloud-text:#c3dafe; /* bluish */
  }
  html,body{ margin:0;padding:0;background:
      radial-gradient(circle at top left, rgba(56,189,248,0.18), transparent 55%),
      radial-gradient(circle at bottom right, rgba(110,231,183,0.12), transparent 60%),
      linear-gradient(155deg, var(--bg), var(--bg-alt));
    color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif; overflow-x:hidden;}
  a{color:var(--brand);text-decoration:none} a:hover{text-decoration:underline}

  .wrap{width:100%;max-width:none;margin:24px auto;padding:0 12px;box-sizing:border-box;}

  .card{background:rgba(9,14,28,0.72);border:1px solid var(--line);border-radius:14px;padding:18px;width:100%;box-sizing:border-box;overflow:hidden;}
  .card h3{margin:0 0 10px 0;font-size:22px}
  .muted{color:var(--muted)}
  .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
       color:var(--text);padding:10px 14px;border-radius:10px;cursor:pointer;text-decoration:none}
  .btn.brand{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
  .btn.warn{background:#2a1617;border-color:rgba(248,113,113,0.45);color:#f87171}
  .btn[disabled]{opacity:.6;cursor:not-allowed;pointer-events:none;filter:grayscale(30%);}
  .pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:rgba(15,23,42,0.7);font-size:12px}
  .pill.current{background:#2a3446}
  .pill.active{background:rgba(56,189,248,0.22);border-color:rgba(56,189,248,0.35)}
  .pill.inactive{ background:var(--inactive-bg); border-color:var(--inactive-br); color:var(--inactive-text); }
  .pill.expired{ background:var(--gold-bg); border-color:var(--gold); color:var(--gold-text); }
  .pill.revoked{ background:rgba(127,29,29,0.28); border-color:rgba(248,113,113,0.45); color:#f87171 }
  .pill.vpn{ background:var(--gold-bg); border-color:var(--gold); color:var(--gold-text); }
  .pill.icloud{ background:var(--icloud-bg); border-color:var(--icloud-br); color:var(--icloud-text); }

  .table-wrap{overflow-x:auto;border-radius:10px;border:1px solid var(--line);background:rgba(11,18,30,0.9)}
  table{width:100%;border-collapse:collapse;min-width:1160px}
  th,td{padding:10px;text-align:left;border-bottom:1px solid var(--line)}
  thead th{position:sticky;top:0;background:rgba(8,13,23,0.95)}

  .flash{margin:0 0 16px 0;padding:12px;border-radius:10px;border:1px solid;background:rgba(8,13,23,0.85)}
  .flash.ok{border-color:rgba(34,197,94,0.45);color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}
  .toolbar{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap}
  .filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .legend{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  .sid-mask{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.3px}
  .eye{cursor:pointer;opacity:.85}
  .eye:hover{opacity:1}
  .modal{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:none; align-items:center; justify-content:center; z-index:100 }
  .modal.show{ display:flex; }
  .inline-input{width:100%;background:rgba(8,13,23,0.95);border:1px solid var(--line);color:#var(--text);padding:10px;border-radius:10px;box-sizing:border-box}

  /* Tooltip bubble */
  .ip-tip{ position:fixed; z-index:200; max-width:420px; background:rgba(8,13,23,0.95); color:var(--text);
           border:1px solid var(--line); border-radius:12px; padding:12px; box-shadow:0 6px 24px rgba(0,0,0,.45); display:none; }
  .ip-tip h4{ margin:0 0 8px 0; font-size:14px; }
  .ip-tip .line{ margin:4px 0; }
  .ip-tip .k{ color:#cbd5f5; }
  .ip-chip{ display:inline-flex; align-items:center; gap:6px; }
  </style>
</head>
<body>
<main class="wrap">
  <h1 style="margin:0 0 10px 0;">Sessions</h1>
  <p class="muted" style="margin:0 0 18px 0;">Admin view of all login sessions across the platform.</p>

  <div class="card">
    <div class="toolbar" style="justify-content:space-between;margin-bottom:12px">
      <div class="filters">
        <span class="pill">Total: <?php echo (int)$counts['total']; ?></span>
        <span class="pill current">Current: <?php echo (int)$counts['current']; ?></span>
        <span class="pill active">Active: <?php echo (int)$counts['active']; ?></span>
        <span class="pill inactive">Inactive: <?php echo (int)$counts['inactive']; ?></span>
        <span class="pill expired">Expired: <?php echo (int)$counts['expired']; ?></span>
        <span class="pill revoked">Revoked: <?php echo (int)$counts['revoked']; ?></span>
      </div>
      <button class="btn warn" id="btn-global-signout">Sign Out ALL Sessions</button>
    </div>

    <?php if (!$sessions): ?>
      <div class="muted">No sessions recorded.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table id="sess-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Session ID</th>
              <th>User ID</th>
              <th>Role</th>
              <th>Email</th>
              <th>Location</th>
              <th>IP Address</th>
              <th>Browser</th>
              <th>Operating System</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $s): ?>
            <?php
              $ts = $s['last_seen_at'] ?: $s['created_at'];
              $tsDisp = $ts ? date('M j, Y g:i A', strtotime($ts)) : '—';
              $roleDisp = $s['role'] ? ucfirst(strtolower((string)$s['role'])) : '—';
              $canSignOut = (!$s['is_revoked'] && !$s['is_expired'] && !$s['is_current']); // cannot sign out current/expired/revoked
            ?>
            <tr
              data-sid="<?php echo h($s['session_id']); ?>"
              data-revoked="<?php echo (int)$s['is_revoked']; ?>"
              data-current="<?php echo $s['is_current'] ? '1':'0'; ?>"
            >
              <td class="muted" style="white-space:nowrap">
                <?php echo h($tsDisp); ?>

                <?php if ($s['is_current']): ?>
                  <span class="pill current" style="margin-left:6px">Current</span>
                <?php endif; ?>

                <?php if ($s['is_active']): ?>
                  <span class="pill active" style="margin-left:6px">Active</span>
                <?php elseif ($s['is_revoked']): ?>
                  <span class="pill revoked" style="margin-left:6px">Revoked</span>
                <?php elseif ($s['is_expired']): ?>
                  <span class="pill expired" style="margin-left:6px">Expired</span>
                <?php elseif (!$s['is_current']): ?>
                  <span class="pill inactive" style="margin-left:6px">Inactive</span>
                <?php endif; ?>
              </td>

              <td>
                <span class="sid-mask" data-hide="1">••••••••••••••••</span>
                <a class="eye" title="Reveal Session ID" href="#" onclick="return openReveal(this)">👁️</a>
                <div class="sid-full" style="display:none;font-family:ui-monospace"><?php echo h($s['session_id']); ?></div>
              </td>

              <td><?php echo (int)$s['user_id']; ?></td>
              <td class="muted"><?php echo h($roleDisp); ?></td>
              <td class="muted"><?php echo h($s['email'] ?: '—'); ?></td>
              <td class="muted"><?php echo h( (($s['city']?:'Unknown').', '.($s['region']?:'Unknown')) ); ?></td>

              <td class="muted">
                <?php if (!empty($s['ip'])): ?>
                  <span class="ip-chip">
                    <a href="#" class="ip-hover" data-ip="<?php echo h($s['ip']); ?>" onclick="return false;"><?php echo h($s['ip']); ?></a>
                    <?php if (!empty($s['is_vpn_cached'])): ?>
                      <span class="pill vpn" title="Potential VPN / Hosting / Proxy">VPN</span>
                    <?php endif; ?>
                  </span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>

              <td class="muted"><?php echo h($s['browser_disp'] ?: 'Unknown'); ?></td>
              <td class="muted"><?php echo h($s['platform_disp'] ?: 'Unknown'); ?></td>
              <td>
                <?php if ($canSignOut): ?>
                  <button class="btn" onclick="return openRevoke(this)">Sign Out</button>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Tooltip bubble -->
<div id="ip-tip" class="ip-tip" role="tooltip" aria-hidden="true"></div>

<!-- Reveal Session ID -->
<div class="modal" id="modal-reveal">
  <div class="card" style="max-width:460px;width:92%;">
    <h3>Reveal Session ID</h3>
    <p class="muted">Enter your current password to reveal this Session ID.</p>
    <form onsubmit="return doReveal(event)" autocomplete="off">
      <input type="hidden" id="rv-csrf" value="<?php echo h($csrf); ?>">
      <label>Current password</label>
      <input class="inline-input" id="rv-pass" type="password" required>
      <div id="rv-err" class="flash err" style="display:none;margin-top:10px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px">
        <a class="btn" href="#" onclick="return closeModal(this)">Cancel</a>
        <button class="btn brand" id="rv-submit" type="submit">Reveal</button>
      </div>
    </form>
  </div>
</div>

<!-- Revoke ONE -->
<div class="modal" id="modal-revoke">
  <div class="card" style="max-width:460px;width:92%;">
    <h3>Sign Out Session</h3>
    <p class="muted">Enter your current password to sign out (revoke) this session.</p>
    <form onsubmit="return doRevoke(event)" autocomplete="off">
      <input type="hidden" id="rk-csrf" value="<?php echo h($csrf); ?>">
      <label>Current password</label>
      <input class="inline-input" id="rk-pass" type="password" required>
      <div id="rk-err" class="flash err" style="display:none;margin-top:10px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px">
        <a class="btn" href="#" onclick="return closeModal(this)">Cancel</a>
        <button class="btn brand" id="rk-submit" type="submit">Sign Out</button>
      </div>
    </form>
  </div>
</div>

<!-- Revoke ALL -->
<div class="modal" id="modal-all">
  <div class="card" style="max-width:520px;width:92%;">
    <h3>Sign Out ALL Sessions</h3>
    <p class="muted">This will revoke <strong>every</strong> session in the system except your current session. This action is audited and all admins will be notified.</p>
    <form onsubmit="return doRevokeAll(event)" autocomplete="off">
      <input type="hidden" id="ga-csrf" value="<?php echo h($csrf); ?>">
      <label>Authenticator App code</label>
      <input class="inline-input" id="ga-code" maxlength="8" inputmode="numeric" required>
      <label style="margin-top:10px">Current password</label>
      <input class="inline-input" id="ga-pass" type="password" required>
      <div id="ga-err" class="flash err" style="display:none;margin-top:10px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px">
        <a class="btn" href="#" onclick="return closeModal(this)">Cancel</a>
        <button class="btn warn" id="ga-submit" type="submit">Sign Out ALL Sessions</button>
      </div>
    </form>
  </div>
</div>

<script>
let ROW_TARGET = null; // the <tr> we’re acting on
const TIP = document.getElementById('ip-tip');

/* ---------- Tooltip helpers ---------- */
function tipHide(){ if (!TIP) return; TIP.style.display='none'; TIP.setAttribute('aria-hidden','true'); TIP.innerHTML=''; }
function tipShow(html, x, y){
  if (!TIP) return;
  TIP.innerHTML = html;
  TIP.style.display='block';
  TIP.setAttribute('aria-hidden','false');
  const pad = 12;
  const vw = window.innerWidth, vh = window.innerHeight;
  TIP.style.left = Math.min(x + 14, vw - TIP.offsetWidth - pad) + 'px';
  TIP.style.top  = Math.min(y + 14, vh - TIP.offsetHeight - pad) + 'px';
}
function tipHTML(data){
  if (!data || !data.ok) return '<div class="muted">Lookup failed</div>';
  const vpn = (data.is_icloud ? '' : (data.is_vpn ? '<span class="pill vpn" style="margin-left:6px">VPN</span>' : ''));
  const icloud = (data.is_icloud ? '<span class="pill icloud" style="margin-left:6px">iCloud</span>' : '');
  const flags = data.anonymous_flags || null;

  let html = '';
  html += `<h4>IP: ${data.ip} ${icloud}${vpn}</h4>`;
  html += `<div class="line"><span class="k">City:</span> ${data.city || '—'}</div>`;
  html += `<div class="line"><span class="k">Region:</span> ${data.region || '—'}</div>`;
  html += `<div class="line"><span class="k">ASN/Org:</span> ${data.asn_org || '—'}</div>`;
  html += `<div class="line"><span class="k">Source:</span> ${data.source || '—'}</div>`;
  if (flags){
    html += `<div class="line" style="margin-top:6px;border-top:1px dashed var(--line);padding-top:6px"><span class="k">Anonymous-IP flags:</span></div>`;
    Object.entries(flags).forEach(([k,v])=>{
      html += `<div class="line">${k.replaceAll('_',' ')}: ${v ? 'true' : 'false'}</div>`;
    });
  }
  return html;
}
let tipTimer = null;

function attachIpHovers(){
  document.querySelectorAll('.ip-hover').forEach(el=>{
    const ip = el.getAttribute('data-ip');

    const onEnter = (e)=>{
      clearTimeout(tipTimer);
      tipShow('<div class="muted">Loading…</div>', e.clientX, e.clientY);
      fetch('sessions_ipinfo.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({ ip })
      })
      .then(async (r)=>{
        const txt = await r.text();
        try { return JSON.parse(txt); } catch { throw new Error(txt || 'Lookup failed'); }
      })
      .then(j=>{
        tipShow(tipHTML(j), e.clientX, e.clientY);

        // Lazily add iCloud/VPN pill beside the IP (prefer iCloud over VPN)
        try{
          const chip = el.closest('.ip-chip');
          if (!chip) return;
          // Remove existing pills to avoid duplicates/contradictions
          chip.querySelectorAll('.pill.vpn,.pill.icloud').forEach(n=>n.remove());

          if (j && j.ok) {
            if (j.is_icloud) {
              const span = document.createElement('span');
              span.className = 'pill icloud';
              span.title = 'iCloud Private Relay';
              span.textContent = 'iCloud';
              chip.appendChild(span);
            } else if (j.is_vpn) {
              const span = document.createElement('span');
              span.className = 'pill vpn';
              span.title = 'Potential VPN / Hosting / Proxy';
              span.textContent = 'VPN';
              chip.appendChild(span);
            }
          }
        }catch(_e){}
      })
      .catch(err=>{
        tipShow('<div class="muted">'+String(err.message||err)+'</div>', e.clientX, e.clientY);
        console.error('IP tooltip error:', err);
      });
    };

    const onMove = (e)=>{
      if (TIP.style.display !== 'block') return;
      tipShow(TIP.innerHTML, e.clientX, e.clientY);
    };

    const onLeave = ()=>{
      clearTimeout(tipTimer);
      tipTimer = setTimeout(tipHide, 120);
    };

    el.addEventListener('mouseenter', onEnter);
    el.addEventListener('mousemove', onMove);
    el.addEventListener('mouseleave', onLeave);
  });

  document.addEventListener('click', (e)=>{
    if (!TIP.contains(e.target) && !e.target.classList.contains('ip-hover')) tipHide();
  });
  document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') tipHide(); });
}
document.addEventListener('DOMContentLoaded', attachIpHovers);

/* ---------- Modal plumbing ---------- */
function closeModal(el){
  const m = el.closest('.modal'); if (m) m.classList.remove('show'); return false;
}
function showModalById(id){ const m=document.getElementById(id); if (m) m.classList.add('show'); }
document.querySelectorAll('.modal').forEach(m=>{
  m.addEventListener('click', (e)=>{ if (e.target === m) m.classList.remove('show'); });
});
document.addEventListener('keydown', (e)=>{ if (e.key==='Escape'){ document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show')); }});

// Busy button helpers
function lockButton(btn, label='Processing...'){
  if (!btn) return;
  if (!btn.dataset.origLabel) btn.dataset.origLabel = btn.textContent;
  btn.textContent = label; btn.disabled = true;
}
function unlockButton(btn){
  if (!btn) return;
  btn.disabled = false;
  if (btn.dataset.origLabel){ btn.textContent = btn.dataset.origLabel; delete btn.dataset.origLabel; }
}

/* ---------- REVEAL / HIDE Session ID (toggle) ---------- */
function openReveal(a){
  ROW_TARGET = a.closest('tr');
  const mask = ROW_TARGET.querySelector('.sid-mask');
  const full = ROW_TARGET.querySelector('.sid-full');

  if (mask && mask.getAttribute('data-hide') === '0') {
    mask.setAttribute('data-hide','1');
    mask.textContent = '••••••••••••••••';
    if (full) full.style.display = 'none';
    a.title = 'Reveal Session ID';
    return false;
  }

  document.getElementById('rv-pass').value = '';
  document.getElementById('rv-err').style.display='none';
  showModalById('modal-reveal');
  return false;
}

async function doReveal(e){
  e.preventDefault();
  const pass = document.getElementById('rv-pass').value || '';
  const btn  = document.getElementById('rv-submit');
  const err  = document.getElementById('rv-err');
  err.style.display='none';
  lockButton(btn);
  try{
    const txt = await fetch('sessions_admin_actions.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action:'verify_password',
        csrf_token: document.getElementById('rv-csrf').value,
        password: pass
      })
    }).then(r=>r.text());
    let j; try { j = JSON.parse(txt); } catch { throw new Error(txt || 'Verification failed.'); }
    if (!j.ok){ throw new Error(j.error || 'Incorrect password.'); }
    if (ROW_TARGET){
      ROW_TARGET.querySelector('.sid-mask')?.setAttribute('data-hide','0');
      const sidFull = ROW_TARGET.querySelector('.sid-full');
      if (sidFull){
        sidFull.style.display='';
        ROW_TARGET.querySelector('.sid-mask').textContent = sidFull.textContent;
      }
      const eye = ROW_TARGET.querySelector('.eye');
      if (eye) eye.title = 'Hide Session ID';
    }
    document.getElementById('modal-reveal').classList.remove('show');
  } catch(ex){
    err.textContent = String(ex.message || ex || 'Verification failed.');
    err.style.display='block';
    console.error('Reveal error:', ex);
  } finally { unlockButton(btn); }
  return false;
}

/* ---------- REVOKE one ---------- */
function openRevoke(btn){
  ROW_TARGET = btn.closest('tr');
  document.getElementById('rk-pass').value='';
  document.getElementById('rk-err').style.display='none';
  showModalById('modal-revoke');
  return false;
}
async function doRevoke(e){
  e.preventDefault();
  if (!ROW_TARGET) return false;
  const pass = document.getElementById('rk-pass').value || '';
  const btn  = document.getElementById('rk-submit');
  const err  = document.getElementById('rk-err');
  err.style.display='none';
  lockButton(btn);
  try{
    const sid = ROW_TARGET.getAttribute('data-sid');
    const txt = await fetch('sessions_admin_actions.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action:'revoke_one',
        csrf_token: document.getElementById('rk-csrf').value,
        password: pass,
        session_id: sid
      })
    }).then(r=>r.text());
    let j; try { j = JSON.parse(txt); } catch { throw new Error(txt || 'Failed to revoke session.'); }
    if (!j.ok){ throw new Error(j.error || 'Failed to revoke session.'); }

    const tdTs = ROW_TARGET.children[0];
    if (tdTs){
      tdTs.querySelectorAll('.pill.active,.pill.inactive,.pill.expired').forEach(n=>n.remove());
      const pill = document.createElement('span');
      pill.className='pill revoked'; pill.style.marginLeft='6px'; pill.textContent='Revoked';
      tdTs.appendChild(pill);
    }
    const actionsTd = ROW_TARGET.children[9];
    if (actionsTd){ actionsTd.innerHTML = '<span class="muted">—</span>'; }
    document.getElementById('modal-revoke').classList.remove('show');
  } catch(ex){
    err.textContent = String(ex.message || ex || 'Revoke failed.');
    err.style.display='block';
    console.error('Revoke error:', ex);
  } finally { unlockButton(btn); }
  return false;
}

/* ---------- GLOBAL revoke all ---------- */
document.getElementById('btn-global-signout')?.addEventListener('click', ()=>{
  document.getElementById('ga-code').value='';
  document.getElementById('ga-pass').value='';
  document.getElementById('ga-err').style.display='none';
  showModalById('modal-all');
});
async function doRevokeAll(e){
  e.preventDefault();
  const code = (document.getElementById('ga-code').value||'').trim();
  const pass = (document.getElementById('ga-pass').value||'');
  const btn  = document.getElementById('ga-submit');
  const err  = document.getElementById('ga-err');
  err.style.display='none';
  lockButton(btn);
  try{
    const txt = await fetch('sessions_admin_actions.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action:'revoke_all_global',
        csrf_token: document.getElementById('ga-csrf').value,
        app_code: code,
        password: pass
      })
    }).then(r=>r.text());
    let j; try { j = JSON.parse(txt); } catch { throw new Error(txt || 'Failed to sign out all sessions.'); }
    if (!j.ok){ throw new Error(j.error || 'Failed to sign out all sessions.'); }
    window.location.reload();
  } catch(ex){
    err.textContent = String(ex.message || ex || 'Action failed.');
    err.style.display='block';
    console.error('Revoke-all error:', ex);
  } finally { unlockButton(btn); }
  return false;
}
</script>
</body>
</html>