<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A setting of the panel (E-091). Only known keys; read on every page for
 * the footer, so all values are cached together.
 */
class Setting extends Model
{
    public const IMPRINT_URL = 'imprint_url';

    public const PRIVACY_URL = 'privacy_url';

    // Own help page for customers instead of the customer documentation (E-178).
    public const HELP_URL = 'help_url';

    // Outgoing mail (E-094); the password is stored encrypted.
    public const MAIL_HOST = 'mail_host';

    public const MAIL_PORT = 'mail_port';

    public const MAIL_ENCRYPTION = 'mail_encryption';

    public const MAIL_USERNAME = 'mail_username';

    public const MAIL_PASSWORD = 'mail_password';

    public const MAIL_FROM_ADDRESS = 'mail_from_address';

    public const MAIL_FROM_NAME = 'mail_from_name';

    // Token of a Hetzner Cloud project for snapshots (E-095), encrypted.
    public const HETZNER_TOKEN = 'hetzner_token';

    // Optional token of the Hetzner project with the DNS zones (E-118),
    // encrypted; without it, DNS uses HETZNER_TOKEN.
    public const HETZNER_DNS_TOKEN = 'hetzner_dns_token';

    // Optional API key of Pocket ID for creating and linking customers
    // (E-138), encrypted.
    public const POCKET_ID_API_KEY = 'pocket_id_api_key';

    // Key pair for push messages (E-101), created on first use; the
    // private key is stored encrypted.
    public const VAPID_PUBLIC = 'vapid_public';

    public const VAPID_PRIVATE = 'vapid_private';

    // Written by the installer after restoring the panel (E-099): which
    // backup it came from, as JSON.
    public const PANEL_RESTORED = 'panel_restored';

    // Newest published release of the panel (E-154), as JSON: version,
    // checked_at and error. Written by owpanel:release-check.
    public const PANEL_RELEASE = 'panel_release';

    // Fill level of the Storage Boxes the backups use (E-160), JSON per
    // box id: name, used and total bytes, time of the reading.
    public const STORAGE_BOXES = 'storage_boxes';

    // Heartbeat to an outside monitoring (E-160): the address (encrypted,
    // it often holds a secret) and the result of the last call as JSON.
    public const HEARTBEAT_URL = 'heartbeat_url';

    public const HEARTBEAT_STATE = 'heartbeat_state';

    // Login with Pocket ID or another OpenID Connect provider (E-114); the
    // client secret is stored encrypted.
    public const OIDC_ISSUER = 'oidc_issuer';

    public const OIDC_CLIENT_ID = 'oidc_client_id';

    public const OIDC_CLIENT_SECRET = 'oidc_client_secret';

    public const OIDC_NAME = 'oidc_name';

    public const KEYS = [
        self::IMPRINT_URL, self::PRIVACY_URL, self::HELP_URL,
        self::MAIL_HOST, self::MAIL_PORT, self::MAIL_ENCRYPTION, self::MAIL_USERNAME,
        self::MAIL_PASSWORD, self::MAIL_FROM_ADDRESS, self::MAIL_FROM_NAME,
        self::HETZNER_TOKEN, self::HETZNER_DNS_TOKEN, self::POCKET_ID_API_KEY, self::VAPID_PUBLIC, self::VAPID_PRIVATE, self::PANEL_RESTORED, self::PANEL_RELEASE, self::STORAGE_BOXES, self::HEARTBEAT_URL, self::HEARTBEAT_STATE,
        self::OIDC_ISSUER, self::OIDC_CLIENT_ID, self::OIDC_CLIENT_SECRET, self::OIDC_NAME,
    ];

    private const CACHE_KEY = 'owpanel.settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * The backup the panel was restored from, or null. "dismissed" is set
     * once an administrator has hidden the hint; the backup stays marked in
     * the list of panel backups.
     *
     * @return array{snapshot: string, backup_time: Carbon, restored_at: Carbon, dismissed: bool}|null
     */
    public static function panelRestored(): ?array
    {
        $data = json_decode((string) self::get(self::PANEL_RESTORED), true);
        if (! is_array($data) || ! preg_match('/^[0-9a-f]{8}$/', (string) ($data['snapshot'] ?? ''))) {
            return null;
        }
        try {
            return [
                'snapshot' => $data['snapshot'],
                'backup_time' => Carbon::createFromFormat('Y-m-d H:i', (string) ($data['backup_time'] ?? ''), 'UTC'),
                'restored_at' => Carbon::createFromFormat('Y-m-d H:i', (string) ($data['restored_at'] ?? ''), 'UTC'),
                'dismissed' => ($data['dismissed'] ?? false) === true,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Hides the hint about the restore under System -> Backup. A later
     * restore writes the setting anew and shows it again.
     */
    public static function dismissPanelRestored(): void
    {
        $data = json_decode((string) self::get(self::PANEL_RESTORED), true);
        if (is_array($data)) {
            self::put(self::PANEL_RESTORED, (string) json_encode(['dismissed' => true] + $data));
        }
    }

    public static function get(string $key): ?string
    {
        return self::values()[$key] ?? null;
    }

    public static function put(string $key, ?string $value): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown setting {$key}.");
        }
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    private static function values(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => Schema::hasTable('settings')
                ? self::query()->pluck('value', 'key')->all()
                : []);
        } catch (Throwable) {
            // The footer must render even before the first migration.
            return [];
        }
    }
}
