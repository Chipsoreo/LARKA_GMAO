<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka — Conformité RGPD : information des personnes (articles 12 à 14)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Une politique COMMUNE à toutes les organisations, que chacune peut
 *  personnaliser — et toute personnalisation se VOIT.
 *
 *  Trois couches, de la plus générale à la plus précise :
 *    1. le modèle Larka (ci-dessous, FR et EN), qui s'adapte de lui-même à
 *       l'installation : assistant IA local ou distant, connexion Microsoft /
 *       Google, notifications, durée des journaux et des sessions ;
 *    2. le modèle commun, retouché au besoin par le Super Admin (registre,
 *       clé « rgpd_modele ») : il vaut pour toutes les organisations ;
 *    3. la personnalisation d'une organisation (clé « rgpd_org_<clé> ») :
 *       ses coordonnées (responsable, contact, DPO), des sections réécrites,
 *       des sections ajoutées. Les sections réécrites ou ajoutées portent la
 *       mention « Personnalisé par l'entreprise », et la politique entière un
 *       bandeau qui le dit, avec la date.
 *
 *  Les coordonnées ne sont pas une personnalisation : le modèle les attend.
 *  Les textes sont du TEXTE (paragraphes, listes « - ») : rien n'est servi en
 *  HTML, la page de connexion qui les affiche n'exécute donc rien de saisi.
 *
 *  Le modèle est fourni à titre indicatif : il décrit ce que fait le logiciel,
 *  pas les choix de l'organisation (base légale, durées métier) — à faire
 *  valider par son délégué à la protection des données.
 * ═══════════════════════════════════════════════════════════════════════════
 */

final class LarkaRgpd
{
    /** Date de la dernière révision du modèle Larka (affichée « mise à jour le »). */
    public const MODELE_DATE = '2026-10-23';

    /** Coordonnées attendues : clé => [longueur max, libellé]. */
    public const IDENTITE = [
        'organisation' => [160, 'Organisation (responsable du traitement)'],
        'adresse'      => [300, 'Adresse postale'],
        'contact'      => [200, 'Contact pour les données personnelles (adresse électronique ou service)'],
        'dpo'          => [200, 'Délégué à la protection des données (nom et contact)'],
    ];

    private const TITRE_MAX = 120;
    private const TEXTE_MAX = 6000;
    private const AJOUTS_MAX = 6;

    // ── Modèle Larka ─────────────────────────────────────────────────────────

    /**
     * Sections du modèle, dans l'ordre d'affichage, adaptées à l'installation.
     * Une section absente de l'installation (pas d'assistant, pas de SSO) n'est
     * pas rendue. Variables : {organisation} {contact} {dpo} {journaux_jours}
     * {session_heures} {fournisseur_ia} {fournisseurs_sso}.
     *
     * @return array<string, array{titre: string, texte: string}>
     */
    public static function modele(string $langue = 'fr', ?array $contexte = null): array
    {
        $c = $contexte ?? self::contexte();
        $en = $langue === 'en';
        $s = [];

        $s['responsable'] = $en ? [
            'titre' => 'Who is responsible',
            'texte' => "Larka is the maintenance management software (CMMS) used by {organisation}. {organisation} is the data controller for the personal data recorded in it: it decides what is entered, by whom and for how long.",
        ] : [
            'titre' => 'Responsable du traitement',
            'texte' => "Larka est le logiciel de gestion de la maintenance (GMAO) utilisé par {organisation}. {organisation} est responsable du traitement des données personnelles qui y sont enregistrées : c'est elle qui décide de ce qui est saisi, par qui et pour combien de temps.",
        ];

        $s['dpo'] = $en ? [
            'titre' => 'Data protection officer',
            'texte' => "You can contact the data protection officer (DPO) of {organisation}: {dpo}.",
        ] : [
            'titre' => 'Délégué à la protection des données',
            'texte' => "Vous pouvez joindre le délégué à la protection des données (DPO) de {organisation} : {dpo}.",
        ];

        $s['finalites'] = $en ? [
            'titre' => 'Why the data is processed',
            'texte' => "The data is used only to manage the maintenance of the assets of {organisation}:\n"
                     . "- recording maintenance requests and following them up;\n"
                     . "- planning and tracing work orders, contracts, equipment and stock;\n"
                     . "- granting access rights according to each person's role and securing sign-ins;\n"
                     . "- keeping the history needed for the technical and regulatory follow-up of the assets.\n\n"
                     . "It is not sold, not used for advertising, and not subject to any automated decision producing legal effects.",
        ] : [
            'titre' => 'Pourquoi ces données sont traitées',
            'texte' => "Les données servent uniquement à la gestion de la maintenance du patrimoine de {organisation} :\n"
                     . "- enregistrer les demandes d'intervention et en suivre le traitement ;\n"
                     . "- planifier et tracer les interventions, les contrats, les équipements et les stocks ;\n"
                     . "- attribuer les droits d'accès selon le rôle de chacun et sécuriser les connexions ;\n"
                     . "- conserver l'historique nécessaire au suivi technique et réglementaire du patrimoine.\n\n"
                     . "Elles ne sont ni vendues, ni utilisées à des fins publicitaires, ni soumises à une décision automatisée produisant des effets juridiques.",
        ];

        $s['base_legale'] = $en ? [
            'titre' => 'Legal basis',
            'texte' => "Depending on the nature of {organisation}, the processing is based on the performance of a task carried out in the public interest or on the legitimate interest of the organisation in managing its assets (Article 6(1)(e) or (f) GDPR) and, for some records, on compliance with its legal obligations (Article 6(1)(c)). {organisation} states the basis it relies on in its record of processing activities.",
        ] : [
            'titre' => 'Sur quelle base',
            'texte' => "Selon la nature de {organisation}, le traitement repose sur l'exécution d'une mission d'intérêt public ou sur l'intérêt légitime de l'organisation à gérer son patrimoine (article 6.1 e ou f du RGPD), et, pour certaines traces, sur le respect de ses obligations légales (article 6.1 c). {organisation} précise la base retenue dans son registre des activités de traitement.",
        ];

        $s['donnees'] = $en ? [
            'titre' => 'What data',
            'texte' => "- Identity and work contact details: last name, first name, email address, telephone, department or position;\n"
                     . "- Account: login, role, profile, display preferences — the password is never stored in clear text (only a hashed fingerprint);\n"
                     . "- Activity: requests, work orders, comments, attached photos and documents, assignments;\n"
                     . "- Technical traces: sign-in and security logs (date, time, IP address, action).\n\n"
                     . "No special category of data within the meaning of Article 9 GDPR (health, opinions, etc.) is meant to be entered in Larka: please do not write any in free-text fields.",
        ] : [
            'titre' => 'Quelles données',
            'texte' => "- Identité et coordonnées professionnelles : nom, prénom, adresse électronique, téléphone, service ou poste ;\n"
                     . "- Compte : identifiant, rôle, profil, préférences d'affichage — le mot de passe n'est jamais conservé en clair (seule une empreinte chiffrée l'est) ;\n"
                     . "- Activité : demandes, interventions, commentaires, photos et documents joints, affectations ;\n"
                     . "- Traces techniques : journaux de connexion et de sécurité (date, heure, adresse IP, action).\n\n"
                     . "Aucune donnée sensible au sens de l'article 9 du RGPD (santé, opinions…) n'a vocation à être saisie dans Larka : n'en écrivez pas dans les champs libres.",
        ];

        $s['destinataires'] = $en ? [
            'titre' => 'Who can see it',
            'texte' => "- authorised staff of {organisation}, each according to their role (requester, technician, manager, administrator);\n"
                     . "- maintenance contractors, only for the requests and work orders that concern them;\n"
                     . "- the host and the provider operating Larka, within the limits of their assignment and bound by confidentiality.\n\n"
                     . "The data is not passed on to any other third party, except where required by law.",
        ] : [
            'titre' => 'Qui y a accès',
            'texte' => "- les agents habilités de {organisation}, chacun selon son rôle (demandeur, technicien, gestionnaire, administrateur) ;\n"
                     . "- les prestataires de maintenance, pour les seules demandes et interventions qui les concernent ;\n"
                     . "- l'hébergeur et le prestataire chargé de l'exploitation de Larka, dans la limite de leur mission et tenus à la confidentialité.\n\n"
                     . "Les données ne sont transmises à aucun autre tiers, sauf obligation légale.",
        ];

        if ($c['ia']) {
            if ($c['ia_externe']) {
                $s['ia'] = $en ? [
                    'titre' => 'AI assistant',
                    'texte' => "The AI assistant relies on an external provider ({fournisseur_ia}). Before anything is sent, names, email addresses and telephone numbers are replaced with anonymous markers; only the information needed for the answer is transmitted, and no conversation is stored on the server.\n\n"
                             . "{organisation} governs this use by contract (processor, Article 28 GDPR) and, if the provider processes data outside the European Union, by the safeguards of Articles 44 et seq.",
                ] : [
                    'titre' => 'Assistant IA',
                    'texte' => "L'assistant IA s'appuie sur un fournisseur externe ({fournisseur_ia}). Avant tout envoi, les noms, adresses électroniques et numéros de téléphone sont remplacés par des marqueurs anonymes ; seules les informations utiles à la réponse sont transmises, et aucune conversation n'est enregistrée sur le serveur.\n\n"
                             . "{organisation} encadre ce recours par contrat (sous-traitance, article 28 du RGPD) et, si le fournisseur traite des données hors de l'Union européenne, par les garanties prévues aux articles 44 et suivants.",
                ];
            } else {
                $s['ia'] = $en ? [
                    'titre' => 'AI assistant',
                    'texte' => "The AI assistant works without any external service: answers are produced by Larka itself or by a model installed on the network of {organisation}. No data leaves that network. Names, email addresses and telephone numbers are in any case replaced with anonymous markers before reaching the model, and no conversation is stored on the server.",
                ] : [
                    'titre' => 'Assistant IA',
                    'texte' => "L'assistant IA fonctionne sans service extérieur : les réponses sont produites par Larka lui-même ou par un modèle installé sur le réseau de {organisation}. Aucune donnée ne quitte ce réseau. Les noms, adresses électroniques et numéros de téléphone sont de toute façon remplacés par des marqueurs anonymes avant d'atteindre le modèle, et aucune conversation n'est enregistrée sur le serveur.",
                ];
            }
        }

        if ($c['sso'] !== []) {
            $s['connexion'] = $en ? [
                'titre' => 'Signing in with an external account',
                'texte' => "If you sign in with your {fournisseurs_sso} account, Larka only receives your identity (name, email address) to open your session. Your password is never sent to Larka.",
            ] : [
                'titre' => 'Connexion avec un compte externe',
                'texte' => "Si vous vous connectez avec votre compte {fournisseurs_sso}, Larka ne reçoit que votre identité (nom, adresse électronique) pour ouvrir votre session. Votre mot de passe ne lui est jamais transmis.",
            ];
        }

        if ($c['push']) {
            $s['notifications'] = $en ? [
                'titre' => 'Notifications',
                'texte' => "If you enable notifications, they are delivered, encrypted, through your browser's notification service. You can turn them off at any time in your browser or in Larka.",
            ] : [
                'titre' => 'Notifications',
                'texte' => "Si vous activez les notifications, elles vous parviennent, chiffrées, par le service de notification de votre navigateur. Vous pouvez les désactiver à tout moment dans le navigateur ou dans Larka.",
            ];
        }

        $s['duree'] = $en ? [
            'titre' => 'How long it is kept',
            'texte' => "- Account: as long as you use it; it is deactivated or deleted when you leave.\n"
                     . "- Requests, work orders and technical history: as long as they are useful for following up the assets, for the periods set by {organisation} (often the life of the equipment, or warranty and limitation periods).\n"
                     . "- Activity logs: {journaux_jours} days, then deleted automatically.\n"
                     . "- Backups: overwritten by the following ones, according to a rotation.",
        ] : [
            'titre' => 'Combien de temps',
            'texte' => "- Compte : tant que vous en avez l'usage ; il est désactivé ou supprimé à votre départ.\n"
                     . "- Demandes, interventions et historique technique : tant qu'ils sont utiles au suivi du patrimoine, selon les durées fixées par {organisation} (souvent la durée de vie de l'équipement, ou les délais de garantie et de prescription).\n"
                     . "- Journaux d'activité : {journaux_jours} jours, puis effacés automatiquement.\n"
                     . "- Sauvegardes : remplacées par les suivantes, selon une rotation.",
        ];

        $s['securite'] = $en ? [
            'titre' => 'How it is protected',
            'texte' => "- passwords stored as a fingerprint (bcrypt), never in clear text;\n"
                     . "- access limited by role, sessions expiring after {session_heures} hours, protection against repeated sign-in attempts;\n"
                     . "- configuration secrets (database passwords, keys) kept out of the database and the code, in a file reserved for the server;\n"
                     . "- regular backups and logging of sensitive actions.",
        ] : [
            'titre' => 'Comment elles sont protégées',
            'texte' => "- mots de passe conservés sous forme d'empreinte (bcrypt), jamais en clair ;\n"
                     . "- accès limités par rôle, sessions expirant après {session_heures} heures, protection contre les tentatives de connexion répétées ;\n"
                     . "- secrets de configuration (mots de passe de base, clés) conservés hors de la base et du code, dans un fichier réservé au serveur ;\n"
                     . "- sauvegardes régulières et journalisation des actions sensibles.",
        ];

        $s['droits'] = $en ? [
            'titre' => 'Your rights',
            'texte' => "You can access the data concerning you, have it corrected or erased, ask for its processing to be restricted or for its portability, and object to its processing on grounds relating to your particular situation (Articles 15 to 21 GDPR). Send your request to {contact}. You will receive an answer within one month.\n\n"
                     . "If, after contacting {organisation}, you consider that your rights are not respected, you can lodge a complaint with the CNIL (www.cnil.fr) or with the supervisory authority of your country.",
        ] : [
            'titre' => 'Vos droits',
            'texte' => "Vous pouvez accéder aux données qui vous concernent, les faire rectifier ou effacer, en demander la limitation ou la portabilité, et vous opposer à leur traitement pour des raisons tenant à votre situation particulière (articles 15 à 21 du RGPD). Adressez votre demande à {contact}. Une réponse vous est apportée dans un délai d'un mois.\n\n"
                     . "Si vous estimez, après avoir contacté {organisation}, que vos droits ne sont pas respectés, vous pouvez adresser une réclamation à la CNIL (www.cnil.fr) ou à l'autorité de contrôle de votre pays.",
        ];

        $s['cookies'] = $en ? [
            'titre' => 'Cookies and local storage',
            'texte' => "Larka only uses a session cookie, strictly necessary for signing in, and your browser's local storage for your display preferences (theme, language, sorting). No advertising tracker and no audience measurement: no consent is therefore requested.",
        ] : [
            'titre' => 'Cookies et stockage local',
            'texte' => "Larka n'utilise qu'un cookie de session, strictement nécessaire à la connexion, et le stockage local de votre navigateur pour vos préférences d'affichage (thème, langue, tri). Ni traceur publicitaire, ni mesure d'audience : aucun consentement n'est donc demandé.",
        ];

        return $s;
    }

    /** Ce que l'installation fait vraiment, pour adapter le modèle. */
    public static function contexte(): array
    {
        $cfg = static fn(string $s, string $k, $d = null) => function_exists('cfg') ? (cfg($s, $k) ?? $d) : $d;
        $f = strtolower((string)($cfg('assistant', 'fournisseur', 'anthropic') ?: 'anthropic'));
        $local = in_array($f, ['ollama', 'lmstudio', 'local'], true);
        $mode = strtolower((string)$cfg('assistant', 'mode', 'fiable')) === 'agent' ? 'agent' : 'fiable';
        $interp = in_array(strtolower((string)$cfg('assistant', 'interpretation_ia', 'off')), ['auto', 'on', 'oui', 'true', '1'], true);
        $ia = filter_var($cfg('assistant', 'actif', false), FILTER_VALIDATE_BOOLEAN);
        $noms = ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'mistral' => 'Mistral AI',
                 'gemini' => 'Google (Gemini)', 'copilot' => 'GitHub Copilot'];
        $sso = [];
        if (filter_var($cfg('microsoft_oauth', 'actif', false), FILTER_VALIDATE_BOOLEAN)) $sso[] = 'Microsoft';
        if (filter_var($cfg('google_oauth', 'actif', false), FILTER_VALIDATE_BOOLEAN)) $sso[] = 'Google';
        return [
            'ia'             => $ia,
            // Le modèle distant n'est appelé qu'en mode agent, ou pour reformuler
            // les questions que le moteur ne comprend pas (interpretation_ia).
            'ia_externe'     => $ia && !$local && ($mode === 'agent' || $interp),
            'fournisseur_ia' => $noms[$f] ?? ucfirst($f),
            'sso'            => $sso,
            'push'           => filter_var($cfg('push', 'actif', true), FILTER_VALIDATE_BOOLEAN)
                                && (string)$cfg('push', 'vapid_public_key', '') !== '',
            'journaux_jours' => max(1, (int)$cfg('logs', 'rotation_jours', 14)),
            'session_heures' => max(1, (int)$cfg('securite', 'session_duree_heures', 24)),
        ];
    }

    // ── Personnalisations (registre Super Admin) ─────────────────────────────

    /** Modèle commun retouché par le Super Admin (ou [] s'il ne l'a pas touché). */
    public static function modeleCommun(): array
    {
        return self::nettoyer(TenantResolver::getRgpd('modele') ?? []);
    }

    /** Personnalisation d'une organisation (ou []). */
    public static function personnalisation(string $cle): array
    {
        return self::nettoyer(TenantResolver::getRgpd('org_' . $cle) ?? []);
    }

    /**
     * Ne garde que ce qui est attendu, borné : coordonnées connues, sections
     * du modèle réécrites, sections ajoutées. Le reste est ignoré.
     */
    public static function nettoyer(array $p): array
    {
        $txt = static function ($v, int $max): string {
            $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
            $v = preg_replace('/[^\P{C}\n\t]/u', '', $v) ?? '';     // caractères de contrôle
            return trim(mb_substr($v, 0, $max));
        };
        $connues = array_keys(self::modele('fr', ['ia' => true, 'ia_externe' => true, 'fournisseur_ia' => '', 'sso' => ['x'],
                                                  'push' => true, 'journaux_jours' => 1, 'session_heures' => 1]));
        $out = ['identite' => [], 'sections' => [], 'ajouts' => []];
        foreach (self::IDENTITE as $k => [$max]) {
            $v = $txt($p['identite'][$k] ?? '', $max);
            if ($v !== '') $out['identite'][$k] = $v;
        }
        foreach ((array)($p['sections'] ?? []) as $id => $sec) {
            if (!in_array($id, $connues, true) || !is_array($sec)) continue;
            $texte = $txt($sec['texte'] ?? '', self::TEXTE_MAX);
            if ($texte === '') continue;
            $out['sections'][$id] = ['texte' => $texte];
            $titre = $txt($sec['titre'] ?? '', self::TITRE_MAX);
            if ($titre !== '') $out['sections'][$id]['titre'] = $titre;
        }
        foreach (array_slice(array_values((array)($p['ajouts'] ?? [])), 0, self::AJOUTS_MAX) as $a) {
            if (!is_array($a)) continue;
            $titre = $txt($a['titre'] ?? '', self::TITRE_MAX);
            $texte = $txt($a['texte'] ?? '', self::TEXTE_MAX);
            if ($titre !== '' && $texte !== '') $out['ajouts'][] = ['titre' => $titre, 'texte' => $texte];
        }
        foreach (['mise_a_jour', 'par'] as $k) {
            if (!empty($p[$k]) && is_string($p[$k])) $out[$k] = mb_substr($p[$k], 0, 60);
        }
        return $out;
    }

    /**
     * Ce qui sera enregistré pour une portée ('modele' ou 'org') : nettoyé, et
     * sans les sections recopiées telles quelles — les garder ferait afficher
     * « Personnalisé par l'entreprise » à tort.
     */
    public static function preparer(string $portee, array $donnees): array
    {
        $d = self::nettoyer($donnees);
        $reference = self::modele('fr');
        if ($portee === 'org') {
            foreach (self::modeleCommun()['sections'] as $sid => $sec) $reference[$sid] = $sec + ($reference[$sid] ?? []);
        }
        $norm = static fn(string $t): string => preg_replace('/\s+/u', ' ', trim($t)) ?? '';
        foreach ($d['sections'] as $sid => $sec) {
            $ref = $reference[$sid] ?? null;
            if ($ref && $norm($sec['texte']) === $norm((string)$ref['texte'])
                && (!isset($sec['titre']) || $norm($sec['titre']) === $norm((string)$ref['titre']))) {
                unset($d['sections'][$sid]);
            }
        }
        unset($d['mise_a_jour'], $d['par']);
        return $d;
    }

    /** Une personnalisation au sens de la mention : texte réécrit ou ajouté. */
    public static function estPersonnalisee(array $p): bool
    {
        return !empty($p['sections']) || !empty($p['ajouts']);
    }

    // ── Politique effective ──────────────────────────────────────────────────

    /**
     * La politique telle qu'elle s'affiche : modèle Larka, puis modèle commun,
     * puis personnalisation de l'organisation (marquée), variables remplies.
     *
     * @param ?string $cle  organisation (null : aucune, avant connexion hors domaine)
     * @param ?array $orgForcee, $communForce  aperçu d'un brouillon non enregistré
     */
    public static function politique(?string $cle, string $langue = 'fr', ?string $nomParDefaut = null,
                                     ?array $orgForcee = null, ?array $communForce = null): array
    {
        $en = $langue === 'en';
        $ctx = self::contexte();
        $modele = self::modele($langue, $ctx);
        $commun = $communForce !== null ? self::nettoyer($communForce) : self::modeleCommun();
        $org = $orgForcee !== null ? self::nettoyer($orgForcee)
             : ($cle !== null ? self::personnalisation($cle) : self::nettoyer([]));

        $id = $org['identite'] + $commun['identite'];
        $organisation = $id['organisation'] ?? ($nomParDefaut ?: ($en ? 'your organisation' : 'votre organisation'));
        $contact = $id['contact'] ?? ($en ? "your organisation's Larka administrator" : "l'administrateur de Larka de votre organisation");
        $vars = [
            '{organisation}'     => $organisation,
            '{contact}'          => $contact,
            '{dpo}'              => $id['dpo'] ?? '',
            '{journaux_jours}'   => (string)$ctx['journaux_jours'],
            '{session_heures}'   => (string)$ctx['session_heures'],
            '{fournisseur_ia}'   => $ctx['fournisseur_ia'],
            '{fournisseurs_sso}' => implode(' / ', $ctx['sso']),
        ];
        // En tête de phrase, « l'organisation qui… » prend la majuscule.
        $majuscule = static fn(string $v): string => mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1);
        $remplir = static function (string $t) use ($vars, $majuscule): string {
            $t = preg_replace_callback('/(^|[.!?]\s+|\n\s*)\{(organisation|contact)\}/u',
                static fn($m) => $m[1] . $majuscule($vars['{' . $m[2] . '}']), $t) ?? $t;
            return strtr($t, $vars);
        };

        $sections = [];
        foreach ($modele as $sid => $sec) {
            // Pas de DPO connu : la section l'explique au lieu d'un trou.
            if ($sid === 'dpo' && empty($id['dpo']) && empty($org['sections']['dpo']) && empty($commun['sections']['dpo'])) {
                $sec['texte'] = $en
                    ? "If {organisation} has appointed a data protection officer (DPO), you can reach them through {contact}."
                    : "Si {organisation} a désigné un délégué à la protection des données (DPO), vous pouvez le joindre par l'intermédiaire de {contact}.";
            }
            $perso = false;
            if (isset($commun['sections'][$sid])) $sec = $commun['sections'][$sid] + $sec;
            if (isset($org['sections'][$sid])) { $sec = $org['sections'][$sid] + $sec; $perso = true; }
            $texte = $remplir($sec['texte']);
            if ($sid === 'responsable') {
                // Coordonnées du responsable, à la suite du texte (celles qui sont connues).
                $lignes = [];
                if (!empty($id['organisation']) || $nomParDefaut) $lignes[] = ($en ? 'Organisation: ' : 'Organisation : ') . $organisation;
                if (!empty($id['adresse'])) $lignes[] = ($en ? 'Address: ' : 'Adresse : ') . $id['adresse'];
                if (!empty($id['contact'])) $lignes[] = ($en ? 'Contact: ' : 'Contact : ') . $id['contact'];
                if ($lignes) $texte .= "\n\n" . implode("\n", $lignes);
            }
            $sections[] = ['id' => $sid, 'titre' => $remplir($sec['titre']), 'texte' => $texte, 'personnalisee' => $perso];
        }
        foreach ($commun['ajouts'] as $i => $a) {
            $sections[] = ['id' => 'commun_' . ($i + 1), 'titre' => $remplir($a['titre']), 'texte' => $remplir($a['texte']), 'personnalisee' => false];
        }
        foreach ($org['ajouts'] as $i => $a) {
            $sections[] = ['id' => 'ajout_' . ($i + 1), 'titre' => $remplir($a['titre']), 'texte' => $remplir($a['texte']), 'personnalisee' => true];
        }

        $dates = array_filter([self::MODELE_DATE, $commun['mise_a_jour'] ?? null, $org['mise_a_jour'] ?? null]);
        rsort($dates);
        $perso = self::estPersonnalisee($org);
        return [
            'titre'            => $en ? 'Personal data protection' : 'Protection des données personnelles',
            'organisation'     => $organisation,
            'mise_a_jour'      => substr((string)reset($dates), 0, 10),
            'personnalisee'    => $perso,
            'personnalisee_le' => $perso ? substr((string)($org['mise_a_jour'] ?? ''), 0, 10) : null,
            'mention'          => $perso
                ? ($en ? "This policy has been customised by {$organisation}. Sections marked “Customised by the organisation” replace or supplement Larka's common model."
                       : "Cette politique a été personnalisée par {$organisation}. Les sections marquées « Personnalisé par l'entreprise » remplacent ou complètent le modèle commun de Larka.")
                : ($en ? "Larka's common policy, not customised by the organisation."
                       : "Politique commune de Larka, non personnalisée par l'organisation."),
            'sections'         => $sections,
        ];
    }

    /** Coordonnées manquantes pour une organisation (à compléter par le Super Admin). */
    public static function manquants(?string $cle): array
    {
        $id = ($cle !== null ? self::personnalisation($cle)['identite'] : []) + self::modeleCommun()['identite'];
        return array_values(array_filter(['organisation', 'contact'], static fn($k) => empty($id[$k])));
    }
}
