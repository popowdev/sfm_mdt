<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('NPU_DDL_VERSION', '2026-08-23.1');
define('NPU_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
define('NPU_GEO_MAX', 5000);
define('NPU_UPLOAD_MAX', 8 * 1024 * 1024);

$NPU_QUESTIONS = array(
  array('q'=>"Quelle est la mission première de la NPU ?", 'choix'=>array("Prendre le contrôle du nord","Occuper, réguler et protéger le nord","Remplacer la CID"), 'correct'=>1),
  array('q'=>"Quel est le grade MDT minimum pour postuler à la NPU ?", 'choix'=>array("Cadet","Trooper 1","Trooper 2"), 'correct'=>1),
  array('q'=>"Qui garde la main sur toutes les enquêtes, nord comme sud ?", 'choix'=>array("La NPU","La CID","Le SAMR"), 'correct'=>1),
  array('q'=>"Un gradé te donne un ordre illégal, illégitime ou dangereux :", 'choix'=>array("Cet ordre doit être ignoré","Je l'exécute sans discuter","Je l'exécute puis je me plains"), 'correct'=>0),
  array('q'=>"La patrouille seul dans le nord est autorisée à partir de :", 'choix'=>array("Cadet","Deputy I","Deputy II"), 'correct'=>2),
  array('q'=>"Quelle formation est obligatoire pour TOUS les membres de la NPU ?", 'choix'=>array("Pilote hélicoptère","Premiers secours","Highway"), 'correct'=>1),
  array('q'=>"Le Mont Chiliad se situe :", 'choix'=>array("Au-dessus de Paleto Bay","Au bord de l'Alamo Sea","Au sud de Los Santos"), 'correct'=>0),
  array('q'=>"L'Alamo Sea est :", 'choix'=>array("L'océan de la côte ouest","Une rivière de Raton Canyon","Une mer intérieure près de Sandy Shores"), 'correct'=>2),
  array('q'=>"Quelle route longe la côte ouest jusqu'à Paleto Bay ?", 'choix'=>array("La Route 68","La Great Ocean Highway","La Senora Freeway"), 'correct'=>1),
  array('q'=>"Une personne est portée disparue sur le Mont Chiliad. Quelle cellule intervient en priorité ?", 'choix'=>array("SAR","Nautique","Highway"), 'correct'=>0),
  array('q'=>"Une noyade est signalée sur l'Alamo Sea. Quelle cellule intervient ?", 'choix'=>array("Garde-chasse","Tout-terrain","Nautique"), 'correct'=>2),
  array('q'=>"Tu surprends un braconnier dans une réserve naturelle. Quelle cellule est concernée ?", 'choix'=>array("Garde-chasse","Pilote moto","HRT"), 'correct'=>0),
  array('q'=>"Le code radio 10-99 signifie :", 'choix'=>array("Fin de patrouille","Agent en danger","Contrôle routier"), 'correct'=>1),
  array('q'=>"Seul en patrouille, un individu armé te met en joue par surprise :", 'choix'=>array("Je dégaine immédiatement","Je m'enfuis en voiture","Je privilégie ma vie : je coopère et j'attends les renforts ou le bon moment"), 'correct'=>2),
  array('q'=>"L'alcool en service, c'est :", 'choix'=>array("Strictement interdit","Toléré avec modération","Autorisé hors patrouille"), 'correct'=>0),
  array('q'=>"Grapeseed se situe :", 'choix'=>array("Sur la côte ouest","Au nord-est de l'Alamo Sea","Dans Los Santos"), 'correct'=>1),
  array('q'=>"Un Cadet en patrouille :", 'choix'=>array("Peut partir seul","Peut partir seul la nuit uniquement","Doit toujours être accompagné d'au moins un Deputy"), 'correct'=>2),
  array('q'=>"Pour patrouiller sur les pistes et chemins du nord, tu privilégies :", 'choix'=>array("Un 4x4 ou un véhicule tout-terrain","Une berline rapide","Une moto de route"), 'correct'=>0),
  array('q'=>"Un collègue annonce un 10-99 à Sandy Shores. Que fais-tu ?", 'choix'=>array("Je termine tranquillement mon contrôle en cours","Je fonce en renfort en annonçant ma position à la radio","J'attends qu'on me demande de venir"), 'correct'=>1),
  array('q'=>"En poursuite d'un véhicule sur la Route 68, ta priorité à la radio :", 'choix'=>array("Annoncer ma position, la direction et la description du véhicule","Ne rien dire pour rester concentré","Couper la radio"), 'correct'=>0),
);

function npu_ddl($conn) {
    try {
        $v = $conn->query("SELECT mv FROM npu_config WHERE mk = '__ddl'")->fetchColumn();
        if ($v === NPU_DDL_VERSION) return;
    } catch (Exception $e) {}
    $conn->exec("CREATE TABLE IF NOT EXISTS npu_config (mk VARCHAR(40) PRIMARY KEY, mv TEXT)");
    $conn->exec("CREATE TABLE IF NOT EXISTS npu_places (
        id INT AUTO_INCREMENT PRIMARY KEY, ordre INT DEFAULT 0,
        nom VARCHAR(120) DEFAULT '', photo VARCHAR(500) DEFAULT NULL,
        x FLOAT DEFAULT NULL, y FLOAT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $conn->exec("CREATE TABLE IF NOT EXISTS npu_responses (
        discord_id VARCHAR(32) PRIMARY KEY, nom VARCHAR(160),
        quiz_answers TEXT, quiz_score INT DEFAULT 0,
        geo_answers TEXT, geo_score INT DEFAULT 0,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $conn->exec("CREATE TABLE IF NOT EXISTS npu_chrono (
        discord_id VARCHAR(32) PRIMARY KEY, nom VARCHAR(160),
        temps_ms INT DEFAULT NULL, eliminated TINYINT(1) DEFAULT 0,
        note VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $conn->prepare("REPLACE INTO npu_config (mk, mv) VALUES ('__ddl', :v)")->execute(array(':v' => NPU_DDL_VERSION));
}

function npu_cfg($conn, $k, $def = null) {
    try {
        $st = $conn->prepare("SELECT mv FROM npu_config WHERE mk = :k");
        $st->execute(array(':k' => $k));
        $v = $st->fetchColumn();
        return $v === false ? $def : $v;
    } catch (Exception $e) { return $def; }
}

function npu_cfg_set($conn, $k, $v) {
    $conn->prepare("REPLACE INTO npu_config (mk, mv) VALUES (:k, :v)")->execute(array(':k' => $k, ':v' => $v));
}

function npu_places($conn) {
    return $conn->query("SELECT id, ordre, nom, photo, x, y FROM npu_places ORDER BY ordre, id")->fetchAll(PDO::FETCH_ASSOC);
}

function npu_points($gx, $gy, $cx, $cy) {
    if ($gx === null || $gy === null || $cx === null || $cy === null) return array(null, 0);
    $dist = sqrt(pow($gx - $cx, 2) + pow($gy - $cy, 2));
    return array($dist, (int)round(NPU_GEO_MAX * exp(-$dist * 8)));
}

function npu_num($v) {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    return ($f < 0 || $f > 1) ? null : $f;
}

mdt_require_auth($conn, 'npu');
$ACT = isset($GLOBALS['MDT_ACTOR']) ? $GLOBALS['MDT_ACTOR'] : array();
$SELF_DID = isset($ACT['did']) ? (string)$ACT['did'] : null;
$SELF_NAME = isset($ACT['name']) && $ACT['name'] !== null ? (string)$ACT['name'] : '';
$IS_ADMIN = mdt_is_admin($conn, $SELF_DID);

if (!$IS_ADMIN && !mdt_module_access($conn, 'npu', $SELF_DID)) {
    mdt_error(403, 'E-NPU-403', 'Acces refuse au module NPU');
}

npu_ddl($conn);

function npu_require_admin() {
    if (!$GLOBALS['IS_ADMIN']) mdt_error(403, 'E-NPU-403', 'Reserve au commandement');
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {

    if ($action === 'meta') {
        $q = array();
        foreach ($NPU_QUESTIONS as $i => $it) $q[] = array('i' => $i, 'q' => $it['q'], 'choix' => $it['choix']);
        $geo = array();
        foreach (npu_places($conn) as $p) {
            if (!empty($p['photo'])) $geo[] = array('id' => (int)$p['id'], 'photo' => $p['photo'], 'nom' => $p['nom']);
        }
        $done = false;
        $st = $conn->prepare("SELECT 1 FROM npu_responses WHERE discord_id = :d");
        $st->execute(array(':d' => $SELF_DID));
        $done = $st->fetch() ? true : false;
        echo json_encode(array(
            'ok' => true,
            'me' => array('discord_id' => $SELF_DID, 'name' => $SELF_NAME),
            'is_admin' => $IS_ADMIN,
            'open' => (npu_cfg($conn, 'open', '0') === '1'),
            'questions' => $q,
            'geo' => $geo,
            'already' => $done,
            'geo_max' => NPU_GEO_MAX,
            'total_quiz' => count($NPU_QUESTIONS),
        ), NPU_JSON);
        exit;
    }

    elseif ($action === 'submit') {
        mdt_post_only();
        if (npu_cfg($conn, 'open', '0') !== '1') mdt_error(403, 'E-NPU-CLOSED', 'L\'epreuve est fermee');
        $d = mdt_get_post_data('E-NPU-400');

        $st = $conn->prepare("SELECT 1 FROM npu_responses WHERE discord_id = :d");
        $st->execute(array(':d' => $SELF_DID));
        if ($st->fetch() && !$IS_ADMIN) mdt_error(409, 'E-NPU-409', 'Tu as deja passe l\'epreuve');

        $quiz = isset($d['quiz']) && is_array($d['quiz']) ? $d['quiz'] : array();
        $qscore = 0; $qdetail = array();
        foreach ($NPU_QUESTIONS as $i => $it) {
            $rep = isset($quiz[$i]) ? (int)$quiz[$i] : -1;
            $bon = ($rep === (int)$it['correct']);
            if ($bon) $qscore++;
            $qdetail[] = array('i' => $i, 'rep' => $rep, 'ok' => $bon);
        }

        $coords = array();
        foreach (npu_places($conn) as $p) $coords[(int)$p['id']] = array($p['x'], $p['y']);
        $geo = isset($d['geo']) && is_array($d['geo']) ? $d['geo'] : array();
        $gscore = 0; $gdetail = array();
        foreach ($geo as $g) {
            $pid = isset($g['id']) ? (int)$g['id'] : 0;
            if (!isset($coords[$pid])) continue;
            $gx = npu_num(isset($g['x']) ? $g['x'] : null);
            $gy = npu_num(isset($g['y']) ? $g['y'] : null);
            list($dist, $pts) = npu_points($gx, $gy, $coords[$pid][0], $coords[$pid][1]);
            $gscore += $pts;
            $gdetail[] = array('id' => $pid, 'gx' => $gx, 'gy' => $gy, 'dist' => $dist, 'pts' => $pts);
        }

        $conn->prepare("REPLACE INTO npu_responses (discord_id, nom, quiz_answers, quiz_score, geo_answers, geo_score, submitted_at)
                        VALUES (:d, :n, :qa, :qs, :ga, :gs, NOW())")
             ->execute(array(':d' => $SELF_DID, ':n' => $SELF_NAME,
                             ':qa' => json_encode($qdetail, NPU_JSON), ':qs' => $qscore,
                             ':ga' => json_encode($gdetail, NPU_JSON), ':gs' => $gscore));

        echo json_encode(array('success' => true, 'quiz_score' => $qscore,
            'total_quiz' => count($NPU_QUESTIONS), 'geo_score' => $gscore), NPU_JSON);
        exit;
    }

    elseif ($action === 'save_chrono') {
        mdt_post_only();
        $d = mdt_get_post_data('E-NPU-400');
        $ms = isset($d['temps_ms']) ? (int)$d['temps_ms'] : 0;
        if ($ms < 0 || $ms > 86400000) $ms = 0;
        $conn->prepare("REPLACE INTO npu_chrono (discord_id, nom, temps_ms, updated_at) VALUES (:d, :n, :t, NOW())")
             ->execute(array(':d' => $SELF_DID, ':n' => $SELF_NAME, ':t' => $ms));
        echo json_encode(array('success' => true), NPU_JSON);
        exit;
    }

    elseif ($action === 'admin_places') {
        npu_require_admin();
        echo json_encode(array('ok' => true, 'places' => npu_places($conn),
            'open' => (npu_cfg($conn, 'open', '0') === '1')), NPU_JSON);
        exit;
    }

    elseif ($action === 'save_place') {
        npu_require_admin();
        mdt_post_only();
        $d = mdt_get_post_data('E-NPU-400');
        $id = isset($d['id']) ? (int)$d['id'] : 0;
        $nom = mb_substr(trim((string)(isset($d['nom']) ? $d['nom'] : '')), 0, 120, 'UTF-8');
        $x = npu_num(isset($d['x']) ? $d['x'] : null);
        $y = npu_num(isset($d['y']) ? $d['y'] : null);
        $ordre = isset($d['ordre']) ? (int)$d['ordre'] : 0;
        $photo = isset($d['photo']) ? trim((string)$d['photo']) : '';
        if ($photo !== '' && !preg_match('#^/npu/uploads/[A-Za-z0-9_.-]+$#', $photo)) {
            mdt_error(400, 'E-NPU-PHOTO', 'Chemin de photo invalide');
        }
        if ($id) {
            $sql = "UPDATE npu_places SET nom = :n, x = :x, y = :y, ordre = :o" . ($photo !== '' ? ", photo = :p" : "") . " WHERE id = :i";
            $par = array(':n' => $nom, ':x' => $x, ':y' => $y, ':o' => $ordre, ':i' => $id);
            if ($photo !== '') $par[':p'] = $photo;
            $conn->prepare($sql)->execute($par);
        } else {
            $conn->prepare("INSERT INTO npu_places (ordre, nom, photo, x, y) VALUES (:o, :n, :p, :x, :y)")
                 ->execute(array(':o' => $ordre, ':n' => $nom, ':p' => ($photo !== '' ? $photo : null), ':x' => $x, ':y' => $y));
            $id = (int)$conn->lastInsertId();
        }
        echo json_encode(array('success' => true, 'id' => $id), NPU_JSON);
        exit;
    }

    elseif ($action === 'delete_place') {
        npu_require_admin();
        mdt_post_only();
        $d = mdt_get_post_data('E-NPU-400');
        $conn->prepare("DELETE FROM npu_places WHERE id = :i")->execute(array(':i' => (int)(isset($d['id']) ? $d['id'] : 0)));
        echo json_encode(array('success' => true), NPU_JSON);
        exit;
    }

    elseif ($action === 'upload') {
        npu_require_admin();
        mdt_post_only();
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            mdt_error(400, 'E-NPU-UP', 'Aucun fichier recu');
        }
        if ($_FILES['photo']['size'] > NPU_UPLOAD_MAX) mdt_error(413, 'E-NPU-UP2', 'Fichier trop lourd (8 Mo max)');
        $tmp = $_FILES['photo']['tmp_name'];
        $info = @getimagesize($tmp);
        if (!$info) mdt_error(400, 'E-NPU-UP3', 'Ce fichier n\'est pas une image');
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmp); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmp);  break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($tmp); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmp);  break;
        }
        if (!$src) mdt_error(400, 'E-NPU-UP4', 'Format d\'image non accepte (JPG, PNG, WEBP, GIF)');
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = bin2hex(random_bytes(8)) . '_' . time() . '.webp';
        if (!@imagewebp($src, $dir . '/' . $name, 82)) { imagedestroy($src); mdt_error(500, 'E-NPU-UP5', 'Echec de l\'enregistrement'); }
        imagedestroy($src);
        echo json_encode(array('success' => true, 'photo' => '/npu/uploads/' . $name), NPU_JSON);
        exit;
    }

    elseif ($action === 'admin_results') {
        npu_require_admin();
        $rows = $conn->query("SELECT r.discord_id, r.nom, r.quiz_score, r.geo_score, r.quiz_answers, r.geo_answers, r.submitted_at,
                                     c.temps_ms, c.eliminated, c.note
                              FROM npu_responses r LEFT JOIN npu_chrono c ON c.discord_id = r.discord_id
                              ORDER BY (r.quiz_score * 250 + r.geo_score) DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array('ok' => true, 'results' => $rows, 'places' => npu_places($conn),
            'total_quiz' => count($NPU_QUESTIONS), 'geo_max' => NPU_GEO_MAX), NPU_JSON);
        exit;
    }

    elseif ($action === 'participants') {
        npu_require_admin();
        $rows = $conn->query("SELECT r.discord_id, r.nom, r.quiz_score, r.geo_score, r.submitted_at,
                                     c.temps_ms, c.eliminated, c.note
                              FROM npu_responses r LEFT JOIN npu_chrono c ON c.discord_id = r.discord_id
                              ORDER BY c.eliminated ASC, c.temps_ms IS NULL, c.temps_ms ASC, r.nom")->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'discord_id' => $r['discord_id'], 'nom' => $r['nom'],
                'quiz_score' => (int)$r['quiz_score'], 'geo_score' => (int)$r['geo_score'],
                'submitted_at' => $r['submitted_at'],
                'temps_ms' => $r['temps_ms'] !== null ? (int)$r['temps_ms'] : null,
                'eliminated' => ((int)$r['eliminated']) ? true : false,
                'note' => $r['note'],
            );
        }
        echo json_encode(array('ok' => true, 'participants' => $out,
            'total_quiz' => count($NPU_QUESTIONS)), NPU_JSON);
        exit;
    }

    elseif ($action === 'chrono_save') {
        npu_require_admin();
        mdt_post_only();
        $d = mdt_get_post_data('E-NPU-400');
        $did = isset($d['discord_id']) ? (string)$d['discord_id'] : '';
        if ($did === '') mdt_error(400, 'E-NPU-400', 'discord_id requis');
        $q = $conn->prepare("SELECT nom FROM npu_responses WHERE discord_id = :d");
        $q->execute(array(':d' => $did));
        $nom = $q->fetchColumn();
        if ($nom === false) mdt_error(404, 'E-NPU-404', 'Candidat introuvable');
        $t = (isset($d['temps_ms']) && $d['temps_ms'] !== '' && $d['temps_ms'] !== null) ? (int)$d['temps_ms'] : null;
        if ($t !== null && ($t < 0 || $t > 86400000)) $t = null;
        $elim = !empty($d['eliminated']) ? 1 : 0;
        $note = isset($d['note']) ? mb_substr(trim((string)$d['note']), 0, 255, 'UTF-8') : null;
        $conn->prepare("INSERT INTO npu_chrono (discord_id, nom, temps_ms, eliminated, note, updated_at)
                        VALUES (:d, :n, :t, :e, :no, NOW())
                        ON DUPLICATE KEY UPDATE nom = VALUES(nom), temps_ms = VALUES(temps_ms),
                          eliminated = VALUES(eliminated), note = VALUES(note), updated_at = NOW()")
             ->execute(array(':d' => $did, ':n' => $nom, ':t' => $t, ':e' => $elim, ':no' => $note));
        echo json_encode(array('success' => true), NPU_JSON);
        exit;
    }

    elseif ($action === 'set_open') {
        npu_require_admin();
        mdt_post_only();
        $d = mdt_get_post_data('E-NPU-400');
        npu_cfg_set($conn, 'open', !empty($d['open']) ? '1' : '0');
        echo json_encode(array('success' => true, 'open' => !empty($d['open'])), NPU_JSON);
        exit;
    }

    elseif ($action === 'reset_session') {
        npu_require_admin();
        mdt_post_only();
        $d = mdt_get_post_data('E-NPU-400');
        $did = isset($d['discord_id']) ? (string)$d['discord_id'] : '';
        if ($did === '') mdt_error(400, 'E-NPU-400', 'discord_id requis');
        $conn->prepare("DELETE FROM npu_responses WHERE discord_id = :d")->execute(array(':d' => $did));
        $conn->prepare("DELETE FROM npu_chrono WHERE discord_id = :d")->execute(array(':d' => $did));
        echo json_encode(array('success' => true), NPU_JSON);
        exit;
    }

    else mdt_error(400, 'E-NPU-ACT', 'Action inconnue');

} catch (PDOException $e) {
    mdt_error(500, 'E-NPU-500', 'Erreur base de donnees', $e->getMessage());
}
