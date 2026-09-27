<?php
require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$me = mdt_require_auth($conn, 'presence');
$did = isset($me['discord_id']) ? (string)$me['discord_id'] : (isset($GLOBALS['MDT_ACTOR']['did']) ? $GLOBALS['MDT_ACTOR']['did'] : null);

if ($action === 'ping') {
    $v = isset($_POST['visible']) ? $_POST['visible'] : '1';
    $visible = ($v === '1' || $v === 'true' || $v === 1 || $v === true);
    mdt_presence_ping($conn, $did, $visible);
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'get') {
    $raw = isset($_POST['dids']) ? $_POST['dids'] : (isset($_GET['dids']) ? $_GET['dids'] : '');
    $dids = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
    echo json_encode(array('success' => true, 'presence' => mdt_presence_map($conn, $dids)), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(array('success' => false, 'error' => 'Action inconnue', 'code' => 'E-PRES-400'));
