<?php

namespace Tests\Feature;

use App\Console\Commands\CheckServers;
use App\Models\Server;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\SerializableClosure\SerializableClosure;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * The status check sends one closure per server to a child process (E-191).
 * The tests run the sync driver, so this checks the round trip through the
 * closure serializer that the process driver uses.
 */
class CheckServersTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_task_survives_serialization_and_checks_its_own_server(): void
    {
        $tasks = CheckServers::tasks(['01missing00000000000000001', '01missing00000000000000002']);

        $this->assertSame(['01missing00000000000000001', '01missing00000000000000002'], array_keys($tasks));
        foreach ($tasks as $id => $task) {
            // As ProcessDriver and InvokeSerializedClosureCommand do it.
            $payload = base64_encode(serialize(new SerializableClosure($task)));
            $closure = unserialize(base64_decode($payload));

            $this->assertSame("{$id}: gone", $this->app->call($closure));
        }
    }

    public function test_a_check_through_a_serialized_task_reaches_the_server(): void
    {
        $server = Server::factory()->create(['name' => 'web1']);
        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(['{"ok":false,"script":"server-status","error":"test_failed"}'."\n"], 1));
        $task = CheckServers::tasks([$server->id])[$server->id];
        $closure = unserialize(serialize(new SerializableClosure($task)));

        // The fake answers with an error: the check ran for this server.
        $this->assertStringStartsWith('web1: ', $this->app->call($closure));
        $this->assertNotNull($server->fresh()->check_error);
        $this->assertNotNull($server->fresh()->checked_at);
    }
}
