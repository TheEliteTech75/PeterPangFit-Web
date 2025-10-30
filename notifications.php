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

$archivedTotal = 0;
if ($userId > 0) {
  try {
    ppf_notifications_prune_archived($conn, $tenantId, $userId);
  } catch (Throwable $e) {
    // ignore pruning errors for boot
  }
  try {
    if ($stmt = $conn->prepare('SELECT COUNT(*) AS c FROM notification_messages WHERE tenant_id = ? AND user_id = ? AND is_archived = 1')) {
      $stmt->bind_param('ii', $tenantId, $userId);
      $stmt->execute();
      if ($res = $stmt->get_result()) {
        if ($row = $res->fetch_assoc()) {
          $archivedTotal = (int)$row['c'];
        }
        $res->close();
      }
      $stmt->close();
    }
  } catch (Throwable $e) {
    $archivedTotal = 0;
  }
}

$initialState = [
  'view' => 'inbox',
  'feed' => [
    'items' => $initialQuery['data'] ?? [],
    'pagination' => $initialQuery['pagination'] ?? ['page' => 1, 'per_page' => 25, 'total' => 0],
    'settings' => $initialQuery['settings'] ?? ppf_notifications_default_settings(),
    'filters' => $baseFilters,
    'unread' => $initialUnread,
    'view' => 'inbox',
    'archived_total' => $archivedTotal,
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
      --subheader-offset: 76px;
    }
    body {
      margin: 0;
      font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--surface, #020617);
      color: var(--text, #f8fafc);
    }
    .subheader-bar {
      position: sticky;
      top: var(--subheader-offset);
      z-index: 3500;
      background: color-mix(in srgb, var(--panel, rgba(15,23,42,0.82)) 85%, rgba(30,41,59,0.65) 15%);
      border-bottom: 1px solid var(--line, rgba(148,163,184,0.18));
      box-shadow: 0 18px 40px rgba(15,23,42,0.35);
      backdrop-filter: blur(22px);
      padding: 8px 0;
      margin-bottom: 24px;
    }
    .subheader {
      width: min(1180px, 100%);
      margin: 0 auto;
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    main.wrap {
      max-width: 1180px;
      margin: 0 auto 48px;
      padding: 24px 20px 60px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .subheader .title {
      display: flex;
      flex-direction: column;
      gap: 6px;
      flex: 1 1 320px;
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
    .subheader .page-tabs {
      margin-left: auto;
      align-self: flex-end;
      margin-bottom: 0;
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
    .panel-title-top {
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
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
    .panel-meta {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 12px;
    }
    .view-tabs,
    .page-tabs {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px;
      border-radius: 999px;
      background: rgba(15,23,42,0.7);
      border: 1px solid rgba(148,163,184,0.22);
      box-shadow: inset 0 0 0 1px rgba(148,163,184,0.08);
    }
    .page-tabs {
      align-self: flex-start;
      margin-bottom: 4px;
    }
    .view-tab,
    .page-tab {
      border: none;
      border-radius: 999px;
      padding: 6px 14px;
      background: transparent;
      color: rgba(226,232,240,0.9);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s ease, color 0.2s ease;
    }
    .view-tab:hover,
    .view-tab:focus-visible,
    .page-tab:hover,
    .page-tab:focus-visible {
      outline: none;
      background: rgba(56,189,248,0.18);
      color: #f0f9ff;
    }
    .view-tab.is-active,
    .page-tab.is-active {
      background: color-mix(in srgb, var(--brand, rgba(14,165,233,0.9)) 70%, rgba(15,23,42,0.6) 30%);
      color: #0b1120;
      box-shadow: 0 10px 24px rgba(14,165,233,0.28);
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
      appearance: none;
    }
    body.ppf-themed .notification-actions .action-link,
    .notification-actions .action-link {
      background: none;
      background-color: transparent;
      border: none;
      box-shadow: none;
      padding: 0;
      margin: 0;
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
    .channel-toggle-group {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }
    .channel-toggle-group.is-fixed {
      margin-left: auto;
      justify-content: flex-end;
    }
    .channel-toggle {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(30,41,59,0.6);
      border: 1px solid rgba(148,163,184,0.25);
      font-size: 13px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 75%, var(--text, #f8fafc) 25%);
      transition: background 0.2s ease, color 0.2s ease, border 0.2s ease;
    }
    .channel-toggle input {
      width: 18px;
      height: 18px;
      accent-color: var(--brand, #38bdf8);
    }
    .channel-toggle.is-active {
      background: rgba(56,189,248,0.18);
      border-color: rgba(56,189,248,0.45);
      color: #f0f9ff;
    }
    .channel-toggle.is-pending {
      opacity: 0.6;
      cursor: progress;
    }
    .channel-toggle.is-pending input {
      cursor: progress;
    }
    .notification-card.is-disabled {
      opacity: 0.45;
      box-shadow: none;
    }
    .rule-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
      color: color-mix(in srgb, var(--muted, #cbd5f5) 78%, var(--text, #f8fafc) 22%);
    }
    .rule-status strong {
      color: var(--text, #f8fafc);
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
        padding: 16px 20px;
        flex-direction: column;
        align-items: flex-start;
      }
      .subheader .page-tabs {
        width: 100%;
        margin-left: 0;
        margin-top: 12px;
        justify-content: flex-start;
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
      .panel-meta {
        width: 100%;
        align-items: flex-start;
      }
      .panel-title-top {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
      .view-tabs {
        width: 100%;
        justify-content: flex-start;
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
    .panel.is-hidden {
      display: none;
    }
  </style>
</head>
<body>
  <div class="subheader-bar">
    <div class="subheader">
      <div class="title">
        <h1>Notification Center</h1>
        <p>Stay up to date with workouts, billing, security alerts, and your own reminders.</p>
      </div>
      <div class="page-tabs" data-main-tabs role="tablist" aria-label="Notification views">
        <button type="button" class="page-tab is-active" data-main-view="inbox" role="tab" aria-selected="true" tabindex="0" aria-controls="notifications-inbox-panel" id="notifications-inbox-tab">Inbox</button>
        <button type="button" class="page-tab" data-main-view="rules" role="tab" aria-selected="false" tabindex="-1" aria-controls="notifications-rules-panel" id="notifications-rules-tab">Rules</button>
      </div>
    </div>
  </div>

  <main class="wrap" data-notification-center>
    <section class="panel" data-feed-section aria-hidden="false" id="notifications-inbox-panel" role="tabpanel" aria-labelledby="notifications-inbox-tab">
      <div class="panel-header">
        <div class="panel-title">
          <div class="panel-title-top">
            <h2 data-feed-title>Inbox</h2>
            <div class="view-tabs" data-feed-views>
              <button type="button" class="view-tab is-active" data-feed-view="inbox">Inbox</button>
              <button type="button" class="view-tab" data-feed-view="archived">Archived</button>
            </div>
          </div>
          <p data-feed-description>Your latest alerts appear here. Use filters to focus on what's important.</p>
        </div>
        <div class="panel-meta">
          <div class="panel-actions" data-feed-actions aria-hidden="false">
            <button type="button" class="ppf-btn" data-feed-action="mark-all" disabled>Mark all read</button>
            <button type="button" class="ppf-btn" data-feed-action="archive-read" disabled>Archive read</button>
            <button type="button" class="ppf-btn" data-feed-action="refresh">Refresh</button>
          </div>
          <div class="status-indicator" data-summary>
            <strong>0</strong> unread notifications
          </div>
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

    <section class="panel is-hidden" data-rules-section aria-hidden="true" id="notifications-rules-panel" role="tabpanel" aria-labelledby="notifications-rules-tab">
      <div class="panel-header">
        <div class="panel-title">
          <h2>Notification Rules</h2>
          <p>Toggle how you receive alerts. Turn on Portal or Email to activate a rule.</p>
        </div>
      </div>
      <div data-rules-container></div>
    </section>
  </main>

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

    function updateSubheaderOffset() {
      var topStack = document.querySelector('.ppf-top-stack');
      var offset = topStack ? topStack.offsetHeight : 0;
      if (offset > 0) {
        document.documentElement.style.setProperty('--subheader-offset', offset + 'px');
      } else {
        document.documentElement.style.removeProperty('--subheader-offset');
      }
    }

    updateSubheaderOffset();
    window.addEventListener('load', updateSubheaderOffset);
    window.addEventListener('resize', function(){
      window.requestAnimationFrame(updateSubheaderOffset);
    });
    function cloneRule(rule) {
      return JSON.parse(JSON.stringify(rule || {}));
    }

    function ruleKey(rule) {
      if (!rule) { return ''; }
      if (rule.type_key) { return String(rule.type_key); }
      if (rule.metadata && rule.metadata.type_key) { return String(rule.metadata.type_key); }
      return '';
    }

    function rulePendingKey(rule) {
      if (!rule) { return ''; }
      if (rule.id !== undefined && rule.id !== null && rule.id !== '') {
        return 'id:' + String(rule.id);
      }
      var key = ruleKey(rule);
      if (key) {
        return 'key:' + key;
      }
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

    var preconfiguredRuleMap = Object.create(null);

    function cachePreconfiguredRule(rule) {
      if (!rule || !(rule.metadata && rule.metadata.preconfigured)) { return; }
      var key = ruleKey(rule);
      if (!key) { return; }
      preconfiguredRuleMap[key] = cloneRule(rule);
    }

    if (Array.isArray(initialState.rules)) {
      initialState.rules.forEach(cachePreconfiguredRule);
    }

    function withPreconfiguredRules(rules) {
      var list = Array.isArray(rules) ? rules.slice() : [];
      var keyed = Object.create(null);
      var anonymous = [];

      list.forEach(function(rule){
        if (!rule) { return; }
        var key = ruleKey(rule);
        var current = rule;
        if (key && preconfiguredRuleMap[key]) {
          var fallback = preconfiguredRuleMap[key];
          var merged = Object.assign({}, fallback, rule);
          var fallbackMeta = fallback.metadata || {};
          var ruleMeta = rule.metadata || {};
          merged.metadata = Object.assign({}, fallbackMeta, ruleMeta);
          current = merged;
        }
        if (key) {
          keyed[key] = current;
        } else {
          anonymous.push(current);
        }
      });

      Object.keys(preconfiguredRuleMap).forEach(function(key){
        if (!Object.prototype.hasOwnProperty.call(keyed, key)) {
          keyed[key] = cloneRule(preconfiguredRuleMap[key]);
        }
      });

      var deduped = anonymous.slice();
      Object.keys(keyed).forEach(function(key){
        deduped.push(keyed[key]);
      });

      return sortRuleList(deduped);
    }

    var state = {
      mainView: typeof initialState.view === 'string' ? initialState.view : 'inbox',
      feed: {
        items: initialState.feed && Array.isArray(initialState.feed.items) ? initialState.feed.items.slice() : [],
        pagination: initialState.feed && initialState.feed.pagination ? initialState.feed.pagination : { page: 1, per_page: 25, total: 0 },
        filters: Object.assign({ status: 'all', type: '', priority: '', q: '', category: 'all' }, initialState.feed && initialState.feed.filters ? initialState.feed.filters : {}),
        unread: initialState.feed && typeof initialState.feed.unread === 'number' ? initialState.feed.unread : 0,
        settings: initialState.feed && initialState.feed.settings ? initialState.feed.settings : {},
        category: 'all',
        loading: false,
        view: initialState.feed && typeof initialState.feed.view === 'string' ? initialState.feed.view : 'inbox',
        archivedTotal: initialState.feed && typeof initialState.feed.archived_total === 'number' ? initialState.feed.archived_total : 0
      },
      rules: withPreconfiguredRules(Array.isArray(initialState.rules) ? initialState.rules.slice() : []),
      searchTimeout: null
    };

    var pendingRuleUpdates = Object.create(null);

    if (state.feed.filters && state.feed.filters.category && state.feed.filters.category !== 'all') {
      state.feed.category = state.feed.filters.category;
    }

    if (state.feed.filters && state.feed.filters.status === 'archived') {
      state.feed.view = 'archived';
    }

    if (state.feed.view !== 'archived') {
      state.feed.view = 'inbox';
    }

    if (state.mainView !== 'rules' && state.mainView !== 'inbox') {
      state.mainView = 'inbox';
    }

    ensureViewConsistency();

    var center = document.querySelector('[data-notification-center]');
    if (!center) { return; }

    var mainTabsEl = document.querySelector('[data-main-tabs]');
    var feedSectionEl = center.querySelector('[data-feed-section]');
    var rulesSectionEl = center.querySelector('[data-rules-section]');
    var feedListEl = center.querySelector('[data-feed-list]');
    var feedTabsEl = center.querySelector('[data-feed-tabs]');
    var statusSelect = center.querySelector('select[data-filter="status"]');
    var searchInput = center.querySelector('input[data-filter="search"]');
    var markAllBtn = center.querySelector('[data-feed-action="mark-all"]');
    var archiveReadBtn = center.querySelector('[data-feed-action="archive-read"]');
    var refreshBtn = center.querySelector('[data-feed-action="refresh"]');
    var summaryEl = center.querySelector('[data-summary]');
    var panelTitleEl = center.querySelector('[data-feed-title]');
    var panelDescriptionEl = center.querySelector('[data-feed-description]');
    var viewTabsEl = center.querySelector('[data-feed-views]');
    var rulesContainer = center.querySelector('[data-rules-container]');
    var feedActionsEl = center.querySelector('[data-feed-actions]');

    function formatDate(iso) {
      if (!iso) return '';
      var date = new Date((iso || '').replace(' ', 'T'));
      if (isNaN(date.getTime())) {
        return iso;
      }
      return date.toLocaleString();
    }

    function renderMainView() {
      var view = state.mainView === 'rules' ? 'rules' : 'inbox';
      if (view !== state.mainView) {
        state.mainView = view;
      }
      var isInbox = view === 'inbox';
      if (feedSectionEl) {
        var hideFeed = !isInbox;
        feedSectionEl.classList.toggle('is-hidden', hideFeed);
        feedSectionEl.setAttribute('aria-hidden', hideFeed ? 'true' : 'false');
        if (hideFeed) {
          feedSectionEl.setAttribute('hidden', 'hidden');
        } else {
          feedSectionEl.removeAttribute('hidden');
        }
      }
      if (rulesSectionEl) {
        var hideRules = isInbox;
        rulesSectionEl.classList.toggle('is-hidden', hideRules);
        rulesSectionEl.setAttribute('aria-hidden', hideRules ? 'true' : 'false');
        if (hideRules) {
          rulesSectionEl.setAttribute('hidden', 'hidden');
        } else {
          rulesSectionEl.removeAttribute('hidden');
        }
      }
      if (feedActionsEl) {
        feedActionsEl.style.display = isInbox ? '' : 'none';
        feedActionsEl.setAttribute('aria-hidden', isInbox ? 'false' : 'true');
        if (isInbox) {
          feedActionsEl.removeAttribute('hidden');
        } else {
          feedActionsEl.setAttribute('hidden', 'hidden');
        }
      }
      if (mainTabsEl) {
        var buttons = mainTabsEl.querySelectorAll('[data-main-view]');
        Array.prototype.forEach.call(buttons, function(btn) {
          var target = btn.getAttribute('data-main-view');
          var isActive = target === view || (!target && view === 'inbox');
          btn.classList.toggle('is-active', isActive);
          btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
          btn.setAttribute('tabindex', isActive ? '0' : '-1');
        });
      }
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
      if (state.feed.view === 'archived') {
        var total = 0;
        if (state.feed.pagination && typeof state.feed.pagination.total === 'number') {
          total = state.feed.pagination.total;
        }
        if ((!total || total < 0) && typeof state.feed.archivedTotal === 'number') {
          total = state.feed.archivedTotal;
        }
        if (!total && Array.isArray(state.feed.items)) {
          total = state.feed.items.filter(function(item){ return item && item.is_archived; }).length;
        }
        var archivedLabel = total === 1 ? 'notification' : 'notifications';
        summaryEl.innerHTML = '<strong>' + total + '</strong> archived ' + archivedLabel + ' · Auto-delete after 30 days';
        if (markAllBtn) {
          markAllBtn.disabled = true;
        }
        if (archiveReadBtn) {
          archiveReadBtn.disabled = true;
        }
        return;
      }
      var count = state.feed.unread || 0;
      var label = count === 1 ? 'notification' : 'notifications';
      summaryEl.innerHTML = '<strong>' + count + '</strong> unread ' + label;
      if (markAllBtn) {
        markAllBtn.disabled = !(count > 0);
      }
      if (archiveReadBtn) {
        var hasRead = Array.isArray(state.feed.items) && state.feed.items.some(function(item){
          return item && item.is_read && !item.is_archived;
        });
        archiveReadBtn.disabled = !hasRead;
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

    function renderViewTabs() {
      if (!viewTabsEl) return;
      var buttons = viewTabsEl.querySelectorAll('[data-feed-view]');
      buttons.forEach(function(btn){
        var view = btn.dataset.feedView || 'inbox';
        var isActive = view === state.feed.view || (!view && state.feed.view === 'inbox');
        btn.classList.toggle('is-active', isActive);
        if (view === 'inbox') {
          var unread = state.feed.unread || 0;
          btn.textContent = unread > 0 ? 'Inbox (' + unread + ')' : 'Inbox';
        } else if (view === 'archived') {
          var total = state.feed.archivedTotal || 0;
          if ((!total || total < 0) && state.feed.view === 'archived' && state.feed.pagination && typeof state.feed.pagination.total === 'number') {
            total = state.feed.pagination.total;
          }
          btn.textContent = total > 0 ? 'Archived (' + total + ')' : 'Archived';
        }
      });
    }

    function renderPanelHeader() {
      if (panelTitleEl) {
        panelTitleEl.textContent = state.feed.view === 'archived' ? 'Archived' : 'Inbox';
      }
      if (panelDescriptionEl) {
        if (state.feed.view === 'archived') {
          panelDescriptionEl.textContent = 'Previously archived notifications live here. Items are removed automatically after 30 days.';
        } else {
          panelDescriptionEl.textContent = "Your latest alerts appear here. Use filters to focus on what's important.";
        }
      }
    }

    function ensureViewConsistency() {
      if (state.feed.view === 'archived') {
        state.feed.filters.status = 'archived';
      } else {
        if (state.feed.filters.status === 'archived') {
          state.feed.filters.status = 'all';
        }
      }
    }

    function renderFeedList() {
      if (!feedListEl) return;
      feedListEl.innerHTML = '';
      var filtered = state.feed.items.slice().filter(function(item){
        return state.feed.view === 'archived' ? !!(item && item.is_archived) : !(item && item.is_archived);
      });
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
          if (state.feed.view === 'archived') {
            empty.textContent = 'No archived notifications yet.';
          } else {
            empty.textContent = state.feed.category === 'all' ? 'No notifications yet.' : 'No notifications for this category.';
          }
          fragment.appendChild(empty);
        }
      }
      feedListEl.appendChild(fragment);
    }

    function resolveChannels(rule) {
      var channels = rule && typeof rule === 'object' ? rule.channels : null;
      var center = true;
      var email = false;
      if (channels && typeof channels === 'object') {
        if (Object.prototype.hasOwnProperty.call(channels, 'center')) {
          center = !!channels.center;
        }
        if (Object.prototype.hasOwnProperty.call(channels, 'email')) {
          email = !!channels.email;
        }
      }
      return { center: center, email: email };
    }

    function normalizeChannelState(channels) {
      var center = !!(channels && channels.center);
      var email = !!(channels && channels.email);
      return { center: center, email: email };
    }

    function buildRuleWithChannels(rule, channels) {
      if (!rule) { return null; }
      var normalized = normalizeChannelState(channels);
      var metadata = Object.assign({}, rule.metadata || {});
      var metaChannels = Object.assign({}, metadata.channels || {});
      metaChannels.center = normalized.center;
      metaChannels.email = normalized.email;
      metadata.channels = metaChannels;
      metadata.send_email = !!normalized.email;
      return Object.assign({}, rule, {
        channels: { center: normalized.center, email: normalized.email },
        send_email: !!normalized.email,
        metadata: metadata
      });
    }

    function renderRules() {
      if (!rulesContainer) return;
      rulesContainer.innerHTML = '';
      if (!state.rules.length) {
        var empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = 'No notification rules are available.';
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
          card.setAttribute('data-rule-card', '');
          var ruleId = (rule && rule.id != null && rule.id !== '') ? String(rule.id) : '';
          var ruleTypeKey = ruleKey(rule);
          if (ruleId) {
            card.setAttribute('data-rule-id', ruleId);
          }
          if (ruleTypeKey) {
            card.setAttribute('data-rule-key', ruleTypeKey);
          }
          var channelState = resolveChannels(rule);
          if (rule && rule.immutable) {
            channelState.center = true;
            channelState.email = true;
          }
          var isActive = channelState.center || channelState.email;
          if (!isActive) {
            card.classList.add('is-disabled');
          }
          if (rule.immutable) {
            card.classList.add('is-immutable');
          }
          var top = document.createElement('div');
          top.className = 'notification-top';
          var titleEl = document.createElement('h3');
          titleEl.textContent = rule.title || 'Notification rule';
          top.appendChild(titleEl);
          if (!rule.immutable) {
            var badge = document.createElement('span');
            badge.className = 'badge';
            badge.dataset.type = 'info';
            badge.textContent = 'Rule';
            top.appendChild(badge);
          }
          card.appendChild(top);
          if (rule.body) {
            var body = document.createElement('div');
            body.className = 'notification-body';
            body.textContent = rule.body;
            card.appendChild(body);
          }
          var metaLine = document.createElement('div');
          metaLine.className = 'notification-meta';

          var status = document.createElement('span');
          status.className = 'rule-status';
          var statusStrong = document.createElement('strong');
          statusStrong.textContent = isActive ? 'On' : 'Off';
          status.appendChild(statusStrong);
          status.appendChild(document.createTextNode(' · '));
          var detail = document.createElement('span');
          if (isActive) {
            var methods = [];
            if (channelState.center) { methods.push('Portal'); }
            if (channelState.email) { methods.push('Email'); }
            detail.textContent = methods.join(' + ');
          } else {
            detail.textContent = 'Enable Portal or Email to turn on.';
          }
          status.appendChild(detail);
          metaLine.appendChild(status);

          var toggles = document.createElement('div');
          toggles.className = 'channel-toggle-group';
          var isImmutable = !!(rule && rule.immutable);
          if (isImmutable) {
            toggles.classList.add('is-fixed');
          }

          function appendToggle(labelText, channelKey, checked) {
            var labelEl = document.createElement('label');
            var pendingKey = rulePendingKey(rule) || (ruleTypeKey ? 'key:' + ruleTypeKey : '');
            var pendingEntry = pendingKey ? pendingRuleUpdates[pendingKey] : null;
            var pendingChannels = pendingEntry && pendingEntry.channels;
            var hasPendingValue = pendingChannels && Object.prototype.hasOwnProperty.call(pendingChannels, channelKey);
            var effectiveChecked = hasPendingValue ? !!pendingChannels[channelKey] : checked;
            var isPending = !!pendingEntry;
            var className = 'channel-toggle';
            if (effectiveChecked) { className += ' is-active'; }
            if (isPending) { className += ' is-pending'; }
            labelEl.className = className;
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.dataset.ruleToggle = channelKey;
            if (ruleId) { input.dataset.id = ruleId; }
            if (ruleTypeKey) { input.dataset.key = ruleTypeKey; }
            input.checked = effectiveChecked;
            input.disabled = isImmutable || isPending;
            labelEl.appendChild(input);
            var text = document.createElement('span');
            text.textContent = labelText;
            labelEl.appendChild(text);
            toggles.appendChild(labelEl);
          }

          appendToggle('Portal', 'center', channelState.center);
          appendToggle('Email', 'email', channelState.email);
          metaLine.appendChild(toggles);

          card.appendChild(metaLine);
          list.appendChild(card);
        });
        section.appendChild(list);
        fragment.appendChild(section);
      });
      rulesContainer.appendChild(fragment);
    }

    function renderAll() {
      renderMainView();
      renderPanelHeader();
      renderViewTabs();
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

    function loadFeed(options) {
      options = options || {};
      ensureViewConsistency();
      var showSpinner = !options.silent;
      if (showSpinner) {
        state.feed.loading = true;
        renderFeedList();
      }
      var params = new URLSearchParams();
      params.set('status', state.feed.filters.status || 'all');
      if (state.feed.filters.type) params.set('type', state.feed.filters.type);
      if (state.feed.filters.priority) params.set('priority', state.feed.filters.priority);
      if (state.feed.filters.q) params.set('q', state.feed.filters.q);
      if (state.feed.filters.category) params.set('category', state.feed.filters.category);
      params.set('page', state.feed.pagination.page || 1);
      params.set('per_page', state.feed.pagination.per_page || 25);
      return fetchJson('api/notifications/index.php?' + params.toString()).then(function(json){
        state.feed.items = Array.isArray(json.data) ? json.data : [];
        state.feed.pagination = json.pagination || state.feed.pagination;
        if (json.filters && typeof json.filters === 'object') {
          state.feed.filters = Object.assign(state.feed.filters, json.filters);
        }
        state.feed.category = state.feed.filters.category && state.feed.filters.category !== 'all' ? state.feed.filters.category : 'all';
        state.feed.unread = typeof json.unread === 'number' ? json.unread : state.feed.unread;
        if ((state.feed.filters.status || 'all') === 'archived') {
          state.feed.view = 'archived';
          if (json.pagination && typeof json.pagination.total === 'number') {
            state.feed.archivedTotal = json.pagination.total;
          }
        } else if (state.feed.view === 'archived') {
          state.feed.view = 'inbox';
        }
        if (statusSelect) {
          statusSelect.value = state.feed.filters.status || 'all';
        }
        renderAll();
      }).catch(function(err){
        console.error(err);
        if (!showSpinner) {
          renderAll();
        }
      }).finally(function(){
        state.feed.loading = false;
        if (showSpinner) {
          renderFeedList();
        }
      });
    }

    function loadRules() {
      fetchJson('api/notifications/index.php/rules').then(function(json){
        var incoming = Array.isArray(json.rules) ? json.rules : [];
        incoming.forEach(cachePreconfiguredRule);
        state.rules = withPreconfiguredRules(incoming);
        renderRules();
      }).catch(function(err){
        console.error(err);
      });
    }

    function updateRuleInState(rule) {
      if (!rule) { return; }
      var nextRules = state.rules.slice();
      var targetId = (rule.id !== undefined && rule.id !== null && rule.id !== '') ? String(rule.id) : null;
      var idx = -1;
      var key = ruleKey(rule);
      var replacement = cloneRule(rule);
      if (targetId !== null) {
        idx = nextRules.findIndex(function(existing){
          return existing && existing.id !== undefined && existing.id !== null && String(existing.id) === targetId;
        });
      }
      if (idx === -1 && key) {
        idx = nextRules.findIndex(function(existing){
          return ruleKey(existing) === key;
        });
      }
      if (idx === -1) {
        if (key) {
          nextRules = nextRules.filter(function(existing){
            return ruleKey(existing) !== key;
          });
        }
        nextRules.push(replacement);
      } else {
        nextRules[idx] = replacement;
      }
      if (Array.isArray(nextRules)) {
        nextRules.forEach(cachePreconfiguredRule);
      }
      state.rules = withPreconfiguredRules(nextRules);
      renderRules();
    }

    renderAll();

    if (mainTabsEl) {
      mainTabsEl.addEventListener('click', function(event){
        var button = event.target.closest('[data-main-view]');
        if (!button || !mainTabsEl.contains(button)) { return; }
        var view = button.getAttribute('data-main-view');
        if (view !== 'rules') {
          view = 'inbox';
        }
        if (state.mainView === view) { return; }
        state.mainView = view;
        renderMainView();
      });
      mainTabsEl.addEventListener('keydown', function(event){
        var key = event.key;
        if (!key) { return; }
        if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'Home' && key !== 'End') {
          return;
        }
        var tabs = mainTabsEl.querySelectorAll('[data-main-view]');
        if (!tabs.length) { return; }
        var activeElement = document.activeElement;
        var currentIndex = Array.prototype.indexOf.call(tabs, activeElement);
        var targetIndex = currentIndex;
        if (key === 'Home') {
          targetIndex = 0;
        } else if (key === 'End') {
          targetIndex = tabs.length - 1;
        } else if (key === 'ArrowRight' || key === 'ArrowDown') {
          targetIndex = (currentIndex + 1) % tabs.length;
        } else if (key === 'ArrowLeft' || key === 'ArrowUp') {
          targetIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        }
        if (targetIndex < 0 || targetIndex >= tabs.length) { return; }
        var targetTab = tabs[targetIndex];
        if (!targetTab) { return; }
        event.preventDefault();
        targetTab.focus();
        targetTab.click();
      });
    }

    if (statusSelect) {
      statusSelect.value = state.feed.filters.status || 'all';
      statusSelect.addEventListener('change', function(){
        var value = this.value || 'all';
        state.feed.filters.status = value;
        if (value === 'archived') {
          state.feed.view = 'archived';
          state.feed.category = 'all';
          state.feed.filters.category = 'all';
        } else if (state.feed.view === 'archived') {
          state.feed.view = 'inbox';
        }
        state.feed.pagination.page = 1;
        renderViewTabs();
        renderPanelHeader();
        renderFeedTabs();
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

    if (viewTabsEl) {
      viewTabsEl.addEventListener('click', function(event){
        var btn = event.target.closest('[data-feed-view]');
        if (!btn) { return; }
        var view = btn.dataset.feedView || 'inbox';
        if (view === state.feed.view) { return; }
        state.feed.view = view === 'archived' ? 'archived' : 'inbox';
        state.feed.pagination.page = 1;
        if (state.feed.view === 'archived') {
          state.feed.category = 'all';
          state.feed.filters.category = 'all';
        }
        ensureViewConsistency();
        if (statusSelect) {
          statusSelect.value = state.feed.filters.status || 'all';
        }
        renderAll();
        loadFeed();
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

    if (archiveReadBtn) {
      archiveReadBtn.addEventListener('click', function(){
        if (archiveReadBtn.disabled || state.feed.view === 'archived') { return; }
        archiveReadBtn.disabled = true;
        fetchJson('api/notifications/index.php/bulk', withCsrfOptions('PATCH', { operation: 'archive_read' })).then(function(json){
          if (json && typeof json.archived === 'number') {
            state.feed.archivedTotal = (state.feed.archivedTotal || 0) + Math.max(0, json.archived);
          }
          state.feed.unread = typeof json.unread === 'number' ? json.unread : state.feed.unread;
          return loadFeed();
        }).catch(function(err){
          alert(err.message || 'Unable to archive notifications.');
        }).finally(function(){
          archiveReadBtn.disabled = false;
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
          var before = state.feed.items.find(function(entry){ return entry.id === id; });
          fetchJson('api/notifications/index.php/' + id + '/archive', withCsrfOptions('PATCH', { archived: archived })).then(function(json){
            if (json && json.data) {
              state.feed.items = state.feed.items.map(function(entry){ return entry.id === id ? json.data : entry; });
              state.feed.unread = typeof json.unread === 'number' ? json.unread : state.feed.unread;
              if (before && before.is_archived && !(json.data && json.data.is_archived)) {
                state.feed.archivedTotal = Math.max(0, (state.feed.archivedTotal || 0) - 1);
              } else if ((!before || !before.is_archived) && json.data && json.data.is_archived) {
                state.feed.archivedTotal = (state.feed.archivedTotal || 0) + 1;
              }
              renderAll();
              loadFeed({ silent: true });
            }
          }).catch(function(err){
            alert(err.message || 'Unable to update notification.');
          });
        }
      });
    }

    if (rulesContainer) {
      rulesContainer.addEventListener('change', function(event){
        var target = event.target;
        if (!target || !target.dataset.ruleToggle) { return; }
        var channelKey = target.dataset.ruleToggle;
        var id = target.dataset.id ? String(target.dataset.id) : '';
        var lookupKey = target.dataset.key ? String(target.dataset.key) : '';
        if (!id || !lookupKey) {
          var owner = target.closest('[data-rule-card]');
          if (owner) {
            if (!id && owner.dataset.ruleId) {
              id = String(owner.dataset.ruleId);
            }
            if (!lookupKey && owner.dataset.ruleKey) {
              lookupKey = String(owner.dataset.ruleKey);
            }
          }
        }
        if (!id && !lookupKey) { return; }
        var rule = null;
        if (id) {
          rule = state.rules.find(function(r){ return String(r.id || '') === id; });
        }
        if (!rule && lookupKey) {
          rule = state.rules.find(function(r){ return ruleKey(r) === lookupKey; });
        }
        if (!rule) {
          renderRules();
          return;
        }
        if (rule.immutable) {
          renderRules();
          return;
        }
        var originalRule = cloneRule(rule);
        var previousState = resolveChannels(rule);
        var nextState = { center: previousState.center, email: previousState.email };
        if (channelKey === 'center') {
          nextState.center = target.checked;
        } else if (channelKey === 'email') {
          nextState.email = target.checked;
        } else {
          renderRules();
          return;
        }

        var pendingKey = rulePendingKey(rule) || (lookupKey ? 'key:' + lookupKey : '');
        if (pendingKey && pendingRuleUpdates[pendingKey]) {
          renderRules();
          return;
        }
        if (pendingKey) {
          pendingRuleUpdates[pendingKey] = {
            channels: { center: !!nextState.center, email: !!nextState.email }
          };
        }

        var optimisticRule = buildRuleWithChannels(rule, nextState);
        if (optimisticRule) {
          updateRuleInState(optimisticRule);
        } else {
          renderRules();
        }

        var clearPending = function() {
          if (pendingKey) {
            delete pendingRuleUpdates[pendingKey];
          }
        };

        if (rule.id) {
          var patchBody = { center: !!nextState.center, email: !!nextState.email };
          if (lookupKey) {
            patchBody.type_key = lookupKey;
          }
          fetchJson('api/notifications/index.php/rules/' + rule.id + '/channels', withCsrfOptions('PATCH', patchBody)).then(function(json){
            clearPending();
            if (json && json.data) {
              cachePreconfiguredRule(json.data);
              updateRuleInState(json.data);
            }
            loadRules();
          }).catch(function(err){
            clearPending();
            alert(err.message || 'Unable to update rule.');
            var revertRule = buildRuleWithChannels(originalRule, previousState);
            if (revertRule) {
              updateRuleInState(revertRule);
            } else {
              renderRules();
            }
          });
          return;
        }

        var typeKey = lookupKey || ruleKey(originalRule);
        if (!typeKey) {
          clearPending();
          renderRules();
          return;
        }

        var payload = {
          type_key: typeKey,
          channels: nextState,
          send_email: !!nextState.email
        };
        if (originalRule && originalRule.title) {
          payload.title = originalRule.title;
        }
        if (originalRule && originalRule.body) {
          payload.body = originalRule.body;
        }
        var category = '';
        if (originalRule && originalRule.category) {
          category = String(originalRule.category);
        } else if (originalRule && originalRule.metadata && originalRule.metadata.category) {
          category = String(originalRule.metadata.category);
        }
        if (category) {
          payload.category = category;
        }
        if (originalRule && typeof originalRule.priority !== 'undefined') {
          var priorityValue = parseInt(originalRule.priority, 10);
          if (!isNaN(priorityValue)) {
            payload.priority = priorityValue;
          }
        }
        if (originalRule && originalRule.metadata && originalRule.metadata.preconfigured) {
          payload.metadata = { preconfigured: true };
        }

        fetchJson('api/notifications/index.php/rules', withCsrfOptions('POST', payload)).then(function(json){
          clearPending();
          if (json && json.data) {
            cachePreconfiguredRule(json.data);
            updateRuleInState(json.data);
          }
          loadRules();
        }).catch(function(err){
          clearPending();
          alert(err.message || 'Unable to update rule.');
          var revertRule = buildRuleWithChannels(originalRule, previousState);
          if (revertRule) {
            updateRuleInState(revertRule);
          } else {
            renderRules();
          }
        });
      });
    }

    loadRules();
  })();
  </script>

</body>
</html>
