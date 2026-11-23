<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Notify\Channel;
use App\Notify\NotificationEvent;
use App\Notify\Notifier;
use App\Push\PushSender;
use App\Support\Audit;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Own notifications (E-101): events and channels, quiet hours and the
 * devices that receive push messages. Administrators only.
 */
class NotificationController extends Controller
{
    private const TIME = 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    public function show(Request $request): View
    {
        Gate::authorize('viewAny', PushSubscription::class);
        $user = $request->user();

        return view('account.notifications', [
            'user' => $user,
            'groups' => NotificationEvent::groups(),
            'devices' => $user->pushSubscriptions()->with(['deliveries' => fn ($query) => $query->orderByDesc('id')])->orderBy('created_at')->get(),
            'mailReady' => MailSettings::configured(),
            'vapidKey' => PushSender::publicKey(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', PushSubscription::class);
        $data = $request->validate([
            'events' => ['array'],
            'events.*' => ['array'],
            'events.*.*' => ['boolean'],
            'quiet_enabled' => ['boolean'],
            'quiet_from' => ['required', 'string', self::TIME],
            'quiet_until' => ['required', 'string', self::TIME],
        ]);

        $prefs = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (Channel::cases() as $channel) {
                $prefs[$event->value][$channel->value] = (bool) ($data['events'][$event->value][$channel->value] ?? false);
            }
        }

        $request->user()->forceFill([
            'notification_prefs' => $prefs,
            'quiet_enabled' => (bool) ($data['quiet_enabled'] ?? false),
            'quiet_from' => $data['quiet_from'],
            'quiet_until' => $data['quiet_until'],
        ])->save();

        return to_route('account.notifications')->with('status', __('notifications.saved'));
    }

    /**
     * Registers this browser. Called by JavaScript after the browser's
     * push service handed out the subscription.
     */
    public function subscribe(Request $request): JsonResponse
    {
        Gate::authorize('create', PushSubscription::class);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{86,88}={0,2}$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22,24}={0,2}$/'],
        ]);

        if (! PushSubscription::allowedEndpoint($data['endpoint'])) {
            return response()->json(['message' => __('notifications.push.unknown_service')], 422);
        }

        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $existing = PushSubscription::firstWhere('endpoint_hash', $hash);
        // A browser belongs to one account; signing in as another moves it.
        $existing?->delete();

        $subscription = $request->user()->pushSubscriptions()->create([
            'endpoint' => $data['endpoint'],
            'endpoint_hash' => $hash,
            'public_key' => rtrim($data['keys']['p256dh'], '='),
            'auth' => rtrim($data['keys']['auth'], '='),
            'label' => PushSubscription::labelFor($request->userAgent()),
        ]);
        Audit::record('notifications.device_added', null, ['label' => $subscription->label]);

        return response()->json(['id' => $subscription->getKey()], 201);
    }

    public function destroy(Request $request, PushSubscription $pushSubscription): RedirectResponse
    {
        Gate::authorize('delete', $pushSubscription);
        $pushSubscription->delete();
        Audit::record('notifications.device_removed', null, ['label' => $pushSubscription->label]);

        return to_route('account.notifications')->with('status', __('notifications.push.removed'));
    }

    /**
     * A test message to all own devices and, if set up, by mail; ignores
     * the quiet hours.
     */
    public function test(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', PushSubscription::class);
        $user = $request->user();
        $message = Notifier::message('test', 'raised', 'owpanel-test', [], route('account.notifications'));

        $channels = [];
        if ($user->pushSubscriptions()->exists()) {
            $channels[] = Channel::Push;
        }
        if (MailSettings::configured()) {
            $channels[] = Channel::Mail;
        }
        foreach ($channels as $channel) {
            Notifier::deliver($user, $channel, [$message], ignoreQuietHours: true);
        }

        return to_route('account.notifications')->with(
            $channels === [] ? 'warning' : 'status',
            __($channels === [] ? 'notifications.test.nothing' : 'notifications.test.sent'),
        );
    }
}
