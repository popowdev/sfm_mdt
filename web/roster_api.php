<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_config.php';
mdt_cors();
require_once __DIR__ . '/dispatch/dispatch_perms.php';

try {
    $conn->query("SELECT 1 FROM roster_blocked_matricules LIMIT 0");
} catch (PDOException $e) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS roster_blocked_matricules (
            matricule VARCHAR(10) PRIMARY KEY,
            reason VARCHAR(255) DEFAULT NULL,
            blocked_by VARCHAR(30) DEFAULT NULL,
            blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (PDOException $e2) {}
}

function roster_actor_is_dev($conn) {
    $did = function_exists('dpResolveActorDiscordId') ? dpResolveActorDiscordId($conn) : null;
    if (!$did) return false;
    try {
        $s = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = :d LIMIT 1");
        $s->execute(array(':d' => $did));
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}
function roster_norm_mat($m) {
    $n = (int)preg_replace('/\D/', '', (string)$m);
    if ($n < 1 || $n > 99) return null;
    return str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

try {
    $dirty = $conn->query("SELECT id, matricule, nom_prenom FROM roster WHERE nom_prenom REGEXP '^[0-9]{1,3} \\\\| '")->fetchAll();
    if (count($dirty) > 0) {
        $fix = $conn->prepare("UPDATE roster SET nom_prenom = :nom WHERE id = :id");
        foreach ($dirty as $d) {
            $clean = preg_replace('/^\d{1,3}\s*\|\s*/', '', $d['nom_prenom']);
            if ($clean !== $d['nom_prenom']) {
                $fix->execute(array(':nom' => $clean, ':id' => $d['id']));
            }
        }
        error_log("[MDT AUTO-FIX] Nettoyage " . count($dirty) . " nom_prenom avec prefixe matricule");
    }
} catch (PDOException $e) {}

$action = isset($_GET['action']) ? $_GET['action'] : '';
mdt_require_auth($conn, 'roster');

$DEFAULT_ROSTER = array();

function getRosterList($conn) {
    $stmt = $conn->query("SELECT matricule, nom_prenom FROM roster ORDER BY ordre, matricule");
    $rows = $stmt->fetchAll();
    $result = array();
    foreach ($rows as $row) {
        $result[] = $row['matricule'] . ' | ' . $row['nom_prenom'];
    }
    return $result;
}

function getRosterFull($conn) {
    $stmt = $conn->query("SELECT id, matricule, nom_prenom, discord_id FROM roster ORDER BY ordre, matricule");
    return $stmt->fetchAll();
}

function insertRosterArray($conn, $roster) {

    $existing = array();
    $stmt = $conn->query("SELECT matricule, discord_id FROM roster WHERE discord_id IS NOT NULL");
    foreach ($stmt->fetchAll() as $row) {
        $existing[$row['matricule']] = $row['discord_id'];
    }

    $conn->exec("DELETE FROM roster");

    $ins = $conn->prepare("INSERT INTO roster (matricule, nom_prenom, ordre, discord_id) VALUES (:mat, :nom, :ordre, :did)");

    foreach ($roster as $i => $entry) {
        if (!is_string($entry) || strpos($entry, ' | ') === false) continue;
        $parts = explode(' | ', $entry, 2);
        $mat = substr(trim($parts[0]), 0, 10);
        $nom = isset($parts[1]) ? substr(trim($parts[1]), 0, 255) : '';
        if ($mat === '') continue;
        $did = isset($existing[$mat]) ? $existing[$mat] : null;
        $ins->execute(array(':mat' => $mat, ':nom' => $nom, ':ordre' => $i, ':did' => $did));
    }
}

switch ($action) {
    case 'list':
        echo json_encode(getRosterList($conn), JSON_UNESCAPED_UNICODE);
        break;

    case 'save':
        mdt_require_admin($conn, 'roster_write');
        mdt_post_only();
        $data = mdt_get_post_data('E-300');
        if (!is_array($data)) {
            mdt_error(400, 'E-300', 'Donnees invalides: tableau attendu');
        }
        $conn->beginTransaction();
        try {
            insertRosterArray($conn, $data);
            $conn->commit();
            echo json_encode(array('success' => true));
        } catch (Exception $e) {
            $conn->rollBack();
            mdt_error(500, 'E-301', 'Erreur sauvegarde', $e->getMessage());
        }
        break;

    case 'reset':
        mdt_require_admin($conn, 'roster_write');
        mdt_post_only();
        $conn->beginTransaction();
        try {
            insertRosterArray($conn, $DEFAULT_ROSTER);
            $conn->commit();
            echo json_encode(array('success' => true));
        } catch (Exception $e) {
            $conn->rollBack();
            mdt_error(500, 'E-302', 'Erreur reinitialisation', $e->getMessage());
        }
        break;

    case 'list_full':
        echo json_encode(getRosterFull($conn), JSON_UNESCAPED_UNICODE);
        break;

    case 'save_agent':
        mdt_require_admin($conn, 'roster_write');
        mdt_post_only();
        $data = mdt_get_post_data('E-300');
        if (!$data || empty($data['matricule']) || empty($data['nom_prenom'])) {
            mdt_error(400, 'E-300', 'Matricule et nom requis');
        }
        $mat = substr(trim($data['matricule']), 0, 10);
        $nom = substr(trim($data['nom_prenom']), 0, 255);
        $did = !empty($data['discord_id']) ? $data['discord_id'] : null;

        $blk = $conn->prepare("SELECT reason FROM roster_blocked_matricules WHERE matricule = :m");
        $blk->execute(array(':m' => $mat));
        $brow = $blk->fetch();
        if ($brow) {
            $bmsg = 'Matricule ' . $mat . ' bloque';
            if (!empty($brow['reason'])) $bmsg .= ' : ' . $brow['reason'];
            mdt_error(403, 'E-310', $bmsg);
        }

        $matInt = (int)$mat;
        if ($matInt > 0 && preg_match('/^(\d{1,3})\s*[^A-Za-zÀ-ÿ0-9\s]+\s*(.+)$/u', $nom, $mm)) {
            if ((int)$mm[1] === $matInt) {
                $nom = trim($mm[2]);
            }
        }

        elseif ($matInt > 0 && preg_match('/^(\d{1,3})\s+[IlL]\s+(.+)$/u', $nom, $mm)) {
            if ((int)$mm[1] === $matInt) {
                $nom = trim($mm[2]);
            }
        }

        $nom = preg_replace('/\s{2,}/', ' ', $nom);

        try {
            $check = $conn->prepare("SELECT id FROM roster WHERE matricule = :mat");
            $check->execute(array(':mat' => $mat));

            if ($check->fetch()) {
                $stmt = $conn->prepare("UPDATE roster SET nom_prenom = :nom, discord_id = :did WHERE matricule = :mat");
                $stmt->execute(array(':nom' => $nom, ':did' => $did, ':mat' => $mat));
            } else {
                $maxOrdre = (int)$conn->query("SELECT COALESCE(MAX(ordre),0) FROM roster")->fetchColumn();
                $stmt = $conn->prepare("INSERT INTO roster (matricule, nom_prenom, discord_id, ordre) VALUES (:mat, :nom, :did, :ord)");
                $stmt->execute(array(':mat' => $mat, ':nom' => $nom, ':did' => $did, ':ord' => $maxOrdre + 1));
            }
            echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            mdt_error(500, 'E-303', 'Erreur sauvegarde agent', $e->getMessage());
        }
        break;

    case 'delete_agent':
        mdt_require_admin($conn, 'roster_write');
        mdt_post_only();
        $data = mdt_get_post_data('E-300');
        if (!$data || empty($data['matricule'])) mdt_error(400, 'E-300', 'Matricule requis');
        $conn->prepare("DELETE FROM roster WHERE matricule = :mat")->execute(array(':mat' => $data['matricule']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
        break;

    case 'list_legacy':

        $rows = $conn->query("SELECT matricule, nom_prenom FROM roster ORDER BY ordre, matricule")->fetchAll();
        echo json_encode(array_map(function($r){ return $r['matricule'] . ' | ' . $r['nom_prenom']; }, $rows), JSON_UNESCAPED_UNICODE);
        break;

    case 'list_unassigned':

        $rosterIds = $conn->query("SELECT discord_id FROM roster WHERE discord_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $allUsers = $conn->query("SELECT id, discord_id, discord_username, discord_nick, discord_avatar FROM users WHERE discord_id IS NOT NULL")->fetchAll();
        $result = array();
        foreach ($allUsers as $u) {
            if (!in_array($u['discord_id'], $rosterIds)) {
                $result[] = $u;
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'list_blocked':
        $rows = $conn->query("SELECT matricule, reason, blocked_at FROM roster_blocked_matricules ORDER BY matricule")->fetchAll();
        echo json_encode(array('blocked' => $rows, 'is_dev' => roster_actor_is_dev($conn)), JSON_UNESCAPED_UNICODE);
        break;

    case 'block_matricule':
        mdt_post_only();
        if (!roster_actor_is_dev($conn)) mdt_error(403, 'E-311', 'Reserve aux developpeurs');
        $data = mdt_get_post_data('E-300');
        $mat = isset($data['matricule']) ? roster_norm_mat($data['matricule']) : null;
        if (!$mat) mdt_error(400, 'E-312', 'Matricule invalide (01-99)');
        $reason = isset($data['reason']) ? substr(trim($data['reason']), 0, 255) : '';
        $did = dpResolveActorDiscordId($conn);
        $conn->prepare("INSERT INTO roster_blocked_matricules (matricule, reason, blocked_by) VALUES (:m, :r, :b)
                        ON DUPLICATE KEY UPDATE reason = :r2, blocked_by = :b2, blocked_at = CURRENT_TIMESTAMP")
             ->execute(array(':m' => $mat, ':r' => $reason, ':b' => $did, ':r2' => $reason, ':b2' => $did));
        echo json_encode(array('success' => true, 'matricule' => $mat, 'reason' => $reason), JSON_UNESCAPED_UNICODE);
        break;

    case 'unblock_matricule':
        mdt_post_only();
        if (!roster_actor_is_dev($conn)) mdt_error(403, 'E-311', 'Reserve aux developpeurs');
        $data = mdt_get_post_data('E-300');
        $mat = isset($data['matricule']) ? roster_norm_mat($data['matricule']) : null;
        if (!$mat) mdt_error(400, 'E-312', 'Matricule invalide');
        $conn->prepare("DELETE FROM roster_blocked_matricules WHERE matricule = :m")->execute(array(':m' => $mat));
        echo json_encode(array('success' => true, 'matricule' => $mat), JSON_UNESCAPED_UNICODE);
        break;

    default:
        mdt_unknown_action();
}
