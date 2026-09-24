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
 * Larka — Sécurité des extensions : politique des tables et des colonnes
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Classe chaque table et chaque colonne de la base, DYNAMIQUEMENT, par
 * introspection — pas à partir d'une liste écrite en dur qui prendrait du retard
 * au premier ajout de table.
 *
 * PRINCIPE : REFUS PAR DÉFAUT
 * Une table inconnue n'est pas « probablement anodine », elle est refusée. Une
 * colonne inconnue d'une table autorisée est refusée. C'est ce qui fait que la
 * politique reste sûre quand le schéma évolue sans qu'on ait pensé à elle : le
 * pire cas est une extension qui ne voit pas assez, jamais une extension qui
 * voit trop.
 *
 * NIVEAUX DE TABLE
 *   SYSTEME_PROTEGE  jamais accessible, ni en lecture ni en écriture
 *   SENSIBLE         lecture possible sous capacité explicite, écriture jamais
 *   NORMAL           lecture sous capacité, écriture sous capacité
 *   EXTENSION        table appartenant à une extension (préfixe ext_<id>_)
 *
 * NIVEAUX DE COLONNE
 *   AUTORISE  renvoyée telle quelle
 *   MASQUE    renvoyée partiellement  (jean.***@exemple.fr)
 *   CAVIARDE  renvoyée vide           (valeur remplacée par ●●●●)
 *   REFUSE    jamais renvoyée, et sa demande explicite refuse l'opération
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/JournalSecurite.php';

final class ExtPolitiqueDonnees
{
    public const SYSTEME_PROTEGE = 'SYSTEME_PROTEGE';
    public const SENSIBLE        = 'SENSIBLE';
    public const NORMAL          = 'NORMAL';
    public const EXTENSION       = 'EXTENSION';

    public const AUTORISE = 'AUTORISE';
    public const MASQUE   = 'MASQUE';
    public const CAVIARDE = 'CAVIARDE';
    public const REFUSE   = 'REFUSE';

    /**
     * Tables du cœur qu'aucune extension n'atteint, jamais.
     * Comparaison insensible à la casse : les moteurs ne s'accordent pas.
     */
    private const TABLES_INTERDITES = [
        'utilisateurs',            // comptes, mots de passe, jetons de réinitialisation
        'configuration',           // secrets applicatifs, réglages serveur
        'pushsubscriptions',       // points de terminaison de notification
        'historiquesuppression',   // trace de suppression : reconstitution possible
        'sa_tenants',              // multi-tenant : identifiants d'autres clients
        'sa_admins',
        'sa_sessions',
        'sessions',
        'migrations',
    ];

    /** Tables lisibles sous capacité, mais jamais modifiables par une extension. */
    private const TABLES_SENSIBLES = [
        'demandesintervention',    // nominatif
        'gestionmateriel',         // nominatif
        'archivesdossier',         // nominatif
        'archivesboite',
        'archivesbordereau',
        'documents',               // chemins de fichiers
        'factures',
        'factureinterventions',
        'declarationsinventaire',
        'notesinfo',
    ];

    /**
     * Motifs de colonnes sensibles, appliqués à TOUTE table.
     *
     * Par motif plutôt que par liste nominative : une colonne ajoutée demain et
     * nommée `ApiToken` sera couverte sans que personne n'ait pensé à
     * l'inscrire. Une liste figée, elle, prend du retard en silence — et un
     * retard sur une colonne de secret, c'est une fuite.
     */
    private const MOTIFS_COLONNES = [
        // REFUSE : la valeur ne sort jamais, et la demander refuse l'opération
        self::REFUSE => [
            '/(mot_?de_?passe|password|passwd|motdepasse)/i',
            '/(secret|api_?key|apikey|private_?key|encryption_?key)/i',
            '/(token|jeton)/i',
            '/(hash|salt)/i',
            '/(vapid|oauth|client_?secret)/i',
        ],
        // CAVIARDE : on confirme l'existence, jamais le contenu
        self::CAVIARDE => [
            '/(iban|bic|rib)/i',
            '/(numero_?securite|nir|siret_?prive)/i',
        ],
        // MASQUE : partiellement lisible, assez pour rapprocher, pas pour exploiter
        self::MASQUE => [
            '/(email|courriel|mail)$/i',
            '/(telephone|tel|mobile|portable)$/i',
            '/(adresse_?perso|adressepersonnelle)/i',
            '/(nomprenom|nom_?prenom)/i',
        ],
    ];

    private static ?array $cacheTables = null;
    private static array $cacheColonnes = [];

    // ═══════════════════════════════════════════════════════════════════════
    //  Introspection
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Tables réellement présentes dans la base.
     *
     * Par introspection du moteur : la politique suit le schéma, y compris les
     * tables ajoutées par une migration ou par une extension.
     */
    public static function tablesExistantes($db): array
    {
        if (self::$cacheTables !== null) return self::$cacheTables;

        $pdo = $db->getPdo();
        $pilote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($pilote) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table'",
            'pgsql'  => "SELECT tablename AS name FROM pg_tables WHERE schemaname='public'",
            'mysql'  => 'SHOW TABLES',
            default  => null,
        };
        if ($sql === null) return self::$cacheTables = [];

        $out = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $l) {
            $out[] = (string)$l[0];
        }
        sort($out);
        return self::$cacheTables = $out;
    }

    /** Colonnes réellement présentes dans une table. */
    public static function colonnesExistantes($db, string $table): array
    {
        if (isset(self::$cacheColonnes[$table])) return self::$cacheColonnes[$table];

        $pdo = $db->getPdo();
        $pilote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $reel = self::nomReel($db, $table);
        if ($reel === null) return self::$cacheColonnes[$table] = [];

        $out = [];
        try {
            if ($pilote === 'sqlite') {
                // PRAGMA n'accepte pas de paramètre lié : le nom vient de la
                // liste introspectée, jamais de l'extension.
                foreach ($pdo->query('PRAGMA table_info("' . $reel . '")')
                             ->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $out[] = (string)$c['name'];
                }
            } else {
                $st = $pdo->prepare(
                    'SELECT column_name FROM information_schema.columns
                     WHERE table_name = :t ORDER BY ordinal_position');
                $st->execute(['t' => $reel]);
                $out = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (\Throwable $e) {
            return self::$cacheColonnes[$table] = [];
        }
        return self::$cacheColonnes[$table] = $out;
    }

    /**
     * Nom réel d'une table, à la casse du moteur.
     * Renvoie null si elle n'existe pas — donc si elle n'existe pas, elle est
     * refusée : on ne laisse pas une extension deviner des noms.
     */
    public static function nomReel($db, string $demande): ?string
    {
        foreach (self::tablesExistantes($db) as $t) {
            if (strcasecmp($t, $demande) === 0) return $t;
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Classification
    // ═══════════════════════════════════════════════════════════════════════

    /** Niveau d'une table. Une table inconnue est traitée comme protégée. */
    public static function niveauTable($db, string $table): string
    {
        $reel = self::nomReel($db, $table);
        if ($reel === null) return self::SYSTEME_PROTEGE;   // refus par défaut

        $bas = strtolower($reel);

        // Table appartenant à une extension : ext_<identifiant>_<nom>
        if (str_starts_with($bas, 'ext_')) return self::EXTENSION;

        if (in_array($bas, self::TABLES_INTERDITES, true)) return self::SYSTEME_PROTEGE;
        if (in_array($bas, self::TABLES_SENSIBLES, true))  return self::SENSIBLE;

        // Tout ce qui ressemble à de l'authentification ou à du secret, même
        // dans une table qu'on n'a pas prévue.
        if (preg_match('/(user|utilisateur|auth|session|token|secret|credential|password)/i', $bas)) {
            return self::SYSTEME_PROTEGE;
        }
        return self::NORMAL;
    }

    /** Niveau d'une colonne, indépendamment de la table. */
    public static function niveauColonne(string $colonne): string
    {
        foreach (self::MOTIFS_COLONNES as $niveau => $motifs) {
            foreach ($motifs as $m) {
                if (preg_match($m, $colonne)) return $niveau;
            }
        }
        return self::AUTORISE;
    }

    /**
     * Table appartenant à cette extension précise ?
     * Le préfixe encode le propriétaire : une extension ne peut pas atteindre
     * la table d'une autre, même en connaissant son nom.
     */
    public static function prefixeExtension(string $identifiant): string
    {
        return 'ext_' . preg_replace('/[^a-z0-9]/', '_', strtolower($identifiant)) . '_';
    }

    public static function appartientA(string $table, string $identifiant): bool
    {
        return str_starts_with(strtolower($table), self::prefixeExtension($identifiant));
    }

    /**
     * Deux identifiants se disputent-ils le même espace de tables ?
     *
     * ⚠️ LE PRÉFIXE N'EST PAS INJECTIF, ET L'APPARTENANCE EN DÉPEND.
     *
     * prefixeExtension() remplace tout ce qui n'est pas alphanumérique par « _ ».
     * Deux conséquences, toutes deux vérifiées :
     *
     *   « larka.recharge »   → ext_larka_recharge_
     *   « larka.recharge-x » → ext_larka_recharge_x_
     *
     * Le premier préfixe est un préfixe du second : appartientA() répond donc
     * OUI quand « larka.recharge » réclame la table « ext_larka_recharge_x_… »
     * — celle d'un AUTRE module. Lecture, écriture et suppression comprises,
     * puisque tout le reste de la politique s'aligne sur cette réponse.
     *
     *   « larka.recharge-x » et « larka-recharge.x » → le MÊME préfixe.
     *
     * Deux modules distincts partageraient alors une seule table.
     *
     * On ne change pas le calcul du préfixe : les tables installées portent ces
     * noms-là, et les renommer perdrait des données. On refuse la collision là
     * où elle peut naître — à l'installation, seul moment où un espace de noms
     * apparaît. Le cloisonnement redevient une propriété du nom, ce qu'il
     * prétendait être.
     */
    public static function espacesEnConflit(string $a, string $b): bool
    {
        if (strcasecmp($a, $b) === 0) return false;         // le même module
        $pa = self::prefixeExtension($a);
        $pb = self::prefixeExtension($b);
        return str_starts_with($pa, $pb) || str_starts_with($pb, $pa);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Filtrage des résultats
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Applique la politique de colonnes à une ligne renvoyée.
     * Dernier filet : même si une colonne interdite avait franchi la
     * construction de la requête, elle ne sortirait pas d'ici.
     */
    public static function filtrerLigne(array $ligne): array
    {
        $out = [];
        foreach ($ligne as $col => $val) {
            switch (self::niveauColonne((string)$col)) {
                case self::REFUSE:
                    break;                                   // absente du résultat
                case self::CAVIARDE:
                    $out[$col] = $val === null ? null : '●●●●';
                    break;
                case self::MASQUE:
                    $out[$col] = self::masquer((string)$col, $val);
                    break;
                default:
                    $out[$col] = $val;
            }
        }
        return $out;
    }

    /**
     * Masquage : assez pour reconnaître, pas assez pour exploiter.
     * Un courriel masqué reste utile à l'affichage (« c'est bien Jean »),
     * mais ne permet ni de le contacter ni de le revendre.
     */
    public static function masquer(string $colonne, mixed $valeur): mixed
    {
        if ($valeur === null || $valeur === '') return $valeur;
        $v = (string)$valeur;

        if (preg_match('/(email|courriel|mail)$/i', $colonne) && str_contains($v, '@')) {
            [$local, $domaine] = explode('@', $v, 2);
            $debut = mb_substr($local, 0, min(3, max(1, mb_strlen($local) - 1)));
            return $debut . str_repeat('*', 3) . '@' . $domaine;
        }

        if (preg_match('/(telephone|tel|mobile|portable)$/i', $colonne)) {
            $chiffres = preg_replace('/\D/', '', $v);
            if (strlen($chiffres) < 4) return '••';
            return str_repeat('•', max(0, strlen($chiffres) - 2)) . substr($chiffres, -2);
        }

        // Défaut : première lettre, puis des points.
        $n = mb_strlen($v);
        return mb_substr($v, 0, 1) . str_repeat('•', max(1, min($n - 1, 8)));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Inventaire, pour l'écran d'administration
    // ═══════════════════════════════════════════════════════════════════════

    public static function inventaire($db): array
    {
        $out = [];
        foreach (self::tablesExistantes($db) as $t) {
            $niveau = self::niveauTable($db, $t);
            $colonnes = [];
            foreach (self::colonnesExistantes($db, $t) as $c) {
                $n = self::niveauColonne($c);
                if ($n !== self::AUTORISE) $colonnes[$c] = $n;
            }
            $out[] = ['table' => $t, 'niveau' => $niveau,
                      'colonnes_protegees' => $colonnes];
        }
        return $out;
    }

    /** Vide les caches — après création d'une table par une extension. */
    public static function oublier(): void
    {
        self::$cacheTables = null;
        self::$cacheColonnes = [];
    }
}
