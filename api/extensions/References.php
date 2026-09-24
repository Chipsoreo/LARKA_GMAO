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
 * Larka — Extensions : références entre fichiers d'un paquet
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * LE PROBLÈME
 * Un module sérieux finit avec un extension.json de deux mille lignes. Les
 * mêmes quinze champs d'adresse s'y répètent dans trois jeux, la même page se
 * décline en quatre variantes, et une correction doit être reportée à la main
 * partout. Le fichier devient impossible à relire — et un format dont l'argument
 * de sécurité est « vous pouvez le lire avant d'installer » ne peut pas se
 * permettre d'être illisible.
 *
 * LA RÉPONSE
 *   { "$ref": "parties/adresse.json" }
 *
 * remplace l'objet par le contenu du fichier désigné. Les fichiers vivent dans
 * « parties/ », à l'intérieur du paquet, et nulle part ailleurs.
 *
 * DES CLÉS À CÔTÉ DU $ref SURCHARGENT LE CONTENU RÉFÉRENCÉ :
 *
 *   { "$ref": "parties/champ-texte.json", "libelle": "Nom du site" }
 *
 * C'est ce qui rend la chose utile plutôt que seulement compacte : on décrit un
 * champ générique une fois, puis on le décline. La surcharge est de SURFACE —
 * elle remplace une clé entière, elle ne fusionne pas en profondeur. Une fusion
 * récursive est commode dans les cas simples et devient indevinable dès qu'un
 * tableau est en jeu : on ne sait plus si l'on ajoute ou si l'on remplace.
 *
 * CE QUE CELA NE CHANGE PAS
 * La résolution a lieu À LA LECTURE, avant toute validation, et le résultat est
 * INLINÉ. Ce qui est validé, stocké et exécuté est un bloc unique, sans aucune
 * référence résiduelle. Le module installé est donc exactement aussi
 * inspectable qu'avant : les références sont une commodité d'écriture, elles ne
 * survivent pas à l'installation.
 *
 * CE QUI EST BORNÉ, ET POURQUOI
 * Une inclusion est une amplification : un fichier de 10 Ko inclus cent fois
 * fait un mégaoctet, et cent fichiers s'incluant en cascade font bien pire. Le
 * ZIP a la même faiblesse, et on la borne de la même façon — par la taille
 * dépliée, pas par la taille du fichier reçu.
 *   • profondeur         8 niveaux
 *   • inclusions totales 400
 *   • taille résolue     4 Mo
 *   • cycles             refusés (A → B → A, et A → A)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtReferences
{
    /** Nom de fichier acceptable sous parties/. Pas de sous-dossier : rien à y gagner. */
    private const RE_PARTIE = '/^parties\/[a-zA-Z0-9_\-]{1,60}\.json$/';

    private const PROFONDEUR_MAX = 8;
    private const INCLUSIONS_MAX = 400;
    private const TAILLE_MAX     = 4 * 1024 * 1024;

    /** @var callable(string):?string  rend le contenu d'un chemin, ou null */
    private $lecteur;
    private int $inclusions = 0;

    private function __construct(callable $lecteur)
    {
        $this->lecteur = $lecteur;
    }

    /**
     * Résout les références d'une déclaration.
     *
     * Le LECTEUR est fourni par l'appelant : depuis un dossier déplié il lit le
     * disque, depuis une archive il lit l'entrée ZIP. Le résolveur n'ouvre
     * jamais de fichier lui-même — c'est ce qui lui permet de servir les deux
     * chemins d'installation sans dupliquer les contrôles de sécurité de
     * chacun.
     *
     * @param  array $decl     déclaration brute
     * @param  callable $lecteur  fn(string $chemin): ?string
     * @throws RuntimeException à la première anomalie
     */
    public static function resoudre(array $decl, callable $lecteur): array
    {
        $r = new self($lecteur);
        $out = $r->noeud($decl, 0, []);
        if (!is_array($out)) {
            throw new RuntimeException('La déclaration ne peut pas être remplacée '
                . 'entièrement par une référence.');
        }
        return $out;
    }

    /** Une déclaration contient-elle au moins une référence ? */
    public static function contientReference(array $decl): bool
    {
        foreach ($decl as $cle => $v) {
            if ($cle === '$ref') return true;
            if (is_array($v) && self::contientReference($v)) return true;
        }
        return false;
    }

    /** Parcours récursif. $pile porte les chemins en cours, pour couper les cycles. */
    private function noeud(mixed $n, int $profondeur, array $pile): mixed
    {
        if (!is_array($n)) return $n;

        if ($profondeur > self::PROFONDEUR_MAX) {
            throw new RuntimeException('Références trop imbriquées ('
                . self::PROFONDEUR_MAX . ' niveaux au maximum). Une partie qui en '
                . 'inclut une autre qui en inclut une autre devient aussi difficile '
                . 'à suivre que le fichier unique qu\'elle remplaçait.');
        }

        if (isset($n['$ref'])) {
            return $this->inclure($n, $profondeur, $pile);
        }

        $out = [];
        foreach ($n as $cle => $v) {
            $out[$cle] = $this->noeud($v, $profondeur, $pile);
        }
        return $out;
    }

    /** Remplace { "$ref": …, …surcharges } par le contenu du fichier, surchargé. */
    private function inclure(array $n, int $profondeur, array $pile): mixed
    {
        $chemin = $n['$ref'];
        if (!is_string($chemin) || !preg_match(self::RE_PARTIE, $chemin)) {
            throw new RuntimeException('Référence « '
                . (is_string($chemin) ? $chemin : '?') . ' » invalide. Attendu : '
                . '« parties/<nom>.json » — un fichier du paquet, sans sous-dossier '
                . 'ni remontée.');
        }

        // Le cycle est détecté sur la PILE, pas sur l'ensemble des fichiers déjà
        // vus : inclure deux fois la même partie côte à côte est légitime — c'est
        // même l'usage principal. Seule l'inclusion d'un fichier par lui-même,
        // directement ou en boucle, est fautive.
        if (in_array($chemin, $pile, true)) {
            throw new RuntimeException("Référence circulaire : « $chemin » "
                . 's\'inclut lui-même (' . implode(' → ', $pile) . " → $chemin).");
        }

        if (++$this->inclusions > self::INCLUSIONS_MAX) {
            throw new RuntimeException('Trop d\'inclusions (' . self::INCLUSIONS_MAX
                . ' au maximum). Une déclaration qui en demande davantage est '
                . 'probablement engendrée par une boucle.');
        }

        $brut = ($this->lecteur)($chemin);
        if ($brut === null) {
            throw new RuntimeException("Référence « $chemin » : fichier absent du "
                . 'paquet. Les parties doivent être livrées avec le module.');
        }
        if (strlen($brut) > self::TAILLE_MAX) {
            throw new RuntimeException("Référence « $chemin » : fichier trop "
                . 'volumineux.');
        }

        $contenu = json_decode($brut, true);
        if ($contenu === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Référence « $chemin » : JSON invalide — "
                . json_last_error_msg());
        }

        // Résolution du contenu inclus, avec ce fichier ajouté à la pile.
        $contenu = $this->noeud($contenu, $profondeur + 1, [...$pile, $chemin]);

        // Surcharges : toute clé posée à côté du $ref l'emporte. Elles sont
        // résolues elles aussi — une surcharge peut être une référence.
        $surcharges = [];
        foreach ($n as $cle => $v) {
            if ($cle === '$ref') continue;
            $surcharges[$cle] = $this->noeud($v, $profondeur, $pile);
        }
        if ($surcharges === []) return $contenu;

        if (!is_array($contenu)) {
            throw new RuntimeException("Référence « $chemin » : le fichier ne "
                . 'contient pas un objet, il ne peut pas être surchargé.');
        }
        return array_merge($contenu, $surcharges);
    }

    /**
     * Lecteur pour un dossier déplié.
     *
     * Le chemin a déjà été vérifié contre RE_PARTIE — pas de « .. », pas de
     * sous-dossier — mais on repasse par realpath() quand même : c'est la
     * garantie qui ne dépend pas de la justesse d'une expression régulière.
     */
    public static function lecteurDossier(string $dossier): callable
    {
        $racine = realpath($dossier);
        return function (string $chemin) use ($racine): ?string {
            if ($racine === false) return null;
            $abs = realpath($racine . '/' . $chemin);
            if ($abs === false || !str_starts_with($abs, $racine . DIRECTORY_SEPARATOR)) {
                return null;
            }
            $c = @file_get_contents($abs);
            return $c === false ? null : $c;
        };
    }

    /** Lecteur pour une archive ouverte (thématiques : rien n'est déplié sur disque). */
    public static function lecteurZip(ZipArchive $zip): callable
    {
        return function (string $chemin) use ($zip): ?string {
            $c = $zip->getFromName($chemin);
            return $c === false ? null : $c;
        };
    }
}
