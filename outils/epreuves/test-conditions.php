<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — épreuve du moteur de conditions.
 *
 * Une seule grammaire sert à masquer un champ, afficher un ancrage, déclencher
 * un workflow ou accorder une permission. Une erreur ici se propagerait donc
 * partout, et une condition qui rend « vrai » à tort ouvre un accès.
 *
 *   php outils/epreuves/test-conditions.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
require_once dirname(__DIR__, 2) . '/api/extensions/declaratif/Condition.php';

$champs = ['statut' => ['libelle' => 'Statut'], 'montant' => ['libelle' => 'Montant'],
           'echeance' => ['libelle' => 'Échéance'], 'note' => ['libelle' => 'Note']];
$ligne = ['statut' => 'urgent', 'montant' => 1500, 'echeance' => '2026-09-01', 'note' => ''];
$avant = ['statut' => 'normal', 'montant' => 1500, 'echeance' => '2026-09-01', 'note' => ''];
$ctx   = ['today' => '2026-08-21', 'user' => ['nom' => 'mlarcin']];

$ok = 0; $ko = [];

function verdict(array $c, bool $attendu, array $champs, array $ligne,
                 array $ctx, array $avant, int &$ok, array &$ko): void
{
    $r = ExtCondition::valider($c, $champs);
    if (!$r['ok']) { $ko[] = $r['erreur']; printf("  ❌  %s\n", $r['erreur']); return; }
    $v = ExtCondition::evaluer($r['arbre'], $ligne, $ctx, $avant);
    $texte = ExtCondition::decrire($r['arbre'], $champs);
    if ($v === $attendu) { $ok++; printf("  ✅  %-52s %s\n", $texte, $v ? 'VRAI' : 'faux'); }
    else { $ko[] = $texte; printf("  ❌  %-52s %s (attendu %s)\n", $texte,
           $v ? 'VRAI' : 'faux', $attendu ? 'VRAI' : 'faux'); }
}

function refus(mixed $c, array $champs, int &$ok, array &$ko): void
{
    $r = ExtCondition::valider($c, $champs);
    if (!$r['ok']) { $ok++; printf("  ✅  ⛔ %s\n", mb_substr($r['erreur'], 0, 66)); }
    else { $ko[] = json_encode($c); printf("  ❌  acceptée : %s\n", json_encode($c)); }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — épreuve du moteur de conditions\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

echo "  Opérateurs\n";
verdict(['champ'=>'statut','operateur'=>'egal','valeur'=>'urgent'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'statut','operateur'=>'different','valeur'=>'urgent'], false, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'montant','operateur'=>'superieur_a','valeur'=>1000], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'montant','operateur'=>'entre','valeur'=>[1000,2000]], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'montant','operateur'=>'entre','valeur'=>[2000,3000]], false, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'statut','operateur'=>'parmi','valeur'=>['urgent','critique']], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'statut','operateur'=>'pas_parmi','valeur'=>['froid']], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'statut','operateur'=>'commence_par','valeur'=>'urg'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'note','operateur'=>'est_vide'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'note','operateur'=>'non_vide'], false, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'echeance','operateur'=>'superieur_a','valeur'=>'{{today}}'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);

echo "\n  Détection de changement\n";
verdict(['champ'=>'statut','operateur'=>'a_change'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'montant','operateur'=>'a_change'], false, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'statut','operateur'=>'a_change_vers','valeur'=>'urgent'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['champ'=>'statut','operateur'=>'a_change_depuis','valeur'=>'normal'], true, $champs,$ligne,$ctx,$avant,$ok,$ko);
// À la CRÉATION (aucun état précédent), « a changé » doit être faux : sinon
// toute règle de modification se déclencherait sur chaque nouvel enregistrement.
verdict(['champ'=>'statut','operateur'=>'a_change'], false, $champs,$ligne,$ctx,[],$ok,$ko);

echo "\n  Groupes logiques\n";
verdict(['tous'=>[['champ'=>'statut','operateur'=>'egal','valeur'=>'urgent'],
                  ['champ'=>'montant','operateur'=>'superieur_a','valeur'=>1000]]],
        true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['tous'=>[['champ'=>'statut','operateur'=>'egal','valeur'=>'urgent'],
                  ['champ'=>'montant','operateur'=>'superieur_a','valeur'=>9999]]],
        false, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['au_moins_un'=>[['champ'=>'statut','operateur'=>'egal','valeur'=>'froid'],
                         ['champ'=>'montant','operateur'=>'superieur_a','valeur'=>1000]]],
        true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['aucun'=>[['champ'=>'statut','operateur'=>'egal','valeur'=>'froid']]],
        true, $champs,$ligne,$ctx,$avant,$ok,$ko);
verdict(['tous'=>[['au_moins_un'=>[['champ'=>'statut','operateur'=>'egal','valeur'=>'urgent'],
                                   ['champ'=>'statut','operateur'=>'egal','valeur'=>'critique']]],
                  ['champ'=>'montant','operateur'=>'superieur_a','valeur'=>500]]],
        true, $champs,$ligne,$ctx,$avant,$ok,$ko);

echo "\n  Déclarations refusées\n";
refus(['champ'=>'statut','operateur'=>'egale','valeur'=>'x'], $champs, $ok, $ko);
refus(['champ'=>'inexistant','operateur'=>'egal','valeur'=>'x'], $champs, $ok, $ko);
refus(['champ'=>'montant','operateur'=>'entre','valeur'=>5], $champs, $ok, $ko);
refus(['champ'=>'statut','operateur'=>'egal'], $champs, $ok, $ko);
refus(['champ'=>'statut','operateur'=>'egal','valeur'=>'{{system("id")}}'], $champs, $ok, $ko);
refus([], $champs, $ok, $ko);
refus(['tous'=>[]], $champs, $ok, $ko);
// Imbrication excessive : une condition n'est pas un programme.
//
// La profondeur d'épreuve était figée à 15, soit exactement l'ancienne borne
// plus cinq. Relever la borne à 15 a donc fait passer ce cas — l'épreuve
// mesurait un chiffre, pas une propriété, et elle s'est tue au moment précis
// où elle aurait dû parler.
//
// On empile désormais très au-delà de toute borne plausible : ce qui est
// vérifié, c'est qu'une imbrication déraisonnable est refusée, quelle que soit
// la limite retenue par le schéma.
$profond = ['champ'=>'statut','operateur'=>'egal','valeur'=>'x'];
for ($i = 0; $i < 60; $i++) $profond = ['tous'=>[$profond]];
refus($profond, $champs, $ok, $ko);

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) printf(" ✅ %d/%d — conditions justes, déclarations fautives refusées.\n", $ok, $total);
else { printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
       foreach ($ko as $e) echo "      • $e\n"; }
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);
