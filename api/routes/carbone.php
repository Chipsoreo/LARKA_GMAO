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
 * Larka — Routes : Facteurs d'émission carbone mobilité (proxy ADEME)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Actions : facteurs_carbone (GET), facteurs_carbone_sync (POST),
 *           facteurs_carbone_millesimes (GET), facteurs_carbone_test (POST),
 *           facteurs_carbone_cache (DELETE)
 *
 * MILLÉSIMES — VALEURS FIGÉES PAR ANNÉE
 * -------------------------------------
 * Même principe que le module Énergie (FacteurCarboneOfficiel / Simple) :
 * les facteurs sont enregistrés PAR ANNÉE dans FacteurMobilite et ne sont
 * JAMAIS recalculés rétroactivement. Un trajet 2025 garde les facteurs 2025
 * même si l'ADEME révise son référentiel en 2026.
 *
 * Résolution d'une année :
 *   1. millésime exact en base                → utilisé tel quel
 *   2. millésime le plus récent antérieur      → utilisé, signalé à l'UI
 *   3. année courante et source active         → appel ADEME puis figement
 *   4. rien                                    → repli navigateur
 *
 * ⚠️ L'API ADEME ne sert que ses valeurs COURANTES : impossible de reconstituer
 * un millésime passé après coup. Une année non figée à l'époque le restera.
 * D'où le figement automatique à la première consultation d'une année.
 *
 * PRINCIPE DE RÉSOLUTION
 * ----------------------
 *   1. Source ADEME activée + appel réussi   → facteurs à jour
 *   2. Sinon, cache disque encore valide     → derniers facteurs connus
 *   3. Sinon                                 → repli côté navigateur
 *      (table FACTEURS_REPLI de js/pages/mobilite.js)
 *
 * Larka fonctionne donc sans aucune configuration : la clé n'est qu'un
 * enrichissement. En cas d'échec on renvoie une liste vide plutôt qu'une
 * erreur, pour que le frontend bascule sur ses valeurs intégrées sans bruit.
 *
 * QUATRE PÉRIMÈTRES EMBOÎTÉS
 * --------------------------
 * Chacun est un sous-ensemble du précédent, du plus large au plus étroit :
 *
 *   1. acv         fabrication du véhicule + amont carburant + combustion
 *                  + forçage radiatif (avion). API includeConstruction=1.
 *   2. horsConstr  idem sans la fabrication. API includeConstruction=0.
 *   3. combustion  combustion à bord seule, hors amont carburant et hors
 *                  forçage radiatif. C'est le périmètre « scope 1 » : nul
 *                  pour tout mode électrique ou musculaire.
 *   4. co2Seul     idem, en CO₂ uniquement (sans CH₄ ni N₂O).
 *
 * 1 et 2 sortent directement de l'API. 3 et 4 en sont DÉRIVÉS par ratio :
 *   • l'API donne le poste « Carburant » entier (amont + combustion) ; on
 *     l'obtient isolé via ignoreRadiativeForcing=1, puis on applique
 *     `partCombustion`, part de la combustion dans ce poste.
 *     Base Carbone : essence 2,28/2,79 · gazole 2,51/3,14 → ≈ 0,81.
 *   • `PART_CO2` = 0,99 : le CO₂ représente ~99 % des GES de combustion des
 *     carburants routiers, le CH₄ et le N₂O étant marginaux.
 *
 * ⚠️ Les périmètres 3 et 4 sont donc des ESTIMATIONS dérivées, pas des valeurs
 * publiées telles quelles par l'ADEME. Les `partCombustion` des modes mixtes
 * (TER, Intercités) sont des ordres de grandeur à affiner. L'UI les signale.
 *
 * SÉCURITÉ : la clé API ne sort jamais d'ici. Elle vit dans .env
 * (GMAO_IMPACTCO2_API_KEY) et n'est jamais renvoyée au navigateur.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Configuration ────────────────────────────────────────────────────────────
function _carboneCfg(string $key, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        global $_cfg;
        $cfg = (isset($_cfg['carbone']) && is_array($_cfg['carbone'])) ? $_cfg['carbone'] : [];
    }
    return $cfg[$key] ?? $default;
}

function _carboneCacheFile(): string {
    $dir = __DIR__ . '/../../data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/facteurs_carbone.json';
}

/**
 * Table des millésimes. Une ligne = (année, mode de transport).
 * Index unique (Annee, Transport) : un seul jeu de valeurs par année et par mode.
 */
function _ensureFacteurMobiliteSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $driver = DB_DRIVER;
    $AI = ($driver === 'pgsql') ? 'SERIAL PRIMARY KEY'
        : (($driver === 'mariadb' || $driver === 'mysql') ? 'INT AUTO_INCREMENT PRIMARY KEY'
        : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS FacteurMobilite (
            Id $AI,
            Annee         INTEGER NOT NULL,
            Transport     TEXT NOT NULL,
            Co2Acv        REAL,
            Co2HorsConstr REAL,
            Co2Combustion REAL,
            Co2Seul       REAL,
            Source        TEXT,
            DateMaj       TEXT
        )");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_fmob_annee_transport ON FacteurMobilite(Annee, Transport)");
    } catch (\Throwable $e) { /* index déjà présent */ }

    // Table créée par une version antérieure : ajouter les périmètres dérivés.
    foreach (['Co2Combustion' => 'REAL', 'Co2Seul' => 'REAL'] as $col => $type) {
        try { $pdo->exec("ALTER TABLE FacteurMobilite ADD COLUMN $col $type"); }
        catch (\Throwable $e) { /* colonne déjà présente */ }
    }
}

/** Millésime figé pour une année donnée. */
function _facteursMillesime(PDO $pdo, int $annee): array {
    _ensureFacteurMobiliteSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM FacteurMobilite WHERE Annee = ? ORDER BY Transport");
    $st->execute([$annee]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $out[] = [
            'id'            => (string)$r['transport'],
            'co2Acv'        => $r['co2acv']        !== null ? (float)$r['co2acv']        : null,
            'co2HorsConstr' => $r['co2horsconstr'] !== null ? (float)$r['co2horsconstr'] : null,
            'co2Combustion' => isset($r['co2combustion']) && $r['co2combustion'] !== null ? (float)$r['co2combustion'] : null,
            'co2Seul'       => isset($r['co2seul'])       && $r['co2seul']       !== null ? (float)$r['co2seul']       : null,
        ];
    }
    return $out;
}

/**
 * Millésime de repli pour une année sans valeurs propres.
 *
 * On cherche D'ABORD en arrière (millésime antérieur le plus récent) : appliquer
 * des facteurs plus anciens à une année récente est conservateur et défendable.
 * À défaut seulement, on repart en avant vers le millésime le plus ancien
 * disponible — appliquer des facteurs POSTÉRIEURS à un exercice passé est bien
 * plus contestable, d'où le 'sens' renvoyé pour que l'UI alerte différemment.
 *
 * @return array{annee:int, sens:string}|null  sens = 'arriere' | 'avant'
 */
function _millesimeDeRepli(PDO $pdo, int $annee): ?array {
    _ensureFacteurMobiliteSchema($pdo);

    $st = $pdo->prepare("SELECT MAX(Annee) AS A FROM FacteurMobilite WHERE Annee <= ?");
    $st->execute([$annee]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $r = $r ? array_change_key_case($r, CASE_LOWER) : null;
    if ($r && $r['a'] !== null) return ['annee' => (int)$r['a'], 'sens' => 'arriere'];

    $st = $pdo->prepare("SELECT MIN(Annee) AS A FROM FacteurMobilite WHERE Annee > ?");
    $st->execute([$annee]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $r = $r ? array_change_key_case($r, CASE_LOWER) : null;
    if ($r && $r['a'] !== null) return ['annee' => (int)$r['a'], 'sens' => 'avant'];

    return null;
}

/**
 * Écrit un millésime. Remplace l'année si $ecraser, sinon ne touche pas à une
 * année déjà figée — le figement est définitif par défaut, c'est tout l'intérêt.
 */
function _figerMillesime(PDO $pdo, int $annee, array $facteurs, string $source, bool $ecraser = false): int {
    _ensureFacteurMobiliteSchema($pdo);
    if (!$ecraser) {
        $st = $pdo->prepare("SELECT COUNT(*) AS N FROM FacteurMobilite WHERE Annee = ?");
        $st->execute([$annee]);
        $n = (int)array_values(array_change_key_case($st->fetch(PDO::FETCH_ASSOC), CASE_LOWER))[0];
        if ($n > 0) return 0;
    }
    $pdo->prepare("DELETE FROM FacteurMobilite WHERE Annee = ?")->execute([$annee]);
    $ins = $pdo->prepare("INSERT INTO FacteurMobilite
        (Annee, Transport, Co2Acv, Co2HorsConstr, Co2Combustion, Co2Seul, Source, DateMaj)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach ($facteurs as $f) {
        if (empty($f['id'])) continue;
        $ins->execute([$annee, (string)$f['id'],
                       isset($f['co2Acv'])        ? (float)$f['co2Acv']        : null,
                       isset($f['co2HorsConstr']) ? (float)$f['co2HorsConstr'] : null,
                       isset($f['co2Combustion']) ? (float)$f['co2Combustion'] : null,
                       isset($f['co2Seul'])       ? (float)$f['co2Seul']       : null,
                       $source, $now]);
        $n++;
    }
    return $n;
}

/** Part du CO₂ dans les GES émis par la combustion des carburants. */
const CARBONE_PART_CO2 = 0.99;

/**
 * Correspondance modes Larka → identifiants Impact CO2.
 *   idApi  : identifiant du mode dans l'API
 *   km     : distance d'interrogation (1 km sauf aviation, dont le facteur
 *            dépend de la distance : l'API sélectionne le palier court /
 *            moyen / long courrier en fonction du km demandé)
 *   div    : diviseur appliqué au résultat (covoiturage : l'API renvoie déjà
 *            la valeur par passager pour les id 22-25, donc div = 1)
 *   part   : partCombustion — part de la combustion dans le poste « Carburant ».
 *            0 pour tout mode sans combustion à bord (électrique, musculaire).
 *            Les valeurs des modes mixtes sont des estimations.
 */
function _carboneMapping(): array {
    return [
        'voiture_thermique'  => ['idApi' => 4,   'km' => 1,    'part' => 0.81],
        'voiture_electrique' => ['idApi' => 5,   'km' => 1,    'part' => 0.0],
        'voiture_hybride'    => ['idApi' => 200, 'km' => 1,    'part' => 0.81],
        'covoiturage_2'      => ['idApi' => 22,  'km' => 1,    'part' => 0.81],
        'covoiturage_3'      => ['idApi' => 23,  'km' => 1,    'part' => 0.81],
        'moto'               => ['idApi' => 13,  'km' => 1,    'part' => 0.81],
        'scooter_thermique'  => ['idApi' => 12,  'km' => 1,    'part' => 0.81],
        'scooter_elec'       => ['idApi' => 33,  'km' => 1,    'part' => 0.0],
        'velo'               => ['idApi' => 7,   'km' => 1,    'part' => 0.0],
        'velo_elec'          => ['idApi' => 8,   'km' => 1,    'part' => 0.0],
        'trottinette_elec'   => ['idApi' => 17,  'km' => 1,    'part' => 0.0],
        'marche'             => ['idApi' => 30,  'km' => 1,    'part' => 0.0],
        'bus_thermique'      => ['idApi' => 9,   'km' => 1,    'part' => 0.81],
        'bus_electrique'     => ['idApi' => 16,  'km' => 1,    'part' => 0.0],
        'autocar'            => ['idApi' => 6,   'km' => 1,    'part' => 0.81],
        'tramway'            => ['idApi' => 10,  'km' => 1,    'part' => 0.0],
        'metro'              => ['idApi' => 11,  'km' => 1,    'part' => 0.0],
        'rer'                => ['idApi' => 14,  'km' => 1,    'part' => 0.0],
        'ter'                => ['idApi' => 15,  'km' => 1,    'part' => 0.45],  // mix diesel/élec — estimation
        'intercites'         => ['idApi' => 3,   'km' => 1,    'part' => 0.20],  // traction majoritairement élec — estimation
        'tgv'                => ['idApi' => 2,   'km' => 1,    'part' => 0.0],
        'avion_court'        => ['idApi' => 1,   'km' => 800,  'part' => 0.81],
        'avion_moyen'        => ['idApi' => 1,   'km' => 2000, 'part' => 0.81],
        'avion_long'         => ['idApi' => 1,   'km' => 3000, 'part' => 0.81],
    ];
}

/**
 * Appel HTTP GET vers l'API Impact CO2.
 * @return array{ok:bool, data:array, message:string, http:int}
 */
function _carboneHttpGet(string $url, string $cle, int $timeout = 6): array {
    $headers = ['Accept: application/json'];
    if ($cle !== '') $headers[] = 'Authorization: Bearer ' . $cle;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => (bool)_carboneCfg('tls_verify', true),
            CURLOPT_SSL_VERIFYHOST => _carboneCfg('tls_verify', true) ? 2 : 0,
            CURLOPT_USERAGENT      => 'Larka/1.0 (+bilan carbone mobilite)',
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($body === false) return ['ok' => false, 'data' => [], 'message' => 'Réseau : ' . $err, 'http' => 0];
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $headers),
            'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $http = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $http = (int)$m[1];
        }
        if ($body === false) return ['ok' => false, 'data' => [], 'message' => 'Réseau : requête impossible.', 'http' => 0];
    }

    if ($http >= 400) {
        return ['ok' => false, 'data' => [], 'message' => 'HTTP ' . $http, 'http' => $http];
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'data' => [], 'message' => 'Réponse JSON invalide.', 'http' => $http];
    }
    return ['ok' => true, 'data' => $json, 'message' => '', 'http' => $http];
}

/**
 * Récupère les facteurs auprès d'Impact CO2 et les met au format Larka.
 *
 * Stratégie : une paire d'appels (avec / sans construction) par distance
 * distincte du mapping — soit 8 requêtes au total, uniquement lors d'un
 * rafraîchissement du cache (une fois par jour en configuration par défaut).
 *
 * @return array{ok:bool, facteurs:array, message:string, anonyme:bool}
 */
function _carboneFetchImpactCo2(string $cle): array {
    $base    = rtrim((string)_carboneCfg('impactco2_url', 'https://impactco2.fr/api/v1/transport'), '/');
    $mapping = _carboneMapping();

    // Distances distinctes à interroger.
    $distances = array_values(array_unique(array_map(fn($m) => $m['km'], $mapping)));

    $valeurs = [];   // "km|includeConstruction" => [idApi => kgCO2e pour ce km]
    $anonyme = false;
    $dernierMessage = '';
    $echecs  = 0;
    $debut   = microtime(true);

    // Trois variantes par distance :
    //   acv  → construction incluse
    //   hors → construction exclue
    //   carb → construction exclue ET forçage radiatif ignoré, ce qui isole le
    //          poste « Carburant » (déterminant pour l'avion, où les trainées
    //          de condensation pèsent presque autant que le kérosène).
    $variantes = [
        'acv'  => ['construction' => 1, 'forcage' => 0],
        'hors' => ['construction' => 0, 'forcage' => 0],
        'carb' => ['construction' => 0, 'forcage' => 1],
    ];

    foreach ($distances as $km) {
        foreach ($variantes as $nom => $v) {
            // Fail-fast : le navigateur abandonne au bout de 20 s (API_TIMEOUT_MS).
            // Deux échecs ou 15 s écoulées suffisent à conclure que la source
            // est injoignable — inutile d'enchaîner douze appels lents.
            if ($echecs >= 2 || (microtime(true) - $debut) > 15) break 2;

            $url = $base . '?km=' . $km . '&displayAll=1'
                 . '&includeConstruction=' . $v['construction']
                 . '&ignoreRadiativeForcing=' . $v['forcage'];
            $res = _carboneHttpGet($url, $cle);
            if (!$res['ok']) { $dernierMessage = $res['message']; $echecs++; continue; }
            if (!empty($res['data']['warning'])) $anonyme = true;

            $ligne = [];
            foreach (($res['data']['data'] ?? []) as $item) {
                if (!isset($item['id'])) continue;
                $ligne[(int)$item['id']] = (float)($item['value'] ?? 0);
            }
            if ($ligne) $valeurs[$km . '|' . $nom] = $ligne;
        }
    }

    if (!$valeurs) {
        return ['ok' => false, 'facteurs' => [], 'anonyme' => $anonyme,
                'message' => $dernierMessage !== '' ? $dernierMessage : 'Aucune donnée exploitable renvoyée.'];
    }

    $facteurs = [];
    foreach ($mapping as $idLarka => $m) {
        $km  = $m['km'];
        $div = max(1, (int)($m['div'] ?? 1)) * $km;   // ramener au kgCO2e par km
        $acv  = $valeurs[$km . '|acv'][$m['idApi']]  ?? null;
        $hors = $valeurs[$km . '|hors'][$m['idApi']] ?? null;
        $carb = $valeurs[$km . '|carb'][$m['idApi']] ?? null;
        if ($acv === null && $hors === null) continue;

        $entree = ['id' => $idLarka];
        if ($acv  !== null) $entree['co2Acv']        = round($acv  / $div, 6);
        if ($hors !== null) $entree['co2HorsConstr'] = round($hors / $div, 6);

        // Périmètres dérivés. À défaut de variante « carb » exploitable, on se
        // rabat sur « hors » : sans le forçage radiatif isolé, l'avion serait
        // surestimé, mais mieux vaut une valeur cohérente qu'aucune valeur.
        $base_carb = ($carb !== null) ? $carb : $hors;
        if ($base_carb !== null) {
            $part = isset($m['part']) ? (float)$m['part'] : 0.0;
            $comb = ($base_carb / $div) * $part;
            $entree['co2Combustion'] = round($comb, 6);
            $entree['co2Seul']       = round($comb * CARBONE_PART_CO2, 6);
        }
        $facteurs[] = $entree;
    }

    if (!$facteurs) {
        return ['ok' => false, 'facteurs' => [], 'anonyme' => $anonyme,
                'message' => 'Aucun mode reconnu dans la réponse (le mapping des identifiants a peut-être changé côté ADEME).'];
    }
    return ['ok' => true, 'facteurs' => $facteurs, 'message' => '', 'anonyme' => $anonyme];
}

/** Clé API Impact CO2 : .env en priorité, config.json en secours. */
function _carboneCle(): string {
    if (function_exists('env')) {
        $v = env('GMAO_IMPACTCO2_API_KEY', null);
        if (is_string($v) && $v !== '') return $v;
    }
    return trim((string)_carboneCfg('impactco2_key', ''));
}

// ═══════════════════════════════════════════════════════════════════════════════
//  GET facteurs_carbone[&annee=N] — utilisé par la page Mobilité
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'facteurs_carbone' && $method === 'GET') {
    require_auth();
    $pdo         = $db->getPdo();
    $annee       = (int)($_GET['annee'] ?? date('Y'));
    $anneeActive = (int)date('Y');
    $actif       = (bool)_carboneCfg('actif', false);

    // 1) Millésime exact déjà figé → réponse immédiate, sans appel réseau.
    $facteurs = _facteursMillesime($pdo, $annee);
    if ($facteurs) {
        json_ok(['annee' => $annee, 'anneeFacteurs' => $annee, 'facteurs' => $facteurs,
                 'origine' => 'millesime', 'fige' => true,
                 'source'  => 'Millésime ' . $annee . ' figé en base', 'maj' => null, 'message' => '']);
    }

    // 2) Année courante UNIQUEMENT, et source active → on interroge l'ADEME et
    //    on fige le résultat. C'est le seul moment où un millésime peut naître :
    //    l'API ne sert que ses valeurs du jour, une année ratée l'est pour de bon.
    //    ⚠️ Surtout pas les années futures : ouvrir 2030 aujourd'hui y scellerait
    //    définitivement les valeurs du jour, alors qu'elles auront changé.
    if ($actif && $annee === $anneeActive) {
        $res = _carboneFetchImpactCo2(_carboneCle());
        if ($res['ok']) {
            $source = 'ADEME — Impact CO2';
            _figerMillesime($pdo, $annee, $res['facteurs'], $source);
            json_ok(['annee' => $annee, 'anneeFacteurs' => $annee, 'facteurs' => $res['facteurs'],
                     'origine' => 'api', 'fige' => true, 'source' => $source,
                     'maj' => date('Y-m-d H:i'),
                     'message' => $res['anonyme']
                        ? 'Appel non authentifié : renseignez une clé API dans la configuration serveur.'
                        : '']);
        }
        error_log('[Larka][carbone] Source ADEME indisponible : ' . $res['message']);
    }

    // 3) Report sur un autre millésime. Explicite, jamais silencieux : l'UI doit
    //    dire quelle année sert réellement au calcul, et distinguer les deux sens.
    $repli = _millesimeDeRepli($pdo, $annee);
    if ($repli !== null) {
        $avant = ($repli['sens'] === 'avant');
        json_ok(['annee' => $annee, 'anneeFacteurs' => $repli['annee'],
                 'facteurs' => _facteursMillesime($pdo, $repli['annee']),
                 'origine' => $avant ? 'report_avant' : 'report', 'fige' => true,
                 'source'  => 'Millésime ' . $repli['annee'] . ' (aucun facteur figé pour ' . $annee . ')',
                 'maj' => null,
                 'message' => $avant
                    ? 'Aucun millésime pour ' . $annee . ' ni pour une année antérieure. Le millésime '
                      . $repli['annee'] . ', POSTÉRIEUR, est appliqué : ce bilan n\'est pas défendable en l\'état. '
                      . 'Saisissez les facteurs de ' . $annee . ' dans la configuration serveur.'
                    : 'Aucun facteur n\'a été figé pour ' . $annee . ' : le millésime ' . $repli['annee'] . ' est utilisé.']);
    }

    // 4) Rien du tout → repli navigateur.
    json_ok(['annee' => $annee, 'anneeFacteurs' => null, 'facteurs' => [],
             'origine' => $actif ? 'indisponible' : 'desactive', 'fige' => false,
             'source' => null, 'maj' => null,
             'message' => $actif
                ? 'Source ADEME injoignable et aucun millésime en base.'
                : 'Source ADEME désactivée : valeurs de référence intégrées.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  GET facteurs_carbone_millesimes — inventaire des années figées
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'facteurs_carbone_millesimes' && $method === 'GET') {
    $user = require_auth();
    require_lecture($user, ['Admin', 'Gestionnaire', 'Visionneur'], ['carbone','energie'], $db);
    $pdo = $db->getPdo();
    _ensureFacteurMobiliteSchema($pdo);
    $rows = $pdo->query("SELECT Annee, COUNT(*) AS Nb, MAX(Source) AS Source, MAX(DateMaj) AS DateMaj
                         FROM FacteurMobilite GROUP BY Annee ORDER BY Annee DESC")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $out[] = ['annee' => (int)$r['annee'], 'nb' => (int)$r['nb'],
                  'source' => $r['source'], 'dateMaj' => $r['datemaj']];
    }
    json_ok($out);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  POST facteurs_carbone_sync — figer (ou refiger) le millésime d'une année
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'facteurs_carbone_sync' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);
    $pdo   = $db->getPdo();
    $body  = get_body();
    $annee = (int)($body['annee'] ?? date('Y'));
    // Refiger une année écrase des valeurs déjà utilisées dans des bilans
    // publiés : l'UI doit demander confirmation avant d'envoyer ecraser=true.
    $ecraser = !empty($body['ecraser']);

    if (!(bool)_carboneCfg('actif', false)) {
        json_error('La source ADEME est désactivée dans la configuration serveur.', 400);
    }
    $res = _carboneFetchImpactCo2(_carboneCle());
    if (!$res['ok']) json_error('Source ADEME injoignable : ' . $res['message'], 502);

    $n = _figerMillesime($pdo, $annee, $res['facteurs'], 'ADEME — Impact CO2', $ecraser);
    if ($n === 0 && !$ecraser) {
        json_error('Le millésime ' . $annee . ' est déjà figé. Cochez « écraser » pour le remplacer.', 409);
    }
    json_ok(['annee' => $annee, 'nb' => $n, 'anonyme' => $res['anonyme'],
             'message' => 'Millésime ' . $annee . ' figé : ' . $n . ' modes.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  GET facteurs_carbone_grille&annee=N — grille éditable d'un millésime
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'facteurs_carbone_grille' && $method === 'GET') {
    $user = require_auth();
    require_lecture($user, ['Admin', 'Gestionnaire', 'Visionneur'], ['carbone','energie'], $db);
    $pdo   = $db->getPdo();
    $annee = (int)($_GET['annee'] ?? date('Y'));
    _ensureFacteurMobiliteSchema($pdo);

    $st = $pdo->prepare("SELECT * FROM FacteurMobilite WHERE Annee = ?");
    $st->execute([$annee]);
    $index = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $index[(string)$r['transport']] = $r;
    }

    // On renvoie TOUS les modes connus, même absents de la base : la grille doit
    // montrer les trous, c'est précisément ce qu'on demande à l'admin de combler.
    $lignes = [];
    foreach (_carboneMapping() as $idLarka => $m) {
        $r = $index[$idLarka] ?? null;
        $lignes[] = [
            'id'            => $idLarka,
            'present'       => $r !== null,
            'co2Acv'        => $r ? (float)$r['co2acv'] : null,
            'co2HorsConstr' => $r ? (float)$r['co2horsconstr'] : null,
            'co2Combustion' => $r && isset($r['co2combustion']) ? (float)$r['co2combustion'] : null,
            'co2Seul'       => $r && isset($r['co2seul']) ? (float)$r['co2seul'] : null,
            'source'        => $r['source'] ?? null,
        ];
    }
    json_ok(['annee' => $annee, 'lignes' => $lignes]);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  POST facteurs_carbone_saisie — écrire un millésime à la main
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Seule voie correcte pour reconstituer un exercice passé : l'API ADEME ne sert
 * que ses valeurs du jour. Les valeurs viennent alors d'une Base Carbone
 * archivée, saisies ou importées en CSV par l'administrateur.
 *
 * Corps attendu : { annee, source?, lignes:[{id, co2Acv, co2HorsConstr,
 *                   co2Combustion, co2Seul}], ecraser? }
 */
if ($action === 'facteurs_carbone_saisie' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);
    $pdo   = $db->getPdo();
    $body  = get_body();
    $annee = (int)($body['annee'] ?? 0);
    if ($annee < 2000 || $annee > 2100) json_error('Année invalide.', 400);

    $lignes = is_array($body['lignes'] ?? null) ? $body['lignes'] : [];
    if (!$lignes) json_error('Aucune ligne à enregistrer.', 400);

    $connus = _carboneMapping();
    $facteurs = [];
    $ignores  = [];
    foreach ($lignes as $l) {
        $id = (string)($l['id'] ?? '');
        if ($id === '') continue;
        if (!isset($connus[$id])) { $ignores[] = $id; continue; }

        $acv = isset($l['co2Acv']) && $l['co2Acv'] !== '' ? (float)$l['co2Acv'] : null;
        if ($acv === null) continue;   // l'ACV est le socle : sans lui la ligne n'a pas de sens

        $hors = isset($l['co2HorsConstr']) && $l['co2HorsConstr'] !== '' ? (float)$l['co2HorsConstr'] : $acv;
        $comb = isset($l['co2Combustion']) && $l['co2Combustion'] !== '' ? (float)$l['co2Combustion'] : null;
        $seul = isset($l['co2Seul'])       && $l['co2Seul']       !== '' ? (float)$l['co2Seul']       : null;

        // Périmètres dérivés absents du CSV : on les recalcule avec les mêmes
        // ratios que l'import automatique, pour rester cohérent d'une année à l'autre.
        if ($comb === null) $comb = $hors * (float)($connus[$id]['part'] ?? 0);
        if ($seul === null) $seul = $comb * CARBONE_PART_CO2;

        // Garde-fou : les périmètres doivent rester emboîtés, sinon les
        // comparaisons de l'interface deviennent absurdes (barres croissantes).
        if ($hors > $acv || $comb > $hors || $seul > $comb) {
            json_error('Mode « ' . $id . " » : les périmètres doivent décroître "
                     . '(ACV ≥ hors construction ≥ combustion ≥ CO₂ seul).', 400);
        }

        $facteurs[] = ['id' => $id, 'co2Acv' => $acv, 'co2HorsConstr' => $hors,
                       'co2Combustion' => $comb, 'co2Seul' => $seul];
    }
    if (!$facteurs) json_error('Aucune ligne exploitable : la colonne ACV est obligatoire.', 400);

    $source = trim((string)($body['source'] ?? '')) ?: 'Saisie manuelle';
    $n = _figerMillesime($pdo, $annee, $facteurs, $source, true);

    json_ok(['annee' => $annee, 'nb' => $n, 'ignores' => $ignores,
             'message' => 'Millésime ' . $annee . ' enregistré : ' . $n . ' modes.'
                        . ($ignores ? ' Modes inconnus ignorés : ' . implode(', ', $ignores) . '.' : '')]);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  POST facteurs_carbone_test — bouton « Tester » de la configuration serveur
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'facteurs_carbone_test' && $method === 'POST') {
    $isSuperAdmin = !empty($_SESSION['superadmin']['authenticated']);
    if (!$isSuperAdmin) {
        $user = require_auth();
        require_role($user, ['Admin', 'Gestionnaire']);
    }

    // Une clé saisie mais pas encore enregistrée peut être testée telle quelle.
    $body = get_body();
    $cle  = trim((string)($body['cle'] ?? ''));
    if ($cle === '' || str_contains($cle, '••')) $cle = _carboneCle();

    $res = _carboneFetchImpactCo2($cle);
    json_ok([
        'ok'         => $res['ok'],
        'nbFacteurs' => count($res['facteurs']),
        'anonyme'    => $res['anonyme'],
        'message'    => $res['ok']
            ? ($res['anonyme']
                ? 'Connexion établie, mais sans clé : l\'ADEME se réserve le droit de couper l\'accès anonyme.'
                : 'Connexion authentifiée établie.')
            : $res['message'],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  DELETE facteurs_carbone_cache — purge du cache disque
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'facteurs_carbone_cache' && $method === 'DELETE') {
    $isSuperAdmin = !empty($_SESSION['superadmin']['authenticated']);
    if (!$isSuperAdmin) {
        $user = require_auth();
        require_role($user, ['Admin', 'Gestionnaire']);
    }
    $f = _carboneCacheFile();
    if (is_file($f)) @unlink($f);
    json_ok('OK');
}
