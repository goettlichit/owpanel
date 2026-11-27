<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Livewire\TaskWatcher;
use App\Models\Customer;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One way to follow server tasks (E-152): stay on the page, spinner at the
 * button, reload with a short message when the task is done.
 */
class TaskWatcherTest extends TestCase
{
    use RefreshDatabase;

    private function running(Site $site, User $user, ServerScript $script = ServerScript::SiteSuspend): ServerTask
    {
        return ServerTask::factory()->create([
            'server_id' => $site->server_id, 'site_id' => $site->id, 'user_id' => $user->id,
            'script' => $script, 'status' => TaskStatus::Running,
        ]);
    }

    public function test_pages_carry_the_watcher_and_forms_their_spinner_text(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create();
        $this->running($site, $admin);

        $this->actingAs($admin)->get("/sites/{$site->id}/suspension")->assertOk()
            ->assertSee('data-ow-task-watcher', false)
            ->assertSee('data-open="1"', false)
            ->assertSee('data-scripts="site-suspend"', false)
            ->assertSee('wire:poll.2s="check"', false)
            ->assertSee('„Webseite sperren“ läuft …');
        $this->actingAs($admin)->get("/sites/{$site->id}/cron")->assertOk()
            ->assertSee('data-ow-busy="Cronjobs werden übertragen …" data-ow-task="site-cron"', false);
    }

    public function test_a_finished_task_reloads_the_page_with_a_message(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create();
        $task = $this->running($site, $admin);

        $watcher = Livewire::actingAs($admin)->test(TaskWatcher::class, ['type' => 'site', 'subject' => $site->id]);
        $watcher->call('check')->assertNoRedirect();

        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $watcher->call('check')->assertRedirect();
        $this->assertSame('„Webseite sperren“ ist erledigt.', session('status'));
    }

    public function test_a_failed_task_links_administrators_to_its_log(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create();
        $task = $this->running($site, $admin);

        $watcher = Livewire::actingAs($admin)->test(TaskWatcher::class, ['type' => 'site', 'subject' => $site->id]);
        $task->forceFill(['status' => TaskStatus::Failed])->save();
        $watcher->call('check')->assertRedirect();

        $this->assertSame('„Webseite sperren“ ist fehlgeschlagen.', session('error'));
        $this->assertSame(route('tasks.show', $task), session('error_link'));
    }

    public function test_customers_get_no_link_and_see_only_their_own_websites(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $site = Site::factory()->for($customer)->create();
        $foreign = Site::factory()->for(Customer::factory()->create())->create();
        $task = $this->running($site, $user, ServerScript::SiteCron);

        $watcher = Livewire::actingAs($user)->test(TaskWatcher::class, ['type' => 'site', 'subject' => $site->id]);
        $task->forceFill(['status' => TaskStatus::Failed])->save();
        $watcher->call('check')->assertRedirect();
        $this->assertStringContainsString('Bitte versuchen Sie es erneut', session('error'));
        $this->assertNull(session('error_link'));

        Livewire::actingAs($user)->test(TaskWatcher::class, ['type' => 'site', 'subject' => $foreign->id])->assertForbidden();
        Livewire::actingAs($user)->test(TaskWatcher::class, ['type' => 'server', 'subject' => $site->server_id])->assertForbidden();
    }

    public function test_tasks_of_others_reload_without_a_message(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $other = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create();
        $task = $this->running($site, $other, ServerScript::SiteBackup);

        $watcher = Livewire::actingAs($admin)->test(TaskWatcher::class, ['type' => 'site', 'subject' => $site->id]);
        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $watcher->call('check')->assertRedirect();
        $this->assertNull(session('status'));
    }

    public function test_the_suspension_page_keeps_the_button_that_runs(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $site = Site::factory()->create();
        $site->forceFill(['suspended_at' => now(), 'suspended_by' => 'admin'])->save();
        $task = $this->running($site, $admin);

        // Suspending: the suspend button with its spinner text, not yet "Unsuspend".
        $this->actingAs($admin)->get("/sites/{$site->id}/suspension")->assertOk()
            ->assertSee('data-ow-task="site-suspend gateway-site" data-ow-busy="Webseite wird gesperrt …"', false)
            ->assertDontSee('data-ow-task="site-unsuspend', false);

        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $site->forceFill(['suspended_at' => null, 'suspended_by' => null])->save();
        $this->running($site, $admin, ServerScript::SiteUnsuspend);

        // Unsuspending: the unsuspend button, not yet the form to suspend.
        $this->actingAs($admin)->get("/sites/{$site->id}/suspension")->assertOk()
            ->assertSee('data-ow-task="site-unsuspend site-create gateway-site" data-ow-busy="Webseite wird entsperrt …"', false)
            ->assertDontSee('name="note"', false);
    }
}
