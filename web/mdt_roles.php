<?php

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403); exit;
}

if (!defined('MDT_ROLE_CHIEF'))           define('MDT_ROLE_CHIEF', '1210813640987377759');
if (!defined('MDT_ROLE_ASSISTANT_CHIEF')) define('MDT_ROLE_ASSISTANT_CHIEF', '1485642111314432082');
if (!defined('MDT_ROLE_DEPUTY_CHIEF'))    define('MDT_ROLE_DEPUTY_CHIEF', '1485642087360757991');
if (!defined('MDT_ROLE_ETAT_MAJOR'))      define('MDT_ROLE_ETAT_MAJOR', '1210813640987377758');
if (!defined('MDT_ROLE_SUPERVISION'))     define('MDT_ROLE_SUPERVISION', '1210813640987377761');
if (!defined('MDT_ROLE_MRD'))             define('MDT_ROLE_MRD', '1432494299840250037');

if (!defined('MDT_GRADE_MAP')) define('MDT_GRADE_MAP', array(
    MDT_ROLE_CHIEF           => 'Chief Of Police',
    MDT_ROLE_ASSISTANT_CHIEF => 'Assistant Chief',
    MDT_ROLE_DEPUTY_CHIEF    => 'Major',
    '1210813640987377762'     => 'Commandant',
    '1210813640987377763'     => 'Captain',
    '1210813641004294225'     => 'Lieutenant',
    '1370086966628061258'     => 'Sergeant II',
    '1210813641004294227'     => 'Sergeant',
    '1210813641004294228'     => 'Caporal',
    '1210813641004294229'     => 'Master Trooper',
    '1210813641004294231'     => 'Senior Trooper',
    '1370774891069968416'     => 'Trooper III',
    '1370774221634146334'     => 'Trooper II',
    '1210813641004294232'     => 'Trooper I',
    '1210813641004294233'     => 'Cadet',
));

if (!defined('MDT_ADMIN_ROLE_IDS')) define('MDT_ADMIN_ROLE_IDS', array(
    MDT_ROLE_SUPERVISION,
    MDT_ROLE_ETAT_MAJOR,
    MDT_ROLE_CHIEF,
    MDT_ROLE_ASSISTANT_CHIEF,
    MDT_ROLE_DEPUTY_CHIEF,
));

if (!defined('MDT_SUP_ELIG_ROLE_IDS')) define('MDT_SUP_ELIG_ROLE_IDS', array(
    '1210813641004294231',
    '1210813641004294229',
    MDT_ROLE_SUPERVISION,
    MDT_ROLE_ETAT_MAJOR,
    '1210813641004294228',
    '1210813641004294227',
    '1370086966628061258',
    '1210813641004294225',
    '1210813640987377763',
    '1210813640987377762',
    MDT_ROLE_DEPUTY_CHIEF,
    MDT_ROLE_ASSISTANT_CHIEF,
    MDT_ROLE_CHIEF,
));

if (!defined('MDT_GRADE_RATES')) define('MDT_GRADE_RATES', array(
    MDT_ROLE_CHIEF           => 1500,
    MDT_ROLE_ASSISTANT_CHIEF => 1000,
    MDT_ROLE_DEPUTY_CHIEF    =>  975,
    '1210813640987377762'     =>  960,
    '1210813640987377763'     =>  950,
    '1210813641004294225'     =>  925,
    '1370086966628061258'     =>  900,
    '1210813641004294227'     =>  875,
    '1210813641004294228'     =>  850,
    '1210813641004294229'     =>  825,
    '1210813641004294231'     =>  800,
    '1370774891069968416'     =>  775,
    '1370774221634146334'     =>  750,
    '1210813641004294232'     =>  725,
    '1210813641004294233'     =>  700,
));

function mdt_grade_labels($conn) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $vivants = array();
    try {
        foreach ($conn->query("SELECT role_id, name FROM discord_roles_cache")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) {
            $n = preg_replace('/^.*\x{2503}/u', '', (string)$n);
            $n = preg_replace('/[^\x{0020}-\x{024F}]/u', '', $n);
            $n = trim(preg_replace('/\s+/u', ' ', $n));
            if ($n !== '') $vivants[(string)$id] = $n;
        }
    } catch (Exception $e) {}
    $out = array();
    foreach (MDT_GRADE_MAP as $id => $defaut) {
        $out[(string)$id] = isset($vivants[(string)$id]) ? $vivants[(string)$id] : $defaut;
    }
    return $cache = $out;
}
