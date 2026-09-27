# RP MDT

Terminal de données mobile (MDT) pour un serveur de jeu de rôle FiveM.
Application web PHP, authentification Discord OAuth2, droits adossés aux rôles
Discord. Modules : fiches d'individus, dossiers d'enquête, documents, plaintes,
dispatch, messagerie, code pénal, carte tactique, saisies, examens, annonces.

## Structure

| Dossier | Contenu |
| --- | --- |
| `web/` | L'application : 34 fichiers PHP, 31 pages HTML, 39 scripts JS |
| `bot/` | Bot Discord (Node.js) : cache des rôles, alertes, API interne |
| `apps/launcher/` | Lanceur natif (Electron) |
| `apps/overlay/` | Surcouche dispatch en jeu (Electron) |
| `apps/overwolf/` | Variante Overwolf de la surcouche |

Le code tiers vendorisé (`web/assets/vendor/` : Leaflet, Socket.IO, Tailwind ;
`web/assets/fontawesome/`) est laissé tel quel.

## Prérequis

PHP 8.3 avec PDO MySQL, MariaDB ou MySQL, Node.js 20+ pour le bot, un serveur
web servant `web/` comme racine.

## Installation

```bash
mysql -u root -e "CREATE DATABASE rp_mdt CHARACTER SET utf8mb4;"
mysql -u root rp_mdt < base-rp-mdt_AAAAMMJJ.sql
```

Puis renseigner la configuration :

| Fichier | Valeurs à remplir |
| --- | --- |
| `web/db_config.php` | `$db_host`, `$db_name`, `$db_user`, `$db_pass` |
| `web/login/config.php` | `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_GUILD_ID`, `DISCORD_REDIRECT_URI`, `JWT_SECRET_KEY` |
| `bot/.env` | copier depuis `bot/.env.example` |

Toutes les valeurs livrées sont des marqueurs `REMPLACER_*`. L'application ne
démarre pas tant qu'elles ne sont pas renseignées.

```bash
cd bot && npm install && node bot.js
```

## Points d'attention

**Les identifiants de rôles Discord sont codés en dur** dans
`web/mdt_roles.php` (`MDT_ROLE_CHIEF`, `MDT_ROLE_ETAT_MAJOR`, etc.). Ce sont les
rôles de l'ancien serveur : ils doivent être remplacés par ceux du vôtre, sinon
personne n'obtient de droits. Les rôles sont comparés par identifiant, jamais
par nom — renommer un rôle côté Discord est sans effet.

**Le schéma est créé par le code, pas par des migrations.** Plusieurs fichiers
exécutent des `CREATE TABLE IF NOT EXISTS` au premier appel (voir
`web/db_config.php` et `web/login/permissions_api.php`). Il n'existe aucun
outil de migration : le dump SQL fourni est la référence du schéma.

**Les routes dépendent de la configuration du serveur web.** Chaque module est
un répertoire servi par une règle dédiée. Le module principal est
`web/mdt/` : adaptez la règle correspondante.

**Les répertoires `uploads/` ne sont pas livrés.** Sept modules stockent des
fichiers sur disque (annonces, messagerie, npu, plainte, cid, documents, td) et
les référencent en base. Sans eux, les enregistrements pointent dans le vide.
Le serveur web doit interdire l'exécution de PHP dans ces répertoires.

**Aucune rétention sur les journaux.** `audit_log` dépasse 80 000 lignes et
n'est jamais purgé.

Voir `modele-de-donnees.md` pour le détail des 118 tables.
