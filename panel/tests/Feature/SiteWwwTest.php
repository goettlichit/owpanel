<?php

namespace Tests\Feature;

use App\Backups\Rebuild;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use App\Sites\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * www.<domain> as a second address that sends to the main one (E-110).
 */
class SiteWwwTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function runner(bool $tls = true, bool $wwwTls = true): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([
            '{"ok":true,"script":"site-create","domain":"owpanel.org","user":"ow12345","uid":12345,"php":"8.5","tls":'.($tls ? 'true' : 'false').',"www":true,"www_tls":'.($wwwTls ? 'true' : 'false').'}'."\n",
        ]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_a_new_website_can_answer_to_www_too(): void
    {
        $runner = $this->runner();
        Server::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/sites/create')->assertOk()
            ->assertSee('Auch unter www erreichbar')->assertSee('name="www" value="1"', false);

        $this->actingAs($admin)->post('/sites', ['domain' => 'owpanel.org', 'tls' => '1', 'www' => '1'])->assertRedirect();

        $site = Site::sole();
        $this->assertTrue($site->www);
        $this->assertTrue($site->www_tls);
        $this->assertSame(['--domain', 'owpanel.org', '--type', 'php', '--user', $site->system_user, '--tls', '--www', '--php', '8.5', '--php-memory', '256', '--php-upload', '64', '--php-time', '60', '--php-processes', '10', '--php-errors', 'off'], $runner->calls[0]->arguments);

        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSee('www.owpanel.org leitet auf owpanel.org weiter.')
            ->assertSee('Das Zertifikat gilt für beide Adressen.')
            ->assertDontSee('Ohne Zertifikat')
            ->assertSee('www-Adresse ausschalten');
    }

    public function test_www_names_cannot_be_taken_twice(): void
    {
        $this->runner();
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'owpanel.org', 'www' => true]);
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'www.example.de']);
        $admin = $this->admin();

        // The www name of another website, as a domain of its own.
        $this->actingAs($admin)->post('/sites', ['domain' => 'www.owpanel.org', 'tls' => '1'])->assertSessionHasErrors('domain');
        // A www name that is another website's domain.
        $this->actingAs($admin)->post('/sites', ['domain' => 'example.de', 'tls' => '1', 'www' => '1'])->assertSessionHasErrors('www');
        // www.www.… is no option.
        $this->actingAs($admin)->post('/sites', ['domain' => 'www.other.de', 'tls' => '1', 'www' => '1'])->assertSessionHasErrors('www');
        $this->assertSame(2, Site::count());
        $this->assertSame(0, ServerTask::count());
    }

    public function test_www_can_be_turned_on_and_off_later_with_a_new_certificate(): void
    {
        $runner = $this->runner(true, false);
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'owpanel.org', 'tls' => true, 'wants_tls' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()->assertSee('www-Adresse einschalten');

        $response = $this->actingAs($admin)->post("/sites/{$site->id}/www", ['www' => '1']);
        $task = ServerTask::latest('created_at')->latest('id')->first();
        $response->assertRedirect(route('sites.domain.show', $site));
        $this->assertSame(['--domain', 'owpanel.org', '--type', 'php', '--user', $site->system_user, '--tls', '--www'], $runner->calls[0]->arguments);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.www_enabled']);

        // Let's Encrypt refused www: the page says so and offers a new request.
        $site->refresh();
        $this->assertTrue($site->www);
        $this->assertFalse($site->www_tls);
        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSee('Das Zertifikat gilt noch nicht für www.owpanel.org')->assertSee('Zertifikat neu anfordern')
            ->assertSee('Ohne Zertifikat')->assertSee('bg-orange', false);
        $this->actingAs($admin)->get("/sites/{$site->id}")->assertOk()->assertSee('www noch ohne Zertifikat, siehe „Webseite und Domain“.');
        $this->actingAs($admin)->post("/sites/{$site->id}/certificate")->assertRedirect();
        $this->assertSame(['--domain', 'owpanel.org', '--type', 'php', '--user', $site->system_user, '--tls', '--www'], $runner->calls[1]->arguments);

        $this->actingAs($admin)->post("/sites/{$site->id}/www", ['www' => '0'])->assertRedirect();
        $this->assertSame(['--domain', 'owpanel.org', '--type', 'php', '--user', $site->system_user, '--tls'], $runner->calls[2]->arguments);
        $this->assertFalse($site->fresh()->www);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.www_disabled']);
    }

    public function test_www_waits_for_open_tasks_and_is_admin_only(): void
    {
        $this->runner();
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'owpanel.org']);
        ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $site->id, 'status' => TaskStatus::Running->value]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/www", ['www' => '1'])->assertSessionHas('error');
        $this->actingAs(User::factory()->create())->post("/sites/{$site->id}/www", ['www' => '1'])->assertForbidden();
        $this->assertFalse($site->fresh()->www);
    }

    public function test_a_rebuild_keeps_www(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'owpanel.org', 'www' => true, 'tls' => true]);

        Rebuild::site($site, 'latest', null);

        $creates = ServerTask::where('script', 'site-create')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $creates);
        $this->assertContains('--www', $creates[0]->arguments);
        $this->assertContains('--www', $creates[1]->arguments);
    }

    public function test_only_main_domains_get_www(): void
    {
        foreach (['example.de' => true, 'example.co.uk' => true, 'müller.de' => true, 'shop.example.de' => false, 'a.b.example.de' => false, 'shop.example.co.uk' => false] as $domain => $main) {
            $this->assertSame($main, Domain::isMain((string) Domain::normalize($domain)), $domain);
        }
        $this->assertFalse(Site::wwwOffered('www.example.de'));
    }

    public function test_subdomains_cannot_get_www(): void
    {
        $runner = $this->runner();
        $server = Server::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1', 'www' => '1'])
            ->assertSessionHasErrors(['www' => __('sites.www.subdomain')]);
        $this->assertSame(0, Site::count());

        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'shop.example.de']);
        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSee(__('sites.www.subdomain_off'))->assertSee('opacity-50', false);
        $this->actingAs($admin)->post("/sites/{$site->id}/www", ['www' => '1'])->assertSessionHas('error', __('sites.www.subdomain'));
        $this->assertSame([], $runner->calls);
        $this->assertFalse($site->fresh()->www);
    }

    public function test_a_subdomain_that_already_has_www_can_turn_it_off(): void
    {
        $runner = $this->runner();
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'shop.example.de', 'www' => true]);

        $this->actingAs($this->admin())->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSee('www-Adresse ausschalten')->assertDontSee('opacity-50', false);
        $this->actingAs($this->admin())->post("/sites/{$site->id}/www", ['www' => '0'])->assertRedirect();
        $this->assertNotContains('--www', $runner->calls[0]->arguments);
        $this->assertSame(['--domain', 'shop.example.de'], array_slice($runner->calls[0]->arguments, 0, 2));
    }
}
