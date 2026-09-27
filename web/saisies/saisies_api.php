<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('SAI_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
define('SAI_TEXT_MAX', 5000);
$SAI_TYPES = array('arme', 'drogue', 'objet');

function sai_gstr($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : ''; }
function sai_txt($v, $max) { return substr((string)$v, 0, $max); }

function sai_enrich(&$row) {
    $row['sd_data'] = (isset($row['sd_data']) && $row['sd_data']) ? json_decode($row['sd_data'], true) : null;
}

$action = sai_gstr('action');

try {
    $me = mdt_require_auth($conn, 'saisies');
    $meDid = isset($me['discord_id']) ? $me['discord_id'] : null;
    $isAdmin = mdt_is_admin($conn, $meDid);

    if ($action === 'bootstrap') {
        $rows = $conn->query("SELECT s.id, s.created_at, s.agent, s.date_saisie, s.type, s.motif, s.notes, s.poste,
                s.arme_modele, s.arme_serial, s.arme_accessoires, s.sd_analyse_id,
                s.drogue_variete, s.drogue_quantite, s.objet_description, s.objet_quantite, s.saisi_sur,
                s.vol_statut, s.vol_raison, s.vol_date, s.vol_agent,
                a.case_name AS sd_case_name, a.data AS sd_data
            FROM saisies s LEFT JOIN sd_analyses a ON s.sd_analyse_id = a.id
            ORDER BY s.created_at DESC")->fetchAll();
        foreach ($rows as &$row) sai_enrich($row);
        unset($row);
        $drogueTypes = $conn->query("SELECT id, nom, icon, couleur, aliases FROM drogue_types ORDER BY nom")->fetchAll();
        $sdArmes = $conn->query("SELECT id, case_name, author, created_at, data FROM sd_analyses WHERE evidence_type = 'ARME' ORDER BY created_at DESC")->fetchAll();
        foreach ($sdArmes as &$sa) { $sa['data'] = json_decode($sa['data'], true); if (!is_array($sa['data'])) $sa['data'] = array(); }
        unset($sa);
        $stats = array(
            'total'   => (int)$conn->query("SELECT COUNT(*) FROM saisies")->fetchColumn(),
            'armes'   => (int)$conn->query("SELECT COUNT(*) FROM saisies WHERE type='arme'")->fetchColumn(),
            'drogues' => (int)$conn->query("SELECT COUNT(*) FROM saisies WHERE type='drogue'")->fetchColumn(),
            'objets'  => (int)$conn->query("SELECT COUNT(*) FROM saisies WHERE type='objet'")->fetchColumn(),
            'volees'  => (int)$conn->query("SELECT COUNT(*) FROM saisies WHERE vol_statut='volee'")->fetchColumn(),
        );
        echo json_encode(array(
            'access' => true,
            'me'     => array('is_admin' => $isAdmin),
            'perms'  => array('create' => true, 'edit' => true, 'delete' => true, 'stolen' => true, 'drogue_manage' => true),
            'stats'  => $stats,
            'saisies' => $rows,
            'drogue_types' => $drogueTypes,
            'sd_armes' => $sdArmes,
        ), SAI_JSON);
    }

    elseif ($action === 'get') {
        $id = sai_gstr('id');
        if ($id === '') mdt_error(400, 'E-905', 'ID manquant');
        $st = $conn->prepare("SELECT s.id, s.created_at, s.agent, s.date_saisie, s.type, s.motif, s.notes, s.poste,
                s.arme_modele, s.arme_serial, s.arme_accessoires, s.sd_analyse_id,
                s.drogue_variete, s.drogue_quantite, s.objet_description, s.objet_quantite, s.saisi_sur,
                s.vol_statut, s.vol_raison, s.vol_date, s.vol_agent,
                a.case_name AS sd_case_name, a.data AS sd_data
            FROM saisies s LEFT JOIN sd_analyses a ON s.sd_analyse_id = a.id WHERE s.id = :id");
        $st->execute(array(':id' => $id));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-902', 'Saisie introuvable');
        sai_enrich($row);
        echo json_encode(array('success' => true, 'saisie' => $row), SAI_JSON);
    }

    elseif ($action === 'create') {
        mdt_post_only();
        $input = mdt_get_post_data('E-900');
        if (!$input || !isset($input['agent']) || !isset($input['type']) || !isset($input['motif'])) mdt_error(400, 'E-900', 'Donnees invalides: agent, type et motif requis');
        $type = $input['type'];
        if (!in_array($type, $GLOBALS['SAI_TYPES'], true)) mdt_error(400, 'E-900', 'Type invalide');
        $id = uniqid('sai_');
        $poste = (isset($input['poste']) && in_array($input['poste'], array('nord', 'sud'), true)) ? $input['poste'] : 'sud';
        $stmt = $conn->prepare("INSERT INTO saisies (id, created_at, agent, date_saisie, type, motif, notes, poste, arme_modele, arme_serial, arme_accessoires, sd_analyse_id, drogue_variete, drogue_quantite, objet_description, objet_quantite, saisi_sur) VALUES (:id, :cat, :agent, :ds, :type, :motif, :notes, :poste, :am, :as, :aa, :sd, :dv, :dq, :od, :oq, :ss)");
        $stmt->execute(sai_write_params($id, $type, $poste, $input, null));
        echo json_encode(array('success' => true, 'id' => $id));
    }

    elseif ($action === 'update') {
        mdt_post_only();
        $input = mdt_get_post_data('E-901');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-901', 'Donnees invalides: id requis');
        $chk = $conn->prepare("SELECT * FROM saisies WHERE id = :id");
        $chk->execute(array(':id' => $input['id']));
        $existing = $chk->fetch();
        if (!$existing) mdt_error(404, 'E-902', 'Saisie introuvable');

        $type = (isset($input['type']) && in_array($input['type'], $GLOBALS['SAI_TYPES'], true)) ? $input['type'] : $existing['type'];
        $poste = (isset($input['poste']) && in_array($input['poste'], array('nord', 'sud'), true)) ? $input['poste'] : (in_array($existing['poste'], array('nord', 'sud'), true) ? $existing['poste'] : 'sud');
        $stmt = $conn->prepare("UPDATE saisies SET agent=:agent, date_saisie=:ds, type=:type, motif=:motif, notes=:notes, poste=:poste, arme_modele=:am, arme_serial=:as, arme_accessoires=:aa, sd_analyse_id=:sd, drogue_variete=:dv, drogue_quantite=:dq, objet_description=:od, objet_quantite=:oq, saisi_sur=:ss WHERE id=:id");
        $stmt->execute(sai_write_params($input['id'], $type, $poste, $input, $existing));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'delete') {
        mdt_post_only();
        $input = mdt_get_post_data('E-903');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-903', 'ID manquant');
        $conn->prepare("DELETE FROM saisies WHERE id = :id")->execute(array(':id' => $input['id']));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'mark_stolen') {
        mdt_post_only();
        $input = mdt_get_post_data('E-920');
        if (!$input || !isset($input['id']) || !isset($input['raison']) || !trim((string)$input['raison'])) mdt_error(400, 'E-920', 'ID et raison requis');
        $agent = isset($input['agent']) ? sai_txt($input['agent'], 100) : '';
        $stmt = $conn->prepare("UPDATE saisies SET vol_statut='volee', vol_raison=:raison, vol_date=NOW(), vol_agent=:agent WHERE id=:id AND type='arme'");
        $stmt->execute(array(':raison' => sai_txt($input['raison'], SAI_TEXT_MAX), ':agent' => $agent, ':id' => $input['id']));
        echo json_encode(array('success' => true));
    }
    elseif ($action === 'unmark_stolen') {
        mdt_post_only();
        $input = mdt_get_post_data('E-921');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-921', 'ID requis');
        $conn->prepare("UPDATE saisies SET vol_statut=NULL, vol_raison=NULL, vol_date=NULL, vol_agent=NULL WHERE id=:id AND type='arme'")->execute(array(':id' => $input['id']));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'check_serial') {
        $serial = trim(sai_gstr('serial'));
        $excludeId = sai_gstr('exclude_id');
        if ($serial === '') { echo json_encode(array('duplicates' => array())); }
        else {
            $stmt = $conn->prepare("SELECT id, arme_modele, agent, date_saisie, vol_statut FROM saisies WHERE arme_serial = :serial AND arme_serial != '' AND id != :eid");
            $stmt->execute(array(':serial' => $serial, ':eid' => $excludeId));
            echo json_encode(array('duplicates' => $stmt->fetchAll()), SAI_JSON);
        }
    }

    elseif ($action === 'save_drogue_type') {
        mdt_post_only();
        $input = mdt_get_post_data('E-910');
        if (!$input || !isset($input['nom']) || !isset($input['icon']) || !isset($input['couleur'])) mdt_error(400, 'E-910', 'Donnees invalides: nom, icon et couleur requis');
        $id = (isset($input['id']) && $input['id']) ? substr((string)$input['id'], 0, 50) : 'dt_' . uniqid();
        $aliases = isset($input['aliases']) ? $input['aliases'] : '';
        $stmt = $conn->prepare("INSERT INTO drogue_types (id, nom, icon, couleur, aliases) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nom=VALUES(nom), icon=VALUES(icon), couleur=VALUES(couleur), aliases=VALUES(aliases)");
        $stmt->execute(array($id, sai_txt($input['nom'], 100), sai_txt($input['icon'], 50), sai_txt($input['couleur'], 20), sai_txt($aliases, 500)));
        echo json_encode(array('success' => true, 'id' => $id));
    }
    elseif ($action === 'delete_drogue_type') {
        mdt_post_only();
        $input = mdt_get_post_data('E-911');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-911', 'ID manquant');
        $conn->prepare("DELETE FROM drogue_types WHERE id = ?")->execute(array($input['id']));
        echo json_encode(array('success' => true));
    }

    else { mdt_unknown_action(); }

} catch (Exception $e) {
    mdt_error(500, 'E-904', 'Erreur serveur', $e->getMessage());
}

function sai_write_params($id, $type, $poste, $input, $existing) {
    $ex = is_array($existing) ? $existing : null;

    $common = function($k, $col, $max, $default) use ($input, $ex) {
        if (isset($input[$k])) return sai_txt($input[$k], $max);
        return ($ex && isset($ex[$col])) ? $ex[$col] : $default;
    };

    $typed = function($want, $k, $col, $max) use ($type, $input, $ex) {
        if ($type !== $want) return null;
        if (isset($input[$k])) { return ($input[$k] === '') ? null : sai_txt($input[$k], $max); }
        return ($ex && isset($ex[$col])) ? $ex[$col] : null;
    };
    if (isset($input['date_saisie']) && $input['date_saisie']) $ds = substr((string)$input['date_saisie'], 0, 10);
    elseif ($ex && !empty($ex['date_saisie'])) $ds = $ex['date_saisie'];
    else $ds = date('Y-m-d');
    if (isset($input['notes'])) $notes = ($input['notes'] !== '') ? sai_txt($input['notes'], SAI_TEXT_MAX) : null;
    else $notes = $ex ? $ex['notes'] : null;
    if (isset($input['saisi_sur'])) $saisiSur = ($input['saisi_sur'] !== '') ? sai_txt($input['saisi_sur'], 120) : null;
    else $saisiSur = $ex ? $ex['saisi_sur'] : null;
    $p = array(
        ':id'    => $id,
        ':agent' => $common('agent', 'agent', 100, ''),
        ':ds'    => $ds,
        ':type'  => $type,
        ':motif' => $common('motif', 'motif', SAI_TEXT_MAX, ''),
        ':notes' => $notes,
        ':poste' => $poste,
        ':am'    => $typed('arme', 'arme_modele', 'arme_modele', 255),
        ':as'    => $typed('arme', 'arme_serial', 'arme_serial', 100),
        ':aa'    => $typed('arme', 'arme_accessoires', 'arme_accessoires', 500),
        ':sd'    => $typed('arme', 'sd_analyse_id', 'sd_analyse_id', 50),
        ':dv'    => $typed('drogue', 'drogue_variete', 'drogue_variete', 255),
        ':dq'    => $typed('drogue', 'drogue_quantite', 'drogue_quantite', 100),
        ':od'    => $typed('objet', 'objet_description', 'objet_description', 500),
        ':oq'    => $typed('objet', 'objet_quantite', 'objet_quantite', 100),
        ':ss'    => $saisiSur,
    );
    if ($ex === null) $p[':cat'] = date('Y-m-d H:i:s');
    return $p;
}
