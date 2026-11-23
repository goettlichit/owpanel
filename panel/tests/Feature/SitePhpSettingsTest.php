<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Sites\PhpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PHP settings of a website and their limits in packages (E-173).
 */
class SitePhpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @return array{0: Site, 1: User, 2: Package}
     */
    private function customerSite(): array
    {
        $package = Package::factory()->create(['php_memory_mb' => 512, 'php_upload_mb' => 128, 'php_time_s' => 300, 'php_processes' => 6]);
        $customer = Customer::factory()->create(['package_id' => $package->id]);
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $site = Site::factory()->for($customer)->for(Server::factory())->create(['domain' => 'shop.example.de', 'system_user' => 'ow38217', 'tls' => false, 'wants_tls' => false]);

        return [$site, $user, $package];
    }

    /**
     * @return list<string>
     */
    private static function settingArgs(int $memory, int $upload, int $time, int $processes, bool $errors = false): array
    {
        return ['--php-memory', (string) $memory, '--php-upload', (string) $upload, '--php-time', (string) $time,
            '--php-processes', (string) $processes, '--php-errors', $errors ? 'on' : 'off'];
    }

    public function test_customers_set_values_within_their_package(): void
    {
        Queue::fake();
        [$site, $user] = $this->customerSite();

        $this->actingAs($user)->get("/my/sites/{$site->id}/php")->assertOk()
            ->assertSee(__('sites.php_settings.title'))
            ->assertSee(__('sites.php_settings.range', ['min' => 32, 'max' => 512, 'unit' => 'MB']));

        // Above the package: refused, nothing changes.
        $this->actingAs($user)->post("/my/sites/{$site->id}/php/settings", ['memory' => 1024, 'upload' => 64, 'time' => 60])
            ->assertSessionHasErrors('memory');
        $this->assertSame(0, ServerTask::count());

        $this->actingAs($user)->post("/my/sites/{$site->id}/php/settings", ['memory' => 512, 'upload' => 100, 'time' => 120, 'errors' => '1'])
            ->assertRedirect();
        $site->refresh();
        $this->assertSame([512, 100, 120, true], [$site->php_memory_mb, $site->php_upload_mb, $site->php_time_s, $site->php_display_errors]);
        $task = ServerTask::where('script', ServerScript::SiteCreate->value)->sole();
        $this->assertSame(['--domain', 'shop.example.de', '--type', 'php', '--user', 'ow38217', ...self::settingArgs(512, 100, 120, 6, true)], $task->arguments);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.php_settings_changed']);
    }

    public function test_customers_cannot_change_foreign_websites_or_without_a_package(): void
    {
        Queue::fake();
        [, $user] = $this->customerSite();
        $foreign = Site::factory()->for(Customer::factory()->create(['package_id' => Package::factory()]))->create();
        $this->actingAs($user)->post("/my/sites/{$foreign->id}/php/settings", ['memory' => 256, 'upload' => 64, 'time' => 60])->assertForbidden();
        $this->actingAs($user)->post("/sites/{$foreign->id}/php/settings", ['memory' => 256, 'upload' => 64, 'time' => 60])->assertForbidden();

        $customer = Customer::factory()->create(['package_id' => null]);
        $without = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $site = Site::factory()->for($customer)->create();
        $this->actingAs($without)->post("/my/sites/{$site->id}/php/settings", ['memory' => 256, 'upload' => 64, 'time' => 60])
            ->assertSessionHas('error', __('sites.php_settings.no_package'));
        $this->assertSame(0, ServerTask::count());

        // Administrators can, within what the server accepts.
        $this->actingAs($this->admin())->post("/sites/{$site->id}/php/settings", ['memory' => 2048, 'upload' => 64, 'time' => 60])->assertRedirect();
        $this->assertSame(1, ServerTask::count());
    }

    public function test_a_smaller_package_cuts_the_settings_of_its_websites_at_once(): void
    {
        Queue::fake();
        [$site, , $package] = $this->customerSite();
        $site->forceFill(['php_memory_mb' => 512, 'php_time_s' => 300])->save();
        Site::factory()->for($site->customer)->create(['suspended_at' => now()]);
        $this->assertSame(['memory' => 512, 'upload' => 64, 'time' => 300, 'processes' => 6, 'errors' => false], PhpSettings::effective($site));

        $this->actingAs($this->admin())->put("/packages/{$package->id}", [
            'name' => $package->name,
            'php_memory_mb' => 256, 'php_upload_mb' => 128, 'php_time_s' => 120, 'php_processes' => 4,
        ])->assertRedirect();

        // Only the active website; the suspended one gets them with its next change.
        $task = ServerTask::where('script', ServerScript::SiteCreate->value)->sole();
        $this->assertSame($site->id, $task->site_id);
        $this->assertSame(['--domain', 'shop.example.de', '--type', 'php', '--user', 'ow38217', ...self::settingArgs(256, 64, 120, 4)], $task->arguments);
        // What the website chose stays; it applies again with a bigger package.
        $this->assertSame(512, $site->fresh()->php_memory_mb);
    }

    public function test_new_packages_start_with_sensible_php_limits(): void
    {
        $this->actingAs($this->admin())->get('/packages/create')->assertOk()
            ->assertSee('name="php_memory_mb" value="512"', false)
            ->assertSee('name="php_processes" value="10"', false);
    }

    public function test_websites_without_a_package_get_the_defaults(): void
    {
        $site = Site::factory()->create(['customer_id' => null]);
        $this->assertSame(['memory' => 256, 'upload' => 64, 'time' => 60, 'processes' => 10, 'errors' => false], PhpSettings::effective($site));
        $this->assertSame(4096, PhpSettings::bounds($site)['memory']['max']);
    }
}
