<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$actorId = (int)($USER_ID ?? ($_SESSION['user_id'] ?? 0));
$role = ppf_role_key($USER_ROLE ?? ($_SESSION['role'] ?? 'guest'));
if ($actorId <= 0) {
    header('Location: login.php');
    exit;
}

ppf_trainer_sessions_ensure_schema($conn);

$token = trim((string)($_GET['token'] ?? ''));
$session = $token !== '' ? ppf_trainer_sessions_find_by_token($conn, $token) : null;
$sessionId = $session ? (int)($session['id'] ?? 0) : 0;
$packageName = $session['package_name'] ?? 'Training Session';
$startLabel = $session && !empty($session['scheduled_start'])
    ? ppf_format_user_datetime($session['scheduled_start'], ['fallback' => '—'])
    : '—';
$clientName = trim(($session['client_first'] ?? '') . ' ' . ($session['client_last'] ?? ''));
$trainerName = trim(($session['trainer_first'] ?? '') . ' ' . ($session['trainer_last'] ?? ''));

$allowed = false;
$failureReason = '';
if (!$session || $sessionId <= 0) {
    $failureReason = 'Session not found.';
} else {
    $trainerId = (int)($session['trainer_id'] ?? 0);
    $clientId = (int)($session['client_id'] ?? 0);
    if (ppf_is_admin_role($role)) {
        $allowed = true;
    } elseif (in_array($role, ['trainer','trainer_admin'], true)) {
        if ($trainerId === $actorId) {
            $allowed = true;
        } else {
            $failureReason = 'Trainer validation failed!';
        }
    } elseif ($role === 'client') {
        if ($clientId === $actorId) {
            $allowed = true;
        } else {
            $failureReason = 'Client validation failed!';
        }
    } else {
        $failureReason = 'Trainer validation failed!';
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Session Scan</title>
  <style>
    :root {
      color-scheme: dark;
      font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    body {
      margin: 0;
      min-height: 100vh;
      background: radial-gradient(circle at top, #0f172a, #020617 65%);
      color: #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .scan-card {
      width: min(480px, 100%);
      background: rgba(15, 23, 42, 0.75);
      border: 1px solid rgba(148, 163, 184, 0.25);
      border-radius: 24px;
      box-shadow: 0 30px 80px rgba(15, 23, 42, 0.6);
      padding: 40px 32px;
      text-align: center;
      display: grid;
      gap: 18px;
    }
    .scan-title {
      font-size: 24px;
      font-weight: 700;
      margin: 0;
    }
    .scan-meta {
      font-size: 15px;
      opacity: .8;
      margin: 0;
      line-height: 1.5;
    }
    .scan-progress {
      font-size: 15px;
      opacity: .8;
    }
    .scan-check {
      width: 96px;
      height: 96px;
      border-radius: 50%;
      background: rgba(74, 222, 128, 0.15);
      color: #4ade80;
      display: grid;
      place-items: center;
      font-size: 48px;
      margin: 0 auto;
    }
    .scan-error {
      border-color: rgba(248, 113, 113, 0.4);
      background: rgba(127, 29, 29, 0.25);
    }
    .scan-error .scan-title {
      color: #fca5a5;
    }
    .scan-actions {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: 6px;
    }
    .scan-actions a {
      color: #93c5fd;
      text-decoration: none;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <?php if (!$session || $sessionId <= 0): ?>
    <div class="scan-card scan-error">
      <div class="scan-title">Scan failed!</div>
      <p class="scan-meta"><?php echo h($failureReason ?: 'Session not found.'); ?></p>
      <div class="scan-actions"><a href="dashboard.php">Return to dashboard</a></div>
    </div>
  <?php elseif (!$allowed): ?>
    <div class="scan-card scan-error">
      <div class="scan-title">Scan failed!</div>
      <p class="scan-meta"><?php echo h($failureReason ?: 'You are not authorized to close this session.'); ?></p>
      <div class="scan-actions"><a href="dashboard.php">Return to dashboard</a></div>
    </div>
  <?php else: ?>
    <div class="scan-card" id="scanCard"
         data-session-id="<?php echo h($sessionId); ?>"
         data-csrf="<?php echo h($csrf); ?>"
         data-package="<?php echo h($packageName); ?>"
         data-start="<?php echo h($startLabel); ?>"
         data-client="<?php echo h($clientName); ?>"
         data-trainer="<?php echo h($trainerName); ?>">
      <div class="scan-progress" data-status>Validating scan…</div>
      <div class="scan-meta">
        <strong><?php echo h($packageName); ?></strong><br>
        Starts <?php echo h($startLabel); ?><br>
        Trainer: <?php echo h($trainerName ?: '—'); ?><br>
        Client: <?php echo h($clientName ?: '—'); ?>
      </div>
    </div>
    <script>
      (function(){
        const card = document.getElementById('scanCard');
        if (!card) return;
        const sessionId = parseInt(card.getAttribute('data-session-id'), 10);
        const csrf = card.getAttribute('data-csrf') || '';
        const statusEl = card.querySelector('[data-status]');
        function showError(message){
          card.classList.add('scan-error');
          if (statusEl) statusEl.textContent = message || 'Unable to confirm session.';
        }
        function showSuccess(){
          card.innerHTML = '';
          const check = document.createElement('div');
          check.className = 'scan-check';
          check.textContent = '✓';
          const title = document.createElement('div');
          title.className = 'scan-title';
          title.textContent = 'Session Complete!';
          const meta = document.createElement('p');
          meta.className = 'scan-meta';
          meta.innerHTML = `<?php echo h($packageName); ?><br><?php echo h($startLabel); ?>`;
          const actions = document.createElement('div');
          actions.className = 'scan-actions';
          const link = document.createElement('a');
          link.href = 'dashboard.php';
          link.textContent = 'Back to dashboard';
          actions.appendChild(link);
          card.appendChild(check);
          card.appendChild(title);
          card.appendChild(meta);
          card.appendChild(actions);
        }
        if (!sessionId){
          showError('Invalid session identifier.');
          return;
        }
        if (statusEl) statusEl.textContent = 'Completing session…';
        const params = new URLSearchParams();
        params.set('action', 'end_session');
        params.set('session_id', String(sessionId));
        params.set('source', 'session_scan');
        if (csrf) params.set('csrf_token', csrf);
        fetch('client_sessions_actions.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString(),
          credentials: 'same-origin',
        }).then((res) => res.json().catch(() => null)).then((payload) => {
          if (!payload || !payload.ok) {
            const message = payload && payload.message ? payload.message : 'Unable to complete the session.';
            showError(message);
            return;
          }
          showSuccess();
        }).catch(() => {
          showError('Network error. Please try again.');
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
