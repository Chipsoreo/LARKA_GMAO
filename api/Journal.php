<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Journal — journalisation applicative centralisée
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Complète SecurityLog.php (dédié aux évènements de sécurité en JSONL pur)
 *  en apportant ce qui manquait :
 *
 *   • une écriture EN BASE, donc consultable et filtrable depuis l'application ;
 *   • une couverture automatique de toutes les requêtes API (middleware) ;
 *   • la capture des exceptions et erreurs fatales avec leur contexte ;
 *   • l'audit des écritures métier avec un diff avant/après ;
 *   • la remontée des erreurs JavaScript du navigateur ;
 *   • le respect réel de LOG_NIVEAU et LOG_ROTATE, qui étaient jusqu'ici
 *     définis dans config.php mais consommés par personne.
 *
 *  ── Principes de conception ──────────────────────────────────────────────
 *
 *  1. NE JAMAIS CASSER L'APPLICATION. Toute la classe est défensive : une
 *     panne d'écriture (base indisponible, disque plein, table absente)
 *     dégrade silencieusement vers le fichier, puis vers error_log(), mais
 *     ne remonte jamais d'exception à l'appelant.
 *
 *  2. DOUBLE ÉCRITURE. La base sert à la consultation ; le fichier JSONL sert
 *     de filet quand la base est justement ce qui a lâché.
 *
 *  3. AUCUN SECRET EN CLAIR. Toute valeur dont la clé évoque un secret est
 *     remplacée par ***. La liste est volontairement large.
 *
 *  4. CORRÉLATION. Chaque requête HTTP reçoit un identifiant unique reporté
 *     sur toutes ses entrées : on reconstitue une requête complète d'un seul
 *     filtre, y compris les erreurs JS qu'elle a déclenchées.
 *
 *  Usage :
 *      Journal::info('interventions', 'Intervention clôturée', ['entiteId' => 42]);
 *      Journal::audit('UPDATE', 'Intervention', 42, $avant, $apres);
 *      Journal::erreur('api', 'Échec Graph', ['exception' => $e]);
 */

final class Journal
{
    // ── Niveaux, du plus bavard au plus grave ────────────────────────────────
    public const DEBUG    = 'debug';
    public const INFO     = 'info';
    public const AUDIT    = 'audit';    // écriture métier — toujours conservé
    public const WARNING  = 'warning';
    public const ERROR    = 'error';
    public const CRITIQUE = 'critique';

    /** Poids pour le filtrage par LOG_NIVEAU. */
    private const POIDS = [
        'debug' => 10, 'info' => 20, 'audit' => 30,
        'warning' => 40, 'error' => 50, 'critique' => 60,
    ];

    /** Clés dont la valeur ne doit jamais être écrite en clair. */
    private const SENSIBLE = [
        'motdepasse', 'mot_de_passe', 'password', 'passwd', 'pwd', 'secret',
        'token', 'access_token', 'refresh_token', 'id_token', 'apikey',
        'api_key', 'authorization', 'auth', 'cookie', 'session', 'csrf',
        'clientsecret', 'client_secret', 'privatekey', 'private_key',
        'vapid', 'signature', 'hash', 'salt', 'resettoken',
    ];

    /** Taille max d'un champ texte stocké (évite qu'un payload gonfle la base). */
    private const MAX_CHAMP = 4000;

    private static ?PDO   $pdo        = null;
    private static bool   $tablePrete = false;
    private static string $requestId  = '';
    private static ?float $debut      = null;
    private static array  $tampon     = [];    // entrées mises de côté si la base n'est pas prête
    private static bool   $enEcriture = false; // garde anti-récursion

    // ═════════════════════════════════════════════════════════════════════════
    //  Initialisation
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Rattache le journal à une connexion PDO. Appelé une fois la base ouverte.
     * Les entrées émises avant (bootstrap, erreurs de configuration) ont été
     * mises en tampon : elles sont écrites ici.
     */
    public static function attacherBase(PDO $pdo): void
    {
        self::$pdo = $pdo;
        try {
            self::assurerTable();
            self::viderTampon();
            self::purgerSiNecessaire();
        } catch (\Throwable $e) {
            self::secours('attacherBase: ' . $e->getMessage());
        }
    }

    /** Identifiant de corrélation de la requête courante. */
    public static function requestId(): string
    {
        if (self::$requestId === '') {
            try {
                self::$requestId = bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                self::$requestId = dechex(mt_rand(0, PHP_INT_MAX));
            }
        }
        return self::$requestId;
    }

    /** Démarre le chronomètre de la requête (appelé par le middleware). */
    public static function demarrerChrono(): void
    {
        self::$debut = microtime(true);
    }

    /** Durée écoulée depuis demarrerChrono(), en millisecondes. */
    public static function dureeMs(): ?int
    {
        return self::$debut === null ? null : (int)round((microtime(true) - self::$debut) * 1000);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  API publique
    // ═════════════════════════════════════════════════════════════════════════

    public static function debug(string $canal, string $message, array $ctx = []): void    { self::log(self::DEBUG,    $canal, $message, $ctx); }
    public static function info(string $canal, string $message, array $ctx = []): void     { self::log(self::INFO,     $canal, $message, $ctx); }
    public static function warning(string $canal, string $message, array $ctx = []): void  { self::log(self::WARNING,  $canal, $message, $ctx); }
    public static function erreur(string $canal, string $message, array $ctx = []): void   { self::log(self::ERROR,    $canal, $message, $ctx); }
    public static function critique(string $canal, string $message, array $ctx = []): void { self::log(self::CRITIQUE, $canal, $message, $ctx); }

    /**
     * Journalise une écriture métier avec le détail de ce qui a changé.
     *
     * Seuls les champs réellement modifiés sont conservés dans Avant/Apres :
     * un UPDATE qui ne touche qu'une date ne produit pas une copie complète de
     * l'enregistrement. C'est ce qui rend le journal exploitable dans le temps.
     *
     * @param string     $operation CREATE, UPDATE, DELETE, RESTORE…
     * @param array|null $avant     état avant (null pour une création)
     * @param array|null $apres     état après (null pour une suppression)
     */
    public static function audit(string $operation, string $entiteType, int $entiteId,
                                 ?array $avant = null, ?array $apres = null, array $ctx = []): void
    {
        $diff = self::diff($avant, $apres);

        // Un UPDATE sans changement effectif n'a aucune valeur : on ne l'écrit
        // pas, sinon le journal se remplit de bruit à chaque ouverture de fiche.
        if (strtoupper($operation) === 'UPDATE' && !$diff['avant'] && !$diff['apres']) {
            return;
        }

        self::log(self::AUDIT, 'audit', strtoupper($operation) . ' ' . $entiteType . '#' . $entiteId, array_merge($ctx, [
            'action'     => strtoupper($operation),
            'entiteType' => $entiteType,
            'entiteId'   => $entiteId,
            '_avant'     => $diff['avant'],
            '_apres'     => $diff['apres'],
        ]));
    }

    /** Écrit une entrée. Point d'entrée unique — tout passe par ici. */
    public static function log(string $niveau, string $canal, string $message, array $ctx = []): void
    {
        // Anti-récursion : si l'écriture d'un log déclenche elle-même un log
        // (typiquement une erreur SQL sur la table Journal), on s'arrête.
        if (self::$enEcriture) return;

        try {
            if (!self::niveauActif($niveau)) return;

            self::$enEcriture = true;
            $entree = self::construireEntree($niveau, $canal, $message, $ctx);

            // 1. Fichiers — toujours, indépendamment de l'état de la base.
            //    .txt   : lisible directement (tail -f), c'est le format de
            //             référence pour l'exploitation quotidienne.
            //    .jsonl : même contenu, structuré, pour un traitement outillé.
            self::ecrireTexte($entree);
            self::ecrireFichier($entree);

            // 2. Base — si disponible, sinon mise en tampon pour plus tard.
            if (self::$pdo !== null && self::$tablePrete) {
                self::ecrireBase($entree);
            } else {
                if (count(self::$tampon) < 200) self::$tampon[] = $entree;
            }
        } catch (\Throwable $e) {
            self::secours('log: ' . $e->getMessage());
        } finally {
            self::$enEcriture = false;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Construction de l'entrée
    // ═════════════════════════════════════════════════════════════════════════

    private static function construireEntree(string $niveau, string $canal, string $message, array $ctx): array
    {
        // Contexte utilisateur : lu depuis la session, sans la démarrer si elle
        // ne l'est pas (on ne veut aucun effet de bord ici).
        $login = $role = ''; $uid = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $login = (string)($_SESSION['user_login'] ?? $_SESSION['login'] ?? '');
            $role  = (string)($_SESSION['user_role']  ?? $_SESSION['role']  ?? '');
            $uid   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        }
        // Un appelant peut surcharger (ex. journaliser une action pour le compte
        // d'un utilisateur identifié autrement que par la session).
        $login = (string)($ctx['login'] ?? $login);
        $role  = (string)($ctx['role']  ?? $role);
        $uid   = isset($ctx['utilisateurId']) ? (int)$ctx['utilisateurId'] : $uid;

        // Exception éventuelle : type, message, fichier, ligne, pile.
        $exception = null;
        if (isset($ctx['exception']) && $ctx['exception'] instanceof \Throwable) {
            $e = $ctx['exception'];
            $exception = [
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'fichier' => self::cheminRelatif($e->getFile()),
                'ligne'   => $e->getLine(),
                'pile'    => self::pileCourte($e),
            ];
            unset($ctx['exception']);
        }

        $avant = $ctx['_avant'] ?? null;
        $apres = $ctx['_apres'] ?? null;
        unset($ctx['_avant'], $ctx['_apres']);

        // Le reste du contexte, nettoyé des clés déjà promues en colonnes.
        $extra = $ctx;
        foreach (['login','role','utilisateurId','action','entiteType','entiteId','codeHttp','dureeMs'] as $k) {
            unset($extra[$k]);
        }
        if ($exception) $extra['exception'] = $exception;

        return [
            'DateHeure'     => date('Y-m-d H:i:s'),
            'Niveau'        => $niveau,
            'Canal'         => substr($canal, 0, 40),
            'Action'        => substr((string)($ctx['action'] ?? ''), 0, 60),
            'Message'       => self::tronquer((string)$message, 1000),
            'UtilisateurId' => $uid,
            'Login'         => substr($login, 0, 80),
            'Role'          => substr($role, 0, 40),
            'Ip'            => self::ip(),
            'UserAgent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            'Methode'       => substr((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'), 0, 10),
            'Route'         => substr((string)($_GET['action'] ?? ''), 0, 60),
            'CodeHttp'      => isset($ctx['codeHttp']) ? (int)$ctx['codeHttp'] : null,
            'DureeMs'       => isset($ctx['dureeMs'])  ? (int)$ctx['dureeMs']  : null,
            'EntiteType'    => substr((string)($ctx['entiteType'] ?? ''), 0, 40),
            'EntiteId'      => isset($ctx['entiteId']) ? (int)$ctx['entiteId'] : null,
            'Avant'         => self::encoder($avant),
            'Apres'         => self::encoder($apres),
            // nettoyer() aussi ici : un appelant peut passer un payload brut
            // contenant un mot de passe ou un token dans le contexte libre.
            'Contexte'      => self::encoder(self::nettoyer($extra) ?: null),
            'RequestId'     => self::requestId(),
        ];
    }

    /** IP réelle du client, en tenant compte d'un éventuel reverse proxy. */
    private static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if ($v !== '') {
                // X-Forwarded-For peut contenir « client, proxy1, proxy2 »
                $premier = trim(explode(',', (string)$v)[0]);
                if ($premier !== '') return substr($premier, 0, 45);
            }
        }
        return 'cli';
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Diff avant/après
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Réduit deux états à leurs seules différences.
     * Comparaison en chaîne : la base peut renvoyer "1" là où le code a écrit 1,
     * et ce n'est pas un changement.
     */
    private static function diff(?array $avant, ?array $apres): array
    {
        if ($avant === null && $apres === null) return ['avant' => null, 'apres' => null];
        if ($avant === null) return ['avant' => null, 'apres' => self::nettoyer($apres)];
        if ($apres === null) return ['avant' => self::nettoyer($avant), 'apres' => null];

        $dA = $dB = [];
        foreach (array_unique(array_merge(array_keys($avant), array_keys($apres))) as $k) {
            $a = $avant[$k] ?? null;
            $b = $apres[$k] ?? null;
            if (is_array($a) || is_array($b)) {
                if (json_encode($a) === json_encode($b)) continue;
            } elseif ((string)$a === (string)$b) {
                continue;
            }
            $dA[$k] = $a;
            $dB[$k] = $b;
        }
        return ['avant' => self::nettoyer($dA) ?: null, 'apres' => self::nettoyer($dB) ?: null];
    }

    /** Masque les valeurs sensibles et coupe les valeurs démesurées. */
    private static function nettoyer(?array $t): ?array
    {
        if ($t === null) return null;
        $out = [];
        foreach ($t as $k => $v) {
            $cle = strtolower(str_replace(['-', '_', ' '], '', (string)$k));
            $estSensible = false;
            foreach (self::SENSIBLE as $motif) {
                if (str_contains($cle, str_replace('_', '', $motif))) { $estSensible = true; break; }
            }
            if ($estSensible)  { $out[$k] = '***'; continue; }
            if (is_array($v))  { $out[$k] = self::nettoyer($v); continue; }
            if (is_object($v)) { $out[$k] = '[objet ' . get_class($v) . ']'; continue; }
            if (is_string($v)) { $out[$k] = self::tronquer($v, 500); continue; }
            $out[$k] = $v;
        }
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Écritures
    // ═════════════════════════════════════════════════════════════════════════

    private static function ecrireBase(array $e): void
    {
        $sql = "INSERT INTO Journal
                (DateHeure,Niveau,Canal,Action,Message,UtilisateurId,Login,Role,Ip,UserAgent,
                 Methode,Route,CodeHttp,DureeMs,EntiteType,EntiteId,Avant,Apres,Contexte,RequestId)
                VALUES
                (:DateHeure,:Niveau,:Canal,:Action,:Message,:UtilisateurId,:Login,:Role,:Ip,:UserAgent,
                 :Methode,:Route,:CodeHttp,:DureeMs,:EntiteType,:EntiteId,:Avant,:Apres,:Contexte,:RequestId)";
        $st = self::$pdo->prepare($sql);
        foreach ($e as $k => $v) $st->bindValue(':' . $k, $v);
        $st->execute();
    }

    /**
     * Journal lisible : data/logs/activite-AAAA-MM-JJ.txt
     *
     * Une ligne par évènement, colonnes alignées pour rester lisible au
     * défilement. Les détails (modifications, pile d'appel, contexte) sont
     * indentés sous la ligne principale plutôt que compressés dedans.
     *
     * Format :
     *   11:22:03  INFO      api       mdurand@203.0.113.42  GET interventions → 200 (45 ms)
     *   11:22:05  AUDIT     donnees   mdurand@203.0.113.42  UPDATE Interventions#42
     *                └─ Statut : « En cours » → « Réalisée »
     */
    private static function ecrireTexte(array $e): void
    {
        $dossier = defined('LOG_PATH') ? LOG_PATH : (__DIR__ . '/../data/logs');
        if (!is_dir($dossier) && !@mkdir($dossier, 0755, true) && !is_dir($dossier)) return;

        $heure  = substr((string)$e['DateHeure'], 11, 8);
        $niveau = strtoupper((string)$e['Niveau']);
        $canal  = (string)$e['Canal'];
        $qui    = ($e['Login'] !== '' ? $e['Login'] : 'anonyme') . '@' . $e['Ip'];

        // Corps du message, enrichi selon le type d'évènement.
        $corps = (string)$e['Message'];
        if ($canal === 'api') {
            $corps = trim(($e['Methode'] ?? '') . ' ' . ($e['Route'] ?? ''));
            if ($e['CodeHttp'] !== null) $corps .= ' → ' . $e['CodeHttp'];
            if ($e['DureeMs'] !== null)  $corps .= ' (' . $e['DureeMs'] . ' ms)';
        }

        $ligne = sprintf(
            "%s  %-8s %-10s %-26s %s",
            $heure, $niveau, $canal, self::couper($qui, 28), $corps
        );

        // Lignes de détail, indentées sous l'évènement.
        $details = [];

        // Modifications : « champ : ancien → nouveau », le plus utile au
        // quotidien pour savoir qui a changé quoi.
        $avant = json_decode((string)$e['Avant'], true);
        $apres = json_decode((string)$e['Apres'], true);
        if (is_array($apres) || is_array($avant)) {
            $cles = array_unique(array_merge(
                is_array($avant) ? array_keys($avant) : [],
                is_array($apres) ? array_keys($apres) : []
            ));
            foreach (array_slice($cles, 0, 15) as $k) {
                $a = $avant[$k] ?? null;
                $b = $apres[$k] ?? null;
                $fa = self::valeurLisible($a);
                $fb = self::valeurLisible($b);
                if ($avant === null)      $details[] = "$k : $fb";
                elseif ($apres === null)  $details[] = "$k : $fa (supprimé)";
                else                      $details[] = "$k : $fa → $fb";
            }
        }

        // Exception : message + trois premiers niveaux de pile.
        $ctx = json_decode((string)$e['Contexte'], true);
        if (is_array($ctx) && isset($ctx['exception'])) {
            $x = $ctx['exception'];
            $details[] = ($x['type'] ?? 'Exception') . ' : ' . ($x['message'] ?? '')
                       . ' @ ' . ($x['fichier'] ?? '?') . ':' . ($x['ligne'] ?? '?');
            foreach (array_slice($x['pile'] ?? [], 0, 3) as $p) $details[] = '  ' . $p;
        } elseif (is_array($ctx) && $canal === 'interaction') {
            if (!empty($ctx['page']))   $details[] = 'page : ' . $ctx['page'];
            if (isset($ctx['valeur']) && $ctx['valeur'] !== null && $ctx['valeur'] !== '')
                $details[] = 'valeur : « ' . self::couper((string)$ctx['valeur'], 100) . ' »';
            if (!empty($ctx['chemin'])) $details[] = 'élément : ' . $ctx['chemin'];
        } elseif (is_array($ctx) && $canal === 'client') {
            // Erreur JavaScript : où et sur quelle page.
            if (!empty($ctx['source'])) $details[] = 'source : ' . $ctx['source']
                . (!empty($ctx['ligne']) ? ':' . $ctx['ligne'] : '');
            if (!empty($ctx['page']))   $details[] = 'page : ' . $ctx['page'];
        }

        $texte = $ligne . "\n";
        foreach ($details as $i => $d) {
            $texte .= sprintf("%s└─ %s\n", str_repeat(' ', 10), self::couper($d, 150));
        }

        $chemin = rtrim($dossier, '/') . '/activite-' . date('Y-m-d') . '.txt';
        // Pas de @ ici : si l'écriture échoue (dossier non inscriptible par
        // www-data, disque plein…), il FAUT une trace quelque part, sinon on
        // cherche pendant des heures pourquoi aucun fichier n'apparaît.
        // On la dépose dans le log PHP natif, qui lui fonctionne toujours.
        $ok = @file_put_contents($chemin, $texte, FILE_APPEND | LOCK_EX);
        if ($ok === false && !self::$ecritureSignalee) {
            self::$ecritureSignalee = true; // une seule fois par requête
            $qui = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                 ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                 : (get_current_user() ?: '?');
            self::secours(sprintf(
                "écriture impossible dans %s (utilisateur « %s », dossier %s). "
                . "Correction probable : chown -R %s %s && chmod 775 %s",
                $chemin, $qui,
                is_dir($dossier) ? (is_writable($dossier) ? 'inscriptible' : 'NON inscriptible') : 'INEXISTANT',
                $qui, $dossier, $dossier
            ));
        }
    }

    /** Évite de répéter l'alerte d'écriture à chaque entrée d'une même requête. */
    private static bool $ecritureSignalee = false;

    /** Valeur affichable dans le journal texte (guillemets, vide explicite). */
    private static function valeurLisible($v): string
    {
        if ($v === null || $v === '') return '(vide)';
        if (is_bool($v))  return $v ? 'oui' : 'non';
        if (is_array($v)) return self::couper(json_encode($v, JSON_UNESCAPED_UNICODE), 60);
        return '« ' . self::couper((string)$v, 60) . ' »';
    }

    /** Coupe sans dépendre de mbstring (voir tronquer()). */
    private static function couper(string $s, int $max): string
    {
        return self::tronquer($s, $max);
    }

    private static function ecrireFichier(array $e): void
    {
        $dossier = defined('LOG_PATH') ? LOG_PATH : (__DIR__ . '/../data/logs');
        if (!is_dir($dossier) && !@mkdir($dossier, 0755, true) && !is_dir($dossier)) return;

        // Un fichier par jour : la purge par date devient un simple unlink.
        $chemin = rtrim($dossier, '/') . '/journal-' . date('Y-m-d') . '.jsonl';
        $ligne  = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($ligne === false) return;
        @file_put_contents($chemin, $ligne . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Dernier recours : le log natif PHP, qui lui fonctionne toujours. */
    private static function secours(string $msg): void
    {
        @error_log('[Larka][Journal] ' . $msg);
    }

    private static function viderTampon(): void
    {
        if (!self::$tampon) return;
        $t = self::$tampon;
        self::$tampon = [];
        foreach ($t as $e) {
            try { self::ecrireBase($e); } catch (\Throwable $x) { /* perdu, tant pis */ }
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Table et rétention
    // ═════════════════════════════════════════════════════════════════════════

    private static function assurerTable(): void
    {
        $driver = self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ai = match ($driver) {
            'pgsql' => 'SERIAL PRIMARY KEY',
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $txt = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';

        self::$pdo->exec(
            "CREATE TABLE IF NOT EXISTS Journal (
                Id $ai,
                DateHeure TEXT, Niveau TEXT, Canal TEXT, Action TEXT, Message $txt,
                UtilisateurId INTEGER, Login TEXT, Role TEXT, Ip TEXT, UserAgent TEXT,
                Methode TEXT, Route TEXT, CodeHttp INTEGER, DureeMs INTEGER,
                EntiteType TEXT, EntiteId INTEGER,
                Avant $txt, Apres $txt, Contexte $txt, RequestId TEXT)"
        );
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_journal_date    ON Journal(DateHeure)',
            'CREATE INDEX IF NOT EXISTS idx_journal_niveau  ON Journal(Niveau)',
            'CREATE INDEX IF NOT EXISTS idx_journal_canal   ON Journal(Canal)',
            'CREATE INDEX IF NOT EXISTS idx_journal_login   ON Journal(Login)',
            'CREATE INDEX IF NOT EXISTS idx_journal_entite  ON Journal(EntiteType, EntiteId)',
            'CREATE INDEX IF NOT EXISTS idx_journal_request ON Journal(RequestId)',
        ] as $sql) {
            try { self::$pdo->exec($sql); } catch (\Throwable $e) { /* index optionnel */ }
        }
        self::$tablePrete = true;
    }

    /**
     * Purge selon LOG_ROTATE (en jours). Cette constante était définie dans
     * config.php mais n'était consommée nulle part — c'est ici qu'elle prend
     * enfin effet, sur la base ET sur les fichiers JSONL.
     *
     * Déclenchée au plus une fois par jour via un marqueur, pour ne pas
     * exécuter un DELETE à chaque requête.
     */
    private static function purgerSiNecessaire(): void
    {
        $jours = defined('LOG_ROTATE') ? (int)LOG_ROTATE : 14;
        if ($jours <= 0) return;

        $dossier  = defined('LOG_PATH') ? LOG_PATH : (__DIR__ . '/../data/logs');
        $marqueur = rtrim($dossier, '/') . '/.derniere-purge';
        if (is_file($marqueur) && trim((string)@file_get_contents($marqueur)) === date('Y-m-d')) return;
        @file_put_contents($marqueur, date('Y-m-d'));

        $limite = date('Y-m-d H:i:s', strtotime("-$jours days"));
        try {
            $st = self::$pdo->prepare("DELETE FROM Journal WHERE DateHeure < :limite");
            $st->execute(['limite' => $limite]);
            $n = $st->rowCount();
            if ($n > 0) {
                self::log(self::INFO, 'systeme', "Purge du journal : $n entrée(s) de plus de $jours jours supprimée(s).");
            }
        } catch (\Throwable $e) { /* purge best-effort */ }

        // Fichiers JSONL antérieurs à la fenêtre de rétention.
        foreach (['journal-*.jsonl', 'activite-*.txt'] as $motif) {
            foreach (glob(rtrim($dossier, '/') . '/' . $motif) ?: [] as $f) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})\.(jsonl|txt)$/', $f, $m)
                    && $m[1] < date('Y-m-d', strtotime("-$jours days"))) {
                    @unlink($f);
                }
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Utilitaires
    // ═════════════════════════════════════════════════════════════════════════

    private static function niveauActif(string $niveau): bool
    {
        if (defined('LOG_ERRORS') && !LOG_ERRORS) return false;
        $min = defined('LOG_NIVEAU') ? strtolower((string)LOG_NIVEAU) : 'info';
        // L'audit est une trace réglementaire : jamais filtré par le niveau.
        if ($niveau === self::AUDIT) return true;
        return (self::POIDS[$niveau] ?? 20) >= (self::POIDS[$min] ?? 20);
    }

    private static function encoder($v): ?string
    {
        if ($v === null || $v === []) return null;
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false) return null;
        return self::tronquer($j, self::MAX_CHAMP);
    }

    /**
     * Tronque une chaîne sans dépendre de mbstring.
     *
     * L'extension mbstring n'est pas garantie sur toutes les installations.
     * Sans ce repli, mb_strlen() lève une Error, l'exception est avalée par le
     * bloc défensif de log(), et TOUTE la journalisation devient silencieusement
     * inopérante — le pire des scénarios pour un journal.
     */
    private static function tronquer(string $s, int $max): string
    {
        if (function_exists('mb_strlen')) {
            if (mb_strlen($s, 'UTF-8') <= $max) return $s;
            return mb_substr($s, 0, $max - 3, 'UTF-8') . '...';
        }
        if (strlen($s) <= $max) return $s;
        // Découpe binaire, puis on recule tant qu'on est au milieu d'une
        // séquence UTF-8 multi-octets (octets de continuation 10xxxxxx).
        $c = substr($s, 0, $max - 3);
        while ($c !== '' && (ord($c[strlen($c) - 1]) & 0xC0) === 0x80) {
            $c = substr($c, 0, -1);
        }
        if ($c !== '' && (ord($c[strlen($c) - 1]) & 0x80)) {
            $c = substr($c, 0, -1); // octet de tête sans ses continuations
        }
        return $c . '...';
    }

    /** Chemin sans le répertoire d'installation, pour ne pas fuiter l'arborescence. */
    private static function cheminRelatif(string $f): string
    {
        $racine = dirname(__DIR__);
        return str_starts_with($f, $racine) ? ltrim(substr($f, strlen($racine)), '/') : basename($f);
    }

    /** Pile d'appels compacte : 12 niveaux max, chemins relatifs, sans arguments. */
    private static function pileCourte(\Throwable $e): array
    {
        $out = [];
        foreach (array_slice($e->getTrace(), 0, 12) as $t) {
            $out[] = sprintf(
                '%s%s%s() @ %s:%s',
                $t['class'] ?? '', $t['type'] ?? '', $t['function'] ?? '?',
                isset($t['file']) ? self::cheminRelatif($t['file']) : '?',
                $t['line'] ?? '?'
            );
        }
        return $out;
    }
}
