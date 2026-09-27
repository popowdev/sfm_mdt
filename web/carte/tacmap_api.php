<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('TAC_DDL_VERSION', '2026-08-04.4');
define('TAC_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
define('TAC_MAX_SHAPES', 600);
define('TAC_MAX_POINTS', 4000);
define('TAC_MAX_BATCH', 40);
define('TAC_TEXT_MAX', 200);
define('TAC_URL_MAX', 500);
define('TAC_TOMB_DAYS', 7);

$TAC_KINDS  = array('ink', 'line', 'arrow', 'zone', 'circle', 'text', 'point');
$TAC_BGS    = array('tiles', 'image');

function tac_g($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? trim($_GET[$k]) : ''; }
function tac_txt($v, $max) { return mb_substr(trim((string)$v), 0, $max, 'UTF-8'); }

function tac_color($v) {
    $v = strtoupper(trim((string)$v));
    return preg_match('/^#[0-9A-F]{6}$/', $v) ? $v : '#F87171';
}

function tac_url($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (!preg_match('#^(https?://|/)#i', $v)) return '';
    if (preg_match('#^\s*javascript:#i', $v)) return '';
    return mb_substr($v, 0, TAC_URL_MAX, 'UTF-8');
}

function tac_points($raw) {
    if (!is_array($raw)) return null;
    $n = count($raw);
    if ($n < 1 || $n > TAC_MAX_POINTS) return null;
    $out = array();
    foreach ($raw as $p) {
        if (!is_array($p) || count($p) < 2) return null;
        $x = (float)$p[0]; $y = (float)$p[1];
        if (!is_finite($x) || !is_finite($y)) return null;
        $out[] = array(round(max(-2, min(3, $x)), 5), round(max(-2, min(3, $y)), 5));
    }
    return $out;
}

function tac_ddl($conn) {
    try {
        $st = $conn->query("SELECT mv FROM tac_meta WHERE mk = 'ddl_version'");
        if ($st && $st->fetchColumn() === TAC_DDL_VERSION) return;
    } catch (PDOException $e) {}

    $conn->exec("CREATE TABLE IF NOT EXISTS tac_meta (
        mk VARCHAR(40) NOT NULL PRIMARY KEY,
        mv TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS tac_boards (
        id VARCHAR(48) NOT NULL PRIMARY KEY,
        kind VARCHAR(16) NOT NULL DEFAULT 'common',
        label VARCHAR(120) NOT NULL DEFAULT '',
        bg_kind VARCHAR(16) NOT NULL DEFAULT 'tiles',
        bg_url VARCHAR(500) NOT NULL DEFAULT '',
        owner_did VARCHAR(30) NULL,
        owner_name VARCHAR(120) NULL,
        legend LONGTEXT NULL,
        notes LONGTEXT NULL,
        roles LONGTEXT NULL,
        roles_edit LONGTEXT NULL,
        rev BIGINT NOT NULL DEFAULT 0,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY k_kind (kind, archived)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS tac_shapes (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        board_id VARCHAR(48) NOT NULL,
        cid VARCHAR(40) NOT NULL,
        kind VARCHAR(16) NOT NULL,
        color VARCHAR(9) NOT NULL DEFAULT '#F87171',
        width TINYINT UNSIGNED NOT NULL DEFAULT 4,
        alpha SMALLINT UNSIGNED NOT NULL DEFAULT 100,
        pts LONGTEXT NULL,
        label VARCHAR(255) NOT NULL DEFAULT '',
        image_url VARCHAR(500) NOT NULL DEFAULT '',
        owner_did VARCHAR(30) NULL,
        owner_name VARCHAR(120) NULL,
        rev BIGINT NOT NULL DEFAULT 0,
        deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY u_board_cid (board_id, cid),
        KEY k_sync (board_id, rev)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $conn->exec("ALTER TABLE tac_shapes ADD COLUMN deleted_at DATETIME NULL"); } catch (PDOException $e) {}
    try { $conn->exec("ALTER TABLE tac_boards ADD COLUMN roles LONGTEXT NULL"); } catch (PDOException $e) {}
    try { $conn->exec("ALTER TABLE tac_boards ADD COLUMN roles_edit LONGTEXT NULL"); } catch (PDOException $e) {}

    $conn->prepare("INSERT INTO tac_boards (id, kind, label) VALUES ('tb_common', 'common', 'Plan commun')
                    ON DUPLICATE KEY UPDATE id = id")->execute();

    $conn->prepare("INSERT INTO tac_meta (mk, mv) VALUES ('ddl_version', :v)
                    ON DUPLICATE KEY UPDATE mv = :v2")
        ->execute(array(':v' => TAC_DDL_VERSION, ':v2' => TAC_DDL_VERSION));
}

function tac_private_id($did) { return 'tb_p_' . substr(md5('tacmap|' . $did), 0, 24); }

function tac_board_roles($board, $col = 'roles') {
    if (!isset($board[$col]) || !$board[$col]) return array();
    $r = json_decode($board[$col], true);
    return is_array($r) ? $r : array();
}

function tac_is_owner_or_sup($board) {
    if (!empty($GLOBALS['TAC_IS_SUP'])) return true;
    $me = isset($GLOBALS['TAC_ME_DID']) ? (string)$GLOBALS['TAC_ME_DID'] : '';
    return $me !== '' && (string)$board['owner_did'] === $me;
}

function tac_board_can_edit($board) {
    if (tac_is_owner_or_sup($board)) return true;
    $req = tac_board_roles($board, 'roles_edit');
    if (!$req) return tac_board_allowed($board);
    $mine = isset($GLOBALS['TAC_ME_ROLES']) ? $GLOBALS['TAC_ME_ROLES'] : array();
    return count(array_intersect($mine, $req)) > 0;
}

function tac_require_edit($board) {
    if (!tac_board_can_edit($board)) {
        mdt_error(403, 'E-TAC-403', 'Plan en lecture seule pour toi');
    }
    return $board;
}

function tac_board_allowed($board) {
    $req = tac_board_roles($board);
    if (!$req) return true;
    if (tac_is_owner_or_sup($board)) return true;
    $mine = isset($GLOBALS['TAC_ME_ROLES']) ? $GLOBALS['TAC_ME_ROLES'] : array();
    return count(array_intersect($mine, $req)) > 0;
}

function tac_resolve_board($conn, $scope, $ref, $did, $name) {
    if ($scope === 'priv') {
        if (!$did) mdt_error(403, 'E-TAC-403', 'Compte sans Discord lie : calque prive indisponible');
        $id = tac_private_id($did);
        $st = $conn->prepare("SELECT * FROM tac_boards WHERE id = :i");
        $st->execute(array(':i' => $id));
        $b = $st->fetch();
        if (!$b) {
            $conn->prepare("INSERT INTO tac_boards (id, kind, label, owner_did, owner_name)
                            VALUES (:i, 'private', 'Mon calque', :d, :n)
                            ON DUPLICATE KEY UPDATE id = id")
                ->execute(array(':i' => $id, ':d' => $did, ':n' => $name));
            $st->execute(array(':i' => $id));
            $b = $st->fetch();
        }
        return $b;
    }

    $id = 'tb_common';
    if ($ref !== '' && preg_match('/^tb_i_[0-9]{1,12}$/', $ref)) $id = $ref;
    elseif ($ref !== '' && preg_match('/^tb_u_[a-f0-9]{16}$/', $ref)) $id = $ref;
    elseif ($ref !== '' && $ref !== 'tb_common') mdt_error(400, 'E-TAC-400', 'Plan inconnu');

    $st = $conn->prepare("SELECT * FROM tac_boards WHERE id = :i");
    $st->execute(array(':i' => $id));
    $b = $st->fetch();

    if (!$b && strpos($id, 'tb_i_') === 0) {
        $iv = (int)substr($id, 5);
        $lbl = 'Intervention #' . $iv;
        try {
            $q = $conn->prepare("SELECT titre FROM dispatch_interventions WHERE id = :i");
            $q->execute(array(':i' => $iv));
            $t = $q->fetchColumn();
            if ($t) $lbl = tac_txt($t, 110);
        } catch (PDOException $e) {}
        $conn->prepare("INSERT INTO tac_boards (id, kind, label, owner_did, owner_name)
                        VALUES (:i, 'intervention', :l, :d, :n) ON DUPLICATE KEY UPDATE id = id")
            ->execute(array(':i' => $id, ':l' => $lbl, ':d' => $did, ':n' => $name));
        $st->execute(array(':i' => $id));
        $b = $st->fetch();
    }

    if (!$b) mdt_error(404, 'E-TAC-404', 'Plan introuvable');
    if (!tac_board_allowed($b)) {
        mdt_error(403, 'E-TAC-403', 'Ce plan est reserve a certains roles');
    }
    return $b;
}

function tac_bump($conn, $boardId) {
    $conn->prepare("UPDATE tac_boards SET rev = LAST_INSERT_ID(rev + 1) WHERE id = :i")
        ->execute(array(':i' => $boardId));
    return (int)$conn->query("SELECT LAST_INSERT_ID()")->fetchColumn();
}

function tac_matricules($conn, $dids) {
    $dids = array_values(array_unique(array_filter($dids)));
    if (!$dids) return array();
    $in = array(); $p = array();
    foreach ($dids as $i => $d) { $in[] = ':d' . $i; $p[':d' . $i] = (string)$d; }
    $out = array();
    try {
        $st = $conn->prepare("SELECT discord_id, matricule, nom_prenom FROM roster
                              WHERE discord_id IN (" . implode(',', $in) . ")");
        $st->execute($p);
        foreach ($st->fetchAll() as $row) {
            $out[(string)$row['discord_id']] = array(
                'mat' => (string)$row['matricule'],
                'nom' => (string)$row['nom_prenom'],
            );
        }
    } catch (PDOException $e) {}
    return $out;
}

function tac_shape_out($r) {
    return array(
        'cid'    => $r['cid'],
        'kind'   => $r['kind'],
        'color'  => $r['color'],
        'width'  => (int)$r['width'],
        'alpha'  => (int)$r['alpha'],
        'pts'    => $r['pts'] ? (json_decode($r['pts'], true) ?: array()) : array(),
        'label'  => $r['label'],
        'image'  => $r['image_url'],
        'by'     => $r['owner_name'],
        'by_did' => $r['owner_did'],
        'rev'    => (int)$r['rev'],
        'del'    => (int)$r['deleted'] === 1,
    );
}

$action = tac_g('action');

try {
    $me    = mdt_require_auth($conn, 'tacmap');
    $meDid = isset($me['discord_id']) ? $me['discord_id'] : null;
    $meNam = isset($GLOBALS['MDT_ACTOR']['name']) ? $GLOBALS['MDT_ACTOR']['name'] : (string)$meDid;

    if (!mdt_module_access($conn, 'dispatch', $meDid)) {
        mdt_seclog($conn, 'tacmap_denied', 'low', array('did' => (string)$meDid));
        mdt_error(403, 'E-TAC-403', 'Acces refuse a la carte tactique');
    }
    $isSup = mdt_is_admin($conn, $meDid);
    $meRoles = array();
    try {
        $rq = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = :d LIMIT 1");
        $rq->execute(array(':d' => $meDid));
        $raw = $rq->fetchColumn();
        $meRoles = $raw ? json_decode($raw, true) : array();
        if (!is_array($meRoles)) $meRoles = array();
    } catch (PDOException $e) {}
    $GLOBALS['TAC_IS_SUP'] = $isSup;
    $GLOBALS['TAC_ME_DID'] = $meDid;
    $GLOBALS['TAC_ME_ROLES'] = $meRoles;

    tac_ddl($conn);

    if ($action === 'boards') {
        $rows = $conn->query("SELECT id, kind, label, bg_kind, bg_url, owner_did, owner_name, roles, rev, updated_at
                              FROM tac_boards WHERE archived = 0 AND kind <> 'private'
                              ORDER BY FIELD(kind, 'common', 'intervention', 'image'), updated_at DESC
                              LIMIT 200")->fetchAll();
        $bmats = tac_matricules($conn, array_map(function ($r) { return $r['owner_did']; }, $rows));
        $out = array();
        foreach ($rows as $r) {
            if (!tac_board_allowed($r)) continue;
            $bk = (string)$r['owner_did'];
            if (isset($bmats[$bk]) && $bmats[$bk]['nom'] !== '') {
                $r['owner_name'] = ($bmats[$bk]['mat'] !== '' ? $bmats[$bk]['mat'] . ' ' : '') . $bmats[$bk]['nom'];
            } elseif (preg_match('/^[0-9]{15,}$/', (string)$r['owner_name'])) {
                $r['owner_name'] = '';
            }
            $r['roles'] = tac_board_roles($r);
            $r['roles_edit'] = tac_board_roles($r, 'roles_edit');
            $r['mine'] = ((string)$r['owner_did'] === (string)$meDid) ? 1 : 0;
            $r['can_edit'] = tac_board_can_edit($r) ? 1 : 0;
            $out[] = $r;
        }
        echo json_encode(array('boards' => $out, 'is_sup' => $isSup ? 1 : 0, 'me' => $meDid), TAC_JSON);
        exit;
    }

    if ($action === 'state') {
        $scope = tac_g('scope') === 'priv' ? 'priv' : 'comm';
        $board = tac_resolve_board($conn, $scope, tac_g('board'), $meDid, $meNam);
        $since = (int)tac_g('since');
        $rev   = (int)$board['rev'];

        $etag = '"tac.' . $board['id'] . '.' . $rev . '"';
        if ($since > 0 && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header('ETag: ' . $etag);
            http_response_code(304);
            exit;
        }
        header('ETag: ' . $etag);

        if ($since <= 0) {
            $conn->prepare("DELETE FROM tac_shapes WHERE board_id = :b AND deleted = 1
                            AND deleted_at IS NOT NULL
                            AND deleted_at < DATE_SUB(NOW(), INTERVAL " . TAC_TOMB_DAYS . " DAY)")
                ->execute(array(':b' => $board['id']));
        }

        $st = $conn->prepare("SELECT * FROM tac_shapes WHERE board_id = :b AND rev > :s
                              " . ($since > 0 ? "" : "AND deleted = 0 ") . "
                              ORDER BY rev ASC, id ASC LIMIT 2000");
        $st->execute(array(':b' => $board['id'], ':s' => $since));
        $rows = $st->fetchAll();
        $mats = tac_matricules($conn, array_map(function ($r) { return $r['owner_did']; }, $rows));
        $shapes = array();
        foreach ($rows as $r) {
            $o = tac_shape_out($r);
            $k = (string)$r['owner_did'];
            $o['mat'] = isset($mats[$k]) ? $mats[$k]['mat'] : '';
            if (isset($mats[$k]) && $mats[$k]['nom'] !== '') $o['by'] = $mats[$k]['nom'];
            $shapes[] = $o;
        }

        echo json_encode(array(
            'board' => array(
                'id' => $board['id'], 'kind' => $board['kind'], 'label' => $board['label'],
                'bg_kind' => $board['bg_kind'], 'bg_url' => $board['bg_url'],
                'legend' => $board['legend'] ? (json_decode($board['legend'], true) ?: array()) : array(),
                'notes' => (string)$board['notes'],
                'owner_did' => $board['owner_did'],
                'roles' => tac_board_roles($board),
                'roles_edit' => tac_board_roles($board, 'roles_edit'),
                'mine' => ((string)$board['owner_did'] === (string)$meDid) ? 1 : 0,
                'can_edit' => tac_board_can_edit($board) ? 1 : 0,
            ),
            'rev' => $rev, 'full' => $since <= 0, 'shapes' => $shapes,
            'me' => $meDid, 'is_sup' => $isSup ? 1 : 0,
        ), TAC_JSON);
        exit;
    }

    if ($action === 'add') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $scope = (isset($d['scope']) && $d['scope'] === 'priv') ? 'priv' : 'comm';
        $board = tac_require_edit(tac_resolve_board($conn, $scope, isset($d['board']) ? (string)$d['board'] : '', $meDid, $meNam));
        $list  = (isset($d['shapes']) && is_array($d['shapes'])) ? $d['shapes'] : array();
        if (!$list) mdt_error(400, 'E-TAC-400', 'Aucune forme');
        if (count($list) > TAC_MAX_BATCH) mdt_error(400, 'E-TAC-413', 'Trop de formes en une fois');

        $cnt = $conn->prepare("SELECT COUNT(*) FROM tac_shapes WHERE board_id = :b AND deleted = 0");
        $cnt->execute(array(':b' => $board['id']));
        if ((int)$cnt->fetchColumn() + count($list) > TAC_MAX_SHAPES) {
            mdt_error(409, 'E-TAC-409', 'Plan sature (' . TAC_MAX_SHAPES . ' formes) : effacez avant d ajouter');
        }

        $rev = tac_bump($conn, $board['id']);
        $ins = $conn->prepare("INSERT INTO tac_shapes
            (board_id, cid, kind, color, width, alpha, pts, label, image_url, owner_did, owner_name, rev)
            VALUES (:b, :c, :k, :col, :w, :a, :p, :l, :img, :od, :on, :r)
            ON DUPLICATE KEY UPDATE color = :col2, width = :w2, alpha = :a2, pts = :p2,
                label = :l2, image_url = :img2, rev = :r2, deleted = 0, deleted_at = NULL");

        $ownQ = $conn->prepare("SELECT owner_did FROM tac_shapes WHERE board_id = :b AND cid = :c");
        $okCids = array(); $refused = 0;
        foreach ($list as $s) {
            if (!is_array($s)) continue;
            $kind = isset($s['kind']) ? (string)$s['kind'] : '';
            if (!in_array($kind, $TAC_KINDS, true)) continue;
            $pts = tac_points(isset($s['pts']) ? $s['pts'] : null);
            if ($pts === null) continue;
            $cid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)(isset($s['cid']) ? $s['cid'] : ''));
            if ($cid === '' || strlen($cid) > 40) continue;
            if ($scope !== 'priv' && !$isSup) {
                $ownQ->execute(array(':b' => $board['id'], ':c' => $cid));
                $prevOwner = $ownQ->fetchColumn();
                if ($prevOwner !== false && (string)$prevOwner !== (string)$meDid) { $refused++; continue; }
            }
            $alpha = isset($s['alpha']) ? (int)$s['alpha'] : 100;
            $width = isset($s['width']) ? (int)$s['width'] : 4;
            $p = array(
                ':b' => $board['id'], ':c' => $cid, ':k' => $kind,
                ':col' => tac_color(isset($s['color']) ? $s['color'] : ''), ':w' => max(1, min(40, $width)),
                ':a' => max(5, min(100, $alpha)), ':p' => json_encode($pts, TAC_JSON),
                ':l' => tac_txt(isset($s['label']) ? $s['label'] : '', TAC_TEXT_MAX),
                ':img' => tac_url(isset($s['image']) ? $s['image'] : ''),
                ':od' => $meDid, ':on' => $meNam, ':r' => $rev,
            );
            $p[':col2'] = $p[':col']; $p[':w2'] = $p[':w']; $p[':a2'] = $p[':a'];
            $p[':p2'] = $p[':p']; $p[':l2'] = $p[':l']; $p[':img2'] = $p[':img']; $p[':r2'] = $rev;
            $ins->execute($p);
            $okCids[] = $cid;
        }
        if (!$okCids) {
            if ($refused) mdt_error(403, 'E-TAC-403', 'Ces traces appartiennent a un autre agent');
            mdt_error(400, 'E-TAC-422', 'Formes invalides');
        }
        echo json_encode(array('success' => true, 'rev' => $rev, 'cids' => $okCids,
                               'refused' => $refused), TAC_JSON);
        exit;
    }

    if ($action === 'del') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $scope = (isset($d['scope']) && $d['scope'] === 'priv') ? 'priv' : 'comm';
        $board = tac_require_edit(tac_resolve_board($conn, $scope, isset($d['board']) ? (string)$d['board'] : '', $meDid, $meNam));
        $cids  = (isset($d['cids']) && is_array($d['cids'])) ? array_slice($d['cids'], 0, 200) : array();
        if (!$cids) mdt_error(400, 'E-TAC-400', 'Rien a supprimer');

        $rev = tac_bump($conn, $board['id']);
        $own = ($scope === 'priv' || $isSup) ? "" : "AND owner_did <=> :me ";
        $up  = $conn->prepare("UPDATE tac_shapes SET deleted = 1, deleted_at = NOW(), rev = :r
                               WHERE board_id = :b AND cid = :c AND deleted = 0 " . $own);
        $n = 0;
        foreach ($cids as $c) {
            $c = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$c);
            if ($c === '') continue;
            $p = array(':r' => $rev, ':b' => $board['id'], ':c' => $c);
            if ($own !== "") $p[':me'] = $meDid;
            $up->execute($p);
            $n += $up->rowCount();
        }
        echo json_encode(array('success' => true, 'rev' => $rev, 'deleted' => $n,
                               'partial' => ($n < count($cids))), TAC_JSON);
        exit;
    }

    if ($action === 'clear') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $scope = (isset($d['scope']) && $d['scope'] === 'priv') ? 'priv' : 'comm';
        $board = tac_require_edit(tac_resolve_board($conn, $scope, isset($d['board']) ? (string)$d['board'] : '', $meDid, $meNam));
        $mine  = !empty($d['mine_only']);
        if ($scope !== 'priv' && !$isSup && !$mine) {
            mdt_error(403, 'E-TAC-403', 'Seule la Supervision peut tout effacer');
        }
        $rev = tac_bump($conn, $board['id']);
        $sql = "UPDATE tac_shapes SET deleted = 1, deleted_at = NOW(), rev = :r WHERE board_id = :b AND deleted = 0";
        $p = array(':r' => $rev, ':b' => $board['id']);
        if ($scope !== 'priv' && $mine && !$isSup) { $sql .= " AND owner_did <=> :me"; $p[':me'] = $meDid; }
        $st = $conn->prepare($sql); $st->execute($p);
        echo json_encode(array('success' => true, 'rev' => $rev, 'deleted' => $st->rowCount()), TAC_JSON);
        exit;
    }

    if ($action === 'promote') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        if (!$meDid) mdt_error(403, 'E-TAC-403', 'Compte sans Discord lie');
        $src  = tac_resolve_board($conn, 'priv', '', $meDid, $meNam);
        $dst  = tac_require_edit(tac_resolve_board($conn, 'comm', isset($d['board']) ? (string)$d['board'] : '', $meDid, $meNam));
        $cids = (isset($d['cids']) && is_array($d['cids'])) ? array_slice($d['cids'], 0, 200) : array();

        $sql = "SELECT * FROM tac_shapes WHERE board_id = :b AND deleted = 0";
        $p = array(':b' => $src['id']);
        if ($cids) {
            $in = array();
            foreach ($cids as $i => $c) {
                $c = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$c);
                if ($c === '') continue;
                $in[] = ':c' . $i; $p[':c' . $i] = $c;
            }
            if (!$in) mdt_error(400, 'E-TAC-400', 'Rien a publier');
            $sql .= " AND cid IN (" . implode(',', $in) . ")";
        }
        $st = $conn->prepare($sql); $st->execute($p);
        $rows = $st->fetchAll();
        if (!$rows) mdt_error(404, 'E-TAC-404', 'Aucune forme a publier');

        $cnt = $conn->prepare("SELECT COUNT(*) FROM tac_shapes WHERE board_id = :b AND deleted = 0");
        $cnt->execute(array(':b' => $dst['id']));
        if ((int)$cnt->fetchColumn() + count($rows) > TAC_MAX_SHAPES) {
            mdt_error(409, 'E-TAC-409', 'Plan de destination sature');
        }

        $rev = tac_bump($conn, $dst['id']);
        $ins = $conn->prepare("INSERT INTO tac_shapes
            (board_id, cid, kind, color, width, alpha, pts, label, image_url, owner_did, owner_name, rev)
            VALUES (:b, :c, :k, :col, :w, :a, :p, :l, :img, :od, :on, :r)
            ON DUPLICATE KEY UPDATE pts = :p2, rev = :r2, deleted = 0");
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute(array(
                ':b' => $dst['id'], ':c' => substr('p' . $r['cid'], 0, 40), ':k' => $r['kind'],
                ':col' => $r['color'], ':w' => $r['width'], ':a' => $r['alpha'], ':p' => $r['pts'],
                ':l' => $r['label'], ':img' => $r['image_url'], ':od' => $meDid, ':on' => $meNam,
                ':r' => $rev, ':p2' => $r['pts'], ':r2' => $rev,
            ));
            $n++;
        }
        echo json_encode(array('success' => true, 'rev' => $rev, 'moved' => $n, 'board' => $dst['id']), TAC_JSON);
        exit;
    }

    if ($action === 'board_save') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $scope = (isset($d['scope']) && $d['scope'] === 'priv') ? 'priv' : 'comm';
        $board = tac_resolve_board($conn, $scope, isset($d['board']) ? (string)$d['board'] : '', $meDid, $meNam);
        if ($scope !== 'priv') tac_require_edit($board);
        $legend = (isset($d['legend']) && is_array($d['legend'])) ? array_slice($d['legend'], 0, 24) : array();
        $clean = array();
        foreach ($legend as $l) {
            if (!is_array($l)) continue;
            $clean[] = array('color' => tac_color(isset($l['color']) ? $l['color'] : ''),
                             'label' => tac_txt(isset($l['label']) ? $l['label'] : '', 60));
        }
        $conn->prepare("UPDATE tac_boards SET legend = :lg, notes = :no, label = COALESCE(NULLIF(:lb,''), label)
                        WHERE id = :i")
            ->execute(array(
                ':lg' => json_encode($clean, TAC_JSON),
                ':no' => tac_txt(isset($d['notes']) ? $d['notes'] : '', 4000),
                ':lb' => tac_txt(isset($d['label']) ? $d['label'] : '', 110),
                ':i'  => $board['id'],
            ));
        $rev = tac_bump($conn, $board['id']);
        echo json_encode(array('success' => true, 'rev' => $rev), TAC_JSON);
        exit;
    }

    if ($action === 'board_create') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $bg = tac_url(isset($d['bg_url']) ? $d['bg_url'] : '');
        $onTiles = !$bg;
        $id = 'tb_u_' . substr(md5(uniqid('tac', true) . $meDid), 0, 16);
        $conn->prepare("INSERT INTO tac_boards (id, kind, label, bg_kind, bg_url, owner_did, owner_name)
                        VALUES (:i, 'image', :l, :bk, :u, :d, :n)")
            ->execute(array(
                ':i' => $id,
                ':l' => tac_txt(isset($d['label']) ? $d['label'] : '', 110) ?: ($onTiles ? 'Nouveau plan' : 'Briefing'),
                ':bk' => $onTiles ? 'tiles' : 'image',
                ':u' => $bg, ':d' => $meDid, ':n' => $meNam,
            ));
        echo json_encode(array('success' => true, 'board' => $id), TAC_JSON);
        exit;
    }

    if ($action === 'board_access') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $ref = isset($d['board']) ? (string)$d['board'] : '';
        if ($ref === 'tb_common' || strpos($ref, 'tb_p_') === 0) {
            mdt_error(400, 'E-TAC-400', 'Ce plan ne peut pas etre restreint');
        }
        $board = tac_resolve_board($conn, 'comm', $ref, $meDid, $meNam);
        if (!$isSup && (string)$board['owner_did'] !== (string)$meDid) {
            mdt_error(403, 'E-TAC-403', 'Plan cree par un autre agent');
        }
        $norm = function ($raw) {
            $ids = is_array($raw) ? array_slice($raw, 0, 30) : array();
            $out = array();
            foreach ($ids as $r) {
                $r = preg_replace('/[^0-9]/', '', (string)$r);
                if ($r !== '' && !in_array($r, $out, true)) $out[] = $r;
            }
            return $out;
        };
        $view = $norm(isset($d['roles']) ? $d['roles'] : null);
        $edit = $norm(isset($d['roles_edit']) ? $d['roles_edit'] : null);
        $conn->prepare("UPDATE tac_boards SET roles = :r, roles_edit = :e WHERE id = :i")
            ->execute(array(
                ':r' => $view ? json_encode($view, TAC_JSON) : null,
                ':e' => $edit ? json_encode($edit, TAC_JSON) : null,
                ':i' => $board['id'],
            ));
        echo json_encode(array('success' => true, 'roles' => $view, 'roles_edit' => $edit), TAC_JSON);
        exit;
    }

    if ($action === 'board_delete') {
        mdt_post_only();
        $d = mdt_get_post_data('E-TAC-400');
        $ref = isset($d['board']) ? (string)$d['board'] : '';
        if (!preg_match('/^tb_[iu]_[a-f0-9]{1,16}$/', $ref) && !preg_match('/^tb_i_[0-9]{1,12}$/', $ref)) {
            mdt_error(400, 'E-TAC-400', 'Plan non supprimable');
        }
        $st = $conn->prepare("SELECT owner_did FROM tac_boards WHERE id = :i");
        $st->execute(array(':i' => $ref));
        $row = $st->fetch();
        if (!$row) mdt_error(404, 'E-TAC-404', 'Plan introuvable');
        if (!$isSup && $row['owner_did'] !== $meDid) mdt_error(403, 'E-TAC-403', 'Plan cree par un autre agent');
        $conn->prepare("UPDATE tac_boards SET archived = 1 WHERE id = :i")->execute(array(':i' => $ref));
        echo json_encode(array('success' => true), TAC_JSON);
        exit;
    }

    mdt_error(400, 'E-TAC-400', 'Action inconnue');

} catch (PDOException $e) {
    mdt_error(500, 'E-TAC-500', 'Erreur base de donnees', $e->getMessage());
}
