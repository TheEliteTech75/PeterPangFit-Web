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

$heroHeadline = $isSelfView
  ? ($clientFirst !== '' ? ('Welcome back, ' . $clientFirst . '!') : 'Welcome back!')
  : ($clientName !== '' ? ('Viewing ' . $clientName . '\'s workouts') : 'Workout plans overview');

$heroLine = $isSelfView
  ? ($latestPlanAssignedStr
      ? 'Your workouts, videos, and coaching cues are queued up below. Open a plan to see exactly what to focus on today.'
      : 'As soon as your coach publishes a plan it’ll land here with videos, descriptions, and notes ready to go.')
  : 'Preview the client experience exactly as they see it—videos, descriptions, and coaching notes included.';

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
      color-scheme: light;
      --bg: #f7f8ff;
      --bg-soft: #ffffff;
      --card: #ffffff;
      --card-border: rgba(82, 109, 255, 0.16);
      --card-border-hover: rgba(82, 109, 255, 0.36);
      --text: #1c1f3b;
      --muted: #5d6483;
      --accent: #4e63ff;
      --accent-soft: rgba(78, 99, 255, 0.12);
      --highlight: #0ab4a6;
      --chip-bg: rgba(78, 99, 255, 0.09);
      --chip-border: rgba(78, 99, 255, 0.2);
      --shadow: 0 28px 50px rgba(119, 136, 196, 0.18);
      --radius: 22px;
      --radius-sm: 14px;
      --transition: 220ms cubic-bezier(.4,.12,.2,1);
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      background:
        radial-gradient(circle at -10% -20%, rgba(78, 99, 255, 0.18), transparent 54%),
        radial-gradient(circle at 90% 10%, rgba(10, 180, 166, 0.18), transparent 46%),
        var(--bg);
      color: var(--text);
      font-family: 'Inter', 'Segoe UI', Roboto, -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
      line-height: 1.6;
      min-height: 100vh;
    }

    a { color: var(--accent); }

    main {
      padding: 28px clamp(18px, 4vw, 60px) 80px;
      max-width: min(1100px, 100%);
      margin-left: auto;
      margin-right: auto;
    }

    .hero {
      position: relative;
      padding: clamp(32px, 6vw, 64px) clamp(18px, 4vw, 60px);
      overflow: hidden;
      border-radius: clamp(26px, 6vw, 40px);
      background: var(--bg-soft);
      box-shadow: var(--shadow);
      margin: clamp(12px, 4vw, 36px) auto 0;
    }

    .hero::before {
      content: '';
      position: absolute;
      inset: -120px -180px -80px -180px;
      background:
        radial-gradient(circle at 18% 20%, rgba(78, 99, 255, 0.22), transparent 55%),
        radial-gradient(circle at 80% 10%, rgba(10, 180, 166, 0.18), transparent 50%);
      z-index: -2;
    }

    .hero::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(180deg, rgba(255,255,255,0.85), rgba(247, 249, 255, 0.92));
      opacity: 0.6;
      z-index: -3;
    }

    .hero__wrap {
      position: relative;
      z-index: 1;
      display: grid;
      gap: clamp(24px, 5vw, 40px);
    }

    .hero__intro {
      display: grid;
      gap: 12px;
      max-width: 720px;
    }

    .hero__eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px;
      background: rgba(78, 99, 255, 0.1);
      border: 1px solid rgba(78, 99, 255, 0.24);
      border-radius: 999px;
      font-size: 13px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--accent);
      width: fit-content;
    }

    .hero__headline {
      margin: 0;
      font-size: clamp(34px, 6vw, 50px);
      line-height: 1.08;
      font-weight: 700;
      color: #131739;
    }

    .hero__subtitle {
      margin: 0;
      font-size: clamp(16px, 3.2vw, 20px);
      max-width: 680px;
      color: rgba(19, 31, 59, 0.72);
    }

    .hero__status {
      display: grid;
      gap: clamp(16px, 4vw, 28px);
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      align-items: stretch;
    }

    .hero-highlight {
      padding: clamp(18px, 3.5vw, 26px);
      background: linear-gradient(150deg, rgba(255, 255, 255, 0.92), rgba(233, 239, 255, 0.88));
      border-radius: var(--radius);
      border: 1px solid rgba(78, 99, 255, 0.18);
      display: grid;
      gap: 10px;
      box-shadow: 0 20px 45px rgba(126, 144, 212, 0.22);
    }

    .hero-highlight__label {
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(78, 99, 255, 0.75);
    }

    .hero-highlight__name {
      font-size: clamp(20px, 3.6vw, 26px);
      font-weight: 600;
      line-height: 1.3;
      color: #0e1b40;
    }

    .hero-highlight__meta {
      color: rgba(19, 31, 59, 0.7);
      font-size: 15px;
    }

    .hero__stats {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }

    .hero-stat {
      padding: 18px 20px;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(82, 109, 255, 0.16);
      border-radius: var(--radius-sm);
      display: grid;
      gap: 8px;
      box-shadow: 0 16px 30px rgba(139, 156, 214, 0.18);
    }

    .hero-stat strong {
      display: block;
      font-size: clamp(24px, 4vw, 30px);
      font-weight: 700;
      line-height: 1.15;
      color: #121942;
    }

    .hero-stat__label {
      color: rgba(28, 31, 59, 0.58);
      font-size: 13px;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 14px;
      margin-top: clamp(18px, 3vw, 28px);
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(82, 109, 255, 0.16);
      border-radius: 20px;
      padding: clamp(14px, 3vw, 18px);
      box-shadow: 0 14px 30px rgba(142, 158, 214, 0.18);
    }

    .toolbar .search {
      flex: 1 1 260px;
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(82, 109, 255, 0.25);
      border-radius: 999px;
      padding: 10px 16px;
      min-width: 200px;
      color: var(--muted);
    }

    .toolbar .search svg {
      stroke: var(--accent);
    }

    .toolbar .search input {
      flex: 1;
      border: none;
      background: transparent;
      color: var(--text);
      font-size: 15px;
      outline: none;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 14px;
      border-radius: 999px;
      border: 1px solid var(--chip-border);
      background: var(--chip-bg);
      font-size: 14px;
      color: rgba(28, 31, 59, 0.82);
      white-space: nowrap;
    }

    .chip .dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }

    .chip strong {
      color: #121942;
    }

    .chip .dot.blue { background: var(--accent); }
    .chip .dot.green { background: var(--highlight); }

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
      padding: 10px 16px;
      border-radius: 12px;
      border: 1px solid rgba(82, 109, 255, 0.26);
      background: linear-gradient(135deg, #5366ff, #6b7eff);
      color: #fff;
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      transition: background var(--transition), transform var(--transition), border-color var(--transition), box-shadow var(--transition);
      min-width: 140px;
      box-shadow: 0 12px 20px rgba(105, 125, 210, 0.26);
    }

    .btn:hover {
      background: linear-gradient(135deg, #4b5cf7, #6476ff);
      border-color: rgba(82, 109, 255, 0.36);
      transform: translateY(-1px);
      box-shadow: 0 16px 26px rgba(105, 125, 210, 0.32);
    }

    .plan-nav {
      margin-top: clamp(26px, 5vw, 36px);
      display: grid;
      gap: 12px;
    }

    .plan-nav__title {
      margin: 0;
      font-size: 15px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(28, 31, 59, 0.6);
    }

    .plan-nav__rail {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding-bottom: 6px;
      scrollbar-width: thin;
      padding-right: 4px;
      scroll-snap-type: x mandatory;
      overscroll-behavior-x: contain;
      -webkit-overflow-scrolling: touch;
    }

    .plan-nav__rail::-webkit-scrollbar {
      height: 6px;
    }

    .plan-nav__rail::-webkit-scrollbar-thumb {
      background: rgba(78, 99, 255, 0.26);
      border-radius: 999px;
    }

    .plan-nav__button {
      flex: 0 0 auto;
      padding: 12px 16px;
      border-radius: 14px;
      border: 1px solid rgba(82, 109, 255, 0.22);
      background: rgba(255, 255, 255, 0.95);
      color: #1f2550;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: background var(--transition), border-color var(--transition), transform var(--transition), box-shadow var(--transition);
      text-align: left;
      box-shadow: 0 10px 18px rgba(142, 158, 214, 0.18);
      scroll-snap-align: start;
    }

    .plan-nav__button:hover,
    .plan-nav__button:focus-visible {
      outline: none;
      background: linear-gradient(120deg, rgba(83, 102, 255, 0.18), rgba(255, 255, 255, 0.95));
      border-color: rgba(83, 102, 255, 0.42);
      transform: translateY(-1px);
      box-shadow: 0 12px 22px rgba(121, 138, 210, 0.22);
    }

    .plan-nav__button span {
      display: block;
      margin-top: 4px;
      font-size: 12px;
      font-weight: 500;
      color: rgba(31, 37, 80, 0.58);
    }

    .plan-grid {
      display: grid;
      gap: clamp(20px, 3vw, 30px);
      margin-top: clamp(28px, 4vw, 40px);
    }

    .plan-card {
      position: relative;
      border-radius: var(--radius);
      background: var(--card);
      border: 1px solid var(--card-border);
      box-shadow: var(--shadow);
      overflow: hidden;
      transition: border-color var(--transition), transform var(--transition), box-shadow var(--transition);
    }

    .plan-card:hover {
      border-color: var(--card-border-hover);
      transform: translateY(-2px);
    }

    .plan-card--highlight {
      border-color: rgba(110, 197, 255, 0.7) !important;
      box-shadow: 0 0 0 2px rgba(110, 197, 255, 0.4), var(--shadow);
    }

    .plan-card__header {
      padding: 26px clamp(22px, 4vw, 34px) 20px;
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      align-items: flex-start;
      border-bottom: 1px solid rgba(82, 109, 255, 0.16);
      background: linear-gradient(155deg, rgba(83, 102, 255, 0.12), rgba(255, 255, 255, 0.94));
    }

    .plan-card__title {
      margin: 0;
      font-size: clamp(22px, 3.5vw, 28px);
      font-weight: 700;
      color: #121942;
    }

    .plan-card__meta {
      color: rgba(28, 31, 59, 0.62);
      font-size: 14px;
    }

    .plan-card__pills {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 10px;
    }

    .plan-card__pill {
      padding: 7px 12px;
      border-radius: 999px;
      border: 1px solid rgba(82, 109, 255, 0.2);
      background: rgba(82, 109, 255, 0.08);
      font-size: 13px;
      color: #1f2550;
    }

    .plan-card__toggle {
      margin-left: auto;
    }

    .plan-card__toggle button {
      all: unset;
      cursor: pointer;
      padding: 8px 14px;
      border-radius: 12px;
      border: 1px solid rgba(82, 109, 255, 0.28);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #31417f;
      background: rgba(255, 255, 255, 0.95);
      transition: background var(--transition), border-color var(--transition), box-shadow var(--transition);
    }

    .plan-card__toggle button:hover {
      background: linear-gradient(135deg, rgba(83, 102, 255, 0.16), rgba(255, 255, 255, 0.96));
      border-color: rgba(82, 109, 255, 0.42);
      box-shadow: 0 10px 20px rgba(124, 142, 210, 0.18);
    }

    .plan-card__body {
      padding: clamp(20px, 4vw, 34px);
      display: grid;
      gap: 18px;
      background: rgba(255, 255, 255, 0.88);
    }

    .plan-card__empty,
    .plan-card__empty-filter {
      padding: 18px;
      border-radius: var(--radius-sm);
      background: rgba(228, 234, 255, 0.6);
      border: 1px dashed rgba(82, 109, 255, 0.35);
      color: rgba(31, 37, 80, 0.7);
      text-align: center;
      font-size: 15px;
    }

    .exercise-card {
      display: grid;
      grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
      gap: 22px;
      padding: clamp(18px, 3.2vw, 26px);
      border-radius: var(--radius-sm);
      background: rgba(255, 255, 255, 0.95);
      border: 1px solid rgba(82, 109, 255, 0.18);
      position: relative;
      overflow: hidden;
      transition: border-color var(--transition), transform var(--transition), box-shadow var(--transition);
      box-shadow: 0 18px 30px rgba(151, 164, 219, 0.2);
    }

    .exercise-card::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: radial-gradient(circle at 15% 15%, rgba(83, 102, 255, 0.12), transparent 60%);
      opacity: 0;
      transition: opacity var(--transition);
    }

    .exercise-card:hover {
      border-color: rgba(83, 102, 255, 0.45);
      transform: translateY(-3px);
      box-shadow: 0 22px 36px rgba(142, 158, 214, 0.28);
    }

    .exercise-card:hover::after {
      opacity: 1;
    }

    .exercise-media {
      position: relative;
      border-radius: 16px;
      overflow: hidden;
      background: rgba(239, 244, 255, 0.9);
      min-height: 180px;
      box-shadow: inset 0 0 0 1px rgba(82, 109, 255, 0.08);
    }

    .exercise-media video,
    .exercise-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .exercise-media video {
      aspect-ratio: 16 / 9;
      background: #000;
    }

    .exercise-media__badge {
      position: absolute;
      right: 12px;
      bottom: 12px;
      background: rgba(19, 31, 59, 0.78);
      color: #fff;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: 12px;
      letter-spacing: 0.04em;
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
      color: rgba(31, 37, 80, 0.72);
      font-size: 14px;
      text-align: center;
      padding: 20px;
      background: linear-gradient(145deg, rgba(230, 235, 255, 0.92), rgba(255, 255, 255, 0.92));
      border: 1px dashed rgba(82, 109, 255, 0.26);
    }

    .exercise-media__fallback svg {
      width: 38px;
      height: 38px;
      opacity: 0.9;
    }

    .exercise-body {
      display: grid;
      gap: 14px;
    }

    .exercise-head {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 16px;
      align-items: baseline;
    }

    .exercise-index {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border-radius: 12px;
      background: rgba(83, 102, 255, 0.12);
      border: 1px solid rgba(83, 102, 255, 0.32);
      font-weight: 600;
      font-size: 15px;
      color: #31417f;
    }

    .exercise-name {
      font-size: clamp(20px, 3vw, 24px);
      font-weight: 600;
      margin: 0;
      flex: 1 1 auto;
      color: #141c45;
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
      padding: 6px 11px;
      border-radius: 999px;
      background: rgba(229, 235, 255, 0.8);
      border: 1px solid rgba(82, 109, 255, 0.3);
      font-size: 13px;
      color: #1f2550;
    }

    .badge svg {
      width: 16px;
      height: 16px;
      opacity: 0.85;
    }

    .notes-block {
      background: rgba(235, 240, 255, 0.85);
      border: 1px solid rgba(82, 109, 255, 0.18);
      border-radius: 14px;
      padding: 16px 18px;
    }

    .notes-block h4 {
      margin: 0 0 8px;
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(31, 37, 80, 0.72);
    }

    .notes-block p {
      margin: 0;
      color: #1f2550;
      white-space: pre-line;
      font-size: 15px;
    }

    .notes-block.empty p {
      color: rgba(31, 37, 80, 0.48);
      font-style: italic;
    }

    .plan-footnote {
      margin-top: 6px;
      font-size: 13px;
      color: rgba(31, 37, 80, 0.56);
      text-align: right;
    }

    .page-empty {
      margin-top: 40px;
      padding: 28px;
      background: rgba(235, 240, 255, 0.8);
      border: 1px dashed rgba(82, 109, 255, 0.3);
      border-radius: var(--radius);
      text-align: center;
      font-size: 18px;
      color: #1f2550;
      box-shadow: 0 20px 38px rgba(150, 162, 216, 0.18);
    }

    @media (max-width: 1100px) {
      .hero__status {
        grid-template-columns: minmax(0, 1fr);
      }
      .exercise-card {
        grid-template-columns: minmax(0, 1fr);
      }
      .exercise-media {
        min-height: 200px;
        order: -1;
      }
      .exercise-body {
        order: 2;
      }
    }

    @media (max-width: 720px) {
      main {
        padding: 22px clamp(14px, 6vw, 32px) 70px;
      }
      .hero {
        padding: clamp(26px, 7vw, 42px) clamp(14px, 6vw, 32px);
        border-radius: clamp(20px, 8vw, 30px);
      }
      .hero__status {
        gap: 16px;
      }
      .toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
      }
      .toolbar .search {
        width: 100%;
      }
      .actions {
        width: 100%;
        justify-content: center;
        gap: 8px;
      }
      .actions .btn {
        flex: 1 1 auto;
        justify-content: center;
        min-width: 0;
      }
      .plan-nav {
        position: sticky;
        top: calc(68px + env(safe-area-inset-top, 0px));
        z-index: 5;
        padding: 14px clamp(14px, 6vw, 24px);
        background: linear-gradient(180deg, rgba(247, 249, 255, 0.92), rgba(247, 249, 255, 0.6));
        margin-left: calc(-1 * clamp(14px, 6vw, 24px));
        margin-right: calc(-1 * clamp(14px, 6vw, 24px));
        box-shadow: 0 18px 30px rgba(151, 164, 219, 0.18);
      }
      .plan-nav__rail {
        padding-bottom: 6px;
      }
      .plan-card__header {
        flex-direction: column;
      }
      .plan-card__pills {
        width: 100%;
      }
      .plan-card__toggle {
        width: 100%;
      }
      .plan-card__toggle button {
        width: 100%;
        text-align: center;
      }
      .exercise-card {
        padding: 18px;
        gap: 18px;
      }
      .exercise-head {
        flex-direction: column;
        align-items: flex-start;
      }
      .exercise-index {
        width: 30px;
        height: 30px;
        font-size: 14px;
      }
      .notes-block {
        padding: 14px 16px;
      }
    }
  </style>
</head>
<body>

<div class="hero">
  <div class="hero__wrap">
    <div class="hero__intro">
      <span class="hero__eyebrow"><?php echo $isSelfView ? 'Your training home' : 'Viewing as client'; ?></span>
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
          <span class="hero-stat__label">Exercises to review</span>
          <strong><?php echo $totalExercises; ?></strong>
        </div>
        <div class="hero-stat">
          <span class="hero-stat__label">First plan dropped</span>
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
        <button class="btn" type="button" id="btnCollapseAll">Close all</button>
      </div>
    </div>
  </div>
</div>

<main>
  <?php if (!$plans): ?>
    <div class="page-empty">No workout plans have been assigned yet. Check back soon!</div>
  <?php else: ?>
    <section class="plan-nav">
      <h2 class="plan-nav__title"><?php echo $isSelfView ? 'Jump to a plan' : 'Client plan navigator'; ?></h2>
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
      <section class="plan-card" id="plan-<?php echo $pid; ?>" data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($plan['plan_name']); ?>" data-plan-assigned="<?php echo h($assignedStr); ?>">
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
            <button type="button" data-plan-toggle aria-expanded="true" data-target="#plan-body-<?php echo $pid; ?>">Hide details</button>
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
          <div class="plan-footnote">Need a quick review? Use the global search to instantly spotlight exercises across plans.</div>
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

  function setPlanVisibility(section, open) {
    const targetSel = section.querySelector('[data-plan-toggle]')?.getAttribute('data-target');
    const body = targetSel ? document.querySelector(targetSel) : section.querySelector('.plan-card__body');
    const toggle = section.querySelector('[data-plan-toggle]');
    if (!body) return;
    body.style.display = open ? '' : 'none';
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.textContent = open ? 'Hide details' : 'Show details';
    }
  }

  plans.forEach(section => {
    const toggle = section.querySelector('[data-plan-toggle]');
    if (!toggle) return;
    toggle.addEventListener('click', () => {
      const targetSel = toggle.getAttribute('data-target');
      const body = targetSel ? document.querySelector(targetSel) : section.querySelector('.plan-card__body');
      if (!body) return;
      const isVisible = body.style.display !== 'none';
      setPlanVisibility(section, !isVisible);
    });
  });

  btnExpand?.addEventListener('click', () => plans.forEach(section => setPlanVisibility(section, true)));
  btnCollapse?.addEventListener('click', () => plans.forEach(section => setPlanVisibility(section, false)));

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
      const body = section.querySelector('.plan-card__body');
      if (body && !matches && term) {
        body.style.display = '';
        const toggle = section.querySelector('[data-plan-toggle]');
        if (toggle) {
          toggle.setAttribute('aria-expanded', 'true');
          toggle.textContent = 'Hide details';
        }
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
      setPlanVisibility(section, true);
      section.scrollIntoView({behavior: 'smooth', block: 'start'});
      section.classList.add('plan-card--highlight');
      setTimeout(() => section.classList.remove('plan-card--highlight'), 1200);
    });
  });
})();
</script>

</body>
</html>
