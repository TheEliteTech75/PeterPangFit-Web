<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_theme.php';

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userEmail = (string)($_SESSION['email'] ?? '');
$userRole = (string)($_SESSION['role'] ?? '');
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

$flash = $_SESSION['notifications_flash'] ?? null;
unset($_SESSION['notifications_flash']);

function notifications_flash(string $type, string $message): void {
    $_SESSION['notifications_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

$categories = ppf_notification_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        notifications_flash('err', 'Your session expired. Please try again.');
        header('Location: notifications.php');
        exit;
    }

    switch ($action) {
        case 'create':
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $category = ppf_notifications_valid_category((string)($_POST['category'] ?? 'custom'));
            $sendEmail = !empty($_POST['send_email']);
            if ($title === '' && $message === '') {
                notifications_flash('err', 'Please provide a title or message for your notification.');
                break;
            }
            $createdId = ppf_notifications_upsert($conn, $userId, [
                'title' => $title,
                'message' => $message,
                'category' => $category,
                'send_email' => $sendEmail,
                'allow_email' => true,
                'type_key' => 'custom.manual',
            ]);
            if ($createdId) {
                notifications_flash('ok', 'Notification created.');
            } else {
                notifications_flash('err', 'Unable to create notification.');
            }
            break;

        case 'update':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            $existing = ppf_notifications_load_one($conn, $userId, $notificationId);
            if (!$existing) {
                notifications_flash('err', 'Notification not found.');
                break;
            }
            if ((int)($existing['is_mutable'] ?? 0) !== 1) {
                notifications_flash('err', 'This notification cannot be edited.');
                break;
            }
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $category = ppf_notifications_valid_category((string)($_POST['category'] ?? ($existing['category'] ?? 'custom')));
            $sendEmail = !empty($_POST['send_email']);
            $saved = ppf_notifications_upsert($conn, $userId, [
                'title' => $title,
                'message' => $message,
                'category' => $category,
                'send_email' => $sendEmail,
                'allow_email' => (int)($existing['allow_email'] ?? 1) === 1,
                'type_key' => $existing['type_key'] ?? 'custom.manual',
            ], $notificationId);
            if ($saved) {
                notifications_flash('ok', 'Notification updated.');
            } else {
                notifications_flash('err', 'Unable to update notification.');
            }
            break;

        case 'delete':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            if (ppf_notifications_delete($conn, $userId, $notificationId)) {
                notifications_flash('ok', 'Notification deleted.');
            } else {
                notifications_flash('err', 'Unable to delete notification.');
            }
            break;

        case 'toggle_read':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            $markRead = ((string)($_POST['mark_read'] ?? '') === '1');
            if (ppf_notifications_set_read($conn, $userId, $notificationId, $markRead)) {
                notifications_flash('ok', $markRead ? 'Notification marked as read.' : 'Notification marked as unread.');
            } else {
                notifications_flash('err', 'Unable to update notification status.');
            }
            break;

        case 'toggle_email':
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            $enableEmail = ((string)($_POST['send_email'] ?? '') === '1');
            if (ppf_notifications_toggle_email($conn, $userId, $notificationId, $enableEmail)) {
                notifications_flash('ok', $enableEmail ? 'Email delivery enabled.' : 'Email delivery disabled.');
            } else {
                notifications_flash('err', 'Unable to update email preference.');
            }
            break;

        case 'mark_all_read':
            if (ppf_notifications_mark_all_read($conn, $userId)) {
                notifications_flash('ok', 'All notifications marked as read.');
            } else {
                notifications_flash('err', 'Unable to mark notifications as read.');
            }
            break;

        default:
            notifications_flash('err', 'Unsupported action.');
            break;
    }

    header('Location: notifications.php');
    exit;
}

$groupedNotifications = ppf_notifications_fetch_all($conn, $userId);
$summary = ppf_notifications_fetch_recent($conn, $userId, 10);
$totalUnread = (int)($summary['unread'] ?? 0);
$subtitle = $totalUnread === 0 ? 'You are all caught up.' : ($totalUnread === 1 ? '1 unread notification' : ($totalUnread . ' unread notifications'));
$nowIso = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notification Center</title>
  <link rel="stylesheet" href="style.css">
  <style>
    :root {
      color-scheme: dark;
    }
    .notifications-main {
      position: relative;
      padding-bottom: 80px;
    }
    .notifications-content {
      max-width: 1024px;
      margin: 0 auto;
      padding: 32px 32px 64px;
      display: flex;
      flex-direction: column;
      gap: 32px;
    }
    @media (max-width: 720px) {
      .notifications-content {
        padding: 24px 18px 64px;
      }
    }
    .notifications-hero {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .notifications-hero h1 {
      font-size: clamp(1.9rem, 1.8vw + 1.4rem, 2.6rem);
      margin: 0;
      font-weight: 800;
      letter-spacing: -.02em;
      color: var(--text);
    }
    .notifications-hero p {
      margin: 0;
      color: color-mix(in srgb, var(--muted) 70%, var(--text) 30%);
      font-size: 16px;
      max-width: 620px;
      line-height: 1.45;
    }
    .notifications-hero .summary {
      display: flex;
      align-items: center;
      gap: 18px;
      flex-wrap: wrap;
      font-size: 14px;
      color: color-mix(in srgb, var(--muted) 60%, var(--text) 40%);
    }
    .notifications-hero form {
      display: inline-flex;
    }
    .notifications-hero button {
      border-radius: 999px;
      padding: 10px 18px;
      border: 1px solid var(--chip-border);
      background: color-mix(in srgb, var(--chip-bg) 80%, rgba(255,255,255,0.05) 20%);
      color: var(--text);
      font-weight: 600;
      cursor: pointer;
      transition: transform .2s ease, background .2s ease;
    }
    .notifications-hero button:hover,
    .notifications-hero button:focus-visible {
      transform: translateY(-1px);
      background: color-mix(in srgb, var(--chip-bg) 70%, var(--theme-swatch-2, var(--brand)) 30%);
    }
    .notifications-flash {
      border-radius: 14px;
      padding: 14px 18px;
      background: color-mix(in srgb, var(--panel-elevated) 82%, rgba(56, 189, 248, 0.15) 18%);
      border: 1px solid color-mix(in srgb, var(--brand) 60%, var(--card-border) 40%);
      color: var(--text);
      font-size: 14px;
    }
    .notifications-flash.err {
      background: color-mix(in srgb, var(--panel-elevated) 75%, rgba(248, 113, 113, 0.2) 25%);
      border-color: color-mix(in srgb, var(--danger) 60%, var(--card-border) 40%);
    }
    .notifications-create,
    .notifications-category {
      background: var(--panel-elevated);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      box-shadow: var(--card-shadow);
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .notifications-create h2,
    .notifications-category h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: .01em;
      color: var(--text);
    }
    .notifications-create p.description,
    .notifications-category p.description {
      margin: 0;
      font-size: 14px;
      color: color-mix(in srgb, var(--muted) 70%, var(--text) 30%);
    }
    .notifications-form {
      display: grid;
      gap: 14px;
    }
    .notifications-form label {
      display: flex;
      flex-direction: column;
      gap: 6px;
      font-size: 13px;
      color: color-mix(in srgb, var(--muted) 65%, var(--text) 35%);
      font-weight: 600;
    }
    .notifications-form input[type="text"],
    .notifications-form select,
    .notifications-form textarea {
      border-radius: 12px;
      border: 1px solid var(--input-border);
      background: var(--input-bg);
      color: var(--text);
      padding: 10px 12px;
      font-size: 14px;
      font-family: inherit;
    }
    .notifications-form textarea {
      min-height: 100px;
      resize: vertical;
    }
    .notifications-form .form-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .notifications-form .form-actions button {
      padding: 10px 18px;
      border-radius: 999px;
      border: 1px solid var(--chip-border);
      background: color-mix(in srgb, var(--chip-bg) 78%, rgba(255,255,255,0.08) 22%);
      color: var(--text);
      font-weight: 600;
      cursor: pointer;
      transition: transform .2s ease, background .2s ease;
    }
    .notifications-form .form-actions button:hover,
    .notifications-form .form-actions button:focus-visible {
      transform: translateY(-1px);
      background: color-mix(in srgb, var(--chip-bg) 68%, var(--theme-swatch-2, var(--brand)) 32%);
    }
    .notifications-card-list {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .notifications-card {
      border: 1px solid color-mix(in srgb, var(--card-border) 75%, transparent 25%);
      border-radius: 16px;
      padding: 18px;
      background: color-mix(in srgb, var(--panel) 85%, rgba(255,255,255,0.05) 15%);
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .notifications-card.unread {
      border-color: color-mix(in srgb, var(--brand) 65%, var(--card-border) 35%);
      background: color-mix(in srgb, var(--panel) 78%, rgba(56,189,248,0.18) 22%);
    }
    .notifications-card__head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
    }
    .notifications-card__title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text);
      margin: 0;
    }
    .notifications-card__badges {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .08em;
      font-weight: 700;
    }
    .badge {
      border-radius: 999px;
      padding: 4px 10px;
      background: color-mix(in srgb, var(--chip-bg) 85%, rgba(255,255,255,0.08) 15%);
      border: 1px solid var(--chip-border);
      color: color-mix(in srgb, var(--muted) 60%, var(--text) 40%);
    }
    .badge.warn {
      background: color-mix(in srgb, rgba(248, 113, 113, 0.28) 60%, var(--panel) 40%);
      border-color: color-mix(in srgb, var(--danger) 60%, var(--chip-border) 40%);
      color: color-mix(in srgb, var(--danger) 70%, var(--text) 30%);
    }
    .notifications-card__message {
      font-size: 14px;
      line-height: 1.45;
      color: color-mix(in srgb, var(--text) 88%, var(--muted) 12%);
      white-space: pre-wrap;
    }
    .notifications-card__meta {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      font-size: 12px;
      color: color-mix(in srgb, var(--muted) 70%, var(--text) 30%);
    }
    .notifications-card__meta span strong {
      display: block;
      color: var(--text);
      font-weight: 600;
      margin-bottom: 4px;
      font-size: 13px;
    }
    .notifications-card__actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .notifications-card__actions form {
      display: inline-flex;
    }
    .notifications-card__actions button {
      border-radius: 999px;
      padding: 8px 14px;
      font-size: 13px;
      font-weight: 600;
      border: 1px solid var(--chip-border);
      background: color-mix(in srgb, var(--chip-bg) 80%, rgba(255,255,255,0.06) 20%);
      color: var(--text);
      cursor: pointer;
      transition: transform .2s ease, background .2s ease;
    }
    .notifications-card__actions button:hover,
    .notifications-card__actions button:focus-visible {
      transform: translateY(-1px);
      background: color-mix(in srgb, var(--chip-bg) 70%, var(--theme-swatch-2, var(--brand)) 30%);
    }
    .notifications-card__actions button.danger {
      border-color: color-mix(in srgb, var(--danger) 60%, var(--chip-border) 40%);
      color: color-mix(in srgb, var(--danger) 70%, var(--text) 30%);
    }
    .notifications-card__edit summary {
      font-weight: 600;
      cursor: pointer;
      color: color-mix(in srgb, var(--brand) 65%, var(--text) 35%);
    }
    .notifications-card__edit form {
      margin-top: 12px;
      display: grid;
      gap: 12px;
    }
    .notifications-empty {
      padding: 18px;
      border-radius: 14px;
      background: color-mix(in srgb, var(--panel) 80%, rgba(148,163,184,0.12) 20%);
      border: 1px dashed color-mix(in srgb, var(--card-border) 80%, transparent 20%);
      font-size: 13px;
      color: color-mix(in srgb, var(--muted) 75%, var(--text) 25%);
    }
  </style>
</head>
<body class="ppf-themed notifications-page">
<?php
  $USER_ROLE = $userRole;
  $USER_ID = $userId;
  $USER_EMAIL = $userEmail;
  $USER_FIRST_NAME = $_SESSION['first_name'] ?? '';
  $USER_LAST_NAME = $_SESSION['last_name'] ?? '';
  require __DIR__ . '/ppf_header.php';
  require __DIR__ . '/ppf_nav.php';
?>
  <main class="notifications-main">
    <div class="notifications-content">
      <section class="notifications-hero">
        <h1>Notification Center</h1>
        <p>Review automated alerts, create personal reminders, and decide what reaches your inbox.</p>
        <div class="summary">
          <span><?php echo h($subtitle); ?></span>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit"<?php echo $totalUnread === 0 ? ' disabled' : ''; ?>>Mark all as read</button>
          </form>
        </div>
      </section>

      <?php if ($flash): ?>
        <div class="notifications-flash <?php echo h($flash['type'] ?? ''); ?>"><?php echo h($flash['message'] ?? ''); ?></div>
      <?php endif; ?>

      <section class="notifications-create">
        <div>
          <h2>Create a reminder</h2>
          <p class="description">Draft a personal notification to keep upcoming goals, deadlines, or milestones front and center.</p>
        </div>
        <form method="post" class="notifications-form">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
          <input type="hidden" name="action" value="create">
          <label>
            Title
            <input type="text" name="title" maxlength="200" placeholder="Upcoming check-in">
          </label>
          <label>
            Category
            <select name="category">
              <?php foreach ($categories as $key => $meta): ?>
                <option value="<?php echo h($key); ?>"<?php echo $key === 'custom' ? ' selected' : ''; ?>><?php echo h($meta['label']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Message
            <textarea name="message" placeholder="Add any context you want to remember."></textarea>
          </label>
          <label style="flex-direction:row;align-items:center;gap:8px;font-weight:500;">
            <input type="checkbox" name="send_email" value="1">
            Also email me this notification
          </label>
          <div class="form-actions">
            <button type="submit">Save notification</button>
          </div>
        </form>
      </section>

      <?php foreach ($categories as $key => $meta):
        $items = $groupedNotifications[$key] ?? [];
        $label = $meta['label'];
        $description = $meta['description'];
      ?>
      <section class="notifications-category" id="category-<?php echo h($key); ?>">
        <div>
          <h2><?php echo h($label); ?></h2>
          <p class="description"><?php echo h($description); ?></p>
        </div>
        <?php if (empty($items)): ?>
          <div class="notifications-empty">No notifications in this category yet.</div>
        <?php else: ?>
          <div class="notifications-card-list">
            <?php foreach ($items as $item):
              $noteId = (int)($item['id'] ?? 0);
              $isRead = (int)($item['is_read'] ?? 0) === 1;
              $isMutable = (int)($item['is_mutable'] ?? 0) === 1;
              $allowEmail = (int)($item['allow_email'] ?? 0) === 1;
              $sendEmail = (int)($item['send_email'] ?? 0) === 1;
              $createdAt = $item['created_at'] ?? null;
              $updatedAt = $item['updated_at'] ?? null;
              $createdLabel = function_exists('ppf_format_user_datetime') ? ppf_format_user_datetime($createdAt, ['fallback' => '—']) : fmt_when($createdAt);
              $updatedLabel = function_exists('ppf_format_user_datetime') ? ppf_format_user_datetime($updatedAt, ['fallback' => '—']) : fmt_when($updatedAt);
            ?>
            <article class="notifications-card <?php echo $isRead ? '' : 'unread'; ?>">
              <div class="notifications-card__head">
                <h3 class="notifications-card__title"><?php echo h($item['title'] ?? 'Notification'); ?></h3>
                <div class="notifications-card__badges">
                  <span class="badge"><?php echo $isRead ? 'Read' : 'Unread'; ?></span>
                  <?php if (!$isMutable): ?><span class="badge warn">System</span><?php endif; ?>
                  <span class="badge"><?php echo $sendEmail ? 'Email On' : 'Email Off'; ?></span>
                </div>
              </div>
              <?php if (!empty($item['message'])): ?>
                <div class="notifications-card__message"><?php echo nl2br(h($item['message']), false); ?></div>
              <?php endif; ?>
              <div class="notifications-card__meta">
                <span><strong>Created</strong><?php echo h($createdLabel); ?></span>
                <span><strong>Last updated</strong><?php echo h($updatedLabel); ?></span>
                <span><strong>Email delivery</strong><?php echo $allowEmail ? ($sendEmail ? 'Enabled' : 'Disabled') : 'Managed by system'; ?></span>
              </div>
              <div class="notifications-card__actions">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="action" value="toggle_read">
                  <input type="hidden" name="notification_id" value="<?php echo $noteId; ?>">
                  <input type="hidden" name="mark_read" value="<?php echo $isRead ? '0' : '1'; ?>">
                  <button type="submit"><?php echo $isRead ? 'Mark unread' : 'Mark read'; ?></button>
                </form>
                <?php if ($allowEmail): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="action" value="toggle_email">
                  <input type="hidden" name="notification_id" value="<?php echo $noteId; ?>">
                  <input type="hidden" name="send_email" value="<?php echo $sendEmail ? '0' : '1'; ?>">
                  <button type="submit"><?php echo $sendEmail ? 'Email off' : 'Email on'; ?></button>
                </form>
                <?php endif; ?>
                <?php if ($isMutable): ?>
                <form method="post" onsubmit="return confirm('Delete this notification?');">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="notification_id" value="<?php echo $noteId; ?>">
                  <button type="submit" class="danger">Delete</button>
                </form>
                <?php endif; ?>
              </div>
              <?php if ($isMutable): ?>
              <details class="notifications-card__edit">
                <summary>Edit notification</summary>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="notification_id" value="<?php echo $noteId; ?>">
                  <label>
                    Title
                    <input type="text" name="title" value="<?php echo h($item['title'] ?? ''); ?>" maxlength="200">
                  </label>
                  <label>
                    Category
                    <select name="category">
                      <?php foreach ($categories as $catKey => $catMeta): ?>
                        <option value="<?php echo h($catKey); ?>"<?php echo $catKey === ($item['category'] ?? '') ? ' selected' : ''; ?>><?php echo h($catMeta['label']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>
                    Message
                    <textarea name="message"><?php echo h($item['message'] ?? ''); ?></textarea>
                  </label>
                  <?php if ($allowEmail): ?>
                  <label style="flex-direction:row;align-items:center;gap:8px;font-weight:500;">
                    <input type="checkbox" name="send_email" value="1"<?php echo $sendEmail ? ' checked' : ''; ?>>
                    Email me this notification
                  </label>
                  <?php endif; ?>
                  <div class="form-actions">
                    <button type="submit">Save changes</button>
                  </div>
                </form>
              </details>
              <?php else: ?>
                <p style="font-size:12px;color:color-mix(in srgb, var(--muted) 70%, var(--text) 30%);margin:0;">System managed notification. Content edits are disabled for security.</p>
              <?php endif; ?>
            </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endforeach; ?>
    </div>
  </main>
</body>
</html>
