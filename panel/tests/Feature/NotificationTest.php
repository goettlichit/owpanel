<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\DeliverNotification;
use App\Mail\NotificationMail;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\PendingNotification;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerSnapshot;
use App\Models\ServerTask;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Notify\AlertChecker;
use App\Notify\Channel;
use App\Notify\Events;
use App\Notify\MessageText;
use App\Notify\NotificationEvent;
use App\Notify\Notifier;
use App\Push\PushSender;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PushReceiver;
use Tests\TestCase;

/**
 * Notifications by mail and push (E-101).
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-1';

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create(['locale' => 'de']);
    }

    private function device(User $user, ?PushReceiver $browser = null, string $endpoint = self::ENDPOINT): PushSubscription
    {
        $browser ??= new PushReceiver;

        return $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => $browser->publicKey(),
            'auth' => $browser->authSecret(),
            'label' => 'Firefox · Linux',
        ]);
    }

    private function configureMail(): void
    {
        MailSettings::save([
            'host' => 'smtp.example.de', 'port' => 587, 'encryption' => 'starttls', 'username' => 'panel',
            'from_address' => 'panel@example.de', 'from_name' => 'Panel',
        ], 'smtp-secret');
    }

    private function onlineServer(array $attributes = []): Server
    {
        return Server::factory()->create($attributes)->forceFill([
            'checked_at' => now(), 'last_seen_at' => now(), 'check_error' => null,
        ]);
    }

    // Delivery -------------------------------------------------------------

    public function test_push_message_is_encrypted_for_the_device_and_signed(): void
    {
        config(['app.url' => 'https://panel.example.de']);
        $browser = new PushReceiver;
        $device = $this->device($this->admin(), $browser);
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

        $this->assertTrue(PushSender::send($device, ['title' => 'web1 ist nicht erreichbar', 'url' => 'https://panel.example.de/servers/1']));

        Http::assertSent(function (Request $request) use ($browser) {
            $payload = json_decode($browser->decrypt($request->body()), true);
            $claims = PushReceiver::verifyVapid($request->header('Authorization')[0]);

            return $request->url() === self::ENDPOINT
                && $request->header('Content-Encoding')[0] === 'aes128gcm'
                && $payload['title'] === 'web1 ist nicht erreichbar'
                && $claims['aud'] === 'https://fcm.googleapis.com'
                && $claims['sub'] === 'https://panel.example.de';
        });
        $this->assertNotNull($device->fresh()->last_success_at);
    }

    public function test_every_delivery_is_noted_per_device_and_logged_without_secrets(): void
    {
        $admin = $this->admin();
        $device = $this->device($admin);
        Log::spy();
        Http::fakeSequence('fcm.googleapis.com/*')
            ->push('', 201)
            ->push('', 500)
            ->pushFailedConnection();

        PushSender::send($device, ['title' => '2 Meldungen aus der Ruhezeit']);
        PushSender::send($device, ['title' => 'Backup auf web1 fertig']);
        PushSender::send($device, ['title' => 'Snapshot von web1 fertig']);

        $this->assertSame(
            [['Snapshot von web1 fertig', 'unreachable'], ['Backup auf web1 fertig', 'rejected'], ['2 Meldungen aus der Ruhezeit', 'accepted']],
            $device->deliveries()->orderByDesc('id')->get()->map(fn (PushDelivery $delivery) => [$delivery->title, $delivery->state()])->all(),
        );
        $this->assertSame(2, $device->fresh()->failures);
        Log::shouldHaveReceived('info')->with('Push message handed to the push service', \Mockery::on(
            fn (array $context) => $context['service'] === 'fcm.googleapis.com' && ! str_contains(json_encode($context), 'device-1') && ! isset($context['body'])
        ))->times(3);

        $this->actingAs($admin)->get('/account/notifications')->assertOk()
            ->assertSee('Letzte Zustellungen (3)')
            ->assertSeeInOrder(['Snapshot von web1 fertig', 'nicht erreicht', 'Backup auf web1 fertig', 'abgelehnt (500)', '2 Meldungen aus der Ruhezeit', 'angenommen'])
            ->assertSee('2 Fehlversuche seitdem');
    }

    public function test_only_the_newest_deliveries_stay(): void
    {
        $device = $this->device($this->admin());
        foreach (range(1, PushDelivery::KEEP + 5) as $number) {
            $device->recordDelivery(201, "Nachricht {$number}");
        }

        $this->assertSame(PushDelivery::KEEP, $device->deliveries()->count());
        $this->assertSame('Nachricht 6', $device->deliveries()->orderBy('id')->first()->title);
    }

    public function test_device_unknown_to_the_push_service_is_removed(): void
    {
        $device = $this->device($this->admin());
        Http::fake(['*' => Http::response('', 410)]);

        $this->assertFalse(PushSender::send($device, ['title' => 'x']));

        $this->assertModelMissing($device);
    }

    public function test_push_is_never_sent_to_an_unknown_host(): void
    {
        $device = $this->device($this->admin(), null, 'https://attacker.example.com/push');
        Http::fake();

        $this->assertFalse(PushSender::send($device, ['title' => 'x']));

        Http::assertNothingSent();
        $this->assertModelMissing($device);
    }

    public function test_vapid_keys_are_created_once_and_stored_encrypted(): void
    {
        $public = PushSender::publicKey();

        $this->assertSame($public, PushSender::publicKey());
        $this->assertSame(87, strlen($public));
        $this->assertStringNotContainsString('PRIVATE KEY', (string) Setting::get(Setting::VAPID_PRIVATE));
    }

    public function test_mail_lists_all_messages_in_the_language_of_the_admin(): void
    {
        $messages = [
            Notifier::message(NotificationEvent::ServerOffline, 'raised', 'k1', ['server' => 'web1'], 'https://panel.example.de/servers/1'),
            Notifier::message(NotificationEvent::DiskFull, 'resolved', 'k2', ['server' => 'web2', 'percent' => 80], null),
        ];

        $mail = (new NotificationMail($messages))->locale('de');

        $mail->assertHasSubject('[OWPanel] 2 Meldungen aus der Ruhezeit');
        $mail->assertSeeInText('web1 ist nicht erreichbar');
        $mail->assertSeeInText('Speicher auf web2 wieder im grünen Bereich');
        $mail->assertSeeInText('https://panel.example.de/servers/1');
    }

    public function test_delivery_job_sends_mail_and_push(): void
    {
        $admin = $this->admin();
        $browser = new PushReceiver;
        $this->device($admin, $browser);
        Mail::fake();
        Http::fake(['*' => Http::response('', 201)]);
        $message = Notifier::message(NotificationEvent::RebootRequired, 'raised', 'reboot_required:1', ['server' => 'web1'], null);

        (new DeliverNotification($admin->getKey(), 'mail', [$message]))->handle();
        (new DeliverNotification($admin->getKey(), 'push', [$message]))->handle();

        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Http::assertSent(fn (Request $request) => json_decode($browser->decrypt($request->body()), true)['title'] === 'web1 braucht einen Neustart');
    }

    public function test_push_carries_the_number_of_open_alerts_for_the_app_badge(): void
    {
        $admin = $this->admin();
        $browser = new PushReceiver;
        $this->device($admin, $browser);
        Http::fake(['*' => Http::response('', 201)]);
        Alert::create(['key' => 'server_offline:1', 'event' => NotificationEvent::ServerOffline, 'data' => [], 'opened_at' => now()]);
        Alert::create(['key' => 'disk:1', 'event' => NotificationEvent::ServerOffline, 'data' => [], 'opened_at' => now()]);
        Alert::create(['key' => 'old:1', 'event' => NotificationEvent::ServerOffline, 'data' => [], 'opened_at' => now(), 'resolved_at' => now()]);
        $message = Notifier::message(NotificationEvent::ServerOffline, 'resolved', 'server_offline:2', ['server' => 'web2'], null);

        (new DeliverNotification($admin->getKey(), 'push', [$message]))->handle();
        Alert::whereNull('resolved_at')->update(['resolved_at' => now()]);
        (new DeliverNotification($admin->getKey(), 'push', [$message, $message]))->handle();

        $badges = Http::recorded()->map(fn ($pair) => json_decode($browser->decrypt($pair[0]->body()), true)['badge'])->all();
        $this->assertSame([2, 0], $badges);
    }

    public function test_pages_tell_administrators_only_the_open_alerts(): void
    {
        Alert::create(['key' => 'server_offline:1', 'event' => NotificationEvent::ServerOffline, 'data' => [], 'opened_at' => now()]);

        $this->actingAs($this->admin())->get('/')->assertSee('<meta name="ow-open-alerts" content="1">', false);
        $customer = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->actingAs($customer)->get('/')->assertDontSee('ow-open-alerts', false);
    }

    // Once and all-clear, choices, quiet hours -----------------------------

    public function test_problem_is_sent_once_and_the_all_clear_once(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->device($admin);

        Notifier::raise(NotificationEvent::ServerOffline, 'server_offline:1', ['server' => 'web1']);
        Notifier::raise(NotificationEvent::ServerOffline, 'server_offline:1', ['server' => 'web1']);
        Notifier::resolve('server_offline:1');
        Notifier::resolve('server_offline:1');

        Queue::assertPushed(DeliverNotification::class, 2);
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->messages[0]['state'] === 'raised' && $job->channel === 'push');
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->messages[0]['state'] === 'resolved');
    }

    public function test_only_chosen_channels_and_only_administrators_are_notified(): void
    {
        Queue::fake();
        $this->configureMail();
        $admin = $this->admin();
        $admin->forceFill(['notification_prefs' => ['server_offline' => ['mail' => false, 'push' => true]]])->save();
        $this->device($admin);
        $customer = User::factory()->create();
        $this->device($customer, null, 'https://fcm.googleapis.com/fcm/send/customer');

        Notifier::raise(NotificationEvent::ServerOffline, 'server_offline:1', ['server' => 'web1']);

        Queue::assertPushed(DeliverNotification::class, 1);
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->userId === $admin->getKey() && $job->channel === 'push');
    }

    public function test_customers_never_receive_messages_even_if_queued(): void
    {
        $customer = User::factory()->create();
        $browser = new PushReceiver;
        $this->device($customer, $browser);
        Http::fake();

        (new DeliverNotification($customer->getKey(), 'push', [Notifier::message('test', 'raised', 't', [], null)]))->handle();

        Http::assertNothingSent();
        $this->assertFalse($customer->wantsNotification(NotificationEvent::ServerOffline, Channel::Push));
    }

    public function test_messages_wait_during_quiet_hours_and_come_together_afterwards(): void
    {
        Queue::fake();
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $admin = $this->admin();
        $admin->forceFill(['quiet_enabled' => true, 'quiet_from' => '22:00', 'quiet_until' => '07:00'])->save();
        $this->device($admin);

        $this->travelTo(now()->setTimezone('Europe/Berlin')->setTime(23, 30)->utc());
        Notifier::raise(NotificationEvent::ServerOffline, 'server_offline:1', ['server' => 'web1']);
        Notifier::raise(NotificationEvent::DiskFull, 'disk_full:1', ['server' => 'web1', 'percent' => 93]);
        Notifier::flushPending();

        Queue::assertNothingPushed();
        $this->assertSame(2, PendingNotification::count());

        $this->travelTo(now()->setTimezone('Europe/Berlin')->addDay()->setTime(7, 1)->utc());
        Notifier::flushPending();

        Queue::assertPushed(DeliverNotification::class, 1);
        Queue::assertPushed(DeliverNotification::class, fn ($job) => count($job->messages) === 2);
        $this->assertSame(0, PendingNotification::count());
    }

    public function test_quiet_hours_within_one_day(): void
    {
        config(['owpanel.timezone' => 'UTC']);
        $user = User::factory()->admin()->make(['quiet_enabled' => true, 'quiet_from' => '12:00', 'quiet_until' => '14:00']);

        $this->assertTrue($user->inQuietHours(now()->setTime(13, 0)));
        $this->assertFalse($user->inQuietHours(now()->setTime(14, 0)));
        $this->assertFalse($user->inQuietHours(now()->setTime(11, 59)));
    }

    // Checks -----------------------------------------------------------------

    public function test_offline_server_raises_and_resolves(): void
    {
        Queue::fake();
        $server = $this->onlineServer();
        $server->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen("server_offline:{$server->id}"));

        $server->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen("server_offline:{$server->id}"));

        $server->forceFill(['last_seen_at' => now()])->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen("server_offline:{$server->id}"));
    }

    public function test_disk_alert_uses_two_thresholds(): void
    {
        Queue::fake();
        $server = $this->onlineServer();
        $server->save();
        $step = 0;
        $metric = function (int $used) use ($server, &$step): void {
            (new ServerMetric)->forceFill([
                'server_id' => $server->id, 'recorded_at' => now()->addSeconds(++$step),
                'cpu_percent' => 1, 'load1' => 0, 'load5' => 0, 'load15' => 0, 'network_rx' => 0, 'network_tx' => 0,
                'memory_used' => 1, 'memory_total' => 2, 'swap_used' => 0, 'swap_total' => 0,
                'disk_used' => $used, 'disk_total' => 100,
            ])->save();
        };
        $key = "disk_full:{$server->id}";

        $metric(91);
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen($key));
        $this->assertSame(91, Alert::firstWhere('key', $key)->data['params']['percent']);

        $metric(87);
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen($key));

        $metric(80);
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));
    }

    public function test_failed_scheduled_backup_raises_and_manual_runs_are_ignored(): void
    {
        Queue::fake();
        $server = $this->onlineServer();
        $server->save();
        $site = Site::factory()->for($server)->create(['domain' => 'shop.example.de']);
        $task = fn (TaskStatus $status, array $arguments) => ServerTask::factory()->create([
            'server_id' => $server->id, 'site_id' => $site->id, 'script' => ServerScript::SiteBackup,
            'arguments' => $arguments, 'status' => $status, 'finished_at' => now(),
        ]);
        $key = "backup_failed:{$server->id}";

        $task(TaskStatus::Failed, ['--manual', 'ow10001']);
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));

        $this->travel(1)->minutes();
        $task(TaskStatus::Failed, ['ow10001']);
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen($key));
        $this->assertSame('shop.example.de', Alert::firstWhere('key', $key)->data['params']['items']);

        // A successful backup by hand gives the all-clear.
        $this->travel(1)->minutes();
        $task(TaskStatus::Succeeded, ['--manual', 'ow10001']);
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));
    }

    public function test_failed_snapshot_raises(): void
    {
        Queue::fake();
        $server = $this->onlineServer();
        $server->save();
        $server->snapshots()->create(['trigger' => 'schedule', 'status' => ServerSnapshot::FAILED]);

        (new AlertChecker)->run();

        $this->assertTrue(Notifier::isOpen("snapshot_failed:{$server->id}"));
    }

    public function test_alerts_of_deleted_servers_end_without_a_message(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->device($admin);
        $server = $this->onlineServer(['name' => 'gone']);
        $server->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        (new AlertChecker)->run();
        Queue::assertPushed(DeliverNotification::class, 1);

        $server->delete();
        (new AlertChecker)->run();

        Queue::assertPushed(DeliverNotification::class, 1);
        $this->assertFalse(Notifier::isOpen("server_offline:{$server->id}"));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function serverWithUpdates(array $override = []): Server
    {
        $server = $this->onlineServer(['name' => 'web1']);
        $server->forceFill(['updates' => array_replace([
            'pending' => 3, 'security' => 1, 'packages' => [], 'runs' => [], 'automatic' => true,
            'reboot' => ['required' => false], 'pending_since' => now()->subHours(2)->toIso8601String(),
        ], $override), 'updates_checked_at' => now()])->save();

        return $server;
    }

    public function test_updates_waiting_for_a_day_are_reported_once_and_end_silently(): void
    {
        Queue::fake();
        $this->device($this->admin());
        $server = $this->serverWithUpdates();
        $key = "updates_available:{$server->id}";

        // Fresh updates: the night may still install them.
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));

        $server->forceFill(['updates' => array_replace($server->updates, ['pending_since' => now()->subHours(25)->toIso8601String()])])->save();
        (new AlertChecker)->run();
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen($key));
        $this->assertCount(1, $this->pushed('updates_available'));
        $this->assertSame('3 Updates warten auf web1', MessageText::title($this->pushed('updates_available')[0]->messages[0]));

        // Installed: "updates installed" has its own message.
        $server->forceFill(['updates' => array_replace($server->updates, ['pending' => 0, 'security' => 0, 'pending_since' => null])])->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));
        $this->assertCount(1, $this->pushed('updates_available'));
    }

    public function test_an_offline_server_keeps_its_update_alerts(): void
    {
        Queue::fake();
        $server = $this->serverWithUpdates(['pending_since' => now()->subDays(2)->toIso8601String(), 'automatic' => false]);
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen("updates_available:{$server->id}"));
        $this->assertTrue(Notifier::isOpen("auto_updates_off:{$server->id}"));

        $server->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen("updates_available:{$server->id}"));
        $this->assertTrue(Notifier::isOpen("auto_updates_off:{$server->id}"));
    }

    public function test_automatic_updates_switched_off_raise_and_resolve(): void
    {
        Queue::fake();
        $this->device($this->admin());
        $server = $this->serverWithUpdates(['automatic' => false]);
        (new AlertChecker)->run();
        $this->assertSame('Automatische Updates auf web1 sind aus', MessageText::title($this->pushed('auto_updates_off')[0]->messages[0]));

        $server->forceFill(['updates' => array_replace($server->updates, ['automatic' => true])])->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen("auto_updates_off:{$server->id}"));
        $this->assertCount(2, $this->pushed('auto_updates_off'));
    }

    public function test_the_panel_certificate_warns_like_a_website(): void
    {
        Queue::fake();
        $this->device($this->admin());
        config(['app.url' => 'https://panel.example.de']);
        $server = $this->onlineServer(['name' => 'web1']);
        $server->forceFill(['panel_certificate_expires_at' => now()->addDays(5)])->save();

        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen("certificate_expiring:panel:{$server->id}"));
        $this->assertStringContainsString('panel.example.de', MessageText::title($this->pushed('certificate_expiring')[0]->messages[0]));

        $server->forceFill(['panel_certificate_expires_at' => now()->addDays(80)])->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen("certificate_expiring:panel:{$server->id}"));
    }

    public function test_a_stopped_service_raises_and_resolves_and_waits_while_offline(): void
    {
        Queue::fake();
        $this->device($this->admin());
        $server = $this->onlineServer(['name' => 'web1']);
        $server->forceFill(['services' => ['nginx' => true, 'mariadb' => false]])->save();

        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen("service_down:{$server->id}:mariadb"));
        $this->assertFalse(Notifier::isOpen("service_down:{$server->id}:nginx"));
        $this->assertSame('mariadb auf web1 läuft nicht', MessageText::title($this->pushed('service_down')[0]->messages[0]));

        $server->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen("service_down:{$server->id}:mariadb"));

        $server->forceFill(['last_seen_at' => now(), 'services' => ['nginx' => true, 'mariadb' => true]])->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen("service_down:{$server->id}:mariadb"));
        $this->assertCount(2, $this->pushed('service_down'));
    }

    public function test_a_new_panel_release_is_reported_once_per_version(): void
    {
        Queue::fake();
        $this->device($this->admin());
        config(['owpanel.version' => '1.0.0']);
        Setting::put(Setting::PANEL_RELEASE, (string) json_encode(['version' => '1.1.0', 'checked_at' => now()->toIso8601String(), 'error' => null]));

        (new AlertChecker)->run();
        (new AlertChecker)->run();
        $this->assertCount(1, $this->pushed('panel_update_available'));
        $this->assertSame('OWPanel 1.1.0 ist erschienen', MessageText::title($this->pushed('panel_update_available')[0]->messages[0]));

        Setting::put(Setting::PANEL_RELEASE, (string) json_encode(['version' => '1.2.0', 'checked_at' => now()->toIso8601String(), 'error' => null]));
        (new AlertChecker)->run();
        $this->assertCount(2, $this->pushed('panel_update_available'));
        $this->assertFalse(Notifier::isOpen('panel_update_available:1.1.0'));

        // Installed: ends without a message. Test builds never compare.
        config(['owpanel.version' => '1.2.0']);
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen('panel_update_available:1.2.0'));
        config(['owpanel.version' => 'dev-abc1234']);
        (new AlertChecker)->run();
        $this->assertCount(2, $this->pushed('panel_update_available'));
    }

    // One-off events (E-104) ---------------------------------------------------

    private function pushed(string $event): Collection
    {
        return collect(Queue::pushed(DeliverNotification::class))
            ->filter(fn ($job) => $job->messages[0]['event'] === $event)->values();
    }

    public function test_failed_tasks_are_reported_but_not_scheduled_backups_or_reads(): void
    {
        Queue::fake();
        $this->device($this->admin());
        $server = $this->onlineServer(['name' => 'web1']);
        $server->save();
        $failed = fn (ServerScript $script, array $arguments = []) => ServerTask::factory()->create([
            'server_id' => $server->id, 'script' => $script, 'arguments' => $arguments,
            'status' => TaskStatus::Failed, 'error' => 'certbot_failed', 'finished_at' => now(),
        ]);

        Events::taskFinished($failed(ServerScript::SiteCertificates));
        Events::taskFinished($failed(ServerScript::SiteBackup, ['--user', 'ow10001']));
        Events::taskFinished($failed(ServerScript::ServerUpdates));

        $jobs = $this->pushed('task_failed');
        $this->assertCount(1, $jobs);
        app()->setLocale('de');
        $this->assertStringContainsString('web1', MessageText::title($jobs[0]->messages[0]));
    }

    public function test_scheduled_backups_give_one_message_when_all_of_the_run_succeeded(): void
    {
        Queue::fake();
        config(['owpanel.timezone' => 'UTC']);
        $this->device($this->admin());
        $server = $this->onlineServer(['name' => 'web1']);
        $server->forceFill(['backup_time' => '00:00'])->save();
        $sites = Site::factory()->for($server)->count(2)->sequence(['domain' => 'a.example.de'], ['domain' => 'b.example.de'])->create();
        $tasks = $sites->map(fn (Site $site) => ServerTask::factory()->create([
            'server_id' => $server->id, 'site_id' => $site->id, 'script' => ServerScript::SiteBackup,
            'arguments' => ['--user', $site->system_user], 'status' => TaskStatus::Running, 'started_at' => now(),
            'batch' => '01RUN0000000000000000000AA',
        ]));

        $tasks[0]->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();
        Events::taskFinished($tasks[0]);
        $this->assertCount(0, $this->pushed('backup_succeeded'));

        $tasks[1]->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()->addMinutes(3)])->save();
        Events::taskFinished($tasks[1]);
        $jobs = $this->pushed('backup_succeeded');
        $this->assertCount(1, $jobs);
        $this->assertSame('a.example.de, b.example.de', $jobs[0]->messages[0]['params']['items']);
    }

    public function test_a_later_backup_of_a_new_website_names_only_that_website(): void
    {
        Queue::fake();
        $this->device($this->admin());
        $server = $this->onlineServer(['name' => 'web1']);
        $old = Site::factory()->for($server)->create(['domain' => 'old.example.de']);
        $new = Site::factory()->for($server)->create(['domain' => 'new.example.de']);
        $done = fn (array $values) => ServerTask::factory()->create(array_replace([
            'server_id' => $server->id, 'script' => ServerScript::SiteBackup, 'status' => TaskStatus::Succeeded,
        ], $values));

        // The night's run and the panel's first backup after connecting the Storage Box.
        $done(['site_id' => $old->id, 'arguments' => ['--user', $old->system_user], 'batch' => '01RUN0000000000000000000AA',
            'started_at' => now()->subMinutes(40), 'finished_at' => now()->subMinutes(39)]);
        $done(['script' => ServerScript::PanelBackup, 'arguments' => [], 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        $late = $done(['site_id' => $new->id, 'arguments' => ['--user', $new->system_user], 'batch' => '01RUN0000000000000000000BB',
            'started_at' => now()->subSeconds(20), 'finished_at' => now()]);

        Events::taskFinished($late);

        $jobs = $this->pushed('backup_succeeded');
        $this->assertCount(1, $jobs);
        $this->assertSame('new.example.de', $jobs[0]->messages[0]['params']['items']);
        $this->assertSame('20 Sekunden', $jobs[0]->messages[0]['params']['duration']);
    }

    public function test_the_scheduler_starts_one_batch_per_server_run(): void
    {
        Queue::fake();
        config(['owpanel.timezone' => 'UTC']);
        $this->travelTo(now()->setTime(3, 0));
        $server = $this->onlineServer(['backup_time' => '02:30', 'backup_ready' => true, 'backup_box_id' => 1]);
        Site::factory()->for($server)->count(2)->create(['backup_enabled' => true]);

        $this->artisan('owpanel:backups')->assertSuccessful();

        $batches = ServerTask::where('script', ServerScript::SiteBackup->value)->pluck('batch');
        $this->assertCount(2, $batches);
        $this->assertNotNull($batches[0]);
        $this->assertSame($batches[0], $batches[1]);
    }

    public function test_manual_backup_restore_snapshot_restart_and_updates_are_reported(): void
    {
        Queue::fake();
        $this->device($this->admin());
        $server = $this->onlineServer(['name' => 'web1']);
        $server->save();
        $site = Site::factory()->for($server)->create(['domain' => 'shop.example.de']);
        $done = fn (ServerScript $script, array $arguments) => ServerTask::factory()->create([
            'server_id' => $server->id, 'site_id' => $site->id, 'script' => $script, 'arguments' => $arguments,
            'status' => TaskStatus::Succeeded, 'started_at' => now()->subSeconds(42), 'finished_at' => now(),
        ]);

        Events::taskFinished($done(ServerScript::SiteBackup, ['--user', $site->system_user, '--manual']));
        Events::taskFinished($done(ServerScript::SiteRestore, ['--user', $site->system_user]));
        Events::snapshotDone($server->snapshots()->create(['trigger' => 'manual', 'status' => ServerSnapshot::DONE, 'finished_at' => now()]));
        Events::serverRestarted($server, now());
        Events::updatesInstalled($server, ['count' => 3, 'kind' => 'automatic', 'packages' => ['openssl', 'libssl3']]);

        foreach (['backup_succeeded', 'restore_finished', 'snapshot_succeeded', 'server_restarted', 'updates_installed'] as $event) {
            $this->assertCount(1, $this->pushed($event), $event);
        }
        app()->setLocale('de');
        $this->assertSame('Auf web1, Dauer: 42 Sekunden.', MessageText::body($this->pushed('restore_finished')[0]->messages[0]));
        $this->assertSame('3 Updates auf web1 eingespielt', MessageText::title($this->pushed('updates_installed')[0]->messages[0]));
    }

    public function test_events_can_be_switched_off(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $admin->forceFill(['notification_prefs' => ['server_restarted' => ['mail' => false, 'push' => false]]])->save();
        $this->device($admin);
        $server = $this->onlineServer();
        $server->save();

        Events::serverRestarted($server, now());

        Queue::assertNothingPushed();
    }

    // Page and access --------------------------------------------------------

    public function test_admin_sees_and_saves_own_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('account.notifications'))
            ->assertOk()
            ->assertSee(__('notifications.events.backup_failed.label'))
            ->assertSee(route('account.notifications.subscribe'), false);

        $this->actingAs($admin)->put(route('account.notifications.update'), [
            'events' => ['server_offline' => ['push' => '1']],
            'quiet_enabled' => '1', 'quiet_from' => '23:00', 'quiet_until' => '06:30',
        ])->assertRedirect(route('account.notifications'));

        $admin->refresh();
        $this->assertTrue($admin->wantsNotification(NotificationEvent::ServerOffline, Channel::Push));
        $this->assertFalse($admin->wantsNotification(NotificationEvent::ServerOffline, Channel::Mail));
        $this->assertFalse($admin->wantsNotification(NotificationEvent::BackupFailed, Channel::Push));
        $this->assertTrue($admin->quiet_enabled);
        $this->assertSame('06:30', $admin->quiet_until);
    }

    public function test_invalid_quiet_time_is_refused(): void
    {
        $this->actingAs($this->admin())->put(route('account.notifications.update'), [
            'quiet_from' => '25:00', 'quiet_until' => '07:00',
        ])->assertSessionHasErrors('quiet_from');
    }

    public function test_device_is_registered_with_a_known_push_service_only(): void
    {
        $admin = $this->admin();
        $browser = new PushReceiver;
        $keys = ['p256dh' => $browser->publicKey(), 'auth' => $browser->authSecret()];

        $this->actingAs($admin)->postJson(route('account.notifications.subscribe'), [
            'endpoint' => 'https://evil.example.com/push', 'keys' => $keys,
        ])->assertStatus(422);

        $this->actingAs($admin)->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0')
            ->postJson(route('account.notifications.subscribe'), ['endpoint' => self::ENDPOINT, 'keys' => $keys])
            ->assertCreated();

        $device = $admin->pushSubscriptions()->sole();
        $this->assertSame('Firefox · Linux', $device->label);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.device_added']);
    }

    public function test_customer_has_no_access(): void
    {
        $customer = User::factory()->create();
        $browser = new PushReceiver;

        $this->actingAs($customer)->get(route('account.notifications'))->assertForbidden();
        $this->actingAs($customer)->put(route('account.notifications.update'), ['quiet_from' => '22:00', 'quiet_until' => '07:00'])->assertForbidden();
        $this->actingAs($customer)->postJson(route('account.notifications.subscribe'), [
            'endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => $browser->publicKey(), 'auth' => $browser->authSecret()],
        ])->assertForbidden();
        $this->actingAs($customer)->post(route('account.notifications.test'))->assertForbidden();
        $this->actingAs($customer)->get(route('account.show'))->assertDontSee(route('account.notifications'));
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_admin_cannot_remove_the_device_of_another_admin(): void
    {
        $other = $this->device($this->admin());

        $this->withoutMiddleware(ThrottleRequests::class)
            ->actingAs($this->admin())->delete(route('account.notifications.destroy', $other))->assertForbidden();

        $this->assertModelExists($other);
    }

    public function test_admin_removes_own_device(): void
    {
        $admin = $this->admin();
        $device = $this->device($admin);

        $this->actingAs($admin)->delete(route('account.notifications.destroy', $device))->assertRedirect(route('account.notifications'));

        $this->assertModelMissing($device);
    }

    public function test_test_message_ignores_quiet_hours(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $admin->forceFill(['quiet_enabled' => true, 'quiet_from' => '00:00', 'quiet_until' => '23:59'])->save();
        $this->device($admin);

        $this->actingAs($admin)->post(route('account.notifications.test'))->assertSessionHas('status');

        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->channel === 'push' && $job->messages[0]['event'] === 'test');
    }

    public function test_test_without_any_channel_warns(): void
    {
        $this->actingAs($this->admin())->post(route('account.notifications.test'))->assertSessionHas('warning');
    }

    public function test_service_worker_and_manifest_exist(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString('setAppBadge', (string) file_get_contents(public_path('sw.js')));
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }
}
