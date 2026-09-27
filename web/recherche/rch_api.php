<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db_config.php';
mdt_cors();

define('RCH_DDL_VERSION', '2026-08-11.3');
define('RCH_JSON', JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
define('RCH_NOTE_MAX', 300);

$RCH_ETATS = array('non', 'encours', 'ok', 'ras', 'suspect');

function rch_g($k) { return (isset($_GET[$k]) && is_string($_GET[$k])) ? trim($_GET[$k]) : ''; }
function rch_txt($v, $max) { return mb_substr(trim((string)$v), 0, $max, 'UTF-8'); }

function rch_color($v) {
    $v = strtoupper(trim((string)$v));
    return preg_match('/^#[0-9A-F]{6}$/', $v) ? $v : '#64748B';
}

function rch_points($raw) {
    if (!is_array($raw) || count($raw) < 3 || count($raw) > 500) return null;
    $out = array();
    foreach ($raw as $p) {
        if (!is_array($p) || count($p) < 2) return null;
        $x = (float)$p[0]; $y = (float)$p[1];
        if (!is_finite($x) || !is_finite($y)) return null;
        $out[] = array(round(max(-1, min(2, $x)), 5), round(max(-1, min(2, $y)), 5));
    }
    return $out;
}

function rch_catalogue() {
    return array(
        array('Morningwood / Richman', '#EA8C2E', array('Studios de cinéma', 'Golf', 'Cimetière', 'Kortz Drive', 'Parking à étages OMC')),
        array('Vinewood / Eclipse Boulevard', '#1F4E79', array('Casino / Hippodrome', 'Parking derrière GoPostal', 'East Vinewood', 'Eclipse Boulevard')),
        array('Centre-ville / Rockford Hills', '#111827', array('Égouts', 'Dépôt ferroviaire', 'Parkings souterrains', 'Parking rouge', 'Los Santos Custom RH')),
        array('Vespucci / Little Seoul', '#4E9A51', array('Marina', 'Ruelle Goma Street', 'Ponton fête foraine', 'Parking multicolore')),
        array('Davis', '#B91C1C', array('Petite casse', 'Camps SDF', 'Parking à étages fourrière', 'Entrepôts sous El Rancho')),
        array('Zone industrielle', '#EAB308', array('La Mesa', 'Los Santos Custom', 'Terrain de drift', 'Fonderie', 'Boucherie', 'Wardogs', 'Tunnel Wardogs')),
        array('Est', '#EF6C6C', array('El Burro', 'Champs pétroliers', 'Plage DHS', 'Mirror Park', 'Barrage')),
        array('Aéroport / La Puerta', '#2F6B34', array('Casse', 'Entrepôts sous l’Arena', 'Los Santos Custom aéroport', 'Entrepôts Nord de l’aéroport', 'Parking à étages', 'Entrepôts Est de l’aéroport', 'Entrepôts Sud de l’aéroport')),
        array('Port', '#3B82F6', array('Docks', 'Merryweather', 'Entrepôts Bugstar', 'Partie Ouest')),
        array('Nord', '#F4A261', array('Ponton Chumash', 'Banham Canyon', 'Observatoire', 'Boule orange', 'Vinewood Bowl')),
        array('Paleto Bay — 201', '#D6608F', array('Plage Paleto — Pop’s Diner', 'Plage Paleto — Station Essence', 'Plage Paleto — Bayview', 'Camp Clochards', 'Usine de Poulet', 'Scierie', 'Camp Altruiste', 'Garage Bateau GOH', 'Tunnel Scierie — Trains', 'Tunnel Scierie — Hauteur Scierie')),
        array('Grapeseed — 201', '#8B5A00', array('Ferme O’Neil', 'Petite Ferme', 'Hangar à GoFast', 'Ponton Bateaux', 'Aérodrome Grapeseed', 'Phare', 'Lachesis Muta')),
        array('Sandy Shores Central — 202', '#1E3A8A', array('Terrain de Cross', 'Youtool', 'Casse Avion', 'Power Station', 'Tunnel Nord-Ouest', 'Stab City', 'Éoliennes', 'Mine de Quartz — Rex’s Diner', 'Camp de Gitans — Rex’s Diner', 'Humane Labs', 'Rebel Radio', 'Supérette Désaffectée')),
        array('Sandy Shores côté GOH — 202', '#14532D', array('Tunnel Nord-Ouest (GOH)', 'Pont Base Militaire', 'Marécages', 'Vignobles', 'Hookies', 'Raton Canyon', 'Cabane Chasseur Cassidy Trail', 'Plage Base Militaire')),
        array('Limite du 203', '#7F1D1D', array('Plage État-Major (DHS)', 'Pacific Bluff')),
        array('Montagnes', '#4C1D95', array('Mont Chianski', 'Mont Chiliad', 'Mont Gordo', 'Mont Josiah')),
    );
}

function rch_decoupage() {
    return array(
        'Morningwood / Richman' => array(array(0.262,0.672),array(0.281,0.651),array(0.309,0.648),array(0.330,0.658),array(0.352,0.663),array(0.352,0.686),array(0.322,0.694),array(0.292,0.699),array(0.268,0.690)),
        'Vinewood / Eclipse Boulevard' => array(array(0.352,0.640),array(0.400,0.620),array(0.455,0.618),array(0.492,0.628),array(0.505,0.645),array(0.492,0.668),array(0.440,0.672),array(0.390,0.668),array(0.355,0.662)),
        'Centre-ville / Rockford Hills' => array(array(0.334,0.690),array(0.390,0.672),array(0.455,0.676),array(0.472,0.700),array(0.462,0.740),array(0.395,0.752),array(0.345,0.735),array(0.330,0.712)),
        'Vespucci / Little Seoul' => array(array(0.258,0.680),array(0.300,0.672),array(0.340,0.686),array(0.352,0.706),array(0.345,0.760),array(0.318,0.790),array(0.286,0.795),array(0.262,0.760),array(0.252,0.715)),
        'Davis' => array(array(0.352,0.752),array(0.430,0.742),array(0.478,0.748),array(0.492,0.775),array(0.480,0.812),array(0.432,0.828),array(0.382,0.820),array(0.352,0.795)),
        'Zone industrielle' => array(array(0.487,0.688),array(0.520,0.690),array(0.540,0.720),array(0.548,0.790),array(0.545,0.840),array(0.512,0.855),array(0.487,0.830),array(0.478,0.760)),
        'Est' => array(array(0.552,0.640),array(0.610,0.618),array(0.680,0.660),array(0.722,0.700),array(0.728,0.775),array(0.690,0.840),array(0.620,0.870),array(0.570,0.845),array(0.552,0.780)),
        'Aéroport / La Puerta' => array(array(0.278,0.842),array(0.330,0.828),array(0.390,0.840),array(0.412,0.872),array(0.405,0.935),array(0.360,0.962),array(0.300,0.950),array(0.272,0.905)),
        'Port' => array(array(0.415,0.868),array(0.492,0.855),array(0.560,0.862),array(0.598,0.885),array(0.596,0.945),array(0.520,0.962),array(0.442,0.955),array(0.412,0.918)),
        'Nord' => array(array(0.205,0.600),array(0.270,0.565),array(0.350,0.560),array(0.410,0.585),array(0.415,0.630),array(0.350,0.648),array(0.280,0.652),array(0.215,0.640)),
        'Paleto Bay — 201' => array(array(0.305,0.115),array(0.345,0.075),array(0.420,0.062),array(0.480,0.085),array(0.495,0.135),array(0.455,0.175),array(0.380,0.185),array(0.318,0.160)),
        'Grapeseed — 201' => array(array(0.575,0.255),array(0.630,0.222),array(0.700,0.235),array(0.730,0.272),array(0.720,0.322),array(0.660,0.345),array(0.598,0.325),array(0.570,0.290)),
        'Sandy Shores Central — 202' => array(array(0.505,0.360),array(0.560,0.332),array(0.630,0.340),array(0.665,0.375),array(0.658,0.432),array(0.600,0.462),array(0.535,0.450),array(0.498,0.408)),
        'Sandy Shores côté GOH — 202' => array(array(0.625,0.400),array(0.680,0.378),array(0.745,0.402),array(0.772,0.445),array(0.755,0.498),array(0.700,0.520),array(0.645,0.495),array(0.618,0.448)),
        'Limite du 203' => array(array(0.168,0.480),array(0.215,0.448),array(0.272,0.455),array(0.298,0.492),array(0.288,0.552),array(0.238,0.585),array(0.188,0.570),array(0.162,0.525)),
        'Montagnes' => array(array(0.368,0.235),array(0.430,0.180),array(0.512,0.178),array(0.565,0.215),array(0.572,0.282),array(0.515,0.325),array(0.440,0.330),array(0.378,0.295)),
    );
}

function rch_ddl($conn) {
    try {
        $st = $conn->query("SELECT mv FROM rch_meta WHERE mk = 'ddl_version'");
        if ($st && $st->fetchColumn() === RCH_DDL_VERSION) return;
    } catch (PDOException $e) {}

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_meta (
        mk VARCHAR(40) NOT NULL PRIMARY KEY, mv TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_secteurs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(120) NOT NULL,
        couleur VARCHAR(9) NOT NULL DEFAULT '#64748B',
        pts LONGTEXT NULL,
        ordre INT NOT NULL DEFAULT 0,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY u_nom (nom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_zones (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        secteur_id INT NOT NULL,
        nom VARCHAR(160) NOT NULL,
        pts LONGTEXT NULL,
        ordre INT NOT NULL DEFAULT 0,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        KEY k_secteur (secteur_id, archived, ordre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_ops (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        titre VARCHAR(160) NOT NULL,
        motif VARCHAR(300) NOT NULL DEFAULT '',
        statut VARCHAR(12) NOT NULL DEFAULT 'active',
        grid_cols TINYINT UNSIGNED NOT NULL DEFAULT 8,
        grid_rows TINYINT UNSIGNED NOT NULL DEFAULT 8,
        rev BIGINT NOT NULL DEFAULT 0,
        by_did VARCHAR(30) NULL,
        by_name VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        KEY k_statut (statut, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_etats (
        op_id INT NOT NULL,
        zone_id INT NOT NULL,
        etat VARCHAR(12) NOT NULL DEFAULT 'non',
        note VARCHAR(300) NOT NULL DEFAULT '',
        by_did VARCHAR(30) NULL,
        by_mat VARCHAR(20) NULL,
        by_name VARCHAR(120) NULL,
        rev BIGINT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        PRIMARY KEY (op_id, zone_id),
        KEY k_sync (op_id, rev)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $conn->exec("ALTER TABLE rch_secteurs ADD COLUMN pts LONGTEXT NULL"); } catch (PDOException $e) {}
    try { $conn->exec("ALTER TABLE rch_ops ADD COLUMN grid_cols TINYINT UNSIGNED NOT NULL DEFAULT 8"); } catch (PDOException $e) {}
    try { $conn->exec("ALTER TABLE rch_ops ADD COLUMN grid_rows TINYINT UNSIGNED NOT NULL DEFAULT 8"); } catch (PDOException $e) {}

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_cells (
        op_id INT NOT NULL,
        cell VARCHAR(8) NOT NULL,
        etat VARCHAR(12) NOT NULL DEFAULT 'non',
        note VARCHAR(300) NOT NULL DEFAULT '',
        by_mat VARCHAR(20) NULL,
        by_name VARCHAR(120) NULL,
        rev BIGINT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        PRIMARY KEY (op_id, cell),
        KEY k_sync (op_id, rev)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS rch_log (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        op_id INT NOT NULL,
        zone_id INT NOT NULL,
        etat VARCHAR(12) NOT NULL,
        note VARCHAR(300) NOT NULL DEFAULT '',
        by_mat VARCHAR(20) NULL,
        by_name VARCHAR(120) NULL,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_op (op_id, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $seeded = false;
    try { $seeded = ((int)$conn->query("SELECT COUNT(*) FROM rch_secteurs")->fetchColumn()) > 0; } catch (PDOException $e) {}
    if (!$seeded) {
        $insS = $conn->prepare("INSERT INTO rch_secteurs (nom, couleur, ordre) VALUES (:n, :c, :o)");
        $insZ = $conn->prepare("INSERT INTO rch_zones (secteur_id, nom, ordre) VALUES (:s, :n, :o)");
        $o = 0;
        foreach (rch_catalogue() as $sec) {
            $insS->execute(array(':n' => $sec[0], ':c' => $sec[1], ':o' => $o++));
            $sid = (int)$conn->lastInsertId();
            $z = 0;
            foreach ($sec[2] as $nom) $insZ->execute(array(':s' => $sid, ':n' => $nom, ':o' => $z++));
        }
    }

    $geo = rch_decoupage();
    $upG = $conn->prepare("UPDATE rch_secteurs SET pts = :p WHERE nom = :n AND pts IS NULL");
    foreach ($geo as $nom => $pts) $upG->execute(array(':p' => json_encode($pts), ':n' => $nom));

    $conn->prepare("INSERT INTO rch_meta (mk, mv) VALUES ('ddl_version', :v)
                    ON DUPLICATE KEY UPDATE mv = :v2")
        ->execute(array(':v' => RCH_DDL_VERSION, ':v2' => RCH_DDL_VERSION));
}

function rch_bump($conn, $opId) {
    $conn->prepare("UPDATE rch_ops SET rev = LAST_INSERT_ID(rev + 1) WHERE id = :i")->execute(array(':i' => $opId));
    return (int)$conn->query("SELECT LAST_INSERT_ID()")->fetchColumn();
}

function rch_destinataires($conn) {
    $out = array();
    try {
        $roles = $conn->query("SELECT role_id FROM module_permissions WHERE module_key = 'dispatch'")
                      ->fetchAll(PDO::FETCH_COLUMN);
        $rows = $conn->query("SELECT discord_id, discord_roles FROM users
                              WHERE discord_id IS NOT NULL AND discord_id <> ''")->fetchAll();
        foreach ($rows as $u) {
            if (!$roles) { $out[] = $u['discord_id']; continue; }
            $mine = $u['discord_roles'] ? (json_decode($u['discord_roles'], true) ?: array()) : array();
            if (is_array($mine) && count(array_intersect($mine, $roles)) > 0) $out[] = $u['discord_id'];
        }
    } catch (PDOException $e) {}
    return $out;
}

function rch_op($conn, $id) {
    $st = $conn->prepare("SELECT * FROM rch_ops WHERE id = :i");
    $st->execute(array(':i' => (int)$id));
    $o = $st->fetch();
    if (!$o) mdt_error(404, 'E-RCH-404', 'Recherche introuvable');
    return $o;
}

$action = rch_g('action');

try {
    $me    = mdt_require_auth($conn, 'recherche');
    $meDid = isset($me['discord_id']) ? $me['discord_id'] : null;
    $meNam = isset($GLOBALS['MDT_ACTOR']['name']) ? $GLOBALS['MDT_ACTOR']['name'] : (string)$meDid;

    if (!mdt_module_access($conn, 'dispatch', $meDid)) {
        mdt_seclog($conn, 'recherche_denied', 'low', array('did' => (string)$meDid));
        mdt_error(403, 'E-RCH-403', 'Acces refuse au module Recherche');
    }
    $isSup = mdt_is_admin($conn, $meDid);

    $meMat = '';
    try {
        $q = $conn->prepare("SELECT matricule, nom_prenom FROM roster WHERE discord_id = :d LIMIT 1");
        $q->execute(array(':d' => $meDid));
        $r = $q->fetch();
        if ($r) { $meMat = (string)$r['matricule']; if ($r['nom_prenom']) $meNam = $r['nom_prenom']; }
    } catch (PDOException $e) {}

    rch_ddl($conn);

    if ($action === 'bootstrap') {
        $secteurs = $conn->query("SELECT id, nom, couleur, pts, ordre FROM rch_secteurs WHERE archived = 0 ORDER BY ordre, nom")->fetchAll();
        foreach ($secteurs as &$sx) { $sx['pts'] = $sx['pts'] ? (json_decode($sx['pts'], true) ?: null) : null; }
        unset($sx);
        $zones = $conn->query("SELECT id, secteur_id, nom, pts, ordre FROM rch_zones WHERE archived = 0 ORDER BY secteur_id, ordre, nom")->fetchAll();
        foreach ($zones as &$z) { $z['pts'] = $z['pts'] ? (json_decode($z['pts'], true) ?: null) : null; }
        unset($z);
        $ops = $conn->query("SELECT id, titre, motif, statut, rev, by_name, created_at, closed_at,
                                    grid_cols, grid_rows
                             FROM rch_ops ORDER BY FIELD(statut,'active','close'), created_at DESC LIMIT 40")->fetchAll();
        echo json_encode(array(
            'secteurs' => $secteurs, 'zones' => $zones, 'ops' => $ops,
            'etats' => $GLOBALS['RCH_ETATS'], 'is_sup' => $isSup ? 1 : 0,
            'me' => array('did' => $meDid, 'mat' => $meMat, 'nom' => $meNam),
        ), RCH_JSON);
        exit;
    }

    if ($action === 'state') {
        $op = rch_op($conn, rch_g('op'));
        $since = (int)rch_g('since');
        $etag = '"rch.' . $op['id'] . '.' . $op['rev'] . '"';
        if ($since > 0 && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header('ETag: ' . $etag); http_response_code(304); exit;
        }
        header('ETag: ' . $etag);
        $st = $conn->prepare("SELECT zone_id, etat, note, by_mat, by_name, updated_at, rev
                              FROM rch_etats WHERE op_id = :o AND rev > :s ORDER BY rev ASC LIMIT 2000");
        $st->execute(array(':o' => (int)$op['id'], ':s' => $since));
        $cs = $conn->prepare("SELECT cell, etat, note, by_mat, by_name, updated_at, rev
                              FROM rch_cells WHERE op_id = :o AND rev > :s ORDER BY rev ASC LIMIT 2000");
        $cs->execute(array(':o' => (int)$op['id'], ':s' => $since));
        echo json_encode(array(
            'op' => array('id' => (int)$op['id'], 'titre' => $op['titre'], 'motif' => $op['motif'],
                          'statut' => $op['statut'], 'created_at' => $op['created_at'],
                          'cols' => (int)$op['grid_cols'], 'rows' => (int)$op['grid_rows'],
                          'par' => $op['by_name']),
            'rev' => (int)$op['rev'], 'full' => $since <= 0,
            'etats' => $st->fetchAll(), 'cells' => $cs->fetchAll(),
        ), RCH_JSON);
        exit;
    }

    if ($action === 'op_create') {
        mdt_post_only();
        if (!$isSup) mdt_error(403, 'E-RCH-403', 'Seule la Supervision peut lancer une recherche');
        $d = mdt_get_post_data('E-RCH-400');
        $titre = rch_txt(isset($d['titre']) ? $d['titre'] : '', 160);
        if ($titre === '') mdt_error(400, 'E-RCH-400', 'Titre requis');
        $cols = max(4, min(16, (int)(isset($d['cols']) ? $d['cols'] : 8)));
        $rows = max(4, min(16, (int)(isset($d['rows']) ? $d['rows'] : 8)));
        $motif = rch_txt(isset($d['motif']) ? $d['motif'] : '', 300);

        $conn->prepare("INSERT INTO rch_ops (titre, motif, grid_cols, grid_rows, by_did, by_name)
                        VALUES (:t, :m, :c, :r, :d, :n)")
            ->execute(array(':t' => $titre, ':m' => $motif, ':c' => $cols, ':r' => $rows,
                            ':d' => $meDid, ':n' => $meNam));
        $opId = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO rch_etats (op_id, zone_id, etat, rev)
                        SELECT :o, id, 'non', 0 FROM rch_zones WHERE archived = 0")
            ->execute(array(':o' => $opId));

        $vises = rch_destinataires($conn);
        $n = 0;
        try {
            $ins = $conn->prepare("INSERT INTO notifications (discord_id, type, titre, corps, lien, ref_type, ref_id, urgent)
                                   VALUES (:d, 'recherche', :t, :c, :l, 'recherche', :r, 1)");
            foreach ($vises as $did) {
                $ins->execute(array(
                    ':d' => $did,
                    ':t' => 'Recherche lancée : ' . $titre,
                    ':c' => ($motif !== '' ? $motif . ' — ' : '') . 'Ouverte par ' . $meNam . '. Rejoins le quadrillage.',
                    ':l' => '/recherche/?op=' . $opId,
                    ':r' => (string)$opId,
                ));
                $n++;
            }
        } catch (PDOException $e) {}

        echo json_encode(array('success' => true, 'op' => $opId, 'notifies' => $n), RCH_JSON);
        exit;
    }

    if ($action === 'cell_set') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        $op = rch_op($conn, isset($d['op']) ? $d['op'] : 0);
        if ($op['statut'] !== 'active') mdt_error(409, 'E-RCH-409', 'Cette recherche est cloturee');
        $cell = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)(isset($d['cell']) ? $d['cell'] : '')));
        if ($cell === '' || strlen($cell) > 8) mdt_error(400, 'E-RCH-400', 'Case invalide');
        $etat = isset($d['etat']) ? (string)$d['etat'] : '';
        if (!in_array($etat, $GLOBALS['RCH_ETATS'], true)) mdt_error(400, 'E-RCH-400', 'Etat inconnu');
        $note = rch_txt(isset($d['note']) ? $d['note'] : '', RCH_NOTE_MAX);

        $rev = rch_bump($conn, (int)$op['id']);
        $conn->prepare("INSERT INTO rch_cells (op_id, cell, etat, note, by_mat, by_name, rev, updated_at)
                        VALUES (:o, :c, :e, :n, :m, :na, :r, NOW())
                        ON DUPLICATE KEY UPDATE etat = :e2, note = :n2, by_mat = :m2, by_name = :na2,
                            rev = :r2, updated_at = NOW()")
            ->execute(array(':o' => (int)$op['id'], ':c' => $cell, ':e' => $etat, ':n' => $note,
                            ':m' => $meMat, ':na' => $meNam, ':r' => $rev,
                            ':e2' => $etat, ':n2' => $note, ':m2' => $meMat, ':na2' => $meNam, ':r2' => $rev));
        echo json_encode(array('success' => true, 'rev' => $rev, 'by_mat' => $meMat), RCH_JSON);
        exit;
    }

    if ($action === 'op_close') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        $op = rch_op($conn, isset($d['op']) ? $d['op'] : 0);
        if (!$isSup && (string)$op['by_did'] !== (string)$meDid) {
            mdt_error(403, 'E-RCH-403', 'Recherche ouverte par un autre agent');
        }
        $conn->prepare("UPDATE rch_ops SET statut = 'close', closed_at = NOW() WHERE id = :i")
            ->execute(array(':i' => (int)$op['id']));
        echo json_encode(array('success' => true), RCH_JSON);
        exit;
    }

    if ($action === 'zone_set') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        $op = rch_op($conn, isset($d['op']) ? $d['op'] : 0);
        if ($op['statut'] !== 'active') mdt_error(409, 'E-RCH-409', 'Cette recherche est cloturee');
        $zid = (int)(isset($d['zone']) ? $d['zone'] : 0);
        $etat = isset($d['etat']) ? (string)$d['etat'] : '';
        if (!in_array($etat, $GLOBALS['RCH_ETATS'], true)) mdt_error(400, 'E-RCH-400', 'Etat inconnu');
        $zq = $conn->prepare("SELECT id FROM rch_zones WHERE id = :i AND archived = 0");
        $zq->execute(array(':i' => $zid));
        if (!$zq->fetchColumn()) mdt_error(404, 'E-RCH-404', 'Zone introuvable');

        $note = rch_txt(isset($d['note']) ? $d['note'] : '', RCH_NOTE_MAX);
        $rev = rch_bump($conn, (int)$op['id']);
        $conn->prepare("INSERT INTO rch_etats (op_id, zone_id, etat, note, by_did, by_mat, by_name, rev, updated_at)
                        VALUES (:o, :z, :e, :n, :d, :m, :na, :r, NOW())
                        ON DUPLICATE KEY UPDATE etat = :e2, note = :n2, by_did = :d2, by_mat = :m2,
                            by_name = :na2, rev = :r2, updated_at = NOW()")
            ->execute(array(
                ':o' => (int)$op['id'], ':z' => $zid, ':e' => $etat, ':n' => $note,
                ':d' => $meDid, ':m' => $meMat, ':na' => $meNam, ':r' => $rev,
                ':e2' => $etat, ':n2' => $note, ':d2' => $meDid, ':m2' => $meMat,
                ':na2' => $meNam, ':r2' => $rev,
            ));
        $conn->prepare("INSERT INTO rch_log (op_id, zone_id, etat, note, by_mat, by_name)
                        VALUES (:o, :z, :e, :n, :m, :na)")
            ->execute(array(':o' => (int)$op['id'], ':z' => $zid, ':e' => $etat,
                            ':n' => $note, ':m' => $meMat, ':na' => $meNam));
        echo json_encode(array('success' => true, 'rev' => $rev, 'by_mat' => $meMat, 'by_name' => $meNam), RCH_JSON);
        exit;
    }

    if ($action === 'zone_geom') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        $zid = (int)(isset($d['zone']) ? $d['zone'] : 0);
        $pts = rch_points(isset($d['pts']) ? $d['pts'] : null);
        if ($pts === null && !empty($d['pts'])) mdt_error(400, 'E-RCH-400', 'Contour invalide');
        $conn->prepare("UPDATE rch_zones SET pts = :p WHERE id = :i")
            ->execute(array(':p' => $pts ? json_encode($pts, RCH_JSON) : null, ':i' => $zid));
        echo json_encode(array('success' => true), RCH_JSON);
        exit;
    }

    if ($action === 'secteur_geom') {
        mdt_post_only();
        if (!$isSup) mdt_error(403, 'E-RCH-403', 'Reserve a la Supervision');
        $d = mdt_get_post_data('E-RCH-400');
        $pts = rch_points(isset($d['pts']) ? $d['pts'] : null);
        if ($pts === null && !empty($d['pts'])) mdt_error(400, 'E-RCH-400', 'Contour invalide');
        $conn->prepare("UPDATE rch_secteurs SET pts = :p WHERE id = :i")
            ->execute(array(':p' => $pts ? json_encode($pts, RCH_JSON) : null,
                            ':i' => (int)(isset($d['secteur']) ? $d['secteur'] : 0)));
        echo json_encode(array('success' => true), RCH_JSON);
        exit;
    }

    if ($action === 'zone_add') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        $sid = (int)(isset($d['secteur']) ? $d['secteur'] : 0);
        $nom = rch_txt(isset($d['nom']) ? $d['nom'] : '', 160);
        if ($nom === '' || !$sid) mdt_error(400, 'E-RCH-400', 'Secteur et nom requis');
        $ord = (int)$conn->query("SELECT COALESCE(MAX(ordre),0)+1 FROM rch_zones WHERE secteur_id = " . $sid)->fetchColumn();
        $conn->prepare("INSERT INTO rch_zones (secteur_id, nom, ordre) VALUES (:s, :n, :o)")
            ->execute(array(':s' => $sid, ':n' => $nom, ':o' => $ord));
        $zid = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO rch_etats (op_id, zone_id, etat, rev)
                        SELECT id, :z, 'non', 0 FROM rch_ops WHERE statut = 'active'")
            ->execute(array(':z' => $zid));
        echo json_encode(array('success' => true, 'zone' => $zid), RCH_JSON);
        exit;
    }

    if ($action === 'secteur_add') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        $nom = rch_txt(isset($d['nom']) ? $d['nom'] : '', 120);
        if ($nom === '') mdt_error(400, 'E-RCH-400', 'Nom requis');
        $ord = (int)$conn->query("SELECT COALESCE(MAX(ordre),0)+1 FROM rch_secteurs")->fetchColumn();
        $conn->prepare("INSERT INTO rch_secteurs (nom, couleur, ordre) VALUES (:n, :c, :o)
                        ON DUPLICATE KEY UPDATE couleur = :c2")
            ->execute(array(':n' => $nom, ':c' => rch_color(isset($d['couleur']) ? $d['couleur'] : ''),
                            ':o' => $ord, ':c2' => rch_color(isset($d['couleur']) ? $d['couleur'] : '')));
        echo json_encode(array('success' => true), RCH_JSON);
        exit;
    }

    if ($action === 'zone_del') {
        mdt_post_only();
        $d = mdt_get_post_data('E-RCH-400');
        if (!$isSup) mdt_error(403, 'E-RCH-403', 'Reserve a la Supervision');
        $conn->prepare("UPDATE rch_zones SET archived = 1 WHERE id = :i")
            ->execute(array(':i' => (int)(isset($d['zone']) ? $d['zone'] : 0)));
        echo json_encode(array('success' => true), RCH_JSON);
        exit;
    }

    if ($action === 'journal') {
        $op = rch_op($conn, rch_g('op'));
        $st = $conn->prepare("SELECT l.at, l.etat, l.note, l.by_mat, l.by_name, z.nom AS zone
                              FROM rch_log l JOIN rch_zones z ON z.id = l.zone_id
                              WHERE l.op_id = :o ORDER BY l.id DESC LIMIT 200");
        $st->execute(array(':o' => (int)$op['id']));
        echo json_encode(array('lignes' => $st->fetchAll()), RCH_JSON);
        exit;
    }

    mdt_error(400, 'E-RCH-400', 'Action inconnue');

} catch (PDOException $e) {
    mdt_error(500, 'E-RCH-500', 'Erreur base de donnees', $e->getMessage());
}
