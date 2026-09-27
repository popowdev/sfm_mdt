<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        discord_id VARCHAR(32) NOT NULL,
        type VARCHAR(40) NOT NULL DEFAULT 'info',
        titre VARCHAR(255) NOT NULL,
        corps VARCHAR(500) DEFAULT NULL,
        lien VARCHAR(500) DEFAULT NULL,
        ref_type VARCHAR(40) DEFAULT NULL,
        ref_id VARCHAR(64) DEFAULT NULL,
        urgent TINYINT(1) NOT NULL DEFAULT 0,
        lu TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dest (discord_id, lu), INDEX idx_created (created_at))");
} catch (PDOException $e) {}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

mdt_require_auth($conn, 'notifications');
$did = function_exists('mdt_session_discord_id') ? mdt_session_discord_id($conn) : null;
if (!$did) {
    if ($action === 'unread_count') { echo json_encode(array('ok' => true, 'unread' => 0, 'urgent' => 0, 'no_discord' => true)); exit; }
    if ($action === 'list')         { echo json_encode(array('ok' => true, 'notifications' => array(), 'items' => array(), 'no_discord' => true)); exit; }
    echo json_encode(array('ok' => true, 'no_discord' => true)); exit;
}

if ($action === 'unread_count') {
    $st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE discord_id = :d AND lu = 0");
    $st->execute(array(':d' => $did));
    $st2 = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE discord_id = :d AND lu = 0 AND urgent = 1");
    $st2->execute(array(':d' => $did));
    echo json_encode(array('ok' => true, 'unread' => (int)$st->fetchColumn(), 'urgent' => (int)$st2->fetchColumn()));
    exit;
}

if ($action === 'list') {
    $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 25;
    $st = $conn->prepare("SELECT id, type, titre, corps, lien, ref_type, ref_id, urgent, lu, created_at FROM notifications WHERE discord_id = :d ORDER BY lu ASC, created_at DESC LIMIT $limit");
    $st->execute(array(':d' => $did));
    $rows = $st->fetchAll();
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'id' => (int)$r['id'], 'type' => $r['type'], 'titre' => $r['titre'], 'corps' => $r['corps'],
            'lien' => $r['lien'], 'ref_type' => $r['ref_type'], 'ref_id' => $r['ref_id'],
            'urgent' => (int)$r['urgent'] ? true : false, 'lu' => (int)$r['lu'] ? true : false, 'created_at' => $r['created_at'],
        );
    }
    $unread = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE discord_id = " . $conn->quote($did) . " AND lu = 0")->fetchColumn();
    echo json_encode(array('ok' => true, 'notifications' => $out, 'unread' => $unread), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'mark_read') {
    $data = mdt_get_post_data();
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $st = $conn->prepare("UPDATE notifications SET lu = 1 WHERE id = :id AND discord_id = :d");
    $st->execute(array(':id' => $id, ':d' => $did));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'mark_all') {
    $st = $conn->prepare("UPDATE notifications SET lu = 1 WHERE discord_id = :d AND lu = 0");
    $st->execute(array(':d' => $did));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'delete') {
    $data = mdt_get_post_data();
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $st = $conn->prepare("DELETE FROM notifications WHERE id = :id AND discord_id = :d");
    $st->execute(array(':id' => $id, ':d' => $did));
    echo json_encode(array('ok' => true));
    exit;
}

mdt_unknown_action();
