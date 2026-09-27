<?php

function mdt_embed_types() {
    return array('carte', 'plainte', 'cid', 'saisie', 'document');
}

function mdt_embed_clean($raw) {
    if (!is_array($raw)) return null;
    $t = isset($raw['t']) ? (string)$raw['t'] : '';
    $id = isset($raw['id']) ? (string)$raw['id'] : '';
    if (!in_array($t, mdt_embed_types(), true)) return null;
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '' || strlen($id) > 48) return null;
    return array('t' => $t, 'id' => $id);
}

function mdt_embed_locked($t) {
    $meta = mdt_embed_meta($t);
    return array(
        'type'  => $t,
        'ok'    => false,
        'title' => 'Ressource non accessible',
        'sub'   => 'Tu n’as pas les droits pour l’ouvrir',
        'url'   => '',
        'icon'  => 'fa-lock',
        'color' => '#64748b',
        'kind'  => $meta['kind'],
    );
}

function mdt_embed_meta($t) {
    $m = array(
        'carte'    => array('kind' => 'Carte tactique', 'icon' => 'fa-map-location-dot', 'color' => '#22d3ee', 'module' => 'dispatch'),
        'plainte'  => array('kind' => 'Plainte',        'icon' => 'fa-file-signature',   'color' => '#f97316', 'module' => 'plainte'),
        'cid'      => array('kind' => 'Dossier CID',    'icon' => 'fa-folder-open',      'color' => '#a855f7', 'module' => ''),
        'saisie'   => array('kind' => 'Saisie',         'icon' => 'fa-box-archive',      'color' => '#eab308', 'module' => 'saisies'),
        'document' => array('kind' => 'Documentation',  'icon' => 'fa-book',             'color' => '#38bdf8', 'module' => 'documents'),
    );
    return isset($m[$t]) ? $m[$t] : array('kind' => 'Ressource', 'icon' => 'fa-link', 'color' => '#94a3b8', 'module' => '');
}

function mdt_embed_resolve($conn, $ref, $viewerDid) {
    $ref = mdt_embed_clean($ref);
    if (!$ref) return null;
    $t = $ref['t'];
    $id = $ref['id'];
    $meta = mdt_embed_meta($t);

    if ($meta['module'] !== '' && !mdt_module_access($conn, $meta['module'], $viewerDid)) {
        return mdt_embed_locked($t);
    }

    $title = '';
    $sub = '';
    $url = '';

    try {
        if ($t === 'carte') {
            $st = $conn->prepare("SELECT label, kind, bg_kind, roles, owner_did, owner_name FROM tac_boards
                                  WHERE id = :i AND archived = 0");
            $st->execute(array(':i' => $id));
            $b = $st->fetch();
            if (!$b) return null;
            if ($b['kind'] === 'private') return mdt_embed_locked($t);
            $req = $b['roles'] ? (json_decode($b['roles'], true) ?: array()) : array();
            if ($req) {
                $ok = mdt_is_admin($conn, $viewerDid) || (string)$b['owner_did'] === (string)$viewerDid;
                if (!$ok) {
                    $r = $conn->prepare("SELECT discord_roles FROM users WHERE discord_id = :d LIMIT 1");
                    $r->execute(array(':d' => $viewerDid));
                    $raw = $r->fetchColumn();
                    $mine = $raw ? (json_decode($raw, true) ?: array()) : array();
                    if (!is_array($mine)) $mine = array();
                    $ok = count(array_intersect($mine, $req)) > 0;
                }
                if (!$ok) return mdt_embed_locked($t);
            }
            $title = $b['label'];
            $sub = ($b['bg_kind'] === 'image' ? 'Briefing sur image' : 'Plan sur la carte de Los Santos')
                 . ($b['owner_name'] ? ' — ' . $b['owner_name'] : '');
            $url = '/carte/?board=' . rawurlencode($id);

        } elseif ($t === 'plainte') {
            $st = $conn->prepare("SELECT id, pe_prenom_nom, date_plainte, redacteur FROM plaintes WHERE id = :i");
            $st->execute(array(':i' => $id));
            $p = $st->fetch();
            if (!$p) return null;
            $title = 'Plainte de ' . ($p['pe_prenom_nom'] ?: 'plaignant inconnu');
            $sub = ($p['date_plainte'] ? 'Déposée le ' . $p['date_plainte'] : '')
                 . ($p['redacteur'] ? ' — rédigée par ' . $p['redacteur'] : '');
            $url = '/plainte/' . rawurlencode($id);

        } elseif ($t === 'cid') {
            $st = $conn->prepare("SELECT id, nom, description FROM cid_dossiers WHERE id = :i AND archived_at IS NULL");
            $st->execute(array(':i' => $id));
            $c = $st->fetch();
            if (!$c) return null;
            $title = $c['nom'];
            $sub = mb_substr((string)$c['description'], 0, 90, 'UTF-8');
            $url = '/cid/dossier/' . rawurlencode($id);

        } elseif ($t === 'saisie') {
            $st = $conn->prepare("SELECT id, type, motif, agent, date_saisie FROM saisies WHERE id = :i");
            $st->execute(array(':i' => $id));
            $s = $st->fetch();
            if (!$s) return null;
            $labels = array('arme' => 'Arme', 'drogue' => 'Drogue', 'objet' => 'Objet');
            $kindLbl = isset($labels[$s['type']]) ? $labels[$s['type']] : $s['type'];
            $title = 'Saisie — ' . $kindLbl . ($s['motif'] ? ' : ' . $s['motif'] : '');
            $sub = ($s['agent'] ? 'Par ' . $s['agent'] : '') . ($s['date_saisie'] ? ' le ' . $s['date_saisie'] : '');
            $url = '/saisies/' . rawurlencode($id);

        } elseif ($t === 'document') {
            $st = $conn->prepare("SELECT id, titre, extrait, slug FROM doc_documents WHERE id = :i AND statut = 'publie'");
            $st->execute(array(':i' => $id));
            $d = $st->fetch();
            if (!$d) return null;
            $title = $d['titre'];
            $sub = mb_substr((string)$d['extrait'], 0, 90, 'UTF-8');
            $url = '/documents/' . rawurlencode($d['slug'] ? $d['slug'] : $id);
        }
    } catch (PDOException $e) {
        return null;
    }

    if ($title === '') return null;

    return array(
        'type'  => $t,
        'ok'    => true,
        'title' => mb_substr($title, 0, 120, 'UTF-8'),
        'sub'   => mb_substr(trim($sub), 0, 140, 'UTF-8'),
        'url'   => $url,
        'icon'  => $meta['icon'],
        'color' => $meta['color'],
        'kind'  => $meta['kind'],
    );
}
