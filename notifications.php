<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$userId = (int)($_SESSION['user_id'] ?? 0);
$tenantId = ppf_current_tenant_id();
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');

$categories = ppf_notification_categories();
$types = ppf_notifications_types();
$priorities = ppf_notifications_priorities();
$catalog = ppf_notifications_catalog();

$catalogGrouped = [];
foreach ($categories as $key => $meta) {
  $catalogGrouped[$key] = [];
}
foreach ($catalog as $typeKey => $definition) {
  $categoryKey = strtolower((string)($definition['category'] ?? 'system'));
  if (!array_key_exists($categoryKey, $catalogGrouped)) {
    $catalogGrouped[$categoryKey] = [];
  }
  $catalogGrouped[$categoryKey][] = array_merge($definition, [
    'type_key' => $typeKey,
  ]);
}

$baseFilters = [
  'status' => 'all',
  'type' => '',
  'priority' => '',
  'date_from' => '',
  'date_to' => '',
  'q' => '',
  'category' => 'all',
];

$initialQuery = [
  'data' => [],
  'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0],
  'settings' => ppf_notifications_default_settings(),
];
try {
  $initialQuery = ppf_notifications_query($conn, $tenantId, $userId, $baseFilters, [
    'page' => 1,
    'per_page' => 25,
    'sort' => 'created_at:desc',
  ]);
} catch (Throwable $e) {
  $initialQuery = [
    'data' => [],
    'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0],
    'settings' => ppf_notifications_default_settings(),
  ];
}

try {
  $initialUnread = ppf_notifications_unread_count($conn, $tenantId, $userId, $initialQuery['settings'] ?? null);
} catch (Throwable $e) {
  $initialUnread = 0;
}

$initialState = [
  'items' => $initialQuery['data'] ?? [],
  'pagination' => $initialQuery['pagination'] ?? ['page' => 1, 'per_page' => 25, 'total' => 0],
  'settings' => $initialQuery['settings'] ?? ppf_notifications_default_settings(),
  'filters' => $baseFilters,
  'unread' => $initialUnread,
];

$initialStateJson = json_encode($initialState, JSON_UNESCAPED_SLASHES);
$categoriesJson = json_encode($categories, JSON_UNESCAPED_SLASHES);
$catalogJson = json_encode($catalogGrouped, JSON_UNESCAPED_SLASHES);
$typesJson = json_encode($types, JSON_UNESCAPED_SLASHES);
$prioritiesJson = json_encode($priorities, JSON_UNESCAPED_SLASHES);
$csrfJson = json_encode($csrfToken, JSON_UNESCAPED_SLASHES);
if ($initialStateJson === false) { $initialStateJson = '{}'; }
if ($categoriesJson === false) { $categoriesJson = '{}'; }
if ($catalogJson === false) { $catalogJson = '{}'; }
if ($typesJson === false) { $typesJson = '{}'; }
if ($prioritiesJson === false) { $prioritiesJson = '{}'; }
if ($csrfJson === false) { $csrfJson = '""'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notification Center</title>
  <style>
    :root {
      color-scheme: dark;
    }
    body {
      margin: 0;
      font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--surface, #020617);
      color: var(--text, #f8fafc);
    }
    main.wrap {
      max-width: 1180px;
      margin: 24px auto 48px;
      padding: 0 20px 60px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .subheader {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 18px;
      border-radius: 14px;
      background: color-mix(in srgb, var(--panel, rgba(15,23,42,0.82)) 85%, rgba(30,41,59,0.65) 15%);
      border: 1px solid var(--line, rgba(148,163,184,0.18));
      box-shadow: 0 18px 40px rgba(15,23,42,0.35);
      position: sticky;
      top: 76px;
      z-index: 20;
      backdrop-filter: blur(22px);
    }
    .subheader .title {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .subheader h1 {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .subheader p {
      margin: 0;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 85%, var(--text, #f8fafc) 15%);
      font-size: 14px;
    }
    .subheader .actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .ppf-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid var(--line, rgba(148,163,184,0.35));
      background: rgba(15,23,42,0.72);
      color: var(--text, #f8fafc);
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: background 0.2s ease, border 0.2s ease, color 0.2s ease;
    }
    .ppf-btn:hover,
    .ppf-btn:focus-visible {
      background: color-mix(in srgb, rgba(56,189,248,0.2) 60%, rgba(15,23,42,0.72) 40%);
      border-color: rgba(56,189,248,0.45);
      color: #f0f9ff;
      outline: none;
    }
    .ppf-btn.brand {
      background: var(--brand, rgba(14,165,233,0.85));
      border-color: var(--brand, rgba(14,165,233,0.85));
      color: #0b1120;
    }
    .ppf-btn.brand:hover,
    .ppf-btn.brand:focus-visible {
      background: color-mix(in srgb, var(--brand, rgba(14,165,233,1)) 85%, white 15%);
      border-color: transparent;
      color: #020617;
    }
    .ppf-btn[disabled],
    .ppf-btn[disabled]:hover {
      opacity: 0.55;
      cursor: not-allowed;
      background: rgba(15,23,42,0.45);
      border-color: rgba(148,163,184,0.25);
      color: rgba(148,163,184,0.75);
    }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 16px;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      border-radius: 14px;
      border: 1px solid var(--line, rgba(148,163,184,0.18));
      background: rgba(12,19,36,0.85);
      box-shadow: inset 0 0 0 1px rgba(148,163,184,0.08);
    }
    .toolbar-left,
    .toolbar-right {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }
    .toolbar select,
    .toolbar input[type="search"] {
      background: rgba(15,23,42,0.86);
      border: 1px solid rgba(148,163,184,0.3);
      border-radius: 10px;
      padding: 7px 12px;
      color: var(--text, #f8fafc);
      font-size: 14px;
      min-width: 160px;
    }
    .toolbar label {
      font-size: 12px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 82%, var(--text, #f8fafc) 18%);
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .category-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 4px 0 6px;
    }
    .category-tab {
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid rgba(148,163,184,0.22);
      background: rgba(15,23,42,0.65);
      color: rgba(226,232,240,0.92);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .category-tab.is-active {
      background: color-mix(in srgb, var(--brand, rgba(14,165,233,0.9)) 65%, rgba(15,23,42,0.65) 35%);
      border-color: rgba(56,189,248,0.6);
      color: #0b1120;
      box-shadow: 0 10px 20px rgba(14,165,233,0.28);
    }
    .category-tab:focus-visible {
      outline: 2px solid var(--brand, rgba(14,165,233,0.85));
      outline-offset: 2px;
    }
    .category-section {
      border: 1px solid rgba(148,163,184,0.16);
      border-radius: 16px;
      background: rgba(8,13,23,0.82);
      padding: 20px 20px 18px;
      margin-top: 14px;
      box-shadow: 0 18px 32px rgba(2,6,23,0.45);
    }
    .category-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 16px;
    }
    .category-header .info {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .category-header h2 {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .category-header p {
      margin: 0;
      font-size: 13px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 78%, var(--text, #f8fafc) 22%);
    }
    .notification-list {
      display: grid;
      gap: 14px;
    }
    .notification-card {
      border: 1px solid rgba(148,163,184,0.22);
      border-radius: 14px;
      padding: 16px;
      background: rgba(10,17,30,0.92);
      display: flex;
      flex-direction: column;
      gap: 12px;
      position: relative;
      transition: border 0.2s ease, transform 0.2s ease;
    }
    .notification-card.is-unread {
      border-color: rgba(56,189,248,0.45);
      box-shadow: 0 14px 30px rgba(14,165,233,0.25);
    }
    .notification-card.is-immutable::after {
      content: '\1F512';
      position: absolute;
      top: 12px;
      right: 12px;
      font-size: 16px;
      color: rgba(248,250,252,0.65);
    }
    .notification-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .notification-top h3 {
      margin: 0;
      font-size: 17px;
      font-weight: 600;
      color: #f8fafc;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      background: rgba(56,189,248,0.18);
      color: #38bdf8;
    }
    .badge[data-type="success"] {
      background: rgba(34,197,94,0.18);
      color: #4ade80;
    }
    .badge[data-type="warning"] {
      background: rgba(251,191,36,0.18);
      color: #facc15;
    }
    .badge[data-type="error"] {
      background: rgba(248,113,113,0.18);
      color: #f87171;
    }
    .badge[data-type="system"] {
      background: rgba(148,163,184,0.22);
      color: #cbd5f5;
    }
    .notification-body {
      font-size: 14px;
      line-height: 1.6;
      color: color-mix(in srgb, var(--text, #f8fafc) 86%, var(--muted, #cbd5f5) 14%);
      white-space: pre-line;
    }
    .notification-meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      font-size: 12px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 80%, var(--text, #f8fafc) 20%);
    }
    .notification-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }
    .action-link {
      background: none;
      border: none;
      padding: 0;
      margin: 0;
      color: var(--brand, #38bdf8);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
    }
    .action-link:hover,
    .action-link:focus-visible {
      text-decoration: underline;
      outline: none;
    }
    .action-link[disabled] {
      color: rgba(148,163,184,0.6);
      cursor: not-allowed;
      text-decoration: none;
    }
    .empty-state {
      border: 1px dashed rgba(148,163,184,0.28);
      border-radius: 14px;
      padding: 32px;
      text-align: center;
      color: rgba(148,163,184,0.8);
      font-size: 14px;
      line-height: 1.6;
    }
    .status-indicator {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 78%, var(--text, #f8fafc) 22%);
    }
    .status-indicator strong {
      color: var(--text, #f8fafc);
    }
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(2,6,23,0.68);
      backdrop-filter: blur(10px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      z-index: 1000;
    }
    .modal-backdrop.is-visible {
      display: flex;
    }
    .modal {
      width: min(520px, 95vw);
      background: rgba(10,17,30,0.95);
      border-radius: 18px;
      border: 1px solid rgba(148,163,184,0.25);
      box-shadow: 0 30px 60px rgba(2,6,23,0.55);
      padding: 22px 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      max-height: 90vh;
      overflow-y: auto;
    }
    .modal h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
    }
    .modal form {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .modal label {
      display: flex;
      flex-direction: column;
      gap: 6px;
      font-size: 13px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 80%, var(--text, #f8fafc) 20%);
    }
    .modal select,
    .modal input,
    .modal textarea {
      border-radius: 10px;
      border: 1px solid rgba(148,163,184,0.3);
      background: rgba(15,23,42,0.9);
      color: var(--text, #f8fafc);
      padding: 9px 12px;
      font-size: 14px;
    }
    .modal textarea {
      min-height: 120px;
      resize: vertical;
      line-height: 1.5;
    }
    .channel-options {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 4px;
    }
    .channel-options label {
      flex-direction: row;
      align-items: center;
      gap: 8px;
      font-size: 14px;
    }
    .channel-options input {
      width: 18px;
      height: 18px;
    }
    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 10px;
    }
    .meta-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 10px;
      border-radius: 999px;
      background: rgba(148,163,184,0.16);
      color: rgba(226,232,240,0.9);
      font-size: 12px;
    }
    @media (max-width: 768px) {
      .subheader {
        flex-direction: column;
        align-items: flex-start;
      }
      .toolbar {
        flex-direction: column;
        align-items: stretch;
      }
      .toolbar-left,
      .toolbar-right {
        width: 100%;
      }
      .toolbar-right {
        justify-content: flex-end;
      }
      .category-header {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <main class="wrap" data-notification-center>
    <div class="subheader">
      <div class="title">
        <h1>Notification Center</h1>
        <p>Stay up to date with workouts, billing, security alerts, and your own reminders.</p>
      </div>
      <div class="actions">
        <button type="button" class="ppf-btn" data-action="mark-all" disabled>Mark all read</button>
        <button type="button" class="ppf-btn" data-action="refresh">Refresh</button>
        <button type="button" class="ppf-btn brand" data-action="create">Create</button>
      </div>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <label>
          Status
          <select data-filter="status">
            <option value="all">All</option>
            <option value="unread">Unread</option>
            <option value="read">Read</option>
            <option value="archived">Archived</option>
          </select>
        </label>
        <label>
          Search
          <input type="search" placeholder="Search notifications" data-filter="search" />
        </label>
      </div>
      <div class="toolbar-right">
        <div class="status-indicator" data-summary>
          <strong>0</strong> unread notifications
        </div>
      </div>
    </div>

    <div class="category-tabs" data-category-tabs></div>

    <div data-category-container></div>
  </main>

  <div class="modal-backdrop" data-modal-backdrop>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="notificationModalTitle">
      <h2 id="notificationModalTitle">Create notification</h2>
      <form data-modal-form>
        <label>
          Category
          <select name="category" required data-modal-field="category"></select>
        </label>
        <label>
          Action
          <select name="action" required data-modal-field="action"></select>
        </label>
        <label>
          Title
          <input type="text" name="title" maxlength="160" data-modal-field="title" placeholder="Notification title" required />
        </label>
        <label>
          Message
          <textarea name="body" data-modal-field="body" placeholder="What should this notification say?" required></textarea>
        </label>
        <label>
          Channels
          <div class="channel-options">
            <label><input type="checkbox" checked disabled /> Notification Center</label>
            <label><input type="checkbox" value="email" data-modal-field="channel-email" /> Email</label>
          </div>
        </label>
        <div class="modal-actions">
          <button type="button" class="ppf-btn" data-modal-close>Cancel</button>
          <button type="submit" class="ppf-btn brand" data-modal-submit>Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.__PPF_NOTIFICATION_BOOTSTRAP__ = {
      state: <?php echo $initialStateJson; ?>,
      categories: <?php echo $categoriesJson; ?>,
      catalog: <?php echo $catalogJson; ?>,
      types: <?php echo $typesJson; ?>,
      priorities: <?php echo $prioritiesJson; ?>,
      csrf: <?php echo $csrfJson; ?>
    };
  </script>
  <script>
  (function(){
    var bootstrap = window.__PPF_NOTIFICATION_BOOTSTRAP__ || {};
    var initialState = bootstrap.state || {};
    var state = {
      items: Array.isArray(initialState.items) ? initialState.items.slice() : [],
      pagination: initialState.pagination || { page: 1, per_page: 25, total: 0 },
      filters: Object.assign({}, initialState.filters || {}),
      unread: (typeof initialState.unread === 'number') ? initialState.unread : 0,
      category: 'all',
      searchTimeout: null,
      loading: false
    };
    var categories = bootstrap.categories || {};
    var catalog = bootstrap.catalog || {};
    var types = bootstrap.types || {};
    var csrf = bootstrap.csrf || '';
    var center = document.querySelector('[data-notification-center]');
    if (!center) { return; }
    var tabsContainer = center.querySelector('[data-category-tabs]');
    var categoryContainer = center.querySelector('[data-category-container]');
    var statusSelect = center.querySelector('[data-filter="status"]');
    var searchInput = center.querySelector('[data-filter="search"]');
    var summaryEl = center.querySelector('[data-summary]');
    var markAllBtn = center.querySelector('[data-action="mark-all"]');
    var refreshBtn = center.querySelector('[data-action="refresh"]');
    var createBtn = center.querySelector('[data-action="create"]');

    function groupItems(items){
      var grouped = {};
      Object.keys(categories).forEach(function(key){ grouped[key] = []; });
      (items || []).forEach(function(item){
        var meta = item.metadata || {};
        var cat = (meta.category || 'system').toLowerCase();
        if (!grouped[cat]) { grouped[cat] = []; }
        grouped[cat].push(item);
      });
      return grouped;
    }

    function formatDate(iso){
      if (!iso) return '';
      var date = new Date(iso.replace(' ', 'T'));
      if (isNaN(date.getTime())) {
        return iso;
      }
      return date.toLocaleString();
    }

    function renderTabs(){
      if (!tabsContainer) return;
      tabsContainer.innerHTML = '';
      var tabOrder = Object.keys(categories);
      var fragment = document.createDocumentFragment();
      var allTab = document.createElement('button');
      allTab.type = 'button';
      allTab.className = 'category-tab' + (state.category === 'all' ? ' is-active' : '');
      allTab.textContent = 'All';
      allTab.dataset.key = 'all';
      fragment.appendChild(allTab);
      tabOrder.forEach(function(key){
        var meta = categories[key] || {};
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'category-tab' + (state.category === key ? ' is-active' : '');
        btn.textContent = meta.label || key;
        btn.dataset.key = key;
        fragment.appendChild(btn);
      });
      tabsContainer.appendChild(fragment);
    }

    function renderSummary(){
      if (!summaryEl) return;
      var label = state.unread === 1 ? 'notification' : 'notifications';
      summaryEl.innerHTML = '<strong>' + state.unread + '</strong> unread ' + label;
      if (markAllBtn) {
        markAllBtn.disabled = !(state.unread > 0);
      }
    }

    function renderList(){
      if (!categoryContainer) return;
      categoryContainer.innerHTML = '';
      var grouped = groupItems(state.items);
      var categoriesToShow = [];
      if (state.category === 'all') {
        categoriesToShow = Object.keys(grouped);
      } else {
        categoriesToShow = [state.category];
      }
      var fragment = document.createDocumentFragment();
      categoriesToShow.forEach(function(catKey){
        if (!grouped[catKey] || grouped[catKey].length === 0) {
          if (state.category !== 'all') {
            var emptyWrap = document.createElement('div');
            emptyWrap.className = 'empty-state';
            emptyWrap.textContent = 'No notifications yet for this category.';
            fragment.appendChild(emptyWrap);
          }
          return;
        }
        var section = document.createElement('section');
        section.className = 'category-section';
        var header = document.createElement('div');
        header.className = 'category-header';
        var info = document.createElement('div');
        info.className = 'info';
        var catMeta = categories[catKey] || {};
        var title = document.createElement('h2');
        title.textContent = catMeta.label ? String(catMeta.label) : catKey;
        var desc = document.createElement('p');
        var descText = catMeta.description ? String(catMeta.description) : '';
        desc.textContent = descText;
        info.appendChild(title);
        if (descText) { info.appendChild(desc); }
        header.appendChild(info);
        section.appendChild(header);
        var list = document.createElement('div');
        list.className = 'notification-list';
        grouped[catKey].forEach(function(item){
          var card = document.createElement('article');
          card.className = 'notification-card' + (item.is_read ? '' : ' is-unread');
          var immutable = !!(item.metadata && item.metadata.immutable);
          if (immutable) {
            card.classList.add('is-immutable');
          }
          var top = document.createElement('div');
          top.className = 'notification-top';
          var titleEl = document.createElement('h3');
          titleEl.textContent = item.title || 'Notification';
          var badge = document.createElement('span');
          badge.className = 'badge';
          badge.dataset.type = item.type || 'info';
          var typeInfo = types[item.type || 'info'] || {};
          badge.textContent = typeInfo.label ? String(typeInfo.label) : (item.type || 'Info');
          top.appendChild(titleEl);
          top.appendChild(badge);
          card.appendChild(top);
          if (item.body) {
            var body = document.createElement('div');
            body.className = 'notification-body';
            body.textContent = item.body;
            card.appendChild(body);
          }
          var metaLine = document.createElement('div');
          metaLine.className = 'notification-meta';
          var time = document.createElement('span');
          time.textContent = formatDate(item.created_at);
          metaLine.appendChild(time);
          var metaPills = document.createElement('div');
          metaPills.className = 'notification-actions';
          var readBtn = document.createElement('button');
          readBtn.type = 'button';
          readBtn.className = 'action-link';
          readBtn.dataset.action = 'toggle-read';
          readBtn.dataset.id = item.id;
          readBtn.textContent = item.is_read ? 'Mark unread' : 'Mark read';
          metaPills.appendChild(readBtn);
          var emailBtn = document.createElement('button');
          emailBtn.type = 'button';
          emailBtn.className = 'action-link';
          emailBtn.dataset.action = 'toggle-email';
          emailBtn.dataset.id = item.id;
          var sendEmail = !!(item.metadata && item.metadata.send_email);
          emailBtn.textContent = sendEmail ? 'Email enabled' : 'Email disabled';
          metaPills.appendChild(emailBtn);
          if (!immutable) {
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'action-link';
            editBtn.dataset.action = 'edit';
            editBtn.dataset.id = item.id;
            editBtn.textContent = 'Edit';
            metaPills.appendChild(editBtn);
            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'action-link';
            deleteBtn.dataset.action = 'delete';
            deleteBtn.dataset.id = item.id;
            deleteBtn.textContent = 'Delete';
            metaPills.appendChild(deleteBtn);
          } else {
            var lockPill = document.createElement('span');
            lockPill.className = 'meta-pill';
            lockPill.textContent = 'Security policy';
            metaPills.appendChild(lockPill);
          }
          metaLine.appendChild(metaPills);
          card.appendChild(metaLine);
          list.appendChild(card);
        });
        section.appendChild(list);
        fragment.appendChild(section);
      });
      if (!fragment.children.length) {
        var empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.innerHTML = 'No notifications found. Use <strong>Create</strong> to add your first reminder.';
        fragment.appendChild(empty);
      }
      categoryContainer.appendChild(fragment);
    }

    function renderAll(){
      renderTabs();
      renderSummary();
      renderList();
    }

    function buildParams(){
      var params = new URLSearchParams();
      var status = state.filters.status || 'all';
      if (status && status !== 'all') params.set('status', status);
      if (state.category && state.category !== 'all') params.set('category', state.category);
      var search = state.filters.q || '';
      if (search) params.set('q', search);
      params.set('page', state.pagination.page || 1);
      params.set('per_page', state.pagination.per_page || 25);
      params.set('sort', 'created_at:desc');
      return params;
    }

    function fetchNotifications(){
      state.loading = true;
      var params = buildParams();
      return fetch('api/notifications/index.php?' + params.toString(), {
        headers: { 'Accept': 'application/json' }
      }).then(function(res){
        if (!res.ok) throw new Error('Failed to load');
        return res.json();
      }).then(function(json){
        if (!json) return;
        state.items = Array.isArray(json.data) ? json.data : [];
        state.pagination = json.pagination || state.pagination;
        if (json.filters && typeof json.filters.status !== 'undefined') {
          state.filters.status = json.filters.status || 'all';
        }
        if (typeof json.unread === 'number') {
          state.unread = json.unread;
        }
        renderAll();
      }).catch(function(){
        // silent
      }).finally(function(){
        state.loading = false;
      });
    }

    function postJson(path, method, payload){
      var headers = { 'Content-Type': 'application/json' };
      if (csrf) headers['X-CSRF-Token'] = csrf;
      return fetch(path, {
        method: method || 'POST',
        headers: headers,
        body: payload ? JSON.stringify(payload) : undefined
      }).then(function(res){
        if (!res.ok) throw res;
        return res.json();
      });
    }

    function handleTabClick(event){
      var btn = event.target.closest('.category-tab');
      if (!btn) return;
      var key = btn.dataset.key || 'all';
      if (state.category === key) return;
      state.category = key;
      renderAll();
      fetchNotifications();
    }

    function handleStatusChange(){
      state.filters.status = statusSelect.value;
      fetchNotifications();
    }

    function handleSearchInput(){
      if (state.searchTimeout) {
        clearTimeout(state.searchTimeout);
      }
      state.searchTimeout = setTimeout(function(){
        state.filters.q = searchInput.value.trim();
        fetchNotifications();
      }, 300);
    }

    function handleActionClick(event){
      var btn = event.target.closest('.action-link');
      if (!btn) return;
      var id = parseInt(btn.dataset.id || '0', 10);
      if (!id) return;
      var action = btn.dataset.action;
      if (action === 'toggle-read') {
        var item = state.items.find(function(it){ return it.id === id; });
        var shouldRead = !(item && item.is_read);
        postJson('api/notifications/index.php/' + id + '/' + (shouldRead ? 'read' : 'unread'), 'PATCH').then(function(json){
          if (json && json.data) {
            state.items = state.items.map(function(entry){ return entry.id === id ? json.data : entry; });
            state.unread = typeof json.unread === 'number' ? json.unread : state.unread;
            renderAll();
          }
        }).catch(function(){});
      } else if (action === 'toggle-email') {
        var item2 = state.items.find(function(it){ return it.id === id; });
        var next = !(item2 && item2.metadata && item2.metadata.send_email);
        postJson('api/notifications/index.php/' + id + '/channels', 'PATCH', { email: next }).then(function(json){
          if (json && json.data) {
            state.items = state.items.map(function(entry){ return entry.id === id ? json.data : entry; });
            renderAll();
          }
        }).catch(function(){});
      } else if (action === 'edit') {
        openModal('edit', id);
      } else if (action === 'delete') {
        if (!confirm('Delete this notification?')) return;
        postJson('api/notifications/index.php/' + id, 'DELETE').then(function(){
          state.items = state.items.filter(function(entry){ return entry.id !== id; });
          fetchNotifications();
        }).catch(function(){});
      }
    }

    if (tabsContainer) {
      tabsContainer.addEventListener('click', handleTabClick);
    }
    if (statusSelect) {
      statusSelect.value = state.filters.status || 'all';
      statusSelect.addEventListener('change', handleStatusChange);
    }
    if (searchInput) {
      searchInput.value = state.filters.q || '';
      searchInput.addEventListener('input', handleSearchInput);
    }
    if (markAllBtn) {
      markAllBtn.addEventListener('click', function(){
        postJson('api/notifications/index.php/bulk', 'PATCH', { scope: 'all', operation: 'read' }).then(function(json){
          if (json && Array.isArray(json.processed)) {
            fetchNotifications();
          }
        }).catch(function(){});
      });
    }
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function(){
        fetchNotifications();
      });
    }

    var modalBackdrop = document.querySelector('[data-modal-backdrop]');
    var modalForm = modalBackdrop ? modalBackdrop.querySelector('[data-modal-form]') : null;
    var modalTitle = modalBackdrop ? modalBackdrop.querySelector('#notificationModalTitle') : null;
    var categoryField = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="category"]') : null;
    var actionField = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="action"]') : null;
    var titleField = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="title"]') : null;
    var bodyField = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="body"]') : null;
    var emailField = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="channel-email"]') : null;
    var modalSubmit = modalBackdrop ? modalBackdrop.querySelector('[data-modal-submit]') : null;
    var closeBtn = modalBackdrop ? modalBackdrop.querySelector('[data-modal-close]') : null;
    var currentModalMode = 'create';
    var editingId = null;

    function populateCategoryField(){
      if (!categoryField) return;
      categoryField.innerHTML = '';
      Object.keys(categories).forEach(function(key){
        var opt = document.createElement('option');
        opt.value = key;
        var meta = categories[key] || {};
        opt.textContent = meta.label ? String(meta.label) : key;
        categoryField.appendChild(opt);
      });
      if (!categories['custom']) {
        var opt = document.createElement('option');
        opt.value = 'custom';
        opt.textContent = 'Custom';
        categoryField.appendChild(opt);
      }
    }

    function getActionDefinition(catKey, actionKey){
      var list = catalog[catKey] || [];
      for (var i = 0; i < list.length; i++) {
        if (list[i] && list[i].type_key === actionKey) {
          return list[i];
        }
      }
      return null;
    }

    function populateActionField(catKey){
      if (!actionField) return;
      actionField.innerHTML = '';
      var options = catalog[catKey] || [];
      if (catKey === 'custom' || options.length === 0) {
        var customOpt = document.createElement('option');
        customOpt.value = 'custom.manual';
        customOpt.textContent = 'Custom message';
        actionField.appendChild(customOpt);
      }
      options.forEach(function(def){
        var opt = document.createElement('option');
        opt.value = def.type_key;
        opt.textContent = def.title || def.type_key;
        actionField.appendChild(opt);
      });
    }

    function applyActionDefaults(catKey, actionKey){
      if (!titleField || !bodyField) return;
      if (!catKey) return;
      if (actionKey === 'custom.manual') {
        if (currentModalMode === 'create') {
          if (!titleField.value) titleField.value = '';
          if (!bodyField.value) bodyField.value = '';
        }
        return;
      }
      var def = getActionDefinition(catKey, actionKey);
      if (!def) return;
      if (currentModalMode === 'create' || !titleField.value) {
        titleField.value = def.title || titleField.value;
      }
      if (currentModalMode === 'create' || !bodyField.value) {
        bodyField.value = def.body || bodyField.value;
      }
    }

    function openModal(mode, id){
      currentModalMode = mode || 'create';
      editingId = id || null;
      if (!modalBackdrop) return;
      populateCategoryField();
      var defaultCategory = 'custom';
      var defaultAction = 'custom.manual';
      var defaultTitle = '';
      var defaultBody = '';
      var defaultEmail = false;
      if (mode === 'edit' && id) {
        var existing = state.items.find(function(it){ return it.id === id; });
        if (existing) {
          var meta = existing.metadata || {};
          defaultCategory = (meta.category || 'system').toLowerCase();
          defaultAction = meta.type_key || 'custom.manual';
          defaultTitle = existing.title || '';
          defaultBody = existing.body || '';
          defaultEmail = !!meta.send_email;
        }
      }
      if (!categories[defaultCategory]) {
        defaultCategory = 'custom';
      }
      populateActionField(defaultCategory);
      categoryField.value = defaultCategory;
      if (Array.from(actionField.options).some(function(opt){ return opt.value === defaultAction; })) {
        actionField.value = defaultAction;
      }
      titleField.value = defaultTitle;
      bodyField.value = defaultBody;
      applyActionDefaults(defaultCategory, actionField.value);
      emailField.checked = !!defaultEmail;
      if (modalTitle) {
        modalTitle.textContent = mode === 'edit' ? 'Edit notification' : 'Create notification';
      }
      modalBackdrop.classList.add('is-visible');
      setTimeout(function(){ titleField.focus(); }, 60);
    }

    function closeModal(){
      if (!modalBackdrop) return;
      modalBackdrop.classList.remove('is-visible');
      editingId = null;
      currentModalMode = 'create';
      if (modalForm && typeof modalForm.reset === 'function') {
        modalForm.reset();
      }
    }

    if (createBtn) {
      createBtn.addEventListener('click', function(){ openModal('create'); });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', function(){ closeModal(); });
    }
    if (modalBackdrop) {
      modalBackdrop.addEventListener('click', function(evt){
        if (evt.target === modalBackdrop) {
          closeModal();
        }
      });
    }
    if (categoryField) {
      categoryField.addEventListener('change', function(){
        var cat = categoryField.value || 'custom';
        populateActionField(cat);
        if (actionField.options.length > 0) {
          actionField.value = actionField.options[0].value;
        }
        titleField.value = '';
        bodyField.value = '';
        applyActionDefaults(cat, actionField.value);
      });
    }
    if (actionField) {
      actionField.addEventListener('change', function(){
        var cat = categoryField.value || 'custom';
        applyActionDefaults(cat, actionField.value || 'custom.manual');
      });
    }
    if (modalForm) {
      modalForm.addEventListener('submit', function(evt){
        evt.preventDefault();
        var payload = {
          category: categoryField.value || 'custom',
          action: actionField.value || 'custom.manual',
          title: titleField.value.trim(),
          body: bodyField.value.trim(),
          send_email: emailField.checked
        };
        if (currentModalMode === 'edit' && editingId) {
          postJson('api/notifications/index.php/' + editingId, 'PATCH', payload).then(function(){
            closeModal();
            fetchNotifications();
          }).catch(function(){});
        } else {
          postJson('api/notifications/index.php', 'POST', payload).then(function(){
            closeModal();
            fetchNotifications();
          }).catch(function(){});
        }
      });
    }

    if (categoryContainer) {
      categoryContainer.addEventListener('click', handleActionClick);
    }

    renderAll();
    fetchNotifications();
  })();
  </script>
</body>
</html>
