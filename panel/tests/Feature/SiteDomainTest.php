<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Moving a website to another domain (E-112).
 */
class SiteDomainTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function runner(string $answer): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([$answer."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private static function created(string $domain, bool $www = false, bool $wwwTls = false): string
    {
        return '{"ok":true,"script":"site-create","domain":"'.$domain.'","user":"ow12345","uid":12345,"php":"8.5","tls":true,"www":'
            .($www ? 'true' : 'false').',"www_tls":'.($wwwTls ? 'true' : 'false').',"certificate_expires":1900000000}';
    }

    public function test_a_website_moves_to_a_new_domain_with_its_certificate(): void
    {
        $runner = $this->runner(self::created('new-example.de', true, true));
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'old-example.de', 'tls' => true, 'www' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSee('Domain ändern')->assertSee('bleibt die Webseite unter old-example.de erreichbar');

        $response = $this->actingAs($admin)->post("/sites/{$site->id}/domain", ['new_domain' => 'New-Example.de']);
        $response->assertRedirect(route('sites.show', $site));

        // The backend answers to both names while the gateway moves, then
        // lets go of the old one (E-195, E-112).
        $this->assertSame(['--domain', 'new-example.de', '--type', 'php', '--user', $site->system_user, '--https', '--alias', 'old-example.de'], $runner->calls[0]->arguments);
        $this->assertSame(
            ['--domain', 'new-example.de', '--user', $site->system_user, '--backend-id', $server->backendId(), '--backend', '127.0.0.1', '--tls', '--www', '--previous-domain', 'old-example.de', '--body', '72', '--timeout', '90'],
            $runner->calls[1]->arguments,
        );
        $this->assertSame(['--domain', 'new-example.de', '--type', 'php', '--user', $site->system_user, '--https', '--existing'], $runner->calls[2]->arguments);
        $site->refresh();
        $this->assertSame('new-example.de', $site->domain);
        $this->assertTrue($site->www);
        $this->assertTrue($site->www_tls);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.domain_change_started']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.domain_changed']);
    }

    public function test_a_refused_certificate_keeps_the_old_domain(): void
    {
        $this->runner('{"ok":false,"script":"site-create","error":"certificate_failed"}');
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'old.example.de', 'tls' => true, 'wants_tls' => true]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/domain", ['new_domain' => 'new.example.de'])->assertRedirect();

        $this->assertSame(TaskStatus::Failed, ServerTask::where('script', ServerScript::SiteCreate->value)->sole()->status);
        $site->refresh();
        $this->assertSame('old.example.de', $site->domain);
        $this->assertTrue($site->tls);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'site.domain_changed']);
    }

    public function test_a_domain_starting_with_www_drops_the_www_address(): void
    {
        $runner = $this->runner(self::created('www.example.de'));
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'example.de', 'tls' => true, 'www' => true, 'www_tls' => true]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/domain", ['new_domain' => 'www.example.de'])->assertRedirect();

        $this->assertSame('gateway-site', $runner->calls[1]->script->value);
        $this->assertNotContains('--www', $runner->calls[1]->arguments);
        $site->refresh();
        $this->assertSame('www.example.de', $site->domain);
        $this->assertFalse($site->www);
    }

    public function test_the_new_domain_is_checked(): void
    {
        $this->runner(self::created('x.example.de'));
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'old-example.de', 'www' => true]);
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'taken.example.de']);
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'www.free-example.de']);
        $admin = $this->admin();

        foreach (['', 'not a domain', 'old-example.de', 'taken.example.de', 'https://x.example.de/path'] as $value) {
            $this->actingAs($admin)->post("/sites/{$site->id}/domain", ['new_domain' => $value])->assertSessionHasErrors('new_domain');
        }
        // www.free-example.de belongs to another website.
        $this->actingAs($admin)->post("/sites/{$site->id}/domain", ['new_domain' => 'free-example.de'])->assertSessionHasErrors('new_domain');

        $this->assertSame(0, ServerTask::count());
        $this->assertSame('old-example.de', $site->fresh()->domain);
    }

    public function test_only_administrators_change_domains_and_never_during_a_task(): void
    {
        $this->runner(self::created('new.example.de'));
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'old.example.de']);

        $customer = User::factory()->create();
        $this->actingAs($customer)->post("/sites/{$site->id}/domain", ['new_domain' => 'new.example.de'])->assertForbidden();

        ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $site->id, 'status' => TaskStatus::Running->value]);
        $this->actingAs($this->admin())->post("/sites/{$site->id}/domain", ['new_domain' => 'new.example.de'])->assertSessionHas('error');

        $this->assertSame('old.example.de', $site->fresh()->domain);
    }

    public function test_a_task_that_reports_another_domain_changes_nothing(): void
    {
        $this->runner(self::created('evil.example.de'));
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'old.example.de', 'tls' => true]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/domain", ['new_domain' => 'new.example.de'])->assertRedirect();

        $this->assertSame('old.example.de', $site->fresh()->domain);
    }

    public function test_an_ordinary_run_of_site_create_keeps_the_domain(): void
    {
        $this->runner(self::created('other.example.de'));
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'domain' => 'old.example.de', 'tls' => false, 'wants_tls' => true]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/certificate")->assertRedirect();

        $this->assertSame('old.example.de', $site->fresh()->domain);
    }
}
