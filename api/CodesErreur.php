<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Codes d'erreur de Larka — ce qui s'est passé, pourquoi, et quoi faire
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Un message de journal dit CE QUI s'est passé, souvent dans les mots de la
 *  machine (« SQLSTATE[HY000]: database is locked », « 10.0.0.12:502 ne
 *  répond pas »). Celui qui le lit a besoin de trois choses de plus : un
 *  CODE stable (à citer au support, à chercher dans la documentation), une
 *  EXPLICATION en clair, et la MARCHE À SUIVRE — dans l'ordre où l'essayer,
 *  avec qui doit agir.
 *
 *  Ce fichier est le catalogue fermé de ces codes. Chaque code porte :
 *
 *    titre        une ligne, ce que l'on voit ;
 *    gravite      info | avertissement | erreur | critique ;
 *    description  ce que cela veut dire, en clair ;
 *    causes       les causes probables, de la plus fréquente à la plus rare ;
 *    remedes      quoi faire, dans l'ordre ; commandes entre `…` ;
 *    qui          qui peut agir (utilisateur, gestionnaire, administrateur,
 *                 super administrateur, service informatique) ;
 *    doc          où lire la suite (fichier#section).
 *
 *  Le code est posé de deux façons :
 *    - explicitement, par l'appelant (contexte « code » d'une entrée de
 *      journal) quand il sait précisément ce qui s'est passé ;
 *    - sinon, RECONNU d'après le message (motifs ci-dessous). Les messages
 *      de Larka sont écrits en français, stables, et les erreurs de PHP ou de
 *      la base ont des formulations connues : la reconnaissance suffit pour
 *      l'immense majorité des cas, sans réécrire chaque appel.
 *
 *  Format d'un code : trois lettres de domaine, trois chiffres. Un code ne
 *  change jamais de sens ; un code retiré n'est pas réattribué.
 *
 *    SYS système (PHP, configuration)      CXC Larka Connect : collecte
 *    BDD base de données                   CXR Larka Connect : réseau
 *    FIC fichiers et disque                CXP Larka Connect : protocoles
 *    SEC connexion, droits, sécurité       CXB bornes de recharge (OCPP)
 *    API requêtes refusées                 CXL capteurs LoRaWAN
 *    NAV navigateur                        CXW compteurs radio wM-Bus
 *    MEL messagerie, notifications         CXH appareils hors réseau
 *    TAC tâches de fond                    CXA alarmes, relevés, écritures
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

final class CodesErreur
{
    public const DOMAINES = [
        'SYS' => 'Système', 'BDD' => 'Base de données', 'FIC' => 'Fichiers et disque', 'SEC' => 'Connexion et sécurité',
        'API' => 'Requêtes refusées', 'NAV' => 'Navigateur', 'MEL' => 'Messagerie et notifications', 'TAC' => 'Tâches de fond',
        'CXC' => 'Supervision : collecte', 'CXR' => 'Supervision : réseau', 'CXP' => 'Supervision : protocoles',
        'CXB' => 'Bornes de recharge', 'CXL' => 'Capteurs LoRaWAN', 'CXW' => 'Compteurs radio wM-Bus',
        'CXH' => 'Appareils hors réseau', 'CXA' => 'Alarmes, relevés, écritures',
    ];

    public const GRAVITES = ['info', 'avertissement', 'erreur', 'critique'];
    public const QUI = ['utilisateur', 'gestionnaire', 'administrateur', 'super administrateur', 'service informatique', 'fournisseur'];

    private const DOC_CX = 'Documentations/LARKA-CONNECT.md';

    // ══ Le catalogue ═══════════════════════════════════════════════════════

    public const CATALOGUE = [
        // ── Système ────────────────────────────────────────────────────────
        'SYS-001' => ['titre' => 'Erreur interne du serveur', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Une opération s'est arrêtée sur une erreur que Larka n'a pas su traiter. L'action demandée n'a pas été faite (ou pas entièrement) ; les données déjà enregistrées ne sont pas touchées.",
            'causes' => ["Cas imprévu dans le code (donnée inhabituelle, combinaison de réglages rare)", "Fichier de Larka modifié ou incomplet après une mise à jour interrompue"],
            'remedes' => ["Réessayer une fois : une erreur passagère (base momentanément occupée) disparaît d'elle-même.", "Ouvrir le détail de l'entrée dans Journal : le type d'erreur, le fichier et la ligne y figurent.", "Si elle se répète, transmettre au support la référence de corrélation et le code SYS-001, avec ce que vous faisiez à ce moment-là."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'SYS-002' => ['titre' => 'Erreur fatale de PHP', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "PHP s'est arrêté au milieu d'une requête. La page a reçu une réponse incomplète ; l'action n'a probablement pas abouti.",
            'causes' => ["Fichier PHP de Larka manquant ou abîmé (mise à jour interrompue, copie partielle)", "Version de PHP trop ancienne pour cette version de Larka", "Extension PHP retirée du serveur"],
            'remedes' => ["Vérifier les prérequis : `php api/outils/prerequis.php`.", "Réinstaller la même version par-dessus (Configuration → Version et mises à jour, ou `./start.sh maj LARKA_GMAO-3.0.0.zip`) : vos données ne sont pas touchées.", "Lire la ligne correspondante de `data/logs/php_errors.log` (fichier et ligne exacts)."],
            'doc' => 'Documentations/Manuel-Larka-SuperAdmin.docx, « Mises à jour de la plateforme »'],
        'SYS-003' => ['titre' => 'Mémoire de PHP épuisée', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "L'opération demandait plus de mémoire que PHP n'en accorde (memory_limit). Elle a été interrompue.",
            'causes' => ["Export ou import très volumineux (plusieurs centaines de milliers de lignes)", "memory_limit réglé trop bas (128 Mo ou moins)"],
            'remedes' => ["Découper l'opération : export sur une période plus courte, import en plusieurs fichiers.", "Augmenter memory_limit à 512M dans le php.ini du serveur web (ou de PHP-FPM), puis redémarrer le service PHP.", "Sous Windows (Larka.cmd), le php.ini est dans le dossier php\\ de Larka."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'SYS-004' => ['titre' => 'Durée maximale dépassée', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "L'opération a duré plus longtemps que PHP ne l'autorise (max_execution_time) et a été coupée.",
            'causes' => ["Traitement long (import, sauvegarde, analyse) lancé depuis l'écran", "Serveur surchargé, ou base distante lente"],
            'remedes' => ["Relancer l'opération en dehors des heures chargées, ou sur moins de données.", "Pour les traitements longs, utiliser la commande prévue (`php api/outils/…`) qui n'a pas cette limite.", "Augmenter max_execution_time (et le délai de PHP-FPM / Nginx) si l'opération doit rester dans l'écran."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'SYS-005' => ['titre' => 'Extension PHP manquante', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "Une fonction indispensable de PHP n'est pas disponible sur ce serveur. La fonction de Larka qui en dépend ne marche pas.",
            'causes' => ["Extension non installée (pdo_sqlite, pdo_pgsql, mbstring, openssl, zip, gd, curl…)", "Extension installée mais pas activée dans le php.ini utilisé par le serveur web"],
            'remedes' => ["Lister ce qui manque : `php api/outils/prerequis.php`.", "Linux : installer le paquet (ex. `sudo apt install php-mbstring php-zip`), puis redémarrer PHP-FPM ou Apache.", "Windows : relancer Larka.cmd, qui active les extensions de son PHP ; sinon supprimer le dossier php\\ et relancer pour le réinstaller."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'SYS-006' => ['titre' => 'Configuration illisible', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "Le fichier config.json est absent, vide ou mal formé (JSON invalide). Larka démarre avec ses réglages par défaut, ou pas du tout.",
            'causes' => ["Modification à la main avec une virgule ou un guillemet en trop", "Fichier enregistré dans un encodage inhabituel", "Fichier écrasé par une copie incomplète"],
            'remedes' => ["Vérifier la syntaxe : `php -r 'json_decode(file_get_contents(\"config.json\")); echo json_last_error_msg();'`.", "Comparer avec config.example.json ; restaurer la dernière sauvegarde de config.json si besoin (data/backups).", "Préférer Configuration → Serveur dans l'application : elle n'écrit que du JSON valide."],
            'doc' => 'config.example.json'],
        'SYS-007' => ['titre' => 'Avertissement de PHP', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "PHP a signalé une anomalie sans arrêter l'opération (variable absente, conversion douteuse…). Le résultat est en général correct, mais le cas mérite d'être regardé.",
            'causes' => ["Donnée incomplète (champ vide là où une valeur était attendue)", "Comportement d'une nouvelle version de PHP"],
            'remedes' => ["Rien d'urgent si l'écran a fonctionné.", "S'il se répète, transmettre au support le fichier et la ligne (détail de l'entrée)."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],

        // ── Base de données ────────────────────────────────────────────────
        'BDD-001' => ['titre' => 'Base de données injoignable', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "Larka n'a pas pu ouvrir sa base. Rien ne peut être lu ni enregistré tant que la base ne répond pas.",
            'causes' => ["Serveur PostgreSQL ou MariaDB arrêté, ou sur une autre machine injoignable", "Mot de passe ou nom de base modifié", "Fichier SQLite déplacé ou supprimé (data/gmao.db)"],
            'remedes' => ["PostgreSQL : `sudo systemctl status postgresql` ; MariaDB : `sudo systemctl status mariadb`.", "Vérifier base_de_donnees dans config.json (hôte, port, nom, utilisateur) et le mot de passe dans .env.", "SQLite : vérifier que le fichier désigné existe et appartient à l'utilisateur du serveur web.", "Tester la connexion depuis le serveur : `psql -h <hôte> -U <utilisateur> <base>`."],
            'doc' => 'README.md, « Configuration & secrets »'],
        'BDD-002' => ['titre' => 'Base de données occupée', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "La base était verrouillée par une autre écriture au même moment. L'opération a échoué après une attente ; recommencer suffit le plus souvent.",
            'causes' => ["SQLite : deux écritures longues simultanées (import, sauvegarde, collecte)", "Sauvegarde ou copie du fichier de base pendant l'utilisation", "Antivirus qui verrouille data/gmao.db (Windows)"],
            'remedes' => ["Réessayer dans quelques secondes.", "Windows : exclure le dossier data\\ de Larka de l'analyse en temps réel de l'antivirus.", "Avec beaucoup d'utilisateurs ou d'équipements raccordés, passer à PostgreSQL (Super Admin → bases)."],
            'doc' => 'README.md, « Configuration & secrets »'],
        'BDD-003' => ['titre' => 'Structure de la base incomplète', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Une table ou une colonne attendue manque. La mise à niveau de la base ne s'est pas faite, ou pas jusqu'au bout.",
            'causes' => ["Mise à jour de Larka sans redémarrage complet", "Base restaurée depuis une version plus ancienne", "Droits insuffisants de l'utilisateur de la base pour créer ou modifier des tables"],
            'remedes' => ["Recharger la page : la mise à niveau s'applique d'elle-même au premier accès.", "PostgreSQL / MariaDB : vérifier que l'utilisateur de Larka a le droit CREATE et ALTER sur sa base.", "Si l'erreur persiste, lancer la vérification : `php api/outils/prerequis.php` et transmettre la table citée au support."],
            'doc' => 'Documentations/Manuel-Larka-SuperAdmin.docx, « Mises à jour de la plateforme »'],
        'BDD-004' => ['titre' => 'Donnée refusée par la base', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "La base a refusé l'enregistrement : doublon d'une valeur unique, lien vers un élément supprimé, ou valeur trop longue.",
            'causes' => ["Un élément du même code ou du même nom existe déjà", "L'élément lié (bien, équipement, compteur) a été supprimé entre-temps", "Saisie plus longue que ce que le champ accepte"],
            'remedes' => ["Rechercher l'élément existant et le modifier plutôt que d'en créer un second.", "Recharger la page puis recommencer : la liste des éléments liés sera à jour.", "Raccourcir la saisie si elle est très longue."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'BDD-005' => ['titre' => 'Base en lecture seule', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "La base refuse toute écriture. Les écrans s'affichent, mais rien ne s'enregistre.",
            'causes' => ["SQLite : fichier ou dossier data/ non inscriptible par l'utilisateur du serveur web", "Disque plein ou monté en lecture seule", "Base PostgreSQL en mode réplique (lecture seule)"],
            'remedes' => ["Linux : `sudo chown -R www-data: data && sudo chmod -R u+rwX data` (adapter l'utilisateur).", "Vérifier l'espace disque : `df -h`.", "PostgreSQL : vérifier que Larka vise le serveur principal, pas une réplique."],
            'doc' => 'README.md, « Configuration & secrets »'],

        // ── Fichiers et disque ─────────────────────────────────────────────
        'FIC-001' => ['titre' => 'Écriture impossible sur le disque', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "Larka n'a pas pu écrire un fichier (journal, document joint, sauvegarde, export). L'opération concernée est perdue ou incomplète.",
            'causes' => ["Dossier data/ non inscriptible par l'utilisateur du serveur web", "Disque plein", "Windows : dossier protégé (Program Files), en lecture seule, ou bloqué par l'antivirus"],
            'remedes' => ["Linux : `sudo chown -R www-data: data && sudo chmod -R 775 data` (adapter l'utilisateur du service).", "Vérifier l'espace libre : `df -h` (Linux) ou l'explorateur (Windows).", "Windows : installer Larka hors de Program Files (ex. C:\\Larka) et exclure son dossier de l'antivirus."],
            'doc' => 'README.md, « Installation en production »'],
        'FIC-002' => ['titre' => 'Disque plein', 'gravite' => 'critique', 'qui' => 'administrateur',
            'description' => "Il n'y a plus de place sur le disque du serveur. Les enregistrements, journaux et sauvegardes échouent.",
            'causes' => ["Sauvegardes accumulées (data/backups)", "Journaux volumineux non purgés", "Documents joints nombreux ou lourds"],
            'remedes' => ["Libérer de la place : réduire « Sauvegardes conservées » (Configuration → Sauvegardes), supprimer les plus anciennes.", "Réduire la rétention du journal (Configuration → Logs) et de l'historique des mesures (Larka Connect).", "Agrandir le disque ou déplacer data/backups vers un autre volume."],
            'doc' => 'README.md, « Sauvegardes »'],
        'FIC-003' => ['titre' => 'Fichier refusé', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "Le fichier envoyé n'a pas été accepté : type non autorisé, trop gros, ou contenu qui ne correspond pas à son extension.",
            'causes' => ["Format non autorisé par la configuration (documents.mime_autorises)", "Taille au-delà de documents.taille_max_mo", "Fichier renommé (un .exe en .pdf, par exemple)"],
            'remedes' => ["Enregistrer le document en PDF, JPEG ou PNG, puis le joindre à nouveau.", "Réduire sa taille (compression, résolution).", "Administrateur : élargir les types ou la taille dans Configuration → Documents si c'est légitime."],
            'doc' => 'README.md, « Configuration & secrets »'],

        // ── Connexion, droits, sécurité ────────────────────────────────────
        'SEC-001' => ['titre' => 'Identifiant ou mot de passe incorrect', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "La connexion a été refusée. Pour la sécurité, Larka ne dit pas lequel des deux est faux.",
            'causes' => ["Faute de frappe, majuscules activées", "Mot de passe changé récemment", "Compte local alors que l'organisation utilise la connexion Microsoft ou Google"],
            'remedes' => ["Vérifier la touche Verr. Maj et retaper le mot de passe.", "Utiliser « Mot de passe oublié » si la messagerie est configurée.", "Sinon, demander à un administrateur de réinitialiser le mot de passe (Utilisateurs)."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-002' => ['titre' => 'Compte temporairement bloqué', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "Trop d'essais de connexion manqués : le compte (ou l'adresse) est bloqué pour quelques minutes, contre les tentatives d'intrusion.",
            'causes' => ["Plusieurs fautes de frappe de suite", "Un appareil (téléphone, ancien onglet) qui réessaie avec un ancien mot de passe", "Tentative d'intrusion depuis l'extérieur"],
            'remedes' => ["Attendre la fin du délai indiqué, puis réessayer calmement.", "Fermer les anciens onglets et applications mobiles connectés avec l'ancien mot de passe.", "Administrateur : vérifier dans Journal (canal securite) d'où viennent les essais."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-003' => ['titre' => 'Session expirée', 'gravite' => 'info', 'qui' => 'utilisateur',
            'description' => "La session a pris fin (inactivité, déconnexion ailleurs, redémarrage du serveur). Il faut se reconnecter.",
            'causes' => ["Inactivité plus longue que la durée de session", "Déconnexion depuis un autre onglet", "Cookies effacés par le navigateur"],
            'remedes' => ["Se reconnecter. Une saisie en cours non enregistrée est à refaire.", "Si cela arrive en pleine saisie, demander à l'administrateur d'allonger la durée de session (Configuration → Sécurité)."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-004' => ['titre' => 'Accès refusé', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "Votre rôle ne permet pas cette action. Rien n'a été modifié.",
            'causes' => ["Action réservée à un rôle supérieur (Gestionnaire, Admin, Super Admin)", "Module désactivé pour l'organisation", "Lien ouvert depuis un autre compte"],
            'remedes' => ["Demander l'action à un gestionnaire ou un administrateur.", "Si votre fonction le justifie, demander à un administrateur de changer votre rôle (Utilisateurs).", "Module désactivé : le Super Admin l'ouvre par organisation (modules)."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-005' => ['titre' => "Confirmation d'identité requise", 'gravite' => 'info', 'qui' => 'utilisateur',
            'description' => "Cette action sensible (écriture vers un équipement, téléchargement d'un jeton…) demande de retaper son mot de passe. La confirmation vaut cinq minutes.",
            'causes' => ["Action sensible lancée plus de cinq minutes après la dernière confirmation"],
            'remedes' => ["Saisir son mot de passe dans la fenêtre qui s'ouvre, puis relancer l'action."],
            'doc' => self::DOC_CX . ', § 8'],
        'SEC-006' => ['titre' => 'Jeton de sécurité invalide (CSRF)', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "La requête ne portait pas le jeton de la session en cours. Par sécurité, elle est refusée.",
            'causes' => ["Page ouverte depuis longtemps, session renouvelée entre-temps", "Formulaire envoyé depuis un autre site (tentative d'attaque)"],
            'remedes' => ["Recharger la page (F5), puis recommencer.", "Si cela se répète, vider le cache du navigateur."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-007' => ['titre' => 'Trop de requêtes', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "Le serveur a limité les requêtes venant de cette adresse pendant un court instant.",
            'causes' => ["Script ou onglet qui rafraîchit en boucle", "Plusieurs postes derrière la même adresse qui font beaucoup d'essais de connexion"],
            'remedes' => ["Patienter une minute.", "Fermer les onglets en double.", "Administrateur : si un proxy regroupe les postes, le déclarer dans securite.trusted_proxies (Super Admin)."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-008' => ['titre' => 'Connexion fédérée refusée (Microsoft, Google)', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Le fournisseur d'identité n'a pas validé la connexion, ou sa réponse n'a pas pu être vérifiée.",
            'causes' => ["Secret de l'application expiré dans Entra ID / Google Cloud", "Adresse de retour (redirect URI) différente de celle déclarée chez le fournisseur", "Compte non autorisé dans le locataire"],
            'remedes' => ["Vérifier l'adresse de retour déclarée : elle doit être exactement celle affichée dans Configuration → Authentification.", "Renouveler le secret client chez le fournisseur et le recopier dans Larka.", "Vérifier que l'utilisateur appartient bien au locataire ou au groupe autorisé."],
            'doc' => 'README.md, « Sécurité »'],
        'SEC-009' => ['titre' => 'Tentative suspecte bloquée', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "Une requête contenait ce qui ressemble à une attaque (injection, chemin détourné, fichier piégé). Elle a été refusée sans effet.",
            'causes' => ["Scanner de vulnérabilités ou robot sur Internet", "Copier-coller d'un contenu inhabituel dans un champ"],
            'remedes' => ["Rien à faire si c'est isolé : la requête n'a rien produit.", "Si l'adresse revient souvent, la bloquer au pare-feu ou dans le serveur web.", "Consulter le journal de sécurité (data/security/audit.log)."],
            'doc' => 'README.md, « Sécurité »'],

        // ── Requêtes refusées ──────────────────────────────────────────────
        'API-400' => ['titre' => 'Saisie refusée', 'gravite' => 'info', 'qui' => 'utilisateur',
            'description' => "Le serveur a refusé la demande parce qu'une valeur est absente ou invalide. Le message dit laquelle ; rien n'a été enregistré.",
            'causes' => ["Champ obligatoire vide", "Format inattendu (date, nombre, adresse)"],
            'remedes' => ["Corriger la valeur citée par le message, puis enregistrer de nouveau."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'API-404' => ['titre' => 'Élément introuvable', 'gravite' => 'info', 'qui' => 'utilisateur',
            'description' => "L'élément demandé n'existe pas ou plus (supprimé, ou dans une autre organisation).",
            'causes' => ["Élément supprimé par quelqu'un d'autre entre-temps", "Lien ancien ou copié depuis une autre organisation"],
            'remedes' => ["Recharger la liste : elle montrera l'état actuel.", "Consulter l'Historique pour savoir qui l'a supprimé, et quand."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'API-409' => ['titre' => 'Action impossible dans l\'état actuel', 'gravite' => 'info', 'qui' => 'gestionnaire',
            'description' => "La demande est valide, mais l'état présent l'empêche : module fermé, élément déjà traité, réglage manquant. Le message dit lequel.",
            'causes' => ["Module désactivé (Larka Connect, Énergie…)", "Élément déjà clôturé, validé ou en cours de traitement", "Réglage préalable manquant"],
            'remedes' => ["Lire la condition donnée par le message et la remplir (ouvrir le module, terminer l'étape précédente).", "Recharger la page : l'élément a peut-être changé d'état entre-temps."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'API-413' => ['titre' => 'Envoi trop volumineux', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "Le fichier ou les données envoyés dépassent la taille acceptée par le serveur.",
            'causes' => ["Fichier très lourd", "Limite basse du serveur web (client_max_body_size, upload_max_filesize)"],
            'remedes' => ["Réduire le fichier (compression, découpe).", "Administrateur : relever upload_max_filesize et post_max_size (PHP) et client_max_body_size (Nginx)."],
            'doc' => 'README.md, « Configuration & secrets »'],
        'API-500' => ['titre' => 'Le serveur a rencontré une erreur', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "La requête a échoué côté serveur. Le détail est au journal, sous la même référence de corrélation.",
            'causes' => ["Voir l'entrée d'erreur qui précède, même référence de corrélation (Journal → Détail → Même requête)"],
            'remedes' => ["Ouvrir le détail : l'entrée d'erreur liée porte son propre code et sa marche à suivre.", "Transmettre la référence au support si rien n'est explicite."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],

        // ── Navigateur ─────────────────────────────────────────────────────
        'NAV-001' => ['titre' => 'Erreur dans la page (navigateur)', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "Un script de l'interface a échoué dans le navigateur de l'utilisateur. Une partie de l'écran peut être restée vide ou sans réaction.",
            'causes' => ["Ancienne version de l'interface en cache après une mise à jour", "Navigateur trop ancien", "Extension de navigateur qui modifie les pages"],
            'remedes' => ["Recharger sans cache : Ctrl+Maj+R (Cmd+Maj+R sur Mac).", "Mettre le navigateur à jour (Edge, Chrome, Firefox récents).", "Essayer en navigation privée pour écarter une extension.", "Transmettre au support la source et la ligne (détail de l'entrée)."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],
        'NAV-002' => ['titre' => 'Serveur injoignable depuis le navigateur', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "Le navigateur n'a reçu aucune réponse du serveur Larka : la demande n'est peut-être pas arrivée, ou sa réponse s'est perdue.",
            'causes' => ["Perte de réseau du poste (Wi-Fi, VPN coupé)", "Serveur arrêté ou redémarrage en cours", "Délai dépassé sur une opération longue"],
            'remedes' => ["Vérifier la connexion réseau du poste, puis réessayer.", "Si plusieurs utilisateurs sont touchés, prévenir l'administrateur : le serveur est peut-être arrêté."],
            'doc' => 'README.md, « Diagnostic & dépannage »'],

        // ── Messagerie et notifications ────────────────────────────────────
        'MEL-001' => ['titre' => 'Courriel non envoyé', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Larka n'a pas pu remettre un courriel au serveur de messagerie. Le destinataire ne l'a pas reçu.",
            'causes' => ["Identifiants SMTP refusés ou expirés", "Serveur ou port SMTP injoignable (pare-feu)", "Envoi refusé par Microsoft 365 / Gmail (authentification moderne exigée)"],
            'remedes' => ["Configuration → Messagerie : vérifier serveur, port (587 STARTTLS ou 465 SSL), identifiant, puis « Envoyer un courriel d'essai ».", "Microsoft 365 : utiliser la connexion OAuth plutôt qu'un mot de passe.", "Demander au service informatique d'autoriser la sortie vers le port SMTP."],
            'doc' => 'README.md, « Configuration & secrets »'],
        'MEL-002' => ['titre' => 'Notification push non remise', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "Une notification n'a pas pu être envoyée au navigateur ou au téléphone d'un utilisateur.",
            'causes' => ["Abonnement expiré (navigateur réinstallé, autorisation retirée)", "Clés VAPID modifiées", "Certificat HTTPS non pleinement valide"],
            'remedes' => ["Demander à l'utilisateur de réactiver les notifications (Mon profil).", "Ne pas changer les clés VAPID une fois en service.", "Vérifier que le certificat HTTPS est valide (pas d'exception du navigateur)."],
            'doc' => 'README.md, « Configuration & secrets »'],

        // ── Tâches de fond ─────────────────────────────────────────────────
        'TAC-001' => ['titre' => 'Sauvegarde automatique en échec', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "La dernière sauvegarde automatique n'a pas abouti. Les sauvegardes précédentes sont conservées.",
            'causes' => ["Disque plein", "Dossier de sauvegarde non inscriptible", "Outil de sauvegarde PostgreSQL (pg_dump) absent ou d'une autre version"],
            'remedes' => ["Configuration → Sauvegardes : lire le message de la dernière tentative, puis « Sauvegarder maintenant ».", "Vérifier l'espace libre et les droits de data/backups.", "PostgreSQL : installer pg_dump de la même version que le serveur."],
            'doc' => 'README.md, « Sauvegardes »'],
        'TAC-002' => ['titre' => 'Planificateur arrêté', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Les tâches de fond (collecte Larka Connect, sauvegardes, rappels) ne passent plus. Rien n'est perdu, mais rien n'avance.",
            'causes' => ["Tâche cron supprimée ou serveur redémarré sans la minuterie", "Fenêtre de Larka.cmd fermée (Windows)", "./start.sh arrêté"],
            'remedes' => ["Production : vérifier la tâche cron (`crontab -l -u www-data`) ou `systemctl status larka-connect.timer`.", "Poste : relancer `./start.sh start` ou Larka.cmd.", "Activer le démarrage automatique : `./start.sh autostart on`."],
            'doc' => self::DOC_CX . ', § 5'],
        'TAC-003' => ['titre' => 'Mise à jour non installée', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "La mise à jour a été arrêtée avant d'être appliquée. La version en place continue de fonctionner.",
            'causes' => ["Prérequis non remplis (version de PHP, extension)", "Archive incomplète ou non signée par l'éditeur", "Dossier de Larka non inscriptible"],
            'remedes' => ["Lire la raison affichée dans Configuration → Version et mises à jour.", "Vérifier les prérequis : `php api/outils/prerequis.php --paquet=<dossier décompressé>`.", "Télécharger de nouveau l'archive depuis la source officielle."],
            'doc' => 'Documentations/Manuel-Larka-SuperAdmin.docx, « Mises à jour de la plateforme »'],

        // ── Larka Connect : collecte ───────────────────────────────────────
        'CXC-001' => ['titre' => 'Collecte automatique arrêtée', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Aucun passage de collecte depuis plusieurs minutes : les valeurs de la supervision ne se mettent plus à jour, les règles d'alarme ne sont plus évaluées.",
            'causes' => ["Tâche cron, minuterie systemd, start.sh ou Larka.cmd arrêté", "Passage bloqué (verrou resté posé après un arrêt brutal)"],
            'remedes' => ["Voir l'état : `php api/outils/connect-collecte.php --etat`.", "Relancer le déclencheur : `./start.sh autostart on` (Linux) ou Larka.cmd (Windows) ; en production, la tâche cron de deploy/install.sh.", "Lancer un passage à la main pour voir l'erreur : `php api/outils/connect-collecte.php --forcer`."],
            'doc' => self::DOC_CX . ', § 5'],
        'CXC-002' => ['titre' => 'Connexion en défaut', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'équipement n'a donné aucune valeur à plusieurs relevés de suite. Une alarme de communication est ouverte ; les courbes ont un trou depuis.",
            'causes' => ["Équipement éteint, débranché ou redémarré", "Réseau ou pare-feu modifié", "Adresse, port ou identifiants changés sur l'équipement"],
            'remedes' => ["Connexions → 🧪 Tester : le test dit à quelle étape cela bloque (réseau, protocole, droits) et donne le code précis.", "Vérifier que l'équipement est allumé et sur le réseau.", "Le code de l'étape en échec (CXR-…, CXP-…) donne la suite."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXC-003' => ['titre' => 'Connexion partielle', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "L'équipement répond, mais certains points ne sont pas lus. Les autres continuent normalement.",
            'causes' => ["Adresse d'un point inexistante dans l'équipement (registre, objet, champ)", "Point retiré ou renommé dans l'équipement", "Capteur ou compteur derrière la passerelle hors service"],
            'remedes' => ["Onglet Points : l'erreur de chaque point est affichée ; corriger son adresse.", "« 🔍 Découvrir » liste ce que l'équipement expose réellement.", "Un point devenu inutile se désactive."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXC-004' => ['titre' => 'Communication rétablie', 'gravite' => 'info', 'qui' => 'gestionnaire',
            'description' => "L'équipement répond de nouveau. L'alarme de communication se ferme ; les relevés reprennent.",
            'causes' => ["Équipement rallumé, réseau rétabli, réglage corrigé"],
            'remedes' => ["Rien à faire. La durée de la coupure est visible dans l'alarme terminée."],
            'doc' => self::DOC_CX . ', § 6'],
        'CXC-005' => ['titre' => 'Passage de collecte trop long', 'gravite' => 'avertissement', 'qui' => 'administrateur',
            'description' => "Le temps alloué à un passage est épuisé : les connexions restantes sont relevées au passage suivant, une minute plus tard.",
            'causes' => ["Équipements lents ou injoignables qui attendent leur délai", "Écoutes radio longues (wM-Bus)", "Beaucoup de connexions au même rythme"],
            'remedes' => ["Corriger d'abord les connexions en défaut : leur délai d'attente coûte le plus.", "Espacer le rythme des connexions qui n'ont pas besoin d'être lues chaque minute.", "Augmenter « Durée maximale d'un passage » (55 s au plus)."],
            'doc' => self::DOC_CX . ', § 5'],
        'CXC-006' => ['titre' => 'Point illisible', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Un point n'a pas pu être lu ou sa valeur n'a pas pu être interprétée. Les autres points de la connexion ne sont pas touchés.",
            'causes' => ["Adresse incomplète ou fautive", "Valeur reçue d'un autre type que celui réglé (texte au lieu d'un nombre)", "Donnée absente de la réponse de l'équipement"],
            'remedes' => ["Ouvrir le point (onglet Points) et vérifier son adresse.", "« 🔍 Découvrir » pour reprendre l'adresse exacte.", "Modbus : l'explorateur montre la valeur sous plusieurs interprétations."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXC-007' => ['titre' => 'Larka Connect fermé', 'gravite' => 'info', 'qui' => 'administrateur',
            'description' => "Le module de supervision est désactivé sur ce serveur ou pour cette organisation : rien n'est collecté.",
            'causes' => ["Réglage connect.actif à « non »", "Module fermé pour l'organisation par le Super Admin"],
            'remedes' => ["Configuration → Serveur → Larka Connect : cocher « Larka Connect activé ».", "Multi-organisation : Super Admin → modules → Larka Connect."],
            'doc' => self::DOC_CX . ', § 2'],

        // ── Larka Connect : réseau ─────────────────────────────────────────
        'CXR-001' => ['titre' => "L'équipement refuse la connexion", 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'équipement est joignable, mais rien n'écoute sur le port demandé : il a répondu « refusé » immédiatement.",
            'causes' => ["Mauvais port (502 Modbus, 47808 BACnet, 1883 MQTT, 80/443 REST)", "Service de l'équipement arrêté ou désactivé dans ses réglages", "Nombre maximal de connexions simultanées atteint sur l'équipement"],
            'remedes' => ["Vérifier le port dans la notice de l'équipement et dans la fiche de la connexion.", "Vérifier que le service (Modbus TCP, API, broker) est activé sur l'équipement.", "Redémarrer l'équipement si un autre logiciel l'interroge aussi et sature ses connexions."],
            'doc' => self::DOC_CX . ', § 10'],
        'CXR-002' => ['titre' => "L'équipement ne répond pas (pare-feu probable)", 'gravite' => 'erreur', 'qui' => 'service informatique',
            'description' => "Aucune réponse dans le délai : les paquets sont perdus en chemin. C'est la signature d'un pare-feu qui jette les paquets, ou d'un équipement éteint.",
            'causes' => ["Pare-feu entre le serveur Larka et l'équipement (VLAN GTB séparé)", "Équipement éteint ou adresse IP changée", "Mauvaise adresse IP"],
            'remedes' => ["Vérifier que l'équipement est allumé et que son adresse IP n'a pas changé.", "Demander au service informatique d'ouvrir le flux : serveur Larka → adresse de l'équipement, sur le port de la connexion. Le bouton « Copier le message » du test rédige la demande.", "Depuis le serveur : `nc -vz <adresse> <port>` (TCP)."],
            'doc' => self::DOC_CX . ', § 10'],
        'CXR-003' => ['titre' => "Nom de l'équipement inconnu (DNS)", 'gravite' => 'erreur', 'qui' => 'service informatique',
            'description' => "Le serveur ne sait pas traduire le nom saisi en adresse IP : il ne peut donc pas joindre l'équipement, même s'il est allumé.",
            'causes' => ["Faute de frappe dans le nom", "Nom connu seulement des postes de travail, pas du serveur", "DNS interne injoignable depuis le serveur"],
            'remedes' => ["Saisir directement l'adresse IP de l'équipement.", "Ou demander au service informatique de déclarer ce nom dans le DNS utilisé par le serveur.", "Vérifier : `getent hosts <nom>` sur le serveur."],
            'doc' => self::DOC_CX . ', § 10'],
        'CXR-004' => ['titre' => "Aucune route vers l'équipement", 'gravite' => 'erreur', 'qui' => 'service informatique',
            'description' => "Le serveur n'a aucun chemin réseau vers cette adresse : elle est dans un réseau qu'il ne voit pas.",
            'causes' => ["Équipement dans un VLAN ou un site non routé vers le serveur", "Adresse d'un autre réseau saisie par erreur"],
            'remedes' => ["Vérifier l'adresse saisie.", "Demander au service informatique une route entre le réseau du serveur et celui de l'équipement.", "Sinon, relever l'équipement par un PC releveur (Hors réseau)."],
            'doc' => self::DOC_CX . ', § 10'],
        'CXR-005' => ['titre' => 'Adresse de l\'équipement absente ou invalide', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "La fiche de la connexion n'a pas d'adresse, ou l'adresse n'a pas une forme valide.",
            'causes' => ["Champ adresse vide", "Adresse avec des espaces, un « http:// » de trop ou un port collé"],
            'remedes' => ["Ouvrir la connexion et saisir l'adresse IP seule (ex. 10.0.12.20) ; le port a son propre champ."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXR-006' => ['titre' => 'Certificat HTTPS refusé', 'gravite' => 'erreur', 'qui' => 'service informatique',
            'description' => "L'équipement présente un certificat que le serveur ne peut pas vérifier. Par sécurité, la connexion est refusée.",
            'causes' => ["Certificat auto-signé ou d'une autorité interne inconnue du serveur", "Certificat expiré", "Nom du certificat différent de l'adresse saisie"],
            'remedes' => ["Installer l'autorité interne sur le serveur (`/usr/local/share/ca-certificates`, puis `update-ca-certificates`).", "Saisir l'adresse exactement comme dans le certificat (nom plutôt qu'IP).", "En dernier recours, et sur un réseau interne seulement : désactiver la vérification du certificat dans la connexion."],
            'doc' => self::DOC_CX . ', § 10'],

        // ── Larka Connect : protocoles ─────────────────────────────────────
        'CXP-001' => ['titre' => 'Modbus : registre inexistant', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'équipement a répondu « adresse de registre invalide » (exception Modbus 0x02) : le registre demandé n'existe pas chez lui.",
            'causes' => ["Décalage d'adresse : la notice compte à partir de 1 (40001), Larka à partir de 0", "Mauvais type de registre (maintien / entrée)", "Plage regroupée qui traverse un trou de la table"],
            'remedes' => ["Essayer l'adresse moins 1 (ou plus 1) : c'est la cause la plus fréquente.", "Vérifier la fonction (03 maintien, 04 entrée) dans la notice.", "« 🧭 Explorer » lit une plage brute pour repérer les registres présents."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXP-002' => ['titre' => "Modbus : l'équipement derrière la passerelle ne répond pas", 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "La passerelle Modbus TCP répond, mais l'équipement visé derrière elle (bus RS-485) non (exception 0x0B ou 0x0A).",
            'causes' => ["Numéro d'esclave (unit id) inexistant", "Équipement du bus éteint, ou bus RS-485 coupé", "Vitesse ou parité du bus différente entre la passerelle et l'équipement"],
            'remedes' => ["Vérifier l'unit id de l'équipement (souvent sur son afficheur).", "Vérifier son alimentation et le câblage du bus (A/B, terminaison).", "Comparer vitesse, parité et bits de stop dans la passerelle et l'équipement."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXP-003' => ['titre' => 'Modbus : requête refusée par l\'équipement', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'équipement a refusé la requête : fonction non gérée (0x01), quantité refusée (0x03), défaut interne (0x04) ou occupé (0x06).",
            'causes' => ["Fonction de lecture non prise en charge par ce modèle", "Trop de registres demandés d'un coup", "Équipement en défaut ou occupé"],
            'remedes' => ["Changer la fonction du point (03 ↔ 04).", "Réduire la plage (moins de points contigus).", "Défaut interne : consulter l'afficheur ou la notice de l'équipement."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXP-004' => ['titre' => 'BACnet : aucune réponse', 'gravite' => 'erreur', 'qui' => 'service informatique',
            'description' => "L'automate ne répond pas en BACnet/IP (UDP 47808). En UDP, le silence ne distingue pas pare-feu, automate éteint et numéro d'automate faux.",
            'causes' => ["Flux UDP 47808 filtré entre le serveur et l'automate", "Numéro d'automate (device instance) faux", "Automate sur un autre réseau BACnet (BBMD nécessaire)"],
            'remedes' => ["Vérifier le numéro d'automate : « 🔍 Découvrir » fait un Who-Is.", "Demander l'ouverture de l'UDP 47808 dans les deux sens.", "Si l'automate est sur un autre sous-réseau, déclarer le serveur dans son BBMD."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXP-005' => ['titre' => 'BACnet : objet ou propriété inconnu', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'automate répond, mais l'objet demandé (type, instance) ou sa propriété n'existe pas.",
            'causes' => ["Objet supprimé ou renuméroté après une modification du programme de l'automate", "Type d'objet erroné (analog-input au lieu d'analog-value)"],
            'remedes' => ["« 🔍 Découvrir » relit la liste des objets de l'automate ; reprendre l'adresse exacte."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXP-006' => ['titre' => 'MQTT : connexion refusée par le broker', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "Le broker MQTT a refusé la connexion (identifiants, identifiant client ou autorisation).",
            'causes' => ["Identifiant ou mot de passe incorrect", "Identifiant client déjà utilisé par un autre programme", "Compte sans droit de connexion"],
            'remedes' => ["Vérifier l'identifiant et le mot de passe dans la connexion (les ressaisir).", "Laisser Larka choisir l'identifiant client (champ vide).", "Vérifier les droits du compte dans le broker (ACL)."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXP-007' => ['titre' => 'MQTT : abonnement refusé', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "La connexion est acceptée, mais le broker refuse un ou plusieurs sujets suivis.",
            'causes' => ["Droits du compte limités à d'autres sujets (ACL)", "Sujet mal écrit (caractère + ou # mal placé)"],
            'remedes' => ["Vérifier les sujets saisis (ex. `batiment/+/capteurs/#`).", "Demander l'ouverture de ces sujets en lecture pour ce compte."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXP-008' => ['titre' => "Le port répond, mais pas avec le protocole attendu", 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "Quelque chose écoute sur ce port, mais ne parle pas le protocole choisi (MQTT, Modbus, HTTP).",
            'causes' => ["Mauvais port (celui de l'interface web au lieu de celui du service)", "Protocole chiffré attendu (MQTT sur TLS 8883, HTTPS)"],
            'remedes' => ["Vérifier le port dans la notice.", "Cocher TLS si le service est chiffré.", "« 🛰️ Détecter » identifie le protocole parlé sur chaque port."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXP-009' => ['titre' => 'API REST : authentification refusée', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'API a répondu 401 ou 403 : le jeton ou le mot de passe n'est pas accepté, ou n'a pas les droits.",
            'causes' => ["Jeton expiré ou révoqué", "Jeton en lecture seule pour une écriture", "Mode d'authentification différent (Bearer, Basic, clé dans l'en-tête)"],
            'remedes' => ["Générer un nouveau jeton dans le superviseur et le recopier dans la connexion.", "Vérifier le mode d'authentification choisi."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXP-010' => ['titre' => "API REST : réponse d'erreur ou illisible", 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "L'API a répondu une erreur (404, 422, 5xx) ou une réponse qui n'est pas du JSON.",
            'causes' => ["Chemin d'accès erroné (adresse de base, liste des points)", "Superviseur en maintenance ou surchargé (503)", "Page d'erreur HTML d'un proxy à la place de l'API"],
            'remedes' => ["Vérifier l'adresse de base et les chemins dans la connexion.", "Ouvrir l'adresse dans un navigateur depuis le réseau du serveur pour voir la réponse.", "5xx : patienter ; prévenir l'éditeur du superviseur si cela dure."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXP-011' => ['titre' => 'CSV : dossier non autorisé', 'gravite' => 'erreur', 'qui' => 'super administrateur',
            'description' => "Le dossier des exports CSV est hors des dossiers que Larka a le droit de lire (connect.csv_racines). Par sécurité, il n'est pas lu.",
            'causes' => ["Partage réseau monté ailleurs que sous data/connect/imports", "Chemin saisi avec une faute"],
            'remedes' => ["Super administrateur : ajouter le dossier à `connect.csv_racines` dans config.json.", "Ou monter le partage sous data/connect/imports."],
            'doc' => self::DOC_CX . ', § 10'],
        'CXP-012' => ['titre' => 'CSV : aucun fichier exploitable', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Aucun fichier ne correspond au modèle de nom, ou les fichiers trouvés n'ont pas les colonnes attendues.",
            'causes' => ["La GTB n'exporte plus (tâche d'export arrêtée)", "Modèle de nom différent (GTB_EXPORT_*.csv)", "Format des colonnes changé"],
            'remedes' => ["Vérifier dans le dossier que des fichiers récents arrivent.", "Ajuster le modèle de nom dans la connexion.", "« 🔍 Découvrir » montre les colonnes du dernier fichier."],
            'doc' => self::DOC_CX . ', § 4'],

        // ── Bornes de recharge (OCPP) ──────────────────────────────────────
        'CXB-001' => ['titre' => 'Service OCPP arrêté', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Le service permanent qui reçoit les bornes (et les passerelles LoRaWAN) ne tourne pas : elles ne peuvent pas se connecter.",
            'causes' => ["Service non démarré (start.sh, Larka.cmd, larka-ocpp.service)", "Port déjà pris par un autre programme", "Arrêt après une erreur"],
            'remedes' => ["État : `php api/outils/connect-ocpp.php --etat`.", "Production : `sudo systemctl restart larka-ocpp` puis `journalctl -u larka-ocpp -n 50`.", "Vérifier qu'aucun autre programme n'écoute le port 9000 : `ss -ltnp | grep 9000`."],
            'doc' => self::DOC_CX . ', § 9'],
        'CXB-002' => ['titre' => 'Borne inconnue refusée', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Une borne s'est présentée avec un identifiant qui n'est déclaré dans aucune connexion. Elle a été refusée.",
            'causes' => ["Borne neuve pas encore déclarée", "Identifiant saisi différemment (majuscules, tiret)"],
            'remedes' => ["Onglet Connexions : le bandeau des bornes inconnues propose « la déclarer ».", "Recopier l'identifiant exactement comme dans la borne."],
            'doc' => self::DOC_CX . ', § 9'],
        'CXB-003' => ['titre' => 'Borne : mot de passe refusé', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "La borne s'est présentée avec un mot de passe différent de celui réglé dans Larka (HTTP 401).",
            'causes' => ["Mot de passe changé d'un seul côté", "Borne réinitialisée à ses réglages d'usine"],
            'remedes' => ["Saisir le même mot de passe dans la borne et dans sa fiche Larka."],
            'doc' => self::DOC_CX . ', § 9'],
        'CXB-004' => ['titre' => 'Borne déconnectée', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "La borne ne parle plus à Larka depuis plus de trois battements de cœur.",
            'causes' => ["Coupure de réseau ou du modem 4G de la borne", "Borne hors tension", "Adresse du serveur modifiée dans la borne"],
            'remedes' => ["Vérifier l'alimentation et le réseau de la borne.", "Vérifier dans la borne l'adresse affichée dans sa fiche Larka."],
            'doc' => self::DOC_CX . ', § 9'],

        // ── LoRaWAN ────────────────────────────────────────────────────────
        'CXL-001' => ['titre' => 'Aucune passerelle LoRaWAN entendue', 'gravite' => 'erreur', 'qui' => 'service informatique',
            'description' => "Aucune passerelle n'a parlé à Larka depuis dix minutes : aucune trame de capteur ne peut arriver.",
            'causes' => ["Passerelle pas réglée en « Semtech UDP packet forwarder »", "Adresse ou port du serveur faux dans la passerelle", "UDP 1700 filtré par un pare-feu", "Passerelle sur Internet non autorisée (connect.lorawan.passerelles)"],
            'remedes' => ["Dans la passerelle : mode Semtech UDP, serveur = adresse de Larka, ports montant et descendant 1700.", "Demander l'ouverture de l'UDP 1700 vers le serveur (et le retour).", "`php api/outils/connect-ocpp.php --etat` liste les passerelles vues."],
            'doc' => self::DOC_CX . ', § 9 bis'],
        'CXL-002' => ['titre' => 'Capteur LoRaWAN : clé refusée', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "La signature (MIC) de la trame ne correspond pas aux clés saisies pour ce capteur. La trame est ignorée.",
            'causes' => ["AppKey (OTAA) ou NwkSKey / AppSKey (ABP) différentes de celles du capteur", "Deux capteurs déclarés avec le même DevAddr (ABP)"],
            'remedes' => ["Recopier les clés depuis la fiche du fournisseur (32 caractères hexadécimaux).", "OTAA : redémarrer le capteur pour qu'il refasse son join."],
            'doc' => self::DOC_CX . ', § 9 bis'],
        'CXL-003' => ['titre' => 'Capteur LoRaWAN : trame rejouée', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Le compteur de trames du capteur est revenu en arrière. La trame est refusée : ce pourrait être un rejeu.",
            'causes' => ["Capteur ABP redémarré (il recompte depuis 0)", "Trame réellement rejouée par un tiers"],
            'remedes' => ["Capteur ABP : cocher « Accepter un compteur remis à zéro » dans sa connexion.", "Sinon, surveiller : un rejeu sans raison mérite une enquête."],
            'doc' => self::DOC_CX . ', § 9 bis'],
        'CXL-004' => ['titre' => 'Capteur LoRaWAN : activation refusée', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "La demande d'activation (join) du capteur n'a pas abouti : tant qu'il n'est pas activé, il n'envoie aucune mesure.",
            'causes' => ["AppKey fausse", "JoinEUI différent de celui saisi", "DevNonce déjà utilisé (rejeu ou capteur défectueux)", "Aucune passerelle capable d'émettre la réponse"],
            'remedes' => ["Vérifier AppKey et JoinEUI (laisser JoinEUI vide pour l'accepter quel qu'il soit).", "« 🧪 Tester » sur la connexion dit la raison exacte du dernier refus.", "Vérifier que la passerelle envoie bien PULL_DATA (« peut répondre aux capteurs »)."],
            'doc' => self::DOC_CX . ', § 9 bis'],
        'CXL-005' => ['titre' => 'Capteur LoRaWAN non déclaré', 'gravite' => 'info', 'qui' => 'gestionnaire',
            'description' => "Un capteur inconnu cherche à rejoindre le réseau. Rien n'est enregistré tant qu'il n'est pas déclaré.",
            'causes' => ["Capteur neuf installé", "Capteur d'un voisin à portée d'une passerelle"],
            'remedes' => ["Onglet Connexions : « le déclarer » (il faudra son AppKey).", "Capteur étranger : ignorer, il ne peut rien injecter sans ses clés."],
            'doc' => self::DOC_CX . ', § 9 bis'],
        'CXL-006' => ['titre' => 'Capteur radio silencieux', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Aucune trame du capteur depuis plus longtemps que son délai de silence. Une alarme de communication est ouverte.",
            'causes' => ["Pile épuisée", "Capteur déplacé hors de portée", "Passerelle en panne"],
            'remedes' => ["Vérifier la pile (point « batterie » s'il existe) et l'emplacement.", "Vérifier que les passerelles sont entendues (bandeau de la fiche).", "Allonger « Alarme si aucune trame depuis » si le capteur émet rarement."],
            'doc' => self::DOC_CX . ', § 9 bis'],

        // ── wM-Bus ─────────────────────────────────────────────────────────
        'CXW-001' => ['titre' => 'Aucun récepteur radio wM-Bus branché', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "Aucune clé USB réceptrice reconnue sur la machine qui écoute (serveur ou PC releveur).",
            'causes' => ["Clé débranchée", "Clé d'un modèle non reconnu automatiquement", "Pilote USB-série absent (rare sous Windows récent)"],
            'remedes' => ["« Chercher le matériel » dans la fiche (ou `releveur materiel` sur le PC) liste les ports vus.", "Choisir le port et le modèle à la main si la clé n'est pas reconnue.", "Essayer une autre prise USB."],
            'doc' => self::DOC_CX . ', § 9 ter'],
        'CXW-002' => ['titre' => 'Port série : accès refusé', 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Le port de la clé existe, mais le compte du service n'a pas le droit de l'ouvrir, ou un autre programme l'occupe.",
            'causes' => ["Linux : utilisateur du service hors du groupe dialout", "Autre logiciel de relève ouvert sur le même port"],
            'remedes' => ["Linux : `sudo usermod -aG dialout <utilisateur>`, puis redémarrer le service (PHP-FPM, larka-ocpp).", "Fermer l'autre logiciel qui utilise la clé."],
            'doc' => self::DOC_CX . ', § 9 ter'],
        'CXW-003' => ['titre' => 'Compteur radio non entendu', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Le compteur n'a émis aucun télégramme pendant l'écoute de la clé radio : son index n'est pas mis à jour à ce relevé.",
            'causes' => ["Durée d'écoute plus courte que son intervalle d'émission", "Mode radio différent (T1 / C1)", "Compteur hors de portée (sous-sol, armoire métallique)"],
            'remedes' => ["Allonger la durée d'écoute (jusqu'à 45 s).", "Essayer l'autre mode radio.", "Rapprocher la clé (rallonge USB) ou installer le PC releveur plus près."],
            'doc' => self::DOC_CX . ', § 9 ter'],
        'CXW-004' => ['titre' => 'Compteur radio chiffré : clé AES manquante', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Le compteur émet chiffré et sa clé n'est pas saisie : son index ne peut pas être lu.",
            'causes' => ["Clé fournie par le fabricant ou le distributeur d'eau non saisie"],
            'remedes' => ["Demander la clé AES du compteur (fichier de livraison, distributeur).", "La saisir dans la connexion : `identifiant=clé`."],
            'doc' => self::DOC_CX . ', § 9 ter'],
        'CXW-005' => ['titre' => 'Compteur radio chiffré : clé AES incorrecte', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "Le déchiffrement avec la clé saisie ne donne rien de lisible.",
            'causes' => ["Clé d'un autre compteur", "Faute de frappe dans la clé"],
            'remedes' => ["Vérifier que l'identifiant avant le « = » est bien celui du compteur (8 chiffres).", "Recopier la clé (32 caractères hexadécimaux)."],
            'doc' => self::DOC_CX . ', § 9 ter'],
        'CXW-006' => ['titre' => 'Format radio non pris en charge', 'gravite' => 'avertissement', 'qui' => 'fournisseur',
            'description' => "Le compteur utilise un chiffrement ou un format de télégramme que cette version de Larka ne lit pas (modes 7 ou 13, trames compactes, couche ELL).",
            'causes' => ["Compteur récent au profil OMS 4", "Format propre au fabricant"],
            'remedes' => ["Demander au fabricant s'il peut émettre en mode 5 (OMS 3).", "Signaler le modèle au support : il sera ajouté."],
            'doc' => self::DOC_CX . ', § 15'],

        // ── Hors réseau (PC releveur) ──────────────────────────────────────
        'CXH-001' => ['titre' => 'PC releveur : jeton refusé', 'gravite' => 'erreur', 'qui' => 'gestionnaire',
            'description' => "Le collecteur d'un PC s'est présenté avec un jeton que Larka ne reconnaît plus.",
            'causes' => ["Collecteur retéléchargé (nouveau jeton) : l'ancien dossier sur le PC est périmé", "PC releveur supprimé ou désactivé dans Larka"],
            'remedes' => ["Retélécharger le collecteur (Hors réseau → ⬇) et remplacer le dossier sur le PC."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXH-002' => ['titre' => 'PC releveur : relevé refusé', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Un relevé envoyé par le PC a été écarté : trop ancien, mal daté, ou pour une connexion qui ne lui est plus confiée.",
            'causes' => ["Relevés restés plus de 90 jours sur le PC", "Horloge du PC très décalée", "Connexion retirée de la liste du PC"],
            'remedes' => ["Vérifier les appareils confiés au PC (Hors réseau → ✎).", "Mettre le PC à l'heure (synchronisation automatique)."],
            'doc' => self::DOC_CX . ', § 4'],
        'CXH-003' => ['titre' => 'Hotspot de l\'appareil introuvable', 'gravite' => 'avertissement', 'qui' => 'utilisateur',
            'description' => "Le PC n'a pas trouvé (ou pas pu rejoindre) le Wi-Fi de l'appareil à relever.",
            'causes' => ["PC hors de portée", "Nom du Wi-Fi différent (majuscules comprises)", "Clé Wi-Fi changée sur l'appareil", "Appareil éteint"],
            'remedes' => ["Se rapprocher de l'appareil et relancer le relevé.", "Vérifier le nom exact : `netsh wlan show networks` (Windows).", "Mettre à jour la clé Wi-Fi dans la connexion."],
            'doc' => self::DOC_CX . ', § 12'],
        'CXH-004' => ['titre' => "PC releveur jamais vu", 'gravite' => 'info', 'qui' => 'gestionnaire',
            'description' => "Le PC est déclaré, mais son collecteur n'a encore jamais contacté Larka.",
            'causes' => ["Collecteur pas encore installé ou lancé", "Larka injoignable depuis ce PC (adresse, certificat)"],
            'remedes' => ["Sur le PC : double-cliquer releveur.cmd, puis `releveur.cmd etat`.", "Certificat interne : `releveur.cmd confiance`."],
            'doc' => self::DOC_CX . ', § 4'],

        // ── Alarmes, relevés, écritures ────────────────────────────────────
        'CXA-001' => ['titre' => "Demande d'intervention automatique impossible", 'gravite' => 'erreur', 'qui' => 'administrateur',
            'description' => "Une alarme devait créer une demande d'intervention et n'a pas pu. L'alarme, elle, est bien ouverte.",
            'causes' => ["Compte technique de Larka Connect supprimé ou modifié", "Champ obligatoire des demandes ajouté depuis (formulaire personnalisé)"],
            'remedes' => ["Créer la demande à la main depuis l'alarme.", "Vérifier les champs obligatoires des demandes (Configuration).", "Transmettre le détail de l'entrée au support si la cause n'est pas claire."],
            'doc' => self::DOC_CX . ', § 6'],
        'CXA-002' => ['titre' => 'Écriture vers un équipement refusée', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "La consigne ou la commande n'a pas été envoyée, ou l'équipement l'a refusée.",
            'causes' => ["Valeur hors de la plage permise du point", "Point non déclaré inscriptible", "Équipement qui refuse l'écriture (priorité BACnet, registre en lecture seule)"],
            'remedes' => ["Vérifier la plage permise et l'option « écriture autorisée » du point.", "BACnet : vérifier qu'une priorité plus forte ne commande pas déjà l'objet.", "Le journal des écritures (onglet Journal) garde la réponse de l'équipement."],
            'doc' => self::DOC_CX . ', § 8'],
        'CXA-003' => ['titre' => 'Relevé automatique en anomalie', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "L'index transmis au module Énergie paraît anormal (baisse, consommation hors norme). Il attend une validation.",
            'causes' => ["Compteur remplacé (index remis à zéro)", "Valeur aberrante lue ce jour-là", "Fuite ou consommation réellement exceptionnelle"],
            'remedes' => ["Relevés automatiques → à valider : accepter, corriger ou rejeter.", "Compteur remplacé : saisir le nouvel index de départ dans le module Énergie."],
            'doc' => self::DOC_CX . ', § 7'],
        'CXA-004' => ['titre' => 'Alarme technique', 'gravite' => 'avertissement', 'qui' => 'gestionnaire',
            'description' => "Une règle d'alarme s'est déclenchée sur une valeur suivie, ou l'équipement a signalé un défaut.",
            'causes' => ["Valeur hors seuil (température, CO₂, pression…)", "Défaut remonté par l'équipement"],
            'remedes' => ["Alarmes techniques : voir la valeur qui l'a déclenchée et l'équipement concerné.", "Traiter sur place ou par la demande d'intervention créée, puis acquitter avec un commentaire."],
            'doc' => self::DOC_CX . ', § 6'],
    ];

    /**
     * Motifs de reconnaissance, du plus précis au plus général. Ils portent sur
     * le message (et le message de l'exception). Le premier qui correspond gagne.
     */
    private const MOTIFS = [
        // Base de données
        ['BDD-002', '/database (table )?is locked|SQLSTATE\[HY000\]: General error: 5\b|deadlock|lock wait timeout|could not obtain lock/i'],
        ['BDD-005', '/attempt to write a readonly database|read-only transaction|readonly database/i'],
        ['BDD-003', '/no such (table|column)|undefined (table|column)|relation "[^"]+" does not exist|column "[^"]+" (of relation "[^"]+" )?does not exist|Unknown column|Base table or view not found|doesn\'t exist/i'],
        ['BDD-004', '/UNIQUE constraint failed|Duplicate entry|duplicate key value|FOREIGN KEY constraint failed|violates foreign key|Integrity constraint violation|value too long|Data too long/i'],
        ['BDD-001', '/SQLSTATE\[08|could not connect to server|Connection refused.*(5432|3306)|unable to open database file|Access denied for user|password authentication failed|base injoignable|could not translate host name/i'],
        // Disque, fichiers, PHP
        ['FIC-002', '/No space left on device|disk full|disque plein|Disk quota exceeded/i'],
        ['FIC-001', '/écriture impossible dans|failed to open stream: Permission denied|Permission denied|Read-only file system|is not writable|non inscriptible/i'],
        ['SYS-003', '/Allowed memory size of \d+ bytes exhausted|Out of memory/i'],
        ['SYS-004', '/Maximum execution time of \d+ seconds? exceeded/i'],
        ['SYS-005', '/Call to undefined function (mb_|openssl_|gz|imagecreate|curl_|zip_|sodium_)|Class "(ZipArchive|PDO|SQLite3|IntlDateFormatter)" not found|could not find driver|extension (PHP )?(manquante|absente)|Extension\(s\) PHP manquante/i'],
        ['SYS-006', '/config\.json (introuvable|illisible|invalide)|Syntax error.*config|JSON invalide dans config/i'],
        // Sécurité
        ['SEC-002', '/trop de tentatives|compte (temporairement )?bloqué|verrouill(é|age) .*tentatives|too many (login )?attempts/i'],
        ['SEC-001', '/identifiants? (ou mot de passe )?incorrects?|mot de passe incorrect|login_fail|invalid credentials/i'],
        ['SEC-005', '/confirm(ation|ez) (d\'|de votre )?identité|confirmer_identite|mot de passe à confirmer/i'],
        ['SEC-006', '/csrf/i'],
        ['SEC-007', '/trop de requêtes|rate limit|too many requests/i'],
        ['SEC-008', '/oauth|openid|entra id|AADSTS\d+|invalid_client|redirect_uri/i'],
        ['SEC-009', '/injection|tentative suspecte|path traversal|chemin (hors|détourné)|archive piégée/i'],
        ['SEC-003', '/session expirée|non authentifié|authentification requise|Non connecté/i'],
        ['SEC-004', '/accès refusé|access_denied|réservé (aux|au) |droits insuffisants|forbidden|non autorisé pour votre rôle/i'],
        // Messagerie
        ['MEL-001', '/SMTP|courriel non envoyé|échec d\'envoi du (courriel|mail)|mail\(\) failed|Could not authenticate/i'],
        ['MEL-002', '/push.*(échec|expir|410|404)|abonnement push|VAPID/i'],
        // Tâches de fond
        ['TAC-001', '/sauvegarde.*(échec|impossible|échouée)|pg_dump/i'],
        ['TAC-003', '/mise à jour (refusée|impossible|arrêtée)|signature (invalide|absente)|prérequis non remplis/i'],
        // Larka Connect — collecte
        ['CXC-007', '/Larka Connect est (désactivé|fermé)/i'],
        ['CXC-001', '/collecte (automatique )?ne passe pas|planificateur.*(arrêté|ne passe)/i'],
        ['CXC-005', '/budget|passage.*(trop long|reporté)|reportée?s? au (tour|passage) suivant/i'],
        // Larka Connect — réseau
        ['CXR-003', '/inconnu du serveur \(DNS\)|getaddrinfo|Name or service not known|php_network_getaddresses|Temporary failure in name resolution/i'],
        ['CXR-001', '/REFUSE la connexion|Connection refused|connexion refusée/i'],
        ['CXR-004', '/aucune route|No route to host|Network is unreachable/i'],
        ['CXR-006', '/certificate|certificat|SSL operation failed|tls handshake/i'],
        ['CXR-002', '/ne répond pas : probablement filtré|timed out|Connection timed out|délai dépassé|sans réponse|Operation timed out/i'],
        ['CXR-005', "/Adresse de l'équipement non renseignée|Adresse invalide : |adresse « [^»]* » refusée/i"],
        // Larka Connect — protocoles
        ['CXP-001', '/exception 0x02|adresse de registre invalide/i'],
        ['CXP-002', '/exception 0x0[AB]|passerelle : (chemin indisponible|l\'équipement derrière)/i'],
        ['CXP-003', '/exception 0x0[1346]|fonction non gérée|valeur ou quantité refusée|défaut interne de l\'équipement|équipement occupé/i'],
        ['CXP-004', '/Aucune réponse BACnet|BACnet.*(silence|aucune réponse)/i'],
        ['CXP-005', '/unknown-object|unknown-property|objet (BACnet )?inconnu|propriété inconnue|adresse BACnet incomplète/i'],
        ['CXP-007', '/abonnement refusé par le broker/i'],
        ['CXP-006', '/Broker MQTT : |CONNACK.*refus/i'],
        ['CXP-008', '/pas en MQTT|ne parle pas|réponse inattendue sur ce port/i'],
        ['CXP-009', '/HTTP 40[13]\b/i'],
        ['CXP-010', '/HTTP (404|422|5\d\d)\b|réponse qui n\'est pas du JSON|absent de la liste de l\'API/i'],
        ['CXP-011', '/hors des racines autorisées|csv_racines/i'],
        ['CXP-012', '/Aucun fichier « |ligne de titres introuvable|encore en cours d\'écriture/i'],
        // Bornes
        ['CXB-001', '/service OCPP (est désactivé|ne tourne pas)/i'],
        ['CXB-002', '/borne inconnue|identifiant (de borne )?inconnu/i'],
        ['CXB-003', '/borne.*(401|mot de passe)/i'],
        ['CXB-004', '/Borne « [^»]+ » déconnectée|ne s\'est jamais connectée|aucun message de la borne|borne silencieuse/i'],
        // LoRaWAN
        ['CXL-001', '/Aucune passerelle (LoRaWAN )?n\'a (parlé|encore parlé)|serveur LoRaWAN intégré est désactivé|service d\'écoute ne tourne pas/i'],
        ['CXL-003', '/compteur déjà vu|Rejeu, ou capteur redémarré/i'],
        ['CXL-004', '/demande de join|DevNonce|JoinEUI|ne s\'est pas encore activé/i'],
        ['CXL-002', '/MIC ne correspond pas|AppKey ne correspond pas|clés différentes, ou trame altérée/i'],
        ['CXL-005', '/capteur (LoRaWAN )?non déclaré/i'],
        ['CXL-006', '/aucun message du capteur|capteur silencieux|Aucune trame reçue/i'],
        // wM-Bus
        ['CXW-002', '/accès refusé à \/dev|groupe « dialout »|est occupé par un autre programme|ne s\'ouvre pas \(occupé/i'],
        ['CXW-001', '/aucun récepteur wM-Bus|absent : la clé est-elle branchée|clé radio wM-Bus non branchée|PowerShell indisponible : lecture du port/i'],
        ['CXW-004', '/ajoutez la clé AES/i'],
        ['CXW-005', '/clé AES du compteur [0-9A-F]+ incorrecte/i'],
        ['CXW-006', '/chiffrement mode \d+ non pris en charge|format de télégramme non pris en charge/i'],
        ['CXW-003', '/compteur [0-9A-F]+ non entendu|Rien reçu en \d|aucun télégramme de compteur/i'],
        // Hors réseau
        ['CXH-001', '/Jeton refusé|Jeton illisible|PC releveur est désactivé/i'],
        ['CXH-002', '/non confiée à ce PC|relevé mal daté ou trop ancien/i'],
        ['CXH-003', '/hotspot « [^»]* » introuvable/i'],
        // Alarmes, relevés, écritures
        ['CXA-001', '/Demande d\'intervention automatique impossible/i'],
        ['CXA-002', '/Écriture refusée|hors de la plage permise|n\'est pas inscriptible/i'],
        ['CXA-003', '/relevé en anomalie|index qui baisse/i'],
        // Navigateur
        ['NAV-002', '/Impossible de joindre le serveur|Failed to fetch|NetworkError|Load failed/i'],
    ];

    // ══ Lecture ════════════════════════════════════════════════════════════

    /** Fiche complète d'un code, ou null. */
    public static function fiche(?string $code): ?array
    {
        if ($code === null || !isset(self::CATALOGUE[$code])) return null;
        $f = self::CATALOGUE[$code];
        $dom = substr($code, 0, 3);
        return ['code' => $code, 'domaine' => $dom, 'domaine_libelle' => self::DOMAINES[$dom] ?? $dom] + $f;
    }

    /** Catalogue complet, trié, pour l'écran et la documentation. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (array_keys(self::CATALOGUE) as $c) $out[] = self::fiche($c);
        return $out;
    }

    public static function existe(?string $code): bool
    {
        return $code !== null && isset(self::CATALOGUE[$code]);
    }

    /**
     * Reconnaît le code d'une entrée d'après ce que l'on sait d'elle. Rend null
     * pour ce qui n'est pas un incident (une entrée d'information ordinaire).
     *
     * @param string      $message    message de l'entrée
     * @param string      $canal      canal du journal (api, php, client, connect…)
     * @param string      $niveau     niveau du journal
     * @param int|null    $http       code HTTP de la réponse, s'il y en a un
     * @param string      $exception  message de l'exception attachée
     * @param string      $type       classe de l'exception
     */
    public static function reconnaitre(string $message, string $canal = '', string $niveau = '', ?int $http = null,
                                       string $exception = '', string $type = ''): ?string
    {
        // Une entrée d'information ordinaire n'est pas un incident : pas de code
        // reconnu (l'appelant peut toujours en poser un explicitement).
        $incident = in_array($niveau, ['warning', 'error', 'critique'], true) || ($http !== null && $http >= 400)
                 || in_array($canal, ['client'], true) || $niveau === '';
        if (!$incident) return null;
        $texte = trim($message . ' ' . $exception);
        if ($texte !== '') {
            // Un message de la supervision se lit d'abord avec les motifs de la supervision
            // (« identifiant ou mot de passe incorrect » d'un broker MQTT n'est pas un échec
            // de connexion à Larka) ; ailleurs, les motifs généraux passent d'abord.
            $cx = array_filter(self::MOTIFS, fn($m) => str_starts_with($m[0], 'CX'));
            $autres = array_filter(self::MOTIFS, fn($m) => !str_starts_with($m[0], 'CX'));
            foreach ($canal === 'connect' ? array_merge($cx, $autres) : array_merge($autres, $cx) as [$code, $motif]) {
                if (@preg_match($motif . 'u', $texte) === 1) return $code;
            }
        }
        // Rien de reconnu : un repli selon la nature de l'entrée.
        if ($canal === 'client') return 'NAV-001';
        if ($type === 'PDOException' || str_contains($exception, 'SQLSTATE')) return 'BDD-003';
        if ($canal === 'php') {
            if ($niveau === 'critique') return str_starts_with($message, 'Erreur fatale') ? 'SYS-002' : 'SYS-001';
            if ($niveau === 'warning') return 'SYS-007';
            return null;
        }
        if ($http !== null && $http >= 400) {
            return match (true) {
                $http === 401 => 'SEC-003',
                $http === 403 => 'SEC-004',
                $http === 404 => 'API-404',
                $http === 409 => 'API-409',
                $http === 413 => 'API-413',
                $http === 428 => 'SEC-005',
                $http === 429 => 'SEC-007',
                $http >= 500  => 'API-500',
                default       => 'API-400',
            };
        }
        if (in_array($niveau, ['error', 'critique'], true)) return 'SYS-001';
        return null;
    }

    /** Première marche à suivre d'un code (pour une ligne de journal texte). */
    public static function premierRemede(?string $code): ?string
    {
        $f = $code !== null ? (self::CATALOGUE[$code] ?? null) : null;
        return $f ? ($f['remedes'][0] ?? null) : null;
    }

    /**
     * Contrôle du catalogue (épreuves) : champs présents et bornés, vocabulaire
     * fermé, motifs valides, aucun motif vers un code inconnu.
     * @return list<string> problèmes trouvés
     */
    public static function verifier(): array
    {
        $pb = [];
        foreach (self::CATALOGUE as $c => $f) {
            if (!preg_match('/^[A-Z]{3}-\d{3}$/', $c)) $pb[] = "$c : format de code";
            if (!isset(self::DOMAINES[substr($c, 0, 3)])) $pb[] = "$c : domaine inconnu";
            foreach (['titre', 'gravite', 'qui', 'description', 'causes', 'remedes', 'doc'] as $k) if (empty($f[$k])) $pb[] = "$c : $k manquant";
            if (!in_array($f['gravite'] ?? '', self::GRAVITES, true)) $pb[] = "$c : gravité « {$f['gravite']} »";
            if (!in_array($f['qui'] ?? '', self::QUI, true)) $pb[] = "$c : qui « {$f['qui']} »";
            if (mb_strlen((string)($f['titre'] ?? '')) > 70) $pb[] = "$c : titre trop long";
        }
        foreach (self::MOTIFS as [$c, $m]) {
            if (!isset(self::CATALOGUE[$c])) $pb[] = "motif vers un code inconnu : $c";
            if (@preg_match($m . 'u', '') === false) $pb[] = "motif invalide pour $c";
        }
        return $pb;
    }
}
