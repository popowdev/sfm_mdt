<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/config.php';
mdt_cors();

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS module_permissions (
        module_key VARCHAR(30) NOT NULL, role_id VARCHAR(30) NOT NULL,
        PRIMARY KEY (module_key, role_id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS discord_roles_cache (
        role_id VARCHAR(30) PRIMARY KEY, name VARCHAR(100) NOT NULL,
        color VARCHAR(7) NOT NULL DEFAULT '#99aab5', position INT NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {}

$action = isset($_GET['action']) ? $_GET['action'] : '';

function getSessionUser($conn, $token) {
    if (!$token) return null;
    $stmt = $conn->prepare("
        SELECT u.* FROM user_sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.token = :t AND s.expires_at > NOW()
    ");
    $stmt->execute(array(':t' => $token));
    return $stmt->fetch();
}

$SUPER_ADMINS = array('543211066805452805');

function extractToken() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (strpos($h, 'Bearer ') === 0) return substr($h, 7);
    if (isset($_GET['token'])) return $_GET['token'];
    return '';
}

function botApi($endpoint) {
    $url = BOT_API_URL . $endpoint;
    $ctx = stream_context_create(array('http' => array(
        'header' => 'X-API-Key: ' . BOT_API_KEY,
        'timeout' => 5
    )));
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) return null;
    return json_decode($result, true);
}

try {

    if ($action === 'check_access') {
        $moduleKey = isset($_GET['module_key']) ? $_GET['module_key'] : '';
        if (!$moduleKey) mdt_error(400, 'E-1400', 'module_key requis');
        $did = mdt_session_discord_id($conn);
        echo json_encode(array('access' => mdt_module_access($conn, $moduleKey, $did)));
    }

    elseif ($action === 'list_modules') {
        $modules = mdt_valid_modules();
        $labels = array(
            'mdt' => 'Aide MDT (MDT)', 'sd' => 'Laboratoire (SD)', 'upload' => 'Hebergement',
            'admin' => 'Administration', 'td' => 'Training Division', 'suivi' => 'Suivi Cadet',
            'plainte' => 'Plaintes', 'saisies' => 'Saisies', 'dispatch' => 'Dispatch',
            'documents' => 'Documentation'
        );

        $rows = $conn->query("
            SELECT mp.module_key, mp.role_id, rc.name, rc.color, rc.position
            FROM module_permissions mp
            LEFT JOIN discord_roles_cache rc ON rc.role_id = mp.role_id
            ORDER BY rc.position DESC
        ")->fetchAll();

        $permsMap = array();
        foreach ($rows as $row) {
            $mk = $row['module_key'];
            if (!isset($permsMap[$mk])) $permsMap[$mk] = array();
            $permsMap[$mk][] = array(
                'id' => $row['role_id'],
                'name' => $row['name'] ?: $row['role_id'],
                'color' => $row['color'] ?: '#99aab5',
                'position' => (int)($row['position'] ?: 0)
            );
        }

        $result = array();
        foreach ($modules as $mk) {
            $result[] = array(
                'key' => $mk,
                'label' => isset($labels[$mk]) ? $labels[$mk] : $mk,
                'roles' => isset($permsMap[$mk]) ? $permsMap[$mk] : array()
            );
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_module_roles') {
        mdt_post_only();
        mdt_require_admin($conn, 'save_module_roles');
        $data = mdt_get_post_data('E-1402');
        if (!$data || empty($data['module_key'])) mdt_error(400, 'E-1402', 'module_key requis');

        $moduleKey = $data['module_key'];
        $roleIds = isset($data['role_ids']) && is_array($data['role_ids']) ? $data['role_ids'] : array();

        $validModules = mdt_valid_modules();
        if (!in_array($moduleKey, $validModules)) mdt_error(400, 'E-1403', 'Module invalide');

        $conn->beginTransaction();
        $conn->prepare("DELETE FROM module_permissions WHERE module_key = :mk")->execute(array(':mk' => $moduleKey));

        if (!empty($roleIds)) {
            $ins = $conn->prepare("INSERT INTO module_permissions (module_key, role_id) VALUES (:mk, :rid)");
            foreach ($roleIds as $rid) {
                $rid = trim($rid);
                if ($rid) $ins->execute(array(':mk' => $moduleKey, ':rid' => $rid));
            }
        }
        $conn->commit();

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_roles') {

        mdt_require_auth($conn, 'list_roles');
        $rows = $conn->query("SELECT * FROM discord_roles_cache ORDER BY position DESC")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'refresh_roles') {
        mdt_post_only();

        mdt_require_admin($conn, 'refresh_roles');
        $roles = botApi('/api/roles');
        if ($roles === null) mdt_error(502, 'E-1404', 'Bot indisponible');

        $stmt = $conn->prepare("REPLACE INTO discord_roles_cache (role_id, name, color, position, updated_at) VALUES (:id, :n, :c, :p, NOW())");
        foreach ($roles as $r) {
            $stmt->execute(array(':id' => $r['id'], ':n' => $r['name'], ':c' => $r['color'], ':p' => $r['position']));
        }

        echo json_encode(array('success' => true, 'count' => count($roles), 'roles' => $roles), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'refresh_user_roles') {
        mdt_post_only();
        $token = extractToken();
        $user = getSessionUser($conn, $token);
        if (!$user) mdt_error(401, 'E-1401', 'Non connecte');

        if (!$user['discord_id']) {
            echo json_encode(array('success' => true, 'no_discord' => true, 'user' => array(
                'id' => (int)$user['id'], 'username' => $user['username'],
                'discord_id' => null, 'discord_username' => null, 'discord_avatar' => null, 'roles' => array(),
            )));
            exit;
        }

        $memberData = botApi('/api/member/' . $user['discord_id']);
        if ($memberData === null) {

            $roles = $user['discord_roles'] ? json_decode($user['discord_roles'], true) : array();
            echo json_encode(array('success' => true, 'cached' => true, 'user' => array(
                'id' => (int)$user['id'], 'username' => $user['username'],
                'discord_id' => $user['discord_id'], 'discord_username' => $user['discord_username'],
                'discord_avatar' => $user['discord_avatar'], 'discord_nick' => $user['discord_nick'],
                'discord_roles' => $roles
            )), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $roleIds = (isset($memberData['roles']) && is_array($memberData['roles'])) ? $memberData['roles'] : array();

        $oldRoles = $user['discord_roles'] ? json_decode($user['discord_roles'], true) : array();
        if (empty($roleIds) && !empty($oldRoles)) { $roleIds = $oldRoles; }
        $stmt = $conn->prepare("UPDATE users SET discord_roles = :r, discord_nick = :n, discord_username = :u, discord_avatar = :a WHERE id = :id");
        $stmt->execute(array(
            ':r' => json_encode($roleIds),
            ':n' => isset($memberData['nick']) ? $memberData['nick'] : null,
            ':u' => isset($memberData['username']) ? $memberData['username'] : $user['discord_username'],
            ':a' => isset($memberData['avatar']) ? $memberData['avatar'] : $user['discord_avatar'],
            ':id' => $user['id']
        ));

        echo json_encode(array('success' => true, 'user' => array(
            'id' => (int)$user['id'], 'username' => $user['username'],
            'discord_id' => $user['discord_id'],
            'discord_username' => isset($memberData['username']) ? $memberData['username'] : $user['discord_username'],
            'discord_avatar' => isset($memberData['avatar']) ? $memberData['avatar'] : $user['discord_avatar'],
            'discord_nick' => isset($memberData['nick']) ? $memberData['nick'] : $user['discord_nick'],
            'discord_roles' => $roleIds
        )), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'admin_refresh_member') {
        mdt_post_only();
        mdt_require_admin($conn, 'refresh_member');
        $data = mdt_get_post_data('E-1408'); if (!is_array($data)) $data = array();
        $did = isset($data['discord_id']) ? trim((string)$data['discord_id']) : '';
        if (!preg_match('/^\d{10,30}$/', $did)) mdt_error(400, 'E-1409', 'Discord ID invalide');
        $urow = $conn->prepare("SELECT id, discord_roles, discord_username, discord_avatar, discord_nick FROM users WHERE discord_id = :d LIMIT 1");
        $urow->execute(array(':d' => $did)); $u = $urow->fetch();
        if (!$u) mdt_error(404, 'E-1410', 'Aucun compte MDT lié à ce Discord');
        $memberData = botApi('/api/member/' . $did);
        if ($memberData === null) {
            echo json_encode(array('success' => true, 'cached' => true, 'message' => 'Bot indisponible — rôles inchangés'), JSON_UNESCAPED_UNICODE);
        } else {
            $roleIds = (isset($memberData['roles']) && is_array($memberData['roles'])) ? array_map('strval', $memberData['roles']) : array();
            $oldRoles = $u['discord_roles'] ? json_decode($u['discord_roles'], true) : array();
            if (empty($roleIds) && !empty($oldRoles)) $roleIds = $oldRoles;
            $upd = $conn->prepare("UPDATE users SET discord_roles=:r, discord_nick=:n, discord_username=:un, discord_avatar=:a WHERE discord_id=:d");
            $upd->execute(array(
                ':r'  => json_encode($roleIds),
                ':n'  => isset($memberData['nick']) ? $memberData['nick'] : $u['discord_nick'],
                ':un' => isset($memberData['username']) ? $memberData['username'] : $u['discord_username'],
                ':a'  => isset($memberData['avatar']) ? $memberData['avatar'] : $u['discord_avatar'],
                ':d'  => $did
            ));
            echo json_encode(array('success' => true, 'roles_count' => count($roleIds)), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'bulk_check') {
        $did = mdt_session_discord_id($conn);

        if (!$did) mdt_error(401, 'E-SEC-401', 'Authentification requise');
        $result = array();
        foreach (mdt_valid_modules() as $mk) {
            $result[$mk] = mdt_module_access($conn, $mk, $did);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'search_members') {
        $q = isset($_GET['q']) ? $_GET['q'] : '';
        if (strlen($q) < 2) {
            echo json_encode(array(), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = botApi('/api/members/search?q=' . urlencode($q));
        if ($result === null) mdt_error(502, 'E-1407', 'Bot indisponible');

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    else {
        mdt_unknown_action();
    }

} catch (Exception $e) {
    mdt_error(500, 'E-1406', 'Erreur serveur permissions', $e->getMessage());
}
