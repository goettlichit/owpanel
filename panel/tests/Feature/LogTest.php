<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Models\Customer;
use App\Models\Server;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use App\Support\LogTail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Logs of the servers in the server menu and of the panel under System (E-087).
 */
class LogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $renderDeferred = true;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @param  list<string>  $lines
     */
    private function answer(string $script, array $lines): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([json_encode(['ok' => true, 'script' => $script, 'lines' => $lines])."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_the_panel_log_is_read_from_its_file(): void
    {
        $path = storage_path('logs/laravel.log');
        $backup = is_file($path) ? file_get_contents($path) : null;
        File::put($path, "[2026-11-11 12:00:00] production.ERROR: alt\n[2026-11-11 12:01:00] production.ERROR: <b>neu</b>\n");

        try {
            $this->actingAs($this->admin())->get('/system/logs')
                ->assertOk()
                ->assertSee('Panel-Logs')
                ->assertSee('production.ERROR: &lt;b&gt;neu&lt;/b&gt;', false)
                ->assertSee('Aufgaben-Worker')
                ->assertSee('Abfrage-Worker');
        } finally {
            $backup === null ? File::delete($path) : File::put($path, $backup);
        }
    }

    public function test_a_server_log_is_read_through_server_logs(): void
    {
        $runner = $this->answer('server-logs', ['2026-11-11T12:00:00+01:00 web1 sshd[1]: Accepted publickey for owpanel-agent']);
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->get("/servers/{$server->id}/logs?log=auth&lines=500")
            ->assertOk()
            ->assertSee('Accepted publickey for owpanel-agent')
            ->assertSee('fail2ban');

        $this->assertSame(ServerScript::ServerLogs, $runner->calls[0]->script);
        $this->assertSame(['--log', 'auth', '--lines', '500'], $runner->calls[0]->arguments);
    }

    public function test_unknown_logs_fall_back_to_the_first(): void
    {
        $runner = $this->answer('server-logs', []);
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->get("/servers/{$server->id}/logs?log=/etc/shadow&lines=7")->assertOk();
        $this->assertSame(['--log', 'auth', '--lines', '100'], $runner->calls[0]->arguments);
    }

    public function test_the_server_log_call_takes_only_known_logs(): void
    {
        foreach ([['/etc/shadow', 100], ['auth', 0], ['auth', 1001]] as [$log, $lines]) {
            try {
                ScriptCall::serverLogs($log, $lines);
                $this->fail("accepted {$log}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_log_tail_reads_the_end_of_large_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log');
        $handle = fopen($path, 'w');
        for ($i = 1; $i <= 100000; $i++) {
            fwrite($handle, "Zeile {$i}\n");
        }
        fwrite($handle, "kaputt \xff\n");
        fclose($handle);

        $tail = LogTail::read($path, 3);
        unlink($path);

        $this->assertSame(['Zeile 99999', 'Zeile 100000', 'kaputt ?'], $tail['lines']);
        $this->assertSame(['lines' => [], 'size' => null, 'modified' => null], LogTail::read('/does/not/exist', 10));
    }

    public function test_logs_are_in_the_server_menu_and_under_system(): void
    {
        $server = Server::factory()->create();
        $this->answer('server-logs', []);

        $this->actingAs($this->admin())->get("/servers/{$server->id}")
            ->assertSee('href="'.url("/servers/{$server->id}/logs").'"', false)
            ->assertSee('href="'.url("/servers/{$server->id}/tasks").'"', false);
        $this->actingAs($this->admin())->withSession(['auth.password_confirmed_at' => time()])->get('/settings')
            ->assertSee('href="'.url('/system/logs').'"', false);
    }

    public function test_customers_cannot_see_logs(): void
    {
        $runner = $this->answer('server-logs', ['secret']);
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $server = Server::factory()->create();

        $this->actingAs($user)->get('/system/logs')->assertForbidden();
        $this->actingAs($user)->get("/servers/{$server->id}/logs?log=auth")->assertForbidden();
        $this->actingAs($user)->get("/servers/{$server->id}/tasks")->assertForbidden();
        $this->assertSame([], $runner->calls);
    }
}
