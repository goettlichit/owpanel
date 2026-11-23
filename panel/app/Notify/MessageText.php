<?php

namespace App\Notify;

use Illuminate\Support\Carbon;

/**
 * Text of a queued message in the current language (E-101).
 */
final class MessageText
{
    /**
     * @param  array<string, mixed>  $message
     */
    public static function title(array $message): string
    {
        return __(self::base($message), self::params($message));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function body(array $message): string
    {
        return trim((string) __(self::base($message).'_body', self::params($message)));
    }

    /**
     * Time of the event in the panel's time zone, for collected messages.
     *
     * @param  array<string, mixed>  $message
     */
    public static function time(array $message): string
    {
        return Carbon::parse((string) $message['at'])->setTimezone((string) config('owpanel.timezone'))->isoFormat('L LT');
    }

    /**
     * Title of a message that collects several, e.g. after quiet hours.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function digestTitle(array $messages): string
    {
        return count($messages) === 1
            ? self::title($messages[0])
            : __('notifications.digest.title', ['count' => count($messages)]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function base(array $message): string
    {
        if ($message['event'] === 'test') {
            return 'notifications.test.message';
        }
        $event = NotificationEvent::tryFrom((string) $message['event']);
        $state = $message['state'] === 'resolved' ? 'resolved' : 'raised';

        return 'notifications.events.'.($event?->value ?? 'unknown').'.'.$state;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, string>
     */
    private static function params(array $message): array
    {
        return array_map('strval', (array) ($message['params'] ?? []));
    }
}
