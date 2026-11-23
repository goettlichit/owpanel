<?php

namespace Tests\Feature;

use App\Customers\PackageLimits;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Livewire\SiteBackupBrowser;
use App\Livewire\SiteBackupList;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Backups in the customer area (E-124, E-145): see them, back up now and
 * restore, only for the own websites and within the daily limit.
 */
class CustomerBackupsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $site;

    private Site $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::factory()->create(['name' => 'geheim-1', 'backup_box_id' => 7, 'backup_box_name' => 'backup', 'backup_host' => 'u123456.your-storagebox.de', 'backup_ready' => true]);
        $package = Package::factory()->create(['daily_self_service' => 2]);
        $customer = Customer::factory()->create();
        $customer->package()->associate($package)->save();
        $this->user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $this->site = Site::factory()->for($customer)->for($server)->create(['system_user' => 'ow38217', 'domain' => 'meine.example']);
        $this->foreign = Site::factory()->for(Customer::factory())->for($server)->create(['system_user' => 'ow49999']);

        $this->app->instance(ScriptRunner::class, new class implements ScriptRunner
        {
            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $script = $call->script->value;
                $output = match ($script) {
                    'site-backups' => '{"ok":true,"script":"site-backups","snapshots":[{"id":"9811f032","time":"2026-11-12T00:08:38+01:00","size":2048,"added":512,"kind":"manual"}]}',
                    'site-backup-files' => '{"ok":true,"script":"site-backup-files","path":"","entries":[{"name":"index.php","type":"file","size":10}],"databases":[]}',
                    default => '{"ok":true,"script":"'.$script.'"}',
                }."\n";
                $onOutput($output);

                return ScriptResult::fromOutput($script, $output, 0, false);
            }
        });
    }

    public function test_a_customer_sees_his_backups_without_server_or_switch(): void
    {
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/backups")->assertOk()
            ->assertSee(route('my.sites.backups.store', $this->site), false)
            ->assertSee('Heute noch 2 von 2 Sicherungen und Wiederherstellungen.')
            ->assertDontSee(route('sites.backups.toggle', $this->site), false)
            ->assertDontSee('/servers/')
            ->assertDontSee('geheim-1');

        Livewire::withoutLazyLoading()->actingAs($this->user)->test(SiteBackupList::class, ['site' => $this->site, 'area' => 'my.'])
            ->assertSee('9811f032')
            ->assertSeeHtml('href="'.url("/my/sites/{$this->site->id}/backups/9811f032").'"');

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/backups/9811f032")->assertOk()
            ->assertSee(route('my.sites.backups.restore', [$this->site, '9811f032']), false)
            ->assertDontSee('rebuild');
        Livewire::withoutLazyLoading()->actingAs($this->user)->test(SiteBackupBrowser::class, ['site' => $this->site, 'snapshot' => '9811f032', 'area' => 'my.'])
            ->assertSee('Dateien');
    }

    public function test_backups_and_restores_follow_the_daily_limit(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/backups")
            ->assertRedirect("/my/sites/{$this->site->id}/backups");
        ServerTask::query()->update(['status' => TaskStatus::Succeeded]);

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/backups/9811f032/restore", ['scope' => 'all', 'confirm' => '1'])
            ->assertRedirect("/my/sites/{$this->site->id}/backups/9811f032");
        ServerTask::query()->update(['status' => TaskStatus::Succeeded]);
        $this->assertSame(1, AuditLog::where('action', 'site.backup_restore_started')->where('user_id', $this->user->id)->count());

        // Two a day: the third is refused, also with the field of the administrators.
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/backups", ['exceed_limits' => '1'])
            ->assertSessionHas('error', 'Ihr Paket erlaubt 2 Sicherungen und Wiederherstellungen je Tag; für heute sind sie aufgebraucht.');
        $this->assertSame(2, ServerTask::count());
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/backups")
            ->assertSeeInOrder(['alert alert-warning', 'Für heute sind alle 2 Sicherungen und Wiederherstellungen Ihres Pakets aufgebraucht.'], false)
            ->assertDontSee('Heute noch');
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/backups/9811f032")
            ->assertSee('Für heute sind alle 2 Sicherungen');

        // Those of administrators do not count, and they are not limited.
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->post("/sites/{$this->site->id}/backups")->assertRedirect();
        $this->assertSame(3, ServerTask::count());
        $this->assertSame(2, PackageLimits::selfServiceToday($this->site->customer));

        // A new day.
        $this->travel(1)->days();
        ServerTask::query()->update(['status' => TaskStatus::Succeeded]);
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/backups")->assertRedirect("/my/sites/{$this->site->id}/backups");
    }

    public function test_without_a_package_there_are_no_own_backups(): void
    {
        $this->site->customer->package()->dissociate()->save();

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/backups")->assertOk()
            ->assertSeeInOrder(['alert alert-warning', 'Für Ihr Konto ist kein Paket gebucht; eigene Sicherungen und Wiederherstellungen sind deshalb nicht möglich.'], false)
            ->assertDontSee('ohne Grenze je Tag');
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/backups/9811f032")->assertOk()
            ->assertSee('kein Paket gebucht');

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/backups")
            ->assertSessionHas('error', 'Für Ihr Konto ist kein Paket gebucht. Bitte wenden Sie sich an uns.');
        $this->assertSame(0, ServerTask::count());
    }

    public function test_a_customer_reaches_no_backup_of_another_customer(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([['get', "/my/sites/{$this->foreign->id}/backups"], ['post', "/my/sites/{$this->foreign->id}/backups"],
            ['get', "/my/sites/{$this->foreign->id}/backups/9811f032"], ['post', "/my/sites/{$this->foreign->id}/backups/9811f032/restore"]] as [$method, $uri]) {
            $this->actingAs($this->user)->{$method}($uri, ['scope' => 'all', 'confirm' => '1'])->assertForbidden();
        }
        Livewire::withoutLazyLoading()->actingAs($this->user)->test(SiteBackupList::class, ['site' => $this->foreign, 'area' => 'my.'])->assertForbidden();
        Livewire::withoutLazyLoading()->actingAs($this->user)->test(SiteBackupBrowser::class, ['site' => $this->foreign, 'snapshot' => '9811f032', 'area' => 'my.'])->assertForbidden();

        // Switch and rebuild stay with the administration, also for the own website.
        foreach (["/sites/{$this->site->id}/backups/toggle", "/sites/{$this->site->id}/backups/rebuild", "/sites/{$this->site->id}/backups"] as $uri) {
            $this->actingAs($this->user)->post($uri, ['confirm' => '1'])->assertForbidden();
        }
        $this->assertSame(0, ServerTask::count());
        $this->assertSame(0, ServerTask::where('script', ServerScript::SiteBackup->value)->count());
    }
}
