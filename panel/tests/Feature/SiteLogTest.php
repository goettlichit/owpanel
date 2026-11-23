<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Jobs\ReadLog;
use App\Models\Customer;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * "Logs & tasks" of a website (E-086).
 */
class SiteLogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $renderDeferred = true;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function site(): Site
    {
        return Site::factory()->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);
    }

    /**
     * @param  list<string>  $lines
     */
    private function answer(array $lines): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([json_encode(['ok' => true, 'script' => 'site-logs', 'log' => 'error', 'size' => 2048, 'modified' => 1_762_858_800, 'lines' => $lines])."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_the_page_shows_the_chosen_log_escaped_and_the_tasks(): void
    {
        $runner = $this->answer(['2026/11/11 12:00:02 [error] FastCGI sent in stderr: "PHP message: PHP Warning: <script>alert(1)</script>"']);
        $site = $this->site();
        ServerTask::factory()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'script' => ServerScript::SiteCreate]);

        $this->actingAs($this->admin())->get("/sites/{$site->id}/logs?log=php&lines=500")
            ->assertOk()
            ->assertSee('PHP Warning: &lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Datei 2,0 KB');

        $this->actingAs($this->admin())->get("/sites/{$site->id}/tasks")
            ->assertOk()
            ->assertSee('Webseite anlegen');

        $call = $runner->calls[0];
        $this->assertSame(ServerScript::SiteLogs, $call->script);
        $this->assertSame(['--user', 'ow38217', '--log', 'php', '--lines', '500'], $call->arguments);
    }

    public function test_unknown_choices_fall_back_to_the_error_log_with_100_lines(): void
    {
        $runner = $this->answer([]);
        $site = $this->site();

        $this->actingAs($this->admin())->get("/sites/{$site->id}/logs?log=../../etc/shadow&lines=99999")
            ->assertOk()
            ->assertSee('Keine Fehler.');
        $this->assertSame(['--user', 'ow38217', '--log', 'error', '--lines', '100'], $runner->calls[0]->arguments);
    }

    public function test_the_log_is_read_by_the_query_worker_not_the_web_request(): void
    {
        Queue::fake();
        config(['owpanel.queries.wait_seconds' => 0.3]);
        $runner = $this->answer([]);
        $site = $this->site();

        $this->actingAs($this->admin())->get("/sites/{$site->id}/logs")
            ->assertOk()
            ->assertSee('Der Dienst für Abfragen hat nicht geantwortet.');

        Queue::assertPushedOn('queries', ReadLog::class, fn (ReadLog $job) => $job->target === ReadLog::SITE && $job->targetId === $site->id && $job->log === 'error');
        $this->assertInstanceOf(ShouldBeEncrypted::class, new ReadLog('a', ReadLog::SITE, 'b', 'error', 100));
        $this->assertSame([], $runner->calls);
    }

    public function test_a_site_that_is_not_set_up_has_no_logs_yet(): void
    {
        $runner = $this->answer([]);
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Pending])->save();

        $this->actingAs($this->admin())->get("/sites/{$site->id}/logs")->assertOk()->assertSee('Logs gibt es erst danach.');
        $this->assertSame([], $runner->calls);
    }

    public function test_the_overview_no_longer_lists_tasks_and_the_menu_links_the_logs(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin())->get("/sites/{$site->id}")
            ->assertOk()
            ->assertDontSee('Letzte Aufgaben')
            ->assertSee(route('sites.logs', $site), false)
            ->assertSee(route('sites.tasks', $site), false);
    }

    public function test_the_call_checks_its_input(): void
    {
        foreach ([['root', 'access', 100], ['ow38217', 'shadow', 100], ['ow38217', 'access', 0], ['ow38217', 'access', 1001]] as [$user, $log, $lines]) {
            try {
                ScriptCall::siteLogs($user, $log, $lines);
                $this->fail("accepted {$user} {$log} {$lines}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_customers_cannot_read_logs_even_of_their_own_website(): void
    {
        $runner = $this->answer(['secret']);
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $this->site();
        $site->forceFill(['customer_id' => $customer->id])->save();

        $this->actingAs($user)->get("/sites/{$site->id}/logs")->assertForbidden();
        $this->actingAs($user)->get("/sites/{$site->id}/tasks")->assertForbidden();
        $this->assertSame([], $runner->calls);
    }
}
