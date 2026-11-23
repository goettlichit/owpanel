<?php

namespace App\Push;

use InvalidArgumentException;
use RuntimeException;

/**
 * Web Push without a library (E-101): message encryption after RFC 8291
 * (aes128gcm, RFC 8188) and the VAPID sign-in after RFC 8292, with PHP's
 * OpenSSL only. Tested against the example of RFC 8291, Appendix A.
 */
final class WebPush
{
    /** DER prefix of a P-256 public key (SubjectPublicKeyInfo) before the 65-octet point. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Record size of the single record; larger than any message the panel sends. */
    private const RECORD_SIZE = 4096;

    /**
     * Encrypts a message for one subscription and returns the request body:
     * header (salt, record size, sender key) and the one encrypted record.
     * $senderPrivatePem and $salt are only given by tests; normally both
     * are new for every message.
     */
    public static function encrypt(string $plaintext, string $userPublicKey, string $userAuth, ?string $senderPrivatePem = null, ?string $salt = null): string
    {
        $uaPublic = self::base64UrlDecode($userPublicKey);
        $authSecret = self::base64UrlDecode($userAuth);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('Invalid subscription keys.');
        }
        if (strlen($plaintext) > self::RECORD_SIZE - 17) {
            throw new InvalidArgumentException('Message too long.');
        }

        $sender = $senderPrivatePem !== null
            ? openssl_pkey_get_private($senderPrivatePem)
            : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($sender === false) {
            throw new RuntimeException('Could not create the sender key.');
        }
        $asPublic = self::publicKeyOf($sender);
        $salt ??= random_bytes(16);

        $ecdhSecret = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $sender);
        if ($ecdhSecret === false) {
            throw new RuntimeException('Key agreement failed.');
        }

        // RFC 8291, section 3.4.
        $keyInfo = "WebPush: info\x00".$uaPublic.$asPublic;
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // One record: the message and the padding delimiter 0x02 for the last record.
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$asPublic.$ciphertext.$tag;
    }

    /**
     * Authorization header for the push service of an endpoint (RFC 8292):
     * a JWT for the endpoint's origin, signed with the panel's VAPID key.
     */
    public static function vapidAuthorization(string $endpoint, string $publicKey, string $privatePem, string $subject, ?int $expires = null): string
    {
        $parts = parse_url($endpoint);
        if (! isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            throw new InvalidArgumentException('Invalid endpoint.');
        }
        $audience = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => $expires ?? time() + 12 * 3600,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));
        $signature = '';
        if (! openssl_sign($header.'.'.$claims, $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed.');
        }

        return 'vapid t='.$header.'.'.$claims.'.'.self::base64UrlEncode(self::derToRaw($signature)).', k='.$publicKey;
    }

    /**
     * A new VAPID key pair: the public key as base64url (65 octets, for the
     * browser) and the private key as PEM.
     *
     * @return array{public: string, private: string}
     */
    public static function createVapidKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not create a VAPID key.');
        }

        return ['public' => self::base64UrlEncode(self::publicKeyOf($key)), 'private' => $pem];
    }

    /**
     * PEM of a P-256 private key from its raw values; only for the test
     * vector, which gives the keys that way.
     */
    public static function privateKeyPem(string $d, string $publicRaw): string
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$publicRaw;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64url.');
        }

        return $decoded;
    }

    /**
     * The uncompressed point (0x04, x, y) of an EC key.
     */
    private static function publicKeyOf(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (! is_string($x) || ! is_string($y)) {
            throw new RuntimeException('Not an EC key.');
        }

        return "\x04".str_pad($x, 32, "\x00", STR_PAD_LEFT).str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    private static function publicKeyFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InvalidArgumentException('Invalid public key.');
        }

        return $key;
    }

    /**
     * OpenSSL signs ECDSA as DER (SEQUENCE of two INTEGERs); JWT wants the
     * two 32-octet numbers back to back.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new RuntimeException('Invalid signature.');
            }
            $length = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $length), "\x00");
            $raw .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }
}
