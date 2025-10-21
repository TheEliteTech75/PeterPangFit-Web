<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function is_trainer_admin($role): bool {
  return in_array(strtolower((string)($role ?? 'guest')), ['trainer', 'admin'], true);
}

$VIEWER_ID   = (int)($_SESSION['user_id'] ?? $USER_ID ?? 0);
$VIEWER_ROLE = $_SESSION['role'] ?? ($USER_ROLE ?? 'guest');

$client_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($client_id <= 0) {
  http_response_code(400);
  echo 'Missing or invalid user_id';
  exit;
}

if (!is_trainer_admin($VIEWER_ROLE) && $client_id !== $VIEWER_ID) {
  http_response_code(403);
  echo 'Unauthorized';
  exit;
}

// Fetch client details
$stmt = $conn->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
  http_response_code(404);
  echo 'Client not found';
  exit;
}

// Plans assigned to this client
$plans = [];
$stmt = $conn->prepare('
  SELECT up.id   AS user_plan_id,
         up.assigned_at,
         p.id    AS plan_id,
         p.name  AS plan_name
  FROM user_plans up
  JOIN workout_plans p ON p.id = up.plan_id
  WHERE up.user_id = ?
  ORDER BY up.assigned_at DESC, up.id DESC
');
$stmt->bind_param('i', $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $plans[] = $row;
}
$stmt->close();

// Optional columns
$WEIGHT_COL       = null;
$HAS_USER_NOTES   = column_exists($conn, 'user_plan_exercises', 'user_notes');
$HAS_VIDEO_URL    = column_exists($conn, 'exercises', 'video_url');
$HAS_VIDEO_POSTER = column_exists($conn, 'exercises', 'video_poster_url');
$HAS_VIDEO_DUR    = column_exists($conn, 'exercises', 'video_duration_sec');

if (column_exists($conn, 'user_plan_exercises', 'weight_lbs')) {
  $WEIGHT_COL = 'weight_lbs';
} elseif (column_exists($conn, 'user_plan_exercises', 'weight')) {
  $WEIGHT_COL = 'weight';
}

$selectWeight = $WEIGHT_COL ? "upe.`{$WEIGHT_COL}` AS weight_val" : 'NULL AS weight_val';
$selectNotes  = $HAS_USER_NOTES ? 'upe.user_notes AS user_notes' : "'' AS user_notes";
$selectVideo  = $HAS_VIDEO_URL ? 'e.video_url AS video_url' : "'' AS video_url";
$selectPoster = $HAS_VIDEO_POSTER ? 'e.video_poster_url AS video_poster_url' : "'' AS video_poster_url";
$selectVDur   = $HAS_VIDEO_DUR ? 'e.video_duration_sec AS video_duration_sec' : 'NULL AS video_duration_sec';

$plan_items = [];
if ($plans) {
  $sql = "
    SELECT
      upe.user_plan_id,
      upe.exercise_id,
      upe.sets,
      upe.reps,
      upe.duration_seconds,
      upe.position,
      {$selectWeight},
      {$selectNotes},
      e.name AS exercise_name,
      e.notes AS coach_notes,
      {$selectVideo},
      {$selectPoster},
      {$selectVDur}
    FROM user_plan_exercises AS upe
    JOIN exercises e    ON e.id = upe.exercise_id
    JOIN user_plans up  ON up.id = upe.user_plan_id
    WHERE up.user_id = ?
    ORDER BY upe.user_plan_id, COALESCE(upe.position, 999999) ASC, e.name ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $client_id);
  $stmt->execute();
  $rs = $stmt->get_result();
  while ($row = $rs->fetch_assoc()) {
    $plan_items[(int)$row['user_plan_id']][] = $row;
  }
  $stmt->close();
}

function fmt_dur(?int $secs): string {
  if ($secs === null || $secs <= 0) return '';
  $m = intdiv($secs, 60);
  $s = $secs % 60;
  if ($m >= 60) {
    $h = intdiv($m, 60);
    $m = $m % 60;
    return sprintf('%dh %02dm', $h, $m);
  }
  return $s > 0 ? sprintf('%d:%02d', $m, $s) : sprintf('%d min', $m);
}

function total_duration_str(array $rows): string {
  $sum = 0;
  foreach ($rows as $r) {
    $sum += (int)($r['duration_seconds'] ?? 0);
  }
  if ($sum <= 0) return '—';
  $h = intdiv($sum, 3600);
  $m = intdiv($sum % 3600, 60);
  return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
}

function plan_search_blob(array $parts): string {
  $joined = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter(array_map('strval', $parts)))));
  return strtolower($joined);
}

$totalExercises = 0;
$latestAssignedTs = null;
$earliestAssignedTs = null;
foreach ($plans as $p) {
  $pid = (int)$p['user_plan_id'];
  $items = $plan_items[$pid] ?? [];
  $totalExercises += count($items);
  $ts = strtotime($p['assigned_at'] ?? '') ?: null;
  if ($ts !== null) {
    if ($latestAssignedTs === null || $ts > $latestAssignedTs) $latestAssignedTs = $ts;
    if ($earliestAssignedTs === null || $ts < $earliestAssignedTs) $earliestAssignedTs = $ts;
  }
}

$weightLabel = $WEIGHT_COL === 'weight_lbs' ? 'Weight (lb)' : ($WEIGHT_COL ? 'Weight' : 'Weight');

$clientName = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
$clientFirst = trim((string)($client['first_name'] ?? ''));
$isSelfView = ($client_id === $VIEWER_ID && !is_trainer_admin($VIEWER_ROLE));

$pageTitle = $isSelfView
  ? 'My Workout Plans'
  : ('Workout Plans — ' . ($clientName !== '' ? $clientName : ('Client #' . $client_id)));

$latestPlan = $plans[0] ?? null;
$latestPlanAssignedStr = $latestPlan && !empty($latestPlan['assigned_at'])
  ? date('M j, Y', strtotime($latestPlan['assigned_at']))
  : null;

$heroHeadlineName = $clientFirst !== '' ? $clientFirst : ($clientName !== '' ? $clientName : null);
$heroHeadline = $heroHeadlineName ? ('Welcome back, ' . $heroHeadlineName . '!') : 'Welcome back!';

$heroLine = $latestPlanAssignedStr
  ? 'Your workouts, videos, and coaching cues are queued up below. Open a plan to see exactly what to focus on today.'
  : 'As soon as your coach publishes a plan it’ll land here with videos, descriptions, and notes ready to go.';

$firstDate  = $earliestAssignedTs ? date('M j, Y', $earliestAssignedTs) : '—';
$latestPlanName = $latestPlan['plan_name'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo h($pageTitle); ?></title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #020202;
      --bg-soft: #050505;
      --surface: #0a0a0a;
      --surface-alt: #111111;
      --card: #161616;
      --card-border: rgba(0, 191, 255, 0.28);
      --card-border-subtle: rgba(255, 255, 255, 0.08);
      --card-border-hover: rgba(0, 191, 255, 0.45);
      --text: #f3f7ff;
      --muted: #9ca8bf;
      --muted-strong: #c4cee0;
      --accent: #00bfff;
      --accent-soft: rgba(0, 191, 255, 0.15);
      --accent-strong: #32cd32;
      --danger: #ff4c4c;
      --shadow: 0 28px 50px rgba(0, 0, 0, 0.55);
      --radius-lg: 28px;
      --radius: 20px;
      --radius-sm: 14px;
      --transition: 200ms cubic-bezier(.33,.13,.21,.99);
      --plan-nav-offset: 76px;
      --plan-nav-safe-offset: calc(var(--plan-nav-offset) + env(safe-area-inset-top, 0px));
      --plan-nav-gap: 0px;
      font-family: 'Inter', 'Segoe UI', Roboto, -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    html.plan-nav-locked,
    body.plan-nav-locked {
      overflow: hidden;
      touch-action: none;
      overscroll-behavior: none;
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at 12% 12%, rgba(0, 191, 255, 0.18), transparent 55%),
        radial-gradient(circle at 88% 8%, rgba(50, 205, 50, 0.1), transparent 50%),
        linear-gradient(180deg, var(--bg), var(--surface));
      color: var(--text);
      font-family: inherit;
      line-height: 1.6;
      min-height: 100vh;
      padding-bottom: 48px;
    }

    a {
      color: var(--accent);
    }

    main {
      --page-pad-x: clamp(12px, 2vw, 24px);
      padding: clamp(24px, 5vw, 64px) var(--page-pad-x);
      max-width: 100%;
      margin: 0 auto;
      width: 100%;
    }

    .hero {
      position: relative;
      padding: clamp(32px, 6vw, 64px);
      border-radius: clamp(22px, 7vw, 36px);
      overflow: hidden;
      background:
        linear-gradient(145deg, rgba(0, 191, 255, 0.22), rgba(0, 0, 0, 0.35)),
        var(--surface-alt);
      border: 1px solid var(--card-border-subtle);
      box-shadow: var(--shadow);
    }

    .hero::before {
      content: '';
      position: absolute;
      inset: -60px -120px;
      background:
        radial-gradient(circle at 15% 20%, rgba(0, 191, 255, 0.36), transparent 60%),
        radial-gradient(circle at 82% 28%, rgba(255, 76, 76, 0.18), transparent 60%);
      opacity: 0.65;
      z-index: 0;
    }

    .hero::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(120deg, rgba(0, 0, 0, 0.75), transparent 70%);
      z-index: 0;
    }

    .hero__wrap {
      position: relative;
      z-index: 1;
      display: grid;
      gap: clamp(24px, 5vw, 40px);
    }

    .hero__intro {
      display: grid;
      gap: 14px;
      max-width: 680px;
    }

    .hero__eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 16px;
      border-radius: 999px;
      background: rgba(0, 191, 255, 0.12);
      border: 1px solid rgba(0, 191, 255, 0.32);
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted-strong);
      width: fit-content;
    }

    .hero__eyebrow svg {
      width: 16px;
      height: 16px;
      stroke: var(--accent);
    }

    .hero__headline {
      margin: 0;
      font-size: clamp(34px, 7vw, 52px);
      line-height: 1.1;
      font-weight: 700;
      color: #ffffff;
    }

    .hero__subtitle {
      margin: 0;
      font-size: clamp(16px, 3.3vw, 20px);
      color: rgba(255, 255, 255, 0.72);
      max-width: 640px;
    }

    .hero__status {
      display: grid;
      gap: clamp(16px, 4vw, 28px);
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      align-content: start;
    }

    .hero-highlight {
      padding: clamp(18px, 3.6vw, 26px);
      border-radius: var(--radius);
      border: 1px solid var(--card-border);
      background:
        linear-gradient(140deg, rgba(0, 0, 0, 0.5), rgba(0, 191, 255, 0.2));
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.05);
      display: grid;
      gap: 12px;
    }

    .hero-highlight__label {
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .hero-highlight__name {
      font-size: clamp(20px, 3.5vw, 28px);
      font-weight: 600;
      color: var(--text);
      margin: 0;
    }

    .hero-highlight__meta {
      color: rgba(243, 247, 255, 0.72);
      font-size: 15px;
    }

    .hero__stats {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    }

    .hero-stat {
      padding: 18px 20px;
      border-radius: var(--radius-sm);
      border: 1px solid var(--card-border-subtle);
      background: rgba(10, 10, 10, 0.8);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
      display: grid;
      gap: 8px;
    }

    .hero-stat strong {
      display: block;
      font-size: clamp(24px, 4.5vw, 32px);
      color: var(--accent);
      font-weight: 700;
      line-height: 1.15;
    }

    .hero-stat__label {
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }

    .toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 16px 20px;
      margin-top: clamp(18px, 4vw, 30px);
      padding: clamp(14px, 3.5vw, 22px);
      border-radius: var(--radius);
      background: rgba(8, 8, 8, 0.88);
      border: 1px solid var(--card-border-subtle);
      box-shadow: var(--shadow);
    }

    .toolbar .search {
      flex: 1 1 260px;
      display: flex;
      align-items: center;
      gap: 12px;
      padding: clamp(10px, 1.8vw, 14px) clamp(18px, 3vw, 26px);
      border-radius: clamp(24px, 4vw, 999px);
      border: 1px solid rgba(0, 191, 255, 0.35);
      background: rgba(15, 15, 15, 0.9);
      color: var(--muted);
      min-width: 220px;
      min-height: clamp(46px, 9vw, 56px);
    }

    .toolbar .search svg {
      width: 18px;
      height: 18px;
      stroke: var(--accent);
    }

    .toolbar .search input {
      flex: 1;
      border: none;
      background: transparent;
      color: var(--text);
      font-size: clamp(14px, 3.5vw, 16px);
      outline: none;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(20, 20, 20, 0.82);
      font-size: 14px;
      color: var(--muted-strong);
      white-space: nowrap;
    }

    .chip .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: var(--accent);
    }

    .chip strong {
      color: var(--text);
      font-weight: 600;
    }

    .chip .dot.green {
      background: var(--accent-strong);
    }

    .actions {
      margin-left: auto;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 12px;
      border: 1px solid rgba(0, 191, 255, 0.45);
      background: linear-gradient(135deg, rgba(0, 191, 255, 0.95), rgba(50, 205, 50, 0.85));
      color: #001317;
      cursor: pointer;
      font-weight: 700;
      font-size: 14px;
      text-decoration: none;
      min-width: 140px;
      box-shadow: 0 16px 32px rgba(0, 191, 255, 0.28);
      transition: transform var(--transition), box-shadow var(--transition), filter var(--transition);
    }

    .btn:hover,
    .btn:focus-visible {
      outline: none;
      transform: translateY(-2px);
      box-shadow: 0 22px 40px rgba(0, 191, 255, 0.35);
      filter: brightness(1.05);
    }

    .plan-nav {
      margin-top: clamp(26px, 6vw, 38px);
      margin-bottom: clamp(28px, 7vw, 48px);
      display: grid;
      gap: 12px;
      position: relative;
    }

    .plan-nav__panel {
      display: grid;
      gap: 12px;
    }

    .plan-nav__panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .plan-nav__title {
      margin: 0;
      font-size: 14px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .plan-nav__close {
      display: none;
      align-items: center;
      justify-content: center;
      border: none;
      background: rgba(0, 191, 255, 0.14);
      color: var(--text);
      border-radius: 999px;
      width: 34px;
      height: 34px;
      cursor: pointer;
      transition: background var(--transition), transform var(--transition);
    }

    .plan-nav__close span {
      font-size: 18px;
      line-height: 1;
    }

    .plan-nav__close:hover,
    .plan-nav__close:focus-visible {
      outline: none;
      background: rgba(0, 191, 255, 0.24);
      transform: translateY(-1px);
    }

    .plan-nav__mobile-bar {
      display: none;
    }

    .plan-nav__mobile-trigger {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 18px;
      border-radius: 18px;
      border: 1px solid rgba(0, 191, 255, 0.35);
      background: rgba(10, 10, 10, 0.94);
      color: var(--text);
      font-weight: 600;
      font-size: 15px;
      cursor: pointer;
      box-shadow: 0 14px 32px rgba(0, 0, 0, 0.5);
      transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
    }

    .plan-nav__mobile-trigger svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
      transition: transform var(--transition);
    }

    .plan-nav__mobile-trigger[aria-expanded="true"] svg {
      transform: rotate(180deg);
    }

    .plan-nav__mobile-trigger:hover,
    .plan-nav__mobile-trigger:focus-visible {
      outline: none;
      transform: translateY(-1px);
      border-color: rgba(0, 191, 255, 0.55);
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.55);
    }

    .plan-nav__rail {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding: 2px 4px 6px;
      scrollbar-width: thin;
      scroll-snap-type: x mandatory;
      overscroll-behavior-x: contain;
      -webkit-overflow-scrolling: touch;
    }

    .plan-nav__rail::-webkit-scrollbar {
      height: 6px;
    }

    .plan-nav__rail::-webkit-scrollbar-thumb {
      background: rgba(0, 191, 255, 0.35);
      border-radius: 999px;
    }

    .plan-nav__button {
      flex: 0 0 auto;
      display: flex;
      flex-direction: column;
      gap: 6px;
      align-items: flex-start;
      padding: 12px 18px;
      border-radius: 999px;
      border: 1px solid rgba(0, 191, 255, 0.38);
      background: rgba(16, 20, 24, 0.92);
      color: var(--text);
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      scroll-snap-align: center;
      transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
    }

    .plan-nav__button:hover,
    .plan-nav__button:focus-visible {
      outline: none;
      transform: translateY(-2px);
      border-color: var(--card-border-hover);
      box-shadow: 0 16px 32px rgba(0, 191, 255, 0.28);
    }

    .plan-nav__button span {
      font-size: 12px;
      color: var(--muted);
    }

    .plan-section {
      margin-top: clamp(36px, 8vw, 60px);
    }

    .plan-grid {
      display: grid;
      gap: clamp(32px, 4vw, 48px);
      grid-template-columns: minmax(0, 1fr);
      align-items: stretch;
      width: 100%;
    }

    .plan-grid > .plan-card {
      align-self: stretch;
      width: 100%;
    }

    .plan-card {
      position: relative;
      background: var(--surface-alt);
      border-radius: var(--radius-lg);
      border: 1px solid var(--card-border-subtle);
      box-shadow: var(--shadow);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      will-change: transform, box-shadow;
      transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
    }

    .plan-card::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      background: linear-gradient(140deg, rgba(0, 191, 255, 0.18), rgba(0, 0, 0, 0));
      opacity: 0;
      transition: opacity var(--transition);
      z-index: 0;
    }

    .plan-card > * {
      position: relative;
      z-index: 1;
    }

    .plan-card.plan-card--open {
      border-color: var(--card-border);
      box-shadow: 0 30px 58px rgba(0, 0, 0, 0.6);
    }

    @media (hover: hover) and (pointer: fine) {
      .plan-card:hover {
        transform: translateY(-6px) scale(1.01);
        border-color: var(--card-border-hover);
        box-shadow: 0 32px 64px rgba(0, 0, 0, 0.62);
      }

      .plan-card:hover::after {
        opacity: 1;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .plan-card,
      .plan-card::after {
        transition-duration: 0ms !important;
        transition-property: none !important;
      }

      .plan-card:hover {
        transform: none;
      }
    }

    .plan-card__header {
      padding: clamp(22px, 5vw, 32px);
      display: flex;
      flex-wrap: wrap;
      gap: 16px 24px;
      align-items: flex-start;
      background:
        linear-gradient(130deg, rgba(0, 191, 255, 0.18), rgba(0, 0, 0, 0.6));
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      cursor: pointer;
      transition: background var(--transition), border-color var(--transition);
    }

    .plan-card.plan-card--open .plan-card__header {
      border-bottom-color: rgba(0, 191, 255, 0.25);
      background:
        linear-gradient(130deg, rgba(0, 191, 255, 0.24), rgba(0, 0, 0, 0.6));
    }

    .plan-card__intro {
      flex: 1 1 240px;
      display: grid;
      gap: 6px;
      min-width: 0;
    }

    .plan-card__title {
      margin: 0;
      font-size: clamp(24px, 4vw, 32px);
      line-height: 1.2;
      color: #ffffff;
    }

    .plan-card__meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 16px;
      color: rgba(240, 248, 255, 0.7);
      font-size: 14px;
    }

    .plan-card__pills {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .plan-card__pill {
      padding: 7px 12px;
      border-radius: 999px;
      background: rgba(0, 191, 255, 0.18);
      border: 1px solid rgba(0, 191, 255, 0.3);
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted-strong);
    }

    .plan-card__toggle {
      display: flex;
      gap: 8px;
      margin-left: auto;
    }

    .plan-card__toggle button {
      border: none;
      border-radius: 12px;
      padding: 10px 16px;
      background: rgba(0, 191, 255, 0.16);
      color: var(--text);
      font-weight: 600;
      cursor: pointer;
      transition: background var(--transition), transform var(--transition);
    }

    .plan-card__toggle button:hover,
    .plan-card__toggle button:focus-visible {
      outline: none;
      background: rgba(0, 191, 255, 0.24);
      transform: translateY(-1px);
    }

    .plan-card__body {
      display: grid;
      gap: 22px;
      padding: clamp(22px, 5vw, 34px);
      background: rgba(8, 8, 8, 0.85);
      flex: 1 1 auto;
    }

    .exercise-card {
      display: grid;
      gap: 20px;
      grid-template-columns: minmax(220px, 1fr) minmax(0, 1.2fr);
      background: rgba(20, 20, 20, 0.96);
      border-radius: var(--radius);
      padding: clamp(18px, 4vw, 28px);
      border: 1px solid rgba(255, 255, 255, 0.06);
      position: relative;
      overflow: hidden;
      box-shadow: 0 18px 36px rgba(0, 0, 0, 0.55);
      transition: transform var(--transition), border-color var(--transition), box-shadow var(--transition);
      align-items: start;
    }

    .exercise-card::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      border-radius: inherit;
      background: radial-gradient(circle at 18% 18%, rgba(0, 191, 255, 0.18), transparent 60%);
      opacity: 0;
      transition: opacity var(--transition);
    }

    .exercise-card:hover {
      transform: translateY(-3px);
      border-color: var(--card-border);
      box-shadow: 0 26px 48px rgba(0, 0, 0, 0.6);
    }

    .exercise-card:hover::after {
      opacity: 1;
    }

    .exercise-media {
      position: relative;
      border-radius: 16px;
      overflow: hidden;
      background: #000;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.05);
      aspect-ratio: 16 / 9;
      align-self: start;
      min-height: 0;
    }

    .exercise-media video,
    .exercise-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .exercise-media__badge {
      position: absolute;
      right: 12px;
      bottom: 12px;
      background: rgba(0, 0, 0, 0.65);
      color: #ffffff;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: 12px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    .exercise-media__fallback {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      text-align: center;
      padding: 22px;
      color: var(--muted-strong);
      background:
        linear-gradient(160deg, rgba(0, 0, 0, 0.8), rgba(0, 191, 255, 0.18));
      border: 1px dashed rgba(0, 191, 255, 0.4);
    }

    .exercise-media__fallback svg {
      width: 38px;
      height: 38px;
      stroke: var(--accent);
    }

    .exercise-body {
      display: grid;
      gap: 16px;
      min-width: 0;
    }

    .exercise-head {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 12px 18px;
    }

    .exercise-index {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 12px;
      background: rgba(0, 191, 255, 0.16);
      border: 1px solid rgba(0, 191, 255, 0.3);
      font-weight: 600;
      font-size: 15px;
      color: var(--text);
    }

    .exercise-name {
      margin: 0;
      font-size: clamp(20px, 3.5vw, 26px);
      font-weight: 600;
      color: var(--text);
      flex: 1 1 auto;
    }

    .exercise-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(0, 191, 255, 0.16);
      border: 1px solid rgba(0, 191, 255, 0.35);
      font-size: 13px;
      color: var(--muted-strong);
    }

    .badge svg {
      width: 16px;
      height: 16px;
      stroke: currentColor;
    }

    .notes-block {
      background: rgba(16, 18, 20, 0.9);
      border: 1px solid rgba(0, 191, 255, 0.18);
      border-radius: var(--radius-sm);
      padding: 16px 18px;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.03);
    }

    .notes-block h4 {
      margin: 0 0 8px;
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .notes-block p {
      margin: 0;
      color: var(--muted-strong);
      white-space: pre-line;
      font-size: 15px;
    }

    .notes-block.empty p {
      color: rgba(156, 168, 191, 0.55);
      font-style: italic;
    }

    .plan-footnote {
      margin-top: 6px;
      font-size: 12px;
      color: rgba(156, 168, 191, 0.65);
      text-align: right;
    }

    .page-empty {
      margin-top: 40px;
      padding: 30px;
      border-radius: var(--radius-lg);
      border: 1px dashed rgba(0, 191, 255, 0.3);
      background: rgba(12, 12, 12, 0.88);
      color: var(--muted-strong);
      font-size: 18px;
      text-align: center;
      box-shadow: var(--shadow);
    }

    @media (max-width: 1100px) {
      .hero__status {
        grid-template-columns: minmax(0, 1fr);
      }
      .exercise-card {
        grid-template-columns: minmax(0, 1fr);
      }
      .exercise-media {
        order: -1;
        min-height: 200px;
      }
      .exercise-body {
        order: 2;
      }
    }

    @media (max-width: 760px) {
      main {
        padding: clamp(20px, 6vw, 36px) clamp(14px, 6vw, 26px) 80px;
      }
      .hero {
        border-radius: clamp(18px, 9vw, 28px);
        padding: clamp(26px, 8vw, 40px);
      }
      .toolbar {
        flex-direction: column;
        align-items: stretch;
      }
      .toolbar .search {
        flex: 0 0 auto;
        width: 100%;
      }
      .actions {
        width: 100%;
        justify-content: center;
      }
      .actions .btn {
        flex: 1 1 auto;
        min-width: 0;
      }
      :root {
        --plan-nav-gap: clamp(8px, 3vw, 16px);
      }
      .plan-nav {
        position: sticky;
        top: calc(var(--plan-nav-safe-offset) + var(--plan-nav-gap));
        z-index: 12;
        gap: 10px;
      }
      .plan-nav.plan-nav--mobile {
        margin-left: calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
        width: 100vw;
        padding: 0;
      }
      .plan-nav--mobile.plan-nav--collapsed {
        position: sticky;
      }
      .plan-nav--mobile.plan-nav--expanded {
        position: fixed;
        top: calc(var(--plan-nav-safe-offset) + var(--plan-nav-gap));
        left: 0;
        right: 0;
        bottom: 0;
        margin: 0;
        width: 100vw;
        padding: 0;
        background: rgba(0, 0, 0, 0.88);
        backdrop-filter: blur(22px);
        z-index: 130;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        justify-content: flex-start;
        gap: 0;
        touch-action: none;
        height: calc(100vh - var(--plan-nav-safe-offset) - var(--plan-nav-gap));
        overflow: hidden;
      }
      .plan-nav--mobile .plan-nav__mobile-bar {
        display: flex;
        width: 100vw;
        margin-left: calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
        padding: 0;
        background: rgba(8, 8, 8, 0.96);
        border-bottom: 1px solid var(--card-border-subtle);
        box-shadow: 0 18px 36px rgba(0, 0, 0, 0.55);
      }
      .plan-nav__mobile-trigger {
        border-radius: 0;
        border: none;
        padding: clamp(16px, 5vw, 20px) calc(env(safe-area-inset-right, 0px) + clamp(24px, 7vw, 32px)) clamp(16px, 5vw, 20px) calc(env(safe-area-inset-left, 0px) + clamp(24px, 7vw, 32px));
        font-size: clamp(14px, 4vw, 16px);
        background: transparent;
        box-shadow: none;
      }
      .plan-nav__mobile-trigger:hover,
      .plan-nav__mobile-trigger:focus-visible {
        background: rgba(0, 191, 255, 0.14);
        border: none;
        transform: none;
        box-shadow: none;
      }
      .plan-nav--mobile .plan-nav__panel {
        position: relative;
        background: rgba(8, 8, 8, 0.96);
        backdrop-filter: blur(16px);
        border-radius: clamp(22px, 8vw, 28px);
        border: 1px solid var(--card-border-subtle);
        box-shadow: 0 22px 46px rgba(0, 0, 0, 0.6);
        padding: clamp(20px, 7vw, 28px);
        width: 100%;
        max-height: min(72vh, 460px);
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        touch-action: pan-y;
        min-height: 0;
      }
      .plan-nav--mobile.plan-nav--expanded .plan-nav__panel {
        flex: 1 1 auto;
        max-height: none;
        height: 100%;
        padding: clamp(22px, 8vw, 32px) clamp(20px, 7vw, 30px) calc(env(safe-area-inset-bottom, 0px) + clamp(30px, 9vw, 40px));
        border-radius: 0;
        border: none;
        box-shadow: none;
        display: flex;
        flex-direction: column;
        gap: clamp(22px, 7vw, 30px);
      }
      .plan-nav--mobile .plan-nav__panel-head {
        margin-bottom: 10px;
      }
      .plan-nav--mobile .plan-nav__close {
        display: inline-flex;
      }
      .plan-nav--mobile.plan-nav--expanded .plan-nav__panel-head {
        padding-top: 0;
      }
      .plan-nav--mobile.plan-nav--expanded .plan-nav__rail {
        flex: 1 1 auto;
        flex-direction: column;
        overflow-y: auto;
        overflow-x: hidden;
        scroll-snap-type: none;
        padding-bottom: clamp(20px, 7vw, 28px);
      }
      .plan-nav--mobile.plan-nav--expanded .plan-nav__button {
        width: 100%;
      }
      .plan-nav--mobile.plan-nav--collapsed .plan-nav__panel {
        display: none;
      }
      .plan-nav--mobile.plan-nav--collapsed .plan-nav__mobile-bar {
        display: flex;
      }
      .plan-nav--mobile.plan-nav--expanded .plan-nav__panel {
        display: flex;
      }
      .plan-nav--mobile.plan-nav--expanded .plan-nav__mobile-bar {
        display: none;
      }
      .plan-card__header {
        flex-direction: column;
      }
      .plan-card__toggle {
        width: 100%;
      }
      .plan-card__toggle button {
        width: 100%;
      }
      .plan-card__body {
        padding: clamp(18px, 7vw, 28px);
      }
      .exercise-card {
        padding: clamp(18px, 7vw, 24px);
        gap: 18px;
      }
      .exercise-media video,
      .exercise-media img {
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
      .plan-footnote {
        text-align: left;
      }
    }

    @media (min-width: 1024px) {
      .plan-grid {
        grid-template-columns: minmax(0, 1fr);
      }
    }

    @media (max-width: 760px) {
      .plan-grid {
        margin-left: calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
        width: 100vw;
        padding-left: calc(env(safe-area-inset-left, 0px) + clamp(12px, 6vw, 18px));
        padding-right: calc(env(safe-area-inset-right, 0px) + clamp(12px, 6vw, 18px));
      }
    }

    @media (min-width: 1200px) {
      .hero__wrap {
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
        grid-template-areas:
          'intro status'
          'toolbar status';
      }
      .hero__intro {
        grid-area: intro;
      }
      .hero__status {
        grid-area: status;
      }
      .toolbar {
        grid-area: toolbar;
        justify-content: flex-start;
      }
      .plan-nav__rail {
        overflow-x: visible;
        flex-wrap: wrap;
        row-gap: 16px;
        scroll-snap-type: none;
      }
      .plan-nav__button {
        min-width: 220px;
      }
      .hero__status {
        align-self: stretch;
      }
    }

    @media (min-width: 1440px) {
      main {
        max-width: 100%;
        padding-left: clamp(20px, 1.8vw, 28px);
        padding-right: clamp(20px, 1.8vw, 28px);
      }
      .hero__wrap {
        grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
      }
    }

    @media (max-width: 600px) {
      .plan-nav__rail {
        flex-direction: column;
        overflow-x: visible;
        scroll-snap-type: none;
        gap: 10px;
      }
      .plan-nav__button {
        width: 100%;
        text-align: left;
      }
      .chip {
        width: 100%;
        justify-content: center;
      }
      .actions {
        width: 100%;
        flex-direction: column;
        gap: 12px;
      }
      .actions .btn {
        width: 100%;
      }
    }

    .plan-card--highlight {
      animation: planPulse 1.1s ease;
    }

    @keyframes planPulse {
      0% {
        box-shadow: 0 0 0 0 rgba(0, 191, 255, 0.45);
      }
      60% {
        box-shadow: 0 0 0 14px rgba(0, 191, 255, 0.02);
      }
      100% {
        box-shadow: var(--shadow);
      }
    }
  </style>
</head>
<body>

<div class="hero">
  <div class="hero__wrap">
    <div class="hero__intro">
      <span class="hero__eyebrow">Your training home</span>
      <h1 class="hero__headline"><?php echo h($heroHeadline); ?></h1>
      <p class="hero__subtitle"><?php echo h($heroLine); ?></p>
    </div>
    <div class="hero__status">
      <div class="hero-highlight">
        <div class="hero-highlight__label">Latest plan</div>
        <div class="hero-highlight__name"><?php echo $latestPlanName !== '' ? h($latestPlanName) : 'Plan coming soon'; ?></div>
        <div class="hero-highlight__meta">
          <?php if ($latestPlanAssignedStr): ?>Assigned <?php echo h($latestPlanAssignedStr); ?><?php else: ?>Waiting on your coach<?php endif; ?>
        </div>
      </div>
      <div class="hero__stats">
        <div class="hero-stat">
          <span class="hero-stat__label">Active plans</span>
          <strong><?php echo count($plans); ?></strong>
        </div>
        <div class="hero-stat">
          <span class="hero-stat__label">Exercises ready</span>
          <strong><?php echo $totalExercises; ?></strong>
        </div>
        <div class="hero-stat">
          <span class="hero-stat__label">First plan posted</span>
          <strong><?php echo h($firstDate); ?></strong>
        </div>
      </div>
    </div>
    <div class="toolbar">
      <div class="search" title="Search across all plans">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M21 21l-3.8-3.8m1.8-5.2a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
        <input id="globalSearch" type="search" placeholder="Search your workouts" autocomplete="off" />
      </div>
      <span class="chip"><span class="dot blue"></span><span>Plans</span> <strong id="chipPlans"><?php echo count($plans); ?></strong></span>
      <span class="chip"><span class="dot green"></span><span>Exercises shown</span> <strong id="chipItems" data-total="<?php echo $totalExercises; ?>"><?php echo $totalExercises; ?></strong></span>
      <div class="actions">
        <button class="btn" type="button" id="btnExpandAll">Open all workouts</button>
        <button class="btn" type="button" id="btnCollapseAll">Close all workouts</button>
      </div>
    </div>
  </div>
</div>

<main>
  <?php if (!$plans): ?>
    <div class="page-empty">No workout plans have been assigned yet. Check back soon!</div>
  <?php else: ?>
    <section class="plan-nav plan-nav--expanded" data-plan-nav-container>
      <div class="plan-nav__mobile-bar" data-plan-nav-bar>
        <button type="button" class="plan-nav__mobile-trigger" data-plan-nav-open aria-expanded="true" aria-controls="planNavPanel">
          <span>Jump to a plan</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6"></path>
          </svg>
        </button>
      </div>
      <div class="plan-nav__panel" id="planNavPanel" data-plan-nav-panel>
        <div class="plan-nav__panel-head">
          <h2 class="plan-nav__title">Jump to a plan</h2>
          <button type="button" class="plan-nav__close" data-plan-nav-close aria-label="Close plan navigation">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="plan-nav__rail">
        <?php foreach ($plans as $planNav):
          $navId = (int)$planNav['user_plan_id'];
          $navAssigned = !empty($planNav['assigned_at']) ? date('M j, Y', strtotime($planNav['assigned_at'])) : 'No date set';
        ?>
          <button type="button" class="plan-nav__button" data-plan-nav data-target="plan-<?php echo $navId; ?>">
            <?php echo h($planNav['plan_name']); ?>
            <span><?php echo h($navAssigned); ?></span>
          </button>
        <?php endforeach; ?>
        </div>
      </div>
    </section>
    <div class="plan-grid" id="plansGrid">
      <?php foreach ($plans as $plan):
        $pid   = (int)$plan['user_plan_id'];
        $items = $plan_items[$pid] ?? [];
        $exCount = count($items);
        $sumSets = 0;
        $sumReps = 0;
        foreach ($items as $it) {
          $sumSets += (int)($it['sets'] ?? 0);
          $sumReps += (int)($it['reps'] ?? 0);
        }
        $durStr = total_duration_str($items);
        $assignedStr = $plan['assigned_at'] ? date('M j, Y g:ia', strtotime($plan['assigned_at'])) : '—';
      ?>
      <section class="plan-card plan-card--open" id="plan-<?php echo $pid; ?>" data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($plan['plan_name']); ?>" data-plan-assigned="<?php echo h($assignedStr); ?>">
        <div class="plan-card__header">
          <div>
            <h2 class="plan-card__title"><?php echo h($plan['plan_name']); ?></h2>
            <div class="plan-card__meta">Assigned on <?php echo h($assignedStr); ?></div>
            <div class="plan-card__pills">
              <span class="plan-card__pill"><strong><?php echo $exCount; ?></strong> exercises</span>
              <span class="plan-card__pill"><strong><?php echo $sumSets; ?></strong> total sets</span>
              <span class="plan-card__pill"><strong><?php echo $sumReps; ?></strong> total reps</span>
              <span class="plan-card__pill"><strong><?php echo h($durStr); ?></strong> est. duration</span>
            </div>
          </div>
          <div class="plan-card__toggle">
            <button type="button" data-plan-toggle aria-expanded="true" data-target="#plan-body-<?php echo $pid; ?>">Hide workout</button>
          </div>
        </div>
        <div class="plan-card__body" id="plan-body-<?php echo $pid; ?>">
          <?php if (!$items): ?>
            <div class="plan-card__empty">This plan doesn’t have any exercises yet.</div>
          <?php else: ?>
            <?php $index = 1; foreach ($items as $exercise):
              $weightOut = ($exercise['weight_val'] !== null && $exercise['weight_val'] !== '') ? (string)$exercise['weight_val'] : '';
              $coachNotes = trim((string)($exercise['user_notes'] ?? ''));
              $exerciseDescription = trim((string)($exercise['coach_notes'] ?? ''));
              $durationStr = fmt_dur($exercise['duration_seconds'] ?? null);
              $videoUrl = trim((string)($exercise['video_url'] ?? ''));
              $posterUrl = trim((string)($exercise['video_poster_url'] ?? ''));
              $videoBadge = '';
              if ($videoUrl !== '') {
                if (!empty($exercise['video_duration_sec'])) {
                  $sec = (int)$exercise['video_duration_sec'];
                  $videoBadge = sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
                } else {
                  $videoBadge = 'Video';
                }
              }
              $searchBlob = plan_search_blob([
                $exercise['exercise_name'] ?? '',
                $coachNotes,
                $exerciseDescription,
                $weightOut,
                $durationStr
              ]);
            ?>
            <article class="exercise-card" data-search="<?php echo h($searchBlob); ?>"
                     data-order="<?php echo $index; ?>"
                     data-name="<?php echo h($exercise['exercise_name']); ?>"
                     data-sets="<?php echo h($exercise['sets'] !== null ? (int)$exercise['sets'] : ''); ?>"
                     data-reps="<?php echo h($exercise['reps'] !== null ? (int)$exercise['reps'] : ''); ?>"
                     data-weight="<?php echo h($weightOut); ?>"
                     data-duration="<?php echo h($durationStr); ?>"
                     data-coach-notes="<?php echo h($coachNotes); ?>"
            >
              <div class="exercise-media">
                <?php if ($videoUrl !== ''): ?>
                  <video controls preload="metadata" playsinline poster="<?php echo $posterUrl !== '' ? h($posterUrl) : ''; ?>">
                    <source src="<?php echo h($videoUrl); ?>" type="video/mp4" />
                    Your browser does not support embedded videos.
                  </video>
                  <?php if ($videoBadge !== ''): ?>
                    <span class="exercise-media__badge"><?php echo h($videoBadge); ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="exercise-media__fallback">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <rect x="3" y="5" width="18" height="14" rx="2" ry="2"></rect>
                      <path d="m10 9 5 3-5 3V9z"></path>
                    </svg>
                    <span>Video coming soon</span>
                  </div>
                <?php endif; ?>
              </div>
              <div class="exercise-body">
                <div class="exercise-head">
                  <span class="exercise-index">#<?php echo $index; ?></span>
                  <h3 class="exercise-name"><?php echo h($exercise['exercise_name']); ?></h3>
                </div>
                <div class="exercise-meta">
                  <?php if ($exercise['sets'] !== null && $exercise['sets'] !== ''): ?>
                    <span class="badge" title="Sets">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m9 12 2 2 4-4"></path></svg>
                      <?php echo (int)$exercise['sets']; ?> sets
                    </span>
                  <?php endif; ?>
                  <?php if ($exercise['reps'] !== null && $exercise['reps'] !== ''): ?>
                    <span class="badge" title="Reps">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 4H3"></path><path d="M18 4v6a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V4"></path><path d="M9 14h6"></path><path d="M8 18h8"></path><path d="M10 22h4"></path></svg>
                      <?php echo (int)$exercise['reps']; ?> reps
                    </span>
                  <?php endif; ?>
                  <?php if ($weightOut !== ''): ?>
                    <span class="badge" title="<?php echo h($weightLabel); ?>">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 6.5h11v11h-11z"></path><path d="M10 10h4v4h-4z"></path></svg>
                      <?php echo h($weightOut); ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($durationStr !== ''): ?>
                    <span class="badge" title="Duration">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 3"></path></svg>
                      <?php echo h($durationStr); ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="notes-block<?php echo $exerciseDescription === '' ? ' empty' : ''; ?>">
                  <h4>Exercise description</h4>
                  <p><?php echo $exerciseDescription !== '' ? nl2br(h($exerciseDescription)) : 'No description provided yet.'; ?></p>
                </div>
                <div class="notes-block<?php echo $coachNotes === '' ? ' empty' : ''; ?>">
                  <h4>Coach notes</h4>
                  <p><?php echo $coachNotes !== '' ? nl2br(h($coachNotes)) : 'No additional coaching notes provided.'; ?></p>
                </div>
              </div>
            </article>
            <?php $index++; endforeach; ?>
          <?php endif; ?>
          <div class="plan-card__empty-filter" style="display:none;">No exercises match your search in this plan.</div>
          <div class="plan-footnote">Need to find something fast? Use the search above to spotlight exercises across your plans.</div>
        </div>
      </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<script>
(function(){
  const plans = Array.from(document.querySelectorAll('[data-plan-id]'));
  const searchInput = document.getElementById('globalSearch');
  const chipItems = document.getElementById('chipItems');
  const chipTotal = parseInt(chipItems?.dataset?.total || '0', 10);
  const btnExpand = document.getElementById('btnExpandAll');
  const btnCollapse = document.getElementById('btnCollapseAll');
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const navSection = document.querySelector('[data-plan-nav-container]');
  const navOpenTrigger = navSection?.querySelector('[data-plan-nav-open]');
  const navCloseBtn = navSection?.querySelector('[data-plan-nav-close]');
  const navPanel = navSection?.querySelector('[data-plan-nav-panel]');
  const navMobileQuery = window.matchMedia('(max-width: 760px)');
  const rootEl = document.documentElement;
  const topbarEl = document.querySelector('.ppf-topbar');
  let navSkipNextScroll = false;
  let navScrollHoldTimer = null;
  let navScrollLockY = 0;
  let navOffsetRaf = null;

  function updatePlanNavOffset() {
    const measured = topbarEl ? Math.round(topbarEl.getBoundingClientRect().height) : 76;
    rootEl.style.setProperty('--plan-nav-offset', `${Math.max(measured, 1)}px`);
  }

  function schedulePlanNavOffsetUpdate() {
    if (navOffsetRaf) {
      cancelAnimationFrame(navOffsetRaf);
    }
    navOffsetRaf = requestAnimationFrame(() => {
      navOffsetRaf = null;
      updatePlanNavOffset();
    });
  }

  updatePlanNavOffset();
  window.addEventListener('resize', schedulePlanNavOffsetUpdate);
  window.addEventListener('orientationchange', schedulePlanNavOffsetUpdate);
  window.addEventListener('load', updatePlanNavOffset, { once: true });

  function lockBodyForNav() {
    if (!navMobileQuery.matches) return;
    if (document.body.classList.contains('plan-nav-locked')) return;
    navScrollLockY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
    document.documentElement.classList.add('plan-nav-locked');
    document.body.classList.add('plan-nav-locked');
    document.body.dataset.planNavScrollY = String(navScrollLockY);
    document.body.style.position = 'fixed';
    document.body.style.top = `-${navScrollLockY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
  }

  function unlockBodyForNav() {
    if (!document.body.classList.contains('plan-nav-locked')) return;
    const stored = parseInt(document.body.dataset.planNavScrollY || '0', 10);
    document.documentElement.classList.remove('plan-nav-locked');
    document.body.classList.remove('plan-nav-locked');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    delete document.body.dataset.planNavScrollY;
    window.scrollTo(0, stored);
  }

  function holdNavScrollBuffer(duration = 260) {
    navSkipNextScroll = true;
    if (navScrollHoldTimer) {
      window.clearTimeout(navScrollHoldTimer);
    }
    navScrollHoldTimer = window.setTimeout(() => {
      navSkipNextScroll = false;
      navScrollHoldTimer = null;
    }, duration);
  }

  function applyNavResponsiveState() {
    if (!navSection) return;
    if (navMobileQuery.matches) {
      navSection.classList.add('plan-nav--mobile');
      if (!navSection.classList.contains('plan-nav--collapsed') && !navSection.classList.contains('plan-nav--expanded')) {
        navSection.classList.add('plan-nav--expanded');
      }
    } else {
      navSection.classList.remove('plan-nav--mobile', 'plan-nav--collapsed');
      navSection.classList.add('plan-nav--expanded');
      navSkipNextScroll = false;
      navOpenTrigger?.setAttribute('aria-expanded', 'true');
      unlockBodyForNav();
    }
    schedulePlanNavOffsetUpdate();
  }

  function collapsePlanNav() {
    if (!navSection || !navSection.classList.contains('plan-nav--mobile')) return;
    navSection.classList.add('plan-nav--collapsed');
    navSection.classList.remove('plan-nav--expanded');
    navOpenTrigger?.setAttribute('aria-expanded', 'false');
    navSkipNextScroll = false;
    if (navScrollHoldTimer) {
      window.clearTimeout(navScrollHoldTimer);
      navScrollHoldTimer = null;
    }
    unlockBodyForNav();
    schedulePlanNavOffsetUpdate();
  }

  function expandPlanNav(manual = false) {
    if (!navSection) return;
    navSection.classList.add('plan-nav--expanded');
    navSection.classList.remove('plan-nav--collapsed');
    navOpenTrigger?.setAttribute('aria-expanded', 'true');
    if (navSection.classList.contains('plan-nav--mobile')) {
      lockBodyForNav();
      if (manual) {
        holdNavScrollBuffer(420);
      }
    } else {
      unlockBodyForNav();
    }
    schedulePlanNavOffsetUpdate();
  }

  applyNavResponsiveState();
  if (navSection) {
    if (navSection.classList.contains('plan-nav--mobile')) {
      collapsePlanNav();
    } else {
      expandPlanNav(false);
    }
  }

  navMobileQuery.addEventListener('change', () => {
    applyNavResponsiveState();
    if (navSection?.classList.contains('plan-nav--mobile')) {
      collapsePlanNav();
    } else if (navSection) {
      expandPlanNav(false);
    }
  });

  navOpenTrigger?.addEventListener('click', () => {
    expandPlanNav(true);
  });

  navCloseBtn?.addEventListener('click', () => {
    collapsePlanNav();
  });

  window.addEventListener('scroll', () => {
    if (!navSection || !navMobileQuery.matches) return;
    if (navSkipNextScroll) {
      return;
    }
    if (window.scrollY > 40) {
      collapsePlanNav();
    } else {
      expandPlanNav(false);
    }
  }, { passive: true });

  if (navPanel) {
    const attachBuffer = (duration) => {
      if (!navSection?.classList.contains('plan-nav--mobile') || !navSection.classList.contains('plan-nav--expanded')) return;
      holdNavScrollBuffer(duration);
    };
    ['touchstart', 'touchmove'].forEach(evt => {
      navPanel.addEventListener(evt, () => attachBuffer(320), { passive: true });
    });
    navPanel.addEventListener('wheel', () => attachBuffer(320), { passive: true });
    navPanel.addEventListener('scroll', () => attachBuffer(220), { passive: true });
    navPanel.addEventListener('touchend', () => attachBuffer(200), { passive: true });
  }

  function cleanupAnimation(body) {
    if (body._transitionHandler) {
      body.removeEventListener('transitionend', body._transitionHandler);
      body._transitionHandler = null;
    }
    body.style.transition = '';
    body.style.maxHeight = '';
    body.style.opacity = '';
    body.style.overflow = '';
  }

  function setPlanVisibility(section, open, options = {}) {
    const targetSel = section.querySelector('[data-plan-toggle]')?.getAttribute('data-target');
    const body = targetSel ? document.querySelector(targetSel) : section.querySelector('.plan-card__body');
    const toggle = section.querySelector('[data-plan-toggle]');
    const { skipAnimation = false } = options;
    if (!body) return;

    const isOpen = section.classList.contains('plan-card--open');
    const shouldAnimate = !skipAnimation && !prefersReducedMotion;

    cleanupAnimation(body);

    if (open === isOpen) {
      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.textContent = open ? 'Hide workout' : 'Show workout';
      }
      section.setAttribute('data-open', open ? 'true' : 'false');
      if (open && body.style.display === 'none') {
        body.style.display = 'grid';
      }
      return;
    }

    if (open) {
      section.classList.add('plan-card--open');
      section.setAttribute('data-open', 'true');
      if (shouldAnimate) {
        body.style.display = 'grid';
        const fullHeight = body.scrollHeight;
        body.style.overflow = 'hidden';
        body.style.maxHeight = '0px';
        body.style.opacity = '0';
        requestAnimationFrame(() => {
          body.style.transition = 'max-height 0.45s ease, opacity 0.3s ease';
          body.style.maxHeight = fullHeight + 'px';
          body.style.opacity = '1';
        });
        const onEnd = (event) => {
          if (event.propertyName !== 'max-height') return;
          body.style.transition = '';
          body.style.maxHeight = '';
          body.style.opacity = '';
          body.style.overflow = '';
          body.removeEventListener('transitionend', onEnd);
          body._transitionHandler = null;
        };
        body._transitionHandler = onEnd;
        body.addEventListener('transitionend', onEnd);
      } else {
        body.style.display = 'grid';
        body.style.maxHeight = '';
        body.style.opacity = '';
        body.style.overflow = '';
      }
    } else {
      section.classList.remove('plan-card--open');
      section.setAttribute('data-open', 'false');
      if (shouldAnimate) {
        body.style.display = 'grid';
        const fullHeight = body.scrollHeight;
        body.style.overflow = 'hidden';
        body.style.maxHeight = fullHeight + 'px';
        body.style.opacity = '1';
        requestAnimationFrame(() => {
          body.style.transition = 'max-height 0.4s ease, opacity 0.25s ease';
          body.style.maxHeight = '0px';
          body.style.opacity = '0';
        });
        const onEnd = (event) => {
          if (event.propertyName !== 'max-height') return;
          body.style.transition = '';
          body.style.display = 'none';
          body.style.maxHeight = '';
          body.style.opacity = '';
          body.style.overflow = '';
          body.removeEventListener('transitionend', onEnd);
          body._transitionHandler = null;
        };
        body._transitionHandler = onEnd;
        body.addEventListener('transitionend', onEnd);
      } else {
        body.style.display = 'none';
        body.style.maxHeight = '';
        body.style.opacity = '';
        body.style.overflow = '';
      }
    }

    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.textContent = open ? 'Hide workout' : 'Show workout';
    }
  }

  plans.forEach(section => {
    section.setAttribute('data-open', 'true');
    const toggle = section.querySelector('[data-plan-toggle]');
    if (toggle) {
      toggle.addEventListener('click', event => {
        event.stopPropagation();
        const isOpen = section.classList.contains('plan-card--open');
        setPlanVisibility(section, !isOpen);
      });
    }

    section.addEventListener('click', event => {
      if (event.target.closest('[data-plan-toggle]')) return;
      if (event.target.closest('.plan-card__body')) return;
      const isOpen = section.classList.contains('plan-card--open');
      setPlanVisibility(section, !isOpen);
    });
  });

  btnExpand?.addEventListener('click', () => plans.forEach(section => setPlanVisibility(section, true, { skipAnimation: true })));
  btnCollapse?.addEventListener('click', () => plans.forEach(section => setPlanVisibility(section, false, { skipAnimation: true })));

  function applySearch() {
    if (!chipItems) return;
    const term = (searchInput?.value || '').trim().toLowerCase();
    let visible = 0;
    plans.forEach(section => {
      const exercises = Array.from(section.querySelectorAll('.exercise-card'));
      let matches = 0;
      exercises.forEach(card => {
        const haystack = card.getAttribute('data-search') || '';
        const hit = !term || haystack.includes(term);
        card.style.display = hit ? '' : 'none';
        if (hit) {
          matches++;
          visible++;
        }
      });
      const emptyMsg = section.querySelector('.plan-card__empty-filter');
      if (emptyMsg) {
        emptyMsg.style.display = matches === 0 && term ? '' : 'none';
      }
      if (!matches && term) {
        setPlanVisibility(section, true, { skipAnimation: true });
      }
    });
    if (term) {
      chipItems.textContent = visible;
    } else {
      chipItems.textContent = chipTotal;
    }
  }

  searchInput?.addEventListener('input', applySearch);

  const planNavButtons = document.querySelectorAll('[data-plan-nav]');

  planNavButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-target');
      if (!targetId) return;
      const section = document.getElementById(targetId);
      if (!section) return;
      setPlanVisibility(section, true, { skipAnimation: true });
      section.scrollIntoView({behavior: 'smooth', block: 'start'});
      section.classList.add('plan-card--highlight');
      setTimeout(() => section.classList.remove('plan-card--highlight'), 1200);
      if (navMobileQuery.matches) {
        collapsePlanNav();
      }
    });
  });
})();
</script>

</body>
</html>
