<?php
// ppf_nav.php — Universal left-side navigation (slide-in) for Peter Pang Fit
// Categorized, role-aware nav with Home / People / Management / System sections.

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/helpers.php';

// Prefer values from the parent page; fall back to session if needed
$role   = $USER_ROLE ?? ($_SESSION['role'] ?? 'guest');
$userId = (int)($USER_ID   ?? ($_SESSION['user_id'] ?? 0));

$roleLower   = ppf_role_key($role);
$isAdmin     = ppf_is_admin_role($role);
$hasTrainerAccess = in_array($roleLower, ['trainer', 'trainer_admin'], true);
$isTrainerAdmin = ($roleLower === 'trainer_admin');
$isClient    = ($roleLower === 'client');

// Figure out current script (case-insensitive)
$current = strtolower(basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH) ?? ''));

/**
 * Build a nav link. Active state is computed by comparing the basename of the href
 * (ignoring query string) to the current script basename.
 */
function ppf_link(string $href, string $label, string $current): string {
  $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
  $safeText = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

  // Determine active state by basename so "page.php?x=1" still matches "page.php"
  $hrefPath = parse_url($href, PHP_URL_PATH) ?? $href;
  $hrefBase = strtolower(basename($hrefPath));
  $isActive = ($hrefBase === $current);

  $cls = 'ppf-nav-link' . ($isActive ? ' active' : '');
  return "<a class=\"{$cls}\" href=\"{$safeHref}\">{$safeText}</a>";
}

/**
 * Render a section with an optional collapsible body. Auto-expands if one of its items is active.
 */
function render_section(array $section, string $current): string {
  $title = $section['title'] ?? '';
  $icon  = $section['icon']  ?? '';
  $key   = preg_replace('~[^a-z0-9_-]+~i', '-', $section['key'] ?? strtolower($title));
  $items = $section['items'] ?? [];

  // Compute active state (if any item inside is active)
  $isActiveGroup = false;
  foreach ($items as $it) {
    $hrefPath = parse_url($it['href'] ?? '#', PHP_URL_PATH) ?? '';
    $hrefBase = strtolower(basename($hrefPath));
    if ($hrefBase === $current) { $isActiveGroup = true; break; }
  }

  ob_start();
  ?>
  <div class="ppf-section" data-section="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
    <button class="ppf-section-head<?php echo $isActiveGroup ? ' expanded' : ''; ?>" type="button" aria-expanded="<?php echo $isActiveGroup ? 'true':'false'; ?>">
      <span class="ppf-section-left">
        <span class="ppf-section-icon"><?php echo $icon; ?></span>
        <span class="ppf-section-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
      </span>
      <span class="ppf-section-caret" aria-hidden="true">▾</span>
    </button>
    <div class="ppf-section-body" style="<?php echo $isActiveGroup ? '' : 'display:none;'; ?>">
      <?php
        foreach ($items as $it) {
          echo ppf_link($it['href'], $it['label'], $current);

          // Optional mini submenu (tiny, indented)
          if (!empty($it['submenu']) && is_array($it['submenu'])) {
            echo '<div class="ppf-submenu-mini">';
            foreach ($it['submenu'] as $sub) {
              $subHref = htmlspecialchars($sub['href'] ?? '#', ENT_QUOTES, 'UTF-8');
              $subLbl  = htmlspecialchars($sub['label'] ?? '', ENT_QUOTES, 'UTF-8');
              echo "<a href=\"{$subHref}\">{$subLbl}</a>";
            }
            echo '</div>';
          }
        }
      ?>
    </div>
  </div>
  <?php
  return ob_get_clean();
}

/* ---------- Build sections by role ---------- */

// All roles: Home (Dashboard + My Workout Plans)
$home = [
  'key'   => 'home',
  'title' => 'Home',
  'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 3l9 8h-3v9H6v-9H3l9-8z"/></svg>',
  'items' => [
    ['href' => 'dashboard.php', 'label' => 'Dashboard'],
    ['href' => 'client_plans.php' . ($userId > 0 ? ('?user_id=' . urlencode((string)$userId)) : ''), 'label' => 'My Workout Plans'],
  ],
];

$sections = [$home];

// Admin + Trainer (including Trainer Admin): People
if ($isAdmin || $hasTrainerAccess) {
  $peopleItems = [
    [
      'href' => 'clients.php',
      'label' => 'Clients',
      'submenu' => [
        ['href' => 'clients.php?tab=active',   'label' => 'Active Clients'],
        ['href' => 'clients.php?tab=inactive', 'label' => 'Inactive Clients'],
      ],
    ],
  ];

  if ($isAdmin || $isTrainerAdmin) {
    $peopleItems[] = [
      'href' => 'trainers.php',
      'label' => 'Trainers',
      'submenu' => [
        ['href' => 'trainers.php?tab=active',   'label' => 'Active Trainers'],
        ['href' => 'trainers.php?tab=inactive', 'label' => 'Inactive Trainers'],
      ],
    ];
  }

  $peopleItems = array_merge($peopleItems, [
    [
      'href' => 'trainer_sessions.php',
      'label' => 'Sessions',
    ],
    [
      'href' => 'invites.php',
      'label' => 'Invites',
      'submenu' => [
        ['href' => 'invites.php?open=create', 'label' => 'Send Invite'],
      ],
    ],
  ]);

  $sections[] = [
    'key'   => 'people',
    'title' => 'People',
    'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.79 2.99-4S17.66 3 16 3s-3 1.79-3 4 1.34 4 3 4zm-8 0c1.66 0 2.99-1.79 2.99-4S9.66 3 8 3 5 4.79 5 7s1.34 4 3 4zm0 2c-2.33 0-7 1.17-7 3.5V20h10v-3.5C11 14.17 6.33 13 4 13zm12 0c-.29 0-.62.02-.97.05 1.16.84 1.97 2.01 1.97 3.45V20h5v-3.5C22 14.17 17.33 13 16 13z"/></svg>',
    'items' => $peopleItems,
  ];
}

// Admin + Trainer (including Trainer Admin): Management
if ($isAdmin || $hasTrainerAccess) {
  $sections[] = [
    'key'   => 'management',
    'title' => 'Management',
    'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M3 5h18v2H3V5zm2 6h14v2H5v-2zm-2 6h18v2H3v-2z"/></svg>',
    'items' => [
      [
        'href' => 'workout_plans.php',
        'label' => 'Workout Plans',
        'submenu' => [
          ['href' => 'workout_plans.php?open=create', 'label' => 'Create Plan'],
        ],
      ],
      [
        'href' => 'exercises.php',
        'label' => 'Exercises',
        'submenu' => [
          ['href' => 'exercises.php?open=create', 'label' => 'Create Exercise'],
        ],
      ],
      [
        'href' => 'categories.php',
        'label' => 'Categories',
        'submenu' => [
          ['href' => 'categories.php?open=create', 'label' => 'Create Category'],
        ],
      ],
    ],
  ];
}

$systemItems = [];
if ($isAdmin) {
  $systemItems[] = [
    'href' => 'users.php',
    'label' => 'Users',
    'submenu' => [
      ['href' => 'users.php?open=create', 'label' => 'Create User'],
    ],
  ];
  $systemItems[] = ['href' => 'sessions.php', 'label' => 'Login Sessions'];
  $systemItems[] = ['href' => 'logs.php', 'label' => 'Logs'];
}
$systemItems[] = ['href' => 'notifications.php', 'label' => 'Notifications'];

if (!empty($systemItems)) {
  $sections[] = [
    'key'   => 'system',
    'title' => 'System',
    'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 1a4 4 0 0 1 4 4v1h1a3 3 0 0 1 3 3v3a9 9 0 1 1-18 0V9a3 3 0 0 1 3-3h1V5a4 4 0 0 1 4-4z"/></svg>',
    'items' => $systemItems,
  ];
}
?>
<style>
:root {
    --ppf-nav-tint-1: color-mix(in srgb, var(--theme-swatch-1, #05070d) 55%, #020617 45%);
    --ppf-nav-tint-2: color-mix(in srgb, var(--theme-swatch-2, #0ea5e9) 48%, rgba(148, 163, 184, 0.45) 52%);
    --ppf-nav-tint-3: color-mix(in srgb, var(--theme-swatch-3, #22d3a2) 42%, rgba(148, 163, 184, 0.3) 58%);

    --ppf-nav-tone-1: color-mix(in srgb, var(--surface, rgba(9, 14, 28, 0.92)) 92%, var(--ppf-nav-tint-1) 8%);
    --ppf-nav-tone-2: color-mix(in srgb, var(--surface-alt, rgba(15, 23, 42, 0.78)) 88%, var(--ppf-nav-tint-2) 12%);
    --ppf-nav-tone-3: color-mix(in srgb, var(--surface-soft, rgba(15, 23, 42, 0.65)) 85%, var(--ppf-nav-tint-3) 15%);

    --ppf-nav-bg: linear-gradient(155deg,
      color-mix(in srgb, var(--ppf-nav-tone-1) 92%, transparent 8%) 0%,
      color-mix(in srgb, var(--ppf-nav-tone-2) 88%, transparent 12%) 52%,
      color-mix(in srgb, var(--ppf-nav-tone-3) 84%, transparent 16%) 100%);

    --ppf-nav-border: color-mix(in srgb, var(--ppf-nav-tone-2) 18%, rgba(255, 255, 255, 0.08) 82%);
    --ppf-nav-text: color-mix(in srgb, var(--text, #f8fafc) 88%, var(--ppf-nav-tint-3) 12%);
    --ppf-nav-muted: color-mix(in srgb, var(--muted, rgba(203, 213, 225, 0.78)) 82%, var(--ppf-nav-tint-2) 18%);
    --ppf-nav-active-bg: color-mix(in srgb, var(--ppf-nav-tone-3) 18%, rgba(148, 163, 184, 0.08) 82%);
    --ppf-nav-active-color: color-mix(in srgb, var(--text, #f8fafc) 78%, var(--ppf-nav-tint-3) 22%);
    --ppf-section-title: color-mix(in srgb, var(--muted-soft, rgba(148, 163, 184, 0.72)) 82%, var(--ppf-nav-tint-2) 18%);
    --ppf-nav-overlay: color-mix(in srgb, var(--ppf-nav-tone-1) 55%, rgba(2, 6, 23, 0.55) 45%);
    --ppf-nav-hover-bg: color-mix(in srgb, var(--ppf-nav-tone-2) 14%, rgba(148, 163, 184, 0.08) 86%);
    --ppf-nav-hover-border: color-mix(in srgb, var(--ppf-nav-tone-3) 18%, transparent 82%);
    --ppf-nav-submenu-bg: color-mix(in srgb, var(--ppf-nav-tone-1) 26%, rgba(2, 6, 23, 0.64) 74%);
  }
  .ppf-nav-overlay {
    position: fixed; inset: 0;
    background: var(--ppf-nav-overlay, rgba(2,6,23,0.55));
    backdrop-filter: blur(6px);
    opacity: 0; pointer-events: none;
    transition: opacity .2s ease;
    z-index: 4500;
  }
  .ppf-sidenav {
    position: fixed; top: 0; left: 0; height: 100vh; width: 280px;
    height: 100dvh;
    background: var(--ppf-nav-bg);
    background-color: color-mix(in srgb, var(--ppf-nav-tone-1) 70%, #020617 30%);
    border-right: 1px solid var(--ppf-nav-border);
    transform: translateX(-100%);
    transition: transform .24s ease, box-shadow .24s ease;
    z-index: 5000;
    display: flex; flex-direction: column;
    box-shadow: var(--shadow, 0 32px 60px rgba(2,6,23,0.55));
    overflow: hidden;
  }
  .ppf-sidenav-header {
    display:flex; align-items:center; justify-content:space-between;
    padding: 14px 16px; border-bottom: 1px solid var(--ppf-nav-border);
    color: var(--ppf-nav-text); font-weight: 600;
    letter-spacing: .3px;
  }
  .ppf-sidenav-close {
    background: transparent; border: 0; color: var(--ppf-nav-muted);
    font-size: 22px; line-height: 1; cursor: pointer;
  }
  .ppf-sidenav-body {
    padding: 10px 10px 16px; display:flex; flex-direction:column; gap: 10px; overflow:auto;
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
  }
  .ppf-sidenav-body > * {
    flex: 0 0 auto;
  }

  /* Links */
  .ppf-nav-link {
    display:block; padding: 10px 12px; border-radius: 8px;
    color: var(--ppf-nav-text); text-decoration: none;
    border: 1px solid transparent; font-weight: 500;
  }
  .ppf-nav-link:hover {
    background: var(--ppf-nav-hover-bg, rgba(56,189,248,0.08));
    border-color: var(--ppf-nav-hover-border, rgba(56,189,248,0.25));
  }
  .ppf-nav-link.active {
    background: var(--ppf-nav-active-bg);
    color: var(--ppf-nav-active-color);
    font-weight: 600;
    border-color: var(--border-strong, var(--ppf-nav-active-color));
  }

  /* Section blocks */
  .ppf-section { border: 1px solid var(--ppf-nav-border); border-radius: 12px; overflow: hidden; }
  .ppf-section + .ppf-section { margin-top: 6px; }
  .ppf-section-head {
    width: 100%;
    display:flex; align-items:center; justify-content:space-between;
    background: linear-gradient(135deg,
      color-mix(in srgb, var(--ppf-nav-tone-1) 55%, transparent 45%) 0%,
      color-mix(in srgb, var(--ppf-nav-tone-2) 52%, transparent 48%) 52%,
      color-mix(in srgb, var(--ppf-nav-tone-3) 50%, transparent 50%) 100%);
    padding: 10px 12px; border: 0; cursor: pointer; color: var(--ppf-section-title);
    font-weight: 600; letter-spacing: .2px;
  }
  .ppf-section-head:hover { background: var(--ppf-nav-hover-bg, rgba(56,189,248,0.12)); }
  .ppf-section-left { display:flex; gap: 10px; align-items:center; }
  .ppf-section-icon { opacity: .9; display:flex; }
  .ppf-section-title { font-size: 13px; text-transform: uppercase; }
  .ppf-section-caret { font-size: 12px; color: var(--ppf-nav-muted); transition: transform .18s ease; }
  .ppf-section-head.expanded .ppf-section-caret { transform: rotate(180deg); }

  .ppf-section-body { padding: 8px; background: var(--ppf-nav-submenu-bg, rgba(15,23,42,0.4)); }
  .ppf-section-body .ppf-nav-link + .ppf-nav-link { margin-top: 4px; }

  /* Tiny submenu under an item (for + Create / filters) */
  .ppf-submenu-mini {
    margin: 4px 0 8px 10px; padding-left: 10px; border-left: 1px dashed var(--ppf-nav-border);
  }
  .ppf-submenu-mini a {
    display:inline-block; font-size: 12px; color: var(--ppf-nav-muted); text-decoration:none;
    padding: 4px 6px; border-radius: 6px; border: 1px dashed var(--ppf-nav-border);
    margin-right: 6px; margin-top: 6px;
    background: color-mix(in srgb, var(--ppf-nav-tone-2) 14%, transparent 86%);
  }
  .ppf-submenu-mini a:hover {
    color: var(--ppf-nav-text);
    background: var(--ppf-nav-hover-bg, rgba(56,189,248,0.12));
    border-color: var(--ppf-nav-hover-border, rgba(56,189,248,0.35));
  }

  /* Mobile open state */
  html.ppf-nav-open .ppf-sidenav { transform: translateX(0); }
  html.ppf-nav-open .ppf-nav-overlay { opacity: 1; pointer-events: auto; }
  html.ppf-nav-open, html.ppf-nav-open body { overflow: hidden; }
</style>

<div class="ppf-nav-overlay" id="ppfNavOverlay" aria-hidden="true"></div>

<nav class="ppf-sidenav" id="ppfSidenav" aria-hidden="true" aria-label="Main Navigation">
  <div class="ppf-sidenav-header">
    <div>Navigation</div>
    <button class="ppf-sidenav-close" type="button" id="ppfNavClose" aria-label="Close menu">×</button>
  </div>
  <div class="ppf-sidenav-body">
    <?php
      if ($isClient || $isTrainer || $isAdmin) {
        foreach ($sections as $sec) {
          echo render_section($sec, $current);
        }
      } else {
        echo '<div style="color:var(--ppf-nav-muted, rgba(203,213,225,0.75));font-size:12px;padding:10px 12px">No navigation available for your role.</div>';
      }
    ?>
  </div>
</nav>

<script>
(function(){
  const html = document.documentElement;
  const overlay = document.getElementById('ppfNavOverlay');
  const nav = document.getElementById('ppfSidenav');
  const closeBtn = document.getElementById('ppfNavClose');

  function openNav(){ html.classList.add('ppf-nav-open'); overlay?.setAttribute('aria-hidden', 'false'); nav?.setAttribute('aria-hidden', 'false'); }
  function closeNav(){ html.classList.remove('ppf-nav-open'); overlay?.setAttribute('aria-hidden', 'true'); nav?.setAttribute('aria-hidden', 'true'); }
  function toggleNav(){ if (html.classList.contains('ppf-nav-open')) closeNav(); else openNav(); }

  window.PPFNav = { open: openNav, close: closeNav, toggle: toggleNav };

  overlay?.addEventListener('click', closeNav);
  closeBtn?.addEventListener('click', closeNav);
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeNav(); });

  // Delegated listener from header hamburger
  document.addEventListener('click', (e)=>{
    const ham = e.target.closest && e.target.closest('#ppfHamburger');
    if (ham) { e.preventDefault(); toggleNav(); }
  });

  // Expand/collapse sections

  document.querySelectorAll('.ppf-section-head').forEach(head => {
    head.addEventListener('click', () => {
      const expanded = head.classList.toggle('expanded');
      head.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      const body = head.nextElementSibling;
      if (body) body.style.display = expanded ? '' : 'none';
    });
  });

  // --- Auto-open create prompts when arriving via ?open=create ----------------
  const qs = new URLSearchParams(location.search);
  if (qs.get('open') === 'create') {
    const selectors = [];

    // Try multiple known IDs per page (for robustness)
    if (/workout_plans\.php$/i.test(location.pathname)) {
      selectors.push('#btnOpenCreatePlan', '#btnCreatePlan');
    }
    if (/exercises\.php$/i.test(location.pathname)) {
      selectors.push('#btnOpenCreateExercise', '#btnCreateExercise');
    }
    if (/categories\.php$/i.test(location.pathname)) {
      selectors.push('#btnCreate'); // categories.php uses this id for "Add Category"
    }
    if (/invites\.php$/i.test(location.pathname)) {
      selectors.push('#btnSendInvite', '#btnOpenInvite'); // if present on invites page
    }

    const autoClickWhenReady = (selList, tries=0) => {
      for (const sel of selList) {
        const el = document.querySelector(sel);
        if (el) { el.click(); return; }
      }
      if (tries >= 60) return; // ~3s total
      setTimeout(()=>autoClickWhenReady(selList, tries+1), 50);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => autoClickWhenReady(selectors));
    } else {
      autoClickWhenReady(selectors);
    }
  }
})();
</script>
