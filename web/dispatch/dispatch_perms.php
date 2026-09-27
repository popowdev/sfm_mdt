<?php

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../mdt_roles.php';
if (!defined('MDT_ADMIN_DISCORD_ROLES')) {

    define('MDT_ADMIN_DISCORD_ROLES', json_encode(MDT_ADMIN_ROLE_IDS));
}

function dpResolveActorDiscordId($conn, $bodyData = null) {
    $token = '';
    $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$authHeader && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) $authHeader = $headers['Authorization'];
    }
    if (strpos($authHeader, 'Bearer ') === 0) $token = substr($authHeader, 7);

    if ($token) {
        try {
            $stmt = $conn->prepare("SELECT u.discord_id FROM user_sessions s
                                    JOIN users u ON u.id = s.user_id
                                    WHERE s.token = :t AND s.expires_at > NOW() LIMIT 1");
            $stmt->execute(array(':t' => $token));
            $did = $stmt->fetchColumn();
            if ($did) return $did;
        } catch (Exception $e) {
            error_log('[dispatch_perms] Token lookup error: ' . $e->getMessage());
        }
    }

    if (is_array($bodyData) && !empty($bodyData['discord_id'])) {
        if (function_exists('mdt_seclog')) {
            mdt_seclog($conn, 'body_discord_id_spoof', 'high', array('claimed_discord_id' => (string)$bodyData['discord_id']));
        }
    }

    return null;
}

function dpGetActorContext($conn, $discordId) {
    static $cache = array();
    if (!$discordId) return null;
    if (array_key_exists($discordId, $cache)) return $cache[$discordId];

    try {
        $stmt = $conn->prepare("SELECT u.id AS user_id, u.discord_roles, r.id AS roster_id
                                FROM users u
                                LEFT JOIN roster r ON r.discord_id = u.discord_id
                                WHERE u.discord_id = :did LIMIT 1");
        $stmt->execute(array(':did' => $discordId));
        $row = $stmt->fetch();
    } catch (Exception $e) {
        error_log('[dispatch_perms] actor context: ' . $e->getMessage());
        $cache[$discordId] = null;
        return null;
    }

    if (!$row) {

        try {
            $r = $conn->prepare("SELECT id FROM roster WHERE discord_id = :did LIMIT 1");
            $r->execute(array(':did' => $discordId));
            $rid = $r->fetchColumn();
            if ($rid) {
                $ctx = array('user_id' => null, 'roster_id' => (int)$rid, 'discord_id' => $discordId, 'discord_roles' => array());
                $cache[$discordId] = $ctx;
                return $ctx;
            }
        } catch (Exception $e) {}
        $cache[$discordId] = null;
        return null;
    }

    $roles = $row['discord_roles'] ? json_decode($row['discord_roles'], true) : array();
    if (!is_array($roles)) $roles = array();

    $ctx = array(
        'user_id'        => (int)$row['user_id'],
        'roster_id'      => $row['roster_id'] ? (int)$row['roster_id'] : null,
        'discord_id'     => $discordId,
        'discord_roles'  => $roles,
    );
    $cache[$discordId] = $ctx;
    return $ctx;
}

function isDispatchAdmin($conn, $discordId) {
    if (!$discordId) return false;

    static $dpAdminCache = array();
    if (array_key_exists($discordId, $dpAdminCache)) return $dpAdminCache[$discordId];

    $dpAdminCache[$discordId] = false;

    try {
        $dv = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = :did LIMIT 1");
        $dv->execute(array(':did' => $discordId));
        if ($dv->fetch()) return $dpAdminCache[$discordId] = true;
    } catch (Exception $e) {}

    $ctx = dpGetActorContext($conn, $discordId);
    if (!$ctx) return false;

    $adminRoles = json_decode(MDT_ADMIN_DISCORD_ROLES, true);
    foreach ($adminRoles as $rid) {
        if (in_array($rid, $ctx['discord_roles'], true)) return $dpAdminCache[$discordId] = true;
    }

    if ($ctx['roster_id']) {
        try {

            $stmt = $conn->prepare("SELECT 1 FROM dispatch_dispatchers WHERE roster_id = :rid AND role IN ('dispatcher','co_dispatcher') LIMIT 1");
            $stmt->execute(array(':rid' => $ctx['roster_id']));
            if ($stmt->fetch()) return $dpAdminCache[$discordId] = true;
        } catch (Exception $e) {}
    }
    return false;
}

function canEditPatrouille($conn, $discordId, $patrouilleId) {
    if (!$patrouilleId) return false;
    if (isDispatchAdmin($conn, $discordId)) return true;

    $ctx = dpGetActorContext($conn, $discordId);
    if (!$ctx || !$ctx['roster_id']) return false;

    try {
        $stmt = $conn->prepare("SELECT 1 FROM dispatch_patrouille_agents
                                WHERE patrouille_id = :pid AND roster_id = :rid LIMIT 1");
        $stmt->execute(array(':pid' => $patrouilleId, ':rid' => $ctx['roster_id']));
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function canEditIntervention($conn, $discordId, $interventionId) {
    if (!$interventionId) return false;
    if (isDispatchAdmin($conn, $discordId)) return true;

    $ctx = dpGetActorContext($conn, $discordId);
    if (!$ctx || !$ctx['roster_id']) return false;

    try {
        $stmt = $conn->prepare(
            "SELECT 1 FROM dispatch_intervention_patrouilles dip
             JOIN dispatch_patrouille_agents dpa ON dpa.patrouille_id = dip.patrouille_id
             WHERE dip.intervention_id = :iid AND dpa.roster_id = :rid LIMIT 1");
        $stmt->execute(array(':iid' => $interventionId, ':rid' => $ctx['roster_id']));
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function dpInterventionIdFromVehicule($conn, $vehiculeId) {
    if (!$vehiculeId) return null;
    try {
        $stmt = $conn->prepare("SELECT intervention_id FROM dispatch_intervention_vehicules WHERE id = :id LIMIT 1");
        $stmt->execute(array(':id' => $vehiculeId));
        return $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function requireDispatchAdmin($conn, $discordId, $errCode = 'E-1700') {
    if (!$discordId) mdt_error(401, $errCode, 'Authentification requise');
    if (!isDispatchAdmin($conn, $discordId)) {
        mdt_error(403, $errCode, 'Acces refuse: privileges admin dispatch requis');
    }
}

function requirePatrolEdit($conn, $discordId, $patrouilleId, $errCode = 'E-1701') {
    if (!$discordId) mdt_error(401, $errCode, 'Authentification requise');
    if (!canEditPatrouille($conn, $discordId, $patrouilleId)) {
        mdt_error(403, $errCode, 'Acces refuse: vous n etes pas membre de cette patrouille');
    }
}

function requireInterventionEdit($conn, $discordId, $interventionId, $errCode = 'E-1702') {
    if (!$discordId) mdt_error(401, $errCode, 'Authentification requise');
    if (!canEditIntervention($conn, $discordId, $interventionId)) {
        mdt_error(403, $errCode, 'Acces refuse: cette intervention n est pas la votre');
    }
}

function requireAuth($conn, $discordId, $errCode = 'E-1704') {
    if (!$discordId) mdt_error(401, $errCode, 'Authentification requise');
    $ctx = dpGetActorContext($conn, $discordId);
    if (!$ctx) mdt_error(401, $errCode, 'Acteur non resolu');
}

function requireSelfOrAdmin($conn, $discordId, $targetRosterId, $errCode = 'E-1703') {
    if (!$discordId) mdt_error(401, $errCode, 'Authentification requise');
    if (isDispatchAdmin($conn, $discordId)) return;
    $ctx = dpGetActorContext($conn, $discordId);
    if (!$ctx || $ctx['roster_id'] !== (int)$targetRosterId) {
        mdt_error(403, $errCode, 'Acces refuse: action reservee a soi-meme ou admin');
    }
}

