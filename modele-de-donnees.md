# RP MDT — Modèle de données

Document de remise. Il décrit la base de production de l'application telle qu'elle existe
au **27 septembre 2026**, et non telle qu'elle devrait être. Chaque chiffre a été mesuré
sur la base réelle ; chaque comportement décrit a été relu dans le code de production.
Quand une information n'a pas pu être établie avec certitude, c'est écrit noir sur blanc.

- SGBD : **MariaDB 10.11.14** (et non MySQL — la distinction a des conséquences, voir §2.5)
- Base : `rp_mdt` — **118 tables**, **54 Mo** (données + index)
- Moteur : **InnoDB** pour les 118 tables
- `sql_mode` : `STRICT_TRANS_TABLES, ERROR_FOR_DIVISION_BY_ZERO, NO_AUTO_CREATE_USER, NO_ENGINE_SUBSTITUTION`
- Code : PHP 8 procédural, PDO, **34 fichiers `.php`** (pas de framework, pas d'ORM, pas de Composer)

---

## 1. Vue d'ensemble

### 1.1 Ce que fait l'application

RP MDT est un terminal de données mobile (MDT) de police pour un serveur de jeu de rôle
FiveM. C'est une application web classique : des pages `.html` statiques qui appellent,
en `fetch`, des points d'entrée PHP mono-fichier (`*_api.php`) qui parlent directement à
MariaDB en PDO. Il n'y a **ni framework, ni routeur, ni couche d'abstraction de données** :
chaque API construit ses requêtes SQL à la main.

### 1.2 Découpage en modules

| Module | Répertoire | API | Objet |
|---|---|---|---|
| Socle / accueil | racine | `admin_api.php`, `maintenance_api.php`, `roster_api.php`, `presence_api.php`, `log_api.php` | Administration, roster, présence, maintenance |
| Authentification | `login/` | `auth.php`, `auth_api.php`, `permissions_api.php` | Comptes, sessions, liaison Discord, permissions |
| Casier / MDT | racine | `casier_api.php`, `suspects_api.php`, `traitement.php` | Personnes, rapports d'arrestation et de détention |
| Code pénal | `penal/` | `penal_api.php` | Catalogue des infractions et des peines |
| Saisies | `saisies/` | `saisies_api.php` | Armes, drogues, objets saisis |
| Police scientifique | `sd/` | `sd_api.php` | Analyses de preuves, rapports |
| Plaintes | `plainte/` | `plainte_api.php` | Plaintes citoyennes et pièces jointes |
| Enquêtes (CID) | `cid/` | `cid_api.php` | Dossiers de groupe criminel, renseignements, organigrammes |
| Documentation | `documents/` | `documents_api.php` | Base documentaire hiérarchique |
| Formation (TD) | `td/` | `td_api.php` | Fiches de cadet, épreuves, barèmes d'évaluation |
| Épreuve d'entrée (NPU) | `npu/` | `npu_api.php` | Quiz + épreuve de géolocalisation |
| Dispatch | `dispatch/` | `dispatch_api.php`, `dispatch_perms.php` | Patrouilles, interventions, pointage de service |
| Messagerie | `messagerie/` | `messagerie_api.php` | Salons, messages privés, pièces jointes |
| Annonces | `annonces/` | `annonces_api.php` | Publications ciblées et accusés de lecture |
| Notifications | `notifications/` | `notif_api.php` | Cloche utilisateur |
| Carte tactique | `carte/` | `tacmap_api.php` | Tableaux de dessin collaboratifs |
| Recherche (battues) | `recherche/` | `rch_api.php` | Découpage en secteurs/zones, avancement d'une battue |
| Médiathèque | racine | `upload_api.php` | Bibliothèque d'images/vidéos partagée |

Un bot Discord Node.js (`bot/bot.js`) tourne à côté et sert de **passerelle de confiance**
vers l'API Discord. Il ne touche que quatre tables : `users`, `user_sessions`,
`discord_roles_cache`, et lit `module_permissions` indirectement.

### 1.3 Comment l'authentification se raccorde aux données

La chaîne est courte et il faut l'avoir en tête avant de lire le reste :

```
Authorization: Bearer <token 64 hex>
        │
        ▼
user_sessions.token  ──(user_id)──►  users.id
        │                               │
        │                               ├─► users.discord_id   ← la vraie clé métier
        │                               └─► users.discord_roles (JSON : liste de role_id)
        ▼
  expires_at > NOW() ?
        │
        ▼
array_intersect(discord_roles, module_permissions.role_id WHERE module_key = '<module>')
```

Points structurants, tous vérifiés dans `login/auth_api.php` et `mdt_security.php` :

1. **L'OAuth Discord ne crée aucun compte.** Le compte est créé par un couple
   identifiant/mot de passe (`users.username` + `users.password_hash`, bcrypt). L'OAuth sert
   uniquement à **lier** un compte existant à un identifiant Discord (action `link_discord`).
2. **Les rôles ne viennent jamais du navigateur.** À la liaison, le serveur PHP réinterroge
   le bot local pour obtenir la liste réelle des rôles, et journalise une tentative de
   falsification (`role_spoof_attempt`) si le client en annonçait d'autres.
3. **Le jeton de session n'est pas un cookie.** Il est stocké en `localStorage` côté
   navigateur et envoyé en en-tête `Authorization`. Il n'y a donc ni `HttpOnly` ni `SameSite`.
4. **La clé de jointure applicative est `users.discord_id`, pas `users.id`.** Presque toutes
   les tables métier référencent un `discord_id` en texte (`created_by`, `actor_did`,
   `owner_did`, `author_did`…). Seules `user_sessions` et quelques tables dispatch
   référencent `users.id`.
5. **Conséquence à connaître** : sur 185 comptes, **117 n'ont pas de `discord_id`**. Tous les
   modules indexés par `discord_id` (notifications, messagerie, annonces, NPU, présence) leur
   sont structurellement invisibles — l'API renvoie une liste vide, pas une erreur.

### 1.4 Le modèle de permissions

Quatre niveaux coexistent, du plus large au plus fin :

| Niveau | Table | Portée |
|---|---|---|
| Propriétaire | *aucune* — identifiant en dur dans `mdt_security.php` | Tout |
| Super-admin | `dev_users` (5 lignes) | Tout ; non falsifiable (ne dépend pas de `discord_roles`) |
| Accès module | `module_permissions` (60 lignes) | Un module entier |
| Permission fine | `cid_role_perms`, `doc_role_perms`, `role_config` | Une action dans un module |
| Visibilité contenu | `cid_category_visibility`, `doc_visibility` | Une catégorie / une division |

**Règle contre-intuitive à retenir** : dans `module_permissions`, **zéro ligne pour un module
signifie « module public »**, pas « module fermé ». C'est un choix assumé (fail-open) qui
produit aujourd'hui au moins un effet de bord réel, documenté en §5.

---

## 2. Conventions

### 2.1 Nommage des tables

Les tables sont préfixées par module. Le préfixe est le seul mécanisme de regroupement :
il n'y a ni schéma séparé, ni vue, ni convention plus forte.

| Préfixe | Tables | Module |
|---|---|---|
| `cid_` | 14 | Enquêtes |
| `dispatch_` | 24 | Dispatch |
| `doc_` | 10 | Documentation |
| `td_` (dont `td_fl_`) | 15 | Formation et barèmes |
| `msg_` | 8 | Messagerie |
| `rch_` | 7 | Battues |
| `npu_` | 4 | Épreuve d'entrée |
| `sup_` | 4 | Supervision / RH |
| `tac_` | 3 | Carte tactique |
| `plainte_` | 3 | Plaintes (+ `plaintes`) |
| *aucun* | 26 | Socle historique : `users`, `roster`, `personnes`, `rapports`, `saisies`, `annonces`, `notifications`, `penal_code`, `suspects`, `maintenance`, `upload_files`, `audit_log`, `security_events`… |

Trois conventions de suffixe se répètent et il faut les reconnaître :

- `*_meta` / `*_config` — table clé/valeur du module (`cid_meta`, `doc_meta`, `dispatch_meta`,
  `msg_meta`, `rch_meta`, `tac_meta`, `npu_config`). **Le nom des deux colonnes change d'une
  table à l'autre** : `(k, v)` pour `cid_meta` et `doc_meta`, `(mk, mv)` pour les cinq autres.
  C'est un piège d'écriture pure et simple.
- `*_logs` / `*_log` — journal d'audit local au module (`cid_logs`, `doc_logs`, `dispatch_logs`,
  `rch_log`), en plus du journal global `audit_log`.
- `*_role_perms` / `*_visibility` — tables de droits fins propres au module.

### 2.2 Le renommage du préfixe

**Aucune table ni colonne ne porte de préfixe de marque.** Le renommage vers `mdt`
a donc pu être appliqué au code sans désaligner la base : le dump SQL fourni et le
code livré sont cohérents entre eux.

Ce que le renommage a touché, déjà appliqué dans le dépôt :

| Objet | Après renommage |
|---|---|
| Nom de la base | `rp_mdt` (à créer à l'import ; le dump ne contient pas de `CREATE DATABASE`) |
| Utilisateur MySQL | à définir dans `db_config.php`, livré avec des marqueurs `REMPLACER_*` |
| Fichiers PHP du socle | `mdt_security.php`, `mdt_roles.php`, `mdt_embed.php` |
| Préfixe des fonctions | **46 fonctions** `mdt_*` : `mdt_error()`, `mdt_require_auth()`, `mdt_module_access()`, `mdt_audit()`, `mdt_seclog()`, `mdt_touch_session()`… |
| Répertoire du module principal | `web/mdt/` — la règle de routage du serveur web est à adapter |

Deux marqueurs internes ont suivi le même renommage : le fichier-drapeau de bootstrap
est `/tmp/mdt_tables_ready_v3.flag`, et la clé de `localStorage` du jeton de session est
`mdt_auth_token`. Cette seconde valeur change de nom : **les sessions ouvertes avant la
reprise seront invalidées une fois**, ce qui obligera chacun à se reconnecter.

Les codes d'erreur `E-xxx` écrits dans `error_log` portent le nouveau préfixe en
majuscules.

### 2.3 Clés primaires

Les 118 tables ont une clé primaire. Deux familles cohabitent, sans règle :

- **`INT AUTO_INCREMENT`** — 35 colonnes. Tables techniques et journaux : `users`, `roster`,
  `audit_log`, `security_events`, `dispatch_services`, `dispatch_logs`, `notifications`,
  `msg_messages`, `doc_*`, `td_fl_*`…
- **`VARCHAR(50)` généré côté PHP** — la majorité des tables métier : `plaintes`, `saisies`,
  `rapports`, `personnes`, `cid_*`, `sd_*`, `td_fiches`, `dispatch_interventions`,
  `dispatch_patrouilles`…

La génération de ces identifiants n'est pas centralisée. On trouve dans le code, selon les
modules : `uniqid()` seul, `uniqid('prefixe_')`, `uniqid('tac', true)`,
`bin2hex(random_bytes(3|6|8|9))`, et des concaténations des deux. **Il n'existe aucune
fonction partagée de génération d'identifiant** — c'est réécrit dans chaque API.

Enfin, plusieurs tables ont une **clé primaire composite servant de contrainte d'unicité
métier** : `module_permissions (module_key, role_id)`, `rapport_personnes (rapport_id,
personne_id)`, `plainte_tag_assignments (plainte_id, tag_id)`, `annonce_lectures
(annonce_id, discord_id)`, `msg_reactions (message_id, discord_id, emoji)`,
`msg_typing (conv_id, discord_id)`, `cid_role_perms (role_id, perm)`.

### 2.4 Dates et fuseau horaire

C'est un point à comprendre avant de toucher à quoi que ce soit.

**Ce que fait le code, vérifié dans `db_config.php` :**

```php
date_default_timezone_set('Europe/Paris');            // horloge PHP
$conn->exec("SET time_zone = 'Europe/Paris'");        // horloge MySQL, par connexion
// repli silencieux si la table des fuseaux n'est pas chargée :
// $conn->exec("SET time_zone = '+02:00'");
```

Le serveur MariaDB, lui, est en `time_zone = SYSTEM` au niveau global. **Le fuseau de Paris
n'est donc appliqué que par la connexion applicative.** Toute requête lancée en dehors de
l'application (client `mysql`, script d'administration, outil de sauvegarde) lit et écrit
dans le fuseau système. Le repli `+02:00` est en dur : s'il se déclenche en hiver, les
horodatages sont décalés d'une heure sans aucune trace.

**Les deux horloges sont mélangées, parfois sur la même colonne.** Aucune colonne n'est
stockée en UTC ; il n'y a pas une seule occurrence de `gmdate`, `UTC_TIMESTAMP` ou
`DateTimeZone` dans tout le code.

| Mécanisme | Où | Exemples |
|---|---|---|
| `DEFAULT CURRENT_TIMESTAMP` | la plupart des `created_at` | `plaintes`, `saisies`, `cid_*`, `rapports` |
| `NOW()` dans la requête | 119 occurrences, 18 fichiers | `dispatch_api.php` (46), `messagerie_api.php` (16), `mdt_security.php` (10), `auth_api.php` (10) |
| `date('Y-m-d H:i:s')` en PHP | ~20 occurrences | `plainte_api.php:310/313/348`, `sd_api.php:83/129`, `dispatch_api.php` (une douzaine), `auth_api.php:109/182` |

Deux cas concrets où les deux horloges écrivent **la même colonne** :

- `user_sessions.expires_at` — posé en PHP à la création (`date(..., strtotime('+30 days'))`)
  et prolongé en SQL (`DATE_ADD(NOW(), …)`), tandis que la vérification se fait en SQL
  (`expires_at > NOW()`).
- `plaintes.created_at` — la colonne a `DEFAULT current_timestamp()` mais l'`INSERT` fournit
  explicitement une valeur calculée en PHP.

Tant que PHP et MariaDB sont d'accord, rien ne se voit. Le jour où ils divergent (repli
`+02:00`, changement d'heure, migration de serveur), ce sont les expirations de session,
l'ordre chronologique des plaintes et la corrélation `audit_log` ↔ `security_events` qui
partent en vrille. **Une source de temps unique est le premier chantier d'assainissement à
prévoir.**

### 2.5 Encodage et collations — deux incohérences réelles

La connexion est ouverte en `charset=utf8mb4`. Toutes les tables sont en `utf8mb4`. Mais :

**Incohérence n°1 — deux collations de table cohabitent.**

- **98 tables en `utf8mb4_unicode_ci`** (la majorité, et le socle : `users`, `roster`, `cid_*`,
  `doc_*`, `dispatch_*` pour l'essentiel, `msg_*` sauf un)
- **20 tables en `utf8mb4_general_ci`** : `audit_log`, `dispatch_avertos`, `msg_meta`,
  `doc_hierarchie`, `rch_cells`, `rch_etats`, `rch_log`, `rch_meta`, `rch_ops`, `rch_secteurs`,
  `rch_zones`, `sup_agent`, `sup_effectifs`, `sup_sanctions`, `sup_weeks`, `tac_boards`,
  `tac_meta`, `tac_shapes`, `td_epreuve_categories`, `td_notation_criteria`

Ce n'est pas cosmétique : **toute jointure texte entre les deux groupes échoue à
l'exécution.** Vérifié directement sur la base de production (en lecture seule) :

```
SELECT ... FROM sup_effectifs s JOIN roster r ON r.matricule = s.matricule;
ERROR 1267 (HY000): Illegal mix of collations
                    (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT)
```

Le même échec se produit pour `dispatch_avertos` × `roster` sur `matricule`, et pour
`audit_log` × `users` sur `discord_id`. Le code actuel **contourne le problème sans le
nommer** : il ne fait jamais ces jointures et recolle les données en PHP. Un repreneur qui
écrira la jointure « évidente » se heurtera à une erreur SQL brute, pas à un résultat faux.

**Incohérence n°2 — 19 colonnes en `utf8mb4_bin`.** Ce sont les colonnes déclarées `JSON`
(voir §2.6). C'est le comportement normal de MariaDB, pas une anomalie, mais il faut le
savoir : une comparaison texte sur l'une de ces colonnes est **sensible à la casse**.

### 2.6 JSON stocké en texte

**MariaDB n'a pas de vrai type JSON.** `JSON` y est un alias de `LONGTEXT` assorti d'une
contrainte `CHECK (json_valid(col))`. Conséquence directe :

```sql
SELECT COUNT(*) FROM information_schema.columns
 WHERE table_schema='rp_mdt' AND data_type='json';   -- → 0
```

Il n'y a donc **aucune colonne JSON native** dans la base. Ce qui existe :

- **18 colonnes `LONGTEXT` + `CHECK json_valid`** — le moteur refuse un JSON malformé :
  `users.discord_roles`, `penal_code.tiers`, `penal_code.circonstances`, `rapports.agents`,
  `rapports.charges`, `rapports.metadata`, `plaintes.individus`, `plaintes.faits`,
  `sd_analyses.data`, `td_epreuves.data`, `td_fiches.data`, `td_sessions.data`,
  `td_notations.data`, `td_first_lincoln.scores`, `td_first_lincoln.questionnaire`,
  `dispatch_logs.details`, `dispatch_interventions.action_fields_data`,
  `dispatch_action_buttons.fields_config`.
- **Une trentaine de colonnes `TEXT`/`LONGTEXT` portant du JSON sans aucune validation** :
  `annonces.medias`, `annonces.roles`, `msg_conv.roles`, `msg_messages.mentions`,
  `msg_messages.embed`, `plaintes.plaignants`, tous les `cid_*.images`,
  `cid_telephones.preuves`, `cid_notes.faits_reproches`, `cid_interrogatoires.identites`,
  `rch_secteurs.pts`, `rch_zones.pts`, `tac_shapes.pts`, `tac_boards.roles`,
  `tac_boards.roles_edit`, `tac_boards.legend`, `sup_effectifs.qualifs`,
  `sup_sanctions.photos`, `npu_responses.quiz_answers`, `npu_responses.geo_answers`,
  `td_docs.images`, `td_docs.fichiers`, `td_docs.roles`, `td_epreuve_categories.data`,
  `td_first_lincoln.bareme_snapshot`, `drogue_types.aliases`, ainsi que tous les
  `before_json` / `after_json` des quatre tables de journal.

Le second groupe est le plus risqué : **rien n'empêche d'y écrire une chaîne vide, un `NULL`
ou du JSON tronqué**, et les lecteurs PHP font systématiquement `json_decode(...) ?: array()`,
ce qui transforme silencieusement une donnée corrompue en tableau vide.

Cas particulier à signaler : `td_epreuve_categories.data` et `td_first_lincoln.bareme_snapshot`
sont les deux seuls blobs JSON du préfixe `td_` **sans** `CHECK json_valid`, alors que tous
leurs voisins en ont un.

### 2.7 Soft-delete et colonnes d'état

Il n'y a **pas de convention unique**. Sept mécanismes différents coexistent :

| Mécanisme | Colonnes | Tables |
|---|---|---|
| Horodatage de suppression | `deleted_at` | `doc_files`, `msg_attachments`, `tac_shapes`, `td_notation_criteria` |
| Booléen de suppression | `deleted` | `msg_messages`, `tac_shapes` (**les deux à la fois**) |
| Booléen d'archive | `archive`, `archived` | `doc_categories`, `doc_divisions`, `msg_conv`, `rch_secteurs`, `rch_zones`, `tac_boards` |
| Horodatage d'archive | `archived_at` | `cid_dossiers` |
| Booléen d'activité | `active`, `actif` | `td_fl_types`, `td_fl_sections`, `td_fl_criteres`, `td_fl_questions`, `td_fl_quest_categories`, `td_notation_criteria` |
| Horodatage de clôture | `closed_at` | `dispatch_interventions`, `rch_ops` |
| Annulation | `annule` | `dispatch_services` |

`tac_shapes` porte à la fois `deleted` (booléen) et `deleted_at` (horodatage) ;
`td_notation_criteria` porte à la fois `actif` et `deleted_at`. Dans les deux cas le code
écrit les deux, mais **les lectures ne filtrent pas toujours sur la même** — à vérifier avant
toute modification.

Autres colonnes d'état qui ne sont pas des suppressions mais s'y confondent facilement :
`notifications.lu`, `dispatch_avertos.vu`, `plainte_tags.is_closed` (qui sert de **statut de
clôture d'une plainte**, voir §5.6), `maintenance.is_disabled`, `td_fiches.locked`,
`cid_hierarchie.decede`.

Enfin, trois tables portent une colonne `rev` utilisée comme **verrou optimiste**
(incrément en transaction `SELECT … FOR UPDATE`) : `td_fiches`, `td_first_lincoln`,
`tac_boards` / `tac_shapes`, `rch_ops` / `rch_etats`. Ne pas la traiter comme un compteur.

---

## 3. Les 118 tables par domaine

Les volumes ci-dessous sont des `SELECT COUNT(*)` **exacts**, relevés le 27 septembre 2026.
Ce ne sont pas les estimations d'`information_schema`. La base est vivante : les tables de
journal et de dispatch bougent de minute en minute.

Légende de la colonne « Coll. » : **U** = `utf8mb4_unicode_ci`, **G** = `utf8mb4_general_ci`
(voir §2.5 — les deux ne se joignent pas).

### 3.1 Socle : comptes, sessions, rôles, roster (10 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `users` | 185 | U | Compte applicatif (identifiant + bcrypt) et miroir du profil Discord | `id` ← `user_sessions.user_id` ; `discord_id` ← **la quasi-totalité des tables métier** ; UNIQUE sur `username` et `discord_id` |
| `user_sessions` | 283 | U | Jetons Bearer actifs, 30 jours glissants | `user_id` → `users.id` (jointure logique) |
| `user_presence` | 90 | U | Dernier battement de cœur d'un agent (onglet ouvert / actif) | PK `discord_id` → `users.discord_id` |
| `dev_users` | 5 | U | Liste des super-administrateurs, par `discord_id` | `discord_id` → `users.discord_id` ; contrôle non falsifiable |
| `module_permissions` | 60 | U | Rôles Discord autorisés par module | PK `(module_key, role_id)` ; `role_id` → `discord_roles_cache.role_id` |
| `discord_roles_cache` | 117 | U | Miroir local des rôles du serveur Discord (nom, couleur, position) | Cible de `module_permissions`, `role_config`, `cid_role_perms`, `doc_role_perms`, `*_visibility` |
| `role_config` | **0** | U | Capacités fines « clé de capacité → rôle » censées remplacer des constantes en dur | `role_id` → `discord_roles_cache.role_id` |
| `roster` | 58 | U | Annuaire des agents : matricule ↔ nom ↔ `discord_id` | **Pivot central du dispatch.** Cible de 8 tables `dispatch_*` par `roster.id`, et de `tac_*`/`rch_*`/`sup_*` par `matricule` ou `discord_id` |
| `roster_blocked_matricules` | **0** | U | Liste noire de matricules, réservée aux super-admins | PK `matricule` → `roster.matricule` |
| `maintenance` | 9 | U | Interrupteur de maintenance par module | `module_key` contraint en PHP seulement |

**Vide** : `role_config`, `roster_blocked_matricules`. Les deux ont du code actif — ce sont
des fonctionnalités livrées et jamais adoptées, pas des reliquats. `role_config` vide
signifie en pratique que **le repli sur les identifiants de rôle codés en dur dans
`mdt_roles.php` est le comportement de production**.

`maintenance` contient 9 modules (`admin`, `dispatch`, `mdt`, `plainte`, `saisies`, `sd`,
`suivi`, `td`, `upload`) alors que `module_permissions` en connaît 10 : **`documents` manque
dans `maintenance`**, il est donc impossible de le mettre en maintenance.

### 3.2 Journaux globaux (2 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `audit_log` | **81 409** | **G** | Journal d'audit global. Capture **automatiquement tout POST et toute réponse HTTP ≥ 400** de tout le site, via un `register_shutdown_function` | `actor_did` → `users.discord_id` — **jointure impossible en SQL** (collations incompatibles) |
| `security_events` | 1 805 | U | Journal anti-intrusion : accès refusés, échecs de connexion, tentatives de falsification de rôle | `ip`, `actor` en texte libre |

`audit_log` pèse **34,3 Mo, soit 63 % de la base entière**. Le tableau détaillé de ses index
et de sa politique de rétention est en §5.2.

`security_events` n'a **aucun lecteur applicatif** : la table n'apparaît que dans
`mdt_security.php`, pour l'anti-bruteforce et le throttle d'alerte. Il n'existe aucune
interface pour consulter ces 1 805 événements de sécurité, dont des `role_spoof_attempt`
classés `critical`.

### 3.3 Casier judiciaire et code pénal (5 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `personnes` | 80 | U | Identités civiles du casier, dédupliquées par `nom_normalise` (UNIQUE) | Cible de `rapport_personnes.personne_id` et `saisies.personne_id` |
| `rapports` | 96 | U | Rapports d'arrestation (`DA`) et d'activité (`RA`) | Cible de `rapport_personnes.rapport_id` et `saisies.rapport_id` |
| `rapport_personnes` | 96 | U | Table de liaison N-N rapport ↔ personne | PK `(rapport_id, personne_id)` |
| `suspects` | 475 | U | Liste partagée d'identités de joueurs, alimentée par un script horaire externe | `discord_id` → `users.discord_id` (non exploité) ; UNIQUE sur `name` |
| `penal_code` | 168 | U | Catalogue des infractions : code, catégorie, amende, peine, paliers | Référencé par `rapports.charges` et par le module CID **via le texte du code, pas par une clé** |

`personnes.nom_normalise` est produit par une fonction PHP (translittération ASCII,
minuscules, mots triés) **dont une version JavaScript existe côté client**. Deux
implémentations d'une même normalisation, avec un UNIQUE en base derrière : toute divergence
entre les deux crée des doublons ou des collisions.

`penal_code` n'a **aucune colonne de date** (ni `created_at`, ni `updated_at`) : il est
impossible de savoir quand un tarif a changé, alors que les rapports figés le référencent.

### 3.4 Saisies (2 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `saisies` | 2 289 | U | Registre unique des saisies : armes, drogues, objets, avec statut « volée » pour les armes | `sd_analyse_id` → `sd_analyses.id` (seul `LEFT JOIN` réel) ; `rapport_id` → `rapports.id` et `personne_id` → `personnes.id`, **écrits uniquement par `casier_api.php`** |
| `drogue_types` | **0** | U | Catalogue éditable des variétés de drogue (nom, icône, couleur, alias) | **Aucune relation SQL** : `saisies.drogue_variete` est du texte libre, jamais joint |

`saisies` est la table métier la plus volumineuse hors journaux. Elle n'a **pas d'index sur
`created_at`** alors que son écran d'accueil trie dessus, ni sur `arme_serial` alors qu'une
action `check_serial` fait un `WHERE arme_serial = ?`.

### 3.5 Police scientifique (2 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `sd_analyses` | 566 | U | Analyses de preuves (douille, fragment, sang, arme…) rattachées à un nom d'affaire | Cible de `saisies.sd_analyse_id` |
| `sd_reports` | 64 | U | Rapports rédigés du laboratoire | **Aucune relation SQL** : le lien vers une analyse est inséré comme texte côté client |

Ces deux tables n'ont **aucun index en dehors de la clé primaire**, alors que les deux écrans
font un `SELECT *` complet avec `ORDER BY created_at DESC` sans `LIMIT`. Pour `sd_analyses`,
cela inclut la colonne JSON `data` de chaque ligne.

`author` est un **nom dénormalisé** issu de la session, pas un identifiant : on ne peut pas
remonter de façon fiable à l'agent si son pseudo change.

### 3.6 Plaintes (4 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `plaintes` | 39 | U | Plainte déposée par un civil, avec les individus mis en cause et les faits reprochés | `handler_id` → `users.discord_id` ; `discord_thread_id` → un fil Discord externe |
| `plainte_attachments` | 53 | U | Pièces jointes d'une plainte | `plainte_id` → `plaintes.id`, **nullable** : les pièces sont téléversées avant la plainte et rattachées ensuite |
| `plainte_tags` | 14 | U | Étiquettes configurables, dont celles qui valent clôture | `is_closed` sert de **statut de clôture** |
| `plainte_tag_assignments` | 67 | U | Liaison N-N plainte ↔ étiquette | PK `(plainte_id, tag_id)` |

**`plaintes` n'a pas de colonne de statut.** L'état d'une plainte (ouverte / close) se déduit
de la présence d'une étiquette portant `is_closed = 1`. C'est le genre de détail qui coûte
une demi-journée à un repreneur qui cherche un `statut` inexistant.

### 3.7 Enquêtes — CID (14 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `cid_dossiers` | 31 | U | Fiche racine d'un groupe criminel ou d'une affaire, archivable | `type_groupe` → `cid_types_groupe.**cle**` (pas `id`) ; `statut_id` → `cid_dossier_statuts.id` |
| `cid_types_groupe` | 9 | U | Catalogue éditable des catégories de dossier | La clé métier est `cle`, pas la clé primaire `id` |
| `cid_dossier_statuts` | 3 | U | Catalogue éditable des statuts d'avancement | Cible de `cid_dossiers.statut_id` |
| `cid_category_visibility` | 8 | U | Rôles autorisés sur une catégorie ; **sa seule présence rend la catégorie confidentielle** | `type_groupe` → `cid_types_groupe.cle` ; `role_id` → `discord_roles_cache` |
| `cid_role_perms` | 17 | U | Permissions fines du module CID par rôle Discord | 12 valeurs de `perm` définies en PHP |
| `cid_hierarchie` | 93 | U | Nœud d'organigramme d'un dossier | `dossier_id` → `cid_dossiers.id` ; `parent_id` → auto-référence, **sans garde-fou anti-cycle** |
| `cid_informations` | 25 | U | Renseignement libre, niveau d'importance, épinglable | `dossier_id` nullable |
| `cid_notes` | 39 | U | Note d'enquête **obligatoirement** rattachée à un dossier, avec faits reprochés | `dossier_id` NOT NULL ; cascade de suppression applicative |
| `cid_interrogatoires` | 11 | U | Compte rendu d'interrogatoire | `dossier_id` nullable |
| `cid_telephones` | 3 | U | Exploitation d'un téléphone saisi, avec preuves structurées | `dossier_id` nullable |
| `cid_replies` | 1 | U | Fil de commentaires générique, attachable à n'importe quelle entité CID | `(entity_type, entity_id)` — **polymorphe, aucune contrainte possible** |
| `cid_pins` | **0** | U | Information épinglée globale du module, hors dossier | Aucune |
| `cid_logs` | 448 | U | Journal avant/après de toutes les mutations CID | `entity_id` → table variable selon `entity_type` |
| `cid_meta` | 3 | U | Clé/valeur : drapeaux de migration idempotents (`perms_seeded`, `types_seeded`, `perms_split_v2`) | Colonnes `(k, v)` |

**Aucune clé étrangère sur l'ensemble du préfixe `cid_`.** Tout tient par le code PHP.
Supprimer un `cid_dossier_statuts` ne remet pas à `NULL` les `cid_dossiers.statut_id`
correspondants : des références orphelines sont possibles.

### 3.8 Documentation (10 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `doc_divisions` | 1 | U | Racine de l'arborescence documentaire | Cible de `doc_categories.division_id` — **vraie clé étrangère** |
| `doc_categories` | 4 | U | Sous-dossier d'une division | **FK** `division_id` → `doc_divisions.id` (`ON UPDATE CASCADE`) |
| `doc_documents` | 12 | U | Document rédigé en HTML riche, publiable ou en brouillon | **FK** `categorie_id` → `doc_categories.id` |
| `doc_files` | 20 | U | Pièce jointe d'un document, avec soft-delete et ramasse-miettes | **FK** `doc_id` → `doc_documents.id` (`ON DELETE CASCADE`), nullable |
| `doc_visibility` | 6 | U | Rôles autorisés sur une division ou une catégorie restreinte | `(scope, scope_id)` polymorphe — aucune FK possible |
| `doc_role_perms` | **0** | U | Permissions fines du module par rôle Discord | Vide ⇒ seuls propriétaire, super-admins et commandement passent |
| `doc_hierarchie` | **0** | **G** | Organigramme d'une division | `division_id` → `doc_divisions.id`, **code seulement**, contrairement à `doc_categories` |
| `doc_path_alias` | **0** | U | Redirection d'anciennes URL après renommage ou déplacement | `target_id` polymorphe selon `kind` |
| `doc_logs` | 1 | U | Journal avant/après du module | `categorie_id` → `doc_categories.id` |
| `doc_meta` | 2 | U | Clé/valeur : `schema_version`, drapeau de migration | **Lue uniquement, jamais écrite** par le code actuel. Colonnes `(k, v)` |

C'est le **seul module doté de vraies clés étrangères** (3 sur 8 dans toute la base). Noter
l'asymétrie : `doc_categories` est contrainte par le moteur, `doc_hierarchie` — qui pointe
vers la même table — ne l'est pas, et est en plus dans l'autre collation.

Colonnes mortes repérées : `doc_documents.legacy_id` et `doc_documents.legacy_roles` (zéro
occurrence dans le code), `doc_divisions.kind` (lue, jamais écrite ni interprétée).

### 3.9 Formation et barèmes — TD (15 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `td_fiches` | 9 | U | Fiche de suivi d'un cadet : colonnes plates + gros blob JSON `data` | `cadet`/`referent` = chaîne « matricule \| nom », **pas un identifiant** ; verrou optimiste `rev` |
| `td_notation_criteria` | 21 | **G** | Catalogue des critères de validation d'un cadet | Référencée **depuis l'intérieur du JSON** `td_fiches.data.notations` |
| `td_notations` | 1 | U | Vestige du modèle de notation de la V1 | **Table morte** — voir ci-dessous |
| `td_epreuves` | 8 | U | Catalogue des épreuves de recrutement, stockées entièrement en JSON | `data.cat_id` → `td_epreuve_categories.id`, **relation à l'intérieur d'un JSON** |
| `td_epreuve_categories` | 2 | **G** | Regroupement visuel des épreuves | Cible de `td_epreuves.data.cat_id` |
| `td_sessions` | 5 | U | Session de recrutement : promo, recrues, résultats par épreuve | Tout en JSON, `data.recrues[].resultats` indexé par `td_epreuves.id` |
| `td_first_lincoln` | 9 | U | Passation d'une évaluation de conduite + questionnaire | `fl_type_id` → `td_fl_types.id` (validé) ; `fiche_id` → `td_fiches.id` **jamais validé ni joint** |
| `td_fl_types` | 8 | U | Modèle d'évaluation (un barème complet) | Cible de **4 vraies clés étrangères** en cascade |
| `td_fl_sections` | 40 | U | Sections de la grille pratique | **FK** `type_id` → `td_fl_types.id` (`ON DELETE CASCADE`) |
| `td_fl_criteres` | 266 | U | Critères notés d'une section, avec faute éliminatoire | **FK** `section_id` → `td_fl_sections.id` |
| `td_fl_quest_categories` | 35 | U | Catégories du questionnaire théorique | **FK** `type_id` → `td_fl_types.id` |
| `td_fl_questions` | 407 | U | Questions du questionnaire | **FK** `category_id` → `td_fl_quest_categories.id` |
| `td_fl_config` | 48 | U | Paramètres numériques d'un barème (seuils, plafonds), en clé/valeur | **FK** `type_id` → `td_fl_types.id` |
| `td_docs` | 12 | U | Ancienne base documentaire du module, remplacée par `documents/` | **Table morte** — voir ci-dessous |
| `td_doc_categories` | 4 | U | Catégories de l'ancienne base documentaire | **Table morte** |

Les 5 clés étrangères de `td_fl_*` sont en `ON DELETE CASCADE`, mais **elles ne se
déclenchent jamais** : l'interface fait du soft-delete (`UPDATE … SET active = 0`).

**Tables mortes confirmées par grep sur l'ensemble du code (`.php`, `.js`, `.html`)** :
`td_docs` et `td_doc_categories` n'ont **aucune occurrence**. Elles contiennent encore
12 et 4 lignes, et `td_docs` référence 19 fichiers dans un répertoire lui aussi orphelin
(voir §6). `td_notations` est un cas limite : la table n'apparaît qu'une fois, dans un
`SELECT COUNT(*)` d'affichage ; son contenu n'est ni lu ni écrit.

### 3.10 Épreuve d'entrée — NPU (4 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `npu_responses` | 9 | U | Réponses au quiz et à l'épreuve de géolocalisation d'un candidat | PK `discord_id` → `users.discord_id` |
| `npu_chrono` | 9 | U | Chronomètre et élimination d'un candidat | PK `discord_id` ; jointure LEFT avec `npu_responses` |
| `npu_places` | 8 | U | Points géographiques de l'épreuve, avec photo | `photo` = chemin `/npu/uploads/…` en clair |
| `npu_config` | 3 | U | Clé/valeur : `map`, `open`, `__ddl`. Colonnes `(mk, mv)` | — |

### 3.11 Dispatch (24 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `dispatch_services` | **6 551** | U | **Une ligne = une session de service** d'un agent (prise → retrait), avec durée créditée | `roster_id` → `roster.id` ; `user_id` → `users.id` |
| `dispatch_logs` | **5 465** | U | Journal d'audit de toutes les actions du module | `actor_discord_id` → `users.discord_id` ; `target_id` polymorphe |
| `dispatch_interventions` | **2 752** | U | Carte d'intervention (appel) du tableau dispatch | Cible de `_patrouilles`, `_vehicules`, `_attachments`, `dispatch_poursuite_log` |
| `dispatch_salaire_paye` | 569 | U | Marqueur « semaine payée » pour un agent | UNIQUE `(roster_id, week_start)` ; **ne contient aucun montant** |
| `dispatch_intervention_vehicules` | 197 | U | Véhicule suspect rattaché à une intervention | `intervention_id` → `dispatch_interventions.id` |
| `dispatch_vehicules` | 126 | U | Catalogue des véhicules de service | Aucune |
| `dispatch_vehicule_assignations` | 121 | U | Quelle patrouille suit quel véhicule, et à quelle position | `vehicule_id` → `dispatch_intervention_vehicules.id` |
| `dispatch_poursuite_log` | 88 | U | Journal départ/fin d'une poursuite | `intervention_id`, `vehicule_id`, `patrouille_id` |
| `dispatch_avertos` | 50 | **G** | Avertissement hebdomadaire après retrait de service forcé ; 3 par semaine annulent les heures | `roster_id` → `roster.id` ; dénormalise `matricule` et `nom_prenom` |
| `dispatch_action_buttons` | 27 | U | Boutons « code 10-XX » configurables, avec champs de formulaire dynamiques | `statut_target` → `dispatch_statuts.id` ; `fields_config` en JSON |
| `dispatch_intervention_attachments` | 11 | U | Photo jointe à une intervention | `intervention_id` → … ; `uploaded_by` → `users.id` |
| `dispatch_patrol_types` | 10 | U | Types de patrouille et format d'indicatif | Cible de `dispatch_patrouilles.patrol_type_id` |
| `dispatch_operations` | 6 | U | Opérations et placements individuels, avec fréquence radio | Cible de `dispatch_agent_operations.operation_id` |
| `dispatch_meta` | 5 | U | Clé/valeur : `ddl_version`, `last_afk_sweep`, réglages anti-AFK. Colonnes `(mk, mv)` | — |
| `dispatch_statuts` | 2 | U | Statuts de patrouille (disponible, en patrouille, en intervention, hors service) | Cible de `dispatch_patrouilles.statut_id` |
| `dispatch_patrouille_agents` | 1 | U | Liaison N-N patrouille ↔ agent | PK `(patrouille_id, roster_id)` |
| `dispatch_patrouilles` | **0** | U | Unité opérationnelle en service (indicatif, véhicule, canal radio) | **Table transitoire** : vide hors service actif |
| `dispatch_intervention_patrouilles` | **0** | U | Liaison N-N intervention ↔ patrouille | **Transitoire** |
| `dispatch_queue` | **0** | U | File d'attente dispatch : une ligne = un agent en attente | **Transitoire** |
| `dispatch_pauses` | **0** | U | Agents en pause : une ligne = un agent en pause | **Transitoire** |
| `dispatch_dispatchers` | **0** | U | Postes tenus : dispatcher, co-dispatcher, supervision | **Transitoire** ; `role` est un vrai ENUM |
| `dispatch_agent_operations` | **0** | U | Affectation d'un agent à une opération, une seule à la fois | **Transitoire** ; UNIQUE sur `roster_id` |
| `dispatch_wanted_persons` | **0** | U | Avis de recherche « personne » | `created_by` → `users.id`, **jamais renseigné** |
| `dispatch_wanted_vehicles` | **0** | U | Avis de recherche « véhicule » | `created_by` → `users.id`, **jamais renseigné** |

**Aucune clé étrangère sur les 24 tables.** Les 8 tables vides ne sont pas mortes : six sont
des tables d'état transitoire, normalement vides quand personne n'est en service ; les deux
avis de recherche disposent d'un CRUD complet mais n'ont jamais servi.

Trois colonnes mortes à connaître : `dispatch_services.afk_flag` et `.last_afk_check` ne sont
ni lues ni écrites (vestiges d'un essai abandonné), et `dispatch_logs.actor_roster_id`,
`.actor_user_id`, `.actor_label` ne sont jamais alimentées — **l'index `idx_actor_roster`
indexe donc une colonne intégralement `NULL`**.

### 3.12 Supervision et RH (4 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `sup_effectifs` | 461 | **G** | Instantané hebdomadaire de l'effectif : grade, heures, sanction, note | `week_id` → `sup_weeks.id` ; `matricule` → `roster.matricule` — **jointure SQL impossible** (collations) |
| `sup_agent` | 56 | **G** | Méta RH par matricule : dates d'arrivée et de promotion, grade en attente, dérogation | `matricule` → `roster.matricule` — **jointure SQL impossible** |
| `sup_weeks` | 8 | **G** | Semaines de supervision ; une seule porte `is_current = 1` | Cible de `sup_effectifs.week_id` |
| `sup_sanctions` | **0** | **G** | Sanction individuelle avec photos | `matricule` → `roster.matricule` |

L'unicité de la semaine courante n'est garantie **par aucune contrainte** : elle repose sur un
`UPDATE sup_weeks SET is_current = 0` exécuté avant chaque bascule.

### 3.13 Messagerie (8 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `msg_members` | 145 | U | Appartenance à une conversation et curseur de lecture | UNIQUE `(conv_id, discord_id)` ; `kind` ENUM |
| `msg_messages` | 85 | U | Message, avec soft-delete et épinglage | `conv_id` → `msg_conv.id` ; `reply_to` auto-référence |
| `msg_typing` | 29 | U | Indicateur « en train d'écrire » | PK `(conv_id, discord_id)` ; **jamais purgée par âge** |
| `msg_conv` | 12 | U | Conversation : salon, message privé, groupe ou fil | `folder_id` → `msg_folder.id` ; `logo` → `msg_attachments.id` ; auto-références `parent_conv_id`/`parent_msg_id` ; `type` ENUM |
| `msg_attachments` | 5 | U | Pièce jointe, avec soft-delete | `message_id` → `msg_messages.id`, nullable ; `stored_name` UNIQUE ; `kind` ENUM |
| `msg_reactions` | 3 | U | Réaction emoji | PK `(message_id, discord_id, emoji)` |
| `msg_folder` | 1 | U | Dossier de classement des salons | Cible de `msg_conv.folder_id` |
| `msg_meta` | 1 | **G** | Clé/valeur du module, dont `ddl_version`. Colonnes `(mk, mv)` | — |

### 3.14 Annonces et notifications (3 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `notifications` | 466 | U | Cloche utilisateur, tous modules confondus | `discord_id` → `users.discord_id` ; `(ref_type, ref_id)` → entité du module émetteur, **résolution purement conventionnelle** ; UNIQUE `(discord_id, ref_type, ref_id)` |
| `annonce_lectures` | 144 | U | Accusé de lecture d'une annonce obligatoire | PK `(annonce_id, discord_id)` ; **aucune suppression en cascade** |
| `annonces` | 6 | U | Publication ciblée par canal et par rôles | `created_by` → `users.discord_id` ; `roles` et `medias` en JSON non validé |

`notifications.type` n'a **aucune liste blanche, ni en base ni en PHP**. Les valeurs
réellement présentes sont `annonce`, `message`, `sanction`, `recherche`, `cid_reply` — cette
liste n'est écrite nulle part dans le code, elle ne s'obtient qu'en interrogeant la base.

`annonces.canal` est contraint côté PHP à trois valeurs (`divisions`, `supervision`, `news`)
alors que la base n'en contient que deux.

### 3.15 Carte tactique (3 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `tac_shapes` | 130 | **G** | Forme dessinée sur un plan, synchronisée par curseur de révision | `board_id` → `tac_boards.id` ; UNIQUE `(board_id, cid)` ; `pts` = tableau de coordonnées en JSON |
| `tac_boards` | 21 | **G** | Plan de dessin : commun, lié à une intervention, privé, ou image de briefing | `owner_did` → `users.discord_id` ; **l'identifiant encode la relation** : `tb_i_<id intervention>`, `tb_p_<md5>`, `tb_u_<md5>` |
| `tac_meta` | 1 | **G** | Clé/valeur : uniquement `ddl_version`. Colonnes `(mk, mv)` | — |

Ces deux tables sont, avec celles de la recherche, **les mieux indexées de la base** :
`u_board_cid` sert l'UPSERT, `k_sync(board_id, rev)` sert le polling incrémental.

### 3.16 Battues / recherche (7 tables)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `rch_etats` | 96 | **G** | État de fouille d'une zone dans une opération | PK `(op_id, zone_id)` ; `k_sync(op_id, rev)` |
| `rch_zones` | 96 | **G** | Lieu à risque à fouiller, rattaché à un secteur | `secteur_id` → `rch_secteurs.id` ; `pts` en JSON |
| `rch_secteurs` | 19 | **G** | Catalogue permanent des secteurs de la carte | UNIQUE sur `nom` ; `pts` en JSON |
| `rch_log` | 3 | **G** | Journal append-only des changements d'état de **zone** | `zone_id` NOT NULL ⇒ ne peut pas journaliser une cellule |
| `rch_ops` | 1 | **G** | Une opération de recherche (quadrillage) ouverte par la supervision | `by_did` → `users.discord_id` |
| `rch_meta` | 1 | **G** | Clé/valeur : uniquement `ddl_version`. Colonnes `(mk, mv)` | — |
| `rch_cells` | **0** | **G** | État de fouille d'une case du quadrillage grossier | PK `(op_id, cell)` ; **écrit sans journal**, contrairement aux zones |

### 3.17 Médiathèque (1 table)

| Table | Lignes | Coll. | Rôle | Relations principales |
|---|---|---|---|---|
| `upload_files` | 1 261 | U | Métadonnées des images et vidéos de la bibliothèque partagée | `owner_did` → `users.discord_id` ; `stored_name` UNIQUE → fichier dans `videos/` ; `kind` ENUM |

### 3.18 Récapitulatif : tables vides et tables mortes

**17 tables sont vides** au 27 septembre 2026 :

| Catégorie | Tables | Interprétation |
|---|---|---|
| Transitoires (vides hors service actif) | `dispatch_patrouilles`, `dispatch_patrouille_agents`\*, `dispatch_intervention_patrouilles`, `dispatch_queue`, `dispatch_pauses`, `dispatch_dispatchers`, `dispatch_agent_operations` | **Normal.** Elles se remplissent dès qu'un agent prend son service |
| Fonctionnalités livrées jamais adoptées | `role_config`, `roster_blocked_matricules`, `drogue_types`, `sup_sanctions`, `doc_role_perms`, `doc_hierarchie`, `doc_path_alias`, `rch_cells`, `cid_pins`, `dispatch_wanted_persons`, `dispatch_wanted_vehicles` | Code actif des deux côtés, donnée absente. **Ne pas supprimer** : la suppression casserait du code |

\* `dispatch_patrouille_agents` contenait 1 ligne au moment de la mesure.

**3 tables sont réellement mortes** — aucune occurrence de leur nom dans les fichiers `.php`,
`.js` et `.html` servis :

| Table | Lignes | Remarque |
|---|---|---|
| `td_docs` | 12 | Ancienne base documentaire du module Formation, remplacée par `documents/`. Référence 19 fichiers dans `td/uploads/`, lui aussi orphelin |
| `td_doc_categories` | 4 | Ses catégories |
| `td_notations` | 1 | Vestige du modèle de notation V1. Seule trace : un `SELECT COUNT(*)` d'affichage ; le contenu n'est jamais lu |

Deux tables sont partiellement mortes : `doc_meta` (lue, jamais écrite) et `msg_meta` /
`tac_meta` / `rch_meta` (ne servent qu'à stocker `ddl_version`).

**Colonnes mortes recensées** : `doc_documents.legacy_id`, `doc_documents.legacy_roles`,
`doc_divisions.kind`, `dispatch_services.afk_flag`, `dispatch_services.last_afk_check`,
`dispatch_logs.actor_user_id`, `dispatch_logs.actor_roster_id`, `dispatch_logs.actor_label`,
`dispatch_salaire_paye.paid_by` (colonne existante, jamais renseignée par l'`INSERT`).

---

## 4. Relations structurantes

### 4.1 Le fait le plus important de tout ce document

**La base compte 8 clés étrangères pour 118 tables.** Les voici, exhaustivement :

| Contrainte | Colonne | Cible | Règles |
|---|---|---|---|
| `fk_cat_div` | `doc_categories.division_id` | `doc_divisions.id` | `ON DELETE RESTRICT`, `ON UPDATE CASCADE` |
| `fk_doc_cat` | `doc_documents.categorie_id` | `doc_categories.id` | `ON DELETE RESTRICT`, `ON UPDATE CASCADE` |
| `fk_file_doc` | `doc_files.doc_id` | `doc_documents.id` | `ON DELETE CASCADE` |
| `fk_fl_sec_type` | `td_fl_sections.type_id` | `td_fl_types.id` | `ON DELETE CASCADE` |
| `fk_fl_crit_sec` | `td_fl_criteres.section_id` | `td_fl_sections.id` | `ON DELETE CASCADE` |
| `fk_fl_cat_type` | `td_fl_quest_categories.type_id` | `td_fl_types.id` | `ON DELETE CASCADE` |
| `fk_fl_q_cat` | `td_fl_questions.category_id` | `td_fl_quest_categories.id` | `ON DELETE CASCADE` |
| `fk_fl_cfg_type` | `td_fl_config.type_id` | `td_fl_types.id` | `ON DELETE CASCADE` |

**Tout le reste — soit plus de cent relations — ne tient que par le code PHP.** Il n'y a ni
`ON DELETE`, ni `ON UPDATE`, ni vérification à l'insertion par le moteur. Chaque suppression
en cascade est écrite à la main dans l'API concernée, et si une branche du code l'oublie,
la base garde des orphelins sans le signaler.

Les 5 cascades de `td_fl_*` ne se déclenchent d'ailleurs **jamais** en pratique : l'interface
fait du soft-delete (`UPDATE … SET active = 0`) et ne supprime aucune ligne.

### 4.2 Chaîne 1 — identité : la double clé `users.id` / `users.discord_id`

```
user_sessions.user_id ──FK logique──► users.id
                                        │
users.discord_id ◄──────────────────────┘
      ▲
      ├── roster.discord_id                    (annuaire des agents)
      ├── audit_log.actor_did                  (!) jointure SQL IMPOSSIBLE (collation)
      ├── upload_files.owner_did
      ├── notifications.discord_id
      ├── msg_members / msg_messages / msg_typing / msg_reactions.discord_id
      ├── annonce_lectures.discord_id
      ├── npu_responses / npu_chrono.discord_id
      ├── user_presence.discord_id
      ├── dev_users.discord_id
      ├── tac_boards / tac_shapes.owner_did
      ├── rch_ops / rch_etats.by_did
      └── tous les created_by / updated_by / uploaded_by / actor_id / author_id
          des modules cid_*, doc_*, td_*, sup_*
```

Deux pièges structurels :

- **`discord_id` est stocké en `varchar(30)` dans 39 colonnes et en `varchar(32)` dans 12
  autres.** Les colonnes en 32 sont les plus récentes (`annonces`, `msg_*`, `notifications`,
  `npu_*`, `suspects`). Un identifiant Discord tient dans 30, donc il n'y a pas de troncature
  aujourd'hui, mais les deux longueurs ne sont pas interchangeables dans un index composite.
- **4 colonnes nommées comme les autres contiennent en réalité un `users.id` entier** :
  `dispatch_intervention_attachments.uploaded_by`, `dispatch_salaire_paye.paid_by`,
  `dispatch_wanted_persons.created_by`, `dispatch_wanted_vehicles.created_by`. Elles sont en
  `int(11)`, pas en `varchar`. Les trois dernières ne sont jamais renseignées.

### 4.3 Chaîne 2 — le roster, pivot du dispatch, et son défaut majeur

```
roster.id ──► dispatch_services.roster_id          (pointage)
          ──► dispatch_salaire_paye.roster_id      (paie)
          ──► dispatch_avertos.roster_id           (avertissements)
          ──► dispatch_patrouille_agents.roster_id
          ──► dispatch_dispatchers / _pauses / _queue / _agent_operations.roster_id

roster.matricule ──► sup_agent.matricule           (!) jointure SQL IMPOSSIBLE (collation)
                 ──► sup_effectifs.matricule       (!) idem
                 ──► dispatch_avertos.matricule    (!) idem
                 ──► roster_blocked_matricules.matricule

roster.discord_id ──► users.discord_id
```

**Le problème :** l'action `reset` de `roster_api.php` exécute `DELETE FROM roster` puis
réinsère l'annuaire complet. Comme `roster.id` est `AUTO_INCREMENT`, **tous les identifiants
changent à chaque remise à plat**. Constat mesuré sur la production :

| Mesure | Valeur |
|---|---|
| Lignes dans `roster` | 58 |
| Plus grand `roster.id` attribué | **4 609** |
| `dispatch_services` pointant vers un `roster_id` disparu | **3 671 sur 6 551 — 56 %** |
| `dispatch_salaire_paye` pointant vers un `roster_id` disparu | **252 sur 569 — 44 %** |
| `dispatch_avertos` idem | 13 sur 50 |
| `dispatch_services` pointant vers un `users.id` disparu | 44 |

Autrement dit : **plus de la moitié de l'historique des heures de service n'est plus
rattachable à un agent.** La clé stable est `roster.matricule` (UNIQUE), pas `roster.id`.
C'est, de loin, la dette de modèle la plus coûteuse à reprendre.

### 4.4 Chaîne 3 — casier judiciaire

```
personnes.id ◄── rapport_personnes.personne_id ──► rapports.id
     ▲                                                 ▲
     │                                                 │
saisies.personne_id ─────────────────────────► saisies.rapport_id
                                                       │
saisies.sd_analyse_id ──► sd_analyses.id               │
                                                       │
rapports.charges (JSON) ──── référence penal_code.code par TEXTE
```

Cette chaîne est **propre** : mesurée sur la production, elle ne comporte **aucun orphelin**
(0 `saisies.rapport_id` sans rapport, 0 `rapport_personnes` sans cible). C'est l'exception.

Deux particularités : `saisies.rapport_id` et `saisies.personne_id` ne sont écrits que par
`casier_api.php` — l'API des saisies elle-même ne les touche jamais. Et le lien vers le code
pénal se fait **par la valeur textuelle du code d'infraction** stockée dans un JSON, pas par
`penal_code.id`.

### 4.5 Chaîne 4 — permissions et visibilité

```
users.discord_roles (JSON : ["<role_id>", …])
        │
        ├─ ∩ module_permissions.role_id WHERE module_key = '<module>'  → accès au module
        ├─ ∩ cid_role_perms.role_id                                    → permission fine CID
        ├─ ∩ doc_role_perms.role_id                                    → permission fine Documentation
        ├─ ∩ role_config.role_id  (table VIDE → repli sur des constantes PHP)
        ├─ ∩ cid_category_visibility.role_id                           → voir une catégorie CID
        ├─ ∩ doc_visibility.role_id                                    → voir une division / catégorie
        ├─ ∩ tac_boards.roles / roles_edit (JSON)                      → voir / éditer un plan
        └─ ∩ annonces.roles (JSON)                                     → destinataires d'une annonce
```

Toutes ces intersections sont faites **en PHP**, jamais en SQL. Plusieurs d'entre elles
impliquent de charger l'intégralité de `users` en mémoire et de `json_decode` chaque ligne —
voir §5.4.

`discord_roles_cache` est la table de référence des `role_id`, mais **aucune clé étrangère ne
protège les six tables qui y pointent** : supprimer un rôle sur Discord laisse des
`role_id` fantômes dans `module_permissions`, `cid_role_perms`, `doc_visibility`, etc.

### 4.6 Chaîne 5 — les relations polymorphes

Cinq tables portent une relation « une colonne de type + une colonne d'identifiant », qui par
construction **ne peut pas être contrainte par le moteur** :

| Table | Colonnes | Cibles possibles |
|---|---|---|
| `cid_replies` | `(entity_type, entity_id)` | `cid_informations`, `cid_telephones`, `cid_interrogatoires`, `cid_notes`, `cid_hierarchie` |
| `doc_visibility` | `(scope, scope_id)` | `doc_divisions`, `doc_categories` |
| `doc_path_alias` | `(kind, target_id)` | `doc_divisions`, `doc_categories`, `doc_documents` |
| `notifications` | `(ref_type, ref_id)` | `annonces`, `msg_messages`, `rch_ops`, `cid_replies`, sanctions dispatch |
| `dispatch_logs` | `(target_type, target_id)` | service, agent, patrouille, intervention, averto, heures, action |
| `audit_log` | `(entity_type, entity_id)` | n'importe quoi |

La table cible se résout par un tableau associatif PHP (`$CID_ENTITIES`, `$FL_ENT`…). Une
faute de frappe dans un `entity_type` produit une ligne inexploitable, sans erreur.

### 4.7 Chaîne 6 — les relations enfouies dans du JSON

Plusieurs identifiants servant de clé étrangère sont stockés **à l'intérieur d'un blob JSON**,
donc invisibles pour tout outil d'analyse de schéma :

| Porteur | Chemin dans le JSON | Cible |
|---|---|---|
| `td_epreuves.data` | `.cat_id` | `td_epreuve_categories.id` |
| `td_sessions.data` | `.recrues[].resultats.<clé>` | `td_epreuves.id` |
| `td_fiches.data` | `.notations.<clé>` | `td_notation_criteria.id` |
| `td_first_lincoln.scores` | clés de l'objet | `td_fl_criteres.id` |
| `td_first_lincoln.questionnaire` | clés de l'objet | `td_fl_questions.id` |
| `rapports.charges` | code d'infraction | `penal_code.code` |
| `annonces.roles`, `tac_boards.roles` | éléments du tableau | `discord_roles_cache.role_id` |
| `users.discord_roles` | éléments du tableau | `discord_roles_cache.role_id` |
| `msg_conv.roles` | éléments du tableau | `discord_roles_cache.role_id` |

Un `DELETE` sur `td_notation_criteria` ou `td_fl_criteres` laisse donc des clés orphelines
dans les blobs des fiches déjà remplies. Le code s'en protège en partie par un mécanisme de
**snapshot de barème** (`td_fiches.data.bareme_snapshot`, `td_first_lincoln.bareme_snapshot`)
qui fige le référentiel au moment du verrouillage.

### 4.8 Ce qui est contraint par le moteur, au total

| Mécanisme | Nombre | Détail |
|---|---|---|
| Clés étrangères | **8** | `doc_*` (3), `td_fl_*` (5) |
| Contraintes `CHECK json_valid` | **18** | Voir §2.6 |
| Colonnes `ENUM` | **15** | Voir §5.1 |
| Index `UNIQUE` métier | ~25 | `users.username`, `users.discord_id`, `roster.matricule`, `personnes.nom_normalise`, `penal_code.code`, `suspects.name`, `upload_files.stored_name`, `doc_files.stored_name`, `msg_attachments.stored_name`, `dispatch_salaire_paye (roster_id, week_start)`, `notifications (discord_id, ref_type, ref_id)`, `tac_shapes (board_id, cid)`, `rch_secteurs.nom`, `dispatch_agent_operations.roster_id`, `dispatch_dispatchers (role, roster_id)`… |

Tout le reste de l'intégrité est applicatif.

---

## 5. Pièges et points d'attention

Cette section rassemble ce qui n'est pas visible dans le schéma et coûterait cher à
redécouvrir seul.

### 5.1 Les ENUM : 15 en base, des dizaines ailleurs

La base ne contient que **15 colonnes `ENUM`**, toutes listées ici :

| Colonne | Valeurs |
|---|---|
| `audit_log.result` | `ok`, `error` |
| `audit_log.source` | `server`, `client` |
| `dispatch_interventions.priorite` | `low`, `medium`, `high`, `critical` |
| `dispatch_interventions.statut` | `nouveau`, `en_cours`, `termine` |
| `dispatch_dispatchers.role` | `dispatcher`, `co_dispatcher`, `supervision` |
| `doc_categories.visibilite`, `doc_divisions.visibilite` | `ouvert`, `restreint` |
| `doc_documents.statut` | `brouillon`, `publie` |
| `doc_files.kind` | `image`, `pdf`, `video`, `bureau` |
| `doc_path_alias.kind` | `division`, `categorie`, `document` |
| `doc_visibility.scope` | `division`, `categorie` |
| `msg_attachments.kind` | `image`, `video`, `pdf`, `bureau` |
| `msg_conv.type` | `channel`, `dm`, `group`, `thread` |
| `msg_members.kind` | `owner`, `member`, `invited` |
| `upload_files.kind` | `image`, `video` |

**Toutes les autres énumérations de l'application sont des `VARCHAR` nus, contraints
uniquement par une liste blanche PHP — ou par rien du tout.** Les principales :

| Colonne | Type réel | Contrainte | Valeurs |
|---|---|---|---|
| `saisies.type` | `varchar(10)` | `in_array` strict dans `saisies_api.php` | `arme`, `drogue`, `objet` |
| `saisies.poste` | `varchar(10)` | `in_array` strict | `nord`, `sud` |
| `saisies.vol_statut` | `varchar(20)` | littéral en dur | `volee` ou `NULL` |
| `rapports.type` | `varchar(2)` | `in_array` **non strict** | `DA`, `RA` |
| `cid_informations.statut`, `cid_pins.statut` | `varchar(20)` | `in_array` | `important`, `modere`, `faible` |
| `td_first_lincoln.resultat` | `varchar(20)` | produit par une fonction | `en_cours`, `eliminatoire`, `reussi`, `echoue` |
| `td_fl_types.situation_mode` | `varchar(20)` | `in_array` | `always`, `conditional`, `never` |
| `td_epreuves.data.type` | JSON | `in_array` | `simple`, `notation`, `temps` |
| `rch_etats.etat`, `rch_cells.etat` | `varchar` | `in_array` strict | `non`, `encours`, `ok`, `ras`, `suspect` |
| `rch_ops.statut` | `varchar(12)` | littéraux dispersés | `active`, `close` |
| `tac_shapes.kind` | `varchar` | `in_array` strict | `ink`, `line`, `arrow`, `zone`, `circle`, `text`, `point` |
| `dispatch_services.auto_close_reason` | `varchar(24)` | littéraux dispersés | `idle_timeout`, `no_heartbeat`, `hard_cap`, `orphan_roster`, `force_supervision` |
| `dispatch_intervention_vehicules.statut` | `varchar(30)` | littéral | `en_cours`, `interpelle`, `perdu` |
| `annonces.canal` | `varchar` | `in_array` | `divisions`, `supervision`, `news` |
| `notifications.type` | `varchar` | **aucune** | valeurs constatées : `annonce`, `message`, `sanction`, `recherche`, `cid_reply` |
| `security_events.severity` | `varchar` | **aucune** | `info`, `low`, `medium`, `high`, `critical` |
| `dispatch_poursuite_log.resultat` | `varchar(30)` | **aucune** | commentaire du code : `interpelle`, `perdu` — mais la valeur est insérée brute |
| `sd_analyses.evidence_type` | `varchar(50)` | **aucune** | voir ci-dessous |

Trois cas méritent une attention particulière :

1. **`sd_analyses.evidence_type`** — une liste blanche `$SD_TYPES` est déclarée en tête de
   `sd/sd_api.php` et **n'est jamais utilisée**. La seule transformation appliquée est
   `strtoupper(substr(..., 0, 50))`. N'importe quelle chaîne de 50 caractères est acceptée,
   alors que `saisies_api.php` filtre en dur sur `evidence_type = 'ARME'`.
2. **`saisies.type`** — la liste blanche est appliquée dans `saisies/saisies_api.php`, mais
   `casier_api.php` insère une saisie avec un repli `?: 'objet'` **sans `in_array`**. Il existe
   donc deux chemins d'écriture avec deux niveaux de validation différents.
3. **`carte/tacmap_api.php`** déclare une liste blanche `$TAC_BGS` qui n'est, elle non plus,
   jamais appliquée.

Règle à retenir pour un repreneur : **avant de modifier une valeur de statut, faire un
`SELECT DISTINCT` sur la colonne, puis chercher la chaîne dans tout le code** — la liste
autoritaire n'est jamais en base.

### 5.2 Journaux : volume, index et rétention

**Volumes et poids réels :**

| Table | Lignes | Taille | Plus ancienne ligne | Croissance |
|---|---|---|---|---|
| `audit_log` | 81 409 | **34,3 Mo (63 % de la base)** | 28/08/2026 | **~2 700 lignes/jour** |
| `security_events` | 1 805 | 6,1 Mo | 28/08/2026 | ~60/jour |
| `dispatch_logs` | 5 465 | 4,3 Mo | 28/08/2026 | ~190/jour |
| `cid_logs` | 448 | 1,5 Mo | 13/07/2026 | faible |
| `doc_logs` | 1 | — | — | — |
| `rch_log` | 3 | — | — | — |

`audit_log` explose parce qu'il n'est pas écrit à la demande mais **automatiquement** : une
`register_shutdown_function` dans `mdt_security.php` capture **tout POST et toute réponse
HTTP ≥ 400** de tout le site, avec les corps `before_json` / `after_json`. Les appels
explicites à `mdt_audit()` ne représentent que 4 emplacements dans tout le code.

**Rétention — il y en a une, et elle n'est pas là où on la cherche.**

Il existe un script planifié, `scripts/purge-journaux.php`, lancé par la crontab de
l'utilisateur système tous les jours à **04h00**, qui supprime par lots de 50 000 tout ce qui
dépasse **30 jours** dans `security_events`, `audit_log` et `dispatch_logs`. C'est ce qui
explique que les trois tables commencent exactement le 28/08.

**Mais** `admin_api.php` contient aussi, en effet de bord de l'action `audit_list`, un
`DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 90 DAY`. **Deux politiques de
rétention contradictoires (30 et 90 jours) cohabitent** : la seconde est simplement inopérante
puisque la première passe avant. Un repreneur qui lirait seulement `admin_api.php` conclurait
à tort à une rétention de 90 jours.

**Aucune rétention** en revanche pour : `cid_logs`, `doc_logs`, `rch_log`,
`dispatch_poursuite_log`, `notifications` (466 lignes, jamais purgées), `msg_typing`
(table de travail, jamais purgée par âge), `user_sessions` (voir §5.3), `dispatch_services`,
`dispatch_interventions`, `msg_messages`.

**Index sur `audit_log` :** `PRIMARY(id)`, `idx_created(created_at)`, `idx_module(module)`,
`idx_actor(actor_did)`, `idx_result(result)`. Confrontés aux filtres réellement proposés par
l'écran d'audit :

| Filtre du code | Couvert ? |
|---|---|
| `module = ?` | oui |
| `actor_did = ?` | oui |
| `result = ?` | index présent mais cardinalité 2 — l'optimiseur l'ignorera |
| `source = ?` | **non** |
| `action LIKE '%…%'`, `summary LIKE '%…%'`, `err_code LIKE '%…%'`, `actor_name LIKE '%…%'` | **non, et non indexables** (`%` en tête) |
| `ORDER BY id DESC LIMIT/OFFSET` | couvert par la clé primaire, **mais annulé dès qu'un filtre non indexé s'applique** |

À chaque ouverture de l'écran, deux `COUNT(*)` complets sont exécutés sur les 81 409 lignes.
Index manquants les plus rentables : `(module, id)`, `(source, id)`, `(result, id)`.

**Index sur `dispatch_logs` :** l'écran de consultation filtre sur `actor_matricule`,
`actor_discord_id` et `actor_name` — **aucune des trois n'est indexée**. Le plan d'exécution
retombe sur un balayage de la clé primaire. En prime, l'unique index d'acteur existant,
`idx_actor_roster`, porte sur une colonne **intégralement `NULL`** (voir §3.11).

### 5.3 Index manquants, index inutiles, index en double

**À supprimer :**

| Index | Table | Raison |
|---|---|---|
| `idx_svc_endat(end_at)` | `dispatch_services` | **Doublon exact** de `idx_active(end_at)`. Le DDL de bootstrap les crée tous les deux |
| `idx_actor_roster(actor_roster_id)` | `dispatch_logs` | Colonne jamais alimentée — index sur du `NULL` intégral |
| `idx_ann(annonce_id)` | `annonce_lectures` | Redondant avec le préfixe de la clé primaire `(annonce_id, discord_id)` |
| `idx_msg(message_id)` | `msg_reactions` | Redondant avec le préfixe de la clé primaire |

**À ajouter, par ordre de rentabilité :**

| Index | Table | Requête servie |
|---|---|---|
| `(roster_id, end_at)` | `dispatch_services` | « cet agent est-il en service ? » — la requête la plus fréquente du module, aujourd'hui servie par `idx_roster` seul puis filtrée ligne à ligne |
| `(created_at)` | `dispatch_interventions` | L'historique fait `GROUP BY id ORDER BY created_at DESC LIMIT/OFFSET` sur 2 752 lignes : **balayage complet + tri temporaire à chaque appel** |
| `(created_at)` | `saisies` | 2 289 lignes chargées et triées sans index à l'ouverture du module |
| `(arme_serial)` | `saisies` | L'action `check_serial` fait un `WHERE arme_serial = ?` non indexé |
| `(created_at)` | `sd_analyses`, `sd_reports` | **Aucun index hors clé primaire** sur ces deux tables, alors que les deux écrans font un `SELECT *` trié sans `LIMIT` |
| `(actor_discord_id)`, `(actor_matricule)` | `dispatch_logs` | Filtres de l'écran de consultation |
| `(service_date, annule, end_at)` | `dispatch_services` | Agrégat hebdomadaire des heures |
| `(action_button_id)` | `dispatch_interventions` | Déduplication des interventions à la création |
| `(tag_id)` | `plainte_tag_assignments` | `DELETE … WHERE tag_id = ?` : le `tag_id` est le **second** membre de la clé primaire, donc inutilisable seul |

**Contrainte d'unicité manquante :** rien n'empêche deux lignes `dispatch_services` avec
`end_at IS NULL` pour le même `roster_id`. La garde est purement applicative
(`SELECT … WHERE end_at IS NULL` puis 409) et **non transactionnelle** dans `start_service` :
deux requêtes simultanées peuvent créer deux services ouverts. Un index unique partiel
résoudrait le problème, mais MariaDB ne les propose pas — il faudrait une colonne générée.

### 5.4 Requêtes coûteuses sur les chemins chauds

Le dispatch est interrogé en polling permanent par tous les postes en service. Sur ce chemin :

- **`board`** enchaîne 9 requêtes non paginées à chaque sondage. La requête principale filtre
  `WHERE statut != 'termine'` (une inégalité, donc index inutilisable) puis `GROUP BY` et
  `ORDER BY FIELD(priorite, …)` : table temporaire + tri à chaque fois. Un cache ETag/304
  existe, mais **le SQL s'exécute avant le calcul du hash**.
- **`list_services`** exécute 8 requêtes, dont un
  `SELECT discord_id, discord_roles, telephone FROM users WHERE discord_roles IS NOT NULL` :
  **balayage complet de `users` puis `json_decode` de chaque ligne**, à chaque sondage.
- **`rch_destinataires()`** fait la même chose (balayage complet de `users` +
  `array_intersect` par ligne) à chaque ouverture d'opération de recherche.
- **`login/auth_api.php`**, en repli de connexion « tolérante », charge `SELECT * FROM users`
  en mémoire pour comparer des versions normalisées des identifiants.

Avec 185 comptes ces balayages passent inaperçus. Ils ne passeront pas l'échelle.

### 5.5 Colonnes dupliquées entre tables (dénormalisation non gérée)

Plusieurs tables recopient au moment du fait des données qui vivent ailleurs. C'est
volontaire — figer un état historique — mais **aucune ne porte de mécanisme de
resynchronisation**, et il n'est écrit nulle part laquelle fait foi.

| Donnée | Source | Copies |
|---|---|---|
| Matricule + nom d'un agent | `roster.matricule`, `roster.nom_prenom` | `dispatch_avertos.matricule`/`nom_prenom`, `dispatch_logs.actor_matricule`/`actor_name`, `sup_effectifs.matricule`/`nom_prenom`, `rch_etats.by_mat`/`by_name`, `rch_ops.by_name`, `tac_boards.owner_name`, `tac_shapes.owner_name` |
| Nom d'affichage d'un utilisateur | `users.discord_nick` / `discord_username` | `audit_log.actor_name`, `cid_logs.actor_name`, `doc_logs.actor_name`, `msg_messages.author_name`, `annonces.created_by_name`, `sd_analyses.author`, `sd_reports.author`, `plaintes.redacteur`, `saisies.agent` |
| Grade | déduit de `users.discord_roles` | `sup_effectifs.grade`, `plaintes.grade_redacteur` |
| Cadet et référent d'une fiche | `roster` | `td_fiches.cadet`, `td_fiches.referent` — stockés comme **chaîne « matricule \| nom »**, pas comme identifiant |
| Barème d'évaluation | `td_fl_*` | `td_fiches.data.bareme_snapshot`, `td_first_lincoln.bareme_snapshot` — snapshot volontaire au verrouillage |

Cas le plus gênant : `sd_analyses.author` et `saisies.agent` ne contiennent qu'un nom
d'affichage. Si l'agent change de pseudo Discord, **l'attribution historique est perdue**.

### 5.6 Statuts et états implicites

- **Une plainte n'a pas de statut.** Son état se déduit de la présence, dans
  `plainte_tag_assignments`, d'une étiquette dont `plainte_tags.is_closed = 1`.
- **Une évaluation « finalisée » n'a pas de drapeau.** Le code teste
  `!empty($row['bareme_snapshot'])` sur `td_first_lincoln` : c'est la non-nullité du snapshot
  qui vaut finalisation.
- **`dispatch_pauses` et `dispatch_queue` n'ont pas de colonne d'état** : l'existence de la
  ligne *est* l'état.
- **`cid_category_visibility` n'a pas de colonne « confidentiel »** : la simple présence d'une
  ligne pour une catégorie la rend confidentielle et déclenche, à la suppression d'un dossier,
  une purge des contenus liés au lieu d'un simple détachement.
- **`module_permissions` sans ligne = module public.** Ce fail-open a un effet mesurable :
  le module NPU appelle `mdt_module_access($conn, 'npu', …)`, mais `npu` **ne figure ni dans
  la liste PHP des modules valides ni dans `module_permissions`** — le contrôle renvoie donc
  toujours vrai, et le module est ouvert à tout compte connecté. À traiter.

### 5.7 Sessions

- Durée : **30 jours**, glissants (prolongés dès qu'il reste moins de 15 jours).
- Jeton en `localStorage`, envoyé en `Authorization: Bearer`. **Pas de cookie, donc ni
  `HttpOnly` ni `SameSite`.** `user_sessions` ne stocke ni IP ni user-agent : impossible de
  distinguer deux sessions d'un même compte.
- **Aucun nettoyage des sessions expirées.** Sur 283 lignes, **98 sont périmées**. La table est
  petite, le problème est propre, mais il ne se résout jamais tout seul.
- Distribution des durées mesurée : 184 sessions à 30 jours, une trentaine entre 45 et 79 jours
  (effet du glissement, `created_at` restant figé) et **58 sessions à 365 jours**, toutes
  créées les 3 et 4 août 2026. Aucun code actuel ne produit une durée d'un an :
  **l'origine de ces 58 lignes n'a pas pu être établie** ; c'est probablement un réglage
  temporaire lors de la bascule du 03/08. Elles expireront en août 2027.

### 5.8 Requêtes non préparées

**Bonne nouvelle : il n'y a aucune injection SQL exploitable.** Une recherche systématique
d'une variable de requête HTTP concaténée dans une chaîne SQL (`$_GET`, `$_POST`, `$_REQUEST`)
ne remonte **aucun résultat** sur les 34 fichiers PHP. `PDO::ATTR_EMULATE_PREPARES` est à
`false`, ce qui est le bon réglage.

Il reste de la dette de style, en trois familles :

1. **Entiers castés concaténés** — surtout pour `LIMIT`/`OFFSET`, que PDO ne sait pas
   paramétrer proprement en mode non émulé. Environ 20 occurrences, notamment dans
   `messagerie/messagerie_api.php` (`WHERE conv_id = $cid`, `WHERE id = $mid`),
   `admin_api.php`, `upload_api.php`, `notifications/notif_api.php`,
   `documents/documents_api.php`, `dispatch/dispatch_api.php`. Toutes les variables sont
   `(int)`-castées et bornées en amont.
2. **`PDO::quote()`** — 4 occurrences : `notifications/notif_api.php:64`, `admin_api.php:231`,
   `penal/penal_api.php:90`, `documents/documents_api.php:1211`. Échappé, donc sûr, mais
   incohérent avec le reste du fichier qui est intégralement préparé.
3. **Noms de table et de colonne interpolés** — résolus depuis des tableaux associatifs PHP
   codés en dur (`$CID_ENTITIES`, `$FL_ENT`, whitelists de champs), avec un `isset()` en
   garde. Sûr, mais c'est le motif le plus fragile à la maintenance.

Deux points à surveiller quand même : `mdt_security.php` interpole une durée dans un
`INTERVAL {$windowMin} MINUTE` (aujourd'hui toujours un littéral, mais rien ne le garantit
dans la signature), et `admin_api.php` stocke des requêtes SQL complètes comme **données**
dans un tableau `$defs[]['sql']` avant de les exécuter.

### 5.9 Le DDL s'exécute depuis les API de requête

C'est le piège le plus contre-intuitif de la base. Détaillé en §7, mais résumé ici :
**110 instructions `CREATE TABLE IF NOT EXISTS` et 52 `ALTER TABLE` sont réparties dans 16 fichiers PHP** et
s'exécutent au fil des requêtes HTTP normales. Conséquences directes sur le modèle :

- Les définitions de `CREATE TABLE` du code ont **divergé du schéma réel**. Exemple :
  le `CREATE TABLE cid_dossiers` de `cid/cid_api.php` ne contient ni `statut_id` ni
  `archived_at`, qui sont ajoutés ensuite par des `ALTER` conditionnels.
- Deux `CREATE TABLE IF NOT EXISTS dispatch_salaire_paye` sont rejoués **à chaque appel**
  des actions `service_hours` et `mark_paid`, sans aucune garde de version.
- Les erreurs de DDL sont avalées par des `catch (PDOException) {}` silencieux.

### 5.10 Divers, mais à connaître

- **`carte/tacmap_api.php` interroge `dispatch_interventions.titre`, colonne qui n'existe
  pas.** La requête est dans un `try/catch` muet : l'échec est permanent et invisible, le
  libellé retombe toujours sur une valeur par défaut.
- **`dispatch/dispatch_api.php` contient une seconde branche `force_end_service`
  inatteignable** (chaîne `if/elseif` sur la même variable). C'est pourtant la version
  « équitable » du calcul, bornée à la dernière activité. Le comportement réellement en
  production crédite le service jusqu'à l'heure courante.
- **La paie n'est jamais calculée côté serveur.** `dispatch_salaire_paye` ne stocke aucun
  montant : c'est un simple marqueur `(roster_id, week_start)`. Le montant est calculé dans le
  navigateur à partir d'une table de taux par grade codée en PHP. Si un grade change ou si les
  heures sont ajustées après le marquage « payé », le montant recalculé diverge, sans trace.
- **`penal_code` n'a aucune colonne de date** : aucune traçabilité des changements de tarif.
- **`audit_log` est en `general_ci`** alors que `users` est en `unicode_ci` : il est
  impossible de joindre le journal d'audit aux comptes en SQL. C'est probablement la raison
  pour laquelle `actor_name` est dénormalisé dans la table.
- **`saisies_api.php` n'appelle pas `mdt_require_module()`** (contrairement à `sd_api.php`)
  et renvoie un objet de permissions entièrement à `true` pour tout agent authentifié.
- **`maintenance_api.php?action=list` est volontairement non authentifié** : la liste des
  modules du site est énumérable anonymement.
- **Deux sources de vérité pour les rôles d'administration** : les identifiants sont définis à
  la fois dans `mdt_security.php` et dans `mdt_roles.php`.
- **`db_config.php` contient en clair le mot de passe de la base.** `login/config.php` contient
  en clair le secret client Discord et une clé de signature. Le fichier `bot/.env` contient le
  jeton du bot ; `scripts/` contient quatre fichiers de secrets supplémentaires. **Aucune de
  ces valeurs n'est reproduite ici. Toutes doivent être tournées lors de la reprise**, et
  sorties du dépôt.

---

## 6. Fichiers hors base

Une partie importante du patrimoine de l'application n'est pas dans MariaDB. Neuf
répertoires, **environ 2 Go**, dont la relation avec la base va du propre au totalement
délié. Tailles et comptages relevés le 27 septembre 2026.

### 6.1 Inventaire

| Répertoire | Taille | Fichiers | Table de référence | Colonne |
|---|---|---|---|---|
| `videos/` | **1,4 Go** | 3 275 | `upload_files` | `stored_name` |
| `downloads/` | **392 Mo** | 17 | **aucune** | — |
| `td/uploads/` | 95 Mo | 23 | `td_docs` (**table morte**) | `images`, `fichiers` (JSON) |
| `documents/uploads/` | 79 Mo | 25 | `doc_files` | `stored_name` |
| `cid/uploads/` | 13 Mo | 167 | `cid_*` | URL dans des colonnes texte et des JSON |
| `plainte/uploads/` | 7,3 Mo | 54 | `plainte_attachments` | `stored_name` |
| `npu/uploads/` | 1,4 Mo | 8 | `npu_places` | `photo` (chemin complet) |
| `messagerie/uploads/` | 316 Ko | 5 | `msg_attachments` | `stored_name` |
| `annonces/uploads/` | 168 Ko | 5 | `annonces` | `image`, `medias` (JSON) |

Total des sept répertoires `uploads/` : **≈ 196 Mo**.

### 6.2 Deux modèles de référencement, à ne pas confondre

**Modèle A — nom de fichier stocké dans une colonne dédiée.** Utilisé par `upload_files`,
`doc_files`, `msg_attachments`, `plainte_attachments`. La colonne s'appelle toujours
`stored_name`, elle est **`UNIQUE`**, et une colonne `original_name` conserve le nom
d'origine assaini. C'est le modèle propre : on peut réconcilier disque et base par une
simple comparaison d'ensembles.

**Modèle B — URL complète stockée dans du texte ou du JSON.** Utilisé par tout le module CID
(`cid_dossiers.logo_url`, `photo_qg_url`, `photo_carte_url`, `cid_hierarchie.photo_url`, et
surtout les tableaux JSON `images` de `cid_notes`, `cid_informations`, `cid_interrogatoires`,
`cid_telephones`, plus `cid_telephones.preuves[].medias[].url`), par `annonces.image` et
`annonces.medias`, par `npu_places.photo`, par `td_docs.images` / `fichiers`, et par les
`image_url` des barèmes `td_fl_*`. Ici **il n'existe aucune colonne indexable** : pour savoir
si un fichier est encore utilisé, il faut lire et parser tous les blobs JSON.

Conventions de nommage des fichiers générés, selon le module : `up_` (médiathèque), `dimg_` /
`dvid_` / `dpdf_` / `dbur_` (documentation), `cimg_` / `cvid_` (CID), `mimg_` (messagerie),
`att_` (plaintes), `tdd_` (ancienne documentation formation). Toutes comportent une partie
aléatoire hexadécimale.

### 6.3 Désynchronisation mesurée

J'ai comparé, pour chaque répertoire, l'ensemble des fichiers présents et l'ensemble des
noms référencés en base :

| Répertoire | Référencés en base | Sur le disque | Fichiers orphelins | Références cassées |
|---|---|---|---|---|
| `videos/` | 1 261 | 3 275 | **2 014** | 0 |
| `cid/uploads/` | 143 | 167 | 24 | **0** |
| `documents/uploads/` | 20 | 25 | 5 | 0 |
| `plainte/uploads/` | 53 | 54 | 1 | 0 |
| `annonces/uploads/` | 0 | 5 | 5 | 0 |
| `td/uploads/` | 19 (par une table morte) | 23 | 4 | 0 |
| `npu/uploads/` | 8 | 8 | 0 | 0 |
| `messagerie/uploads/` | 5 | 5 | 0 | 0 |

**Aucune référence cassée nulle part** — la base ne pointe jamais vers un fichier absent.
C'est le bon sens de la désynchronisation : l'application ne casse pas, elle accumule.

**Le cas de `videos/` est le plus lourd** : **2 014 fichiers sur 3 275 ne sont référencés par
aucune ligne**, soit l'essentiel des 1,4 Go. L'explication est dans le code : deux
producteurs écrivent dans le même répertoire.

- `upload_api.php` crée un fichier `up_<hex>.<ext>` **et** une ligne dans `upload_files`.
  La suppression, elle, fait bien `@unlink` puis `DELETE` — ce chemin est cohérent.
- `traitement.php` écrit dans le **même répertoire** des fichiers nommés
  `<hex>_<nom nettoyé>.<ext>`, **sans jamais créer de ligne en base**, et les supprime de même
  sans toucher à la base. Ce sont ces fichiers-là qui s'accumulent.

Un détail à connaître : malgré son nom, `videos/` contient très majoritairement des images
(3 129 PNG, 87 JPG, 53 WebP, pour seulement 3 MP4, 1 WebM et 1 GIF). C'est le stockage
générique de la médiathèque, pas un répertoire de vidéos.

### 6.4 Ce qui se passe quand disque et base divergent

**Fichier présent, ligne absente** — c'est le cas courant. Conséquence : de l'espace disque
consommé sans rien de visible. Aucun mécanisme de réconciliation n'existe, sauf un :
`documents/documents_api.php` expose une action `gc` qui purge les pièces jointes non
rattachées depuis plus de 24 h et les pièces soft-deleted depuis plus de 30 jours. **C'est
le seul ramasse-miettes de toute l'application**, et il ne voit que ce qui est en base : les
5 fichiers orphelins de `documents/uploads/` lui échappent, tout comme les images déposées par
le module Formation, qui écrit dans `documents/uploads/` **sans jamais créer de ligne dans
`doc_files`**.

**Ligne présente, fichier absent** — ne se produit pas aujourd'hui. Si cela arrivait, le
comportement varie : la documentation renvoie un 404 propre, les modules du modèle B
produisent une image cassée dans la page, sans erreur.

**Comportement à la suppression, par module :**

| Module | Supprimer la ligne efface-t-il le fichier ? |
|---|---|
| Médiathèque (`upload_files`) | **Oui** — `@unlink` avant le `DELETE` |
| Plaintes | **Oui** |
| Annonces | **Oui** à la suppression d'une annonce — **mais non** si une édition retire une image |
| Documentation | **Oui, en différé**, par le ramasse-miettes |
| CID | **Oui**, mais uniquement pour les fichiers sous `cid/uploads/`. Les médias hébergés dans `videos/` et référencés depuis un dossier CID ne sont **jamais** supprimés |
| Messagerie | **Non** — soft-delete uniquement (`deleted_at`), aucun `unlink` dans tout le fichier |
| NPU | **Non** — supprimer un point, ou remplacer sa photo, laisse le fichier |
| Formation | **Non** — les images de barème n'ont pas de ligne du tout |

### 6.5 Servir les fichiers : deux régimes de sécurité

- **Servis directement par le serveur web** : `videos/`, `npu/uploads/`, `plainte/uploads/`,
  `cid/uploads/`, `td/uploads/`. Quiconque connaît l'URL accède au fichier, sans contrôle.
- **Servis par le PHP avec contrôle d'accès**, puis délégués au serveur web par
  `X-Accel-Redirect` : les pièces jointes de la documentation (PDF et bureautique) et celles
  des annonces, ces dernières derrière une URL signée avec expiration.

C'est une **incohérence de sécurité réelle** : une pièce jointe de plainte ou une photo de
dossier d'enquête confidentiel est accessible sans authentification si son URL fuite, alors
qu'un PDF de la documentation publique est, lui, protégé.

### 6.6 Les deux répertoires sans table

- **`downloads/` (392 Mo, 17 fichiers)** : distribution de l'application de bureau et de
  l'overlay — exécutables, installeurs, archives de sources, plus un sous-dossier de mises à
  jour. Aucune table ne les référence, ils sont pointés par des liens en dur dans les pages
  HTML. À conserver si l'application de bureau est reprise, sinon c'est 392 Mo à libérer.
- **`td/uploads/` (95 Mo, 23 fichiers)** : les 19 fichiers référencés le sont par `td_docs`,
  qui est une **table morte** (§3.9). Le module Formation écrit désormais ses images dans
  `documents/uploads/`. **Ce répertoire est intégralement orphelin** : aucun code servi ne le
  lit ni ne l'écrit. C'est le gisement d'espace le plus facile à récupérer — après
  vérification que le contenu de `td_docs` n'a pas besoin d'être migré vers le module
  Documentation.

---

## 7. Schéma et migrations

### 7.1 La réponse franche

**Il n'existe aucun outil de migration, et le schéma n'a pas de source de vérité unique.**

Vérifications faites :

| Recherche | Résultat |
|---|---|
| Outil de migration (Phinx, Doctrine Migrations, Flyway, Liquibase, Knex, Sequelize, Alembic) | **aucune occurrence** dans le code applicatif |
| Répertoire `migrations/` | **aucun** |
| Table `schema_migrations`, `migrations`, `versions` | **aucune** parmi les 118 tables |
| Fichiers `.sql` dans tout le projet (hors dépendances tierces) | **6**, tous datés et joués une seule fois |
| `composer.json` / gestionnaire de dépendances PHP | **aucun** |

Le schéma de production a été **construit à la main, puis a dérivé**. Il n'existe pas de
fichier dont on pourrait dire : « ceci est le schéma de RP MDT ». Le seul état autoritaire est
la base elle-même.

### 7.2 Ce qui tient lieu de migrations : du DDL exécuté à chaud

Le schéma est créé et modifié **par le code applicatif, au fil des requêtes HTTP ordinaires**.
On compte **110 `CREATE TABLE IF NOT EXISTS` et 52 `ALTER TABLE` répartis dans 16 fichiers
PHP**. Trois mécanismes coexistent :

**a) Le bootstrap global, dans `db_config.php`** (inclus par toutes les API).
Il crée une trentaine de tables, applique des `ALTER` conditionnels
(« si la colonne `saisies.arme_accessoires` n'existe pas, l'ajouter »), ajoute des index
manquants, et insère des jeux de données par défaut (statuts de patrouille, boutons
« code 10-XX »). Il est protégé par un fichier-drapeau dans le répertoire temporaire du
système, revérifié **toutes les heures**. Une helper `mdt_auto_fix()` applique chaque
correction et écrit le résultat dans le journal d'erreurs PHP.

Le tableau `$required_columns` de ce fichier est, de fait, **le seul registre de migrations
de l'application** : une liste `[table, colonne, SQL d'ALTER]` à compléter à la main quand on
ajoute une colonne.

**b) Un versionnage par module, en clé/valeur.** Cinq modules maintiennent leur propre DDL,
gardé par une constante de version comparée à une ligne en base :

| Module | Constante PHP | Emplacement de la version | Valeur en base |
|---|---|---|---|
| Dispatch | `DP_DDL_VERSION` | `dispatch_meta.mk = 'ddl_version'` | `2026-08-02.2` |
| Messagerie | `MSG_DDL_VERSION` | `msg_meta.mk = 'ddl_version'` | `2026-08-11.1` |
| Recherche | `RCH_DDL_VERSION` | `rch_meta.mk = 'ddl_version'` | `2026-08-11.3` |
| Carte | `TAC_DDL_VERSION` | `tac_meta.mk = 'ddl_version'` | `2026-08-04.4` |
| NPU | `NPU_DDL_VERSION` | `npu_config.mk = '__ddl'` | `2026-08-23.1` |

Si la version en base correspond à la constante, le bloc DDL est sauté. **Sinon il est rejoué
intégralement à la première requête reçue.** Pour livrer un changement de schéma sur l'un de
ces modules, il faut donc incrémenter la constante — c'est écrit en commentaire dans
`dispatch/dispatch_api.php`, mais nulle part ailleurs.

Deux variantes s'ajoutent, avec des conventions différentes : `doc_meta.k = 'schema_version'`
(le module Documentation **ne crée rien** et renvoie une erreur 503 si la version ne
correspond pas — c'est le comportement le plus sain de la base) et `cid_meta`, qui utilise de
simples drapeaux booléens (`perms_seeded`, `types_seeded`, `perms_split_v2`).

**c) Du DDL non gardé du tout.** Deux `CREATE TABLE IF NOT EXISTS dispatch_salaire_paye` sont
exécutés à chaque appel des actions de paie, sans aucune garde de version.

**Conséquences pour un repreneur :**

- Les `CREATE TABLE` du code ne décrivent **pas** le schéma réel. Ils décrivent un état
  initial, complété ensuite par des `ALTER` dispersés. Exemple vérifié : le `CREATE TABLE
  cid_dossiers` du module CID ne contient ni `statut_id` ni `archived_at`.
- **Rejouer ce code sur une base vide ne reproduirait pas la production.** Plusieurs colonnes
  n'existent que parce qu'un `ALTER` a été passé à la main puis retiré du code, ou parce qu'un
  script de bascule les a créées.
- Les erreurs de DDL sont attrapées par des `catch (PDOException) {}` silencieux : une
  migration qui échoue ne se voit pas.
- Comme le DDL s'exécute au premier appel après un déploiement, **la première requête d'un
  utilisateur peut déclencher un `ALTER TABLE` sur une table de production**.

### 7.3 Les 6 fichiers `.sql` du projet

Tous sont des instantanés datés, joués une seule fois. Aucun n'est rejouable comme source de
vérité.

| Fichier | Date | Rôle | État vis-à-vis de la base |
|---|---|---|---|
| `bot/setup_db.sql` | avr. 2026 | Crée `module_permissions` et `discord_roles_cache` | **Conforme** |
| `bot/setup_db_v2.sql` | avr. 2026 | Crée `dispatch_action_buttons`, `dispatch_services`, `dispatch_dispatchers` + 4 `ALTER` + le jeu de boutons par défaut | **A divergé** — voir ci-dessous |
| `scripts/documents_schema.sql` | 22/07/2026 | Crée les 9 tables du module Documentation ; en-tête : « appliqué une fois » sur la base de développement | Les 9 tables existent ; la base a en plus `doc_hierarchie`, absente du fichier |
| `scripts/cutover/10_schema.sql` | 26/07/2026 | Schéma de bascule : 15 tables (`cid_replies`, `cid_types_groupe`, les 10 `doc_*`, `suspects`, `td_epreuve_categories`, `td_notation_criteria`) | Toutes existent |
| `scripts/cutover/20_data.sql` | 26/07/2026 | Migration de données ciblée + un `ALTER … ADD UNIQUE uq_notif_ref` sur `notifications` | Joué |
| `scripts/migrations-cutover.sql` | 02/08, joué le 03/08/2026 | Différentiel `information_schema` entre développement et production : crée 15 tables (`audit_log`, les 7 `msg_*`, `role_config`, les 4 `sup_*`, `upload_files`, `user_presence`) | Les 15 existent |

**`bot/setup_db_v2.sql` est trompeur et mérite un avertissement** : rejoué sur une base vide,
il ne reproduirait pas le comportement actuel.

- `dispatch_services` : la base a **8 colonnes de plus** (`last_seen`, `last_afk_check`,
  `last_active`, `afk_flag`, `check_pending_since`, `auto_close_reason`, `auto_close_acked`,
  `annule`) et un index supplémentaire.
- `dispatch_action_buttons` : une colonne de plus (`special_action`).
- `dispatch_dispatchers` : **divergence structurelle** — l'ENUM `role` a gagné la valeur
  `supervision`, et la clé unique est `(role, roster_id)` en base là où le fichier déclare
  `(role)` seul.

### 7.4 Scripts planifiés qui touchent la base

Quatre tâches, dans la crontab de l'utilisateur système (rien dans la crontab `root`, rien
dans `/etc/cron.d`, aucun timer systemd) :

| Horaire | Script | Effet sur la base |
|---|---|---|
| Toutes les heures (mn 7) | `scripts/suspects_sync.php` | `INSERT … ON DUPLICATE KEY` dans `suspects` |
| Toutes les heures (mn 0) | `scripts/cutover/50_suspects_sync_prod.php` | **Écrit la même table `suspects`**, sans journalisation |
| Toutes les 15 minutes | script de synchronisation des rôles | Met à jour `discord_roles_cache`, purge les rôles supprimés |
| 04h00 | `scripts/purge-journaux.php` | `DELETE` à 30 jours sur `security_events`, `audit_log`, `dispatch_logs` |

**Anomalie** : les deux premières sont des doublons quasi identiques. Le script de bascule
`50_suspects_sync_prod.php` devait être temporaire ; il tourne toujours, en parallèle du
script nominal, et écrit la même table sans laisser de trace.

### 7.5 Sauvegardes — le point faible

**Il n'existe aucune sauvegarde automatisée de la base.**

- Le script de déploiement sauvegarde **les fichiers** (copie par liens durs, rotation à 20
  générations), **pas la base**.
- `scripts/cutover/00_backup.sh` fait bien un `mysqldump --single-transaction` compressé, mais
  il est **manuel** et n'a jamais été planifié.
- Les seuls dumps existants sont trois sauvegardes manuelles, dans un répertoire `backups/` :
  une d'avant bascule (26/07/2026), une de bascule (03/08/2026), et une d'avant la mise en
  service du module Carte (**11/08/2026**).

**Le dump le plus récent de la base de production date donc du 11 août 2026, soit environ
six semaines.** C'est, avec le point sur `roster.id` (§4.3), le sujet le plus urgent de la
reprise.

À noter également un fichier de dump de 20 octets dans le même répertoire : un export vide ou
échoué, qui donne une fausse impression de sauvegarde.

### 7.6 Que faire en premier

Par ordre de priorité, du point de vue du modèle de données :

1. **Mettre en place un dump quotidien** de la base, vérifié (contrôler la taille du fichier
   produit, pas seulement le code de retour).
2. **Figer le schéma réel** : produire un `mysqldump --no-data` de la production, le versionner,
   et en faire la référence — c'est aujourd'hui le seul document fiable qui puisse exister.
3. **Migrer les clés du dispatch de `roster.id` vers `roster.matricule`**, ou au minimum cesser
   de vider `roster` (un `UPSERT` sur `matricule` suffirait), pour arrêter l'hémorragie.
4. **Uniformiser les collations** sur `utf8mb4_unicode_ci` pour les 20 tables concernées, ce
   qui débloquera les jointures aujourd'hui impossibles.
5. **Sortir le DDL des API de requête** et le remplacer par un vrai mécanisme de migration
   versionnée, même minimal (un répertoire de fichiers numérotés et une table de suivi).
6. **Tourner tous les secrets** et les sortir du dépôt.
7. Ajouter les index listés en §5.3 et supprimer les quatre index inutiles.

---

## Annexe — méthode et limites

Toutes les affirmations de ce document proviennent de deux sources, et d'aucune autre :

- la base de production `rp_mdt`, interrogée en **lecture seule** (`SELECT`, `SHOW`,
  `information_schema`, `EXPLAIN`) le 27 septembre 2026 ;
- le code de production : 34 fichiers `.php`, les fichiers `.html` et `.js` associés, le bot
  Discord et les scripts d'exploitation.

Aucune écriture n'a été faite, ni en base ni sur les fichiers du projet.

**Ce qui n'a pas pu être établi avec certitude, et qui est signalé comme tel dans le
document :**

- l'origine des 58 sessions d'une durée d'un an créées les 3 et 4 août 2026 (§5.7) ;
- la structure du contenu de `td_notations`, table dont le code ne lit jamais les données
  (§3.9) ;
- si le contenu de `td_docs` (12 lignes, table morte) doit être migré vers le module
  Documentation ou peut être supprimé (§6.6) ;
- l'écart entre le compteur `AUTO_INCREMENT` de `security_events` et son nombre de lignes,
  qui suggère des suppressions massives passées non tracées dans le code actuel.

Les volumes cités sont ceux d'un instant précis. Les tables de journal et de dispatch évoluent
en continu : `audit_log` gagne environ 2 700 lignes par jour, et les tables transitoires du
dispatch se remplissent et se vident au rythme des prises de service.
