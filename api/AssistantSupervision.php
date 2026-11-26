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
 * Larka — Assistant : la supervision (Larka Connect)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * L'assistant répond désormais sur ce que mesurent les équipements raccordés :
 *
 *   « combien a consommé l'Hôtel de Ville en octobre ? »         → conso
 *   « total de conso d'électricité du 1er janvier au 31 mars »   → conso
 *   « et pour le gaz ? »  (suite de la question précédente)      → conso
 *   « combien d'alarmes ce mois-ci ? », « alarmes critiques »    → alarmes
 *   « est-ce que la GTB fonctionne ? », « capteurs en défaut »   → etat
 *   « quelle est la température du bureau R+1 ? »                → valeur
 *
 * Même principe que le reste du moteur : la question est comprise ici sans
 * modèle (même question → même réponse), les chiffres viennent de la base
 * (ConnectLecture), la phrase est un gabarit. Aucun chiffre n'est inventé :
 * un compteur sans historique sur la période le dit ; une consommation
 * calculée sur un historique commencé en route est marquée « partielle ».
 *
 * OÙ SONT LES MESURES ? Sur ce serveur, ou sur le serveur de supervision
 * (SupervisionLien) : la demande part alors par le lien chiffré et la réponse
 * revient identique. Mêmes droits que l'écran Supervision : Gestionnaire,
 * Visionneur (et Admin).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

final class AssistantSupervision
{
    public const ROLES = ['Admin', 'Gestionnaire', 'Visionneur'];

    private const ENERGIES = [
        'Electricite' => ['Électricité', '/\b(?:electricite|electrique\w*|elec|kwh|mwh|courant)\b/'],
        'Gaz'         => ['Gaz', '/\bgaz\b/'],
        'Eau'         => ['Eau', '/\b(?:eau|m3|metres? cubes?)\b/'],
        'Chauffage'   => ['Chauffage', '/\b(?:chauffage|chaleur|calorie\w*|reseau de chaleur|frigorie\w*)\b/'],
    ];
    private const MOIS = ['janvier' => 1, 'janv' => 1, 'fevrier' => 2, 'fevr' => 2, 'fev' => 2, 'mars' => 3, 'avril' => 4, 'avr' => 4,
                          'mai' => 5, 'juin' => 6, 'juillet' => 7, 'juil' => 7, 'aout' => 8, 'septembre' => 9, 'sept' => 9,
                          'octobre' => 10, 'oct' => 10, 'novembre' => 11, 'nov' => 11, 'decembre' => 12, 'dec' => 12];
    private const NOMS_MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    // ═══════════════════════════════════════════════════════════════════════
    //  Disponibilité et lecture (ici ou sur le serveur de supervision)
    // ═══════════════════════════════════════════════════════════════════════

    /** La supervision est-elle lisible par cet utilisateur, dans cette organisation ? */
    public static function disponible(Database $db, array $user): bool
    {
        if (!in_array((string)($user['Role'] ?? ''), self::ROLES, true)) return false;
        try {
            require_once __DIR__ . '/connect/Connect.php';
            Connect::charger();
            if (class_exists('SupervisionLien') && SupervisionLien::distante($db)) return SupervisionLien::moduleOuvert($db);
            return Connect::actifServeur() && Connect::actifPour($db);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Une lecture de synthèse : sur la base locale, ou par le lien chiffré. */
    public static function lire(Database $db, string $quoi, array $args): array
    {
        require_once __DIR__ . '/connect/Connect.php';
        Connect::charger();
        if (class_exists('SupervisionLien') && SupervisionLien::distante($db)) {
            $r = SupervisionLien::requete($db, 'lecture', ['quoi' => $quoi, 'args' => $args]);
            return is_array($r) ? $r : ['erreur' => 'Réponse illisible du serveur de supervision.'];
        }
        return ConnectLecture::executer($db, $quoi, $args);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Compréhension (appelée par AssistantComprehension, sans modèle)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Ce que la question demande à la supervision, ou null si elle n'en parle pas.
     * $t0 : question préparée (minuscules, sans accents ni ponctuation).
     */
    public static function detecter(string $t0): ?string
    {
        $t = ' ' . $t0 . ' ';
        $has = static fn(string $re): bool => (bool)preg_match($re, $t);
        // « où est la sonde de température ? » : un lieu, pas une valeur.
        $lieu = $has('/^ (?:et )?ou\b|\bou (?:est|sont|se trouve)/');
        if ($has('/\b(?:consomm\w*|conso|consos|kwh|mwh|metres? cubes?|energie consommee|depense\w* (?:d )?energ\w*)\b/')) return 'conso';
        // « combien d'électricité le mois dernier ? », « on a dépensé combien d'eau à l'école ? »
        $energie = '(?:electricite|eau|gaz|chaleur|fioul)';
        if ($has('/\b(?:combien|quantite|volume) (?:d |de |du )' . $energie . '\b/')
            || ($has('/\b(?:depense\w*|utilise\w*|brule\w*)\b/') && $has('/\b' . $energie . '\b/') && $has('/\bcombien\b/'))) return 'conso';
        if ($has('/\balarm\w*\b/') || $has('/\balertes? (?:techniques?|de (?:la )?supervision|gtb|gtc|des capteurs|des equipements raccordes)\b/')) return 'alarmes';
        // « combien d'interventions sur les capteurs en panne ? » porte sur des fiches de la
        // GMAO, pas sur l'état mesuré : un type de fiche cité écarte l'état et les valeurs.
        if ($has('/\b(?:interventions?|demandes?|contrats?|devis|commandes?|fiches?|stocks?|archives?|documents?)\b/')) return null;
        if ($has('/\b(?:supervision|gtb|gtc)\b/')
            || $has('/\b(?:connexions?|capteurs?|automates?|sondes?|equipements? raccordes?|compteurs? raccordes?)\b.*\b(?:defaut|hs|repond\w*|hors ligne|injoignable\w*|panne\w*|en ligne|fonctionn\w*|marche\w*|muet\w*)\b/')
            // « est-ce qu'il y a un problème sur les compteurs ? » — en tête de question
            // seulement : « interventions pour panne de compteur » reste une question de GMAO.
            || $has('/^ (?:est ce qu\w* )?(?:il )?(?:y a t il |y a )?(?:un |une |des )?(?:probleme|souci|panne)s? (?:sur|avec|aux|au|des|de|du|d)\b.*\b(?:capteurs?|automates?|sondes?|compteurs?|equipements? raccordes?)\b/')) {
            return 'etat';
        }
        $grandeur = '(?:temperature|humidite|co2|pression|puissance|debit|consigne|hygrometrie|index)';
        if (!$lieu && $has('/\b' . $grandeur . '\b/')
            && $has('/\b(?:quel\w*|combien|valeur|fait il|actuel\w*|en ce moment|maintenant|mesure\w*|niveau|degres?)\b/')) {
            return 'valeur';
        }
        // « il fait combien dans le bureau R+1 ? », « combien de degrés salle 12 ? »
        if (!$lieu && $has('/\b(?:il fait combien|fait il combien|combien de degres|combien fait il)\b/')) return 'valeur';
        // Groupe nominal court : « température extérieure », « taux d'humidité salle du conseil ».
        // Grandeurs sans autre sens en GMAO seulement : « consigne de sécurité », « puissance
        // du groupe électrogène » restent des recherches de fiches ou de documents.
        if (!$lieu && preg_match('/^(?:(?:le|la|l|les) )?(?:(?:taux|niveau) (?:d |de |du )?)?(?:temperature|humidite|hygrometrie|co2)\b/', $t0)
            && count(explode(' ', trim($t0))) <= 6) {
            return 'valeur';
        }
        // « est-ce que la sous-station est en marche ? » : l'état d'un point tout-ou-rien.
        if ($has('/^ est ce qu\w* /') && $has('/\b(?:tourne\w*|en marche|allume\w*|arrete\w*|eteint\w*)\b/')) return 'valeur';
        return null;
    }

    /**
     * Paramètres lus dans la question : énergie, période, gravité, « actives ».
     * $filtres : ceux déjà extraits par AssistantComprehension (jour, mois, annee, semaine).
     */
    public static function parametres(string $t0, array $filtres): array
    {
        $t = ' ' . $t0 . ' ';
        $p = ['energie' => null, 'periode' => null, 'gravite' => null, 'actives' => null];
        foreach (self::ENERGIES as $k => [, $re]) {
            if (preg_match($re, $t)) { $p['energie'] = $k; break; }
        }
        if (preg_match('/\b(critique|majeure|mineure)s?\b/', $t, $m)) $p['gravite'] = $m[1];
        if (preg_match('/\b(?:actives?|en cours|ouvertes?|non acquittee?s?|pas acquittee?s?)\b/', $t)) $p['actives'] = true;
        $p['periode'] = self::periode($t, $filtres);
        return $p;
    }

    /** [debut, fin, libellé] d'une période dite dans la question, ou null. */
    public static function periode(string $t, array $f): ?array
    {
        $auj = date('Y-m-d');
        $an = (int)date('Y');
        $mois = implode('|', array_keys(self::MOIS));
        $tok = '(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?|(?:1er|premier|\d{1,2}) (?:' . $mois . ')(?: 20\d{2})?|(?:' . $mois . ')(?: 20\d{2})?)';
        // « du 1er janvier au 31 mars », « entre janvier et mars 2025 », « de mars à juin »
        if (preg_match('/\b(?:du|de|depuis le|entre le|entre|a partir du)\s+' . $tok . '\s+(?:au|a|jusqu au|et le|et)\s+' . $tok . '\b/', $t, $m)) {
            $anFin = preg_match('/(20\d{2})/', $m[2], $y) ? (int)$y[1] : null;
            $d = self::jetonDate($m[1], true, $anFin ?? $an);
            $e = self::jetonDate($m[2], false, $an);
            if ($d && $e) {
                if (strcmp($d, $e) > 0 && !preg_match('/20\d{2}/', $m[1])) $d = self::jetonDate($m[1], true, ($anFin ?? $an) - 1);
                return [$d, $e, 'du ' . self::dateFr($d) . ' au ' . self::dateFr($e)];
            }
        }
        // « les 3 derniers mois », « les 30 derniers jours », « la semaine dernière » (déjà en filtre)
        if (preg_match('/\b(?:les |ces |sur les |pendant les )?(\d{1,3}) (?:derniers|dernieres) (jours?|semaines?|mois|ans?|annees?)\b/', $t, $m)) {
            $n = max(1, (int)$m[1]);
            $u = str_starts_with($m[2], 'jour') ? 'days' : (str_starts_with($m[2], 'semaine') ? 'weeks' : (str_starts_with($m[2], 'mois') ? 'months' : 'years'));
            $d = date('Y-m-d', strtotime("-$n $u +1 day"));
            return [$d, $auj, 'sur les ' . $n . ' derniers ' . ['days' => 'jours', 'weeks' => 'semaines', 'months' => 'mois', 'years' => 'ans'][$u] . ' (du ' . self::dateFr($d) . ' au ' . self::dateFr($auj) . ')'];
        }
        if (preg_match('/\bdepuis (?:le )?debut (?:de l |d )?(annee|mois)\b/', $t, $m)) {
            $d = $m[1] === 'annee' ? date('Y-01-01') : date('Y-m-01');
            return [$d, $auj, 'depuis le ' . self::dateFr($d)];
        }
        if (preg_match('/\bdepuis (?:le )?' . $tok . '\b/', $t, $m) && ($d = self::jetonDate($m[1], true, $an))) {
            if (strcmp($d, $auj) > 0) $d = self::jetonDate($m[1], true, $an - 1);
            return [$d, $auj, 'depuis le ' . self::dateFr($d)];
        }
        // Filtres de date déjà lus par la compréhension générale.
        // « en octobre », « le 12 mars » sans année : la supervision mesure le passé —
        // une date encore à venir cette année désigne celle de l'an dernier.
        $passe = static function (string $d) use ($t): string {
            return (strcmp($d, date('Y-m-d')) > 0 && !preg_match('/\b20\d{2}\b/', $t)) ? date('Y-m-d', strtotime($d . ' -1 year')) : $d;
        };
        if (!empty($f['jour'])) { $j = $passe($f['jour']); return [$j, $j, 'le ' . self::dateFr($j)]; }
        if (!empty($f['semaine']) && preg_match('/^(\d{4})-W(\d{2})$/', $f['semaine'], $m)) {
            $d = date('Y-m-d', strtotime($m[1] . 'W' . $m[2] . '1'));
            $e = date('Y-m-d', strtotime($d . ' +6 days'));
            return [$d, $e, 'la semaine du ' . self::dateFr($d)];
        }
        if (!empty($f['mois']) && preg_match('/^(\d{4})-(\d{2})$/', $f['mois'], $m)) {
            $d = $passe($f['mois'] . '-01');
            return [$d, date('Y-m-t', strtotime($d)), 'en ' . self::NOMS_MOIS[(int)$m[2]] . ' ' . substr($d, 0, 4)];
        }
        if (!empty($f['annee']) && preg_match('/^\d{4}$/', (string)$f['annee'])) {
            return [$f['annee'] . '-01-01', $f['annee'] . '-12-31', 'en ' . $f['annee']];
        }
        return null;
    }

    /** « 15/03 », « 1er janvier 2025 », « mars » → AAAA-MM-JJ (début ou fin de mois si le jour manque). */
    private static function jetonDate(string $s, bool $debut, int $an): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $s, $m)) {
            $a = isset($m[3]) ? (int)$m[3] : $an; if ($a < 100) $a += 2000;
            return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(?:(1er|premier|\d{1,2}) )?([a-z]+)(?: (20\d{2}))?$/', $s, $m) && isset(self::MOIS[$m[2]])) {
            $a = isset($m[3]) ? (int)$m[3] : $an;
            $mo = self::MOIS[$m[2]];
            if ($m[1] !== '') {
                $j = in_array($m[1], ['1er', 'premier'], true) ? 1 : (int)$m[1];
                return checkdate($mo, $j, $a) ? sprintf('%04d-%02d-%02d', $a, $mo, $j) : null;
            }
            $d = sprintf('%04d-%02d-01', $a, $mo);
            return $debut ? $d : date('Y-m-t', strtotime($d));
        }
        return null;
    }

    public static function dateFr(string $d): string
    {
        $ts = strtotime($d);
        return $ts ? date('d/m/Y', $ts) : $d;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Réponses (appelées par AssistantMoteur)
    // ═══════════════════════════════════════════════════════════════════════

    /** Nombre à la française : espace fine insécable des milliers, décimales selon la grandeur. */
    public static function nombre(?float $v, ?int $dec = null): string
    {
        if ($v === null) return '—';
        $dec ??= abs($v) >= 1000 ? 0 : (abs($v) >= 100 ? 1 : 2);
        $t = number_format($v, $dec, ',', "\u{202F}");
        return str_contains($t, ',') ? rtrim(rtrim($t, '0'), ',') : $t;
    }

    /** « 27 526 kWh (27,5 MWh) » : l'énergie lisible quelle que soit sa grandeur. */
    public static function quantite(float $v, string $unite): string
    {
        $txt = '**' . self::nombre($v) . ($unite !== '' ? ' ' . $unite : '') . '**';
        if ($unite === 'kWh' && $v >= 10000) $txt .= ' (' . self::nombre($v / 1000, 1) . ' MWh)';
        return $txt;
    }

    private static function libEnergie(string $type): string
    {
        return self::ENERGIES[$type][0] ?? ($type === 'Ambiance' ? 'Ambiance' : 'Autre');
    }

    /**
     * La réponse. $c : compréhension (mots, filtres, sup = [quoi, energie, periode, gravite, actives]).
     * @param callable $lire fn(string $quoi, array $args): array
     * @return array{texte:string, trouve:bool}
     */
    public static function repondre(array $c, callable $lire): array
    {
        $s = $c['sup'] ?? [];
        $quoi = (string)($s['quoi'] ?? 'etat');
        $resume = $lire('resume', []);
        if (isset($resume['erreur']) || isset($resume['error'])) {
            return ['texte' => "La supervision ne répond pas pour le moment : " . ($resume['erreur'] ?? $resume['error']), 'trouve' => true];
        }
        $bat = self::batiment($c, (array)($resume['batiments'] ?? []));
        return match ($quoi) {
            'conso'   => self::conso($c, $s, $bat, $lire),
            'alarmes' => self::alarmes($s, $bat, $lire),
            'valeur'  => self::valeur($c, $s, $bat, $lire),
            default   => self::etat($resume, $bat, (string)($c['question'] ?? '')),
        };
    }

    /**
     * Bâtiment de la supervision désigné par la question : nommé dans la question
     * (« le groupe scolaire » suffit pour « Groupe scolaire Les Marronniers »), lu
     * par la compréhension générale, ou — pour une suite (« et en mars ? ») —
     * celui de la question précédente. Deux bâtiments à égalité : aucun (mieux
     * vaut le total que le mauvais bâtiment).
     */
    private static function batiment(array $c, array $connus): ?string
    {
        $cite = function (string $question) use ($connus): ?string {
            $q = ' ' . ConnectLecture::norm($question) . ' ';
            // Mots du quotidien pour les bâtiments publics : « l'école » désigne le groupe
            // scolaire, « la mairie » l'hôtel de ville.
            foreach (self::SYNONYMES_BATIMENT as $mot => $ajout) if (str_contains($q, ' ' . $mot . ' ')) $q .= $ajout . ' ';
            $scores = [];
            foreach ($connus as $b) {
                $mots = array_values(array_filter(explode(' ', ConnectLecture::norm((string)$b)),
                    fn($w) => mb_strlen($w) > 3 && !in_array($w, ['batiment', 'groupe', 'centre', 'maison', 'salle', 'site'], true)));
                $n = count(array_filter($mots, fn($w) => str_contains($q, ' ' . $w . ' ')));
                if ($n) $scores[(string)$b] = $n / max(1, count($mots)) + $n;
            }
            if (!$scores) return null;
            arsort($scores);
            $v = array_values($scores);
            return (count($v) > 1 && $v[0] === $v[1]) ? null : (string)array_key_first($scores);
        };
        $b = $cite((string)($c['question'] ?? ''));
        if ($b !== null) return $b;
        $f = $c['filtres']['batiment'] ?? null;
        if ($f !== null) {
            foreach ($connus as $k) {
                $n = ConnectLecture::norm((string)$k); $fn = ConnectLecture::norm((string)$f);
                if ($fn !== '' && (str_contains($n, $fn) || str_contains($fn, $n))) return (string)$k;
            }
            return (string)$f;
        }
        foreach ((array)($c['questions_prec'] ?? []) as $qp) {
            if (($b = $cite((string)$qp)) !== null) return $b;
        }
        return isset($c['bat_prec']) ? (string)$c['bat_prec'] : null;
    }

    private const SYNONYMES_BATIMENT = [
        'ecole' => 'scolaire maternelle elementaire', 'ecoles' => 'scolaire maternelle elementaire',
        'mairie' => 'hotel ville', 'piscine' => 'aquatique nautique', 'gymnase' => 'sportif sports', 'creche' => 'petite enfance',
    ];

    private static function lienSupervision(): string { return "\n[PAGE:connect:Ouvrir la supervision]"; }

    // ── Consommations ──────────────────────────────────────────────────────

    private static function conso(array $c, array $s, ?string $bat, callable $lire): array
    {
        [$d, $f, $lib] = $s['periode'] ?? [date('Y-m-01'), date('Y-m-d'), 'ce mois-ci (du ' . self::dateFr(date('Y-m-01')) . ' au ' . self::dateFr(date('Y-m-d')) . ')'];
        $r = $lire('conso', array_filter(['debut' => $d, 'fin' => $f, 'batiment' => $bat, 'type' => $s['energie'] ?? null]));
        if (isset($r['erreur'])) return ['texte' => 'Supervision : ' . $r['erreur'], 'trouve' => true];
        $qui = $bat ? "de **" . AssistantLangue::texte($bat) . "**" : 'de tous les bâtiments supervisés';
        $quoiE = !empty($s['energie']) ? ' en ' . mb_strtolower(self::libEnergie($s['energie'])) : '';
        if ($bat && !empty($r['batiments_connus']) && !array_filter($r['batiments_connus'], fn($b) => ConnectLecture::norm($b) === ConnectLecture::norm($bat))
            && !(int)($r['nb_compteurs'] ?? 0)) {
            return ['texte' => "La supervision ne connaît pas de compteur au bâtiment « " . AssistantLangue::texte($bat) . " ». Bâtiments supervisés : "
                . AssistantLangue::texte(implode(', ', $r['batiments_connus'])) . '.', 'trouve' => true];
        }
        if (!(int)($r['nb_compteurs'] ?? 0)) {
            return ['texte' => "Aucun compteur raccordé$quoiE $qui. Les consommations se calculent sur les points de nature « compteur » (index) de la supervision.", 'trouve' => true];
        }
        $L = [];
        $partiel = false;
        $parBat = [];
        foreach ($r['totaux'] ?? [] as $t) $parBat[(string)($t['batiment'] ?? '—')][] = $t;
        if (!$parBat) {
            return ['texte' => "Consommation$quoiE $qui $lib : aucune mesure exploitable sur la période (historique absent ou index en baisse).", 'trouve' => true];
        }
        $plusieurs = count($parBat) > 1;
        foreach ($parBat as $b => $ts) {
            if ($plusieurs) $L[] = '**' . AssistantLangue::texte($b) . '**';
            foreach ($ts as $t) {
                $partiel = $partiel || !empty($t['partiel']);
                $L[] = ($plusieurs ? '  ' : '') . '- ' . self::libEnergie((string)$t['type']) . ' : ' . self::quantite((float)$t['total'], (string)$t['unite'])
                    . ' (' . (int)$t['compteurs'] . ' compteur' . ((int)$t['compteurs'] > 1 ? 's' : '') . ')' . (!empty($t['partiel']) ? ' *partiel*' : '');
            }
        }
        // Total tous bâtiments, par énergie, quand plusieurs bâtiments.
        if ($plusieurs && !empty($r['par_type'])) {
            $L[] = '**Total**';
            foreach ($r['par_type'] as $t) $L[] = '- ' . self::libEnergie((string)$t['type']) . ' : ' . self::quantite((float)$t['total'], (string)$t['unite']);
        }
        $anom = array_filter($r['compteurs'] ?? [], fn($x) => !empty($x['anomalie']));
        $vides = array_filter($r['compteurs'] ?? [], fn($x) => $x['conso'] === null && empty($x['anomalie']));
        $notes = [];
        if ($partiel) $notes[] = '*partiel* : l\'historique du compteur commence en cours de période, la consommation réelle est plus élevée';
        if ($anom) $notes[] = count($anom) . ' compteur' . (count($anom) > 1 ? 's' : '') . ' écarté' . (count($anom) > 1 ? 's' : '') . ' (index en baisse : '
            . AssistantLangue::texte(implode(', ', array_map(fn($x) => $x['nom'], array_slice($anom, 0, 3)))) . ')';
        if ($vides) $notes[] = count($vides) . ' compteur' . (count($vides) > 1 ? 's' : '') . ' sans mesure sur la période';
        $txt = "Consommation$quoiE $qui, " . $lib . " :\n" . implode("\n", $L);
        if ($notes) $txt .= "\n*(" . implode(' ; ', $notes) . ")*";
        $txt .= "\n*Calcul : index de fin moins index de début de chaque compteur, comme un relevé manuel.*" . self::lienSupervision();
        return ['texte' => $txt, 'trouve' => true];
    }

    // ── Alarmes ────────────────────────────────────────────────────────────

    private static function alarmes(array $s, ?string $bat, callable $lire): array
    {
        $periode = $s['periode'] ?? null;
        $actives = !empty($s['actives']) || !$periode;
        $args = array_filter(['batiment' => $bat, 'gravite' => $s['gravite'] ?? null]);
        $grav = fn(int $n) => !empty($s['gravite']) ? ' ' . $s['gravite'] . ($n > 1 ? 's' : '') : '';
        $qui = $bat ? ' à **' . AssistantLangue::texte($bat) . '**' : '';
        $L = [];
        $act = $lire('alarmes', $args + ['etat' => 'active']);
        if (isset($act['erreur'])) return ['texte' => 'Supervision : ' . $act['erreur'], 'trouve' => true];
        $nA = (int)($act['total'] ?? 0);
        if ($actives) {
            $L[] = $nA ? "**$nA alarme" . ($nA > 1 ? 's' : '') . $grav($nA) . " active" . ($nA > 1 ? 's' : '') . "**$qui"
                . ' (' . (int)($act['acquittees'] ?? 0) . ' acquittée' . ((int)($act['acquittees'] ?? 0) > 1 ? 's' : '') . ')'
                       : "Aucune alarme" . $grav(1) . " active$qui.";
            foreach (array_slice($act['liste'] ?? [], 0, 6) as $a) $L[] = self::ligneAlarme($a);
        }
        if ($periode || !$actives) {
            [$d, $f, $lib] = $periode ?? [date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), 'sur les 30 derniers jours'];
        } else {
            [$d, $f, $lib] = [date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), 'sur les 30 derniers jours'];
        }
        $per = $lire('alarmes', $args + ['debut' => $d, 'fin' => $f]);
        $n = (int)($per['total'] ?? 0);
        $detail = [];
        foreach (['critique', 'majeure', 'mineure', 'info'] as $g) if (!empty($per['par_gravite'][$g])) $detail[] = $per['par_gravite'][$g] . ' ' . $g . ($per['par_gravite'][$g] > 1 && $g !== 'info' ? 's' : '');
        $L[] = ($actives ? "\n" : '') . "**$n alarme" . ($n > 1 ? 's' : '') . $grav($n) . "** $lib$qui" . ($detail ? ' : ' . implode(', ', $detail) : '') . '.';
        if (!$bat && count($per['par_batiment'] ?? []) > 1) {
            $parts = [];
            foreach ($per['par_batiment'] as $b => $k) $parts[] = AssistantLangue::texte($b !== '' ? $b : 'sans bâtiment') . ' : ' . $k;
            $L[] = 'Par bâtiment — ' . implode(' · ', $parts) . '.';
        }
        if ($nd = (int)($per['avec_demande'] ?? 0)) $L[] = $nd . ($nd > 1 ? ' ont' : ' a') . ' donné lieu à une demande d\'intervention.';
        if (!$actives || $periode) foreach (array_slice($per['liste'] ?? [], 0, 5) as $a) $L[] = self::ligneAlarme($a);
        return ['texte' => implode("\n", $L) . "\n[PAGE:connect_alarmes:Ouvrir les alarmes techniques]", 'trouve' => true];
    }

    private static function ligneAlarme(array $a): string
    {
        $d = !empty($a['debut']) ? date('d/m H:i', strtotime((string)$a['debut'])) : '';
        return '- ' . $d . ' — ' . AssistantLangue::texte((string)($a['message'] ?? ''), 140)
            . (!empty($a['batiment']) ? ' (' . AssistantLangue::texte((string)$a['batiment']) . ')' : '')
            . ($a['etat'] === 'active' ? ($a['acquittee'] ? ' — *acquittée*' : ' — **à acquitter**') : '');
    }

    // ── État ───────────────────────────────────────────────────────────────

    private static function etat(array $r, ?string $bat, string $question = ''): array
    {
        $n = (int)($r['connexions_actives'] ?? 0);
        if (!(int)($r['connexions'] ?? 0)) return ['texte' => "Aucun équipement n'est encore raccordé à la supervision (Bâtiments → Connexions → Détecter les équipements).", 'trouve' => true];
        $def = $r['en_defaut'] ?? [];
        $pts = $r['points_en_defaut'] ?? [];
        if ($bat) {
            $def = array_values(array_filter($def, fn($x) => ConnectLecture::norm((string)($x['batiment'] ?? '')) === ConnectLecture::norm($bat)));
            $pts = array_values(array_filter($pts, fn($x) => ConnectLecture::norm((string)($x['batiment'] ?? '')) === ConnectLecture::norm($bat)));
        }
        // Collecte arrêtée : l'état affiché est celui du DERNIER passage. Dire
        // « tout fonctionne » serait faux — personne ne vérifie plus rien.
        $passage = !empty($r['dernier_passage']) ? strtotime((string)$r['dernier_passage']) : null;
        $arret = $passage !== null && $passage < time() - 600;
        $ou = $bat ? ' à ' . AssistantLangue::texte($bat) : '';
        $suivis = "$n connexion" . ($n > 1 ? 's' : '') . ' en service, ' . (int)$r['points'] . ' points suivis';
        $L = [];
        if ($arret) {
            $L[] = '**La collecte ne passe plus** depuis le ' . date('d/m/Y à H:i', $passage)
                . ' : les valeurs ne se mettent plus à jour et l\'état des équipements n\'est plus vérifié (code CXC-001).';
        }
        if ($def || $pts) {
            $L[] = '**' . count($def) . ' connexion' . (count($def) > 1 ? 's' : '') . ' en défaut** et ' . count($pts) . ' point' . (count($pts) > 1 ? 's' : '')
                . " sans valeur fiable$ou ($n connexion" . ($n > 1 ? 's' : '') . ' en service).';
        } elseif ($arret) {
            $L[] = "Au dernier passage$ou : aucun défaut, $suivis.";
        } else {
            // « capteurs en défaut ? » attend un oui ou un non sur les défauts.
            $surDefauts = (bool)preg_match('/\b(?:defauts?|hs|pannes?|en panne|repond\w*|hors ligne|injoignables?|muets?)\b/', ConnectLecture::norm($question));
            $L[] = ($surDefauts ? '**Aucune connexion ni point en défaut**' : '**Tout fonctionne**') . "$ou : $suivis.";
        }
        foreach (array_slice($def, 0, 6) as $x) {
            $L[] = '- ' . AssistantLangue::texte((string)$x['nom']) . (!empty($x['batiment']) ? ' (' . AssistantLangue::texte((string)$x['batiment']) . ')' : '')
                . (!empty($x['erreur']) ? ' : ' . AssistantLangue::texte((string)$x['erreur'], 120) : '');
        }
        foreach (array_slice($pts, 0, 6) as $x) $L[] = '- point « ' . AssistantLangue::texte((string)$x['nom']) . ' » (' . AssistantLangue::texte((string)$x['connexion']) . ')';
        $na = (int)($r['alarmes_actives'] ?? 0);
        $L[] = $na . ' alarme' . ($na > 1 ? 's' : '') . ' active' . ($na > 1 ? 's' : '') . '.'
            . ($passage !== null && !$arret ? ' Dernière collecte : ' . date('d/m/Y H:i', $passage) . '.' : '');
        return ['texte' => implode("\n", $L) . self::lienSupervision(), 'trouve' => true];
    }

    // ── Valeur d'un point ──────────────────────────────────────────────────

    private const MOTS_VALEUR_VIDES = ['quelle', 'quel', 'quels', 'quelles', 'valeur', 'actuelle', 'actuel', 'est', 'fait', 'il', 'combien',
                                       'maintenant', 'moment', 'ce', 'en', 'niveau', 'mesure', 'mesuree', 'degres', 'degre', 'dans', 'la', 'le',
                                       'du', 'de', 'des', 'au', 'aux', 'a', 'l', 'd', 'y', 'quelle', 'donne', 'moi', 'stp', 'svp',
                                       'taux', 'actuellement', 'releve', 'relevee', 'indique', 'affiche', 'on', 't', 'il', 'elle', 'sont',
                                       'tourne', 'marche', 'allume', 'allumee', 'arrete', 'arretee', 'eteint', 'eteinte', 'qu', 'que', 'ce',
                                       'degres', 'combien', 'les', 'un', 'une', 'pour'];

    private static function valeur(array $c, array $s, ?string $bat, callable $lire): array
    {
        $q = ConnectLecture::norm((string)$c['question']);
        $mots = array_values(array_filter(explode(' ', $q), fn($w) => mb_strlen($w) > 1 && !in_array($w, self::MOTS_VALEUR_VIDES, true)));
        // Le nom du bâtiment sert de filtre, pas de mot à trouver dans le nom du point.
        if ($bat) {
            $nb = explode(' ', ConnectLecture::norm($bat));
            $mots = array_values(array_filter($mots, fn($w) => !in_array($w, $nb, true)));
        }
        if (preg_match('/\b(?:il fait combien|fait il combien|combien de degres|combien fait il)\b/', $q)
            && !array_filter($mots, fn($w) => preg_match('/^(?:temperature|humidite|co2|pression|puissance|debit|consigne|hygrometrie|index)$/', $w))) {
            array_unshift($mots, 'temperature');
        }
        // « est-ce que la sous-station est en marche ? » : un point d'état (marche / arrêt),
        // pas l'index de chaleur de la même sous-station.
        $etat = (bool)preg_match('/\b(?:tourne\w*|en marche|allume\w*|arrete\w*|eteint\w*)\b/', $q);
        $r = $etat ? $lire('valeurs', array_filter(['mots' => array_slice($mots, 0, 4), 'batiment' => $bat, 'nature' => 'etat'])) : ['total' => 0];
        if (!(int)($r['total'] ?? 0)) $r = $lire('valeurs', array_filter(['mots' => array_slice($mots, 0, 4), 'batiment' => $bat]));
        // Trop précis ? On réessaie avec le seul mot de la grandeur (« température »).
        if (!(int)($r['total'] ?? 0) && count($mots) > 1) {
            $grandeur = array_values(array_filter($mots, fn($w) => preg_match('/^(?:temperature|humidite|co2|pression|puissance|debit|consigne|hygrometrie|index)$/', $w)));
            // Sans grandeur (« la sous-station tourne ? ») : le mot le plus long désigne le point.
            if (!$grandeur) { $m2 = $mots; usort($m2, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); $grandeur = array_slice($m2, 0, 1); }
            if ($grandeur) $r = $lire('valeurs', array_filter(['mots' => $grandeur, 'batiment' => $bat]));
        }
        $pts = $r['points'] ?? [];
        // Repli sur la seule grandeur (« co2 ») : plusieurs points reviennent. Les
        // autres mots de la question (« classe cm2 ») départagent — on garde ceux
        // qui en contiennent le plus, plutôt que d'afficher tous les CO₂ du parc.
        if (count($pts) > 1 && count($mots) > 1) {
            $score = static function (array $p) use ($mots): int {
                $nom = ' ' . ConnectLecture::norm((string)($p['nom'] ?? '') . ' ' . (string)($p['batiment'] ?? '')) . ' ';
                return count(array_filter($mots, fn($w) => str_contains($nom, ' ' . $w . ' ')));
            };
            $scores = array_map($score, $pts);
            $max = max($scores);
            if ($max > 0 && count(array_filter($scores, fn($x) => $x === $max)) < count($pts)) {
                $pts = array_values(array_filter($pts, fn($p, $k) => $scores[$k] === $max, ARRAY_FILTER_USE_BOTH));
                $r['total'] = count($pts);
            }
        }
        if (!$pts) return ['texte' => "Aucun point suivi ne correspond à « " . AssistantLangue::texte(implode(' ', $mots)) . " »" . ($bat ? ' à ' . AssistantLangue::texte($bat) : '') . '.' . self::lienSupervision(), 'trouve' => false];
        $L = [];
        foreach (array_slice($pts, 0, 6) as $p) {
            // Un point d'état se lit en mots (« marche », « arrêt »), pas en 0 / 1.
            $v = (($p['nature'] ?? '') === 'etat' && ($p['texte'] ?? '') !== '') || $p['valeur'] === null
                ? AssistantLangue::texte((string)($p['texte'] ?? '—'))
                : self::nombre((float)$p['valeur'], (int)$p['decimales']) . ($p['unite'] !== '' ? ' ' . $p['unite'] : '');
            $age = !empty($p['lecture']) ? (int)((time() - strtotime((string)$p['lecture'])) / 60) : null;
            $L[] = '- ' . AssistantLangue::texte($p['nom']) . ' : **' . $v . '**'
                . (!empty($p['batiment']) ? ' — ' . AssistantLangue::texte((string)$p['batiment']) : '')
                . ($age !== null ? ' (lu ' . ($age < 2 ? 'à l\'instant' : ($age < 120 ? "il y a $age min" : 'le ' . date('d/m H:i', strtotime((string)$p['lecture'])))) . ')' : '')
                . (($p['qualite'] ?? '') === 'mauvaise' ? ' — *valeur douteuse*' : '')
                . ((int)$p['alarmes'] ? ' — **en alarme**' : '');
        }
        if ((int)$r['total'] > 6) $L[] = '… et ' . ((int)$r['total'] - 6) . ' autres.';
        return ['texte' => implode("\n", $L) . self::lienSupervision(), 'trouve' => true];
    }
}
