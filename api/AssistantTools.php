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
 * Larka — Assistant IA : Outils de lecture (tool calling)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Expose une poignée de fonctions PHP au LLM via le mécanisme de "tool calling"
 * standard (Anthropic / OpenAI / Mistral / Ollama compatibles).
 *
 * Au lieu de déverser toutes les données dans le prompt, le LLM décide quel
 * outil appeler avec quels arguments, le backend exécute, renvoie le résultat,
 * et le LLM continue jusqu'à pouvoir répondre.
 *
 * PRINCIPE :
 *   - LECTURE UNIQUEMENT — aucun outil ne modifie la BDD.
 *   - 6 outils volontairement larges (couvrir 80% des cas avec peu de surface).
 *   - Anonymisation RGPD systématique sur les résultats retournés.
 *
 * UTILISATION :
 *   $tools = new AssistantTools($db);
 *   $schema = $tools->getOpenAISchema();              // pour OpenAI/Mistral/Ollama
 *   $schema = $tools->getAnthropicSchema();           // pour Anthropic
 *   $result = $tools->execute('search', ['type'=>'biens', 'query'=>'centrale']);
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class AssistantTools {
    private Database $db;
    private int $maxResults = 30;
    private ?array $_openaiSchemaCache = null;
    private ?array $_anthropicSchemaCache = null;
    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Définition centrale des outils exposés à l'IA.
     * Pour en ajouter un : ajouter une entrée ici + une méthode `tool_xxx()` plus bas.
     */
    private function definitions(): array {
        return [
            [
                'name' => 'search',
                'description' => "Recherche dans Larka. Type au choix : 'biens', 'equipements', 'interventions', 'contrats', 'demandes', 'stock', 'documents', 'plans' (zones/locaux dessinés), 'archives'. Utilise des mots-clés simples. Limité à 30 résultats par appel.",
                'parameters' => [
                    'type' => ['type' => 'string', 'description' => "Table à interroger : biens, equipements, interventions, contrats, demandes, stock, documents, plans, archives", 'required' => true],
                    'query' => ['type' => 'string', 'description' => "Mots-clés de recherche (acronymes acceptés : SSI, BAES, CTA, VMC, etc.)", 'required' => true],
                    'filtres' => ['type' => 'object', 'description' => "Filtres optionnels selon le type : pour 'interventions' : statut/type ; pour 'contrats' : statut/societe ; pour 'biens'/'equipements' : famille/batiment/etat ; pour 'demandes' : statut/urgence/categorie ; pour 'stock' : categorie", 'required' => false],
                ],
            ],
            [
                'name' => 'get_fiche',
                'description' => "Récupère la fiche détaillée complète d'UN élément précis. Utilise après un 'search' pour avoir tous les champs.",
                'parameters' => [
                    'type' => ['type' => 'string', 'description' => "bien, equipement, intervention, contrat, demande, stock", 'required' => true],
                    'id' => ['type' => 'integer', 'description' => "Identifiant numérique de la fiche", 'required' => true],
                ],
            ],
            [
                'name' => 'localiser',
                'description' => "Où se trouve un bien ou un équipement ? Renvoie bâtiment, étage, bureau, et — si dessiné sur un plan — la zone visuelle avec son étage et son numéro pour ouvrir le plan.",
                'parameters' => [
                    'type' => ['type' => 'string', 'description' => "bien ou equipement", 'required' => true],
                    'id' => ['type' => 'integer', 'description' => "Identifiant de l'élément à localiser", 'required' => true],
                ],
            ],
            [
                'name' => 'localiser_groupe',
                'description' => "Localise PLUSIEURS biens ou équipements en un seul appel. Plus efficace que d'appeler 'localiser' plusieurs fois. Utile quand tu as une liste de résultats de 'search' et que tu veux tous les positionner sur les plans en même temps. Renvoie un tableau avec pour chaque ID son emplacement.",
                'parameters' => [
                    'type' => ['type' => 'string', 'description' => "bien ou equipement (tous les ids doivent être du même type)", 'required' => true],
                    'ids' => ['type' => 'array', 'description' => "Liste des IDs à localiser, ex: [42, 43, 88]", 'required' => true],
                ],
            ],
            [
                'name' => 'compter',
                'description' => "Compte les éléments d'une table avec filtres. Renvoie un nombre. Utile pour 'combien de…'. Types : biens, equipements, interventions, contrats, demandes, stock, plans (= éléments dessinés sur les plans : points, zones, traits, textes).",
                'parameters' => [
                    'type' => ['type' => 'string', 'description' => "biens, equipements, interventions, contrats, demandes, stock, plans", 'required' => true],
                    'filtres' => ['type' => 'object', 'description' => "Mêmes filtres que pour 'search'. Ex: {statut: 'En cours'} ou {famille: 'SSI', batiment: 'A'}. Pour type='plans' : {type_element: 'point'} ou {calque: 'SSI'}.", 'required' => false],
                ],
            ],
            [
                'name' => 'alertes',
                'description' => "Récupère les alertes actives de Larka : contrats qui expirent (dans X jours), stock en alerte (sous le seuil), interventions en retard (date dépassée mais non terminées), demandes non traitées. Renvoie un résumé.",
                'parameters' => [
                    'categorie' => ['type' => 'string', 'description' => "Optionnel : 'contrats', 'stock', 'interventions', 'demandes' ou 'toutes' (défaut)", 'required' => false],
                    'jours' => ['type' => 'integer', 'description' => "Optionnel : pour les contrats, horizon en jours (défaut 30)", 'required' => false],
                ],
            ],
            [
                'name' => 'contexte',
                'description' => "Retourne le vocabulaire métier du site : liste des bâtiments, familles de biens/équipements, catégories de demandes, gestionnaires actifs. À appeler au début si tu n'es pas sûr de la terminologie locale.",
                'parameters' => [],
            ],
            [
                'name' => 'qui_est',
                'description' => "Recherche dans l'annuaire des utilisateurs de Larka (demandeurs, gestionnaires, techniciens, admins). Permet à un gestionnaire de retrouver les coordonnées d'un demandeur ou d'identifier qui est rattaché à un service. Renvoie nom, prénom, rôle, service, poste, login et — si l'utilisateur a explicitement renseigné ses coordonnées professionnelles — son téléphone pro et son email pro. Les coordonnées personnelles ne sont jamais exposées.",
                'parameters' => [
                    'query' => ['type' => 'string', 'description' => "Mots-clés : nom, prénom, login, service, ou rôle (ex: 'Dupont', 'Jean Martin', 'Direction des finances', 'Gestionnaire')", 'required' => false],
                    'role'  => ['type' => 'string', 'description' => "Filtrer par rôle exact : 'Demandeur', 'Gestionnaire', 'Technicien', 'Admin', 'Visionneur'", 'required' => false],
                    'id'    => ['type' => 'integer', 'description' => "Récupérer un utilisateur précis par son ID (ex: pour 'qui est le demandeur de la demande #42' → d'abord get_fiche(demande, 42), puis qui_est(id=DemandeurId))", 'required' => false],
                ],
            ],
        ];
    }

    /**
     * Définitions effectivement exposées au modèle pour CETTE requête.
     * Point d'extension : un outil dont la disponibilité dépend du contexte
     * (droits, connecteur activé) se déclare ici plutôt que dans definitions().
     */
    private function activeDefinitions(): array {
        return $this->definitions();
    }

    /** Schéma au format OpenAI / Mistral / Ollama (le plus répandu). */
    public function getOpenAISchema(): array {
        if ($this->_openaiSchemaCache !== null) return $this->_openaiSchemaCache;
        $out = [];
        foreach ($this->activeDefinitions() as $d) {
            $props = []; $req = [];
            foreach ($d['parameters'] as $name => $p) {
                $props[$name] = ['type' => $p['type'], 'description' => $p['description']];
                if (!empty($p['required'])) $req[] = $name;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $d['name'],
                    'description' => $d['description'],
                    'parameters' => [
                        'type' => 'object',
                        'properties' => empty($props) ? (object)[] : $props,
                        'required' => $req,
                    ],
                ],
            ];
        }
        return $this->_openaiSchemaCache = $out;
    }

    /** Schéma au format Anthropic (clé "input_schema" au lieu de "parameters"). */
    public function getAnthropicSchema(): array {
        if ($this->_anthropicSchemaCache !== null) return $this->_anthropicSchemaCache;
        $out = [];
        foreach ($this->activeDefinitions() as $d) {
            $props = []; $req = [];
            foreach ($d['parameters'] as $name => $p) {
                $props[$name] = ['type' => $p['type'], 'description' => $p['description']];
                if (!empty($p['required'])) $req[] = $name;
            }
            $out[] = [
                'name' => $d['name'],
                'description' => $d['description'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => empty($props) ? (object)[] : $props,
                    'required' => $req,
                ],
            ];
        }
        return $this->_anthropicSchemaCache = $out;
    }

    /** Exécute un outil. Renvoie toujours un array (jamais throw). */
    public function execute(string $toolName, array $args): array {
        $method = 'tool_' . $toolName;
        if (!method_exists($this, $method)) {
            return ['error' => "Outil inconnu : $toolName"];
        }
        try {
            $result = $this->$method($args);
            // L'outil 'qui_est' est l'annuaire interne : il expose volontairement
            // les coordonnées PRO (TelPro, EmailPro) — c'est son utilité. On
            // contourne l'anonymisation pour ce cas précis. Les coordonnées
            // PERSONNELLES (Tel, Email = login) restent filtrées en amont par
            // tool_qui_est() qui ne renvoie QUE les champs autorisés.
            if ($toolName === 'qui_est') {
                return $result;
            }
            return $this->anonymize($result);
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] tool error: ' . $e->getMessage());
            return ['error' => "Une erreur interne est survenue lors de l'exécution de l'outil."];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Implémentation des outils ──
    // ═══════════════════════════════════════════════════════════════════════

    private function tool_search(array $args): array {
        $type    = strtolower($args['type'] ?? '');
        $query   = trim($args['query'] ?? '');
        $filtres = is_array($args['filtres'] ?? null) ? $args['filtres'] : [];
        if (!$query) return ['error' => "Paramètre 'query' requis"];

        $map = [
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
        if (!isset($map[$type])) {
            return [
                'error' => "Type '$type' invalide.",
                'types_valides' => array_keys($map),
            ];
        }

        // Mots-clés + expansion synonymes basique (réutilise la logique d'assistant.php)
        $keywords = $this->extractKeywords($query);
        if (!$keywords) return ['type' => $type, 'count' => 0, 'results' => []];

        // Délégation à $db->searchForAssistant() puis filtrage par type
        $all = $this->db->searchForAssistant($keywords);
        $rows = $all[$map[$type]] ?? [];

        // Appliquer filtres simples (sur les colonnes texte de chaque ligne)
        if ($filtres && $rows) {
            $rows = array_values(array_filter($rows, function($row) use ($filtres) {
                foreach ($filtres as $col => $val) {
                    if ($val === '' || $val === null) continue;
                    $colName = ucfirst($col);
                    $rowVal = $row[$colName] ?? $row[$col] ?? null;
                    if ($rowVal === null) return false;
                    if (stripos((string)$rowVal, (string)$val) === false) return false;
                }
                return true;
            }));
        }

        return [
            'type'    => $type,
            'count'   => count($rows),
            'results' => array_slice($rows, 0, $this->maxResults),
        ];
    }

    private function tool_get_fiche(array $args): array {
        $type = strtolower($args['type'] ?? '');
        $id   = (int)($args['id'] ?? 0);
        if (!$id) return ['error' => "Paramètre 'id' requis"];

        $row = null;
        switch ($type) {
            case 'bien':         $row = $this->db->getBienById($id); break;
            case 'equipement':   $row = $this->db->getEquipementById($id); break;
            case 'intervention': $row = $this->db->getInterventionById($id); break;
            case 'contrat':      $row = $this->db->getContratById($id); break;
            case 'demande':
                $r = $this->db->fetchOne("SELECT * FROM DemandesIntervention WHERE Id=:id", ['id'=>$id]);
                $row = $r ?: null; break;
            case 'stock':
                $r = $this->db->fetchOne("SELECT * FROM Stock WHERE Id=:id", ['id'=>$id]);
                $row = $r ?: null; break;
            default: return ['error' => "Type inconnu : $type"];
        }
        if (!$row) return ['error' => "Fiche non trouvée"];
        return ['fiche' => $row];
    }

    private function tool_localiser(array $args): array {
        $type = strtolower($args['type'] ?? '');
        $id   = (int)($args['id'] ?? 0);
        if (!$id) return ['error' => "Paramètre 'id' requis"];

        // Champs texte de la fiche
        $row = null;
        if ($type === 'bien') $row = $this->db->getBienById($id);
        elseif ($type === 'equipement') $row = $this->db->getEquipementById($id);
        else return ['error' => "Type doit être 'bien' ou 'equipement'"];
        if (!$row) return ['error' => "Élément non trouvé"];

        $loc = [
            'numero'  => $row['Numero'] ?? '',
            'batiment'=> $row['Batiment'] ?? '',
            'etage'   => $row['Etage'] ?? '',
            'bureau'  => $row['NumeroBureau'] ?? '',
        ];

        // Position sur les plans (via PlanLiens)
        $assetType = ($type === 'bien') ? 'Bien' : 'Equipement';
        try {
            $linked = $this->db->fetchAll(
                "SELECT el.Id AS ElementId, el.Nom AS ElementNom, el.TypeElement,
                        et.Id AS EtageId, et.Nom AS EtageNom, et.Niveau,
                        b.Id AS BatimentId, b.Nom AS BatimentNom
                 FROM PlanLiens pl
                 JOIN PlanElements el ON pl.ElementId = el.Id
                 LEFT JOIN PlanEtages et ON el.EtageId = et.Id
                 LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id
                 WHERE pl.AssetType = :t AND pl.AssetId = :id LIMIT 5",
                ['t' => $assetType, 'id' => $id]
            );
            if ($linked) $loc['sur_plan'] = $linked;
        } catch (\Throwable $_) {}

        return $loc;
    }

    private function tool_localiser_groupe(array $args): array {
        $type = strtolower($args['type'] ?? '');
        $ids  = $args['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) return ['error' => "Paramètre 'ids' (tableau) requis"];
        if (!in_array($type, ['bien', 'equipement'], true)) return ['error' => "Type doit être 'bien' ou 'equipement'"];

        $assetType = ($type === 'bien') ? 'Bien' : 'Equipement';
        $idsInt = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (empty($idsInt)) return ['error' => "Aucun ID valide"];
        // Borner pour éviter une requête monstrueuse + un payload JSON énorme renvoyé au LLM
        $truncated = false;
        if (count($idsInt) > 50) {
            $idsInt = array_slice($idsInt, 0, 50);
            $truncated = true;
        }
        $idsInt = array_values(array_unique($idsInt));

        // Récupérer toutes les positions plan en UN seul appel SQL
        try {
            $placeholders = implode(',', array_map(fn($i) => ":id$i", array_keys($idsInt)));
            $params = ['t' => $assetType];
            foreach ($idsInt as $k => $v) $params["id$k"] = $v;

            $rows = $this->db->fetchAll(
                "SELECT pl.AssetId, el.Id AS ElementId, el.Nom AS ElementNom, el.TypeElement,
                        et.Id AS EtageId, et.Nom AS EtageNom, et.Niveau,
                        b.Id AS BatimentId, b.Nom AS BatimentNom
                 FROM PlanLiens pl
                 JOIN PlanElements el ON pl.ElementId = el.Id
                 LEFT JOIN PlanEtages et ON el.EtageId = et.Id
                 LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id
                 WHERE pl.AssetType = :t AND pl.AssetId IN ($placeholders)",
                $params
            );

            // Indexer par AssetId pour faciliter l'accès côté LLM
            $byId = [];
            foreach ($rows as $r) {
                $aid = (int)$r['AssetId'];
                if (!isset($byId[$aid])) $byId[$aid] = [];
                $byId[$aid][] = [
                    'ElementId'   => (int)$r['ElementId'],
                    'ElementNom'  => $r['ElementNom'],
                    'TypeElement' => $r['TypeElement'],
                    'EtageId'     => $r['EtageId'] ? (int)$r['EtageId'] : null,
                    'EtageNom'    => $r['EtageNom'],
                    'BatimentId'  => $r['BatimentId'] ? (int)$r['BatimentId'] : null,
                    'BatimentNom' => $r['BatimentNom'],
                ];
            }

            // Récupérer les noms / numéros pour TOUS les IDs demandés en UNE seule requête.
            // (Avant : on bouclait avec getBienById/getEquipementById → N requêtes SQL,
            //  ce qui annulait tout l'intérêt de l'outil "groupe".)
            $table = ($type === 'bien') ? 'Biens' : 'Equipements';
            $idParams = [];
            foreach ($idsInt as $k => $v) $idParams["aid$k"] = $v;
            $idPh = implode(',', array_map(fn($k) => ":$k", array_keys($idParams)));
            $assets = $this->db->fetchAll(
                "SELECT Id, Numero, InfoProduit, Marque, Modele, Batiment, Etage, NumeroBureau
                 FROM $table
                 WHERE Id IN ($idPh) AND DateSuppression IS NULL",
                $idParams
            );
            // Indexer par Id pour préserver l'ordre demandé
            $assetsById = [];
            foreach ($assets as $a) $assetsById[(int)$a['Id']] = $a;

            $details = [];
            foreach ($idsInt as $id) {
                $row = $assetsById[$id] ?? null;
                if (!$row) continue;
                $nom = $row['InfoProduit'] ?: trim(($row['Marque'] ?? '') . ' ' . ($row['Modele'] ?? ''));
                $details[] = [
                    'id'       => $id,
                    'numero'   => $row['Numero'] ?? '',
                    'nom'      => $nom,
                    'batiment' => $row['Batiment'] ?? '',
                    'etage'    => $row['Etage'] ?? '',
                    'bureau'   => $row['NumeroBureau'] ?? '',
                    'sur_plan' => $byId[$id] ?? null,
                ];
            }

            // Regroupement utile pour l'IA : tous les éléments par EtageId, pour les liens [FICHE:plans:...]
            $regroupement = [];
            foreach ($rows as $r) {
                $etId = (int)($r['EtageId'] ?? 0);
                if (!$etId) continue;
                if (!isset($regroupement[$etId])) {
                    $regroupement[$etId] = [
                        'EtageId'     => $etId,
                        'EtageNom'    => $r['EtageNom'],
                        'BatimentNom' => $r['BatimentNom'],
                        'ElementIds'  => [],
                    ];
                }
                $regroupement[$etId]['ElementIds'][] = (int)$r['ElementId'];
            }

            return [
                'count'           => count($details),
                'count_sur_plan'  => count($byId),
                'truncated'       => $truncated,
                'details'         => $details,
                'regroupement_par_etage' => array_values($regroupement),
            ];
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] SQL error: ' . $e->getMessage());
            return ['error' => "Erreur lors de la requête."];
        }
    }

    private function tool_compter(array $args): array {
        $type = strtolower($args['type'] ?? '');
        $filtres = is_array($args['filtres'] ?? null) ? $args['filtres'] : [];
        $tableMap = [
            'biens' => ['Biens', "DateSuppression IS NULL"],
            'equipements' => ['Equipements', "DateSuppression IS NULL"],
            'interventions' => ['Interventions', ''],
            'contrats' => ['Contrats', ''],
            'demandes' => ['DemandesIntervention', ''],
            'stock' => ['Stock', ''],
            // 'plans' = éléments dessinés sur les plans (points, zones, traits, textes).
            // Filtres pertinents : TypeElement ('point'/'zone'/'trait'/'texte'),
            // Calque (couche logique), EtageId.
            // NB : le nom de la table est 'PlanElements' (PascalCase), pas snake_case.
            'plans' => ['PlanElements', ''],
        ];
        if (!isset($tableMap[$type])) return ['error' => "Type inconnu"];
        [$table, $extraWhere] = $tableMap[$type];

        // Whitelist stricte des colonnes filtrables par table. Évite que le LLM
        // (ou un acteur malveillant via prompt injection) injecte un nom de colonne
        // arbitraire qui ferait planter la requête, ou pire, exposerait des champs sensibles.
        $colWhitelist = [
            'Biens'                => ['Famille','Batiment','Etage','Etat','TypeBien','Categorie'],
            'Equipements'          => ['Famille','Batiment','Etage','Etat','TypeEquipement','Marque'],
            'Interventions'        => ['Statut','Type','Categorie','Priorite','Batiment'],
            'Contrats'             => ['Statut','Societe','Type','Categorie'],
            'DemandesIntervention' => ['Statut','Urgence','Categorie','Batiment'],
            'Stock'                => ['Categorie','Famille','Emplacement'],
            // PlanElements : on accepte aussi 'type_element' comme alias de 'TypeElement'
            // (le LLM utilise spontanément le snake_case).
            'PlanElements'         => ['TypeElement','Calque','EtageId','Nom'],
        ];
        $allowed = $colWhitelist[$table] ?? [];
        $allowedLower = array_map('strtolower', $allowed);

        // Filtre spécial "annee" (sur DateCreation ou DateIntervention)
        $where = []; $params = [];
        if ($extraWhere) $where[] = $extraWhere;

        foreach ($filtres as $col => $val) {
            if ($val === '' || $val === null) continue;
            $colLower = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $col));
            if (!$colLower) continue;

            // Alias pratiques : le LLM utilise spontanément des noms snake_case
            // ou des synonymes. On les normalise vers le nom interne whitelisté.
            $aliases = [
                'type_element' => 'typeelement',
                'type'         => 'typeelement', // pour type='plans', filtres={type:'point'}
                'etage_id'     => 'etageid',
            ];
            if (isset($aliases[$colLower])) {
                $colLower = $aliases[$colLower];
            }

            // Cas spécial année (utilisé par les exemples du prompt)
            if ($colLower === 'annee' && preg_match('/^\d{4}$/', (string)$val)) {
                $dateCol = ($table === 'Interventions') ? 'DateIntervention'
                         : (($table === 'DemandesIntervention' || $table === 'Contrats') ? 'DateCreation' : null);
                if ($dateCol) {
                    $where[] = "SUBSTR(CAST(\"$dateCol\" AS TEXT),1,4) = :annee";
                    $params['annee'] = (string)$val;
                }
                continue;
            }

            // Cas général : colonne whitelistée uniquement
            $idx = array_search($colLower, $allowedLower, true);
            if ($idx === false) continue; // colonne refusée silencieusement
            $safeCol = $allowed[$idx];
            $where[] = "LOWER(CAST(\"$safeCol\" AS TEXT)) LIKE LOWER(:v_$safeCol)";
            $params["v_$safeCol"] = '%' . $val . '%';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        try {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM $table $whereSql", $params);
            return ['type' => $type, 'count' => (int)($row['c'] ?? 0), 'filtres' => $filtres];
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] SQL error: ' . $e->getMessage());
            return ['error' => "Erreur lors de la requête."];
        }
    }

    private function tool_alertes(array $args): array {
        $cat   = strtolower($args['categorie'] ?? 'toutes');
        $jours = max(1, min(365, (int)($args['jours'] ?? 30)));
        $out = [];

        if ($cat === 'toutes' || $cat === 'contrats') {
            try {
                $rows = $this->db->getContratsEnAlerte();
                $out['contrats_expirent'] = array_slice(array_map(fn($r) => [
                    'id' => $r['Id'],
                    'societe' => $r['Societe'],
                    'numero' => $r['Numero'],
                    'date_fin' => $r['DateFin'],
                    'montant_annuel' => $r['MontantAnnuel'],
                ], $rows), 0, 30);
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'stock') {
            try {
                $rows = $this->db->getStockEnAlerte();
                $out['stock_en_alerte'] = array_slice(array_map(fn($r) => [
                    'id' => $r['Id'],
                    'designation' => $r['Designation'],
                    'quantite' => $r['Quantite'],
                    'seuil' => $r['SeuilAlerte'],
                ], $rows), 0, 30);
            } catch (\Throwable $_) {}
        }
        if ($cat === 'toutes' || $cat === 'interventions') {
            try {
                // Interventions planifiées avec DateIntervention dépassée
                $rows = $this->db->fetchAll(
                    "SELECT Id, Numero, Type, Statut, DateIntervention, Description
                     FROM Interventions
                     WHERE Statut IN ('Planifiée','En cours')
                       AND DateIntervention IS NOT NULL AND DateIntervention != ''
                       AND DateIntervention < :today
                     ORDER BY DateIntervention LIMIT 30",
                    ['today' => date('Y-m-d')]
                );
                $out['interventions_en_retard'] = $rows;
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
                $out['demandes_non_traitees'] = $rows;
            } catch (\Throwable $_) {}
        }
        return $out;
    }

    /**
     * Annuaire interne — recherche un utilisateur par mot-clé, rôle ou ID.
     *
     * Politique RGPD : on n'expose JAMAIS le mot de passe (évident), les tokens,
     * les IDs Microsoft/Google. Pour les coordonnées, distinction PRO vs PERSO :
     *   - PRO (TelPro, OfficeLocation, CompanyName) : exposé sans restriction —
     *     c'est de l'annuaire interne légitime.
     *   - PERSO (Tel, TelMobile, Email perso ≠ Login) : exposé UNIQUEMENT si
     *     l'utilisateur a explicitement coché "partager ces coordonnées dans
     *     l'annuaire" (champ PartagerCoordonnees s'il existe, sinon non exposé).
     *
     * Cet outil est exempté de anonymize() — voir execute(). C'est lui qui doit
     * filtrer ce qui sort.
     */
    private function tool_qui_est(array $args): array {
        $id    = (int)($args['id'] ?? 0);
        $query = trim((string)($args['query'] ?? ''));
        $role  = trim((string)($args['role'] ?? ''));
        $roles = ['Demandeur','Gestionnaire','Technicien','Admin','Visionneur'];

        // Champs renvoyés : on ne mappe PAS Tel/TelMobile/Email perso par défaut.
        // Login est utile pour les références internes (le LLM peut s'en servir
        // pour mettre en relation avec un AssigneA, un DeclarantLogin, etc.).
        $select = "Id, Nom, Prenom, Login, Role, Service, Poste, TelPro, "
                . "OfficeLocation, CompanyName, ManagerName, Actif";

        // Lookup par ID
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

        // Construction des WHERE
        $where  = ["Actif = 1"];
        $params = [];

        if ($role && in_array($role, $roles, true)) {
            $where[] = "Role = :role";
            $params['role'] = $role;
        }

        if ($query !== '') {
            // Recherche LIKE sur plusieurs champs. On split en mots et on
            // applique un AND entre mots (chaque mot doit matcher AU MOINS
            // un champ). C'est plus permissif que d'exiger tous les mots
            // sur le même champ.
            $kw = $this->extractKeywords($query);
            if (!empty($kw)) {
                $i = 0;
                foreach ($kw as $w) {
                    $i++;
                    $k = ":kw$i";
                    $params["kw$i"] = '%' . mb_strtolower($w) . '%';
                    $where[] = "(LOWER(Nom) LIKE $k OR LOWER(Prenom) LIKE $k OR LOWER(Login) LIKE $k "
                             . "OR LOWER(Service) LIKE $k OR LOWER(Poste) LIKE $k OR LOWER(Role) LIKE $k)";
                }
            } else {
                // Pas de mot-clé exploitable, on traite query comme un seul motif
                $params['raw'] = '%' . mb_strtolower($query) . '%';
                $where[] = "(LOWER(Nom) LIKE :raw OR LOWER(Prenom) LIKE :raw OR LOWER(Login) LIKE :raw)";
            }
        }

        $sql = "SELECT $select FROM Utilisateurs WHERE " . implode(' AND ', $where)
             . " ORDER BY Nom, Prenom LIMIT 30";
        try {
            $rows = $this->db->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] annuaire error: ' . $e->getMessage());
            return ['error' => 'Erreur lors de la lecture de l\'annuaire.'];
        }

        if (empty($rows)) {
            return ['count' => 0, 'results' => [], 'message' => 'Aucun utilisateur trouvé.'];
        }

        return [
            'count'   => count($rows),
            'results' => array_map(fn($r) => $this->formatUserRow($r), $rows),
        ];
    }

    /** Formate une ligne utilisateur pour exposition au LLM. */
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
        try {
            $r = $this->db->fetchAll("SELECT DISTINCT Batiment FROM Biens WHERE Batiment IS NOT NULL AND Batiment != '' AND DateSuppression IS NULL ORDER BY Batiment LIMIT 50");
            $out['batiments'] = array_column($r, 'Batiment');
        } catch (\Throwable $_) {}
        try {
            $r = $this->db->fetchAll("SELECT DISTINCT Famille FROM Biens WHERE Famille IS NOT NULL AND Famille != '' AND DateSuppression IS NULL ORDER BY Famille LIMIT 30");
            $out['familles_biens'] = array_column($r, 'Famille');
        } catch (\Throwable $_) {}
        try {
            $r = $this->db->fetchAll("SELECT DISTINCT Famille FROM Equipements WHERE Famille IS NOT NULL AND Famille != '' AND DateSuppression IS NULL ORDER BY Famille LIMIT 30");
            $out['familles_equipements'] = array_column($r, 'Famille');
        } catch (\Throwable $_) {}
        try {
            $r = $this->db->fetchAll("SELECT DISTINCT Categorie FROM DemandesIntervention WHERE Categorie IS NOT NULL AND Categorie != '' ORDER BY Categorie LIMIT 30");
            $out['categories_demandes'] = array_column($r, 'Categorie');
        } catch (\Throwable $_) {}
        try {
            $r = $this->db->fetchAll("SELECT Prenom, Nom, Poste, Service FROM Utilisateurs WHERE Actif = 1 AND Role IN ('Gestionnaire','Admin') ORDER BY Nom LIMIT 20");
            $out['gestionnaires'] = array_map(fn($u) => trim(($u['Prenom'] ?? '') . ' ' . ($u['Nom'] ?? '')) . ' (' . ($u['Poste'] ?: $u['Service'] ?: '') . ')', $r);
        } catch (\Throwable $_) {}
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Helpers ──
    // ═══════════════════════════════════════════════════════════════════════

    /** Extraction de mots-clés simple + expansion synonymes/acronymes métier. */
    private function extractKeywords(string $q): array {
        $stop = ['je','tu','il','elle','le','la','les','un','une','des','de','du','au','aux','en','et','ou','est','sur','dans','pour','avec','par','ne','que','qui','quoi','où','quand','quel','quelle','combien','comment'];
        $words = preg_split('/[\s,;:.\-\'"\/()?!]+/u', mb_strtolower($q));
        $kw = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stop)));

        // Dictionnaire d'acronymes métier (réutilisé d'assistant.php)
        $syn = [
            'ssi'=>['sécurité incendie','alarme incendie','centrale incendie','détection incendie'],
            'baes'=>['bloc autonome','éclairage sécurité','éclairage secours'],
            'cta'=>['centrale traitement air','centrale ventilation'],
            'vmc'=>['ventilation','extraction air'],
            'ecs'=>['eau chaude sanitaire','ballon eau chaude'],
            'gtb'=>['gestion technique bâtiment','supervision'],
            'tgbt'=>['tableau général basse tension','armoire électrique'],
            'cvc'=>['chauffage ventilation climatisation'],
            'pac'=>['pompe à chaleur'],
            'pcs'=>['poste central sécurité'],
            'des'=>['désenfumage'],
            'erp'=>['établissement recevant public'],
            'igh'=>['immeuble grande hauteur'],
            'pmr'=>['personne mobilité réduite','accessibilité'],
        ];
        $expanded = $kw;
        foreach ($kw as $w) {
            $k = mb_strtolower($w);
            if (isset($syn[$k])) {
                foreach ($syn[$k] as $exp) {
                    foreach (preg_split('/\s+/', $exp) as $ww) if (mb_strlen($ww) >= 4) $expanded[] = $ww;
                    $expanded[] = $exp;
                }
            }
            // Sens inverse : mot du domaine → acronyme
            foreach ($syn as $acro => $exps) {
                foreach ($exps as $exp) {
                    if (mb_strlen($k) >= 4 && str_contains(mb_strtolower($exp), $k)) {
                        $expanded[] = $acro; break;
                    }
                }
            }
        }
        return array_values(array_unique($expanded));
    }

    /** Anonymisation RGPD des données personnelles dans les résultats. */
    private function anonymize(array $data): array {
        $anonFields = ['NomPrenom','NomDeclarant','ContactNom','ContactTel','ContactEmail',
            'AjoutePar','SupprimeParLogin','SaisieParLogin','CreatedBy','UpdatedBy',
            'AgentNom','AgentPrenom','AgentTel','AgentEmail',
            'EmailDemandeur','TelDemandeur','NomAutrui','EmailAutrui','TelAutrui'];
        // Liste de clés qui contiennent "mail"/"tel" dans leur nom mais ne sont PAS
        // des données personnelles (dates, drapeaux, etc.) — on les laisse intactes.
        $excludedKeys = ['DateEnvoiMail','DateMail','EnvoiMail','TelechargementUrl','TelechargementId'];

        $walk = function(&$v) use (&$walk, $anonFields, $excludedKeys) {
            if (!is_array($v)) return;
            foreach ($v as $k => &$vv) {
                // Recursion d'abord pour les sous-structures
                if (is_array($vv)) {
                    $walk($vv);
                    continue;
                }
                if (!is_string($k)) continue;

                // Clé directement listée comme anonymisable
                if (in_array($k, $anonFields, true) && is_string($vv) && $vv !== '') {
                    $vv = '[personne]';
                    continue;
                }
                if (in_array($k, $excludedKeys, true)) continue;

                if (is_string($vv) && $vv !== '') {
                    // Email : la clé contient "mail" ET la valeur ressemble à un email
                    if ((stripos($k, 'email') !== false || stripos($k, 'mail') !== false)
                        && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim($vv))) {
                        $vv = '[email]';
                    }
                    // Téléphone : la clé contient "tel"/"phone" ET ≥ 8 chiffres
                    elseif ((stripos($k, 'tel') !== false || stripos($k, 'phone') !== false)
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
