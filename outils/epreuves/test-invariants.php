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
 * Larka — invariants de sécurité
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Vérifie que les propriétés dont dépend toute la sécurité sont toujours vraies
 * dans le code. Ce ne sont pas des tests de comportement : ce sont des garde-fous
 * contre une modification bien intentionnée qui ferait tomber une frontière.
 *
 * Exemple typique : quelqu'un ajoute `allow-same-origin` à l'iframe pour régler
 * un problème de style. Tout continue de fonctionner, les suites d'attaques
 * serveur passent toujours — et l'isolement client n'existe plus. Rien ne le
 * signalerait sans ce fichier.
 *
 *   php outils/epreuves/test-invariants.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
$echecs = [];
$ok = 0;

function invariant(string $titre, callable $test, array &$echecs, int &$ok): void
{
    try {
        $r = $test();
        if ($r === true) { $ok++; printf("  ✅  %s\n", $titre); return; }
        $echecs[] = [$titre, is_string($r) ? $r : 'condition fausse'];
        printf("  ❌  %-58s %s\n", $titre, is_string($r) ? $r : '');
    } catch (\Throwable $e) {
        $echecs[] = [$titre, $e->getMessage()];
        printf("  ❌  %-58s %s\n", $titre, $e->getMessage());
    }
}

$lire = fn(string $f) => is_file($racine . '/' . $f)
    ? (string)file_get_contents($racine . '/' . $f) : '';

/**
 * Retire les commentaires avant de chercher un motif.
 *
 * Sans cela, l'invariant « allow-same-origin ne doit pas apparaître » se
 * déclenchait sur le commentaire qui explique justement pourquoi il ne faut pas
 * l'ajouter. Un contrôle qui crie au loup sur sa propre documentation finit
 * ignoré — donc désarmé.
 */
$sansCommentaires = function (string $code): string {
    $code = preg_replace('#/\*.*?\*/#s', '', $code);      // blocs
    $code = preg_replace('#(^|[^:])//.*$#m', '$1', $code);  // lignes, hors ://
    return $code;
};

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — invariants de sécurité des extensions\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

echo "  Bac à sable client\n";






echo "\n  Security API\n";

invariant('Aucun chemin de SQL brut pour un module', function () use ($racine) {
    // L'invariant visait Contexte.php, supprimé avec les modules à code. Il
    // reste pertinent, mais sur la nouvelle surface : la seule voie d'un module
    // vers la base est la requête structurée.
    $sources = '';
    foreach (array_merge(glob($racine . '/api/extensions/*.php'),
                         glob($racine . '/api/extensions/securite/*.php'),
                         glob($racine . '/api/extensions/declaratif/*.php')) as $f) {
        $sources .= file_get_contents($f);
    }
    // Une méthode publique qui accepterait du SQL de l'extérieur rouvrirait la
    // porte ; les requêtes construites par le cœur, elles, sont légitimes.
    if (preg_match('/public function sql\s*\(/', $sources)) {
        return 'une méthode publique « sql() » est réapparue : un module pourrait '
             . 'transmettre sa propre requête';
    }
    return true;
}, $echecs, $ok);

invariant('Aucune capacité de SQL libre au catalogue', function () use ($lire) {
    return !str_contains($lire('api/extensions/Capacites.php'), "'donnees.sql_lecture'")
        ?: 'la capacité donnees.sql_lecture est revenue';
}, $echecs, $ok);

invariant('DELETE, DROP, TRUNCATE, ALTER absents de l\'API', function () use ($lire) {
    $r = $lire('api/extensions/securite/Requete.php');
    if (!preg_match("/const ACTIONS = \[([^\]]*)\]/", $r, $m)) return 'liste ACTIONS introuvable';
    foreach (['DELETE', 'DROP', 'TRUNCATE', 'ALTER'] as $mot) {
        if (str_contains($m[1], $mot)) return "$mot est devenu une action autorisée";
    }
    return true;
}, $echecs, $ok);

invariant('Un UPDATE sans condition est refusé', function () use ($lire) {
    return str_contains($lire('api/extensions/securite/Requete.php'), 'update_sans_condition')
        ?: 'le garde-fou a disparu : un UPDATE toucherait toute la table';
}, $echecs, $ok);

invariant('Les messages d\'erreur restent opaques', function () use ($lire) {
    $j = $lire('api/extensions/securite/JournalSecurite.php');
    return str_contains($j, "MESSAGE_OPAQUE = 'operation_refusee'")
        ?: 'le message opaque a changé : un motif détaillé renseignerait l\'attaquant';
}, $echecs, $ok);

invariant('Le refus par défaut s\'applique aux tables inconnues', function () use ($lire) {
    $p = $lire('api/extensions/securite/PolitiqueDonnees.php');
    return str_contains($p, 'return self::SYSTEME_PROTEGE;   // refus par défaut')
        ?: 'une table inconnue n\'est plus traitée comme protégée';
}, $echecs, $ok);

invariant('Le moteur d\'expressions n\'exécute aucun code',
    function () use ($lire, $sansCommentaires) {
        $code = $sansCommentaires($lire('api/extensions/declaratif/Expression.php'));
        // On cherche l'APPEL, pas le mot : « evaluer » et « evaluation »
        // contiennent « eval » sans rien exécuter. Un contrôle qui se déclenche
        // sur les noms de méthodes du fichier qu'il surveille est un contrôle
        // qu'on finit par désactiver.
        foreach (['eval', 'create_function', 'call_user_func', 'call_user_func_array',
                  'assert', 'preg_replace_callback', 'system', 'exec',
                  'shell_exec', 'passthru', 'include', 'require'] as $interdit) {
            if (preg_match('/(?<![a-zA-Z_])' . preg_quote($interdit, '/')
                           . '\s*\(/', $code)) {
                return "un appel à « $interdit() » est apparu dans le moteur "
                     . "d'expressions : une formule de pack redeviendrait du code";
            }
        }
        // Un appel de fonction par variable serait le même trou, déguisé.
        if (preg_match('/\$[a-zA-Z_]+\s*\(/', $code)) {
            return 'appel de fonction par variable détecté dans le moteur';
        }
        return true;
    }, $echecs, $ok);

invariant('Le catalogue de fonctions reste fermé', function () use ($lire) {
    $c = $lire('api/extensions/declaratif/Expression.php');
    return str_contains($c, 'if (!isset(self::FONCTIONS[$nom]))')
        ?: 'le contrôle du catalogue a disparu : une fonction inconnue pourrait '
         . 'être cherchée ailleurs';
}, $echecs, $ok);

invariant('Le catalogue d\'opérateurs reste fermé', function () use ($lire) {
    $c = $lire('api/extensions/declaratif/Condition.php');
    return str_contains($c, 'if (!isset(self::OPERATEURS[$op]))')
        ?: 'le contrôle du catalogue d\'opérateurs a disparu';
}, $echecs, $ok);

invariant('La sortie réseau reste interdite aux modules de données',
    function () use ($lire) {
        $c = $lire('api/extensions/declaratif/Schema.php');
        foreach (['webhooks', 'domaines_reseau'] as $mot) {
            if (!str_contains($c, "'" . $mot . "'")) {
                return "« $mot » n'est plus refusé : un module de données pourrait "
                     . 'contacter un serveur extérieur et exfiltrer des données';
            }
        }
        return true;
    }, $echecs, $ok);


invariant('Chaque ancrage du catalogue est réellement rendu',
    function () use ($racine) {
        require_once $racine . '/api/extensions/Ancrages.php';
        $sources = '';
        foreach (array_merge(glob($racine . '/js/*.js'), glob($racine . '/js/pages/*.js')) as $f) {
            $sources .= file_get_contents($f);
        }
        $morts = [];
        foreach (array_keys(ExtAncrages::catalogue()) as $a) {
            // Deux façons légitimes de servir un emplacement :
            //   • rendrePoint('<emplacement>') — l'extension pose un encart ;
            //   • lecture directe de la chaîne — l'écran d'accueil décide
            //     lui-même quoi en faire, comme « demandes.type » qui devient
            //     un bouton dans « Nouvelle demande ».
            // Exiger la première forme obligeait à écrire un appel factice
            // pour satisfaire l'épreuve : le contrôle aurait fabriqué le
            // symptôme qu'il cherche à éviter.
            if (!str_contains($sources, "rendrePoint('" . $a . "'")
                && !str_contains($sources, "'" . $a . "'")) {
                $morts[] = $a . ' (jamais appelé)';
                continue;
            }
            // L'appel ne suffit pas : il vise un conteneur par identifiant, et
            // un conteneur absent fait échouer le rendu en silence. Le cas
            // s'est produit sur la fiche d'un bien — l'appel existait, le div
            // non, et l'invariant passait quand même.
            if (preg_match("/rendrePoint\('" . preg_quote($a, '/')
                           . "',\s*\n?\s*document\.getElementById\('([a-zA-Z]+)'\)/",
                           $sources, $m)) {
                if (!str_contains($sources, 'id="' . $m[1] . '"')) {
                    $morts[] = $a . ' (conteneur « ' . $m[1] . ' » absent du gabarit)';
                }
            }
        }
        return $morts === [] ? true
            : 'ancrage(s) non rendu(s) : ' . implode(', ', $morts);
    }, $echecs, $ok);

invariant('Aucun accès à une colonne sensible à la casse',
    function () use ($racine, $sansCommentaires) {
        // PostgreSQL et MariaDB replient les identifiants non quotés en
        // minuscules : une colonne créée « Id » s'y nomme « id ». Lire
        // $ligne['Id'] fonctionne sur SQLite et échoue en production — le
        // défaut est invisible en développement, et systématique ensuite.
        $fautes = [];
        foreach (glob($racine . '/api/extensions/declaratif/*.php') as $f) {
            $code = $sansCommentaires((string)file_get_contents($f));
            // Une majuscule dans un accès direct par clé de résultat SQL.
            if (preg_match_all('/\$[a-z][a-zA-Z]*\[\x27(Id|Numero|Marque|Modele|Famille|'
                             . 'Societe|Reference|Designation|Type|Statut)\x27\]/',
                             $code, $m)) {
                $fautes[] = basename($f) . ' : ' . implode(', ', array_unique($m[0]));
            }
        }
        return $fautes === [] ? true
            : 'accès sensible(s) à la casse : ' . implode(' | ', $fautes)
            . ' — utilisez une lecture insensible à la casse';
    }, $echecs, $ok);

invariant('Toute capacité du catalogue est atteignable',
    function () use ($racine) {
        require_once $racine . '/api/extensions/Capacites.php';
        require_once $racine . '/api/extensions/declaratif/Schema.php';

        // Une capacité qu'aucune déclaration ne peut faire naître n'inquiète
        // l'administrateur que pour rien, et noie les vraies permissions.
        //
        // Cet invariant a d'abord comparé le catalogue à une LISTE ÉCRITE À LA
        // MAIN des capacités jugées atteignables. Elle est tombée en retard dès
        // l'ajout de « fichiers.dossier » — non pas parce que la capacité était
        // inatteignable, mais parce que personne n'avait pensé à l'inscrire.
        // L'épreuve signalait alors un défaut inexistant, ce qui use la
        // confiance aussi sûrement qu'un défaut manqué.
        //
        // On CONSTRUIT donc une déclaration qui exerce tout ce que le format
        // sait produire, on la fait valider par le schéma réel, et l'on demande
        // au schéma lui-même quelles capacités elle engendre. Ce qui reste au
        // catalogue sans jamais apparaître est réellement orphelin.
        $champs = ['nom' => ['type' => 'texte']];
        foreach (array_keys(ExtSchemaDeclaratif::CIBLES_LIEN) as $i => $c) {
            $champs['lien' . $i] = ['type' => 'lien', 'vers' => $c];
        }
        foreach (array_keys(ExtSchemaDeclaratif::SOURCES_SUGGESTION) as $i => $src) {
            $champs['sugg' . $i] = ['type' => 'texte', 'suggestions' => true,
                                    'suggestions_source' => $src];
        }
        $tout = [
            'format'  => ExtSchemaDeclaratif::FORMAT,
            'donnees' => ['jeu' => ['libelle' => 'Jeu', 'champs' => $champs]],
            'pages'   => [['cle' => 'p', 'titre' => 'P',
                           'vue' => ['type' => 'liste', 'source' => 'jeu']]],
            'ancrages' => [['emplacement' => 'dashboard.tuiles', 'type' => 'compteur',
                            'source' => 'jeu', 'libelle' => 'Tout']],
            'fichiers' => ['res' => ['natures' => ['image'], 'import' => true]],
            'interface' => [['element' => 'chorus', 'operation' => 'masquer']],
        ];
        $valide = ExtSchemaDeclaratif::valider($tout);
        $possibles = ExtSchemaDeclaratif::capacitesRequises($valide);

        $orphelines = array_diff(array_keys(ExtCapacites::catalogue()), $possibles);
        return $orphelines === [] ? true
            : 'capacité(s) qu\'aucun pack ne peut obtenir : ' . implode(', ', $orphelines);
    }, $echecs, $ok);

invariant('Domaines et cibles de lien restent alignés',
    function () use ($racine) {
        require_once $racine . '/api/extensions/Capacites.php';
        require_once $racine . '/api/extensions/declaratif/Schema.php';
        $a = array_keys(ExtCapacites::DOMAINES);
        $b = array_keys(ExtSchemaDeclaratif::CIBLES_LIEN);
        // Les domaines ouverts par une source de suggestions comptent aussi.
        foreach (ExtSchemaDeclaratif::SOURCES_SUGGESTION as $s) {
            $b[] = $s['table'] === null ? 'noms' : strtolower($s['table']);
        }
        $b = array_values(array_unique($b));
        sort($a); sort($b);
        return $a === $b ? true
            : 'deux listes divergentes : DOMAINES (' . implode(',', $a) . ') '
            . 'et CIBLES_LIEN (' . implode(',', $b) . ')';
    }, $echecs, $ok);

invariant('Rien ne lit un fichier de module hors extension.json',
    function () use ($racine, $sansCommentaires) {
        // LA propriété de fond : la sécurité ne vient pas du refus du code,
        // elle vient de l'absence de lecteur. Un .js présent dans un paquet ne
        // fait rien — non parce qu'il est interdit, mais parce qu'aucune ligne
        // de Larka ne va le chercher.
        //
        // Portée : la couche extensions uniquement. Le reste du produit charge
        // légitimement ses propres fichiers ; une première version balayait
        // api/routes/ et remontait toutes les routes du produit, ce qui rendait
        // l'invariant inexploitable et donc inutile.
        $suspects = [];
        foreach (array_merge(glob($racine . '/api/extensions/*.php'),
                             glob($racine . '/api/extensions/*/*.php')) as $f) {
            $code = $sansCommentaires((string)file_get_contents($f));

            // Toute inclusion doit viser un chemin littéral du cœur.
            // « require $fichier » chargeait le point d'entrée d'un module :
            // c'est exactement ce qui a été retiré.
            foreach (['require ', 'require_once ', 'include ', 'include_once '] as $mot) {
                $pos = 0;
                while (($pos = strpos($code, $mot, $pos)) !== false) {
                    $suite = ltrim(substr($code, $pos + strlen($mot)));
                    $pos += strlen($mot);
                    if (str_starts_with($suite, '__DIR__')) continue;
                    if (str_starts_with($suite, "'")) continue;
                    if (str_starts_with($suite, '$racine')) continue;
                    $suspects[] = basename($f) . ' : ' . $mot
                                . mb_substr(strtok($suite, ";\n"), 0, 32);
                }
            }

            foreach (['eval(', 'create_function(', 'preg_replace_callback('] as $d) {
                if (str_contains($code, $d)) $suspects[] = basename($f) . " : $d";
            }
        }
        return $suspects === [] ? true
            : 'chemin(s) d\'exécution possible(s) : ' . implode(' | ', $suspects);
    }, $echecs, $ok);

invariant('L\'extraction n\'écrit que le format défini',
    function () use ($racine, $sansCommentaires) {
        // La garantie de fond : ce n'est pas le REFUS du code qui protège, c'est
        // l'absence de mécanisme capable de l'écrire — puis de le lire.
        // Un filtre se contourne ; une liste blanche d'extraction, non.
        $p = $sansCommentaires((string)file_get_contents(
            $racine . '/api/extensions/Paquet.php'));
        if (!str_contains($p, 'private static function extractible(')) {
            return 'la liste blanche d\'extraction a disparu : l\'archive serait '
                 . 'à nouveau dépliée en entier';
        }
        if (!str_contains($p, 'if (!self::extractible($nom)) continue;')) {
            return 'la liste blanche n\'est plus appliquée à l\'extraction';
        }
        // Aucun format exécutable ne doit y figurer.
        if (preg_match('/function extractible.*?\n    \}/s', $p, $m)) {
            foreach (['php', 'js', 'css', 'html', 'svg', 'phar'] as $ext) {
                if (str_contains($m[0], "'" . $ext . "'") || str_contains($m[0], $ext . '|')) {
                    return "« $ext » est devenu extractible : du code atterrirait sur le disque";
                }
            }
        }
        return true;
    }, $echecs, $ok);

invariant('Un paquet ne peut contenir aucun format exécutable',
    function () use ($racine, $sansCommentaires) {
        $p = $sansCommentaires((string)file_get_contents(
            $racine . '/api/extensions/Paquet.php'));
        foreach (['php', 'js', 'css', 'html', 'svg'] as $ext) {
            if (preg_match('/TYPES_AUTORISES[^;]*\x27' . $ext . '\x27/s', $p)) {
                return "« $ext » est redevenu un type de fichier accepté dans un paquet";
            }
        }
        // serveur/ et client/ ne doivent plus figurer dans les chemins acceptés.
        if (preg_match('/RE_CHEMIN[^;]*(serveur|client)\|/s', $p)) {
            return 'les dossiers serveur/ ou client/ sont réacceptés dans un paquet';
        }
        return true;
    }, $echecs, $ok);

invariant('Rien ne sait lire un fichier de module hors image',
    function () use ($racine, $sansCommentaires) {
        // Refuser le code est un filtre ; ne pas savoir le lire est structurel.
        // Un filtre se contourne — par une restauration, une copie manuelle,
        // une régression. Une capacité absente, non.
        $sources = '';
        foreach (array_merge(glob($racine . '/api/extensions/*.php'),
                             glob($racine . '/api/routes/extensions.php')) as $f) {
            $sources .= $sansCommentaires((string)file_get_contents($f));
        }
        if (str_contains($sources, 'cheminAsset') || str_contains($sources, "'ext_asset'")) {
            return 'la route ext_asset est revenue : elle sait servir du JS et du CSS '
                 . 'depuis le dossier d\'un module';
        }
        // Le lecteur d'image ne doit accepter que du matriciel. On isole SON
        // motif d'extensions plutôt que de chercher dans tout le fichier : une
        // expression gourmande débordait sur le reste du code et signalait un
        // faux positif — le genre d'alerte qui apprend à ignorer les alertes.
        if (preg_match('/cheminImage\(.{0,900}?\\\.\(([a-z|]+)\)/s', $sources, $m)) {
            $formats = explode('|', $m[1]);
            $interdits = array_diff($formats, ['png', 'jpg', 'jpeg', 'webp']);
            if ($interdits !== []) {
                return 'cheminImage() accepte : ' . implode(', ', $interdits);
            }
        }
        return true;
    }, $echecs, $ok);

invariant('Aucun script de module n\'est transmis au navigateur',
    function () use ($racine, $sansCommentaires) {
        $r = $sansCommentaires((string)file_get_contents(
            $racine . '/api/extensions/Registre.php'));
        return preg_match("/'scripts'\s*=>\s*\[\]/", $r) === 1
            ?: 'le registre transmet à nouveau des scripts au client';
    }, $echecs, $ok);

echo "\n  Isolation serveur\n";




invariant('Le chargement échoue fermé si la sécurité manque', function () use ($lire) {
    return str_contains($lire('api/extensions/Registre.php'), 'conditionsSecurite === false')
        ?: 'le contrôle fail-closed a disparu du chargement';
}, $echecs, $ok);

// ── Tout ce qui entre dans un paquet doit pouvoir en sortir ─────────────────
//
// inspecterEntrees() dit ce qu'un paquet a le droit de CONTENIR ;
// extractible() dit ce qui est ÉCRIT sur le disque. Les deux listes vivent
// dans le même fichier, à cinquante lignes d'écart, et rien ne les reliait.
//
// « parties/ » a été ajouté à la première et oublié dans la seconde : les
// fragments $ref voyageaient dans l'archive, passaient la vérification,
// figuraient dans la liste du contenu — et disparaissaient à l'installation.
// Le module s'installait sans eux et échouait au premier chargement.
//
// Un fichier accepté puis jamais écrit est le pire des deux mondes : il donne
// l'impression d'être là.
invariant('Fichiers acceptés et extractibles', function () use ($racine) {
    // Cette épreuve inspecte une méthode privée : il faut la classe, pas
    // seulement son fichier source comme le font les contrôles voisins.
    require_once $racine . '/api/extensions/Paquet.php';
    $ext = new ReflectionMethod('ExtPaquet', 'extractible');
    $ext->setAccessible(true);

    $doiventSortir = ['extension.json', 'README.md', 'LICENSE',
                      'parties/creneau.json', 'assets/logo.png'];
    foreach ($doiventSortir as $n) {
        if (!$ext->invoke(null, $n)) {
            return "« $n » est accepté dans un paquet mais jamais écrit sur le disque.";
        }
    }
    $doiventRester = ['serveur/main.php', 'client/page.js', 'assets/x.svg',
                      'parties/x.php', 'parties/sous/x.json', 'parties/../x.json'];
    foreach ($doiventRester as $n) {
        if ($ext->invoke(null, $n)) {
            return "« $n » ne devrait jamais être écrit sur le disque.";
        }
    }
    return true;
}, $echecs, $ok);

$total = $ok + count($echecs);

echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($echecs === []) {
    printf(" ✅ %d/%d invariants tenus.\n", $ok, $total);
} else {
    printf(" ❌ %d invariant(s) rompu(s) sur %d :\n", count($echecs), $total);
    foreach ($echecs as [$t, $m]) echo "      • $t\n        $m\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($echecs === [] ? 0 : 1);
