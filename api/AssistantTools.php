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
 * Larka — Assistant IA : outils de lecture (tool calling)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Expose une poignée de fonctions PHP au LLM via le « tool calling » standard
 * (Anthropic / OpenAI / Mistral / Gemini / Ollama).
 *
 * PRINCIPES
 *   - LECTURE UNIQUEMENT — aucun outil ne modifie la BDD.
 *   - Anonymisation RGPD systématique (sauf l'annuaire pro `qui_est`).
 *   - SOBRIÉTÉ : chaque octet renvoyé repart dans le contexte du modèle. Sur un
 *     modèle local CPU, 1 000 tokens de résultat = 10 à 30 s de traitement.
 *     Les résultats sont donc COMPACTÉS (champs vides retirés, textes tronqués,
 *     lignes limitées, classées par pertinence) — voir compact() / encode().
 *   - Les schémas d'outils sont STABLES d'une question à l'autre (même ordre,
 *     mêmes descriptions) : c'est ce qui permet aux moteurs de réutiliser leur
 *     cache de préfixe (Ollama, llama.cpp) ou leur cache de prompt facturé
 *     moins cher (Anthropic, OpenAI, Gemini).
 *
 * MODULES COMPLÉMENTAIRES (extensions déclaratives)
 *   L'outil `module` n'est exposé que si au moins un module installé, actif et
 *   visible pour l'utilisateur existe (ou, pour un gestionnaire, si la couche
 *   extensions est active — il peut alors apprendre qu'un module n'est PAS
 *   installé). La lecture passe par le moteur déclaratif, donc par la couche
 *   de sécurité des extensions et les rôles accordés par l'administrateur.
 *
 * UTILISATION
 *   $tools = new AssistantTools($db, $msToken, ['max_resultats' => 8, 'modules' => [...]]);
 *   $tools->getOpenAISchema() / getAnthropicSchema() / getGeminiSchema()
 *   $tools->execute('search', ['type' => 'biens', 'query' => 'centrale']);
 *   $tools->encode($result)   // JSON compact, borné en taille
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/AssistantLangue.php';

class AssistantTools {
    private Database $db;
    private ?string $msToken;
    private array $user;
    /** Nombre maximal de lignes renvoyées au modèle par liste. */
    private int $maxResults;
    /** Longueur maximale d'un texte renvoyé au modèle. */
    private int $maxChars;
    /** Taille maximale (caractères) d'un résultat d'outil sérialisé. */
    private int $maxToolChars;
    /** Catalogue des modules installés visibles : id => [nom, jeux, pages, declaration]. */
    private array $modules;
    /** Rappel paresseux : modules disponibles mais non installés (gestionnaires). */
    private $modulesDisponibles;
    private bool $extActif;
    private array $schemaCache = [];

    /** Types acceptés par search / compter (clé outil → clé de searchForAssistant). */
    private const SEARCH_MAP = [
        'biens'         => 'biens',
        'equipements'   => 'equipements',
        'interventions' => 'interventions',
        'contrats'      => 'contrats',
        'demandes'      => 'demandesintervention',
        'stock'         => 'stock',
        'documents'     => 'documents',
        'plans'         => 'plan_elements',
        'archives'      => 'archives_dossiers',
    ];

    /** Clés jamais utiles au modèle (bruit, coordonnées brutes, traçabilité). */
    private const DROP_KEYS = [
        'Coords', 'Icone', 'Couleur', 'Echelle', 'FondLargeur', 'FondHauteur',
        'PointCoords', 'Latitude', 'Longitude', 'lat', 'lng',
        'CreatedBy', 'UpdatedBy', 'UpdatedAt', 'SupprimeParLogin', 'SaisieParLogin',
        'AjoutePar', 'cree_par', 'modifie_par', 'modifie_le', 'MotDePasse', 'Password',
        'ContactsJSON', 'DetailSousCompteurs', 'PlagesAvance', 'Token', 'MsId', 'GoogleId',
    ];

    /** Segments de nom de champ désignant une personne (champs de modules). */
    private const PERSON_SEGMENTS = [
        'nom', 'prenom', 'detenteur', 'titulaire', 'responsable', 'agent', 'demandeur',
        'contact', 'utilisateur', 'beneficiaire', 'conducteur', 'personne', 'salarie',
        'stagiaire', 'participant', 'formateur', 'referent', 'emprunteur',
    ];

    public function __construct(Database $db, ?string $msToken = null, array $opts = []) {
        $this->db            = $db;
        $this->msToken       = $msToken;
        $this->user          = $opts['user'] ?? [];
        $this->maxResults    = max(3, min(30, (int)($opts['max_resultats'] ?? 12)));
        $this->maxChars      = max(40, min(400, (int)($opts['max_chars'] ?? 120)));
        $this->maxToolChars  = max(1500, (int)($opts['max_tool_chars'] ?? 12000));
        $this->modules       = $opts['modules'] ?? [];
        $this->modulesDisponibles = $opts['modules_disponibles'] ?? null;
        $this->extActif      = (bool)($opts['ext_actif'] ?? false);
    }

    public function hasModules(): bool { return $this->modules !== []; }
    public function modules(): array  { return $this->modules; }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Définitions (source unique, rendue dans les 3 formats) ──
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Format interne d'un paramètre : type, description?, required?, enum?,
    // items? (tableaux), properties? (objets : liste de clés chaîne).
    // Les objets reçoivent TOUJOURS des propriétés explicites : Gemini refuse un
    // objet vide, et un petit modèle local devine bien mieux les bons filtres
    // quand on les lui nomme.

    private function definitions(): array {
        $filtresSearch = ['statut', 'type', 'famille', 'batiment', 'etage', 'etat',
                          'categorie', 'urgence', 'societe', 'marque'];
        $filtresCompter = ['statut', 'type', 'famille', 'batiment', 'etage', 'etat',
                           'categorie', 'urgence', 'priorite', 'societe', 'marque',
                           'emplacement', 'annee', 'type_element', 'calque', 'etage_id'];
        $defs = [
            [
                'name' => 'search',
                'description' => "Recherche par mots-clés, résultats triés par pertinence (max {$this->maxResults}). type='tout' = tous les types d'un coup.",
                'parameters' => [
                    'type'    => ['type' => 'string', 'required' => true,
                                  'enum' => array_merge(array_keys(self::SEARCH_MAP), ['tout'])],
                    'query'   => ['type' => 'string', 'required' => true, 'description' => 'Mots-clés courts (acronymes OK : SSI, BAES, CTA…)'],
                    'filtres' => ['type' => 'object', 'description' => 'Filtres optionnels (contient)', 'properties' => $filtresSearch],
                ],
            ],
            [
                'name' => 'get_fiche',
                'description' => "Fiche complète d'UN élément (après search).",
                'parameters' => [
                    'type' => ['type' => 'string', 'required' => true, 'enum' => ['bien', 'equipement', 'intervention', 'contrat', 'demande', 'stock']],
                    'id'   => ['type' => 'integer', 'required' => true],
                ],
            ],
            [
                'name' => 'localiser',
                'description' => "Où est un bien/équipement : bâtiment, étage, bureau et position sur plan (sur_plan).",
                'parameters' => [
                    'type' => ['type' => 'string', 'required' => true, 'enum' => ['bien', 'equipement']],
                    'id'   => ['type' => 'integer', 'required' => true],
                ],
            ],
            [
                'name' => 'localiser_groupe',
                'description' => "Localise PLUSIEURS biens/équipements en un appel, regroupés par étage.",
                'parameters' => [
                    'type' => ['type' => 'string', 'required' => true, 'enum' => ['bien', 'equipement']],
                    'ids'  => ['type' => 'array', 'required' => true, 'items' => 'integer'],
                ],
            ],
            [
                'name' => 'compter',
                'description' => "Nombre exact d'éléments (sans limite). type='plans' = éléments dessinés (filtre type_element: point/zone/trait/texte).",
                'parameters' => [
                    'type'    => ['type' => 'string', 'required' => true,
                                  'enum' => ['biens', 'equipements', 'interventions', 'contrats', 'demandes', 'stock', 'plans']],
                    'filtres' => ['type' => 'object', 'description' => 'annee=AAAA ; autres = contient', 'properties' => $filtresCompter],
                ],
            ],
            [
                'name' => 'alertes',
                'description' => "Alertes : contrats qui expirent, stock sous seuil, interventions en retard, demandes non traitées.",
                'parameters' => [
                    'categorie' => ['type' => 'string', 'enum' => ['toutes', 'contrats', 'stock', 'interventions', 'demandes']],
                    'jours'     => ['type' => 'integer', 'description' => 'Horizon contrats (défaut 30)'],
                ],
            ],
            [
                'name' => 'contexte',
                'description' => "Vocabulaire du site : bâtiments, familles, catégories, gestionnaires.",
                'parameters' => [],
            ],
            [
                'name' => 'qui_est',
                'description' => "Annuaire interne : nom, rôle, service, poste, téléphone pro.",
                'parameters' => [
                    'query' => ['type' => 'string', 'description' => 'Nom, service, poste…'],
                    'role'  => ['type' => 'string', 'enum' => ['Demandeur', 'Gestionnaire', 'Technicien', 'Admin', 'Visionneur']],
                    'id'    => ['type' => 'integer', 'description' => 'Id utilisateur (ex. UtilisateurId d\'une demande)'],
                ],
            ],
        ];

        // SharePoint : seulement si l'utilisateur a un jeton Microsoft valide.
        if ($this->msToken) {
            $defs[] = [
                'name' => 'search_sharepoint',
                'description' => "Documents SharePoint/OneDrive de l'utilisateur (si absents de Larka).",
                'parameters' => [
                    'query' => ['type' => 'string', 'required' => true],
                ],
            ];
        }

        // Modules complémentaires installés (extensions déclaratives).
        if ($this->modules !== [] || $this->extActif) {
            $liste = [];
            foreach ($this->modules as $id => $m) {
                $jeux = [];
                foreach ($m['jeux'] as $nom => $j) $jeux[] = $nom . '=' . $j['libelle'];
                $liste[] = $id . ' « ' . $m['nom'] . ' » (jeux : ' . implode(', ', $jeux) . ')';
            }
            $defs[] = [
                'name' => 'module',
                'description' => 'Données des modules complémentaires installés. '
                    . ($liste ? 'Installés : ' . implode(' ; ', $liste) . '.' : 'Aucun module installé.')
                    . ' Sans « module » : catalogue (installés et non installés).',
                'parameters' => array_filter([
                    'module'    => $this->modules
                        ? ['type' => 'string', 'enum' => array_keys($this->modules)]
                        : ['type' => 'string'],
                    'jeu'       => ['type' => 'string', 'description' => 'Jeu de données (défaut : le premier)'],
                    'recherche' => ['type' => 'string', 'description' => 'Mots-clés (vide = derniers enregistrements)'],
                ]),
            ];
        }
        return $defs;
    }

    /** Schéma JSON d'un paramètre interne. */
    private static function paramSchema(array $p, bool $gemini = false): array {
        $s = ['type' => $p['type']];
        if (!empty($p['description'])) $s['description'] = $p['description'];
        if (!empty($p['enum'])) $s['enum'] = $p['enum'];
        if ($p['type'] === 'array') $s['items'] = ['type' => $p['items'] ?? 'string'];
        if ($p['type'] === 'object') {
            $props = [];
            foreach ($p['properties'] ?? ['valeur'] as $k) $props[$k] = ['type' => 'string'];
            $s['properties'] = $props;
        }
        return $s;
    }

    private function objectSchema(array $params, bool $gemini = false): ?array {
        if (!$params) return $gemini ? null : ['type' => 'object', 'properties' => (object)[], 'required' => []];
        $props = []; $req = [];
        foreach ($params as $name => $p) {
            $props[$name] = self::paramSchema($p, $gemini);
            if (!empty($p['required'])) $req[] = $name;
        }
        $s = ['type' => 'object', 'properties' => $props];
        if ($req || !$gemini) $s['required'] = $req;
        return $s;
    }

    /** Format OpenAI / Mistral / Ollama / LM Studio / llama.cpp. */
    public function getOpenAISchema(): array {
        return $this->schemaCache['openai'] ??= array_map(fn($d) => [
            'type' => 'function',
            'function' => [
                'name'        => $d['name'],
                'description' => $d['description'],
                'parameters'  => $this->objectSchema($d['parameters']),
            ],
        ], $this->definitions());
    }

    /** Format Anthropic (input_schema). */
    public function getAnthropicSchema(): array {
        return $this->schemaCache['anthropic'] ??= array_map(fn($d) => [
            'name'         => $d['name'],
            'description'  => $d['description'],
            'input_schema' => $this->objectSchema($d['parameters']),
        ], $this->definitions());
    }

    /** Format Gemini (functionDeclarations ; pas de `parameters` si aucun paramètre). */
    public function getGeminiSchema(): array {
        return $this->schemaCache['gemini'] ??= array_map(function ($d) {
            $f = ['name' => $d['name'], 'description' => $d['description']];
            $s = $this->objectSchema($d['parameters'], true);
            if ($s !== null) $f['parameters'] = $s;
            return $f;
        }, $this->definitions());
    }

    /** Noms des outils exposés (pour le nettoyage de sortie). */
    public function names(): array {
        return $this->schemaCache['names'] ??= array_column($this->definitions(), 'name');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Exécution ──
    // ═══════════════════════════════════════════════════════════════════════

    /** Exécute un outil. Renvoie toujours un array (jamais throw). */
    public function execute(string $toolName, array $args): array {
        $method = 'tool_' . preg_replace('/[^a-z_]/', '', $toolName);
        if (!in_array($toolName, $this->names(), true) || !method_exists($this, $method)) {
            return ['error' => "Outil inconnu : $toolName", 'outils' => $this->names()];
        }
        try {
            $result = $this->$method($args);
            // L'annuaire expose volontairement les coordonnées PRO (son utilité) ;
            // les coordonnées personnelles sont filtrées en amont par tool_qui_est().
            if ($toolName !== 'qui_est') $result = $this->anonymize($result);
            // Une fiche a droit à des textes plus longs qu'une ligne de liste.
            return $this->compact($result, $toolName === 'get_fiche' ? max($this->maxChars, 240) : null);
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] tool error: ' . $e->getMessage());
            return ['error' => "Une erreur interne est survenue lors de l'exécution de l'outil."];
        }
    }

    /**
     * Sérialise un résultat pour le modèle : JSON compact, borné en taille.
     * Si c'est trop long, on retire des lignes (jamais le compte total) —
     * mieux vaut 5 résultats lisibles qu'un contexte tronqué en silence.
     */
    public function encode(array $result): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($result, $flags) ?: '{}';
        $guard = 0;
        while (strlen($json) > $this->maxToolChars && $guard++ < 6) {
            if (!$this->shrink($result)) break;
            $result['tronque'] = true;
            $json = json_encode($result, $flags) ?: '{}';
        }
        if (strlen($json) > $this->maxToolChars) {
            $json = json_encode(['tronque' => true, 'extrait' => mb_substr($json, 0, $this->maxToolChars - 60)], $flags);
        }
        return $json;
    }

    /** Divise par deux la plus longue liste du résultat. false si rien à réduire. */
    private function shrink(array &$r): bool {
        // 1) Trouver le chemin de la plus longue liste (parcours sans référence).
        $bestPath = null; $bestLen = 1;
        $stack = [[$r, []]];
        while ($stack) {
            [$node, $path] = array_pop($stack);
            foreach ($node as $k => $v) {
                if (!is_array($v)) continue;
                if (array_is_list($v) && count($v) > $bestLen) { $bestLen = count($v); $bestPath = array_merge($path, [$k]); }
                $stack[] = [$v, array_merge($path, [$k])];
            }
        }
        if ($bestPath === null) return false;
        // 2) Y descendre par référence et couper.
        $ref = &$r;
        foreach ($bestPath as $k) $ref = &$ref[$k];
        $ref = array_slice($ref, 0, max(1, intdiv(count($ref), 2)));
        unset($ref);
        return true;
    }

    /**
     * Pré-recherche (mode rapide CPU) : un search(type='tout') exécuté AVANT le
     * premier appel au modèle et injecté comme s'il l'avait demandé lui-même.
     * Le modèle répond alors souvent dès le premier tour — une inférence
     * complète économisée (génération de l'appel d'outil + aller-retour).
     */
    public function prefetch(string $question): ?array {
        [$orig, , $nums] = $this->keywords($question);
        $orig = array_values(array_filter($orig, fn($w) => mb_strlen($w) >= 3));
        if (!$orig) return null;
        // Les numéros (« extincteur 2 ») restent dans la requête : ils départagent.
        $args = ['type' => 'tout', 'query' => implode(' ', array_merge(array_slice($orig, 0, 6), array_slice($nums, 0, 2)))];
        return ['name' => 'search', 'args' => $args, 'result' => $this->execute('search', $args)];
    }

    /**
     * Type canonique, quelle que soit la façon dont le modèle (ou l'utilisateur)
     * l'écrit : « bien », « Biens », « équipement », « articles »… Un petit
     * modèle écrit souvent le singulier ; « Type inconnu » le faisait
     * abandonner (« je ne trouve rien » à « combien de biens ? »).
     */
    public static function normType(string $t): string {
        $t = self::norm(trim($t));
        $t = preg_replace('/[^a-z_]/', '', $t);
        $map = [
            'bien' => 'biens', 'biens' => 'biens',
            'equipement' => 'equipements', 'equipements' => 'equipements', 'equip' => 'equipements',
            'intervention' => 'interventions', 'interventions' => 'interventions', 'interv' => 'interventions',
            'contrat' => 'contrats', 'contrats' => 'contrats',
            'demande' => 'demandes', 'demandes' => 'demandes', 'demandesintervention' => 'demandes',
            'stock' => 'stock', 'stocks' => 'stock', 'article' => 'stock', 'articles' => 'stock',
            'document' => 'documents', 'documents' => 'documents', 'doc' => 'documents', 'docs' => 'documents',
            'plan' => 'plans', 'plans' => 'plans', 'point' => 'plans', 'points' => 'plans', 'zone' => 'plans',
            'zones' => 'plans', 'element' => 'plans', 'elements' => 'plans', 'planelements' => 'plans',
            'archive' => 'archives', 'archives' => 'archives', 'dossier' => 'archives', 'dossiers' => 'archives',
            'tout' => 'tout', 'tous' => 'tout', 'all' => 'tout', 'toutes' => 'tout',
        ];
        return $map[$t] ?? $t;
    }

    /**
     * Pré-comptage (questions « combien… ») : si la question nomme un type
     * (« combien de biens au bâtiment A ? »), le comptage exact est fait AVANT
     * d'interroger le modèle, avec les filtres reconnus (bâtiment, statut,
     * année). S'il reste des mots précis (« combien de biens informatiques »),
     * c'est une recherche sur ce type ; sans type (« combien d'extincteurs »),
     * la recherche globale. Le modèle n'a plus qu'à formuler la réponse.
     */
    public function prefetchComptage(string $question): ?array {
        $q = self::norm($question);
        $types = [
            'biens' => 'biens?', 'equipements' => 'equipements?', 'interventions' => 'interventions?',
            'contrats' => 'contrats?', 'demandes' => 'demandes?', 'stock' => 'articles?|stocks?|references? en stock',
            'plans' => 'points?|zones?|traits?|elements? (?:dessines|sur les plans)',
        ];
        $type = null;
        foreach ($types as $t => $re) {
            if (preg_match('/\b(?:' . $re . ')\b/u', $q)) { $type = $t; break; }
        }
        if ($type === null) return $this->prefetch($question);

        $filtres = [];
        if (preg_match('/\b(?:batiment|bat)\.?\s+([a-z0-9][\w-]*)/u', $q, $m) && !in_array($m[1], ['de', 'du', 'des', 'le', 'la'], true)) {
            $filtres['batiment'] = $m[1];
        }
        if (preg_match('/\bcette annee\b/u', $q)) $filtres['annee'] = date('Y');
        elseif (preg_match('/\b(20\d\d)\b/', $q, $m)) $filtres['annee'] = $m[1];
        if (in_array($type, ['interventions', 'demandes', 'contrats'], true)) {
            foreach (['en cours' => 'En cours', 'termine' => 'Termin', 'planifie' => 'Planifi', 'en attente' => 'attente',
                      'nouvelle' => 'Nouveau', 'non traite' => 'Nouveau', 'actif' => 'Actif', 'expire' => 'Expir'] as $k => $v) {
                if (str_contains($q, $k)) { $filtres['statut'] = $v; break; }
            }
        }
        if ($type === 'plans') {
            foreach (['point', 'zone', 'trait', 'texte'] as $te) if (preg_match('/\b' . $te . 's?\b/', $q)) { $filtres['type_element'] = $te; break; }
        }

        // Mots restants, une fois retirés le type, les filtres et le vocabulaire de question.
        $reste = preg_replace('/\b(?:combien|nombre|total|quantite|au|aux|du|de|des|d|y|a|t|il|en|ai|on|ya|avons|avez|sont|est|dans|le|la|les|l|sur|pour|cette|annee|batiment|bat|' . implode('|', $types) . ')\b/u', ' ', $q);
        if (isset($filtres['batiment'])) $reste = str_replace($filtres['batiment'], ' ', $reste);
        foreach (['en cours', 'termine', 'terminee', 'terminees', 'planifie', 'planifiee', 'planifiees', 'en attente', 'nouvelles?', 'non traitees?', 'actifs?', 'expires?', '20\d\d', 'dessines', 'plans?'] as $w) {
            $reste = preg_replace('/\b' . $w . '\b/u', ' ', $reste);
        }
        [$mots] = $this->keywords($reste);
        $mots = array_values(array_filter($mots, fn($w) => mb_strlen($w) >= 3));

        // « combien d'équipements SSI » : si le mot restant est une FAMILLE
        // connue, le comptage exact par famille vaut mieux qu'une recherche
        // plein texte (qui trouve « ssi » n'importe où et plafonne à 100).
        if (count($mots) === 1 && in_array($type, ['biens', 'equipements', 'stock'], true)) {
            $argsF = ['type' => $type, 'filtres' => $filtres + ['famille' => $mots[0]]];
            $rf = $this->execute('compter', $argsF);
            if ((int)($rf['count'] ?? 0) > 0) return ['name' => 'compter', 'args' => $argsF, 'result' => $rf];
        }
        if ($mots && $type !== 'plans') {
            $args = ['type' => $type, 'query' => implode(' ', array_slice($mots, 0, 4))];
            if (isset($filtres['batiment'])) $args['filtres'] = ['batiment' => $filtres['batiment']];
            return ['name' => 'search', 'args' => $args, 'result' => $this->execute('search', $args)];
        }
        $args = ['type' => $type] + ($filtres ? ['filtres' => $filtres] : []);
        return ['name' => 'compter', 'args' => $args, 'result' => $this->execute('compter', $args)];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Accès pour le moteur déterministe (AssistantMoteur) ──
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Mêmes données, mêmes droits, même anonymisation que les outils du
    // modèle — mais sans passer par lui. Le moteur reçoit des lignes complètes
    // et CLASSÉES, et en tire lui-même la réponse.

    /** Colonnes lues par type (liste, comptage, filtres). Aucune donnée personnelle. */
    private const TABLES = [
        'biens' => ['Biens', 'Id,Numero,Famille,SousFamille,Statut,Etat,InfoProduit,Batiment,Etage,NumeroBureau,NumeroSerie,DateCommande,DateLivraison,CreatedAt',
                    'DateSuppression IS NULL', 'Numero', ['DateLivraison', 'DateCommande', 'CreatedAt']],
        'equipements' => ['Equipements', 'Id,Numero,InfoProduit,Famille,SousFamille,Statut,Etat,Marque,Modele,Batiment,Etage,NumeroBureau,Fournisseur,DateInstallation,CreatedAt',
                    'DateSuppression IS NULL', 'Numero', ['DateInstallation', 'CreatedAt']],
        'interventions' => ['Interventions', 'Id,Numero,Type,Priorite,Statut,Description,DateIntervention,DateRealisation,DateProchaine,SocieteManuelle,Montant,MontantHT,MontantPieces,MontantMainOeuvre,EquipementsIds,BienId,DemandeId,CreatedAt',
                    '', 'Id', ['DateIntervention', 'DateRealisation', 'CreatedAt']],
        'contrats' => ['Contrats', 'Id,Numero,Societe,Type,Statut,DateDebut,DateFin,MontantAnnuel,Description,Perimetre,Frequence',
                    '', 'DateFin', ['DateDebut']],
        'demandes' => ['DemandesIntervention', 'Id,Titre,Description,Statut,Urgence,Batiment,Bureau,DateCreation,Categorie',
                    '', 'Id', ['DateCreation']],
        'stock' => ['Stock', 'Id,Reference,Designation,Categorie,Quantite,SeuilAlerte,PrixUnitaire,Emplacement,Marque,Modele,Fournisseur',
                    '', 'Designation', []],
        'documents' => ['Documents', 'Id,EntiteType,EntiteId,NomFichier,Categorie,DateAjout',
                    '', 'Id', ['DateAjout']],
        'plans' => ['PlanElements el LEFT JOIN PlanEtages et ON el.EtageId = et.Id LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id',
                    'el.Id AS Id, el.TypeElement AS TypeElement, el.SousType AS SousType, el.Calque AS Calque, el.Nom AS Nom, el.Description AS Description, el.EtageId AS EtageId, et.Nom AS EtageNom, et.Niveau AS Niveau, b.Nom AS BatimentNom',
                    '', 'el.Id', []],
        'archives' => ['ArchivesDossier', 'Id,NumeroDossier,NumeroBoite,Service,Annee,Description,Emplacement,Statut,DateSolde',
                    '', 'NumeroDossier', []],
    ];

    /** Filtres qui ont un sens pour chaque type (les autres sont signalés, pas appliqués en silence). */
    public const FILTRES_APPLICABLES = [
        'batiment'     => ['biens', 'equipements', 'demandes', 'plans', 'interventions', 'documents'],
        'etage'        => ['biens', 'equipements', 'plans', 'interventions', 'documents'],
        'bureau'       => ['biens', 'equipements', 'demandes'],
        'statut'       => ['interventions', 'demandes', 'contrats', 'biens', 'equipements', 'archives'],
        'etat'         => ['biens', 'equipements'],
        'type_interv'  => ['interventions'],
        'urgence'      => ['demandes', 'interventions'],
        'annee'        => ['biens', 'equipements', 'interventions', 'contrats', 'demandes', 'documents'],
        'mois'         => ['biens', 'equipements', 'interventions', 'contrats', 'demandes', 'documents'],
        'semaine'      => ['interventions', 'demandes', 'documents'],
        'jour'         => ['interventions', 'demandes', 'documents'],
        'type_element' => ['plans'],
        'famille'      => ['biens', 'equipements', 'stock'],
    ];

    /** Racines des statuts, par clé canonique (comparées au texte normalisé des valeurs réelles). */
    private const STATUTS = [
        'en_cours'     => ['en cours', 'commence', 'demarre'],
        'planifie'     => ['planifi', 'programm', 'prevu', 'a venir'],
        'realise'      => ['realis', 'effectu'],
        'termine'      => ['termin', 'valid', 'realis', 'clotur', 'fini', 'achev', 'clos'],
        'archive'      => ['archiv'],
        'nouveau'      => ['nouveau', 'nouvelle', 'relanc', 'attente', 'a traiter'],
        'traite'       => ['traite', 'resolu', 'clotur', 'regle'],
        'refuse'       => ['refus', 'rejet'],
        'relance'      => ['relanc'],
        'actif'        => ['actif', 'active', 'vigueur'],
        'expire'       => ['expir', 'echu', 'perime'],
        'resilie'      => ['resili'],
        'hors_service' => ['hors service', 'hs', 'panne', 'defectu', 'casse', 'reform'],
        'utilise'      => ['utilis', 'service', 'install', 'en place', 'affect'],
        'stock'        => ['stock'],
        'jete'         => ['jete', 'recycl', 'rebut', 'reform', 'don', 'vendu'],
    ];

    /** Clés à remettre en CamelCase (PostgreSQL rend les alias en minuscules). */
    private const CLES = ['EtageNom', 'BatimentNom', 'ElementNom', 'ElementId', 'EtageId', 'Niveau', 'NumeroDossier',
        'NumeroBoite', 'DateSolde', 'TypeElement', 'SousType', 'Calque', 'DateProchaine', 'MontantHT', 'DemandeId',
        'EquipementsIds', 'BienId', 'Annee', 'EtageNiveau', 'BatimentId', 'SurfaceM2', 'LongueurM', 'VuSurPlan',
        'ElementsLies', 'Intitule', 'AssetId', 'AssetType'];

    private static function remap(array $row): array {
        static $map = null;
        $map ??= array_combine(array_map('strtolower', self::CLES), self::CLES);
        $out = [];
        foreach ($row as $k => $v) $out[is_string($k) ? ($map[$k] ?? $k) : $k] = $v;
        return $out;
    }

    /**
     * Vocabulaire du site pour la compréhension : bâtiments (avec leur forme
     * courte : « Bâtiment A » → « a »), statuts, familles, catégories… lus en
     * base, une fois par requête.
     */
    private ?array $siteCache = null;
    public function vocabulaireSite(): array {
        if ($this->siteCache !== null) return $this->siteCache;
        $q = function (string $sql): array {
            try { return array_values(array_filter(array_map(fn($r) => trim((string)($r['v'] ?? '')), $this->db->fetchAll($sql)), fn($v) => $v !== '')); }
            catch (\Throwable $_) { return []; }
        };
        $bats = [];
        // Ordre de priorité de la graphie retenue : listes de référence, plans, puis données.
        foreach ([
            "SELECT Valeur AS v FROM Listes WHERE Categorie = 'Batiment' AND (Actif = 1 OR Actif IS NULL) ORDER BY Ordre, Valeur",
            "SELECT Nom AS v FROM PlanBatiments ORDER BY Nom",
            "SELECT DISTINCT Batiment AS v FROM Biens WHERE DateSuppression IS NULL ORDER BY Batiment",
            "SELECT DISTINCT Batiment AS v FROM Equipements WHERE DateSuppression IS NULL ORDER BY Batiment",
            "SELECT DISTINCT Batiment AS v FROM DemandesIntervention ORDER BY Batiment",
        ] as $sql) {
            foreach ($q($sql . ' LIMIT 500') as $b) {
                $n = AssistantLangue::preparer($b);
                $court = trim(preg_replace('/^(?:batiments?|bat|bati|bt)\s+/', '', $n));
                if ($court === '') continue;
                $cle = $court;
                if (!isset($bats[$cle])) $bats[$cle] = ['canon' => $b, 'norm' => $n, 'court' => $court];
            }
        }
        $parCanon = [];
        foreach ($bats as $b) $parCanon[$b['canon']] = ['norm' => $b['norm'], 'court' => $b['court']];

        $site = [
            'batiments'  => $parCanon,
            'mots'       => $this->vocab(),
            'categories_demandes' => array_values(array_unique(array_merge(
                $q("SELECT Valeur AS v FROM Listes WHERE Categorie = 'CategorieDemande' AND (Actif = 1 OR Actif IS NULL) ORDER BY Ordre, Valeur LIMIT 60"),
                $q("SELECT DISTINCT Categorie AS v FROM DemandesIntervention ORDER BY Categorie LIMIT 60")))),
            'familles_biens' => array_values(array_unique(array_merge(
                $q("SELECT DISTINCT Famille AS v FROM Biens WHERE DateSuppression IS NULL ORDER BY Famille LIMIT 60"),
                $q("SELECT Valeur AS v FROM Listes WHERE Categorie = 'FamilleBien' ORDER BY Ordre LIMIT 60")))),
            'familles_equipements' => array_values(array_unique(array_merge(
                $q("SELECT DISTINCT Famille AS v FROM Equipements WHERE DateSuppression IS NULL ORDER BY Famille LIMIT 60"),
                $q("SELECT Valeur AS v FROM Listes WHERE Categorie = 'FamilleEquipement' ORDER BY Ordre LIMIT 60")))),
            'categories_stock' => $q("SELECT DISTINCT Categorie AS v FROM Stock ORDER BY Categorie LIMIT 60"),
        ];
        return $this->siteCache = $site;
    }

    /** Deux noms de bâtiment désignent-ils le même bâtiment ? (« Bât. A » = « Bâtiment A ») */
    public static function memeBatiment(?string $valeur, ?string $filtre): bool {
        $a = AssistantLangue::preparer((string)$valeur); $b = AssistantLangue::preparer((string)$filtre);
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        $ca = trim(preg_replace('/^(?:batiments?|bat|bati|bt)\s+/', '', $a));
        $cb = trim(preg_replace('/^(?:batiments?|bat|bati|bt)\s+/', '', $b));
        return $ca !== '' && $ca === $cb;
    }

    /** Valeur de date d'une ligne pour les filtres temporels. */
    private static function dateLigne(string $type, array $row): string {
        foreach (self::TABLES[$type][4] ?? [] as $c) if (!empty($row[$c])) return substr((string)$row[$c], 0, 10);
        return '';
    }

    /** La ligne satisfait-elle un statut canonique (« termine », « en_cours »…) ? */
    private function statutCorrespond(string $type, array $row, string $cle): bool {
        // Même mot, sens différent selon le type : une demande « terminée » est « Traité »,
        // un contrat « terminé » est « Expiré ».
        if ($type === 'demandes' && in_array($cle, ['termine', 'realise'], true)) $cle = 'traite';
        if ($type === 'contrats' && in_array($cle, ['termine', 'realise'], true)) $cle = 'expire';
        if ($type === 'contrats' && $cle === 'en_cours') $cle = 'actif';
        if ($type === 'interventions' && $cle === 'nouveau') $cle = 'planifie';
        $racines = self::STATUTS[$cle] ?? [$cle];
        $vals = in_array($type, ['biens', 'equipements'], true)
            ? [(string)($row['Etat'] ?? ''), (string)($row['Statut'] ?? '')]
            : [(string)($row['Statut'] ?? '')];
        foreach ($vals as $v) {
            $n = ' ' . AssistantLangue::preparer($v) . ' ';
            if (trim($n) === '') continue;
            foreach ($racines as $r) {
                if (self::debutDeMot($n, $r)) {
                    // « Non traité » n'est pas « traité ».
                    if ($cle === 'traite' && str_contains($n, 'non traite')) continue;
                    return true;
                }
            }
        }
        return false;
    }

    /** Filtres réellement applicables à un type (les autres sont renvoyés à part). */
    public static function filtresPour(string $type, array $filtres): array {
        $ok = []; $ignores = [];
        foreach ($filtres as $k => $v) {
            if ($v === null || $v === '' || $v === []) continue;
            if (in_array($type, self::FILTRES_APPLICABLES[$k] ?? [], true)) $ok[$k] = $v; else $ignores[$k] = $v;
        }
        return [$ok, $ignores];
    }

    /** La ligne satisfait-elle tous les filtres (canoniques) ? */
    public function correspond(string $type, array $row, array $f): bool {
        [$f] = self::filtresPour($type, $f);
        foreach ($f as $k => $v) {
            switch ($k) {
                case 'batiment':
                    // Plusieurs bâtiments possibles (intervention sur des équipements de sites différents).
                    $ok = false;
                    foreach (explode('|', (string)($row['Batiment'] ?? $row['BatimentNom'] ?? '')) as $b) if (self::memeBatiment($b, (string)$v)) { $ok = true; break; }
                    if (!$ok) return false;
                    break;
                case 'etage':
                    if ($type === 'plans') {
                        $niv = isset($row['Niveau']) && $row['Niveau'] !== '' && $row['Niveau'] !== null ? (int)$row['Niveau'] : AssistantLangue::niveau((string)($row['EtageNom'] ?? ''));
                        if ($niv !== (int)$v) return false;
                    } else {
                        $ok = false;
                        foreach (explode('|', (string)($row['Etage'] ?? '')) as $e) if (AssistantLangue::niveau($e) === (int)$v) { $ok = true; break; }
                        if (!$ok) return false;
                    }
                    break;
                case 'bureau':
                    $val = AssistantLangue::preparer((string)($row['NumeroBureau'] ?? $row['Bureau'] ?? ''));
                    if (!preg_match('/(?<![a-z0-9])' . preg_quote(AssistantLangue::preparer((string)$v), '/') . '(?![a-z0-9])/', $val)) return false;
                    break;
                case 'statut':
                    if (!$this->statutCorrespond($type, $row, (string)$v)) return false;
                    break;
                case 'etat':
                    if (!$this->statutCorrespond($type, $row, (string)$v)) return false;
                    break;
                case 'type_interv':
                    $t = AssistantLangue::preparer((string)($row['Type'] ?? ''));
                    $r = ['preventive' => 'preventi', 'curative' => 'curati', 'reglementaire' => 'reglementaire'][$v] ?? (string)$v;
                    if (!str_contains($t, $r)) return false;
                    break;
                case 'urgence':
                    $u = AssistantLangue::preparer((string)($row['Urgence'] ?? $row['Priorite'] ?? ''));
                    $ok = match ((string)$v) {
                        'urgente' => str_contains($u, 'urgent'),
                        'haute'   => str_contains($u, 'haute') || str_contains($u, 'elevee') || str_contains($u, 'urgent'),
                        'basse'   => str_contains($u, 'basse') || str_contains($u, 'faible'),
                        default   => str_contains($u, (string)$v),
                    };
                    if (!$ok) return false;
                    break;
                case 'annee':
                    if (!str_starts_with(self::dateLigne($type, $row), (string)$v)) return false;
                    break;
                case 'mois':
                    if (!str_starts_with(self::dateLigne($type, $row), (string)$v)) return false;
                    break;
                case 'semaine':
                    $d = self::dateLigne($type, $row);
                    if ($d === '' || date('o-\WW', strtotime($d)) !== (string)$v) return false;
                    break;
                case 'jour':
                    if (self::dateLigne($type, $row) !== (string)$v) return false;
                    break;
                case 'type_element':
                    if (AssistantLangue::norm((string)($row['TypeElement'] ?? '')) !== (string)$v) return false;
                    break;
                case 'famille':
                    $fam = ' ' . AssistantLangue::preparer(($row['Famille'] ?? '') . ' ' . ($row['SousFamille'] ?? '') . ' ' . ($row['Categorie'] ?? '')) . ' ';
                    if (!self::debutDeMot($fam, AssistantLangue::preparer((string)$v))) return false;
                    break;
            }
        }
        return true;
    }

    /** La ligne contient-elle chaque groupe de mots (l'une des variantes au moins) ? */
    private static function contientGroupes(array $row, array $groups, array $nums = []): bool {
        if (!$groups && !$nums) return true;
        $hay = ' ' . self::norm(implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $row))) . ' ';
        foreach ($groups as $g) {
            $ok = false;
            foreach ($g as $k) if (self::debutDeMot($hay, self::norm((string)$k))) { $ok = true; break; }
            if (!$ok) return false;
        }
        return true;
    }

    /**
     * Interventions : bâtiment et étage déduits des équipements / biens liés
     * (la table n'a pas ces colonnes). « interventions au bâtiment A » devient
     * possible sans rien demander au modèle.
     */
    private ?array $liensCache = null;
    private function enrichirInterventions(array $rows): array {
        if ($this->liensCache === null) {
            $eq = []; $bi = [];
            try { foreach ($this->db->fetchAll("SELECT Id, Numero, InfoProduit, Batiment, Etage FROM Equipements WHERE DateSuppression IS NULL") as $r) $eq[(int)$r['Id']] = $r; } catch (\Throwable $_) {}
            try { foreach ($this->db->fetchAll("SELECT Id, Numero, InfoProduit, Batiment, Etage FROM Biens WHERE DateSuppression IS NULL") as $r) $bi[(int)$r['Id']] = $r; } catch (\Throwable $_) {}
            $this->liensCache = [$eq, $bi];
        }
        [$eq, $bi] = $this->liensCache;
        return $this->lierInterventions($rows, $eq, $bi);
    }

    /**
     * Documents : bâtiment, étage et libellé de l'élément auquel chacun est
     * rattaché (« documents du bâtiment A » = ceux de ses biens et équipements).
     */
    private function enrichirDocuments(array $rows): array {
        if ($this->liensCache === null) $this->enrichirInterventions([]);
        [$eq, $bi] = $this->liensCache;
        $autres = ['contrat' => [], 'intervention' => [], 'demande' => []];
        try { foreach ($this->db->fetchAll("SELECT Id, Numero, Societe FROM Contrats") as $r) $autres['contrat'][(int)$r['Id']] = trim(($r['Numero'] ?? '') . ' ' . ($r['Societe'] ?? '')); } catch (\Throwable $_) {}
        try { foreach ($this->db->fetchAll("SELECT Id, Titre, Batiment FROM DemandesIntervention") as $r) $autres['demande'][(int)$r['Id']] = $r; } catch (\Throwable $_) {}
        $interv = [];
        foreach ($rows as $r) if (AssistantLangue::norm((string)($r['EntiteType'] ?? '')) === 'intervention') $interv[(int)$r['EntiteId']] = true;
        if ($interv) {
            try {
                $ivs = $this->db->fetchAll("SELECT Id, Numero, EquipementsIds, BienId FROM Interventions");
                foreach ($this->lierInterventions($ivs, $eq, $bi) as $iv) $autres['intervention'][(int)$iv['Id']] = $iv;
            } catch (\Throwable $_) {}
        }
        foreach ($rows as &$r) {
            $t = AssistantLangue::norm((string)($r['EntiteType'] ?? '')); $id = (int)($r['EntiteId'] ?? 0);
            $cible = match ($t) { 'equipement', 'equipements' => $eq[$id] ?? null, 'bien', 'biens' => $bi[$id] ?? null, default => null };
            if ($cible) {
                $r['Batiment'] = (string)$cible['Batiment']; $r['Etage'] = (string)$cible['Etage'];
                $r['Element'] = trim(($cible['Numero'] ?? '') . ' ' . ($cible['InfoProduit'] ?? ''));
            } elseif ($t === 'intervention' && isset($autres['intervention'][$id])) {
                $iv = $autres['intervention'][$id];
                $r['Batiment'] = $iv['Batiment']; $r['Etage'] = $iv['Etage']; $r['Element'] = trim((string)($iv['Numero'] ?? ''));
            } elseif ($t === 'demande' && isset($autres['demande'][$id])) {
                $r['Batiment'] = (string)($autres['demande'][$id]['Batiment'] ?? ''); $r['Element'] = 'demande #' . $id;
            } elseif ($t === 'contrat' && isset($autres['contrat'][$id])) {
                $r['Element'] = $autres['contrat'][$id];
            }
        }
        unset($r);
        return $rows;
    }

    private function lierInterventions(array $rows, array $eq, array $bi): array {
        foreach ($rows as &$r) {
            $liens = [];
            foreach (preg_split('/[\s,;]+/', (string)($r['EquipementsIds'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $id) if (isset($eq[(int)$id])) $liens[] = $eq[(int)$id];
            if (!empty($r['BienId']) && isset($bi[(int)$r['BienId']])) $liens[] = $bi[(int)$r['BienId']];
            $r['Batiment'] = implode('|', array_values(array_unique(array_filter(array_map(fn($l) => (string)$l['Batiment'], $liens)))));
            $r['Etage'] = implode('|', array_values(array_unique(array_filter(array_map(fn($l) => (string)$l['Etage'], $liens)))));
            $r['Elements'] = implode(', ', array_slice(array_map(fn($l) => trim(($l['Numero'] ?? '') . ' ' . ($l['InfoProduit'] ?? '')), $liens), 0, 6));
        }
        unset($r);
        return $rows;
    }

    /**
     * Lignes d'un type, filtrées et (facultatif) restreintes aux mots-clés, avec
     * le total EXACT (pas de plafond de recherche). Sert au comptage, aux listes
     * et aux sommes.
     *
     * @param array $options  tri ('recent'|'avenir'|null), limite (int), brut (bool : lignes non compactées)
     * @return array{total:int, lignes:array, ignores:array}
     */
    public function lignes(string $type, array $filtres = [], array $mots = [], array $options = []): array {
        $type = self::normType($type);
        if (!isset(self::TABLES[$type])) return ['total' => 0, 'lignes' => [], 'ignores' => []];
        [$from, $select, $where, $order, $dates] = self::TABLES[$type];
        [$fOk, $ignores] = self::filtresPour($type, $filtres);
        try {
            $rows = $this->db->fetchAll("SELECT $select FROM $from" . ($where ? " WHERE $where" : '') . " ORDER BY $order LIMIT 20000");
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] lignes: ' . $e->getMessage());
            return ['total' => 0, 'lignes' => [], 'ignores' => $ignores, 'erreur' => true];
        }
        $rows = array_map([self::class, 'remap'], $rows);
        // « les demandes de Paul Durand » : l'auteur filtre sans que son identifiant sorte dans les lignes.
        if ($type === 'demandes' && !empty($options['auteur_id'])) {
            try {
                $ids = array_flip(array_map('intval', array_column($this->db->fetchAll(
                    "SELECT Id FROM DemandesIntervention WHERE UtilisateurId = :u", ['u' => (int)$options['auteur_id']]), 'Id')));
            } catch (\Throwable $_) { $ids = []; }
            $rows = array_values(array_filter($rows, fn($r) => isset($ids[(int)$r['Id']])));
        }
        if ($type === 'interventions') $rows = $this->enrichirInterventions($rows);
        if ($type === 'documents') $rows = $this->enrichirDocuments($rows);
        $t = $mots ? $this->terms(implode(' ', $mots)) : ['groups' => [], 'nums' => []];
        $rows = array_values(array_filter($rows, fn($r) => $this->correspond($type, $r, $fOk) && self::contientGroupes($r, $t['groups'])));

        $tri = $options['tri'] ?? null;
        if ($tri && $dates) {
            $auj = date('Y-m-d');
            if ($tri === 'avenir') {
                $rows = array_values(array_filter($rows, fn($r) => self::dateLigne($type, $r) >= $auj));
                usort($rows, fn($a, $b) => [self::dateLigne($type, $a), (int)$a['Id']] <=> [self::dateLigne($type, $b), (int)$b['Id']]);
            } else {
                usort($rows, fn($a, $b) => [self::dateLigne($type, $b), (int)$b['Id']] <=> [self::dateLigne($type, $a), (int)$a['Id']]);
            }
        }
        $total = count($rows);
        if (empty($options['brut'])) {
            $lim = (int)($options['limite'] ?? $this->maxResults);
            $rows = array_slice($rows, 0, max(1, $lim));
            $rows = $this->compact($this->anonymize($rows), 240);
        }
        return ['total' => $total, 'lignes' => $rows, 'ignores' => $ignores, 'corrections' => $t['corrections'] ?? []];
    }

    /**
     * Recherche classée sur un type : lignes triées par pertinence (toutes,
     * pas seulement les premières) et informations de classement (meilleur
     * candidat, correspondance complète, ex æquo).
     */
    public function rechercheClassee(string $type, string $query, array $filtres = []): array {
        $type = self::normType($type);
        $vide = ['type' => $type, 'total' => 0, 'lignes' => [], 'info' => ['score' => 0, 'complet' => false, 'ex_aequo' => 0], 'corrections' => [], 'ignores' => []];
        if (!isset(self::SEARCH_MAP[$type])) return $vide;
        $t = $this->terms($query);
        [$fOk, $ignores] = self::filtresPour($type, $filtres);
        $vide['ignores'] = $ignores; $vide['corrections'] = $t['corrections'];
        if (!$t['sql']) return $vide;
        $key = self::SEARCH_MAP[$type];
        $all = $this->db->searchForAssistant($t['sql'], true, [$key], 200);
        $rows = array_map([self::class, 'remap'], $all[$key] ?? []);
        if ($type === 'interventions') $rows = $this->enrichirInterventions($rows);
        if ($type === 'documents') $rows = $this->enrichirDocuments($rows);
        $rows = array_values(array_filter($rows, fn($r) => $this->correspond($type, $r, $fOk)));
        $rows = $this->rank($rows, $t, $info);
        // Le LIKE SQL ratisse large (variantes, synonymes) : on ne garde que les
        // lignes où un mot ou le numéro demandé se retrouve vraiment.
        $garde = [];
        foreach ($rows as $i => $r) if (($info['scores'][$i] ?? 0) > 0) $garde[] = $r;
        $info['scores'] = array_slice($info['scores'], 0, count($garde));
        $info['complets'] = array_slice($info['complets'] ?? [], 0, count($garde));
        return ['type' => $type, 'total' => count($garde), 'minimum' => count($all[$key] ?? []) >= 200,
                'lignes' => $this->compact($this->anonymize($garde), 240), 'info' => $info,
                'corrections' => $t['corrections'], 'ignores' => $ignores, 'termes' => $t];
    }

    /**
     * Recherche sur plusieurs types d'un coup (et dans les modules installés),
     * blocs triés par pertinence puis par ordre fixe des types — jamais par
     * hasard d'exécution.
     *
     * @param array|null $types  types à interroger (null = tous)
     */
    public function rechercheGlobale(string $query, ?array $types = null, array $filtres = [], bool $avecModules = true): array {
        $t = $this->terms($query);
        $types ??= array_keys(self::SEARCH_MAP);
        $blocs = [];
        if ($t['sql']) {
            $keys = array_values(array_intersect_key(self::SEARCH_MAP, array_flip($types)));
            $all = $this->db->searchForAssistant($t['sql'], true, $keys, 200);
            foreach ($types as $ordre => $type) {
                $key = self::SEARCH_MAP[$type] ?? null;
                if (!$key || empty($all[$key])) continue;
                $rows = array_map([self::class, 'remap'], $all[$key]);
                if ($type === 'interventions') $rows = $this->enrichirInterventions($rows);
                if ($type === 'documents') $rows = $this->enrichirDocuments($rows);
                [$fOk] = self::filtresPour($type, $filtres);
                $rows = array_values(array_filter($rows, fn($r) => $this->correspond($type, $r, $fOk)));
                if (!$rows) continue;
                $rows = $this->rank($rows, $t, $info);
                $garde = [];
                foreach ($rows as $i => $r) if (($info['scores'][$i] ?? 0) > 0) $garde[] = $r;
                if (!$garde) continue;
                $info['scores'] = array_slice($info['scores'], 0, count($garde));
                $info['complets'] = array_slice($info['complets'] ?? [], 0, count($garde));
                $blocs[] = ['type' => $type, 'ordre' => $ordre, 'total' => count($garde), 'info' => $info,
                            'lignes' => $this->compact($this->anonymize(array_slice($garde, 0, 50)), 240)];
            }
        }
        // Modules complémentaires installés (mêmes règles que searchAll).
        if ($avecModules && $this->modules && class_exists('ExtMoteurDeclaratif')) {
            $origMod = array_values(array_filter($t['orig'], fn($w) => mb_strlen($w) >= 3));
            foreach (array_slice($this->modules, 0, 6, true) as $mid => $m) {
                foreach ($m['jeux'] as $jeu => $j) {
                    if (!$origMod) break 2;
                    try { [$rows] = $this->moduleRows($mid, $jeu, $origMod); } catch (\Throwable $_) { continue; }
                    if (!$rows) continue;
                    $rows = $this->rank($rows, $t, $infoM);
                    $blocs[] = ['type' => 'module', 'module' => $mid, 'jeu' => $jeu, 'libelle' => $j['libelle'] ?? $jeu,
                                'ordre' => 100, 'total' => count($rows), 'info' => $infoM,
                                'lignes' => array_map(fn($r) => $this->maskModuleRow($r, $j['formats'] ?? []), array_slice($rows, 0, 20))];
                }
            }
        }
        usort($blocs, fn($a, $b) => [$b['info']['score'], $a['ordre']] <=> [$a['info']['score'], $b['ordre']]);
        return ['blocs' => $blocs, 'corrections' => $t['corrections'], 'termes' => $t];
    }

    /**
     * Alertes complètes pour le moteur : TOUTES les lignes (il filtre et compte
     * lui-même), anonymisées. $args['jours'] null = fenêtre propre à chaque
     * contrat (AlerteJoursAvant, comme le tableau de bord) ; un nombre = horizon
     * demandé explicitement (« contrats qui expirent dans 60 jours »).
     */
    public function alertesCompletes(array $args): array {
        $cat = strtolower((string)($args['categorie'] ?? 'toutes')) ?: 'toutes';
        $jours = isset($args['jours']) && $args['jours'] !== null ? max(1, min(3650, (int)$args['jours'])) : null;
        $out = [];
        $auj = date('Y-m-d');
        if ($cat === 'toutes' || $cat === 'contrats') {
            try {
                if ($jours === null) {
                    $rows = $this->db->getContratsEnAlerte();
                } else {
                    $limite = date('Y-m-d', strtotime("+$jours days"));
                    $rows = array_values(array_filter($this->db->fetchAll("SELECT * FROM Contrats WHERE Statut = 'Actif' AND DateFin IS NOT NULL AND DateFin != '' ORDER BY DateFin"),
                        fn($r) => substr((string)$r['DateFin'], 0, 10) <= $limite));
                }
                usort($rows, fn($a, $b) => [substr((string)$a['DateFin'], 0, 10), (int)$a['Id']] <=> [substr((string)$b['DateFin'], 0, 10), (int)$b['Id']]);
                $out['contrats_expirent'] = ['count' => count($rows), 'items_complets' => array_map(fn($r) => [
                    'id' => (int)$r['Id'], 'numero' => $r['Numero'] ?? '', 'societe' => $r['Societe'] ?? '', 'type' => $r['Type'] ?? '',
                    'date_fin' => substr((string)($r['DateFin'] ?? ''), 0, 10), 'montant_annuel' => $r['MontantAnnuel'] ?? null,
                    'description' => $r['Description'] ?? '',
                ], $rows)];
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'stock') {
            try {
                $rows = $this->db->getStockEnAlerte();
                usort($rows, fn($a, $b) => [(string)$a['Designation'], (int)$a['Id']] <=> [(string)$b['Designation'], (int)$b['Id']]);
                $out['stock_en_alerte'] = ['count' => count($rows), 'items_complets' => array_map(fn($r) => [
                    'id' => (int)$r['Id'], 'designation' => $r['Designation'] ?? '', 'reference' => $r['Reference'] ?? '',
                    'categorie' => $r['Categorie'] ?? '', 'emplacement' => $r['Emplacement'] ?? '',
                    'quantite' => $r['Quantite'] ?? 0, 'seuil' => $r['SeuilAlerte'] ?? 0,
                ], $rows)];
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'interventions') {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT Id, Numero, Type, Statut, Priorite, DateIntervention, Description, SocieteManuelle
                     FROM Interventions
                     WHERE Statut IN ('Planifiée','En cours')
                       AND DateIntervention IS NOT NULL AND DateIntervention != ''
                       AND DateIntervention < :today
                     ORDER BY DateIntervention, Id LIMIT 500",
                    ['today' => $auj]
                );
                $out['interventions_en_retard'] = ['count' => count($rows), 'items_complets' => $this->anonymize($rows)];
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'demandes') {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT Id, Titre, Urgence, Statut, DateCreation, Batiment, Bureau, Categorie
                     FROM DemandesIntervention
                     WHERE Statut IN ('Nouveau','Demandeur','Relancé')
                     ORDER BY DateCreation, Id LIMIT 500"
                );
                $out['demandes_non_traitees'] = ['count' => count($rows), 'items_complets' => $this->anonymize($rows)];
            } catch (\Throwable $_) {}
        }
        return $out;
    }

    /** Étages dessinés (plans) d'un bâtiment, du plus bas au plus haut. */
    public function etagesPlan(string $batiment): array {
        try {
            $rows = array_map([self::class, 'remap'], $this->db->fetchAll(
                "SELECT et.Id AS EtageId, et.Nom AS EtageNom, et.Niveau AS Niveau, b.Nom AS BatimentNom
                 FROM PlanEtages et JOIN PlanBatiments b ON et.BatimentId = b.Id ORDER BY b.Nom, et.Niveau, et.Id"));
        } catch (\Throwable $_) { return []; }
        return array_values(array_filter($rows, fn($r) => self::memeBatiment((string)($r['BatimentNom'] ?? ''), $batiment)));
    }

    /** Fiche complète d'un élément, pour le moteur (anonymisée, textes plus longs). */
    public function fiche(string $type, int $id): ?array {
        $r = $this->execute('get_fiche', ['type' => rtrim(self::normType($type), 's'), 'id' => $id]);
        return isset($r['fiche']) ? self::remap($r['fiche']) : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Implémentation des outils ──
    // ═══════════════════════════════════════════════════════════════════════

    private function tool_search(array $args): array {
        $type    = self::normType((string)($args['type'] ?? ''));
        $query   = trim((string)($args['query'] ?? ''));
        $filtres = is_array($args['filtres'] ?? null) ? $args['filtres'] : [];
        if ($query === '') return ['error' => "Paramètre 'query' requis"];

        if ($type === 'tout' || $type === 'all' || $type === '') {
            return $this->searchAll($query);
        }
        if (!isset(self::SEARCH_MAP[$type])) {
            return ['error' => "Type '$type' invalide.", 'types_valides' => array_merge(array_keys(self::SEARCH_MAP), ['tout'])];
        }

        $t = $this->terms($query);
        // Requête réduite au nom du type (« biens », « tous les équipements ») :
        // pas de mot-clé à chercher — on donne le total plutôt que « rien ».
        $precis = array_filter($t['orig'], fn($w) => self::normType($w) !== $type
            && !in_array(self::norm($w), ['liste', 'tous', 'toutes', 'total', 'nombre', 'ensemble'], true));
        if (!$precis && !$t['nums']) {
            $c = in_array($type, ['biens', 'equipements', 'interventions', 'contrats', 'demandes', 'stock', 'plans'], true)
                ? $this->tool_compter(['type' => $type, 'filtres' => $filtres]) : [];
            return ['type' => $type, 'count' => (int)($c['count'] ?? 0), 'results' => [],
                    'note' => 'Aucun mot-clé précis : nombre total du type. Pour détailler, précise un mot-clé ou utilise compter avec des filtres.'];
        }
        if (!$t['sql']) return ['type' => $type, 'count' => 0, 'results' => []];

        $key  = self::SEARCH_MAP[$type];
        $all  = $this->db->searchForAssistant($t['sql'], true, [$key], 100);
        $rows = $all[$key] ?? [];

        if ($filtres && $rows) {
            $rows = array_values(array_filter($rows, function ($row) use ($filtres) {
                foreach ($filtres as $col => $val) {
                    if ($val === '' || $val === null || is_array($val)) continue;
                    $rowVal = $row[ucfirst((string)$col)] ?? $row[$col] ?? null;
                    if ($rowVal === null) return false;
                    if (!str_contains(self::norm((string)$rowVal), self::norm((string)$val))) return false;
                }
                return true;
            }));
        }

        $rows = $this->rank($rows, $t, $info);
        $out = ['type' => $type, 'count' => count($rows)];
        // Le SQL plafonne à 100 lignes par type : au-delà, le compte est un minimum.
        if (count($rows) >= 100) $out['count_minimum'] = true;
        if ($t['corrections']) $out['corrections'] = $t['corrections'];
        if ($info['complet'] && $info['ex_aequo'] === 1) {
            // Un SEUL élément correspond à tous les critères (mots + numéro) :
            // le modèle peut répondre sans redemander de précision.
            $out['meilleur_candidat'] = ['Id' => $info['best']['Id'] ?? null, 'correspondance' => 'totale'];
        } elseif ($info['ex_aequo'] > 1) {
            // Plusieurs éléments équivalents : il faudra proposer, puis demander.
            $out['candidats_equivalents'] = $info['ex_aequo'];
        }
        $out['results'] = array_slice($rows, 0, $this->maxResults);
        return $out;
    }

    /** search(type='tout') : les meilleurs résultats de chaque type, en une passe SQL. */
    private function searchAll(string $query): array {
        $t = $this->terms($query);
        if (!$t['sql']) return ['type' => 'tout', 'total' => 0, 'par_type' => (object)[]];
        $keys = array_values(self::SEARCH_MAP);
        $all  = $this->db->searchForAssistant($t['sql'], true, $keys, 100);
        $meilleur = null;

        $blocs = []; $total = 0;
        $perType = max(2, min(3, intdiv($this->maxResults, 3)));
        foreach (self::SEARCH_MAP as $type => $key) {
            $rows = $all[$key] ?? [];
            if (!$rows) continue;
            $rows = $this->rank($rows, $t, $info);
            $total += count($rows);
            if ($info['complet'] && $info['ex_aequo'] === 1 && (!$meilleur || $info['score'] > $meilleur['score'])) {
                $meilleur = ['type' => $type, 'Id' => $info['best']['Id'] ?? null, 'score' => $info['score']];
            }
            $blocs[$type] = ['score' => $info['score'], 'count' => count($rows), 'results' => array_slice($rows, 0, $perType)];
        }
        // Modules complémentaires installés : leurs données comptent autant que
        // celles du cœur (« où est le passe général ? » vit dans larka.cles).
        if ($this->modules && class_exists('ExtMoteurDeclaratif')) {
            $origMod = array_values(array_filter($t['orig'], fn($w) => mb_strlen($w) >= 3));
            foreach (array_slice($this->modules, 0, 6, true) as $mid => $m) {
                foreach ($m['jeux'] as $jeu => $j) {
                    if (!$origMod) break 2;
                    try { [$rows] = $this->moduleRows($mid, $jeu, $origMod); } catch (\Throwable $_) { continue; }
                    if (!$rows) continue;
                    $this->rank($rows, $t, $infoM);
                    $best = $infoM['score'];
                    $formats = $j['formats'] ?? [];
                    $total += count($rows);
                    $blocs["module:$mid/$jeu"] = [
                        'score' => $best, 'module' => $mid, 'jeu' => $jeu, 'count' => count($rows),
                        'results' => array_map(fn($r) => $this->maskModuleRow($r, $formats), array_slice($rows, 0, $perType)),
                    ];
                }
            }
        }
        // Les types les plus pertinents d'abord ; on n'en garde que 4.
        uasort($blocs, fn($a, $b) => $b['score'] <=> $a['score']);
        $blocs = array_slice($blocs, 0, 4, true);
        foreach ($blocs as &$b) unset($b['score']);
        unset($b);
        $out = ['type' => 'tout', 'total' => $total];
        if ($t['corrections']) $out['corrections'] = $t['corrections'];
        if ($meilleur) { unset($meilleur['score']); $out['meilleur_candidat'] = $meilleur + ['correspondance' => 'totale']; }
        $out['par_type'] = $blocs ?: (object)[];
        return $out;
    }

    /**
     * Classe les lignes par pertinence :
     *   - chaque mot de la question (ou l'une de ses variantes : correction,
     *     singulier, synonyme métier) trouvé en DÉBUT DE MOT : 2 pts
     *   - numéro demandé présent dans le numéro/nom (« extincteur 2 » ↔ « EXT-002 ») : 3 pts
     *   - numéro/référence/nom identique à un mot : 3 pts
     * $info reçoit : score du meilleur, meilleure ligne, « complet » (tous les
     * mots ET le numéro trouvés) et le nombre d'ex æquo complets.
     *
     * Début de mot plutôt que sous-chaîne : « clim » trouve « climatiseur »,
     * mais « ssi » ne trouve plus « possible ».
     * Tri stable : l'ordre SQL départage les égalités — le classement ne dépend
     * que des données, jamais du modèle qui a posé la question.
     */
    private function rank(array $rows, array $t, ?array &$info = null): array {
        $info = ['score' => 0, 'best' => null, 'complet' => false, 'ex_aequo' => 0, 'scores' => []];
        if (!$rows) return [];
        $groups = array_values(array_filter(array_map(fn($g) => array_values(array_unique(array_filter(array_map([self::class, 'norm'], $g), fn($k) => $k !== ''))), $t['groups'])));
        $all = $groups ? array_merge(...$groups) : [];
        $nums = $t['nums'];
        $scored = [];
        foreach ($rows as $i => $row) {
            $hay = ' ' . self::norm(implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $row))) . ' ';
            $score = 0; $trouves = 0;
            foreach ($groups as $g) {
                foreach ($g as $k) {
                    if (self::debutDeMot($hay, $k)) { $score += 2; $trouves++; break; }
                }
            }
            $numOk = !$nums;
            if ($nums) {
                $ident = implode(' ', array_map(fn($c) => (string)($row[$c] ?? ''),
                    ['Numero', 'InfoProduit', 'Nom', 'Designation', 'Reference', 'Intitule', 'Titre', 'NumeroDossier', 'NumeroBoite', 'numero', 'nomination']));
                preg_match_all('/\d+/', $ident, $m);
                $presents = array_map('intval', $m[0]);
                foreach ($nums as $n) if (in_array((int)$n, $presents, true)) { $score += 3; $numOk = true; break; }
            }
            foreach (['Numero', 'Reference', 'NumeroSerie', 'Nom', 'NumeroDossier'] as $c) {
                if (!empty($row[$c]) && in_array(self::norm((string)$row[$c]), $all, true)) $score += 3;
            }
            $complet = $numOk && $groups && $trouves === count($groups);
            $scored[] = [$score, $i, $row, $complet];
        }
        usort($scored, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        $top = $scored[0];
        $info['score'] = $top[0];
        $info['best'] = $top[2];
        $info['scores'] = array_column($scored, 0);
        $info['complets'] = array_column($scored, 3);
        if ($top[3]) {
            $info['complet'] = true;
            foreach ($scored as $sc) if ($sc[3] && $sc[0] === $top[0]) $info['ex_aequo']++;
        }
        return array_column($scored, 2);
    }

    /** $k apparaît-il au début d'un mot de $hay (normalisé, entouré d'espaces) ? */
    private static function debutDeMot(string $hay, string $k): bool {
        if ($k === '') return false;
        $p = 0;
        while (($p = strpos($hay, $k, $p)) !== false) {
            if ($p === 0 || !ctype_alnum($hay[$p - 1])) return true;
            $p++;
        }
        return false;
    }

    private function tool_get_fiche(array $args): array {
        $type = strtolower((string)($args['type'] ?? ''));
        $id   = (int)($args['id'] ?? 0);
        if (!$id) return ['error' => "Paramètre 'id' requis"];

        $row = null;
        switch (rtrim(self::normType($type), 's')) {
            case 'bien':         $row = $this->db->getBienById($id); break;
            case 'equipement':   $row = $this->db->getEquipementById($id); break;
            case 'intervention': $row = $this->db->getInterventionById($id); break;
            case 'contrat':      $row = $this->db->getContratById($id); break;
            case 'demande':
                $row = $this->db->fetchOne("SELECT * FROM DemandesIntervention WHERE Id=:id", ['id' => $id]) ?: null; break;
            case 'stock':
                $row = $this->db->fetchOne("SELECT * FROM Stock WHERE Id=:id", ['id' => $id]) ?: null; break;
            default: return ['error' => "Type inconnu : $type"];
        }
        if (!$row) return ['error' => "Fiche non trouvée"];
        // Une fiche a droit à des textes un peu plus longs qu'une ligne de liste.
        return ['fiche' => $row];
    }

    private function tool_localiser(array $args): array {
        $type = self::normType((string)($args['type'] ?? ''));
        $id   = (int)($args['id'] ?? 0);
        if (!$id) return ['error' => "Paramètre 'id' requis"];

        $type = rtrim($type, 's');
        if ($type === 'bien') $row = $this->db->getBienById($id);
        elseif ($type === 'equipement') $row = $this->db->getEquipementById($id);
        else return ['error' => "Type doit être 'bien' ou 'equipement'"];
        if (!$row) return ['error' => "Élément non trouvé"];

        $loc = [
            'numero'   => $row['Numero'] ?? '',
            'batiment' => $row['Batiment'] ?? '',
            'etage'    => $row['Etage'] ?? '',
            'bureau'   => $row['NumeroBureau'] ?? '',
        ];
        try {
            $linked = $this->db->fetchAll(
                "SELECT el.Id AS ElementId, el.Nom AS ElementNom,
                        et.Id AS EtageId, et.Nom AS EtageNom, b.Nom AS BatimentNom
                 FROM PlanLiens pl
                 JOIN PlanElements el ON pl.ElementId = el.Id
                 LEFT JOIN PlanEtages et ON el.EtageId = et.Id
                 LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id
                 WHERE pl.AssetType = :t AND pl.AssetId = :id LIMIT 5",
                ['t' => $type === 'bien' ? 'Bien' : 'Equipement', 'id' => $id]
            );
            if ($linked) $loc['sur_plan'] = $linked;
        } catch (\Throwable $_) {}
        return $loc;
    }

    private function tool_localiser_groupe(array $args): array {
        $type = rtrim(self::normType((string)($args['type'] ?? '')), 's');
        $ids  = $args['ids'] ?? [];
        if (is_string($ids)) $ids = preg_split('/[\s,;]+/', $ids);
        if (!is_array($ids) || empty($ids)) return ['error' => "Paramètre 'ids' (tableau) requis"];
        if (!in_array($type, ['bien', 'equipement'], true)) return ['error' => "Type doit être 'bien' ou 'equipement'"];

        $assetType = ($type === 'bien') ? 'Bien' : 'Equipement';
        $idsInt = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (empty($idsInt)) return ['error' => "Aucun ID valide"];
        $truncated = count($idsInt) > 50;
        $idsInt = array_slice($idsInt, 0, 50);

        try {
            $params = ['t' => $assetType];
            foreach ($idsInt as $k => $v) $params["id$k"] = $v;
            $ph = implode(',', array_map(fn($k) => ":id$k", array_keys($idsInt)));
            $rows = $this->db->fetchAll(
                "SELECT pl.AssetId, el.Id AS ElementId, et.Id AS EtageId, et.Nom AS EtageNom, b.Nom AS BatimentNom
                 FROM PlanLiens pl
                 JOIN PlanElements el ON pl.ElementId = el.Id
                 LEFT JOIN PlanEtages et ON el.EtageId = et.Id
                 LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id
                 WHERE pl.AssetType = :t AND pl.AssetId IN ($ph)",
                $params
            );

            $table = ($type === 'bien') ? 'Biens' : 'Equipements';
            $idParams = [];
            foreach ($idsInt as $k => $v) $idParams["aid$k"] = $v;
            $idPh = implode(',', array_map(fn($k) => ":$k", array_keys($idParams)));
            $assets = $this->db->fetchAll(
                "SELECT Id, Numero, InfoProduit, Marque, Modele, Batiment, Etage, NumeroBureau
                 FROM $table WHERE Id IN ($idPh) AND DateSuppression IS NULL",
                $idParams
            );
            $assetsById = [];
            foreach ($assets as $a) $assetsById[(int)$a['Id']] = $a;
            $surPlan = [];
            foreach ($rows as $r) $surPlan[(int)$r['AssetId']] = true;

            $details = [];
            foreach ($idsInt as $id) {
                $row = $assetsById[$id] ?? null;
                if (!$row) continue;
                $details[] = [
                    'id'       => $id,
                    'numero'   => $row['Numero'] ?? '',
                    'nom'      => $row['InfoProduit'] ?: trim(($row['Marque'] ?? '') . ' ' . ($row['Modele'] ?? '')),
                    'batiment' => $row['Batiment'] ?? '',
                    'etage'    => $row['Etage'] ?? '',
                    'bureau'   => $row['NumeroBureau'] ?? '',
                    'sur_plan' => isset($surPlan[$id]),
                ];
            }
            // Regroupement par étage : c'est ce qu'il faut pour les liens [FICHE:plans:…].
            $regroupement = [];
            foreach ($rows as $r) {
                $etId = (int)($r['EtageId'] ?? 0);
                if (!$etId) continue;
                $regroupement[$etId] ??= ['EtageId' => $etId, 'EtageNom' => $r['EtageNom'],
                                          'BatimentNom' => $r['BatimentNom'], 'ElementIds' => []];
                $regroupement[$etId]['ElementIds'][] = (int)$r['ElementId'];
            }
            return [
                'count'                  => count($details),
                'count_sur_plan'         => count($surPlan),
                'truncated'              => $truncated,
                'regroupement_par_etage' => array_values($regroupement),
                'details'                => $details,
            ];
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] SQL error: ' . $e->getMessage());
            return ['error' => "Erreur lors de la requête."];
        }
    }

    private function tool_compter(array $args): array {
        $type = self::normType((string)($args['type'] ?? ''));
        $filtres = is_array($args['filtres'] ?? null) ? $args['filtres'] : [];
        $tableMap = [
            'biens'         => ['Biens', "DateSuppression IS NULL"],
            'equipements'   => ['Equipements', "DateSuppression IS NULL"],
            'interventions' => ['Interventions', ''],
            'contrats'      => ['Contrats', ''],
            'demandes'      => ['DemandesIntervention', ''],
            'stock'         => ['Stock', ''],
            'plans'         => ['PlanElements', ''],
        ];
        if (!isset($tableMap[$type])) return ['error' => "Type inconnu", 'types_valides' => array_keys($tableMap)];
        [$table, $extraWhere] = $tableMap[$type];

        // Whitelist stricte des colonnes filtrables : un nom de colonne venu du
        // modèle (ou d'une injection de prompt) n'entre jamais tel quel en SQL.
        $colWhitelist = [
            'Biens'                => ['Famille','Batiment','Etage','Etat','TypeBien','Categorie'],
            'Equipements'          => ['Famille','Batiment','Etage','Etat','TypeEquipement','Marque'],
            // (pas de colonne Batiment sur Interventions : le filtre faisait échouer la requête)
            'Interventions'        => ['Statut','Type','Priorite'],
            'Contrats'             => ['Statut','Societe','Type','Categorie'],
            'DemandesIntervention' => ['Statut','Urgence','Categorie','Batiment'],
            'Stock'                => ['Categorie','Famille','Emplacement'],
            'PlanElements'         => ['TypeElement','Calque','EtageId','Nom'],
        ];
        $allowed = $colWhitelist[$table] ?? [];
        $allowedLower = array_map('strtolower', $allowed);

        $where = []; $params = [];
        if ($extraWhere) $where[] = $extraWhere;
        $appliques = [];

        foreach ($filtres as $col => $val) {
            if ($val === '' || $val === null || is_array($val)) continue;
            $colLower = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$col));
            if (!$colLower) continue;

            // Alias snake_case spontanés du modèle. « type » ne désigne
            // TypeElement QUE pour les plans : sur les interventions c'est bien
            // la colonne Type (l'ancien alias global la rendait infiltrable).
            $aliases = ['type_element' => 'typeelement', 'etage_id' => 'etageid'];
            if ($table === 'PlanElements') $aliases['type'] = 'typeelement';
            if ($table === 'Biens')       $aliases['type'] = 'typebien';
            if ($table === 'Equipements') $aliases['type'] = 'typeequipement';
            $colLower = $aliases[$colLower] ?? $colLower;

            if ($colLower === 'annee' && preg_match('/^\d{4}$/', (string)$val)) {
                $dateCol = ($table === 'Interventions') ? 'DateIntervention'
                         : (in_array($table, ['DemandesIntervention', 'Contrats'], true) ? 'DateCreation' : null);
                if ($dateCol) {
                    $where[] = "SUBSTR(CAST(\"$dateCol\" AS TEXT),1,4) = :annee";
                    $params['annee'] = (string)$val;
                    $appliques['annee'] = (string)$val;
                }
                continue;
            }
            $idx = array_search($colLower, $allowedLower, true);
            if ($idx === false) continue;
            $safeCol = $allowed[$idx];
            $where[] = "LOWER(CAST(\"$safeCol\" AS TEXT)) LIKE LOWER(:v_$safeCol)";
            $params["v_$safeCol"] = '%' . $val . '%';
            $appliques[$safeCol] = (string)$val;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        try {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM $table $whereSql", $params);
            $out = ['type' => $type, 'count' => (int)($row['c'] ?? 0), 'filtres_appliques' => $appliques ?: (object)[]];
            $ignores = array_diff(array_keys($filtres), array_keys($appliques), ['annee']);
            if ($ignores && count($appliques) < count(array_filter($filtres, fn($v) => $v !== '' && $v !== null))) {
                $out['filtres_ignores'] = array_values($ignores);
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] SQL error: ' . $e->getMessage());
            return ['error' => "Erreur lors de la requête."];
        }
    }

    private function tool_alertes(array $args): array {
        $cat   = strtolower((string)($args['categorie'] ?? 'toutes')) ?: 'toutes';
        $jours = max(1, min(365, (int)($args['jours'] ?? 30)));
        $out = [];
        $lim = $this->maxResults;

        if ($cat === 'toutes' || $cat === 'contrats') {
            try {
                $rows = $this->db->getContratsEnAlerte();
                $limite = date('Y-m-d', strtotime("+$jours days"));
                $rows = array_values(array_filter($rows, fn($r) => empty($r['DateFin']) || substr((string)$r['DateFin'], 0, 10) <= $limite));
                $out['contrats_expirent'] = ['count' => count($rows), 'items' => array_slice(array_map(fn($r) => [
                    'id' => $r['Id'], 'societe' => $r['Societe'], 'numero' => $r['Numero'],
                    'date_fin' => $r['DateFin'], 'montant_annuel' => $r['MontantAnnuel'],
                ], $rows), 0, $lim)];
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'stock') {
            try {
                $rows = $this->db->getStockEnAlerte();
                $out['stock_en_alerte'] = ['count' => count($rows), 'items' => array_slice(array_map(fn($r) => [
                    'id' => $r['Id'], 'designation' => $r['Designation'],
                    'quantite' => $r['Quantite'], 'seuil' => $r['SeuilAlerte'],
                ], $rows), 0, $lim)];
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'interventions') {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT Id, Numero, Type, Statut, DateIntervention, Description
                     FROM Interventions
                     WHERE Statut IN ('Planifiée','En cours')
                       AND DateIntervention IS NOT NULL AND DateIntervention != ''
                       AND DateIntervention < :today
                     ORDER BY DateIntervention LIMIT 30",
                    ['today' => date('Y-m-d')]
                );
                $out['interventions_en_retard'] = ['count' => count($rows), 'items' => array_slice($rows, 0, $lim)];
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'demandes') {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT Id, Titre, Urgence, Statut, DateCreation, Batiment
                     FROM DemandesIntervention
                     WHERE Statut IN ('Nouveau','Demandeur','Relancé')
                     ORDER BY DateCreation LIMIT 30"
                );
                $out['demandes_non_traitees'] = ['count' => count($rows), 'items' => array_slice($rows, 0, $lim)];
            } catch (\Throwable $_) {}
        }
        return $out;
    }

    /**
     * Recherche de documents SharePoint / OneDrive via Microsoft Search, avec
     * le jeton DÉLÉGUÉ de l'utilisateur (il ne voit que ce à quoi il a accès).
     */
    private function tool_search_sharepoint(array $args): array {
        if (!$this->msToken) {
            return ['erreur' => "SharePoint indisponible : l'utilisateur n'est pas connecté via Microsoft."];
        }
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') return ['erreur' => 'Requête vide.'];
        if (mb_strlen($query) > 200) $query = mb_substr($query, 0, 200);

        $body = json_encode(['requests' => [[
            'entityTypes' => ['driveItem'],
            'query'       => ['queryString' => $query],
            'from'        => 0,
            'size'        => 8,
        ]]], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://graph.microsoft.com/v1.0/search/query');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->msToken, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) return ['erreur' => 'Microsoft Graph injoignable.'];
        if ($http === 401)  return ['erreur' => 'Session Microsoft expirée : se reconnecter via Microsoft.'];
        if ($http >= 400)   return ['erreur' => "Erreur Microsoft Graph (HTTP $http)."];

        $data  = json_decode($raw, true);
        $items = [];
        foreach (($data['value'] ?? []) as $resp) {
            foreach (($resp['hitsContainers'] ?? []) as $hc) {
                foreach (($hc['hits'] ?? []) as $hit) {
                    $r = $hit['resource'] ?? [];
                    if (empty($r['id']) || isset($r['folder'])) continue;
                    $parentPath = '';
                    if (!empty($r['parentReference']['path'])) {
                        $p   = $r['parentReference']['path'];
                        $pos = strpos($p, 'root:');
                        $parentPath = $pos !== false ? rawurldecode(ltrim(substr($p, $pos + 5), '/')) : '';
                    }
                    $items[] = [
                        'nom'       => mb_substr($r['name'] ?? '', 0, 120),
                        'chemin'    => mb_substr($parentPath, 0, 150),
                        'taille_ko' => (int)round(($r['size'] ?? 0) / 1024),
                        'modifie'   => substr($r['lastModifiedDateTime'] ?? '', 0, 10),
                        'url'       => $r['webUrl'] ?? '',
                    ];
                    if (count($items) >= 8) break 3;
                }
            }
        }
        if (!$items) return ['resultats' => [], 'note' => "Aucun document SharePoint trouvé pour « $query »."];
        return ['resultats' => $items];
    }

    /**
     * Annuaire interne. On n'expose que des champs PRO (jamais mot de passe,
     * jetons, identifiants OAuth, téléphone ou email personnels).
     */
    private function tool_qui_est(array $args): array {
        $id    = (int)($args['id'] ?? 0);
        $query = trim((string)($args['query'] ?? ''));
        $role  = trim((string)($args['role'] ?? ''));
        $roles = ['Demandeur', 'Gestionnaire', 'Technicien', 'Admin', 'Visionneur'];
        $select = "Id, Nom, Prenom, Login, Role, Service, Poste, TelPro, OfficeLocation, CompanyName, ManagerName, Actif";

        if ($id > 0) {
            try {
                $row = $this->db->fetchOne("SELECT $select FROM Utilisateurs WHERE Id = :id LIMIT 1", ['id' => $id]);
                if (!$row) return ['result' => null, 'message' => "Aucun utilisateur trouvé avec Id=$id"];
                return ['result' => $this->formatUserRow($row)];
            } catch (\Throwable $e) {
                error_log('[Larka][assistant] annuaire error: ' . $e->getMessage());
                return ['error' => 'Erreur lors de la lecture de l\'annuaire.'];
            }
        }

        $where = ["Actif = 1"]; $params = [];
        if ($role && in_array($role, $roles, true)) {
            $where[] = "Role = :role";
            $params['role'] = $role;
        }
        try {
            // L'annuaire est petit : on filtre en PHP, sans accents ni casse
            // (« plombier » trouve « Plombière », « benali » trouve « Benali »).
            $rows = $this->db->fetchAll("SELECT $select FROM Utilisateurs WHERE " . implode(' AND ', $where)
                                        . " ORDER BY Nom, Prenom, Id LIMIT 2000", $params);
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] annuaire error: ' . $e->getMessage());
            return ['error' => 'Erreur lors de la lecture de l\'annuaire.'];
        }
        if ($query !== '') {
            [$kw] = $this->keywords($query);
            $kw = $kw ?: [$query];
            $rows = array_values(array_filter($rows, function ($r) use ($kw) {
                $hay = ' ' . self::norm(implode(' ', [$r['Nom'] ?? '', $r['Prenom'] ?? '', $r['Login'] ?? '', $r['Service'] ?? '',
                                                       $r['Poste'] ?? '', $r['Role'] ?? '', $r['OfficeLocation'] ?? ''])) . ' ';
                foreach ($kw as $w) {
                    $n = self::norm((string)$w);
                    $ok = self::debutDeMot($hay, $n);
                    foreach (self::singuliers($n) as $sg) $ok = $ok || self::debutDeMot($hay, $sg);
                    if (!$ok) return false;
                }
                return true;
            }));
        }
        $rows = array_slice($rows, 0, 30);
        if (!$rows) return ['count' => 0, 'results' => [], 'message' => 'Aucun utilisateur trouvé.'];
        return [
            'count'   => count($rows),
            'results' => array_map(fn($r) => $this->formatUserRow($r), array_slice($rows, 0, $this->maxResults)),
        ];
    }

    private function formatUserRow(array $row): array {
        return [
            'Id'          => (int)($row['Id'] ?? 0),
            'Nom'         => trim(($row['Prenom'] ?? '') . ' ' . ($row['Nom'] ?? '')),
            'Login'       => $row['Login'] ?? '',
            'Role'        => $row['Role'] ?? '',
            'Service'     => $row['Service'] ?? '',
            'Poste'       => $row['Poste'] ?? '',
            'TelPro'      => $row['TelPro'] ?? '',
            'Bureau'      => $row['OfficeLocation'] ?? '',
            'Societe'     => $row['CompanyName'] ?? '',
            'Responsable' => $row['ManagerName'] ?? '',
        ];
    }

    private function tool_contexte(array $args): array {
        $out = [];
        $q = [
            'batiments'            => "SELECT DISTINCT Batiment AS v FROM Biens WHERE Batiment IS NOT NULL AND Batiment != '' AND DateSuppression IS NULL ORDER BY Batiment LIMIT 40",
            'familles_biens'       => "SELECT DISTINCT Famille AS v FROM Biens WHERE Famille IS NOT NULL AND Famille != '' AND DateSuppression IS NULL ORDER BY Famille LIMIT 30",
            'familles_equipements' => "SELECT DISTINCT Famille AS v FROM Equipements WHERE Famille IS NOT NULL AND Famille != '' AND DateSuppression IS NULL ORDER BY Famille LIMIT 30",
            'categories_demandes'  => "SELECT DISTINCT Categorie AS v FROM DemandesIntervention WHERE Categorie IS NOT NULL AND Categorie != '' ORDER BY Categorie LIMIT 30",
        ];
        foreach ($q as $k => $sql) {
            try { $out[$k] = array_column($this->db->fetchAll($sql), 'v'); } catch (\Throwable $_) {}
        }
        try {
            $r = $this->db->fetchAll("SELECT Prenom, Nom, Poste, Service FROM Utilisateurs WHERE Actif = 1 AND Role IN ('Gestionnaire','Admin') ORDER BY Nom LIMIT 20");
            $out['gestionnaires'] = array_map(fn($u) => trim(($u['Prenom'] ?? '') . ' ' . ($u['Nom'] ?? '')) . ' (' . ($u['Poste'] ?: $u['Service'] ?: '') . ')', $r);
        } catch (\Throwable $_) {}
        if ($this->modules) {
            $out['modules_installes'] = array_map(fn($m) => $m['nom'], $this->modules);
        }
        return $out;
    }

    /**
     * Données d'un module complémentaire (extension déclarative).
     *
     * La lecture passe par ExtMoteurDeclaratif::lister() : mêmes contrôles
     * que l'écran du module (rôles page par page, couche de sécurité,
     * journal). Un module non installé, inactif ou non accordé à ce rôle
     * n'apparaît simplement pas dans $this->modules.
     */
    private function tool_module(array $args): array {
        $id = trim((string)($args['module'] ?? ''));

        // ── Catalogue ────────────────────────────────────────────────────
        if ($id === '') {
            $out = ['installes' => []];
            foreach ($this->modules as $mid => $m) {
                $out['installes'][] = [
                    'module' => $mid, 'nom' => $m['nom'],
                    'jeux'   => array_map(fn($j) => $j['libelle'], $m['jeux']),
                    'pages'  => $m['pages'],
                ];
            }
            if (is_callable($this->modulesDisponibles)) {
                try {
                    $dispo = ($this->modulesDisponibles)();
                    if ($dispo) $out['non_installes'] = $dispo;
                } catch (\Throwable $_) {}
            }
            if (!$out['installes']) $out['note'] = 'Aucun module complémentaire installé et accessible.';
            return $out;
        }

        // Tolérance : le modèle donne parfois le NOM au lieu de l'identifiant.
        if (!isset($this->modules[$id])) {
            foreach ($this->modules as $mid => $m) {
                if (self::norm($m['nom']) === self::norm($id) || str_ends_with($mid, '.' . strtolower($id))) { $id = $mid; break; }
            }
        }
        if (!isset($this->modules[$id])) {
            return ['error' => "Module « $id » non installé, inactif ou non accessible pour ce rôle.",
                    'installes' => array_keys($this->modules)];
        }
        if (!class_exists('ExtMoteurDeclaratif')) return ['error' => 'Couche extensions indisponible.'];

        $m   = $this->modules[$id];
        $jeu = trim((string)($args['jeu'] ?? ''));
        if ($jeu === '' || !isset($m['jeux'][$jeu])) {
            // Nom de jeu approximatif (« clés » pour « cles ») → on rapproche.
            $trouve = null;
            foreach ($m['jeux'] as $nom => $j) {
                if ($jeu !== '' && (self::norm($j['libelle']) === self::norm($jeu) || self::norm($nom) === self::norm($jeu))) { $trouve = $nom; break; }
            }
            $jeu = $trouve ?? array_key_first($m['jeux']);
        }
        if ($jeu === null) return ['error' => 'Aucun jeu de données lisible dans ce module pour ce rôle.'];

        $recherche = trim((string)($args['recherche'] ?? ''));
        [$orig] = $recherche !== '' ? $this->keywords($recherche) : [[]];
        try {
            [$rows, $total] = $this->moduleRows($id, $jeu, $orig);
        } catch (\Throwable $e) {
            return ['error' => 'Lecture refusée ou impossible : ' . mb_substr($e->getMessage(), 0, 120)];
        }

        $champs  = $m['jeux'][$jeu]['champs'] ?? [];
        $formats = $m['jeux'][$jeu]['formats'] ?? [];
        $rows = array_map(fn($row) => $this->maskModuleRow($row, $formats), array_slice($rows, 0, $this->maxResults));

        return [
            'module'  => $id,
            'jeu'     => $jeu,
            'libelle' => $m['jeux'][$jeu]['libelle'],
            'champs'  => $champs,
            'total'   => $total,
            'results' => $rows,
        ];
    }

    /**
     * Lignes d'un jeu de module, via le moteur déclaratif (droits compris).
     * Sans mot-clé : les derniers enregistrements. Avec : préfiltre moteur sur
     * le mot le plus long, puis classement par pertinence ici.
     * @return array{0: array, 1: int}
     */
    private function moduleRows(string $id, string $jeu, array $orig): array {
        $moteur = new ExtMoteurDeclaratif($id, $this->modules[$id]['declaration'], $this->db, $this->user);
        if (!$orig) {
            $r = $moteur->lister($jeu, ['page' => 1]);
            return [$r['lignes'] ?? [], (int)($r['total'] ?? count($r['lignes'] ?? []))];
        }
        usort($orig, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $rows = [];
        foreach (array_slice($orig, 0, 2) as $mot) {
            $r = $moteur->lister($jeu, ['page' => 1, 'recherche' => $mot]);
            $rows = $r['lignes'] ?? [];
            if ($rows) break;
        }
        $t = ['groups' => array_map(fn($w) => [$w], $orig), 'sql' => $orig, 'nums' => []];
        $rows = array_values(array_filter($this->rank($rows, $t), function ($row) use ($orig) {
            $hay = self::norm(implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $row)));
            foreach ($orig as $k) if (str_contains($hay, self::norm($k))) return true;
            return false;
        }));
        return [$rows, count($rows)];
    }

    /** RGPD sur une ligne de module : traçabilité retirée, personnes/coordonnées masquées. */
    private function maskModuleRow(array $row, array $formats): array {
        $out = [];
        foreach ($row as $k => $v) {
            $k = (string)$k;
            if (in_array($k, ['cree_par', 'modifie_par', 'modifie_le'], true)) continue;
            if (is_string($v) && $v !== '') {
                $fmt = $formats[$k] ?? '';
                $segs = preg_split('/[_\-]+/', strtolower($k));
                if ($fmt === 'email' || array_intersect($segs, ['email', 'mail', 'courriel'])) $v = '[email]';
                elseif ($fmt === 'telephone' || array_intersect($segs, ['telephone', 'tel', 'mobile', 'portable'])) $v = '[téléphone]';
                elseif (array_intersect($segs, self::PERSON_SEGMENTS) && !str_ends_with($k, '_libelle')) $v = '[personne]';
            }
            $out[$k] = $v;
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Helpers ──
    // ═══════════════════════════════════════════════════════════════════════

    /** Minuscules sans accents — comparaisons tolérantes (une seule définition : AssistantLangue). */
    public static function norm(string $s): string {
        return AssistantLangue::norm($s);
    }

    /**
     * Analyse complète d'une requête pour la recherche :
     *   orig        mots d'origine
     *   groups      par mot : [mot, correction éventuelle] — l'un OU l'autre suffit
     *   sql         mots envoyés au LIKE (mots + corrections + synonymes)
     *   nums        numéros isolés (« extincteur 2 », « 2ème ») — servent au
     *               classement, pas au LIKE (« %2% » trouverait tout)
     *   corrections mot mal orthographié → mot connu de la base
     *
     * La correction orthographique compare les mots inconnus au vocabulaire
     * RÉEL du site (familles, désignations, bâtiments, éléments de plan…) :
     * « exctincteur » → « extincteur ». Le mot d'origine est conservé : une
     * correction ne peut qu'ajouter des résultats, jamais en retirer.
     */
    public function terms(string $q): array {
        [$orig, , $nums] = $this->keywords($q);
        $groups = []; $corr = []; $sql = [];
        $vocab = $this->vocab();
        foreach ($orig as $w) {
            $n = self::norm($w);
            // Variantes du mot : lui-même, ses singuliers, ses synonymes métier.
            $g = [$n];
            foreach (self::singuliers($n) as $s) $g[] = $s;
            foreach ($g as $x) foreach (AssistantLangue::SYNONYMES[$x] ?? [] as $syn) $g[] = $syn;
            // Faute de frappe : rapprochée du vocabulaire RÉEL du site, seulement
            // si ni le mot ni ses singuliers n'y figurent déjà.
            $connu = false;
            foreach ($g as $x) if (isset($vocab[$x])) { $connu = true; break; }
            if (!$connu && mb_strlen($n) >= 4 && !ctype_digit($n) && !preg_match('/\d/', $n)) {
                $best = AssistantLangue::plusProche($n, $vocab);
                if ($best !== null && $best !== $n) {
                    $g[] = $best; $corr[$w] = $best;
                    foreach (AssistantLangue::SYNONYMES[$best] ?? [] as $syn) $g[] = $syn;
                }
            }
            $g = array_values(array_unique($g));
            $groups[] = $g;
            // Mots envoyés au LIKE : chaque variante ET ses graphies réelles en
            // base (« ecran » → « Écran »). Sans cela, sous SQLite (LOWER() ne
            // connaît que l'ASCII), une question tapée sans accent ne trouvait
            // rien — « écran » et « ecran » ne donnaient pas le même résultat.
            if ($w !== $n) $sql[] = $w;
            foreach ($g as $x) {
                $sql[] = $x;
                foreach ($vocab[$x] ?? [] as $o) if ($o !== true) $sql[] = $o;
            }
        }
        // Question réduite à des numéros (« intervention 5 ») : on cherche les numéros eux-mêmes.
        if (!$sql && $nums) foreach ($nums as $x) $sql[] = $x;
        return ['orig' => $orig, 'groups' => $groups, 'sql' => array_slice(array_values(array_unique($sql)), 0, 24),
                'nums' => $nums, 'corrections' => $corr];
    }

    /** Singuliers plausibles d'un mot normalisé (« bureaux » → « bureau », « locaux » → « local »). */
    public static function singuliers(string $n): array {
        $out = [];
        if (strlen($n) > 4 && str_ends_with($n, 's') && !str_ends_with($n, 'ss')) $out[] = substr($n, 0, -1);
        if (strlen($n) > 4 && str_ends_with($n, 'eaux')) $out[] = substr($n, 0, -1);
        if (strlen($n) > 4 && str_ends_with($n, 'aux') && !str_ends_with($n, 'eaux')) $out[] = substr($n, 0, -3) . 'al';
        if (strlen($n) > 4 && str_ends_with($n, 'x') && !str_ends_with($n, 'aux')) $out[] = substr($n, 0, -1);
        return $out;
    }

    /**
     * Vocabulaire du site : mot normalisé → graphies réelles en base (casse et
     * accents d'origine, 3 au plus). Construit une fois par requête.
     */
    private ?array $vocabCache = null;
    private function vocab(): array {
        if ($this->vocabCache !== null) return $this->vocabCache;
        $sources = [
            "SELECT DISTINCT Famille AS v FROM Equipements", "SELECT DISTINCT SousFamille AS v FROM Equipements",
            "SELECT DISTINCT InfoProduit AS v FROM Equipements", "SELECT DISTINCT Batiment AS v FROM Equipements",
            "SELECT DISTINCT Marque AS v FROM Equipements", "SELECT DISTINCT Modele AS v FROM Equipements",
            "SELECT DISTINCT Famille AS v FROM Biens", "SELECT DISTINCT SousFamille AS v FROM Biens",
            "SELECT DISTINCT InfoProduit AS v FROM Biens", "SELECT DISTINCT Batiment AS v FROM Biens",
            "SELECT DISTINCT Designation AS v FROM Stock", "SELECT DISTINCT Categorie AS v FROM Stock",
            "SELECT DISTINCT Emplacement AS v FROM Stock",
            "SELECT DISTINCT Societe AS v FROM Contrats", "SELECT DISTINCT Type AS v FROM Contrats",
            "SELECT DISTINCT Description AS v FROM Contrats",
            "SELECT DISTINCT Type AS v FROM Interventions", "SELECT DISTINCT Description AS v FROM Interventions",
            "SELECT DISTINCT SocieteManuelle AS v FROM Interventions",
            "SELECT DISTINCT Categorie AS v FROM DemandesIntervention", "SELECT DISTINCT Titre AS v FROM DemandesIntervention",
            "SELECT DISTINCT NomFichier AS v FROM Documents",
            "SELECT DISTINCT Nom AS v FROM PlanElements", "SELECT DISTINCT Calque AS v FROM PlanElements",
            "SELECT DISTINCT Nom AS v FROM PlanBatiments", "SELECT DISTINCT Nom AS v FROM PlanEtages",
            "SELECT DISTINCT Valeur AS v FROM Listes",
        ];
        $v = [];
        foreach ($sources as $sql) {
            try { $rows = $this->db->fetchAll($sql . " LIMIT 3000"); } catch (\Throwable $_) { continue; }
            foreach ($rows as $r) {
                foreach (preg_split('/[^\p{L}\p{N}]+/u', (string)($r['v'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $o) {
                    $w = self::norm($o);
                    if (strlen($w) < 3 || ctype_digit($w)) continue;
                    foreach (array_merge([$w], self::singuliers($w)) as $k) {
                        $v[$k] ??= [];
                        if (count($v[$k]) < 3 && !in_array($o, $v[$k], true)) $v[$k][] = $o;
                    }
                }
            }
            if (count($v) > 20000) break;
        }
        // Vocabulaire métier courant, même absent de la base.
        foreach (['extincteur','sprinkler','alarme','incendie','desenfumage','ascenseur','chaudiere','climatisation',
                  'climatiseur','ventilation','luminaire','eclairage','ampoule','prise','porte','poignee','fenetre',
                  'serrure','fuite','robinet','toilette','toilettes','lavabo','radiateur','tableau','electrique',
                  'onduleur','groupe','electrogene','pompe','compteur','vanne','store','volet','plafond','peinture',
                  'vitre','badge','cle','ordinateur','ecran','imprimante','chaise','fauteuil','bureau','armoire',
                  'refrigerateur','chauffage','plomberie','electricite','serrurerie','menuiserie','nettoyage'] as $w) $v[$w] ??= [];
        ksort($v, SORT_STRING);
        return $this->vocabCache = $v;
    }

    /**
     * Mots-clés d'une requête : [mots d'origine, mots + synonymes métier, numéros isolés].
     * @return array{0: string[], 1: string[]}
     */
    public function keywords(string $q): array {
        static $stop = ['je','tu','il','elle','on','nous','vous','ils','le','la','les','l','un','une','des','de','d',
            'du','au','aux','en','et','ou','est','sont','sur','dans','pour','avec','par','ne','pas','que','qui',
            'quoi','où','quand','quel','quelle','quels','quelles','combien','comment','ce','cet','cette','ces',
            'mon','ma','mes','ton','ta','tes','son','sa','ses','se','y','a','moi','donne','donner','liste',
            'lister','montre','montrer','cherche','chercher','trouve','trouver','affiche','afficher','tous',
            'toutes','tout','peux','peut','veux','voudrais','stp','svp','merci','il','y','a-t-il','existe',
            'the','of','and','est-ce','qu','c'];
        static $syn = [
            'ssi'  => ['sécurité incendie','alarme incendie','centrale incendie','détection incendie'],
            'baes' => ['bloc autonome','éclairage sécurité','éclairage secours'],
            'cta'  => ['centrale traitement air','centrale ventilation'],
            'vmc'  => ['ventilation','extraction air'],
            'ecs'  => ['eau chaude sanitaire','ballon eau chaude'],
            'gtb'  => ['gestion technique bâtiment','supervision'],
            'tgbt' => ['tableau général basse tension','armoire électrique'],
            'cvc'  => ['chauffage ventilation climatisation'],
            'pac'  => ['pompe à chaleur'],
            'pcs'  => ['poste central sécurité'],
            'des'  => ['désenfumage'],
            'erp'  => ['établissement recevant public'],
            'igh'  => ['immeuble grande hauteur'],
            'pmr'  => ['personne mobilité réduite','accessibilité'],
        ];
        $words = preg_split('/[\s,;:.\-\'’"\/()?!«»#°]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY);
        // Numéros isolés, ordinaux compris (2, 02, 2e, 2ème, 1er).
        $nums = [];
        foreach ($words as $w) {
            if (preg_match('/^(\d{1,6})(e|eme|ème|er|ere|ère|nd|nde)?$/u', $w, $m)) $nums[] = (string)(int)$m[1];
        }
        $words = array_filter($words, fn($w) => !preg_match('/^\d{1,2}(e|eme|ème|er|ere|ère|nd|nde)?$/u', $w));
        $orig = array_values(array_unique(array_filter($words,
            fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stop, true))));
        // Pluriels simples : « pompes » trouve aussi « pompe ».
        $orig = array_map(fn($w) => (mb_strlen($w) > 4 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) ? mb_substr($w, 0, -1) : $w, $orig);
        $orig = array_values(array_unique($orig));

        $expanded = $orig;
        foreach ($orig as $w) {
            if (isset($syn[$w])) {
                foreach ($syn[$w] as $exp) {
                    foreach (preg_split('/\s+/u', $exp) as $ww) if (mb_strlen($ww) >= 4) $expanded[] = $ww;
                }
            }
            foreach ($syn as $acro => $exps) {
                foreach ($exps as $exp) {
                    if (mb_strlen($w) >= 4 && str_contains(mb_strtolower($exp), $w)) { $expanded[] = $acro; break; }
                }
            }
        }
        // Plafond : chaque mot multiplie les LIKE côté SQL.
        return [$orig, array_slice(array_values(array_unique($expanded)), 0, 12), array_values(array_unique($nums))];
    }

    /**
     * Compactage d'un résultat pour le modèle : retire les valeurs vides et les
     * clés inutiles, tronque les textes, arrondit les décimaux, limite les
     * listes imbriquées. Aucune donnée utile n'est inventée ni reformulée.
     */
    public function compact(mixed $data, ?int $maxChars = null, int $depth = 0): mixed {
        $maxChars ??= $this->maxChars;
        if (is_array($data)) {
            $isList = array_is_list($data);
            $out = [];
            foreach ($data as $k => $v) {
                if (!$isList && in_array((string)$k, self::DROP_KEYS, true)) continue;
                if ($v === null || $v === '' || $v === []) continue;
                $out[$k] = $this->compact($v, $maxChars, $depth + 1);
            }
            // Listes d'objets imbriquées dans une ligne (VuSurPlan, ElementsLies…) :
            // 3 suffisent. Les listes de scalaires (ElementIds) restent entières.
            if ($isList && $depth >= 3 && count($out) > 3 && is_array(reset($out))) $out = array_slice($out, 0, 3);
            return $isList ? array_values($out) : $out;
        }
        if (is_float($data)) return round($data, 2);
        if (is_string($data)) {
            $data = trim(preg_replace('/\s+/u', ' ', $data));
            if (preg_match('/^-?\d+\.\d{3,}$/', $data)) return (string)round((float)$data, 2);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]00:00:00(\.0+)?$/', $data, $m)) return $m[1];
            if (mb_strlen($data) > $maxChars) return mb_substr($data, 0, $maxChars) . '…';
        }
        return $data;
    }

    /** Anonymisation RGPD des données personnelles dans les résultats. */
    private function anonymize(array $data): array {
        $anonFields = ['NomPrenom','NomDeclarant','ContactNom','ContactTel','ContactEmail',
            'AjoutePar','SupprimeParLogin','SaisieParLogin','CreatedBy','UpdatedBy',
            'AgentNom','AgentPrenom','AgentTel','AgentEmail',
            'EmailDemandeur','TelDemandeur','NomAutrui','EmailAutrui','TelAutrui'];
        $excludedKeys = ['DateEnvoiMail','DateMail','EnvoiMail','TelechargementUrl','TelechargementId'];

        $walk = function (&$v) use (&$walk, $anonFields, $excludedKeys) {
            if (!is_array($v)) return;
            foreach ($v as $k => &$vv) {
                if (is_array($vv)) { $walk($vv); continue; }
                if (!is_string($k)) continue;
                if (in_array($k, $anonFields, true) && is_string($vv) && $vv !== '') { $vv = '[personne]'; continue; }
                if (in_array($k, $excludedKeys, true)) continue;
                if (is_string($vv) && $vv !== '') {
                    if ((stripos($k, 'email') !== false || stripos($k, 'mail') !== false)
                        && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim($vv))) {
                        $vv = '[email]';
                    } elseif ((stripos($k, 'tel') !== false || stripos($k, 'phone') !== false)
                        && preg_match('/\d{8,}/', preg_replace('/\D/', '', $vv))) {
                        $vv = '[téléphone]';
                    }
                }
            }
            unset($vv);
        };
        $walk($data);
        return $data;
    }
}
