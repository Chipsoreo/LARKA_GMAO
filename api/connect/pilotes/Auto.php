<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — « à détecter » : un appareil hors réseau dont on ne connaît
 * pas encore le protocole.
 *
 * Le serveur ne le joint pas (hotspot ou câble direct). Au prochain passage,
 * le PC releveur regarde ce qu'il expose — ports, pages web et API JSON
 * qu'elles interrogent, registres Modbus — et l'envoie à Larka
 * (ConnectReconnaissance), qui propose les réglages et les points. Une fois
 * appliqués, la connexion prend le vrai protocole.
 */

declare(strict_types=1);

final class ConnectPiloteAuto extends ConnectPiloteBase
{
    public static function description(): array
    {
        return [
            'protocole' => 'auto',
            'libelle' => 'À détecter',
            'resume' => 'Vous ne savez pas ? Le PC releveur regarde ce que l\'appareil expose (page web, API JSON, Modbus, BACnet) et propose les réglages et les points.',
            'collecte' => 'interrogation',
            'port_defaut' => null,
            'intervalle_defaut' => 3600,
            'hote' => ['libelle' => 'Adresse de l\'appareil (facultatif)', 'requis' => false,
                       'aide' => 'Vide : sur son hotspot, l\'appareil est la passerelle du Wi-Fi, le PC le trouve seul.'],
            'identifiant' => null,
            'champs' => [],
            'secrets' => [],
            'adresse' => [],
            'ecriture' => false,
            'decouverte' => false,
            'hors_reseau_seulement' => true,
        ];
    }

    public function tester(array $points = []): array
    {
        return [self::etape('protocole', false, 'Protocole encore à détecter : le PC releveur s\'en charge à son prochain passage.', null)];
    }

    public function lire(array $points): array
    {
        throw new RuntimeException('Protocole encore à détecter : le PC releveur s\'en charge à son prochain passage.');
    }
}
