<?php
// sessions.php — Admin: view + manage ALL login sessions across the system
// Layout + pill tags mirror settings.php sessions table.
// Features:
// - Lists Current / Active / Inactive / Expired / Revoked sessions for ALL users
// - "Eye" icon to reveal Session ID after verifying current password (and toggle hide without password)
// - Per-row "Sign Out" → modal asks for current password, then revokes that session
// - Global red "Sign Out ALL Sessions" (except the admin's current session) → modal asks for Authenticator App code + current password
// - Notifies all admins via email on global revoke; logs to system_logs with rich context
// - VPN/iCloud pills use cached flags only (no network here); full details on hover via AJAX tooltip
//
// Updated Status Rules (per request):
// - Active:    last activity within ACTIVE_SECS (default 5 minutes)
// - Inactive:  no activity beyond ACTIVE_SECS but not yet past AUTO_LOGOUT_SECS, not current, not revoked
// - Expired:   already logged out for inactivity (i.e., last_seen_at older than AUTO_LOGOUT_SECS) OR explicitly “expired”
// - Revoked:   explicitly revoked (user-initiated or admin-initiated)
//
// Requires:
//   auth.php, db.php, logs.php, send_email.php, totp.php, geo.php (for UA parsing)
//   ppf_header.php, ppf_nav.php
//
// Tables:
//   user_sessions(session_id PK, user_id, created_at, last_seen_at, revoked TINYINT(1), revoked_at, ip, city, region, user_agent, platform, browser)
//   ip_cache(ip_bin VARBINARY(16) PK, is_vpn TINYINT(1), vpn_checked_at DATETIME, is_icloud TINYINT(1), icloud_checked_at DATETIME, ...)

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
/** “Active” window (UX nicety): recent page view within last N seconds */
$ACTIVE_SECS     = 5 * 60;       // 5 minutes
/** Auto-logout for inactivity — if last_seen_at is beyond this, we treat it as “Expired” */
$AUTO_LOGOUT_SECS = 30 * 60;     // 30 minutes (matches your inactivity logout)
// Optional long-tail clean-up threshold (kept for safety if you want very old “dead” sessions to show as expired even without explicit flags)
$FALLBACK_EXPIRE_HOURS = 24;     // purely a fallback visual

/* ---------- Cached-only flag helpers (no network/MMDB) ---------- */
function ppf_vpn_cached_only(mysqli $conn, ?string $ip): ?bool {
  if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
  try {
    if (!$st = $conn->prepare("SELECT is_vpn, vpn_checked_at FROM ip_cache WHERE ip_bin=INET6_ATON(?) LIMIT 1")) return null;
    $st->bind_param("s", $ip);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
    if (!$row) return null;
    $checked = !empty($row['vpn_checked_at']) ? strtotime((string)$row['vpn_checked_at']) : 0;
    if ($checked && (time() - $checked) <= 7*24*3600) {
      return (int)($row['is_vpn'] ?? 0) === 1;
    }
  } catch (\Throwable $e) {}
  return null;
}

function ppf_icloud_cached_only(mysqli $conn, ?string $ip): ?bool {
  if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
  try {
    if (!$st = $conn->prepare("SELECT is_icloud, icloud_checked_at FROM ip_cache WHERE ip_bin=INET6_ATON(?) LIMIT 1")) return null;
    $st->bind_param("s", $ip);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
    if (!$row) return null;
    $checked = !empty($row['icloud_checked_at']) ? strtotime((string)$row['icloud_checked_at']) : 0;
    if ($checked && (time() - $checked) <= 7*24*3600) {
      return (int)($row['is_icloud'] ?? 0) === 1;
    }
  } catch (\Throwable $e) {}
  return null;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// Auto-prune very old revoked sessions (same policy as settings.php, 7 days)
@$conn->query("DELETE FROM user_sessions WHERE revoked=1 AND last_seen_at < (NOW() - INTERVAL 7 DAY)");

$currentSid        = session_id();
$nowTs             = time();
$activeCutTs       = $nowTs - $ACTIVE_SECS;
$inactiveCutTs     = $nowTs - $AUTO_LOGOUT_SECS;
$fallbackExpireCut = date('Y-m-d H:i:s', $nowTs - $FALLBACK_EXPIRE_HOURS * 3600);

/* ---------- Load sessions ---------- */
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

    // ---------- Smart UA parsing (better iOS/iPadOS vs macOS) ----------
    $ua = (string)($row['user_agent'] ?? '');
    $row['browser_disp']  = $row['browser']  ?: ppf_detect_browser($ua);

    $parsedPlatform = ppf_detect_platform($ua);
    $storedPlatform = $row['platform'] ?: '';
    $platformDisp   = $storedPlatform ?: $parsedPlatform;

    // If stored says macOS but UA smells like iPhone/iPad/iOS → trust parser
    if (
      stripos($platformDisp, 'macos') !== false &&
      (
        stripos($ua, 'iphone') !== false ||
        stripos($ua, 'ipad')   !== false ||
        stripos($ua, 'ipod')   !== false ||
        stripos($ua, 'cpu iphone os') !== false ||
        (stripos($ua, 'cpu os') !== false && stripos($ua, 'like mac os x') !== false) ||
        stripos($ua, 'like mac os x') !== false
      )
    ) {
      $platformDisp = $parsedPlatform; // likely iPhone (iOS) or iPad (iPadOS)
    }

    $row['platform_disp'] = $platformDisp;

    // ---------- State derivation (DB-only; no network) ----------
    $lastSeen = (string)($row['last_seen_at'] ?? '');
    $lastTs   = $lastSeen ? strtotime($lastSeen) : false;

    // Treat beyond AUTO_LOGOUT_SECS as “already logged out for inactivity” => Expired
    $seenActiveWindow   = ($lastTs !== false && $lastTs >= $activeCutTs);
    $pastAutoLogout     = ($lastTs !== false && $lastTs <  $inactiveCutTs);
    $veryOldFallback    = ($lastSeen !== '' && strcmp($lastSeen, $fallbackExpireCut) < 0);

    $row['is_active']   = (!$row['is_revoked'] && $seenActiveWindow);
    $row['is_expired']  = (!$row['is_revoked'] && !$row['is_current'] && ($pastAutoLogout || $veryOldFallback));
    $row['is_inactive'] = (
      !$row['is_current'] &&
      !$row['is_revoked'] &&
      !$row['is_active']  &&
      !$row['is_expired']
    );

    // ---------- Cached network tags (iCloud has precedence over VPN) ----------
    $ip = trim((string)($row['ip'] ?? ''));
    $icloudCached = ppf_icloud_cached_only($conn, $ip);
    $vpnCached    = ppf_vpn_cached_only($conn, $ip);
    $row['is_icloud_cached'] = ($icloudCached === true);
    $row['is_vpn_cached']    = (!$row['is_icloud_cached'] && $vpnCached === true);

    $sessions[] = $row;
  }
  $rs->close();
}

/* ---------- Counts ---------- */
$counts = ['total'=>0,'current'=>0,'active'=>0,'inactive'=>0,'expired'=>0,'revoked'=>0,'icloud'=>0,'vpn'=>0];
$counts['total'] = count($sessions);
foreach ($sessions as $s) {
  if (!empty($s['is_current']))      $counts['current']++;
  if (!empty($s['is_revoked']))      $counts['revoked']++;
  elseif (!empty($s['is_active']))   $counts['active']++;
  elseif (!empty($s['is_expired']))  $counts['expired']++;
  else                                $counts['inactive']++;

  if (!empty($s['is_icloud_cached'])) $counts['icloud']++;
  elseif (!empty($s['is_vpn_cached'])) $counts['vpn']++;
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sessions · Peter Pang Fit</title>
  <style>
  :root{
    --bg:#0b0c10; --panel:#12141a; --text:#e6e8ee; --muted:#9aa3b2;
    --brand:#3b82f6; --line:#1c212b; --danger:#b91c1c; --danger-bg:#2a1617; --danger-line:#5b1b20;
    /* Gold theme (also reused for VPN/Expired pills) */
    --gold:#6b4e1b; --gold-bg:#3a2f1a; --gold-text:#ffd166;
    /* Inactive gray */
    --inactive-bg:#1b1e26; --inactive-br:#2a2f3a; --inactive-text:#cbd5e1;
  }
  html,body{ margin:0;padding:0;background:var(--bg);color:var(--text);
    font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif; overflow-x:hidden;}
  a{color:var(--brand);text-decoration:none} a:hover{text-decoration:underline}

  .wrap{width:100%;max-width:none;margin:24px auto;padding:0 12px;box-sizing:border-box;}

  .card{background:#151923;border:1px solid var(--line);border-radius:14px;padding:18px;width:100%;box-sizing:border-box;overflow:hidden;}
  .card h3{margin:0 0 10px 0;font-size:22px}
  .muted{color:var(--muted)}
  .btn{display:inline-flex;align-items:center;gap:8px;background:#2a3446;border:1px solid var(--line);
       color:var(--text);padding:10px 14px;border-radius:10px;cursor:pointer;text-decoration:none}
  .btn.brand{background:#1f2f55;border-color:#284072}
  .btn.warn{background:var(--danger-bg);border-color:var(--danger-line);color:#ffb4b4}
  .btn[disabled]{opacity:.6;cursor:not-allowed;pointer-events:none;filter:grayscale(30%);}
  .pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:#1f2430;font-size:12px}
  .pill.current{background:#2a3446}
  .pill.active{background:#1f2f55;border-color:#284072}
  .pill.inactive{ background:var(--inactive-bg); border-color:var(--inactive-br); color:var(--inactive-text); }
  .pill.expired{ background:var(--gold-bg); border-color:var(--gold); color:var(--gold-text); }
  .pill.revoked{ background:#372126; border-color:#5b1b20; color:#ffb4b4 }
  .pill.vpn{ background:var(--gold-bg); border-color:var(--gold); color:var(--gold-text); }
  /* iCloud pill: faded white bg + solid white outline */
  .pill.icloud{ background:rgba(255,255,255,0.10); border-color:#ffffff; color:#ffffff; }

  .table-wrap{overflow-x:auto;border-radius:10px;border:1px solid var(--line);background:#111521}
  table{width:100%;border-collapse:collapse;min-width:1160px}
  th,td{padding:10px;text-align:left;border-bottom:1px solid var(--line)}
  thead th{position:sticky;top:0;background:#0f1218}

  .flash{margin:0 0 16px 0;padding:12px;border-radius:10px;border:1px solid;background:#10161a}
  .flash.ok{border-color:#204a36;color:#a7f3d0}
  .flash.err{border-color:#4a2020;color:#fca5a5}
  .toolbar{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap}
  .filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .legend{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  .sid-mask{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.3px}
  .eye{cursor:pointer;opacity:.85}
  .eye:hover{opacity:1}
  .modal{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:none; align-items:center; justify-content:center; z-index:100 }
  .modal.show{ display:flex; }
  .inline-input{width:100%;background:#0f1218;border:1px solid var(--line);color:#var(--text);padding:10px;border-radius:10px;box-sizing:border-box}

  /* Tooltip bubble for IP hover */
  .ip-tip{ position:fixed; z-index:200; max-width:360px; background:#0f1218; color:var(--text);
           border:1px solid var(--line); border-radius:12px; padding:12px; box-shadow:0 6px 24px rgba(0,0,0,.45); display:none; }
  .ip-tip h4{ margin:0 0 6px 0; font-size:14px; }
  .ip-tip .row{ display:flex; gap:8px; margin:4px 0; }
  .ip-tip .k{ color:#9aa3b2; min-width:120px; } /* left-aligned "Key: Value" */
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
        <!-- Network pills -->
        <span class="pill icloud">iCloud: <?php echo (int)$counts['icloud']; ?></span>
        <span class="pill vpn">VPN: <?php echo (int)$counts['vpn']; ?></span>
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
                <?php elseif (!$s['is_current']): /* Inactive only for non-current, non-expired */ ?>
                  <span class="pill inactive" style="margin-left:6px">Inactive</span>
                <?php endif; ?>
              </td>

              <!-- Session ID: hidden until password verified; toggle hide on next eye click -->
              <td>
                <span class="sid-mask" data-hide="1">••••••••••••••••</span>
                <a class="eye" title="Reveal Session ID" href="#" onclick="return openReveal(this)">👁️</a>
                <div class="sid-full" style="display:none;font-family:ui-monospace"><?php echo h($s['session_id']); ?></div>
              </td>

              <td><?php echo (int)$s['user_id']; ?></td>
              <td class="muted"><?php echo h($roleDisp); ?></td>
              <td class="muted"><?php echo h($s['email'] ?: '—'); ?></td>
              <td class="muted"><?php echo h( (($s['city']?:'Unknown').', '.($s['region']?:'Unknown')) ); ?></td>

              <!-- IP + iCloud/VPN pill (cached-only) + hover tooltip trigger -->
              <td class="muted">
                <?php if (!empty($s['ip'])): ?>
                  <span class="ip-chip">
                    <a href="#" class="ip-hover" data-ip="<?php echo h($s['ip']); ?>" onclick="return false;"><?php echo h($s['ip']); ?></a>
                    <?php if (!empty($s['is_icloud_cached'])): ?>
                      <span class="pill icloud" title="Apple iCloud Private Relay">iCloud</span>
                    <?php elseif (!empty($s['is_vpn_cached'])): ?>
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

<!-- Tooltip bubble (one per page) -->
<div id="ip-tip" class="ip-tip" role="tooltip" aria-hidden="true"></div>

<!-- Modals (unchanged) -->

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
  const flags = data.anonymous_flags || null;
  const vpn = data.is_vpn ? '<span class="pill vpn">VPN</span>' : '';
  const icloud = data.is_icloud ? '<span class="pill icloud">iCloud</span>' : '';
  let fgrid = '';
  if (flags){
    const entries = Object.entries(flags).map(([k,v])=>(
      `<div class="row"><div class="k">${k.replaceAll('_',' ')}:</div><div>${v ? 'true' : 'false'}</div></div>`
    )).join('');
    fgrid = `<div style="margin-top:6px;border-top:1px dashed var(--line);padding-top:6px">${entries}</div>`;
  }
  return `
    <h4>IP: ${data.ip} ${icloud || vpn}</h4>
    <div class="row"><div class="k">City:</div><div>${data.city || '—'}</div></div>
    <div class="row"><div class="k">Region:</div><div>${data.region || '—'}</div></div>
    <div class="row"><div class="k">ASN/Org:</div><div>${data.asn_org || '—'}</div></div>
    <div class="row"><div class="k">Source:</div><div>${data.source || '—'}</div></div>
    ${fgrid}
  `;
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
      }).then(r=>r.json()).then(j=>{
        tipShow(tipHTML(j), e.clientX, e.clientY);

        // If iCloud/VPN detected but not shown yet (because cache unknown), add pill lazily
        try{
          const chip = el.closest('.ip-chip');
          if (!chip) return;
          if (j && j.ok) {
            if (j.is_icloud) {
              if (!chip.querySelector('.pill.icloud')) {
                chip.querySelector('.pill.vpn')?.remove();
                const span = document.createElement('span');
                span.className = 'pill icloud';
                span.title = 'Apple iCloud Private Relay';
                span.textContent = 'iCloud';
                chip.appendChild(span);
              }
            } else if (j.is_vpn) {
              if (!chip.querySelector('.pill.vpn') && !chip.querySelector('.pill.icloud')) {
                const span = document.createElement('span');
                span.className = 'pill vpn';
                span.title = 'Potential VPN / Hosting / Proxy';
                span.textContent = 'VPN';
                chip.appendChild(span);
              }
            }
          }
        }catch(_e){}
      }).catch(()=>{ tipShow('<div class="muted">Lookup failed</div>', e.clientX, e.clientY); });
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

  // hide on outside click or ESC
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

  // If currently revealed → hide immediately without password
  if (mask && mask.getAttribute('data-hide') === '0') {
    mask.setAttribute('data-hide','1');
    mask.textContent = '••••••••••••••••';
    if (full) full.style.display = 'none';
    a.title = 'Reveal Session ID';
    return false;
  }

  // Otherwise, prompt for password to reveal
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
    const res = await fetch('sessions_admin_actions.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action:'verify_password',
        csrf_token: document.getElementById('rv-csrf').value,
        password: pass
      })
    });
    const j = await res.json();
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
    err.textContent = ex.message || 'Verification failed.';
    err.style.display='block';
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
    const res = await fetch('sessions_admin_actions.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action:'revoke_one',
        csrf_token: document.getElementById('rk-csrf').value,
        password: pass,
        session_id: sid
      })
    });
    const j = await res.json();
    if (!j.ok){ throw new Error(j.error || 'Failed to revoke session.'); }
    // Update row UI
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
    err.textContent = ex.message || 'Revoke failed.';
    err.style.display='block';
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
    const res = await fetch('sessions_admin_actions.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action:'revoke_all_global',
        csrf_token: document.getElementById('ga-csrf').value,
        app_code: code,
        password: pass
      })
    });
    const j = await res.json();
    if (!j.ok){ throw new Error(j.error || 'Failed to sign out all sessions.'); }
    window.location.reload();
  } catch(ex){
    err.textContent = ex.message || 'Action failed.';
    err.style.display='block';
  } finally { unlockButton(btn); }
  return false;
}
</script>
</body>
</html>