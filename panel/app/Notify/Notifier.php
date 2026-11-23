<?php

namespace App\Notify;

use App\Enums\Role;
use App\Jobs\DeliverNotification;
use App\Models\Alert;
use App\Models\PendingNotification;
use App\Models\User;
use App\Support\MailSettings;

/**
 * Sends alerts to the administrators (E-101). raise() reports a problem
 * once, resolve() sends the all-clear once. Each administrator chooses
 * events and channels; during the quiet hours messages wait and go out
 * together when they end (flushPending()).
 */
final class Notifier
{
    /**
     * Reports a problem unless it is already open.
     *
     * @param  array<string, string|int>  $params
     */
    public static function raise(NotificationEvent $event, string $key, array $params, ?string $url = null): void
    {
        $alert = Alert::firstWhere('key', $key);
        if ($alert?->isOpen()) {
            return;
        }

        $data = ['params' => $params, 'url' => $url];
        $alert ??= new Alert(['key' => $key]);
        $alert->fill(['event' => $event, 'data' => $data, 'opened_at' => now(), 'resolved_at' => null])->save();

        self::send($event, self::message($event, 'raised', $key, $params, $url));
    }

    /**
     * Sends the all-clear for an open problem.
     */
    public static function resolve(string $key): void
    {
        $alert = Alert::firstWhere('key', $key);
        if ($alert === null || ! $alert->isOpen()) {
            return;
        }

        $alert->forceFill(['resolved_at' => now()])->save();
        self::send($alert->event, self::message($alert->event, 'resolved', $key, $alert->data['params'] ?? [], $alert->data['url'] ?? null));
    }

    /**
     * Sends a one-off message, such as a finished backup (E-104). The key
     * groups messages on a device: a newer one with the same key replaces
     * the older.
     *
     * @param  array<string, string|int>  $params
     */
    public static function notify(NotificationEvent $event, string $key, array $params, ?string $url = null): void
    {
        self::send($event, self::message($event, 'raised', $key, $params, $url));
    }

    public static function isOpen(string $key): bool
    {
        return (bool) Alert::firstWhere('key', $key)?->isOpen();
    }

    /**
     * Sends the messages held back during quiet hours, one message per
     * administrator and channel.
     */
    public static function flushPending(): void
    {
        PendingNotification::with('user')->orderBy('id')->get()
            ->groupBy(fn (PendingNotification $pending) => $pending->user_id.'|'.$pending->channel)
            ->each(function ($group) {
                $first = $group->first();
                if ($first->user === null || $first->user->inQuietHours()) {
                    return;
                }
                DeliverNotification::dispatch($first->user_id, $first->channel, $group->pluck('message')->all());
                PendingNotification::whereKey($group->modelKeys())->delete();
            });
    }

    /**
     * Sends the messages now, or keeps them until the quiet hours end.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function deliver(User $user, Channel $channel, array $messages, bool $ignoreQuietHours = false): void
    {
        if (! $ignoreQuietHours && $user->inQuietHours()) {
            foreach ($messages as $message) {
                PendingNotification::create(['user_id' => $user->getKey(), 'channel' => $channel->value, 'message' => $message]);
            }

            return;
        }

        DeliverNotification::dispatch($user->getKey(), $channel->value, $messages);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function send(NotificationEvent $event, array $message): void
    {
        $mailReady = MailSettings::configured();

        User::where('role', Role::Admin->value)->withCount('pushSubscriptions')->each(
            function (User $user) use ($event, $message, $mailReady) {
                foreach (Channel::cases() as $channel) {
                    $ready = $channel === Channel::Mail ? $mailReady : $user->push_subscriptions_count > 0;
                    if ($ready && $user->wantsNotification($event, $channel)) {
                        self::deliver($user, $channel, [$message]);
                    }
                }
            },
        );
    }

    /**
     * A message as stored and queued; the text is written when it is sent,
     * in the language of the recipient.
     *
     * @param  array<string, string|int>  $params
     * @return array<string, mixed>
     */
    public static function message(NotificationEvent|string $event, string $state, string $key, array $params, ?string $url): array
    {
        return [
            'event' => $event instanceof NotificationEvent ? $event->value : $event,
            'state' => $state,
            'key' => $key,
            'params' => $params,
            'url' => $url,
            'at' => now()->toIso8601String(),
        ];
    }
}
