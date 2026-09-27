<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();
$__annSecret = __DIR__ . '/../../scripts/annonces_secret.php';
if (is_file($__annSecret)) require_once $__annSecret;

define('ANN_UPLOAD_DIR', __DIR__ . '/uploads/');
define('ANN_UPLOAD_URL', '/annonces/uploads/');
define('ANN_MAX_SIZE', 25 * 1024 * 1024);
define('ANN_SIG_TTL', 21600);
define('ANN_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
require_once __DIR__ . '/../mdt_roles.php';
$ANN_MANAGE_ROLE = MDT_ROLE_MRD;
$ANN_CANAUX = array('divisions', 'supervision', 'news');

function ann_actor($conn) {
    static $c = null;
    if ($c !== null) return $c;
    global $ANN_MANAGE_ROLE;
    $did = function_exists('mdt_session_discord_id') ? mdt_session_discord_id($conn) : null;
    $roles = array(); $name = null;
    if ($did) {
        try {
            $st = $conn->prepare("SELECT discord_roles, COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username, discord_id) AS nom FROM users WHERE discord_id = :d LIMIT 1");
            $st->execute(array(':d' => $did));
            $row = $st->fetch();
            if ($row) {
                $r = $row['discord_roles'] ? json_decode($row['discord_roles'], true) : array();
                if (is_array($r)) $roles = $r;
                $name = $row['nom'];
            }
        } catch (Exception $e) {}
    }
    $manage = false;
    if ($did) {
        if (function_exists('mdt_is_admin') && mdt_is_admin($conn, $did)) $manage = true;
        elseif (count(array_intersect(mdt_role_config($conn, 'annonces_manage', array($ANN_MANAGE_ROLE)), $roles)) > 0) $manage = true;
    }
    $c = array('did' => $did, 'roles' => $roles, 'name' => $name, 'manage' => $manage);
    return $c;
}

function ann_can_view($ctx, $rolesJson) {
    if ($ctx['manage']) return true;
    $roles = $rolesJson ? json_decode($rolesJson, true) : array();
    if (!is_array($roles) || empty($roles)) return true;
    foreach ($roles as $r) { if (in_array($r, $ctx['roles'], true)) return true; }
    return false;
}

function ann_target_dids($conn, $rolesJson) {
    $roles = $rolesJson ? json_decode($rolesJson, true) : array();
    if (!is_array($roles)) $roles = array();
    $dids = array();
    try {
        if (empty($roles)) {
            foreach ($conn->query("SELECT discord_id FROM users WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll(PDO::FETCH_COLUMN) as $d) $dids[] = $d;
        } else {
            foreach ($conn->query("SELECT discord_id, discord_roles FROM users WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll() as $r) {
                $ur = $r['discord_roles'] ? json_decode($r['discord_roles'], true) : array();
                if (!is_array($ur)) continue;
                foreach ($roles as $need) { if (in_array($need, $ur, true)) { $dids[] = $r['discord_id']; break; } }
            }
        }
    } catch (Exception $e) {}
    return array_values(array_unique($dids));
}

function ann_notify($conn, $dids, $titre, $corps, $lien, $refId, $urgent) {
    if (empty($dids)) return;
    try {
        $ins = $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id, urgent, lu) VALUES (:d, 'annonce', :t, :c, :l, 'annonce', :r, :u, 0)
            ON DUPLICATE KEY UPDATE titre=VALUES(titre), corps=VALUES(corps), lien=VALUES(lien), urgent=VALUES(urgent), lu=0, created_at=NOW()");
        foreach ($dids as $d) {
            $ins->execute(array(':d' => $d, ':t' => $titre, ':c' => $corps, ':l' => $lien, ':r' => (string)$refId, ':u' => $urgent ? 1 : 0));
        }
    } catch (Exception $e) {}
}

function ann_sign_file($name) {
    if (!defined('ANN_FILE_SECRET')) return '';
    $exp = time() + ANN_SIG_TTL;
    $sig = hash_hmac('sha256', $name . '|' . $exp, ANN_FILE_SECRET);
    return '/annonces/annonces_api.php?action=file&f=' . rawurlencode($name) . '&exp=' . $exp . '&sig=' . $sig;
}

function ann_upload_to_signed($url) {
    $url = (string)$url;
    if ($url === '') return '';
    if (strpos($url, ANN_UPLOAD_URL) === 0) {
        $name = rawurldecode(substr($url, strlen(ANN_UPLOAD_URL)));
        if ($name !== '' && strpos($name, '..') === false && preg_match('#^[A-Za-z0-9._-]+$#', $name)) return ann_sign_file($name);
        return '';
    }
    return $url;
}

function ann_row_out($conn, $ctx, $row, $withStats) {
    $medias = $row['medias'] ? json_decode($row['medias'], true) : array();
    if (!is_array($medias)) $medias = array();
    $roles = $row['roles'] ? json_decode($row['roles'], true) : array();
    if (!is_array($roles)) $roles = array();

    if (isset($medias['images']) && is_array($medias['images'])) {
        $medias['images'] = array_values(array_filter(array_map('ann_upload_to_signed', $medias['images']), function($u){ return $u !== ''; }));
    }
    if (isset($medias['documents']) && is_array($medias['documents'])) {
        foreach ($medias['documents'] as &$d) { if (is_array($d) && isset($d['url'])) $d['url'] = ann_upload_to_signed($d['url']); }
        unset($d);
    }
    $out = array(
        'id' => (int)$row['id'],
        'canal' => $row['canal'],
        'titre' => $row['titre'],
        'resume' => $row['resume'],
        'contenu' => $row['contenu'],
        'image' => $row['image'] ? ann_upload_to_signed($row['image']) : null,
        'medias' => $medias,
        'roles' => $roles,
        'epingle' => (int)$row['epingle'] ? true : false,
        'lecture_obligatoire' => (int)$row['lecture_obligatoire'] ? true : false,
        'restreint' => (is_array($roles) && !empty($roles)),
        'created_by' => $row['created_by'],
        'created_by_name' => $row['created_by_name'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'lu' => false,
    );
    if ($ctx['did']) {
        $st = $conn->prepare("SELECT 1 FROM annonce_lectures WHERE annonce_id = :a AND discord_id = :d LIMIT 1");
        $st->execute(array(':a' => $row['id'], ':d' => $ctx['did']));
        $out['lu'] = $st->fetch() ? true : false;
    }
    if ($withStats && $ctx['manage']) {
        $targets = ann_target_dids($conn, $row['roles']);
        $total = count($targets);
        $lus = 0;
        if ($total > 0) {
            $lst = $conn->prepare("SELECT discord_id FROM annonce_lectures WHERE annonce_id = :a");
            $lst->execute(array(':a' => $row['id']));
            $tset = array_flip($targets);
            foreach ($lst->fetchAll(PDO::FETCH_COLUMN) as $d) { if (isset($tset[$d])) $lus++; }
        }
        $out['stats'] = array('cibles' => $total, 'lus' => $lus, 'restants' => max(0, $total - $lus));
    }
    return $out;
}

function ann_safe_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (preg_match('/[\s"\'<>`]/', $u)) return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    if ($u[0] === '/' && (strlen($u) < 2 || $u[1] !== '/')) return $u;
    return '';
}
function ann_medias_clean($raw) {
    $m = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: array());
    $out = array('images' => array(), 'documents' => array(), 'liens' => array());
    if (isset($m['images']) && is_array($m['images'])) foreach ($m['images'] as $u) { if (count($out['images']) >= 30) break; $s = ann_safe_url($u); if ($s !== '') $out['images'][] = $s; }
    if (isset($m['documents']) && is_array($m['documents'])) foreach ($m['documents'] as $d) { if (count($out['documents']) >= 30) break; if (is_array($d) && !empty($d['url'])) { $s = ann_safe_url($d['url']); if ($s !== '') $out['documents'][] = array('url' => $s, 'nom' => mb_substr((string)(isset($d['nom']) ? $d['nom'] : 'Document'), 0, 160)); } }
    if (isset($m['liens']) && is_array($m['liens'])) foreach ($m['liens'] as $l) { if (count($out['liens']) >= 30) break; if (is_array($l) && !empty($l['url'])) { $s = ann_safe_url($l['url']); if ($s !== '') $out['liens'][] = array('url' => $s, 'label' => mb_substr((string)(isset($l['label']) ? $l['label'] : $s), 0, 200)); } }
    return $out;
}
function ann_to_webp($src, $mime, $dest, $maxDim = 1600, $quality = 80) {
    if (!function_exists('imagewebp')) return false;
    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($src); break;
        case 'image/png':  $img = @imagecreatefrompng($src); break;
        case 'image/gif':  $img = @imagecreatefromgif($src); break;
        case 'image/webp': $img = @imagecreatefromwebp($src); break;
        default: return false;
    }
    if (!$img) return false;
    $w = imagesx($img); $h = imagesy($img);
    if ($w > 6000 || $h > 6000) { imagedestroy($img); return false; }
    $scale = min(1, $maxDim / max($w, $h));
    if ($scale < 1) {
        $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
        $r = imagecreatetruecolor($nw, $nh);
        imagealphablending($r, false); imagesavealpha($r, true);
        imagecopyresampled($r, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img); $img = $r;
    }
    $ok = imagewebp($img, $dest, $quality);
    imagedestroy($img);
    return $ok;
}

function ann_delete_files($row) {
    $urls = array();
    if (!empty($row['image'])) $urls[] = $row['image'];
    $m = $row['medias'] ? json_decode($row['medias'], true) : array();
    if (is_array($m)) {
        if (isset($m['images']) && is_array($m['images'])) foreach ($m['images'] as $u) $urls[] = $u;
        if (isset($m['documents']) && is_array($m['documents'])) foreach ($m['documents'] as $d) { if (is_array($d) && isset($d['url'])) $urls[] = $d['url']; }
    }
    $baseR = realpath(ANN_UPLOAD_DIR);
    foreach ($urls as $u) {
        if (strpos((string)$u, ANN_UPLOAD_URL) !== 0) continue;
        $name = rawurldecode(substr((string)$u, strlen(ANN_UPLOAD_URL)));
        if ($name === '' || strpos($name, '..') !== false || !preg_match('#^[A-Za-z0-9._-]+$#', $name)) continue;
        $real = realpath(ANN_UPLOAD_DIR . $name);
        if ($real && $baseR && strpos($real, $baseR) === 0) @unlink($real);
    }
}
function ann_guild_roles() {
    $cache = sys_get_temp_dir() . '/cid_roles_cache.json';
    if (is_file($cache) && (time() - filemtime($cache) < 600)) {
        $c = json_decode(@file_get_contents($cache), true);
        if (is_array($c)) return $c;
    }
    $env = array();
    $lines = @file(__DIR__ . '/../../bot/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) foreach ($lines as $line) { $p = strpos($line, '='); if ($p !== false) $env[trim(substr($line, 0, $p))] = trim(trim(substr($line, $p + 1)), "\"'"); }
    $token = isset($env['BOT_TOKEN']) ? $env['BOT_TOKEN'] : '';
    $guild = isset($env['GUILD_ID']) ? $env['GUILD_ID'] : '';
    if (!$token || !$guild || !function_exists('curl_init')) {
        $c = is_file($cache) ? json_decode(@file_get_contents($cache), true) : array();
        return is_array($c) ? $c : array();
    }
    $ch = curl_init("https://discord.com/api/v10/guilds/$guild/roles");
    curl_setopt_array($ch, array(CURLOPT_HTTPHEADER => array("Authorization: Bot $token"), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8));
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($res, true);
    if ($code !== 200 || !is_array($data)) { $c = is_file($cache) ? json_decode(@file_get_contents($cache), true) : array(); return is_array($c) ? $c : array(); }
    $roles = array();
    foreach ($data as $r) { if (!isset($r['id']) || $r['id'] === $guild) continue; $roles[] = array('id' => $r['id'], 'name' => $r['name'], 'color' => isset($r['color']) ? $r['color'] : 0, 'position' => isset($r['position']) ? $r['position'] : 0); }
    usort($roles, function($a, $b) { return $b['position'] - $a['position']; });
    @file_put_contents($cache, json_encode($roles));
    return $roles;
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {

    if ($action === 'file') {
        if (!defined('ANN_FILE_SECRET')) { http_response_code(404); exit; }
        $f = isset($_GET['f']) ? (string)$_GET['f'] : '';
        $exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';
        if ($f === '' || strpos($f, '..') !== false || !preg_match('#^[A-Za-z0-9._-]+$#', $f)) { http_response_code(400); exit; }
        if ($exp < time()) { http_response_code(403); exit; }
        $expected = hash_hmac('sha256', $f . '|' . $exp, ANN_FILE_SECRET);
        if (!hash_equals($expected, $sig)) { http_response_code(403); exit; }
        if (!is_file(ANN_UPLOAD_DIR . $f)) { http_response_code(404); exit; }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $types = array('webp'=>'image/webp','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','pdf'=>'application/pdf');
        header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('X-Accel-Redirect: ' . ANN_UPLOAD_URL . rawurlencode($f));
        exit;
    }

    $ctx = ann_actor($conn);
    if (!$ctx['did']) mdt_error(401, 'E-SEC-401', 'Authentification requise');

    if ($action === 'bootstrap') {
        $rows = $conn->query("SELECT * FROM annonces ORDER BY epingle DESC, ordre ASC, created_at DESC")->fetchAll();
        $out = array();
        foreach ($rows as $row) {
            if (!ann_can_view($ctx, $row['roles'])) continue;
            $out[] = ann_row_out($conn, $ctx, $row, true);
        }
        echo json_encode(array(
            'access'   => true,
            'me'       => array('discord_id' => $ctx['did'], 'name' => $ctx['name']),
            'perms'    => array('manage' => $ctx['manage']),
            'canaux'   => $GLOBALS['ANN_CANAUX'],
            'annonces' => $out,
        ), ANN_JSON);
    }

    elseif ($action === 'home') {
        $luIds = array();
        try {
            $st = $conn->prepare("SELECT annonce_id FROM annonce_lectures WHERE discord_id = :d");
            $st->execute(array(':d' => $ctx['did']));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) $luIds[(int)$aid] = 1;
        } catch (Exception $e) {}

        $rows = $conn->query("SELECT id, canal, titre, resume, roles, epingle, lecture_obligatoire, created_by_name, created_at FROM annonces ORDER BY epingle DESC, ordre ASC, created_at DESC")->fetchAll();
        $byCanal = array();
        foreach ($GLOBALS['ANN_CANAUX'] as $c) $byCanal[$c] = array();
        foreach ($rows as $row) {
            $c = $row['canal'];
            if (!isset($byCanal[$c]) || count($byCanal[$c]) >= 5) continue;
            if (!ann_can_view($ctx, $row['roles'])) continue;
            $byCanal[$c][] = array(
                'id' => (int)$row['id'],
                'canal' => $c,
                'titre' => $row['titre'],
                'resume' => $row['resume'],
                'epingle' => (bool)$row['epingle'],
                'lecture_obligatoire' => (bool)$row['lecture_obligatoire'],
                'restreint' => (bool)($row['roles'] && count((array)json_decode($row['roles'], true)) > 0),
                'created_by_name' => $row['created_by_name'],
                'created_at' => $row['created_at'],
                'lu' => isset($luIds[(int)$row['id']]),
            );
        }
        echo json_encode(array('success' => true, 'canaux' => $byCanal), ANN_JSON);
    }

    elseif ($action === 'get') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $st = $conn->prepare("SELECT * FROM annonces WHERE id = :id");
        $st->execute(array(':id' => $id));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-404', 'Annonce introuvable');
        if (!ann_can_view($ctx, $row['roles'])) mdt_error(403, 'E-SEC-403', 'Accès refusé');
        echo json_encode(array('success' => true, 'annonce' => ann_row_out($conn, $ctx, $row, true)), ANN_JSON);
    }

    elseif ($action === 'ack') {
        mdt_post_only();
        $data = mdt_get_post_data();
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $st = $conn->prepare("SELECT roles FROM annonces WHERE id = :id");
        $st->execute(array(':id' => $id));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-404', 'Annonce introuvable');
        if (!ann_can_view($ctx, $row['roles'])) mdt_error(403, 'E-SEC-403', 'Accès refusé');
        $conn->prepare("INSERT IGNORE INTO annonce_lectures (annonce_id, discord_id, nom) VALUES (:a, :d, :n)")
             ->execute(array(':a' => $id, ':d' => $ctx['did'], ':n' => $ctx['name']));
        try { $conn->prepare("UPDATE notifications SET lu = 1 WHERE discord_id = :d AND ref_type = 'annonce' AND ref_id = :r")->execute(array(':d' => $ctx['did'], ':r' => (string)$id)); } catch (Exception $e) {}
        echo json_encode(array('success' => true), ANN_JSON);
    }

    elseif ($action === 'readers') {
        if (!$ctx['manage']) mdt_error(403, 'E-SEC-403', 'Réservé au rôle MRD');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $st = $conn->prepare("SELECT roles, titre FROM annonces WHERE id = :id");
        $st->execute(array(':id' => $id));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-404', 'Annonce introuvable');
        $targets = ann_target_dids($conn, $row['roles']);
        $lu = array();
        $lst = $conn->prepare("SELECT discord_id, nom, lu_at FROM annonce_lectures WHERE annonce_id = :a");
        $lst->execute(array(':a' => $id));
        foreach ($lst->fetchAll() as $r) $lu[$r['discord_id']] = array('nom' => $r['nom'], 'lu_at' => $r['lu_at']);
        $names = array();
        if (!empty($targets)) {
            $in = implode(',', array_fill(0, count($targets), '?'));
            try {
                $q = $conn->prepare("SELECT discord_id, COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username, discord_id) AS nom FROM users WHERE discord_id IN ($in)");
                $q->execute(array_values($targets));
                foreach ($q->fetchAll() as $r) $names[$r['discord_id']] = $r['nom'];
            } catch (Exception $e) {}
        }
        $read = array(); $unread = array();
        foreach ($targets as $d) {
            $nom = (isset($lu[$d]) && $lu[$d]['nom']) ? $lu[$d]['nom'] : (isset($names[$d]) ? $names[$d] : $d);
            if (isset($lu[$d])) $read[] = array('discord_id' => $d, 'nom' => $nom, 'lu_at' => $lu[$d]['lu_at']);
            else $unread[] = array('discord_id' => $d, 'nom' => $nom);
        }
        $total = count($targets); $nlu = count($read);
        echo json_encode(array(
            'success' => true, 'titre' => $row['titre'],
            'stats' => array('cibles' => $total, 'lus' => $nlu, 'restants' => $total - $nlu, 'pct' => $total ? (int)round($nlu * 100 / $total) : 0),
            'read' => $read, 'unread' => $unread,
        ), ANN_JSON);
    }

    elseif ($action === 'save') {
        if (!$ctx['manage']) mdt_error(403, 'E-SEC-403', 'Réservé au rôle MRD');
        $data = mdt_get_post_data();
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $canal = isset($data['canal']) ? $data['canal'] : '';
        if (!in_array($canal, $GLOBALS['ANN_CANAUX'], true)) mdt_error(400, 'E-400', 'Canal invalide');
        $titre = trim(isset($data['titre']) ? (string)$data['titre'] : '');
        if ($titre === '') mdt_error(400, 'E-400', 'Titre requis');
        $titre = mb_substr($titre, 0, 255);
        $resume = isset($data['resume']) ? mb_substr(trim((string)$data['resume']), 0, 500) : '';
        $contenu = isset($data['contenu']) ? mb_substr((string)$data['contenu'], 0, 100000) : '';
        $image = ann_safe_url(isset($data['image']) ? $data['image'] : '');
        $medias = json_encode(ann_medias_clean(isset($data['medias']) ? $data['medias'] : array()), ANN_JSON);
        $roles = (isset($data['roles']) && is_array($data['roles'])) ? array_slice(array_values(array_filter(array_map('strval', $data['roles']))), 0, 50) : array();
        $rolesJson = json_encode($roles, ANN_JSON);
        $epingle = !empty($data['epingle']) ? 1 : 0;
        $obl = !empty($data['lecture_obligatoire']) ? 1 : 0;
        if ($id > 0) {
            $chk = $conn->prepare("SELECT id FROM annonces WHERE id = :id"); $chk->execute(array(':id' => $id));
            if (!$chk->fetch()) mdt_error(404, 'E-404', 'Annonce introuvable');
            $conn->prepare("UPDATE annonces SET canal=:c, titre=:t, resume=:r, contenu=:co, image=:im, medias=:m, roles=:ro, epingle=:e, lecture_obligatoire=:o WHERE id=:id")
                 ->execute(array(':c'=>$canal, ':t'=>$titre, ':r'=>$resume, ':co'=>$contenu, ':im'=>$image, ':m'=>$medias, ':ro'=>$rolesJson, ':e'=>$epingle, ':o'=>$obl, ':id'=>$id));
            if (function_exists('mdt_seclog')) mdt_seclog($conn, 'annonce_update', 'info', array('id'=>$id), $ctx['did']);
            echo json_encode(array('success' => true, 'id' => $id), ANN_JSON);
        } else {
            $conn->prepare("INSERT INTO annonces (canal, titre, resume, contenu, image, medias, roles, epingle, lecture_obligatoire, created_by, created_by_name) VALUES (:c,:t,:r,:co,:im,:m,:ro,:e,:o,:by,:byn)")
                 ->execute(array(':c'=>$canal, ':t'=>$titre, ':r'=>$resume, ':co'=>$contenu, ':im'=>$image, ':m'=>$medias, ':ro'=>$rolesJson, ':e'=>$epingle, ':o'=>$obl, ':by'=>$ctx['did'], ':byn'=>$ctx['name']));
            $newId = (int)$conn->lastInsertId();
            $canalLabel = array('divisions'=>'Informations Divisions','supervision'=>'Informations Supervision','news'=>'MDT News');
            $lbl = isset($canalLabel[$canal]) ? $canalLabel[$canal] : $canal;
            $targets = ann_target_dids($conn, $rolesJson);
            $targets = array_values(array_filter($targets, function($d) use ($ctx) { return $d !== $ctx['did']; }));
            ann_notify($conn, $targets, ($obl ? '📌 Lecture obligatoire — ' : '') . $lbl, $titre, '/annonces/' . $newId, $newId, $obl);
            if (function_exists('mdt_seclog')) mdt_seclog($conn, 'annonce_create', 'info', array('id'=>$newId, 'canal'=>$canal, 'obligatoire'=>$obl, 'cibles'=>count($targets)), $ctx['did']);
            echo json_encode(array('success' => true, 'id' => $newId, 'notifies' => count($targets)), ANN_JSON);
        }
    }

    elseif ($action === 'delete') {
        if (!$ctx['manage']) mdt_error(403, 'E-SEC-403', 'Réservé au rôle MRD');
        $data = mdt_get_post_data();
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $st = $conn->prepare("SELECT image, medias FROM annonces WHERE id = :id"); $st->execute(array(':id' => $id));
        $row = $st->fetch();
        $conn->prepare("DELETE FROM annonces WHERE id = :id")->execute(array(':id' => $id));
        $conn->prepare("DELETE FROM annonce_lectures WHERE annonce_id = :id")->execute(array(':id' => $id));
        $conn->prepare("DELETE FROM notifications WHERE ref_type='annonce' AND ref_id = :id")->execute(array(':id' => (string)$id));
        if ($row) ann_delete_files($row);
        if (function_exists('mdt_seclog')) mdt_seclog($conn, 'annonce_delete', 'medium', array('id'=>$id), $ctx['did']);
        echo json_encode(array('success' => true), ANN_JSON);
    }

    elseif ($action === 'upload') {
        if (!$ctx['manage']) mdt_error(403, 'E-SEC-403', 'Réservé au rôle MRD');
        if (empty($_FILES['file'])) mdt_error(400, 'E-400', 'Aucun fichier');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) mdt_error(400, 'E-400', 'Erreur upload');
        if ($f['size'] > ANN_MAX_SIZE) mdt_error(400, 'E-400', 'Fichier trop lourd (max 25 Mo)');
        if (function_exists('disk_free_space')) { $free = @disk_free_space(ANN_UPLOAD_DIR); if ($free !== false && $free < 2 * 1024 * 1024 * 1024) mdt_error(507, 'E-507', 'Espace disque insuffisant'); }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        $allowed = array('image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf');
        if (!isset($allowed[$mime])) mdt_error(400, 'E-400', 'Type non autorisé (image ou PDF)');
        if (!is_dir(ANN_UPLOAD_DIR)) @mkdir(ANN_UPLOAD_DIR, 0775, true);
        if (!is_writable(ANN_UPLOAD_DIR)) mdt_error(500, 'E-500', "Dossier d'upload non accessible");
        $base = bin2hex(random_bytes(9)) . '_' . time();
        if ($mime === 'application/pdf') {
            $name = $base . '.pdf';
            if (!move_uploaded_file($f['tmp_name'], ANN_UPLOAD_DIR . $name) || !is_file(ANN_UPLOAD_DIR . $name)) mdt_error(500, 'E-500', "Échec de l'enregistrement");
            $orig = mb_substr(preg_replace('/[^\w.\- ]/u', '', (string)$f['name']), 0, 160);
            echo json_encode(array('success' => true, 'url' => ANN_UPLOAD_URL . $name, 'preview' => ann_sign_file($name), 'type' => 'pdf', 'nom' => $orig), ANN_JSON);
        } else {
            $name = $base . '.webp';
            if (!ann_to_webp($f['tmp_name'], $mime, ANN_UPLOAD_DIR . $name)) {
                $name = $base . '.' . $allowed[$mime];
                if (!move_uploaded_file($f['tmp_name'], ANN_UPLOAD_DIR . $name)) mdt_error(500, 'E-500', "Échec de l'enregistrement");
            }
            if (!is_file(ANN_UPLOAD_DIR . $name)) mdt_error(500, 'E-500', "Échec de l'enregistrement");
            echo json_encode(array('success' => true, 'url' => ANN_UPLOAD_URL . $name, 'preview' => ann_sign_file($name), 'type' => 'image'), ANN_JSON);
        }
    }

    elseif ($action === 'guild_roles') {
        if (!$ctx['manage']) mdt_error(403, 'E-SEC-403', 'Réservé au rôle MRD');
        echo json_encode(array('success' => true, 'roles' => ann_guild_roles()), ANN_JSON);
    }

    else { mdt_unknown_action(); }

} catch (Exception $e) {
    mdt_error(500, 'E-ANN-500', 'Erreur serveur', $e->getMessage());
}
