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
 * Larka — Sécurité des extensions : surveillance comportementale
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Les contrôles précédents jugent une opération isolée : cette table
 * est-elle autorisée, cette colonne peut-elle sortir. Ils ne voient pas la
 * SÉQUENCE — et c'est la séquence qui trahit l'exfiltration.
 *
 * Une extension qui lit dix équipements est normale. La même qui lit ensuite
 * cent mille lignes réparties sur huit tables, en trente secondes, ne l'est
 * plus : chaque opération prise séparément était légitime.
 *
 * On surveille donc, sur une fenêtre glissante :
 *   • le volume total de lignes renvoyées ;
 *   • le nombre d'opérations ;
 *   • le nombre de tables distinctes visitées ;
 *   • les accès à des ressources sensibles ;
 *   • les refus accumulés — une extension qui se heurte dix fois à la politique
 *     n'explore pas par hasard.
 *
 * CE QUE CE N'EST PAS
 * Ce n'est pas de la détection d'intrusion. C'est un jeu de seuils, contournable
 * par qui accepte d'être lent : cent lignes par heure passeront toujours. Cela
 * élève le coût d'une extraction massive et la rend visible dans le journal —
 * ce qui est déjà beaucoup, mais ne remplace pas les frontières des couches
 * précédentes.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/JournalSecurite.php';

final class ExtSurveillance
{
    /** Fenêtre d'observation, en secondes. */
    private const FENETRE = 300;                 // 5 minutes

    /** Seuils au-delà desquels on refuse. */
    private const MAX_LIGNES_FENETRE   = 20000;  // volume cumulé renvoyé
    private const MAX_OPERATIONS       = 400;    // appels à la base
    private const MAX_TABLES_DISTINCTES = 12;    // balayage large du schéma
    private const MAX_REFUS            = 10;     // insistance après refus
    private const MAX_SENSIBLES        = 50;     // accès à des tables sensibles

    /** Seuils d'alerte, sans refus : on note, on n'interrompt pas. */
    private const ALERTE_LIGNES = 5000;
    private const ALERTE_TABLES = 6;

    private static function fichier(string $extension): string
    {
        $d = dirname(__DIR__, 3) . '/data/securite/compteurs';
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $extension) . '.json';
    }

    private static function lire(string $extension): array
    {
        $f = self::fichier($extension);
        $e = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        if (!is_array($e)) $e = [];

        // Fenêtre glissante : on ne garde que les événements récents. Le fichier
        // ne grossit donc pas indéfiniment et le jugement porte sur le présent.
        $limite = time() - self::FENETRE;
        $e['evenements'] = array_values(array_filter(
            $e['evenements'] ?? [], fn($x) => ($x['t'] ?? 0) >= $limite));
        return $e;
    }

    private static function ecrire(string $extension, array $etat): void
    {
        @file_put_contents(self::fichier($extension),
            json_encode($etat, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod(self::fichier($extension), 0600);
    }

    /**
     * Enregistre une opération et rend un verdict.
     *
     * @return array{decision:string, motif:?string}
     *         decision : AUTORISE | SUSPECT | REFUSE
     */
    public static function observer(string $extension, string $operation,
                                    ?string $table = null, int $volume = 0,
                                    bool $refuse = false): array
    {
        $etat = self::lire($extension);
        $etat['evenements'][] = [
            't' => time(), 'op' => $operation, 'tb' => $table,
            'v' => $volume, 'r' => $refuse ? 1 : 0,
        ];

        $lignes = 0; $ops = 0; $refus = 0; $sensibles = 0;
        $tables = [];
        foreach ($etat['evenements'] as $x) {
            $ops++;
            $lignes += (int)($x['v'] ?? 0);
            if (!empty($x['r'])) $refus++;
            if (!empty($x['tb'])) {
                $tables[$x['tb']] = true;
                if (str_contains((string)$x['op'], 'sensible')) $sensibles++;
            }
        }
        $nbTables = count($tables);

        $etat['resume'] = ['lignes' => $lignes, 'operations' => $ops,
                           'tables' => $nbTables, 'refus' => $refus];

        $decision = ExtJournalSecurite::AUTORISE;
        $motif = null;

        if ($refus >= self::MAX_REFUS) {
            $decision = ExtJournalSecurite::REFUSE;
            $motif = 'refus_repetes';
        } elseif ($lignes > self::MAX_LIGNES_FENETRE) {
            $decision = ExtJournalSecurite::REFUSE;
            $motif = 'volume_excessif';
        } elseif ($ops > self::MAX_OPERATIONS) {
            $decision = ExtJournalSecurite::REFUSE;
            $motif = 'frequence_excessive';
        } elseif ($nbTables > self::MAX_TABLES_DISTINCTES) {
            $decision = ExtJournalSecurite::REFUSE;
            $motif = 'balayage_du_schema';
        } elseif ($sensibles > self::MAX_SENSIBLES) {
            $decision = ExtJournalSecurite::REFUSE;
            $motif = 'acces_massif_donnees_sensibles';
        } elseif ($lignes > self::ALERTE_LIGNES || $nbTables > self::ALERTE_TABLES) {
            $decision = ExtJournalSecurite::SUSPECT;
            $motif = 'comportement_inhabituel';
        }

        // Un refus comportemental persiste jusqu'à la fin de la fenêtre : sans
        // cela, l'extension recommence à la milliseconde suivante et le seuil
        // ne sert à rien.
        if ($decision === ExtJournalSecurite::REFUSE) {
            $etat['bloquee_jusqua'] = time() + self::FENETRE;
        }

        self::ecrire($extension, $etat);

        if ($decision !== ExtJournalSecurite::AUTORISE) {
            ExtJournalSecurite::consigner($decision, [
                'extension'        => $extension,
                'operation_reelle' => $operation,
                'table'            => $table,
                'motif'            => $motif,
                'volume'           => $lignes,
                'gravite'          => $decision === ExtJournalSecurite::REFUSE
                                      ? ExtJournalSecurite::CRITIQUE
                                      : ExtJournalSecurite::NOTABLE,
                'detail'           => sprintf('%d lignes · %d opérations · %d tables · %d refus',
                                              $lignes, $ops, $nbTables, $refus),
            ]);
        }

        return ['decision' => $decision, 'motif' => $motif];
    }

    /** L'extension est-elle sous le coup d'un blocage comportemental ? */
    public static function bloquee(string $extension): bool
    {
        $etat = self::lire($extension);
        return (int)($etat['bloquee_jusqua'] ?? 0) > time();
    }

    /** État courant, pour l'écran d'administration. */
    public static function etat(string $extension): array
    {
        $e = self::lire($extension);
        return [
            'resume'  => $e['resume'] ?? ['lignes' => 0, 'operations' => 0,
                                          'tables' => 0, 'refus' => 0],
            'bloquee' => (int)($e['bloquee_jusqua'] ?? 0) > time(),
            'jusqua'  => isset($e['bloquee_jusqua']) && $e['bloquee_jusqua'] > time()
                         ? date('c', $e['bloquee_jusqua']) : null,
            'seuils'  => [
                'lignes' => self::MAX_LIGNES_FENETRE, 'operations' => self::MAX_OPERATIONS,
                'tables' => self::MAX_TABLES_DISTINCTES, 'refus' => self::MAX_REFUS,
                'fenetre_secondes' => self::FENETRE,
            ],
        ];
    }

    /** Lève un blocage — geste d'administrateur, tracé. */
    public static function reinitialiser(string $extension, string $parQui): void
    {
        @unlink(self::fichier($extension));
        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $extension, 'operation_reelle' => 'surveillance.reinitialisation',
            'motif' => 'levee_manuelle', 'utilisateur' => $parQui,
            'gravite' => ExtJournalSecurite::NOTABLE,
        ]);
    }
}
