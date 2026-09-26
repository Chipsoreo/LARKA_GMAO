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
 *   - Signature Ed25519 : si une clé publique est configurée
 *     (mises_a_jour.cle_publique), une version non signée ou mal signée est
 *     REFUSÉE. Recommandé en production. Outil : outils/publier-version.php.
 *   - Archive contrôlée (pas de chemin « .. » ni absolu), version plus récente
 *     exigée, verrou contre deux installations simultanées.
 *
 * FILET DE SÉCURITÉ
 *   Avant d'écrire quoi que ce soit : sauvegarde des fichiers qui vont être
 *   remplacés (+ copie de la base SQLite, ou sauvegarde du tenant si disponible).
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
 *   cle_publique     clé Ed25519 en base64 (outils/publier-version.php --generer-cles)
 *   Jeton GitHub (dépôt privé) : .env → GMAO_MAJ_GITHUB_TOKEN
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Version.php';

class LarkaMiseAJour
{
    /** Jamais écrits par une mise à jour. */
    private const PROTEGES = ['data', 'config.json', '.env', '.git', '.github'];
    /** Écrits seulement s'ils n'existent pas encore (réglages de modules). */
    private const AJOUT_SEUL = ['extensions/config/'];
    /** Dossiers de développement : mis à jour seulement s'ils sont déjà présents
     *  (deploy/install.sh ne les copie pas en production). */
    private const SI_PRESENTS = ['outils', 'Documentations', 'deploy', 'tests'];
    private const TAILLE_MAX = 250 * 1024 * 1024;

    private string $racine;
    private string $dossier;

    public function __construct(?string $racine = null)
    {
        $this->racine = rtrim($racine ?? dirname(__DIR__), '/');
        $this->dossier = $this->racine . '/data/maj';
        foreach (['', '/telechargements', '/sauvegardes'] as $d) {
            if (!is_dir($this->dossier . $d)) @mkdir($this->dossier . $d, 0750, true);
        }
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

    /** État en cache (sans appel réseau). a_verifier = le cache est périmé. */
    public function etat(): array
    {
        $e = $this->lireJson('etat.json');
        $freq = max(1, (int)self::cfg('frequence_heures', 12)) * 3600;
        $locale = $this->locale();
        $dispo = !empty($e['distante']['version'])
            && version_compare($e['distante']['version'], $locale['version'], '>');
        return [
            'actif'       => $this->actif(),
            'locale'      => $locale,
            'distante'    => $dispo ? $e['distante'] : null,
            'disponible'  => $dispo,
            'verifie_le'  => $e['verifie_le'] ?? null,
            'erreur'      => $e['erreur'] ?? null,
            'a_verifier'  => $this->actif() && (time() - (int)($e['ts'] ?? 0)) > $freq,
            'signature_exigee' => (string)self::cfg('cle_publique', '') !== '',
            'installable' => $this->installable(),
        ];
    }

    /** Interroge la source et met le cache à jour. */
    public function verifier(): array
    {
        $e = ['ts' => time(), 'verifie_le' => date('c')];
        try {
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
        $best = null;
        foreach ($json as $rel) {
            if (!empty($rel['draft']) || (!$beta && !empty($rel['prerelease']))) continue;
            if (!preg_match('/(\d+\.\d+\.\d+)/', (string)($rel['tag_name'] ?? '') . ' ' . ($rel['name'] ?? ''), $m)) continue;
            $zip = null; $sha = null; $sig = null;
            foreach ($rel['assets'] ?? [] as $a) {
                $n = (string)($a['name'] ?? '');
                if (str_ends_with($n, '.zip') && !$zip) {
                    $zip = $a;
                    if (preg_match('/^sha256:([a-f0-9]{64})$/i', (string)($a['digest'] ?? ''), $d)) $sha = strtolower($d[1]);
                }
            }
            if (!$zip) continue;
            foreach ($rel['assets'] ?? [] as $a) {
                $n = (string)($a['name'] ?? '');
                if ($n === $zip['name'] . '.sha256' && !$sha) {
                    if (preg_match('/\b([a-f0-9]{64})\b/i', $this->telecharger($a['browser_download_url'], null, 10_000), $d)) $sha = strtolower($d[1]);
                } elseif ($n === $zip['name'] . '.sig') {
                    $sig = trim($this->telecharger($a['browser_download_url'], null, 10_000));
                }
            }
            if (!$sha && preg_match('/sha-?256\s*[:=]\s*([a-f0-9]{64})/i', (string)($rel['body'] ?? ''), $d)) $sha = strtolower($d[1]);
            $cand = [
                'version'  => $m[1],
                'canal'    => !empty($rel['prerelease']) ? 'Beta' : 'Stable',
                'date'     => substr((string)($rel['published_at'] ?? ''), 0, 10),
                'notes'    => mb_substr((string)($rel['body'] ?? ''), 0, 8000),
                'zip'      => (string)$zip['browser_download_url'],
                'taille'   => (int)($zip['size'] ?? 0),
                'sha256'   => $sha,
                'signature'=> $sig ?: null,
                'page'     => (string)($rel['html_url'] ?? ''),
            ];
            if (!$best || version_compare($cand['version'], $best['version'], '>')) $best = $cand;
        }
        if (!$best) throw new RuntimeException('Aucune version publiée (avec archive .zip) sur ' . $depot . '.');
        $best['libelle'] = LarkaVersion::libelle($best);
        return $best;
    }

    private function depuisManifeste(): array
    {
        $url = (string)self::cfg('url_manifeste', '');
        if (!str_starts_with($url, 'https://')) throw new RuntimeException('url_manifeste absente ou non HTTPS.');
        $m = json_decode($this->telecharger($url, null, 1_000_000), true);
        if (!is_array($m) || empty($m['version']) || empty($m['zip'])) throw new RuntimeException('Manifeste invalide (version et zip requis).');
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string)$m['version'])) throw new RuntimeException('Numéro de version invalide dans le manifeste.');
        $d = [
            'version'  => (string)$m['version'],
            'canal'    => (string)($m['canal'] ?? 'Stable'),
            'date'     => (string)($m['date'] ?? ''),
            'notes'    => mb_substr((string)($m['notes'] ?? ''), 0, 8000),
            'zip'      => (string)$m['zip'],
            'taille'   => (int)($m['taille'] ?? 0),
            'sha256'   => strtolower((string)($m['sha256'] ?? '')) ?: null,
            'signature'=> $m['signature'] ?? null,
            'page'     => (string)($m['page'] ?? ''),
        ];
        $d['libelle'] = LarkaVersion::libelle($d);
        return $d;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Installation
    // ═══════════════════════════════════════════════════════════════════════

    /** L'application peut-elle écrire ses propres fichiers ? */
    public function installable(): array
    {
        $raisons = [];
        if (!class_exists('ZipArchive')) $raisons[] = "Extension PHP « zip » absente.";
        foreach (['', '/api', '/js', '/css', '/index.html', '/version.json'] as $p) {
            $f = $this->racine . $p;
            if (file_exists($f) && !is_writable($f)) { $raisons[] = "Pas de droit d'écriture sur " . ($p ?: '/') . '.'; break; }
        }
        if (function_exists('disk_free_space') && ($l = @disk_free_space($this->racine)) !== false && $l < 300 * 1024 * 1024) {
            $raisons[] = 'Moins de 300 Mo libres sur le disque.';
        }
        return ['ok' => !$raisons, 'raisons' => $raisons];
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
            $etat = $this->etat();
            $d = $etat['distante'];
            if (!$etat['disponible'] || !$d) throw new RuntimeException('Aucune mise à jour disponible.');
            if ($d['version'] !== $versionConfirmee) throw new RuntimeException('La version disponible a changé : vérifiez de nouveau avant d\'installer.');
            $inst = $this->installable();
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
                $this->restaurer($idSauv, 'restauration automatique');
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

    private function verifierSignature(array $d, string $sha): bool
    {
        $cle = (string)self::cfg('cle_publique', '');
        if ($cle === '') return false;   // non exigée
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('Extension sodium absente : signature impossible à vérifier.');
        $sig = base64_decode((string)($d['signature'] ?? ''), true);
        $pk = base64_decode($cle, true);
        if (!$sig || !$pk || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('Version non signée (ou clé publique invalide) : installation refusée.');
        if (!sodium_crypto_sign_verify_detached($sig, $sha, $pk)) throw new RuntimeException('Signature invalide : cette archive ne vient pas de l\'éditeur. Installation refusée.');
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

    /** Liste des fichiers à écrire, après filtrage des chemins protégés. */
    private function planifier(ZipArchive $zip, string $prefixe): array
    {
        $plan = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (!str_starts_with($n, $prefixe) || str_ends_with($n, '/')) continue;
            $rel = substr($n, strlen($prefixe));
            if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel) || str_contains($rel, '\\')) {
                throw new RuntimeException("Chemin interdit dans l'archive : $rel");
            }
            $tete = explode('/', $rel)[0];
            if (in_array($tete, self::PROTEGES, true) || in_array($rel, self::PROTEGES, true)) continue;
            if (str_ends_with($rel, '.pem')) continue;
            if (in_array($tete, self::SI_PRESENTS, true) && !is_dir($this->racine . '/' . $tete)) continue;
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

        // Base de données : on ne la modifie pas, mais une copie ne coûte rien.
        $base = 'non nécessaire (aucune donnée modifiée)';
        try {
            $drv = function_exists('cfg') ? (cfg('base_de_donnees', 'driver') ?? 'sqlite') : 'sqlite';
            if ($drv === 'sqlite') {
                $src = (string)(cfg('base_de_donnees', 'path') ?? 'data/gmao.db');
                if (!str_starts_with($src, '/')) $src = $this->racine . '/' . $src;
                if (is_file($src) && @copy($src, "$dir/$id.db")) $base = "copie SQLite ($id.db)";
            } elseif (function_exists('_sa_do_backup')) {
                $r = _sa_do_backup(['base_de_donnees' => cfg('base_de_donnees'), '_key' => 'avant-maj'], $dir, true);
                $base = 'sauvegarde ' . ($r['fichier'] ?? $r['file'] ?? 'effectuée');
            } else {
                $base = 'non faite ici (' . $drv . ') — utilisez la sauvegarde planifiée';
            }
        } catch (\Throwable $e) {
            $base = 'échec (' . $e->getMessage() . ') — aucune donnée n\'est modifiée par la mise à jour';
        }
        return ['base' => $base];
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
            $sauv[] = ['id' => basename($f, '.zip'), 'taille_ko' => (int)(filesize($f) / 1024), 'le' => date('c', filemtime($f))];
        }
        usort($sauv, fn($a, $b) => strcmp($b['id'], $a['id']));
        return ['installations' => array_reverse($h['liste'] ?? []), 'sauvegardes' => $sauv];
    }

    /**
     * Remet les fichiers sauvegardés avant une installation. Les fichiers AJOUTÉS
     * par la mise à jour sont laissés en place (rien n'est supprimé) ;
     * version.json revient, donc la version affichée aussi.
     */
    public function restaurer(string $id, string $parQui = '?'): array
    {
        if (!preg_match('/^[\w.-]+$/', $id)) throw new RuntimeException('Identifiant de sauvegarde invalide.');
        $f = $this->dossier . "/sauvegardes/$id.zip";
        $zip = new ZipArchive();
        if (!is_file($f) || $zip->open($f) !== true) throw new RuntimeException('Sauvegarde introuvable.');
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rel = (string)$zip->getNameIndex($i);
            if ($rel === '.larka-sauvegarde.json' || str_ends_with($rel, '/')) continue;
            if (preg_match('#(^|/)\.\.(/|$)#', $rel) || str_starts_with($rel, '/')) continue;
            $tete = explode('/', $rel)[0];
            if (in_array($tete, self::PROTEGES, true)) continue;
            $this->ecrireFichier($this->racine . '/' . $rel, (string)$zip->getFromIndex($i));
            $n++;
        }
        $zip->close();
        if (function_exists('opcache_reset')) @opcache_reset();
        $this->historiser(['ok' => true, 'restauration' => $id, 'fichiers' => $n, 'par' => $parQui, 'le' => date('c'),
                           'vers' => $this->locale()['version']]);
        return ['ok' => true, 'fichiers' => $n, 'version' => $this->locale()];
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
