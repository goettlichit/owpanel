<?php

namespace Tests\Feature;

use App\Hetzner\HetznerCloud;
use App\Hetzner\SnapshotRotation;
use App\Jobs\CreateServerSnapshot;
use App\Jobs\FollowServerSnapshot;
use App\Livewire\SnapshotRuns;
use App\Models\Alert;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Notify\NotificationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hetzner snapshots of servers (E-095).
 */
class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ0123';

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function token(): void
    {
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(self::TOKEN));
    }

    /**
     * @param  list<array<string, mixed>>  $images
     */
    private function fakeHetzner(array $images = [], string $actionStatus = 'success'): void
    {
        Http::fake([
            'api.hetzner.cloud/v1/servers?*' => Http::response(['servers' => [
                ['id' => 111, 'name' => 'other', 'public_net' => ['ipv4' => ['ip' => '198.51.100.1'], 'ipv6' => ['ip' => '2001:db8:1::/64']]],
                ['id' => 4242, 'name' => 'web1', 'public_net' => ['ipv4' => ['ip' => '203.0.113.10'], 'ipv6' => ['ip' => '2001:db8:2::/64']]],
            ], 'meta' => ['pagination' => ['next_page' => null]]]),
            'api.hetzner.cloud/v1/servers/4242/actions/create_image' => Http::response([
                'image' => ['id' => 9001], 'action' => ['id' => 777, 'status' => 'running'],
            ], 201),
            'api.hetzner.cloud/v1/actions/777' => Http::response(['action' => ['id' => 777, 'status' => $actionStatus, 'error' => $actionStatus === 'error' ? ['code' => 'snapshot_failed'] : null]]),
            'api.hetzner.cloud/v1/images?*' => Http::response(['images' => $images, 'meta' => ['pagination' => ['next_page' => null]]]),
            'api.hetzner.cloud/v1/images/*' => Http::response(null, 204),
        ]);
    }

    private function server(): Server
    {
        return Server::factory()->create(['name' => 'web1', 'public_ipv4' => '203.0.113.10', 'snapshot_enabled' => true]);
    }

    public function test_the_token_is_checked_and_stored_encrypted(): void
    {
        Http::fake(['api.hetzner.cloud/v1/servers?*' => Http::response(['servers' => []])]);
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/hetzner', ['hetzner_token' => self::TOKEN])->assertRedirect('/settings/services');

        $this->assertTrue(HetznerCloud::configured());
        $this->assertNotSame(self::TOKEN, Setting::get(Setting::HETZNER_TOKEN));
        $this->assertSame(self::TOKEN, HetznerCloud::storedToken());
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.hetzner_token_changed']);
    }

    public function test_a_rejected_token_is_not_stored(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401)]);

        $this->actingAs($this->admin())->put('/settings/hetzner', ['hetzner_token' => self::TOKEN])
            ->assertSessionHasErrors(['hetzner_token' => 'Hetzner lehnt den API-Token ab.']);
        $this->actingAs($this->admin())->put('/settings/hetzner', ['hetzner_token' => 'bad token; rm'])
            ->assertSessionHasErrors('hetzner_token');
        $this->assertFalse(HetznerCloud::configured());
    }

    public function test_a_snapshot_links_the_server_creates_the_image_and_rotates(): void
    {
        $this->token();
        $server = $this->server();
        $label = strtolower($server->id);
        $images = [];
        // Panel snapshots of the last 10 days, one a day, newest first.
        for ($i = 0; $i < 10; $i++) {
            $images[] = ['id' => 500 + $i, 'description' => 'x', 'created' => now()->subDays($i)->toIso8601String(), 'status' => 'available',
                'image_size' => 2.5, 'created_from' => ['id' => 4242], 'labels' => ['owpanel' => 'snapshot', 'owpanel-server' => $label]];
        }
        // An older snapshot by hand: never rotated away.
        $images[] = ['id' => 450, 'description' => 'vor dem Update', 'created' => now()->subDays(20)->toIso8601String(), 'status' => 'available',
            'image_size' => 2.5, 'created_from' => ['id' => 4242], 'labels' => ['owpanel' => 'manual', 'owpanel-server' => $label]];
        $this->fakeHetzner($images);
        $server->forceFill(['snapshot_keep_daily' => 7, 'snapshot_keep_weekly' => 0])->save();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/snapshots")->assertRedirect("/servers/{$server->id}/snapshots");

        $run = ServerSnapshot::sole();
        $this->assertSame(ServerSnapshot::DONE, $run->status);
        $this->assertSame(9001, $run->hetzner_image_id);
        $this->assertSame(3, $run->deleted_count);
        $this->assertSame(4242, $server->fresh()->hetzner_server_id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/4242/actions/create_image')
            && $request['type'] === 'snapshot'
            && $request['labels'] === ['owpanel' => 'manual', 'owpanel-server' => $label]
            && str_starts_with($request['description'], 'OWPanel web1 '));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'label_selector=owpanel%3Dsnapshot%2Cowpanel-server%3D'.$label));
        foreach ([507, 508, 509] as $id) {
            Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), "/images/{$id}"));
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/images/506'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/images/450'));
    }

    public function test_scheduled_snapshots_carry_the_schedule_label(): void
    {
        $this->token();
        $this->fakeHetzner();
        $server = $this->server();
        $run = $server->snapshots()->create(['trigger' => 'schedule', 'status' => ServerSnapshot::PENDING]);

        (new CreateServerSnapshot($run->id))->handle();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/actions/create_image')
            && $request['labels']['owpanel'] === 'snapshot');
    }

    public function test_a_server_that_is_not_in_the_project_fails_clearly(): void
    {
        $this->token();
        $this->fakeHetzner();
        $server = Server::factory()->create(['public_ipv4' => '192.0.2.99', 'snapshot_enabled' => true]);

        $this->actingAs($this->admin())->post("/servers/{$server->id}/snapshots");

        $this->assertSame('server_not_found', ServerSnapshot::sole()->error);
        $this->assertSame(ServerSnapshot::FAILED, ServerSnapshot::sole()->status);
    }

    public function test_a_running_snapshot_is_followed_and_a_failed_one_recorded(): void
    {
        $this->token();
        $server = $this->server();
        $server->forceFill(['hetzner_server_id' => 4242])->save();
        Queue::fake();
        $run = $server->snapshots()->create(['trigger' => 'manual', 'status' => ServerSnapshot::CREATING]);
        $run->forceFill(['hetzner_action_id' => 777])->save();

        $this->fakeHetzner([], 'running');
        (new FollowServerSnapshot($run->id))->handle();
        Queue::assertPushed(FollowServerSnapshot::class);
        $this->assertSame(ServerSnapshot::CREATING, $run->fresh()->status);

        Http::swap(new Factory);
        $this->fakeHetzner([], 'error');
        (new FollowServerSnapshot($run->id))->handle();
        $this->assertSame(ServerSnapshot::FAILED, $run->fresh()->status);
        $this->assertSame('snapshot_failed', $run->fresh()->error);
    }

    public function test_the_schedule_starts_one_snapshot_a_day_after_its_time(): void
    {
        $this->token();
        Queue::fake();
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $server = $this->server();
        $server->forceFill(['snapshot_enabled' => true, 'snapshot_time' => '03:30'])->save();
        Server::factory()->create(['snapshot_enabled' => false]);

        $this->travelTo(now('Europe/Berlin')->setTime(3, 0));
        $this->artisan('owpanel:snapshots')->assertSuccessful();
        $this->assertSame(0, ServerSnapshot::count());

        $this->travelTo(now('Europe/Berlin')->setTime(3, 31));
        $this->artisan('owpanel:snapshots')->assertSuccessful();
        $this->artisan('owpanel:snapshots')->assertSuccessful();
        $this->assertSame(1, ServerSnapshot::count());
        $this->assertSame('schedule', ServerSnapshot::sole()->trigger);
    }

    public function test_the_schedule_is_saved(): void
    {
        $server = $this->server();

        $this->actingAs($this->admin())->put("/servers/{$server->id}/snapshots/schedule", [
            'snapshot_enabled' => '1', 'snapshot_time' => '02:15', 'snapshot_keep_daily' => 5, 'snapshot_keep_weekly' => 2,
        ])->assertRedirect();
        $this->actingAs($this->admin())->put("/servers/{$server->id}/snapshots/schedule", [
            'snapshot_time' => '25:00', 'snapshot_keep_daily' => 0, 'snapshot_keep_weekly' => 2,
        ])->assertSessionHasErrors(['snapshot_time', 'snapshot_keep_daily']);

        $server->refresh();
        $this->assertTrue($server->snapshot_enabled);
        $this->assertSame('02:15', $server->snapshot_time);
        $this->assertSame(5, $server->snapshot_keep_daily);
    }

    public function test_the_page_lists_panel_and_own_snapshots_and_deletes_only_panel_ones(): void
    {
        $this->token();
        $server = $this->server();
        $server->forceFill(['hetzner_server_id' => 4242])->save();
        $label = strtolower($server->id);
        $this->fakeHetzner([
            ['id' => 601, 'description' => 'OWPanel web1 2026-11-11 03:30', 'created' => '2026-11-11T02:30:00+00:00', 'status' => 'available',
                'image_size' => 3.2, 'created_from' => ['id' => 4242], 'labels' => ['owpanel' => 'snapshot', 'owpanel-server' => $label]],
            ['id' => 604, 'description' => 'von Hand im Panel', 'created' => '2026-11-11T01:00:00+00:00', 'status' => 'available',
                'image_size' => 3.1, 'created_from' => ['id' => 4242], 'labels' => ['owpanel' => 'manual', 'owpanel-server' => $label]],
            ['id' => 602, 'description' => 'vor dem Update', 'created' => '2026-11-10T10:00:00+00:00', 'status' => 'available',
                'image_size' => 3.0, 'created_from' => ['id' => 4242], 'labels' => []],
            ['id' => 603, 'description' => 'anderer Server', 'created' => '2026-11-10T10:00:00+00:00', 'status' => 'available',
                'image_size' => 1.0, 'created_from' => ['id' => 111], 'labels' => []],
        ]);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/snapshots")
            ->assertOk()->assertSee('OWPanel web1 2026-11-11 03:30')->assertSee('vor dem Update')->assertDontSee('anderer Server')
            ->assertSee('3,2 GB')->assertSee('von Hand')->assertSee('Aufbewahrungsregeln');

        $this->actingAs($this->admin())->delete("/servers/{$server->id}/snapshots/601")->assertRedirect();
        $this->actingAs($this->admin())->delete("/servers/{$server->id}/snapshots/604")->assertRedirect();
        $this->actingAs($this->admin())->delete("/servers/{$server->id}/snapshots/602")->assertNotFound();
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/images/601'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/images/602'));
    }

    public function test_rotation_keeps_daily_and_weekly_and_always_the_newest(): void
    {
        $snapshots = [];
        $start = now()->setTimezone('Europe/Berlin')->setDate(2026, 11, 11)->setTime(3, 30);
        for ($i = 0; $i < 40; $i++) {
            $snapshots[] = ['id' => $i, 'created' => $start->copy()->subDays($i)->toIso8601String()];
        }

        $delete = SnapshotRotation::toDelete($snapshots, 7, 4, 'Europe/Berlin');
        $kept = array_values(array_diff(range(0, 39), $delete));

        // 7 days (0-6) plus the newest of each of the last 4 ISO weeks: the
        // 11th is a Wednesday, so weeks start at 0, 3 (Sunday), 10 and 17.
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 10, 17], $kept);
        $this->assertSame([0], array_values(array_diff(range(0, 0), SnapshotRotation::toDelete([$snapshots[0]], 0, 0, 'UTC'))));
    }

    public function test_the_runs_poll_while_running_and_reload_the_page_when_done(): void
    {
        $admin = $this->admin();
        $server = $this->server();
        $run = $server->snapshots()->create(['trigger' => 'manual', 'status' => ServerSnapshot::CREATING]);

        $component = Livewire::actingAs($admin)
            ->test(SnapshotRuns::class, ['server' => $server])
            ->assertSeeHtml('wire:poll.5s')
            ->assertSeeHtml('status-running')->assertSee('von Hand');

        $run->forceFill(['status' => ServerSnapshot::DONE, 'finished_at' => now()])->save();
        $component->call('$refresh')
            ->assertSee('Erfolgreich')->assertSeeHtml('bg-green-lt')
            ->assertDontSeeHtml('wire:poll');
        $this->assertStringContainsString('window.location.reload()', json_encode($component->effects));
    }

    public function test_customers_cannot_touch_snapshots(): void
    {
        $this->token();
        Http::fake();
        $user = User::factory()->create();
        $server = $this->server();

        $this->actingAs($user)->get("/servers/{$server->id}/snapshots")->assertForbidden();
        $this->actingAs($user)->post("/servers/{$server->id}/snapshots")->assertForbidden();
        $this->actingAs($user)->put("/servers/{$server->id}/snapshots/schedule", ['snapshot_time' => '01:00', 'snapshot_keep_daily' => 1, 'snapshot_keep_weekly' => 0])->assertForbidden();
        $this->actingAs($user)->delete("/servers/{$server->id}/snapshots/601")->assertForbidden();
        $this->actingAs($user)->put('/settings/hetzner', ['hetzner_token' => self::TOKEN])->assertForbidden();

        $this->assertSame(0, ServerSnapshot::count());
        Http::assertNothingSent();
    }

    public function test_snapshots_by_hand_need_the_schedule_on_and_lists_show_five_per_page(): void
    {
        $this->token();
        $server = $this->server();
        $label = strtolower($server->id);
        $images = [];
        for ($i = 0; $i < 7; $i++) {
            $images[] = ['id' => 600 + $i, 'description' => 'Bild '.$i, 'created' => now()->subDays($i)->toIso8601String(), 'status' => 'available',
                'image_size' => 2.5, 'created_from' => ['id' => 4242], 'labels' => ['owpanel' => 'snapshot', 'owpanel-server' => $label]];
        }
        shuffle($images);
        $this->fakeHetzner($images);
        $server->forceFill(['hetzner_server_id' => 4242])->save();
        for ($i = 0; $i < 7; $i++) {
            $server->snapshots()->create(['trigger' => 'schedule', 'status' => ServerSnapshot::DONE])->forceFill(['created_at' => now()->subDays($i)])->save();
        }
        $admin = $this->admin();

        // Newest five first, the rest on page two.
        $this->actingAs($admin)->get("/servers/{$server->id}/snapshots")->assertOk()
            ->assertSee('action="'.url("/servers/{$server->id}/snapshots").'"', false)->assertSeeInOrder(['Bild 0', 'Bild 4'])->assertDontSee('Bild 5')
            ->assertSee('images=2', false)
            ->assertSeeInOrder(['Zeitplan und Aufbewahrung', 'Aufbewahrungsregeln', 'Letzte Läufe', 'Snapshots bei Hetzner']);
        $this->actingAs($admin)->get("/servers/{$server->id}/snapshots?images=2")->assertOk()->assertSee('Bild 6')->assertDontSee('Bild 0');
        $runs = Livewire::actingAs($admin)->test(SnapshotRuns::class, ['server' => $server]);
        $this->assertCount(5, $runs->viewData('runs'));
        $this->assertSame(7, $runs->viewData('runs')->total());

        // Without the schedule there is no button, and the panel refuses.
        $server->forceFill(['snapshot_enabled' => false])->save();
        $this->actingAs($admin)->get("/servers/{$server->id}/snapshots")->assertOk()->assertDontSee('action="'.url("/servers/{$server->id}/snapshots").'"', false);
        $this->actingAs($admin)->post("/servers/{$server->id}/snapshots")->assertSessionHas('error');
        $this->assertSame(7, ServerSnapshot::count());
    }

    public function test_system_backup_shows_the_state_of_each_card_on_its_line(): void
    {
        $admin = $this->admin();
        $server = $this->server();
        $server->forceFill(['snapshot_enabled' => false])->save();
        $page = fn () => $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')->assertOk();
        $lines = fn ($response) => array_map(fn ($m) => $m, preg_match_all('/card-status-top bg-(\w+)/', $response->getContent(), $m) ? $m[1] : []);

        // Order: Hetzner API, emergency data, snapshots, backups. Nothing set up: blue.
        $response = $page()->assertSeeInOrder(['Notfalldaten', 'Snapshots der Server', 'Backups der Server']);
        $this->assertSame(['orange', 'blue', 'blue'], $lines($response));

        // Token, snapshots on, backups connected: green.
        $this->token();
        $server->forceFill(['snapshot_enabled' => true, 'backup_box_id' => 7, 'backup_host' => 'u1.your-storagebox.de', 'backup_ready' => true])->save();
        $this->assertSame(['orange', 'green', 'green'], $lines($page()));

        // Still connecting: orange; a failed snapshot or backup: red, also at the server.
        $server->forceFill(['backup_ready' => false])->save();
        $this->assertSame('orange', $lines($page())[2]);
        Alert::create(['key' => "snapshot_failed:{$server->id}", 'event' => NotificationEvent::SnapshotFailed, 'opened_at' => now()]);
        Alert::create(['key' => "backup_failed:{$server->id}", 'event' => NotificationEvent::BackupFailed, 'opened_at' => now()]);
        $response = $page();
        $this->assertSame(['orange', 'red', 'red'], $lines($response));
        $response->assertSee('fehlgeschlagen');
    }
}
