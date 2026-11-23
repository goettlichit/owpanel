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

    public function test_active_idle_websites_of_the_panel_server_get_site_create_without_a_certificate_request(): void
    {
        Queue::fake();
        $own = Server::factory()->create(['host' => '127.0.0.1']);
        $other = Server::factory()->create(['host' => '203.0.113.20']);
        $plain = Site::factory()->create(['server_id' => $own->id, 'domain' => 'shop.example.de', 'system_user' => 'ow38217', 'www' => false]);
        $www = Site::factory()->create(['server_id' => $own->id, 'domain' => 'example.de', 'system_user' => 'ow26069', 'www' => true]);
        Site::factory()->pending()->create(['server_id' => $own->id]);
        Site::factory()->create(['server_id' => $own->id, 'suspended_at' => now()]);
        $busy = Site::factory()->create(['server_id' => $own->id]);
        ServerTask::factory()->create(['server_id' => $own->id, 'site_id' => $busy->id, 'script' => ServerScript::SiteBackup->value, 'status' => TaskStatus::Running->value]);
        Site::factory()->create(['server_id' => $other->id]);

        $this->artisan('owpanel:refresh-sites')->expectsOutput('2 websites queued')->assertSuccessful();

        $tasks = ServerTask::where('script', ServerScript::SiteCreate->value)->get()->keyBy('site_id');
        $this->assertEqualsCanonicalizing([$plain->id, $www->id], $tasks->keys()->all());
        $this->assertSame(['--domain', 'shop.example.de', '--type', 'php', '--user', 'ow38217', '--existing'], $tasks[$plain->id]->arguments);
        $this->assertSame(['--domain', 'example.de', '--type', 'php', '--user', 'ow26069', '--www', '--existing'], $tasks[$www->id]->arguments);
        $this->assertNull($tasks[$plain->id]->user_id);
    }

    public function test_without_a_panel_server_nothing_happens(): void
    {
        $this->artisan('owpanel:refresh-sites')->expectsOutput('no panel server')->assertSuccessful();
        $this->assertSame(0, ServerTask::count());
    }
}
