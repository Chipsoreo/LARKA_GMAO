<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka — sauvegardes automatiques
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  ⚠️ ELLES N'AVAIENT JAMAIS EU LIEU.
 *  Le réglage existait (case « Sauvegardes automatiques activées », intervalle,
 *  rotation) et l'écran disait « doit être déclenchée par un cron qui appelle
 *  l'API » — mais aucune route ne le permettait et rien ne la déclenchait. Une
 *  installation réglée pour « toutes les 6 heures » n'avait donc aucune
 *  sauvegarde : on ne le découvre qu'au jour où l'on en a besoin.
 *
 *  Ce fichier fait le travail ; quatre déclencheurs l'appellent, de sorte que
 *  les sauvegardes partent quelle que soit la façon dont Larka tourne :
 *    - production Linux : tâche cron posée par deploy/install.sh ;
 *    - démarrage automatique (systemd) : minuterie larka-sauvegardes.timer ;
 *    - ./start.sh start : un planificateur accompagne le serveur ;
 *    - Windows (Larka.cmd) : le lanceur, qui reste ouvert, s'en charge.
 *  Chacun lance « php api/outils/sauvegarde-auto.php » ; un verrou empêche deux
 *  sauvegardes simultanées, et seule une sauvegarde DUE est faite.
 *
 *  Ce qui est sauvegardé :
 *    - multi-tenant : chaque organisation dont la sauvegarde est activée
 *      (Super Admin → organisation → Sauvegardes) ;
 *    - installation simple : la base de config.json, si « sauvegardes.actif »
 *      (Configuration → Serveur → Sauvegardes automatiques).
 *  Base (instantané SQLite ou SQL PostgreSQL / MariaDB) ET fichiers (plans,
 *  modules, thèmes, médias d'urgence), rotation selon « garder ».
 * ═══════════════════════════════════════════════════════════════════════════
 */

final class LarkaSauvegardesAuto
{
    /** Après un échec, on retente au plus tôt après ce délai (minutes). */
    private const REESSAI_MINUTES = 60;

    private static function racine(): string { return dirname(__DIR__); }
    private static function fichierEtat(): string { return self::racine() . '/data/run/sauvegardes-auto.json'; }

    /** Fonctions de sauvegarde du Super Admin (_sa_do_backup, rotation…). */
    private static function chargerOutils(): void
    {
        if (function_exists('_sa_do_backup')) return;
        // Le fichier de routes ne fait rien d'autre que DÉFINIR ces fonctions
        // quand aucune action ne lui correspond.
        $action = '';
        $method = 'INTERNE';
        require_once __DIR__ . '/routes/superadmin.php';
    }

    private static function lireEtat(): array
    {
        $e = @json_decode((string)@file_get_contents(self::fichierEtat()), true);
        return is_array($e) ? $e : [];
    }

    private static function ecrireEtat(array $e): void
    {
        @mkdir(dirname(self::fichierEtat()), 0775, true);
        $tmp = self::fichierEtat() . '.' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, self::fichierEtat());
        }
    }

    /** Réglage de sauvegarde de l'installation simple (config.json « sauvegardes »). */
    private static function reglagePrincipal(): array
    {
        $c = function_exists('cfg') ? (cfg('sauvegardes') ?? []) : [];
        $c = is_array($c) ? $c : [];
        return [
            'actif'              => filter_var($c['actif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'intervalle_minutes' => max(5, (int)($c['intervalle_minutes'] ?? 360)),
            'garder'             => max(1, (int)($c['garder'] ?? 30)),
            'compresser'         => filter_var($c['compresser'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'dossier'            => (string)(($c['dossier'] ?? '') ?: 'data/backups'),
        ];
    }

    /**
     * Ce qui est à sauvegarder : une entrée par base, avec son réglage et
     * son dernier passage.
     *
     * @return list<array{cle: string, nom: string, multi: bool, reglage: array, base: array,
     *                    derniere: ?string, tentative: ?string, erreur: ?string, fichier: ?string}>
     */
    public static function travaux(): array
    {
        $etat = self::lireEtat();
        $out = [];
        $multi = class_exists('TenantResolver') && TenantResolver::isMultiTenant();
        if ($multi) {
            foreach (TenantResolver::getAllTenants() as $cle => $t) {
                $sv = is_array($t['sauvegarde'] ?? null) ? $t['sauvegarde'] : [];
                $out[] = [
                    'cle' => (string)$cle, 'nom' => (string)($t['nom'] ?? $cle), 'multi' => true,
                    'reglage' => [
                        'actif'              => !empty($sv['actif']) && !empty($t['actif']),
                        'intervalle_minutes' => max(5, (int)($sv['intervalle_minutes'] ?? 360)),
                        'garder'             => max(1, (int)($sv['garder'] ?? 30)),
                        'compresser'         => (bool)($sv['compresser'] ?? true),
                        'dossier'            => (string)(($sv['dossier'] ?? '') ?: 'data/backups'),
                    ],
                    'base'      => (array)($t['base_de_donnees'] ?? []),
                    'derniere'  => $sv['derniere'] ?? null,
                    'tentative' => $etat[$cle]['tentative'] ?? null,
                    'erreur'    => $etat[$cle]['erreur'] ?? null,
                    'fichier'   => $etat[$cle]['fichier'] ?? null,
                ];
            }
        } else {
            $base = function_exists('cfg') ? cfg('base_de_donnees') : null;
            $out[] = [
                'cle' => 'default', 'nom' => 'Installation', 'multi' => false,
                'reglage' => self::reglagePrincipal(),
                'base' => is_array($base) ? $base : ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
                'derniere'  => $etat['default']['derniere'] ?? null,
                'tentative' => $etat['default']['tentative'] ?? null,
                'erreur'    => $etat['default']['erreur'] ?? null,
                'fichier'   => $etat['default']['fichier'] ?? null,
            ];
        }
        foreach ($out as &$w) $w['prochaine'] = self::prochaine($w);
        return $out;
    }

    /** Horodatage de la prochaine sauvegarde (null : désactivée). */
    private static function prochaine(array $w): ?int
    {
        if (empty($w['reglage']['actif'])) return null;
        $intervalle = (int)$w['reglage']['intervalle_minutes'] * 60;
        $t = $w['derniere'] ? (int)strtotime((string)$w['derniere']) + $intervalle : time();
        // Après un échec : on n'insiste pas à chaque passage.
        if ($w['erreur'] && $w['tentative']) {
            $t = max($t, (int)strtotime((string)$w['tentative']) + min($intervalle, self::REESSAI_MINUTES * 60));
        }
        return $t;
    }

    /**
     * Fait les sauvegardes dues (toutes si $forcer). Un seul passage à la
     * fois (verrou) ; rend le compte rendu de chaque base examinée.
     *
     * @return array{verrou: bool, resultats: list<array>}
     */
    public static function executer(bool $forcer = false, ?string $seulement = null): array
    {
        @mkdir(self::racine() . '/data/run', 0775, true);
        $verrou = @fopen(self::racine() . '/data/run/sauvegardes-auto.lock', 'c');
        if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) {
            return ['verrou' => false, 'resultats' => []];
        }
        @set_time_limit(0);
        self::noterPassage();
        $resultats = [];
        try {
            foreach (self::travaux() as $w) {
                if ($seulement !== null && $w['cle'] !== $seulement) continue;
                if (!$forcer) {
                    if ($w['prochaine'] === null || $w['prochaine'] > time() + 30) {
                        $resultats[] = ['cle' => $w['cle'], 'nom' => $w['nom'], 'fait' => false,
                                        'raison' => $w['prochaine'] === null ? 'désactivée' : 'pas encore due',
                                        'prochaine' => $w['prochaine'] ? date('Y-m-d H:i', $w['prochaine']) : null];
                        continue;
                    }
                }
                $resultats[] = self::sauvegarder($w);
            }
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
        return ['verrou' => true, 'resultats' => $resultats];
    }

    /** Une sauvegarde : base + fichiers, rotation, état noté. */
    private static function sauvegarder(array $w): array
    {
        self::chargerOutils();
        $etat = self::lireEtat();
        $maintenant = date('Y-m-d H:i:s');
        $t0 = microtime(true);
        try {
            $tenant = [
                '_key' => $w['cle'],
                'base_de_donnees' => $w['base'],
                'sauvegarde' => $w['reglage'],
            ];
            $r = _sa_do_backup($tenant, null, (bool)$w['reglage']['compresser']);
            $supprimees = _sa_rotate_backups(dirname($r['path']), $w['cle'], (int)$w['reglage']['garder']);
            $etat[$w['cle']] = ['derniere' => $maintenant, 'tentative' => $maintenant, 'erreur' => null, 'fichier' => $r['name']];
            self::ecrireEtat($etat);
            if ($w['multi']) self::noterTenant($w['cle'], $maintenant);
            $msg = "Sauvegarde automatique « {$w['nom']} » : {$r['name']} (" . round(($r['size'] ?? 0) / 1024) . ' Ko'
                 . (($r['fichiers']['fait'] ?? false) ? ', fichiers compris' : '') . ')';
            if (class_exists('Journal')) { try { Journal::info('sauvegarde', $msg); } catch (\Throwable $_) {} }
            return ['cle' => $w['cle'], 'nom' => $w['nom'], 'fait' => true, 'fichier' => $r['name'], 'chemin' => $r['path'],
                    'octets' => $r['size'] ?? 0, 'fichiers' => $r['fichiers'] ?? null, 'supprimees' => $supprimees,
                    'duree' => round(microtime(true) - $t0, 1)];
        } catch (\Throwable $e) {
            $etat[$w['cle']] = ['derniere' => $w['derniere'], 'tentative' => $maintenant,
                                'erreur' => mb_substr($e->getMessage(), 0, 300), 'fichier' => $w['fichier']];
            self::ecrireEtat($etat);
            if (class_exists('Journal')) {
                try { Journal::erreur('sauvegarde', "Sauvegarde automatique « {$w['nom']} » impossible", ['exception' => $e]); } catch (\Throwable $_) {}
            }
            return ['cle' => $w['cle'], 'nom' => $w['nom'], 'fait' => false, 'erreur' => $e->getMessage()];
        }
    }

    /** Date de la dernière sauvegarde, dans le registre (visible du Super Admin). */
    private static function noterTenant(string $cle, string $quand): void
    {
        try {
            $t = TenantResolver::getAllTenants()[$cle] ?? null;
            if (!$t) return;
            $t['sauvegarde']['derniere'] = $quand;
            TenantResolver::upsertTenant($cle, $t);
        } catch (\Throwable $e) { /* l'état local fait foi */ }
    }

    /**
     * État lisible par l'interface : réglage, dernière, prochaine, erreur,
     * et si un déclencheur a tourné récemment.
     */
    public static function etat(): array
    {
        $etat = self::lireEtat();
        $passage = $etat['_passage'] ?? null;
        $liste = [];
        foreach (self::travaux() as $w) {
            $intervalle = (int)$w['reglage']['intervalle_minutes'];
            $retard = $w['reglage']['actif'] && $w['prochaine'] !== null && $w['prochaine'] < time() - max(1800, $intervalle * 60);
            $liste[] = [
                'cle' => $w['cle'], 'nom' => $w['nom'], 'reglage' => $w['reglage'],
                'derniere' => $w['derniere'], 'erreur' => $w['erreur'], 'fichier' => $w['fichier'],
                'prochaine' => $w['prochaine'] ? date('Y-m-d H:i:s', $w['prochaine']) : null,
                'en_retard' => $retard,
            ];
        }
        return ['dernier_passage' => $passage, 'travaux' => $liste];
    }

    /** Noté à chaque passage du planificateur : l'écran sait s'il tourne. */
    public static function noterPassage(): void
    {
        $e = self::lireEtat();
        $e['_passage'] = date('Y-m-d H:i:s');
        self::ecrireEtat($e);
    }
}
