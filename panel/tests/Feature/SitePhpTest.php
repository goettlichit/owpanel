<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * PHP version per website (E-172): chosen on creation, switched by
 * administrators and the customer of the website, only versions the server
 * has installed.
 */
class SitePhpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function runner(string $php = '8.6'): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([
            '{"ok":true,"script":"site-create","domain":"shop.example.de","user":"ow38217","uid":38217,"php":"'.$php.'","tls":false,"www":false,"www_tls":false}'."\n",
        ]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_a_new_website_gets_the_default_or_a_version_the_server_has(): void
    {
        $this->runner('8.5');
        $server = Server::factory()->create(['php_versions' => ['8.5']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/sites/create')->assertOk()->assertSee('PHP 8.6');

        // 8.6 is not installed on this server yet.
        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'php' => '8.6'])
            ->assertSessionHasErrors(['php' => __('sites.php.not_installed', ['version' => '8.6'])]);
        $this->assertSame(0, Site::count());
        // Older versions are not offered at all.
        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de', 'php' => '8.4'])->assertSessionHasErrors('php');

        $this->actingAs($admin)->post('/sites', ['domain' => 'shop.example.de'])->assertRedirect();
        $task = ServerTask::sole();
        $this->assertSame(['--php', '8.5'], array_slice($task->arguments, 6, 2));
        $this->assertSame('8.5', Site::sole()->php_version);

        $server->forceFill(['php_versions' => ['8.5', '8.6']])->save();
        $this->runner('8.6');
        $this->actingAs($admin)->post('/sites', ['domain' => 'blog.example.de', 'php' => '8.6'])->assertRedirect();
        $this->assertSame('8.6', Site::where('domain', 'blog.example.de')->sole()->php_version);
    }

    public function test_administrators_switch_the_version_and_cron_jobs_follow(): void
    {
        Queue::fake();
        $server = Server::factory()->create(['php_versions' => ['8.5', '8.6']]);
        $site = Site::factory()->for($server)->create(['domain' => 'shop.example.de', 'system_user' => 'ow38217', 'php_version' => '8.5', 'tls' => true]);
        $site->cronJobs()->create(['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run', 'enabled' => true]);
        $admin = $this->admin();

        // An own menu entry "PHP", no longer on "Website and domain".
        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()->assertDontSee(__('sites.php.title'));
        $this->actingAs($admin)->get("/sites/{$site->id}/php")->assertOk()
            ->assertSee(__('sites.php.title'))->assertSee('PHP 8.6');

        $this->actingAs($admin)->post("/sites/{$site->id}/php", ['php' => '8.6'])
            ->assertRedirect();

        $create = ServerTask::where('script', ServerScript::SiteCreate->value)->sole();
        $this->assertSame(['--domain', 'shop.example.de', '--type', 'php', '--user', 'ow38217', '--tls', '--php', '8.6'], $create->arguments);
        $cron = ServerTask::where('script', ServerScript::SiteCron->value)->sole();
        $this->assertSame($create->id, $cron->after_task_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.php_changed']);
        // The version shown changes with the answer of the server.
        $this->assertSame('8.5', $site->fresh()->php_version);
    }

    public function test_the_switch_refuses_unknown_versions_busy_websites_and_no_change(): void
    {
        Queue::fake();
        $server = Server::factory()->create(['php_versions' => ['8.5']]);
        $site = Site::factory()->for($server)->create(['php_version' => '8.5']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sites/{$site->id}/php", ['php' => '8.6'])
            ->assertSessionHas('error', __('sites.php.not_installed', ['version' => '8.6']));
        $this->actingAs($admin)->post("/sites/{$site->id}/php", ['php' => '7.4'])->assertSessionHasErrors('php');
        $this->actingAs($admin)->post("/sites/{$site->id}/php", ['php' => '8.5'])->assertRedirect();
        $this->assertSame(0, ServerTask::count());

        $server->forceFill(['php_versions' => ['8.5', '8.6']])->save();
        ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $site->id, 'status' => TaskStatus::Running->value]);
        $this->actingAs($admin)->post("/sites/{$site->id}/php", ['php' => '8.6'])
            ->assertSessionHas('error', __('sites.php.busy'));
        $this->assertSame(1, ServerTask::count());
    }

    public function test_customers_switch_only_their_own_websites(): void
    {
        Queue::fake();
        $server = Server::factory()->create(['php_versions' => ['8.5', '8.6']]);
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $own = Site::factory()->for($customer)->for($server)->create(['php_version' => '8.5']);
        $foreign = Site::factory()->for(Customer::factory())->for($server)->create(['php_version' => '8.5']);

        $this->actingAs($user)->get("/my/sites/{$own->id}")->assertOk()->assertSee(route('my.sites.php.show', $own), false);
        $this->actingAs($user)->get("/my/sites/{$own->id}/php")->assertOk()->assertSee(__('sites.php.title'));
        $this->actingAs($user)->get("/my/sites/{$foreign->id}/php")->assertForbidden();

        $this->actingAs($user)->post("/my/sites/{$foreign->id}/php", ['php' => '8.6'])->assertForbidden();
        $this->actingAs($user)->post("/sites/{$own->id}/php", ['php' => '8.6'])->assertForbidden();
        $this->assertSame(0, ServerTask::count());

        $this->actingAs($user)->post("/my/sites/{$own->id}/php", ['php' => '8.6'])->assertRedirect();
        $this->assertSame(['--php', '8.6'], array_slice(ServerTask::sole()->arguments, -2));
    }

    public function test_a_server_without_a_report_offers_only_the_default(): void
    {
        $server = Server::factory()->create(['php_versions' => null]);
        $this->assertSame(['8.5'], $server->phpVersions());
        $server->php_versions = ['8.6', '8.4'];
        $this->assertSame(['8.6'], $server->phpVersions());
        $this->assertSame('8.6', $server->defaultPhpVersion());
    }
}
