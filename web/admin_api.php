<?php

require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$action = isset($_GET['action']) ? $_GET['action'] : '';

$me = mdt_require_auth($conn, 'admin');
$did = isset($me['discord_id']) ? (string)$me['discord_id'] : '';
$isAdmin = mdt_is_admin($conn, $did);
if (!$isAdmin) mdt_error(403, 'E-ADM-403', 'Réservé à l\'administration');

$ADM_GRADES = array(
    '1210813640987377759' => 'Chief Of Police',
    '1485642111314432082' => 'Assistant Chief',
    '1485642087360757991' => 'Major',
    '1210813640987377762' => 'Commandant',
    '1210813640987377763' => 'Captain',
    '1210813641004294225' => 'Lieutenant',
    '1370086966628061258' => 'Sergeant II',
    '1210813641004294227' => 'Sergeant',
    '1210813641004294228' => 'Caporal',
    '1210813641004294229' => 'Master Trooper',
    '1210813641004294231' => 'Senior Trooper',
    '1370774891069968416' => 'Trooper III',
    '1370774221634146334' => 'Trooper II',
    '1210813641004294232' => 'Trooper I',
    '1210813641004294233' => 'Cadet',
);
$ADM_GRADE_ORDER = array_keys($ADM_GRADES);
$ADM_GRADE_POS = array_flip($ADM_GRADE_ORDER);

$ADM_QUALIFS = array(
    'P Secours' => '', 'SWAT' => '1210813641176383546', 'CID' => '1210813641176383547',
    'HP' => '1210813641176383548', 'HRT' => '1210813641302216820', 'TD' => '1210813641176383545',
    'NPU' => '1420186111426297876', 'SD' => '1370504638717104199', 'K9' => '1370017050877100032',
);

$ADM_SANCTIONS = array(
    array('code' => '1er Avert', 'color' => '#9ca3af'),
    array('code' => 'BLAME 1', 'color' => '#fca5a5'),
    array('code' => 'BLAME 2', 'color' => '#f87171'),
    array('code' => 'BLAME 3', 'color' => '#dc2626'),
    array('code' => 'MAP 24h', 'color' => '#fca5a5'),
    array('code' => 'MAP 48h', 'color' => '#f87171'),
    array('code' => 'MAP 72h', 'color' => '#dc2626'),
);

$ADM_ROLECONFIG = array(
    'td_instructor'   => array('label' => 'TD — Instructeur', 'hint' => 'Crée / édite ses passations & fiches', 'module' => 'td', 'href' => '/td/', 'fallback' => array('1210813641176383545', '1463655150152581151', '1384107706608259152')),
    'td_senior'       => array('label' => 'TD — Instructeur confirmé', 'hint' => 'Finalise / rouvre une passation (pas les stagiaires)', 'module' => 'td', 'href' => '/td/', 'fallback' => array('1210813641176383545', '1463655150152581151')),
    'annonces_manage' => array('label' => 'Annonces — Gestion', 'hint' => 'Diffusion descendante (MRD)', 'module' => 'annonces', 'href' => '/annonces/', 'fallback' => array('1432494299840250037')),
);
function adm_derive($rolesJson, $GRADES, $POS, $QUALIFS) {
    $roles = json_decode($rolesJson ?: '[]', true);
    if (!is_array($roles)) $roles = array();
    $roles = array_map('strval', $roles);
    $grade = null; $best = 999;
    foreach ($roles as $rid) { if (isset($POS[$rid]) && $POS[$rid] < $best) { $best = $POS[$rid]; $grade = $GRADES[$rid]; } }
    $q = array();
    foreach ($QUALIFS as $code => $rid) { $q[$code] = ($rid !== '' && in_array($rid, $roles, true)); }
    return array('grade' => $grade, 'grade_pos' => ($grade === null ? 999 : $best), 'qualifs' => $q);
}

function adm_sup_agent_migrate($conn) {
    static $done = false; if ($done) return; $done = true;
    try { $conn->query("SELECT pending_grade FROM sup_agent LIMIT 0"); }
    catch (Exception $e) { try { $conn->exec("ALTER TABLE sup_agent ADD COLUMN pending_grade VARCHAR(40) NULL DEFAULT NULL, ADD COLUMN pending_pos INT NULL DEFAULT NULL, ADD COLUMN pending_from VARCHAR(40) NULL DEFAULT NULL, ADD COLUMN grade_override VARCHAR(40) NULL DEFAULT NULL, ADD COLUMN override_pos INT NULL DEFAULT NULL"); } catch (Exception $e2) {} }
}

function adm_live_effectifs($conn, $GRADES, $POS, $QUALIFS) {
    adm_sup_agent_migrate($conn);
    $meta = array();
    foreach ($conn->query("SELECT matricule, date_arrivee, date_promo, pending_grade, pending_pos, pending_from, grade_override, override_pos FROM sup_agent")->fetchAll() as $m) $meta[$m['matricule']] = $m;
    $clrPend = $conn->prepare("UPDATE sup_agent SET pending_grade=NULL, pending_pos=NULL, pending_from=NULL WHERE matricule=?");
    $clrOver = $conn->prepare("UPDATE sup_agent SET grade_override=NULL, override_pos=NULL WHERE matricule=?");
    $out = array();
    $rows = $conn->query("SELECT r.matricule, r.nom_prenom, r.discord_id, r.ordre, u.discord_roles FROM roster r LEFT JOIN users u ON u.discord_id = r.discord_id ORDER BY r.matricule")->fetchAll();
    foreach ($rows as $r) {
        $d = adm_derive($r['discord_roles'], $GRADES, $POS, $QUALIFS);
        $mk = $r['matricule'];
        $mm = isset($meta[$mk]) ? $meta[$mk] : null;
        $grade = $d['grade']; $pos = $d['grade_pos']; $ancien = null;
        if ($mm) {
            if ($mm['pending_grade'] !== null && $mm['pending_grade'] === $d['grade']) { try { $clrPend->execute(array($mk)); } catch (Exception $e) {} $mm['pending_grade'] = null; }
            if ($mm['grade_override'] !== null && $mm['grade_override'] === $d['grade']) { try { $clrOver->execute(array($mk)); } catch (Exception $e) {} $mm['grade_override'] = null; }
            if ($mm['pending_grade'] !== null) { $grade = $mm['pending_grade']; $pos = (int)$mm['pending_pos']; $ancien = $mm['pending_from']; }
            elseif ($mm['grade_override'] !== null) { $grade = $mm['grade_override']; $pos = (int)$mm['override_pos']; }
        }
        $out[] = array(
            'matricule' => $mk, 'nom_prenom' => $r['nom_prenom'],
            'grade' => $grade, 'grade_pos' => $pos, 'qualifs' => $d['qualifs'],
            'ancien_grade' => $ancien,
            'date_arrivee' => $mm ? $mm['date_arrivee'] : null,
            'date_promo' => $mm ? $mm['date_promo'] : null,
        );
    }
    usort($out, function ($a, $b) { if ($a['grade_pos'] !== $b['grade_pos']) return $a['grade_pos'] - $b['grade_pos']; return strcmp($a['matricule'], $b['matricule']); });
    return $out;
}
function adm_qualif_cols($QUALIFS) { $c = array(); foreach ($QUALIFS as $code => $rid) $c[] = array('code' => $code, 'auto' => $rid !== ''); return $c; }
function adm_days_since($date) { if (!$date) return null; try { $d = new DateTime($date); $n = new DateTime('today'); return (int)$d->diff($n)->days; } catch (Exception $e) { return null; } }
function adm_grade_order_labels($GRADES) { $o = array(); foreach ($GRADES as $rid => $lbl) $o[] = $lbl; return $o; }
function adm_role_colors($conn, $map) {
    $out = array();
    try {
        $ids = array_keys(array_filter($map, function ($v, $k) { return $k !== ''; }, ARRAY_FILTER_USE_BOTH));
        if (!$ids) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT role_id, color FROM discord_roles_cache WHERE role_id IN ($in)");
        $st->execute(array_values($ids));
        $byId = array(); foreach ($st->fetchAll() as $r) $byId[(string)$r['role_id']] = $r['color'];
        foreach ($map as $rid => $key) { if ($rid !== '' && isset($byId[$rid]) && $byId[$rid]) $out[$key] = $byId[$rid]; }
    } catch (Exception $e) {}
    return $out;
}
function adm_grade_colors($conn, $GRADES) { return adm_role_colors($conn, $GRADES); }
function adm_qualif_colors($conn, $QUALIFS) { $m = array(); foreach ($QUALIFS as $code => $rid) { if ($rid !== '') $m[$rid] = $code; } return adm_role_colors($conn, $m); }

function roster_norm_mat($m) { $n = (int)preg_replace('/\D/', '', (string)$m); if ($n < 1 || $n > 99) return null; return str_pad((string)$n, 2, '0', STR_PAD_LEFT); }
function adm_avatar_url($id, $stored) { $stored = (string)$stored; return preg_match('#^https://cdn\.discordapp\.com/avatars/[0-9]+/[A-Za-z0-9_]+\.(png|webp|jpg|gif)(\?[A-Za-z0-9=&]+)?$#', $stored) ? $stored : ''; }
function adm_is_dev($conn, $did) { if ((string)$did === '543211066805452805') return true; try { $s = $conn->prepare("SELECT 1 FROM dev_users WHERE discord_id = ? LIMIT 1"); $s->execute(array((string)$did)); return (bool)$s->fetch(); } catch (Exception $e) { return false; } }
function adm_ensure_blocked($conn) { try { $conn->exec("CREATE TABLE IF NOT EXISTS roster_blocked_matricules (matricule VARCHAR(10) PRIMARY KEY, reason VARCHAR(255) DEFAULT NULL, blocked_by VARCHAR(30) DEFAULT NULL, blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Exception $e) {} }

if ($action === 'bootstrap') {
    $stats = array(
        'effectifs' => (int)$conn->query("SELECT COUNT(*) FROM roster WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchColumn(),
        'semaines'  => (int)$conn->query("SELECT COUNT(*) FROM sup_weeks")->fetchColumn(),
    );
    echo json_encode(array(
        'access' => true,
        'me' => array('discord_id' => $did, 'name' => adm_supname($conn, $did)),
        'perms' => array('access' => true, 'admin' => true),
        'stats' => $stats,
        'grade_order' => adm_grade_order_labels($ADM_GRADES),
        'grade_colors' => adm_grade_colors($conn, $ADM_GRADES),
        'qualif_cols' => adm_qualif_cols($ADM_QUALIFS),
        'qualif_colors' => adm_qualif_colors($conn, $ADM_QUALIFS),
        'sanction_types' => $ADM_SANCTIONS,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
function adm_supname($conn, $did) {
    try { $q = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = ? LIMIT 1"); $q->execute(array($did)); $r = $q->fetch(); if ($r) return trim(($r['matricule'] ? $r['matricule'] . ' | ' : '') . $r['nom_prenom']); } catch (Exception $e) {}
    return 'Admin';
}

if ($action === 'sup_live') {
    $eff = adm_live_effectifs($conn, $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS);
    foreach ($eff as &$e) { $e['jours_promo'] = adm_days_since($e['date_promo']); } unset($e);
    echo json_encode(array('success' => true, 'effectifs' => $eff, 'qualif_cols' => adm_qualif_cols($ADM_QUALIFS), 'qualif_colors' => adm_qualif_colors($conn, $ADM_QUALIFS), 'grade_order' => adm_grade_order_labels($ADM_GRADES), 'grade_colors' => adm_grade_colors($conn, $ADM_GRADES), 'sanction_types' => $ADM_SANCTIONS), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'sup_agent_save') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mk = substr((string)(isset($data['matricule']) ? $data['matricule'] : ''), 0, 10); if ($mk === '') mdt_error(400, 'E-ADM-400', 'matricule requis');
    if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $mk)) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $da = (isset($data['date_arrivee']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date_arrivee'])) ? $data['date_arrivee'] : null;
    $dp = (isset($data['date_promo']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date_promo'])) ? $data['date_promo'] : null;
    $conn->prepare("INSERT INTO sup_agent (matricule, date_arrivee, date_promo, updated_by, updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE date_arrivee=VALUES(date_arrivee), date_promo=VALUES(date_promo), updated_by=VALUES(updated_by), updated_at=NOW()")
        ->execute(array($mk, $da, $dp, $did));
    echo json_encode(array('success' => true)); exit;
}

function adm_week_bounds($monday = null) {
    $monday = $monday ? (clone $monday) : new DateTime('monday this week');
    $sunday = clone $monday; $sunday->modify('+6 days');
    return array($monday->format('Y-m-d'), 'Semaine du ' . $monday->format('d/m/Y') . ' au ' . $sunday->format('d/m/Y'));
}
function adm_week_start_migrate($conn) {
    static $done = false; if ($done) return; $done = true;
    try { $conn->query("SELECT week_start FROM sup_weeks LIMIT 0"); }
    catch (Exception $e) { try { $conn->exec("ALTER TABLE sup_weeks ADD COLUMN week_start DATE NULL DEFAULT NULL"); } catch (Exception $e2) {} }
}
function adm_create_current_week($conn, $did, $grades, $gradePos, $qualifs, $monday = null) {
    adm_week_start_migrate($conn);
    list($ws, $label) = adm_week_bounds($monday);
    $chk = $conn->prepare("SELECT id FROM sup_weeks WHERE week_start = ? LIMIT 1"); $chk->execute(array($ws));
    $ex = $chk->fetchColumn();
    if ($ex) return array('created' => false, 'id' => $ex, 'label' => $label);
    if ((int)$conn->query("SELECT COUNT(*) FROM sup_weeks")->fetchColumn() >= 520) return array('created' => false, 'id' => null, 'label' => $label);
    $conn->beginTransaction();
    try {
        $conn->exec("UPDATE sup_weeks SET is_current = 0");
        $prevCer = $conn->query("SELECT ceremonie FROM sup_weeks ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        $prev = json_decode($prevCer ?: '{}', true); if (!is_array($prev)) $prev = array();

        $tmpl = array();
        foreach ($prev as $k => $sec) { if (strpos((string)$k, '_') !== 0 && is_array($sec) && isset($sec['responsable']) && $sec['responsable'] !== '') $tmpl[$k] = array('responsable' => $sec['responsable']); }
        $wid = uniqid('supw_');
        $conn->prepare("INSERT INTO sup_weeks (id, label, week_start, is_current, ceremonie, created_by) VALUES (?,?,?,1,?,?)")
            ->execute(array($wid, $label, $ws, json_encode((object)$tmpl, JSON_UNESCAPED_UNICODE), $did));
        $ins = $conn->prepare("INSERT INTO sup_effectifs (week_id, matricule, nom_prenom, grade, grade_pos, effectif, date_promo, sanction, note_semaine, qualifs, ordre) VALUES (?,?,?,?,?,1,?,?,?,?,?)");
        $i = 0;
        foreach (adm_live_effectifs($conn, $grades, $gradePos, $qualifs) as $e) {
            $ins->execute(array($wid, $e['matricule'], $e['nom_prenom'], $e['grade'], $e['grade_pos'], $e['date_promo'], null, null, json_encode((object)$e['qualifs'], JSON_UNESCAPED_UNICODE), $i++));
        }
        $conn->commit();
        return array('created' => true, 'id' => $wid, 'label' => $label);
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}

if ($action === 'sup_weeks') {

    try { adm_create_current_week($conn, $did, $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS); } catch (Exception $e) {}
    adm_week_start_migrate($conn);
    $out = array();
    foreach ($conn->query("SELECT id, label, is_current, created_at FROM sup_weeks ORDER BY created_at DESC")->fetchAll() as $w) {
        $out[] = array('id' => $w['id'], 'label' => $w['label'], 'is_current' => (int)$w['is_current'] ? true : false, 'created_at' => $w['created_at'],
            'nb' => (int)$conn->query("SELECT COUNT(*) FROM sup_effectifs WHERE week_id = " . $conn->quote($w['id']))->fetchColumn());
    }
    echo json_encode(array('success' => true, 'weeks' => $out), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'sup_week_get') {
    $id = substr((string)(isset($_GET['id']) ? $_GET['id'] : ''), 0, 40); if ($id === '') mdt_error(400, 'E-ADM-400', 'id requis');
    $st = $conn->prepare("SELECT * FROM sup_weeks WHERE id = ? LIMIT 1"); $st->execute(array($id));
    $w = $st->fetch(); if (!$w) mdt_error(404, 'E-ADM-404', 'Semaine introuvable');

    $pend = array();
    if ((int)$w['is_current']) {
        adm_sup_agent_migrate($conn);
        try { foreach ($conn->query("SELECT matricule, pending_from FROM sup_agent WHERE pending_grade IS NOT NULL")->fetchAll() as $p) $pend[$p['matricule']] = $p['pending_from']; } catch (Exception $e) {}
    }
    $eff = array();
    $eq = $conn->prepare("SELECT * FROM sup_effectifs WHERE week_id = ? ORDER BY grade_pos, matricule"); $eq->execute(array($id));
    foreach ($eq->fetchAll() as $e) {
        $eff[] = array('matricule' => $e['matricule'], 'nom_prenom' => $e['nom_prenom'], 'grade' => $e['grade'], 'grade_pos' => (int)$e['grade_pos'],
            'effectif' => (int)$e['effectif'] ? true : false, 'heures' => isset($e['heures']) ? $e['heures'] : null, 'date_promo' => $e['date_promo'], 'jours_promo' => adm_days_since($e['date_promo']),
            'ancien_grade' => isset($pend[$e['matricule']]) ? $pend[$e['matricule']] : null,
            'sanction' => $e['sanction'], 'note_semaine' => $e['note_semaine'], 'qualifs' => json_decode($e['qualifs'] ?: '{}', true));
    }
    echo json_encode(array('success' => true, 'week' => array('id' => $w['id'], 'label' => $w['label'], 'is_current' => (int)$w['is_current'] ? true : false),
        'effectifs' => $eff, 'ceremonie' => (object)(json_decode($w['ceremonie'] ?: '{}', true) ?: array()), 'qualif_cols' => adm_qualif_cols($ADM_QUALIFS), 'qualif_colors' => adm_qualif_colors($conn, $ADM_QUALIFS), 'grade_order' => adm_grade_order_labels($ADM_GRADES), 'grade_colors' => adm_grade_colors($conn, $ADM_GRADES), 'sanction_types' => $ADM_SANCTIONS), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'sup_week_create') {
    mdt_post_only();
    $r = adm_create_current_week($conn, $did, $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS);
    if (!$r['created']) {
        if ($r['id']) mdt_error(409, 'E-ADM-409', 'La « ' . $r['label'] . ' » existe déjà — elle est créée automatiquement chaque lundi.');
        mdt_error(429, 'E-ADM-429', 'Trop de semaines');
    }
    echo json_encode(array('success' => true, 'id' => $r['id'])); exit;
}

if ($action === 'sup_week_close') {
    mdt_post_only();
    adm_sup_agent_migrate($conn);
    $wk = adm_current_week($conn); if (!$wk) mdt_error(409, 'E-ADM-409', 'Aucune semaine courante');
    $LBLPOS = array_flip(adm_grade_order_labels($ADM_GRADES));
    $today = date('Y-m-d');
    $applied = 0;
    $conn->beginTransaction();
    try {
        $rows = $conn->query("SELECT matricule, pending_grade, pending_pos, pending_from FROM sup_agent WHERE pending_grade IS NOT NULL FOR UPDATE")->fetchAll();
        $up1 = $conn->prepare("UPDATE sup_agent SET grade_override=?, override_pos=?, date_promo=IF(?=1, ?, date_promo), pending_grade=NULL, pending_pos=NULL, pending_from=NULL, updated_by=?, updated_at=NOW() WHERE matricule=?");
        $up2 = $conn->prepare("UPDATE sup_effectifs SET date_promo=? WHERE week_id=? AND matricule=?");
        foreach ($rows as $r) {
            $fromPos = isset($LBLPOS[$r['pending_from']]) ? (int)$LBLPOS[$r['pending_from']] : 999;
            $isPromo = ((int)$r['pending_pos'] < $fromPos) ? 1 : 0;
            $up1->execute(array($r['pending_grade'], (int)$r['pending_pos'], $isPromo, $today, $did, $r['matricule']));
            if ($isPromo) $up2->execute(array($today, $wk['id'], $r['matricule']));
            $applied++;
        }

        $endOk = true;
        if (!empty($wk['week_start'])) { try { $endOk = new DateTime('today') >= (new DateTime($wk['week_start']))->modify('+5 days'); } catch (Exception $e) {} }
        $closed = false; $newId = null; $newLabel = null;
        if ($endOk) {
            $conn->prepare("UPDATE sup_weeks SET is_current = 0 WHERE id = ?")->execute(array($wk['id']));
            $closed = true;
        }
        $conn->commit();
        if ($closed) {
            $nextMonday = null;
            if (!empty($wk['week_start'])) { try { $nextMonday = (new DateTime($wk['week_start']))->modify('+7 days'); } catch (Exception $e) {} }
            if (!$nextMonday) $nextMonday = new DateTime('monday next week');
            try {
                $r2 = adm_create_current_week($conn, $did, $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS, $nextMonday);
                $newId = $r2['id']; $newLabel = $r2['label'];
            } catch (Exception $e) {}
        }
        echo json_encode(array('success' => true, 'applied' => $applied, 'closed' => $closed, 'id' => $newId, 'label' => $newLabel), JSON_UNESCAPED_UNICODE); exit;
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}

if ($action === 'sup_effectif_save') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $wid = substr((string)(isset($data['week_id']) ? $data['week_id'] : ''), 0, 40);
    $mk = substr((string)(isset($data['matricule']) ? $data['matricule'] : ''), 0, 10);
    if ($wid === '' || $mk === '') mdt_error(400, 'E-ADM-400', 'week_id + matricule requis');
    if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $mk)) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $wc = $conn->prepare("SELECT is_current FROM sup_weeks WHERE id = ? LIMIT 1"); $wc->execute(array($wid)); $isCur = $wc->fetchColumn();
    if ($isCur === false) mdt_error(404, 'E-ADM-404', 'Semaine introuvable');
    if (!(int)$isCur) mdt_error(409, 'E-ADM-409', 'Semaine archivée — lecture seule');
    $eff = !empty($data['effectif']) ? 1 : 0;
    $heures = isset($data['heures']) ? mb_substr((string)$data['heures'], 0, 30) : null;
    $sanction = isset($data['sanction']) ? mb_substr((string)$data['sanction'], 0, 255) : null;
    $note = isset($data['note_semaine']) ? mb_substr((string)$data['note_semaine'], 0, 255) : null;
    $dp = (isset($data['date_promo']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date_promo'])) ? $data['date_promo'] : null;
    $conn->prepare("UPDATE sup_effectifs SET effectif=?, heures=?, sanction=?, note_semaine=?, date_promo=? WHERE week_id=? AND matricule=?")
        ->execute(array($eff, $heures, $sanction, $note, $dp, $wid, $mk));
    if ($dp !== null) $conn->prepare("INSERT INTO sup_agent (matricule, date_promo, updated_by, updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE date_promo=VALUES(date_promo), updated_by=VALUES(updated_by), updated_at=NOW()")->execute(array($mk, $dp, $did));
    echo json_encode(array('success' => true, 'jours_promo' => adm_days_since($dp))); exit;
}

if ($action === 'sup_ceremonie_save') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $wid = substr((string)(isset($data['week_id']) ? $data['week_id'] : ''), 0, 40); if ($wid === '') mdt_error(400, 'E-ADM-400', 'week_id requis');
    $wc = $conn->prepare("SELECT is_current FROM sup_weeks WHERE id = ? LIMIT 1"); $wc->execute(array($wid)); $isCur = $wc->fetchColumn();
    if ($isCur === false) mdt_error(404, 'E-ADM-404', 'Semaine introuvable');
    if (!(int)$isCur) mdt_error(409, 'E-ADM-409', 'Semaine archivée — lecture seule');
    $cer = (isset($data['ceremonie']) && is_array($data['ceremonie'])) ? $data['ceremonie'] : array();
    $json = json_encode((object)$cer, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 200000) mdt_error(413, 'E-ADM-413', 'Fiche trop volumineuse');
    $r = $conn->prepare("UPDATE sup_weeks SET ceremonie=? WHERE id=?"); $r->execute(array($json, $wid));
    echo json_encode(array('success' => true)); exit;
}

if ($action === 'sup_ceremonie_publier') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $wid = substr((string)(isset($data['week_id']) ? $data['week_id'] : ''), 0, 40);
    if ($wid === '') mdt_error(400, 'E-ADM-400', 'week_id requis');
    $st = $conn->prepare("SELECT id, label, ceremonie FROM sup_weeks WHERE id = ? LIMIT 1"); $st->execute(array($wid));
    $w = $st->fetch();
    if (!$w) mdt_error(404, 'E-ADM-404', 'Semaine introuvable');
    $cer = json_decode($w['ceremonie'] ?: '{}', true) ?: array();

    $secs = array(
        array('rappel',     'Rappel Supervision / État-Major',  array(array('items', null))),
        array('service',    'Arrivée · Départ · Convocation',   array(array('arrivee', 'Arrivée'), array('depart', 'Départ'), array('convocation', 'Convocation'))),
        array('infos',      'Informations · Sujets',            array(array('items', null))),
        array('promotions', 'Promotions',                       array(array('items', null))),
        array('semaine',    'Trooper · Guignol de la semaine',  array(array('trooper', 'Trooper de la semaine'), array('guignol', 'Guignol de la semaine'), array('repa', 'Répa compteur'))),
        array('notes',      'Notes de service',                 array(array('items', null))),
    );
    $md = '';
    foreach ($secs as $s) {
        $sec = (isset($cer[$s[0]]) && is_array($cer[$s[0]])) ? $cer[$s[0]] : array();
        $block = '';
        foreach ($s[2] as $f) {
            $v = isset($sec[$f[0]]) ? $sec[$f[0]] : null;
            if (is_array($v)) {
                $items = array_values(array_filter(array_map('strval', $v), function($x){ return trim($x) !== ''; }));
                if (!$items) continue;
                if ($f[1] !== null) $block .= "\n**" . $f[1] . "**\n";
                foreach ($items as $it) $block .= '- ' . $it . "\n";
            } elseif ($v !== null && !is_array($v) && trim((string)$v) !== '' && $f[0] !== 'responsable') {
                $block .= '- **' . ($f[1] !== null ? $f[1] : $f[0]) . '** : ' . trim((string)$v) . "\n";
            }
        }
        if ($block === '') continue;
        $md .= "\n## " . $s[1] . "\n";
        if (!empty($sec['responsable']) && trim((string)$sec['responsable']) !== '') $md .= '_Responsable : ' . trim((string)$sec['responsable']) . "_\n";
        $md .= $block;
    }
    if (trim($md) === '') mdt_error(409, 'E-ADM-409', 'La fiche de cérémonie est vide — rien à publier');

    $label = (string)$w['label'];
    $nomSt = $conn->prepare("SELECT COALESCE(NULLIF(discord_nick,''), NULLIF(discord_username,''), username, discord_id) FROM users WHERE discord_id = ? LIMIT 1");
    $nomSt->execute(array($did));
    $nom = $nomSt->fetchColumn() ?: $did;

    $conn->prepare("INSERT INTO annonces (canal, titre, resume, contenu, roles, epingle, lecture_obligatoire, ordre, created_by, created_by_name) VALUES ('supervision', ?, ?, ?, NULL, 0, 0, 0, ?, ?)")
        ->execute(array(
            '📋 Résumé de cérémonie — ' . $label,
            'Le résumé de la cérémonie (' . $label . ') est disponible — allez le consulter.',
            trim($md),
            $did, $nom
        ));
    $aid = (int)$conn->lastInsertId();

    try {
        $ins = $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id, urgent, lu) VALUES (:d, 'annonce', :t, :c, :l, 'annonce', :r, 0, 0)
            ON DUPLICATE KEY UPDATE titre=VALUES(titre), corps=VALUES(corps), lien=VALUES(lien), lu=0, created_at=NOW()");
        foreach ($conn->query("SELECT discord_id FROM users WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $ins->execute(array(':d' => $d, ':t' => '📋 Résumé de cérémonie', ':c' => 'Allez consulter le dernier résumé de la cérémonie — ' . $label, ':l' => '/annonces/' . $aid, ':r' => (string)$aid));
        }
    } catch (Exception $e) {}

    $cer['_publication'] = array('annonce_id' => $aid, 'at' => date('d/m/Y H:i'), 'by' => $did);
    $conn->prepare("UPDATE sup_weeks SET ceremonie = ? WHERE id = ?")->execute(array(json_encode((object)$cer, JSON_UNESCAPED_UNICODE), $wid));

    echo json_encode(array('success' => true, 'annonce_id' => $aid, 'publication' => $cer['_publication'])); exit;
}
if ($action === 'sup_week_delete') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); $id = substr((string)(is_array($data) && isset($data['id']) ? $data['id'] : ''), 0, 40);
    if ($id === '') mdt_error(400, 'E-ADM-400', 'id requis');
    $conn->prepare("DELETE FROM sup_effectifs WHERE week_id = ?")->execute(array($id));
    $conn->prepare("DELETE FROM sup_weeks WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true)); exit;
}

if ($action === 'roster_min') {
    $out = array();
    foreach ($conn->query("SELECT matricule, nom_prenom FROM roster WHERE discord_id IS NOT NULL ORDER BY matricule")->fetchAll() as $r) $out[] = trim($r['matricule'] . ' | ' . $r['nom_prenom']);
    echo json_encode(array('success' => true, 'roster' => $out), JSON_UNESCAPED_UNICODE); exit;
}

function adm_current_week($conn) { $st = $conn->query("SELECT * FROM sup_weeks WHERE is_current = 1 ORDER BY created_at DESC LIMIT 1"); return $st->fetch(); }
function adm_cer_append($conn, $wid, $section, $field, $value) {
    $st = $conn->prepare("SELECT ceremonie FROM sup_weeks WHERE id = ? LIMIT 1"); $st->execute(array($wid));
    $c = json_decode($st->fetchColumn() ?: '{}', true); if (!is_array($c)) $c = array();
    if (!isset($c[$section]) || !is_array($c[$section])) $c[$section] = array();
    if (!isset($c[$section][$field]) || !is_array($c[$section][$field])) $c[$section][$field] = array();
    $c[$section][$field][] = $value;
    $conn->prepare("UPDATE sup_weeks SET ceremonie = ? WHERE id = ?")->execute(array(json_encode($c, JSON_UNESCAPED_UNICODE), $wid));
}

function adm_cer_remove_last($conn, $wid, $section, $field, $prefix) {
    $st = $conn->prepare("SELECT ceremonie FROM sup_weeks WHERE id = ? LIMIT 1"); $st->execute(array($wid));
    $c = json_decode($st->fetchColumn() ?: '{}', true); if (!is_array($c)) return;
    if (!isset($c[$section][$field]) || !is_array($c[$section][$field])) return;
    for ($i = count($c[$section][$field]) - 1; $i >= 0; $i--) {
        if (strpos((string)$c[$section][$field][$i], $prefix) === 0) {
            array_splice($c[$section][$field], $i, 1);
            $conn->prepare("UPDATE sup_weeks SET ceremonie = ? WHERE id = ?")->execute(array(json_encode($c, JSON_UNESCAPED_UNICODE), $wid));
            return;
        }
    }
}
if ($action === 'sup_promote') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mk = substr((string)(isset($data['matricule']) ? $data['matricule'] : ''), 0, 10);
    if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $mk)) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $dir = (isset($data['direction']) && $data['direction'] === 'down') ? 'down' : 'up';
    $wk = adm_current_week($conn); if (!$wk) mdt_error(409, 'E-ADM-409', 'Crée d\'abord une semaine courante');
    $st = $conn->prepare("SELECT r.nom_prenom, u.discord_roles FROM roster r LEFT JOIN users u ON u.discord_id = r.discord_id WHERE r.matricule = ? LIMIT 1"); $st->execute(array($mk));
    $row = $st->fetch(); if (!$row) mdt_error(404, 'E-ADM-404', 'Agent introuvable');
    adm_sup_agent_migrate($conn);
    $d = adm_derive($row['discord_roles'], $ADM_GRADES, $ADM_GRADE_POS, array());
    $sq = $conn->prepare("SELECT pending_grade, pending_pos, pending_from, grade_override, override_pos FROM sup_agent WHERE matricule = ? LIMIT 1"); $sq->execute(array($mk));
    $sa = $sq->fetch() ?: null;

    $basePos = ($sa && $sa['grade_override'] !== null) ? (int)$sa['override_pos'] : $d['grade_pos'];
    $base    = ($sa && $sa['grade_override'] !== null) ? $sa['grade_override'] : ($d['grade'] ?: '—');
    $curPos = $basePos; $cur = $base;
    if ($sa && $sa['pending_grade'] !== null) { $curPos = (int)$sa['pending_pos']; $cur = $sa['pending_grade']; }
    $target = null; $tp = $dir === 'up' ? $curPos - 1 : $curPos + 1;
    if ($curPos < 999 && $tp >= 0 && $tp < count($ADM_GRADE_ORDER)) $target = $ADM_GRADES[$ADM_GRADE_ORDER[$tp]];
    if (!$target) mdt_error(400, 'E-ADM-400', $dir === 'up' ? 'Déjà au grade le plus haut' : 'Déjà au grade le plus bas');
    $agent = trim($mk . ' ' . ($row['nom_prenom'] ?: ''));
    if ($target === $base) {

        $conn->prepare("UPDATE sup_agent SET pending_grade=NULL, pending_pos=NULL, pending_from=NULL, updated_by=?, updated_at=NOW() WHERE matricule=?")->execute(array($did, $mk));
        $conn->prepare("UPDATE sup_effectifs SET grade=?, grade_pos=? WHERE week_id=? AND matricule=?")->execute(array($base === '—' ? null : $base, $basePos, $wk['id'], $mk));
        adm_cer_remove_last($conn, $wk['id'], 'promotions', 'items', $agent . ' : ');
        echo json_encode(array('success' => true, 'cancelled' => true, 'target' => $base, 'item' => $agent . ' : retour ' . $base)); exit;
    }

    $from = ($sa && $sa['pending_grade'] !== null && $sa['pending_from'] !== null) ? $sa['pending_from'] : $base;
    $conn->prepare("INSERT INTO sup_agent (matricule, pending_grade, pending_pos, pending_from, updated_by, updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE pending_grade=VALUES(pending_grade), pending_pos=VALUES(pending_pos), pending_from=VALUES(pending_from), updated_by=VALUES(updated_by), updated_at=NOW()")
        ->execute(array($mk, $target, $tp, $from, $did));
    $conn->prepare("UPDATE sup_effectifs SET grade=?, grade_pos=? WHERE week_id=? AND matricule=?")->execute(array($target, $tp, $wk['id'], $mk));
    $item = $agent . ' : ' . $cur . ' → ' . $target . ($dir === 'down' ? ' (rétrogradation)' : '');
    adm_cer_append($conn, $wk['id'], 'promotions', 'items', $item);
    echo json_encode(array('success' => true, 'target' => $target, 'item' => $item, 'ancien_grade' => $from)); exit;
}
if ($action === 'sup_depart') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mk = substr((string)(isset($data['matricule']) ? $data['matricule'] : ''), 0, 10);
    if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $mk)) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $st = $conn->prepare("SELECT nom_prenom FROM roster WHERE matricule = ? LIMIT 1"); $st->execute(array($mk)); $nom = $st->fetchColumn();
    $wk = adm_current_week($conn);
    if ($wk) {
        adm_cer_append($conn, $wk['id'], 'service', 'depart', trim($mk . ' ' . ($nom ?: '')));
        $conn->prepare("DELETE FROM sup_effectifs WHERE week_id=? AND matricule=?")->execute(array($wk['id'], $mk));
    }
    $conn->prepare("DELETE FROM roster WHERE matricule=?")->execute(array($mk));
    $conn->prepare("DELETE FROM sup_agent WHERE matricule=?")->execute(array($mk));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'sup_sanction') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mk = substr((string)(isset($data['matricule']) ? $data['matricule'] : ''), 0, 10);
    if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $mk)) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $codes = array(); foreach ($ADM_SANCTIONS as $s) $codes[] = $s['code'];
    $type = (string)(isset($data['type']) ? $data['type'] : '');
    if (!in_array($type, $codes, true)) mdt_error(400, 'E-ADM-400', 'Type de sanction invalide');
    $raison = mb_substr(trim((string)(isset($data['raison']) ? $data['raison'] : '')), 0, 255);
    $detail = mb_substr((string)(isset($data['detail']) ? $data['detail'] : ''), 0, 5000);
    $photos = array();
    if (isset($data['photos']) && is_array($data['photos'])) foreach (array_slice($data['photos'], 0, 8) as $p) if (preg_match('#^/documents/uploads/[A-Za-z0-9._-]+$#', (string)$p)) $photos[] = (string)$p;
    $conn->prepare("INSERT INTO sup_sanctions (matricule, raison, detail, photos, auteur, created_by) VALUES (?,?,?,?,?,?)")
        ->execute(array($mk, ($raison !== '' ? $raison : $type), $detail, json_encode($photos, JSON_UNESCAPED_UNICODE), adm_supname($conn, $did), $did));
    $wk = adm_current_week($conn);
    if ($wk) $conn->prepare("UPDATE sup_effectifs SET sanction=? WHERE week_id=? AND matricule=?")->execute(array($type, $wk['id'], $mk));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'sup_sanctions_list') {
    $mk = substr((string)(isset($_GET['matricule']) ? $_GET['matricule'] : ''), 0, 10);
    if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $mk)) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $out = array();
    $st = $conn->prepare("SELECT raison, detail, photos, auteur, created_at FROM sup_sanctions WHERE matricule = ? ORDER BY created_at DESC LIMIT 50"); $st->execute(array($mk));
    foreach ($st->fetchAll() as $r) $out[] = array('raison' => $r['raison'], 'detail' => $r['detail'], 'photos' => json_decode($r['photos'] ?: '[]', true), 'auteur' => $r['auteur'], 'created_at' => $r['created_at']);
    echo json_encode(array('success' => true, 'sanctions' => $out), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'sup_week_resync') {
    mdt_post_only();
    $wk = adm_current_week($conn); if (!$wk) mdt_error(409, 'E-ADM-409', 'Aucune semaine courante');
    $conn->beginTransaction();
    try {
        $prev = array();
        $st = $conn->prepare("SELECT matricule, effectif, heures, sanction, note_semaine FROM sup_effectifs WHERE week_id = ?"); $st->execute(array($wk['id']));
        foreach ($st->fetchAll() as $r) $prev[$r['matricule']] = $r;
        $conn->prepare("DELETE FROM sup_effectifs WHERE week_id = ?")->execute(array($wk['id']));
        $ins = $conn->prepare("INSERT INTO sup_effectifs (week_id, matricule, nom_prenom, grade, grade_pos, effectif, heures, date_promo, sanction, note_semaine, qualifs, ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $i = 0; $kept = 0;
        foreach (adm_live_effectifs($conn, $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS) as $e) {
            $p = isset($prev[$e['matricule']]) ? $prev[$e['matricule']] : null; if ($p) $kept++;
            $ins->execute(array($wk['id'], $e['matricule'], $e['nom_prenom'], $e['grade'], $e['grade_pos'],
                $p ? (int)$p['effectif'] : 1, $p ? $p['heures'] : null, $e['date_promo'], $p ? $p['sanction'] : null, $p ? $p['note_semaine'] : null,
                json_encode((object)$e['qualifs'], JSON_UNESCAPED_UNICODE), $i++));
        }
        $conn->commit();
        echo json_encode(array('success' => true, 'count' => $i, 'kept' => $kept)); exit;
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
}
if ($action === 'sanction_photo') {
    mdt_post_only();
    if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) mdt_error(400, 'E-ADM-400', 'Aucun fichier');
    if ($_FILES['photo']['size'] > 8 * 1024 * 1024) mdt_error(413, 'E-ADM-413', 'Image trop lourde (max 8 Mo)');
    $tmp = $_FILES['photo']['tmp_name'];
    $fi = new finfo(FILEINFO_MIME_TYPE); $mime = $fi->file($tmp);
    $allow = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    if (!isset($allow[$mime])) mdt_error(415, 'E-ADM-415', 'Format non supporté (JPEG/PNG/WebP/GIF)');
    $dim = @getimagesize($tmp);
    if (!$dim || $dim[0] < 1 || $dim[1] < 1) mdt_error(400, 'E-ADM-400', 'Image illisible');
    if ($dim[0] * $dim[1] > 30000000) mdt_error(413, 'E-ADM-413', 'Résolution trop grande');
    $dir = __DIR__ . '/documents/uploads/';
    if (!is_dir($dir)) mdt_error(500, 'E-ADM-500', 'Dossier uploads absent');
    $base = 'sanc_' . bin2hex(random_bytes(8));
    $name = null;
    if ($mime === 'image/gif' || !function_exists('imagecreatetruecolor')) {
        $name = $base . '.' . $allow[$mime];
        if (!@move_uploaded_file($tmp, $dir . $name)) mdt_error(500, 'E-ADM-500', 'Échec enregistrement');
    } else {
        $src = $mime === 'image/jpeg' ? @imagecreatefromjpeg($tmp) : ($mime === 'image/png' ? @imagecreatefrompng($tmp) : (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null));
        if (!$src) mdt_error(400, 'E-ADM-400', 'Image illisible');
        $w = imagesx($src); $h = imagesy($src); $max = 1600;
        if ($w > $max || $h > $max) { $ratio = min($max / $w, $max / $h); $nw = max(1, (int)round($w * $ratio)); $nh = max(1, (int)round($h * $ratio)); $dst = imagecreatetruecolor($nw, $nh); imagealphablending($dst, false); imagesavealpha($dst, true); imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($src); $src = $dst; }
        imagealphablending($src, false); imagesavealpha($src, true);
        $name = $base . '.webp';
        $ok = function_exists('imagewebp') ? @imagewebp($src, $dir . $name, 82) : false;
        if (!$ok) { $name = $base . '.jpg'; $ok = @imagejpeg($src, $dir . $name, 85); }
        imagedestroy($src);
        if (!$ok) mdt_error(500, 'E-ADM-500', 'Échec encodage');
    }
    echo json_encode(array('success' => true, 'url' => '/documents/uploads/' . $name)); exit;
}

if ($action === 'roster_list') {
    $rows = $conn->query("SELECT r.matricule, r.nom_prenom, r.discord_id, u.id AS uid, u.discord_roles, u.discord_avatar, u.discord_username, u.discord_nick FROM roster r LEFT JOIN users u ON u.discord_id = r.discord_id ORDER BY r.matricule")->fetchAll();
    $out = array();
    foreach ($rows as $r) {
        $d = adm_derive($r['discord_roles'], $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS);
        $out[] = array(
            'matricule' => $r['matricule'], 'nom_prenom' => $r['nom_prenom'],
            'discord_id' => $r['discord_id'], 'linked' => !empty($r['discord_id']) && !empty($r['uid']),
            'avatar_url' => adm_avatar_url($r['discord_id'], $r['discord_avatar']),
            'username' => $r['discord_username'], 'nick' => $r['discord_nick'],
            'grade' => $d['grade'], 'grade_pos' => $d['grade_pos'], 'qualifs' => $d['qualifs'],
        );
    }

    usort($out, function ($a, $b) {
        $na = (int)preg_replace('/[^0-9]/', '', $a['matricule']);
        $nb = (int)preg_replace('/[^0-9]/', '', $b['matricule']);
        if ($na !== $nb) return $na - $nb;
        return strcmp($a['matricule'], $b['matricule']);
    });
    echo json_encode(array('success' => true, 'agents' => $out, 'grade_order' => adm_grade_order_labels($ADM_GRADES), 'grade_colors' => adm_grade_colors($conn, $ADM_GRADES), 'qualif_cols' => adm_qualif_cols($ADM_QUALIFS), 'qualif_colors' => adm_qualif_colors($conn, $ADM_QUALIFS), 'is_dev' => adm_is_dev($conn, $did)), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'roster_save_agent') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mat = roster_norm_mat(isset($data['matricule']) ? $data['matricule'] : '');
    if (!$mat) mdt_error(400, 'E-ADM-400', 'Matricule invalide (01-99)');
    $nom = mb_substr(trim((string)(isset($data['nom_prenom']) ? $data['nom_prenom'] : '')), 0, 255);
    if ($nom === '') mdt_error(400, 'E-ADM-400', 'Nom requis');
    $did2 = (isset($data['discord_id']) && preg_match('/^\d{5,25}$/', (string)$data['discord_id'])) ? (string)$data['discord_id'] : null;
    adm_ensure_blocked($conn);
    $blk = $conn->prepare("SELECT reason FROM roster_blocked_matricules WHERE matricule = ?"); $blk->execute(array($mat)); $brow = $blk->fetch();
    if ($brow) mdt_error(403, 'E-ADM-403', 'Matricule ' . $mat . ' bloqué' . (!empty($brow['reason']) ? ' : ' . $brow['reason'] : ''));

    $matInt = (int)$mat;
    if ($matInt > 0 && preg_match('/^(\d{1,3})\s*[^A-Za-zÀ-ÿ0-9\s]+\s*(.+)$/u', $nom, $mm) && (int)$mm[1] === $matInt) $nom = trim($mm[2]);
    elseif ($matInt > 0 && preg_match('/^(\d{1,3})\s+[IlL]\s+(.+)$/u', $nom, $mm) && (int)$mm[1] === $matInt) $nom = trim($mm[2]);
    $nom = preg_replace('/\s{2,}/', ' ', $nom);
    $chk = $conn->prepare("SELECT id FROM roster WHERE matricule = ?"); $chk->execute(array($mat));
    if ($chk->fetch()) {
        $conn->prepare("UPDATE roster SET nom_prenom = ?, discord_id = ? WHERE matricule = ?")->execute(array($nom, $did2, $mat));
    } else {
        $ord = (int)$conn->query("SELECT COALESCE(MAX(ordre),0) FROM roster")->fetchColumn();
        $conn->prepare("INSERT INTO roster (matricule, nom_prenom, discord_id, ordre) VALUES (?,?,?,?)")->execute(array($mat, $nom, $did2, $ord + 1));
    }
    echo json_encode(array('success' => true, 'matricule' => $mat), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'roster_delete_agent') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mat = roster_norm_mat(isset($data['matricule']) ? $data['matricule'] : '');
    if (!$mat) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    $conn->prepare("DELETE FROM roster WHERE matricule = ?")->execute(array($mat));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'roster_unassigned') {
    $set = array();
    foreach ($conn->query("SELECT discord_id FROM roster WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll(PDO::FETCH_COLUMN) as $id) $set[(string)$id] = 1;
    $out = array();
    foreach ($conn->query("SELECT discord_id, discord_username, discord_nick, discord_avatar, discord_roles FROM users WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll() as $u) {
        if (isset($set[(string)$u['discord_id']])) continue;
        $d = adm_derive($u['discord_roles'], $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS);
        $out[] = array('discord_id' => $u['discord_id'], 'username' => $u['discord_username'], 'nick' => $u['discord_nick'], 'avatar_url' => adm_avatar_url($u['discord_id'], $u['discord_avatar']), 'grade' => $d['grade']);
    }
    echo json_encode(array('success' => true, 'users' => $out), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'roster_matricules') {
    adm_ensure_blocked($conn);
    $used = array(); foreach ($conn->query("SELECT matricule, nom_prenom FROM roster")->fetchAll() as $r) $used[$r['matricule']] = $r['nom_prenom'];
    $blocked = array(); foreach ($conn->query("SELECT matricule, reason FROM roster_blocked_matricules")->fetchAll() as $b) $blocked[$b['matricule']] = $b['reason'];
    $out = array();
    for ($i = 1; $i <= 99; $i++) {
        $m = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        if (isset($used[$m])) $out[] = array('m' => $m, 'state' => 'used', 'nom' => $used[$m]);
        elseif (isset($blocked[$m])) $out[] = array('m' => $m, 'state' => 'blocked', 'reason' => $blocked[$m]);
        else $out[] = array('m' => $m, 'state' => 'free');
    }
    echo json_encode(array('success' => true, 'matricules' => $out, 'is_dev' => adm_is_dev($conn, $did)), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'roster_block') {
    mdt_post_only();
    if (!adm_is_dev($conn, $did)) mdt_error(403, 'E-ADM-403', 'Réservé aux développeurs');
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mat = roster_norm_mat(isset($data['matricule']) ? $data['matricule'] : ''); if (!$mat) mdt_error(400, 'E-ADM-400', 'Matricule invalide (01-99)');
    $reason = mb_substr(trim((string)(isset($data['reason']) ? $data['reason'] : '')), 0, 255);
    adm_ensure_blocked($conn);
    $conn->prepare("INSERT INTO roster_blocked_matricules (matricule, reason, blocked_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), blocked_by=VALUES(blocked_by), blocked_at=CURRENT_TIMESTAMP")->execute(array($mat, $reason, $did));
    echo json_encode(array('success' => true, 'matricule' => $mat, 'reason' => $reason), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'roster_unblock') {
    mdt_post_only();
    if (!adm_is_dev($conn, $did)) mdt_error(403, 'E-ADM-403', 'Réservé aux développeurs');
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $mat = roster_norm_mat(isset($data['matricule']) ? $data['matricule'] : ''); if (!$mat) mdt_error(400, 'E-ADM-400', 'Matricule invalide');
    adm_ensure_blocked($conn);
    $conn->prepare("DELETE FROM roster_blocked_matricules WHERE matricule = ?")->execute(array($mat));
    echo json_encode(array('success' => true, 'matricule' => $mat), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'perms_roleconfig') {
    $conn->exec("CREATE TABLE IF NOT EXISTS role_config (cap_key VARCHAR(50) NOT NULL, role_id VARCHAR(30) NOT NULL, PRIMARY KEY (cap_key, role_id))");
    $rows = array();
    foreach ($conn->query("SELECT cap_key, role_id FROM role_config")->fetchAll() as $r) $rows[$r['cap_key']][] = (string)$r['role_id'];
    $out = array();
    foreach ($ADM_ROLECONFIG as $cap => $cfg) {
        $ov = isset($rows[$cap]) && count($rows[$cap]) > 0;
        $out[] = array('cap_key' => $cap, 'label' => $cfg['label'], 'hint' => $cfg['hint'], 'module' => $cfg['module'], 'href' => $cfg['href'],
            'roles' => $ov ? $rows[$cap] : $cfg['fallback'], 'fallback' => $cfg['fallback'], 'overridden' => $ov);
    }
    echo json_encode(array('success' => true, 'caps' => $out, 'is_dev' => adm_is_dev($conn, $did)), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'perms_roleconfig_save') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $cap = (string)(isset($data['cap_key']) ? $data['cap_key'] : '');
    if (!isset($ADM_ROLECONFIG[$cap])) mdt_error(400, 'E-ADM-400', 'Capacité inconnue');
    $ids = array();
    if (isset($data['role_ids']) && is_array($data['role_ids'])) foreach ($data['role_ids'] as $r) { $r = (string)$r; if (ctype_digit($r) && strlen($r) <= 30) $ids[$r] = 1; }
    $conn->exec("CREATE TABLE IF NOT EXISTS role_config (cap_key VARCHAR(50) NOT NULL, role_id VARCHAR(30) NOT NULL, PRIMARY KEY (cap_key, role_id))");
    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM role_config WHERE cap_key = ?")->execute(array($cap));
        if ($ids) { $ins = $conn->prepare("INSERT INTO role_config (cap_key, role_id) VALUES (?,?)"); foreach (array_keys($ids) as $r) $ins->execute(array($cap, $r)); }
        $conn->commit();
    } catch (Exception $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
    echo json_encode(array('success' => true, 'cap_key' => $cap, 'count' => count($ids), 'overridden' => count($ids) > 0)); exit;
}

if ($action === 'verif_search') {
    $q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
    if (mb_strlen($q) < 2) mdt_error(400, 'E-ADM-400', 'Recherche trop courte (min 2 caractères)');
    $like = '%' . $q . '%';
    $rc = array();
    foreach ($conn->query("SELECT role_id, name, color FROM discord_roles_cache")->fetchAll() as $r) $rc[(string)$r['role_id']] = array('id' => (string)$r['role_id'], 'name' => $r['name'], 'color' => $r['color']);
    $st = $conn->prepare("SELECT id, username, discord_id, discord_username, discord_nick, discord_avatar, discord_roles, created_at FROM users WHERE discord_id = ? OR username LIKE ? OR discord_username LIKE ? OR discord_nick LIKE ? LIMIT 12");
    $st->execute(array($q, $like, $like, $like));
    $users = $st->fetchAll();
    $rosterByMat = array();
    if (preg_match('/^\d+$/', $q)) {
        $cand = array_values(array_unique(array_filter(array($q, roster_norm_mat($q)))));
        if ($cand) {
            $in = implode(',', array_fill(0, count($cand), '?'));
            $rm = $conn->prepare("SELECT id, matricule, nom_prenom, discord_id FROM roster WHERE matricule IN ($in) LIMIT 3");
            $rm->execute($cand);
            foreach ($rm->fetchAll() as $r) {
                $rosterByMat[] = $r;
                if ($r['discord_id']) {
                    $u2 = $conn->prepare("SELECT id, username, discord_id, discord_username, discord_nick, discord_avatar, discord_roles, created_at FROM users WHERE discord_id = ? LIMIT 1");
                    $u2->execute(array($r['discord_id'])); $row = $u2->fetch();
                    if ($row) { $dup = false; foreach ($users as $eu) if ($eu['id'] == $row['id']) { $dup = true; break; } if (!$dup) $users[] = $row; }
                }
            }
        }
    }
    $rd = $conn->prepare("SELECT id, matricule, nom_prenom, discord_id FROM roster WHERE discord_id = ? LIMIT 3");
    $rd->execute(array($q)); $rosterByDiscord = $rd->fetchAll();
    $out = array();
    foreach ($users as $u) {
        $rosterEntry = null;
        if ($u['discord_id']) { $re = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = ? LIMIT 1"); $re->execute(array($u['discord_id'])); $rosterEntry = $re->fetch() ?: null; }
        $roles = $u['discord_roles'] ? json_decode($u['discord_roles'], true) : array(); if (!is_array($roles)) $roles = array();
        $der = adm_derive($u['discord_roles'], $ADM_GRADES, $ADM_GRADE_POS, $ADM_QUALIFS);
        $named = array(); foreach ($roles as $rid) { $rid = (string)$rid; $named[] = isset($rc[$rid]) ? $rc[$rid] : array('id' => $rid, 'name' => $rid, 'color' => null); }
        $diag = array();
        if (!$u['discord_id']) $diag[] = array('level' => 'error', 'msg' => 'Aucun Discord lié à ce compte');
        elseif (!$rosterEntry) $diag[] = array('level' => 'warn', 'msg' => 'Discord lié mais pas de matricule dans le roster');
        else $diag[] = array('level' => 'ok', 'msg' => 'Compte complet (Discord + roster)');
        if ($u['discord_id'] && count($roles) === 0) $diag[] = array('level' => 'warn', 'msg' => 'Aucun rôle Discord (bot pas encore synchronisé ou agent absent du serveur)');
        elseif ($u['discord_id'] && !$der['grade']) $diag[] = array('level' => 'warn', 'msg' => 'Aucun grade détecté parmi les rôles');
        $out[] = array(
            'id' => (int)$u['id'], 'username' => $u['username'], 'discord_id' => $u['discord_id'],
            'discord_username' => $u['discord_username'], 'discord_nick' => $u['discord_nick'],
            'avatar_url' => adm_avatar_url($u['discord_id'], $u['discord_avatar']),
            'roles_count' => count($roles), 'roles' => $named, 'grade' => $der['grade'], 'qualifs' => $der['qualifs'],
            'roster' => $rosterEntry ? array('matricule' => $rosterEntry['matricule'], 'nom_prenom' => $rosterEntry['nom_prenom']) : null,
            'diagnostic' => $diag,
        );
    }
    echo json_encode(array('success' => true, 'query' => $q, 'users' => $out, 'roster_by_matricule' => $rosterByMat, 'roster_by_discord' => $rosterByDiscord,
        'grade_colors' => adm_grade_colors($conn, $ADM_GRADES), 'qualif_colors' => adm_qualif_colors($conn, $ADM_QUALIFS)), JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'verif_link') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $uid = (int)(isset($data['user_id']) ? $data['user_id'] : 0);
    $did = trim((string)(isset($data['discord_id']) ? $data['discord_id'] : ''));
    if ($uid < 1) mdt_error(400, 'E-ADM-400', 'Compte cible requis');
    if (!preg_match('/^\d{10,30}$/', $did)) mdt_error(400, 'E-ADM-400', 'Discord ID invalide (10 à 30 chiffres)');
    $c = $conn->prepare("SELECT id, username FROM users WHERE discord_id = ? AND id != ?"); $c->execute(array($did, $uid)); $other = $c->fetch();
    if ($other) mdt_error(409, 'E-ADM-409', 'Discord déjà lié au compte « ' . $other['username'] . ' » (id ' . $other['id'] . ')');
    $cu = $conn->prepare("SELECT id FROM users WHERE id = ?"); $cu->execute(array($uid)); if (!$cu->fetch()) mdt_error(404, 'E-ADM-404', 'Compte introuvable');
    $conn->prepare("UPDATE users SET discord_id = ? WHERE id = ?")->execute(array($did, $uid));
    echo json_encode(array('success' => true, 'message' => 'Discord lié — rafraîchis les rôles pour synchroniser.')); exit;
}
if ($action === 'verif_unlink') {
    mdt_post_only();
    $data = mdt_get_post_data('E-ADM-400'); if (!is_array($data)) $data = array();
    $uid = (int)(isset($data['user_id']) ? $data['user_id'] : 0);
    if ($uid < 1) mdt_error(400, 'E-ADM-400', 'Compte cible requis');
    $conn->prepare("UPDATE users SET discord_id=NULL, discord_username=NULL, discord_avatar=NULL, discord_nick=NULL, discord_roles=NULL WHERE id=?")->execute(array($uid));
    echo json_encode(array('success' => true)); exit;
}
if ($action === 'verif_health') {
    $buckets = array();
    $defs = array(
        array('key' => 'no_discord', 'label' => 'Comptes MDT sans Discord lié', 'level' => 'error', 'kind' => 'user',
            'sql' => "SELECT id, username, NULL AS discord_id, NULL AS discord_username, NULL AS discord_nick FROM users WHERE discord_id IS NULL OR discord_id='' ORDER BY username LIMIT 100"),
        array('key' => 'no_roles', 'label' => 'Discord lié mais aucun rôle synchronisé', 'level' => 'warn', 'kind' => 'user',
            'sql' => "SELECT id, username, discord_id, discord_username, discord_nick FROM users WHERE discord_id IS NOT NULL AND discord_id<>'' AND (discord_roles IS NULL OR discord_roles='' OR discord_roles='[]') ORDER BY username LIMIT 100"),
        array('key' => 'no_matricule', 'label' => 'Discord lié mais absent du roster (pas de matricule)', 'level' => 'warn', 'kind' => 'user',
            'sql' => "SELECT u.id, u.username, u.discord_id, u.discord_username, u.discord_nick FROM users u LEFT JOIN roster r ON r.discord_id=u.discord_id WHERE u.discord_id IS NOT NULL AND u.discord_id<>'' AND r.id IS NULL ORDER BY u.username LIMIT 100"),
        array('key' => 'roster_no_account', 'label' => 'Roster avec Discord mais sans compte MDT', 'level' => 'warn', 'kind' => 'roster',
            'sql' => "SELECT r.matricule, r.nom_prenom, r.discord_id FROM roster r LEFT JOIN users u ON u.discord_id=r.discord_id WHERE r.discord_id IS NOT NULL AND r.discord_id<>'' AND u.id IS NULL ORDER BY CAST(r.matricule AS UNSIGNED) LIMIT 100"),
        array('key' => 'roster_no_discord', 'label' => 'Roster sans Discord (grade non dérivable)', 'level' => 'error', 'kind' => 'roster',
            'sql' => "SELECT matricule, nom_prenom FROM roster WHERE discord_id IS NULL OR discord_id='' ORDER BY CAST(matricule AS UNSIGNED) LIMIT 100"),
    );
    foreach ($defs as $d) {
        $items = $conn->query($d['sql'])->fetchAll(PDO::FETCH_ASSOC);
        $buckets[] = array('key' => $d['key'], 'label' => $d['label'], 'level' => $d['level'], 'kind' => $d['kind'], 'count' => count($items), 'items' => $items);
    }
    echo json_encode(array('success' => true, 'buckets' => $buckets), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'audit_list') {
    try { $conn->exec("DELETE FROM audit_log WHERE created_at < (NOW() - INTERVAL 90 DAY)"); } catch (Exception $e) {}
    $where = array(); $args = array();
    if (!empty($_GET['module']))  { $where[] = "module = ?"; $args[] = substr($_GET['module'], 0, 30); }
    if (!empty($_GET['result']) && in_array($_GET['result'], array('ok', 'error'), true)) { $where[] = "result = ?"; $args[] = $_GET['result']; }
    if (!empty($_GET['source']) && in_array($_GET['source'], array('server', 'client'), true)) { $where[] = "source = ?"; $args[] = $_GET['source']; }
    if (!empty($_GET['actor']))   { $where[] = "(actor_did = ? OR actor_name LIKE ?)"; $args[] = $_GET['actor']; $args[] = '%' . $_GET['actor'] . '%'; }
    if (!empty($_GET['q']))       { $q = '%' . substr($_GET['q'], 0, 60) . '%'; $where[] = "(action LIKE ? OR summary LIKE ? OR err_code LIKE ?)"; $args[] = $q; $args[] = $q; $args[] = $q; }
    $w = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1)); $per = 60; $off = ($page - 1) * $per;
    $ct = $conn->prepare("SELECT COUNT(*) FROM audit_log" . $w); $ct->execute($args); $total = (int)$ct->fetchColumn();
    $st = $conn->prepare("SELECT id, created_at, module, action, actor_did, actor_name, entity_type, entity_id, result, err_code, http_status, source, summary, before_json, after_json, ip FROM audit_log" . $w . " ORDER BY id DESC LIMIT $per OFFSET $off");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $mods = $conn->query("SELECT DISTINCT module FROM audit_log WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(array('success' => true, 'entries' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'modules' => $mods,
        'stats' => array('errors' => (int)$conn->query("SELECT COUNT(*) FROM audit_log WHERE result='error'")->fetchColumn(), 'total' => (int)$conn->query("SELECT COUNT(*) FROM audit_log")->fetchColumn())), JSON_UNESCAPED_UNICODE);
    exit;
}

mdt_error(400, 'E-ADM-400', 'Action inconnue');
