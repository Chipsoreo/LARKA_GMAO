<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — banc d'essai HERMÉTIQUE de l'assistant.
 *
 * L'épreuve ne touche ni à votre config.json ni à votre base : elle recopie
 * api/ dans un dossier temporaire, y écrit un config.json SQLite, crée une base
 * neuve et la peuple d'un site fictif (fixture.php). Elle tourne donc de la
 * même façon sur un poste de dev, en CI ou sur un serveur PostgreSQL.
 *
 * Retourne la base prête à l'emploi. Exige pdo_sqlite.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

function larka_banc_assistant(): Database
{
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "❌ pdo_sqlite est requis pour l'épreuve de l'assistant (base d'essai jetable).\n");
        exit(2);
    }
    $source = dirname(__DIR__, 3);
    $racine = sys_get_temp_dir() . '/larka-epreuve-assistant-' . getmypid() . '-' . bin2hex(random_bytes(3));
    @mkdir($racine . '/data', 0700, true);

    // Copie de api/ (le code éprouvé est celui de l'arbre de travail).
    $copier = function (string $de, string $vers) use (&$copier): void {
        @mkdir($vers, 0700, true);
        foreach (scandir($de) as $f) {
            if ($f === '.' || $f === '..') continue;
            $a = "$de/$f"; $b = "$vers/$f";
            is_dir($a) ? $copier($a, $b) : copy($a, $b);
        }
    };
    $copier($source . '/api', $racine . '/api');
    copy($source . '/version.json', $racine . '/version.json');
    file_put_contents($racine . '/config.json', json_encode([
        'serveur'         => ['env' => 'dev'],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/epreuve.db'],
        'superadmin_db'   => ['driver' => 'sqlite', 'path' => 'data/epreuve_admin.db'],
        'assistant'       => ['actif' => true, 'fournisseur' => 'ollama'],
    ], JSON_PRETTY_PRINT));

    register_shutdown_function(function () use ($racine) {
        $suppr = function (string $d) use (&$suppr): void {
            foreach (@scandir($d) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                is_dir("$d/$f") ? $suppr("$d/$f") : @unlink("$d/$f");
            }
            @rmdir($d);
        };
        $suppr($racine);
    });

    // config.php ouvre une session : en CLI c'est sans effet, mais on la range
    // dans le dossier temporaire.
    ini_set('session.save_path', $racine . '/data');
    require_once $racine . '/api/config.php';
    // config.php coupe l'affichage des erreurs (production) : ici on veut tout voir.
    ini_set('display_errors', 'stderr');
    ini_set('log_errors', '0');
    error_reporting(E_ALL);
    require_once $racine . '/api/Database.php';
    require_once $racine . '/api/AssistantTools.php';
    require_once $racine . '/api/AssistantLLM.php';
    require_once $racine . '/api/AssistantMoteur.php';
    require_once $racine . '/api/AssistantDemandeur.php';
    require_once $racine . '/api/AssistantFiable.php';

    $db = new Database();
    require_once __DIR__ . '/fixture.php';
    larka_fixture_assistant($db);
    return $db;
}
