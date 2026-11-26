<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote API REST (superviseur de GTB, plateforme de
 * télérelève, supervision de bornes…).
 *
 * Deux façons de lire, au choix de la connexion :
 *   - « liste »    : une requête rend tous les points (tableau JSON) ; chaque
 *                    point est retrouvé par son identifiant. Une requête par
 *                    collecte, quel que soit le nombre de points ;
 *   - « par point » : une requête par point (gabarit d'URL avec {id}).
 * Les alarmes du superviseur peuvent aussi être importées (URL d'alarmes).
 *
 * Authentification : aucune, jeton « Bearer », Basic, ou clé dans un en-tête.
 * Les erreurs passagères (5xx, délai) sont retentées une fois : un superviseur
 * capricieux ne doit pas produire de fausses alertes « injoignable ».
 */

declare(strict_types=1);

final class ConnectPiloteRest extends ConnectPiloteBase
{
    private ?array $cacheListe = null;
    /** Pendant un test lancé depuis l'écran : pas de second essai (la réponse doit venir vite). */
    private bool $sansReessai = false;

    public static function description(): array
    {
        return [
            'protocole' => 'rest',
            'libelle' => 'API REST (JSON)',
            'resume' => 'Superviseurs de GTB récents, plateformes de télérelève, supervisions de bornes de recharge.',
            'collecte' => 'interrogation',
            'port_defaut' => null,
            'intervalle_defaut' => 60,
            'hote' => null,
            'identifiant' => null,
            'champs' => [
                ['cle' => 'url_base', 'libelle' => 'Adresse de l\'API', 'type' => 'texte', 'requis' => true,
                 'aide' => 'Ex. http://10.0.12.5:8080/api/v1'],
                ['cle' => 'auth', 'libelle' => 'Authentification', 'type' => 'choix', 'defaut' => 'aucune',
                 'options' => [['aucune', 'Aucune'], ['bearer', 'Jeton (Authorization: Bearer)'], ['basic', 'Identifiant et mot de passe (Basic)'],
                               ['entete', 'Clé dans un en-tête']]],
                ['cle' => 'entete_cle', 'libelle' => 'Nom de l\'en-tête de la clé', 'type' => 'texte', 'defaut' => 'X-API-Key'],
                ['cle' => 'mode', 'libelle' => 'Lecture', 'type' => 'choix', 'defaut' => 'liste',
                 'options' => [['liste', 'Une requête rend tous les points'], ['par_point', 'Une requête par point']]],
                ['cle' => 'url_liste', 'libelle' => 'Chemin de la liste des points', 'type' => 'texte', 'defaut' => '/points'],
                ['cle' => 'chemin_liste', 'libelle' => 'Chemin du tableau dans la réponse', 'type' => 'texte', 'defaut' => '',
                 'aide' => 'Vide si la réponse est directement un tableau, sinon ex. data.items.'],
                ['cle' => 'cle_id', 'libelle' => 'Champ identifiant d\'un point', 'type' => 'texte', 'defaut' => 'id'],
                ['cle' => 'cle_valeur', 'libelle' => 'Champ valeur', 'type' => 'texte', 'defaut' => 'valeur'],
                ['cle' => 'cle_qualite', 'libelle' => 'Champ qualité (facultatif)', 'type' => 'texte', 'defaut' => 'qualite'],
                ['cle' => 'cle_horodatage', 'libelle' => 'Champ horodatage (facultatif)', 'type' => 'texte', 'defaut' => 'horodatage'],
                ['cle' => 'cle_nom', 'libelle' => 'Champ libellé (découverte)', 'type' => 'texte', 'defaut' => 'nom'],
                ['cle' => 'cle_unite', 'libelle' => 'Champ unité (découverte)', 'type' => 'texte', 'defaut' => 'unite'],
                ['cle' => 'url_point', 'libelle' => 'Gabarit d\'URL d\'un point', 'type' => 'texte', 'defaut' => '/points/{id}'],
                ['cle' => 'ecriture_methode', 'libelle' => 'Méthode d\'écriture', 'type' => 'choix', 'defaut' => 'PUT',
                 'options' => [['PUT', 'PUT'], ['POST', 'POST'], ['PATCH', 'PATCH']]],
                ['cle' => 'ecriture_corps', 'libelle' => 'Corps d\'écriture', 'type' => 'texte', 'defaut' => '{"valeur": {valeur}}'],
                ['cle' => 'url_alarmes', 'libelle' => 'Chemin des alarmes (facultatif)', 'type' => 'texte', 'defaut' => '',
                 'aide' => 'Les alarmes du superviseur deviennent des alarmes Larka (et des demandes si vous le souhaitez).'],
                ['cle' => 'alarmes_vers_demande', 'libelle' => 'Créer une demande pour les alarmes majeures', 'type' => 'booleen', 'defaut' => false],
                ['cle' => 'verifier_certificat', 'libelle' => 'Vérifier le certificat (HTTPS)', 'type' => 'booleen', 'defaut' => true],
                ['cle' => 'delai_secondes', 'libelle' => 'Délai de réponse (s)', 'type' => 'nombre', 'defaut' => 8],
            ],
            'secrets' => [
                ['cle' => 'jeton', 'libelle' => 'Jeton ou clé', 'type' => 'secret'],
                ['cle' => 'utilisateur', 'libelle' => 'Identifiant (Basic)', 'type' => 'texte'],
                ['cle' => 'mot_de_passe', 'libelle' => 'Mot de passe (Basic)', 'type' => 'secret'],
            ],
            'adresse' => [
                ['cle' => 'id', 'libelle' => 'Identifiant du point dans l\'API', 'type' => 'texte', 'requis' => true],
                ['cle' => 'chemin', 'libelle' => 'Chemin de la valeur (facultatif)', 'type' => 'texte',
                 'aide' => 'Vide : le champ valeur de la connexion. « a.0.b » suit les clés et les rangs ; « a.*.b » additionne les b de tous les éléments de a (les trois phases d\'un départ, par exemple).'],
            ],
            'ecriture' => true,
            'decouverte' => true,
        ];
    }

    public function __construct(array $connexion)
    {
        parent::__construct($connexion);
        if ((float)$this->param('delai_secondes', 0) <= 0) $this->delaiReponse = 8.0;
    }

    private function base(): string
    {
        return rtrim(trim((string)$this->param('url_base', '')), '/');
    }

    /** URL complète d'un chemin relatif (ou déjà absolu). */
    private function url(string $chemin): string
    {
        if (preg_match('#^https?://#i', $chemin)) return $chemin;
        return $this->base() . '/' . ltrim($chemin, '/');
    }

    private function entetes(): array
    {
        $h = ['Accept: application/json', 'User-Agent: Larka-Connect/1'];
        $auth = (string)$this->param('auth', 'aucune');
        if ($auth === 'bearer' && $this->secretVal('jeton') !== '') $h[] = 'Authorization: Bearer ' . $this->secretVal('jeton');
        if ($auth === 'basic') $h[] = 'Authorization: Basic ' . base64_encode($this->secretVal('utilisateur') . ':' . $this->secretVal('mot_de_passe'));
        if ($auth === 'entete' && $this->secretVal('jeton') !== '') {
            $nom = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$this->param('entete_cle', 'X-API-Key')) ?: 'X-API-Key';
            $h[] = $nom . ': ' . $this->secretVal('jeton');
        }
        return $h;
    }

    /**
     * Requête HTTP. @return array{code: int, corps: string, erreur: ?string, ms: float}
     * Une erreur passagère (5xx, délai, connexion) est retentée une fois si $reessayer.
     */
    public function http(string $methode, string $url, ?string $corps = null, bool $avecAuth = true, bool $reessayer = true): array
    {
        if (!preg_match('~^https?://[^\s/?#]+~i', $url)) {
            return ['code' => 0, 'corps' => '', 'erreur' => 'adresse de l\'API invalide (http://… ou https://…)', 'ms' => 0.0];
        }
        $essais = ($reessayer && !$this->sansReessai) ? 2 : 1;
        $r = null;
        for ($i = 0; $i < $essais; $i++) {
            $r = $this->httpUneFois($methode, $url, $corps, $avecAuth);
            if ($r['erreur'] === null && $r['code'] < 500) return $r;
            if ($i + 1 < $essais) usleep(300000);
        }
        return $r;
    }

    private function httpUneFois(string $methode, string $url, ?string $corps, bool $avecAuth): array
    {
        $t0 = microtime(true);
        $h = $avecAuth ? $this->entetes() : ['Accept: application/json', 'User-Agent: Larka-Connect/1'];
        if ($corps !== null) $h[] = 'Content-Type: application/json';
        $verif = filter_var($this->param('verifier_certificat', true), FILTER_VALIDATE_BOOLEAN);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
                CURLOPT_CONNECTTIMEOUT => (int)ceil($this->delaiConnexion), CURLOPT_TIMEOUT => (int)ceil($this->delaiReponse),
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => $verif, CURLOPT_SSL_VERIFYHOST => $verif ? 2 : 0,
                // Équipements du réseau interne : jamais par un mandataire (proxy) Internet.
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            ]);
            if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
            $rep = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $rep === false ? (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? 'délai de réponse dépassé' : curl_error($ch)) : null;
            return ['code' => $code, 'corps' => $rep === false ? '' : (string)$rep, 'erreur' => $err, 'ms' => self::ms($t0)];
        }
        $ctx = stream_context_create([
            'http' => ['method' => $methode, 'header' => implode("\r\n", $h), 'content' => $corps ?? '',
                       'timeout' => $this->delaiReponse, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => $verif, 'verify_peer_name' => $verif],
        ]);
        $rep = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $l) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) $code = (int)$m[1];
        return ['code' => $code, 'corps' => $rep === false ? '' : $rep,
                'erreur' => $rep === false ? 'connexion impossible ou délai dépassé' : null, 'ms' => self::ms($t0)];
    }

    private static function messageHttp(array $r): string
    {
        if ($r['erreur'] !== null) return $r['erreur'];
        return match (true) {
            $r['code'] === 401 => 'HTTP 401 : authentification refusée (jeton ou mot de passe)',
            $r['code'] === 403 => 'HTTP 403 : accès refusé à ce jeton',
            $r['code'] === 404 => 'HTTP 404 : adresse inconnue de l\'API',
            $r['code'] === 422 => 'HTTP 422 : valeur refusée par l\'API',
            $r['code'] === 503 => 'HTTP 503 : superviseur indisponible (maintenance ?)',
            $r['code'] >= 500  => "HTTP {$r['code']} : erreur du superviseur",
            default            => "HTTP {$r['code']}",
        };
    }

    /** Liste des points de l'API (mode liste), en cache le temps d'une collecte. */
    private function liste(): array
    {
        if ($this->cacheListe !== null) return $this->cacheListe;
        $r = $this->http('GET', $this->url((string)$this->param('url_liste', '/points')));
        if ($r['erreur'] !== null || $r['code'] >= 300) return $this->cacheListe = ['erreur' => self::messageHttp($r)];
        $doc = json_decode($r['corps'], true);
        if (!is_array($doc)) return $this->cacheListe = ['erreur' => 'réponse qui n\'est pas du JSON'];
        $t = self::suivreChemin($doc, (string)$this->param('chemin_liste', ''));
        if (!is_array($t)) return $this->cacheListe = ['erreur' => 'tableau des points introuvable dans la réponse'];
        $cle = (string)$this->param('cle_id', 'id');
        $parId = [];
        foreach ($t as $k => $el) {
            if (!is_array($el)) continue;
            $id = array_key_exists($cle, $el) ? (string)$el[$cle] : (string)$k;
            $parId[$id] = $el;
        }
        return $this->cacheListe = ['points' => $parId];
    }

    private function versValeur($el, array $adresse): array
    {
        $chemin = trim((string)($adresse['chemin'] ?? '')) ?: (string)$this->param('cle_valeur', 'valeur');
        $brut = is_array($el) ? self::sommeSiListe(self::suivreChemin($el, $chemin)) : $el;
        if (is_bool($brut)) $brut = $brut ? 1 : 0;
        $v = self::nombre(is_scalar($brut) ? $brut : null);
        $txt = ($v === null && is_scalar($brut)) ? (string)$brut : null;
        $qualite = 'bonne';
        $cq = (string)$this->param('cle_qualite', 'qualite');
        if (is_array($el) && $cq !== '' && array_key_exists($cq, $el)) {
            $q = strtolower((string)$el[$cq]);
            if (!in_array($q, ['bonne', 'good', 'ok', 'valid', 'valide', '1', 'true', ''], true)) $qualite = 'mauvaise';
        }
        if ($v === null && $txt === null) $qualite = 'mauvaise';
        $horo = null;
        $ch = (string)$this->param('cle_horodatage', 'horodatage');
        if (is_array($el) && $ch !== '' && isset($el[$ch]) && is_string($el[$ch]) && ($t = strtotime($el[$ch])) !== false) {
            $horo = date('Y-m-d H:i:s', $t);
        }
        $r = self::valeurLue($v, $txt, $qualite, $horo);
        if ($qualite === 'mauvaise') $r['erreur'] = 'qualité signalée mauvaise par le superviseur';
        return $r;
    }

    public function lire(array $points): array
    {
        $out = [];
        $mode = (string)$this->param('mode', 'liste');
        if ($mode === 'liste') {
            $l = $this->liste();
            foreach ($points as $p) {
                $a = is_array($p['Adresse'] ?? null) ? $p['Adresse'] : [];
                if (isset($l['erreur'])) { $out[(int)$p['Id']] = self::enDefaut($l['erreur']); continue; }
                $id = (string)($a['id'] ?? '');
                $out[(int)$p['Id']] = array_key_exists($id, $l['points'])
                    ? $this->versValeur($l['points'][$id], $a)
                    : self::enDefaut("point « $id » absent de la liste de l'API");
            }
            return $out;
        }
        $gabarit = (string)$this->param('url_point', '/points/{id}');
        $deja = [];                                   // même adresse pour plusieurs points : une seule requête
        foreach ($points as $p) {
            $a = is_array($p['Adresse'] ?? null) ? $p['Adresse'] : [];
            $u = $this->url(str_replace('{id}', rawurlencode((string)($a['id'] ?? '')), $gabarit));
            $r = $deja[$u] ??= $this->http('GET', $u);
            if ($r['erreur'] !== null || $r['code'] >= 300) { $out[(int)$p['Id']] = self::enDefaut(self::messageHttp($r)); continue; }
            $doc = json_decode($r['corps'], true);
            $out[(int)$p['Id']] = $doc === null && trim($r['corps']) !== 'null'
                ? self::enDefaut('réponse qui n\'est pas du JSON') : $this->versValeur($doc, $a);
        }
        return $out;
    }

    /**
     * Alarmes actives du superviseur, normalisées :
     * [['code','message','gravite','equipement'], …] ou null si non configuré / illisible.
     */
    public function alarmes(): ?array
    {
        $chemin = trim((string)$this->param('url_alarmes', ''));
        if ($chemin === '') return null;
        $r = $this->http('GET', $this->url($chemin));
        if ($r['erreur'] !== null || $r['code'] >= 300) return null;
        $doc = json_decode($r['corps'], true);
        if (!is_array($doc)) return null;
        $out = [];
        foreach ($doc as $a) {
            if (!is_array($a)) continue;
            $out[] = [
                'code' => mb_substr((string)($a['code'] ?? $a['id'] ?? ''), 0, 80),
                'message' => mb_substr((string)($a['message'] ?? $a['libelle'] ?? $a['description'] ?? 'Alarme'), 0, 300),
                'gravite' => (string)($a['gravite'] ?? $a['severity'] ?? 'mineure'),
                'equipement' => mb_substr((string)($a['equipement'] ?? $a['equipment'] ?? ''), 0, 80),
            ];
        }
        return $out;
    }

    public function ecrire(array $point, $valeur): array
    {
        $a = is_array($point['Adresse'] ?? null) ? $point['Adresse'] : [];
        $v = self::nombre($valeur);
        if ($v === null) return ['ok' => false, 'message' => 'Valeur non numérique.'];
        $coef = (float)($point['Coefficient'] ?? 1) ?: 1.0;
        $brut = ($v - (float)($point['Decalage'] ?? 0)) / $coef;
        $txt = rtrim(rtrim(number_format($brut, 6, '.', ''), '0'), '.');
        $corps = str_replace('{valeur}', $txt, (string)$this->param('ecriture_corps', '{"valeur": {valeur}}'));
        $corps = str_replace('{id}', addslashes((string)($a['id'] ?? '')), $corps);
        if (json_decode($corps) === null) return ['ok' => false, 'message' => 'Le corps d\'écriture n\'est pas du JSON valide.'];
        $m = strtoupper((string)$this->param('ecriture_methode', 'PUT'));
        if (!in_array($m, ['PUT', 'POST', 'PATCH'], true)) $m = 'PUT';
        $url = $this->url(str_replace('{id}', rawurlencode((string)($a['id'] ?? '')), (string)$this->param('url_point', '/points/{id}')));
        $r = $this->http($m, $url, $corps, true, false);
        if ($r['erreur'] !== null || $r['code'] >= 300) {
            $detail = '';
            $doc = json_decode($r['corps'], true);
            if (is_array($doc)) $detail = (string)($doc['erreur'] ?? $doc['error'] ?? $doc['message'] ?? '');
            return ['ok' => false, 'message' => 'Écriture refusée : ' . self::messageHttp($r) . ($detail !== '' ? " — $detail" : '')];
        }
        return ['ok' => true, 'message' => "Valeur écrite (HTTP {$r['code']})."];
    }

    public function tester(array $points = []): array
    {
        $this->sansReessai = true;
        $etapes = [];
        $base = $this->base();
        $u = parse_url($base);
        if (!$u || empty($u['host'])) return [self::etape('reseau', false, 'Adresse de l\'API non renseignée ou invalide.')];
        $port = (int)($u['port'] ?? (strtolower($u['scheme'] ?? '') === 'https' ? 443 : 80));
        [$f, $etape] = $this->ouvrirTcp($u['host'], $port);
        $etapes[] = $etape;
        if (!$f) return $etapes;
        fclose($f);
        $urlListe = $this->url((string)$this->param('url_liste', '/points'));
        if ((string)$this->param('auth', 'aucune') !== 'aucune') {
            $sans = $this->http('GET', $urlListe, null, false, false);
            if ($sans['code'] === 401 || $sans['code'] === 403) {
                $etapes[] = self::etape('protocole', true, "L'API répond et exige bien une authentification (HTTP {$sans['code']} sans jeton).", $sans['ms']);
            }
        }
        $this->cacheListe = null;
        if ((string)$this->param('mode', 'liste') === 'liste') {
            $r = $this->http('GET', $urlListe);
            if ($r['erreur'] !== null || $r['code'] >= 300) {
                $etapes[] = self::etape(in_array($r['code'], [401, 403], true) ? 'droits' : 'protocole', false, self::messageHttp($r) . '.', $r['ms']);
                return $etapes;
            }
            $l = $this->liste();
            if (isset($l['erreur'])) { $etapes[] = self::etape('protocole', false, ucfirst($l['erreur']) . '.', $r['ms']); return $etapes; }
            $etapes[] = self::etape('droits', true, 'Accès accordé : ' . count($l['points']) . ' point(s) publiés par l\'API.', $r['ms']);
        }
        if ($points) {
            $res = $this->lire($points);
            $ok = count(array_filter($res, fn($x) => $x['valeur'] !== null || ($x['texte'] ?? null) !== null));
            $ko = count($res) - $ok;
            $det = [];
            foreach ($points as $p) {
                $x = $res[(int)$p['Id']] ?? null;
                if ($x && $x['valeur'] === null && ($x['texte'] ?? null) === null) $det[] = "« {$p['Nom']} » : " . ($x['erreur'] ?? 'illisible');
            }
            $etapes[] = self::etape('points', $ko === 0, "$ok point(s) lu(s)" . ($ko ? ", $ko en échec — " . implode(' ; ', array_slice($det, 0, 3)) : '.'));
        }
        return $etapes;
    }

    public function decouvrir(): array
    {
        $this->cacheListe = null;
        $l = $this->liste();
        if (isset($l['erreur'])) return [];
        $cn = (string)$this->param('cle_nom', 'nom'); $cu = (string)$this->param('cle_unite', 'unite');
        $props = [];
        foreach ($l['points'] as $id => $el) {
            $v = $this->versValeur($el, []);
            $nom = (string)($el[$cn] ?? $id);
            $ecr = !empty($el['ecriture']) || !empty($el['writable']);
            $nature = $ecr ? 'consigne' : (preg_match('/d[ée]faut|alarm|fault/iu', $nom . $id) ? 'alarme'
                    : (preg_match('/heure|d[ée]marrage|index|compteur/iu', $nom . $id) ? 'compteur'
                    : (is_bool($el[(string)$this->param('cle_valeur', 'valeur')] ?? null) ? 'etat' : 'mesure')));
            $props[] = ['nom' => $nom, 'code' => (string)$id, 'adresse' => ['id' => (string)$id],
                        'unite' => (string)($el[$cu] ?? ''), 'nature' => $nature, 'valeur' => $v['valeur'] ?? $v['texte'],
                        'ecriture' => $ecr];
        }
        return $props;
    }
}
