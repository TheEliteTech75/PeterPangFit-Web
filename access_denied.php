<?php
require_once __DIR__ . '/auth.php';

http_response_code(403);

$previousUrl = null;
if (!empty($_SERVER['HTTP_REFERER'])) {
    $referer = (string)$_SERVER['HTTP_REFERER'];
    $refererHost = parse_url($referer, PHP_URL_HOST);
    $refererScheme = parse_url($referer, PHP_URL_SCHEME);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    $currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    $sameHost = !$refererHost || !$currentHost || strcasecmp($refererHost, $currentHost) === 0;
    $sameScheme = !$refererScheme || strcasecmp($refererScheme, $currentScheme) === 0;

    if ($sameHost && $sameScheme) {
        $previousUrl = $referer;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Access Denied · Peter Pang Fit</title>
  <style>
    html, body {
      margin: 0;
      padding: 0;
    }
    body.access-denied-page {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: var(--page-canvas);
      color: var(--text);
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Ubuntu, Cantarell, "Noto Sans", sans-serif;
    }
    .access-denied-main {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 16px 48px;
    }
    .access-card {
      width: min(520px, 100%);
      background: var(--panel-elevated);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      padding: 32px;
      box-shadow: var(--card-shadow);
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .access-card::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at top right,
        color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 22%, transparent 78%) 0%,
        transparent 65%);
      opacity: 0.75;
      pointer-events: none;
    }
    .access-card::after {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at bottom left,
        color-mix(in srgb, var(--theme-swatch-3, var(--primary)) 18%, transparent 82%) 0%,
        transparent 70%);
      opacity: 0.65;
      pointer-events: none;
    }
    .access-card > * {
      position: relative;
      z-index: 2;
    }
    .access-icon {
      width: 72px;
      height: 72px;
      margin: 0 auto 16px;
      border-radius: 20px;
      display: grid;
      place-items: center;
      background: color-mix(in srgb, var(--panel, rgba(15,23,42,0.82)) 78%, var(--theme-swatch-2, var(--brand)) 22%);
      border: 1px solid color-mix(in srgb, var(--card-border) 40%, var(--theme-swatch-2, var(--brand)) 60%);
      box-shadow: 0 16px 40px color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 26%, transparent 74%);
    }
    .access-title {
      font-size: clamp(26px, 4vw, 32px);
      font-weight: 700;
      margin: 0 0 12px;
      color: var(--text);
    }
    .access-message {
      font-size: 16px;
      line-height: 1.6;
      color: color-mix(in srgb, var(--muted, rgba(203,213,225,0.78)) 82%, var(--text) 18%);
      margin: 0 0 28px;
    }
    .access-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 12px;
    }
    .access-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 20px;
      border-radius: 999px;
      border: 1px solid var(--chip-border);
      text-decoration: none;
      font-weight: 600;
      letter-spacing: 0.01em;
      cursor: pointer;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
      color: var(--text);
      background: var(--chip-bg);
      box-shadow: 0 18px 32px color-mix(in srgb, var(--chip-border) 28%, transparent 72%);
    }
    .access-btn:hover,
    .access-btn:focus-visible {
      transform: translateY(-1px);
      border-color: color-mix(in srgb, var(--chip-border) 55%, var(--theme-swatch-2, var(--brand)) 45%);
      box-shadow: 0 22px 40px color-mix(in srgb, var(--chip-border) 38%, transparent 62%);
      outline: none;
    }
    .access-btn.brand {
      background: color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 45%, transparent 55%);
      border-color: color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 70%, transparent 30%);
    }
    .access-btn.brand:hover,
    .access-btn.brand:focus-visible {
      background: color-mix(in srgb, var(--theme-swatch-2, var(--brand)) 55%, transparent 45%);
    }
    .access-hint {
      margin-top: 18px;
      font-size: 13px;
      color: color-mix(in srgb, var(--muted, rgba(203,213,225,0.78)) 70%, var(--text) 30%);
    }
    @media (max-width: 520px) {
      .access-card {
        padding: 26px 22px;
      }
      .access-message {
        font-size: 15px;
      }
      .access-btn {
        width: 100%;
      }
    }
  </style>
</head>
<body class="ppf-themed access-denied-page">
  <?php
    require_once __DIR__ . '/ppf_header.php';
    require_once __DIR__ . '/ppf_nav.php';
  ?>
  <main class="access-denied-main" role="main">
    <section class="access-card" aria-labelledby="access-title">
      <div class="access-icon" aria-hidden="true">
        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 8V6a5 5 0 0 0-10 0v2" />
          <rect x="5" y="8" width="14" height="12" rx="2" ry="2" />
          <path d="M12 12v4" />
        </svg>
      </div>
      <h1 class="access-title" id="access-title">Access Denied</h1>
      <p class="access-message">Your account does not have permission to access this area.</p>
      <div class="access-actions">
        <?php if ($previousUrl): ?>
          <a class="access-btn" href="<?php echo h($previousUrl); ?>">Return to Previous Page</a>
        <?php else: ?>
          <button type="button" class="access-btn" id="accessBackButton">Return to Previous Page</button>
        <?php endif; ?>
        <a class="access-btn brand" href="dashboard.php">Go to Dashboard</a>
      </div>
      <div class="access-hint">Need access? Please contact your administrator.</div>
    </section>
  </main>
  <script>
    (function(){
      var backBtn = document.getElementById('accessBackButton');
      if(!backBtn) return;
      backBtn.addEventListener('click', function(){
        if (window.history.length > 1) {
          window.history.back();
        } else {
          window.location.href = 'dashboard.php';
        }
      });
    })();
  </script>
</body>
</html>
