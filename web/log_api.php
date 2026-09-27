<?php

require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$me = mdt_require_auth($conn, 'log');
$did = isset($me['discord_id']) ? (string)$me['discord_id'] : '';

if ($action === 'client_error') {
    mdt_post_only();
    $d = mdt_get_post_data('E-LOG-400'); if (!is_array($d)) $d = array();
    $code   = mb_substr((string)(isset($d['code']) ? $d['code'] : 'E-JS-000'), 0, 20);
    $msg    = mb_substr((string)(isset($d['msg']) ? $d['msg'] : ''), 0, 255);
    $page   = mb_substr((string)(isset($d['page']) ? $d['page'] : ''), 0, 120);
    $module = mb_substr((string)(isset($d['module']) ? $d['module'] : ''), 0, 30);
    $stack  = mb_substr((string)(isset($d['stack']) ? $d['stack'] : ''), 0, 4000);
    $a = isset($GLOBALS['MDT_ACTOR']) ? $GLOBALS['MDT_ACTOR'] : array();
    mdt_audit_write($conn, array(
        'module' => $module !== '' ? $module : 'client',
        'action' => 'client_error',
        'actor_did' => $did !== '' ? $did : null,
        'actor_name' => isset($a['name']) ? $a['name'] : null,
        'entity_type' => 'page', 'entity_id' => $page,
        'result' => 'error', 'err_code' => $code, 'http_status' => 0, 'source' => 'client',
        'summary' => $msg, 'after' => ($stack !== '' ? array('stack' => $stack) : null),
    ));
    $GLOBALS['MDT_AUDITED'] = true;
    echo json_encode(array('success' => true));
    exit;
}

mdt_error(400, 'E-LOG-400', 'Action inconnue');
