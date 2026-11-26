<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka — import des interventions depuis Excel / CSV
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Reprendre l'historique d'une ancienne GMAO, d'un tableur de suivi ou d'un
 *  prestataire : curatives, préventives, contrôles réglementaires, divers.
 *  Le navigateur lit le fichier et associe ses colonnes aux champs ; ce
 *  fichier-ci décide de tout le reste, pour l'aperçu comme pour l'import :
 *
 *    - TYPE et STATUT reconnus sous leurs noms usuels (« préventif »,
 *      « correctif », « terminée », « clôturée »…) ; un type nouveau rejoint la
 *      liste des types (sauf refus) ; un statut inconnu prend le statut par
 *      défaut, et le rapport le dit — le statut pilote le circuit de
 *      l'intervention, on n'en invente pas ;
 *    - ÉQUIPEMENT ou BIEN retrouvé par son numéro (plusieurs équipements
 *      séparés par « ; » ou « , ») ; CONTRAT par son numéro, sa référence ou,
 *      s'il est seul, le nom de la société ;
 *    - DATES réelles seulement, MONTANTS au format français, DURÉES « 2h30 » ;
 *    - DOUBLONS : un numéro d'intervention déjà présent met à jour la fiche
 *      (ou est laissé, au choix) — et SEULS les champs lus dans le fichier
 *      sont remplacés ; sans numéro, une ligne identique à une intervention
 *      existante (même type, date, équipement, description) n'est pas
 *      recréée : réimporter le même fichier ne double rien ;
 *    - incohérences signalées (réalisée sans date de réalisation, planifiée
 *      déjà réalisée, date de réalisation dans le futur, montant négatif).
 *
 *  Une préventive ou un contrôle réglementaire importé est une intervention
 *  « hors plan » : il apparaît sur l'écran Préventif (ou Contrôle
 *  réglementaire), dans la liste Hors plan, avec son historique.
 *
 *  L'aperçu (dryRun) ne modifie rien. L'import se fait dans une transaction,
 *  ligne par ligne (une ligne en échec n'emporte pas les autres).
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

require_once __DIR__ . '/ImportOutils.php';

final class ImportInterventions
{
    public const MAX_LIGNES = 10000;

    /** Champs importables : clé => libellé (pour les messages du rapport). */
    public const CHAMPS = [
        'numero' => 'N° intervention', 'type' => 'Type', 'statut' => 'Statut', 'priorite' => 'Priorité',
        'description' => 'Description', 'actif' => 'Équipement / bien', 'contrat' => 'Contrat',
        'societeManuelle' => 'Prestataire', 'dateDemande' => 'Date de demande', 'dateIntervention' => "Date prévue d'intervention",
        'dateRealisation' => 'Date de réalisation', 'dureeHeures' => 'Durée (h)', 'montantHT' => 'Montant HT',
        'montant' => 'Montant TTC', 'commentaire' => 'Commentaire', 'agentNom' => "Nom de l'intervenant",
        'agentPrenom' => "Prénom de l'intervenant", 'agentTel' => "Téléphone de l'intervenant", 'agentEmail' => "Courriel de l'intervenant",
        'typeBudget' => 'CBDC / CHMA', 'codeNumeroDemande' => 'N° de demande / de bon', 'dateEnvoiMail' => "Date d'envoi du courriel",
        'lieu' => 'Lieu / bâtiment',
    ];
    private const DATES = ['dateDemande', 'dateIntervention', 'dateRealisation', 'dateEnvoiMail'];
    private const MONTANTS = ['montantHT', 'montant'];
    private const TEXTES = ['societeManuelle' => 200, 'commentaire' => 4000, 'agentNom' => 100, 'agentPrenom' => 100, 'agentTel' => 40,
                            'agentEmail' => 200, 'typeBudget' => 60, 'codeNumeroDemande' => 100, 'lieu' => 120];

    public const STATUTS = ['Planifiée', 'En cours', 'Réalisée', 'Validée', 'Archivée'];
    /** Natures qui ont leur écran (Préventif, Contrôle réglementaire) : jamais ajoutées à la liste des types. */
    public const NATURES = ['Préventive', 'Contrôle réglementaire'];

    /** Noms usuels → valeur de Larka (clé de comparaison sans accents). */
    private const SYNONYMES_STATUT = [
        'Planifiée' => ['planifiee', 'planifie', 'prevue', 'prevu', 'a faire', 'a planifier', 'en attente', 'programmee', 'programme',
                        'nouvelle', 'nouveau', 'ouverte', 'ouvert', 'open', 'planned', 'todo', 'a venir', 'non commencee'],
        'En cours'  => ['en cours', 'encours', 'commencee', 'demarree', 'in progress', 'en traitement', 'en intervention', 'demarre'],
        'Réalisée'  => ['realisee', 'realise', 'faite', 'fait', 'effectuee', 'effectue', 'executee', 'execute', 'done', 'intervenue'],
        'Validée'   => ['validee', 'valide', 'terminee', 'termine', 'cloturee', 'cloture', 'close', 'clos', 'fermee', 'ferme', 'closed',
                        'achevee', 'acheve', 'soldee', 'solde', 'receptionnee'],
        'Archivée'  => ['archivee', 'archive', 'archived'],
    ];
    private const SYNONYMES_TYPE = [
        'Préventive' => ['preventive', 'preventif', 'maintenance preventive', 'entretien', 'entretien preventif', 'prev', 'mp', 'visite preventive',
                         'maintenance programmee', 'maintenance systematique'],
        'Contrôle réglementaire' => ['controle reglementaire', 'controles reglementaires', 'reglementaire', 'verification periodique',
                                     'verification reglementaire', 'vgp', 'controle periodique', 'cr'],
        'Curative'   => ['curative', 'curatif', 'correctif', 'corrective', 'maintenance corrective', 'depannage', 'reparation', 'panne',
                         'mc', 'maintenance curative', 'sav'],
        'Divers'     => ['divers', 'autre', 'autres', 'other'],
    ];
    private const SYNONYMES_PRIORITE = [
        'Urgente' => ['urgente', 'urgent', 'haute', 'elevee', 'critique', 'p1', 'tres urgent', 'immediate', 'high'],
        'Normale' => ['normale', 'normal', 'moyenne', 'standard', 'p2', 'medium'],
        'Basse'   => ['basse', 'faible', 'p3', 'low', 'non urgent'],
    ];

    public function __construct(private Database $db, private string $login) {}

    /**
     * Analyse (dryRun) ou importe. $corps : items, dryRun, dedup (update|skip),
     * doublons (ignorer|importer), typeDefaut, statutDefaut, champsMappes,
     * listesNePasCreer {TypeIntervention: [...], PrioriteIntervention: [...]},
     * champsPersoNouveaux [{cle, libelle, type}] (cases à créer dans les fiches) ;
     * items[].champsPerso {cle: valeur} : valeurs des cases.
     */
    public function executer(array $corps): array
    {
        $items = $corps['items'] ?? null;
        if (!is_array($items)) throw new InvalidArgumentException('Format invalide : « items » doit être une liste de lignes.');
        if (count($items) > self::MAX_LIGNES) throw new InvalidArgumentException('Trop de lignes (' . self::MAX_LIGNES . ' au plus par import — découpez le fichier).');
        $dryRun = !empty($corps['dryRun']);
        $dedup = ($corps['dedup'] ?? 'update') === 'skip' ? 'skip' : 'update';
        $doublons = ($corps['doublons'] ?? 'ignorer') === 'importer' ? 'importer' : 'ignorer';
        $mappes = array_values(array_intersect(array_keys(self::CHAMPS), array_filter((array)($corps['champsMappes'] ?? array_keys(self::CHAMPS)), 'is_string')));
        $nePasCreer = [];
        foreach ((array)($corps['listesNePasCreer'] ?? []) as $cat => $vals) foreach ((array)$vals as $v) $nePasCreer[(string)$cat][ImportOutils::cle($v)] = true;

        // Cases complémentaires que l'import crée dans les fiches (celles qui manquent).
        // Même contrôle qu'à l'import des biens : clé en vocabulaire fermé
        // [a-z0-9_], libellé borné, type connu — le reste est ignoré.
        $nouvellesCases = [];
        foreach ((array)($corps['champsPersoNouveaux'] ?? []) as $c) {
            if (!is_array($c) || !preg_match('/^[a-z0-9_]{1,60}$/', (string)($c['cle'] ?? '')) || trim((string)($c['libelle'] ?? '')) === '') continue;
            $nouvellesCases[] = ['cle' => (string)$c['cle'], 'libelle' => mb_substr(trim((string)$c['libelle']), 0, 80),
                                 'type' => in_array($c['type'] ?? '', Database::CHAMPS_PERSO_TYPES, true) ? $c['type'] : 'texte'];
            if (count($nouvellesCases) >= 100) break;
        }
        $clesExistantes = array_column($this->db->getChampsPersoDefs('interventions'), 'cle');
        $casesACreer = array_values(array_filter($nouvellesCases, fn($c) => !in_array($c['cle'], $clesExistantes, true)));

        $ref = $this->referentiel();
        $typeDefaut = $this->type((string)($corps['typeDefaut'] ?? ''), $ref)[0] ?: 'Curative';
        $statutDefaut = in_array($corps['statutDefaut'] ?? '', self::STATUTS, true) ? $corps['statutDefaut'] : 'auto';

        $lignes = [];
        $listes = ['TypeIntervention' => [], 'PrioriteIntervention' => []];
        $vusNumero = []; $vusEmpreinte = [];
        foreach (array_values($items) as $k => $b) {
            if (!is_array($b)) continue;
            $l = $this->analyserLigne($b, $k, $ref, $typeDefaut, $statutDefaut, $mappes);
            // Une fiche sans lieu ne reçoit qu'UN lieu : « Extincteurs » déjà là sans lieu,
            // et le fichier le donne à Bordeaux ET à Pau le même jour → Bordeaux complète
            // la fiche, Pau devient un passage (sinon Pau disparaissait sans bruit).
            if ($l['action'] === 'lieu') {
                if (isset($this->lieuxPris[$l['completer']])) {
                    $l['action'] = 'creer'; $l['completer'] = null; $l['descriptionPropre'] = null;
                    $l['avert'] = array_values(array_filter($l['avert'], fn($a) => ($a['champ'] ?? '') !== 'Lieu / bâtiment'));
                } else {
                    $this->lieuxPris[$l['completer']] = true;
                }
            }
            // Doublons dans le fichier lui-même
            if (!$l['erreurs']) {
                $num = ImportOutils::cle($l['donnees']['numero'] ?? '');
                if ($num !== '') {
                    if (isset($vusNumero[$num])) {
                        $l['erreurs'][] = ['champ' => 'N° intervention', 'message' => "numéro déjà présent ligne {$vusNumero[$num]} du fichier : ligne ignorée"];
                    } else $vusNumero[$num] = $l['row'];
                } elseif ($doublons === 'ignorer') {
                    // Même passage à deux endroits (deux feuilles, deux sites) : pas un doublon.
                    $e = $l['empreinte'] . '|' . ImportOutils::cle($l['donnees']['lieu'] ?? '');
                    if (isset($vusEmpreinte[$e])) {
                        $l['action'] = 'doublon';
                        $l['avert'][] = ['champ' => '—', 'valeur' => '', 'message' => "identique à la ligne {$vusEmpreinte[$e]} du fichier : ignorée"];
                    } else $vusEmpreinte[$e] = $l['row'];
                }
            }
            if ($l['erreurs']) $l['action'] = 'erreur';
            elseif ($l['action'] === 'maj' && $dedup === 'skip') $l['action'] = 'laisse';
            elseif ($l['action'] === 'lieu' && $doublons === 'importer') $l['action'] = 'creer';
            elseif ($l['action'] === 'doublon' && $doublons === 'importer') $l['action'] = 'creer';
            $lignes[] = $l;
        }
        // Nouvelles valeurs de liste (types, priorités) des lignes importées : proposées, sauf refus.
        foreach ($lignes as $l) {
            if (!in_array($l['action'], ['creer', 'maj'], true)) continue;
            foreach (['TypeIntervention' => 'type', 'PrioriteIntervention' => 'priorite'] as $cat => $champ) {
                $v = (string)($l['donnees'][$champ] ?? '');
                if ($v === '' || empty($l['nouveau_' . $champ]) || !empty($nePasCreer[$cat][ImportOutils::cle($v)])) continue;
                $listes[$cat][ImportOutils::cle($v)] = $v;
            }
        }
        $listesOut = array_filter(array_map('array_values', $listes));

        $compte = static fn(string $a) => count(array_filter($lignes, fn($l) => $l['action'] === $a));
        $rapport = [
            'aCreer' => $compte('creer'), 'aMettreAJour' => $compte('maj') + $compte('lieu'), 'lieuxAjoutes' => $compte('lieu'), 'laissees' => $compte('laisse'),
            'doublons' => $compte('doublon'), 'enErreur' => $compte('erreur'), 'total' => count($lignes),
            'listesACreer' => $listesOut,
            'casesACreer' => array_column($casesACreer, 'libelle'),
            // Une case créée qui ressemble à une case existante ou retirée (faute de frappe, ancien nom).
            'casesRessemblances' => $casesACreer ? $this->db->champsPersoRessemblances('interventions', array_merge($this->db->getChampsPersoDefs('interventions'), $casesACreer)) : [],
            'parType' => array_count_values(array_map(fn($l) => (string)($l['donnees']['type'] ?? ''), array_filter($lignes, fn($l) => in_array($l['action'], ['creer', 'maj'], true)))),
            'lignes' => array_map(fn($l) => ['row' => $l['row'], 'action' => $l['action'], 'numero' => $l['donnees']['numero'] ?? '',
                'type' => $l['donnees']['type'] ?? '', 'statut' => $l['donnees']['statut'] ?? '', 'description' => mb_substr((string)($l['donnees']['description'] ?? ''), 0, 120),
                'actif' => $l['actifLibelle'], 'date' => $l['donnees']['dateRealisation'] ?: ($l['donnees']['dateIntervention'] ?: ''),
                'erreurs' => $l['erreurs'], 'avert' => $l['avert']], $lignes),
        ];
        if ($dryRun) return $rapport;

        return $this->importer($lignes, $listesOut, $mappes, $casesACreer) + ['listesACreer' => $listesOut, 'total' => count($lignes)];
    }

    // ══ Référentiel : ce qui existe déjà ═══════════════════════════════════

    private function referentiel(): array
    {
        $r = ['equip' => [], 'bien' => [], 'contratNum' => [], 'contratRef' => [], 'contratSoc' => [], 'interv' => [], 'empreintes' => [],
              'types' => [], 'priorites' => []];
        foreach ($this->db->fetchAll("SELECT Id, Numero FROM Equipements") as $e) if (trim((string)$e['Numero']) !== '') $r['equip'][ImportOutils::cle($e['Numero'])] = [(int)$e['Id'], (string)$e['Numero']];
        foreach ($this->db->fetchAll("SELECT Id, Numero FROM Biens") as $e) if (trim((string)$e['Numero']) !== '') $r['bien'][ImportOutils::cle($e['Numero'])] = [(int)$e['Id'], (string)$e['Numero']];
        try { $contrats = $this->db->fetchAll("SELECT Id, Numero, Societe, ReferenceContrat FROM Contrats"); }
        catch (\Throwable $e) { $contrats = $this->db->fetchAll("SELECT Id, Numero, Societe FROM Contrats"); }
        foreach ($contrats as $c) {
            if (trim((string)$c['Numero']) !== '') $r['contratNum'][ImportOutils::cle($c['Numero'])] = (int)$c['Id'];
            if (trim((string)($c['ReferenceContrat'] ?? '')) !== '') $r['contratRef'][ImportOutils::cle($c['ReferenceContrat'])] = (int)$c['Id'];
            if (trim((string)$c['Societe']) !== '') $r['contratSoc'][ImportOutils::cle($c['Societe'])][] = (int)$c['Id'];
        }
        foreach ($this->db->fetchAll("SELECT * FROM Interventions") as $i) {
            if (trim((string)$i['Numero']) !== '') $r['interv'][ImportOutils::cle($i['Numero'])] = $i;
            // Plusieurs fiches par empreinte : une par lieu (le même contrôle sur deux sites).
            $r['empreintes'][self::empreinte((string)$i['Type'], (string)($i['DateRealisation'] ?: $i['DateIntervention']),
                (string)$i['EquipementsIds'], (int)($i['BienId'] ?? 0), (string)$i['Description'])][] =
                ['ref' => (string)($i['Numero'] ?: '#' . $i['Id']), 'id' => (int)$i['Id'], 'lieu' => ImportOutils::cle((string)($i['Lieu'] ?? ''))];
        }
        foreach ($this->db->getListe('TypeIntervention') as $v) $r['types'][ImportOutils::cle($v['Valeur'])] = $v['Valeur'];
        foreach ($this->db->getListe('PrioriteIntervention') as $v) $r['priorites'][ImportOutils::cle($v['Valeur'])] = $v['Valeur'];
        return $r;
    }

    /** Même intervention, d'après ce qu'elle dit d'elle-même (pas de numéro) : type, jour, équipement, description. */
    private static function empreinte(string $type, string $date, string $equips, int $bien, string $desc): string
    {
        $eq = array_filter(array_map('intval', explode(',', $equips)));
        sort($eq);
        return implode('|', [ImportOutils::cle($type), substr($date, 0, 10), implode(',', $eq), $bien ?: '', mb_substr(ImportOutils::cle($desc), 0, 120)]);
    }

    // ══ Valeurs ════════════════════════════════════════════════════════════

    /** [valeur Larka, est-elle nouvelle dans la liste ?] */
    private function type(string $v, array $ref): array
    {
        $k = ImportOutils::cle($v);
        if ($k === '') return ['', false];
        foreach (self::NATURES as $n) if (ImportOutils::cle($n) === $k) return [$n, false];
        if (isset($ref['types'][$k])) return [$ref['types'][$k], false];
        foreach (self::SYNONYMES_TYPE as $val => $syn) if (in_array($k, $syn, true)) return [$val, !in_array($val, self::NATURES, true) && !isset($ref['types'][ImportOutils::cle($val)])];
        return [mb_substr(trim($v), 0, 60), true];
    }

    private function statut(string $v): ?string
    {
        $k = ImportOutils::cle($v);
        if ($k === '') return '';
        foreach (self::STATUTS as $s) if (ImportOutils::cle($s) === $k) return $s;
        foreach (self::SYNONYMES_STATUT as $val => $syn) if (in_array($k, $syn, true)) return $val;
        return null;
    }

    private function priorite(string $v, array $ref): array
    {
        $k = ImportOutils::cle($v);
        if ($k === '') return ['', false];
        if (isset($ref['priorites'][$k])) return [$ref['priorites'][$k], false];
        foreach (self::SYNONYMES_PRIORITE as $val => $syn) {
            if (in_array($k, $syn, true)) return [$ref['priorites'][ImportOutils::cle($val)] ?? $val, !isset($ref['priorites'][ImportOutils::cle($val)])];
        }
        return [mb_substr(trim($v), 0, 40), true];
    }

    // ══ Une ligne ══════════════════════════════════════════════════════════

    /** Fiches déjà là dont le lieu sera renseigné par cet import (une seule ligne chacune). */
    private array $lieuxPris = [];

    private function analyserLigne(array $b, int $k, array $ref, string $typeDefaut, string $statutDefaut, array $mappes): array
    {
        $row = (int)($b['_row'] ?? ($k + 2));
        $err = []; $av = [];
        $d = [];
        $d['numero'] = ImportOutils::texte($b['numero'] ?? '', 100);
        // Type
        [$type, $nouveauType] = $this->type((string)($b['type'] ?? ''), $ref);
        if ($type === '') { $type = $typeDefaut; $nouveauType = false; }
        $d['type'] = $type;
        // Description (obligatoire, comme à la saisie)
        $d['description'] = ImportOutils::texte($b['description'] ?? '', 4000);
        if ($d['description'] === '') $err[] = ['champ' => 'Description', 'message' => 'description vide : ligne ignorée (elle est obligatoire)'];
        // Dates
        foreach (self::DATES as $c) {
            $brut = $b[$c] ?? '';
            $d[$c] = ImportOutils::date($brut);
            if (ImportOutils::dateIllisible($brut)) $av[] = ['champ' => self::CHAMPS[$c], 'valeur' => mb_substr((string)$brut, 0, 60), 'message' => 'date non reconnue : importée vide'];
        }
        // Statut : celui du fichier, sinon le défaut — « auto » : réalisée si une date de réalisation est donnée.
        $brutStatut = trim((string)($b['statut'] ?? ''));
        $st = $this->statut($brutStatut);
        $parDefaut = $statutDefaut === 'auto' ? ($d['dateRealisation'] ? 'Réalisée' : 'Planifiée') : $statutDefaut;
        if ($st === null) {
            $av[] = ['champ' => 'Statut', 'valeur' => mb_substr($brutStatut, 0, 60), 'message' => "statut inconnu : importée « $parDefaut »"];
            $st = $parDefaut;
        } elseif ($st === '') $st = $parDefaut;
        $d['statut'] = $st;
        // Priorité
        [$prio, $nouvellePrio] = $this->priorite((string)($b['priorite'] ?? ''), $ref);
        $d['priorite'] = $prio;
        // Montants, durée
        foreach (self::MONTANTS as $c) {
            $n = ImportOutils::nombre($b[$c] ?? '');
            if ($n === null) { $av[] = ['champ' => self::CHAMPS[$c], 'valeur' => mb_substr((string)$b[$c], 0, 40), 'message' => 'montant non reconnu : importé à 0']; $n = 0.0; }
            elseif ($n < 0) $av[] = ['champ' => self::CHAMPS[$c], 'valeur' => (string)$n, 'message' => 'montant négatif (avoir ?)'];
            $d[$c] = round($n, 2);
        }
        $h = ImportOutils::heures($b['dureeHeures'] ?? '');
        if ($h === null) { $av[] = ['champ' => 'Durée (h)', 'valeur' => mb_substr((string)$b['dureeHeures'], 0, 40), 'message' => 'durée non reconnue : importée à 0']; $h = 0.0; }
        $d['dureeHeures'] = round(max(0, $h), 2);
        foreach (self::TEXTES as $c => $max) $d[$c] = ImportOutils::texte($b[$c] ?? '', $max);
        if ($d['agentEmail'] !== '' && !filter_var($d['agentEmail'], FILTER_VALIDATE_EMAIL)) {
            $av[] = ['champ' => self::CHAMPS['agentEmail'], 'valeur' => $d['agentEmail'], 'message' => 'adresse de courriel invalide : importée vide'];
            $d['agentEmail'] = '';
        }
        // Équipement(s) ou bien
        $actifBrut = ImportOutils::texte($b['actif'] ?? '', 500);
        $equips = []; $bienId = null; $libelles = [];
        if ($actifBrut !== '') {
            foreach (preg_split('/\s*[;,\n]\s*/u', $actifBrut) ?: [] as $num) {
                if ($num === '') continue;
                $kk = ImportOutils::cle($num);
                $eq = $ref['equip'][$kk] ?? null; $bi = $ref['bien'][$kk] ?? null;
                if ($eq) {
                    $equips[] = $eq[0]; $libelles[] = $eq[1];
                    if ($bi) $av[] = ['champ' => 'Équipement / bien', 'valeur' => $num, 'message' => 'numéro porté par un équipement ET un bien : rattachée à l\'équipement'];
                } elseif ($bi && !$bienId && !$equips) {
                    $bienId = $bi[0]; $libelles[] = $bi[1];
                } elseif ($bi) {
                    $av[] = ['champ' => 'Équipement / bien', 'valeur' => $num, 'message' => 'une intervention ne se rattache qu\'à un seul bien : celui-ci est laissé'];
                } else {
                    $av[] = ['champ' => 'Équipement / bien', 'valeur' => $num, 'message' => 'introuvable dans les équipements et les biens : non rattachée'];
                }
            }
            if ($equips && $bienId) { $bienId = null; }
        }
        $d['equipementsIds'] = implode(',', array_unique($equips));
        $d['bienId'] = $bienId;
        $d['typeLiaison'] = $equips ? 'Equipement' : ($bienId ? 'Bien' : 'Bien');
        // Contrat
        $cb = ImportOutils::texte($b['contrat'] ?? '', 200);
        $d['contratId'] = null;
        if ($cb !== '') {
            $kc = ImportOutils::cle($cb);
            $id = $ref['contratNum'][$kc] ?? $ref['contratRef'][$kc] ?? null;
            if (!$id && isset($ref['contratSoc'][$kc])) {
                if (count($ref['contratSoc'][$kc]) === 1) $id = $ref['contratSoc'][$kc][0];
                else $av[] = ['champ' => 'Contrat', 'valeur' => $cb, 'message' => count($ref['contratSoc'][$kc]) . ' contrats pour cette société : non rattachée (indiquez le n° de contrat)'];
            } elseif (!$id) {
                $av[] = ['champ' => 'Contrat', 'valeur' => $cb, 'message' => 'contrat introuvable : non rattachée' . ($d['societeManuelle'] === '' ? ' (nom gardé comme prestataire)' : '')];
                if ($d['societeManuelle'] === '' && in_array('contrat', $mappes, true)) $d['societeManuelle'] = mb_substr($cb, 0, 200);
            }
            $d['contratId'] = $id;
        }
        // Cohérence
        $auj = date('Y-m-d');
        if (in_array($st, ['Réalisée', 'Validée', 'Archivée'], true) && !$d['dateRealisation']) {
            $av[] = ['champ' => 'Date de réalisation', 'valeur' => '', 'message' => "statut « $st » sans date de réalisation"];
        }
        if ($st === 'Planifiée' && $d['dateRealisation']) {
            $av[] = ['champ' => 'Statut', 'valeur' => $st, 'message' => 'planifiée, mais une date de réalisation est donnée'];
        }
        if ($d['dateRealisation'] && $d['dateRealisation'] > date('Y-m-d', strtotime('+1 day'))) {
            $av[] = ['champ' => 'Date de réalisation', 'valeur' => $d['dateRealisation'], 'message' => 'date de réalisation dans le futur'];
        }
        // Existe déjà ?
        $action = 'creer'; $existante = null;
        $kn = ImportOutils::cle($d['numero']);
        if ($kn !== '' && isset($ref['interv'][$kn])) { $action = 'maj'; $existante = $ref['interv'][$kn]; }
        $empreinte = self::empreinte($type, (string)($d['dateRealisation'] ?: $d['dateIntervention']), $d['equipementsIds'], (int)$bienId, $d['description']);
        $completer = null;
        if ($action === 'creer' && $kn === '' && isset($ref['empreintes'][$empreinte])) {
            // Déjà dans Larka au même lieu → doublon. Déjà là SANS lieu, et le fichier en
            // donne un → on le lui ajoute (réimporter un planning complète l'historique).
            // Déjà là, mais à un autre lieu → c'est un autre passage : on le crée.
            $lieuK = ImportOutils::cle($d['lieu'] ?? '');
            $memes = array_values(array_filter($ref['empreintes'][$empreinte], fn($x) => $x['lieu'] === $lieuK));
            $sansLieu = array_values(array_filter($ref['empreintes'][$empreinte], fn($x) => $x['lieu'] === ''));
            if ($memes) {
                $action = 'doublon';
                $av[] = ['champ' => '—', 'valeur' => '', 'message' => 'déjà dans Larka (intervention ' . $memes[0]['ref'] . ' : même type, date, équipement, description et lieu) : ignorée'];
            } elseif ($lieuK !== '' && $sansLieu) {
                $action = 'lieu';
                $completer = $sansLieu[0]['id'];
                $av[] = ['champ' => 'Lieu / bâtiment', 'valeur' => $d['lieu'], 'message' => 'déjà dans Larka (intervention ' . $sansLieu[0]['ref'] . ') sans lieu : son lieu sera renseigné'];
            }
        }
        // Planning importé avant la colonne Lieu : le site était collé à la description
        // (« Extincteurs — Bordeaux »). Même passage → on lui donne son lieu et on rend
        // sa description propre, au lieu d'en créer un second.
        $ancienneDesc = null;
        if ($action === 'creer' && $kn === '' && ($d['lieu'] ?? '') !== '') {
            $anc = self::empreinte($type, (string)($d['dateRealisation'] ?: $d['dateIntervention']), $d['equipementsIds'], (int)$bienId,
                                   $d['description'] . ' — ' . $d['lieu']);
            foreach ($ref['empreintes'][$anc] ?? [] as $x) {
                if ($x['lieu'] !== '' && $x['lieu'] !== ImportOutils::cle($d['lieu'])) continue;
                $action = 'lieu'; $completer = $x['id']; $ancienneDesc = $d['description'];
                $av[] = ['champ' => 'Lieu / bâtiment', 'valeur' => $d['lieu'], 'message' => 'déjà dans Larka (intervention ' . $x['ref']
                       . ', le site dans la description) : lieu renseigné, description sans le site'];
                break;
            }
        }
        // Valeurs des cases complémentaires : à part des données de la fiche (elles
        // ne passent pas par add/updateIntervention, mais par setChampsPersoValeurs).
        $cp = [];
        foreach ((array)($b['champsPerso'] ?? []) as $cle => $v) {
            if (!is_string($cle) || !preg_match('/^[a-z0-9_]{1,60}$/', $cle) || !is_scalar($v)) continue;
            $v = trim((string)$v);
            if ($v !== '') $cp[$cle] = mb_substr($v, 0, 2000);
        }
        return ['row' => $row, 'donnees' => $d, 'champsPerso' => $cp, 'erreurs' => $err, 'avert' => $av, 'action' => $action, 'existante' => $existante,
                'completer' => $completer, 'descriptionPropre' => $ancienneDesc,
                'empreinte' => $empreinte, 'actifLibelle' => implode(', ', $libelles) ?: ($actifBrut !== '' ? '(' . $actifBrut . ' — introuvable)' : ''),
                'nouveau_type' => $nouveauType && !in_array($type, self::NATURES, true), 'nouveau_priorite' => $nouvellePrio];
    }

    // ══ Import ═════════════════════════════════════════════════════════════

    /** Fiche existante → charge utile complète de Database::updateIntervention. */
    private static function versPayload(array $r): array
    {
        $map = ['numero' => 'Numero', 'type' => 'Type', 'priorite' => 'Priorite', 'typeLiaison' => 'TypeLiaison', 'bienId' => 'BienId',
                'equipementsIds' => 'EquipementsIds', 'contratId' => 'ContratId', 'demandeId' => 'DemandeId', 'societeManuelle' => 'SocieteManuelle',
                'description' => 'Description', 'dateRealisation' => 'DateRealisation', 'dureeHeures' => 'DureeHeures', 'montant' => 'Montant',
                'montantMainOeuvre' => 'MontantMainOeuvre', 'estRecurrente' => 'EstRecurrente', 'recurrence' => 'Recurrence', 'dateProchaine' => 'DateProchaine',
                'statut' => 'Statut', 'commentaire' => 'Commentaire', 'agentNom' => 'AgentNom', 'agentPrenom' => 'AgentPrenom', 'agentTel' => 'AgentTel',
                'agentEmail' => 'AgentEmail', 'dateDemande' => 'DateDemande', 'dateIntervention' => 'DateIntervention', 'relanceChamp' => 'RelanceChamp',
                'relanceDate' => 'RelanceDate', 'typeBudget' => 'TypeBudget', 'dateEnvoiMail' => 'DateEnvoiMail', 'codeNumeroDemande' => 'CodeNumeroDemande',
                'montantHT' => 'MontantHT', 'notesJSON' => 'NotesJSON', 'delaiAffichageDashboard' => 'DelaiAffichageDashboard',
                'historiqueModifDates' => 'HistoriqueModifDates', 'sansSuiviAdmin' => 'SansSuiviAdmin'];
        $p = [];
        foreach ($map as $k => $col) $p[$k] = $r[$col] ?? null;
        $p['notesJSON'] = $p['notesJSON'] ?: '[]';
        $p['historiqueModifDates'] = $p['historiqueModifDates'] ?: '[]';
        return $p;
    }

    private function importer(array $lignes, array $listes, array $mappes, array $casesACreer = []): array
    {
        $pdo = $this->db->getPdo();
        $cree = 0; $maj = 0; $echecs = []; $listesAjoutees = 0; $ids = []; $casesCreees = 0;
        try { $this->db->resyncAllPgSequences(); } catch (\Throwable $e) {}
        $pdo->beginTransaction();
        try {
            foreach ($listes as $cat => $vals) foreach ($vals as $v) {
                try { $this->db->addListeValeur($cat, $v, 0, 0, 0); $listesAjoutees++; } catch (\Throwable $e) {}
            }
            // Cases des fiches : créées seulement si une ligne importée en remplit une.
            $utiles = [];
            foreach ($lignes as $l) if (in_array($l['action'], ['creer', 'maj'], true)) foreach ($l['champsPerso'] as $k => $_) $utiles[$k] = true;
            $casesACreer = array_values(array_filter($casesACreer, fn($c) => isset($utiles[$c['cle']])));
            if ($casesACreer) {
                $this->db->ajouterChampsPersoDefs('interventions', $casesACreer, $this->login);
                $casesCreees = count($casesACreer);
            }
            // Seules les cases DÉFINIES reçoivent une valeur : une clé inventée par
            // le client ne crée pas de donnée orpheline dans la fiche.
            $clesCases = array_flip(array_column($this->db->getChampsPersoDefs('interventions'), 'cle'));
            $n = 0;
            foreach ($lignes as $l) {
                if ($l['action'] === 'lieu') {
                    // Lieu d'une fiche déjà là, s'il est toujours vide (rien d'autre n'est touché).
                    $pl = ['l' => Database::lieuPropre($l['donnees']['lieu'] ?? ''), 'id' => (int)$l['completer']];
                    if ($l['descriptionPropre'] !== null) $pl['d'] = $l['descriptionPropre'];
                    $st = $pdo->prepare("UPDATE Interventions SET Lieu = :l" . (isset($pl['d']) ? ", Description = :d" : '')
                                      . " WHERE Id = :id AND (Lieu IS NULL OR Lieu = '' OR Lieu = :l2)");
                    $st->execute($pl + ['l2' => $pl['l']]);
                    if ($st->rowCount()) { $maj++; $ids[] = (int)$l['completer']; }
                    continue;
                }
                if (!in_array($l['action'], ['creer', 'maj'], true)) continue;
                $sp = 'sp_ii_' . (++$n);
                $pdo->exec("SAVEPOINT $sp");
                try {
                    $d = $l['donnees'];
                    if ($l['action'] === 'maj') {
                        // Seuls les champs lus dans le fichier remplacent ceux de la fiche.
                        $p = self::versPayload($l['existante']);
                        foreach ($mappes as $c) {
                            if ($c === 'actif') { $p['equipementsIds'] = $d['equipementsIds']; $p['bienId'] = $d['bienId']; $p['typeLiaison'] = $d['typeLiaison']; continue; }
                            if ($c === 'contrat') { $p['contratId'] = $d['contratId']; continue; }
                            if (array_key_exists($c, $d)) $p[$c] = $d[$c];
                        }
                        if (in_array('contrat', $mappes, true) && !in_array('societeManuelle', $mappes, true) && $d['societeManuelle'] !== '') $p['societeManuelle'] = $d['societeManuelle'];
                        $id = (int)$l['existante']['Id'];
                        $this->db->updateIntervention($id, $p, $this->login);
                        $maj++;
                    } else {
                        $p = $d + ['montantMainOeuvre' => 0, 'estRecurrente' => 0, 'recurrence' => '', 'dateProchaine' => null, 'demandeId' => null,
                                   'relanceChamp' => '', 'relanceDate' => null, 'notesJSON' => '[]', 'delaiAffichageDashboard' => 0,
                                   'historiqueModifDates' => '[]', 'sansSuiviAdmin' => 0];
                        if ($p['numero'] === '') $p['numero'] = null;
                        $id = $this->db->addIntervention($p, $this->login);
                        $cree++;
                    }
                    // Statut de fin de circuit : sa date (comme le bouton qui y mène).
                    if ($d['statut'] === 'Validée') {
                        $this->db->execute("UPDATE Interventions SET DateValidation = COALESCE(DateValidation, :d) WHERE Id = :id",
                            ['d' => ($d['dateRealisation'] ?: date('Y-m-d')) . ' 00:00:00', 'id' => $id]);
                    } elseif ($d['statut'] === 'Archivée') {
                        $this->db->execute("UPDATE Interventions SET DateArchivage = COALESCE(DateArchivage, :d), DateValidation = COALESCE(DateValidation, :d) WHERE Id = :id",
                            ['d' => ($d['dateRealisation'] ?: date('Y-m-d')) . ' 00:00:00', 'id' => $id]);
                    }
                    $vals = array_intersect_key($l['champsPerso'], $clesCases);
                    if ($vals) $this->db->setChampsPersoValeurs('interventions', $id, $vals);
                    $ids[] = $id;
                    $pdo->exec("RELEASE SAVEPOINT $sp");
                } catch (\Throwable $e) {
                    try { $pdo->exec("ROLLBACK TO SAVEPOINT $sp"); $pdo->exec("RELEASE SAVEPOINT $sp"); } catch (\Throwable $_) {}
                    $echecs[] = ['row' => $l['row'], 'message' => mb_substr($e->getMessage(), 0, 200)];
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $laisse = count(array_filter($lignes, fn($l) => in_array($l['action'], ['laisse', 'doublon'], true)));
        $err = count(array_filter($lignes, fn($l) => $l['action'] === 'erreur'));
        if (class_exists('Journal')) {
            Journal::info('interventions', "Import d'interventions par {$this->login} : $cree créée(s), $maj mise(s) à jour, $laisse laissée(s), $err en erreur"
                . ($echecs ? ', ' . count($echecs) . ' en échec' : ''), ['action' => 'import_interventions', 'code' => $echecs ? 'BDD-004' : null]);
        }
        return ['crees' => $cree, 'misesAJour' => $maj, 'laissees' => $laisse, 'enErreur' => $err, 'echecs' => $echecs,
                'listesAjoutees' => $listesAjoutees, 'casesCreees' => $casesCreees, 'ids' => $ids];
    }
}
