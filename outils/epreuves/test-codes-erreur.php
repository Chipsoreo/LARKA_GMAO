<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — épreuve des codes d'erreur : chaque incident dit ce qui s'est passé,
 * pourquoi, et quoi faire.
 *
 *   1. le catalogue est complet et fermé (champs, gravités, « qui agit »,
 *      motifs valides, aucun motif vers un code absent) ;
 *   2. les messages RÉELS de Larka (pilotes, base, PHP, sécurité, radio) sont
 *      reconnus sous le bon code — la liste ci-dessous les reprend mot pour mot ;
 *   3. le document Documentations/CODES-ERREUR.md est celui que produit le
 *      catalogue, et les sections qu'il cite existent ;
 *   4. le journal pose le code sur ses entrées (base et fichiers), ajoute la
 *      colonne aux journaux plus anciens, et écrit la marche à suivre dans le
 *      journal texte ;
 *   5. les écrans s'en servent (pastille, fiche, toast, journal de supervision).
 *
 *   php outils/epreuves/test-codes-erreur.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-66s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-66s %s\n", $m, $d); }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — codes d'erreur : ce qui s'est passé, pourquoi, quoi faire\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

require_once "$racine/api/CodesErreur.php";

// ── 1. Catalogue ──────────────────────────────────────────────────────────────
$pb = CodesErreur::verifier();
verif(!$pb, 'catalogue cohérent : champs, gravités, « qui agit », motifs', $pb ? implode(' ; ', array_slice($pb, 0, 3)) : count(CodesErreur::CATALOGUE) . ' codes');
$doms = array_unique(array_map(fn($c) => substr($c, 0, 3), array_keys(CodesErreur::CATALOGUE)));
verif(count(CodesErreur::CATALOGUE) >= 80 && count(array_diff(array_keys(CodesErreur::DOMAINES), $doms)) === 0,
      'tous les domaines ont des codes (système, base, sécurité… supervision, radio)', count($doms) . ' domaines');
$minRem = min(array_map(fn($f) => count($f['remedes']), CodesErreur::CATALOGUE));
$minCause = min(array_map(fn($f) => count($f['causes']), CodesErreur::CATALOGUE));
$descCourte = array_keys(array_filter(CodesErreur::CATALOGUE, fn($f) => mb_strlen($f['description']) < 60));
verif($minRem >= 1 && $minCause >= 1 && !$descCourte, 'chaque code : une description complète, des causes, une marche à suivre',
      $descCourte ? 'descriptions trop courtes : ' . implode(', ', $descCourte) : '');

// ── 2. Les messages réels de Larka, reconnus ─────────────────────────────────
// [message, canal, niveau, http, attendu] — messages repris des pilotes et des couches de Larka.
$cas = [
    ['SQLSTATE[HY000]: General error: 5 database is locked', 'php', 'critique', null, 'BDD-002'],
    ['SQLSTATE[HY000]: General error: 1 no such table: ConnectLorawan', 'php', 'critique', null, 'BDD-003'],
    ['SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: Biens.Code', 'php', 'error', null, 'BDD-004'],
    ['SQLSTATE[08006] [7] could not connect to server: Connection refused', 'php', 'critique', null, 'BDD-001'],
    ['attempt to write a readonly database', 'php', 'critique', null, 'BDD-005'],
    ['file_put_contents(/var/www/larka/data/x): Failed to open stream: No space left on device', 'php', 'warning', null, 'FIC-002'],
    ['écriture impossible dans data/logs/activite-2026-11-19.txt (utilisateur « www-data », dossier NON inscriptible)', 'journal', 'error', null, 'FIC-001'],
    ['Erreur fatale : Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', 'php', 'critique', null, 'SYS-003'],
    ['Erreur fatale : Maximum execution time of 30 seconds exceeded', 'php', 'critique', null, 'SYS-004'],
    ['Exception non rattrapée : Call to undefined function mb_substr()', 'php', 'critique', 500, 'SYS-005'],
    ['Exception non rattrapée : Undefined index Foo', 'php', 'critique', 500, 'SYS-001'],
    ['Erreur fatale : Cannot redeclare foo()', 'php', 'critique', null, 'SYS-002'],
    ['Undefined array key "x"', 'php', 'warning', null, 'SYS-007'],
    ['login_failure (bad_password)', 'securite', 'warning', null, 'SEC-001'],
    ['Trop de tentatives : réessayez dans 5 minutes.', 'api', 'warning', 429, 'SEC-002'],
    ['interventions_prevues — Accès refusé.', 'api', 'warning', 403, 'SEC-004'],
    ['me', 'api', 'warning', 401, 'SEC-003'],
    ['biens — Bien introuvable.', 'api', 'warning', 404, 'API-404'],
    ['connect_points — Le nom est obligatoire.', 'api', 'warning', 422, 'API-400'],
    ['TypeError: Cannot read properties of undefined (reading \'Id\')', 'client', 'error', null, 'NAV-001'],
    // Supervision : messages des pilotes, mot pour mot
    ['10.0.12.20:502 ne répond pas : probablement filtré par un pare-feu, ou équipement éteint.', 'connect', 'error', null, 'CXR-002'],
    ['10.0.12.20:502 REFUSE la connexion : l\'équipement répond, mais rien n\'écoute sur ce port', 'connect', 'error', null, 'CXR-001'],
    ['Le nom « gtb-cta » est inconnu du serveur (DNS). Utilisez l\'adresse IP, ou faites déclarer ce nom.', 'connect', 'error', null, 'CXR-003'],
    ['10.9.0.4 est inaccessible depuis le serveur (aucune route) : réseau ou VLAN séparé.', 'connect', 'error', null, 'CXR-004'],
    ["Adresse de l'équipement non renseignée.", 'connect', 'error', null, 'CXR-005'],
    ['exception 0x02 : adresse de registre invalide', 'connect', 'error', null, 'CXP-001'],
    ['exception 0x0B : passerelle : l\'équipement derrière elle ne répond pas (unit id inexistant ou éteint)', 'connect', 'error', null, 'CXP-002'],
    ['exception 0x01 : fonction non gérée par l\'équipement', 'connect', 'error', null, 'CXP-003'],
    ['Aucune réponse BACnet de 10.0.3.4:47808 (UDP). En UDP, ce silence ne distingue pas un pare-feu', 'connect', 'error', null, 'CXP-004'],
    ['Broker MQTT : identifiant ou mot de passe incorrect.', 'connect', 'error', null, 'CXP-006'],
    ['abonnement refusé par le broker : batiment/#', 'connect', 'error', null, 'CXP-007'],
    ['Le port répond, mais pas en MQTT (aucun CONNACK).', 'connect', 'error', null, 'CXP-008'],
    ['HTTP 401 : authentification refusée (jeton ou mot de passe)', 'connect', 'error', null, 'CXP-009'],
    ['HTTP 503 : superviseur indisponible (maintenance ?)', 'connect', 'error', null, 'CXP-010'],
    ['Le dossier « /mnt/gtb » est hors des racines autorisées. L\'administrateur du serveur peut en ajouter', 'connect', 'error', null, 'CXP-011'],
    ['Le service OCPP ne tourne pas. Il démarre avec Larka (start.sh, service systemd larka-ocpp…)', 'connect', 'error', null, 'CXB-001'],
    ['Aucune passerelle n\'a parlé à Larka depuis 10 minutes. Dans chaque passerelle : « packet forwarder Semtech (UDP) »', 'connect', 'error', null, 'CXL-001'],
    ['trame n° 12 refusée : compteur déjà vu (n° 40). Rejeu, ou capteur redémarré.', 'connect', 'warning', null, 'CXL-003'],
    ['demande de join rejouée (DevNonce déjà utilisé).', 'connect', 'warning', null, 'CXL-004'],
    ['trame reçue avec ce DevAddr, mais le MIC ne correspond pas : clés différentes, ou trame altérée.', 'connect', 'warning', null, 'CXL-002'],
    ['aucun récepteur wM-Bus branché (aucun port série USB).', 'connect', 'error', null, 'CXW-001'],
    ["accès refusé à /dev/ttyUSB0 : l'utilisateur du service doit appartenir au groupe « dialout »", 'connect', 'error', null, 'CXW-002'],
    ['compteur 12345678 non entendu pendant 30 s (hors de portée, ou émet moins souvent ?)', 'connect', 'warning', null, 'CXW-003'],
    ['télégramme chiffré : ajoutez la clé AES du compteur 12345678 (fournie avec le compteur ou par le distributeur)', 'connect', 'warning', null, 'CXW-004'],
    ['clé AES du compteur 12345678 incorrecte (le déchiffrement ne donne rien de lisible)', 'connect', 'error', null, 'CXW-005'],
    ['chiffrement mode 7 non pris en charge (seul le mode 5, OMS, l\'est)', 'connect', 'warning', null, 'CXW-006'],
    ['hotspot « LOGGER-01 » introuvable ou refusé (appareil éteint, hors de portée, ou clé changée ?)', 'connect', 'error', null, 'CXH-003'],
    ['connexion 4 : relevé mal daté ou trop ancien (2020-01-01 00:00:00)', 'connect', 'warning', null, 'CXH-002'],
    ['Demande d\'intervention automatique impossible', 'connect', 'error', null, 'CXA-001'],
];
$rates = [];
foreach ($cas as [$m, $canal, $niv, $http, $attendu]) {
    $c = CodesErreur::reconnaitre($m, $canal, $niv, $http);
    if ($c !== $attendu) $rates[] = "« " . mb_substr($m, 0, 50) . " » → " . ($c ?? '∅') . " (attendu $attendu)";
}
verif(!$rates, 'messages réels de Larka reconnus sous le bon code', $rates ? implode(' ; ', array_slice($rates, 0, 3)) : count($cas) . ' messages');
verif(CodesErreur::reconnaitre('Intervention clôturée', 'interventions', 'info') === null && CodesErreur::reconnaitre('biens', 'api', 'info', 200) === null,
      'une entrée ordinaire n\'est pas un incident : pas de code');
verif(CodesErreur::reconnaitre('Broker MQTT : identifiant ou mot de passe incorrect.', 'connect', 'error') === 'CXP-006'
   && CodesErreur::reconnaitre('Identifiant ou mot de passe incorrect.', 'api', 'warning', 401) === 'SEC-001',
      'même formulation, deux sens : la supervision et la connexion à Larka sont distinguées');

// ── 3. Documentation ─────────────────────────────────────────────────────────
$attendu = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$racine/api/outils/codes-erreur.php") . ' --contenu');
$doc = (string)@file_get_contents("$racine/Documentations/CODES-ERREUR.md");
verif($doc !== '' && $doc === $attendu, 'Documentations/CODES-ERREUR.md à jour (php api/outils/codes-erreur.php --md)');
$cx = (string)@file_get_contents("$racine/Documentations/LARKA-CONNECT.md");
$readme = (string)@file_get_contents("$racine/README.md");
$manque = [];
foreach (CodesErreur::CATALOGUE as $c => $f) {
    $d = $f['doc'];
    if (preg_match('/LARKA-CONNECT\.md, § (\d+(?: bis| ter)?)$/u', $d, $m)) {
        if (!preg_match('/^## ' . preg_quote($m[1], '/') . '\. /mu', $cx)) $manque[] = "$c → § {$m[1]}";
    } elseif (preg_match('/README\.md, « (.+) »$/u', $d, $m)) {
        if (!preg_match('/^## .*' . preg_quote($m[1], '/') . '/mu', $readme)) $manque[] = "$c → « {$m[1]} »";
    } elseif (preg_match('/^(Documentations\/[^,]+\.docx), « (.+) »$/u', $d, $m)) {
        // Renvoi vers un manuel Word : le fichier ne suffit pas, la SECTION citée doit
        // exister — sinon l'administrateur qui suit « Pour aller plus loin » cherche
        // un titre qui n'est plus là (c'est arrivé quand le README a perdu « Mises à jour »).
        $xml = '';
        if (is_file("$racine/{$m[1]}") && class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open("$racine/{$m[1]}") === true) { $xml = (string)$z->getFromName('word/document.xml'); $z->close(); }
        }
        if (!is_file("$racine/{$m[1]}")) $manque[] = "$c → {$m[1]}";
        elseif (class_exists('ZipArchive') && !str_contains(strip_tags($xml), $m[2])) $manque[] = "$c → {$m[1]}, « {$m[2]} »";
    } elseif (!is_file("$racine/" . $d)) {
        $manque[] = "$c → $d";
    }
}
verif(!$manque, 'chaque code renvoie à une section qui existe', $manque ? implode(', ', array_slice(array_unique($manque), 0, 4)) : '');
$o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$racine/api/outils/codes-erreur.php") . ' --message=' . escapeshellarg('SQLSTATE[HY000]: General error: 5 database is locked') . ' 2>&1');
verif(str_contains((string)$o, 'BDD-002') && str_contains((string)$o, 'Comment y remédier'), 'en ligne de commande : le code d\'un message, et sa fiche');

// ── 4. Le journal pose le code ───────────────────────────────────────────────
$t = sys_get_temp_dir() . '/larka-codes-' . bin2hex(random_bytes(3));
@mkdir("$t/logs", 0700, true);
define('LOG_PATH', "$t/logs");
require_once "$racine/api/Journal.php";
// Journal créé par une version d'avant les codes : la colonne doit s'ajouter.
$pdo = new PDO('sqlite:' . "$t/j.db");
$pdo->exec("CREATE TABLE Journal (Id INTEGER PRIMARY KEY AUTOINCREMENT, DateHeure TEXT, Niveau TEXT, Canal TEXT, Action TEXT, Message TEXT,
            UtilisateurId INTEGER, Login TEXT, Role TEXT, Ip TEXT, UserAgent TEXT, Methode TEXT, Route TEXT, CodeHttp INTEGER, DureeMs INTEGER,
            EntiteType TEXT, EntiteId INTEGER, Avant TEXT, Apres TEXT, Contexte TEXT, RequestId TEXT)");
$pdo->prepare("INSERT INTO Journal (DateHeure, Niveau, Canal, Message) VALUES (?, 'info', 'api', 'ancienne entrée')")->execute([date('Y-m-d H:i:s', time() - 3600)]);
Journal::attacherBase($pdo);
Journal::erreur('connect', '« GTB CTA » en défaut : 10.0.12.20:502 ne répond pas : probablement filtré par un pare-feu, ou équipement éteint.');
Journal::erreur('php', 'Erreur base de données', ['exception' => new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')]);
Journal::info('connect', '« GTB CTA » : communication rétablie', ['code' => 'CXC-004']);
Journal::info('interventions', 'Intervention clôturée');
Journal::warning('connect', 'code imposé inconnu', ['code' => 'ZZZ-999']);
$rows = $pdo->query("SELECT Message, Code FROM Journal ORDER BY Id")->fetchAll(PDO::FETCH_KEY_PAIR);
verif(array_key_exists('ancienne entrée', $rows) && $rows['ancienne entrée'] === null, 'journal d\'une version antérieure : colonne Code ajoutée, anciennes entrées gardées');
verif(($rows['« GTB CTA » en défaut : 10.0.12.20:502 ne répond pas : probablement filtré par un pare-feu, ou équipement éteint.'] ?? '') === 'CXR-002'
   && ($rows['Erreur base de données'] ?? '') === 'BDD-002' && ($rows['« GTB CTA » : communication rétablie'] ?? '') === 'CXC-004'
   && array_key_exists('Intervention clôturée', $rows) && $rows['Intervention clôturée'] === null,
      'code reconnu (message, exception), imposé par l\'appelant, ou absent pour une entrée ordinaire');
verif(array_key_exists('code imposé inconnu', $rows) && $rows['code imposé inconnu'] !== 'ZZZ-999', 'un code inconnu imposé par erreur n\'entre pas au journal');
$txt = (string)@file_get_contents("$t/logs/activite-" . date('Y-m-d') . '.txt');
$jsonl = (string)@file_get_contents("$t/logs/journal-" . date('Y-m-d') . '.jsonl');
verif(str_contains($txt, '[CXR-002]') && str_contains($txt, "ne répond pas (pare-feu probable) — à faire : Vérifier que l'équipement est allumé")
   && str_contains($jsonl, '"Code":"BDD-002"'), 'journal texte : [CODE] sur la ligne, titre et première chose à faire en dessous ; .jsonl codé aussi');
Journal::detacherBase();
Journal::erreur('connect', 'après détachement : exception 0x02 : adresse de registre invalide');
$n = (int)$pdo->query("SELECT COUNT(*) FROM Journal")->fetchColumn();
$txt = (string)@file_get_contents("$t/logs/activite-" . date('Y-m-d') . '.txt');
verif($n === 6 && str_contains($txt, '[CXP-001]'), 'service permanent : base détachée entre deux organisations, l\'entrée reste aux fichiers');

// ── 5. Les écrans s'en servent ───────────────────────────────────────────────
$js = (string)@file_get_contents("$racine/js/codes-erreur.js");
$html = (string)@file_get_contents("$racine/index.html");
$api = (string)@file_get_contents("$racine/js/api.js");
$cxjs = (string)@file_get_contents("$racine/js/pages/connect.js");
$jrjs = (string)@file_get_contents("$racine/js/pages/journal.js");
$pui = strpos($html, 'js/ui.js'); $pce = strpos($html, 'js/codes-erreur.js');
verif($pce !== false && $pui !== false && $pce > $pui && str_contains($js, 'function ouvrirCodeErreur') && str_contains($js, 'Comment y remédier')
   && str_contains($api, '_larkaDerniereErreur'), 'interface : fiche d\'un code, et « Que faire ? » sur les toasts d\'erreur (chargé après ui.js)');
verif(str_contains($cxjs, "apiRequest('connect_evenements'") && str_contains($cxjs, 'codeErreurBadge(pa.CodeErreur)') && str_contains($cxjs, 'codeErreurBadge(e.code)')
   && str_contains($jrjs, 'codeErreurBadge(e.Code)') && str_contains($jrjs, 'ouvrirCatalogueCodes'),
      'supervision (journal, cartes, tests) et écran Journal affichent les codes');
$idx = (string)@file_get_contents("$racine/api/index.php");
verif(str_contains($idx, "['code' => \$c]") && str_contains($idx, "'code' => \$codeErr") && str_contains($idx, "'_larka_refus'"),
      'API : code renvoyé avec chaque erreur ; raison du refus inscrite sur la ligne de la requête');

array_map('unlink', glob("$t/logs/*") ?: []); @rmdir("$t/logs"); @unlink("$t/j.db"); @rmdir($t);

echo "\n───────────────────────────────────────────────────────────────────────\n";
printf(" %s %d/%d — chaque incident dit ce qui s'est passé, pourquoi, et quoi faire.\n", $ko ? '❌' : '✅', $ok, $ok + $ko);
exit($ko ? 1 : 0);
