<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Jobs\RunServerTask;
use App\Livewire\TaskBadge;
use App\Livewire\TaskLog;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use App\Servers\ScriptRunner;
use App\Servers\TaskOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

class ServerTaskTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function fake(FakeScriptRunner $runner): FakeScriptRunner
    {
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_a_click_starts_the_test_script_and_shows_status_and_log(): void
    {
        $runner = $this->fake(FakeScriptRunner::succeeding());
        $admin = $this->admin();
        $server = Server::factory()->create();

        $response = $this->actingAs($admin)->post("/servers/{$server->id}/test");

        $task = ServerTask::sole();
        $response->assertRedirect("/servers/{$server->id}");

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(['--steps', '5'], $runner->calls[0]->arguments);
        $this->assertSame($admin->id, $task->user_id);
        $this->assertSame(0, $task->exit_code);
        $this->assertSame('root', $task->result['user']);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->finished_at);

        $this->actingAs($admin)->get("/tasks/{$task->id}")
            ->assertOk()
            ->assertSee('Erfolgreich')
            ->assertSee('step 1 of 5')
            ->assertSee('test message on stderr')
            ->assertDontSee('wire:poll', false);
    }

    public function test_starting_a_task_is_recorded_in_the_audit_log(): void
    {
        $this->fake(FakeScriptRunner::succeeding());
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)->post("/servers/{$server->id}/test");

        $entry = AuditLog::where('action', 'server.task_started')->sole();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame(ServerTask::sole()->id, $entry->subject_id);
        $this->assertSame('panel-test', $entry->context['script']);
    }

    public function test_the_failure_option_ends_with_the_error_of_the_script(): void
    {
        $runner = $this->fake(new FakeScriptRunner([
            "step 1 of 5\n",
            "error: failure requested with --fail\n",
            '{"ok":false,"script":"panel-test","error":"test_failed"}'."\n",
        ], exitCode: 1));
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)->post("/servers/{$server->id}/test", ['fail' => '1']);

        $task = ServerTask::sole();
        $this->assertSame(['--steps', '5', '--fail'], $runner->calls[0]->arguments);
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('test_failed', $task->error);

        $this->actingAs($admin)->get("/tasks/{$task->id}")
            ->assertSee('Fehlgeschlagen')
            ->assertSee('Das Testskript wurde wie gewünscht mit einem Fehler beendet.');
    }

    public function test_connection_errors_fail_the_task_with_their_code(): void
    {
        $this->fake(new FakeScriptRunner(connectionError: 'host_key_mismatch'));
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)->post("/servers/{$server->id}/test");

        $task = ServerTask::sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('host_key_mismatch', $task->error);
        $this->assertNull($task->exit_code);

        $this->actingAs($admin)->get("/tasks/{$task->id}")
            ->assertSee('anderen Host-Schlüssel')
            ->assertSee('text-danger', false)
            ->assertDontSee('alert alert-danger', false);
    }

    public function test_unknown_error_codes_are_shown_as_code(): void
    {
        $task = ServerTask::factory()->failed('something_new')->create();

        $this->actingAs($this->admin())->get("/tasks/{$task->id}")
            ->assertSee('Die Aufgabe ist mit dem Fehlercode something_new fehlgeschlagen.');
    }

    public function test_the_web_request_only_queues_the_task(): void
    {
        Queue::fake();
        $runner = $this->fake(FakeScriptRunner::succeeding());
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/test");

        $this->assertSame(TaskStatus::Pending, ServerTask::sole()->status);
        $this->assertSame([], $runner->calls);
        Queue::assertPushed(RunServerTask::class, fn (RunServerTask $job) => $job->taskId === ServerTask::sole()->id);
    }

    public function test_a_task_runs_only_once(): void
    {
        $runner = $this->fake(FakeScriptRunner::succeeding());
        $task = ServerTask::factory()->create();

        (new RunServerTask($task->id))->handle($runner);
        (new RunServerTask($task->id))->handle($runner);

        $this->assertCount(1, $runner->calls);
    }

    public function test_a_running_task_is_not_started_again(): void
    {
        $runner = $this->fake(FakeScriptRunner::succeeding());
        $task = ServerTask::factory()->running()->create();

        (new RunServerTask($task->id))->handle($runner);

        $this->assertSame([], $runner->calls);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }

    public function test_a_crashed_job_marks_the_task_as_failed(): void
    {
        $task = ServerTask::factory()->running("step 1\n")->create();

        (new RunServerTask($task->id))->failed(new RuntimeException('worker died'));

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('worker_failed', $task->error);
        $this->assertSame("step 1\n", $task->output);
    }

    public function test_the_log_is_limited_and_kept_valid_utf8(): void
    {
        $task = ServerTask::factory()->running()->create();
        $output = new TaskOutput($task);

        // "ü" split across two chunks, as SSH may deliver it.
        $output->append("gr\xC3");
        $output->flush();
        $this->assertTrue(mb_check_encoding($task->fresh()->output, 'UTF-8'));
        $output->append("\xBCn\n");
        $output->flush();
        $this->assertSame("grün\n", $task->fresh()->output);

        $output->append(str_repeat('x', TaskOutput::MAX_BYTES));
        $output->append('never stored');
        $output->flush();

        $stored = $task->fresh()->output;
        $this->assertStringContainsString('[output truncated by the panel]', $stored);
        $this->assertStringNotContainsString('never stored', $stored);
        $this->assertLessThan(TaskOutput::MAX_BYTES + 100, strlen($stored));
    }

    public function test_the_log_is_escaped(): void
    {
        $task = ServerTask::factory()->running('<script>alert(1)</script>')->create();

        $this->actingAs($this->admin())->get("/tasks/{$task->id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_running_task_polls_for_updates(): void
    {
        $admin = $this->admin();
        $task = ServerTask::factory()->running("step 1 of 5\n")->create();

        Livewire::actingAs($admin)
            ->test(TaskLog::class, ['task' => $task])
            ->assertSee('Läuft')
            ->assertSee('step 1 of 5')
            ->assertSeeHtml('wire:poll.1s');

        $task->forceFill([
            'status' => TaskStatus::Succeeded,
            'output' => "step 1 of 5\nstep 2 of 5\n",
            'finished_at' => now(),
        ])->save();

        Livewire::actingAs($admin)
            ->test(TaskLog::class, ['task' => $task])
            ->assertSee('step 2 of 5')
            ->assertSee('Erfolgreich')
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_the_task_badge_polls_until_the_task_is_finished(): void
    {
        $admin = $this->admin();
        $task = ServerTask::factory()->create();

        $component = Livewire::actingAs($admin)
            ->test(TaskBadge::class, ['task' => $task, 'label' => 'Auf dem Server:'])
            ->assertSee('Wartend')
            ->assertSeeHtml('wire:poll.2s');

        $task->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();

        $component->call('$refresh')
            ->assertSee('Erfolgreich')
            ->assertDontSeeHtml('wire:poll');

        Livewire::actingAs(User::factory()->create())
            ->test(TaskBadge::class, ['task' => $task, 'label' => 'x'])
            ->assertForbidden();
    }

    public function test_a_pending_task_explains_that_it_waits_for_the_worker(): void
    {
        $task = ServerTask::factory()->create();

        $this->actingAs($this->admin())->get("/tasks/{$task->id}")
            ->assertSee('Wartend')
            ->assertSee('wartet auf den Queue-Worker');
    }

    public function test_the_task_list_of_a_server_links_to_the_tasks(): void
    {
        $admin = $this->admin();
        $task = ServerTask::factory()->create();

        $this->actingAs($admin)->get("/servers/{$task->server_id}/tasks")
            ->assertOk()
            ->assertSee('Testskript')
            ->assertSee("/tasks/{$task->id}")
            ->assertDontSee('<th scope="col">Server</th>', false);
        // The task page shows the menu of its server; there is no list of all tasks.
        $this->actingAs($admin)->get("/tasks/{$task->id}")
            ->assertSee('href="'.url("/servers/{$task->server_id}/logs").'"', false);
        $this->actingAs($admin)->get('/tasks')->assertNotFound();
    }

    public function test_customers_cannot_see_or_start_tasks(): void
    {
        $this->fake(FakeScriptRunner::succeeding());
        $customer = User::factory()->create();
        $server = Server::factory()->create();
        $task = ServerTask::factory()->for($server)->create();

        $this->actingAs($customer)->get("/tasks/{$task->id}")->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$server->id}/test")->assertForbidden();

        $this->assertSame(1, ServerTask::count());
    }

    public function test_customers_cannot_read_a_task_log_through_livewire(): void
    {
        $task = ServerTask::factory()->running('secret log')->create();

        Livewire::actingAs(User::factory()->create())
            ->test(TaskLog::class, ['task' => $task])
            ->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $server = Server::factory()->create();
        $task = ServerTask::factory()->for($server)->create();

        $this->get("/tasks/{$task->id}")->assertRedirect('/login');
        $this->post("/servers/{$server->id}/test")->assertRedirect('/login');
    }

    public function test_a_toast_appears_when_a_watched_task_finishes(): void
    {
        $admin = $this->admin();
        $task = ServerTask::factory()->running()->create();

        $component = Livewire::actingAs($admin)->test(TaskLog::class, ['task' => $task])
            ->assertNotDispatched('owpanel-toast');

        $task->forceFill(['status' => TaskStatus::Failed, 'error' => 'test_failed', 'finished_at' => now()])->save();

        $component->call('$refresh')
            ->assertDispatched('owpanel-toast', type: 'danger',
                message: 'Testskript ist fehlgeschlagen: Das Testskript wurde wie gewünscht mit einem Fehler beendet.');

        // Only once, not on every later update.
        $component->call('$refresh')->assertNotDispatched('owpanel-toast');
    }

    public function test_a_finished_task_shows_no_toast_when_opened_later(): void
    {
        $task = ServerTask::factory()->failed('test_failed')->create();

        Livewire::actingAs($this->admin())->test(TaskLog::class, ['task' => $task])
            ->assertNotDispatched('owpanel-toast');
    }
}
