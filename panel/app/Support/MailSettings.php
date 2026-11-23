<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Outgoing mail of the panel over SMTP, set under System -> Settings
 * (E-094). Without a server and a sender, the panel sends no mail and
 * offers no password reset.
 */
final class MailSettings
{
    /**
     * starttls: port 587 with STARTTLS, required. tls: port 465 with TLS from
     * the start. none: unencrypted, only for a relay on the own network.
     */
    public const ENCRYPTIONS = ['starttls', 'tls', 'none'];

    public static function configured(): bool
    {
        return Setting::get(Setting::MAIL_HOST) !== null && Setting::get(Setting::MAIL_FROM_ADDRESS) !== null;
    }

    /**
     * The stored values for the form; the password only as "is set".
     *
     * @return array{host: ?string, port: int, encryption: string, username: ?string, password_set: bool, from_address: ?string, from_name: ?string}
     */
    public static function values(): array
    {
        return [
            'host' => Setting::get(Setting::MAIL_HOST),
            'port' => (int) (Setting::get(Setting::MAIL_PORT) ?? 587),
            'encryption' => Setting::get(Setting::MAIL_ENCRYPTION) ?? 'starttls',
            'username' => Setting::get(Setting::MAIL_USERNAME),
            'password_set' => Setting::get(Setting::MAIL_PASSWORD) !== null,
            'from_address' => Setting::get(Setting::MAIL_FROM_ADDRESS),
            'from_name' => Setting::get(Setting::MAIL_FROM_NAME),
        ];
    }

    /**
     * Saves the settings. A null password keeps the stored one, an empty
     * string removes it.
     *
     * @param  array{host: ?string, port: int, encryption: string, username: ?string, from_address: ?string, from_name: ?string}  $values
     */
    public static function save(array $values, #[\SensitiveParameter] ?string $password): void
    {
        Setting::put(Setting::MAIL_HOST, $values['host']);
        Setting::put(Setting::MAIL_PORT, (string) $values['port']);
        Setting::put(Setting::MAIL_ENCRYPTION, $values['encryption']);
        Setting::put(Setting::MAIL_USERNAME, $values['username']);
        Setting::put(Setting::MAIL_FROM_ADDRESS, $values['from_address']);
        Setting::put(Setting::MAIL_FROM_NAME, $values['from_name']);
        if ($password !== null) {
            Setting::put(Setting::MAIL_PASSWORD, $password === '' ? null : Crypt::encryptString($password));
        }
    }

    /**
     * Points Laravel's mailer at the stored server. Called at boot and
     * again right after the settings change, then with $fresh, so an
     * already built mailer is rebuilt.
     */
    public static function apply(bool $fresh = false): void
    {
        if (! self::configured()) {
            // Mails go to the log instead of nowhere; nothing offers to send.
            config(['mail.default' => 'log']);
            if ($fresh) {
                app('mail.manager')->purge('smtp');
            }

            return;
        }

        $values = self::values();
        $password = null;
        if (($encrypted = Setting::get(Setting::MAIL_PASSWORD)) !== null) {
            try {
                $password = Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                $password = null;
            }
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                // smtps: TLS from the start; smtp: STARTTLS if offered, and
                // required through require_tls unless "none" was chosen.
                'scheme' => $values['encryption'] === 'tls' ? 'smtps' : 'smtp',
                'host' => $values['host'],
                'port' => $values['port'],
                'username' => $values['username'],
                'password' => $password,
                'timeout' => 15,
                'require_tls' => $values['encryption'] === 'starttls',
                'auto_tls' => $values['encryption'] !== 'none',
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            ],
            'mail.from' => [
                'address' => $values['from_address'],
                'name' => $values['from_name'] ?: 'OWPanel',
            ],
        ]);
        if ($fresh) {
            app('mail.manager')->purge('smtp');
        }
    }
}
