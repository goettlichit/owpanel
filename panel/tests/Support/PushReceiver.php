<?php

namespace Tests\Support;

use App\Push\WebPush;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * The browser side of Web Push for tests: a key pair and auth secret like
 * a push subscription, and decryption after RFC 8291.
 */
final class PushReceiver
{
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public OpenSSLAsymmetricKey $key;

    public string $publicRaw;

    public string $auth;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $this->publicRaw = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $this->auth = random_bytes(16);
    }

    public function publicKey(): string
    {
        return WebPush::base64UrlEncode($this->publicRaw);
    }

    public function authSecret(): string
    {
        return WebPush::base64UrlEncode($this->auth);
    }

    public function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $senderPublic = substr($body, 21, $idLength);
        $record = substr($body, 21 + $idLength);

        $senderKey = openssl_pkey_get_public(self::pem($senderPublic));
        $secret = openssl_pkey_derive($senderKey, $this->key);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\x00".$this->publicRaw.$senderPublic, $this->auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }
        $plain = rtrim($plain, "\x00");
        if (! str_ends_with($plain, "\x02")) {
            throw new RuntimeException('Missing padding delimiter of the last record.');
        }

        return substr($plain, 0, -1);
    }

    /**
     * Checks the VAPID header "vapid t=<JWT>, k=<public key>" and returns
     * the claims.
     *
     * @return array<string, mixed>
     */
    public static function verifyVapid(string $header): array
    {
        if (! preg_match('/^vapid t=([^,]+), k=(\S+)$/', $header, $match)) {
            throw new RuntimeException('Not a VAPID header.');
        }
        [$head, $claims, $signature] = explode('.', $match[1]);
        $raw = WebPush::base64UrlDecode($signature);
        $der = self::derSignature(substr($raw, 0, 32), substr($raw, 32));
        $key = openssl_pkey_get_public(self::pem(WebPush::base64UrlDecode($match[2])));
        if (openssl_verify($head.'.'.$claims, $der, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Invalid VAPID signature.');
        }
        if (json_decode(WebPush::base64UrlDecode($head), true) !== ['typ' => 'JWT', 'alg' => 'ES256']) {
            throw new RuntimeException('Unexpected JWT header.');
        }

        return json_decode(WebPush::base64UrlDecode($claims), true);
    }

    private static function pem(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derSignature(string $r, string $s): string
    {
        $integer = function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '' || ord($value[0]) > 0x7F) {
                $value = "\x00".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };
        $sequence = $integer($r).$integer($s);

        return "\x30".chr(strlen($sequence)).$sequence;
    }
}
