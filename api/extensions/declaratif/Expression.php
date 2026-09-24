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
 * Larka — Modules déclaratifs : moteur d'expressions
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   {{record.quantite * record.prix_unitaire}}
 *   {{if(record.statut == 'urgent', 'Prioritaire', 'Normal')}}
 *   {{upper(trim(user.nom))}}
 *   {{diffDays(record.echeance, now())}}
 *
 * C'EST LE COMPOSANT LE PLUS DANGEREUX DE TOUT LE FORMAT
 *
 * Une expression est du calcul fourni par l'auteur du pack. La tentation, en PHP,
 * est d'écrire `eval('return ' . $expr . ';')` — trois lignes au lieu de sept
 * cents, et tout le modèle de sécurité s'effondre : le module de données
 * redeviendrait du code, et les cinq frontières bâties plus tôt ne serviraient
 * plus à rien.
 *
 * Ce fichier ne contient donc ni eval, ni create_function, ni preg_replace_callback
 * avec du code, ni appel de fonction par variable. Le chemin est :
 *
 *     texte  →  lexèmes  →  arbre syntaxique  →  évaluation
 *
 * À l'évaluation, chaque nœud est traité par un `match` sur un type FERMÉ. Une
 * fonction inconnue n'est pas cherchée dans PHP : elle n'existe simplement pas.
 * `{{system('rm -rf /')}}` produit une erreur de validation, pas un appel.
 *
 * BORNES
 * Profondeur, longueur, nombre de nœuds et durée sont plafonnés : une expression
 * est une formule de tableur, pas un programme.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtExpression
{
    /** Bornes. Une formule qui les dépasse est refusée à la validation. */
    private const LONGUEUR_MAX  = 4000;
    private const PROFONDEUR_MAX = 30;
    private const NOEUDS_MAX    = 1200;

    /**
     * Catalogue FERMÉ des fonctions.
     *
     * Chaque entrée : [arité min, arité max (-1 = variable)]. L'implémentation
     * est un `match` dans appliquer() — aucun nom venu du JSON ne sert jamais à
     * retrouver une fonction PHP.
     */
    public const FONCTIONS = [
        // Agrégats
        'sum' => [1, -1], 'avg' => [1, -1], 'min' => [1, -1], 'max' => [1, -1],
        'count' => [1, -1],
        // Texte
        'length' => [1, 1], 'contains' => [2, 2], 'startsWith' => [2, 2],
        'endsWith' => [2, 2], 'lower' => [1, 1], 'upper' => [1, 1],
        'trim' => [1, 1], 'replace' => [3, 3], 'concat' => [1, -1],
        // Nombres
        'round' => [1, 2], 'floor' => [1, 1], 'ceil' => [1, 1], 'abs' => [1, 1],
        // Dates
        'date' => [1, 1], 'now' => [0, 0], 'today' => [0, 0],
        'formatDate' => [2, 2], 'addDays' => [2, 2], 'addMonths' => [2, 2],
        'diffDays' => [2, 2], 'year' => [1, 1], 'month' => [1, 1], 'day' => [1, 1],
        // Logique
        'isEmpty' => [1, 1], 'isNull' => [1, 1], 'if' => [3, 3], 'coalesce' => [1, -1],
        'not' => [1, 1],

        // ── Texte (suite) ────────────────────────────────────────────────
        'substring' => [2, 3], 'padStart' => [2, 3], 'padEnd' => [2, 3],
        'capitalize' => [1, 1], 'title' => [1, 1], 'initials' => [1, 1],
        'slug' => [1, 1], 'repeat' => [2, 2], 'reverse' => [1, 1],
        'indexOf' => [2, 2], 'split' => [2, 3], 'lines' => [1, 1],
        'wordCount' => [1, 1], 'stripAccents' => [1, 1], 'mask' => [1, 3],

        // ── Nombres (suite) ──────────────────────────────────────────────
        'pow' => [2, 2], 'sqrt' => [1, 1], 'mod' => [2, 2], 'clamp' => [3, 3],
        'percent' => [2, 3], 'ratio' => [2, 2], 'sign' => [1, 1],
        'roundTo' => [2, 2], 'toNumber' => [1, 2], 'euros' => [1, 1],

        // ── Dates (suite) ────────────────────────────────────────────────
        'addYears' => [2, 2], 'addHours' => [2, 2], 'addMinutes' => [2, 2],
        'diffMonths' => [2, 2], 'diffYears' => [2, 2], 'diffHours' => [2, 2],
        'diffMinutes' => [2, 2], 'weekNumber' => [1, 1], 'dayName' => [1, 1],
        'monthName' => [1, 1], 'quarter' => [1, 1], 'isWeekend' => [1, 1],
        'startOfMonth' => [1, 1], 'endOfMonth' => [1, 1], 'startOfYear' => [1, 1],
        'endOfYear' => [1, 1], 'age' => [1, 2], 'workDays' => [2, 2],
        'isPast' => [1, 1], 'isFuture' => [1, 1], 'daysInMonth' => [1, 1],

        // ── Logique et listes (suite) ────────────────────────────────────
        'ifEmpty' => [2, 2], 'switch' => [3, -1], 'between' => [3, 3],
        'first' => [1, 1], 'last' => [1, 1], 'nth' => [2, 2],
        'join' => [1, 2], 'unique' => [1, -1], 'sortAsc' => [1, -1],
        'median' => [1, -1], 'anyOf' => [2, -1], 'allOf' => [2, -1],
    ];


    /**
     * Aide de chaque fonction : à quoi elle sert, et un exemple qui donne un
     * résultat vérifiable.
     *
     * Elle vit ICI, pas dans la documentation : une description recopiée dans
     * un fichier séparé prend du retard dès la première évolution, et une
     * référence qui décrit mal est pire qu'une référence absente. La
     * documentation et l'aide de l'éditeur sont produites depuis cette table.
     *
     * Format : nom => [description, exemple, résultat attendu]
     */
    public const AIDE = [
        'sum'        => ['Somme des valeurs', 'sum(1, 2, 3)', '6'],
        'avg'        => ['Moyenne', 'avg(10, 20)', '15'],
        'min'        => ['Plus petite valeur', 'min(3, 8)', '3'],
        'max'        => ['Plus grande valeur', 'max(3, 8)', '8'],
        'count'      => ['Nombre de valeurs', 'count(1, 2, 3)', '3'],
        'length'     => ['Longueur d\'un texte', 'length("vanne")', '5'],
        'contains'   => ['Contient ce fragment', 'contains("Vanne DN50", "DN")', 'vrai'],
        'startsWith' => ['Commence par', 'startsWith("EQ-01", "EQ")', 'vrai'],
        'endsWith'   => ['Finit par', 'endsWith("photo.pdf", ".pdf")', 'vrai'],
        'lower'      => ['Tout en minuscules', 'lower("VANNE")', 'vanne'],
        'upper'      => ['Tout en majuscules', 'upper("vanne")', 'VANNE'],
        'trim'       => ['Retire les espaces de bord', 'trim("  vanne  ")', 'vanne'],
        'replace'    => ['Remplace un fragment', 'replace("A-1", "-", "/")', 'A/1'],
        'concat'     => ['Colle plusieurs valeurs', 'concat("EQ", "-", 7)', 'EQ-7'],
        'substring'  => ['Extrait une portion', 'substring("Chaufferie", 0, 5)', 'Chauf'],
        'padStart'   => ['Complète à gauche', 'padStart("7", 3, "0")', '007'],
        'padEnd'     => ['Complète à droite', 'padEnd("A", 3, ".")', 'A..'],
        'capitalize' => ['Première lettre en majuscule', 'capitalize("vanne")', 'Vanne'],
        'title'      => ['Chaque mot en majuscule', 'title("porte nord")', 'Porte Nord'],
        'initials'   => ['Initiales', 'initials("Jean Dupont")', 'JD'],
        'slug'       => ['Texte simplifié pour un identifiant', 'slug("Éclairage n°2")', 'eclairage-n-2'],
        'repeat'     => ['Répète un texte', 'repeat("ab", 3)', 'ababab'],
        'reverse'    => ['Inverse un texte', 'reverse("abc")', 'cba'],
        'indexOf'    => ['Position d\'un fragment, -1 si absent', 'indexOf("EQ-01", "-")', '2'],
        'split'      => ['n-ième morceau, ou leur nombre', 'split("a,b,c", ",", 1)', 'b'],
        'lines'      => ['Nombre de lignes', 'lines(record.commentaire)', '3'],
        'wordCount'  => ['Nombre de mots', 'wordCount("porte du nord")', '3'],
        'stripAccents' => ['Retire les accents', 'stripAccents("Éclairé")', 'Eclaire'],
        'mask'       => ['Masque le milieu', 'mask("FR7612345678", 4, 2)', 'FR76••••••78'],
        'round'      => ['Arrondit', 'round(12.347, 2)', '12.35'],
        'floor'      => ['Arrondit vers le bas', 'floor(12.9)', '12'],
        'ceil'       => ['Arrondit vers le haut', 'ceil(12.1)', '13'],
        'abs'        => ['Valeur absolue', 'abs(-4)', '4'],
        'pow'        => ['Puissance', 'pow(2, 10)', '1024'],
        'sqrt'       => ['Racine carrée', 'sqrt(144)', '12'],
        'mod'        => ['Reste de division', 'mod(10, 3)', '1'],
        'clamp'      => ['Ramène dans un intervalle', 'clamp(150, 0, 100)', '100'],
        'percent'    => ['Part en pourcentage', 'percent(3, 4)', '75'],
        'ratio'      => ['Quotient', 'ratio(10, 4)', '2.5'],
        'sign'       => ['Signe : -1, 0 ou 1', 'sign(-4)', '-1'],
        'roundTo'    => ['Arrondit à un pas', 'roundTo(127, 25)', '125'],
        'toNumber'   => ['Convertit en nombre, virgule acceptée', 'toNumber("12,5")', '12.5'],
        'euros'      => ['Montant en euros', 'euros(1234.5)', '1 234,50 €'],
        'now'        => ['Date et heure courantes', 'now()', '2026-08-23T…'],
        'today'      => ['Date du jour', 'today()', 'la date du jour…'],
        'date'       => ['Convertit en date', 'date(record.saisie)', '2026-08-23'],
        'formatDate' => ['Met en forme : court, long, jour, mois, annee, iso, heure',
                         'formatDate(record.echeance, "court")', '01/09/2026'],
        'addDays'    => ['Décale de N jours', 'addDays("2026-01-01", 30)', '2026-01-31'],
        'addMonths'  => ['Décale de N mois', 'addMonths("2026-01-15", 12)', '2027-01-15'],
        'addYears'   => ['Décale de N années', 'addYears("2026-01-15", 2)', '2028-01-15'],
        'addHours'   => ['Décale de N heures', 'addHours("2026-01-01 08:00", 5)', '2026-01-01 13:00'],
        'addMinutes' => ['Décale de N minutes', 'addMinutes("2026-01-01 08:00", 90)', '2026-01-01 09:30'],
        'diffDays'   => ['Écart en jours', 'diffDays("2026-03-01", "2026-01-01")', '59'],
        'diffMonths' => ['Écart en mois', 'diffMonths("2026-03-01", "2026-01-01")', '2'],
        'diffYears'  => ['Écart en années', 'diffYears("2026-01-01", "2020-01-01")', '6'],
        'diffHours'  => ['Écart en heures', 'diffHours("2026-01-01 12:00", "2026-01-01 08:00")', '4'],
        'diffMinutes'=> ['Écart en minutes', 'diffMinutes("2026-01-01 09:30", "2026-01-01 08:00")', '90'],
        'year'       => ['Année', 'year("2026-08-23")', '2026'],
        'month'      => ['Mois, 1 à 12', 'month("2026-08-23")', '8'],
        'day'        => ['Jour du mois', 'day("2026-08-23")', '23'],
        'weekNumber' => ['Numéro de semaine', 'weekNumber("2026-08-23")', '34'],
        'quarter'    => ['Trimestre, 1 à 4', 'quarter("2026-08-23")', '3'],
        'daysInMonth'=> ['Nombre de jours du mois', 'daysInMonth("2026-02-10")', '28'],
        'dayName'    => ['Nom du jour, en français', 'dayName("2026-08-23")', 'dimanche'],
        'monthName'  => ['Nom du mois, en français', 'monthName("2026-08-23")', 'août'],
        'isWeekend'  => ['Samedi ou dimanche', 'isWeekend("2026-08-23")', 'vrai'],
        'isPast'     => ['Date déjà passée', 'isPast("2020-01-01")', 'vrai'],
        'isFuture'   => ['Date à venir', 'isFuture("2099-01-01")', 'vrai'],
        'startOfMonth' => ['Premier jour du mois', 'startOfMonth("2026-08-23")', '2026-08-01'],
        'endOfMonth' => ['Dernier jour du mois', 'endOfMonth("2026-08-23")', '2026-08-31'],
        'startOfYear'=> ['Premier jour de l\'année', 'startOfYear("2026-08-23")', '2026-01-01'],
        'endOfYear'  => ['Dernier jour de l\'année', 'endOfYear("2026-08-23")', '2026-12-31'],
        'age'        => ['Âge en années révolues', 'age("1990-06-01")', '36'],
        'workDays'   => ['Jours ouvrés, jours fériés non déduits',
                         'workDays("2026-08-24", "2026-08-28")', '5'],
        'if'         => ['Choisit entre deux valeurs', 'if(record.q < 5, "Bas", "OK")', 'Bas'],
        'not'        => ['Inverse un booléen', 'not(record.rendue)', 'vrai'],
        'isEmpty'    => ['Vide, nul ou zéro', 'isEmpty(record.detenteur)', 'vrai'],
        'isNull'     => ['Strictement nul', 'isNull(record.retour)', 'vrai'],
        'coalesce'   => ['Première valeur non vide', 'coalesce(record.surnom, record.nom)', 'Dupont'],
        'ifEmpty'    => ['Remplace si vide', 'ifEmpty(record.detenteur, "—")', '—'],
        'switch'     => ['Choix multiple avec défaut',
                         'switch(record.n, 1, "un", 7, "sept", "autre")', 'sept'],
        'between'    => ['Compris entre deux bornes', 'between(7, 5, 10)', 'vrai'],
        'first'      => ['Première valeur', 'first(record.tags)', 'Urgent'],
        'last'       => ['Dernière valeur', 'last(record.tags)', 'Externe'],
        'nth'        => ['n-ième valeur', 'nth(record.tags, 1)', 'Externe'],
        'join'       => ['Assemble avec un séparateur', 'join(record.tags, " / ")', 'Urgent / Externe'],
        'unique'     => ['Nombre de valeurs distinctes', 'unique(1, 1, 2)', '2'],
        'sortAsc'    => ['Trie par ordre croissant', 'sortAsc(3, 1, 2)', '1, 2, 3'],
        'median'     => ['Valeur médiane', 'median(1, 3, 100)', '3'],
        'anyOf'      => ['Égale l\'une des valeurs', 'anyOf(record.statut, "A", "B")', 'vrai'],
        'allOf'      => ['Égale toutes les valeurs données', 'allOf(record.statut, "A", "A")', 'vrai'],
    ];

    private array $lexemes = [];
    private int $position  = 0;
    private int $noeuds    = 0;

    // ═══════════════════════════════════════════════════════════════════════
    //  API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Compile une expression en arbre. À faire une fois, à la validation du
     * pack : l'arbre est ensuite réutilisé à chaque ligne, sans réanalyse.
     *
     * @throws RuntimeException si la syntaxe est invalide ou une borne dépassée
     */
    public static function compiler(string $source): array
    {
        if (mb_strlen($source) > self::LONGUEUR_MAX) {
            throw new RuntimeException('Expression trop longue ('
                . self::LONGUEUR_MAX . ' caractères maximum).');
        }

        $m = new self();
        $m->lexemes = $m->analyser($source);
        $m->position = 0;
        $arbre = $m->expression(0, 0);

        if ($m->position < count($m->lexemes)) {
            $l = $m->lexemes[$m->position];
            throw new RuntimeException("Expression : « {$l['valeur']} » inattendu.");
        }
        return $arbre;
    }

    /** Compile, ou explique pourquoi c'est impossible. */
    public static function valider(string $source): array
    {
        try {
            return ['ok' => true, 'arbre' => self::compiler($source)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'erreur' => $e->getMessage()];
        }
    }

    /**
     * Évalue un arbre compilé.
     *
     * @param array $contexte  ['record' => [...], 'user' => [...], 'variables' => [...]]
     */
    public static function evaluer(array $arbre, array $contexte): mixed
    {
        return (new self())->noeud($arbre, $contexte, 0);
    }

    /**
     * Remplace les {{…}} d'un gabarit par leur valeur.
     * Le texte hors accolades n'est jamais interprété.
     */
    public static function interpoler(string $gabarit, array $contexte): string
    {
        $out = '';
        $reste = $gabarit;

        while (($i = strpos($reste, '{{')) !== false) {
            $j = strpos($reste, '}}', $i);
            if ($j === false) break;

            $out .= substr($reste, 0, $i);
            $expr = trim(substr($reste, $i + 2, $j - $i - 2));

            try {
                $v = self::evaluer(self::compiler($expr), $contexte);
                $out .= is_scalar($v) ? (string)$v : ($v === null ? '' : json_encode($v));
            } catch (\Throwable $e) {
                // Un gabarit fautif n'interrompt pas l'affichage : il montre où
                // il échoue. Interrompre laisserait une page blanche, ce qui ne
                // renseigne personne.
                $out .= '⟨' . $e->getMessage() . '⟩';
            }
            $reste = substr($reste, $j + 2);
        }
        return $out . $reste;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Analyse lexicale
    // ═══════════════════════════════════════════════════════════════════════

    private function analyser(string $s): array
    {
        $lexemes = [];
        $n = strlen($s);
        $i = 0;

        // Les plus longs d'abord : « <= » doit gagner sur « < ».
        $OPERATEURS = ['&&', '||', '==', '!=', '>=', '<=', '<>',
                       '+', '-', '*', '/', '%', '>', '<', '!'];

        while ($i < $n) {
            $c = $s[$i];

            if (ctype_space($c)) { $i++; continue; }

            // Chaîne, simple ou double quote, avec échappement par \
            if ($c === "'" || $c === '"') {
                $fin = $c; $i++; $val = '';
                while ($i < $n && $s[$i] !== $fin) {
                    if ($s[$i] === '\\' && $i + 1 < $n) { $val .= $s[++$i]; }
                    else { $val .= $s[$i]; }
                    $i++;
                }
                if ($i >= $n) throw new RuntimeException('Expression : chaîne non fermée.');
                $i++;
                $lexemes[] = ['type' => 'texte', 'valeur' => $val];
                continue;
            }

            // Nombre
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                $val = '';
                while ($i < $n && (ctype_digit($s[$i]) || $s[$i] === '.')) $val .= $s[$i++];
                $lexemes[] = ['type' => 'nombre', 'valeur' => $val];
                continue;
            }

            // Identifiant, éventuellement pointé : record.statut
            if (ctype_alpha($c) || $c === '_') {
                $val = '';
                while ($i < $n && (ctype_alnum($s[$i]) || $s[$i] === '_' || $s[$i] === '.')) {
                    $val .= $s[$i++];
                }
                $lexemes[] = ['type' => 'nom', 'valeur' => $val];
                continue;
            }

            if ($c === '(' || $c === ')' || $c === ',') {
                $lexemes[] = ['type' => $c, 'valeur' => $c];
                $i++;
                continue;
            }

            $trouve = false;
            foreach ($OPERATEURS as $op) {
                if (substr($s, $i, strlen($op)) === $op) {
                    $lexemes[] = ['type' => 'op', 'valeur' => $op];
                    $i += strlen($op);
                    $trouve = true;
                    break;
                }
            }
            if (!$trouve) {
                throw new RuntimeException("Expression : caractère « $c » non autorisé.");
            }
        }
        return $lexemes;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Analyse syntaxique (précédence grimpante)
    // ═══════════════════════════════════════════════════════════════════════

    private const PRECEDENCE = [
        '||' => 1, '&&' => 2,
        '==' => 3, '!=' => 3, '<>' => 3, '>' => 3, '<' => 3, '>=' => 3, '<=' => 3,
        '+' => 4, '-' => 4,
        '*' => 5, '/' => 5, '%' => 5,
    ];

    private function compter(): void
    {
        if (++$this->noeuds > self::NOEUDS_MAX) {
            throw new RuntimeException('Expression trop complexe ('
                . self::NOEUDS_MAX . ' éléments maximum).');
        }
    }

    private function expression(int $minPrecedence, int $profondeur): array
    {
        if ($profondeur > self::PROFONDEUR_MAX) {
            throw new RuntimeException('Expression trop imbriquée ('
                . self::PROFONDEUR_MAX . ' niveaux maximum).');
        }

        $gauche = $this->terme($profondeur);

        while ($this->position < count($this->lexemes)) {
            $l = $this->lexemes[$this->position];
            if ($l['type'] !== 'op') break;
            $p = self::PRECEDENCE[$l['valeur']] ?? null;
            if ($p === null || $p < $minPrecedence) break;

            $this->position++;
            $droite = $this->expression($p + 1, $profondeur + 1);
            $this->compter();
            $gauche = ['t' => 'binaire', 'op' => $l['valeur'], 'g' => $gauche, 'd' => $droite];
        }
        return $gauche;
    }

    private function terme(int $profondeur): array
    {
        if ($this->position >= count($this->lexemes)) {
            throw new RuntimeException('Expression incomplète.');
        }
        $l = $this->lexemes[$this->position++];
        $this->compter();

        // Unaires
        if ($l['type'] === 'op' && in_array($l['valeur'], ['-', '!'], true)) {
            return ['t' => 'unaire', 'op' => $l['valeur'],
                    'v' => $this->terme($profondeur + 1)];
        }

        if ($l['type'] === 'nombre') {
            return ['t' => 'litteral',
                    'v' => str_contains($l['valeur'], '.')
                           ? (float)$l['valeur'] : (int)$l['valeur']];
        }
        if ($l['type'] === 'texte') {
            return ['t' => 'litteral', 'v' => $l['valeur']];
        }

        if ($l['type'] === '(') {
            $e = $this->expression(0, $profondeur + 1);
            if (($this->lexemes[$this->position]['type'] ?? '') !== ')') {
                throw new RuntimeException('Expression : parenthèse non fermée.');
            }
            $this->position++;
            return $e;
        }

        if ($l['type'] === 'nom') {
            $nom = $l['valeur'];

            // Appel de fonction
            if (($this->lexemes[$this->position]['type'] ?? '') === '(') {
                $this->position++;
                $args = [];
                if (($this->lexemes[$this->position]['type'] ?? '') !== ')') {
                    while (true) {
                        $args[] = $this->expression(0, $profondeur + 1);
                        $suite = $this->lexemes[$this->position]['type'] ?? '';
                        if ($suite === ',') { $this->position++; continue; }
                        break;
                    }
                }
                if (($this->lexemes[$this->position]['type'] ?? '') !== ')') {
                    throw new RuntimeException("Expression : parenthèse non fermée après « $nom ».");
                }
                $this->position++;

                // ── LE contrôle qui rend le moteur sûr ────────────────────
                // Une fonction inconnue est refusée ICI, à la compilation, donc
                // à l'installation du pack. Elle n'est jamais cherchée dans PHP.
                if (!isset(self::FONCTIONS[$nom])) {
                    $proche = self::plusProche($nom);
                    throw new RuntimeException("Fonction « $nom » inconnue."
                        . ($proche ? " Vouliez-vous dire « $proche » ?" : '')
                        . ' Fonctions : ' . implode(', ', array_keys(self::FONCTIONS)));
                }
                [$mini, $maxi] = self::FONCTIONS[$nom];
                $c = count($args);
                if ($c < $mini || ($maxi >= 0 && $c > $maxi)) {
                    throw new RuntimeException("Fonction « $nom » : $c argument(s) fourni(s), "
                        . ($mini === $maxi ? "$mini attendu(s)" : "entre $mini et "
                          . ($maxi < 0 ? 'plusieurs' : $maxi)) . '.');
                }
                return ['t' => 'appel', 'nom' => $nom, 'args' => $args];
            }

            // Constantes littérales
            $bas = strtolower($nom);
            if ($bas === 'true')  return ['t' => 'litteral', 'v' => true];
            if ($bas === 'false') return ['t' => 'litteral', 'v' => false];
            if ($bas === 'null')  return ['t' => 'litteral', 'v' => null];

            return ['t' => 'reference', 'chemin' => explode('.', $nom)];
        }

        throw new RuntimeException("Expression : « {$l['valeur']} » inattendu.");
    }

    private static function plusProche(string $nom): ?string
    {
        $meilleur = null; $d = PHP_INT_MAX;
        foreach (array_keys(self::FONCTIONS) as $f) {
            $x = levenshtein(strtolower($nom), strtolower($f));
            if ($x < $d) { $d = $x; $meilleur = $f; }
        }
        return $d <= 3 ? $meilleur : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Évaluation
    // ═══════════════════════════════════════════════════════════════════════

    private function noeud(array $n, array $ctx, int $profondeur): mixed
    {
        if ($profondeur > self::PROFONDEUR_MAX) return null;

        return match ($n['t']) {
            'litteral'  => $n['v'],
            'reference' => $this->resoudre($n['chemin'], $ctx),
            'unaire'    => $this->unaire($n, $ctx, $profondeur),
            'binaire'   => $this->binaire($n, $ctx, $profondeur),
            'appel'     => $this->appliquer($n['nom'],
                               array_map(fn($a) => $this->noeud($a, $ctx, $profondeur + 1),
                                         $n['args'])),
            default     => null,
        };
    }

    /**
     * Résout record.statut, user.nom, variables.delai…
     * Traversée d'un tableau, jamais d'un objet : une référence ne peut pas
     * atteindre une méthode ni une propriété d'objet du cœur.
     */
    private function resoudre(array $chemin, array $ctx): mixed
    {
        $courant = $ctx;
        foreach ($chemin as $morceau) {
            if (!is_array($courant) || !array_key_exists($morceau, $courant)) return null;
            $courant = $courant[$morceau];
        }
        return is_object($courant) ? null : $courant;
    }

    private function unaire(array $n, array $ctx, int $p): mixed
    {
        $v = $this->noeud($n['v'], $ctx, $p + 1);
        return $n['op'] === '-' ? -(float)$v : !self::vrai($v);
    }

    private function binaire(array $n, array $ctx, int $p): mixed
    {
        // Court-circuit : && et || n'évaluent pas leur droite inutilement.
        if ($n['op'] === '&&') {
            return self::vrai($this->noeud($n['g'], $ctx, $p + 1))
                && self::vrai($this->noeud($n['d'], $ctx, $p + 1));
        }
        if ($n['op'] === '||') {
            return self::vrai($this->noeud($n['g'], $ctx, $p + 1))
                || self::vrai($this->noeud($n['d'], $ctx, $p + 1));
        }

        $g = $this->noeud($n['g'], $ctx, $p + 1);
        $d = $this->noeud($n['d'], $ctx, $p + 1);

        return match ($n['op']) {
            '+'  => is_string($g) || is_string($d) ? ((string)$g . (string)$d)
                                                   : ((float)$g + (float)$d),
            '-'  => (float)$g - (float)$d,
            '*'  => (float)$g * (float)$d,
            // Division par zéro : null, pas une erreur fatale. Une formule de
            // tableur affiche une case vide, elle n'arrête pas le tableau.
            '/'  => ((float)$d) == 0.0 ? null : (float)$g / (float)$d,
            '%'  => ((int)$d) === 0 ? null : (int)$g % (int)$d,
            '==' => self::egal($g, $d),
            '!=', '<>' => !self::egal($g, $d),
            '>'  => self::comparer($g, $d) > 0,
            '<'  => self::comparer($g, $d) < 0,
            '>=' => self::comparer($g, $d) >= 0,
            '<=' => self::comparer($g, $d) <= 0,
            default => null,
        };
    }

    /** Application d'une fonction : un match fermé, jamais un appel dynamique. */
    private function appliquer(string $nom, array $a): mixed
    {
        // Méthode statique plutôt que fermeture dans une variable : c'est la
        // règle qu'on impose aux auteurs de modules, et ce fichier — le plus
        // sensible du format — n'a aucune raison d'y échapper. Un appel par
        // variable ici déclencherait à juste titre l'invariant de sécurité.
        return match ($nom) {
            'sum'   => array_sum(self::nombres($a)),
            'avg'   => ($n = self::nombres($a)) ? array_sum($n) / count($n) : null,
            'min'   => ($n = self::nombres($a)) ? min($n) : null,
            'max'   => ($n = self::nombres($a)) ? max($n) : null,
            'count' => count(self::aplatir($a)),

            'length'     => is_array($a[0]) ? count($a[0]) : mb_strlen((string)$a[0]),
            'contains'   => is_array($a[0]) ? in_array($a[1], $a[0])
                            : str_contains((string)$a[0], (string)$a[1]),
            'startsWith' => str_starts_with((string)$a[0], (string)$a[1]),
            'endsWith'   => str_ends_with((string)$a[0], (string)$a[1]),
            'lower'      => mb_strtolower((string)$a[0]),
            'upper'      => mb_strtoupper((string)$a[0]),
            'trim'       => trim((string)$a[0]),
            'replace'    => str_replace((string)$a[1], (string)$a[2], (string)$a[0]),
            'concat'     => implode('', array_map(fn($x) => (string)$x, $a)),

            'round' => round((float)$a[0], (int)($a[1] ?? 0)),
            'floor' => floor((float)$a[0]),
            'ceil'  => ceil((float)$a[0]),
            'abs'   => abs((float)$a[0]),

            'now'        => date('c'),
            'today'      => date('Y-m-d'),
            'date'       => self::versDate($a[0]),
            'formatDate' => self::formater($a[0], (string)$a[1]),
            'addDays'    => self::decaler($a[0], (int)$a[1], 'days'),
            'addMonths'  => self::decaler($a[0], (int)$a[1], 'months'),
            'diffDays'   => self::ecartJours($a[0], $a[1]),
            'year'       => (int)date('Y', self::horodatage($a[0])),
            'month'      => (int)date('n', self::horodatage($a[0])),
            'day'        => (int)date('j', self::horodatage($a[0])),

            'isEmpty'  => $a[0] === null || $a[0] === '' || $a[0] === []
                          || $a[0] === 0 || $a[0] === '0',
            'isNull'   => $a[0] === null,
            'not'      => !self::vrai($a[0]),
            'if'       => self::vrai($a[0]) ? $a[1] : $a[2],
            'coalesce' => self::premierNonNul($a),

            // ── Texte ────────────────────────────────────────────────────
            'substring'    => mb_substr((string)$a[0], (int)$a[1],
                                  isset($a[2]) ? (int)$a[2] : null),
            'padStart'     => self::remplir((string)$a[0], (int)$a[1], (string)($a[2] ?? '0'), true),
            'padEnd'       => self::remplir((string)$a[0], (int)$a[1], (string)($a[2] ?? ' '), false),
            'capitalize'   => mb_strtoupper(mb_substr((string)$a[0], 0, 1))
                              . mb_substr((string)$a[0], 1),
            'title'        => mb_convert_case((string)$a[0], MB_CASE_TITLE, 'UTF-8'),
            'initials'     => self::initiales((string)$a[0]),
            'slug'         => self::slug((string)$a[0]),
            'repeat'       => str_repeat((string)$a[0], max(0, min((int)$a[1], 500))),
            'reverse'      => implode('', array_reverse(mb_str_split((string)$a[0]))),
            'indexOf'      => ($p = mb_strpos((string)$a[0], (string)$a[1])) === false ? -1 : $p,
            'split'        => self::morceau((string)$a[0], (string)$a[1], $a[2] ?? null),
            'lines'        => count(preg_split('/\r\n|\r|\n/', (string)$a[0])),
            'wordCount'    => str_word_count((string)$a[0], 0, 'àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ'),
            'stripAccents' => self::sansAccents((string)$a[0]),
            'mask'         => self::masquer((string)$a[0], (int)($a[1] ?? 2), (int)($a[2] ?? 2)),

            // ── Nombres ──────────────────────────────────────────────────
            'pow'      => pow((float)$a[0], (float)$a[1]),
            'sqrt'     => (float)$a[0] < 0 ? null : sqrt((float)$a[0]),
            'mod'      => ((int)$a[1]) === 0 ? null : (int)$a[0] % (int)$a[1],
            'clamp'    => max((float)$a[1], min((float)$a[0], (float)$a[2])),
            'percent'  => ((float)$a[1]) == 0.0 ? null
                          : round((float)$a[0] / (float)$a[1] * 100, (int)($a[2] ?? 1)),
            'ratio'    => ((float)$a[1]) == 0.0 ? null : (float)$a[0] / (float)$a[1],
            'sign'     => (float)$a[0] <=> 0.0,
            'roundTo'  => ((float)$a[1]) == 0.0 ? (float)$a[0]
                          : round((float)$a[0] / (float)$a[1]) * (float)$a[1],
            'toNumber' => is_numeric(str_replace(',', '.', (string)$a[0]))
                          ? (float)str_replace(',', '.', (string)$a[0]) : ($a[1] ?? null),
            'euros'    => number_format((float)$a[0], 2, ',', ' ') . ' €',

            // ── Dates ────────────────────────────────────────────────────
            'addYears'    => self::decaler($a[0], (int)$a[1], 'years'),
            'addHours'    => self::decalerHeure($a[0], (int)$a[1], 3600),
            'addMinutes'  => self::decalerHeure($a[0], (int)$a[1], 60),
            'diffMonths'  => self::ecartMois($a[0], $a[1]),
            'diffYears'   => (int)floor(self::ecartMois($a[0], $a[1]) / 12),
            'diffHours'   => self::ecartUnite($a[0], $a[1], 3600),
            'diffMinutes' => self::ecartUnite($a[0], $a[1], 60),
            'weekNumber'  => (int)date('W', self::horodatage($a[0])),
            'dayName'     => self::JOURS[(int)date('w', self::horodatage($a[0]))] ?? null,
            'monthName'   => self::MOIS[(int)date('n', self::horodatage($a[0]))] ?? null,
            'quarter'     => (int)ceil((int)date('n', self::horodatage($a[0])) / 3),
            'isWeekend'   => in_array((int)date('w', self::horodatage($a[0])), [0, 6], true),
            'startOfMonth'=> date('Y-m-01', self::horodatage($a[0])),
            'endOfMonth'  => date('Y-m-t', self::horodatage($a[0])),
            'startOfYear' => date('Y-01-01', self::horodatage($a[0])),
            'endOfYear'   => date('Y-12-31', self::horodatage($a[0])),
            'daysInMonth' => (int)date('t', self::horodatage($a[0])),
            'age'         => self::ageEnAnnees($a[0], $a[1] ?? null),
            'workDays'    => self::joursOuvres($a[0], $a[1]),
            'isPast'      => self::horodatage($a[0]) > 0 && self::horodatage($a[0]) < time(),
            'isFuture'    => self::horodatage($a[0]) > time(),

            // ── Logique et listes ────────────────────────────────────────
            'ifEmpty'  => (self::estVide($a[0]) ? $a[1] : $a[0]),
            'switch'   => self::choisir($a),
            'between'  => self::comparer($a[0], $a[1]) >= 0 && self::comparer($a[0], $a[2]) <= 0,
            'first'    => ($x = self::aplatir([$a[0]])) ? $x[0] : null,
            'last'     => ($x = self::aplatir([$a[0]])) ? end($x) : null,
            'nth'      => (self::aplatir([$a[0]])[(int)$a[1]] ?? null),
            'join'     => implode((string)($a[1] ?? ', '), self::aplatir([$a[0]])),
            'unique'   => count(array_unique(self::aplatir($a))),
            'sortAsc'  => self::trier($a),
            'median'   => self::mediane(self::nombres($a)),
            'anyOf'    => in_array($a[0], array_slice(self::aplatir($a), 1)),
            'allOf'    => count(array_unique(array_slice(self::aplatir($a), 1))) === 1
                          && in_array($a[0], array_slice(self::aplatir($a), 1)),

            default => null,   // inatteignable : filtré à la compilation
        };
    }

    // ── Aides ───────────────────────────────────────────────────────────────

    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi',
                           'vendredi', 'samedi'];
    private const MOIS  = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                           'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private static function remplir(string $v, int $n, string $c, bool $gauche): string
    {
        $n = max(0, min($n, 200));
        if ($c === '') $c = ' ';
        while (mb_strlen($v) < $n) $v = $gauche ? $c . $v : $v . $c;
        return $v;
    }

    private static function initiales(string $v): string
    {
        $out = '';
        foreach (preg_split('/[\s\-]+/u', trim($v)) as $mot) {
            if ($mot !== '') $out .= mb_strtoupper(mb_substr($mot, 0, 1));
        }
        return mb_substr($out, 0, 6);
    }

    private static function sansAccents(string $v): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        return $t === false ? $v : preg_replace('/[^\x20-\x7E]/', '', $t);
    }

    private static function slug(string $v): string
    {
        $v = strtolower(self::sansAccents($v));
        return trim(preg_replace('/[^a-z0-9]+/', '-', $v), '-');
    }

    /** Masque le milieu d'une valeur : « AB••••YZ ». */
    private static function masquer(string $v, int $debut, int $fin): string
    {
        $n = mb_strlen($v);
        $debut = max(0, min($debut, $n));
        $fin   = max(0, min($fin, $n - $debut));
        $cache = $n - $debut - $fin;
        return $cache <= 0 ? $v
             : mb_substr($v, 0, $debut) . str_repeat('•', $cache) . mb_substr($v, $n - $fin);
    }

    private static function morceau(string $v, string $sep, mixed $i): mixed
    {
        if ($sep === '') return $v;
        $parts = explode($sep, $v);
        return $i === null ? count($parts) : ($parts[(int)$i] ?? null);
    }

    private static function decalerHeure(mixed $v, int $n, int $unite): ?string
    {
        $t = self::horodatage($v);
        return $t ? date('Y-m-d H:i', $t + $n * $unite) : null;
    }

    private static function ecartUnite(mixed $a, mixed $b, int $unite): ?int
    {
        $x = self::horodatage($a); $y = self::horodatage($b);
        return ($x && $y) ? (int)floor(($x - $y) / $unite) : null;
    }

    private static function ecartMois(mixed $a, mixed $b): ?int
    {
        $x = self::horodatage($a); $y = self::horodatage($b);
        if (!$x || !$y) return null;
        return ((int)date('Y', $x) - (int)date('Y', $y)) * 12
             + ((int)date('n', $x) - (int)date('n', $y));
    }

    private static function ageEnAnnees(mixed $naissance, mixed $ref): ?int
    {
        $n = self::horodatage($naissance);
        if (!$n) return null;
        $r = $ref === null ? time() : self::horodatage($ref);
        $ans = (int)date('Y', $r) - (int)date('Y', $n);
        if ((int)date('md', $r) < (int)date('md', $n)) $ans--;
        return $ans;
    }

    /** Jours ouvrés entre deux dates, week-ends exclus. Jours fériés non gérés. */
    private static function joursOuvres(mixed $a, mixed $b): ?int
    {
        $x = self::horodatage($a); $y = self::horodatage($b);
        if (!$x || !$y) return null;
        if ($x > $y) { [$x, $y] = [$y, $x]; }
        // Borne de sûreté : une expression ne doit pas boucler dix ans.
        if (($y - $x) / 86400 > 3660) return null;
        $n = 0;
        for ($t = $x; $t <= $y; $t += 86400) {
            if (!in_array((int)date('w', $t), [0, 6], true)) $n++;
        }
        return $n;
    }

    private static function estVide(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [] || $v === 0 || $v === '0';
    }

    /** switch(valeur, cas1, résultat1, cas2, résultat2, …, défaut) */
    private static function choisir(array $a): mixed
    {
        $valeur = $a[0];
        $n = count($a);
        for ($i = 1; $i + 1 < $n; $i += 2) {
            if (self::egal($valeur, $a[$i])) return $a[$i + 1];
        }
        // Nombre pair d'arguments après la valeur : le dernier est le défaut.
        return (($n - 1) % 2 === 1) ? $a[$n - 1] : null;
    }

    private static function trier(array $a): string
    {
        $x = self::aplatir($a);
        sort($x);
        return implode(', ', array_map('strval', $x));
    }

    private static function mediane(array $n): ?float
    {
        if ($n === []) return null;
        sort($n);
        $c = count($n);
        return $c % 2 ? $n[intdiv($c, 2)] : ($n[$c / 2 - 1] + $n[$c / 2]) / 2;
    }

    /** Les valeurs numériques d'une liste, aplaties. */
    private static function nombres(array $x): array
    {
        return array_map('floatval',
            array_filter(self::aplatir($x), fn($v) => is_numeric($v)));
    }

    private static function aplatir(array $a): array
    {
        $out = [];
        array_walk_recursive($a, function ($v) use (&$out) { $out[] = $v; });
        return $out;
    }

    private static function premierNonNul(array $a): mixed
    {
        foreach ($a as $v) if ($v !== null && $v !== '') return $v;
        return null;
    }

    private static function vrai(mixed $v): bool
    {
        if (is_string($v)) return $v !== '' && $v !== '0' && strtolower($v) !== 'false';
        return (bool)$v;
    }

    /** Égalité souple : « 5 » égale 5, mais null n'égale pas 0. */
    private static function egal(mixed $g, mixed $d): bool
    {
        if ($g === null || $d === null) return $g === $d;
        if (is_numeric($g) && is_numeric($d)) return (float)$g == (float)$d;
        if (is_bool($g) || is_bool($d)) return self::vrai($g) === self::vrai($d);
        return (string)$g === (string)$d;
    }

    private static function comparer(mixed $g, mixed $d): int
    {
        if (is_numeric($g) && is_numeric($d)) return (float)$g <=> (float)$d;
        return strcmp((string)$g, (string)$d);
    }

    private static function horodatage(mixed $v): int
    {
        if (is_numeric($v)) return (int)$v;
        $t = strtotime((string)$v);
        return $t === false ? 0 : $t;
    }

    private static function versDate(mixed $v): ?string
    {
        $t = self::horodatage($v);
        return $t ? date('Y-m-d', $t) : null;
    }

    /**
     * Formats symboliques uniquement.
     * On ne transmet pas le motif à date() : « U » ou un motif exotique
     * laisserait l'auteur du pack piloter une fonction du cœur.
     */
    private static function formater(mixed $v, string $format): ?string
    {
        $t = self::horodatage($v);
        if (!$t) return null;
        return match ($format) {
            'court'    => date('d/m/Y', $t),
            'long'     => date('d/m/Y H:i', $t),
            'jour'     => date('d/m', $t),
            'mois'     => date('m/Y', $t),
            'annee'    => date('Y', $t),
            'iso'      => date('Y-m-d', $t),
            'heure'    => date('H:i', $t),
            default    => date('d/m/Y', $t),
        };
    }

    private static function decaler(mixed $v, int $n, string $unite): ?string
    {
        $t = self::horodatage($v);
        if (!$t) return null;
        $signe = $n >= 0 ? '+' : '-';
        return date('Y-m-d', strtotime("$signe" . abs($n) . " $unite", $t));
    }

    private static function ecartJours(mixed $a, mixed $b): ?int
    {
        $x = self::horodatage($a); $y = self::horodatage($b);
        if (!$x || !$y) return null;
        return (int)floor(($x - $y) / 86400);
    }
}
