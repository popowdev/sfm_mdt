<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../mdt_roles.php';

if (file_exists(__DIR__ . '/dispatch_perms.php')) {
    require_once __DIR__ . '/dispatch_perms.php';
}
mdt_cors();

$GLOBALS['DP_SELF_DID'] = function_exists('dpResolveActorDiscordId') ? dpResolveActorDiscordId($conn) : null;
$__dpBody = json_decode(file_get_contents('php://input'), true);
$GLOBALS['DP_BODY'] = is_array($__dpBody) ? $__dpBody : null;
if (!empty($GLOBALS['DP_SELF_DID'])) {
    $_GET['discord_id'] = $GLOBALS['DP_SELF_DID'];
    $_POST['discord_id'] = $GLOBALS['DP_SELF_DID'];
    if (is_array($GLOBALS['DP_BODY'])) $GLOBALS['DP_BODY']['discord_id'] = $GLOBALS['DP_SELF_DID'];
}
function dp_get_post_data($code = 'E-104') {
    return is_array($GLOBALS['DP_BODY']) ? $GLOBALS['DP_BODY'] : null;
}

if (!defined('DP_AUTO_CLOSE_INTERVENTIONS')) define('DP_AUTO_CLOSE_INTERVENTIONS', false);

define('DP_DDL_VERSION', '2026-08-02.2');
$dpDdlUpToDate = false;
try {
    $dpDdlSt = $conn->query("SELECT mv FROM dispatch_meta WHERE mk = 'ddl_version'");
    $dpDdlUpToDate = ($dpDdlSt && $dpDdlSt->fetchColumn() === DP_DDL_VERSION);
} catch (Exception $e) {

    try { $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_meta (mk VARCHAR(50) PRIMARY KEY, mv VARCHAR(255))"); } catch (Exception $e2) {}
}
if (!$dpDdlUpToDate) try {
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_statuts (
        id VARCHAR(50) PRIMARY KEY, label VARCHAR(100) NOT NULL,
        couleur VARCHAR(7) NOT NULL DEFAULT '#3b82f6', ordre INT NOT NULL DEFAULT 0, INDEX idx_ordre (ordre)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_vehicules (
        id VARCHAR(50) PRIMARY KEY, label VARCHAR(100) NOT NULL,
        plaque VARCHAR(20) DEFAULT NULL, type VARCHAR(50) DEFAULT 'Sedan',
        ordre INT NOT NULL DEFAULT 0, INDEX idx_ordre (ordre)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_patrouilles (
        id VARCHAR(50) PRIMARY KEY, indicatif VARCHAR(50) NOT NULL, vehicule VARCHAR(100) DEFAULT NULL,
        canal_radio VARCHAR(20) DEFAULT NULL, notes TEXT DEFAULT NULL,
        statut_id VARCHAR(50) NOT NULL DEFAULT 'ds_disponible', statut_since DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_statut (statut_id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_patrouille_agents (
        patrouille_id VARCHAR(50) NOT NULL, roster_id INT NOT NULL,
        PRIMARY KEY (patrouille_id, roster_id), INDEX idx_roster (roster_id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_interventions (
        id VARCHAR(50) PRIMARY KEY, type VARCHAR(50) NOT NULL, lieu VARCHAR(255) DEFAULT NULL,
        priorite ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium', description TEXT DEFAULT NULL,
        statut ENUM('nouveau','en_cours','termine') NOT NULL DEFAULT 'nouveau',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        closed_at DATETIME DEFAULT NULL, INDEX idx_statut (statut), INDEX idx_priorite (priorite)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_intervention_patrouilles (
        intervention_id VARCHAR(50) NOT NULL, patrouille_id VARCHAR(50) NOT NULL,
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (intervention_id, patrouille_id), INDEX idx_patrouille (patrouille_id)
    )");

    $cntDS = $conn->query("SELECT COUNT(*) FROM dispatch_statuts")->fetchColumn();
    if ((int)$cntDS === 0) {
        $conn->exec("INSERT INTO dispatch_statuts (id, label, couleur, ordre) VALUES
            ('ds_disponible', 'Disponible', '#10b981', 0),
            ('ds_patrouille', 'En patrouille', '#3b82f6', 1),
            ('ds_intervention', 'En intervention', '#f59e0b', 2),
            ('ds_hors_service', 'Hors service', '#ef4444', 3)
        ");
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_action_buttons (
        id VARCHAR(50) PRIMARY KEY, code VARCHAR(20) NOT NULL, label VARCHAR(100) NOT NULL,
        intervention_type VARCHAR(50) NOT NULL DEFAULT 'autre', icon VARCHAR(50) DEFAULT 'fa-circle-exclamation',
        color VARCHAR(7) DEFAULT '#3b82f6', fields_config JSON DEFAULT NULL,
        statut_target VARCHAR(50) DEFAULT 'ds_intervention', is_reset TINYINT(1) DEFAULT 0,
        ordre INT NOT NULL DEFAULT 0, INDEX idx_ordre (ordre)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_services (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, roster_id INT NOT NULL,
        start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, duration_minutes INT DEFAULT NULL,
        service_date DATE NOT NULL, INDEX idx_user (user_id), INDEX idx_roster (roster_id),
        INDEX idx_date (service_date), INDEX idx_active (end_at)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_dispatchers (
        id INT AUTO_INCREMENT PRIMARY KEY, roster_id INT NOT NULL,
        role ENUM('dispatcher','co_dispatcher') NOT NULL, assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role (role)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_patrol_types (
        id VARCHAR(50) PRIMARY KEY, label VARCHAR(100) NOT NULL,
        nom_format VARCHAR(100) DEFAULT NULL, icon VARCHAR(50) DEFAULT 'fa-shield-halved',
        color VARCHAR(7) DEFAULT '#3b82f6', ordre INT NOT NULL DEFAULT 0, INDEX idx_ordre (ordre)
    )");

    $cntPT = $conn->query("SELECT COUNT(*) FROM dispatch_patrol_types")->fetchColumn();
    if ((int)$cntPT === 0) {
        $conn->exec("INSERT INTO dispatch_patrol_types (id, label, nom_format, icon, color, ordre) VALUES
            ('dpt_standard', 'Standard', NULL, 'fa-shield-halved', '#3b82f6', 0),
            ('dpt_swat', 'SWAT', 'METRO', 'fa-crosshairs', '#ef4444', 1),
            ('dpt_moto', 'Moto', 'MOTO', 'fa-motorcycle', '#f59e0b', 2),
            ('dpt_helico', 'Helicoptere', 'HELICO', 'fa-helicopter', '#10b981', 3)
        ");
    }

    try {
        $conn->query("SELECT discord_id FROM roster LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE roster ADD COLUMN discord_id VARCHAR(30) DEFAULT NULL");
    }
    try {
        $conn->query("SELECT created_by_user_id FROM dispatch_interventions LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_interventions ADD COLUMN created_by_user_id INT DEFAULT NULL, ADD COLUMN action_button_id VARCHAR(50) DEFAULT NULL, ADD COLUMN action_fields_data JSON DEFAULT NULL");
    }
    try {
        $conn->query("SELECT canal_radio FROM dispatch_interventions LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_interventions ADD COLUMN canal_radio VARCHAR(20) DEFAULT NULL");
    }
    try {
        $conn->query("SELECT patrol_type_id FROM dispatch_patrouilles LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_patrouilles ADD COLUMN patrol_type_id VARCHAR(50) DEFAULT NULL");
    }
    try {
        $conn->query("SELECT custom_indicatif FROM dispatch_patrouilles LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_patrouilles ADD COLUMN custom_indicatif TINYINT(1) DEFAULT 0");
    }
    try {
        $conn->query("SELECT armement FROM dispatch_patrouilles LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_patrouilles ADD COLUMN armement VARCHAR(100) DEFAULT NULL, ADD COLUMN tph VARCHAR(30) DEFAULT NULL, ADD COLUMN info_sup TEXT DEFAULT NULL");
    }
    try {
        $conn->query("SELECT secteur FROM dispatch_patrouilles LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_patrouilles ADD COLUMN secteur VARCHAR(50) DEFAULT 'all'");
    }
    try {
        $conn->query("SELECT plaque FROM dispatch_vehicules LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_vehicules ADD COLUMN plaque VARCHAR(20) DEFAULT NULL AFTER label");
    }
    try {
        $conn->query("SELECT statut FROM dispatch_intervention_vehicules LIMIT 1");
    } catch (PDOException $e2) {
        $conn->exec("ALTER TABLE dispatch_intervention_vehicules ADD COLUMN statut VARCHAR(30) DEFAULT 'en_cours'");
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_intervention_vehicules (
        id VARCHAR(50) PRIMARY KEY,
        intervention_id VARCHAR(50) NOT NULL,
        modele VARCHAR(100) DEFAULT NULL,
        couleur VARCHAR(50) DEFAULT NULL,
        immat VARCHAR(20) DEFAULT NULL,
        nb_personnes INT DEFAULT NULL,
        armes VARCHAR(20) DEFAULT NULL,
        photo_url TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        statut VARCHAR(30) DEFAULT 'en_cours',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inter (intervention_id)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_vehicule_assignations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicule_id VARCHAR(50) NOT NULL,
        patrouille_id VARCHAR(50) NOT NULL,
        position VARCHAR(5) NOT NULL DEFAULT 'P1',
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_veh_pat (vehicule_id, patrouille_id),
        INDEX idx_vehicule (vehicule_id),
        INDEX idx_patrouille (patrouille_id)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_intervention_attachments (
        id VARCHAR(50) PRIMARY KEY,
        intervention_id VARCHAR(50) NOT NULL,
        file_url TEXT NOT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_type VARCHAR(20) DEFAULT 'image',
        uploaded_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inter (intervention_id)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_pauses (
        roster_id INT PRIMARY KEY, since DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_queue (
        roster_id INT PRIMARY KEY, since DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_poursuite_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        intervention_id VARCHAR(50) NOT NULL,
        vehicule_id VARCHAR(50) NOT NULL,
        patrouille_id VARCHAR(50) NOT NULL,
        position VARCHAR(5) NOT NULL DEFAULT 'P1',
        depart_at DATETIME DEFAULT NULL,
        fin_at DATETIME DEFAULT NULL,
        resultat VARCHAR(30) DEFAULT NULL,
        transport_destination VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inter (intervention_id),
        INDEX idx_patrol (patrouille_id)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_operations (
        id VARCHAR(50) PRIMARY KEY, label VARCHAR(100) NOT NULL,
        icon VARCHAR(50) DEFAULT 'fa-briefcase', color VARCHAR(7) DEFAULT '#f59e0b',
        frequence VARCHAR(20) DEFAULT NULL, ordre INT NOT NULL DEFAULT 0,
        INDEX idx_ordre (ordre)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_agent_operations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        operation_id VARCHAR(50) NOT NULL, roster_id INT NOT NULL,
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_roster (roster_id), INDEX idx_op (operation_id)
    )");

    $cntOp = $conn->query("SELECT COUNT(*) FROM dispatch_operations")->fetchColumn();
    if ((int)$cntOp === 0) {
        $conn->exec("INSERT INTO dispatch_operations (id, label, icon, color, frequence, ordre) VALUES
            ('op_academy','Police Academy','fa-building-columns','#ef4444','FRQ 3',1),
            ('op_formation3','Formation','fa-chalkboard-user','#f97316','FRQ 3',2),
            ('op_formation4','Formation','fa-chalkboard-user','#f97316','FRQ 4',3),
            ('op_lincoln','First Lincoln','fa-car-side','#3b82f6','FRQ 3',4),
            ('op_otage','Prise d''otage','fa-handcuffs','#ef4444','FRQ 10',5),
            ('op_superette','Superette','fa-store','#f59e0b','FRQ 11',6),
            ('op_superette2','Superette','fa-store','#f59e0b','FRQ 12',7),
            ('op_ammunition','Ammunition','fa-gun','#8b5cf6','FRQ 13',8),
            ('op_ammunition2','Ammunition','fa-gun','#8b5cf6','FRQ 14',9),
            ('op_convoi','Convoi','fa-truck','#06b6d4','FRQ 14',10),
            ('op_fleeca','Fleeca','fa-building-columns','#10b981','FRQ 15',11),
            ('op_bijouterie','Bijouterie','fa-gem','#fbbf24','FRQ 16',12),
            ('op_humanlabs','Human Labs','fa-flask','#a78bfa','FRQ 17',13),
            ('op_entreprise','Entreprise','fa-city','#64748b','FRQ 18',14)
        ");
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_wanted_persons (
        id VARCHAR(50) PRIMARY KEY,
        nom_prenom VARCHAR(200) NOT NULL,
        photo_url TEXT DEFAULT NULL,
        info_sup TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_wanted_vehicles (
        id VARCHAR(50) PRIMARY KEY,
        modele VARCHAR(100) DEFAULT NULL,
        couleur VARCHAR(50) DEFAULT NULL,
        immat VARCHAR(30) DEFAULT NULL,
        photo_url TEXT DEFAULT NULL,
        proprio VARCHAR(200) DEFAULT NULL,
        info_sup TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        actor_discord_id VARCHAR(30) DEFAULT NULL,
        actor_matricule VARCHAR(10) DEFAULT NULL,
        actor_name VARCHAR(200) DEFAULT NULL,
        action VARCHAR(60) NOT NULL,
        target_type VARCHAR(30) DEFAULT NULL,
        target_id VARCHAR(60) DEFAULT NULL,
        target_label VARCHAR(255) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        INDEX idx_created (created_at),
        INDEX idx_action (action),
        INDEX idx_actor (actor_discord_id),
        INDEX idx_target (target_type, target_id)
    )");

    try { $conn->query("SELECT actor_matricule FROM dispatch_logs LIMIT 0"); }
    catch (PDOException $eL) { try { $conn->exec("ALTER TABLE dispatch_logs ADD COLUMN actor_matricule VARCHAR(10) DEFAULT NULL"); } catch (Exception $e2) {} }
    try { $conn->query("SELECT actor_name FROM dispatch_logs LIMIT 0"); }
    catch (PDOException $eL) { try { $conn->exec("ALTER TABLE dispatch_logs ADD COLUMN actor_name VARCHAR(200) DEFAULT NULL"); } catch (Exception $e2) {} }

    foreach (array(
        'last_active'         => "ADD COLUMN last_active DATETIME NULL DEFAULT NULL",
        'annule'              => "ADD COLUMN annule TINYINT(1) NOT NULL DEFAULT 0",
        'check_pending_since' => "ADD COLUMN check_pending_since DATETIME NULL DEFAULT NULL",
        'auto_close_reason'   => "ADD COLUMN auto_close_reason VARCHAR(24) NULL DEFAULT NULL",
        'auto_close_acked'    => "ADD COLUMN auto_close_acked TINYINT NOT NULL DEFAULT 1",
    ) as $afkCol => $afkDdl) {
        try { $conn->query("SELECT `$afkCol` FROM dispatch_services LIMIT 0"); }
        catch (PDOException $eA) { try { $conn->exec("ALTER TABLE dispatch_services $afkDdl"); } catch (Exception $e2) {} }
    }
    try { $conn->exec("UPDATE dispatch_services SET last_active = COALESCE(last_seen, start_at) WHERE end_at IS NULL AND last_active IS NULL"); } catch (Exception $e2) {}

    try { $conn->exec("ALTER TABLE dispatch_dispatchers DROP INDEX IF EXISTS uk_role"); } catch (Exception $e2) {}
    try { $conn->exec("ALTER TABLE dispatch_dispatchers MODIFY role ENUM('dispatcher','co_dispatcher','supervision') NOT NULL"); } catch (Exception $e2) {}
    try { $conn->exec("ALTER TABLE dispatch_dispatchers ADD UNIQUE INDEX IF NOT EXISTS uk_role_roster (role, roster_id)"); } catch (Exception $e2) {}

    try { $conn->query("SELECT empty_since FROM dispatch_interventions LIMIT 0"); }
    catch (PDOException $eM) { try { $conn->exec("ALTER TABLE dispatch_interventions ADD COLUMN empty_since DATETIME NULL DEFAULT NULL"); } catch (Exception $e2) {} }

    try { $conn->exec("ALTER TABLE dispatch_interventions ADD INDEX idx_statut_empty (statut, empty_since)"); } catch (Exception $eI) {}
    try { $conn->exec("ALTER TABLE roster ADD INDEX idx_roster_discord (discord_id)"); } catch (Exception $eI) {}
    try { $conn->exec("ALTER TABLE dispatch_services ADD INDEX idx_svc_endat (end_at)"); } catch (Exception $eI) {}

    $conn->prepare("INSERT INTO dispatch_meta (mk, mv) VALUES ('ddl_version', :v) ON DUPLICATE KEY UPDATE mv = :v2")
        ->execute(array(':v' => DP_DDL_VERSION, ':v2' => DP_DDL_VERSION));
} catch (PDOException $e) {

}

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_avertos (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        roster_id INT NOT NULL,
        discord_id VARCHAR(30) NULL,
        matricule VARCHAR(20) NULL,
        nom_prenom VARCHAR(120) NULL,
        semaine DATE NOT NULL,
        motif VARCHAR(255) NOT NULL DEFAULT '',
        rang TINYINT UNSIGNED NOT NULL DEFAULT 1,
        sanction TINYINT(1) NOT NULL DEFAULT 0,
        vu TINYINT(1) NOT NULL DEFAULT 0,
        by_mat VARCHAR(20) NULL,
        by_name VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_agent (roster_id, semaine),
        KEY k_vu (discord_id, vu)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$action = isset($_GET['action']) ? $_GET['action'] : '';
mdt_require_auth($conn, 'dispatch');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($GLOBALS['DP_SELF_DID']) && $action !== 'heartbeat' && $action !== 'confirm_afk_check') {
    dpHeartbeat($conn, $GLOBALS['DP_SELF_DID'], true);
}

function broadcastUpdate($type = 'board_changed') {
    static $sent = array();
    if (isset($sent[$type])) return;
    $sent[$type] = 1;

    if (!defined('BOT_API_KEY')) { @include_once __DIR__ . '/../bot_config.php'; }
    $bkey = defined('BOT_API_KEY') ? BOT_API_KEY : '';
    if ($bkey === '') return;
    $ch = curl_init((defined('BOT_API_URL') ? BOT_API_URL : 'http://127.0.0.1:3100') . '/api/broadcast');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Api-Key: ' . $bkey),
        CURLOPT_POSTFIELDS => json_encode(array('type' => $type)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 500,
        CURLOPT_CONNECTTIMEOUT_MS => 300
    ));
    @curl_exec($ch);
    @curl_close($ch);
}

function dpEchoWithEtag($payload) {
    $etag = '"' . md5($payload) . '"';
    header('ETag: ' . $etag);
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($inm === $etag) { http_response_code(304); return; }
    echo $payload;
}

function dpResolveActor($conn, $hint) {
    static $cache = array();
    if ($hint === null || $hint === '') return array('discord_id' => null, 'matricule' => null, 'nom_prenom' => null);
    $key = (string)$hint;
    if (isset($cache[$key])) return $cache[$key];

    $isDiscord = is_string($hint) && strlen($hint) >= 15 && ctype_digit($hint);
    try {
        if ($isDiscord) {
            $stmt = $conn->prepare("SELECT discord_id, matricule, nom_prenom FROM roster WHERE discord_id = :d LIMIT 1");
            $stmt->execute(array(':d' => $hint));
        } else {
            $stmt = $conn->prepare("SELECT discord_id, matricule, nom_prenom FROM roster WHERE id = :i LIMIT 1");
            $stmt->execute(array(':i' => (int)$hint));
        }
        $row = $stmt->fetch();
    } catch (Exception $e) { $row = null; }

    if (!$row) $row = array('discord_id' => ($isDiscord ? $hint : null), 'matricule' => null, 'nom_prenom' => null);
    $cache[$key] = $row;
    return $row;
}

function dpLog($conn, $action, $opts = array()) {
    try {
        $actor = isset($opts['actor']) ? dpResolveActor($conn, $opts['actor']) : array('discord_id' => null, 'matricule' => null, 'nom_prenom' => null);
        $stmt = $conn->prepare(
            "INSERT INTO dispatch_logs
             (actor_discord_id, actor_matricule, actor_name, action, target_type, target_id, target_label, details, ip)
             VALUES (:adid, :amat, :anom, :act, :ttype, :tid, :tlab, :det, :ip)"
        );
        $stmt->execute(array(
            ':adid'  => $actor['discord_id'],
            ':amat'  => $actor['matricule'],
            ':anom'  => $actor['nom_prenom'],
            ':act'   => $action,
            ':ttype' => isset($opts['target_type']) ? $opts['target_type'] : null,
            ':tid'   => isset($opts['target_id']) ? (string)$opts['target_id'] : null,
            ':tlab'  => isset($opts['target_label']) ? mb_substr((string)$opts['target_label'], 0, 190) : null,
            ':det'   => isset($opts['details']) && $opts['details'] !== null ? json_encode($opts['details'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
            ':ip'    => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        ));
    } catch (Exception $e) {
        error_log('[dpLog] ' . $e->getMessage() . ' action=' . $action);
    }
}

function dpEnsureLastSeenColumn($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try { $conn->query("SELECT last_seen FROM dispatch_services LIMIT 0"); }
    catch (Exception $e) {
        try { $conn->exec("ALTER TABLE dispatch_services ADD COLUMN last_seen DATETIME DEFAULT NULL"); } catch (Exception $e2) {}

        try { $conn->exec("UPDATE dispatch_services SET last_seen = start_at WHERE end_at IS NULL AND last_seen IS NULL"); } catch (Exception $e3) {}
    }
}

function dpHeartbeat($conn, $discordId, $active = false) {
    if (!$discordId) return;
    dpEnsureLastSeenColumn($conn);
    try {

        try {
            $gq = $conn->prepare("SELECT s.last_seen FROM dispatch_services s JOIN roster r ON r.id = s.roster_id WHERE r.discord_id = :did AND s.end_at IS NULL LIMIT 1");
            $gq->execute(array(':did' => $discordId));
            $prevSeen = $gq->fetchColumn();
            if ($prevSeen) {
                $gapMin = (int)floor((time() - strtotime($prevSeen)) / 60);
                if ($gapMin >= 5) {
                    dpLog($conn, 'afk_hb_gap', array('actor' => $discordId, 'target_type' => 'service',
                        'details' => array('gap_min' => $gapMin, 'ua' => substr((string)(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 120))));
                }
            }
        } catch (Exception $eG) {}

        $conn->prepare("
            UPDATE dispatch_services s
            JOIN roster r ON r.id = s.roster_id
            SET s.last_seen = NOW(),
                s.last_active = IF(:act = 1, NOW(), s.last_active),
                s.check_pending_since = IF(:act2 = 1, NULL, s.check_pending_since)
            WHERE r.discord_id = :did AND s.end_at IS NULL
              AND (s.last_seen IS NULL OR s.last_seen < NOW() - INTERVAL 60 SECOND
                   OR (:act3 = 1 AND (s.last_active IS NULL OR s.last_active < NOW() - INTERVAL 60 SECOND)))
        ")->execute(array(':did' => $discordId, ':act' => $active ? 1 : 0, ':act2' => $active ? 1 : 0, ':act3' => $active ? 1 : 0));
    } catch (Exception $e) {}
}

function dpAfkParams($conn) {
    static $P = null;
    if ($P !== null) return $P;
    $P = array('mode' => 'shadow', 'check_min' => 180, 'window_min' => 45, 'hb_dead_min' => 15,
               'resume_min' => 15, 'idle_cap_min' => 240, 'off_start' => 21, 'off_end' => 24);
    try {
        $rows = $conn->query("SELECT mk, mv FROM dispatch_meta WHERE mk LIKE 'afk_%'")->fetchAll();
        foreach ($rows as $r) {
            $k = substr($r['mk'], 4);
            if ($k === 'mode') { if (in_array($r['mv'], array('off','shadow','enforce'), true)) $P['mode'] = $r['mv']; }
            elseif (array_key_exists($k, $P)) $P[$k] = max(1, (int)$r['mv']);
        }
    } catch (Exception $e) {}
    return $P;
}

function dpAfkSweep($conn) {
    $P = dpAfkParams($conn);
    if ($P['mode'] === 'off') return;
    try {
        $hour = (int)date('G');
        $offEnd = $P['off_end'] === 0 ? 24 : $P['off_end'];
        $inOff = ($hour >= $P['off_start'] && $hour < $offEnd);
        $threshold = $inOff ? max($P['idle_cap_min'], $P['check_min']) : $P['check_min'];

        $arm = $conn->prepare("
            UPDATE dispatch_services
            SET check_pending_since = NOW()
            WHERE end_at IS NULL AND check_pending_since IS NULL
              AND last_seen IS NOT NULL AND last_seen >= NOW() - INTERVAL :dead MINUTE
              AND COALESCE(last_active, start_at) < NOW() - INTERVAL :thr MINUTE
        ");
        $arm->execute(array(':dead' => $P['hb_dead_min'], ':thr' => $threshold));
        if ($arm->rowCount() > 0 && $P['mode'] === 'shadow') {
            dpLog($conn, 'afk_check_armed_shadow', array('target_type' => 'service', 'details' => array('count' => $arm->rowCount(), 'threshold_min' => $threshold, 'in_off_window' => $inOff)));
        }

        $exp = $conn->prepare("
            SELECT s.id, s.roster_id, s.start_at, s.last_seen, s.last_active, s.check_pending_since, r.matricule, r.nom_prenom, r.discord_id
            FROM dispatch_services s JOIN roster r ON r.id = s.roster_id
            WHERE s.end_at IS NULL AND s.check_pending_since IS NOT NULL
              AND s.check_pending_since < NOW() - INTERVAL :win MINUTE
        ");
        $exp->execute(array(':win' => $P['window_min']));
        foreach ($exp->fetchAll() as $svc) {
            $lastActive = $svc['last_active'] ?: $svc['start_at'];
            $nonCredite = max(0, (int)floor((time() - strtotime($lastActive)) / 60));
            if ($P['mode'] === 'shadow') {

                dpLog($conn, 'afk_would_close', array('actor' => $svc['discord_id'], 'target_type' => 'service', 'target_id' => $svc['id'],
                    'target_label' => $svc['matricule'] . ' | ' . $svc['nom_prenom'],
                    'details' => array('credited_until' => $lastActive, 'non_credite_min' => $nonCredite)));
                $conn->prepare("UPDATE dispatch_services SET check_pending_since = NULL WHERE id = :id AND end_at IS NULL")->execute(array(':id' => $svc['id']));
                continue;
            }

            $endAt = date('Y-m-d H:i:s', min(time(), max(strtotime($lastActive), strtotime($svc['start_at']))));
            $minutes = max(0, (int)floor((strtotime($endAt) - strtotime($svc['start_at'])) / 60));
            $up = $conn->prepare("UPDATE dispatch_services SET end_at = :e, duration_minutes = :d, auto_close_reason = 'idle_timeout', auto_close_acked = 0, check_pending_since = NULL WHERE id = :id AND end_at IS NULL");
            $up->execute(array(':e' => $endAt, ':d' => $minutes, ':id' => $svc['id']));
            if ($up->rowCount() === 0) continue;
            dpAfkCleanupAgent($conn, (int)$svc['roster_id']);
            dpLog($conn, 'end_service_afk_idle', array('actor' => $svc['discord_id'], 'target_type' => 'service', 'target_id' => $svc['id'],
                'target_label' => $svc['matricule'] . ' | ' . $svc['nom_prenom'],
                'details' => array('credited_until' => $endAt, 'non_credite_min' => $nonCredite)));
            broadcastUpdate('board_changed');
        }
    } catch (Exception $e) { error_log('[dpAfkSweep] ' . $e->getMessage()); }
}

function dpAfkCleanupAgent($conn, $rid) {
    try { $conn->prepare("DELETE FROM dispatch_dispatchers WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (Exception $e) {}
    try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (Exception $e) {}
    try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (Exception $e) {}
    try { $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (Exception $e) {}
    try {
        $patStmt = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
        $patStmt->execute(array(':rid' => $rid));
        $patId = $patStmt->fetchColumn();
        if ($patId) {
            $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $rid));
            $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouille_agents WHERE patrouille_id = :pid");
            $cnt->execute(array(':pid' => $patId));
            if ((int)$cnt->fetchColumn() === 0) {
                $ints = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
                $ints->execute(array(':pid' => $patId));
                $intIds = array_map(function($r) { return $r['intervention_id']; }, $ints->fetchAll());
                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $patId));
                foreach ($intIds as $iid) dpInterUpdateEmpty($conn, $iid);
                try { $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE patrouille_id = :pid")->execute(array(':pid' => $patId)); } catch (Exception $e) {}
                $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $patId));
            } else {
                if (function_exists('recalcIndicatif')) recalcIndicatif($conn, $patId);
            }
        }
    } catch (Exception $e) {}
}

function dpInterUpdateEmpty($conn, $intId) {
    if (!$intId) return;
    try {
        $c = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM dispatch_intervention_patrouilles WHERE intervention_id = :i1) +
            (SELECT COUNT(*) FROM dispatch_intervention_vehicules WHERE intervention_id = :i2 AND (statut IS NULL OR statut = 'en_cours'))");
        $c->execute(array(':i1' => $intId, ':i2' => $intId));
        if ((int)$c->fetchColumn() === 0) {
            $conn->prepare("UPDATE dispatch_interventions SET empty_since = COALESCE(empty_since, NOW()) WHERE id = :id AND statut != 'termine'")
                ->execute(array(':id' => $intId));
        } else {
            $conn->prepare("UPDATE dispatch_interventions SET empty_since = NULL WHERE id = :id")
                ->execute(array(':id' => $intId));
        }
    } catch (Exception $e) {}
}

function dpSweepEmptyInterventions($conn) {
    try {
        $rows = $conn->query("SELECT id FROM dispatch_interventions i
            WHERE i.statut != 'termine'
              AND i.empty_since IS NOT NULL
              AND i.empty_since < (NOW() - INTERVAL 30 SECOND)
              AND NOT EXISTS (SELECT 1 FROM dispatch_intervention_patrouilles ip WHERE ip.intervention_id = i.id)
              AND NOT EXISTS (SELECT 1 FROM dispatch_intervention_vehicules iv WHERE iv.intervention_id = i.id AND (iv.statut IS NULL OR iv.statut = 'en_cours'))
        ")->fetchAll();
        foreach ($rows as $r) {

            $up = $conn->prepare("UPDATE dispatch_interventions
                SET statut = 'termine', closed_at = NOW(), empty_since = NULL,
                    description = CONCAT(COALESCE(description,''), ' | [Code 4 auto - plus aucune unite]')
                WHERE id = :id AND statut != 'termine'
                  AND empty_since IS NOT NULL AND empty_since < (NOW() - INTERVAL 30 SECOND)");
            $up->execute(array(':id' => $r['id']));
            if ($up->rowCount() > 0) {
                dpLog($conn, 'auto_close_intervention', array(
                    'target_type' => 'intervention', 'target_id' => $r['id'],
                    'details' => array('reason' => 'vide_depuis_30s')
                ));
                if (function_exists('broadcastUpdate')) { try { broadcastUpdate('board_changed'); } catch (Exception $e) {} }
            }
        }
    } catch (Exception $e) {}
}

function dpMaybeAutoCloseStale($conn) {
    dpSweepEmptyInterventions($conn);
    dpEnsureLastSeenColumn($conn);
    try {

        $now = time();
        $conn->prepare("INSERT IGNORE INTO dispatch_meta (mk, mv) VALUES ('last_afk_sweep', '0')")->execute();
        $up = $conn->prepare("UPDATE dispatch_meta SET mv = :now WHERE mk = 'last_afk_sweep' AND CAST(mv AS UNSIGNED) < :cutoff");
        $up->execute(array(':now' => (string)$now, ':cutoff' => $now - 90));
        if ($up->rowCount() === 0) return;
    } catch (Exception $e) { return; }
    dpAfkSweep($conn);
    dpAutoCloseStaleServices($conn);
}

function dpAutoCloseStaleServices($conn) {
    $STALE_MINUTES = 120;
    $HARD_CAP_HOURS = 12;
    $ANTI_AFK_HEARTBEAT = false;
    $staleCond = $ANTI_AFK_HEARTBEAT ? "COALESCE(s.last_seen, s.start_at) < (NOW() - INTERVAL $STALE_MINUTES MINUTE) OR " : "";
    try {

        $stale = $conn->query("
            SELECT s.id, s.roster_id, s.start_at, s.last_seen, r.discord_id, r.matricule, r.nom_prenom,
                   TIMESTAMPDIFF(MINUTE, COALESCE(s.last_seen, s.start_at), NOW()) AS idle_min,
                   TIMESTAMPDIFF(HOUR, s.start_at, NOW()) AS dur_hours
            FROM dispatch_services s
            LEFT JOIN roster r ON r.id = s.roster_id
            WHERE s.end_at IS NULL
              AND (
                    $staleCond s.start_at < (NOW() - INTERVAL $HARD_CAP_HOURS HOUR)
                 OR r.id IS NULL
              )
        ")->fetchAll();
    } catch (Exception $e) { return; }

    foreach ($stale as $svc) {
        $now = date('Y-m-d H:i:s');
        $rid = (int)$svc['roster_id'];

        if (empty($svc['matricule']) && empty($svc['nom_prenom'])) {
            $reason = 'orphan_roster';
        } elseif ($svc['dur_hours'] >= $HARD_CAP_HOURS) {
            $reason = 'hard_cap';
        } else {
            $reason = 'no_heartbeat';
        }
        $svcLabel = (!empty($svc['matricule']) || !empty($svc['nom_prenom']))
            ? (($svc['matricule'] ?: '?') . ' | ' . ($svc['nom_prenom'] ?: '?'))
            : ('roster_id ' . $rid . ' (supprime)');

        $start = new DateTime($svc['start_at']);
        if ($reason === 'hard_cap') {
            $effEnd = new DateTime($now);
        } else {
            $effEnd = new DateTime($svc['last_seen'] ?: $svc['start_at']);
        }
        if ($effEnd < $start) $effEnd = clone $start;
        $minutes = max(0, (int)floor(($effEnd->getTimestamp() - $start->getTimestamp()) / 60));
        $effEndStr = $effEnd->format('Y-m-d H:i:s');

        try {
            $conn->beginTransaction();

            $up = $conn->prepare("UPDATE dispatch_services SET end_at = :end, duration_minutes = :dur WHERE id = :id AND end_at IS NULL");
            $up->execute(array(':end' => $effEndStr, ':dur' => $minutes, ':id' => $svc['id']));
            if ($up->rowCount() === 0) { $conn->rollBack(); continue; }

            $conn->prepare("DELETE FROM dispatch_dispatchers WHERE roster_id = :rid")->execute(array(':rid' => $rid));
            try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch (PDOException $e) {}

            $patStmt = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
            $patStmt->execute(array(':rid' => $rid));
            $patId = $patStmt->fetchColumn();
            if ($patId) {
                $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $rid));
                $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouille_agents WHERE patrouille_id = :pid");
                $cnt->execute(array(':pid' => $patId));
                if ((int)$cnt->fetchColumn() === 0) {

                    $ints = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
                    $ints->execute(array(':pid' => $patId));
                    $intIds = array_map(function($r) { return $r['intervention_id']; }, $ints->fetchAll());
                    $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $patId));
                    foreach ($intIds as $iid) dpInterUpdateEmpty($conn, $iid);
                    try { $conn->prepare("DELETE FROM dispatch_poursuite_log WHERE patrouille_id = :pid")->execute(array(':pid' => $patId)); } catch (PDOException $e) {}
                    try { $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE patrouille_id = :pid")->execute(array(':pid' => $patId)); } catch (PDOException $e) {}
                    $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $patId));
                } else {
                    if (function_exists('recalcIndicatif')) recalcIndicatif($conn, $patId);
                }
            }

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('[dpAutoClose] ' . $e->getMessage());
            continue;
        }

        dpLog($conn, 'end_service_afk_server', array(
            'actor' => !empty($svc['discord_id']) ? $svc['discord_id'] : null,
            'target_type' => 'service',
            'target_id' => $svc['id'],
            'target_label' => $svcLabel,
            'details' => array(
                'start_at' => $svc['start_at'],
                'end_at' => $now,
                'last_seen' => $svc['last_seen'],
                'idle_minutes' => (int)$svc['idle_min'],
                'duration_minutes' => $minutes,
                'duration_hours' => round($minutes / 60, 2),
                'patrol_id' => isset($patId) ? $patId : null,
                'reason' => $reason,
                'method' => 'server_auto'
            )
        ));
    }
}

function recalcIndicatif($conn, $patrolId) {
    $p = $conn->prepare("SELECT p.id, p.custom_indicatif, p.patrol_type_id, pt.nom_format
        FROM dispatch_patrouilles p
        LEFT JOIN dispatch_patrol_types pt ON pt.id = p.patrol_type_id
        WHERE p.id = :id");
    $p->execute(array(':id' => $patrolId));
    $patrol = $p->fetch();
    if (!$patrol || $patrol['custom_indicatif']) return;

    static $GRADE_ORDER = array(
        '1210813640987377759',
        '1485642111314432082',
        '1485642087360757991',
        '1210813640987377762',
        '1210813640987377763',
        '1210813641004294225',
        '1370086966628061258',
        '1210813641004294227',
        '1210813641004294228',
        '1210813641004294229',
        '1210813641004294231',
        '1370774891069968416',
        '1370774221634146334',
        '1210813641004294232',
        '1210813641004294233'
    );
    $rankPos = array_flip($GRADE_ORDER);

    $agents = $conn->prepare(
        "SELECT r.matricule, r.ordre, u.discord_roles
         FROM dispatch_patrouille_agents dpa
         JOIN roster r ON r.id = dpa.roster_id
         LEFT JOIN users u ON u.discord_id = r.discord_id
         WHERE dpa.patrouille_id = :pid"
    );
    $agents->execute(array(':pid' => $patrolId));
    $rows = $agents->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) return;

    foreach ($rows as &$row) {
        $best = 999;
        if (!empty($row['discord_roles'])) {
            $roles = json_decode($row['discord_roles'], true);
            if (is_array($roles)) {
                foreach ($roles as $rid) {
                    if (isset($rankPos[$rid]) && $rankPos[$rid] < $best) {
                        $best = $rankPos[$rid];
                    }
                }
            }
        }
        $row['_rank'] = $best;
    }
    unset($row);

    usort($rows, function($a, $b) {
        if ($a['_rank'] !== $b['_rank']) return $a['_rank'] - $b['_rank'];
        $oa = isset($a['ordre']) ? (int)$a['ordre'] : 0;
        $ob = isset($b['ordre']) ? (int)$b['ordre'] : 0;
        if ($oa !== $ob) return $oa - $ob;
        return strcmp($a['matricule'], $b['matricule']);
    });

    $mats = array();
    foreach ($rows as $r) $mats[] = $r['matricule'];

    $count = count($mats);
    $prefix = $patrol['nom_format'];
    if (!$prefix) {

        $map = array(1 => 'LINCOLN', 2 => 'ADAM', 3 => 'TANGO', 4 => 'QUEEN');
        $prefix = isset($map[$count]) ? $map[$count] : 'SQUAD';
    }

    $leader = array_shift($mats);
    $indicatif = $prefix . ' ' . $leader;
    if (!empty($mats)) $indicatif .= ' | ' . $leader . ' + ' . implode(' + ', $mats);
    else $indicatif .= ' | ' . $leader;
    $conn->prepare("UPDATE dispatch_patrouilles SET indicatif = :ind WHERE id = :id")
        ->execute(array(':ind' => $indicatif, ':id' => $patrolId));
}

try {

    if ($action === 'board') {

        dpMaybeAutoCloseStale($conn);

        $statuts = $conn->query("SELECT * FROM dispatch_statuts ORDER BY ordre")->fetchAll();

        $patRows = $conn->query("
            SELECT p.*, GROUP_CONCAT(CONCAT(r.matricule, ' | ', r.nom_prenom) ORDER BY r.ordre SEPARATOR '||') AS agents_list,
                   GROUP_CONCAT(r.id ORDER BY r.ordre SEPARATOR ',') AS agent_ids
            FROM dispatch_patrouilles p
            LEFT JOIN dispatch_patrouille_agents pa ON pa.patrouille_id = p.id
            LEFT JOIN roster r ON r.id = pa.roster_id
            GROUP BY p.id
            ORDER BY p.created_at
        ")->fetchAll();

        $patrouilles = array();
        foreach ($patRows as $row) {
            $row['agents'] = array();
            if ($row['agents_list']) {
                $names = explode('||', $row['agents_list']);
                $ids = explode(',', $row['agent_ids']);
                for ($i = 0; $i < count($names); $i++) {
                    $row['agents'][] = array('id' => (int)$ids[$i], 'label' => $names[$i]);
                }
            }
            unset($row['agents_list'], $row['agent_ids']);
            $patrouilles[] = $row;
        }

        $intRows = $conn->query("
            SELECT i.*, GROUP_CONCAT(ip.patrouille_id SEPARATOR ',') AS patrouille_ids
            FROM dispatch_interventions i
            LEFT JOIN dispatch_intervention_patrouilles ip ON ip.intervention_id = i.id
            WHERE i.statut != 'termine'
            GROUP BY i.id
            ORDER BY FIELD(i.priorite, 'critical', 'high', 'medium', 'low'), i.created_at DESC
        ")->fetchAll();

        $allVehs = array();
        try {
            $vehRows = $conn->query("SELECT dv.*, GROUP_CONCAT(CONCAT(dva.patrouille_id,':', dva.position) SEPARATOR '|') AS assigns FROM dispatch_intervention_vehicules dv JOIN dispatch_interventions di ON di.id = dv.intervention_id AND di.statut != 'termine' LEFT JOIN dispatch_vehicule_assignations dva ON dva.vehicule_id = dv.id GROUP BY dv.id")->fetchAll();
            foreach ($vehRows as $vr) {
                $intId = $vr['intervention_id'];
                $assigns = array();
                if ($vr['assigns']) {
                    foreach (explode('|', $vr['assigns']) as $a) {
                        $parts = explode(':', $a);
                        if (count($parts) === 2) $assigns[] = array('patrol_id' => $parts[0], 'position' => $parts[1]);
                    }
                }
                $vr['assignations'] = $assigns;
                unset($vr['assigns']);
                if (!isset($allVehs[$intId])) $allVehs[$intId] = array();
                $allVehs[$intId][] = $vr;
            }
        } catch (PDOException $e) {}

        $interventions = array();
        foreach ($intRows as $row) {
            $row['patrouilles'] = $row['patrouille_ids'] ? explode(',', $row['patrouille_ids']) : array();
            unset($row['patrouille_ids']);
            $row['vehicules'] = isset($allVehs[$row['id']]) ? $allVehs[$row['id']] : array();
            $interventions[] = $row;
        }

        $patrolTypes = $conn->query("SELECT * FROM dispatch_patrol_types ORDER BY ordre")->fetchAll();

        $actionBtns = $conn->query("SELECT id, code, label, icon, color FROM dispatch_action_buttons ORDER BY ordre")->fetchAll();

        $operations = array();
        $agentOps = array();
        try {
            $operations = $conn->query("SELECT * FROM dispatch_operations ORDER BY ordre")->fetchAll();
            $agentOps = $conn->query("SELECT dao.operation_id, dao.roster_id, r.matricule, r.nom_prenom, r.discord_id FROM dispatch_agent_operations dao JOIN roster r ON r.id = dao.roster_id ORDER BY dao.assigned_at")->fetchAll();
        } catch (PDOException $e) {}

        $wantedPersons = array(); $wantedVehicles = array();
        try {
            $wantedPersons = $conn->query("SELECT * FROM dispatch_wanted_persons ORDER BY created_at DESC")->fetchAll();
            $wantedVehicles = $conn->query("SELECT * FROM dispatch_wanted_vehicles ORDER BY created_at DESC")->fetchAll();
        } catch (PDOException $e) {}

        dpEchoWithEtag(json_encode(array(
            'statuts' => $statuts,
            'patrouilles' => $patrouilles,
            'interventions' => $interventions,
            'patrol_types' => $patrolTypes,
            'action_buttons' => $actionBtns,
            'operations' => $operations,
            'agent_operations' => $agentOps,
            'wanted_persons' => $wantedPersons,
            'wanted_vehicles' => $wantedVehicles
        ), JSON_UNESCAPED_UNICODE));
    }

    elseif ($action === 'save_patrouille') {
        mdt_post_only();
        $data = dp_get_post_data('E-1200');
        if (!$data || empty($data['indicatif'])) {
            mdt_error(400, 'E-1200', 'Indicatif requis');
        }

        $id = !empty($data['id']) ? $data['id'] : 'dp_' . uniqid();
        $isNew = empty($data['id']);
        $agentIds = isset($data['agent_ids']) ? $data['agent_ids'] : array();

        $conn->beginTransaction();

        $ptId = isset($data['patrol_type_id']) ? $data['patrol_type_id'] : null;
        $armement = isset($data['armement']) ? $data['armement'] : null;
        $tph = isset($data['tph']) ? $data['tph'] : null;
        $infoSup = isset($data['info_sup']) ? $data['info_sup'] : null;
        $secteur = isset($data['secteur']) ? $data['secteur'] : 'all';

        if ($isNew) {
            $stmt = $conn->prepare("INSERT INTO dispatch_patrouilles (id, indicatif, vehicule, canal_radio, notes, statut_id, patrol_type_id, custom_indicatif, armement, tph, info_sup, secteur) VALUES (:id, :ind, :veh, :can, :not, :sid, :ptid, :ci, :arm, :tph, :inf, :sec)");
            $stmt->execute(array(
                ':id'  => $id,
                ':ind' => $data['indicatif'],
                ':veh' => isset($data['vehicule']) ? $data['vehicule'] : null,
                ':can' => isset($data['canal_radio']) ? $data['canal_radio'] : null,
                ':not' => isset($data['notes']) ? $data['notes'] : null,
                ':sid' => isset($data['statut_id']) ? $data['statut_id'] : 'ds_disponible',
                ':ptid' => $ptId,
                ':ci' => 1,
                ':arm' => $armement,
                ':tph' => $tph,
                ':inf' => $infoSup,
                ':sec' => $secteur
            ));
        } else {
            $stmt = $conn->prepare("UPDATE dispatch_patrouilles SET indicatif=:ind, vehicule=:veh, canal_radio=:can, notes=:not, patrol_type_id=:ptid, armement=:arm, tph=:tph, info_sup=:inf, secteur=:sec WHERE id=:id");
            $stmt->execute(array(
                ':id'  => $id,
                ':ind' => $data['indicatif'],
                ':veh' => isset($data['vehicule']) ? $data['vehicule'] : null,
                ':can' => isset($data['canal_radio']) ? $data['canal_radio'] : null,
                ':not' => isset($data['notes']) ? $data['notes'] : null,
                ':ptid' => $ptId,
                ':arm' => $armement,
                ':tph' => $tph,
                ':inf' => $infoSup,
                ':sec' => $secteur
            ));
        }

        if (isset($data['agent_ids'])) {
            $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE patrouille_id = :pid")->execute(array(':pid' => $id));
            if (!empty($agentIds)) {
                $ins = $conn->prepare("INSERT INTO dispatch_patrouille_agents (patrouille_id, roster_id) VALUES (:pid, :rid)");
                $delQ = $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid");
                foreach ($agentIds as $rid) {
                    $ins->execute(array(':pid' => $id, ':rid' => (int)$rid));

                    try { $delQ->execute(array(':rid' => (int)$rid)); } catch(PDOException $e) {}
                }
            }
        }

        $conn->commit();

        dpLog($conn, $isNew ? 'create_patrouille' : 'update_patrouille', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'patrouille',
            'target_id' => $id,
            'target_label' => $data['indicatif'],
            'details' => array(
                'agent_count' => is_array($agentIds) ? count($agentIds) : null,
                'patrol_type_id' => $ptId,
                'secteur' => $secteur,
                'vehicule' => isset($data['vehicule']) ? $data['vehicule'] : null,
            )
        ));

        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_patrol_field') {
        mdt_post_only();
        $data = dp_get_post_data('E-1213');
        if (!$data || empty($data['id']) || !isset($data['field'])) {
            mdt_error(400, 'E-1213', 'id et field requis');
        }
        $allowed = array('info_sup','canal_radio','vehicule','armement','tph','notes','secteur','patrol_type_id','indicatif');
        $field = $data['field'];
        if (!in_array($field, $allowed, true)) {
            mdt_error(400, 'E-1213F', 'Champ non autorise');
        }
        $value = isset($data['value']) ? $data['value'] : null;
        if (is_string($value) && $value === '') $value = null;

        $sets = "`$field` = :val";
        if ($field === 'indicatif') {
            $sets .= ", custom_indicatif = 1";
        }

        $prevStmt = $conn->prepare("SELECT `$field` AS oldv, indicatif FROM dispatch_patrouilles WHERE id = :id");
        $prevStmt->execute(array(':id' => $data['id']));
        $prevRow = $prevStmt->fetch();

        $up = $conn->prepare("UPDATE dispatch_patrouilles SET $sets WHERE id = :id");
        $up->execute(array(':val' => $value, ':id' => $data['id']));

        dpLog($conn, 'update_patrol_field', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'patrouille',
            'target_id' => $data['id'],
            'target_label' => $prevRow ? $prevRow['indicatif'] : null,
            'details' => array(
                'field' => $field,
                'from' => $prevRow ? $prevRow['oldv'] : null,
                'to' => $value,
            )
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_patrouille') {
        mdt_post_only();
        $data = dp_get_post_data('E-1201');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1201', 'ID requis');

        $prevStmt = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
        $prevStmt->execute(array(':id' => $data['id']));
        $prevIndicatif = $prevStmt->fetchColumn();

        $conn->beginTransaction();
        $delInts = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :id");
        $delInts->execute(array(':id' => $data['id']));
        $delIntIds = array_map(function($r) { return $r['intervention_id']; }, $delInts->fetchAll());
        $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE patrouille_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :id")->execute(array(':id' => $data['id']));
        foreach ($delIntIds as $iid) dpInterUpdateEmpty($conn, $iid);
        $conn->commit();

        dpLog($conn, 'delete_patrouille', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'patrouille',
            'target_id' => $data['id'],
            'target_label' => $prevIndicatif ?: null
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'join_patrol') {
        mdt_post_only();
        $data = dp_get_post_data('E-1210');
        if (!$data || empty($data['patrol_id']) || empty($data['roster_id'])) {
            mdt_error(400, 'E-1210', 'patrol_id et roster_id requis');
        }
        $pid = $data['patrol_id'];
        $rid = (int)$data['roster_id'];

        $check = $conn->prepare("SELECT id FROM dispatch_patrouilles WHERE id = :id");
        $check->execute(array(':id' => $pid));
        if (!$check->fetch()) mdt_error(404, 'E-1211', 'Patrouille introuvable');

        $oldPat = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
        $oldPat->execute(array(':rid' => $rid));
        $oldPatId = $oldPat->fetchColumn();
        $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $rid));
        if ($oldPatId && $oldPatId !== $pid) recalcIndicatif($conn, $oldPatId);

        $conn->prepare("INSERT INTO dispatch_patrouille_agents (patrouille_id, roster_id) VALUES (:pid, :rid)")
            ->execute(array(':pid' => $pid, ':rid' => $rid));

        try {
            $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $rid));
        } catch(PDOException $e) {}

        recalcIndicatif($conn, $pid);

        $pInfo = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
        $pInfo->execute(array(':id' => $pid));
        $pIndicatif = $pInfo->fetchColumn();
        $logActor = isset($data['discord_id']) && $data['discord_id'] ? $data['discord_id'] : $rid;
        dpLog($conn, 'join_patrol', array(
            'actor' => $logActor,
            'target_type' => 'patrouille',
            'target_id' => $pid,
            'target_label' => $pIndicatif ?: null,
            'details' => array('roster_id' => $rid, 'from_patrol' => $oldPatId)
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'leave_patrol') {
        mdt_post_only();
        $data = dp_get_post_data('E-1212');
        if (!$data || empty($data['roster_id'])) {
            mdt_error(400, 'E-1212', 'roster_id requis');
        }
        $rid = (int)$data['roster_id'];

        $pat = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
        $pat->execute(array(':rid' => $rid));
        $patId = $pat->fetchColumn();

        $patIndicatif = null;
        if ($patId) {
            $pInfo = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
            $pInfo->execute(array(':id' => $patId));
            $patIndicatif = $pInfo->fetchColumn() ?: null;
        }

        $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $rid));

        if ($patId) recalcIndicatif($conn, $patId);

        if ($patId) {
            $logActor = isset($data['discord_id']) && $data['discord_id'] ? $data['discord_id'] : $rid;
            dpLog($conn, 'leave_patrol', array(
                'actor' => $logActor,
                'target_type' => 'patrouille',
                'target_id' => $patId,
                'target_label' => $patIndicatif,
                'details' => array('roster_id' => $rid)
            ));
        }

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'move_patrouille') {
        mdt_post_only();
        $data = dp_get_post_data('E-1202');
        if (!$data || empty($data['id']) || empty($data['statut_id'])) {
            mdt_error(400, 'E-1202', 'ID et statut_id requis');
        }

        $prevStmt = $conn->prepare("SELECT indicatif, statut_id FROM dispatch_patrouilles WHERE id = :id");
        $prevStmt->execute(array(':id' => $data['id']));
        $prevRow = $prevStmt->fetch();

        $conn->beginTransaction();

        $stmt = $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = :sid, statut_since = NOW() WHERE id = :id");
        $stmt->execute(array(':sid' => $data['statut_id'], ':id' => $data['id']));

        if (!empty($data['intervention_id']) && !empty($data['intervention_action'])) {
            $intId = $data['intervention_id'];
            $intAction = $data['intervention_action'];

            if ($intAction === 'close') {

                $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW() WHERE id = :id")
                    ->execute(array(':id' => $intId));
                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :id")
                    ->execute(array(':id' => $intId));
            } elseif ($intAction === 'remove') {

                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :iid AND patrouille_id = :pid")
                    ->execute(array(':iid' => $intId, ':pid' => $data['id']));
                dpInterUpdateEmpty($conn, $intId);
            }

        }

        $conn->commit();

        dpLog($conn, 'move_patrouille', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'patrouille',
            'target_id' => $data['id'],
            'target_label' => $prevRow ? $prevRow['indicatif'] : null,
            'details' => array(
                'from_statut' => $prevRow ? $prevRow['statut_id'] : null,
                'to_statut' => $data['statut_id'],
                'intervention_id' => isset($data['intervention_id']) ? $data['intervention_id'] : null,
                'intervention_action' => isset($data['intervention_action']) ? $data['intervention_action'] : null,
            )
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_intervention') {
        mdt_post_only();
        $data = dp_get_post_data('E-1203');
        if (!$data || empty($data['type'])) {
            mdt_error(400, 'E-1203', 'Type d\'intervention requis');
        }

        $id = !empty($data['id']) ? $data['id'] : 'di_' . uniqid();
        $isNew = empty($data['id']);

        if ($isNew) {
            $stmt = $conn->prepare("INSERT INTO dispatch_interventions (id, type, lieu, priorite, description, statut) VALUES (:id, :typ, :lieu, :pri, :desc, :sta)");
            $stmt->execute(array(
                ':id'   => $id,
                ':typ'  => $data['type'],
                ':lieu' => isset($data['lieu']) ? $data['lieu'] : null,
                ':pri'  => isset($data['priorite']) ? $data['priorite'] : 'medium',
                ':desc' => isset($data['description']) ? $data['description'] : null,
                ':sta'  => isset($data['statut']) ? $data['statut'] : 'nouveau'
            ));
        } else {
            $stmt = $conn->prepare("UPDATE dispatch_interventions SET type=:typ, lieu=:lieu, priorite=:pri, description=:desc, statut=:sta WHERE id=:id");
            $stmt->execute(array(
                ':id'   => $id,
                ':typ'  => $data['type'],
                ':lieu' => isset($data['lieu']) ? $data['lieu'] : null,
                ':pri'  => isset($data['priorite']) ? $data['priorite'] : 'medium',
                ':desc' => isset($data['description']) ? $data['description'] : null,
                ':sta'  => isset($data['statut']) ? $data['statut'] : 'nouveau'
            ));
        }

        dpLog($conn, $isNew ? 'create_intervention' : 'update_intervention', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'intervention',
            'target_id' => $id,
            'target_label' => trim($data['type'] . ' - ' . (isset($data['lieu']) ? $data['lieu'] : '')),
            'details' => array(
                'priorite' => isset($data['priorite']) ? $data['priorite'] : null,
                'statut' => isset($data['statut']) ? $data['statut'] : null,
            )
        ));

        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_intervention') {
        mdt_post_only();
        $data = dp_get_post_data('E-1204');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1204', 'ID requis');

        $iStmt = $conn->prepare("SELECT type, lieu FROM dispatch_interventions WHERE id = :id");
        $iStmt->execute(array(':id' => $data['id']));
        $iRow = $iStmt->fetch();

        $conn->beginTransaction();
        $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM dispatch_interventions WHERE id = :id")->execute(array(':id' => $data['id']));
        $conn->commit();

        dpLog($conn, 'delete_intervention', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'intervention',
            'target_id' => $data['id'],
            'target_label' => $iRow ? trim(($iRow['type'] ?: '') . ' - ' . ($iRow['lieu'] ?: '')) : null,
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_intervention_quick') {
        mdt_post_only();
        $data = dp_get_post_data('E-1208');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1208', 'ID requis');
        $sets = array(); $params = array(':id' => $data['id']);
        if (isset($data['canal_radio'])) { $sets[] = 'canal_radio = :cr'; $params[':cr'] = $data['canal_radio']; }
        if (isset($data['lieu'])) { $sets[] = 'lieu = :lieu'; $params[':lieu'] = $data['lieu']; }
        if (isset($data['description'])) { $sets[] = 'description = :desc'; $params[':desc'] = $data['description']; }
        if (empty($sets)) mdt_error(400, 'E-1208', 'Rien a modifier');
        $conn->prepare("UPDATE dispatch_interventions SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'close_intervention') {
        mdt_post_only();
        $data = dp_get_post_data('E-1205');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1205', 'ID requis');

        $iStmt = $conn->prepare("SELECT type, lieu FROM dispatch_interventions WHERE id = :id");
        $iStmt->execute(array(':id' => $data['id']));
        $iRow = $iStmt->fetch();

        $stmt = $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW() WHERE id = :id");
        $stmt->execute(array(':id' => $data['id']));

        $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :id")->execute(array(':id' => $data['id']));

        dpLog($conn, 'close_intervention', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'intervention',
            'target_id' => $data['id'],
            'target_label' => $iRow ? trim(($iRow['type'] ?: '') . ' - ' . ($iRow['lieu'] ?: '')) : null,
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'assign_patrouille') {
        mdt_post_only();
        $data = dp_get_post_data('E-1206');
        if (!$data || empty($data['intervention_id']) || empty($data['patrouille_id'])) {
            mdt_error(400, 'E-1206', 'intervention_id et patrouille_id requis');
        }

        $conn->prepare("UPDATE dispatch_interventions SET empty_since = NULL WHERE id = :id")->execute(array(':id' => $data['intervention_id']));
        $stmt = $conn->prepare("INSERT IGNORE INTO dispatch_intervention_patrouilles (intervention_id, patrouille_id) VALUES (:iid, :pid)");
        $stmt->execute(array(':iid' => $data['intervention_id'], ':pid' => $data['patrouille_id']));

        $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_intervention', statut_since = NOW() WHERE id = :pid AND statut_id != 'ds_intervention'")
            ->execute(array(':pid' => $data['patrouille_id']));

        $pInfo = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
        $pInfo->execute(array(':id' => $data['patrouille_id']));
        $pIndicatif = $pInfo->fetchColumn();
        dpLog($conn, 'assign_patrouille', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'intervention',
            'target_id' => $data['intervention_id'],
            'target_label' => $pIndicatif,
            'details' => array('patrouille_id' => $data['patrouille_id'])
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'unassign_patrouille') {
        mdt_post_only();
        $data = dp_get_post_data('E-1207');
        if (!$data || empty($data['intervention_id']) || empty($data['patrouille_id'])) {
            mdt_error(400, 'E-1207', 'intervention_id et patrouille_id requis');
        }

        $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :iid AND patrouille_id = :pid")
            ->execute(array(':iid' => $data['intervention_id'], ':pid' => $data['patrouille_id']));
        dpInterUpdateEmpty($conn, $data['intervention_id']);

        $remaining = $conn->prepare("SELECT COUNT(*) FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
        $remaining->execute(array(':pid' => $data['patrouille_id']));
        if ((int)$remaining->fetchColumn() === 0) {
            $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_disponible', statut_since = NOW() WHERE id = :pid AND statut_id = 'ds_intervention'")
                ->execute(array(':pid' => $data['patrouille_id']));
        }

        $pInfo = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
        $pInfo->execute(array(':id' => $data['patrouille_id']));
        $pIndicatif = $pInfo->fetchColumn();
        dpLog($conn, 'unassign_patrouille', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'intervention',
            'target_id' => $data['intervention_id'],
            'target_label' => $pIndicatif,
            'details' => array('patrouille_id' => $data['patrouille_id'])
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_statuts') {
        $rows = $conn->query("SELECT * FROM dispatch_statuts ORDER BY ordre")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_statut') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1208');
        if (!$data || empty($data['label'])) mdt_error(400, 'E-1208', 'Label requis');

        $id = !empty($data['id']) ? $data['id'] : 'ds_' . uniqid();
        $stmt = $conn->prepare("INSERT INTO dispatch_statuts (id, label, couleur, ordre) VALUES (:id, :lab, :col, :ord) ON DUPLICATE KEY UPDATE label=VALUES(label), couleur=VALUES(couleur), ordre=VALUES(ordre)");
        $stmt->execute(array(
            ':id'  => $id,
            ':lab' => $data['label'],
            ':col' => isset($data['couleur']) ? $data['couleur'] : '#3b82f6',
            ':ord' => isset($data['ordre']) ? (int)$data['ordre'] : 99
        ));

        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_statut') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1209');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1209', 'ID requis');

        $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouilles WHERE statut_id = :id");
        $cnt->execute(array(':id' => $data['id']));
        if ((int)$cnt->fetchColumn() > 0) {
            mdt_error(400, 'E-1210', 'Impossible: des patrouilles utilisent ce statut');
        }

        $conn->prepare("DELETE FROM dispatch_statuts WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'reorder_statuts') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        mdt_post_only();
        $data = dp_get_post_data('E-1211');
        if (!$data || !is_array($data)) mdt_error(400, 'E-1211', 'Tableau attendu');

        $stmt = $conn->prepare("UPDATE dispatch_statuts SET ordre = :o WHERE id = :id");
        foreach ($data as $item) {
            if (isset($item['id']) && isset($item['ordre'])) {
                $stmt->execute(array(':id' => $item['id'], ':o' => (int)$item['ordre']));
            }
        }
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_vehicules') {
        $rows = $conn->query("SELECT * FROM dispatch_vehicules ORDER BY ordre, label")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_vehicule') {
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1212');
        if (!$data || empty($data['label'])) mdt_error(400, 'E-1212', 'Label requis');

        $id = !empty($data['id']) ? $data['id'] : 'dv_' . uniqid();
        $stmt = $conn->prepare("INSERT INTO dispatch_vehicules (id, label, plaque, type, ordre) VALUES (:id, :lab, :plq, :typ, :ord) ON DUPLICATE KEY UPDATE label=VALUES(label), plaque=VALUES(plaque), type=VALUES(type), ordre=VALUES(ordre)");
        $stmt->execute(array(
            ':id'  => $id,
            ':lab' => $data['label'],
            ':plq' => isset($data['plaque']) ? $data['plaque'] : null,
            ':typ' => isset($data['type']) ? $data['type'] : 'Sedan',
            ':ord' => isset($data['ordre']) ? (int)$data['ordre'] : 0
        ));

        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_vehicule') {
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1213');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1213', 'ID requis');

        $conn->prepare("DELETE FROM dispatch_vehicules WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'all_interventions') {

        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
        $rows = $conn->query("
            SELECT i.*, GROUP_CONCAT(ip.patrouille_id SEPARATOR ',') AS patrouille_ids
            FROM dispatch_interventions i
            LEFT JOIN dispatch_intervention_patrouilles ip ON ip.intervention_id = i.id
            GROUP BY i.id
            ORDER BY i.created_at DESC
            LIMIT $limit OFFSET $offset
        ")->fetchAll();

        $result = array();
        foreach ($rows as $row) {
            $row['patrouilles'] = $row['patrouille_ids'] ? explode(',', $row['patrouille_ids']) : array();
            unset($row['patrouille_ids']);
            $result[] = $row;
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'heartbeat') {
        $discordId = isset($_GET['discord_id']) ? $_GET['discord_id'] : (isset($_POST['discord_id']) ? $_POST['discord_id'] : '');

        $hbActive = !empty($_GET['active']) && !empty($GLOBALS['DP_SELF_DID']);
        if ($discordId) dpHeartbeat($conn, $discordId, $hbActive);

        dpMaybeAutoCloseStale($conn);
        $resp = array('ok' => true);

        $P = dpAfkParams($conn);
        if ($P['mode'] === 'enforce' && $discordId) {
            try {
                $cq = $conn->prepare("SELECT s.check_pending_since FROM dispatch_services s JOIN roster r ON r.id = s.roster_id WHERE r.discord_id = :d AND s.end_at IS NULL LIMIT 1");
                $cq->execute(array(':d' => $discordId));
                $cps = $cq->fetchColumn();
                if ($cps) {
                    $deadline = strtotime($cps) + $P['window_min'] * 60;
                    $resp['afk'] = array('pending' => 1, 'remaining_s' => max(0, $deadline - time()));
                }
            } catch (Exception $e) {}
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'widget_state') {
        $discordId = isset($_GET['discord_id']) ? $_GET['discord_id'] : '';
        if (!$discordId) { echo json_encode(array('has_roster' => false)); exit; }

        dpHeartbeat($conn, $discordId);

        dpMaybeAutoCloseStale($conn);

        $r = $conn->prepare("SELECT id, matricule, nom_prenom FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $discordId));
        $roster = $r->fetch();
        if (!$roster) { echo json_encode(array('has_roster' => false)); exit; }

        $s = $conn->prepare("SELECT * FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL LIMIT 1");
        $s->execute(array(':rid' => $roster['id']));
        $service = $s->fetch();

        $p = $conn->prepare("SELECT dp.id, dp.indicatif, dp.statut_id, dp.vehicule, dp.canal_radio FROM dispatch_patrouille_agents dpa JOIN dispatch_patrouilles dp ON dp.id = dpa.patrouille_id WHERE dpa.roster_id = :rid LIMIT 1");
        $p->execute(array(':rid' => $roster['id']));
        $patrol = $p->fetch();

        $intervention = null;
        if ($patrol) {
            $iv = $conn->prepare("SELECT di.* FROM dispatch_intervention_patrouilles dip JOIN dispatch_interventions di ON di.id = dip.intervention_id WHERE dip.patrouille_id = :pid AND di.statut != 'termine' LIMIT 1");
            $iv->execute(array(':pid' => $patrol['id']));
            $intervention = $iv->fetch() ?: null;
        }

        $btns = $conn->query("SELECT * FROM dispatch_action_buttons ORDER BY ordre")->fetchAll();
        foreach ($btns as &$btn) {
            $btn['fields_config'] = $btn['fields_config'] ? json_decode($btn['fields_config'], true) : null;
            $btn['is_reset'] = (int)$btn['is_reset'];
        }

        $vehAssigns = array();
        try {
            if ($patrol) {
                $va = $conn->prepare("SELECT dva.position, dv.id AS vehicule_id, dv.modele, dv.couleur, dv.immat, dv.nb_personnes, dv.armes, dv.photo_url, COALESCE(dv.statut,'en_cours') AS veh_statut, di.type AS inter_type, di.lieu FROM dispatch_vehicule_assignations dva JOIN dispatch_intervention_vehicules dv ON dv.id = dva.vehicule_id JOIN dispatch_interventions di ON di.id = dv.intervention_id WHERE dva.patrouille_id = :pid AND di.statut != 'termine' AND (dv.statut IS NULL OR dv.statut = 'en_cours')");
                $va->execute(array(':pid' => $patrol['id']));
                $vehAssigns = $va->fetchAll();
            }
        } catch (PDOException $e) { $vehAssigns = array(); }

        $activePursuit = null;
        try {
            if ($patrol) {
                $ap = $conn->prepare("SELECT pl.*, dv.modele, dv.couleur, dv.immat, dv.nb_personnes, dv.armes, dv.photo_url, di.type AS inter_type, di.lieu, di.created_at AS inter_created_at, di.action_button_id
                    FROM dispatch_poursuite_log pl
                    JOIN dispatch_intervention_vehicules dv ON dv.id = pl.vehicule_id
                    JOIN dispatch_interventions di ON di.id = pl.intervention_id
                    WHERE pl.patrouille_id = :pid AND pl.fin_at IS NULL
                    ORDER BY pl.id DESC LIMIT 1");
                $ap->execute(array(':pid' => $patrol['id']));
                $activePursuit = $ap->fetch() ?: null;
            }
        } catch (PDOException $e) {}

        $wsOut = array(
            'has_roster' => true,
            'can_tacmap' => mdt_module_access($conn, 'dispatch', $discordId) ? true : false,
            'roster' => $roster,
            'in_service' => $service ? true : false,
            'service' => $service ?: null,
            'patrol' => $patrol ?: null,
            'intervention' => $intervention,
            'buttons' => $btns,
            'vehicle_assignments' => $vehAssigns,
            'active_pursuit' => $activePursuit
        );

        $P = dpAfkParams($conn);
        try {
            if ($service && $P['mode'] === 'enforce' && !empty($service['check_pending_since'])) {
                $deadline = strtotime($service['check_pending_since']) + $P['window_min'] * 60;
                $wsOut['afk'] = array('pending' => 1, 'remaining_s' => max(0, $deadline - time()));
            } elseif (!$service) {
                $lq = $conn->prepare("SELECT id, end_at, auto_close_reason, duration_minutes FROM dispatch_services WHERE roster_id = :rid AND auto_close_reason IS NOT NULL AND auto_close_acked = 0 AND end_at > NOW() - INTERVAL 24 HOUR ORDER BY end_at DESC LIMIT 1");
                $lq->execute(array(':rid' => $roster['id']));
                $closed = $lq->fetch();
                if ($closed) {
                    $wsOut['afk_ended'] = array(
                        'service_id' => (int)$closed['id'],
                        'reason' => $closed['auto_close_reason'],
                        'end_at' => $closed['end_at'],
                        'credited_min' => (int)$closed['duration_minutes'],
                        'resumable' => (time() - strtotime($closed['end_at'])) <= $P['resume_min'] * 60,
                    );
                }
            }
        } catch (Exception $e) {}
        echo json_encode($wsOut, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'check_service') {
        $discordId = isset($_GET['discord_id']) ? $_GET['discord_id'] : '';
        if (!$discordId) {
            echo json_encode(array('has_roster' => false));
            exit;
        }

        $r = $conn->prepare("SELECT id, matricule, nom_prenom FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $discordId));
        $roster = $r->fetch();

        if (!$roster) {
            echo json_encode(array('has_roster' => false));
            exit;
        }

        $s = $conn->prepare("SELECT * FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL LIMIT 1");
        $s->execute(array(':rid' => $roster['id']));
        $service = $s->fetch();

        $p = $conn->prepare("
            SELECT dp.id, dp.indicatif, dp.statut_id, dp.vehicule, dp.canal_radio
            FROM dispatch_patrouille_agents dpa
            JOIN dispatch_patrouilles dp ON dp.id = dpa.patrouille_id
            WHERE dpa.roster_id = :rid
            LIMIT 1
        ");
        $p->execute(array(':rid' => $roster['id']));
        $patrol = $p->fetch();

        $intervention = null;
        if ($patrol) {
            $iv = $conn->prepare("
                SELECT di.* FROM dispatch_intervention_patrouilles dip
                JOIN dispatch_interventions di ON di.id = dip.intervention_id
                WHERE dip.patrouille_id = :pid AND di.statut != 'termine'
                LIMIT 1
            ");
            $iv->execute(array(':pid' => $patrol['id']));
            $intervention = $iv->fetch() ?: null;
        }

        echo json_encode(array(
            'has_roster' => true,
            'roster' => $roster,
            'in_service' => $service ? true : false,
            'service' => $service ?: null,
            'patrol' => $patrol ?: null,
            'intervention' => $intervention
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'start_service') {
        mdt_post_only();
        if (empty($GLOBALS['DP_SELF_DID'])) mdt_error(401, 'E-DP-401', 'Session requise (anti-usurpation)');
        $data = dp_get_post_data('E-1220');
        $discordId = isset($data['discord_id']) ? $data['discord_id'] : '';
        if (!$discordId) mdt_error(400, 'E-1220', 'discord_id requis');

        $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $discordId));
        $roster = $r->fetch();
        if (!$roster) mdt_error(404, 'E-1221', 'Aucun agent lie a ce Discord');

        $u = $conn->prepare("SELECT id FROM users WHERE discord_id = :did");
        $u->execute(array(':did' => $discordId));
        $user = $u->fetch();
        if (!$user) mdt_error(404, 'E-1222', 'Aucun compte utilisateur lie');

        $check = $conn->prepare("SELECT id FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL");
        $check->execute(array(':rid' => $roster['id']));
        if ($check->fetch()) mdt_error(409, 'E-1223', 'Deja en service');

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        dpEnsureLastSeenColumn($conn);
        $stmt = $conn->prepare("INSERT INTO dispatch_services (user_id, roster_id, start_at, service_date, last_seen) VALUES (:uid, :rid, :start, :date, :start2)");
        $stmt->execute(array(':uid' => $user['id'], ':rid' => $roster['id'], ':start' => $now, ':date' => $today, ':start2' => $now));
        $svcId = $conn->lastInsertId();

        $rinfo = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE id = :id");
        $rinfo->execute(array(':id' => $roster['id']));
        $rinfoRow = $rinfo->fetch();
        $heurePrise = date('H:i', strtotime($now));

        dpLog($conn, 'start_service', array(
            'actor' => $discordId,
            'target_type' => 'service',
            'target_id' => $svcId,
            'target_label' => $rinfoRow ? ($rinfoRow['matricule'] . ' | ' . $rinfoRow['nom_prenom'] . ' a pris son service a ' . $heurePrise) : null,
            'details' => array(
                'start_at' => $now,
                'heure_prise' => $heurePrise,
                'service_date' => $today,
                'roster_id' => $roster['id']
            )
        ));

        echo json_encode(array('success' => true, 'service_id' => $conn->lastInsertId()), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'end_service') {
        mdt_post_only();
        if (empty($GLOBALS['DP_SELF_DID'])) mdt_error(401, 'E-DP-401', 'Session requise (anti-usurpation)');
        $data = dp_get_post_data('E-1225');
        $discordId = isset($data['discord_id']) ? $data['discord_id'] : '';
        if (!$discordId) mdt_error(400, 'E-1225', 'discord_id requis');

        $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $discordId));
        $roster = $r->fetch();
        if (!$roster) mdt_error(404, 'E-1226', 'Aucun agent lie');

        $svc = $conn->prepare("SELECT id, start_at FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL LIMIT 1");
        $svc->execute(array(':rid' => $roster['id']));
        $service = $svc->fetch();
        if (!$service) mdt_error(404, 'E-1227', 'Pas en service');

        $now = date('Y-m-d H:i:s');
        $start = new DateTime($service['start_at']);
        $end = new DateTime($now);
        $minutes = max(0, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60));

        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE dispatch_services SET end_at = :end, duration_minutes = :dur WHERE id = :id")
                ->execute(array(':end' => $now, ':dur' => $minutes, ':id' => $service['id']));

            $conn->prepare("DELETE FROM dispatch_dispatchers WHERE roster_id = :rid")->execute(array(':rid' => $roster['id']));
            try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}

            $pat = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
            $pat->execute(array(':rid' => $roster['id']));
            $patId = $pat->fetchColumn();
            if ($patId) {
                $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $roster['id']));
                $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouille_agents WHERE patrouille_id = :pid");
                $cnt->execute(array(':pid' => $patId));
                if ((int)$cnt->fetchColumn() === 0) {

                    $ints = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
                    $ints->execute(array(':pid' => $patId));
                    foreach ($ints->fetchAll() as $intRow) {
                        $iid = $intRow['intervention_id'];
                        $hasVeh = $conn->prepare("SELECT COUNT(*) FROM dispatch_intervention_vehicules WHERE intervention_id = :iid AND (statut IS NULL OR statut = 'en_cours')");
                        $hasVeh->execute(array(':iid' => $iid));
                        if ((int)$hasVeh->fetchColumn() > 0) continue;
                        if (DP_AUTO_CLOSE_INTERVENTIONS) $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW(), description = CONCAT(COALESCE(description,''), ' | [Code 4 auto - fin de service]') WHERE id = :id AND statut != 'termine'")
                            ->execute(array(':id' => $iid));
                    }
                    $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $patId));
                    try { $conn->prepare("DELETE FROM dispatch_poursuite_log WHERE patrouille_id = :pid")->execute(array(':pid' => $patId)); } catch (PDOException $e) {}
                    try { $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE patrouille_id = :pid")->execute(array(':pid' => $patId)); } catch (PDOException $e) {}
                    $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $patId));
                } else {
                    if (function_exists('recalcIndicatif')) recalcIndicatif($conn, $patId);
                }
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();

            mdt_error(500, 'E-1228X', 'Erreur lors de la fin de service', 'fin_service: ' . $e->getMessage());
        }

        $isAutoAfk = !empty($data['auto_afk']) || !empty($data['reason']) && $data['reason'] === 'afk';

        $svcInfo = $conn->prepare("SELECT service_date FROM dispatch_services WHERE id = :id");
        $svcInfo->execute(array(':id' => $service['id']));
        $svcDateRow = $svcInfo->fetch();
        dpLog($conn, $isAutoAfk ? 'end_service_afk' : 'end_service', array(
            'actor' => $discordId,
            'target_type' => 'service',
            'target_id' => $service['id'],
            'details' => array(
                'start_at' => $service['start_at'],
                'end_at' => $now,
                'service_date' => $svcDateRow ? $svcDateRow['service_date'] : null,
                'duration_minutes' => $minutes,
                'duration_hours' => round($minutes / 60, 2),
                'patrol_id' => $patId ?: null,
                'reason' => isset($data['reason']) ? $data['reason'] : null,
            )
        ));

        echo json_encode(array('success' => true, 'duration_minutes' => $minutes), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'force_end_service') {
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1228');
        if (!$data || empty($data['roster_id'])) mdt_error(400, 'E-1228', 'roster_id requis');
        $rid = (int)$data['roster_id'];

        $svc = $conn->prepare("SELECT id, start_at FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL LIMIT 1");
        $svc->execute(array(':rid' => $rid));
        $service = $svc->fetch();
        if (!$service) mdt_error(404, 'E-1229', 'Agent pas en service');

        $now = date('Y-m-d H:i:s');
        $start = new DateTime($service['start_at']);
        $end = new DateTime($now);
        $minutes = max(0, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60));

        $conn->beginTransaction();
        $conn->prepare("UPDATE dispatch_services SET end_at = :end, duration_minutes = :dur WHERE id = :id")
            ->execute(array(':end' => $now, ':dur' => $minutes, ':id' => $service['id']));

        $pat = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
        $pat->execute(array(':rid' => $rid));
        $patRow = $pat->fetch();
        $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $rid));
        if ($patRow) {
            $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouille_agents WHERE patrouille_id = :pid");
            $cnt->execute(array(':pid' => $patRow['patrouille_id']));
            if ((int)$cnt->fetchColumn() === 0) {
                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $patRow['patrouille_id']));
                $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $patRow['patrouille_id']));
            } else {
                recalcIndicatif($conn, $patRow['patrouille_id']);
            }
        }

        try { $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch(PDOException $e) {}

        try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch(PDOException $e) {}
        try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch(PDOException $e) {}

        try { $conn->prepare("DELETE FROM dispatch_dispatchers WHERE roster_id = :rid")->execute(array(':rid' => $rid)); } catch(PDOException $e) {}

        $conn->commit();

        $agentName = '';
        $ag = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE id = :rid");
        $ag->execute(array(':rid' => $rid));
        $agRow = $ag->fetch();
        if ($agRow) $agentName = $agRow['matricule'] . ' | ' . $agRow['nom_prenom'];

        $svcInfo2 = $conn->prepare("SELECT service_date FROM dispatch_services WHERE id = :id");
        $svcInfo2->execute(array(':id' => $service['id']));
        $svcDateRow2 = $svcInfo2->fetch();
        dpLog($conn, 'force_end_service', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'agent',
            'target_id' => $rid,
            'target_label' => $agentName,
            'details' => array(
                'start_at' => $service['start_at'],
                'end_at' => $now,
                'service_date' => $svcDateRow2 ? $svcDateRow2['service_date'] : null,
                'duration_minutes' => $minutes,
                'duration_hours' => round($minutes / 60, 2),
                'patrol_id' => $patRow ? $patRow['patrouille_id'] : null,
            )
        ));

        $avRang = 1; $avSanction = 0;
        try {
            $avMotif = mb_substr(trim((string)(isset($data['motif']) ? $data['motif'] : '')), 0, 255, 'UTF-8');
            if ($avMotif === '') $avMotif = 'Service non retire en fin de session';
            $avSem = date('Y-m-d', strtotime('monday this week'));

            $agq = $conn->prepare("SELECT matricule, nom_prenom, discord_id FROM roster WHERE id = :i");
            $agq->execute(array(':i' => $rid));
            $agv = $agq->fetch();
            if (!$agv) $agv = array('matricule' => '', 'nom_prenom' => '', 'discord_id' => null);

            $cq = $conn->prepare("SELECT COUNT(*) FROM dispatch_avertos WHERE roster_id = :r AND semaine = :s");
            $cq->execute(array(':r' => $rid, ':s' => $avSem));
            $avRang = ((int)$cq->fetchColumn()) + 1;
            $avSanction = $avRang >= 3 ? 1 : 0;

            $bMat = ''; $bNom = '';
            $bq = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = :d LIMIT 1");
            $bq->execute(array(':d' => $GLOBALS['DP_SELF_DID']));
            $bb = $bq->fetch();
            if ($bb) { $bMat = $bb['matricule']; $bNom = $bb['nom_prenom']; }

            $conn->prepare("INSERT INTO dispatch_avertos
                (roster_id, discord_id, matricule, nom_prenom, semaine, motif, rang, sanction, by_mat, by_name)
                VALUES (:r, :d, :m, :n, :s, :mo, :ra, :sa, :bm, :bn)")
                ->execute(array(':r' => $rid, ':d' => $agv['discord_id'], ':m' => $agv['matricule'],
                    ':n' => $agv['nom_prenom'], ':s' => $avSem, ':mo' => $avMotif,
                    ':ra' => $avRang, ':sa' => $avSanction, ':bm' => $bMat, ':bn' => $bNom));

            if ($avSanction) {
                $conn->prepare("UPDATE dispatch_services SET annule = 1
                                WHERE roster_id = :r AND service_date >= :s AND annule = 0")
                    ->execute(array(':r' => $rid, ':s' => $avSem));
            }
            if ($agv['discord_id']) {
                $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id, urgent)
                                VALUES (:d, 'sanction', :t, :c, '/heures', 'averto', :r, 1)")
                    ->execute(array(':d' => $agv['discord_id'],
                        ':t' => 'Retrait de service force - avertissement ' . $avRang . '/3',
                        ':c' => $avMotif . ($avSanction ? ' - 3e avertissement : tes heures de la semaine sont annulees.' : ''),
                        ':r' => (string)$rid));
            }
        } catch (PDOException $eAv) {
            error_log('[MDT] averto: ' . $eAv->getMessage());
        }

        echo json_encode(array('success' => true, 'duration_minutes' => $minutes, 'agent' => $agentName, 'rang' => $avRang, 'sanction' => $avSanction), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_services') {
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

        $svcIsAdmin = function_exists('isDispatchAdmin') ? isDispatchAdmin($conn, $GLOBALS['DP_SELF_DID']) : false;

        $agents = $conn->query("SELECT r.id, r.matricule, r.nom_prenom, r.discord_id FROM roster r ORDER BY CAST(r.matricule AS UNSIGNED), r.matricule")->fetchAll();

        $active = $conn->query("SELECT roster_id, start_at FROM dispatch_services WHERE end_at IS NULL")->fetchAll();
        $activeMap = array();
        foreach ($active as $a) {
            $activeMap[$a['roster_id']] = $a['start_at'];
        }

        $patAssign = $conn->query("SELECT dpa.roster_id, dp.id as patrol_id, dp.indicatif FROM dispatch_patrouille_agents dpa JOIN dispatch_patrouilles dp ON dp.id = dpa.patrouille_id")->fetchAll();
        $patMap = array();
        foreach ($patAssign as $pa) {
            $patMap[$pa['roster_id']] = array('id' => $pa['patrol_id'], 'indicatif' => $pa['indicatif']);
        }

        $dispatchers = $conn->query("SELECT roster_id, role FROM dispatch_dispatchers")->fetchAll();
        $dispatcherMap = array();
        $supAssignedMap = array();
        foreach ($dispatchers as $d) {

            if ($d['role'] === 'supervision') $supAssignedMap[$d['roster_id']] = true;
            else $dispatcherMap[$d['roster_id']] = $d['role'];
        }

        $GRADE_MAP = mdt_grade_labels($conn);
        $GRADE_ORDER = array_keys($GRADE_MAP);

        $DIV_MAP = array(
            '1210813641302216821' => array('code' => 'NEG', 'label' => 'Negociateur', 'color' => '#10b981'),
            '1210813641176383547' => array('code' => 'CID', 'label' => 'Criminal Investigation', 'color' => '#e91e63'),
            '1210813641176383546' => array('code' => 'SWAT', 'label' => 'Special Weapons', 'color' => '#313131'),
            '1210813641302216820' => array('code' => 'HRT', 'label' => 'Helicopter Response', 'color' => '#94a3b8'),
            '1370017478436065432' => array('code' => 'HBR', 'label' => 'Hubert', 'color' => '#ffffff'),
            '1210813641176383548' => array('code' => 'HP', 'label' => 'Highway Patrol', 'color' => '#94a3b8'),
            '1370017050877100032' => array('code' => 'K9', 'label' => 'Canine Unit', 'color' => '#94a3b8'),
            '1432494299840250037' => array('code' => 'MRD', 'label' => 'Media Relation', 'color' => '#11806a'),
            '1370017619037655060' => array('code' => 'MARY', 'label' => 'Mary (Moto)', 'color' => '#ffffff'),
            '1420186111426297876' => array('code' => 'NPU', 'label' => 'North Patrol Unit', 'color' => '#000000'),
            '1370504638717104199' => array('code' => 'SD', 'label' => 'Scientific Division', 'color' => '#3498db'),
            '1210813641176383545' => array('code' => 'TD', 'label' => 'Training Division', 'color' => '#94a3b8'),
            '1436091416726999182' => array('code' => 'DOJ', 'label' => 'Procuration DOJ', 'color' => '#546e7a')
        );

        $roleMap = array();
        try {
            $usersRoles = $conn->query("SELECT discord_id, discord_roles, telephone FROM users WHERE discord_roles IS NOT NULL")->fetchAll();
            foreach ($usersRoles as $ur) {
                $roles = json_decode($ur['discord_roles'], true);
                if (!is_array($roles)) continue;

                $grade = null;
                foreach ($GRADE_ORDER as $gId) {
                    if (in_array($gId, $roles)) { $grade = $GRADE_MAP[$gId]; break; }
                }

                $divs = array();
                foreach ($DIV_MAP as $dId => $dInfo) {
                    if (in_array($dId, $roles)) $divs[] = $dInfo;
                }
                $roleMap[$ur['discord_id']] = array(
                    'is_supervision' => in_array(MDT_ROLE_SUPERVISION, $roles),
                    'is_etat_major' => in_array(MDT_ROLE_ETAT_MAJOR, $roles),
                    'grade' => $grade,
                    'telephone' => isset($ur['telephone']) ? $ur['telephone'] : null,
                    'divisions' => $divs
                );
            }
        } catch (PDOException $e) {   }

        $pauseMap = array();
        try {
            $pauses = $conn->query("SELECT roster_id, since FROM dispatch_pauses")->fetchAll();
            foreach ($pauses as $pp) { $pauseMap[$pp['roster_id']] = $pp['since']; }
        } catch (PDOException $e) {}

        $queueMap = array();
        try {
            $queues = $conn->query("SELECT roster_id, since FROM dispatch_queue")->fetchAll();
            foreach ($queues as $qq) { $queueMap[$qq['roster_id']] = $qq['since']; }
        } catch (PDOException $e) {}

        $opMap = array();
        try {
            $ops = $conn->query("SELECT dao.roster_id, o.label AS op_label, o.id AS op_id FROM dispatch_agent_operations dao JOIN dispatch_operations o ON o.id = dao.operation_id")->fetchAll();
            foreach ($ops as $op) { $opMap[$op['roster_id']] = array('id' => $op['op_id'], 'label' => $op['op_label']); }
        } catch (PDOException $e) {}

        $result = array();
        foreach ($agents as $ag) {
            $inService = isset($activeMap[$ag['id']]);
            if ($filter === 'active' && !$inService) continue;
            if ($filter === 'inactive' && $inService) continue;

            $pat = isset($patMap[$ag['id']]) ? $patMap[$ag['id']] : null;
            $dRole = isset($dispatcherMap[$ag['id']]) ? $dispatcherMap[$ag['id']] : null;
            $uRoles = ($ag['discord_id'] && isset($roleMap[$ag['discord_id']])) ? $roleMap[$ag['discord_id']] : null;

            $result[] = array(
                'id' => (int)$ag['id'],
                'matricule' => $ag['matricule'],
                'nom_prenom' => $ag['nom_prenom'],
                'discord_id' => $ag['discord_id'],
                'in_service' => $inService,
                'start_at' => $inService ? $activeMap[$ag['id']] : null,
                'patrol_id' => $pat ? $pat['id'] : null,
                'patrol_indicatif' => $pat ? $pat['indicatif'] : null,
                'dispatcher_role' => $dRole,
                'is_sup_assigned' => isset($supAssignedMap[$ag['id']]),
                'is_supervision' => $uRoles ? $uRoles['is_supervision'] : false,
                'is_etat_major' => $uRoles ? $uRoles['is_etat_major'] : false,
                'grade' => $uRoles ? $uRoles['grade'] : null,
                'telephone' => $uRoles ? $uRoles['telephone'] : null,
                'is_paused' => isset($pauseMap[$ag['id']]),
                'pause_since' => isset($pauseMap[$ag['id']]) ? $pauseMap[$ag['id']] : null,
                'in_dispatch_queue' => isset($queueMap[$ag['id']]),
                'queue_since' => isset($queueMap[$ag['id']]) ? $queueMap[$ag['id']] : null,
                'operation_id' => isset($opMap[$ag['id']]) ? $opMap[$ag['id']]['id'] : null,
                'operation_label' => isset($opMap[$ag['id']]) ? $opMap[$ag['id']]['label'] : null,
                'divisions' => $uRoles ? $uRoles['divisions'] : array()
            );
        }

        $unassigned = array();
        try {
            $uq = $conn->query("SELECT u.id, u.discord_id, u.discord_username, u.discord_nick, u.discord_avatar
                FROM users u
                LEFT JOIN roster r ON r.discord_id = u.discord_id
                WHERE u.discord_id IS NOT NULL AND u.discord_id <> '' AND r.id IS NULL");
            foreach ($uq->fetchAll() as $u) {
                $unassigned[] = array(
                    'user_id' => (int)$u['id'],
                    'discord_id' => $u['discord_id'],
                    'discord_username' => $u['discord_username'],
                    'discord_nick' => $u['discord_nick'],
                    'discord_avatar' => $u['discord_avatar']
                );
            }
        } catch (PDOException $e) {}

        dpEchoWithEtag(json_encode(array('agents' => $result, 'unassigned' => $unassigned,

            'grade_order' => array_values($GRADE_MAP)), JSON_UNESCAPED_UNICODE));
    }

    elseif ($action === 'force_end_service') {
        mdt_post_only();
        $d = mdt_get_post_data('E-DP-400');
        $isSup = mdt_is_admin($conn, $GLOBALS['DP_SELF_DID']);
        if (!$isSup) mdt_error(403, 'E-DP-403', 'Reserve a la Supervision et a l Etat-Major');

        $rid = (int)(isset($d['roster_id']) ? $d['roster_id'] : 0);
        $motif = mb_substr(trim((string)(isset($d['motif']) ? $d['motif'] : '')), 0, 255, 'UTF-8');
        if ($motif === '') $motif = 'Service non retire en fin de session';

        $rq = $conn->prepare("SELECT r.id, r.matricule, r.nom_prenom, r.discord_id FROM roster r WHERE r.id = :i");
        $rq->execute(array(':i' => $rid));
        $ag = $rq->fetch();
        if (!$ag) mdt_error(404, 'E-DP-404', 'Agent introuvable');

        $sq = $conn->prepare("SELECT id, start_at, last_active FROM dispatch_services
                              WHERE roster_id = :r AND end_at IS NULL ORDER BY id DESC LIMIT 1");
        $sq->execute(array(':r' => $rid));
        $svc = $sq->fetch();
        if (!$svc) mdt_error(409, 'E-DP-409', 'Cet agent n est pas en service');

        $fin = $svc['last_active'] ?: $svc['start_at'];
        if (strtotime($fin) < strtotime($svc['start_at'])) $fin = $svc['start_at'];
        $min = max(0, (int)floor((strtotime($fin) - strtotime($svc['start_at'])) / 60));
        $conn->prepare("UPDATE dispatch_services SET end_at = :e, duration_minutes = :d,
                        auto_close_reason = 'force_supervision', auto_close_acked = 0,
                        check_pending_since = NULL WHERE id = :i AND end_at IS NULL")
            ->execute(array(':e' => $fin, ':d' => $min, ':i' => (int)$svc['id']));
        dpAfkCleanupAgent($conn, $rid);

        $lundi = date('Y-m-d', strtotime('monday this week'));
        $cq = $conn->prepare("SELECT COUNT(*) FROM dispatch_avertos WHERE roster_id = :r AND semaine = :s");
        $cq->execute(array(':r' => $rid, ':s' => $lundi));
        $rang = ((int)$cq->fetchColumn()) + 1;
        $sanction = $rang >= 3 ? 1 : 0;

        $byMat = ''; $byNom = $GLOBALS['DP_SELF_DID'];
        try {
            $bq = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = :d LIMIT 1");
            $bq->execute(array(':d' => $GLOBALS['DP_SELF_DID']));
            $b = $bq->fetch();
            if ($b) { $byMat = $b['matricule']; $byNom = $b['nom_prenom']; }
        } catch (PDOException $e) {}

        $conn->prepare("INSERT INTO dispatch_avertos
            (roster_id, discord_id, matricule, nom_prenom, semaine, motif, rang, sanction, by_mat, by_name)
            VALUES (:r, :d, :m, :n, :s, :mo, :ra, :sa, :bm, :bn)")
            ->execute(array(':r' => $rid, ':d' => $ag['discord_id'], ':m' => $ag['matricule'],
                            ':n' => $ag['nom_prenom'], ':s' => $lundi, ':mo' => $motif,
                            ':ra' => $rang, ':sa' => $sanction, ':bm' => $byMat, ':bn' => $byNom));

        $annules = 0;
        if ($sanction) {
            $up = $conn->prepare("UPDATE dispatch_services SET annule = 1
                                  WHERE roster_id = :r AND service_date >= :s AND annule = 0");
            $up->execute(array(':r' => $rid, ':s' => $lundi));
            $annules = $up->rowCount();
        }

        try {
            $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id, urgent)
                            VALUES (:d, 'sanction', :t, :c, '/heures', 'averto', :r, 1)")
                ->execute(array(
                    ':d' => $ag['discord_id'],
                    ':t' => 'Retrait de service forcé — avertissement ' . $rang . '/3',
                    ':c' => $motif . ($sanction ? ' — 3e avertissement : tes heures de la semaine sont annulées.' : ''),
                    ':r' => (string)$rid,
                ));
        } catch (PDOException $e) {}

        dpLog($conn, 'force_end_service', array('target_type' => 'service', 'target_id' => (int)$svc['id'],
            'target_label' => $ag['matricule'] . ' | ' . $ag['nom_prenom'],
            'details' => array('rang' => $rang, 'sanction' => $sanction, 'annules' => $annules, 'motif' => $motif)));

        echo json_encode(array('success' => true, 'rang' => $rang, 'sanction' => $sanction,
                               'services_annules' => $annules), JSON_UNESCAPED_UNICODE);
        exit;
    }

    elseif ($action === 'avertos_list') {
        if (!mdt_is_admin($conn, $GLOBALS['DP_SELF_DID'])) mdt_error(403, 'E-DP-403', 'Reserve a la Supervision');
        $sem = isset($_GET['semaine']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semaine'])
             ? $_GET['semaine'] : date('Y-m-d', strtotime('monday this week'));
        $st = $conn->prepare("SELECT a.id, a.roster_id, a.matricule, a.nom_prenom, a.motif, a.rang,
                                     a.sanction, a.by_mat, a.by_name, a.created_at,
                                     (SELECT COUNT(*) FROM dispatch_avertos b
                                      WHERE b.roster_id = a.roster_id AND b.semaine = a.semaine) AS total_semaine
                              FROM dispatch_avertos a WHERE a.semaine = :s
                              ORDER BY a.roster_id, a.id");
        $st->execute(array(':s' => $sem));
        echo json_encode(array('semaine' => $sem, 'avertos' => $st->fetchAll()), JSON_UNESCAPED_UNICODE);
        exit;
    }

    elseif ($action === 'averto_del') {
        mdt_post_only();
        if (!mdt_is_admin($conn, $GLOBALS['DP_SELF_DID'])) mdt_error(403, 'E-DP-403', 'Reserve a la Supervision');
        $d = mdt_get_post_data('E-DP-400');
        $id = (int)(isset($d['id']) ? $d['id'] : 0);
        $aq = $conn->prepare("SELECT roster_id, semaine, matricule, nom_prenom FROM dispatch_avertos WHERE id = :i");
        $aq->execute(array(':i' => $id));
        $a = $aq->fetch();
        if (!$a) mdt_error(404, 'E-DP-404', 'Avertissement introuvable');

        $conn->prepare("DELETE FROM dispatch_avertos WHERE id = :i")->execute(array(':i' => $id));

        $rows = $conn->prepare("SELECT id FROM dispatch_avertos WHERE roster_id = :r AND semaine = :s ORDER BY id");
        $rows->execute(array(':r' => $a['roster_id'], ':s' => $a['semaine']));
        $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
        $up = $conn->prepare("UPDATE dispatch_avertos SET rang = :ra, sanction = :sa WHERE id = :i");
        foreach ($ids as $k => $aid) $up->execute(array(':ra' => $k + 1, ':sa' => ($k + 1) >= 3 ? 1 : 0, ':i' => $aid));

        $restaures = 0;
        if (count($ids) < 3) {
            $r = $conn->prepare("UPDATE dispatch_services SET annule = 0
                                 WHERE roster_id = :r AND service_date >= :s AND annule = 1");
            $r->execute(array(':r' => $a['roster_id'], ':s' => $a['semaine']));
            $restaures = $r->rowCount();
        }

        dpLog($conn, 'averto_retire', array('target_type' => 'averto', 'target_id' => $id,
            'target_label' => $a['matricule'] . ' | ' . $a['nom_prenom'],
            'details' => array('restants' => count($ids), 'services_restaures' => $restaures)));

        echo json_encode(array('success' => true, 'restants' => count($ids),
                               'services_restaures' => $restaures), JSON_UNESCAPED_UNICODE);
        exit;
    }

    elseif ($action === 'mes_avertos') {
        $st = $conn->prepare("SELECT id, motif, rang, sanction, by_mat, by_name, created_at
                              FROM dispatch_avertos WHERE discord_id = :d AND vu = 0 ORDER BY id ASC LIMIT 3");
        $st->execute(array(':d' => $GLOBALS['DP_SELF_DID']));
        echo json_encode(array('avertos' => $st->fetchAll()), JSON_UNESCAPED_UNICODE);
        exit;
    }

    elseif ($action === 'averto_vu') {
        mdt_post_only();
        $d = mdt_get_post_data('E-DP-400');
        $conn->prepare("UPDATE dispatch_avertos SET vu = 1 WHERE id = :i AND discord_id = :d")
            ->execute(array(':i' => (int)(isset($d['id']) ? $d['id'] : 0), ':d' => $GLOBALS['DP_SELF_DID']));
        echo json_encode(array('success' => true));
        exit;
    }

    elseif ($action === 'service_hours') {

        $shIsAdmin = isDispatchAdmin($conn, $GLOBALS['DP_SELF_DID']);
        $shSelf = (string)$GLOBALS['DP_SELF_DID'];
        if (!$shIsAdmin && $shSelf === '') {
            mdt_error(403, 'E-1700', 'Acces refuse');
        }
        $weekStart = isset($_GET['week_start']) ? $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        $rows = $conn->prepare("
            SELECT ds.roster_id, r.matricule, r.nom_prenom, r.discord_id, ds.service_date,
                   SUM(ds.duration_minutes) AS total_minutes
            FROM dispatch_services ds
            JOIN roster r ON r.id = ds.roster_id
            WHERE ds.annule = 0 AND ds.service_date BETWEEN :ws AND :we AND ds.end_at IS NOT NULL
              " . ($shIsAdmin ? "" : "AND r.discord_id = :self ") . "
            GROUP BY ds.roster_id, ds.service_date
            ORDER BY r.matricule, ds.service_date
        ");
        $shParams = array(':ws' => $weekStart, ':we' => $weekEnd);
        if (!$shIsAdmin) $shParams[':self'] = $shSelf;
        $rows->execute($shParams);
        $rawData = $rows->fetchAll();

        $userInfo = array();
        try {

            if ($shIsAdmin) {
                $uRows = $conn->query("SELECT discord_id, discord_roles, telephone, compte_bancaire FROM users WHERE discord_roles IS NOT NULL")->fetchAll();
            } else {
                $uq = $conn->prepare("SELECT discord_id, discord_roles, telephone, compte_bancaire FROM users WHERE discord_id = :d LIMIT 1");
                $uq->execute(array(':d' => $shSelf));
                $uRows = $uq->fetchAll();
            }
            $GRADE_MAP = mdt_grade_labels($conn);
            foreach ($uRows as $ur) {
                $roles = json_decode($ur['discord_roles'], true);
                if (!is_array($roles)) continue;
                $grade = null; $gradeId = null;
                foreach (array_keys($GRADE_MAP) as $gId) { if (in_array($gId, $roles)) { $grade = $GRADE_MAP[$gId]; $gradeId = (string)$gId; break; } }
                $userInfo[$ur['discord_id']] = array('grade' => $grade, 'grade_id' => $gradeId,
                                                       'compte_bancaire' => $ur['compte_bancaire']);
            }
        } catch (PDOException $e) {}

        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_salaire_paye (
                id INT AUTO_INCREMENT PRIMARY KEY, roster_id INT NOT NULL, week_start DATE NOT NULL,
                paid_at DATETIME DEFAULT CURRENT_TIMESTAMP, paid_by INT DEFAULT NULL,
                UNIQUE KEY uk_roster_week (roster_id, week_start)
            )");
        } catch (PDOException $e) {}
        $paidRows = $conn->prepare("SELECT roster_id FROM dispatch_salaire_paye WHERE week_start = :ws");
        $paidRows->execute(array(':ws' => $weekStart));
        $paidMap = array();
        foreach ($paidRows->fetchAll() as $pr) { $paidMap[$pr['roster_id']] = true; }

        echo json_encode(array(
            'week_start' => $weekStart, 'week_end' => $weekEnd,
            'data' => $rawData, 'user_info' => $userInfo, 'paid' => $paidMap,

            'grades' => array_map(function ($id) use ($GRADE_MAP) {
                return array('id' => (string)$id, 'name' => $GRADE_MAP[$id],
                             'rate' => isset(MDT_GRADE_RATES[$id]) ? MDT_GRADE_RATES[$id] : 0);
            }, array_keys($GRADE_MAP))
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'mark_paid') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1270');
        if (!$data || empty($data['roster_id']) || empty($data['week_start'])) mdt_error(400, 'E-1270', 'roster_id et week_start requis');
        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_salaire_paye (id INT AUTO_INCREMENT PRIMARY KEY, roster_id INT NOT NULL, week_start DATE NOT NULL, paid_at DATETIME DEFAULT CURRENT_TIMESTAMP, paid_by INT DEFAULT NULL, UNIQUE KEY uk_roster_week (roster_id, week_start))");
        if (isset($data['unpaid']) && $data['unpaid']) {
            $conn->prepare("DELETE FROM dispatch_salaire_paye WHERE roster_id = :rid AND week_start = :ws")->execute(array(':rid' => (int)$data['roster_id'], ':ws' => $data['week_start']));
        } else {
            $conn->prepare("INSERT IGNORE INTO dispatch_salaire_paye (roster_id, week_start) VALUES (:rid, :ws)")->execute(array(':rid' => (int)$data['roster_id'], ':ws' => $data['week_start']));
        }
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'ack_auto_close') {
        mdt_post_only();
        $did = !empty($GLOBALS['DP_SELF_DID']) ? $GLOBALS['DP_SELF_DID'] : null;
        if (!$did) mdt_error(401, 'E-1701', 'Authentification requise');
        try {
            $conn->prepare("
                UPDATE dispatch_services s JOIN roster r ON r.id = s.roster_id
                SET s.auto_close_acked = 1
                WHERE r.discord_id = :d AND s.auto_close_reason IS NOT NULL AND s.auto_close_acked = 0
            ")->execute(array(':d' => $did));
        } catch (Exception $e) {}
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'resume_service') {
        mdt_post_only();
        $did = !empty($GLOBALS['DP_SELF_DID']) ? $GLOBALS['DP_SELF_DID'] : null;
        if (!$did) mdt_error(401, 'E-1701', 'Authentification requise');
        $P = dpAfkParams($conn);
        $conn->beginTransaction();
        try {
            $rq = $conn->prepare("SELECT id FROM roster WHERE discord_id = :d LIMIT 1");
            $rq->execute(array(':d' => $did));
            $rid = (int)$rq->fetchColumn();
            if (!$rid) { $conn->rollBack(); mdt_error(400, 'E-1703', 'Aucun matricule associe a ce compte'); }
            $open = $conn->prepare("SELECT id FROM dispatch_services WHERE roster_id = :r AND end_at IS NULL LIMIT 1 FOR UPDATE");
            $open->execute(array(':r' => $rid));
            if ($open->fetchColumn()) { $conn->rollBack(); mdt_error(409, 'E-1705', 'Un service est deja en cours'); }
            $cq = $conn->prepare("SELECT id, end_at FROM dispatch_services WHERE roster_id = :r AND auto_close_reason IS NOT NULL AND end_at >= NOW() - INTERVAL :m MINUTE ORDER BY end_at DESC LIMIT 1 FOR UPDATE");
            $cq->execute(array(':r' => $rid, ':m' => $P['resume_min']));
            $closed = $cq->fetch();
            if (!$closed) { $conn->rollBack(); mdt_error(409, 'E-1706', 'Aucun service reprennable (fenetre de ' . $P['resume_min'] . ' min depassee) — reprends un nouveau service'); }
            $gapMin = max(0, (int)floor((time() - strtotime($closed['end_at'])) / 60));
            $conn->prepare("UPDATE dispatch_services SET end_at = NULL, duration_minutes = NULL, auto_close_reason = NULL, auto_close_acked = 1, check_pending_since = NULL, last_seen = NOW(), last_active = NOW() WHERE id = :id")
                ->execute(array(':id' => $closed['id']));
            $conn->commit();
            dpLog($conn, 'resume_service', array('actor' => $did, 'target_type' => 'service', 'target_id' => $closed['id'], 'details' => array('gap_min' => $gapMin)));
            echo json_encode(array('success' => true, 'gap_min' => $gapMin), JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'confirm_afk_check') {
        mdt_post_only();
        $data = dp_get_post_data('E-1290');

        $discordId = !empty($GLOBALS['DP_SELF_DID']) ? $GLOBALS['DP_SELF_DID'] : null;
        if (!$discordId) mdt_error(401, 'E-1701', 'Authentification requise');
        $reason = isset($data['reason']) ? $data['reason'] : 'unknown';

        try {
            $conn->prepare("
                UPDATE dispatch_services s JOIN roster r ON r.id = s.roster_id
                SET s.last_active = NOW(), s.last_seen = NOW(), s.check_pending_since = NULL
                WHERE r.discord_id = :d AND s.end_at IS NULL
            ")->execute(array(':d' => $discordId));
        } catch (Exception $e) {}

        if (strpos($reason, 'auto') !== 0) {
            dpLog($conn, 'confirm_afk_check', array(
                'actor' => $discordId,
                'target_type' => 'service',
                'target_id' => null,
                'details' => array(
                    'reason' => $reason,
                    'confirmed_at' => date('Y-m-d H:i:s')
                )
            ));
        }

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'adjust_hours') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1275');
        if (!$data || empty($data['roster_id']) || !isset($data['minutes']) || empty($data['service_date'])) {
            mdt_error(400, 'E-1275', 'roster_id, minutes et service_date requis');
        }
        $rid = (int)$data['roster_id'];
        $adjustMinutes = (int)$data['minutes'];
        $serviceDate = $data['service_date'];
        $reason = isset($data['reason']) ? substr($data['reason'], 0, 255) : 'Ajustement manuel';
        $isQuick = !empty($data['quick']);

        if ($adjustMinutes === 0) mdt_error(400, 'E-1276', 'Le nombre de minutes ne peut pas etre 0');

        $agentStmt = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE id = :rid");
        $agentStmt->execute(array(':rid' => $rid));
        $agentRow = $agentStmt->fetch();
        $agentLabel = $agentRow ? ($agentRow['matricule'] . ' | ' . $agentRow['nom_prenom']) : null;

        $existing = $conn->prepare("SELECT id, duration_minutes FROM dispatch_services WHERE roster_id = :rid AND service_date = :sd AND end_at IS NOT NULL ORDER BY duration_minutes DESC LIMIT 1");
        $existing->execute(array(':rid' => $rid, ':sd' => $serviceDate));
        $row = $existing->fetch();

        $beforeMinutes = $row ? (int)$row['duration_minutes'] : 0;
        $afterMinutes = max(0, $beforeMinutes + $adjustMinutes);
        $appliedDelta = $afterMinutes - $beforeMinutes;

        if ($row) {

            $conn->prepare("UPDATE dispatch_services SET duration_minutes = :dur WHERE id = :id")
                ->execute(array(':dur' => $afterMinutes, ':id' => $row['id']));
        } else {

            if ($adjustMinutes <= 0) {
                mdt_error(400, 'E-1277', 'Aucune heure n\'est enregistree pour cette date (rien a retirer)');
            }
            $conn->prepare("INSERT INTO dispatch_services (user_id, roster_id, start_at, end_at, duration_minutes, service_date) VALUES (0, :rid, :sa, :ea, :dur, :sd)")
                ->execute(array(
                    ':rid' => $rid,
                    ':sa' => $serviceDate . ' 00:00:00',
                    ':ea' => $serviceDate . ' 00:00:00',
                    ':dur' => $adjustMinutes,
                    ':sd' => $serviceDate
                ));
            $beforeMinutes = 0;
            $afterMinutes = $adjustMinutes;
            $appliedDelta = $adjustMinutes;
        }

        dpLog($conn, 'adjust_hours', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'agent_hours',
            'target_id' => $rid,
            'target_label' => $agentLabel,
            'details' => array(
                'service_date' => $serviceDate,
                'delta_minutes_requested' => $adjustMinutes,
                'delta_minutes_applied' => $appliedDelta,
                'before_minutes' => $beforeMinutes,
                'after_minutes' => $afterMinutes,
                'before_hours' => round($beforeMinutes / 60, 2),
                'after_hours' => round($afterMinutes / 60, 2),
                'reason' => $reason,
                'quick' => $isQuick
            )
        ));

        echo json_encode(array(
            'success' => true,
            'adjusted' => $appliedDelta,
            'before_minutes' => $beforeMinutes,
            'after_minutes' => $afterMinutes,
            'reason' => $reason
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_action_buttons') {
        $rows = $conn->query("SELECT * FROM dispatch_action_buttons ORDER BY ordre")->fetchAll();
        foreach ($rows as &$row) {
            $row['fields_config'] = $row['fields_config'] ? json_decode($row['fields_config'], true) : null;
            $row['is_reset'] = (int)$row['is_reset'];
        }
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_action_button') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1230');
        if (!$data || empty($data['code']) || empty($data['label'])) mdt_error(400, 'E-1230', 'Code et label requis');

        $id = !empty($data['id']) ? $data['id'] : 'dab_' . uniqid();
        $fieldsJson = isset($data['fields_config']) && is_array($data['fields_config']) ? json_encode($data['fields_config']) : null;

        $stmt = $conn->prepare("INSERT INTO dispatch_action_buttons (id, code, label, intervention_type, icon, color, fields_config, statut_target, is_reset, ordre)
            VALUES (:id, :code, :label, :itype, :icon, :color, :fields, :starget, :reset, :ordre)
            ON DUPLICATE KEY UPDATE code=VALUES(code), label=VALUES(label), intervention_type=VALUES(intervention_type),
            icon=VALUES(icon), color=VALUES(color), fields_config=VALUES(fields_config), statut_target=VALUES(statut_target),
            is_reset=VALUES(is_reset), ordre=VALUES(ordre)");
        $stmt->execute(array(
            ':id' => $id,
            ':code' => $data['code'],
            ':label' => $data['label'],
            ':itype' => isset($data['intervention_type']) ? $data['intervention_type'] : 'autre',
            ':icon' => isset($data['icon']) ? $data['icon'] : 'fa-circle-exclamation',
            ':color' => isset($data['color']) ? $data['color'] : '#3b82f6',
            ':fields' => $fieldsJson,
            ':starget' => isset($data['statut_target']) ? $data['statut_target'] : 'ds_intervention',
            ':reset' => !empty($data['is_reset']) ? 1 : 0,
            ':ordre' => isset($data['ordre']) ? (int)$data['ordre'] : 99
        ));

        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_action_button') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1231');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1231', 'ID requis');
        $conn->prepare("DELETE FROM dispatch_action_buttons WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'remove_dispatch_queue') {
        mdt_post_only();
        $data = dp_get_post_data('E-1218');
        if (!$data || empty($data['roster_id'])) mdt_error(400, 'E-1218', 'roster_id requis');
        $actorDid = dpResolveActorDiscordId($conn, $data);
        if (function_exists('requireSelfOrAdmin')) {
            requireSelfOrAdmin($conn, $actorDid, (int)$data['roster_id'], 'E-1218F');
        }
        try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => (int)$data['roster_id'])); } catch(PDOException $e) {}

        dpLog($conn, 'remove_dispatch_queue', array(
            'actor' => $actorDid,
            'target_type' => 'agent',
            'target_id' => (int)$data['roster_id']
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'execute_action') {
        mdt_post_only();
        if (empty($GLOBALS['DP_SELF_DID'])) mdt_error(401, 'E-DP-401', 'Session requise (anti-usurpation)');
        $data = dp_get_post_data('E-1240');
        if (!$data || empty($data['button_id']) || empty($data['discord_id'])) {
            mdt_error(400, 'E-1240', 'button_id et discord_id requis');
        }

        $btn = $conn->prepare("SELECT * FROM dispatch_action_buttons WHERE id = :id");
        $btn->execute(array(':id' => $data['button_id']));
        $button = $btn->fetch();
        if (!$button) mdt_error(404, 'E-1241', 'Bouton introuvable');

        $specialAction = isset($button['special_action']) ? $button['special_action'] : null;

        $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $data['discord_id']));
        $roster = $r->fetch();
        if (!$roster) mdt_error(404, 'E-1242', 'Agent introuvable');

        $u = $conn->prepare("SELECT id FROM users WHERE discord_id = :did");
        $u->execute(array(':did' => $data['discord_id']));
        $user = $u->fetch();

        $p = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid LIMIT 1");
        $p->execute(array(':rid' => $roster['id']));
        $pa = $p->fetch();

        $logIndicatif = null;
        if ($pa) {
            $pInfo = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
            $pInfo->execute(array(':id' => $pa['patrouille_id']));
            $logIndicatif = $pInfo->fetchColumn() ?: null;
        }

        dpLog($conn, 'execute_action', array(
            'actor' => $data['discord_id'],
            'target_type' => 'code',
            'target_id' => $button['id'],
            'target_label' => $button['code'] . ' ' . $button['label'],
            'details' => array(
                'patrouille_id' => $pa ? $pa['patrouille_id'] : null,
                'patrouille_indicatif' => $logIndicatif,
                'special_action' => $specialAction,
                'is_reset' => (int)$button['is_reset'],
                'field_values' => isset($data['field_values']) ? $data['field_values'] : null,
            )
        ));

        if ($specialAction === 'pause') {

            try { $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_pauses (roster_id INT PRIMARY KEY, since DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(PDOException $e) {}
            $conn->prepare("REPLACE INTO dispatch_pauses (roster_id, since) VALUES (:rid, NOW())")->execute(array(':rid' => $roster['id']));

            if ($pa) {
                $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_intervention', statut_since = NOW(), info_sup = '10-06 Procedure' WHERE id = :pid")
                    ->execute(array(':pid' => $pa['patrouille_id']));
            }
            echo json_encode(array('success' => true, 'action' => 'pause'), JSON_UNESCAPED_UNICODE);
        }
        elseif ($specialAction === 'start_service') {

            if (!$user) mdt_error(404, 'E-1222', 'Aucun compte utilisateur lie');
            $check = $conn->prepare("SELECT id FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL");
            $check->execute(array(':rid' => $roster['id']));
            if ($check->fetch()) {
                echo json_encode(array('success' => true, 'action' => 'already_in_service'), JSON_UNESCAPED_UNICODE);
            } else {
                $now = date('Y-m-d H:i:s');
                dpEnsureLastSeenColumn($conn);
                $conn->prepare("INSERT INTO dispatch_services (user_id, roster_id, start_at, service_date, last_seen) VALUES (:uid, :rid, :s, :d, :s2)")
                    ->execute(array(':uid' => $user['id'], ':rid' => $roster['id'], ':s' => $now, ':d' => date('Y-m-d'), ':s2' => $now));

                try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}

                $hp = date('H:i', strtotime($now));
                $ri = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE id = :id");
                $ri->execute(array(':id' => $roster['id']));
                $riRow = $ri->fetch();
                dpLog($conn, 'start_service', array(
                    'actor' => $data['discord_id'],
                    'target_type' => 'service',
                    'target_label' => $riRow ? ($riRow['matricule'] . ' | ' . $riRow['nom_prenom'] . ' a pris son service a ' . $hp . ' (10-8)') : null,
                    'details' => array('start_at' => $now, 'heure_prise' => $hp, 'via' => '10-8')
                ));
                echo json_encode(array('success' => true, 'action' => 'start_service'), JSON_UNESCAPED_UNICODE);
            }
        }
        elseif ($specialAction === 'end_service') {

            $svc = $conn->prepare("SELECT id, start_at FROM dispatch_services WHERE roster_id = :rid AND end_at IS NULL LIMIT 1");
            $svc->execute(array(':rid' => $roster['id']));
            $service = $svc->fetch();
            $duration = 0;
            if ($service) {
                $start = new DateTime($service['start_at']);
                $now = new DateTime();
                $duration = max(0, (int)floor(($now->getTimestamp() - $start->getTimestamp()) / 60));
                $conn->prepare("UPDATE dispatch_services SET end_at = NOW(), duration_minutes = :dur WHERE id = :id")
                    ->execute(array(':dur' => $duration, ':id' => $service['id']));
            }

            if ($pa) {
                $patId = $pa['patrouille_id'];
                $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $roster['id']));

                $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouille_agents WHERE patrouille_id = :pid");
                $cnt->execute(array(':pid' => $patId));
                if ((int)$cnt->fetchColumn() === 0) {
                    $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $patId));
                    $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $patId));
                } else {
                    recalcIndicatif($conn, $patId);
                }
            }

            try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_dispatchers WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}

            try { $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            echo json_encode(array('success' => true, 'action' => 'end_service', 'duration_minutes' => $duration), JSON_UNESCAPED_UNICODE);
        }
        elseif ($specialAction === 'close_patrol') {

            if ($pa) {
                $patId = $pa['patrouille_id'];

                $ints = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
                $ints->execute(array(':pid' => $patId));
                foreach ($ints->fetchAll() as $intRow) {
                    if (DP_AUTO_CLOSE_INTERVENTIONS) $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW() WHERE id = :id AND statut != 'termine'")
                        ->execute(array(':id' => $intRow['intervention_id']));
                }
                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $patId));
                $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE patrouille_id = :pid")->execute(array(':pid' => $patId));
                $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $patId));
            }
            echo json_encode(array('success' => true, 'action' => 'close_patrol'), JSON_UNESCAPED_UNICODE);
        }
        elseif ($specialAction === 'dispatch_queue') {

            try { $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_queue (roster_id INT PRIMARY KEY, since DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(PDOException $e) {}
            $conn->prepare("REPLACE INTO dispatch_queue (roster_id, since) VALUES (:rid, NOW())")->execute(array(':rid' => $roster['id']));
            echo json_encode(array('success' => true, 'action' => 'dispatch_queue'), JSON_UNESCAPED_UNICODE);
        }
        elseif ((int)$button['is_reset']) {

            if ($pa) {
                $ints = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
                $ints->execute(array(':pid' => $pa['patrouille_id']));
                foreach ($ints->fetchAll() as $intRow) {
                    if (DP_AUTO_CLOSE_INTERVENTIONS) $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW() WHERE id = :id AND statut != 'termine'")
                        ->execute(array(':id' => $intRow['intervention_id']));
                    $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :id")
                        ->execute(array(':id' => $intRow['intervention_id']));
                }
                $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = :sid, statut_since = NOW(), info_sup = NULL WHERE id = :pid")
                    ->execute(array(':sid' => $button['statut_target'], ':pid' => $pa['patrouille_id']));
            }

            try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            try { $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}
            echo json_encode(array('success' => true, 'action' => 'reset'), JSON_UNESCAPED_UNICODE);
        } else {

            $fieldsData = isset($data['field_values']) ? json_encode($data['field_values']) : null;

            $desc = '';
            if (isset($data['field_values']) && is_array($data['field_values'])) {

                $labelMap = array();
                $fieldsConfig = $button['fields_config'] ? json_decode($button['fields_config'], true) : array();
                if (is_array($fieldsConfig)) {
                    foreach ($fieldsConfig as $fc) { if (isset($fc['name']) && isset($fc['label'])) $labelMap[$fc['name']] = $fc['label']; }
                }
                $parts = array();
                foreach ($data['field_values'] as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $label = isset($labelMap[$k]) ? $labelMap[$k] : $k;
                        $parts[] = $label . ': ' . $v;
                    }
                }
                $desc = implode(' | ', $parts);
            }

            $lieu = null;
            if (isset($data['field_values']['lieu'])) $lieu = $data['field_values']['lieu'];
            elseif (isset($data['field_values']['destination'])) $lieu = $data['field_values']['destination'];

            $priorite = 'medium';
            $highPrio = array('dab_1031','dab_1035','dab_1049','dab_1091','dab_1099');
            $lowPrio = array('dab_1037','dab_1050','dab_1051','dab_1052','dab_1060');
            if (in_array($button['id'], $highPrio)) $priorite = 'high';
            elseif (in_array($button['id'], $lowPrio)) $priorite = 'low';

            $canalRadio = isset($data['field_values']['canal_radio']) ? $data['field_values']['canal_radio'] : null;

            $existingId = null;
            try {
                $dedupStmt = $conn->prepare(
                    "SELECT id FROM dispatch_interventions
                     WHERE action_button_id = :bid
                       AND statut != 'termine'
                       AND COALESCE(LOWER(TRIM(lieu)), '') = COALESCE(LOWER(TRIM(:lieu)), '')
                       AND created_at >= (NOW() - INTERVAL 2 HOUR)
                     ORDER BY created_at ASC
                     LIMIT 1"
                );
                $dedupStmt->execute(array(':bid' => $button['id'], ':lieu' => $lieu));
                $existingId = $dedupStmt->fetchColumn() ?: null;
            } catch (Exception $e) { $existingId = null; }

            if ($existingId) {

                $intId = $existingId;
            } else {
                $intId = 'di_' . uniqid();
                $stmt = $conn->prepare("INSERT INTO dispatch_interventions (id, type, lieu, priorite, description, statut, created_by_user_id, action_button_id, action_fields_data, canal_radio)
                    VALUES (:id, :type, :lieu, :pri, :desc, 'nouveau', :uid, :bid, :fdata, :cr)");
                $stmt->execute(array(
                    ':id' => $intId,
                    ':type' => $button['intervention_type'],
                    ':lieu' => $lieu,
                    ':pri' => $priorite,
                    ':desc' => $button['code'] . ' - ' . $button['label'] . ($desc ? ' | ' . $desc : ''),
                    ':uid' => $user ? $user['id'] : null,
                    ':bid' => $button['id'],
                    ':fdata' => $fieldsData,
                    ':cr' => $canalRadio
                ));
            }

            if ($pa) {

                $oldInts = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid");
                $oldInts->execute(array(':pid' => $pa['patrouille_id']));
                $oldIntIds = $oldInts->fetchAll(PDO::FETCH_COLUMN);

                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")
                    ->execute(array(':pid' => $pa['patrouille_id']));

                foreach ($oldIntIds as $oldIntId) {
                    $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_intervention_patrouilles WHERE intervention_id = :iid");
                    $cnt->execute(array(':iid' => $oldIntId));
                    if ((int)$cnt->fetchColumn() === 0) {
                        $hasVeh = $conn->prepare("SELECT COUNT(*) FROM dispatch_intervention_vehicules WHERE intervention_id = :iid AND (statut IS NULL OR statut = 'en_cours')");
                        $hasVeh->execute(array(':iid' => $oldIntId));
                        if ((int)$hasVeh->fetchColumn() > 0) continue;
                        if (DP_AUTO_CLOSE_INTERVENTIONS) $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW(), description = CONCAT(COALESCE(description,''), ' | [Code 4 auto]') WHERE id = :id AND statut != 'termine'")
                            ->execute(array(':id' => $oldIntId));
                        try { $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE vehicule_id IN (SELECT id FROM dispatch_intervention_vehicules WHERE intervention_id = :iid)")->execute(array(':iid' => $oldIntId)); } catch (PDOException $e) {}
                    }
                }

                $conn->prepare("INSERT IGNORE INTO dispatch_intervention_patrouilles (intervention_id, patrouille_id) VALUES (:iid, :pid)")
                    ->execute(array(':iid' => $intId, ':pid' => $pa['patrouille_id']));

                $newStatut = !empty($button['statut_target']) ? $button['statut_target'] : 'ds_intervention';
                $newInfoSup = $button['code'] . ' ' . $button['label'];
                $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = :sid, statut_since = NOW(), info_sup = :inf WHERE id = :pid")
                    ->execute(array(':sid' => $newStatut, ':inf' => $newInfoSup, ':pid' => $pa['patrouille_id']));
            }

            try { $conn->prepare("DELETE FROM dispatch_pauses WHERE roster_id = :rid")->execute(array(':rid' => $roster['id'])); } catch(PDOException $e) {}

            echo json_encode(array('success' => true, 'action' => 'created', 'intervention_id' => $intId), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'get_dispatchers') {
        $rows = $conn->query("
            SELECT dd.role, dd.assigned_at, r.id AS roster_id, r.matricule, r.nom_prenom
            FROM dispatch_dispatchers dd
            JOIN roster r ON r.id = dd.roster_id
        ")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'take_dispatch_role') {
        mdt_post_only();
        $did = $GLOBALS['DP_SELF_DID'];
        if (!$did) mdt_error(401, 'E-1701', 'Authentification requise');
        $data = dp_get_post_data('E-1250');
        $role = isset($data['role']) ? $data['role'] : '';
        if ($role !== 'dispatcher' && $role !== 'co_dispatcher') mdt_error(400, 'E-1702', 'Role invalide');
        $rq = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did LIMIT 1");
        $rq->execute(array(':did' => $did));
        $rid = (int)$rq->fetchColumn();
        if (!$rid) mdt_error(400, 'E-1703', 'Aucun matricule associe a ce compte');
        $occ = $conn->prepare("SELECT roster_id FROM dispatch_dispatchers WHERE role = :role LIMIT 1");
        $occ->execute(array(':role' => $role));
        $cur = (int)$occ->fetchColumn();
        if ($cur && $cur !== $rid) mdt_error(409, 'E-1704', 'Ce poste est deja occupe');
        $conn->prepare("DELETE FROM dispatch_dispatchers WHERE role = :role")->execute(array(':role' => $role));
        $conn->prepare("INSERT INTO dispatch_dispatchers (roster_id, role) VALUES (:rid, :role)")->execute(array(':rid' => $rid, ':role' => $role));
        dpLog($conn, 'take_dispatch_role', array('actor' => $did, 'target_type' => 'dispatch', 'details' => array('role' => $role, 'roster_id' => $rid)));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'leave_dispatch_role') {
        mdt_post_only();
        $did = $GLOBALS['DP_SELF_DID'];
        if (!$did) mdt_error(401, 'E-1701', 'Authentification requise');
        $data = dp_get_post_data('E-1250');
        $role = isset($data['role']) ? $data['role'] : '';
        if ($role !== 'dispatcher' && $role !== 'co_dispatcher') mdt_error(400, 'E-1702', 'Role invalide');
        $rq = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did LIMIT 1");
        $rq->execute(array(':did' => $did));
        $rid = (int)$rq->fetchColumn();
        if (!$rid) mdt_error(400, 'E-1703', 'Aucun matricule associe a ce compte');
        $conn->prepare("DELETE FROM dispatch_dispatchers WHERE role = :role AND roster_id = :rid")->execute(array(':role' => $role, ':rid' => $rid));
        dpLog($conn, 'leave_dispatch_role', array('actor' => $did, 'target_type' => 'dispatch', 'details' => array('role' => $role, 'roster_id' => $rid)));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'join_supervision') {
        mdt_post_only();
        $did = $GLOBALS['DP_SELF_DID'];
        if (!$did) mdt_error(401, 'E-1701', 'Authentification requise');
        $rq = $conn->prepare("SELECT r.id AS rid, u.discord_roles FROM roster r LEFT JOIN users u ON u.discord_id = r.discord_id WHERE r.discord_id = :did LIMIT 1");
        $rq->execute(array(':did' => $did));
        $row = $rq->fetch();
        $rid = $row ? (int)$row['rid'] : 0;
        if (!$rid) mdt_error(400, 'E-1703', 'Aucun matricule associe a ce compte');
        $roles = ($row && $row['discord_roles']) ? (json_decode($row['discord_roles'], true) ?: array()) : array();
        $DP_SUP_ELIG = MDT_SUP_ELIG_ROLE_IDS;
        if (count(array_intersect($roles, $DP_SUP_ELIG)) === 0) mdt_error(403, 'E-1705', 'Reserve Senior/Master Trooper et Supervision');
        $conn->prepare("INSERT IGNORE INTO dispatch_dispatchers (roster_id, role) VALUES (:rid, 'supervision')")->execute(array(':rid' => $rid));
        dpLog($conn, 'join_supervision', array('actor' => $did, 'target_type' => 'dispatch', 'details' => array('roster_id' => $rid)));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'leave_supervision') {
        mdt_post_only();
        $did = $GLOBALS['DP_SELF_DID'];
        if (!$did) mdt_error(401, 'E-1701', 'Authentification requise');
        $rq = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did LIMIT 1");
        $rq->execute(array(':did' => $did));
        $rid = (int)$rq->fetchColumn();
        if (!$rid) mdt_error(400, 'E-1703', 'Aucun matricule associe a ce compte');
        $conn->prepare("DELETE FROM dispatch_dispatchers WHERE role = 'supervision' AND roster_id = :rid")->execute(array(':rid' => $rid));
        dpLog($conn, 'leave_supervision', array('actor' => $did, 'target_type' => 'dispatch', 'details' => array('roster_id' => $rid)));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'set_dispatchers') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        mdt_post_only();
        $data = dp_get_post_data('E-1250');

        $conn->beginTransaction();
        $conn->exec("DELETE FROM dispatch_dispatchers");

        if (!empty($data['dispatcher_roster_id'])) {
            $conn->prepare("INSERT INTO dispatch_dispatchers (roster_id, role) VALUES (:rid, 'dispatcher')")
                ->execute(array(':rid' => (int)$data['dispatcher_roster_id']));
        }
        if (!empty($data['co_dispatcher_roster_id'])) {
            $conn->prepare("INSERT INTO dispatch_dispatchers (roster_id, role) VALUES (:rid, 'co_dispatcher')")
                ->execute(array(':rid' => (int)$data['co_dispatcher_roster_id']));
        }

          if (!empty($data['supervision_roster_ids']) && is_array($data['supervision_roster_ids'])) {
              $DP_SUP_ELIG = MDT_SUP_ELIG_ROLE_IDS;
              $supIns = $conn->prepare("INSERT INTO dispatch_dispatchers (roster_id, role) VALUES (:rid, 'supervision')");
              $supChk = $conn->prepare("SELECT u.discord_roles FROM roster r JOIN users u ON u.discord_id = r.discord_id WHERE r.id = :rid LIMIT 1");
              $supSeen = array();
              foreach ($data['supervision_roster_ids'] as $sid) {
                  $sid = (int)$sid; if ($sid <= 0 || isset($supSeen[$sid])) continue; $supSeen[$sid] = 1;
                  $supChk->execute(array(':rid' => $sid));
                  $rolesJson = $supChk->fetchColumn();
                  $roles = $rolesJson ? (json_decode($rolesJson, true) ?: array()) : array();
                  if (count(array_intersect($roles, $DP_SUP_ELIG)) > 0) $supIns->execute(array(':rid' => $sid));
              }
          }

        $conn->commit();

        dpLog($conn, 'set_dispatchers', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'dispatch',
            'details' => array(
                'dispatcher_roster_id' => isset($data['dispatcher_roster_id']) ? (int)$data['dispatcher_roster_id'] : null,
                'co_dispatcher_roster_id' => isset($data['co_dispatcher_roster_id']) ? (int)$data['co_dispatcher_roster_id'] : null,
            )
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'command_panel') {

        $ddRows = $conn->query("
            SELECT dd.role, r.matricule, r.nom_prenom, r.discord_id
            FROM dispatch_dispatchers dd
            JOIN roster r ON r.id = dd.roster_id
        ")->fetchAll();
        $dispatchers = array();
        $supervision = array();
        $supSeen = array();
        foreach ($ddRows as $dd) {
            if ($dd['role'] === 'supervision') {
                $supervision[] = array('matricule' => $dd['matricule'], 'nom_prenom' => $dd['nom_prenom'], 'start_at' => null);
                $supSeen[$dd['discord_id']] = 1;
            } else {
                $dispatchers[] = $dd;
            }
        }

        $inService = $conn->query("
            SELECT r.matricule, r.nom_prenom, r.discord_id, u.discord_roles, ds.start_at
            FROM dispatch_services ds
            JOIN roster r ON r.id = ds.roster_id
            LEFT JOIN users u ON u.discord_id = r.discord_id
            WHERE ds.end_at IS NULL
        ")->fetchAll();

        $etatMajor = array();
        foreach ($inService as $agent) {
            $roles = $agent['discord_roles'] ? json_decode($agent['discord_roles'], true) : array();
            if (!is_array($roles)) continue;
            if (in_array(MDT_ROLE_SUPERVISION, $roles) && !isset($supSeen[$agent['discord_id']])) {
                $supervision[] = array('matricule' => $agent['matricule'], 'nom_prenom' => $agent['nom_prenom'], 'start_at' => $agent['start_at']);
            }
            if (in_array(MDT_ROLE_ETAT_MAJOR, $roles)) {
                $etatMajor[] = array('matricule' => $agent['matricule'], 'nom_prenom' => $agent['nom_prenom'], 'start_at' => $agent['start_at']);
            }
        }

        echo json_encode(array(
            'dispatchers' => $dispatchers,
            'supervision' => $supervision,
            'etat_major' => $etatMajor
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'create_patrol_auto') {
        mdt_post_only();
        $data = dp_get_post_data('E-1260');
        if (!$data || !isset($data['agent_ids']) || !is_array($data['agent_ids']) || empty($data['agent_ids'])) {
            mdt_error(400, 'E-1260', 'agent_ids requis (tableau)');
        }
        if (empty($data['leader_roster_id'])) {
            mdt_error(400, 'E-1261', 'leader_roster_id requis (plus haut grade)');
        }

        $agentIds = $data['agent_ids'];
        $leaderId = (int)$data['leader_roster_id'];
        $nbAgents = count($agentIds);

        $ptId = isset($data['patrol_type_id']) ? $data['patrol_type_id'] : null;
        $nomFormat = null;
        if ($ptId) {
            $ptRow = $conn->prepare("SELECT nom_format FROM dispatch_patrol_types WHERE id = :id");
            $ptRow->execute(array(':id' => $ptId));
            $ptData = $ptRow->fetch();
            if ($ptData && $ptData['nom_format']) $nomFormat = $ptData['nom_format'];
        }
        if ($nomFormat) {
            $callsign = $nomFormat;
        } else {
            $callsigns = array(1 => 'LINCOLN', 2 => 'ADAM', 3 => 'TANGO', 4 => 'QUEEN');
            $callsign = isset($callsigns[$nbAgents]) ? $callsigns[$nbAgents] : 'SQUAD';
        }

        $leaderStmt = $conn->prepare("SELECT matricule FROM roster WHERE id = :id");
        $leaderStmt->execute(array(':id' => $leaderId));
        $leader = $leaderStmt->fetch();
        if (!$leader) mdt_error(404, 'E-1262', 'Leader introuvable');

        $mats = array();
        $idsInt = array_values(array_unique(array_map('intval', $agentIds)));
        if ($idsInt) {
            $ph = implode(',', array_fill(0, count($idsInt), '?'));
            $matStmt = $conn->prepare("SELECT id, matricule FROM roster WHERE id IN ($ph)");
            $matStmt->execute($idsInt);
            $byId = array();
            foreach ($matStmt->fetchAll() as $row) $byId[(int)$row['id']] = $row['matricule'];
            foreach ($idsInt as $aid) if (isset($byId[$aid])) $mats[] = $byId[$aid];
        }

        $indicatif = $callsign . ' ' . $leader['matricule'] . ' | ' . implode(' + ', $mats);

        $id = 'dp_' . uniqid();
        $conn->beginTransaction();

        $stmt = $conn->prepare("INSERT INTO dispatch_patrouilles (id, indicatif, vehicule, canal_radio, notes, statut_id, patrol_type_id, armement, tph, info_sup, secteur, custom_indicatif)
            VALUES (:id, :ind, :veh, :can, :not, :sid, :ptid, :arm, :tph, :inf, :sec, 0)");
        $stmt->execute(array(
            ':id' => $id,
            ':ind' => $indicatif,
            ':veh' => isset($data['vehicule']) ? $data['vehicule'] : null,
            ':can' => isset($data['canal_radio']) ? $data['canal_radio'] : null,
            ':not' => isset($data['notes']) ? $data['notes'] : null,
            ':sid' => 'ds_disponible',
            ':ptid' => $ptId,
            ':arm' => isset($data['armement']) ? $data['armement'] : null,
            ':tph' => isset($data['tph']) ? $data['tph'] : null,
            ':inf' => isset($data['info_sup']) ? $data['info_sup'] : null,
            ':sec' => isset($data['secteur']) ? $data['secteur'] : 'all'
        ));

        $ins = $conn->prepare("INSERT INTO dispatch_patrouille_agents (patrouille_id, roster_id) VALUES (:pid, :rid)");
        $delQ = $conn->prepare("DELETE FROM dispatch_queue WHERE roster_id = :rid");
        foreach ($agentIds as $rid) {
            $ins->execute(array(':pid' => $id, ':rid' => (int)$rid));

            try { $delQ->execute(array(':rid' => (int)$rid)); } catch(PDOException $e) {}
        }

        $conn->commit();

        recalcIndicatif($conn, $id);
        $finalStmt = $conn->prepare("SELECT indicatif FROM dispatch_patrouilles WHERE id = :id");
        $finalStmt->execute(array(':id' => $id));
        $finalRow = $finalStmt->fetch();
        if ($finalRow && !empty($finalRow['indicatif'])) $indicatif = $finalRow['indicatif'];

        dpLog($conn, 'create_patrol_auto', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'patrouille',
            'target_id' => $id,
            'target_label' => $indicatif,
            'details' => array(
                'nb_agents' => $nbAgents,
                'agent_ids' => $agentIds,
                'leader_roster_id' => $leaderId,
                'patrol_type_id' => $ptId,
            )
        ));

        echo json_encode(array('success' => true, 'id' => $id, 'indicatif' => $indicatif), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_patrol_types') {
        $rows = $conn->query("SELECT * FROM dispatch_patrol_types ORDER BY ordre")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_patrol_type') {
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1270');
        if (!$data || empty($data['label'])) mdt_error(400, 'E-1270', 'Label requis');

        $id = !empty($data['id']) ? $data['id'] : 'dpt_' . uniqid();
        $nomFormat = isset($data['nom_format']) && trim($data['nom_format']) !== '' ? trim($data['nom_format']) : null;

        $stmt = $conn->prepare("INSERT INTO dispatch_patrol_types (id, label, nom_format, icon, color, ordre)
            VALUES (:id, :label, :nf, :icon, :color, :ordre)
            ON DUPLICATE KEY UPDATE label=VALUES(label), nom_format=VALUES(nom_format),
            icon=VALUES(icon), color=VALUES(color), ordre=VALUES(ordre)");
        $stmt->execute(array(
            ':id' => $id,
            ':label' => $data['label'],
            ':nf' => $nomFormat,
            ':icon' => isset($data['icon']) ? $data['icon'] : 'fa-shield-halved',
            ':color' => isset($data['color']) ? $data['color'] : '#3b82f6',
            ':ordre' => isset($data['ordre']) ? (int)$data['ordre'] : 0
        ));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_patrol_type') {
        if (!function_exists('mdt_require_admin')) mdt_error(500, 'E-1700', 'Garde admin indisponible'); mdt_require_admin($conn, 'dispatch_admin');
        mdt_post_only();
        $data = dp_get_post_data('E-1271');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1271', 'ID requis');
        if ($data['id'] === 'dpt_standard') mdt_error(400, 'E-1272', 'Impossible de supprimer le type Standard');

        $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouilles WHERE patrol_type_id = :id");
        $cnt->execute(array(':id' => $data['id']));
        if ((int)$cnt->fetchColumn() > 0) {
            mdt_error(400, 'E-1272', 'Ce type est utilise par des patrouilles actives');
        }

        $conn->prepare("DELETE FROM dispatch_patrol_types WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'intervention_detail') {

        $intId = isset($_GET['id']) ? $_GET['id'] : '';
        if (!$intId) mdt_error(400, 'E-1280', 'ID intervention requis');

        $int = $conn->prepare("SELECT * FROM dispatch_interventions WHERE id = :id");
        $int->execute(array(':id' => $intId));
        $intervention = $int->fetch();
        if (!$intervention) mdt_error(404, 'E-1281', 'Intervention introuvable');

        $pats = $conn->prepare("SELECT dp.id, dp.indicatif FROM dispatch_intervention_patrouilles dip JOIN dispatch_patrouilles dp ON dp.id = dip.patrouille_id WHERE dip.intervention_id = :id");
        $pats->execute(array(':id' => $intId));
        $intervention['assigned_patrols'] = $pats->fetchAll();

        $vehs = $conn->prepare("SELECT * FROM dispatch_intervention_vehicules WHERE intervention_id = :id ORDER BY created_at");
        $vehs->execute(array(':id' => $intId));
        $vehicules = $vehs->fetchAll();

        $assignMap = array();
        if ($vehicules) {
            $vids = array_map(function($v){ return $v['id']; }, $vehicules);
            $ph = implode(',', array_fill(0, count($vids), '?'));
            $aq = $conn->prepare("SELECT dva.vehicule_id, dva.position, dp.id AS patrol_id, dp.indicatif FROM dispatch_vehicule_assignations dva JOIN dispatch_patrouilles dp ON dp.id = dva.patrouille_id WHERE dva.vehicule_id IN ($ph)");
            $aq->execute($vids);
            foreach ($aq->fetchAll() as $a) {
                $vid = $a['vehicule_id']; unset($a['vehicule_id']);
                if (!isset($assignMap[$vid])) $assignMap[$vid] = array();
                $assignMap[$vid][] = $a;
            }
        }
        foreach ($vehicules as &$v) {
            $v['assignations'] = isset($assignMap[$v['id']]) ? $assignMap[$v['id']] : array();
        }
        unset($v);
        $intervention['vehicules'] = $vehicules;

        $atts = $conn->prepare("SELECT * FROM dispatch_intervention_attachments WHERE intervention_id = :id ORDER BY created_at");
        $atts->execute(array(':id' => $intId));
        $intervention['attachments'] = $atts->fetchAll();

        if ($intervention['action_button_id']) {
            $ab = $conn->prepare("SELECT code, label, icon, color FROM dispatch_action_buttons WHERE id = :id");
            $ab->execute(array(':id' => $intervention['action_button_id']));
            $intervention['action_button'] = $ab->fetch() ?: null;
        }

        try {
            $logs = $conn->prepare("SELECT pl.*, dp.indicatif FROM dispatch_poursuite_log pl LEFT JOIN dispatch_patrouilles dp ON dp.id = pl.patrouille_id WHERE pl.intervention_id = :id ORDER BY pl.created_at");
            $logs->execute(array(':id' => $intId));
            $intervention['poursuite_logs'] = $logs->fetchAll();
        } catch (PDOException $e) { $intervention['poursuite_logs'] = array(); }

        echo json_encode($intervention, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'add_intervention_vehicule') {
        mdt_post_only();
        $data = dp_get_post_data('E-1282');
        if (!$data || empty($data['intervention_id'])) mdt_error(400, 'E-1282', 'intervention_id requis');

        $id = 'div_' . uniqid();
        $stmt = $conn->prepare("INSERT INTO dispatch_intervention_vehicules (id, intervention_id, modele, couleur, immat, nb_personnes, armes, photo_url, notes) VALUES (:id, :iid, :mod, :coul, :imm, :nb, :arm, :photo, :notes)");
        $stmt->execute(array(
            ':id' => $id, ':iid' => $data['intervention_id'],
            ':mod' => isset($data['modele']) ? $data['modele'] : null,
            ':coul' => isset($data['couleur']) ? $data['couleur'] : null,
            ':imm' => isset($data['immat']) ? $data['immat'] : null,
            ':nb' => isset($data['nb_personnes']) ? (int)$data['nb_personnes'] : null,
            ':arm' => isset($data['armes']) ? $data['armes'] : null,
            ':photo' => isset($data['photo_url']) ? $data['photo_url'] : null,
            ':notes' => isset($data['notes']) ? $data['notes'] : null
        ));
        $conn->prepare("UPDATE dispatch_interventions SET empty_since = NULL WHERE id = :id")->execute(array(':id' => $data['intervention_id']));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_intervention_vehicule') {
        mdt_post_only();
        $data = dp_get_post_data('E-1283');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1283', 'ID requis');
        $vInt = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_vehicules WHERE id = :id");
        $vInt->execute(array(':id' => $data['id']));
        $vIntId = $vInt->fetchColumn();
        $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE vehicule_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM dispatch_intervention_vehicules WHERE id = :id")->execute(array(':id' => $data['id']));
        if ($vIntId) dpInterUpdateEmpty($conn, $vIntId);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'assign_vehicule_patrol') {
        mdt_post_only();
        $data = dp_get_post_data('E-1284');
        if (!$data || empty($data['vehicule_id']) || empty($data['patrouille_id'])) mdt_error(400, 'E-1284', 'vehicule_id et patrouille_id requis');
        $pos = isset($data['position']) ? $data['position'] : 'P1';

        $stmt = $conn->prepare("INSERT INTO dispatch_vehicule_assignations (vehicule_id, patrouille_id, position) VALUES (:vid, :pid, :pos) ON DUPLICATE KEY UPDATE position = VALUES(position)");
        $stmt->execute(array(':vid' => $data['vehicule_id'], ':pid' => $data['patrouille_id'], ':pos' => $pos));

        $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_intervention', statut_since = NOW() WHERE id = :pid AND statut_id != 'ds_intervention'")
            ->execute(array(':pid' => $data['patrouille_id']));

        $vInt = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_vehicules WHERE id = :vid");
        $vInt->execute(array(':vid' => $data['vehicule_id']));
        $vIntRow = $vInt->fetch();
        if ($vIntRow) {
            $conn->prepare("INSERT IGNORE INTO dispatch_intervention_patrouilles (intervention_id, patrouille_id) VALUES (:iid, :pid)")
                ->execute(array(':iid' => $vIntRow['intervention_id'], ':pid' => $data['patrouille_id']));
        }

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'unassign_vehicule_patrol') {
        mdt_post_only();
        $data = dp_get_post_data('E-1285');
        if (!$data || empty($data['vehicule_id']) || empty($data['patrouille_id'])) mdt_error(400, 'E-1285', 'vehicule_id et patrouille_id requis');
        $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE vehicule_id = :vid AND patrouille_id = :pid")
            ->execute(array(':vid' => $data['vehicule_id'], ':pid' => $data['patrouille_id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'add_intervention_attachment') {
        mdt_post_only();
        $data = dp_get_post_data('E-1286');
        if (!$data || empty($data['intervention_id']) || empty($data['file_url'])) mdt_error(400, 'E-1286', 'intervention_id et file_url requis');

        $id = 'dia_' . uniqid();
        $stmt = $conn->prepare("INSERT INTO dispatch_intervention_attachments (id, intervention_id, file_url, file_name, file_type, uploaded_by) VALUES (:id, :iid, :url, :name, :type, :uid)");
        $stmt->execute(array(
            ':id' => $id, ':iid' => $data['intervention_id'],
            ':url' => $data['file_url'],
            ':name' => isset($data['file_name']) ? $data['file_name'] : null,
            ':type' => isset($data['file_type']) ? $data['file_type'] : 'image',
            ':uid' => isset($data['uploaded_by']) ? (int)$data['uploaded_by'] : null
        ));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_intervention_attachment') {
        mdt_post_only();
        $data = dp_get_post_data('E-1287');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1287', 'ID requis');
        $conn->prepare("DELETE FROM dispatch_intervention_attachments WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_vehicule_photo') {
        mdt_post_only();
        $data = dp_get_post_data('E-1288');
        if (!$data || empty($data['id']) || empty($data['photo_url'])) mdt_error(400, 'E-1288', 'ID et photo_url requis');
        $conn->prepare("UPDATE dispatch_intervention_vehicules SET photo_url = :url WHERE id = :id")
            ->execute(array(':url' => $data['photo_url'], ':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'start_pursuit') {
        mdt_post_only();
        $data = dp_get_post_data('E-1295');
        if (!$data || empty($data['vehicule_id']) || empty($data['discord_id'])) mdt_error(400, 'E-1295', 'vehicule_id et discord_id requis');

        $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $data['discord_id']));
        $roster = $r->fetch();
        if (!$roster) mdt_error(404, 'E-1296', 'Agent introuvable');

        $p = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid LIMIT 1");
        $p->execute(array(':rid' => $roster['id']));
        $pa = $p->fetch();
        if (!$pa) mdt_error(404, 'E-1297', 'Pas de patrouille');

        $assign = $conn->prepare("SELECT dva.position, dv.intervention_id FROM dispatch_vehicule_assignations dva JOIN dispatch_intervention_vehicules dv ON dv.id = dva.vehicule_id WHERE dva.vehicule_id = :vid AND dva.patrouille_id = :pid");
        $assign->execute(array(':vid' => $data['vehicule_id'], ':pid' => $pa['patrouille_id']));
        $assignRow = $assign->fetch();
        if (!$assignRow) mdt_error(404, 'E-1298', 'Assignation introuvable');

        $conn->beginTransaction();

        $conn->prepare("INSERT INTO dispatch_poursuite_log (intervention_id, vehicule_id, patrouille_id, position, depart_at) VALUES (:iid, :vid, :pid, :pos, NOW())")
            ->execute(array(':iid' => $assignRow['intervention_id'], ':vid' => $data['vehicule_id'], ':pid' => $pa['patrouille_id'], ':pos' => $assignRow['position']));
        $logId = $conn->lastInsertId();

        $interInfo = '';
        try {
            $ii = $conn->prepare("SELECT ab.code, ab.label, di.created_at FROM dispatch_interventions di LEFT JOIN dispatch_action_buttons ab ON ab.id = di.action_button_id WHERE di.id = :id");
            $ii->execute(array(':id' => $assignRow['intervention_id']));
            $iiRow = $ii->fetch();
            if ($iiRow) $interInfo = '10-56 Poursuite ' . ($iiRow['code'] ? $iiRow['code'] : '') . ' ' . substr($iiRow['created_at'] ?? '', 11, 5);
        } catch (PDOException $e) {}
        $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_intervention', statut_since = NOW(), info_sup = :inf WHERE id = :pid")
            ->execute(array(':inf' => $interInfo ?: '10-56 Poursuite en cours', ':pid' => $pa['patrouille_id']));

        $conn->commit();
        echo json_encode(array('success' => true, 'log_id' => (int)$logId), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'end_pursuit') {
        mdt_post_only();
        $data = dp_get_post_data('E-1299');
        if (!$data || empty($data['vehicule_id']) || empty($data['discord_id']) || empty($data['resultat'])) {
            mdt_error(400, 'E-1299', 'vehicule_id, discord_id et resultat requis');
        }

        $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $data['discord_id']));
        $roster = $r->fetch();
        if (!$roster) mdt_error(404, 'E-1296', 'Agent introuvable');

        $p = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid LIMIT 1");
        $p->execute(array(':rid' => $roster['id']));
        $pa = $p->fetch();

        $patrolId = $pa ? $pa['patrouille_id'] : null;
        $resultat = $data['resultat'];
        $transport = isset($data['transport_destination']) ? $data['transport_destination'] : null;

        $conn->beginTransaction();

        if ($patrolId) {
            $conn->prepare("UPDATE dispatch_poursuite_log SET fin_at = NOW(), resultat = :res, transport_destination = :dest WHERE vehicule_id = :vid AND patrouille_id = :pid AND fin_at IS NULL ORDER BY id DESC LIMIT 1")
                ->execute(array(':res' => $resultat, ':dest' => $transport, ':vid' => $data['vehicule_id'], ':pid' => $patrolId));
        }

        $vehStatut = ($resultat === 'interpelle') ? 'interpelle' : 'perdu';

        if ($patrolId) {
            $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE vehicule_id = :vid AND patrouille_id = :pid")
                ->execute(array(':vid' => $data['vehicule_id'], ':pid' => $patrolId));

            $otherVehs = $conn->prepare("SELECT COUNT(*) FROM dispatch_vehicule_assignations WHERE patrouille_id = :pid");
            $otherVehs->execute(array(':pid' => $patrolId));

            if ((int)$otherVehs->fetchColumn() === 0) {

                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")
                    ->execute(array(':pid' => $patrolId));

                if ($resultat === 'interpelle' && $transport) {

                    $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_intervention', statut_since = NOW(), info_sup = :inf WHERE id = :pid")
                        ->execute(array(':inf' => '10-15 Transport → ' . $transport, ':pid' => $patrolId));
                } else {

                    $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_disponible', statut_since = NOW(), info_sup = CASE WHEN info_sup LIKE '10-56 Poursuite%' THEN NULL ELSE info_sup END WHERE id = :pid")
                        ->execute(array(':pid' => $patrolId));
                }
            }
        }

        $remainAssigns = $conn->prepare("SELECT COUNT(*) FROM dispatch_vehicule_assignations WHERE vehicule_id = :vid");
        $remainAssigns->execute(array(':vid' => $data['vehicule_id']));
        if ((int)$remainAssigns->fetchColumn() === 0) {

            $conn->prepare("UPDATE dispatch_intervention_vehicules SET statut = :s WHERE id = :vid")
                ->execute(array(':s' => $vehStatut, ':vid' => $data['vehicule_id']));
        }

        $conn->commit();
        echo json_encode(array('success' => true, 'resultat' => $resultat), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'code4_vehicule') {
        mdt_post_only();
        $data = dp_get_post_data('E-1290');
        if (!$data || empty($data['vehicule_id'])) mdt_error(400, 'E-1290', 'vehicule_id requis');
        $statut = isset($data['statut']) ? $data['statut'] : 'interpelle';
        $vehId = $data['vehicule_id'];

        $callerPatrolId = null;
        if (!empty($data['discord_id'])) {
            $cr = $conn->prepare("SELECT r.id FROM roster r WHERE r.discord_id = :did");
            $cr->execute(array(':did' => $data['discord_id']));
            $cRoster = $cr->fetch();
            if ($cRoster) {
                $cp = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid LIMIT 1");
                $cp->execute(array(':rid' => $cRoster['id']));
                $cpRow = $cp->fetch();
                if ($cpRow) $callerPatrolId = $cpRow['patrouille_id'];
            }
        } elseif (!empty($data['patrouille_id'])) {
            $callerPatrolId = $data['patrouille_id'];
        }

        $conn->beginTransaction();

        $conn->prepare("UPDATE dispatch_intervention_vehicules SET statut = :s WHERE id = :id")
            ->execute(array(':s' => $statut, ':id' => $vehId));

        if ($callerPatrolId) {
            $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE vehicule_id = :vid AND patrouille_id = :pid")
                ->execute(array(':vid' => $vehId, ':pid' => $callerPatrolId));

            $otherVehs = $conn->prepare("SELECT COUNT(*) FROM dispatch_vehicule_assignations WHERE patrouille_id = :pid");
            $otherVehs->execute(array(':pid' => $callerPatrolId));
            if ((int)$otherVehs->fetchColumn() === 0) {
                $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_disponible', statut_since = NOW() WHERE id = :pid")
                    ->execute(array(':pid' => $callerPatrolId));
            }
        }

        $conn->commit();

        $veh = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_vehicules WHERE id = :id");
        $veh->execute(array(':id' => $vehId));
        $vRow = $veh->fetch();
        $allDone = false;
        if ($vRow) {
            $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_intervention_vehicules WHERE intervention_id = :iid AND (statut IS NULL OR statut = 'en_cours')");
            $cnt->execute(array(':iid' => $vRow['intervention_id']));
            $allDone = (int)$cnt->fetchColumn() === 0;
            dpInterUpdateEmpty($conn, $vRow['intervention_id']);
        }

        echo json_encode(array('success' => true, 'all_vehicles_done' => $allDone, 'intervention_id' => $vRow ? $vRow['intervention_id'] : null), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'code4_intervention') {
        mdt_post_only();
        $data = dp_get_post_data('E-1291');
        if (!$data || empty($data['intervention_id'])) mdt_error(400, 'E-1291', 'intervention_id requis');
        $motif = isset($data['motif']) ? $data['motif'] : 'Code 4';

        $conn->beginTransaction();

        $conn->prepare("UPDATE dispatch_interventions SET statut = 'termine', closed_at = NOW(), description = CONCAT(COALESCE(description,''), :motif) WHERE id = :id")
            ->execute(array(':id' => $data['intervention_id'], ':motif' => ' | [' . $motif . ']'));

        $pats = $conn->prepare("SELECT patrouille_id FROM dispatch_intervention_patrouilles WHERE intervention_id = :iid");
        $pats->execute(array(':iid' => $data['intervention_id']));
        foreach ($pats->fetchAll() as $patRow) {
            $conn->prepare("UPDATE dispatch_patrouilles SET statut_id = 'ds_disponible', statut_since = NOW() WHERE id = :pid")
                ->execute(array(':pid' => $patRow['patrouille_id']));
        }
        $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE intervention_id = :iid")
            ->execute(array(':iid' => $data['intervention_id']));

        $conn->prepare("DELETE FROM dispatch_vehicule_assignations WHERE vehicule_id IN (SELECT id FROM dispatch_intervention_vehicules WHERE intervention_id = :iid)")
            ->execute(array(':iid' => $data['intervention_id']));
        $conn->commit();

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'my_vehicle_assignment') {
        $discordId = isset($_GET['discord_id']) ? $_GET['discord_id'] : '';
        if (!$discordId) { echo json_encode(null); exit; }

        $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did");
        $r->execute(array(':did' => $discordId));
        $roster = $r->fetch();
        if (!$roster) { echo json_encode(null); exit; }

        $p = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid LIMIT 1");
        $p->execute(array(':rid' => $roster['id']));
        $pa = $p->fetch();
        if (!$pa) { echo json_encode(null); exit; }

        $assigns = $conn->prepare("
            SELECT dva.position, dv.id AS vehicule_id, dv.modele, dv.couleur, dv.immat, dv.nb_personnes, dv.armes, dv.photo_url, dv.statut AS veh_statut, di.type AS inter_type, di.lieu
            FROM dispatch_vehicule_assignations dva
            JOIN dispatch_intervention_vehicules dv ON dv.id = dva.vehicule_id
            JOIN dispatch_interventions di ON di.id = dv.intervention_id
            WHERE dva.patrouille_id = :pid AND di.statut != 'termine'
        ");
        $assigns->execute(array(':pid' => $pa['patrouille_id']));
        $result = $assigns->fetchAll();

        echo json_encode($result ?: array(), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_operations') {
        $rows = $conn->query("SELECT * FROM dispatch_operations ORDER BY ordre")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_operation') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        mdt_post_only();
        $data = dp_get_post_data('E-1400');
        if (!$data || empty($data['label'])) mdt_error(400, 'E-1400', 'Label requis');
        $id = !empty($data['id']) ? $data['id'] : 'op_' . uniqid();
        $stmt = $conn->prepare("INSERT INTO dispatch_operations (id, label, icon, color, frequence, ordre) VALUES (:id, :lab, :ico, :col, :frq, :ord) ON DUPLICATE KEY UPDATE label=VALUES(label), icon=VALUES(icon), color=VALUES(color), frequence=VALUES(frequence), ordre=VALUES(ordre)");
        $stmt->execute(array(
            ':id' => $id, ':lab' => $data['label'],
            ':ico' => isset($data['icon']) ? $data['icon'] : 'fa-briefcase',
            ':col' => isset($data['color']) ? $data['color'] : '#f59e0b',
            ':frq' => isset($data['frequence']) ? $data['frequence'] : null,
            ':ord' => isset($data['ordre']) ? (int)$data['ordre'] : 0
        ));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_operation') {
        requireDispatchAdmin($conn, $GLOBALS['DP_SELF_DID'], 'E-1700');
        mdt_post_only();
        $data = dp_get_post_data('E-1401');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1401', 'ID requis');

        $opStmt = $conn->prepare("SELECT label, frequence FROM dispatch_operations WHERE id = :id");
        $opStmt->execute(array(':id' => $data['id']));
        $opRow = $opStmt->fetch();
        $opLabel = $opRow ? $opRow['label'] : $data['id'];

        $agStmt = $conn->prepare("SELECT r.matricule, r.nom_prenom FROM dispatch_agent_operations dao JOIN roster r ON r.id = dao.roster_id WHERE dao.operation_id = :id");
        $agStmt->execute(array(':id' => $data['id']));
        $agentsInOp = array();
        foreach ($agStmt->fetchAll() as $ar) { $agentsInOp[] = $ar['matricule'] . ' | ' . $ar['nom_prenom']; }

        $conn->prepare("DELETE FROM dispatch_agent_operations WHERE operation_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM dispatch_operations WHERE id = :id")->execute(array(':id' => $data['id']));

        dpLog($conn, 'close_operation', array(
            'actor' => isset($data['discord_id']) ? $data['discord_id'] : null,
            'target_type' => 'operation',
            'target_id' => $data['id'],
            'target_label' => $opLabel,
            'details' => array(
                'frequence' => $opRow ? $opRow['frequence'] : null,
                'agents_count' => count($agentsInOp),
                'agents' => $agentsInOp,
                'method' => 'manual'
            )
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'join_operation') {
        mdt_post_only();
        $data = dp_get_post_data('E-1402');
        if (!$data || empty($data['operation_id']) || empty($data['roster_id'])) mdt_error(400, 'E-1402', 'operation_id et roster_id requis');
        $rid = (int)$data['roster_id'];
        $opId = $data['operation_id'];

        $conn->beginTransaction();

        $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $rid));

        $oldPat = $conn->prepare("SELECT patrouille_id FROM dispatch_patrouille_agents WHERE roster_id = :rid");
        $oldPat->execute(array(':rid' => $rid));
        $oldPatId = $oldPat->fetchColumn();
        if ($oldPatId) {
            $conn->prepare("DELETE FROM dispatch_patrouille_agents WHERE roster_id = :rid")->execute(array(':rid' => $rid));
            recalcIndicatif($conn, $oldPatId);

            $cnt = $conn->prepare("SELECT COUNT(*) FROM dispatch_patrouille_agents WHERE patrouille_id = :pid");
            $cnt->execute(array(':pid' => $oldPatId));
            if ((int)$cnt->fetchColumn() === 0) {
                $conn->prepare("DELETE FROM dispatch_intervention_patrouilles WHERE patrouille_id = :pid")->execute(array(':pid' => $oldPatId));
                $conn->prepare("DELETE FROM dispatch_patrouilles WHERE id = :pid")->execute(array(':pid' => $oldPatId));
            }
        }

        $conn->prepare("INSERT INTO dispatch_agent_operations (operation_id, roster_id) VALUES (:oid, :rid)")
            ->execute(array(':oid' => $opId, ':rid' => $rid));
        $conn->commit();

        $opLabel = null;
        try {
            $opStmt = $conn->prepare("SELECT label FROM dispatch_operations WHERE id = :id");
            $opStmt->execute(array(':id' => $opId));
            $opLabel = $opStmt->fetchColumn() ?: null;
        } catch (Exception $e) {}
        $logActor = isset($data['discord_id']) && $data['discord_id'] ? $data['discord_id'] : $rid;
        dpLog($conn, 'join_operation', array(
            'actor' => $logActor,
            'target_type' => 'operation',
            'target_id' => $opId,
            'target_label' => $opLabel,
            'details' => array('roster_id' => $rid, 'from_patrol' => $oldPatId)
        ));

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'leave_operation') {
        mdt_post_only();
        $data = dp_get_post_data('E-1403');
        if (!$data || empty($data['roster_id'])) mdt_error(400, 'E-1403', 'roster_id requis');
        $rid = (int)$data['roster_id'];

        $prevOpStmt = $conn->prepare("SELECT ao.operation_id, op.label FROM dispatch_agent_operations ao LEFT JOIN dispatch_operations op ON op.id = ao.operation_id WHERE ao.roster_id = :rid LIMIT 1");
        $prevOpStmt->execute(array(':rid' => $rid));
        $prevOp = $prevOpStmt->fetch();

        $conn->prepare("DELETE FROM dispatch_agent_operations WHERE roster_id = :rid")->execute(array(':rid' => $rid));

        if ($prevOp) {
            $logActor = isset($data['discord_id']) && $data['discord_id'] ? $data['discord_id'] : $rid;
            dpLog($conn, 'leave_operation', array(
                'actor' => $logActor,
                'target_type' => 'operation',
                'target_id' => $prevOp['operation_id'],
                'target_label' => $prevOp['label'],
                'details' => array('roster_id' => $rid)
            ));
        }

        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_wanted') {
        $persons = $conn->query("SELECT * FROM dispatch_wanted_persons ORDER BY created_at DESC")->fetchAll();
        $vehicles = $conn->query("SELECT * FROM dispatch_wanted_vehicles ORDER BY created_at DESC")->fetchAll();
        echo json_encode(array('persons' => $persons, 'vehicles' => $vehicles), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'add_wanted_person') {
        mdt_post_only();
        $data = dp_get_post_data('E-1410');
        if (!$data || empty($data['nom_prenom'])) mdt_error(400, 'E-1410', 'Nom requis');
        $id = 'wp_' . uniqid();
        $conn->prepare("INSERT INTO dispatch_wanted_persons (id, nom_prenom, photo_url, info_sup) VALUES (:id, :nom, :photo, :inf)")
            ->execute(array(':id' => $id, ':nom' => $data['nom_prenom'], ':photo' => isset($data['photo_url']) ? $data['photo_url'] : null, ':inf' => isset($data['info_sup']) ? $data['info_sup'] : null));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_wanted_person') {
        mdt_post_only();
        $data = dp_get_post_data('E-1411');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1411', 'ID requis');
        $conn->prepare("DELETE FROM dispatch_wanted_persons WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'add_wanted_vehicle') {
        mdt_post_only();
        $data = dp_get_post_data('E-1412');
        $id = 'wv_' . uniqid();
        $conn->prepare("INSERT INTO dispatch_wanted_vehicles (id, modele, couleur, immat, photo_url, proprio, info_sup) VALUES (:id, :mod, :coul, :imm, :photo, :prop, :inf)")
            ->execute(array(':id' => $id, ':mod' => isset($data['modele']) ? $data['modele'] : null, ':coul' => isset($data['couleur']) ? $data['couleur'] : null, ':imm' => isset($data['immat']) ? $data['immat'] : null, ':photo' => isset($data['photo_url']) ? $data['photo_url'] : null, ':prop' => isset($data['proprio']) ? $data['proprio'] : null, ':inf' => isset($data['info_sup']) ? $data['info_sup'] : null));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_wanted_vehicle') {
        mdt_post_only();
        $data = dp_get_post_data('E-1413');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1413', 'ID requis');
        $conn->prepare("DELETE FROM dispatch_wanted_vehicles WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_wanted_person_photo') {
        mdt_post_only();
        $data = dp_get_post_data('E-1414');
        if (!$data || empty($data['id']) || empty($data['photo_url'])) mdt_error(400, 'E-1414', 'ID et photo_url requis');
        $conn->prepare("UPDATE dispatch_wanted_persons SET photo_url = :url WHERE id = :id")->execute(array(':url' => $data['photo_url'], ':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_wanted_vehicle_photo') {
        mdt_post_only();
        $data = dp_get_post_data('E-1415');
        if (!$data || empty($data['id']) || empty($data['photo_url'])) mdt_error(400, 'E-1415', 'ID et photo_url requis');
        $conn->prepare("UPDATE dispatch_wanted_vehicles SET photo_url = :url WHERE id = :id")->execute(array(':url' => $data['photo_url'], ':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_wanted_person_field') {
        mdt_post_only();
        $data = dp_get_post_data('E-1416');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1416', 'ID requis');
        $sets = array(); $params = array(':id' => $data['id']);
        if (isset($data['nom_prenom'])) { $sets[] = 'nom_prenom = :np'; $params[':np'] = $data['nom_prenom']; }
        if (isset($data['info_sup'])) { $sets[] = 'info_sup = :inf'; $params[':inf'] = $data['info_sup']; }
        if (!empty($sets)) $conn->prepare("UPDATE dispatch_wanted_persons SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update_wanted_vehicle_field') {
        mdt_post_only();
        $data = dp_get_post_data('E-1417');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-1417', 'ID requis');
        $sets = array(); $params = array(':id' => $data['id']);
        if (isset($data['modele'])) { $sets[] = 'modele = :mod'; $params[':mod'] = $data['modele']; }
        if (isset($data['couleur'])) { $sets[] = 'couleur = :coul'; $params[':coul'] = $data['couleur']; }
        if (isset($data['immat'])) { $sets[] = 'immat = :imm'; $params[':imm'] = $data['immat']; }
        if (isset($data['proprio'])) { $sets[] = 'proprio = :prop'; $params[':prop'] = $data['proprio']; }
        if (isset($data['info_sup'])) { $sets[] = 'info_sup = :inf'; $params[':inf'] = $data['info_sup']; }
        if (!empty($sets)) $conn->prepare("UPDATE dispatch_wanted_vehicles SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_logs') {

        $actorDid = dpResolveActorDiscordId($conn);
        requireDispatchAdmin($conn, $actorDid, 'E-1710');
        $where = array(); $params = array();
        if (!empty($_GET['filter_action'])) { $where[] = 'action = :a'; $params[':a'] = $_GET['filter_action']; }
        if (!empty($_GET['filter_target'])) { $where[] = 'target_type = :tt'; $params[':tt'] = $_GET['filter_target']; }
        if (!empty($_GET['filter_target_id'])) { $where[] = 'target_id = :tid'; $params[':tid'] = $_GET['filter_target_id']; }
        if (!empty($_GET['filter_actor'])) {
            $where[] = '(actor_matricule = :am1 OR actor_discord_id = :am2 OR actor_name LIKE :amlike)';
            $params[':am1'] = $_GET['filter_actor'];
            $params[':am2'] = $_GET['filter_actor'];
            $params[':amlike'] = '%' . $_GET['filter_actor'] . '%';
        }
        if (!empty($_GET['date_from'])) { $where[] = 'created_at >= :df'; $params[':df'] = $_GET['date_from'] . ' 00:00:00'; }
        if (!empty($_GET['date_to'])) { $where[] = 'created_at <= :dt'; $params[':dt'] = $_GET['date_to'] . ' 23:59:59'; }

        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

        $sql = "SELECT id, created_at, actor_discord_id, actor_matricule, actor_name, action, target_type, target_id, target_label, details, ip
                FROM dispatch_logs";
        if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {

            if (!empty($r['actor_matricule']) && !empty($r['actor_name'])) {
                $r['actor_label'] = $r['actor_matricule'] . ' | ' . $r['actor_name'];
            } elseif (!empty($r['actor_name'])) {
                $r['actor_label'] = $r['actor_name'];
            } elseif (!empty($r['actor_matricule'])) {
                $r['actor_label'] = $r['actor_matricule'];
            } else {
                $r['actor_label'] = null;
            }
            if (!empty($r['details'])) {
                $decoded = json_decode($r['details'], true);
                if ($decoded !== null) $r['details'] = $decoded;
            }
        }
        unset($r);

        $countSql = "SELECT COUNT(*) FROM dispatch_logs";
        if (!empty($where)) $countSql .= ' WHERE ' . implode(' AND ', $where);
        $cs = $conn->prepare($countSql);
        foreach ($params as $k => $v) $cs->bindValue($k, $v);
        $cs->execute();
        $total = (int)$cs->fetchColumn();

        $availActions = array(); $availActors = array();
        if ($offset === 0) {
            $availActions = $conn->query("SELECT DISTINCT action FROM dispatch_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
            $seenActor = array();
            $arows = $conn->query("SELECT DISTINCT actor_discord_id, actor_matricule, actor_name FROM dispatch_logs ORDER BY (actor_name IS NULL), actor_name")->fetchAll();
            foreach ($arows as $a) {
                $val = !empty($a['actor_discord_id']) ? $a['actor_discord_id'] : (!empty($a['actor_matricule']) ? $a['actor_matricule'] : $a['actor_name']);
                if (!$val || isset($seenActor[$val])) continue;
                $seenActor[$val] = true;
                if (!empty($a['actor_matricule']) && !empty($a['actor_name'])) $label = $a['actor_matricule'] . ' | ' . $a['actor_name'];
                elseif (!empty($a['actor_name'])) $label = $a['actor_name'];
                elseif (!empty($a['actor_matricule'])) $label = $a['actor_matricule'];
                else $label = 'DID:' . $a['actor_discord_id'];
                $availActors[] = array('value' => $val, 'label' => $label);
            }
            usort($availActors, function($x, $y) { return strcasecmp($x['label'], $y['label']); });
        }

        echo json_encode(array(
            'logs' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($rows)) < $total,
            'available_actions' => $availActions,
            'available_actors' => $availActors
        ), JSON_UNESCAPED_UNICODE);
    }

    else {
        mdt_unknown_action();
    }

} catch (Exception $e) {

    try { dpLog($conn, 'error', array(
        'actor' => isset($_POST['discord_id']) ? $_POST['discord_id'] : (isset($_GET['discord_id']) ? $_GET['discord_id'] : null),
        'target_type' => 'action',
        'target_id' => $action,
        'details' => array('error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine())
    )); } catch (Exception $e2) {}
    mdt_error(500, 'E-1214', 'Erreur serveur dispatch', $e->getMessage());
}

$writeActions = array('save_patrouille','delete_patrouille','move_patrouille','join_patrol','leave_patrol',
    'save_intervention','close_intervention','update_intervention_quick','assign_patrouille','unassign_patrouille',
    'execute_action','start_service','end_service','force_end_service','mark_paid','set_dispatchers','create_patrol_auto',
    'code4_vehicule','code4_intervention','add_intervention_vehicule','delete_intervention_vehicule',
    'assign_vehicule_patrol','unassign_vehicule_patrol','save_statut','delete_statut',
    'join_operation','leave_operation','save_operation','delete_operation',
    'add_wanted_person','delete_wanted_person','add_wanted_vehicle','delete_wanted_vehicle',
    'update_patrol_field','remove_dispatch_queue','adjust_hours',
    'take_dispatch_role','leave_dispatch_role','join_supervision','leave_supervision');
if (in_array($action, $writeActions)) {

    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    broadcastUpdate('board_changed');
}
