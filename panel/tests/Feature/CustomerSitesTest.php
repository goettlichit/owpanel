<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Livewire\TaskBadge;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Websites in the customer area, part 1 (E-124, E-143): overview with www
 * and certificate, DNS check, SFTP and logs, each only for the own website.
 */
class CustomerSitesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $renderDeferred = true;

    private User $user;

    private Site $site;

    private Site $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::factory()->create(['name' => 'geheim-1', 'public_ipv4' => '203.0.113.10', 'public_ipv6' => null]);
        $customer = Customer::factory()->create();
        $this->user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $this->site = Site::factory()->for($customer)->for($server)->create(['domain' => 'meine.example', 'system_user' => 'ow38217', 'tls' => false, 'wants_tls' => false]);
        $this->foreign = Site::factory()->for(Customer::factory())->for($server)->create(['domain' => 'fremde.example', 'system_user' => 'ow49999']);
        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(['{"ok":true}'."\n"]));
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function __construct() {}

            public function addresses(string $host): array
            {
                return ['203.0.113.10'];
            }

            public function nameservers(string $host): array
            {
                return [['host' => 'ns1.example.net', 'ip' => '192.0.2.53']];
            }

            public function authoritative(string $host, int $type): ?array
            {
                return ['203.0.113.10'];
            }
        });
    }

    /**
     * Every page and action of the customer area for a website.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function routes(Site $site): array
    {
        return [
            ['get', "/my/sites/{$site->id}"],
            ['get', "/my/sites/{$site->id}/dns"],
            ['post', "/my/sites/{$site->id}/dns/check"],
            ['get', "/my/sites/{$site->id}/logs"],
            ['get', "/my/sites/{$site->id}/sftp"],
            ['get', "/my/sites/{$site->id}/sftp/password"],
            ['post', "/my/sites/{$site->id}/sftp/toggle"],
            ['post', "/my/sites/{$site->id}/sftp/password"],
            ['post', "/my/sites/{$site->id}/sftp/keys"],
            ['post', "/my/sites/{$site->id}/certificate"],
            ['post', "/my/sites/{$site->id}/www"],
            ['get', "/my/sites/{$site->id}/php"],
            ['post', "/my/sites/{$site->id}/php"],
            ['post', "/my/sites/{$site->id}/php/settings"],
        ];
    }

    public function test_the_overview_shows_the_own_website_without_server_details(): void
    {
        $this->actingAs($this->user)->get('/my/sites')->assertOk()
            ->assertSee(route('my.sites.show', $this->site), false)->assertDontSee('fremde.example');

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}")->assertOk()
            ->assertSee('meine.example')
            ->assertSee('Zertifikat anfordern')
            ->assertSee('/htdocs/public')
            ->assertSee(route('my.sites.sftp', $this->site), false)
            ->assertSee(route('my.sites.logs', $this->site), false)
            ->assertSee(route('my.sites.dns', $this->site), false)
            ->assertDontSee('geheim-1')
            ->assertDontSee('/var/www')
            ->assertDontSee('/servers/')
            ->assertDontSee('/tasks/');
    }

    public function test_a_customer_reaches_nothing_of_another_customer(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach ($this->routes($this->foreign) as [$method, $uri]) {
            $this->actingAs($this->user)->{$method}($uri, ['www' => '1', 'action' => 'generate', 'public_key' => 'x'])->assertForbidden();
        }
        $key = $this->foreign->sftpKeys()->create(['name' => 'k', 'public_key' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x']);
        $this->actingAs($this->user)->delete("/my/sites/{$this->foreign->id}/sftp/keys/{$key->id}")->assertForbidden();
        // A key of another website through the own website.
        $this->actingAs($this->user)->delete("/my/sites/{$this->site->id}/sftp/keys/{$key->id}")->assertNotFound();

        $this->assertSame(1, $this->foreign->sftpKeys()->count());
        $this->assertSame(0, ServerTask::count());
        $this->assertFalse($this->foreign->fresh()->sftp_enabled);
    }

    public function test_unassigned_and_administration_websites_are_not_reachable_either(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $loose = Site::factory()->create();
        $own = Site::factory()->create(['admin_owned' => true]);

        foreach ([$loose, $own] as $site) {
            foreach ($this->routes($site) as [$method, $uri]) {
                $this->actingAs($this->user)->{$method}($uri)->assertForbidden();
            }
        }
    }

    public function test_the_administration_stays_closed_even_for_the_own_website(): void
    {
        $task = ServerTask::factory()->create(['server_id' => $this->site->server_id, 'site_id' => $this->site->id]);

        foreach (["/sites/{$this->site->id}", "/sites/{$this->site->id}/sftp", "/sites/{$this->site->id}/tasks", "/tasks/{$task->id}", '/servers', "/servers/{$this->site->server_id}", '/sites'] as $uri) {
            $this->actingAs($this->user)->get($uri)->assertForbidden();
        }
        $this->actingAs($this->user)->post("/sites/{$this->site->id}/sftp/toggle")->assertForbidden();
    }

    public function test_administrators_use_their_own_pages_not_the_customer_area(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = User::factory()->admin()->withTwoFactor()->create();

        foreach ($this->routes($this->site) as [$method, $uri]) {
            $this->actingAs($admin)->{$method}($uri)->assertForbidden();
        }
        $this->actingAs($admin)->get("/sites/{$this->site->id}/sftp")->assertOk();
    }

    public function test_a_customer_turns_on_sftp_and_stays_in_his_area(): void
    {
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/sftp")->assertOk()
            ->assertSee('ow38217')->assertSee(route('my.sites.sftp.toggle', $this->site), false)
            ->assertDontSee('runuser');

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/sftp/toggle")
            ->assertRedirect("/my/sites/{$this->site->id}/sftp");

        $this->assertTrue($this->site->fresh()->sftp_enabled);
        $task = ServerTask::sole();
        $this->assertSame([ServerScript::SiteSftp, $this->user->id], [$task->script, $task->user_id]);
        $this->assertSame($this->user->id, AuditLog::where('action', 'site.sftp_enabled')->sole()->user_id);

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/sftp/password")->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'site.sftp_password_revealed')->count());

        // The state of the transfer without a link to the task log.
        Livewire::actingAs($this->user)->test(TaskBadge::class, ['task' => $task, 'label' => 'Übertragung'])
            ->assertSee('Übertragung')->assertDontSee('/tasks/');
    }

    public function test_task_badges_of_other_websites_stay_closed(): void
    {
        $task = ServerTask::factory()->create(['server_id' => $this->foreign->server_id, 'site_id' => $this->foreign->id]);

        Livewire::actingAs($this->user)->test(TaskBadge::class, ['task' => $task, 'label' => 'x'])->assertForbidden();
    }

    public function test_a_customer_requests_a_certificate_and_turns_on_www(): void
    {
        // Tasks stay waiting, as on a real server while a worker is busy.
        Queue::fake();
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/certificate")
            ->assertRedirect("/my/sites/{$this->site->id}");
        $this->assertTrue($this->site->fresh()->wants_tls);

        // While that runs, a second change waits.
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/www", ['www' => '1'])->assertSessionHas('error');
        ServerTask::query()->update(['status' => TaskStatus::Succeeded]);

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/www", ['www' => '1'])
            ->assertRedirect("/my/sites/{$this->site->id}");
        $this->assertTrue($this->site->fresh()->www);
        $this->assertSame([$this->user->id], ServerTask::pluck('user_id')->unique()->values()->all());

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}")->assertOk()->assertSee('Eine Änderung an dieser Webseite läuft gerade.');
    }

    public function test_the_dns_check_only_reads_and_asks_no_hetzner_zone(): void
    {
        Http::fake();

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/dns")->assertOk()
            ->assertSee('203.0.113.10')
            ->assertSee('Alle Einträge zeigen auf die richtige Adresse.')
            ->assertDontSee('/dns/change');
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/dns/check")
            ->assertRedirect("/my/sites/{$this->site->id}/dns");

        Http::assertNothingSent();
    }

    public function test_a_customer_reads_the_log_of_his_website(): void
    {
        $runner = new FakeScriptRunner([json_encode(['ok' => true, 'script' => 'site-logs', 'log' => 'error', 'size' => 10, 'modified' => 1_762_858_800, 'lines' => ['eine Zeile']])."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/logs?log=access")->assertOk()
            ->assertSee('eine Zeile')
            ->assertSee(e(route('my.sites.logs', [$this->site, 'log' => 'error', 'lines' => 100])), false);
        $this->assertSame(['--user', 'ow38217', '--log', 'access', '--lines', '100'], $runner->calls[0]->arguments);
    }
}
