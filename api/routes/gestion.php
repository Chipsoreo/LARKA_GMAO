<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Gestion (admin)
 *
 * Actions : gestion_materiel, utilisateurs, historique, demandes, demandes_count,
 *           listes, listes_usage, notes_info, configuration, config_serveur
 */

// ── Gestion matériel ──────────────────────────────────────────────────────────────
if ($action === 'gestion_materiel') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'gestion_materiel', $db);
    if ($method==='GET')    json_ok($db->getAllGestionMateriel());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method==='POST')   json_ok(['id' => $db->addGestionMateriel(get_body())]);
    if ($method==='PUT')    { $db->updateGestionMateriel($id, get_body()); json_ok('OK'); }
    if ($method==='DELETE') {
        // Vérifier que l'actif est sorti avant suppression
        if (!$db->isGestionMaterielAssetSorti($id)) {
            json_error('Impossible de supprimer : l\'actif n\'est pas sorti du parc.', 403);
        }
        $db->deleteGestionMateriel($id); json_ok('OK');
    }
}

if ($action === 'gestion_materiel_cloturer') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $b = get_body();
    $db->cloturerGestionMateriel((int)$b['id'], $b['motif'] ?? '', $user['Login'] ?? '');
    json_ok('OK');
}

// ── Liste utilisateurs simplifiée (pour autocomplete Affecté à) ───────────────
if ($action === 'utilisateurs_liste' && $method === 'GET') {
    $user = require_auth(); // Tous les rôles (y compris Demandeur pour l'autocomplete inventaire)
    $all = $db->getAllUtilisateurs();
    // Retourner uniquement les champs nécessaires (pas de mot de passe)
    $result = array_map(function($u) {
        return ['Id' => $u['Id'], 'Nom' => $u['Nom'], 'Prenom' => $u['Prenom'], 'Login' => $u['Login'], 'Role' => $u['Role'], 'Actif' => $u['Actif'] ?? 1];
    }, $all);
    json_ok($result);
}

// ── Utilisateurs ──────────────────────────────────────────────────────────────
if ($action === 'utilisateurs') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    if ($method==='GET')    json_ok($db->getAllUtilisateurs());
    if ($method==='POST') {
        $body = get_body();
        $newLogin = trim($body['login'] ?? '');
        // Vérification doublon cross-tenant
        if ($newLogin && class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            $currentTenant = $_SESSION['tenant_key'] ?? 'default';
            if (TenantResolver::isLoginTakenByOther($newLogin, $currentTenant)) {
                $otherTk = TenantResolver::resolveLocalAccount($newLogin);
                $cfg = TenantResolver::loadConfig();
                $otherNom = $cfg['tenants'][$otherTk]['nom'] ?? $otherTk;
                json_error("Le login \"{$newLogin}\" existe déjà dans une autre organisation ({$otherNom}). Choisissez un login différent.");
            }
        }
        $newId = $db->addUtilisateur($body);
        // Enregistrer dans le registre centralisé
        if ($newLogin && class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            $provider = $body['provider'] ?? 'local';
            if ($provider === 'local') {
                $currentTenant = $_SESSION['tenant_key'] ?? 'default';
                TenantResolver::registerLocalAccount($newLogin, $currentTenant);
            }
        }
        json_ok(['id' => $newId]);
    }
    if ($method==='PUT') {
        $body = get_body();
        $newLogin = trim($body['login'] ?? '');
        // Vérification doublon cross-tenant lors du changement de login
        if ($newLogin && $id && class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            $currentTenant = $_SESSION['tenant_key'] ?? 'default';
            // Récupérer l'ancien login
            $existingUsers = $db->getAllUtilisateurs();
            $oldLogin = '';
            foreach ($existingUsers as $eu) {
                if ((int)$eu['Id'] === $id) { $oldLogin = $eu['Login']; break; }
            }
            if ($oldLogin && strtolower($oldLogin) !== strtolower($newLogin)) {
                if (TenantResolver::isLoginTakenByOther($newLogin, $currentTenant)) {
                    $otherTk = TenantResolver::resolveLocalAccount($newLogin);
                    $cfg = TenantResolver::loadConfig();
                    $otherNom = $cfg['tenants'][$otherTk]['nom'] ?? $otherTk;
                    json_error("Le login \"{$newLogin}\" existe déjà dans une autre organisation ({$otherNom}).");
                }
                // Mettre à jour le registre
                $provider = $body['provider'] ?? 'local';
                if ($provider === 'local') {
                    TenantResolver::renameLocalAccount($oldLogin, $newLogin, $currentTenant);
                }
            }
        }
        $db->updateUtilisateur($id, $body);
        json_ok('OK');
    }
    if ($method==='DELETE') {
        if ($id === $user['Id']) json_error('Impossible de supprimer son propre compte.');
        // Retirer du registre centralisé
        if ($id && class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            // ⚠️ FIX PERFS : lookup indexé (idx_utilisateurs_login) au lieu de
            // charger toute la table Utilisateurs pour retrouver un seul compte.
            // findLocalUtilisateurById ne renvoie que les comptes provider='local',
            // ce qui correspond exactement à la condition voulue ici.
            $eu = $db->findLocalUtilisateurById($id);
            if ($eu) {
                TenantResolver::unregisterLocalAccount($eu['Login']);
            }
        }
        $db->deleteUtilisateur($id);
        json_ok('OK');
    }
}

// ── Historique ────────────────────────────────────────────────────────────────
if ($action === 'historique' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    json_ok($db->getAllHistorique());
}

// ── Demandes d'intervention ───────────────────────────────────────────────────
if ($action === 'demandes') {
    $user = require_auth();
    if ($method === 'GET')  json_ok($db->getAllDemandes($user));
    if ($method === 'POST') {
        $demandeId = $db->addDemande(get_body(), $user);

        // ── Notification push aux rôles configurés ─────────────────────────
        if (defined('PUSH_ACTIF') && PUSH_ACTIF && defined('VAPID_PRIVATE_PEM') && VAPID_PRIVATE_PEM) {
            try {
                require_once __DIR__ . '/../WebPush.php';
                $roles = defined('PUSH_NOTIF_DEMANDE_ROLES') ? PUSH_NOTIF_DEMANDE_ROLES : ['Admin', 'Gestionnaire'];
                $subs = $db->getAllPushSubscriptions(null, $roles);
                if (!empty($subs)) {
                    $nomDemandeur = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? '')) ?: $user['Login'];

                    // Titre et corps configurables avec placeholder {demandeur}
                    $titre = defined('PUSH_NOTIF_DEMANDE_TITRE') ? PUSH_NOTIF_DEMANDE_TITRE : '📝 Nouvelle demande';
                    $corps = defined('PUSH_NOTIF_DEMANDE_CORPS') ? PUSH_NOTIF_DEMANDE_CORPS : '{demandeur} a soumis une demande d\'intervention.';
                    $titre = str_replace('{demandeur}', $nomDemandeur, $titre);
                    $corps = str_replace('{demandeur}', $nomDemandeur, $corps);

                    $notifData = [
                        'title'    => $titre,
                        'body'     => $corps,
                        'tag'      => 'gmao-demande-' . $demandeId,
                        'icon'     => defined('PUSH_NOTIF_DEMANDE_ICON') ? PUSH_NOTIF_DEMANDE_ICON : '/icon.png',
                        'data'     => ['page' => 'demandes'],
                        'renotify' => true,
                        'requireInteraction' => defined('PUSH_NOTIF_DEMANDE_REQUIRE_INTERACTION') ? PUSH_NOTIF_DEMANDE_REQUIRE_INTERACTION : false,
                    ];
                    // Image optionnelle
                    $img = defined('PUSH_NOTIF_DEMANDE_IMAGE') ? PUSH_NOTIF_DEMANDE_IMAGE : '';
                    if ($img) $notifData['image'] = $img;

                    $payload = json_encode($notifData, JSON_UNESCAPED_UNICODE);
                    $wp = new WebPush(VAPID_PRIVATE_PEM, VAPID_PUBLIC_KEY, VAPID_SUBJECT);
                    // ⚠️ FIX PERFS : envoi parallèle (cf. WebPush::sendBatch)
                    $batchSubs = array_map(fn($s) => [
                        'endpoint' => $s['Endpoint'],
                        'keys'     => ['p256dh' => $s['P256dh'], 'auth' => $s['Auth']],
                    ], $subs);
                    $batchResults = $wp->sendBatch($batchSubs, $payload);
                    foreach ($subs as $i => $sub) {
                        $r = $batchResults[$i] ?? ['statusCode' => 0];
                        // Nettoyer les souscriptions mortes
                        if (in_array($r['statusCode'], [404, 410])) {
                            $db->deletePushSubscription($sub['Endpoint']);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ne pas bloquer la création de la demande si le push échoue
                error_log('Push notification error: ' . $e->getMessage());
            }
        }

        json_ok(['id' => $demandeId]);
    }
    if ($method === 'PUT') {
        $body = get_body();
        // Relance utilisateur (pas besoin d'être admin)
        if (($body['action'] ?? '') === 'relancer') {
            $db->relancerDemande($id);
            json_ok('OK');
        }
        // Traitement admin/gestionnaire
        require_role($user, ['Admin','Gestionnaire']);
        $db->updateDemande($id, $body);
        json_ok('OK');
    }
}

// ── Badge demandes non traitées ───────────────────────────────────────────────
if ($action === 'demandes_count' && $method === 'GET') {
    $user = require_auth();

    /**
     * ⚠️ LA ROUTE ÉTAIT FERMÉE AUX DEMANDEURS.
     *
     * Ils n'obtenaient donc aucun compteur, et la cloche restait muette sur le
     * seul sujet qui les concerne : la réponse à LEUR demande. Pendant ce
     * temps, l'écran leur proposait de s'abonner aux alertes de stock et de
     * contrats — des préoccupations de gestionnaire, sur des écrans qui leur
     * sont fermés.
     *
     * On leur rend un compteur, et UNIQUEMENT le leur : les demandes qu'ils ont
     * déposées et qui ont reçu une réponse. Le décompte global reste réservé à
     * ceux qui traitent les demandes.
     *
     * Les statuts retenus sont ceux que l'écran considère comme CLOS — les
     * mêmes que ceux qui sortent une demande de la liste « en cours ». Deux
     * définitions du mot « traité » finiraient par diverger.
     */
    if (!in_array($user['Role'] ?? '', ['Admin', 'Gestionnaire'], true)) {
        $n = (int)($db->fetchOne(
            "SELECT COUNT(*) AS n FROM DemandesIntervention
              WHERE UtilisateurId = :u AND Statut IN ('Traité','Refusé','Terminée')",
            ['u' => (int)($user['Id'] ?? 0)]
        )['n'] ?? 0);
        json_ok(['tech' => 0, 'archive' => 0, 'total' => 0, 'mesReponses' => $n]);
    }

    $counts = $db->countDemandesDetail();
    json_ok($counts);
}

// ── Listes configurables ──────────────────────────────────────────────────────
if ($action === 'listes') {
    $user = require_auth();
    if ($method === 'GET') {
        $cat = $_GET['categorie'] ?? null;
        json_ok($cat ? $db->getListe($cat) : $db->getAllListes());
    }
    require_role($user, ['Admin','Gestionnaire']);
    // null quand le client ne se prononce pas : la valeur hérite alors des
    // réglages de sa catégorie. Forcer 0 ici remettait « non obligatoire » à
    // chaque ajout, en écrasant le réglage de l'administrateur.
    if ($method==='POST')   { $b=get_body(); json_ok(['id' => $db->addListeValeur($b['categorie'], $b['valeur'], (int)($b['ordre']??0), isset($b['obligatoire']) ? (int)$b['obligatoire'] : null, isset($b['saisieLibre']) ? (int)$b['saisieLibre'] : null)]); }
    if ($method==='PUT')    { $b=get_body(); $db->updateListeValeur($id, $b['valeur'], (int)($b['ordre']??0), (int)($b['actif']??1), (int)($b['obligatoire']??0), (int)($b['saisieLibre']??0)); json_ok('OK'); }
    if ($method==='DELETE') {
        $b = get_body();
        $force = !empty($b['force']);
        if ($force) {
            $db->forceDeleteListeValeur($id);
            json_ok(['action' => 'deleted', 'count' => 0]);
        } else {
            $result = $db->deleteListeValeur($id);
            json_ok($result);
        }
    }
}

// ── Réglages d'une catégorie de liste ────────────────────────────────────────
//
// Une route dédiée, plutôt qu'une boucle côté client sur chaque valeur : le
// réglage vaut pour la CATÉGORIE, et l'appliquer ligne par ligne le laissait à
// moitié posé si un appel échouait. C'est aussi le seul moyen de configurer une
// catégorie encore vide — celle que vient de créer un module, précisément le
// moment où l'on veut décider si les utilisateurs pourront l'enrichir.
if ($action === 'liste_reglages') {
    $user = require_auth();
    if ($method === 'GET') {
        $cat = (string)($_GET['categorie'] ?? '');
        if ($cat === '') json_error('Catégorie manquante.');
        json_ok($db->reglagesCategorie($cat));
    }
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST') {
        $b   = get_body();
        $cat = (string)($b['categorie'] ?? '');
        if ($cat === '') json_error('Catégorie manquante.');
        // Ramené à 0 ou 1 : un entier arbitraire rangé en base se relirait
        // comme « ni oui ni non », et le formulaire afficherait un état que
        // personne ne saurait expliquer.
        $borne = fn($v) => !empty($v) ? 1 : 0;
        $n = $db->setReglagesCategorie($cat,
            $borne($b['obligatoire'] ?? 0), $borne($b['libre'] ?? 0));
        json_ok(['valeurs_touchees' => $n]);
    }
}

// ── Vérification usage d'une valeur de liste ──────────────────────────────────
if ($action === 'listes_usage') {
    $user = require_auth();
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'GET' && $id) {
        json_ok($db->getListeValeurUsage($id));
    }
}

// ── Notes info (banderole demandeur) ──────────────────────────────────────────
if ($action === 'notes_info') {
    $user = require_auth();
    if ($method === 'GET') {
        if (in_array($user['Role'], ['Admin','Gestionnaire'])) {
            json_ok($db->getAllNotesInfoAdmin());
        } else {
            json_ok($db->getAllNotesInfo());
        }
    }
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST') { $b = get_body(); json_ok(['id' => $db->addNoteInfo($b['message'] ?? '', $user['Login'])]); }
    if ($method === 'PUT')  { $b = get_body(); $db->updateNoteInfo($id, $b['message'] ?? '', (int)($b['actif'] ?? 1)); json_ok('OK'); }
    if ($method === 'DELETE') { $db->deleteNoteInfo($id); json_ok('OK'); }
}

// ── Configuration (SuperAdmin) ────────────────────────────────────────────────
if ($action === 'configuration') {
    $user = require_auth();
    if ($method==='GET')  { require_role($user, ['Admin','Gestionnaire']); json_ok($db->getAllConfig()); }
    if ($method==='POST') { require_role($user, ['Admin','Gestionnaire']); $b=get_body(); $db->setConfig($b['cle'], $b['valeur']); json_ok('OK'); }
}

// ── Vérification config publique (pour les demandeurs) ──────────────────────
// Retourne uniquement les clés de config non sensibles nécessaires au frontend
// ── Onglets ouverts en consultation à l'utilisateur courant ───────────────────
// Renvoie la liste EFFECTIVE (surcharge individuelle si définie, sinon réglage
// global) : la sidebar ne doit pas avoir à recomposer cette règle côté client,
// au risque de diverger de ce que le serveur applique réellement.
if ($action === 'mes_acces_lecture' && $method === 'GET') {
    $user = require_auth();
    $modules = ($user['Role'] === 'Demandeur')
        ? demandeur_modules_lecture($db, $user)
        : [];
    json_ok(['modules' => $modules]);
}

if ($action === 'config_check' && $method === 'GET') {
    $user = require_auth(); // authentifié mais tous les rôles
    $key = $_GET['key'] ?? '';
    $allowedKeys = ['inventaire_agent_actif', 'modules_disabled', 'urgences_actif', 'urgences_config', 'plans_demandeur_actif']; // clés autorisées en lecture publique
    if (!in_array($key, $allowedKeys)) {
        json_error('Clé non autorisée.');
    }
    $row = $db->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = :cle", ['cle' => $key]);
    $value = $row['Valeur'] ?? '';

    // ⚠️ SÉCURITÉ : `urgences_config` contient désormais des catégories qui
    // peuvent être marquées « Gestionnaires uniquement » (visibilite =
    // 'gestionnaires'), avec procédure détaillée, photos et vidéos. On les
    // retire ICI, côté serveur : le contenu réservé ne doit jamais transiter
    // vers un Demandeur / Technicien / Visionneur, même si le JS est modifié.
    if ($key === 'urgences_config' && $value !== '') {
        require_once __DIR__ . '/../UrgencesAcl.php';
        $parsed = json_decode($value, true);
        if (is_array($parsed) && isset($parsed['categories']) && is_array($parsed['categories'])) {
            $value = json_encode(
                urg_filter_config_for_user($parsed, $user),
                JSON_UNESCAPED_UNICODE
            );
        }
    }

    json_ok(['key' => $key, 'value' => $value]);
}

// ── Configuration serveur (config.json) ───────────────────────────────────────
if ($action === 'config_serveur') {
    // Le Super Admin peut accéder sans session user (il n'a pas de compte dans un tenant)
    $isSuperAdmin = !empty($_SESSION['superadmin']['authenticated']);
    if ($isSuperAdmin) {
        // OK — accès autorisé via session super admin
    } else {
        $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
        // En multi-tenant, bloquer les admins normaux
        if (class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            json_error('En mode multi-tenant, les paramètres serveur sont réservés au Super Administrateur. Accédez via ?superadmin.', 403);
        }
    }

    $cfgFile = __DIR__ . '/../../config.json';

    if ($method === 'GET') {
        if (!file_exists($cfgFile)) json_error('config.json introuvable.', 404);
        $cfg = json_decode(file_get_contents($cfgFile), true);
        if ($cfg === null) json_error('config.json invalide : ' . json_last_error_msg(), 500);

        // Déchiffrement transparent : on déchiffre d'abord (pour récupérer les
        // valeurs réelles), puis on masque pour l'UI. Ainsi l'UI travaille
        // toujours avec la même structure, peu importe que le fichier soit
        // chiffré ou pas.
        require_once __DIR__ . '/../ConfigCrypto.php';
        require_once __DIR__ . '/../env.php';
        require_once __DIR__ . '/../EnvFile.php';
        loadEnv();
        try { ConfigCrypto::decryptSensitiveFields($cfg); } catch (\Throwable $e) { /* on continue avec ce qu'on a */ }

        // Champs gérés en variables d'environnement (.env). Pour chacun, on
        // renvoie à l'UI des drapeaux :
        //   *_set        → une valeur est configurée (via .env OU config.json)
        //   *_env        → la valeur provient d'une variable d'environnement
        //   *_env_locked → elle vient de l'env SYSTÈME (lecture seule)
        //   *_env_name   → nom de la variable
        //
        // Deux comportements :
        //   - SECRET   → la valeur réelle n'est JAMAIS renvoyée (masque "••••••••").
        //   - CONNEXION (client_id, tenant_id, redirect URIs…) → privée mais
        //     visible/éditable : on renvoie la valeur effective (env > config).
        $mask = '••••••••';
        $markEnvField = function (array &$cfg, string $section, string $cle, bool $secret) use ($mask) {
            $envName  = EnvFile::envNameFor($section, $cle);
            $envVal   = $envName ? env($envName, null) : null;
            $cfgVal   = $cfg[$section][$cle] ?? '';
            $fromEnv  = ($envVal !== null && $envVal !== '');
            $effective = $fromEnv ? (string)$envVal : (is_string($cfgVal) ? $cfgVal : '');
            $hasValue = ($effective !== '');
            if (!isset($cfg[$section]) || !is_array($cfg[$section])) $cfg[$section] = [];
            // Cas particulier secret_key : on exige une longueur minimale.
            if ($section === 'securite' && $cle === 'secret_key') {
                $cfg[$section][$cle . '_set'] = (strlen($effective) > 10);
            } else {
                $cfg[$section][$cle . '_set'] = $hasValue;
            }
            $cfg[$section][$cle . '_env']        = $fromEnv;
            $cfg[$section][$cle . '_env_locked'] = $fromEnv && $envName ? EnvFile::isManagedByOsEnv($envName) : false;
            $cfg[$section][$cle . '_env_name']   = $envName;
            // Secret → masqué ; connexion → valeur effective révélée.
            $cfg[$section][$cle] = $secret ? ($hasValue ? $mask : '') : $effective;
        };
        foreach (EnvFile::envMap() as $_p => $_meta) {
            [$_s, $_k] = explode('.', $_p, 2);
            $markEnvField($cfg, $_s, $_k, !empty($_meta['secret']));
        }
        unset($_p, $_meta, $_s, $_k);

        // Info diagnostique : la clé de chiffrement (legacy) est-elle en place ?
        $cfg['_crypto'] = [
            'key_file_exists' => file_exists(ConfigCrypto::keyPath()),
            'key_file_path'   => str_replace(__DIR__ . '/../../', '', ConfigCrypto::keyPath()),
        ];
        // Info diagnostique : état du fichier .env.
        $cfg['_env'] = [
            'file_exists'   => is_file(EnvFile::path()),
            'file_path'     => '.env',
            'managed_paths' => array_values(EnvFile::allEnvMap()),
        ];

        json_ok($cfg);
    }

    if ($method === 'POST') {
        if (!file_exists($cfgFile)) json_error('config.json introuvable.', 404);
        require_once __DIR__ . '/../ConfigCrypto.php';
        require_once __DIR__ . '/../env.php';
        require_once __DIR__ . '/../EnvFile.php';
        loadEnv();
        $current = json_decode(file_get_contents($cfgFile), true) ?? [];
        // Déchiffrement en mémoire : tout le reste du code travaille avec des
        // valeurs en clair (test de connexion DB, application du patch…).
        try { ConfigCrypto::decryptSensitiveFields($current); } catch (\Throwable $e) { /* on continue avec ce qu'on a */ }

        // Overlay des secrets déjà présents dans l'environnement (.env / système).
        // Ainsi les tests (connexion DB…) utilisent les vraies valeurs même si
        // l'admin n'a pas re-saisi les secrets dans le formulaire.
        foreach (EnvFile::allEnvMap() as $_sp => $_en) {
            $_ev = env($_en, null);
            if ($_ev === null || $_ev === '') continue;
            [$_ss, $_sc] = explode('.', $_sp, 2);
            if (!isset($current[$_ss]) || !is_array($current[$_ss])) $current[$_ss] = [];
            $current[$_ss][$_sc] = $_ev;
        }
        unset($_sp, $_en, $_ev, $_ss, $_sc);

        $patch   = get_body(); // tableau de {section, cle, valeur}
        $warnings = [];
        $secretChanges = []; // 'section.cle' => nouvelle valeur (saisie dans ce POST)

        // ── Appliquer le patch ───────────────────────────────────────────────
        foreach ($patch as $item) {
            $section = $item['section'] ?? null;
            $cle     = $item['cle']     ?? null;
            $valeur  = $item['valeur']  ?? null;
            if (!$section || !$cle) continue;

            // Ne pas écraser les secrets masqués (uniquement pour les scalaires)
            if (!is_array($valeur) && str_contains((string)$valeur, '••••')) continue;

            // ── Champ SENSIBLE → routé vers le fichier .env, jamais config.json ──
            $envName = isset($item['sous_section']) ? null : EnvFile::envNameFor($section, $cle);
            if ($envName !== null) {
                // Géré par l'environnement système (export/systemd/conteneur) :
                // l'application ne peut pas l'éditer → on ignore avec un avertissement.
                if (EnvFile::isManagedByOsEnv($envName)) {
                    $warnings[] = "🔒 $envName est défini par une variable d'environnement système — modification ignorée (gérez-la côté serveur).";
                    continue;
                }
                $sval = (string)$valeur;
                if ($sval === '') continue; // champ laissé vide → on ne touche à rien
                $secretChanges[$section . '.' . $cle] = $sval;
                // Appliquer aussi dans $current pour les tests (ex : connexion DB).
                if (!isset($current[$section]) || !is_array($current[$section])) $current[$section] = [];
                $current[$section][$cle] = $sval;
                continue;
            }

            // Coercion de type selon valeur (champs non sensibles uniquement)
            if (is_string($valeur) && ctype_digit($valeur)) $valeur = (int)$valeur;
            elseif ($valeur === 'true')  $valeur = true;
            elseif ($valeur === 'false') $valeur = false;

            // Sous-section (ex: serveur.https)
            if (isset($item['sous_section'])) {
                $sous = $item['sous_section'];
                if (!isset($current[$section][$sous]) || !is_array($current[$section][$sous])) {
                    $current[$section][$sous] = [];
                }
                $current[$section][$sous][$cle] = $valeur;
            } else {
                if (!isset($current[$section]) || !is_array($current[$section])) {
                    $current[$section] = [];
                }
                $current[$section][$cle] = $valeur;
            }
        }

        // ── Vérification de la connectivité DB si le driver est pgsql/mariadb ──
        $newDriver  = $current['base_de_donnees']['driver'] ?? 'sqlite';
        $prevDriver = DB_DRIVER; // driver actuellement en cours
        $dbError    = null; // message d'erreur bloquant

        if ($newDriver !== 'sqlite') {
            $dbHost = $current['base_de_donnees']['host']     ?? '127.0.0.1';
            $dbPort = (int)($current['base_de_donnees']['port'] ?? ($newDriver === 'pgsql' ? 5432 : 3306));
            $dbName = $current['base_de_donnees']['dbname']   ?? 'gmao';
            $dbUser = $current['base_de_donnees']['user']     ?? '';
            $dbPass = $current['base_de_donnees']['password'] ?? '';

            // Note : $current a été déchiffré en mémoire en début de POST, donc
            // $dbPass est déjà en clair. Si la modale a envoyé la valeur masquée
            // (••••), elle a été ignorée à la ligne 334 → on garde l'ancien mdp
            // déchiffré. Si elle a envoyé une nouvelle valeur, c'est elle qu'on a.

            // Extension PHP disponible ?
            $ext = $newDriver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';
            if (!extension_loaded($ext)) {
                // Extension manquante → erreur bloquante
                $dbError  = "Extension PHP $ext non chargée.";
                $warnings[] = "⚠️  Extension PHP $ext non chargée — activez-la dans php.ini.";
                $warnings[] = "→  Dans php.ini, décommentez : extension=php_{$ext}.dll (Windows) ou extension=$ext (Linux)";
                $warnings[] = "→  Sauvegarde refusée : corrigez la configuration avant d'enregistrer.";
            } else {
                // Test de connexion réseau
                $sock = @fsockopen($dbHost, $dbPort, $errno, $errstr, 2);
                if ($sock === false) {
                    // Serveur inaccessible → erreur bloquante
                    $dbError = "Serveur $newDriver inaccessible sur $dbHost:$dbPort.";
                    if ($newDriver === 'pgsql') {
                        $warnings[] = "⚠️  Impossible de joindre PostgreSQL sur $dbHost:$dbPort ($errstr).";
                        $warnings[] = "→  PostgreSQL n'est probablement pas installé ou démarré.";
                        $warnings[] = "→  Téléchargez-le : https://www.postgresql.org/download/";
                    } else {
                        $warnings[] = "⚠️  Impossible de joindre MariaDB/MySQL sur $dbHost:$dbPort ($errstr).";
                        $warnings[] = "→  Le serveur n'est probablement pas installé ou démarré.";
                    }
                    $warnings[] = "→  Sauvegarde refusée : le serveur doit être accessible avant d'enregistrer.";
                } else {
                    fclose($sock);
                    // Port ouvert — test PDO réel
                    try {
                        if ($newDriver === 'pgsql') {
                            $sslmode = $current['base_de_donnees']['sslmode'] ?? 'prefer';
                            $dsn     = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;sslmode=$sslmode";
                            $testPdo = new PDO($dsn, $dbUser, $dbPass);
                        } else {
                            $dsn     = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                            $testPdo = new PDO($dsn, $dbUser, $dbPass);
                        }
                        unset($testPdo);
                        $warnings[] = "✅ Connexion $newDriver réussie sur $dbHost:$dbPort/$dbName.";
                    } catch (PDOException $pdoEx) {
                        // Connexion PDO échouée → erreur bloquante
                        $dbError    = "Connexion $newDriver échouée : " . $pdoEx->getMessage();
                        $warnings[] = "⚠️  Connexion $newDriver échouée : " . $pdoEx->getMessage();
                        $warnings[] = "→  Vérifiez user, password et le nom de la base.";
                        $warnings[] = "→  Sauvegarde refusée : corrigez les identifiants avant d'enregistrer.";
                    }
                }
            }

            // Si une erreur bloquante → on retourne l'erreur SANS écrire config.json
            if ($dbError !== null) {
                http_response_code(422);
                echo json_encode([
                    'error'    => $dbError,
                    'warnings' => $warnings,
                    'saved'    => false,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // ── Secrets → fichier .env (jamais dans config.json) ─────────────────
        // Pour chaque champ sensible : on détermine la valeur effective, on la
        // (ré)écrit dans .env si nécessaire, puis on VIDE le champ dans la copie
        // destinée à config.json. C'est aussi le moment de la migration douce :
        // un secret encore présent en clair/chiffré dans config.json est déplacé
        // vers .env à la première sauvegarde via l'UI.
        $envUpdates = [];
        foreach (EnvFile::allEnvMap() as $sp => $en) {
            [$ss, $sc] = explode('.', $sp, 2);

            if (EnvFile::isManagedByOsEnv($en)) {
                // Géré par l'admin système : on n'y touche pas, mais on s'assure
                // que config.json ne contient aucune copie du secret.
                if (isset($current[$ss]) && is_array($current[$ss])) $current[$ss][$sc] = '';
                continue;
            }

            $effective = '';
            if (array_key_exists($sp, $secretChanges)) {
                // Nouvelle valeur saisie dans ce POST.
                $effective = $secretChanges[$sp];
            } elseif (($existing = env($en, null)) !== null && $existing !== '') {
                // Déjà présent dans .env, inchangé → rien à réécrire.
                $effective = '';
            } else {
                // Valeur héritée de config.json (legacy) → on la migre vers .env.
                $legacy = $current[$ss][$sc] ?? '';
                if (is_string($legacy) && $legacy !== '') $effective = $legacy;
            }

            if ($effective !== '') {
                $envUpdates[$en] = $effective;
            }
            // config.json ne doit JAMAIS stocker le secret.
            if (isset($current[$ss]) && is_array($current[$ss])) $current[$ss][$sc] = '';
        }
        unset($sp, $en, $ss, $sc, $effective, $legacy, $existing);

        if (!empty($envUpdates)) {
            try {
                EnvFile::set($envUpdates);
            } catch (\Throwable $e) {
                json_error("Impossible d'enregistrer les secrets dans le fichier .env : " . $e->getMessage()
                    . " Vérifiez les droits du dossier (le fichier .env doit être inscriptible par le serveur web).", 500);
            }
            $warnings[] = "🔐 Secrets enregistrés dans .env. Un redémarrage du service peut être nécessaire pour les recharger.";
        }

        // ── Écriture config.json (non sensible uniquement) ───────────────────
        // Les champs sensibles ont été vidés ci-dessus ; encryptSensitiveFields
        // est conservé par sécurité (no-op sur des valeurs vides) au cas où un
        // secret résiduel subsisterait.
        $toWrite = $current;
        try {
            ConfigCrypto::encryptSensitiveFields($toWrite);
        } catch (\Throwable $e) {
            // Champs sensibles vides → ne devrait pas échouer. On ignore.
        }

        if (file_put_contents($cfgFile, json_encode($toWrite, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            json_error('Impossible d\'écrire config.json. Vérifiez les permissions.', 500);
        }
        // Bonus sécu : forcer chmod 600 sur config.json (lecture/écriture par
        // le propriétaire uniquement). Idempotent et silencieux si refusé.
        @chmod($cfgFile, 0600);


        json_ok(['saved' => true, 'warnings' => $warnings]);
    }
}

// ── Statut Légifrance (onglet France) ───────────────────────────────────────
if ($action === 'legifrance_status') {
    $user = require_auth();

    $cfgFile = __DIR__ . '/../../config.json';
    if (!file_exists($cfgFile)) json_error('config.json introuvable.', 404);
    $cfg = json_decode(file_get_contents($cfgFile), true);
    if ($cfg === null) json_error('config.json invalide : ' . json_last_error_msg(), 500);

    $lf = $cfg['legifrance'] ?? [];
    $apiBase = trim((string)($lf['api_base_url'] ?? ''));
    $oauthUrl = trim((string)($lf['oauth_url'] ?? ''));
    $apiHost = parse_url($apiBase, PHP_URL_HOST) ?: '';
    $oauthHost = parse_url($oauthUrl, PHP_URL_HOST) ?: '';
    $env = strtolower((string)($lf['environnement'] ?? 'sandbox'));
    $serviceActive = (bool)($lf['actif'] ?? false);
    $clientConfigured = trim((string)($lf['client_id'] ?? '')) !== '' && trim((string)($lf['client_secret'] ?? '')) !== '';
    $official = (
        ($env === 'sandbox' && $apiHost === 'sandbox-api.piste.gouv.fr' && $oauthHost === 'sandbox-oauth.piste.gouv.fr') ||
        ($env === 'production' && $apiHost === 'api.piste.gouv.fr' && $oauthHost === 'oauth.piste.gouv.fr')
    );

    json_ok([
        'service_active' => $serviceActive,
        'client_configured' => $clientConfigured,
        'api_host' => $apiHost,
        'oauth_host' => $oauthHost,
        'environment' => $env,
        'official_mode' => $official,
        'scope' => (string)($lf['scope'] ?? 'openid'),
        'tls_verify' => (bool)($lf['tls_verify'] ?? true),
        'ca_bundle_configured' => trim((string)($lf['ca_bundle_path'] ?? '')) !== '',
    ]);
}



// ── Proxy Légifrance (OAuth + recherche + consultation) ────────────────────
function lf_cfg(string $key, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        // Priorité au $_cfg global (config.php) : il intègre déjà les secrets
        // injectés depuis les variables d'environnement (.env). On ne relit le
        // disque qu'en dernier recours.
        global $_cfg;
        if (isset($_cfg['legifrance']) && is_array($_cfg['legifrance'])) {
            $cfg = $_cfg['legifrance'];
        } else {
            $cfgFile = __DIR__ . '/../../config.json';
            if (!file_exists($cfgFile)) json_error('config.json introuvable.', 404);
            $all = json_decode(file_get_contents($cfgFile), true);
            if ($all === null) json_error('config.json invalide : ' . json_last_error_msg(), 500);
            $cfg = $all['legifrance'] ?? [];
        }
    }
    return $cfg[$key] ?? $default;
}

function lf_require_ready(): void {
    if (!(bool)lf_cfg('actif', false)) json_error('Le service Légifrance est désactivé dans la configuration serveur.', 400);
    if (trim((string)lf_cfg('client_id', '')) === '' || trim((string)lf_cfg('client_secret', '')) === '') {
        json_error('Client Légifrance non configuré.', 400);
    }
}

function lf_resolve_ca_bundle_path(): string {
    $path = trim((string)lf_cfg('ca_bundle_path', ''));
    if ($path === '') return '';
    if (preg_match('~^[A-Za-z]:[\\/]~', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
        return $path;
    }
    $base = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
    return rtrim(str_replace('\\', '/', $base), '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

function lf_tls_config(): array {
    $verify = (bool)lf_cfg('tls_verify', true);
    $cafile = lf_resolve_ca_bundle_path();
    if ($verify && $cafile !== '' && (!is_file($cafile) || !is_readable($cafile))) {
        json_error('Bundle CA Légifrance introuvable ou illisible : ' . $cafile, 500);
    }
    return ['verify' => $verify, 'cafile' => $cafile];
}

function lf_network_error_message(string $err, array $tls): string {
    $msg = 'Erreur réseau Légifrance : ' . $err;
    if (stripos($err, 'self-signed certificate in certificate chain') !== false) {
        $msg .= ' — la chaîne TLS n\'est pas approuvée par le serveur PHP. Renseigne "Chemin bundle CA" dans Configuration serveur > Légifrance / France';
        if (($tls['verify'] ?? true) === true) {
            $msg .= ' ou désactive temporairement "Vérification TLS" pour tester.';
        }
    }
    return $msg;
}

function lf_http(string $method, string $url, array $headers = [], $body = null): array {
    $method = strtoupper($method);
    $responseHeaders = '';
    $status = 0;
    $respBody = '';
    $tls = lf_tls_config();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $tls['verify'],
            CURLOPT_SSL_VERIFYHOST => $tls['verify'] ? 2 : 0,
        ]);
        if ($tls['verify'] && $tls['cafile'] !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $tls['cafile']);
        }
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            json_error(lf_network_error_message($err, $tls), 502);
        }
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $responseHeaders = substr($raw, 0, $headerSize);
        $respBody = substr($raw, $headerSize);
    } else {
        $ssl = [
            'verify_peer' => $tls['verify'],
            'verify_peer_name' => $tls['verify'],
            'allow_self_signed' => !$tls['verify'],
        ];
        if ($tls['verify'] && $tls['cafile'] !== '') $ssl['cafile'] = $tls['cafile'];
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 45,
            ],
            'ssl' => $ssl,
        ];
        if ($body !== null) $opts['http']['content'] = $body;
        $ctx = stream_context_create($opts);
        $respBody = @file_get_contents($url, false, $ctx);
        if ($respBody === false) {
            $err = error_get_last();
            json_error(lf_network_error_message((string)($err['message'] ?? 'échec de connexion HTTPS'), $tls), 502);
        }
        global $http_response_header;
        $responseHeaders = is_array($http_response_header ?? null) ? implode("\n", $http_response_header) : '';
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $responseHeaders, $m)) $status = (int)$m[1];
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $respBody];
}

function lf_get_token(): string {
    $cacheDir = __DIR__ . '/../../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/legifrance_token_' . sha1((string)lf_cfg('oauth_url','') . '|' . (string)lf_cfg('client_id','')) . '.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['access_token']) && (int)($cached['expires_at'] ?? 0) > time() + 30) {
            return (string)$cached['access_token'];
        }
    }

    $form = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => (string)lf_cfg('client_id', ''),
        'client_secret' => (string)lf_cfg('client_secret', ''),
        'scope' => (string)lf_cfg('scope', 'openid'),
    ], '', '&', PHP_QUERY_RFC3986);

    $res = lf_http('POST', (string)lf_cfg('oauth_url', ''), [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ], $form);

    $json = json_decode((string)$res['body'], true);
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($json) || empty($json['access_token'])) {
        $msg = is_array($json) ? ($json['error_description'] ?? $json['message'] ?? $json['error'] ?? '') : '';
        json_error('Échec OAuth Légifrance' . ($msg ? ' : ' . $msg : '.') , 502);
    }

    $ttl = max(60, ((int)($json['expires_in'] ?? 3600)) - 60);
    @file_put_contents($cacheFile, json_encode([
        'access_token' => (string)$json['access_token'],
        'expires_at' => time() + $ttl,
    ], JSON_UNESCAPED_UNICODE));

    return (string)$json['access_token'];
}

function lf_proxy_post(string $path, array $payload): array {
    lf_require_ready();
    $token = lf_get_token();
    $base = rtrim((string)lf_cfg('api_base_url', ''), '/');
    $url = $base . $path;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = lf_http('POST', $url, [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ], $body);
    $json = json_decode((string)$res['body'], true);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $msg = is_array($json) ? ($json['message'] ?? $json['error'] ?? '') : '';
        json_error('Erreur API Légifrance' . ($msg ? ' : ' . $msg : ' (HTTP ' . $res['status'] . ')'), 502);
    }
    if (!is_array($json)) json_error('Réponse Légifrance invalide.', 502);
    return $json;
}

if ($action === 'legifrance_search') {
    $user = require_auth();
    $body = get_body();
    $payload = $body['payload'] ?? $body;
    if (!is_array($payload) || empty($payload['recherche'])) json_error('Requête de recherche Légifrance invalide.');
    $json = lf_proxy_post('/search', $payload);
    json_ok($json);
}

if ($action === 'legifrance_suggest') {
    $user = require_auth();
    $body = get_body();
    $payload = $body['payload'] ?? $body;
    if (!is_array($payload)) json_error('Requête de suggestion Légifrance invalide.');
    $json = lf_proxy_post('/suggest', $payload);
    json_ok($json);
}

if ($action === 'legifrance_consult') {
    $user = require_auth();
    $body = get_body();
    $endpoint = trim((string)($body['endpoint'] ?? ''));
    $payload = $body['payload'] ?? [];
    $allowed = ['/consult/getArticle', '/consult/legiPart', '/consult/juri', '/consult/kaliCont', '/consult/code', '/consult/kaliText', '/consult/kaliArticle', '/consult/kaliContIdcc', '/consult/jorf', '/consult/jorfPart', '/consult/jorfCont', '/consult/cnil', '/consult/circulaire', '/consult/acco', '/consult/lawDecree', '/consult/debat', '/consult/legi/tableMatieres', '/consult/kaliSection', '/list/code'];
    if (!in_array($endpoint, $allowed, true)) json_error('Endpoint de consultation Légifrance non autorisé.');
    if (!is_array($payload)) json_error('Payload de consultation invalide.');
    $json = lf_proxy_post($endpoint, $payload);
    json_ok($json);
}

// ── Test SMTP ────────────────────────────────────────────────────────────────
if ($action === 'smtp_test') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $b = get_body();
    $email = $b['email'] ?? '';
    if (!$email || !str_contains($email, '@')) json_error('Adresse email invalide.');
    if (!SMTP_HOST) json_error('Hôte SMTP non configuré. Enregistrez d\'abord la configuration.');
    
    $sent = gmao_send_mail($email,
        'Larka — Test SMTP',
        '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:20px">
            <h2 style="color:#2563eb">✅ Test SMTP réussi !</h2>
            <p>Si vous lisez cet email, la configuration SMTP fonctionne correctement.</p>
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">
            <p style="color:#6b7280;font-size:12px">— GMAO</p>
        </div>'
    );
    if ($sent) json_ok('Email envoyé.');
    else json_error('Échec de l\'envoi. Vérifiez hôte, port, identifiants SMTP.');
}

// ── Feedback utilisateur (problème / suggestion) ─────────────────────────────
// Envoie un mail aux destinataires définis dans config.json > feedback.emails
// (ou tous les Admin si liste vide). Accessible à tous les utilisateurs connectés.
if ($action === 'feedback_send') {
    $user = require_auth();

    // Désactivé par configuration ?
    $actif = cfg('feedback', 'actif');
    if ($actif === false) json_error('La fonction feedback est désactivée par l\'administrateur.', 403);

    $b = get_body();
    $type    = trim($b['type']    ?? 'autre');   // 'probleme' | 'suggestion' | 'autre'
    $sujet   = trim($b['sujet']   ?? '');
    $message = trim($b['message'] ?? '');
    $pageCtx = trim($b['page']    ?? '');
    $screenshotB64 = $b['screenshot'] ?? null;   // dataURL ou base64 brut
    $screenshotMime = $b['screenshot_mime'] ?? 'image/png';

    if ($sujet === '' && $message === '') json_error('Sujet ou message requis.');
    if (mb_strlen($message) > 5000) json_error('Message trop long (max 5000 caractères).');
    if (mb_strlen($sujet)   > 200)  json_error('Sujet trop long (max 200 caractères).');

    // Récupérer les destinataires depuis la config
    $emails = cfg('feedback', 'emails') ?? [];
    if (!is_array($emails)) {
        // Tolérer une string CSV
        $emails = array_filter(array_map('trim', explode(',', (string)$emails)));
    }
    $emails = array_values(array_filter($emails, function($e) {
        return filter_var(trim($e), FILTER_VALIDATE_EMAIL);
    }));

    // Fallback : si rien, on prend les mails des Admin actifs
    if (!$emails) {
        try {
            $allUsers = $db->getAllUtilisateurs();
            foreach ($allUsers as $a) {
                if (($a['Role'] ?? '') !== 'Admin') continue;
                if (isset($a['Actif']) && !$a['Actif']) continue;
                $e = trim($a['Email'] ?? $a['Login'] ?? '');
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e;
            }
        } catch (\Throwable $e) { /* tolérer */ }
    }
    $emails = array_values(array_unique($emails));
    if (!$emails) json_error('Aucun destinataire configuré. Renseignez "Emails feedback" dans Configuration → Serveur, ou créez un compte Admin avec un email valide.');

    if (!SMTP_HOST) json_error('SMTP non configuré (Configuration → Serveur).');

    $typeLabel = ['probleme'=>'🐛 Problème', 'suggestion'=>'💡 Suggestion'][$type] ?? '📨 Message';
    $userLabel = htmlspecialchars(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''), ENT_QUOTES, 'UTF-8');
    $userMail  = htmlspecialchars($user['Login'] ?? $user['Email'] ?? '', ENT_QUOTES, 'UTF-8');
    $userRole  = htmlspecialchars($user['Role'] ?? '', ENT_QUOTES, 'UTF-8');
    $sujetEsc  = htmlspecialchars($sujet, ENT_QUOTES, 'UTF-8');
    $msgEsc    = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $pageEsc   = htmlspecialchars($pageCtx, ENT_QUOTES, 'UTF-8');

    // Screenshot : on l'embarque inline si fourni (dataURL ou base64 pur).
    // Pour faire simple, on génère un mail HTML avec data: inline (la plupart
    // des clients modernes affichent ; sinon l'utilisateur peut copier-coller).
    $imgHtml = '';
    if ($screenshotB64) {
        if (str_starts_with($screenshotB64, 'data:')) {
            // déjà une dataURL
            $src = $screenshotB64;
        } else {
            $src = 'data:' . preg_replace('/[^a-z0-9\/\-+.]/i', '', $screenshotMime) . ';base64,' . $screenshotB64;
        }
        // Validation grossière de la taille (< 5 Mo de base64)
        if (strlen($src) < 6 * 1024 * 1024) {
            $imgHtml = '<div style="margin-top:16px"><div style="font-size:11px;color:#6b7280;margin-bottom:6px">Capture d\'écran jointe :</div><img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" style="max-width:100%;border:1px solid #e5e7eb;border-radius:6px"></div>';
        }
    }

    $subject = '[Larka] ' . $typeLabel . ($sujet ? ' — ' . $sujet : '');
    $body = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:20px;color:#111">'
          . '<h2 style="color:#2563eb;margin:0 0 8px">' . $typeLabel . ($sujet ? ' — ' . $sujetEsc : '') . '</h2>'
          . '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;font-size:13px;margin:12px 0">'
          .   '<div><b>De :</b> ' . $userLabel . ' &lt;' . $userMail . '&gt; <span style="color:#6b7280">(' . $userRole . ')</span></div>'
          .   ($pageEsc ? '<div><b>Page :</b> <code>' . $pageEsc . '</code></div>' : '')
          .   '<div><b>Date :</b> ' . date('d/m/Y H:i') . '</div>'
          . '</div>'
          . '<div style="white-space:pre-wrap;font-size:14px;line-height:1.6;padding:12px 0">' . $msgEsc . '</div>'
          . $imgHtml
          . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 12px">'
          . '<p style="color:#6b7280;font-size:11px;margin:0">Message envoyé depuis Larka. Pour répondre directement à l\'utilisateur : ' . $userMail . '</p>'
          . '</div>';

    $okCount = 0;
    foreach ($emails as $to) {
        if (gmao_send_mail($to, $subject, $body)) $okCount++;
    }
    if ($okCount === 0) json_error('Échec de l\'envoi à tous les destinataires.');
    json_ok(['sent' => $okCount, 'total' => count($emails)]);
}

// ── Resync des séquences PostgreSQL (réparation après import) ────────────────
if ($action === 'db_resync_sequences') {
    // Accessible aussi en mode SuperAdmin pur (sans tenant)
    $isSuperAdmin = !empty($_SESSION['superadmin']['authenticated']);
    if (!$isSuperAdmin) {
        $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    }
    if ($method === 'POST') {
        try {
            // Diagnostic AVANT le resync
            $diagBefore = $db->diagPgSequences();
            $count = $db->resyncAllPgSequences();
            // Diagnostic APRÈS pour voir ce qui a vraiment changé
            $diagAfter  = $db->diagPgSequences();

            $changed = [];
            foreach ($diagAfter as $seq) {
                $before = null;
                foreach ($diagBefore as $b) {
                    if ($b['table'] === $seq['table']) { $before = $b; break; }
                }
                if ($before && $before['seqVal'] != $seq['seqVal']) {
                    $changed[] = [
                        'table'  => $seq['table'],
                        'maxId'  => $seq['maxId'],
                        'before' => $before['seqVal'],
                        'after'  => $seq['seqVal'],
                    ];
                }
            }

            $msg = $count === 0
                ? 'Aucune séquence resynchronisée. Cette opération est utile uniquement avec PostgreSQL.'
                : ($count . ' séquence(s) resynchronisée(s).' . (count($changed) > 0 ? ' ' . count($changed) . ' modification(s).' : ' Toutes les séquences étaient déjà à jour.'));
            json_ok([
                'count'      => $count,
                'message'    => $msg,
                'changed'    => $changed,
                'diagBefore' => $diagBefore,
                'diagAfter'  => $diagAfter,
                'driver'     => DB_DRIVER,
            ]);
        } catch (\Throwable $e) {
            json_error('Erreur de resynchronisation : ' . $e->getMessage());
        }
    }
}