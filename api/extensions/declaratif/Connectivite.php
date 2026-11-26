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
 * Larka — Modules déclaratifs : connectivité des bâtiments (Larka Connect)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ce que le vocabulaire déclaratif sait demander au bâtiment connecté :
 *
 *   • désigner un point suivi, un équipement raccordé ou un bâtiment
 *     (champ « lien » vers points_connect, connexions_connect, batiments) ;
 *   • lire une MESURE sur ce qu'il désigne (champ « mesure ») : dernière
 *     valeur, état, alarmes actives, minimum, maximum et moyenne sur 24 h,
 *     consommation du jour ou du mois d'un compteur ;
 *   • RECEVOIR les alarmes techniques dans un de ses registres (« alarmes »).
 *
 * POURQUOI UNE CLASSE À PART, ET PAS LA SECURITY API GÉNÉRIQUE
 * Les tables de Larka Connect portent des secrets chiffrés (identifiants des
 * automates), des adresses réseau, l'empreinte des jetons des PC releveurs, le
 * nom des personnes qui ont acquitté une alarme ou commandé un équipement. La
 * politique de données les classe « système protégé » : aucune requête
 * structurée ne peut les nommer.
 *
 * Le module obtient donc des RÉPONSES, pas un accès — exactement comme la
 * source de suggestions « personnes ». Chaque méthode ci-dessous est une
 * projection FIXE, écrite ici : le module ne choisit ni la table, ni les
 * colonnes, ni le filtre. Il fournit au plus des identifiants, qui partent en
 * paramètres liés. La capacité déduite de sa déclaration est vérifiée à chaque
 * appel, et chaque lecture est journalisée comme une requête ordinaire.
 *
 * CE QUI N'EXISTE PAS, ET N'EXISTERA PAS PAR CE CHEMIN
 *   • écrire vers un équipement (consigne, commande de borne) : cela exige un
 *     gestionnaire qui confirme son identité ; un module n'en a pas ;
 *   • acquitter ou modifier une alarme ;
 *   • lire l'adresse, le protocole détaillé ou les identifiants d'un équipement.
 *
 * Larka Connect fermé (serveur ou organisation) : les liens restent lisibles
 * — ce sont des noms —, les mesures valent « indisponible », et la réception
 * des alarmes ne fait rien.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/../securite/JournalSecurite.php';
require_once __DIR__ . '/../securite/PolitiqueDonnees.php';
require_once __DIR__ . '/../securite/Surveillance.php';
require_once __DIR__ . '/../securite/Securite.php';

final class ExtConnectivite
{
    /** Bornes : une page de module ne demande jamais plus. */
    private const MAX_IDS        = 500;
    private const MAX_CIBLES     = 500;
    /** Réception des alarmes : par passage et par réception. */
    private const MAX_PAR_PASSAGE = 200;
    private const MAX_RELUES      = 500;
    /** Lecture différée depuis l'écran du module : pas plus souvent que… */
    private const INTERVALLE_ECRAN_S = 30;

    /** Compte au nom duquel Larka écrit les alarmes reçues. */
    public const AUTEUR = 'larka.connect';

    private $db;
    private string $extension;
    private array $capacites;
    private ?bool $connectOuvert = null;

    public function __construct($db, string $extension, array $capacites)
    {
        $this->db        = $db;
        $this->extension = $extension;
        $this->capacites = $capacites;
    }

    /** La cible est-elle servie par ce lecteur plutôt que par une requête ? */
    public static function sert(string $vers): bool
    {
        return (ExtSchemaDeclaratif::CIBLES_LIEN[$vers]['servi_par'] ?? '') === 'connect';
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Contrôles communs
    // ═══════════════════════════════════════════════════════════════════════

    /** Capacité déduite présente, module non bloqué — sinon refus journalisé. */
    private function exiger(string $vers, string $operation): void
    {
        if (!self::sert($vers)) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension, 'operation_reelle' => 'connect.' . $operation,
                'ressource' => $vers, 'motif' => 'cible_non_servie_par_connect',
                'gravite' => ExtJournalSecurite::CRITIQUE,
            ]);
        }
        if (class_exists('ExtSurveillance') && ExtSurveillance::bloquee($this->extension)) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension, 'operation_reelle' => 'connect.' . $operation,
                'motif' => 'extension_sous_blocage_comportemental',
            ]);
        }
        if (!in_array('donnees.lire:' . $vers, $this->capacites, true)) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension, 'operation_reelle' => 'connect.' . $operation,
                'ressource' => $vers, 'motif' => 'capacite_lecture_absente',
            ]);
        }
    }

    private function tracer(string $operation, string $vers, int $volume): void
    {
        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->extension,
            'operation_reelle' => 'connect.' . $operation,
            'table' => $vers, 'colonnes' => 'projection fixe', 'volume' => $volume,
        ]);
    }

    /** Larka Connect est-il ouvert pour cette organisation ? */
    public function connectOuvert(): bool
    {
        if ($this->connectOuvert !== null) return $this->connectOuvert;
        return $this->connectOuvert = self::ouvertPour($this->db);
    }

    public static function ouvertPour($db): bool
    {
        if (!class_exists('Connect')) {
            if (!is_file(__DIR__ . '/../../connect/Connect.php')) return false;
            require_once __DIR__ . '/../../connect/Connect.php';
        }
        try {
            return $db instanceof Database ? Connect::actifPour($db) : Connect::actifServeur();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function pdo(): PDO
    {
        return $this->db->getPdo();
    }

    /** Liste d'identifiants entiers, positifs, uniques, bornés. */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $i) {
            $n = (int)$i;
            if ($n > 0) $out[$n] = $n;
            if (count($out) >= self::MAX_IDS) break;
        }
        return array_values($out);
    }

    /** « ?, ?, ? » pour une liste IN — des marques, jamais des valeurs. */
    private static function marques(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * Exécute une requête ÉCRITE ICI. Une table absente (base créée avant
     * Larka Connect, plans jamais ouverts) rend un résultat vide plutôt qu'une
     * erreur : le module affiche « — », il ne tombe pas.
     */
    private function lire(string $sql, array $params = []): array
    {
        try {
            $st = $this->pdo()->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Valeur d'une colonne sans se fier à la casse (PostgreSQL replie). */
    private static function v(array $l, string $c): mixed
    {
        if (array_key_exists($c, $l)) return $l[$c];
        foreach ($l as $k => $x) if (strcasecmp((string)$k, $c) === 0) return $x;
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Liens : libellés, choix, existence
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Projection fixe d'une cible. Le libellé est composé ICI : c'est la seule
     * chose qu'un module saura de l'objet désigné.
     */
    private function projection(string $vers, ?array $ids, string $recherche = ''): array
    {
        $params = [];
        $ou = '';
        if ($ids !== null) {
            if ($ids === []) return [];
            $ou = ' WHERE x.Id IN (' . self::marques($ids) . ')';
            $params = $ids;
        }

        $sql = match ($vers) {
            'points_connect' =>
                'SELECT x.Id, x.Nom, x.Unite, pa.Nom AS Connexion, b.Nom AS Batiment
                   FROM ConnectPoints x
                   LEFT JOIN ConnectPasserelles pa ON pa.Id = x.PasserelleId
                   LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId' . $ou,
            'connexions_connect' =>
                'SELECT x.Id, x.Nom, x.Protocole, b.Nom AS Batiment
                   FROM ConnectPasserelles x
                   LEFT JOIN PlanBatiments b ON b.Id = x.BatimentId' . $ou,
            'batiments' =>
                'SELECT x.Id, x.Nom FROM PlanBatiments x' . $ou,
        };
        $lignes = $this->lire($sql . ' ORDER BY x.Id LIMIT ' . self::MAX_CIBLES, $params);
        // Plans jamais ouverts : PlanBatiments n'existe pas encore, et la
        // jointure fait échouer toute la lecture. On relit sans le bâtiment.
        if ($lignes === [] && $vers !== 'batiments') {
            $sql = preg_replace('/\s+LEFT JOIN PlanBatiments b ON b\.Id = (?:pa|x)\.BatimentId/', '', $sql);
            $sql = str_replace(', b.Nom AS Batiment', '', $sql);
            $lignes = $this->lire($sql . ' ORDER BY x.Id LIMIT ' . self::MAX_CIBLES, $params);
        }

        $out = [];
        $q = mb_strtolower(trim($recherche));
        // Le bâtiment n'est ajouté que s'il apporte quelque chose : une
        // connexion nommée « Automate — Groupe scolaire » le porte déjà.
        $suffixe = static function (string $deja, string $bat, string $sep): string {
            return $bat === '' || mb_stripos($deja, $bat) !== false ? '' : $sep . $bat;
        };
        foreach ($lignes as $l) {
            $id    = (int)self::v($l, 'Id');
            $nom   = trim((string)self::v($l, 'Nom')) ?: ('#' . $id);
            $unite = trim((string)self::v($l, 'Unite'));
            $cx    = trim((string)self::v($l, 'Connexion'));
            $bat   = trim((string)self::v($l, 'Batiment'));
            $lib = match ($vers) {
                'points_connect' => ($p = $nom . ($unite !== '' ? ' (' . $unite . ')' : '')
                                        . ($cx !== '' ? ' — ' . $cx : ''))
                                    . $suffixe($p, $bat, ' · '),
                'connexions_connect' => $nom . $suffixe($nom, $bat, ' — '),
                default => $nom,
            };
            if ($q !== '' && !str_contains(mb_strtolower($lib), $q)) continue;
            $out[$id] = $lib;
        }
        return $out;
    }

    /** id → libellé, pour les champs « lien » d'une liste. */
    public function libelles(string $vers, array $ids): array
    {
        $this->exiger($vers, 'lien');
        $ids = self::ids($ids);
        $out = $this->projection($vers, $ids);
        $this->tracer('lien', $vers, count($out));
        return $out;
    }

    /** Valeurs proposables dans le sélecteur d'un champ « lien ». */
    public function cibles(string $vers, string $recherche = ''): array
    {
        $this->exiger($vers, 'choix');
        $out = [];
        foreach ($this->projection($vers, null, $recherche) as $id => $lib) {
            $out[] = ['id' => $id, 'libelle' => $lib];
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['libelle'], $b['libelle']));
        $this->tracer('choix', $vers, count($out));
        return ['valeurs' => array_slice($out, 0, 200), 'total' => count($out)];
    }

    /** L'objet désigné existe-t-il ? (contrôle d'écriture d'un champ lien) */
    public function existe(string $vers, int $id): bool
    {
        $this->exiger($vers, 'verification');
        if ($id <= 0) return false;
        $table = ['points_connect' => 'ConnectPoints', 'connexions_connect' => 'ConnectPasserelles',
                  'batiments' => 'PlanBatiments'][$vers];
        return $this->lire('SELECT Id FROM ' . $table . ' WHERE Id = ?', [$id]) !== [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Mesures
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Lit les grandeurs demandées pour une liste d'objets d'une même cible.
     *
     * @param string   $vers       points_connect | connexions_connect | batiments
     * @param int[]    $ids        objets désignés par les lignes affichées
     * @param string[] $grandeurs  noms du catalogue GRANDEURS_MESURE[$vers]
     * @return array<int, array<string, array{valeur:mixed, unite?:string, etat?:string}>>
     */
    public function mesures(string $vers, array $ids, array $grandeurs): array
    {
        $this->exiger($vers, 'mesure');
        $ids = self::ids($ids);
        $grandeurs = array_values(array_intersect(array_unique($grandeurs),
            array_keys(ExtSchemaDeclaratif::GRANDEURS_MESURE[$vers] ?? [])));
        if ($ids === [] || $grandeurs === []) return [];

        // Larka Connect fermé : rien n'est lu, et l'on dit pourquoi plutôt que
        // d'afficher des valeurs figées le jour de la fermeture.
        if (!$this->connectOuvert()) {
            $out = [];
            foreach ($ids as $id) {
                foreach ($grandeurs as $g) {
                    $out[$id][$g] = ['valeur' => $g === 'etat' ? 'indisponible' : null,
                                     'etat' => 'indisponible'];
                }
            }
            return $out;
        }

        $out = match ($vers) {
            'points_connect'     => $this->mesuresPoints($ids, $grandeurs),
            'connexions_connect' => $this->mesuresConnexions($ids, $grandeurs),
            'batiments'          => $this->mesuresBatiments($ids, $grandeurs),
        };
        $this->tracer('mesure', $vers, count($out));
        return $out;
    }

    /** Une valeur reçue est-elle trop ancienne pour décrire le présent ? (règle de la supervision) */
    public static function perimee(?string $lecture, array $pa): bool
    {
        if (!$lecture) return false;
        $t = strtotime($lecture);
        if ($t === false) return false;
        $intervalle = (int)($pa['IntervalleSecondes'] ?? 60) ?: 60;
        $proto = (string)($pa['Protocole'] ?? '');
        $limite = max(3 * $intervalle, in_array($proto, ['modbus', 'bacnet'], true) ? 900 : 3600);
        return (time() - $t) > $limite;
    }

    private function mesuresPoints(array $ids, array $grandeurs): array
    {
        $m = self::marques($ids);
        $points = [];
        foreach ($this->lire("SELECT p.Id, p.Nature, p.Unite, p.Decimales, p.Actif, p.DerniereValeur,
                                     p.DerniereValeurTexte, p.DerniereQualite, p.DerniereLecture,
                                     pa.Actif AS PaActif, pa.IntervalleSecondes, pa.Protocole
                                FROM ConnectPoints p
                                LEFT JOIN ConnectPasserelles pa ON pa.Id = p.PasserelleId
                               WHERE p.Id IN ($m)", $ids) as $l) {
            $points[(int)self::v($l, 'Id')] = $l;
        }

        $alarmes = [];
        foreach ($this->lire("SELECT PointId, COUNT(*) AS N FROM ConnectAlarmes
                               WHERE Etat = 'active' AND PointId IN ($m) GROUP BY PointId", $ids) as $l) {
            $alarmes[(int)self::v($l, 'PointId')] = (int)self::v($l, 'N');
        }

        // Statistiques sur 24 h : UNE requête groupée pour tous les points
        // affichés, jamais une par ligne.
        $stats = [];
        if (array_intersect($grandeurs, ['moyenne_24h', 'minimum_24h', 'maximum_24h'])) {
            $depuis = date('Y-m-d H:i:s', time() - 86400);
            foreach ($this->lire("SELECT PointId, AVG(Valeur) AS Moy, MIN(Valeur) AS Mini, MAX(Valeur) AS Maxi
                                    FROM ConnectMesures
                                   WHERE PointId IN ($m) AND Horodatage >= ? AND Valeur IS NOT NULL
                                     AND (Qualite IS NULL OR Qualite <> 'mauvaise')
                                   GROUP BY PointId", array_merge($ids, [$depuis])) as $l) {
                $stats[(int)self::v($l, 'PointId')] = $l;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $p = $points[$id] ?? null;
            if ($p === null) {
                foreach ($grandeurs as $g) {
                    $out[$id][$g] = ['valeur' => $g === 'etat' ? 'sans_valeur' : null, 'etat' => 'sans_valeur'];
                }
                continue;
            }
            $unite = trim((string)self::v($p, 'Unite'));
            $dec   = max(0, min(6, (int)(self::v($p, 'Decimales') ?? 2)));
            $etat  = $this->etatPoint($p, $alarmes[$id] ?? 0);
            $nombre = static fn($v) => $v === null || $v === '' ? null : round((float)$v, $dec);

            foreach ($grandeurs as $g) {
                $val = match ($g) {
                    'valeur'      => self::v($p, 'DerniereValeur') !== null
                                        ? $nombre(self::v($p, 'DerniereValeur'))
                                        : (self::v($p, 'DerniereValeurTexte') ?: null),
                    'horodatage'  => self::v($p, 'DerniereLecture') ?: null,
                    'etat'        => $etat,
                    'alarmes'     => $alarmes[$id] ?? 0,
                    'moyenne_24h' => $nombre(self::v($stats[$id] ?? [], 'Moy')),
                    'minimum_24h' => $nombre(self::v($stats[$id] ?? [], 'Mini')),
                    'maximum_24h' => $nombre(self::v($stats[$id] ?? [], 'Maxi')),
                    'conso_jour'  => $this->conso($p, date('Y-m-d 00:00:00'), $dec),
                    'conso_mois'  => $this->conso($p, date('Y-m-01 00:00:00'), $dec),
                    default       => null,
                };
                $nature = ExtSchemaDeclaratif::GRANDEURS_MESURE['points_connect'][$g]['nature'];
                $out[$id][$g] = ['valeur' => $val, 'etat' => $etat]
                              + ($nature === 'nombre' && $unite !== '' ? ['unite' => $unite] : []);
            }
        }
        return $out;
    }

    /**
     * Consommation d'un compteur depuis un instant : valeur actuelle moins le
     * dernier index connu à cet instant (ou le premier qui le suit, si
     * l'historique commence après). Même principe qu'un relevé manuel, et que
     * l'écran Consommations de la supervision. Un index en baisse (compteur
     * remplacé) ne donne pas de consommation : on ne l'invente pas.
     */
    private function conso(array $p, string $depuis, int $dec): ?float
    {
        if ((string)self::v($p, 'Nature') !== 'compteur') return null;
        $actuel = self::v($p, 'DerniereValeur');
        if ($actuel === null || (string)self::v($p, 'DerniereQualite') === 'mauvaise') return null;
        $id = (int)self::v($p, 'Id');
        $avant = $this->lire("SELECT Valeur FROM ConnectMesures WHERE PointId = ? AND Horodatage <= ?
                                 AND Valeur IS NOT NULL AND (Qualite IS NULL OR Qualite <> 'mauvaise')
                               ORDER BY Horodatage DESC LIMIT 1", [$id, $depuis]);
        if ($avant === []) {
            $avant = $this->lire("SELECT Valeur FROM ConnectMesures WHERE PointId = ? AND Horodatage >= ?
                                     AND Valeur IS NOT NULL AND (Qualite IS NULL OR Qualite <> 'mauvaise')
                                   ORDER BY Horodatage ASC LIMIT 1", [$id, $depuis]);
        }
        if ($avant === []) return null;
        $c = (float)$actuel - (float)self::v($avant[0], 'Valeur');
        return $c < 0 ? null : round($c, $dec);
    }

    /** État d'un point, dans le vocabulaire fermé ETATS_MESURE. */
    private function etatPoint(array $p, int $alarmes): string
    {
        if ((int)(self::v($p, 'Actif') ?? 1) === 0 || (int)(self::v($p, 'PaActif') ?? 1) === 0) return 'inactif';
        if (!self::v($p, 'DerniereLecture')) return 'sans_valeur';
        if ($alarmes > 0) return 'alarme';
        if ((string)self::v($p, 'DerniereQualite') === 'mauvaise') return 'defaut';
        if (self::perimee((string)self::v($p, 'DerniereLecture'),
                          ['IntervalleSecondes' => self::v($p, 'IntervalleSecondes'),
                           'Protocole' => self::v($p, 'Protocole')])) return 'ancienne';
        return 'normal';
    }

    private function mesuresConnexions(array $ids, array $grandeurs): array
    {
        $m = self::marques($ids);
        $cx = [];
        foreach ($this->lire("SELECT Id, Actif, Etat, DerniereCollecte, DernierContact
                                FROM ConnectPasserelles WHERE Id IN ($m)", $ids) as $l) {
            $cx[(int)self::v($l, 'Id')] = $l;
        }
        $alarmes = [];
        foreach ($this->lire("SELECT PasserelleId, COUNT(*) AS N FROM ConnectAlarmes
                               WHERE Etat = 'active' AND PasserelleId IN ($m) GROUP BY PasserelleId", $ids) as $l) {
            $alarmes[(int)self::v($l, 'PasserelleId')] = (int)self::v($l, 'N');
        }
        $out = [];
        foreach ($ids as $id) {
            $c = $cx[$id] ?? null;
            $etat = match (true) {
                $c === null                          => 'sans_valeur',
                (int)(self::v($c, 'Actif') ?? 1) === 0 => 'inactif',
                default => ['ok' => 'normal', 'partiel' => 'partiel', 'erreur' => 'defaut']
                               [(string)self::v($c, 'Etat')] ?? 'sans_valeur',
            };
            foreach ($grandeurs as $g) {
                $out[$id][$g] = ['etat' => $etat, 'valeur' => match ($g) {
                    'etat'       => $etat,
                    'horodatage' => $c ? (self::v($c, 'DerniereCollecte') ?: self::v($c, 'DernierContact') ?: null) : null,
                    'alarmes'    => $alarmes[$id] ?? 0,
                    default      => null,
                }];
            }
        }
        return $out;
    }

    private function mesuresBatiments(array $ids, array $grandeurs): array
    {
        $m = self::marques($ids);
        $cx = [];
        foreach ($this->lire("SELECT BatimentId, Etat, Actif FROM ConnectPasserelles
                               WHERE BatimentId IN ($m)", $ids) as $l) {
            $cx[(int)self::v($l, 'BatimentId')][] = $l;
        }
        $alarmes = [];
        foreach ($this->lire("SELECT pa.BatimentId, COUNT(*) AS N FROM ConnectAlarmes a
                                JOIN ConnectPasserelles pa ON pa.Id = a.PasserelleId
                               WHERE a.Etat = 'active' AND pa.BatimentId IN ($m) GROUP BY pa.BatimentId", $ids) as $l) {
            $alarmes[(int)self::v($l, 'BatimentId')] = (int)self::v($l, 'N');
        }
        $out = [];
        foreach ($ids as $id) {
            $actives = array_filter($cx[$id] ?? [], fn($c) => (int)(self::v($c, 'Actif') ?? 1) !== 0);
            $etats = array_map(fn($c) => (string)self::v($c, 'Etat'), $actives);
            $defaut = count(array_filter($etats, fn($e) => $e === 'erreur'));
            $etat = match (true) {
                $actives === []                     => 'sans_valeur',
                ($alarmes[$id] ?? 0) > 0            => 'alarme',
                $defaut > 0                         => 'defaut',
                in_array('partiel', $etats, true)   => 'partiel',
                default                             => 'normal',
            };
            foreach ($grandeurs as $g) {
                $out[$id][$g] = ['etat' => $etat, 'valeur' => match ($g) {
                    'etat'                 => $etat,
                    'alarmes'              => $alarmes[$id] ?? 0,
                    'connexions_en_defaut' => $defaut,
                    default                => null,
                }];
            }
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Réception des alarmes techniques
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Inscrit, dans les registres des modules qui l'ont déclaré, les alarmes
     * techniques de l'organisation dont $db est la base.
     *
     * Appelé à chaque passage de la collecte (api/connect/Collecte.php), pour
     * chaque organisation où Larka Connect est ouvert. N'échoue jamais : un
     * module en panne n'arrête ni la collecte, ni les autres modules.
     *
     * @return array{modules:int, inscrites:int, mises_a_jour:int, erreurs:string[]}
     */
    public static function synchroniserOrganisation($db, string $cleOrganisation = 'default'): array
    {
        $bilan = ['modules' => 0, 'inscrites' => 0, 'mises_a_jour' => 0, 'erreurs' => []];
        if (!function_exists('cfg') || !cfg('extensions', 'actif')) return $bilan;
        if (!self::ouvertPour($db)) return $bilan;

        require_once __DIR__ . '/../Registre.php';

        // L'état d'installation des modules est rangé PAR ORGANISATION
        // (data/extensions/tenants/<clé>/) et se résout d'après la clé
        // courante. En tâche planifiée, il n'y a pas de requête pour la poser :
        // on la pose le temps du passage, et on la rend ensuite.
        $avant = $GLOBALS['_currentTenantKey'] ?? null;
        $multi = class_exists('TenantResolver') && TenantResolver::isMultiTenant();
        if ($multi) $GLOBALS['_currentTenantKey'] = $cleOrganisation;
        try {
            $registre = ExtRegistre::pourBase($db);
            foreach ($registre->actives() as $id => $man) {
                if (!$man->estDeclaratif()) continue;
                $decl = $man->declaration();
                if (empty($decl['alarmes'])) continue;
                $bilan['modules']++;
                try {
                    $r = self::recevoir($db, $id, $decl);
                    $bilan['inscrites']    += $r['inscrites'];
                    $bilan['mises_a_jour'] += $r['mises_a_jour'];
                } catch (\Throwable $e) {
                    $bilan['erreurs'][] = $id . ' : ' . $e->getMessage();
                }
            }
        } finally {
            if ($multi) $GLOBALS['_currentTenantKey'] = $avant;
        }
        return $bilan;
    }

    /**
     * Réception pour UN module déjà chargé — depuis son propre écran, pour que
     * le registre soit à jour même si la collecte ne passe pas (poste
     * autonome arrêté, démonstration). Espacée : au plus toutes les 30 s.
     */
    public static function recevoirDepuisEcran($db, string $identifiant, array $decl): void
    {
        if (empty($decl['alarmes']) || !self::ouvertPour($db)) return;
        $etat = self::lireEtat();
        $vu = (int)($etat[$identifiant]['_ecran'] ?? 0);
        if (time() - $vu < self::INTERVALLE_ECRAN_S) return;
        try {
            self::recevoir($db, $identifiant, $decl);
        } catch (\Throwable $e) {
            // L'écran s'affiche quand même : la collecte réessaiera.
        }
        self::ecrireEtat(function (array $e) use ($identifiant) {
            $e[$identifiant]['_ecran'] = time();
            return $e;
        });
    }

    /**
     * Réception des alarmes pour un module : toutes ses réceptions déclarées.
     * @return array{inscrites:int, mises_a_jour:int}
     */
    public static function recevoir($db, string $identifiant, array $decl): array
    {
        $caps = ExtSchemaDeclaratif::capacitesRequises($decl);
        if (!in_array('connect.alarmes', $caps, true)) return ['inscrites' => 0, 'mises_a_jour' => 0];

        $securite = new ExtSecurite($db, $identifiant, $caps,
            ['Login' => self::AUTEUR, 'Role' => 'Systeme']);
        $total = ['inscrites' => 0, 'mises_a_jour' => 0];

        // Un seul passage à la fois par organisation : la collecte et l'écran
        // du module pourraient sinon inscrire deux fois la même alarme.
        $verrou = @fopen(self::fichierEtat() . '.verrou', 'c');
        if ($verrou && !flock($verrou, LOCK_EX | LOCK_NB)) { fclose($verrou); return $total; }
        try {
            foreach ($decl['alarmes'] as $reception) {
                $r = self::recevoirUne($db, $securite, $identifiant, $decl, $reception);
                $total['inscrites']    += $r['inscrites'];
                $total['mises_a_jour'] += $r['mises_a_jour'];
            }
        } finally {
            if ($verrou) { flock($verrou, LOCK_UN); fclose($verrou); }
        }
        if ($total['inscrites'] || $total['mises_a_jour']) {
            ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
                'extension' => $identifiant, 'operation_reelle' => 'connect.alarmes',
                'colonnes' => sprintf('%d inscrite(s), %d mise(s) à jour',
                                      $total['inscrites'], $total['mises_a_jour']),
                'volume' => $total['inscrites'] + $total['mises_a_jour'],
            ]);
        }
        return $total;
    }

    /** Attributs qui changent après l'ouverture : la ligne se relit tant qu'ils bougent. */
    private const ATTRIBUTS_MOBILES = ['etat', 'fin', 'duree', 'acquittee', 'demande'];

    private static function recevoirUne($db, ExtSecurite $securite, string $identifiant,
                                        array $decl, array $rec): array
    {
        $jeu    = $rec['jeu'];
        $champs = $decl['donnees'][$jeu]['champs'];
        $table  = ExtPolitiqueDonnees::prefixeExtension($identifiant) . $jeu;
        $cleChamp = array_search('alarme', $rec['champs'], true);
        $bilan = ['inscrites' => 0, 'mises_a_jour' => 0];

        // ── Points suivis (filtre « points_de ») ─────────────────────────
        $points = null;
        if (!empty($rec['si']['points_de'])) {
            $pd = $rec['si']['points_de'];
            $points = [];
            foreach ($securite->requeter([
                '_intention' => 'alarmes.points_suivis', 'action' => 'SELECT',
                'table' => ExtPolitiqueDonnees::prefixeExtension($identifiant) . $pd['jeu'],
                'colonnes' => [$pd['champ']], 'limite' => 1000,
            ])['lignes'] as $l) {
                $p = (int)self::v($l, $pd['champ']);
                if ($p > 0) $points[$p] = true;
            }
        }

        // ── 1. Nouvelles alarmes ─────────────────────────────────────────
        $cleEtat = $identifiant . '/' . $jeu;
        $etat = self::lireEtat();
        $dernier = isset($etat[$cleEtat]['dernier']) ? (int)$etat[$cleEtat]['dernier'] : null;
        // Premier passage sans aucun point suivi : on n'avance pas le repère,
        // sinon l'historique récent serait perdu le jour où l'on ajoute les
        // salles ou les sondes à suivre.
        if (!($dernier === null && $points === [])) {
            $sql = self::SQL_ALARMES . ' WHERE a.Id > ?';
            $params = [$dernier ?? 0];
            if ($dernier === null) {
                $sql .= ' AND a.Debut >= ?';
                $params[] = date('Y-m-d H:i:s', time() - 86400 * (int)$rec['historique_jours']);
            }
            $lignes = self::lireAlarmes($db, $sql . ' ORDER BY a.Id LIMIT ' . self::MAX_PAR_PASSAGE, $params);
            $max = $dernier ?? 0;
            foreach ($lignes as $a) {
                $max = max($max, (int)$a['id']);
                if (!self::passeFiltre($a, $rec['si'], $points)) continue;
                // Déjà là (état perdu, passage concurrent) : on n'en refait pas.
                $deja = $securite->requeter([
                    '_intention' => 'alarmes.deja_recue', 'action' => 'COUNT', 'table' => $table,
                    'ou' => [$cleChamp => (int)$a['id']],
                ])['nombre'] ?? 0;
                if ($deja > 0) continue;

                $valeurs = self::valeursPour($a, $rec['champs'], $champs)
                         + self::defauts($champs, $rec['champs']);
                $maintenant = date('Y-m-d H:i:s');
                $valeurs += ['cree_le' => $maintenant, 'cree_par' => self::AUTEUR,
                             'modifie_le' => $maintenant, 'modifie_par' => self::AUTEUR];
                $securite->requeter([
                    '_intention' => 'alarmes.inscription', 'action' => 'INSERT',
                    'table' => $table, 'valeurs' => $valeurs,
                ]);
                $bilan['inscrites']++;
            }
            if ($lignes !== [] || $dernier === null) {
                // Aucun résultat au premier passage : le repère part du dernier
                // numéro existant, pour ne pas relire l'historique à chaque fois.
                if ($lignes === []) {
                    $r = self::lireAlarmes($db, 'SELECT MAX(Id) AS Id FROM ConnectAlarmes', []);
                    $max = max($max, (int)($r[0]['id'] ?? 0));
                }
                self::ecrireEtat(function (array $e) use ($cleEtat, $max) {
                    $e[$cleEtat]['dernier'] = $max;
                    return $e;
                });
            }
        }

        // ── 2. Lignes à relire : alarmes encore ouvertes, non acquittées… ──
        $mobiles = array_intersect($rec['champs'], self::ATTRIBUTS_MOBILES);
        if ($mobiles === []) return $bilan;

        $recentes = $securite->requeter([
            '_intention' => 'alarmes.relecture', 'action' => 'SELECT', 'table' => $table,
            'colonnes' => array_merge(['id', $cleChamp], array_keys($mobiles)),
            'tri' => [$cleChamp => 'DESC'], 'limite' => self::MAX_RELUES,
        ])['lignes'];
        $aRelire = [];
        foreach ($recentes as $l) {
            $n = (int)self::v($l, $cleChamp);
            if ($n <= 0) continue;
            foreach ($mobiles as $champ => $attr) {
                $v = self::v($l, $champ);
                $fini = match ($attr) {
                    'etat'      => $v === 'terminee',
                    'acquittee' => (int)$v === 1,
                    default     => $v !== null && $v !== '',
                };
                if (!$fini) { $aRelire[$n] = $l; break; }
            }
        }
        if ($aRelire === []) return $bilan;

        $ids = array_keys($aRelire);
        $actuelles = self::lireAlarmes($db, self::SQL_ALARMES . ' WHERE a.Id IN ('
            . self::marques($ids) . ')', $ids);
        foreach ($actuelles as $a) {
            $ligne = $aRelire[(int)$a['id']] ?? null;
            if ($ligne === null) continue;
            $tout = self::valeursPour($a, $rec['champs'], $champs);
            $changes = [];
            foreach ($mobiles as $champ => $attr) {
                $neuf = $tout[$champ] ?? null;
                $ancien = self::v($ligne, $champ);
                if ((string)($neuf ?? '') !== (string)($ancien ?? '')) $changes[$champ] = $neuf;
            }
            if ($changes === []) continue;
            $changes['modifie_le']  = date('Y-m-d H:i:s');
            $changes['modifie_par'] = self::AUTEUR;
            $securite->requeter([
                '_intention' => 'alarmes.mise_a_jour', 'action' => 'UPDATE', 'table' => $table,
                'valeurs' => $changes, 'ou' => ['id' => (int)self::v($ligne, 'id')],
            ]);
            $bilan['mises_a_jour']++;
        }
        return $bilan;
    }

    /** Lecture FIXE des alarmes, avec ce qu'il faut pour nommer le point et le bâtiment. */
    private const SQL_ALARMES =
        'SELECT a.Id, a.PointId, a.PasserelleId, a.Origine, a.Gravite, a.Message,
                a.ValeurDeclenchement, a.Debut, a.Fin, a.Etat, a.Acquittee, a.DemandeId,
                p.Nom AS PointNom, p.Unite AS PointUnite, p.Decimales AS PointDecimales, p.EquipementId,
                pa.BatimentId, b.Nom AS BatimentNom
           FROM ConnectAlarmes a
           LEFT JOIN ConnectPoints p ON p.Id = a.PointId
           LEFT JOIN ConnectPasserelles pa ON pa.Id = a.PasserelleId
           LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId';

    private static function lireAlarmes($db, string $sql, array $params): array
    {
        $exec = static function (string $q) use ($db, $params): array {
            $st = $db->getPdo()->prepare($q);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };
        try {
            $l = $exec($sql);
        } catch (\Throwable $e) {
            // Plans jamais ouverts : PlanBatiments manque, on lit sans le nom.
            try {
                $l = $exec(str_replace(', b.Nom AS BatimentNom', ', NULL AS BatimentNom',
                    preg_replace('/\s+LEFT JOIN PlanBatiments b ON b\.Id = pa\.BatimentId/', '', $sql)));
            } catch (\Throwable $e2) {
                return [];
            }
        }
        // Noms normalisés : PostgreSQL rend « pointid », SQLite « PointId ».
        // La suite ne lit plus que ces clés-là, en minuscules.
        $noms = ['id' => 'Id', 'point_id' => 'PointId', 'passerelle_id' => 'PasserelleId',
                 'origine' => 'Origine', 'gravite' => 'Gravite', 'message' => 'Message',
                 'valeur' => 'ValeurDeclenchement', 'debut' => 'Debut', 'fin' => 'Fin',
                 'etat' => 'Etat', 'acquittee' => 'Acquittee', 'demande_id' => 'DemandeId',
                 'point_nom' => 'PointNom', 'point_unite' => 'PointUnite', 'point_decimales' => 'PointDecimales',
                 'equipement_id' => 'EquipementId', 'batiment_id' => 'BatimentId',
                 'batiment_nom' => 'BatimentNom'];
        return array_map(function ($r) use ($noms) {
            $o = [];
            foreach ($noms as $cle => $colonne) $o[$cle] = self::v($r, $colonne);
            return $o;
        }, $l);
    }

    private static function passeFiltre(array $a, array $si, ?array $points): bool
    {
        if (!empty($si['gravites']) && !in_array(self::gravite($a['gravite']), $si['gravites'], true)) return false;
        if (!empty($si['origines']) && !in_array((string)$a['origine'], $si['origines'], true)) return false;
        if ($points !== null && !isset($points[(int)$a['point_id']])) return false;
        return true;
    }

    private static function gravite(mixed $g): string
    {
        $g = strtolower((string)$g);
        return in_array($g, ['critique', 'majeure', 'mineure', 'info'], true) ? $g : 'mineure';
    }

    /**
     * Valeurs d'une ligne du registre, champ par champ, d'après la
     * correspondance déclarée. Chaque valeur est mise au TYPE du champ qui la
     * reçoit : la table du module ne contient jamais que ce que son propre
     * formulaire aurait accepté.
     */
    private static function valeursPour(array $a, array $correspondance, array $champs): array
    {
        $out = [];
        foreach ($correspondance as $champ => $attr) {
            $def  = $champs[$champ] ?? [];
            $type = (string)($def['type'] ?? 'texte');
            $brut = match ($attr) {
                'alarme'       => (int)$a['id'],
                'point'        => $a['point_id'] !== null ? (int)$a['point_id'] : null,
                'connexion'    => $a['passerelle_id'] !== null ? (int)$a['passerelle_id'] : null,
                'batiment'     => $a['batiment_id'] !== null ? (int)$a['batiment_id'] : null,
                'equipement'   => $a['equipement_id'] !== null ? (int)$a['equipement_id'] : null,
                'point_nom'    => $a['point_nom'],
                'batiment_nom' => $a['batiment_nom'],
                'gravite'      => self::gravite($a['gravite']),
                'origine'      => (string)$a['origine'],
                'etat'         => (string)$a['etat'] === 'active' ? 'active' : 'terminee',
                'message'      => (string)$a['message'],
                // Arrondie comme le point l'affiche : 1 472 ppm, pas 1 472,4807.
                'valeur'       => $a['valeur'] !== null
                                    ? round((float)$a['valeur'], max(0, min(6, (int)($a['point_decimales'] ?? 2))))
                                    : null,
                'unite'        => $a['point_unite'],
                'debut'        => $a['debut'],
                'fin'          => $a['fin'],
                'duree'        => ($a['fin'] && $a['debut'])
                                    ? max(0, (int)round((strtotime((string)$a['fin'])
                                                       - strtotime((string)$a['debut'])) / 60))
                                    : null,
                'acquittee'    => (int)$a['acquittee'] === 1 ? 1 : 0,
                'demande'      => $a['demande_id'] !== null ? (int)$a['demande_id'] : null,
                default        => null,
            };
            $out[$champ] = self::auType($brut, $type, $def);
        }
        return $out;
    }

    /**
     * Valeurs par défaut des champs que l'alarme ne remplit pas.
     *
     * Une ligne inscrite par Larka doit ressembler à une ligne saisie : un
     * « Suivi » déclaré « À analyser » par défaut le reste, sinon la tuile
     * « dépassements à analyser » compterait zéro pendant que le registre se
     * remplit. Les défauts dynamiques ({{today}}) sont évalués comme à la
     * saisie.
     */
    private static function defauts(array $champs, array $correspondance): array
    {
        $out = [];
        foreach ($champs as $cle => $c) {
            if (isset($correspondance[$cle]) || !empty($c['calcule'])) continue;
            if (in_array($c['type'] ?? '', ['formule', 'mesure'], true)) continue;
            if (!array_key_exists('defaut', $c)) continue;
            $v = $c['defaut'];
            if (isset($c['defaut_arbre'])) {
                try {
                    $v = ExtExpression::evaluer($c['defaut_arbre'], [
                        'today' => date('Y-m-d'), 'user' => ['nom' => self::AUTEUR]]);
                } catch (\Throwable $e) { continue; }
            }
            $out[$cle] = self::auType($v, (string)($c['type'] ?? 'texte'), $c);
        }
        return $out;
    }

    private static function auType(mixed $v, string $type, array $def): mixed
    {
        if ($v === null || $v === '') return $type === 'booleen' ? 0 : null;
        return match ($type) {
            'entier', 'duree', 'lien' => (int)$v,
            'booleen'                 => $v ? 1 : 0,
            'decimal'                 => (float)$v,
            'horodatage'              => ($t = strtotime((string)$v)) !== false ? date('Y-m-d H:i', $t) : null,
            'date'                    => ($t = strtotime((string)$v)) !== false ? date('Y-m-d', $t) : null,
            'texte', 'texte_long', 'choix' =>
                mb_substr(is_float($v) ? rtrim(rtrim(number_format($v, 6, ',', ''), '0'), ',') : (string)$v,
                          0, (int)($def['max'] ?? ($type === 'texte_long' ? 5000 : 500))),
            default                   => (string)$v,
        };
    }

    // ── État de la réception : repère du dernier numéro lu, par organisation ──

    private static function fichierEtat(): string
    {
        require_once __DIR__ . '/../Paquet.php';
        return ExtPaquet::dossierEtat() . '/connect-alarmes.json';
    }

    private static function lireEtat(): array
    {
        $f = self::fichierEtat();
        if (!is_file($f)) return [];
        $e = json_decode((string)@file_get_contents($f), true);
        return is_array($e) ? $e : [];
    }

    private static function ecrireEtat(callable $modifier): void
    {
        $f = self::fichierEtat();
        $e = $modifier(self::lireEtat());
        @file_put_contents($f, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
