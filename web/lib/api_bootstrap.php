<?php
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
require_once __DIR__ . '/../db_config.php';
mdt_cors();
$action = isset($_GET['action']) ? $_GET['action'] : '';
