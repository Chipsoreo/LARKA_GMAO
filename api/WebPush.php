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
 * Larka — WebPush : envoi de notifications push (pure PHP, sans lib externe)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Implémente le protocole Web Push (RFC 8030 + RFC 8291 + RFC 8292) :
 *   - VAPID (Voluntary Application Server Identification) — JWT ES256
 *   - Chiffrement du payload (ECDH + HKDF + AES-128-GCM, encoding aes128gcm)
 *
 * PRÉREQUIS PHP :
 *   - ext-openssl (pour ECDSA, ECDH, AES-GCM)
 *   - PHP 7.4+ (openssl_pkey_derive, hash_hkdf)
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class WebPush {

    private string $vapidPem;        // Contenu du fichier PEM (clé privée EC)
    private string $vapidPublicB64;  // Clé publique VAPID en base64url (65 bytes uncompressed)
    private string $vapidSubject;    // mailto: ou https://

    /** DER header pour une clé publique EC P-256 non compressée (26 octets) */
    private const EC_P256_DER_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    public function __construct(string $vapidPemContent, string $vapidPublicKeyB64url, string $subject = 'mailto:admin@localhost') {
        $this->vapidPem       = $vapidPemContent;
        $this->vapidPublicB64 = $vapidPublicKeyB64url;
        $this->vapidSubject   = $subject;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  API PUBLIQUE
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Envoie une notification push.
     *
     * @param array  $subscription  ['endpoint'=>..., 'keys'=>['p256dh'=>..., 'auth'=>...]]
     * @param string $payload       JSON du contenu de la notification
     * @param int    $ttl           Durée de vie en secondes (défaut 4h)
     * @return array ['success'=>bool, 'statusCode'=>int, 'reason'=>string]
     */
    public function send(array $subscription, string $payload, int $ttl = 14400): array {
        $endpoint = $subscription['endpoint'] ?? '';
        $p256dh   = $subscription['keys']['p256dh'] ?? '';
        $auth     = $subscription['keys']['auth']   ?? '';

        if (!$endpoint || !$p256dh || !$auth) {
            return ['success' => false, 'statusCode' => 0, 'reason' => 'Subscription incomplète'];
        }

        // 1. Chiffrer le payload (RFC 8291)
        $encrypted = $this->encryptPayload($payload, $p256dh, $auth);
        if ($encrypted === false) {
            return ['success' => false, 'statusCode' => 0, 'reason' => 'Erreur de chiffrement du payload'];
        }

        // 2. Générer les en-têtes VAPID (RFC 8292)
        $audience = $this->getAudience($endpoint);
        try {
            $vapidAuth = $this->createVapidAuth($audience);
        } catch (\Throwable $e) {
            return ['success' => false, 'statusCode' => 0, 'reason' => 'Erreur VAPID : ' . $e->getMessage()];
        }

        // 3. Envoyer la requête HTTP POST au push service
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($encrypted),
            'TTL: ' . $ttl,
            'Urgency: normal',
            'Authorization: vapid t=' . $vapidAuth['jwt'] . ', k=' . $vapidAuth['key'],
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encrypted,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'statusCode' => 0, 'reason' => 'cURL: ' . $curlError];
        }

        $success = ($statusCode >= 200 && $statusCode < 300);

        return [
            'success'    => $success,
            'statusCode' => $statusCode,
            'reason'     => $success ? 'OK' : "HTTP $statusCode — " . substr($response, 0, 300),
        ];
    }

    /**
     * Envoie un lot de notifications EN PARALLÈLE via curl_multi.
     *
     * ⚠️ FIX PERFS : `send()` séquentielle prend ~30s en cas de timeout sur
     * un seul push service lent — multiplié par N abonnés, on dépasse vite
     * `max_execution_time`. Avec curl_multi, tous les push partent ensemble
     * et on récupère les réponses au fur et à mesure : le total équivaut à
     * la requête la plus lente, pas à la somme.
     *
     * @param array  $subscriptions Liste de souscriptions au format
     *                              [['endpoint'=>..., 'keys'=>['p256dh'=>..., 'auth'=>...]], ...]
     * @param string $payload       JSON du contenu de la notification (commun à toutes)
     * @param int    $ttl           Durée de vie en secondes
     * @param int    $maxConcurrent Limite de connexions simultanées (10 par défaut)
     * @return array Tableau parallèle avec, pour chaque sub :
     *               ['success'=>bool, 'statusCode'=>int, 'reason'=>string, 'endpoint'=>string]
     */
    public function sendBatch(array $subscriptions, string $payload, int $ttl = 14400, int $maxConcurrent = 10): array {
        $results = [];
        if (empty($subscriptions)) return $results;

        // Pré-calculer le payload chiffré et les en-têtes pour chaque souscription.
        // Le chiffrement utilise des éphémères propres à chaque endpoint.
        $prepared = [];
        foreach ($subscriptions as $i => $sub) {
            $endpoint = $sub['endpoint'] ?? '';
            $p256dh   = $sub['keys']['p256dh'] ?? '';
            $auth     = $sub['keys']['auth']   ?? '';

            if (!$endpoint || !$p256dh || !$auth) {
                $results[$i] = ['success' => false, 'statusCode' => 0, 'reason' => 'Subscription incomplète', 'endpoint' => $endpoint];
                continue;
            }

            $encrypted = $this->encryptPayload($payload, $p256dh, $auth);
            if ($encrypted === false) {
                $results[$i] = ['success' => false, 'statusCode' => 0, 'reason' => 'Erreur de chiffrement du payload', 'endpoint' => $endpoint];
                continue;
            }

            try {
                $vapidAuth = $this->createVapidAuth($this->getAudience($endpoint));
            } catch (\Throwable $e) {
                $results[$i] = ['success' => false, 'statusCode' => 0, 'reason' => 'Erreur VAPID : ' . $e->getMessage(), 'endpoint' => $endpoint];
                continue;
            }

            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Content-Length: ' . strlen($encrypted),
                'TTL: ' . $ttl,
                'Urgency: normal',
                'Authorization: vapid t=' . $vapidAuth['jwt'] . ', k=' . $vapidAuth['key'],
            ];

            $prepared[$i] = [
                'endpoint'  => $endpoint,
                'encrypted' => $encrypted,
                'headers'   => $headers,
            ];
        }

        if (empty($prepared)) return $results;

        // Exécuter par "fenêtres" de $maxConcurrent — sinon on peut épuiser
        // les FD sur un envoi massif.
        $chunks = array_chunk($prepared, max(1, $maxConcurrent), true);

        foreach ($chunks as $chunk) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($chunk as $i => $req) {
                $ch = curl_init($req['endpoint']);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $req['encrypted'],
                    CURLOPT_HTTPHEADER     => $req['headers'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[$i] = $ch;
            }

            // Boucle d'exécution non bloquante
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) curl_multi_select($multi, 1.0);
            } while ($running > 0 && $status === CURLM_OK);

            // Collecter les réponses
            foreach ($handles as $i => $ch) {
                $response   = curl_multi_getcontent($ch);
                $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError  = curl_error($ch);
                $endpoint   = $prepared[$i]['endpoint'];

                if ($curlError) {
                    $results[$i] = ['success' => false, 'statusCode' => 0, 'reason' => 'cURL: ' . $curlError, 'endpoint' => $endpoint];
                } else {
                    $success = ($statusCode >= 200 && $statusCode < 300);
                    $results[$i] = [
                        'success'    => $success,
                        'statusCode' => $statusCode,
                        'reason'     => $success ? 'OK' : "HTTP $statusCode — " . substr((string)$response, 0, 300),
                        'endpoint'   => $endpoint,
                    ];
                }

                curl_multi_remove_handle($multi, $ch);
            }

            curl_multi_close($multi);
        }

        // Re-tri par ordre original des subscriptions
        ksort($results);
        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  VAPID JWT (RFC 8292)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Crée le JWT VAPID signé et retourne ['jwt' => ..., 'key' => ...]
     */
    private function createVapidAuth(string $audience): array {
        // Charger la clé privée EC
        $key = openssl_pkey_get_private($this->vapidPem);
        if (!$key) {
            $err = '';
            while ($e = openssl_error_string()) $err .= $e . '; ';
            throw new \RuntimeException('Impossible de charger la clé VAPID PEM: ' . $err);
        }

        // Extraire la clé publique directement de la clé privée pour garantir la cohérence
        $details = openssl_pkey_get_details($key);
        if (!$details || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('La clé VAPID n\'est pas une clé EC valide');
        }

        // Reconstruire la clé publique raw (65 bytes) depuis les coordonnées
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $pubKeyRaw = "\x04" . $x . $y;
        $pubKeyB64url = self::b64url($pubKeyRaw);

        // Construire le JWT — ne PAS inclure "typ" (certains push services le rejettent)
        $header  = self::b64url(json_encode(['alg' => 'ES256']));
        $claims  = self::b64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 7200, // 2h (certains push services rejettent > 12h)
            'sub' => $this->vapidSubject,
        ]));

        $dataToSign = $header . '.' . $claims;

        // Signer avec ECDSA P-256 (ES256)
        $signed = openssl_sign($dataToSign, $derSig, $key, OPENSSL_ALGO_SHA256);
        if (!$signed || empty($derSig)) {
            throw new \RuntimeException('Échec signature ECDSA');
        }

        // Convertir la signature DER en format raw R||S (64 octets)
        $rawSig = self::derSignatureToRaw($derSig);

        // Vérifier que la signature fait bien 64 octets
        if (strlen($rawSig) !== 64) {
            throw new \RuntimeException('Signature raw invalide : ' . strlen($rawSig) . ' octets au lieu de 64');
        }

        // Auto-vérification : s'assurer que la signature est valide
        $pubKeyPem = self::rawPublicKeyToPem($pubKeyRaw);
        $pubKeyRes = openssl_pkey_get_public($pubKeyPem);
        $verified = openssl_verify($dataToSign, $derSig, $pubKeyRes, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new \RuntimeException('Auto-vérification de signature échouée');
        }

        $jwt = $dataToSign . '.' . self::b64url($rawSig);

        return [
            'jwt' => $jwt,
            'key' => $pubKeyB64url,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  CHIFFREMENT PAYLOAD (RFC 8291 + RFC 8188 aes128gcm)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Chiffre le payload selon RFC 8291 (aes128gcm).
     */
    private function encryptPayload(string $payload, string $p256dhB64url, string $authB64url) {
        // Décoder les clés du souscripteur
        $subscriberPubRaw = self::b64urlDecode($p256dhB64url);
        $authSecret       = self::b64urlDecode($authB64url);

        if (strlen($subscriberPubRaw) !== 65 || strlen($authSecret) !== 16) {
            return false;
        }

        // 1. Générer une paire de clés EC éphémère (côté serveur)
        $localKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$localKey) return false;

        $localDetails   = openssl_pkey_get_details($localKey);
        $localPubX      = str_pad($localDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $localPubY      = str_pad($localDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $localPublicRaw = "\x04" . $localPubX . $localPubY;

        // 2. Construire la clé publique du souscripteur comme ressource OpenSSL
        $subscriberPem = self::rawPublicKeyToPem($subscriberPubRaw);
        $subscriberKey = openssl_pkey_get_public($subscriberPem);
        if (!$subscriberKey) return false;

        // 3. ECDH : secret partagé
        $sharedSecret = openssl_pkey_derive($subscriberKey, $localKey);
        if ($sharedSecret === false) return false;

        // 4. Dériver IKM (RFC 8291 §3.3)
        $ikmInfo = "WebPush: info\x00" . $subscriberPubRaw . $localPublicRaw;
        $ikm = self::hkdf($authSecret, $sharedSecret, $ikmInfo, 32);

        // 5. Générer un salt aléatoire (16 octets)
        $salt = random_bytes(16);

        // 6. Dériver CEK et nonce (RFC 8188 §2.2)
        $cekInfo   = "Content-Encoding: aes128gcm\x00";
        $nonceInfo = "Content-Encoding: nonce\x00";
        $cek   = self::hkdf($salt, $ikm, $cekInfo, 16);
        $nonce = self::hkdf($salt, $ikm, $nonceInfo, 12);

        // 7. Padding + délimiteur (aes128gcm : payload || 0x02)
        $paddedPayload = $payload . "\x02";

        // 8. Chiffrer avec AES-128-GCM
        $tag = '';
        $encrypted = openssl_encrypt(
            $paddedPayload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16
        );
        if ($encrypted === false) return false;

        // 9. Construire le body aes128gcm (RFC 8188 §2.1)
        $rs = pack('N', 4096);
        $idlen = chr(65);

        return $salt . $rs . $idlen . $localPublicRaw . $encrypted . $tag;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  HELPERS CRYPTO
    // ═══════════════════════════════════════════════════════════════════════════

    private static function hkdf(string $salt, string $ikm, string $info, int $length): string {
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $ikm, $length, $info, $salt);
        }
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
    }

    private static function rawPublicKeyToPem(string $rawPub): string {
        $der = self::EC_P256_DER_PREFIX . $rawPub;
        $b64 = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Convertit une signature ECDSA DER en format raw R||S (64 octets).
     * Robuste : gère les cas où R ou S ont un octet de padding 0x00.
     */
    private static function derSignatureToRaw(string $der): string {
        // Format DER : 0x30 [len] 0x02 [rlen] [R] 0x02 [slen] [S]
        $offset = 0;
        if (ord($der[$offset]) !== 0x30) {
            throw new \RuntimeException('DER signature invalide : pas SEQUENCE');
        }
        $offset++;
        // Lire la longueur de SEQUENCE
        $seqLen = ord($der[$offset++]);
        if ($seqLen > 127) {
            $numLenBytes = $seqLen & 0x7F;
            $seqLen = 0;
            for ($i = 0; $i < $numLenBytes; $i++) {
                $seqLen = ($seqLen << 8) | ord($der[$offset++]);
            }
        }

        // Lire R
        if (ord($der[$offset++]) !== 0x02) throw new \RuntimeException('DER: expected INTEGER for R');
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // Lire S
        if (ord($der[$offset++]) !== 0x02) throw new \RuntimeException('DER: expected INTEGER for S');
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        // Enlever les octets de padding 0x00 en tête et padder à exactement 32 octets
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  HELPERS ENCODING
    // ═══════════════════════════════════════════════════════════════════════════

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string {
        $data = strtr($data, '-_', '+/');
        $padding = (4 - strlen($data) % 4) % 4;
        $data .= str_repeat('=', $padding);
        return base64_decode($data);
    }

    private function getAudience(string $endpoint): string {
        $parsed = parse_url($endpoint);
        return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  UTILITAIRE — Génération de clés VAPID
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Génère une nouvelle paire de clés VAPID.
     * Retourne ['publicKey' => base64url, 'privateKeyPem' => string PEM]
     */
    public static function generateVapidKeys(): array {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$key) throw new \RuntimeException('Impossible de générer une clé EC P-256');

        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);

        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $publicRaw = "\x04" . $x . $y;

        return [
            'publicKey'     => self::b64url($publicRaw),
            'privateKeyPem' => $pem,
        ];
    }

    /**
     * Diagnostique la configuration VAPID.
     */
    public function diagnose(?array $subscription = null): array {
        $result = ['ok' => true, 'errors' => [], 'info' => []];

        // 1. Vérifier la clé PEM
        $key = @openssl_pkey_get_private($this->vapidPem);
        if (!$key) {
            $result['ok'] = false;
            $err = '';
            while ($e = openssl_error_string()) $err .= $e . '; ';
            $result['errors'][] = 'Clé PEM invalide: ' . $err;
            $result['info']['pem_length'] = strlen($this->vapidPem);
            $result['info']['pem_starts'] = substr($this->vapidPem, 0, 30);
            return $result;
        }

        $details = openssl_pkey_get_details($key);
        $result['info']['key_type'] = $details['type'] === OPENSSL_KEYTYPE_EC ? 'EC' : 'OTHER';
        $result['info']['key_bits'] = $details['bits'] ?? 0;

        // 2. Vérifier la cohérence clé publique / privée
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $derivedPub = self::b64url("\x04" . $x . $y);
        $result['info']['derived_public_key'] = $derivedPub;
        $result['info']['stored_public_key'] = $this->vapidPublicB64;
        $result['info']['keys_match'] = ($derivedPub === $this->vapidPublicB64);

        if (!$result['info']['keys_match']) {
            $result['ok'] = false;
            $result['errors'][] = 'La clé publique configurée ne correspond pas à la clé privée.';
        }

        // 3. Vérifier le subject
        $result['info']['subject'] = $this->vapidSubject;
        if (!str_starts_with($this->vapidSubject, 'mailto:') && !str_starts_with($this->vapidSubject, 'https://')) {
            $result['ok'] = false;
            $result['errors'][] = 'Le subject VAPID doit commencer par mailto: ou https://';
        }

        // 4. Test de signature avec audience réelle si subscription fournie
        $testAudience = 'https://fcm.googleapis.com';
        if ($subscription && !empty($subscription['Endpoint'])) {
            $testAudience = $this->getAudience($subscription['Endpoint']);
            $result['info']['real_endpoint'] = substr($subscription['Endpoint'], 0, 80) . '...';
            $result['info']['real_audience'] = $testAudience;
        }

        try {
            $auth = $this->createVapidAuth($testAudience);
            $result['info']['test_jwt_length'] = strlen($auth['jwt']);
            $result['info']['test_key_length'] = strlen($auth['key']);

            // Décoder le JWT pour vérification visuelle
            $parts = explode('.', $auth['jwt']);
            if (count($parts) === 3) {
                $jwtHeader = json_decode(self::b64urlDecode($parts[0]), true);
                $jwtClaims = json_decode(self::b64urlDecode($parts[1]), true);
                $result['info']['jwt_header'] = $jwtHeader;
                $result['info']['jwt_claims'] = $jwtClaims;
                $result['info']['jwt_sig_length'] = strlen(self::b64urlDecode($parts[2]));
            }

            $result['info']['auto_verify'] = 'PASSED';
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = 'Erreur lors du test de signature : ' . $e->getMessage();
            $result['info']['auto_verify'] = 'FAILED: ' . $e->getMessage();
        }

        return $result;
    }
}

