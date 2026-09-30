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
 * Larka — Assistant : moteur de réponse déterministe (gestionnaires)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * LE PRINCIPE
 *   Le modèle de langage ne décide plus de rien : ni des recherches, ni des
 *   chiffres, ni des liens. Le moteur comprend la question (AssistantComprehension),
 *   exécute lui-même les recherches (AssistantTools : mêmes droits, même
 *   anonymisation) et rédige la réponse à partir de gabarits.
 *
 *   Conséquence recherchée : « même question → même réponse », que le modèle
 *   configuré soit un 0,6B, un 1B, un 3B, un 7B, un modèle distant — ou
 *   qu'aucun modèle ne réponde. Et une réponse en quelques dizaines de
 *   millisecondes au lieu de dizaines de secondes sur CPU.
 *
 *   Le modèle n'intervient qu'en SECOND RECOURS, quand le moteur ne trouve rien
 *   (AssistantInterprete : formulaire JSON contraint, vérifié ici avant usage).
 *
 * CE QUI GARANTIT LE DÉTERMINISME
 *   - aucune donnée aléatoire, aucune dépendance à l'ordre d'arrivée : tous les
 *     tris ont un départage explicite (score, puis ordre SQL, puis Id) ;
 *   - aucun réglage lié au fournisseur (nombre de résultats, longueur des
 *     textes) : un modèle local et un modèle distant voient la même réponse ;
 *   - les liens [FICHE:…] sont construits avec les Id réels des lignes trouvées.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/AssistantLangue.php';
require_once __DIR__ . '/AssistantTools.php';
require_once __DIR__ . '/AssistantComprehension.php';

final class AssistantMoteur
{
    /** Éléments listés au plus dans une réponse (au-delà : « et N autres »). */
    public const MAX_LISTE = 8;

    private AssistantTools $tools;
    private AssistantComprehension $comp;
    private array $etapes = [];
    /** Graphies d'origine des mots de la question en cours (affichage). */
    private array $graphies = [];
    /** @var callable|null fn(string $evenement, array $donnees) — suivi en direct (SSE) */
    private $suivi = null;

    public function __construct(AssistantTools $tools, ?AssistantComprehension $comp = null)
    {
        $this->tools = $tools;
        $this->comp = $comp ?? new AssistantComprehension($tools);
    }

    public function comprehension(): AssistantComprehension { return $this->comp; }
    public function setSuivi(?callable $f): void { $this->suivi = $f; }

    // ═══════════════════════════════════════════════════════════════════════
    //  Point d'entrée
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array{texte:string, trouve:bool, intention:string, comprehension:array, etapes:array}
     *   trouve = la réponse s'appuie sur des données trouvées (false : rien
     *   trouvé ou question non comprise — la route peut alors tenter le modèle).
     */
    public function repondre(string $question, array $historique = [], ?array $c = null): array
    {
        $this->etapes = [];
        $c ??= $this->comp->analyser($question, $historique);
        $this->graphies = $c['affichage'] ?? [];
        try {
            // « interventions du 31/02 » : une date qui n'existe pas ne filtre rien — on le dit.
            if (!empty($c['date_invalide']) && !in_array($c['intention'], ['politesse', 'aide', 'hors_sujet'], true)) {
                $c['intention'] = 'date_invalide';
            }
            $r = match ($c['intention']) {
                'date_invalide' => ['texte' => "La date du **" . AssistantLangue::texte((string)$c['date_invalide']) . "** n'existe pas. Vérifiez le jour et le mois — par exemple « interventions du 28/02/2026 ».", 'trouve' => true],
                'politesse'  => $this->politesse($c),
                'aide'       => $this->aide(),
                'hors_sujet' => $this->horsSujet(),
                'modules'    => $this->modules(),
                'contexte'   => $this->contexte($c),
                'personne'   => $this->personne($c),
                'alertes'    => $this->alertes($c),
                'compter'    => $this->compter($c),
                'stock'      => $this->stock($c),
                'somme'      => $this->somme($c),
                'localiser'  => $this->localiser($c),
                'fiche'      => $this->fiche($c),
                'quand'      => $this->quand($c),
                'chercher'   => $this->chercher($c),
                default      => $this->incompris($c),
            };
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] moteur: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            $r = ['texte' => "Je n'ai pas pu traiter cette question (erreur interne). Reformulez-la plus simplement, ou réessayez.", 'trouve' => false];
        }
        $texte = $r['texte'];
        if (!empty($c['corrections']) && !empty($r['trouve'])) {
            $cor = [];
            foreach ($c['corrections'] as $de => $vers) $cor[] = "« $de » → « $vers »";
            // Corrections des mots-outils seulement (celles des mots-clés sont dites par la recherche).
            $texte = '*(compris : ' . implode(', ', $cor) . ")*\n" . $texte;
        }
        return ['texte' => trim($texte), 'trouve' => (bool)$r['trouve'], 'intention' => $c['intention'],
                'comprehension' => $c, 'etapes' => $this->etapes];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Intentions simples
    // ═══════════════════════════════════════════════════════════════════════

    private function politesse(array $c): array
    {
        $q = AssistantLangue::preparer($c['question']);
        if (preg_match('/\b(?:merci|parfait|super|top|genial|nickel|cool)\b/', $q)) {
            return ['texte' => "Avec plaisir ! Autre chose ?", 'trouve' => true];
        }
        if (preg_match('/\b(?:au revoir|bye|a plus|bonne (?:journee|soiree))\b/', $q)) {
            return ['texte' => "Bonne journée !", 'trouve' => true];
        }
        return ['texte' => "Bonjour ! Posez-moi une question sur les données Larka — par exemple « où est la centrale SSI ? » ou « combien d'interventions en cours ? ».", 'trouve' => true];
    }

    private function aide(): array
    {
        return ['texte' => "Je réponds sur les données Larka. Exemples :\n"
            . "- « Où est l'extincteur 2 du bâtiment A ? », « où est le bureau 204 ? »\n"
            . "- « Combien d'équipements au bâtiment A ? », « combien d'interventions en cours ? »\n"
            . "- « Interventions préventives sur les BAES », « contrats de maintenance »\n"
            . "- « Contrats qui expirent », « stock en alerte », « interventions en retard »\n"
            . "- « Qu'est-ce qui est prévu demain ? », « interventions de la semaine prochaine »\n"
            . "- « Fiche de l'équipement EXT-002 », « quand expire le contrat des ascenseurs ? »\n"
            . "- « Liste des techniciens », « qui a fait la demande 12 ? »\n"
            . "Les fautes et abréviations sont comprises (« cb de clims au bat B »).", 'trouve' => true];
    }

    private function horsSujet(): array
    {
        return ['texte' => "Je ne réponds que sur les données Larka : biens, équipements, interventions, contrats, demandes, stock, documents et plans.", 'trouve' => true];
    }

    private function incompris(array $c): array
    {
        return ['texte' => "Je n'ai pas compris la question. Vous pouvez par exemple demander :\n"
            . "- « où est … ? » (un équipement, un bien)\n- « combien de … ? »\n- « liste des … » (interventions, contrats, demandes…)\n"
            . "- « alertes », « stock en alerte », « contrats qui expirent »\n- « qui est … ? »", 'trouve' => false];
    }

    private function modules(): array
    {
        $r = $this->outil('module', []);
        if (isset($r['error'])) return ['texte' => "Aucun module complémentaire n'est accessible.", 'trouve' => true];
        $l = [];
        foreach ($r['installes'] ?? [] as $m) {
            $jeux = array_values((array)($m['jeux'] ?? []));
            $l[] = '- **' . AssistantLangue::texte($m['nom'] ?? $m['module']) . '**' . ($jeux ? ' — ' . AssistantLangue::texte(implode(', ', $jeux)) : '');
        }
        $txt = $l ? "Modules complémentaires installés :\n" . implode("\n", $l) : "Aucun module complémentaire installé et accessible.";
        $non = array_map(fn($m) => AssistantLangue::texte(($m['nom'] ?? $m['module']) . ' (' . ($m['etat'] ?? '') . ')'), $r['non_installes'] ?? []);
        if ($non) $txt .= "\nNon disponibles ici : " . implode(', ', array_slice($non, 0, 8)) . '.';
        return ['texte' => $txt, 'trouve' => true];
    }

    private function contexte(array $c): array
    {
        $r = $this->outil('contexte', []);
        $q = AssistantLangue::preparer($c['question']);
        $parts = [];
        $voulu = fn(string $re) => (bool)preg_match($re, $q);
        $tout = !$voulu('/\b(?:batiments|familles|categories)\b/');
        if ($tout || $voulu('/\bbatiments\b/')) $parts[] = '**Bâtiments** : ' . AssistantLangue::texte(implode(', ', $r['batiments'] ?? []) ?: 'aucun', 400);
        if ($tout || $voulu('/\bfamilles\b/')) {
            if (!$voulu('/\bbiens?\b/')) $parts[] = "**Familles d'équipements** : " . AssistantLangue::texte(implode(', ', $r['familles_equipements'] ?? []) ?: 'aucune', 400);
            if (!$voulu('/\bequipements?\b/')) $parts[] = '**Familles de biens** : ' . AssistantLangue::texte(implode(', ', $r['familles_biens'] ?? []) ?: 'aucune', 400);
        }
        if ($tout || $voulu('/\bcategories\b/')) {
            $cats = $this->tools->vocabulaireSite()['categories_demandes'] ?? [];
            $parts[] = '**Catégories de demandes** : ' . AssistantLangue::texte(implode(', ', $cats) ?: 'aucune', 400);
        }
        if ($tout && !empty($r['gestionnaires'])) $parts[] = '**Gestionnaires** : ' . AssistantLangue::texte(implode(', ', $r['gestionnaires']), 400);
        return ['texte' => implode("\n", $parts), 'trouve' => true];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Localiser
    // ═══════════════════════════════════════════════════════════════════════

    private const LOCALISABLES = ['equipements', 'biens', 'plans', 'stock', 'archives'];

    private function localiser(array $c): array
    {
        // Élément déjà désigné (réponse précédente, « où est-il ? »)
        if ($c['reference']) {
            $row = $this->ligneParId($c['reference']['type'], $c['reference']['id']);
            if ($row) return $this->localiserUn($c['reference']['type'], $row);
        }
        if (!$c['mots'] && !$c['codes'] && !$c['numeros']) {
            // « où est le bureau 204 ? » : un lieu, situé par ce qui y est rattaché.
            if (!empty($c['filtres']['bureau'])) return $this->localiserBureau($c);
            return ['texte' => "Que cherchez-vous ? Par exemple : « où est la centrale SSI ? » ou « où est l'extincteur 2 du centre technique ? ».", 'trouve' => false];
        }
        $query = $this->requete($c, true);
        $types = $c['type'] && in_array($c['type'], self::LOCALISABLES, true) ? [$c['type']] : self::LOCALISABLES;
        // Le lieu fait partie des critères de recherche : « extincteur 2 centre technique ».
        $filtres = array_intersect_key($c['filtres'], array_flip(['batiment', 'etage', 'bureau']));
        $res = $this->rechercheGlobale($query, $types, $filtres, true);
        if (!$res['blocs'] && $filtres) {
            // Rien à cet endroit : on cherche partout pour ne pas conclure « introuvable » à tort.
            $res = $this->rechercheGlobale($query, $types, [], true);
            $horsLieu = (bool)$res['blocs'];
        }
        if (!$res['blocs']) return $this->rienTrouve($c, $res['corrections']);

        // Candidats de tête : meilleur score, tous types confondus.
        $cands = $this->meilleurs($res['blocs']);
        $pluriel = $this->demandePluriel($c);
        $prefixe = $this->prefixeCorrections($res['corrections']) . (!empty($horsLieu) ? "*(rien à l'endroit indiqué ; voici ce qui existe ailleurs)*\n" : '');

        if (count($cands) === 1 && !$pluriel) {
            [$type, $row] = $cands[0];
            $u = $this->localiserUn($type, $row);
            $u['texte'] = $prefixe . $u['texte'];
            return $u;
        }
        // Plusieurs : on les situe tous ; question au singulier → on demande lequel.
        return $this->localiserPlusieurs($c, $cands, $pluriel, $prefixe);
    }

    /**
     * Précision : dès qu'au moins une ligne contient TOUS les mots demandés, les
     * correspondances partielles sont écartées (« contrat maintenance
     * ascenseurs » = le contrat Otis, pas tous les contrats de maintenance).
     */
    private function precisionType(array $res): array
    {
        if (empty($res['info']['complet'])) return $res;
        $garde = [];
        foreach ($res['lignes'] as $i => $r) if (!empty($res['info']['complets'][$i])) $garde[] = $r;
        $res['lignes'] = $garde; $res['total'] = count($garde);
        return $res;
    }

    private function precisionBlocs(array $blocs): array
    {
        $complet = false;
        foreach ($blocs as $b) if (!empty($b['info']['complet'])) { $complet = true; break; }
        if (!$complet) return $blocs;
        $out = [];
        foreach ($blocs as $b) {
            if (empty($b['info']['complet'])) continue;
            $garde = [];
            foreach ($b['lignes'] as $i => $r) if (!empty($b['info']['complets'][$i])) $garde[] = $r;
            $nbComplets = count(array_filter($b['info']['complets'] ?? []));
            $b['lignes'] = $garde; $b['total'] = $nbComplets;
            if ($garde) $out[] = $b;
        }
        return $out;
    }

    /** Mots affichés : sigles en capitales (« BAES », « SSI »). */
    private function affichageMots(array $mots): string
    {
        $out = [];
        foreach ($mots as $m) {
            $m = (string)$m;
            $out[] = (strlen($m) <= 4 && isset(AssistantLangue::SYNONYMES[$m])) ? strtoupper($m) : ($this->graphies[$m] ?? $m);
        }
        return AssistantLangue::texte(implode(' ', array_filter($out, fn($x) => $x !== '')));
    }

    /** Candidats au meilleur score (tous types), dans l'ordre déterministe des blocs. */
    private function meilleurs(array $blocs, int $max = 12): array
    {
        $top = max(array_map(fn($b) => (int)$b['info']['score'], $blocs));
        $complet = false;
        foreach ($blocs as $b) if (!empty($b['info']['complet']) && (int)$b['info']['score'] === $top) $complet = true;
        $out = [];
        foreach ($blocs as $b) {
            foreach ($b['lignes'] as $i => $row) {
                $s = (int)($b['info']['scores'][$i] ?? 0);
                if ($s < $top) break;
                if ($complet && empty($b['info']['complets'][$i])) continue;
                $out[] = [$b['type'] === 'module' ? 'module:' . $b['module'] . ':' . ($b['libelle'] ?? '') : $b['type'], $row];
                if (count($out) >= $max * 2) break 2;
            }
        }
        // Un point de plan relié à un bien/équipement déjà candidat n'est que sa
        // position : un seul élément, pas deux.
        $actifs = [];
        foreach ($out as [$t, $r]) if (in_array($t, ['biens', 'equipements'], true)) $actifs[($t === 'biens' ? 'bien' : 'equipement') . ':' . (int)$r['Id']] = true;
        if ($actifs) {
            $out = array_values(array_filter($out, function ($x) use ($actifs) {
                if ($x[0] !== 'plans') return true;
                foreach ((array)($x[1]['ElementsLies'] ?? []) as $l) {
                    if (isset($actifs[strtolower((string)($l['type'] ?? '')) . ':' . (int)($l['id'] ?? 0)])) return false;
                }
                return true;
            }));
        }
        return array_slice($out, 0, $max);
    }

    private function localiserUn(string $type, array $row): array
    {
        if (str_starts_with($type, 'module:')) {
            [, $mid, $lib] = explode(':', $type, 3) + [null, null, ''];
            $desc = $this->resumeLigneModule($row);
            return ['texte' => "📍 $desc\n[MODULE:$mid:" . AssistantLangue::libelle($lib ?: 'Ouvrir le module') . ']', 'trouve' => true];
        }
        $nom = $this->nomLigne($type, $row);
        switch ($type) {
            case 'plans':
                $lieu = $this->lieuPlan($row);
                return ['texte' => "📍 **$nom** ({$this->libelleTypeElement($row)}) : **" . ($lieu ?: 'plan') . "**.\n"
                    . $this->lienPlan($row, 'Voir sur le plan'), 'trouve' => true];
            case 'stock':
                $empl = trim((string)($row['Emplacement'] ?? ''));
                return ['texte' => "📍 **$nom** : " . ($empl !== '' ? '**' . AssistantLangue::texte($empl) . '**' : 'emplacement non renseigné')
                    . ' — ' . (int)($row['Quantite'] ?? 0) . " en stock.\n" . $this->lien('stock', $row), 'trouve' => true];
            case 'archives':
                $empl = trim((string)($row['Emplacement'] ?? ''));
                return ['texte' => "📍 Dossier **" . AssistantLangue::texte($row['NumeroDossier'] ?? '') . '**'
                    . ($row['NumeroBoite'] ?? '' ? ' (boîte ' . AssistantLangue::texte($row['NumeroBoite']) . ')' : '') . ' : '
                    . ($empl !== '' ? '**' . AssistantLangue::texte($empl) . '**' : 'emplacement non renseigné') . '.', 'trouve' => true];
        }
        // Biens et équipements : position sur plan d'abord, sinon bâtiment/étage/bureau saisis.
        $loc = $this->outil('localiser', ['type' => rtrim($type, 's'), 'id' => (int)$row['Id']]);
        $num = trim((string)($row['Numero'] ?? ''));
        $tete = "📍 **$nom**" . ($num !== '' && !str_contains(AssistantLangue::norm($nom), AssistantLangue::norm($num)) ? " ($num)" : '');
        $plan = $loc['sur_plan'][0] ?? null;
        $lieuSaisi = $this->lieu($row);
        if ($plan) {
            $lieuPlan = AssistantLangue::texte(trim(($plan['BatimentNom'] ?? '') . ', ' . ($plan['EtageNom'] ?? ''), ', '));
            $bureau = trim((string)($row['NumeroBureau'] ?? ''));
            $txt = "$tete : **$lieuPlan**" . ($bureau !== '' ? ', ' . $this->bureau($bureau) : '') . ".\n"
                 . $this->lien($type, $row) . ' · [FICHE:plan:' . (int)$plan['EtageId'] . ':' . (int)$plan['ElementId'] . ':Voir sur le plan]';
        } elseif ($lieuSaisi !== '') {
            $txt = "$tete : **$lieuSaisi**.\n" . $this->lien($type, $row) . ' *(pas encore placé sur un plan)*';
        } else {
            $txt = "$tete existe, mais sa localisation n'est pas renseignée.\n" . $this->lien($type, $row);
        }
        return ['texte' => $txt, 'trouve' => true];
    }

    /**
     * « où est le bureau 204 ? » : Larka n'a pas de fiche « bureau » ; le lieu se
     * déduit des biens et équipements qui y sont rattachés (bâtiment, étage), et
     * du plan de cet étage s'il est dessiné.
     */
    private function localiserBureau(array $c): array
    {
        $f = array_intersect_key($c['filtres'], array_flip(['batiment', 'etage', 'bureau']));
        // Le mot de la question : « la salle 12 », « le local 3 », « le bureau 204 ».
        $mot = preg_match('/\b(bureau|salle|local|box|piece)\s+(?:numero\s+)?' . preg_quote(AssistantLangue::norm((string)$f['bureau']), '/') . '\b/',
            AssistantLangue::preparer($c['question']), $m) ? $m[1] : 'bureau';
        $fem = in_array($mot, ['salle', 'piece'], true);
        $nom = ($mot === 'piece' ? 'pièce' : $mot) . ' ' . AssistantLangue::texte((string)$f['bureau']);
        [$le, $Le] = $fem ? ['la', 'La'] : ['le', 'Le'];
        $lieux = [];
        foreach (['equipements', 'biens'] as $t) {
            $r = $this->lignesTrace($t, $f, [], ['limite' => 50]);
            foreach ($r['lignes'] as $row) {
                $bat = trim((string)($row['Batiment'] ?? ''));
                $niv = AssistantLangue::niveau((string)($row['Etage'] ?? ''));
                $k = AssistantLangue::norm($bat) . '|' . ($niv ?? '?');
                $lieux[$k] ??= ['batiment' => $bat, 'niveau' => $niv, 'elements' => []];
                $lieux[$k]['elements'][] = [$t, $row];
            }
        }
        if (!$lieux) {
            return ['texte' => "Je ne trouve pas $le **$nom**" . $this->descLieu(array_diff_key($f, ['bureau' => 1]))
                . " : aucun bien ni équipement n'y est rattaché dans Larka.", 'trouve' => false];
        }
        ksort($lieux);
        $blocs = [];
        foreach ($lieux as $l) {
            $ou = implode(', ', array_filter([$l['batiment'] !== '' ? '**' . AssistantLangue::texte($l['batiment']) . '**' : '',
                $l['niveau'] !== null ? '**' . AssistantLangue::libelleNiveau($l['niveau']) . '**' : '']));
            $plan = '';
            if ($l['batiment'] !== '' && $l['niveau'] !== null) {
                foreach ($this->tools->etagesPlan($l['batiment']) as $e) {
                    $n = isset($e['Niveau']) && $e['Niveau'] !== '' && $e['Niveau'] !== null ? (int)$e['Niveau'] : AssistantLangue::niveau((string)($e['EtageNom'] ?? ''));
                    if ($n === $l['niveau']) {
                        $plan = ' · [FICHE:plan:' . (int)$e['EtageId'] . ':' . AssistantLangue::libelle('Plan ' . trim(($e['EtageNom'] ?? '') . ' — ' . ($e['BatimentNom'] ?? ''), ' —')) . ']';
                        break;
                    }
                }
            }
            $els = array_map(fn($x) => '- ' . $this->lien($x[0], $x[1]), array_slice($l['elements'], 0, 5));
            if (count($l['elements']) > 5) $els[] = '… et ' . (count($l['elements']) - 5) . ' autres.';
            $au = $this->descLieu(array_filter(['batiment' => $l['batiment'], 'etage' => $l['niveau']], fn($v) => $v !== null && $v !== ''));
            $blocs[] = [$ou !== '' ? $ou : 'lieu non renseigné', $au, $plan, $els];
        }
        if (count($blocs) === 1) {
            [, $au, $plan, $els] = $blocs[0];
            $tete = $au !== '' ? "📍 $Le **$nom** se trouve$au." : "📍 $Le **$nom** existe, mais son bâtiment et son étage ne sont pas renseignés.";
            return ['texte' => $tete . ($plan !== '' ? "\n" . ltrim($plan, ' ·') : '') . "\nOn y trouve :\n" . implode("\n", $els), 'trouve' => true];
        }
        $txt = "$Le **$nom** existe dans **" . count($blocs) . "** lieux :";
        foreach ($blocs as [$ou, , $plan, $els]) $txt .= "\n📍 $ou$plan\n" . implode("\n", $els);
        return ['texte' => $txt . "\nLequel cherchez-vous ? (précisez le bâtiment)", 'trouve' => true];
    }

    private function localiserPlusieurs(array $c, array $cands, bool $pluriel, string $prefixe): array
    {
        $lignes = []; $parEtage = [];
        foreach (array_slice($cands, 0, self::MAX_LISTE) as [$type, $row]) {
            if (str_starts_with($type, 'module:')) { $lignes[] = '- ' . $this->resumeLigneModule($row); continue; }
            if ($type === 'plans') {
                $lignes[] = '- ' . $this->lienPlan($row, $this->nomLigne('plans', $row)) . ' — ' . $this->lieuPlan($row);
                $parEtage[(int)$row['EtageId']][] = [(int)$row['Id'], $this->lieuPlan($row)];
                continue;
            }
            if (in_array($type, ['biens', 'equipements'], true)) {
                $loc = $this->outil('localiser', ['type' => rtrim($type, 's'), 'id' => (int)$row['Id']]);
                $p = $loc['sur_plan'][0] ?? null;
                $ou = $p ? AssistantLangue::texte(trim(($p['BatimentNom'] ?? '') . ', ' . ($p['EtageNom'] ?? ''), ', ')) : ($this->lieu($row) ?: 'lieu non renseigné');
                $lignes[] = '- ' . $this->lien($type, $row) . " — $ou" . ($p ? ' · [FICHE:plan:' . (int)$p['EtageId'] . ':' . (int)$p['ElementId'] . ':plan]' : '');
                if ($p) $parEtage[(int)$p['EtageId']][] = [(int)$p['ElementId'], $ou];
                continue;
            }
            $lignes[] = '- ' . $this->ligneListe($type, $row);
        }
        $n = count($cands);
        $objet = $this->affichageMots(array_merge($c['mots'], $c['codes'], $c['numeros']));
        $lieu = $this->descLieu(array_intersect_key($c['filtres'], array_flip(['batiment', 'etage', 'bureau'])));
        $tete = $pluriel
            ? "**" . ($n > self::MAX_LISTE ? 'Plus de ' . self::MAX_LISTE : $n) . "** résultats pour « $objet »$lieu :"
            : "J'ai trouvé **$n** éléments qui correspondent à « $objet »$lieu :";
        $txt = $prefixe . $tete . "\n" . implode("\n", $lignes);
        if ($n > self::MAX_LISTE) $txt .= "\n… et " . ($n - self::MAX_LISTE) . ' autres.';
        // Tous sur le même étage : un lien qui les montre ensemble.
        foreach ($parEtage as $etageId => $els) {
            if (count($els) >= 2) {
                $ids = implode(',', array_unique(array_column($els, 0)));
                $txt .= "\n[FICHE:plans:$etageId:$ids:Voir les " . count($els) . ' sur le plan (' . AssistantLangue::libelle($els[0][1], 40) . ')]';
            }
        }
        if (!$pluriel) $txt .= "\nLequel cherchez-vous ? (précisez le numéro, le bâtiment ou l'étage)";
        return ['texte' => $txt, 'trouve' => true];
    }

    private function demandePluriel(array $c): bool
    {
        $q = ' ' . AssistantLangue::preparer($c['question']) . ' ';
        if (preg_match('/\b(?:ou sont|ou se trouvent|tous les|toutes les|les differents|les differentes|liste|lister|chaque)\b/', $q)) return true;
        // « les extincteurs » : article pluriel devant un mot-clé
        foreach ($c['mots'] as $m) if (preg_match('/\b(?:les|des|mes|nos|vos|ces) ' . preg_quote($m, '/') . '\b/', $q) && str_ends_with($m, 's')) return true;
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Compter
    // ═══════════════════════════════════════════════════════════════════════

    private const COMPTABLES = ['equipements', 'biens', 'stock', 'plans', 'archives', 'interventions', 'contrats', 'demandes', 'documents'];

    private function compter(array $c): array
    {
        $mots = array_merge($c['mots'], $c['codes']);
        if (!$c['type'] && !$mots) {
            return ['texte' => "Combien de quoi ? Biens, équipements, interventions, contrats, demandes, articles de stock ou éléments de plan — par exemple « combien d'équipements au bâtiment A ? ».", 'trouve' => false];
        }
        if ($c['type']) {
            $r = $this->lignesTrace($c['type'], $c['filtres'], $mots, ['limite' => 5, 'tri' => $c['tri']]);
            $n = $r['total'];
            // « combien de demandes de Paul Durand ? » : les mots désignent l'auteur.
            if ($n === 0 && ($de = $this->demandesDe($c)) !== null) return $de;
            $txt = 'Il y a **' . $n . '** ' . $this->libelleType($c['type'], $n, $c['filtres']) . $this->descQualif($c['filtres'], $c['type'], $n) . $this->descMots($mots) . $this->descLieu($c['filtres']) . '.';
            if ($n >= 1 && $n <= 5) $txt .= "\n" . $this->liste($c['type'], $r['lignes']);
            $txt .= $this->noteIgnores($r['ignores'], $c['type']);
            return ['texte' => $this->prefixeCorrections($r['corrections'] ?? []) . $txt, 'trouve' => $n > 0 || !$mots];
        }
        // Sans type : chaque type où l'objet apparaît, le plus probable d'abord.
        $trouves = [];
        foreach (self::COMPTABLES as $type) {
            // Un type auquel un filtre ne s'applique pas (bâtiment d'un contrat) fausserait le compte.
            [, $ign] = AssistantTools::filtresPour($type, $c['filtres']);
            if ($ign) continue;
            $r = $this->lignesTrace($type, $c['filtres'], $mots, ['limite' => 5]);
            if ($r['total'] > 0) $trouves[$type] = $r;
        }
        if (!$trouves) {
            return ['texte' => 'Il n\'y a **aucun** élément' . $this->descMots($mots) . $this->descFiltres($c['filtres'], null, 1) . ' dans Larka.', 'trouve' => false];
        }
        $type = array_key_first($trouves);
        $r = $trouves[$type];
        $txt = 'Il y a **' . $r['total'] . '** ' . $this->libelleType($type, $r['total'], $c['filtres']) . $this->descQualif($c['filtres'], $type, $r['total']) . $this->descMots($mots) . $this->descLieu($c['filtres']) . '.';
        if ($r['total'] <= 5) $txt .= "\n" . $this->liste($type, $r['lignes']);
        $autres = [];
        foreach (array_slice($trouves, 1, null, true) as $t => $x) $autres[] = AssistantLangue::nombre($x['total'], $t);
        if ($autres) $txt .= "\n*(le mot apparaît aussi dans : " . implode(', ', $autres) . ')*';
        return ['texte' => $this->prefixeCorrections($r['corrections'] ?? []) . $txt, 'trouve' => true];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Chercher / lister
    // ═══════════════════════════════════════════════════════════════════════

    private function chercher(array $c): array
    {
        if ($c['reference']) return $this->fiche($c);
        $mots = array_merge($c['mots'], $c['codes']);
        if ($c['type']) {
            if (!$mots && !$c['numeros']) {
                // Liste filtrée (« interventions en cours au bâtiment A », « les 5 dernières demandes »)
                $tri = $c['tri'] ?? (in_array($c['type'], ['interventions', 'demandes', 'documents'], true) ? 'recent' : null);
                $r = $this->lignesTrace($c['type'], $c['filtres'], [], ['limite' => $c['limite'] ?? self::MAX_LISTE, 'tri' => $tri]);
                $n = $r['total'];
                if ($n === 0) return ['texte' => 'Aucun' . ($this->feminin($c['type']) ? 'e ' : ' ') . AssistantLangue::LIBELLES[$c['type']][0] . $this->descFiltres($c['filtres'], $c['type'], 1) . '.' . $this->noteIgnores($r['ignores'], $c['type']), 'trouve' => true];
                $k = count($r['lignes']);
                $fem = $this->feminin($c['type']);
                if (!empty($c['limite']) && $tri) {
                    // « les 3 dernières demandes », « la prochaine intervention »
                    $adj = $tri === 'avenir' ? ($k > 1 ? ($fem ? 'prochaines' : 'prochains') : ($fem ? 'prochaine' : 'prochain'))
                                             : ($k > 1 ? ($fem ? 'dernières' : 'derniers') : ($fem ? 'dernière' : 'dernier'));
                    if ($k === 1) {
                        return ['texte' => ($fem ? 'La ' : 'Le ') . "$adj " . $this->libelleType($c['type'], 1, $c['filtres']) . $this->descFiltres($c['filtres'], $c['type'], 1)
                            . ' : ' . $this->ligneListe($c['type'], $r['lignes'][0]) . $this->noteIgnores($r['ignores'], $c['type']), 'trouve' => true];
                    }
                    $tete = "Les **$k** $adj " . $this->libelleType($c['type'], $k, $c['filtres'])
                          . $this->descFiltres($c['filtres'], $c['type'], $k) . ($n > $k ? " (sur $n)" : '') . ' :';
                    return ['texte' => $tete . "\n" . implode("\n", array_map(fn($x) => '- ' . $this->ligneListe($c['type'], $x), $r['lignes'])) . $this->noteIgnores($r['ignores'], $c['type']), 'trouve' => true];
                }
                $tete = '**' . $n . '** ' . $this->libelleType($c['type'], $n, $c['filtres']) . $this->descFiltres($c['filtres'], $c['type'], $n)
                      . ($tri === 'recent' && $n > $k ? ' (les plus récent' . ($fem ? 'es' : 's') . ' d\'abord)' : '')
                      . ($tri === 'avenir' ? ' à venir' : '') . ' :';
                return ['texte' => $tete . "\n" . $this->liste($c['type'], $r['lignes'], $n) . $this->noteIgnores($r['ignores'], $c['type']), 'trouve' => true];
            }
            if (($c['tri'] || $c['limite']) && $mots) {
                // « la prochaine intervention sur la chaudière » : filtre exact + tri par date.
                $r = $this->lignesTrace($c['type'], $c['filtres'], $mots, ['limite' => $c['limite'] ?? self::MAX_LISTE, 'tri' => $c['tri'] ?? 'recent']);
                if ($r['total'] > 0) {
                    if (($c['limite'] ?? 0) === 1) {
                        $fem = $this->feminin($c['type']);
                        $adj = ($c['tri'] ?? 'recent') === 'avenir' ? ($fem ? 'prochaine' : 'prochain') : ($fem ? 'dernière' : 'dernier');
                        return ['texte' => ($fem ? 'La ' : 'Le ') . "$adj " . $this->libelleType($c['type'], 1, $c['filtres']) . $this->descMots($mots)
                            . $this->descFiltres($c['filtres'], $c['type'], 1) . ' : ' . $this->ligneListe($c['type'], $r['lignes'][0]), 'trouve' => true];
                    }
                    return ['texte' => '**' . $r['total'] . '** ' . $this->libelleType($c['type'], $r['total'], $c['filtres']) . $this->descQualif($c['filtres'], $c['type'], $r['total'])
                        . $this->descMots($mots) . $this->descLieu($c['filtres']) . (($c['tri'] ?? '') === 'avenir' ? ' à venir' : '') . " :\n" . $this->liste($c['type'], $r['lignes'], $r['total']), 'trouve' => true];
                }
            }
            $res = $this->precisionType($this->rechercheClassee($c['type'], $this->requete($c), $c['filtres']));
            if ($res['total'] === 0) {
                // Le moteur de recherche plein texte n'a rien : on tente le filtre exact (famille, désignation…)
                $r = $this->lignesTrace($c['type'], $c['filtres'], $mots, ['limite' => self::MAX_LISTE, 'tri' => $c['tri']]);
                if ($r['total'] === 0) return $this->demandesDe($c) ?? $this->rienTrouve($c, $res['corrections']);
                $res = ['total' => $r['total'], 'lignes' => $r['lignes'], 'corrections' => $r['corrections'] ?? [], 'ignores' => $r['ignores']];
            }
            $n = $res['total'];
            // « intervention 5 », « demande 12 » : un seul élément désigné par son numéro → sa fiche.
            if ($n === 1 && !$c['mots'] && ($c['numeros'] || $c['codes'])) return ['texte' => $this->ficheTexte($c['type'], $res['lignes'][0]), 'trouve' => true];
            $txt = $this->prefixeCorrections($res['corrections']) . '**' . $n . '** ' . $this->libelleType($c['type'], $n)
                 . $this->descQualif($c['filtres'], $c['type'], $n) . $this->descMots($mots) . $this->descLieu($c['filtres']) . " :\n"
                 . $this->liste($c['type'], array_slice($res['lignes'], 0, self::MAX_LISTE), $n) . $this->noteIgnores($res['ignores'] ?? [], $c['type']);
            return ['texte' => $txt, 'trouve' => true];
        }
        if (!$mots && !$c['numeros']) {
            // « le plan du centre technique » : les étages dessinés du bâtiment.
            if (!empty($c['filtres']['batiment']) && preg_match('/\bplans?\b/', AssistantLangue::preparer($c['question']))) {
                return $this->plansBatiment($c);
            }
            if ($c['filtres']) {
                // « tout ce qu'il y a au bâtiment A » : biens et équipements du lieu ;
                // « qu'est-ce qui se passe demain ? » : interventions et demandes de la période.
                $periode = array_intersect_key($c['filtres'], array_flip(['jour', 'semaine', 'mois', 'annee']));
                $types = $periode ? ['interventions', 'demandes'] : ['equipements', 'biens'];
                $parts = []; $vus = [];
                foreach ($types as $t) {
                    [, $ign] = AssistantTools::filtresPour($t, $c['filtres']);
                    if ($ign) continue;            // un filtre qui ne s'applique pas fausserait la liste
                    $vus[] = $t;
                    $r = $this->lignesTrace($t, $c['filtres'], [], ['limite' => 5, 'tri' => $periode ? 'recent' : null]);
                    if ($r['total']) $parts[] = '**' . AssistantLangue::capitale($this->libelleType($t, $r['total'])) . '** (' . $r['total'] . ")\n" . $this->liste($t, $r['lignes'], $r['total']);
                }
                if ($parts) return ['texte' => AssistantLangue::capitale(trim($this->descFiltres($c['filtres'], null))) . " :\n" . implode("\n", $parts), 'trouve' => true];
                if ($vus) {
                    $aucun = array_map(fn($t) => 'aucun' . ($this->feminin($t) ? 'e ' : ' ') . AssistantLangue::LIBELLES[$t][0], $vus);
                    return ['texte' => AssistantLangue::capitale(trim($this->descFiltres($c['filtres'], null))) . ' : ' . implode(', ', $aucun) . '.', 'trouve' => false];
                }
            }
            return $this->incompris($c);
        }
        $res = $this->rechercheGlobale($this->requete($c), null, $c['filtres']);
        $res['blocs'] = $this->precisionBlocs($res['blocs']);
        if (!$res['blocs']) {
            // « Karim », « Sophie Martin » : un nom de personne → l'annuaire.
            if (count($c['mots']) <= 2 && !$c['filtres'] && !$c['codes']) {
                $p = $this->outil('qui_est', ['query' => implode(' ', $c['mots'])]);
                if (!empty($p['results'])) return $this->personne(['personne' => $c['mots'], 'type' => null] + $c);
            }
            return $this->rienTrouve($c, $res['corrections']);
        }
        $objet = $this->affichageMots(array_merge($mots, $c['numeros']));
        $parts = [];
        foreach (array_slice($res['blocs'], 0, 4) as $b) {
            if ($b['type'] === 'module') {
                $parts[] = '**' . AssistantLangue::texte($b['libelle']) . '** (module, ' . $b['total'] . ")\n"
                    . implode("\n", array_map(fn($r) => '- ' . $this->resumeLigneModule($r), array_slice($b['lignes'], 0, 3)))
                    . "\n[MODULE:" . $b['module'] . ':Ouvrir le module]';
                continue;
            }
            $parts[] = '**' . AssistantLangue::capitale(AssistantLangue::LIBELLES[$b['type']][1]) . '** (' . $b['total'] . ")\n"
                . $this->liste($b['type'], array_slice($b['lignes'], 0, 3), $b['total']);
        }
        $autres = array_slice($res['blocs'], 4);
        $txt = $this->prefixeCorrections($res['corrections']) . "Résultats pour « $objet »" . $this->descFiltres($c['filtres'], null) . " :\n" . implode("\n", $parts);
        if ($autres) $txt .= "\n*(aussi : " . implode(', ', array_map(fn($b) => $b['type'] === 'module' ? $b['total'] . ' dans ' . AssistantLangue::texte($b['libelle']) : AssistantLangue::nombre($b['total'], $b['type']), $autres)) . ')*';
        return ['texte' => $txt, 'trouve' => true];
    }

    /** « les demandes de Paul Durand » : les mots désignent une seule personne de l'annuaire → ses demandes. */
    private function demandesDe(array $c): ?array
    {
        if ($c['type'] !== 'demandes' || !$c['mots'] || count($c['mots']) > 3 || $c['codes']) return null;
        $p = $this->outil('qui_est', ['query' => implode(' ', $c['mots'])]);
        if (count($p['results'] ?? []) !== 1) return null;
        $qui = $p['results'][0];
        $r = $this->lignesTrace('demandes', $c['filtres'], [], ['limite' => $c['limite'] ?? self::MAX_LISTE, 'tri' => $c['tri'] ?? 'recent', 'auteur_id' => (int)$qui['Id']]);
        $nom = '**' . AssistantLangue::texte($qui['Nom'] ?? '?') . '**';
        $n = $r['total'];
        if ($n === 0) return ['texte' => "Aucune demande" . $this->descFiltres($c['filtres'], 'demandes', 1) . " faite par $nom.", 'trouve' => true];
        return ['texte' => "**$n** " . $this->libelleType('demandes', $n, $c['filtres']) . $this->descFiltres($c['filtres'], 'demandes', $n)
            . ' faite' . ($n > 1 ? 's' : '') . " par $nom :\n" . $this->liste('demandes', $r['lignes'], $n) . $this->noteIgnores($r['ignores'], 'demandes'), 'trouve' => true];
    }

    /** « le plan du centre technique », « le plan du RDC du bâtiment A » : les étages dessinés. */
    private function plansBatiment(array $c): array
    {
        $bat = (string)$c['filtres']['batiment'];
        $niv = isset($c['filtres']['etage']) && $c['filtres']['etage'] !== '' ? (int)$c['filtres']['etage'] : null;
        $et = $this->tools->etagesPlan($bat);
        $this->tracer('plans', array_filter(['batiment' => $bat, 'etage' => $niv], fn($v) => $v !== null), count($et));
        $b = AssistantLangue::texte($bat);
        $nomme = preg_match('/^(?:batiment|bat)\b/', AssistantLangue::preparer($b));
        $de = $nomme ? "du **$b**" : "du bâtiment **$b**";
        if (!$et) return ['texte' => "Aucun plan n'est dessiné pour le " . ($nomme ? "**$b**" : "bâtiment **$b**") . '.', 'trouve' => true];
        $lien = fn(array $e) => '[FICHE:plan:' . (int)$e['EtageId'] . ':' . AssistantLangue::libelle(trim(($e['EtageNom'] ?? '') . ' — ' . ($e['BatimentNom'] ?? ''), ' —')) . ']';
        if ($niv !== null) {
            $ok = array_values(array_filter($et, fn($e) => (isset($e['Niveau']) && $e['Niveau'] !== '' && $e['Niveau'] !== null
                ? (int)$e['Niveau'] : AssistantLangue::niveau((string)($e['EtageNom'] ?? ''))) === $niv));
            $etage = AssistantLangue::libelleNiveau($niv);
            if ($ok) return ['texte' => "Plan du **$etage** $de :\n" . implode(' · ', array_map($lien, $ok)), 'trouve' => true];
            return ['texte' => "Pas de plan dessiné pour le **$etage** $de. Plans existants :\n" . implode(' · ', array_map($lien, $et)), 'trouve' => true];
        }
        return ['texte' => "Plans $de :\n" . implode(' · ', array_map($lien, $et)), 'trouve' => true];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Fiche, statut, dates
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * L'élément que désigne la question : référence de la réponse précédente,
     * identifiant (#12, demande 12), code (EXT-002, INT-2026-003) ou recherche.
     * @return array{0:?string,1:?array,2:array} [type, ligne, candidats si ambigu]
     */
    private function cible(array $c, ?array $types = null): array
    {
        if ($c['reference']) {
            $row = $this->ligneParId($c['reference']['type'], $c['reference']['id']);
            if ($row) return [$c['reference']['type'], $row, []];
        }
        if ($c['id']) {
            $type = $c['type'] ?? 'demandes';
            $row = $this->ligneParId($type, (int)$c['id']);
            if ($row) return [$type, $row, []];
        }
        $mots = array_merge($c['mots'], $c['codes']);
        if (!$mots && !$c['numeros']) return [null, null, []];
        $types ??= $c['type'] ? [$c['type']] : ['equipements', 'biens', 'interventions', 'contrats', 'demandes', 'stock', 'documents', 'plans', 'archives'];
        $res = $this->rechercheGlobale($this->requete($c), $types, $c['filtres'], false);
        if (!$res['blocs']) return [null, null, []];
        // Code exact (« EXT-002 ») : correspondance directe sur le numéro/la référence.
        foreach ($c['codes'] as $code) {
            $k = preg_replace('/[^a-z0-9]/', '', AssistantLangue::norm($code));
            foreach ($res['blocs'] as $b) foreach ($b['lignes'] as $row) {
                foreach (['Numero', 'Reference', 'NumeroDossier'] as $col) {
                    if (!empty($row[$col]) && preg_replace('/[^a-z0-9]/', '', AssistantLangue::norm((string)$row[$col])) === $k) return [$b['type'], $row, []];
                }
            }
        }
        $cands = $this->meilleurs($res['blocs'], 6);
        $cands = array_values(array_filter($cands, fn($x) => !str_starts_with($x[0], 'module:')));
        if (count($cands) === 1) return [$cands[0][0], $cands[0][1], []];
        return [null, null, $cands];
    }

    private function fiche(array $c): array
    {
        [$type, $row, $cands] = $this->cible($c);
        if (!$row) {
            if ($cands) return $this->choix($c, $cands);
            if (!$c['mots'] && !$c['codes'] && !$c['id'] && !$c['numeros']) {
                if ($c['type']) return $this->chercher($c);
                return ['texte' => "De quel élément voulez-vous la fiche ? Donnez son numéro (ex. « INT-2026-003 », « EXT-002 », « demande 12 ») ou son nom.", 'trouve' => false];
            }
            return $this->rienTrouve($c, []);
        }
        return ['texte' => $this->ficheTexte($type, $row), 'trouve' => true];
    }

    private function choix(array $c, array $cands): array
    {
        $l = array_map(fn($x) => '- ' . $this->ligneListe($x[0], $x[1]), array_slice($cands, 0, self::MAX_LISTE));
        return ['texte' => "Plusieurs éléments correspondent :\n" . implode("\n", $l) . "\nLequel voulez-vous ? (répondez par exemple « le 2e » ou donnez son numéro)", 'trouve' => true];
    }

    private function ficheTexte(string $type, array $row): string
    {
        $f = $type === 'documents' ? $row : ($this->tools->fiche($type, (int)$row['Id']) ?? $row);
        $f += $row;
        $L = []; $d = fn($v) => AssistantLangue::date((string)$v);
        $v = fn(string $k) => trim((string)($f[$k] ?? ''));
        switch ($type) {
            case 'equipements':
            case 'biens':
                $tete = '**' . $this->nomLigne($type, $f) . '**' . ($v('Numero') !== '' ? ' (' . AssistantLangue::texte($v('Numero')) . ')' : '');
                $fam = trim($v('Famille') . ($v('SousFamille') !== '' ? ' / ' . $v('SousFamille') : ''));
                if ($fam !== '') $tete .= ' — ' . AssistantLangue::texte($fam);
                $L[] = $tete;
                if ($v('Marque') . $v('Modele') !== '') $L[] = '- Marque / modèle : ' . AssistantLangue::texte(trim($v('Marque') . ' ' . $v('Modele')));
                $lieu = $this->lieu($f); if ($lieu !== '') $L[] = '- Lieu : ' . $lieu;
                if ($v('Etat') . $v('Statut') !== '') $L[] = '- État : ' . AssistantLangue::texte(implode(' · ', array_filter([$v('Etat'), $v('Statut')])));
                if ($v('NumeroSerie') !== '') $L[] = '- N° de série : ' . AssistantLangue::texte($v('NumeroSerie'));
                if ($v('DateInstallation') !== '') $L[] = '- Installé le ' . $d($v('DateInstallation'));
                elseif ($v('DateLivraison') !== '') $L[] = '- Livré le ' . $d($v('DateLivraison'));
                if ($v('Fournisseur') !== '') $L[] = '- Fournisseur : ' . AssistantLangue::texte($v('Fournisseur'));
                if ($v('Observations') !== '') $L[] = '- Observations : ' . AssistantLangue::texte($v('Observations'), 200);
                $loc = $this->outil('localiser', ['type' => rtrim($type, 's'), 'id' => (int)$f['Id']]);
                $p = $loc['sur_plan'][0] ?? null;
                $L[] = $this->lien($type, $f) . ($p ? ' · [FICHE:plan:' . (int)$p['EtageId'] . ':' . (int)$p['ElementId'] . ':Voir sur le plan]' : '');
                break;
            case 'interventions':
                $L[] = '**' . AssistantLangue::texte($v('Numero') ?: '#' . $f['Id']) . '** — ' . AssistantLangue::texte($v('Type') ?: 'intervention')
                     . ', **' . AssistantLangue::texte($this->statutAffiche($type, $v('Statut'))) . '**' . ($v('Priorite') !== '' ? ', priorité ' . AssistantLangue::texte(mb_strtolower($v('Priorite'))) : '');
                if ($v('Description') !== '') $L[] = '- ' . AssistantLangue::texte($v('Description'), 220);
                $dates = [];
                if ($v('DateIntervention') !== '') $dates[] = 'prévue le ' . $d($v('DateIntervention'));
                if ($v('DateRealisation') !== '') $dates[] = 'réalisée le ' . $d($v('DateRealisation'));
                if ($v('DateProchaine') !== '') $dates[] = 'prochaine le ' . $d($v('DateProchaine'));
                if ($dates) $L[] = '- ' . AssistantLangue::capitale(implode(', ', $dates));
                if ($v('SocieteManuelle') !== '') $L[] = '- Prestataire : ' . AssistantLangue::texte($v('SocieteManuelle'));
                $el = $this->elementsIntervention($f);
                if ($el) $L[] = '- Concerne : ' . implode(', ', $el);
                $L[] = $this->lien($type, $f);
                break;
            case 'contrats':
                $L[] = '**' . AssistantLangue::texte($v('Numero') ?: '#' . $f['Id']) . '** — ' . AssistantLangue::texte($v('Societe'))
                     . ($v('Type') !== '' ? ' (' . AssistantLangue::texte($v('Type')) . ')' : '') . ', **' . AssistantLangue::texte($v('Statut') ?: '?') . '**';
                if ($v('Description') !== '') $L[] = '- ' . AssistantLangue::texte($v('Description'), 220);
                if ($v('DateDebut') . $v('DateFin') !== '') $L[] = '- Du ' . ($d($v('DateDebut')) ?: '?') . ' au ' . ($d($v('DateFin')) ?: '?') . $this->dansJours($v('DateFin'));
                if ((float)($f['MontantAnnuel'] ?? 0) > 0) $L[] = '- Montant annuel : ' . AssistantLangue::montant($f['MontantAnnuel']);
                if ($v('Frequence') !== '') $L[] = '- Fréquence : ' . AssistantLangue::texte($v('Frequence'));
                $L[] = $this->lien($type, $f);
                break;
            case 'demandes':
                $L[] = '**#' . (int)$f['Id'] . ' ' . AssistantLangue::texte($v('Titre')) . '** — **' . AssistantLangue::texte($v('Statut') ?: '?') . '**'
                     . ($v('Urgence') !== '' ? ', ' . AssistantLangue::texte($this->urgenceTexte($v('Urgence'))) : '');
                if ($v('Description') !== '') $L[] = '- ' . AssistantLangue::texte($v('Description'), 220);
                $lieu = AssistantLangue::texte(implode(', ', array_filter([$v('Batiment'), $v('Bureau')])));
                if ($lieu !== '') $L[] = '- Lieu : ' . $lieu;
                if ($v('Categorie') !== '') $L[] = '- Catégorie : ' . AssistantLangue::texte($v('Categorie'));
                if ($v('DateCreation') !== '') $L[] = '- Créée le ' . $d($v('DateCreation'));
                $iv = $this->interventionDeDemande((int)$f['Id']);
                if ($iv) $L[] = '- Intervention liée : ' . $this->lien('interventions', $iv) . ' (' . AssistantLangue::texte($this->statutAffiche('interventions', (string)($iv['Statut'] ?? ''))) . ($iv['DateIntervention'] ?? '' ? ', prévue le ' . $d($iv['DateIntervention']) : '') . ')';
                $L[] = $this->lien($type, $f);
                break;
            case 'stock':
                $q = (int)($f['Quantite'] ?? 0); $s = (int)($f['SeuilAlerte'] ?? 0);
                $L[] = '**' . AssistantLangue::texte($v('Designation')) . '**' . ($v('Reference') !== '' ? ' (' . AssistantLangue::texte($v('Reference')) . ')' : '')
                     . ' : **' . $q . '** en stock' . ($s ? " (seuil $s)" : '') . ($s && $q <= $s ? ' ⚠️ sous le seuil' : '');
                if ($v('Emplacement') !== '') $L[] = '- Emplacement : ' . AssistantLangue::texte($v('Emplacement'));
                if ($v('Categorie') !== '') $L[] = '- Catégorie : ' . AssistantLangue::texte($v('Categorie'));
                if ((float)($f['PrixUnitaire'] ?? 0) > 0) $L[] = '- Prix unitaire : ' . AssistantLangue::montant($f['PrixUnitaire']);
                $L[] = $this->lien($type, $f);
                break;
            case 'documents':
                $L[] = $this->lien('documents', $f) . ' — ' . AssistantLangue::texte(implode(', ', array_filter([$v('Categorie'), $v('DateAjout') !== '' ? 'ajouté le ' . $d($v('DateAjout')) : '', $v('EntiteType') !== '' ? 'lié à : ' . $v('EntiteType') . ' #' . $v('EntiteId') : ''])));
                break;
            case 'plans':
                $L[] = '**' . $this->nomLigne('plans', $f) . '** (' . $this->libelleTypeElement($f) . ') — ' . $this->lieuPlan($f);
                if ($v('Description') !== '') $L[] = '- ' . AssistantLangue::texte($v('Description'), 200);
                if (!empty($f['SurfaceM2'])) $L[] = '- Surface : ' . str_replace('.', ',', (string)round((float)$f['SurfaceM2'], 1)) . ' m²';
                if (!empty($f['LongueurM'])) $L[] = '- Longueur : ' . str_replace('.', ',', (string)round((float)$f['LongueurM'], 1)) . ' m';
                $L[] = $this->lienPlan($f, 'Voir sur le plan');
                break;
            case 'archives':
                $L[] = 'Dossier **' . AssistantLangue::texte($v('NumeroDossier')) . '**' . ($v('Description') !== '' ? ' — ' . AssistantLangue::texte($v('Description')) : '');
                foreach (['Service' => 'Service', 'NumeroBoite' => 'Boîte', 'Emplacement' => 'Emplacement', 'Statut' => 'Statut', 'Annee' => 'Année'] as $k => $lib) {
                    if ($v($k) !== '') $L[] = "- $lib : " . AssistantLangue::texte($v($k));
                }
                break;
        }
        return implode("\n", $L);
    }

    private function quand(array $c): array
    {
        [$type, $row, $cands] = $this->cible($c);
        if (!$row && $cands && $c['type'] === 'interventions' && in_array($c['tri'] ?? null, ['recent', 'avenir'], true)) {
            // « quand a été faite la dernière intervention sur l'ascenseur ? » : la plus récente déjà
            // passée (ou, pour « la prochaine », la plus proche à venir), pas un choix à faire.
            $r = $this->lignesTrace('interventions', $c['filtres'], array_merge($c['mots'], $c['codes']), ['brut' => true]);
            $auj = date('Y-m-d'); $garde = null; $dGarde = null;
            foreach ($r['lignes'] as $l) {
                $dt = substr((string)(($l['DateRealisation'] ?? '') ?: ($l['DateIntervention'] ?? '')), 0, 10);
                if ($dt === '') continue;
                if ($c['tri'] === 'recent' ? ($dt <= $auj && ($dGarde === null || $dt > $dGarde)) : ($dt >= $auj && ($dGarde === null || $dt < $dGarde))) {
                    $garde = $l; $dGarde = $dt;
                }
            }
            if ($garde) [$type, $row] = ['interventions', $garde];
        }
        if (!$row && !$cands && $c['type'] && !$c['mots'] && !$c['codes']) {
            // « quand est la prochaine intervention ? » : la plus proche dans le temps.
            $tri = $c['tri'] ?? 'avenir';
            $r = $this->lignesTrace($c['type'], $c['filtres'], [], ['limite' => 1, 'tri' => $tri]);
            if (!$r['lignes']) return ['texte' => 'Aucun' . ($this->feminin($c['type']) ? 'e ' : ' ') . AssistantLangue::LIBELLES[$c['type']][0] . ($tri === 'avenir' ? ' à venir' : '') . $this->descFiltres($c['filtres'], $c['type'], 1) . '.', 'trouve' => true];
            [$type, $row] = [$c['type'], $r['lignes'][0]];
        }
        if (!$row) return $cands ? $this->choix($c, $cands) : $this->rienTrouve($c, []);
        $q = AssistantLangue::preparer($c['question']);
        $d = fn($x) => AssistantLangue::date((string)$x);
        $f = $this->tools->fiche($type, (int)$row['Id']) ?? $row;
        $f += $row;
        $nom = $this->lien($type, $f);
        switch ($type) {
            case 'contrats':
                if (preg_match('/\b(?:debut|commence\w*|depuis|signe\w*)\b/', $q)) return ['texte' => "Le contrat $nom a commencé le **" . $d($f['DateDebut'] ?? '') . '**.', 'trouve' => true];
                $fin = (string)($f['DateFin'] ?? '');
                if ($fin === '') return ['texte' => "Le contrat $nom n'a pas de date de fin renseignée.", 'trouve' => true];
                $passe = substr($fin, 0, 10) < date('Y-m-d');
                return ['texte' => "Le contrat $nom " . ($passe ? 'a expiré le' : 'expire le') . ' **' . $d($fin) . '**' . $this->dansJours($fin) . '.', 'trouve' => true];
            case 'interventions':
                $parts = [];
                if (!empty($f['DateIntervention'])) $parts[] = (substr((string)$f['DateIntervention'], 0, 10) < date('Y-m-d') ? 'était prévue le **' : 'est prévue le **') . $d($f['DateIntervention']) . '**' . $this->dansJours((string)$f['DateIntervention']);
                if (!empty($f['DateRealisation'])) $parts[] = 'a été réalisée le **' . $d($f['DateRealisation']) . '**';
                if (!empty($f['DateProchaine'])) $parts[] = 'prochaine occurrence le **' . $d($f['DateProchaine']) . '**';
                return ['texte' => "L'intervention $nom " . ($parts ? implode(', ', $parts) : "n'a pas de date renseignée") . '.' . ($this->statutAffiche($type, (string)($f['Statut'] ?? '')) ? ' Statut : **' . AssistantLangue::texte($this->statutAffiche($type, (string)$f['Statut'])) . '**.' : ''), 'trouve' => true];
            case 'demandes':
                return ['texte' => "La demande $nom a été créée le **" . $d($f['DateCreation'] ?? '') . '** (statut **' . AssistantLangue::texte($f['Statut'] ?? '?') . '**).', 'trouve' => true];
            default:
                return ['texte' => $this->ficheTexte($type, $f), 'trouve' => true];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Alertes, stock, sommes
    // ═══════════════════════════════════════════════════════════════════════

    private function alertes(array $c): array
    {
        $q = ' ' . AssistantLangue::preparer($c['question']) . ' ';
        $cat = match (true) {
            $c['type'] === 'contrats' || (bool)preg_match('/\b(?:expir\w*|echeance\w*|echu\w*|renouvel\w*|fin de contrat)\b/', $q) => 'contrats',
            $c['type'] === 'stock' || (bool)preg_match('/\b(?:seuil|rupture\w*|stock|manqu\w*|a commander|reappro\w*)\b/', $q) => 'stock',
            $c['type'] === 'interventions' || (bool)preg_match('/\bretard\w*\b/', $q) => 'interventions',
            $c['type'] === 'demandes' || (bool)preg_match('/\b(?:non trait\w*|a traiter)\b/', $q) && (bool)preg_match('/\bdemandes?\b/', $q) => 'demandes',
            default => 'toutes',
        };
        // Horizon : celui de la question s'il est dit, sinon la fenêtre d'alerte propre à chaque contrat.
        $jours = $c['jours'];
        if ($jours === null && isset($c['filtres']['annee']) && $cat === 'contrats' && (int)$c['filtres']['annee'] === (int)date('Y')) {
            $jours = max(1, (int)((strtotime(date('Y') . '-12-31') - strtotime(date('Y-m-d'))) / 86400));
        }
        $r = $this->outilBrut('alertes', ['categorie' => $cat, 'jours' => $jours]);
        // Filtre par mots-clés (« stock de plomberie en alerte ») : seulement des mots
        // réellement présents dans les données du site, jamais un verbe resté en route.
        $vocab = $this->tools->vocabulaireSite()['mots'] ?? [];
        $mots = array_values(array_filter($c['mots'], function ($w) use ($vocab) {
            if (isset($vocab[$w])) return true;
            foreach (AssistantTools::singuliers($w) as $s) if (isset($vocab[$s])) return true;
            return false;
        }));
        $filtre = function (array $items) use ($mots): array {
            if (!$mots) return $items;
            $t = $this->tools->terms(implode(' ', $mots));
            return array_values(array_filter($items, function ($it) use ($t) {
                $hay = ' ' . AssistantLangue::norm(implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $it))) . ' ';
                foreach ($t['groups'] as $g) {
                    $ok = false;
                    foreach ($g as $k) if (str_contains($hay, ' ' . AssistantLangue::norm($k))) { $ok = true; break; }
                    if (!$ok) return false;
                }
                return true;
            }));
        };
        // Lieu (« qu'est-ce qui est urgent au bâtiment B ? ») : seules les alertes qui ont un lieu
        // — interventions (par leurs équipements) et demandes — et qui s'y trouvent.
        $lieu = array_intersect_key($c['filtres'], array_flip(['batiment', 'etage', 'bureau']));
        $idsLieu = [];
        if ($lieu) foreach (['interventions', 'demandes'] as $t) {
            [, $ign] = AssistantTools::filtresPour($t, $lieu);
            if (!$ign) $idsLieu[$t] = array_flip(array_map(fn($x) => (int)$x['Id'], $this->tools->lignes($t, $lieu, [], ['brut' => true])['lignes']));
        }
        $dansLieu = function (string $t, array $items) use ($lieu, $idsLieu): array {
            if (!$lieu) return $items;
            if (!isset($idsLieu[$t])) return [];
            return array_values(array_filter($items, fn($it) => isset($idsLieu[$t][(int)($it['Id'] ?? $it['id'] ?? 0)])));
        };
        // Contrats et stock n'ont pas de lieu : écartés d'une vue « au bâtiment B », sauf demandés nommément.
        $sansLieu = $lieu && $cat === 'toutes';
        $blocs = []; $total = 0;
        $d = fn($x) => AssistantLangue::date((string)$x);
        if (isset($r['contrats_expirent']) && !$sansLieu) {
            $it = $filtre($r['contrats_expirent']['items_complets'] ?? $r['contrats_expirent']['items'] ?? []);
            $n = count($it); $total += $n;
            if ($n || $cat === 'contrats') {
                $l = array_map(fn($x) => $this->lien('contrats', ['Id' => $x['id'], 'Numero' => $x['numero'] ?? '', 'Societe' => $x['societe'] ?? ''])
                    . ' — fin le ' . $d($x['date_fin'] ?? '') . $this->dansJours((string)($x['date_fin'] ?? '')), array_slice($it, 0, self::MAX_LISTE));
                $blocs[] = '**' . $n . '** ' . AssistantLangue::pluriel($n, 'contrat') . ' ' . AssistantLangue::pluriel($n, 'arrive', 'arrivent') . ' à échéance' . ($jours ? " d'ici $jours jours" : '') . ($n ? " :\n" . implode("\n", array_map(fn($s) => "- $s", $l)) : '.');
            }
        }
        if (isset($r['stock_en_alerte']) && !$sansLieu) {
            $it = $filtre($r['stock_en_alerte']['items_complets'] ?? $r['stock_en_alerte']['items'] ?? []);
            $n = count($it); $total += $n;
            if ($n || $cat === 'stock') {
                $l = array_map(fn($x) => $this->lien('stock', ['Id' => $x['id'], 'Designation' => $x['designation'] ?? '']) . ' — **' . (int)($x['quantite'] ?? 0) . '** en stock (seuil ' . (int)($x['seuil'] ?? 0) . ')', array_slice($it, 0, self::MAX_LISTE));
                $blocs[] = '**' . $n . '** ' . AssistantLangue::pluriel($n, 'article') . ' sous le seuil' . ($n ? " :\n" . implode("\n", array_map(fn($s) => "- $s", $l)) : '.');
            }
        }
        if (isset($r['interventions_en_retard'])) {
            $it = $dansLieu('interventions', $filtre($r['interventions_en_retard']['items_complets'] ?? $r['interventions_en_retard']['items'] ?? []));
            $n = count($it); $total += $n;
            if ($n || $cat === 'interventions') {
                $l = array_map(fn($x) => $this->lien('interventions', $x) . ' — ' . AssistantLangue::texte(mb_strtolower((string)($x['Statut'] ?? ''))) . ', prévue le ' . $d($x['DateIntervention'] ?? '') . ($x['Description'] ?? '' ? ' : ' . AssistantLangue::texte($x['Description'], 70) : ''), array_slice($it, 0, self::MAX_LISTE));
                $blocs[] = '**' . $n . '** ' . AssistantLangue::pluriel($n, 'intervention') . ' en retard' . ($n ? " :\n" . implode("\n", array_map(fn($s) => "- $s", $l)) : '.');
            }
        }
        if (isset($r['demandes_non_traitees'])) {
            $it = $dansLieu('demandes', $filtre($r['demandes_non_traitees']['items_complets'] ?? $r['demandes_non_traitees']['items'] ?? []));
            $n = count($it); $total += $n;
            if ($n || $cat === 'demandes') {
                $l = array_map(fn($x) => $this->lien('demandes', $x) . ' — ' . implode(' · ', array_filter([$this->urgenceTexte((string)($x['Urgence'] ?? '')), AssistantLangue::texte($x['Statut'] ?? ''), 'depuis le ' . $d($x['DateCreation'] ?? '')])), array_slice($it, 0, self::MAX_LISTE));
                $blocs[] = '**' . $n . '** ' . AssistantLangue::pluriel($n, 'demande') . ' non ' . AssistantLangue::pluriel($n, 'traitée') . ($n ? " :\n" . implode("\n", array_map(fn($s) => "- $s", $l)) : '.');
            }
        }
        if ($total === 0 && $cat === 'toutes') {
            return ['texte' => '✅ Aucune alerte' . ($mots ? $this->descMots($mots) : '') . $this->descLieu($lieu)
                . ($sansLieu ? " : pas d'intervention en retard ni de demande en attente."
                             : ' : pas de contrat à échéance' . ($jours ? " sous $jours jours" : '') . ', de stock sous le seuil, d\'intervention en retard ni de demande en attente.'), 'trouve' => true];
        }
        $tete = $cat === 'toutes' ? '⚠️ **Alertes**' . $this->descLieu($lieu) . "\n" : '';
        $note = $lieu && in_array($cat, ['contrats', 'stock'], true) ? "\n*(le lieu ne s'applique pas aux " . ($cat === 'contrats' ? 'contrats' : 'articles de stock') . ')*' : '';
        return ['texte' => $tete . implode("\n", $blocs) . $note, 'trouve' => true];
    }

    private function stock(array $c): array
    {
        $mots = array_merge($c['mots'], $c['codes']);
        $r = $this->lignesTrace('stock', $c['filtres'], $mots, ['limite' => self::MAX_LISTE]);
        if ($r['total'] === 0) {
            // « combien d'écrans en stock » : ce sont peut-être des biens à l'état « Stock ».
            foreach (['biens', 'equipements'] as $t) {
                $b = $this->lignesTrace($t, $c['filtres'] + ['etat' => 'stock'], $mots, ['limite' => 5]);
                if ($b['total'] > 0) {
                    return ['texte' => 'Il y a **' . $b['total'] . '** ' . $this->libelleType($t, $b['total']) . $this->descMots($mots) . " à l'état « stock » :\n" . $this->liste($t, $b['lignes'], $b['total']), 'trouve' => true];
                }
            }
            return $this->rienTrouve($c + ['type' => 'stock'], $r['corrections'] ?? []);
        }
        $L = [];
        foreach ($r['lignes'] as $x) {
            $q = (int)($x['Quantite'] ?? 0); $s = (int)($x['SeuilAlerte'] ?? 0);
            $L[] = '- ' . $this->lien('stock', $x) . " : **$q** en stock" . ($s ? " (seuil $s)" : '') . ($s && $q <= $s ? ' ⚠️' : '')
                 . (!empty($x['Emplacement']) ? ' — ' . AssistantLangue::texte($x['Emplacement']) : '');
        }
        $tot = $r['total'];
        if ($tot === 1) $L[0] = substr($L[0], 2);
        $tete = $tot === 1 ? '' : '**' . $tot . "** articles correspondent" . $this->descMots($mots) . " :\n";
        if ($tot > count($r['lignes'])) $L[] = '… et ' . ($tot - count($r['lignes'])) . ' autres.';
        return ['texte' => $this->prefixeCorrections($r['corrections'] ?? []) . $tete . implode("\n", $L), 'trouve' => true];
    }

    private function somme(array $c): array
    {
        $type = $c['type'] ?? 'contrats';
        if (!in_array($type, ['contrats', 'interventions', 'stock'], true)) $type = 'contrats';
        $mots = array_merge($c['mots'], $c['codes']);
        $filtres = $c['filtres'];
        $defaut = '';
        if ($type === 'contrats' && !isset($filtres['statut'])) { $filtres['statut'] = 'actif'; $defaut = ' actifs'; }
        $r = $this->lignesTrace($type, $filtres, $mots, ['brut' => true]);
        $s = 0.0;
        foreach ($r['lignes'] as $x) {
            $s += match ($type) {
                'contrats'      => (float)($x['MontantAnnuel'] ?? 0),
                'interventions' => (float)($x['Montant'] ?? 0) + (float)($x['MontantHT'] ?? 0) + (float)($x['MontantPieces'] ?? 0) + (float)($x['MontantMainOeuvre'] ?? 0),
                'stock'         => (float)($x['Quantite'] ?? 0) * (float)($x['PrixUnitaire'] ?? 0),
            };
        }
        $n = $r['total'];
        $txt = match (true) {
            $type === 'contrats' && $n === 1 => 'Montant annuel du contrat ' . $this->lien('contrats', $r['lignes'][0]) . ' : **' . AssistantLangue::montant($s) . '**.',
            $type === 'contrats' => "Montant annuel des **$n** contrats$defaut" . $this->descMots($mots) . $this->descFiltres(array_diff_key($c['filtres'], ['statut' => 1]), $type) . ' : **' . AssistantLangue::montant($s) . '**.',
            $type === 'interventions' => "Montant total des **$n** interventions" . $this->descMots($mots) . $this->descFiltres($c['filtres'], $type) . ' : **' . AssistantLangue::montant($s) . '**.',
            default => "Valeur du stock (**$n** articles" . $this->descMots($mots) . ') : **' . AssistantLangue::montant($s) . '**.',
        };
        return ['texte' => $txt . $this->noteIgnores($r['ignores'], $type), 'trouve' => true];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Personnes (annuaire professionnel)
    // ═══════════════════════════════════════════════════════════════════════

    private function personne(array $c): array
    {
        // « qui a fait la demande 12 ? » : l'auteur de la demande.
        if ($c['auteur'] || ($c['type'] === 'demandes' && ($c['id'] || $c['reference']))) {
            [$type, $row] = $this->cible($c + ['type' => 'demandes'], ['demandes']);
            if ($row && $type === 'demandes') {
                $f = $this->tools->fiche('demandes', (int)$row['Id']) ?? $row;
                $u = !empty($f['UtilisateurId']) ? $this->outil('qui_est', ['id' => (int)$f['UtilisateurId']]) : [];
                $p = $u['result'] ?? null;
                return ['texte' => 'La demande ' . $this->lien('demandes', $f) . ' a été faite par '
                    . ($p ? $this->personneTexte($p) : 'un utilisateur qui n\'est plus dans l\'annuaire') . '.', 'trouve' => true];
            }
        }
        // Intervention, contrat : l'intervenant et le contact sont masqués (RGPD) —
        // y compris « qui est le technicien de l'intervention INT-2026-003 ? ».
        if (in_array($c['type'], ['interventions', 'contrats'], true)
            && ($c['codes'] || $c['id'] || $c['reference'] || (!$c['personne'] && !$c['role']))) {
            [$type, $row] = $this->cible($c);
            if ($row) {
                $qui = $type === 'contrats' ? 'Le contact du contrat' : "L'intervenant";
                return ['texte' => "$qui n'est pas communiqué à l'assistant (données personnelles masquées). Consultez la fiche : " . $this->lien($type, $row), 'trouve' => true];
            }
        }
        $query = implode(' ', $c['personne']);
        $args = array_filter(['query' => $query, 'role' => $c['role']]);
        $r = $this->outil('qui_est', $args);
        $res = $r['results'] ?? [];
        if (!$res) {
            return ['texte' => 'Personne ne correspond' . ($query !== '' ? ' à « ' . AssistantLangue::texte($query) . ' »' : '') . ($c['role'] ? ' (rôle ' . $c['role'] . ')' : '') . " dans l'annuaire.", 'trouve' => $query === '' && !$c['role'] ? false : false];
        }
        if (count($res) === 1) return ['texte' => $this->personneTexte($res[0]) . '.', 'trouve' => true];
        $n = (int)($r['count'] ?? count($res));
        $L = array_map(fn($p) => '- ' . $this->personneTexte($p), $res);
        if ($n > count($res)) $L[] = '… et ' . ($n - count($res)) . ' autres.';
        return ['texte' => "**$n** " . AssistantLangue::pluriel($n, 'personne') . ($c['role'] ? ' (' . mb_strtolower($c['role']) . ')' : '') . " :\n" . implode("\n", $L), 'trouve' => true];
    }

    private function personneTexte(array $p): string
    {
        $det = array_filter([$p['Role'] ?? '', $p['Service'] ?? '', $p['Poste'] ?? '',
            ($p['TelPro'] ?? '') !== '' ? '☎ ' . $p['TelPro'] : '', ($p['Bureau'] ?? '') !== '' ? 'bureau ' . $p['Bureau'] : '']);
        return '**' . AssistantLangue::texte($p['Nom'] ?? '?') . '**' . ($det ? ' — ' . AssistantLangue::texte(implode(' · ', $det), 200) : '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Rien trouvé
    // ═══════════════════════════════════════════════════════════════════════

    private function rienTrouve(array $c, array $corrections): array
    {
        $objet = $this->affichageMots(array_merge($c['mots'], $c['codes'], $c['numeros']));
        $ou = $this->descFiltres($c['filtres'], $c['type'], 1);
        $quoi = $c['type'] ? AssistantLangue::LIBELLES[$c['type']][0] : 'élément';
        $aucun = $c['type'] && $this->feminin($c['type']) ? 'aucune' : 'aucun';
        $txt = $this->prefixeCorrections($corrections) . "Je n'ai trouvé $aucun $quoi" . ($objet !== '' ? " correspondant à « $objet »" : '') . "$ou dans Larka.\n"
             . "Essayez un autre mot (famille, numéro, bâtiment) — par exemple « extincteur », « EXT-002 » ou « bâtiment A ».";
        return ['texte' => $txt, 'trouve' => false];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Accès aux outils (tracés pour le suivi en direct)
    // ═══════════════════════════════════════════════════════════════════════

    private function tracer(string $nom, array $args, int $taille): void
    {
        $this->etapes[] = ['name' => $nom, 'args' => $args, 'result_size' => $taille];
        if ($this->suivi) {
            ($this->suivi)('tool_call', ['name' => $nom, 'args' => $args]);
            ($this->suivi)('tool_result', ['name' => $nom, 'size' => $taille]);
        }
    }

    private function outil(string $nom, array $args): array
    {
        $r = $this->tools->execute($nom, $args);
        $this->tracer($nom, $args, (int)($r['count'] ?? $r['total'] ?? (isset($r['error']) ? 0 : 1)));
        return $r;
    }

    /** Alertes avec toutes les lignes (le moteur filtre lui-même par mots-clés). */
    private function outilBrut(string $nom, array $args): array
    {
        $r = $this->tools->alertesCompletes($args);
        $this->tracer($nom, $args, array_sum(array_map(fn($b) => (int)($b['count'] ?? 0), $r)));
        return $r;
    }

    private function lignesTrace(string $type, array $filtres, array $mots, array $options = []): array
    {
        $r = $this->tools->lignes($type, $filtres, $mots, $options);
        $this->tracer('compter', ['type' => $type] + ($mots ? ['mots' => implode(' ', $mots)] : []) + $filtres, (int)$r['total']);
        return $r;
    }

    private function rechercheClassee(string $type, string $query, array $filtres): array
    {
        $r = $this->tools->rechercheClassee($type, $query, $filtres);
        $this->tracer('search', ['type' => $type, 'query' => $query] + $filtres, (int)$r['total']);
        return $r;
    }

    private function rechercheGlobale(string $query, ?array $types, array $filtres, bool $modules = true): array
    {
        $r = $this->tools->rechercheGlobale($query, $types, $filtres, $modules);
        $this->tracer('search', ['type' => $types && count($types) === 1 ? $types[0] : 'tout', 'query' => $query] + $filtres,
            array_sum(array_map(fn($b) => (int)$b['total'], $r['blocs'])));
        return $r;
    }

    private function ligneParId(string $type, int $id): ?array
    {
        if ($type === 'documents') {
            $r = $this->tools->lignes('documents', [], [], ['brut' => true]);
            foreach ($r['lignes'] as $x) if ((int)$x['Id'] === $id) return $x;
            return null;
        }
        if (in_array($type, ['plans', 'archives'], true)) {
            $r = $this->tools->lignes($type, [], [], ['brut' => true]);
            foreach ($r['lignes'] as $x) if ((int)$x['Id'] === $id) return $x;
            return null;
        }
        $f = $this->tools->fiche($type, $id);
        if ($f) $this->tracer('get_fiche', ['type' => rtrim($type, 's'), 'id' => $id], 1);
        return $f;
    }

    /** Texte de recherche : mots-clés, codes et numéros (le lieu reste un filtre). */
    private function requete(array $c, bool $avecLieu = false): string
    {
        return trim(implode(' ', array_merge($c['mots'], $c['codes'], $c['numeros'])));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Mise en forme
    // ═══════════════════════════════════════════════════════════════════════

    private function feminin(string $type): bool { return (bool)(AssistantLangue::LIBELLES[$type][2] ?? false); }

    private function libelleType(string $type, int $n, array $filtres = []): string
    {
        if ($type === 'plans' && !empty($filtres['type_element'])) {
            $e = (string)$filtres['type_element'];
            return ($n > 1 ? $e . 's' : $e) . ' sur les plans';
        }
        [$s, $p] = AssistantLangue::LIBELLES[$type] ?? [$type, $type];
        return $n > 1 ? $p : $s;
    }

    private function descMots(array $mots): string
    {
        $t = $this->affichageMots($mots);
        return $t !== '' ? ' « ' . $t . ' »' : '';
    }

    /** « préventives en cours au Bâtiment A, en 2026 » — accordé au nombre $n. */
    private function descFiltres(array $f, ?string $type, int $n = 2): string
    {
        return $this->descQualif($f, $type, $n) . $this->descLieu($f);
    }

    /** Qualificatifs accordés : type d'intervention, statut, état, urgence. */
    private function descQualif(array $f, ?string $type, int $n = 2): string
    {
        $pl = $n !== 1;
        $p = [];
        if (!empty($f['type_interv'])) $p[] = match ($f['type_interv']) {
            'preventive' => $pl ? 'préventives' : 'préventive',
            'curative'   => $pl ? 'curatives' : 'curative',
            default      => 'de contrôle réglementaire',
        };
        if (!empty($f['statut'])) $p[] = $this->libelleStatut((string)$f['statut'], $type, $n);
        if (!empty($f['etat'])) $p[] = $this->libelleStatut((string)$f['etat'], $type, $n);
        if (!empty($f['urgence'])) $p[] = match ($f['urgence']) {
            'urgente' => $pl ? 'urgentes' : 'urgente', 'haute' => 'de priorité haute', 'basse' => 'de priorité basse', default => (string)$f['urgence'],
        };
        return $p ? ' ' . implode(' ', $p) : '';
    }

    /** Lieu et période : « au Bâtiment A, au 1er étage, en 2026 ». */
    private function descLieu(array $f): string
    {
        $p = [];
        if (!empty($f['batiment'])) {
            // « au Bâtiment A », mais « au bâtiment Hôtel de ville » (jamais « au Hôtel de ville »).
            $b = AssistantLangue::texte($f['batiment']);
            $p[] = preg_match('/^(?:batiment|bat)\b/', AssistantLangue::preparer($b)) ? "au **$b**" : "au bâtiment **$b**";
        }
        if (isset($f['etage']) && $f['etage'] !== '') $p[] = 'au **' . AssistantLangue::libelleNiveau((int)$f['etage']) . '**';
        if (!empty($f['bureau'])) $p[] = 'bureau **' . AssistantLangue::texte($f['bureau']) . '**';
        if (!empty($f['famille'])) $p[] = 'famille ' . AssistantLangue::texte($f['famille']);
        if (!empty($f['annee'])) $p[] = 'en **' . $f['annee'] . '**';
        if (!empty($f['mois'])) $p[] = 'en **' . self::libelleMois((string)$f['mois']) . '**';
        if (!empty($f['semaine'])) $p[] = self::libelleSemaine((string)$f['semaine']);
        if (!empty($f['jour'])) $p[] = self::libelleJour((string)$f['jour']);
        return $p ? ' ' . implode(', ', $p) : '';
    }

    /** « octobre 2026 » (mois « 2026-10 »). */
    private static function libelleMois(string $m): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $m, $x)) return $m;
        $noms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return ($noms[(int)$x[2] - 1] ?? $x[2]) . ' ' . $x[1];
    }

    /** « cette semaine », « la semaine prochaine », « la semaine du 05/10/2026 » (semaine ISO « 2026-W41 »). */
    private static function libelleSemaine(string $s): string
    {
        if ($s === date('o-\WW')) return 'cette semaine';
        if ($s === date('o-\WW', strtotime('+7 days'))) return 'la semaine prochaine';
        if ($s === date('o-\WW', strtotime('-7 days'))) return 'la semaine dernière';
        if (preg_match('/^(\d{4})-W(\d{2})$/', $s, $m)) {
            $lundi = (new DateTimeImmutable())->setISODate((int)$m[1], (int)$m[2], 1)->format('Y-m-d');
            return 'la semaine du ' . AssistantLangue::date($lundi);
        }
        return $s;
    }

    /** « aujourd'hui », « demain (27/09/2026) », « le 12/10/2026 ». */
    private static function libelleJour(string $j): string
    {
        $d = AssistantLangue::date($j);
        return match ($j) {
            date('Y-m-d') => "aujourd'hui",
            date('Y-m-d', strtotime('+1 day')) => "demain ($d)",
            date('Y-m-d', strtotime('-1 day')) => "hier ($d)",
            date('Y-m-d', strtotime('+2 days')) => "après-demain ($d)",
            date('Y-m-d', strtotime('-2 days')) => "avant-hier ($d)",
            default => "le $d",
        };
    }

    private function libelleStatut(string $k, ?string $type, int $n = 2): string
    {
        $fem = $type ? $this->feminin($type) : false;
        $pl = $n !== 1;
        $e = ($fem ? 'e' : '') . ($pl ? 's' : '');
        return match ($k) {
            'en_cours' => 'en cours', 'planifie' => 'planifié' . $e, 'realise' => 'réalisé' . $e,
            'termine' => 'terminé' . $e, 'archive' => 'archivé' . $e, 'nouveau' => 'en attente',
            'traite' => 'traité' . $e, 'refuse' => 'refusé' . $e, 'relance' => 'relancé' . $e,
            'actif' => ($fem ? 'active' : 'actif') . ($pl ? 's' : ''), 'expire' => 'expiré' . $e, 'resilie' => 'résilié' . $e,
            'hors_service' => 'hors service', 'utilise' => 'en service', 'stock' => 'en stock', 'jete' => "sorti$e de l'inventaire",
            default => $k,
        };
    }

    private function noteIgnores(array $ignores, string $type): string
    {
        unset($ignores['semaine'], $ignores['jour']);
        if (!$ignores) return '';
        $noms = ['batiment' => 'le bâtiment', 'etage' => "l'étage", 'bureau' => 'le bureau', 'statut' => 'le statut',
                 'etat' => "l'état", 'type_interv' => "le type d'intervention", 'urgence' => "l'urgence",
                 'annee' => "l'année", 'mois' => 'le mois', 'type_element' => "le type d'élément", 'famille' => 'la famille'];
        $l = array_map(fn($k) => $noms[$k] ?? $k, array_keys($ignores));
        return "\n*(" . AssistantLangue::capitale(AssistantLangue::enumeration($l)) . ' ne ' . (count($l) > 1 ? "s'appliquent" : "s'applique") . ' pas aux ' . AssistantLangue::LIBELLES[$type][1] . ')*';
    }

    private function prefixeCorrections(array $corr): string
    {
        if (!$corr) return '';
        $l = [];
        foreach ($corr as $de => $vers) $l[] = "« $de » → « $vers »";
        return '*(orthographe rapprochée : ' . AssistantLangue::texte(implode(', ', $l), 200) . ")*\n";
    }

    private function dansJours(?string $date): string
    {
        $date = substr(trim((string)$date), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
        $j = (int)round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
        if ($j === 0) return " (aujourd'hui)";
        return $j > 0 ? " (dans $j " . AssistantLangue::pluriel($j, 'jour') . ')' : ' (il y a ' . abs($j) . ' ' . AssistantLangue::pluriel(abs($j), 'jour') . ')';
    }

    private function statutAffiche(string $type, string $statut): string
    {
        // L'interface affiche « Terminée » pour le statut interne « Validée ».
        return ($type === 'interventions' && $statut === 'Validée') ? 'Terminée' : $statut;
    }

    /** « urgente », « urgence haute »… (jamais « urgence urgente »). */
    private function urgenceTexte(string $u): string
    {
        $u = mb_strtolower(trim($u));
        if ($u === '') return '';
        return str_starts_with(AssistantLangue::norm($u), 'urgent') ? 'urgente' : 'urgence ' . $u;
    }

    private function bureau(string $b): string
    {
        return preg_match('/^\d/', $b) ? 'bureau ' . AssistantLangue::texte($b) : AssistantLangue::texte($b);
    }

    /** « Bâtiment A, 1er étage, bureau 101 » à partir des champs saisis. */
    private function lieu(array $row): string
    {
        $etage = trim((string)($row['Etage'] ?? ''));
        if ($etage !== '' && ctype_digit($etage)) $etage = AssistantLangue::libelleNiveau((int)$etage);
        $bureau = trim((string)($row['NumeroBureau'] ?? ''));
        return AssistantLangue::texte(implode(', ', array_filter([trim((string)($row['Batiment'] ?? '')), $etage, $bureau !== '' ? $this->bureau($bureau) : ''])));
    }

    private function lieuPlan(array $row): string
    {
        return AssistantLangue::texte(implode(', ', array_filter([trim((string)($row['BatimentNom'] ?? '')), trim((string)($row['EtageNom'] ?? ''))])));
    }

    private function libelleTypeElement(array $row): string
    {
        $t = (string)($row['TypeElement'] ?? '');
        return AssistantLangue::texte(($t === 'texte' ? 'texte' : $t) . (!empty($row['Calque']) ? ', calque ' . $row['Calque'] : ''));
    }

    private function nomLigne(string $type, array $row): string
    {
        $v = fn(string $k) => trim((string)($row[$k] ?? ''));
        $n = match ($type) {
            'biens', 'equipements' => $v('InfoProduit') ?: trim($v('Famille') . ' ' . $v('SousFamille')) ?: $v('Numero'),
            'interventions' => $v('Numero') ?: ('#' . $v('Id')),
            'contrats' => trim($v('Numero') . ' ' . $v('Societe')),
            'demandes' => $v('Titre'),
            'stock' => $v('Designation'),
            'documents' => $v('NomFichier'),
            'plans' => $v('Nom') ?: $v('TypeElement'),
            'archives' => $v('NumeroDossier'),
            default => $v('Nom'),
        };
        return AssistantLangue::texte($n !== '' ? $n : '#' . $v('Id'), 90);
    }

    /** Lien cliquable vers la fiche (Id RÉEL de la ligne). */
    private function lien(string $type, array $row): string
    {
        $id = (int)($row['Id'] ?? $row['id'] ?? 0);
        $v = fn(string $k) => trim((string)($row[$k] ?? ''));
        switch ($type) {
            case 'documents': return '[DOC:' . $id . ':' . AssistantLangue::libelle($v('NomFichier') ?: 'document', 80) . ']';
            case 'plans':     return $this->lienPlan($row, $this->nomLigne('plans', $row));
            case 'archives':  return '**' . AssistantLangue::texte($v('NumeroDossier') ?: '#' . $id) . '**';
        }
        $label = match ($type) {
            'biens', 'equipements' => trim($v('Numero') . ' · ' . ($v('InfoProduit') ?: trim($v('Famille') . ' ' . $v('SousFamille'))), ' ·'),
            'interventions' => $v('Numero') ?: 'Intervention #' . $id,
            'contrats' => trim($v('Numero') . ' · ' . $v('Societe'), ' ·'),
            'demandes' => '#' . $id . ' · ' . $v('Titre'),
            'stock' => $v('Designation') ?: $v('Reference'),
            default => '#' . $id,
        };
        $ft = AssistantLangue::TYPE_FICHE[$type] ?? null;
        if (!$ft || $id <= 0) return '**' . AssistantLangue::texte($label) . '**';
        return "[FICHE:$ft:$id:" . AssistantLangue::libelle($label !== '' ? $label : '#' . $id) . ']';
    }

    private function lienPlan(array $row, string $label): string
    {
        $etage = (int)($row['EtageId'] ?? 0); $id = (int)($row['Id'] ?? 0);
        if ($etage <= 0) return '**' . AssistantLangue::texte($label) . '**';
        return "[FICHE:plan:$etage:$id:" . AssistantLangue::libelle($label) . ']';
    }

    /** Une ligne de liste : lien + l'essentiel selon le type. */
    private function ligneListe(string $type, array $row): string
    {
        if (str_starts_with($type, 'module:')) return $this->resumeLigneModule($row);
        $d = fn($x) => AssistantLangue::date((string)$x);
        $v = fn(string $k) => trim((string)($row[$k] ?? ''));
        $det = match ($type) {
            'biens', 'equipements' => $this->lieu($row),
            'interventions' => implode(' · ', array_filter([$v('Type'), $this->statutAffiche('interventions', $v('Statut')),
                $v('DateIntervention') !== '' ? $d($v('DateIntervention')) : ($v('DateRealisation') !== '' ? $d($v('DateRealisation')) : ''),
                AssistantLangue::texte($v('Description'), 70)])),
            'contrats' => implode(' · ', array_filter([$v('Type'), $v('Statut'), $v('DateFin') !== '' ? 'fin ' . $d($v('DateFin')) : '',
                (float)($row['MontantAnnuel'] ?? 0) > 0 ? AssistantLangue::montant($row['MontantAnnuel']) . '/an' : ''])),
            'demandes' => implode(' · ', array_filter([$v('Statut'), $this->urgenceTexte($v('Urgence')), $v('Batiment'), $v('DateCreation') !== '' ? $d($v('DateCreation')) : ''])),
            'stock' => (int)($row['Quantite'] ?? 0) . ' en stock' . ((int)($row['SeuilAlerte'] ?? 0) ? ' (seuil ' . (int)$row['SeuilAlerte'] . ')' : '') . ($v('Emplacement') !== '' ? ' · ' . $v('Emplacement') : ''),
            'documents' => implode(' · ', array_filter([$v('Categorie'), $v('DateAjout') !== '' ? $d($v('DateAjout')) : ''])),
            'plans' => implode(' · ', array_filter([$v('TypeElement'), $this->lieuPlan($row)])),
            'archives' => implode(' · ', array_filter([$v('Description'), $v('Service'), $v('Emplacement')])),
            default => '',
        };
        $det = AssistantLangue::texte($det, 160);
        return $this->lien($type, $row) . ($det !== '' ? " — $det" : '');
    }

    private function liste(string $type, array $lignes, ?int $total = null): string
    {
        $l = array_map(fn($r) => '- ' . $this->ligneListe($type, $r), array_slice($lignes, 0, self::MAX_LISTE));
        $total ??= count($lignes);
        if ($total > count($l)) $l[] = '… et ' . ($total - count($l)) . ' ' . AssistantLangue::pluriel($total - count($l), 'autre') . '.';
        return implode("\n", $l);
    }

    private function resumeLigneModule(array $row): string
    {
        $p = [];
        foreach ($row as $k => $v) {
            if (!is_scalar($v) || $v === '' || $v === null || in_array((string)$k, ['id', 'Id', 'cree_le', 'etage_id'], true)) continue;
            $p[] = AssistantLangue::texte((string)$v, 50);
            if (count($p) >= 4) break;
        }
        return implode(' · ', $p);
    }

    private function elementsIntervention(array $f): array
    {
        $out = [];
        $ids = preg_split('/[\s,;]+/', (string)($f['EquipementsIds'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($ids, 0, 5) as $id) {
            $e = $this->tools->fiche('equipements', (int)$id);
            if ($e) $out[] = $this->lien('equipements', $e);
        }
        if (!empty($f['BienId'])) { $b = $this->tools->fiche('biens', (int)$f['BienId']); if ($b) $out[] = $this->lien('biens', $b); }
        return $out;
    }

    private function interventionDeDemande(int $demandeId): ?array
    {
        $r = $this->tools->lignes('interventions', [], [], ['brut' => true]);
        foreach ($r['lignes'] as $x) if ((int)($x['DemandeId'] ?? 0) === $demandeId) return $x;
        return null;
    }
}
