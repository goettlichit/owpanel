<?php

namespace App\Push;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one push message to one browser (E-101). The key pair (VAPID) is
 * created on first use and kept in the settings; it identifies the panel
 * to the push services. Devices the push service no longer knows are
 * removed.
 */
final class PushSender
{
    /** How long the push service keeps a message for an offline device. */
    private const TTL_SECONDS = 86400;

    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function send(PushSubscription $subscription, array $payload): bool
    {
        if (! PushSubscription::allowedEndpoint($subscription->endpoint)) {
            $subscription->delete();

            return false;
        }

        $keys = self::keys();
        $body = WebPush::encrypt(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $subscription->public_key,
            $subscription->auth,
        );

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    'Authorization' => WebPush::vapidAuthorization($subscription->endpoint, $keys['public'], $keys['private'], self::subject()),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) self::TTL_SECONDS,
                    'Urgency' => 'high',
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (ConnectionException) {
            self::log($subscription, $payload, null);
            self::failed($subscription);

            return false;
        }

        self::log($subscription, $payload, $response->status());

        // The browser left the subscription or it expired.
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        if (! $response->successful()) {
            self::failed($subscription);

            return false;
        }

        $subscription->forceFill(['last_success_at' => now(), 'failures' => 0])->save();

        return true;
    }

    /**
     * Notes the delivery for the device list and in the log of the worker
     * (E-147): device, push service, title and answer. Never the endpoint,
     * the keys or the body.
     *
     * @param  array<string, string|int>  $payload
     */
    private static function log(PushSubscription $subscription, array $payload, ?int $status): void
    {
        $title = (string) ($payload['title'] ?? '');
        $subscription->recordDelivery($status, $title);
        Log::info('Push message handed to the push service', [
            'device' => $subscription->getKey(),
            'service' => parse_url($subscription->endpoint, PHP_URL_HOST),
            'status' => $status ?? 'unreachable',
            'title' => $title,
        ]);
    }

    private static function failed(PushSubscription $subscription): void
    {
        $subscription->increment('failures');
        if ($subscription->failures >= PushSubscription::MAX_FAILURES) {
            $subscription->delete();
        }
    }

    /**
     * Contact for the push services: the panel's address, as RFC 8292
     * suggests.
     */
    private static function subject(): string
    {
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://') ? rtrim($url, '/') : 'mailto:owpanel@localhost';
    }

    /**
     * @return array{public: string, private: string}
     */
    private static function keys(): array
    {
        if (Setting::get(Setting::VAPID_PUBLIC) === null) {
            Cache::lock('owpanel.vapid', 10)->block(10, function () {
                if (Setting::get(Setting::VAPID_PUBLIC) === null) {
                    $keys = WebPush::createVapidKeys();
                    Setting::put(Setting::VAPID_PRIVATE, Crypt::encryptString($keys['private']));
                    Setting::put(Setting::VAPID_PUBLIC, $keys['public']);
                }
            });
        }

        return [
            'public' => (string) Setting::get(Setting::VAPID_PUBLIC),
            'private' => Crypt::decryptString((string) Setting::get(Setting::VAPID_PRIVATE)),
        ];
    }
}
