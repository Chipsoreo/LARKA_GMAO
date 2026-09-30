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
 * Larka — Assistant : compréhension des questions (déterministe)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Transforme une question en français libre — fautes, abréviations, langage
 * parlé compris — en une structure fixe :
 *
 *   intention   localiser | compter | chercher | fiche | quand | alertes |
 *               personne | stock | somme | contexte | modules | aide |
 *               politesse | hors_sujet | inconnue
 *   type        biens | equipements | interventions | contrats | demandes |
 *               stock | documents | plans | archives | null
 *   mots        mots-clés de l'objet cherché (« extincteur »)
 *   numeros     numéros isolés (« extincteur 2 » → 2) · codes (« EXT-002 »)
 *   filtres     batiment, etage (niveau), bureau, statut, type_interv,
 *               urgence, annee, mois, famille…
 *
 * AUCUN modèle n'intervient ici : la même question donne toujours la même
 * structure, donc la même réponse, que le modèle configuré fasse 0,6 ou 70
 * milliards de paramètres — ou qu'il n'y en ait pas. C'est tout l'objet.
 *
 * Les valeurs propres au site (noms de bâtiments, statuts, familles) sont lues
 * dans la base via AssistantTools::vocabulaireSite() : « bat A », « centre
 * technique » ou « terminées » sont ramenés aux valeurs réellement saisies.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/AssistantLangue.php';

final class AssistantComprehension
{
    /** Intentions exprimées par un mot de la question (et non déduites). */
    private const EXPLICITES = ['localiser', 'compter', 'fiche', 'quand', 'alertes', 'personne', 'stock',
                                'somme', 'contexte', 'modules', 'aide', 'politesse', 'hors_sujet', 'lister'];

    /** Mots-outils que l'on corrige s'ils sont mal orthographiés (« cobien », « intervantions »). */
    private const LEXIQUE_CORRIGEABLE = [
        'combien', 'nombre', 'quantite', 'localiser', 'localisation', 'emplacement', 'trouve', 'trouver', 'intervention',
        'interventions', 'equipement', 'equipements', 'contrat', 'contrats', 'demande', 'demandes', 'document',
        'documents', 'archives', 'batiment', 'batiments', 'etage', 'etages', 'alerte', 'alertes', 'expirent',
        'expiration', 'retard', 'preventive', 'preventives', 'curative', 'curatives', 'planifiee', 'planifiees',
        'terminee', 'terminees', 'realisee', 'realisees', 'articles', 'stock', 'fiche', 'details', 'liste', 'responsable',
        'gestionnaire', 'gestionnaires', 'technicien', 'techniciens', 'telephone', 'annuaire', 'modules', 'categories',
        'familles', 'reglementaire', 'maintenance', 'echeance', 'rez', 'chaussee', 'sous', 'niveau', 'bureau', 'salle',
        'combiens', 'quelles', 'quels', 'lesquels', 'aujourd', 'semaine', 'annee', 'mois', 'prochaine', 'derniere',
        'janvier', 'fevrier', 'juillet', 'septembre', 'octobre', 'novembre', 'decembre', 'mercredi', 'vendredi',
        'samedi', 'dimanche',
    ];

    /** Mois cités en toutes lettres (texte préparé : sans accents). */
    private const MOIS = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
                          'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];

    private AssistantTools $tools;
    private ?array $site = null;

    public function __construct(AssistantTools $tools)
    {
        $this->tools = $tools;
    }

    private function site(): array
    {
        return $this->site ??= $this->tools->vocabulaireSite();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Point d'entrée
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param array $historique  [['role'=>'user'|'assistant','content'=>…], …] (le plus récent en dernier)
     */
    public function analyser(string $question, array $historique = []): array
    {
        $c = $this->analyserSimple($question);

        // ── Suite de conversation (« et au bâtiment B ? », « et le 3 ? ») ──
        if ($this->estSuite($c)) {
            [$precQ, $precR] = $this->echangePrecedent($historique);
            // « le 2e », « celui-ci », « où est-il ? » : sans objet nommé, on désigne un élément déjà cité.
            $designe = ($c['rang'] !== null || $c['pronom']) && !$c['mots'] && !$c['codes'] && !$c['id'];
            if ($precQ !== null) {
                $p = $this->analyserSimple($precQ);
                // La question précédente était elle-même une suite : on remonte d'un cran.
                if ($this->estSuite($p)) {
                    [$precQ2] = $this->echangePrecedent($historique, 2);
                    if ($precQ2 !== null) $p = $this->fusionner($p, $this->analyserSimple($precQ2));
                }
                $c = $this->fusionner($c, $p);
                if ($designe) {
                    $ref = self::entiteCitee((string)$precR, $c['rang']);
                    if ($ref) { $c['reference'] = $ref; $c['mots'] = []; $c['numeros'] = []; $c['codes'] = []; $c['id'] = null; }
                }
            }
        }
        return $c;
    }

    /** La question dépend-elle de la précédente ? */
    public function estSuite(array $c): bool
    {
        if (in_array($c['intention'], ['politesse', 'aide', 'hors_sujet', 'modules', 'contexte'], true)) return false;
        if ($c['suite_explicite']) return true;
        if ($c['pronom'] || $c['rang'] !== null) return true;
        // Question très courte, sans intention ni type, mais avec un filtre : « et au bât B ? », « en 2025 ? »
        $explicite = in_array($c['intention'], self::EXPLICITES, true);
        return !$explicite && $c['type'] === null && count($c['_mots_bruts']) <= 4
            && ($c['filtres'] || $c['numeros'] || ($c['mots'] === [] && $c['codes'] === []));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Analyse d'une question isolée
    // ═══════════════════════════════════════════════════════════════════════

    public function analyserSimple(string $question): array
    {
        $c = [
            'question' => trim($question), 'intention' => 'inconnue', 'type' => null, 'mots' => [],
            'numeros' => [], 'codes' => [], 'id' => null, 'filtres' => [], 'alerte' => null, 'jours' => null,
            'personne' => [], 'role' => null, 'auteur' => false, 'pronom' => false, 'rang' => null,
            'reference' => null, 'tri' => null, 'limite' => null, 'suite_explicite' => false,
            'corrections' => [], 'confiance' => 'basse', '_mots_bruts' => [], 'affichage' => [], 'date_invalide' => null,
        ];
        $t0 = AssistantLangue::preparer($question);
        if ($t0 === '') { $c['intention'] = 'aide'; return $c; }
        // Graphie d'origine de chaque mot (accents compris) pour l'affichage : « chaudière », pas « chaudiere ».
        $c['affichage'] = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $question, -1, PREG_SPLIT_NO_EMPTY) as $o) {
            // Casse d'origine, sauf mot tout en capitales dans une phrase criée (« OÙ EST… »).
            $c['affichage'][AssistantLangue::norm($o)] ??= (mb_strtoupper($question) === $question ? mb_strtolower($o) : $o);
        }

        // ── Corrections des mots-outils mal orthographiés ─────────────────
        $t0 = $this->corrigerOutils($t0, $c['corrections']);
        // ── Tournures familières → vocabulaire métier (« le truc qui éteint le feu ») ──
        $t0 = AssistantLangue::reformuler($t0);
        $c['_mots_bruts'] = AssistantLangue::mots($t0);
        $t = ' ' . $t0 . ' ';

        // ── Politesse / aide / hors sujet (question entière) ──────────────
        if (preg_match('/^ (?:(?:bonjour|bonsoir|salut|hello|coucou|hey|yo|merci|ok|okay|d accord|parfait|super|top|genial|cool|au revoir|bye|a plus|bonne (?:journee|soiree)|tres bien|c est bon|ca marche|nickel)\s*)+(?:beaucoup|bien|a toi|a vous|l assistant|larka)? $/', $t)) {
            $c['intention'] = 'politesse'; $c['confiance'] = 'haute';
            return $c;
        }
        if (preg_match('/^ (?:aide|help|\?+|menu|exemples?|quoi|hein) $/', $t)
            || preg_match('/\b(?:que (?:sais|peux) tu|tu (?:sais|peux) faire quoi|que peux tu faire|que sais tu faire|comment (?:ca marche|t utiliser|te servir|utiliser l assistant|tu marches|tu fonctionnes)|a quoi (?:tu sers|sers tu)|tu sers a quoi|qu est ce que tu (?:sais|peux) faire|exemples? de questions?|quelles questions|tu es qui|qui es tu|t es qui|c est qui toi|tu t appelles comment|comment tu t appelles|presente toi|tu es quoi)\b/', $t)) {
            $c['intention'] = 'aide'; $c['confiance'] = 'haute';
            return $c;
        }

        $c['suite_explicite'] = (bool)preg_match('/^ (?:et|pareil|idem|meme chose|meme question|aussi|sinon|et pour|et sur|et dans|et au|et en|et le|et la|et les|et l)\b/', $t);

        // ── Identifiants et codes (« #12 », « EXT-002 », « INT-2026-003 ») ──
        if (preg_match('/#\s*(\d{1,7})\b/', $t, $m)) { $c['id'] = (int)$m[1]; $t = str_replace($m[0], ' ', $t); }
        if (preg_match_all('/(?<=\s)([a-z]{1,8}-?\d[a-z0-9\-]*)(?=\s)/', $t, $mm)) {
            foreach ($mm[1] as $code) {
                // Ordinaux (2e, 1er), étages (r+1), unités (co2 est un mot du produit, pas un code : on le garde en mot)
                if (preg_match('/^\d/', $code) || preg_match('/^(?:r\+?\d+|co2|h2o|m2|m3|kw|kva|db)$/', $code)) continue;
                if (!preg_match('/[a-z]/', $code) || !preg_match('/\d/', $code)) continue;
                if (!str_contains($code, '-') && strlen($code) < 4) continue;   // « a1 » : trop court pour un code
                $c['codes'][] = strtoupper($code);
                $t = preg_replace('/(?<=\s)' . preg_quote($code, '/') . '(?=\s)/', ' ', $t, 1);
            }
        }

        // ── Dates et périodes ─────────────────────────────────────────────
        $this->extraireDates($t, $c);

        // ── Étage, bureau, bâtiment ───────────────────────────────────────
        $this->extraireEtage($t, $c);
        if (preg_match('/\b(?:bureau|salle|local|box|piece)\s+(?:numero\s+)?([a-z]?\d{1,4}[a-z]?)\b/', $t, $m)) {
            $c['filtres']['bureau'] = strtoupper($m[1]);
            $t = str_replace($m[0], ' ', $t);
        }
        $this->extraireBatiment($t, $c);

        // ── Intentions (sur le texte d'origine, avant retraits) ───────────
        $intention = $this->detecterIntention($t0, $c);

        // ── Type ──────────────────────────────────────────────────────────
        $this->extraireType($t, $c);

        // ── Statuts, types d'intervention, urgences ───────────────────────
        $this->extraireStatuts($t, $c, $intention);

        // ── Nombre demandé (« les 5 dernières interventions ») ────────────
        if (preg_match('/\b(\d{1,2})\s+(?:dernier|derniere|derniers|dernieres|premiers|premieres|prochain|prochaine|prochains|prochaines|plus recent\w*)\b/', $t, $m)) {
            $c['limite'] = (int)$m[1]; $t = str_replace($m[1] . ' ', ' ', $t);
        }
        if (preg_match('/\b(?:dernier|derniere|derniers|dernieres|plus recent\w*|recemment|recent\w*)\b/', $t)) $c['tri'] = 'recent';
        elseif (preg_match('/\b(?:prochain|prochaine|prochains|prochaines|a venir|futur\w*|prevu\w*)\b/', $t)) $c['tri'] = 'avenir';
        // « la prochaine intervention », « le dernier contrat » : un seul élément.
        if ($c['limite'] === null && preg_match('/\b(?:la|le|l) (?:prochaine?|derniere?|plus recente?)\b/', $t)) $c['limite'] = 1;

        // « qu'est-ce qui est prévu demain ? » : un statut propre à un seul type désigne ce type.
        if ($c['type'] === null && !$c['codes'] && $c['id'] === null && in_array($intention, ['lister', 'inconnue', 'fiche', 'compter'], true)) {
            $c['type'] = ['planifie' => 'interventions', 'refuse' => 'demandes', 'relance' => 'demandes', 'resilie' => 'contrats'][$c['filtres']['statut'] ?? ''] ?? null;
        }
        $this->resoudreDates($t0, $c);

        // ── Rang et pronoms (suites de conversation) ──────────────────────
        $this->extraireReference($t0, $c);

        // ── Numéros isolés (« extincteur 2 », « numéro 3 ») ───────────────
        $this->extraireNumeros($t, $c);

        // ── Personnes (annuaire) ──────────────────────────────────────────
        if ($intention === 'personne') {
            if (preg_match('/\b(gestionnaire|technicien|administrateur|admin|demandeur|visionneur)s?\b/', $t, $m)) {
                $c['role'] = ['gestionnaire' => 'Gestionnaire', 'technicien' => 'Technicien', 'administrateur' => 'Admin',
                              'admin' => 'Admin', 'demandeur' => 'Demandeur', 'visionneur' => 'Visionneur'][$m[1]];
                $t = preg_replace('/\b' . $m[1] . 's?\b/', ' ', $t);
            }
            if (preg_match('/\bqui a (?:fait|cree|demande|declare|saisi|signale|ouvert|emis|depose|envoye)\b/', $t0)) $c['auteur'] = true;
        }

        // ── Mots-clés restants ────────────────────────────────────────────
        $c['mots'] = $this->motsCles($t, $intention);
        if ($intention === 'personne') {
            $c['personne'] = array_values(array_filter($c['mots'], fn($w) => !in_array($w, ['responsable', 'responsables',
                'contact', 'contacts', 'contacter', 'joindre', 'appeler', 'telephone', 'tel', 'mail', 'email', 'courriel',
                'coordonnees', 'annuaire', 'personne', 'personnes', 'occupe', 'gere', 'soccupe', 'travaille', 'intervient',
                'portable', 'adresse'], true)));
        }

        // ── Décision finale ───────────────────────────────────────────────
        $c['intention'] = $this->decider($intention, $c);
        $c['confiance'] = in_array($c['intention'], self::EXPLICITES, true) ? 'haute'
            : (($c['mots'] || $c['codes'] || $c['type'] || $c['id'] || $c['reference']) ? 'moyenne' : 'basse');
        if ($c['intention'] === 'lister') $c['intention'] = 'chercher';
        return $c;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Intentions
    // ═══════════════════════════════════════════════════════════════════════

    private function detecterIntention(string $t0, array &$c): string
    {
        $t = ' ' . $t0 . ' ';
        $has = static fn(string $re): bool => (bool)preg_match($re, $t);

        if ($has('/\b(?:meteo|il fait (?:beau|chaud|froid)|blague|recette|foot\w*|match de|politique|president|poeme|chanson|film|horoscope|tradui\w+|capitale de|bitcoin|bourse|actualites?|qui a gagne|raconte moi|ecris moi un|l heure qu il est|quelle heure)\b/')) {
            return 'hors_sujet';
        }
        if ($has('/\b(?:modules?|extensions?)\b/') && !$has('/\bmodule de\b/')) return 'modules';

        $qui = $has('/^ (?:et )?(?:qui|c est qui|qui c est)\b/')
            || $has('/\b(?:(?<!ce )qui (?:est|sont|s occupe|soccupe|gere|a (?:fait|cree|demande|declare|saisi|signale|ouvert|emis|depose|envoye)|peut|contacter|appeler|joindre|travaille|intervient)|annuaire|contacter|joindre|appeler|coordonnees|numero de (?:tel\w*|portable)|telephone de|tel de|mail de|email de|adresse mail)\b/');
        if ($qui) return 'personne';

        // « où en est » : c'est un statut, pas un lieu.
        if ($has('/\bou en (?:est|sont|sommes)\b/')) return 'fiche';

        if ($has('/^ (?:et |alors |dis moi |sais tu |tu sais |stp |svp |bonjour |salut )*ou\b(?! alors)(?! bien)/')
            || $has('/\bou (?:est|sont|se trouve\w*|trouver|trouve\w*|je trouve|on trouve|peut on trouver|puis je trouver|ranger|range\w*|situe\w*|mettre|placer|as tu mis|ai je mis|a t on mis|est passe\w*|sont passe\w*|c est)\b/')
            || $has('/\b(?:est|sont|se trouve|se trouvent|c est|ca se trouve|il est|elle est|ils sont|elles sont) ou(?: ca)? $/')
            || $has('/\b(?:il|elle|ils|elles|c) (?:est|sont) ou\b/')
            || $has('/\b(?:localis\w+|emplacements?|se trouv\w+|situe\w*|position\w*|a quel etage|dans quel(?:le)? (?:batiment|bureau|local|salle|piece|etage)|quel(?:le)? (?:batiment|etage|bureau|salle)|sur (?:le|quel) plan|voir sur (?:le|un) plan|montre\w* (?:moi )?sur (?:le|un) plan)\b/')) {
            return 'localiser';
        }

        $combien = $has('/\b(?:combien|cb|kombien|combiens|nombre|nb|denombr\w+|compte[rz]?|quantites?|total)\b/');
        $montant = $has('/\b(?:montant\w*|cout\w*|coute\w*|budget\w*|depense\w*|prix total|valeur totale|valeur du stock|combien (?:coute|coutent|ca coute))\b/');
        if ($montant && ($combien || $has('/\b(?:quel|quelle|total|totale|somme|annuel\w*)\b/'))) return 'somme';
        if ($has('/\b(?:valeur (?:du |de |des )?stocks?|valeur totale|montant total|cout total|budget total|total des montants)\b/')) return 'somme';

        $alerte = $has('/\b(?:alertes?|a traiter|en retard|retards?|expir\w+|echeances?|echu\w*|renouvel\w+|arriv\w+ a (?:echeance|terme)|fin de contrat|seuil|ruptures?|stock (?:bas|faible|critique|insuffisant)|sous le seuil|manqu\w+|a commander|reappro\w*|non trait\w+|quoi de neuf|point du jour|a surveiller)\b/');
        $urgentSeul = $has('/\b(?:urgent\w*|urgences?|critiques?|prioritaires?)\b/');

        $stockQte = $has('/\b(?:en stock|reste\w*|il reste|quantite\w*|dispo\w*|combien d? ?exemplaires)\b/') && ($combien || $has('/\b(?:reste|il reste|quantite|dispo\w*|en stock)\b/'));

        if ($alerte) return 'alertes';
        if ($stockQte && !$has('/\b(?:biens?|equipements?)\b/')) return 'stock';
        if ($combien) return 'compter';
        if ($urgentSeul && !$has(self::typesUnion())) return 'alertes';
        if ($has('/\b(?:quand|a quelle date|quelle date|depuis quand|jusqu a quand|date de fin|date d echeance|date de debut)\b/')) return 'quand';
        if ($has('/\b(?:qu est ce qu il y a|qu y a t il|ce qu il y a|qu est ce qu on a|qu avons nous)\b/')) return 'lister';
        if ($has('/\b(?:fiche|details?|detaille\w*|infos?|informations?|caracteristiques?|c est quoi|qu est ce que|qu est ce qu|decri\w+|resume\w*|ouvre\w*|ouvrir|statut (?:de|du|des|d)|etat (?:de|du|des|d))\b/')) return 'fiche';
        if ($has('/\b(?:quels|quelles|liste des|liste|lister)\b(?:\s+\w+){0,3}\s+(?:batiments|familles|sous familles|categories|marques|calques|societes|prestataires|fournisseurs)\b/')
            && !$has('/\b(?:equipements?|biens?|interventions?|contrats?|demandes?|articles?)\b (?:de|du|des|d)\b/')) {
            return 'contexte';
        }
        if ($has('/\b(?:responsables?|gestionnaires|techniciens|interlocuteurs?)\b/')) return 'personne';
        if ($has('/\b(?:liste\w*|list|tous les|toutes les|tout les|affiche\w*|montre\w*|donne\w*|quels sont|quelles sont|quels|quelles|y a t il|il y a|existe\w*|cherche\w*|recherch\w+|trouve\w*|voir|consulter|retrouve\w*)\b/')) return 'lister';
        return 'inconnue';
    }

    private static function typesUnion(): string
    {
        return '/' . implode('|', array_map(fn($re) => trim($re, '/'), AssistantLangue::TYPES)) . '/';
    }

    /** Arbitrage final entre l'intention lue et ce que la question contient. */
    private function decider(string $i, array $c): string
    {
        // « le contrat CTR-001 », « demande #12 », « EXT-002 » : désigner un élément précis, c'est en voir la fiche.
        if (in_array($i, ['inconnue', 'lister'], true) && ($c['codes'] || ($c['id'] && !$c['mots']))) return 'fiche';
        // « quand expire le contrat Otis ? » : une date précise, pas la liste des alertes.
        if ($i === 'alertes' && ($c['mots'] || $c['codes'] || $c['id'] || $c['reference'])
            && preg_match('/\b(?:quand|a quelle date|quelle date|date de fin|date d echeance|jusqu a quand)\b/', AssistantLangue::preparer($c['question']))) return 'quand';
        if ($i === 'inconnue' && ($c['reference'] || $c['pronom'])) return 'fiche';
        // « c'est quoi au 2e du bâtiment B ? » : sans élément désigné, c'est une liste du lieu.
        if ($i === 'fiche' && !$c['mots'] && !$c['codes'] && !$c['id'] && !$c['reference'] && !$c['pronom'] && ($c['filtres'] || $c['type'])) return 'chercher';
        if ($i === 'stock' && !$c['mots'] && !$c['codes']) return 'compter';
        if ($i === 'personne' && $c['type'] && !$c['auteur'] && !$c['personne'] && !$c['role']) {
            // « responsable du contrat Otis » : le contact est masqué, on montre la fiche.
            return $c['type'] === 'contrats' || $c['type'] === 'interventions' ? 'personne' : 'personne';
        }
        if ($i === 'inconnue' && ($c['mots'] || $c['type'] || $c['filtres'])) return 'chercher';
        return $i;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Extraction des éléments de la question
    // ═══════════════════════════════════════════════════════════════════════

    private function corrigerOutils(string $t, array &$corrections): string
    {
        $vides = array_flip(array_merge(AssistantLangue::VIDES, AssistantLangue::OUTILS, AssistantLangue::MOTS_TYPES));
        $lex = array_flip(self::LEXIQUE_CORRIGEABLE);
        $site = $this->site()['mots'] ?? [];
        $out = [];
        foreach (AssistantLangue::mots($t) as $w) {
            if (strlen($w) >= 5 && !ctype_digit($w) && !isset($vides[$w]) && !isset($lex[$w]) && !isset($site[$w])
                && !preg_match('/\d/', $w)) {
                $p = AssistantLangue::plusProche($w, $lex, strlen($w) <= 7 ? 1 : 2);
                if ($p !== null && $p !== $w) { $corrections[$w] = $p; $w = $p; }
            }
            $out[] = $w;
        }
        return implode(' ', $out);
    }

    private function extraireDates(string &$t, array &$c): void
    {
        $an = (int)date('Y');
        $f = &$c['filtres'];
        $retire = function (string $re) use (&$t): ?array {
            if (!preg_match($re, $t, $m)) return null;
            $t = str_replace($m[0], ' ', $t);
            return $m;
        };
        // Une date impossible (« 31/02 ») est signalée, jamais ignorée en silence :
        // sinon « interventions du 31/02 » listerait toutes les interventions.
        $jourValide = static function (int $j, int $m, int $a) use (&$c): ?string {
            if ($a < 100) $a += 2000;
            if (checkdate($m, $j, $a)) return sprintf('%04d-%02d-%02d', $a, $m, $j);
            $c['date_invalide'] = sprintf('%02d/%02d/%04d', $j, $m, $a);
            return null;
        };
        $moisRe = implode('|', array_keys(self::MOIS));
        // ── Dates explicites (avant l'année seule, qui prendrait « 2026 » dans « 15/10/2026 ») ──
        // « le 15/10/2026 », « 15/10/26 » ; « le 15/10 » seulement après un article (« joint 1/2 » n'est pas une date).
        if ($m = $retire('/\b(?:le |du |au |pour le |depuis le |avant le |apres le |jusqu au )?(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})\b/')) {
            if ($d = $jourValide((int)$m[1], (int)$m[2], (int)$m[3])) $f['jour'] = $d;
        } elseif ($m = $retire('/\b(?:le|du|au|pour le|depuis le|avant le|apres le|jusqu au) (\d{1,2})\/(\d{1,2})\b(?!\/)/')) {
            if ($d = $jourValide((int)$m[1], (int)$m[2], $an)) $f['jour'] = $d;
        } elseif ($m = $retire('/\b(?:le |du |au |pour le |depuis le )?(1er|premier|\d{1,2}) (' . $moisRe . ')(?: (20\d{2}))?\b/')) {
            $j = in_array($m[1], ['1er', 'premier'], true) ? 1 : (int)$m[1];
            if ($d = $jourValide($j, self::MOIS[$m[2]], isset($m[3]) && $m[3] !== '' ? (int)$m[3] : $an)) $f['jour'] = $d;
        } elseif ($m = $retire('/\b(?:en |d |de |du mois d |du mois de |au mois d |au mois de |le mois d |le mois de |mois d |mois de |pour |courant |fin |debut |mi )?(' . $moisRe . ')(?: (20\d{2}))?\b/')) {
            $f['mois'] = isset($m[2]) && $m[2] !== '' ? sprintf('%04d-%02d', (int)$m[2], self::MOIS[$m[1]]) : null;
            if ($f['mois'] === null) { unset($f['mois']); $c['_mois_seul'] = self::MOIS[$m[1]]; }
        } elseif ($m = $retire('/\b(?:en |de |du |pour )?(\d{1,2})\/(20\d{2})\b/')) {
            if ((int)$m[1] >= 1 && (int)$m[1] <= 12) $f['mois'] = sprintf('%04d-%02d', (int)$m[2], (int)$m[1]);
            else $c['date_invalide'] = $m[1] . '/' . $m[2];
        }
        // « lundi », « mardi prochain », « jeudi dernier » : résolu après lecture du type (passé ou futur).
        if ($m = $retire('/\b(?:ce |le |du |de |d |pour |depuis )?(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)(?: (prochain|dernier|passe|qui vient))?\b/')) {
            $c['_jour_semaine'] = [array_search($m[1], ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'], true) + 1, $m[2] ?? ''];
        }
        if ($retire('/\b(?:cette annee|l annee en cours|annee en cours|cette annee ci)\b/')) $f['annee'] = (string)$an;
        elseif ($retire('/\b(?:l an dernier|l annee derniere|l annee passee|annee precedente|l an passe)\b/')) $f['annee'] = (string)($an - 1);
        elseif ($retire('/\b(?:l an prochain|l annee prochaine)\b/')) $f['annee'] = (string)($an + 1);
        elseif ($m = $retire('/\b(?:en |de |du |pour |depuis |annee |sur |d )?(20[0-9]{2})\b(?!-)/')) $f['annee'] = $m[1];

        if ($retire('/\b(?:ce mois ci|ce mois|du mois|mois en cours)\b/')) $f['mois'] = date('Y-m');
        elseif ($retire('/\b(?:le mois dernier|mois dernier|mois precedent)\b/')) $f['mois'] = date('Y-m', strtotime('first day of last month'));
        elseif ($retire('/\b(?:le mois prochain|mois prochain)\b/')) $f['mois'] = date('Y-m', strtotime('first day of next month'));
        if ($retire('/\b(?:cette semaine|la semaine en cours|semaine en cours)\b/')) $f['semaine'] = date('o-\WW');
        elseif ($retire('/\b(?:la semaine prochaine|semaine prochaine|la semaine suivante)\b/')) $f['semaine'] = date('o-\WW', strtotime('+7 days'));
        elseif ($retire('/\b(?:la semaine derniere|semaine derniere|la semaine passee|semaine passee|la semaine precedente)\b/')) $f['semaine'] = date('o-\WW', strtotime('-7 days'));
        // « après-demain » avant « demain », « avant-hier » avant « hier ».
        if ($retire('/\baujourd hui\b/')) $f['jour'] = date('Y-m-d');
        elseif ($retire('/\bapres demain\b/')) $f['jour'] = date('Y-m-d', strtotime('+2 days'));
        elseif ($retire('/\bavant hier\b/')) $f['jour'] = date('Y-m-d', strtotime('-2 days'));
        elseif ($retire('/\bdemain\b/')) $f['jour'] = date('Y-m-d', strtotime('+1 day'));
        elseif ($retire('/\bhier\b/')) $f['jour'] = date('Y-m-d', strtotime('-1 day'));

        if ($m = $retire('/\b(?:dans (?:les )?|d ici |sous |les |des |aux )(\d{1,3}) (?:prochains? )?(jours?|semaines?|mois)\b/')
            ?: $retire('/\b(\d{1,3}) prochains? (jours?|semaines?|mois)\b/')) {
            $n = (int)$m[1];
            $c['jours'] = str_starts_with($m[2], 'mois') ? $n * 30 : (str_starts_with($m[2], 'semaine') ? $n * 7 : $n);
        } elseif ($retire('/\b(?:d ici|avant) (?:la )?fin (?:de l |d )?annee\b/')) {
            $c['jours'] = max(1, (int)((strtotime(date('Y') . '-12-31') - strtotime(date('Y-m-d'))) / 86400));
        } elseif ($retire('/\b(?:d ici|dans) (?:un|1) mois\b/')) {
            $c['jours'] = 30;
        }
    }

    /**
     * « mercredi », « en octobre » : sans précision, le passé ou le futur selon
     * la question — ce qui est prévu est à venir, une demande ou un document est
     * déjà là. Déterministe : même question, même jour, même date.
     */
    private function resoudreDates(string $t0, array &$c): void
    {
        $f = &$c['filtres'];
        $t = ' ' . $t0 . ' ';
        $futur = ($f['statut'] ?? null) === 'planifie' || $c['tri'] === 'avenir'
            || preg_match('/\b(?:prevu\w*|planifie\w*|programme\w*|a faire|sera|seront|va|vont|a venir|qui vient)\b/', $t);
        $passe = !$futur && (in_array($c['type'], ['demandes', 'documents'], true)
            || in_array($f['statut'] ?? null, ['realise', 'termine', 'traite', 'refuse'], true) || $c['tri'] === 'recent'
            || preg_match('/\b(?:a ete|ont ete|a eu|ont eu|s est passe\w*|se sont passe\w*|cree\w*|signale\w*|realise\w*|recu\w*|ajoute\w*|depose\w*|ouvert\w*|faite?s?)\b/', $t));
        if (isset($c['_jour_semaine'])) {
            [$n, $precision] = $c['_jour_semaine'];
            $en = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$n - 1];
            $auj = (int)date('N') === $n;
            $ts = match (true) {
                in_array($precision, ['prochain', 'qui vient'], true) => strtotime('next ' . $en),
                in_array($precision, ['dernier', 'passe'], true)      => strtotime('last ' . $en),
                $auj                                                 => strtotime('today'),
                (bool)$passe                                         => strtotime('last ' . $en),
                default                                              => strtotime('next ' . $en),
            };
            if (empty($f['jour'])) $f['jour'] = date('Y-m-d', $ts);
        }
        if (isset($c['_mois_seul'])) {
            $m = (int)$c['_mois_seul'];
            $courant = (int)date('n');
            $a = !empty($f['annee']) ? (int)$f['annee']
               : ((int)date('Y') + ($futur && $m < $courant ? 1 : 0) - ($passe && $m > $courant ? 1 : 0));
            unset($f['annee']);
            if (empty($f['jour'])) $f['mois'] = sprintf('%04d-%02d', $a, $m);
        }
        unset($c['_jour_semaine'], $c['_mois_seul']);
    }

    private function extraireEtage(string &$t, array &$c): void
    {
        $n = AssistantLangue::extraireEtage($t);
        if ($n !== null) $c['filtres']['etage'] = $n;
    }

    private function extraireBatiment(string &$t, array &$c): void
    {
        [$canon, $brut] = AssistantLangue::extraireBatiment($t, $this->site()['batiments'] ?? []);
        if ($canon !== null) $c['filtres']['batiment'] = $canon;
        // Inconnu du site : gardé tel quel, il sert de filtre souple (« bâtiment C »).
        elseif ($brut !== null) $c['filtres']['batiment'] = strlen($brut) <= 2 ? strtoupper($brut) : AssistantLangue::capitale($brut);
    }

    private function extraireType(string &$t, array &$c): void
    {
        $trouves = [];
        foreach (AssistantLangue::TYPES as $type => $re) {
            if (preg_match($re, $t)) $trouves[] = $type;
        }
        if (!$trouves) return;
        // « en stock » accolé à un autre type = état des biens/équipements, pas le magasin.
        $autres = array_values(array_diff($trouves, ['stock', 'plans']));
        if ($autres) {
            $c['type'] = $autres[0];
            if (in_array('stock', $trouves, true) && preg_match('/\ben stock\b/', $t) && in_array($c['type'], ['biens', 'equipements'], true)) {
                $c['filtres']['etat'] = 'stock';
            }
        } else {
            $c['type'] = in_array('stock', $trouves, true) ? 'stock' : 'plans';
        }
        if ($c['type'] === 'plans' && preg_match('/\b(point|zone|trait|texte)s?\b/', $t, $m)) $c['filtres']['type_element'] = $m[1];
        if ($c['type'] === 'interventions') {
            if (preg_match('/\bpreventives?\b/', $t)) $c['filtres']['type_interv'] = 'preventive';
            elseif (preg_match('/\bcuratives?\b|\bdepannages?\b|\breparations?\b/', $t)) $c['filtres']['type_interv'] = 'curative';
            elseif (preg_match('/\bcontroles? reglementaires?\b/', $t)) $c['filtres']['type_interv'] = 'reglementaire';
        }
        $t = preg_replace('/\ben stock\b/', ' ', $t);
        foreach (AssistantLangue::TYPES as $re) $t = preg_replace($re, ' ', $t);
    }

    private function extraireStatuts(string &$t, array &$c, string $intention): void
    {
        $f = &$c['filtres'];
        $table = [
            // clé canonique => motif (le type décide ensuite de la valeur réelle en base)
            'en_cours'     => '/\ben cours\b|\bcommence\w*\b|\bdemarre\w*\b/',
            'planifie'     => '/\bplanifie\w*\b|\bprogramme\w*\b|\bprevue?s?\b|\ba venir\b/',
            'realise'      => '/\brealise\w*\b|\beffectue\w*\b|\bfaite?s?\b(?! (?:par|le|la|les|une|un))/',
            'termine'      => '/\btermine\w*\b|\bcloture\w*\b|\bfinie?s?\b|\bachevee?s?\b|\bvalidee?s?\b|\bclose?s?\b|\bfermee?s?\b/',
            'archive'      => '/\barchivee?s?\b/',
            'nouveau'      => '/\bnouvel\w*\b|\bnouveaux?\b|\bnon trait\w+\b|\ben attente\b|\bpas encore traite\w*\b|\ba traiter\b/',
            'traite'       => '/\b(?<!non )traite\w*\b|\bresolue?s?\b|\bregle\w*\b/',
            'refuse'       => '/\brefuse\w*\b|\brejete\w*\b/',
            'relance'      => '/\brelance\w*\b/',
            'actif'        => '/\bactifs?\b|\bactives?\b|\ben vigueur\b|\bvalides\b|\bvalable\w*\b/',
            'expire'       => '/\bexpire\w*\b|\bechu\w*\b|\bperime\w*\b/',
            'resilie'      => '/\bresilie\w*\b/',
            'hors_service' => '/\bhors service\b|\bhs\b|\ben panne\b|\bdefectueu\w+\b|\bcasse\w*\b/',
            'utilise'      => '/\butilise\w*\b|\ben service\b|\ben place\b|\binstalle\w*\b/',
            'jete'         => '/\bjete\w*\b|\brecycle\w*\b|\breforme\w*\b|\bmis au rebut\b/',
        ];
        // En alertes, « expirent », « en retard », « à traiter » disent la catégorie : pas de filtre de statut.
        $skip = $intention === 'alertes' ? ['expire', 'nouveau'] : [];
        // « le contrat Dalkia se termine quand ? » : le verbe porte la question, ce n'est pas un statut.
        if ($intention === 'quand') {
            $t = preg_replace('/\b(?:se |s )?(?:termine\w*|finit|finissent|fini\w*|expire\w*|echoit|echu\w*|commence\w*|debute\w*|demarre\w*|arrive\w* a (?:echeance|terme)|prend\w* fin)\b/', ' ', $t);
        }
        foreach ($table as $cle => $re) {
            if (in_array($cle, $skip, true)) continue;
            if (preg_match($re, $t)) { $f['statut'] = $cle; $t = preg_replace($re, ' ', $t); break; }
        }
        if (preg_match('/\b(?:tres )?urgent\w*\b|\ben urgence\b/', $t)) { $f['urgence'] = 'urgente'; $t = preg_replace('/\b(?:tres )?urgent\w*\b|\ben urgence\b/', ' ', $t); }
        elseif (preg_match('/\b(?:priorite |urgence )?(?:haute|elevee|importante?s?|prioritaires?)\b/', $t)) { $f['urgence'] = 'haute'; $t = preg_replace('/\b(?:priorite |urgence )?(?:haute|elevee|importante?s?|prioritaires?)\b/', ' ', $t); }
        elseif (preg_match('/\b(?:priorite |urgence )?(?:basse|faible)s?\b/', $t)) { $f['urgence'] = 'basse'; $t = preg_replace('/\b(?:priorite |urgence )?(?:basse|faible)s?\b/', ' ', $t); }
        $t = preg_replace('/\b(?:preventives?|curatives?|controles? reglementaires?)\b/', ' ', $t);
    }

    private function extraireReference(string $t0, array &$c): void
    {
        $t = ' ' . $t0 . ' ';
        if (preg_match('/^ (?:et )?(?:le|la|l|les)? ?(premier|premiere|deuxieme|second|seconde|troisieme|quatrieme|cinquieme|dernier|derniere|(\d{1,2}) ?(?:er|ere|e|eme|em|nd|nde)?)( (?:de la liste|resultat|element|equipement|bien|contrat|demande|intervention|article|document))?(?: \?)? $/', $t, $m)) {
            // « le dernier contrat » : le plus récent, pas « le dernier de la liste précédente ».
            $avecType = isset($m[3]) && $m[3] !== '' && !in_array(trim($m[3]), ['de la liste', 'resultat', 'element'], true);
            if (!($avecType && in_array($m[1], ['dernier', 'derniere'], true))) {
                $c['rang'] = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : (in_array($m[1], ['dernier', 'derniere'], true) ? -1 : (AssistantLangue::ORDINAUX[$m[1]] ?? null));
            }
        }
        if (preg_match('/\b(?:ou (?:est|se trouve|sont|se trouvent) (?:t )?(?:il|elle|ils|elles)|(?:est|sont) (?:t )?(?:il|elle|ils|elles)|sa fiche|son emplacement|sa localisation|ses details|son statut|son etat|celui ci|celle ci|celui la|celle la|ce dernier|cette derniere|le localiser|la localiser|les localiser|y acceder|(?:cet|cette|ce) (?:equipement|element|bien|contrat|intervention|demande|article|document)(?! [a-z]{3,}))\b/', $t)) {
            $c['pronom'] = true;
        }
    }

    private function extraireNumeros(string &$t, array &$c): void
    {
        // « numéro 2 », « n°2 », « extincteur 2 », « le 3 »
        if (preg_match_all('/\b(?:numero )?(\d{1,6})(?: ?(?:er|ere|e|eme|em|nd|nde))?\b/', $t, $mm)) {
            foreach ($mm[1] as $n) $c['numeros'][] = (string)(int)$n;
            $t = preg_replace('/\b(?:numero )?\d{1,6}(?: ?(?:er|ere|e|eme|em|nd|nde))?\b/', ' ', $t);
        }
        if (preg_match_all('/\bnumero (un|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\b/', $t, $mm)) {
            foreach ($mm[1] as $n) $c['numeros'][] = (string)AssistantLangue::NOMBRES[$n];
            $t = preg_replace('/\bnumero (?:un|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\b/', ' ', $t);
        }
        $c['numeros'] = array_values(array_unique($c['numeros']));
        // « demande 12 », « la demande numéro 12 » : l'identifiant d'une demande est son numéro.
        if (!$c['id'] && $c['type'] === 'demandes' && count($c['numeros']) === 1 && (int)$c['numeros'][0] > 0) {
            $c['id'] = (int)$c['numeros'][0];
        }
    }

    private function motsCles(string $t, string $intention): array
    {
        $exclus = array_flip(array_merge(AssistantLangue::VIDES, AssistantLangue::OUTILS, AssistantLangue::MOTS_TYPES,
            array_keys(AssistantLangue::ORDINAUX), [
            'etage', 'etages', 'batiment', 'batiments', 'bat', 'bureau', 'rdc', 'combiens', 'kombien', 'quantites',
            'alerte', 'alertes', 'retard', 'retards', 'traiter', 'expirent', 'expire', 'expirant', 'expiration',
            'echeance', 'echeances', 'renouveler', 'seuil', 'rupture', 'manque', 'manquent', 'commander', 'bas',
            'faible', 'urgent', 'urgente', 'urgents', 'urgentes', 'quoi', 'neuf', 'responsable', 'responsables',
            'gestionnaire', 'gestionnaires', 'technicien', 'techniciens', 'contacter', 'joindre', 'appeler',
            'qui', 'est', 'sont', 'soccupe', 'occupe', 'gere', 'montant', 'montants', 'cout', 'couts', 'coute', 'coutent',
            'budget', 'depense', 'depenses', 'valeur', 'prix', 'somme', 'annuel', 'annuelle', 'annuels', 'quand',
            'reste', 'restent', 'il', 'en', 'fin', 'terme', 'modules', 'module', 'familles', 'famille', 'categories',
            'categorie', 'marques', 'calques', 'societes', 'prestataires', 'fournisseurs', 'annee', 'mois', 'semaine',
            'jour', 'jours', 'mettre', 'mis', 'placer', 'passe', 'passee', 'rangee', 'ranger', 'depuis', 'jusqu',
            'resultat', 'resultats', 'rapide', 'rapidement', 'vite', 'possible', 'donc', 'hein', 'svp', 'please',
            'interlocuteur', 'interlocuteurs', 'annuaire', 'coordonnees', 'telephone', 'tel', 'mail', 'email',
            'courriel', 'portable', 'adresse', 'mobile', 'fixe', 'ligne', 'ci', 'la', 'avez', 'avons', 'ont',
        ]));
        $garde = ($intention === 'personne')
            ? array_flip(['responsable', 'responsables'])    // restent visibles pour la détection, filtrés ensuite
            : [];
        $mots = [];
        foreach (AssistantLangue::mots(trim(preg_replace('/\s+/', ' ', $t))) as $w) {
            if ($w === '' || ctype_digit($w) || strlen($w) < 2) continue;
            if (isset($exclus[$w]) && !isset($garde[$w])) continue;
            if (preg_match('/^[#+\-\/]+$/', $w)) continue;
            $mots[] = $w;
        }
        return array_values(array_unique($mots));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Suites de conversation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fusion d'une question de suite avec la précédente : ce qui est dit
     * maintenant prime, le reste est repris. « et au bâtiment B ? » après
     * « combien d'extincteurs au bâtiment A ? » = combien d'extincteurs au B.
     */
    private function fusionner(array $c, array $p): array
    {
        $m = $c;
        $explicite = in_array($c['intention'], self::EXPLICITES, true) && $c['intention'] !== 'lister';
        if (!$explicite || $c['intention'] === 'inconnue' || ($c['intention'] === 'chercher' && !$c['mots'] && !$c['type'])) {
            $m['intention'] = in_array($p['intention'], ['inconnue', 'politesse', 'aide', 'hors_sujet'], true) ? 'chercher' : $p['intention'];
        }
        $m['type'] ??= $p['type'];
        if (!$c['mots'] && !$c['codes'] && !$c['id']) {
            $m['mots'] = $p['mots'];
            $m['codes'] = $p['codes'];
            $m['id'] = $p['id'];
            if (!$c['numeros']) $m['numeros'] = $p['numeros'];
        }
        $m['filtres'] = array_merge($p['filtres'], $c['filtres']);
        // Un type différent de la question précédente rend caducs les filtres qui lui étaient propres.
        if ($c['type'] && $p['type'] && $c['type'] !== $p['type']) {
            unset($m['filtres']['statut'], $m['filtres']['type_interv'], $m['filtres']['type_element'], $m['filtres']['etat']);
            if (!$c['mots']) { $m['mots'] = []; $m['numeros'] = $c['numeros']; }
        }
        $m['alerte'] ??= $p['alerte'];
        $m['jours'] ??= $p['jours'];
        $m['role'] ??= $p['role'];
        if (!$m['personne']) $m['personne'] = $p['personne'];
        $m['tri'] ??= $p['tri'];
        $m['suite'] = true;
        $m['confiance'] = $p['confiance'] === 'basse' ? $c['confiance'] : 'haute';
        return $m;
    }

    /** [question, réponse] de l'échange précédent (n-ième en partant de la fin). */
    private function echangePrecedent(array $historique, int $n = 1): array
    {
        $q = null; $r = null; $vus = 0;
        for ($i = count($historique) - 1; $i >= 0; $i--) {
            $h = $historique[$i];
            if (!is_array($h)) continue;
            $role = $h['role'] ?? '';
            if ($role === 'assistant' && $r === null && $vus === $n - 1) $r = (string)($h['content'] ?? '');
            if ($role === 'user') {
                $vus++;
                if ($vus === $n) { $q = trim((string)($h['content'] ?? '')); break; }
                $r = null;
            }
        }
        return [$q !== '' ? $q : null, $r];
    }

    /**
     * Élément cité dans une réponse : [FICHE:type:id:…], [FICHE:plan:etage:element:…],
     * [DOC:id:…]. $rang : 1 = premier, -1 = dernier, null = le premier cité.
     */
    public static function entiteCitee(string $reponse, ?int $rang = null): ?array
    {
        if (!preg_match_all('/\[(FICHE|DOC):([^\]]+)\]/', $reponse, $mm, PREG_SET_ORDER)) return null;
        $liste = [];
        foreach ($mm as $m) {
            $p = explode(':', $m[2]);
            if ($m[1] === 'DOC') { if (ctype_digit($p[0] ?? '')) $liste[] = ['type' => 'documents', 'id' => (int)$p[0]]; continue; }
            $t = $p[0] ?? '';
            if ($t === 'plan' || $t === 'plans') continue;   // lien « voir sur le plan » : pas une fiche
            $map = array_flip(AssistantLangue::TYPE_FICHE);
            if (isset($map[$t]) && ctype_digit($p[1] ?? '')) $liste[] = ['type' => $map[$t], 'id' => (int)$p[1]];
        }
        // Un même élément peut être cité deux fois (fiche + plan) : on dédoublonne dans l'ordre.
        $vu = []; $u = [];
        foreach ($liste as $e) { $k = $e['type'] . ':' . $e['id']; if (!isset($vu[$k])) { $vu[$k] = 1; $u[] = $e; } }
        if (!$u) return null;
        if ($rang === null || $rang === 0) return $u[0];
        if ($rang < 0) return $u[count($u) - 1];
        return $u[$rang - 1] ?? null;
    }
}
