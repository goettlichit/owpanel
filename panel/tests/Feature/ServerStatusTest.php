<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Servers\LoadHistory;
use App\Servers\ScriptRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

class ServerStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     */
    private static function answer(array $override = []): string
    {
        $json = array_replace([
            'ok' => true,
            'script' => 'server-status',
            'hostname' => 'web1.example.de',
            'os' => 'Debian GNU/Linux 13 (trixie)',
            'kernel' => '6.12.48+deb13-amd64',
            'uptime' => 7200,
            'cpu_cores' => 2,
            'cpu' => 12.5,
            'load' => [0.52, 0.4, 0.31],
            'memory' => ['total' => 4_000_000_000, 'used' => 1_000_000_000],
            'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 40_000_000_000, 'used' => 30_000_000_000],
            'network' => ['rx' => 1_000_000, 'tx' => 500_000],
            'reboot_required' => false,
        ], $override);

        return json_encode($json)."\n";
    }

    private function fake(FakeScriptRunner $runner): FakeScriptRunner
    {
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_the_check_stores_system_data_and_a_measurement(): void
    {
        Carbon::setTestNow('2026-11-10 18:30:20');
        $runner = $this->fake(new FakeScriptRunner([self::answer()]));
        $server = Server::factory()->create(['name' => 'web1']);

        $this->artisan('owpanel:check-servers')->assertSuccessful();

        $this->assertSame(ServerScript::ServerStatus, $runner->calls[0]->script);
        $this->assertSame([], $runner->calls[0]->arguments);
        $this->assertSame("sudo -n '/opt/owpanel/bin/server-status'", $runner->calls[0]->commandLine());

        $server->refresh();
        $this->assertSame('web1.example.de', $server->hostname);
        $this->assertSame('web1.example.de', $server->display_name);
        $this->assertSame('Debian GNU/Linux 13 (trixie)', $server->os_name);
        $this->assertSame(2, $server->cpu_cores);
        $this->assertSame('2026-11-10 16:30:00', $server->booted_at->format('Y-m-d H:i:s'));
        $this->assertNull($server->check_error);
        $this->assertSame('online', $server->onlineState());

        $metric = ServerMetric::sole();
        $this->assertSame(12.5, $metric->cpu_percent);
        $this->assertSame(0.52, $metric->load1);
        $this->assertSame(25.0, $metric->memoryPercent());
        $this->assertSame(75.0, $metric->diskPercent());
        $this->assertSame(1_000_000, $metric->network_rx);
    }

    public function test_the_check_stores_known_services_only(): void
    {
        $this->fake(new FakeScriptRunner([self::answer(['services' => [
            ['name' => 'nginx', 'active' => true],
            ['name' => 'php8.4-fpm', 'active' => false],
            ['name' => 'evil;rm', 'active' => true],
            ['name' => 'mariadb', 'active' => 'yes'],
        ]])]));
        $server = Server::factory()->create(['name' => 'web1']);

        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->assertSame(['nginx' => true, 'php8.4-fpm' => false], $server->fresh()->services);

        // An older script without the list keeps what was known.
        $this->fake(new FakeScriptRunner([self::answer()]));
        $this->travel(2)->minutes();
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->assertSame(['nginx' => true, 'php8.4-fpm' => false], $server->fresh()->services);

        $this->actingAs($this->admin())->get(route('servers.show', $server))
            ->assertSee('php8.4-fpm')->assertSee(__('servers.system.service_stopped'));
    }

    public function test_the_check_stores_the_version_of_the_server_scripts(): void
    {
        config(['owpanel.version' => '1.2.0']);
        $this->fake(new FakeScriptRunner([self::answer(['scripts_version' => '1.2.0'])]));
        $server = Server::factory()->create(['name' => 'web1']);

        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->assertSame('1.2.0', $server->fresh()->scripts_version);
        $this->actingAs($this->admin())->get(route('servers.show', $server))
            ->assertSee(__('servers.system.scripts'))
            ->assertDontSee(__('servers.system.scripts_differ', ['version' => '1.2.0']));

        // A different version is shown with a hint (E-167).
        $this->fake(new FakeScriptRunner([self::answer(['scripts_version' => '1.1.0'])]));
        $this->travel(2)->minutes();
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->get(route('servers.show', $server))
            ->assertSee('1.1.0')
            ->assertSee(__('servers.system.scripts_differ', ['version' => '1.2.0']));

        // Older scripts and unexpected text leave no version.
        foreach ([self::answer(), self::answer(['scripts_version' => '1.2.0"<b>'])] as $answer) {
            $this->fake(new FakeScriptRunner([$answer]));
            $this->travel(2)->minutes();
            $this->artisan('owpanel:check-servers')->assertSuccessful();
            $this->assertNull($server->fresh()->scripts_version);
            $this->assertNull($server->fresh()->check_error);
        }
    }

    public function test_the_check_stores_the_private_address(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        // Only an RFC 1918 address; anything else leaves none (E-196).
        foreach (['10.0.0.3' => '10.0.0.3', '203.0.113.5' => null, '10.0.0.3"<b>' => null] as $value => $expected) {
            $this->fake(new FakeScriptRunner([self::answer(['private_ipv4' => $value])]));
            $this->travel(2)->minutes();
            $this->artisan('owpanel:check-servers')->assertSuccessful();
            $this->assertSame($expected, $server->fresh()->private_ipv4, $value);
        }
    }

    public function test_the_php_versions_of_a_server_are_kept_and_shown(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        $this->actingAs($this->admin());

        // Only versions the panel offers; 8.4 is left out.
        $this->fake(new FakeScriptRunner([self::answer(['php_versions' => ['8.4', '8.6', '8.5']])]));
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->assertSame(['8.5', '8.6'], $server->fresh()->php_versions);
        $this->get(route('servers.show', $server))->assertSee(__('servers.system.php'))->assertSee('8.5, 8.6');

        // An older server-status says nothing: what was known stays.
        $this->fake(new FakeScriptRunner([self::answer([])]));
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->assertSame(['8.5', '8.6'], $server->fresh()->php_versions);
    }

    public function test_the_server_page_compares_the_release_keys_with_the_panel(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'signers');
        file_put_contents($file, "# keys\nowpanel-release namespaces=\"owpanel-release\" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOe3rYOKp4b8QsHyUJ43Ef/SCOQbiGmFG5N/eCpW0hOp\n");
        config(['owpanel.release_signers' => $file]);
        $good = 'SHA256:X0bCyQfl6ua6+ZdUXZc1hgJw8yObPYPgOtE0vEwdKMA';
        $other = 'SHA256:'.str_repeat('A', 43);
        $server = Server::factory()->create(['name' => 'web1']);
        $this->actingAs($this->admin());

        $this->fake(new FakeScriptRunner([self::answer(['release_keys' => [$good]])]));
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->assertSame([$good], $server->fresh()->release_keys);
        $this->get(route('servers.show', $server))
            ->assertSee($good)
            ->assertSee(__('servers.system.release_key_ok'));

        $this->fake(new FakeScriptRunner([self::answer(['release_keys' => [$other]])]));
        $this->travel(2)->minutes();
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->get(route('servers.show', $server))
            ->assertSee($other)
            ->assertSee(__('servers.system.release_key_differs'))
            ->assertDontSee(__('servers.system.release_key_ok'));

        $this->fake(new FakeScriptRunner([self::answer(['release_keys' => []])]));
        $this->travel(2)->minutes();
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->get(route('servers.show', $server))
            ->assertSee(__('servers.system.release_key_none'))
            ->assertSee(__('servers.system.release_key_differs'));

        // Older scripts and odd answers store nothing.
        foreach ([self::answer(), self::answer(['release_keys' => ['SHA256:<b>']]), self::answer(['release_keys' => 'x'])] as $answer) {
            $this->fake(new FakeScriptRunner([$answer]));
            $this->travel(2)->minutes();
            $this->artisan('owpanel:check-servers')->assertSuccessful();
            $this->assertNull($server->fresh()->release_keys);
        }

        // Without a key in the panel's release there is nothing to compare.
        config(['owpanel.release_signers' => $file.'.missing']);
        $this->fake(new FakeScriptRunner([self::answer(['release_keys' => [$good]])]));
        $this->travel(2)->minutes();
        $this->artisan('owpanel:check-servers')->assertSuccessful();
        $this->get(route('servers.show', $server))
            ->assertSee($good)
            ->assertDontSee(__('servers.system.release_key_ok'))
            ->assertDontSee(__('servers.system.release_key_differs'));
        unlink($file);
    }

    public function test_an_unreachable_server_is_offline_and_keeps_its_last_data(): void
    {
        $server = Server::factory()->create();
        $this->fake(new FakeScriptRunner([self::answer()]));
        $this->artisan('owpanel:check-servers');

        $this->travel(2)->minutes();
        $this->fake(new FakeScriptRunner(connectionError: 'ssh_connection_failed'));
        $this->artisan('owpanel:check-servers');

        $server->refresh();
        $this->assertSame('offline', $server->onlineState());
        $this->assertSame('ssh_connection_failed', $server->check_error);
        $this->assertSame('web1.example.de', $server->hostname);
        $this->assertSame(1, ServerMetric::count());
        $this->assertSame('Keine SSH-Verbindung zum Server.', $server->checkErrorMessage());
    }

    public function test_a_server_without_recent_answer_is_offline(): void
    {
        $server = Server::factory()->create();
        $this->fake(new FakeScriptRunner([self::answer()]));
        $this->artisan('owpanel:check-servers');

        $this->assertSame('online', $server->fresh()->onlineState());
        $this->travel(Server::ONLINE_MINUTES + 1)->minutes();
        $this->assertSame('offline', $server->fresh()->onlineState());
    }

    public function test_a_server_that_was_never_checked_is_unknown(): void
    {
        $this->assertSame('unknown', Server::factory()->create()->onlineState());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidAnswers(): array
    {
        return [
            'cpu above 100' => [['cpu' => 101]],
            'negative memory' => [['memory' => ['total' => 1, 'used' => -1]]],
            'memory as text' => [['memory' => ['total' => '1', 'used' => 1]]],
            'two load values' => [['load' => [1, 2]]],
            'missing disk' => [['disk' => null]],
            'network as float' => [['network' => ['rx' => 1.5, 'tx' => 1]]],
            'interface with odd name' => [['network' => ['rx' => 1, 'tx' => 1, 'interfaces' => [['name' => 'eth0; rm', 'rx' => 1, 'tx' => 1, 'public' => true]]]]],
            'interface twice' => [['network' => ['rx' => 1, 'tx' => 1, 'interfaces' => [
                ['name' => 'eth0', 'rx' => 1, 'tx' => 1, 'public' => true],
                ['name' => 'eth0', 'rx' => 1, 'tx' => 1, 'public' => false],
            ]]]],
            'interface without public flag' => [['network' => ['rx' => 1, 'tx' => 1, 'interfaces' => [['name' => 'eth0', 'rx' => 1, 'tx' => 1]]]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidAnswers')]
    public function test_implausible_answers_are_rejected(array $override): void
    {
        $server = Server::factory()->create();
        $this->fake(new FakeScriptRunner([self::answer($override)]));

        $this->artisan('owpanel:check-servers');

        $this->assertSame('invalid_result', $server->fresh()->check_error);
        $this->assertSame(0, ServerMetric::count());
    }

    public function test_unexpected_text_from_the_server_is_not_stored(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        $this->fake(new FakeScriptRunner([self::answer([
            'hostname' => '<script>alert(1)</script>',
            'os' => 'Debian"; DROP',
            'kernel' => str_repeat('a', 65),
        ])]));

        $this->artisan('owpanel:check-servers');

        $server->refresh();
        $this->assertNull($server->hostname);
        $this->assertNull($server->os_name);
        $this->assertNull($server->kernel);
        $this->assertSame('web1', $server->display_name);
        $this->assertSame('online', $server->onlineState());
    }

    public function test_a_script_error_is_kept_as_reason(): void
    {
        $server = Server::factory()->create();
        $this->fake(new FakeScriptRunner(['{"ok":false,"script":"server-status","error":"status_unreadable"}'."\n"], exitCode: 1));

        $this->artisan('owpanel:check-servers');

        $this->assertSame('status_unreadable', $server->fresh()->check_error);
    }

    public function test_the_scheduler_checks_every_minute_and_prunes_old_measurements(): void
    {
        $events = collect(app(Schedule::class)->events());

        $check = $events->first(fn ($event) => str_contains($event->command, 'owpanel:check-servers'));
        $this->assertNotNull($check);
        $this->assertSame('* * * * *', $check->expression);
        $this->assertTrue($check->withoutOverlapping);

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command, 'model:prune')
            && str_contains($event->command, 'ServerMetric')));
    }

    public function test_measurements_older_than_seven_days_are_pruned(): void
    {
        $server = Server::factory()->create();
        foreach ([8, 6] as $days) {
            $this->metric($server, now()->subDays($days));
        }

        $this->artisan('model:prune', ['--model' => [ServerMetric::class]]);

        $this->assertSame(1, ServerMetric::count());
        $this->assertTrue(ServerMetric::sole()->recorded_at->greaterThan(now()->subDays(7)));
    }

    private function metric(Server $server, Carbon $at, array $values = []): ServerMetric
    {
        $metric = new ServerMetric;
        $metric->forceFill(array_replace([
            'server_id' => $server->id,
            'recorded_at' => $at,
            'cpu_percent' => 10,
            'load1' => 0.5, 'load5' => 0.4, 'load15' => 0.3,
            'memory_total' => 1000, 'memory_used' => 500,
            'swap_total' => 0, 'swap_used' => 0,
            'disk_total' => 1000, 'disk_used' => 250,
            'network_rx' => 0, 'network_tx' => 0,
        ], $values))->save();

        return $metric;
    }

    public function test_load_history_averages_buckets_computes_rates_and_leaves_gaps(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:30');
        $server = Server::factory()->create();
        // 11:57 and 11:58 measured, 11:59 missing, 12:00 measured.
        $this->metric($server, Carbon::parse('2026-11-10 11:57:05'), ['cpu_percent' => 10, 'network_rx' => 0, 'network_tx' => 0]);
        $this->metric($server, Carbon::parse('2026-11-10 11:58:05'), ['cpu_percent' => 30, 'network_rx' => 6000, 'network_tx' => 600]);
        $this->metric($server, Carbon::parse('2026-11-10 12:00:05'), ['cpu_percent' => 50, 'network_rx' => 100, 'network_tx' => 1800]);

        $history = LoadHistory::for($server, '1h');

        $this->assertCount(60, $history['times']);
        $this->assertSame(Carbon::parse('2026-11-10 12:00:00')->getTimestamp(), end($history['times']));
        $this->assertSame([10.0, 30.0, null, 50.0], array_slice($history['cpu'], -4));
        $this->assertSame([50.0, 50.0, null, 50.0], array_slice($history['memory'], -4));
        // 6000 bytes in 60 s; after the gap the counter fell (reboot): no rate.
        $this->assertSame([null, 100.0, null, null], array_slice($history['rx'], -4));
        $this->assertSame([null, 10.0, null, 10.0], array_slice($history['tx'], -4));
    }

    public function test_the_check_stores_counters_per_network_card(): void
    {
        $this->fake(new FakeScriptRunner([self::answer(['network' => ['rx' => 1500, 'tx' => 2700, 'interfaces' => [
            ['name' => 'enp7s0', 'rx' => 500, 'tx' => 700, 'public' => false],
            ['name' => 'eth0', 'rx' => 1000, 'tx' => 2000, 'public' => true],
        ]]])]));
        Server::factory()->create();

        $this->artisan('owpanel:check-servers');

        $metric = ServerMetric::sole();
        $this->assertSame(['eth0', 'enp7s0'], array_keys($metric->interfaces()));
        $this->assertSame(['rx' => 500, 'tx' => 700, 'public' => false], $metric->interfaces()['enp7s0']);
    }

    public function test_an_answer_of_an_older_script_without_cards_is_accepted(): void
    {
        $this->fake(new FakeScriptRunner([self::answer()]));
        Server::factory()->create();

        $this->artisan('owpanel:check-servers');

        $this->assertNull(ServerMetric::sole()->network_interfaces);
    }

    public function test_load_history_computes_rates_per_network_card(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:30');
        $server = Server::factory()->create();
        $cards = fn (int $public, int $private) => ['network_interfaces' => [
            'eth0' => ['rx' => $public, 'tx' => 0, 'public' => true],
            'enp7s0' => ['rx' => $private, 'tx' => 0, 'public' => false],
        ]];
        $this->metric($server, Carbon::parse('2026-11-10 11:58:05'), $cards(0, 0));
        $this->metric($server, Carbon::parse('2026-11-10 11:59:05'), $cards(600, 60_000));
        // A card that appears later has no rate in its first minute.
        $this->metric($server, Carbon::parse('2026-11-10 12:00:05'), ['network_interfaces' => [
            'eth0' => ['rx' => 1200, 'tx' => 0, 'public' => true],
            'enp7s0' => ['rx' => 120_000, 'tx' => 0, 'public' => false],
            'enp8s0' => ['rx' => 5, 'tx' => 0, 'public' => false],
        ]]);

        $history = LoadHistory::for($server, '1h');

        $this->assertSame(['eth0', 'enp7s0', 'enp8s0'], array_column($history['interfaces'], 'name'));
        $this->assertTrue($history['interfaces'][0]['public']);
        $this->assertSame([null, 10.0, 10.0], array_slice($history['interfaces'][0]['rx'], -3));
        $this->assertSame([null, 1000.0, 1000.0], array_slice($history['interfaces'][1]['rx'], -3));
        $this->assertSame([null, null, null], array_slice($history['interfaces'][2]['rx'], -3));
    }

    public function test_the_load_page_shows_one_chart_per_network_card(): void
    {
        $this->fake(new FakeScriptRunner([self::answer(['network' => ['rx' => 1500, 'tx' => 2700, 'interfaces' => [
            ['name' => 'eth0', 'rx' => 1000, 'tx' => 2000, 'public' => true],
            ['name' => 'enp7s0', 'rx' => 500, 'tx' => 700, 'public' => false],
        ]]])]));
        $server = Server::factory()->create();
        $this->artisan('owpanel:check-servers');

        $this->actingAs($this->admin())->get("/servers/{$server->id}/load")
            ->assertOk()
            ->assertSeeInOrder(['Netzwerk öffentlich', 'eth0', 'Netzwerk privat', 'enp7s0']);
    }

    public function test_the_load_page_shows_one_network_chart_for_a_single_card(): void
    {
        $this->fake(new FakeScriptRunner([self::answer(['network' => ['rx' => 1000, 'tx' => 2000, 'interfaces' => [
            ['name' => 'eth0', 'rx' => 1000, 'tx' => 2000, 'public' => true],
        ]]])]));
        $server = Server::factory()->create();
        $this->artisan('owpanel:check-servers');

        $this->actingAs($this->admin())->get("/servers/{$server->id}/load")
            ->assertOk()
            ->assertSee('Netzwerk')
            ->assertDontSee('Netzwerk öffentlich');
    }

    public function test_load_history_ranges_have_a_sensible_number_of_points(): void
    {
        $server = Server::factory()->create();

        $this->assertCount(288, LoadHistory::for($server, '24h')['times']);
        $this->assertCount(336, LoadHistory::for($server, '7d')['times']);
        $this->assertCount(288, LoadHistory::for($server, 'bogus')['times']);
    }

    public function test_the_server_list_shows_full_name_and_state(): void
    {
        $this->fake(new FakeScriptRunner([self::answer()]));
        Server::factory()->create(['name' => 'web1']);
        $this->artisan('owpanel:check-servers');
        Server::factory()->create(['name' => 'web2', 'checked_at' => now(), 'check_error' => 'ssh_connection_failed']);

        $this->actingAs($this->admin())->get('/servers')
            ->assertOk()
            ->assertSee('web1.example.de')
            ->assertSee('status-online', false)
            ->assertSee('status-offline', false)
            ->assertSee('Keine SSH-Verbindung zum Server.', false)
            ->assertSee('Zuletzt erreicht')
            ->assertDontSee('role="progressbar"', false);
    }

    public function test_the_server_page_shows_system_data_and_a_reboot_hint(): void
    {
        $this->fake(new FakeScriptRunner([self::answer(['reboot_required' => true])]));
        $server = Server::factory()->create(['name' => 'web1']);
        $this->artisan('owpanel:check-servers');

        $this->actingAs($this->admin())->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSee('web1.example.de')
            ->assertSee('Debian GNU/Linux 13 (trixie)')
            ->assertSee('6.12.48+deb13-amd64')
            ->assertSee('Der Server muss neu gestartet werden')
            ->assertSee('Auslastung ansehen')
            ->assertDontSee('role="progressbar"', false);
    }

    public function test_the_load_page_shows_current_values_and_charts(): void
    {
        $this->fake(new FakeScriptRunner([self::answer()]));
        $server = Server::factory()->create();
        $this->artisan('owpanel:check-servers');
        $admin = $this->admin();

        $this->actingAs($admin)->get("/servers/{$server->id}/load")
            ->assertOk()
            ->assertSeeLivewire('server-load')
            ->assertSee('Arbeitsspeicher')
            ->assertSee('953,7 MB / 3,7 GB')
            ->assertSee('Festplatte (/)')
            ->assertSee('aria-valuenow="75"', false)
            ->assertSee('0,52')
            ->assertSee('viewBox="0 0 1000 100"', false)
            ->assertSee('Empfangen');

        Livewire::actingAs($admin)->test('server-load', ['server' => $server])
            ->call('$set', 'range', '7d')
            ->assertSet('range', '7d')
            ->set('range', 'bogus')
            ->assertSet('range', '24h');
    }

    public function test_the_load_page_explains_missing_measurements(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->get("/servers/{$server->id}/load")
            ->assertOk()
            ->assertSee('Noch keine Messwerte.')
            ->assertSee('owpanel-scheduler.timer');
    }

    public function test_customers_cannot_see_server_state_or_load(): void
    {
        $server = Server::factory()->create();
        $customer = User::factory()->for(Customer::factory())->create();

        $this->get("/servers/{$server->id}/load")->assertRedirect('/login');
        $this->actingAs($customer)->get("/servers/{$server->id}/load")->assertForbidden();

        Livewire::actingAs($customer)->test('server-load', ['server' => $server])->assertForbidden();
    }
}
