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
 * Larka — Extensions : le format de paquet .larka
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ D'ABORD, CE QUE L'EXTENSION DE FICHIER N'APPORTE PAS
 * Renommer une archive en .larka ne sécurise rien : le PHP qu'elle contient
 * reste du PHP, et un attaquant renomme un fichier aussi vite que nous. Si l'on
 * s'arrêtait au nom, on n'aurait gagné que l'illusion d'avoir fait quelque chose.
 *
 * CE QUE LE FORMAT APPORTE VRAIMENT, LUI
 * Un paquet scellé permet trois choses qu'un dossier déposé à la main ne permet
 * pas, et la première vaut à elle seule le détour :
 *
 *   1. LE CODE NE VIT PLUS SOUS LA RACINE WEB.
 *      Une extension installée est dépliée dans data/extensions/code/, hors du
 *      dossier servi par nginx. Il n'existe alors plus AUCUNE URL menant à un
 *      .php d'extension — le risque « appel direct sans authentification »,
 *      qui était le point faible de l'approche par dossier, disparaît par
 *      construction au lieu de dépendre d'une règle serveur qu'on peut oublier.
 *
 *   2. UN POINT D'ENTRÉE UNIQUE ET CONTRÔLÉ.
 *      Un fichier reçu = un moment de vérification. Taille, structure, chemins,
 *      types de fichiers, manifeste, analyse statique, empreinte : tout est
 *      vérifié avant que la moindre ligne ne touche le disque définitif.
 *
 *   3. UNE UNITÉ SIGNABLE.
 *      Le jour où le site communautaire existera, c'est ce fichier-là qui
 *      portera la signature de l'auteur et la note des utilisateurs. Le champ
 *      SIGNATURE est déjà prévu dans le format (voir verifierSignature()).
 *
 * STRUCTURE DU FICHIER
 * Un .larka est une archive ZIP dont la PREMIÈRE entrée est « mimetype »,
 * stockée sans compression, contenant « application/vnd.larka.extension ».
 * C'est le procédé d'EPUB et d'OpenDocument : il place une signature lisible à
 * un décalage fixe, si bien qu'un .zip simplement renommé est reconnu comme tel
 * immédiatement, sans même ouvrir l'archive.
 *
 *   mimetype              (stocké, non compressé, en premier)
 *   extension.json        le manifeste
 *   README.md             documentation
 *   SIGNATURE             (facultatif, pour plus tard)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Manifeste.php';

final class ExtPaquet
{
    public const MIMETYPE  = 'application/vnd.larka.extension';
    public const EXTENSION = '.larka';

    /**
     * Extensions acceptées.
     *
     * « .larka_thematique » désigne un pack d'HABILLAGE : un thème, des
     * traductions, ou les deux. Le contenu et les contrôles sont identiques —
     * seule l'extension change, pour qu'on sache au premier coup d'œil qu'un
     * fichier ne contient ni données ni écran de saisie.
     *
     * Ce n'est pas une sécurité : le format réel est vérifié à la lecture. Un
     * fichier nommé « .larka_thematique » qui déclarerait des données serait
     * refusé.
     */
    /**
     * Dossiers où l'on cherche des sources et des paquets, dans l'ordre.
     *
     * `extensions/` mélangeait tout : les modules, les thèmes et les packs de
     * langue côte à côte, distingués seulement par un préfixe dans le nom. À
     * neuf entrées c'était déjà pénible ; à trente, on ne retrouve plus le
     * module qu'on cherche.
     *
     *   extensions/modules/   add-ons  → .larka
     *   extensions/themes/    habillage → .larka_thematique
     *   extensions/langues/   traductions → .larka_thematique
     *
     * LA RACINE RESTE PARCOURUE. Ce n'est pas une hésitation : des installations
     * existantes ont leurs paquets à cet endroit, et un rangement qui rendrait
     * invisible ce qui fonctionnait hier serait une régression déguisée en
     * amélioration. Les sous-dossiers sont une commodité, pas une obligation.
     *
     * Cette liste vit ICI et nulle part ailleurs. Elle était déjà recopiée dans
     * Registre.php et dans routes/extensions.php ; en ajouter une troisième
     * copie aurait garanti qu'elles divergent.
     */
    public const SOUS_DOSSIERS = ['modules', 'themes', 'langues'];

    /**
     * Dossier des configurations déposées à la main : extensions/config/
     *
     * Un fichier par module, nommé « <identifiant>.json ». Il donne les
     * réglages du module SANS passer par l'écran — pour préparer un déploiement,
     * livrer une configuration type à un client, ou versionner ce que l'on veut
     * retrouver après réinstallation.
     *
     *   extensions/config/acme.suivi-cles.json
     *   { "prealerte_jours": 30, "couleur_module": "#0b6e4f" }
     *
     * PRÉCÉDENCE, ET POURQUOI CELLE-CI
     *   1. réglages posés dans l'écran   (data/extensions/<id>.reglages.json)
     *   2. extensions/config/<id>.json   ← ce dossier
     *   3. « defaut » déclaré au manifeste
     *
     * Le fichier fournit donc des DÉFAUTS, et l'écran l'emporte. L'inverse —
     * un fichier qui écrase l'écran — donnerait un réglage qu'on modifie sans
     * effet et sans explication : c'est le défaut que l'on vient de corriger
     * sur les listes, il n'y a pas de raison de le réintroduire ici.
     *
     * Le dossier est créé au premier besoin. Un fichier illisible est ignoré
     * silencieusement : une configuration facultative ne doit pas empêcher un
     * module de démarrer.
     */
    public static function dossierConfig(): string
    {
        $d = dirname(__DIR__, 2) . '/extensions/config';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    /** Configuration déposée pour un module, ou [] s'il n'y en a pas. */
    public static function configDeposee(string $identifiant): array
    {
        // L'identifiant vient d'un manifeste déjà validé, mais on le reborne :
        // c'est lui qui compose un nom de fichier, et un contrôle qui dépend
        // d'un contrôle fait ailleurs finit par sauter quand l'ailleurs change.
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{0,64}$/', $identifiant)) return [];
        $f = self::dossierConfig() . '/' . $identifiant . '.json';
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    /** Racine des sources, et ses sous-dossiers, pour la lecture. */
    public static function dossiersSources(): array
    {
        $racine = dirname(__DIR__, 2) . '/extensions';
        $out = [$racine];
        foreach (self::SOUS_DOSSIERS as $d) $out[] = $racine . '/' . $d;
        $out[] = self::dossierPaquets();
        return $out;
    }

    /**
     * Sous-dossier qui convient à un paquet, d'après sa nature.
     *
     * Un thème et un pack de langue produisent tous deux un .larka_thematique :
     * l'extension seule ne les distingue pas. On regarde donc ce que le
     * manifeste contient — « langues » range dans langues/, « variables » dans
     * themes/, le reste dans modules/.
     */
    public static function sousDossierPour(array $manifeste): string
    {
        if (!empty($manifeste['langues']))   return 'langues';
        if (!empty($manifeste['variables'])) return 'themes';
        if (!empty($manifeste['vocabulaire']) && empty($manifeste['donnees'])
            && empty($manifeste['pages'])) return 'themes';
        return 'modules';
    }

    public const EXTENSIONS_ACCEPTEES = ['.larka', '.larka_thematique'];
    public const EXTENSION_THEMATIQUE = '.larka_thematique';

    /** Le fichier porte-t-il une extension acceptée ? */
    public static function extensionAcceptee(string $nom): bool
    {
        $bas = strtolower($nom);
        foreach (self::EXTENSIONS_ACCEPTEES as $e) {
            if (str_ends_with($bas, $e)) return true;
        }
        return false;
    }

    /** Plafonds. Une extension de GMAO n'a aucune raison d'être volumineuse. */
    private const TAILLE_MAX_PAQUET  = 64 * 1024 * 1024;   // le .larka lui-même
    private const TAILLE_MAX_DEPLIE  = 256 * 1024 * 1024;  // total une fois déplié
    private const TAILLE_MAX_FICHIER = 32 * 1024 * 1024;
    private const ENTREES_MAX        = 2000;
    private const RATIO_MAX          = 200;                // garde anti-« bombe zip »

    /**
     * Fichiers acceptés dans un paquet. Tout le reste est REFUSÉ, pas ignoré :
     * un paquet qui transporte un .so ou un .phar n'est pas un paquet qu'on
     * veut déplier, même si l'on comptait ne pas exécuter le fichier en trop.
     */
    /**
     * Extensions de fichier acceptées.
     *
     * Ni `php`, ni `js`, ni `css`, ni `html` : un module de données décrit, il
     * n'exécute ni ne met en forme. `svg` est également retiré — un SVG peut
     * contenir un `<script>`, et un logo servi au navigateur deviendrait un
     * vecteur d'exécution dans la page de Larka.
     */
    private const TYPES_AUTORISES = [
        'json', 'md', 'txt',
        'png', 'jpg', 'jpeg', 'webp',
    ];

    /** Chemins acceptés à l'intérieur du paquet. */
    /**
     * Chemins acceptés dans un paquet.
     *
     * `serveur/` et `client/` ont disparu : un module ne contient plus de code.
     * Ils étaient encore acceptés ici, et le refus n'intervenait qu'à la lecture
     * du manifeste — donc APRÈS extraction du PHP sur le disque. Le fichier ne
     * s'exécutait pas, mais il était écrit, et c'est déjà trop : le contrôle
     * doit porter sur ce qui entre, pas sur ce qui sert.
     */
    private const RE_CHEMIN = '#^(mimetype|SIGNATURE|extension\.json|README\.md|LICENSE|'
                            . 'parties/[a-zA-Z0-9_\-]{1,60}\.json|'
                            . 'assets/[a-zA-Z0-9_\-.]{1,80})$#';

    // ═══════════════════════════════════════════════════════════════════════
    //  Emplacements — tous HORS de la racine web
    // ═══════════════════════════════════════════════════════════════════════

    private static function racineDonnees(): string
    {
        return dirname(__DIR__, 2) . '/data/extensions';
    }

    /**
     * Sous-chemin du client courant, ou chaîne vide en mono-tenant.
     *
     * ⚠️ CE QUI A ÉTÉ CORRIGÉ ICI
     * Le code déplié et l'état d'installation vivaient dans un dossier UNIQUE,
     * partagé par tous les clients d'un même serveur. Installer une extension
     * en étant connecté chez l'un l'installait donc chez TOUS : elle apparaissait
     * dans le menu d'entreprises qui ne l'avaient jamais demandée.
     *
     * Un garde-fou existait — une liste d'autorisation tenue par le super admin —
     * mais il filtrait l'EXÉCUTION par-dessus un état partagé. Le module restait
     * installé pour tout le monde, et il suffisait que la liste ne soit pas à
     * jour pour qu'il ressorte ailleurs.
     *
     * L'isolation se fait maintenant par le chemin : chaque client a son dossier
     * de code, ses paquets et son état. Un module absent de chez lui n'est pas
     * « filtré », il n'existe pas. C'est une propriété du système de fichiers,
     * pas une règle qu'on peut oublier d'appliquer.
     *
     * Les paquets pèsent quelques centaines de kilo-octets : les dupliquer coûte
     * moins cher qu'un partage à surveiller.
     */
    public static function sousCheminTenant(): string
    {
        if (class_exists('TenantResolver') && !TenantResolver::isMultiTenant()) {
            return '';
        }
        $k = $GLOBALS['_currentTenantKey'] ?? null;
        if (!is_string($k) || $k === '') return '';
        $k = preg_replace('/[^A-Za-z0-9_\-]/', '_', $k);
        return $k === '' ? '' : '/tenants/' . $k;
    }

    /** Les paquets reçus, conservés tels quels (preuve + réinstallation). */
    public static function dossierPaquets(): string
    {
        return self::assurer(self::racineDonnees() . self::sousCheminTenant() . '/paquets');
    }

    /** Le code déplié. Jamais servi par le serveur web. */
    public static function dossierCode(?string $id = null): string
    {
        $d = self::assurer(self::racineDonnees() . self::sousCheminTenant() . '/code');
        return $id ? $d . '/' . $id : $d;
    }

    /** Racine de l'état d'installation, propre au client courant. */
    public static function dossierEtat(): string
    {
        return self::assurer(self::racineDonnees() . self::sousCheminTenant());
    }

    private static function assurer(string $d): string
    {
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Vérification
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Vérifie qu'un fichier est bien un paquet Larka, SANS rien extraire.
     *
     * L'ordre compte : on va du moins coûteux au plus coûteux, et on s'arrête au
     * premier problème. Inutile d'ouvrir une archive de 40 Mo pour découvrir
     * ensuite qu'elle n'a pas la bonne signature.
     *
     * @throws RuntimeException avec un message destiné à l'administrateur
     */
    /**
     * L'extension PHP « zip » est-elle chargée ?
     *
     * Sans elle, aucun paquet .larka ne peut être ouvert. Le contrôle est
     * exposé pour que l'interface le signale AVANT qu'on tente un import : un
     * fichier glissé qui revient avec « extension zip absente » se comprend mal
     * — on soupçonne le fichier, pas le serveur.
     *
     * Note : nous n'écrivons pas de lecteur ZIP en PHP pur pour nous en passer.
     * Analyser une archive fournie par un tiers est exactement le terrain où
     * une erreur d'implémentation devient une faille ; autant s'appuyer sur
     * libzip, éprouvée, et exiger l'extension.
     */
    public static function zipDisponible(): bool
    {
        return class_exists('ZipArchive');
    }

    public static function verifier(string $chemin): array
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            throw new RuntimeException('Fichier illisible.');
        }
        $taille = filesize($chemin);
        if ($taille === 0) {
            throw new RuntimeException('Fichier vide.');
        }
        if ($taille > self::TAILLE_MAX_PAQUET) {
            throw new RuntimeException('Paquet trop volumineux : '
                . round($taille / 1048576, 1) . ' Mo (maximum '
                . (self::TAILLE_MAX_PAQUET / 1048576) . ' Mo).');
        }

        self::verifierSignatureFormat($chemin);

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : "
                . 'les paquets .larka ne peuvent pas être ouverts. Activez extension=zip dans php.ini.');
        }

        $zip = new ZipArchive();
        $code = $zip->open($chemin, ZipArchive::CHECKCONS);
        if ($code !== true) {
            throw new RuntimeException('Archive illisible ou corrompue (code ' . $code . '). '
                . 'Le fichier a-t-il été tronqué au téléchargement ?');
        }

        try {
            $entrees = self::inspecterEntrees($zip);
            $manifesteBrut = $zip->getFromName('extension.json');
            if ($manifesteBrut === false) {
                throw new RuntimeException('Le paquet ne contient pas de fichier extension.json '
                    . 'à sa racine.');
            }
            $m = json_decode($manifesteBrut, true);
            if (!is_array($m) || empty($m['identifiant'])) {
                throw new RuntimeException('Le manifeste du paquet est illisible ou sans identifiant.');
            }
            $signe = $zip->getFromName('SIGNATURE');
        } finally {
            $zip->close();
        }

        return [
            'identifiant' => (string)$m['identifiant'],
            'nom'         => (string)($m['nom'] ?? $m['identifiant']),
            'version'     => (string)($m['version'] ?? '?'),
            'auteur'      => (string)($m['auteur'] ?? '?'),
            'entrees'     => $entrees['nombre'],
            'taille_paquet' => $taille,
            'taille_deplie' => $entrees['total'],
            'empreinte_paquet' => hash_file('sha256', $chemin),
            'signe'       => $signe !== false,
        ];
    }

    /**
     * Contrôle de la signature de format, sur les octets bruts.
     *
     * Un ZIP commence par un en-tête local de 30 octets suivi du nom de la
     * première entrée. On exige donc : « PK\x03\x04 », méthode 0 (stocké), nom
     * « mimetype », et le contenu attendu juste derrière. Un .zip renommé en
     * .larka échoue ici, avant toute ouverture d'archive.
     *
     * Ce contrôle n'est pas une sécurité — il n'empêche personne de fabriquer un
     * en-tête conforme. C'est un contrôle de FORMAT : il évite d'engager la
     * suite de la chaîne sur un fichier qui n'a manifestement rien à y faire, et
     * il produit un message clair au lieu d'une erreur de manifeste obscure.
     */
    private static function verifierSignatureFormat(string $chemin): void
    {
        $f = fopen($chemin, 'rb');
        if (!$f) throw new RuntimeException('Fichier illisible.');
        $tete = fread($f, 30 + 8 + strlen(self::MIMETYPE));
        fclose($f);

        if (substr($tete, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('Ce fichier n\'est pas un paquet Larka : signature absente. '
                . 'Un paquet .larka est une archive scellée produite par l\'auteur de l\'extension, '
                . 'pas un dossier compressé à la main.');
        }
        // Octets 8-9 : méthode de compression. 0 = stocké.
        $methode = unpack('v', substr($tete, 8, 2))[1] ?? -1;
        $nomLong = unpack('v', substr($tete, 26, 2))[1] ?? 0;
        $nom     = substr($tete, 30, $nomLong);

        if ($nom !== 'mimetype' || $methode !== 0) {
            throw new RuntimeException('Ce fichier ressemble à une archive ZIP ordinaire, pas à un '
                . 'paquet Larka. La première entrée doit être « mimetype », stockée sans compression. '
                . 'Si vous avez compressé un dossier vous-même, utilisez plutôt l\'outil de '
                . 'construction de paquet fourni avec la documentation.');
        }
        if (substr($tete, 30 + $nomLong, strlen(self::MIMETYPE)) !== self::MIMETYPE) {
            throw new RuntimeException('Type de paquet inattendu : ce n\'est pas une extension Larka.');
        }
    }

    /**
     * Parcourt les entrées : chemins, types, tailles, bombes zip.
     *
     * C'est ici que se joue la protection contre le « zip slip » — une entrée
     * nommée « ../../api/config.php » qui, à l'extraction naïve, écraserait un
     * fichier du cœur. On refuse le paquet entier plutôt que d'ignorer l'entrée :
     * un paquet qui contient ça n'est pas un paquet maladroit.
     */
    private static function inspecterEntrees(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::ENTREES_MAX) {
            throw new RuntimeException('Paquet refusé : ' . $zip->numFiles . ' entrées '
                . '(maximum ' . self::ENTREES_MAX . ').');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) throw new RuntimeException("Entrée #$i illisible.");
            $nom = $st['name'];

            if (str_ends_with($nom, '/')) continue;   // dossier : sans contenu

            if (str_contains($nom, "\0")) {
                throw new RuntimeException('Paquet refusé : nom d\'entrée contenant un octet nul.');
            }
            if (str_starts_with($nom, '/') || preg_match('#(^|/)\.\.(/|$)#', $nom)
                || preg_match('#^[a-zA-Z]:#', $nom) || str_contains($nom, '\\')) {
                throw new RuntimeException('Paquet refusé : l\'entrée « ' . htmlspecialchars($nom)
                    . ' » sort du paquet. Une archive qui tente d\'écrire hors de son dossier '
                    . 'n\'est pas une extension, c\'est une attaque.');
            }
            if (!preg_match(self::RE_CHEMIN, $nom)) {
                throw new RuntimeException('Paquet refusé : l\'entrée « ' . htmlspecialchars($nom)
                    . ' » n\'est pas à un emplacement prévu. Les fichiers doivent être à la racine '
                    . '(extension.json, README.md) ou dans serveur/, client/ ou assets/.');
            }
            $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
            if ($nom !== 'mimetype' && $nom !== 'SIGNATURE' && $nom !== 'LICENSE'
                && !in_array($ext, self::TYPES_AUTORISES, true)) {
                throw new RuntimeException('Paquet refusé : type de fichier « .' . $ext
                    . ' » non autorisé (' . htmlspecialchars($nom) . ').');
            }
            // Message explicite pour les formats de code : l'auteur d'un ancien
            // module doit comprendre ce qui a changé, pas seulement être refusé.
            if (in_array($ext, ['php', 'js', 'css', 'html', 'htm', 'svg', 'phar',
                                'sh', 'py', 'wasm'], true)) {
                throw new RuntimeException('Paquet refusé : « ' . htmlspecialchars($nom)
                    . ' ». Un module est un module de données — il ne contient que sa '
                    . 'déclaration extension.json, un README et des images. Larka '
                    . 'n\'exécute aucun code fourni par un module.');
            }

            if ($st['size'] > self::TAILLE_MAX_FICHIER) {
                throw new RuntimeException('Paquet refusé : « ' . htmlspecialchars($nom) . ' » fait '
                    . round($st['size'] / 1048576, 1) . ' Mo (maximum '
                    . (self::TAILLE_MAX_FICHIER / 1048576) . ' Mo).');
            }
            // Ratio de compression aberrant = archive conçue pour saturer le disque.
            if ($st['comp_size'] > 0 && ($st['size'] / $st['comp_size']) > self::RATIO_MAX) {
                throw new RuntimeException('Paquet refusé : taux de compression anormal sur « '
                    . htmlspecialchars($nom) . ' » — archive probablement piégée.');
            }
            $total += $st['size'];
            if ($total > self::TAILLE_MAX_DEPLIE) {
                throw new RuntimeException('Paquet refusé : plus de '
                    . (self::TAILLE_MAX_DEPLIE / 1048576) . ' Mo une fois déplié.');
            }
        }
        return ['nombre' => $zip->numFiles, 'total' => $total];
    }

    /**
     * Vérification de signature cryptographique — emplacement réservé.
     *
     * Le format prévoit une entrée SIGNATURE, mais RIEN N'EST VÉRIFIÉ pour le
     * moment : la présence d'une signature ne prouve donc rien aujourd'hui, et
     * l'interface l'indique explicitement plutôt que d'afficher un cadenas
     * trompeur. Le jour où le dépôt communautaire existera, c'est ici que se
     * fera la vérification par clé publique d'auteur.
     */
    public static function verifierSignature(string $chemin, array $clesConnues = []): array
    {
        return ['verifiee' => false,
                'motif' => 'La vérification de signature n\'est pas encore implémentée.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Extraction et installation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Déplie un paquet vérifié dans un dossier temporaire, pour examen.
     * Rien n'est installé à ce stade : l'administrateur n'a encore rien accepté.
     *
     * @return string chemin du dossier temporaire (à nettoyer par l'appelant)
     */
    /**
     * Ce qui est écrit sur le disque à l'installation.
     *
     * Trois entrées, et rien d'autre. Ni dossier, ni motif large : la liste est
     * close et se lit d'un coup d'œil.
     *
     * C'est la garantie structurelle du format : il n'existe aucun mécanisme
     * capable de faire atterrir un fichier de code dans l'installation, donc
     * aucun mécanisme capable de le lire ensuite. Le contrôle des types de
     * fichier, en amont, ne sert plus qu'à renseigner l'auteur — plus à
     * protéger.
     */
    private static function extractible(string $nom): bool
    {
        if ($nom === 'extension.json') return true;
        if ($nom === 'README.md')      return true;
        if ($nom === 'LICENSE')        return true;

        /**
         * ⚠️ « parties/ » MANQUAIT À CETTE LISTE.
         *
         * Les fragments référencés par $ref étaient acceptés à l'entrée du
         * paquet — inspecterEntrees() les autorise — puis jamais écrits sur le
         * disque : cette fonction ne les connaissait pas. Le module s'installait
         * donc sans eux, et le premier chargement du manifeste échouait sur
         * « parties/reglages.json fichier absent ».
         *
         * Le défaut ne se voyait ni à la construction du paquet, ni à la
         * vérification, ni même dans la liste du contenu de l'archive : le
         * fichier y était bien. Il disparaissait entre l'archive et le disque.
         *
         * Même forme que assets/ : un seul niveau, nom borné, extension close.
         */
        if (preg_match('#^parties/[a-zA-Z0-9_\-]{1,60}\.json$#', $nom)) return true;

        // Images du dossier assets/, sans sous-dossier, extension close.
        return (bool)preg_match('#^assets/[a-zA-Z0-9_\-]{1,60}\.(png|jpg|jpeg|webp)$#', $nom);
    }

    public static function deplierPourExamen(string $chemin): string
    {
        self::verifier($chemin);

        $tmp = self::racineDonnees() . '/tmp/' . bin2hex(random_bytes(8));
        self::assurer($tmp);

        $zip = new ZipArchive();
        if ($zip->open($chemin) !== true) {
            throw new RuntimeException('Ouverture du paquet impossible.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $nom = $st['name'];
                if (str_ends_with($nom, '/') || $nom === 'mimetype') continue;

                // ── Liste blanche d'EXTRACTION ────────────────────────────
                //
                // On n'extrait que ce que le format définit, quoi que l'archive
                // contienne. Refuser un fichier de code est un filtre, et un
                // filtre se contourne : il suffit d'une extension oubliée, d'un
                // encodage inattendu, d'un motif mal écrit.
                //
                // Ici, il n'y a rien à contourner. Un .php présent dans
                // l'archive n'est pas refusé — il n'est simplement jamais
                // écrit. Pour qu'il arrive sur le disque, il faudrait ajouter
                // son nom à cette liste, c'est-à-dire décider de l'accepter.
                if (!self::extractible($nom)) continue;

                // Les chemins ont déjà été validés par inspecterEntrees(), mais
                // on revalide à l'écriture : la vérification et l'extraction sont
                // deux moments distincts, et c'est précisément entre les deux que
                // se glissent les erreurs de ce genre.
                $cible = $tmp . '/' . $nom;
                $reel  = self::normaliser($cible);
                if (!str_starts_with($reel, self::normaliser($tmp) . '/')) {
                    throw new RuntimeException('Extraction refusée : chemin hors du dossier cible.');
                }
                self::assurer(dirname($cible));
                $flux = $zip->getStream($nom);
                if (!$flux) throw new RuntimeException("Lecture impossible de « $nom ».");
                $sortie = fopen($cible, 'wb');
                stream_copy_to_stream($flux, $sortie, self::TAILLE_MAX_FICHIER);
                fclose($flux);
                fclose($sortie);
                @chmod($cible, 0640);
            }
        } finally {
            $zip->close();
        }
        return $tmp;
    }

    /**
     * Installe définitivement un paquet déjà examiné et accepté.
     *
     * L'ordre des opérations est celui d'une mise à jour sûre : on déplie à
     * côté, on bascule, on garde l'ancien. Si quoi que ce soit échoue en cours
     * de route, l'installation précédente est toujours en place.
     *
     * @return array informations sur l'installation
     */
    public static function installer(string $cheminPaquet, string $identifiant): array
    {
        $info = self::verifier($cheminPaquet);
        if ($info['identifiant'] !== $identifiant) {
            throw new RuntimeException('Le paquet contient l\'extension « ' . $info['identifiant']
                . " » et non « $identifiant ».");
        }

        $destination = self::dossierCode($identifiant);
        $neuf        = $destination . '.neuf';
        $ancien      = $destination . '.ancien';

        self::supprimerRecursif($neuf);
        self::supprimerRecursif($ancien);

        $tmp = self::deplierPourExamen($cheminPaquet);
        if (!@rename($tmp, $neuf)) {
            self::supprimerRecursif($tmp);
            throw new RuntimeException('Impossible de préparer le dossier d\'installation. '
                . 'Vérifiez les droits sur data/extensions/code/.');
        }

        // Bascule : l'indisponibilité se réduit à un rename.
        if (is_dir($destination) && !@rename($destination, $ancien)) {
            self::supprimerRecursif($neuf);
            throw new RuntimeException('Impossible de mettre de côté l\'installation précédente.');
        }
        if (!@rename($neuf, $destination)) {
            if (is_dir($ancien)) @rename($ancien, $destination);   // retour en arrière
            self::supprimerRecursif($neuf);
            throw new RuntimeException('Bascule impossible : l\'installation précédente a été '
                . 'rétablie.');
        }
        self::supprimerRecursif($ancien);

        // Le paquet d'origine est conservé : c'est la pièce à conviction en cas
        // de doute ultérieur, et la source d'une réinstallation à l'identique.
        $archive = self::dossierPaquets() . '/' . $identifiant . '-' . $info['version'] . '.larka';
        @copy($cheminPaquet, $archive);
        @chmod($archive, 0640);

        return $info + ['dossier' => $destination, 'paquet_conserve' => $archive];
    }

    /**
     * Chemin de l'IMAGE d'un module, ou null.
     *
     * Remplace cheminAsset(), qui servait `client/` et `assets/` en JS, CSS et
     * SVG. Un module ne contient plus de code : il n'y a donc plus rien à
     * servir en dehors de son logo, et il vaut mieux ne pas savoir le faire que
     * de savoir le faire en le refusant.
     *
     * Quatre contrôles, tous nécessaires : identifiant conforme, chemin sans
     * remontée, résultat confiné au dossier du module, extension matricielle.
     */
    public static function cheminImage(string $identifiant, string $relatif): ?string
    {
        if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $identifiant)) return null;
        if (!preg_match('#^assets/[a-zA-Z0-9_\-]{1,80}\.(png|jpg|jpeg|webp)$#', $relatif)) {
            return null;
        }

        $base = self::normaliser(self::dossierCode($identifiant));
        $abs  = self::normaliser($base . '/' . $relatif);
        if (!str_starts_with($abs, $base . '/') || !is_file($abs)) return null;

        // L'extension a beau être contrôlée par le motif ci-dessus, on vérifie
        // que le contenu EST une image : un fichier nommé logo.png peut être
        // n'importe quoi, et l'en-tête Content-Type que nous poserons ferait
        // foi auprès du navigateur.
        $info = @getimagesize($abs);
        if ($info === false || !in_array($info[2] ?? 0,
                [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        return $abs;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Utilitaires
    // ═══════════════════════════════════════════════════════════════════════

    /** realpath() ne fonctionne que sur l'existant : on normalise à la main. */
    private static function normaliser(string $chemin): string
    {
        $chemin = str_replace('\\', '/', $chemin);
        $morceaux = [];
        foreach (explode('/', $chemin) as $m) {
            if ($m === '' || $m === '.') { if ($morceaux === []) $morceaux[] = ''; continue; }
            if ($m === '..') { array_pop($morceaux); continue; }
            $morceaux[] = $m;
        }
        return implode('/', $morceaux);
    }

    public static function supprimerRecursif(string $dossier): void
    {
        if (!is_dir($dossier)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dossier);
    }

    /** Ménage des dépliages temporaires abandonnés (examen jamais confirmé). */
    public static function nettoyerTemporaires(int $ageSecondes = 3600): void
    {
        $tmp = self::racineDonnees() . '/tmp';
        if (!is_dir($tmp)) return;
        foreach (glob($tmp . '/*') ?: [] as $d) {
            if (is_dir($d) && (time() - filemtime($d)) > $ageSecondes) {
                self::supprimerRecursif($d);
            }
        }
    }

    /**
     * Fabrique un paquet .larka à partir d'un dossier — pour les auteurs.
     * Reproduit la contrainte du « mimetype » stocké en premier, que ni zip(1)
     * ni un clic droit « Compresser » ne produisent naturellement.
     */
    public static function construire(string $dossierSource, string $cheminSortie): array
    {
        if (!is_file($dossierSource . '/extension.json')) {
            throw new RuntimeException('extension.json introuvable dans ' . $dossierSource);
        }
        $man = ExtManifeste::charger($dossierSource);

        @unlink($cheminSortie);
        $zip = new ZipArchive();
        if ($zip->open($cheminSortie, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Création du paquet impossible.');
        }
        // Impérativement en premier, et non compressé.
        $zip->addFromString('mimetype', self::MIMETYPE);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dossierSource, FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dossierSource) + 1));
            if ($rel === 'mimetype') continue;
            if (!preg_match(self::RE_CHEMIN, $rel)) {
                $zip->close(); @unlink($cheminSortie);
                throw new RuntimeException("Fichier à un emplacement non prévu : « $rel ». "
                    . 'Rangez le code serveur dans serveur/ et le code client dans client/.');
            }
            $zip->addFile($f->getPathname(), $rel);
            $n++;
        }
        $zip->close();

        $info = self::verifier($cheminSortie);   // on relit ce qu'on vient d'écrire
        return $info + ['fichiers' => $n];
    }
}
