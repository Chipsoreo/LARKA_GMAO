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
 * Larka — Mises à jour de l'application
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * DÉTECTION AUTOMATIQUE, INSTALLATION MANUELLE
 *   - verifier() interroge la source (GitHub Releases ou un manifeste JSON) au
 *     plus toutes les N heures ; le résultat est mis en cache.
 *   - installer() n'est JAMAIS appelé tout seul : il faut qu'un administrateur
 *     clique, en confirmant la version exacte qu'il a lue.
 *
 * LES DONNÉES NE SONT JAMAIS TOUCHÉES
 *   Seuls les fichiers de l'APPLICATION sont remplacés. Restent intacts :
 *     data/ (base SQLite, plans, médias, modules installés, sauvegardes…),
 *     config.json, .env, extensions/config/ (réglages des modules : un fichier
 *     existant n'est jamais écrasé, seuls les nouveaux sont ajoutés).
 *   Aucun fichier n'est supprimé, même obsolète. Le schéma de base évolue par
 *   ajout de colonnes au démarrage (Database), jamais par suppression.
 *
 * SÉCURITÉ (le code téléchargé va s'exécuter sur le serveur)
 *   - HTTPS uniquement, redirections HTTPS uniquement, taille bornée.
 *   - Empreinte SHA-256 OBLIGATOIRE (publiée avec la version) et vérifiée.
 *   - Signature Ed25519 OBLIGATOIRE, vérifiée avec la clé publique de
 *     l'éditeur livrée DANS LE CODE (api/CleEditeur.php). Version non signée,
 *     mal signée, ou installation sans clé : REFUSÉE.
 *     ⚠️ La clé était lue dans config.json et la vérification sautait quand
 *     elle était vide (le défaut). L'empreinte venant de la même source que
 *     l'archive, rien n'en garantissait l'origine : un dépôt GitHub compromis
 *     suffisait. Et config.json se modifie depuis l'interface — qui tenait ce
 *     compte pouvait désigner sa propre clé et sa propre source.
 *     Outil de l'éditeur : outils/publier-version.php.
 *   - Archive contrôlée (pas de chemin « .. » ni absolu), version plus récente
 *     exigée, verrou contre deux installations simultanées.
 *
 * FILET DE SÉCURITÉ
 *   Avant d'écrire quoi que ce soit : sauvegarde des fichiers qui vont être
 *   remplacés, et copie de CHAQUE base (principale et tenants ; SQLite :
 *   instantané, PostgreSQL / MariaDB : sauvegarde SQL). Une copie qui échoue
 *   annule la mise à jour.
 *   Une erreur en cours d'écriture restaure automatiquement la version
 *   précédente ; restaurer() permet aussi de revenir en arrière à la main.
 *
 * CONFIGURATION (config.json → mises_a_jour, tout est facultatif)
 *   actif            true
 *   source           "github" | "manifeste"
 *   depot            "Chipsoreo/LARKA_GMAO"     (source github)
 *   url_manifeste    "https://…/latest.json"    (source manifeste)
 *   canal            "beta" (versions préliminaires incluses) | "stable"
 *   frequence_heures 12
 *   (cle_publique n'est PLUS lue ici : voir api/CleEditeur.php.)
 *   Jeton GitHub (dépôt privé) : .env → GMAO_MAJ_GITHUB_TOKEN
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Gardés : « mise-a-jour.php --racine » charge la configuration d'une AUTRE
// installation (qui a déjà déclaré ces classes et fonctions) avant ce fichier.
if (!class_exists('LarkaVersion')) require_once __DIR__ . '/Version.php';
if (!function_exists('larka_chemin_absolu')) require_once __DIR__ . '/Chemins.php';
if (!class_exists('LarkaPrerequis')) require_once __DIR__ . '/Prerequis.php';

class LarkaMiseAJour
{
    /** Jamais écrits par une mise à jour (« php » : le PHP que Larka.cmd installe sous Windows). */
    private const PROTEGES = ['data', 'config.json', '.env', 'config.key', '.git', '.github', 'php'];
    /**
     * Fichiers de configuration du SERVEUR, à la racine : jamais écrits non plus,
     * quelle que soit leur variante (« .env.local », « config.json.bak-… »,
     * « config.key.old »…). Une archive préparée à la main peut en contenir une
     * copie ; l'écrire remplaçait silencieusement le réglage en service.
     */
    private const PROTEGES_MOTIF = '#^(\.env($|\.)|config\.json($|\.)|config\.key($|\.)|Caddyfile$)#';
    /** Écrits seulement s'ils n'existent pas encore (réglages de modules). */
    private const AJOUT_SEUL = ['extensions/config/'];
    /**
     * Chaîne de confiance des mises à jour : JAMAIS remise par une restauration.
     * Une sauvegarde contient les fichiers de la version précédente ; restaurer
     * l'ancien MiseAJour.php rétablissait la signature facultative et la clé lue
     * dans config.json — de quoi annuler, depuis l'interface, la signature
     * obligatoire. Ils ne changent que par une mise à jour signée.
     */
    private const JAMAIS_RESTAURES = ['api/MiseAJour.php', 'api/CleEditeur.php', 'api/routes/majs.php',
                                      'api/outils/mise-a-jour.php'];
    /** Dernière version publiée SANS signature obligatoire (base de ce correctif). */
    private const DERNIERE_SANS_SIGNATURE = '2.0.2';
    /** Dossiers de développement : mis à jour seulement s'ils sont déjà présents
     *  (deploy/install.sh ne les copie pas en production). */
    private const SI_PRESENTS = ['outils', 'Documentations', 'deploy', 'tests', 'demo'];
    /**
     * La démonstration (demo/) fait exception : apportée aussi là où elle manque
     * — une installation de poste ou de développement faite depuis une archive
     * qui ne la contenait pas (2.1.0) —, sauf en production (« env: prod » ou
     * code sous /var/www), où elle n'a rien à faire.
     */
    private function demoBienvenue(): bool
    {
        if (is_dir($this->racine . '/demo')) return true;
        $env = function_exists('cfg') ? strtolower((string)(cfg('serveur', 'env') ?? '')) : '';
        return $env !== 'prod' && !str_starts_with(str_replace('\\', '/', $this->racine), '/var/www/');
    }
    private const TAILLE_MAX = 250 * 1024 * 1024;
    /** Version de PHP minimale de Larka (start.sh vérifie la même). Une version publiée peut en exiger une plus récente (« php_min »). */
    public const PHP_MIN = '8.1';
    /** Fin du support de sécurité de chaque branche de PHP (php.net/supported-versions). */
    private const PHP_FIN_SUPPORT = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31',
                                     '8.4' => '2028-12-31', '8.5' => '2029-12-31'];
    /** Extensions dont la mise à jour a besoin (sodium : la signature est toujours vérifiée). */
    private const EXTENSIONS = ['curl' => 'curl_init', 'zip' => 'ZipArchive', 'openssl' => 'openssl_verify',
                                'sodium' => 'sodium_crypto_sign_verify_detached'];

    private string $racine;
    private string $dossier;

    public function __construct(?string $racine = null)
    {
        $this->racine = rtrim($racine ?? dirname(__DIR__), '/');
        $this->dossier = $this->racine . '/data/maj';
        foreach (['', '/telechargements', '/sauvegardes'] as $d) {
            if (!is_dir($this->dossier . $d)) @mkdir($this->dossier . $d, 0750, true);
        }
        // Première exécution de ce code sur ce serveur : la version EN PLACE
        // est la première qui exige la signature. Notée une fois pour toutes
        // (data/ n'est jamais touché par une mise à jour ni une restauration) ;
        // restaurer() s'en sert pour refuser, depuis l'interface, les
        // sauvegardes plus anciennes.
        // (Pas tant que le numéro en place n'a pas dépassé 2.0.2 : un arbre de
        // travail non encore publié porte encore l'ancien numéro.)
        if (self::versionNotee($this->lireJson('signature-depuis.json')) === null) {
            $v = (string)($this->locale()['version'] ?? '');
            if (preg_match('/^\d+\.\d+\.\d+$/', $v) && version_compare($v, self::DERNIERE_SANS_SIGNATURE, '>')) {
                $this->ecrireJson('signature-depuis.json', ['version' => $v, 'le' => date('c')]);
            }
        }
    }

    private static function versionNotee(array $d): ?string
    {
        $v = (string)($d['version'] ?? '');
        return preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : null;
    }

    /**
     * Une sauvegarde se restaure depuis l'interface seulement si ses fichiers
     * viennent d'une version qui exigeait déjà la signature (et embarque donc
     * les correctifs qui vont avec). Décidé sur le NUMÉRO DE VERSION des
     * fichiers sauvegardés : un drapeau posé par le code qui fait la sauvegarde
     * mentait après une restauration en ligne de commande (outil de mise à jour
     * récent, mais fichiers anciens). Dans le doute : non.
     */
    private function restaurableDepuisInterface(?array $meta): bool
    {
        $base = self::versionNotee($this->lireJson('signature-depuis.json'));
        $v = self::versionNotee(is_array($meta['version'] ?? null) ? $meta['version'] : []);
        return $base !== null && $v !== null
            && version_compare($v, self::DERNIERE_SANS_SIGNATURE, '>')
            && version_compare($v, $base, '>=');
    }

    private static function cfg(string $k, mixed $defaut = null): mixed
    {
        $v = function_exists('cfg') ? cfg('mises_a_jour', $k) : null;
        return ($v === null || $v === '') ? $defaut : $v;
    }

    public function actif(): bool { return (bool)self::cfg('actif', true); }

    public function locale(): array
    {
        $v = LarkaVersion::lire($this->racine . '/version.json');
        return $v + ['libelle' => LarkaVersion::libelle($v)];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  État et détection
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * PHP du serveur : version, compatibilité, fin de support, extensions
     * manquantes et, si besoin, la commande pour le mettre à jour. La mise à
     * jour fonctionne sur toute version supportée ; sinon elle le DIT, avec la
     * marche à suivre, au lieu d'échouer sur une erreur obscure.
     */
    public function php(?string $requis = null): array
    {
        $branche = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $min = $requis && version_compare($requis, self::PHP_MIN, '>') ? $requis : self::PHP_MIN;
        $ok = version_compare(PHP_VERSION, $min, '>=');
        $fin = self::PHP_FIN_SUPPORT[$branche] ?? null;
        $perime = $fin !== null && date('Y-m-d') > $fin;
        $manquantes = [];
        foreach (self::EXTENSIONS as $ext => $symbole) {
            if (!function_exists($symbole) && !class_exists($symbole)) $manquantes[] = $ext;
        }
        $conseil = null;
        if (!$ok) $conseil = "PHP $branche est trop ancien : il faut PHP $min ou plus récent. Mettez PHP à jour, puis relancez la vérification.";
        elseif ($manquantes) $conseil = 'Extension(s) PHP manquante(s) : ' . implode(', ', $manquantes) . '. Installez-les, puis redémarrez PHP.';
        elseif ($perime) $conseil = "PHP $branche ne reçoit plus de correctifs de sécurité depuis le " . date('d/m/Y', strtotime($fin)) . ' : pensez à le mettre à jour.';
        return [
            'version' => PHP_VERSION, 'branche' => $branche, 'minimum' => $min, 'ok' => $ok && !$manquantes,
            'fin_support' => $fin, 'perime' => $perime, 'extensions_manquantes' => $manquantes,
            'conseil' => $conseil, 'commande' => $conseil ? self::commandePhp($manquantes) : null,
        ];
    }

    /** Commande de mise à jour de PHP adaptée au système (lue dans /etc/os-release). */
    private static function commandePhp(array $extensions = []): string
    {
        // Sous Windows, PHP est celui que Larka.cmd a posé dans le dossier php\.
        if (PHP_OS_FAMILY === 'Windows') {
            return 'Fermez la fenêtre de Larka, puis lancez « Larka.cmd php » (dossier de Larka) : '
                 . 'il installe la dernière version de PHP et ses extensions';
        }
        $os = @parse_ini_file('/etc/os-release') ?: [];
        $id = strtolower(($os['ID'] ?? '') . ' ' . ($os['ID_LIKE'] ?? ''));
        $ext = $extensions ? ' ' . implode(' ', array_map(fn($e) => "php-$e", $extensions)) : '';
        if (preg_match('/\b(fedora|rhel|centos|rocky|almalinux|nobara)\b/', $id)) return "sudo dnf upgrade --refresh 'php*'" . ($ext ? " && sudo dnf install$ext" : '');
        if (preg_match('/\b(debian|ubuntu)\b/', $id)) return 'sudo apt update && sudo apt install --only-upgrade php php-cli php-fpm' . ($ext ? " && sudo apt install$ext" : '')
            . '   (version récente : dépôt « deb.sury.org » sur Debian, « ppa:ondrej/php » sur Ubuntu)';
        if (preg_match('/\barch\b/', $id)) return 'sudo pacman -Syu php';
        if (preg_match('/\b(opensuse|suse)\b/', $id)) return 'sudo zypper update php*';
        return 'Mettez PHP à jour avec le gestionnaire de paquets du système, puis redémarrez PHP-FPM / le serveur web.';
    }

    /** « PHP ≥ 8.2 » écrit dans les notes d'une version publiée. */
    private static function phpMinDesNotes(string $notes): ?string
    {
        return preg_match('/\bPHP\s*(?:≥|>=|>|minimum\s*:?|min\.?\s*:?|requis\s*:?)\s*(\d+\.\d+)/iu', $notes, $m) ? $m[1] : null;
    }

    /** État en cache (sans appel réseau). a_verifier = le cache est périmé. */
    public function etat(): array
    {
        $e = $this->lireJson('etat.json');
        $freq = max(1, (int)self::cfg('frequence_heures', 12)) * 3600;
        $locale = $this->locale();
        $dispo = !empty($e['distante']['version'])
            && version_compare($e['distante']['version'], $locale['version'], '>');
        $requis = $dispo ? ($e['distante']['php_min'] ?? null) : null;
        $prerequis = $dispo && is_array($e['distante']['prerequis'] ?? null) ? $e['distante']['prerequis'] : null;
        return [
            'php'         => $this->php($requis),
            'actif'       => $this->actif(),
            'locale'      => $locale,
            'distante'    => $dispo ? $e['distante'] : null,
            'disponible'  => $dispo,
            'verifie_le'  => $e['verifie_le'] ?? null,
            'erreur'      => $e['erreur'] ?? null,
            'a_verifier'  => $this->actif() && (time() - (int)($e['ts'] ?? 0)) > $freq,
            // Toujours vraie désormais : plus de mise à jour sans signature.
            'signature_exigee' => true,
            'cle_editeur' => self::clePubliqueEditeur() !== null,
            // Ancien réglage encore présent dans config.json : il est ignoré,
            // l'écran peut le signaler pour éviter une fausse impression.
            'cle_config_ignoree' => (string)self::cfg('cle_publique', '') !== '',
            'installable' => $this->installable($requis, $prerequis),
        ];
    }

    /** Interroge la source et met le cache à jour. */
    public function verifier(): array
    {
        $e = ['ts' => time(), 'verifie_le' => date('c')];
        try {
            if (!function_exists('curl_init')) {
                throw new RuntimeException("Extension PHP « curl » absente : impossible d'interroger la source des versions. " . self::commandePhp(['curl']));
            }
            $e['distante'] = self::cfg('source', 'github') === 'manifeste'
                ? $this->depuisManifeste()
                : $this->depuisGithub();
            $e['erreur'] = null;
        } catch (\Throwable $ex) {
            $ancien = $this->lireJson('etat.json');
            $e['distante'] = $ancien['distante'] ?? null;   // on garde la dernière connue
            $e['erreur'] = $ex->getMessage();
        }
        $this->ecrireJson('etat.json', $e);
        return $this->etat();
    }

    private function depuisGithub(): array
    {
        $depot = (string)self::cfg('depot', 'Chipsoreo/LARKA_GMAO');
        if (!preg_match('#^[\w.-]+/[\w.-]+$#', $depot)) throw new RuntimeException('Dépôt GitHub invalide.');
        $json = json_decode($this->telecharger("https://api.github.com/repos/$depot/releases?per_page=15", null, 5_000_000), true);
        if (!is_array($json)) throw new RuntimeException('Réponse GitHub illisible.');
        $beta = strtolower((string)self::cfg('canal', strcasecmp($this->locale()['canal'], 'Stable') === 0 ? 'stable' : 'beta')) !== 'stable';
        $cands = [];
        foreach ($json as $rel) {
            if (!empty($rel['draft']) || (!$beta && !empty($rel['prerelease']))) continue;
            if (!preg_match('/(\d+\.\d+\.\d+)/', (string)($rel['tag_name'] ?? '') . ' ' . ($rel['name'] ?? ''), $m)) continue;
            // L'archive de la version est celle qui a sa signature (.sig) à côté. Une
            // release peut porter d'autres .zip — la démonstration, par exemple — et
            // la première venue était prise : sans .sha256 ni .sig à son nom, la
            // version était refusée sur tous les serveurs. À défaut de signature, la
            // première archive reste candidate : son refus est expliqué à l'écran.
            $zip = null; $sha = null; $urlSha = null; $urlSig = null;
            $noms = array_map(static fn($a) => (string)($a['name'] ?? ''), $rel['assets'] ?? []);
            foreach ([true, false] as $signeeSeulement) {
                foreach ($rel['assets'] ?? [] as $a) {
                    $n = (string)($a['name'] ?? '');
                    if (str_ends_with($n, '.zip') && (!$signeeSeulement || in_array($n . '.sig', $noms, true))) { $zip = $a; break 2; }
                }
            }
            if (!$zip) continue;
            if (preg_match('/^sha256:([a-f0-9]{64})$/i', (string)($zip['digest'] ?? ''), $d)) $sha = strtolower($d[1]);
            // .sha256 et .sig ne sont lus qu'au besoin (choisir()) : seulement pour
            // les versions candidates, la plus récente d'abord.
            foreach ($rel['assets'] ?? [] as $a) {
                $n = (string)($a['name'] ?? '');
                if ($n === $zip['name'] . '.sha256') $urlSha = (string)($a['browser_download_url'] ?? '');
                elseif ($n === $zip['name'] . '.sig') $urlSig = (string)($a['browser_download_url'] ?? '');
            }
            if (!$sha && preg_match('/sha-?256\s*[:=]\s*([a-f0-9]{64})/i', (string)($rel['body'] ?? ''), $d)) $sha = strtolower($d[1]);
            $cands[] = [
                'version'  => $m[1],
                'canal'    => !empty($rel['prerelease']) ? 'Beta' : 'Stable',
                'date'     => substr((string)($rel['published_at'] ?? ''), 0, 10),
                'notes'    => mb_substr((string)($rel['body'] ?? ''), 0, 8000),
                'zip'      => (string)$zip['browser_download_url'],
                'taille'   => (int)($zip['size'] ?? 0),
                'sha256'   => $sha,
                'signature'=> null,
                'page'     => (string)($rel['html_url'] ?? ''),
                'php_min'  => self::phpMinDesNotes((string)($rel['body'] ?? '')),
                '_url_sha' => $urlSha, '_url_sig' => $urlSig,
            ];
        }
        if (!$cands) throw new RuntimeException('Aucune version publiée (avec archive .zip) sur ' . $depot . '.');
        $best = $this->choisir($cands, function (array $c): array {
            if (!$c['sha256'] && $c['_url_sha']
                && preg_match('/\b([a-f0-9]{64})\b/i', $this->telecharger($c['_url_sha'], null, 10_000), $d)) $c['sha256'] = strtolower($d[1]);
            if ($c['_url_sig']) $c['signature'] = trim($this->telecharger($c['_url_sig'], null, 10_000)) ?: null;
            return $c;
        });
        unset($best['_url_sha'], $best['_url_sig']);
        $best['libelle'] = LarkaVersion::libelle($best);
        return $best;
    }

    /**
     * Version à proposer : la plus récente, plus récente que l'installée, dont
     * la signature est celle de la clé de CE serveur ; à défaut, la plus récente
     * (son installation sera refusée, avec la raison à l'écran).
     * ⚠️ Seule la plus récente était regardée. Après une rotation de clé, un
     * serveur qui n'avait pas installé la version de transition (signée avec
     * l'ancienne clé) ne se voyait plus proposer que des versions signées avec
     * la nouvelle — refusées, sans fin : il ne pouvait plus jamais se mettre à
     * jour. La transition lui est désormais proposée d'abord.
     * $completer lit au besoin l'empreinte et la signature d'un candidat.
     */
    private function choisir(array $cands, ?callable $completer = null): array
    {
        usort($cands, fn($a, $b) => version_compare($b['version'], $a['version']));
        $faits = [];
        // Sans clé ni sodium, rien ne peut être authentifié : inutile de lire
        // les signatures de toutes les versions à chaque vérification.
        if (self::clePubliqueEditeur() !== null && function_exists('sodium_crypto_sign_verify_detached')) {
            $installee = (string)($this->locale()['version'] ?? '0.0.0');
            foreach ($cands as $i => $c) {
                if (!version_compare($c['version'], $installee, '>')) break;
                if ($completer) {
                    // Une version plus ancienne illisible (signature introuvable…)
                    // n'empêche pas de proposer les autres.
                    try { $cands[$i] = $c = $completer($c); $faits[$i] = true; }
                    catch (\Throwable $e) { if ($i === 0) throw $e; continue; }
                }
                if (self::signatureAcceptee($c['sha256'] ?? null, $c['signature'] ?? null)) return $c;
            }
        }
        if ($completer && empty($faits[0])) $cands[0] = $completer($cands[0]);
        return $cands[0];
    }

    /** La signature annoncée est-elle celle de la clé de ce serveur ? (Sans rien télécharger d'autre.) */
    private static function signatureAcceptee(?string $sha, mixed $sigB64): bool
    {
        if (!$sha || !is_string($sigB64) || !function_exists('sodium_crypto_sign_verify_detached')) return false;
        $pk = self::clePubliqueEditeur();
        $sig = base64_decode(trim($sigB64), true);
        return $pk !== null && $sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($sig, $sha, $pk);
    }

    private function depuisManifeste(): array
    {
        $url = (string)self::cfg('url_manifeste', '');
        if (!str_starts_with($url, 'https://')) throw new RuntimeException('url_manifeste absente ou non HTTPS.');
        $racineM = json_decode($this->telecharger($url, null, 1_000_000), true);
        if (!is_array($racineM) || empty($racineM['version']) || empty($racineM['zip'])) throw new RuntimeException('Manifeste invalide (version et zip requis).');
        // « anterieures » : versions précédentes encore publiées (outils/publier-version.php
        // les y reporte) — dont la version de transition d'une rotation de clé.
        $entrees = [$racineM];
        foreach (array_slice(is_array($racineM['anterieures'] ?? null) ? $racineM['anterieures'] : [], 0, 20) as $a) {
            if (is_array($a) && !empty($a['zip']) && preg_match('/^\d+\.\d+\.\d+$/', (string)($a['version'] ?? ''))) $entrees[] = $a;
        }
        $cands = [];
        foreach ($entrees as $m) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', (string)$m['version'])) throw new RuntimeException('Numéro de version invalide dans le manifeste.');
            $cands[] = $this->entreeManifeste($m);
        }
        $d = $this->choisir($cands);
        $d['libelle'] = LarkaVersion::libelle($d);
        return $d;
    }

    private function entreeManifeste(array $m): array
    {
        return [
            'version'  => (string)$m['version'],
            'canal'    => (string)($m['canal'] ?? 'Stable'),
            'date'     => (string)($m['date'] ?? ''),
            'notes'    => mb_substr((string)($m['notes'] ?? ''), 0, 8000),
            'zip'      => (string)$m['zip'],
            'taille'   => (int)($m['taille'] ?? 0),
            'sha256'   => strtolower((string)($m['sha256'] ?? '')) ?: null,
            'signature'=> $m['signature'] ?? null,
            'page'     => (string)($m['page'] ?? ''),
            'php_min'  => preg_match('/^\d+\.\d+/', (string)($m['php_min'] ?? ''), $x) ? $x[0] : self::phpMinDesNotes((string)($m['notes'] ?? '')),
            // Exigences complètes du paquet (extensions, SQLite, PostgreSQL…), si le manifeste les porte.
            'prerequis'=> is_array($m['prerequis'] ?? null) ? $m['prerequis'] : null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Installation
    // ═══════════════════════════════════════════════════════════════════════

    /** L'application peut-elle écrire ses propres fichiers ? */
    public function installable(?string $phpRequis = null, ?array $prerequis = null): array
    {
        $raisons = [];
        $php = $this->php($phpRequis);
        if (version_compare(PHP_VERSION, $php['minimum'], '<')) {
            $raisons[] = "Cette version demande PHP {$php['minimum']} ou plus récent ; ce serveur a PHP " . PHP_VERSION . ". Mettez d'abord PHP à jour : " . self::commandePhp() . '.';
        }
        // Exigences complètes de la NOUVELLE version (extensions, SQLite, serveur
        // de base) : ce qu'elle exige, confronté à ce serveur, avant tout téléchargement.
        if ($prerequis !== null) {
            foreach ($this->prerequisBloquants(['prerequis' => $prerequis, 'php_min' => $phpRequis]) as $r) $raisons[] = $r;
        }
        if (!class_exists('ZipArchive')) $raisons[] = "Extension PHP « zip » absente (" . self::commandePhp(['zip']) . ').';
        if (!function_exists('curl_init')) $raisons[] = "Extension PHP « curl » absente (" . self::commandePhp(['curl']) . ').';
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $raisons[] = "Extension PHP « sodium » absente : la signature de l'éditeur ne peut pas être vérifiée.";
        }
        // La ligne de commande (php api/outils/mise-a-jour.php) contourne les
        // droits d'écriture et un PHP web différent du PHP en ligne de commande ;
        // elle ne peut rien contre une clé absente ou un disque plein : l'écran
        // ne la suggère alors pas.
        $cliUtile = true;
        if (self::clePubliqueEditeur() === null) {
            $raisons[] = "Clé publique de l'éditeur absente ou invalide (api/CleEditeur.php) : "
                       . "aucune mise à jour ne peut être authentifiée. Installez une version publiée par l'éditeur.";
            $cliUtile = false;
        }
        foreach (['', '/api', '/js', '/css', '/index.html', '/version.json'] as $p) {
            $f = $this->racine . $p;
            if (file_exists($f) && !is_writable($f)) { $raisons[] = "Pas de droit d'écriture sur " . ($p ?: '/') . '.'; break; }
        }
        if (function_exists('disk_free_space') && ($l = @disk_free_space($this->racine)) !== false && $l < 300 * 1024 * 1024) {
            $raisons[] = 'Moins de 300 Mo libres sur le disque.';
            $cliUtile = false;
        }
        return ['ok' => !$raisons, 'raisons' => $raisons, 'ligne_de_commande' => $raisons && $cliUtile];
    }

    /**
     * Installe la version distante. $versionConfirmee doit être celle que
     * l'administrateur a lue à l'écran : une source qui change entre-temps
     * n'installe pas autre chose que ce qui a été validé.
     */
    public function installer(string $versionConfirmee, string $parQui = '?'): array
    {
        @set_time_limit(600);
        $verrou = fopen($this->dossier . '/verrou', 'c');
        if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) throw new RuntimeException('Une installation est déjà en cours.');

        try {
            // Métadonnées fraîches : si le paquet a été republié (empreinte changée)
            // depuis la dernière détection, on installe ce qui est publié MAINTENANT.
            $etat = $this->verifier();
            if (!$etat['disponible']) $etat = $this->etat();
            $d = $etat['distante'];
            if (!$etat['disponible'] || !$d) throw new RuntimeException('Aucune mise à jour disponible.');
            if ($d['version'] !== $versionConfirmee) throw new RuntimeException('La version disponible a changé : vérifiez de nouveau avant d\'installer.');
            $inst = $this->installable($d['php_min'] ?? null);
            if (!$inst['ok']) throw new RuntimeException(implode(' ', $inst['raisons']));
            if (empty($d['sha256'])) throw new RuntimeException('Version publiée sans empreinte SHA-256 : installation refusée.');

            // 1. Téléchargement + contrôles d'intégrité
            $zipPath = $this->dossier . '/telechargements/larka-' . $d['version'] . '.zip';
            $this->telecharger($d['zip'], $zipPath, self::TAILLE_MAX);
            $sha = hash_file('sha256', $zipPath);
            if (!hash_equals($d['sha256'], $sha)) { @unlink($zipPath); throw new RuntimeException('Empreinte SHA-256 différente : archive corrompue ou modifiée. Rien n\'a été installé.'); }
            $signee = $this->verifierSignature($d, $sha);

            // 2. Lecture de l'archive
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new RuntimeException('Archive illisible.');
            $prefixe = $this->prefixeRacine($zip);
            $nv = json_decode((string)$zip->getFromName($prefixe . 'version.json'), true);
            if (!is_array($nv) || ($nv['version'] ?? '') !== $d['version']) throw new RuntimeException('version.json de l\'archive ne correspond pas à la version annoncée.');
            // Exigence PHP écrite dans le paquet lui-même : on n'installe pas un code que ce PHP ne saurait pas exécuter.
            $phpMin = preg_match('/^\d+\.\d+/', (string)($nv['php_min'] ?? ''), $x) ? $x[0] : null;
            if ($phpMin && version_compare(PHP_VERSION, $phpMin, '<')) {
                throw new RuntimeException("Cette version demande PHP $phpMin ou plus récent ; ce serveur a PHP " . PHP_VERSION . ". Rien n'a été installé. Mettez d'abord PHP à jour : " . self::commandePhp() . '.');
            }
            // Toutes les exigences écrites dans le paquet (extensions, SQLite,
            // PostgreSQL / MariaDB) : une version incompatible n'est pas écrite.
            if ($bloquants = $this->prerequisBloquants($nv)) {
                throw new RuntimeException('Cette version n\'est pas compatible avec ce serveur : ' . implode(' ', $bloquants) . ' Rien n\'a été installé.');
            }
            if (!version_compare($d['version'], $this->locale()['version'], '>')) throw new RuntimeException('Cette version n\'est pas plus récente que celle installée.');
            $plan = $this->planifier($zip, $prefixe);

            // 3. Sauvegarde AVANT écriture
            $idSauv = date('Ymd-His') . '_' . $this->locale()['version'] . '_vers_' . $d['version'];
            $sauv = $this->sauvegarder($idSauv, $plan);

            // 4. Écriture (restauration automatique en cas d'échec)
            $ecrits = 0;
            try {
                foreach ($plan as $p) { $this->ecrireFichier($p['cible'], (string)$zip->getFromIndex($p['index'])); $ecrits++; }
            } catch (\Throwable $ex) {
                try {
                    $this->retablir($idSauv);
                } catch (\Throwable $ex2) {
                    throw new RuntimeException("Échec pendant l'écriture ($ecrits fichier(s)) : " . $ex->getMessage()
                        . " — ET le retour arrière automatique a échoué (" . $ex2->getMessage() . "). "
                        . "Sur le serveur : php api/outils/mise-a-jour.php --restaurer $idSauv");
                }
                throw new RuntimeException("Échec pendant l'écriture ($ecrits fichier(s)) — version précédente restaurée. Détail : " . $ex->getMessage());
            }
            $zip->close();
            if (function_exists('opcache_reset')) @opcache_reset();
            @unlink($zipPath);

            $res = [
                'ok' => true, 'de' => $etat['locale']['version'], 'vers' => $d['version'],
                'libelle' => LarkaVersion::libelle($d), 'fichiers' => $ecrits,
                'nouveaux' => count(array_filter($plan, fn($p) => !$p['existait'])),
                'sauvegarde' => $idSauv, 'sauvegarde_base' => $sauv['base'], 'signee' => $signee,
                'par' => $parQui, 'le' => date('c'),
            ];
            $this->historiser($res);
            $this->ecrireJson('etat.json', ['ts' => time(), 'verifie_le' => date('c'), 'distante' => null, 'erreur' => null]);
            if (class_exists('Journal')) {
                try { Journal::log(Journal::AUDIT, 'mise_a_jour', "Mise à jour {$res['de']} → {$res['vers']} ($ecrits fichiers) par $parQui."); } catch (\Throwable $_) {}
            }
            return $res;
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }

    /**
     * Installe une ARCHIVE LOCALE (zip d'une version de Larka déposé sur le
     * serveur) — en ligne de commande seulement : « ./start.sh maj <zip> »,
     * « Larka.cmd maj <zip> », « php api/outils/mise-a-jour.php --archive <zip> ».
     *
     * Remplace la décompression « à la main » par-dessus l'installation, qui
     * écrasait ce que la mise à jour en ligne protège : réglages des modules
     * (extensions/config/), variantes de config.json / .env, et le reste. Ici,
     * mêmes règles qu'installer() : chemins protégés, ajout seul pour les
     * réglages de modules, prérequis vérifiés, sauvegarde des fichiers ET des
     * bases avant écriture, retour arrière automatique en cas d'échec.
     *
     * Signature : si « <zip>.sig » est présent, elle est vérifiée comme en
     * ligne (refus si elle est fausse). Sans signature, il faut le dire
     * (--sans-signature) : l'archive vient alors de quelqu'un en qui
     * l'administrateur du serveur a confiance — il pourrait de toute façon
     * écrire ces fichiers lui-même. Jamais depuis l'interface web.
     */
    public function installerArchive(string $zipPath, string $parQui = 'cli', bool $sansSignature = false,
                                     bool $memeVersion = false): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Installation d\'une archive locale : en ligne de commande seulement.');
        @set_time_limit(0);
        if (!is_file($zipPath)) throw new RuntimeException("Archive introuvable : $zipPath");
        $verrou = fopen($this->dossier . '/verrou', 'c');
        if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) throw new RuntimeException('Une installation est déjà en cours.');
        try {
            $sha = hash_file('sha256', $zipPath);
            $signee = false;
            $fSig = $zipPath . '.sig';
            if (is_file($fSig)) {
                $signee = $this->verifierSignature(['signature' => (string)file_get_contents($fSig)], $sha);
            } elseif (!$sansSignature) {
                throw new RuntimeException("Archive sans signature (" . basename($fSig) . " absent). "
                    . "Si vous êtes sûr de sa provenance, relancez avec --sans-signature.");
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new RuntimeException('Archive illisible.');
            $prefixe = $this->prefixeRacine($zip);
            $nv = json_decode((string)$zip->getFromName($prefixe . 'version.json'), true);
            if (!is_array($nv) || !preg_match('/^\d+\.\d+\.\d+/', (string)($nv['version'] ?? ''))) {
                throw new RuntimeException('version.json absent ou illisible dans l\'archive.');
            }
            $vNouvelle = (string)$nv['version'];
            $vLocale = (string)$this->locale()['version'];
            $cmp = version_compare($vNouvelle, $vLocale);
            if ($cmp < 0) throw new RuntimeException("L'archive contient la version $vNouvelle, plus ancienne que celle installée ($vLocale). "
                . 'Pour revenir en arrière : --restaurer <sauvegarde>.');
            if ($cmp === 0 && !$memeVersion) throw new RuntimeException("La version $vNouvelle est déjà installée. Pour la réappliquer : --reinstaller.");
            $phpMin = preg_match('/^\d+\.\d+/', (string)($nv['php_min'] ?? ''), $x) ? $x[0] : null;
            if ($phpMin && version_compare(PHP_VERSION, $phpMin, '<')) {
                throw new RuntimeException("Cette version demande PHP $phpMin ou plus récent ; ce serveur a PHP " . PHP_VERSION . ". Rien n'a été installé.");
            }
            if ($bloquants = $this->prerequisBloquants($nv)) {
                throw new RuntimeException('Cette version n\'est pas compatible avec ce serveur : ' . implode(' ', $bloquants) . ' Rien n\'a été installé.');
            }
            $plan = $this->planifier($zip, $prefixe);
            $idSauv = date('Ymd-His') . '_' . $vLocale . '_vers_' . $vNouvelle;
            $sauv = $this->sauvegarder($idSauv, $plan);
            $ecrits = 0;
            try {
                foreach ($plan as $p) { $this->ecrireFichier($p['cible'], (string)$zip->getFromIndex($p['index'])); $ecrits++; }
            } catch (\Throwable $ex) {
                try { $this->retablir($idSauv); }
                catch (\Throwable $ex2) {
                    throw new RuntimeException("Échec pendant l'écriture ($ecrits fichier(s)) : " . $ex->getMessage()
                        . " — ET le retour arrière automatique a échoué (" . $ex2->getMessage() . "). "
                        . "Sur le serveur : php api/outils/mise-a-jour.php --restaurer $idSauv");
                }
                throw new RuntimeException("Échec pendant l'écriture ($ecrits fichier(s)) — version précédente restaurée. Détail : " . $ex->getMessage());
            }
            $zip->close();
            if (function_exists('opcache_reset')) @opcache_reset();
            $res = [
                'ok' => true, 'de' => $vLocale, 'vers' => $vNouvelle,
                'libelle' => LarkaVersion::libelle($nv), 'fichiers' => $ecrits,
                'nouveaux' => count(array_filter($plan, fn($p) => !$p['existait'])),
                'sauvegarde' => $idSauv, 'sauvegarde_base' => $sauv['base'], 'signee' => $signee,
                'source' => 'archive locale : ' . basename($zipPath), 'sha256' => $sha,
                'par' => $parQui, 'le' => date('c'),
            ];
            $this->historiser($res);
            if (class_exists('Journal')) {
                try { Journal::log(Journal::AUDIT, 'mise_a_jour', "Mise à jour {$vLocale} → {$vNouvelle} depuis une archive locale ($ecrits fichiers) par $parQui."); } catch (\Throwable $_) {}
            }
            return $res;
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }

    /**
     * Clé publique de l'éditeur (32 octets bruts), lue dans api/CleEditeur.php,
     * ou null si elle est absente ou mal formée.
     *
     * Jamais lue dans config.json : voir l'en-tête de api/CleEditeur.php.
     */
    public static function clePubliqueEditeur(?string $fichier = null): ?string
    {
        $f = $fichier ?? __DIR__ . '/CleEditeur.php';
        if (!is_file($f)) return null;
        try {
            $b64 = include $f;
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($b64) || trim($b64) === '') return null;
        $pk = base64_decode(trim($b64), true);
        // 32 octets = clé publique Ed25519. Longueur écrite en dur : sans
        // l'extension sodium, la constante n'existe pas, et la clé passait pour
        // absente — l'écran accusait la clé au lieu de l'extension manquante.
        if ($pk === false || strlen($pk) !== 32) return null;
        return $pk;
    }

    /**
     * Vérifie la signature Ed25519 de l'empreinte SHA-256 de l'archive.
     * Lève une exception — donc refuse l'installation — dans TOUS les cas
     * où l'origine n'est pas prouvée : clé absente, signature absente,
     * illisible ou fausse. Il n'existe plus de chemin « non exigée ».
     */
    private function verifierSignature(array $d, string $sha): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Extension PHP « sodium » absente : signature impossible à vérifier. Installation refusée.');
        }
        $pk = self::clePubliqueEditeur();
        if ($pk === null) {
            throw new RuntimeException('Clé publique de l\'éditeur absente ou invalide (api/CleEditeur.php) : '
                . 'l\'origine de la mise à jour ne peut pas être vérifiée. Installation refusée.');
        }
        $sig = base64_decode(trim((string)($d['signature'] ?? '')), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Version non signée par l\'éditeur : installation refusée.');
        }
        if (!sodium_crypto_sign_verify_detached($sig, $sha, $pk)) {
            throw new RuntimeException('Signature invalide : cette archive ne vient pas de l\'éditeur. Installation refusée.');
        }
        return true;
    }

    /** Dossier de l'archive qui contient l'application (index.html + api/index.php). */
    private function prefixeRacine(ZipArchive $zip): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (preg_match('#^(.*/)?api/index\.php$#', $n, $m)) {
                $p = $m[1] ?? '';
                if ($zip->locateName($p . 'index.html') !== false) return $p;
            }
        }
        throw new RuntimeException('Archive invalide : application Larka introuvable.');
    }

    /** Prérequis bloquants d'une version (son version.json) sur ce serveur, en phrases. */
    private function prerequisBloquants(array $versionJson): array
    {
        $base = null;
        if (function_exists('cfg') && is_array($b = cfg('base_de_donnees'))) $base = $b + ['driver' => 'sqlite'];
        $r = LarkaPrerequis::verifier(LarkaPrerequis::exigences($versionJson), $base, $this->racine);
        $out = [];
        foreach ($r['points'] as $p) {
            if ($p['statut'] === 'bloquant') $out[] = "{$p['libelle']} : {$p['detail']}" . ($p['conseil'] !== '' ? " {$p['conseil']}" : '');
        }
        return $out;
    }

    /** Liste des fichiers à écrire, après filtrage des chemins protégés. */
    private function planifier(ZipArchive $zip, string $prefixe): array
    {
        $plan = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (!str_starts_with($n, $prefixe) || str_ends_with($n, '/')) continue;
            $rel = substr($n, strlen($prefixe));
            // « C:… » : sous Windows, une entrée à lettre de lecteur sortirait de l'installation.
            if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel) || str_contains($rel, '\\') || preg_match('#^[A-Za-z]:#', $rel)) {
                throw new RuntimeException("Chemin interdit dans l'archive : $rel");
            }
            $tete = explode('/', $rel)[0];
            if (in_array($tete, self::PROTEGES, true) || in_array($rel, self::PROTEGES, true)) continue;
            if (!str_contains($rel, '/') && preg_match(self::PROTEGES_MOTIF, $rel)) continue;
            // Clés et certificats : jamais apportés par une mise à jour (config.key
            // chiffre les secrets de config.json et les mots de passe des tenants).
            if (str_ends_with($rel, '.pem') || str_ends_with($rel, '.key')) continue;
            if ($tete === 'demo') { if (!$this->demoBienvenue()) continue; }
            elseif (in_array($tete, self::SI_PRESENTS, true) && !is_dir($this->racine . '/' . $tete)) continue;
            $cible = $this->racine . '/' . $rel;
            $existe = file_exists($cible);
            foreach (self::AJOUT_SEUL as $a) if (str_starts_with($rel, $a) && $existe) continue 2;
            $plan[] = ['index' => $i, 'rel' => $rel, 'cible' => $cible, 'existait' => $existe];
        }
        if (count($plan) < 10) throw new RuntimeException('Archive presque vide : installation refusée.');
        return $plan;
    }

    /** Sauvegarde des fichiers qui vont être remplacés + base SQLite. */
    private function sauvegarder(string $id, array $plan): array
    {
        $dir = $this->dossier . '/sauvegardes';
        $zip = new ZipArchive();
        if ($zip->open("$dir/$id.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Impossible de créer la sauvegarde.');
        $nouveaux = [];
        foreach ($plan as $p) {
            if ($p['existait']) $zip->addFile($p['cible'], $p['rel']);
            else $nouveaux[] = $p['rel'];
        }
        $zip->addFromString('.larka-sauvegarde.json', json_encode([
            'id' => $id, 'version' => $this->locale(), 'nouveaux' => $nouveaux, 'le' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$zip->close()) throw new RuntimeException('Sauvegarde incomplète : installation annulée.');

        // Bases de données : la mise à jour ne les touche pas elle-même, mais le
        // nouveau code peut faire évoluer leur schéma à sa première requête.
        // Une copie de CHAQUE base (principale et tenants), sinon pas de mise
        // à jour : une sauvegarde qui échoue annule tout, avant la moindre écriture.
        $base = $this->sauvegarderBases($dir, $id);
        return ['base' => $base];
    }

    /**
     * Copie de chaque base avant mise à jour : instantané SQLite (VACUUM INTO),
     * sauvegarde SQL pour PostgreSQL / MariaDB (api/SauvegardeSql.php — sans
     * pg_dump, que PHP-FPM ne peut pas lancer). Une base désignée deux fois
     * n'est copiée qu'une fois ; un tenant désactivé ou sans fichier est
     * ignoré. Lève une exception au premier échec : la mise à jour n'a alors
     * rien écrit.
     */
    private function sauvegarderBases(string $dir, string $id): string
    {
        $principale = function_exists('cfg') ? cfg('base_de_donnees') : null;
        $bases = ['principale' => is_array($principale) ? $principale : ['driver' => 'sqlite', 'path' => 'data/gmao.db']];
        if (class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            foreach (TenantResolver::getAllTenants() as $cle => $t) {
                if (!is_array($t) || empty($t['actif']) || !is_array($t['base_de_donnees'] ?? null)) continue;
                $bases['tenant-' . $cle] = $t['base_de_donnees'];
            }
        }
        $faits = [];
        $vues = [];
        foreach ($bases as $nom => $c) {
            $drv = strtolower((string)($c['driver'] ?? 'sqlite'));
            if ($drv === 'mariadb') $drv = 'mysql';
            $fic = preg_replace('/[^\w.-]/', '_', (string)$nom);
            try {
                if ($drv === 'sqlite') {
                    $src = (string)(($c['path'] ?? '') ?: 'data/gmao.db');
                    if (!larka_chemin_absolu($src)) $src = $this->racine . '/' . $src;
                    $cle = 'sqlite:' . (realpath($src) ?: $src);
                    if (isset($vues[$cle]) || !is_file($src)) continue;
                    $vues[$cle] = true;
                    // Instantané cohérent (VACUUM INTO) et non copy() à chaud : la base
                    // tourne en WAL, une copie du seul .db serait tronquée.
                    $dst = $nom === 'principale' ? "$dir/$id.db" : "$dir/{$id}_{$fic}.db";
                    @unlink($dst);
                    $pdo = new \PDO('sqlite:' . $src);
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    try { $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)'); } catch (\Throwable $_) {}
                    $pdo->exec('VACUUM INTO ' . $pdo->quote($dst));
                    $pdo = null;
                    if (!is_file($dst) || filesize($dst) === 0) throw new RuntimeException('instantané vide');
                    $faits[] = "$nom : instantané SQLite (" . basename($dst) . ')';
                } elseif ($drv === 'pgsql' || $drv === 'mysql') {
                    $hote = (string)(($c['host'] ?? '') ?: '127.0.0.1');
                    $port = (int)(($c['port'] ?? 0) ?: ($drv === 'pgsql' ? 5432 : 3306));
                    $nomBase = (string)(($c['dbname'] ?? '') ?: 'gmao');
                    $cle = "$drv://" . strtolower($hote) . ":$port/$nomBase";
                    if (isset($vues[$cle])) continue;
                    $vues[$cle] = true;
                    $dsn = $drv === 'pgsql'
                        ? sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10', $hote, $port, $nomBase, ($c['sslmode'] ?? '') ?: 'prefer')
                        : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $hote, $port, $nomBase);
                    $mdp = (string)($c['password'] ?? '');
                    $pdo = new \PDO($dsn, (string)($c['user'] ?? ''), $mdp === '' ? null : $mdp);
                    require_once __DIR__ . '/SauvegardeSql.php';
                    $dst = "$dir/{$id}_{$fic}.sql.gz";
                    $r = larka_sauvegarde_sql($pdo, $drv, $dst);
                    $pdo = null;
                    $faits[] = "$nom : sauvegarde SQL, {$r['tables']} tables (" . basename($dst) . ')';
                }
            } catch (\Throwable $e) {
                throw new RuntimeException("Sauvegarde de la base « $nom » impossible (" . $e->getMessage() . ") : "
                    . "mise à jour annulée, rien n'a été modifié. Rétablissez l'accès à cette base, ou sauvegardez-la vous-même, puis relancez.");
            }
        }
        return $faits ? implode(' ; ', $faits) : 'aucune base à copier';
    }

    /** Écriture atomique : fichier temporaire puis renommage, droits conservés. */
    private function ecrireFichier(string $cible, string $contenu): void
    {
        $dir = dirname($cible);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException("Dossier impossible à créer : $dir");
        $mode = file_exists($cible) ? (fileperms($cible) & 0777) : (str_ends_with($cible, '.sh') ? 0750 : 0640);
        $tmp = $dir . '/.maj-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contenu) === false) throw new RuntimeException("Écriture impossible : $cible");
        @chmod($tmp, $mode);
        // Lancé par root (ligne de commande) : le fichier garde le propriétaire de
        // celui qu'il remplace — sinon root:root 0640, et PHP-FPM (www-data) ne
        // pouvait plus le lire : le site tombait après une mise à jour.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $ref = file_exists($cible) ? $cible : $this->racine . '/api/index.php';
            if (file_exists($ref)) { @chown($tmp, fileowner($ref)); @chgrp($tmp, filegroup($ref)); }
        }
        if (!@rename($tmp, $cible)) { @unlink($tmp); throw new RuntimeException("Remplacement impossible : $cible"); }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Historique et retour arrière
    // ═══════════════════════════════════════════════════════════════════════

    public function historique(): array
    {
        $h = $this->lireJson('historique.json');
        $sauv = [];
        foreach (glob($this->dossier . '/sauvegardes/*.zip') ?: [] as $f) {
            // restaurable : depuis l'interface (cf. restaurer()).
            $meta = null;
            $z = new ZipArchive();
            if ($z->open($f) === true) { $meta = json_decode((string)$z->getFromName('.larka-sauvegarde.json'), true); $z->close(); }
            $sauv[] = ['id' => basename($f, '.zip'), 'taille_ko' => (int)(filesize($f) / 1024), 'le' => date('c', filemtime($f)),
                       'restaurable' => $this->restaurableDepuisInterface(is_array($meta) ? $meta : null)];
        }
        usort($sauv, fn($a, $b) => strcmp($b['id'], $a['id']));
        return ['installations' => array_reverse($h['liste'] ?? []), 'sauvegardes' => $sauv];
    }

    /**
     * Remet les fichiers sauvegardés avant une installation. Les fichiers AJOUTÉS
     * par la mise à jour sont laissés en place (rien n'est supprimé) ;
     * version.json revient, donc la version affichée aussi. Les fichiers de la
     * chaîne de confiance (JAMAIS_RESTAURES) restent ceux en place.
     */
    public function restaurer(string $id, string $parQui = '?', bool $ligneDeCommande = false): array
    {
        if (!preg_match('/^[\w.-]+$/', $id)) throw new RuntimeException('Identifiant de sauvegarde invalide.');
        $f = $this->dossier . "/sauvegardes/$id.zip";
        $zip = new ZipArchive();
        if (!is_file($f) || $zip->open($f) !== true) throw new RuntimeException('Sauvegarde introuvable.');
        // ⚠️ UNE SAUVEGARDE ANTÉRIEURE À LA SIGNATURE OBLIGATOIRE RAMÈNE LES FAILLES
        // CORRIGÉES AVEC ELLE (le code de cette époque, pas seulement l'outil de
        // mise à jour). Depuis l'interface, seules les sauvegardes dont les
        // fichiers viennent d'une version qui exigeait déjà la signature sont
        // restaurables (restaurableDepuisInterface) ; les autres restent
        // possibles en ligne de commande sur le serveur, par quelqu'un qui en a
        // de toute façon la maîtrise.
        $meta = json_decode((string)$zip->getFromName('.larka-sauvegarde.json'), true);
        if (!$ligneDeCommande && !$this->restaurableDepuisInterface(is_array($meta) ? $meta : null)) {
            $zip->close();
            throw new RuntimeException('Sauvegarde antérieure à la vérification obligatoire des signatures : '
                . 'la restaurer depuis l\'interface réintroduirait des failles corrigées depuis. '
                . "Si c'est vraiment nécessaire, sur le serveur : php api/outils/mise-a-jour.php --restaurer $id");
        }
        $n = $this->remettre($zip, true);
        $zip->close();
        if (function_exists('opcache_reset')) @opcache_reset();
        $this->historiser(['ok' => true, 'restauration' => $id, 'fichiers' => $n, 'par' => $parQui, 'le' => date('c'),
                           'vers' => $this->locale()['version']]);
        return ['ok' => true, 'fichiers' => $n, 'version' => $this->locale()];
    }

    /**
     * Retour arrière AUTOMATIQUE après une installation interrompue : remet
     * l'instantané pris quelques secondes plus tôt, TEL QUEL — chaîne de
     * confiance comprise, sans le filtre de l'interface. Ce n'est pas un choix
     * de l'utilisateur mais le retour à l'état exact qui tournait.
     * ⚠️ Il passait par restaurer() : refusé quand la version en place était
     * sous la base notée (après un retour en ligne de commande), il laissait une
     * installation à moitié écrite ; et, MiseAJour.php / CleEditeur.php n'étant
     * jamais remis, une transition de clé interrompue laissait l'ancien code
     * avec la nouvelle clé — la transition elle-même était alors refusée.
     */
    private function retablir(string $id): int
    {
        $zip = new ZipArchive();
        if ($zip->open($this->dossier . "/sauvegardes/$id.zip") !== true) throw new RuntimeException('Sauvegarde introuvable.');
        try { $n = $this->remettre($zip, false); } finally { $zip->close(); }
        if (function_exists('opcache_reset')) @opcache_reset();
        $this->historiser(['ok' => true, 'restauration' => $id, 'fichiers' => $n, 'par' => 'restauration automatique',
                           'le' => date('c'), 'vers' => $this->locale()['version']]);
        return $n;
    }

    /** Réécrit les fichiers d'une sauvegarde ; $garderChaine : sauf JAMAIS_RESTAURES. */
    private function remettre(ZipArchive $zip, bool $garderChaine): int
    {
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rel = (string)$zip->getNameIndex($i);
            if ($rel === '.larka-sauvegarde.json' || str_ends_with($rel, '/')) continue;
            if (preg_match('#(^|/)\.\.(/|$)#', $rel) || str_starts_with($rel, '/')
                || str_contains($rel, '\\') || preg_match('#^[A-Za-z]:#', $rel)) continue;
            $tete = explode('/', $rel)[0];
            if (in_array($tete, self::PROTEGES, true)) continue;
            if (!str_contains($rel, '/') && preg_match(self::PROTEGES_MOTIF, $rel)) continue;
            if ($garderChaine && in_array($rel, self::JAMAIS_RESTAURES, true)) continue;
            $this->ecrireFichier($this->racine . '/' . $rel, (string)$zip->getFromIndex($i));
            $n++;
        }
        return $n;
    }

    private function historiser(array $e): void
    {
        $h = $this->lireJson('historique.json');
        $h['liste'][] = $e;
        $h['liste'] = array_slice($h['liste'], -50);
        $this->ecrireJson('historique.json', $h);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Outils
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET HTTPS. Avec $dest : écrit dans un fichier (borné à $max octets).
     * Redirections suivies en HTTPS seulement (GitHub redirige les archives).
     */
    protected function telecharger(string $url, ?string $dest, int $max): string
    {
        if (!str_starts_with($url, 'https://')) throw new RuntimeException('URL non HTTPS refusée.');
        $ch = curl_init($url);
        $headers = ['User-Agent: Larka-MiseAJour', 'Accept: application/vnd.github+json, application/octet-stream, */*'];
        $jeton = function_exists('env') ? (string)(env('GMAO_MAJ_GITHUB_TOKEN', '') ?? '') : (string)getenv('GMAO_MAJ_GITHUB_TOKEN');
        if ($jeton !== '' && str_contains((string)parse_url($url, PHP_URL_HOST), 'github.com')) $headers[] = 'Authorization: Bearer ' . $jeton;
        $fh = null; $recu = 0; $buf = '';
        if ($dest) { $fh = fopen($dest . '.part', 'wb'); if (!$fh) throw new RuntimeException('Écriture du téléchargement impossible.'); }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $dest ? 300 : 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($_c, $data) use (&$recu, &$buf, $fh, $max) {
                $recu += strlen($data);
                if ($recu > $max) return 0;   // interrompt : trop gros
                if ($fh) fwrite($fh, $data); else $buf .= $data;
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($fh) fclose($fh);
        if ($recu > $max) { if ($dest) @unlink($dest . '.part'); throw new RuntimeException('Fichier trop volumineux.'); }
        if ($err || $code >= 400 || $code === 0) {
            if ($dest) @unlink($dest . '.part');
            $msg = $code === 404 ? 'introuvable (404) — dépôt privé ? Ajoutez GMAO_MAJ_GITHUB_TOKEN dans .env'
                 : ($code === 403 ? 'accès refusé ou quota GitHub dépassé (403)' : ($err ?: "HTTP $code"));
            throw new RuntimeException('Téléchargement : ' . $msg);
        }
        if ($dest) { rename($dest . '.part', $dest); return $dest; }
        return $buf;
    }

    private function lireJson(string $nom): array
    {
        $f = $this->dossier . '/' . $nom;
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    private function ecrireJson(string $nom, array $d): void
    {
        @file_put_contents($this->dossier . '/' . $nom, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
