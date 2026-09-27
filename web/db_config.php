<?php
 
 
 
 

 
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 

 

function mdt_error($httpCode, $errCode, $publicMsg, $logDetail = '') {
    if ($logDetail) error_log("[MDT $errCode] $logDetail");
    http_response_code($httpCode);
    echo json_encode(array('error' => $publicMsg, 'code' => $errCode), JSON_UNESCAPED_UNICODE);
    exit;
}

function mdt_cors() {
    $allowed = array('https://exemple.tld', 'http://exemple.tld', 'https://exemple.tld', 'http://exemple.tld', 'http://localhost');
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
     
    $isNui = $origin && (strpos($origin, 'nui://') === 0 || strpos($origin, 'https://cfx-nui-') === 0);
    if (in_array($origin, $allowed) || $isNui) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, If-None-Match, X-Api-Key');
    header('Access-Control-Expose-Headers: ETag');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

function mdt_post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mdt_error(405, 'E-102', 'Methode non autorisee');
    }
}

function mdt_get_post_data($errCode = 'E-104') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) && $data !== null) {
        mdt_error(400, $errCode, 'Donnees JSON invalides');
    }
    return $data;
}

function mdt_unknown_action() {
    mdt_error(400, 'E-103', 'Action inconnue');
}

 

$db_host = '127.0.0.1';
$db_name = 'rp_mdt';
$db_user = 'rp_mdt';
$db_pass = 'REMPLACER_MOT_DE_PASSE';

 
date_default_timezone_set('Europe/Paris');

try {
    $conn = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        )
    );
     
    try { $conn->exec("SET time_zone = 'Europe/Paris'"); } catch (Exception $e) { try { $conn->exec("SET time_zone = '+02:00'"); } catch (Exception $e2) {} }
} catch (PDOException $e) {
    mdt_error(500, 'E-100', 'Erreur serveur', 'Connexion BDD: ' . $e->getMessage());
}

 
require_once __DIR__ . '/mdt_security.php';

 
 
 
 
 
 
 
 

$flagFile = sys_get_temp_dir() . '/mdt_tables_ready_v3.flag';
$tablesReady = file_exists($flagFile) && (time() - filemtime($flagFile)) < 3600;  

if (!$tablesReady) {
    try {
         
        function mdt_column_exists($conn, $table, $column) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $stmt->execute(array(':t' => $table, ':c' => $column));
            return (int)$stmt->fetchColumn() > 0;
        }

         
        function mdt_table_exists($conn, $table) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
            $stmt->execute(array(':t' => $table));
            return (int)$stmt->fetchColumn() > 0;
        }

         
        function mdt_index_exists($conn, $table, $index) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i");
            $stmt->execute(array(':t' => $table, ':i' => $index));
            return (int)$stmt->fetchColumn() > 0;
        }

         
        function mdt_auto_fix($conn, $description, $sql) {
            try {
                $conn->exec($sql);
                error_log("[MDT AUTO-FIX] OK: $description");
                return true;
            } catch (PDOException $e) {
                error_log("[MDT AUTO-FIX] ECHEC: $description — " . $e->getMessage());
                return false;
            }
        }

         
         
         

        $conn->exec("CREATE TABLE IF NOT EXISTS penal_code (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL UNIQUE,
            cat CHAR(1) NOT NULL,
            label VARCHAR(255) NOT NULL,
            price INT NOT NULL DEFAULT 0,
            prison VARCHAR(20) DEFAULT NULL,
            doj VARCHAR(20) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            per_unit TINYINT(1) DEFAULT 0,
            multiplier INT DEFAULT NULL,
            tiers JSON DEFAULT NULL,
            circonstances JSON DEFAULT NULL,
            ordre INT NOT NULL DEFAULT 0,
            INDEX idx_cat_ordre (cat, ordre)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS roster (
            id INT AUTO_INCREMENT PRIMARY KEY,
            matricule VARCHAR(10) NOT NULL UNIQUE,
            nom_prenom VARCHAR(255) NOT NULL,
            discord_id VARCHAR(30) DEFAULT NULL,
            ordre INT NOT NULL DEFAULT 0,
            INDEX idx_ordre (ordre)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS td_epreuves (
            id VARCHAR(50) PRIMARY KEY,
            data JSON NOT NULL
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS td_sessions (
            id VARCHAR(50) PRIMARY KEY,
            data JSON NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS td_fiches (
            id VARCHAR(50) PRIMARY KEY,
            data JSON NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS td_notations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data JSON NOT NULL
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS sd_analyses (
            id VARCHAR(50) PRIMARY KEY,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            author VARCHAR(100) NOT NULL,
            case_name VARCHAR(255) NOT NULL,
            evidence_type VARCHAR(50) NOT NULL,
            data JSON DEFAULT NULL
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS sd_reports (
            id VARCHAR(50) PRIMARY KEY,
            date DATETIME DEFAULT CURRENT_TIMESTAMP,
            author VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS plaintes (
            id VARCHAR(50) PRIMARY KEY,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            redacteur VARCHAR(100) NOT NULL,
            grade_redacteur VARCHAR(50) DEFAULT '',
            date_plainte DATE NOT NULL,
            pe_prenom_nom VARCHAR(255) NOT NULL,
            pe_telephone VARCHAR(30) DEFAULT '',
            pe_verif_mdt VARCHAR(10) DEFAULT 'Non',
            deroule_faits TEXT NOT NULL,
            individus JSON DEFAULT NULL,
            faits JSON DEFAULT NULL
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS plainte_tags (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
            icon VARCHAR(50) DEFAULT NULL,
            ordre INT NOT NULL DEFAULT 0
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS plainte_tag_assignments (
            plainte_id VARCHAR(50) NOT NULL,
            tag_id VARCHAR(50) NOT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (plainte_id, tag_id)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS saisies (
            id VARCHAR(50) PRIMARY KEY,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            agent VARCHAR(100) NOT NULL,
            date_saisie DATE NOT NULL,
            type VARCHAR(10) NOT NULL,
            motif TEXT NOT NULL,
            notes TEXT DEFAULT NULL,
            arme_modele VARCHAR(255) DEFAULT NULL,
            arme_serial VARCHAR(100) DEFAULT NULL,
            arme_accessoires VARCHAR(500) DEFAULT NULL,
            sd_analyse_id VARCHAR(50) DEFAULT NULL,
            drogue_variete VARCHAR(255) DEFAULT NULL,
            drogue_quantite VARCHAR(100) DEFAULT NULL,
            objet_description VARCHAR(500) DEFAULT NULL,
            objet_quantite VARCHAR(100) DEFAULT NULL,
            poste VARCHAR(10) DEFAULT 'sud',
            vol_statut VARCHAR(20) DEFAULT NULL,
            vol_raison TEXT DEFAULT NULL,
            vol_date DATETIME DEFAULT NULL,
            vol_agent VARCHAR(100) DEFAULT NULL,
            INDEX idx_type (type),
            INDEX idx_date (date_saisie),
            INDEX idx_sd_link (sd_analyse_id)
        )");

         

        $conn->exec("CREATE TABLE IF NOT EXISTS personnes (
            id VARCHAR(50) PRIMARY KEY,
            nom_complet VARCHAR(255) NOT NULL,
            nom_normalise VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_nom_normalise (nom_normalise)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS rapports (
            id VARCHAR(50) PRIMARY KEY,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            type VARCHAR(2) NOT NULL,
            titre VARCHAR(500) NOT NULL,
            contenu TEXT NOT NULL,
            date_incident DATE NOT NULL,
            heure_incident VARCHAR(10) DEFAULT NULL,
            lieu VARCHAR(255) DEFAULT NULL,
            nature VARCHAR(255) DEFAULT NULL,
            agents JSON NOT NULL,
            charges JSON DEFAULT NULL,
            total_amende INT DEFAULT 0,
            metadata JSON DEFAULT NULL,
            INDEX idx_type (type),
            INDEX idx_date (date_incident)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS rapport_personnes (
            rapport_id VARCHAR(50) NOT NULL,
            personne_id VARCHAR(50) NOT NULL,
            PRIMARY KEY (rapport_id, personne_id),
            INDEX idx_personne (personne_id)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS maintenance (
            module_key VARCHAR(30) PRIMARY KEY,
            is_disabled TINYINT(1) NOT NULL DEFAULT 0,
            message VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

         
        $cnt = $conn->query("SELECT COUNT(*) FROM maintenance")->fetchColumn();
        if ((int)$cnt === 0) {
            $modules = array('mdt', 'sd', 'upload', 'admin', 'td', 'suivi', 'plainte', 'saisies', 'dispatch');
            $ins = $conn->prepare("INSERT IGNORE INTO maintenance (module_key, is_disabled) VALUES (:k, 0)");
            foreach ($modules as $m) {
                $ins->execute(array(':k' => $m));
            }
        }

         
        $cols = $conn->query("SHOW COLUMNS FROM saisies LIKE 'rapport_id'")->fetchAll();
        if (count($cols) === 0) {
            $conn->exec("ALTER TABLE saisies ADD COLUMN rapport_id VARCHAR(50) DEFAULT NULL, ADD COLUMN personne_id VARCHAR(50) DEFAULT NULL");
            $conn->exec("ALTER TABLE saisies ADD INDEX idx_rapport (rapport_id), ADD INDEX idx_personne_sai (personne_id)");
        }

         
        $cols = $conn->query("SHOW COLUMNS FROM saisies LIKE 'arme_accessoires'")->fetchAll();
        if (count($cols) === 0) {
            $conn->exec("ALTER TABLE saisies ADD COLUMN arme_accessoires VARCHAR(500) DEFAULT NULL AFTER arme_serial");
        }

         
        $conn->exec("CREATE TABLE IF NOT EXISTS drogue_types (
            id VARCHAR(50) PRIMARY KEY,
            nom VARCHAR(100) NOT NULL,
            icon VARCHAR(50) NOT NULL DEFAULT 'fa-pills',
            couleur VARCHAR(20) NOT NULL DEFAULT '#d8b4fe',
            aliases TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

         

        $conn->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            discord_id VARCHAR(30) DEFAULT NULL,
            discord_username VARCHAR(100) DEFAULT NULL,
            discord_avatar VARCHAR(255) DEFAULT NULL,
            discord_roles JSON DEFAULT NULL,
            discord_nick VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_discord (discord_id),
            INDEX idx_username (username)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            token VARCHAR(64) PRIMARY KEY,
            user_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_user (user_id),
            INDEX idx_expires (expires_at)
        )");

         

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_statuts (
            id VARCHAR(50) PRIMARY KEY,
            label VARCHAR(100) NOT NULL,
            couleur VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
            ordre INT NOT NULL DEFAULT 0,
            INDEX idx_ordre (ordre)
        )");

         
        $cntDS = $conn->query("SELECT COUNT(*) FROM dispatch_statuts")->fetchColumn();
        if ((int)$cntDS === 0) {
            $conn->exec("INSERT INTO dispatch_statuts (id, label, couleur, ordre) VALUES
                ('ds_disponible', 'Disponible', '#10b981', 0),
                ('ds_patrouille', 'En patrouille', '#3b82f6', 1),
                ('ds_intervention', 'En intervention', '#f59e0b', 2),
                ('ds_hors_service', 'Hors service', '#ef4444', 3)
            ");
        }

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_vehicules (
            id VARCHAR(50) PRIMARY KEY,
            label VARCHAR(100) NOT NULL,
            type VARCHAR(50) DEFAULT 'Sedan',
            ordre INT NOT NULL DEFAULT 0,
            INDEX idx_ordre (ordre)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_patrouilles (
            id VARCHAR(50) PRIMARY KEY,
            indicatif VARCHAR(50) NOT NULL,
            vehicule VARCHAR(100) DEFAULT NULL,
            canal_radio VARCHAR(20) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            statut_id VARCHAR(50) NOT NULL DEFAULT 'ds_disponible',
            statut_since DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_statut (statut_id)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_patrouille_agents (
            patrouille_id VARCHAR(50) NOT NULL,
            roster_id INT NOT NULL,
            PRIMARY KEY (patrouille_id, roster_id),
            INDEX idx_roster (roster_id)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_interventions (
            id VARCHAR(50) PRIMARY KEY,
            type VARCHAR(50) NOT NULL,
            lieu VARCHAR(255) DEFAULT NULL,
            priorite ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            description TEXT DEFAULT NULL,
            statut ENUM('nouveau','en_cours','termine') NOT NULL DEFAULT 'nouveau',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            closed_at DATETIME DEFAULT NULL,
            INDEX idx_statut (statut),
            INDEX idx_priorite (priorite)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_intervention_patrouilles (
            intervention_id VARCHAR(50) NOT NULL,
            patrouille_id VARCHAR(50) NOT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (intervention_id, patrouille_id),
            INDEX idx_patrouille (patrouille_id)
        )");

         
        $conn->exec("INSERT IGNORE INTO maintenance (module_key, is_disabled, message) VALUES ('dispatch', 1, 'Module en cours de developpement')");

         

        $conn->exec("CREATE TABLE IF NOT EXISTS module_permissions (
            module_key VARCHAR(30) NOT NULL,
            role_id VARCHAR(30) NOT NULL,
            PRIMARY KEY (module_key, role_id)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS discord_roles_cache (
            role_id VARCHAR(30) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#99aab5',
            position INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

         

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_action_buttons (
            id VARCHAR(50) PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            label VARCHAR(100) NOT NULL,
            intervention_type VARCHAR(50) NOT NULL DEFAULT 'autre',
            icon VARCHAR(50) DEFAULT 'fa-circle-exclamation',
            color VARCHAR(7) DEFAULT '#3b82f6',
            fields_config JSON DEFAULT NULL,
            statut_target VARCHAR(50) DEFAULT 'ds_intervention',
            is_reset TINYINT(1) DEFAULT 0,
            ordre INT NOT NULL DEFAULT 0,
            INDEX idx_ordre (ordre)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            roster_id INT NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME DEFAULT NULL,
            duration_minutes INT DEFAULT NULL,
            service_date DATE NOT NULL,
            INDEX idx_user (user_id),
            INDEX idx_roster (roster_id),
            INDEX idx_date (service_date),
            INDEX idx_active (end_at)
        )");

        $conn->exec("CREATE TABLE IF NOT EXISTS dispatch_dispatchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            roster_id INT NOT NULL,
            role ENUM('dispatcher','co_dispatcher') NOT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_role (role)
        )");

         
         
         
         
         

        $required_columns = array(
             
            array('roster', 'discord_id', "ALTER TABLE roster ADD COLUMN discord_id VARCHAR(30) DEFAULT NULL"),
             
            array('saisies', 'rapport_id', "ALTER TABLE saisies ADD COLUMN rapport_id VARCHAR(50) DEFAULT NULL, ADD COLUMN personne_id VARCHAR(50) DEFAULT NULL"),
            array('saisies', 'arme_accessoires', "ALTER TABLE saisies ADD COLUMN arme_accessoires VARCHAR(500) DEFAULT NULL AFTER arme_serial"),
            array('saisies', 'poste', "ALTER TABLE saisies ADD COLUMN poste VARCHAR(10) DEFAULT 'sud'"),
            array('saisies', 'vol_statut', "ALTER TABLE saisies ADD COLUMN vol_statut VARCHAR(20) DEFAULT NULL, ADD COLUMN vol_raison TEXT DEFAULT NULL, ADD COLUMN vol_date DATETIME DEFAULT NULL, ADD COLUMN vol_agent VARCHAR(100) DEFAULT NULL"),
             
            array('dispatch_interventions', 'created_by_user_id', "ALTER TABLE dispatch_interventions ADD COLUMN created_by_user_id INT DEFAULT NULL, ADD COLUMN action_button_id VARCHAR(50) DEFAULT NULL, ADD COLUMN action_fields_data JSON DEFAULT NULL"),
        );

        foreach ($required_columns as $check) {
            list($table, $col, $fix_sql) = $check;
            if (mdt_table_exists($conn, $table) && !mdt_column_exists($conn, $table, $col)) {
                mdt_auto_fix($conn, "Ajout colonne $table.$col", $fix_sql);
            }
        }

         
         
         

        $required_indexes = array(
            array('saisies', 'idx_rapport', 'rapport_id', "ALTER TABLE saisies ADD INDEX idx_rapport (rapport_id)"),
            array('saisies', 'idx_personne_sai', 'personne_id', "ALTER TABLE saisies ADD INDEX idx_personne_sai (personne_id)"),
        );

        foreach ($required_indexes as $check) {
            list($table, $idx, $col, $fix_sql) = $check;
            if (mdt_table_exists($conn, $table) && mdt_column_exists($conn, $table, $col) && !mdt_index_exists($conn, $table, $idx)) {
                mdt_auto_fix($conn, "Ajout index $table.$idx", $fix_sql);
            }
        }

         
        $cntAB = $conn->query("SELECT COUNT(*) FROM dispatch_action_buttons")->fetchColumn();
        if ((int)$cntAB === 0) {
            $conn->exec("INSERT INTO dispatch_action_buttons (id, code, label, intervention_type, icon, color, fields_config, statut_target, is_reset, ordre) VALUES
                ('dab_1008', '10-8', 'Prise de service', 'autre', 'fa-right-to-bracket', '#10b981', NULL, 'ds_disponible', 0, 1),
                ('dab_1010', '10-10', 'Fin de service', 'autre', 'fa-right-from-bracket', '#64748b', NULL, 'ds_hors_service', 0, 2),
                ('dab_1019', '10-19', 'En route vers...', 'autre', 'fa-route', '#3b82f6', '[{\"name\":\"destination\",\"label\":\"Destination\",\"type\":\"text\",\"required\":true}]', 'ds_patrouille', 0, 3),
                ('dab_1031', '10-31', 'Coups de feu', 'tir', 'fa-crosshairs', '#ef4444', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"nb_tirs\",\"label\":\"Nombre de tirs estimes\",\"type\":\"text\",\"required\":false}]', 'ds_intervention', 0, 4),
                ('dab_1035', '10-35', 'Demande de renforts', 'autre', 'fa-people-group', '#f97316', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"raison\",\"label\":\"Raison\",\"type\":\"text\",\"required\":true}]', 'ds_intervention', 0, 5),
                ('dab_1037', '10-37', 'Cambriolage', 'vol', 'fa-house-crack', '#f59e0b', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"suspects\",\"label\":\"Nombre de suspects\",\"type\":\"text\",\"required\":false}]', 'ds_intervention', 0, 6),
                ('dab_1038', '10-38', 'Controle routier', 'controle', 'fa-hand', '#06b6d4', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"plaque\",\"label\":\"Plaque vehicule\",\"type\":\"text\",\"required\":false}]', 'ds_intervention', 0, 7),
                ('dab_1040', '10-40', 'Braquage minime', 'vol', 'fa-mask', '#f97316', '[{\"name\":\"lieu\",\"label\":\"Lieu (superette, vol, hack...)\",\"type\":\"text\",\"required\":true},{\"name\":\"suspects\",\"label\":\"Nombre de suspects\",\"type\":\"text\",\"required\":false},{\"name\":\"armes\",\"label\":\"Armes visibles\",\"type\":\"select\",\"options\":[\"Oui\",\"Non\",\"Inconnue\"],\"required\":true}]', 'ds_intervention', 0, 8),
                ('dab_1041', '10-41', 'Debut de patrouille', 'autre', 'fa-shield-halved', '#10b981', NULL, 'ds_patrouille', 0, 9),
                ('dab_1042', '10-42', 'Fin de patrouille', 'autre', 'fa-shield', '#64748b', NULL, 'ds_disponible', 0, 10),
                ('dab_1049', '10-49', 'Homicide', 'agression', 'fa-skull', '#ef4444', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"victimes\",\"label\":\"Nombre de victimes\",\"type\":\"text\",\"required\":true}]', 'ds_intervention', 0, 11),
                ('dab_1050', '10-50', 'Accident', 'accident', 'fa-car-burst', '#f59e0b', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"vehicules\",\"label\":\"Nombre de vehicules\",\"type\":\"text\",\"required\":false},{\"name\":\"blesses\",\"label\":\"Blesses\",\"type\":\"select\",\"options\":[\"Oui\",\"Non\",\"Inconnu\"],\"required\":true}]', 'ds_intervention', 0, 12),
                ('dab_1056', '10-56', 'Refus d obtemperer', 'poursuite', 'fa-gauge-high', '#ef4444', '[{\"name\":\"marque\",\"label\":\"Marque vehicule\",\"type\":\"text\",\"required\":true},{\"name\":\"couleur\",\"label\":\"Couleur vehicule\",\"type\":\"text\",\"required\":true},{\"name\":\"occupants\",\"label\":\"Nombre occupants\",\"type\":\"text\",\"required\":true},{\"name\":\"armes\",\"label\":\"Armes\",\"type\":\"select\",\"options\":[\"Oui\",\"Non\",\"Inconnue\"],\"required\":true},{\"name\":\"position\",\"label\":\"Position\",\"type\":\"select\",\"options\":[\"P1 (Poursuit)\",\"P2 (Anticipe)\"],\"required\":true}]', 'ds_intervention', 0, 13),
                ('dab_1060', '10-60', 'Vente de drogue', 'suspect', 'fa-cannabis', '#8b5cf6', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"suspects\",\"label\":\"Description suspects\",\"type\":\"text\",\"required\":false}]', 'ds_intervention', 0, 14),
                ('dab_1070', '10-70', 'Poursuite a pieds', 'poursuite', 'fa-person-running', '#f97316', '[{\"name\":\"lieu\",\"label\":\"Lieu / Direction\",\"type\":\"text\",\"required\":true},{\"name\":\"description\",\"label\":\"Description suspect\",\"type\":\"text\",\"required\":true},{\"name\":\"armes\",\"label\":\"Armes\",\"type\":\"select\",\"options\":[\"Oui\",\"Non\",\"Inconnue\"],\"required\":true}]', 'ds_intervention', 0, 15),
                ('dab_1090', '10-90', 'Go-fast', 'poursuite', 'fa-truck-fast', '#ef4444', '[{\"name\":\"lieu\",\"label\":\"Lieu / Axe\",\"type\":\"text\",\"required\":true},{\"name\":\"vehicules\",\"label\":\"Description vehicules\",\"type\":\"text\",\"required\":true}]', 'ds_intervention', 0, 16),
                ('dab_1091', '10-91', 'Braquage banque / Ammunition', 'vol', 'fa-building-columns', '#ef4444', '[{\"name\":\"lieu\",\"label\":\"Lieu (banque / ammunition)\",\"type\":\"text\",\"required\":true},{\"name\":\"suspects\",\"label\":\"Nombre de suspects\",\"type\":\"text\",\"required\":true},{\"name\":\"armes\",\"label\":\"Armes visibles\",\"type\":\"select\",\"options\":[\"Oui\",\"Non\",\"Inconnue\"],\"required\":true},{\"name\":\"otages\",\"label\":\"Otages\",\"type\":\"select\",\"options\":[\"Oui\",\"Non\",\"Inconnu\"],\"required\":true}]', 'ds_intervention', 0, 17),
                ('dab_1098', '10-98', 'Dispo / Fin intervention', 'autre', 'fa-circle-check', '#10b981', NULL, 'ds_disponible', 1, 18),
                ('dab_1099', '10-99', 'Agent en danger', 'agression', 'fa-triangle-exclamation', '#ef4444', '[{\"name\":\"lieu\",\"label\":\"Lieu\",\"type\":\"text\",\"required\":true},{\"name\":\"situation\",\"label\":\"Description situation\",\"type\":\"text\",\"required\":true}]', 'ds_intervention', 0, 19)
            ");
        }

        @touch($flagFile);
    } catch (PDOException $e) {
        mdt_error(500, 'E-101', 'Erreur serveur', 'Creation tables: ' . $e->getMessage());
    }
}
