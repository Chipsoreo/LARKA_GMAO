<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Énergie
 *
 * Actions : compteurs_energie (CRUD), releves_energie (CRUD),
 *           facteurs carbone (double méthode), sync ADEME, sync simplifiée, recalcul CO₂
 *
 * 2 méthodes de facteurs :
 *   - 'officielle'  : API Base Carbone ADEME (ACV, identifiants précis)
 *   - 'simplifiee'  : Moyennes Impact CO2 / Datagir (valeurs grand public)
 *
 * Contraintes :
 *   - Facteurs stockés PAR ANNÉE + PAR MÉTHODE (pas de recalcul rétroactif)
 *   - Chaque calcul utilise le facteur de l'année de consommation
 */

// ── Compteurs ─────────────────────────────────────────────────────────────────
if ($action === 'energie_compteurs') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], ['carbone','energie'], $db);
    if ($method === 'GET')    json_ok($db->getEnergieCompteurs());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->addEnergieCompteur(get_body())]);
    if ($method === 'PUT')    { $db->updateEnergieCompteur($id, get_body()); json_ok('OK'); }
    if ($method === 'DELETE') { $db->deleteEnergieCompteur($id); json_ok('OK'); }
}

// ── Relevés ───────────────────────────────────────────────────────────────────
if ($action === 'energie_releves') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], ['carbone','energie'], $db);
    $cptId = intval($_GET['compteur_id'] ?? 0);
    if ($method === 'GET')    json_ok($db->getEnergieReleves($cptId));
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->addEnergieReleve(get_body())]);
    if ($method === 'PUT')    { $db->updateEnergieReleve($id, get_body()); json_ok('OK'); }
    if ($method === 'DELETE') { $db->deleteEnergieReleve($id); json_ok('OK'); }
}

// ── Facteurs Carbone ─────────────────────────────────────────────────────────
if ($action === 'energie_facteurs_carbone') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], ['carbone','energie'], $db);
    $methode = $_GET['methode'] ?? '';
    if ($method === 'GET')    json_ok($db->getFacteursCarbone($methode));
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->upsertFacteurCarbone(get_body())]);
    if ($method === 'DELETE') {
        $methode = $_GET['methode'] ?? 'officielle';
        $db->deleteFacteurCarbone($id, $methode);
        json_ok('OK');
    }
}

// ── Purger TOUS les facteurs d'une méthode ───────────────────────────────────
if ($action === 'energie_purger_facteurs') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $methode = $body['methode'] ?? 'officielle';
    if (!in_array($methode, ['officielle', 'simplifiee'])) json_error('Méthode invalide.');
    // Compter puis supprimer
    $all = $db->getFacteursCarbone($methode);
    $count = count($all);
    foreach ($all as $f) {
        $db->deleteFacteurCarbone((int)$f['Id'], $methode);
    }
    $label = $methode === 'simplifiee' ? 'simplifiés' : 'officiels';
    json_ok(['deleted' => $count, 'message' => "$count facteur(s) $label supprimé(s)."]);
}

// ── Facteur Carbone : récupérer pour un type/année/méthode ───────────────────
if ($action === 'energie_facteur_carbone') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'energie', $db);
    $type    = $_GET['type'] ?? '';
    $annee   = intval($_GET['annee'] ?? date('Y'));
    $methode = $_GET['methode'] ?? 'officielle';
    $fc = $db->getFacteurCarbonePlusProcheAnnee($type, $annee, $methode);
    if (is_array($fc)) $fc['_anneeDemandee'] = $annee;
    json_ok($fc);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── SYNC ADEME — Méthode OFFICIELLE (100% côté serveur) ─────────────────────
// 1. Sauvegarde les valeurs officielles ADEME (hardcodées, garanties)
// 2. Tente l'API ADEME pour mise à jour (bonus)
// 3. Vérifie la cohérence des valeurs avant d'écraser les défauts
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'energie_sync_carbone_ademe') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body  = get_body();
    $annee = intval($body['annee'] ?? date('Y'));

    // ── Valeurs ADEME officielles ──
    $defaults = [
        'Electricite' => [
            'facteurCO2'       => 0.0569,
            'unite'            => 'kgCO2e/kWh',
            'source'           => 'ADEME Base Carbone (valeur officielle)',
            'identifiantADEME' => '36651',
            'nomADEME'         => 'Electricité — Mix moyen consommation — France continentale',
            'incertitude'      => 10,
        ],
        'Gaz' => [
            'facteurCO2'       => 0.227,
            'unite'            => 'kgCO2e/kWh PCI',
            'source'           => 'ADEME Base Carbone (valeur officielle)',
            'identifiantADEME' => '38952',
            'nomADEME'         => 'Gaz naturel — PCI — France continentale',
            'incertitude'      => 5,
        ],
        'Chauffage' => [
            'facteurCO2'       => 0.112,
            'unite'            => 'kgCO2e/kWh',
            'source'           => 'ADEME / Arrêté DPE (réseau de chaleur mix moyen)',
            'identifiantADEME' => 'DPE-RCU',
            'nomADEME'         => 'Réseau de chaleur — Mix moyen — France (arrêté DPE)',
            'incertitude'      => 50,
        ],
    ];

    // ── Étape 1 : Sauvegarder les défauts IMMÉDIATEMENT (méthode = officielle) ──
    $results = [];
    $debug   = [];
    foreach ($defaults as $type => $def) {
        $id = $db->upsertFacteurCarbone([
            'annee'            => $annee,
            'type'             => $type,
            'facteurCO2'       => $def['facteurCO2'],
            'unite'            => $def['unite'],
            'source'           => $def['source'],
            'identifiantADEME' => $def['identifiantADEME'],
            'nomADEME'         => $def['nomADEME'],
            'incertitude'      => $def['incertitude'],
            'methode'          => 'officielle',
        ]);
        $results[$type] = [
            'id'         => $id,
            'facteurCO2' => $def['facteurCO2'],
            'source'     => $def['source'],
        ];
        $debug[] = "OK $type defaut sauve : {$def['facteurCO2']} {$def['unite']} (officielle)";
    }

    // ── Étape 2 : Tenter l'API ADEME (bonus, ne casse jamais les défauts) ──
    // Paramètres pilotés par config.json → section carbone_energie, pour que
    // l'URL du jeu de données et les identifiants ne soient plus enfouis ici.
    global $_cfg;
    $cfgCarbone = (isset($_cfg['carbone_energie']) && is_array($_cfg['carbone_energie']))
                ? $_cfg['carbone_energie'] : [];

    if (isset($cfgCarbone['actif']) && !$cfgCarbone['actif']) {
        $debug[] = 'INFO : source ADEME desactivee dans la configuration serveur, defauts conserves';
        json_ok(['annee' => $annee, 'methode' => 'officielle', 'apiSuccess' => false,
                 'facteurs' => $results, 'debug' => $debug]);
    }

    $apiSuccess = false;
    $baseUrl    = trim((string)($cfgCarbone['dataset_url'] ?? ''))
               ?: 'https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/lines';
    $selectFields = implode(',', [
        "Identifiant_de_l'élément","Nom_base_français","Nom_attribut_français",
        "Nom_frontière_français","Unité_français","Total_poste_non_décomposé",
        "CO2f","CH4f","N2O",
        "Statut_de_l'élément","Type_Ligne","Structure",
        "Localisation_géographique","Incertitude"
    ]);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "Accept: application/json\r\nUser-Agent: Larka/1.0\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => (bool)($cfgCarbone['tls_verify'] ?? true),
            'verify_peer_name' => (bool)($cfgCarbone['tls_verify'] ?? true),
        ],
    ]);

    $ademeIds = [
        'Electricite' => trim((string)($cfgCarbone['id_electricite'] ?? '')) ?: '36651',
        'Gaz'         => trim((string)($cfgCarbone['id_gaz'] ?? ''))         ?: '38952',
    ];

    foreach ($ademeIds as $type => $ademeId) {
        try {
            $params = [
                'size'   => 5,
                'select' => $selectFields,
                'qs'     => "Identifiant_de_l'élément:\"$ademeId\"",
            ];
            $url  = $baseUrl . '?' . http_build_query($params);
            $json = @file_get_contents($url, false, $ctx);

            if ($json === false) {
                $debug[] = "WARN $type : API injoignable";
                continue;
            }

            $data = @json_decode($json, true);
            if (!$data || empty($data['results'])) {
                $debug[] = "WARN $type : ID $ademeId → 0 resultat";
                continue;
            }

            // ⚠️ Un même identifiant Base Carbone porte PLUSIEURS lignes valides,
            // une par frontière (« amont », « combustion », « amont + combustion »…),
            // dont les valeurs diffèrent d'un facteur 2 à 3. L'ancien code prenait
            // la première rencontrée : le facteur enregistré dépendait de l'ordre
            // de tri de l'API, sans que rien ne le signale.
            // On exige désormais la frontière attendue ; à défaut on conserve le
            // défaut et on liste les frontières trouvées pour que l'admin tranche.
            $frontiereVoulue = strtolower(trim((string)($cfgCarbone['frontiere'] ?? 'amont + combustion')));
            $candidats = [];
            foreach ($data['results'] as $row) {
                $statut = $row["Statut_de_l'élément"] ?? '';
                if (strpos($statut, 'Valide') === false) continue;
                if (($row["Identifiant_de_l'élément"] ?? '') !== $ademeId) continue;
                $candidats[] = $row;
            }

            if (!$candidats) {
                $debug[] = "WARN $type : aucun resultat valide pour l'ID $ademeId";
                continue;
            }

            $bestRow = null;
            if (count($candidats) === 1) {
                $bestRow = $candidats[0];
            } else {
                $frontieres = [];
                foreach ($candidats as $row) {
                    $f = strtolower(trim((string)($row['Nom_frontière_français'] ?? '')));
                    $frontieres[] = $f !== '' ? $f : '(vide)';
                    if ($f === $frontiereVoulue) { $bestRow = $row; break; }
                }
                if (!$bestRow) {
                    $debug[] = "WARN $type : " . count($candidats) . " frontieres pour l'ID $ademeId ("
                             . implode(' | ', array_unique($frontieres)) . "), aucune ne correspond a « "
                             . $frontiereVoulue . " » → defaut conserve";
                    continue;
                }
            }

            $val = null;
            foreach (['Total_poste_non_décomposé', 'CO2f'] as $field) {
                $raw = $bestRow[$field] ?? null;
                if ($raw === null || $raw === '') continue;
                if (is_numeric($raw)) { $val = (float)$raw; break; }
                $cleaned = str_replace(',', '.', (string)$raw);
                if (is_numeric($cleaned)) { $val = (float)$cleaned; break; }
            }

            if ($val === null || $val <= 0) {
                $debug[] = "WARN $type : CO2 vide ou nul dans la reponse API";
                continue;
            }

            $defVal = $defaults[$type]['facteurCO2'];
            if ($val > $defVal * 10 || $val < $defVal * 0.1) {
                $debug[] = "WARN $type : valeur API $val hors plage attendue (defaut=$defVal), ignoree";
                continue;
            }

            $unite = $bestRow['Unité_français'] ?? '';
            $uniteOk = (stripos($unite, 'kWh') !== false || stripos($unite, 'kwh') !== false || $unite === '');
            if (!$uniteOk) {
                $debug[] = "WARN $type : unite API '$unite' incompatible (attendu kgCO2e/kWh), defaut conserve";
                continue;
            }

            $db->upsertFacteurCarbone([
                'annee'            => $annee,
                'type'             => $type,
                'facteurCO2'       => $val,
                'unite'            => $bestRow['Unité_français'] ?? $defaults[$type]['unite'],
                'source'           => 'ADEME Base Carbone (API) — frontière : '
                                    . (($bestRow['Nom_frontière_français'] ?? '') ?: 'non précisée'),
                'identifiantADEME' => $bestRow["Identifiant_de_l'élément"] ?? $ademeId,
                'nomADEME'         => trim(implode(' — ', array_filter([
                    $bestRow['Nom_base_français'] ?? '',
                    $bestRow['Nom_attribut_français'] ?? '',
                    $bestRow['Nom_frontière_français'] ?? '',
                ]))),
                'incertitude'      => $bestRow['Incertitude'] ?? $defaults[$type]['incertitude'],
                'methode'          => 'officielle',
            ]);
            $results[$type]['facteurCO2'] = $val;
            $results[$type]['source']     = 'ADEME Base Carbone (API)';
            $apiSuccess = true;
            $debug[] = "OK $type : API → $val (ID $ademeId)";

        } catch (\Exception $e) {
            $debug[] = "ERR $type : " . $e->getMessage();
        }
    }

    json_ok([
        'annee'      => $annee,
        'methode'    => 'officielle',
        'apiSuccess' => $apiSuccess,
        'facteurs'   => $results,
        'debug'      => $debug,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── SYNC SIMPLIFIÉE — Impact CO2 / Datagir ──────────────────────────────────
// Enregistre des facteurs moyens nationaux pour une année donnée.
// Pas d'appel API externe : valeurs hardcodées, mises à jour avec le code.
// Source : impactco2.fr / Datagir / ADEME (données grand public vulgarisées)
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'energie_sync_carbone_simplifiee') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body  = get_body();
    $annee = intval($body['annee'] ?? date('Y'));

    // ── Valeurs simplifiées Impact CO2 / Datagir ──
    // Uniquement les 3 types d'énergie gérés par Larka (Electricite, Gaz, Chauffage).
    // Valeurs DIFFÉRENTES de l'officiel ADEME (approche vulgarisée, moyennes nationales) :
    //   - Élec : 0.0520 kgCO2e/kWh (conso moyenne France, Base Empreinte sans ACV chauffage)
    //   - Gaz  : 0.243 kgCO2e/kWh (ACV complet combustion+amont, source ECOinfos/Datagir)
    //   - Chauffage réseau : 0.116 kgCO2e/kWh (moyenne nationale réseaux, source notre-environnement.gouv.fr)
    $simplifies = [
        'Electricite' => [
            'facteurCO2'  => 0.0520,
            'unite'       => 'kgCO2e/kWh',
            'source'      => 'Impact CO2 / Datagir — Conso moyenne France',
            'nomADEME'    => 'Electricité — Conso moyenne France (Impact CO2 / Datagir)',
            'incertitude' => 15,
        ],
        'Gaz' => [
            'facteurCO2'  => 0.243,
            'unite'       => 'kgCO2e/kWh',
            'source'      => 'Impact CO2 / Datagir — ACV complet',
            'nomADEME'    => 'Gaz naturel — ACV complet combustion+amont (Impact CO2 / Datagir)',
            'incertitude' => 10,
        ],
        'Chauffage' => [
            'facteurCO2'  => 0.116,
            'unite'       => 'kgCO2e/kWh',
            'source'      => 'Impact CO2 / Datagir — Moyenne nationale réseaux',
            'nomADEME'    => 'Réseau de chaleur — Moyenne nationale (Impact CO2 / Datagir)',
            'incertitude' => 50,
        ],
    ];

    $results = [];
    $debug   = [];
    foreach ($simplifies as $type => $def) {
        $id = $db->upsertFacteurCarbone([
            'annee'            => $annee,
            'type'             => $type,
            'facteurCO2'       => $def['facteurCO2'],
            'unite'            => $def['unite'],
            'source'           => $def['source'],
            'identifiantADEME' => null,
            'nomADEME'         => $def['nomADEME'],
            'incertitude'      => $def['incertitude'],
            'methode'          => 'simplifiee',
        ]);
        $results[$type] = [
            'id'         => $id,
            'facteurCO2' => $def['facteurCO2'],
            'source'     => $def['source'],
        ];
        $debug[] = "OK $type simplifie sauve : {$def['facteurCO2']} {$def['unite']}";
    }

    json_ok([
        'annee'    => $annee,
        'methode'  => 'simplifiee',
        'facteurs' => $results,
        'debug'    => $debug,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── RECALCUL CO₂ en masse (avec choix de méthode) ───────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'energie_recalcul_carbone') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body    = get_body();
    $methode = $body['methode'] ?? 'officielle';
    if (!in_array($methode, ['officielle', 'simplifiee'])) $methode = 'officielle';

    $compteurs     = $db->getEnergieCompteurs();
    $updated       = 0;
    $skipped       = 0;
    $errors        = 0;
    $typesAvecCO2  = ['Electricite', 'Gaz', 'Chauffage'];

    foreach ($compteurs as $cpt) {
        $type = $cpt['Type'];
        if (!in_array($type, $typesAvecCO2)) continue;

        // Pour le chauffage : utiliser le coeff CO₂ spécifique du compteur si dispo
        $compteurCoeffCO2 = null;
        if ($type === 'Chauffage') {
            $tedCfg = $cpt['ChauffageTED'] ?? $cpt['chauffageted'] ?? null;
            if ($tedCfg) {
                $ted = @json_decode($tedCfg, true);
                if ($ted && isset($ted['coeffCO2']) && floatval($ted['coeffCO2']) > 0) {
                    $compteurCoeffCO2 = floatval($ted['coeffCO2']);
                }
            }
        }

        $releves = $db->getEnergieReleves((int)$cpt['Id']);
        foreach ($releves as $r) {
            $conso = floatval($r['Consommation'] ?? 0);
            if ($conso <= 0) { $skipped++; continue; }

            $annee = $r['Date'] ? (int)substr($r['Date'], 0, 4) : null;
            if (!$annee) { $skipped++; continue; }

            if ($type === 'Chauffage' && $compteurCoeffCO2) {
                // Coefficient réseau spécifique (en kgCO₂/kWh), conso en MWh
                $co2 = $conso * $compteurCoeffCO2 * 1000;
            } else {
                // Utiliser le facteur de la MÉTHODE choisie pour l'ANNÉE de la consommation
                $fc = $db->getFacteurCarbonePlusProcheAnnee($type, $annee, $methode);
                if (!$fc || floatval($fc['FacteurCO2']) <= 0) { $skipped++; continue; }
                $facteur = floatval($fc['FacteurCO2']);
                $co2 = ($type === 'Chauffage') ? $conso * $facteur * 1000 : $conso * $facteur;
            }

            if ($co2 <= 0) { $skipped++; continue; }

            try {
                $stmt = $db->getPdo()->prepare("UPDATE ReleverEnergie SET Carbone=? WHERE Id=?");
                $stmt->execute([round($co2, 2), (int)$r['Id']]);
                $updated++;
            } catch (\Exception $e) {
                $errors++;
            }
        }
    }

    $methodeLabel = $methode === 'simplifiee' ? 'simplifiée (Impact CO2)' : 'officielle (ADEME)';
    json_ok([
        'updated' => $updated,
        'skipped' => $skipped,
        'errors'  => $errors,
        'methode' => $methode,
        'message' => "$updated relevé(s) mis à jour (méthode $methodeLabel), $skipped ignoré(s)" . ($errors > 0 ? ", $errors erreur(s)" : ''),
    ]);
}

// ── Proxy ADEME — contourne le CORS pour le frontend ─────────────────────────
if ($action === 'energie_proxy_ademe') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $query = $_GET['q'] ?? '';
    $qs    = $_GET['qs'] ?? '';
    $size  = intval($_GET['size'] ?? 20);
    if ($size > 50) $size = 50;

    $baseUrl = 'https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/lines';
    $params = [
        'size' => $size,
        'select' => implode(',', [
            "Identifiant_de_l'élément","Nom_base_français","Nom_attribut_français",
            "Nom_frontière_français","Unité_français","Total_poste_non_décomposé",
            "CO2f","CH4f","N2O",
            "Statut_de_l'élément","Type_Ligne","Structure",
            "Localisation_géographique","Incertitude"
        ]),
    ];
    if ($qs) $params['qs'] = $qs;
    elseif ($query) { $params['q'] = $query; $params['q_mode'] = 'simple'; }

    $url = $baseUrl . '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "Accept: application/json\r\nUser-Agent: Larka/1.0\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => (bool)($cfgCarbone['tls_verify'] ?? true),
            'verify_peer_name' => (bool)($cfgCarbone['tls_verify'] ?? true),
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        json_error('Impossible de contacter l\'API ADEME (data.ademe.fr).');
    }

    $data = json_decode($json, true);
    if ($data === null) {
        json_error('Réponse ADEME invalide.');
    }

    json_ok($data);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── RÉSEAUX DE CHALEUR — Recherche locale ───────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'energie_recherche_reseau') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'energie', $db);
    $q = $_GET['q'] ?? '';
    if (strlen($q) < 2) json_error('Recherche trop courte (min 2 caractères).');

    // Seed / mise à jour des réseaux (upsert = safe, ajoute les manquants, met à jour les existants)
    _seedReseauxDefaut($db);

    $rows = $db->rechercherReseaux($q);
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'id_reseau'  => $r['IdReseau'] ?? $r['idreseau'] ?? '',
            'nom'        => $r['Nom'] ?? $r['nom'] ?? '',
            'commune'    => $r['Commune'] ?? $r['commune'] ?? '',
            'gestionnaire' => $r['Gestionnaire'] ?? $r['gestionnaire'] ?? '',
            'co2'        => ($r['CO2'] ?? $r['co2'] ?? null) !== null ? (float)($r['CO2'] ?? $r['co2']) : null,
            'co2_acv'    => ($r['CO2ACV'] ?? $r['co2acv'] ?? null) !== null ? (float)($r['CO2ACV'] ?? $r['co2acv']) : null,
            'taux_enrr'  => ($r['TauxEnRR'] ?? $r['tauxenrr'] ?? null) !== null ? (float)($r['TauxEnRR'] ?? $r['tauxenrr']) : null,
        ];
    }
    json_ok($result);
}

// ── Import réseaux depuis saisie manuelle ou CSV ────────────────────────────
if ($action === 'energie_import_reseau') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $reseaux = $body['reseaux'] ?? [];
    if (empty($reseaux)) json_error('Aucun réseau à importer.');
    $imported = 0;
    foreach ($reseaux as $r) {
        $idReseau = $r['idReseau'] ?? $r['id_reseau'] ?? '';
        if (!$idReseau) continue;
        $db->upsertReseau([
            'idReseau'    => $idReseau,
            'nom'         => $r['nom'] ?? null,
            'commune'     => $r['commune'] ?? null,
            'gestionnaire'=> $r['gestionnaire'] ?? null,
            'co2'         => $r['co2'] ?? null,
            'co2acv'      => $r['co2_acv'] ?? $r['co2acv'] ?? null,
            'tauxEnRR'    => $r['taux_enrr'] ?? $r['tauxEnRR'] ?? null,
        ]);
        $imported++;
    }
    json_ok(['imported' => $imported]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── SEED : réseaux de chaleur courants (arrêté DPE 2025 — données 2023) ─────
// Source : Arrêté du 11 avril 2025, Annexe 7
// CO₂ en gCO₂/kWh, CO₂ ACV en gCO₂/kWh
// ═══════════════════════════════════════════════════════════════════════════════
function _seedReseauxDefaut($db) {
    $reseaux = [
        // Grandes métropoles
        ['7501C','CPCU','Paris','CPCU (Engie)',127,149,56],
        ['7502C','Réseau de froid Climespace','Paris','Climespace (Engie)',null,null,null],
        ['6915C','Réseau Centre Métropole','Lyon','Dalkia',68,95,72],
        ['6916C','Réseau Lyon Confluence','Lyon','Dalkia',24,51,90],
        ['1318C','Réseau de Marseille','Marseille','Dalkia',98,125,65],
        ['3101C','Réseau du Mirail','Toulouse','Eneriance (Coriance)',22,49,99],
        ['3103C','Réseau de Blagnac (Ritouret)','Blagnac','Blagnac Énergies Vertes (Véolia)',78,105,76],
        ['3112C','Réseau Plaine Campus','Toulouse','Toulouse Énergie Durable (Dalkia)',58,85,70],
        ['3113C','Réseau Toulouse Nord-Est','Toulouse','—',null,null,null],
        ['4420C','Réseau Nantes','Nantes','Idex',52,79,80],
        ['3323C','Réseau Bordeaux Mériadeck','Bordeaux','Régaz-Bordeaux',48,75,83],
        ['6718C','Réseau Strasbourg','Strasbourg','R-CUA',58,85,77],
        ['3529C','Réseau Rennes','Rennes','Engie Réseaux',49,76,82],
        ['5906C','Réseau Lille','Lille','Dalkia',104,131,62],
        ['0613C','Réseau Nice','Nice','Dalkia',182,209,22],
        ['3818C','Réseau Grenoble','Grenoble','CCIAG',30,57,88],
        ['2116C','Réseau Dijon','Dijon','Sodien',45,72,84],
        ['7614C','Réseau Rouen','Rouen','Dalkia',108,135,60],
        ['4918C','Réseau Angers','Angers','Alter Energies',28,55,90],
        ['3725C','Réseau Tours','Tours','Cofely',68,95,73],
        ['5107C','Réseau Reims','Reims','Coriance',53,80,79],
        ['8001C','Réseau Amiens','Amiens','Amiens Energies',32,59,87],
        ['5720C','Réseau Metz','Metz','UEM',52,79,81],
        ['6306C','Réseau Clermont-Ferrand','Clermont-Ferrand','Dalkia',76,103,70],
        ['2506C','Réseau Besançon','Besançon','Dalkia',44,71,85],
        ['8710C','Réseau Limoges','Limoges','Dalkia',38,65,86],
        ['1411C','Réseau Caen','Caen','Dalkia',120,147,55],
        ['4512C','Réseau Orléans','Orléans','Orléans Énergie',56,83,78],
        ['7208C','Réseau Le Mans','Le Mans','Setram Énergie',35,62,87],
        ['9201C','Réseau Boulogne-Billancourt','Boulogne-Billancourt','Idex',62,89,76],
        ['9303C','Réseau Bobigny','Bobigny','Dalkia',58,85,78],
        ['9403C','Réseau Créteil','Créteil','Dalkia',25,52,91],
        ['9105C','Réseau Évry','Évry','Dalkia',47,74,83],
        ['9505C','Réseau Cergy-Pontoise','Cergy','Dalkia',64,91,75],
        // Île-de-France
        ['9202C','Réseau Issy-les-Moulineaux','Issy-les-Moulineaux','IDEX',44,71,84],
        ['9302C','Réseau Saint-Denis','Saint-Denis','Engie',86,113,67],
        ['9402C','Réseau Vitry-sur-Seine','Vitry-sur-Seine','SEMHACH',30,57,89],
        ['9404C','Réseau Ivry-sur-Seine','Ivry-sur-Seine','CPCU',82,109,68],
        ['7803C','Réseau Versailles','Versailles','Dalkia',98,125,63],
        // Défaut pour réseaux non-listés
        ['0000C','Autres réseaux de chaleur (défaut)','France','—',183,210,50],
    ];

    foreach ($reseaux as $r) {
        $db->upsertReseau([
            'idReseau'    => $r[0],
            'nom'         => $r[1],
            'commune'     => $r[2],
            'gestionnaire'=> $r[3],
            'co2'         => $r[4],
            'co2acv'      => $r[5],
            'tauxEnRR'    => $r[6],
        ]);
    }
}
