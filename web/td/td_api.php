<?php

require_once __DIR__ . '/../db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$action = isset($_GET['action']) ? $_GET['action'] : '';

$TD_INSTRUCTOR_ROLES = array(
    '1210813641176383545',
    '1463655150152581151',
    '1384107706608259152',
);
$TD_SENIOR_ROLES = array(
    '1210813641176383545',
    '1463655150152581151',
);

function td_actor_roles($conn, $did) {
    if ($did === '') return array();
    try {
        $s = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = ? LIMIT 1");
        $s->execute(array($did));
        $raw = $s->fetchColumn();
        $d = $raw ? json_decode($raw, true) : array();
        return is_array($d) ? array_map('strval', $d) : array();
    } catch (Exception $e) { return array(); }
}

$me = mdt_require_auth($conn, 'td');

$TD_SUIVI_WRITES = array('fiche_notation_set', 'fiche_notation_remark');
$tdDid = isset($GLOBALS['MDT_ACTOR']['did']) ? $GLOBALS['MDT_ACTOR']['did'] : null;
$tdHasTd    = mdt_module_access($conn, 'td', $tdDid);
$tdHasSuivi = mdt_module_access($conn, 'suivi', $tdDid);
$tdIsWrite  = ($_SERVER['REQUEST_METHOD'] === 'POST');
if (!$tdHasTd) {
    if (!$tdHasSuivi) mdt_require_module($conn, 'suivi', 'td_api');
    if ($tdIsWrite && !in_array($action, $TD_SUIVI_WRITES, true)) {
        mdt_require_module($conn, 'td', 'td_write');
    }
}
$did = isset($me['discord_id']) ? (string)$me['discord_id'] : '';
$isAdmin = mdt_is_admin($conn, $did);
$td_actor_roles = td_actor_roles($conn, $did);
$isInstructor = $isAdmin || (count(array_intersect($td_actor_roles, mdt_role_config($conn, 'td_instructor', $TD_INSTRUCTOR_ROLES))) > 0);
$isSenior = $isAdmin || (count(array_intersect($td_actor_roles, mdt_role_config($conn, 'td_senior', $TD_SENIOR_ROLES))) > 0);

function td_perms($isAdmin, $isInstructor, $isSenior) {
    return array(
        'access'        => true,
        'td_instructor' => $isInstructor ? true : false,
        'td_senior'     => $isSenior ? true : false,
        'td_manage'     => $isAdmin ? true : false,
    );
}
function td_require_instructor($isInstructor) { if (!$isInstructor) mdt_error(403, 'E-TD-403', 'Réservé aux instructeurs TD'); }
function td_require_senior($isSenior) { if (!$isSenior) mdt_error(403, 'E-TD-403', 'Réservé aux instructeurs confirmés (Training Division / Référents)'); }

function td_my_identity($conn, $did) {
    if ($did === '') return '';
    try {
        $q = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = ? LIMIT 1");
        $q->execute(array($did));
        $r = $q->fetch();
        if ($r) return substr(($r['matricule'] ? $r['matricule'] . ' | ' : '') . $r['nom_prenom'], 0, 100);
    } catch (Exception $e) {}
    try {
        $q = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(username,''), '') FROM users WHERE discord_id = ? LIMIT 1");
        $q->execute(array($did));
        $n = $q->fetchColumn();
        if ($n) return substr((string)$n, 0, 100);
    } catch (Exception $e) {}
    return '';
}

function td_me_name($conn, $did) {
    if ($did === '') return '';
    try {
        $s = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username) FROM users WHERE discord_id = ? LIMIT 1");
        $s->execute(array($did));
        $n = $s->fetchColumn();
        return $n ?: '';
    } catch (Exception $e) { return ''; }
}

function td_count($conn, $sql) {
    try { return (int)$conn->query($sql)->fetchColumn(); } catch (Exception $e) { return 0; }
}

if ($action === 'bootstrap') {

    $fl = array('total' => 0, 'reussi' => 0, 'echoue' => 0, 'en_cours' => 0, 'faute_elim' => 0);
    try {
        foreach ($conn->query("SELECT resultat, COUNT(*) n FROM td_first_lincoln GROUP BY resultat") as $r) {
            $n = (int)$r['n'];
            $fl['total'] += $n;
            if ($r['resultat'] === 'reussi') $fl['reussi'] += $n;
            elseif ($r['resultat'] === 'en_cours') $fl['en_cours'] += $n;
            else $fl['echoue'] += $n;
        }
        $fl['faute_elim'] = td_count($conn, "SELECT COUNT(*) FROM td_first_lincoln WHERE faute_eliminatoire = 1");
    } catch (Exception $e) {}

    $stats = array(
        'epreuves'      => td_count($conn, "SELECT COUNT(*) FROM td_epreuves"),
        'sessions'      => td_count($conn, "SELECT COUNT(*) FROM td_sessions"),
        'fiches'        => td_count($conn, "SELECT COUNT(*) FROM td_fiches"),
        'notations'     => td_count($conn, "SELECT COUNT(*) FROM td_notations"),
        'fl_types'      => td_count($conn, "SELECT COUNT(*) FROM td_fl_types WHERE active = 1"),
        'first_lincoln' => $fl,
    );

    echo json_encode(array(
        'success' => true,
        'access'  => true,
        'me'      => array('discord_id' => $did, 'name' => td_me_name($conn, $did)),
        'perms'   => td_perms($isAdmin, $isInstructor, $isSenior),
        'stats'   => $stats,
    ));
    exit;
}

function td_epreuves_all($conn) {
    $out = array();
    foreach ($conn->query("SELECT id, data FROM td_epreuves")->fetchAll() as $r) {
        $j = json_decode($r['data'], true); if (!is_array($j)) $j = array();
        $out[] = array(
            'id'          => $r['id'],
            'nom'         => isset($j['nom']) ? (string)$j['nom'] : '',
            'type'        => in_array(isset($j['type']) ? $j['type'] : '', array('simple', 'notation', 'temps'), true) ? $j['type'] : 'simple',
            'ordre'       => (int)(isset($j['ordre']) ? $j['ordre'] : 0),
            'noteMax'     => (isset($j['noteMax']) && $j['noteMax'] !== '' && $j['noteMax'] !== null) ? (string)$j['noteMax'] : null,
            'tempsVise'   => (isset($j['tempsVise']) && $j['tempsVise'] !== '' && $j['tempsVise'] !== null) ? (string)$j['tempsVise'] : null,
            'description' => isset($j['description']) ? (string)$j['description'] : '',
            'image_url'   => isset($j['image_url']) ? $j['image_url'] : null,
            'cat_id'      => isset($j['cat_id']) ? (string)$j['cat_id'] : '',
        );
    }
    usort($out, function ($a, $b) { return $a['ordre'] - $b['ordre']; });
    return $out;
}
function td_require_manage($isAdmin) {
    if (!$isAdmin) mdt_error(403, 'E-TD-403', 'Réservé au commandement / dev');
}
function td_epreuve_payload($conn, $data, $existingOrdre) {
    $nom = mb_substr(trim((string)(isset($data['nom']) ? $data['nom'] : '')), 0, 120);
    if ($nom === '') mdt_error(400, 'E-TD-400', 'Nom requis');
    $type = in_array(isset($data['type']) ? $data['type'] : '', array('simple', 'notation', 'temps'), true) ? $data['type'] : 'simple';
    $noteMax = ($type === 'notation' && isset($data['noteMax']) && $data['noteMax'] !== '') ? (string)max(0, (int)$data['noteMax']) : null;
    $tempsVise = ($type === 'temps' && isset($data['tempsVise']) && $data['tempsVise'] !== '') ? mb_substr(trim((string)$data['tempsVise']), 0, 40) : null;
    $ordre = isset($data['ordre']) ? (int)$data['ordre'] : (int)$existingOrdre;
    $description = mb_substr(trim((string)(isset($data['description']) ? $data['description'] : '')), 0, 2000);
    $image_url = fl_safe_img(isset($data['image_url']) ? $data['image_url'] : null);
    $cat_id = mb_substr(trim((string)(isset($data['cat_id']) ? $data['cat_id'] : '')), 0, 50);
    return array('nom' => $nom, 'type' => $type, 'ordre' => $ordre, 'noteMax' => $noteMax, 'tempsVise' => $tempsVise, 'description' => $description, 'image_url' => $image_url, 'cat_id' => $cat_id);
}

function td_epreuve_cats_all($conn) {
    $out = array();
    foreach ($conn->query("SELECT id, data FROM td_epreuve_categories")->fetchAll() as $r) {
        $j = json_decode($r['data'], true); if (!is_array($j)) $j = array();
        $out[] = array(
            'id'    => $r['id'],
            'label' => isset($j['label']) ? (string)$j['label'] : '',
            'color' => isset($j['color']) ? $j['color'] : null,
            'icon'  => isset($j['icon']) ? (string)$j['icon'] : '',
            'ordre' => (int)(isset($j['ordre']) ? $j['ordre'] : 0),
        );
    }
    usort($out, function ($a, $b) { return $a['ordre'] - $b['ordre']; });
    return $out;
}

if ($action === 'epreuves_list') {
    echo json_encode(array('success' => true, 'epreuves' => td_epreuves_all($conn)));
    exit;
}

if ($action === 'epreuve_save') {
    mdt_post_only();
    td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400');
    if (!is_array($data)) $data = array();
    $id = (isset($data['id']) && $data['id'] !== '') ? substr((string)$data['id'], 0, 50) : null;
    if ($id) {
        $st = $conn->prepare("SELECT data FROM td_epreuves WHERE id = ? LIMIT 1"); $st->execute(array($id));
        $ex = $st->fetchColumn();
        if ($ex === false) mdt_error(404, 'E-TD-404', 'Épreuve introuvable');
        $exj = json_decode($ex, true);
        $p = td_epreuve_payload($conn, $data, is_array($exj) && isset($exj['ordre']) ? $exj['ordre'] : 0);
        $p['id'] = $id;
        $conn->prepare("UPDATE td_epreuves SET data = ? WHERE id = ?")->execute(array(json_encode($p, JSON_UNESCAPED_UNICODE), $id));
        echo json_encode(array('success' => true, 'id' => $id));
        exit;
    }
    $n = (int)$conn->query("SELECT COUNT(*) FROM td_epreuves")->fetchColumn();
    if ($n >= 200) mdt_error(429, 'E-TD-429', 'Trop d\'épreuves (max 200)');
    $nid = uniqid('ep_');
    $p = td_epreuve_payload($conn, $data, $n);
    $p['id'] = $nid;
    $conn->prepare("INSERT INTO td_epreuves (id, data) VALUES (?, ?)")->execute(array($nid, json_encode($p, JSON_UNESCAPED_UNICODE)));
    echo json_encode(array('success' => true, 'id' => $nid));
    exit;
}

if ($action === 'epreuve_delete') {
    mdt_post_only();
    td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400');
    $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->prepare("DELETE FROM td_epreuves WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'epreuve_reorder') {
    mdt_post_only();
    td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400');
    $order = (is_array($data) && isset($data['order']) && is_array($data['order'])) ? $data['order'] : array();
    if (count($order) > 500) mdt_error(400, 'E-TD-400', 'Trop d\'éléments');
    $sel = $conn->prepare("SELECT data FROM td_epreuves WHERE id = ? LIMIT 1");
    $upd = $conn->prepare("UPDATE td_epreuves SET data = ? WHERE id = ?");
    $i = 0;
    foreach ($order as $eid) {
        $eid = substr((string)$eid, 0, 50);
        $sel->execute(array($eid)); $ex = $sel->fetchColumn();
        if ($ex === false) continue;
        $j = json_decode($ex, true); if (!is_array($j)) $j = array();
        $j['ordre'] = $i++;
        $upd->execute(array(json_encode($j, JSON_UNESCAPED_UNICODE), $eid));
    }
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'epreuve_cats_list') {
    echo json_encode(array('success' => true, 'categories' => td_epreuve_cats_all($conn)), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'epreuve_cat_save') {
    mdt_post_only(); td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $label = mb_substr(trim((string)(isset($data['label']) ? $data['label'] : '')), 0, 120);
    if ($label === '') mdt_error(400, 'E-TD-400', 'Nom requis');
    $p = array('label' => $label, 'color' => fl_safe_color(isset($data['color']) ? $data['color'] : ''), 'icon' => mb_substr(trim((string)(isset($data['icon']) ? $data['icon'] : '')), 0, 60));
    $id = (isset($data['id']) && $data['id'] !== '') ? substr((string)$data['id'], 0, 50) : null;
    if ($id) {
        $st = $conn->prepare("SELECT data FROM td_epreuve_categories WHERE id = ? LIMIT 1"); $st->execute(array($id));
        $ex = $st->fetchColumn(); if ($ex === false) mdt_error(404, 'E-TD-404', 'Catégorie introuvable');
        $exj = json_decode($ex, true); $p['ordre'] = (is_array($exj) && isset($exj['ordre'])) ? (int)$exj['ordre'] : 0; $p['id'] = $id;
        $conn->prepare("UPDATE td_epreuve_categories SET data = ? WHERE id = ?")->execute(array(json_encode($p, JSON_UNESCAPED_UNICODE), $id));
        echo json_encode(array('success' => true, 'id' => $id)); exit;
    }
    $n = (int)$conn->query("SELECT COUNT(*) FROM td_epreuve_categories")->fetchColumn();
    if ($n >= 100) mdt_error(429, 'E-TD-429', 'Trop de catégories');
    $nid = uniqid('epc_'); $p['ordre'] = $n; $p['id'] = $nid;
    $conn->prepare("INSERT INTO td_epreuve_categories (id, data) VALUES (?, ?)")->execute(array($nid, json_encode($p, JSON_UNESCAPED_UNICODE)));
    echo json_encode(array('success' => true, 'id' => $nid)); exit;
}
if ($action === 'epreuve_cat_delete') {
    mdt_post_only(); td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400'); $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->prepare("DELETE FROM td_epreuve_categories WHERE id = ?")->execute(array($id));

    $sel = $conn->prepare("SELECT data FROM td_epreuves WHERE id = ? LIMIT 1");
    $upd = $conn->prepare("UPDATE td_epreuves SET data = ? WHERE id = ?");
    foreach (td_epreuves_all($conn) as $ep) {
        if ($ep['cat_id'] === $id) {
            $sel->execute(array($ep['id'])); $ex = $sel->fetchColumn(); if ($ex === false) continue;
            $j = json_decode($ex, true); if (!is_array($j)) $j = array(); $j['cat_id'] = '';
            $upd->execute(array(json_encode($j, JSON_UNESCAPED_UNICODE), $ep['id']));
        }
    }
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'epreuve_cat_reorder') {
    mdt_post_only(); td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400');
    $order = (is_array($data) && isset($data['order']) && is_array($data['order'])) ? $data['order'] : array();
    if (count($order) > 200) mdt_error(400, 'E-TD-400', 'Trop d\'éléments');
    $sel = $conn->prepare("SELECT data FROM td_epreuve_categories WHERE id = ? LIMIT 1");
    $upd = $conn->prepare("UPDATE td_epreuve_categories SET data = ? WHERE id = ?");
    $i = 0;
    foreach ($order as $cid) {
        $cid = substr((string)$cid, 0, 50);
        $sel->execute(array($cid)); $ex = $sel->fetchColumn(); if ($ex === false) continue;
        $j = json_decode($ex, true); if (!is_array($j)) $j = array(); $j['ordre'] = $i++;
        $upd->execute(array(json_encode($j, JSON_UNESCAPED_UNICODE), $cid));
    }
    echo json_encode(array('success' => true)); exit;
}

function td_session_clean($data, $ownerDid, $rev) {
    $VERDICTS = array('', 'OUI', 'NON', 'NON PEUT REPASSER', 'OUI MAIS...');
    $recrues = array();
    if (isset($data['recrues']) && is_array($data['recrues'])) {
        $slice = array_slice($data['recrues'], 0, 150);
        foreach ($slice as $i => $r) {
            if (!is_array($r)) continue;
            $res = array(); $k = 0;
            if (isset($r['resultats']) && is_array($r['resultats'])) {
                foreach ($r['resultats'] as $epId => $cell) {
                    if ($k++ >= 60) break;
                    $epId = substr((string)$epId, 0, 50);
                    if (!is_array($cell)) $cell = array();
                    $c = array('remarque' => isset($cell['remarque']) ? mb_substr((string)$cell['remarque'], 0, 2000) : '');
                    if (isset($cell['note']) && $cell['note'] !== '') $c['note'] = mb_substr((string)$cell['note'], 0, 40);
                    if (isset($cell['temps']) && $cell['temps'] !== '') $c['temps'] = mb_substr((string)$cell['temps'], 0, 40);
                    $res[$epId] = $c;
                }
            }
            $verdict = (isset($r['verdict']) && in_array($r['verdict'], $VERDICTS, true)) ? $r['verdict'] : '';
            $recrues[] = array(
                'numero'    => (int)(isset($r['numero']) ? $r['numero'] : ($i + 1)),
                'nom'       => mb_substr(trim((string)(isset($r['nom']) ? $r['nom'] : '')), 0, 80),
                'prenom'    => mb_substr(trim((string)(isset($r['prenom']) ? $r['prenom'] : '')), 0, 80),
                'resultats' => (object)$res,
                'verdict'   => $verdict,
            );
        }
    }
    return array(
        'promo'   => mb_substr(trim((string)(isset($data['promo']) ? $data['promo'] : '')), 0, 120),
        'date'    => mb_substr((string)(isset($data['date']) ? $data['date'] : ''), 0, 20),
        'locked'  => !empty($data['locked']),
        'recrues' => $recrues,
        '_owner'  => (string)$ownerDid,
        '_rev'    => (int)$rev,
    );
}
function td_session_verdicts($recrues) {
    $vc = array('OUI' => 0, 'NON' => 0, 'REP' => 0, 'MAIS' => 0, 'pending' => 0);
    foreach ((is_array($recrues) ? $recrues : array()) as $r) {
        $v = isset($r['verdict']) ? $r['verdict'] : '';
        if ($v === 'OUI') $vc['OUI']++;
        elseif ($v === 'NON') $vc['NON']++;
        elseif ($v === 'NON PEUT REPASSER') $vc['REP']++;
        elseif ($v === 'OUI MAIS...') $vc['MAIS']++;
        else $vc['pending']++;
    }
    return $vc;
}

if ($action === 'sessions_list') {
    $out = array();
    foreach ($conn->query("SELECT id, data, created_at FROM td_sessions")->fetchAll() as $row) {
        $j = json_decode($row['data'], true); if (!is_array($j)) $j = array();
        $recs = (isset($j['recrues']) && is_array($j['recrues'])) ? $j['recrues'] : array();
        $owner = isset($j['_owner']) ? (string)$j['_owner'] : '';
        $out[] = array(
            'id'         => $row['id'],
            'promo'      => isset($j['promo']) ? (string)$j['promo'] : '',
            'date'       => isset($j['date']) ? (string)$j['date'] : '',
            'locked'     => !empty($j['locked']),
            'nb_recrues' => count($recs),
            'verdicts'   => td_session_verdicts($recs),
            'owner_name' => $owner !== '' ? td_me_name($conn, $owner) : '',
            'mine'       => ($owner !== '' && $owner === $did),
        );
    }
    usort($out, function ($a, $b) { return strcmp((string)$b['date'], (string)$a['date']); });
    echo json_encode(array('success' => true, 'sessions' => $out));
    exit;
}

if ($action === 'session_get') {
    $id = isset($_GET['id']) ? substr((string)$_GET['id'], 0, 50) : '';
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $st = $conn->prepare("SELECT data FROM td_sessions WHERE id = ? LIMIT 1"); $st->execute(array($id));
    $ex = $st->fetchColumn();
    if ($ex === false) mdt_error(404, 'E-TD-404', 'Session introuvable');
    $j = json_decode($ex, true); if (!is_array($j)) $j = array();
    $owner = isset($j['_owner']) ? (string)$j['_owner'] : '';
    $j['id'] = $id;
    if (!isset($j['_rev'])) $j['_rev'] = 0;
    $j['can_edit'] = ($isAdmin || $owner === '' || $owner === $did);
    $j['owner_name'] = $owner !== '' ? td_me_name($conn, $owner) : '';
    if (!isset($j['recrues']) || !is_array($j['recrues'])) $j['recrues'] = array();
    echo json_encode($j, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'session_save') {
    mdt_post_only();
    $data = mdt_get_post_data('E-TD-400');
    if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) mdt_error(400, 'E-TD-400', 'Identifiant de session invalide');
    $st = $conn->prepare("SELECT data FROM td_sessions WHERE id = ? LIMIT 1"); $st->execute(array($id));
    $ex = $st->fetchColumn();
    if ($ex === false) {
        $n = (int)$conn->query("SELECT COUNT(*) FROM td_sessions")->fetchColumn();
        if ($n >= 5000) mdt_error(429, 'E-TD-429', 'Trop de sessions');
        $blob = td_session_clean($data, $did, 1); $blob['id'] = $id;
        $conn->prepare("INSERT INTO td_sessions (id, data) VALUES (?, ?)")->execute(array($id, json_encode($blob, JSON_UNESCAPED_UNICODE)));
        echo json_encode(array('success' => true, 'id' => $id, '_rev' => 1));
        exit;
    }
    $exj = json_decode($ex, true); if (!is_array($exj)) $exj = array();
    $owner = isset($exj['_owner']) ? (string)$exj['_owner'] : '';
    if (!$isAdmin && $owner !== '' && $owner !== $did) mdt_error(403, 'E-TD-403', 'Seul le créateur (ou un admin) peut modifier cette session');
    $storedRev = (int)(isset($exj['_rev']) ? $exj['_rev'] : 0);
    $clientRev = (int)(isset($data['_rev']) ? $data['_rev'] : 0);
    if ($storedRev !== $clientRev) mdt_error(409, 'E-TD-409', 'Cette session a été modifiée entre-temps. Recharge avant d\'enregistrer.');
    $newOwner = ($owner !== '') ? $owner : $did;
    $blob = td_session_clean($data, $newOwner, $storedRev + 1); $blob['id'] = $id;
    $conn->prepare("UPDATE td_sessions SET data = ? WHERE id = ?")->execute(array(json_encode($blob, JSON_UNESCAPED_UNICODE), $id));
    echo json_encode(array('success' => true, 'id' => $id, '_rev' => $storedRev + 1));
    exit;
}

if ($action === 'session_delete') {
    mdt_post_only();
    $data = mdt_get_post_data('E-TD-400');
    $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $st = $conn->prepare("SELECT data FROM td_sessions WHERE id = ? LIMIT 1"); $st->execute(array($id));
    $ex = $st->fetchColumn();
    if ($ex === false) { echo json_encode(array('success' => true)); exit; }
    $exj = json_decode($ex, true);
    $owner = (is_array($exj) && isset($exj['_owner'])) ? (string)$exj['_owner'] : '';
    if (!$isAdmin && $owner !== '' && $owner !== $did) mdt_error(403, 'E-TD-403', 'Seul le créateur (ou un admin) peut supprimer cette session');
    $conn->prepare("DELETE FROM td_sessions WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true));
    exit;
}

function td_active_criteria($conn) {
    $out = array();
    foreach ($conn->query("SELECT id, nom, ordre FROM td_notation_criteria WHERE actif = 1 ORDER BY ordre, nom")->fetchAll() as $r)
        $out[] = array('id' => $r['id'], 'nom' => $r['nom'], 'ordre' => (int)$r['ordre']);
    return $out;
}

function td_fiche_merge($row) {
    $g = function ($k, $d = null) use ($row) { return isset($row[$k]) ? $row[$k] : $d; };
    $j = json_decode((string)$g('data', ''), true); if (!is_array($j)) $j = array();
    return array(
        'id' => $g('id'),
        'cadet' => (string)$g('cadet', ''), 'referent' => (string)$g('referent', ''),
        'date_arrivee' => $g('date_arrivee'), 'ppa' => ((int)$g('ppa', 0) ? true : false),
        'locked' => ((int)$g('locked', 0) ? true : false), 'rev' => (int)$g('rev', 0),
        'notes_suivi' => (isset($j['notes_suivi']) && is_array($j['notes_suivi'])) ? array_values($j['notes_suivi']) : array(),
        'notations' => (isset($j['notations']) && is_array($j['notations'])) ? $j['notations'] : array(),
        'bareme_snapshot' => (isset($j['bareme_snapshot']) && is_array($j['bareme_snapshot'])) ? $j['bareme_snapshot'] : null,
        'created_by' => (string)$g('created_by', ''), 'created_at' => $g('created_at'), 'updated_at' => $g('updated_at'),
    );
}
function td_fiche_verdict($fiche, $criteria) {
    $total = count($criteria); $validated = 0;
    $notations = (isset($fiche['notations']) && is_array($fiche['notations'])) ? $fiche['notations'] : array();
    foreach ($criteria as $cr) { $cid = $cr['id']; if (isset($notations[$cid]) && !empty($notations[$cid]['validee'])) $validated++; }
    $MAX_ECHECS = 3; $minimum = max(0, $total - $MAX_ECHECS);
    $pass = ($validated >= $minimum) && !empty($fiche['ppa']);
    return array('total' => $total, 'validated' => $validated, 'minimum' => $minimum, 'max_echecs' => $MAX_ECHECS, 'ppa' => !empty($fiche['ppa']), 'pass' => $pass);
}
function td_fiche_apply($conn, $id, $did, $requireUnlocked, $mutate) {
    $conn->beginTransaction();
    try {
        $st = $conn->prepare("SELECT * FROM td_fiches WHERE id = ? LIMIT 1 FOR UPDATE");
        $st->execute(array($id));
        $row = $st->fetch();
        if (!$row) { $conn->rollBack(); mdt_error(404, 'E-TD-404', 'Fiche introuvable'); }
        if ($requireUnlocked && (int)$row['locked']) { $conn->rollBack(); mdt_error(409, 'E-TD-409', 'Fiche verrouillée — rouvre-la pour la modifier'); }
        $f = td_fiche_merge($row);
        $mutate($f);
        $data = array('notes_suivi' => array_values($f['notes_suivi']), 'notations' => (object)$f['notations']);
        if ($f['bareme_snapshot'] !== null) $data['bareme_snapshot'] = $f['bareme_snapshot'];
        $date = ($f['date_arrivee'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['date_arrivee'])) ? $f['date_arrivee'] : null;
        $conn->prepare("UPDATE td_fiches SET cadet=?, referent=?, date_arrivee=?, ppa=?, locked=?, rev=rev+1, updated_by=?, data=? WHERE id=?")
            ->execute(array(
                $f['cadet'] !== '' ? mb_substr($f['cadet'], 0, 120) : null,
                $f['referent'] !== '' ? mb_substr($f['referent'], 0, 120) : null,
                $date, $f['ppa'] ? 1 : 0, $f['locked'] ? 1 : 0, (string)$did,
                json_encode($data, JSON_UNESCAPED_UNICODE), $id
            ));
        $conn->commit();
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}

if ($action === 'fiches_list') {
    $active = td_active_criteria($conn);
    $out = array();
    foreach ($conn->query("SELECT id,cadet,referent,date_arrivee,ppa,locked,data,updated_at FROM td_fiches")->fetchAll() as $row) {
        $f = td_fiche_merge($row);
        $crit = ($f['locked'] && $f['bareme_snapshot']) ? $f['bareme_snapshot'] : $active;
        $v = td_fiche_verdict($f, $crit);
        $out[] = array('id' => $f['id'], 'cadet' => $f['cadet'], 'referent' => $f['referent'], 'date_arrivee' => $f['date_arrivee'], 'ppa' => $f['ppa'], 'locked' => $f['locked'], 'validated' => $v['validated'], 'total' => $v['total'], 'pass' => $v['pass'], 'updated_at' => $row['updated_at']);
    }
    usort($out, function ($a, $b) { if ($a['locked'] !== $b['locked']) return $a['locked'] ? 1 : -1; return strcmp((string)$b['date_arrivee'], (string)$a['date_arrivee']); });
    echo json_encode(array('success' => true, 'fiches' => $out)); exit;
}
if ($action === 'fiche_get') {
    $id = isset($_GET['id']) ? substr((string)$_GET['id'], 0, 50) : '';
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $st = $conn->prepare("SELECT * FROM td_fiches WHERE id = ? LIMIT 1"); $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) mdt_error(404, 'E-TD-404', 'Fiche introuvable');
    $f = td_fiche_merge($row);
    $crit = ($f['locked'] && $f['bareme_snapshot']) ? $f['bareme_snapshot'] : td_active_criteria($conn);
    $f['criteria'] = $crit;
    $f['verdict'] = td_fiche_verdict($f, $crit);
    $f['can_edit'] = $isInstructor && !$f['locked'];
    $f['can_lock'] = $isInstructor;
    $f['can_delete'] = $isAdmin;
    echo json_encode($f, JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'fiche_create') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $cadet = mb_substr(trim((string)(isset($data['cadet']) ? $data['cadet'] : '')), 0, 120);
    if ($cadet === '') mdt_error(400, 'E-TD-400', 'Le nom du cadet est requis');
    if ((int)$conn->query("SELECT COUNT(*) FROM td_fiches")->fetchColumn() >= 5000) mdt_error(429, 'E-TD-429', 'Trop de fiches');
    $id = uniqid('fiche_');
    $ref = mb_substr(trim((string)(isset($data['referent']) ? $data['referent'] : '')), 0, 120);
    $date = (isset($data['date_arrivee']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date_arrivee'])) ? $data['date_arrivee'] : null;
    $conn->prepare("INSERT INTO td_fiches (id,cadet,referent,date_arrivee,ppa,locked,rev,created_by,updated_by,data) VALUES (?,?,?,?,0,0,1,?,?,?)")
        ->execute(array($id, $cadet, $ref ?: null, $date, (string)$did, (string)$did, json_encode(array('notes_suivi' => array(), 'notations' => (object)array()), JSON_UNESCAPED_UNICODE)));
    echo json_encode(array('success' => true, 'id' => $id)); exit;
}
if ($action === 'fiche_meta') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    td_fiche_apply($conn, $id, $did, true, function (&$f) use ($data) {
        if (isset($data['cadet'])) { $c = mb_substr(trim((string)$data['cadet']), 0, 120); if ($c !== '') $f['cadet'] = $c; }
        if (isset($data['referent'])) $f['referent'] = mb_substr(trim((string)$data['referent']), 0, 120);
        if (isset($data['date_arrivee'])) $f['date_arrivee'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date_arrivee']) ? $data['date_arrivee'] : null;
        if (isset($data['ppa'])) $f['ppa'] = !empty($data['ppa']);
    });
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fiche_note_add') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $text = mb_substr(trim((string)(isset($data['text']) ? $data['text'] : '')), 0, 2000); if ($text === '') mdt_error(400, 'E-TD-400', 'Texte requis');
    $auteur = td_my_identity($conn, $did);
    td_fiche_apply($conn, $id, $did, true, function (&$f) use ($text, $auteur) {
        if (count($f['notes_suivi']) >= 500) return;
        $f['notes_suivi'][] = array('text' => $text, 'auteur' => $auteur, 'date' => date('Y-m-d H:i'));
    });
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fiche_note_delete') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $idx = (int)(isset($data['index']) ? $data['index'] : -1);
    td_fiche_apply($conn, $id, $did, true, function (&$f) use ($idx) { if ($idx >= 0 && $idx < count($f['notes_suivi'])) array_splice($f['notes_suivi'], $idx, 1); });
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fiche_notation_set') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50);
    $cid = substr((string)(isset($data['critere_id']) ? $data['critere_id'] : ''), 0, 50);
    if ($id === '' || $cid === '') mdt_error(400, 'E-TD-400', 'id/critère requis');
    $ck = $conn->prepare("SELECT 1 FROM td_notation_criteria WHERE id = ? AND actif = 1 LIMIT 1"); $ck->execute(array($cid));
    if (!$ck->fetch()) mdt_error(400, 'E-TD-400', 'Critère invalide');
    $validee = !empty($data['validee']); $meId = td_my_identity($conn, $did);
    td_fiche_apply($conn, $id, $did, true, function (&$f) use ($cid, $validee, $meId) {
        if (!isset($f['notations'][$cid]) && count($f['notations']) >= 300) return;
        if (!isset($f['notations'][$cid]) || !is_array($f['notations'][$cid])) $f['notations'][$cid] = array('validee' => false, 'evaluateur' => '', 'remarques' => array());
        if (!isset($f['notations'][$cid]['remarques']) || !is_array($f['notations'][$cid]['remarques'])) $f['notations'][$cid]['remarques'] = array();
        $f['notations'][$cid]['validee'] = $validee;
        $f['notations'][$cid]['evaluateur'] = $validee ? $meId : '';
    });
    echo json_encode(array('success' => true, 'evaluateur' => $validee ? $meId : '')); exit;
}
if ($action === 'fiche_notation_remark') {
    mdt_post_only();
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50);
    $cid = substr((string)(isset($data['critere_id']) ? $data['critere_id'] : ''), 0, 50);
    if ($id === '' || $cid === '') mdt_error(400, 'E-TD-400', 'id/critère requis');
    $ck = $conn->prepare("SELECT 1 FROM td_notation_criteria WHERE id = ? AND actif = 1 LIMIT 1"); $ck->execute(array($cid));
    if (!$ck->fetch()) mdt_error(400, 'E-TD-400', 'Critère invalide');
    $text = mb_substr(trim((string)(isset($data['text']) ? $data['text'] : '')), 0, 2000); if ($text === '') mdt_error(400, 'E-TD-400', 'Texte requis');
    $auteur = td_my_identity($conn, $did);
    td_fiche_apply($conn, $id, $did, true, function (&$f) use ($cid, $text, $auteur) {
        if (!isset($f['notations'][$cid]) && count($f['notations']) >= 300) return;
        if (!isset($f['notations'][$cid]) || !is_array($f['notations'][$cid])) $f['notations'][$cid] = array('validee' => false, 'evaluateur' => '', 'remarques' => array());
        if (!isset($f['notations'][$cid]['remarques']) || !is_array($f['notations'][$cid]['remarques'])) $f['notations'][$cid]['remarques'] = array();
        if (count($f['notations'][$cid]['remarques']) >= 100) return;
        $f['notations'][$cid]['remarques'][] = array('text' => $text, 'auteur' => $auteur, 'date' => date('Y-m-d H:i'));
    });
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fiche_lock') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $lock = !empty($data['locked']); $snapshot = $lock ? td_active_criteria($conn) : null;
    td_fiche_apply($conn, $id, $did, false, function (&$f) use ($lock, $snapshot) { $f['locked'] = $lock; if ($lock) $f['bareme_snapshot'] = $snapshot; });
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fiche_delete') {
    mdt_post_only();
    if (!$isAdmin) mdt_error(403, 'E-TD-403', 'Suppression réservée au commandement/dev');
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->prepare("DELETE FROM td_fiches WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true)); exit;
}

if ($action === 'criteria_list') {
    echo json_encode(array('success' => true, 'criteres' => td_active_criteria($conn))); exit;
}
if ($action === 'critere_save') {
    mdt_post_only(); if (!$isAdmin) mdt_error(403, 'E-TD-403', 'Réservé au commandement/dev');
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $nom = mb_substr(trim((string)(isset($data['nom']) ? $data['nom'] : '')), 0, 200); if ($nom === '') mdt_error(400, 'E-TD-400', 'Nom requis');
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id !== '') { $conn->prepare("UPDATE td_notation_criteria SET nom = ? WHERE id = ?")->execute(array($nom, $id)); echo json_encode(array('success' => true, 'id' => $id)); exit; }
    $n = (int)$conn->query("SELECT COUNT(*) FROM td_notation_criteria WHERE actif = 1")->fetchColumn();
    if ($n >= 200) mdt_error(429, 'E-TD-429', 'Trop de critères');
    $nid = uniqid('crit_');
    $conn->prepare("INSERT INTO td_notation_criteria (id,nom,ordre,actif,created_by) VALUES (?,?,?,1,?)")->execute(array($nid, $nom, $n, (string)$did));
    echo json_encode(array('success' => true, 'id' => $nid)); exit;
}
if ($action === 'critere_delete') {
    mdt_post_only(); if (!$isAdmin) mdt_error(403, 'E-TD-403', 'Réservé au commandement/dev');
    $data = mdt_get_post_data('E-TD-400'); $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->prepare("UPDATE td_notation_criteria SET actif = 0, deleted_at = NOW() WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'criteres_reorder') {
    mdt_post_only(); if (!$isAdmin) mdt_error(403, 'E-TD-403', 'Réservé au commandement/dev');
    $data = mdt_get_post_data('E-TD-400'); $order = (is_array($data) && isset($data['order']) && is_array($data['order'])) ? $data['order'] : array();
    if (count($order) > 500) mdt_error(400, 'E-TD-400', 'Trop d\'éléments');
    $u = $conn->prepare("UPDATE td_notation_criteria SET ordre = ? WHERE id = ?"); $i = 0;
    foreach ($order as $cid) { $u->execute(array($i++, substr((string)$cid, 0, 50))); }
    echo json_encode(array('success' => true)); exit;
}

if ($action === 'referents_list') {
    $active = td_active_criteria($conn);
    $groups = array();
    foreach ($conn->query("SELECT id,cadet,referent,date_arrivee,ppa,locked,data FROM td_fiches")->fetchAll() as $row) {
        $f = td_fiche_merge($row);
        $ref = $f['referent'] !== '' ? $f['referent'] : '— Sans référent —';
        $crit = ($f['locked'] && $f['bareme_snapshot']) ? $f['bareme_snapshot'] : $active;
        $v = td_fiche_verdict($f, $crit);
        if (!isset($groups[$ref])) $groups[$ref] = array('referent' => $ref, 'cadets' => array());
        $groups[$ref]['cadets'][] = array('id' => $f['id'], 'cadet' => $f['cadet'], 'locked' => $f['locked'], 'pass' => $v['pass'], 'validated' => $v['validated'], 'total' => $v['total']);
    }
    $out = array_values($groups);
    usort($out, function ($a, $b) { return strcmp($a['referent'], $b['referent']); });
    echo json_encode(array('success' => true, 'referents' => $out)); exit;
}
if ($action === 'roster_list') {
    $out = array();
    try { foreach ($conn->query("SELECT matricule, nom_prenom FROM roster ORDER BY ordre, matricule")->fetchAll() as $r) $out[] = ($r['matricule'] ? $r['matricule'] . ' | ' : '') . $r['nom_prenom']; } catch (Exception $e) {}
    echo json_encode(array('success' => true, 'roster' => $out)); exit;
}

function fl_type_full($conn, $typeId, $activeOnly) {
    $ao = $activeOnly ? ' AND active = 1' : '';
    $t = $conn->prepare("SELECT id, label, description, situation_mode, ordre, active, image_url FROM td_fl_types WHERE id = ? LIMIT 1");
    $t->execute(array($typeId)); $type = $t->fetch();
    if (!$type) return null;
    $sections = array();
    $sq = $conn->prepare("SELECT id, label, icon, color, max_pts, ordre, active, image_url FROM td_fl_sections WHERE type_id = ?$ao ORDER BY ordre, label");
    $sq->execute(array($typeId));
    $cq = $conn->prepare("SELECT id, label, description, max_pts, step, elim, elim_desc, ordre, active, image_url FROM td_fl_criteres WHERE section_id = ?$ao ORDER BY ordre, label");
    foreach ($sq->fetchAll() as $s) {
        $cq->execute(array($s['id']));
        $crits = array();
        foreach ($cq->fetchAll() as $cr) $crits[] = array('id' => $cr['id'], 'label' => $cr['label'], 'description' => $cr['description'], 'max_pts' => (float)$cr['max_pts'], 'step' => (float)$cr['step'], 'elim' => (int)$cr['elim'] ? true : false, 'elim_desc' => $cr['elim_desc'], 'ordre' => (int)$cr['ordre'], 'active' => (int)$cr['active'] ? true : false, 'image_url' => $cr['image_url']);
        $sections[] = array('id' => $s['id'], 'label' => $s['label'], 'icon' => $s['icon'], 'color' => $s['color'], 'max_pts' => (float)$s['max_pts'], 'ordre' => (int)$s['ordre'], 'active' => (int)$s['active'] ? true : false, 'image_url' => $s['image_url'], 'criteres' => $crits);
    }
    $cats = array();
    $qcq = $conn->prepare("SELECT id, label, color, ordre, active FROM td_fl_quest_categories WHERE type_id = ?$ao ORDER BY ordre, label");
    $qcq->execute(array($typeId));
    $qq = $conn->prepare("SELECT id, question, reponse, points, bonus, ordre, active, image_url FROM td_fl_questions WHERE category_id = ?$ao ORDER BY ordre, question");
    foreach ($qcq->fetchAll() as $ct) {
        $qq->execute(array($ct['id']));
        $qs = array();
        foreach ($qq->fetchAll() as $q) $qs[] = array('id' => $q['id'], 'question' => $q['question'], 'reponse' => $q['reponse'], 'points' => (int)$q['points'], 'bonus' => (int)$q['bonus'] ? true : false, 'ordre' => (int)$q['ordre'], 'active' => (int)$q['active'] ? true : false, 'image_url' => $q['image_url']);
        $cats[] = array('id' => $ct['id'], 'label' => $ct['label'], 'color' => $ct['color'], 'ordre' => (int)$ct['ordre'], 'active' => (int)$ct['active'] ? true : false, 'questions' => $qs);
    }
    $config = array();
    $cf = $conn->prepare("SELECT cfg_key, cfg_value FROM td_fl_config WHERE type_id = ?"); $cf->execute(array($typeId));
    foreach ($cf->fetchAll() as $r) $config[$r['cfg_key']] = $r['cfg_value'];
    return array('type' => array('id' => $type['id'], 'label' => $type['label'], 'description' => $type['description'], 'situation_mode' => $type['situation_mode'], 'active' => (int)$type['active'] ? true : false, 'image_url' => $type['image_url']), 'sections' => $sections, 'categories' => $cats, 'config' => $config);
}

function fl_ref_for_eval($conn, $row) {
    if (!empty($row['bareme_snapshot'])) { $j = json_decode($row['bareme_snapshot'], true); if (is_array($j) && isset($j['sections'])) return $j; }
    return fl_type_full($conn, $row['fl_type_id'], true);
}

function fl_compute($scores, $questionnaire, $ref, $daFailed) {
    if (!is_array($scores)) $scores = array();
    if (!is_array($questionnaire)) $questionnaire = array();
    $mode = isset($ref['type']['situation_mode']) ? $ref['type']['situation_mode'] : 'always';
    $cfg = isset($ref['config']) ? $ref['config'] : array();
    $seuil = isset($cfg['seuil_validation']) ? (float)$cfg['seuil_validation'] : 80;
    $qmax = isset($cfg['total_questionnaire_max']) ? (float)$cfg['total_questionnaire_max'] : 30;
    $prat = 0.0; $elim = array();
    foreach ($ref['sections'] as $sec) {
        foreach ($sec['criteres'] as $cr) {
            $cid = $cr['id'];
            if (isset($scores[$cid]) && is_numeric($scores[$cid])) {
                $v = (float)$scores[$cid]; $mp = (float)$cr['max_pts']; $step = (float)$cr['step'];
                $v = max(0.0, min($v, $mp)); if ($step > 0) $v = round($v / $step) * $step;
                $prat += $v;
            }
            if (!empty($cr['elim']) && isset($scores[$cid . '_elim']) && $scores[$cid . '_elim'] === true) $elim[] = $cr['label'];
        }
    }
    $quest = 0.0;
    foreach ($ref['categories'] as $cat) {
        foreach ($cat['questions'] as $q) { if (isset($questionnaire[$q['id']]) && $questionnaire[$q['id']] === true) $quest += (float)$q['points']; }
    }
    if ($quest > $qmax) $quest = $qmax;
    $pratiqueCompte = ($mode === 'always') || ($mode === 'conditional' && $daFailed);
    if (!$pratiqueCompte) { $prat = 0.0; $elim = array(); }
    $global = $pratiqueCompte ? ($prat + $quest) : $quest;
    return array('total_pratique' => round($prat, 1), 'total_questionnaire' => round($quest, 1), 'total_global' => round($global, 1), 'faute_eliminatoire' => count($elim) > 0, 'faute_detail' => implode(', ', $elim), 'seuil' => $seuil, 'pratique_compte' => $pratiqueCompte);
}
function fl_verdict($computed, $finalized) {
    if (!$finalized) return 'en_cours';
    if (!empty($computed['faute_eliminatoire'])) return 'eliminatoire';
    return ($computed['total_global'] >= $computed['seuil']) ? 'reussi' : 'echoue';
}

function fl_clean_scores($scores, $ref) {
    if (!is_array($scores)) return array();
    $valid = array();
    foreach ($ref['sections'] as $sec) foreach ($sec['criteres'] as $cr) { $valid[$cr['id']] = $cr; }
    $out = array();
    foreach ($scores as $k => $v) {
        $base = (substr($k, -5) === '_elim') ? substr($k, 0, -5) : $k;
        if (!isset($valid[$base])) continue;
        if (substr($k, -5) === '_elim') { $out[$k] = ($v === true); }
        elseif (is_numeric($v)) { $out[$k] = 0 + $v; }
    }
    return $out;
}
function fl_clean_quest($q, $ref) {
    if (!is_array($q)) return array();
    $valid = array();
    foreach ($ref['categories'] as $cat) foreach ($cat['questions'] as $qq) $valid[$qq['id']] = true;
    $out = array();
    foreach ($q as $k => $v) { if (isset($valid[$k]) && $v === true) $out[$k] = true; }
    return $out;
}
function fl_row_out($conn, $row, $isInstructor, $isSenior, $isAdmin, $did) {
    $ref = fl_ref_for_eval($conn, $row);
    $scores = json_decode($row['scores'], true); $quest = json_decode($row['questionnaire'], true);
    $finalized = !empty($row['bareme_snapshot']);
    $owner = ($isAdmin || !$row['created_by'] || (string)$row['created_by'] === (string)$did);
    $comp = $ref ? fl_compute($scores, $quest, $ref, (int)$row['da_failed'] ? true : false) : array();
    return array(
        'id' => $row['id'], 'fiche_id' => $row['fiche_id'], 'agent' => $row['agent'], 'evaluateur' => $row['evaluateur'],
        'date_eval' => $row['date_eval'], 'vehicule' => $row['vehicule'], 'fl_type_id' => $row['fl_type_id'],
        'da_failed' => (int)$row['da_failed'] ? true : false, 'notes' => $row['notes'],
        'scores' => is_array($scores) ? $scores : array(), 'questionnaire' => is_array($quest) ? $quest : array(),
        'finalized' => $finalized, 'resultat' => $row['resultat'], 'rev' => (int)$row['rev'],
        'computed' => $comp, 'verdict_live' => $ref ? fl_verdict($comp, true) : 'en_cours',
        'referentiel' => $ref, 'type_label' => $ref ? $ref['type']['label'] : $row['fl_type_id'],
        'is_owner' => $owner,
        'can_edit' => ($isInstructor && $owner && !$finalized),
        'can_finalize' => ($isSenior && $owner),
        'can_delete' => $isAdmin,
    );
}

if ($action === 'fl_list') {
    td_require_instructor($isInstructor);
    $out = array();
    foreach ($conn->query("SELECT * FROM td_first_lincoln ORDER BY date_eval DESC, created_at DESC")->fetchAll() as $row) {
        $ref = fl_ref_for_eval($conn, $row);
        $comp = $ref ? fl_compute(json_decode($row['scores'], true), json_decode($row['questionnaire'], true), $ref, (int)$row['da_failed'] ? true : false) : array();
        $fin = !empty($row['bareme_snapshot']);
        $out[] = array('id' => $row['id'], 'agent' => $row['agent'], 'evaluateur' => $row['evaluateur'], 'date_eval' => $row['date_eval'], 'fl_type_id' => $row['fl_type_id'], 'type_label' => $ref ? $ref['type']['label'] : $row['fl_type_id'], 'finalized' => $fin, 'resultat' => $fin ? $row['resultat'] : 'en_cours', 'verdict_live' => $ref ? fl_verdict($comp, true) : 'en_cours', 'total_global' => isset($comp['total_global']) ? $comp['total_global'] : 0, 'seuil' => isset($comp['seuil']) ? $comp['seuil'] : 0);
    }
    echo json_encode(array('success' => true, 'evals' => $out)); exit;
}
if ($action === 'fl_get') {
    td_require_instructor($isInstructor);
    $id = isset($_GET['id']) ? substr((string)$_GET['id'], 0, 50) : '';
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $st = $conn->prepare("SELECT * FROM td_first_lincoln WHERE id = ? LIMIT 1"); $st->execute(array($id));
    $row = $st->fetch(); if (!$row) mdt_error(404, 'E-TD-404', 'Évaluation introuvable');
    echo json_encode(fl_row_out($conn, $row, $isInstructor, $isSenior, $isAdmin, $did), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'fl_types_active') {
    td_require_instructor($isInstructor);
    $out = array();
    foreach ($conn->query("SELECT id, label, description, situation_mode, image_url FROM td_fl_types WHERE active = 1 ORDER BY ordre, label")->fetchAll() as $r) $out[] = $r;
    echo json_encode(array('success' => true, 'types' => $out)); exit;
}
if ($action === 'fl_types_all') {
    td_require_instructor($isInstructor);
    $out = array();
    $qSec = $conn->prepare("SELECT COUNT(*) FROM td_fl_sections WHERE type_id = ? AND active = 1");
    $qCri = $conn->prepare("SELECT COUNT(*) FROM td_fl_criteres c JOIN td_fl_sections s ON s.id = c.section_id WHERE s.type_id = ? AND s.active = 1 AND c.active = 1");
    $qQ   = $conn->prepare("SELECT COUNT(*) FROM td_fl_questions q JOIN td_fl_quest_categories k ON k.id = q.category_id WHERE k.type_id = ? AND k.active = 1 AND q.active = 1");
    $qE   = $conn->prepare("SELECT COUNT(*) FROM td_first_lincoln WHERE fl_type_id = ?");
    foreach ($conn->query("SELECT id, label, description, situation_mode, ordre, active, image_url FROM td_fl_types ORDER BY ordre, label")->fetchAll() as $t) {
        $qSec->execute(array($t['id'])); $qCri->execute(array($t['id'])); $qQ->execute(array($t['id'])); $qE->execute(array($t['id']));
        $out[] = array('id' => $t['id'], 'label' => $t['label'], 'description' => $t['description'], 'situation_mode' => $t['situation_mode'], 'active' => (int)$t['active'] ? true : false, 'image_url' => $t['image_url'], 'n_sections' => (int)$qSec->fetchColumn(), 'n_criteres' => (int)$qCri->fetchColumn(), 'n_questions' => (int)$qQ->fetchColumn(), 'n_evals' => (int)$qE->fetchColumn());
    }
    echo json_encode(array('success' => true, 'types' => $out), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'fl_type_active') {
    mdt_post_only(); td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $act = !empty($data['active']) ? 1 : 0;
    $conn->prepare("UPDATE td_fl_types SET active = ? WHERE id = ?")->execute(array($act, $id));
    echo json_encode(array('success' => true, 'active' => $act ? true : false)); exit;
}
if ($action === 'fl_create') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $typeId = substr((string)(isset($data['fl_type_id']) ? $data['fl_type_id'] : ''), 0, 50);
    $tc = $conn->prepare("SELECT 1 FROM td_fl_types WHERE id = ? AND active = 1 LIMIT 1"); $tc->execute(array($typeId));
    if (!$tc->fetch()) mdt_error(400, 'E-TD-400', 'Type d\'évaluation invalide');
    $agent = mb_substr(trim((string)(isset($data['agent']) ? $data['agent'] : '')), 0, 100);
    if ($agent === '') mdt_error(400, 'E-TD-400', 'Agent (cadet) requis');
    if ((int)$conn->query("SELECT COUNT(*) FROM td_first_lincoln")->fetchColumn() >= 20000) mdt_error(429, 'E-TD-429', 'Trop d\'évaluations');
    $id = uniqid('fl_'); $evalu = td_my_identity($conn, $did);
    $fiche = mb_substr(trim((string)(isset($data['fiche_id']) ? $data['fiche_id'] : '')), 0, 50);
    $veh = mb_substr(trim((string)(isset($data['vehicule']) ? $data['vehicule'] : '')), 0, 50);
    $conn->prepare("INSERT INTO td_first_lincoln (id, fiche_id, agent, evaluateur, date_eval, vehicule, scores, questionnaire, total_pratique, total_questionnaire, total_global, faute_eliminatoire, resultat, fl_type_id, da_failed, rev, created_by, updated_by) VALUES (?,?,?,?,CURDATE(),?,?,?,0,0,0,0,'en_cours',?,0,1,?,?)")
        ->execute(array($id, $fiche, $agent, $evalu, $veh ?: null, '{}', '{}', $typeId, (string)$did, (string)$did));
    echo json_encode(array('success' => true, 'id' => $id)); exit;
}
if ($action === 'fl_save') {
    mdt_post_only(); td_require_instructor($isInstructor);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->beginTransaction();
    try {
        $st = $conn->prepare("SELECT * FROM td_first_lincoln WHERE id = ? LIMIT 1 FOR UPDATE"); $st->execute(array($id));
        $row = $st->fetch();
        if (!$row) { $conn->rollBack(); mdt_error(404, 'E-TD-404', 'Évaluation introuvable'); }
        if (!$isAdmin && $row['created_by'] && (string)$row['created_by'] !== (string)$did) { $conn->rollBack(); mdt_error(403, 'E-TD-403', 'Seul le créateur (ou un admin) peut noter cette passation'); }
        if (!empty($row['bareme_snapshot'])) { $conn->rollBack(); mdt_error(409, 'E-TD-409', 'Évaluation finalisée — rouvre-la pour modifier'); }
        $rev = isset($data['_rev']) ? (int)$data['_rev'] : null;
        if ($rev !== null && (int)$row['rev'] !== $rev) { $conn->rollBack(); mdt_error(409, 'E-TD-409', 'Modifiée entre-temps — recharge la passation'); }
        $ref = fl_type_full($conn, $row['fl_type_id'], true);
        if (!$ref) { $conn->rollBack(); mdt_error(500, 'E-TD-500', 'Référentiel introuvable'); }
        $daFailed = isset($data['da_failed']) ? !empty($data['da_failed']) : ((int)$row['da_failed'] ? true : false);
        $scores = fl_clean_scores(isset($data['scores']) ? $data['scores'] : json_decode($row['scores'], true), $ref);
        $quest = fl_clean_quest(isset($data['questionnaire']) ? $data['questionnaire'] : json_decode($row['questionnaire'], true), $ref);
        $comp = fl_compute($scores, $quest, $ref, $daFailed);
        $agent = isset($data['agent']) ? mb_substr(trim((string)$data['agent']), 0, 100) : $row['agent'];
        $veh = isset($data['vehicule']) ? mb_substr(trim((string)$data['vehicule']), 0, 50) : $row['vehicule'];
        $notes = isset($data['notes']) ? mb_substr((string)$data['notes'], 0, 5000) : $row['notes'];
        $conn->prepare("UPDATE td_first_lincoln SET agent=?, vehicule=?, notes=?, scores=?, questionnaire=?, da_failed=?, total_pratique=?, total_questionnaire=?, total_global=?, faute_eliminatoire=?, faute_detail=?, resultat='en_cours', rev=rev+1, updated_by=? WHERE id=?")
            ->execute(array($agent ?: null, $veh ?: null, $notes, json_encode((object)$scores, JSON_UNESCAPED_UNICODE), json_encode((object)$quest, JSON_UNESCAPED_UNICODE), $daFailed ? 1 : 0, $comp['total_pratique'], $comp['total_questionnaire'], $comp['total_global'], $comp['faute_eliminatoire'] ? 1 : 0, $comp['faute_detail'], (string)$did, $id));
        $conn->commit();
        echo json_encode(array('success' => true, 'computed' => $comp, 'rev' => (int)$row['rev'] + 1)); exit;
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}
if ($action === 'fl_finalize') {
    mdt_post_only(); td_require_senior($isSenior);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->beginTransaction();
    try {
        $st = $conn->prepare("SELECT * FROM td_first_lincoln WHERE id = ? LIMIT 1 FOR UPDATE"); $st->execute(array($id));
        $row = $st->fetch(); if (!$row) { $conn->rollBack(); mdt_error(404, 'E-TD-404', 'Évaluation introuvable'); }
        if (!$isAdmin && $row['created_by'] && (string)$row['created_by'] !== (string)$did) { $conn->rollBack(); mdt_error(403, 'E-TD-403', 'Seul le créateur (ou un admin) peut finaliser cette passation'); }
        if (!empty($row['bareme_snapshot'])) { $conn->rollBack(); mdt_error(409, 'E-TD-409', 'Déjà finalisée — rouvre-la d\'abord'); }
        $ref = fl_type_full($conn, $row['fl_type_id'], true);
        if (!$ref) { $conn->rollBack(); mdt_error(500, 'E-TD-500', 'Référentiel introuvable'); }
        $comp = fl_compute(json_decode($row['scores'], true), json_decode($row['questionnaire'], true), $ref, (int)$row['da_failed'] ? true : false);
        $verdict = fl_verdict($comp, true);
        $conn->prepare("UPDATE td_first_lincoln SET total_pratique=?, total_questionnaire=?, total_global=?, faute_eliminatoire=?, faute_detail=?, resultat=?, bareme_snapshot=?, rev=rev+1, updated_by=? WHERE id=?")
            ->execute(array($comp['total_pratique'], $comp['total_questionnaire'], $comp['total_global'], $comp['faute_eliminatoire'] ? 1 : 0, $comp['faute_detail'], $verdict, json_encode($ref, JSON_UNESCAPED_UNICODE), (string)$did, $id));
        $conn->commit();
        echo json_encode(array('success' => true, 'resultat' => $verdict, 'computed' => $comp)); exit;
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}
if ($action === 'fl_reopen') {
    mdt_post_only(); td_require_senior($isSenior);
    $data = mdt_get_post_data('E-TD-400'); $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->beginTransaction();
    try {
        $st = $conn->prepare("SELECT * FROM td_first_lincoln WHERE id = ? LIMIT 1 FOR UPDATE"); $st->execute(array($id));
        $row = $st->fetch(); if (!$row) { $conn->rollBack(); mdt_error(404, 'E-TD-404', 'Évaluation introuvable'); }
        if (!$isAdmin && $row['created_by'] && (string)$row['created_by'] !== (string)$did) { $conn->rollBack(); mdt_error(403, 'E-TD-403', 'Seul le créateur (ou un admin) peut rouvrir cette passation'); }
        $conn->prepare("UPDATE td_first_lincoln SET bareme_snapshot=NULL, resultat='en_cours', rev=rev+1, updated_by=? WHERE id=?")->execute(array((string)$did, $id));
        $conn->commit();
        echo json_encode(array('success' => true, 'rev' => (int)$row['rev'] + 1)); exit;
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}
if ($action === 'fl_delete') {
    mdt_post_only(); if (!$isAdmin) mdt_error(403, 'E-TD-403', 'Suppression réservée au commandement/dev');
    $data = mdt_get_post_data('E-TD-400'); $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 50);
    if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->prepare("DELETE FROM td_first_lincoln WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fl_type_full') {
    td_require_instructor($isInstructor);
    $typeId = isset($_GET['id']) ? substr((string)$_GET['id'], 0, 50) : '';
    if ($typeId === '') mdt_error(400, 'E-TD-400', 'id requis');
    $full = fl_type_full($conn, $typeId, false);
    if (!$full) mdt_error(404, 'E-TD-404', 'Type introuvable');
    echo json_encode(array('success' => true, 'referentiel' => $full), JSON_UNESCAPED_UNICODE); exit;
}

define('TD_UPLOAD_DIR', __DIR__ . '/../documents/uploads/');
define('TD_UPLOAD_URL', '/documents/uploads/');
function fl_safe_img($url) { $url = trim((string)$url); if ($url === '') return null; return preg_match('#^/documents/uploads/[A-Za-z0-9._-]+$#', $url) ? $url : null; }
function fl_safe_color($c) { $c = trim((string)$c); return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : null; }
function td_store_image($tmp, $mime) {
    $ext = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp');
    if (!isset($ext[$mime])) return array(false, 'Type image non supporté');
    $base = 'dimg_' . uniqid() . '_' . bin2hex(random_bytes(6));
    if (!is_dir(TD_UPLOAD_DIR)) @mkdir(TD_UPLOAD_DIR, 0775, true);
    $dim = @getimagesize($tmp);
    if ($dim && ($dim[0] > 6000 || $dim[1] > 6000)) return array(false, 'Image trop grande');
    if ($dim && ((int)$dim[0] * (int)$dim[1]) > 16000000) return array(false, 'Image trop grande (aire max 16 Mpx)');
    if ($mime === 'image/gif' || !function_exists('imagecreatetruecolor')) {
        $name = $base . '.' . $ext[$mime];
        return @move_uploaded_file($tmp, TD_UPLOAD_DIR . $name) ? array($name, null) : array(false, 'Echec');
    }
    $src = ($mime === 'image/jpeg') ? @imagecreatefromjpeg($tmp) : (($mime === 'image/png') ? @imagecreatefrompng($tmp) : @imagecreatefromwebp($tmp));
    if (!$src) return array(false, 'Image illisible');
    $w = imagesx($src); $h = imagesy($src); $max = 1600;
    if ($w > $max || $h > $max) { $r = min($max / $w, $max / $h); $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r)); $d = imagecreatetruecolor($nw, $nh); imagealphablending($d, false); imagesavealpha($d, true); imagecopyresampled($d, $src, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($src); $src = $d; }
    imagealphablending($src, false); imagesavealpha($src, true);
    $name = $base . '.webp';
    $ok = function_exists('imagewebp') ? @imagewebp($src, TD_UPLOAD_DIR . $name, 80) : false;
    if (!$ok) { $name = $base . '.jpg'; $ok = @imagejpeg($src, TD_UPLOAD_DIR . $name, 85); }
    imagedestroy($src);
    return $ok ? array($name, null) : array(false, 'Echec encodage');
}
if ($action === 'fl_image') {
    mdt_post_only(); td_require_manage($isAdmin);
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) mdt_error(400, 'E-TD-420', 'Aucun fichier');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) mdt_error(400, 'E-TD-421', 'Erreur upload');
    if ((int)$f['size'] > 10 * 1024 * 1024) mdt_error(400, 'E-TD-422', 'Image trop volumineuse (max 10 Mo)');
    if (!function_exists('finfo_open')) mdt_error(500, 'E-TD-500', 'finfo indisponible');
    $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
    $res = td_store_image($f['tmp_name'], $mime);
    if (!$res[0]) mdt_error(400, 'E-TD-423', $res[1] ?: 'Echec');
    @chmod(TD_UPLOAD_DIR . $res[0], 0644);
    echo json_encode(array('success' => true, 'url' => TD_UPLOAD_URL . $res[0])); exit;
}

$FL_ENT = array(
    'type'     => array('table' => 'td_fl_types',            'parent' => null,          'prefix' => 'flt_',  'req' => 'label'),
    'section'  => array('table' => 'td_fl_sections',         'parent' => 'type_id',     'prefix' => 'fls_',  'req' => 'label'),
    'critere'  => array('table' => 'td_fl_criteres',         'parent' => 'section_id',  'prefix' => 'flc_',  'req' => 'label'),
    'qcat'     => array('table' => 'td_fl_quest_categories', 'parent' => 'type_id',     'prefix' => 'flq_',  'req' => 'label'),
    'question' => array('table' => 'td_fl_questions',        'parent' => 'category_id', 'prefix' => 'flqq_', 'req' => 'question'),
);
function fl_ent_fields($kind, $data) {
    $s = function ($k, $max) use ($data) { return isset($data[$k]) ? mb_substr(trim((string)$data[$k]), 0, $max) : null; };
    if ($kind === 'type')     return array('label' => $s('label', 120), 'description' => $s('description', 2000), 'situation_mode' => (in_array(isset($data['situation_mode']) ? $data['situation_mode'] : '', array('always', 'conditional', 'never'), true) ? $data['situation_mode'] : 'always'), 'image_url' => fl_safe_img(isset($data['image_url']) ? $data['image_url'] : null));
    if ($kind === 'section')  return array('label' => $s('label', 150), 'icon' => $s('icon', 60), 'color' => fl_safe_color(isset($data['color']) ? $data['color'] : ''), 'image_url' => fl_safe_img(isset($data['image_url']) ? $data['image_url'] : null));
    if ($kind === 'critere')  return array('label' => $s('label', 200), 'description' => $s('description', 2000), 'max_pts' => max(0, (float)(isset($data['max_pts']) ? $data['max_pts'] : 1)), 'step' => max(0.1, (float)(isset($data['step']) ? $data['step'] : 1)), 'elim' => !empty($data['elim']) ? 1 : 0, 'elim_desc' => $s('elim_desc', 255), 'image_url' => fl_safe_img(isset($data['image_url']) ? $data['image_url'] : null));
    if ($kind === 'qcat')     return array('label' => $s('label', 150), 'color' => fl_safe_color(isset($data['color']) ? $data['color'] : ''));
    if ($kind === 'question') return array('question' => $s('question', 500), 'reponse' => $s('reponse', 2000), 'points' => max(0, (int)(isset($data['points']) ? $data['points'] : 1)), 'bonus' => !empty($data['bonus']) ? 1 : 0, 'image_url' => fl_safe_img(isset($data['image_url']) ? $data['image_url'] : null));
    return array();
}
if ($action === 'fl_ent_save') {
    mdt_post_only(); td_require_manage($isAdmin);
    global $FL_ENT;
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $kind = isset($data['kind']) ? (string)$data['kind'] : '';
    if (!isset($FL_ENT[$kind])) mdt_error(400, 'E-TD-400', 'Type d\'entité invalide');
    $cfg = $FL_ENT[$kind]; $fields = fl_ent_fields($kind, $data);
    $req = $cfg['req'];
    if (!isset($fields[$req]) || $fields[$req] === '' || $fields[$req] === null) mdt_error(400, 'E-TD-400', 'Champ « ' . $req . ' » requis');
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 100);
    if ($id !== '') {
        $set = array(); $vals = array();
        foreach ($fields as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $id;
        $conn->prepare("UPDATE " . $cfg['table'] . " SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
        echo json_encode(array('success' => true, 'id' => $id)); exit;
    }

    $parentVal = null;
    if ($cfg['parent']) {
        $parentVal = substr((string)(isset($data['parent_id']) ? $data['parent_id'] : ''), 0, 100);
        if ($parentVal === '') mdt_error(400, 'E-TD-400', 'parent_id requis');
    }
    $cnt = (int)$conn->query("SELECT COUNT(*) FROM " . $cfg['table'] . ($cfg['parent'] ? " WHERE " . $cfg['parent'] . " = " . $conn->quote($parentVal) : ""))->fetchColumn();
    if ($cnt >= 2000) mdt_error(429, 'E-TD-429', 'Trop d\'éléments');
    $nid = uniqid($cfg['prefix']);
    $cols = array('id'); $ph = array('?'); $vals = array($nid);
    if ($cfg['parent']) { $cols[] = $cfg['parent']; $ph[] = '?'; $vals[] = $parentVal; }
    foreach ($fields as $k => $v) { $cols[] = $k; $ph[] = '?'; $vals[] = $v; }
    $cols[] = 'ordre'; $ph[] = '?'; $vals[] = $cnt;
    $cols[] = 'active'; $ph[] = '1';
    $conn->prepare("INSERT INTO " . $cfg['table'] . " (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")")->execute($vals);
    echo json_encode(array('success' => true, 'id' => $nid)); exit;
}
if ($action === 'fl_ent_delete') {
    mdt_post_only(); td_require_manage($isAdmin);
    global $FL_ENT;
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $kind = isset($data['kind']) ? (string)$data['kind'] : '';
    if (!isset($FL_ENT[$kind])) mdt_error(400, 'E-TD-400', 'Type d\'entité invalide');
    $id = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 100); if ($id === '') mdt_error(400, 'E-TD-400', 'id requis');
    $conn->prepare("UPDATE " . $FL_ENT[$kind]['table'] . " SET active = 0 WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fl_ent_reorder') {
    mdt_post_only(); td_require_manage($isAdmin);
    global $FL_ENT;
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $kind = isset($data['kind']) ? (string)$data['kind'] : '';
    if (!isset($FL_ENT[$kind])) mdt_error(400, 'E-TD-400', 'Type d\'entité invalide');
    $order = (isset($data['order']) && is_array($data['order'])) ? $data['order'] : array();
    if (count($order) > 1000) mdt_error(400, 'E-TD-400', 'Trop d\'éléments');
    $u = $conn->prepare("UPDATE " . $FL_ENT[$kind]['table'] . " SET ordre = ? WHERE id = ?"); $i = 0;
    foreach ($order as $oid) { $u->execute(array($i++, substr((string)$oid, 0, 100))); }
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fl_config_save') {
    mdt_post_only(); td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $typeId = substr((string)(isset($data['type_id']) ? $data['type_id'] : ''), 0, 50); if ($typeId === '') mdt_error(400, 'E-TD-400', 'type_id requis');
    $config = (isset($data['config']) && is_array($data['config'])) ? $data['config'] : array();
    $allowed = array('seuil_validation', 'total_pratique_max', 'total_questionnaire_max', 'total_global_max', 'question_points_standard', 'question_points_bonus');
    $up = $conn->prepare("INSERT INTO td_fl_config (type_id, cfg_key, cfg_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)");
    foreach ($config as $k => $v) { if (in_array($k, $allowed, true) && is_numeric($v)) $up->execute(array($typeId, $k, (string)(0 + $v))); }
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'fl_type_clone') {
    mdt_post_only(); td_require_manage($isAdmin);
    $data = mdt_get_post_data('E-TD-400'); if (!is_array($data)) $data = array();
    $src = substr((string)(isset($data['id']) ? $data['id'] : ''), 0, 50); if ($src === '') mdt_error(400, 'E-TD-400', 'id requis');
    $full = fl_type_full($conn, $src, false);
    if (!$full) mdt_error(404, 'E-TD-404', 'Type introuvable');
    $conn->beginTransaction();
    try {
        $newType = uniqid('flt_');
        $lbl = mb_substr((isset($data['label']) && trim((string)$data['label']) !== '') ? (string)$data['label'] : ($full['type']['label'] . ' (copie)'), 0, 120);
        $ord = (int)$conn->query("SELECT COUNT(*) FROM td_fl_types")->fetchColumn();
        $conn->prepare("INSERT INTO td_fl_types (id,label,description,situation_mode,ordre,active,image_url) VALUES (?,?,?,?,?,1,?)")
            ->execute(array($newType, $lbl, $full['type']['description'], $full['type']['situation_mode'], $ord, $full['type']['image_url']));
        foreach ($full['sections'] as $si => $sec) {
            $nsec = uniqid('fls_');
            $conn->prepare("INSERT INTO td_fl_sections (id,type_id,label,icon,color,max_pts,ordre,active,image_url) VALUES (?,?,?,?,?,?,?,1,?)")
                ->execute(array($nsec, $newType, $sec['label'], $sec['icon'], $sec['color'], $sec['max_pts'], $si, $sec['image_url']));
            foreach ($sec['criteres'] as $ci => $cr) {
                $conn->prepare("INSERT INTO td_fl_criteres (id,section_id,label,description,max_pts,step,elim,elim_desc,ordre,active,image_url) VALUES (?,?,?,?,?,?,?,?,?,1,?)")
                    ->execute(array(uniqid('flc_'), $nsec, $cr['label'], $cr['description'], $cr['max_pts'], $cr['step'], $cr['elim'] ? 1 : 0, $cr['elim_desc'], $ci, $cr['image_url']));
            }
        }
        foreach ($full['categories'] as $qi => $cat) {
            $ncat = uniqid('flq_');
            $conn->prepare("INSERT INTO td_fl_quest_categories (id,type_id,label,color,ordre,active) VALUES (?,?,?,?,?,1)")
                ->execute(array($ncat, $newType, $cat['label'], $cat['color'], $qi));
            foreach ($cat['questions'] as $qqi => $q) {
                $conn->prepare("INSERT INTO td_fl_questions (id,category_id,question,reponse,points,bonus,ordre,active,image_url) VALUES (?,?,?,?,?,?,?,1,?)")
                    ->execute(array(uniqid('flqq_'), $ncat, $q['question'], $q['reponse'], $q['points'], $q['bonus'] ? 1 : 0, $qqi, $q['image_url']));
            }
        }
        foreach ($full['config'] as $k => $v) $conn->prepare("INSERT INTO td_fl_config (type_id,cfg_key,cfg_value) VALUES (?,?,?)")->execute(array($newType, $k, $v));
        $conn->commit();
        echo json_encode(array('success' => true, 'id' => $newType)); exit;
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}

mdt_error(400, 'E-TD-400', 'Action inconnue');
