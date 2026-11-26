<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote « fichiers CSV » (GTB anciennes, exports de
 * télérelève, extractions de superviseur).
 *
 * Beaucoup de GTB, surtout dans le public, ne savent que déposer un fichier
 * dans un dossier. Leurs pièges habituels sont gérés ici :
 *   - encodage Windows (cp1252) ou UTF-8 avec ou sans BOM ;
 *   - séparateur « ; », « , » ou tabulation, virgule décimale ;
 *   - lignes d'en-tête parasites avant les noms de colonnes ;
 *   - fichier en cours d'écriture : lu seulement une fois stable ;
 *   - fichier tronqué : lignes complètes gardées, fichier signalé incomplet ;
 *   - valeur non numérique (#####, N/A) : point en défaut, pas de chiffre inventé ;
 *   - fichier en double : déjà lu (même empreinte), ignoré.
 * Deux formats : « long » (une ligne par valeur : date ; repère ; valeur) et
 * « large » (une ligne par instant, une colonne par point).
 *
 * SÉCURITÉ : le dossier lu doit se trouver sous une des racines autorisées
 * par l'administrateur du SERVEUR (config.json, connect.csv_racines ; défaut :
 * data/connect/imports). Sans cette borne, un gestionnaire pourrait faire lire
 * à Larka n'importe quel fichier du serveur et en voir le contenu à l'écran.
 */

declare(strict_types=1);

final class ConnectPiloteCsv extends ConnectPiloteBase
{
    /** Fichiers lus pendant la dernière collecte, pour que la collecte les note. */
    private array $lus = [];

    public static function description(): array
    {
        return [
            'protocole' => 'csv',
            'libelle' => 'Fichiers CSV déposés',
            'resume' => 'GTB qui exportent des fichiers dans un dossier (partage réseau, FTP synchronisé).',
            'collecte' => 'fichier',
            'port_defaut' => null,
            'intervalle_defaut' => 300,
            'hote' => null,
            'identifiant' => null,
            'champs' => [
                ['cle' => 'dossier', 'libelle' => 'Dossier des fichiers', 'type' => 'texte', 'requis' => true,
                 'aide' => 'Sous une racine autorisée par l\'administrateur du serveur (par défaut data/connect/imports).'],
                ['cle' => 'motif', 'libelle' => 'Noms de fichiers', 'type' => 'texte', 'defaut' => '*.csv', 'aide' => 'Ex. GTB_EXPORT_*.csv'],
                ['cle' => 'format', 'libelle' => 'Disposition', 'type' => 'choix', 'defaut' => 'long',
                 'options' => [['long', 'Une ligne par valeur (repère, valeur)'], ['large', 'Une colonne par point']]],
                ['cle' => 'encodage', 'libelle' => 'Encodage', 'type' => 'choix', 'defaut' => 'auto',
                 'options' => [['auto', 'Détection automatique'], ['utf-8', 'UTF-8'], ['windows-1252', 'Windows (cp1252)'], ['iso-8859-1', 'ISO-8859-1']]],
                ['cle' => 'separateur', 'libelle' => 'Séparateur', 'type' => 'choix', 'defaut' => 'auto',
                 'options' => [['auto', 'Détection automatique'], [';', 'Point-virgule'], [',', 'Virgule'], ['tab', 'Tabulation']]],
                ['cle' => 'col_repere', 'libelle' => 'Colonne du repère (format long)', 'type' => 'texte', 'defaut' => 'Repère'],
                ['cle' => 'col_valeur', 'libelle' => 'Colonne de la valeur (format long)', 'type' => 'texte', 'defaut' => 'Valeur'],
                ['cle' => 'col_date', 'libelle' => 'Colonne de la date', 'type' => 'texte', 'defaut' => 'Date'],
                ['cle' => 'col_heure', 'libelle' => 'Colonne de l\'heure (si séparée)', 'type' => 'texte', 'defaut' => 'Heure'],
                ['cle' => 'col_etat', 'libelle' => 'Colonne de l\'état (facultatif)', 'type' => 'texte', 'defaut' => 'Etat'],
                ['cle' => 'col_libelle', 'libelle' => 'Colonne du libellé (découverte)', 'type' => 'texte', 'defaut' => 'Libellé'],
                ['cle' => 'col_unite', 'libelle' => 'Colonne de l\'unité (découverte)', 'type' => 'texte', 'defaut' => 'Unité'],
                ['cle' => 'format_date', 'libelle' => 'Format de date', 'type' => 'choix', 'defaut' => 'd/m/Y',
                 'options' => [['d/m/Y', 'JJ/MM/AAAA'], ['Y-m-d', 'AAAA-MM-JJ'], ['m/d/Y', 'MM/JJ/AAAA'], ['d.m.Y', 'JJ.MM.AAAA']]],
                ['cle' => 'etats_normaux', 'libelle' => 'États considérés comme normaux', 'type' => 'texte', 'defaut' => 'Normal,OK,Bon',
                 'aide' => 'Tout autre état rend la mesure douteuse (et peut déclencher une règle d\'alarme).'],
                ['cle' => 'stabilite_secondes', 'libelle' => 'Attendre qu\'un fichier soit stable depuis (s)', 'type' => 'nombre', 'defaut' => 10],
                ['cle' => 'age_max_jours', 'libelle' => 'Au premier passage, ignorer les fichiers plus vieux que (jours)', 'type' => 'nombre', 'defaut' => 7],
            ],
            'secrets' => [],
            'adresse' => [
                ['cle' => 'repere', 'libelle' => 'Repère (format long)', 'type' => 'texte', 'aide' => 'Ex. GYM_TAMB'],
                ['cle' => 'colonne', 'libelle' => 'Colonne (format large)', 'type' => 'texte'],
            ],
            'ecriture' => false,
            'decouverte' => true,
        ];
    }

    // ── Racines autorisées ─────────────────────────────────────────────────

    /** Racines où un dossier d'import peut se trouver (chemins réels). */
    public static function racines(): array
    {
        $larka = dirname(__DIR__, 3);
        $cfg = function_exists('cfg') ? cfg('connect', 'csv_racines') : null;
        $liste = is_array($cfg) && $cfg ? $cfg : ['data/connect/imports'];
        $out = [];
        foreach ($liste as $r) {
            $r = trim((string)$r);
            if ($r === '') continue;
            $abs = (function_exists('larka_chemin_absolu') && larka_chemin_absolu($r)) ? $r : $larka . '/' . $r;
            if (str_ends_with(str_replace('\\', '/', $r), 'data/connect/imports')) @mkdir($abs, 0750, true);
            $reel = realpath($abs);
            if ($reel !== false && is_dir($reel)) $out[] = $reel;
        }
        return array_values(array_unique($out));
    }

    /** Dossier réel s'il est sous une racine autorisée, sinon [null, raison]. */
    public static function dossierAutorise(string $dossier): array
    {
        $dossier = trim($dossier);
        if ($dossier === '') return [null, 'Dossier non renseigné.'];
        if (str_contains($dossier, "\0")) return [null, 'Chemin invalide.'];
        $larka = dirname(__DIR__, 3);
        $abs = (function_exists('larka_chemin_absolu') && larka_chemin_absolu($dossier)) ? $dossier : $larka . '/' . $dossier;
        $reel = realpath($abs);
        if ($reel === false || !is_dir($reel)) return [null, "Le dossier « $dossier » n'existe pas (ou n'est pas lisible par le serveur)."];
        foreach (self::racines() as $racine) {
            if ($reel === $racine || str_starts_with($reel, rtrim($racine, '/\\') . DIRECTORY_SEPARATOR)) return [$reel, null];
        }
        return [null, "Le dossier « $dossier » est hors des racines autorisées. L'administrateur du serveur peut en ajouter "
            . "dans config.json (connect.csv_racines). Racines actuelles : " . implode(', ', array_map([self::class, 'relatif'], self::racines())) . '.'];
    }

    private static function relatif(string $chemin): string
    {
        $larka = realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3);
        return str_starts_with($chemin, $larka . DIRECTORY_SEPARATOR) ? substr($chemin, strlen($larka) + 1) : $chemin;
    }

    // ── Analyse d'un fichier ───────────────────────────────────────────────

    /** Texte UTF-8 du fichier, et l'encodage retenu. */
    private function texte(string $brut): array
    {
        $enc = (string)$this->param('encodage', 'auto');
        if (str_starts_with($brut, "\xEF\xBB\xBF")) { $brut = substr($brut, 3); $enc = 'utf-8'; }
        if ($enc === 'auto') $enc = mb_check_encoding($brut, 'UTF-8') ? 'utf-8' : 'windows-1252';
        if ($enc !== 'utf-8') $brut = (string)@mb_convert_encoding($brut, 'UTF-8', $enc === 'windows-1252' ? 'Windows-1252' : 'ISO-8859-1');
        return [$brut, $enc];
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s, " \t\"'"));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return preg_replace('/[^a-z0-9]/', '', $t !== false ? $t : $s) ?? $s;
    }

    /**
     * Analyse : lignes de données sous forme de tableaux associatifs.
     * @return array{ok: bool, erreur?: string, encodage: string, separateur: string, entete: int,
     *               colonnes: list<string>, lignes: list<array>, incompletes: int, avertissements: list<string>}
     */
    public function analyser(string $brut): array
    {
        [$txt, $enc] = $this->texte($brut);
        $lignes = preg_split('/\r\n|\n|\r/', $txt) ?: [];
        while ($lignes && trim((string)end($lignes)) === '') array_pop($lignes);
        $sep = (string)$this->param('separateur', 'auto');
        if ($sep === 'tab') $sep = "\t";
        $format = (string)$this->param('format', 'long');
        $attendues = $format === 'long'
            ? [self::norm((string)$this->param('col_repere', 'Repère')), self::norm((string)$this->param('col_valeur', 'Valeur'))]
            : [self::norm((string)$this->param('col_date', 'Date'))];
        $entete = -1;
        foreach (array_slice($lignes, 0, 30) as $i => $l) {
            // Ligne vide (fréquente entre le bandeau d'un export et ses titres) :
            // str_getcsv('') rend [null], qu'on ne normalise pas.
            if (trim($l) === '') continue;
            $s = $sep === 'auto' ? self::devinerSeparateur($l) : $sep;
            $champs = array_map(fn($x) => self::norm((string)$x), str_getcsv($l, $s, '"', ''));
            if (count(array_intersect($attendues, $champs)) === count($attendues)) { $entete = $i; $sep = $s; break; }
        }
        $res = ['ok' => false, 'encodage' => $enc, 'separateur' => $sep === "\t" ? 'tab' : $sep, 'entete' => $entete,
                'colonnes' => [], 'lignes' => [], 'incompletes' => 0, 'avertissements' => []];
        if ($entete < 0) {
            $res['erreur'] = 'ligne de titres introuvable (colonnes attendues : ' . ($format === 'long'
                ? $this->param('col_repere', 'Repère') . ', ' . $this->param('col_valeur', 'Valeur') : $this->param('col_date', 'Date')) . ')';
            return $res;
        }
        if ($entete > 0) $res['avertissements'][] = "$entete ligne(s) avant les titres, ignorée(s)";
        if ($enc !== 'utf-8') $res['avertissements'][] = "encodage $enc converti en UTF-8";
        $cols = str_getcsv($lignes[$entete], $sep, '"', '');
        $res['colonnes'] = array_map('trim', $cols);
        $n = count($cols);
        for ($i = $entete + 1; $i < count($lignes); $i++) {
            if (trim($lignes[$i]) === '') continue;
            $c = str_getcsv($lignes[$i], $sep, '"', '');
            if (count($c) < $n) { $res['incompletes']++; continue; }     // ligne tronquée
            $res['lignes'][] = array_combine($res['colonnes'], array_slice($c, 0, $n));
        }
        if ($res['incompletes']) $res['avertissements'][] = $res['incompletes'] . ' ligne(s) incomplète(s) ignorée(s) (fichier tronqué ?)';
        $res['ok'] = true;
        return $res;
    }

    private static function devinerSeparateur(string $l): string
    {
        $comptes = [';' => substr_count($l, ';'), ',' => substr_count($l, ','), "\t" => substr_count($l, "\t")];
        arsort($comptes);
        return (string)array_key_first($comptes);
    }

    private function colonne(array $colonnes, string $voulue): ?string
    {
        $voulue = trim($voulue);
        if ($voulue === '') return null;
        if (preg_match('/^#(\d+)$/', $voulue, $m)) return $colonnes[(int)$m[1] - 1] ?? null;
        $n = self::norm($voulue);
        foreach ($colonnes as $c) if (self::norm($c) === $n) return $c;
        return null;
    }

    /** Horodatage d'une ligne (date + heure), ou null. */
    private function horodatage(array $ligne, array $colonnes, ?int $repli): ?string
    {
        $cd = $this->colonne($colonnes, (string)$this->param('col_date', 'Date'));
        $ch = $this->colonne($colonnes, (string)$this->param('col_heure', 'Heure'));
        $d = $cd !== null ? trim((string)$ligne[$cd]) : '';
        if ($d === '') return $repli ? date('Y-m-d H:i:s', $repli) : null;
        $h = $ch !== null ? trim((string)$ligne[$ch]) : '';
        $fmt = (string)$this->param('format_date', 'd/m/Y');
        $texte = trim($d . ' ' . $h);
        foreach ([$fmt . ' H:i:s', $fmt . ' H:i', $fmt] as $f) {
            $dt = DateTime::createFromFormat('!' . $f, $texte);
            if ($dt !== false) return $dt->format('Y-m-d H:i:s');
        }
        $t = strtotime(str_replace('/', '-', $texte));
        return $t !== false ? date('Y-m-d H:i:s', $t) : ($repli ? date('Y-m-d H:i:s', $repli) : null);
    }

    /** Valeurs par repère (long) ou par colonne (large) : [clé => [ [horo, valeur, texte, etat] … ]]. */
    private function valeurs(array $a, ?int $mtime): array
    {
        $out = [];
        $format = (string)$this->param('format', 'long');
        $normaux = array_map([self::class, 'norm'], explode(',', (string)$this->param('etats_normaux', 'Normal,OK,Bon')));
        $cr = $this->colonne($a['colonnes'], (string)$this->param('col_repere', 'Repère'));
        $cv = $this->colonne($a['colonnes'], (string)$this->param('col_valeur', 'Valeur'));
        $ce = $this->colonne($a['colonnes'], (string)$this->param('col_etat', 'Etat'));
        $exclues = array_filter([$this->colonne($a['colonnes'], (string)$this->param('col_date', 'Date')),
                                 $this->colonne($a['colonnes'], (string)$this->param('col_heure', 'Heure'))]);
        foreach ($a['lignes'] as $l) {
            $horo = $this->horodatage($l, $a['colonnes'], $mtime);
            if ($format === 'long') {
                if ($cr === null || $cv === null) break;
                $etat = $ce !== null ? trim((string)$l[$ce]) : '';
                $out[trim((string)$l[$cr])][] = [$horo, trim((string)$l[$cv]), $etat === '' || in_array(self::norm($etat), $normaux, true), $etat];
            } else {
                foreach ($l as $col => $val) {
                    if (in_array($col, $exclues, true)) continue;
                    $out[trim((string)$col)][] = [$horo, trim((string)$val), true, ''];
                }
            }
        }
        return $out;
    }

    // ── Fichiers à lire ────────────────────────────────────────────────────

    /** Fichiers stables du dossier, du plus ancien au plus récent. */
    private function fichiers(string $dossier): array
    {
        $motif = basename(trim((string)$this->param('motif', '*.csv')) ?: '*.csv');
        $liste = glob(rtrim($dossier, '/\\') . DIRECTORY_SEPARATOR . $motif) ?: [];
        $stable = max(0, (int)$this->param('stabilite_secondes', 10));
        $out = [];
        foreach ($liste as $f) {
            if (!is_file($f) || !is_readable($f)) continue;
            $st = @stat($f);
            if (!$st) continue;
            $out[] = ['chemin' => $f, 'nom' => basename($f), 'taille' => (int)$st['size'], 'mtime' => (int)$st['mtime'],
                      'stable' => (time() - (int)$st['mtime']) >= $stable];
        }
        usort($out, fn($x, $y) => [$x['mtime'], $x['nom']] <=> [$y['mtime'], $y['nom']]);
        return $out;
    }

    public function fichiersLus(): array
    {
        return $this->lus;
    }

    public function lire(array $points): array
    {
        $out = [];
        $this->lus = [];
        [$dossier, $raison] = self::dossierAutorise((string)$this->param('dossier', ''));
        if ($dossier === null) {
            foreach ($points as $p) $out[(int)$p['Id']] = self::enDefaut($raison);
            return $out;
        }
        $deja = is_array($this->c['_fichiers_traites'] ?? null) ? $this->c['_fichiers_traites'] : [];
        $premier = !$deja;
        $ageMax = max(0, (int)$this->param('age_max_jours', 7));
        $series = [];
        $nb = 0;
        foreach ($this->fichiers($dossier) as $f) {
            if (!$f['stable']) continue;                                     // encore en cours d'écriture
            if ($premier && $ageMax > 0 && $f['mtime'] < time() - $ageMax * 86400) continue;
            if ($f['taille'] > 20 * 1024 * 1024) { $this->lus[] = $f + ['statut' => 'rejete', 'message' => 'fichier trop volumineux (> 20 Mo)', 'empreinte' => sha1($f['nom'] . $f['taille'] . $f['mtime']), 'nb' => 0]; continue; }
            $brut = @file_get_contents($f['chemin']);
            if ($brut === false) continue;
            $empreinte = sha1($brut);
            if (isset($deja[$empreinte])) {
                // Même contenu sous un AUTRE nom (export en double) : noté une fois, jamais relu.
                $cleDoublon = $empreinte . '#' . $f['nom'];
                if ((string)$deja[$empreinte] !== $f['nom'] && !isset($deja[$cleDoublon])) {
                    $this->lus[] = $f + ['statut' => 'doublon', 'message' => 'contenu identique à « ' . $deja[$empreinte] . ' », déjà lu',
                                         'empreinte' => $cleDoublon, 'nb' => 0];
                    $deja[$cleDoublon] = $f['nom'];
                }
                continue;
            }
            $deja[$empreinte] = $f['nom'];
            if (++$nb > 50) break;                                           // le reste au passage suivant
            $a = $this->analyser($brut);
            if (!$a['ok']) {
                $this->lus[] = $f + ['statut' => 'rejete', 'message' => $a['erreur'] ?? 'illisible', 'empreinte' => $empreinte, 'nb' => 0];
                continue;
            }
            $vals = $this->valeurs($a, $f['mtime']);
            $compte = 0;
            foreach ($points as $p) {
                $adr = is_array($p['Adresse'] ?? null) ? $p['Adresse'] : [];
                $cle = trim((string)($adr[(string)$this->param('format', 'long') === 'long' ? 'repere' : 'colonne'] ?? ''));
                if ($cle === '' || !isset($vals[$cle])) continue;
                foreach ($vals[$cle] as [$horo, $val, $normal, $etat]) {
                    $v = self::nombre($val);
                    $series[(int)$p['Id']][] = ['valeur' => $v, 'texte' => $v === null ? $val : ($normal ? null : $etat),
                        'horodatage' => $horo, 'qualite' => ($v === null || !$normal) ? 'mauvaise' : 'bonne',
                        'erreur' => $v === null ? "valeur non numérique « $val »" : ($normal ? null : "état « $etat » signalé par la GTB")];
                    $compte++;
                }
            }
            $this->lus[] = $f + ['statut' => $a['incompletes'] ? 'incomplet' : 'importe',
                                 'message' => implode(' ; ', $a['avertissements']), 'empreinte' => $empreinte, 'nb' => $compte];
        }
        foreach ($series as $id => $s) {
            usort($s, fn($x, $y) => strcmp((string)$x['horodatage'], (string)$y['horodatage']));
            $dernier = array_pop($s);
            $r = self::valeurLue($dernier['valeur'], $dernier['texte'], $dernier['qualite'], $dernier['horodatage']);
            if ($dernier['erreur']) $r['erreur'] = $dernier['erreur'];
            $r['serie'] = $s;
            $out[$id] = $r;
        }
        return $out;
    }

    public function tester(array $points = []): array
    {
        $etapes = [];
        [$dossier, $raison] = self::dossierAutorise((string)$this->param('dossier', ''));
        if ($dossier === null) return [self::etape('reseau', false, (string)$raison)];
        $fichiers = $this->fichiers($dossier);
        if (!$fichiers) {
            return [self::etape('reseau', true, 'Dossier accessible : ' . self::relatif($dossier) . '.'),
                    self::etape('protocole', false, 'Aucun fichier « ' . $this->param('motif', '*.csv') . ' » dans ce dossier pour l\'instant.')];
        }
        $dernier = end($fichiers);
        $etapes[] = self::etape('reseau', true, 'Dossier accessible : ' . count($fichiers) . ' fichier(s), le plus récent du '
            . date('d/m/Y à H:i', $dernier['mtime']) . '.');
        $stables = array_values(array_filter($fichiers, fn($f) => $f['stable']));
        if (!$stables) return array_merge($etapes, [self::etape('protocole', false, 'Les fichiers sont encore en cours d\'écriture : réessayez dans quelques secondes.')]);
        $f = end($stables);
        if (!$dernier['stable']) $etapes[] = self::etape('donnees', null, "« {$dernier['nom']} » est en cours d'écriture : il sera lu au prochain passage.");
        $a = $this->analyser((string)@file_get_contents($f['chemin']));
        if (!$a['ok']) return array_merge($etapes, [self::etape('protocole', false, "« {$f['nom']} » : " . ucfirst((string)$a['erreur']) . '.')]);
        $vals = $this->valeurs($a, $f['mtime']);
        $etapes[] = self::etape('protocole', true, "« {$f['nom']} » compris : " . count($vals) . ' repère(s), séparateur « '
            . $a['separateur'] . ' »' . ($a['avertissements'] ? ' — ' . implode(' ; ', $a['avertissements']) : '') . '.');
        if ($points) {
            $cle = (string)$this->param('format', 'long') === 'long' ? 'repere' : 'colonne';
            $absents = [];
            foreach ($points as $p) {
                $r = trim((string)($p['Adresse'][$cle] ?? ''));
                if (!isset($vals[$r])) $absents[] = "« {$p['Nom']} » ($r)";
            }
            $etapes[] = self::etape('points', !$absents, $absents ? count($absents) . ' point(s) absent(s) du dernier fichier : ' . implode(', ', array_slice($absents, 0, 4))
                                                                  : count($points) . ' point(s) présents dans le dernier fichier.');
        }
        return $etapes;
    }

    public function decouvrir(): array
    {
        [$dossier] = self::dossierAutorise((string)$this->param('dossier', ''));
        if ($dossier === null) return [];
        $stables = array_values(array_filter($this->fichiers($dossier), fn($f) => $f['stable']));
        if (!$stables) return [];
        $f = end($stables);
        $a = $this->analyser((string)@file_get_contents($f['chemin']));
        if (!$a['ok']) return [];
        $format = (string)$this->param('format', 'long');
        $cl = $this->colonne($a['colonnes'], (string)$this->param('col_libelle', 'Libellé'));
        $cu = $this->colonne($a['colonnes'], (string)$this->param('col_unite', 'Unité'));
        $cr = $this->colonne($a['colonnes'], (string)$this->param('col_repere', 'Repère'));
        $props = [];
        foreach ($this->valeurs($a, $f['mtime']) as $cle => $serie) {
            $cle = (string)$cle;     // repère numérique (« 12 ») : PHP en a fait une clé entière
            $der = end($serie);
            $libelle = $cle; $unite = '';
            if ($format === 'long' && $cr !== null) {
                foreach ($a['lignes'] as $l) {
                    if (trim((string)$l[$cr]) !== $cle) continue;
                    if ($cl !== null && trim((string)$l[$cl]) !== '') $libelle = trim((string)$l[$cl]);
                    if ($cu !== null) $unite = trim((string)$l[$cu]);
                    break;
                }
            }
            $nature = preg_match('/index|compteur|cumul/i', $libelle . $cle) ? 'compteur' : 'mesure';
            $props[] = ['nom' => $libelle, 'code' => $cle, 'adresse' => [$format === 'long' ? 'repere' : 'colonne' => $cle],
                        'unite' => $unite, 'nature' => $nature, 'valeur' => self::nombre($der[1]) ?? $der[1]];
        }
        return $props;
    }
}
