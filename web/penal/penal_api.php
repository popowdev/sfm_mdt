<?php

require_once __DIR__ . '/../db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$me = mdt_require_auth($conn, 'penal');
$did = isset($me['discord_id']) ? (string)$me['discord_id'] : '';
$isAdmin = mdt_is_admin($conn, $did);

$PEN_CATS = array('B', 'C', 'D', 'E');
$PEN_DOJ  = array('', 'Procureur', 'Jugement');

function penal_ensure($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS penal_code (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        cat CHAR(1) NOT NULL,
        label VARCHAR(255) NOT NULL,
        price INT NOT NULL DEFAULT 0,
        prison VARCHAR(30) DEFAULT NULL,
        doj VARCHAR(20) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        casier TINYINT(1) NOT NULL DEFAULT 0,
        per_unit TINYINT(1) NOT NULL DEFAULT 0,
        multiplier INT DEFAULT NULL,
        tiers LONGTEXT DEFAULT NULL,
        circonstances LONGTEXT DEFAULT NULL,
        ordre INT DEFAULT 0)");
}

if ($action === 'list') {
    penal_ensure($conn);
    $rows = $conn->query("SELECT * FROM penal_code ORDER BY FIELD(cat,'B','C','D','E'), ordre, code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['price'] = (int)$r['price'];
        $r['per_unit'] = (int)$r['per_unit'];
        $r['casier'] = (int)$r['casier'];
        $r['ordre'] = (int)$r['ordre'];
        $r['multiplier'] = ($r['multiplier'] !== null && $r['multiplier'] !== '') ? (int)$r['multiplier'] : null;
        $r['tiers'] = $r['tiers'] ? (json_decode($r['tiers'], true) ?: array()) : array();
        $r['circonstances'] = $r['circonstances'] ? (json_decode($r['circonstances'], true) ?: array()) : array();
    }
    unset($r);
    echo json_encode(array('success' => true, 'infractions' => $rows, 'is_admin' => $isAdmin), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$isAdmin) mdt_error(403, 'E-PEN-403', 'Réservé à l\'administration');

if ($action === 'save') {
    mdt_post_only();
    $d = mdt_get_post_data('E-PEN-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    $code = mb_substr(trim((string)(isset($d['code']) ? $d['code'] : '')), 0, 10);
    if (!preg_match('/^[A-Za-z0-9-]{1,10}$/', $code)) mdt_error(400, 'E-PEN-400', 'Code invalide (lettres/chiffres/-)');
    $cat = strtoupper((string)(isset($d['cat']) ? $d['cat'] : ''));
    if (!in_array($cat, $PEN_CATS, true)) mdt_error(400, 'E-PEN-400', 'Catégorie invalide (B/C/D/E)');
    $label = mb_substr(trim((string)(isset($d['label']) ? $d['label'] : '')), 0, 255);
    if ($label === '') mdt_error(400, 'E-PEN-400', 'Libellé requis');
    $price = max(0, (int)(isset($d['price']) ? $d['price'] : 0));
    $prison = mb_substr(trim((string)(isset($d['prison']) ? $d['prison'] : '')), 0, 30);
        $doj = (isset($d['doj']) && in_array((string)$d['doj'], $PEN_DOJ, true)) ? (string)$d['doj'] : '';
    $note = mb_substr((string)(isset($d['note']) ? $d['note'] : ''), 0, 500);
    $casier = !empty($d['casier']) ? 1 : 0;
    $per_unit = !empty($d['per_unit']) ? 1 : 0;
    $mult = (isset($d['multiplier']) && $d['multiplier'] !== '' && $d['multiplier'] !== null) ? (int)$d['multiplier'] : null;
    $tiers = array();
    if (isset($d['tiers']) && is_array($d['tiers'])) foreach ($d['tiers'] as $t) { if (is_numeric($t)) $tiers[] = (int)$t; }
    $circ = array();
    if (isset($d['circonstances']) && is_array($d['circonstances'])) foreach ($d['circonstances'] as $x) { $x = mb_substr(trim((string)$x), 0, 255); if ($x !== '') $circ[] = $x; }
    penal_ensure($conn);
    $chk = $conn->prepare("SELECT id FROM penal_code WHERE code = ? AND id <> ?");
    $chk->execute(array($code, $id));
    if ($chk->fetch()) mdt_error(409, 'E-PEN-409', 'Code « ' . $code . ' » déjà utilisé');
    $tj = $tiers ? json_encode($tiers) : null;
    $cj = $circ ? json_encode($circ, JSON_UNESCAPED_UNICODE) : null;
    $before = null;
    $after = array('code'=>$code, 'cat'=>$cat, 'label'=>$label, 'price'=>$price, 'prison'=>$prison, 'doj'=>$doj, 'note'=>$note, 'casier'=>$casier, 'per_unit'=>$per_unit, 'multiplier'=>$mult, 'tiers'=>$tiers, 'circonstances'=>$circ);
    if ($id > 0) {
        $bp = $conn->prepare("SELECT code,cat,label,price,prison,doj,note,casier,per_unit,multiplier FROM penal_code WHERE id=?"); $bp->execute(array($id)); $before = $bp->fetch(PDO::FETCH_ASSOC) ?: null;
        $conn->prepare("UPDATE penal_code SET code=?,cat=?,label=?,price=?,prison=?,doj=?,note=?,casier=?,per_unit=?,multiplier=?,tiers=?,circonstances=? WHERE id=?")
            ->execute(array($code, $cat, $label, $price, $prison, $doj, $note, $casier, $per_unit, $mult, $tj, $cj, $id));
    } else {
        $ord = (int)$conn->query("SELECT COALESCE(MAX(ordre),0)+1 FROM penal_code WHERE cat = " . $conn->quote($cat))->fetchColumn();
        $conn->prepare("INSERT INTO penal_code (code,cat,label,price,prison,doj,note,casier,per_unit,multiplier,tiers,circonstances,ordre) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array($code, $cat, $label, $price, $prison, $doj, $note, $casier, $per_unit, $mult, $tj, $cj, $ord));
        $id = (int)$conn->lastInsertId();
    }
    if (function_exists('mdt_audit')) mdt_audit($conn, 'save', array('entity_type'=>'penal_code', 'entity_id'=>$code, 'summary'=>$code.' — '.$label, 'before'=>$before, 'after'=>$after));
    echo json_encode(array('success' => true, 'id' => $id));
    exit;
}

if ($action === 'delete') {
    mdt_post_only();
    $d = mdt_get_post_data('E-PEN-400'); if (!is_array($d)) $d = array();
    $id = (int)(isset($d['id']) ? $d['id'] : 0);
    if ($id < 1) mdt_error(400, 'E-PEN-400', 'id requis');
    $conn->prepare("DELETE FROM penal_code WHERE id = ?")->execute(array($id));
    echo json_encode(array('success' => true));
    exit;
}

mdt_error(400, 'E-PEN-400', 'Action inconnue');
