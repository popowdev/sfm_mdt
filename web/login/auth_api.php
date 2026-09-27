<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../bot_config.php';
mdt_cors();

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        discord_id VARCHAR(30) DEFAULT NULL,
        discord_username VARCHAR(100) DEFAULT NULL,
        discord_avatar VARCHAR(255) DEFAULT NULL,
        discord_roles JSON DEFAULT NULL,
        discord_nick VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_discord (discord_id),
        INDEX idx_username (username)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        token VARCHAR(64) PRIMARY KEY,
        user_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        INDEX idx_user (user_id),
        INDEX idx_expires (expires_at)
    )");

    try {
        $conn->query("SELECT telephone FROM users LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE users ADD COLUMN telephone VARCHAR(30) DEFAULT NULL");
    }
    try {
        $conn->query("SELECT compte_bancaire FROM users LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE users ADD COLUMN compte_bancaire VARCHAR(50) DEFAULT NULL");
    }
} catch (PDOException $e) {

}

function autoLinkRoster($conn, $userId, $discordId) {
    if (!$discordId) return;

    $r = $conn->prepare("SELECT id, matricule FROM roster WHERE discord_id = :did");
    $r->execute(array(':did' => $discordId));
    $roster = $r->fetch();

    if (!$roster) return;

}

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {

    if ($action === 'register') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1300');
        if (!$data || empty($data['username']) || empty($data['password'])) {
            mdt_error(400, 'E-1300', 'Identifiant et mot de passe requis');
        }

        $username = trim($data['username']);
        $password = $data['password'];

        if (strlen($username) < 3 || strlen($username) > 50) {
            mdt_error(400, 'E-1301', 'Identifiant: 3 a 50 caracteres');
        }
        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
            mdt_error(400, 'E-1301', 'Identifiant: lettres, chiffres, _ . - uniquement');
        }

        if (strlen($password) < 6) {
            mdt_error(400, 'E-1302', 'Mot de passe: 6 caracteres minimum');
        }

        $check = $conn->prepare("SELECT id FROM users WHERE username = :u");
        $check->execute(array(':u' => $username));
        if ($check->fetch()) {
            mdt_error(409, 'E-1303', 'Cet identifiant est deja pris');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
        $stmt->execute(array(':u' => $username, ':p' => $hash));
        $userId = $conn->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+' . MDT_SESSION_DAYS . ' days'));
        $ins = $conn->prepare("INSERT INTO user_sessions (token, user_id, expires_at) VALUES (:t, :u, :e)");
        $ins->execute(array(':t' => $token, ':u' => $userId, ':e' => $expires));

        echo json_encode(array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => (int)$userId,
                'username' => $username,
                'discord_id' => null,
                'discord_username' => null,
                'discord_avatar' => null,
                'discord_nick' => null,
                'discord_roles' => null
            )
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'login') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1304');
        if (!$data || empty($data['username']) || empty($data['password'])) {
            mdt_error(400, 'E-1304', 'Identifiant et mot de passe requis');
        }

        $username = trim($data['username']);
        $password = $data['password'];

        if (function_exists('mdt_bruteforce_blocked') && mdt_bruteforce_blocked($conn, 'login', 10, 15)) {
            if (function_exists('mdt_seclog')) mdt_seclog($conn, 'login_bruteforce', 'high', array('username' => substr($username, 0, 60)));
            mdt_error(429, 'E-1305B', 'Trop de tentatives. Reessaie dans quelques minutes.');
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE username = :u");
        $stmt->execute(array(':u' => $username));
        $user = $stmt->fetch();

        if (!$user) {
            $norm = function ($v) {
                $v = mb_strtolower(trim((string)$v), 'UTF-8');
                $v = strtr($v, array('à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                                     'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'));
                $v = preg_replace('/[^a-z0-9]+/', '-', $v);
                return trim($v, '-');
            };
            $needle = $norm($username);
            if ($needle !== '') {
                $cands = array();
                foreach ($conn->query("SELECT * FROM users")->fetchAll() as $u2) {
                    if ($norm($u2['username']) === $needle) $cands[] = $u2;
                }
                if (count($cands) === 1) $user = $cands[0];
            }
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            if (function_exists('mdt_seclog')) mdt_seclog($conn, 'login_fail', 'low', array('username' => substr($username, 0, 60)));
            echo json_encode(array('error' => 'Identifiant ou mot de passe incorrect', 'code' => 'E-1305'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+' . MDT_SESSION_DAYS . ' days'));
        $ins = $conn->prepare("INSERT INTO user_sessions (token, user_id, expires_at) VALUES (:t, :u, :e)");
        $ins->execute(array(':t' => $token, ':u' => (int)$user['id'], ':e' => $expires));

        $conn->prepare("UPDATE users SET updated_at = NOW() WHERE id = :id")->execute(array(':id' => $user['id']));

        $roles = $user['discord_roles'] ? json_decode($user['discord_roles'], true) : null;

        echo json_encode(array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'discord_id' => $user['discord_id'],
                'discord_username' => $user['discord_username'],
                'discord_avatar' => $user['discord_avatar'],
                'discord_nick' => $user['discord_nick'],
                'discord_roles' => $roles
            )
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'validate') {
        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        } elseif (isset($_GET['token'])) {
            $token = $_GET['token'];
        }

        if (!$token) {
            mdt_error(401, 'E-1306', 'Token manquant');
        }

        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.discord_id, u.discord_username, u.discord_avatar, u.discord_nick, u.discord_roles, u.telephone, u.compte_bancaire, s.expires_at
            FROM user_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token = :t AND s.expires_at > NOW()
        ");
        $stmt->execute(array(':t' => $token));
        $row = $stmt->fetch();

        if (!$row) {
            mdt_error(401, 'E-1307', 'Session invalide ou expiree');
        }

        $roles = $row['discord_roles'] ? json_decode($row['discord_roles'], true) : null;

        $matricule = null;
        if ($row['discord_id']) {
            $r = $conn->prepare("SELECT matricule FROM roster WHERE discord_id = :did");
            $r->execute(array(':did' => $row['discord_id']));
            $rr = $r->fetch();
            if ($rr) $matricule = $rr['matricule'];
        }

        echo json_encode(array(
            'valid' => true,
            'user' => array(
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'discord_id' => $row['discord_id'],
                'discord_username' => $row['discord_username'],
                'discord_avatar' => $row['discord_avatar'],
                'discord_nick' => $row['discord_nick'],
                'discord_roles' => $roles,
                'telephone' => $row['telephone'],
                'compte_bancaire' => isset($row['compte_bancaire']) ? $row['compte_bancaire'] : null,
                'matricule' => $matricule
            )
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'me') {
        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        } elseif (isset($_GET['token'])) {
            $token = $_GET['token'];
        }

        if (!$token) {
            mdt_error(401, 'E-1306', 'Token manquant');
        }

        $stmt = $conn->prepare("
            SELECT u.*
            FROM user_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token = :t AND s.expires_at > NOW()
        ");
        $stmt->execute(array(':t' => $token));
        $user = $stmt->fetch();

        if (!$user) {
            mdt_error(401, 'E-1307', 'Session invalide ou expiree');
        }

        $roles = $user['discord_roles'] ? json_decode($user['discord_roles'], true) : null;

        $matricule = null;
        if ($user['discord_id']) {
            $r = $conn->prepare("SELECT matricule FROM roster WHERE discord_id = :did");
            $r->execute(array(':did' => $user['discord_id']));
            $rr = $r->fetch();
            if ($rr) $matricule = $rr['matricule'];
        }

        echo json_encode(array(
            'user' => array(
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'discord_id' => $user['discord_id'],
                'discord_username' => $user['discord_username'],
                'discord_avatar' => $user['discord_avatar'],
                'discord_nick' => $user['discord_nick'],
                'discord_roles' => $roles,
                'telephone' => isset($user['telephone']) ? $user['telephone'] : null,
                'compte_bancaire' => isset($user['compte_bancaire']) ? $user['compte_bancaire'] : null,
                'matricule' => $matricule,
                'created_at' => $user['created_at']
            )
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'link_discord') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1308');

        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
        if (!$token && isset($data['token'])) {
            $token = $data['token'];
        }

        if (!$token) {
            mdt_error(401, 'E-1306', 'Token manquant');
        }

        if (!$data || empty($data['discord_id'])) {
            mdt_error(400, 'E-1308', 'Donnees Discord manquantes');
        }

        $sess = $conn->prepare("SELECT user_id FROM user_sessions WHERE token = :t AND expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $sessRow = $sess->fetch();
        if (!$sessRow) {
            mdt_error(401, 'E-1307', 'Session invalide ou expiree');
        }
        $userId = $sessRow['user_id'];

        $checkDiscord = $conn->prepare("SELECT id FROM users WHERE discord_id = :d AND id != :uid");
        $checkDiscord->execute(array(':d' => $data['discord_id'], ':uid' => $userId));
        if ($checkDiscord->fetch()) {
            mdt_error(409, 'E-1309', 'Ce compte Discord est deja lie a un autre utilisateur');
        }

        $realRoles = null;
        $botMember = null;
        $botUrl = 'http://127.0.0.1:3100/api/member/' . urlencode($data['discord_id']);
        $bch = curl_init($botUrl);
        curl_setopt($bch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($bch, CURLOPT_TIMEOUT, 5);
        curl_setopt($bch, CURLOPT_CONNECTTIMEOUT, 2);
        if (defined('BOT_API_KEY') && BOT_API_KEY) curl_setopt($bch, CURLOPT_HTTPHEADER, array('X-Api-Key: ' . BOT_API_KEY));
        $bresp = curl_exec($bch);
        $bcode = curl_getinfo($bch, CURLINFO_HTTP_CODE);
        curl_close($bch);
        $botMember = json_decode($bresp, true);
        if ($bcode === 200 && is_array($botMember) && isset($botMember['roles']) && is_array($botMember['roles'])) {
            $realRoles = json_encode($botMember['roles']);
        }

        if (isset($data['discord_roles']) && is_array($data['discord_roles'])) {
            $serverRoles = ($realRoles !== null) ? json_decode($realRoles, true) : array();
            $forged = array_values(array_diff($data['discord_roles'], is_array($serverRoles) ? $serverRoles : array()));
            if (!empty($forged) && function_exists('mdt_seclog')) {
                mdt_seclog($conn, 'role_spoof_attempt', 'critical',
                    array('discord_id' => (string)$data['discord_id'], 'claimed' => array_values($data['discord_roles']), 'forged_extra' => $forged),
                    'user_id ' . $userId);
            }
        }

        $stmt = $conn->prepare("UPDATE users SET discord_id = :did, discord_username = :du, discord_avatar = :da, discord_nick = :dn, discord_roles = :dr WHERE id = :uid");
        $stmt->execute(array(
            ':did' => $data['discord_id'],
            ':du' => isset($botMember['username']) ? $botMember['username'] : (isset($data['discord_username']) ? $data['discord_username'] : null),
            ':da' => isset($botMember['avatar']) ? $botMember['avatar'] : (isset($data['discord_avatar']) ? $data['discord_avatar'] : null),
            ':dn' => isset($botMember['nick']) ? $botMember['nick'] : (isset($data['discord_nick']) ? $data['discord_nick'] : null),
            ':dr' => $realRoles,
            ':uid' => $userId
        ));

        echo json_encode(array('success' => true, 'message' => 'Compte Discord lie avec succes', 'roles_synced' => ($realRoles !== null)), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'unlink_discord') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1310');

        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
        if (!$token && isset($data['token'])) {
            $token = $data['token'];
        }

        if (!$token) mdt_error(401, 'E-1306', 'Token manquant');

        $sess = $conn->prepare("SELECT user_id FROM user_sessions WHERE token = :t AND expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $sessRow = $sess->fetch();
        if (!$sessRow) mdt_error(401, 'E-1307', 'Session invalide ou expiree');

        $stmt = $conn->prepare("UPDATE users SET discord_id = NULL, discord_username = NULL, discord_avatar = NULL, discord_nick = NULL, discord_roles = NULL WHERE id = :uid");
        $stmt->execute(array(':uid' => $sessRow['user_id']));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'logout') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1311');

        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
        if (!$token && isset($data['token'])) {
            $token = $data['token'];
        }

        if ($token) {
            $conn->prepare("DELETE FROM user_sessions WHERE token = :t")->execute(array(':t' => $token));
        }

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_telephone') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1312');

        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
        if (!$token && isset($data['token'])) {
            $token = $data['token'];
        }
        if (!$token) mdt_error(401, 'E-1306', 'Token manquant');

        $sess = $conn->prepare("SELECT user_id FROM user_sessions WHERE token = :t AND expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $sessRow = $sess->fetch();
        if (!$sessRow) mdt_error(401, 'E-1307', 'Session invalide');

        $tel = isset($data['telephone']) ? trim($data['telephone']) : null;
        $cb = isset($data['compte_bancaire']) ? trim($data['compte_bancaire']) : null;
        $conn->prepare("UPDATE users SET telephone = :tel, compte_bancaire = :cb WHERE id = :uid")->execute(array(':tel' => $tel, ':cb' => $cb, ':uid' => $sessRow['user_id']));

        echo json_encode(array('success' => true, 'telephone' => $tel, 'compte_bancaire' => $cb), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'refresh_discord_roles') {
        mdt_post_only();

        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) $token = substr($authHeader, 7);
        if (!$token) {
            $body = mdt_get_post_data('E-1340');
            if ($body && isset($body['token'])) $token = $body['token'];
        }
        if (!$token) mdt_error(401, 'E-1340', 'Token manquant');

        $sess = $conn->prepare("SELECT u.id, u.discord_id, u.discord_roles FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $row = $sess->fetch();
        if (!$row) mdt_error(401, 'E-1341', 'Session invalide ou expiree');
        if (empty($row['discord_id'])) mdt_error(400, 'E-1342', 'Compte Discord non lie');

        $botUrl = 'http://127.0.0.1:3100/api/member/' . urlencode($row['discord_id']);
        $ch = curl_init($botUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        if (defined('BOT_API_KEY') && BOT_API_KEY) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Api-Key: ' . BOT_API_KEY));
        }
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            mdt_error(503, 'E-1343', 'Bot Discord injoignable (verifie qu il tourne)');
        }
        $botData = json_decode($resp, true);
        if (!is_array($botData) || empty($botData['roles'])) {

            if (!is_array($botData)) mdt_error(502, 'E-1344', 'Reponse bot invalide');
        }

        $oldRoles = $row['discord_roles'] ? json_decode($row['discord_roles'], true) : array();
        if (!is_array($oldRoles)) $oldRoles = array();
        $newRoles = isset($botData['roles']) && is_array($botData['roles']) ? $botData['roles'] : array();

        $added = array_values(array_diff($newRoles, $oldRoles));
        $removed = array_values(array_diff($oldRoles, $newRoles));
        $hasChanges = !empty($added) || !empty($removed);

        $updateFields = array('discord_roles = :dr');
        $params = array(':dr' => json_encode($newRoles), ':uid' => $row['id']);
        if (isset($botData['nick'])) { $updateFields[] = 'discord_nick = :dn'; $params[':dn'] = $botData['nick']; }
        if (isset($botData['username'])) { $updateFields[] = 'discord_username = :du'; $params[':du'] = $botData['username']; }
        if (isset($botData['avatar'])) { $updateFields[] = 'discord_avatar = :da'; $params[':da'] = $botData['avatar']; }

        $stmt = $conn->prepare("UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :uid");
        $stmt->execute($params);

        echo json_encode(array(
            'success' => true,
            'changes' => $hasChanges,
            'roles_count' => count($newRoles),
            'added' => $added,
            'removed' => $removed,
            'discord_nick' => isset($botData['nick']) ? $botData['nick'] : null,
            'discord_username' => isset($botData['username']) ? $botData['username'] : null,
            'discord_avatar' => isset($botData['avatar']) ? $botData['avatar'] : null,
            'discord_roles' => $newRoles
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'reset_password') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1320');

        $apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
        if (!BOT_API_KEY || $apiKey !== BOT_API_KEY) {
            mdt_error(401, 'E-1321', 'Cle API invalide');
        }

        if (!$data || empty($data['discord_id'])) {
            mdt_error(400, 'E-1322', 'discord_id requis');
        }

        $user = $conn->prepare("SELECT id, username FROM users WHERE discord_id = :did");
        $user->execute(array(':did' => $data['discord_id']));
        $userRow = $user->fetch();

        if (!$userRow) {
            mdt_error(404, 'E-1323', 'Aucun compte lie a ce Discord');
        }

        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $newPassword = '';
        for ($i = 0; $i < 8; $i++) {
            $newPassword .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $conn->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
            ->execute(array(':h' => $hash, ':id' => $userRow['id']));

        $conn->prepare("DELETE FROM user_sessions WHERE user_id = :uid")->execute(array(':uid' => $userRow['id']));

        echo json_encode(array(
            'success' => true,
            'username' => $userRow['username'],
            'new_password' => $newPassword
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'admin_discord_check') {
        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) $token = substr($authHeader, 7);
        if (!$token) mdt_error(401, 'E-1380', 'Token manquant');

        $sess = $conn->prepare("SELECT u.id, u.discord_roles FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $caller = $sess->fetch();
        if (!$caller) mdt_error(401, 'E-1381', 'Session invalide');
        $callerRoles = $caller['discord_roles'] ? json_decode($caller['discord_roles'], true) : array();
        if (!is_array($callerRoles)) $callerRoles = array();
        $adminRoles = array('1484914784410403037','1210813640987377758','1210813640987377761','1210813640987377759','1485642111314432082','1485642087360757991');
        $isAdmin = false;
        foreach ($adminRoles as $r) { if (in_array($r, $callerRoles, true)) { $isAdmin = true; break; } }
        if (!$isAdmin) mdt_error(403, 'E-1382', 'Acces refuse: admin requis');

        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (!$q) mdt_error(400, 'E-1383', 'Parametre q requis');

        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.discord_id, u.discord_username, u.discord_nick, u.discord_avatar, u.discord_roles, u.telephone, u.compte_bancaire, u.created_at
            FROM users u
            WHERE u.discord_id = :q1 OR u.username LIKE :q2a OR u.discord_username LIKE :q2b OR u.discord_nick LIKE :q2c
            LIMIT 10
        ");
        $stmt->execute(array(':q1' => $q, ':q2a' => $like, ':q2b' => $like, ':q2c' => $like));
        $users = $stmt->fetchAll();

        $rosterRows = array();
        if (preg_match('/^\d+$/', $q)) {
            $rs = $conn->prepare("SELECT id, matricule, nom_prenom, discord_id FROM roster WHERE matricule = :m LIMIT 1");
            $rs->execute(array(':m' => $q));
            $r = $rs->fetch();
            if ($r) {
                $rosterRows[] = $r;
                if ($r['discord_id']) {
                    $u = $conn->prepare("SELECT id, username, discord_id, discord_username, discord_nick, discord_avatar, discord_roles, telephone, compte_bancaire, created_at FROM users WHERE discord_id = :d");
                    $u->execute(array(':d' => $r['discord_id']));
                    $u2 = $u->fetch();
                    if ($u2) {
                        $alreadyIn = false;
                        foreach ($users as $eu) { if ($eu['id'] == $u2['id']) { $alreadyIn = true; break; } }
                        if (!$alreadyIn) $users[] = $u2;
                    }
                }
            }
        }
        $rosterByDiscord = array();
        if ($q) {
            $rs2 = $conn->prepare("SELECT id, matricule, nom_prenom, discord_id FROM roster WHERE discord_id = :d");
            $rs2->execute(array(':d' => $q));
            $r2 = $rs2->fetch();
            if ($r2) $rosterByDiscord[] = $r2;
        }

        $results = array();
        foreach ($users as $u) {
            $diag = array();
            $rosterEntry = null;
            if ($u['discord_id']) {
                $rs3 = $conn->prepare("SELECT id, matricule, nom_prenom FROM roster WHERE discord_id = :d");
                $rs3->execute(array(':d' => $u['discord_id']));
                $rosterEntry = $rs3->fetch() ?: null;
            }

            if (!$u['discord_id']) $diag[] = array('level' => 'error', 'msg' => 'Aucun Discord lie a ce compte');
            elseif (!$rosterEntry) $diag[] = array('level' => 'warn', 'msg' => 'Discord lie mais pas de matricule dans le roster');
            else $diag[] = array('level' => 'ok', 'msg' => 'Compte complet (Discord + roster)');

            $roles = $u['discord_roles'] ? json_decode($u['discord_roles'], true) : null;
            if ($u['discord_id'] && (!$roles || !is_array($roles) || count($roles) === 0)) {
                $diag[] = array('level' => 'warn', 'msg' => 'Aucun role Discord (le bot n a pas encore sync ou agent absent du serveur)');
            }
            unset($u['discord_roles']);
            $u['roles_count'] = is_array($roles) ? count($roles) : 0;
            $u['roster'] = $rosterEntry;
            $u['diagnostic'] = $diag;
            $results[] = $u;
        }

        echo json_encode(array(
            'query' => $q,
            'users' => $results,
            'roster_by_discord' => $rosterByDiscord,
            'roster_by_matricule' => $rosterRows,
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'admin_discord_link_force') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1390');
        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) $token = substr($authHeader, 7);
        if (!$token) mdt_error(401, 'E-1390', 'Token manquant');

        $sess = $conn->prepare("SELECT u.id, u.discord_roles FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $caller = $sess->fetch();
        if (!$caller) mdt_error(401, 'E-1391', 'Session invalide');
        $callerRoles = $caller['discord_roles'] ? json_decode($caller['discord_roles'], true) : array();
        if (!is_array($callerRoles)) $callerRoles = array();
        $adminRoles = array('1484914784410403037','1210813640987377758','1210813640987377761','1210813640987377759','1485642111314432082','1485642087360757991');
        $isAdmin = false;
        foreach ($adminRoles as $r) { if (in_array($r, $callerRoles, true)) { $isAdmin = true; break; } }
        if (!$isAdmin) mdt_error(403, 'E-1392', 'Acces refuse: admin requis');

        if (!$data || empty($data['user_id']) || empty($data['discord_id'])) {
            mdt_error(400, 'E-1393', 'user_id et discord_id requis');
        }
        $uid = (int)$data['user_id'];
        $did = trim($data['discord_id']);
        if (!preg_match('/^\d{10,30}$/', $did)) mdt_error(400, 'E-1394', 'discord_id invalide (numerique)');

        $check = $conn->prepare("SELECT id, username FROM users WHERE discord_id = :d AND id != :uid");
        $check->execute(array(':d' => $did, ':uid' => $uid));
        $other = $check->fetch();
        if ($other) mdt_error(409, 'E-1395', 'Discord deja lie au compte ' . $other['username'] . ' (id ' . $other['id'] . ')');

        $checkUser = $conn->prepare("SELECT id FROM users WHERE id = :uid");
        $checkUser->execute(array(':uid' => $uid));
        if (!$checkUser->fetch()) mdt_error(404, 'E-1396', 'User introuvable');

        $upd = $conn->prepare("UPDATE users SET discord_id = :d WHERE id = :uid");
        $upd->execute(array(':d' => $did, ':uid' => $uid));

        echo json_encode(array('success' => true, 'message' => 'Discord lie. Le bot va sync les roles dans les prochaines minutes.'), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'admin_discord_unlink') {
        mdt_post_only();
        $data = mdt_get_post_data('E-1397');
        $token = '';
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (strpos($authHeader, 'Bearer ') === 0) $token = substr($authHeader, 7);
        if (!$token) mdt_error(401, 'E-1397', 'Token manquant');

        $sess = $conn->prepare("SELECT u.id, u.discord_roles FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :t AND s.expires_at > NOW()");
        $sess->execute(array(':t' => $token));
        $caller = $sess->fetch();
        if (!$caller) mdt_error(401, 'E-1398', 'Session invalide');
        $callerRoles = $caller['discord_roles'] ? json_decode($caller['discord_roles'], true) : array();
        if (!is_array($callerRoles)) $callerRoles = array();
        $adminRoles = array('1484914784410403037','1210813640987377758','1210813640987377761','1210813640987377759','1485642111314432082','1485642087360757991');
        $isAdmin = false;
        foreach ($adminRoles as $r) { if (in_array($r, $callerRoles, true)) { $isAdmin = true; break; } }
        if (!$isAdmin) mdt_error(403, 'E-1399', 'Acces refuse: admin requis');

        if (!$data || empty($data['user_id'])) mdt_error(400, 'E-1400X', 'user_id requis');
        $uid = (int)$data['user_id'];

        $upd = $conn->prepare("UPDATE users SET discord_id = NULL, discord_username = NULL, discord_avatar = NULL, discord_nick = NULL, discord_roles = NULL WHERE id = :uid");
        $upd->execute(array(':uid' => $uid));

        echo json_encode(array('success' => true, 'message' => 'Discord detache. L utilisateur peut maintenant relier son compte.'), JSON_UNESCAPED_UNICODE);
    }

    else {
        mdt_unknown_action();
    }

} catch (Exception $e) {
    mdt_error(500, 'E-1312', 'Erreur serveur auth', $e->getMessage());
}
