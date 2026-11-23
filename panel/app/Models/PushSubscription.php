<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A browser that receives push messages for one administrator (E-101).
 * Endpoint and keys come from the browser's push service; the panel
 * encrypts every message with them.
 */
class PushSubscription extends Model
{
    use HasUlids;

    /**
     * Push services the panel sends to. Other addresses are refused, so the
     * panel never posts to a host an attacker chose (E-101).
     */
    public const ALLOWED_HOSTS = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    /** Failed deliveries in a row after which a device is removed. */
    public const MAX_FAILURES = 10;

    protected $fillable = ['endpoint', 'endpoint_hash', 'public_key', 'auth', 'label'];

    protected $hidden = ['endpoint', 'public_key', 'auth'];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PushDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(PushDelivery::class);
    }

    /**
     * Notes one delivery and keeps only the newest (E-147).
     */
    public function recordDelivery(?int $status, string $title): void
    {
        $this->deliveries()->create(['status' => $status, 'title' => mb_substr($title, 0, 150)]);
        $keep = $this->deliveries()->orderByDesc('id')->limit(PushDelivery::KEEP)->pluck('id');
        $this->deliveries()->whereNotIn('id', $keep)->delete();
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Whether the endpoint is an https address of a known push service,
     * the host itself or a subdomain of it.
     */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Short name of the device from the browser's user agent, e.g.
     * "Firefox · Linux". Only for display.
     */
    public static function labelFor(?string $userAgent): string
    {
        $agent = (string) $userAgent;
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : $browser.' · '.$system;
    }
}
