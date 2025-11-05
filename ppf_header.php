<?php
// ppf_header.php — shared top-right profile menu, styled same as dashboard.php
// Sticky header version: stays visible at top on scroll.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_theme.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';

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

if (!function_exists('ppf_header_format_datetime')) {
  function ppf_header_format_datetime(?string $iso): string {
    if (!$iso) return '—';
    try {
      $dt = new DateTimeImmutable($iso);
      return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
      return (string)$iso;
    }
  }
}

// Pull current session/user info
$first = $USER_FIRST_NAME ?? ($_SESSION['first_name'] ?? '');
$last  = $USER_LAST_NAME  ?? ($_SESSION['last_name']  ?? '');
$name  = trim(($first . ' ' . $last)) ?: ($USER_EMAIL ?? $_SESSION['email'] ?? 'Account');
$role  = $USER_ROLE ?? ($_SESSION['role'] ?? '');
$roleKey = ppf_role_key($role);
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
    'trainer_admin' => '/assets/avatars/default_trainer.png',
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
$headerCsrf = '';
if (session_status() === PHP_SESSION_ACTIVE) {
  if (empty($_SESSION['csrf_token'])) {
    try {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
  }
  $headerCsrf = $_SESSION['csrf_token'];
}
$headerUserId = (int)($_SESSION['user_id'] ?? 0);
$headerNotifications = ['items' => [], 'unread' => 0];
if ($headerUserId > 0 && isset($conn) && $conn instanceof mysqli) {
  try {
    $headerNotifications = ppf_notifications_fetch_recent($conn, $headerUserId, 10);
  } catch (Throwable $e) {
    $headerNotifications = ['items' => [], 'unread' => 0];
  }
}
$headerNotifItems = $headerNotifications['items'] ?? [];
$headerNotifUnread = (int)($headerNotifications['unread'] ?? 0);
$headerNotifSubtitle = $headerNotifUnread === 0
  ? 'You are all caught up.'
  : ($headerNotifUnread === 1
      ? '1 unread notification'
      : ($headerNotifUnread . ' unread notifications'));
$headerNotifySeed = [
  'items' => array_map(function ($item) {
    return [
      'id' => (int)($item['id'] ?? 0),
      'title' => (string)($item['title'] ?? 'Notification'),
      'body' => (string)($item['body'] ?? ''),
      'type' => (string)($item['type'] ?? 'info'),
      'priority' => (int)($item['priority'] ?? 0),
      'url' => $item['url'] ?? null,
      'is_read' => (bool)($item['is_read'] ?? false),
      'created_at' => $item['created_at'] ?? '',
      'metadata' => $item['metadata'] ?? [],
    ];
  }, $headerNotifItems),
  'unread' => $headerNotifUnread,
];
$headerNotifySeedJson = json_encode($headerNotifySeed, JSON_UNESCAPED_SLASHES);
$headerNotifyTypesJson = json_encode(ppf_notifications_types(), JSON_UNESCAPED_SLASHES);
$activeSessionContext = null;
if ($headerUserId > 0 && isset($conn) && $conn instanceof mysqli) {
  try {
    if ($roleKey === 'client') {
      $active = ppf_trainer_sessions_find_active_session_for_client($conn, $headerUserId);
      if ($active) {
        $trainerName = trim(($active['trainer_first'] ?? '') . ' ' . ($active['trainer_last'] ?? ''));
        $activeSessionContext = [
          'session_id' => (int)($active['id'] ?? 0),
          'label' => $active['package_name'] ?? 'Active Session',
          'subtitle' => $trainerName ? ('Trainer: ' . $trainerName) : 'Active Session',
          'time' => ppf_header_format_datetime($active['scheduled_start'] ?? null),
          'role' => 'client',
        ];
      }
    } elseif (in_array($roleKey, ['trainer','trainer_admin'], true)) {
      $active = ppf_trainer_sessions_find_active_session_for_trainer($conn, $headerUserId);
      if ($active) {
        $clientName = trim(($active['client_first'] ?? '') . ' ' . ($active['client_last'] ?? ''));
        $activeSessionContext = [
          'session_id' => (int)($active['id'] ?? 0),
          'label' => $active['package_name'] ?? 'Active Session',
          'subtitle' => $clientName ? ('Client: ' . $clientName) : 'Active Session',
          'time' => ppf_header_format_datetime($active['scheduled_start'] ?? null),
          'role' => 'trainer',
        ];
      }
    }
  } catch (Throwable $e) {
    $activeSessionContext = null;
  }
}
$showDemoBanner = false;
if (ppf_is_admin_role($role)) {
  try {
    if (function_exists('ppf_demo_is_enabled')) {
      $showDemoBanner = (bool)ppf_demo_is_enabled();
    } elseif (function_exists('ppf_demo_get_enabled')) {
      $primaryConn = $GLOBALS['demoPrimaryConn'] ?? ($GLOBALS['PPF_DEMO_PRIMARY_CONN'] ?? null);
      if ($primaryConn instanceof mysqli) {
        $showDemoBanner = (bool)ppf_demo_get_enabled($primaryConn);
      }
    }
  } catch (Throwable $e) {
    $showDemoBanner = false;
  }
}

if (isset($conn) && $conn instanceof mysqli && function_exists('ppf_log_page_view')) {
  try {
    ppf_log_page_view($conn, $_SESSION['user_id'] ?? null, $_SESSION['email'] ?? null, $_SESSION['role'] ?? null);
  } catch (Throwable $e) {
    // Never interrupt rendering if logging fails
  }
}
?>
<?php echo $themeStyleTag, "\n", $themeInitScript, "\n"; ?>
<style>
/* ===== Shared Header — refreshed gradient palette ===== */
:root {
  color-scheme: dark;
}
.ppf-top-stack {
  position: sticky;
  top: 0;
  z-index: 4000;
  display: block;
}
.ppf-active-session-banner {
  position: relative;
  z-index: 3050;
  background: linear-gradient(135deg, color-mix(in srgb, var(--brand-strong, var(--brand)) 85%, #1e293b 15%), color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 65%, #0f172a 35%));
  color: #f8fafc;
  padding: 14px 24px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  border-bottom: 1px solid color-mix(in srgb, var(--brand-strong, var(--brand)) 60%, rgba(15,23,42,0.65) 40%);
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.35);
}
.ppf-active-session-banner strong {
  font-weight: 700;
  letter-spacing: .01em;
}
.ppf-active-session-banner span {
  font-size: 14px;
  opacity: .85;
}
.ppf-active-session-actions {
  margin-left: auto;
  display: flex;
  gap: 10px;
  align-items: center;
}
.ppf-active-session-button {
  appearance: none;
  border: 0;
  padding: 8px 16px;
  border-radius: 999px;
  font-weight: 600;
  font-size: 14px;
  background: rgba(15,23,42,0.18);
  color: inherit;
  cursor: pointer;
  transition: background .2s ease, transform .2s ease;
}
.ppf-active-session-button:hover,
.ppf-active-session-button:focus-visible {
  background: rgba(15,23,42,0.32);
  transform: translateY(-1px);
}
.ppf-active-session-button:focus-visible {
  outline: 2px solid rgba(148, 163, 184, 0.6);
  outline-offset: 2px;
}
.ppf-session-qr-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.68);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 5000;
  padding: 24px;
}
body.ppf-session-qr-open {
  overflow: hidden;
}
.ppf-session-qr-modal {
  background: var(--panel-elevated);
  border-radius: 20px;
  max-width: min(460px, 100%);
  width: 100%;
  box-shadow: 0 30px 90px rgba(15, 23, 42, 0.55);
  padding: 28px 28px 32px;
  position: relative;
  border: 1px solid color-mix(in srgb, var(--card-border) 60%, rgba(148,163,184,0.25) 40%);
}
.ppf-session-qr-close {
  position: absolute;
  top: 14px;
  right: 14px;
  background: transparent;
  border: 0;
  color: var(--text, #e2e8f0);
  font-size: 26px;
  line-height: 1;
  cursor: pointer;
  opacity: .7;
  transition: opacity .2s ease;
}
.ppf-session-qr-close:hover,
.ppf-session-qr-close:focus-visible {
  opacity: 1;
}
.ppf-session-qr-loading,
.ppf-session-qr-error,
.ppf-session-qr-ready,
.ppf-session-qr-success {
  text-align: center;
  color: var(--text, #e2e8f0);
  display: grid;
  gap: 12px;
  justify-items: center;
}
.ppf-session-qr-code {
  background: rgba(148, 163, 184, 0.12);
  padding: 16px;
  border-radius: 18px;
  display: inline-block;
}
.ppf-session-qr-code img {
  display: block;
  width: 240px;
  height: 240px;
}
.ppf-session-qr-subtitle {
  font-size: 15px;
  font-weight: 600;
  opacity: .85;
  margin: 0;
}
.ppf-session-qr-instructions {
  font-size: 14px;
  opacity: .75;
  margin: 0;
}
.ppf-session-qr-error strong {
  color: var(--danger, #fca5a5);
}
.ppf-session-qr-success-icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(74, 222, 128, 0.15);
  color: #4ade80;
  font-size: 42px;
  font-weight: 700;
}
.ppf-session-qr-success h3 {
  margin: 0;
  font-size: 22px;
}
.ppf-topbar {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 24px;
  background:var(--panel-elevated);
  backdrop-filter:blur(18px);
  border-bottom:1px solid var(--card-border);
  box-shadow:var(--card-shadow);
  position: relative;
  z-index: 3100;
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
.ppf-demo-banner {
  display:flex;
  align-items:center;
  justify-content:center;
  gap:12px;
  padding:18px 24px;
  background:linear-gradient(135deg, rgba(185,28,28,0.92), rgba(239,68,68,0.92));
  border-bottom:1px solid rgba(127,29,29,0.65);
  box-shadow:0 16px 32px rgba(15,23,42,0.55);
  color:#fee2e2;
  font-weight:700;
  letter-spacing:0.04em;
  text-transform:uppercase;
  position:relative;
  z-index:3200;
}
.ppf-demo-banner svg {
  width:22px;
  height:22px;
  flex-shrink:0;
}
.ppf-demo-banner span {
  font-size:15px;
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
.ppf-user { margin-left:auto;position:relative;display:flex;align-items:center; gap:16px; z-index: 3200; }
.ppf-notify { position:relative; }
.ppf-notify__button {
  position:relative;
  width:40px;height:40px;
  border-radius:12px;
  border:1px solid color-mix(in srgb, var(--card-border) 60%, transparent 40%);
  background:color-mix(in srgb, var(--panel-elevated) 70%, transparent 30%);
  display:inline-flex;align-items:center;justify-content:center;
  color:color-mix(in srgb, var(--muted) 70%, var(--text) 30%);
  transition:all .25s ease;
  cursor:pointer;
}
.ppf-notify__button:hover,
.ppf-notify__button:focus-visible {
  color:var(--text);
  border-color:color-mix(in srgb, var(--brand) 45%, var(--card-border) 55%);
  box-shadow:0 0 0 1px color-mix(in srgb, var(--brand) 25%, transparent 75%);
}
.ppf-notify__button svg { width:22px; height:22px; }
.ppf-notify__button.is-active svg { color:var(--brand-strong, var(--brand)); }
.ppf-notify__glyph { display:inline-flex; align-items:center; justify-content:center; }
.ppf-notify__badge {
  position:absolute;
  top:6px;
  right:6px;
  min-width:18px;
  padding:2px 5px;
  border-radius:999px;
  background:var(--brand-strong, var(--brand));
  color:#fff;
  font-size:11px;
  font-weight:700;
  line-height:1;
  box-shadow:0 0 0 2px var(--panel-elevated);
}
.ppf-notify__panel {
  position:absolute;
  top:calc(100% + 14px);
  right:0;
  width:min(380px, 90vw);
  background:color-mix(in srgb, var(--panel-elevated) 92%, transparent 8%);
  border:1px solid color-mix(in srgb, var(--card-border) 70%, transparent 30%);
  border-radius:16px;
  box-shadow:0 25px 55px rgba(15, 23, 42, 0.45);
  padding:18px;
  backdrop-filter:blur(22px);
  color:var(--text);
  z-index:3300;
}
.ppf-notify__panel[hidden] { display:none; }
.ppf-notify__header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
.ppf-notify__header h3 { margin:0; font-size:16px; font-weight:700; letter-spacing:.01em; }
.ppf-notify__header p { margin:4px 0 0; font-size:12px; color:color-mix(in srgb, var(--muted) 70%, var(--text) 30%); }
.ppf-notify__header-actions { display:flex; align-items:center; gap:8px; }
.ppf-notify__close {
  display:none;
  align-items:center;
  justify-content:center;
  width:34px;
  height:34px;
  border-radius:10px;
  border:1px solid color-mix(in srgb, var(--card-border) 70%, transparent 30%);
  background:transparent;
  color:var(--text);
  font-size:18px;
  line-height:1;
  cursor:pointer;
}
.ppf-notify__close:hover,
.ppf-notify__close:focus-visible {
  outline:none;
  border-color:color-mix(in srgb, var(--brand) 55%, transparent 45%);
  background:color-mix(in srgb, var(--brand) 18%, transparent 82%);
}
.ppf-notify__mark-all {
  background:color-mix(in srgb, var(--brand) 12%, transparent 88%);
  border:1px solid color-mix(in srgb, var(--brand) 35%, transparent 65%);
  border-radius:10px;
  color:var(--text);
  padding:6px 12px;
  font-size:12px;
  font-weight:600;
  cursor:pointer;
  transition:all .2s ease;
}
.ppf-notify__mark-all:hover:not([disabled]),
.ppf-notify__mark-all:focus-visible:not([disabled]) {
  border-color:color-mix(in srgb, var(--brand) 60%, transparent 40%);
  background:color-mix(in srgb, var(--brand) 18%, transparent 82%);
}
.ppf-notify__mark-all[disabled] {
  cursor:not-allowed;
  opacity:.5;
  border-color:color-mix(in srgb, var(--card-border) 80%, transparent 20%);
}
.ppf-notify__icon {
  background:transparent;
  border:1px solid color-mix(in srgb, var(--card-border) 70%, transparent 30%);
  border-radius:8px;
  width:32px;height:32px;
  display:inline-flex;align-items:center;justify-content:center;
  color:color-mix(in srgb, var(--muted) 70%, var(--text) 30%);
  font-size:14px;
  cursor:pointer;
}
.ppf-notify__icon:hover,
.ppf-notify__icon:focus-visible {
  color:var(--text);
  border-color:color-mix(in srgb, var(--brand) 55%, transparent 45%);
}
.ppf-notify__list { list-style:none; margin:16px 0 0; padding:0; display:flex; flex-direction:column; gap:12px; max-height:360px; overflow:auto; }
.ppf-notify__list.is-loading .ppf-notify__item { display:none; }
.ppf-notify__skeleton {
  height:70px;
  border-radius:12px;
  background:linear-gradient(90deg, rgba(148,163,184,0.15), rgba(148,163,184,0.05), rgba(148,163,184,0.15));
  background-size:200% 100%;
  animation:ppf-sheen 1.4s ease infinite;
}
@keyframes ppf-sheen { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }
.ppf-notify__item {
  padding:12px 14px;
  border-radius:12px;
  border:1px solid color-mix(in srgb, var(--card-border) 75%, transparent 25%);
  background:color-mix(in srgb, var(--panel-elevated) 85%, transparent 15%);
  display:flex;
  flex-direction:column;
  gap:10px;
  transition:background .2s ease, border-color .2s ease;
}
.ppf-notify__item.is-unread {
  border-color:color-mix(in srgb, var(--brand) 35%, var(--card-border) 65%);
  background:color-mix(in srgb, var(--brand) 12%, var(--panel-elevated) 88%);
}
.ppf-notify__topline { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.ppf-notify__title { font-size:14px; font-weight:600; color:var(--text); margin:0; }
.ppf-notify__tag {
  font-size:11px;
  font-weight:600;
  padding:3px 8px;
  border-radius:999px;
  text-transform:uppercase;
  letter-spacing:.06em;
}
.ppf-notify__tag[data-type="info"] { background:rgba(56,189,248,0.18); color:#0ea5e9; }
.ppf-notify__tag[data-type="success"] { background:rgba(34,197,94,0.18); color:#22c55e; }
.ppf-notify__tag[data-type="warning"] { background:rgba(251,191,36,0.18); color:#f59e0b; }
.ppf-notify__tag[data-type="error"] { background:rgba(248,113,113,0.18); color:#f87171; }
.ppf-notify__tag[data-type="system"] { background:rgba(148,163,184,0.25); color:#94a3b8; }
.ppf-notify__teaser { font-size:13px; color:color-mix(in srgb, var(--text) 88%, var(--muted) 12%); line-height:1.45; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.ppf-notify__meta { display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:12px; color:color-mix(in srgb, var(--muted) 75%, var(--text) 25%); }
.ppf-notify__actions { display:flex; align-items:center; gap:10px; }
.ppf-notify__actions button {
  background:transparent;
  border:0;
  color:var(--brand);
  font-weight:600;
  cursor:pointer;
  font-size:12px;
  padding:0;
}
.ppf-notify__actions button:hover,
.ppf-notify__actions button:focus-visible { text-decoration:underline; }
.ppf-notify__footer { text-align:right; font-size:13px; margin-top:12px; }
.ppf-notify__footer a { color:var(--brand); text-decoration:none; font-weight:600; }
.ppf-notify__footer a:hover { text-decoration:underline; }
.ppf-notify__empty { font-size:13px; color:color-mix(in srgb, var(--muted) 75%, var(--text) 25%); text-align:center; padding:28px 0; }

@media (max-width: 640px) {
  .ppf-notify { position: static; }
  .ppf-notify__panel {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    border: none;
    box-shadow: none;
    padding: 24px 20px 28px;
    max-height: none;
    overflow-y: auto;
  }
  .ppf-notify__list { max-height: none; }
  .ppf-notify__close { display: inline-flex; }
  .ppf-notify__header { align-items: center; }
}

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
body.ppf-themed button:not(.sort-btn),
body.ppf-themed input[type="submit"],
body.ppf-themed .pill,
body.ppf-themed .chip,
body.ppf-themed .status-pill {
  background: var(--chip-bg);
  border: 1px solid var(--chip-border);
  color: var(--text);
  box-shadow: 0 12px 20px color-mix(in srgb, var(--chip-border) 35%, transparent 65%);
  transition: background-color 0.25s ease, border-color 0.25s ease,
    box-shadow 0.25s ease, color 0.25s ease, transform 0.2s ease;
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
body.ppf-themed .btn:hover,
body.ppf-themed .btn:focus-visible,
body.ppf-themed button:not(.sort-btn):hover,
body.ppf-themed button:not(.sort-btn):focus-visible,
body.ppf-themed input[type="submit"]:hover,
body.ppf-themed input[type="submit"]:focus-visible {
  background: color-mix(in srgb, var(--chip-bg) 70%, var(--theme-swatch-2, var(--brand)) 30%);
  border-color: color-mix(in srgb, var(--chip-border) 45%, var(--theme-swatch-2, var(--brand)) 55%);
  box-shadow: 0 18px 36px color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 32%, transparent 68%);
  color: var(--text);
  filter: none;
  transform: translateY(-1px);
}
body.ppf-themed .btn:focus-visible,
body.ppf-themed button:not(.sort-btn):focus-visible,
body.ppf-themed input[type="submit"]:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 60%, transparent 40%);
  outline-offset: 2px;
}
body.ppf-themed .btn.primary:hover,
body.ppf-themed .btn.primary:focus-visible,
body.ppf-themed .btn.brand:hover,
body.ppf-themed .btn.brand:focus-visible {
  background: color-mix(in srgb, var(--brand) 78%, var(--theme-swatch-3, var(--primary, var(--brand))) 22%);
  border-color: color-mix(in srgb, var(--brand-strong, var(--brand)) 65%, var(--theme-swatch-3, var(--primary, var(--brand))) 35%);
  box-shadow: 0 20px 40px color-mix(in srgb, var(--brand) 40%, transparent 60%);
  color: #fff;
  filter: none;
}
body.ppf-themed .btn.primary:focus-visible,
body.ppf-themed .btn.brand:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--brand-strong, var(--brand)) 60%, transparent 40%);
  outline-offset: 2px;
}
body.ppf-themed .btn.warn:hover,
body.ppf-themed .btn.warn:focus-visible {
  background: color-mix(in srgb, var(--danger) 62%, transparent 38%);
  border-color: color-mix(in srgb, var(--danger) 68%, transparent 32%);
  box-shadow: 0 18px 36px color-mix(in srgb, var(--danger) 42%, transparent 58%);
  color: color-mix(in srgb, #ffffff 75%, var(--danger) 25%);
  filter: none;
}
body.ppf-themed .btn.warn:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--danger) 65%, transparent 35%);
  outline-offset: 2px;
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
  font-size: 18px;
  font-weight: 600;
  letter-spacing: .01em;
}
body.ppf-themed .sort-btn {
  font-size: 18px;
  font-weight: 600;
  letter-spacing: .01em;
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
body.ppf-themed .tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
body.ppf-themed .tabs .tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 34px;
  padding: 8px 14px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--chip-border) 65%, transparent 35%);
  background: color-mix(in srgb, var(--panel-muted) 85%, transparent 15%);
  color: color-mix(in srgb, var(--muted) 78%, var(--text) 22%);
  font-weight: 600;
  letter-spacing: .01em;
  transition: background-color 0.25s ease, border-color 0.25s ease,
    box-shadow 0.25s ease, color 0.25s ease, transform 0.2s ease;
}
body.ppf-themed .tabs .tab:hover,
body.ppf-themed .tabs .tab:focus-visible {
  background: color-mix(in srgb, var(--chip-bg) 65%, var(--theme-swatch-2, var(--brand)) 35%);
  border-color: color-mix(in srgb, var(--chip-border) 40%, var(--theme-swatch-2, var(--brand)) 60%);
  color: var(--text);
  box-shadow: 0 16px 32px color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 32%, transparent 68%);
  transform: translateY(-1px);
}
body.ppf-themed .tabs .tab:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 60%, transparent 40%);
  outline-offset: 2px;
}
body.ppf-themed .tabs .tab.active {
  background: color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 78%, var(--theme-swatch-3, var(--primary, var(--brand))) 22%);
  border-color: color-mix(in srgb, var(--brand-strong, var(--brand)) 60%, var(--theme-swatch-2, var(--brand)) 40%);
  color: #fff;
  box-shadow: 0 18px 38px color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 40%, transparent 60%);
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

<div class="ppf-top-stack<?php echo $showDemoBanner ? ' has-demo-banner' : ''; ?>">
<?php if ($showDemoBanner): ?>
  <div class="ppf-demo-banner" role="alert" aria-live="assertive">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10"></circle>
      <line x1="12" y1="8" x2="12" y2="12"></line>
      <line x1="12" y1="16" x2="12.01" y2="16"></line>
    </svg>
    <span>Demo Mode is Enabled</span>
  </div>
<?php endif; ?>

<header class="ppf-topbar">
  <?php if (ppf_is_admin_role($roleKey) || in_array($roleKey, ['trainer','trainer_admin','client'], true)): ?>
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
    <?php if ($headerUserId > 0): ?>
    <div class="ppf-notify" data-notify data-csrf="<?php echo h($headerCsrf); ?>">
      <button type="button" class="ppf-notify__button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" data-notify-toggle>
        <span class="ppf-notify__glyph" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 01-3.46 0"></path>
          </svg>
        </span>
        <span class="ppf-notify__badge" data-notify-badge hidden>0</span>
      </button>
      <div class="ppf-notify__panel" role="dialog" aria-label="Notifications" hidden data-notify-panel>
        <div class="ppf-notify__header">
          <div>
            <h3>Notifications</h3>
            <p data-notify-subtitle>Loading…</p>
          </div>
          <div class="ppf-notify__header-actions">
            <button type="button" class="ppf-notify__icon" title="Refresh" aria-label="Refresh" data-notify-refresh>⟳</button>
            <button type="button" class="ppf-notify__mark-all" data-notify-mark-all disabled>Mark all read</button>
            <button type="button" class="ppf-notify__close" data-notify-close aria-label="Close notifications">×</button>
          </div>
        </div>
        <ul class="ppf-notify__list is-loading" data-notify-list>
          <li class="ppf-notify__skeleton" aria-hidden="true"></li>
          <li class="ppf-notify__skeleton" aria-hidden="true"></li>
          <li class="ppf-notify__skeleton" aria-hidden="true"></li>
        </ul>
        <div class="ppf-notify__footer">
          <a href="notifications.php">View all</a>
        </div>
      </div>
    </div>
    <script type="application/json" id="ppf-notify-bootstrap"><?php echo $headerNotifySeedJson; ?></script>
    <script type="application/json" id="ppf-notify-types"><?php echo $headerNotifyTypesJson; ?></script>
    <?php endif; ?>
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
<?php if ($activeSessionContext): ?>
  <div class="ppf-active-session-banner" role="status" aria-live="polite">
    <div style="display:flex;flex-direction:column;gap:2px;">
      <strong>Active Session</strong>
      <span><?php echo h($activeSessionContext['label']); ?></span>
    </div>
    <div style="display:flex;flex-direction:column;gap:2px;min-width:180px;">
      <span><?php echo h($activeSessionContext['subtitle']); ?></span>
      <span><?php echo h($activeSessionContext['time']); ?></span>
    </div>
    <div class="ppf-active-session-actions">
      <button type="button"
              class="ppf-active-session-button"
              data-session-qr-trigger="1"
              data-session-qr-session-id="<?php echo h($activeSessionContext['session_id']); ?>"
              data-session-qr-label="<?php echo h($activeSessionContext['label']); ?>">
        Show QR Code
      </button>
    </div>
  </div>
<?php endif; ?>
</div>

<script src="trainer_sessions_qr.js"></script>
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
(function(){
  var container=document.querySelector('[data-notify]');
  if(!container) return;
  var toggleBtn=container.querySelector('[data-notify-toggle]');
  var panel=container.querySelector('[data-notify-panel]');
  if(!toggleBtn||!panel) return;
  var listEl=container.querySelector('[data-notify-list]');
  var subtitleEl=container.querySelector('[data-notify-subtitle]');
  var badgeEl=container.querySelector('[data-notify-badge]');
  var markAllBtn=container.querySelector('[data-notify-mark-all]');
  var refreshBtn=container.querySelector('[data-notify-refresh]');
  var closeBtn=container.querySelector('[data-notify-close]');
  var csrf=container.getAttribute('data-csrf')||'';
  var bootstrapScript=document.getElementById('ppf-notify-bootstrap');
  var typesScript=document.getElementById('ppf-notify-types');
  var types={};
  try{ if(typesScript){ types=JSON.parse(typesScript.textContent||'{}')||{}; } }catch(err){}
  var state={ items:[], unread:0, loading:false, settings:{} };
  try{ if(bootstrapScript){ var boot=JSON.parse(bootstrapScript.textContent||'{}'); if(boot){ state.items=boot.items||[]; state.unread=boot.unread||0; } } }catch(err){}
  var API_BASE='api/notifications/index.php';
  var STREAM_URL='api/notifications/stream.php';
  var HEALTH_URL='api/notifications/health.php';
  var limit=10;
  var isOpen=false;
  var eventSource=null;
  var pollingTimer=null;
  var connectAttempts=0;
  function subtitleText(count){ if(count<=0){ return 'You are all caught up.'; } if(count===1){ return '1 unread notification'; } return count+' unread notifications'; }
  function updateBadge(count){ if(!badgeEl) return; if(count>0){ badgeEl.textContent=String(count); badgeEl.hidden=false; toggleBtn.classList.add('is-active'); } else { badgeEl.hidden=true; toggleBtn.classList.remove('is-active'); } toggleBtn.setAttribute('aria-expanded', isOpen?'true':'false'); }
  function relativeTime(iso){ if(!iso) return ''; var date=new Date(iso.replace(' ','T')); if(isNaN(date.getTime())){ date=new Date(iso); if(isNaN(date.getTime())) return iso; } var diff=(Date.now()-date.getTime())/1000; var formats=[['year',31536000],['month',2592000],['day',86400],['hour',3600],['minute',60],['second',1]]; for(var i=0;i<formats.length;i++){ var unit=formats[i][0]; var value=formats[i][1]; if(Math.abs(diff)>=value||unit==='second'){ var delta=Math.round(diff/value); var formatter=new Intl.RelativeTimeFormat(undefined,{numeric:'auto'}); return formatter.format(-delta,unit); } } return ''; }
  function render(){ if(state.loading){ if(listEl){ listEl.classList.add('is-loading'); } if(subtitleEl){ subtitleEl.textContent='Loading…'; } if(markAllBtn){ markAllBtn.disabled=true; } return; } if(listEl){ listEl.classList.remove('is-loading'); listEl.innerHTML=''; if(!state.items || state.items.length===0){ var empty=document.createElement('li'); empty.className='ppf-notify__empty'; empty.textContent='No notifications yet.'; listEl.appendChild(empty); } else { state.items.slice(0,limit).forEach(function(item){ var li=document.createElement('li'); li.className='ppf-notify__item'+(item.is_read?' is-read':' is-unread'); li.setAttribute('data-id', String(item.id)); li.setAttribute('data-read', item.is_read ? '1':'0'); var top=document.createElement('div'); top.className='ppf-notify__topline'; var title=document.createElement('span'); title.className='ppf-notify__title'; title.textContent=item.title||'Notification'; top.appendChild(title); var tag=document.createElement('span'); tag.className='ppf-notify__tag'; var typeKey=(item.type||'info'); tag.dataset.type=typeKey; var typeInfo=types[typeKey]; tag.textContent=(typeInfo && typeInfo.label) ? typeInfo.label : typeKey; top.appendChild(tag); li.appendChild(top); if(item.body){ var teaser=document.createElement('div'); teaser.className='ppf-notify__teaser'; teaser.textContent=item.body; li.appendChild(teaser); } var meta=document.createElement('div'); meta.className='ppf-notify__meta'; var time=document.createElement('span'); time.textContent=relativeTime(item.created_at); if(item.created_at){ time.setAttribute('title', item.created_at); } meta.appendChild(time); var actions=document.createElement('div'); actions.className='ppf-notify__actions'; var toggle=document.createElement('button'); toggle.setAttribute('type','button'); toggle.dataset.action='toggle'; toggle.textContent=item.is_read?'Mark unread':'Mark read'; actions.appendChild(toggle); var archive=document.createElement('button'); archive.setAttribute('type','button'); archive.dataset.action='archive'; archive.textContent='Archive'; actions.appendChild(archive); if(item.url){ var open=document.createElement('button'); open.setAttribute('type','button'); open.dataset.action='open'; open.textContent='Open'; actions.appendChild(open); } meta.appendChild(actions); li.appendChild(meta); listEl.appendChild(li); }); } }
    updateBadge(state.unread);
    if(subtitleEl){ subtitleEl.textContent=subtitleText(state.unread); }
    if(markAllBtn){ markAllBtn.disabled = !(state.unread>0); }
  }
  function setLoading(flag){ state.loading=flag; render(); }
  function buildUrl(params){ var query=new URLSearchParams(params); return API_BASE+'?'+query.toString(); }
  function fetchList(options){ if(options===undefined) options={}; if(!options.silent){ setLoading(true); }
    var url=buildUrl({ per_page:String(limit), sort:'created_at:desc' });
    return fetch(url, { headers:{ 'Accept':'application/json' } }).then(function(res){ if(!res.ok) throw new Error('Failed'); return res.json(); }).then(function(json){ if(!json) return; state.items=json.data||[]; if(json.settings){ state.settings=json.settings; } if(typeof json.unread==='number'){ state.unread=json.unread; }
      render();
    }).catch(function(){ /* swallow */ }).finally(function(){ if(!options.silent){ state.loading=false; render(); } });
  }
  function sendRequest(path, method, body){ var headers={ 'Content-Type':'application/json' }; if(csrf){ headers['X-CSRF-Token']=csrf; } headers['Idempotency-Key']='notify-'+Date.now()+'-'+Math.random().toString(16).slice(2); return fetch(path,{ method:method, headers:headers, body:body?JSON.stringify(body):undefined }); }
  function markItem(id, read){ return sendRequest(API_BASE+'/'+id+'/'+(read?'read':'unread'),'PATCH').then(function(res){ if(!res.ok) throw new Error('failed'); return res.json(); }).then(function(json){ if(json && json.ok){ if(typeof json.unread==='number'){ state.unread=json.unread; } state.items=state.items.map(function(item){ if(item.id===id){ item.is_read=read; } return item; }); render(); } }); }
  function archiveItem(id){ return sendRequest(API_BASE+'/'+id+'/archive','PATCH',{ archived:true }).then(function(res){ if(!res.ok) throw new Error('failed'); return res.json(); }).then(function(){ state.items=state.items.filter(function(item){ return item.id!==id; }); fetchList({ silent:true }); }); }
  function handleActionClick(event){ var actionBtn=event.target.closest('[data-action]'); if(!actionBtn) return; var item=actionBtn.closest('.ppf-notify__item'); if(!item) return; var id=parseInt(item.getAttribute('data-id'),10); if(!id) return; var action=actionBtn.dataset.action; if(action==='toggle'){ var shouldRead=item.getAttribute('data-read')!=='1'; markItem(id, shouldRead).catch(function(){}); } else if(action==='archive'){ archiveItem(id).catch(function(){}); } else if(action==='open'){ var match=state.items.find(function(entry){ return entry.id===id; }); if(match && match.url){ window.open(match.url, '_blank','noopener'); } }
  }
  function openPanel(){ if(isOpen) return; isOpen=true; panel.hidden=false; toggleBtn.setAttribute('aria-expanded','true'); toggleBtn.classList.add('is-active'); if(state.settings.delivery_prefs && state.settings.delivery_prefs.auto_mark_on_open){ var unreadIds=state.items.filter(function(item){ return !item.is_read; }).map(function(item){ return item.id; }); if(unreadIds.length){ sendRequest(API_BASE+'/bulk','PATCH',{ ids:unreadIds, operation:'read' }).then(function(res){ if(res.ok){ return res.json(); } }).then(function(json){ if(json && Array.isArray(json.processed)){ unreadIds.forEach(function(id){ var entry=state.items.find(function(item){ return item.id===id; }); if(entry){ entry.is_read=true; } }); if(typeof json.unread==='number'){ state.unread=json.unread; } render(); } }).catch(function(){}); }
    }
  }
  function closePanel(){ if(!isOpen) return; isOpen=false; panel.hidden=true; toggleBtn.setAttribute('aria-expanded','false'); toggleBtn.classList.remove('is-active'); }
  toggleBtn.addEventListener('click', function(e){ e.stopPropagation(); if(isOpen){ closePanel(); } else { openPanel(); } });
  document.addEventListener('click', function(e){ if(!container.contains(e.target)){ closePanel(); } });
  window.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closePanel(); } });
  if(listEl){ listEl.addEventListener('click', handleActionClick); }
  if(markAllBtn){ markAllBtn.addEventListener('click', function(){ if(markAllBtn.disabled) return; markAllBtn.disabled=true; sendRequest(API_BASE+'/bulk','PATCH',{ scope:'all', operation:'read' }).then(function(res){ if(!res.ok) throw new Error('failed'); return res.json(); }).then(function(json){ if(json){ if(typeof json.unread==='number'){ state.unread=json.unread; } state.items=state.items.map(function(item){ item.is_read=true; return item; }); render(); } }).catch(function(){}).finally(function(){ markAllBtn.disabled=false; }); }); }
  if(refreshBtn){ refreshBtn.addEventListener('click', function(){ fetchList(); }); }
  if(closeBtn){ closeBtn.addEventListener('click', function(){ closePanel(); }); }
  function startPolling(){ if(pollingTimer) return; pollingTimer=setInterval(function(){ fetchList({ silent:true }); }, 15000); }
  function stopPolling(){ if(pollingTimer){ clearInterval(pollingTimer); pollingTimer=null; } }
  function connectStream(){ if(typeof EventSource==='undefined'){ startPolling(); return; }
    try { if(eventSource){ eventSource.close(); eventSource=null; } } catch(err){}
    stopPolling();
    connectAttempts++;
    eventSource=new EventSource(STREAM_URL);
    eventSource.addEventListener('open', function(){ connectAttempts=0; stopPolling(); });
    eventSource.addEventListener('unread_count', function(evt){ try{ var payload=JSON.parse(evt.data||'{}'); if(typeof payload.count==='number'){ state.unread=payload.count; render(); } }catch(err){} });
    eventSource.addEventListener('list_update', function(evt){ try{ var payload=JSON.parse(evt.data||'{}'); if(Array.isArray(payload.items)){ state.items=payload.items; render(); } }catch(err){} });
    eventSource.addEventListener('error', function(){ if(eventSource){ eventSource.close(); eventSource=null; }
      startPolling();
      var backoff=Math.min(60000, 5000 * Math.max(connectAttempts,1));
      setTimeout(connectStream, backoff);
    });
    eventSource.addEventListener('stream_end', function(){ if(eventSource){ eventSource.close(); eventSource=null; } startPolling(); setTimeout(connectStream, 15000); });
  }
  render();
  updateBadge(state.unread);
  fetchList({ silent:true });
  connectStream();
})();

if(typeof window!=='undefined'){
  window.__CSRF = window.__CSRF || '<?php echo h($headerCsrf); ?>';
}
</script>