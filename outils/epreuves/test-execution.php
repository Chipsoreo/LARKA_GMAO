<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — épreuve d'exécution réelle de la couche modules.
 *
 * `php -l` vérifie la SYNTAXE, pas la portée des variables : une variable
 * devenue orpheline après un nettoyage passe le lint sans un mot, et n'explose
 * qu'à l'affichage, chez l'utilisateur. C'est arrivé — « Undefined variable
 * $mode » sur l'écran d'installation, après le retrait des modes d'exécution.
 *
 * Cette épreuve parcourt le chemin complet : installer, examiner, écrire, lire,
 * ancrer — avec les avertissements PHP transformés en erreurs. Une variable non
 * définie fait donc échouer le test au lieu de passer inaperçue.
 *
 *   php outils/epreuves/test-execution.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

// Le point de l'épreuve : tout avertissement devient une exception.
set_error_handler(static function (int $n, string $msg, string $f, int $l): bool {
    throw new ErrorException("$msg  ($f:$l)", 0, $n, $f, $l);
});

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/config.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/extensions/Registre.php';

$ok = 0; $ko = [];

function etape(string $titre, callable $f, int &$ok, array &$ko): mixed
{
    try {
        $r = $f();
        $ok++;
        printf("  ✅  %-46s %s\n", $titre, is_string($r) ? $r : 'OK');
        return $r;
    } catch (\Throwable $e) {
        $ko[] = $titre . ' : ' . $e->getMessage();
        printf("  ❌  %-46s %s\n", $titre, mb_substr($e->getMessage(), 0, 60));
        return null;
    }
}

/**
 * Une valeur d'épreuve qui RESPECTE ce que le champ déclare.
 *
 * ⚠️ LE GÉNÉRATEUR PRÉCÉDENT IGNORAIT LES CONTRÔLES DE SAISIE.
 * Tout ce qui n'était ni nombre, ni date, ni choix, ni booléen, ni lien
 * recevait la chaîne « Épreuve ». Un champ obligatoire portant
 * `format_saisie: immatriculation` la refusait — à juste titre — et l'épreuve
 * comptait un échec du PRODUIT là où c'était son propre jeu d'essai qui ne
 * respectait pas le format annoncé. Elle mesurait son générateur.
 *
 * Le catalogue des formats porte déjà un exemple valide pour chacun : on s'en
 * sert. Une seule source, et un format ajouté au schéma est automatiquement
 * éprouvable sans toucher ici.
 */
function valeurEpreuve(array $c, int $eq): mixed
{
    $type = (string)($c['type'] ?? 'texte');

    // Un format de saisie prime : c'est lui qui sera vérifié à l'écriture.
    $f = (string)($c['format_saisie'] ?? '');
    if ($f !== '' && isset(ExtSchemaDeclaratif::FORMATS_SAISIE[$f])) {
        return ExtSchemaDeclaratif::FORMATS_SAISIE[$f]['exemple'];
    }

    // Les bornes déclarées sont des refus en puissance : on se place dedans.
    $borne = static function (float $v) use ($c): float {
        if (isset($c['min']) && $c['min'] !== null) $v = max($v, (float)$c['min']);
        if (isset($c['max']) && $c['max'] !== null) $v = min($v, (float)$c['max']);
        return $v;
    };

    return match ($type) {
        'entier'         => (int)$borne(1),
        'decimal'        => $borne(1),
        'pourcentage'    => $borne(50),
        'note'           => (int)$borne(3),
        'duree'          => (int)$borne(30),
        'date'           => date('Y-m-d', strtotime(
                                '+' . max(0, (int)($c['min_jours'] ?? 0)) . ' days')),
        'horodatage'     => date('Y-m-d H:i'),
        'heure'          => '09:30',
        'couleur'        => '#1b3a5c',
        'choix'          => $c['valeurs'][0] ?? 'Épreuve',
        'choix_multiple' => $c['valeurs'][0] ?? 'Épreuve',
        'booleen'        => true,
        'lien'           => $eq,
        // Un texte doit tenir dans sa longueur maximale déclarée.
        default          => mb_substr('Épreuve', 0, max(1, (int)($c['max'] ?? 200))),
    };
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — chemin complet d'un module, avertissements compris\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

try {
    $db = new Database();
    $pdo = $db->getPdo();
} catch (\Throwable $e) {
    echo "  ⏭️  Section sautée : aucune base accessible (" . get_class($e) . ").\n";
    exit(0);
}
$eq = $db->addEquipement(['numero' => 'EQ-TEST', 'famille' => 'CVC',
                          'marque' => 'Test', 'modele' => 'T1'], 'test');

/**
 * ⚠️ CETTE ÉPREUVE NE TESTAIT PLUS RIEN, ET SORTAIT EN SUCCÈS.
 *
 * Elle cherchait `extensions/*.larka`. Les paquets vivent dans des
 * sous-dossiers depuis leur rangement par famille — `extensions/modules/`,
 * `extensions/themes/`, `extensions/langues/`. Le glob ne rendait donc plus
 * rien, et la ligne suivante quittait avec le code 0 : « rien à éprouver ».
 *
 * C'est le pire état possible pour une épreuve. Celle-ci est précisément celle
 * qui transforme les avertissements PHP en erreurs sur le chemin complet
 * — installer, examiner, écrire, lire, ancrer. Muette et verte, elle certifiait
 * un chemin que plus personne ne parcourait : les six paquets livrés n'ont pas
 * été éprouvés depuis leur rangement.
 *
 * On balaie donc `extensions/` en PROFONDEUR, ce qui couvre l'ancien plan
 * comme le nouveau, et l'on REFUSE de sortir vert quand il n'y a rien à faire :
 * une épreuve sans matière est un incident, pas un succès.
 */
$paquets = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $racine . '/extensions', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'larka') {
        $paquets[] = $f->getPathname();
    }
}
sort($paquets);

if ($paquets === []) {
    echo "  ❌  Aucun paquet .larka sous extensions/ : cette épreuve n'a rien pu\n";
    echo "      parcourir. Ce n'est pas un succès — c'est une épreuve aveugle.\n\n";
    exit(1);
}
printf("  %d paquet(s) à parcourir.\n\n", count($paquets));

foreach ($paquets as $paquet) {
    $id = null;
    echo '  ' . basename($paquet) . "\n";

    etape('Vérifier le paquet', function () use ($paquet, &$id) {
        $i = ExtPaquet::verifier($paquet);
        $id = $i['identifiant'];
        return $id;
    }, $ok, $ko);
    if ($id === null) continue;

    /**
     * ⚠️ L'ÉPREUVE NE POUVAIT PAS ÊTRE REJOUÉE.
     * Elle écrivait un enregistrement d'essai et le laissait en base. Au
     * passage suivant, tout champ « unique » refusait la même valeur — « la
     * valeur "Épreuve" est déjà utilisée » — et l'échec était imputé au code.
     * Une épreuve qui ne passe qu'une fois ne protège de rien : on la relance,
     * elle est rouge, et l'on cesse de la lancer.
     *
     * On vide donc les tables du module AVANT de l'installer : c'est
     * l'installation qui les recrée. Les vider après l'aurait privé de ses
     * tables, et toutes les étapes suivantes auraient été refusées.
     */
    // Le compteur de surveillance comportementale, aussi : un passage qui
    // échoue accumule des refus, et dix refus BLOQUENT le module. Le passage
    // suivant se heurtait alors à « operation_refusee » sur tout — y compris
    // sur ce qui marche. L'épreuve se punissait elle-même d'avoir échoué.
    //
    // is_file() plutôt que « @ » : cette épreuve transforme les avertissements
    // en exceptions, et l'arobase ne les arrête pas — supprimer un fichier
    // absent la faisait tomber net. C'est précisément le durcissement qu'elle
    // impose au produit ; elle s'y soumet.
    $compteur = $racine . '/data/securite/compteurs/' . $id . '.json';
    if (is_file($compteur)) unlink($compteur);

    if (is_dir((string)ExtPaquet::dossierCode($id))) {
        try {
            $precedente = ExtManifeste::charger(ExtPaquet::dossierCode($id))->declaration();
            foreach (array_keys($precedente['donnees'] ?? []) as $jeuPrecedent) {
                $db->getPdo()->exec('DROP TABLE IF EXISTS "'
                    . ExtPolitiqueDonnees::prefixeExtension($id) . $jeuPrecedent . '"');
            }
            ExtPolitiqueDonnees::oublier();
        } catch (\Throwable $e) { /* rien d'installé : rien à vider */ }
    }

    etape('Installer', function () use ($paquet, $id, $db) {
        ExtPaquet::installer($paquet, $id);
        $reg = ExtRegistre::initialiser($db, ['Login' => 'test', 'Role' => 'Gestionnaire']);
        $reg->installer($id, ExtManifeste::charger(ExtPaquet::dossierCode($id))->capacites(), 'test');
        return 'installé';
    }, $ok, $ko);

    // C'est CET appel qui échouait : l'écran d'installation lit le rapport.
    // Un module d'habillage n'a ni données ni écran : les étapes qui suivent
    // n'ont pas d'objet pour lui.
    $decl = ExtManifeste::charger(ExtPaquet::dossierCode($id))->declaration();
    if (empty($decl['donnees'])) {
        $ok++;
        printf("  ✅  %-46s habillage : ni données, ni écran\n", 'Module d\'interface');
        echo "\n";
        continue;
    }

    etape('Examiner (rapport d\'installation)', function () use ($db, $id) {
        $reg = ExtRegistre::initialiser($db, ['Login' => 'test', 'Role' => 'Gestionnaire']);
        $r = $reg->examiner($id);
        if (empty($r['resume_declaration']['actions'])) {
            throw new RuntimeException('résumé vide : l\'écran n\'aurait rien à montrer');
        }
        return count($r['resume_declaration']['actions']) . ' action(s) décrite(s)';
    }, $ok, $ko);

    etape('Inventaire (écran Modules)', function () use ($db) {
        $reg = ExtRegistre::initialiser($db, ['Login' => 'test', 'Role' => 'Gestionnaire']);
        return count($reg->inventaire()) . ' module(s)';
    }, $ok, $ko);

    etape('Description client (chargement des pages)', function () use ($db) {
        $reg = ExtRegistre::initialiser($db, ['Login' => 'test', 'Role' => 'Gestionnaire']);
        return count($reg->descriptionClient()) . ' page(s)';
    }, $ok, $ko);

    $reg = ExtRegistre::initialiser($db, ['Login' => 'test', 'Role' => 'Gestionnaire']);
    $man = ExtManifeste::charger(ExtPaquet::dossierCode($id));
    $jeu = array_key_first($man->declaration()['donnees']);


    etape('Écrire un enregistrement', function () use ($reg, $id, $jeu, $man, $eq) {
        $valeurs = [];
        foreach ($man->declaration()['donnees'][$jeu]['champs'] as $cle => $c) {
            if (($c['type'] ?? '') === 'formule') continue;
            if (!empty($c['obligatoire'])) {
                $valeurs[$cle] = valeurEpreuve($c, $eq);
            }
        }
        $r = $reg->executerAction($id, 'dp_enregistrer', ['jeu' => $jeu, 'valeurs' => $valeurs]);
        return 'id ' . ($r['id'] ?? '?');
    }, $ok, $ko);

    etape('Lister', function () use ($reg, $id, $jeu) {
        return $reg->executerAction($id, 'dp_lister', ['jeu' => $jeu])['nombre'] . ' ligne(s)';
    }, $ok, $ko);

    /**
     * ⚠️ L'EXPORT N'EST PAS UN DROIT ACQUIS.
     *
     * Cette étape exportait toujours, et comptait comme un échec le refus
     * d'un module qui ne déclare pas l'action « exporter » — alors que ce
     * refus est exactement ce que le format promet : une action non déclarée
     * n'est pas servie. L'épreuve reprochait au produit d'avoir raison.
     *
     * On lit donc ce que la page déclare, et l'on vérifie le comportement
     * ATTENDU dans les deux cas : l'export réussit là où il est déclaré, et il
     * est refusé là où il ne l'est pas. Le second cas vaut le premier — c'est
     * la frontière d'autorisation qui tient.
     */
    $exportDeclare = false;
    foreach ($man->declaration()['pages'] ?? [] as $pg) {
        if (($pg['vue']['source'] ?? '') !== $jeu) continue;
        if (in_array('exporter', $pg['vue']['actions'] ?? [], true)) $exportDeclare = true;
    }

    etape($exportDeclare ? 'Exporter' : 'Export refusé (action non déclarée)',
        function () use ($reg, $id, $jeu, $exportDeclare) {
            if ($exportDeclare) {
                return strlen($reg->executerAction($id, 'dp_exporter',
                    ['jeu' => $jeu])['contenu']) . ' octets';
            }
            try {
                $reg->executerAction($id, 'dp_exporter', ['jeu' => $jeu]);
            } catch (\Throwable $e) {
                return 'refusé, comme déclaré';
            }
            throw new RuntimeException('export servi alors que la page ne le déclare pas');
        }, $ok, $ko);

    foreach ($man->declaration()['ancrages'] ?? [] as $i => $a) {
        etape('Ancrage ' . $a['type'], function () use ($reg, $id, $i, $eq) {
            $r = $reg->executerAction($id, 'dp_ancrage',
                ['index' => $i, 'etage' => 1, 'valeur_lien' => $eq]);
            return $r['type'];
        }, $ok, $ko);
    }
    echo "\n";
}

restore_error_handler();

$total = $ok + count($ko);
echo "───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — chemin complet parcouru, aucun avertissement PHP.\n", $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $e) echo "      • $e\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);
