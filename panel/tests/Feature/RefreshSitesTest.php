<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The installer writes the configuration of all websites again (E-171).
 */
class RefreshSitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_idle_websites_get_backend_and_gateway_without_a_certificate_request(): void
    {
        Queue::fake();
        $own = Server::factory()->create(['host' => '127.0.0.1', 'hosts_panel' => true]);
        $other = Server::factory()->create(['host' => '203.0.113.20']);
        $plain = Site::factory()->create(['server_id' => $own->id, 'domain' => 'shop.example.de', 'system_user' => 'ow38217', 'www' => false, 'tls' => false]);
        $www = Site::factory()->create(['server_id' => $own->id, 'domain' => 'example.de', 'system_user' => 'ow26069', 'www' => true, 'tls' => true]);
        Site::factory()->pending()->create(['server_id' => $own->id]);
        $suspended = Site::factory()->create(['server_id' => $own->id, 'suspended_at' => now()]);
        $busy = Site::factory()->create(['server_id' => $own->id]);
        ServerTask::factory()->create(['server_id' => $own->id, 'site_id' => $busy->id, 'script' => ServerScript::SiteBackup->value, 'status' => TaskStatus::Running->value]);
        $remote = Site::factory()->create(['server_id' => $other->id, 'system_user' => 'ow41234']);

        $this->artisan('owpanel:refresh-sites')->expectsOutput('4 websites queued')->assertSuccessful();

        // The backends where the websites run (E-195); a suspended one rests.
        $backends = ServerTask::where('script', ServerScript::SiteCreate->value)->get()->keyBy('site_id');
        $this->assertEqualsCanonicalizing([$plain->id, $www->id, $remote->id], $backends->keys()->all());
        $this->assertSame(['--domain', 'shop.example.de', '--type', 'php', '--user', 'ow38217', '--existing'], $backends[$plain->id]->arguments);
        $this->assertSame(['--domain', 'example.de', '--type', 'php', '--user', 'ow26069', '--https', '--existing'], $backends[$www->id]->arguments);
        $this->assertSame($other->id, $backends[$remote->id]->server_id);
        $this->assertContains('203.0.113.20', $backends[$remote->id]->arguments);
        $this->assertNull($backends[$plain->id]->user_id);

        // All gateways on the panel server, each after its backend, without --tls.
        $gateways = ServerTask::where('script', ServerScript::GatewaySite->value)->get()->keyBy('site_id');
        $this->assertEqualsCanonicalizing([$plain->id, $www->id, $remote->id, $suspended->id], $gateways->keys()->all());
        $this->assertTrue($gateways->every(fn (ServerTask $task) => $task->server_id === $own->id && ! in_array('--tls', $task->arguments, true)));
        $this->assertSame($backends[$www->id]->id, $gateways[$www->id]->after_task_id);
        $this->assertContains('--www', $gateways[$www->id]->arguments);
        $this->assertContains('--suspended', $gateways[$suspended->id]->arguments);
        $this->assertNull($gateways[$suspended->id]->after_task_id);
        $this->assertContains('--backend-cert', $gateways[$remote->id]->arguments);
    }

    public function test_without_a_panel_server_nothing_happens(): void
    {
        $this->artisan('owpanel:refresh-sites')->expectsOutput('no panel server')->assertSuccessful();
        $this->assertSame(0, ServerTask::count());
    }
}
