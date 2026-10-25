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
 * Larka — Sécurité des extensions : journal d'audit
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Journal SÉPARÉ de celui que voit l'extension, et séparé du journal applicatif.
 *
 * DEUX PUBLICS, DEUX NIVEAUX DE DÉTAIL — c'est la raison d'être de ce fichier.
 *
 *   L'extension reçoit :   {"ok": false, "erreur": "operation_refusee"}
 *   Le journal contient :  extension, pid, uid, opération déclarée, opération
 *                          réelle, ressource visée, décision, motif, gravité.
 *
 * Pourquoi ne rien dire à l'extension : un message précis (« table interdite »,
 * « colonne masquée », « quota de 500 lignes ») est un oracle. Un attaquant
 * l'utilise pour cartographier la politique par essais successifs — quelles
 * tables existent, quelles colonnes sont sensibles, où sont les seuils. Le code
 * de refus est donc volontairement opaque et toujours identique.
 *
 * Ce journal n'est jamais lisible par une extension : il vit sous data/securite/,
 * en dehors de tout ce que le Contexte expose, et le processus isolé n'a même
 * pas le droit de lecture sur ce dossier.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtJournalSecurite
{
    public const AUTORISE  = 'AUTORISE';
    public const REFUSE    = 'REFUSE';
    public const FILTRE    = 'FILTRE';     // autorisé, mais contenu réduit
    public const SUSPECT   = 'SUSPECT';    // comportement anormal détecté

    public const INFO      = 'INFO';
    public const NOTABLE   = 'NOTABLE';
    public const GRAVE     = 'GRAVE';
    public const CRITIQUE  = 'CRITIQUE';

    /** Message unique renvoyé à l'extension, quelle que soit la cause réelle. */
    public const MESSAGE_OPAQUE = 'operation_refusee';

    private static ?string $fichier = null;

    private static function fichier(): string
    {
        if (self::$fichier !== null) return self::$fichier;
        $d = dirname(__DIR__, 3) . '/data/securite';
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);   // 0700 : ni le groupe, ni larka-ext
        }
        return self::$fichier = $d . '/extensions-' . date('Y-m') . '.jsonl';
    }

    /**
     * Consigne une décision de sécurité.
     *
     * @param string $decision   AUTORISE | REFUSE | FILTRE | SUSPECT
     * @param array  $contexte   extension, operation_declaree, operation_reelle,
     *                           ressource, table, colonnes, motif, gravite, volume…
     */
    public static function consigner(string $decision, array $contexte = []): void
    {
        $ligne = [
            'horodatage'  => date('c'),
            'decision'    => $decision,
            'gravite'     => $contexte['gravite'] ?? self::INFO,
            'extension'   => $contexte['extension'] ?? '?',
            // pid et uid : indispensables pour rapprocher une entrée d'un
            // processus réel lors d'une analyse post-incident.
            'pid'         => getmypid(),
            'uid'         => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'utilisateur' => $contexte['utilisateur'] ?? null,
        ] + array_intersect_key($contexte, array_flip([
            'operation_declaree', 'operation_reelle', 'ressource', 'table',
            'colonnes', 'motif', 'volume', 'detail',
        ]));

        // Une écriture par ligne, en JSONL : lisible par grep, tolérant à
        // l'écriture concurrente, et jamais réécrit — un journal de sécurité
        // qu'on peut modifier après coup ne vaut rien.
        @file_put_contents(self::fichier(),
            json_encode($ligne, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX);
        @chmod(self::fichier(), 0600);

        // Les faits graves remontent AUSSI dans le journal applicatif, pour que
        // l'administrateur les voie sans avoir à ouvrir un fichier : un incident
        // consigné dans un endroit que personne ne consulte n'est pas consigné.
        $gravite = $contexte['gravite'] ?? self::INFO;
        if (in_array($gravite, [self::GRAVE, self::CRITIQUE], true) && class_exists('Journal')) {
            Journal::critique('securite_extensions', sprintf(
                '[%s] %s a tenté « %s » sur « %s » — %s',
                $decision,
                $contexte['extension'] ?? '?',
                $contexte['operation_reelle'] ?? $contexte['operation_declaree'] ?? '?',
                $contexte['ressource'] ?? $contexte['table'] ?? '?',
                $contexte['motif'] ?? 'motif non précisé'));
        }
    }

    /** Refus : consigne le détail, ne renvoie que l'opaque. */
    public static function refuser(array $contexte): ExtRefusSecurite
    {
        self::consigner(self::REFUSE, $contexte + ['gravite' => self::GRAVE]);
        return new ExtRefusSecurite(self::MESSAGE_OPAQUE);
    }

    /** Les N dernières entrées, pour l'écran d'administration. */
    public static function dernieres(int $n = 100, ?string $extension = null): array
    {
        $f = self::fichier();
        if (!is_file($f)) return [];

        $lignes = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lignes = array_reverse($lignes);

        $out = [];
        foreach ($lignes as $l) {
            $e = json_decode($l, true);
            if (!is_array($e)) continue;
            if ($extension !== null && ($e['extension'] ?? '') !== $extension) continue;
            $out[] = $e;
            if (count($out) >= $n) break;
        }
        return $out;
    }

    /** Synthèse par extension, pour repérer celle qui insiste. */
    public static function synthese(int $heures = 24): array
    {
        $depuis = time() - $heures * 3600;
        $par = [];
        foreach (self::dernieres(5000) as $e) {
            if (strtotime($e['horodatage'] ?? '') < $depuis) break;
            $id = $e['extension'] ?? '?';
            $par[$id] ??= ['extension' => $id, 'total' => 0, 'refus' => 0,
                           'suspects' => 0, 'critiques' => 0];
            $par[$id]['total']++;
            if (($e['decision'] ?? '') === self::REFUSE)  $par[$id]['refus']++;
            if (($e['decision'] ?? '') === self::SUSPECT) $par[$id]['suspects']++;
            if (($e['gravite']  ?? '') === self::CRITIQUE) $par[$id]['critiques']++;
        }
        usort($par, fn($a, $b) => ($b['refus'] + $b['suspects']) <=> ($a['refus'] + $a['suspects']));
        return $par;
    }
}

/**
 * Erreur de SAISIE, destinée à l'utilisateur.
 *
 * Distincte d'un refus de sécurité et d'une panne : « Le numéro est déjà
 * utilisé » n'est pas un incident, c'est une information. Le moteur la renvoie
 * telle quelle et ne compte pas l'appel comme un échec — sinon trois fautes de
 * frappe suffiraient à suspendre un module qui fonctionne parfaitement.
 *
 * Elle vivait dans Contexte.php, retiré avec les modules à code. Sa place est
 * ici : c'est le fichier que tout chemin d'exécution charge.
 */
class ExtErreurUtilisateur extends RuntimeException {}

/**
 * Refus de sécurité.
 *
 * Distincte d'ExtRefus (capacité non accordée, message explicite destiné à
 * l'administrateur) : celle-ci ne dit RIEN. Son message est toujours
 * « operation_refusee », et le détail n'existe que dans le journal.
 */
class ExtRefusSecurite extends RuntimeException {}
