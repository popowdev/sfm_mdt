<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('CID_UPLOAD_DIR', __DIR__ . '/uploads/');
define('CID_UPLOAD_URL', '/cid/uploads/');
define('CID_MAX_SIZE', 10 * 1024 * 1024);
$CID_ALLOWED = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp');
$CID_VIDEO_ALLOWED = array('video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov');
define('CID_MAX_VIDEO', 100 * 1024 * 1024);
$CID_ACCESS_ROLES = array('1210813641176383547');
$CID_SUPERADMIN_ROLES = array('1370345783794208939');
$CID_TYPES = array('MC', 'GANG', 'ORGANISATION', 'FAMILLE', 'INDEPENDANTS', 'LABORATOIRES', 'MEURTRE', 'DIVERS', 'SENSIBLES');

$CID_PERMS = array(
    'access'           => 'Accès au module CID',
    'dossier_create'   => 'Créer un dossier',
    'dossier_edit'     => 'Modifier un dossier',
    'dossier_delete'   => 'Supprimer un dossier',
    'content_create'   => 'Créer du contenu (interro, infos, tél, notes)',
    'content_edit'     => 'Modifier du contenu',
    'content_delete'   => 'Supprimer du contenu',
    'hierarchy_manage' => 'Gérer la hiérarchie (organigramme)',
    'pin_manage'       => 'Gérer les infos épinglées',
    'taxonomy_manage'  => 'Gérer les statuts & les types de groupe',
    'logs_view'        => 'Consulter le journal',
    'perms_manage'     => 'Gérer les permissions & la confidentialité',
);

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_dossiers (
        id VARCHAR(50) PRIMARY KEY,
        nom VARCHAR(255) NOT NULL,
        type_groupe VARCHAR(30) NOT NULL DEFAULT 'DIVERS',
        logo_url TEXT DEFAULT NULL,
        photo_qg_url TEXT DEFAULT NULL,
        photo_carte_url TEXT DEFAULT NULL,
        description LONGTEXT DEFAULT NULL,
        created_by VARCHAR(30) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (type_groupe)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_pins (
        id VARCHAR(50) PRIMARY KEY,
        titre VARCHAR(200) NOT NULL,
        contenu TEXT DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'modere',
        created_by VARCHAR(30) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_informations (
        id VARCHAR(50) PRIMARY KEY, dossier_id VARCHAR(50) DEFAULT NULL,
        titre VARCHAR(200) NOT NULL, contenu LONGTEXT DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'modere', epingle TINYINT(1) NOT NULL DEFAULT 0,
        created_by VARCHAR(30) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doss (dossier_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_telephones (
        id VARCHAR(50) PRIMARY KEY, dossier_id VARCHAR(50) DEFAULT NULL,
        nom_prenom VARCHAR(200) NOT NULL, numero VARCHAR(30) DEFAULT NULL,
        contenu LONGTEXT DEFAULT NULL, images LONGTEXT DEFAULT NULL,
        created_by VARCHAR(30) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doss (dossier_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_interrogatoires (
        id VARCHAR(50) PRIMARY KEY, dossier_id VARCHAR(50) DEFAULT NULL,
        titre VARCHAR(200) NOT NULL, resume LONGTEXT DEFAULT NULL,
        identites LONGTEXT DEFAULT NULL, images LONGTEXT DEFAULT NULL,
        created_by VARCHAR(30) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doss (dossier_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_notes (
        id VARCHAR(50) PRIMARY KEY, dossier_id VARCHAR(50) NOT NULL,
        titre VARCHAR(200) NOT NULL, contenu LONGTEXT DEFAULT NULL,
        faits_reproches LONGTEXT DEFAULT NULL, images LONGTEXT DEFAULT NULL,
        created_by VARCHAR(30) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doss (dossier_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_hierarchie (
        id VARCHAR(50) PRIMARY KEY, dossier_id VARCHAR(50) NOT NULL,
        nom VARCHAR(150) NOT NULL, grade VARCHAR(100) DEFAULT NULL,
        photo_url TEXT DEFAULT NULL, parent_id VARCHAR(50) DEFAULT NULL, ordre INT DEFAULT 0,
        created_by VARCHAR(30) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doss (dossier_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_role_perms (
        role_id VARCHAR(30) NOT NULL, perm VARCHAR(40) NOT NULL,
        PRIMARY KEY (role_id, perm))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_category_visibility (
        type_groupe VARCHAR(30) NOT NULL, role_id VARCHAR(30) NOT NULL,
        PRIMARY KEY (type_groupe, role_id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255))");

    $seeded = $conn->query("SELECT v FROM cid_meta WHERE k = 'perms_seeded'")->fetchColumn();
    if (!$seeded) {
        $base = '1210813641176383547';
        $def = array('access', 'dossier_create', 'dossier_edit', 'content_create', 'content_edit', 'content_delete', 'hierarchy_manage', 'pin_manage');
        $ins = $conn->prepare("INSERT IGNORE INTO cid_role_perms (role_id, perm) VALUES (:r, :p)");
        foreach ($def as $p) { $ins->execute(array(':r' => $base, ':p' => $p)); }
        $conn->prepare("INSERT INTO cid_meta (k, v) VALUES ('perms_seeded', '1')")->execute();
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(20) NOT NULL, entity_type VARCHAR(30) NOT NULL,
        entity_id VARCHAR(50) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL,
        actor_id VARCHAR(30) DEFAULT NULL, actor_name VARCHAR(150) DEFAULT NULL,
        before_json LONGTEXT DEFAULT NULL, after_json LONGTEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_created (created_at))");
    cid_ensure_col($conn, 'cid_informations', 'images', 'images LONGTEXT DEFAULT NULL');
    cid_ensure_col($conn, 'cid_hierarchie', 'telephone', 'telephone VARCHAR(30) DEFAULT NULL');
    cid_ensure_col($conn, 'cid_hierarchie', 'emploi', 'emploi VARCHAR(150) DEFAULT NULL');
    cid_ensure_col($conn, 'cid_hierarchie', 'decede', 'decede TINYINT(1) NOT NULL DEFAULT 0');
    cid_ensure_col($conn, 'cid_telephones', 'preuves', 'preuves LONGTEXT DEFAULT NULL');
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_dossier_statuts (
        id VARCHAR(50) PRIMARY KEY, titre VARCHAR(100) NOT NULL,
        couleur VARCHAR(20) NOT NULL DEFAULT '#6366f1', icone VARCHAR(60) DEFAULT 'fa-flag',
        ordre INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    cid_ensure_col($conn, 'cid_dossiers', 'statut_id', 'statut_id VARCHAR(50) DEFAULT NULL');
    cid_ensure_col($conn, 'cid_dossiers', 'archived_at', 'archived_at DATETIME NULL DEFAULT NULL');
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_types_groupe (
        id VARCHAR(50) PRIMARY KEY, cle VARCHAR(30) NOT NULL UNIQUE, titre VARCHAR(100) NOT NULL,
        couleur VARCHAR(20) NOT NULL DEFAULT '#64748b', icone VARCHAR(60) DEFAULT 'fa-folder',
        ordre INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    if (!$conn->query("SELECT v FROM cid_meta WHERE k = 'types_seeded'")->fetchColumn()) {
        $seed = array(
            array('MC', 'MC', '#f59e0b', 'fa-motorcycle'),
            array('GANG', 'Gang', '#ef4444', 'fa-users-rectangle'),
            array('ORGANISATION', 'Organisation', '#8b5cf6', 'fa-sitemap'),
            array('FAMILLE', 'Famille', '#ec4899', 'fa-people-roof'),
            array('INDEPENDANTS', 'Indépendants', '#06b6d4', 'fa-user'),
            array('LABORATOIRES', 'Laboratoires', '#10b981', 'fa-flask'),
            array('MEURTRE', 'Meurtre', '#dc2626', 'fa-skull'),
            array('DIVERS', 'Divers', '#64748b', 'fa-folder'),
            array('SENSIBLES', 'Sensibles', '#eab308', 'fa-triangle-exclamation'),
        );
        $ins = $conn->prepare("INSERT IGNORE INTO cid_types_groupe (id, cle, titre, couleur, icone, ordre) VALUES (:i, :c, :t, :co, :ic, :o)");
        foreach ($seed as $i => $s) {
            $ins->execute(array(':i' => 'typ_' . strtolower($s[0]), ':c' => $s[0], ':t' => $s[1], ':co' => $s[2], ':ic' => $s[3], ':o' => $i));
        }
        $conn->prepare("INSERT INTO cid_meta (k, v) VALUES ('types_seeded', '1')")->execute();
    }
    if (!$conn->query("SELECT v FROM cid_meta WHERE k = 'perms_split_v2'")->fetchColumn()) {
        $conn->exec("INSERT IGNORE INTO cid_role_perms (role_id, perm)
                     SELECT role_id, 'taxonomy_manage' FROM cid_role_perms WHERE perm = 'perms_manage'");
        $conn->exec("INSERT IGNORE INTO cid_role_perms (role_id, perm)
                     SELECT role_id, 'logs_view' FROM cid_role_perms WHERE perm = 'perms_manage'");
        $conn->prepare("INSERT INTO cid_meta (k, v) VALUES ('perms_split_v2', '1')")->execute();
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS cid_replies (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(30) NOT NULL, entity_id VARCHAR(50) NOT NULL,
        author_id VARCHAR(30) DEFAULT NULL, author_name VARCHAR(150) DEFAULT NULL,
        contenu TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ent (entity_type, entity_id, id))");
} catch (PDOException $e) {}

function cid_safe_img($u) {
    if (!is_string($u)) return null;
    $u = trim($u);
    if ($u === '') return null;
    if (preg_match('#^/cid/uploads/[A-Za-z0-9._-]+$#', $u)) return $u;
    if (preg_match('#^/videos/up_[A-Za-z0-9._-]+$#', $u)) return $u;
    if (preg_match('#^https?://[^\s\'"<>()\\\\]+$#', $u)) return $u;
    return null;
}
function cid_delete_image($url) {
    if (!is_string($url) || !preg_match('#^/cid/uploads/([A-Za-z0-9._-]+)$#', trim($url), $m)) return;
    $path = CID_UPLOAD_DIR . $m[1];
    if (is_file($path)) @unlink($path);
}
function cid_delete_images($list) {
    if (!is_array($list)) return;
    foreach ($list as $u) cid_delete_image($u);
}
function cid_json_images($raw) {
    if (!$raw) return array();
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}
function cid_preuve_medias($raw) {
    $out = array();
    if (!$raw) return $out;
    $d = is_array($raw) ? $raw : json_decode($raw, true);
    if (!is_array($d)) return $out;
    foreach ($d as $p) {
        if (!is_array($p) || empty($p['medias']) || !is_array($p['medias'])) continue;
        foreach ($p['medias'] as $m) {
            $u = is_array($m) ? (isset($m['url']) ? $m['url'] : '') : $m;
            if ($u) $out[] = $u;
        }
    }
    return $out;
}
function cid_restricted_types($conn) {
    try { return $conn->query("SELECT DISTINCT type_groupe FROM cid_category_visibility")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { return array(); }
}
function cid_sql_not_hidden($hiddenIds, $col = 'dossier_id') {
    if (!$hiddenIds) return array('', array());
    $ph = implode(',', array_fill(0, count($hiddenIds), '?'));
    return array(" AND ($col IS NULL OR $col NOT IN ($ph))", array_values($hiddenIds));
}
function cid_entity_counts($conn, $actx) {
    global $CID_ENTITIES;
    $hiddenIds = cid_hidden_dossier_ids($conn, $actx);
    $counts = array();
    foreach ($CID_ENTITIES as $k => $e) {
        if ($e['doss']) continue;
        if ($hiddenIds) {
            $ph = implode(',', array_fill(0, count($hiddenIds), '?'));
            $q = $conn->prepare("SELECT COUNT(*) FROM {$e['table']} WHERE (dossier_id IS NULL OR dossier_id NOT IN ($ph))");
            $q->execute(array_values($hiddenIds));
            $counts[$k] = (int)$q->fetchColumn();
        } else {
            $counts[$k] = (int)$conn->query("SELECT COUNT(*) FROM {$e['table']}")->fetchColumn();
        }
    }
    return $counts;
}
function cid_safe_local_media($u) {
    if (!is_string($u)) return null;
    $u = trim($u);
    if (preg_match('#^/cid/uploads/[A-Za-z0-9._-]+$#', $u)) return $u;
    if (preg_match('#^/videos/up_[A-Za-z0-9._-]+$#', $u)) return $u;
    return null;
}
function cid_safe_preuves($raw) {
    $out = array();
    if (!is_array($raw)) return $out;
    foreach ($raw as $p) {
        if (!is_array($p)) continue;
        $meds = array();
        if (!empty($p['medias']) && is_array($p['medias'])) {
            foreach ($p['medias'] as $m) {
                if (!is_array($m)) continue;
                $u = cid_safe_local_media(isset($m['url']) ? $m['url'] : '');
                if (!$u) continue;
                $meds[] = array('url' => $u, 'type' => (isset($m['type']) && $m['type'] === 'video') ? 'video' : 'image');
                if (count($meds) >= 30) break;
            }
        }
        $out[] = array(
            'titre'   => mb_substr((string)(isset($p['titre']) ? $p['titre'] : ''), 0, 300),
            'contenu' => mb_substr((string)(isset($p['contenu']) ? $p['contenu'] : ''), 0, 20000),
            'medias'  => $meds
        );
        if (count($out) >= 50) break;
    }
    return $out;
}
function cid_reply_dossier($conn, $entityType, $entityId) {
    global $CID_ENTITIES;
    if (!isset($CID_ENTITIES[$entityType])) return false;
    $table = $CID_ENTITIES[$entityType]['table'];
    try {
        $s = $conn->prepare("SELECT dossier_id FROM `$table` WHERE id = :i LIMIT 1");
        $s->execute(array(':i' => $entityId));
        $v = $s->fetchColumn();
        return ($v === false) ? false : $v;
    } catch (Exception $e) { return false; }
}
function cid_reply_guard($conn, $entityType, $entityId) {
    $actx = cid_actx($conn);
    $dossierId = cid_reply_dossier($conn, $entityType, $entityId);
    if ($dossierId === false) mdt_error(404, 'E-CID-404', 'Element introuvable');
    if ($dossierId) {
        $dt = $conn->prepare("SELECT type_groupe FROM cid_dossiers WHERE id = :d LIMIT 1");
        $dt->execute(array(':d' => $dossierId));
        $dtype = $dt->fetchColumn();
        if ($dtype !== false && in_array($dtype, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
    }
    return $dossierId;
}

function cid_types($conn) {
    static $c = null;
    if ($c !== null) return $c;
    try {
        $c = $conn->query("SELECT id, cle, titre, couleur, icone, ordre FROM cid_types_groupe ORDER BY ordre, titre")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $c = array(); }
    if (!$c) {
        global $CID_TYPES;
        $c = array();
        foreach ($CID_TYPES as $i => $t) {
            $c[] = array('id' => 'typ_' . strtolower($t), 'cle' => $t, 'titre' => $t, 'couleur' => '#64748b', 'icone' => 'fa-folder', 'ordre' => $i);
        }
    }
    return $c;
}
function cid_type_keys($conn) { return array_column(cid_types($conn), 'cle'); }
function cid_type_slug($titre) {
    $s = (string)$titre;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') $s = $t;
    }
    $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s);
    $s = trim((string)$s, '_');
    return mb_substr(strtoupper($s), 0, 30);
}

$CID_STATUTS = array('important', 'modere', 'faible');

$CID_ENTITIES = array(
    'information'    => array('table' => 'cid_informations',   'prefix' => 'info_', 'label' => 'titre',      'fields' => array('titre', 'contenu', 'statut', 'epingle', 'images'), 'json' => array('images'),               'order' => "epingle DESC, FIELD(statut,'important','modere','faible'), created_at DESC", 'doss' => false),
    'telephone'     => array('table' => 'cid_telephones',      'prefix' => 'tel_',  'label' => 'nom_prenom', 'fields' => array('nom_prenom', 'numero', 'contenu', 'preuves', 'images'),           'json' => array('preuves', 'images'),               'order' => 'created_at DESC', 'doss' => false),
    'interrogatoire'=> array('table' => 'cid_interrogatoires', 'prefix' => 'intr_', 'label' => 'titre',      'fields' => array('titre', 'resume', 'identites', 'images'),              'json' => array('identites', 'images'),  'order' => 'created_at DESC', 'doss' => false),
    'note'          => array('table' => 'cid_notes',           'prefix' => 'note_', 'label' => 'titre',      'fields' => array('titre', 'contenu', 'faits_reproches', 'images'),       'json' => array('faits_reproches', 'images'), 'order' => 'created_at DESC', 'doss' => true),
    'membre'        => array('table' => 'cid_hierarchie',      'prefix' => 'mbr_',  'label' => 'nom',        'fields' => array('nom', 'grade', 'emploi', 'telephone', 'photo_url', 'parent_id', 'ordre', 'decede'), 'json' => array(), 'order' => 'ordre, nom', 'doss' => true),
);

function cid_actor($conn) {
    return mdt_session_discord_id($conn);
}
function cid_ctx_roles($conn, $did) {
    static $c = array();
    if (!$did) return array();
    if (isset($c[$did])) return $c[$did];
    $roles = array();
    try {
        $r = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = :d LIMIT 1");
        $r->execute(array(':d' => $did));
        $raw = $r->fetchColumn();
        $decoded = $raw ? json_decode($raw, true) : array();
        if (is_array($decoded)) $roles = $decoded;
    } catch (Exception $e) {}
    $c[$did] = $roles;
    return $roles;
}
function cid_is_superadmin($conn, $did) {
    global $CID_SUPERADMIN_ROLES;
    if (!$did) return false;
    if (function_exists('mdt_is_owner') && mdt_is_owner($did)) return true;
    try {
        $s = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = :d LIMIT 1");
        $s->execute(array(':d' => $did));
        if ($s->fetch()) return true;
    } catch (Exception $e) {}
    $roles = cid_ctx_roles($conn, $did);
    $admin = function_exists('mdt_admin_roles') ? mdt_admin_roles() : array();
    foreach ($admin as $r) { if (in_array($r, $roles, true)) return true; }
    foreach ($CID_SUPERADMIN_ROLES as $r) { if (in_array($r, $roles, true)) return true; }
    return false;
}

function cid_actx($conn) {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $CID_PERMS, $CID_ACCESS_ROLES;
    $did = cid_actor($conn);
    $roles = cid_ctx_roles($conn, $did);
    $super = cid_is_superadmin($conn, $did);
    $perms = array();
    if ($super) {
        foreach ($CID_PERMS as $k => $v) { $perms[$k] = true; }
    } else {
        foreach ($CID_ACCESS_ROLES as $r) { if (in_array($r, $roles, true)) { $perms['access'] = true; break; } }
        if ($roles) {
            $ph = implode(',', array_fill(0, count($roles), '?'));
            try {
                $st = $conn->prepare("SELECT DISTINCT perm FROM cid_role_perms WHERE role_id IN ($ph)");
                $st->execute(array_values($roles));
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $perms[$p] = true; }
            } catch (Exception $e) {}
        }
    }
    $hidden = array();
    if (!$super) {
        try {
            $rows = $conn->query("SELECT type_groupe, GROUP_CONCAT(role_id) AS roles FROM cid_category_visibility GROUP BY type_groupe")->fetchAll();
            foreach ($rows as $row) {
                $allowed = explode(',', (string)$row['roles']);
                $ok = false;
                foreach ($allowed as $ar) { if (in_array($ar, $roles, true)) { $ok = true; break; } }
                if (!$ok) $hidden[] = $row['type_groupe'];
            }
        } catch (Exception $e) {}
    }
    $cache = array('did' => $did, 'super' => $super, 'roles' => $roles, 'perms' => $perms, 'hidden' => $hidden);
    return $cache;
}
function cid_has_access($conn) { $c = cid_actx($conn); return !empty($c['perms']['access']); }
function cid_can($conn, $perm) { $c = cid_actx($conn); return !empty($c['perms'][$perm]); }
function cid_require_access($conn) {
    if (!cid_has_access($conn)) mdt_error(403, 'E-CID-403', 'Acces reserve au CID');
}
function cid_require_perm($conn, $perm) {
    cid_require_access($conn);
    if (!cid_can($conn, $perm)) mdt_error(403, 'E-CID-403', 'Permission refusee');
}
function cid_hidden_dossier_ids($conn, $actx) {
    if (empty($actx['hidden'])) return array();
    $ph = implode(',', array_fill(0, count($actx['hidden']), '?'));
    $q = $conn->prepare("SELECT id FROM cid_dossiers WHERE type_groupe IN ($ph)");
    $q->execute(array_values($actx['hidden']));
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

function cid_read_env($path) {
    $env = array();
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $p = strpos($line, '=');
            if ($p === false) continue;
            $env[trim(substr($line, 0, $p))] = trim(trim(substr($line, $p + 1)), "\"'");
        }
    }
    return $env;
}

function cid_guild_roles() {
    $cache = sys_get_temp_dir() . '/cid_roles_cache.json';
    if (is_file($cache) && (time() - filemtime($cache) < 600)) {
        $c = json_decode(@file_get_contents($cache), true);
        if (is_array($c)) return $c;
    }
    $env = cid_read_env(__DIR__ . '/../../bot/.env');
    $token = isset($env['BOT_TOKEN']) ? $env['BOT_TOKEN'] : '';
    $guild = isset($env['GUILD_ID']) ? $env['GUILD_ID'] : '';
    if (!$token || !$guild || !function_exists('curl_init')) {
        $c = is_file($cache) ? json_decode(@file_get_contents($cache), true) : array();
        return is_array($c) ? $c : array();
    }
    $ch = curl_init("https://discord.com/api/v10/guilds/$guild/roles");
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array("Authorization: Bot $token"),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 8,
    ));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if ($code !== 200 || !is_array($data)) {
        $c = is_file($cache) ? json_decode(@file_get_contents($cache), true) : array();
        return is_array($c) ? $c : array();
    }
    $roles = array();
    foreach ($data as $r) {
        if (!isset($r['id']) || $r['id'] === $guild) continue;
        $roles[] = array('id' => $r['id'], 'name' => $r['name'], 'color' => isset($r['color']) ? $r['color'] : 0, 'position' => isset($r['position']) ? $r['position'] : 0);
    }
    usort($roles, function($a, $b) { return $b['position'] - $a['position']; });
    @file_put_contents($cache, json_encode($roles));
    return $roles;
}

function cid_is_cid_role($name) {
    if ($name === null) return false;
    if (mb_strpos($name, "\u{1F575}") !== false) return true;
    return (bool)preg_match('/(^|[^a-z])cid([^a-z]|$)/iu', $name);
}
function cid_cid_roles() {
    $out = array();
    foreach (cid_guild_roles() as $r) { if (cid_is_cid_role($r['name'])) $out[] = $r; }
    return $out;
}

function cid_ensure_col($conn, $table, $col, $ddl) {
    try {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $s->execute(array(':t' => $table, ':c' => $col));
        if ((int)$s->fetchColumn() === 0) $conn->exec("ALTER TABLE $table ADD COLUMN $ddl");
    } catch (Exception $e) {}
}

function cid_actor_name($conn, $did) {
    if (!$did) return 'Inconnu';
    try {
        $s = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username) FROM users WHERE discord_id = :d LIMIT 1");
        $s->execute(array(':d' => $did));
        $n = $s->fetchColumn();
        return $n ? $n : $did;
    } catch (Exception $e) { return $did; }
}
function cid_role_perms_snap($conn, $roleId) {
    try {
        $s = $conn->prepare("SELECT perm FROM cid_role_perms WHERE role_id = :r ORDER BY perm");
        $s->execute(array(':r' => $roleId));
        $p = $s->fetchAll(PDO::FETCH_COLUMN);
        return $p ? array('droits' => implode(', ', $p)) : null;
    } catch (Exception $e) { return null; }
}
function cid_cat_vis_snap($conn, $type) {
    try {
        $s = $conn->prepare("SELECT role_id FROM cid_category_visibility WHERE type_groupe = :t ORDER BY role_id");
        $s->execute(array(':t' => $type));
        $r = $s->fetchAll(PDO::FETCH_COLUMN);
        return array('roles_autorises' => $r ? implode(', ', $r) : 'tous les membres CID');
    } catch (Exception $e) { return null; }
}
function cid_role_label($conn, $roleId) {
    $roles = cid_guild_roles();
    foreach ($roles as $r) { if (isset($r['id']) && $r['id'] === $roleId) return $r['name']; }
    return 'Rôle ' . $roleId;
}
function cid_log($conn, $action, $type, $id, $label, $before, $after) {
    try {
        $did = cid_actor($conn);
        $conn->prepare("INSERT INTO cid_logs (action, entity_type, entity_id, label, actor_id, actor_name, before_json, after_json) VALUES (:a,:t,:i,:l,:aid,:an,:b,:af)")
            ->execute(array(
                ':a' => $action, ':t' => $type, ':i' => $id, ':l' => mb_substr((string)$label, 0, 255),
                ':aid' => $did, ':an' => cid_actor_name($conn, $did),
                ':b' => ($before !== null) ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                ':af' => ($after !== null) ? json_encode($after, JSON_UNESCAPED_UNICODE) : null
            ));
    } catch (Exception $e) {}
}
function cid_entity_snapshot($e, $row) {
    if (!$row) return null;
    $snap = array();
    if (array_key_exists('dossier_id', $row)) $snap['dossier_id'] = $row['dossier_id'];
    foreach ($e['fields'] as $f) {
        $v = isset($row[$f]) ? $row[$f] : null;
        if (in_array($f, $e['json'], true)) $v = ($v ? json_decode($v, true) : array());
        $snap[$f] = $v;
    }
    return $snap;
}

function cid_store_image($tmp, $mime) {
    global $CID_ALLOWED;
    $ext = isset($CID_ALLOWED[$mime]) ? $CID_ALLOWED[$mime] : 'bin';
    $rawName = uniqid('cimg_') . '_' . bin2hex(random_bytes(6));
    if ($mime === 'image/gif' || !function_exists('imagecreatetruecolor')) {
        $name = $rawName . '.' . $ext;
        if (move_uploaded_file($tmp, CID_UPLOAD_DIR . $name)) return $name;
        return @copy($tmp, CID_UPLOAD_DIR . $name) ? $name : false;
    }
    $src = null;
    if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($tmp);
    elseif ($mime === 'image/png') $src = @imagecreatefrompng($tmp);
    elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($tmp);
    if (!$src) {
        $name = $rawName . '.' . $ext;
        return move_uploaded_file($tmp, CID_UPLOAD_DIR . $name) ? $name : false;
    }
    $w = imagesx($src); $h = imagesy($src); $max = 1600;
    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $nw = max(1, (int)round($w * $ratio)); $nh = max(1, (int)round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src); $src = $dst;
    }
    imagealphablending($src, false); imagesavealpha($src, true);
    $name = $rawName . '.webp';
    $ok = function_exists('imagewebp') ? @imagewebp($src, CID_UPLOAD_DIR . $name, 80) : false;
    if (!$ok) { $name = $rawName . '.jpg'; $ok = @imagejpeg($src, CID_UPLOAD_DIR . $name, 85); }
    imagedestroy($src);
    return $ok ? $name : false;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {

    if ($action === 'meta') {
        $c = cid_actx($conn);
        echo json_encode(array(
            'access' => !empty($c['perms']['access']),
            'super' => $c['super'],
            'perms' => $c['perms'],
            'can_manage_perms' => !empty($c['perms']['perms_manage']),
            'hidden_types' => array_values($c['hidden']),
            'types' => cid_type_keys($conn)
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'bootstrap') {
        $c = cid_actx($conn);
        $out = array(
            'access' => !empty($c['perms']['access']),
            'super' => $c['super'],
            'perms' => $c['perms'],
            'can_manage_perms' => !empty($c['perms']['perms_manage']),
            'hidden_types' => array_values($c['hidden']),
            'types' => cid_types($conn),
        );
        if (!$out['access']) { echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; }

        $hidden = $c['hidden'];
        if ($hidden) {
            $ph = implode(',', array_fill(0, count($hidden), '?'));
            $st = $conn->prepare("SELECT id, nom, type_groupe, statut_id, logo_url, updated_at FROM cid_dossiers WHERE archived_at IS NULL AND type_groupe NOT IN ($ph) ORDER BY nom");
            $st->execute(array_values($hidden));
            $out['dossiers'] = $st->fetchAll();
            $ac = $conn->prepare("SELECT COUNT(*) FROM cid_dossiers WHERE archived_at IS NOT NULL AND type_groupe NOT IN ($ph)");
            $ac->execute(array_values($hidden));
            $out['archived_count'] = (int)$ac->fetchColumn();
        } else {
            $out['dossiers'] = $conn->query("SELECT id, nom, type_groupe, statut_id, logo_url, updated_at FROM cid_dossiers WHERE archived_at IS NULL ORDER BY nom")->fetchAll();
            $out['archived_count'] = (int)$conn->query("SELECT COUNT(*) FROM cid_dossiers WHERE archived_at IS NOT NULL")->fetchColumn();
        }

        $out['statuts'] = $conn->query("SELECT id, titre, couleur, icone, ordre FROM cid_dossier_statuts ORDER BY ordre, titre")->fetchAll();
        $out['pins'] = $conn->query("SELECT id, titre, contenu, statut, created_at FROM cid_pins ORDER BY FIELD(statut,'important','modere','faible'), created_at DESC")->fetchAll();

        $out['counts'] = cid_entity_counts($conn, $c);
        $out['restricted_types'] = array_values(cid_restricted_types($conn));

        $seedDefaults = array('access', 'dossier_create', 'dossier_edit', 'content_create', 'content_edit', 'content_delete', 'hierarchy_manage', 'pin_manage');
        $out['warnings'] = array();
        if (!empty($c['perms']['perms_manage'])) {
            $base = '1210813641176383547';
            $have = $conn->prepare("SELECT perm FROM cid_role_perms WHERE role_id = :r");
            $have->execute(array(':r' => $base));
            $missing = array_values(array_diff($seedDefaults, $have->fetchAll(PDO::FETCH_COLUMN)));
            if ($missing) $out['warnings'][] = array('code' => 'base_role_amputee', 'role_id' => $base, 'missing' => $missing);

            $restricted = cid_restricted_types($conn);
            $open = array_values(array_diff(cid_type_keys($conn), $restricted));
            if ($restricted && $open) $out['warnings'][] = array('code' => 'categories_ouvertes', 'types' => $open);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'search') {
        cid_require_access($conn);
        $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
        if (mb_strlen($q) < 2) { echo json_encode(array('q' => $q, 'results' => array())); exit; }
        $c = cid_actx($conn);
        $like = '%' . $q . '%';
        $hiddenIds = cid_hidden_dossier_ids($conn, $c);
        $res = array();

        $dw = ''; $dargs = array($like, $like);
        if ($c['hidden']) {
            $dph = implode(',', array_fill(0, count($c['hidden']), '?'));
            $dw = " AND type_groupe NOT IN ($dph)";
            $dargs = array_merge($dargs, array_values($c['hidden']));
        }
        $st = $conn->prepare("SELECT id, nom, type_groupe FROM cid_dossiers WHERE archived_at IS NULL AND (nom LIKE ? OR description LIKE ?)$dw ORDER BY nom LIMIT 20");
        $st->execute($dargs);
        foreach ($st->fetchAll() as $r) {
            $res[] = array('kind' => 'dossier', 'id' => $r['id'], 'titre' => $r['nom'], 'meta' => $r['type_groupe'], 'url' => '/cid/dossier/' . $r['id']);
        }

        $map = array(
            'note'           => array('cid_notes', 'titre', 'contenu', 'notes'),
            'information'    => array('cid_informations', 'titre', 'contenu', 'informations'),
            'interrogatoire' => array('cid_interrogatoires', 'titre', 'resume', 'interrogatoires'),
            'telephone'      => array('cid_telephones', 'nom_prenom', 'contenu', 'telephones'),
        );
        foreach ($map as $kind => $m) {
            list($table, $lbl, $body, $tab) = $m;
            $extra = ''; $args = array($like, $like);
            if ($kind === 'telephone') { $extra = " OR numero LIKE ?"; $args[] = $like; }
            elseif ($kind === 'note') { $extra = " OR faits_reproches LIKE ?"; $args[] = $like; }
            elseif ($kind === 'interrogatoire') { $extra = " OR identites LIKE ?"; $args[] = $like; }
            list($hw, $hargs) = cid_sql_not_hidden($hiddenIds);
            $st = $conn->prepare("SELECT id, dossier_id, `$lbl` AS lbl, `$body` AS body FROM `$table` WHERE (`$lbl` LIKE ? OR `$body` LIKE ?$extra)$hw ORDER BY created_at DESC LIMIT 20");
            $st->execute(array_merge($args, $hargs));
            foreach ($st->fetchAll() as $r) {
                $snip = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$r['body'])));
                $res[] = array(
                    'kind' => $kind, 'id' => $r['id'], 'titre' => $r['lbl'],
                    'meta' => mb_substr($snip, 0, 120),
                    'url' => '/cid/' . $kind . '/' . rawurlencode($r['id'])
                );
            }
        }

        list($mw, $margs) = cid_sql_not_hidden($hiddenIds);
        $st = $conn->prepare("SELECT id, dossier_id, nom, grade FROM cid_hierarchie WHERE (nom LIKE ? OR grade LIKE ? OR emploi LIKE ? OR telephone LIKE ?)$mw ORDER BY nom LIMIT 20");
        $st->execute(array_merge(array($like, $like, $like, $like), $margs));
        foreach ($st->fetchAll() as $r) {
            $res[] = array('kind' => 'membre', 'id' => $r['id'], 'titre' => $r['nom'], 'meta' => (string)$r['grade'], 'url' => '/cid/membre/' . rawurlencode($r['id']));
        }

        echo json_encode(array('q' => $q, 'results' => array_slice($res, 0, 50)), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'recent_activity') {
        cid_require_access($conn);
        $c = cid_actx($conn);
        $hidden = $c['hidden'];
        $hiddenIds = cid_hidden_dossier_ids($conn, $c);
        $dossName = array();
        foreach ($conn->query("SELECT id, nom FROM cid_dossiers")->fetchAll() as $r) { $dossName[$r['id']] = $r['nom']; }
        $items = array();

        if ($hidden) {
            $ph = implode(',', array_fill(0, count($hidden), '?'));
            $st = $conn->prepare("SELECT id, nom, type_groupe, created_at, updated_at FROM cid_dossiers WHERE archived_at IS NULL AND type_groupe NOT IN ($ph) ORDER BY GREATEST(COALESCE(updated_at, created_at), created_at) DESC LIMIT 8");
            $st->execute(array_values($hidden));
            $drows = $st->fetchAll();
        } else {
            $drows = $conn->query("SELECT id, nom, type_groupe, created_at, updated_at FROM cid_dossiers WHERE archived_at IS NULL ORDER BY GREATEST(COALESCE(updated_at, created_at), created_at) DESC LIMIT 8")->fetchAll();
        }
        foreach ($drows as $r) {
            $upd = $r['updated_at'] && $r['updated_at'] > $r['created_at'];
            $items[] = array('kind' => 'dossier', 'action' => $upd ? 'modifié' : 'créé', 'id' => $r['id'], 'titre' => $r['nom'], 'dossier_id' => null, 'dossier_nom' => null, 'when' => $upd ? $r['updated_at'] : $r['created_at'], 'url' => '/cid/dossier/' . $r['id']);
        }

        $map = array(
            'note' => array('cid_notes', 'titre', 'notes'),
            'interrogatoire' => array('cid_interrogatoires', 'titre', 'interrogatoires'),
            'information' => array('cid_informations', 'titre', 'informations'),
            'telephone' => array('cid_telephones', 'nom_prenom', 'telephones'),
        );
        foreach ($map as $kind => $m) {
            list($table, $lbl, $tab) = $m;
            list($hw, $hargs) = cid_sql_not_hidden($hiddenIds);
            $rq = $conn->prepare("SELECT id, dossier_id, `$lbl` AS lbl, created_at FROM `$table` WHERE 1=1$hw ORDER BY created_at DESC LIMIT 8");
            $rq->execute($hargs);
            $rows = $rq->fetchAll();
            foreach ($rows as $r) {
                $items[] = array('kind' => $kind, 'action' => 'ajouté', 'id' => $r['id'], 'titre' => $r['lbl'], 'dossier_id' => $r['dossier_id'], 'dossier_nom' => $r['dossier_id'] && isset($dossName[$r['dossier_id']]) ? $dossName[$r['dossier_id']] : null, 'when' => $r['created_at'], 'url' => '/cid/' . $kind . '/' . rawurlencode($r['id']));
            }
        }

        usort($items, function ($a, $b) { return strcmp($b['when'], $a['when']); });
        echo json_encode(array('items' => array_slice($items, 0, 12)), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'penal_codes') {
        cid_require_access($conn);
        $rows = array();
        try {
            $rows = $conn->query("SELECT code, cat, label FROM penal_code ORDER BY cat, ordre, code")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {}
        echo json_encode(array('codes' => $rows), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list') {
        cid_require_access($conn);
        $actx = cid_actx($conn);
        $wantArchived = !empty($_GET['archived']);
        $archCond = $wantArchived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        if (!empty($actx['hidden'])) {
            $ph = implode(',', array_fill(0, count($actx['hidden']), '?'));
            $st = $conn->prepare("SELECT id, nom, type_groupe, statut_id, logo_url, updated_at, archived_at FROM cid_dossiers WHERE $archCond AND type_groupe NOT IN ($ph) ORDER BY " . ($wantArchived ? 'archived_at DESC' : 'nom'));
            $st->execute(array_values($actx['hidden']));
            $rows = $st->fetchAll();
        } else {
            $rows = $conn->query("SELECT id, nom, type_groupe, statut_id, logo_url, updated_at, archived_at FROM cid_dossiers WHERE $archCond ORDER BY " . ($wantArchived ? 'archived_at DESC' : 'nom'))->fetchAll();
        }
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'archive' || $action === 'unarchive') {
        cid_require_perm($conn, 'dossier_edit');
        mdt_post_only();
        $data = mdt_get_post_data('E-CID-400');
        $id = isset($data['id']) ? trim((string)$data['id']) : '';
        if ($id === '') mdt_error(400, 'E-CID-400', 'ID requis');
        $c = cid_actx($conn);
        $row = $conn->prepare("SELECT id, nom, type_groupe, archived_at FROM cid_dossiers WHERE id = :id");
        $row->execute(array(':id' => $id));
        $d = $row->fetch();
        if (!$d) mdt_error(404, 'E-CID-404', 'Dossier introuvable');
        if (in_array($d['type_groupe'], $c['hidden'], true)) mdt_error(403, 'E-CID-403', 'Acces refuse');
        if ($action === 'archive') {
            $conn->prepare("UPDATE cid_dossiers SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL")->execute(array(':id' => $id));
        } else {
            $conn->prepare("UPDATE cid_dossiers SET archived_at = NULL WHERE id = :id")->execute(array(':id' => $id));
        }
        cid_log($conn, $action, 'dossier', $id, $d['nom'], array('archived_at' => $d['archived_at']), array('archived_at' => $action === 'archive' ? date('Y-m-d H:i:s') : null));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'set_statut') {
        cid_require_perm($conn, 'dossier_edit');
        mdt_post_only();
        $data = mdt_get_post_data('E-CID-400');
        $id = isset($data['id']) ? trim((string)$data['id']) : '';
        if ($id === '') mdt_error(400, 'E-CID-400', 'ID requis');
        $statutId = isset($data['statut_id']) && $data['statut_id'] !== '' ? (string)$data['statut_id'] : null;
        if ($statutId !== null) {
            $chk = $conn->prepare("SELECT 1 FROM cid_dossier_statuts WHERE id = :s");
            $chk->execute(array(':s' => $statutId));
            if (!$chk->fetch()) mdt_error(400, 'E-CID-400', 'Statut inconnu');
        }
        $c = cid_actx($conn);
        $row = $conn->prepare("SELECT id, nom, type_groupe, statut_id FROM cid_dossiers WHERE id = :id");
        $row->execute(array(':id' => $id));
        $d = $row->fetch();
        if (!$d) mdt_error(404, 'E-CID-404', 'Dossier introuvable');
        if (in_array($d['type_groupe'], $c['hidden'], true)) mdt_error(403, 'E-CID-403', 'Acces refuse');
        $conn->prepare("UPDATE cid_dossiers SET statut_id = :s WHERE id = :id")->execute(array(':s' => $statutId, ':id' => $id));
        cid_log($conn, 'set_statut', 'dossier', $id, $d['nom'], array('statut_id' => $d['statut_id']), array('statut_id' => $statutId));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'get') {
        cid_require_access($conn);
        $id = isset($_GET['id']) ? $_GET['id'] : '';
        $stmt = $conn->prepare("SELECT * FROM cid_dossiers WHERE id = :id");
        $stmt->execute(array(':id' => $id));
        $row = $stmt->fetch();
        if (!$row) mdt_error(404, 'E-CID-404', 'Dossier introuvable');
        $actx = cid_actx($conn);
        if (in_array($row['type_groupe'], $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
        $counts = array();
        foreach (array('note' => 'cid_notes', 'interrogatoire' => 'cid_interrogatoires', 'information' => 'cid_informations', 'telephone' => 'cid_telephones', 'membre' => 'cid_hierarchie') as $k => $t) {
            try { $q = $conn->prepare("SELECT COUNT(*) FROM `$t` WHERE dossier_id = :d"); $q->execute(array(':d' => $id)); $counts[$k] = (int)$q->fetchColumn(); }
            catch (Exception $ex) { $counts[$k] = 0; }
        }
        $row['counts'] = $counts;
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'create') {
        mdt_post_only();
        cid_require_perm($conn, 'dossier_create');
        $data = mdt_get_post_data('E-CID-400');
        if (!$data || empty(trim((string)($data['nom'] ?? '')))) mdt_error(400, 'E-CID-400', 'Nom requis');
        $type = (isset($data['type_groupe']) && in_array($data['type_groupe'], cid_type_keys($conn), true)) ? $data['type_groupe'] : 'DIVERS';
        $actx = cid_actx($conn);
        if (in_array($type, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie non autorisee');
        $statutId = (isset($data['statut_id']) && $data['statut_id']) ? $data['statut_id'] : null;
        $id = uniqid('cid_');
        $conn->prepare("INSERT INTO cid_dossiers (id, nom, type_groupe, statut_id, logo_url, photo_qg_url, photo_carte_url, description, created_by)
                        VALUES (:id, :nom, :type, :statut, :logo, :qg, :carte, :desc, :by)")
            ->execute(array(
                ':id' => $id,
                ':nom' => substr(trim($data['nom']), 0, 255),
                ':type' => $type,
                ':statut' => $statutId,
                ':logo' => isset($data['logo_url']) ? cid_safe_img($data['logo_url']) : null,
                ':qg' => isset($data['photo_qg_url']) ? cid_safe_img($data['photo_qg_url']) : null,
                ':carte' => isset($data['photo_carte_url']) ? cid_safe_img($data['photo_carte_url']) : null,
                ':desc' => isset($data['description']) ? $data['description'] : null,
                ':by' => cid_actor($conn)
            ));
        cid_log($conn, 'create', 'dossier', $id, $data['nom'], null, array('nom' => substr(trim($data['nom']), 0, 255), 'type_groupe' => $type, 'statut_id' => $statutId, 'description' => isset($data['description']) ? $data['description'] : null));
        echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'update') {
        mdt_post_only();
        cid_require_perm($conn, 'dossier_edit');
        $data = mdt_get_post_data('E-CID-400');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-CID-400', 'id requis');
        $chk = $conn->prepare("SELECT nom, type_groupe, statut_id, description, logo_url, photo_qg_url, photo_carte_url FROM cid_dossiers WHERE id = :id");
        $chk->execute(array(':id' => $data['id']));
        $oldDoss = $chk->fetch();
        if (!$oldDoss) mdt_error(404, 'E-CID-404', 'Dossier introuvable');
        $curType = $oldDoss['type_groupe'];
        $type = (isset($data['type_groupe']) && in_array($data['type_groupe'], cid_type_keys($conn), true)) ? $data['type_groupe'] : 'DIVERS';
        $statutId = (isset($data['statut_id']) && $data['statut_id']) ? $data['statut_id'] : null;
        $actx = cid_actx($conn);
        if (in_array($curType, $actx['hidden'], true) || in_array($type, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
        $newLogo = isset($data['logo_url']) ? cid_safe_img($data['logo_url']) : null;
        $newQg = isset($data['photo_qg_url']) ? cid_safe_img($data['photo_qg_url']) : null;
        $newCarte = isset($data['photo_carte_url']) ? cid_safe_img($data['photo_carte_url']) : null;
        if ($oldDoss['logo_url'] && $oldDoss['logo_url'] !== $newLogo) cid_delete_image($oldDoss['logo_url']);
        if ($oldDoss['photo_qg_url'] && $oldDoss['photo_qg_url'] !== $newQg) cid_delete_image($oldDoss['photo_qg_url']);
        if ($oldDoss['photo_carte_url'] && $oldDoss['photo_carte_url'] !== $newCarte) cid_delete_image($oldDoss['photo_carte_url']);
        $conn->prepare("UPDATE cid_dossiers SET nom = :nom, type_groupe = :type, statut_id = :statut, logo_url = :logo, photo_qg_url = :qg, photo_carte_url = :carte, description = :desc WHERE id = :id")
            ->execute(array(
                ':nom' => substr(trim((string)($data['nom'] ?? '')), 0, 255),
                ':type' => $type,
                ':statut' => $statutId,
                ':logo' => $newLogo,
                ':qg' => $newQg,
                ':carte' => $newCarte,
                ':desc' => isset($data['description']) ? $data['description'] : null,
                ':id' => $data['id']
            ));
        cid_log($conn, 'update', 'dossier', $data['id'], substr(trim((string)($data['nom'] ?? '')), 0, 255),
            array('nom' => $oldDoss['nom'], 'type_groupe' => $oldDoss['type_groupe'], 'statut_id' => $oldDoss['statut_id'], 'description' => $oldDoss['description']),
            array('nom' => substr(trim((string)($data['nom'] ?? '')), 0, 255), 'type_groupe' => $type, 'statut_id' => $statutId, 'description' => isset($data['description']) ? $data['description'] : null));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete') {
        mdt_post_only();
        cid_require_perm($conn, 'dossier_delete');
        $data = mdt_get_post_data('E-CID-400');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-CID-400', 'id requis');
        $chk = $conn->prepare("SELECT nom, type_groupe, statut_id, description, logo_url, photo_qg_url, photo_carte_url FROM cid_dossiers WHERE id = :id");
        $chk->execute(array(':id' => $data['id']));
        $oldDoss = $chk->fetch();
        if (!$oldDoss) mdt_error(404, 'E-CID-404', 'Dossier introuvable');
        $curType = $oldDoss['type_groupe'];
        $actx = cid_actx($conn);
        if (in_array($curType, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
        cid_log($conn, 'delete', 'dossier', $data['id'], $oldDoss['nom'], array('nom' => $oldDoss['nom'], 'type_groupe' => $oldDoss['type_groupe'], 'statut_id' => $oldDoss['statut_id'], 'description' => $oldDoss['description']), null);
        cid_delete_image($oldDoss['logo_url']); cid_delete_image($oldDoss['photo_qg_url']); cid_delete_image($oldDoss['photo_carte_url']);
        try {
            $ni = $conn->prepare("SELECT images FROM cid_notes WHERE dossier_id = :id"); $ni->execute(array(':id' => $data['id']));
            foreach ($ni->fetchAll(PDO::FETCH_COLUMN) as $raw) cid_delete_images(cid_json_images($raw));
            $mp = $conn->prepare("SELECT photo_url FROM cid_hierarchie WHERE dossier_id = :id"); $mp->execute(array(':id' => $data['id']));
            foreach ($mp->fetchAll(PDO::FETCH_COLUMN) as $p) cid_delete_image($p);
        } catch (Exception $ex) {}

        $conn->prepare("DELETE FROM cid_replies WHERE entity_type = 'note' AND entity_id IN (SELECT id FROM (SELECT id FROM cid_notes WHERE dossier_id = :id) t)")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM cid_notes WHERE dossier_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM cid_hierarchie WHERE dossier_id = :id")->execute(array(':id' => $data['id']));

        $optional = array('cid_informations' => 'information', 'cid_telephones' => 'telephone', 'cid_interrogatoires' => 'interrogatoire');
        $confidentiel = in_array($curType, cid_restricted_types($conn), true);
        $purges = 0; $detaches = 0;
        foreach ($optional as $t => $kind) {
            if ($confidentiel) {
                $cols = ($t === 'cid_telephones') ? 'id, images, preuves' : 'id, images';
                $sel = $conn->prepare("SELECT $cols FROM $t WHERE dossier_id = :id");
                $sel->execute(array(':id' => $data['id']));
                $rows = $sel->fetchAll();
                foreach ($rows as $r) {
                    cid_delete_images(cid_json_images(isset($r['images']) ? $r['images'] : ''));
                    if ($t === 'cid_telephones') cid_delete_images(cid_preuve_medias(isset($r['preuves']) ? $r['preuves'] : ''));
                    $dr = $conn->prepare("DELETE FROM cid_replies WHERE entity_type = ? AND entity_id = ?");
                    $dr->execute(array($kind, $r['id']));
                }
                $purges += count($rows);
                $conn->prepare("DELETE FROM $t WHERE dossier_id = :id")->execute(array(':id' => $data['id']));
            } else {
                $up = $conn->prepare("UPDATE $t SET dossier_id = NULL WHERE dossier_id = :id");
                $up->execute(array(':id' => $data['id']));
                $detaches += $up->rowCount();
            }
        }
        $conn->prepare("DELETE FROM cid_dossiers WHERE id = :id")->execute(array(':id' => $data['id']));
        if ($confidentiel && $purges) cid_log($conn, 'purge', 'dossier', $data['id'], $oldDoss['nom'], null, array('motif' => 'categorie_restreinte', 'contenus_supprimes' => $purges));
        echo json_encode(array('success' => true, 'confidentiel' => $confidentiel, 'purges' => $purges, 'detaches' => $detaches), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'upload') {
        cid_require_access($conn);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) mdt_error(400, 'E-CID-420', 'Aucun fichier');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) mdt_error(400, 'E-CID-421', 'Erreur upload');
        $mime = '';
        if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
        else { $mime = $f['type']; }
        $isVideo = isset($CID_VIDEO_ALLOWED[$mime]);
        if (!$isVideo && !isset($CID_ALLOWED[$mime])) mdt_error(400, 'E-CID-423', 'Type non autorise (images ou videos mp4/webm/mov)');
        if ($isVideo && $f['size'] > CID_MAX_VIDEO) mdt_error(400, 'E-CID-422', 'Video trop volumineuse (max 100 Mo)');
        if (!$isVideo && $f['size'] > CID_MAX_SIZE) mdt_error(400, 'E-CID-422', 'Image trop volumineuse (max 10 Mo)');
        if (!is_dir(CID_UPLOAD_DIR)) @mkdir(CID_UPLOAD_DIR, 0775, true);
        if ($isVideo) {
            $name = 'cvid_' . uniqid() . '_' . bin2hex(random_bytes(6)) . '.' . $CID_VIDEO_ALLOWED[$mime];
            if (!@move_uploaded_file($f['tmp_name'], CID_UPLOAD_DIR . $name)) mdt_error(500, 'E-CID-424', 'Echec enregistrement');
            @chmod(CID_UPLOAD_DIR . $name, 0644);
            echo json_encode(array('success' => true, 'url' => CID_UPLOAD_URL . $name, 'type' => 'video'), JSON_UNESCAPED_UNICODE);
        } else {
            $stored = cid_store_image($f['tmp_name'], $mime);
            if (!$stored) mdt_error(500, 'E-CID-424', 'Echec enregistrement');
            echo json_encode(array('success' => true, 'url' => CID_UPLOAD_URL . $stored, 'type' => 'image'), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'list_pins') {
        cid_require_access($conn);
        $rows = $conn->query("SELECT id, titre, contenu, statut, created_at FROM cid_pins ORDER BY FIELD(statut,'important','modere','faible'), created_at DESC")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_pin') {
        mdt_post_only();
        cid_require_perm($conn, 'pin_manage');
        $data = mdt_get_post_data('E-CID-400');
        if (!$data || empty(trim((string)($data['titre'] ?? '')))) mdt_error(400, 'E-CID-400', 'Titre requis');
        $statut = (isset($data['statut']) && in_array($data['statut'], $CID_STATUTS, true)) ? $data['statut'] : 'modere';
        $titre = substr(trim($data['titre']), 0, 200);
        $contenu = isset($data['contenu']) ? $data['contenu'] : null;
        if (!empty($data['id'])) {
            $op = $conn->prepare("SELECT titre, contenu, statut FROM cid_pins WHERE id = :id"); $op->execute(array(':id' => $data['id'])); $oldPin = $op->fetch();
            $conn->prepare("UPDATE cid_pins SET titre = :t, contenu = :c, statut = :s WHERE id = :id")
                ->execute(array(':t' => $titre, ':c' => $contenu, ':s' => $statut, ':id' => $data['id']));
            cid_log($conn, 'update', 'pin', $data['id'], $titre, $oldPin ?: null, array('titre' => $titre, 'contenu' => $contenu, 'statut' => $statut));
            echo json_encode(array('success' => true, 'id' => $data['id']), JSON_UNESCAPED_UNICODE);
        } else {
            $id = uniqid('pin_');
            $conn->prepare("INSERT INTO cid_pins (id, titre, contenu, statut, created_by) VALUES (:id, :t, :c, :s, :by)")
                ->execute(array(':id' => $id, ':t' => $titre, ':c' => $contenu, ':s' => $statut, ':by' => cid_actor($conn)));
            cid_log($conn, 'create', 'pin', $id, $titre, null, array('titre' => $titre, 'contenu' => $contenu, 'statut' => $statut));
            echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'delete_pin') {
        mdt_post_only();
        cid_require_perm($conn, 'pin_manage');
        $data = mdt_get_post_data('E-CID-400');
        if (!$data || empty($data['id'])) mdt_error(400, 'E-CID-400', 'id requis');
        $op = $conn->prepare("SELECT titre, contenu, statut FROM cid_pins WHERE id = :id"); $op->execute(array(':id' => $data['id'])); $oldPin = $op->fetch();
        $conn->prepare("DELETE FROM cid_pins WHERE id = :id")->execute(array(':id' => $data['id']));
        cid_log($conn, 'delete', 'pin', $data['id'], ($oldPin ? $oldPin['titre'] : ''), $oldPin ?: null, null);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_entity') {
        cid_require_access($conn);
        $type = isset($_GET['type']) ? $_GET['type'] : '';
        if (!isset($CID_ENTITIES[$type])) mdt_error(400, 'E-CID-400', 'Type inconnu');
        $e = $CID_ENTITIES[$type];
        $did = (isset($_GET['dossier_id']) && $_GET['dossier_id']) ? $_GET['dossier_id'] : null;
        if ($e['doss'] && !$did) mdt_error(400, 'E-CID-400', 'dossier_id requis');
        $actx = cid_actx($conn);
        if ($did) {
            $dt = $conn->prepare("SELECT type_groupe FROM cid_dossiers WHERE id = :d");
            $dt->execute(array(':d' => $did));
            $dtype = $dt->fetchColumn();
            if ($dtype !== false && in_array($dtype, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
            $st = $conn->prepare("SELECT * FROM {$e['table']} WHERE dossier_id = :d ORDER BY {$e['order']}");
            $st->execute(array(':d' => $did));
        } else {
            $hiddenIds = cid_hidden_dossier_ids($conn, $actx);
            if ($hiddenIds) {
                $ph = implode(',', array_fill(0, count($hiddenIds), '?'));
                $st = $conn->prepare("SELECT * FROM {$e['table']} WHERE (dossier_id IS NULL OR dossier_id NOT IN ($ph)) ORDER BY {$e['order']}");
                $st->execute(array_values($hiddenIds));
            } else {
                $st = $conn->query("SELECT * FROM {$e['table']} ORDER BY {$e['order']}");
            }
        }
        $rows = $st->fetchAll();
        $rc = array();
        if ($rows) {
            $ids = array_map(function ($x) { return $x['id']; }, $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            try {
                $cq = $conn->prepare("SELECT entity_id, COUNT(*) AS n FROM cid_replies WHERE entity_type = ? AND entity_id IN ($ph) GROUP BY entity_id");
                $cq->execute(array_merge(array($type), array_values($ids)));
                foreach ($cq->fetchAll() as $cr) { $rc[$cr['entity_id']] = (int)$cr['n']; }
            } catch (Exception $ex) {}
        }
        foreach ($rows as &$r) {
            foreach ($e['json'] as $jf) { $r[$jf] = (isset($r[$jf]) && $r[$jf]) ? json_decode($r[$jf], true) : array(); }
            $r['reply_count'] = isset($rc[$r['id']]) ? $rc[$r['id']] : 0;
        }
        unset($r);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'counts') {
        cid_require_access($conn);
        echo json_encode(cid_entity_counts($conn, cid_actx($conn)), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'get_entity') {
        cid_require_access($conn);
        $type = isset($_GET['type']) ? $_GET['type'] : '';
        if (!isset($CID_ENTITIES[$type])) mdt_error(400, 'E-CID-400', 'Type inconnu');
        $e = $CID_ENTITIES[$type];
        $id = isset($_GET['id']) ? $_GET['id'] : '';
        if ($id === '') mdt_error(400, 'E-CID-400', 'id requis');
        $st = $conn->prepare("SELECT * FROM {$e['table']} WHERE id = :i LIMIT 1");
        $st->execute(array(':i' => $id));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-CID-404', 'Element introuvable');
        if (!empty($row['dossier_id'])) {
            $actx = cid_actx($conn);
            $dt = $conn->prepare("SELECT type_groupe FROM cid_dossiers WHERE id = :d");
            $dt->execute(array(':d' => $row['dossier_id']));
            $dtype = $dt->fetchColumn();
            if ($dtype !== false && in_array($dtype, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
        }
        foreach ($e['json'] as $jf) { $row[$jf] = (isset($row[$jf]) && $row[$jf]) ? json_decode($row[$jf], true) : array(); }
        $row['auteur'] = cid_actor_name($conn, isset($row['created_by']) ? $row['created_by'] : null);
        $row['reply_count'] = 0;
        try {
            $cq = $conn->prepare("SELECT COUNT(*) FROM cid_replies WHERE entity_type = ? AND entity_id = ?");
            $cq->execute(array($type, $id));
            $row['reply_count'] = (int)$cq->fetchColumn();
        } catch (Exception $ex) {}
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_entity') {
        mdt_post_only();
        cid_require_access($conn);
        $data = mdt_get_post_data('E-CID-400');
        $type = isset($data['type']) ? $data['type'] : '';
        if (!isset($CID_ENTITIES[$type])) mdt_error(400, 'E-CID-400', 'Type inconnu');
        $e = $CID_ENTITIES[$type];
        $editing = !empty($data['id']);
        if ($type === 'membre') cid_require_perm($conn, 'hierarchy_manage');
        else cid_require_perm($conn, $editing ? 'content_edit' : 'content_create');
        if (empty(trim((string)(isset($data[$e['label']]) ? $data[$e['label']] : '')))) mdt_error(400, 'E-CID-400', ucfirst($e['label']) . ' requis');
        $did = (isset($data['dossier_id']) && $data['dossier_id']) ? $data['dossier_id'] : null;
        if ($e['doss'] && !$did) mdt_error(400, 'E-CID-400', 'dossier_id requis');
        if ($did) {
            $actx = cid_actx($conn);
            $dt = $conn->prepare("SELECT type_groupe FROM cid_dossiers WHERE id = :d");
            $dt->execute(array(':d' => $did));
            $dtype = $dt->fetchColumn();
            if ($dtype !== false && in_array($dtype, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
        }
        $cols = array('dossier_id'); $vals = array(':dossier_id' => $did);
        foreach ($e['fields'] as $f) {
            $v = isset($data[$f]) ? $data[$f] : null;
            if ($f === 'images' && in_array($f, $e['json'], true)) {
                $imgs = is_array($v) ? array_values(array_filter(array_map('cid_safe_img', $v))) : array();
                $v = json_encode($imgs, JSON_UNESCAPED_UNICODE);
            }
            elseif ($f === 'preuves' && in_array($f, $e['json'], true)) {
                $v = json_encode(cid_safe_preuves(is_array($v) ? $v : array()), JSON_UNESCAPED_UNICODE);
            }
            elseif (in_array($f, $e['json'], true)) { $v = json_encode(is_array($v) ? $v : array(), JSON_UNESCAPED_UNICODE); }
            elseif ($f === 'statut') { $v = in_array($v, $CID_STATUTS, true) ? $v : 'modere'; }
            elseif ($f === 'epingle') { $v = !empty($v) ? 1 : 0; }
            elseif ($f === 'ordre') { $v = (int)$v; }
            else { $v = ($v === '' || $v === null) ? null : (is_string($v) ? mb_substr($v, 0, 65000) : $v); }
            $cols[] = $f; $vals[':' . $f] = $v;
        }
        if (!empty($data['id'])) {
            $oldRow = null;
            try { $q = $conn->prepare("SELECT * FROM {$e['table']} WHERE id = :id"); $q->execute(array(':id' => $data['id'])); $oldRow = $q->fetch(); } catch (Exception $ex) {}
            if ($oldRow && !empty($oldRow['dossier_id'])) {
                $srcActx = cid_actx($conn);
                $st = $conn->prepare("SELECT type_groupe FROM cid_dossiers WHERE id = :d LIMIT 1");
                $st->execute(array(':d' => $oldRow['dossier_id']));
                $stype = $st->fetchColumn();
                if ($stype !== false && in_array($stype, $srcActx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
            }
            if ($oldRow && in_array('images', $e['json'], true)) {
                $oldImgs = cid_json_images(isset($oldRow['images']) ? $oldRow['images'] : '');
                $newImgs = cid_json_images(isset($vals[':images']) ? $vals[':images'] : '');
                foreach (array_diff($oldImgs, $newImgs) as $gone) cid_delete_image($gone);
            }
            if ($oldRow && $type === 'membre' && !empty($oldRow['photo_url']) && $oldRow['photo_url'] !== (isset($vals[':photo_url']) ? $vals[':photo_url'] : null)) cid_delete_image($oldRow['photo_url']);
            if ($oldRow && $type === 'telephone') {
                $oldMed = cid_preuve_medias(isset($oldRow['preuves']) ? $oldRow['preuves'] : '');
                $newMed = cid_preuve_medias(isset($vals[':preuves']) ? $vals[':preuves'] : '');
                foreach (array_diff($oldMed, $newMed) as $gone) cid_delete_image($gone);
            }
            $set = implode(', ', array_map(function($c) { return "$c = :$c"; }, $cols));
            $vals[':id'] = $data['id'];
            $conn->prepare("UPDATE {$e['table']} SET $set WHERE id = :id")->execute($vals);
            $newRow = null;
            try { $q = $conn->prepare("SELECT * FROM {$e['table']} WHERE id = :id"); $q->execute(array(':id' => $data['id'])); $newRow = $q->fetch(); } catch (Exception $ex) {}
            cid_log($conn, 'update', $type, $data['id'], (isset($newRow[$e['label']]) ? $newRow[$e['label']] : ''), cid_entity_snapshot($e, $oldRow), cid_entity_snapshot($e, $newRow));
            echo json_encode(array('success' => true, 'id' => $data['id']), JSON_UNESCAPED_UNICODE);
        } else {
            $id = uniqid($e['prefix']);
            $allCols = array_merge(array('id'), $cols, array('created_by'));
            $vals[':id'] = $id; $vals[':created_by'] = cid_actor($conn);
            $ph = implode(', ', array_map(function($c) { return ":$c"; }, $allCols));
            $conn->prepare("INSERT INTO {$e['table']} (" . implode(', ', $allCols) . ") VALUES ($ph)")->execute($vals);
            $newRow = null;
            try { $q = $conn->prepare("SELECT * FROM {$e['table']} WHERE id = :id"); $q->execute(array(':id' => $id)); $newRow = $q->fetch(); } catch (Exception $ex) {}
            cid_log($conn, 'create', $type, $id, (isset($newRow[$e['label']]) ? $newRow[$e['label']] : ''), null, cid_entity_snapshot($e, $newRow));
            echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'delete_entity') {
        mdt_post_only();
        $data = mdt_get_post_data('E-CID-400');
        $type = isset($data['type']) ? $data['type'] : '';
        if (!isset($CID_ENTITIES[$type]) || empty($data['id'])) mdt_error(400, 'E-CID-400', 'type et id requis');
        if ($type === 'membre') cid_require_perm($conn, 'hierarchy_manage');
        else cid_require_perm($conn, 'content_delete');
        $e = $CID_ENTITIES[$type]; $oldRow = null;
        try { $q = $conn->prepare("SELECT * FROM {$e['table']} WHERE id = :id"); $q->execute(array(':id' => $data['id'])); $oldRow = $q->fetch(); } catch (Exception $ex) {}
        if ($oldRow && !empty($oldRow['dossier_id'])) {
            $actx = cid_actx($conn);
            $dt = $conn->prepare("SELECT type_groupe FROM cid_dossiers WHERE id = :d LIMIT 1");
            $dt->execute(array(':d' => $oldRow['dossier_id']));
            $dtype = $dt->fetchColumn();
            if ($dtype !== false && in_array($dtype, $actx['hidden'], true)) mdt_error(403, 'E-CID-403', 'Categorie confidentielle');
        }
        if ($oldRow) {
            if (in_array('images', $e['json'], true)) cid_delete_images(cid_json_images(isset($oldRow['images']) ? $oldRow['images'] : ''));
            if ($type === 'membre' && !empty($oldRow['photo_url'])) cid_delete_image($oldRow['photo_url']);
            if ($type === 'telephone') cid_delete_images(cid_preuve_medias(isset($oldRow['preuves']) ? $oldRow['preuves'] : ''));
        }
        $conn->prepare("DELETE FROM {$e['table']} WHERE id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM cid_replies WHERE entity_type = :t AND entity_id = :i")->execute(array(':t' => $type, ':i' => $data['id']));
        cid_log($conn, 'delete', $type, $data['id'], ($oldRow && isset($oldRow[$e['label']]) ? $oldRow[$e['label']] : ''), cid_entity_snapshot($e, $oldRow), null);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_replies') {
        cid_require_access($conn);
        $type = isset($_GET['entity_type']) ? $_GET['entity_type'] : '';
        $eid = isset($_GET['entity_id']) ? $_GET['entity_id'] : '';
        if (!isset($CID_ENTITIES[$type]) || $eid === '') mdt_error(400, 'E-CID-400', 'entity_type et entity_id requis');
        cid_reply_guard($conn, $type, $eid);
        $st = $conn->prepare("SELECT id, author_id, author_name, contenu, created_at FROM cid_replies WHERE entity_type = :t AND entity_id = :i ORDER BY id ASC");
        $st->execute(array(':t' => $type, ':i' => $eid));
        $me = cid_actor($conn);
        $rows = array_map(function ($r) use ($me) {
            $r['mine'] = ($me && $r['author_id'] === $me);
            return $r;
        }, $st->fetchAll());
        echo json_encode(array('replies' => $rows), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'add_reply') {
        mdt_post_only();
        cid_require_perm($conn, 'content_create');
        $data = mdt_get_post_data('E-CID-400');
        $type = isset($data['entity_type']) ? $data['entity_type'] : '';
        $eid = isset($data['entity_id']) ? $data['entity_id'] : '';
        $contenu = isset($data['contenu']) ? trim((string)$data['contenu']) : '';
        if (!isset($CID_ENTITIES[$type]) || $eid === '') mdt_error(400, 'E-CID-400', 'entity_type et entity_id requis');
        if ($contenu === '') mdt_error(400, 'E-CID-400', 'Message vide');
        if (mb_strlen($contenu) > 4000) $contenu = mb_substr($contenu, 0, 4000);
        $dossierId = cid_reply_guard($conn, $type, $eid);
        $did = cid_actor($conn);
        $name = cid_actor_name($conn, $did);
        $conn->prepare("INSERT INTO cid_replies (entity_type, entity_id, author_id, author_name, contenu) VALUES (:t, :i, :a, :n, :c)")
             ->execute(array(':t' => $type, ':i' => $eid, ':a' => $did, ':n' => $name, ':c' => $contenu));
        $rid = $conn->lastInsertId();

        $e = $CID_ENTITIES[$type];
        $lbl = '';
        $author = null;
        try {
            $q = $conn->prepare("SELECT " . $e['label'] . " AS lbl, created_by FROM {$e['table']} WHERE id = :i LIMIT 1");
            $q->execute(array(':i' => $eid));
            if ($row = $q->fetch()) { $lbl = $row['lbl']; $author = $row['created_by']; }
        } catch (Exception $ex) {}

        if ($author && $author !== $did) {
            $lien = $dossierId ? ('/cid/dossier/' . $dossierId . '/' . $type . 's') : '/cid/' . $type . 's';
            try {
                $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id) VALUES (:d, 'cid_reply', :ti, :co, :li, 'cid_reply', :ri)")
                     ->execute(array(':d' => $author, ':ti' => 'Réponse sur « ' . mb_substr((string)$lbl, 0, 60) . ' »', ':co' => $name . ' a répondu : ' . mb_substr($contenu, 0, 160), ':li' => $lien, ':ri' => (string)$eid));
            } catch (Exception $ex) {}
        }
        cid_log($conn, 'create', 'reply', (string)$rid, mb_substr($contenu, 0, 60), null, array('sur' => $type . ' ' . $eid));
        echo json_encode(array('success' => true, 'id' => $rid, 'author_name' => $name, 'author_id' => $did), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_reply') {
        mdt_post_only();
        cid_require_access($conn);
        $data = mdt_get_post_data('E-CID-400');
        $rid = isset($data['id']) ? $data['id'] : '';
        if ($rid === '') mdt_error(400, 'E-CID-400', 'id requis');
        $st = $conn->prepare("SELECT author_id, contenu, entity_type, entity_id FROM cid_replies WHERE id = :i LIMIT 1");
        $st->execute(array(':i' => $rid));
        $r = $st->fetch();
        if (!$r) mdt_error(404, 'E-CID-404', 'Réponse introuvable');
        cid_reply_guard($conn, $r['entity_type'], $r['entity_id']);
        $me = cid_actor($conn);
        $mine = ($me && $r['author_id'] === $me);
        if (!$mine && !cid_can($conn, 'content_delete')) mdt_error(403, 'E-CID-403', 'Permission refusee');
        $conn->prepare("DELETE FROM cid_replies WHERE id = :i")->execute(array(':i' => $rid));
        cid_log($conn, 'delete', 'reply', (string)$rid, mb_substr((string)$r['contenu'], 0, 60), null, null);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'perms_config') {
        cid_require_perm($conn, 'perms_manage');
        $grants = array();
        foreach ($conn->query("SELECT role_id, perm FROM cid_role_perms")->fetchAll() as $r) { $grants[$r['role_id']][] = $r['perm']; }
        $vis = array();
        foreach ($conn->query("SELECT type_groupe, role_id FROM cid_category_visibility")->fetchAll() as $r) { $vis[$r['type_groupe']][] = $r['role_id']; }
        echo json_encode(array(
            'perms' => $CID_PERMS,
            'types' => $CID_TYPES,
            'grants' => (object)$grants,
            'visibility' => (object)$vis,
            'guild_roles' => cid_guild_roles(),
            'cid_roles' => cid_cid_roles(),
            'superadmin_roles' => $CID_SUPERADMIN_ROLES,
            'access_role' => $CID_ACCESS_ROLES[0]
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_role_perms') {
        mdt_post_only();
        cid_require_perm($conn, 'perms_manage');
        $data = mdt_get_post_data('E-CID-400');
        $role = isset($data['role_id']) ? trim((string)$data['role_id']) : '';
        if ($role === '' || !ctype_digit($role)) mdt_error(400, 'E-CID-400', 'role_id invalide');
        $perms = (isset($data['perms']) && is_array($data['perms'])) ? $data['perms'] : array();
        $before = cid_role_perms_snap($conn, $role);
        $conn->prepare("DELETE FROM cid_role_perms WHERE role_id = :r")->execute(array(':r' => $role));
        $ins = $conn->prepare("INSERT IGNORE INTO cid_role_perms (role_id, perm) VALUES (:r, :p)");
        foreach ($perms as $p) { if (isset($CID_PERMS[$p])) $ins->execute(array(':r' => $role, ':p' => $p)); }
        cid_log($conn, 'update', 'role_perms', $role, cid_role_label($conn, $role), $before, cid_role_perms_snap($conn, $role));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'remove_role') {
        mdt_post_only();
        cid_require_perm($conn, 'perms_manage');
        $data = mdt_get_post_data('E-CID-400');
        $role = isset($data['role_id']) ? trim((string)$data['role_id']) : '';
        if ($role === '') mdt_error(400, 'E-CID-400', 'role_id requis');
        $before = cid_role_perms_snap($conn, $role);
        $conn->prepare("DELETE FROM cid_role_perms WHERE role_id = :r")->execute(array(':r' => $role));
        cid_log($conn, 'delete', 'role_perms', $role, cid_role_label($conn, $role), $before, null);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_category_visibility') {
        mdt_post_only();
        cid_require_perm($conn, 'perms_manage');
        $data = mdt_get_post_data('E-CID-400');
        $type = isset($data['type_groupe']) ? $data['type_groupe'] : '';
        if (!in_array($type, cid_type_keys($conn), true)) mdt_error(400, 'E-CID-400', 'Type inconnu');
        $roles = (isset($data['roles']) && is_array($data['roles'])) ? $data['roles'] : array();
        $before = cid_cat_vis_snap($conn, $type);
        $conn->prepare("DELETE FROM cid_category_visibility WHERE type_groupe = :t")->execute(array(':t' => $type));
        $ins = $conn->prepare("INSERT IGNORE INTO cid_category_visibility (type_groupe, role_id) VALUES (:t, :r)");
        foreach ($roles as $r) { $r = trim((string)$r); if ($r !== '' && ctype_digit($r)) $ins->execute(array(':t' => $type, ':r' => $r)); }
        cid_log($conn, 'update', 'confidentialite', $type, $type, $before, cid_cat_vis_snap($conn, $type));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_statuts') {
        cid_require_access($conn);
        $rows = $conn->query("SELECT id, titre, couleur, icone, ordre FROM cid_dossier_statuts ORDER BY ordre, titre")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_statut') {
        mdt_post_only();
        cid_require_perm($conn, 'taxonomy_manage');
        $data = mdt_get_post_data('E-CID-400');
        $titre = isset($data['titre']) ? trim((string)$data['titre']) : '';
        if ($titre === '') mdt_error(400, 'E-CID-400', 'Titre requis');
        $couleur = (isset($data['couleur']) && preg_match('/^#[0-9a-fA-F]{6}$/', $data['couleur'])) ? $data['couleur'] : '#6366f1';
        $icone = isset($data['icone']) ? preg_replace('/[^a-z0-9\- ]/i', '', (string)$data['icone']) : 'fa-flag';
        if (trim($icone) === '') $icone = 'fa-flag';
        $ordre = isset($data['ordre']) ? (int)$data['ordre'] : 0;
        if (!empty($data['id'])) {
            $conn->prepare("UPDATE cid_dossier_statuts SET titre = :t, couleur = :c, icone = :i, ordre = :o WHERE id = :id")
                ->execute(array(':t' => mb_substr($titre, 0, 100), ':c' => $couleur, ':i' => mb_substr($icone, 0, 60), ':o' => $ordre, ':id' => $data['id']));
            echo json_encode(array('success' => true, 'id' => $data['id']), JSON_UNESCAPED_UNICODE);
        } else {
            $id = uniqid('st_');
            $conn->prepare("INSERT INTO cid_dossier_statuts (id, titre, couleur, icone, ordre) VALUES (:id, :t, :c, :i, :o)")
                ->execute(array(':id' => $id, ':t' => mb_substr($titre, 0, 100), ':c' => $couleur, ':i' => mb_substr($icone, 0, 60), ':o' => $ordre));
            echo json_encode(array('success' => true, 'id' => $id), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'delete_statut') {
        mdt_post_only();
        cid_require_perm($conn, 'taxonomy_manage');
        $data = mdt_get_post_data('E-CID-400');
        if (empty($data['id'])) mdt_error(400, 'E-CID-400', 'id requis');
        $conn->prepare("UPDATE cid_dossiers SET statut_id = NULL WHERE statut_id = :id")->execute(array(':id' => $data['id']));
        $conn->prepare("DELETE FROM cid_dossier_statuts WHERE id = :id")->execute(array(':id' => $data['id']));
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_types') {
        cid_require_access($conn);
        $rows = $conn->query("SELECT id, cle, titre, couleur, icone, ordre FROM cid_types_groupe ORDER BY ordre, titre")->fetchAll();
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_type') {
        mdt_post_only();
        cid_require_perm($conn, 'taxonomy_manage');
        $data = mdt_get_post_data('E-CID-400');
        $titre = isset($data['titre']) ? trim((string)$data['titre']) : '';
        if ($titre === '') mdt_error(400, 'E-CID-400', 'Titre requis');
        $couleur = (isset($data['couleur']) && preg_match('/^#[0-9a-fA-F]{6}$/', $data['couleur'])) ? $data['couleur'] : '#64748b';
        $icone = isset($data['icone']) ? preg_replace('/[^a-z0-9\- ]/i', '', (string)$data['icone']) : 'fa-folder';
        if (trim($icone) === '') $icone = 'fa-folder';
        $ordre = isset($data['ordre']) ? (int)$data['ordre'] : 0;
        $sel = $conn->prepare("SELECT id, cle, titre, couleur, icone, ordre FROM cid_types_groupe WHERE id = :id");
        if (!empty($data['id'])) {
            $sel->execute(array(':id' => $data['id']));
            $before = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$before) mdt_error(404, 'E-CID-404', 'Type introuvable');
            $conn->prepare("UPDATE cid_types_groupe SET titre = :t, couleur = :c, icone = :i, ordre = :o WHERE id = :id")
                ->execute(array(':t' => mb_substr($titre, 0, 100), ':c' => $couleur, ':i' => mb_substr($icone, 0, 60), ':o' => $ordre, ':id' => $data['id']));
            $sel->execute(array(':id' => $data['id']));
            cid_log($conn, 'update', 'type_groupe', $data['id'], $titre, $before, $sel->fetch(PDO::FETCH_ASSOC));
            echo json_encode(array('success' => true, 'id' => $data['id']), JSON_UNESCAPED_UNICODE);
        } else {
            $cle = cid_type_slug($titre);
            if ($cle === '') mdt_error(400, 'E-CID-400', 'Titre invalide (aucun caractere exploitable pour la cle)');
            $taken = $conn->prepare("SELECT COUNT(*) FROM cid_types_groupe WHERE cle = :c");
            $base = mb_substr($cle, 0, 27);
            $n = 1;
            $taken->execute(array(':c' => $cle));
            while ((int)$taken->fetchColumn() > 0) {
                $n++;
                if ($n > 99) mdt_error(409, 'E-CID-409', 'Impossible de generer une cle unique pour ce titre');
                $cle = $base . '_' . $n;
                $taken->execute(array(':c' => $cle));
            }
            $id = uniqid('typ_');
            $conn->prepare("INSERT INTO cid_types_groupe (id, cle, titre, couleur, icone, ordre) VALUES (:id, :cl, :t, :c, :i, :o)")
                ->execute(array(':id' => $id, ':cl' => $cle, ':t' => mb_substr($titre, 0, 100), ':c' => $couleur, ':i' => mb_substr($icone, 0, 60), ':o' => $ordre));
            $sel->execute(array(':id' => $id));
            cid_log($conn, 'create', 'type_groupe', $id, $titre, null, $sel->fetch(PDO::FETCH_ASSOC));
            echo json_encode(array('success' => true, 'id' => $id, 'cle' => $cle), JSON_UNESCAPED_UNICODE);
        }
    }

    elseif ($action === 'delete_type') {
        mdt_post_only();
        cid_require_perm($conn, 'taxonomy_manage');
        $data = mdt_get_post_data('E-CID-400');
        if (empty($data['id'])) mdt_error(400, 'E-CID-400', 'id requis');
        $sel = $conn->prepare("SELECT id, cle, titre, couleur, icone, ordre FROM cid_types_groupe WHERE id = :id");
        $sel->execute(array(':id' => $data['id']));
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) mdt_error(404, 'E-CID-404', 'Type introuvable');
        $used = $conn->prepare("SELECT COUNT(*) FROM cid_dossiers WHERE type_groupe = :c");
        $used->execute(array(':c' => $row['cle']));
        $n = (int)$used->fetchColumn();
        if ($n > 0) mdt_error(409, 'E-CID-409', $n . ' dossier' . ($n > 1 ? 's utilisent' : ' utilise') . ' encore ce type. Change leur type avant de le supprimer.');
        $conn->prepare("DELETE FROM cid_types_groupe WHERE id = :id")->execute(array(':id' => $data['id']));
        cid_log($conn, 'delete', 'type_groupe', $row['id'], $row['titre'], $row, null);
        echo json_encode(array('success' => true), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_logs') {
        cid_require_perm($conn, 'logs_view');
        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;
        $tf = isset($_GET['entity_type']) ? $_GET['entity_type'] : '';
        if ($tf !== '') { $st = $conn->prepare("SELECT * FROM cid_logs WHERE entity_type = :t ORDER BY id DESC LIMIT $limit"); $st->execute(array(':t' => $tf)); $rows = $st->fetchAll(); }
        else { $rows = $conn->query("SELECT * FROM cid_logs ORDER BY id DESC LIMIT $limit")->fetchAll(); }
        foreach ($rows as &$r) {
            $r['before'] = $r['before_json'] ? json_decode($r['before_json'], true) : null;
            $r['after'] = $r['after_json'] ? json_decode($r['after_json'], true) : null;
            unset($r['before_json'], $r['after_json']);
        }
        unset($r);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    else {
        mdt_unknown_action();
    }

} catch (Exception $e) {
    mdt_error(500, 'E-CID-500', 'Erreur serveur', $e->getMessage());
}
