<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('SD_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
define('SD_CONTENT_MAX', 60000);
define('SD_MAX_DATA_KEYS', 40);
$SD_TYPES = array('DOUILLE', 'FRAGMENT', 'TROU', 'SANG', 'ARME');

function sd_gstr($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : ''; }

function sd_author_name($conn, $did) {
    if (!$did) return 'Agent SD';
    try {
        $q = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(username,''), 'Agent SD') FROM users WHERE discord_id = :d LIMIT 1");
        $q->execute(array(':d' => $did));
        $n = $q->fetchColumn();
        return ($n !== false && $n !== null && $n !== '') ? substr((string)$n, 0, 100) : 'Agent SD';
    } catch (Exception $e) { return 'Agent SD'; }
}

function sd_norm_data($v) {
    $out = array();
    if (is_array($v)) {
        foreach ($v as $k => $val) {
            if (count($out) >= SD_MAX_DATA_KEYS) break;
            if (!is_string($k)) continue;
            $k = substr($k, 0, 60);
            if (is_array($val) || is_object($val)) continue;
            $out[$k] = substr((string)$val, 0, 5000);
        }
    }
    return $out;
}

$action = sd_gstr('action');

try {
    $me = mdt_require_auth($conn, 'sd');

    mdt_require_module($conn, 'sd', 'sd_api');
    $meDid = isset($me['discord_id']) ? $me['discord_id'] : null;
    $isAdmin = mdt_is_admin($conn, $meDid);

    if ($action === 'bootstrap') {
        $analyses = $conn->query("SELECT id, created_at, author, case_name, evidence_type, data FROM sd_analyses ORDER BY created_at DESC")->fetchAll();
        foreach ($analyses as &$a) { $a['data'] = json_decode($a['data'], true); if (!is_array($a['data'])) $a['data'] = array(); }
        unset($a);
        $reports = $conn->query("SELECT id, date, author, title, content FROM sd_reports ORDER BY date DESC")->fetchAll();
        echo json_encode(array(
            'access'   => true,
            'me'       => array('is_admin' => $isAdmin, 'author_name' => sd_author_name($conn, $meDid)),
            'perms'    => array('create' => true, 'edit' => true, 'delete' => true),
            'stats'    => array('analyses' => count($analyses), 'reports' => count($reports)),
            'analyses' => $analyses,
            'reports'  => $reports,
        ), SD_JSON);
    }

    elseif ($action === 'create_analyse') {
        mdt_post_only();
        $input = mdt_get_post_data('E-600');
        if (!$input || !isset($input['case_name']) || !trim((string)$input['case_name']) || !isset($input['evidence_type'])) {
            mdt_error(400, 'E-600', 'Donnees invalides: case_name et evidence_type requis');
        }
        $type = strtoupper(substr((string)$input['evidence_type'], 0, 50));
        $id = uniqid('an_');
        $stmt = $conn->prepare("INSERT INTO sd_analyses (id, created_at, author, case_name, evidence_type, data) VALUES (:id, :created_at, :author, :case_name, :evidence_type, :data)");
        $stmt->execute(array(
            ':id' => $id,
            ':created_at' => date('Y-m-d H:i:s'),
            ':author' => sd_author_name($conn, $meDid),
            ':case_name' => substr((string)$input['case_name'], 0, 255),
            ':evidence_type' => $type,
            ':data' => json_encode(sd_norm_data(isset($input['data']) ? $input['data'] : array()), SD_JSON)
        ));
        echo json_encode(array('success' => true, 'id' => $id));
    }

    elseif ($action === 'update_analyse') {
        mdt_post_only();
        $input = mdt_get_post_data('E-600');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-600', 'Donnees invalides: id requis');
        $chk = $conn->prepare("SELECT id FROM sd_analyses WHERE id = :id");
        $chk->execute(array(':id' => $input['id']));
        if (!$chk->fetch()) mdt_error(404, 'E-601', 'Analyse introuvable');
        $stmt = $conn->prepare("UPDATE sd_analyses SET case_name = :case_name, data = :data WHERE id = :id");
        $stmt->execute(array(
            ':case_name' => isset($input['case_name']) ? substr((string)$input['case_name'], 0, 255) : '',
            ':data' => json_encode(sd_norm_data(isset($input['data']) ? $input['data'] : array()), SD_JSON),
            ':id' => $input['id']
        ));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'delete_analyse') {
        mdt_post_only();
        $input = mdt_get_post_data('E-603');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-603', 'ID manquant');
        $conn->prepare("DELETE FROM sd_analyses WHERE id = :id")->execute(array(':id' => $input['id']));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'create_report') {
        mdt_post_only();
        $input = mdt_get_post_data('E-604');
        if (!$input || !isset($input['title']) || !trim((string)$input['title']) || !isset($input['content'])) {
            mdt_error(400, 'E-604', 'Donnees invalides: title et content requis');
        }
        $id = uniqid('rep_');
        $stmt = $conn->prepare("INSERT INTO sd_reports (id, date, author, title, content) VALUES (:id, :date, :author, :title, :content)");
        $stmt->execute(array(
            ':id' => $id,
            ':date' => date('Y-m-d H:i:s'),
            ':author' => sd_author_name($conn, $meDid),
            ':title' => substr((string)$input['title'], 0, 255),
            ':content' => substr((string)$input['content'], 0, SD_CONTENT_MAX)
        ));
        echo json_encode(array('success' => true, 'id' => $id));
    }

    elseif ($action === 'update_report') {
        mdt_post_only();
        $input = mdt_get_post_data('E-604');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-604', 'Donnees invalides: id requis');
        $chk = $conn->prepare("SELECT id FROM sd_reports WHERE id = :id");
        $chk->execute(array(':id' => $input['id']));
        if (!$chk->fetch()) mdt_error(404, 'E-605', 'Rapport introuvable');
        $stmt = $conn->prepare("UPDATE sd_reports SET title = :title, content = :content WHERE id = :id");
        $stmt->execute(array(
            ':title' => isset($input['title']) ? substr((string)$input['title'], 0, 255) : '',
            ':content' => isset($input['content']) ? substr((string)$input['content'], 0, SD_CONTENT_MAX) : '',
            ':id' => $input['id']
        ));
        echo json_encode(array('success' => true));
    }

    elseif ($action === 'delete_report') {
        mdt_post_only();
        $input = mdt_get_post_data('E-607');
        if (!$input || !isset($input['id'])) mdt_error(400, 'E-607', 'ID manquant');
        $conn->prepare("DELETE FROM sd_reports WHERE id = :id")->execute(array(':id' => $input['id']));
        echo json_encode(array('success' => true));
    }

    else { mdt_unknown_action(); }

} catch (Exception $e) {
    mdt_error(500, 'E-602', 'Erreur serveur', $e->getMessage());
}
