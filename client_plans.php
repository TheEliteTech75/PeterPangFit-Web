<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/trainer_sessions_helpers.php';
require_once __DIR__ . '/ppf_header.php';
require_once __DIR__ . '/ppf_nav.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
      upe.set_details_json AS set_details_json,
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
    $setRows = cp_get_set_details(
      $row['set_details_json'] ?? null,
      $row['sets'] ?? null,
      $row['reps'] ?? null,
      $row['weight_val'] ?? null,
      $row['duration_seconds'] ?? null
    );
    $enrichedSets = cp_enrich_set_details($setRows);
    $summary = cp_summarize_set_details(
      $enrichedSets,
      $row['sets'] ?? null,
      $row['reps'] ?? null,
      $row['weight_val'] ?? null,
      $row['duration_seconds'] ?? null
    );

    $hasDetails = $summary['has_details'];

    $displaySets = $summary['count'];
    if ($displaySets === null && $row['sets'] !== null && $row['sets'] !== '') {
      $displaySets = (int)$row['sets'];
    }

    $legacyReps = $row['reps'] ?? null;
    $displayReps = null;
    if ($summary['reps'] !== null) {
      $displayReps = (string)$summary['reps'];
    } elseif (!$hasDetails && $legacyReps !== null && $legacyReps !== '') {
      $displayReps = (string)$legacyReps;
    }

    $legacyWeight = $row['weight_val'] ?? null;
    $displayWeightValue = null;
    $displayWeight = null;
    if ($summary['weight_display'] !== null) {
      $displayWeightValue = $summary['weight_value'];
      $displayWeight = $summary['weight_display'];
    } elseif (!$hasDetails && $legacyWeight !== null && $legacyWeight !== '' && is_numeric($legacyWeight)) {
      $displayWeightValue = (float)$legacyWeight;
      $displayWeight = cp_format_weight_lbs($displayWeightValue);
    }

    $legacyDuration = $row['duration_seconds'] ?? null;
    $displayDurationSeconds = null;
    $displayDuration = null;
    if ($summary['duration_display'] !== null) {
      $displayDurationSeconds = $summary['duration_seconds'];
      $displayDuration = $summary['duration_display'];
    } elseif (!$hasDetails && $legacyDuration !== null && $legacyDuration !== '' && is_numeric($legacyDuration)) {
      $displayDurationSeconds = (int)$legacyDuration;
      $displayDuration = fmt_dur($displayDurationSeconds);
    }

    $row['sets'] = $displaySets;
    $row['display_sets'] = $displaySets;
    $row['reps'] = $displayReps;
    $row['display_reps'] = $displayReps;
    $row['weight_val'] = $displayWeightValue;
    $row['display_weight'] = $displayWeight;
    $row['display_weight_value'] = $displayWeightValue;
    $row['display_duration'] = $displayDuration;
    $row['display_duration_seconds'] = $displayDurationSeconds;
    if (is_numeric($legacyDuration)) {
      $row['duration_seconds'] = (int)$legacyDuration;
    } elseif ($displayDurationSeconds !== null) {
      $row['duration_seconds'] = (int)$displayDurationSeconds;
    }

    $row['set_details'] = $enrichedSets;
    $row['set_detail_lines'] = array_values(array_filter(array_map('cp_format_set_detail_line', $enrichedSets)));
    $row['show_set_details'] = $summary['show_details'];

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

function cp_trim_number(float $value, int $precision = 2): string {
  $formatted = number_format($value, $precision, '.', '');
  $formatted = rtrim(rtrim($formatted, '0'), '.');
  return $formatted === '' ? '0' : $formatted;
}

function cp_format_weight_lbs($value): ?string {
  if ($value === null || $value === '') return null;
  if (!is_numeric($value)) return null;
  return cp_trim_number((float)$value) . ' lbs';
}

function cp_decode_set_details(?string $json): array {
  if ($json === null || trim($json) === '') return [];
  $decoded = json_decode($json, true);
  if (!is_array($decoded)) return [];

  $out = [];
  foreach ($decoded as $entry) {
    if (!is_array($entry)) continue;
    $reps = isset($entry['reps']) ? trim((string)$entry['reps']) : '';
    $weight = $entry['weight_lbs'] ?? ($entry['weight'] ?? null);
    $duration = $entry['duration_seconds'] ?? ($entry['duration'] ?? null);

    $out[] = [
      'set_number' => count($out) + 1,
      'reps' => $reps !== '' ? $reps : null,
      'weight_lbs' => is_numeric($weight) ? (float)$weight : null,
      'duration_seconds' => is_numeric($duration) ? (int)$duration : null,
    ];
  }

  return $out;
}

function cp_build_legacy_set_details($sets, $reps, $weight, $duration): array {
  $count = null;
  if ($sets !== null && $sets !== '') {
    $count = (int)$sets;
  }
  if ($count === null || $count <= 0) {
    if (($reps !== null && $reps !== '') || ($weight !== null && $weight !== '') || ($duration !== null && $duration !== '')) {
      $count = 1;
    } else {
      $count = 0;
    }
  }

  $repsVal = ($reps !== null && $reps !== '') ? (string)$reps : null;
  $weightVal = ($weight !== null && $weight !== '' && is_numeric($weight)) ? (float)$weight : null;
  $durationVal = ($duration !== null && $duration !== '' && is_numeric($duration)) ? (int)$duration : null;

  $rows = [];
  for ($i = 0; $i < $count; $i++) {
    $rows[] = [
      'set_number' => $i + 1,
      'reps' => $repsVal,
      'weight_lbs' => $weightVal,
      'duration_seconds' => $durationVal,
    ];
  }

  return $rows;
}

function cp_get_set_details($json, $sets, $reps, $weight, $duration): array {
  $fromJson = cp_decode_set_details($json);
  if ($fromJson) return $fromJson;
  return cp_build_legacy_set_details($sets, $reps, $weight, $duration);
}

function cp_enrich_set_details(array $rows): array {
  $out = [];
  foreach ($rows as $idx => $row) {
    $weightVal = $row['weight_lbs'] ?? null;
    $durationVal = $row['duration_seconds'] ?? null;
    $out[] = [
      'set_number' => $row['set_number'] ?? ($idx + 1),
      'reps' => isset($row['reps']) ? (string)$row['reps'] : null,
      'weight_value' => ($weightVal !== null) ? (float)$weightVal : null,
      'weight_display' => ($weightVal !== null) ? cp_format_weight_lbs($weightVal) : null,
      'duration_seconds' => ($durationVal !== null) ? (int)$durationVal : null,
      'duration_display' => ($durationVal !== null) ? fmt_dur((int)$durationVal) : null,
    ];
  }
  return $out;
}

function cp_sets_uniform(array $details, callable $getter, string $mode = 'string'): bool {
  if (count($details) <= 1) return true;
  $first = $getter($details[0]);
  foreach ($details as $row) {
    $current = $getter($row);
    if ($mode === 'number') {
      $firstNorm = ($first === null || $first === '') ? null : (float)$first;
      $currNorm = ($current === null || $current === '') ? null : (float)$current;
    } else {
      $firstNorm = ($first === null) ? '' : trim((string)$first);
      $currNorm = ($current === null) ? '' : trim((string)$current);
    }
    if ($mode === 'number') {
      if ($firstNorm === null && $currNorm === null) continue;
      if ($firstNorm === null || $currNorm === null) return false;
      if (abs($firstNorm - $currNorm) > 0.0001) return false;
    } else {
      if ($firstNorm !== $currNorm) return false;
    }
  }
  return true;
}

function cp_summarize_set_details(array $details, $legacySets, $legacyReps, $legacyWeight, $legacyDuration): array {
  $count = count($details);
  if ($count <= 0 && $legacySets !== null && $legacySets !== '') {
    $count = (int)$legacySets;
  }
  if ($count <= 0) {
    $count = null;
  }

  $first = $details[0] ?? null;

  $legacyRepsVal = ($legacyReps !== null && $legacyReps !== '') ? (string)$legacyReps : null;
  $legacyWeightVal = ($legacyWeight !== null && $legacyWeight !== '' && is_numeric($legacyWeight)) ? (float)$legacyWeight : null;
  $legacyDurationVal = ($legacyDuration !== null && $legacyDuration !== '' && is_numeric($legacyDuration)) ? (int)$legacyDuration : null;

  $uniformReps = cp_sets_uniform($details, fn($row) => $row['reps'] ?? null, 'string');
  $uniformWeight = cp_sets_uniform($details, fn($row) => $row['weight_value'] ?? null, 'number');
  $uniformDuration = cp_sets_uniform($details, fn($row) => $row['duration_seconds'] ?? null, 'number');

  $reps = null;
  if ($uniformReps) {
    if ($first && isset($first['reps']) && $first['reps'] !== null && $first['reps'] !== '') {
      $reps = (string)$first['reps'];
    } elseif ($legacyRepsVal !== null && !$details) {
      $reps = $legacyRepsVal;
    }
  } elseif (!$details) {
    $reps = $legacyRepsVal;
  }

  $weightValue = null;
  $weightDisplay = null;
  if ($uniformWeight) {
    if ($first && $first['weight_value'] !== null) {
      $weightValue = (float)$first['weight_value'];
      $weightDisplay = $first['weight_display'];
    } elseif ($legacyWeightVal !== null && !$details) {
      $weightValue = $legacyWeightVal;
      $weightDisplay = cp_format_weight_lbs($legacyWeightVal);
    }
  } elseif (!$details && $legacyWeightVal !== null) {
    $weightValue = $legacyWeightVal;
    $weightDisplay = cp_format_weight_lbs($legacyWeightVal);
  }

  $durationSeconds = null;
  $durationDisplay = null;
  if ($uniformDuration) {
    if ($first && $first['duration_seconds'] !== null) {
      $durationSeconds = (int)$first['duration_seconds'];
      $durationDisplay = $first['duration_display'];
    } elseif ($legacyDurationVal !== null && !$details) {
      $durationSeconds = $legacyDurationVal;
      $durationDisplay = fmt_dur($legacyDurationVal);
    }
  } elseif (!$details && $legacyDurationVal !== null) {
    $durationSeconds = $legacyDurationVal;
    $durationDisplay = fmt_dur($legacyDurationVal);
  }

  $hasDetails = count($details) > 0;
  $hasVariation = $hasDetails && (!$uniformReps || !$uniformWeight || !$uniformDuration);

  return [
    'count' => $count,
    'reps' => $reps,
    'weight_value' => $weightValue,
    'weight_display' => $weightDisplay,
    'duration_seconds' => $durationSeconds,
    'duration_display' => $durationDisplay,
    'has_details' => $hasDetails,
    'show_details' => $hasDetails && ($hasVariation || ($count !== null && $count > 1)),
  ];
}

function cp_format_set_detail_line(array $detail): ?string {
  $num = $detail['set_number'] ?? null;
  if ($num === null) return null;
  $parts = [];
  if (isset($detail['reps']) && $detail['reps'] !== null && $detail['reps'] !== '') {
    $parts[] = trim((string)$detail['reps']) . ' reps';
  }
  if (!empty($detail['weight_display'])) {
    $parts[] = $detail['weight_display'];
  }
  if (!empty($detail['duration_display'])) {
    $parts[] = $detail['duration_display'];
  }
  $suffix = $parts ? ' — ' . implode(' · ', $parts) : '';
  return 'Set ' . (int)$num . $suffix;
}

function cp_money($amount): string {
  $amount = (float)$amount;
  $sign = $amount < 0 ? '-' : '';
  $abs = abs($amount);
  return $sign . '$' . number_format($abs, 2);
}

function cp_format_datetime(?string $iso): string {
  if (!$iso) return '—';
  try {
    $dt = new DateTime($iso);
    return $dt->format('M j, Y g:i A');
  } catch (Throwable $e) {
    return (string)$iso;
  }
}

function cp_session_financial_map(array $package, array $sessions): array {
  $price = max(0.0, (float)($package['price_per_session'] ?? 0));
  $payments = max(0.0, (float)($package['payments_total'] ?? 0));
  $refunds = max(0.0, (float)($package['refunds_total'] ?? 0));
  $net = max(0.0, $payments - $refunds);
  $sorted = $sessions;
  usort($sorted, function ($a, $b) {
    $aTs = strtotime($a['scheduled_start'] ?? '') ?: 0;
    $bTs = strtotime($b['scheduled_start'] ?? '') ?: 0;
    if ($aTs === $bTs) {
      return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    }
    return $aTs <=> $bTs;
  });
  $map = [];
  if ($price <= 0.0) {
    foreach ($sorted as $row) {
      $sid = (int)($row['id'] ?? 0);
      $map[$sid] = ['amount' => 0.0, 'label' => '—'];
    }
    return $map;
  }
  $fullUnits = (int)floor($net / $price);
  $partialRemainder = $net - ($fullUnits * $price);
  $index = 0;
  foreach ($sorted as $row) {
    $sid = (int)($row['id'] ?? 0);
    $paid = 0.0;
    if ($index < $fullUnits) {
      $paid = $price;
    } elseif ($index === $fullUnits && $partialRemainder > 0) {
      $paid = $partialRemainder;
    }
    $status = 'Not refunded';
    $epsilon = 0.01;
    if ($paid <= $epsilon && $refunds > 0) {
      $status = 'Refunded';
    } elseif ($paid > $epsilon && $paid < ($price - $epsilon)) {
      $status = 'Partially refunded';
    }
    $map[$sid] = [
      'amount' => round($paid, 2),
      'label' => $status,
    ];
    $index++;
  }
  return $map;
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
$latestPlanId = isset($latestPlan['user_plan_id']) ? (int)$latestPlan['user_plan_id'] : null;

ppf_trainer_sessions_ensure_schema($conn);
$sessionPackages = ppf_trainer_sessions_fetch_packages($conn, null, $client_id);
$sessionTotalsPurchased = 0;
$sessionTotalsUsed = 0;
$sessionTotalsRemaining = 0;
foreach ($sessionPackages as &$sessionPkg) {
  $sessionPkg['purchased_sessions'] = (int)($sessionPkg['purchased_sessions'] ?? 0);
  $sessionPkg['completed_count'] = (int)($sessionPkg['completed_count'] ?? 0);
  $sessionPkg['remaining_sessions'] = max(0, $sessionPkg['purchased_sessions'] - $sessionPkg['completed_count']);
  $sessionPkg['price_per_session'] = (float)($sessionPkg['price_per_session'] ?? 0.0);
  $sessionPkg['payments_total'] = (float)($sessionPkg['payments_total'] ?? 0.0);
  $sessionPkg['refunds_total'] = (float)($sessionPkg['refunds_total'] ?? 0.0);
  $pid = (int)($sessionPkg['id'] ?? 0);
  $sessionPkg['sessions'] = $pid > 0 ? ppf_trainer_sessions_fetch_sessions_for_package($conn, $pid) : [];
  $sessionPkg['financials'] = cp_session_financial_map($sessionPkg, $sessionPkg['sessions']);
  $sessionPkg['scheduled_sessions'] = array_values(array_filter($sessionPkg['sessions'], function ($row) {
    $status = strtolower((string)($row['status'] ?? ''));
    return in_array($status, ['scheduled', 'in_progress'], true);
  }));
  $sessionTotalsPurchased += $sessionPkg['purchased_sessions'];
  $sessionTotalsUsed += $sessionPkg['completed_count'];
  $sessionTotalsRemaining += $sessionPkg['remaining_sessions'];
}
unset($sessionPkg);
$hasSessionPackages = !empty($sessionPackages);
$canCloseSessions = $isSelfView || is_trainer_admin($VIEWER_ROLE);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo h($pageTitle); ?></title>
  <style>

    :root {
      --plan-nav-offset: 76px;
      --plan-nav-safe-offset: calc(var(--plan-nav-offset) + env(safe-area-inset-top, 0px));
      --plan-nav-gap: 0px;
    }

    body.client-plans-page {
      font-family: 'Inter', 'Segoe UI', Roboto, -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
    }


    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    html {
      scroll-padding-top: calc(var(--plan-nav-safe-offset) + clamp(12px, 3vw, 24px));
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
        linear-gradient(180deg, var(--bg, #020202), var(--surface, #0a0a0a));
      color: var(--text, #f3f7ff);
      font-family: inherit;
      line-height: 1.6;
      min-height: 100vh;
      padding-top: calc(var(--plan-nav-safe-offset) + clamp(18px, 4vw, 32px));
      padding-bottom: 48px;
    }

    .ppf-topbar {
      position: fixed !important;
      top: 0;
      left: 0;
      right: 0;
      width: 100%;
      z-index: 4600;
      -webkit-transform: translateZ(0);
      transform: translateZ(0);
    }

    a {
      color: var(--accent, #00bfff);
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
        var(--surface-alt, #111111);
      border: 1px solid var(--card-border-subtle, rgba(255, 255, 255, 0.08));
      box-shadow: var(--shadow, var(--card-shadow, 0 28px 50px rgba(0, 0, 0, 0.55)));
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
      color: var(--muted-strong, #c4cee0);
      width: fit-content;
    }

    .hero__eyebrow svg {
      width: 16px;
      height: 16px;
      stroke: var(--accent, #00bfff);
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
      border-radius: var(--radius, 20px);
      border: 1px solid var(--card-border, rgba(0, 191, 255, 0.28));
      background:
        linear-gradient(140deg, rgba(0, 0, 0, 0.5), rgba(0, 191, 255, 0.2));
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.05);
      display: grid;
      gap: 12px;
    }

    .hero-highlight--action {
      position: relative;
      cursor: default;
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), box-shadow var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), border-color var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
    }

    .hero-highlight--ready {
      cursor: pointer;
    }

    .hero-highlight--ready:hover,
    .hero-highlight--ready:focus-visible {
      outline: none;
      transform: translateY(-4px) scale(1.02);
      border-color: var(--card-border-hover, rgba(0, 191, 255, 0.45));
      box-shadow: 0 26px 52px rgba(0, 0, 0, 0.62);
    }

    .hero-highlight--ready:focus-visible {
      box-shadow: 0 0 0 3px rgba(0, 191, 255, 0.45), 0 26px 52px rgba(0, 0, 0, 0.62);
    }

    .hero-highlight__label {
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted, #9ca8bf);
    }

    .hero-highlight__name {
      font-size: clamp(20px, 3.5vw, 28px);
      font-weight: 600;
      color: var(--text, #f3f7ff);
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
      border-radius: var(--radius-sm, 14px);
      border: 1px solid var(--card-border-subtle, rgba(255, 255, 255, 0.08));
      background: rgba(10, 10, 10, 0.8);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
      display: grid;
      gap: 8px;
    }

    .hero-stat strong {
      display: block;
      font-size: clamp(24px, 4.5vw, 32px);
      color: var(--accent, #00bfff);
      font-weight: 700;
      line-height: 1.15;
    }

    .hero-stat__label {
      color: var(--muted, #9ca8bf);
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
      border-radius: var(--radius, 20px);
      background: rgba(8, 8, 8, 0.88);
      border: 1px solid var(--card-border-subtle, rgba(255, 255, 255, 0.08));
      box-shadow: var(--shadow, var(--card-shadow, 0 28px 50px rgba(0, 0, 0, 0.55)));
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
      color: var(--muted, #9ca8bf);
      min-width: 220px;
      min-height: clamp(46px, 9vw, 56px);
    }

    .toolbar .search svg {
      width: 18px;
      height: 18px;
      stroke: var(--accent, #00bfff);
    }

    .toolbar .search input {
      flex: 1;
      border: none;
      background: transparent;
      color: var(--text, #f3f7ff);
      font-size: clamp(14px, 3.5vw, 16px);
      outline: none;
    }

    .sessions {
      margin: clamp(28px, 6vw, 60px) auto;
      max-width: min(1080px, 100% - 32px);
      display: grid;
      gap: clamp(18px, 4vw, 28px);
    }

    .sessions__header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: flex-end;
      gap: 16px;
    }

    .sessions__header h2 {
      margin: 0;
      font-size: clamp(22px, 4vw, 28px);
      color: var(--text, #f3f7ff);
    }

    .sessions__header p {
      margin: 0;
      color: var(--muted, #9ca8bf);
      font-size: 14px;
    }

    .sessions__totals {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 12px;
      flex: 1;
      min-width: 260px;
    }

    .sessions-total {
      padding: 14px 16px;
      border-radius: var(--radius-sm, 14px);
      border: 1px solid var(--card-border, rgba(255, 255, 255, 0.08));
      background: var(--panel, rgba(10, 10, 10, 0.82));
      box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--card-border, rgba(255, 255, 255, 0.08)) 35%, transparent 65%);
      display: grid;
      gap: 6px;
    }

    .sessions-total span {
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 11px;
      color: var(--muted, #9ca8bf);
    }

    .sessions-total strong {
      font-size: clamp(20px, 4vw, 26px);
      color: var(--text, #f3f7ff);
    }

    .session-card {
      background: var(--panel, rgba(10, 10, 10, 0.82));
      border: 1px solid var(--card-border, rgba(255, 255, 255, 0.08));
      border-radius: var(--radius, 20px);
      padding: clamp(18px, 4vw, 26px);
      box-shadow: var(--shadow, var(--card-shadow, 0 28px 50px rgba(0, 0, 0, 0.55)));
      display: grid;
      gap: clamp(16px, 3vw, 24px);
    }

    .session-card__header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
    }

    .session-card__title {
      margin: 0;
      font-size: clamp(18px, 3.6vw, 22px);
      color: var(--text, #f3f7ff);
    }

    .session-card__meta {
      margin-top: 4px;
      color: var(--muted, #9ca8bf);
      font-size: 13px;
    }

    .session-card__counts {
      display: grid;
      gap: 6px;
      text-align: right;
      min-width: 180px;
    }

    .session-card__counts span {
      font-size: 12px;
      color: var(--muted, #9ca8bf);
      text-transform: uppercase;
      letter-spacing: 0.07em;
    }

    .session-card__counts strong {
      display: block;
      font-size: 18px;
      color: var(--text, #f3f7ff);
    }

    .session-card__table-wrap {
      border-radius: var(--radius-sm, 14px);
      border: 1px solid var(--card-border, rgba(255, 255, 255, 0.08));
      overflow: hidden;
      background: color-mix(in srgb, var(--panel, rgba(10, 10, 10, 0.82)) 88%, transparent 12%);
    }

    .session-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 640px;
    }

    .session-table th,
    .session-table td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--line, rgba(255, 255, 255, 0.12));
      font-size: 13px;
      text-align: left;
      color: var(--text, #f3f7ff);
    }

    .session-table th {
      background: color-mix(in srgb, var(--panel-elevated, rgba(15, 15, 15, 0.9)) 85%, transparent 15%);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-size: 11px;
      color: color-mix(in srgb, var(--muted, #9ca8bf) 85%, var(--text, #f3f7ff) 15%);
    }

    .session-table tbody tr:last-child td {
      border-bottom: 0;
    }

    .session-table td.session-cell--actions {
      text-align: right;
      white-space: nowrap;
    }

    .session-empty {
      color: var(--muted, #9ca8bf);
      font-size: 14px;
    }

    .session-action-group {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 10px;
      align-items: center;
    }

    .session-action-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 9px 16px;
      border-radius: 12px;
      border: 1px solid color-mix(in srgb, var(--brand, #38bdf8) 55%, transparent 45%);
      background: color-mix(in srgb, var(--brand, #38bdf8) 30%, transparent 70%);
      color: var(--text, #f3f7ff);
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 12px 24px color-mix(in srgb, var(--brand, #38bdf8) 18%, transparent 82%);
    }

    .session-action-btn[data-session-start] {
      background: color-mix(in srgb, var(--brand, #38bdf8) 22%, transparent 78%);
    }

    .session-action-btn[data-session-start]:hover:not([disabled]),
    .session-action-btn[data-session-start]:focus-visible:not([disabled]) {
      background: color-mix(in srgb, var(--brand, #38bdf8) 34%, transparent 66%);
      transform: translateY(-1px);
    }

    .session-action-btn[data-session-end]:hover:not([disabled]),
    .session-action-btn[data-session-end]:focus-visible:not([disabled]) {
      background: color-mix(in srgb, var(--brand, #38bdf8) 42%, transparent 58%);
      transform: translateY(-1px);
    }

    .session-action-btn[disabled] {
      opacity: 0.65;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .session-action-btn.is-processing {
      cursor: wait;
      background: color-mix(in srgb, var(--brand, #38bdf8) 18%, var(--muted, #9ca8bf) 82%);
      box-shadow: none;
    }

    .session-action-btn.is-complete {
      cursor: default;
      background: color-mix(in srgb, var(--success, #32cd32) 32%, transparent 68%);
      border-color: color-mix(in srgb, var(--success, #32cd32) 55%, transparent 45%);
      color: color-mix(in srgb, var(--success, #32cd32) 70%, var(--text, #f3f7ff) 30%);
      box-shadow: none;
    }

    .session-action-btn.is-started {
      background: color-mix(in srgb, var(--success, #32cd32) 20%, transparent 80%);
      border-color: color-mix(in srgb, var(--success, #32cd32) 55%, transparent 45%);
      color: color-mix(in srgb, var(--success, #32cd32) 68%, var(--text, #f3f7ff) 32%);
    }

    .session-action-btn.is-cancelled {
      background: color-mix(in srgb, var(--danger, #ef4444) 22%, transparent 78%);
      border-color: color-mix(in srgb, var(--danger, #ef4444) 58%, transparent 42%);
      color: color-mix(in srgb, var(--danger, #ef4444) 70%, var(--text, #f3f7ff) 30%);
      box-shadow: none;
    }

    .session-live-timer {
      font-variant-numeric: tabular-nums;
      font-weight: 600;
      color: color-mix(in srgb, var(--success, #32cd32) 70%, var(--text, #f3f7ff) 30%);
      background: color-mix(in srgb, var(--success, #32cd32) 18%, transparent 82%);
      border-radius: 999px;
      padding: 6px 12px;
      border: 1px solid color-mix(in srgb, var(--success, #32cd32) 45%, transparent 55%);
      box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--success, #32cd32) 18%, transparent 82%);
    }

    @media (max-width: 720px) {
      .sessions {
        max-width: 100%;
        margin: clamp(22px, 6vw, 40px) 16px;
      }

      .sessions__header {
        align-items: flex-start;
      }

      .sessions__totals {
        min-width: 0;
      }

      .session-card__counts {
        width: 100%;
        text-align: left;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      }

      .session-card__table-wrap {
        overflow-x: auto;
      }
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
      color: var(--muted-strong, #c4cee0);
      white-space: nowrap;
    }

    .chip .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: var(--accent, #00bfff);
    }

    .chip strong {
      color: var(--text, #f3f7ff);
      font-weight: 600;
    }

    .chip .dot.green {
      background: var(--accent-strong, var(--success, #32cd32));
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
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), box-shadow var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), filter var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
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
      color: var(--muted, #9ca8bf);
    }

    .plan-nav__close {
      display: none;
      align-items: center;
      justify-content: center;
      border: none;
      background: rgba(0, 191, 255, 0.14);
      color: var(--text, #f3f7ff);
      border-radius: 999px;
      width: 34px;
      height: 34px;
      cursor: pointer;
      transition: background var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
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
      cursor: pointer;
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
      color: var(--text, #f3f7ff);
      font-weight: 600;
      font-size: 15px;
      cursor: pointer;
      box-shadow: 0 14px 32px rgba(0, 0, 0, 0.5);
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), box-shadow var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), border-color var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
    }

    .plan-nav__mobile-trigger svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
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
      color: var(--text, #f3f7ff);
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      scroll-snap-align: center;
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), box-shadow var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), border-color var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
    }

    .plan-nav__button:hover,
    .plan-nav__button:focus-visible {
      outline: none;
      transform: translateY(-2px);
      border-color: var(--card-border-hover, rgba(0, 191, 255, 0.45));
      box-shadow: 0 16px 32px rgba(0, 191, 255, 0.28);
    }

    .plan-nav__button span {
      font-size: 12px;
      color: var(--muted, #9ca8bf);
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
      background: var(--surface-alt, #111111);
      border-radius: var(--radius-lg, 28px);
      border: 1px solid var(--card-border-subtle, rgba(255, 255, 255, 0.08));
      box-shadow: var(--shadow, var(--card-shadow, 0 28px 50px rgba(0, 0, 0, 0.55)));
      overflow: hidden;
      display: flex;
      flex-direction: column;
      will-change: transform, box-shadow;
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), box-shadow var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), border-color var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
    }

    .plan-card::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      background: linear-gradient(140deg, rgba(0, 191, 255, 0.18), rgba(0, 0, 0, 0));
      opacity: 0;
      transition: opacity var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
      z-index: 0;
    }

    .plan-card > * {
      position: relative;
      z-index: 1;
    }

    .plan-card.plan-card--open {
      border-color: var(--card-border, rgba(0, 191, 255, 0.28));
      box-shadow: 0 30px 58px rgba(0, 0, 0, 0.6);
    }

    @media (hover: hover) and (pointer: fine) {
      .plan-card:hover {
        transform: translateY(-6px) scale(1.01);
        border-color: var(--card-border-hover, rgba(0, 191, 255, 0.45));
        box-shadow: 0 32px 64px rgba(0, 0, 0, 0.62);
      }

      .plan-card:hover::after {
        opacity: 1;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .plan-card,
      .plan-card::after,
      .hero-highlight--action {
        transition-duration: 0ms !important;
        transition-property: none !important;
      }

      .plan-card:hover,
      .hero-highlight--ready:hover,
      .hero-highlight--ready:focus-visible {
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
      transition: background var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), border-color var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
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
      color: var(--muted-strong, #c4cee0);
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
      color: var(--text, #f3f7ff);
      font-weight: 600;
      cursor: pointer;
      transition: background var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
    }

    .plan-card__toggle button:hover,
    .plan-card__toggle button:focus-visible {
      outline: none;
      background: rgba(0, 191, 255, 0.24);
      transform: translateY(-1px);
    }

    .plan-card__body {
      --plan-body-pad-inline: clamp(22px, 5vw, 34px);
      --plan-body-pad-block: clamp(22px, 5vw, 34px);
      display: grid;
      gap: 22px;
      padding: var(--plan-body-pad-block) var(--plan-body-pad-inline);
      background: rgba(8, 8, 8, 0.85);
      flex: 1 1 auto;
    }

    .plan-card:not(.plan-card--open) .plan-card__body {
      display: none;
    }

    .exercise-card {
      display: grid;
      gap: 20px;
      grid-template-columns: minmax(220px, 1fr) minmax(0, 1.2fr);
      background: rgba(20, 20, 20, 0.96);
      border-radius: var(--radius, 20px);
      padding: clamp(18px, 4vw, 28px);
      border: 1px solid rgba(255, 255, 255, 0.06);
      position: relative;
      overflow: hidden;
      box-shadow: 0 18px 36px rgba(0, 0, 0, 0.55);
      transition: transform var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), border-color var(--transition, 200ms cubic-bezier(.33,.13,.21,.99)), box-shadow var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
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
      transition: opacity var(--transition, 200ms cubic-bezier(.33,.13,.21,.99));
    }

    .exercise-card:hover {
      transform: translateY(-3px);
      border-color: var(--card-border, rgba(0, 191, 255, 0.28));
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
      color: var(--muted-strong, #c4cee0);
      background:
        linear-gradient(160deg, rgba(0, 0, 0, 0.8), rgba(0, 191, 255, 0.18));
      border: 1px dashed rgba(0, 191, 255, 0.4);
    }

    .exercise-media__fallback svg {
      width: 38px;
      height: 38px;
      stroke: var(--accent, #00bfff);
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
      color: var(--text, #f3f7ff);
    }

    .exercise-name {
      margin: 0;
      font-size: clamp(20px, 3.5vw, 26px);
      font-weight: 600;
      color: var(--text, #f3f7ff);
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
      color: var(--muted-strong, #c4cee0);
    }

    .badge svg {
      width: 16px;
      height: 16px;
      stroke: currentColor;
    }

    .notes-block {
      background: rgba(16, 18, 20, 0.9);
      border: 1px solid rgba(0, 191, 255, 0.18);
      border-radius: var(--radius-sm, 14px);
      padding: 16px 18px;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.03);
    }

    .notes-block h4 {
      margin: 0 0 8px;
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted, #9ca8bf);
    }

    .notes-block p {
      margin: 0;
      color: var(--muted-strong, #c4cee0);
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
      border-radius: var(--radius-lg, 28px);
      border: 1px dashed rgba(0, 191, 255, 0.3);
      background: rgba(12, 12, 12, 0.88);
      color: var(--muted-strong, #c4cee0);
      font-size: 18px;
      text-align: center;
      box-shadow: var(--shadow, var(--card-shadow, 0 28px 50px rgba(0, 0, 0, 0.55)));
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
        --plan-nav-gap: 0px;
      }
      .plan-nav {
        position: sticky;
        top: var(--plan-nav-safe-offset);
        z-index: 12;
        gap: 10px;
        margin-top: 0;
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
        top: var(--plan-nav-safe-offset);
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
        height: calc(100vh - var(--plan-nav-safe-offset));
        overflow: hidden;
      }
      .plan-nav--mobile .plan-nav__mobile-bar {
        display: flex;
        width: 100vw;
        margin-left: calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
        padding: 0;
        background: rgba(8, 8, 8, 0.96);
        border-bottom: 1px solid var(--card-border-subtle, rgba(255, 255, 255, 0.08));
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
        border: 1px solid var(--card-border-subtle, rgba(255, 255, 255, 0.08));
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
        --plan-body-pad-inline: clamp(14px, 8vw, 24px);
        --plan-body-pad-block: clamp(18px, 7vw, 26px);
        padding: var(--plan-body-pad-block) var(--plan-body-pad-inline);
      }
      .plan-card__body .exercise-card {
        margin-left: calc(var(--plan-body-pad-inline) * -1);
        margin-right: calc(var(--plan-body-pad-inline) * -1);
        border-radius: 0;
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
        padding-left: calc(env(safe-area-inset-left, 0px));
        padding-right: calc(env(safe-area-inset-right, 0px));
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
        box-shadow: var(--shadow, var(--card-shadow, 0 28px 50px rgba(0, 0, 0, 0.55)));
      }
    }
  </style>
</head>
<body class="client-plans-page" data-csrf="<?php echo h($csrf_token); ?>">

<div class="hero">
  <div class="hero__wrap">
    <div class="hero__intro">
      <span class="hero__eyebrow">Your training home</span>
      <h1 class="hero__headline"><?php echo h($heroHeadline); ?></h1>
      <p class="hero__subtitle"><?php echo h($heroLine); ?></p>
    </div>
    <div class="hero__status">
      <div class="hero-highlight hero-highlight--action<?php echo $latestPlanId ? ' hero-highlight--ready' : ''; ?>"
           <?php if ($latestPlanId): ?>
             role="button"
             tabindex="0"
             data-latest-plan-target="plan-<?php echo $latestPlanId; ?>"
             aria-label="Open latest plan <?php echo h($latestPlanName !== '' ? $latestPlanName : 'details'); ?>"
             title="Open latest plan"
           <?php else: ?>
             aria-disabled="true"
           <?php endif; ?>
      >
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
        <?php if ($hasSessionPackages): ?>
          <div class="hero-stat">
            <span class="hero-stat__label">Sessions purchased</span>
            <strong id="sessionsTotalPurchased" data-session-total="purchased"><?php echo $sessionTotalsPurchased; ?></strong>
          </div>
          <div class="hero-stat">
            <span class="hero-stat__label">Sessions used</span>
            <strong id="sessionsTotalUsed" data-session-total="used"><?php echo $sessionTotalsUsed; ?></strong>
          </div>
          <div class="hero-stat">
            <span class="hero-stat__label">Sessions remaining</span>
            <strong id="sessionsTotalRemaining" data-session-total="remaining"><?php echo $sessionTotalsRemaining; ?></strong>
          </div>
        <?php endif; ?>
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

<?php if ($hasSessionPackages): ?>
<section class="sessions" aria-labelledby="sessionsHeading">
  <div class="sessions__header">
    <div>
      <h2 id="sessionsHeading">Training Sessions</h2>
      <p>Track your prepaid sessions, upcoming appointments, and wrap them up when you finish.</p>
    </div>
    <div class="sessions__totals">
      <div class="sessions-total">
        <span>Purchased</span>
        <strong data-session-total="purchased"><?php echo $sessionTotalsPurchased; ?></strong>
      </div>
      <div class="sessions-total">
        <span>Used</span>
        <strong data-session-total="used"><?php echo $sessionTotalsUsed; ?></strong>
      </div>
      <div class="sessions-total">
        <span>Remaining</span>
        <strong data-session-total="remaining"><?php echo $sessionTotalsRemaining; ?></strong>
      </div>
    </div>
  </div>

  <?php foreach ($sessionPackages as $sessionPkg):
    $pkgId = (int)($sessionPkg['id'] ?? 0);
    $packageName = trim((string)($sessionPkg['package_name'] ?? 'Session Package')) ?: 'Session Package';
    $priceEach = (float)($sessionPkg['price_per_session'] ?? 0.0);
    $priceLabel = $priceEach > 0 ? (cp_money($priceEach) . ' each') : 'Custom rate';
    $scheduledSessions = $sessionPkg['scheduled_sessions'];
    $financials = $sessionPkg['financials'];
    $purchasedCount = (int)($sessionPkg['purchased_sessions'] ?? 0);
    $completedCount = (int)($sessionPkg['completed_count'] ?? 0);
    $remainingCount = (int)($sessionPkg['remaining_sessions'] ?? max(0, $purchasedCount - $completedCount));
  ?>
    <article class="session-card" data-package-id="<?php echo $pkgId; ?>">
      <header class="session-card__header">
        <div>
          <h3 class="session-card__title"><?php echo h($packageName); ?></h3>
          <div class="session-card__meta">
            <?php echo $purchasedCount; ?> sessions purchased · <?php echo h($priceLabel); ?>
          </div>
        </div>
        <div class="session-card__counts" data-package-summary="<?php echo $pkgId; ?>">
          <div>
            <span>Used</span>
            <strong data-package-used="<?php echo $pkgId; ?>"><?php echo $completedCount; ?></strong>
          </div>
          <div>
            <span>Remaining</span>
            <strong data-package-remaining="<?php echo $pkgId; ?>"><?php echo $remainingCount; ?></strong>
          </div>
        </div>
      </header>

      <?php if ($scheduledSessions): ?>
        <div class="session-card__table-wrap">
          <table class="session-table">
            <thead>
              <tr>
                <th scope="col">Session</th>
                <th scope="col">Starts</th>
                <th scope="col">Ends</th>
                <th scope="col">Price paid</th>
                <th scope="col">Refund status</th>
                <th scope="col" class="session-cell--actions">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($scheduledSessions as $idx => $session):
                $sid = (int)($session['id'] ?? 0);
                $financial = $financials[$sid] ?? ['amount' => 0.0, 'label' => 'Not refunded'];
                $startIso = $session['scheduled_start'] ?? null;
                $endIso = $session['scheduled_end'] ?? ($startIso ?? null);
                $startLabel = cp_format_datetime($startIso);
                $endLabel = cp_format_datetime($session['scheduled_end'] ?? null);
                $paidLabel = cp_money($financial['amount'] ?? 0.0);
                $refundLabel = $financial['label'] ?? 'Not refunded';
                $statusKey = strtolower((string)($session['status'] ?? 'scheduled'));
                $actualStart = $session['actual_start_at'] ?? null;
                $actualEnd = $session['actual_end_at'] ?? null;
                $durationSeconds = $session['duration_seconds'] ?? null;
              ?>
                <tr data-session-row="<?php echo $sid; ?>"
                    data-package-id="<?php echo $pkgId; ?>"
                    data-session-status="<?php echo h($statusKey); ?>"
                    data-session-start-iso="<?php echo h($startIso ?? ''); ?>"
                    data-session-end-iso="<?php echo h($endIso ?? ''); ?>"
                    data-session-actual-start="<?php echo h($actualStart ?? ''); ?>"
                    data-session-actual-end="<?php echo h($actualEnd ?? ''); ?>"
                    data-session-duration="<?php echo $durationSeconds !== null ? (int)$durationSeconds : ''; ?>">
                  <td><?php echo h('Session ' . ($idx + 1)); ?></td>
                  <td><?php echo h($startLabel); ?></td>
                  <td><?php echo h($endLabel); ?></td>
                  <td><?php echo h($paidLabel); ?></td>
                  <td><?php echo h($refundLabel); ?></td>
                  <td class="session-cell--actions">
                    <?php if ($canCloseSessions): ?>
                      <div class="session-action-group" data-session-controls="<?php echo $sid; ?>">
                        <button type="button"
                                class="session-action-btn"
                                data-session-start="true"
                                data-session-id="<?php echo $sid; ?>"
                                <?php if ($statusKey === 'completed'): ?>disabled aria-disabled="true"<?php endif; ?>>Start Session</button>
                        <button type="button"
                                class="session-action-btn"
                                data-session-end="true"
                                data-session-id="<?php echo $sid; ?>"
                                <?php if ($statusKey === 'completed'): ?>disabled aria-disabled="true"<?php endif; ?>>End Session</button>
                        <span class="session-live-timer" data-session-timer<?php echo $statusKey === 'in_progress' ? '' : ' hidden'; ?>>00:00:00</span>
                      </div>
                    <?php else: ?>
                      <span class="session-empty">Contact your coach to close this session.</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="session-empty">No upcoming sessions are scheduled. Reach out to your coach to book the next one.</p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

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
          $repVal = $it['reps'] ?? null;
          if (is_numeric($repVal)) {
            $sumReps += (int)$repVal;
          }
        }
        $durStr = total_duration_str($items);
        $assignedStr = $plan['assigned_at'] ? date('M j, Y g:ia', strtotime($plan['assigned_at'])) : '—';
      ?>
      <section class="plan-card" id="plan-<?php echo $pid; ?>" data-plan-id="<?php echo $pid; ?>" data-plan-name="<?php echo h($plan['plan_name']); ?>" data-plan-assigned="<?php echo h($assignedStr); ?>" data-open="false">
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
            <button type="button" data-plan-toggle aria-expanded="false" data-target="#plan-body-<?php echo $pid; ?>">Show workout</button>
          </div>
        </div>
        <div class="plan-card__body" id="plan-body-<?php echo $pid; ?>">
          <?php if (!$items): ?>
            <div class="plan-card__empty">This plan doesn’t have any exercises yet.</div>
          <?php else: ?>
            <?php $index = 1; foreach ($items as $exercise):
              $weightOut = trim((string)($exercise['display_weight'] ?? ''));
              $coachNotes = trim((string)($exercise['user_notes'] ?? ''));
              $exerciseDescription = trim((string)($exercise['coach_notes'] ?? ''));
              $durationStr = trim((string)($exercise['display_duration'] ?? ''));
              $videoUrl = trim((string)($exercise['video_url'] ?? ''));
              $posterUrl = trim((string)($exercise['video_poster_url'] ?? ''));
              $setDetailLines = $exercise['set_detail_lines'] ?? [];
              $showSetDetails = !empty($exercise['show_set_details']) && !empty($setDetailLines);
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
                $durationStr,
                implode(' ', $setDetailLines)
              ]);
            ?>
            <article class="exercise-card" data-search="<?php echo h($searchBlob); ?>"
                     data-order="<?php echo $index; ?>"
                     data-name="<?php echo h($exercise['exercise_name']); ?>"
                     data-sets="<?php echo h($exercise['sets'] !== null ? (int)$exercise['sets'] : ''); ?>"
                     data-reps="<?php echo h($exercise['reps'] !== null ? (string)$exercise['reps'] : ''); ?>"
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
                      <?php echo h($exercise['reps']); ?> reps
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
                <?php if ($showSetDetails): ?>
                  <div class="notes-block">
                    <h4>Set details</h4>
                    <p><?php echo implode('<br>', array_map('h', $setDetailLines)); ?></p>
                  </div>
                <?php endif; ?>
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
  const latestPlanTrigger = document.querySelector('[data-latest-plan-target]');
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const navSection = document.querySelector('[data-plan-nav-container]');
  const navOpenTrigger = navSection?.querySelector('[data-plan-nav-open]');
  const navCloseBtn = navSection?.querySelector('[data-plan-nav-close]');
  const navPanel = navSection?.querySelector('[data-plan-nav-panel]');
  const navMobileBar = navSection?.querySelector('[data-plan-nav-bar]');
  const navMobileQuery = window.matchMedia('(max-width: 760px)');
  const rootEl = document.documentElement;
  const topbarEl = document.querySelector('.ppf-topbar');
  let navSkipNextScroll = false;
  let navScrollHoldTimer = null;
  let navScrollLockY = 0;
  let navOffsetRaf = null;

  function enforceTopbarFixed() {
    if (!topbarEl) return;
    topbarEl.style.position = 'fixed';
    topbarEl.style.top = '0';
    topbarEl.style.left = '0';
    topbarEl.style.right = '0';
    topbarEl.style.width = '100%';
    topbarEl.style.zIndex = '4600';
  }

  function updatePlanNavOffset() {
    enforceTopbarFixed();
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

  enforceTopbarFixed();
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
    if (topbarEl) {
      if (!topbarEl.dataset.planNavLocked) {
        topbarEl.dataset.planNavPrevPosition = topbarEl.style.position || '';
        topbarEl.dataset.planNavPrevTop = topbarEl.style.top || '';
        topbarEl.dataset.planNavPrevLeft = topbarEl.style.left || '';
        topbarEl.dataset.planNavPrevRight = topbarEl.style.right || '';
        topbarEl.dataset.planNavPrevWidth = topbarEl.style.width || '';
        topbarEl.dataset.planNavPrevZ = topbarEl.style.zIndex || '';
      }
      topbarEl.dataset.planNavLocked = 'true';
      topbarEl.style.position = 'fixed';
      topbarEl.style.top = '0';
      topbarEl.style.left = '0';
      topbarEl.style.right = '0';
      topbarEl.style.width = '100%';
      topbarEl.style.zIndex = '4000';
    }
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
    if (topbarEl && topbarEl.dataset.planNavLocked) {
      topbarEl.style.position = topbarEl.dataset.planNavPrevPosition || '';
      topbarEl.style.top = topbarEl.dataset.planNavPrevTop || '';
      topbarEl.style.left = topbarEl.dataset.planNavPrevLeft || '';
      topbarEl.style.right = topbarEl.dataset.planNavPrevRight || '';
      topbarEl.style.width = topbarEl.dataset.planNavPrevWidth || '';
      topbarEl.style.zIndex = topbarEl.dataset.planNavPrevZ || '';
      delete topbarEl.dataset.planNavPrevPosition;
      delete topbarEl.dataset.planNavPrevTop;
      delete topbarEl.dataset.planNavPrevLeft;
      delete topbarEl.dataset.planNavPrevRight;
      delete topbarEl.dataset.planNavPrevWidth;
      delete topbarEl.dataset.planNavPrevZ;
      delete topbarEl.dataset.planNavLocked;
    }
    enforceTopbarFixed();
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

  navMobileBar?.addEventListener('click', (event) => {
    if (!navSection?.classList.contains('plan-nav--mobile')) return;
    if (!navSection.classList.contains('plan-nav--collapsed')) return;
    if (event.target.closest('[data-plan-nav-open]')) return;
    event.preventDefault();
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
    if (window.scrollY > 40 && navSection.classList.contains('plan-nav--expanded')) {
      collapsePlanNav();
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
    const startOpen = section.classList.contains('plan-card--open');
    setPlanVisibility(section, startOpen, { skipAnimation: true });
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

  function focusPlanSection(section) {
    if (!section) return;
    setPlanVisibility(section, true, { skipAnimation: true });
    const highlight = () => {
      section.classList.add('plan-card--highlight');
      const highlightDuration = prefersReducedMotion ? 800 : 1200;
      window.setTimeout(() => section.classList.remove('plan-card--highlight'), highlightDuration);
    };
    const scrollBehavior = prefersReducedMotion ? 'auto' : 'smooth';
    const performScroll = () => {
      section.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
      highlight();
    };
    if (prefersReducedMotion) {
      performScroll();
    } else {
      window.requestAnimationFrame(performScroll);
    }
  }

  planNavButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-target');
      if (!targetId) return;
      const section = document.getElementById(targetId);
      if (!section) return;
      if (navMobileQuery.matches) {
        collapsePlanNav();
        window.setTimeout(() => window.requestAnimationFrame(() => focusPlanSection(section)), 80);
      } else {
        focusPlanSection(section);
      }
    });
  });

  if (latestPlanTrigger && latestPlanTrigger.getAttribute('data-latest-plan-target')) {
    const handleLatestPlanAction = () => {
      const targetId = latestPlanTrigger.getAttribute('data-latest-plan-target');
      if (!targetId) return;
      const section = document.getElementById(targetId);
      if (!section) return;
      focusPlanSection(section);
    };

    latestPlanTrigger.addEventListener('click', event => {
      event.preventDefault();
      handleLatestPlanAction();
    });

    latestPlanTrigger.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        handleLatestPlanAction();
      }
    });
  }

  const sessionRows = new Map();
  document.querySelectorAll('[data-session-row]').forEach(row => {
    const sessionId = row.dataset.sessionRow;
    if (!sessionId) return;
    const controls = row.querySelector('[data-session-controls]');
    const startBtn = controls ? controls.querySelector('[data-session-start]') : null;
    const endBtn = controls ? controls.querySelector('[data-session-end]') : null;
    const timerEl = controls ? controls.querySelector('[data-session-timer]') : null;
    sessionRows.set(String(sessionId), { row, startBtn, endBtn, timerEl });
  });

  const csrfToken = document.body && document.body.dataset ? (document.body.dataset.csrf || '') : '';
  const sessionTotalsTargets = {
    purchased: Array.from(document.querySelectorAll('[data-session-total="purchased"]')),
    used: Array.from(document.querySelectorAll('[data-session-total="used"]')),
    remaining: Array.from(document.querySelectorAll('[data-session-total="remaining"]')),
  };
  const packageTotals = new Map();
  document.querySelectorAll('[data-package-summary]').forEach(el => {
    const pkgId = el.dataset.packageSummary;
    if (!pkgId) return;
    const usedEl = el.querySelector('[data-package-used]');
    const remainingEl = el.querySelector('[data-package-remaining]');
    packageTotals.set(String(pkgId), { used: usedEl, remaining: remainingEl });
  });

  const HALF_HOUR_MS = 30 * 60 * 1000;

  function parseIsoTimestamp(iso) {
    if (!iso) return null;
    const value = Date.parse(iso);
    return Number.isFinite(value) ? value : null;
  }

  function formatDuration(totalSeconds) {
    const secs = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(secs / 3600);
    const minutes = Math.floor((secs % 3600) / 60);
    const seconds = secs % 60;
    const parts = [hours, minutes, seconds].map(part => String(part).padStart(2, '0'));
    return parts.join(':');
  }

  function computeWindow(row) {
    const startIso = parseIsoTimestamp(row.dataset.sessionStartIso || '');
    if (startIso === null) {
      return { within: false };
    }
    const now = Date.now();
    const windowStart = startIso - HALF_HOUR_MS;
    if (now < windowStart) {
      return { within: false, windowStart };
    }
    const endIso = parseIsoTimestamp(row.dataset.sessionEndIso || '');
    const windowEnd = endIso !== null ? endIso + HALF_HOUR_MS : null;
    if (windowEnd !== null && now > windowEnd) {
      return { within: false, windowStart, windowEnd };
    }
    return { within: true, windowStart, windowEnd };
  }

  function applySessionPayload(row, payload) {
    if (!row || !payload) return;
    if (typeof payload.status === 'string') {
      row.dataset.sessionStatus = payload.status.toLowerCase();
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'scheduled_start')) {
      row.dataset.sessionStartIso = payload.scheduled_start || '';
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'scheduled_end')) {
      row.dataset.sessionEndIso = payload.scheduled_end || '';
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'actual_start_at')) {
      row.dataset.sessionActualStart = payload.actual_start_at || '';
      if (!payload.actual_end_at) {
        row.dataset.sessionDuration = '';
      }
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'actual_end_at')) {
      row.dataset.sessionDuration = '';
      row.dataset.sessionActualEnd = payload.actual_end_at || '';
      const startTs = parseIsoTimestamp(row.dataset.sessionActualStart || '');
      const endTs = parseIsoTimestamp(payload.actual_end_at || '');
      if (startTs !== null && endTs !== null && endTs >= startTs) {
        row.dataset.sessionDuration = Math.floor((endTs - startTs) / 1000);
      }
    }
  }

  function updateButtons(entry) {
    const { row, startBtn, endBtn } = entry;
    if (!row) return;
    const status = (row.dataset.sessionStatus || '').toLowerCase();
    const actualStart = parseIsoTimestamp(row.dataset.sessionActualStart || '');
    const actualEnd = parseIsoTimestamp(row.dataset.sessionActualEnd || '');
    const { within } = computeWindow(row);

    if (startBtn) {
      startBtn.classList.remove('is-complete', 'is-started', 'is-cancelled');
      let label = 'Start Session';
      startBtn.disabled = true;
      startBtn.setAttribute('aria-disabled', 'true');
      if (status === 'completed') {
        label = 'Session Completed';
        startBtn.classList.add('is-complete');
      } else if (status === 'cancelled') {
        label = 'Session Cancelled';
        startBtn.classList.add('is-cancelled');
      } else if (status === 'in_progress' || actualStart !== null) {
        label = 'Session Started';
        startBtn.classList.add('is-started');
      } else if (within && !startBtn.classList.contains('is-processing')) {
        startBtn.disabled = false;
        startBtn.removeAttribute('aria-disabled');
      }
      startBtn.textContent = label;
    }

    if (endBtn) {
      endBtn.classList.remove('is-complete', 'is-cancelled');
      let label = 'End Session';
      endBtn.disabled = true;
      endBtn.setAttribute('aria-disabled', 'true');
      if (status === 'completed' || actualEnd !== null) {
        label = 'Session Completed';
        endBtn.classList.add('is-complete');
      } else if (status === 'cancelled') {
        label = 'Session Cancelled';
        endBtn.classList.add('is-cancelled');
      } else if ((status === 'in_progress' || actualStart !== null) && within && !endBtn.classList.contains('is-processing')) {
        endBtn.disabled = false;
        endBtn.removeAttribute('aria-disabled');
      }
      endBtn.textContent = label;
    }
  }

  function updateTimer(entry) {
    const { row, timerEl } = entry;
    if (!row || !timerEl) return;
    const status = (row.dataset.sessionStatus || '').toLowerCase();
    const startTs = parseIsoTimestamp(row.dataset.sessionActualStart || '');
    const endTs = parseIsoTimestamp(row.dataset.sessionActualEnd || '');
    if (status === 'in_progress' && startTs !== null && endTs === null) {
      const diffSeconds = Math.floor((Date.now() - startTs) / 1000);
      timerEl.textContent = formatDuration(diffSeconds);
      timerEl.hidden = false;
    } else {
      timerEl.hidden = true;
    }
  }

  function refreshAllSessions() {
    sessionRows.forEach(entry => {
      updateButtons(entry);
      updateTimer(entry);
    });
  }

  refreshAllSessions();
  if (sessionRows.size) {
    window.setInterval(() => {
      sessionRows.forEach(updateTimer);
    }, 1000);
    window.setInterval(() => {
      sessionRows.forEach(updateButtons);
    }, 60000);
  }

  async function sendSessionAction(sessionId, action) {
    const params = new URLSearchParams();
    params.set('action', action);
    params.set('session_id', sessionId);
    if (csrfToken) {
      params.set('csrf_token', csrfToken);
    }
    const response = await fetch('client_sessions_actions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
      credentials: 'same-origin',
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || !payload.ok) {
      const message = payload && payload.message ? payload.message : 'Request failed. Please try again.';
      throw new Error(message);
    }
    return payload;
  }

  function getPackageIdForRow(row) {
    return row ? String(row.dataset.packageId || '') : '';
  }

  sessionRows.forEach(entry => {
    const { row, startBtn, endBtn } = entry;
    const sessionId = row ? row.dataset.sessionRow : null;
    if (startBtn && sessionId) {
      startBtn.addEventListener('click', async () => {
        if (startBtn.disabled || startBtn.classList.contains('is-processing')) return;
        const originalText = startBtn.textContent;
        startBtn.classList.add('is-processing');
        startBtn.textContent = 'Starting…';
        startBtn.disabled = true;
        startBtn.setAttribute('aria-disabled', 'true');
        try {
          const payload = await sendSessionAction(sessionId, 'start_session');
          applySessionPayload(row, payload.session || null);
          startBtn.classList.remove('is-processing');
          refreshAllSessions();
        } catch (error) {
          startBtn.classList.remove('is-processing');
          startBtn.textContent = originalText || 'Start Session';
          updateButtons(entry);
          window.alert(error && error.message ? error.message : 'Unable to start the session.');
        }
      });
    }
    if (endBtn && sessionId) {
      endBtn.addEventListener('click', async () => {
        if (endBtn.disabled || endBtn.classList.contains('is-processing')) return;
        const originalText = endBtn.textContent;
        endBtn.classList.add('is-processing');
        endBtn.textContent = 'Ending…';
        endBtn.disabled = true;
        endBtn.setAttribute('aria-disabled', 'true');
        try {
          const payload = await sendSessionAction(sessionId, 'end_session');
          applySessionPayload(row, payload.session || null);
          const pkgTotals = payload.package_totals || null;
          const pkgId = pkgTotals && typeof pkgTotals.package_id !== 'undefined'
            ? String(pkgTotals.package_id)
            : getPackageIdForRow(row);
          if (pkgId) {
            const summary = packageTotals.get(pkgId);
            if (summary) {
              if (summary.used && pkgTotals && typeof pkgTotals.used !== 'undefined') {
                summary.used.textContent = pkgTotals.used;
              }
              if (summary.remaining && pkgTotals && typeof pkgTotals.remaining !== 'undefined') {
                summary.remaining.textContent = pkgTotals.remaining;
              }
            }
          }
          const overall = payload.overall_totals || null;
          if (overall) {
            ['purchased', 'used', 'remaining'].forEach(key => {
              if (typeof overall[key] === 'undefined') return;
              sessionTotalsTargets[key].forEach(node => {
                node.textContent = overall[key];
              });
            });
          }
          endBtn.classList.remove('is-processing');
          refreshAllSessions();
        } catch (error) {
          endBtn.classList.remove('is-processing');
          endBtn.textContent = originalText || 'End Session';
          updateButtons(entry);
          window.alert(error && error.message ? error.message : 'Unable to end the session.');
        }
      });
    }
  });
})();
</script>

</body>
</html>