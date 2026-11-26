<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Point d'entrée API (routeur principal)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Reçoit toutes les requêtes via ?action=xxx et les dispatch vers les routes.
 *
 * FLUX :
 *   1. Charge config.php (lecture config.json → constantes)
 *   2. Charge Database.php (connexion PDO, init schéma)
 *   3. Applique headers CORS / sécurité HTTP
 *   4. Dispatch vers le bon fichier routes/*.php selon $action
 *
 * FONCTIONS UTILITAIRES :
 *   - json_ok($data)         → réponse JSON succès
 *   - json_error($msg,$code) → réponse JSON erreur
 *   - get_body()             → lecture du body JSON de la requête
 *   - require_auth()         → vérifie la session utilisateur
 *   - require_role($user,$r) → vérifie le rôle (Admin a tous les droits)
 *
 * ROUTES INCLUSES :
 *   auth.php, inventaire.php, maintenance.php, energie.php, gestion.php,
 *   stats.php, documents.php, archives.php, france.php, chorus.php,
 *   assistant.php, superadmin.php, migration.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

// Gestionnaire global : toutes les erreurs PHP produisent du JSON valide
set_error_handler(function ($severity, $message, $file, $line) {
    // Ignorer les erreurs supprimées avec @
    if (!(error_reporting() & $severity)) return true;
    // Une dépréciation annonce un changement futur de PHP, ce n'est pas une panne.
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) return true;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) {
        header_remove('Content-Encoding'); header_remove('Content-Length');   // réponse gzip déjà préparée
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    // FIX SÉCURITÉ (F1) : ne pas divulguer le détail de l'exception au client
    // (chemins, requêtes SQL, structure interne). On journalise côté serveur et
    // on renvoie un identifiant de corrélation. Le détail n'est renvoyé que si
    // securite.debug_errors=true (constante DEBUG_ERRORS).
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $showDetail = defined('DEBUG_ERRORS') && DEBUG_ERRORS;
    echo json_encode([
        'success' => false,
        'error'   => $showDetail
            ? ('Erreur serveur : ' . $e->getMessage())
            : ('Erreur serveur interne. Référence : ' . $ref),
        'ref'     => $ref,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level()) ob_end_clean();
        if (!headers_sent()) {
            header_remove('Content-Encoding'); header_remove('Content-Length');   // réponse gzip déjà préparée
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        // FIX SÉCURITÉ (F1) : message générique + référence, détail en log.
        $ref = bin2hex(random_bytes(4));
        error_log('[Larka][' . $ref . '] Fatal: ' . $err['message']
            . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'));
        $showDetail = defined('DEBUG_ERRORS') && DEBUG_ERRORS;
        echo json_encode([
            'success' => false,
            'error'   => $showDetail
                ? ('Erreur fatale : ' . $err['message'])
                : ('Erreur fatale interne. Référence : ' . $ref),
            'ref'     => $ref,
        ], JSON_UNESCAPED_UNICODE);
    }
});

// S'assurer que le dossier data existe
$_dataDir = __DIR__ . '/../data';
if (!is_dir($_dataDir)) @mkdir($_dataDir, 0755, true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SecurityLog.php';
require_once __DIR__ . '/Journal.php';
require_once __DIR__ . '/Database.php';

// ── Sauvegardes automatiques : filet sous PHP-FPM ────────────────────────────
// Les déclencheurs normaux sont la tâche cron (production), la minuterie
// systemd, le planificateur de start.sh et le lanceur Windows. Si aucun n'est
// passé depuis 10 minutes (hébergement sans cron…), une requête s'en charge —
// APRÈS avoir rendu sa réponse (fastcgi_finish_request) : personne n'attend.
if (function_exists('fastcgi_finish_request')) {
    register_shutdown_function(static function (): void {
        $e = @json_decode((string)@file_get_contents(__DIR__ . '/../data/run/sauvegardes-auto.json'), true);
        $passage = is_array($e) && !empty($e['_passage']) ? (int)strtotime((string)$e['_passage']) : 0;
        if (time() - $passage < 600) return;
        @fastcgi_finish_request();
        try {
            require_once __DIR__ . '/SauvegardesAuto.php';
            LarkaSauvegardesAuto::executer();
        } catch (\Throwable $x) {
            error_log('[Larka] sauvegardes automatiques : ' . $x->getMessage());
        }
    });
}

// ── Larka Connect : même filet pour la collecte des équipements ─────────────
// Les déclencheurs normaux (cron chaque minute, minuterie systemd, start.sh,
// lanceur Windows) font la collecte. Sans aucun d'eux depuis 2 minutes, une
// requête s'en charge après avoir rendu sa réponse. Un verrou empêche deux
// passages simultanés ; rien ne se passe si Larka Connect est désactivé.
if (function_exists('fastcgi_finish_request')) {
    register_shutdown_function(static function (): void {
        $e = @json_decode((string)@file_get_contents(__DIR__ . '/../data/run/connect-collecte.json'), true);
        $passage = is_array($e) && !empty($e['_passage']) ? (int)strtotime((string)$e['_passage']) : 0;
        if (time() - $passage < 120) return;
        if (!function_exists('cfg') || !filter_var(cfg('connect', 'actif') ?? false, FILTER_VALIDATE_BOOLEAN)) return;
        @fastcgi_finish_request();
        try {
            require_once __DIR__ . '/connect/Connect.php';
            Connect::charger();
            ConnectCollecte::executer();
        } catch (\Throwable $x) {
            error_log('[Larka] collecte Larka Connect : ' . $x->getMessage());
        }
    });
}

// ── Journalisation : chrono + capteurs globaux ───────────────────────────────
// Installés le plus tôt possible pour que même une erreur de bootstrap soit
// tracée. Les entrées émises avant l'ouverture de la base sont mises en tampon
// par Journal et écrites dès que la connexion est disponible.
Journal::demarrerChrono();

// Exceptions non rattrapées.
set_exception_handler(function (\Throwable $e) {
    $codeErr = CodesErreur::reconnaitre('Exception non rattrapée : ' . $e->getMessage(), 'php', 'critique', 500,
                                        $e->getMessage(), get_class($e)) ?? 'SYS-001';
    Journal::critique('php', 'Exception non rattrapée : ' . $e->getMessage(), [
        'exception' => $e, 'codeHttp' => 500, 'dureeMs' => Journal::dureeMs(), 'code' => $codeErr,
    ]);
    if (!headers_sent()) { http_response_code(500); }
    if (ob_get_level()) ob_clean();
    // Le détail reste au journal ; l'utilisateur reçoit le code (ce qui s'est passé,
    // en clair, et quoi faire) et la référence à transmettre.
    $fiche = CodesErreur::fiche($codeErr);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur interne' . ($fiche ? ' — ' . $fiche['titre'] : '')
                      . ' (' . $codeErr . '). Référence : ' . Journal::requestId(),
                      'code' => $codeErr, 'reference' => Journal::requestId()], JSON_UNESCAPED_UNICODE);
    exit;
});

// Erreurs PHP (warnings, notices, deprecated) — converties en entrées de journal.
set_error_handler(function ($niveau, $message, $fichier, $ligne) {
    if (!(error_reporting() & $niveau)) return false; // respecte @ et error_reporting
    $map = [
        E_WARNING => Journal::WARNING, E_USER_WARNING => Journal::WARNING,
        E_NOTICE  => Journal::INFO,    E_USER_NOTICE  => Journal::INFO,
        E_DEPRECATED => Journal::INFO, E_USER_DEPRECATED => Journal::INFO,
    ];
    Journal::log($map[$niveau] ?? Journal::WARNING, 'php', $message, [
        'fichier' => basename((string)$fichier), 'ligne' => $ligne, 'niveauPhp' => $niveau,
    ]);
    // Dépréciation : journalisée, jamais fatale. PHP 8.5 a déprécié des fonctions
    // devenues sans effet (curl_close, setAccessible…) : les transformer en
    // exceptions faisait échouer la vérification des mises à jour, l'assistant,
    // les notifications… sur un serveur à jour.
    if ($niveau === E_DEPRECATED || $niveau === E_USER_DEPRECATED) return true;
    // Pour le reste, on CONSERVE la stratégie « fail-fast » installée plus haut :
    // toute erreur PHP devient une exception, qui produit une réponse JSON
    // propre. Journaliser ne doit pas changer le comportement de l'application,
    // seulement le rendre observable.
    throw new ErrorException($message, 0, $niveau, $fichier, $ligne);
});

// Erreurs fatales + clôture de requête. Les routes se terminent par exit(),
// donc c'est le SEUL endroit où l'on peut journaliser la fin d'une requête.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Journal::critique('php', 'Erreur fatale : ' . $err['message'], [
            'fichier' => basename((string)$err['file']), 'ligne' => $err['line'],
            'codeHttp' => 500, 'dureeMs' => Journal::dureeMs(),
        ]);
        return;
    }
    // Requête terminée normalement : une ligne d'accès par appel API.
    if (!defined('LARKA_ACTION_COURANTE')) return;
    $code = http_response_code() ?: 200;
    $duree = Journal::dureeMs();
    // Les erreurs serveur et les lenteurs méritent d'être visibles d'un coup d'œil.
    // Sauf les routes LONGUES PAR NATURE : le pont du serveur de supervision attend
    // jusqu'à 20 s qu'une demande arrive, la réponse en flux de l'assistant dure le
    // temps de la réponse. Comptées « lentes », elles remplissaient le journal
    // (Routes lentes, avertissements) d'attentes normales et y noyaient les vraies.
    $lente = $duree !== null && $duree > 2000
          && !in_array((string)LARKA_ACTION_COURANTE, ['supervision_pont', 'assistant_stream'], true);
    $niveau = $code >= 500 ? Journal::ERROR
            : ($code >= 400 ? Journal::WARNING
            : ($lente ? Journal::WARNING : Journal::INFO));
    // Requête refusée : le message donné à l'utilisateur figure dans la ligne (avant :
    // la route seule, sans savoir pourquoi elle avait été refusée).
    $refus = $code >= 400 ? (string)($GLOBALS['_larka_refus'] ?? '') : '';
    Journal::log($niveau, 'api', LARKA_ACTION_COURANTE . ($refus !== '' ? ' — ' . $refus : ''), [
        'action'   => LARKA_ACTION_COURANTE,
        'codeHttp' => $code,
        'dureeMs'  => $duree,
        'lente'    => $lente ?: null,
    ]);

    // ── Trace de sécurité des actions sensibles ─────────────────────────────
    //
    // LES ACTIONS LES PLUS DANGEREUSES NE LAISSAIENT AUCUNE TRACE DE SÉCURITÉ.
    // Seule la bascule de tenant appelait SecurityLog. Supprimer un tenant (et
    // sa base), purger une base, importer un dump, ajouter un super admin,
    // installer une mise à jour, modifier config.json, changer un rôle… ne
    // passaient que par le journal EN BASE — celui-là même qu'une purge, un
    // import ou une suppression de tenant peut faire disparaître.
    //
    // Toute écriture sur ces actions est désormais doublée dans
    // data/security/audit.log (JSONL), qu'aucune route n'efface. Succès ET
    // échecs : une série de refus sur ces routes est un signal en soi.
    // Couverture automatique, comme l'audit des écritures dans Database.
    $act = (string)LARKA_ACTION_COURANTE;
    $met = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($met !== 'GET' && $met !== 'OPTIONS' && class_exists('SecurityLog')) {
        $sensibles = [
            'utilisateurs', 'config_serveur', 'configuration', 'db_migrate',
            'maj_installer', 'maj_restaurer', 'maj_canal', 'plans_upload_limits',
            'ext_installer', 'ext_desinstaller', 'ext_activer', 'ext_roles',
            'ext_televerser', 'ext_importer', 'ext_installer_paquet',
            'push_generate_vapid', 'push_send', 'thematique_supprimer',
            'smtp_test', 'db_resync_sequences',
            // Larka Connect : secrets des connexions, écritures vers les équipements.
            'connect_passerelles', 'connect_ecrire', 'connect_commande_borne',
        ];
        // superadmin_login a ses propres évènements, plus précis (voir route).
        $estSensible = in_array($act, $sensibles, true)
            || (str_starts_with($act, 'superadmin_') && $act !== 'superadmin_login');
        if ($estSensible) {
            $u  = $_SESSION['user'] ?? [];
            $sa = $_SESSION['superadmin'] ?? [];
            SecurityLog::audit('sensitive_action', [
                'action'    => $act,
                'method'    => $met,
                'http_code' => $code,
                'success'   => $code < 400,
                'login'     => !empty($sa['authenticated'])
                                 ? 'superadmin:' . ($sa['login'] ?? '?')
                                 : (string)($u['Login'] ?? ''),
                'role'      => !empty($sa['authenticated']) ? 'SuperAdmin' : (string)($u['Role'] ?? ''),
                'tenant'    => (string)($_SESSION['tenant_key'] ?? $_SESSION['forced_tenant'] ?? 'default'),
                // L'identifiant de la cible (?id=) aide à reconstituer qui a
                // touché quoi, sans copier aucune donnée métier.
                'target_user' => isset($_GET['id']) ? (string)(int)$_GET['id'] : null,
            ]);
        }
    }
});

if (ob_get_level()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

// ── CORS ────────────────────────────────────────────────────────────────────
$_reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', CORS_ORIGINS)) {
    // FIX SÉCURITÉ : `Access-Control-Allow-Origin: *` est incompatible avec
    // credentials. Et reflêter l'origin du client AVEC credentials est une
    // CORS misconfiguration classique (n'importe quel site peut faire des
    // requêtes authentifiées). Si la config est `origins:["*"]` + credentials,
    // on REFUSE et on log l'erreur — l'admin doit fournir une whitelist explicite.
    if (CORS_CREDENTIALS) {
        error_log('Larka SECURITY: refus CORS — cors.origins:["*"] avec cors.allow_credentials:true. Listez explicitement les origines autorisées.');
        // On n'envoie PAS de header CORS → le navigateur refusera la requête cross-origin.
        // Les requêtes same-origin continuent de fonctionner.
    } else {
        // Pas de credentials : le wildcard est sûr.
        header('Access-Control-Allow-Origin: *');
    }
} elseif ($_reqOrigin && in_array($_reqOrigin, CORS_ORIGINS)) {
    header('Access-Control-Allow-Origin: ' . $_reqOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: ' . CORS_METHODS);
header('Access-Control-Allow-Headers: ' . CORS_HEADERS);
if (CORS_CREDENTIALS) header('Access-Control-Allow-Credentials: true');
// Synchronisation de l'app mobile : authentifiée par jeton (jamais par cookie), ouverte à
// toute origine — l'app tourne dans son propre WebView (https://localhost).
if (str_starts_with((string)($_GET['action'] ?? ''), 'synchro_')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Larka-Synchro');
    header_remove('Access-Control-Allow-Credentials');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── En-têtes sécurité HTTP ───────────────────────────────────────────────────
header("Content-Security-Policy: "        . SEC_CSP);
header("X-Frame-Options: "                . SEC_X_FRAME);
header("X-Content-Type-Options: "         . SEC_X_CONTENT_TYPE);
header("Referrer-Policy: "                . SEC_REFERRER);
header("Permissions-Policy: camera=(self), microphone=(), geolocation=()");
header("X-Permitted-Cross-Domain-Policies: none");
header("Cache-Control: no-store, no-cache, must-revalidate, private");
header("Pragma: no-cache");
if (SEC_HSTS) header("Strict-Transport-Security: max-age=" . SEC_HSTS_MAX_AGE . "; includeSubDomains");

// ── Protection CSRF : vérifier Content-Type JSON sur les requêtes mutantes ──
// Liste blanche des actions qui acceptent légitimement multipart/form-data
// (upload de fichier). Pour toutes les autres, on impose JSON.
$MULTIPART_ALLOWED_ACTIONS = [
    'superadmin_tenant_import_db',
    'plans_upload_fond',
    'plans_import',
    'ext_televerser',   // paquets .larka (extensions communautaires)
    'ext_importer',     // dépôt d'un paquet depuis l'écran Extensions
    'thematique_importer',  // thèmes et traductions, déposés depuis Apparence
    'fond_televerser',      // image de fond, déposée depuis Apparence
    'ext_fichier_importer', // fichiers d'un module (dossiers déclarés)
    'urgences_media_upload',// pièces jointes des procédures d'urgence
];

// CETTE LISTE DOIT SUIVRE LES ROUTES.
// Une action qui lit $_FILES sans figurer ici reçoit un 403
// « Content-Type non autorisé » AVANT d'être exécutée. Vu de l'utilisateur :
// le téléversement échoue sans explication utile, et rien dans la route
// concernée ne permet de comprendre pourquoi — le refus a lieu bien en amont.
//
// « ext_fichier_importer » et « urgences_media_upload » manquaient. Pour
// vérifier la liste après avoir ajouté une route :
//   grep -rn '\$_FILES' api/routes/ | sed 's/:.*//' | sort -u
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $_currentAction = $_GET['action'] ?? $_POST['action'] ?? '';
    $_isMultipartWhitelisted = in_array($_currentAction, $MULTIPART_ALLOWED_ACTIONS, true);

    // LA « LISTE BLANCHE STRICTE » ÉTAIT UNE RECHERCHE DE SOUS-CHAÎNE.
    // Le test était str_contains($ct, 'application/json') : il acceptait
    // « text/plain; charset=application/json », dont le type RÉEL (« essence »
    // au sens Fetch) est text/plain — un type « simple » CORS, envoyable
    // cross-site SANS pré-vérification. Comme get_body() lit le JSON quel que
    // soit le type annoncé, la protection anti-CSRF tombait d'un paramètre.
    // On compare désormais le type MIME extrait (avant le premier « ; »),
    // à l'égalité stricte. Idem pour le multipart des téléversements.
    $_ctEssence = strtolower(trim(explode(';', (string)$ct, 2)[0]));
    if ($ct !== '' && $_ctEssence !== 'application/json') {
        // Tout Content-Type non-JSON est refusé sur une requête mutante, SAUF
        // le multipart des actions d'upload explicitement listées.
        // (Requêtes sans body → pas de Content-Type → traitées plus bas.)
        $isWhitelistedMultipart = $_isMultipartWhitelisted
            && $_ctEssence === 'multipart/form-data';
        if (!$isWhitelistedMultipart) {
            if (class_exists('SecurityLog')) {
                // Signal utile : soit un client mal écrit, soit une tentative
                // de requête intersite. Le type reçu est tronqué (pas de PII).
                SecurityLog::audit('access_denied', [
                    'action'    => (string)$_currentAction,
                    'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
                    'reason'    => 'content_type_refuse:' . substr($_ctEssence, 0, 60),
                    'http_code' => 403,
                ]);
            }
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Content-Type non autorisé.']);
            exit;
        }
    }

    // « PAS DE CONTENT-TYPE » NE VEUT PAS DIRE « PAS DE CORPS ».
    // Le commentaire ci-dessus supposait qu'une requête sans Content-Type était
    // une requête sans corps. C'est faux : fetch() envoie un ArrayBuffer ou un
    // Blob sans type SANS aucun en-tête Content-Type, ce qui en fait une requête
    // « simple » (pas de pré-vérification CORS) — et get_body() lit le JSON de
    // toute façon. On refuse donc un corps non vide qui n'annonce pas son type.
    // Tous les clients de Larka déclarent application/json (ou multipart).
    if ($ct === '') {
        $_len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($_len === 0 && !isset($_SERVER['CONTENT_LENGTH'])) {
            // Transfert « chunked » : pas de longueur annoncée, on mesure.
            $_len = strlen((string)file_get_contents('php://input'));
        }
        if ($_len > 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Content-Type manquant.']);
            exit;
        }
    }
}

// ── Helpers globaux ───────────────────────────────────────────────────────────
function json_ok($data): void {
    if (ob_get_level()) ob_clean();
    json_sortie(json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE)); exit;
}
function json_error($message, $code = 400, ?string $codeErreur = null): void {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    // Le message refusé est gardé pour la ligne de journal de la requête (elle ne
    // disait que la route et « 400 ») ; son code d'erreur part avec la réponse :
    // l'écran affiche le code et « Comment y remédier ».
    $GLOBALS['_larka_refus'] = (string)$message;
    $c = $codeErreur;
    if ($c === null && class_exists('CodesErreur')) { try { $c = CodesErreur::reconnaitre((string)$message, 'api', 'warning', (int)$code); } catch (\Throwable $e) {} }
    json_sortie(json_encode(['success'=>false,'error'=>$message] + ($c ? ['code' => $c] : []), JSON_UNESCAPED_UNICODE)); exit;
}
/**
 * Écrit une réponse JSON, compressée en gzip quand c'est utile.
 *
 * LES LISTES PARTAIENT NON COMPRESSÉES.
 * Le serveur intégré (Larka.cmd, start.sh) ne compresse rien : « demandes »
 * pesait 2,8 Mo, « biens » 1,1 Mo, et la page Historique en chargeait 10.
 * Le JSON se compresse d'un facteur 8 à 15. Derrière nginx ou Apache, la
 * réponse arrive déjà encodée et le serveur ne la recompresse pas.
 */
function json_sortie($json): void {
    $json = (string)$json;
    if (strlen($json) > 1400 && !headers_sent() && !ini_get('zlib.output_compression')
        && function_exists('gzencode')
        && str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
        $gz = gzencode($json, 4);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            header('Content-Length: ' . strlen($gz));
            echo $gz;
            return;
        }
    }
    echo $json;
}
function get_body(): array {
    // Corps d'un appel relayé par le lien chiffré (le corps HTTP, lui, est l'enveloppe scellée).
    if (isset($GLOBALS['_larka_corps_delegue']) && is_array($GLOBALS['_larka_corps_delegue'])) return $GLOBALS['_larka_corps_delegue'];
    $raw = file_get_contents('php://input');
    // La limite était fixée à 10 Mo, alors que les documents (envoyés en
    // base64, +33 %) sont annoncés jusqu'à documents.taille_max_mo (25 Mo par
    // défaut) : tout fichier de plus de ~7,5 Mo était refusé en « 413 ». La
    // limite suit désormais la taille de document autorisée (plancher 10 Mo).
    $maxBody = max(10 * 1024 * 1024,
        (int)((defined('DOC_TAILLE_MAX_MO') ? DOC_TAILLE_MAX_MO : 25) * 1024 * 1024 * 4 / 3) + 1024 * 1024);
    if ($raw !== false && strlen($raw) > $maxBody) {
        json_error('Requête trop volumineuse.', 413);
    }
    return json_decode($raw ?: '', true) ?? [];
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// ── Expiration de session ────────────────────────────────────────────────────
if (!empty($_SESSION['user'])) {
    $lastActivity = $_SESSION['_last_activity'] ?? 0;
    $maxLifetime  = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400;
    if ($lastActivity && (time() - $lastActivity) > $maxLifetime) {
        $_SESSION = [];
        session_destroy();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }
}
// Expiration session super admin (4h max)
if (!empty($_SESSION['superadmin']['authenticated'])) {
    $saTs = $_SESSION['superadmin']['ts'] ?? 0;
    if ($saTs && (time() - $saTs) > 14400) {
        unset($_SESSION['superadmin']);
    }
}
// ── Super admin : le compte existe-t-il toujours ? ───────────────────────────
// LA SESSION SUPER ADMIN SURVIVAIT À LA SUPPRESSION DU COMPTE.
// require_superadmin() ne regarde que la session : un compte secondaire supprimé
// (départ, compte compromis) gardait la main sur TOUTES les organisations jusqu'à
// 4 h, et un mot de passe changé n'éteignait pas les autres sessions. Comme pour
// les comptes d'organisation (utilisateur_session), on revérifie à chaque
// requête : connexion locale → le compte existe, avec le mot de passe de la
// connexion ; connexion Microsoft → l'adresse est toujours autorisée. Registre
// injoignable : la session tombe (on ne prête pas les pleins pouvoirs à l'aveugle).
if (!empty($_SESSION['superadmin']['authenticated']) && class_exists('TenantResolver')) {
    $saValide = false;
    try { $saValide = TenantResolver::sessionSuperAdminValide((array)$_SESSION['superadmin']); }
    catch (\Throwable $e) { $saValide = false; }
    if (!$saValide) {
        if (class_exists('SecurityLog')) {
            SecurityLog::audit('session_revoked', ['login' => 'superadmin:' . (string)($_SESSION['superadmin']['login'] ?? '?'),
                                                   'reason' => 'compte super admin supprimé, mot de passe changé ou adresse retirée']);
        }
        unset($_SESSION['superadmin'], $_SESSION['forced_tenant']);
    }
    unset($saValide);
}
$_SESSION['_last_activity'] = time();

/**
 * Utilisateur de la session, REVÉRIFIÉ en base — ou null.
 *
 * LA SESSION FAISAIT FOI JUSQU'À SON EXPIRATION.
 * $_SESSION['user'] est une copie de la fiche prise à la connexion. Un compte
 * désactivé, et même SUPPRIMÉ, continuait donc de tout faire jusqu'à
 * l'expiration du cookie (24 h) ; un gestionnaire rétrogradé gardait ses
 * droits ; un tenant désactivé par le super admin restait ouvert à ceux qui
 * étaient déjà connectés.
 *
 * On relit donc la fiche une fois par requête (une requête indexée par Id) :
 *   • compte absent ou inactif → session utilisateur révoquée ;
 *   • rôle, identité et drapeau « mot de passe à changer » → rafraîchis.
 * (Le tenant désactivé ou supprimé est traité dans config.php.)
 * Si la base est injoignable, la session est conservée telle quelle : les
 * routes qui ont besoin de la base échoueront d'elles-mêmes.
 */
function utilisateur_session(): ?array {
    static $verifie = false;
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) return null;
    if ($verifie) return $_SESSION['user'];
    $verifie = true;

    $revoquer = function (string $raison): void {
        if (class_exists('SecurityLog')) {
            SecurityLog::audit('session_revoked', [
                'login'  => (string)($_SESSION['user']['Login'] ?? ''),
                'tenant' => (string)($_SESSION['tenant_key'] ?? ''),
                'reason' => $raison,
            ]);
        }
        unset($_SESSION['user']);
        if (empty($_SESSION['forced_tenant'])) unset($_SESSION['tenant_key']);
    };

    // (Le tenant désactivé ou supprimé est traité plus tôt, dans config.php,
    //  avant même le choix de la base.)

    $uid = (int)($_SESSION['user']['Id'] ?? 0);
    if ($uid <= 0) return $_SESSION['user'];
    try {
        if (!(($GLOBALS['db'] ?? null) instanceof Database)) {
            // Réutilisée par le dispatch : une seule connexion par requête.
            $GLOBALS['db'] = new Database();
        }
        $base  = $GLOBALS['db'];
        $fiche = $base->fetchOne("SELECT * FROM Utilisateurs WHERE Id = :id", ['id' => $uid]);
    } catch (\Throwable $e) {
        return $_SESSION['user'];
    }
    if (!$fiche || (int)($fiche['Actif'] ?? 1) !== 1) {
        $revoquer($fiche ? 'compte désactivé' : 'compte supprimé');
        return null;
    }
    // Garde-fou : la fiche trouvée sous cet Id doit être celle de la session.
    // Un Id ne désigne la même personne que dans la même base ; s'il pointe
    // ailleurs (base changée, compte supprimé puis Id réattribué, renommage),
    // on ne prête pas à la session les droits de quelqu'un d'autre.
    $loginSession = (string)($_SESSION['user']['Login'] ?? '');
    if ($loginSession !== '' && strcasecmp($loginSession, (string)($fiche['Login'] ?? '')) !== 0) {
        $revoquer('identité différente sous le même Id');
        return null;
    }
    // MOT DE PASSE CHANGÉ : LES AUTRES SESSIONS RESTAIENT OUVERTES.
    // Changer son mot de passe après un doute (poste partagé, mot de passe vu),
    // ou le faire réinitialiser par un gestionnaire, laissait en place la session
    // de celui qui s'en servait — jusqu'à 24 h. La session retient une empreinte
    // du mot de passe de la connexion ; s'il a changé depuis, elle tombe. La
    // session qui fait le changement prend la nouvelle (session_noter_mot_de_passe).
    $empreinte = empreinte_mot_de_passe((string)($fiche['MotDePasse'] ?? ''));
    $notee = $_SESSION['_mdp'] ?? null;
    if (!is_array($notee) || (int)($notee['id'] ?? 0) !== $uid) {
        $_SESSION['_mdp'] = ['id' => $uid, 'e' => $empreinte];   // première vérification de cette connexion
    } elseif (!hash_equals((string)($notee['e'] ?? ''), $empreinte)) {
        $revoquer('mot de passe changé depuis la connexion');
        unset($_SESSION['_mdp']);
        return null;
    }
    foreach (['Login', 'Nom', 'Prenom', 'Email', 'Role', 'Provider', 'Actif', 'MustChangePassword'] as $k) {
        if (array_key_exists($k, $fiche)) $_SESSION['user'][$k] = $fiche[$k];
    }
    return $_SESSION['user'];
}

/** Empreinte d'une empreinte de mot de passe : ce qui est gardé en session (jamais le hash lui-même). */
function empreinte_mot_de_passe(string $hash): string {
    return substr(hash('sha256', 'larka-session|' . $hash), 0, 32);
}

/**
 * La session courante a changé le mot de passe de SON compte (change_password,
 * fiche éditée par soi-même) : elle prend la nouvelle empreinte et reste ouverte ;
 * les autres sessions de ce compte tomberont à leur prochaine requête.
 */
function session_noter_mot_de_passe($db): void {
    $uid = (int)($_SESSION['user']['Id'] ?? 0);
    if ($uid <= 0 || !($db instanceof Database)) return;
    try {
        $h = $db->fetchOne('SELECT MotDePasse FROM Utilisateurs WHERE Id = :id', ['id' => $uid]);
        if ($h) $_SESSION['_mdp'] = ['id' => $uid, 'e' => empreinte_mot_de_passe((string)($h['MotDePasse'] ?? $h['motdepasse'] ?? ''))];
    } catch (\Throwable $e) { /* la prochaine requête reposera la référence */ }
}

/**
 * Libère le verrou de session d'une lecture authentifiée.
 *
 * LES APPELS D'UN MÊME UTILISATEUR PASSAIENT UN PAR UN.
 * Les sessions PHP (fichiers) sont verrouillées de session_start() à la fin de
 * la requête : les ~30 appels lancés en parallèle à l'ouverture de Larka — et
 * les 5 à 10 de chaque page — attendaient chacun la fin du précédent, quel que
 * soit le nombre de workers. Mesuré : 8 appels simultanés prenaient autant que
 * 8 appels successifs.
 *
 * Une lecture (GET) authentifiée relâche donc la session dès que l'utilisateur
 * est vérifié. Si la route modifie ensuite $_SESSION (jeton Microsoft
 * rafraîchi, réglage mis en session…), seules les clés modifiées sont
 * réécrites en fin de requête, sur la session relue à ce moment — sans écraser
 * ce qu'une requête parallèle aurait écrit entre-temps. Une route qui rouvre
 * elle-même la session (session_start) retrouve le comportement habituel.
 * Les écritures (POST, PUT, DELETE) gardent la session ouverte jusqu'au bout.
 */
function liberer_session_lecture(): void {
    static $fait = false;
    if ($fait || session_status() !== PHP_SESSION_ACTIVE) return;
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;
    $fait = true;
    $avant = $_SESSION;
    $id    = session_id();
    session_write_close();
    register_shutdown_function(static function () use ($avant, $id): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;   // rouverte : PHP l'écrira
        $apres = is_array($_SESSION ?? null) ? $_SESSION : [];
        $modifs = [];
        foreach ($apres as $k => $v) {
            if (!array_key_exists($k, $avant) || serialize($avant[$k]) !== serialize($v)) $modifs[$k] = $v;
        }
        $retraits = array_values(array_diff(array_keys($avant), array_keys($apres)));
        if (!$modifs && !$retraits) return;
        // Le cookie et les en-têtes de cache sont partis avec la première
        // ouverture : la réouverture n'a rien à envoyer (et ne le pourrait plus
        // si la réponse est déjà partie).
        @ini_set('session.use_cookies', '0');
        @ini_set('session.cache_limiter', '');
        session_id($id);
        if (!@session_start()) return;
        if (session_id() !== $id) { @session_destroy(); return; }   // session détruite entre-temps
        foreach ($modifs as $k => $v) $_SESSION[$k] = $v;
        foreach ($retraits as $k) unset($_SESSION[$k]);
        @session_write_close();
    });
}

function require_auth(): array {
    // Appel relayé par le lien chiffré du serveur principal (SupervisionLien::executerApi) :
    // le compte et le rôle de la personne, posés APRÈS vérification cryptographique du message.
    if (isset($GLOBALS['_larka_utilisateur_delegue']) && is_array($GLOBALS['_larka_utilisateur_delegue'])) return $GLOBALS['_larka_utilisateur_delegue'];
    $u = utilisateur_session();
    if ($u !== null) { liberer_session_lecture(); return $u; }
    // Le Super Admin n'a pas de $_SESSION['user'] mais doit pouvoir
    // accéder aux routes admin (push_test, push_config, etc.) depuis la Configuration.
    // On retourne un user virtuel avec le rôle Admin.
    if (!empty($_SESSION['superadmin']['authenticated'])) {
        liberer_session_lecture();
        return [
            'Id'    => 0,
            'Login' => $_SESSION['superadmin']['login'] ?? 'superadmin',
            'Role'  => 'Gestionnaire',
            'Nom'   => 'Super',
            'Prenom'=> 'Admin',
        ];
    }
    json_error('Non authentifié.', 401);
}
/**
 * Refus d'accès centralisé : journalise PUIS répond 403.
 *
 * LES REFUS N'ÉTAIENT JAMAIS TRACÉS.
 * SecurityLog::accessDenied() existait mais aucune garde ne l'appelait : un
 * compte qui sondait les routes une à une (énumération de droits, tentative
 * d'accès à des données d'un autre rôle) ne laissait AUCUNE trace exploitable.
 * Toutes les gardes passent désormais par ici, de sorte qu'un refus apparaît à
 * la fois dans data/security/audit.log (source de vérité, survit à une panne
 * de base) et dans le journal applicatif (écran Journal, canal « securite »).
 *
 * $raison : motif court et non sensible (jamais de donnée métier).
 */
function refuser_acces(array $user, string $raison, string $message = 'Permission insuffisante.'): void {
    if (class_exists('SecurityLog')) {
        SecurityLog::audit('access_denied', [
            'action'    => defined('LARKA_ACTION_COURANTE') ? LARKA_ACTION_COURANTE : '',
            'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
            'login'     => (string)($user['Login'] ?? ''),
            'user_id'   => (int)($user['Id'] ?? 0),
            'role'      => (string)($user['Role'] ?? ''),
            'tenant'    => (string)($_SESSION['tenant_key'] ?? $_SESSION['forced_tenant'] ?? 'default'),
            'reason'    => $raison,
            'http_code' => 403,
        ]);
    }
    json_error($message, 403);
}

function require_role(array $user, array $roles): void {
    // Gestionnaire = rôle administrateur (Admin conservé pour rétrocompatibilité)
    if ($user['Role'] === 'Gestionnaire' || $user['Role'] === 'Admin') return;
    // Technicien : mêmes droits de LECTURE que le Visionneur. Les écritures
    // exigent des listes sans Visionneur, donc restent fermées.
    if ($user['Role'] === 'Technicien' && in_array('Visionneur', $roles, true)) return;
    if (!in_array($user['Role'], $roles)) {
        refuser_acces($user, 'role_insuffisant:requis=' . implode('|', $roles));
    }
}

/**
 * Onglets qu'un Demandeur donné est autorisé à CONSULTER, en plus de son
 * périmètre habituel.
 *
 * L'autorisation est STRICTEMENT INDIVIDUELLE : elle est portée par la colonne
 * Utilisateurs.AccesLecture et se règle sur la fiche du compte. Il n'existe
 * volontairement aucun réglage collectif — ouvrir un onglet à « tous les
 * demandeurs » d'un seul geste rendrait trop facile d'exposer des données à des
 * agents qui n'en ont pas l'usage.
 *
 * Lu EN BASE à chaque requête, jamais depuis la session : une révocation doit
 * prendre effet tout de suite, sans attendre que l'agent se reconnecte. Le
 * cache est indexé par utilisateur, le temps de la requête HTTP seulement.
 */
function demandeur_modules_lecture($db, array $user = []): array {
    static $cache = [];
    $uid = isset($user['Id']) ? (int)$user['Id'] : 0;
    if ($uid <= 0) return [];
    if (!array_key_exists($uid, $cache)) {
        try { $cache[$uid] = $db->getAccesLecture($uid) ?? []; }
        catch (\Throwable $e) { $cache[$uid] = []; }
    }
    return $cache[$uid];
}

/**
 * Garde de LECTURE seule, en remplacement de require_role() sur les routes GET.
 *
 * Comportement identique à require_role(), avec une exception : un Demandeur
 * est admis si cet onglet lui a été ouvert nominativement.
 *
 * À n'utiliser QUE sur des routes de lecture. L'écriture reste protégée par
 * require_role() : l'autorisation accordée ici ne confère jamais le droit de
 * modifier quoi que ce soit, quel que soit l'onglet ouvert.
 */
function require_lecture(array $user, array $roles, $module, $db = null): void {
    if ($user['Role'] === 'Gestionnaire' || $user['Role'] === 'Admin') return;
    if (in_array($user['Role'], $roles)) return;
    if ($user['Role'] === 'Technicien' && in_array('Visionneur', $roles, true)) return;
    if ($user['Role'] === 'Demandeur' && $db !== null) {
        // $module accepte un tableau : certaines routes alimentent plusieurs
        // onglets (les compteurs servent aussi bien Énergie que Bilan Carbone).
        // Disposer de l'un OU l'autre suffit alors à consulter la donnée.
        $requis  = is_array($module) ? $module : [$module];
        $accordes = demandeur_modules_lecture($db, $user);
        if (array_intersect($requis, $accordes)) return;
    }
    $mod = is_array($module) ? implode('|', $module) : (string)$module;
    refuser_acces($user, 'lecture_refusee:module=' . $mod);
}

// ── Paramètres de la requête ──────────────────────────────────────────────────
$action = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$method = $_SERVER['REQUEST_METHOD'];
// Rendue disponible au hook de fin de requête (register_shutdown_function).
define('LARKA_ACTION_COURANTE', $action !== '' ? $action : '(vide)');

// Validation action
if (strlen($action) > 50) json_error('Action invalide.', 400);

// Certaines routes publiques ne nécessitent pas la base de données.
// Les exécuter avant d'instancier Database() évite qu'un souci PDO empêche
// l'affichage des boutons SSO ou la découverte de l'URL serveur.
$dbOptionalActions = ['auth_config', 'server_url', 'oauth_microsoft_url', 'push_vapid_key',
    // Synchronisation de l'app mobile : la base vient du jeton de l'appareil (routes/synchro.php).
    'synchro_lier', 'synchro_etat', 'synchro_tables', 'synchro_cliche', 'synchro_empreintes', 'synchro_lignes',
    'synchro_tirer', 'synchro_pousser', 'synchro_reserver', 'synchro_fichiers', 'synchro_fichier',
    'synchro_fichier_envoyer', 'synchro_fichier_supprimer', 'synchro_delier',
    'synchro_appairer', 'synchro_appairage_confirmer', 'synchro_appairage_attendre',
    'legifrance_status', 'legifrance_search', 'legifrance_suggest', 'legifrance_consult',
    'france_legifrance_config', 'france_legifrance_ping', 'france_legifrance_search',
    'france_legifrance_suggest', 'france_legifrance_call',
    'chorus_visibility', 'chorus_status', 'chorus_token_test', 'chorus_call',
    'superadmin_login', 'superadmin_me', 'superadmin_logout', 'superadmin_status',
    'superadmin_tenants', 'superadmin_tenant_save', 'superadmin_tenant_delete',
    'superadmin_switch_tenant', 'superadmin_test_db', 'superadmin_provision',
    'superadmin_change_password', 'superadmin_setup',
    'superadmin_tenant_users', 'superadmin_tenant_user_save', 'superadmin_tenant_user_delete',
    'superadmin_local_accounts', 'superadmin_sync_accounts', 'superadmin_monitoring',
    'superadmin_branding_public',
    'config_serveur', 'sauvegardes_etat', 'sauvegarde_maintenant',
    'rgpd', 'superadmin_rgpd', 'aide', 'thematiques_langue',
    'maj_etat', 'maj_verifier', 'maj_installer', 'maj_historique', 'maj_restaurer', 'maj_canal',
    'connect_releveur',    // PC releveur (Larka Connect) : jeton, sans session — sa base s'ouvre d'après le jeton
    // Lien chiffré avec le serveur de supervision : authentifié par les clés du lien, sans session ;
    // la base s'ouvre d'après l'organisation désignée dans le message (api/SupervisionLien.php).
    'supervision_lien', 'supervision_pont', 'supervision_pont_reponse', 'supervision_appairer'];

// ── Revérification de la session (compte supprimé / désactivé / rétrogradé) ─
// Faite avant le blocage ci-dessous pour que le drapeau MustChangePassword
// soit celui de la base et non celui figé à la connexion. Pas pour les routes
// qui doivent répondre sans base (boutons SSO, super admin…) : celles qui
// exigent un utilisateur la feront d'elles-mêmes via require_auth().
if (!empty($_SESSION['user']) && !in_array($action, $dbOptionalActions, true)) utilisateur_session();

// ── Blocage si l'utilisateur doit changer son mot de passe ──────────────────
// Pour les comptes par défaut (admin/admin) ou réinitialisés avec un flag forcé,
// on refuse toute action sauf : se déconnecter, lire son profil, changer son mdp.
if (!empty($_SESSION['user']['MustChangePassword']) && (int)$_SESSION['user']['MustChangePassword'] === 1) {
    $_allowedWhenMustChange = ['logout', 'me', 'change_password', 'auth_config', 'server_url', 'rgpd'];
    if (!in_array($action, $_allowedWhenMustChange, true)) {
        json_error('Vous devez changer votre mot de passe avant de continuer.', 403);
    }
}

// ── Rate limiting simple (fichier) pour le login et l'API globale ───────────
// FIX BUG : la définition de cette fonction est volontairement placée
// AVANT ses appels. PHP hoist les fonctions nommées donc l'ancien ordre
// fonctionnait, mais c'était fragile (ex. si on remplaçait par une closure
// ou une fonction conditionnelle). Plus robuste de garder l'ordre logique.
/**
 * Limite de débit. Refuse (429) si $maxAttempts évènements ont déjà été notés
 * pour $key dans la fenêtre.
 *
 * $noter = true  : l'appel lui-même compte (limite de débit classique) ;
 * $noter = false : on vérifie seulement — l'appelant notera les seuls ÉCHECS
 *                  avec rate_limit_noter() (connexion : une connexion réussie
 *                  ne doit pas rapprocher du blocage).
 */
function check_rate_limit(string $key, int $maxAttempts = 10, int $windowSeconds = 300, bool $noter = true): void {
    $dir = __DIR__ . '/../data/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . sha1($key) . '.json';
    // Fenêtre mémorisée avec la clé : rate_limit_noter() purge à l'identique.
    $GLOBALS['_rate_limit_fenetres'][$key] = $windowSeconds;
    $fh = @fopen($file, 'c+');
    if (!$fh) return;                       // stockage indisponible : on ne bloque pas
    flock($fh, LOCK_EX);                    // lecture-écriture atomique (requêtes concurrentes)
    $data = json_decode(stream_get_contents($fh) ?: '[]', true);
    if (!is_array($data)) $data = [];
    $now  = time();
    // Nettoyer les anciennes entrées
    $data = array_values(array_filter($data, fn($t) => is_int($t) && ($now - $t) < $windowSeconds));
    if (count($data) >= $maxAttempts) {
        flock($fh, LOCK_UN); fclose($fh);
        // Log de sécurité — utile pour détecter les bruteforce
        if (class_exists('SecurityLog')) {
            SecurityLog::rateLimited($key, $maxAttempts);
        }
        json_error('Trop de tentatives. Réessayez dans quelques minutes.', 429);
    }
    if ($noter) $data[] = $now;
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN); fclose($fh);
}

/** Note un évènement (typiquement un échec) pour une clé de limite de débit. */
function rate_limit_noter(string $key): void {
    $dir = __DIR__ . '/../data/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fenetre = (int)($GLOBALS['_rate_limit_fenetres'][$key] ?? 3600);
    $fh = @fopen($dir . '/' . sha1($key) . '.json', 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $data = json_decode(stream_get_contents($fh) ?: '[]', true);
    if (!is_array($data)) $data = [];
    $now  = time();
    $data = array_values(array_filter($data, fn($t) => is_int($t) && ($now - $t) < $fenetre));
    $data[] = $now;
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN); fclose($fh);
}

// ── Rate limiting global par IP (200 req / 60s — anti-DDoS basique) ─────────
$_clientIp = client_ip(); // IP réelle, y compris derrière un proxy de confiance
// (La synchronisation de l'app mobile, authentifiée par jeton, a sa limite par appareil : routes/synchro.php.)
// (Le lien du serveur de supervision, authentifié par ses clés, a sa propre limite d'échecs : routes/supervision-lien.php.)
if ($action && !in_array($action, ['auth_config', 'server_url', 'push_vapid_key', 'supervision_lien', 'supervision_pont', 'supervision_pont_reponse']) && !str_starts_with($action, 'synchro_')) {
    check_rate_limit('global_' . $_clientIp, 200, 60);
}


if (in_array($action, $dbOptionalActions, true)) {
    try {
        require_once __DIR__ . '/routes/superadmin.php'; // Multi-tenant management
        require __DIR__ . '/routes/auth.php';
        require __DIR__ . '/routes/gestion.php';
        require_once __DIR__ . '/routes/france.php';    // Légifrance proxy (france_legifrance_*)
        require_once __DIR__ . '/routes/chorus.php';   // Chorus Pro proxy
        require_once __DIR__ . '/routes/assistant.php'; // Assistant IA
        require_once __DIR__ . '/routes/push.php';     // Push notifications (vapid_key public)
        require_once __DIR__ . '/routes/majs.php';     // Mises à jour de l'application
        require_once __DIR__ . '/routes/rgpd.php';     // Conformité RGPD (politique, module Super Admin)
        require_once __DIR__ . '/routes/aide.php';     // Aide intégrée (fiches du rôle, procédures de l'installation)
        require_once __DIR__ . '/routes/connect-releveur.php'; // PC releveur des appareils en hotspot (jeton)
        if (str_starts_with($action, 'supervision_')) require_once __DIR__ . '/routes/supervision-lien.php'; // lien chiffré (clés)
        if (str_starts_with($action, 'synchro_')) require_once __DIR__ . '/routes/synchro.php'; // app mobile (jeton)
        if ($action === 'thematiques_langue') require_once __DIR__ . '/routes/thematiques.php'; // libellés, écran de connexion
        json_error("Action inconnue : $action", 404);
    } catch (\Throwable $e) {
        // Le message d'exception partait tel quel au client, même hors
        // debug_errors (ex. « Impossible de se connecter à la base super
        // admin : SQLSTATE… » avec hôte et utilisateur). Détail en journal,
        // référence de corrélation au client — comme le reste de l'API.
        Journal::erreur('api', 'Erreur sur une route sans base : ' . $action, ['exception' => $e]);
        json_error((defined('DEBUG_ERRORS') && DEBUG_ERRORS)
            ? ('Erreur serveur : ' . $e->getMessage())
            : ('Erreur serveur interne. Référence : ' . Journal::requestId()), 500);
    }
}

// ── Dispatch des routes ───────────────────────────────────────────────────────
try {
    // Déjà ouverte par utilisateur_session() quand une session est active.
    $db = (($GLOBALS['db'] ?? null) instanceof Database) ? $GLOBALS['db'] : new Database();
    // À partir d'ici le journal écrit en base (et vide son tampon de bootstrap).
    Journal::attacherBase($db->getPdo());

    require_once __DIR__ . '/routes/superadmin.php'; // Multi-tenant (avant auth pour le switch)
    require __DIR__ . '/routes/auth.php';        // Login, logout, OAuth, session
    require __DIR__ . '/routes/inventaire.php';  // Biens, équipements, stock, lignes interventions
    require __DIR__ . '/routes/maintenance.php'; // Interventions, contrats
    require __DIR__ . '/routes/energie.php';     // Compteurs et relevés énergie
    require __DIR__ . '/routes/gestion.php';     // Gestion matériel, utilisateurs, historique, demandes, listes, config
    require __DIR__ . '/routes/stats.php';       // Dashboard, stats, stats complètes
    require __DIR__ . '/routes/documents.php';   // Upload, download, suppression de fichiers
    require __DIR__ . '/routes/sharepoint.php';  // Navigation SharePoint + liens
    require __DIR__ . '/routes/archives.php';    // Boîtes, dossiers, bordereaux archives
    require_once __DIR__ . '/routes/appareils.php'; // Appareils mobiles reliés (QR code, révocation)
    require_once __DIR__ . '/routes/france.php'; // Légifrance sandbox (france_legifrance_*)
    require_once __DIR__ . '/routes/chorus.php'; // Chorus Pro proxy
    require_once __DIR__ . '/routes/assistant.php'; // Assistant IA
    require_once __DIR__ . '/routes/push.php';      // Push notifications
    require __DIR__ . '/routes/mobilite.php';    // Mobilité carbone (demandeurs)
    require_once __DIR__ . '/routes/carbone.php'; // Facteurs d'émission ADEME (proxy + cache)
    require __DIR__ . '/routes/plans.php';       // Plans interactifs (bâtiments, étages, éléments)
    require __DIR__ . '/routes/annuaire.php';    // Annuaire cartographié (Plans côté Demandeur)
    // ORDRE IMPORTANT : journal.php DOIT être chargé APRÈS annuaire.php.
    //    L'action presence_effectif s'appuie sur _annuaireAppToken() et
    //    _annuaireGraphPost(), définies dans annuaire.php. Les routes étant
    //    de simples require successifs, une inclusion plus haut ferait
    //    échouer le test function_exists() et renverrait « module annuaire
    //    non chargé » alors que tout est correctement configuré.
    require __DIR__ . '/routes/journal.php';     // Journal applicatif + présence
    require __DIR__ . '/routes/urgences.php';    // Médias (photos/vidéos) des procédures d'urgence
    require __DIR__ . '/routes/migration.php';   // Migration inter-drivers (SQLite ↔ PG ↔ MariaDB)
    require __DIR__ . '/routes/connect.php';     // Larka Connect : équipements raccordés (GTB, compteurs, bornes)
    require __DIR__ . '/routes/supervision.php'; // Serveur de supervision séparé : réglage et appairage (gestionnaire)

    // ── Extensions communautaires ────────────────────────────────────────────
    // La couche reste INERTE tant que extensions.actif vaut false dans
    // config.json : aucun dossier n'est ouvert, aucun code tiers n'est chargé.
    require_once __DIR__ . '/extensions/Registre.php';
    ExtRegistre::initialiser($db, $_SESSION['user'] ?? []);
    require __DIR__ . '/routes/extensions.php';

    // Thématiques : thèmes et traductions. Elles ne sont pas des extensions —
    // ni écran, ni données, ni permission — mais elles vivent dans la même
    // zone fonctionnelle, et suivent le même interrupteur de configuration.
    require __DIR__ . '/routes/thematiques.php';

    json_error("Action inconnue : $action", 404);

} catch (PDOException $e) {
    // FIX SÉCURITÉ (F1) : le diagnostic détaillé ci-dessous expose l'hôte, le
    // port, le nom de base, l'utilisateur et les chemins. Il ne doit JAMAIS sortir
    // en production. On journalise systématiquement, et on ne renvoie le détail
    // au client que si securite.debug_errors=true.
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '][DB] ' . $e->getMessage()
        . ' (driver=' . DB_DRIVER . ', code=' . $e->getCode() . ')');
    // Au journal aussi (il n'y figurait pas) : avec son code — base injoignable,
    // occupée, structure incomplète… — et la marche à suivre qui va avec.
    $codeBdd = CodesErreur::reconnaitre('Erreur base de données', 'php', 'critique', 500, $e->getMessage(), 'PDOException') ?? 'BDD-001';
    Journal::erreur('php', 'Erreur base de données : ' . $e->getMessage(), ['exception' => $e, 'code' => $codeBdd, 'codeHttp' => 500, 'reference' => $ref]);
    if (!(defined('DEBUG_ERRORS') && DEBUG_ERRORS)) {
        $ficheBdd = CodesErreur::fiche($codeBdd);
        json_error('Erreur base de données' . ($ficheBdd ? ' — ' . $ficheBdd['titre'] : '') . " ($codeBdd). Référence : " . $ref
                   . '. Consultez les journaux serveur.', 500, $codeBdd);
    }

    $driver  = DB_DRIVER;
    $message = $e->getMessage();
    $code    = $e->getCode();
    $details = [];

    // ── Diagnostic selon le driver et le code d'erreur ────────────────────
    if ($driver === 'pgsql') {
        $ext = extension_loaded('pdo_pgsql') ? 'chargée' : 'MANQUANTE (activer php_pdo_pgsql dans php.ini)';
        $details[] = "Extension PHP pdo_pgsql : $ext";
        $details[] = "Serveur cible : " . DB_HOST . ":" . DB_PORT . " / base : " . DB_NAME . " / user : " . DB_USER;

        // Connection refused ou host unreachable
        if (str_contains($message, 'Connection refused') || str_contains($message, '10061') || $code === 7) {
            $details[] = "⚠ PostgreSQL n'est pas démarré ou n'écoute pas sur ce port.";
            $details[] = "→ Installez PostgreSQL : https://www.postgresql.org/download/";
            $details[] = "→ Ou repassez en SQLite dans config.json : base_de_donnees.driver = \"sqlite\"";
        } elseif (str_contains($message, 'password authentication failed')) {
            $details[] = "⚠ Mot de passe PostgreSQL incorrect.";
            $details[] = "→ Vérifiez base_de_donnees.password dans config.json";
        } elseif (str_contains($message, 'database') && str_contains($message, 'does not exist')) {
            $details[] = "⚠ La base \"" . DB_NAME . "\" n'existe pas.";
            $details[] = "→ Créez-la : CREATE DATABASE " . DB_NAME . ";";
        } elseif (str_contains($message, 'could not find driver') || !extension_loaded('pdo_pgsql')) {
            $details[] = "⚠ L'extension PHP pdo_pgsql n'est pas activée.";
            $details[] = "→ Ajoutez extension=php_pdo_pgsql.dll dans php.ini";
        } elseif (str_contains($message, 'role') && str_contains($message, 'does not exist')) {
            $details[] = "⚠ L'utilisateur PostgreSQL \"" . DB_USER . "\" n'existe pas.";
            $details[] = "→ Créez-le : CREATE USER " . DB_USER . " WITH PASSWORD '...';";
        }

    } elseif ($driver === 'mariadb' || $driver === 'mysql') {
        $ext = extension_loaded('pdo_mysql') ? 'chargée' : 'MANQUANTE (activer php_pdo_mysql dans php.ini)';
        $details[] = "Extension PHP pdo_mysql : $ext";
        $details[] = "Serveur cible : " . DB_HOST . ":" . DB_PORT . " / base : " . DB_NAME . " / user : " . DB_USER;

        if (str_contains($message, 'Connection refused') || str_contains($message, '10061') || $code === 2002) {
            $details[] = "⚠ MySQL/MariaDB n'est pas démarré ou refuse la connexion.";
            $details[] = "→ Installez MariaDB : https://mariadb.org/download/";
            $details[] = "→ Ou repassez en SQLite dans config.json : base_de_donnees.driver = \"sqlite\"";
        } elseif (str_contains($message, 'Access denied')) {
            $details[] = "⚠ Accès refusé — mauvais identifiants MySQL.";
            $details[] = "→ Vérifiez base_de_donnees.user et base_de_donnees.password dans config.json";
        } elseif (str_contains($message, 'Unknown database')) {
            $details[] = "⚠ La base \"" . DB_NAME . "\" n'existe pas.";
            $details[] = "→ Créez-la : CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4;";
        } elseif (!extension_loaded('pdo_mysql')) {
            $details[] = "⚠ L'extension PHP pdo_mysql n'est pas activée.";
            $details[] = "→ Ajoutez extension=php_pdo_mysql.dll dans php.ini";
        }

    } elseif ($driver === 'sqlite') {
        $ext = extension_loaded('pdo_sqlite') ? 'chargée' : 'MANQUANTE';
        $details[] = "Extension PHP pdo_sqlite : $ext";
        $details[] = "Fichier DB : " . DB_PATH;
        if (!is_writable(dirname(DB_PATH)) && is_dir(dirname(DB_PATH))) {
            $details[] = "⚠ Le dossier data/ n'est pas accessible en écriture.";
        }
    }

    $details[] = "Driver configuré : $driver";
    $details[] = "Code erreur PDO : $code";

    // (Atteint uniquement en mode debug_errors — voir garde plus haut.)
    json_error(
        "Erreur base de données : $message\n\n" . implode("\n", $details) . "\n\nRéférence : $ref",
        500
    );

} catch (RuntimeException $e) {
    // FIX SÉCURITÉ (F1) : erreur de config — détail en log, message générique.
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '][CFG] ' . $e->getMessage());
    json_error((defined('DEBUG_ERRORS') && DEBUG_ERRORS)
        ? ('Erreur de configuration : ' . $e->getMessage())
        : ('Erreur de configuration. Référence : ' . $ref), 500);
} catch (\Throwable $e) {
    // FIX SÉCURITÉ (F1) : erreur générique — détail en log uniquement.
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error((defined('DEBUG_ERRORS') && DEBUG_ERRORS)
        ? ('Erreur serveur : ' . $e->getMessage())
        : ('Erreur serveur interne. Référence : ' . $ref), 500);
}
