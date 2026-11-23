<?php

namespace App\Oidc;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings of the login with Pocket ID or another OpenID Connect provider,
 * under System -> Settings (E-114). The client secret is stored encrypted
 * and never shown again.
 */
final class OidcSettings
{
    /**
     * Shown on the login page and in the account when no name is set.
     */
    public const DEFAULT_NAME = 'Pocket ID';

    /**
     * Address the provider sends the browser back to after the login. The
     * route itself follows with the login (milestone 6, second step).
     */
    public const CALLBACK_PATH = '/auth/oidc/callback';

    /**
     * Issuer, client ID and secret are all set.
     */
    public static function configured(): bool
    {
        return self::issuer() !== null && self::clientId() !== null && self::clientSecret() !== null;
    }

    /**
     * The issuer exactly as the provider names itself in its discovery
     * document; ID tokens must carry the same value (E-113).
     */
    public static function issuer(): ?string
    {
        return Setting::get(Setting::OIDC_ISSUER) ?: null;
    }

    public static function clientId(): ?string
    {
        return Setting::get(Setting::OIDC_CLIENT_ID) ?: null;
    }

    public static function clientSecret(): ?string
    {
        $encrypted = Setting::get(Setting::OIDC_CLIENT_SECRET);
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Name of the Pocket ID instance, e.g. "goettlichIT ID".
     */
    public static function name(): string
    {
        return Setting::get(Setting::OIDC_NAME) ?: self::DEFAULT_NAME;
    }

    /**
     * The name as entered, empty when the default is used.
     */
    public static function customName(): ?string
    {
        return Setting::get(Setting::OIDC_NAME) ?: null;
    }

    public static function redirectUrl(): string
    {
        return rtrim((string) config('app.url'), '/').self::CALLBACK_PATH;
    }

    /**
     * A null secret keeps the stored one.
     */
    public static function save(string $issuer, string $clientId, #[\SensitiveParameter] ?string $clientSecret, ?string $name): void
    {
        Setting::put(Setting::OIDC_ISSUER, $issuer);
        Setting::put(Setting::OIDC_CLIENT_ID, $clientId);
        if ($clientSecret !== null) {
            Setting::put(Setting::OIDC_CLIENT_SECRET, Crypt::encryptString($clientSecret));
        }
        Setting::put(Setting::OIDC_NAME, $name);
    }

    /**
     * Turns the login off. The name stays, and linked accounts keep their
     * link for a later return of the same issuer.
     */
    public static function disable(?string $name): void
    {
        Setting::put(Setting::OIDC_ISSUER, null);
        Setting::put(Setting::OIDC_CLIENT_ID, null);
        Setting::put(Setting::OIDC_CLIENT_SECRET, null);
        Setting::put(Setting::OIDC_NAME, $name);
    }
}
