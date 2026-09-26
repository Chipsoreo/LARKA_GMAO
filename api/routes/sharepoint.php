<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : SharePoint (via Microsoft Graph — autodétection)
 *
 * Principe : AUCUNE configuration admin (site_id, drive_id…).
 * Tout est découvert dynamiquement via le token Microsoft de l'utilisateur connecté.
 * L'utilisateur voit exactement les sites/drives auxquels son compte a accès.
 *
 * Actions :
 *   sharepoint_status  → vérifie si SP est dispo (Microsoft OAuth actif + token en session)
 *   sharepoint_sites   → sites auxquels l'utilisateur a accès (suivis + recherche)
 *   sharepoint_drives  → bibliothèques de documents d'un site
 *   sharepoint_files   → contenu d'un dossier (ou racine d'un drive)
 *   sharepoint_search  → recherche de fichiers dans un drive
 *   sharepoint_link    → créer un raccourci vers un fichier SP dans la table Documents
 *   sharepoint_mydrives → les drives personnels de l'utilisateur (OneDrive)
 */

// ── Helper : redirection sûre (anti open-redirect, F7) ───────────────────────
// N'autorise une redirection Location: que vers un hôte Microsoft / SharePoint
// légitime. Empêche qu'une URL stockée par l'utilisateur (SharePointUrl fournie
// à sharepoint_link) serve d'open redirect exploitable en hameçonnage.
function _sp_is_safe_redirect(string $url): bool {
    $p = parse_url(trim($url));
    if (!$p || (($p['scheme'] ?? '') !== 'https') || empty($p['host'])) return false;
    $host = strtolower($p['host']);
    $allowed = [
        'sharepoint.com', 'sharepointonline.com', 'onedrive.com', 'onedrive.live.com',
        '1drv.ms', 'graph.microsoft.com', 'office.com', 'office365.com',
        'microsoft.com', 'microsoftonline.com',
    ];
    foreach ($allowed as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    }
    return false;
}

// ── Helper : appel Microsoft Graph ───────────────────────────────────────────
function graphApi(string $endpoint, string $accessToken): array {
    $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);

    if ($curlErr) json_error('Erreur réseau Graph : ' . $curlErr, 502);
    $data = json_decode($resp, true);
    if ($httpCode === 401) json_error('Token Microsoft expiré. Reconnectez-vous.', 401);
    if ($httpCode === 403) json_error('Accès refusé. Votre compte n\'a pas les permissions SharePoint nécessaires.', 403);
    if ($httpCode >= 400)  json_error($data['error']['message'] ?? "Erreur Graph HTTP $httpCode", $httpCode);
    return $data;
}

// ── Helper : appel Graph qui NE stoppe PAS sur erreur ────────────────────────
//    Retourne ['status'=>int, 'data'=>array]. Utilisé par la résolution de lien
//    pour distinguer proprement « fichier supprimé » (404) des autres erreurs.
function graphTry(string $endpoint, string $accessToken): array {
    $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    if ($err) return ['status' => 0, 'data' => [], 'error' => $err];
    return ['status' => $status, 'data' => json_decode($resp, true) ?: []];
}

// ── Helper : POST vers Graph (pour l'API Microsoft Search /search/query) ──────
function graphPost(string $endpoint, string $jsonBody, string $accessToken): array {
    $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($resp, true) ?: [];
    if ($status >= 400) json_error($data['error']['message'] ?? "Erreur Graph HTTP $status", $status);
    return $data;
}

// ── Helper : rafraîchir le token si expiré ───────────────────────────────────
function ensureMsToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $token   = $_SESSION['ms_access_token']  ?? '';
    $refresh = $_SESSION['ms_refresh_token'] ?? '';
    $expires = $_SESSION['ms_token_expires'] ?? 0;

    if (!$token) json_error('Aucun token Microsoft en session. Reconnectez-vous via Microsoft.', 401);

    // Si le token expire dans moins de 5 minutes, tenter un refresh
    if ($refresh && time() > ($expires - 300)) {
        $ch = curl_init('https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => MICROSOFT_CLIENT_ID,
                'client_secret' => MICROSOFT_CLIENT_SECRET,
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
                'scope'         => 'openid profile email offline_access User.Read User.ReadBasic.All Files.Read.All Sites.Read.All'
                                   . ((defined('PLANS_PRESENCE_ENABLED') && PLANS_PRESENCE_ENABLED) ? ' Presence.Read.All Calendars.Read.Shared' : ''),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 200) {
            $newToken = json_decode($resp, true);
            if (!empty($newToken['access_token'])) {
                $_SESSION['ms_access_token']  = $newToken['access_token'];
                $_SESSION['ms_refresh_token'] = $newToken['refresh_token'] ?? $refresh;
                $_SESSION['ms_token_expires'] = time() + ($newToken['expires_in'] ?? 3600);
                return $newToken['access_token'];
            }
        }
    }

    return $token;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ROUTES
// ═══════════════════════════════════════════════════════════════════════════════

// ── Statut : est-ce que SharePoint est utilisable pour cet utilisateur ? ──────
if ($action === 'sharepoint_status') {
    require_auth();
    $hasToken = !empty($_SESSION['ms_access_token'] ?? '');

    // Tester rapidement si le token donne accès à SharePoint
    $hasAccess = false;
    if ($hasToken && SHAREPOINT_ENABLED) {
        try {
            $t = ensureMsToken();
            graphApi('sites/root?$select=id', $t);
            $hasAccess = true;
        } catch (\Exception $e) {
            // Pas d'accès SharePoint (permissions ou tenant sans SP)
            $hasAccess = false;
        }
    }

    json_ok([
        'enabled'   => SHAREPOINT_ENABLED && $hasAccess,
        'hasToken'  => $hasToken,
        'hasAccess' => $hasAccess,
        // Mode consultation seule : l'UI masque les boutons de téléchargement
        // (le refus réel est appliqué côté serveur dans sharepoint_download).
        'lectureSeule' => ($db->getConfig('sharepoint_lecture_seule') === '1'),
    ]);
}

// ── Tenant : host SharePoint interne + domaine de l'identité connectée ────────
//    Sert à séparer les sites « internes » (même host SharePoint que le tenant,
//    ex. contoso.sharepoint.com) des sites externes/invités, et à étiqueter le
//    groupe interne avec le domaine mail de l'utilisateur (ex. eau-adour-garonne.fr).
//    N'exige aucune permission supplémentaire (sites/root + identité de session).
if ($action === 'sharepoint_tenant' && $method === 'GET') {
    $user = require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    $host = '';
    try {
        $token = ensureMsToken();
        $root  = graphApi('sites/root?$select=webUrl', $token);
        $host  = parse_url($root['webUrl'] ?? '', PHP_URL_HOST) ?: '';
    } catch (\Exception $e) { /* host inconnu → pas de séparation, non bloquant */ }

    // Domaine « interne » = domaine de l'identité connectée (partie après @).
    $domain = '';
    $login  = $user['Login'] ?? '';
    if (strpos($login, '@') !== false) {
        $domain = substr(strrchr($login, '@'), 1);
    }

    json_ok(['host' => strtolower($host), 'domain' => strtolower($domain)]);
}

// ── Sites : sites suivis par l'utilisateur + recherche ───────────────────────
if ($action === 'sharepoint_sites' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);
    $token = ensureMsToken();

    $search = trim($_GET['q'] ?? '');
    $sites  = [];
    $seen   = [];

    // Nombre de résultats souhaité (recherche / liste complète), borné pour éviter
    // les requêtes abusives. Par défaut 50.
    $top = (int)($_GET['top'] ?? 50);
    if ($top < 1)   $top = 50;
    if ($top > 200) $top = 200;

    // 1) Sites suivis par l'utilisateur (ses favoris SharePoint)
    try {
        $followed = graphApi('me/followedSites?$select=id,displayName,webUrl,description&$top=100', $token);
        foreach ($followed['value'] ?? [] as $s) {
            $sites[] = [
                'id'          => $s['id'],
                'name'        => $s['displayName'] ?? '',
                'url'         => $s['webUrl'] ?? '',
                'description' => $s['description'] ?? '',
                'followed'    => true,
            ];
            $seen[$s['id']] = true;
        }
    } catch (\Exception $e) { /* pas grave, on continue */ }

    // 2) Recherche par nom, ou liste complète du tenant — mais UNIQUEMENT sur
    //    demande explicite. Sans paramètre, on ne renvoie QUE les sites suivis
    //    (ci-dessus), pour ne pas exposer tout le tenant par défaut.
    //      q="<terme>" → recherche ciblée (ex. « BATIMENTS »)
    //      q="*"       → liste complète (déclenchée par le bouton « voir tous »)
    if ($search !== '') {
        $searchData = graphApi(
            'sites?search=' . urlencode($search === '*' ? '*' : $search)
            . '&$select=id,displayName,webUrl,description&$top=' . $top,
            $token
        );
        foreach ($searchData['value'] ?? [] as $s) {
            if (isset($seen[$s['id']])) continue;
            $sites[] = [
                'id'          => $s['id'],
                'name'        => $s['displayName'] ?? '',
                'url'         => $s['webUrl'] ?? '',
                'description' => $s['description'] ?? '',
                'followed'    => false,
            ];
        }
    }

    json_ok($sites);
}

// ── Drives personnels (OneDrive de l'utilisateur) ────────────────────────────
if ($action === 'sharepoint_mydrives' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);
    $token  = ensureMsToken();

    $data = graphApi('me/drives?$select=id,name,driveType,webUrl', $token);
    $drives = [];
    foreach ($data['value'] ?? [] as $d) {
        $drives[] = [
            'id'   => $d['id'],
            'name' => $d['name'] ?? 'OneDrive',
            'type' => $d['driveType'] ?? 'personal',
            'url'  => $d['webUrl'] ?? '',
        ];
    }
    json_ok($drives);
}

// ── Drives d'un site SharePoint ──────────────────────────────────────────────
if ($action === 'sharepoint_drives' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);
    $token  = ensureMsToken();
    $siteId = $_GET['siteId'] ?? '';
    if (!$siteId) json_error('siteId requis.', 400);

    $data   = graphApi("sites/$siteId/drives?\$select=id,name,driveType,webUrl", $token);
    $drives = [];
    foreach ($data['value'] ?? [] as $d) {
        $drives[] = [
            'id'   => $d['id'],
            'name' => $d['name'] ?? '',
            'type' => $d['driveType'] ?? '',
            'url'  => $d['webUrl'] ?? '',
        ];
    }
    json_ok($drives);
}

// ── Contenu d'un dossier (ou racine) ─────────────────────────────────────────
if ($action === 'sharepoint_files' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);
    $token   = ensureMsToken();
    $driveId = $_GET['driveId'] ?? '';
    $itemId  = $_GET['itemId']  ?? '';
    if (!$driveId) json_error('driveId requis.', 400);

    $endpoint = $itemId
        ? "drives/$driveId/items/$itemId/children"
        : "drives/$driveId/root/children";
    $endpoint .= '?$select=id,name,size,file,folder,webUrl,lastModifiedDateTime,parentReference&$top=200&$orderby=name';

    $data  = graphApi($endpoint, $token);
    $items = [];
    foreach ($data['value'] ?? [] as $f) {
        $items[] = [
            'id'         => $f['id'],
            'name'       => $f['name'] ?? '',
            'size'       => $f['size'] ?? 0,
            'isFolder'   => isset($f['folder']),
            'childCount' => $f['folder']['childCount'] ?? null,
            'mimeType'   => $f['file']['mimeType'] ?? null,
            'webUrl'     => $f['webUrl'] ?? '',
            'modified'   => $f['lastModifiedDateTime'] ?? '',
            'parentId'   => $f['parentReference']['id'] ?? '',
            'driveId'    => $f['parentReference']['driveId'] ?? $driveId,
        ];
    }
    json_ok($items);
}

// ── Recherche dans un drive ──────────────────────────────────────────────────
if ($action === 'sharepoint_search' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);
    $token   = ensureMsToken();
    $driveId = $_GET['driveId'] ?? '';
    $query   = $_GET['q'] ?? '';
    if (!$driveId) json_error('driveId requis.', 400);
    if (!$query)   json_error('Requête de recherche vide.', 400);

    // Recherche RÉCURSIVE sur tout le drive : root/search parcourt toute la
    // hiérarchie (sous-dossiers compris). On inclut désormais les DOSSIERS dans
    // les résultats pour pouvoir retrouver — et mettre en favori — un dossier
    // profond, pas seulement les fichiers.
    $data  = graphApi("drives/$driveId/root/search(q='" . urlencode($query) . "')?&\$select=id,name,size,file,folder,webUrl,lastModifiedDateTime,parentReference&\$top=100", $token);
    $items = [];
    foreach ($data['value'] ?? [] as $f) {
        $isFolder = isset($f['folder']);
        // Chemin lisible du parent (ex. « Marchés/2024 ») pour situer un résultat.
        $parentPath = '';
        if (!empty($f['parentReference']['path'])) {
            $p   = $f['parentReference']['path'];
            $pos = strpos($p, 'root:');
            $parentPath = $pos !== false ? ltrim(substr($p, $pos + 5), '/') : '';
            $parentPath = rawurldecode($parentPath);
        }
        $items[] = [
            'id'         => $f['id'],
            'name'       => $f['name'] ?? '',
            'size'       => $f['size'] ?? 0,
            'isFolder'   => $isFolder,
            'childCount' => $f['folder']['childCount'] ?? null,
            'mimeType'   => $f['file']['mimeType'] ?? null,
            'webUrl'     => $f['webUrl'] ?? '',
            'modified'   => $f['lastModifiedDateTime'] ?? '',
            'driveId'    => $driveId,
            'parentPath' => $parentPath,
        ];
    }
    json_ok($items);
}

// ── Recherche GLOBALE de fichiers (API Microsoft Search) ─────────────────────
//    Cherche un fichier/dossier par son nom dans TOUT SharePoint / OneDrive
//    accessible à l'utilisateur, sans avoir à ouvrir une bibliothèque précise.
//    N'exige aucune permission de plus (utilise Files.Read.All / Sites.Read.All).
if ($action === 'sharepoint_search_files' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    $query = trim($_GET['q'] ?? '');
    if ($query === '') json_error('Requête de recherche vide.', 400);
    $size = (int)($_GET['top'] ?? 30);
    if ($size < 1)   $size = 30;
    if ($size > 100) $size = 100;

    $token = ensureMsToken();
    $body  = json_encode([
        'requests' => [[
            'entityTypes' => ['driveItem'],
            'query'       => ['queryString' => $query],
            'from'        => 0,
            'size'        => $size,
        ]],
    ]);
    $data = graphPost('search/query', $body, $token);

    $items = [];
    foreach ($data['value'] ?? [] as $resp) {
        foreach ($resp['hitsContainers'] ?? [] as $hc) {
            foreach ($hc['hits'] ?? [] as $hit) {
                $r = $hit['resource'] ?? [];
                if (empty($r['id'])) continue;
                $isFolder = isset($r['folder']);
                $parentPath = '';
                if (!empty($r['parentReference']['path'])) {
                    $p   = $r['parentReference']['path'];
                    $pos = strpos($p, 'root:');
                    $parentPath = $pos !== false ? ltrim(substr($p, $pos + 5), '/') : '';
                    $parentPath = rawurldecode($parentPath);
                }
                $items[] = [
                    'id'         => $r['id'],
                    'name'       => $r['name'] ?? '',
                    'size'       => $r['size'] ?? 0,
                    'isFolder'   => $isFolder,
                    'mimeType'   => $r['file']['mimeType'] ?? null,
                    'webUrl'     => $r['webUrl'] ?? '',
                    'driveId'    => $r['parentReference']['driveId'] ?? '',
                    'siteId'     => $r['parentReference']['siteId'] ?? '',
                    'parentPath' => $parentPath,
                ];
            }
        }
    }
    json_ok($items);
}

// ── Vignettes d'un document ───────────────────────────────────────────────────
// PAS de génération locale de miniatures (.thumb) : Microsoft Graph fabrique et
// héberge DÉJÀ des vignettes pour chaque fichier (PDF, Office, images, vidéos...).
// On les récupère à la demande : GET /drives/{driveId}/items/{itemId}/thumbnails
// → 3 tailles (small ~96px, medium ~176px, large ~800px), servies par des URLs
// pré-authentifiées à durée de vie courte (~1h) — utilisables directement dans
// un <img src> côté client, sans token.
// Usage : ?action=sharepoint_thumbnail&driveId=...&itemId=...
if ($action === 'sharepoint_thumbnail' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    $driveId = trim($_GET['driveId'] ?? '');
    $itemId  = trim($_GET['itemId']  ?? '');
    if ($driveId === '' || $itemId === '') json_error('driveId et itemId requis.', 400);
    // Les IDs Graph sont alphanum + quelques séparateurs — on refuse le reste
    // (évite toute injection dans le chemin de l'endpoint).
    if (!preg_match('/^[A-Za-z0-9!_\-\.]{1,200}$/', $driveId) ||
        !preg_match('/^[A-Za-z0-9!_\-\.]{1,200}$/', $itemId)) {
        json_error('Identifiants invalides.', 400);
    }

    $token = ensureMsToken();
    $data  = graphTry("drives/$driveId/items/$itemId/thumbnails", $token);

    $set = $data['value'][0] ?? null;
    if (!$set) json_ok(['available' => false]); // pas de vignette pour ce type de fichier

    $pick = fn(?array $t) => $t ? ['url' => $t['url'] ?? '', 'width' => $t['width'] ?? 0, 'height' => $t['height'] ?? 0] : null;
    json_ok([
        'available' => true,
        'small'  => $pick($set['small']  ?? null),
        'medium' => $pick($set['medium'] ?? null),
        'large'  => $pick($set['large']  ?? null),
        // NB client : URLs valables ~1h. Ne pas les stocker en BDD ; les
        // redemander à l'affichage (elles sont peu coûteuses côté Graph).
    ]);
}

// ── Résout driveId + itemId d'un document lié ────────────────────────────────
// Depuis les colonnes stockées ; pour les anciens liens sans driveId, via
// l'URL de partage (endpoint Graph /shares/). Rend donc les anciens raccourcis
// exploitables pour l'aperçu et le téléchargement.
function spItemRef(array $doc, string $token): array {
    $driveId = $doc['SharePointDriveId']     ?? '';
    $itemId  = $doc['SharePointDriveItemId'] ?? '';
    if ($driveId !== '' && $itemId !== '') return ['driveId' => $driveId, 'itemId' => $itemId];

    $url = $doc['SharePointUrl'] ?? '';
    if ($url === '') return ['driveId' => '', 'itemId' => ''];

    // "Share ID" attendu par /shares/ : u! + base64url(URL) sans padding.
    $share = 'u!' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $res   = graphTry("shares/" . rawurlencode($share) . "/driveItem?\$select=id,parentReference", $token);
    if ($res['status'] === 200 && !empty($res['data']['id'])) {
        return [
            'driveId' => $res['data']['parentReference']['driveId'] ?? '',
            'itemId'  => $res['data']['id'],
        ];
    }
    return ['driveId' => '', 'itemId' => ''];
}

//    Insensible aux renommages et aux déplacements du fichier sur SharePoint.
//    - 200 → { url, name } (URL live) ; le raccourci est auto-réparé au passage.
//    - 404 → le fichier n'existe plus (supprimé ou hors de portée du compte).
if ($action === 'sharepoint_resolve' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    $docId = (int)($_GET['id'] ?? 0);
    if (!$docId) json_error('Identifiant de document requis.', 400);

    $doc = $db->getSharePointDoc($docId);
    if (!$doc) json_error('Document introuvable.', 404);

    $token   = ensureMsToken();
    $ref     = spItemRef($doc, $token);
    $driveId = $ref['driveId'];
    $itemId  = $ref['itemId'];

    // Lien vraiment inexploitable (ni identifiants, ni résolution par URL).
    if (!$driveId || !$itemId) {
        $legacy = $doc['SharePointUrl'] ?? '';
        if (!$legacy) json_error('Ce lien SharePoint est incomplet et ne peut pas être ouvert.', 422);
        json_ok(['url' => $legacy, 'name' => $doc['NomFichier'] ?? '', 'legacy' => true]);
    }

    $res   = graphTry("drives/$driveId/items/$itemId?\$select=id,name,webUrl,deleted", $token);

    if ($res['status'] === 401) json_error('Token Microsoft expiré. Reconnectez-vous via Microsoft.', 401);
    if ($res['status'] === 404 || isset($res['data']['deleted'])) {
        json_error('Ce fichier n\'existe plus sur SharePoint (déplacé ou supprimé).', 404);
    }
    if ($res['status'] >= 400 || empty($res['data']['webUrl'])) {
        json_error($res['data']['error']['message'] ?? 'Impossible de récupérer le fichier sur SharePoint.', $res['status'] ?: 502);
    }

    $liveUrl = $res['data']['webUrl'];
    $liveNom = $res['data']['name'] ?? ($doc['NomFichier'] ?? '');

    // Auto-réparation : si l'URL ou le nom a changé côté SharePoint, on met à
    // jour le raccourci en base pour que la liste affiche le nom courant.
    if ($liveUrl !== ($doc['SharePointUrl'] ?? '') || $liveNom !== ($doc['NomFichier'] ?? '')) {
        try { $db->updateSharePointCache($docId, $liveUrl, $liveNom); } catch (\Exception $e) { /* non bloquant */ }
    }

    json_ok(['url' => $liveUrl, 'name' => $liveNom]);
}

// ── Téléchargement direct d'un fichier lié ───────────────────────────────────
//    Résout l'identifiant stable → @microsoft.graph.downloadUrl (URL courte,
//    pré-authentifiée) → redirection 302 : le navigateur télécharge le fichier.
if ($action === 'sharepoint_download' && $method === 'GET') {
    $__u = require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    // Mode « consultation seule » : les documents SharePoint restent
    // consultables (stream inline via sharepoint_content) mais l'export en
    // pièce jointe est refusé. Le contrôle est ICI et pas seulement dans l'UI :
    // masquer un bouton côté client n'empêche personne d'appeler l'URL.
    if ($db->getConfig('sharepoint_lecture_seule') === '1') {
        json_error('Téléchargement désactivé : ces documents sont en consultation seule.', 403);
    }

    $docId = (int)($_GET['id'] ?? 0);
    if (!$docId) json_error('Identifiant de document requis.', 400);

    $doc = $db->getSharePointDoc($docId);
    if (!$doc) json_error('Document introuvable.', 404);
    error_log('[Larka][AUDIT] sharepoint_download id=' . (int)$docId . ' user=' . ($__u['Login'] ?? '?') . ' role=' . ($__u['Role'] ?? '?')); // F6

    $fallbackUrl = $doc['SharePointUrl'] ?? '';

    $token   = ensureMsToken();
    $ref     = spItemRef($doc, $token);
    $driveId = $ref['driveId'];
    $itemId  = $ref['itemId'];

    // Vraiment inexploitable : on renvoie vers la page SharePoint.
    if (!$driveId || !$itemId) {
        if ($fallbackUrl && _sp_is_safe_redirect($fallbackUrl)) { header('Location: ' . $fallbackUrl); exit; } // F7
        json_error('Lien SharePoint incomplet.', 422);
    }

    $res   = graphTry("drives/$driveId/items/$itemId", $token);

    if ($res['status'] === 404 || isset($res['data']['deleted'])) {
        json_error('Ce fichier n\'existe plus sur SharePoint.', 404);
    }
    $dl = $res['data']['@microsoft.graph.downloadUrl'] ?? '';
    if ($dl === '') {
        if ($fallbackUrl && _sp_is_safe_redirect($fallbackUrl)) { header('Location: ' . $fallbackUrl); exit; } // F7
        json_error('Impossible d\'obtenir le lien de téléchargement.', $res['status'] ?: 502);
    }

    // On STREAME le fichier via Larka (Content-Disposition: attachment). Une
    // simple redirection 302 provoquait un téléchargement de 0 octet dans
    // certains navigateurs (l'attribut download téléchargeait la réponse vide).
    $name = preg_replace('/[^\w.\- ]/u', '_', $res['data']['name'] ?? 'fichier');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');

    $ch = curl_init($dl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, // H3 : ne suivre que des redirections HTTPS
        CURLOPT_MAXREDIRS      => 5,                // H3 : limiter les chaînes de redirection
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HEADER         => false,
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) { echo $chunk; return strlen($chunk); },
    ]);
    curl_exec($ch);
    exit;
}

// ── Diffusion du contenu d'un fichier lié (pour l'aperçu intégré) ────────────
//    Streame le fichier depuis SharePoint via Larka (même origine) pour pouvoir
//    l'afficher dans une iframe/img sans être bloqué par les navigateurs.
if ($action === 'sharepoint_content' && $method === 'GET') {
    require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    $docId = (int)($_GET['id'] ?? 0);
    if (!$docId) json_error('Identifiant de document requis.', 400);

    $doc = $db->getSharePointDoc($docId);
    if (!$doc) json_error('Document introuvable.', 404);

    $token   = ensureMsToken();
    $ref     = spItemRef($doc, $token);
    $driveId = $ref['driveId'];
    $itemId  = $ref['itemId'];
    if (!$driveId || !$itemId) json_error('Lien SharePoint incomplet.', 422);

    $meta  = graphTry("drives/$driveId/items/$itemId", $token);
    if ($meta['status'] === 404 || isset($meta['data']['deleted'])) json_error('Ce fichier n\'existe plus sur SharePoint.', 404);
    $name = preg_replace('/[^\w.\- ]/u', '_', $meta['data']['name'] ?? 'fichier');

    // format=pdf : conversion Office → PDF par Graph (Word/Excel/PowerPoint).
    $wantPdf = (($_GET['format'] ?? '') === 'pdf');
    if ($wantPdf) {
        $mime   = 'application/pdf';
        $srcUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId) . '/content?format=pdf';
        $authHeader = ['Authorization: Bearer ' . $token];
    } else {
        $mime   = $meta['data']['file']['mimeType'] ?? 'application/octet-stream';
        $srcUrl = $meta['data']['@microsoft.graph.downloadUrl'] ?? '';
        if ($srcUrl === '') json_error('Impossible de récupérer le fichier.', $meta['status'] ?: 502);
        $authHeader = []; // downloadUrl est pré-authentifiée
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    // En consultation seule on interdit la mise en cache disque du navigateur :
    // le flux reste affichable mais ne laisse pas de copie persistante.
    header('Cache-Control: ' . ($db->getConfig('sharepoint_lecture_seule') === '1'
        ? 'no-store, no-cache, must-revalidate'
        : 'private, max-age=60'));

    // Streaming direct depuis Graph vers la sortie.
    $ch = curl_init($srcUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, // H3 : ne suivre que des redirections HTTPS
        CURLOPT_MAXREDIRS      => 5,                // H3 : limiter les chaînes de redirection
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => $authHeader,
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) { echo $chunk; return strlen($chunk); },
    ]);
    curl_exec($ch);
    exit;
}

// ── État d'un lien : SharePoint est-il joignable pour CE document ? ──────────
// Appelé avant d'ouvrir un aperçu. Ne transfère jamais le contenu du fichier :
// on interroge uniquement les métadonnées Graph. Renvoie systématiquement 200
// avec {disponible: bool}, pour que le client puisse afficher une fiche hors
// ligne exploitable plutôt qu'un onglet d'erreur.
if ($action === 'sharepoint_check' && $method === 'GET') {
    require_auth();

    $docId = (int)($_GET['id'] ?? 0);
    if (!$docId) json_error('Identifiant de document requis.', 400);

    $doc = $db->getSharePointDoc($docId);
    if (!$doc) json_error('Document introuvable.', 404);

    // Instantané local : toujours disponible, même SharePoint éteint.
    $horsLigne = [
        'id'         => $docId,
        'nom'        => $doc['NomFichier']         ?? '',
        'url'        => $doc['SharePointUrl']      ?? '',
        'derniereMaj'=> $doc['SharePointLastSync'] ?? null,
        'lectureSeule' => ($db->getConfig('sharepoint_lecture_seule') === '1'),
    ];

    if (!SHAREPOINT_ENABLED) {
        json_ok($horsLigne + ['disponible' => false, 'raison' => 'SharePoint n\'est pas activé sur cette instance.']);
    }

    try {
        $token = ensureMsToken();
        $ref   = spItemRef($doc, $token);
        if (empty($ref['driveId']) || empty($ref['itemId'])) {
            json_ok($horsLigne + ['disponible' => false, 'raison' => 'Le lien SharePoint est incomplet.']);
        }
        $meta = graphTry("drives/{$ref['driveId']}/items/{$ref['itemId']}", $token);
        if ($meta['status'] === 404 || isset($meta['data']['deleted'])) {
            json_ok($horsLigne + ['disponible' => false, 'raison' => 'Ce fichier n\'existe plus sur SharePoint.']);
        }
        if ($meta['status'] < 200 || $meta['status'] >= 300) {
            json_ok($horsLigne + ['disponible' => false, 'raison' => 'SharePoint est injoignable (code ' . (int)$meta['status'] . ').']);
        }

        // Succès : on rafraîchit l'instantané local (métadonnées uniquement).
        $nom    = (string)($meta['data']['name'] ?? '');
        $mime   = (string)($meta['data']['file']['mimeType'] ?? '');
        $taille = isset($meta['data']['size']) ? (int)$meta['data']['size'] : null;
        $db->touchSharePointSync($docId, $nom, $mime, $taille);

        json_ok([
            'disponible'   => true,
            'id'           => $docId,
            'nom'          => $nom !== '' ? $nom : $horsLigne['nom'],
            'url'          => $horsLigne['url'],
            'derniereMaj'  => date('Y-m-d H:i:s'),
            'lectureSeule' => $horsLigne['lectureSeule'],
        ]);
    } catch (\Throwable $e) {
        // ensureMsToken() peut couper la réponse via json_error (401) ; sinon on
        // dégrade proprement plutôt que de renvoyer une 500 au client.
        json_ok($horsLigne + ['disponible' => false, 'raison' => 'SharePoint est momentanément injoignable.']);
    }
}

// ── Créer un raccourci vers un fichier SharePoint ────────────────────────────
if ($action === 'sharepoint_link' && $method === 'POST') {
    $user = require_auth();
    if (!SHAREPOINT_ENABLED) json_error('SharePoint non disponible.', 403);

    $b        = get_body();
    $entType  = $b['entiteType']  ?? '';
    $entId    = (int)($b['entiteId'] ?? 0);
    $nom      = $b['nom']         ?? 'Fichier SharePoint';
    $mime     = $b['mime']        ?? 'application/octet-stream';
    $url      = $b['url']         ?? '';
    $itemId   = $b['driveItemId'] ?? '';
    $driveId  = $b['driveId']     ?? '';
    $siteId   = $b['siteId']     ?? '';
    $taille   = (int)($b['taille'] ?? 0);
    $categorie= $b['categorie']  ?? 'autre';

    if (!$entType || !$entId) json_error('entiteType et entiteId requis.', 400);
    if (!$url)                json_error('URL SharePoint requise.', 400);

    $docId = $db->addSharePointLink($entType, $entId, $nom, $mime, $categorie, $url, $itemId, $driveId, $siteId, $taille, $user['Login']);
    json_ok(['id' => $docId]);
}
