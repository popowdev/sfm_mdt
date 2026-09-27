<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('DOC_SCHEMA_VERSION', '1');
define('DOC_MODULE_KEY', 'documents');
define('DOC_SEARCH_LIMIT', 50);
define('DOC_QUERY_MAX', 100);
define('DOC_ALIAS_LIMIT', 300);
define('DOC_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

define('DOC_UPLOAD_DIR', __DIR__ . '/uploads/');
define('DOC_UPLOAD_URL', '/documents/uploads/');
define('DOC_MAX_IMG', 10 * 1024 * 1024);
define('DOC_MAX_PDF', 25 * 1024 * 1024);
define('DOC_MAX_BUR', 25 * 1024 * 1024);
define('DOC_MAX_VID', 100 * 1024 * 1024);
define('DOC_MAX_IMG_PX', 4000);
define('DOC_MIN_DISK_FREE', 2 * 1024 * 1024 * 1024);
define('DOC_QUOTA_FILES', 20);
define('DOC_QUOTA_BYTES', 300 * 1024 * 1024);
define('DOC_HIER_MAX', 300);
define('DOC_TITLE_MAX', 200);
define('DOC_CONTENT_MAX', 200000);

$DOC_IMG = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp');
$DOC_VID = array('video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov');
$DOC_PDF = array('application/pdf' => 'pdf');
$DOC_BUR = array(
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/msword'              => 'doc',
    'application/vnd.ms-excel'        => 'xls',
    'application/vnd.ms-powerpoint'   => 'ppt',
);

$DOC_SUPERADMIN_ROLES = array();

$DOC_PERMS = array(
    'access'           => 'Acces au module Documentation',
    'division_manage'  => 'Creer / renommer / supprimer une division',
    'categorie_manage' => 'Creer / renommer / supprimer une categorie',
    'categorie_move'   => 'Deplacer une categorie d\'une division a l\'autre',
    'doc_create'       => 'Creer un document',
    'doc_edit'         => 'Modifier un document',
    'doc_delete'       => 'Supprimer un document',
    'pin_manage'       => 'Epingler un document',
    'acl_manage'       => 'Gerer la confidentialite',
    'logs_view'        => 'Consulter le journal',
);

function doc_gstr($k) {
    return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : '';
}

function doc_ctx_roles($conn, $did) {
    static $cache = array();
    if (!$did) return array();
    if (isset($cache[$did])) return $cache[$did];
    $roles = array();
    try {
        $r = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = :d LIMIT 1");
        $r->execute(array(':d' => $did));
        $raw = $r->fetchColumn();
        $decoded = $raw ? json_decode($raw, true) : array();
        if (is_array($decoded)) $roles = array_map('strval', $decoded);
    } catch (Exception $e) {}
    $cache[$did] = $roles;
    return $roles;
}

function doc_is_superadmin($conn, $did, $roles) {
    global $DOC_SUPERADMIN_ROLES;
    if (!$did) return false;
    if (function_exists('mdt_is_owner') && mdt_is_owner($did)) return true;
    try {
        $q = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = :d LIMIT 1");
        $q->execute(array(':d' => $did));
        if ($q->fetch()) return true;
    } catch (Exception $e) {}
    $admin = function_exists('mdt_admin_roles') ? mdt_admin_roles() : array();
    foreach (array_merge($admin, $DOC_SUPERADMIN_ROLES) as $r) {
        if (in_array((string)$r, $roles, true)) return true;
    }
    return false;
}

function doc_require_schema($conn) {
    $q = $conn->prepare("SELECT v FROM doc_meta WHERE k = 'schema_version' LIMIT 1");
    $q->execute();
    $v = $q->fetchColumn();
    if ((string)$v !== (string)DOC_SCHEMA_VERSION) {
        mdt_error(503, 'E-DOC-503', 'Module indisponible (schema)', 'schema_version=' . var_export($v, true));
    }
}

function doc_actx($conn, $did) {
    static $ctx = null;
    if ($ctx !== null) return $ctx;

    $roles = doc_ctx_roles($conn, $did);
    $super = doc_is_superadmin($conn, $did, $roles);

    $q = $conn->prepare("SELECT role_id FROM module_permissions WHERE module_key = :mk");
    $q->execute(array(':mk' => DOC_MODULE_KEY));
    $required = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    $access = $super || (count($required) > 0 && count(array_intersect($roles, $required)) > 0);

    $perms = array();
    if ($super) {
        global $DOC_PERMS;
        foreach ($DOC_PERMS as $k => $v) $perms[$k] = true;
    } elseif ($access && $roles) {
        $ph = implode(',', array_fill(0, count($roles), '?'));
        $st = $conn->prepare("SELECT DISTINCT perm FROM doc_role_perms WHERE role_id IN ($ph)");
        $st->execute(array_values($roles));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $perms[$p] = true;
    }
    $perms['access'] = $access;

    if (!$access) {
        $ctx = array('did' => $did, 'roles' => $roles, 'super' => false, 'access' => false,
            'perms' => $perms, 'divs_visibles' => array(), 'cats_visibles' => array(),
            'whitelists' => array('division' => array(), 'categorie' => array()), '_divs' => array(), '_cats' => array());
        return $ctx;
    }

    $divs = $conn->query("SELECT id, slug, titre, description, icone, couleur, visibilite, kind, ordre, archive FROM doc_divisions ORDER BY ordre, titre")->fetchAll();
    $cats = $conn->query("SELECT id, division_id, slug, titre, description, icone, visibilite, ordre, archive FROM doc_categories ORDER BY ordre, titre")->fetchAll();

    $wl = array('division' => array(), 'categorie' => array());
    $vq = $conn->query("SELECT scope, scope_id, GROUP_CONCAT(role_id) AS roles FROM doc_visibility GROUP BY scope, scope_id");
    foreach ($vq->fetchAll() as $row) {
        if (!isset($wl[$row['scope']])) continue;
        $wl[$row['scope']][(string)$row['scope_id']] = array_map('strval', explode(',', (string)$row['roles']));
    }

    $divVis = array();
    $catVis = array();
    foreach ($divs as $d) {
        if ((int)$d['archive']) continue;
        $key = (string)$d['id'];
        $ok = $super || $d['visibilite'] === 'ouvert'
            || (isset($wl['division'][$key]) && count(array_intersect($roles, $wl['division'][$key])) > 0);
        if ($ok) $divVis[$key] = true;
    }
    foreach ($cats as $c) {
        if ((int)$c['archive']) continue;
        if (!isset($divVis[(string)$c['division_id']])) continue;
        $key = (string)$c['id'];
        $ok = $super || $c['visibilite'] === 'ouvert'
            || (isset($wl['categorie'][$key]) && count(array_intersect($roles, $wl['categorie'][$key])) > 0);
        if ($ok) $catVis[$key] = true;
    }

    $ctx = array(
        'did'           => $did,
        'roles'         => $roles,
        'super'         => $super,
        'access'        => true,
        'perms'         => $perms,
        'divs_visibles' => array_keys($divVis),
        'cats_visibles' => array_keys($catVis),
        'whitelists'    => $wl,
        '_divs'         => $divs,
        '_cats'         => $cats,
    );
    return $ctx;
}

function doc_can($actx, $perm) {
    return !empty($actx['super']) || !empty($actx['perms'][$perm]);
}

function doc_require_access($actx) {
    if (empty($actx['access'])) mdt_error(403, 'E-DOC-403', 'Acces reserve a la Documentation');
}

function doc_require_perm($actx, $perm) {
    doc_require_access($actx);
    if (!doc_can($actx, $perm)) mdt_error(403, 'E-DOC-403', 'Droit requis : ' . $perm);
}

function doc_visible_sql($actx, $col) {
    if (!empty($actx['super'])) return array('', array());
    if (empty($actx['cats_visibles'])) return array(' AND 1=0', array());
    $ph = implode(',', array_fill(0, count($actx['cats_visibles']), '?'));
    return array(" AND $col IN ($ph)", array_map('strval', array_values($actx['cats_visibles'])));
}

function doc_div_visible($actx, $id) {
    return !empty($actx['super']) || in_array((string)$id, array_map('strval', $actx['divs_visibles']), true);
}

function doc_cat_visible($actx, $id) {
    return !empty($actx['super']) || in_array((string)$id, array_map('strval', $actx['cats_visibles']), true);
}

function doc_scope_manageable($actx, $conn, $scope, $id) {
    if (!empty($actx['super'])) return true;
    $visible = ($scope === 'division') ? doc_div_visible($actx, $id) : doc_cat_visible($actx, $id);
    if ($visible) return true;
    $tbl = ($scope === 'division') ? 'doc_divisions' : 'doc_categories';
    $q = $conn->prepare("SELECT created_by FROM $tbl WHERE id = ? LIMIT 1");
    $q->execute(array((string)$id));
    $row = $q->fetch();
    if (!$row) return false;
    return ((string)$row['created_by'] !== '' && (string)$row['created_by'] === (string)$actx['did']);
}

function doc_hier_safe_photo($url) {
    $url = trim((string)$url);
    if ($url === '') return null;
    return preg_match('#^/documents/uploads/[A-Za-z0-9._-]+$#', $url) ? $url : null;
}

function doc_hier_is_descendant($conn, $divId, $startId, $ancestorId) {
    $st = $conn->prepare("SELECT parent_id FROM doc_hierarchie WHERE id = ? AND division_id = ? LIMIT 1");
    $seen = array();
    $cur = (string)$startId;
    for ($i = 0; $i < DOC_HIER_MAX + 5 && $cur !== ''; $i++) {
        if ($cur === (string)$ancestorId) return true;
        if (isset($seen[$cur])) break;
        $seen[$cur] = true;
        $st->execute(array($cur, $divId));
        $cur = (string)($st->fetchColumn() ?: '');
    }
    return false;
}

function doc_draft_clause($actx, &$args) {
    if (!empty($actx['super']) || doc_can($actx, 'doc_edit')) return '';
    $args[] = (string)$actx['did'];
    return " AND (statut = 'publie' OR created_by = ?)";
}

function doc_extrait($html, $len = 300) {
    $t = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return mb_substr($t, 0, $len);
}

function doc_file_url($f) {
    $name = $f['stored_name'];
    if ($f['kind'] === 'image' || $f['kind'] === 'video') return '/documents/uploads/' . $name;
    return '/documents/documents_api.php?action=file&id=' . (int)$f['id'];
}

function doc_slugify($s, $max = 90) {
    $map = array(
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae'
    );
    $s = function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    $s = trim(mb_substr($s, 0, $max), '-');
    if ($s === '' || preg_match('/^[0-9]+$/', $s)) $s = 'doc-' . bin2hex(random_bytes(3));
    return $s;
}
function doc_alias_key($path) {
    $p = (string)$path;
    return (mb_strlen($p) <= 191) ? $p : '#h:' . sha1($p);
}

function doc_slug_variant($base, $n) {
    if ($n <= 1) return $base;
    if ($n <= 30) return $base . '-' . $n;
    return $base . '-' . bin2hex(random_bytes(3));
}

$DOC_RICH_TAGS = array('p','br','strong','b','em','i','u','s','h1','h2','h3','h4','ul','ol','li',
    'blockquote','code','pre','a','img','table','thead','tbody','tr','th','td','hr','span','div');
$DOC_RICH_DROP = array('script','style','svg','math','template','iframe','object','embed','link',
    'meta','form','input','button','textarea','select','noscript','base','frame','frameset','audio','video','source');

function doc_safe_href($u) {
    $u = preg_replace('/[\x00-\x20\x7f]+/', '', (string)$u);
    if (preg_match('#^https?://#i', $u)) return $u;
    if (preg_match('#^mailto:[^\s<>"\']+$#i', $u)) return $u;
    if ($u !== '' && $u[0] === '/' && (strlen($u) < 2 || $u[1] !== '/')) return $u;
    if ($u !== '' && $u[0] === '#') return $u;
    return '';
}
function doc_safe_img_src($u) {
    $u = preg_replace('/[\x00-\x20\x7f]+/', '', (string)$u);
    if (preg_match('#^/documents/uploads/[A-Za-z0-9._-]+$#', $u)) return $u;
    if (preg_match('#^https://[^\s<>"\'\\\\]+$#i', $u)) return $u;
    return '';
}
function doc_sanitize_html($html) {
    global $DOC_RICH_TAGS, $DOC_RICH_DROP;
    $html = (string)$html;
    if (trim($html) === '') return '';
    $dom = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $wrapped = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html . '</body>';
    $dom->loadHTML($wrapped, LIBXML_NONET | (defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0));
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) return '';

    $allow = array_flip($DOC_RICH_TAGS);
    $drop = array_flip($DOC_RICH_DROP);
    $nodes = array();
    $strip = array();
    $walker = function ($node) use (&$walker, &$nodes, &$strip) {
        for ($c = $node->firstChild; $c; $c = $c->nextSibling) {
            if ($c->nodeType === XML_ELEMENT_NODE) { $nodes[] = $c; $walker($c); }
            elseif ($c->nodeType === XML_COMMENT_NODE || $c->nodeType === XML_PI_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) { $strip[] = $c; }
        }
    };
    $walker($body);
    foreach ($strip as $sn) { if ($sn->parentNode) $sn->parentNode->removeChild($sn); }

    foreach ($nodes as $el) {
        if (!$el->parentNode) continue;
        $tag = strtolower($el->nodeName);
        if (isset($drop[$tag])) { $el->parentNode->removeChild($el); continue; }
        if (!isset($allow[$tag])) {
            while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el);
            $el->parentNode->removeChild($el);
            continue;
        }
        if ($el->hasAttributes()) {
            $attrs = array();
            foreach ($el->attributes as $a) $attrs[] = $a->nodeName;
            foreach ($attrs as $an) {
                $anl = strtolower($an);
                $av = $el->getAttribute($an);
                $keep = false; $newv = null;
                if ($tag === 'a' && $anl === 'href') { $u = doc_safe_href($av); if ($u !== '') { $keep = true; $newv = $u; } }
                elseif ($tag === 'img' && $anl === 'src') { $u = doc_safe_img_src($av); if ($u !== '') { $keep = true; $newv = $u; } }
                elseif ($tag === 'img' && ($anl === 'alt' || $anl === 'title')) { $keep = true; $newv = $av; }
                elseif (($tag === 'td' || $tag === 'th') && ($anl === 'colspan' || $anl === 'rowspan')) { if (preg_match('/^[0-9]{1,3}$/', $av)) { $keep = true; $newv = $av; } }
                if ($keep) { $el->setAttribute($an, $newv); }
                else { $el->removeAttribute($an); }
            }
        }
        if ($tag === 'a' && $el->getAttribute('href') !== '') {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    $out = '';
    for ($c = $body->firstChild; $c; $c = $c->nextSibling) $out .= $dom->saveHTML($c);
    return trim($out);
}

function doc_upload_classify($mime, $ext) {
    global $DOC_IMG, $DOC_VID, $DOC_PDF, $DOC_BUR;
    if (isset($DOC_IMG[$mime])) return array('image', $DOC_IMG[$mime]);
    if (isset($DOC_VID[$mime])) return array('video', $DOC_VID[$mime]);
    if (isset($DOC_PDF[$mime])) return array('pdf', $DOC_PDF[$mime]);
    if (isset($DOC_BUR[$mime])) return array('bureau', $DOC_BUR[$mime]);
    if ($mime === 'application/zip' || $mime === 'application/octet-stream') {
        $e = strtolower($ext);
        $ooxml = array('docx' => array('word/'), 'xlsx' => array('xl/'), 'pptx' => array('ppt/'));
        if (isset($ooxml[$e])) return array('bureau', $e, 'zipcheck', $ooxml[$e][0]);
        if (in_array($e, array('doc', 'xls', 'ppt'), true)) return array('bureau', $e, 'ole2');
    }
    return null;
}
function doc_zip_has_prefix($za, $prefix) {
    for ($i = 0; $i < $za->numFiles; $i++) {
        $n = $za->getNameIndex($i);
        if ($n !== false && strpos($n, $prefix) === 0) return true;
    }
    return false;
}
function doc_store_image($tmp, $mime) {
    global $DOC_IMG;
    $ext = isset($DOC_IMG[$mime]) ? $DOC_IMG[$mime] : 'bin';
    $base = 'dimg_' . uniqid() . '_' . bin2hex(random_bytes(6));
    $dim = @getimagesize($tmp);
    if ($dim && ($dim[0] > DOC_MAX_IMG_PX || $dim[1] > DOC_MAX_IMG_PX)) return array(false, 'E-DOC-425', 'Image trop grande (max ' . DOC_MAX_IMG_PX . 'px)');
    if ($mime === 'image/gif' || !function_exists('imagecreatetruecolor')) {
        $name = $base . '.' . $ext;
        return @move_uploaded_file($tmp, DOC_UPLOAD_DIR . $name) ? array($name, 'gif' === $ext ? 'image/gif' : $mime) : array(false, 'E-DOC-424', 'Echec');
    }
    $src = null;
    if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($tmp);
    elseif ($mime === 'image/png') $src = @imagecreatefrompng($tmp);
    elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($tmp);
    if (!$src) return array(false, 'E-DOC-424', 'Image illisible');
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
    $name = $base . '.webp';
    $ok = function_exists('imagewebp') ? @imagewebp($src, DOC_UPLOAD_DIR . $name, 80) : false;
    $outMime = 'image/webp';
    if (!$ok) { $name = $base . '.jpg'; $ok = @imagejpeg($src, DOC_UPLOAD_DIR . $name, 85); $outMime = 'image/jpeg'; }
    imagedestroy($src);
    if (!$ok) return array(false, 'E-DOC-424', 'Echec encodage');
    return array($name, $outMime);
}
function doc_delete_stored($name) {
    if (!is_string($name) || !preg_match('#^[A-Za-z0-9._-]+$#', $name)) return;
    $p = DOC_UPLOAD_DIR . $name;
    if (is_file($p)) @unlink($p);
}
function doc_actor_name($conn, $did) {
    if (!$did) return 'Inconnu';
    try {
        $s = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username) FROM users WHERE discord_id = :d LIMIT 1");
        $s->execute(array(':d' => $did));
        $n = $s->fetchColumn();
        return $n ? $n : $did;
    } catch (Exception $e) { return $did; }
}
function doc_log($conn, $actx, $action, $type, $id, $catId, $label, $before, $after) {
    try {
        $st = $conn->prepare("INSERT INTO doc_logs (action, entity_type, entity_id, categorie_id, label, actor_id, actor_name, before_json, after_json)
                              VALUES (:a,:t,:i,:c,:l,:aid,:an,:b,:af)");
        $st->execute(array(
            ':a' => $action, ':t' => $type, ':i' => $id, ':c' => $catId, ':l' => mb_substr((string)$label, 0, 255),
            ':aid' => $actx['did'], ':an' => doc_actor_name($conn, $actx['did']),
            ':b' => $before ? json_encode($before, DOC_JSON) : null,
            ':af' => $after ? json_encode($after, DOC_JSON) : null,
        ));
    } catch (Exception $e) {}
}
function doc_content_digest($html) {
    return array('len' => mb_strlen((string)$html), 'sha1' => sha1((string)$html));
}

function doc_reserved_slug($s) {
    static $r = array('gestion', 'recherche', 'uploads', 'api', 'assets', 'admin', 'index', 'new', 'edit', 'documents');
    return in_array($s, $r, true);
}
function doc_safe_couleur($c) { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? $c : '#10b981'; }
function doc_safe_icone($i) { return preg_match('/^fa-[a-z0-9-]{1,40}$/', (string)$i) ? $i : ''; }
function doc_safe_vis($v) { return ($v === 'ouvert') ? 'ouvert' : 'restreint'; }

function doc_gen_div_slug($conn, $titre, $exceptId = null) {
    $base = doc_slugify($titre, 70);
    for ($n = 1; $n <= 200; $n++) {
        $c = doc_slug_variant($base, $n);
        if (doc_reserved_slug($c)) continue;
        $q = $conn->prepare("SELECT 1 FROM doc_divisions WHERE slug = ? AND id <> ? LIMIT 1");
        $q->execute(array($c, (string)$exceptId));
        if ($q->fetch()) continue;
        $q = $conn->prepare("SELECT 1 FROM doc_path_alias WHERE old_path = ? LIMIT 1");
        $q->execute(array($c));
        if ($q->fetch()) continue;
        return $c;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}
function doc_gen_cat_slug($conn, $divId, $divSlug, $titre, $exceptId = null) {
    $base = doc_slugify($titre, 70);
    for ($n = 1; $n <= 200; $n++) {
        $c = doc_slug_variant($base, $n);
        $q = $conn->prepare("SELECT 1 FROM doc_categories WHERE division_id = ? AND slug = ? AND id <> ? LIMIT 1");
        $q->execute(array($divId, $c, (string)$exceptId));
        if ($q->fetch()) continue;
        $q = $conn->prepare("SELECT 1 FROM doc_path_alias WHERE old_path = ? LIMIT 1");
        $q->execute(array($divSlug . '/' . $c));
        if ($q->fetch()) continue;
        return $c;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}
function doc_add_alias($conn, $oldPath, $kind, $targetId) {
    try {
        $conn->prepare("INSERT IGNORE INTO doc_path_alias (old_path, kind, target_id) VALUES (?,?,?)")
            ->execute(array(doc_alias_key($oldPath), $kind, (string)$targetId));
    } catch (Exception $e) {}
}
function doc_alias_category_tree($conn, $oldCatPath, $catId) {
    doc_add_alias($conn, $oldCatPath, 'categorie', $catId);
    $dq = $conn->prepare("SELECT id, slug FROM doc_documents WHERE categorie_id = ?");
    $dq->execute(array($catId));
    foreach ($dq->fetchAll() as $d) doc_add_alias($conn, $oldCatPath . '/' . $d['slug'], 'document', $d['id']);
}
function doc_alias_division_tree($conn, $oldDivSlug, $divId) {
    doc_add_alias($conn, $oldDivSlug, 'division', $divId);
    $cq = $conn->prepare("SELECT id, slug FROM doc_categories WHERE division_id = ?");
    $cq->execute(array($divId));
    foreach ($cq->fetchAll() as $c) doc_alias_category_tree($conn, $oldDivSlug . '/' . $c['slug'], $c['id']);
}
function doc_guild_roles($conn) {
    try {
        $rows = $conn->query("SELECT role_id, name, color, position FROM discord_roles_cache ORDER BY position DESC")->fetchAll();
        $out = array();
        foreach ($rows as $r) $out[] = array('id' => $r['role_id'], 'name' => $r['name'] ?: $r['role_id'], 'color' => $r['color'] ?: '#99aab5', 'position' => (int)$r['position']);
        return $out;
    } catch (Exception $e) { return array(); }
}
function doc_vis_snap($conn, $scope, $scopeId) {
    $st = $conn->prepare("SELECT visibilite FROM " . ($scope === 'division' ? 'doc_divisions' : 'doc_categories') . " WHERE id = ?");
    $st->execute(array($scopeId));
    $vis = $st->fetchColumn();
    $rq = $conn->prepare("SELECT role_id FROM doc_visibility WHERE scope = ? AND scope_id = ? ORDER BY role_id");
    $rq->execute(array($scope, $scopeId));
    return array('visibilite' => $vis, 'roles' => $rq->fetchAll(PDO::FETCH_COLUMN));
}

try {
    $me = mdt_require_auth($conn, 'documents');

    mdt_require_module($conn, 'documents', 'documents_api');
    $did = isset($me['discord_id']) ? $me['discord_id'] : null;
    header('Cache-Control: no-store');
    doc_require_schema($conn);

    $action = doc_gstr('action');
    $KNOWN = array('bootstrap', 'get', 'search', 'resolve', 'file', 'doc_save', 'doc_delete', 'doc_pin', 'upload', 'gc',
        'division_save', 'division_delete', 'categorie_save', 'categorie_delete', 'categorie_move',
        'acl_config', 'acl_set', 'open_scope', 'perms_save', 'logs',
        'hierarchie_list', 'hierarchie_save', 'hierarchie_delete', 'hierarchie_photo');
    if (!in_array($action, $KNOWN, true)) mdt_error(400, 'E-DOC-400', 'Action inconnue');

    $actx = doc_actx($conn, $did);

    if ($action === 'bootstrap') {
        $out = array(
            'access'         => (bool)$actx['access'],
            'super'          => (bool)$actx['super'],
            'schema_version' => DOC_SCHEMA_VERSION,
        );
        if (!$actx['access']) { echo json_encode($out, DOC_JSON); exit; }
        $out['perms'] = $actx['perms'];

        $visDiv = array_flip(array_map('strval', $actx['divs_visibles']));
        $visCat = array_flip(array_map('strval', $actx['cats_visibles']));

        $divisions = array();
        foreach ($actx['_divs'] as $d) {
            if (!isset($visDiv[(string)$d['id']])) continue;
            $d['ordre'] = (int)$d['ordre'];
            $d['archive'] = (int)$d['archive'];
            $d['nb_categories'] = 0;
            $divisions[(string)$d['id']] = $d;
        }
        $categories = array();
        foreach ($actx['_cats'] as $c) {
            if (!isset($visCat[(string)$c['id']])) continue;
            if (!isset($divisions[(string)$c['division_id']])) continue;
            $c['ordre'] = (int)$c['ordre'];
            $c['archive'] = (int)$c['archive'];
            $c['nb_documents'] = 0;
            $categories[(string)$c['id']] = $c;
            $divisions[(string)$c['division_id']]['nb_categories']++;
        }

        $docs = array();
        if ($categories) {
            list($vsql, $args) = doc_visible_sql($actx, 'categorie_id');
            $draft = doc_draft_clause($actx, $args);
            $sql = "SELECT id, categorie_id, slug, titre, extrait, statut, epingle, ordre, created_by, created_at, updated_at
                    FROM doc_documents WHERE 1=1" . $vsql . $draft . " ORDER BY epingle DESC, ordre, titre";
            $st = $conn->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll() as $r) {
                if (!isset($categories[(string)$r['categorie_id']])) continue;
                $r['epingle'] = (int)$r['epingle'];
                $r['ordre'] = (int)$r['ordre'];
                $docs[] = $r;
                $categories[(string)$r['categorie_id']]['nb_documents']++;
            }
        }

        $out['divisions'] = array_values($divisions);
        $out['categories'] = array_values($categories);
        $out['documents'] = $docs;

        if (doc_can($actx, 'acl_manage')) {
            $warnings = array();
            foreach ($actx['_divs'] as $d) {
                if ((int)$d['archive'] || !isset($visDiv[(string)$d['id']])) continue;
                if ($d['visibilite'] === 'restreint' && empty($actx['whitelists']['division'][(string)$d['id']])) {
                    $warnings[] = array('code' => 'ferme_a_personne', 'scope' => 'division', 'id' => $d['id'], 'titre' => $d['titre']);
                }
            }
            foreach ($actx['_cats'] as $c) {
                if ((int)$c['archive'] || !isset($visCat[(string)$c['id']])) continue;
                if ($c['visibilite'] === 'restreint' && empty($actx['whitelists']['categorie'][(string)$c['id']])) {
                    $warnings[] = array('code' => 'ferme_a_personne', 'scope' => 'categorie', 'id' => $c['id'], 'titre' => $c['titre']);
                }
            }
            if (count($docs) >= 300) $warnings[] = array('code' => 'volumetrie', 'documents' => count($docs));
            $out['warnings'] = $warnings;
        }

        echo json_encode($out, DOC_JSON);
    }

    elseif ($action === 'get') {
        doc_require_access($actx);
        $id = doc_gstr('id');
        if ($id === '') mdt_error(400, 'E-DOC-400', 'id requis');

        list($vsql, $args) = doc_visible_sql($actx, 'categorie_id');
        array_unshift($args, $id);
        $sql = "SELECT id, categorie_id, slug, titre, contenu, extrait, statut, epingle, ordre,
                       created_by, created_at, updated_by, updated_at
                FROM doc_documents WHERE id = ?" . $vsql . " LIMIT 1";
        $st = $conn->prepare($sql);
        $st->execute($args);
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-DOC-404', 'Document introuvable');

        if ($row['statut'] === 'brouillon' && empty($actx['super'])
            && !doc_can($actx, 'doc_edit')
            && (string)$row['created_by'] !== (string)$actx['did']) {
            mdt_error(404, 'E-DOC-404', 'Document introuvable');
        }

        $row['epingle'] = (int)$row['epingle'];
        $row['ordre'] = (int)$row['ordre'];

        $fq = $conn->prepare("SELECT id, kind, stored_name, original_name, mime, ext, taille, ordre
                              FROM doc_files WHERE doc_id = :d AND deleted_at IS NULL ORDER BY kind, ordre, id");
        $fq->execute(array(':d' => $row['id']));
        $files = $fq->fetchAll();
        foreach ($files as &$f) {
            $f['taille'] = (int)$f['taille'];
            $f['ordre'] = (int)$f['ordre'];
            $f['url'] = doc_file_url($f);
        }
        unset($f);
        $row['files'] = $files;

        $cat = null; $div = null;
        foreach ($actx['_cats'] as $c) { if ((string)$c['id'] === (string)$row['categorie_id']) { $cat = $c; break; } }
        if ($cat) { foreach ($actx['_divs'] as $d) { if ((string)$d['id'] === (string)$cat['division_id']) { $div = $d; break; } } }
        $row['categorie'] = $cat ? array('id' => $cat['id'], 'slug' => $cat['slug'], 'titre' => $cat['titre']) : null;
        $row['division'] = $div ? array('id' => $div['id'], 'slug' => $div['slug'], 'titre' => $div['titre']) : null;

        echo json_encode($row, DOC_JSON);
    }

    elseif ($action === 'search') {
        doc_require_access($actx);
        $q = trim(doc_gstr('q'));
        $q = mb_substr($q, 0, DOC_QUERY_MAX);
        if (mb_strlen($q) < 2) { echo json_encode(array('q' => $q, 'results' => array()), DOC_JSON); exit; }
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $args = array($like, $like, $like);
        list($vsql, $vargs) = doc_visible_sql($actx, 'categorie_id');
        $args = array_merge($args, $vargs);
        $draft = doc_draft_clause($actx, $args);
        $sql = "SELECT id, categorie_id, slug, titre, extrait, statut, updated_at
                FROM doc_documents
                WHERE (titre LIKE ? OR extrait LIKE ? OR contenu LIKE ?)" . $vsql . $draft . "
                ORDER BY updated_at DESC LIMIT " . (int)DOC_SEARCH_LIMIT;
        $st = $conn->prepare($sql);
        $st->execute($args);

        $results = array();
        foreach ($st->fetchAll() as $r) {
            $results[] = array(
                'id'           => $r['id'],
                'categorie_id' => $r['categorie_id'],
                'slug'         => $r['slug'],
                'titre'        => $r['titre'],
                'extrait'      => ($r['extrait'] !== null && $r['extrait'] !== '') ? $r['extrait'] : '',
                'statut'       => $r['statut'],
                'updated_at'   => $r['updated_at'],
            );
        }
        echo json_encode(array('q' => $q, 'results' => $results), DOC_JSON);
    }

    elseif ($action === 'resolve') {
        doc_require_access($actx);
        $path = trim(doc_gstr('path'), '/');
        if ($path === '') mdt_error(404, 'E-DOC-404', 'Introuvable');

        $st = $conn->prepare("SELECT kind, target_id FROM doc_path_alias WHERE old_path = :p LIMIT 1");
        $st->execute(array(':p' => doc_alias_key($path)));
        $a = $st->fetch();
        if (!$a) mdt_error(404, 'E-DOC-404', 'Introuvable');

        $ok = false;
        if ($a['kind'] === 'division') $ok = doc_div_visible($actx, $a['target_id']);
        elseif ($a['kind'] === 'categorie') $ok = doc_cat_visible($actx, $a['target_id']);
        elseif ($a['kind'] === 'document') {
            $dq = $conn->prepare("SELECT categorie_id, statut, created_by FROM doc_documents WHERE id = :i LIMIT 1");
            $dq->execute(array(':i' => $a['target_id']));
            $dr = $dq->fetch();
            $ok = $dr && doc_cat_visible($actx, $dr['categorie_id'])
                && !($dr['statut'] === 'brouillon' && empty($actx['super']) && !doc_can($actx, 'doc_edit') && (string)$dr['created_by'] !== (string)$actx['did']);
        }
        if (!$ok) mdt_error(404, 'E-DOC-404', 'Introuvable');

        echo json_encode(array('kind' => $a['kind'], 'id' => $a['target_id']), DOC_JSON);
    }

    elseif ($action === 'file') {
        doc_require_access($actx);
        $id = (int)doc_gstr('id');
        if ($id <= 0) mdt_error(400, 'E-DOC-400', 'id requis');
        $st = $conn->prepare("SELECT f.stored_name, f.kind, f.mime, f.original_name, f.doc_id, d.categorie_id, d.statut, d.created_by
                              FROM doc_files f JOIN doc_documents d ON d.id = f.doc_id
                              WHERE f.id = :i AND f.deleted_at IS NULL LIMIT 1");
        $st->execute(array(':i' => $id));
        $row = $st->fetch();
        if (!$row || !doc_cat_visible($actx, $row['categorie_id'])) mdt_error(404, 'E-DOC-404', 'Fichier introuvable');
        if ($row['statut'] === 'brouillon' && empty($actx['super'])
            && !doc_can($actx, 'doc_edit')
            && (string)$row['created_by'] !== (string)$actx['did']) {
            mdt_error(404, 'E-DOC-404', 'Fichier introuvable');
        }
        if (!preg_match('#^[A-Za-z0-9._-]+$#', (string)$row['stored_name'])) mdt_error(404, 'E-DOC-404', 'Fichier introuvable');
        header('Content-Type: ' . $row['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        $fn = preg_replace('/[\x00-\x1f\x22\x5c\x7f]/', '', (string)$row['original_name']);
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('X-Accel-Redirect: /documents/uploads/' . $row['stored_name']);
        exit;
    }

    elseif ($action === 'upload') {
        mdt_post_only();
        $editing = doc_can($actx, 'doc_edit');
        if (!doc_can($actx, 'doc_create') && !$editing) mdt_error(403, 'E-DOC-403', 'Droit requis : doc_create');
        $catId = doc_gstr('categorie_id');
        if ($catId === '' || !doc_cat_visible($actx, $catId)) mdt_error(404, 'E-DOC-404', 'Categorie introuvable');
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) mdt_error(400, 'E-DOC-420', 'Aucun fichier');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) mdt_error(400, 'E-DOC-421', 'Erreur upload');

        if (@disk_free_space(DOC_UPLOAD_DIR) < DOC_MIN_DISK_FREE + (int)$f['size']) mdt_error(507, 'E-DOC-507', 'Stockage insuffisant');
        $cnt = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(taille),0) AS b FROM doc_files WHERE uploaded_by = :u AND doc_id IS NULL AND deleted_at IS NULL");
        $cnt->execute(array(':u' => (string)$actx['did']));
        $qr = $cnt->fetch();
        if ((int)$qr['n'] >= DOC_QUOTA_FILES || (int)$qr['b'] + (int)$f['size'] > DOC_QUOTA_BYTES) mdt_error(429, 'E-DOC-429', 'Quota de fichiers en attente atteint');

        if (!function_exists('finfo_open')) mdt_error(500, 'E-DOC-500', 'finfo indisponible');
        $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
        if ($mime === 'image/svg+xml' || $mime === 'text/html' || $mime === 'text/xml' || $mime === 'application/xml')
            mdt_error(400, 'E-DOC-423', 'Type non autorise (SVG et HTML interdits)');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $cls = doc_upload_classify($mime, $ext);
        if (!$cls) mdt_error(400, 'E-DOC-423', 'Type non autorise (PDF, image, video, bureautique)');
        $kind = $cls[0]; $fext = $cls[1];

        if ($kind === 'bureau' && isset($cls[2]) && $cls[2] === 'zipcheck') {
            if (!class_exists('ZipArchive')) mdt_error(400, 'E-DOC-423', 'Verification impossible');
            $za = new ZipArchive();
            if ($za->open($f['tmp_name']) !== true) mdt_error(400, 'E-DOC-423', 'Archive invalide');
            $ok = ($za->locateName('[Content_Types].xml') !== false) && ($za->locateName($cls[3], ZipArchive::FL_NODIR | ZipArchive::FL_NOCASE) !== false || doc_zip_has_prefix($za, $cls[3]));
            $za->close();
            if (!$ok) mdt_error(400, 'E-DOC-423', 'Fichier bureautique invalide');
        }
        if ($kind === 'bureau' && isset($cls[2]) && $cls[2] === 'ole2') {
            $magic = @file_get_contents($f['tmp_name'], false, null, 0, 8);
            if ($magic !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") mdt_error(400, 'E-DOC-423', 'Fichier bureautique invalide');
        }

        $limits = array('image' => DOC_MAX_IMG, 'video' => DOC_MAX_VID, 'pdf' => DOC_MAX_PDF, 'bureau' => DOC_MAX_BUR);
        if ((int)$f['size'] > $limits[$kind]) mdt_error(400, 'E-DOC-422', 'Fichier trop volumineux');

        if (!is_dir(DOC_UPLOAD_DIR)) @mkdir(DOC_UPLOAD_DIR, 0775, true);
        $storeMime = $mime;
        if ($kind === 'image') {
            $res = doc_store_image($f['tmp_name'], $mime);
            if (!$res[0]) mdt_error(500, $res[1], $res[2]);
            $stored = $res[0]; $storeMime = $res[1]; $fext = pathinfo($stored, PATHINFO_EXTENSION);
        } else {
            $prefix = ($kind === 'video') ? 'dvid_' : (($kind === 'pdf') ? 'dpdf_' : 'dbur_');
            $stored = $prefix . uniqid() . '_' . bin2hex(random_bytes(6)) . '.' . $fext;
            if (!@move_uploaded_file($f['tmp_name'], DOC_UPLOAD_DIR . $stored)) mdt_error(500, 'E-DOC-424', 'Echec enregistrement');
        }
        @chmod(DOC_UPLOAD_DIR . $stored, 0644);

        $orig = preg_replace('/[\x00-\x1f\x7f\/\\\\]+/', ' ', (string)$f['name']);
        $orig = mb_substr(trim($orig), 0, 160);
        try {
            $ins = $conn->prepare("INSERT INTO doc_files (doc_id, kind, stored_name, original_name, mime, ext, taille, uploaded_by)
                                   VALUES (NULL, :k, :s, :o, :m, :e, :t, :u)");
            $ins->execute(array(':k' => $kind, ':s' => $stored, ':o' => $orig, ':m' => $storeMime, ':e' => $fext, ':t' => (int)@filesize(DOC_UPLOAD_DIR . $stored), ':u' => (string)$actx['did']));
            $fileId = (int)$conn->lastInsertId();
        } catch (Exception $e) { doc_delete_stored($stored); mdt_error(500, 'E-DOC-424', 'Echec enregistrement'); }

        echo json_encode(array('success' => true, 'id' => $fileId, 'kind' => $kind,
            'url' => doc_file_url(array('kind' => $kind, 'stored_name' => $stored, 'id' => $fileId)),
            'original_name' => $orig, 'taille' => (int)@filesize(DOC_UPLOAD_DIR . $stored)), DOC_JSON);
    }

    elseif ($action === 'doc_save') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        $editing = !empty($data['id']);
        $catId = isset($data['categorie_id']) ? (string)$data['categorie_id'] : '';
        $titre = trim((string)(isset($data['titre']) ? $data['titre'] : ''));
        if ($titre === '') mdt_error(400, 'E-DOC-400', 'Titre requis');
        $titre = mb_substr($titre, 0, DOC_TITLE_MAX);
        if (mb_strlen((string)(isset($data['contenu']) ? $data['contenu'] : '')) > DOC_CONTENT_MAX) mdt_error(400, 'E-DOC-400', 'Contenu trop long');

        doc_require_perm($actx, $editing ? 'doc_edit' : 'doc_create');
        if ($catId === '' || !doc_cat_visible($actx, $catId)) mdt_error(404, 'E-DOC-404', 'Categorie introuvable');

        $contenu = doc_sanitize_html(isset($data['contenu']) ? $data['contenu'] : '');
        $extrait = doc_extrait($contenu, 300);
        $statut = (isset($data['statut']) && $data['statut'] === 'brouillon') ? 'brouillon' : 'publie';
        $old = null;

        if ($editing) {
            $q = $conn->prepare("SELECT * FROM doc_documents WHERE id = :i LIMIT 1");
            $q->execute(array(':i' => $data['id']));
            $old = $q->fetch();
            if (!$old || !doc_cat_visible($actx, $old['categorie_id'])) mdt_error(404, 'E-DOC-404', 'Document introuvable');
            $slugBase = mb_substr((string)$old['slug'], 0, 80);
            for ($n = 1; ; $n++) {
                $slug = doc_slug_variant($slugBase, $n);
                try {
                    $conn->prepare("UPDATE doc_documents SET categorie_id=:c, slug=:sl, titre=:t, contenu=:co, extrait=:e, statut=:s, updated_by=:u, updated_at=NOW() WHERE id=:i")
                        ->execute(array(':c' => $catId, ':sl' => $slug, ':t' => $titre, ':co' => $contenu, ':e' => $extrait, ':s' => $statut, ':u' => (string)$actx['did'], ':i' => $data['id']));
                    break;
                } catch (PDOException $ex) {
                    if (($ex->errorInfo[1] ?? 0) !== 1062 || $n > 60) throw $ex;
                }
            }
            $docId = $data['id'];
            doc_log($conn, $actx, 'update', 'document', $docId, $catId, $titre,
                array('titre' => $old['titre'], 'categorie_id' => $old['categorie_id'], 'contenu' => doc_content_digest($old['contenu'])),
                array('titre' => $titre, 'categorie_id' => $catId, 'contenu' => doc_content_digest($contenu)));
        } else {
            $docId = uniqid('doc_');
            $base = doc_slugify($titre, 80);
            for ($n = 1; ; $n++) {
                $slug = doc_slug_variant($base, $n);
                try {
                    $conn->prepare("INSERT INTO doc_documents (id, categorie_id, slug, titre, contenu, extrait, statut, created_by, updated_by)
                                    VALUES (:id,:c,:sl,:t,:co,:e,:s,:cb,:ub)")
                        ->execute(array(':id' => $docId, ':c' => $catId, ':sl' => $slug, ':t' => $titre, ':co' => $contenu, ':e' => $extrait, ':s' => $statut, ':cb' => (string)$actx['did'], ':ub' => (string)$actx['did']));
                    break;
                } catch (PDOException $ex) {
                    if (($ex->errorInfo[1] ?? 0) !== 1062 || $n > 60) throw $ex;
                }
            }
            doc_log($conn, $actx, 'create', 'document', $docId, $catId, $titre, null,
                array('titre' => $titre, 'categorie_id' => $catId, 'contenu' => doc_content_digest($contenu)));
        }

        $attach = (isset($data['file_ids']) && is_array($data['file_ids'])) ? $data['file_ids'] : array();
        $attach = array_slice(array_values(array_filter(array_map('intval', $attach))), 0, DOC_QUOTA_FILES);
        if ($attach) {
            $ph = implode(',', array_fill(0, count($attach), '?'));
            $up = $conn->prepare("UPDATE doc_files SET doc_id = ? WHERE id IN ($ph) AND doc_id IS NULL AND uploaded_by = ?");
            $up->execute(array_merge(array($docId), array_values($attach), array((string)$actx['did'])));
        }
        $remove = (isset($data['remove_file_ids']) && is_array($data['remove_file_ids'])) ? array_slice(array_values(array_filter(array_map('intval', $data['remove_file_ids']))), 0, 200) : array();
        if ($remove && $editing) {
            $ph = implode(',', array_fill(0, count($remove), '?'));
            $up = $conn->prepare("UPDATE doc_files SET deleted_at = NOW() WHERE id IN ($ph) AND doc_id = ? AND deleted_at IS NULL");
            $up->execute(array_merge(array_values($remove), array($docId)));
        }

        echo json_encode(array('success' => true, 'id' => $docId, 'slug' => (isset($slug) ? $slug : ($old ? $old['slug'] : null))), DOC_JSON);
    }

    elseif ($action === 'doc_delete') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        if (empty($data['id'])) mdt_error(400, 'E-DOC-400', 'id requis');
        doc_require_perm($actx, 'doc_delete');
        $q = $conn->prepare("SELECT * FROM doc_documents WHERE id = :i LIMIT 1");
        $q->execute(array(':i' => $data['id']));
        $row = $q->fetch();
        if (!$row || !doc_cat_visible($actx, $row['categorie_id'])) mdt_error(404, 'E-DOC-404', 'Document introuvable');
        $fq = $conn->prepare("SELECT stored_name FROM doc_files WHERE doc_id = :d");
        $fq->execute(array(':d' => $data['id']));
        $storedNames = $fq->fetchAll(PDO::FETCH_COLUMN);
        $conn->prepare("DELETE FROM doc_documents WHERE id = :i")->execute(array(':i' => $data['id']));
        foreach ($storedNames as $sn) doc_delete_stored($sn);
        doc_log($conn, $actx, 'delete', 'document', $data['id'], $row['categorie_id'], $row['titre'],
            array('titre' => $row['titre'], 'categorie_id' => $row['categorie_id'], 'contenu' => doc_content_digest($row['contenu'])), null);
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'doc_pin') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        if (empty($data['id'])) mdt_error(400, 'E-DOC-400', 'id requis');
        doc_require_perm($actx, 'pin_manage');
        $q = $conn->prepare("SELECT categorie_id, titre, epingle FROM doc_documents WHERE id = :i LIMIT 1");
        $q->execute(array(':i' => $data['id']));
        $row = $q->fetch();
        if (!$row || !doc_cat_visible($actx, $row['categorie_id'])) mdt_error(404, 'E-DOC-404', 'Document introuvable');
        $val = !empty($data['epingle']) ? 1 : 0;
        $conn->prepare("UPDATE doc_documents SET epingle = :e WHERE id = :i")->execute(array(':e' => $val, ':i' => $data['id']));
        doc_log($conn, $actx, $val ? 'pin' : 'unpin', 'document', $data['id'], $row['categorie_id'], $row['titre'], null, null);
        echo json_encode(array('success' => true, 'epingle' => $val), DOC_JSON);
    }

    elseif ($action === 'gc') {
        mdt_post_only();
        if (empty($actx['super'])) mdt_error(403, 'E-DOC-403', 'Reserve aux administrateurs');
        $gone = 0;
        $sel = $conn->query("SELECT id, stored_name FROM doc_files WHERE (doc_id IS NULL AND created_at < NOW() - INTERVAL 24 HOUR) OR (deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 30 DAY)");
        foreach ($sel->fetchAll() as $r) { doc_delete_stored($r['stored_name']); $conn->prepare("DELETE FROM doc_files WHERE id = :i")->execute(array(':i' => $r['id'])); $gone++; }
        echo json_encode(array('success' => true, 'supprimes' => $gone), DOC_JSON);
    }

    elseif ($action === 'division_save') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'division_manage');
        $editing = !empty($data['id']);
        $titre = trim((string)(isset($data['titre']) ? $data['titre'] : ''));
        if ($titre === '') mdt_error(400, 'E-DOC-400', 'Titre requis');
        $titre = mb_substr($titre, 0, 120);
        $desc = mb_substr(trim((string)(isset($data['description']) ? $data['description'] : '')), 0, 255);
        $icone = doc_safe_icone(isset($data['icone']) ? $data['icone'] : '') ?: 'fa-folder';
        $couleur = doc_safe_couleur(isset($data['couleur']) ? $data['couleur'] : '');
        $ordre = max(0, min(2147483647, (int)(isset($data['ordre']) ? $data['ordre'] : 0)));

        if ($editing) {
            $q = $conn->prepare("SELECT * FROM doc_divisions WHERE id = :i LIMIT 1");
            $q->execute(array(':i' => $data['id']));
            $old = $q->fetch();
            if (!$old || !doc_div_visible($actx, $old['id'])) mdt_error(404, 'E-DOC-404', 'Division introuvable');
            $slug = $old['slug'];
            $newSlug = isset($data['slug']) ? doc_slugify((string)$data['slug'], 70) : '';
            if ($newSlug !== '' && $newSlug !== $old['slug'] && !doc_reserved_slug($newSlug)) {
                doc_alias_division_tree($conn, $old['slug'], $old['id']);
                $slug = doc_gen_div_slug($conn, (string)$data['slug'], $old['id']);
            }
            $conn->prepare("UPDATE doc_divisions SET titre=:t, slug=:sl, description=:d, icone=:ic, couleur=:co, ordre=:o, updated_at=NOW() WHERE id=:i")
                ->execute(array(':t' => $titre, ':sl' => $slug, ':d' => $desc ?: null, ':ic' => $icone, ':co' => $couleur, ':o' => $ordre, ':i' => $old['id']));
            doc_log($conn, $actx, 'update', 'division', $old['id'], null, $titre, array('titre' => $old['titre'], 'slug' => $old['slug']), array('titre' => $titre, 'slug' => $slug));
            echo json_encode(array('success' => true, 'id' => $old['id'], 'slug' => $slug), DOC_JSON);
        } else {
            $vis = doc_safe_vis(isset($data['visibilite']) ? $data['visibilite'] : 'restreint');
            $id = uniqid('div_');
            $slug = doc_gen_div_slug($conn, $titre);
            for ($n = 0; ; $n++) {
                try {
                    $conn->prepare("INSERT INTO doc_divisions (id, slug, titre, description, icone, couleur, visibilite, ordre, created_by)
                                    VALUES (:id,:sl,:t,:d,:ic,:co,:v,:o,:cb)")
                        ->execute(array(':id' => $id, ':sl' => $slug, ':t' => $titre, ':d' => $desc ?: null, ':ic' => $icone, ':co' => $couleur, ':v' => $vis, ':o' => $ordre, ':cb' => (string)$actx['did']));
                    break;
                } catch (PDOException $ex) { if (($ex->errorInfo[1] ?? 0) !== 1062 || $n > 5) throw $ex; $slug = doc_gen_div_slug($conn, $titre); }
            }
            doc_log($conn, $actx, 'create', 'division', $id, null, $titre, null, array('titre' => $titre, 'slug' => $slug, 'visibilite' => $vis));
            echo json_encode(array('success' => true, 'id' => $id, 'slug' => $slug), DOC_JSON);
        }
    }

    elseif ($action === 'division_delete') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data) || empty($data['id'])) mdt_error(400, 'E-DOC-400', 'id requis');
        doc_require_perm($actx, 'division_manage');
        $q = $conn->prepare("SELECT * FROM doc_divisions WHERE id = :i LIMIT 1");
        $q->execute(array(':i' => $data['id']));
        $old = $q->fetch();
        if (!$old || !doc_div_visible($actx, $old['id'])) mdt_error(404, 'E-DOC-404', 'Division introuvable');
        $cc = $conn->prepare("SELECT COUNT(*) FROM doc_categories WHERE division_id = :i");
        $cc->execute(array(':i' => $data['id']));
        if ((int)$cc->fetchColumn() > 0) mdt_error(409, 'E-DOC-409', 'Division non vide : supprime d\'abord ses categories');
        $conn->prepare("DELETE FROM doc_divisions WHERE id = :i")->execute(array(':i' => $data['id']));
        doc_log($conn, $actx, 'delete', 'division', $data['id'], null, $old['titre'], array('titre' => $old['titre'], 'slug' => $old['slug']), null);
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'categorie_save') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'categorie_manage');
        $editing = !empty($data['id']);
        $titre = trim((string)(isset($data['titre']) ? $data['titre'] : ''));
        if ($titre === '') mdt_error(400, 'E-DOC-400', 'Titre requis');
        $titre = mb_substr($titre, 0, 120);
        $desc = mb_substr(trim((string)(isset($data['description']) ? $data['description'] : '')), 0, 255);
        $icone = doc_safe_icone(isset($data['icone']) ? $data['icone'] : '') ?: 'fa-folder-open';
        $ordre = max(0, min(2147483647, (int)(isset($data['ordre']) ? $data['ordre'] : 0)));

        if ($editing) {
            $q = $conn->prepare("SELECT * FROM doc_categories WHERE id = :i LIMIT 1");
            $q->execute(array(':i' => $data['id']));
            $old = $q->fetch();
            if (!$old || !doc_cat_visible($actx, $old['id'])) mdt_error(404, 'E-DOC-404', 'Categorie introuvable');
            $div = null; foreach ($actx['_divs'] as $d) { if ((string)$d['id'] === (string)$old['division_id']) { $div = $d; break; } }
            $divSlug = $div ? $div['slug'] : '';
            $slug = $old['slug'];
            $newSlug = isset($data['slug']) ? doc_slugify((string)$data['slug'], 70) : '';
            if ($newSlug !== '' && $newSlug !== $old['slug']) {
                doc_alias_category_tree($conn, $divSlug . '/' . $old['slug'], $old['id']);
                $slug = doc_gen_cat_slug($conn, $old['division_id'], $divSlug, (string)$data['slug'], $old['id']);
            }
            $conn->prepare("UPDATE doc_categories SET titre=:t, slug=:sl, description=:d, icone=:ic, ordre=:o, updated_at=NOW() WHERE id=:i")
                ->execute(array(':t' => $titre, ':sl' => $slug, ':d' => $desc ?: null, ':ic' => $icone, ':o' => $ordre, ':i' => $old['id']));
            doc_log($conn, $actx, 'update', 'categorie', $old['id'], $old['id'], $titre, array('titre' => $old['titre'], 'slug' => $old['slug']), array('titre' => $titre, 'slug' => $slug));
            echo json_encode(array('success' => true, 'id' => $old['id'], 'slug' => $slug), DOC_JSON);
        } else {
            $divId = (string)(isset($data['division_id']) ? $data['division_id'] : '');
            if ($divId === '' || !doc_div_visible($actx, $divId)) mdt_error(404, 'E-DOC-404', 'Division introuvable');
            $div = null; foreach ($actx['_divs'] as $d) { if ((string)$d['id'] === $divId) { $div = $d; break; } }
            $divSlug = $div ? $div['slug'] : '';
            $vis = doc_safe_vis(isset($data['visibilite']) ? $data['visibilite'] : 'restreint');
            $id = uniqid('cat_');
            $slug = doc_gen_cat_slug($conn, $divId, $divSlug, $titre);
            for ($n = 0; ; $n++) {
                try {
                    $conn->prepare("INSERT INTO doc_categories (id, division_id, slug, titre, description, icone, visibilite, ordre, created_by)
                                    VALUES (:id,:dv,:sl,:t,:d,:ic,:v,:o,:cb)")
                        ->execute(array(':id' => $id, ':dv' => $divId, ':sl' => $slug, ':t' => $titre, ':d' => $desc ?: null, ':ic' => $icone, ':v' => $vis, ':o' => $ordre, ':cb' => (string)$actx['did']));
                    break;
                } catch (PDOException $ex) { if (($ex->errorInfo[1] ?? 0) !== 1062 || $n > 5) throw $ex; $slug = doc_gen_cat_slug($conn, $divId, $divSlug, $titre); }
            }
            doc_log($conn, $actx, 'create', 'categorie', $id, $id, $titre, null, array('titre' => $titre, 'slug' => $slug, 'division_id' => $divId, 'visibilite' => $vis));
            echo json_encode(array('success' => true, 'id' => $id, 'slug' => $slug), DOC_JSON);
        }
    }

    elseif ($action === 'categorie_delete') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data) || empty($data['id'])) mdt_error(400, 'E-DOC-400', 'id requis');
        doc_require_perm($actx, 'categorie_manage');
        $q = $conn->prepare("SELECT * FROM doc_categories WHERE id = :i LIMIT 1");
        $q->execute(array(':i' => $data['id']));
        $old = $q->fetch();
        if (!$old || !doc_cat_visible($actx, $old['id'])) mdt_error(404, 'E-DOC-404', 'Categorie introuvable');
        $dc = $conn->prepare("SELECT COUNT(*) FROM doc_documents WHERE categorie_id = :i");
        $dc->execute(array(':i' => $data['id']));
        if ((int)$dc->fetchColumn() > 0) mdt_error(409, 'E-DOC-409', 'Categorie non vide : supprime ou deplace d\'abord ses documents');
        $conn->prepare("DELETE FROM doc_categories WHERE id = :i")->execute(array(':i' => $data['id']));
        doc_log($conn, $actx, 'delete', 'categorie', $data['id'], $data['id'], $old['titre'], array('titre' => $old['titre'], 'slug' => $old['slug']), null);
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'categorie_move') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'categorie_move');
        $catId = (string)(isset($data['id']) ? $data['id'] : '');
        $destDivId = (string)(isset($data['division_id']) ? $data['division_id'] : '');
        if ($catId === '' || $destDivId === '') mdt_error(400, 'E-DOC-400', 'id et division_id requis');
        $q = $conn->prepare("SELECT * FROM doc_categories WHERE id = :i LIMIT 1");
        $q->execute(array(':i' => $catId));
        $old = $q->fetch();
        if (!$old || !doc_cat_visible($actx, $old['id'])) mdt_error(404, 'E-DOC-404', 'Categorie introuvable');
        if (!doc_div_visible($actx, $old['division_id']) || !doc_div_visible($actx, $destDivId)) mdt_error(404, 'E-DOC-404', 'Division introuvable');
        if ((string)$old['division_id'] === $destDivId) { echo json_encode(array('success' => true, 'id' => $catId, 'slug' => $old['slug']), DOC_JSON); exit; }
        $srcDiv = null; $dstDiv = null;
        foreach ($actx['_divs'] as $d) { if ((string)$d['id'] === (string)$old['division_id']) $srcDiv = $d; if ((string)$d['id'] === $destDivId) $dstDiv = $d; }
        if (!$dstDiv) mdt_error(404, 'E-DOC-404', 'Division introuvable');
        doc_alias_category_tree($conn, ($srcDiv ? $srcDiv['slug'] : '') . '/' . $old['slug'], $old['id']);
        $newSlug = doc_gen_cat_slug($conn, $destDivId, $dstDiv['slug'], $old['titre'], $old['id']);
        $conn->prepare("UPDATE doc_categories SET division_id=:dv, slug=:sl, updated_at=NOW() WHERE id=:i")
            ->execute(array(':dv' => $destDivId, ':sl' => $newSlug, ':i' => $catId));
        doc_log($conn, $actx, 'move', 'categorie', $catId, $catId, $old['titre'],
            array('division_id' => $old['division_id'], 'slug' => $old['slug']), array('division_id' => $destDivId, 'slug' => $newSlug));
        echo json_encode(array('success' => true, 'id' => $catId, 'slug' => $newSlug), DOC_JSON);
    }

    elseif ($action === 'acl_config') {
        header('Cache-Control: no-store');
        doc_require_perm($actx, 'acl_manage');
        $visDiv = array_flip(array_map('strval', $actx['divs_visibles']));
        $visCat = array_flip(array_map('strval', $actx['cats_visibles']));
        $divisions = array();
        foreach ($actx['_divs'] as $d) { if (!(int)$d['archive'] && isset($visDiv[(string)$d['id']])) $divisions[] = array('id' => $d['id'], 'titre' => $d['titre'], 'slug' => $d['slug'], 'visibilite' => $d['visibilite']); }
        $categories = array();
        foreach ($actx['_cats'] as $c) { if (!(int)$c['archive'] && isset($visCat[(string)$c['id']]) && isset($visDiv[(string)$c['division_id']])) $categories[] = array('id' => $c['id'], 'division_id' => $c['division_id'], 'titre' => $c['titre'], 'slug' => $c['slug'], 'visibilite' => $c['visibilite']); }
        $wl = array('division' => (object)array(), 'categorie' => (object)array());
        $wq = $conn->query("SELECT scope, scope_id, GROUP_CONCAT(role_id) AS roles FROM doc_visibility GROUP BY scope, scope_id");
        $wlarr = array('division' => array(), 'categorie' => array());
        foreach ($wq->fetchAll() as $r) {
            if ($r['scope'] === 'division' && !isset($visDiv[(string)$r['scope_id']])) continue;
            if ($r['scope'] === 'categorie' && !isset($visCat[(string)$r['scope_id']])) continue;
            if (isset($wlarr[$r['scope']])) $wlarr[$r['scope']][(string)$r['scope_id']] = explode(',', (string)$r['roles']);
        }
        $out = array(
            'divisions' => $divisions, 'categories' => $categories,
            'whitelists' => array('division' => (object)$wlarr['division'], 'categorie' => (object)$wlarr['categorie']),
            'guild_roles' => doc_guild_roles($conn),
            'can_perms' => !empty($actx['super']),
        );
        if (!empty($actx['super'])) {
            global $DOC_PERMS;
            $grants = array();
            foreach ($conn->query("SELECT role_id, perm FROM doc_role_perms")->fetchAll() as $g) $grants[(string)$g['role_id']][] = $g['perm'];
            $out['perms_catalog'] = $DOC_PERMS;
            $out['grants'] = (object)$grants;
        }
        echo json_encode($out, DOC_JSON);
    }

    elseif ($action === 'acl_set') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'acl_manage');
        $scope = (isset($data['scope']) && $data['scope'] === 'division') ? 'division' : 'categorie';
        $scopeId = (string)(isset($data['scope_id']) ? $data['scope_id'] : '');
        if ($scopeId === '') mdt_error(404, 'E-DOC-404', 'Element introuvable');
        $tbl = ($scope === 'division') ? 'doc_divisions' : 'doc_categories';
        $ex = $conn->prepare("SELECT 1 FROM $tbl WHERE id = ? LIMIT 1");
        $ex->execute(array($scopeId));
        if (!$ex->fetch()) mdt_error(404, 'E-DOC-404', 'Element introuvable');
        if (!doc_scope_manageable($actx, $conn, $scope, $scopeId)) mdt_error(404, 'E-DOC-404', 'Element introuvable');
        $roles = array();
        if (isset($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $r) { $r = trim((string)$r); if ($r !== '' && ctype_digit($r)) $roles[$r] = true; }
        }
        $roles = array_keys($roles);
        if (!$roles) mdt_error(409, 'E-DOC-409', 'Une liste vide fermerait a tous. Utilise « Rendre ouvert » a la place.');
        $before = doc_vis_snap($conn, $scope, $scopeId);
        $tbl = ($scope === 'division') ? 'doc_divisions' : 'doc_categories';
        $conn->prepare("UPDATE $tbl SET visibilite = 'restreint', updated_at = NOW() WHERE id = :i")->execute(array(':i' => $scopeId));
        $conn->prepare("DELETE FROM doc_visibility WHERE scope = :s AND scope_id = :i")->execute(array(':s' => $scope, ':i' => $scopeId));
        $ins = $conn->prepare("INSERT IGNORE INTO doc_visibility (scope, scope_id, role_id, added_by) VALUES (:s,:i,:r,:a)");
        foreach ($roles as $r) $ins->execute(array(':s' => $scope, ':i' => $scopeId, ':r' => $r, ':a' => (string)$actx['did']));
        doc_log($conn, $actx, 'acl_set', $scope, $scopeId, ($scope === 'categorie' ? $scopeId : null), $scopeId, $before, doc_vis_snap($conn, $scope, $scopeId));
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'open_scope') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'acl_manage');
        $scope = (isset($data['scope']) && $data['scope'] === 'division') ? 'division' : 'categorie';
        $scopeId = (string)(isset($data['scope_id']) ? $data['scope_id'] : '');
        if ($scopeId === '') mdt_error(404, 'E-DOC-404', 'Element introuvable');
        $tbl = ($scope === 'division') ? 'doc_divisions' : 'doc_categories';
        $ex = $conn->prepare("SELECT 1 FROM $tbl WHERE id = ? LIMIT 1");
        $ex->execute(array($scopeId));
        if (!$ex->fetch()) mdt_error(404, 'E-DOC-404', 'Element introuvable');
        if (!doc_scope_manageable($actx, $conn, $scope, $scopeId)) mdt_error(404, 'E-DOC-404', 'Element introuvable');
        $before = doc_vis_snap($conn, $scope, $scopeId);
        $conn->prepare("UPDATE $tbl SET visibilite = 'ouvert', updated_at = NOW() WHERE id = :i")->execute(array(':i' => $scopeId));
        doc_log($conn, $actx, 'open_scope', $scope, $scopeId, ($scope === 'categorie' ? $scopeId : null), $scopeId, $before, doc_vis_snap($conn, $scope, $scopeId));
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'hierarchie_list') {
        doc_require_access($actx);
        $divId = doc_gstr('division_id');
        if ($divId === '' || !doc_div_visible($actx, $divId)) mdt_error(404, 'E-DOC-404', 'Division introuvable');
        $st = $conn->prepare("SELECT id, division_id, nom, grade, emploi, telephone, photo_url, parent_id, ordre FROM doc_hierarchie WHERE division_id = ? ORDER BY ordre, nom");
        $st->execute(array($divId));
        echo json_encode(array('success' => true, 'membres' => $st->fetchAll()), DOC_JSON);
    }

    elseif ($action === 'hierarchie_save') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'division_manage');
        $divId = (string)(isset($data['division_id']) ? $data['division_id'] : '');
        if ($divId === '' || !doc_div_visible($actx, $divId)) mdt_error(404, 'E-DOC-404', 'Division introuvable');
        $exd = $conn->prepare("SELECT 1 FROM doc_divisions WHERE id = ? LIMIT 1"); $exd->execute(array($divId));
        if (!$exd->fetch()) mdt_error(404, 'E-DOC-404', 'Division introuvable');

        $nom = mb_substr(trim((string)(isset($data['nom']) ? $data['nom'] : '')), 0, 120);
        if ($nom === '') mdt_error(400, 'E-DOC-400', 'Nom requis');
        $grade  = mb_substr(trim((string)(isset($data['grade']) ? $data['grade'] : '')), 0, 120);
        $emploi = mb_substr(trim((string)(isset($data['emploi']) ? $data['emploi'] : '')), 0, 150);
        $tel    = mb_substr(trim((string)(isset($data['telephone']) ? $data['telephone'] : '')), 0, 30);
        $ordre  = (int)(isset($data['ordre']) ? $data['ordre'] : 0);
        $photo  = doc_hier_safe_photo(isset($data['photo_url']) ? $data['photo_url'] : null);
        $id     = trim((string)(isset($data['id']) ? $data['id'] : ''));
        $parent = trim((string)(isset($data['parent_id']) ? $data['parent_id'] : ''));

        if ($parent !== '') {
            if ($parent === $id) mdt_error(400, 'E-DOC-400', 'Un membre ne peut pas etre son propre superieur');
            $pc = $conn->prepare("SELECT 1 FROM doc_hierarchie WHERE id = ? AND division_id = ? LIMIT 1");
            $pc->execute(array($parent, $divId));
            if (!$pc->fetch()) mdt_error(400, 'E-DOC-400', 'Superieur invalide');
        } else { $parent = null; }

        if ($id !== '') {
            $ck = $conn->prepare("SELECT division_id FROM doc_hierarchie WHERE id = ? LIMIT 1"); $ck->execute(array($id));
            $old = $ck->fetch();
            if (!$old || (string)$old['division_id'] !== $divId) mdt_error(404, 'E-DOC-404', 'Membre introuvable');
            if ($parent !== null && doc_hier_is_descendant($conn, $divId, $parent, $id)) mdt_error(400, 'E-DOC-400', 'Hierarchie circulaire interdite');
            $conn->prepare("UPDATE doc_hierarchie SET nom=:n, grade=:g, emploi=:e, telephone=:t, photo_url=:p, parent_id=:pa, ordre=:o WHERE id=:i")
                ->execute(array(':n'=>$nom, ':g'=>$grade ?: null, ':e'=>$emploi ?: null, ':t'=>$tel ?: null, ':p'=>$photo, ':pa'=>$parent, ':o'=>$ordre, ':i'=>$id));
            doc_log($conn, $actx, 'update', 'membre', $id, null, $nom, null, null);
            echo json_encode(array('success' => true, 'id' => $id), DOC_JSON);
        } else {
            $n = (int)$conn->query("SELECT COUNT(*) FROM doc_hierarchie WHERE division_id = " . $conn->quote($divId))->fetchColumn();
            if ($n >= DOC_HIER_MAX) mdt_error(429, 'E-DOC-429', 'Trop de membres dans cet organigramme (max ' . DOC_HIER_MAX . ')');
            $nid = uniqid('dhr_');
            $conn->prepare("INSERT INTO doc_hierarchie (id, division_id, nom, grade, emploi, telephone, photo_url, parent_id, ordre, created_by) VALUES (:i,:d,:n,:g,:e,:t,:p,:pa,:o,:cb)")
                ->execute(array(':i'=>$nid, ':d'=>$divId, ':n'=>$nom, ':g'=>$grade ?: null, ':e'=>$emploi ?: null, ':t'=>$tel ?: null, ':p'=>$photo, ':pa'=>$parent, ':o'=>$ordre, ':cb'=>(string)$actx['did']));
            doc_log($conn, $actx, 'create', 'membre', $nid, null, $nom, null, null);
            echo json_encode(array('success' => true, 'id' => $nid), DOC_JSON);
        }
    }

    elseif ($action === 'hierarchie_delete') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        doc_require_perm($actx, 'division_manage');
        $id = trim((string)(isset($data['id']) ? $data['id'] : ''));
        if ($id === '') mdt_error(400, 'E-DOC-400', 'id requis');
        $ck = $conn->prepare("SELECT division_id, parent_id, nom FROM doc_hierarchie WHERE id = ? LIMIT 1"); $ck->execute(array($id));
        $row = $ck->fetch();
        if (!$row || !doc_div_visible($actx, $row['division_id'])) mdt_error(404, 'E-DOC-404', 'Membre introuvable');

        $conn->prepare("UPDATE doc_hierarchie SET parent_id = :np WHERE parent_id = :id AND division_id = :d")
            ->execute(array(':np'=>$row['parent_id'], ':id'=>$id, ':d'=>$row['division_id']));
        $conn->prepare("DELETE FROM doc_hierarchie WHERE id = ?")->execute(array($id));
        doc_log($conn, $actx, 'delete', 'membre', $id, null, $row['nom'], null, null);
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'hierarchie_photo') {
        mdt_post_only();
        doc_require_perm($actx, 'division_manage');
        $divId = doc_gstr('division_id');
        if ($divId === '' || !doc_div_visible($actx, $divId)) mdt_error(404, 'E-DOC-404', 'Division introuvable');
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) mdt_error(400, 'E-DOC-420', 'Aucun fichier');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) mdt_error(400, 'E-DOC-421', 'Erreur upload');
        if ((int)$f['size'] > DOC_MAX_IMG) mdt_error(400, 'E-DOC-422', 'Image trop volumineuse');
        if (@disk_free_space(DOC_UPLOAD_DIR) < DOC_MIN_DISK_FREE + (int)$f['size']) mdt_error(507, 'E-DOC-507', 'Stockage insuffisant');
        if (!function_exists('finfo_open')) mdt_error(500, 'E-DOC-500', 'finfo indisponible');
        $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
        global $DOC_IMG;
        if (!isset($DOC_IMG[$mime])) mdt_error(400, 'E-DOC-423', 'Image uniquement (PNG, JPEG, GIF, WebP)');
        if (!is_dir(DOC_UPLOAD_DIR)) @mkdir(DOC_UPLOAD_DIR, 0775, true);
        $res = doc_store_image($f['tmp_name'], $mime);
        if (!$res[0]) mdt_error(500, $res[1], isset($res[2]) ? $res[2] : 'Echec');
        @chmod(DOC_UPLOAD_DIR . $res[0], 0644);
        echo json_encode(array('success' => true, 'url' => DOC_UPLOAD_URL . $res[0]), DOC_JSON);
    }

    elseif ($action === 'perms_save') {
        mdt_post_only();
        $data = mdt_get_post_data('E-DOC-400');
        if (!is_array($data)) $data = array();
        if (empty($actx['super'])) mdt_error(403, 'E-DOC-403', 'Reserve aux administrateurs');
        $roleId = trim((string)(isset($data['role_id']) ? $data['role_id'] : ''));
        if ($roleId === '' || !ctype_digit($roleId)) mdt_error(400, 'E-DOC-400', 'role_id invalide');
        global $DOC_PERMS;
        $valid = array();
        if (isset($data['perms']) && is_array($data['perms'])) {
            foreach ($data['perms'] as $p) { if (isset($DOC_PERMS[$p]) && $p !== 'access') $valid[$p] = true; }
        }
        $before = array();
        $bq = $conn->prepare("SELECT perm FROM doc_role_perms WHERE role_id = :r ORDER BY perm");
        $bq->execute(array(':r' => $roleId)); $before = $bq->fetchAll(PDO::FETCH_COLUMN);
        $conn->prepare("DELETE FROM doc_role_perms WHERE role_id = :r")->execute(array(':r' => $roleId));
        $ins = $conn->prepare("INSERT IGNORE INTO doc_role_perms (role_id, perm) VALUES (:r, :p)");
        foreach (array_keys($valid) as $p) $ins->execute(array(':r' => $roleId, ':p' => $p));
        doc_log($conn, $actx, 'perms_save', 'role_perms', $roleId, null, $roleId, array('perms' => $before), array('perms' => array_keys($valid)));
        echo json_encode(array('success' => true), DOC_JSON);
    }

    elseif ($action === 'logs') {
        header('Cache-Control: no-store');
        doc_require_perm($actx, 'logs_view');
        $limit = isset($_GET['limit']) ? max(1, min(300, (int)$_GET['limit'])) : 150;
        if (!empty($actx['super'])) {
            $rows = $conn->query("SELECT id, action, entity_type, entity_id, categorie_id, label, actor_id, actor_name, before_json, after_json, created_at FROM doc_logs ORDER BY id DESC LIMIT $limit")->fetchAll();
        } else {
            list($vsql, $args) = doc_visible_sql($actx, 'categorie_id');
            $draftExcl = '';
            if (!doc_can($actx, 'doc_edit')) {
                $draftExcl = " AND NOT (entity_type = 'document' AND EXISTS (SELECT 1 FROM doc_documents dd WHERE dd.id = doc_logs.entity_id AND dd.statut = 'brouillon' AND dd.created_by <> ?))";
                $args[] = (string)$actx['did'];
            }
            $sql = "SELECT id, action, entity_type, entity_id, categorie_id, label, actor_id, actor_name, before_json, after_json, created_at FROM doc_logs WHERE categorie_id IS NOT NULL" . $vsql . $draftExcl . " ORDER BY id DESC LIMIT $limit";
            $st = $conn->prepare($sql); $st->execute($args); $rows = $st->fetchAll();
        }
        foreach ($rows as &$r) {
            $r['before'] = $r['before_json'] ? json_decode($r['before_json'], true) : null;
            $r['after'] = $r['after_json'] ? json_decode($r['after_json'], true) : null;
            unset($r['before_json'], $r['after_json']);
        }
        unset($r);
        echo json_encode(array('logs' => $rows), DOC_JSON);
    }

} catch (Exception $e) {
    mdt_error(500, 'E-DOC-500', 'Erreur serveur', $e->getMessage());
}
