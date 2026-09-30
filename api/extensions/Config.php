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
 * Larka — Extensions : la configuration d'un module, sur disque
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Chaque module installé reçoit son dossier :
 *
 *   extensions/config/<identifiant>/
 *       listes.json     ← ses listes de choix, éditables à la main
 *       reglages.json   ← ses réglages
 *       LISEZ-MOI.md    ← à quoi servent les deux fichiers ci-dessus
 *
 * POURQUOI UN FICHIER PLUTÔT QUE LA TABLE « Listes »
 * Une liste de module vivait dans la table du cœur, mélangée à celles de Larka,
 * et se réglait depuis Configuration → Listes. Deux inconvénients : elle n'était
 * ni versionnable ni livrable — on ne pouvait pas préparer la nomenclature d'un
 * client à l'avance, ni la retrouver après réinstallation, puisqu'elle vivait en
 * base. Et l'écran de configuration du cœur se remplissait de catégories
 * appartenant à des modules.
 *
 * Le fichier est la SOURCE. On l'ouvre, on l'édite, on le commit.
 *
 * ⚠️ MULTI-TENANT
 * Larka résout un tenant par domaine. Un fichier unique serait donc PARTAGÉ
 * entre tous les clients — la nomenclature de l'un deviendrait celle des autres.
 * C'est exactement ce que la table « Listes » évitait, puisqu'elle vit dans la
 * base du tenant.
 *
 * D'où la structure :
 *
 *   extensions/config/<id>/listes.json              base, livrée avec le module
 *   extensions/config/<id>/<tenant>/listes.json     ce que ce client a modifié
 *
 * En mono-tenant, il n'y a que le premier chemin. En multi-tenant, la base sert
 * de modèle et chaque client écrit dans le sien.
 *
 * ⚠️ CE DOSSIER EST DANS LA RACINE DE L'APPLICATION
 * extensions/.htaccess refuse déjà les .json et coupe le moteur PHP, et
 * deploy/nginx.conf porte la règle équivalente. Le contenu n'est donc pas
 * servi. Mais il reste écrit dans l'arborescence du code : si votre déploiement
 * monte la racine en lecture seule — ce qui est une bonne pratique — donnez
 * l'écriture à « extensions/config/ » seul, et à rien d'autre.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtConfig
{
    private const RE_ID = '/^[a-z0-9][a-z0-9.\-]{0,64}$/';

    /** Racine commune. Créée au premier besoin. */
    public static function racine(): string
    {
        $d = dirname(__DIR__, 2) . '/extensions/config';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    /**
     * Dossier d'un module. Créé au premier besoin.
     *
     * L'identifiant est reborné ici même : c'est lui qui compose un chemin, et
     * un contrôle qui s'appuie sur un contrôle fait ailleurs finit par sauter le
     * jour où l'ailleurs change.
     */
    public static function dossier(string $identifiant): ?string
    {
        if (!preg_match(self::RE_ID, $identifiant)) return null;
        $d = self::racine() . '/' . $identifiant;
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return is_dir($d) ? $d : null;
    }

    /**
     * Clé du tenant courant, ou null en mono-tenant.
     *
     * Deux conditions, et les deux sont nécessaires : une clé en session ET une
     * installation réellement multi-tenant. Sur une installation simple, la
     * session peut porter une clé résiduelle ; créer un sous-dossier pour elle
     * scinderait la configuration en deux sans que personne l'ait demandé.
     */
    public static function tenant(): ?string
    {
        if (class_exists('TenantResolver') && !TenantResolver::isMultiTenant()) {
            return null;
        }
        $k = $GLOBALS['_currentTenantKey'] ?? null;
        if (!is_string($k) || $k === '') return null;
        $k = preg_replace('/[^A-Za-z0-9_\-]/', '_', $k);
        return $k !== '' ? $k : null;
    }

    /**
     * Chemin d'un fichier de configuration.
     *
     * UN SEUL FICHIER PAR CLIENT, et rien à la racine quand il y a des clients.
     *
     * Il y avait ici un « modèle partagé » à la racine du dossier du module, et
     * une copie par client à côté. Deux fichiers du même nom, à un niveau
     * d'écart : on ouvrait le mauvais, on le modifiait, et rien ne changeait à
     * l'écran — ou pire, cela changeait pour tout le monde.
     *
     * Le modèle n'avait de toute façon pas lieu d'être : les valeurs de départ
     * sont dans la DÉCLARATION du module, qui est livrée avec lui et versionnée.
     * En recopier un exemplaire sur disque créait une seconde source de vérité
     * sans rien apporter.
     *
     * En mono-tenant, le fichier est à la racine du dossier du module — il n'y a
     * pas de client à distinguer.
     */
    public static function chemin(string $identifiant, string $fichier): ?string
    {
        if (!in_array($fichier, ['listes.json', 'reglages.json'], true)) return null;
        $d = self::dossier($identifiant);
        if ($d === null) return null;

        $t = self::tenant();
        if ($t === null) return $d . '/' . $fichier;

        $dt = $d . '/' . $t;
        if (!is_dir($dt)) @mkdir($dt, 0750, true);
        return $dt . '/' . $fichier;
    }

    /**
     * Lit un fichier de configuration.
     *
     * La surcharge du tenant d'abord, le modèle partagé ensuite. Un fichier
     * illisible est traité comme absent : une configuration facultative ne doit
     * pas empêcher un module de démarrer, et un JSON cassé se corrige — il ne
     * doit pas mettre l'écran par terre en attendant.
     */
    public static function lire(string $identifiant, string $fichier): array
    {
        $f = self::chemin($identifiant, $fichier);
        if ($f === null || !is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    /** Écrit un fichier de configuration. Rend false si le disque refuse. */
    public static function ecrire(string $identifiant, string $fichier,
                                   array $contenu): bool
    {
        $f = self::chemin($identifiant, $fichier);
        if ($f === null) return false;
        $ok = @file_put_contents($f, json_encode($contenu,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        if ($ok !== false) @chmod($f, 0640);
        return $ok !== false;
    }

    /**
     * Crée le dossier du module et y dépose ses fichiers, à l'installation.
     *
     * ⚠️ N'ÉCRASE JAMAIS un fichier existant. Réinstaller un module — pour le
     * mettre à jour, ou après une désinstallation — ne doit pas effacer la
     * nomenclature que le client a mise des mois à constituer. Les valeurs
     * déclarées sont un POINT DE DÉPART, pas une remise à zéro.
     *
     * Les listes nouvellement déclarées par une mise à jour sont en revanche
     * ajoutées : sans cela, un module qui gagne un champ verrait sa liste
     * absente du fichier et n'aurait aucune valeur à proposer.
     *
     * @return array les listes effectivement présentes après l'opération
     */
    public static function initialiser(string $identifiant, array $declaration): array
    {
        if (self::dossier($identifiant) === null) return [];

        $declarees = self::listesDeclarees($declaration);
        $existantes = self::lire($identifiant, 'listes.json');

        $fusion = $existantes;
        $nouvelles = 0;
        foreach ($declarees as $cat => $def) {
            if (!isset($fusion[$cat])) { $fusion[$cat] = $def; $nouvelles++; }
        }
        if ($existantes === [] || $nouvelles > 0) {
            self::ecrire($identifiant, 'listes.json', $fusion);
        }

        self::deposerLisezMoi($identifiant, $declaration);
        return $fusion;
    }

    /**
     * Extrait de la déclaration les listes ouvertes, sous forme éditable.
     *
     * ⚠️ LES DRAPEAUX D'AMORÇAGE SE LISENT DANS « amorce », PAS À LA RACINE.
     *
     * Le schéma range « ajout_autorise » et « libre » sous `amorce` — ce sont
     * des valeurs de DÉPART pour la catégorie, que l'administrateur pourra
     * ensuite contredire. Cette méthode les lisait à la racine du champ, où
     * la validation ne les laisse jamais : toute catégorie naissait donc
     * fermée, quoi qu'ait écrit l'auteur. Le droit d'ajout n'était pas
     * « refusé », il était perdu entre deux couches — et le bouton « + »
     * n'apparaissait chez personne.
     *
     * Les deux formes sont acceptées : `amorce` pour une déclaration validée,
     * la racine pour une déclaration brute (outils, tests, aperçu avant
     * installation). Une seule vérité, lue là où elle se trouve.
     *
     * ⚠️ DEUX CHAMPS PEUVENT DÉSIGNER LA MÊME CATÉGORIE.
     * Le premier rencontré gagnait, et l'ORDRE DE DÉCLARATION décidait
     * silencieusement si la saisie libre était permise. On fusionne : les
     * valeurs s'unissent, les droits s'additionnent. Un droit accordé à un
     * champ ne se retire pas parce qu'un autre champ, plus haut, se tait.
     */
    public static function listesDeclarees(array $declaration): array
    {
        $out = [];
        foreach (($declaration['donnees'] ?? []) as $jeu) {
            foreach (($jeu['champs'] ?? []) as $cle => $c) {
                if (($c['type'] ?? '') !== 'choix') continue;
                if (empty($c['liste']) || empty($c['modifiable'])) continue;
                $cat = (string)$c['liste'];

                $amorce = is_array($c['amorce'] ?? null) ? $c['amorce'] : [];
                $libre  = !empty($amorce['libre']) || !empty($c['libre']);

                /**
                 * Le droit d'AJOUT n'est volontairement pas écrit ici.
                 *
                 * Il appartient au champ — deux champs peuvent puiser dans la
                 * même catégorie sans avoir le même droit de l'enrichir — et
                 * c'est le champ que consulte ajouterValeurListe(). L'inscrire
                 * aussi dans le fichier créerait une seconde vérité que rien ne
                 * lit : un réglage qu'un administrateur croirait pouvoir
                 * changer, sans aucun effet. Mieux vaut qu'il n'y soit pas.
                 */

                if (!isset($out[$cat])) {
                    $out[$cat] = [
                        'libelle'     => (string)($c['libelle'] ?? $cat),
                        'obligatoire' => !empty($c['obligatoire']),
                        'libre'       => $libre,
                        'valeurs'     => array_values($c['valeurs'] ?? []),
                    ];
                    continue;
                }

                $out[$cat]['libre']       = $out[$cat]['libre']       || $libre;
                $out[$cat]['obligatoire'] = $out[$cat]['obligatoire'] || !empty($c['obligatoire']);
                $out[$cat]['valeurs']     = array_values(array_unique(array_merge(
                    $out[$cat]['valeurs'], array_values($c['valeurs'] ?? []))));
            }
        }
        return $out;
    }

    /**
     * Les listes effectives d'un module.
     *
     * ⚠️ GÉNÈRE LE FICHIER S'IL MANQUE.
     * Il n'était écrit qu'à l'installation, par preparer() — laquelle ne tourne
     * QU'À l'installation. Un module déjà installé avant ce changement n'avait
     * donc aucun fichier, et n'en aurait jamais eu : rien ne le déclenchait, et
     * il n'y avait plus d'écran pour modifier ses listes. Elles devenaient
     * impossibles à toucher.
     *
     * On écrit donc ici, au premier besoin. C'est aussi ce qui rattrape un
     * fichier supprimé par mégarde, ou un module installé sur un serveur qui
     * n'avait pas encore les droits d'écriture au moment de l'installation.
     */
    public static function listes(string $identifiant, array $declaration): array
    {
        $f = self::lire($identifiant, 'listes.json');
        if ($f === []) {
            $f = self::initialiser($identifiant, $declaration);
        }
        return $f !== [] ? $f : self::listesDeclarees($declaration);
    }

    /**
     * Ajoute une valeur à une liste et réécrit le fichier.
     *
     * Écrit dans la surcharge du TENANT quand il y en a un : une valeur ajoutée
     * par un client ne doit pas apparaître chez les autres.
     */
    public static function ajouterValeur(string $identifiant, array $declaration,
                                          string $categorie, string $valeur): bool
    {
        $listes = self::listes($identifiant, $declaration);
        if (!isset($listes[$categorie])) return false;

        $vals = $listes[$categorie]['valeurs'] ?? [];
        if (in_array($valeur, $vals, true)) return true;   // déjà là : rien à faire
        $vals[] = $valeur;
        $listes[$categorie]['valeurs'] = array_values($vals);

        return self::ecrire($identifiant, 'listes.json', $listes);
    }

    /** Note d'explication déposée à côté des fichiers, pour qui les ouvre. */
    private static function deposerLisezMoi(string $identifiant, array $declaration): void
    {
        $d = self::dossier($identifiant);
        if ($d === null || is_file($d . '/LISEZ-MOI.md')) return;
        $nom = (string)($declaration['nom'] ?? $identifiant);

        @file_put_contents($d . '/LISEZ-MOI.md', <<<MD
        # Configuration — $nom

        `$identifiant`

        ## listes.json

        Les listes de choix du module. C'est **la** source : le module lit ce
        fichier, pas la base de données. Modifiez-le à la main, il est fait pour.

        ```json
        {
          "MaCategorie": {
            "libelle": "Ma catégorie",
            "obligatoire": false,
            "libre": false,
            "valeurs": ["Première", "Deuxième"]
          }
        }
        ```

        - `obligatoire` — le champ doit être renseigné.
        - `libre` — une valeur hors liste est acceptée à la saisie.
        - `valeurs` — l'ordre du fichier est l'ordre du menu déroulant.

        Retirer une valeur la retire du menu. Les fiches qui la portaient déjà
        la conservent, signalée « retirée de la liste » : rien n'est perdu, et
        elles restent modifiables.

        ## reglages.json

        Les réglages du module, s'il en déclare. L'écran Modules l'emporte sur
        ce fichier : ce sont des valeurs de départ, pas un verrou.

        ## Réinstallation

        Ces fichiers ne sont **jamais écrasés**. Une mise à jour du module ajoute
        les listes qu'il déclare en plus, sans toucher à ce que vous avez écrit.

        ## Plusieurs clients sur le même serveur

        Chaque client a son fichier, dans un sous-dossier à son nom :

        ```
        <client>/listes.json   ← la configuration de CE client
        ```

        Il n'y a **rien à la racine** dans ce cas : un second fichier du même
        nom, un niveau plus haut, ne servirait qu'à se tromper de fichier. Les
        valeurs de départ viennent de la déclaration du module.

        MD);
    }
}
