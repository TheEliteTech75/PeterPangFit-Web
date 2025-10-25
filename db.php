<?php
require_once __DIR__ . '/demo_mode.php';
require_once __DIR__ . '/helpers.php';

$primaryDefaults = [
    'host'    => '10.0.1.7',
    'name'    => 'PeterPangFit',
    'user'    => 'Kung6020',
    'pass'    => 'iyyFhM%umDuG&@mgL$5Cf75s765b*7*n',
    'charset' => 'utf8mb4',
    'port'    => null,
    'socket'  => null,
];

$primaryCfg = array_merge($primaryDefaults, ppf_demo_build_config('PPF_DB_'));

$primaryConn = ppf_demo_bootstrap_primary($primaryCfg);
if (!$primaryConn instanceof mysqli) {
    $err = ppf_demo_last_error();
    die('Connection failed: ' . ($err ?: 'Unable to connect to database.'));
}

$conn = $primaryConn;

global $demoPrimaryConn;
$demoPrimaryConn = $primaryConn;
$GLOBALS['demoPrimaryConn'] = $primaryConn;

$sandboxDefaults = [
    'host'    => $primaryCfg['host'],
    'name'    => ($primaryCfg['name'] ?? 'PeterPangFit') . '_demo',
    'user'    => $primaryCfg['user'],
    'pass'    => $primaryCfg['pass'],
    'charset' => $primaryCfg['charset'] ?? 'utf8mb4',
    'port'    => $primaryCfg['port'] ?? null,
    'socket'  => $primaryCfg['socket'] ?? null,
];

$sandboxCfg = array_merge($sandboxDefaults, ppf_demo_build_config('PPF_DEMO_DB_'));
ppf_demo_refresh_state($primaryConn, $sandboxCfg);

$activeConn = ppf_demo_active_conn();
if ($activeConn instanceof mysqli) {
    $conn = $activeConn;
}

if ($conn instanceof mysqli) {
    if (function_exists('ppf_ensure_super_admin_role')) {
        ppf_ensure_super_admin_role($conn);
    }
    if (function_exists('ppf_promote_super_admin_account')) {
        ppf_promote_super_admin_account($conn, 'abdickens@me.com');
    }
}

$demoAlerts = function_exists('ppf_demo_collect_alerts') ? ppf_demo_collect_alerts() : [];
if ($demoAlerts) {
    ppf_demo_dispatch_alerts($demoAlerts);
    foreach ($demoAlerts as $alert) {
        error_log('[PPF Demo Mode] ' . $alert);
    }
}
?>
