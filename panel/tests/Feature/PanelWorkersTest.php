<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Workers\PanelWorkers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * System -> Services: workers and scheduler of the panel (E-133).
 */
class PanelWorkersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function job(string $queue, ?int $reservedAt = null, int $createdAt = 0): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\RunServerScript']),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $createdAt ?: now()->getTimestamp(),
            'created_at' => $createdAt ?: now()->getTimestamp(),
        ]);
    }

    public function test_the_scheduler_notes_its_run_and_sends_one_heartbeat_per_queue(): void
    {
        Queue::fake();

        PanelWorkers::tick();

        $this->assertNotNull(PanelWorkers::lastBeat('scheduler'));
        Queue::assertPushedOn('default', QueueHeartbeat::class);
        Queue::assertPushedOn('queries', QueueHeartbeat::class);
        Queue::assertPushed(QueueHeartbeat::class, 2);
    }

    public function test_a_heartbeat_marks_the_worker_as_running(): void
    {
        $this->assertSame('unknown', PanelWorkers::worker('default')['state']);

        (new QueueHeartbeat('default'))->handle();
        $this->assertSame('running', PanelWorkers::worker('default')['state']);
        $this->assertSame('unknown', PanelWorkers::worker('queries')['state']);

        $this->travel(PanelWorkers::STALE_AFTER + 30)->seconds();
        $this->assertSame('unresponsive', PanelWorkers::worker('default')['state']);
    }

    public function test_a_long_job_shows_as_working_and_waiting_jobs_are_counted(): void
    {
        (new QueueHeartbeat('default'))->handle();
        $this->travel(10)->minutes();

        $this->job('default', reservedAt: now()->subMinutes(8)->getTimestamp());
        $this->job('default', createdAt: now()->subMinutes(5)->getTimestamp());
        $this->job('default');
        $this->job('queries');

        $worker = PanelWorkers::worker('default');
        $this->assertSame('busy', $worker['state']);
        $this->assertSame('RunServerScript', $worker['current']);
        $this->assertSame(2, $worker['waiting']);
        $this->assertSame(now()->subMinutes(5)->getTimestamp(), $worker['oldest_waiting']->getTimestamp());
    }

    public function test_an_admin_sees_the_page_and_pauses_and_resumes_a_queue(): void
    {
        $admin = $this->admin();
        PanelWorkers::beat('scheduler');
        (new QueueHeartbeat('default'))->handle();

        $this->actingAs($admin)->get('/system/workers')->assertOk()
            ->assertSee('Auftrags-Worker')->assertSee('Abfrage-Worker')->assertSee('Zeitplaner')
            ->assertSee('owpanel-queue.target')->assertSee('Prozesse')->assertSee('läuft')->assertDontSee('Angehalten:');

        $this->actingAs($admin)->from('/system/workers')->post('/system/workers/pause', ['queue' => 'default'])
            ->assertRedirect('/system/workers')->assertSessionHas('status', 'Auftrags-Worker ist angehalten.');
        $this->assertTrue(Queue::isPaused(config('queue.default'), 'default'));
        $this->assertFalse(PanelWorkers::isPaused('queries'));

        // Every page shows the banner while a queue is paused.
        $this->actingAs($admin)->get('/customers')->assertOk()->assertSee('Angehalten: Auftrags-Worker.');
        $this->assertSame('paused', PanelWorkers::worker('default')['state']);

        $this->actingAs($admin)->from('/system/workers')->post('/system/workers/resume', ['queue' => 'default'])
            ->assertSessionHas('status', 'Auftrags-Worker nimmt wieder Aufträge an.');
        $this->assertFalse(PanelWorkers::isPaused('default'));
        $this->actingAs($admin)->get('/customers')->assertOk()->assertDontSee('Angehalten:');

        $this->assertSame(['system.queue_paused', 'system.queue_resumed'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->filter(fn ($a) => str_starts_with($a, 'system.'))->values()->all());
        $this->assertSame('default', AuditLog::where('action', 'system.queue_paused')->sole()->context['queue']);

        $this->actingAs($admin)->post('/system/workers/pause', ['queue' => 'nonsense'])->assertSessionHasErrors('queue');
    }

    public function test_an_admin_restarts_the_workers(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/system/workers/restart')
            ->assertRedirect('/system/workers')->assertSessionHas('status');

        $this->assertNotNull(PanelWorkers::lastRestart());
        $this->assertIsInt(Cache::get('illuminate:queue:restart'));
        $this->assertSame(1, AuditLog::where('action', 'system.workers_restarted')->count());
    }

    public function test_customers_cannot_see_or_control_the_workers_and_see_no_banner(): void
    {
        PanelWorkers::pause('default');
        $customer = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $this->actingAs($customer)->get('/system/workers')->assertForbidden();
        $this->actingAs($customer)->post('/system/workers/restart')->assertForbidden();
        $this->actingAs($customer)->post('/system/workers/resume', ['queue' => 'default'])->assertForbidden();
        $this->actingAs($customer)->post('/system/workers/pause', ['queue' => 'queries'])->assertForbidden();

        $this->assertTrue(PanelWorkers::isPaused('default'));
        $this->assertFalse(PanelWorkers::isPaused('queries'));
        $this->assertNull(PanelWorkers::lastRestart());
        $this->actingAs($customer)->get('/account')->assertDontSee('Angehalten:');
    }
}
