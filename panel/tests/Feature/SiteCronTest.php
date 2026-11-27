<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Cron jobs of websites (E-088).
 */
class SiteCronTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function site(): Site
    {
        return Site::factory()->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);
    }

    private function runner(): FakeScriptRunner
    {
        $runner = new FakeScriptRunner(['{"ok":true,"script":"site-cron","jobs":1}'."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_a_new_job_is_stored_and_sent_to_the_server(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/cron")->assertOk()->assertSee('Noch keine Cronjobs.');

        $this->actingAs($admin)->post("/sites/{$site->id}/cron", [
            'schedule' => ' */5  *  * * * ', 'command' => ' php artisan schedule:run ', 'description' => 'Laravel',
        ])->assertRedirect("/sites/{$site->id}/cron")->assertSessionHas('status');

        $job = CronJob::sole();
        $this->assertSame(['*/5 * * * *', 'php artisan schedule:run', 'Laravel', true], [$job->schedule, $job->command, $job->description, $job->enabled]);

        $task = ServerTask::sole();
        $this->assertSame(ServerScript::SiteCron, $task->script);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(['--user', 'ow38217'], $runner->calls[0]->arguments);
        $this->assertSame("*/5 * * * *\tphp artisan schedule:run\n", $runner->calls[0]->stdin);

        $this->actingAs($admin)->get("/sites/{$site->id}/cron")
            ->assertSee('php artisan schedule:run')
            ->assertSee('alle 5 Minuten')
            // Only a failed transfer shows a badge; a running one shows at its button (E-152).
            ->assertDontSee('Erfolgreich');
    }

    public function test_paused_jobs_stay_in_the_panel_but_not_on_the_server(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $keep = $site->cronJobs()->create(['schedule' => '0 3 * * *', 'command' => 'php backup.php']);
        $pause = $site->cronJobs()->create(['schedule' => '* * * * *', 'command' => 'php noisy.php']);

        $this->actingAs($this->admin())->put("/sites/{$site->id}/cron/{$pause->id}", ['toggle' => '1'])->assertRedirect();

        $this->assertFalse($pause->fresh()->enabled);
        $this->assertSame("0 3 * * *\tphp backup.php\n", $runner->calls[0]->stdin);
        $this->assertTrue($keep->fresh()->enabled);
    }

    public function test_jobs_can_be_edited_and_deleted(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $job = $site->cronJobs()->create(['schedule' => '0 3 * * *', 'command' => 'php a.php']);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/sites/{$site->id}/cron/{$job->id}", ['edit_schedule' => '30 4 * * 1-5', 'edit_command' => 'php b.php', 'edit_description' => ''])->assertRedirect();
        $this->assertSame(['30 4 * * 1-5', 'php b.php', null], [$job->fresh()->schedule, $job->fresh()->command, $job->fresh()->description]);

        $this->actingAs($admin)->delete("/sites/{$site->id}/cron/{$job->id}")->assertRedirect();
        $this->assertSame(0, CronJob::count());
        $this->assertSame('', $runner->calls[1]->stdin);
    }

    public function test_schedules_and_commands_are_checked(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $admin = $this->admin();

        foreach (['* * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '* * * * 8', '5-1 * * * *', '*/0 * * * *', '@reboot', 'mon * * * *', '$(id) * * * *'] as $schedule) {
            $this->actingAs($admin)->post("/sites/{$site->id}/cron", ['schedule' => $schedule, 'command' => 'date'])
                ->assertSessionHasErrors('schedule');
        }
        foreach (["date\nrm -rf /", "date\rid", '   ', str_repeat('x', 1001)] as $command) {
            $this->actingAs($admin)->post("/sites/{$site->id}/cron", ['schedule' => '* * * * *', 'command' => $command])
                ->assertSessionHasErrors('command');
        }

        $this->assertSame(0, CronJob::count());
        $this->assertSame([], $runner->calls);
        $this->assertTrue(CronJob::validSchedule('0,30 8-18/2 1 */3 0-7'));
    }

    public function test_jobs_of_a_site_that_is_not_set_up_wait_for_it(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Pending])->save();

        $this->actingAs($this->admin())->post("/sites/{$site->id}/cron", ['schedule' => '* * * * *', 'command' => 'date'])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'danach übertragen'));
        $this->assertSame([], $runner->calls);

        // When the gateway after site-create succeeds (E-195), the waiting jobs are sent.
        $create = ServerTask::factory()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'script' => ServerScript::GatewaySite, 'status' => TaskStatus::Succeeded, 'result' => ['ok' => true]]);
        $site->taskFinished($create);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        $this->assertSame(1, ServerTask::where('script', ServerScript::SiteCron->value)->count());
    }

    public function test_a_job_of_another_site_cannot_be_changed_through_this_site(): void
    {
        $this->runner();
        $site = $this->site();
        $other = Site::factory()->create();
        $job = $other->cronJobs()->create(['schedule' => '* * * * *', 'command' => 'date']);

        $this->actingAs($this->admin())->delete("/sites/{$site->id}/cron/{$job->id}")->assertNotFound();
        $this->assertNotNull($job->fresh());
    }

    public function test_the_call_checks_every_job(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScriptCall::siteCron('ow38217', [['schedule' => '* * * * *', 'command' => "date\nid"]]);
    }

    public function test_customers_cannot_manage_cron_jobs(): void
    {
        $runner = $this->runner();
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $this->site();
        $site->forceFill(['customer_id' => $customer->id])->save();
        $job = $site->cronJobs()->create(['schedule' => '* * * * *', 'command' => 'date']);

        $this->actingAs($user)->get("/sites/{$site->id}/cron")->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/cron", ['schedule' => '* * * * *', 'command' => 'id'])->assertForbidden();
        $this->actingAs($user)->put("/sites/{$site->id}/cron/{$job->id}", ['toggle' => '1'])->assertForbidden();
        $this->actingAs($user)->delete("/sites/{$site->id}/cron/{$job->id}")->assertForbidden();

        $this->assertSame([], $runner->calls);
        $this->assertTrue($job->fresh()->enabled);
    }
}
