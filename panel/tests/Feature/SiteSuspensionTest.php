<?php

namespace Tests\Feature;

use App\Backups\Rebuild;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\Database;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Suspending and unsuspending websites (E-127).
 */
class SiteSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @return array{Site, User}
     */
    private function customerSite(string $domain = 'shop.example', string $user = 'ow38217'): array
    {
        $customer = Customer::factory()->create();
        User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);

        return [Site::factory()->for($customer)->create(['domain' => $domain, 'system_user' => $user]), User::where('customer_id', $customer->id)->sole()];
    }

    private function suspend(Site $site, string $note = 'Rechnung offen'): Site
    {
        $site->forceFill(['suspended_at' => now(), 'suspended_by' => 'admin', 'suspend_note' => $note])->save();

        return $site;
    }

    public function test_the_script_call_refuses_the_panel(): void
    {
        $this->assertSame(['--user', 'ow38217'], ScriptCall::siteSuspend('ow38217')->arguments);
        $this->assertSame(ServerScript::SiteUnsuspend, ScriptCall::siteSuspend('ow38217', false)->script);
        $this->expectException(InvalidArgumentException::class);
        ScriptCall::siteSuspend('ow10000');
    }

    public function test_an_admin_suspends_with_a_note_and_can_lock_the_access(): void
    {
        [$site, $user] = $this->customerSite();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/suspension")->assertOk()
            ->assertSee('Auch den Zugang des Kunden sperren');
        $this->actingAs($admin)->post("/sites/{$site->id}/suspension", ['note' => 'Rechnung 2026-117 offen', 'lock_access' => '1'])
            ->assertRedirect();

        $site->refresh();
        $this->assertTrue($site->isSuspended());
        $this->assertSame('admin', $site->suspended_by);
        $this->assertSame('Rechnung 2026-117 offen', $site->suspend_note);
        $this->assertTrue($user->fresh()->isLocked());
        // The gateway shows the suspension page first, then the server rests
        // PHP, cron and SFTP (E-195).
        $gateway = ServerTask::where('script', ServerScript::GatewaySite->value)->sole();
        $this->assertContains('--suspended', $gateway->arguments);
        $task = ServerTask::where('script', ServerScript::SiteSuspend->value)->sole();
        $this->assertSame(['--user', 'ow38217'], $task->arguments);
        $this->assertSame($gateway->id, $task->after_task_id);
        $this->assertTrue(AuditLog::where('action', 'site.suspended')->exists());
        $this->assertTrue(AuditLog::where('action', 'customer.access_locked')->exists());

        $this->actingAs($admin)->get("/sites/{$site->id}/suspension")->assertOk()
            ->assertSee('Rechnung 2026-117 offen')->assertSee('Entsperren');
    }

    public function test_unsuspending_brings_it_back_and_leaves_the_access_locked(): void
    {
        [$site, $user] = $this->customerSite();
        $this->suspend($site);
        $user->forceFill(['locked_at' => now()])->save();

        $this->actingAs($this->admin())->delete("/sites/{$site->id}/suspension")->assertRedirect();

        $site->refresh();
        $this->assertFalse($site->isSuspended());
        $this->assertNull($site->suspend_note);
        // The server first, then the backend again, then the open gateway.
        $this->assertSame(
            [ServerScript::SiteUnsuspend, ServerScript::SiteCreate, ServerScript::GatewaySite],
            ServerTask::orderBy('created_at')->orderBy('id')->pluck('script')->all(),
        );
        $this->assertNotContains('--suspended', ServerTask::where('script', ServerScript::GatewaySite->value)->sole()->arguments);
        $this->assertTrue($user->fresh()->isLocked());
        $this->assertTrue(AuditLog::where('action', 'site.unsuspended')->exists());
    }

    public function test_nothing_starts_while_a_task_runs(): void
    {
        [$site] = $this->customerSite();
        ServerTask::factory()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'status' => TaskStatus::Running]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/suspension")->assertSessionHas('error');
        $this->assertFalse($site->fresh()->isSuspended());
    }

    public function test_customers_cannot_suspend_and_see_no_reason(): void
    {
        [$site, $user] = $this->customerSite();
        [$foreign] = $this->customerSite('fremd.example', 'ow38218');
        $this->suspend($site, 'Rechnung 2026-117 offen');

        $this->actingAs($user)->get("/sites/{$site->id}/suspension")->assertForbidden();
        // Refused either by the guard of suspended websites or by the role.
        $this->actingAs($user)->post("/sites/{$site->id}/suspension")->assertRedirect();
        $this->actingAs($user)->delete("/sites/{$site->id}/suspension")->assertRedirect();
        $this->actingAs($user)->post("/sites/{$foreign->id}/suspension")->assertForbidden();
        $this->assertTrue($site->fresh()->isSuspended());
        $this->assertFalse($foreign->fresh()->isSuspended());
        $this->assertSame(0, ServerTask::count());

        $this->actingAs($user)->get('/my/sites')->assertOk()->assertSee('Gesperrt');
        $this->actingAs($user)->get("/my/sites/{$site->id}")->assertOk()
            ->assertSee('Diese Webseite ist gesperrt.')
            ->assertSee('Bitte wenden Sie sich an uns.')
            ->assertSee('<fieldset disabled class="ow-suspended">', false)
            ->assertDontSee('Rechnung 2026-117 offen')
            ->assertDontSee('Administration');
        $this->actingAs($user)->get("/my/sites/{$foreign->id}")->assertForbidden();
    }

    public function test_a_suspended_website_takes_no_changes_from_its_customer(): void
    {
        [$site, $user] = $this->customerSite();
        $this->suspend($site);
        $database = Database::factory()->create(['site_id' => $site->id, 'server_id' => $site->server_id, 'name' => 'ow38217_shop']);
        $cron = ['schedule' => '0 3 * * *', 'command' => 'php artisan schedule:run', 'description' => ''];

        foreach ([
            ['post', "/my/sites/{$site->id}/cron", $cron],
            ['post', "/my/sites/{$site->id}/sftp/toggle", []],
            ['post', "/my/sites/{$site->id}/www", ['www' => '1']],
            ['post', "/my/sites/{$site->id}/php", ['php' => '8.6']],
            ['post', "/my/sites/{$site->id}/backups", []],
            ['post', "/my/sites/{$site->id}/databases", ['suffix' => 'blog']],
            ['delete', "/my/databases/{$database->id}", []],
            ['post', "/my/databases/{$database->id}/export", []],
        ] as [$method, $url, $data]) {
            $this->actingAs($user)->{$method}($url, $data)
                ->assertSessionHas('error', 'Die Webseite ist gesperrt. Änderungen sind erst nach dem Entsperren möglich.');
        }
        $this->assertSame(0, ServerTask::count());
        $this->assertSame(0, CronJob::count());
        $this->assertTrue($database->fresh()->exists);
    }

    public function test_administrators_may_only_unsuspend_delete_back_up_and_restore(): void
    {
        [$site] = $this->customerSite();
        $this->suspend($site);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/toggle")
            ->assertSessionHas('error', 'Die Webseite ist gesperrt. Änderungen sind erst nach dem Entsperren möglich.');
        $this->actingAs($admin)->post("/sites/{$site->id}/certificate")->assertSessionHas('error');
        $this->assertSame(0, ServerTask::count());

        $this->actingAs($admin)->get("/sites/{$site->id}")->assertOk()
            ->assertSee('Diese Webseite ist gesperrt, durch Administration seit')
            ->assertSee('Sperre verwalten')
            ->assertSee('<fieldset disabled class="ow-suspended">', false);
        $this->actingAs($admin)->get("/sites/{$site->id}/suspension")->assertOk()
            ->assertDontSee('<fieldset disabled', false);
        $this->actingAs($admin)->get("/sites/{$site->id}/delete")->assertOk()
            ->assertDontSee('<fieldset disabled', false);
        $this->actingAs($admin)->get('/sites')->assertOk()->assertSee('Gesperrt');
        // Every list and overview shows the suspension instead of "active".
        $this->actingAs($admin)->get("/customers/{$site->customer_id}")->assertOk()
            ->assertSeeInOrder(['shop.example', 'Gesperrt'])->assertDontSee('>Aktiv<', false);
        $this->actingAs($admin)->get("/sites/{$site->id}")->assertOk()->assertSee('Gesperrt');

        $this->actingAs($admin)->delete("/sites/{$site->id}", ['confirm_domain' => 'shop.example'])
            ->assertSessionMissing('error');
    }

    public function test_a_rebuilt_website_is_suspended_again(): void
    {
        [$site] = $this->customerSite();
        $this->suspend($site);

        Rebuild::site($site, ScriptCall::LATEST, $this->admin());

        $this->assertSame(ServerScript::SiteSuspend, ServerTask::orderByDesc('id')->first()->script);
    }
}
