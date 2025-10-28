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

$preconfiguredRules = [];
if ($userId > 0) {
  foreach ($catalog as $typeKey => $definition) {
    if (empty($definition['preconfigured'])) {
      continue;
    }
    $channels = ['center' => true, 'email' => false];
    if (!empty($definition['channels']) && is_array($definition['channels'])) {
      $channels = array_merge($channels, $definition['channels']);
    } elseif (!empty($definition['send_email'])) {
      $channels['email'] = true;
    }
    $categoryKey = ppf_notifications_valid_category((string)($definition['category'] ?? 'system'));
    $sendEmail = isset($definition['send_email']) ? (bool)$definition['send_email'] : !empty($channels['email']);
    $immutable = !empty($definition['immutable']);
    $metadata = [
      'preconfigured' => true,
      'type_key' => $typeKey,
      'category' => $categoryKey,
      'channels' => $channels,
      'send_email' => $sendEmail,
    ];
    if ($immutable) {
      $metadata['immutable'] = true;
    }
    $preconfiguredRules[$typeKey] = [
      'id' => null,
      'tenant_id' => $tenantId,
      'user_id' => $userId,
      'type_key' => $typeKey,
      'title' => (string)($definition['title'] ?? 'Notification'),
      'body' => (string)($definition['body'] ?? ''),
      'category' => $categoryKey,
      'channels' => $channels,
      'send_email' => $sendEmail,
      'priority' => (int)($definition['priority'] ?? 0),
      'immutable' => $immutable,
      'metadata' => $metadata,
      'created_at' => null,
      'updated_at' => null,
    ];
  }
}

ppf_notifications_seed_defaults($conn, $tenantId, $userId);

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
  'feed' => [
    'items' => $initialQuery['data'] ?? [],
    'pagination' => $initialQuery['pagination'] ?? ['page' => 1, 'per_page' => 25, 'total' => 0],
    'settings' => $initialQuery['settings'] ?? ppf_notifications_default_settings(),
    'filters' => $baseFilters,
    'unread' => $initialUnread,
  ],
  'rules' => []
];

try {
  $initialState['rules'] = ppf_notification_rules_list($conn, $tenantId, $userId);
} catch (Throwable $e) {
  $initialState['rules'] = [];
}

if (!is_array($initialState['rules'])) {
  $initialState['rules'] = [];
}

if (!empty($preconfiguredRules)) {
  $existingRuleKeys = [];
  foreach ($initialState['rules'] as $rule) {
    $key = isset($rule['type_key']) ? (string)$rule['type_key'] : '';
    if ($key !== '') {
      $existingRuleKeys[$key] = true;
    }
  }
  foreach ($preconfiguredRules as $typeKey => $rule) {
    if (!isset($existingRuleKeys[$typeKey])) {
      $initialState['rules'][] = $rule;
    }
  }
  if (!empty($initialState['rules'])) {
    usort($initialState['rules'], function ($a, $b) {
      $catA = strtolower((string)($a['category'] ?? ''));
      $catB = strtolower((string)($b['category'] ?? ''));
      if ($catA !== $catB) {
        return $catA <=> $catB;
      }
      $priorityA = (int)($a['priority'] ?? 0);
      $priorityB = (int)($b['priority'] ?? 0);
      if ($priorityA !== $priorityB) {
        return $priorityB <=> $priorityA;
      }
      $titleA = (string)($a['title'] ?? '');
      $titleB = (string)($b['title'] ?? '');
      return $titleA <=> $titleB;
    });
    $initialState['rules'] = array_values($initialState['rules']);
  }
}

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
    .panel {
      display: flex;
      flex-direction: column;
      gap: 22px;
      padding: 26px 24px 30px;
      border-radius: 18px;
      border: 1px solid var(--line, rgba(148,163,184,0.16));
      background: color-mix(in srgb, var(--panel, rgba(15,23,42,0.82)) 88%, rgba(30,41,59,0.6) 12%);
      box-shadow: 0 20px 45px rgba(15,23,42,0.34);
    }
    .panel-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
    }
    .panel-title {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .panel-title h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .panel-title p {
      margin: 0;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 82%, var(--text, #f8fafc) 18%);
      font-size: 13px;
      max-width: 560px;
    }
    .panel-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
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
      border-radius: 0;
      box-shadow: none;
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
    .modal-backdrop.is-active,
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
      .panel-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
      }
      .panel-actions {
        width: 100%;
        justify-content: flex-start;
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
        <button type="button" class="ppf-btn" data-feed-action="mark-all" disabled>Mark all read</button>
        <button type="button" class="ppf-btn" data-feed-action="refresh">Refresh</button>
      </div>
    </div>

    <section class="panel" data-feed-section>
      <div class="panel-header">
        <div class="panel-title">
          <h2>Inbox</h2>
          <p>Your latest alerts appear here. Use filters to focus on what's important.</p>
        </div>
        <div class="status-indicator" data-summary>
          <strong>0</strong> unread notifications
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
      </div>

      <div class="category-tabs" data-feed-tabs></div>

      <div data-feed-list></div>
    </section>

    <section class="panel" data-rules-section>
      <div class="panel-header">
        <div class="panel-title">
          <h2>Notification Rules</h2>
          <p>Customize which events create alerts and whether email copies are sent.</p>
        </div>
        <div class="panel-actions">
          <button type="button" class="ppf-btn brand" data-rule-action="create">Create rule</button>
        </div>
      </div>
      <div data-rules-container></div>
    </section>
  </main>

  <div class="modal-backdrop" data-modal-backdrop>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="notificationModalTitle">
      <h2 id="notificationModalTitle">Create notification rule</h2>
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
    var categories = bootstrap.categories || {};
    var catalog = bootstrap.catalog || {};
    var types = bootstrap.types || {};
    var csrf = bootstrap.csrf || '';
    function cloneRule(rule) {
      return JSON.parse(JSON.stringify(rule || {}));
    }

    function ruleKey(rule) {
      if (!rule) { return ''; }
      if (rule.type_key) { return String(rule.type_key); }
      if (rule.metadata && rule.metadata.type_key) { return String(rule.metadata.type_key); }
      return '';
    }

    function sortRuleList(list) {
      return list.slice().sort(function(a, b){
        var catA = ((a && (a.category || (a.metadata && a.metadata.category))) || '').toString().toLowerCase();
        var catB = ((b && (b.category || (b.metadata && b.metadata.category))) || '').toString().toLowerCase();
        if (catA !== catB) {
          return catA < catB ? -1 : 1;
        }
        var priorityA = parseInt(a && a.priority, 10);
        if (isNaN(priorityA)) { priorityA = 0; }
        var priorityB = parseInt(b && b.priority, 10);
        if (isNaN(priorityB)) { priorityB = 0; }
        if (priorityA !== priorityB) {
          return priorityB - priorityA;
        }
        var titleA = ((a && a.title) || '').toString();
        var titleB = ((b && b.title) || '').toString();
        return titleA.localeCompare(titleB);
      });
    }

    var preconfiguredRuleMap = {};
    if (Array.isArray(initialState.rules)) {
      initialState.rules.forEach(function(rule){
        if (!(rule && rule.metadata && rule.metadata.preconfigured)) { return; }
        var key = ruleKey(rule);
        if (!key || preconfiguredRuleMap[key]) { return; }
        preconfiguredRuleMap[key] = cloneRule(rule);
      });
    }

    function withPreconfiguredRules(rules) {
      var list = Array.isArray(rules) ? rules.slice() : [];
      var seen = {};
      list = list.map(function(rule){
        var key = ruleKey(rule);
        if (key) {
          seen[key] = true;
          if (preconfiguredRuleMap[key]) {
            var fallback = preconfiguredRuleMap[key];
            var merged = Object.assign({}, fallback, rule);
            var fallbackMeta = fallback.metadata || {};
            var ruleMeta = rule.metadata || {};
            merged.metadata = Object.assign({}, fallbackMeta, ruleMeta);
            return merged;
          }
        }
        return rule;
      });
      Object.keys(preconfiguredRuleMap).forEach(function(key){
        if (!seen[key]) {
          list.push(cloneRule(preconfiguredRuleMap[key]));
        }
      });
      return sortRuleList(list);
    }

    var state = {
      feed: {
        items: initialState.feed && Array.isArray(initialState.feed.items) ? initialState.feed.items.slice() : [],
        pagination: initialState.feed && initialState.feed.pagination ? initialState.feed.pagination : { page: 1, per_page: 25, total: 0 },
        filters: Object.assign({ status: 'all', type: '', priority: '', q: '', category: 'all' }, initialState.feed && initialState.feed.filters ? initialState.feed.filters : {}),
        unread: initialState.feed && typeof initialState.feed.unread === 'number' ? initialState.feed.unread : 0,
        settings: initialState.feed && initialState.feed.settings ? initialState.feed.settings : {},
        category: 'all',
        loading: false
      },
      rules: withPreconfiguredRules(Array.isArray(initialState.rules) ? initialState.rules.slice() : []),
      ruleEditing: null,
      searchTimeout: null
    };

    if (state.feed.filters && state.feed.filters.category && state.feed.filters.category !== 'all') {
      state.feed.category = state.feed.filters.category;
    }

    var center = document.querySelector('[data-notification-center]');
    if (!center) { return; }

    var feedListEl = center.querySelector('[data-feed-list]');
    var feedTabsEl = center.querySelector('[data-feed-tabs]');
    var statusSelect = center.querySelector('select[data-filter="status"]');
    var searchInput = center.querySelector('input[data-filter="search"]');
    var markAllBtn = center.querySelector('[data-feed-action="mark-all"]');
    var refreshBtn = center.querySelector('[data-feed-action="refresh"]');
    var summaryEl = center.querySelector('[data-summary]');
    var rulesContainer = center.querySelector('[data-rules-container]');
    var createRuleBtn = center.querySelector('[data-rule-action="create"]');

    var modalBackdrop = document.querySelector('[data-modal-backdrop]');
    var modalForm = modalBackdrop ? modalBackdrop.querySelector('[data-modal-form]') : null;
    var modalTitle = document.getElementById('notificationModalTitle');
    var modalClose = modalBackdrop ? modalBackdrop.querySelector('[data-modal-close]') : null;
    var modalSubmit = modalBackdrop ? modalBackdrop.querySelector('[data-modal-submit]') : null;
    var fieldCategory = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="category"]') : null;
    var fieldAction = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="action"]') : null;
    var fieldTitle = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="title"]') : null;
    var fieldBody = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="body"]') : null;
    var fieldChannelEmail = modalBackdrop ? modalBackdrop.querySelector('[data-modal-field="channel-email"]') : null;

    function formatDate(iso) {
      if (!iso) return '';
      var date = new Date((iso || '').replace(' ', 'T'));
      if (isNaN(date.getTime())) {
        return iso;
      }
      return date.toLocaleString();
    }

    function groupByCategory(items, extractor) {
      var grouped = {};
      Object.keys(categories).forEach(function(key){ grouped[key] = []; });
      (items || []).forEach(function(item){
        var key = extractor ? extractor(item) : null;
        if (!key) {
          key = (item && item.category) ? item.category : 'system';
        }
        key = String(key).toLowerCase();
        if (!grouped[key]) {
          grouped[key] = [];
        }
        grouped[key].push(item);
      });
      return grouped;
    }

    function renderSummary() {
      if (!summaryEl) return;
      var count = state.feed.unread || 0;
      var label = count === 1 ? 'notification' : 'notifications';
      summaryEl.innerHTML = '<strong>' + count + '</strong> unread ' + label;
      if (markAllBtn) {
        markAllBtn.disabled = !(count > 0);
      }
    }

    function renderFeedTabs() {
      if (!feedTabsEl) return;
      feedTabsEl.innerHTML = '';
      var fragment = document.createDocumentFragment();
      var allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.className = 'category-tab' + (state.feed.category === 'all' ? ' is-active' : '');
      allBtn.dataset.key = 'all';
      allBtn.textContent = 'All';
      fragment.appendChild(allBtn);
      Object.keys(categories).forEach(function(key){
        var meta = categories[key] || {};
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'category-tab' + (state.feed.category === key ? ' is-active' : '');
        btn.dataset.key = key;
        btn.textContent = meta.label || key;
        fragment.appendChild(btn);
      });
      feedTabsEl.appendChild(fragment);
    }

    function renderFeedList() {
      if (!feedListEl) return;
      feedListEl.innerHTML = '';
      var filtered = state.feed.items.slice();
      if (state.feed.category !== 'all') {
        filtered = filtered.filter(function(item){
          var meta = item.metadata || {};
          var cat = (meta.category || 'system').toLowerCase();
          return cat === state.feed.category;
        });
      }
      var grouped = groupByCategory(filtered, function(item){
        var meta = item.metadata || {};
        return meta.category || 'system';
      });
      var categoriesToShow = state.feed.category === 'all' ? Object.keys(categories) : [state.feed.category];
      var fragment = document.createDocumentFragment();

      if (state.feed.loading) {
        var loading = document.createElement('div');
        loading.className = 'empty-state';
        loading.textContent = 'Loading notifications...';
        fragment.appendChild(loading);
      } else {
        categoriesToShow.forEach(function(key){
          var items = grouped[key] || [];
          if (!items.length) { return; }
          var section = document.createElement('section');
          section.className = 'category-section';
          var header = document.createElement('div');
          header.className = 'category-header';
          var info = document.createElement('div');
          info.className = 'info';
          var title = document.createElement('h3');
          title.textContent = (categories[key] && categories[key].label) || key;
          info.appendChild(title);
          if (categories[key] && categories[key].description) {
            var desc = document.createElement('p');
            desc.textContent = categories[key].description;
            info.appendChild(desc);
          }
          header.appendChild(info);
          section.appendChild(header);
          var list = document.createElement('div');
          list.className = 'notification-list';
          items.forEach(function(item){
            var card = document.createElement('article');
            card.className = 'notification-card' + (item.is_read ? '' : ' is-unread');
            var top = document.createElement('div');
            top.className = 'notification-top';
            var titleEl = document.createElement('h3');
            titleEl.textContent = item.title || 'Notification';
            top.appendChild(titleEl);
            var badge = document.createElement('span');
            badge.className = 'badge';
            var typeKey = item.type || 'info';
            badge.dataset.type = typeKey;
            var typeMeta = types[typeKey] || {};
            badge.textContent = typeMeta.label || typeKey;
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
            var actions = document.createElement('div');
            actions.className = 'notification-actions';
            var readBtn = document.createElement('button');
            readBtn.type = 'button';
            readBtn.className = 'action-link';
            readBtn.dataset.feedItemAction = 'toggle-read';
            readBtn.dataset.id = item.id;
            readBtn.textContent = item.is_read ? 'Mark unread' : 'Mark read';
            actions.appendChild(readBtn);
            var archiveBtn = document.createElement('button');
            archiveBtn.type = 'button';
            archiveBtn.className = 'action-link';
            archiveBtn.dataset.feedItemAction = item.is_archived ? 'unarchive' : 'archive';
            archiveBtn.dataset.id = item.id;
            archiveBtn.textContent = item.is_archived ? 'Restore' : 'Archive';
            actions.appendChild(archiveBtn);
            if (item.url) {
              var viewLink = document.createElement('a');
              viewLink.href = item.url;
              viewLink.target = '_blank';
              viewLink.rel = 'noopener';
              viewLink.className = 'action-link';
              viewLink.textContent = 'View';
              actions.appendChild(viewLink);
            }
            if (item.metadata && item.metadata.channels && item.metadata.channels.email) {
              var emailPill = document.createElement('span');
              emailPill.className = 'meta-pill';
              emailPill.textContent = 'Email copy';
              actions.appendChild(emailPill);
            }
            metaLine.appendChild(actions);
            card.appendChild(metaLine);
            list.appendChild(card);
          });
          section.appendChild(list);
          fragment.appendChild(section);
        });

        if (!fragment.children.length) {
          var empty = document.createElement('div');
          empty.className = 'empty-state';
          empty.textContent = state.feed.category === 'all' ? 'No notifications yet.' : 'No notifications for this category.';
          fragment.appendChild(empty);
        }
      }
      feedListEl.appendChild(fragment);
    }

    function renderRules() {
      if (!rulesContainer) return;
      rulesContainer.innerHTML = '';
      if (!state.rules.length) {
        var empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.innerHTML = 'No rules yet. Use <strong>Create rule</strong> to add one.';
        rulesContainer.appendChild(empty);
        return;
      }
      var grouped = groupByCategory(state.rules, function(rule){ return rule.category || 'system'; });
      var fragment = document.createDocumentFragment();
      Object.keys(categories).forEach(function(key){
        var rules = grouped[key] || [];
        if (!rules.length) { return; }
        var section = document.createElement('section');
        section.className = 'category-section';
        var header = document.createElement('div');
        header.className = 'category-header';
        var info = document.createElement('div');
        info.className = 'info';
        var title = document.createElement('h3');
        title.textContent = (categories[key] && categories[key].label) || key;
        info.appendChild(title);
        if (categories[key] && categories[key].description) {
          var desc = document.createElement('p');
          desc.textContent = categories[key].description;
          info.appendChild(desc);
        }
        header.appendChild(info);
        section.appendChild(header);
        var list = document.createElement('div');
        list.className = 'notification-list';
        rules.forEach(function(rule){
          var card = document.createElement('article');
          card.className = 'notification-card';
          if (rule.immutable) {
            card.classList.add('is-immutable');
          }
          var top = document.createElement('div');
          top.className = 'notification-top';
          var titleEl = document.createElement('h3');
          titleEl.textContent = rule.title || 'Notification rule';
          top.appendChild(titleEl);
          var badge = document.createElement('span');
          badge.className = 'badge';
          badge.dataset.type = rule.immutable ? 'system' : 'info';
          badge.textContent = rule.immutable ? 'Protected' : 'Custom';
          top.appendChild(badge);
          card.appendChild(top);
          if (rule.body) {
            var body = document.createElement('div');
            body.className = 'notification-body';
            body.textContent = rule.body;
            card.appendChild(body);
          }
          var metaLine = document.createElement('div');
          metaLine.className = 'notification-meta';
          var channels = document.createElement('span');
          var channelText = rule.send_email ? 'Notification Center + Email' : 'Notification Center only';
          channels.textContent = channelText;
          metaLine.appendChild(channels);
          var actions = document.createElement('div');
          actions.className = 'notification-actions';
          if (!rule.immutable && rule.id != null && rule.id !== '') {
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'action-link';
            editBtn.dataset.ruleAction = 'edit';
            editBtn.dataset.id = String(rule.id);
            editBtn.textContent = 'Edit';
            actions.appendChild(editBtn);

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'action-link';
            deleteBtn.dataset.ruleAction = 'delete';
            deleteBtn.dataset.id = String(rule.id);
            deleteBtn.textContent = 'Delete';
            actions.appendChild(deleteBtn);
          } else if (rule.immutable) {
            var lock = document.createElement('span');
            lock.className = 'meta-pill';
            lock.textContent = 'Security policy';
            actions.appendChild(lock);
          }
          metaLine.appendChild(actions);
          card.appendChild(metaLine);
          list.appendChild(card);
        });
        section.appendChild(list);
        fragment.appendChild(section);
      });
      rulesContainer.appendChild(fragment);
    }

    function renderAll() {
      renderSummary();
      renderFeedTabs();
      renderFeedList();
      renderRules();
    }

    function withCsrfOptions(method, body) {
      var opts = { method: method || 'POST', headers: {} };
      if (opts.method !== 'GET') {
        opts.headers['Content-Type'] = 'application/json';
        if (csrf) {
          opts.headers['X-CSRF-Token'] = csrf;
        }
      }
      if (body !== undefined) {
        opts.body = JSON.stringify(body);
      }
      return opts;
    }

    function fetchJson(url, options) {
      return fetch(url, options).then(function(res){
        if (!res.ok) {
          return res.json().catch(function(){ return {}; }).then(function(json){
            var message = json && json.error ? json.error : 'Request failed.';
            throw new Error(message);
          });
        }
        return res.json();
      });
    }

    function loadFeed() {
      state.feed.loading = true;
      renderFeedList();
      var params = new URLSearchParams();
      params.set('status', state.feed.filters.status || 'all');
      if (state.feed.filters.type) params.set('type', state.feed.filters.type);
      if (state.feed.filters.priority) params.set('priority', state.feed.filters.priority);
      if (state.feed.filters.q) params.set('q', state.feed.filters.q);
      if (state.feed.filters.category) params.set('category', state.feed.filters.category);
      params.set('page', state.feed.pagination.page || 1);
      params.set('per_page', state.feed.pagination.per_page || 25);
      fetchJson('api/notifications/index.php?' + params.toString()).then(function(json){
        state.feed.items = Array.isArray(json.data) ? json.data : [];
        state.feed.pagination = json.pagination || state.feed.pagination;
        state.feed.filters = json.filters || state.feed.filters;
        state.feed.unread = typeof json.unread === 'number' ? json.unread : state.feed.unread;
        renderAll();
      }).catch(function(err){
        console.error(err);
      }).finally(function(){
        state.feed.loading = false;
        renderFeedList();
      });
    }

    function loadRules() {
      fetchJson('api/notifications/index.php/rules').then(function(json){
        state.rules = withPreconfiguredRules(Array.isArray(json.rules) ? json.rules : []);
        renderRules();
      }).catch(function(err){
        console.error(err);
      });
    }

    function updateRuleInState(rule) {
      var targetId = rule && rule.id != null ? String(rule.id) : null;
      var idx = state.rules.findIndex(function(r){ return targetId !== null && String(r.id || '') === targetId; });
      var nextRules = state.rules.slice();
      if (idx === -1) {
        nextRules.push(rule);
      } else {
        nextRules[idx] = rule;
      }
      state.rules = withPreconfiguredRules(nextRules);
      renderRules();
    }

    renderAll();

    if (statusSelect) {
      statusSelect.value = state.feed.filters.status || 'all';
      statusSelect.addEventListener('change', function(){
        state.feed.filters.status = this.value;
        loadFeed();
      });
    }

    if (searchInput) {
      searchInput.value = state.feed.filters.q || '';
      searchInput.addEventListener('input', function(){
        var value = this.value || '';
        state.feed.filters.q = value;
        if (state.searchTimeout) {
          clearTimeout(state.searchTimeout);
        }
        state.searchTimeout = setTimeout(function(){
          loadFeed();
        }, 350);
      });
    }

    if (feedTabsEl) {
      feedTabsEl.addEventListener('click', function(event){
        var target = event.target;
        if (!target || !target.dataset.key) { return; }
        var key = target.dataset.key;
        if (key === state.feed.category) { return; }
        state.feed.category = key;
        state.feed.filters.category = key === 'all' ? 'all' : key;
        renderFeedTabs();
        renderFeedList();
      });
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', function(){
        loadFeed();
      });
    }

    if (markAllBtn) {
      markAllBtn.addEventListener('click', function(){
        fetchJson('api/notifications/index.php/bulk', withCsrfOptions('PATCH', { scope: 'all', operation: 'read' })).then(function(json){
          state.feed.unread = typeof json.unread === 'number' ? json.unread : 0;
          state.feed.items = state.feed.items.map(function(item){
            var clone = Object.assign({}, item);
            clone.is_read = true;
            clone.read_at = clone.read_at || new Date().toISOString();
            return clone;
          });
          renderAll();
        }).catch(function(err){
          alert(err.message || 'Unable to mark notifications.');
        });
      });
    }

    if (feedListEl) {
      feedListEl.addEventListener('click', function(event){
        var target = event.target;
        if (!target || !target.dataset.feedItemAction) { return; }
        var id = parseInt(target.dataset.id || '0', 10);
        if (!id) { return; }
        var action = target.dataset.feedItemAction;
        if (action === 'toggle-read') {
          var item = state.feed.items.find(function(it){ return it.id === id; });
          var shouldRead = !(item && item.is_read);
          fetchJson('api/notifications/index.php/' + id + '/' + (shouldRead ? 'read' : 'unread'), withCsrfOptions('PATCH')).then(function(json){
            if (json && json.data) {
              state.feed.items = state.feed.items.map(function(entry){ return entry.id === id ? json.data : entry; });
              state.feed.unread = typeof json.unread === 'number' ? json.unread : state.feed.unread;
              renderAll();
            }
          }).catch(function(err){
            alert(err.message || 'Unable to update notification.');
          });
        } else if (action === 'archive' || action === 'unarchive') {
          var archived = action === 'archive';
          fetchJson('api/notifications/index.php/' + id + '/archive', withCsrfOptions('PATCH', { archived: archived })).then(function(json){
            if (json && json.data) {
              state.feed.items = state.feed.items.map(function(entry){ return entry.id === id ? json.data : entry; });
              state.feed.unread = typeof json.unread === 'number' ? json.unread : state.feed.unread;
              renderAll();
            }
          }).catch(function(err){
            alert(err.message || 'Unable to update notification.');
          });
        }
      });
    }

    function populateCategoryOptions(selected) {
      if (!fieldCategory) return;
      fieldCategory.innerHTML = '';
      Object.keys(categories).forEach(function(key){
        var opt = document.createElement('option');
        opt.value = key;
        opt.textContent = categories[key].label || key;
        if (selected && selected === key) {
          opt.selected = true;
        }
        fieldCategory.appendChild(opt);
      });
      if (!selected && fieldCategory.options.length) {
        fieldCategory.selectedIndex = 0;
      }
    }

    function populateActionOptions(categoryKey, selected) {
      if (!fieldAction) return false;
      fieldAction.innerHTML = '';
      var options = catalog[categoryKey] || [];
      var reserved = {};
      state.rules.forEach(function(rule){
        var key = ruleKey(rule);
        if (!key) { return; }
        if (selected && key === selected) { return; }
        reserved[key] = true;
      });
      var available = 0;
      if (!options.length) {
        var fallback = document.createElement('option');
        fallback.value = 'custom.manual';
        fallback.textContent = 'Custom reminder';
        if (!reserved[fallback.value] || (selected && selected === fallback.value)) {
          fieldAction.appendChild(fallback);
          available = 1;
        }
      } else {
        options.forEach(function(optDef){
          var value = optDef.type_key || optDef.key || '';
          if (!value) { return; }
          if (reserved[value]) { return; }
          var opt = document.createElement('option');
          opt.value = value;
          opt.textContent = optDef.title || opt.value || 'Notification';
          if (selected && selected === opt.value) {
            opt.selected = true;
          }
          fieldAction.appendChild(opt);
          available += 1;
        });
      }
      if (!selected && fieldAction.options.length) {
        fieldAction.selectedIndex = 0;
      }
      if (!available) {
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'No available rules';
        placeholder.disabled = true;
        placeholder.selected = true;
        fieldAction.appendChild(placeholder);
      }
      return available > 0;
    }

    function openRuleModal(rule) {
      if (!modalBackdrop || !modalForm) return;
      state.ruleEditing = rule && rule.id != null ? String(rule.id) : null;
      if (modalTitle) {
        modalTitle.textContent = rule ? 'Edit notification rule' : 'Create notification rule';
      }
      populateCategoryOptions(rule ? rule.category : null);
      var category = rule ? rule.category : (fieldCategory && fieldCategory.value ? fieldCategory.value : 'system');
      var hasOptions = populateActionOptions(category, rule ? rule.type_key : null);
      if (fieldCategory) {
        fieldCategory.disabled = !!rule;
      }
      if (fieldAction) {
        fieldAction.disabled = rule ? true : !hasOptions;
      }
      if (fieldTitle) {
        fieldTitle.value = rule ? (rule.title || '') : '';
      }
      if (fieldBody) {
        fieldBody.value = rule ? (rule.body || '') : '';
      }
      if (fieldChannelEmail) {
        fieldChannelEmail.checked = rule ? !!rule.send_email : false;
      }
      if (modalSubmit) {
        modalSubmit.disabled = rule ? false : !hasOptions;
      }
      modalBackdrop.classList.add('is-active');
      setTimeout(function(){ if (fieldTitle) { fieldTitle.focus(); } }, 60);
    }

    function closeRuleModal() {
      if (!modalBackdrop) return;
      modalBackdrop.classList.remove('is-active');
      modalBackdrop.classList.remove('is-visible');
      state.ruleEditing = null;
      if (modalForm) {
        modalForm.reset();
      }
      if (fieldCategory) {
        fieldCategory.disabled = false;
      }
      if (fieldAction) {
        fieldAction.disabled = false;
      }
      if (modalSubmit) {
        modalSubmit.disabled = false;
      }
    }

    if (modalClose) {
      modalClose.addEventListener('click', function(){
        closeRuleModal();
      });
    }

    if (modalBackdrop) {
      modalBackdrop.addEventListener('click', function(event){
        if (event.target === modalBackdrop) {
          closeRuleModal();
        }
      });
    }

    if (fieldCategory) {
      fieldCategory.addEventListener('change', function(){
        var hasOptions = populateActionOptions(this.value, null);
        if (!state.ruleEditing && fieldAction) {
          fieldAction.disabled = !hasOptions;
        }
        if (!state.ruleEditing && modalSubmit) {
          modalSubmit.disabled = !hasOptions;
        }
      });
    }

    if (modalForm) {
      modalForm.addEventListener('submit', function(event){
        event.preventDefault();
        var payload = {
          category: fieldCategory ? fieldCategory.value : 'custom',
          action: fieldAction ? fieldAction.value : 'custom.manual',
          title: fieldTitle ? fieldTitle.value.trim() : '',
          body: fieldBody ? fieldBody.value.trim() : '',
          send_email: fieldChannelEmail ? fieldChannelEmail.checked : false
        };
        var method = state.ruleEditing ? 'PATCH' : 'POST';
        var url = state.ruleEditing ? ('api/notifications/index.php/rules/' + state.ruleEditing) : 'api/notifications/index.php/rules';
        if (modalSubmit) { modalSubmit.disabled = true; }
        fetchJson(url, withCsrfOptions(method, payload)).then(function(json){
          if (json && json.data) {
            updateRuleInState(json.data);
          } else {
            loadRules();
          }
          closeRuleModal();
        }).catch(function(err){
          alert(err.message || 'Unable to save rule.');
        }).finally(function(){
          if (modalSubmit) { modalSubmit.disabled = false; }
        });
      });
    }

    if (createRuleBtn) {
      createRuleBtn.addEventListener('click', function(){
        openRuleModal(null);
      });
    }

    if (rulesContainer) {
      rulesContainer.addEventListener('click', function(event){
        var target = event.target;
        if (!target || !target.dataset.ruleAction) { return; }
        var id = target.dataset.id ? String(target.dataset.id) : '';
        if (!id) { return; }
        var action = target.dataset.ruleAction;
        if (action === 'edit') {
          var rule = state.rules.find(function(r){ return String(r.id || '') === id; });
          if (rule) {
            openRuleModal(rule);
          }
        } else if (action === 'delete') {
          if (!confirm('Delete this notification rule?')) { return; }
          fetchJson('api/notifications/index.php/rules/' + id, withCsrfOptions('DELETE')).then(function(){
            state.rules = withPreconfiguredRules(state.rules.filter(function(r){ return String(r.id || '') !== id; }));
            renderRules();
          }).catch(function(err){
            alert(err.message || 'Unable to delete rule.');
          });
        }
      });
    }

    loadRules();
  })();
  </script>

</body>
</html>
