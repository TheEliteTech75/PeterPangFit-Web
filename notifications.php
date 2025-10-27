<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_theme.php';
ob_start();
require_once __DIR__ . '/ppf_header.php';
$ppfHeaderMarkup = ob_get_clean();

ob_start();
require_once __DIR__ . '/ppf_nav.php';
$ppfNavMarkup = ob_get_clean();

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
}
$csrfToken = $_SESSION['csrf_token'];

$tenantId = ppf_current_tenant_id();
$types = ppf_notifications_types();
$priorities = ppf_notifications_priorities();
$categories = ppf_notification_categories();
$settings = ppf_notifications_settings_get($conn, $tenantId, $userId);
$typesJson = json_encode($types, JSON_UNESCAPED_SLASHES);
$prioritiesJson = json_encode($priorities, JSON_UNESCAPED_SLASHES);
$categoriesJson = json_encode($categories, JSON_UNESCAPED_SLASHES);
$settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);

$roleKey = strtolower((string)($_SESSION['role'] ?? ''));
$isAdmin = in_array($roleKey, ['admin', 'owner', 'superadmin', 'super_admin'], true);
$isStaff = in_array($roleKey, ['staff', 'trainer'], true);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notification Center</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.5/dist/tailwind.min.css">
  <style>
    body { font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .ppf-shell { min-height: 100vh; display:flex; flex-direction:column; background: radial-gradient(circle at top, rgba(15,23,42,0.65), #020617 58%); color:#e2e8f0; padding: 120px 0 48px; }
    .ppf-panel { background: rgba(15,23,42,0.72); border:1px solid rgba(148,163,184,0.14); border-radius:18px; box-shadow:0 24px 48px rgba(2,6,23,0.55); }
    .ppf-tabular th { font-size:0.75rem; letter-spacing:0.08em; text-transform:uppercase; color:rgba(148,163,184,0.9); }
    .ppf-tabular td { vertical-align:top; font-size:0.9rem; }
    .ppf-chip-type[data-type="info"] { background:rgba(56,189,248,0.18); color:#38bdf8; }
    .ppf-chip-type[data-type="success"] { background:rgba(34,197,94,0.18); color:#22c55e; }
    .ppf-chip-type[data-type="warning"] { background:rgba(251,191,36,0.18); color:#f59e0b; }
    .ppf-chip-type[data-type="error"] { background:rgba(248,113,113,0.18); color:#f87171; }
    .ppf-chip-type[data-type="system"] { background:rgba(148,163,184,0.25); color:#94a3b8; }
    .ppf-priority[data-priority="1"]::before { content:'•'; color:#f97316; margin-right:4px; }
    .ppf-priority[data-priority="0"]::before { content:'•'; color:#38bdf8; margin-right:4px; }
    .ppf-badge { display:inline-flex; align-items:center; padding:2px 8px; font-size:0.75rem; border-radius:999px; background:rgba(148,163,184,0.18); color:#cbd5f5; }
    .ppf-toast { position:fixed; top:24px; right:24px; min-width:240px; padding:12px 18px; border-radius:12px; background:rgba(15,23,42,0.88); border:1px solid rgba(148,163,184,0.2); color:#e2e8f0; box-shadow:0 18px 36px rgba(2,6,23,0.45); opacity:0; pointer-events:none; transition:opacity .25s ease; z-index:5000; }
    .ppf-toast[data-visible="true"] { opacity:1; pointer-events:auto; }
    .ppf-toast[data-variant="error"] { border-color:rgba(248,113,113,0.45); color:#fecaca; }
    .ppf-toast[data-variant="success"] { border-color:rgba(34,197,94,0.45); color:#bbf7d0; }
    .ppf-modal-backdrop { position:fixed; inset:0; background:rgba(2,6,23,0.75); display:flex; align-items:center; justify-content:center; z-index:6000; }
    .ppf-modal { width:min(640px, 92vw); max-height:90vh; overflow:auto; }
    .ppf-table-row:focus-visible { outline:2px solid #38bdf8; outline-offset:2px; }
    .ppf-skeleton { background:linear-gradient(90deg, rgba(148,163,184,0.14), rgba(148,163,184,0.04), rgba(148,163,184,0.14)); background-size:200% 100%; animation:ppf-shimmer 1.6s ease infinite; border-radius:12px; }
    @keyframes ppf-shimmer { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }
  </style>
</head>
<body class="h-full bg-slate-950">
<?php echo $ppfNavMarkup; ?>
<?php echo $ppfHeaderMarkup; ?>
<div class="ppf-shell">
  <div class="flex-1 min-h-full overflow-x-hidden">
    <main class="px-6 py-10 lg:px-12">
      <div class="max-w-7xl mx-auto space-y-8">
        <header class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-sky-400 uppercase tracking-[0.35em] text-xs font-semibold">System</p>
            <h1 class="text-3xl md:text-4xl font-extrabold text-slate-100">Notification Center</h1>
            <p class="text-slate-400 mt-2 max-w-2xl">Manage real-time alerts across workouts, billing, and security events. Craft personal reminders, tune delivery preferences, and audit historical changes with full transparency.</p>
          </div>
          <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
            <button id="ppfCreateNotification" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-white px-5 py-3 font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-300 focus:ring-offset-slate-900">
              <span class="text-lg" aria-hidden="true">＋</span>
              <span>Create notification</span>
            </button>
            <button id="ppfRefreshNotifications" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-600/60 px-5 py-3 font-semibold text-slate-200 hover:bg-slate-800 transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 focus:ring-offset-slate-900">Refresh</button>
          </div>
        </header>

        <section class="ppf-panel p-6 lg:p-8" aria-labelledby="filters-heading">
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 id="filters-heading" class="text-lg font-semibold text-slate-100">Filters</h2>
              <p class="text-slate-400 text-sm">Slice notifications by status, type, and time. Filters persist as you paginate.</p>
            </div>
            <button id="ppfResetFilters" type="button" class="text-sm font-semibold text-slate-300 hover:text-sky-300 focus:outline-none">Reset</button>
          </div>
          <form id="ppfFilters" class="grid gap-4 lg:gap-6 md:grid-cols-2 xl:grid-cols-4" autocomplete="off">
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Status</span>
              <select name="status" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="">All</option>
                <option value="unread">Unread</option>
                <option value="read">Read</option>
                <option value="archived">Archived</option>
              </select>
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Type</span>
              <select name="type" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="">All types</option>
                <?php foreach ($types as $key => $meta): ?>
                  <option value="<?php echo h($key); ?>"><?php echo h($meta['label'] ?? ucfirst($key)); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Priority</span>
              <select name="priority" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="">All</option>
                <?php foreach ($priorities as $key => $meta): ?>
                  <option value="<?php echo h($key); ?>"><?php echo h($meta['label'] ?? ($key === 0 ? 'Normal' : 'High')); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Actor</span>
              <select name="actor" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="">All</option>
                <option value="system">System</option>
                <option value="user">User initiated</option>
              </select>
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">From</span>
              <input type="date" name="date_from" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" />
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">To</span>
              <input type="date" name="date_to" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" />
            </label>
            <label class="flex flex-col gap-2 text-sm md:col-span-2 xl:col-span-2">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Search title or body</span>
              <input type="search" name="q" placeholder="Search by keywords" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" />
            </label>
          </form>
        </section>

        <section class="ppf-panel p-6 lg:p-8" aria-labelledby="list-heading">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between mb-6">
            <div>
              <h2 id="list-heading" class="text-lg font-semibold text-slate-100">Notifications</h2>
              <p class="text-slate-400 text-sm">Real-time updates land here. Use keyboard: <kbd class="px-2 py-1 rounded bg-slate-800">Space</kbd> to toggle selection, <kbd class="px-2 py-1 rounded bg-slate-800">Enter</kbd> to edit, <kbd class="px-2 py-1 rounded bg-slate-800">A</kbd> to select all.</p>
            </div>
            <div class="flex items-center gap-3 text-sm text-slate-300">
              <span id="ppfSelectionSummary">0 selected</span>
              <div class="flex gap-2">
                <button data-bulk="read" type="button" class="rounded-lg border border-slate-700/60 px-3 py-2 hover:bg-slate-800 transition disabled:opacity-40">Mark read</button>
                <button data-bulk="unread" type="button" class="rounded-lg border border-slate-700/60 px-3 py-2 hover:bg-slate-800 transition disabled:opacity-40">Mark unread</button>
                <button data-bulk="archive" type="button" class="rounded-lg border border-slate-700/60 px-3 py-2 hover:bg-slate-800 transition disabled:opacity-40">Archive</button>
                <button data-bulk="delete" type="button" class="rounded-lg border border-rose-500/50 text-rose-200 px-3 py-2 hover:bg-rose-600/20 transition disabled:opacity-40">Delete</button>
              </div>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full ppf-tabular" role="grid" aria-describedby="list-heading">
              <thead>
                <tr class="text-left">
                  <th class="px-4 py-3"><input type="checkbox" id="ppfSelectAll" class="rounded border-slate-600 bg-slate-900/60"></th>
                  <th class="px-4 py-3">Title</th>
                  <th class="px-4 py-3">Type</th>
                  <th class="px-4 py-3">Priority</th>
                  <th class="px-4 py-3">Read</th>
                  <th class="px-4 py-3">Created</th>
                  <th class="px-4 py-3">Read at</th>
                  <th class="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody id="ppfNotificationBody" class="divide-y divide-slate-800/60">
              </tbody>
            </table>
            <div id="ppfNotificationSkeleton" class="grid gap-3 mt-4" aria-hidden="true">
              <div class="ppf-skeleton h-16"></div>
              <div class="ppf-skeleton h-16"></div>
              <div class="ppf-skeleton h-16"></div>
            </div>
          </div>

          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mt-6 text-sm text-slate-300">
            <div class="flex items-center gap-2">
              <label for="ppfPageSize" class="font-semibold">Rows per page</label>
              <select id="ppfPageSize" class="rounded bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
            </div>
            <div class="flex items-center gap-3 justify-between md:justify-end">
              <button id="ppfPrevPage" type="button" class="px-3 py-2 rounded-lg border border-slate-700/60 hover:bg-slate-800 transition disabled:opacity-40">Previous</button>
              <span id="ppfPaginationSummary">Page 1</span>
              <button id="ppfNextPage" type="button" class="px-3 py-2 rounded-lg border border-slate-700/60 hover:bg-slate-800 transition disabled:opacity-40">Next</button>
            </div>
          </div>
        </section>

        <section class="ppf-panel p-6 lg:p-8" aria-labelledby="settings-heading">
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 id="settings-heading" class="text-lg font-semibold text-slate-100">Delivery preferences</h2>
              <p class="text-slate-400 text-sm">Tune how alerts behave across devices. Preferences apply instantly and feed the badge badge count logic.</p>
            </div>
            <button id="ppfSaveSettings" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-600/60 px-4 py-2 font-semibold text-slate-200 hover:bg-slate-800 transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 focus:ring-offset-slate-900">Save preferences</button>
          </div>
          <form id="ppfSettings" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3" autocomplete="off">
            <label class="flex items-center gap-3 bg-slate-900/40 border border-slate-700/40 rounded-xl px-4 py-3">
              <input type="checkbox" name="auto_mark_on_open" class="rounded border-slate-600 bg-slate-900/60" />
              <div>
                <span class="font-semibold text-slate-200">Auto mark as read</span>
                <p class="text-xs text-slate-400">When opening the panel, unread items switch to read automatically.</p>
              </div>
            </label>
            <label class="flex items-center gap-3 bg-slate-900/40 border border-slate-700/40 rounded-xl px-4 py-3">
              <input type="checkbox" name="badge_includes_muted" class="rounded border-slate-600 bg-slate-900/60" />
              <div>
                <span class="font-semibold text-slate-200">Badge counts muted types</span>
                <p class="text-xs text-slate-400">Include muted categories in the header unread badge.</p>
              </div>
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Default sort</span>
              <select name="default_sort" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="created_at:desc">Newest first</option>
                <option value="created_at:asc">Oldest first</option>
                <option value="priority:desc">High priority</option>
                <option value="priority:asc">Low priority</option>
                <option value="type:asc">Type A-Z</option>
                <option value="type:desc">Type Z-A</option>
                <option value="read_at:asc">Oldest read</option>
                <option value="read_at:desc">Latest read</option>
              </select>
            </label>
            <label class="flex flex-col gap-2 text-sm">
              <span class="font-semibold text-slate-300 uppercase tracking-wide">Default page size</span>
              <select name="page_size" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
            </label>
            <fieldset class="flex flex-col gap-2 text-sm md:col-span-2">
              <legend class="font-semibold text-slate-300 uppercase tracking-wide">Muted types</legend>
              <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <?php foreach ($types as $key => $meta): ?>
                  <label class="flex items-center gap-2 bg-slate-900/40 border border-slate-700/40 rounded-lg px-3 py-2">
                    <input type="checkbox" value="<?php echo h($key); ?>" name="types_muted[]" class="rounded border-slate-600 bg-slate-900/60" />
                    <span><?php echo h($meta['label'] ?? ucfirst($key)); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          </form>
        </section>
      </div>
    </main>
  </div>
</div>

<div id="ppfToast" class="ppf-toast" role="status" aria-live="polite"></div>

<div id="ppfNotificationModal" class="ppf-modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="ppf-panel ppf-modal p-6 lg:p-8 text-slate-100">
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <h2 id="modal-title" class="text-xl font-semibold">Create notification</h2>
        <p class="text-slate-400 text-sm">Share timely updates or craft personal reminders. HTML is stripped for safety.</p>
      </div>
      <button type="button" id="ppfModalClose" class="text-slate-400 hover:text-slate-200 text-2xl leading-none">&times;</button>
    </div>
    <form id="ppfNotificationForm" class="space-y-4">
      <input type="hidden" name="id" value="">
      <div class="grid gap-4 md:grid-cols-2">
        <label class="flex flex-col gap-2 text-sm md:col-span-2">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Title</span>
          <input type="text" name="title" required maxlength="160" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" placeholder="e.g. New plan assigned" />
        </label>
        <label class="flex flex-col gap-2 text-sm md:col-span-2">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Body</span>
          <textarea name="body" rows="4" maxlength="1000" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" placeholder="Additional context for the notification"></textarea>
        </label>
        <label class="flex flex-col gap-2 text-sm">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Type</span>
          <select name="type" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
            <?php foreach ($types as $key => $meta): ?>
              <option value="<?php echo h($key); ?>"><?php echo h($meta['label'] ?? ucfirst($key)); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="flex flex-col gap-2 text-sm">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Priority</span>
          <select name="priority" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
            <?php foreach ($priorities as $key => $meta): ?>
              <option value="<?php echo h($key); ?>"><?php echo h($meta['label'] ?? ($key === 0 ? 'Normal' : 'High')); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="flex flex-col gap-2 text-sm">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Category</span>
          <select name="category" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
            <?php foreach ($categories as $key => $meta): ?>
              <option value="<?php echo h($key); ?>"><?php echo h($meta['label'] ?? ucfirst($key)); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="flex flex-col gap-2 text-sm">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Link (optional)</span>
          <input type="url" name="url" placeholder="https://" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" />
        </label>
      </div>
      <label class="flex items-center gap-3 text-sm">
        <input type="checkbox" name="send_email" class="rounded border-slate-600 bg-slate-900/60" />
        <span>Send email in addition to in-app notification</span>
      </label>
      <?php if ($isAdmin): ?>
      <label class="flex flex-col gap-2 text-sm">
        <span class="font-semibold text-slate-300 uppercase tracking-wide">Target</span>
        <select name="target_mode" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400">
          <option value="self">Only me</option>
          <option value="user">Specific user ID</option>
          <option value="role">Role within tenant</option>
          <option value="all">All users in tenant</option>
        </select>
      </label>
      <div class="grid gap-4 md:grid-cols-2" id="ppfTargetExtras">
        <label class="flex flex-col gap-2 text-sm">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">User ID</span>
          <input type="number" name="target_user_id" min="1" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" />
        </label>
        <label class="flex flex-col gap-2 text-sm">
          <span class="font-semibold text-slate-300 uppercase tracking-wide">Role key</span>
          <input type="text" name="target_role" placeholder="trainer" class="rounded-lg bg-slate-900/60 border border-slate-700/60 px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400" />
        </label>
      </div>
      <?php endif; ?>
      <div class="flex items-center justify-end gap-3 pt-4">
        <button type="button" id="ppfModalCancel" class="rounded-lg border border-slate-700/60 px-4 py-2 text-slate-200 hover:bg-slate-800">Cancel</button>
        <button type="submit" class="rounded-lg bg-sky-500 hover:bg-sky-400 text-white px-4 py-2 font-semibold">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
const PPF_NOTIFY_TYPES = <?php echo $typesJson; ?>;
const PPF_NOTIFY_PRIORITIES = <?php echo $prioritiesJson; ?>;
const PPF_NOTIFY_SETTINGS = <?php echo $settingsJson; ?>;
const PPF_IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
const PPF_IS_STAFF = <?php echo $isStaff ? 'true' : 'false'; ?>;
const PPF_CATEGORIES = <?php echo $categoriesJson; ?>;
const PPF_CSRF = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES); ?>;
const PPF_NOTIFICATION_API = 'api/notifications/index.php';
const PPF_NOTIFICATION_SETTINGS_API = 'api/notifications/index.php/settings';
const PPF_NOTIFICATION_BULK_API = 'api/notifications/index.php/bulk';
const PPF_NOTIFICATION_STREAM = 'api/notifications/stream.php';

(function(){
  const toast = document.getElementById('ppfToast');
  let toastTimer = null;
  function showToast(message, variant) {
    if (!toast) return;
    toast.textContent = message;
    toast.dataset.variant = variant || 'info';
    toast.dataset.visible = 'true';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.dataset.visible = 'false';
    }, 3200);
  }
  window.ppfToast = showToast;
})();

(function(){
  const filtersForm = document.getElementById('ppfFilters');
  const resetFiltersBtn = document.getElementById('ppfResetFilters');
  const tableBody = document.getElementById('ppfNotificationBody');
  const selectionSummary = document.getElementById('ppfSelectionSummary');
  const skeleton = document.getElementById('ppfNotificationSkeleton');
  const selectAllCheckbox = document.getElementById('ppfSelectAll');
  const pageSizeSelect = document.getElementById('ppfPageSize');
  const prevPageBtn = document.getElementById('ppfPrevPage');
  const nextPageBtn = document.getElementById('ppfNextPage');
  const paginationSummary = document.getElementById('ppfPaginationSummary');
  const refreshBtn = document.getElementById('ppfRefreshNotifications');
  const createBtn = document.getElementById('ppfCreateNotification');
  const bulkButtons = document.querySelectorAll('[data-bulk]');
  const settingsForm = document.getElementById('ppfSettings');
  const saveSettingsBtn = document.getElementById('ppfSaveSettings');
  const modalBackdrop = document.getElementById('ppfNotificationModal');
  const modalForm = document.getElementById('ppfNotificationForm');
  const modalTitle = document.getElementById('modal-title');
  const modalClose = document.getElementById('ppfModalClose');
  const modalCancel = document.getElementById('ppfModalCancel');
  const targetExtras = document.getElementById('ppfTargetExtras');
  const targetModeSelect = modalForm ? modalForm.querySelector('select[name="target_mode"]') : null;

  const state = {
    items: [],
    pagination: { page: 1, pages: 1, total: 0 },
    perPage: PPF_NOTIFY_SETTINGS.delivery_prefs?.page_size || 25,
    sort: PPF_NOTIFY_SETTINGS.delivery_prefs?.default_sort || 'created_at:desc',
    filters: {
      status: '',
      type: '',
      priority: '',
      actor: '',
      date_from: '',
      date_to: '',
      q: ''
    },
    selected: new Set(),
    loading: false,
    editing: null,
    settings: JSON.parse(JSON.stringify(PPF_NOTIFY_SETTINGS || {})),
    optimisticCache: new Map()
  };

  pageSizeSelect.value = String(state.perPage);
  Array.from(settingsForm.querySelectorAll('input[name="types_muted[]"]')).forEach(box => {
    if ((state.settings.types_muted || []).includes(box.value)) {
      box.checked = true;
    }
  });
  settingsForm.querySelector('input[name="auto_mark_on_open"]').checked = !!(state.settings.delivery_prefs?.auto_mark_on_open);
  settingsForm.querySelector('input[name="badge_includes_muted"]').checked = !!(state.settings.delivery_prefs?.badge_includes_muted);
  settingsForm.querySelector('select[name="default_sort"]').value = state.settings.delivery_prefs?.default_sort || 'created_at:desc';
  settingsForm.querySelector('select[name="page_size"]').value = String(state.settings.delivery_prefs?.page_size || 25);

  function applyFiltersFromForm() {
    const formData = new FormData(filtersForm);
    for (const key of Object.keys(state.filters)) {
      state.filters[key] = (formData.get(key) || '').trim();
    }
  }

  function resetFilters() {
    filtersForm.reset();
    for (const key of Object.keys(state.filters)) {
      state.filters[key] = '';
    }
    state.pagination.page = 1;
    loadNotifications();
  }

  function updateSelectionSummary() {
    selectionSummary.textContent = `${state.selected.size} selected`;
    bulkButtons.forEach(button => {
      button.disabled = state.selected.size === 0;
    });
    if (selectAllCheckbox) {
      const idsOnPage = state.items.map(item => item.id);
      const allSelected = idsOnPage.length > 0 && idsOnPage.every(id => state.selected.has(id));
      selectAllCheckbox.checked = allSelected;
      selectAllCheckbox.indeterminate = !allSelected && idsOnPage.some(id => state.selected.has(id));
    }
  }

  function renderTable() {
    if (state.loading) {
      skeleton.classList.remove('hidden');
      tableBody.innerHTML = '';
      return;
    }
    skeleton.classList.add('hidden');
    tableBody.innerHTML = '';
    if (!state.items.length) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 8;
      cell.className = 'px-4 py-6 text-center text-slate-400';
      cell.textContent = 'No notifications match your filters.';
      row.appendChild(cell);
      tableBody.appendChild(row);
      return;
    }
    state.items.forEach(item => {
      const row = document.createElement('tr');
      row.tabIndex = 0;
      row.className = 'ppf-table-row hover:bg-slate-900/60 transition';
      row.dataset.id = item.id;
      const checkboxCell = document.createElement('td');
      checkboxCell.className = 'px-4 py-4 align-top';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'rounded border-slate-600 bg-slate-900/60';
      checkbox.checked = state.selected.has(item.id);
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) {
          state.selected.add(item.id);
        } else {
          state.selected.delete(item.id);
        }
        updateSelectionSummary();
      });
      checkboxCell.appendChild(checkbox);
      row.appendChild(checkboxCell);

      const titleCell = document.createElement('td');
      titleCell.className = 'px-4 py-4 align-top';
      const title = document.createElement('div');
      title.className = 'font-semibold text-slate-100 flex items-center gap-2';
      title.textContent = item.title || 'Notification';
      const categoryBadge = document.createElement('span');
      categoryBadge.className = 'ppf-badge';
      categoryBadge.textContent = (PPF_CATEGORIES[item.metadata?.category || '']?.label) || (item.metadata?.category || '');
      if (categoryBadge.textContent.trim() !== '') {
        title.appendChild(categoryBadge);
      }
      titleCell.appendChild(title);
      if (item.body) {
        const snippet = document.createElement('p');
        snippet.className = 'mt-1 text-sm text-slate-400 line-clamp-2';
        snippet.textContent = item.body;
        titleCell.appendChild(snippet);
      }
      if (item.url) {
        const link = document.createElement('a');
        link.href = item.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'inline-flex items-center gap-1 text-xs text-sky-300 hover:text-sky-200 mt-2';
        link.textContent = 'Open link';
        titleCell.appendChild(link);
      }
      row.appendChild(titleCell);

      const typeCell = document.createElement('td');
      typeCell.className = 'px-4 py-4 align-top';
      const typeChip = document.createElement('span');
      typeChip.className = 'ppf-chip-type inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold';
      typeChip.dataset.type = item.type || 'info';
      typeChip.textContent = (PPF_NOTIFY_TYPES[item.type]?.label) || item.type;
      typeCell.appendChild(typeChip);
      row.appendChild(typeCell);

      const priorityCell = document.createElement('td');
      priorityCell.className = 'px-4 py-4 align-top';
      const priority = document.createElement('span');
      priority.className = 'ppf-priority text-sm';
      priority.dataset.priority = item.priority;
      priority.textContent = (PPF_NOTIFY_PRIORITIES[item.priority]?.label) || (item.priority ? 'High' : 'Normal');
      priorityCell.appendChild(priority);
      row.appendChild(priorityCell);

      const readCell = document.createElement('td');
      readCell.className = 'px-4 py-4 align-top';
      readCell.textContent = item.is_read ? 'Read' : 'Unread';
      row.appendChild(readCell);

      const createdCell = document.createElement('td');
      createdCell.className = 'px-4 py-4 align-top text-sm text-slate-400';
      createdCell.textContent = item.created_at || '';
      row.appendChild(createdCell);

      const readAtCell = document.createElement('td');
      readAtCell.className = 'px-4 py-4 align-top text-sm text-slate-400';
      readAtCell.textContent = item.read_at || '—';
      row.appendChild(readAtCell);

      const actionsCell = document.createElement('td');
      actionsCell.className = 'px-4 py-4 align-top';
      const actionsWrap = document.createElement('div');
      actionsWrap.className = 'flex flex-wrap gap-2 text-xs';
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.textContent = 'Edit';
      editBtn.className = 'px-3 py-1 rounded-lg border border-slate-700/60 hover:bg-slate-800';
      editBtn.addEventListener('click', () => openModal(item));
      actionsWrap.appendChild(editBtn);
      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.textContent = item.is_read ? 'Mark unread' : 'Mark read';
      toggleBtn.className = 'px-3 py-1 rounded-lg border border-slate-700/60 hover:bg-slate-800';
      toggleBtn.addEventListener('click', () => bulkAction(item.is_read ? 'unread' : 'read', [item.id]));
      actionsWrap.appendChild(toggleBtn);
      const archiveBtn = document.createElement('button');
      archiveBtn.type = 'button';
      archiveBtn.textContent = 'Archive';
      archiveBtn.className = 'px-3 py-1 rounded-lg border border-slate-700/60 hover:bg-slate-800';
      archiveBtn.addEventListener('click', () => bulkAction('archive', [item.id]));
      actionsWrap.appendChild(archiveBtn);
      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.textContent = 'Delete';
      deleteBtn.className = 'px-3 py-1 rounded-lg border border-rose-500/60 text-rose-200 hover:bg-rose-600/20';
      deleteBtn.addEventListener('click', () => bulkAction('delete', [item.id]));
      actionsWrap.appendChild(deleteBtn);
      actionsCell.appendChild(actionsWrap);
      row.appendChild(actionsCell);

      row.addEventListener('keydown', event => {
        if (event.key === ' ' || event.key === 'Spacebar') {
          event.preventDefault();
          const checked = state.selected.has(item.id);
          if (checked) {
            state.selected.delete(item.id);
          } else {
            state.selected.add(item.id);
          }
          renderTable();
          updateSelectionSummary();
        }
        if (event.key === 'Enter') {
          event.preventDefault();
          openModal(item);
        }
        if (event.key.toLowerCase() === 'a' && (event.ctrlKey || event.metaKey)) {
          event.preventDefault();
          selectAllOnPage();
        }
      });

      if (state.selected.has(item.id)) {
        row.classList.add('bg-slate-900/70');
      }
      tableBody.appendChild(row);
    });
    updateSelectionSummary();
  }

  function updatePagination() {
    paginationSummary.textContent = `Page ${state.pagination.page} of ${state.pagination.pages}`;
    prevPageBtn.disabled = state.pagination.page <= 1;
    nextPageBtn.disabled = state.pagination.page >= state.pagination.pages;
  }

  function selectAllOnPage() {
    state.items.forEach(item => state.selected.add(item.id));
    renderTable();
  }

  function clearSelection() {
    state.selected.clear();
    updateSelectionSummary();
    renderTable();
  }

  function loadNotifications(options) {
    options = options || {};
    state.loading = !options.silent;
    if (!options.silent) {
      renderTable();
    }
    const params = new URLSearchParams();
    params.set('page', String(state.pagination.page));
    params.set('per_page', String(state.perPage));
    params.set('sort', state.sort);
    Object.entries(state.filters).forEach(([key, value]) => {
      if (value) params.set(key, value);
    });
    fetch(`${PPF_NOTIFICATION_API}?${params.toString()}`, { headers: { 'Accept': 'application/json' } })
      .then(res => {
        if (!res.ok) throw new Error('Failed to load notifications');
        return res.json();
      })
      .then(json => {
        state.items = json.data || [];
        state.pagination.page = json.pagination?.page || 1;
        state.pagination.pages = json.pagination?.pages || 1;
        state.pagination.total = json.pagination?.total || 0;
        if (typeof json.unread === 'number') {
          state.unread = json.unread;
        }
        if (json.settings) {
          state.settings = json.settings;
        }
        renderTable();
        updatePagination();
      })
      .catch(err => {
        window.ppfToast?.(err.message || 'Failed to load notifications', 'error');
      })
      .finally(() => {
        state.loading = false;
      });
  }

  function bulkAction(operation, ids) {
    const targets = ids && ids.length ? ids : Array.from(state.selected);
    if (!targets.length) return;
    if (operation === 'delete') {
      const confirmDelete = confirm('Delete selected notifications? This cannot be undone.');
      if (!confirmDelete) return;
    }
    fetch(PPF_NOTIFICATION_BULK_API, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': PPF_CSRF,
        'Idempotency-Key': `notify-bulk-${Date.now()}-${Math.random().toString(16).slice(2)}`
      },
      body: JSON.stringify({ ids: targets, operation })
    }).then(res => {
      if (!res.ok) throw new Error('Bulk update failed');
      return res.json();
    }).then(json => {
      window.ppfToast?.('Bulk operation completed', 'success');
      state.selected.clear();
      if (Array.isArray(json.processed)) {
        json.processed.forEach(entry => {
          if (entry.ok) {
            if (operation === 'read') {
              const item = state.items.find(x => x.id === entry.id);
              if (item) item.is_read = true;
            } else if (operation === 'unread') {
              const item = state.items.find(x => x.id === entry.id);
              if (item) item.is_read = false;
            } else if (operation === 'archive' || operation === 'delete') {
              state.items = state.items.filter(x => x.id !== entry.id);
            }
          }
        });
      }
      loadNotifications({ silent: true });
      updateSelectionSummary();
    }).catch(err => {
      window.ppfToast?.(err.message || 'Bulk update failed', 'error');
    });
  }

  function openModal(item) {
    if (!modalBackdrop || !modalForm) return;
    modalBackdrop.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    if (item) {
      state.editing = item;
      modalTitle.textContent = 'Edit notification';
      modalForm.elements['id'].value = item.id;
      modalForm.elements['title'].value = item.title || '';
      modalForm.elements['body'].value = item.body || '';
      modalForm.elements['type'].value = item.type || 'info';
      modalForm.elements['priority'].value = String(item.priority || 0);
      modalForm.elements['category'].value = item.metadata?.category || 'system';
      modalForm.elements['url'].value = item.url || '';
      modalForm.elements['send_email'].checked = !!item.metadata?.send_email;
      if (targetModeSelect) targetModeSelect.value = 'self';
      if (targetExtras) targetExtras.classList.add('hidden');
    } else {
      state.editing = null;
      modalTitle.textContent = 'Create notification';
      modalForm.reset();
      modalForm.elements['id'].value = '';
      modalForm.elements['type'].value = 'info';
      modalForm.elements['priority'].value = '0';
      modalForm.elements['category'].value = 'system';
      if (targetExtras) targetExtras.classList.add('hidden');
    }
    const firstField = modalForm.querySelector('input[name="title"]');
    if (firstField) firstField.focus();
  }

  function closeModal() {
    modalBackdrop.classList.add('hidden');
    document.body.style.overflow = '';
    state.editing = null;
  }

  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (modalCancel) modalCancel.addEventListener('click', closeModal);
  if (modalBackdrop) {
    modalBackdrop.addEventListener('click', event => {
      if (event.target === modalBackdrop) closeModal();
    });
  }
  window.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !modalBackdrop.classList.contains('hidden')) closeModal();
  });

  if (targetModeSelect && targetExtras) {
    targetModeSelect.addEventListener('change', () => {
      const value = targetModeSelect.value;
      if (value === 'user' || value === 'role') {
        targetExtras.classList.remove('hidden');
      } else {
        targetExtras.classList.add('hidden');
      }
    });
  }

  modalForm?.addEventListener('submit', event => {
    event.preventDefault();
    const formData = new FormData(modalForm);
    const payload = Object.fromEntries(formData.entries());
    payload.send_email = formData.get('send_email') ? true : false;
    const editingId = payload.id ? parseInt(payload.id, 10) : null;
    const method = editingId ? 'PUT' : 'POST';
    const endpoint = editingId ? `${PPF_NOTIFICATION_API}/${editingId}` : PPF_NOTIFICATION_API;
    if (targetModeSelect) {
      payload.target_mode = payload.target_mode || 'self';
    }
    const optimisticSnapshot = editingId ? { ...state.items.find(item => item.id === editingId) } : null;
    if (editingId) {
      const itemRef = state.items.find(item => item.id === editingId);
      if (itemRef) {
        itemRef.title = payload.title;
        itemRef.body = payload.body;
        itemRef.type = payload.type;
        itemRef.priority = parseInt(payload.priority, 10) || 0;
        itemRef.metadata = itemRef.metadata || {};
        itemRef.metadata.category = payload.category;
        itemRef.metadata.send_email = payload.send_email;
      }
    }
    renderTable();
    fetch(endpoint, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': PPF_CSRF,
        'Idempotency-Key': `notify-${method}-${Date.now()}-${Math.random().toString(16).slice(2)}`
      },
      body: JSON.stringify(payload)
    }).then(res => {
      if (!res.ok) throw new Error('Save failed');
      return res.json();
    }).then(json => {
      window.ppfToast?.('Notification saved', 'success');
      closeModal();
      loadNotifications({ silent: true });
    }).catch(err => {
      window.ppfToast?.(err.message || 'Unable to save notification', 'error');
      if (optimisticSnapshot) {
        const target = state.items.find(item => item.id === optimisticSnapshot.id);
        if (target) {
          Object.assign(target, optimisticSnapshot);
          renderTable();
        }
      }
    });
  });

  filtersForm.addEventListener('change', () => {
    applyFiltersFromForm();
    state.pagination.page = 1;
    loadNotifications();
  });
  filtersForm.addEventListener('submit', event => {
    event.preventDefault();
    applyFiltersFromForm();
    state.pagination.page = 1;
    loadNotifications();
  });
  resetFiltersBtn?.addEventListener('click', resetFilters);
  pageSizeSelect.addEventListener('change', () => {
    state.perPage = parseInt(pageSizeSelect.value, 10) || 25;
    state.pagination.page = 1;
    loadNotifications();
  });
  prevPageBtn.addEventListener('click', () => {
    if (state.pagination.page > 1) {
      state.pagination.page -= 1;
      loadNotifications();
    }
  });
  nextPageBtn.addEventListener('click', () => {
    if (state.pagination.page < state.pagination.pages) {
      state.pagination.page += 1;
      loadNotifications();
    }
  });
  refreshBtn?.addEventListener('click', () => loadNotifications());
  createBtn?.addEventListener('click', () => openModal(null));
  bulkButtons.forEach(button => {
    button.addEventListener('click', () => bulkAction(button.dataset.bulk));
  });
  selectAllCheckbox?.addEventListener('change', () => {
    if (selectAllCheckbox.checked) {
      selectAllOnPage();
    } else {
      state.items.forEach(item => state.selected.delete(item.id));
      renderTable();
    }
    updateSelectionSummary();
  });

  settingsForm?.addEventListener('change', () => {
    saveSettingsBtn.disabled = false;
  });
  saveSettingsBtn?.addEventListener('click', () => {
    const formData = new FormData(settingsForm);
    const payload = {
      delivery_prefs: {
        auto_mark_on_open: !!formData.get('auto_mark_on_open'),
        badge_includes_muted: !!formData.get('badge_includes_muted'),
        default_sort: formData.get('default_sort') || 'created_at:desc',
        page_size: parseInt(formData.get('page_size'), 10) || 25
      },
      types_muted: formData.getAll('types_muted[]')
    };
    fetch(PPF_NOTIFICATION_SETTINGS_API, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': PPF_CSRF,
        'Idempotency-Key': `notify-settings-${Date.now()}-${Math.random().toString(16).slice(2)}`
      },
      body: JSON.stringify(payload)
    }).then(res => {
      if (!res.ok) throw new Error('Unable to save settings');
      return res.json();
    }).then(() => {
      window.ppfToast?.('Preferences updated', 'success');
      saveSettingsBtn.disabled = true;
    }).catch(err => {
      window.ppfToast?.(err.message || 'Unable to save settings', 'error');
    });
  });

  document.addEventListener('keydown', event => {
    if ((event.key === 'a' || event.key === 'A') && !event.ctrlKey && !event.metaKey && document.activeElement?.closest('table')) {
      event.preventDefault();
      selectAllOnPage();
      updateSelectionSummary();
    }
  });

  loadNotifications();
})();
</script>
</body>
</html>
