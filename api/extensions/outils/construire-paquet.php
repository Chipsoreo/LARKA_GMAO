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
 * Larka — Extensions : fabrication d'un paquet .larka
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un dossier d'extension ne s'installe pas : c'est le paquet .larka qui
 * s'installe. Et il ne se fabrique pas au clic droit « Compresser » — la
 * première entrée de l'archive doit être « mimetype », stockée sans
 * compression, ce qu'aucun outil grand public ne produit.
 *
 * D'où ce script. Sans lui, on modifie son extension, on oublie de reconstruire
 * le paquet, et l'interface installe encore l'ancienne version — ou n'affiche
 * rien du tout. Cela m'est arrivé deux fois en développant cette couche.
 *
 * USAGE
 *   construire-paquet.php <dossier>              construit le paquet
 *   construire-paquet.php --tous                 construit tout extensions/*
 *   construire-paquet.php <dossier> --sortie f   sortie explicite
 *   construire-paquet.php --nouveau <id>         crée un squelette d'extension
 *   construire-paquet.php --info <fichier.larka> inspecte un paquet existant
 *
 * Le paquet est écrit à côté du dossier source, nommé <identifiant>-<version>.larka,
 * et RELU aussitôt : ce qui est annoncé à l'écran est ce que le fichier contient
 * réellement, pas ce qu'on croit y avoir mis.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

$racine = dirname(__DIR__, 3);

// LARKA_VERSION est nécessaire à la validation du manifeste (champ larka_min).
// On la lit dans api/config.php sans charger la configuration complète : ce
// script doit tourner sans base de données ni config.json.
if (!defined('LARKA_VERSION')) {
    $src = @file_get_contents($racine . '/api/config.php');
    if ($src && preg_match("/define\('LARKA_VERSION',\s*'([^']+)'/", $src, $m)) {
        define('LARKA_VERSION', $m[1]);
    } else {
        define('LARKA_VERSION', '1.0.0');
    }
}

require_once $racine . '/api/extensions/Paquet.php';
require_once $racine . '/api/extensions/Ancrages.php';

// ── Prérequis, vérifié AVANT toute autre chose ───────────────────────────────
// Sans cette garde, l'outil s'arrêtait sur « Class "ZipArchive" not found » :
// une trace PHP brute, qui ne dit ni ce qui manque ni comment l'installer. Un
// outil en ligne de commande doit expliquer ce qu'il attend.
if (!ExtPaquet::zipDisponible()) {
    fwrite(STDERR,
        "\n  ✗ L'extension PHP « zip » n'est pas chargée : impossible de créer un paquet.\n\n"
      . "    Debian / Ubuntu :  sudo apt install php" . PHP_MAJOR_VERSION . '.'
        . PHP_MINOR_VERSION . "-zip\n"
      . "    Fedora / Nobara :  sudo dnf install php-zip\n\n"
      . "    Puis :             sudo systemctl restart php-fpm   (ou relancez ./start.sh)\n"
      . "    Vérification :     php -m | grep -x zip\n\n");
    exit(3);
}

// ── Arguments ────────────────────────────────────────────────────────────────
$args = array_slice($argv, 1);
$sortie = null;
$tous = false;
$dossiers = [];

$nouveau = null;
$info    = null;

for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--tous')        { $tous = true; continue; }
    if ($args[$i] === '--sortie')      { $sortie = $args[++$i] ?? null; continue; }
    if ($args[$i] === '--nouveau')     { $nouveau = $args[++$i] ?? null; continue; }
    if ($args[$i] === '--info')        { $info = $args[++$i] ?? null; continue; }
    if (str_starts_with($args[$i], '-')) {
        fwrite(STDERR, "Option inconnue : {$args[$i]}\n");
        exit(2);
    }
    $dossiers[] = rtrim($args[$i], '/');
}

// ── --info : inspecter un paquet déjà construit ─────────────────────────────
if ($info !== null) {
    if (!str_starts_with($info, '/')) $info = $racine . '/' . $info;
    try {
        $d = ExtPaquet::verifier($info);
        echo "\n" . basename($info) . "\n";
        printf("  %s v%s\n  %s\n", $d['nom'], $d['version'], $d['auteur']);
        printf("  identifiant : %s\n", $d['identifiant']);
        printf("  %d entrées · %s Ko · déplié %s Ko\n", $d['entrees'],
            number_format($d['taille_paquet'] / 1024, 1, ',', ' '),
            number_format($d['taille_deplie'] / 1024, 1, ',', ' '));
        printf("  empreinte : %s\n", $d['empreinte_paquet']);
        printf("  signature : %s\n\n", $d['signe']
            ? 'présente, mais NON vérifiée par cette version' : 'absente');
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "\n  ✗ " . $e->getMessage() . "\n\n");
        exit(1);
    }
}

// ── --nouveau : squelette d'extension ───────────────────────────────────────
if ($nouveau !== null) {
    if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?\.[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?$/',
                    (string)$nouveau)) {
        fwrite(STDERR, "\n  ✗ Identifiant invalide : « $nouveau ».\n"
            . "    Format attendu : vendeur.nom — ex. « acme.suivi-cles »\n\n");
        exit(2);
    }
    // Rangé dans modules/ : un squelette produit un module de données, et c'est
    // là qu'il a sa place dans la nouvelle disposition.
    $cible = $racine . '/extensions/modules/' . $nouveau;
    if (is_dir($cible)) {
        fwrite(STDERR, "\n  ✗ Le dossier extensions/modules/$nouveau existe déjà.\n\n");
        exit(2);
    }
    @mkdir($cible, 0755, true);

    $nomLisible = ucfirst(str_replace('-', ' ', explode('.', $nouveau)[1]));
    $cle = preg_replace('/[^a-z0-9]/', '', explode('.', $nouveau)[1]);

    // ⚠️ CE SQUELETTE ÉTAIT MORT-NÉ.
    // Il produisait « serveur/main.php », « client/page.js », une clé
    // « capacites » et aucun « format ». Or le manifeste refuse désormais tout
    // module sans "format": "declaratif/1", refuse explicitement les clés
    // « serveur » et « client », et déduit les capacités au lieu de les lire.
    // Les deux fichiers de code auraient de toute façon été rejetés à l'entrée
    // du paquet. Un auteur suivant la documentation partait donc d'un modèle
    // qui ne pouvait pas s'installer — et l'erreur qu'il recevait parlait d'une
    // clé qu'il n'avait pas écrite lui-même.
    file_put_contents($cible . '/extension.json', json_encode([
        'identifiant' => $nouveau,
        'format'      => 'declaratif/1',
        'nom'         => $nomLisible,
        'version'     => '0.1.0',
        'auteur'      => 'Votre nom <vous@exemple.fr>',
        'description' => 'À compléter : ce que fait ce module, en une phrase.',
        'larka_min'   => defined('LARKA_VERSION') ? LARKA_VERSION : '1.0.0',
        // api_min est recalculé à chaque construction : inutile d'y toucher.
        // Les capacités NE SE DÉCLARENT PAS : Larka les déduit de ce qui suit.
        'donnees' => [
            $cle => [
                'libelle'         => $nomLisible,
                'libelle_pluriel' => $nomLisible,
                'champs' => [
                    'nom' => ['type' => 'texte', 'libelle' => 'Nom',
                              'obligatoire' => true, 'max' => 120],
                    'note' => ['type' => 'texte_long', 'libelle' => 'Remarques',
                               'largeur' => 'pleine'],
                    'cree' => ['type' => 'date', 'libelle' => 'Créé le',
                               'defaut' => '{{today}}'],
                ],
            ],
        ],
        'pages' => [[
            'cle'   => $cle,
            'titre' => $nomLisible,
            'icone' => 'puzzle',
            'roles' => ['Gestionnaire', 'Admin'],
            'vue'   => [
                'type'      => 'liste',
                'source'    => $cle,
                'colonnes'  => ['nom', 'cree'],
                'recherche' => ['nom'],
                'actions'   => ['creer', 'modifier', 'supprimer', 'exporter'],
            ],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    file_put_contents($cible . '/README.md',
        "# $nomLisible\n\n$nouveau — version 0.1.0\n\n"
      . "Module déclaratif : il décrit des données et un écran, Larka les dessine.\n"
      . "Aucun code n'est exécuté.\n\n"
      . "## Construire le paquet\n\n```bash\n"
      . "php api/extensions/outils/construire-paquet.php extensions/modules/$nouveau\n```\n");

    echo "\n  ✓ extensions/modules/$nouveau créé\n";
    echo "      extension.json · README.md\n\n";
    echo "    Un registre à trois champs, valide et installable tel quel.\n";
    echo "    Prochaine étape :\n";
    echo "      php api/extensions/outils/construire-paquet.php extensions/modules/$nouveau\n\n";
    exit(0);
}

if ($tous) {
    // On balaie la racine ET les sous-dossiers de rangement. Un dossier de
    // rangement n'est pas un module : on le reconnaît à l'absence de manifeste,
    // et on descend dedans plutôt que d'essayer de le construire.
    $candidats = glob($racine . '/extensions/*', GLOB_ONLYDIR) ?: [];
    foreach (ExtPaquet::SOUS_DOSSIERS as $sd) {
        $candidats = array_merge($candidats,
            glob($racine . '/extensions/' . $sd . '/*', GLOB_ONLYDIR) ?: []);
    }
    // Le filtre sur extension.json écarte de lui-même « config/ », « modules/ »
    // et les autres dossiers de rangement : ils n'ont pas de manifeste.
    $candidats = array_values(array_filter($candidats,
        fn($d) => is_file($d . '/extension.json')));

    foreach ($candidats as $d) {
        if (is_file($d . '/extension.json')) $dossiers[] = $d;
    }
    if ($dossiers === []) {
        echo "Aucun dossier d'extension trouvé dans extensions/.\n";
        exit(0);
    }
}

if ($dossiers === []) {
    $n = basename(__FILE__);
    echo <<<AIDE

  Larka — fabrication des paquets .larka

    php $n <dossier>                 construit le paquet d'une extension
    php $n --tous                    construit tous les dossiers d'extensions/
    php $n <dossier> --sortie f.larka écrit le paquet à l'emplacement indiqué
    php $n --nouveau acme.mon-module crée un squelette prêt à modifier
    php $n --info fichier.larka      inspecte un paquet sans l'installer

  Le paquet est écrit à côté du dossier source, sous le nom
  <identifiant>-<version>.larka, après validation complète du manifeste.

AIDE;
    exit(2);
}
if ($sortie !== null && count($dossiers) > 1) {
    fwrite(STDERR, "--sortie ne peut viser qu'un seul paquet.\n");
    exit(2);
}

// ── Construction ─────────────────────────────────────────────────────────────
$echecs = 0;

foreach ($dossiers as $dossier) {
    // Chemin relatif accepté : on travaille depuis la racine du projet.
    if (!str_starts_with($dossier, '/')) {
        $dossier = $racine . '/' . $dossier;
    }
    $court = str_replace($racine . '/', '', $dossier);

    echo "\n─── $court\n";

    if (!is_dir($dossier) || !is_file($dossier . '/extension.json')) {
        echo "  ✗ Pas un dossier d'extension (extension.json introuvable).\n";
        $echecs++;
        continue;
    }

    try {
        $cheminManifeste = $dossier . '/extension.json';
        $manifeste = json_decode((string)file_get_contents($cheminManifeste), true);

        // ── api_min calculé, pas deviné ──────────────────────────────────────
        // Un auteur qui utilise une capacité récente sans relever « api_min »
        // publie une extension qui échoue sur les installations plus anciennes
        // avec un message trompeur (« capacité inconnue »). Le calcul est
        // mécanique : on le fait ici plutôt que de compter sur la vigilance.
        $requis = max(
            ExtCapacites::apiMinimale($manifeste['capacites'] ?? []),
            ExtAncrages::apiMinimale($manifeste['ancrages'] ?? [])
        );
        $declare = $manifeste['api_min'] ?? '1.0';
        if (version_compare($declare, $requis, '<')) {
            $manifeste['api_min'] = $requis;
            // Réécriture en conservant l'ordre des clés : le manifeste est lu
            // par des humains, on ne le réordonne pas dans leur dos.
            $ordonne = [];
            foreach ($manifeste as $k => $v) {
                $ordonne[$k] = $v;
                if ($k === 'larka_min') $ordonne['api_min'] = $requis;
            }
            if (!isset($ordonne['api_min'])) $ordonne['api_min'] = $requis;
            file_put_contents($cheminManifeste,
                json_encode($ordonne, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                    | JSON_UNESCAPED_SLASHES) . "\n");
            $manifeste = $ordonne;
            echo "  · api_min relevé à $requis (était $declare) — manifeste mis à jour\n";
        }
        /**
         * ── Les images déclarées sont-elles vraiment là ? ────────────────
         *
         * ⚠️ UN LOGO ABSENT SE CONSTRUISAIT SANS UN MOT.
         * Le manifeste pouvait désigner « assets/logo.png » sans que le
         * fichier existe : le paquet se fabriquait, s'installait, et l'écran
         * Modules affichait une icône brisée. L'auteur, lui, a le fichier sur
         * son disque — il ne voit jamais le défaut. Et personne ne remarque
         * une image qui manque, seulement une image qui est là.
         *
         * On vérifie donc ici, au seul moment où le dossier source est sous la
         * main. Le contrôle vaut pour le logo comme pour les images de mise en
         * page, et il REFUSE : un paquet dont une pièce manque n'est pas un
         * paquet, c'est une promesse.
         */
        $imagesManquantes = [];
        foreach (ExtSchemaDeclaratif::imagesDeclarees($manifeste) as $img) {
            if (!is_file($dossier . '/' . $img)) $imagesManquantes[] = $img;
        }
        if ($imagesManquantes !== []) {
            throw new RuntimeException('Image(s) déclarée(s) mais absente(s) du '
                . 'dossier : ' . implode(', ', $imagesManquantes)
                . '. Déposez-les, ou retirez-les de la déclaration.');
        }

        $cible = $sortie ?? dirname($dossier) . '/'
               . ($manifeste['identifiant'] ?? basename($dossier))
               // Une THÉMATIQUE n'apporte que des valeurs — un thème, des
               // traductions. Elle n'a ni écran ni données : elle n'est pas
               // installée comme un module, elle est LUE par le moteur.
               //
               // Un module qui déclare une page reste un « .larka », même sans
               // données : le moteur d'apparence en est un.
               . '-' . ($manifeste['version'] ?? '0.0.0')
               . ((empty($manifeste['donnees']) && empty($manifeste['pages']))
                  ? ExtPaquet::EXTENSION_THEMATIQUE : ExtPaquet::EXTENSION);

        // Les anciennes versions du même module encombrent la liste des paquets
        // à importer : on les retire, sauf si une sortie explicite est demandée.
        if ($sortie === null && !empty($manifeste['identifiant'])) {
            foreach (glob(dirname($dossier) . '/' . $manifeste['identifiant']
                          . '-*') ?: [] as $vieux) {
                if (!ExtPaquet::extensionAcceptee($vieux)) continue;
                if ($vieux !== $cible) { @unlink($vieux); echo "  · ancien paquet retiré : "
                    . basename($vieux) . "\n"; }
            }
        }

        $info = ExtPaquet::construire($dossier, $cible);

        printf("  ✓ %s\n", basename($cible));
        printf("    %s v%s — %s\n", $info['nom'], $info['version'], $info['auteur']);
        printf("    API extensions requise : %s (serveur : %s)\n",
            $manifeste['api_min'] ?? '1.0', ExtCapacites::VERSION_API);
        printf("    %d fichiers · %s Ko · empreinte %s…\n",
            $info['fichiers'], number_format($info['taille_paquet'] / 1024, 1, ',', ' '),
            substr($info['empreinte_paquet'], 0, 16));
        if (!empty($manifeste['capacites'])) {
            printf("    %d permission(s) : %s\n", count($manifeste['capacites']),
                implode(', ', $manifeste['capacites']));
        }

    } catch (\Throwable $e) {
        echo "  ✗ " . $e->getMessage() . "\n";
        $echecs++;
    }
}

echo "\n";
if ($echecs > 0) {
    echo "$echecs paquet(s) en échec.\n";
    exit(1);
}
echo "Terminé. Importez le paquet depuis Modules → glisser-déposer, ou « Examiner »\n"
   . "s'il se trouve déjà dans extensions/.\n";
exit(0);
