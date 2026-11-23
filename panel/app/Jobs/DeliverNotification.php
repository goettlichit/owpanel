<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\Alert;
use App\Models\User;
use App\Notify\Channel;
use App\Notify\MessageText;
use App\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one or more messages to one administrator on one channel (E-101).
 * Runs on the queue of the short jobs, so long server tasks do not delay
 * it.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    /** Longest push message body; the push services allow about 4 KB. */
    private const PUSH_LINES = 6;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(public string $userId, public string $channel, public array $messages)
    {
        $this->onQueue(RunDatabaseQuery::QUEUE);
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if ($user === null || ! $user->isAdmin() || $this->messages === []) {
            return;
        }

        $locale = $user->locale ?? (string) config('app.locale');

        if (Channel::tryFrom($this->channel) === Channel::Mail) {
            Mail::to($user->email)->locale($locale)->send(new NotificationMail($this->messages));

            return;
        }

        App::setLocale($locale);
        $payload = $this->pushPayload();
        foreach ($user->pushSubscriptions as $subscription) {
            PushSender::send($subscription, $payload);
        }
    }

    /**
     * The badge is the number of open alerts when the message goes out
     * (E-119); the service worker sets it on the app icon.
     *
     * @return array<string, string|int>
     */
    private function pushPayload(): array
    {
        return [...$this->pushText(), 'badge' => Alert::openCount()];
    }

    /**
     * @return array<string, string>
     */
    private function pushText(): array
    {
        $messages = $this->messages;
        if (count($messages) === 1) {
            return [
                'title' => MessageText::title($messages[0]),
                'body' => MessageText::body($messages[0]),
                'url' => (string) ($messages[0]['url'] ?? url('/')),
                'tag' => (string) ($messages[0]['key'] ?? 'owpanel'),
            ];
        }

        $lines = array_map(fn (array $message) => MessageText::title($message), array_slice($messages, 0, self::PUSH_LINES));
        if (count($messages) > self::PUSH_LINES) {
            $lines[] = '…';
        }

        return [
            'title' => MessageText::digestTitle($messages),
            'body' => mb_substr(implode("\n", $lines), 0, 1500),
            'url' => route('dashboard'),
            'tag' => 'owpanel-digest',
        ];
    }
}
