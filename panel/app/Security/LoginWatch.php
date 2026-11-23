<?php

namespace App\Security;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notify\NotificationEvent;
use App\Notify\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Sign-ins of administrators from new devices and locked sign-ins (E-160).
 *
 * A device is a browser with the cookie owp_device: a random value the
 * panel only keeps as a hash. A sign-in without a known cookie is a new
 * device. The very first sign-in after this feature arrived only learns
 * the device, so nobody gets a message for the browser they already use.
 */
class LoginWatch
{
    public const COOKIE = 'owp_device';

    /** The cookie lives about 400 days, the most browsers allow. */
    private const COOKIE_MINUTES = 400 * 24 * 60;

    /** Devices kept per administrator; the longest unused drops out. */
    public const MAX_DEVICES = 20;

    /** One message per account and hour about locked sign-ins. */
    private const LOCKOUT_PAUSE_SECONDS = 3600;

    public static function signedIn(User $user, Request $request): void
    {
        if (! $user->isAdmin()) {
            return;
        }

        $token = (string) $request->cookie(self::COOKIE);
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            $token = Str::random(40);
        }
        $hash = hash('sha256', $token);
        $devices = is_array($user->known_devices) ? $user->known_devices : null;
        $learning = $devices === null;
        $known = ! $learning && isset($devices[$hash]);
        $label = PushSubscription::labelFor($request->userAgent());

        $devices ??= [];
        $devices[$hash] = [
            'label' => $known ? $devices[$hash]['label'] : $label,
            'last_used_at' => now()->toIso8601String(),
        ];
        uasort($devices, fn ($a, $b) => strcmp($b['last_used_at'], $a['last_used_at']));
        $user->forceFill(['known_devices' => array_slice($devices, 0, self::MAX_DEVICES, true)])->saveQuietly();

        Cookie::queue(Cookie::make(self::COOKIE, $token, self::COOKIE_MINUTES, httpOnly: true));

        if (! $known && ! $learning) {
            Notifier::notify(NotificationEvent::NewDeviceLogin, "new_device:{$user->getKey()}:{$hash}", [
                'user' => $user->name,
                'device' => $label,
                'ip' => (string) $request->ip(),
            ], route('account.security'));
        }
    }

    /**
     * Too many wrong passwords: only for existing accounts, so typed
     * addresses of strangers never end up in a message.
     */
    public static function lockedOut(Request $request): void
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $pending = $request->hasSession() ? $request->session()->get('login.id') : null;
        $user = match (true) {
            $email !== '' => User::where('email', $email)->first(),
            // The second factor: the account waiting for its code.
            $pending !== null => User::find($pending),
            default => null,
        };
        if ($user === null || ! Cache::add('login-lockout-notified:'.$user->getKey(), true, self::LOCKOUT_PAUSE_SECONDS)) {
            return;
        }

        Notifier::notify(NotificationEvent::LoginLockout, "login_lockout:{$user->getKey()}", [
            'email' => $user->email,
            'ip' => (string) $request->ip(),
        ], route('audit-log.index'));
    }
}
