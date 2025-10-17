<?php
// ppf_header.php — shared top-right profile menu, styled same as dashboard.php
// Sticky header version: stays visible at top on scroll.

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
  $r = strtolower((string)$role);
  $map = [
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
?>
<style>
/* ===== Shared Header, same colors as dashboard ===== */
.ppf-topbar {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 24px;
  background:#0b0c10;
  border-bottom:1px solid #1c212b;
  position: sticky;
  top: 0;
  z-index: 3000;
}
.ppf-brand { font-weight:800;font-size:22px;color:#e6e8ee;letter-spacing:.3px; }
.ppf-user { margin-left:auto;position:relative;display:flex;align-items:center; z-index: 3200; }
.ppf-chip {
  display:flex;align-items:center;gap:10px;background:#151923;border:1px solid #1c212b;
  padding:6px 12px;border-radius:999px;color:#e6e8ee;cursor:pointer;
}
.ppf-avatar { width:36px;height:36px;border-radius:999px;overflow:hidden;border:1px solid #1c212b;background:#0f1218;display:flex;align-items:center;justify-content:center; }
.ppf-avatar img {width:100%;height:100%;object-fit:cover;display:block;}
.ppf-names {display:flex;flex-direction:column;line-height:1.05}
.ppf-names .ppf-name {font-weight:600;font-size:14px}
.ppf-names .ppf-role {font-size:12px;color:#9aa3b2}
.ppf-menu {
  position:absolute;right:0;top:52px;background:#151923;border:1px solid #1c212b;border-radius:10px;
  min-width:170px;box-shadow:0 6px 20px rgba(0,0,0,.4);display:none;z-index: 3500;
}
.ppf-menu a { display:block;padding:10px 14px;color:#e6e8ee;text-decoration:none;border-bottom:1px solid #1c212b;font-size:14px; }
.ppf-menu a:last-child {border-bottom:0}
.ppf-menu a:hover {background:#1f2430}
html { scroll-padding-top: 64px; }
</style>

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
  <div class="ppf-brand">Peter Pang Fit</div>
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
        <span class="ppf-role"><?php echo h(ucfirst((string)$role)); ?></span>
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