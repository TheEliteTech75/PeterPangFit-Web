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
$pageTitle = ($client_id === $VIEWER_ID && !is_trainer_admin($VIEWER_ROLE))
  ? 'My Workout Plans'
  : ('Workout Plans — ' . ($clientName !== '' ? $clientName : ('Client #' . $client_id)));

$heroLine = ($client_id === $VIEWER_ID && !is_trainer_admin($VIEWER_ROLE))
  ? 'Every movement was hand-crafted for you. Explore the videos, cues, and your personal notes for each exercise.'
  : 'A polished view of every plan you\'ve crafted for ' . ($clientName !== '' ? $clientName : 'this client') . '.';

$newestDate = $latestAssignedTs ? date('M j, Y', $latestAssignedTs) : '—';
$firstDate  = $earliestAssignedTs ? date('M j, Y', $earliestAssignedTs) : '—';

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
      --bg: #040611;
      --bg-soft: #070b19;
      --card: linear-gradient(140deg, rgba(23,32,68,0.92), rgba(11,19,45,0.92));
      --card-border: rgba(72, 106, 255, 0.22);
      --card-border-hover: rgba(103, 135, 255, 0.38);
      --text: #f6f9ff;
      --muted: #aeb6d5;
      --accent: #6e8bff;
      --accent-soft: rgba(110,139,255,0.16);
      --highlight: #40e4c2;
      --chip-bg: rgba(118, 131, 200, 0.15);
      --chip-border: rgba(118, 131, 200, 0.28);
      --shadow: 0 20px 60px rgba(5, 8, 27, 0.6);
      --radius: 22px;
      --radius-sm: 14px;
      --transition: 220ms cubic-bezier(.4,.12,.2,1);
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: radial-gradient(circle at 20% -10%, rgba(110,139,255,0.25), transparent 45%),
                  radial-gradient(circle at 90% 0%, rgba(64,228,194,0.22), transparent 40%),
                  var(--bg);
      color: var(--text);
      font-family: 'Inter', 'Segoe UI', Roboto, -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
      line-height: 1.6;
      min-height: 100vh;
    }

    a { color: inherit; }

    main {
      padding: 28px clamp(18px, 4vw, 60px) 80px;
      max-width: 1200px;
      margin-left: auto;
      margin-right: auto;
    }

    .hero {
      position: relative;
      padding: clamp(32px, 6vw, 64px) clamp(18px, 4vw, 60px);
      overflow: hidden;
    }

    .hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(64,228,194,0.12), rgba(28,39,96,0.65));
      filter: blur(0px);
      z-index: -2;
    }

    .hero::after {
      content: '';
      position: absolute;
      inset: -120px -200px;
      background: radial-gradient(circle at 20% 20%, rgba(126,140,255,0.22), transparent 45%),
                  radial-gradient(circle at 80% 0%, rgba(53, 80, 255, 0.18), transparent 42%);
      z-index: -3;
      opacity: .85;
    }

    .hero__wrap {
      position: relative;
      z-index: 1;
      display: grid;
      gap: clamp(20px, 5vw, 36px);
    }

    .hero__eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px;
      background: rgba(13, 21, 58, 0.65);
      border: 1px solid rgba(110,139,255,0.35);
      border-radius: 999px;
      font-size: 13px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #c8d4ff;
      width: fit-content;
    }

    .hero__title {
      margin: 0;
      font-size: clamp(34px, 6vw, 50px);
      line-height: 1.08;
      font-weight: 700;
    }

    .hero__subtitle {
      margin: 0;
      font-size: clamp(16px, 3.2vw, 20px);
      max-width: 720px;
      color: #d7dcf7;
    }

    .hero__stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
    }

    .hero-stat {
      position: relative;
      padding: 18px 20px;
      background: rgba(9, 15, 34, 0.82);
      border: 1px solid rgba(132, 151, 255, 0.22);
      border-radius: var(--radius-sm);
      backdrop-filter: blur(12px);
    }

    .hero-stat strong {
      display: block;
      font-size: 28px;
      font-weight: 700;
      line-height: 1.15;
    }

    .hero-stat span {
      color: var(--muted);
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
    }

    .toolbar .search {
      flex: 1 1 240px;
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(11, 16, 32, 0.82);
      border: 1px solid rgba(110,139,255,0.28);
      border-radius: 999px;
      padding: 10px 16px;
      min-width: 200px;
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
      color: #d2d9ff;
      white-space: nowrap;
    }

    .chip .dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }

    .chip .dot.blue { background: #6e8bff; }
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
      gap: 8px;
      padding: 10px 16px;
      border-radius: 12px;
      border: 1px solid rgba(110,139,255,0.35);
      background: rgba(10, 15, 32, 0.9);
      color: var(--text);
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      transition: background var(--transition), transform var(--transition), border-color var(--transition);
    }

    .btn:hover {
      background: rgba(14, 21, 48, 0.98);
      border-color: rgba(130, 156, 255, 0.55);
      transform: translateY(-1px);
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
      transition: border-color var(--transition), transform var(--transition);
    }

    .plan-card:hover {
      border-color: var(--card-border-hover);
      transform: translateY(-2px);
    }

    .plan-card__header {
      padding: 26px clamp(22px, 4vw, 34px) 20px;
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      align-items: flex-start;
      border-bottom: 1px solid rgba(122, 144, 255, 0.18);
      background: linear-gradient(150deg, rgba(28, 41, 90, 0.75), rgba(14, 24, 54, 0.55));
    }

    .plan-card__title {
      margin: 0;
      font-size: clamp(22px, 3.5vw, 28px);
      font-weight: 700;
    }

    .plan-card__meta {
      color: var(--muted);
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
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(15, 25, 56, 0.65);
      font-size: 13px;
      color: #d9e0ff;
    }

    .plan-card__toggle {
      margin-left: auto;
    }

    .plan-card__toggle button {
      all: unset;
      cursor: pointer;
      padding: 8px 14px;
      border-radius: 12px;
      border: 1px solid rgba(110,139,255,0.4);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #d5deff;
      transition: background var(--transition), border-color var(--transition);
    }

    .plan-card__toggle button:hover {
      background: rgba(24, 38, 86, 0.9);
      border-color: rgba(135,160,255,0.6);
    }

    .plan-card__body {
      padding: clamp(20px, 4vw, 34px);
      display: grid;
      gap: 18px;
    }

    .plan-card__empty, .plan-card__empty-filter {
      padding: 18px;
      border-radius: var(--radius-sm);
      background: rgba(11, 18, 42, 0.68);
      border: 1px dashed rgba(132, 151, 255, 0.35);
      color: var(--muted);
      text-align: center;
      font-size: 15px;
    }

    .exercise-card {
      display: grid;
      grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
      gap: 22px;
      padding: clamp(18px, 3.2vw, 26px);
      border-radius: var(--radius-sm);
      background: rgba(10, 16, 36, 0.82);
      border: 1px solid rgba(122, 144, 255, 0.16);
      backdrop-filter: blur(10px);
      position: relative;
      overflow: hidden;
      transition: border-color var(--transition), transform var(--transition);
    }

    .exercise-card::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: radial-gradient(circle at 15% 15%, rgba(110, 139, 255, 0.13), transparent 60%);
      opacity: 0;
      transition: opacity var(--transition);
    }

    .exercise-card:hover {
      border-color: rgba(142, 165, 255, 0.45);
      transform: translateY(-2px);
    }

    .exercise-card:hover::after {
      opacity: 1;
    }

    .exercise-media {
      position: relative;
      border-radius: 16px;
      overflow: hidden;
      background: rgba(0, 0, 0, 0.6);
      min-height: 180px;
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
      background: rgba(0, 0, 0, 0.55);
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
      color: rgba(210, 221, 255, 0.9);
      font-size: 14px;
      text-align: center;
      padding: 20px;
      background: linear-gradient(145deg, rgba(26, 31, 58, 0.85), rgba(13, 16, 36, 0.85));
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
      background: rgba(112, 134, 255, 0.22);
      border: 1px solid rgba(112, 134, 255, 0.45);
      font-weight: 600;
      font-size: 15px;
    }

    .exercise-name {
      font-size: clamp(20px, 3vw, 24px);
      font-weight: 600;
      margin: 0;
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
      padding: 6px 11px;
      border-radius: 999px;
      background: rgba(19, 28, 60, 0.8);
      border: 1px solid rgba(120, 140, 255, 0.28);
      font-size: 13px;
      color: #dbe2ff;
    }

    .badge svg {
      width: 16px;
      height: 16px;
      opacity: 0.85;
    }

    .notes-block {
      background: rgba(12, 18, 40, 0.72);
      border: 1px solid rgba(122, 144, 255, 0.18);
      border-radius: 14px;
      padding: 16px 18px;
    }

    .notes-block h4 {
      margin: 0 0 8px;
      font-size: 14px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #c9d3ff;
    }

    .notes-block p {
      margin: 0;
      color: #d7dcf7;
      white-space: pre-line;
      font-size: 15px;
    }

    .notes-block.empty p {
      color: rgba(205, 214, 244, 0.6);
      font-style: italic;
    }

    .plan-footnote {
      margin-top: 6px;
      font-size: 13px;
      color: rgba(200, 208, 240, 0.6);
      text-align: right;
    }

    .page-empty {
      margin-top: 40px;
      padding: 28px;
      background: rgba(9, 14, 32, 0.72);
      border: 1px dashed rgba(122, 144, 255, 0.35);
      border-radius: var(--radius);
      text-align: center;
      font-size: 18px;
      color: #d1d8ff;
    }

    @media (max-width: 1100px) {
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
        padding: 22px clamp(14px, 6vw, 32px) 60px;
      }
      .hero {
        padding: clamp(26px, 7vw, 42px) clamp(14px, 6vw, 32px);
      }
      .toolbar {
        flex-direction: column;
        align-items: stretch;
      }
      .toolbar .search {
        width: 100%;
      }
      .actions {
        width: 100%;
        justify-content: space-between;
      }
      .actions .btn {
        flex: 1 1 auto;
        justify-content: center;
      }
      .plan-card__header {
        flex-direction: column;
      }
      .plan-card__toggle {
        width: 100%;
      }
      .plan-card__toggle button {
        width: 100%;
        text-align: center;
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
    }
  </style>
</head>
<body>

<div class="hero">
  <div class="hero__wrap">
    <span class="hero__eyebrow">My Workout Plans</span>
    <h1 class="hero__title"><?php echo h($pageTitle); ?></h1>
    <p class="hero__subtitle"><?php echo h($heroLine); ?></p>
    <div class="hero__stats">
      <div class="hero-stat">
        <strong><?php echo count($plans); ?></strong>
        <span>Total Plans</span>
      </div>
      <div class="hero-stat">
        <strong><?php echo $totalExercises; ?></strong>
        <span>Total Exercises</span>
      </div>
      <div class="hero-stat">
        <strong><?php echo h($newestDate); ?></strong>
        <span>Newest Plan</span>
      </div>
      <div class="hero-stat">
        <strong><?php echo h($firstDate); ?></strong>
        <span>First Assignment</span>
      </div>
    </div>
    <div class="toolbar">
      <div class="search" title="Search across all plans">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M21 21l-3.8-3.8m1.8-5.2a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" stroke="#b4c0ff" stroke-width="2" stroke-linecap="round" />
        </svg>
        <input id="globalSearch" type="search" placeholder="Search by exercise, cue, or note..." autocomplete="off" />
      </div>
      <span class="chip"><span class="dot blue"></span><span>Total plans</span> <strong id="chipPlans"><?php echo count($plans); ?></strong></span>
      <span class="chip"><span class="dot green"></span><span>Exercises shown</span> <strong id="chipItems" data-total="<?php echo $totalExercises; ?>"><?php echo $totalExercises; ?></strong></span>
      <div class="actions">
        <button class="btn" type="button" id="btnExpandAll">Expand all</button>
        <button class="btn" type="button" id="btnCollapseAll">Collapse all</button>
        <button class="btn" type="button" id="btnExportCSV">Export CSV</button>
      </div>
    </div>
  </div>
</div>

<main>
  <?php if (!$plans): ?>
    <div class="page-empty">No workout plans have been assigned yet. Check back soon!</div>
  <?php else: ?>
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
      <section class="plan-card" data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($plan['plan_name']); ?>" data-plan-assigned="<?php echo h($assignedStr); ?>">
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
            <button type="button" data-plan-toggle aria-expanded="true" data-target="#plan-body-<?php echo $pid; ?>">Collapse plan</button>
          </div>
        </div>
        <div class="plan-card__body" id="plan-body-<?php echo $pid; ?>">
          <?php if (!$items): ?>
            <div class="plan-card__empty">This plan doesn’t have any exercises yet.</div>
          <?php else: ?>
            <?php $index = 1; foreach ($items as $exercise):
              $weightOut = ($exercise['weight_val'] !== null && $exercise['weight_val'] !== '') ? (string)$exercise['weight_val'] : '';
              $coachNotes = trim((string)($exercise['coach_notes'] ?? ''));
              $userNotes  = trim((string)($exercise['user_notes'] ?? ''));
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
                $userNotes,
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
                     data-user-notes="<?php echo h($userNotes); ?>"
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
                <div class="notes-block<?php echo $coachNotes === '' ? ' empty' : ''; ?>">
                  <h4>Coach notes</h4>
                  <p><?php echo $coachNotes !== '' ? nl2br(h($coachNotes)) : 'No additional coaching notes provided.'; ?></p>
                </div>
                <div class="notes-block<?php echo $userNotes === '' ? ' empty' : ''; ?>">
                  <h4>Your notes</h4>
                  <p><?php echo $userNotes !== '' ? nl2br(h($userNotes)) : 'Add your own reminders and sensations as you complete this move.'; ?></p>
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
  const btnExport = document.getElementById('btnExportCSV');

  function setPlanVisibility(section, open) {
    const targetSel = section.querySelector('[data-plan-toggle]')?.getAttribute('data-target');
    const body = targetSel ? document.querySelector(targetSel) : section.querySelector('.plan-card__body');
    const toggle = section.querySelector('[data-plan-toggle]');
    if (!body) return;
    body.style.display = open ? '' : 'none';
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.textContent = open ? 'Collapse plan' : 'Expand plan';
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
          toggle.textContent = 'Collapse plan';
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

  function escapeCsv(str) {
    const s = String(str ?? '');
    if (s.includes('"') || s.includes(',') || s.includes('\n')) {
      return '"' + s.replace(/"/g, '""') + '"';
    }
    return s;
  }

  function exportCSV() {
    const lines = [];
    lines.push(['Plan Name','Assigned At','#','Exercise','Sets','Reps','Weight','Duration','Coach Notes','Your Notes'].join(','));
    plans.forEach(section => {
      const planName = section.getAttribute('data-plan-name') || '';
      const planAssigned = section.getAttribute('data-plan-assigned') || '';
      const cards = Array.from(section.querySelectorAll('.exercise-card'));
      cards.forEach(card => {
        const order = card.getAttribute('data-order') || '';
        const name = card.getAttribute('data-name') || '';
        const sets = card.getAttribute('data-sets') || '';
        const reps = card.getAttribute('data-reps') || '';
        const weight = card.getAttribute('data-weight') || '';
        const duration = card.getAttribute('data-duration') || '';
        const coach = card.getAttribute('data-coach-notes') || '';
        const user = card.getAttribute('data-user-notes') || '';
        lines.push([
          escapeCsv(planName),
          escapeCsv(planAssigned),
          escapeCsv(order),
          escapeCsv(name),
          escapeCsv(sets),
          escapeCsv(reps),
          escapeCsv(weight),
          escapeCsv(duration),
          escapeCsv(coach),
          escapeCsv(user)
        ].join(','));
      });
    });
    const blob = new Blob([lines.join('\r\n')], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const stamp = new Date().toISOString().slice(0,19).replace(/[:T]/g,'-');
    a.href = url;
    a.download = 'client_plans_<?php echo (int)$client_id; ?>_' + stamp + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  btnExport?.addEventListener('click', exportCSV);
})();
</script>

</body>
</html>
