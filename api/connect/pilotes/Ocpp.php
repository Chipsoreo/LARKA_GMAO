<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote OCPP 1.6J (bornes de recharge).
 *
 * En OCPP, c'est la BORNE qui se connecte au superviseur (WebSocket). Ce
 * pilote ne lit donc rien lui-même : le service permanent (api/outils/
 * connect-ocpp.php) reçoit les bornes, tient leurs points à jour et range les
 * sessions de recharge. Ce pilote :
 *   - dit si la borne est connectée (test) et quelle adresse lui donner ;
 *   - transmet les commandes (arrêt de charge à distance, mise hors service,
 *     redémarrage, déverrouillage) au service, par la file ConnectCommandes,
 *     et attend sa réponse.
 */

declare(strict_types=1);

final class ConnectPiloteOcpp extends ConnectPiloteBase
{
    public const COMMANDES = [
        'RemoteStopTransaction' => 'Arrêter la charge en cours',
        'RemoteStartTransaction' => 'Démarrer une charge (badge)',
        'ChangeAvailability:Inoperative' => 'Mettre hors service',
        'ChangeAvailability:Operative' => 'Remettre en service',
        'Reset:Soft' => 'Redémarrer (logiciel)',
        'Reset:Hard' => 'Redémarrer (complet)',
        'UnlockConnector' => 'Déverrouiller le câble',
        'TriggerMessage:StatusNotification' => 'Demander l\'état',
        'TriggerMessage:MeterValues' => 'Demander un relevé',
    ];

    public static function description(): array
    {
        return [
            'protocole' => 'ocpp',
            'libelle' => 'OCPP 1.6J (borne de recharge)',
            'resume' => 'Bornes de recharge de véhicules électriques : états, sessions, énergie, commandes à distance.',
            'collecte' => 'entrant',
            'port_defaut' => null,
            'intervalle_defaut' => 0,
            'hote' => null,
            'identifiant' => ['libelle' => 'Identifiant de la borne (ChargeBoxId)', 'requis' => true,
                              'aide' => 'Tel que configuré dans la borne, ex. BORNE-01. Il termine l\'adresse que la borne appelle.'],
            'champs' => [
                ['cle' => 'badges', 'libelle' => 'Badges acceptés', 'type' => 'choix', 'defaut' => 'tous',
                 'options' => [['tous', 'Tous les badges'], ['liste', 'Seulement la liste ci-dessous']]],
                ['cle' => 'liste_badges', 'libelle' => 'Liste des badges (un par ligne)', 'type' => 'texte_long', 'defaut' => '',
                 'aide' => 'Identifiants RFID (idTag), ex. 04A2B3C4. Un libellé peut suivre après « ; ».'],
                ['cle' => 'intervalle_battement', 'libelle' => 'Intervalle de battement de cœur (s)', 'type' => 'nombre', 'defaut' => 60],
                ['cle' => 'defaut_vers_demande', 'libelle' => 'Un défaut de la borne crée une demande d\'intervention', 'type' => 'booleen', 'defaut' => true],
            ],
            'secrets' => [
                ['cle' => 'mot_de_passe', 'libelle' => 'Mot de passe de la borne (OCPP profil 1)', 'type' => 'secret',
                 'aide' => 'Facultatif mais recommandé : la borne s\'authentifie en Basic avec son identifiant et ce mot de passe.'],
            ],
            'adresse' => [],
            'ecriture' => false,
            'commandes' => self::COMMANDES,
            'decouverte' => false,
            'aide_decouverte' => 'Les points d\'une borne (état, énergie, puissance, défaut) se créent d\'eux-mêmes à sa première connexion.',
        ];
    }

    public function lire(array $points): array
    {
        return [];       // les valeurs arrivent par le service OCPP
    }

    /** État publié par le service OCPP (data/run/connect-ocpp.json). */
    public static function etatService(): array
    {
        $f = dirname(__DIR__, 3) . '/data/run/connect-ocpp.json';
        $e = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($e)) return ['en_marche' => false];
        $vivant = !empty($e['maj']) && (time() - (int)strtotime((string)$e['maj'])) < 30;
        return $e + ['en_marche' => $vivant];
    }

    /** Adresse à configurer dans la borne. */
    public static function adresseBorne(string $identifiant, string $tenant = 'default'): string
    {
        $cfg = function_exists('cfg') ? (cfg('connect', 'ocpp') ?? []) : [];
        $public = trim((string)($cfg['adresse_publique'] ?? ''));
        if ($public === '') {
            $port = (int)($cfg['port'] ?? 9000);
            $hote = (string)($cfg['hote'] ?? '0.0.0.0');
            if ($hote === '0.0.0.0' || $hote === '::' || $hote === '') {
                // Écoute sur toutes les interfaces : l'adresse à donner aux bornes
                // est celle par laquelle on joint Larka (requête web en cours),
                // sinon celle de la route par défaut (UDP « connecté » vers une
                // adresse de documentation : aucun paquet n'est envoyé), sinon le nom.
                $hote = '';
                $sa = (string)($_SERVER['SERVER_ADDR'] ?? '');
                if ($sa !== '' && !in_array($sa, ['127.0.0.1', '::1'], true) && filter_var($sa, FILTER_VALIDATE_IP)) {
                    $hote = str_contains($sa, ':') ? "[$sa]" : $sa;
                }
                if ($hote === '') {
                    $ip = self::adresseServeur('192.0.2.1');
                    if ($ip !== '' && $ip !== '0.0.0.0' && !str_starts_with($ip, '127.')) $hote = $ip;
                }
                if ($hote === '') $hote = gethostname() ?: '<adresse-du-serveur>';
            }
            $public = "ws://$hote:$port/ocpp";
        }
        $multi = class_exists('TenantResolver') && TenantResolver::isMultiTenant();
        return rtrim($public, '/') . '/' . ($multi ? rawurlencode($tenant) . '/' : '') . rawurlencode($identifiant);
    }

    public function tester(array $points = []): array
    {
        $id = trim((string)($this->c['Identifiant'] ?? ''));
        $tenant = (string)($this->c['_tenant'] ?? 'default');
        if ($id === '') return [self::etape('reseau', false, 'Identifiant de la borne non renseigné.')];
        $cfg = function_exists('cfg') ? (cfg('connect', 'ocpp') ?? []) : [];
        if (empty($cfg['actif'])) {
            return [self::etape('reseau', false, 'Le service OCPP est désactivé sur ce serveur (Configuration → Serveur → Larka Connect).')];
        }
        $s = self::etatService();
        if (!$s['en_marche']) {
            return [self::etape('reseau', false, 'Le service OCPP ne tourne pas. Il démarre avec Larka (start.sh, service systemd larka-ocpp, lanceur Windows).')];
        }
        $etapes = [self::etape('reseau', true, 'Service OCPP en écoute sur ' . ($s['ecoute'] ?? '?') . '. Adresse à configurer dans la borne : '
            . self::adresseBorne($id, $tenant))];
        $b = $s['bornes'][$tenant][$id] ?? null;
        if (!$b) {
            $etapes[] = self::etape('protocole', false, "La borne « $id » ne s'est pas encore connectée. Vérifiez l'adresse configurée dans la borne, "
                . 'son identifiant (sensible à la casse) et que le serveur est joignable depuis son réseau.');
            return $etapes;
        }
        if (empty($b['connecte'])) {
            $etapes[] = self::etape('protocole', false, "Borne « $id » déconnectée depuis le " . date('d/m/Y à H:i', (int)strtotime((string)($b['depuis'] ?? 'now'))) . '.');
            return $etapes;
        }
        $etapes[] = self::etape('protocole', true, "Borne « $id » connectée depuis le " . date('d/m/Y à H:i', (int)strtotime((string)($b['depuis'] ?? 'now')))
            . (!empty($b['ip']) ? " (adresse {$b['ip']})" : '') . (!empty($b['modele']) ? ' — ' . $b['modele'] : '') . '.');
        if (!empty($b['auth'])) $etapes[] = self::etape('droits', true, 'Borne authentifiée par mot de passe (profil 1).');
        else $etapes[] = self::etape('droits', null, 'Borne acceptée sans mot de passe : définissez-en un pour qu\'une autre borne ne puisse pas se faire passer pour elle.');
        return $etapes;
    }
}
