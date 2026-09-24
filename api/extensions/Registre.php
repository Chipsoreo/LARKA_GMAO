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
 * Larka — Extensions : registre (découverte, état, exécution)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Point d'entrée unique de la couche. Le cœur ne connaît que cette classe.
 *
 * ÉTAT PERSISTÉ — data/extensions/etat.json
 *   Pour chaque extension : active ou non, capacités acceptées par
 *   l'administrateur, empreinte du code au moment de la validation, mode
 *   d'exécution, compteur d'échecs, date d'installation.
 *   Les capacités RÉELLEMENT accordées sont celles de ce fichier, pas celles du
 *   manifeste : si une mise à jour de l'extension en réclame de nouvelles, elle
 *   ne démarre pas tant que l'administrateur n'a pas revu la liste.
 *
 * TROIS SÉCURITÉS AUTOMATIQUES
 *   1. Empreinte : le code est haché à l'installation. À chaque chargement,
 *      on recompare. Code modifié après validation → désactivation immédiate.
 *   2. Compteur d'échecs : trois erreurs → suspension automatique. Une
 *      extension qui plante en boucle ne dégrade pas l'application.
 *   3. Budget de temps : au-delà du seuil, l'extension est signalée puis
 *      suspendue. PHP ne sait pas interrompre du code en cours dans le même
 *      processus — c'est mesuré après coup, et c'est une raison de plus de
 *      préférer le mode isolé.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Capacites.php';
require_once __DIR__ . '/Ancrages.php';
require_once __DIR__ . '/Manifeste.php';
require_once __DIR__ . '/Paquet.php';
require_once __DIR__ . '/securite/Securite.php';
require_once __DIR__ . '/declaratif/Moteur.php';
// Amorçage des nomenclatures au chargement. Disponible jusqu'ici par la chaîne
// du moteur, mais une dépendance qu'on utilise se déclare : le jour où le
// moteur cesse d'inclure Config.php, l'amorçage tomberait sans prévenir.
require_once __DIR__ . '/Config.php';

final class ExtRegistre
{

    private const MAX_ECHECS      = 3;
    private const BUDGET_MS       = 2000;   // au-delà : signalement
    private const BUDGET_MS_DUR   = 8000;   // au-delà : suspension

    private static ?self $instance = null;

    private $db;
    private array $utilisateur = [];
    private string $racine;
    private ?array $etat = null;
    private ?bool $conditionsSecurite = null;  // cache : contrôle préalable de sécurité
    private array $chargees = [];           // id → ['manifeste'=>…, 'handlers'=>…]

    private function __construct($db = null, array $utilisateur = [])
    {
        $this->db = $db;
        $this->utilisateur = $utilisateur;
        // ⚠️ CHANGEMENT IMPORTANT : le code des extensions vit désormais dans
        // data/extensions/code/, HORS de la racine web. Il n'existe donc plus
        // aucune URL menant à un .php d'extension — le risque d'appel direct
        // sans authentification disparaît par construction, au lieu de dépendre
        // d'une règle nginx qu'on peut oublier de déployer.
        $this->racine = ExtPaquet::dossierCode();
    }

    public static function initialiser($db, array $utilisateur = []): self
    {
        self::$instance = new self($db, $utilisateur);
        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(null, []);
    }

    public function definirUtilisateur(array $u): void { $this->utilisateur = $u; }

    // ═══════════════════════════════════════════════════════════════════════
    //  Mode d'exécution
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Mode courant, lu dans config.json → extensions.mode.
     *
     * Le mode « développement » est REFUSÉ quand APP_ENV vaut prod : c'est le
     * garde-fou contre l'oubli. On désactive la protection le temps d'écrire une
     * extension, on oublie de la remettre, et la config part en production —
     * ici, elle retombe automatiquement en standard, et le journal le dit.
     */

    public function actif(): bool
    {
        return function_exists('cfg') ? (bool)(cfg('extensions', 'actif') ?? false) : false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  État persisté
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * État d'installation, PROPRE AU CLIENT COURANT.
     *
     * C'était un fichier unique : ce qu'un client installait, tous le voyaient.
     * Il vit désormais sous data/extensions/tenants/<client>/, comme le code
     * et les paquets.
     */
    private function fichierEtat(): string
    {
        return ExtPaquet::dossierEtat() . '/etat.json';
    }

    private function etat(): array
    {
        if ($this->etat === null) {
            $f = $this->fichierEtat();
            $this->etat = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        }
        return $this->etat;
    }

    private function ecrireEtat(array $etat): void
    {
        $this->etat = $etat;
        file_put_contents($this->fichierEtat(),
            json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function majEtat(string $id, array $modifs): void
    {
        $e = $this->etat();
        $e[$id] = array_merge($e[$id] ?? [], $modifs);
        $this->ecrireEtat($e);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Découverte et inventaire
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Liste tout ce qui se trouve dans extensions/, installé ou non, avec son
     * état et le motif s'il y a lieu. C'est ce que voit l'écran d'administration.
     */
    public function inventaire(): array
    {
        $out = [];
        if (!is_dir($this->racine)) return $out;
        $etat = $this->etat();

        foreach (scandir($this->racine) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') continue;
            $dossier = $this->racine . '/' . $entree;
            if (!is_dir($dossier)) continue;

            $ligne = ['identifiant' => $entree, 'installee' => isset($etat[$entree])];
            try {
                $man = ExtManifeste::charger($dossier);
                $e = $etat[$entree] ?? [];
                $ligne += [
                    'nom'         => $man->nom(),
                    'version'     => $man->version(),
                    'auteur'      => $man->donnees['auteur'],
                    'description' => $man->donnees['description'],
                    'capacites'   => $man->capacites(),
                    'risque'      => ExtCapacites::risqueGlobal($man->capacites()),
                    'page'        => $man->page(),
                    'hooks'       => $man->hooks(),
                    'actions'     => $man->actions(),
                    'active'      => (bool)($e['active'] ?? false),
                    'suspendue'   => $e['suspendue'] ?? null,
                    'echecs'      => (int)($e['echecs'] ?? 0),
                    'installe_le' => $e['installe_le'] ?? null,
                'logo'        => $man->donnees['logo'] ?? null,
                    // Affectée au tenant courant ? Une extension peut être
                    // active côté serveur sans l'être pour ce client-ci.
                    'affectee_tenant' => $this->autoriseePourTenant($entree),
                    // Qui voit ce module aujourd'hui, et parmi quoi choisir.
                    // L'écran doit montrer les deux : sans les rôles possibles,
                    // on ne sait pas si « Demandeur » est absent parce qu'on l'a
                    // retiré ou parce que le module ne sait pas le servir.
                    'roles_effectifs'  => $this->rolesAccordes($entree, $man),
                    'roles_optionnels' => array_values(array_intersect(
                        $man->page()['roles'] ?? [], self::ROLES_OPTIONNELS)),
                    'valide'      => true,
                    'erreur'      => null,
                ];
                // Capacités réclamées depuis la validation : mise à jour à revoir.
                $accordees = $e['capacites_accordees'] ?? [];
                $ligne['capacites_nouvelles'] = array_values(array_diff($man->capacites(), $accordees));
                $ligne['code_modifie'] = isset($e['empreinte']) && $e['empreinte'] !== $man->empreinte();
            } catch (\Throwable $ex) {
                $msg = $ex->getMessage();
                $ligne += [
                    'valide' => false, 'erreur' => $msg,
                    'active' => false, 'nom' => $entree, 'risque' => 'critique',
                    // Distinguer « incompatible » de « cassée » : dans le
                    // premier cas il n'y a rien à corriger dans l'extension,
                    // c'est Larka qu'il faut mettre à jour.
                    'incompatible' => str_contains($msg, "API d'extensions"),
                ];
            }
            $out[] = $ligne;
        }
        usort($out, fn($a, $b) => strcmp($a['identifiant'], $b['identifiant']));
        return $out;
    }

    /**
     * Rapport d'installation : ce que l'administrateur doit lire AVANT
     * d'accepter. Manifeste + capacités en clair + analyse statique.
     */
    public function examiner(string $id): array
    {
        return $this->examinerDossier($this->dossierDe($id), $id);
    }

    /**
     * Même rapport, sur un dossier quelconque — typiquement le dépliage
     * temporaire d'un paquet .larka qui vient d'être reçu et que personne n'a
     * encore accepté. C'est ce qui permet d'examiner AVANT d'installer, plutôt
     * que d'installer puis de regarder.
     */
    public function examinerDossier(string $dossier, string $id): array
    {
        // Un dépliage temporaire porte un nom aléatoire : on transmet
        // l'identifiant lu dans le paquet, et le manifeste vérifie qu'il
        // concorde. La règle « un emplacement, une identité » reste appliquée.
        $man = ExtManifeste::charger($dossier, $id);
        {
            // Plus d'analyse statique : un paquet ne contient plus de code à
            // relire. Plus de modes non plus — ils décrivaient l'isolement de
            // ce code. Ce qui reste vérifié tient dans le manifeste, et il l'a
            // déjà été par ExtManifeste::charger() ci-dessus.
            $analyse = ['verdict' => 'note', 'bloquants' => [], 'avertissements' => [],
                        'fichiers' => 0, 'lignes' => 0,
                        'remarques' => ['Pack de données : aucun code à analyser.']];
            $bloquantsMode = [];

            return [
                'identifiant'  => $man->id(),
                'nom'          => $man->nom(),
                'version'      => $man->version(),
                'auteur'       => $man->donnees['auteur'],
                'description'  => $man->donnees['description'],
                'logo'         => $man->donnees['logo'] ?? null,
                'empreinte'    => $man->empreinte(),
                // Capacités DÉJÀ accordées : sans elles, l'écran rouvre toutes
                // les cases décochées sur un module installé, laissant croire
                // que rien n'a été enregistré. L'administrateur recoche, et se
                // demande à juste titre si son geste précédent a servi.
                // L'état est indexé DIRECTEMENT par identifiant : il n'y a pas
                // de niveau « installees ». Lire une clé absente renvoyait un
                // tableau vide sans erreur — les cases repartaient décochées et
                // le module semblait n'avoir jamais été installé.
                'deja_accordees' => array_values(
                    ($this->etat()[$id]['capacites_accordees'] ?? [])),
                'deja_installee'  => isset($this->etat()[$id]),
                'capacites'    => ExtCapacites::pourAffichage($man->capacites(),
                                      !empty($man->donnees['declaratif'])),
                'risque'       => ExtCapacites::risqueGlobal($man->capacites(),
                                      !empty($man->donnees['declaratif'])),
                'domaines_reseau' => $man->domainesReseau(),
                'hooks'        => array_map(fn($h) => ['nom' => $h, 'libelle' => ExtHooks::libelle($h)],
                                            $man->hooks()),
                // Deux formes d'ancrage à présenter à l'administrateur : le nom
                // seul (module à code) ou l'objet paramétrant une primitive
                // (module déclaratif). Dans le second cas on précise ce que
                // Larka dessinera — c'est plus parlant que l'emplacement seul.
                'ancrages'     => array_map(function ($a) {
                    $nom = is_array($a) ? (string)($a['emplacement'] ?? '') : (string)$a;
                    $libelle = ExtAncrages::libelle($nom);
                    if (is_array($a)) {
                        $libelle .= ' — ' . $a['type'] . ' « ' . ($a['libelle'] ?? '') . ' », '
                                  . 'dessiné par Larka';
                    }
                    return ['nom' => $nom, 'libelle' => $libelle,
                            'page' => ExtAncrages::meta($nom)['page'] ?? '',
                            'declaratif' => is_array($a)];
                }, $man->ancrages()),
                // L'écran d'installation doit savoir à quoi il a affaire : les
                // avertissements sur le code n'ont aucun sens pour un pack de
                // données, et les afficher quand même apprend à l'administrateur
                // à ne plus les lire.
                'declaratif'   => !empty($man->donnees['declaratif']),
                'format'       => !empty($man->donnees['declaratif']) ? 'declaratif' : 'code',
                'resume_declaration' => !empty($man->donnees['declaratif'])
                    ? self::resumerDeclaration($man->declaration()) : null,
                'analyse'      => $analyse,
                'bloquants_mode' => $bloquantsMode,
                'installable'  => true,   // le manifeste a déjà tout validé
                'avertissement' => $this->texteAvertissement($man, $analyse),
            ];
        }
    }

    /**
     * Le texte affiché en tête de l'écran d'installation. Il dit la vérité sur
     * ce que Larka garantit et ne garantit pas — c'est le point le plus
     * important de tout l'écran.
     */
    /**
     * Phrase d'accueil de l'écran d'installation.
     *
     * Elle avertissait autrefois qu'un code tiers allait s'exécuter, et
     * détaillait le mode d'isolement. Ces deux choses ont disparu : ce que le
     * module va faire est désormais énuméré par resumerDeclaration(), en
     * français et sans conditionnel.
     */
    private function texteAvertissement(ExtManifeste $man, array $analyse): string
    {
        return 'Ce module est un module de données : il décrit ce qu\'il ajoute, '
             . 'et c\'est Larka qui l\'affiche. Aucun code fourni par son auteur '
             . 'ne sera exécuté. Vérifiez ci-dessous ce qu\'il va créer.';
    }

    /**
     * Installe (ou met à jour) une extension : enregistre les capacités
     * explicitement acceptées et l'empreinte du code validé.
     *
     * @param array $capacitesAcceptees doit couvrir exactement le manifeste.
     */
    /** Rôles qu'un module peut viser. Admin et Gestionnaire ne sont pas retirables. */
    public const ROLES_OPTIONNELS = ['Visionneur', 'Demandeur'];

    /**
     * Actions qui CONFIGURENT le module plutôt que de s'en servir.
     * Réservées au gestionnaire, quel que soit le chemin emprunté pour les
     * atteindre. Voir executerAction().
     */
    public const ACTIONS_ADMINISTRATION = ['dp_definir_reglages'];

    /**
     * Quels rôles voient ce module — décision de l'ADMINISTRATEUR, pas de l'auteur.
     *
     * Un module déclare les rôles qu'il vise ; c'était jusqu'ici la fin de
     * l'histoire. Or la sensibilité d'un module ne dépend pas de son code mais
     * de ce qu'on y met : un habillage peut s'ouvrir à tout le monde, un
     * registre d'habilitations nominatives non — et l'auteur du module, qui ne
     * connaît ni l'entreprise ni ses usages, n'est pas en position de trancher.
     *
     * La déclaration devient donc une PROPOSITION : elle sert de valeur par
     * défaut à l'installation, et l'administrateur la restreint ou l'élargit.
     *
     * Admin et Gestionnaire ne sont pas retirables : ce sont eux qui installent
     * et configurent. Leur permettre de se retirer l'accès produirait un module
     * installé que plus personne ne peut ouvrir — y compris pour le désinstaller.
     *
     * ⚠️ CE QUI EST RENDU ICI EST APPLIQUÉ CÔTÉ SERVEUR, aux trois endroits qui
     * décident : l'exécution d'une action, l'envoi du manifeste au client, et
     * l'affichage des ancrages. Masquer une entrée de menu sans refuser l'appel
     * serait un décor.
     */
    public function rolesAccordes(string $id, ExtManifeste $man): array
    {
        $declares = $man->page()['roles'] ?? ['Gestionnaire'];
        $etat = $this->etat()[$id] ?? [];

        // Absent = jamais réglé : la déclaration fait foi. Un tableau vide, lui,
        // est une DÉCISION — « aucun rôle optionnel » — et doit être respectée.
        if (!array_key_exists('roles_accordes', $etat)
            || !is_array($etat['roles_accordes'])) {
            return $declares;
        }

        $accordes = array_values(array_intersect(
            array_map('strval', $etat['roles_accordes']), self::ROLES_OPTIONNELS));

        // On n'accorde jamais un rôle que le module ne sait pas servir : sa page
        // n'a pas été pensée pour lui, et l'ouvrir donnerait un écran bancal.
        $accordes = array_values(array_intersect($accordes, $declares));

        return array_values(array_unique(array_merge(
            array_intersect($declares, ['Gestionnaire', 'Admin']), $accordes)));
    }

    /**
     * Ce rôle peut-il OUVRIR ce module ?
     *
     * Même règle que pour l'exécution d'une action : le gestionnaire et
     * l'administrateur passent — ce sont eux qui installent —, les autres
     * doivent figurer dans les rôles accordés.
     *
     * Extrait pour être appliqué AILLEURS QUE dans executerAction() : les
     * routes de fichiers d'un module ne vérifiaient que son activité, jamais le
     * rôle de l'appelant. Un module réservé au gestionnaire livrait donc ses
     * documents à n'importe quel compte authentifié — le contrôle existait, il
     * n'était simplement pas appelé là.
     */
    public function visiblePour(string $id, array $utilisateur = []): bool
    {
        $actives = $this->actives();
        if (!isset($actives[$id])) return false;

        $role = (string)(($utilisateur ?: $this->utilisateur)['Role'] ?? '');
        if (in_array($role, ['Gestionnaire', 'Admin'], true)) return true;

        return in_array($role, $this->rolesAccordes($id, $actives[$id]), true);
    }

    /** Modifie les rôles accordés à un module déjà installé. */
    public function definirRoles(string $id, array $roles, string $parQui): array
    {
        $actives = $this->actives();
        if (!isset($actives[$id])) {
            throw new RuntimeException("Module « $id » inconnu ou inactif.");
        }
        $man = $actives[$id];
        $declares = $man->page()['roles'] ?? ['Gestionnaire'];

        $propres = array_values(array_intersect(
            array_intersect(array_map('strval', $roles), self::ROLES_OPTIONNELS),
            $declares));

        $this->majEtat($id, ['roles_accordes' => $propres]);
        $this->tracer('audit', "Extension « $id » : rôles accordés = "
            . (implode(', ', $propres) ?: 'aucun rôle optionnel') . " (par $parQui).");

        return $this->rolesAccordes($id, $man);
    }

    public function installer(string $id, array $capacitesAcceptees, string $parQui,
                              ?array $rolesChoisis = null): array
    {
        $dossier = $this->dossierDe($id);
        $man = ExtManifeste::charger($dossier);
        $rapport = $this->examiner($id);

        if (!$rapport['installable']) {
            throw new RuntimeException('Installation refusée : '
                . ($rapport['bloquants_mode'][0]
                   ?? ($rapport['analyse']['bloquants'][0]['message'] ?? 'analyse bloquante')));
        }

        // L'administrateur doit accepter TOUTES les capacités demandées : on
        // n'installe pas « à moitié », sinon l'extension échouerait plus tard
        // sur une capacité manquante, de façon incompréhensible.
        $manquantes = array_diff($man->capacites(), $capacitesAcceptees);
        if ($manquantes !== []) {
            throw new RuntimeException('Capacités non acceptées : ' . implode(', ', $manquantes)
                . '. L\'extension ne peut pas fonctionner sans elles.');
        }
        $enTrop = array_diff($capacitesAcceptees, $man->capacites());
        if ($enTrop !== []) {
            throw new RuntimeException('Capacités acceptées qui ne figurent pas au manifeste : '
                . implode(', ', $enTrop) . '.');
        }

        // ── Cloisonnement des tables : un espace, un module ──────────────
        // Le préfixe ext_<identifiant>_ encode le propriétaire, mais il replie
        // « . » et « - » sur « _ » : deux identifiants voisins peuvent donc
        // désigner le même espace, ou l'un contenir l'autre. Voir
        // ExtPolitiqueDonnees::espacesEnConflit(). C'est ici, et seulement ici,
        // qu'un nouvel espace apparaît : c'est donc ici qu'on refuse.
        foreach (array_keys($this->etat()) as $installe) {
            if (!ExtPolitiqueDonnees::espacesEnConflit($man->id(), (string)$installe)) continue;
            throw new RuntimeException('Installation refusée : l\'identifiant « ' . $man->id()
                . ' » partage son espace de tables ('
                . ExtPolitiqueDonnees::prefixeExtension($man->id()) . ') avec le module déjà '
                . 'installé « ' . $installe . ' ». Deux modules ne peuvent pas se répondre du '
                . 'même préfixe : choisissez un identifiant qui ne soit ni le début de l\'autre, '
                . 'ni le même une fois les points et les tirets ramenés à « _ ».');
        }

        // Rôles retenus par l'administrateur. null = il n'a rien dit : on prend
        // ce que le module propose. Un tableau vide, lui, est une décision —
        // « aucun rôle optionnel » — et se distingue du silence.
        $declares = $man->page()['roles'] ?? ['Gestionnaire'];
        $rolesAccordes = $rolesChoisis === null
            ? array_values(array_intersect($declares, self::ROLES_OPTIONNELS))
            : array_values(array_intersect(
                array_intersect(array_map('strval', $rolesChoisis), self::ROLES_OPTIONNELS),
                $declares));

        $this->majEtat($man->id(), [
            'active'              => true,
            'capacites_accordees' => array_values($man->capacites()),
            'roles_accordes'      => $rolesAccordes,
            'empreinte'           => $man->empreinte(),
            'version'             => $man->version(),
            // Trace du mode en vigueur à l'installation. NE SERT PAS à
            // l'exécution : voir executer(), où le mode global fait autorité.
            'installe_le'         => date('c'),
            'installe_par'        => $parQui,
            'echecs'              => 0,
            'suspendue'           => null,
        ]);

        if ($man->estDeclaratif()) {
            // Les tables décrites sont créées à l'installation. Le module ne
            // fait rien lui-même : c'est le moteur qui pose son schéma.
            (new ExtMoteurDeclaratif($man->id(), $man->declaration(),
                                     $this->db, $this->utilisateur))->preparer();
        }

        $this->tracer('audit', "Extension installée : {$man->id()} v{$man->version()} par $parQui. "
            . 'Capacités : ' . implode(', ', $man->capacites())
            . '. Rôles optionnels accordés : '
            . (implode(', ', $rolesAccordes) ?: 'aucun'));
        if (class_exists('SecurityLog')) {
            SecurityLog::audit('extension_installee', [
                'action' => $man->id(), 'reason' => 'risque=' . $rapport['risque']]);
        }
        return $rapport;
    }

    public function activer(string $id, bool $actif, string $parQui): void
    {
        $etat = $this->etat();
        if (!isset($etat[$id])) throw new RuntimeException('Extension non installée : ' . $id);
        $this->majEtat($id, ['active' => $actif, 'suspendue' => null,
                             'echecs' => 0, 'modifie_par' => $parQui, 'modifie_le' => date('c')]);
        $this->tracer('audit', ($actif ? 'Extension activée' : 'Extension désactivée')
            . " : $id (par $parQui)");
    }

    public function desinstaller(string $id, string $parQui): void
    {
        $etat = $this->etat();
        unset($etat[$id]);
        $this->ecrireEtat($etat);
        // Les fichiers de l'extension et son stockage privé restent en place :
        // une désinstallation ne détruit pas de données. Le ménage est manuel,
        // et c'est volontaire — on ne veut pas d'un bouton qui efface.
        $this->tracer('audit', "Extension désinstallée : $id (par $parQui). "
            . 'Fichiers et stockage conservés sur le disque.');
    }

    private function suspendre(string $id, string $raison): void
    {
        $this->majEtat($id, ['active' => false, 'suspendue' => $raison, 'suspendue_le' => date('c')]);
        $this->tracer('warning', "Extension suspendue automatiquement : $id — $raison");
        if (class_exists('SecurityLog')) {
            SecurityLog::audit('extension_suspendue', ['action' => $id, 'reason' => $raison]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Chargement
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Une extension installée est-elle utilisable par ce client ?
     *
     * ⚠️ RÉPOND TOUJOURS OUI — ET C'EST VOULU.
     *
     * Il y avait ici une liste d'autorisation par client, tenue par le super
     * admin depuis Tenants → 🧩 : une extension installée ne s'exécutait que si
     * quelqu'un l'avait explicitement affectée. Deux raisons de la retirer.
     *
     * 1. ELLE N'ISOLAIT RIEN. L'état d'installation et le code étaient partagés
     *    entre tous les clients ; la liste ne faisait que masquer, à l'exécution,
     *    un module qui restait installé pour tout le monde. L'isolation se fait
     *    désormais par le CHEMIN — data/extensions/tenants/<client>/ — et un
     *    module absent de chez un client n'existe pas chez lui. Il n'y a plus
     *    rien à filtrer.
     *
     * 2. ELLE FAISAIT VALIDER PAR L'HÉBERGEUR UNE DÉCISION QUI N'EST PAS LA
     *    SIENNE. Installer un module est déjà une décision d'administrateur,
     *    prise après lecture des permissions demandées. Exiger une seconde
     *    approbation, ailleurs, par quelqu'un d'autre, ajoutait une étape qu'on
     *    oublie — et le symptôme était un module « Actif » à l'écran, invisible
     *    pour ses utilisateurs, sans explication.
     *
     * La méthode est CONSERVÉE plutôt que supprimée : ses trois appelants
     * expriment une intention — « ce client peut-il s'en servir ? » — qui reste
     * juste. Si une règle d'affectation revient un jour, elle se pose ici, à un
     * seul endroit.
     */
    private function autoriseePourTenant(string $id): bool
    {
        return true;
    }

    /**
     * Pourquoi un module donné n'est-il pas actif ?
     *
     * Le message « inconnue, inactive ou suspendue » couvrait cinq causes
     * distinctes. Cette méthode en désigne une seule, celle qui s'applique.
     */
    /**
     * Lève une suspension et remet le compteur d'échecs à zéro.
     *
     * Une suspension après trois échecs protège d'un module qui boucle. Mais
     * quand la panne venait du cœur — et non du module — l'administrateur
     * n'avait aucun moyen simple de le réactiver : il fallait désinstaller puis
     * réinstaller, en perdant les permissions accordées.
     */
    public function relancer(string $id, string $parQui): void
    {
        $etat = $this->etat();
        if (!isset($etat[$id])) {
            throw new RuntimeException('Module « ' . $id . ' » non installé.');
        }
        unset($etat[$id]['suspendue']);
        $etat[$id]['echecs'] = 0;
        $etat[$id]['active'] = true;
        $this->ecrireEtat($etat);

        $this->tracer('info', sprintf('Module « %s » relancé par %s.', $id, $parQui));
    }

    public function diagnostic(string $id): string
    {
        if (!$this->actif()) {
            return 'Les modules sont désactivés sur ce serveur '
                 . '(Configuration → Modules complémentaires).';
        }
        $etat = $this->etat()[$id] ?? null;
        if ($etat === null) {
            return 'Module « ' . $id . ' » non installé sur ce serveur.';
        }
        if (!empty($etat['suspendue'])) {
            return 'Module suspendu : ' . $etat['suspendue'];
        }
        if (empty($etat['active'])) {
            return 'Module installé mais désactivé. Réactivez-le depuis l\'écran Modules.';
        }
        if (!is_dir(ExtPaquet::dossierCode($id))) {
            return 'Les fichiers du module sont introuvables. Réimportez le paquet.';
        }
        $c = ExtSecurite::controlePrealable();
        if (!$c['ok']) {
            return 'Conditions de sécurité non réunies : ' . ($c['bloquants'][0] ?? '');
        }
        return 'Module « ' . $id . ' » indisponible pour une raison indéterminée.';
    }

    /**
     * Paquets .larka présents sur le disque mais PAS encore installés.
     *
     * Depuis que le code déplié vit sous data/extensions/code/, un paquet
     * simplement déposé dans extensions/ n'était plus vu par personne — pas même
     * l'exemple livré avec Larka. On les liste donc ici pour que l'écran puisse
     * proposer « Importer », au lieu de laisser croire qu'il n'y a rien.
     *
     * Deux emplacements scrutés : extensions/ (dépôt manuel, exemple fourni) et
     * data/extensions/paquets/ (déjà importés une fois, réinstallables).
     */
    public function paquetsDisponibles(): array
    {
        $out = [];
        // Sans l'extension zip, chaque paquet remonterait la même erreur : on
        // ne répète pas dix fois un problème de serveur, l'écran l'affiche une
        // seule fois en bandeau.
        if (!ExtPaquet::zipDisponible()) return $out;
        $installees = array_keys($this->etat());
        $dossiers = ExtPaquet::dossiersSources();

        foreach ($dossiers as $d) {
            if (!is_dir($d)) continue;
            // Seuls les MODULES apparaissent ici. Une thématique n'est pas un
            // module : elle n'a ni écran, ni données, ni permission. La faire
            // passer par l'installation d'extension demanderait au gestionnaire
            // d'approuver un jeu de couleurs comme un accès aux données.
            //
            // Elle est déposée dans l'onglet Apparence, et lue par le moteur.
            foreach (glob($d . '/*' . ExtPaquet::EXTENSION) ?: [] as $f) {
                try {
                    $info = ExtPaquet::verifier($f);
                } catch (\Throwable $e) {
                    // Un fichier illisible est signalé, pas masqué : sinon on
                    // cherche longtemps pourquoi son paquet « n'apparaît pas ».
                    $out[] = ['fichier' => basename($f), 'chemin' => $f, 'valide' => false,
                              'erreur' => $e->getMessage()];
                    continue;
                }
                $out[] = [
                    'fichier'     => basename($f),
                    'chemin'      => $f,
                    'valide'      => true,
                    'identifiant' => $info['identifiant'],
                    'nom'         => $info['nom'],
                    'version'     => $info['version'],
                    'auteur'      => $info['auteur'],
                    'deja_installe' => in_array($info['identifiant'], $installees, true),
                ];
            }
        }
        return $out;
    }

    /**
     * Manifestes des extensions actives, vérification d'intégrité comprise.
     * @return ExtManifeste[]
     */
    public function actives(): array
    {
        if (!$this->actif()) return [];

        // ── FAIL CLOSED ──────────────────────────────────────────────────
        // Si les conditions minimales de sécurité ne sont pas réunies, AUCUNE
        // extension ne se charge. Jamais « la sécurité est indisponible, on
        // continue quand même » : c'est précisément le moment où elle sert.
        // Le contrôle est mis en cache pour la requête — il lit le système de
        // fichiers, inutile de le refaire à chaque appel.
        if ($this->conditionsSecurite === null) {
            $c = ExtSecurite::controlePrealable();
            $this->conditionsSecurite = $c['ok'];
            if (!$c['ok']) {
                foreach ($c['bloquants'] as $b) {
                    $this->tracer('erreur', 'Extensions désactivées (sécurité) : ' . $b);
                }
                ExtJournalSecurite::consigner(ExtJournalSecurite::REFUSE, [
                    'extension' => '(toutes)', 'operation_reelle' => 'chargement',
                    'motif' => 'conditions_de_securite_non_reunies',
                    'gravite' => ExtJournalSecurite::CRITIQUE,
                    'detail' => implode(' | ', $c['bloquants']),
                ]);
            }
        }
        if ($this->conditionsSecurite === false) return [];
        $out = [];
        foreach ($this->etat() as $id => $e) {
            if (empty($e['active'])) continue;
            // Deux verrous distincts : installée ET activée au niveau serveur
            // (ci-dessus), puis affectée à ce tenant (ci-dessous). En
            // mono-tenant, le second verrou est neutre.
            if (!$this->autoriseePourTenant($id)) continue;
            $dossier = $this->racine . '/' . $id;
            if (!is_dir($dossier)) {
                $this->suspendre($id, 'Dossier introuvable.');
                continue;
            }
            try {
                $man = ExtManifeste::charger($dossier);
            } catch (\Throwable $ex) {
                $this->suspendre($id, 'Manifeste invalide : ' . $ex->getMessage());
                continue;
            }
            // ── Intégrité : le code a-t-il bougé depuis la validation ? ──────
            if (($e['empreinte'] ?? '') !== $man->empreinte()) {
                $this->suspendre($id, 'Le code a été modifié après validation. '
                    . 'Réexaminez l\'extension avant de la réactiver.');
                continue;
            }
            // ── Capacités : le manifeste en réclame-t-il de nouvelles ? ──────
            $nouvelles = array_diff($man->capacites(), $e['capacites_accordees'] ?? []);
            if ($nouvelles !== []) {
                $this->suspendre($id, 'Nouvelles permissions demandées ('
                    . implode(', ', $nouvelles) . ') : validation requise.');
                continue;
            }
            /**
             * Amorçage des listes : au CHARGEMENT, pas seulement à
             * l'installation. Un module installé avant l'ajout de ses valeurs
             * de départ affichait un menu vide, et rien ne le rattrapait.
             *
             * ⚠️ CE BLOC APPELAIT UNE MÉTHODE QUI N'EXISTE PLUS.
             * `amorcerListes()` a disparu du moteur le jour où les
             * nomenclatures sont passées de la table « Listes » au fichier
             * `extensions/config/<module>/listes.json` — mais l'appel est
             * resté, enveloppé dans un `catch (\Throwable)`. PHP levait donc
             * une Error à chaque chargement, le catch l'avalait, et
             * l'amorçage n'avait JAMAIS lieu. Silencieusement : aucune trace,
             * aucun message, un menu déroulant vide et rien pour l'expliquer.
             *
             * C'est le défaut que ce catch était censé couvrir qui l'a rendu
             * aveugle. On appelle désormais le vrai chemin — celui qui écrit
             * le fichier — et l'on ne rattrape que ce qu'il peut légitimement
             * lever : une configuration non inscriptible.
             */
            if ($man->estDeclaratif()) {
                try {
                    ExtConfig::initialiser($man->id(), $man->declaration());
                } catch (\Throwable $e) { /* configuration non inscriptible */ }
            }

            $out[$id] = $man;
        }
        return $out;
    }


    // ═══════════════════════════════════════════════════════════════════════
    //  Exécution
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Exécute une action d'extension appelée depuis le client
     * (?action=ext_<identifiant>_<action>).
     *
     * @throws RuntimeException si l'extension ou l'action n'existe pas
     */
    public function executerAction(string $id, string $action, array $charge): mixed
    {
        // ── Échec FERMÉ ──────────────────────────────────────────────────
        //
        // Si le contrôle préalable de sécurité n'a pas pu conclure — journal
        // d'audit inaccessible, politique de données illisible — on refuse. Le
        // réflexe inverse rendrait la protection facultative le jour précis où
        // elle est indisponible.
        //
        // Ce garde-fou vivait dans le chargeur de code d'auteur, retiré avec
        // lui. Il appartient désormais au seul chemin d'exécution restant.
        if ($this->conditionsSecurite === null) {
            $c = ExtSecurite::controlePrealable();
            $this->conditionsSecurite = (bool)($c['ok'] ?? false);
        }
        if ($this->conditionsSecurite === false) {
            throw new RuntimeException('Conditions de sécurité non réunies : '
                . 'les modules sont suspendus jusqu\'à leur rétablissement.');
        }

        $actives = $this->actives();
        if (!isset($actives[$id])) {
            // Message précis plutôt que trois hypothèses : l'administrateur
            // doit savoir quoi faire, pas deviner laquelle des cinq causes
            // s'applique.
            throw new RuntimeException($this->diagnostic($id));
        }
        $man = $actives[$id];
        if (!in_array($action, $man->actions(), true)) {
            throw new RuntimeException("Action « $action » non déclarée par l'extension « $id ».");
        }
        // Contrôle de rôle : une action d'extension n'est jamais plus permissive
        // que la page qui la porte, ET jamais plus permissive que ce que
        // l'administrateur a accordé. Sans page déclarée (extension purement
        // « ancrages »), on exige Gestionnaire : c'est le défaut prudent.
        $roles = $this->rolesAccordes($id, $man);
        $role  = $this->utilisateur['Role'] ?? '';
        if ($role !== 'Gestionnaire' && $role !== 'Admin' && !in_array($role, $roles, true)) {
            throw new RuntimeException('Permission insuffisante pour cette extension.');
        }

        // ── Actions d'ADMINISTRATION du module ───────────────────────────
        //
        // ⚠️ « dp_definir_reglages » ÉCRIVAIT LES RÉGLAGES POUR TOUT LE MONDE.
        //
        // La route ext_reglages exige Gestionnaire ou Admin. La même écriture,
        // atteinte par ext_appel, n'exigeait que de pouvoir ouvrir le module :
        // un demandeur admis sur l'écran d'un module en reconfigurait la
        // couleur, la densité, ou la durée maximale d'un créneau. Deux chemins
        // vers le même fichier, deux permissions différentes — c'est la plus
        // basse qui faisait foi.
        //
        // Liste CLOSE plutôt que motif : une action d'administration ajoutée
        // demain doit être inscrite ici, donc décidée.
        if (in_array($action, self::ACTIONS_ADMINISTRATION, true)
            && !in_array($role, ['Gestionnaire', 'Admin'], true)) {
            ExtJournalSecurite::consigner(ExtJournalSecurite::REFUSE, [
                'extension'        => $id,
                'operation_reelle' => 'module.' . $action,
                'motif'            => 'action_d_administration_hors_role',
                'utilisateur'      => (string)($this->utilisateur['Login'] ?? '?'),
                'gravite'          => ExtJournalSecurite::CRITIQUE,
            ]);
            throw new RuntimeException('Le réglage d\'un module revient à son '
                . 'gestionnaire.');
        }

        return $this->executer($man, 'action', $action, $charge);
    }

    /** Diffuse un hook à toutes les extensions qui l'écoutent. N'échoue jamais. */
    public function diffuserHook(string $nom, array $charge): void
    {
        foreach ($this->actives() as $id => $man) {
            if (!in_array($nom, $man->hooks(), true)) continue;
            try {
                $this->executer($man, 'hook', $nom, $charge);
            } catch (\Throwable $e) {
                $this->comptabiliserEchec($id, $e);
            }
        }
    }

    /**
     * Cœur de l'exécution : choisit le mode, mesure, isole les erreurs.
     */
    /**
     * Exécute une action d'un module déclaratif.
     *
     * Aucun code tiers n'est chargé : c'est le moteur de Larka qui travaille, à
     * partir de la déclaration. Ni processus isolé, ni analyse statique — il n'y
     * a rien à isoler ni à analyser.
     */
    private function executerDeclaratif(ExtManifeste $man, string $action, array $charge): mixed
    {
        $moteur = new ExtMoteurDeclaratif($man->id(), $man->declaration(),
                                          $this->db, $this->utilisateur);
        $jeu = (string)($charge['jeu'] ?? array_key_first($man->declaration()['donnees']));

        return match ($action) {
            'dp_lister'      => $moteur->lister($jeu, $charge),
            'dp_enregistrer' => $moteur->enregistrer($jeu, $charge),
            'dp_supprimer'   => $moteur->supprimer($jeu, $charge),
            'dp_exporter'    => $moteur->exporter($jeu, $charge),
            'dp_ancrage'     => $moteur->ancrage($charge),
            'dp_reglages'    => $moteur->reglages(),
            'dp_definir_reglages' => $moteur->definirReglages((array)($charge['valeurs'] ?? [])),
            'dp_cibles'      => $moteur->cibles($jeu, $charge),
            'dp_suggestions' => $moteur->suggestions($jeu, $charge),
            'dp_ajouter_valeur' => $moteur->ajouterValeurListe($jeu, $charge),
            default => throw new RuntimeException('Action déclarative inconnue : ' . $action),
        };
    }

    private function executer(ExtManifeste $man, string $type, string $nom, array $charge): mixed
    {
        $id = $man->id();

        if ($man->estDeclaratif()) {
            if ($type !== 'action') return null;   // un module déclaratif n'écoute aucun hook
            try {
                return $this->executerDeclaratif($man, $nom, $charge);
            } catch (ExtErreurUtilisateur | ExtRefusSecurite $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->comptabiliserEchec($id, $e);
                throw new RuntimeException('Le module « ' . $man->nom() . ' » a échoué : '
                    . $e->getMessage());
            }
        }

        // Inatteignable : un module non déclaratif est refusé dès le
        // manifeste. La branche d'exécution de code a été retirée avec le
        // processus isolé — il n'y a plus qu'un chemin.
        throw new RuntimeException('Format de module non pris en charge.');
    }

    /**
     * Mode standard / développement : exécution dans le processus courant.
     *
     * Ce qu'on peut garantir ici : l'extension ne casse pas la réponse (sortie
     * capturée, erreurs attrapées) et n'accède au cœur que par le Contexte
     * qu'on lui donne. Ce qu'on ne peut PAS garantir : qu'elle s'en tienne au
     * Contexte. PHP n'offre aucun moyen de cloisonner du code dans un même
     * processus. C'est la limite annoncée du mode standard.
     */

    /**
     * Le résultat d'une extension part vers le navigateur : il doit être du JSON
     * scalaire, borné, sans objet ni ressource.
     */
    public function resultatPropre(mixed $resultat, string $id, int $profondeur = 0): mixed
    {
        if ($profondeur > 12) return null;
        if ($resultat === null || is_scalar($resultat)) {
            return is_string($resultat) ? mb_substr($resultat, 0, 200000) : $resultat;
        }
        if (is_array($resultat)) {
            if (count($resultat) > 5000) {
                $resultat = array_slice($resultat, 0, 5000, true);
                $this->tracer('warning', "[$id] Résultat tronqué à 5 000 entrées.");
            }
            $out = [];
            foreach ($resultat as $k => $v) {
                $out[is_string($k) ? mb_substr($k, 0, 100) : $k]
                    = $this->resultatPropre($v, $id, $profondeur + 1);
            }
            return $out;
        }
        // Objets, ressources, fermetures : jamais transmis.
        return null;
    }

    private function comptabiliserEchec(string $id, \Throwable $e): void
    {
        $n = (int)($this->etat()[$id]['echecs'] ?? 0) + 1;
        $this->majEtat($id, ['echecs' => $n, 'dernier_echec' => date('c'),
                             'dernier_message' => mb_substr($e->getMessage(), 0, 300)]);
        $this->tracer('erreur', "[$id] Échec ($n/" . self::MAX_ECHECS . ') : ' . $e->getMessage());
        if ($n >= self::MAX_ECHECS) {
            $this->suspendre($id, self::MAX_ECHECS . ' échecs consécutifs. '
                . 'Dernier message : ' . mb_substr($e->getMessage(), 0, 200));
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Ressources pour le client
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Ce que le navigateur doit savoir : pages à ajouter au menu, scripts à
     * charger. Appelé par la route ext_manifeste au démarrage de l'application.
     */
    public function descriptionClient(): array
    {
        $role = $this->utilisateur['Role'] ?? '';
        $out = [];
        foreach ($this->actives() as $id => $man) {
            $page = $man->page();

            // Les rôles de la page gouvernent AUSSI les ancrages. Sans cela, un
            // Demandeur n'obtenait pas la page mais recevait quand même l'outil
            // de l'extension dans la barre des plans : le serveur refusait bien
            // ses appels, mais afficher un bouton qui échoue est à la fois
            // trompeur et l'aveu d'un contrôle mal placé.
            //
            // Sans page déclarée, on exige Gestionnaire — le même défaut prudent
            // que pour l'exécution des actions.
            $roles = $this->rolesAccordes($id, $man);
            $autorise = in_array($role, ['Gestionnaire', 'Admin'], true)
                     || in_array($role, $roles, true);
            if (!$autorise) continue;

            $out[] = [
                'identifiant' => $id,
                // Habillage et traductions remontés À LA RACINE : le chargeur
                // client les applique avant tout rendu, sans avoir à ouvrir la
                // déclaration de chaque module. Ils y étaient enfouis, et
                // l'habillage arrivait « 0 propriété ».
                'vocabulaire' => $man->estDeclaratif()
                    ? ($man->declaration()['vocabulaire'] ?? []) : [],
                'variables'   => $man->estDeclaratif()
                    ? ($man->declaration()['variables'] ?? []) : [],
                'langues'     => $man->estDeclaratif()
                    ? ($man->declaration()['langues'] ?? []) : [],
                'nom'         => $man->nom(),
                'version'     => $man->version(),
                'page'        => $page,
                // ── Toutes les pages que CE rôle a le droit d'ouvrir ────────
                //
                // Seule « page » — la première déclarée — était transmise. Un
                // module à deux écrans n'en exposait donc qu'un : le second
                // n'avait ni entrée de menu ni route, et restait injoignable.
                //
                // Le filtrage se fait page par page : un module peut très bien
                // ouvrir son formulaire de demande à tous et réserver son
                // planning aux gestionnaires. C'est même l'usage attendu.
                'pages'       => array_values(array_filter(
                    $man->donnees['pages_menu'] ?? [],
                    static function (array $pg) use ($role, $roles): bool {
                        // ⚠️ GESTIONNAIRE ET ADMIN NE VOIENT PLUS TOUT.
                        //
                        // Ils passaient outre les rôles déclarés, par prudence :
                        // ne pas enfermer un administrateur hors d'un module
                        // qu'il installe. Or à l'échelle d'une PAGE, cela
                        // revenait à leur montrer aussi les écrans écrits pour
                        // d'autres — un gestionnaire voyait « Planning des
                        // bornes », destiné à ceux qui consultent, en plus de
                        // son propre planning. Deux entrées, deux écrans
                        // presque identiques, dont un inutile pour lui.
                        //
                        // L'auteur a listé les rôles page par page : c'est une
                        // intention, pas une omission. On la respecte. La
                        // garantie d'accès reste au niveau du MODULE, où
                        // rolesAccordes() conserve Gestionnaire et Admin.
                        $r = array_intersect($pg['roles'] ?? [], $roles);
                        return in_array($role, $pg['roles'] ?? [], true)
                            && (in_array($role, ['Gestionnaire', 'Admin'], true)
                                || in_array($role, $r, true));
                    })),
                // Plus aucun script n'est transmis au navigateur : un module ne
                // contient pas de code, et la route qui savait en servir a été
                // retirée. Le champ subsiste pour ne pas rompre le contrat de
                // l'API cliente, toujours vide.
                'scripts'     => [],
                // Le logo, seule ressource d'un module encore servie.
                'logo'        => !empty($man->donnees['logo'])
                    ? 'api/index.php?action=ext_image&extension=' . rawurlencode($id)
                      . '&fichier=' . rawurlencode($man->donnees['logo'])
                      . '&v=' . substr($man->empreinte(), 0, 12)
                    : null,
                'actions'     => $man->actions(),
                'ancrages'    => $man->ancrages(),
                // Un module déclaratif n'a pas de script : le client dessine
                // ses écrans à partir de sa déclaration.
                'declaratif'  => $man->estDeclaratif(),
                // Par le MOTEUR, pas par la déclaration brute : lui seul résout
                // les listes gérées dans Configuration. En lisant la déclaration
                // directement, les champs « liste » arrivaient au client sans
                // aucune valeur — un menu déroulant vide, sans explication.
                'declaration' => $man->estDeclaratif()
                    ? (new ExtMoteurDeclaratif($man->id(), $man->declaration(),
                           $this->db, $this->utilisateur))->descriptionClient()
                    : null,
            ];
        }
        return $out;
    }

    private function dossierDe(string $id): string
    {
        if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) {
            throw new RuntimeException('Identifiant d\'extension invalide.');
        }
        $d = $this->racine . '/' . $id;
        if (!is_dir($d)) throw new RuntimeException("Extension « $id » introuvable.");
        return $d;
    }

    private function tracer(string $niveau, string $message): void
    {
        if (!class_exists('Journal')) return;
        match ($niveau) {
            'audit'   => Journal::log(Journal::AUDIT,   'extensions', $message),
            'erreur'  => Journal::erreur('extensions', $message),
            'warning' => Journal::warning('extensions', $message),
            'debug'   => Journal::debug('extensions', $message),
            default   => Journal::info('extensions', $message),
        };
    }
    /**
     * Résumé lisible d'une déclaration, pour l'écran d'installation.
     *
     * Remplace la « lecture automatique du code » — qui n'a rien à lire sur un
     * module de données — par ce que l'administrateur veut réellement savoir :
     * quelles tables vont être créées, quels écrans ajoutés, ce qui est publié
     * aux autres modules et ce qui est lu chez eux.
     */
    private static function resumerDeclaration(array $d): array
    {
        $jeux = [];
        foreach ($d['donnees'] ?? [] as $nom => $jeu) {
            $calcules = 0;
            foreach ($jeu['champs'] as $c) {
                if (($c['type'] ?? '') === 'formule') $calcules++;
            }
            $jeux[] = [
                'nom'      => $nom,
                'libelle'  => $jeu['libelle'] ?? $nom,
                'champs'   => count($jeu['champs']),
                'calcules' => $calcules,
                'table'    => ExtPolitiqueDonnees::prefixeExtension(
                                  (string)($d['identifiant'] ?? '')) . $nom,
            ];
        }

        $ancrages = [];
        foreach ($d['ancrages'] ?? [] as $a) {
            if (!is_array($a)) continue;
            $ancrages[] = ['type' => $a['type'] ?? '', 'emplacement' => $a['emplacement'] ?? '',
                           'libelle' => $a['libelle'] ?? ''];
        }

        $partages = [];
        foreach ($d['partage'] ?? [] as $p) {
            $partages[] = ['nom' => $p['nom'], 'champs' => $p['champs']];
        }

        $calculs = [];
        foreach ($d['calculs'] ?? [] as $n => $c) {
            $calculs[] = ['nom' => $n, 'type' => $c['type'], 'de' => $c['de']];
        }

        // ── Ce que le pack va concrètement faire ─────────────────────────
        // Une liste de tables et d'écrans ne dit pas à quoi sert le module.
        // On compose donc des phrases : l'administrateur doit pouvoir décider
        // sans lire le JSON, et « 16 champs » ne l'y aide pas.
        $actions = [];
        foreach ($jeux as $j) {
            $actions[] = sprintf('Créera une table « %s » pour y tenir %s%s.',
                $j['table'], mb_strtolower($j['libelle']),
                $j['calcules'] ? sprintf(' (%d valeur%s calculée%s à l\'affichage)',
                    $j['calcules'], $j['calcules'] > 1 ? 's' : '',
                    $j['calcules'] > 1 ? 's' : '') : '');
        }
        foreach ($d['pages'] ?? [] as $pg) {
            $actions[] = sprintf('Ajoutera l\'onglet « %s » au menu, visible par : %s.',
                $pg['titre'], implode(', ', $pg['roles']));
            $v = $pg['vue'] ?? [];
            $verbes = [];
            if (in_array('creer', $v['actions'] ?? [], true))     $verbes[] = 'saisir';
            if (in_array('modifier', $v['actions'] ?? [], true))  $verbes[] = 'modifier';
            if (in_array('supprimer', $v['actions'] ?? [], true)) $verbes[] = 'supprimer';
            if (in_array('exporter', $v['actions'] ?? [], true))  $verbes[] = 'exporter en CSV';
            if ($verbes) {
                $actions[] = 'Depuis cet onglet, les utilisateurs pourront '
                           . implode(', ', $verbes) . ' ces enregistrements.';
            }
            if (!empty($v['recherche'])) {
                $actions[] = 'La recherche portera sur : '
                    . implode(', ', $v['recherche']) . '.';
            }
        }
        foreach ($ancrages as $a) {
            $actions[] = match ($a['type']) {
                'compteur'   => sprintf('Affichera une tuile « %s » sur le tableau de bord.',
                                        $a['libelle']),
                'marqueurs'  => 'Posera des repères sur les plans, et permettra d\'en '
                              . 'ajouter depuis la barre d\'outils.',
                'liste_liee' => sprintf('Affichera « %s » dans la fiche concernée.',
                                        $a['libelle']),
                default      => sprintf('S\'insérera dans %s.', $a['emplacement']),
            };
        }
        foreach ($partages as $pa) {
            $actions[] = sprintf('Publiera « %s » aux autres modules : %s. '
                . 'Aucune autre donnée ne sortira.', $pa['nom'], implode(', ', $pa['champs']));
        }
        foreach ($calculs as $ca) {
            $actions[] = sprintf('Lira une valeur agrégée (%s) chez le module « %s ». '
                . 'Elle restera vide tant que ce module n\'est pas installé.',
                $ca['type'], explode('/', $ca['de'])[0]);
        }

        // Ce qu'il ne fera pas : aussi utile que ce qu'il fera, parce que c'est
        // ce que l'administrateur redoute sans oser le demander.
        $garanties = [
            'Ne lira ni ne modifiera aucune donnée existante de Larka.',
            'Ne contactera aucun serveur extérieur.',
            'N\'exécute aucun code : tout ce qui précède est dessiné par Larka.',
        ];

        return [
            'actions'   => $actions,
            'garanties' => $garanties,
            'jeux'     => $jeux,
            'pages'    => array_map(
                fn($p) => ['titre' => $p['titre'], 'roles' => $p['roles']],
                $d['pages'] ?? []),
            // Les rôles OPTIONNELS que ce module sait servir : ce sont les
            // seules cases que l'écran d'installation a le droit de proposer.
            // Admin et Gestionnaire n'y figurent pas — ils ne se retirent pas,
            // faute de quoi on installerait un module que plus personne ne peut
            // ouvrir, pas même pour le désinstaller.
            'roles_optionnels' => array_values(array_intersect(
                $d['pages'][0]['roles'] ?? [], self::ROLES_OPTIONNELS)),
            'ancrages' => $ancrages,
            'partage'  => $partages,
            'calculs'  => $calculs,
        ];
    }

}
