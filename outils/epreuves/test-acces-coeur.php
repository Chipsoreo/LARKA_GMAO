<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — épreuve des contrôles d'accès du CŒUR (hors modules)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Les autres épreuves gardent le moteur des modules. Celle-ci garde les routes
 * du cœur, où sept défauts ont été reproduits puis corrigés :
 *
 *   #3  router.php      /./.env, /js/../config.json… livraient les secrets
 *   #1  documents       un nom de fichier s'affichait brut chez le gestionnaire
 *   #2  documents       lecture, dépôt et suppression sans contrôle par fiche
 *   #4  rôles           un visionneur écrivait (interventions, archives),
 *                       n'importe qui relançait n'importe quelle demande
 *   #5  CSRF            des actions destructrices répondaient à un simple GET
 *   #6  multi-pilote    un tenant SQLite sur une installation PostgreSQL
 *                       recevait un schéma dans le mauvais dialecte
 *   #7  audit           aucune entrée du journal ne portait son auteur
 *
 * Et ceux de la seconde série :
 *
 *   #8  sessions        compte désactivé / supprimé / rétrogradé : la session
 *                       gardait ses droits jusqu'à expiration
 *   #9  multi-tenant    tenant désactivé toujours ouvert ; une connexion ratée
 *                       avec le login d'un autre tenant y basculait la session
 *   #13 mot de passe    après le changement imposé, la session restait bloquée
 *   #10 numérotation    numéros d'intervention en double après suppression
 *   #11 stock           consommation au-delà du stock, quantités négatives
 *       connexion       les connexions RÉUSSIES épuisaient la limite de débit
 *       Chorus / push   proxy Chorus ouvert à tous ; endpoint push quelconque
 *       setup           super admin configurable via un proxy local
 *       config          acces.domaine_email_autorise ignoré ; nom du cookie
 *       nginx           install.sh exécutait n'importe quel .php
 *
 * Et la copie locale des documents SharePoint (2.0.2) : un compte local qui
 * ouvrait un lien SharePoint était déconnecté ; la copie doit lui être servie,
 * avec les droits de la fiche.
 *
 * Elle est AUTONOME : elle recopie l'application dans un dossier temporaire,
 * y écrit sa propre configuration (SQLite jetable), lance le serveur intégré
 * sur un port libre et parle HTTP comme un navigateur. Elle ne touche ni à
 * votre config.json, ni à vos données — et ne peut donc pas être « sautée »
 * faute de base configurée.
 *
 *   php outils/epreuves/test-acces-coeur.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
foreach (['curl', 'pdo_sqlite'] as $x) {
    if (!extension_loaded($x)) { echo "  ⏭️  Épreuve sautée : extension « $x » absente.\n"; exit(0); }
}

$source = dirname(__DIR__, 2);
$ok = 0; $ko = [];

function verifier(string $titre, bool $vrai, string $detail, int &$ok, array &$ko): void {
    if ($vrai) { $ok++; printf("  ✅  %-52s %s\n", $titre, mb_substr($detail, 0, 30)); }
    else       { $ko[] = $titre; printf("  ❌  %-52s %s\n", $titre, mb_substr($detail, 0, 40)); }
}

// ── 1. Copie jetable de l'application ─────────────────────────────────────────
$dir = sys_get_temp_dir() . '/larka-epreuve-' . bin2hex(random_bytes(4));
$exclus = ['.git', 'outils', 'Documentations', 'config.json', 'config.key', '.env', 'tenants.json', '.user.ini'];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($source) + 1);
    $tete = explode('/', $rel)[0];
    if (in_array($tete, $exclus, true)) continue;
    // De data/, on ne garde que l'arborescence et ses garde-fous, jamais le contenu.
    if ($tete === 'data' && $f->isFile() && !in_array($f->getFilename(), ['.gitkeep', '.htaccess'], true)) continue;
    $cible = $dir . '/' . $rel;
    if ($f->isDir()) { @mkdir($cible, 0777, true); }
    else { @mkdir(dirname($cible), 0777, true); copy($f->getPathname(), $cible); }
}
$nettoyer = function () use ($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
};

// Port libre.
$s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
fclose($s);

$ecrireConfig = function (string $driverPrincipal, array $extra = []) use ($dir, $port) {
    file_put_contents($dir . '/config.json', json_encode($extra + [
        'serveur'         => ['env' => 'prod'],
        'base_de_donnees' => $driverPrincipal === 'pgsql'
            ? ['driver' => 'pgsql', 'user' => 'larka']
            : ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
        'superadmin_db'   => ['driver' => 'sqlite', 'path' => 'data/superadmin.db'],
        'securite'        => ['allowed_origin' => "http://127.0.0.1:$port", 'secret_key' => bin2hex(random_bytes(16))],
        'session'         => ['cookie_samesite' => 'Lax'],
        // Microsoft / SharePoint « actifs » (sans vrai locataire) : seules les
        // réponses faites aux comptes locaux sont éprouvées.
        'microsoft_oauth' => ['actif' => true, 'client_id' => 'epreuve', 'client_secret' => 'epreuve', 'tenant_id' => 'common'],
        'sharepoint'      => ['actif' => true],
    ], JSON_PRETTY_PRINT));
};
$ecrireConfig('sqlite');
file_put_contents($dir . '/.env', "EPREUVE_SECRET=ne-doit-jamais-sortir\n");

// ── 2. Serveur intégré ────────────────────────────────────────────────────────
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'],
                  [0 => ['pipe', 'r'], 1 => ['file', $dir . '/serveur.log', 'a'], 2 => ['file', $dir . '/serveur.log', 'a']],
                  $tubes, $dir);
if (!is_resource($proc)) { $nettoyer(); echo "  ❌  Impossible de lancer le serveur intégré.\n"; exit(1); }
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

$base = "http://127.0.0.1:$port";
$jarres = [];
/** Requête HTTP ; renvoie [code, corps décodé ou brut]. */
$http = function (string $qui, string $methode, string $chemin, $corps = null, array $entetes = [])
        use ($base, $dir, &$jarres): array {
    $jarres[$qui] ??= $dir . "/cookies-$qui.txt";
    $ch = curl_init($base . $chemin);
    $h = $entetes;
    if (is_array($corps)) { $corps = json_encode($corps); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $jarres[$qui], CURLOPT_COOKIEFILE => $jarres[$qui],
        CURLOPT_HTTPHEADER => $h, CURLOPT_PATH_AS_IS => true,
    ]);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    $rep = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode($rep, true);
    return [$code, $j ?? $rep];
};
$api = fn(string $qui, string $m, string $action, $corps = null) => $http($qui, $m, "/api/index.php?action=$action", $corps);

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — les contrôles d'accès du cœur tiennent-ils ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

try {
    // ── Comptes de l'épreuve ──────────────────────────────────────────────────
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'admin']);
    [$cAvant] = $api('adm', 'GET', 'dashboard');
    $api('adm', 'POST', 'change_password', ['oldPassword' => 'admin', 'newPassword' => 'Epreuve-Admin-1']);
    [$cApres] = $api('adm', 'GET', 'dashboard');   // même session, sans se reconnecter
    $api('adm', 'POST', 'logout');
    [$c] = $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Admin-1']);
    if ($c !== 200) throw new RuntimeException("connexion administrateur impossible (HTTP $c)");
    foreach (['dem1' => 'Demandeur', 'dem2' => 'Demandeur', 'vis1' => 'Visionneur',
              'dem3' => 'Demandeur', 'dem4' => 'Demandeur', 'vis2' => 'Visionneur'] as $l => $r) {
        $api('adm', 'POST', 'utilisateurs', ['nom' => $l, 'prenom' => 'E', 'login' => $l,
             'motDePasse' => "Epreuve-$l-1", 'role' => $r, 'provider' => 'local']);
        $api($l, 'POST', 'login', ['login' => $l, 'password' => "Epreuve-$l-1"]);
    }
    $api('adm', 'POST', 'contrats', ['numero' => 'C-1', 'societe' => 'ACME', 'type' => 'Maintenance',
         'dateDebut' => '2026-01-01', 'dateFin' => '2027-01-01']);
    $pdf = base64_encode('%PDF-1.4 epreuve');
    $png = base64_encode("\x89PNG\r\n\x1a\nepreuve");
    $api('adm', 'POST', 'documents&type=Contrat&eid=1', ['categorie' => 'pdf', 'nom' => 'contrat.pdf', 'mime' => 'application/pdf', 'data' => $pdf]);
    $api('dem1', 'POST', 'demandes', ['titre' => 'Fuite', 'description' => 'épreuve']);

    // ── #3 router.php ─────────────────────────────────────────────────────────
    echo "\n  #3 Serveur intégré : chemins détournés\n";
    foreach (['/./.env', '/js/../.env', '/api/../.env', '/%2e/.env', '/./config.json',
              '/js/../config.json', '/.%2Fconfig.json', '/js/%2e%2e/config.json'] as $p) {
        [$c, $r] = $http('anonyme', 'GET', $p);
        $fuite = is_string($r) && (str_contains($r, 'EPREUVE_SECRET') || str_contains($r, 'base_de_donnees'));
        verifier("refusé : $p", $c !== 200 && !$fuite, "HTTP $c", $ok, $ko);
    }
    [$c] = $http('anonyme', 'GET', '/js/api.js');
    verifier('servi : /js/api.js', $c === 200, "HTTP $c", $ok, $ko);

    // ── #2 / #1 documents ─────────────────────────────────────────────────────
    echo "\n  #2 Documents : contrôle par fiche   ·   #1 noms de fichiers\n";
    [$c] = $api('dem1', 'GET', 'documents&type=Contrat&eid=1');
    verifier('demandeur : liste des pièces d\'un contrat', $c === 403, "HTTP $c", $ok, $ko);
    [$c] = $api('dem1', 'GET', 'document_download&id=1');
    verifier('demandeur : téléchargement d\'une pièce de contrat', $c === 404, "HTTP $c", $ok, $ko);
    [$c] = $api('dem1', 'POST', 'documents&type=Contrat&eid=1', ['categorie' => 'photo', 'nom' => 'x.png', 'mime' => 'image/png', 'data' => $png]);
    verifier('demandeur : dépôt sur un contrat', $c === 403, "HTTP $c", $ok, $ko);
    [$c, $r] = $api('dem1', 'POST', 'documents&type=Demande&eid=1', ['categorie' => 'photo', 'nom' => '<b>fuite</b>.png', 'mime' => 'image/png', 'data' => $png]);
    verifier('demandeur : photo sur SA demande', $c === 200, "HTTP $c", $ok, $ko);
    $photo = (int)($r['data']['id'] ?? 0);
    [, $r] = $api('adm', 'GET', 'documents&type=Demande&eid=1');
    $nom = (string)($r['data'][0]['NomFichier'] ?? '');
    verifier('nom stocké sans « < » ni « > »', $nom !== '' && !preg_match('/[<>]/', $nom), $nom, $ok, $ko);
    [$c] = $api('dem2', 'GET', "document_download&id=$photo");
    verifier('autre demandeur : photo d\'une demande d\'autrui', $c === 404, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'DELETE', 'documents&id=1');
    verifier('visionneur : suppression d\'un document', $c === 403, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'GET', 'documents&type=Contrat&eid=1');
    verifier('visionneur : consultation (permise)', $c === 200, "HTTP $c", $ok, $ko);

    // ── #4 rôles en lecture seule ─────────────────────────────────────────────
    echo "\n  #4 Rôles en lecture seule\n";
    $api('adm', 'POST', 'interventions', ['type' => 'Curative', 'description' => 'épreuve']);
    [$c] = $api('vis1', 'POST', 'interventions', ['type' => 'Divers']);
    verifier('visionneur : création d\'intervention', $c === 403, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'PUT', 'interventions&id=1', ['type' => 'Divers']);
    verifier('visionneur : modification d\'intervention', $c === 403, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'DELETE', 'interventions&id=1', []);
    verifier('visionneur : suppression d\'intervention', $c === 403, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'POST', 'archives_boites', ['numeroBoite' => 'B-1']);
    verifier('visionneur : création d\'une boîte d\'archives', $c === 403, "HTTP $c", $ok, $ko);
    [$c] = $api('dem1', 'GET', 'archives_recherche');
    verifier('demandeur : recherche dans les archives', $c === 403, "HTTP $c", $ok, $ko);
    $api('adm', 'POST', 'demandes', ['titre' => 'Demande gestionnaire', 'description' => 'épreuve']);
    [$c] = $api('dem2', 'PUT', 'demandes&id=2', ['action' => 'relancer']);
    verifier('demandeur : relance de la demande d\'autrui', $c === 404, "HTTP $c", $ok, $ko);

    // ── #5 CSRF ───────────────────────────────────────────────────────────────
    echo "\n  #5 Aucune modification par un simple GET\n";
    foreach (['energie_purger_facteurs', 'energie_recalcul_carbone', 'gestion_materiel_cloturer', 'plans_delete_file'] as $a) {
        [$c] = $api('adm', 'GET', $a);
        verifier("GET refusé : $a", $c === 405, "HTTP $c", $ok, $ko);
    }
    [$c] = $http('adm', 'POST', '/api/index.php?action=energie_purger_facteurs', '{"methode":"officielle"}', ['Content-Type:']);
    verifier('corps sans Content-Type refusé', $c === 403, "HTTP $c", $ok, $ko);

    // ── #7 audit ──────────────────────────────────────────────────────────────
    echo "\n  #7 Journal d'audit\n";
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $vides = (int)$pdo->query("SELECT COUNT(*) FROM Journal WHERE Canal = 'donnees'
                                AND Route IN ('demandes','contrats','interventions') AND COALESCE(Login,'') = ''")->fetchColumn();
    $auteur = (string)$pdo->query("SELECT Login FROM Journal WHERE Canal = 'donnees' AND Message LIKE 'CREATE DemandesIntervention%'
                                   ORDER BY Id LIMIT 1")->fetchColumn();
    verifier('aucune écriture sans auteur', $vides === 0, "$vides sans auteur", $ok, $ko);
    verifier('la demande est attribuée à son auteur', $auteur === 'dem1', $auteur ?: '(vide)', $ok, $ko);
    $pdo = null;

    // ── #8 / #13 sessions ─────────────────────────────────────────────────────
    echo "\n  #8 Sessions revérifiées   ·   #13 changement de mot de passe imposé\n";
    verifier('mot de passe à changer : action refusée', $cAvant === 403, "HTTP $cAvant", $ok, $ko);
    verifier('après le changement : même session débloquée', $cApres === 200, "HTTP $cApres", $ok, $ko);
    [, $r] = $api('adm', 'GET', 'utilisateurs');
    $ids = [];
    foreach ((array)($r['data'] ?? []) as $u) $ids[$u['Login'] ?? ''] = (int)($u['Id'] ?? 0);
    $api('adm', 'PUT', 'utilisateurs&id=' . ($ids['dem3'] ?? 0), ['nom' => 'dem3', 'prenom' => 'E', 'login' => 'dem3',
         'role' => 'Demandeur', 'provider' => 'local', 'actif' => 0]);
    [$c] = $api('dem3', 'GET', 'me');
    verifier('compte désactivé : session fermée', $c === 401, "HTTP $c", $ok, $ko);
    $api('adm', 'DELETE', 'utilisateurs&id=' . ($ids['dem4'] ?? 0));
    [$c] = $api('dem4', 'GET', 'me');
    verifier('compte supprimé : session fermée', $c === 401, "HTTP $c", $ok, $ko);
    $api('adm', 'PUT', 'utilisateurs&id=' . ($ids['vis2'] ?? 0), ['nom' => 'vis2', 'prenom' => 'E', 'login' => 'vis2',
         'role' => 'Demandeur', 'provider' => 'local', 'actif' => 1]);
    [$c] = $api('vis2', 'GET', 'interventions');
    verifier('rôle retiré : droit perdu sans reconnexion', $c === 403, "HTTP $c", $ok, $ko);
    $codes = [];
    for ($i = 0; $i < 6; $i++) { [$codes[]] = $api('dem1', 'POST', 'login', ['login' => 'dem1', 'password' => 'Epreuve-dem1-1']); }
    verifier('6 connexions réussies : jamais bloquées', $codes === array_fill(0, 6, 200), implode(' ', $codes), $ok, $ko);
    $jar = (string)@file_get_contents($jarres['dem1'] ?? '');
    verifier('cookie de session nommé GMAO_SID', (bool)preg_match('/\tGMAO_SID\t/', $jar),
             preg_match('/\t(\w*SESS\w*|GMAO_SID)\t/', $jar, $m) ? $m[1] : '?', $ok, $ko);

    // ── #10 / #11 numérotation et stock ───────────────────────────────────────
    echo "\n  #10 Numéros d'intervention   ·   #11 Stock\n";
    [, $r2] = $api('adm', 'POST', 'interventions', ['type' => 'Curative', 'description' => 'n2']);
    $api('adm', 'POST', 'interventions', ['type' => 'Curative', 'description' => 'n3']);
    $api('adm', 'DELETE', 'interventions&id=' . (int)($r2['data']['id'] ?? 0));
    $api('adm', 'POST', 'interventions', ['type' => 'Curative', 'description' => 'n4']);
    [, $r] = $api('adm', 'GET', 'interventions');
    $liste = $r['data']['items'] ?? $r['data'] ?? [];
    $nums = array_map(fn($x) => (string)($x['Numero'] ?? ''), is_array($liste) ? $liste : []);
    verifier('numéros uniques après une suppression', count($nums) >= 3 && count(array_unique($nums)) === count($nums),
             implode(',', array_map(fn($n) => substr($n, -4), $nums)), $ok, $ko);
    [, $r] = $api('adm', 'POST', 'stock', ['reference' => 'R-1', 'designation' => 'Filtre', 'quantite' => 2,
                                           'seuilAlerte' => 0, 'prixUnitaire' => 3]);
    $stockId = (int)($r['data']['id'] ?? 0);
    [$c] = $api('adm', 'POST', 'interv_lignes&interv_id=1', ['stockId' => $stockId, 'quantite' => 5, 'prixUnitaire' => 3]);
    verifier('consommer 5 sur un stock de 2 : refusé', $c === 409, "HTTP $c", $ok, $ko);
    [$c] = $api('adm', 'POST', 'interv_lignes&interv_id=1', ['stockId' => $stockId, 'quantite' => -1, 'prixUnitaire' => 3]);
    verifier('quantité négative : refusée', $c === 400, "HTTP $c", $ok, $ko);

    // ── Chorus Pro, push, setup ───────────────────────────────────────────────
    echo "\n  Chorus Pro · notifications push · configuration initiale\n";
    [$c] = $api('dem1', 'POST', 'chorus_call', ['path' => '/cpro/factures/v1', 'payload' => []]);
    verifier('demandeur : proxy Chorus Pro', $c === 403, "HTTP $c", $ok, $ko);
    $cles = ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('A', 22)];
    [$c] = $api('dem1', 'POST', 'push_subscribe', ['subscription' => ['endpoint' => "http://127.0.0.1:$port/api/index.php", 'keys' => $cles]]);
    verifier('push : endpoint interne refusé', $c === 400, "HTTP $c", $ok, $ko);
    [$c] = $api('dem1', 'POST', 'push_subscribe', ['subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/epreuve', 'keys' => $cles]]);
    verifier('push : service du navigateur accepté', $c === 200, "HTTP $c", $ok, $ko);
    $saCorps = ['login' => 'sa', 'password' => 'Epreuve-SA-12', 'email' => 'sa@epreuve.test'];
    [$c] = $http('proxy', 'POST', '/api/index.php?action=superadmin_setup', $saCorps,
                 ['X-Forwarded-For: 203.0.113.9', 'X-Forwarded-Proto: https']);
    verifier('setup super admin relayé par un proxy : refusé', $c === 403, "HTTP $c", $ok, $ko);

    // ── SharePoint : copie locale pour les comptes sans Microsoft ─────────────
    echo "\n  SharePoint : comptes locaux et copie dans Larka\n";
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $pdo->exec("INSERT INTO Documents (EntiteType,EntiteId,NomFichier,TypeMime,Categorie,Taille,Donnees,DateAjout,AjoutePar,SharePointUrl)
                VALUES ('Contrat',1,'Plan.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'autre',10,'','2026-01-01 00:00:00','admin','https://contoso.sharepoint.com/sites/x/Plan.docx')");
    $spId = (int)$pdo->lastInsertId();
    [$c] = $api('vis1', 'GET', "sharepoint_check&id=$spId");
    verifier('compte local : état du lien sans déconnexion', $c === 200, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'GET', "document_preview&id=$spId");
    verifier('lien sans copie : rien à servir', $c === 404, "HTTP $c", $ok, $ko);
    $pdo->prepare("UPDATE Documents SET Donnees = ?, CopieLocaleMime = 'application/pdf', CopieLocaleDate = '2026-01-02 00:00:00' WHERE Id = ?")
        ->execute([base64_encode("%PDF-1.4 copie epreuve"), $spId]);
    $pdo = null;
    [$c, $r] = $api('vis1', 'GET', "document_preview&id=$spId");
    verifier('copie locale : consultable par un compte local', $c === 200 && is_string($r) && str_starts_with($r, '%PDF'), "HTTP $c", $ok, $ko);
    [$c] = $api('dem1', 'GET', "document_preview&id=$spId");
    verifier('copie locale : droits de la fiche appliqués', $c === 404, "HTTP $c", $ok, $ko);
    [$c] = $api('vis1', 'DELETE', "sharepoint_copie_locale&id=$spId");
    verifier('visionneur : retrait de la copie refusé', $c === 403, "HTTP $c", $ok, $ko);

    // ── #9 multi-tenant ───────────────────────────────────────────────────────
    echo "\n  #9 Multi-tenant\n";
    $api('sa', 'POST', 'superadmin_setup', $saCorps);   // depuis le serveur lui-même
    [$c] = $api('sa', 'POST', 'superadmin_login', ['login' => 'sa', 'password' => 'Epreuve-SA-12']);
    if ($c !== 200) throw new RuntimeException("connexion super admin impossible (HTTP $c)");
    foreach (['alpha' => 'a1', 'beta' => 'b1'] as $t => $u) {
        $api('sa', 'POST', 'superadmin_tenant_save', ['key' => $t, 'nom' => $t, 'actif' => true,
             'domaines_email' => ["$t.test"], 'db_driver' => 'sqlite']);
        $api('sa', 'POST', 'superadmin_provision', ['key' => $t]);
        $api('sa', 'POST', 'superadmin_tenant_user_save', ['tenant_key' => $t, 'login' => $u, 'nom' => $u,
             'prenom' => 'E', 'role' => 'Gestionnaire', 'provider' => 'local', 'motDePasse' => "Epreuve-$u-1"]);
    }
    $api('a1', 'POST', 'login', ['login' => 'a1', 'password' => 'Epreuve-a1-1']);
    $api('a1', 'POST', 'login', ['login' => 'b1', 'password' => 'mauvais']);
    [, $r] = $api('a1', 'GET', 'me');
    $ou = ($r['data']['Login'] ?? '?') . '@' . ($r['data']['_tenant'] ?? '?');
    verifier('connexion ratée vers un autre tenant : session intacte', $ou === 'a1@alpha', $ou, $ok, $ko);
    $api('sa', 'POST', 'superadmin_tenant_save', ['key' => 'alpha', 'nom' => 'alpha', 'actif' => false,
         'domaines_email' => ['alpha.test'], 'db_driver' => 'sqlite']);
    [$c] = $api('a1', 'GET', 'me');
    verifier('tenant désactivé : session fermée', $c === 401, "HTTP $c", $ok, $ko);
    [$c] = $api('a1b', 'POST', 'login', ['login' => 'a1', 'password' => 'Epreuve-a1-1']);
    verifier('tenant désactivé : connexion refusée', $c === 403, "HTTP $c", $ok, $ko);
} catch (\Throwable $e) {
    $ko[] = 'déroulé : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

// Arrêt du serveur (avant de modifier la configuration).
proc_terminate($proc); proc_close($proc);

// ── #6 multi-pilote : installation PostgreSQL, tenant SQLite ─────────────────
// Processus séparé : config.php définit des constantes, une par processus.
echo "\n  #6 Tenant SQLite sur une installation PostgreSQL\n";
// Repartir d'un registre de tenants vide : la section #9 en a créé, et un
// serveur multi-tenant choisirait la base d'un tenant plutôt que config.json.
@unlink($dir . '/data/superadmin.db');
$ecrireConfig('pgsql');
$script = <<<'PHP'
<?php
require 'api/config.php'; require 'api/Database.php';
$cfg = ['driver' => 'sqlite', 'path' => 'data/tenant_epreuve.db'];
TenantResolver::upsertTenant('epreuve', ['nom' => 'Épreuve', 'base_de_donnees' => $cfg]);
TenantResolver::provisionDatabase('epreuve');
$db = new Database($cfg); $db->ensureFullSchema();
$id = $db->addUtilisateur(['nom' => 'A', 'prenom' => 'B', 'login' => 'admin.epreuve', 'email' => 'a@e.fr',
                           'motDePasse' => 'Epreuve-1', 'role' => 'Gestionnaire', 'provider' => 'local']);
$p = new PDO('sqlite:data/tenant_epreuve.db');
$cols = array_column($p->query("PRAGMA table_info(Utilisateurs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
echo json_encode(['pilote' => DB_DRIVER, 'id' => $id, 'email' => in_array('Email', $cols, true)]);
PHP;
file_put_contents($dir . '/epreuve-pilote.php', $script);
// Exécuté DANS la copie jetable : les chemins relatifs du script s'y résolvent.
$sortie = (string)shell_exec('cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg(PHP_BINARY) . ' epreuve-pilote.php 2>&1');
$res = json_decode(trim((string)strrchr("\n" . $sortie, '{')), true) ?: [];
verifier('compte admin créé dans le tenant SQLite', ($res['id'] ?? 0) > 0 && ($res['pilote'] ?? '') === 'pgsql',
         'pilote requête : ' . ($res['pilote'] ?? '?'), $ok, $ko);
verifier('colonnes migrées (Email…)', !empty($res['email']), $res ? 'Email présent' : mb_substr(trim($sortie), 0, 40), $ok, $ko);

// ── acces.domaine_email_autorise ─────────────────────────────────────────────
echo "\n  Domaine email autorisé (config.json)\n";
$ecrireConfig('sqlite', ['acces' => ['domaine_email_autorise' => 'exemple.fr']]);
file_put_contents($dir . '/epreuve-domaine.php', <<<'PHP'
<?php
require 'api/config.php'; require 'api/Database.php';
$db = new Database();
echo json_encode(['ok' => $db->isEmailDomainAllowed('agent@exemple.fr'), 'autre' => $db->isEmailDomainAllowed('x@autre.fr')]);
PHP);
$sortie = (string)shell_exec('cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg(PHP_BINARY) . ' epreuve-domaine.php 2>&1');
$res = json_decode(trim((string)strrchr("\n" . $sortie, '{')), true) ?: [];
verifier('domaine autorisé : accepté', ($res['ok'] ?? null) === true, json_encode($res['ok'] ?? null), $ok, $ko);
verifier('autre domaine : refusé', ($res['autre'] ?? null) === false, json_encode($res['autre'] ?? null), $ok, $ko);

// ── deploy/install.sh : nginx n'exécute que les points d'entrée ──────────────
echo "\n  Configuration nginx générée par deploy/install.sh\n";
$inst = (string)@file_get_contents($source . '/deploy/install.sh');
preg_match_all('/location\s+([^{]*)\{[^{}]*fastcgi_pass/', $inst, $m);
$blocs = array_map('trim', $m[1]);
$hors = array_filter($blocs, fn($b) => !str_contains($b, 'api/index'));
verifier('PHP exécuté uniquement en liste blanche', $blocs !== [] && $hors === [],
         $hors ? 'hors liste : ' . reset($hors) : count($blocs) . ' bloc(s) FastCGI', $ok, $ko);
verifier('dossier data/ refusé en priorité (^~)', str_contains($inst, 'location ^~ /data/'), '', $ok, $ko);

$nettoyer();

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — les contrôles d'accès du cœur tiennent.\n", $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $t) echo "      • $t\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);
