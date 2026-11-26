<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Confirmation d'identité (« retaper son mot de passe »).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Agir sur un équipement, télécharger un collecteur, appairer ou délier le
 * serveur de supervision : ces gestes exigent une identité confirmée depuis
 * moins de X minutes. X est réglé par le gestionnaire (5 min à 8 h) ; une
 * session ouverte et oubliée, ou un cookie de session volé, ne suffit donc pas.
 *
 * Trois façons de confirmer, selon le compte :
 *   - compte local      : le mot de passe Larka (routes/connect.php) ;
 *   - Microsoft, Google : une fenêtre du fournisseur qui REDEMANDE le mot de
 *                         passe, revenue sur les mêmes adresses de retour que
 *                         la connexion (oauth/microsoft.php, oauth/google.php) —
 *                         aucune adresse de plus à déclarer chez eux.
 *       · Microsoft : prompt=login (Entra redemande toujours le mot de passe).
 *         L'heure d'authentification (auth_time) n'est dans l'id_token que si
 *         l'application la demande en revendication facultative ; présente,
 *         elle est vérifiée, absente on se fie à prompt=login, que le serveur
 *         pose lui-même dans l'adresse.
 *       · Google : ne connaît pas prompt=login. On demande max_age=0 et
 *         l'heure d'authentification, et on l'EXIGE : sans elle, rien ne
 *         prouve que Google a redemandé le mot de passe. Elle suppose l'option
 *         « Session age claims » du projet (Google Auth Platform → Paramètres →
 *         Paramètres avancés).
 *     Avant, un compte Microsoft/Google devait se déconnecter et se
 *     reconnecter, puis cocher une case : la case seule ne prouvait rien.
 *
 * Le flux Microsoft/Google est lié :
 *   - à CE navigateur (cookie GMAO_OAUTH, comme la connexion) ;
 *   - à CE compte : l'adresse vérifiée revenue du fournisseur doit être
 *     celle du compte connecté, sinon refus (on ne confirme pas Paul avec le
 *     compte Microsoft de Jeanne) ;
 *   - à CE moment : si le fournisseur donne l'heure de l'authentification
 *     (auth_time), elle doit être postérieure au départ du flux.
 *
 * Le réglage vit dans la table Configuration sous une clé « securite_… »,
 * que la route générique « configuration » refuse d'écrire : sans quoi on
 * pourrait l'allonger sans confirmer son identité.
 */

final class IdentiteConfirmee
{
    /** Durées proposées, en minutes (liste fermée). */
    public const DUREES  = [5, 15, 30, 60, 120, 240, 480];
    public const DEFAUT  = 5;
    public const CLE     = 'securite_identite_minutes';
    /** Préfixe des états OAuth d'une confirmation (« ra » : re-authentification). */
    public const PREFIXE = 'ra_';

    /** Durée de validité réglée (minutes). */
    public static function minutes(Database $db): int
    {
        try { $v = (int)$db->getConfig(self::CLE); } catch (\Throwable $e) { $v = 0; }
        return in_array($v, self::DUREES, true) ? $v : self::DEFAUT;
    }

    /** Le gestionnaire a-t-il choisi une durée (ou est-ce le défaut) ? */
    public static function estReglee(Database $db): bool
    {
        try { return in_array((int)$db->getConfig(self::CLE), self::DUREES, true); } catch (\Throwable $e) { return false; }
    }

    public static function regler(Database $db, int $minutes): void
    {
        if (!in_array($minutes, self::DUREES, true)) throw new InvalidArgumentException('Durée non proposée.');
        $db->setConfig(self::CLE, (string)$minutes);
    }

    /** Secondes de confirmation qu'il reste à la session. */
    public static function restant(): int
    {
        return max(0, (int)($_SESSION['connect_elevation'] ?? 0) - time());
    }

    /** Pose la confirmation pour $secondes (la session en cours). */
    public static function accorder(int $secondes): int
    {
        $s = max(60, min(max(self::DUREES) * 60, $secondes));
        $_SESSION['connect_elevation'] = time() + $s;
        $_SESSION['_authentifie_le'] = time();
        return $s;
    }

    /**
     * Plafond accepté par un serveur de supervision pour la confirmation que
     * lui transmet le serveur principal : sa propre durée si son gestionnaire
     * en a choisi une, sinon la plus longue proposée (le principal décide).
     */
    public static function plafondDistant(Database $db): int
    {
        return (self::estReglee($db) ? self::minutes($db) : max(self::DUREES)) * 60;
    }

    // ── Comptes Microsoft / Google ──────────────────────────────────────────

    /** Fournisseur du compte : local, microsoft ou google. */
    public static function fournisseur(array $user): string
    {
        $p = strtolower((string)($user['Provider'] ?? 'local')) ?: 'local';
        return in_array($p, ['microsoft', 'google'], true) ? $p : 'local';
    }

    /**
     * Adresse de la fenêtre du fournisseur qui redemande le mot de passe.
     * L'état écrit sur le disque porte le compte attendu, l'heure de départ et
     * la durée à accorder (lue ICI, dans la base de l'organisation de la session).
     */
    public static function urlSso(array $user, Database $db): string
    {
        $f = self::fournisseur($user);
        if ($f === 'local') throw new RuntimeException('Ce compte se confirme avec son mot de passe Larka.');
        if ($f === 'microsoft' && !(defined('MICROSOFT_ENABLED') && MICROSOFT_ENABLED && MICROSOFT_CLIENT_ID !== '')) {
            throw new RuntimeException('La connexion Microsoft n\'est plus activée sur ce serveur : demandez à l\'administrateur.');
        }
        if ($f === 'google' && !(defined('GOOGLE_ENABLED') && GOOGLE_ENABLED && GOOGLE_CLIENT_ID !== '')) {
            throw new RuntimeException('La connexion Google n\'est plus activée sur ce serveur : demandez à l\'administrateur.');
        }
        $stateDir = dirname(__DIR__) . '/data/oauth_states';
        if (!oauth_etat_autorise($stateDir)) throw new RuntimeException('Trop de tentatives. Réessayez dans quelques minutes.');
        $state = self::PREFIXE . bin2hex(random_bytes(16));
        $nonce = oauth_nonce_emettre();
        $courriel = (string)($user['Email'] ?? '') ?: (string)($user['Login'] ?? '');
        file_put_contents($stateDir . '/' . hash('sha256', $state) . '.json', json_encode([
            'state' => $state, 'ts' => time(), 'nonce' => $nonce,
            'reauth' => [
                'fournisseur' => $f,
                'login'       => (string)($user['Login'] ?? ''),
                'courriel'    => $courriel,
                'debut'       => time(),
                'secondes'    => self::minutes($db) * 60,
                'tenant'      => (string)($_SESSION['tenant_key'] ?? ''),
            ],
        ]));
        if ($f === 'microsoft') {
            return 'https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/authorize?' . http_build_query([
                'client_id'     => MICROSOFT_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri'  => MICROSOFT_REDIRECT_URI,
                // Juste de quoi relire l'adresse vérifiée : pas les jetons SharePoint ni
                // présence, que la session garde de sa connexion.
                'scope'         => 'openid profile email User.Read',
                'response_mode' => 'query',
                'state'         => $state,
                'prompt'        => 'login',
                'max_age'       => 0,
                'login_hint'    => $courriel,
            ]);
        }
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
            'max_age'       => 0,
            'login_hint'    => $courriel,
            // Google ne met l'heure de l'authentification dans l'id_token que si on la demande.
            'claims'        => json_encode(['id_token' => ['auth_time' => ['essential' => true]]]),
        ]);
    }

    /** Revendications d'un id_token reçu directement du fournisseur (TLS) : lecture seule. */
    public static function revendications(string $jwt): array
    {
        $p = explode('.', $jwt);
        if (count($p) < 2) return [];
        $b = strtr($p[1], '-_', '+/');
        $b .= str_repeat('=', (4 - strlen($b) % 4) % 4);
        $c = json_decode((string)base64_decode($b, true), true);
        return is_array($c) ? $c : [];
    }

    /**
     * Fin du flux, dans oauth/microsoft.php ou oauth/google.php : le compte
     * revenu est-il celui de la session, et l'authentification est-elle fraîche ?
     * Renvoie [true, secondes] ou [false, message].
     */
    public static function conclure(array $etat, string $fournisseur, string $courriel, array $revendications): array
    {
        $ra = is_array($etat['reauth'] ?? null) ? $etat['reauth'] : null;
        if (!$ra || ($ra['fournisseur'] ?? '') !== $fournisseur) return [false, 'Confirmation invalide : recommencez depuis Larka.'];
        $u = $_SESSION['user'] ?? null;
        if (!is_array($u) || strcasecmp((string)($u['Login'] ?? ''), (string)$ra['login']) !== 0) {
            return [false, 'La session Larka a changé pendant la confirmation : recommencez.'];
        }
        if ((string)($_SESSION['tenant_key'] ?? '') !== (string)($ra['tenant'] ?? '')) {
            return [false, 'La session Larka a changé pendant la confirmation : recommencez.'];
        }
        $attendu = strtolower(trim((string)$ra['courriel']));
        $recu    = strtolower(trim($courriel));
        if ($recu === '' || ($recu !== $attendu && $recu !== strtolower((string)($u['Login'] ?? '')))) {
            return [false, 'Ce n\'est pas le compte connecté à Larka : confirmez avec ' . ($ra['courriel'] ?: 'votre propre compte') . '.'];
        }
        // auth_time : heure à laquelle le fournisseur a VRAIMENT demandé le mot de
        // passe (voir l'en-tête : exigée chez Google, vérifiée si présente chez Microsoft).
        $at = (int)($revendications['auth_time'] ?? 0);
        if ($at <= 0 && $fournisseur === 'google') {
            return [false, 'Google n\'indique pas quand il a demandé le mot de passe : l\'administrateur doit activer '
                         . '« Session age claims » (Google Auth Platform → Paramètres → Paramètres avancés).'];
        }
        if ($at > 0 && $at < (int)$ra['debut'] - 60) {
            return [false, ($fournisseur === 'google' ? 'Google' : 'Microsoft') . ' n\'a pas redemandé votre mot de passe : '
                         . 'dans sa fenêtre, déconnectez-vous puis reconnectez-vous, et recommencez.'];
        }
        return [true, self::accorder((int)($ra['secondes'] ?? self::DEFAUT * 60))];
    }

    /** Page de la fenêtre : prévient Larka (même origine) puis se ferme. */
    public static function page(bool $ok, string $message): string
    {
        $charge = json_encode(['type' => 'LARKA_IDENTITE', 'ok' => $ok, 'message' => $message],
                              JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $titre = $ok ? 'Identité confirmée' : 'Confirmation refusée';
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>' . $titre . ' — Larka</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>body{font-family:system-ui,sans-serif;background:#0d2137;color:#e2eaf4;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.c{background:rgba(15,32,56,.9);border-radius:16px;padding:32px 36px;max-width:360px;text-align:center}h1{font-size:18px;margin:0 0 8px}p{margin:0;color:#a9bbd0;font-size:14px}</style></head>'
            . '<body><div class="c"><h1>' . htmlspecialchars($titre) . '</h1><p>' . htmlspecialchars($message) . '</p></div>'
            . '<script>(function(){var m=' . $charge . ';try{if(window.opener)window.opener.postMessage(m,window.location.origin);}catch(e){}'
            . 'setTimeout(function(){window.close();},' . ($ok ? 900 : 4000) . ');})();</script></body></html>';
    }
}
