<?php

namespace Tests\Feature;

use App\Backups\StorageBoxUsage;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Notify\AlertChecker;
use App\Notify\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fill level of the Storage Boxes of the backups (E-160).
 */
class StorageBoxUsageTest extends TestCase
{
    use RefreshDatabase;

    private function box(int $used, int $total = 1_000_000_000_000): array
    {
        return ['storage_box' => ['id' => 42, 'name' => 'backups', 'username' => 'u123456', 'stats' => ['size' => $used, 'size_data' => $used, 'size_snapshots' => 0], 'storage_box_type' => ['name' => 'bx11', 'size' => $total]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString('token-for-tests-0123456789'));
        Server::factory()->create()->forceFill(['backup_box_id' => 42])->save();
        Server::factory()->create()->forceFill(['backup_box_id' => 42])->save();
    }

    public function test_the_level_is_read_once_per_box_and_raises_from_90_percent(): void
    {
        Queue::fake();
        Http::fake(['api.hetzner.com/v1/storage_boxes/42' => Http::sequence()
            ->push($this->box(950_000_000_000))
            ->push($this->box(880_000_000_000))
            ->push($this->box(800_000_000_000))]);

        $this->artisan('owpanel:storage-boxes')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(95.0, StorageBoxUsage::percent(StorageBoxUsage::all()[42]));
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen('storage_box_full:42'));

        // Between the thresholds nothing changes, below 85 % the all-clear.
        $this->artisan('owpanel:storage-boxes');
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen('storage_box_full:42'));
        $this->artisan('owpanel:storage-boxes');
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen('storage_box_full:42'));
    }

    public function test_a_failed_reading_keeps_the_last_one_and_unused_boxes_drop_out(): void
    {
        Http::fake(['api.hetzner.com/v1/storage_boxes/42' => Http::sequence()
            ->push($this->box(500_000_000_000))
            ->push(['error' => ['code' => 'unavailable']], 503)]);

        $this->artisan('owpanel:storage-boxes');
        $this->artisan('owpanel:storage-boxes');
        $this->assertSame(500_000_000_000, StorageBoxUsage::all()[42]['used']);

        Server::query()->update(['backup_box_id' => null]);
        $this->artisan('owpanel:storage-boxes');
        $this->assertSame([], StorageBoxUsage::all());
    }

    public function test_the_backup_page_shows_the_level(): void
    {
        Http::fake(['api.hetzner.com/v1/storage_boxes/42' => $this->box(250_000_000_000)]);
        $this->artisan('owpanel:storage-boxes');

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('settings.backup'))
            ->assertOk()
            ->assertSee(__('settings.backup_page.storage_box', ['name' => 'backups']));
    }
}
