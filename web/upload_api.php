<?php
require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$me = mdt_require_auth($conn, 'upload');
$did = isset($me['discord_id']) ? (string)$me['discord_id'] : '';
$isAdmin = function_exists('mdt_is_admin') ? mdt_is_admin($conn, $did) : false;

$action = isset($_GET['action']) ? $_GET['action'] : '';
$dir = __DIR__ . '/videos/';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$MAX = 500 * 1024 * 1024;
$EXT = array('mp4', 'mov', 'webm', 'gif', 'jpg', 'jpeg', 'png', 'webp');
$MIME = array('video/mp4', 'video/quicktime', 'video/webm', 'image/gif', 'image/jpeg', 'image/png', 'image/webp');

function up_item($r) {
    return array(
        'id' => (int)$r['id'],
        'name' => $r['orig_name'],
        'stored' => $r['stored_name'],
        'url' => '/videos/' . $r['stored_name'],
        'kind' => $r['kind'],
        'mime' => $r['mime'],
        'size' => (int)$r['size'],
        'created_at' => $r['created_at'],
    );
}

function up_process_image($tmp, $mime, $dest, $isUpload) {
    $gi = @getimagesize($tmp);
    if (!$gi || (int)$gi[0] < 1 || (int)$gi[1] < 1) mdt_error(400, 'E-UP-701', 'Image invalide');
    if ((int)$gi[0] * (int)$gi[1] > 50000000) mdt_error(400, 'E-UP-702', 'Image trop grande');
    if ($mime === 'image/gif') {
        $sig = (string)@file_get_contents($tmp, false, null, 0, 6);
        if ($sig !== 'GIF87a' && $sig !== 'GIF89a') mdt_error(400, 'E-UP-701', 'GIF invalide');
        $moved = $isUpload ? move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$moved) mdt_error(500, 'E-UP-703', 'Erreur serveur');
    } else {
        $img = ($mime === 'image/png') ? @imagecreatefrompng($tmp) : (($mime === 'image/webp') ? @imagecreatefromwebp($tmp) : @imagecreatefromjpeg($tmp));
        if (!$img) mdt_error(400, 'E-UP-701', 'Image illisible');
        if ($mime === 'image/png') { imagealphablending($img, false); imagesavealpha($img, true); }
        $ok = ($mime === 'image/png') ? imagepng($img, $dest, 6) : (($mime === 'image/webp') ? imagewebp($img, $dest, 90) : imagejpeg($img, $dest, 90));
        imagedestroy($img);
        if (!$ok) { if (is_file($dest)) @unlink($dest); mdt_error(500, 'E-UP-703', 'Réencodage échoué'); }
    }
}

function up_host_public($host) {
    $host = trim((string)$host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : @gethostbynamel($host);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;
        $l = ip2long($ip);
        if ($l === false) return false;
        $blocked = array(
            array('0.0.0.0', 8), array('10.0.0.0', 8), array('100.64.0.0', 10), array('127.0.0.0', 8),
            array('169.254.0.0', 16), array('172.16.0.0', 12), array('192.168.0.0', 16),
            array('192.0.0.0', 24), array('198.18.0.0', 15), array('224.0.0.0', 3),
        );
        foreach ($blocked as $b) {
            $mask = -1 << (32 - $b[1]);
            if (($l & $mask) === (ip2long($b[0]) & $mask)) return false;
        }
    }
    return true;
}

if ($action === 'list') {
    $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $per = (int)(isset($_GET['per_page']) ? $_GET['per_page'] : 24);
    if ($per < 6) $per = 6; if ($per > 60) $per = 60;
    $total = 0;
    $st = $conn->prepare("SELECT COUNT(*) FROM upload_files WHERE owner_did = ?");
    $st->execute(array($did));
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) $page = $pages;
    $off = ($page - 1) * $per;
    $q = $conn->prepare("SELECT * FROM upload_files WHERE owner_did = ? ORDER BY id DESC LIMIT $per OFFSET $off");
    $q->execute(array($did));
    $items = array();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = up_item($r);
    echo json_encode(array('success' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => $pages), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'upload') {
    mdt_post_only();
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) mdt_error(400, 'E-UP-400', 'Aucun fichier');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) mdt_error(400, 'E-UP-703', 'Échec de l\'envoi');
    if ($f['size'] <= 0 || $f['size'] > $MAX) mdt_error(400, 'E-UP-702', 'Fichier trop volumineux (max 500 Mo)');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $EXT, true)) mdt_error(400, 'E-UP-701', 'Format non autorisé');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if (!in_array($mime, $MIME, true)) mdt_error(400, 'E-UP-701', 'Type de fichier non autorisé');
    $kind = in_array($mime, array('video/mp4', 'video/quicktime', 'video/webm'), true) ? 'video' : 'image';
    $stored = 'up_' . bin2hex(random_bytes(9)) . '.' . $ext;
    $dest = $dir . $stored;
    $tmp = $f['tmp_name'];

    if ($kind === 'image') {
        up_process_image($tmp, $mime, $dest, true);
    } else {
        $head = (string)@file_get_contents($tmp, false, null, 0, 16);
        $atom = strlen($head) >= 8 ? substr($head, 4, 4) : '';
        $isMp4Mov = in_array($atom, array('ftyp', 'moov', 'mdat', 'free', 'wide', 'skip', 'pnot'), true);
        $isWebm = substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
        if (!$isMp4Mov && !$isWebm) mdt_error(400, 'E-UP-701', 'Vidéo non reconnue');
        if (!move_uploaded_file($tmp, $dest)) mdt_error(500, 'E-UP-703', 'Erreur serveur');
    }
    @chmod($dest, 0644);
    $finalSize = (int)@filesize($dest);
    $orig = mb_substr(preg_replace('/[\x00-\x1f]/', '', (string)$f['name']), 0, 255);
    $ins = $conn->prepare("INSERT INTO upload_files (stored_name, orig_name, owner_did, mime, kind, size, created_at) VALUES (?,?,?,?,?,?,NOW())");
    $ins->execute(array($stored, $orig, $did, $mime, $kind, $finalSize, ));
    $row = array('id' => $conn->lastInsertId(), 'orig_name' => $orig, 'stored_name' => $stored, 'owner_did' => $did, 'mime' => $mime, 'kind' => $kind, 'size' => $finalSize, 'created_at' => date('Y-m-d H:i:s'));
    echo json_encode(array('success' => true, 'item' => up_item($row)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'import') {
    mdt_post_only();
    $d = mdt_get_post_data('E-UP-400'); if (!is_array($d)) $d = array();
    $url = isset($d['url']) ? trim((string)$d['url']) : '';
    if ($url === '' || strlen($url) > 2000) mdt_error(400, 'E-UP-710', 'URL requise');
    $IMPORT_MAX = 25 * 1024 * 1024;

    $body = null; $finalUrl = $url;
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($finalUrl);
        if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), array('http', 'https'), true)) {
            mdt_error(400, 'E-UP-710', 'URL invalide (http/https uniquement)');
        }
        if (!up_host_public($p['host'])) mdt_error(400, 'E-UP-711', 'Hôte non autorisé');

        $ch = curl_init($finalUrl);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'MDT-Tool-Import/1.0',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($ch2, $dlTotal, $dlNow) use ($IMPORT_MAX) {
                return ($dlNow > $IMPORT_MAX || $dlTotal > $IMPORT_MAX) ? 1 : 0;
            },
        ));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redir = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($code >= 300 && $code < 400 && $redir) { $finalUrl = $redir; continue; }
        if ($code !== 200 || $resp === false || $resp === '') mdt_error(400, 'E-UP-712', 'Téléchargement impossible (HTTP ' . $code . ')');
        if (strlen($resp) > $IMPORT_MAX) mdt_error(400, 'E-UP-702', 'Image trop volumineuse (max 25 Mo)');
        $body = $resp;
        break;
    }
    if ($body === null) mdt_error(400, 'E-UP-712', 'Trop de redirections');

    $tmp = tempnam(sys_get_temp_dir(), 'upimp_');
    if ($tmp === false || @file_put_contents($tmp, $body) === false) mdt_error(500, 'E-UP-703', 'Erreur serveur');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $extMap = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    if (!isset($extMap[$mime])) { @unlink($tmp); mdt_error(400, 'E-UP-701', 'Le lien ne pointe pas vers une image (jpg/png/webp/gif)'); }
    $ext = $extMap[$mime];
    $stored = 'up_' . bin2hex(random_bytes(9)) . '.' . $ext;
    $dest = $dir . $stored;
    up_process_image($tmp, $mime, $dest, false);
    if (is_file($tmp)) @unlink($tmp);
    @chmod($dest, 0644);
    $finalSize = (int)@filesize($dest);
    $urlPath = parse_url($url, PHP_URL_PATH);
    $orig = mb_substr(preg_replace('/[\x00-\x1f]/', '', basename($urlPath ? $urlPath : 'import')), 0, 200);
    if ($orig === '' || $orig === '/') $orig = 'import.' . $ext;
    $ins = $conn->prepare("INSERT INTO upload_files (stored_name, orig_name, owner_did, mime, kind, size, created_at) VALUES (?,?,?,?,?,?,NOW())");
    $ins->execute(array($stored, $orig, $did, $mime, 'image', $finalSize));
    $row = array('id' => $conn->lastInsertId(), 'orig_name' => $orig, 'stored_name' => $stored, 'owner_did' => $did, 'mime' => $mime, 'kind' => 'image', 'size' => $finalSize, 'created_at' => date('Y-m-d H:i:s'));
    echo json_encode(array('success' => true, 'item' => up_item($row)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    mdt_post_only();
    $d = mdt_get_post_data('E-UP-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    if ($id <= 0) mdt_error(400, 'E-UP-400', 'id requis');
    $q = $conn->prepare("SELECT * FROM upload_files WHERE id = ? LIMIT 1");
    $q->execute(array($id));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) mdt_error(404, 'E-UP-404', 'Fichier introuvable');
    if ($r['owner_did'] !== $did && !$isAdmin) mdt_error(403, 'E-UP-403', 'Non autorisé');
    $p = $dir . basename($r['stored_name']);
    if (is_file($p)) @unlink($p);
    $conn->prepare("DELETE FROM upload_files WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true));
    exit;
}

echo json_encode(array('success' => false, 'error' => 'Action inconnue', 'code' => 'E-UP-400'));
