<?php
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../mdt_embed.php';
$__msgSecret = __DIR__ . '/../../scripts/messagerie_secret.php';
if (is_file($__msgSecret)) require_once $__msgSecret;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$MSG_IMG = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp');
$MSG_VID = array('video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov');
$MSG_PDF = array('application/pdf' => 'pdf');
$MSG_BUR = array(
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/msword' => 'doc',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.ms-powerpoint' => 'ppt',
);
define('MSG_MAX_IMG', 10 * 1024 * 1024);
define('MSG_MAX_VID', 100 * 1024 * 1024);
define('MSG_MAX_PDF', 25 * 1024 * 1024);
define('MSG_MAX_BUR', 25 * 1024 * 1024);
define('MSG_MAX_IMG_PX', 4000);
define('MSG_MIN_DISK_FREE', 2 * 1024 * 1024 * 1024);
define('MSG_UPLOAD_DIR', __DIR__ . '/uploads/');
define('MSG_BODY_MAX', 4000);
define('MSG_QUOTA_FILES', 20);

$action = isset($_GET['action']) ? $_GET['action'] : '';
define('MSG_UPLOAD_URL', '/messagerie/uploads/');
define('MSG_SIG_TTL', 86400);
function msg_sign_file($name) {
    if (!defined('MSG_FILE_SECRET') || $name === '') return '';
    $exp = time() + MSG_SIG_TTL;
    $sig = hash_hmac('sha256', $name . '|' . $exp, MSG_FILE_SECRET);
    return '/messagerie/messagerie_api.php?action=file&f=' . rawurlencode($name) . '&exp=' . $exp . '&sig=' . $sig;
}
if ($action === 'file') {
    if (!defined('MSG_FILE_SECRET')) { http_response_code(404); exit; }
    $f = isset($_GET['f']) ? (string)$_GET['f'] : '';
    $exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
    $sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';
    if ($f === '' || strpos($f, '..') !== false || !preg_match('#^[A-Za-z0-9._-]+$#', $f)) { http_response_code(400); exit; }
    if ($exp < time()) { http_response_code(403); exit; }
    if (!hash_equals(hash_hmac('sha256', $f . '|' . $exp, MSG_FILE_SECRET), $sig)) { http_response_code(403); exit; }
    if (!is_file(MSG_UPLOAD_DIR . $f)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $types = array('webp'=>'image/webp','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','doc'=>'application/msword','xls'=>'application/vnd.ms-excel','ppt'=>'application/vnd.ms-powerpoint');
    $mime = isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';
    $inline = in_array($ext, array('webp','png','jpg','jpeg','gif','mp4','webm','mov','pdf'), true);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($f) . '"');
    header('Cache-Control: private, max-age=3600');
    header('X-Accel-Redirect: ' . MSG_UPLOAD_URL . rawurlencode($f));
    exit;
}
$me = mdt_require_auth($conn, 'messagerie');
$myDid = isset($me['discord_id']) ? (string)$me['discord_id'] : '';
$isAdmin = mdt_is_admin($conn, $myDid);

define('MSG_DDL_VERSION', '2026-08-11.1');

function msg_ensure($conn) {
    try {
        $st = $conn->query("SELECT mv FROM msg_meta WHERE mk = 'ddl_version'");
        if ($st && $st->fetchColumn() === MSG_DDL_VERSION) return;
    } catch (PDOException $e) {}
    msg_ensure_run($conn);
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS msg_meta (mk VARCHAR(40) NOT NULL PRIMARY KEY, mv TEXT NULL)
                     ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->prepare("INSERT INTO msg_meta (mk, mv) VALUES ('ddl_version', :v)
                        ON DUPLICATE KEY UPDATE mv = :v2")
            ->execute(array(':v' => MSG_DDL_VERSION, ':v2' => MSG_DDL_VERSION));
    } catch (PDOException $e) {}
}

function msg_ensure_run($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_conv (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('channel','dm','group') NOT NULL DEFAULT 'channel',
        name VARCHAR(120) DEFAULT NULL,
        roles LONGTEXT DEFAULT NULL,
        color VARCHAR(9) DEFAULT NULL,
        created_by VARCHAR(32) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        last_at DATETIME NOT NULL,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_type (type, archived))");
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conv_id INT NOT NULL,
        discord_id VARCHAR(32) NOT NULL,
        kind ENUM('owner','member') NOT NULL DEFAULT 'member',
        last_read_id BIGINT NOT NULL DEFAULT 0,
        muted TINYINT(1) NOT NULL DEFAULT 0,
        joined_at DATETIME NOT NULL,
        UNIQUE KEY uq_member (conv_id, discord_id),
        INDEX idx_did (discord_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        conv_id INT NOT NULL,
        author_did VARCHAR(32) NOT NULL,
        author_name VARCHAR(120) DEFAULT NULL,
        body TEXT DEFAULT NULL,
        mentions LONGTEXT DEFAULT NULL,
        reply_to BIGINT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        edited_at DATETIME DEFAULT NULL,
        deleted TINYINT(1) NOT NULL DEFAULT 0,
        embed LONGTEXT DEFAULT NULL,
        INDEX idx_conv (conv_id, id))");
    try { $conn->exec("ALTER TABLE msg_messages ADD COLUMN embed LONGTEXT DEFAULT NULL"); } catch (PDOException $e) {}
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_attachments (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT DEFAULT NULL,
        conv_id INT DEFAULT NULL,
        kind ENUM('image','video','pdf','bureau') NOT NULL,
        stored_name VARCHAR(120) NOT NULL UNIQUE,
        original_name VARCHAR(160) DEFAULT NULL,
        mime VARCHAR(120) DEFAULT NULL,
        ext VARCHAR(8) DEFAULT NULL,
        taille BIGINT UNSIGNED NOT NULL DEFAULT 0,
        uploaded_by VARCHAR(32) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        deleted_at DATETIME DEFAULT NULL,
        INDEX idx_msg (message_id, deleted_at))");
    $n = (int)$conn->query("SELECT COUNT(*) FROM msg_conv WHERE type='channel'")->fetchColumn();
    if ($n === 0) {
        $conn->exec("INSERT INTO msg_conv (type,name,roles,color,created_by,created_at,last_at) VALUES ('channel','Général',NULL,'#6366f1','system',NOW(),NOW())");
    }
    foreach (array("ALTER TABLE msg_conv ADD COLUMN logo INT DEFAULT NULL", "ALTER TABLE msg_conv ADD COLUMN folder_id INT DEFAULT NULL", "ALTER TABLE msg_conv ADD COLUMN ordre INT NOT NULL DEFAULT 0", "ALTER TABLE msg_conv MODIFY type ENUM('channel','dm','group','thread') NOT NULL DEFAULT 'channel'", "ALTER TABLE msg_conv ADD COLUMN parent_conv_id INT DEFAULT NULL", "ALTER TABLE msg_conv ADD COLUMN parent_msg_id BIGINT DEFAULT NULL", "ALTER TABLE msg_members MODIFY kind ENUM('owner','member','invited') NOT NULL DEFAULT 'member'") as $alt) { try { $conn->exec($alt); } catch (Exception $e) {} }
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_folder (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, ordre INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)");
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_typing (conv_id INT NOT NULL, discord_id VARCHAR(32) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (conv_id, discord_id))");
    try { $conn->exec("ALTER TABLE msg_messages ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    $conn->exec("CREATE TABLE IF NOT EXISTS msg_reactions (message_id BIGINT NOT NULL, discord_id VARCHAR(32) NOT NULL, emoji VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (message_id, discord_id, emoji), INDEX idx_msg (message_id))");

    try { $conn->exec("ALTER TABLE msg_reactions MODIFY emoji VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL"); } catch (Exception $e) {}
}
msg_ensure($conn);

function msg_actor($conn) {
    static $c = null;
    if ($c !== null) return $c;
    global $myDid, $isAdmin;
    $roles = array(); $name = null;
    if ($myDid !== '') {
        try {
            $st = $conn->prepare("SELECT discord_roles, COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username, discord_id) AS nom FROM users WHERE discord_id = :d LIMIT 1");
            $st->execute(array(':d' => $myDid));
            $row = $st->fetch();
            if ($row) {
                $r = $row['discord_roles'] ? json_decode($row['discord_roles'], true) : array();
                if (is_array($r)) $roles = $r;
                $name = $row['nom'];
            }
        } catch (Exception $e) {}
    }
    $c = array('did' => $myDid, 'roles' => $roles, 'name' => $name, 'admin' => $isAdmin);
    return $c;
}

function msg_user_map($conn, $dids) {
    $dids = array_values(array_unique(array_filter(array_map('strval', $dids), function ($x) { return $x !== ''; })));
    if (!$dids) return array();
    $in = implode(',', array_fill(0, count($dids), '?'));
    $st = $conn->prepare("SELECT discord_id, discord_avatar, COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username, discord_id) AS nom FROM users WHERE discord_id IN ($in)");
    $st->execute($dids);
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['discord_id']] = array('name' => $r['nom'], 'avatar' => msg_avatar($r['discord_avatar']));
    }
    return $out;
}

function msg_avatar($raw) {
    $raw = (string)$raw;
    if ($raw === '') return null;
    if (preg_match('#^https://cdn\.discordapp\.com/[A-Za-z0-9/._-]+$#', $raw)) return $raw;
    return null;
}

function msg_role_dids($conn, $rolesJson) {
    $roles = $rolesJson ? json_decode($rolesJson, true) : array();
    if (!is_array($roles)) $roles = array();
    $dids = array();
    try {
        if (empty($roles)) {
            foreach ($conn->query("SELECT discord_id FROM users WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll(PDO::FETCH_COLUMN) as $d) $dids[] = (string)$d;
        } else {
            foreach ($conn->query("SELECT discord_id, discord_roles FROM users WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll() as $r) {
                $ur = $r['discord_roles'] ? json_decode($r['discord_roles'], true) : array();
                if (!is_array($ur)) continue;
                foreach ($roles as $need) { if (in_array($need, $ur, true)) { $dids[] = (string)$r['discord_id']; break; } }
            }
        }
    } catch (Exception $e) {}
    return array_values(array_unique($dids));
}

function msg_load_conv($conn, $id) {
    $st = $conn->prepare("SELECT * FROM msg_conv WHERE id = ?");
    $st->execute(array((int)$id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function msg_is_member($conn, $convId, $did) {
    $st = $conn->prepare("SELECT 1 FROM msg_members WHERE conv_id = ? AND discord_id = ? LIMIT 1");
    $st->execute(array((int)$convId, (string)$did));
    return (bool)$st->fetchColumn();
}

function msg_role_match($actor, $rolesJson) {
    $roles = $rolesJson ? json_decode($rolesJson, true) : array();
    if (!is_array($roles) || empty($roles)) return true;
    foreach ($roles as $r) { if (in_array($r, $actor['roles'], true)) return true; }
    return false;
}

function msg_can_access($conn, $actor, $conv) {
    if (!$conv) return false;
    if ($conv['type'] === 'thread') {
        $pc = isset($conv['parent_conv_id']) ? (int)$conv['parent_conv_id'] : 0;
        if (!$pc) return false;
        return msg_can_access($conn, $actor, msg_load_conv($conn, $pc));
    }
    if ($conv['type'] === 'channel') {
        if ((int)$conv['archived'] === 1 && !$actor['admin']) return false;
        if ($actor['admin']) return true;
        if (msg_role_match($actor, $conv['roles'])) return true;
        return msg_is_member($conn, $conv['id'], $actor['did']);
    }
    return msg_is_member($conn, $conv['id'], $actor['did']);
}

function msg_ensure_member($conn, $convId, $did, $kind = 'member') {
    $st = $conn->prepare("INSERT INTO msg_members (conv_id, discord_id, kind, last_read_id, muted, joined_at) VALUES (?,?,?,0,0,NOW()) ON DUPLICATE KEY UPDATE conv_id = conv_id");
    $st->execute(array((int)$convId, (string)$did, $kind));
}

function msg_last_read($conn, $convId, $did) {
    $st = $conn->prepare("SELECT last_read_id FROM msg_members WHERE conv_id = ? AND discord_id = ?");
    $st->execute(array((int)$convId, (string)$did));
    $v = $st->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

function msg_muted($conn, $convId, $did) {
    $st = $conn->prepare("SELECT muted FROM msg_members WHERE conv_id = ? AND discord_id = ?");
    $st->execute(array((int)$convId, (string)$did));
    return (int)$st->fetchColumn() === 1;
}

function msg_preview($body, $hasFile) {
    $b = trim(preg_replace('/\s+/', ' ', (string)$body));
    if ($b === '') return $hasFile ? '📎 Pièce jointe' : '';
    return mb_substr($b, 0, 120);
}

function msg_notify($conn, $did, $type, $titre, $corps, $convId) {
    if ($did === '' || $did === null) return;
    try {
        if ($type === 'message') {
            $c = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE discord_id = ? AND ref_type = 'msg_conv' AND ref_id = ? AND type = 'message' AND lu = 0");
            $c->execute(array((string)$did, (string)$convId));
            if ((int)$c->fetchColumn() > 0) return;
        }
        $lien = '/messagerie/?c=' . (int)$convId;
        $st = $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id, urgent, lu, created_at) VALUES (?,?,?,?,?,?,?,0,0,NOW())");
        $st->execute(array((string)$did, $type, mb_substr((string)$titre, 0, 255), mb_substr((string)$corps, 0, 500), $lien, 'msg_conv', (string)$convId));
    } catch (Exception $e) {}
}

function msg_att_rows($conn, $messageIds) {
    $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
    if (!$messageIds) return array();
    $in = implode(',', array_fill(0, count($messageIds), '?'));
    $st = $conn->prepare("SELECT id, message_id, kind, stored_name, original_name, mime, taille FROM msg_attachments WHERE message_id IN ($in) AND deleted_at IS NULL ORDER BY id");
    $st->execute($messageIds);
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $mid = (int)$a['message_id'];
        if (!isset($out[$mid])) $out[$mid] = array();
        $out[$mid][] = array(
            'id' => (int)$a['id'], 'kind' => $a['kind'], 'name' => $a['original_name'],
            'mime' => $a['mime'], 'size' => (int)$a['taille'],
            'url' => msg_sign_file($a['stored_name']),
        );
    }
    return $out;
}

function msg_render_messages($conn, $convId, $rows) {
    global $myDid;
    if (!$rows) return array();
    $dids = array(); $ids = array(); $replyIds = array();
    foreach ($rows as $r) { $dids[] = $r['author_did']; $ids[] = (int)$r['id']; if ($r['reply_to']) $replyIds[] = (int)$r['reply_to']; }
    $um = msg_user_map($conn, $dids);
    $att = msg_att_rows($conn, $ids);
    $threads = array();
    if ($ids) {
        $tin = implode(',', array_fill(0, count($ids), '?'));
        $tq = $conn->prepare("SELECT c.id, c.name, c.parent_msg_id, (SELECT COUNT(*) FROM msg_messages m WHERE m.conv_id = c.id AND m.deleted = 0) AS cnt, c.last_at FROM msg_conv c WHERE c.type = 'thread' AND c.parent_msg_id IN ($tin)");
        $tq->execute($ids);
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) $threads[(int)$t['parent_msg_id']] = array('id' => (int)$t['id'], 'name' => $t['name'], 'count' => (int)$t['cnt'], 'last_at' => $t['last_at']);
    }
    $reactions = array();
    if ($ids) {
        $rin = implode(',', array_fill(0, count($ids), '?'));
        $rq = $conn->prepare("SELECT message_id, emoji, COUNT(*) AS cnt, SUM(discord_id = ?) AS mine FROM msg_reactions WHERE message_id IN ($rin) GROUP BY message_id, emoji ORDER BY cnt DESC, emoji");
        $rq->execute(array_merge(array($myDid), $ids));
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $rr) { $mid = (int)$rr['message_id']; if (!isset($reactions[$mid])) $reactions[$mid] = array(); $reactions[$mid][] = array('emoji' => $rr['emoji'], 'count' => (int)$rr['cnt'], 'me' => (int)$rr['mine'] > 0); }
    }
    $replies = array();
    if ($replyIds) {
        $in = implode(',', array_fill(0, count(array_unique($replyIds)), '?'));
        $st = $conn->prepare("SELECT id, author_did, author_name, body, deleted FROM msg_messages WHERE id IN ($in)");
        $st->execute(array_values(array_unique($replyIds)));
        $rdids = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) { $replies[(int)$rr['id']] = $rr; $rdids[] = $rr['author_did']; }
        $rum = msg_user_map($conn, $rdids);
        foreach ($replies as $k => $rr) $replies[$k]['author_name'] = isset($rum[(string)$rr['author_did']]) ? $rum[(string)$rr['author_did']]['name'] : $rr['author_name'];
    }
    $out = array();
    foreach ($rows as $r) {
        $did = (string)$r['author_did'];
        $u = isset($um[$did]) ? $um[$did] : array('name' => $r['author_name'], 'avatar' => null);
        $deleted = (int)$r['deleted'] === 1;
        $reply = null;
        if ($r['reply_to'] && isset($replies[(int)$r['reply_to']])) {
            $rr = $replies[(int)$r['reply_to']];
            $reply = array('id' => (int)$rr['id'], 'author' => $rr['author_name'], 'excerpt' => (int)$rr['deleted'] === 1 ? '(supprimé)' : msg_preview($rr['body'], false));
        }
        $out[] = array(
            'id' => (int)$r['id'],
            'author_did' => $did,
            'author' => $u['name'],
            'avatar' => $u['avatar'],
            'body' => $deleted ? null : $r['body'],
            'mentions' => $r['mentions'] ? (json_decode($r['mentions'], true) ?: array()) : array(),
            'attachments' => $deleted ? array() : (isset($att[(int)$r['id']]) ? $att[(int)$r['id']] : array()),
            'reply' => $reply,
            'created_at' => $r['created_at'],
            'edited_at' => $r['edited_at'],
            'deleted' => $deleted,
            'thread' => isset($threads[(int)$r['id']]) ? $threads[(int)$r['id']] : null,
            'reactions' => isset($reactions[(int)$r['id']]) ? $reactions[(int)$r['id']] : array(),
            'pinned' => isset($r['pinned']) && (int)$r['pinned'] === 1,
            'embed' => ($deleted || empty($r['embed'])) ? null
                     : mdt_embed_resolve($conn, json_decode($r['embed'], true), $myDid),
        );
    }
    return $out;
}

function msg_classify($mime, $ext) {
    global $MSG_IMG, $MSG_VID, $MSG_PDF, $MSG_BUR;
    if (isset($MSG_IMG[$mime])) return array('image', $MSG_IMG[$mime]);
    if (isset($MSG_VID[$mime])) return array('video', $MSG_VID[$mime]);
    if (isset($MSG_PDF[$mime])) return array('pdf', $MSG_PDF[$mime]);
    if (isset($MSG_BUR[$mime])) return array('bureau', $MSG_BUR[$mime]);
    $ext = strtolower($ext);
    if (($mime === 'application/zip' || $mime === 'application/octet-stream') && in_array($ext, array('docx', 'xlsx', 'pptx'), true)) return array('bureau', $ext);
    return null;
}

function msg_store_image($tmp, $mime) {
    $info = @getimagesize($tmp);
    if (!$info || $info[0] < 1 || $info[1] < 1) return null;
    if ($info[0] > MSG_MAX_IMG_PX || $info[1] > MSG_MAX_IMG_PX) return null;
    $rand = 'mimg_' . uniqid() . '_' . bin2hex(random_bytes(6));
    if ($mime === 'image/gif') {
        $name = $rand . '.gif';
        if (!@move_uploaded_file($tmp, MSG_UPLOAD_DIR . $name)) return null;
        @chmod(MSG_UPLOAD_DIR . $name, 0644);
        return array($name, 'image/gif', 'gif');
    }
    if (!function_exists('imagecreatefromstring')) {
        $name = $rand . '.img';
        if (!@move_uploaded_file($tmp, MSG_UPLOAD_DIR . $name)) return null;
        @chmod(MSG_UPLOAD_DIR . $name, 0644);
        return array($name, $mime, 'img');
    }
    $data = @file_get_contents($tmp);
    $img = $data ? @imagecreatefromstring($data) : false;
    if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img);
    $max = 1600;
    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $nw = max(1, (int)($w * $ratio)); $nh = max(1, (int)($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img); $img = $dst;
    }
    if (function_exists('imagewebp')) {
        $name = $rand . '.webp';
        $ok = @imagewebp($img, MSG_UPLOAD_DIR . $name, 80);
        imagedestroy($img);
        if (!$ok) return null;
        @chmod(MSG_UPLOAD_DIR . $name, 0644);
        return array($name, 'image/webp', 'webp');
    }
    $name = $rand . '.jpg';
    $ok = @imagejpeg($img, MSG_UPLOAD_DIR . $name, 85);
    imagedestroy($img);
    if (!$ok) return null;
    @chmod(MSG_UPLOAD_DIR . $name, 0644);
    return array($name, 'image/jpeg', 'jpg');
}

if ($action === 'unread_total') {
    $a = msg_actor($conn);
    $total = 0;
    foreach ($conn->query("SELECT * FROM msg_conv WHERE type='channel' AND archived=0")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!msg_can_access($conn, $a, $c)) continue;
        if (msg_muted($conn, (int)$c['id'], $myDid)) continue;
        $lr = msg_last_read($conn, (int)$c['id'], $myDid);
        $uc = $conn->prepare("SELECT COUNT(*) FROM msg_messages WHERE conv_id=? AND id>? AND author_did<>? AND deleted=0"); $uc->execute(array((int)$c['id'], $lr, $myDid));
        $total += (int)$uc->fetchColumn();
    }
    $st = $conn->prepare("SELECT c.id FROM msg_conv c JOIN msg_members m ON m.conv_id=c.id WHERE m.discord_id=? AND c.type IN('dm','group')"); $st->execute(array($myDid));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid2) {
        if (msg_muted($conn, (int)$cid2, $myDid)) continue;
        $lr = msg_last_read($conn, (int)$cid2, $myDid);
        $uc = $conn->prepare("SELECT COUNT(*) FROM msg_messages WHERE conv_id=? AND id>? AND author_did<>? AND deleted=0"); $uc->execute(array((int)$cid2, $lr, $myDid));
        $total += (int)$uc->fetchColumn();
    }
    echo json_encode(array('success' => true, 'total' => $total));
    exit;
}

if ($action === 'list_convs') {
    $a = msg_actor($conn);
    $channels = array();
    foreach ($conn->query("SELECT * FROM msg_conv WHERE type='channel' ORDER BY archived, ordre, name")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!msg_can_access($conn, $a, $c)) continue;
        $channels[] = msg_conv_summary($conn, $a, $c);
    }
    $st = $conn->prepare("SELECT c.* FROM msg_conv c JOIN msg_members m ON m.conv_id = c.id WHERE m.discord_id = ? AND c.type IN ('dm','group') ORDER BY c.last_at DESC");
    $st->execute(array($myDid));
    $dms = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $dms[] = msg_conv_summary($conn, $a, $c);
    $folders = array();
    foreach ($conn->query("SELECT id, name, ordre FROM msg_folder ORDER BY ordre, id")->fetchAll(PDO::FETCH_ASSOC) as $fo) $folders[] = array('id'=>(int)$fo['id'], 'name'=>$fo['name'], 'ordre'=>(int)$fo['ordre']);
    $pdids = array();
    foreach ($dms as $d) { if (!empty($d['peer_did'])) $pdids[] = $d['peer_did']; if (!empty($d['members'])) foreach ($d['members'] as $mm) $pdids[] = $mm['did']; }
    $presence = mdt_presence_map($conn, $pdids);
    echo json_encode(array('success' => true, 'is_admin' => $isAdmin, 'me' => array('did' => $myDid, 'name' => $a['name']), 'channels' => $channels, 'dms' => $dms, 'folders' => $folders, 'presence' => $presence), JSON_UNESCAPED_UNICODE);
    exit;
}

function msg_conv_summary($conn, $actor, $c) {
    $cid = (int)$c['id'];
    $last = $conn->query("SELECT id, author_did, author_name, body, deleted, created_at FROM msg_messages WHERE conv_id = $cid ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $hasAtt = false;
    if ($last) {
        $ha = $conn->prepare("SELECT 1 FROM msg_attachments WHERE message_id = ? AND deleted_at IS NULL LIMIT 1");
        $ha->execute(array((int)$last['id']));
        $hasAtt = (bool)$ha->fetchColumn();
    }
    $lastRead = msg_last_read($conn, $cid, $actor['did']);
    $uc = $conn->prepare("SELECT COUNT(*) FROM msg_messages WHERE conv_id = ? AND id > ? AND author_did <> ? AND deleted = 0");
    $uc->execute(array($cid, $lastRead, $actor['did']));
    $unread = (int)$uc->fetchColumn();
    $title = $c['name'];
    $peer = null;
    $members = array();
    if ($c['type'] !== 'channel') {
        $mst = $conn->prepare("SELECT discord_id FROM msg_members WHERE conv_id = ?");
        $mst->execute(array($cid));
        $mdids = $mst->fetchAll(PDO::FETCH_COLUMN);
        $um = msg_user_map($conn, $mdids);
        foreach ($mdids as $d) {
            $d = (string)$d;
            $members[] = array('did' => $d, 'name' => isset($um[$d]) ? $um[$d]['name'] : $d, 'avatar' => isset($um[$d]) ? $um[$d]['avatar'] : null);
        }
        if ($c['type'] === 'dm') {
            foreach ($members as $m) { if ($m['did'] !== $actor['did']) { $title = $m['name']; $peer = $m['did']; break; } }
        } elseif (!$title) {
            $names = array();
            foreach ($members as $m) { if ($m['did'] !== $actor['did']) $names[] = $m['name']; }
            $title = implode(', ', array_slice($names, 0, 3));
        }
    }
    return array(
        'id' => $cid,
        'type' => $c['type'],
        'title' => $title,
        'color' => $c['color'],
        'logo_url' => (isset($c['logo']) && $c['logo']) ? msg_logo_url($conn, $c['logo']) : null,
        'logo_id' => (isset($c['logo']) && $c['logo']) ? (int)$c['logo'] : null,
        'folder_id' => (isset($c['folder_id']) && $c['folder_id'] !== null) ? (int)$c['folder_id'] : null,
        'archived' => (int)$c['archived'] === 1,
        'roles' => $c['roles'] ? (json_decode($c['roles'], true) ?: array()) : array(),
        'members' => $members,
        'peer_did' => $peer,
        'unread' => $unread,
        'muted' => msg_muted($conn, $cid, $actor['did']),
        'last_at' => $c['last_at'],
        'last' => $last ? array(
            'author' => $last['author_name'],
            'author_did' => (string)$last['author_did'],
            'preview' => (int)$last['deleted'] === 1 ? '(message supprimé)' : msg_preview($last['body'], $hasAtt),
            'created_at' => $last['created_at'],
        ) : null,
    );
}

function msg_logo_url($conn, $logoId) {
    if (!$logoId) return null;
    $st = $conn->prepare("SELECT stored_name FROM msg_attachments WHERE id = ? AND deleted_at IS NULL");
    $st->execute(array((int)$logoId));
    $n = $st->fetchColumn();
    return $n ? msg_sign_file($n) : null;
}

if ($action === 'messages') {
    $a = msg_actor($conn);
    $conv = msg_load_conv($conn, isset($_GET['conv']) ? $_GET['conv'] : 0);
    if (!msg_can_access($conn, $a, $conv)) mdt_error(403, 'E-MSG-403', 'Accès refusé à cette conversation');
    $cid = (int)$conv['id'];
    $limit = max(1, min(100, (int)(isset($_GET['limit']) ? $_GET['limit'] : 40)));
    $after = (int)(isset($_GET['after']) ? $_GET['after'] : 0);
    $before = (int)(isset($_GET['before']) ? $_GET['before'] : 0);
    if ($after > 0) {
        $st = $conn->prepare("SELECT * FROM msg_messages WHERE conv_id = ? AND id > ? ORDER BY id ASC LIMIT $limit");
        $st->execute(array($cid, $after));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($before > 0) {
        $st = $conn->prepare("SELECT * FROM msg_messages WHERE conv_id = ? AND id < ? ORDER BY id DESC LIMIT $limit");
        $st->execute(array($cid, $before));
        $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    } else {
        $st = $conn->prepare("SELECT * FROM msg_messages WHERE conv_id = ? ORDER BY id DESC LIMIT $limit");
        $st->execute(array($cid));
        $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    }
    $more = false;
    if ($before === 0 && $after === 0 && $rows) {
        $mc = $conn->prepare("SELECT COUNT(*) FROM msg_messages WHERE conv_id = ? AND id < ?");
        $mc->execute(array($cid, (int)$rows[0]['id']));
        $more = (int)$mc->fetchColumn() > 0;
    }
    $typing = array();
    try {
        $tps = $conn->prepare("SELECT t.discord_id, COALESCE(NULLIF(u.discord_nick,''), NULLIF(u.discord_username,''), u.username, t.discord_id) AS nom FROM msg_typing t LEFT JOIN users u ON u.discord_id = t.discord_id WHERE t.conv_id = ? AND t.discord_id <> ? AND t.updated_at > (NOW() - INTERVAL 6 SECOND)");
        $tps->execute(array($cid, $myDid));
        foreach ($tps->fetchAll(PDO::FETCH_ASSOC) as $tr) $typing[] = array('did' => (string)$tr['discord_id'], 'name' => $tr['nom']);
    } catch (Exception $e) {}
    $reads = array();
    if ($conv['type'] === 'dm' || $conv['type'] === 'group') {
        $rs = $conn->prepare("SELECT m.discord_id, m.last_read_id, COALESCE(NULLIF(u.discord_nick,''), NULLIF(u.discord_username,''), u.username, m.discord_id) AS nom, u.discord_avatar FROM msg_members m LEFT JOIN users u ON u.discord_id = m.discord_id WHERE m.conv_id = ? AND m.discord_id <> ?");
        $rs->execute(array($cid, $myDid));
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $rr) $reads[] = array('did' => (string)$rr['discord_id'], 'name' => $rr['nom'], 'avatar' => msg_avatar($rr['discord_avatar']), 'last_read' => (int)$rr['last_read_id']);
    }
    echo json_encode(array('success' => true, 'messages' => msg_render_messages($conn, $cid, $rows), 'has_more' => $more, 'my_did' => $myDid, 'is_admin' => $isAdmin, 'conv' => array('id' => $cid, 'type' => $conv['type']), 'typing' => $typing, 'last_read' => msg_last_read($conn, $cid, $myDid), 'reads' => $reads), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'mark_read') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $a = msg_actor($conn);
    $conv = msg_load_conv($conn, isset($d['conv']) ? $d['conv'] : 0);
    if (!msg_can_access($conn, $a, $conv)) mdt_error(403, 'E-MSG-403', 'Accès refusé');
    $cid = (int)$conv['id'];
    $upto = (int)(isset($d['upto']) ? $d['upto'] : 0);
    if ($upto <= 0) { $upto = (int)$conn->query("SELECT COALESCE(MAX(id),0) FROM msg_messages WHERE conv_id = $cid")->fetchColumn(); }
    msg_ensure_member($conn, $cid, $myDid);
    $st = $conn->prepare("UPDATE msg_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conv_id = ? AND discord_id = ?");
    $st->execute(array($upto, $cid, $myDid));
    $conn->prepare("UPDATE notifications SET lu = 1 WHERE discord_id = ? AND ref_type = 'msg_conv' AND ref_id = ? AND lu = 0")->execute(array($myDid, (string)$cid));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'pin') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $a = msg_actor($conn);
    $mid = (int)(isset($d['message']) ? $d['message'] : 0);
    $pin = !empty($d['pin']) ? 1 : 0;
    $mr = $conn->prepare("SELECT conv_id FROM msg_messages WHERE id = ? AND deleted = 0"); $mr->execute(array($mid));
    $cvid = $mr->fetchColumn();
    if (!$cvid) mdt_error(404, 'E-MSG-404', 'Message introuvable');
    if (!msg_can_access($conn, $a, msg_load_conv($conn, (int)$cvid))) mdt_error(403, 'E-MSG-403', 'Acc\u00e8s refus\u00e9');
    $conn->prepare("UPDATE msg_messages SET pinned = ? WHERE id = ?")->execute(array($pin, $mid));
    echo json_encode(array('success' => true, 'pinned' => $pin === 1));
    exit;
}

if ($action === 'pins') {
    $a = msg_actor($conn);
    $conv = msg_load_conv($conn, isset($_GET['conv']) ? $_GET['conv'] : 0);
    if (!msg_can_access($conn, $a, $conv)) mdt_error(403, 'E-MSG-403', 'Acc\u00e8s refus\u00e9');
    $st = $conn->prepare("SELECT * FROM msg_messages WHERE conv_id = ? AND pinned = 1 AND deleted = 0 ORDER BY id DESC LIMIT 50");
    $st->execute(array((int)$conv['id']));
    echo json_encode(array('success' => true, 'messages' => msg_render_messages($conn, (int)$conv['id'], $st->fetchAll(PDO::FETCH_ASSOC))), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search') {
    $a = msg_actor($conn);
    $conv = msg_load_conv($conn, isset($_GET['conv']) ? $_GET['conv'] : 0);
    if (!msg_can_access($conn, $a, $conv)) mdt_error(403, 'E-MSG-403', 'Acc\u00e8s refus\u00e9');
    $q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
    if (mb_strlen($q) < 2) { echo json_encode(array('success' => true, 'results' => array())); exit; }
    $st = $conn->prepare("SELECT id, author_did, author_name, body, created_at FROM msg_messages WHERE conv_id = ? AND deleted = 0 AND body LIKE ? ORDER BY id DESC LIMIT 40");
    $st->execute(array((int)$conv['id'], '%' . $q . '%'));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $dids = array(); foreach ($rows as $r) $dids[] = $r['author_did'];
    $um = msg_user_map($conn, $dids);
    $out = array();
    foreach ($rows as $r) { $did = (string)$r['author_did']; $out[] = array('id' => (int)$r['id'], 'author' => isset($um[$did]) ? $um[$did]['name'] : $r['author_name'], 'snippet' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)$r['body'])), 0, 120), 'created_at' => $r['created_at']); }
    echo json_encode(array('success' => true, 'results' => $out), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'send') {
    mdt_post_only();
    $a = msg_actor($conn);
    if (!empty($_FILES)) {
        $conv = msg_load_conv($conn, isset($_POST['conv']) ? $_POST['conv'] : 0);
        $body = isset($_POST['body']) ? (string)$_POST['body'] : '';
        $reply = (int)(isset($_POST['reply_to']) ? $_POST['reply_to'] : 0);
        $mentions = isset($_POST['mentions']) ? (json_decode((string)$_POST['mentions'], true) ?: array()) : array();
        $attachIds = isset($_POST['attach_ids']) ? (json_decode((string)$_POST['attach_ids'], true) ?: array()) : array();
    } else {
        $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
        $conv = msg_load_conv($conn, isset($d['conv']) ? $d['conv'] : 0);
        $body = isset($d['body']) ? (string)$d['body'] : '';
        $reply = (int)(isset($d['reply_to']) ? $d['reply_to'] : 0);
        $mentions = isset($d['mentions']) && is_array($d['mentions']) ? $d['mentions'] : array();
        $attachIds = isset($d['attach_ids']) && is_array($d['attach_ids']) ? $d['attach_ids'] : array();
    }
    if (!msg_can_access($conn, $a, $conv)) mdt_error(403, 'E-MSG-403', 'Accès refusé à cette conversation');
    if ((int)$conv['archived'] === 1) mdt_error(403, 'E-MSG-403', 'Conversation archivée');
    $cid = (int)$conv['id'];
    $body = trim(mb_substr($body, 0, MSG_BODY_MAX));
    $attachIds = array_values(array_unique(array_map('intval', $attachIds)));
    $validAtt = array();
    if ($attachIds) {
        $in = implode(',', array_fill(0, count($attachIds), '?'));
        $st = $conn->prepare("SELECT id FROM msg_attachments WHERE id IN ($in) AND message_id IS NULL AND uploaded_by = ? AND deleted_at IS NULL");
        $st->execute(array_merge($attachIds, array($myDid)));
        $validAtt = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($body === '' && !$validAtt) mdt_error(400, 'E-MSG-400', 'Message vide');
    if ($reply > 0) {
        $rc = $conn->prepare("SELECT 1 FROM msg_messages WHERE id = ? AND conv_id = ? LIMIT 1");
        $rc->execute(array($reply, $cid));
        if (!$rc->fetchColumn()) $reply = 0;
    }
    $mdids = array();
    foreach ($mentions as $m) { $m = (string)$m; if ($m !== '' && preg_match('/^\d{5,32}$/', $m)) $mdids[] = $m; }
    $mdids = array_values(array_unique($mdids));
    $mj = $mdids ? json_encode($mdids) : null;
    $embedRef = null;
    if (isset($d) && is_array($d) && isset($d['embed'])) $embedRef = mdt_embed_clean($d['embed']);
    elseif (isset($_POST['embed'])) $embedRef = mdt_embed_clean(json_decode((string)$_POST['embed'], true));
    if ($embedRef && !mdt_embed_resolve($conn, $embedRef, $myDid)) {
        mdt_error(403, 'E-MSG-403', 'Tu ne peux pas partager cette ressource');
    }
    if ($body === '' && !$validAtt && !$embedRef) mdt_error(400, 'E-MSG-400', 'Message vide');

    $st = $conn->prepare("INSERT INTO msg_messages (conv_id, author_did, author_name, body, mentions, reply_to, embed, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $st->execute(array($cid, $myDid, $a['name'], $body !== '' ? $body : null, $mj, $reply > 0 ? $reply : null,
                       $embedRef ? json_encode($embedRef, JSON_UNESCAPED_UNICODE) : null));
    $mid = (int)$conn->lastInsertId();
    if ($validAtt) {
        $in = implode(',', array_fill(0, count($validAtt), '?'));
        $conn->prepare("UPDATE msg_attachments SET message_id = ?, conv_id = ? WHERE id IN ($in)")->execute(array_merge(array($mid, $cid), $validAtt));
    }
    $conn->prepare("UPDATE msg_conv SET last_at = NOW() WHERE id = ?")->execute(array($cid));
    msg_ensure_member($conn, $cid, $myDid);
    $conn->prepare("UPDATE msg_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conv_id = ? AND discord_id = ?")->execute(array($mid, $cid, $myDid));
    $prev = msg_preview($body, !empty($validAtt));
    if ($mdids) {
        foreach ($mdids as $md) {
            if ($md === $myDid) continue;
            if (msg_muted($conn, $cid, $md)) continue;
            $ctmp = array('id' => $cid, 'type' => $conv['type'], 'archived' => 0, 'roles' => $conv['roles']);
            if (!msg_can_access($conn, array('did' => $md, 'roles' => msg_roles_of($conn, $md), 'admin' => false), $ctmp)) continue;
            msg_notify($conn, $md, 'mention', $a['name'] . ' vous a mentionné', $prev, $cid);
        }
    }
    if ($conv['type'] !== 'channel') {
        $mm = $conn->prepare("SELECT discord_id FROM msg_members WHERE conv_id = ? AND discord_id <> ?");
        $mm->execute(array($cid, $myDid));
        foreach ($mm->fetchAll(PDO::FETCH_COLUMN) as $rd) {
            $rd = (string)$rd;
            if (in_array($rd, $mdids, true)) continue;
            if (msg_muted($conn, $cid, $rd)) continue;
            $ttl = $conv['type'] === 'dm' ? $a['name'] : ($a['name'] . ' — ' . (isset($conv['name']) && $conv['name'] ? $conv['name'] : 'Groupe'));
            msg_notify($conn, $rd, 'message', $ttl, $prev, $cid);
        }
    }
    $row = $conn->query("SELECT * FROM msg_messages WHERE id = $mid")->fetch(PDO::FETCH_ASSOC);
    $rendered = msg_render_messages($conn, $cid, array($row));
    echo json_encode(array('success' => true, 'message' => $rendered ? $rendered[0] : null), JSON_UNESCAPED_UNICODE);
    exit;
}

function msg_roles_of($conn, $did) {
    $st = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = ? LIMIT 1");
    $st->execute(array((string)$did));
    $v = $st->fetchColumn();
    $r = $v ? json_decode($v, true) : array();
    return is_array($r) ? $r : array();
}

if ($action === 'upload') {
    mdt_post_only();
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) mdt_error(400, 'E-MSG-420', 'Aucun fichier');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) mdt_error(400, 'E-MSG-421', 'Erreur upload');
    if (@disk_free_space(__DIR__) !== false && @disk_free_space(__DIR__) < MSG_MIN_DISK_FREE + (int)$f['size']) mdt_error(507, 'E-MSG-507', 'Espace disque insuffisant');
    $q = $conn->prepare("SELECT COUNT(*) FROM msg_attachments WHERE uploaded_by = ? AND message_id IS NULL AND deleted_at IS NULL");
    $q->execute(array($myDid));
    if ((int)$q->fetchColumn() >= MSG_QUOTA_FILES) mdt_error(429, 'E-MSG-429', 'Trop de fichiers en attente');
    if (!is_dir(MSG_UPLOAD_DIR)) @mkdir(MSG_UPLOAD_DIR, 0775, true);
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime = $finfo ? finfo_file($finfo, $f['tmp_name']) : (isset($f['type']) ? $f['type'] : '');
    if ($finfo) finfo_close($finfo);
    if (in_array($mime, array('image/svg+xml', 'text/html', 'text/xml', 'application/xml'), true)) mdt_error(400, 'E-MSG-423', 'Type de fichier interdit');
    $origExt = strtolower(pathinfo(isset($f['name']) ? $f['name'] : '', PATHINFO_EXTENSION));
    $cls = msg_classify($mime, $origExt);
    if (!$cls) mdt_error(400, 'E-MSG-424', 'Format non accepté');
    list($kind, $ext) = $cls;
    $limits = array('image' => MSG_MAX_IMG, 'video' => MSG_MAX_VID, 'pdf' => MSG_MAX_PDF, 'bureau' => MSG_MAX_BUR);
    if ((int)$f['size'] > $limits[$kind]) mdt_error(400, 'E-MSG-422', 'Fichier trop volumineux');
    if ($kind === 'bureau' && in_array($ext, array('docx', 'xlsx', 'pptx'), true)) {
        $zip = new ZipArchive();
        if ($zip->open($f['tmp_name']) !== true) mdt_error(400, 'E-MSG-425', 'Fichier Office invalide');
        $ct = $zip->locateName('[Content_Types].xml') !== false;
        $pref = array('docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/');
        $hasPref = false;
        for ($i = 0; $i < $zip->numFiles; $i++) { if (strpos($zip->getNameIndex($i), $pref[$ext]) === 0) { $hasPref = true; break; } }
        $zip->close();
        if (!$ct || !$hasPref) mdt_error(400, 'E-MSG-425', 'Fichier Office invalide');
    } elseif ($kind === 'bureau') {
        $fh = @fopen($f['tmp_name'], 'rb'); $magic = $fh ? fread($fh, 8) : ''; if ($fh) fclose($fh);
        if ($magic !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") mdt_error(400, 'E-MSG-425', 'Fichier Office invalide');
    }
    if ($kind === 'image') {
        $stored = msg_store_image($f['tmp_name'], $mime);
        if (!$stored) mdt_error(400, 'E-MSG-426', 'Image invalide');
        list($name, $mime, $ext) = $stored;
    } else {
        $prefix = array('video' => 'mvid_', 'pdf' => 'mpdf_', 'bureau' => 'mbur_');
        $name = $prefix[$kind] . uniqid() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], MSG_UPLOAD_DIR . $name)) mdt_error(500, 'E-MSG-500', 'Échec stockage');
        @chmod(MSG_UPLOAD_DIR . $name, 0644);
    }
    $orig = mb_substr(preg_replace('/[\/\\\\\x00-\x1F]+/', '', (string)(isset($f['name']) ? $f['name'] : 'fichier')), 0, 160);
    $size = @filesize(MSG_UPLOAD_DIR . $name) ?: (int)$f['size'];
    $st = $conn->prepare("INSERT INTO msg_attachments (message_id, kind, stored_name, original_name, mime, ext, taille, uploaded_by, created_at) VALUES (NULL,?,?,?,?,?,?,?,NOW())");
    $st->execute(array($kind, $name, $orig, $mime, $ext, $size, $myDid));
    $id = (int)$conn->lastInsertId();
    echo json_encode(array('success' => true, 'attachment' => array('id' => $id, 'kind' => $kind, 'name' => $orig, 'size' => (int)$size, 'is_image' => $kind === 'image', 'url' => msg_sign_file($name))), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create_dm') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $dids = isset($d['dids']) && is_array($d['dids']) ? $d['dids'] : array();
    $clean = array();
    foreach ($dids as $x) { $x = (string)$x; if (preg_match('/^\d{5,32}$/', $x) && $x !== $myDid) $clean[] = $x; }
    $clean = array_values(array_unique($clean));
    if (!$clean) mdt_error(400, 'E-MSG-400', 'Aucun destinataire');
    $in = implode(',', array_fill(0, count($clean), '?'));
    $chk = $conn->prepare("SELECT discord_id FROM users WHERE discord_id IN ($in)");
    $chk->execute($clean);
    $exist = array_map('strval', $chk->fetchAll(PDO::FETCH_COLUMN));
    $clean = array_values(array_intersect($clean, $exist));
    if (!$clean) mdt_error(400, 'E-MSG-404', 'Destinataire introuvable');
    $all = array_values(array_unique(array_merge(array($myDid), $clean)));
    sort($all);
    $type = count($all) === 2 ? 'dm' : 'group';
    if ($type === 'dm') {
        $q = $conn->prepare("SELECT c.id FROM msg_conv c
            JOIN msg_members m1 ON m1.conv_id = c.id AND m1.discord_id = ?
            JOIN msg_members m2 ON m2.conv_id = c.id AND m2.discord_id = ?
            WHERE c.type = 'dm' AND (SELECT COUNT(*) FROM msg_members mm WHERE mm.conv_id = c.id) = 2 LIMIT 1");
        $q->execute(array($all[0], $all[1]));
        $ex = $q->fetchColumn();
        if ($ex) { echo json_encode(array('success' => true, 'conv_id' => (int)$ex, 'existing' => true)); exit; }
    }
    $conn->prepare("INSERT INTO msg_conv (type, name, created_by, created_at, last_at) VALUES (?,NULL,?,NOW(),NOW())")->execute(array($type, $myDid));
    $cid = (int)$conn->lastInsertId();
    foreach ($all as $d2) msg_ensure_member($conn, $cid, $d2, $d2 === $myDid ? 'owner' : 'member');
    echo json_encode(array('success' => true, 'conv_id' => $cid, 'existing' => false));
    exit;
}

if ($action === 'mute') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $a = msg_actor($conn);
    $conv = msg_load_conv($conn, isset($d['conv']) ? $d['conv'] : 0);
    if (!msg_can_access($conn, $a, $conv)) mdt_error(403, 'E-MSG-403', 'Accès refusé');
    msg_ensure_member($conn, (int)$conv['id'], $myDid);
    $conn->prepare("UPDATE msg_members SET muted = ? WHERE conv_id = ? AND discord_id = ?")->execute(array(!empty($d['muted']) ? 1 : 0, (int)$conv['id'], $myDid));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'edit') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    $body = trim(mb_substr((string)(isset($d['body']) ? $d['body'] : ''), 0, MSG_BODY_MAX));
    if ($body === '') mdt_error(400, 'E-MSG-400', 'Message vide');
    $m = $conn->query("SELECT * FROM msg_messages WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
    if (!$m || (int)$m['deleted'] === 1) mdt_error(404, 'E-MSG-404', 'Message introuvable');
    if ((string)$m['author_did'] !== $myDid && !$isAdmin) mdt_error(403, 'E-MSG-403', 'Non autorisé');
    $conn->prepare("UPDATE msg_messages SET body = ?, edited_at = NOW() WHERE id = ?")->execute(array($body, $id));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'delete_msg') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    $m = $conn->query("SELECT * FROM msg_messages WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
    if (!$m) mdt_error(404, 'E-MSG-404', 'Message introuvable');
    if ((string)$m['author_did'] !== $myDid && !$isAdmin) mdt_error(403, 'E-MSG-403', 'Non autorisé');
    $conn->prepare("UPDATE msg_messages SET deleted = 1, body = NULL, mentions = NULL WHERE id = ?")->execute(array($id));
    $conn->prepare("UPDATE msg_attachments SET deleted_at = NOW() WHERE message_id = ? AND deleted_at IS NULL")->execute(array($id));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'search_people') {
    $q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
    if (mb_strlen($q) < 1) { echo json_encode(array('success' => true, 'people' => array())); exit; }
    $like = '%' . $q . '%';
    $st = $conn->prepare("SELECT discord_id, discord_avatar, COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username, discord_id) AS nom
        FROM users WHERE discord_id IS NOT NULL AND discord_id <> '' AND discord_id <> ?
        AND (discord_nick LIKE ? OR discord_username LIKE ? OR username LIKE ? OR discord_id = ?) LIMIT 15");
    $st->execute(array($myDid, $like, $like, $like, $q));
    $people = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $people[] = array('did' => (string)$r['discord_id'], 'name' => $r['nom'], 'avatar' => msg_avatar($r['discord_avatar']));
    }
    echo json_encode(array('success' => true, 'people' => $people), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'channel_roles') {
    $rows = array();
    try {
        foreach ($conn->query("SELECT role_id, name, color FROM discord_roles_cache ORDER BY position DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = array('id' => (string)$r['role_id'], 'name' => $r['name'], 'color' => $r['color']);
        }
    } catch (Exception $e) {}
    echo json_encode(array('success' => true, 'roles' => $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'react') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $a = msg_actor($conn);
    $mid = (int)(isset($d['message']) ? $d['message'] : 0);
    $emoji = mb_substr(trim((string)(isset($d['emoji']) ? $d['emoji'] : '')), 0, 24);
    if ($mid < 1 || $emoji === '') mdt_error(400, 'E-MSG-400', 'Param\u00e8tres invalides');
    $mr = $conn->prepare("SELECT conv_id FROM msg_messages WHERE id = ? AND deleted = 0"); $mr->execute(array($mid));
    $cvid = $mr->fetchColumn();
    if (!$cvid) mdt_error(404, 'E-MSG-404', 'Message introuvable');
    if (!msg_can_access($conn, $a, msg_load_conv($conn, (int)$cvid))) mdt_error(403, 'E-MSG-403', 'Acc\u00e8s refus\u00e9');
    $ex = $conn->prepare("SELECT 1 FROM msg_reactions WHERE message_id = ? AND discord_id = ? AND emoji = ?"); $ex->execute(array($mid, $myDid, $emoji));
    if ($ex->fetchColumn()) {
        $conn->prepare("DELETE FROM msg_reactions WHERE message_id = ? AND discord_id = ? AND emoji = ?")->execute(array($mid, $myDid, $emoji));
    } else {

        $mineQ = $conn->prepare("SELECT COUNT(*) FROM msg_reactions WHERE message_id = ? AND discord_id = ?");
        $mineQ->execute(array($mid, $myDid));
        if ((int)$mineQ->fetchColumn() >= 5) mdt_error(409, 'E-MSG-429', 'Maximum 5 réactions par message et par membre');
        $emoQ = $conn->prepare("SELECT COUNT(DISTINCT emoji) FROM msg_reactions WHERE message_id = ?");
        $emoQ->execute(array($mid));
        $newEmoji = $conn->prepare("SELECT 1 FROM msg_reactions WHERE message_id = ? AND emoji = ? LIMIT 1");
        $newEmoji->execute(array($mid, $emoji));
        if (!$newEmoji->fetchColumn() && (int)$emoQ->fetchColumn() >= 20) mdt_error(409, 'E-MSG-429', 'Maximum 20 emojis différents par message');
        $conn->prepare("INSERT IGNORE INTO msg_reactions (message_id, discord_id, emoji, created_at) VALUES (?,?,?,NOW())")->execute(array($mid, $myDid, $emoji));
    }
    $rq = $conn->prepare("SELECT emoji, COUNT(*) AS cnt, SUM(discord_id = ?) AS mine FROM msg_reactions WHERE message_id = ? GROUP BY emoji ORDER BY cnt DESC, emoji");
    $rq->execute(array($myDid, $mid));
    $out = array();
    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $rr) $out[] = array('emoji' => $rr['emoji'], 'count' => (int)$rr['cnt'], 'me' => (int)$rr['mine'] > 0);
    echo json_encode(array('success' => true, 'reactions' => $out), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'thread_create') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $a = msg_actor($conn);
    $pc = (int)(isset($d['parent_conv']) ? $d['parent_conv'] : 0);
    $pm = (int)(isset($d['parent_msg']) ? $d['parent_msg'] : 0);
    $parent = msg_load_conv($conn, $pc);
    if (!$parent || $parent['type'] !== 'channel') mdt_error(404, 'E-MSG-404', 'Canal introuvable');
    if (!msg_can_access($conn, $a, $parent)) mdt_error(403, 'E-MSG-403', 'Acces refuse');
    $mc = $conn->prepare("SELECT 1 FROM msg_messages WHERE id = ? AND conv_id = ?"); $mc->execute(array($pm, $pc));
    if (!$mc->fetchColumn()) mdt_error(404, 'E-MSG-404', 'Message introuvable');
    $ex = $conn->prepare("SELECT id FROM msg_conv WHERE type='thread' AND parent_msg_id = ? LIMIT 1"); $ex->execute(array($pm));
    $exId = $ex->fetchColumn();
    if ($exId) { echo json_encode(array('success' => true, 'conv_id' => (int)$exId, 'existing' => true)); exit; }
    $name = trim(mb_substr((string)(isset($d['name']) ? $d['name'] : ''), 0, 120));
    if ($name === '') $name = 'Fil';
    $conn->prepare("INSERT INTO msg_conv (type, name, parent_conv_id, parent_msg_id, created_by, created_at, last_at) VALUES ('thread',?,?,?,?,NOW(),NOW())")->execute(array($name, $pc, $pm, $myDid));
    $tid = (int)$conn->lastInsertId();
    echo json_encode(array('success' => true, 'conv_id' => $tid, 'existing' => false, 'name' => $name));
    exit;
}

if ($action === 'group_add_member') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $conv = msg_load_conv($conn, isset($d['conv']) ? $d['conv'] : 0);
    if (!$conv || $conv['type'] !== 'group') mdt_error(404, 'E-MSG-404', 'Groupe introuvable');
    if (!msg_is_member($conn, (int)$conv['id'], $myDid)) mdt_error(403, 'E-MSG-403', 'Non membre');
    $dids = isset($d['dids']) && is_array($d['dids']) ? $d['dids'] : array();
    $clean = array();
    foreach ($dids as $x) { $x = (string)$x; if (preg_match('/^\d{5,32}$/', $x)) $clean[] = $x; }
    if (!$clean) mdt_error(400, 'E-MSG-400', 'Aucun destinataire');
    $in = implode(',', array_fill(0, count($clean), '?'));
    $chk = $conn->prepare("SELECT discord_id FROM users WHERE discord_id IN ($in)"); $chk->execute($clean);
    $exist = array_map('strval', $chk->fetchAll(PDO::FETCH_COLUMN));
    $add = array_values(array_intersect($clean, $exist));
    if (!$add) mdt_error(400, 'E-MSG-404', 'Destinataire introuvable');
    foreach ($add as $dd) msg_ensure_member($conn, (int)$conv['id'], $dd);
    echo json_encode(array('success' => true, 'conv_id' => (int)$conv['id']));
    exit;
}

if ($action === 'typing') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $a = msg_actor($conn);
    $conv = msg_load_conv($conn, isset($d['conv']) ? $d['conv'] : 0);
    if (!msg_can_access($conn, $a, $conv)) { echo json_encode(array('success' => false)); exit; }
    try { $conn->prepare("INSERT INTO msg_typing (conv_id, discord_id, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()")->execute(array((int)$conv['id'], $myDid)); } catch (Exception $e) {}
    echo json_encode(array('success' => true));
    exit;
}

if (!$isAdmin) mdt_error(403, 'E-MSG-403', 'Réservé à l\'administration');

if ($action === 'create_channel' || $action === 'update_channel') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $name = trim(mb_substr((string)(isset($d['name']) ? $d['name'] : ''), 0, 120));
    if ($name === '') mdt_error(400, 'E-MSG-400', 'Nom requis');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)(isset($d['color']) ? $d['color'] : '')) ? $d['color'] : '#6366f1';
    $roles = array();
    if (isset($d['roles']) && is_array($d['roles'])) foreach ($d['roles'] as $r) { $r = (string)$r; if (preg_match('/^\d{5,32}$/', $r)) $roles[] = $r; }
    $roles = array_values(array_unique($roles));
    $rj = $roles ? json_encode($roles) : null;
    $logo = (isset($d['logo']) && $d['logo'] !== '' && $d['logo'] !== null && (int)$d['logo'] > 0) ? (int)$d['logo'] : null;
    $folderId = (isset($d['folder_id']) && $d['folder_id'] !== '' && $d['folder_id'] !== null && (int)$d['folder_id'] > 0) ? (int)$d['folder_id'] : null;
    if ($logo) { $lk = $conn->prepare("SELECT kind FROM msg_attachments WHERE id = ? AND deleted_at IS NULL"); $lk->execute(array($logo)); if ($lk->fetchColumn() !== 'image') $logo = null; }
    if ($action === 'create_channel') {
        $conn->prepare("INSERT INTO msg_conv (type, name, roles, color, logo, folder_id, created_by, created_at, last_at) VALUES ('channel',?,?,?,?,?,?,NOW(),NOW())")->execute(array($name, $rj, $color, $logo, $folderId, $myDid));
        $cid = (int)$conn->lastInsertId();
        if ($logo) $conn->prepare("UPDATE msg_attachments SET conv_id = ? WHERE id = ?")->execute(array($cid, $logo));
        if (function_exists('mdt_audit')) mdt_audit($conn, 'create_channel', array('entity_type' => 'msg_channel', 'entity_id' => (string)$cid, 'summary' => 'Canal « ' . $name . ' »', 'before' => null, 'after' => array('name' => $name, 'roles' => $roles, 'color' => $color)));
        echo json_encode(array('success' => true, 'conv_id' => $cid));
    } else {
        $id = (int)(isset($d['id']) ? $d['id'] : 0);
        $before = $conn->query("SELECT name, roles, color, logo, folder_id FROM msg_conv WHERE id = " . $id . " AND type='channel'")->fetch(PDO::FETCH_ASSOC);
        if (!$before) mdt_error(404, 'E-MSG-404', 'Canal introuvable');
        $conn->prepare("UPDATE msg_conv SET name = ?, roles = ?, color = ?, logo = ?, folder_id = ? WHERE id = ? AND type='channel'")->execute(array($name, $rj, $color, $logo, $folderId, $id));
        if ($logo) $conn->prepare("UPDATE msg_attachments SET conv_id = ? WHERE id = ?")->execute(array($id, $logo));
        if (function_exists('mdt_audit')) mdt_audit($conn, 'update_channel', array('entity_type' => 'msg_channel', 'entity_id' => (string)$id, 'summary' => 'Canal « ' . $name . ' »', 'before' => array('name' => $before['name'], 'roles' => $before['roles'] ? json_decode($before['roles'], true) : array(), 'color' => $before['color']), 'after' => array('name' => $name, 'roles' => $roles, 'color' => $color)));
        echo json_encode(array('success' => true, 'conv_id' => $id));
    }
    exit;
}

if ($action === 'archive_channel') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    $arch = !empty($d['archived']) ? 1 : 0;
    $before = $conn->query("SELECT name, archived FROM msg_conv WHERE id = " . $id . " AND type='channel'")->fetch(PDO::FETCH_ASSOC);
    if (!$before) mdt_error(404, 'E-MSG-404', 'Canal introuvable');
    $conn->prepare("UPDATE msg_conv SET archived = ? WHERE id = ? AND type='channel'")->execute(array($arch, $id));
    if (function_exists('mdt_audit')) mdt_audit($conn, 'archive_channel', array('entity_type' => 'msg_channel', 'entity_id' => (string)$id, 'summary' => ($arch ? 'Archivé' : 'Réactivé') . ' « ' . $before['name'] . ' »', 'before' => array('archived' => (int)$before['archived']), 'after' => array('archived' => $arch)));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'folder_save') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $name = trim(mb_substr((string)(isset($d['name']) ? $d['name'] : ''), 0, 80));
    if ($name === '') mdt_error(400, 'E-MSG-400', 'Nom requis');
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    if ($id > 0) { $conn->prepare("UPDATE msg_folder SET name = ? WHERE id = ?")->execute(array($name, $id)); }
    else { $ord = (int)$conn->query("SELECT COALESCE(MAX(ordre),0)+1 FROM msg_folder")->fetchColumn(); $conn->prepare("INSERT INTO msg_folder (name, ordre, created_at) VALUES (?,?,NOW())")->execute(array($name, $ord)); $id = (int)$conn->lastInsertId(); }
    echo json_encode(array('success' => true, 'id' => $id));
    exit;
}

if ($action === 'folder_delete') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    if ($id < 1) mdt_error(400, 'E-MSG-400', 'id requis');
    $conn->prepare("UPDATE msg_conv SET folder_id = NULL WHERE folder_id = ?")->execute(array($id));
    $conn->prepare("DELETE FROM msg_folder WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'reorder') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    if (isset($d['folders']) && is_array($d['folders'])) { $i = 0; foreach ($d['folders'] as $fid) { $conn->prepare("UPDATE msg_folder SET ordre = ? WHERE id = ?")->execute(array($i, (int)$fid)); $i++; } }
    if (isset($d['channels']) && is_array($d['channels'])) { $i = 0; foreach ($d['channels'] as $ch) { $cid2 = (int)(isset($ch['id']) ? $ch['id'] : 0); $fid = (isset($ch['folder_id']) && $ch['folder_id'] !== null && $ch['folder_id'] !== '' && (int)$ch['folder_id'] > 0) ? (int)$ch['folder_id'] : null; $conn->prepare("UPDATE msg_conv SET folder_id = ?, ordre = ? WHERE id = ? AND type='channel'")->execute(array($fid, $i, $cid2)); $i++; } }
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'channel_members') {
    $id = (int)(isset($_GET['conv']) ? $_GET['conv'] : 0);
    $st = $conn->prepare("SELECT discord_id FROM msg_members WHERE conv_id = ? AND kind = 'invited'");
    $st->execute(array($id));
    $dids = $st->fetchAll(PDO::FETCH_COLUMN);
    $um = msg_user_map($conn, $dids);
    $out = array();
    foreach ($dids as $dd) { $dd = (string)$dd; $out[] = array('did' => $dd, 'name' => isset($um[$dd]) ? $um[$dd]['name'] : $dd, 'avatar' => isset($um[$dd]) ? $um[$dd]['avatar'] : null); }
    echo json_encode(array('success' => true, 'members' => $out, 'presence' => mdt_presence_map($conn, $dids)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'channel_add_member') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['conv']) ? $d['conv'] : 0);
    $did2 = (string)(isset($d['did']) ? $d['did'] : '');
    if (!preg_match('/^\d{5,32}$/', $did2)) mdt_error(400, 'E-MSG-400', 'Membre invalide');
    $ck = $conn->prepare("SELECT 1 FROM msg_conv WHERE id = ? AND type='channel'"); $ck->execute(array($id)); if (!$ck->fetchColumn()) mdt_error(404, 'E-MSG-404', 'Canal introuvable');
    $ue = $conn->prepare("SELECT 1 FROM users WHERE discord_id = ?"); $ue->execute(array($did2)); if (!$ue->fetchColumn()) mdt_error(404, 'E-MSG-404', 'Utilisateur introuvable');
    $conn->prepare("INSERT INTO msg_members (conv_id, discord_id, kind, last_read_id, muted, joined_at) VALUES (?,?,'invited',0,0,NOW()) ON DUPLICATE KEY UPDATE kind = 'invited'")->execute(array($id, $did2));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'channel_remove_member') {
    mdt_post_only();
    $d = mdt_get_post_data('E-MSG-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['conv']) ? $d['conv'] : 0);
    $did2 = (string)(isset($d['did']) ? $d['did'] : '');
    $conn->prepare("DELETE FROM msg_members WHERE conv_id = ? AND discord_id = ? AND kind = 'invited'")->execute(array($id, $did2));
    echo json_encode(array('success' => true));
    exit;
}

mdt_error(400, 'E-MSG-400', 'Action inconnue');
