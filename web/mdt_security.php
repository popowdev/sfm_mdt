<?php

if (!defined('MDT_SECURITY_OWNER_ID')) define('MDT_SECURITY_OWNER_ID', '543211066805452805');

function mdt_sec_setup($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS security_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            severity VARCHAR(12) NOT NULL DEFAULT 'info',
            event_type VARCHAR(60) NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            path VARCHAR(255) DEFAULT NULL,
            method VARCHAR(10) DEFAULT NULL,
            actor VARCHAR(150) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            detail TEXT DEFAULT NULL,
            INDEX idx_ip_type (ip, event_type, created_at),
            INDEX idx_created (created_at),
            INDEX idx_sev (severity, created_at)
        )");
    } catch (Exception $e) {}
}

function mdt_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $first = trim($parts[0]);
        if ($first !== '') $ip = $first;
    }
    return substr($ip, 0, 45);
}

function mdt_seclog($conn, $eventType, $severity = 'info', $detail = array(), $actor = null) {
    if (!$conn) return;
    mdt_sec_setup($conn);
    $ip     = mdt_client_ip();
    $path   = isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 255) : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? substr($_SERVER['REQUEST_METHOD'], 0, 10) : '';
    $ua     = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    $det    = !empty($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null;
    try {
        $conn->prepare("INSERT INTO security_events (severity,event_type,ip,path,method,actor,user_agent,detail) VALUES (:s,:e,:ip,:p,:m,:a,:ua,:d)")
             ->execute(array(':s'=>$severity, ':e'=>$eventType, ':ip'=>$ip, ':p'=>$path, ':m'=>$method,
                             ':a'=>$actor ? substr($actor,0,150) : null, ':ua'=>$ua, ':d'=>$det));
    } catch (Exception $e) {}
    if ($severity === 'high' || $severity === 'critical') {
        mdt_sec_alert($conn, $eventType, $severity, $ip, $path, $actor, $detail);
    }
}

function mdt_audit_module() {
    $s = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    $s = preg_replace('/_api\.php$/', '', $s);
    $s = preg_replace('/\.php$/', '', $s);
    return $s !== '' ? $s : null;
}

function mdt_audit_write($conn, $row) {
    if (!$conn) return false;
    try {
        $st = $conn->prepare("INSERT INTO audit_log (module,action,actor_did,actor_name,entity_type,entity_id,result,err_code,http_status,source,summary,before_json,after_json,ip,ua)
                              VALUES (:module,:action,:did,:name,:et,:eid,:result,:err,:http,:source,:summary,:before,:after,:ip,:ua)");
        $st->execute(array(
            ':module'=>isset($row['module'])?$row['module']:null, ':action'=>isset($row['action'])?$row['action']:null,
            ':did'=>isset($row['actor_did'])?$row['actor_did']:null, ':name'=>isset($row['actor_name'])?mb_substr((string)$row['actor_name'],0,120):null,
            ':et'=>isset($row['entity_type'])?mb_substr((string)$row['entity_type'],0,40):null, ':eid'=>isset($row['entity_id'])?mb_substr((string)$row['entity_id'],0,60):null,
            ':result'=>(isset($row['result'])&&$row['result']==='error')?'error':'ok', ':err'=>isset($row['err_code'])?mb_substr((string)$row['err_code'],0,20):null,
            ':http'=>isset($row['http_status'])?(int)$row['http_status']:null, ':source'=>(isset($row['source'])&&$row['source']==='client')?'client':'server',
            ':summary'=>isset($row['summary'])?mb_substr((string)$row['summary'],0,255):null,
            ':before'=>isset($row['before'])?json_encode($row['before'],JSON_UNESCAPED_UNICODE):null,
            ':after'=>isset($row['after'])?json_encode($row['after'],JSON_UNESCAPED_UNICODE):null,
            ':ip'=>substr(function_exists('mdt_client_ip')?mdt_client_ip():(isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:''),0,45),
            ':ua'=>substr(isset($_SERVER['HTTP_USER_AGENT'])?$_SERVER['HTTP_USER_AGENT']:'',0,255),
        ));
        return true;
    } catch (Exception $e) { return false; }
}

function mdt_audit($conn, $action, $opts = array()) {
    $a = isset($GLOBALS['MDT_ACTOR']) ? $GLOBALS['MDT_ACTOR'] : array();
    mdt_audit_write($conn, array(
        'module'=>isset($opts['module'])?$opts['module']:mdt_audit_module(), 'action'=>$action,
        'actor_did'=>isset($a['did'])?$a['did']:null, 'actor_name'=>isset($a['name'])?$a['name']:null,
        'entity_type'=>isset($opts['entity_type'])?$opts['entity_type']:null, 'entity_id'=>isset($opts['entity_id'])?$opts['entity_id']:null,
        'result'=>'ok', 'http_status'=>200, 'source'=>'server',
        'summary'=>isset($opts['summary'])?$opts['summary']:null,
        'before'=>isset($opts['before'])?$opts['before']:null, 'after'=>isset($opts['after'])?$opts['after']:null,
    ));
    $GLOBALS['MDT_AUDITED'] = true;
}

function mdt_sec_alert($conn, $eventType, $severity, $ip, $path, $actor, $detail) {
    try {
        $q = $conn->prepare("SELECT COUNT(*) FROM security_events
                             WHERE event_type = :e AND ip = :ip AND severity IN ('high','critical')
                             AND created_at > (NOW() - INTERVAL 5 MINUTE)");
        $q->execute(array(':e'=>$eventType, ':ip'=>$ip));
        if ((int)$q->fetchColumn() > 1) return;
    } catch (Exception $e) {}
    $emoji = $severity === 'critical' ? '🚨' : '⚠️';
    $msg = $emoji . " **Alerte sécurité RP MDT** — " . strtoupper($severity) . " <@" . MDT_SECURITY_OWNER_ID . ">\n"
         . "• Événement : `" . $eventType . "`\n"
         . "• IP : `" . ($ip !== '' ? $ip : '?') . "`\n"
         . "• Cible : `" . ($path !== '' ? $path : '?') . "`\n"
         . ($actor ? "• Acteur : `" . $actor . "`\n" : '')
         . (!empty($detail) ? "• Détail : `" . substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300) . "`\n" : '')
         . "• " . date('Y-m-d H:i:s') . " (heure de Paris)";
    mdt_sec_deliver($msg);
}

function mdt_sec_deliver($msg) {
    $wh = mdt_env('ALERT_WEBHOOK');
    if ($wh) { if (mdt_discord_webhook($wh, $msg)) return true; }
    $chan = mdt_env('ALERT_CHANNEL_ID');
    if ($chan) { if (mdt_discord_channel_post($chan, $msg)) return true; }
    return mdt_discord_dm(MDT_SECURITY_OWNER_ID, $msg);
}

function mdt_env($key) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        $lines = @file(__DIR__ . '/../bot/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $p = strpos($line, '=');
            if ($p !== false) $cache[trim(substr($line, 0, $p))] = trim(trim(substr($line, $p + 1)), "\"'");
        }
    }
    return isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : null;
}

function mdt_discord_webhook($url, $message) {
    if (!function_exists('curl_init')) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array("Content-Type: application/json"),
        CURLOPT_POSTFIELDS => json_encode(array('content' => mb_substr($message, 0, 1900)), JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
    ));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function mdt_discord_channel_post($channelId, $message) {
    $token = mdt_bot_token();
    if (!$token || !function_exists('curl_init')) return false;
    $ch = curl_init("https://discord.com/api/v10/channels/" . rawurlencode($channelId) . "/messages");
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array("Authorization: Bot $token", "Content-Type: application/json"),
        CURLOPT_POSTFIELDS => json_encode(array('content' => mb_substr($message, 0, 1900)), JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
    ));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function mdt_bot_token() {
    static $tok = null;
    if ($tok !== null) return $tok;
    $tok = '';
    $lines = @file(__DIR__ . '/../bot/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) foreach ($lines as $line) {
        $line = ltrim($line);
        if (strpos($line, 'BOT_TOKEN=') === 0) { $tok = trim(trim(substr($line, 10)), "\"'"); break; }
    }
    return $tok;
}

function mdt_discord_dm($userId, $message) {
    $token = mdt_bot_token();
    if (!$token || !function_exists('curl_init')) return false;

    $ch = curl_init("https://discord.com/api/v10/users/@me/channels");
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array("Authorization: Bot $token", "Content-Type: application/json"),
        CURLOPT_POSTFIELDS => json_encode(array('recipient_id' => (string)$userId)),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300 || empty($data['id'])) return false;
    $chanId = $data['id'];

    $ch = curl_init("https://discord.com/api/v10/channels/$chanId/messages");
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array("Authorization: Bot $token", "Content-Type: application/json"),
        CURLOPT_POSTFIELDS => json_encode(array('content' => mb_substr($message, 0, 1900)), JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
    ));
    curl_exec($ch);
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code2 >= 200 && $code2 < 300;
}

function mdt_session_discord_id($conn) {
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$auth && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (isset($h['Authorization'])) $auth = $h['Authorization'];
    }
    $token = (strpos($auth, 'Bearer ') === 0) ? substr($auth, 7) : '';
    if ($token === '') return null;
    try {
        $st = $conn->prepare("SELECT u.discord_id FROM user_sessions s JOIN users u ON u.id = s.user_id
                              WHERE s.token = :t AND s.expires_at > NOW() LIMIT 1");
        $st->execute(array(':t' => $token));
        $did = $st->fetchColumn();
        if ($did) mdt_touch_session($conn, $token);
        return $did ? $did : null;
    } catch (Exception $e) { return null; }
}

define('MDT_SESSION_DAYS', 30);

function mdt_touch_session($conn, $token) {
    if (!$token) return;
    try {
        $half = (int)floor(MDT_SESSION_DAYS / 2);
        $st = $conn->prepare("UPDATE user_sessions
                              SET expires_at = DATE_ADD(NOW(), INTERVAL :d DAY)
                              WHERE token = :t AND expires_at > NOW()
                                AND expires_at < DATE_ADD(NOW(), INTERVAL :h DAY)");
        $st->execute(array(':d' => MDT_SESSION_DAYS, ':t' => $token, ':h' => $half));
    } catch (Exception $e) {}
}

function mdt_bearer_token() {
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$auth && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (isset($h['Authorization'])) $auth = $h['Authorization'];
    }
    return (strpos($auth, 'Bearer ') === 0) ? substr($auth, 7) : '';
}

function mdt_session_user($conn) {
    $token = mdt_bearer_token();
    if ($token === '') return null;
    try {
        $st = $conn->prepare("SELECT u.id AS user_id, u.discord_id, u.username, u.discord_nick, u.discord_username
                              FROM user_sessions s JOIN users u ON u.id = s.user_id
                              WHERE s.token = :t AND s.expires_at > NOW() LIMIT 1");
        $st->execute(array(':t' => $token));
        $row = $st->fetch();
        if ($row) mdt_touch_session($conn, $token);
        return $row ? $row : null;
    } catch (Exception $e) { return null; }
}

function mdt_require_auth($conn, $ctx = '') {
    $u = mdt_session_user($conn);
    if (!$u) {
        mdt_seclog($conn, 'unauth_access' . ($ctx ? '_' . $ctx : ''), 'medium', array());
        if (function_exists('mdt_error')) mdt_error(401, 'E-SEC-401', 'Authentification requise');
        http_response_code(401);
        echo json_encode(array('error' => 'Authentification requise', 'code' => 'E-SEC-401'));
        exit;
    }
    $GLOBALS['MDT_ACTOR'] = array(
        'did'  => isset($u['discord_id']) ? (string)$u['discord_id'] : null,
        'name' => isset($u['discord_nick']) && $u['discord_nick'] ? $u['discord_nick']
                : (isset($u['discord_username']) && $u['discord_username'] ? $u['discord_username']
                : (isset($u['username']) ? $u['username'] : (isset($u['discord_id']) ? (string)$u['discord_id'] : null)))
    );
    return $u;
}

function mdt_is_owner($discordId) {
    $owners = array('543211066805452805');
    return $discordId && in_array((string)$discordId, $owners, true);
}

function mdt_admin_roles() {
    return array(
        '1210813640987377761',
        '1210813640987377758',
        '1210813640987377759',
        '1485642111314432082',
        '1485642087360757991',
    );
}

function mdt_is_admin($conn, $did) {
    if (!$did) return false;
    if (mdt_is_owner($did)) return true;
    try {
        $q = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = :d LIMIT 1");
        $q->execute(array(':d' => $did));
        if ($q->fetch()) return true;
    } catch (Exception $e) {}
    try {
        $r = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = :d LIMIT 1");
        $r->execute(array(':d' => $did));
        $raw = $r->fetchColumn();
        $roles = $raw ? json_decode($raw, true) : array();
        if (is_array($roles)) {
            foreach (mdt_admin_roles() as $ar) { if (in_array($ar, $roles, true)) return true; }
        }
    } catch (Exception $e) {}
    return false;
}

function mdt_require_admin($conn, $ctx = 'admin') {
    $did = mdt_session_discord_id($conn);
    if (!mdt_is_admin($conn, $did)) {
        mdt_seclog($conn, 'unauth_admin_' . $ctx, 'high', array('discord_id' => $did ? (string)$did : null));
        if (function_exists('mdt_error')) mdt_error(403, 'E-SEC-403', 'Acces refuse : reserve au commandement (Etat-Major) et aux devs');
        http_response_code(403);
        echo json_encode(array('error' => 'Acces refuse', 'code' => 'E-SEC-403'));
        exit;
    }
    return $did;
}

function mdt_require_superadmin($conn, $ctx = 'admin') {
    $did = mdt_session_discord_id($conn);
    $ok = false;
    if ($did) {
        if (mdt_is_owner($did)) $ok = true;
        else {
            try {
                $q = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = :d LIMIT 1");
                $q->execute(array(':d' => $did));
                if ($q->fetch()) $ok = true;
            } catch (Exception $e) {}
        }
    }
    if (!$ok) {
        mdt_seclog($conn, 'unauth_admin_' . $ctx, 'high', array('discord_id' => $did ? (string)$did : null));
        if (function_exists('mdt_error')) mdt_error(403, 'E-SEC-403', 'Acces refuse : super-admin requis');
        http_response_code(403);
        echo json_encode(array('error' => 'Acces refuse', 'code' => 'E-SEC-403'));
        exit;
    }
    return $did;
}

function mdt_valid_modules() {
    return array('mdt', 'sd', 'upload', 'admin', 'td', 'suivi', 'plainte', 'saisies', 'dispatch', 'documents');
}

function mdt_role_config($conn, $capKey, $fallback = array()) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS role_config (
                cap_key VARCHAR(50) NOT NULL, role_id VARCHAR(30) NOT NULL,
                PRIMARY KEY (cap_key, role_id))");
            foreach ($conn->query("SELECT cap_key, role_id FROM role_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['cap_key']][] = (string)$r['role_id'];
            }
        } catch (Exception $e) { $cache = array(); }
    }
    if (isset($cache[$capKey]) && count($cache[$capKey]) > 0) return $cache[$capKey];
    return array_map('strval', $fallback);
}

function mdt_module_access($conn, $moduleKey, $discordId) {
    if (!$moduleKey) return true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS module_permissions (
            module_key VARCHAR(30) NOT NULL, role_id VARCHAR(30) NOT NULL,
            PRIMARY KEY (module_key, role_id))");
        $stmt = $conn->prepare("SELECT role_id FROM module_permissions WHERE module_key = :mk");
        $stmt->execute(array(':mk' => $moduleKey));
        $required = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { return true; }
    if (empty($required)) return true;
    if (!$discordId) return false;
    if (mdt_is_admin($conn, $discordId)) return true;
    try {
        $r = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = :d LIMIT 1");
        $r->execute(array(':d' => $discordId));
        $raw = $r->fetchColumn();
        $roles = $raw ? json_decode($raw, true) : array();
        if (!is_array($roles)) $roles = array();
        return count(array_intersect($roles, $required)) > 0;
    } catch (Exception $e) { return false; }
}

function mdt_require_module($conn, $moduleKey, $ctx = '') {
    $did = mdt_session_discord_id($conn);
    if (!mdt_module_access($conn, $moduleKey, $did)) {
        mdt_seclog($conn, 'unauth_module_' . ($ctx !== '' ? $ctx : $moduleKey), 'medium',
            array('module' => $moduleKey, 'discord_id' => $did ? (string)$did : null));
        if (function_exists('mdt_error')) mdt_error(403, 'E-SEC-403', 'Acces refuse : role requis pour ce module');
        http_response_code(403);
        echo json_encode(array('error' => 'Acces refuse', 'code' => 'E-SEC-403'));
        exit;
    }
    return $did;
}

function mdt_bruteforce_blocked($conn, $key, $max = 10, $windowMin = 15) {
    if (!$conn) return false;
    mdt_sec_setup($conn);
    try {
        $q = $conn->prepare("SELECT COUNT(*) FROM security_events
                             WHERE event_type = :e AND ip = :ip
                             AND created_at > (NOW() - INTERVAL {$windowMin} MINUTE)");
        $q->execute(array(':e'=>$key.'_fail', ':ip'=>mdt_client_ip()));
        return (int)$q->fetchColumn() >= $max;
    } catch (Exception $e) { return false; }
}

define('MDT_PRESENCE_ONLINE', 90);
define('MDT_PRESENCE_AWAY', 300);

function mdt_presence_ping($conn, $did, $visible) {
    if (!$conn || !$did) return;
    try {
        if ($visible) {
            $st = $conn->prepare("INSERT INTO user_presence (discord_id, last_seen, last_active) VALUES (:d, NOW(), NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW(), last_active = NOW()");
        } else {
            $st = $conn->prepare("INSERT INTO user_presence (discord_id, last_seen) VALUES (:d, NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW()");
        }
        $st->execute(array(':d' => (string)$did));
    } catch (Exception $e) {}
}

function mdt_presence_map($conn, $dids) {
    $out = array();
    if (!$conn) return $out;
    $dids = array_values(array_unique(array_filter(array_map('strval', (array)$dids), 'strlen')));
    if (!$dids) return $out;
    if (count($dids) > 300) $dids = array_slice($dids, 0, 300);
    foreach ($dids as $d) $out[$d] = 'offline';
    try {
        $ph = implode(',', array_fill(0, count($dids), '?'));
        $st = $conn->prepare("SELECT discord_id, TIMESTAMPDIFF(SECOND, last_active, NOW()) AS a, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS s FROM user_presence WHERE discord_id IN ($ph)");
        $st->execute($dids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a = $r['a']; $s = $r['s'];
            $status = 'offline';
            if ($a !== null && (int)$a <= MDT_PRESENCE_ONLINE) $status = 'online';
            else if ($s !== null && (int)$s <= MDT_PRESENCE_ONLINE) $status = 'away';
            else if ($a !== null && (int)$a <= MDT_PRESENCE_AWAY) $status = 'away';
            $out[(string)$r['discord_id']] = $status;
        }
    } catch (Exception $e) {}
    return $out;
}

function mdt_audit_flush() {
    try {
        if (!empty($GLOBALS['MDT_AUDITED'])) return;
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $status = function_exists('http_response_code') ? (int)http_response_code() : 200;
        $isErr = $status >= 400;
        if ($method !== 'POST' && !$isErr) return;
        $auditBn = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''); if ($auditBn === 'log_api.php' || $auditBn === 'presence_api.php') return;
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof PDO)) return;
        if (!function_exists('mdt_audit_write')) return;
        $a = isset($GLOBALS['MDT_ACTOR']) ? $GLOBALS['MDT_ACTOR'] : array();
        mdt_audit_write($GLOBALS['conn'], array(
            'module'      => function_exists('mdt_audit_module') ? mdt_audit_module() : null,
            'action'      => substr((string)(isset($_GET['action']) ? $_GET['action'] : ''), 0, 60),
            'actor_did'   => isset($a['did']) ? $a['did'] : null,
            'actor_name'  => isset($a['name']) ? $a['name'] : null,
            'result'      => $isErr ? 'error' : 'ok',
            'err_code'    => isset($GLOBALS['MDT_ERR_CODE']) ? $GLOBALS['MDT_ERR_CODE'] : null,
            'http_status' => $status,
            'source'      => 'server',
        ));
    } catch (Throwable $e) {   }
}
register_shutdown_function('mdt_audit_flush');
