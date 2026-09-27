<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_config.php';
mdt_cors();

$action = isset($_GET['action']) ? $_GET['action'] : '';

function normalise_nom($nom) {
    $n = transliterator_transliterate('Any-Latin; Latin-ASCII', $nom);
    if (!$n) {

        $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom);
    }
    $n = mb_strtolower(trim($n));
    $parts = preg_split('/\s+/', $n);
    sort($parts);
    return implode(' ', $parts);
}

try {

    mdt_require_auth($conn, 'casier');

    if ($action === 'search_personnes') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (strlen($q) < 2) {
            echo json_encode(array(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $qNorm = normalise_nom($q);
        $stmt = $conn->prepare("SELECT p.id, p.nom_complet, p.nom_normalise, p.created_at,
            COUNT(rp.rapport_id) AS nb_rapports
            FROM personnes p
            LEFT JOIN rapport_personnes rp ON rp.personne_id = p.id
            WHERE p.nom_normalise LIKE :q
            GROUP BY p.id
            ORDER BY nb_rapports DESC, p.nom_complet ASC
            LIMIT 10");
        $qEsc = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $qNorm);
        $stmt->execute(array(':q' => '%' . $qEsc . '%'));
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'save_rapport') {
        mdt_post_only();
        $input = mdt_get_post_data('E-1000');
        if (!$input || !isset($input['type']) || !isset($input['titre']) || !isset($input['contenu']) || !isset($input['date_incident'])) {
            mdt_error(400, 'E-1000', 'Donnees rapport invalides: type, titre, contenu et date_incident requis');
        }
        if (!in_array($input['type'], array('DA', 'RA'))) {
            mdt_error(400, 'E-1000', 'Type invalide: DA ou RA attendu');
        }
        $suspects = isset($input['suspects']) && is_array($input['suspects']) ? $input['suspects'] : array();
        if (empty($suspects)) {
            mdt_error(400, 'E-1004', 'Au moins un suspect requis');
        }

        $conn->beginTransaction();
        try {

            $personneIds = array();
            foreach ($suspects as $suspect) {
                $nom = isset($suspect['name']) ? trim($suspect['name']) : '';
                if (!$nom) continue;
                $nomNorm = normalise_nom($nom);

                $stmt = $conn->prepare("SELECT id FROM personnes WHERE nom_normalise = :nn");
                $stmt->execute(array(':nn' => $nomNorm));
                $existing = $stmt->fetch();

                if ($existing) {
                    $personneIds[] = $existing['id'];
                } else {
                    $pId = 'per_' . bin2hex(random_bytes(9));
                    try {
                        $stmt = $conn->prepare("INSERT INTO personnes (id, nom_complet, nom_normalise) VALUES (:id, :nc, :nn)");
                        $stmt->execute(array(':id' => $pId, ':nc' => $nom, ':nn' => $nomNorm));
                        $personneIds[] = $pId;
                    } catch (PDOException $eDup) {

                        $re = $conn->prepare("SELECT id FROM personnes WHERE nom_normalise = :nn");
                        $re->execute(array(':nn' => $nomNorm));
                        $row = $re->fetch();
                        if ($row) $personneIds[] = $row['id']; else throw $eDup;
                    }
                }
            }

            $rapportId = 'rap_' . bin2hex(random_bytes(9));
            $agents = isset($input['agents']) ? $input['agents'] : array();
            $charges = isset($input['charges']) ? $input['charges'] : null;
            $totalAmende = isset($input['total_amende']) ? (int)$input['total_amende'] : 0;
            $metadata = isset($input['metadata']) ? $input['metadata'] : null;

            $stmt = $conn->prepare("INSERT INTO rapports (id, type, titre, contenu, date_incident, heure_incident, lieu, nature, agents, charges, total_amende, metadata) VALUES (:id, :type, :titre, :contenu, :date_incident, :heure, :lieu, :nature, :agents, :charges, :total, :meta)");
            $stmt->execute(array(
                ':id' => $rapportId,
                ':type' => $input['type'],
                ':titre' => substr($input['titre'], 0, 500),
                ':contenu' => $input['contenu'],
                ':date_incident' => $input['date_incident'],
                ':heure' => isset($input['heure_incident']) ? $input['heure_incident'] : null,
                ':lieu' => isset($input['lieu']) ? substr($input['lieu'], 0, 255) : null,
                ':nature' => isset($input['nature']) ? substr($input['nature'], 0, 255) : null,
                ':agents' => json_encode($agents, JSON_UNESCAPED_UNICODE),
                ':charges' => $charges ? json_encode($charges, JSON_UNESCAPED_UNICODE) : null,
                ':total' => $totalAmende,
                ':meta' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
            ));

            $stmtLink = $conn->prepare("INSERT INTO rapport_personnes (rapport_id, personne_id) VALUES (:rid, :pid)");
            foreach ($personneIds as $pid) {
                $stmtLink->execute(array(':rid' => $rapportId, ':pid' => $pid));
            }

            $saisies = isset($input['saisies']) && is_array($input['saisies']) ? $input['saisies'] : array();
            $saisieIds = isset($input['saisie_ids']) && is_array($input['saisie_ids']) ? $input['saisie_ids'] : array();
            $firstPersonne = !empty($personneIds) ? $personneIds[0] : null;

            if (!empty($saisieIds)) {
                $stmtLink = $conn->prepare("UPDATE saisies SET rapport_id = :rid, personne_id = :pid WHERE id = :id");
                foreach ($saisieIds as $sid) {
                    if ($sid) $stmtLink->execute(array(':rid' => $rapportId, ':pid' => $firstPersonne, ':id' => $sid));
                }
            }

            $agentStr = !empty($agents) ? $agents[0] : 'Inconnu';
            $stmtSaisie = $conn->prepare("INSERT INTO saisies (id, agent, date_saisie, type, motif, arme_modele, arme_serial, drogue_variete, drogue_quantite, objet_description, objet_quantite, rapport_id, personne_id) VALUES (:id, :agent, :ds, :type, :motif, :am, :as2, :dv, :dq, :od, :oq, :rid, :pid)");

            foreach ($saisies as $saisie) {

                if (isset($saisie['id']) && in_array($saisie['id'], $saisieIds)) continue;

                $sType = isset($saisie['type']) ? $saisie['type'] : 'objet';
                if (!in_array($sType, array('arme', 'drogue', 'objet'))) continue;
                $sId = 'sai_' . bin2hex(random_bytes(9));

                $stmtSaisie->execute(array(
                    ':id' => $sId,
                    ':agent' => $agentStr,
                    ':ds' => $input['date_incident'],
                    ':type' => $sType,
                    ':motif' => $input['titre'],
                    ':am' => $sType === 'arme' && isset($saisie['arme_modele']) ? substr($saisie['arme_modele'], 0, 255) : null,
                    ':as2' => $sType === 'arme' && isset($saisie['arme_serial']) ? substr($saisie['arme_serial'], 0, 100) : null,
                    ':dv' => $sType === 'drogue' && isset($saisie['drogue_variete']) ? substr($saisie['drogue_variete'], 0, 255) : null,
                    ':dq' => $sType === 'drogue' && isset($saisie['drogue_quantite']) ? substr($saisie['drogue_quantite'], 0, 100) : null,
                    ':od' => $sType === 'objet' && isset($saisie['objet_description']) ? substr($saisie['objet_description'], 0, 500) : null,
                    ':oq' => $sType === 'objet' && isset($saisie['objet_quantite']) ? substr($saisie['objet_quantite'], 0, 100) : null,
                    ':rid' => $rapportId,
                    ':pid' => $firstPersonne
                ));
            }

            $conn->commit();

            $mapIds = array();
            foreach ($suspects as $i => $s) {
                $nom = isset($s['name']) ? trim($s['name']) : '';
                if (!$nom) continue;
                $mapIds[] = array('name' => $nom, 'personne_id' => isset($personneIds[$i]) ? $personneIds[$i] : null);
            }

            echo json_encode(array(
                'success' => true,
                'rapport_id' => $rapportId,
                'personnes' => $mapIds
            ), JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            $conn->rollBack();
            mdt_error(500, 'E-1002', 'Erreur sauvegarde rapport', $e->getMessage());
        }
    }

    elseif ($action === 'get_casier') {
        $personneId = isset($_GET['personne_id']) ? $_GET['personne_id'] : '';
        if (!$personneId) {
            mdt_error(400, 'E-1005', 'personne_id requis');
        }

        $stmt = $conn->prepare("SELECT * FROM personnes WHERE id = :id");
        $stmt->execute(array(':id' => $personneId));
        $personne = $stmt->fetch();
        if (!$personne) {
            mdt_error(404, 'E-1005', 'Personne introuvable');
        }

        $stmt = $conn->prepare("SELECT r.* FROM rapports r
            INNER JOIN rapport_personnes rp ON r.id = rp.rapport_id
            WHERE rp.personne_id = :pid
            ORDER BY r.date_incident DESC, r.created_at DESC");
        $stmt->execute(array(':pid' => $personneId));
        $rapports = $stmt->fetchAll();
        foreach ($rapports as &$r) {
            $r['agents'] = json_decode($r['agents'], true);
            $r['charges'] = $r['charges'] ? json_decode($r['charges'], true) : null;
            $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : null;
        }

        $rapportIds = array_column($rapports, 'id');
        $saisies = array();
        if (!empty($rapportIds)) {
            $placeholders = implode(',', array_fill(0, count($rapportIds), '?'));
            $stmt = $conn->prepare("SELECT * FROM saisies WHERE rapport_id IN ($placeholders) ORDER BY created_at DESC");
            $stmt->execute($rapportIds);
            $saisies = $stmt->fetchAll();
        }

        echo json_encode(array(
            'personne' => $personne,
            'rapports' => $rapports,
            'saisies' => $saisies
        ), JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'list_rapports') {
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
        $stmt = $conn->prepare("SELECT r.*, GROUP_CONCAT(p.nom_complet SEPARATOR ', ') AS suspects
            FROM rapports r
            LEFT JOIN rapport_personnes rp ON r.id = rp.rapport_id
            LEFT JOIN personnes p ON rp.personne_id = p.id
            GROUP BY r.id
            ORDER BY r.created_at DESC
            LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rapports = $stmt->fetchAll();
        foreach ($rapports as &$r) {
            $r['agents'] = json_decode($r['agents'], true);
            $r['charges'] = $r['charges'] ? json_decode($r['charges'], true) : null;
            $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : null;
        }
        echo json_encode($rapports, JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'delete_rapport') {
        mdt_post_only();
        mdt_require_admin($conn, 'casier_delete');
        $input = mdt_get_post_data('E-1003');
        if (!$input || !isset($input['id'])) {
            mdt_error(400, 'E-1003', 'ID rapport requis');
        }
        $rapportId = $input['id'];

        $stmt = $conn->prepare("SELECT id FROM rapports WHERE id = :id");
        $stmt->execute(array(':id' => $rapportId));
        if (!$stmt->fetch()) {
            mdt_error(404, 'E-1001', 'Rapport introuvable');
        }

        $conn->beginTransaction();
        try {

            $conn->prepare("UPDATE saisies SET rapport_id = NULL WHERE rapport_id = :rid")
                ->execute(array(':rid' => $rapportId));

            $conn->prepare("DELETE FROM rapport_personnes WHERE rapport_id = :rid")
                ->execute(array(':rid' => $rapportId));

            $conn->prepare("DELETE FROM rapports WHERE id = :id")
                ->execute(array(':id' => $rapportId));

            $conn->commit();
            echo json_encode(array('success' => true));
        } catch (Exception $e) {
            $conn->rollBack();
            mdt_error(500, 'E-1003', 'Erreur suppression rapport', $e->getMessage());
        }
    }

    else {
        mdt_unknown_action();
    }

} catch (Exception $e) {
    mdt_error(500, 'E-1002', 'Erreur serveur', $e->getMessage());
}
