<?php
/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka — Import du stock (Excel / CSV), action import_stock
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Même principe que l'import des biens et des interventions : l'écran lit le
 *  fichier et propose les correspondances ; le serveur REFAIT tous les
 *  contrôles, parce qu'une requête peut être forgée sans passer par l'écran.
 *
 *  Ce qu'il fait :
 *   - Référence obligatoire ; désignation obligatoire pour un NOUVEL article,
 *     comme à la saisie (un bon de livraison « référence + quantité » suffit
 *     à mettre à jour les articles déjà là) ;
 *   - un article déjà là est mis à jour — seules les colonnes du fichier
 *     remplacent celles de la fiche — ou laissé. Il est reconnu à sa
 *     référence, et à son type quand la ligne en donne un (la fiche prévoit
 *     « même référence, technologie différente ») ; plusieurs articles sous
 *     une référence et pas de type dans la ligne : erreur, pas de hasard ;
 *   - la quantité du fichier REMPLACE celle de la fiche (un inventaire) ou
 *     s'y AJOUTE (une livraison) ; jamais négative ;
 *   - une catégorie nouvelle est proposée à l'ajout dans la liste, décochable ;
 *   - un contrat (n°, référence ou société) passe l'article en « fourni par
 *     contrat » ; introuvable, il est signalé, pas inventé ;
 *   - les colonnes sans champ Larka deviennent des cases des fiches du stock.
 *
 *  L'aperçu (dryRun) n'écrit rien. L'import se fait dans une transaction,
 *  ligne par ligne (une ligne en échec n'emporte pas les autres).
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

require_once __DIR__ . '/ImportOutils.php';

final class ImportStock
{
    public const MAX_LIGNES = 10000;

    /** Champs importables : clé => libellé (pour les messages du rapport). */
    public const CHAMPS = [
        'reference' => 'Référence', 'designation' => 'Désignation', 'categorie' => 'Catégorie', 'marque' => 'Marque',
        'modele' => 'Modèle', 'typeArticle' => "Type d'article", 'quantite' => 'Quantité', 'seuilAlerte' => "Seuil d'alerte",
        'prixUnitaire' => 'Prix unitaire', 'emplacement' => 'Emplacement', 'fournisseur' => 'Fournisseur',
        'source' => 'Source', 'contrat' => 'Contrat',
    ];
    private const TEXTES = ['reference' => 100, 'designation' => 300, 'marque' => 100, 'modele' => 100, 'typeArticle' => 100,
                            'emplacement' => 200, 'fournisseur' => 200];
    /** Vocabulaire fermé des sources (comme la fiche). */
    public const SOURCES = ['Achat', 'Contrat'];
    private const SYNONYMES_SOURCE = [
        'Achat'   => ['achat', 'achete', 'achetee', 'budget', 'budget propre', 'propre', 'commande', 'acquisition'],
        'Contrat' => ['contrat', 'fourni par contrat', 'fourni contrat', 'marche', 'prestataire', 'contrat de maintenance'],
    ];
    private const CATEGORIE_DEFAUT = 'Consommable';

    public function __construct(private Database $db, private string $login) {}

    /**
     * Analyse (dryRun) ou importe. $corps : items, dryRun, dedup (update|skip),
     * quantites (remplacer|ajouter), champsMappes, listesNePasCreer
     * {CategorieStock: [...]}, champsPersoNouveaux [{cle, libelle, type}] ;
     * items[].champsPerso {cle: valeur}.
     */
    public function executer(array $corps): array
    {
        $items = $corps['items'] ?? null;
        if (!is_array($items)) throw new InvalidArgumentException('Format invalide : « items » doit être une liste de lignes.');
        if (count($items) > self::MAX_LIGNES) throw new InvalidArgumentException('Trop de lignes (' . self::MAX_LIGNES . ' au plus par import — découpez le fichier).');
        $dryRun = !empty($corps['dryRun']);
        $dedup = ($corps['dedup'] ?? 'update') === 'skip' ? 'skip' : 'update';
        $quantites = ($corps['quantites'] ?? 'remplacer') === 'ajouter' ? 'ajouter' : 'remplacer';
        $mappes = array_values(array_intersect(array_keys(self::CHAMPS), array_filter((array)($corps['champsMappes'] ?? array_keys(self::CHAMPS)), 'is_string')));
        $nePasCreer = [];
        foreach ((array)(($corps['listesNePasCreer'] ?? [])['CategorieStock'] ?? []) as $v) $nePasCreer[ImportOutils::cle($v)] = true;

        // Cases complémentaires que l'import crée dans les fiches (celles qui manquent).
        $nouvellesCases = [];
        foreach ((array)($corps['champsPersoNouveaux'] ?? []) as $c) {
            if (!is_array($c) || !preg_match('/^[a-z0-9_]{1,60}$/', (string)($c['cle'] ?? '')) || trim((string)($c['libelle'] ?? '')) === '') continue;
            $nouvellesCases[] = ['cle' => (string)$c['cle'], 'libelle' => mb_substr(trim((string)$c['libelle']), 0, 80),
                                 'type' => in_array($c['type'] ?? '', Database::CHAMPS_PERSO_TYPES, true) ? $c['type'] : 'texte'];
            if (count($nouvellesCases) >= 100) break;
        }
        $defsAvant = $this->db->getChampsPersoDefs('stock');
        $clesExistantes = array_column($defsAvant, 'cle');
        $casesACreer = array_values(array_filter($nouvellesCases, fn($c) => !in_array($c['cle'], $clesExistantes, true)));

        $ref = $this->referentiel();
        $lignes = []; $vus = []; $nouvellesCat = [];
        foreach (array_values($items) as $k => $b) {
            if (!is_array($b)) continue;
            $l = $this->analyserLigne($b, $k, $ref, $mappes, $quantites);
            if (!$l['erreurs']) {
                if (isset($vus[$l['cleArticle']])) {
                    $l['erreurs'][] = ['champ' => 'Référence', 'message' => "article déjà présent ligne {$vus[$l['cleArticle']]} du fichier : ligne ignorée"];
                } else $vus[$l['cleArticle']] = $l['row'];
            }
            if ($l['erreurs']) $l['action'] = 'erreur';
            elseif ($l['action'] === 'maj' && $dedup === 'skip') $l['action'] = 'laisse';
            if (in_array($l['action'], ['creer', 'maj'], true) && $l['nouvelleCategorie'] && empty($nePasCreer[ImportOutils::cle($l['donnees']['categorie'])])) {
                $nouvellesCat[ImportOutils::cle($l['donnees']['categorie'])] = $l['donnees']['categorie'];
            }
            $lignes[] = $l;
        }
        $listesOut = $nouvellesCat ? ['CategorieStock' => array_values($nouvellesCat)] : [];

        $compte = static fn(string $a) => count(array_filter($lignes, fn($l) => $l['action'] === $a));
        $rapport = [
            'aCreer' => $compte('creer'), 'aMettreAJour' => $compte('maj'), 'laissees' => $compte('laisse'),
            'enErreur' => $compte('erreur'), 'total' => count($lignes), 'quantites' => $quantites,
            'listesACreer' => $listesOut,
            'casesACreer' => array_column($casesACreer, 'libelle'),
            'casesRessemblances' => $casesACreer ? $this->db->champsPersoRessemblances('stock', array_merge($defsAvant, $casesACreer)) : [],
            'lignes' => array_map(fn($l) => ['row' => $l['row'], 'action' => $l['action'],
                'reference' => $l['donnees']['reference'] ?? '', 'designation' => mb_substr((string)($l['donnees']['designation'] ?? ''), 0, 120),
                'categorie' => $l['donnees']['categorie'] ?? '', 'quantiteAvant' => $l['quantiteAvant'], 'quantite' => $l['quantiteApres'],
                'erreurs' => $l['erreurs'], 'avert' => $l['avert']], $lignes),
        ];
        if ($dryRun) return $rapport;
        return $this->importer($lignes, $listesOut, $mappes, $casesACreer) + ['listesACreer' => $listesOut, 'total' => count($lignes)];
    }

    // ══ Référentiel : ce qui existe déjà ═══════════════════════════════════

    private function referentiel(): array
    {
        $r = ['articles' => [], 'categories' => [], 'contratNum' => [], 'contratRef' => [], 'contratSoc' => []];
        foreach ($this->db->fetchAll("SELECT * FROM Stock") as $s) {
            if (trim((string)($s['Reference'] ?? '')) === '') continue;
            // Rangés par référence : le type ne départage qu'ensuite (voir analyserLigne).
            $r['articles'][ImportOutils::cle($s['Reference'])][] = $s;
        }
        foreach ($this->db->fetchAll("SELECT Valeur FROM Listes WHERE Categorie = 'CategorieStock'") as $c) {
            $r['categories'][ImportOutils::cle($c['Valeur'])] = (string)$c['Valeur'];
        }
        try { $contrats = $this->db->fetchAll("SELECT Id, Numero, Societe, ReferenceContrat FROM Contrats"); }
        catch (\Throwable $e) { $contrats = $this->db->fetchAll("SELECT Id, Numero, Societe FROM Contrats"); }
        foreach ($contrats as $c) {
            if (trim((string)$c['Numero']) !== '') $r['contratNum'][ImportOutils::cle($c['Numero'])] = (int)$c['Id'];
            if (trim((string)($c['ReferenceContrat'] ?? '')) !== '') $r['contratRef'][ImportOutils::cle($c['ReferenceContrat'])] = (int)$c['Id'];
            if (trim((string)$c['Societe']) !== '') $r['contratSoc'][ImportOutils::cle($c['Societe'])][] = (int)$c['Id'];
        }
        return $r;
    }

    /** Identité d'un article : référence + type d'article (« même douille, technologie différente »). */
    private static function cleArticle(string $reference, string $type): string
    {
        return ImportOutils::cle($reference) . '|' . ImportOutils::cle($type);
    }

    /**
     * Article déjà là pour cette ligne : [fiche|null, erreur|null].
     *
     * La fiche prévoit plusieurs articles sous une même référence, distingués
     * par leur TYPE (« même douille, technologie différente »). Mais la plupart
     * des fichiers n'ont pas de colonne Type : exiger le type pour reconnaître
     * un article aurait recréé en double chaque article typé. Donc :
     *   - la ligne donne un type → l'article de cette référence ET de ce type ;
     *   - elle n'en donne pas → l'article de cette référence s'il est SEUL ;
     *     s'ils sont plusieurs, on ne choisit pas au hasard : erreur expliquée.
     */
    private static function articleExistant(array $ref, string $reference, string $type): array
    {
        $memeRef = $ref['articles'][ImportOutils::cle($reference)] ?? [];
        if (!$memeRef) return [null, null];
        if (ImportOutils::cle($type) !== '') {
            $memeType = array_values(array_filter($memeRef, fn($s) => ImportOutils::cle($s['TypeArticle'] ?? '') === ImportOutils::cle($type)));
            if (count($memeType) === 1) return [$memeType[0], null];
            if (count($memeType) > 1) return [null, count($memeType) . ' articles portent déjà cette référence et ce type : ligne ignorée (impossible de savoir lequel mettre à jour)'];
            return [null, null];   // même référence, autre type : un article distinct
        }
        if (count($memeRef) === 1) return [$memeRef[0], null];
        $types = array_map(fn($s) => trim((string)($s['TypeArticle'] ?? '')) ?: '(sans type)', $memeRef);
        return [null, count($memeRef) . ' articles portent cette référence (types : ' . implode(', ', array_unique($types))
                      . ') : ligne ignorée — précisez le type d\'article'];
    }

    private static function source(string $v): ?string
    {
        $k = ImportOutils::cle($v);
        if ($k === '') return '';
        foreach (self::SYNONYMES_SOURCE as $val => $syn) if ($k === ImportOutils::cle($val) || in_array($k, $syn, true)) return $val;
        return null;
    }

    /** Entier positif ou nul ; [valeur, avertissement|null]. Vide → null (colonne absente pour cette ligne). */
    private static function entier(mixed $brut, string $champ): array
    {
        $s = trim((string)($brut ?? ''));
        if ($s === '') return [null, null];
        $n = ImportOutils::nombre($s);
        if ($n === null) return [null, ['champ' => $champ, 'valeur' => mb_substr($s, 0, 40), 'message' => 'nombre non reconnu : valeur de la fiche gardée (0 pour un nouvel article)']];
        if ($n < 0) return [0, ['champ' => $champ, 'valeur' => $s, 'message' => 'négatif : ramené à 0']];
        $e = (int)round($n);
        if (abs($n - $e) > 1e-9) return [$e, ['champ' => $champ, 'valeur' => $s, 'message' => "pas un nombre entier : arrondi à $e"]];
        return [$e, null];
    }

    // ══ Une ligne ══════════════════════════════════════════════════════════

    private function analyserLigne(array $b, int $k, array $ref, array $mappes, string $quantites): array
    {
        $row = (int)($b['_row'] ?? ($k + 2));
        $err = []; $av = [];
        $d = [];
        foreach (self::TEXTES as $c => $max) $d[$c] = ImportOutils::texte($b[$c] ?? '', $max);
        if ($d['reference'] === '') $err[] = ['champ' => 'Référence', 'message' => 'référence vide : ligne ignorée (elle est obligatoire, comme à la saisie)'];
        // La désignation n'est exigée que d'un NOUVEL article (plus bas) : un bon
        // de livraison « référence + quantité » met à jour les articles déjà là.

        // Catégorie : valeur de la liste (comparée sans accents ni casse), sinon nouvelle.
        $catBrute = ImportOutils::texte($b['categorie'] ?? '', 100);
        $nouvelleCat = false;
        if ($catBrute === '') $d['categorie'] = '';
        elseif (isset($ref['categories'][ImportOutils::cle($catBrute)])) $d['categorie'] = $ref['categories'][ImportOutils::cle($catBrute)];
        else { $d['categorie'] = $catBrute; $nouvelleCat = true; }

        // Nombres
        [$qte, $a] = self::entier($b['quantite'] ?? '', 'Quantité');           if ($a) $av[] = $a;
        [$seuil, $a] = self::entier($b['seuilAlerte'] ?? '', "Seuil d'alerte"); if ($a) $av[] = $a;
        $prix = null;
        if (trim((string)($b['prixUnitaire'] ?? '')) !== '') {
            $p = ImportOutils::nombre($b['prixUnitaire']);
            if ($p === null) $av[] = ['champ' => 'Prix unitaire', 'valeur' => mb_substr((string)$b['prixUnitaire'], 0, 40), 'message' => 'prix non reconnu : valeur de la fiche gardée (0 pour un nouvel article)'];
            elseif ($p < 0) { $av[] = ['champ' => 'Prix unitaire', 'valeur' => (string)$p, 'message' => 'prix négatif : ramené à 0']; $prix = 0.0; }
            else $prix = round($p, 4);
        }

        // Source et contrat
        $brutSource = trim((string)($b['source'] ?? ''));
        $source = self::source($brutSource);
        if ($source === null) { $av[] = ['champ' => 'Source', 'valeur' => mb_substr($brutSource, 0, 40), 'message' => 'source inconnue (Achat ou Contrat) : non reprise']; $source = ''; }
        $contratId = null;
        $cb = ImportOutils::texte($b['contrat'] ?? '', 200);
        if ($cb !== '') {
            $kc = ImportOutils::cle($cb);
            $contratId = $ref['contratNum'][$kc] ?? $ref['contratRef'][$kc] ?? null;
            if (!$contratId && isset($ref['contratSoc'][$kc])) {
                if (count($ref['contratSoc'][$kc]) === 1) $contratId = $ref['contratSoc'][$kc][0];
                else $av[] = ['champ' => 'Contrat', 'valeur' => $cb, 'message' => count($ref['contratSoc'][$kc]) . ' contrats pour cette société : non rattaché (indiquez le n° de contrat)'];
            } elseif (!$contratId) {
                $av[] = ['champ' => 'Contrat', 'valeur' => $cb, 'message' => 'contrat introuvable : non rattaché'];
            }
            if ($contratId) $source = 'Contrat';
        }
        if ($source === 'Contrat' && !$contratId && $cb === '') {
            $av[] = ['champ' => 'Source', 'valeur' => 'Contrat', 'message' => 'fourni par contrat, sans contrat indiqué'];
        }

        // Existe déjà ?
        $existante = null; $action = 'creer';
        if (!$err) {
            [$existante, $pb] = self::articleExistant($ref, $d['reference'], $d['typeArticle']);
            if ($pb) $err[] = ['champ' => 'Référence', 'message' => $pb];
            elseif ($existante) $action = 'maj';
        }
        // Doublons du fichier : l'article reconnu (son Id), sinon référence + type.
        $cle = $existante ? 'id:' . (int)$existante['Id'] : self::cleArticle($d['reference'], $d['typeArticle']);
        if (!$existante && !$err && $d['designation'] === '') {
            $err[] = ['champ' => 'Désignation', 'message' => 'désignation vide pour un nouvel article : ligne ignorée (elle est obligatoire, comme à la saisie)'];
            $action = 'erreur';
        }
        $avant = $existante ? (int)($existante['Quantite'] ?? 0) : null;
        $apres = $qte === null ? ($avant ?? 0) : ($existante && $quantites === 'ajouter' ? $avant + $qte : $qte);

        // Valeurs des cases complémentaires (à part : elles passent par setChampsPersoValeurs).
        $cp = [];
        foreach ((array)($b['champsPerso'] ?? []) as $ck => $v) {
            if (!is_string($ck) || !preg_match('/^[a-z0-9_]{1,60}$/', $ck) || !is_scalar($v)) continue;
            $v = trim((string)$v);
            if ($v !== '') $cp[$ck] = mb_substr($v, 0, 2000);
        }

        return ['row' => $row, 'donnees' => $d + ['quantite' => $qte, 'seuilAlerte' => $seuil, 'prixUnitaire' => $prix, 'source' => $source, 'contratId' => $contratId],
                'champsPerso' => $cp, 'erreurs' => $err, 'avert' => $av, 'action' => $action, 'existante' => $existante, 'cleArticle' => $cle,
                'quantiteAvant' => $avant, 'quantiteApres' => $apres, 'nouvelleCategorie' => $nouvelleCat];
    }

    // ══ Import ═════════════════════════════════════════════════════════════

    /** Fiche existante → charge utile complète de Database::updateStock. */
    private static function versPayload(array $r): array
    {
        return ['reference' => $r['Reference'] ?? '', 'designation' => $r['Designation'] ?? '', 'categorie' => $r['Categorie'] ?? '',
                'marque' => $r['Marque'] ?? '', 'modele' => $r['Modele'] ?? '', 'typeArticle' => $r['TypeArticle'] ?? '',
                'quantite' => (int)($r['Quantite'] ?? 0), 'seuilAlerte' => (int)($r['SeuilAlerte'] ?? 0), 'prixUnitaire' => (float)($r['PrixUnitaire'] ?? 0),
                'emplacement' => $r['Emplacement'] ?? '', 'fournisseur' => $r['Fournisseur'] ?? '', 'source' => $r['Source'] ?: 'Achat',
                'contratId' => $r['ContratId'] ?? null];
    }

    private function importer(array $lignes, array $listes, array $mappes, array $casesACreer): array
    {
        $pdo = $this->db->getPdo();
        $cree = 0; $maj = 0; $echecs = []; $listesAjoutees = 0; $casesCreees = 0;
        try { $this->db->resyncAllPgSequences(); } catch (\Throwable $e) {}
        $pdo->beginTransaction();
        try {
            foreach ($listes as $cat => $vals) foreach ($vals as $v) {
                try { $this->db->addListeValeur($cat, $v, 0, 0, 0); $listesAjoutees++; } catch (\Throwable $e) {}
            }
            $utiles = [];
            foreach ($lignes as $l) if (in_array($l['action'], ['creer', 'maj'], true)) foreach ($l['champsPerso'] as $k => $_) $utiles[$k] = true;
            $casesACreer = array_values(array_filter($casesACreer, fn($c) => isset($utiles[$c['cle']])));
            if ($casesACreer) { $this->db->ajouterChampsPersoDefs('stock', $casesACreer, $this->login); $casesCreees = count($casesACreer); }
            $clesCases = array_flip(array_column($this->db->getChampsPersoDefs('stock'), 'cle'));

            $n = 0;
            foreach ($lignes as $l) {
                if (!in_array($l['action'], ['creer', 'maj'], true)) continue;
                $sp = 'sp_is_' . (++$n);
                $pdo->exec("SAVEPOINT $sp");
                try {
                    $d = $l['donnees'];
                    if ($l['action'] === 'maj') {
                        // Seules les colonnes lues dans le fichier remplacent celles de la fiche.
                        $p = self::versPayload($l['existante']);
                        foreach ($mappes as $c) {
                            if ($c === 'contrat') { if ($d['contratId']) { $p['contratId'] = $d['contratId']; $p['source'] = 'Contrat'; } continue; }
                            if ($c === 'quantite') { if ($d['quantite'] !== null) $p['quantite'] = $l['quantiteApres']; continue; }
                            if (in_array($c, ['seuilAlerte', 'prixUnitaire'], true)) { if ($d[$c] !== null) $p[$c] = $d[$c]; continue; }
                            if ($c === 'source') { if ($d['source'] !== '') $p['source'] = $d['source']; continue; }
                            if ($c === 'categorie') { if ($d['categorie'] !== '') $p['categorie'] = $d['categorie']; continue; }
                            if ($c === 'designation') { if ($d['designation'] !== '') $p['designation'] = $d['designation']; continue; }
                            if ($c === 'typeArticle') { if ($d['typeArticle'] !== '') $p['typeArticle'] = $d['typeArticle']; continue; }
                            if (array_key_exists($c, $d)) $p[$c] = $d[$c];
                        }
                        if ($p['source'] !== 'Contrat') $p['contratId'] = null;
                        $id = (int)$l['existante']['Id'];
                        $this->db->updateStock($id, $p, $this->login);
                        $maj++;
                    } else {
                        $p = ['reference' => $d['reference'], 'designation' => $d['designation'],
                              'categorie' => $d['categorie'] !== '' ? $d['categorie'] : self::CATEGORIE_DEFAUT,
                              'marque' => $d['marque'], 'modele' => $d['modele'], 'typeArticle' => $d['typeArticle'],
                              'quantite' => $d['quantite'] ?? 0, 'seuilAlerte' => $d['seuilAlerte'] ?? 0, 'prixUnitaire' => $d['prixUnitaire'] ?? 0,
                              'emplacement' => $d['emplacement'], 'fournisseur' => $d['fournisseur'],
                              'source' => $d['source'] !== '' ? $d['source'] : 'Achat', 'contratId' => $d['contratId']];
                        if ($p['source'] !== 'Contrat') $p['contratId'] = null;
                        $id = $this->db->addStock($p, $this->login);
                        $cree++;
                    }
                    $vals = array_intersect_key($l['champsPerso'], $clesCases);
                    if ($vals) $this->db->setChampsPersoValeurs('stock', $id, $vals);
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
        $laisse = count(array_filter($lignes, fn($l) => $l['action'] === 'laisse'));
        $err = count(array_filter($lignes, fn($l) => $l['action'] === 'erreur'));
        if (class_exists('Journal')) {
            Journal::info('stock', "Import du stock par {$this->login} : $cree article(s) créé(s), $maj mis à jour, $laisse laissé(s), $err en erreur"
                . ($echecs ? ', ' . count($echecs) . ' en échec' : ''), ['action' => 'import_stock', 'code' => $echecs ? 'BDD-004' : null]);
        }
        return ['crees' => $cree, 'misesAJour' => $maj, 'laissees' => $laisse, 'enErreur' => $err, 'echecs' => $echecs,
                'listesAjoutees' => $listesAjoutees, 'casesCreees' => $casesCreees];
    }
}
