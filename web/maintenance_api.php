<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_config.php';
mdt_cors();

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {

    if ($action === 'list') {
        $stmt = $conn->query("SELECT module_key, is_disabled, message, updated_at FROM maintenance ORDER BY module_key");
        $rows = $stmt->fetchAll();
        $result = array();
        foreach ($rows as $row) {
            $result[$row['module_key']] = array(
                'is_disabled' => (int)$row['is_disabled'],
                'message' => $row['message'],
                'updated_at' => $row['updated_at']
            );
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'toggle') {
        mdt_post_only();
        mdt_require_admin($conn, 'maintenance_toggle');
        $input = mdt_get_post_data('E-1100');
        if (!$input || !isset($input['module_key']) || !isset($input['is_disabled'])) {
            mdt_error(400, 'E-1100', 'Donnees invalides: module_key et is_disabled requis');
        }

        $key = $input['module_key'];
        $disabled = $input['is_disabled'] ? 1 : 0;
        $message = isset($input['message']) && $input['message'] !== '' ? substr($input['message'], 0, 255) : null;

        $check = $conn->prepare("SELECT module_key FROM maintenance WHERE module_key = :k");
        $check->execute(array(':k' => $key));
        if (!$check->fetch()) {
            mdt_error(404, 'E-1101', 'Module introuvable');
        }

        $stmt = $conn->prepare("UPDATE maintenance SET is_disabled = :d, message = :m WHERE module_key = :k");
        $stmt->execute(array(':d' => $disabled, ':m' => $message, ':k' => $key));

        echo json_encode(array('success' => true, 'module_key' => $key, 'is_disabled' => $disabled));
    }

    elseif ($action === 'list_dev_users') {

        mdt_require_superadmin($conn, 'list_dev_users');

        $conn->exec("CREATE TABLE IF NOT EXISTS dev_users (discord_id VARCHAR(30) PRIMARY KEY, added_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $rows = $conn->query("SELECT discord_id FROM dev_users ORDER BY added_at")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'add_dev_user') {
        mdt_post_only();
        mdt_require_superadmin($conn, 'add_dev_user');
        $input = mdt_get_post_data('E-1110');
        if (!$input || empty($input['discord_id'])) mdt_error(400, 'E-1110', 'discord_id requis');
        $conn->exec("CREATE TABLE IF NOT EXISTS dev_users (discord_id VARCHAR(30) PRIMARY KEY, added_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $conn->prepare("INSERT IGNORE INTO dev_users (discord_id) VALUES (:did)")->execute(array(':did' => $input['discord_id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'remove_dev_user') {
        mdt_post_only();
        mdt_require_superadmin($conn, 'remove_dev_user');
        $input = mdt_get_post_data('E-1111');
        if (!$input || empty($input['discord_id'])) mdt_error(400, 'E-1111', 'discord_id requis');
        $conn->prepare("DELETE FROM dev_users WHERE discord_id = :did")->execute(array(':did' => $input['discord_id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'is_dev') {
        $did = isset($_GET['discord_id']) ? $_GET['discord_id'] : '';
        $conn->exec("CREATE TABLE IF NOT EXISTS dev_users (discord_id VARCHAR(30) PRIMARY KEY, added_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $check = $conn->prepare("SELECT COUNT(*) FROM dev_users WHERE discord_id = :did");
        $check->execute(array(':did' => $did));
        $isDev = (int)$check->fetchColumn() > 0;
        echo json_encode(array('is_dev' => $isDev), JSON_UNESCAPED_UNICODE);
    }

    else {
        mdt_unknown_action();
    }

} catch (Exception $e) {
    mdt_error(500, 'E-1102', 'Erreur serveur', $e->getMessage());
}
