<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Droits sur les documents joints (fichiers stockés et liens SharePoint).
 *
 * ⚠️ LES DOCUMENTS N'AVAIENT AUCUN CONTRÔLE D'ACCÈS PAR ENTITÉ.
 *
 * Les routes « documents », « document_download », « document_preview » et
 * celles des raccourcis SharePoint n'exigeaient qu'une session. N'importe quel
 * compte — un demandeur compris — pouvait donc lister et télécharger les pièces
 * d'un contrat ou d'une intervention en énumérant les identifiants, déposer un
 * fichier sur n'importe quelle fiche, et SUPPRIMER n'importe quel document (un
 * visionneur, rôle en lecture seule, le pouvait aussi).
 *
 * Règle appliquée ici, alignée sur celle des écrans qui portent les documents :
 *
 *   LECTURE — ce que l'utilisateur peut déjà lire sur la fiche elle-même :
 *     • Admin / Gestionnaire : tout ;
 *     • Visionneur : tout type connu (il consulte tous ces écrans) ;
 *     • Demandeur : ses propres demandes, et les fiches des onglets qui lui ont
 *       été ouverts nominativement (AccesLecture) — comme require_lecture().
 *
 *   ÉCRITURE (dépôt, lien SharePoint, suppression) :
 *     • Admin / Gestionnaire : tout ;
 *     • l'auteur d'une demande, sur les photos de SA demande (c'est ainsi
 *       qu'un demandeur joint une photo à sa déclaration) ;
 *     • personne d'autre — un onglet ouvert en consultation ne donne jamais le
 *       droit d'écrire.
 *
 * Un type d'entité inconnu reste réservé à la gestion : un nouveau type ajouté
 * sans règle ici est fermé par défaut, jamais ouvert.
 */

/**
 * Onglet(s) de l'application qui portent chaque type d'entité documentée —
 * mêmes clés que les require_lecture() des routes correspondantes.
 */
function doc_modules_de(string $type): ?array {
    return [
        'Bien'           => ['biens'],
        'Equipement'     => ['equipements'],
        'Stock'          => ['stock'],
        'Contrat'        => ['contrats'],
        'Intervention'   => ['interventions'],
        'ReleverEnergie' => ['energie', 'carbone'],
    ][$type] ?? null;
}

function doc_est_gestion(array $user): bool {
    return in_array($user['Role'] ?? '', ['Admin', 'Gestionnaire'], true);
}

/** La demande $demandeId a-t-elle été déposée par $user ? */
function doc_demande_de($db, int $demandeId, array $user): bool {
    $uid = (int)($user['Id'] ?? 0);
    if ($uid <= 0 || $demandeId <= 0) return false;
    try {
        $row = $db->fetchOne("SELECT UtilisateurId FROM DemandesIntervention WHERE Id = :id", ['id' => $demandeId]);
    } catch (\Throwable $e) {
        return false; // en cas de doute, on n'accorde rien
    }
    return $row !== null && (int)($row['UtilisateurId'] ?? 0) === $uid;
}

function doc_peut_lire($db, array $user, string $type, int $eid): bool {
    if (doc_est_gestion($user)) return true;
    $role = $user['Role'] ?? '';

    if ($type === 'Demande') {
        // Le visionneur consulte toutes les demandes (getAllDemandes) ; les
        // autres ne voient que les leurs.
        return $role === 'Visionneur' || doc_demande_de($db, $eid, $user);
    }

    $modules = doc_modules_de($type);
    if ($modules === null) return false;
    if ($role === 'Visionneur') return true;
    if ($role === 'Demandeur' && function_exists('demandeur_modules_lecture')) {
        return (bool) array_intersect($modules, demandeur_modules_lecture($db, $user));
    }
    return false;
}

function doc_peut_ecrire($db, array $user, string $type, int $eid): bool {
    if (doc_est_gestion($user)) return true;
    // Photos d'une demande : son auteur, quel que soit son rôle (un visionneur
    // peut lui aussi déposer une demande).
    if ($type === 'Demande') return doc_demande_de($db, $eid, $user);
    return false;
}

/** Entité à laquelle un document est rattaché, ou null s'il n'existe pas. */
function doc_entite($db, int $docId): ?array {
    if ($docId <= 0) return null;
    $row = $db->fetchOne("SELECT EntiteType, EntiteId FROM Documents WHERE Id = :id", ['id' => $docId]);
    if (!$row) return null;
    return [
        'type' => (string)($row['EntiteType'] ?? $row['entitetype'] ?? ''),
        'id'   => (int)($row['EntiteId'] ?? $row['entiteid'] ?? 0),
    ];
}

/**
 * Coupe la requête si le document ne peut pas être LU.
 *
 * Réponse 404, identique à celle d'un document inexistant : un refus 403
 * confirmerait qu'un identifiant existe, et permettrait de cartographier la
 * base en énumérant les numéros.
 */
function doc_exiger_lecture_document($db, array $user, int $docId): array {
    $ent = doc_entite($db, $docId);
    if ($ent === null || !doc_peut_lire($db, $user, $ent['type'], $ent['id'])) {
        json_error('Document introuvable.', 404);
    }
    return $ent;
}

/** Coupe la requête si le document ne peut pas être MODIFIÉ ou supprimé. */
function doc_exiger_ecriture_document($db, array $user, int $docId): array {
    $ent = doc_entite($db, $docId);
    if ($ent === null || !doc_peut_lire($db, $user, $ent['type'], $ent['id'])) {
        json_error('Document introuvable.', 404);
    }
    if (!doc_peut_ecrire($db, $user, $ent['type'], $ent['id'])) {
        json_error('Permission insuffisante.', 403);
    }
    return $ent;
}

/** Contrôle d'une fiche désignée par (type, id) : liste ou dépôt. */
function doc_exiger_entite($db, array $user, string $type, int $eid, bool $ecriture): void {
    if ($type === '' || !preg_match('/^[A-Za-z_]{1,40}$/', $type) || $eid <= 0) {
        json_error('Entité invalide.', 400);
    }
    if (!doc_peut_lire($db, $user, $type, $eid)) json_error('Permission insuffisante.', 403);
    if ($ecriture && !doc_peut_ecrire($db, $user, $type, $eid)) json_error('Permission insuffisante.', 403);
}
