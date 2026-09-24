<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Inventaire
 *
 * Actions : biens (CRUD), equipements (CRUD), stock (CRUD),
 *           interv_lignes (consommation pièces stock)
 */

// ── Biens ─────────────────────────────────────────────────────────────────────
if ($action === 'biens') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'biens', $db);
    if ($method==='GET') {
        // ⚠️ FIX PERFS : pagination opt-in (cf. interventions)
        if (!empty($_GET['paginated'])) {
            $limit   = isset($_GET['limit'])  ? (int)$_GET['limit']  : 100;
            $offset  = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $filters = [];
            if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];
            json_ok($db->getBiensPaginated($limit, $offset, $filters));
        } else {
            json_ok($db->getAllBiens());
        }
    }
    require_role($user, ['Admin','Gestionnaire']);
    if ($method==='POST')   json_ok(['id' => $db->addBien(get_body(), $user['Login'])]);
    if ($method==='PUT')    { $db->updateBien($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { $db->deleteBien($id, $user['Login']); json_ok('OK'); }
}

// ── Équipements ───────────────────────────────────────────────────────────────
if ($action === 'equipements') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'equipements', $db);
    if ($method==='GET')    json_ok($db->getAllEquipements());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method==='POST') {
        $nouvelId = $db->addEquipement(get_body(), $user['Login']);
        $creee = $db->getEquipementById($nouvelId) ?: [];
        json_ok(['id' => $nouvelId]);
    }
    if ($method==='PUT')    { $db->updateEquipement($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { $db->deleteEquipement($id, $user['Login']); json_ok('OK'); }
}

// ── Stock ─────────────────────────────────────────────────────────────────────
if ($action === 'stock') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'stock', $db);
    if ($method==='GET')    json_ok($db->getAllStock());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method==='POST')   json_ok(['id' => $db->addStock(get_body(), $user['Login'])]);
    if ($method==='PUT')    { $db->updateStock($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') {
        try { $db->deleteStock($id); json_ok('OK'); }
        catch (\RuntimeException $e) { json_error($e->getMessage(), 409); }
    }
}

// ── Lignes stock par intervention ─────────────────────────────────────────────
if ($action === 'interv_lignes') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method === 'GET')    json_ok($db->getLignesIntervention((int)($_GET['interv_id']??0)));
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->addLigneIntervention(array_merge(get_body(), ['interventionId' => (int)($_GET['interv_id']??0)]))]);
    if ($method === 'DELETE') { $db->deleteLigneIntervention($id); json_ok('OK'); }
}

// ── Inventaire Agent (Demandeur) ─────────────────────────────────────────────
// GET  → Biens affectés à l'agent + ses déclarations passées
// POST → Créer une déclaration (présence ou départ)
if ($action === 'inventaire_agent') {
    $user = require_auth();

    // Vérifier que la fonctionnalité est activée
    $cfgActif = $db->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = 'inventaire_agent_actif'");
    if (!$cfgActif || $cfgActif['Valeur'] !== '1') {
        json_error('L\'inventaire agent n\'est pas activé. Contactez votre administrateur.');
    }

    if ($method === 'GET') {
        $userId = (int)$user['Id'];
        $login  = $user['Login'];
        $prenom = trim($user['Prenom'] ?? '');
        $nom    = trim($user['Nom'] ?? '');
        $nomComplet = trim("$prenom $nom");

        // Biens affectés à l'agent
        $allBiens = $db->getAllBiens();
        $mesBiens = array_values(array_filter($allBiens, function($b) use ($userId, $login, $nom, $prenom, $nomComplet) {
            // Priorité 1 : lien direct par UserId (fiable à 100%)
            if (!empty($b['AffecteUserId']) && (int)$b['AffecteUserId'] === $userId) {
                return true;
            }

            // Priorité 2 : matching texte intelligent sur NomPrenom
            $affecte = strtolower(trim($b['NomPrenom'] ?? ''));
            if (!$affecte) return false;

            // Normaliser : retirer accents pour comparaison
            $normalize = function($s) {
                $s = strtolower(trim($s));
                $s = str_replace(
                    ['à','â','ä','é','è','ê','ë','ï','î','ô','ö','ù','û','ü','ÿ','ç','œ','æ'],
                    ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','y','c','oe','ae'],
                    $s
                );
                return $s;
            };

            $affN = $normalize($affecte);
            $myNom     = $normalize($nom);
            $myPrenom  = $normalize($prenom);
            $myFull    = $normalize($nomComplet);
            $myLogin   = strtolower($login);

            // Match exact nom complet (dans les 2 sens : "Dupont Jean" ou "Jean Dupont")
            if ($myFull && ($affN === $myFull || $affN === $normalize("$nom $prenom"))) return true;

            // Match login
            if ($myLogin && str_contains($affN, $myLogin)) return true;

            // Match nom + prénom séparément (gère "J. Dupont", "Dupont J.", diminutifs)
            if ($myNom && strlen($myNom) >= 2 && str_contains($affN, $myNom)) {
                // Le nom de famille est trouvé, vérifier le prénom au moins par initiale
                if (!$myPrenom) return true;
                if (str_contains($affN, $myPrenom)) return true;
                // Initiale du prénom (gère "J. Dupont" ou "J Dupont")
                if (str_contains($affN, $myPrenom[0] . '.') || str_contains($affN, $myPrenom[0] . ' ')) return true;
                // Juste le nom de famille seul
                if ($affN === $myNom) return true;
            }

            return false;
        }));

        // Déclarations de cet agent
        $declarations = $db->getDeclarationsByUser($login);

        json_ok([
            'biens'        => $mesBiens,
            'declarations' => $declarations,
        ]);
    }

    if ($method === 'POST') {
        $body = get_body();
        $numero = trim($body['numeroBien'] ?? '');
        $type   = ($body['type'] ?? 'presence') === 'depart' ? 'depart' : 'presence';

        if (!$numero) json_error('Veuillez saisir un numéro de bien.');

        // Chercher le bien par numéro
        $bien = $db->fetchOne("SELECT Id, Numero, InfoProduit, Famille, NomPrenom FROM Biens WHERE Numero = :num AND DateSuppression IS NULL", ['num' => $numero]);
        if (!$bien) json_error("Aucun bien trouvé avec le numéro « {$numero} ». Vérifiez le numéro.");

        $nomAgent = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
        if (!$nomAgent) $nomAgent = $user['Login'];

        // Vérifier doublon en attente du même agent sur le même bien
        $existant = $db->fetchOne(
            "SELECT Id FROM DeclarationsInventaire WHERE BienId = :bid AND DeclarantLogin = :login AND Statut = 'en_attente'",
            ['bid' => $bien['Id'], 'login' => $user['Login']]
        );
        if ($existant) json_error('Vous avez déjà une déclaration en attente pour ce bien.');

        // Vérifier doublons d'autres agents
        $doublons = $db->checkDoublon($bien['Id'], $user['Login']);
        $alerte = count($doublons) > 0;

        $declId = $db->addDeclaration([
            'bienId'       => $bien['Id'],
            'numeroBien'   => $numero,
            'type'         => $type,
            'batiment'     => $body['batiment'] ?? '',
            'etage'        => $body['etage'] ?? '',
            'numeroBureau' => $body['numeroBureau'] ?? '',
            'commentaire'  => $body['commentaire'] ?? '',
            'raisonDepart' => $body['raisonDepart'] ?? '',
            'destBatiment' => $body['destBatiment'] ?? '',
            'destEtage'    => $body['destEtage'] ?? '',
            'destBureau'   => $body['destBureau'] ?? '',
            'destPersonne' => $body['destPersonne'] ?? '',
            'personneDeclaree' => $body['personneDeclaree'] ?? '',
            'login'        => $user['Login'],
            'nom'          => $nomAgent,
        ]);

        json_ok([
            'id'      => $declId,
            'doublon' => $alerte,
            'bien'    => ['Numero' => $bien['Numero'], 'InfoProduit' => $bien['InfoProduit'], 'Famille' => $bien['Famille']],
        ]);
    }
}

// ── Recherche bien par numéro (pour l'agent) ────────────────────────────────
if ($action === 'inventaire_agent_search') {
    $user = require_auth();
    $cfgActif = $db->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = 'inventaire_agent_actif'");
    if (!$cfgActif || $cfgActif['Valeur'] !== '1') json_error('Inventaire agent non activé.');

    $numero = trim($_GET['numero'] ?? '');
    if (!$numero) json_error('Numéro requis.');

    $bien = $db->fetchOne(
        "SELECT Id, Numero, Famille, SousFamille, InfoProduit, NumeroSerie, Batiment, Etage, NumeroBureau, NomPrenom
         FROM Biens WHERE Numero = :num AND DateSuppression IS NULL",
        ['num' => $numero]
    );
    if (!$bien) json_error("Aucun bien trouvé avec le numéro « {$numero} ».");
    json_ok($bien);
}

// ── Gestion des déclarations (Admin / Gestionnaire) ─────────────────────────
if ($action === 'declarations_inventaire') {
    $user = require_auth();

    if ($method === 'GET') {
        // Admin/Gestionnaire : toutes les déclarations ; Demandeur : seulement les siennes
        if (in_array($user['Role'], ['Admin', 'Gestionnaire'])) {
            $statut = $_GET['statut'] ?? '';
            json_ok($db->getAllDeclarations($statut));
        } else {
            json_ok($db->getDeclarationsByUser($user['Login']));
        }
    }

    // Valider ou refuser (Admin/Gestionnaire uniquement)
    if ($method === 'PUT') {
        require_role($user, ['Admin', 'Gestionnaire']);
        $body = get_body();
        $statut = ($body['statut'] ?? '') === 'valide' ? 'valide' : 'refuse';
        $motif  = $body['motifRefus'] ?? '';
        $nomTraiteur = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));

        $decl = $db->getDeclarationById($id);
        if (!$decl) json_error('Déclaration introuvable.', 404);
        if ($decl['Statut'] !== 'en_attente') json_error('Cette déclaration a déjà été traitée.');

        $bienId = $decl['BienId'] ?? null;

        // ── Validation d'une PRÉSENCE → mettre à jour le bien ──
        if ($statut === 'valide' && $decl['Type'] === 'presence' && $bienId) {
            $personneDeclaree = trim($decl['PersonneDeclaree'] ?? '');

            // Déterminer le nom et l'UserId de la personne à affecter
            if ($personneDeclaree) {
                // L'agent a indiqué une autre personne en possession
                $nomAffecte = $personneDeclaree;
                $userIdAffecte = null;
                // Chercher l'UserId correspondant parmi les utilisateurs actifs
                $allUsers = $db->getAllUtilisateurs();
                $searchLower = mb_strtolower(trim($personneDeclaree));
                foreach ($allUsers as $u) {
                    if (($u['Actif'] ?? 1) == 0) continue;
                    $full = mb_strtolower(trim(($u['Prenom'] ?? '') . ' ' . ($u['Nom'] ?? '')));
                    $rev  = mb_strtolower(trim(($u['Nom'] ?? '') . ' ' . ($u['Prenom'] ?? '')));
                    if ($full === $searchLower || $rev === $searchLower) {
                        $userIdAffecte = (int)$u['Id'];
                        break;
                    }
                }
            } else {
                // Pas de personne déclarée → affecter au déclarant
                $nomAffecte = $decl['DeclarantNom'] ?: ($decl['DeclarantLogin'] ?? '');
                $declarant = $db->fetchOne("SELECT Id FROM Utilisateurs WHERE Login = :login", ['login' => $decl['DeclarantLogin']]);
                $userIdAffecte = $declarant ? (int)$declarant['Id'] : null;
            }

            // Récupérer le bien actuel pour ne pas écraser avec du vide
            $bienActuel = $db->fetchOne("SELECT Batiment, Etage, NumeroBureau FROM Biens WHERE Id = :id", ['id' => (int)$bienId]);

            $newBat    = !empty($decl['Batiment'])     ? $decl['Batiment']     : ($bienActuel['Batiment'] ?? '');
            $newEtage  = !empty($decl['Etage'])        ? $decl['Etage']        : ($bienActuel['Etage'] ?? '');
            $newBureau = !empty($decl['NumeroBureau']) ? $decl['NumeroBureau'] : ($bienActuel['NumeroBureau'] ?? '');

            $db->execute(
                "UPDATE Biens SET NomPrenom = :nom, AffecteUserId = :uid, Batiment = :bat, Etage = :etage, NumeroBureau = :bureau, UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
                [
                    'nom'    => $nomAffecte,
                    'uid'    => $userIdAffecte,
                    'bat'    => $newBat,
                    'etage'  => $newEtage,
                    'bureau' => $newBureau,
                    'dt'     => date('Y-m-d H:i:s'),
                    'by'     => $user['Login'],
                    'id'     => (int)$bienId,
                ]
            );
        }

        // ── Validation d'un DÉPART → mettre à jour la fiche avec les infos de destination ──
        if ($statut === 'valide' && $decl['Type'] === 'depart' && $bienId) {
            $raison = $decl['RaisonDepart'] ?? '';

            if ($raison === 'transfert_bureau') {
                $bienActuel = $db->fetchOne("SELECT Batiment, Etage, NumeroBureau FROM Biens WHERE Id = :id", ['id' => (int)$bienId]);
                $db->execute(
                    "UPDATE Biens SET NomPrenom = '', AffecteUserId = NULL, Batiment = :bat, Etage = :etage, NumeroBureau = :bureau, UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
                    [
                        'bat'    => $decl['DestBatiment'] ?: ($bienActuel['Batiment'] ?? ''),
                        'etage'  => $decl['DestEtage']    ?: ($bienActuel['Etage'] ?? ''),
                        'bureau' => $decl['DestBureau']   ?: ($bienActuel['NumeroBureau'] ?? ''),
                        'dt'     => date('Y-m-d H:i:s'),
                        'by'     => $user['Login'],
                        'id'     => (int)$bienId,
                    ]
                );
            } elseif ($raison === 'donne_personne') {
                $bienActuel = $db->fetchOne("SELECT Batiment, Etage, NumeroBureau FROM Biens WHERE Id = :id", ['id' => (int)$bienId]);
                $db->execute(
                    "UPDATE Biens SET NomPrenom = :nom, AffecteUserId = NULL, Batiment = :bat, Etage = :etage, NumeroBureau = :bureau, UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
                    [
                        'nom'    => $decl['DestPersonne'] ?? '',
                        'bat'    => $decl['DestBatiment'] ?: ($bienActuel['Batiment'] ?? ''),
                        'etage'  => $decl['DestEtage']    ?: ($bienActuel['Etage'] ?? ''),
                        'bureau' => $decl['DestBureau']   ?: ($bienActuel['NumeroBureau'] ?? ''),
                        'dt'     => date('Y-m-d H:i:s'),
                        'by'     => $user['Login'],
                        'id'     => (int)$bienId,
                    ]
                );
            } elseif ($raison === 'retour_magasin') {
                $db->execute(
                    "UPDATE Biens SET NomPrenom = '', AffecteUserId = NULL, Batiment = '', Etage = '', NumeroBureau = '', Etat = 'Stock', UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
                    ['dt' => date('Y-m-d H:i:s'), 'by' => $user['Login'], 'id' => (int)$bienId]
                );
            } elseif ($raison === 'hors_service') {
                $commentaire = 'Signalé hors service par ' . ($decl['DeclarantNom'] ?: $decl['DeclarantLogin']);
                $db->execute(
                    "UPDATE Biens SET NomPrenom = '', AffecteUserId = NULL, CommentaireSortie = :comm, UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
                    ['comm' => $commentaire, 'dt' => date('Y-m-d H:i:s'), 'by' => $user['Login'], 'id' => (int)$bienId]
                );
            } else {
                $db->execute(
                    "UPDATE Biens SET NomPrenom = '', AffecteUserId = NULL, UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
                    ['dt' => date('Y-m-d H:i:s'), 'by' => $user['Login'], 'id' => (int)$bienId]
                );
            }
        }

        $db->traiterDeclaration($id, $statut, $user['Login'], $nomTraiteur, $motif);
        json_ok('OK');
    }
}

// ── Compteur déclarations en attente (pour badge) ───────────────────────────
if ($action === 'declarations_inventaire_count') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    json_ok(['count' => $db->countDeclarations('en_attente')]);
}

// ══════════════════════════════════════════════════════════════════════════════
// IMPORT BIENS EN MASSE (depuis fichier Excel/CSV parsé côté navigateur)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'import_biens') {
    $user = require_auth();
    require_role($user, ['Admin','Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode invalide.', 405);

    // ─── Limites étendues pour gros volumes ───
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);          // 5 min max
    @ini_set('max_execution_time', '300');

    // ─── Capture d'erreurs fatales pour TOUJOURS renvoyer du JSON ───
    // Sans ce handler, une erreur fatale PHP renvoie une page HTML d'erreur
    // qui plante le JSON.parse côté frontend.
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            // Nettoyer le buffer de sortie pour ne pas mélanger HTML d'erreur et JSON
            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'error'   => 'Erreur fatale : ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
            ], JSON_UNESCAPED_UNICODE);
        }
    });
    // Capturer la sortie pour pouvoir la nettoyer si erreur
    if (!ob_get_level()) ob_start();

    $body   = get_body();
    $dryRun = !empty($body['dryRun']);
    $target = $body['target'] ?? 'biens';
    if (!in_array($target, ['biens','equipements','auto'], true)) $target = 'biens';
    $items  = $body['biens'] ?? [];
    if (!is_array($items)) json_error('Format invalide : items doit être un tableau.');
    if (count($items) > 10000) json_error('Trop d\'enregistrements (max 10000 par import — découpez en lots).');

    // Catégories de listes selon la cible
    if ($target === 'equipements') {
        $listCats = ['FamilleEquipement','SousFamilleEquipement','StatutEquipement','EtatAsset','Batiment'];
        $catFamille     = 'FamilleEquipement';
        $catSousFamille = 'SousFamilleEquipement';
        $catStatut      = 'StatutEquipement';
    } elseif ($target === 'auto') {
        // Mode auto : on a besoin des deux familles de listes
        $listCats = ['FamilleBien','SousFamilleBien','StatutBien','FamilleEquipement','SousFamilleEquipement','StatutEquipement','EtatAsset','Batiment'];
        $catFamille     = 'FamilleBien';        // valeur par défaut, surchargée par item
        $catSousFamille = 'SousFamilleBien';
        $catStatut      = 'StatutBien';
    } else {
        $listCats = ['FamilleBien','SousFamilleBien','StatutBien','EtatAsset','Batiment'];
        $catFamille     = 'FamilleBien';
        $catSousFamille = 'SousFamilleBien';
        $catStatut      = 'StatutBien';
    }

    // ── Helpers de normalisation pour rendre l'import robuste ──
    $cleanDate = function($v) {
        if (!$v) return null;
        $s = trim((string)$v);
        if ($s === '' || $s === '0' || $s === 'null' || $s === 'NULL') return null;
        // Format ISO YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        // Format français DD/MM/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', $s, $m)) {
            $y = $m[3]; if (strlen($y) == 2) $y = ($y < '50' ? '20' : '19') . $y;
            return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
        }
        // Format DD-MM-YYYY
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})/', $s, $m)) {
            $y = $m[3]; if (strlen($y) == 2) $y = ($y < '50' ? '20' : '19') . $y;
            return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
        }
        // Tentative générique
        $t = strtotime($s);
        if ($t !== false) return date('Y-m-d', $t);
        return null;
    };
    $cleanNum = function($v) {
        if ($v === null || $v === '' || $v === false) return 0.0;
        $s = str_replace([' ', "\xc2\xa0", '€', ','], ['', '', '', '.'], (string)$v);
        return is_numeric($s) ? (float)$s : 0.0;
    };
    $cleanStr = function($v) {
        if ($v === null) return '';
        $s = trim((string)$v);
        // Nettoyer les .0 résiduels d'Excel/pandas
        if (preg_match('/^(\d+)\.0+$/', $s, $m)) return $m[1];
        return $s;
    };

    // ── Phase 1 : récupérer l'existant (numéros + listes) ──
    // En mode auto, on garde 2 dictionnaires séparés (un par cible) car 2 numéros identiques
    // peuvent exister, l'un en Biens et l'autre en Équipements.
    // En mode dédié, $existing sert pour la cible spécifique seulement.
    $existingBiens = [];
    $existingEquips = [];
    if ($target === 'equipements' || $target === 'auto') {
        foreach ($db->getAllEquipements() as $row) {
            if (!empty($row['Numero'])) $existingEquips[strtolower(trim($row['Numero']))] = (int)$row['Id'];
        }
    }
    if ($target === 'biens' || $target === 'auto') {
        foreach ($db->getAllBiens() as $row) {
            if (!empty($row['Numero'])) $existingBiens[strtolower(trim($row['Numero']))] = (int)$row['Id'];
        }
    }
    // Pour rétrocompat avec le code en aval qui utilise $existing
    $existing = ($target === 'equipements') ? $existingEquips : $existingBiens;

    $listesByCat = [];
    foreach ($listCats as $cat) {
        $listesByCat[$cat] = [];
        foreach ($db->getListe($cat) as $r) {
            $listesByCat[$cat][strtolower(trim($r['Valeur']))] = $r['Valeur'];
        }
    }

    // ── Phase 2 : analyse + détection des nouvelles valeurs de listes ──
    $errors        = [];
    $duplicatesSuffixed = 0;
    $listesToAdd   = array_fill_keys($listCats, []);
    $toCreateIdx   = []; $toUpdateIdx = []; $toSkipIdx = [];
    $catsSeen      = array_fill_keys($listCats, []);

    $biens = $items; // alias pour minimiser les changements ci-dessous

    foreach ($biens as $i => $b) {
        $row = $b['_row'] ?? ($i + 2);
        // Normaliser le numéro : supprimer le .0 si Excel/pandas a converti en float
        $numero = trim((string)($b['numero'] ?? ''));
        if (preg_match('/^(\d+)\.0+$/', $numero, $m)) $numero = $m[1];
        $biens[$i]['numero'] = $numero;
        // Idem pour _NumeroImmo et _BC
        foreach (['_NumeroImmo', '_BC'] as $fld) {
            $v = trim((string)($b[$fld] ?? ''));
            if (preg_match('/^(\d+)\.0+$/', $v, $m2)) $v = $m2[1];
            $biens[$i][$fld] = $v;
        }
        if ($numero === '') {
            $errors[] = ['row' => $row, 'message' => 'N° de bien vide'];
            continue;
        }
        // En mode auto, déterminer la cible de cette ligne d'après _targetGuess
        // (envoyé par le frontend) ou recalcul côté serveur si manquant.
        $itemTarget = $target;
        if ($target === 'auto') {
            $itemTarget = $b['_targetGuess'] ?? null;
            if (!in_array($itemTarget, ['biens','equipements'], true)) {
                // Fallback : devine côté backend
                $f = strtolower(trim((string)($b['famille'] ?? '')));
                $f = preg_replace('/[\x{0300}-\x{036f}]/u', '', \Normalizer::normalize($f, \Normalizer::FORM_D) ?: $f);
                $equipKw = ['cvc','plomberie','plomb','electricite','elec','ssi','incendie','chauffage','climatisation','ventilation','clim','sanitaire','ascenseur','serrurerie','menuiserie','toiture','eclairage','extincteur','detection','alarme','cloture','voirie','gaz','reseau eau'];
                $itemTarget = 'biens';
                foreach ($equipKw as $kw) { if (strpos($f, $kw) !== false) { $itemTarget = 'equipements'; break; } }
            }
            $biens[$i]['_targetGuess'] = $itemTarget;
        }

        // Collecter les valeurs candidates pour ajout dans les listes (selon la cible de la ligne)
        $catFamilleItem     = $itemTarget === 'equipements' ? 'FamilleEquipement'     : 'FamilleBien';
        $catSousFamilleItem = $itemTarget === 'equipements' ? 'SousFamilleEquipement' : 'SousFamilleBien';
        $catStatutItem      = $itemTarget === 'equipements' ? 'StatutEquipement'      : 'StatutBien';

        $catMap = [
            $catFamilleItem     => $b['famille']     ?? '',
            $catSousFamilleItem => $b['sousFamille'] ?? '',
            $catStatutItem      => $b['statut']      ?? '',
            'EtatAsset'         => $b['etat']        ?? '',
            'Batiment'          => $b['batiment']    ?? '',
        ];
        foreach ($catMap as $cat => $val) {
            $v = trim((string)$val);
            if ($v === '') continue;
            if (!isset($listesByCat[$cat])) continue;     // sécurité (mode auto a tous les cats)
            $key = strtolower($v);
            if (!isset($listesByCat[$cat][$key]) && !isset($catsSeen[$cat][$key])) {
                $catsSeen[$cat][$key] = $v;
                $listesToAdd[$cat][] = $v;
            }
        }

        $existKey = strtolower($numero);
        // En mode auto, vérifier l'existence dans le bon dico selon la cible de l'item
        if ($target === 'auto') {
            $dico = $itemTarget === 'equipements' ? $existingEquips : $existingBiens;
        } else {
            $dico = $existing;
        }
        if (isset($dico[$existKey])) {
            $toUpdateIdx[] = $i;
        } else {
            $toCreateIdx[] = $i;
        }
    }

    // Doublons internes (un même numéro 2× dans le fichier) :
    // SUFFIXAGE AUTOMATIQUE — on garde toutes les lignes en suffixant les doublons
    // (cas typique : "10776" devient "10776-2", "10776-3" pour les occurrences suivantes)
    $seenNum = [];
    foreach ([&$toCreateIdx, &$toUpdateIdx] as &$arr) {
        foreach ($arr as $i) {
            $orig = trim((string)($biens[$i]['numero'] ?? ''));
            $key = strtolower($orig);
            if (!isset($seenNum[$key])) {
                $seenNum[$key] = 1;
            } else {
                $seenNum[$key]++;
                $newNum = $orig . '-' . $seenNum[$key];
                // Vérifier que le suffixe ne collide pas avec un autre numéro
                $newKey = strtolower($newNum);
                while (isset($seenNum[$newKey])) {
                    $seenNum[$key]++;
                    $newNum = $orig . '-' . $seenNum[$key];
                    $newKey = strtolower($newNum);
                }
                $biens[$i]['numero'] = $newNum;
                $seenNum[$newKey] = 1;
                $duplicatesSuffixed++;
            }
        }
    }
    unset($arr);

    // Recalculer toCreateIdx / toUpdateIdx après suffixage
    // (un numéro suffixé peut maintenant être "nouveau" alors qu'il était considéré comme "à update")
    $newToCreateIdx = []; $newToUpdateIdx = [];
    foreach (array_merge($toCreateIdx, $toUpdateIdx) as $i) {
        $existKey = strtolower(trim((string)($biens[$i]['numero'] ?? '')));
        if ($target === 'auto') {
            $itemTg = $biens[$i]['_targetGuess'] ?? 'biens';
            $dico = $itemTg === 'equipements' ? $existingEquips : $existingBiens;
        } else {
            $dico = $existing;
        }
        if (isset($dico[$existKey])) $newToUpdateIdx[] = $i;
        else $newToCreateIdx[] = $i;
    }
    $toCreateIdx = $newToCreateIdx;
    $toUpdateIdx = $newToUpdateIdx;

    // Filtrage selon stratégie : pour cet import, on respecte $body['dedup']
    $dedup = $body['dedup'] ?? 'update';
    if ($dedup === 'skip') {
        $toSkipIdx = $toUpdateIdx;
        $toUpdateIdx = [];
    }

    // Listes à ajouter — déduplication
    $listesToAddOut = [];
    foreach ($listesToAdd as $cat => $vals) {
        $vals = array_values(array_unique($vals));
        if (count($vals) > 0) $listesToAddOut[$cat] = $vals;
    }

    // ── DRY-RUN : on s'arrête ici et on retourne le diagnostic ──
    if ($dryRun) {
        // Compteurs routage en mode auto
        $routedBiens = 0; $routedEquips = 0; $sortis = 0;
        foreach (array_merge($toCreateIdx, $toUpdateIdx) as $i) {
            $tg = $biens[$i]['_targetGuess'] ?? $target;
            if ($tg === 'equipements') $routedEquips++; else $routedBiens++;
            if (!empty($biens[$i]['_isSorti'])) $sortis++;
        }
        json_ok([
            'toCreate'           => count($toCreateIdx),
            'toUpdate'           => count($toUpdateIdx),
            'toSkip'             => count($toSkipIdx),
            'errors'             => array_slice($errors, 0, 200),
            'listesToCreate'     => $listesToAddOut,
            'totalParsed'        => count($biens),
            'duplicatesSuffixed' => $duplicatesSuffixed,
            'routedBiens'        => $routedBiens,
            'routedEquipements'  => $routedEquips,
            'sortis'             => $sortis,
        ]);
    }

    // ══ Phase 3 : import réel ══
    $created = 0; $updated = 0; $failed = 0; $listesAdded = 0; $comptaCreated = 0;
    $login = $user['Login'];

    // Activer le mode transaction pour de bien meilleures performances sur gros volume
    $pdoConn = null;
    try {
        // On utilise reflection pour récupérer le PDO interne (Database expose pas directement)
        $r = new \ReflectionClass($db);
        if ($r->hasProperty('pdo')) {
            $p = $r->getProperty('pdo');
            $p->setAccessible(true);
            $pdoConn = $p->getValue($db);
        }
    } catch (\Throwable $e) { $pdoConn = null; }

    if ($pdoConn) { try { $pdoConn->beginTransaction(); } catch(\Throwable $e) {} }

    // Resync proactif des séquences AVANT tout INSERT pour éviter les collisions futures
    try { $db->resyncAllPgSequences(); } catch(\Throwable $e) {}

    // 3a) Ajouter les nouvelles valeurs aux listes
    foreach ($listesToAddOut as $cat => $vals) {
        foreach ($vals as $v) {
            // Savepoint pour isoler l'erreur (sinon contamination 25P02)
            $spListe = null;
            if ($pdoConn && $pdoConn->inTransaction()) {
                try { $spListe = 'sp_liste_' . md5($cat.$v); $pdoConn->exec("SAVEPOINT $spListe"); }
                catch (\Throwable $_) { $spListe = null; }
            }
            try {
                $db->addListeValeur($cat, $v, 0, 0, 0);
                $listesAdded++;
                $listesByCat[$cat][strtolower(trim($v))] = $v;
                if ($spListe) { try { $pdoConn->exec("RELEASE SAVEPOINT $spListe"); } catch(\Throwable $_) {} }
            } catch (\Throwable $e) {
                if ($spListe) { try { $pdoConn->exec("ROLLBACK TO SAVEPOINT $spListe"); $pdoConn->exec("RELEASE SAVEPOINT $spListe"); } catch(\Throwable $_) {} }
                // Tentative de resync si erreur 23505 (rare avec auto-resync, mais filet de sécurité)
                if (str_contains($e->getMessage(), '23505') || str_contains($e->getMessage(), 'duplicate')) {
                    if ($pdoConn && $pdoConn->inTransaction()) {
                        try { $spListe = 'sp_liste_retry_' . md5($cat.$v); $pdoConn->exec("SAVEPOINT $spListe"); } catch (\Throwable $_) { $spListe = null; }
                    }
                    try {
                        $db->resyncAllPgSequences();
                        $db->addListeValeur($cat, $v, 0, 0, 0);
                        $listesAdded++;
                        $listesByCat[$cat][strtolower(trim($v))] = $v;
                        if ($spListe) { try { $pdoConn->exec("RELEASE SAVEPOINT $spListe"); } catch(\Throwable $_) {} }
                    } catch(\Throwable $e2) {
                        if ($spListe) { try { $pdoConn->exec("ROLLBACK TO SAVEPOINT $spListe"); $pdoConn->exec("RELEASE SAVEPOINT $spListe"); } catch(\Throwable $_) {} }
                    }
                }
            }
        }
    }

    // 3b) Importer les items (biens ou équipements ou les deux selon mode)
    // Stratégie : un SAVEPOINT par item. Si une ligne échoue, on rollback CE savepoint
    // mais la transaction principale reste valide pour les lignes suivantes.
    // Cela évite la cascade 25P02 "current transaction is aborted".
    $useSavepoints = ($pdoConn !== null) && $pdoConn->inTransaction();
    $savepointCounter = 0;

    $allIdx = array_merge($toCreateIdx, $toUpdateIdx);
    foreach ($allIdx as $i) {
        $b = $biens[$i];
        // Cible effective de cette ligne
        $itemTarget = ($target === 'auto') ? ($b['_targetGuess'] ?? 'biens') : $target;

        // Démarrer un savepoint pour isoler cette ligne
        $spName = null;
        if ($useSavepoints) {
            try {
                $spName = 'sp_item_' . (++$savepointCounter);
                $pdoConn->exec("SAVEPOINT $spName");
            } catch (\Throwable $_) { $spName = null; }
        }

        try {
            // Construire les données selon la cible (avec helpers de nettoyage robustes)
            if ($itemTarget === 'equipements') {
                $itemData = [
                    'numero'       => $cleanStr($b['numero']),
                    'famille'      => $cleanStr($b['famille']) ?: 'Plomberie',
                    'sousFamille'  => $cleanStr($b['sousFamille']),
                    'infoProduit'  => $cleanStr($b['infoProduit']),
                    'numeroSerie'  => $cleanStr($b['numeroSerie']),
                    'marque'       => $cleanStr($b['marque'] ?? ''),
                    'modele'       => $cleanStr($b['modele'] ?? ''),
                    'fournisseur'  => $cleanStr($b['fournisseur'] ?? ''),
                    'batiment'     => $cleanStr($b['batiment']),
                    'etage'        => $cleanStr($b['etage'] ?? ''),
                    'numeroBureau' => $cleanStr($b['numeroBureau'] ?? ''),
                    'nomPrenom'    => $cleanStr($b['nomPrenom']),
                    'observations' => $cleanStr($b['observations'] ?? ''),
                    'statut'       => $cleanStr($b['statut']) ?: 'Commande',
                    'etat'         => $cleanStr($b['etat']) ?: 'Stock',
                    'dateCommande' => $cleanDate($b['dateCommande'] ?? null),
                    'dateLivraison'=> $cleanDate($b['dateLivraison'] ?? null),
                    'dateInstallation' => $cleanDate($b['dateInstallation'] ?? null),
                    'prix'         => $cleanNum($b['prix'] ?? 0),
                ];
            } else {
                $itemData = [
                    'numero'       => $cleanStr($b['numero']),
                    'famille'      => $cleanStr($b['famille']) ?: 'Mobilier',
                    'sousFamille'  => $cleanStr($b['sousFamille']),
                    'infoProduit'  => $cleanStr($b['infoProduit']),
                    'numeroSerie'  => $cleanStr($b['numeroSerie']),
                    'batiment'     => $cleanStr($b['batiment']),
                    'nomPrenom'    => $cleanStr($b['nomPrenom']),
                    'statut'       => $cleanStr($b['statut']) ?: 'Livre',
                    'etat'         => $cleanStr($b['etat']) ?: 'Stock',
                    'dateCommande' => $cleanDate($b['dateCommande'] ?? null),
                    'dateLivraison'=> $cleanDate($b['dateLivraison'] ?? null),
                    'prix'         => $cleanNum($b['prix'] ?? 0),
                ];
            }

            // Choisir le bon dico d'existence selon la cible de l'item
            if ($target === 'auto') {
                $dicoRef = ($itemTarget === 'equipements') ? 'existingEquips' : 'existingBiens';
            } else {
                $dicoRef = 'existing';
            }
            $dico = &$$dicoRef;

            $existKey = strtolower($itemData['numero']);
            $itemId = null;
            if (isset($dico[$existKey])) {
                if ($itemTarget === 'equipements') $db->updateEquipement($dico[$existKey], $itemData, $login);
                else $db->updateBien($dico[$existKey], $itemData, $login);
                $itemId = $dico[$existKey];
                $updated++;
            } else {
                $itemId = $itemTarget === 'equipements'
                    ? $db->addEquipement($itemData, $login)
                    : $db->addBien($itemData, $login);
                $dico[$existKey] = $itemId;
                $created++;
            }
            unset($dico); // libérer la référence

            // Compta : uniquement pour les Biens (pas pour les Équipements)
            if ($itemTarget === 'biens') {
                $numImmo = $cleanStr($b['_NumeroImmo'] ?? '');
                $bcStr   = $cleanStr($b['_BC'] ?? '');
                $amortCum = $cleanNum($b['_AmortCum'] ?? 0);
                $vnc      = $cleanNum($b['_VNC'] ?? 0);
                $hasCompta = ($numImmo !== '') || ($amortCum > 0) || ($vnc > 0) || ($bcStr !== '');
                if ($hasCompta && $itemId) {
                    $valHt    = $cleanNum($b['prix'] ?? 0);
                    $exercice = '';
                    $dateAcq  = $cleanDate($b['dateCommande'] ?? null);
                    if ($dateAcq && preg_match('/^(\d{4})/', $dateAcq, $mm)) $exercice = $mm[1];

                    $existCompta = $db->fetchOne(
                        "SELECT Id FROM GestionMateriel WHERE AssetType = :t AND AssetId = :id",
                        ['t' => 'Bien', 'id' => $itemId]
                    );
                    $payload = [
                        'AssetType'                => 'Bien',
                        'AssetId'                  => $itemId,
                        'NumeroImmo'               => $numImmo,
                        'CompteImmo'               => '',
                        'Exercice'                 => $exercice,
                        'BonDeCommande'            => $bcStr,
                        'DateMiseEnService'        => $cleanDate($b['dateLivraison'] ?? null),
                        'NumeroMandat'             => '',
                        'DateAchat'                => $dateAcq,
                        'ValeurAchat'              => $valHt,
                        'ValeurVenale'             => $vnc,
                        'DureeAmortissement'       => 0,
                        'MontantDuMarche'          => 0,
                        'DateBascule'              => null,
                        'AnnualiteAmortissement'   => 0,
                        'DepreciationTotal'        => $amortCum,
                        'DateCalculDepreciation'   => date('Y-m-d'),
                        'User7'                    => '',
                        'Item'                     => '',
                    ];
                    // Compta dans son propre savepoint pour ne pas contaminer
                    // le bien créé en cas d'erreur sur la compta
                    $spCompta = null;
                    if ($useSavepoints) {
                        try {
                            $spCompta = 'sp_compta_' . (++$savepointCounter);
                            $pdoConn->exec("SAVEPOINT $spCompta");
                        } catch (\Throwable $_) { $spCompta = null; }
                    }
                    try {
                        if ($existCompta) {
                            $payload['Id'] = (int)$existCompta['Id'];
                            $cols = array_diff(array_keys($payload), ['Id']);
                            $sets = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
                            $db->execute("UPDATE GestionMateriel SET $sets WHERE Id = :Id", $payload);
                        } else {
                            $cols = array_keys($payload);
                            $colsList = implode(',', $cols);
                            $valsList = ':' . implode(', :', $cols);
                            $db->execute("INSERT INTO GestionMateriel ($colsList) VALUES ($valsList)", $payload);
                            $comptaCreated++;
                        }
                        if ($spCompta) { try { $pdoConn->exec("RELEASE SAVEPOINT $spCompta"); } catch(\Throwable $_) {} }
                    } catch (\Throwable $e) {
                        if ($spCompta) { try { $pdoConn->exec("ROLLBACK TO SAVEPOINT $spCompta"); $pdoConn->exec("RELEASE SAVEPOINT $spCompta"); } catch(\Throwable $_) {} }
                        $errors[] = ['row' => $b['_row'] ?? '?', 'message' => 'Compta : ' . $e->getMessage()];
                    }
                }
            }
            // Tout s'est bien passé pour cette ligne : release le savepoint
            if ($spName) { try { $pdoConn->exec("RELEASE SAVEPOINT $spName"); } catch(\Throwable $_) {} }
        } catch (\Throwable $e) {
            // Erreur sur la ligne : rollback CE savepoint (la transaction reste valide)
            if ($spName) {
                try {
                    $pdoConn->exec("ROLLBACK TO SAVEPOINT $spName");
                    $pdoConn->exec("RELEASE SAVEPOINT $spName");
                } catch(\Throwable $_) {}
            }
            $failed++;
            // Convertir le message d'erreur technique en message lisible
            $msg = $e->getMessage();
            if (str_contains($msg, '23505') || str_contains($msg, 'duplicate key')) {
                $msg = 'Numéro déjà utilisé en base : ' . ($itemData['numero'] ?? '?');
            } elseif (str_contains($msg, '22007') || str_contains($msg, 'invalid input syntax for type date')) {
                $msg = 'Date invalide (format attendu : AAAA-MM-JJ ou JJ/MM/AAAA)';
            } elseif (str_contains($msg, '22P02') || str_contains($msg, 'invalid input syntax')) {
                $msg = 'Format de valeur invalide (probablement un nombre ou une date mal formaté)';
            } elseif (str_contains($msg, '23502') || str_contains($msg, 'not-null')) {
                $msg = 'Champ obligatoire vide en base';
            } elseif (str_contains($msg, '22001') || str_contains($msg, 'value too long')) {
                $msg = 'Valeur trop longue pour la colonne';
            } elseif (str_contains($msg, '25P02') || str_contains($msg, 'transaction is aborted')) {
                $msg = 'Transaction PostgreSQL annulée par une erreur précédente — voir les lignes au-dessus';
            } else {
                if (strlen($msg) > 200) $msg = substr($msg, 0, 200) . '…';
            }
            $errors[] = ['row' => $b['_row'] ?? '?', 'numero' => $itemData['numero'] ?? '', 'message' => $msg];
        }
    }

    if ($pdoConn && $pdoConn->inTransaction()) {
        try { $pdoConn->commit(); } catch(\Throwable $e) { try { $pdoConn->rollBack(); } catch(\Throwable $e2) {} }
    }

    json_ok([
        'created'        => $created,
        'updated'        => $updated,
        'failed'         => $failed,
        'comptaCreated'  => $comptaCreated,
        'listesAdded'    => $listesAdded,
        'errors'         => array_slice($errors, 0, 200),
        'totalProcessed' => $created + $updated + $failed,
        'duplicatesSuffixed' => $duplicatesSuffixed,
    ]);
}
