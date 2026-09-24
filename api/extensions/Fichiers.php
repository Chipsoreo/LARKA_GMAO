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
 * Larka — Extensions : les fichiers d'un module
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un module peut détenir un dossier de fichiers : des icônes, des thèmes, des
 * traductions déposées après coup. C'est la PREMIÈRE surface d'écriture ouverte
 * aux modules en dehors de leurs tables, et elle mérite d'être bornée avec le
 * même soin.
 *
 * TROIS PROPRIÉTÉS, ET AUCUNE N'EST NÉGOCIABLE
 *
 *   1. HORS DE LA RACINE WEB. Les fichiers vivent sous data/extensions/fichiers/,
 *      que nginx ne sert pas. Il n'existe donc AUCUNE URL menant directement à
 *      l'un d'eux : ils ne sortent que par une route qui impose le type et le
 *      téléchargement. C'est la même raison qui fait vivre le code des modules
 *      hors racine — un fichier qu'on ne peut pas atteindre par une URL ne peut
 *      pas être servi par erreur.
 *
 *   2. LE TYPE EST LU DANS LES OCTETS, JAMAIS DANS LE NOM. Un fichier appelé
 *      « logo.png » qui contient du HTML n'est pas une image, et le renommer ne
 *      le rend pas inoffensif. On relit l'en-tête, comme le fait déjà le
 *      téléversement des images de fond du cœur.
 *
 *   3. LE NOM EST RÉÉCRIT. Celui fourni par le client n'est jamais conservé :
 *      il porte des séparateurs, des points, de l'unicode trompeur. On dérive
 *      un nom neutre du contenu, et on garde le nom d'origine comme simple
 *      libellé d'affichage.
 *
 * CE QUE CELA N'OUVRE PAS
 * Un module déclaratif n'exécute rien : il ne peut donc pas écrire ici de sa
 * propre initiative. Deux écritures seulement — l'installation, qui recopie ce
 * que le paquet livre, et un utilisateur, si le module déclare « import ». Le
 * module, lui, ne fait que LIRE.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/declaratif/Schema.php';

final class ExtFichiers
{
    /** Racine, hors de la racine web. */
    public static function racine(): string
    {
        $d = dirname(__DIR__, 2) . '/data/extensions/fichiers';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    /**
     * Dossier d'un module, éventuellement d'un de ses dossiers déclarés.
     *
     * L'identifiant est normalisé de la même façon que le préfixe de table :
     * un module ne peut pas atteindre l'espace d'un autre, même en connaissant
     * son nom.
     */
    public static function dossier(string $identifiant, ?string $nom = null): string
    {
        $id = preg_replace('/[^a-z0-9._\-]/', '_', strtolower($identifiant));
        $d  = self::racine() . '/' . $id;
        if ($nom !== null) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $nom)) {
                throw new RuntimeException('Nom de dossier invalide.');
            }
            $d .= '/' . $nom;
        }
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    /** Crée les dossiers déclarés. Appelé à l'installation. */
    public static function preparer(string $identifiant, array $declares): array
    {
        $faits = [];
        foreach ($declares as $nom => $d) {
            self::dossier($identifiant, (string)$nom);
            $faits[] = (string)$nom;
        }
        return $faits;
    }

    /**
     * Vérifie un contenu contre les natures acceptées par le dossier.
     *
     * Rend ['extension' => 'png', 'nature' => 'image'].
     * @throws RuntimeException si le contenu ne correspond à aucune nature admise
     */
    public static function verifier(string $cheminTemporaire, array $natures): array
    {
        $taille = @filesize($cheminTemporaire);
        foreach ($natures as $nature) {
            $spec = ExtSchemaDeclaratif::NATURES_FICHIER[$nature] ?? null;
            if ($spec === null) continue;
            if ($taille !== false && $taille > $spec['taille_max']) {
                throw new RuntimeException('Fichier trop volumineux pour la nature « '
                    . $nature . ' » (' . round($spec['taille_max'] / 1024 / 1024, 1) . ' Mo).');
            }

            $ext = match ($spec['controle']) {
                'image'    => self::verifierImage($cheminTemporaire),
                'langue'   => self::verifierJson($cheminTemporaire, 'langues'),
                'theme'    => self::verifierJson($cheminTemporaire, 'variables'),
                'document' => self::verifierDocument($cheminTemporaire),
                default    => null,
            };
            if ($ext !== null) return ['extension' => $ext, 'nature' => $nature];
        }
        throw new RuntimeException('Ce fichier ne correspond à aucune nature acceptée '
            . 'par ce dossier (' . implode(', ', $natures) . '). Le type est vérifié sur '
            . 'le contenu, pas sur le nom du fichier.');
    }

    /** Image : l'en-tête décide, pas l'extension. Le SVG n'est pas une option. */
    private static function verifierImage(string $f): ?string
    {
        return match (@exif_imagetype($f)) {
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
            default        => null,
        };
    }

    /**
     * JSON de traduction ou de thème.
     *
     * On ne se contente pas de « c'est du JSON valide » : on exige la clé
     * attendue. Un fichier accepté dans le dossier « traductions » qui ne
     * contiendrait pas de traductions serait rangé là pour rien, et personne ne
     * comprendrait pourquoi il ne s'applique jamais.
     */
    private static function verifierJson(string $f, string $cleAttendue): ?string
    {
        $brut = @file_get_contents($f);
        if ($brut === false) return null;
        $j = json_decode($brut, true);
        if (!is_array($j)) return null;
        return isset($j[$cleAttendue]) || isset($j['valeurs']) ? 'json' : null;
    }

    /** Document : on refuse tout ce qui pourrait être interprété par un navigateur. */
    private static function verifierDocument(string $f): ?string
    {
        $brut = (string)@file_get_contents($f, false, null, 0, 4096);
        if ($brut === '') return null;
        // Un octet nul en tête d'un « txt » trahit un binaire déguisé ; une
        // balise trahit du HTML, qui serait rendu s'il était servi un jour.
        if (str_starts_with($brut, '%PDF-')) return 'pdf';
        if (preg_match('/<\s*(script|html|iframe|svg|object|embed)/i', $brut)) return null;
        if (str_contains($brut, "\0")) return null;
        return 'txt';
    }

    /**
     * Range un fichier vérifié. Rend son nom neutre.
     *
     * Le nom est dérivé du CONTENU : deux dépôts du même fichier n'en font
     * qu'un, et un nom fourni par le client n'atteint jamais le disque.
     */
    public static function ranger(string $identifiant, string $nomDossier,
                                  string $temporaire, string $extension,
                                  string $libelle): array
    {
        $d = self::dossier($identifiant, $nomDossier);
        $empreinte = substr(hash_file('sha256', $temporaire), 0, 24);
        $fichier = $empreinte . '.' . $extension;

        if (!@copy($temporaire, $d . '/' . $fichier)) {
            throw new RuntimeException('Écriture impossible. Vérifiez les droits sur data/.');
        }
        @chmod($d . '/' . $fichier, 0640);

        // Le nom d'origine sert d'ÉTIQUETTE, à côté du fichier, jamais de nom
        // de fichier. On le nettoie quand même : il sera réaffiché.
        $etiquettes = self::etiquettes($identifiant, $nomDossier);
        $etiquettes[$fichier] = mb_substr(preg_replace('/[\x00-\x1F\/\\\\]/', '',
            $libelle) ?: $fichier, 0, 80);
        @file_put_contents($d . '/.etiquettes.json',
            json_encode($etiquettes, JSON_UNESCAPED_UNICODE));

        return ['fichier' => $fichier, 'libelle' => $etiquettes[$fichier]];
    }

    public static function etiquettes(string $identifiant, string $nomDossier): array
    {
        $f = self::dossier($identifiant, $nomDossier) . '/.etiquettes.json';
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    /** Contenu d'un dossier. */
    public static function lister(string $identifiant, string $nomDossier): array
    {
        $d = self::dossier($identifiant, $nomDossier);
        $etiq = self::etiquettes($identifiant, $nomDossier);
        $out = [];
        foreach (glob($d . '/*') ?: [] as $f) {
            $b = basename($f);
            if (!is_file($f) || str_starts_with($b, '.')) continue;
            $out[] = ['fichier' => $b, 'libelle' => $etiq[$b] ?? $b,
                      'taille' => filesize($f)];
        }
        return $out;
    }

    /**
     * Chemin d'un fichier à servir, ou null.
     *
     * Le nom reçu du client est confronté au format que NOUS produisons —
     * empreinte plus extension — et rien d'autre. Un nom qui ne ressemble pas à
     * ce que ranger() écrit n'est pas cherché sur le disque.
     */
    public static function chemin(string $identifiant, string $nomDossier,
                                  string $fichier): ?string
    {
        if (!preg_match('/^[a-f0-9]{24}\.[a-z]{2,4}$/', $fichier)) return null;
        $c = self::dossier($identifiant, $nomDossier) . '/' . $fichier;
        return is_file($c) ? $c : null;
    }

    public static function supprimer(string $identifiant, string $nomDossier,
                                     string $fichier): bool
    {
        $c = self::chemin($identifiant, $nomDossier, $fichier);
        if ($c === null) return false;
        @unlink($c);
        $etiq = self::etiquettes($identifiant, $nomDossier);
        unset($etiq[$fichier]);
        @file_put_contents(self::dossier($identifiant, $nomDossier) . '/.etiquettes.json',
            json_encode($etiq, JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Type MIME servi. Liste close : on ne devine jamais d'après l'extension reçue. */
    public static function mime(string $fichier): string
    {
        return match (strtolower(pathinfo($fichier, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
