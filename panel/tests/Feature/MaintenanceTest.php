<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Livewire\UpdatesChecked;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\MaintenanceCheck;
use App\Servers\ScriptRunner;
use App\Servers\StatusCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeScriptRunner;
use Tests\Fakes\ScriptedRunner;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private static function statusAnswer(array $override = []): array
    {
        return array_replace([
            'hostname' => 'web1.example.de', 'os' => 'Debian GNU/Linux 13 (trixie)', 'kernel' => '6.12.48+deb13-amd64',
            'uptime' => 3600, 'cpu_cores' => 2, 'cpu' => 5.0, 'load' => [0.1, 0.1, 0.1],
            'memory' => ['total' => 1000, 'used' => 500], 'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 1000, 'used' => 100], 'network' => ['rx' => 1, 'tx' => 1],
            'reboot_required' => false,
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    private static function updatesAnswer(array $override = []): array
    {
        return array_replace([
            'pending' => 3,
            'security' => 1,
            'packages' => [
                ['name' => 'libssl3t64', 'from' => '3.5.1-1', 'to' => '3.5.4-1~deb13u1', 'security' => true],
                ['name' => 'nginx', 'from' => '1.29.2-1~trixie', 'to' => '1.29.3-1~trixie', 'security' => false],
                ['name' => 'linux-image-6.12.57+deb13-amd64', 'from' => '', 'to' => '6.12.57-1', 'security' => false],
            ],
            'runs' => [
                ['at' => 1762751521, 'kind' => 'automatic', 'count' => 2, 'packages' => ['openssl', 'libssl3t64']],
            ],
            'lists_updated' => 1762750800,
            'upgraded' => 1762751540,
            'automatic' => true,
            'reboot' => ['required' => false, 'kernel_running' => '6.12.48+deb13-amd64', 'kernel_installed' => '', 'packages' => []],
        ], $override);
    }

    private function runner(array $answers): ScriptedRunner
    {
        $runner = new ScriptedRunner($answers);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_the_first_check_also_reads_updates_and_certificates(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        $site = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow38217']);
        $runner = $this->runner([
            'server-status' => self::statusAnswer(),
            'server-updates' => self::updatesAnswer(),
            'site-certificates' => [
                'certificates' => [
                    ['user' => 'ow10000', 'expires' => now()->addDays(80)->getTimestamp(), 'domains' => ['panel.example.de']],
                    ['user' => 'ow38217', 'expires' => now()->addDays(10)->getTimestamp(), 'domains' => ['shop.example.de']],
                    ['user' => 'root', 'expires' => 1, 'domains' => []],
                ],
                'renewal_timer' => true,
            ],
        ]);

        $this->artisan('owpanel:check-servers')->assertSuccessful();

        $this->assertSame(['server-status', 'server-updates', 'site-certificates'], $runner->scripts());
        $server->refresh();
        $this->assertSame('security', $server->updateState());
        $this->assertSame(3, $server->pendingUpdates());
        $this->assertSame(1, $server->securityUpdates());
        $this->assertTrue($server->certificate_renewal);
        $this->assertNotNull($server->panel_certificate_expires_at);
        $this->assertSame('expiring', $site->fresh()->certificateState());

        // Within the hour only the status is asked for again. The fake
        // always answers with the same uptime, which would look like a
        // restart; forgetting the boot time keeps that out of this test.
        $server->forceFill(['booted_at' => null])->save();
        $this->travel(10)->minutes();
        $this->artisan('owpanel:check-servers');
        $this->assertSame(['server-status', 'server-updates', 'site-certificates', 'server-status'], $runner->scripts());

        $this->travel(61)->minutes();
        $this->artisan('owpanel:check-servers');
        $this->assertCount(7, $runner->calls);
    }

    public function test_the_panel_remembers_since_when_updates_wait(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        $this->runner(['server-updates' => self::updatesAnswer(), 'site-certificates' => ['certificates' => [], 'renewal_timer' => true]]);
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
        app(MaintenanceCheck::class)->run($server);
        $since = $server->fresh()->updates['pending_since'];
        $this->assertNotNull($since);

        // Later checks keep the first time while updates still wait.
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
        app(MaintenanceCheck::class)->run($server->fresh());
        $this->assertSame($since, $server->fresh()->updates['pending_since']);

        // Nothing pending: the time is gone and starts anew next time.
        $this->runner(['server-updates' => self::updatesAnswer(['pending' => 0, 'security' => 0, 'packages' => []]), 'site-certificates' => ['certificates' => [], 'renewal_timer' => true]]);
        app(MaintenanceCheck::class)->run($server->fresh());
        $this->assertNull($server->fresh()->updates['pending_since']);
    }

    public function test_an_offline_server_is_not_asked_for_updates(): void
    {
        Server::factory()->create();
        $runner = new FakeScriptRunner(connectionError: 'ssh_connection_failed');
        $this->app->instance(ScriptRunner::class, $runner);

        $this->artisan('owpanel:check-servers');

        $this->assertCount(1, $runner->calls);
        $this->assertSame(ServerScript::ServerStatus, $runner->calls[0]->script);
    }

    public function test_a_failed_update_check_keeps_the_last_answer(): void
    {
        $server = Server::factory()->create(['updates' => self::updatesAnswer(), 'updates_checked_at' => now()->subHours(2)]);
        $this->runner(['server-status' => self::statusAnswer()]);

        $this->artisan('owpanel:check-servers');

        $server->refresh();
        $this->assertSame('test_failed', $server->updates_error);
        $this->assertSame(3, $server->pendingUpdates());
        $this->assertTrue($server->updates_checked_at->isToday());
    }

    public function test_implausible_update_answers_are_cleaned_or_rejected(): void
    {
        $server = Server::factory()->create();
        $this->runner([
            'server-status' => self::statusAnswer(),
            'server-updates' => self::updatesAnswer([
                'packages' => [
                    ['name' => '<b>x</b>', 'from' => '1', 'to' => '2', 'security' => false],
                    ['name' => 'ok', 'from' => '"1', 'to' => '2', 'security' => 'yes'],
                ],
                'runs' => [['at' => -5, 'kind' => 'automatic', 'count' => 1, 'packages' => []], ['at' => 5, 'kind' => 'evil', 'count' => 1, 'packages' => []]],
                'security' => 9,
            ]),
        ]);

        $this->artisan('owpanel:check-servers');

        $updates = $server->fresh()->updates;
        $this->assertSame([['name' => 'ok', 'from' => '', 'to' => '2', 'security' => false]], $updates['packages']);
        $this->assertSame([], $updates['runs']);
        $this->assertSame(3, $updates['security']);

        Server::query()->update(['updates_checked_at' => null]);
        $this->runner(['server-status' => self::statusAnswer(), 'server-updates' => ['pending' => 'many']]);
        $this->artisan('owpanel:check-servers');
        $this->assertSame('invalid_result', $server->fresh()->updates_error);
    }

    public function test_update_states(): void
    {
        $this->assertSame('unknown', Server::factory()->make()->updateState());
        $this->assertSame('current', Server::factory()->make(['updates' => self::updatesAnswer(['pending' => 0, 'security' => 0, 'packages' => []])])->updateState());
        $this->assertSame('pending', Server::factory()->make(['updates' => self::updatesAnswer(['security' => 0])])->updateState());
        $this->assertSame('security', Server::factory()->make(['updates' => self::updatesAnswer()])->updateState());
        $this->assertSame('reboot', Server::factory()->make(['reboot_required' => true])->updateState());
        $this->assertSame('reboot', Server::factory()->make(['updates' => self::updatesAnswer(['reboot' => ['required' => true]])])->updateState());
    }

    public function test_after_a_restart_the_newer_status_check_clears_the_reboot_hint(): void
    {
        $updates = self::updatesAnswer(['pending' => 0, 'security' => 0, 'packages' => [], 'reboot' => ['required' => true]]);

        // The hourly update check saw a new kernel; then the server restarted
        // and the minutely status check no longer reports a needed restart.
        $server = Server::factory()->make([
            'updates' => $updates,
            'updates_checked_at' => now()->subMinutes(30),
            'checked_at' => now(),
            'reboot_required' => false,
        ]);
        $this->assertFalse($server->rebootRequired());
        $this->assertSame('current', $server->updateState());

        // The update check is newer: its flag counts.
        $server->updates_checked_at = now()->addMinutes(5);
        $this->assertSame('reboot', $server->updateState());

        // The status check failed: either flag counts.
        $server->forceFill(['updates_checked_at' => now()->subMinutes(30), 'check_error' => 'ssh_connection_failed']);
        $this->assertSame('reboot', $server->updateState());
    }

    public function test_a_restart_seen_by_the_status_check_brings_the_update_check_forward(): void
    {
        Carbon::setTestNow('2026-11-11 08:00:00');
        $server = Server::factory()->create([
            'booted_at' => now()->subDays(3),
            'updates_checked_at' => now()->subMinutes(10),
        ]);
        $status = json_encode([
            'ok' => true, 'script' => 'server-status', 'hostname' => 'web1.example.de', 'os' => 'Debian GNU/Linux 13 (trixie)',
            'kernel' => '6.12.57+deb13-amd64', 'uptime' => 120, 'cpu_cores' => 2, 'cpu' => 1.0, 'load' => [0.1, 0.1, 0.1],
            'memory' => ['total' => 4_000_000_000, 'used' => 1_000_000_000], 'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 40_000_000_000, 'used' => 1_000_000_000], 'network' => ['rx' => 1, 'tx' => 1], 'reboot_required' => false,
        ])."\n";

        (new StatusCheck(new FakeScriptRunner([$status])))->run($server);
        $this->assertNull($server->refresh()->updates_checked_at);
        $this->assertTrue(MaintenanceCheck::isDue($server));

        // The next minute, without a restart, changes nothing.
        $server->forceFill(['updates_checked_at' => now()])->save();
        Carbon::setTestNow('2026-11-11 08:01:00');
        (new StatusCheck(new FakeScriptRunner([str_replace('"uptime":120', '"uptime":180', $status)])))->run($server);
        $this->assertNotNull($server->refresh()->updates_checked_at);
    }

    public function test_the_updates_page_shows_state_packages_history_and_reboot(): void
    {
        $server = Server::factory()->create([
            'name' => 'web1',
            'hostname' => 'web1.example.de',
            'host' => '127.0.0.1',
            'reboot_required' => true,
            'updates' => self::updatesAnswer(['reboot' => ['required' => true, 'kernel_running' => '6.12.48+deb13-amd64', 'kernel_installed' => '6.12.57+deb13-amd64', 'packages' => ['linux-image-amd64']]]),
            'updates_checked_at' => now(),
        ]);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/updates")
            ->assertOk()
            ->assertSee('Kernel 6.12.48+deb13-amd64 läuft, 6.12.57+deb13-amd64 ist installiert.')
            ->assertSee('Neustart angefordert von: linux-image-amd64')
            ->assertSee('3 Updates stehen aus, davon 1 für die Sicherheit.')
            ->assertSee('libssl3t64')
            ->assertSee('3.5.4-1~deb13u1')
            ->assertSee('Automatisch')
            ->assertSee('openssl, libssl3t64')
            ->assertSee('Auf diesem Server läuft auch das Panel.')
            ->assertSee('Jetzt neu starten');
    }

    public function test_the_overview_shows_the_central_hint(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create([
            'name' => 'web1', 'checked_at' => now(), 'last_seen_at' => now(),
            'updates' => self::updatesAnswer(['pending' => 0, 'security' => 0, 'packages' => []]),
        ]);

        $this->actingAs($admin)->get('/')
            ->assertSee('Alle Server sind erreichbar und aktuell, alle Zertifikate gültig.');

        $server->forceFill(['reboot_required' => true])->save();
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'shop.example.de', 'certificate_expires_at' => now()->addDays(5)]);

        $this->actingAs($admin)->get('/')
            ->assertSee('Einige Server brauchen Aufmerksamkeit.')
            ->assertSee('Neustart nötig')
            ->assertSee('shop.example.de')
            ->assertSee('läuft ab am');

        $this->actingAs($admin)->get('/servers')->assertSee('Neustart nötig');
        $this->actingAs($admin)->get("/servers/{$server->id}")->assertSee('Der Server muss neu gestartet werden');
    }

    public function test_check_now_asks_at_the_next_minute(): void
    {
        $server = Server::factory()->create(['updates_checked_at' => now()]);
        $runner = $this->runner(['server-upgrade' => ['mode' => 'refresh']]);

        // "Check now" first refreshes the package lists (E-154) …
        $this->actingAs($this->admin())->from("/servers/{$server->id}/updates")
            ->post("/servers/{$server->id}/updates/check")
            ->assertRedirect("/servers/{$server->id}/updates");
        $this->assertSame(['--refresh'], $runner->calls[0]->arguments);

        // … and then the state is read again at the next minute.
        $this->assertNull($server->fresh()->updates_checked_at);

        // Until the next check the page says so and waits, then reloads.
        $this->actingAs($this->admin())->get("/servers/{$server->id}/updates")->assertOk()->assertSee('Wird geprüft …');
        $waiter = Livewire::actingAs($this->admin())->test(UpdatesChecked::class, ['server' => $server])
            ->assertSeeHtml('wire:poll.5s');
        Server::whereKey($server->id)->update(['updates_checked_at' => now()]);
        $waiter->call('$refresh')->assertDontSeeHtml('wire:poll')->assertDontSee('Wird geprüft');
        $this->assertStringContainsString('window.location.reload()', json_encode($waiter->effects));
    }

    public function test_reboot_needs_the_server_name_and_runs_as_server_task(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $server = Server::factory()->create(['name' => 'web1', 'hostname' => 'web1.example.de']);

        $this->actingAs($admin)->post("/servers/{$server->id}/reboot", ['confirm_name' => 'web2'])
            ->assertSessionHasErrors(['confirm_name' => 'Der Name stimmt nicht mit dem Server überein.']);
        $this->actingAs($admin)->post("/servers/{$server->id}/reboot", [])
            ->assertSessionHasErrors('confirm_name');
        $this->assertSame(0, ServerTask::count());

        $this->actingAs($admin)->post("/servers/{$server->id}/reboot", ['confirm_name' => ' WEB1.example.de '])
            ->assertRedirect("/servers/{$server->id}");
        $this->assertSame(1, ServerTask::count());

        $task = ServerTask::sole();
        $this->assertSame(ServerScript::ServerReboot, $task->script);
        $this->assertSame([], $task->arguments);
        $this->assertSame(1, AuditLog::where('action', 'server.reboot_requested')->count());

        // A second request while the first one waits is refused.
        $this->actingAs($admin)->post("/servers/{$server->id}/reboot", ['confirm_name' => 'web1'])
            ->assertSessionHas('error', 'Ein Neustart ist bereits angefordert.');
        $this->assertSame(1, ServerTask::count());
        $this->actingAs($admin)->get("/servers/{$server->id}/updates")->assertSee('Ein Neustart ist bereits angefordert.');
    }

    public function test_a_certificate_can_be_requested_for_an_http_site(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->withoutTls()->create(['domain' => 'shop.example.de', 'system_user' => 'ow23456']);
        $this->actingAs($admin)->get("/sites/{$site->id}/certificate")->assertSee('Zertifikat anfordern');

        $expires = now()->addDays(90)->startOfSecond();
        $runner = $this->runner(['site-create' => ['domain' => 'shop.example.de', 'user' => 'ow23456', 'uid' => 23456, 'php' => '8.5', 'tls' => true, 'certificate_expires' => $expires->getTimestamp()]]);

        // Back to the page the request came from (E-152).
        $this->actingAs($admin)->post("/sites/{$site->id}/certificate")
            ->assertRedirect("/sites/{$site->id}/certificate");

        $this->assertSame(['--domain', 'shop.example.de', '--type', 'php', '--user', 'ow23456', '--tls'], $runner->calls[0]->arguments);
        $site->refresh();
        $this->assertTrue($site->tls);
        $this->assertTrue($site->wants_tls);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertTrue($expires->equalTo($site->certificate_expires_at));
        $this->assertSame('valid', $site->certificateState());
        $this->assertSame(1, AuditLog::where('action', 'site.certificate_requested')->count());

        $this->actingAs($admin)->get("/sites/{$site->id}/certificate")
            ->assertSee('Gültig bis')
            ->assertDontSee('Zertifikat anfordern');
    }

    public function test_a_refused_certificate_leaves_the_http_site_active(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->withoutTls()->create();
        $this->runner([]);

        $this->actingAs($admin)->post("/sites/{$site->id}/certificate");

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertFalse($site->tls);
        $this->assertFalse($site->wants_tls);
        $this->assertSame(TaskStatus::Failed, ServerTask::sole()->status);

        $this->actingAs($admin)->get("/sites/{$site->id}/certificate")
            ->assertSee('Die letzte Anforderung ist fehlgeschlagen')
            ->assertSee('Zertifikat anfordern');
    }

    public function test_certificates_cannot_be_requested_twice_or_for_https_sites(): void
    {
        $runner = $this->runner([]);
        $admin = $this->admin();

        $https = Site::factory()->create();
        $this->actingAs($admin)->post("/sites/{$https->id}/certificate")->assertSessionHas('error');

        $failed = Site::factory()->failed()->withoutTls()->create();
        $this->actingAs($admin)->post("/sites/{$failed->id}/certificate")->assertSessionHas('error');

        $this->assertSame([], $runner->calls);
    }

    public function test_certificate_states(): void
    {
        $this->assertSame('none', Site::factory()->withoutTls()->make()->certificateState());
        $this->assertSame('unknown', Site::factory()->make(['certificate_expires_at' => null])->certificateState());
        $this->assertSame('valid', Site::factory()->make(['certificate_expires_at' => now()->addDays(60)])->certificateState());
        $this->assertSame('expiring', Site::factory()->make(['certificate_expires_at' => now()->addDays(19)])->certificateState());
        $this->assertSame('expired', Site::factory()->make(['certificate_expires_at' => now()->subDay()])->certificateState());

        Carbon::setTestNow();
    }

    public function test_customers_cannot_see_updates_reboot_or_request_certificates(): void
    {
        Queue::fake();
        $server = Server::factory()->create(['name' => 'web1']);
        $site = Site::factory()->withoutTls()->create(['server_id' => $server->id]);
        $customer = User::factory()->for(Customer::factory())->create();
        $site->forceFill(['customer_id' => $customer->customer_id])->save();

        $this->actingAs($customer)->get("/servers/{$server->id}/updates")->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$server->id}/updates/check")->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$server->id}/reboot", ['confirm_name' => 'web1'])->assertForbidden();
        $this->actingAs($customer)->post("/sites/{$site->id}/certificate")->assertForbidden();
        $this->actingAs($customer)->get('/')->assertDontSee('Alle Server');

        $this->assertSame(0, ServerTask::count());
    }
}
