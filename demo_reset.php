<?php
// demo_reset.php — CLI helper to reset the demo sandbox using the bundled seed
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This endpoint is only available via the command line." . PHP_EOL;
    exit(1);
}

require_once __DIR__ . '/db.php';

if (!function_exists('ppf_demo_reset')) {
    fwrite(STDERR, "Demo reset helper is unavailable." . PHP_EOL);
    exit(1);
}

$primary = $demoPrimaryConn ?? ($GLOBALS['demoPrimaryConn'] ?? ($primaryConn ?? null));
if (!($primary instanceof mysqli)) {
    fwrite(STDERR, "Primary database connection could not be resolved." . PHP_EOL);
    exit(1);
}

$result = ppf_demo_reset($primary);

$success = false;
$messages = [];
$errors = [];
if (is_array($result)) {
    $success = !empty($result['success']);
    $messages = array_filter(array_map('trim', (array)($result['messages'] ?? [])));
    $errors = array_filter(array_map('trim', (array)($result['errors'] ?? [])));
} else {
    $success = ($result !== false);
}

foreach ($messages as $line) {
    fwrite(STDOUT, '[OK] ' . $line . PHP_EOL);
}
foreach ($errors as $line) {
    fwrite(STDERR, '[ERROR] ' . $line . PHP_EOL);
}

if (!$success && !$errors) {
    fwrite(STDERR, "Demo reset returned an unknown error." . PHP_EOL);
}

exit($success ? 0 : 1);
