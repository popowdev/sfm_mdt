<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_config.php';
mdt_cors();

try {
    mdt_require_auth($conn, 'suspects');
    $names = array();
    try { $names = $conn->query("SELECT name FROM suspects ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
    echo json_encode(array('success' => true, 'suspects' => $names), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => 'server'));
}
