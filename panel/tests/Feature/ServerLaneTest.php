<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\RunServerTask;
use App\Models\Server;
use App\Models\ServerTask;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerLane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ScriptedRunner;
use Tests\TestCase;

/**
 * One task at a time per server, several servers side by side (M5-1).
 */
class ServerLaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function pending(Server $server, ServerScript $script = ServerScript::PanelTest): ServerTask
    {
        return ServerTask::factory()->create(['server_id' => $server->id, 'script' => $script, 'arguments' => [], 'status' => TaskStatus::Pending]);
    }

    private function runTask(ServerTask $task, ?ScriptRunner $runner = null): ServerTask
    {
        (new RunServerTask($task->getKey()))->handle($runner ?? new ScriptedRunner(['panel-test' => []]));

        return $task->fresh();
    }

    public function test_a_busy_server_makes_the_next_task_wait_while_other_servers_run(): void
    {
        $one = Server::factory()->create();
        $two = Server::factory()->create();
        $running = ServerTask::factory()->create(['server_id' => $one->id, 'status' => TaskStatus::Running, 'started_at' => now()]);
        $one->forceFill(['busy_task_id' => $running->id])->save();

        $waiting = $this->runTask($this->pending($one));
        $this->assertSame(TaskStatus::Pending, $waiting->status);
        // It comes back as a new job a few seconds later.
        Queue::assertPushed(RunServerTask::class, fn (RunServerTask $job) => $job->taskId === $waiting->id && $job->delay !== null);

        $other = $this->runTask($this->pending($two));
        $this->assertSame(TaskStatus::Succeeded, $other->status);
        $this->assertNull($two->fresh()->busy_task_id);
        $this->assertSame($running->id, $one->fresh()->busy_task_id);
    }

    public function test_the_lane_is_free_again_after_a_task_and_held_while_it_runs(): void
    {
        $server = Server::factory()->create();
        $task = $this->pending($server);
        // A runner that notes who holds the lane while the script runs.
        $runner = new class($server) implements ScriptRunner
        {
            public ?string $seen = null;

            public function __construct(private Server $server) {}

            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $this->seen = $this->server->fresh()->busy_task_id;

                return ScriptResult::fromOutput('panel-test', json_encode(['ok' => true, 'script' => 'panel-test'])."\n", 0);
            }
        };

        $task = $this->runTask($task, $runner);

        $this->assertSame($task->id, $runner->seen);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertNull($server->fresh()->busy_task_id);
    }

    public function test_a_lane_whose_task_ended_is_taken_over(): void
    {
        $server = Server::factory()->create();
        $ended = ServerTask::factory()->failed('worker_failed')->create(['server_id' => $server->id]);
        $server->forceFill(['busy_task_id' => $ended->id])->save();

        $task = $this->runTask($this->pending($server));

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertNull($server->fresh()->busy_task_id);
    }

    public function test_a_second_job_for_a_running_task_leaves_its_lane_alone(): void
    {
        $server = Server::factory()->create();
        $running = ServerTask::factory()->create(['server_id' => $server->id, 'status' => TaskStatus::Running, 'started_at' => now()]);
        $server->forceFill(['busy_task_id' => $running->id])->save();

        $this->assertSame(ServerLane::GONE, ServerLane::start($running->id));
        $this->runTask($running);

        $this->assertSame($running->id, $server->fresh()->busy_task_id);
        $this->assertSame(TaskStatus::Running, $running->fresh()->status);
    }

    public function test_the_firewall_confirmation_does_not_wait(): void
    {
        $server = Server::factory()->create();
        $running = ServerTask::factory()->create(['server_id' => $server->id, 'status' => TaskStatus::Running, 'started_at' => now()]);
        $server->forceFill(['busy_task_id' => $running->id])->save();
        $confirm = ServerTask::start($server, ScriptCall::firewallConfirm(), null);

        $this->assertSame(ServerLane::STARTED, ServerLane::start($confirm->id));
        $this->assertSame($running->id, $server->fresh()->busy_task_id);
    }
}
