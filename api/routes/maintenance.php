<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Maintenance
 *
 * Actions : interventions (CRUD), interv_autonumero, valider_intervention,
 *           archiver_intervention, contrats (CRUD)
 */

// ── Interventions ─────────────────────────────────────────────────────────────
if ($action === 'interv_autonumero' && $method === 'GET') {
    require_auth();
    json_ok(['numero' => $db->generateInterventionNumero()]);
}

if ($action === 'modifier_date_prevue' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $db->modifierDateInterventionPrevue($id, $body['nouvelleDate'] ?? '', $body['qui'] ?? '', $body['raison'] ?? '');
    json_ok('OK');
}

if ($action === 'realiser_prevue' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $db->realiserInterventionPrevue($id, $user['Login']);
    json_ok('OK');
}

if ($action === 'interventions_prevues' && $method === 'GET') {
    require_auth();
    json_ok($db->getInterventionsPrevues());
}

if ($action === 'valider_intervention' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $commentaire     = trim((string)($body['commentaire']     ?? ''));
    $cloturerDemande = !empty($body['cloturer_demande']);

    // ── Pré-requis : les champs CBDC/CHMA + dates doivent déjà être renseignés
    //    sur l'intervention (saisis via le formulaire d'édition), SAUF si la
    //    case « pas de suivi administratif » a été cochée. On garde-fou côté
    //    serveur pour empêcher une clôture si le client a contourné le bouton
    //    (qui est lui-même masqué tant que ces champs sont vides).
    $interv = $db->getInterventionById($id);
    if (!$interv) json_error('Intervention introuvable.', 404);

    $typeBudget    = trim((string)($interv['TypeBudget']      ?? ''));   // CBDC / CHMA / autre
    $dateRea       = trim((string)($interv['DateRealisation'] ?? ''));
    $dateEnvoiMail = trim((string)($interv['DateEnvoiMail']   ?? ''));

    // Dispense : la case « Pas de suivi administratif » a été cochée sur la
    // fiche (intervention sans bon de commande, sans mail prestataire…). Dans
    // ce cas on n'exige aucun des 3 champs ci-dessus.
    $sansSuiviAdmin = !empty($interv['SansSuiviAdmin']);

    if (!$sansSuiviAdmin) {
        $manquants = [];
        if ($typeBudget    === '') $manquants[] = 'CBDC / CHMA';
        if ($dateRea       === '') $manquants[] = 'Date de réalisation';
        if ($dateEnvoiMail === '') $manquants[] = 'Date envoi mail';
        if ($manquants) {
            json_error('Clôture impossible : champs manquants sur l\'intervention — ' . implode(', ', $manquants) . '. Éditez l\'intervention pour les renseigner, ou cochez « Pas de suivi administratif » si elle n\'en nécessite pas.');
        }
    }

    // 1) Marquer l'intervention comme Validée (= clôturée par le gestionnaire)
    $db->validerIntervention($id);

    // 2) Si un commentaire est fourni, le concaténer au champ Commentaire
    //    existant de l'intervention (sans écraser un éventuel commentaire saisi
    //    lors de l'édition). Format : ligne datée + login + message.
    if ($commentaire !== '') {
        $existant = trim((string)($interv['Commentaire'] ?? ''));
        $ligne = '[Clôture ' . date('d/m/Y H:i') . ' — ' . ($user['Login'] ?? '?') . '] ' . $commentaire;
        $nouveau = $existant === '' ? $ligne : ($existant . "\n\n" . $ligne);
        $db->execute(
            "UPDATE Interventions SET Commentaire=:c, UpdatedAt=:dt, UpdatedBy=:by WHERE Id=:id",
            ['c' => $nouveau, 'dt' => date('Y-m-d H:i:s'), 'by' => $user['Login'], 'id' => $id]
        );
    }

    // 3) Si l'intervention est liée à une demande et que le client a demandé
    //    la clôture conjointe, on passe la demande en "Traité" avec le même
    //    commentaire visible côté demandeur (CommentaireAdmin).
    $demandeCloturee = false;
    if ($cloturerDemande) {
        $demandeId = (int)($interv['DemandeId'] ?? 0);
        if ($demandeId > 0) {
            // On ne ré-clôture pas une demande déjà Traitée/Refusée
            $row = $db->fetchOne("SELECT Statut, CommentaireAdmin FROM DemandesIntervention WHERE Id=:id", ['id' => $demandeId]);
            if ($row && !in_array($row['Statut'] ?? '', ['Traité','Refusé'], true)) {
                // On préserve un éventuel commentaire admin existant
                $existant = trim((string)($row['CommentaireAdmin'] ?? ''));
                $msg = $commentaire !== ''
                    ? $commentaire
                    : 'Intervention terminée.';
                // Référence au bon pour traçabilité côté demandeur. Sur une
                // intervention dispensée de suivi administratif, il n'y a pas
                // de bon à citer : on n'ajoute rien plutôt qu'un « (envoyé le ) ».
                $refBon = '';
                if ($typeBudget !== '') {
                    $refBon = $typeBudget . ($dateEnvoiMail !== '' ? ' (envoyé le ' . $dateEnvoiMail . ')' : '');
                }
                $entete = '[Clôture ' . date('d/m/Y H:i') . ' — ' . ($user['Login'] ?? '?')
                        . ($refBon !== '' ? ' — ' . $refBon : '') . ']';
                $ligne = $entete . ' ' . $msg;
                $nouveau = $existant === '' ? $ligne : ($existant . "\n\n" . $ligne);
                $db->execute(
                    "UPDATE DemandesIntervention SET Statut='Traité', CommentaireAdmin=:c, DateTraitement=:dt WHERE Id=:id",
                    ['c' => $nouveau, 'dt' => date('Y-m-d H:i:s'), 'id' => $demandeId]
                );
                $demandeCloturee = true;
            }
        }
    }

    json_ok(['demandeCloturee' => $demandeCloturee]);
}

// Prise en charge : passe l'intervention en "En cours" et remplit l'agent avec l'utilisateur courant
if ($action === 'prise_en_charge_intervention' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $interv = $db->getInterventionById($id);
    if (!$interv) json_error('Intervention introuvable.', 404);

    $nomAgent    = trim($user['Nom'] ?? '');
    $prenomAgent = trim($user['Prenom'] ?? '');
    $telAgent    = trim($user['TelMobile'] ?? $user['TelPro'] ?? $user['Tel'] ?? '');
    $emailAgent  = trim($user['Email'] ?? '');

    $db->execute(
        "UPDATE Interventions SET AgentNom = :nom, AgentPrenom = :prenom, AgentTel = :tel, AgentEmail = :email, UpdatedAt = :dt, UpdatedBy = :by WHERE Id = :id",
        [
            'nom'    => $nomAgent,
            'prenom' => $prenomAgent,
            'tel'    => $telAgent,
            'email'  => $emailAgent,
            'dt'     => date('Y-m-d H:i:s'),
            'by'     => $user['Login'],
            'id'     => $id,
        ]
    );

    // Si liée à une demande, passer la demande en "En cours"
    if (!empty($interv['DemandeId'])) {
        $db->execute("UPDATE DemandesIntervention SET Statut='En cours' WHERE Id=:id AND Statut='Nouveau'", ['id' => (int)$interv['DemandeId']]);
    }

    json_ok('OK');
}

// ── Déléguer une intervention à un autre agent (avec historique) ─────────────
if ($action === 'deleguer_intervention' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $interv = $db->getInterventionById($id);
    if (!$interv) json_error('Intervention introuvable.', 404);

    $body = get_body();
    $nouveauNom    = trim($body['agentNom'] ?? '');
    $nouveauPrenom = trim($body['agentPrenom'] ?? '');
    $nouveauTel    = trim($body['agentTel'] ?? '');
    $nouveauEmail  = trim($body['agentEmail'] ?? '');
    $motif         = trim($body['motif'] ?? '');

    if (!$nouveauNom) json_error('Le nom du nouvel agent est obligatoire.');

    // Historique dans le commentaire
    $ancienAgent = trim(($interv['AgentPrenom'] ?? '') . ' ' . ($interv['AgentNom'] ?? ''));
    $nouveauAgent = trim("$nouveauPrenom $nouveauNom");
    $parQui = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
    $dateDelegation = date('d/m/Y H:i');

    $histoEntry = "[Délégation $dateDelegation] $ancienAgent → $nouveauAgent par $parQui" . ($motif ? " — Motif : $motif" : '');
    $commentaire = trim(($interv['Commentaire'] ?? '') . "\n" . $histoEntry);

    $db->execute(
        "UPDATE Interventions SET AgentNom=:nom, AgentPrenom=:prenom, AgentTel=:tel, AgentEmail=:email, Commentaire=:com, UpdatedAt=:dt, UpdatedBy=:by WHERE Id=:id",
        [
            'nom'    => $nouveauNom,
            'prenom' => $nouveauPrenom,
            'tel'    => $nouveauTel,
            'email'  => $nouveauEmail,
            'com'    => $commentaire,
            'dt'     => date('Y-m-d H:i:s'),
            'by'     => $user['Login'],
            'id'     => $id,
        ]
    );

    json_ok(['message' => "Intervention déléguée à $nouveauAgent."]);
}

// ── Délaisser une intervention (retirer la prise en charge) ─────────────────
if ($action === 'delaisser_intervention' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $interv = $db->getInterventionById($id);
    if (!$interv) json_error('Intervention introuvable.', 404);

    $ancienAgent = trim(($interv['AgentPrenom'] ?? '') . ' ' . ($interv['AgentNom'] ?? ''));
    $parQui = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
    $dateDel = date('d/m/Y H:i');

    $histoEntry = "[Délaissée $dateDel] $ancienAgent a été retiré par $parQui";
    $commentaire = trim(($interv['Commentaire'] ?? '') . "\n" . $histoEntry);

    // On retire l'agent mais on NE CHANGE PAS le statut : si l'interv est "En cours",
    // "Planifiée", "Validée"…, elle reste dans ce statut. L'historique reste dans le commentaire.
    $db->execute(
        "UPDATE Interventions SET AgentNom='', AgentPrenom='', AgentTel='', AgentEmail='', Commentaire=:com, UpdatedAt=:dt, UpdatedBy=:by WHERE Id=:id",
        [
            'com' => $commentaire,
            'dt'  => date('Y-m-d H:i:s'),
            'by'  => $user['Login'],
            'id'  => $id,
        ]
    );

    json_ok('OK');
}

if ($action === 'archiver_intervention' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $db->archiverIntervention($id);
    json_ok('OK');
}

if ($action === 'interventions_archivees' && $method === 'GET') {
    require_auth();
    json_ok($db->getInterventionsArchivees());
}

if ($action === 'interventions') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'interventions', $db);
    if ($method==='GET') {
        // ⚠️ FIX PERFS : pagination opt-in. Si le client envoie ?paginated=1,
        // on retourne {items, total, limit, offset} ; sinon, comportement
        // legacy (toute la liste) — pour ne pas casser le frontend existant.
        if (!empty($_GET['paginated'])) {
            $limit   = isset($_GET['limit'])  ? (int)$_GET['limit']  : 100;
            $offset  = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $filters = [];
            if (!empty($_GET['statut']))    $filters['statut']    = $_GET['statut'];
            if (!empty($_GET['assignee']))  $filters['assignee']  = (int)$_GET['assignee'];
            if (!empty($_GET['bien_id']))   $filters['bien_id']   = (int)$_GET['bien_id'];
            json_ok($db->getInterventionsPaginated($limit, $offset, $filters));
        } else {
            json_ok($db->getAllInterventions());
        }
    }
    if ($method==='POST')   json_ok(['id' => $db->addIntervention(get_body(), $user['Login'])]);
    if ($method==='PUT')    { $db->updateIntervention($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') {
        // Raison optionnelle de la suppression (saisie côté UI). Comme la
        // ligne est physiquement supprimée, on garde une trace dans le log
        // applicatif (PHP error_log → généralement /var/log/php-fpm/error.log
        // ou stderr selon SAPI). Ça suffit pour répondre à un futur "pourquoi
        // cette intervention a-t-elle été supprimée ?" sans introduire une
        // table d'historique dédiée.
        $body = get_body();
        $raison = trim((string)($body['raison'] ?? ''));
        if ($raison !== '') {
            $interv = $db->getInterventionById($id);
            $numero = $interv['Numero'] ?? ('#' . $id);
            error_log(sprintf(
                '[INTERV-DELETE] %s | id=%d numero=%s par=%s | raison: %s',
                date('Y-m-d H:i:s'),
                $id,
                $numero,
                $user['Login'] ?? '?',
                substr($raison, 0, 500)
            ));
        }
        $db->deleteIntervention($id, $user['Login']);
        json_ok('OK');
    }
}

// ── Devis interventions ──────────────────────────────────────────────────────
if ($action === 'interv_devis') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $intervId = isset($_GET['interv_id']) ? (int)$_GET['interv_id'] : 0;
    if ($method==='GET')    json_ok($db->getDevisIntervention($intervId));
    if ($method==='POST')   json_ok(['id' => $db->addDevisIntervention($intervId, get_body())]);
    if ($method==='PUT')    { $db->updateDevisIntervention($id, get_body()); json_ok('OK'); }
    if ($method==='DELETE') { $db->deleteDevisIntervention($id); json_ok('OK'); }
}

// ── Factures (regroupement d'interventions) ──────────────────────────────────
// Une facture (CBDC/CHMA, n° EJ, date EJ, montant) peut regrouper plusieurs
// interventions. Le rattachement se pilote depuis la fiche intervention via
// l'action interv_factures (cf. plus bas).
if ($action === 'facture_autonumero' && $method === 'GET') {
    require_auth();
    json_ok(['numero' => $db->generateFactureNumero()]);
}

if ($action === 'factures') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method==='GET')    json_ok($db->getAllFactures());
    if ($method==='POST')   { require_role($user, ['Admin','Gestionnaire']); json_ok(['id' => $db->addFacture(get_body(), $user['Login'])]); }
    if ($method==='PUT')    { require_role($user, ['Admin','Gestionnaire']); $db->updateFacture($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteFacture($id, $user['Login']); json_ok('OK'); }
}

// Interventions rattachées à une facture (vue détail / regroupement)
if ($action === 'facture_interventions' && $method === 'GET') {
    require_auth();
    $factureId = isset($_GET['facture_id']) ? (int)$_GET['facture_id'] : 0;
    json_ok($db->getInterventionsForFacture($factureId));
}

// ── Rattachement facture ↔ intervention (depuis la fiche intervention) ───────
if ($action === 'interv_factures') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $intervId = isset($_GET['interv_id']) ? (int)$_GET['interv_id'] : 0;
    if ($method==='GET')    json_ok($db->getFacturesIntervention($intervId));
    if ($method==='POST')   {
        $body = get_body();
        // Trace l'auteur pour la facture créée à la volée (champ technique).
        $body['__login'] = $user['Login'] ?? '';
        json_ok(['id' => $db->attachFactureToIntervention($intervId, $body)]);
    }
    if ($method==='PUT')    { if ($id === null) json_error('Identifiant de liaison manquant.'); $db->updateFactureLigne($id, get_body()); json_ok('OK'); }
    if ($method==='DELETE') { if ($id === null) json_error('Identifiant de liaison manquant.'); $db->detachFactureLigne($id); json_ok('OK'); }
}

// Lier une facture (existante OU nouvelle) à une intervention CHOISIE — qui peut
// être la fiche courante ou une autre. Couvre les deux usages du bouton
// « Ajouter une intervention » : faire rejoindre cette intervention à une
// facturation existante, ou rattacher une autre intervention à une facturation
// de cette fiche.
if ($action === 'facture_link' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $body['__login'] = $user['Login'] ?? '';
    $targetInterv = (int)($body['interventionId'] ?? 0);
    if ($targetInterv <= 0) json_error('Intervention cible manquante.');
    json_ok(['id' => $db->attachFactureToIntervention($targetInterv, $body)]);
}

// ── Contrats ──────────────────────────────────────────────────────────────────
if ($action === 'contrats') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'contrats', $db);
    if ($method==='GET')    json_ok($db->getAllContrats());
    // ⚠️ CORRECTIF SÉCURITÉ : POST et PUT s'exécutaient juste après la garde de
    // LECTURE, qui admet le Visionneur — un compte en consultation pouvait donc
    // créer et modifier des contrats. Seul DELETE re-vérifiait. La garde
    // d'écriture est désormais posée avant toute modification, comme sur les
    // autres routes (biens, équipements, stock).
    require_role($user, ['Admin','Gestionnaire']);
    if ($method==='POST')   json_ok(['id' => $db->addContrat(get_body(), $user['Login'])]);
    if ($method==='PUT')    { $db->updateContrat($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { $db->deleteContrat($id); json_ok('OK'); }
}
