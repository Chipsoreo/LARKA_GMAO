<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — site fictif pour les épreuves de l'assistant.
 *
 * Quatre bâtiments (A, B, Centre technique, Hôtel de ville), des équipements
 * placés sur plan ou non, des biens, interventions, contrats, demandes, stock,
 * documents et archives. Les dates sont RELATIVES à aujourd'hui : les alertes
 * (contrat qui expire, intervention en retard) restent vraies quel que soit le
 * jour où l'épreuve tourne.
 */

function larka_fixture_assistant(Database $db): void
{
    $pdo = $db->getPdo();
    $j = static fn(int $d): string => date('Y-m-d', strtotime(($d >= 0 ? '+' : '') . $d . ' days'));
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")";
        $pdo->prepare($sql)->execute($row);
        return (int)$pdo->lastInsertId();
    };

    // ── Listes du site (en plus des valeurs par défaut) ──────────────────
    foreach ([['Batiment', 'Centre technique', 4], ['Batiment', 'Hôtel de ville', 5],
              ['CategorieDemande', 'Chauffage / Climatisation', 5], ['CategorieDemande', 'Sécurité incendie', 6],
              ['CategorieDemande', 'Menuiserie', 7], ['CategorieDemande', 'Nettoyage', 8]] as [$c, $v, $o]) {
        $ins('Listes', ['Categorie' => $c, 'Valeur' => $v, 'Ordre' => $o]);
    }

    // ── Utilisateurs ─────────────────────────────────────────────────────
    $u = [];
    foreach ([
        ['smartin', 'Martin', 'Sophie', 'Gestionnaire', 'Service technique', 'Responsable maintenance', '01 23 45 67 89', 'sophie.martin@ville.test'],
        ['kbenali', 'Benali', 'Karim', 'Gestionnaire', 'Patrimoine', 'Gestionnaire bâtiments', '01 23 45 67 90', 'karim.benali@ville.test'],
        ['lmoreau', 'Moreau', 'Luc', 'Technicien', 'Service technique', 'Électricien', '01 23 45 67 91', 'luc.moreau@ville.test'],
        ['jpetit', 'Petit', 'Julie', 'Technicien', 'Service technique', 'Plombière', '01 23 45 67 92', 'julie.petit@ville.test'],
        ['pdurand', 'Durand', 'Paul', 'Demandeur', 'Ressources Humaines', 'Assistant RH', '', 'paul.durand@ville.test'],
        ['eleroy', 'Leroy', 'Emma', 'Demandeur', 'Finances', 'Comptable', '', 'emma.leroy@ville.test'],
    ] as [$login, $nom, $prenom, $role, $service, $poste, $tel, $mail]) {
        $u[$login] = $ins('Utilisateurs', ['Nom' => $nom, 'Prenom' => $prenom, 'Login' => $login, 'MotDePasse' => 'x',
            'Role' => $role, 'Service' => $service, 'Poste' => $poste, 'TelPro' => $tel, 'Email' => $mail,
            'DateCreation' => $j(-400), 'Actif' => 1]);
    }

    // ── Équipements ──────────────────────────────────────────────────────
    $e = [];
    foreach ([
        'SSI'   => ['SSI-001', 'Centrale SSI', 'SSI', 'Detecteur incendie', 'Siemens', 'FC2020', 'Bâtiment A', 'RDC', 'Accueil'],
        'EXT1'  => ['EXT-001', 'Extincteur 1 eau pulvérisée 6L', 'SSI', 'Extincteur', 'Sicli', 'EP6', 'Centre technique', 'RDC', ''],
        'EXT2'  => ['EXT-002', 'Extincteur 2 CO2 5kg', 'SSI', 'Extincteur', 'Sicli', 'CO2-5', 'Centre technique', '1er étage', '12'],
        'EXT3'  => ['EXT-003', 'Extincteur 3 CO2 2kg', 'SSI', 'Extincteur', 'Sicli', 'CO2-2', 'Bâtiment A', '1er étage', ''],
        'EXT4'  => ['EXT-004', 'Extincteur 4 poudre 9kg', 'SSI', 'Extincteur', 'Desautel', 'P9', 'Bâtiment B', 'RDC', ''],
        'BAES1' => ['BAES-001', 'Bloc autonome éclairage de sécurité', 'Electricite', 'BAES', 'Legrand', 'URA21', 'Bâtiment A', 'RDC', ''],
        'BAES2' => ['BAES-002', 'Bloc autonome éclairage de sécurité', 'Electricite', 'BAES', 'Legrand', 'URA21', 'Bâtiment A', '1er étage', ''],
        'CTA'   => ['CTA-001', 'Centrale de traitement d\'air', 'CVC', 'Ventilation', 'Carrier', '39HQ', 'Centre technique', 'Toiture', ''],
        'CHD'   => ['CHD-001', 'Chaudière gaz à condensation', 'CVC', 'Chauffage', 'Viessmann', 'Vitodens 200', 'Hôtel de ville', 'Sous-sol', 'Chaufferie'],
        'CLIM1' => ['CLIM-001', 'Climatiseur split salle serveur', 'CVC', 'Climatisation', 'Daikin', 'FTXM35', 'Bâtiment B', '2e étage', '204'],
        'CLIM2' => ['CLIM-002', 'Climatiseur bureau direction', 'CVC', 'Climatisation', 'Daikin', 'FTXM25', 'Hôtel de ville', '1er étage', '110'],
        'TGBT'  => ['TGBT-001', 'Tableau général basse tension', 'Electricite', 'Tableau electrique', 'Schneider', 'Prisma', 'Centre technique', 'RDC', 'Local technique'],
        'ASC'   => ['ASC-001', 'Ascenseur principal', 'Ascenseur', '', 'Otis', 'Gen2', 'Hôtel de ville', 'RDC', ''],
        'POMPE' => ['POMPE-001', 'Pompe de relevage', 'Plomberie', 'Relevage', 'Grundfos', 'Unilift', 'Centre technique', 'Sous-sol', ''],
        'BALLON'=> ['ECS-001', 'Ballon eau chaude 200L', 'Plomberie', 'ECS', 'Atlantic', 'Chauffeo', 'Bâtiment A', 'RDC', 'Local ménage'],
    ] as $k => [$num, $info, $fam, $sfam, $marque, $modele, $bat, $etage, $bureau]) {
        $e[$k] = $ins('Equipements', ['Numero' => $num, 'InfoProduit' => $info, 'Famille' => $fam, 'SousFamille' => $sfam,
            'Marque' => $marque, 'Modele' => $modele, 'Batiment' => $bat, 'Etage' => $etage, 'NumeroBureau' => $bureau,
            'Etat' => 'Utilise', 'Statut' => 'Installe', 'DateInstallation' => $j(-700), 'CreatedAt' => $j(-700)]);
    }

    // ── Biens ────────────────────────────────────────────────────────────
    $b = [];
    foreach ([
        'PC1'   => ['B-0001', 'Informatique', 'Ordinateur', 'PC portable Dell Latitude 5440', 'Bâtiment A', '1er étage', '101', 'Utilise'],
        'ECR1'  => ['B-0002', 'Informatique', 'Ecran', 'Écran Dell 24 pouces', 'Bâtiment A', '1er étage', '101', 'Utilise'],
        'BUR1'  => ['B-0003', 'Mobilier', 'Bureau', 'Bureau droit 160 cm', 'Bâtiment A', '1er étage', '102', 'Utilise'],
        'FAUT'  => ['B-0004', 'Mobilier', 'Chaise', 'Fauteuil ergonomique', 'Bâtiment B', '2e étage', '204', 'Utilise'],
        'PC2'   => ['B-0005', 'Informatique', 'Ordinateur', 'PC fixe HP EliteDesk', 'Hôtel de ville', 'RDC', 'Accueil', 'Utilise'],
        'FRIGO' => ['B-0006', 'Electromenager', '', 'Réfrigérateur', 'Centre technique', 'RDC', 'Salle de pause', 'Utilise'],
        'IMP'   => ['B-0007', 'Informatique', '', 'Imprimante multifonction Canon', 'Bâtiment B', '2e étage', '205', 'Utilise'],
        'CHAISE'=> ['B-0008', 'Mobilier', 'Chaise', 'Chaise visiteur', 'Bâtiment A', 'RDC', 'Accueil', 'Utilise'],
        'ECR2'  => ['B-0009', 'Informatique', 'Ecran', 'Écran Samsung 27 pouces', 'Bâtiment B', '2e étage', '204', 'Stock'],
        'ARM'   => ['B-0010', 'Mobilier', '', 'Armoire forte', 'Hôtel de ville', 'Sous-sol', '', 'Utilise'],
    ] as $k => [$num, $fam, $sfam, $info, $bat, $etage, $bureau, $etat]) {
        $b[$k] = $ins('Biens', ['Numero' => $num, 'Famille' => $fam, 'SousFamille' => $sfam, 'InfoProduit' => $info,
            'Batiment' => $bat, 'Etage' => $etage, 'NumeroBureau' => $bureau, 'Etat' => $etat, 'Statut' => 'Livre',
            'NomPrenom' => $k === 'PC1' ? 'Paul Durand' : '', 'DateLivraison' => $j(-300), 'CreatedAt' => $j(-300)]);
    }

    // ── Plans ────────────────────────────────────────────────────────────
    $pb = [];
    foreach (['A' => 'Bâtiment A', 'CT' => 'Centre technique', 'HDV' => 'Hôtel de ville'] as $k => $nom) {
        $pb[$k] = $ins('PlanBatiments', ['Nom' => $nom, 'Adresse' => '', 'CreatedAt' => $j(-200)]);
    }
    $pe = [];
    foreach ([['A0', 'A', 'RDC', 0], ['A1', 'A', '1er étage', 1], ['CT0', 'CT', 'RDC', 0],
              ['CT1', 'CT', '1er étage', 1], ['HDV0', 'HDV', 'RDC', 0]] as [$k, $bat, $nom, $niv]) {
        $pe[$k] = $ins('PlanEtages', ['BatimentId' => $pb[$bat], 'Nom' => $nom, 'Niveau' => $niv, 'Echelle' => 0.05]);
    }
    $el = static function (string $etage, string $type, string $nom, string $calque = 'Plan', ?array $lien = null, string $coords = '[[100,100]]') use ($ins, $pe): int {
        $id = $ins('PlanElements', ['EtageId' => $pe[$etage], 'TypeElement' => $type, 'Nom' => $nom, 'Calque' => $calque, 'Coords' => $coords]);
        if ($lien) $ins('PlanLiens', ['ElementId' => $id, 'AssetType' => $lien[0], 'AssetId' => $lien[1]]);
        return $id;
    };
    $el('A0', 'point', 'Centrale SSI', 'Sécurité', ['Equipement', $e['SSI']]);
    $el('CT0', 'point', 'Extincteur 1', 'Sécurité', ['Equipement', $e['EXT1']]);
    $el('CT1', 'point', 'Extincteur 2', 'Sécurité', ['Equipement', $e['EXT2']]);
    $el('A1', 'point', 'Extincteur 3', 'Sécurité', ['Equipement', $e['EXT3']]);
    $el('A0', 'point', 'BAES 1', 'Sécurité', ['Equipement', $e['BAES1']]);
    $el('A1', 'point', 'BAES 2', 'Sécurité', ['Equipement', $e['BAES2']]);
    $el('A1', 'zone', 'Salle de réunion 101', 'Locaux', null, '[[0,0],[0,200],[300,200],[300,0]]');
    $el('CT0', 'zone', 'Local technique', 'Locaux', null, '[[0,0],[0,100],[100,100],[100,0]]');
    $el('CT0', 'point', 'TGBT', 'Électricité', ['Equipement', $e['TGBT']]);
    $el('CT0', 'trait', 'Réseau eau chaude', 'Réseaux', null, '[[0,0],[500,0]]');
    $el('A0', 'texte', 'Accueil', 'Plan');
    $el('A1', 'point', 'PC portable 101', 'Informatique', ['Bien', $b['PC1']]);
    $el('HDV0', 'zone', 'Chaufferie', 'Locaux', null, '[[0,0],[0,80],[80,80],[80,0]]');

    // ── Contrats ─────────────────────────────────────────────────────────
    $c = [];
    foreach ([
        'OTIS'  => ['CTR-001', 'Otis', 'Maintenance', 'Actif', $j(-900), $j(24), 4800, 'Maintenance des ascenseurs de l\'Hôtel de ville', 30],
        'SICLI' => ['CTR-002', 'Sicli', 'Controle reglementaire', 'Actif', $j(-270), $j(460), 1200, 'Vérification annuelle des extincteurs', 30],
        'DALKIA'=> ['CTR-003', 'Dalkia', 'Maintenance', 'Actif', $j(-1200), $j(96), 15000, 'Maintenance chauffage, ventilation et climatisation', 120],
        'ELEC'  => ['CTR-004', 'Elec Services', 'Maintenance', 'Expire', $j(-1700), $j(-270), 3000, 'Maintenance BAES et éclairage', 30],
        'PROPRE'=> ['CTR-005', 'Propreté Plus', 'Autre', 'Actif', $j(-25), $j(700), 22000, 'Nettoyage des locaux', 60],
    ] as $k => [$num, $soc, $type, $statut, $deb, $fin, $montant, $desc, $alerte]) {
        $c[$k] = $ins('Contrats', ['Numero' => $num, 'Societe' => $soc, 'Type' => $type, 'Statut' => $statut,
            'DateDebut' => $deb, 'DateFin' => $fin, 'MontantAnnuel' => $montant, 'Description' => $desc,
            'AlerteJoursAvant' => $alerte, 'ContactNom' => 'Contact ' . $soc, 'CreatedAt' => $deb]);
    }

    // ── Demandes ─────────────────────────────────────────────────────────
    $d = [];
    foreach ([
        'FUITE'  => ['Fuite d\'eau lavabo', 'Le lavabo des toilettes du rez-de-chaussée fuit.', 'Bâtiment A', 'Toilettes RDC', 'Haute', 'Nouveau', 'pdurand', -2, 'Plomberie'],
        'POIGN'  => ['Poignée de porte cassée', 'La poignée de la porte du bureau 204 est cassée.', 'Bâtiment B', 'Bureau 204', 'Normale', 'En cours', 'pdurand', -16, 'Serrurerie'],
        'CLIM'   => ['Climatisation en panne', 'La climatisation du bureau 205 ne fonctionne plus.', 'Bâtiment B', 'Bureau 205', 'Urgente', 'Traité', 'eleroy', -86, 'Chauffage / Climatisation'],
        'AMPOULE'=> ['Ampoule grillée couloir', 'Ampoule grillée dans le couloir du 1er étage.', 'Hôtel de ville', '1er étage', 'Basse', 'Nouveau', 'eleroy', -1, 'Électricité'],
        'STORE'  => ['Store bloqué', 'Le store du bureau 102 est bloqué en position basse.', 'Bâtiment A', 'Bureau 102', 'Normale', 'Refusé', 'pdurand', -134, 'Autre'],
    ] as $k => [$titre, $desc, $bat, $bureau, $urg, $statut, $login, $jours, $cat]) {
        $d[$k] = $ins('DemandesIntervention', ['Titre' => $titre, 'Description' => $desc, 'Batiment' => $bat, 'Bureau' => $bureau,
            'Urgence' => $urg, 'Statut' => $statut, 'UtilisateurId' => $u[$login], 'NomDeclarant' => $login,
            'DateCreation' => $j($jours) . ' 09:30:00', 'Categorie' => $cat]);
    }

    // ── Interventions ────────────────────────────────────────────────────
    foreach ([
        ['INT-2026-001', 'Préventive', 'Validée', 'Normale', 'Vérification annuelle des BAES du bâtiment A', $j(-200), $j(-200), 'Elec Services', null, (string)$e['BAES1'] . ',' . $e['BAES2'], null],
        ['INT-2026-002', 'Préventive', 'Planifiée', 'Normale', 'Contrôle des extincteurs (tous bâtiments)', $j(19), null, 'Sicli', $c['SICLI'], implode(',', [$e['EXT1'], $e['EXT2'], $e['EXT3'], $e['EXT4']]), null],
        ['INT-2026-003', 'Curative', 'En cours', 'Urgente', 'Fuite sur la pompe de relevage du centre technique', $j(-6), null, 'Plomberie Express', null, (string)$e['POMPE'], null],
        ['INT-2026-004', 'Contrôle réglementaire', 'Planifiée', 'Normale', 'Contrôle réglementaire quinquennal de l\'ascenseur', $j(-27), null, 'Otis', $c['OTIS'], (string)$e['ASC'], null],
        ['INT-2026-005', 'Préventive', 'Réalisée', 'Basse', 'Maintenance CTA : changement des filtres', $j(-106), $j(-106), 'Dalkia', $c['DALKIA'], (string)$e['CTA'], null],
        ['INT-2025-010', 'Curative', 'Validée', 'Normale', 'Remplacement du climatiseur du bureau de la direction', $j(-450), $j(-450), 'Dalkia', $c['DALKIA'], (string)$e['CLIM2'], null],
        ['INT-2026-006', 'Préventive', 'Planifiée', 'Normale', 'Entretien annuel de la chaudière gaz de l\'Hôtel de ville', $j(40), null, 'Dalkia', $c['DALKIA'], (string)$e['CHD'], null],
        ['INT-2026-007', 'Curative', 'Planifiée', 'Normale', 'Remplacement de la poignée de porte du bureau 204', $j(4), null, '', null, '', $d['POIGN']],
    ] as [$num, $type, $statut, $prio, $desc, $date, $real, $soc, $ctr, $eqs, $dem]) {
        $row = ['Numero' => $num, 'Type' => $type, 'Statut' => $statut, 'Priorite' => $prio, 'Description' => $desc,
            'DateIntervention' => $date, 'DateRealisation' => $real, 'SocieteManuelle' => $soc, 'ContratId' => $ctr,
            'EquipementsIds' => $eqs, 'TypeLiaison' => 'Equipement', 'CreatedAt' => $date, 'Montant' => 0];
        if ($dem) { $row['DemandeId'] = $dem; $row['AgentPrenom'] = 'Luc'; $row['AgentNom'] = 'Moreau'; }
        $ins('Interventions', $row);
    }

    // ── Stock ────────────────────────────────────────────────────────────
    foreach ([
        ['PL-001', 'Joint de robinet 1/2', 'Plomberie', 3, 10, 'Magasin CT - étagère 1', 0.5],
        ['PL-002', 'Siphon de lavabo', 'Plomberie', 12, 5, 'Magasin CT - étagère 1', 6.9],
        ['EL-001', 'Ampoule LED E27', 'Electricite', 4, 20, 'Magasin CT - étagère 2', 3.2],
        ['EL-002', 'Tube néon T8 120 cm', 'Electricite', 30, 10, 'Magasin CT - étagère 2', 4.5],
        ['FI-001', 'Filtre F7 pour CTA 592x592', 'Consommable', 8, 6, 'Magasin CT - étagère 3', 38],
        ['CO-001', 'Papier toilette (colis de 48)', 'Consommable', 0, 5, 'Réserve Bâtiment A', 21],
        ['OU-001', 'Clé à molette 250 mm', 'Outillage', 2, 1, 'Atelier', 14],
    ] as [$ref, $des, $cat, $qte, $seuil, $empl, $prix]) {
        $ins('Stock', ['Reference' => $ref, 'Designation' => $des, 'Categorie' => $cat, 'Quantite' => $qte,
            'SeuilAlerte' => $seuil, 'Emplacement' => $empl, 'PrixUnitaire' => $prix, 'Fournisseur' => 'Rexel']);
    }

    // ── Documents ────────────────────────────────────────────────────────
    foreach ([
        ['equipement', $e['SSI'], 'Notice centrale SSI Siemens FC2020.pdf', 'notice'],
        ['contrat', $c['OTIS'], 'Contrat Otis ascenseurs 2024.pdf', 'contrat'],
        ['intervention', 1, 'Rapport vérification BAES 2026.pdf', 'rapport'],
        ['bien', $b['PC1'], 'Facture PC portable Dell.pdf', 'facture'],
        ['equipement', $e['TGBT'], 'Schéma TGBT Centre technique.pdf', 'plan'],
    ] as [$type, $id, $nom, $cat]) {
        $ins('Documents', ['EntiteType' => $type, 'EntiteId' => $id, 'NomFichier' => $nom, 'TypeMime' => 'application/pdf',
            'Categorie' => $cat, 'Taille' => 1000, 'DateAjout' => $j(-50), 'AjoutePar' => 'smartin']);
    }

    // ── Archives ─────────────────────────────────────────────────────────
    $boite = $ins('ArchivesBoite', ['NumeroBoite' => 'BOITE-2019-04', 'Service' => 'Ressources Humaines',
        'Batiment' => 'Hôtel de ville', 'Emplacement' => 'Sous-sol, travée 3', 'Description' => 'Dossiers du personnel 2015-2019', 'Statut' => 'Active']);
    $ins('ArchivesDossier', ['BoiteId' => $boite, 'NumeroBoite' => 'BOITE-2019-04', 'NumeroDossier' => 'DOS-2019-017',
        'Service' => 'Ressources Humaines', 'Annee' => '2019', 'Description' => 'Recrutements 2019', 'Emplacement' => 'Sous-sol, travée 3', 'Statut' => 'Archive']);
    $ins('ArchivesDossier', ['BoiteId' => $boite, 'NumeroBoite' => 'BOITE-2019-04', 'NumeroDossier' => 'DOS-2019-018',
        'Service' => 'Ressources Humaines', 'Annee' => '2019', 'Description' => 'Formations 2019', 'Emplacement' => 'Sous-sol, travée 3', 'Statut' => 'Archive']);
}

/** Utilisateur d'essai (ligne Utilisateurs complète) par login. */
function larka_fixture_utilisateur(Database $db, string $login): array
{
    return $db->fetchOne("SELECT * FROM Utilisateurs WHERE Login = :l", ['l' => $login]) ?: [];
}
