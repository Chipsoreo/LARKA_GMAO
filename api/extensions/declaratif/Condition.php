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
 * Larka — Modules déclaratifs : moteur de conditions
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   { "champ": "statut", "operateur": "egal", "valeur": "urgent" }
 *
 *   { "tous": [
 *       { "champ": "statut",  "operateur": "egal",         "valeur": "urgent" },
 *       { "champ": "montant", "operateur": "superieur_a",  "valeur": 1000 }
 *   ]}
 *
 * UNE SEULE GRAMMAIRE, RÉUTILISÉE PARTOUT
 * La même condition sert à masquer un champ, colorer une ligne, afficher un
 * ancrage, déclencher un workflow ou accorder une permission. C'est le principe
 * que la spécification appelle « primitives composables » : une notion apprise
 * une fois s'applique dans tous les contextes, au lieu de sept syntaxes
 * légèrement différentes qu'il faut réapprendre à chaque écran.
 *
 * SÉCURITÉ
 * Comme pour les expressions, le catalogue d'opérateurs est FERMÉ et résolu par
 * un `match`. Un opérateur inconnu est refusé à l'installation, jamais cherché
 * ailleurs. Les valeurs peuvent contenir des {{expressions}}, évaluées par
 * ExtExpression — donc sans exécution de code non plus.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Expression.php';

final class ExtCondition
{

    /**
     * Aide de chaque opérateur : ce qu'il vérifie, et un exemple lisible.
     * Même raison que pour les fonctions : la description vit dans le code,
     * la documentation en découle.
     */
    public const AIDE = [
        'egal'                 => ['La valeur est exactement celle-ci', 'statut = « urgent »'],
        'different'            => ['La valeur est autre', 'statut ≠ « clos »'],
        'contient'             => ['Le texte contient ce fragment, casse ignorée', 'nom contient « vanne »'],
        'ne_contient_pas'      => ['Le texte ne contient pas ce fragment', 'nom ne contient pas « test »'],
        'commence_par'         => ['Le texte débute par', 'numero commence par « EQ- »'],
        'finit_par'            => ['Le texte se termine par', 'fichier finit par « .pdf »'],
        'superieur_a'          => ['Strictement plus grand', 'montant > 1000'],
        'superieur_ou_egal'    => ['Plus grand ou égal', 'quantite ≥ 1'],
        'inferieur_a'          => ['Strictement plus petit', 'stock < 5'],
        'inferieur_ou_egal'    => ['Plus petit ou égal', 'note ≤ 3'],
        'entre'                => ['Compris entre deux bornes incluses', 'montant entre [100, 500]'],
        'parmi'                => ['Figure dans la liste', 'statut parmi [urgent, critique]'],
        'pas_parmi'            => ['Ne figure pas dans la liste', 'statut hors de [clos]'],
        'est_vide'             => ['Nul, chaîne vide, zéro ou liste vide', 'detenteur est vide'],
        'non_vide'             => ['Renseigné', 'detenteur non vide'],
        'est_nul'              => ['Strictement nul', 'retour est nul'],
        'non_nul'              => ['Non nul, même vide', 'retour non nul'],
        'a_change'             => ['A changé depuis l\'enregistrement précédent', 'statut a changé'],
        'a_change_depuis'      => ['Valait cette valeur, et a changé', 'statut était « ouvert »'],
        'a_change_vers'        => ['Vient de prendre cette valeur', 'statut est devenu « clos »'],
        'commence_par_un_de'   => ['Débute par l\'un des préfixes', 'numero commence par [EQ-, BI-]'],
        'contient_un_de'       => ['Contient l\'un des fragments', 'objet contient [fuite, panne]'],
        'longueur_superieure'  => ['Plus long que N caractères', 'commentaire fait plus de 200'],
        'longueur_inferieure'  => ['Plus court que N caractères', 'code fait moins de 5'],
        'est_vrai'             => ['Case cochée', 'conforme est vrai'],
        'est_faux'             => ['Case décochée', 'conforme est faux'],
        'date_passee'          => ['Date antérieure à aujourd\'hui', 'echeance est passée'],
        'date_future'          => ['Date postérieure à aujourd\'hui', 'livraison est à venir'],
        'date_aujourdhui'      => ['Date du jour', 'realise_le est aujourd\'hui'],
        'dans_les_n_jours'     => ['Échoit dans N jours ou moins', 'echeance dans 30 jours'],
        'depuis_plus_de_jours' => ['Date de plus de N jours', 'livraison depuis plus de 365'],
        'multiple_de'          => ['Divisible par N', 'quantite multiple de 12'],
        'correspond_format'    => ['Respecte un format nommé', 'courriel correspond à « email »'],
    ];

    private const PROFONDEUR_MAX = 15;
    private const CLAUSES_MAX    = 200;

    /**
     * Catalogue FERMÉ. La valeur indique si l'opérateur attend une valeur de
     * comparaison — « est_vide » n'en attend pas, et en exiger une serait un
     * piège inutile pour l'auteur.
     */
    public const OPERATEURS = [
        'egal'              => true,
        'different'         => true,
        'contient'          => true,
        'ne_contient_pas'   => true,
        'commence_par'      => true,
        'finit_par'         => true,
        'superieur_a'       => true,
        'superieur_ou_egal' => true,
        'inferieur_a'       => true,
        'inferieur_ou_egal' => true,
        'entre'             => true,   // valeur = [min, max]
        'parmi'             => true,   // valeur = liste
        'pas_parmi'         => true,
        'est_vide'          => false,
        'non_vide'          => false,
        'est_nul'           => false,
        'non_nul'           => false,
        // Comparaison avec l'état précédent : n'a de sens qu'à la modification,
        // où le moteur fournit « avant ».
        'a_change'          => false,
        'a_change_depuis'   => true,
        'a_change_vers'     => true,

        // ── Opérateurs ajoutés ───────────────────────────────────────────
        'commence_par_un_de' => true,   // valeur = liste de préfixes
        'contient_un_de'     => true,   // valeur = liste de fragments
        'longueur_superieure'=> true,
        'longueur_inferieure'=> true,
        'est_vrai'           => false,
        'est_faux'           => false,
        'date_passee'        => false,
        'date_future'        => false,
        'date_aujourdhui'    => false,
        'dans_les_n_jours'   => true,   // échéance proche
        'depuis_plus_de_jours' => true, // ancienneté
        'multiple_de'        => true,
        'correspond_format'  => true,   // email, telephone, code_postal…
    ];

    private const LOGIQUES = ['tous', 'au_moins_un', 'aucun'];

    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Valide une condition et la met en forme.
     * @throws RuntimeException à la première anomalie
     */
    public static function compiler(mixed $c, array $champsConnus = [],
                                    int $profondeur = 0): array
    {
        if ($profondeur > self::PROFONDEUR_MAX) {
            throw new RuntimeException('Condition trop imbriquée ('
                . self::PROFONDEUR_MAX . ' niveaux maximum).');
        }
        if (!is_array($c) || $c === []) {
            throw new RuntimeException('Condition : objet attendu, par exemple '
                . '{"champ":"statut","operateur":"egal","valeur":"urgent"}');
        }

        // ── Groupe logique ───────────────────────────────────────────────
        foreach (self::LOGIQUES as $logique) {
            if (!isset($c[$logique])) continue;
            if (!is_array($c[$logique]) || $c[$logique] === []) {
                throw new RuntimeException("Condition « $logique » : liste non vide attendue.");
            }
            if (count($c[$logique]) > self::CLAUSES_MAX) {
                throw new RuntimeException('Condition : ' . self::CLAUSES_MAX
                    . ' clauses au maximum.');
            }
            $clauses = [];
            foreach ($c[$logique] as $sous) {
                $clauses[] = self::compiler($sous, $champsConnus, $profondeur + 1);
            }
            return ['t' => 'groupe', 'logique' => $logique, 'clauses' => $clauses];
        }

        // ── Clause simple ────────────────────────────────────────────────
        $champ = (string)($c['champ'] ?? '');
        if ($champ === '') {
            throw new RuntimeException('Condition : champ « champ » obligatoire. '
                . 'Groupes possibles : ' . implode(', ', self::LOGIQUES) . '.');
        }
        if ($champsConnus !== [] && !isset($champsConnus[$champ])) {
            throw new RuntimeException("Condition : le champ « $champ » n'existe pas. "
                . 'Champs : ' . implode(', ', array_keys($champsConnus)));
        }

        $op = (string)($c['operateur'] ?? 'egal');
        if (!isset(self::OPERATEURS[$op])) {
            $proche = self::plusProche($op);
            throw new RuntimeException("Opérateur « $op » inconnu."
                . ($proche ? " Vouliez-vous dire « $proche » ?" : '')
                . ' Opérateurs : ' . implode(', ', array_keys(self::OPERATEURS)));
        }

        $noeud = ['t' => 'clause', 'champ' => $champ, 'operateur' => $op];

        if (self::OPERATEURS[$op]) {
            if (!array_key_exists('valeur', $c)) {
                throw new RuntimeException("Condition sur « $champ » : l'opérateur « $op » "
                    . 'attend une « valeur ».');
            }
            $v = $c['valeur'];

            if (in_array($op, ['entre'], true)) {
                if (!is_array($v) || count($v) !== 2) {
                    throw new RuntimeException("Condition « entre » sur « $champ » : "
                        . 'valeur attendue sous la forme [min, max].');
                }
            } elseif (in_array($op, ['parmi', 'pas_parmi', 'commence_par_un_de',
                                     'contient_un_de'], true)) {
                if (!is_array($v) || $v === []) {
                    throw new RuntimeException("Condition « $op » sur « $champ » : "
                        . 'liste de valeurs attendue.');
                }
            } elseif (!is_scalar($v) && $v !== null) {
                throw new RuntimeException("Condition sur « $champ » : valeur simple attendue.");
            }

            // Une valeur peut être une expression : {{today}}, {{user.nom}}…
            // Compilée maintenant pour qu'une faute échoue à l'installation.
            if (is_string($v) && str_contains($v, '{{')) {
                $nue = trim($v);
                if (str_starts_with($nue, '{{') && str_ends_with($nue, '}}')) {
                    $r = ExtExpression::valider(trim(substr($nue, 2, -2)));
                    if (!$r['ok']) {
                        throw new RuntimeException("Condition sur « $champ » : " . $r['erreur']);
                    }
                    $noeud['expression'] = $r['arbre'];
                }
            }
            $noeud['valeur'] = $v;
        }
        return $noeud;
    }

    /** Compile sans lever. */
    public static function valider(mixed $c, array $champs = []): array
    {
        try {
            return ['ok' => true, 'arbre' => self::compiler($c, $champs)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'erreur' => $e->getMessage()];
        }
    }

    /**
     * Évalue une condition compilée.
     *
     * @param array $ligne    l'enregistrement courant
     * @param array $contexte user, today, variables… pour les {{expressions}}
     * @param array $avant    état précédent, pour a_change* — vide sinon
     */
    public static function evaluer(array $arbre, array $ligne,
                                   array $contexte = [], array $avant = []): bool
    {
        if (($arbre['t'] ?? '') === 'groupe') {
            $resultats = array_map(
                fn($c) => self::evaluer($c, $ligne, $contexte, $avant),
                $arbre['clauses']);

            return match ($arbre['logique']) {
                'tous'        => !in_array(false, $resultats, true),
                'au_moins_un' => in_array(true, $resultats, true),
                'aucun'       => !in_array(true, $resultats, true),
                default       => false,
            };
        }

        $champ    = $arbre['champ'];
        $actuel   = $ligne[$champ] ?? null;
        $ancienne = $avant[$champ] ?? null;

        // Valeur de comparaison : littérale, ou résultat d'une expression.
        $valeur = $arbre['valeur'] ?? null;
        if (isset($arbre['expression'])) {
            $valeur = ExtExpression::evaluer($arbre['expression'],
                $contexte + ['record' => $ligne]);
        }

        return match ($arbre['operateur']) {
            'egal'              => self::egal($actuel, $valeur),
            'different'         => !self::egal($actuel, $valeur),
            'contient'          => self::contient($actuel, $valeur),
            'ne_contient_pas'   => !self::contient($actuel, $valeur),
            'commence_par'      => str_starts_with(self::txt($actuel), self::txt($valeur)),
            'finit_par'         => str_ends_with(self::txt($actuel), self::txt($valeur)),
            'superieur_a'       => self::comparer($actuel, $valeur) > 0,
            'superieur_ou_egal' => self::comparer($actuel, $valeur) >= 0,
            'inferieur_a'       => self::comparer($actuel, $valeur) < 0,
            'inferieur_ou_egal' => self::comparer($actuel, $valeur) <= 0,
            'entre'             => self::comparer($actuel, $valeur[0]) >= 0
                                && self::comparer($actuel, $valeur[1]) <= 0,
            'parmi'             => self::parmi($actuel, (array)$valeur),
            'pas_parmi'         => !self::parmi($actuel, (array)$valeur),
            'est_vide'          => self::vide($actuel),
            'non_vide'          => !self::vide($actuel),
            'est_nul'           => $actuel === null,
            'non_nul'           => $actuel !== null,
            // Sans état précédent, « a changé » est FAUX plutôt que vrai : à la
            // création, rien n'a changé — considérer l'inverse déclencherait
            // toutes les règles de modification sur chaque nouvel enregistrement.
            'a_change'          => $avant !== [] && !self::egal($actuel, $ancienne),
            'a_change_depuis'   => $avant !== [] && self::egal($ancienne, $valeur)
                                && !self::egal($actuel, $ancienne),
            'a_change_vers'     => $avant !== [] && self::egal($actuel, $valeur)
                                && !self::egal($actuel, $ancienne),

            'commence_par_un_de'  => self::unParmi($actuel, (array)$valeur, 'debut'),
            'contient_un_de'      => self::unParmi($actuel, (array)$valeur, 'contient'),
            'longueur_superieure' => mb_strlen(self::txt($actuel)) > (int)$valeur,
            'longueur_inferieure' => mb_strlen(self::txt($actuel)) < (int)$valeur,
            'est_vrai'            => (bool)$actuel && $actuel !== '0',
            'est_faux'            => !$actuel || $actuel === '0',
            'date_passee'         => self::jours($actuel) !== null && self::jours($actuel) < 0,
            'date_future'         => self::jours($actuel) !== null && self::jours($actuel) > 0,
            'date_aujourdhui'     => self::jours($actuel) === 0,
            // Échéance à venir dans N jours : le cas des contrôles à planifier.
            'dans_les_n_jours'    => ($j = self::jours($actuel)) !== null
                                  && $j >= 0 && $j <= (int)$valeur,
            'depuis_plus_de_jours'=> ($j = self::jours($actuel)) !== null
                                  && $j < -(int)$valeur,
            'multiple_de'         => ((int)$valeur) !== 0 && is_numeric($actuel)
                                  && ((int)$actuel) % ((int)$valeur) === 0,
            'correspond_format'   => self::respecteFormat($actuel, (string)$valeur),
            default             => false,
        };
    }

    /** Décrit une condition en français, pour l'écran d'installation. */
    public static function decrire(array $arbre, array $champs = []): string
    {
        if (($arbre['t'] ?? '') === 'groupe') {
            $liaison = match ($arbre['logique']) {
                'tous' => ' et ', 'au_moins_un' => ' ou ', default => ' ni ',
            };
            $morceaux = array_map(fn($c) => self::decrire($c, $champs), $arbre['clauses']);
            $texte = implode($liaison, $morceaux);
            return $arbre['logique'] === 'aucun' ? "ni $texte" : "($texte)";
        }

        $nom = $champs[$arbre['champ']]['libelle'] ?? $arbre['champ'];
        $v   = $arbre['valeur'] ?? null;
        $vt  = is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v;

        return $nom . ' ' . match ($arbre['operateur']) {
            'egal' => "= « $vt »",                'different' => "≠ « $vt »",
            'contient' => "contient « $vt »",     'ne_contient_pas' => "ne contient pas « $vt »",
            'commence_par' => "commence par « $vt »", 'finit_par' => "finit par « $vt »",
            'superieur_a' => "> $vt",             'superieur_ou_egal' => "≥ $vt",
            'inferieur_a' => "< $vt",             'inferieur_ou_egal' => "≤ $vt",
            'entre' => "entre $vt",               'parmi' => "parmi ($vt)",
            'pas_parmi' => "hors de ($vt)",       'est_vide' => 'est vide',
            'non_vide' => "n'est pas vide",       'est_nul' => 'est nul',
            'non_nul' => "n'est pas nul",         'a_change' => 'a changé',
            'a_change_depuis' => "était « $vt » et a changé",
            'a_change_vers' => "est devenu « $vt »",
            'commence_par_un_de'   => "commence par l'un de ($vt)",
            'contient_un_de'       => "contient l'un de ($vt)",
            'longueur_superieure'  => "fait plus de $vt caractères",
            'longueur_inferieure'  => "fait moins de $vt caractères",
            'est_vrai'             => 'est vrai',
            'est_faux'             => 'est faux',
            'date_passee'          => 'est une date passée',
            'date_future'          => 'est une date à venir',
            'date_aujourdhui'      => "est aujourd'hui",
            'dans_les_n_jours'     => "échoit dans $vt jour(s) ou moins",
            'depuis_plus_de_jours' => "date de plus de $vt jour(s)",
            'multiple_de'          => "est un multiple de $vt",
            'correspond_format'    => "respecte le format « $vt »",
            default => $arbre['operateur'],
        };
    }

    // ── Comparaisons ────────────────────────────────────────────────────────

    private static function txt(mixed $v): string
    {
        return is_scalar($v) ? (string)$v : '';
    }

    private static function vide(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [] || $v === 0 || $v === '0';
    }

    private static function egal(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        if (is_numeric($a) && is_numeric($b)) return (float)$a == (float)$b;
        if (is_bool($a) || is_bool($b)) return (bool)$a === (bool)$b;
        return (string)$a === (string)$b;
    }

    private static function comparer(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) return (float)$a <=> (float)$b;
        // Dates ISO : la comparaison de chaînes suffit et reste exacte.
        return strcmp(self::txt($a), self::txt($b));
    }

    private static function contient(mixed $a, mixed $b): bool
    {
        if (is_array($a)) return in_array($b, $a);
        return $b !== null && $b !== ''
            && str_contains(mb_strtolower(self::txt($a)), mb_strtolower(self::txt($b)));
    }

    private static function parmi(mixed $a, array $liste): bool
    {
        foreach ($liste as $v) if (self::egal($a, $v)) return true;
        return false;
    }

    /** Nombre de jours d'écart avec aujourd'hui. Négatif si la date est passée. */
    private static function jours(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        $t = strtotime(self::txt($v));
        if ($t === false) return null;
        return (int)floor(($t - strtotime(date('Y-m-d'))) / 86400);
    }

    private static function unParmi(mixed $a, array $liste, string $mode): bool
    {
        $s = mb_strtolower(self::txt($a));
        foreach ($liste as $v) {
            $x = mb_strtolower(self::txt($v));
            if ($x === '') continue;
            if ($mode === 'debut' && str_starts_with($s, $x)) return true;
            if ($mode === 'contient' && str_contains($s, $x)) return true;
        }
        return false;
    }

    /**
     * Vérifie une forme nommée. Les motifs viennent du schéma : une seule
     * définition, pour la saisie comme pour les conditions.
     */
    private static function respecteFormat(mixed $a, string $format): bool
    {
        $f = ExtSchemaDeclaratif::FORMATS_SAISIE[$format] ?? null;
        if ($f === null) return false;
        return (bool)preg_match($f['motif'], self::txt($a));
    }

    private static function plusProche(string $op): ?string
    {
        $meilleur = null; $d = PHP_INT_MAX;
        foreach (array_keys(self::OPERATEURS) as $o) {
            $x = levenshtein(strtolower($op), $o);
            if ($x < $d) { $d = $x; $meilleur = $o; }
        }
        return $d <= 4 ? $meilleur : null;
    }
}
