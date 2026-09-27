<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('PL_UPLOAD_DIR', __DIR__ . '/uploads/');
define('PL_UPLOAD_URL', '/plainte/uploads/');
define('PL_MAX_SIZE', 10 * 1024 * 1024);
define('PL_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
define('PL_SEARCH_LIMIT', 100);
define('PL_MAX_ITEMS', 100);
define('PL_MAX_PLAIGNANTS', 30);
define('PL_MAX_ATTACH', 30);

$PL_ALLOWED = array(
    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
    'image/webp' => 'webp', 'application/pdf' => 'pdf'
);

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS plainte_attachments (
        id VARCHAR(50) PRIMARY KEY,
        plainte_id VARCHAR(50) DEFAULT NULL,
        stored_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) DEFAULT NULL,
        mime VARCHAR(100) DEFAULT NULL,
        size INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_plainte (plainte_id)
    )");
} catch (PDOException $e) {}

function pl_gstr($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : ''; }

function pl_extract_plaignants($input) {
    $out = array();
    if (is_array($input) && isset($input['plaignants']) && is_array($input['plaignants'])) {
        foreach ($input['plaignants'] as $x) {
            if (count($out) >= PL_MAX_PLAIGNANTS) break;
            if (!is_array($x)) continue;
            $nom = trim((string)(isset($x['nom']) ? $x['nom'] : ''));
            if ($nom === '') continue;
            $out[] = array(
                'nom' => substr($nom, 0, 255),
                'tel' => substr(trim((string)(isset($x['tel']) ? $x['tel'] : '')), 0, 30),
                'mdt' => (isset($x['mdt']) && $x['mdt'] === 'Oui') ? 'Oui' : 'Non'
            );
        }
    }
    return $out;
}

function pl_norm_individus($v) {
    $out = array();
    if (is_array($v)) foreach ($v as $x) { if (count($out) >= PL_MAX_ITEMS) break; $s = trim((string)$x); if ($s !== '') $out[] = substr($s, 0, 200); }
    return $out;
}
function pl_norm_faits($v) {
    $out = array();
    if (is_array($v)) foreach ($v as $f) {
        if (count($out) >= PL_MAX_ITEMS) break;
        if (!is_array($f)) continue;
        $out[] = array(
            'cat'   => substr(trim((string)(isset($f['cat']) ? $f['cat'] : '')), 0, 40),
            'code'  => substr(trim((string)(isset($f['code']) ? $f['code'] : '')), 0, 40),
            'label' => substr(trim((string)(isset($f['label']) ? $f['label'] : '')), 0, 255),
        );
    }
    return $out;
}

function pl_enrich(&$row, $tagMap, $attMap) {
    $row['individus'] = pl_norm_individus(json_decode(isset($row['individus']) ? $row['individus'] : '', true));
    $row['faits'] = pl_norm_faits(json_decode(isset($row['faits']) ? $row['faits'] : '', true));
    $row['tags'] = isset($tagMap[$row['id']]) ? $tagMap[$row['id']] : array();
    $row['tag_ids'] = array();
    foreach ($row['tags'] as $t) $row['tag_ids'][] = $t['id'];
    $row['attachments'] = isset($attMap[$row['id']]) ? $attMap[$row['id']] : array();
    $pl = (isset($row['plaignants']) && $row['plaignants']) ? json_decode($row['plaignants'], true) : null;
    if (!is_array($pl) || !count($pl)) {
        $pl = array(array('nom' => $row['pe_prenom_nom'], 'tel' => $row['pe_telephone'], 'mdt' => $row['pe_verif_mdt']));
    }
    $row['plaignants'] = $pl;
    unset($row['discord_thread_id']);
}

function pl_load_tagmap($conn) {
    $st = $conn->query("SELECT a.plainte_id, t.id, t.name, t.color, t.icon FROM plainte_tag_assignments a JOIN plainte_tags t ON a.tag_id = t.id ORDER BY t.ordre");
    $map = array();
    foreach ($st->fetchAll() as $r) {
        $pid = $r['plainte_id'];
        if (!isset($map[$pid])) $map[$pid] = array();
        $map[$pid][] = array('id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'icon' => $r['icon']);
    }
    return $map;
}
function pl_load_attmap($conn) {
    $st = $conn->query("SELECT id, plainte_id, stored_name, original_name, mime, size FROM plainte_attachments WHERE plainte_id IS NOT NULL ORDER BY created_at");
    $map = array();
    foreach ($st->fetchAll() as $a) {
        $pid = $a['plainte_id'];
        if (!isset($map[$pid])) $map[$pid] = array();
        $map[$pid][] = array(
            'id' => $a['id'], 'url' => PL_UPLOAD_URL . $a['stored_name'],
            'original_name' => $a['original_name'], 'mime' => $a['mime'], 'size' => (int)$a['size']
        );
    }
    return $map;
}

function pl_norm_name($s) {
    $s = (string)$s;
    if (function_exists('normalizer_normalize')) {
        $n = normalizer_normalize($s, defined('Normalizer::FORM_D') ? Normalizer::FORM_D : 4);
        if ($n !== false && $n !== null) $s = preg_replace('/\p{Mn}/u', '', $n);
    }
    $s = strtr($s, array('à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u','ÿ'=>'y','ñ'=>'n'));
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return preg_replace('/\s+/', ' ', trim($s));
}

function pl_name_variants($s) {
    $s = (string)$s; $out = array();
    $full = pl_norm_name($s); if ($full !== '') $out[$full] = true;
    $pipe = strpos($s, '|');
    if ($pipe !== false) { $part = pl_norm_name(substr($s, $pipe + 1)); if ($part !== '') $out[$part] = true; }
    return $out;
}
function pl_my_identity($conn, $did) {
    $names = array();
    if ($did) {
        try {
            $q = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = :d");
            $q->execute(array(':d' => $did));
            foreach ($q->fetchAll() as $r) {
                $full = ($r['matricule'] ? $r['matricule'] . ' | ' : '') . $r['nom_prenom'];
                foreach (pl_name_variants($full) as $k => $_) $names[$k] = true;
                foreach (pl_name_variants($r['nom_prenom']) as $k => $_) $names[$k] = true;
            }
        } catch (Exception $e) {}
    }
    return array('did' => $did, 'names' => $names);
}
function pl_display_name($conn, $did) {
    if (!$did) return '';
    try {
        $q = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = :d LIMIT 1");
        $q->execute(array(':d' => $did));
        $r = $q->fetch();
        if ($r) return substr(($r['matricule'] ? $r['matricule'] . ' | ' : '') . $r['nom_prenom'], 0, 100);
    } catch (Exception $e) {}
    try {
        $q = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(username,''), '') FROM users WHERE discord_id = :d LIMIT 1");
        $q->execute(array(':d' => $did));
        $n = $q->fetchColumn();
        if ($n) return substr((string)$n, 0, 100);
    } catch (Exception $e) {}
    return '';
}
function pl_is_mine($row, $ident) {
    if (!empty($row['handler_id']) && $ident['did'] && (string)$row['handler_id'] === (string)$ident['did']) return true;
    if (!$ident['names']) return false;
    foreach (pl_name_variants(isset($row['redacteur']) ? $row['redacteur'] : '') as $k => $_) {
        if (isset($ident['names'][$k])) return true;
    }
    return false;
}

$action = pl_gstr('action');

try {

    $me = mdt_require_auth($conn, 'plainte');

    mdt_require_module($conn, 'plainte', 'plainte_api');
    $meDid = isset($me['discord_id']) ? $me['discord_id'] : null;
    $isAdmin = mdt_is_admin($conn, $meDid);

    if ($action === 'bootstrap') {
        $ident = pl_my_identity($conn, $meDid);
        $tagMap = pl_load_tagmap($conn);
        $attMap = pl_load_attmap($conn);
        $tags = $conn->query("SELECT id, name, color, icon, ordre, is_closed FROM plainte_tags ORDER BY ordre, name")->fetchAll();
        $closedTagIds = array();
        foreach ($tags as &$t) { $t['is_closed'] = (int)$t['is_closed']; if ($t['is_closed']) $closedTagIds[$t['id']] = true; }
        unset($t);
        $rows = $conn->query("SELECT * FROM plaintes ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as &$row) {
            pl_enrich($row, $tagMap, $attMap);
            $closed = false;
            foreach ($row['tag_ids'] as $tid) { if (isset($closedTagIds[$tid])) { $closed = true; break; } }
            $row['closed'] = $closed;
            $row['mine'] = pl_is_mine($row, $ident);
            $row['handled_by_me'] = (!empty($row['handler_id']) && $meDid && (string)$row['handler_id'] === (string)$meDid);
            unset($row['handler_id']);
        }
        unset($row);
        echo json_encode(array(
            'access'   => true,
            'me'       => array('user_id' => isset($me['user_id']) ? (int)$me['user_id'] : null, 'is_admin' => $isAdmin, 'has_identity' => !empty($ident['names'])),
            'perms'    => array('create' => true, 'edit' => true, 'delete' => $isAdmin, 'tag_manage' => true),
            'plaintes' => $rows,
            'tags'     => $tags,
        ), PL_JSON);
    }

    elseif ($action === 'aux') {
        $rlist = array(); $penal = array();
        try {
            foreach ($conn->query("SELECT matricule, nom_prenom FROM roster ORDER BY ordre, matricule")->fetchAll() as $r) {
                $rlist[] = ($r['matricule'] ? $r['matricule'] . ' | ' : '') . $r['nom_prenom'];
            }
        } catch (Exception $e) {}
        try { $penal = $conn->query("SELECT code, cat, label FROM penal_code ORDER BY cat, code")->fetchAll(); } catch (Exception $e) {}
        $suspects = array();
        try { $suspects = $conn->query("SELECT name FROM suspects ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
        echo json_encode(array('success' => true, 'roster' => $rlist, 'penal' => $penal, 'suspects' => $suspects), PL_JSON);
    }

    elseif ($action === 'take') {
        mdt_post_only();
        $input = mdt_get_post_data('E-840');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-840', 'ID manquant');
        $chk = $conn->prepare("SELECT id FROM plaintes WHERE id = :id");
        $chk->execute(array(':id' => $input['id']));
        if (!$chk->fetch()) mdt_error(404, 'E-840', 'Plainte introuvable');
        $name = pl_display_name($conn, $meDid);
        $conn->prepare("UPDATE plaintes SET handler_id = :h, handler_name = :n, handler_at = NOW() WHERE id = :id")
             ->execute(array(':h' => $meDid, ':n' => $name, ':id' => $input['id']));
        echo json_encode(array('success' => true, 'handler_name' => $name), PL_JSON);
    }
    elseif ($action === 'release') {
        mdt_post_only();
        $input = mdt_get_post_data('E-841');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-841', 'ID manquant');
        $cur = $conn->prepare("SELECT handler_id FROM plaintes WHERE id = :id");
        $cur->execute(array(':id' => $input['id']));
        $row = $cur->fetch();
        if (!$row) mdt_error(404, 'E-841', 'Plainte introuvable');

        if (!empty($row['handler_id']) && (string)$row['handler_id'] !== (string)$meDid && !$isAdmin) {
            mdt_error(403, 'E-841', 'Seul le responsable ou un admin peut lacher cette plainte');
        }
        $conn->prepare("UPDATE plaintes SET handler_id = NULL, handler_name = NULL, handler_at = NULL WHERE id = :id")->execute(array(':id' => $input['id']));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'get') {
        $id = pl_gstr('id');
        if ($id === '') mdt_error(400, 'E-830', 'ID manquant');
        $st = $conn->prepare("SELECT * FROM plaintes WHERE id = :id");
        $st->execute(array(':id' => $id));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-831', 'Plainte introuvable');
        pl_enrich($row, pl_load_tagmap($conn), pl_load_attmap($conn));
        $ident = pl_my_identity($conn, $meDid);
        $closedTagIds = array();
        foreach ($conn->query("SELECT id FROM plainte_tags WHERE is_closed = 1")->fetchAll(PDO::FETCH_COLUMN) as $cid) $closedTagIds[$cid] = true;
        $closed = false; foreach ($row['tag_ids'] as $tid) { if (isset($closedTagIds[$tid])) { $closed = true; break; } }
        $row['closed'] = $closed;
        $row['handled_by_me'] = (!empty($row['handler_id']) && $meDid && (string)$row['handler_id'] === (string)$meDid);
        $row['mine'] = pl_is_mine($row, $ident);
        unset($row['handler_id']);
        echo json_encode(array('success' => true, 'plainte' => $row), PL_JSON);
    }

    elseif ($action === 'create') {
        mdt_post_only();
        $input = mdt_get_post_data('E-800');
        $plaignants = pl_extract_plaignants($input);
        if (count($plaignants)) {
            $input['pe_prenom_nom'] = $plaignants[0]['nom'];
            $input['pe_telephone'] = $plaignants[0]['tel'];
            $input['pe_verif_mdt'] = $plaignants[0]['mdt'];
        }
        if (!$input || !isset($input['redacteur']) || !isset($input['pe_prenom_nom']) || !isset($input['deroule_faits'])) {
            mdt_error(400, 'E-800', 'Donnees invalides: redacteur, plaignant et deroule_faits requis');
        }
        if (!count($plaignants)) {
            $plaignants = array(array('nom' => substr((string)$input['pe_prenom_nom'], 0, 255), 'tel' => isset($input['pe_telephone']) ? substr((string)$input['pe_telephone'], 0, 30) : '', 'mdt' => (isset($input['pe_verif_mdt']) && $input['pe_verif_mdt'] === 'Oui') ? 'Oui' : 'Non'));
        }
        $id = uniqid('pl_');
        $stmt = $conn->prepare("INSERT INTO plaintes (id, created_at, redacteur, grade_redacteur, date_plainte, pe_prenom_nom, pe_telephone, pe_verif_mdt, deroule_faits, individus, faits, plaignants) VALUES (:id, :created_at, :redacteur, :grade_redacteur, :date_plainte, :pe_prenom_nom, :pe_telephone, :pe_verif_mdt, :deroule_faits, :individus, :faits, :plaignants)");
        $stmt->execute(array(
            ':id' => $id,
            ':created_at' => date('Y-m-d H:i:s'),
            ':redacteur' => substr($input['redacteur'], 0, 100),
            ':grade_redacteur' => isset($input['grade_redacteur']) ? substr($input['grade_redacteur'], 0, 50) : '',
            ':date_plainte' => (isset($input['date_plainte']) && $input['date_plainte']) ? str_replace('T', ' ', substr($input['date_plainte'], 0, 19)) : date('Y-m-d H:i:s'),
            ':pe_prenom_nom' => substr($input['pe_prenom_nom'], 0, 255),
            ':pe_telephone' => isset($input['pe_telephone']) ? substr($input['pe_telephone'], 0, 30) : '',
            ':pe_verif_mdt' => isset($input['pe_verif_mdt']) ? substr($input['pe_verif_mdt'], 0, 10) : 'Non',
            ':deroule_faits' => substr((string)$input['deroule_faits'], 0, 20000),
            ':individus' => json_encode(pl_norm_individus(isset($input['individus']) ? $input['individus'] : array()), PL_JSON),
            ':faits' => json_encode(pl_norm_faits(isset($input['faits']) ? $input['faits'] : array()), PL_JSON),
            ':plaignants' => json_encode($plaignants, PL_JSON)
        ));
        pl_link_attachments($conn, $id, $input);
        echo json_encode(array('success' => true, 'id' => $id));
    }

    elseif ($action === 'update') {
        mdt_post_only();
        $input = mdt_get_post_data('E-800');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-800', 'Donnees invalides: id requis');
        $plaignants = pl_extract_plaignants($input);
        if (count($plaignants)) {
            $input['pe_prenom_nom'] = $plaignants[0]['nom'];
            $input['pe_telephone'] = $plaignants[0]['tel'];
            $input['pe_verif_mdt'] = $plaignants[0]['mdt'];
        } else {
            $plaignants = array(array('nom' => isset($input['pe_prenom_nom']) ? substr((string)$input['pe_prenom_nom'], 0, 255) : '', 'tel' => isset($input['pe_telephone']) ? substr((string)$input['pe_telephone'], 0, 30) : '', 'mdt' => (isset($input['pe_verif_mdt']) && $input['pe_verif_mdt'] === 'Oui') ? 'Oui' : 'Non'));
        }
        $check = $conn->prepare("SELECT id FROM plaintes WHERE id = :id");
        $check->execute(array(':id' => $input['id']));
        if (!$check->fetch()) mdt_error(404, 'E-801', 'Plainte introuvable');
        $stmt = $conn->prepare("UPDATE plaintes SET redacteur = :redacteur, grade_redacteur = :grade_redacteur, date_plainte = :date_plainte, pe_prenom_nom = :pe_prenom_nom, pe_telephone = :pe_telephone, pe_verif_mdt = :pe_verif_mdt, deroule_faits = :deroule_faits, individus = :individus, faits = :faits, plaignants = :plaignants WHERE id = :id");
        $stmt->execute(array(
            ':redacteur' => isset($input['redacteur']) ? substr($input['redacteur'], 0, 100) : '',
            ':grade_redacteur' => isset($input['grade_redacteur']) ? substr($input['grade_redacteur'], 0, 50) : '',
            ':date_plainte' => (isset($input['date_plainte']) && $input['date_plainte']) ? str_replace('T', ' ', substr($input['date_plainte'], 0, 19)) : date('Y-m-d H:i:s'),
            ':pe_prenom_nom' => isset($input['pe_prenom_nom']) ? substr($input['pe_prenom_nom'], 0, 255) : '',
            ':pe_telephone' => isset($input['pe_telephone']) ? substr($input['pe_telephone'], 0, 30) : '',
            ':pe_verif_mdt' => isset($input['pe_verif_mdt']) ? substr($input['pe_verif_mdt'], 0, 10) : 'Non',
            ':deroule_faits' => substr((string)(isset($input['deroule_faits']) ? $input['deroule_faits'] : ''), 0, 20000),
            ':individus' => json_encode(pl_norm_individus(isset($input['individus']) ? $input['individus'] : array()), PL_JSON),
            ':faits' => json_encode(pl_norm_faits(isset($input['faits']) ? $input['faits'] : array()), PL_JSON),
            ':plaignants' => json_encode($plaignants, PL_JSON),
            ':id' => $input['id']
        ));
        pl_link_attachments($conn, $input['id'], $input);
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'delete') {
        mdt_post_only();
        mdt_require_admin($conn, 'plainte_delete');
        $input = mdt_get_post_data('E-803');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-803', 'ID manquant');
        $atts = $conn->prepare("SELECT stored_name FROM plainte_attachments WHERE plainte_id = :id");
        $atts->execute(array(':id' => $input['id']));
        $stored = $atts->fetchAll(PDO::FETCH_COLUMN);
        $conn->prepare("DELETE FROM plainte_tag_assignments WHERE plainte_id = :id")->execute(array(':id' => $input['id']));
        $conn->prepare("DELETE FROM plainte_attachments WHERE plainte_id = :id")->execute(array(':id' => $input['id']));
        $conn->prepare("DELETE FROM plaintes WHERE id = :id")->execute(array(':id' => $input['id']));
        foreach ($stored as $sn) pl_safe_unlink($sn);
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'list_tags') {
        echo json_encode($conn->query("SELECT id, name, color, icon, ordre FROM plainte_tags ORDER BY ordre, name")->fetchAll(), PL_JSON);
    }
    elseif ($action === 'create_tag') {
        mdt_post_only();
        $input = mdt_get_post_data('E-810');
        if (!$input || !isset($input['name']) || !trim($input['name']) || !isset($input['color'])) mdt_error(400, 'E-810', 'Donnees invalides: name et color requis');
        $id = uniqid('ptag_');
        $maxOrdre = $conn->query("SELECT COALESCE(MAX(ordre), 0) FROM plainte_tags")->fetchColumn();
        $conn->prepare("INSERT INTO plainte_tags (id, name, color, icon, ordre, is_closed) VALUES (:id, :name, :color, :icon, :ordre, :is_closed)")->execute(array(
            ':id' => $id, ':name' => substr(trim($input['name']), 0, 100), ':color' => pl_color($input['color']),
            ':icon' => (isset($input['icon']) && $input['icon']) ? substr($input['icon'], 0, 50) : null, ':ordre' => $maxOrdre + 1,
            ':is_closed' => (isset($input['is_closed']) && $input['is_closed']) ? 1 : 0
        ));
        echo json_encode(array('success' => true, 'id' => $id));
    }
    elseif ($action === 'update_tag') {
        mdt_post_only();
        $input = mdt_get_post_data('E-811');
        if (!$input || !isset($input['id']) || !isset($input['name']) || !trim($input['name']) || !isset($input['color'])) mdt_error(400, 'E-811', 'Donnees invalides: id, name et color requis');
        $check = $conn->prepare("SELECT id FROM plainte_tags WHERE id = :id");
        $check->execute(array(':id' => $input['id']));
        if (!$check->fetch()) mdt_error(404, 'E-811', 'Tag introuvable');
        $conn->prepare("UPDATE plainte_tags SET name = :name, color = :color, icon = :icon, is_closed = :is_closed WHERE id = :id")->execute(array(
            ':name' => substr(trim($input['name']), 0, 100), ':color' => pl_color($input['color']),
            ':icon' => (isset($input['icon']) && $input['icon']) ? substr($input['icon'], 0, 50) : null,
            ':is_closed' => (isset($input['is_closed']) && $input['is_closed']) ? 1 : 0, ':id' => $input['id']
        ));
        echo json_encode(array('success' => true));
    }
    elseif ($action === 'delete_tag') {
        mdt_post_only();
        $input = mdt_get_post_data('E-812');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-812', 'ID manquant');
        $conn->prepare("DELETE FROM plainte_tag_assignments WHERE tag_id = :id")->execute(array(':id' => $input['id']));
        $conn->prepare("DELETE FROM plainte_tags WHERE id = :id")->execute(array(':id' => $input['id']));
        echo json_encode(array('success' => true));
    }
    elseif ($action === 'assign_tags') {
        mdt_post_only();
        $input = mdt_get_post_data('E-813');
        if (!$input || !isset($input['plainte_id']) || !isset($input['tag_ids'])) mdt_error(400, 'E-813', 'Donnees invalides: plainte_id et tag_ids requis');
        $pid = $input['plainte_id'];
        $tagIds = is_array($input['tag_ids']) ? $input['tag_ids'] : array();
        $conn->prepare("DELETE FROM plainte_tag_assignments WHERE plainte_id = :pid")->execute(array(':pid' => $pid));
        if (count($tagIds)) {
            $st = $conn->prepare("INSERT INTO plainte_tag_assignments (plainte_id, tag_id) VALUES (:pid, :tid)");
            foreach ($tagIds as $tid) $st->execute(array(':pid' => $pid, ':tid' => $tid));
        }
        echo json_encode(array('success' => true));
    }
    elseif ($action === 'seed_tags') {
        mdt_post_only();
        $count = $conn->query("SELECT COUNT(*) FROM plainte_tags")->fetchColumn();
        if ($count > 0) { echo json_encode(array('success' => true, 'message' => 'Tags deja presents')); }
        else {
            $st = $conn->prepare("INSERT INTO plainte_tags (id, name, color, icon, ordre, is_closed) VALUES (:id, :name, :color, :icon, :ordre, :is_closed)");
            foreach (array(
                array(':id' => 'ptag_encours', ':name' => 'En cours', ':color' => '#f59e0b', ':icon' => 'fa-clock', ':ordre' => 1, ':is_closed' => 0),
                array(':id' => 'ptag_traite', ':name' => 'Traite', ':color' => '#10b981', ':icon' => 'fa-check', ':ordre' => 2, ':is_closed' => 1),
                array(':id' => 'ptag_classe', ':name' => 'Classe sans suite', ':color' => '#6366f1', ':icon' => 'fa-ban', ':ordre' => 3, ':is_closed' => 1)
            ) as $tag) $st->execute($tag);
            echo json_encode(array('success' => true, 'seeded' => 3));
        }
    }

    elseif ($action === 'upload_attachment') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) mdt_error(400, 'E-820', 'Aucun fichier recu');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) mdt_error(400, 'E-821', 'Erreur upload (code ' . $f['error'] . ')');
        if ($f['size'] > PL_MAX_SIZE) mdt_error(400, 'E-822', 'Fichier trop volumineux (max 10 Mo)');
        $mime = '';
        if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
        else { $mime = $f['type']; }
        if (!isset($PL_ALLOWED[$mime])) mdt_error(400, 'E-823', 'Type non autorise (images PNG/JPG/GIF/WEBP ou PDF)');
        $ext = $PL_ALLOWED[$mime];
        if (!is_dir(PL_UPLOAD_DIR)) @mkdir(PL_UPLOAD_DIR, 0775, true);
        $id = uniqid('att_');
        $stored = $id . '_' . bin2hex(random_bytes(9)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], PL_UPLOAD_DIR . $stored)) mdt_error(500, 'E-824', 'Echec enregistrement du fichier');
        $orig = substr(preg_replace('/[^\w.\- ]/u', '', (string)$f['name']), 0, 200);
        $conn->prepare("INSERT INTO plainte_attachments (id, plainte_id, stored_name, original_name, mime, size) VALUES (:id, NULL, :sn, :on, :m, :sz)")
             ->execute(array(':id' => $id, ':sn' => $stored, ':on' => $orig, ':m' => $mime, ':sz' => (int)$f['size']));
        echo json_encode(array('success' => true, 'id' => $id, 'url' => PL_UPLOAD_URL . $stored, 'original_name' => $orig, 'mime' => $mime, 'size' => (int)$f['size']), PL_JSON);
    }
    elseif ($action === 'delete_attachment') {
        mdt_post_only();
        $input = mdt_get_post_data('E-825');
        if (!$input || empty($input['id'])) mdt_error(400, 'E-825', 'ID manquant');
        $row = $conn->prepare("SELECT stored_name FROM plainte_attachments WHERE id = :id");
        $row->execute(array(':id' => $input['id']));
        $r = $row->fetch();
        if ($r) {
            $conn->prepare("DELETE FROM plainte_attachments WHERE id = :id")->execute(array(':id' => $input['id']));
            pl_safe_unlink($r['stored_name']);
        }
        echo json_encode(array('success' => true));
    }

    else { mdt_unknown_action(); }

} catch (Exception $e) {
    mdt_error(500, 'E-802', 'Erreur serveur', $e->getMessage());
}

function pl_color($c) {
    $c = trim((string)$c);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#6366f1';
}
function pl_link_attachments($conn, $plainteId, $input) {
    if (isset($input['attachment_ids']) && is_array($input['attachment_ids']) && count($input['attachment_ids'])) {
        $au = $conn->prepare("UPDATE plainte_attachments SET plainte_id = :pid WHERE id = :aid AND plainte_id IS NULL");
        $n = 0;
        foreach ($input['attachment_ids'] as $aid) { if (++$n > PL_MAX_ATTACH) break; $au->execute(array(':pid' => $plainteId, ':aid' => (string)$aid)); }
    }
}

function pl_safe_unlink($storedName) {
    $storedName = (string)$storedName;
    if ($storedName === '' || strpos($storedName, '/') !== false || strpos($storedName, "\\") !== false || strpos($storedName, '..') !== false) return;
    $path = PL_UPLOAD_DIR . $storedName;
    $real = realpath($path);
    $base = realpath(PL_UPLOAD_DIR);
    if ($real && $base && strpos($real, $base) === 0) @unlink($real);
}
