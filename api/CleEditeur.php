<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — Clé publique de l'ÉDITEUR (ancre de confiance des mises à jour).
 *
 * Toute mise à jour installée par l'application doit être signée (Ed25519)
 * par la clé privée correspondant à cette clé publique. Sans elle, ou avec
 * une signature absente ou fausse, l'installation est REFUSÉE.
 *
 * POURQUOI ICI ET PAS DANS config.json
 * La clé vivait dans config.json (mises_a_jour.cle_publique), et la
 * vérification était désactivée quand elle était vide — ce qui était le cas
 * par défaut. Or config.json se modifie depuis l'interface (Configuration →
 * Serveur : super admin, ou gestionnaire en mono-tenant). Quiconque tenait
 * ce compte pouvait donc désigner SA clé et SA source de mises à jour, puis
 * faire installer son propre code sur le serveur. Une clé livrée AVEC le code
 * ne se change que par une mise à jour… elle-même signée par la clé en place.
 *
 * CE FICHIER EST ÉCRIT PAR L'OUTIL DE PUBLICATION
 *   php outils/publier-version.php --generer-cles          (nouvelle paire)
 *   php outils/publier-version.php --ecrire-cle-publique   (depuis une clé privée existante)
 * L'outil refuse de construire une version tant que cette clé est vide ou ne
 * correspond pas à la clé privée de signature.
 *
 * Valeur : clé publique Ed25519 (32 octets) encodée en base64.
 */
return 'f6bNJ/Cz2Ia3czoMG5TB90/+o+Gw2mrrzhlI3S9e4LI=';