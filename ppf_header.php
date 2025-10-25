<?php
// ppf_header.php — shared top-right profile menu, styled same as dashboard.php
// Sticky header version: stays visible at top on scroll.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_theme.php';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('avatar_src')) {
  function avatar_src(?string $val): string {
    if (!$val) return '';
    if (preg_match('#^https?://#i', $val)) return $val;   // full URL
    if ($val[0] === '/') return $val;                     // absolute web path
    if (stripos($val, 'uploads/') === 0) return '/'.$val; // relative /uploads/...
    return '/uploads/avatars/' . ltrim($val, '/');        // bare filename (legacy)
  }
}

// Pull current session/user info
$first = $USER_FIRST_NAME ?? ($_SESSION['first_name'] ?? '');
$last  = $USER_LAST_NAME  ?? ($_SESSION['last_name']  ?? '');
$name  = trim(($first . ' ' . $last)) ?: ($USER_EMAIL ?? $_SESSION['email'] ?? 'Account');
$role  = $USER_ROLE ?? ($_SESSION['role'] ?? '');
$photoRaw = $USER_PHOTO_URL ?? ($_SESSION['photo_url'] ?? '');

// Add a cache-busting query any time photo_ver changes (set in profile.php after upload)
$photoVer = (int)($_SESSION['photo_ver'] ?? 0);
$photo = avatar_src($photoRaw);
if ($photo) { $photo .= ($photoVer ? ('?v='.$photoVer) : ''); }

// Role default avatars (place files at /assets/avatars/default_{role}.png)
function role_default_avatar(?string $role): ?string {
  $r = ppf_role_key($role);
  $map = [
    'super_admin' => '/assets/avatars/default_admin.png',
    'admin'   => '/assets/avatars/default_admin.png',
    'trainer' => '/assets/avatars/default_trainer.png',
    'client'  => '/assets/avatars/default_client.png',
  ];
  if (isset($map[$r]) && file_exists($_SERVER['DOCUMENT_ROOT'] . $map[$r])) {
    return $map[$r];
  }
  // fallback null → SVG silhouette
  return null;
}
$roleDefault = $photo ? null : role_default_avatar($role);

$themeKey = $USER_THEME ?? ($_SESSION['theme'] ?? ppf_theme_default_key());
$themeKey = ppf_theme_resolve((string)$themeKey);
$_SESSION['theme'] = $themeKey;
$themeStyleTag = ppf_theme_render_style_block();
$themeInitScript = '<script>(function(){var theme=' . json_encode($themeKey, JSON_UNESCAPED_SLASHES) . ';function apply(){var d=document.documentElement;d.dataset.theme=theme;var b=document.body;if(b&&!b.classList.contains("ppf-themed")){b.classList.add("ppf-themed");}}if(document.readyState!=="loading"){apply();}else{document.addEventListener("DOMContentLoaded",apply);}})();</script>';
$demoAlerts = [];
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['demo_alerts']) && ppf_is_admin_role($role)) {
  $demoAlerts = array_values(array_unique(array_map('strval', $_SESSION['demo_alerts'])));
}
?>
<?php echo $themeStyleTag, "\n", $themeInitScript, "\n"; ?>
<style>
/* ===== Shared Header — refreshed gradient palette ===== */
:root {
  color-scheme: dark;
}
.ppf-topbar {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 24px;
  background:var(--panel-elevated);
  backdrop-filter:blur(18px);
  border-bottom:1px solid var(--card-border);
  box-shadow:var(--card-shadow);
  position: sticky;
  top: 0;
  z-index: 3000;
}
.ppf-demo-alerts {
  display:flex;
  flex-direction:column;
  gap:10px;
  padding:12px 24px;
  background:color-mix(in srgb, var(--danger-bg, rgba(127,29,29,0.35)) 65%, var(--panel-elevated) 35%);
  border-bottom:1px solid color-mix(in srgb, var(--danger-line, rgba(248,113,113,0.6)) 65%, var(--card-border) 35%);
  box-shadow:0 12px 28px rgba(15,23,42,0.45);
  color:color-mix(in srgb, #fecaca 70%, var(--text) 30%);
}
.ppf-demo-alerts strong {
  font-weight:700;
  letter-spacing:.01em;
}
.ppf-demo-alert {
  display:flex;
  align-items:flex-start;
  gap:10px;
  font-size:13px;
  line-height:1.45;
}
.ppf-demo-alert svg {
  flex-shrink:0;
  width:18px;
  height:18px;
  margin-top:2px;
}
.ppf-brand {
  font-weight:800;font-size:22px;color:var(--header-text, var(--text));letter-spacing:-.02em;
  text-decoration:none;display:inline-flex;align-items:center;
  transition:color .3s ease,text-shadow .3s ease,transform .3s ease;
}
.ppf-brand:hover,
.ppf-brand:focus-visible {
  color:color-mix(in srgb, var(--header-text, var(--text)) 70%, var(--theme-swatch-2, var(--brand)) 30%);
  text-shadow:0 10px 26px color-mix(in srgb, var(--theme-swatch-1, var(--brand)) 45%, transparent 55%);
  transform:translateY(-1px);
}
.ppf-brand:focus-visible {
  outline:2px solid color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 55%, transparent 45%);
  outline-offset:4px;
  border-radius:10px;
}
.ppf-user { margin-left:auto;position:relative;display:flex;align-items:center; z-index: 3200; }
.ppf-chip {
  display:flex;align-items:center;gap:10px;
  background:var(--chip-bg);
  border:1px solid var(--chip-border);
  padding:8px 14px;border-radius:999px;color:var(--text);cursor:pointer;
  transition:background .25s ease,border-color .25s ease,box-shadow .25s ease,transform .25s ease;
  box-shadow:0 12px 24px color-mix(in srgb, var(--chip-border) 35%, transparent 65%);
}
.ppf-chip:hover,
.ppf-chip:focus-visible {
  background:color-mix(in srgb, var(--chip-bg) 82%, var(--theme-swatch-2, var(--brand)) 18%);
  border-color:var(--chip-border);
  box-shadow:0 16px 32px color-mix(in srgb, var(--chip-border) 50%, transparent 50%);
  transform:translateY(-1px);
}
.ppf-avatar { width:36px;height:36px;border-radius:999px;overflow:hidden;border:1px solid var(--chip-border);background:color-mix(in srgb, var(--panel-elevated) 88%, rgba(255,255,255,0.05) 12%);display:flex;align-items:center;justify-content:center; }
.ppf-avatar img {width:100%;height:100%;object-fit:cover;display:block;}
.ppf-names {display:flex;flex-direction:column;line-height:1.05}
.ppf-names .ppf-name {font-weight:600;font-size:14px;color:var(--text)}
.ppf-names .ppf-role {font-size:12px;color:color-mix(in srgb, var(--muted) 75%, var(--text) 25%)}
.ppf-menu {
  position:absolute;right:0;top:56px;background:var(--panel-elevated);border:1px solid var(--card-border);border-radius:16px;
  min-width:190px;box-shadow:var(--card-shadow);backdrop-filter:blur(22px);display:none;z-index: 3500;
}
.ppf-menu a { display:block;padding:12px 16px;color:var(--text);text-decoration:none;border-bottom:1px solid color-mix(in srgb, var(--card-border) 55%, transparent 45%);
font-size:14px;letter-spacing:.01em; }
.ppf-menu a:last-child {border-bottom:0}
.ppf-menu a:hover {background:color-mix(in srgb, var(--panel-muted) 80%, var(--theme-swatch-2, var(--brand)) 20%);color:var(--text)}
html { scroll-padding-top: 64px; }
</style>

<style>
body.ppf-themed {
  background: var(--page-canvas);
  color: var(--text);
  transition: background-color .35s ease, color .35s ease;
}
body.ppf-themed a { color: var(--brand); }
body.ppf-themed .flash,
body.ppf-themed .alert {
  background: color-mix(in srgb, var(--panel, rgba(9, 14, 28, 0.92)) 84%, rgba(255, 255, 255, 0.06) 16%);
  border: 1px solid var(--card-border);
  color: var(--text);
}
body.ppf-themed .btn,
body.ppf-themed button,
body.ppf-themed input[type="submit"],
body.ppf-themed .pill,
body.ppf-themed .chip,
body.ppf-themed .status-pill {
  background: var(--chip-bg);
  border: 1px solid var(--chip-border);
  color: var(--text);
  box-shadow: 0 12px 20px color-mix(in srgb, var(--chip-border) 35%, transparent 65%);
}
body.ppf-themed .btn.primary,
body.ppf-themed .btn.brand {
  background: color-mix(in srgb, var(--brand) 32%, transparent 68%);
  border-color: color-mix(in srgb, var(--brand-strong, var(--brand)) 55%, transparent 45%);
  color: var(--text);
}
body.ppf-themed .btn.warn,
body.ppf-themed .status-pill.warn {
  background: color-mix(in srgb, var(--danger) 22%, transparent 78%);
  border-color: color-mix(in srgb, var(--danger) 55%, transparent 45%);
  color: color-mix(in srgb, var(--danger) 75%, var(--text) 25%);
}
body.ppf-themed .status-pill.good {
  background: color-mix(in srgb, var(--success) 24%, transparent 76%);
  border-color: color-mix(in srgb, var(--success) 55%, transparent 45%);
  color: color-mix(in srgb, var(--success) 75%, var(--text) 25%);
}
body.ppf-themed .status-pill.ok {
  background: color-mix(in srgb, var(--warning) 24%, transparent 76%);
  border-color: color-mix(in srgb, var(--warning) 50%, transparent 50%);
  color: color-mix(in srgb, var(--warning) 70%, var(--text) 30%);
}
body.ppf-themed input,
body.ppf-themed select,
body.ppf-themed textarea {
  background: var(--input-bg);
  border: 1px solid var(--input-border);
  color: var(--text);
}
body.ppf-themed table {
  background: var(--panel);
  border: 1px solid var(--card-border);
  box-shadow: var(--card-shadow);
}
body.ppf-themed th {
  background: color-mix(in srgb, var(--panel-elevated) 82%, var(--theme-swatch-2, var(--brand)) 18%);
  color: color-mix(in srgb, var(--muted) 78%, var(--text) 22%);
}
body.ppf-themed .card,
body.ppf-themed .card-resize,
body.ppf-themed .security-card,
body.ppf-themed .system-card,
body.ppf-themed .dashboard-card,
body.ppf-themed .plan-item,
body.ppf-themed .dash-settings__panel,
body.ppf-themed .dash-settings__option,
body.ppf-themed .dash-settings__columns span,
body.ppf-themed .dash-settings__close,
body.ppf-themed .dash-settings-toggle {
  background: var(--panel-elevated);
  border: 1px solid var(--card-border);
  color: var(--text);
  box-shadow: var(--card-shadow);
}
</style>


<?php if ($demoAlerts): ?>
<div class="ppf-demo-alerts" role="alert" aria-live="assertive">
  <strong>Demo Mode Notice</strong>
  <?php foreach ($demoAlerts as $demoAlert): ?>
    <div class="ppf-demo-alert">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span><?php echo h($demoAlert); ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<header class="ppf-topbar">
  <?php if (in_array(($USER_ROLE ?? 'guest'), ['admin','trainer', 'client'], true)): ?>
  <button id="ppfHamburger" type="button" aria-label="Open navigation" title="Open navigation"
          style="background:transparent;border:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;color:#e6e8ee">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="3" y1="6" x2="21" y2="6"></line>
      <line x1="3" y1="12" x2="21" y2="12"></line>
      <line x1="3" y1="18" x2="21" y2="18"></line>
    </svg>
  </button>
  <?php endif; ?>
  <a class="ppf-brand" href="/index.php">Peter Pang Fit</a>
  <div class="ppf-user">
    <div class="ppf-chip" id="ppfUserChip" aria-haspopup="true" aria-expanded="false">
      <div class="ppf-avatar">
        <?php if ($photo): ?>
          <img src="<?php echo h($photo); ?>" alt="Profile">
        <?php elseif ($roleDefault): ?>
          <img src="<?php echo h($roleDefault); ?>" alt="Profile">
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:22px;height:22px;opacity:.85">
            <circle cx="12" cy="8" r="4"></circle><path d="M4 20a8 8 0 0 1 16 0"></path>
          </svg>
        <?php endif; ?>
      </div>
      <div class="ppf-names">
        <span class="ppf-name"><?php echo h($name); ?></span>
        <span class="ppf-role"><?php echo h(ppf_role_display($role)); ?></span>
      </div>
      <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="16" height="16" style="opacity:.7">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/>
      </svg>
    </div>
    <nav class="ppf-menu" id="ppfUserMenu">
      <a href="profile.php">Profile</a>
      <a href="settings.php">Settings</a>
      <a href="logout.php">Logout</a>
    </nav>
  </div>
</header>

<script>
(function(){
  var chip=document.getElementById('ppfUserChip');
  var menu=document.getElementById('ppfUserMenu');
  if(!chip||!menu) return;
  function closeM(){menu.style.display='none';chip.setAttribute('aria-expanded','false');}
  function openM(){menu.style.display='block';chip.setAttribute('aria-expanded','true');}
  chip.addEventListener('click',function(e){ e.stopPropagation(); if(menu.style.display==='block'){closeM();} else {openM();} });
  document.addEventListener('click',function(e){ if(!menu.contains(e.target)&&!chip.contains(e.target)){closeM();} });
  window.addEventListener('keydown',function(e){ if(e.key==='Escape'){closeM();} });
})();
</script>