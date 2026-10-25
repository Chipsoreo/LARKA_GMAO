<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Conformité RGPD (voir api/Rgpd.php).
 *
 *   GET  rgpd                     politique affichée — publique : l'écran de
 *                                 connexion la montre avant toute session
 *   GET  superadmin_rgpd          modèle, retouches communes, organisations
 *   GET  superadmin_rgpd&cle=…    personnalisation d'une organisation + aperçu
 *   POST superadmin_rgpd          {portee: 'modele' | 'org', cle?, donnees}
 *                                 ou {portee, cle?, reinitialiser: true}
 */

if ($action === 'rgpd') {
    require_once __DIR__ . '/../Rgpd.php';
    $langue = (string)($_GET['langue'] ?? 'fr') === 'en' ? 'en' : 'fr';
    $cle = null;
    $nom = null;
    try {
        if (!TenantResolver::isMultiTenant()) {
            $cle = 'default';
        } else {
            $k = (string)($_SESSION['tenant_key'] ?? $_SESSION['forced_tenant'] ?? '');
            if ($k === '') {
                // Avant connexion : l'organisation de CE domaine (domaines_web),
                // jamais « la première venue » — on montrerait à l'un la
                // politique d'un autre. Sinon, le modèle commun.
                $hote = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
                foreach (TenantResolver::getAllTenants() as $tk => $t) {
                    if (!empty($t['actif']) && in_array($hote, array_map('strtolower', (array)($t['domaines_web'] ?? [])), true)) {
                        $k = (string)$tk;
                        break;
                    }
                }
            }
            if ($k !== '' && ($t = TenantResolver::getTenant($k))) {
                $cle = $k;
                $nom = (string)($t['nom'] ?? '');
            }
        }
    } catch (\Throwable $e) {
        $cle = null;   // registre injoignable : le modèle Larka, sans personnalisation
    }
    json_ok(LarkaRgpd::politique($cle, $langue, $nom));
}

if ($action === 'superadmin_rgpd') {
    require_superadmin();
    require_once __DIR__ . '/../Rgpd.php';
    $multi = TenantResolver::isMultiTenant();
    $orgs = [];
    if ($multi) {
        foreach (TenantResolver::getAllTenants() as $k => $t) $orgs[(string)$k] = (string)($t['nom'] ?? $k);
    } else {
        // Installation simple : le nom saisi dans les coordonnées, s'il l'est.
        $orgs['default'] = LarkaRgpd::personnalisation('default')['identite']['organisation'] ?? 'Cette installation';
    }

    if ($method === 'GET') {
        $cle = trim((string)($_GET['cle'] ?? ''));
        if ($cle !== '') {
            if (!isset($orgs[$cle])) json_error('Organisation inconnue.', 404);
            json_ok([
                'cle'              => $cle,
                'nom'              => $orgs[$cle],
                'personnalisation' => LarkaRgpd::personnalisation($cle),
                'apercu'           => LarkaRgpd::politique($cle, 'fr', $multi ? $orgs[$cle] : null),
            ]);
        }
        $liste = [];
        foreach ($orgs as $k => $nomOrg) {
            $p = LarkaRgpd::personnalisation($k);
            $manque = LarkaRgpd::manquants($k);
            // En multi-tenant, le nom de l'organisation vaut par défaut celui du tenant.
            if ($multi) $manque = array_values(array_diff($manque, ['organisation']));
            $liste[] = [
                'cle' => $k, 'nom' => $nomOrg,
                'personnalisee' => LarkaRgpd::estPersonnalisee($p),
                'sections' => count($p['sections']), 'ajouts' => count($p['ajouts']),
                'manquants' => $manque,
                'mise_a_jour' => $p['mise_a_jour'] ?? null, 'par' => $p['par'] ?? null,
            ];
        }
        json_ok([
            'multi'         => $multi,
            'modele'        => LarkaRgpd::modele('fr'),
            'commun'        => LarkaRgpd::modeleCommun(),
            'identite'      => array_map(static fn($d) => $d[1], LarkaRgpd::IDENTITE),
            'contexte'      => LarkaRgpd::contexte(),
            'modele_date'   => LarkaRgpd::MODELE_DATE,
            'organisations' => $liste,
        ]);
    }

    if ($method !== 'POST') json_error('Méthode non supportée.', 405);
    $body = get_body();
    $portee = (string)($body['portee'] ?? '');
    if ($portee === 'modele') {
        $cleReg = 'modele';
        $libelle = 'modèle commun';
    } elseif ($portee === 'org') {
        $cle = trim((string)($body['cle'] ?? ''));
        if (!isset($orgs[$cle])) json_error('Organisation inconnue.', 404);
        $cleReg = 'org_' . $cle;
        $libelle = "« {$orgs[$cle]} »";
    } else {
        json_error('Portée invalide.');
    }
    $qui = (string)($_SESSION['superadmin']['login'] ?? 'superadmin');

    // Aperçu d'un brouillon : rien n'est enregistré.
    if (!empty($body['apercu'])) {
        $brouillon = (array)($body['donnees'] ?? []);
        $brouillon['mise_a_jour'] = date('Y-m-d H:i');
        if ($portee === 'modele') {
            json_ok(LarkaRgpd::politique(null, 'fr', null, null, $brouillon));
        }
        json_ok(LarkaRgpd::politique($cle, 'fr', $multi ? $orgs[$cle] : null, $brouillon));
    }

    if (!empty($body['reinitialiser'])) {
        TenantResolver::setRgpd($cleReg, null);
        Journal::info('rgpd', "Politique RGPD : {$libelle} ramené au modèle (par {$qui})");
        json_ok(['reinitialise' => true]);
    }

    $d = LarkaRgpd::preparer($portee, (array)($body['donnees'] ?? []));
    if ($d['identite'] === [] && $d['sections'] === [] && $d['ajouts'] === []) {
        TenantResolver::setRgpd($cleReg, null);
        Journal::info('rgpd', "Politique RGPD : {$libelle} sans personnalisation (par {$qui})");
        json_ok(['enregistre' => true, 'personnalisee' => false]);
    }
    $d['mise_a_jour'] = date('Y-m-d H:i');
    $d['par'] = $qui;
    TenantResolver::setRgpd($cleReg, $d);
    Journal::info('rgpd', "Politique RGPD : {$libelle} enregistré — " . count($d['sections']) . ' section(s) réécrite(s), '
        . count($d['ajouts']) . ' ajoutée(s) (par ' . $qui . ')');
    json_ok(['enregistre' => true, 'personnalisee' => LarkaRgpd::estPersonnalisee($d)]);
}
