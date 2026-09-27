USE mdt_main;

CREATE TABLE IF NOT EXISTS dispatch_action_buttons (
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
);

CREATE TABLE IF NOT EXISTS dispatch_services (
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
);

CREATE TABLE IF NOT EXISTS dispatch_dispatchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  roster_id INT NOT NULL,
  role ENUM('dispatcher','co_dispatcher') NOT NULL,
  assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_role (role)
);

-- Migration roster: ajout discord_id
ALTER TABLE roster ADD COLUMN IF NOT EXISTS discord_id VARCHAR(30) DEFAULT NULL;
-- Note: si "IF NOT EXISTS" pas supporte par ta version MariaDB, ignore l'erreur

-- Migration interventions: ajout colonnes provenance
ALTER TABLE dispatch_interventions ADD COLUMN IF NOT EXISTS created_by_user_id INT DEFAULT NULL;
ALTER TABLE dispatch_interventions ADD COLUMN IF NOT EXISTS action_button_id VARCHAR(50) DEFAULT NULL;
ALTER TABLE dispatch_interventions ADD COLUMN IF NOT EXISTS action_fields_data JSON DEFAULT NULL;

-- Seed codes 10 par defaut (seulement si table vide)
INSERT IGNORE INTO dispatch_action_buttons (id, code, label, intervention_type, icon, color, fields_config, statut_target, is_reset, ordre) VALUES
('dab_1008', '10-8', 'Prise de service', 'autre', 'fa-right-to-bracket', '#10b981', NULL, 'ds_disponible', 0, 1),
('dab_1010', '10-10', 'Fin de service', 'autre', 'fa-right-from-bracket', '#64748b', NULL, 'ds_hors_service', 0, 2),
('dab_1019', '10-19', 'En route vers...', 'autre', 'fa-route', '#3b82f6', '[{"name":"destination","label":"Destination","type":"text","required":true}]', 'ds_patrouille', 0, 3),
('dab_1031', '10-31', 'Coups de feu', 'tir', 'fa-crosshairs', '#ef4444', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"nb_tirs","label":"Nombre de tirs estimes","type":"text","required":false}]', 'ds_intervention', 0, 4),
('dab_1035', '10-35', 'Demande de renforts', 'autre', 'fa-people-group', '#f97316', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"raison","label":"Raison","type":"text","required":true}]', 'ds_intervention', 0, 5),
('dab_1037', '10-37', 'Cambriolage', 'vol', 'fa-house-crack', '#f59e0b', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"suspects","label":"Nombre de suspects","type":"text","required":false}]', 'ds_intervention', 0, 6),
('dab_1038', '10-38', 'Controle routier', 'controle', 'fa-hand', '#06b6d4', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"plaque","label":"Plaque vehicule","type":"text","required":false}]', 'ds_intervention', 0, 7),
('dab_1040', '10-40', 'Braquage minime', 'vol', 'fa-mask', '#f97316', '[{"name":"lieu","label":"Lieu (superette, vol, hack...)","type":"text","required":true},{"name":"suspects","label":"Nombre de suspects","type":"text","required":false},{"name":"armes","label":"Armes visibles","type":"select","options":["Oui","Non","Inconnue"],"required":true}]', 'ds_intervention', 0, 8),
('dab_1041', '10-41', 'Debut de patrouille', 'autre', 'fa-shield-halved', '#10b981', NULL, 'ds_patrouille', 0, 9),
('dab_1042', '10-42', 'Fin de patrouille', 'autre', 'fa-shield', '#64748b', NULL, 'ds_disponible', 0, 10),
('dab_1049', '10-49', 'Homicide', 'agression', 'fa-skull', '#ef4444', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"victimes","label":"Nombre de victimes","type":"text","required":true}]', 'ds_intervention', 0, 11),
('dab_1050', '10-50', 'Accident', 'accident', 'fa-car-burst', '#f59e0b', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"vehicules","label":"Nombre de vehicules","type":"text","required":false},{"name":"blesses","label":"Blesses","type":"select","options":["Oui","Non","Inconnu"],"required":true}]', 'ds_intervention', 0, 12),
('dab_1056', '10-56', 'Refus d obtemperer', 'poursuite', 'fa-gauge-high', '#ef4444', '[{"name":"marque","label":"Marque vehicule","type":"text","required":true},{"name":"couleur","label":"Couleur vehicule","type":"text","required":true},{"name":"occupants","label":"Nombre occupants","type":"text","required":true},{"name":"armes","label":"Armes","type":"select","options":["Oui","Non","Inconnue"],"required":true},{"name":"position","label":"Position","type":"select","options":["P1 (Poursuit)","P2 (Anticipe)"],"required":true}]', 'ds_intervention', 0, 13),
('dab_1060', '10-60', 'Vente de drogue', 'suspect', 'fa-cannabis', '#8b5cf6', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"suspects","label":"Description suspects","type":"text","required":false}]', 'ds_intervention', 0, 14),
('dab_1070', '10-70', 'Poursuite a pieds', 'poursuite', 'fa-person-running', '#f97316', '[{"name":"lieu","label":"Lieu / Direction","type":"text","required":true},{"name":"description","label":"Description suspect","type":"text","required":true},{"name":"armes","label":"Armes","type":"select","options":["Oui","Non","Inconnue"],"required":true}]', 'ds_intervention', 0, 15),
('dab_1090', '10-90', 'Go-fast', 'poursuite', 'fa-truck-fast', '#ef4444', '[{"name":"lieu","label":"Lieu / Axe","type":"text","required":true},{"name":"vehicules","label":"Description vehicules","type":"text","required":true}]', 'ds_intervention', 0, 16),
('dab_1091', '10-91', 'Braquage banque / Ammunition', 'vol', 'fa-building-columns', '#ef4444', '[{"name":"lieu","label":"Lieu (banque / ammunition)","type":"text","required":true},{"name":"suspects","label":"Nombre de suspects","type":"text","required":true},{"name":"armes","label":"Armes visibles","type":"select","options":["Oui","Non","Inconnue"],"required":true},{"name":"otages","label":"Otages","type":"select","options":["Oui","Non","Inconnu"],"required":true}]', 'ds_intervention', 0, 17),
('dab_1098', '10-98', 'Dispo / Fin intervention', 'autre', 'fa-circle-check', '#10b981', NULL, 'ds_disponible', 1, 18),
('dab_1099', '10-99', 'Agent en danger', 'agression', 'fa-triangle-exclamation', '#ef4444', '[{"name":"lieu","label":"Lieu","type":"text","required":true},{"name":"situation","label":"Description situation","type":"text","required":true}]', 'ds_intervention', 0, 19);
