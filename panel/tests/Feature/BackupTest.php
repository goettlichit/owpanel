<?php

namespace Tests\Feature;

use App\Backups\BackupTarget;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Hetzner\HetznerCloud;
use App\Livewire\PanelBackupFiles;
use App\Livewire\PanelBackupList;
use App\Livewire\ServerBackupState;
use App\Livewire\SiteBackupBrowser;
use App\Livewire\SiteBackupList;
use App\Models\Database;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * restic backups of websites to a Hetzner Storage Box (E-096), with one
 * subaccount per server created through the Hetzner API (E-097), set per
 * server under Server -> Backups (E-100).
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEC0HQx6+YSYOer5/uj2bzYwPcFtt5dQrAfQQ3WetOmm';

    /**
     * @param  array<string, string>  $outputs  script => result line
     */
    private function runner(array $outputs = []): ScriptRunner
    {
        $runner = new class($outputs) implements ScriptRunner
        {
            /** @var list<ScriptCall> */
            public array $calls = [];

            /** @param array<string, string> $outputs */
            public function __construct(public array $outputs) {}

            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $this->calls[] = $call;
                $script = $call->script->value;
                $output = ($this->outputs[$script] ?? '{"ok":true,"script":"'.$script.'"}')."\n";
                $onOutput($output);

                return ScriptResult::fromOutput($script, $output, str_contains($output, '"ok":false') ? 1 : 0, false);
            }
        };
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * Gives every server, existing and created later in the test, the box
     * with ID 7 as backup target, as if chosen under Server -> Backups.
     */
    private function target(): void
    {
        $values = ['backup_box_id' => 7, 'backup_box_name' => 'backup', 'backup_host' => 'u123456.your-storagebox.de'];
        Server::query()->update($values);
        Server::creating(fn (Server $server) => $server->forceFill($values + $server->getAttributes()));
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'backup_box' => 7, 'backup_path' => 'owpanel', 'backup_time' => '02:30',
            'backup_keep_daily' => 7, 'backup_keep_weekly' => 4, 'backup_keep_monthly' => 6,
        ], $overrides);
    }

    /**
     * A Hetzner API with one Storage Box (ID 7) that keeps the subaccounts
     * it creates. Returns the recorded requests with their bodies.
     *
     * @return \ArrayObject<int, array{method: string, path: string, body: array<string, mixed>}>
     */
    private function hetzner(): \ArrayObject
    {
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        HetznerCloud::$pollMicroseconds = 0;
        $log = new \ArrayObject;
        $subs = new \ArrayObject;
        Http::fake(function (Request $request) use ($log, $subs) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $log[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->data()];
            $this->assertStringStartsWith('https://api.hetzner.com/v1/', $request->url());

            if ($request->method() === 'GET' && $path === '/v1/storage_boxes') {
                return Http::response(['storage_boxes' => [
                    ['id' => 7, 'name' => 'backup', 'username' => 'u123456', 'server' => 'u123456.your-storagebox.de', 'location' => ['name' => 'fsn1'], 'status' => 'active'],
                ], 'meta' => ['pagination' => ['next_page' => null]]]);
            }
            if ($request->method() === 'POST' && $path === '/v1/storage_boxes/7/subaccounts') {
                $id = count($subs) + 1;
                $subs[$id] = ['id' => $id, 'username' => "u123456-sub{$id}", 'server' => "u123456-sub{$id}.your-storagebox.de", 'home_directory' => $request->data()['home_directory'], 'storage_box' => 7];

                return Http::response(['subaccount' => ['id' => $id, 'storage_box' => 7], 'action' => ['id' => 100 + $id, 'status' => 'running']], 201);
            }
            if (preg_match('#^/v1/storage_boxes/7/subaccounts/(\d+)$#', $path, $m) && $request->method() === 'GET') {
                return isset($subs[(int) $m[1]]) ? Http::response(['subaccount' => $subs[(int) $m[1]]]) : Http::response(['error' => ['code' => 'not_found']], 404);
            }
            if (preg_match('#^/v1/storage_boxes/7/subaccounts/(\d+)/actions/(reset_subaccount_password|update_access_settings)$#', $path)) {
                return Http::response(['action' => ['id' => 200, 'status' => 'running']], 201);
            }
            if ($path === '/v1/storage_boxes/7/actions/update_access_settings') {
                return Http::response(['action' => ['id' => 300, 'status' => 'running']], 201);
            }
            if (preg_match('#^/v1/storage_boxes/actions/\d+$#', $path)) {
                return Http::response(['action' => ['status' => 'success']]);
            }

            return Http::response(['error' => ['code' => 'not_found']], 404);
        });

        return $log;
    }

    public function test_saving_the_target_gives_the_server_its_own_subaccount(): void
    {
        $api = $this->hetzner();
        $runner = $this->runner(['backup-setup' => '{"ok":true,"script":"backup-setup","key_installed":true,"initialized":true,"public_key":"'.self::KEY.'"}']);
        $one = Server::factory()->create(['name' => 'a']);
        $two = Server::factory()->create(['name' => 'b']);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form(['backup_time' => '01:15', 'backup_keep_daily' => 10]))
            ->assertRedirect("/servers/{$one->id}/backups")->assertSessionHas('status');
        $one->refresh();
        $this->assertSame([7, 'backup', 'u123456.your-storagebox.de', '01:15', 10], [$one->backup_box_id, $one->backup_box_name, $one->backup_host, $one->backup_time, $one->backup_keep_daily]);
        // Only this server; the other one keeps its own settings.
        $this->assertNull($two->fresh()->backup_box_id);
        $this->actingAs($admin)->put("/servers/{$two->id}/backups", $this->form());

        // Each server got a subaccount with only SSH, at home in its own directory.
        $created = array_values(array_filter($api->getArrayCopy(), fn ($r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/subaccounts')));
        $this->assertCount(2, $created);
        $this->assertSame('owpanel/'.strtolower($one->id), $created[0]['body']['home_directory']);
        $this->assertSame('owpanel/'.strtolower($two->id), $created[1]['body']['home_directory']);
        $this->assertTrue($created[0]['body']['access_settings']['ssh_enabled']);
        $this->assertFalse($created[0]['body']['access_settings']['samba_enabled']);
        $this->assertFalse($created[0]['body']['access_settings']['webdav_enabled']);
        $this->assertFalse($created[0]['body']['access_settings']['reachable_externally']);
        $password = $created[0]['body']['password'];
        $this->assertMatchesRegularExpression('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[-_+#]).{32}$/', $password);

        // The server installs its key with that password once.
        $this->assertCount(2, $runner->calls);
        $call = $runner->calls[0];
        $this->assertSame(ServerScript::BackupSetup, $call->script);
        $this->assertSame(['--host', 'u123456.your-storagebox.de', '--account', 'u123456-sub1', '--path', 'restic'], $call->arguments);
        $one->refresh();
        $this->assertSame("password\t{$password}\nrepo\t{$one->backup_repo_password}\n", $call->stdin);
        $this->assertSame(['--host', 'u123456.your-storagebox.de', '--account', 'u123456-sub2', '--path', 'restic'], $runner->calls[1]->arguments);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $one->backup_repo_password);
        $this->assertNotSame($one->backup_repo_password, $two->fresh()->backup_repo_password);
        $this->assertTrue($one->backup_ready);
        $this->assertSame(self::KEY, $one->backup_public_key);
        $this->assertSame([7, 1, 'u123456-sub1'], [$one->backup_box_id, $one->backup_subaccount_id, $one->backup_account]);

        // No password of the box or subaccount is kept anywhere.
        $this->assertDatabaseMissing('settings', ['value' => $password]);
        foreach (ServerTask::all() as $task) {
            $this->assertStringNotContainsString($password, json_encode($task->toArray()));
        }
        $this->assertStringNotContainsString($one->backup_repo_password, (string) $one->getRawOriginal('backup_repo_password'));

        // Saving again without changes connects nothing.
        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form());
        $this->assertCount(2, $runner->calls);

        // Connecting again keeps the subaccount and gives it a new password.
        $one->forceFill(['backup_ready' => false])->save();
        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form());
        $this->assertCount(3, $runner->calls);
        $reset = array_values(array_filter($api->getArrayCopy(), fn ($r) => str_ends_with($r['path'], '/subaccounts/1/actions/reset_subaccount_password')));
        $this->assertCount(1, $reset);
        $this->assertStringContainsString("password\t{$reset[0]['body']['password']}\n", $runner->calls[2]->stdin);
        $this->assertNotSame($password, $reset[0]['body']['password']);
        $this->assertCount(2, array_filter($api->getArrayCopy(), fn ($r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/subaccounts')));

        // Allowing access from outside connects this server again and
        // changes its subaccount only.
        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form(['backup_external' => '1']));
        $this->assertCount(4, $runner->calls);
        $access = array_values(array_filter($api->getArrayCopy(), fn ($r) => str_contains($r['path'], '/subaccounts/') && str_ends_with($r['path'], '/actions/update_access_settings')));
        $this->assertCount(2, $access);
        $this->assertStringContainsString('/subaccounts/1/', end($access)['path']);
        $this->assertTrue(end($access)['body']['reachable_externally']);
        $this->assertFalse(end($access)['body']['samba_enabled']);

        // The box itself is open from outside while one of its servers needs
        // it, and only that one setting is sent.
        $box = array_values(array_filter($api->getArrayCopy(), fn ($r) => $r['path'] === '/v1/storage_boxes/7/actions/update_access_settings'));
        $this->assertTrue(end($box)['body']['reachable_externally']);
        $this->assertSame(['reachable_externally'], array_keys(end($box)['body']));
        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form());
        $box = array_values(array_filter($api->getArrayCopy(), fn ($r) => $r['path'] === '/v1/storage_boxes/7/actions/update_access_settings'));
        $this->assertFalse(end($box)['body']['reachable_externally']);

        // Another box or directory means a new subaccount.
        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form(['backup_path' => 'panel']));
        $created = array_values(array_filter($api->getArrayCopy(), fn ($r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/subaccounts')));
        $this->assertCount(3, $created);
        $this->assertSame('panel/'.strtolower($one->id), $created[2]['body']['home_directory']);

        // "No backups" turns this server off and keeps the other.
        $this->actingAs($admin)->put("/servers/{$one->id}/backups", $this->form(['backup_box' => '']))->assertSessionHas('status');
        $this->assertFalse($one->fresh()->backupConfigured());
        $this->assertTrue($two->fresh()->backupActive());
    }

    public function test_a_failed_connection_leaves_the_server_not_ready(): void
    {
        $this->hetzner();
        $this->runner(['backup-setup' => '{"ok":false,"script":"backup-setup","error":"storage_box_login_failed"}']);
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->put("/servers/{$server->id}/backups", $this->form());

        $this->assertFalse($server->fresh()->backup_ready);
        $this->assertSame('storage_box_login_failed', ServerTask::sole()->error);
        Livewire::actingAs($this->admin())->test(ServerBackupState::class, ['server' => $server])
            ->assertSee('nicht verbunden')->assertSee('Aufgabe anzeigen')->assertDontSeeHtml('wire:poll');
    }

    public function test_an_api_error_fails_the_task_without_running_the_script(): void
    {
        $this->target();
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        Http::fake(['*' => Http::response(['error' => ['code' => 'forbidden', 'message' => 'token is read only']], 403)]);
        $runner = $this->runner();
        $server = Server::factory()->create();

        BackupTarget::setUp($server, null);

        $task = ServerTask::sole();
        $this->assertSame('hetzner_forbidden', $task->error);
        $this->assertStringContainsString('token is read only', (string) $task->output);
        $this->assertSame([], $runner->calls);
        $this->assertFalse($server->fresh()->backup_ready);
    }

    public function test_the_state_follows_a_running_connection(): void
    {
        $this->target();
        $server = Server::factory()->create();
        $task = ServerTask::forceCreate(['server_id' => $server->id, 'script' => ServerScript::BackupSetup, 'arguments' => [], 'status' => TaskStatus::Running]);

        $component = Livewire::actingAs($this->admin())->test(ServerBackupState::class, ['server' => $server])
            ->assertSeeHtml('wire:poll.3s')->assertSee('wird verbunden');

        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $server->forceFill(['backup_ready' => true])->save();
        $component->call('$refresh')->assertSee('verbunden')->assertDontSee('wird verbunden')->assertDontSeeHtml('wire:poll');

        Livewire::actingAs(User::factory()->create())->test(ServerBackupState::class, ['server' => $server])->assertForbidden();
    }

    public function test_invalid_targets_are_refused(): void
    {
        $this->hetzner();
        $runner = $this->runner();
        $server = Server::factory()->create();
        $admin = $this->admin();
        $url = "/servers/{$server->id}/backups";

        $this->actingAs($admin)->put($url, $this->form(['backup_box' => 'abc']))->assertSessionHasErrors('backup_box');
        $this->actingAs($admin)->put($url, $this->form(['backup_box' => 8]))->assertSessionHasErrors('backup_box');
        $this->actingAs($admin)->put($url, $this->form(['backup_path' => '../etc']))->assertSessionHasErrors('backup_path');
        $this->actingAs($admin)->put($url, $this->form(['backup_keep_daily' => 0]))->assertSessionHasErrors('backup_keep_daily');
        $this->actingAs($admin)->put($url, $this->form(['backup_time' => '25:00']))->assertSessionHasErrors('backup_time');

        $this->assertFalse($server->fresh()->backupConfigured());
        $this->assertSame([], $runner->calls);
    }

    public function test_the_site_page_lists_backups_after_loading_and_backs_up_by_hand(): void
    {
        $runner = $this->runner([
            'site-backups' => '{"ok":true,"script":"site-backups","snapshots":[{"id":"9811f032","time":"2026-11-12T00:08:38+01:00","size":2048,"added":512,"kind":"manual"},{"id":"38d18c78","time":"2026-11-11T02:30:00+01:00","size":null,"added":null,"kind":"schedule"},{"id":"77aa00ff","time":"2026-11-10T02:30:00+01:00","kind":"restore"},{"id":"bad id"}]}',
            'site-backup' => '{"ok":true,"script":"site-backup","snapshot":"abcd1234","kind":"manual","databases":1,"files":5,"bytes_added":100}',
        ]);
        $this->target();
        $site = Site::factory()->create(['system_user' => 'ow38217']);
        $site->server->forceFill(['backup_ready' => true])->save();
        $admin = $this->admin();

        // The page itself does not wait for the Storage Box.
        $this->actingAs($admin)->get("/sites/{$site->id}/backups")
            ->assertOk()->assertSee('Der Server verbindet sich mit der Storage Box')->assertDontSee('9811f032');
        $this->assertSame([], $runner->calls);

        Livewire::withoutLazyLoading()->actingAs($admin)->test(SiteBackupList::class, ['site' => $site])
            ->assertSee('9811f032')->assertSee('von Hand')->assertSee('automatisch')->assertSee('vor Wiederherstellung')
            ->assertDontSee('bad id')
            ->assertSeeHtml('href="'.url("/sites/{$site->id}/backups/9811f032").'"');
        $this->assertSame(['--user', 'ow38217'], $runner->calls[0]->arguments);

        $this->actingAs($admin)->post("/sites/{$site->id}/backups")->assertRedirect();
        $task = ServerTask::where('script', ServerScript::SiteBackup->value)->sole();
        $this->assertSame(['--user', 'ow38217', '--manual', '--keep-daily', '7', '--keep-weekly', '4', '--keep-monthly', '6'], $task->arguments);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
    }

    public function test_a_snapshot_shows_its_files_folder_by_folder_and_its_databases(): void
    {
        $runner = $this->runner([
            'site-backup-files' => '{"ok":true,"script":"site-backup-files","snapshot":"9811f032","files":{"entries":[{"name":"wp-content","type":"dir","size":null,"mtime":"2026-11-10T10:00:00"},{"name":"index.php","type":"file","size":1200,"mtime":"2026-11-11T13:22:43"},{"name":"../etc","type":"dir"},{"name":"a/b","type":"file"}],"more":false},"dumps":{"entries":[{"name":"ow38217_shop.sql","type":"file","size":2255},{"name":"ow38218_other.sql","type":"file","size":1}],"more":false}}',
        ]);
        $this->target();
        $site = Site::factory()->create(['system_user' => 'ow38217']);
        $site->server->forceFill(['backup_ready' => true])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/backups/9811f032")->assertOk()
            ->assertSee('Sicherung 9811f032')->assertSee('Mir ist klar');
        $this->actingAs($admin)->get("/sites/{$site->id}/backups/NOTANID")->assertNotFound();

        $component = Livewire::withoutLazyLoading()->actingAs($admin)
            ->test(SiteBackupBrowser::class, ['site' => $site, 'snapshot' => '9811f032'])
            ->assertSee('wp-content')->assertSee('index.php')->assertSee('ow38217_shop')
            ->assertDontSee('../etc')->assertDontSee('ow38218_other')
            // Not just "a/b": random IDs in the markup could contain it.
            ->assertDontSee('</svg>a/b', false)->assertDontSee('>a/b<', false);
        $this->assertSame(['--user', 'ow38217', '--snapshot', '9811f032'], $runner->calls[0]->arguments);

        $component->call('open', 'wp-content')->assertSet('path', 'wp-content');
        $this->assertSame(['--user', 'ow38217', '--snapshot', '9811f032', '--path', 'wp-content'], $runner->calls[1]->arguments);
        $component->call('open', '../x')->assertSet('path', 'wp-content');
        $component->call('go', 0)->assertSet('path', '');

        // A path set from outside that the script would refuse falls back to htdocs.
        $component->set('path', 'a/../../etc')->assertSet('path', '');
    }

    public function test_restoring_starts_the_right_task_and_checks_everything(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner(['site-restore' => '{"ok":true,"script":"site-restore","snapshot":"9811f032","scope":"all","safety_snapshot":"aaaa1111","files":3,"databases":[],"skipped":[]}']);
        $this->target();
        $site = Site::factory()->create(['system_user' => 'ow38217']);
        $site->server->forceFill(['backup_ready' => true])->save();
        Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);
        $admin = $this->admin();
        $url = "/sites/{$site->id}/backups/9811f032/restore";

        // Without the confirmation nothing happens.
        $this->actingAs($admin)->post($url, ['scope' => 'all'])->assertSessionHasErrors('confirm');
        $this->actingAs($admin)->post($url, ['scope' => 'everything', 'confirm' => '1'])->assertSessionHasErrors('scope');
        $this->actingAs($admin)->post($url, ['scope' => 'path', 'path' => '../etc', 'confirm' => '1'])->assertSessionHasErrors('path');
        $this->actingAs($admin)->post($url, ['scope' => 'database', 'database' => 'ow38217_other', 'confirm' => '1'])->assertSessionHas('error');
        $this->assertSame([], $runner->calls);

        $expected = [
            [['scope' => 'all'], ['--scope', 'all']],
            [['scope' => 'files'], ['--scope', 'files']],
            [['scope' => 'databases'], ['--scope', 'databases']],
            [['scope' => 'path', 'path' => 'wp-content/themes'], ['--scope', 'path', '--path', 'wp-content/themes']],
            [['scope' => 'database', 'database' => 'ow38217_shop'], ['--scope', 'database', '--database', 'ow38217_shop']],
        ];
        foreach ($expected as $i => [$form, $arguments]) {
            $this->actingAs($admin)->post($url, $form + ['confirm' => '1'])->assertRedirect();
            $this->assertSame(array_merge(['--user', 'ow38217', '--snapshot', '9811f032'], $arguments), $runner->calls[$i]->arguments);
        }
        $this->assertSame(5, ServerTask::where('script', ServerScript::SiteRestore->value)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.backup_restore_started']);

        // While a restore runs, neither another restore nor a backup starts.
        ServerTask::query()->update(['status' => TaskStatus::Running->value]);
        $this->actingAs($admin)->post($url, ['scope' => 'all', 'confirm' => '1'])->assertSessionHas('error');
        $this->actingAs($admin)->post("/sites/{$site->id}/backups")->assertSessionHas('error');
        $this->assertCount(5, $runner->calls);
    }

    public function test_customers_cannot_look_into_or_restore_backups(): void
    {
        $runner = $this->runner();
        $this->target();
        $site = Site::factory()->create(['system_user' => 'ow38217']);
        $site->server->forceFill(['backup_ready' => true])->save();
        $user = User::factory()->create();

        $this->actingAs($user)->get("/sites/{$site->id}/backups/9811f032")->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/backups/9811f032/restore", ['scope' => 'all', 'confirm' => '1'])->assertForbidden();
        Livewire::withoutLazyLoading()->actingAs($user)->test(SiteBackupList::class, ['site' => $site])->assertForbidden();
        Livewire::withoutLazyLoading()->actingAs($user)->test(SiteBackupBrowser::class, ['site' => $site, 'snapshot' => '9811f032'])->assertForbidden();
        $this->assertSame([], $runner->calls);
        $this->assertSame(0, ServerTask::count());
    }

    public function test_without_a_connected_target_nothing_runs(): void
    {
        $runner = $this->runner();
        $site = Site::factory()->create();

        $this->actingAs($this->admin())->get("/sites/{$site->id}/backups")->assertOk()->assertSee('Kein Backup-Ziel eingerichtet');
        $this->actingAs($this->admin())->post("/sites/{$site->id}/backups")->assertSessionHas('error');
        $this->artisan('owpanel:backups')->assertSuccessful();

        $this->assertSame([], $runner->calls);
    }

    public function test_the_schedule_backs_up_each_enabled_site_once_a_day(): void
    {
        $runner = $this->runner();
        $this->target();
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $server = Server::factory()->create(['backup_ready' => true]);
        $on = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow20001']);
        // Each server at its own time (E-100).
        $later = Server::factory()->create(['backup_ready' => true]);
        $later->forceFill(['backup_time' => '04:00'])->save();
        Site::factory()->create(['server_id' => $later->id, 'system_user' => 'ow20005']);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow20002', 'backup_enabled' => false]);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow20003', 'status' => SiteStatus::Pending]);
        $offline = Server::factory()->create(['backup_ready' => false]);
        Site::factory()->create(['server_id' => $offline->id, 'system_user' => 'ow20004']);

        $this->travelTo(now('Europe/Berlin')->setTime(2, 0));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertSame([], $runner->calls);

        // A backup by hand earlier that day does not replace the nightly one.
        ServerTask::start($server, ScriptCall::siteBackup('ow20001', true, $server->backupRetention()), null, $on);

        $this->travelTo(now('Europe/Berlin')->setTime(2, 31));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->artisan('owpanel:backups')->assertSuccessful();

        $scheduled = ServerTask::where('script', ServerScript::SiteBackup->value)->get()
            ->reject(fn (ServerTask $task) => in_array('--manual', $task->arguments, true));
        $this->assertCount(1, $scheduled);
        $this->assertSame('ow20001', $scheduled->first()->arguments[1]);
    }

    public function test_after_a_restore_the_panel_server_connects_its_backups_again(): void
    {
        Queue::fake();
        $this->target();
        $other = Server::factory()->create(['backup_ready' => false]);
        $own = Server::factory()->create(['host' => '127.0.0.1', 'backup_ready' => false]);

        $this->artisan('owpanel:reconnect-backups')->assertSuccessful();

        $task = ServerTask::where('script', ServerScript::BackupSetup->value)->sole();
        $this->assertSame($own->id, $task->server_id);
        $this->assertFalse($other->tasks()->exists());

        // Connected already or without a backup target: nothing to do.
        $own->forceFill(['backup_ready' => true])->save();
        $this->artisan('owpanel:reconnect-backups')->expectsOutput('nothing to reconnect')->assertSuccessful();
        $this->assertSame(1, ServerTask::count());
    }

    public function test_backups_every_six_hours_run_once_per_slot_with_hourly_retention(): void
    {
        $this->runner();
        $this->target();
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $server = Server::factory()->create(['backup_ready' => true]);
        $server->forceFill(['backup_time' => '19:00', 'backup_interval' => 6, 'backup_keep_hourly' => 12])->save();
        $site = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow20001']);
        $scheduled = fn () => ServerTask::where('script', ServerScript::SiteBackup->value)->get()
            ->reject(fn (ServerTask $task) => in_array('--manual', $task->arguments, true));

        // 19, 01, 07 and 13 o'clock; 02:00 belongs to the slot of 01:00.
        $this->assertSame('00:00', $server->backupSlot(now('Europe/Berlin')->setTime(2, 0))->format('H:i'));
        $this->assertSame('18:00', $server->backupSlot(now('Europe/Berlin')->setTime(19, 0))->format('H:i'));
        $this->assertSame('12:00', $server->backupSlot(now('Europe/Berlin')->setTime(18, 59))->format('H:i'));

        $this->travelTo(now('Europe/Berlin')->setTime(7, 10));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertCount(1, $scheduled());
        $this->assertSame(['--user', 'ow20001', '--keep-hourly', '12', '--keep-daily', '7', '--keep-weekly', '4', '--keep-monthly', '6'], $scheduled()->first()->arguments);

        // Same slot: nothing new; while one still runs: nothing new either.
        $this->travelTo(now('Europe/Berlin')->setTime(12, 59));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertCount(1, $scheduled());
        ServerTask::query()->update(['status' => TaskStatus::Running->value]);
        $this->travelTo(now('Europe/Berlin')->setTime(13, 0));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertCount(1, $scheduled());

        ServerTask::query()->update(['status' => TaskStatus::Succeeded->value]);
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertCount(2, $scheduled());

        // Daily backups keep no hourly ones.
        $server->forceFill(['backup_interval' => 24])->save();
        $this->assertSame(0, $server->backupRetention()['hourly']);
    }

    public function test_the_interval_is_saved_and_validated(): void
    {
        $this->hetzner();
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)->put("/servers/{$server->id}/backups", $this->form(['backup_interval' => 5]))->assertSessionHasErrors('backup_interval');
        $this->actingAs($admin)->put("/servers/{$server->id}/backups", $this->form(['backup_interval' => 3, 'backup_keep_hourly' => 48]));
        $server->refresh();
        $this->assertSame([3, 48], [$server->backup_interval, $server->backup_keep_hourly]);
        $server->forceFill(['backup_ready' => true])->save();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/servers')->assertSee('alle 3 h ab 02:30 Uhr');
    }

    public function test_moving_the_time_later_in_the_day_runs_the_backup_again(): void
    {
        $this->runner();
        $this->target();
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $server = Server::factory()->create(['backup_ready' => true]);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow20001']);
        $scheduled = fn () => ServerTask::where('script', ServerScript::SiteBackup->value)->get()
            ->reject(fn (ServerTask $task) => in_array('--manual', $task->arguments, true))->count();

        $this->travelTo(now('Europe/Berlin')->setTime(2, 31));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertSame(1, $scheduled());

        // The administrator moves the time to the evening of the same day.
        $server->forceFill(['backup_time' => '19:00'])->save();
        $this->travelTo(now('Europe/Berlin')->setTime(18, 59));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertSame(1, $scheduled());

        $this->travelTo(now('Europe/Berlin')->setTime(19, 0));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->assertSame(2, $scheduled());
    }

    public function test_the_nightly_switch_and_access(): void
    {
        $runner = $this->runner();
        $site = Site::factory()->create();

        $this->actingAs($this->admin())->post("/sites/{$site->id}/backups/toggle")->assertRedirect();
        $this->assertFalse($site->fresh()->backup_enabled);

        $user = User::factory()->create();
        $this->actingAs($user)->get("/sites/{$site->id}/backups")->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/backups")->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/backups/toggle")->assertForbidden();
        $this->actingAs($user)->put("/servers/{$site->server->id}/backups", $this->form())->assertForbidden();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')->assertForbidden();
        $this->assertSame([], $runner->calls);
        $this->assertFalse($site->fresh()->backup_enabled);
    }

    public function test_repository_passwords_are_only_in_the_emergency_data(): void
    {
        $this->target();
        $server = Server::factory()->create(['name' => 'web1']);
        $server->forceFill(['backup_repo_password' => str_repeat('a', 48), 'backup_ready' => true])->save();
        $admin = $this->admin();

        // The overview on System -> Backup links to the server, without passwords.
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertOk()->assertSee('Backups der Server')->assertSee('täglich um 02:30')
            ->assertSee('href="'.url("/servers/{$server->id}/backups").'"', false)
            ->assertDontSee(str_repeat('a', 48));
        // Server -> Backups needs no password again and so shows none.
        $this->actingAs($admin)->get("/servers/{$server->id}/backups")->assertOk()
            ->assertSee('Backup-Ziel')->assertSee('owpanel/'.strtolower($server->id))
            ->assertDontSee(str_repeat('a', 48));
    }

    public function test_the_backup_page_opens_without_a_password_prompt_and_is_linked_in_the_menu(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings/backup')->assertOk();
        $this->actingAs($admin)->get('/settings')
            ->assertOk()->assertSee('href="'.url('/settings/backup').'"', false)
            ->assertDontSee('Backup-Ziel')->assertDontSee('hetzner_token');
    }

    public function test_the_panel_is_backed_up_every_night_and_by_hand(): void
    {
        $runner = $this->runner(['panel-backups' => '{"ok":true,"script":"panel-backups","snapshots":[{"id":"85f2cfc3","time":"2026-11-12T02:30:00+01:00","kind":"schedule"},{"id":"a91b9397","time":"2026-11-11T10:43:27+01:00","kind":"manual"}]}']);
        $this->target();
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $own = Server::factory()->create(['host' => '127.0.0.1', 'backup_ready' => true]);
        Server::factory()->create(['host' => '203.0.113.5', 'backup_ready' => true]);
        $admin = $this->admin();

        // By hand: does not count as the nightly one.
        $this->actingAs($admin)->post("/servers/{$own->id}/backups/panel")->assertRedirect();
        $manual = ServerTask::where('script', ServerScript::PanelBackup->value)->sole();
        $this->assertSame($own->id, $manual->server_id);
        $this->assertSame(['--manual', '--keep-daily', '7', '--keep-weekly', '4', '--keep-monthly', '6'], $manual->arguments);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.panel_backup_started']);

        $this->travelTo(now('Europe/Berlin')->setTime(2, 31));
        $this->artisan('owpanel:backups')->assertSuccessful();
        $this->artisan('owpanel:backups')->assertSuccessful();
        $nightly = ServerTask::where('script', ServerScript::PanelBackup->value)->get()
            ->filter(fn (ServerTask $task) => ! in_array('--manual', $task->arguments, true));
        $this->assertCount(1, $nightly);
        $this->assertSame($own->id, $nightly->first()->server_id);

        // While one runs, the button starts no second one.
        ServerTask::query()->update(['status' => TaskStatus::Running->value]);
        $this->actingAs($admin)->post("/servers/{$own->id}/backups/panel")->assertSessionHas('error');
        $this->assertSame(2, ServerTask::where('script', ServerScript::PanelBackup->value)->count());

        Livewire::withoutLazyLoading()->actingAs($admin)->test(PanelBackupList::class, ['server' => $own])
            ->assertSee('85f2cfc3')->assertSee('a91b9397')->assertSee('von Hand');
        $this->actingAs($admin)->get("/servers/{$own->id}/backups")->assertOk()->assertSee('Sicherung des Panels')
            ->assertSee('action="'.url("/servers/{$own->id}/backups/panel").'"', false);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertOk()->assertDontSee('Sicherung des Panels')->assertDontSee('wiederhergestellt');

        // After a restore the installer notes the backup; the panel shows it.
        Setting::put(Setting::PANEL_RESTORED, '{"snapshot":"a91b9397","backup_time":"2026-11-12 01:30","restored_at":"2026-11-12 18:05"}');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertOk()->assertSee('aus der Sicherung vom 12.11.2026 02:30 wiederhergestellt')->assertSee('<code>a91b9397</code>', false);
        Livewire::withoutLazyLoading()->actingAs($admin)->test(PanelBackupList::class, ['server' => $own])
            ->assertSeeHtml('wiederhergestellt</span>');

        // The hint can be hidden; the backup stays marked in the list.
        $this->actingAs(User::factory()->create())->post('/settings/backup/restored/dismiss')->assertForbidden();
        $this->actingAs($admin)->post('/settings/backup/restored/dismiss')->assertRedirect(route('settings.backup'));
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertOk()->assertDontSee('aus der Sicherung vom');
        $this->assertSame('a91b9397', Setting::panelRestored()['snapshot']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.restored_dismissed']);
        Livewire::withoutLazyLoading()->actingAs($admin)->test(PanelBackupList::class, ['server' => $own])
            ->assertSeeHtml('wiederhergestellt</span>');
    }

    public function test_without_a_connected_panel_server_nothing_runs_and_customers_are_refused(): void
    {
        $runner = $this->runner();
        $this->target();
        $own = Server::factory()->create(['host' => '127.0.0.1', 'backup_ready' => false]);

        $this->actingAs($this->admin())->post("/servers/{$own->id}/backups/panel")->assertSessionHas('error');
        $this->travelTo(now('Europe/Berlin')->setTime(23, 0));
        $this->artisan('owpanel:backups')->assertSuccessful();
        Livewire::withoutLazyLoading()->actingAs($this->admin())->test(PanelBackupList::class, ['server' => $own])->assertSee('noch nicht mit dem Backup-Ziel verbunden');

        $user = User::factory()->create();
        $this->actingAs($user)->post("/servers/{$own->id}/backups/panel")->assertForbidden();
        Livewire::withoutLazyLoading()->actingAs($user)->test(PanelBackupList::class, ['server' => $own])->assertForbidden();
        $this->assertSame([], $runner->calls);
        $this->assertSame(0, ServerTask::count());
    }

    public function test_connecting_the_panel_server_backs_up_the_panel_right_away(): void
    {
        $this->hetzner();
        $runner = $this->runner(['backup-setup' => '{"ok":true,"script":"backup-setup","key_installed":true,"initialized":true,"public_key":"'.self::KEY.'"}']);
        $own = Server::factory()->create(['host' => '127.0.0.1', 'name' => 'a']);
        $other = Server::factory()->create(['host' => '203.0.113.5', 'name' => 'b']);

        $this->actingAs($this->admin())->put("/servers/{$own->id}/backups", $this->form());
        $this->actingAs($this->admin())->put("/servers/{$other->id}/backups", $this->form());

        $panel = ServerTask::where('script', ServerScript::PanelBackup->value)->sole();
        $this->assertSame($own->id, $panel->server_id);
        $this->assertNotContains('--manual', $panel->arguments);

        // Connecting again while it is ready starts no second one.
        $this->actingAs($this->admin())->put("/servers/{$own->id}/backups", $this->form(['backup_external' => '1']));
        $this->assertSame(1, ServerTask::where('script', ServerScript::PanelBackup->value)->count());
        $this->assertTrue($other->fresh()->backup_ready);
    }

    public function test_the_emergency_data_holds_every_repository_password_and_needs_the_password(): void
    {
        $this->target();
        $own = Server::factory()->create(['host' => '127.0.0.1', 'name' => 'panel-host']);
        $own->forceFill(['backup_repo_password' => str_repeat('a', 48), 'backup_account' => 'u123456-sub1', 'backup_ready' => true])->save();
        $other = Server::factory()->create(['name' => 'web & shop']);
        $other->forceFill(['backup_repo_password' => str_repeat('b', 48), 'backup_account' => 'u123456-sub2'])->save();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/settings/backup/export');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="owpanel-notfalldaten-', $response->headers->get('Content-Disposition'));
        $text = $response->getContent();
        $this->assertStringContainsString(str_repeat('a', 48), $text);
        $this->assertStringContainsString(str_repeat('b', 48), $text);
        $this->assertStringContainsString('u123456-sub1', $text);
        $this->assertStringContainsString('hier läuft das Panel', $text);
        $this->assertStringContainsString('web & shop', $text);
        // The subaccount password is not stored; the file says how to set a new one.
        $this->assertSame(2, substr_count($text, 'Passwort des Subaccounts: wird nirgends gespeichert'));
        $this->assertStringContainsString('„Passwort zurücksetzen“', $text);
        $this->assertStringContainsString('sftp.command="ssh -p 23 u123456-sub1@u123456.your-storagebox.de -s sftp"', $text);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.backup_keys_exported']);

        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/backup/export')->assertForbidden();
    }

    public function test_the_server_list_shows_snapshots_and_backup_per_server(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create(['name' => 'a', 'backup_ready' => false]);
        $server->forceFill(['snapshot_enabled' => true, 'snapshot_time' => '03:30'])->save();

        // Without a token and a target both are off.
        $this->actingAs($admin)->get('/servers')->assertOk()->assertSee('Snapshots')->assertDontSee('täglich 03:30 Uhr')->assertDontSee('nicht verbunden');

        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        $this->target();
        $this->actingAs($admin)->get('/servers')->assertSee('täglich 03:30 Uhr')->assertSee('nicht verbunden')
            ->assertSee('href="'.url("/servers/{$server->id}/snapshots").'"', false);

        $server->forceFill(['backup_ready' => true])->save();
        $this->actingAs($admin)->get('/servers')->assertSee('täglich 02:30 Uhr')->assertDontSee('nachts 02:30');
    }

    public function test_the_server_backup_page_lists_its_websites_with_their_last_backup(): void
    {
        $this->runner();
        $this->target();
        $server = Server::factory()->create(['host' => '127.0.0.1', 'backup_ready' => true]);
        $on = Site::factory()->create(['server_id' => $server->id, 'domain' => 'shop.example.de', 'system_user' => 'ow20001']);
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'blog.example.de', 'system_user' => 'ow20002', 'backup_enabled' => false]);
        ServerTask::start($server, ScriptCall::siteBackup('ow20001', false, $server->backupRetention()), null, $on);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/servers/{$server->id}/backups")->assertOk()
            ->assertSee('shop.example.de')->assertSee('blog.example.de')
            ->assertSee('eingeschaltet')->assertSee('ausgeschaltet')->assertSee('noch keine')
            ->assertSee('href="'.url("/sites/{$on->id}/backups").'"', false)
            ->assertSee('Sicherung des Panels');
        $this->actingAs($admin)->get("/servers/{$server->id}")->assertSee('href="'.url("/servers/{$server->id}/backups").'"', false);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertSee('Backups der Server')->assertSee('href="'.url("/servers/{$server->id}/backups").'"', false);

        $this->actingAs(User::factory()->create())->get("/servers/{$server->id}/backups")->assertForbidden();
    }

    public function test_a_panel_backup_shows_its_files_but_offers_no_restore(): void
    {
        $runner = $this->runner([
            'panel-backups' => '{"ok":true,"script":"panel-backups","snapshots":[{"id":"85f2cfc3","time":"2026-11-12T02:30:00+01:00","kind":"schedule"}]}',
            'panel-backup-files' => '{"ok":true,"script":"panel-backup-files","snapshot":"85f2cfc3","files":{"entries":[{"name":"/var/backups/owpanel/panel/owpanel.sql","type":"file","size":8000,"mtime":"2026-11-12T02:30:01"},{"name":"/var/www/ow10000/shared/.env","type":"file","size":900,"mtime":null},{"name":"relative/evil","type":"file"}],"more":false}}',
        ]);
        $this->target();
        $own = Server::factory()->create(['host' => '127.0.0.1', 'backup_ready' => true]);
        $other = Server::factory()->create(['host' => '203.0.113.5', 'backup_ready' => true]);
        $admin = $this->admin();

        Livewire::withoutLazyLoading()->actingAs($admin)->test(PanelBackupList::class, ['server' => $own])
            ->assertSeeHtml('href="'.url("/servers/{$own->id}/backups/panel/85f2cfc3").'"');
        $this->actingAs($admin)->get("/servers/{$own->id}/backups/panel/85f2cfc3")->assertOk()
            ->assertSee('Panel-Sicherung 85f2cfc3')->assertSee('Restore from a backup?')->assertDontSee('Ja, wiederherstellen');
        $this->actingAs($admin)->get("/servers/{$other->id}/backups/panel/85f2cfc3")->assertNotFound();

        Livewire::withoutLazyLoading()->actingAs($admin)->test(PanelBackupFiles::class, ['server' => $own, 'snapshot' => '85f2cfc3'])
            ->assertSee('Datenbank des Panels')->assertSee('/var/www/ow10000/shared/.env')->assertDontSee('relative/evil');
        $this->assertSame(['--snapshot', '85f2cfc3'], end($runner->calls)->arguments);

        $user = User::factory()->create();
        $this->actingAs($user)->get("/servers/{$own->id}/backups/panel/85f2cfc3")->assertForbidden();
        Livewire::withoutLazyLoading()->actingAs($user)->test(PanelBackupFiles::class, ['server' => $own, 'snapshot' => '85f2cfc3'])->assertForbidden();
    }

    public function test_the_panel_backups_show_three_per_page_and_are_read_once(): void
    {
        $snapshots = [];
        foreach (['11111111', '22222222', '33333333', '44444444', '55555555'] as $n => $id) {
            $snapshots[] = ['id' => $id, 'time' => now()->subDays($n)->toIso8601String(), 'kind' => $n === 1 ? 'manual' : 'schedule'];
        }
        $runner = $this->runner(['panel-backups' => json_encode(['ok' => true, 'script' => 'panel-backups', 'snapshots' => $snapshots])]);
        $this->target();
        $own = Server::factory()->create(['host' => '127.0.0.1', 'backup_ready' => true]);

        $list = Livewire::withoutLazyLoading()->actingAs($this->admin())->test(PanelBackupList::class, ['server' => $own])
            ->assertSeeInOrder(['ID', 'Automatische Sicherung', 'Art', 'Zeitpunkt'])
            ->assertSee('11111111')->assertSee('33333333')->assertDontSee('44444444')
            ->assertSee('Seite 1 von 2')->assertSee('Erfolgreich');
        $list->call('older')->assertSee('55555555')->assertDontSee('11111111')->assertSee('Seite 2 von 2');
        $list->call('older')->assertSee('Seite 2 von 2');
        $list->call('newer')->assertSee('11111111');
        $this->assertCount(1, array_filter($runner->calls, fn ($call) => $call->script->value === 'panel-backups'));
    }
}
