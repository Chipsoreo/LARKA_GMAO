<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Aide intégrée : des fiches « comment faire… ? » propres au rôle,
 * consultées et recherchées dans la page (aucun fichier à télécharger).
 *
 *   GET aide&langue=fr|en[&console=1]
 *
 * Le serveur ne renvoie que les fiches du rôle de l'utilisateur
 * (api/AideFiches.php). L'exploitant du serveur — Super Admin, ou gestionnaire
 * d'une installation simple — reçoit en plus les fiches de SON installation,
 * construites ici d'après ce que Larka voit de lui-même : PHP intégré lancé par
 * Larka.cmd, par start.sh ou par systemd ; PHP-FPM derrière Nginx ; système.
 * Pas de commande Linux sous Windows, pas de Larka.cmd sous Linux.
 */

/** Système, mode de fonctionnement, PHP — ce que Larka voit de lui-même. */
function aide_environnement(): array
{
    $racine = dirname(__DIR__, 2);
    $win = PHP_OS_FAMILY === 'Windows';
    if ($win) {
        $build = preg_match('/build (\d+)/i', php_uname('v'), $m) ? (int)$m[1] : 0;
        $os = ($build >= 22000 ? 'Windows 11' : 'Windows 10') . ($build ? " (build $build)" : '');
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $os = 'macOS ' . php_uname('r');
    } else {
        $os = 'Linux';
        $rel = @parse_ini_file('/etc/os-release');
        if (is_array($rel) && !empty($rel['PRETTY_NAME'])) $os = (string)$rel['PRETTY_NAME'];
    }

    $sapi = PHP_SAPI;
    $serveurWeb = '';
    if ($sapi === 'cli-server') {
        $unite = '/etc/systemd/system/larka.service';
        if ($win) {
            $mode = 'windows';
        } elseif (is_file($unite) && str_contains((string)@file_get_contents($unite), 'WorkingDirectory=' . $racine)) {
            $mode = 'systemd';
        } elseif (is_file($racine . '/data/run/larka-windows.json')) {
            $mode = 'lanceur';
        } else {
            $mode = 'startsh';
        }
    } else {
        $logiciel = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        $mode = 'production';
        $serveurWeb = stripos($logiciel, 'nginx') !== false ? 'Nginx' : (stripos($logiciel, 'apache') !== false ? 'Apache' : ($logiciel ?: ''));
    }
    return ['os' => $os, 'windows' => $win, 'mode' => $mode, 'serveur_web' => $serveurWeb,
            'php' => PHP_VERSION, 'sapi' => $sapi, 'fuseau' => date_default_timezone_get(), 'racine' => $racine];
}

/** Libellé du mode de fonctionnement. */
function aide_libelle_mode(array $env, bool $en): string
{
    switch ($env['mode']) {
        case 'windows': return $en ? 'Windows — Larka.cmd launcher (built-in PHP server)' : 'Windows — lanceur Larka.cmd (serveur PHP intégré)';
        case 'systemd': return $en ? 'Built-in PHP server — systemd service (start at boot)' : 'Serveur PHP intégré — service systemd (démarrage automatique)';
        case 'lanceur': return $en ? 'Built-in PHP server — deploy/windows/larka.php launcher' : 'Serveur PHP intégré — lanceur deploy/windows/larka.php';
        case 'startsh': return $en ? 'Built-in PHP server — ./start.sh' : 'Serveur PHP intégré — ./start.sh';
    }
    $web = $env['serveur_web'] ?: ($en ? 'web server' : 'serveur web');
    return 'Production — ' . $web . ' + PHP ' . ($env['sapi'] === 'fpm-fcgi' ? 'FPM' : $env['sapi']);
}

/** Groupes de fiches d'un utilisateur (voir api/AideFiches.php). */
function aide_groupes(string $role, bool $sa, bool $console, bool $multi): array
{
    // Super Admin dans sa console : ses fiches. Entré dans une organisation
    // (bouton « Accéder »), il y agit en gestionnaire : celles du gestionnaire.
    if ($sa && $console) return ['sa', 'exploitant'];
    if ($sa) return ['gestionnaire', 'exploitant'];
    switch ($role) {
        case 'Demandeur':  return ['demandeur'];
        case 'Visionneur':
        case 'Technicien': return ['visionneur'];
        case 'Admin':
        case 'Gestionnaire':
            // Installation simple : l'administrateur est aussi celui qui l'exploite.
            return $multi ? ['gestionnaire'] : ['gestionnaire', 'exploitant'];
    }
    return [];
}

/** Libellé du profil, pour l'en-tête de la page. */
function aide_libelle_role(string $role, bool $sa, bool $console, bool $en): string
{
    if ($sa && $console) return 'Super Admin';
    $noms = $en
        ? ['Demandeur' => 'Requester', 'Visionneur' => 'Viewer', 'Technicien' => 'Technician', 'Gestionnaire' => 'Manager', 'Admin' => 'Administrator']
        : ['Demandeur' => 'Demandeur', 'Visionneur' => 'Visionneur', 'Technicien' => 'Technicien', 'Gestionnaire' => 'Gestionnaire', 'Admin' => 'Administrateur'];
    return $sa ? ($noms['Gestionnaire'] . ' (Super Admin)') : ($noms[$role] ?? $role);
}

/** Bas de page « Vous ne trouvez pas ? » : à qui s'adresser, selon le rôle. */
function aide_pied(array $groupes, bool $multi, bool $en): string
{
    $doc = $en ? "The README and the technical architecture file (Documentations/DAT-Larka.docx) shipped with Larka cover installation and operation in detail."
               : "Le README et le dossier d'architecture technique (Documentations/DAT-Larka.docx) livrés avec Larka détaillent l'installation et l'exploitation.";
    if (in_array('sa', $groupes, true)) return $doc;
    if (in_array('gestionnaire', $groupes, true)) {
        return $multi ? ($en ? 'For the platform itself, contact its administrator (Super Admin).' : 'Pour la plateforme elle-même, adressez-vous à son administrateur (Super Admin).')
                      : $doc;
    }
    return $en ? "Contact your organisation's Larka manager." : 'Rapprochez-vous du gestionnaire Larka de votre organisation.';
}

/** Fiches communes du rôle, dans la langue demandée. */
function aide_fiches(array $groupes, bool $multi, string $langue): array
{
    $contenu = require __DIR__ . '/../AideFiches.php';
    $out = [];
    foreach ($contenu['fiches'] as $f) {
        if (!array_intersect($f['pour'], $groupes)) continue;
        $si = (string)($f['si'] ?? '');
        if (($si === 'mono' && $multi) || ($si === 'multi' && !$multi)) continue;
        $t = $f[$langue] ?? $f['fr'];
        $out[] = array_filter([
            'id' => $f['id'], 'cat' => $f['cat'],
            'page' => $f['page'] ?? null, 'action' => $f['action'] ?? null,
            // Conditions que seul le navigateur peut juger (assistant actif, onglets ouverts)
            'si' => in_array($si, ['assistant', 'consultation'], true) ? $si : null,
        ], fn($v) => $v !== null) + $t;
    }
    return [$out, $contenu['categories']];
}

/** Rapport des prérequis en anglais (les messages de LarkaPrerequis sont en français). */
function aide_prerequis_en(array $points): array
{
    $usages = [
        'accès aux bases' => 'database access', 'textes accentués' => 'accented text', 'contrôle des saisies' => 'input checks',
        'chiffrement des secrets' => 'secret encryption', 'connexion' => 'sign-in', 'type des documents déposés' => 'uploaded file types',
        'mises à jour, OAuth, facteurs carbone' => 'updates, OAuth, carbon factors', 'modules, thèmes, mises à jour' => 'modules, themes, updates',
        'signature des mises à jour' => 'update signatures', 'recherche sans accents' => 'accent-insensitive search',
        'import de fichiers XML' => 'XML file import', 'images' => 'images',
    ];
    $libelles = [
        'Extensions PHP indispensables' => 'Required PHP extensions', 'Extensions PHP conseillées' => 'Recommended PHP extensions',
        "Droits d'écriture" => 'Write permissions', 'Espace disque' => 'Disk space', 'Fichier .env' => '.env file',
        'Serveur de base de données' => 'Database server',
    ];
    $regles = [
        '/^Larka demande (\S+) (\S+) ou plus récent\.$/u' => 'Larka needs $1 $2 or later.',
        '/^Installez PHP (\S+) ou plus récent, puis relancez\.$/u' => 'Install PHP $1 or later, then restart.',
        "/^Plus récent que les versions éprouvées \\(jusqu'à (\\S+)\\)\\.$/u" => 'Newer than the tested versions (up to $1).',
        '/^Larka devrait fonctionner ; signalez toute anomalie\.$/u' => 'Larka should work; report any problem.',
        '/^Plus de correctifs de sécurité depuis le (\S+)\.$/u' => 'No security fixes since $1.',
        '/^Prévoyez de passer à une version de PHP encore suivie\.$/u' => 'Plan a move to a PHP version that is still supported.',
        "/^Suivi jusqu'au (\\S+)\\.$/u" => 'Supported until $1.',
        '/^Installez-les \(paquets php-…\), puis redémarrez PHP\.$/u' => 'Install them (php-… packages), then restart PHP.',
        '/^Toutes présentes\.$/u' => 'All present.',
        "/^Les fonctions citées restent indisponibles tant qu'elles manquent\\.$/u" => 'The features listed stay unavailable while they are missing.',
        '/^Mettez PHP à jour \(il embarque sa bibliothèque SQLite\)\.$/u' => 'Update PHP (it bundles its SQLite library).',
        '/^Sauvegarde à chaud impossible avant SQLite (\S+) \(VACUUM INTO\)\.$/u' => 'Live backup impossible before SQLite $1 (VACUUM INTO).',
        '/^Mettez PHP à jour\.$/u' => 'Update PHP.',
        '/^Dossier\(s\) non inscriptible\(s\) par PHP : (.*)\.$/u' => 'Folder(s) PHP cannot write to: $1.',
        '/^data\/ inscriptible\.$/u' => 'data/ is writable.',
        '/^Donnez ces dossiers au compte qui exécute PHP \(chown\)\.$/u' => 'Give these folders to the account running PHP (chown).',
        '/^([\d  ]+) Mo libres\.$/u' => '$1 MB free.',
        '/^Les sauvegardes et les mises à jour ont besoin de place \(500 Mo conseillés\)\.$/u' => 'Backups and updates need space (500 MB recommended).',
        '/^Lisible par tous les comptes du système\.$/u' => 'Readable by every account on the system.',
        "/^Version non vérifiée : connexion impossible pour l'instant\\.$/u" => 'Version not checked: no connection for now.',
        '/^Mettez PostgreSQL à jour \(sauvegarde puis pg_upgrade\)\.$/u' => 'Update PostgreSQL (backup, then pg_upgrade).',
        '/^Prévoyez une mise à jour de PostgreSQL\.$/u' => 'Plan a PostgreSQL update.',
        '/^Versions éprouvées : (\S+) (\S+) ou plus récent\.$/u' => 'Tested versions: $1 $2 or later.',
        '/^Mettez (\S+) à jour\.$/u' => 'Update $1.',
    ];
    foreach ($points as &$p) {
        $p['libelle'] = $libelles[$p['libelle']] ?? $p['libelle'];
        foreach (['detail', 'conseil'] as $k) {
            $t = (string)$p[$k];
            if ($t === '') continue;
            if (preg_match('/^Absente\(s\) : (.*)\.$/u', $t, $m)) {
                $t = 'Missing: ' . preg_replace_callback('/\(([^)]*)\)/u', function ($u) use ($usages) {
                    $v = $u[1];
                    if (preg_match('/^base (\w+)$/u', $v, $b)) return '(' . $b[1] . ' database)';
                    return '(' . ($usages[$v] ?? $v) . ')';
                }, $m[1]) . '.';
            } else {
                foreach ($regles as $motif => $rempl) {
                    $n = preg_replace($motif, $rempl, $t, 1, $nb);
                    if ($nb) { $t = $n; break; }
                }
            }
            $p[$k] = $t;
        }
    }
    unset($p);
    return $points;
}

/**
 * Fiches de l'exploitant, propres à CETTE installation. Les commandes sont des
 * chaînes affichées telles quelles (jamais exécutées).
 */
function aide_fiches_serveur(array $env, bool $multi, bool $en, ?array $pre, ?array $sauv, string $base): array
{
    $m = $env['mode'];
    $r = $env['racine'];
    $win = $m === 'windows';
    $prod = $m === 'production';
    $L = fn(string $fr, string $anglais) => $en ? $anglais : $fr;
    $fpm = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm';
    $f = [];

    // Caractéristiques
    $etatSauv = !$sauv ? $L('État indisponible.', 'Status unavailable.')
        : ($sauv['actives'] === 0
            ? $L('Désactivées', 'Disabled') . ($multi ? $L(' (réglées par organisation dans le Super Admin)', ' (set per organisation in the Super Admin)') : '')
            : ($sauv['planificateur_actif']
                ? $L('Actives', 'Enabled') . " ({$sauv['actives']}) — " . $L('planificateur en marche', 'scheduler running')
                : $L('Activées, mais le planificateur ne tourne pas', 'Enabled, but the scheduler is not running'))
              . ($sauv['en_retard'] ? ' — ⚠ ' . $sauv['en_retard'] . ' ' . $L('en retard ou en échec', 'late or failed') : ''));
    $tableau = [
        [$L('Système', 'System'), $env['os']],
        [$L('Fonctionnement', 'Running mode'), aide_libelle_mode($env, $en)],
        ['PHP', $env['php'] . ' (' . $env['sapi'] . ')'],
        [$L('Base de données', 'Database'), $base],
        [$L('Organisations', 'Organisations'), $multi ? $L('Plusieurs (multi-tenant)', 'Several (multi-tenant)') : $L('Installation simple', 'Single installation')],
        [$L('Fuseau horaire', 'Time zone'), $env['fuseau']],
        [$L('Dossier', 'Folder'), $r],
        [$L('Sauvegardes automatiques', 'Automatic backups'), $etatSauv],
    ];
    $f[] = ['id' => 'srv-installation', 'titre' => $L('Caractéristiques de cette installation', 'This installation at a glance'),
            'mots' => $L('installation système version php base dossier fuseau horaire caractéristiques serveur configuration', 'installation system version php database folder time zone server configuration'),
            'texte' => $L('Ce que Larka voit de lui-même :', 'What Larka sees of itself:'), 'tableau' => $tableau];

    // Démarrer, arrêter
    if ($win) {
        $f[] = ['id' => 'srv-demarrer', 'titre' => $L('Démarrer, arrêter Larka', 'Start, stop Larka'),
                'mots' => $L('démarrer lancer arrêter redémarrer relancer stopper fermer serveur larka.cmd fenêtre service', 'start launch stop restart close server larka.cmd window service'),
                'texte' => $L("Double-cliquez sur **Larka.cmd** dans le dossier de Larka. Gardez sa fenêtre ouverte (réduite) : la fermer arrête Larka.", "Double-click **Larka.cmd** in Larka's folder. Keep its window open (minimised): closing it stops Larka."),
                'code' => "Larka.cmd\nLarka.cmd arreter\nLarka.cmd etat",
                'note' => $L('Démarrer avec Windows : `Larka.cmd raccourci`, puis copiez le raccourci « Larka » dans le dossier de démarrage (Win+R, shell:startup).', 'Start with Windows: `Larka.cmd raccourci`, then copy the “Larka” shortcut into the startup folder (Win+R, shell:startup).')];
        $f[] = ['id' => 'srv-reseau', 'titre' => $L('Rendre Larka accessible aux autres postes', 'Make Larka reachable from other computers'),
                'mots' => $L('réseau autres postes accès distant adresse ip port pare-feu partager collègues', 'network other computers remote access ip address port firewall share colleagues'),
                'texte' => $L("Par défaut, Larka ne répond qu'à ce poste.", 'By default Larka only answers this computer.'),
                'code' => "Larka.cmd demarrer --reseau\nLarka.cmd demarrer --local\nLarka.cmd demarrer --port 8080",
                'note' => $L("L'adresse à donner aux collègues s'affiche ; autorisez « php » dans le pare-feu Windows (réseaux privés). Le choix est mémorisé.", 'The address to give colleagues is shown; allow “php” in the Windows firewall (private networks). The choice is remembered.')];
    } elseif ($prod) {
        $f[] = ['id' => 'srv-demarrer', 'titre' => $L('Redémarrer Larka (production)', 'Restart Larka (production)'),
                'mots' => $L('démarrer redémarrer relancer arrêter serveur service nginx php-fpm postgresql état systemctl', 'start restart stop server service nginx php-fpm postgresql status systemctl'),
                'texte' => $L('Larka est servi par Nginx et PHP-FPM, démarrés au boot.', 'Larka is served by Nginx and PHP-FPM, started at boot.'),
                'code' => "sudo systemctl restart $fpm\nsudo systemctl reload nginx\nsystemctl status nginx $fpm postgresql",
                'note' => $L('Depuis le dossier source de Larka, `sudo ./start.sh prod-restart` fait la même chose, puis vérifie que le site répond.', "From Larka's source folder, `sudo ./start.sh prod-restart` does the same, then checks that the site answers.")];
    } else {
        $sysd = $m === 'systemd';
        $f[] = ['id' => 'srv-demarrer', 'titre' => $L('Démarrer, arrêter Larka', 'Start, stop Larka'),
                'mots' => $L('démarrer lancer arrêter redémarrer relancer stopper serveur start.sh service démon arrière-plan', 'start launch stop restart server start.sh service daemon background'),
                'texte' => $sysd
                    ? $L('Larka tourne comme service systemd (larka.service) : il démarre au boot et redémarre en cas d\'arrêt anormal. Depuis le dossier de Larka :', 'Larka runs as a systemd service (larka.service): it starts at boot and restarts after an abnormal stop. From Larka\'s folder:')
                    : $L('Depuis le dossier de Larka :', "From Larka's folder:"),
                'code' => $sysd ? "./start.sh restart\n./start.sh stop\n./start.sh status" : "./start.sh start -d\n./start.sh stop\n./start.sh status",
                'note' => $sysd
                    ? $L('Ne plus démarrer au boot : `sudo ./start.sh autostart off`.', 'Stop starting at boot: `sudo ./start.sh autostart off`.')
                    : $L('Sans `-d`, Larka reste au premier plan (Ctrl+C pour l\'arrêter).', 'Without `-d`, Larka stays in the foreground (Ctrl+C to stop it).')];
        if (!$sysd) {
            $f[] = ['id' => 'srv-autostart', 'titre' => $L('Démarrer Larka automatiquement au boot', 'Start Larka automatically at boot'),
                    'mots' => $L('démarrage automatique boot redémarrage serveur service systemd autostart', 'automatic start boot server restart service systemd autostart'),
                    'texte' => $L('Installe le service larka.service (redémarre en cas d\'arrêt anormal) et la minuterie des sauvegardes automatiques.', 'Installs the larka.service service (restarts after an abnormal stop) and the automatic backups timer.'),
                    'code' => 'sudo ./start.sh autostart on',
                    'note' => $L('À ne pas faire sur un serveur déjà en production Nginx + PHP-FPM : le script le détecte et demande confirmation.', 'Do not do this on a server already in Nginx + PHP-FPM production: the script detects it and asks for confirmation.')];
        }
    }

    // Diagnostic et prérequis (rapport en direct)
    $diag = ['id' => 'srv-diagnostic', 'titre' => $L('Vérifier le serveur (diagnostic, prérequis)', 'Check the server (diagnostics, prerequisites)'),
             'mots' => $L('diagnostic prérequis vérifier panne problème erreur php extension version compatible doctor réparer', 'diagnostics prerequisites check failure problem error php extension version compatible doctor repair'),
             'texte' => $L('Rapport des prérequis de cette version, vérifiés à l\'instant :', 'Report on this version\'s prerequisites, checked just now:')];
    if ($pre) {
        $n = (int)$pre['bloquants'];
        $w = (int)$pre['avertissements'];
        $bilan = !$pre['ok'] ? $L("$n prérequis bloquant(s), à corriger :", "$n blocking prerequisite(s) to fix:")
            : ($w ? $L("Prérequis satisfaits, avec $w avertissement(s) :", "Prerequisites met, with $w warning(s):")
                  : $L('Tous les prérequis de cette version sont satisfaits.', "All of this version's prerequisites are met."));
        $diag['rapport'] = ['ok' => $pre['ok'], 'bloquants' => $n, 'avertissements' => $w, 'bilan' => $bilan,
                            'points' => $en ? aide_prerequis_en($pre['points']) : $pre['points']];
    }
    if ($win) {
        $diag['code'] = 'Larka.cmd diagnostic';
        $diag['note'] = $L('Le diagnostic complet ajoute les certificats, la configuration, l\'intégrité de la base et la fin des journaux.', 'The full diagnostics add certificates, configuration, database integrity and the end of the logs.');
    } elseif ($prod) {
        $diag['code'] = "sudo -u www-data php $r/api/outils/prerequis.php";
        $diag['note'] = $L('Depuis le dossier source : `./start.sh doctor` (et `--fix` pour réparer ce qui peut l\'être).', 'From the source folder: `./start.sh doctor` (and `--fix` to repair what can be repaired).');
    } else {
        $diag['code'] = "./start.sh doctor\n./start.sh doctor --fix";
        $diag['note'] = $L('Un prérequis bloquant arrête le démarrage avec le code GMAO-E13 et la marche à suivre.', 'A blocking prerequisite stops start-up with code GMAO-E13 and what to do.');
    }
    $f[] = $diag;

    // Sauvegardes
    $declencheur = $win ? $L('le lanceur Larka.cmd, tant que Larka tourne', 'the Larka.cmd launcher, while Larka runs')
        : ($prod ? $L('une tâche cron (/etc/cron.d/gmao)', 'a cron job (/etc/cron.d/gmao)')
        : ($m === 'systemd' ? $L('la minuterie systemd larka-sauvegardes.timer', 'the larka-sauvegardes.timer systemd timer')
        : $L('le planificateur lancé avec ./start.sh start', 'the scheduler started with ./start.sh start')));
    $f[] = ['id' => 'srv-sauvegardes', 'titre' => $L('Sauvegardes : où, quand, comment', 'Backups: where, when, how'),
            'mots' => $L('sauvegarde sauvegardes backup où dossier planificateur restaurer copie forcer état', 'backup backups where folder scheduler restore copy force status'),
            'texte' => $L("Sur cette installation, les sauvegardes dues sont lancées toutes les 10 minutes par $declencheur.", "On this installation, due backups are started every 10 minutes by $declencheur.")
                     . ' ' . $L('État :', 'Status:') . ' ' . $etatSauv,
            'etapes' => [
                $multi ? $L('Réglages : Super Admin → fiche de chaque organisation → **Sauvegardes**.', 'Settings: Super Admin → each organisation\'s record → **Backups**.')
                       : $L('Réglages : **Configuration → 🖥️ Serveur → Sauvegardes automatiques**.', 'Settings: **Settings → 🖥️ Server → Automatic backups**.'),
                $L('Fichiers : dossier ' . ($win ? '`data\\backups`' : '`data/backups`') . ' par défaut — la base et `…-fichiers.tar.gz`, au même horodatage.', 'Files: ' . ($win ? '`data\\backups`' : '`data/backups`') . ' folder by default — the database and `…-fichiers.tar.gz`, with the same timestamp.'),
                $L('Journal : ' . ($win ? '`data\\logs\\sauvegardes.log`' : '`data/logs/sauvegardes.log`') . '.', 'Log: ' . ($win ? '`data\\logs\\sauvegardes.log`' : '`data/logs/sauvegardes.log`') . '.'),
            ],
            'code' => $win ? '' : ($prod ? "php $r/api/outils/sauvegarde-auto.php --etat\nphp $r/api/outils/sauvegarde-auto.php --forcer" : "php api/outils/sauvegarde-auto.php --etat\nphp api/outils/sauvegarde-auto.php --forcer"),
            'note' => $L('Copiez régulièrement les sauvegardes hors du serveur, avec .env et config.key. Pour restaurer, arrêtez Larka et remettez en place la base et l\'archive des fichiers du même horodatage.', 'Regularly copy backups off the server, together with .env and config.key. To restore, stop Larka and put back the database and the file archive with the same timestamp.')];

    // Journaux
    if ($win) {
        $code = '';
        $texte = $L('Dans le dossier `data\\logs` de Larka : `php_errors.log` (erreurs), `serveur.log` (démarrage du serveur), `sauvegardes.log`.', "In Larka's `data\\logs` folder: `php_errors.log` (errors), `serveur.log` (server start-up), `sauvegardes.log`.");
    } elseif ($prod) {
        $code = "tail -f $r/data/logs/php_errors.log\nsudo tail -f /var/log/nginx/gmao_error.log";
        $texte = $L('Erreurs de l\'application et du serveur web :', 'Application and web server errors:');
    } else {
        $code = ($m === 'systemd' ? "journalctl -u larka -f\n" : "./start.sh logs\n") . 'tail -f data/logs/php_errors.log';
        $texte = $L('Depuis le dossier de Larka :', "From Larka's folder:");
    }
    $f[] = ['id' => 'srv-journaux', 'titre' => $L('Consulter les journaux', 'Read the logs'),
            'mots' => $L('journaux logs erreur trace php_errors nginx journalctl problème diagnostic', 'logs log error trace php_errors nginx journalctl problem diagnostics'),
            'texte' => $texte, 'code' => $code,
            'note' => $L('Les adresses des requêtes ne sont pas journalisées en entier : elles peuvent porter un jeton à usage unique.', 'Request addresses are not logged in full: they may carry a single-use token.')];

    // Mises à jour du système et de PHP
    if ($win) {
        $f[] = ['id' => 'srv-php', 'titre' => $L('Mettre PHP à jour', 'Update PHP'),
                'mots' => $L('php mise à jour version installer télécharger windows', 'php update version install download windows'),
                'texte' => $L('Fermez Larka, puis :', 'Close Larka, then:'),
                'code' => "Larka.cmd php\nLarka.cmd php C:\\chemin\\php-8.3.x-nts-Win32-vs16-x64.zip",
                'note' => $L('Sans accès à Internet, téléchargez ailleurs l\'archive « VS16 x64 Non Thread Safe » de windows.php.net et donnez son chemin. Le dossier php\\ n\'est jamais touché par une mise à jour de Larka.', 'Without Internet access, download the “VS16 x64 Non Thread Safe” zip from windows.php.net elsewhere and give its path. The php\\ folder is never touched by a Larka update.')];
    }
    if ($prod) {
        $f[] = ['id' => 'srv-maj-cli', 'titre' => $L('Mettre Larka à jour sans l\'interface', 'Update Larka without the interface'),
                'mots' => $L('mise à jour ligne de commande serveur installer version terminal', 'update command line server install version terminal'),
                'texte' => $L('Mêmes contrôles que l\'interface (signature, prérequis), sauvegarde avant écriture :', 'Same checks as the interface (signature, prerequisites), backup before writing:'),
                'code' => "sudo -u www-data php $r/api/outils/mise-a-jour.php --verifier\nsudo -u www-data php $r/api/outils/mise-a-jour.php --installer"];
    }
    if (!$win && $base === 'PostgreSQL') {
        $f[] = ['id' => 'srv-postgresql', 'titre' => $L('PostgreSQL après une mise à jour du système', 'PostgreSQL after a system update'),
                'mots' => $L('postgresql collation index reindex mise à jour système glibc apt upgrade avertissement', 'postgresql collation index reindex system update glibc apt upgrade warning'),
                'texte' => $L('Si la bibliothèque C du système change, les index des bases sont à reconstruire (aucune donnée modifiée).', 'If the system C library changes, database indexes must be rebuilt (no data is changed).'),
                'code' => $prod ? "php $r/api/outils/postgresql.php collation" : './start.sh doctor --fix'];
    }
    if (!$prod && !$multi) {
        $f[] = ['id' => 'srv-demo', 'titre' => $L('Installer la démonstration Larkaville', 'Install the Larkaville demo'),
                'mots' => $L('démonstration demo larkaville exemple essai données fictives tester', 'demonstration demo larkaville example trial sample data test'),
                'texte' => $L('Une commune fictive, cinq ans d\'activité dans tous les modules. La base actuelle est d\'abord mise de côté.', 'A fictional town with five years of activity in every module. The current database is set aside first.'),
                'code' => $win ? 'Larka.cmd demo' : './start.sh demo',
                'note' => $L('La démonstration est livrée dans le dossier demo de Larka. Une autre archive …-sauvegarde-demo-Larkaville.zip se pose dans le dossier de Larka, ou se donne en argument.', "The demo ships in Larka's demo folder. Another …-sauvegarde-demo-Larkaville.zip archive goes in Larka's folder, or is given as an argument.")];
    }
    foreach ($f as &$x) { $x['cat'] = 'serveur'; $x = array_filter($x, fn($v) => $v !== '' && $v !== null); }
    unset($x);
    return $f;
}

if ($action === 'aide') {
    $sa = !empty($_SESSION['superadmin']['authenticated']);
    $user = require_auth();
    $role = (string)($user['Role'] ?? '');
    $console = !empty($_GET['console']);
    $en = (string)($_GET['langue'] ?? 'fr') === 'en';
    $langue = $en ? 'en' : 'fr';
    $multi = false;
    try { $multi = TenantResolver::isMultiTenant(); } catch (\Throwable $e) { /* registre injoignable */ }

    $groupes = aide_groupes($role, $sa, $console, $multi);
    [$fiches, $categories] = aide_fiches($groupes, $multi, $langue);

    if (in_array('exploitant', $groupes, true)) {
        require_once __DIR__ . '/../Prerequis.php';
        $racine = dirname(__DIR__, 2);
        $cfgBase = cfg('base_de_donnees');
        $cfgBase = is_array($cfgBase) ? $cfgBase + ['driver' => 'sqlite'] : ['driver' => 'sqlite'];
        $pre = null;
        try { $pre = LarkaPrerequis::verifier(null, $cfgBase, $racine); } catch (\Throwable $e) { /* rapport indisponible */ }
        $sauv = null;
        try {
            require_once __DIR__ . '/../SauvegardesAuto.php';
            $e = LarkaSauvegardesAuto::etat();
            $passe = !empty($e['dernier_passage']) ? (int)strtotime((string)$e['dernier_passage']) : 0;
            $actives = array_values(array_filter($e['travaux'], fn($w) => !empty($w['reglage']['actif'])));
            $sauv = ['planificateur_actif' => $passe > time() - 1800, 'actives' => count($actives),
                     'en_retard' => count(array_filter($actives, fn($w) => $w['en_retard'] || $w['erreur']))];
        } catch (\Throwable $x) { /* état indisponible */ }
        $drv = strtolower((string)$cfgBase['driver']);
        $base = $drv === 'pgsql' ? 'PostgreSQL' : ($drv === 'sqlite' ? 'SQLite' : 'MariaDB / MySQL');
        $fiches = array_merge($fiches, aide_fiches_serveur(aide_environnement(), $multi, $en, $pre, $sauv, $base));
    }

    // Catégories utilisées, dans l'ordre du contenu.
    $utilisees = array_flip(array_column($fiches, 'cat'));
    $cats = [];
    foreach ($categories as $id => $c) {
        if (isset($utilisees[$id])) $cats[] = ['id' => $id, 'icone' => $c['icone'], 'titre' => $c[$langue] ?? $c['fr']];
    }

    require_once __DIR__ . '/../Version.php';
    json_ok([
        'langue'   => $langue,
        'role'     => aide_libelle_role($role, $sa, $console, $en),
        'version'  => LarkaVersion::libelle(LarkaVersion::lire()),
        'categories' => $cats,
        'fiches'   => $fiches,
        'feedback' => (bool)(cfg('feedback', 'actif') ?? false),
        'pied'     => aide_pied($groupes, $multi, $en),
    ]);
}
