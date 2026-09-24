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
 * Larka — Extensions : lecture et validation du manifeste
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Chaque extension vit dans extensions/<identifiant>/ et s'annonce par un
 * fichier extension.json. Le manifeste est la seule source d'autorité sur ce
 * qu'une extension prétend faire : le Contexte n'accorde jamais rien qui n'y
 * soit écrit.
 *
 * Le manifeste est REFUSÉ, pas corrigé. Une extension dont le manifeste est
 * approximatif est une extension qu'on ne comprend pas ; l'installer serait
 * accepter une liste de permissions qui ne veut rien dire.
 *
 * Exemple minimal :
 * {
 *   "identifiant":  "acme.suivi-garanties",
 *   "nom":          "Suivi des garanties",
 *   "version":      "1.2.0",
 *   "auteur":       "ACME SARL <contact@acme.fr>",
 *   "description":  "Alerte 60 jours avant l'expiration des garanties.",
 *   "larka_min":    "1.0.0",
 *   "capacites":    ["donnees.lire:equipements", "ui.page", "journal.ecrire"],
 *   "domaines_reseau": [],
 *   "serveur":      "serveur/main.php",
 *   "client":       ["client/page.js"],
 *   "page":         { "cle": "garanties", "titre": "Garanties", "icone": "shield",
 *                     "roles": ["Gestionnaire", "Visionneur"] },
 *   "actions":      ["liste", "export"],
 *   "hooks":        ["intervention.apres_creation"]
 * }
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Capacites.php';
require_once __DIR__ . '/References.php';
require_once __DIR__ . '/Ancrages.php';
require_once __DIR__ . '/declaratif/Schema.php';

final class ExtManifeste
{
    /** identifiant : <vendeur>.<nom>, minuscules, tirets. Sert aussi de nom de dossier. */
    private const RE_IDENTIFIANT = '/^[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?\.[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?$/';
    private const RE_VERSION     = '/^\d{1,3}\.\d{1,3}\.\d{1,3}(-[a-z0-9.]{1,20})?$/';
    private const RE_ACTION      = '/^[a-z0-9_]{1,40}$/';
    private const RE_CLE_PAGE    = '/^[a-z0-9_]{1,30}$/';
    private const ROLES_CONNUS   = ['Gestionnaire', 'Admin', 'Visionneur', 'Demandeur'];

    public array $donnees;
    public string $dossier;

    private function __construct(array $donnees, string $dossier)
    {
        $this->donnees = $donnees;
        $this->dossier = $dossier;
    }

    /**
     * Lit et valide extensions/<id>/extension.json.
     * @throws RuntimeException si le manifeste est absent, illisible ou invalide.
     */
    public static function charger(string $dossier, ?string $nomAttendu = null): self
    {
        $fichier = $dossier . '/extension.json';
        if (!is_file($fichier)) {
            throw new RuntimeException('Manifeste extension.json introuvable.');
        }
        $brut = @file_get_contents($fichier);
        // 512 Ko. L'ancienne borne de 64 Ko était calibrée pour un module de
        // données — quelques jeux, quelques écrans. Une traduction complète du
        // logiciel fait 1800 libellés, soit près de 100 Ko : le pack le plus
        // utile était refusé par une limite qui ne le visait pas.
        //
        // La borne reste : elle protège d'un fichier aberrant, pas d'un
        // traducteur consciencieux.
        if ($brut === false || strlen($brut) > 2 * 1024 * 1024) {
            throw new RuntimeException('Manifeste illisible ou anormalement volumineux '
                . '(2 Mo au maximum).');
        }
        $m = json_decode($brut, true);
        if (!is_array($m)) {
            throw new RuntimeException('Manifeste JSON invalide : ' . json_last_error_msg());
        }

        // ── Références vers d'autres fichiers du paquet ──────────────────
        //
        // Résolues ICI, avant toute validation, et INLINÉES : ce qui est validé
        // puis stocké est un bloc unique. Les faire résoudre plus tard — au
        // moment de l'exécution, par exemple — rendrait le module installé
        // dépendant de fichiers annexes, et l'écran d'installation montrerait
        // une déclaration incomplète. On ne veut ni l'un ni l'autre.
        if (ExtReferences::contientReference($m)) {
            $m = ExtReferences::resoudre($m, ExtReferences::lecteurDossier($dossier));
        }

        $m = self::valider($m, $dossier, $nomAttendu ?? basename($dossier));
        return new self($m, $dossier);
    }

    /** Valide et normalise. Lève une RuntimeException au premier problème. */
    private static function valider(array $m, string $dossier, string $nomAttendu): array
    {
        $req = fn(string $k) => isset($m[$k]) && is_string($m[$k]) && $m[$k] !== ''
            ? $m[$k] : throw new RuntimeException("Champ obligatoire manquant ou vide : « $k ».");

        $id = $req('identifiant');
        if (!preg_match(self::RE_IDENTIFIANT, $id)) {
            throw new RuntimeException("Identifiant « $id » invalide. Format attendu : vendeur.nom "
                . '(minuscules, chiffres et tirets, ex. « acme.suivi-garanties »).');
        }
        // Le dossier DOIT porter le nom de l'identifiant : sans cela, deux extensions
        // pourraient revendiquer le même identifiant et se marcher dessus (stockage
        // privé, réglages, routes). C'est aussi ce qui rend le chemin prévisible.
        // Pour un paquet fraîchement déplié, le dossier temporaire porte un nom
        // aléatoire : l'appelant fournit alors l'identifiant lu dans le paquet,
        // et la règle continue de s'appliquer — les deux doivent concorder.
        if ($nomAttendu !== $id) {
            throw new RuntimeException("L'emplacement « $nomAttendu » ne correspond pas à "
                . "l'identifiant déclaré « $id ».");
        }

        $version = $req('version');
        if (!preg_match(self::RE_VERSION, $version)) {
            throw new RuntimeException("Version « $version » invalide (attendu : 1.2.3).");
        }

        $req('nom');
        $req('auteur');
        $req('description');

        foreach (['nom' => 80, 'auteur' => 120, 'description' => 500] as $k => $max) {
            if (mb_strlen($m[$k]) > $max) {
                throw new RuntimeException("Champ « $k » trop long (max $max caractères).");
            }
        }

        // ── Version minimale de Larka ────────────────────────────────────────
        $min = $m['larka_min'] ?? '1.0.0';
        if (!is_string($min) || !preg_match(self::RE_VERSION, $min)) {
            throw new RuntimeException('Champ « larka_min » invalide.');
        }
        if (defined('LARKA_VERSION') && version_compare(LARKA_VERSION, $min, '<')) {
            throw new RuntimeException("Cette extension exige Larka $min ou supérieur "
                . '(version installée : ' . LARKA_VERSION . ').');
        }

        // ── Module déclaratif ? ──────────────────────────────────────────────
        //
        // ── Le format déclaratif est désormais le SEUL ───────────────────
        //
        // Larka n'exécute plus de code fourni par un module. Un paquet
        // contenant du PHP ou du JavaScript est refusé à la réception : il n'y
        // a plus de processus isolé pour l'accueillir, plus d'analyseur pour le
        // relire, plus de bac à sable dans le navigateur.
        //
        // Ce qui disparaît avec eux : appels à un service extérieur, envois de
        // courriel, interactions sur mesure. Ces besoins relèvent désormais du
        // cœur de Larka, plus du format des modules.
        if (($m['format'] ?? '') !== ExtSchemaDeclaratif::FORMAT) {
            throw new RuntimeException('Seuls les modules déclaratifs sont acceptés. '
                . 'Ajoutez "format": "' . ExtSchemaDeclaratif::FORMAT . '" et décrivez vos '
                . 'données et vos écrans en JSON : Larka n\'exécute plus de code fourni '
                . 'par un module. Voir Documentations/MODULES-DECLARATIFS.md.');
        }

        // Un module déclaratif ne contient aucun code : ni point d'entrée, ni
        // fichier client, ni hook, ni ancrage. Ses capacités ne sont pas
        // déclarées mais DÉDUITES de ce qu'il décrit — il ne peut donc pas en
        // réclamer une dont sa déclaration n'a pas l'usage.
        if (($m['format'] ?? '') === ExtSchemaDeclaratif::FORMAT) {
            $m = ExtSchemaDeclaratif::valider($m);
            $m['declaratif'] = true;
            $m['capacites']  = ExtSchemaDeclaratif::capacitesRequises($m);
            // Actions IMPOSÉES par le moteur, jamais déclarées par l'auteur :
            // un module déclaratif n'invente pas de verbe, il utilise ceux que
            // Larka sait exécuter.
            $m['actions']    = ['dp_lister', 'dp_enregistrer', 'dp_supprimer',
                                'dp_exporter', 'dp_ancrage', 'dp_cibles',
                                'dp_reglages', 'dp_definir_reglages',
                                'dp_suggestions', 'dp_ajouter_valeur'];
            $m['hooks']      = [];
            $m['client']     = [];
            // ⚠️ Ne PAS vider $m['ancrages'] : le schéma vient de les valider.
            // Cette ligne existait quand un module de données ne pouvait pas
            // s'ancrer ; laissée en place, elle effaçait silencieusement les
            // primitives d'ancrage et le module perdait ses tuiles et ses
            // repères sans le moindre message.
            // La page annoncée au menu est la première déclarée. Un module
            // d'habillage n'en a aucune : lui en fabriquer une vide le faisait
            // échouer sur « page.cle invalide », alors qu'il n'a simplement
            // rien à afficher.
            if (!empty($m['pages'])) {
                // ⚠️ TOUTES LES PAGES, PAS SEULEMENT LA PREMIÈRE.
                //
                // Le manifeste ne retenait que « pages[0] ». Un module pouvait
                // donc déclarer deux écrans — un formulaire court pour le
                // demandeur, un planning complet pour le gestionnaire — et le
                // second n'existait nulle part : pas d'entrée de menu, pas de
                // route, aucun moyen de l'atteindre. La déclaration passait la
                // validation et la moitié disparaissait en silence.
                //
                // « page » au singulier est CONSERVÉ : les modules publiés
                // avant ce changement s'y réfèrent, et c'est la page d'entrée
                // du module — celle qu'on ouvre quand on ne précise rien.
                $resume = static fn(array $p): array => [
                    'cle' => $p['cle'], 'titre' => $p['titre'],
                    'icone' => $p['icone'], 'roles' => $p['roles'],
                ];
                $m['page']      = $resume($m['pages'][0]);
                $m['pages_menu'] = array_map($resume, $m['pages']);
            }
        }

        // ── Version de l'API d'extensions ────────────────────────────────────
        //
        // CE CONTRÔLE PASSE AVANT CELUI DES CAPACITÉS, et ce n'est pas un
        // détail d'ordre. Une extension écrite pour une API plus récente
        // déclare forcément des capacités que cette version ignore : sans ce
        // contrôle, l'administrateur reçoit « capacité inconnue, faute de
        // frappe ? » alors que son manifeste est parfaitement correct et que
        // le vrai problème est un Larka à mettre à jour. Le diagnostic doit
        // désigner la cause, pas le premier symptôme rencontré.
        $apiMin = $m['api_min'] ?? null;
        if ($apiMin !== null) {
            if (!is_string($apiMin) || !preg_match('/^\d{1,3}\.\d{1,3}$/', $apiMin)) {
                throw new RuntimeException('Champ « api_min » invalide (attendu : 1.1).');
            }
            if (version_compare($apiMin, ExtCapacites::VERSION_API, '>')) {
                throw new RuntimeException(
                    'Cette extension exige l\'API d\'extensions ' . $apiMin
                    . ', or ce serveur fournit la version ' . ExtCapacites::VERSION_API . '. '
                    . 'Mettez Larka à jour, ou installez une version de l\'extension prévue '
                    . 'pour votre installation.');
            }
        }

        // ── Capacités ────────────────────────────────────────────────────────
        $caps = $m['capacites'] ?? [];
        if (!is_array($caps)) throw new RuntimeException('Champ « capacites » : tableau attendu.');
        $caps = array_values(array_unique(array_filter($caps, 'is_string')));
        if (count($caps) > 120) throw new RuntimeException('Trop de capacités demandées (max 120).');
        foreach ($caps as $c) {
            if (!ExtCapacites::existe($c)) {
                throw new RuntimeException(self::messageInconnu('capacité', $c,
                    array_keys(ExtCapacites::catalogue()), true));
            }
        }
        $m['capacites'] = $caps;

        // ── Domaines réseau : obligatoires dès que reseau.sortant est demandé ──
        $dom = $m['domaines_reseau'] ?? [];
        if (!is_array($dom)) throw new RuntimeException('Champ « domaines_reseau » : tableau attendu.');
        $dom = array_values(array_filter($dom, 'is_string'));
        foreach ($dom as $d) {
            // On veut un nom d'hôte nu : ni schéma, ni chemin, ni joker.
            if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]{0,251}[a-z0-9])?$/i', $d) || !str_contains($d, '.')) {
                throw new RuntimeException("Domaine réseau « $d » invalide : indiquez un nom d'hôte seul "
                    . "(ex. « api.exemple.fr »), sans https:// ni chemin ni joker.");
            }
        }
        if (in_array('reseau.sortant', $caps, true) && $dom === []) {
            throw new RuntimeException('La capacité « reseau.sortant » exige la liste des domaines '
                . 'contactés dans « domaines_reseau ». Une sortie réseau sans destination connue est refusée.');
        }
        if ($dom !== [] && !in_array('reseau.sortant', $caps, true)) {
            throw new RuntimeException('Des domaines réseau sont déclarés sans la capacité « reseau.sortant ».');
        }
        $m['domaines_reseau'] = array_map('strtolower', $dom);

        // ── Point d'entrée serveur ───────────────────────────────────────────
        if (isset($m['serveur'])) {
            $m['serveur'] = self::cheminInterne($m['serveur'], $dossier, 'serveur');
        }

        // ── Fichiers client ──────────────────────────────────────────────────
        $client = $m['client'] ?? [];
        if (!is_array($client)) throw new RuntimeException('Champ « client » : tableau attendu.');
        if (count($client) > 20) throw new RuntimeException('Trop de fichiers client (max 20).');
        foreach ($client as $i => $f) {
            $chemin = self::cheminInterne($f, $dossier, "client[$i]");
            if (!preg_match('/\.(js|css)$/', $chemin)) {
                throw new RuntimeException("Fichier client « $f » : seuls .js et .css sont servis.");
            }
            $client[$i] = $chemin;
        }
        $m['client'] = array_values($client);

        // ── Page ─────────────────────────────────────────────────────────────
        if (isset($m['page'])) {
            $p = $m['page'];
            if (!is_array($p)) throw new RuntimeException('Champ « page » : objet attendu.');
            if (!isset($p['cle']) || !is_string($p['cle']) || !preg_match(self::RE_CLE_PAGE, $p['cle'])) {
                throw new RuntimeException('Champ « page.cle » invalide (minuscules, chiffres, underscore).');
            }
            if (!isset($p['titre']) || !is_string($p['titre']) || mb_strlen($p['titre']) > 40) {
                throw new RuntimeException('Champ « page.titre » manquant ou trop long (max 40).');
            }
            $roles = $p['roles'] ?? ['Gestionnaire'];
            if (!is_array($roles) || $roles === []) {
                throw new RuntimeException('Champ « page.roles » : liste non vide attendue.');
            }
            foreach ($roles as $r) {
                if (!in_array($r, self::ROLES_CONNUS, true)) {
                    throw new RuntimeException("Rôle inconnu dans « page.roles » : « $r ».");
                }
            }
            $p['roles'] = array_values($roles);
            $p['icone'] = (isset($p['icone']) && is_string($p['icone']))
                ? preg_replace('/[^a-z0-9\-]/', '', strtolower($p['icone'])) : 'puzzle';
            $m['page'] = $p;

            if (!in_array('ui.page', $caps, true)) {
                throw new RuntimeException('Une page est déclarée sans la capacité « ui.page ».');
            }
        }

        // ── Actions serveur exposées ─────────────────────────────────────────
        $actions = $m['actions'] ?? [];
        if (!is_array($actions)) throw new RuntimeException('Champ « actions » : tableau attendu.');
        if (count($actions) > 120) throw new RuntimeException('Trop d\'actions déclarées (max 120).');
        foreach ($actions as $a) {
            if (!is_string($a) || !preg_match(self::RE_ACTION, $a)) {
                throw new RuntimeException("Nom d'action invalide : « " . (is_string($a) ? $a : '?') . " ».");
            }
        }
        $m['actions'] = array_values(array_unique($actions));
        // Un module déclaratif a des actions sans point d'entrée : elles sont
        // servies par le moteur de Larka, pas par du code du module.
        if ($m['actions'] !== [] && empty($m['serveur']) && empty($m['declaratif'])) {
            throw new RuntimeException('Des actions sont déclarées sans point d\'entrée « serveur ».');
        }

        // ── Ancrages dans les pages du cœur ──────────────────────────────────
        //
        // Deux formes, selon la nature du module :
        //   module à code       une liste de NOMS ; l'auteur fournit le
        //                       JavaScript qui dessinera.
        //   module déclaratif   une liste d'OBJETS déjà validés par le schéma ;
        //                       c'est Larka qui dessine, l'auteur ne fait que
        //                       paramétrer une primitive.
        // Les valider ici comme des noms rejetterait les seconds — c'est
        // exactement ce qui se produisait.
        $anc = $m['ancrages'] ?? [];
        if (!is_array($anc)) throw new RuntimeException('Champ « ancrages » : tableau attendu.');

        if (!empty($m['declaratif'])) {
            foreach ($anc as $a) {
                if (!is_array($a) || !ExtAncrages::existe((string)($a['emplacement'] ?? ''))) {
                    throw new RuntimeException(self::messageInconnu("emplacement d'ancrage",
                        is_array($a) ? (string)($a['emplacement'] ?? '?') : '?',
                        array_keys(ExtAncrages::catalogue())));
                }
            }
            $m['ancrages'] = array_values($anc);
        } else {
            foreach ($anc as $a) {
                if (!is_string($a) || !ExtAncrages::existe($a)) {
                    throw new RuntimeException(self::messageInconnu("point d'ancrage",
                        is_string($a) ? $a : '?', array_keys(ExtAncrages::catalogue())));
                }
            }
            $m['ancrages'] = array_values(array_unique($anc));
        }
        if ($m['ancrages'] !== [] && !in_array('ui.ancrage', $caps, true)) {
            throw new RuntimeException('Des ancrages sont déclarés sans la capacité « ui.ancrage ».');
        }

        $m['hooks'] = [];


        return $m;
    }

    /**
     * Message d'erreur pour un identifiant inconnu.
     *
     * On distingue les deux causes plutôt que de les mélanger :
     *   - une faute de frappe → on propose l'entrée la plus proche ;
     *   - une extension trop récente → on rappelle la version d'API du serveur.
     * Dans les deux cas on indique où lire la liste exacte, au lieu de laisser
     * chercher dans le code.
     */
    private static function messageInconnu(string $genre, string $valeur, array $connus,
                                           bool $feminin = false): string
    {
        $msg = ucfirst($genre) . ($feminin ? ' inconnue' : ' inconnu') . " : « $valeur ». ";

        // Deux façons de se tromper, deux façons de retrouver l'entrée voulue :
        //
        //   - la faute de frappe  → distance d'édition faible ;
        //   - le nom TRONQUÉ      → « stock.seuil » pour « stock.seuil_atteint ».
        //
        // Le second cas est fréquent (on écrit de mémoire, on abrège) et la
        // distance d'édition l'ignore : 8 caractères d'écart, aucune suggestion.
        // On teste donc d'abord le préfixe, qui est le signal le plus net.
        $meilleur = null;
        foreach ($connus as $k) {
            $k = (string)$k;
            if ($valeur !== '' && $k !== $valeur
                && (str_starts_with($k, $valeur) || str_starts_with($valeur, $k))) {
                $meilleur = $k;
                break;
            }
        }
        if ($meilleur === null) {
            $distance = PHP_INT_MAX;
            foreach ($connus as $k) {
                $d = levenshtein($valeur, (string)$k);
                if ($d < $distance) { $distance = $d; $meilleur = $k; }
            }
            if ($distance === 0 || $distance > max(3, (int)(mb_strlen($valeur) / 4))) {
                $meilleur = null;
            }
        }

        if ($meilleur !== null) {
            $msg .= "Vouliez-vous dire « $meilleur » ? ";
        } else {
            $msg .= 'Ce serveur fournit l\'API d\'extensions ' . ExtCapacites::VERSION_API
                  . ' : si l\'extension a été écrite pour une version plus récente, '
                  . 'déclarez « api_min » dans son manifeste et mettez Larka à jour. ';
        }
        $msg .= 'Liste exacte : ?action=ext_catalogue';
        return $msg;
    }

    /**
     * Résout un chemin déclaré dans le manifeste et vérifie qu'il reste DANS le
     * dossier de l'extension. C'est le garde-fou contre « ../../api/config.php ».
     */
    private static function cheminInterne(mixed $relatif, string $dossier, string $champ): string
    {
        if (!is_string($relatif) || $relatif === '') {
            throw new RuntimeException("Champ « $champ » : chemin attendu.");
        }
        if (str_contains($relatif, "\0") || preg_match('#(^/)|(^[a-z]:)|(\.\.)#i', $relatif)) {
            throw new RuntimeException("Champ « $champ » : chemin « $relatif » refusé "
                . '(chemin absolu ou remontée de dossier).');
        }
        $abs  = realpath($dossier . '/' . $relatif);
        $base = realpath($dossier);
        if ($abs === false || $base === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Champ « $champ » : fichier « $relatif » introuvable "
                . 'ou hors du dossier de l\'extension.');
        }
        return $relatif;
    }

    // ── Accès confort ────────────────────────────────────────────────────────
    public function id(): string           { return $this->donnees['identifiant']; }
    public function nom(): string          { return $this->donnees['nom']; }
    public function version(): string      { return $this->donnees['version']; }
    public function capacites(): array     { return $this->donnees['capacites']; }
    public function domainesReseau(): array{ return $this->donnees['domaines_reseau'] ?? []; }
    public function actions(): array       { return $this->donnees['actions'] ?? []; }
    public function hooks(): array         { return $this->donnees['hooks'] ?? []; }
    public function ancrages(): array      { return $this->donnees['ancrages'] ?? []; }
    public function apiMin(): string       { return $this->donnees['api_min'] ?? '1.0'; }
    public function estDeclaratif(): bool  { return !empty($this->donnees['declaratif']); }
    public function declaration(): array   { return $this->donnees; }
    public function page(): ?array         { return $this->donnees['page'] ?? null; }
    public function client(): array        { return $this->donnees['client'] ?? []; }
    public function aCapacite(string $c): bool
    {
        return in_array($c, $this->donnees['capacites'], true);
    }

    /**
     * Empreinte de tous les fichiers de l'extension. Recalculée à chaque
     * vérification : si elle bouge alors que l'extension est active, c'est que
     * le code a été modifié après validation — l'extension est désactivée et
     * l'événement journalisé.
     */
    public function empreinte(): string
    {
        $h = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dossier, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile()) continue;
            $rel = substr($f->getPathname(), strlen($this->dossier) + 1);
            $h[str_replace('\\', '/', $rel)] = hash_file('sha256', $f->getPathname());
        }
        ksort($h);
        return hash('sha256', json_encode($h));
    }
}
