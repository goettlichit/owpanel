<?php

namespace Tests\Feature;

use App\Backups\Rebuild;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Sites\WebserverRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Own nginx rules of a website (E-184): headers and blocked paths, for
 * administrators only.
 */
class SiteWebserverTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function site(): Site
    {
        $customer = Customer::factory()->create(['package_id' => Package::factory()]);

        return Site::factory()->for($customer)->for(Server::factory())->create(['domain' => 'shop.example.de', 'system_user' => 'ow38217', 'tls' => true, 'wants_tls' => true]);
    }

    public function test_administrators_save_headers_and_paths(): void
    {
        Queue::fake();
        $site = $this->site();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/webserver")->assertOk()
            ->assertSee(__('sites.webserver.headers'))
            ->assertSee(__('sites.menu.webserver'));

        $this->actingAs($admin)->put("/sites/{$site->id}/webserver", [
            'headers' => "Strict-Transport-Security: max-age=31536000; includeSubDomains; preload\r\n\r\nContent-Security-Policy-Report-Only: default-src 'self'; img-src 'self' data:",
            'paths' => "/vendor/\n  /composer.json  \n/support/ticket-errors.log",
        ])->assertRedirect();

        $site->refresh();
        $this->assertSame([
            ['name' => 'Strict-Transport-Security', 'value' => 'max-age=31536000; includeSubDomains; preload'],
            ['name' => 'Content-Security-Policy-Report-Only', 'value' => "default-src 'self'; img-src 'self' data:"],
        ], $site->nginx_headers);
        $this->assertSame(['/vendor/', '/composer.json', '/support/ticket-errors.log'], $site->nginx_blocked_paths);

        $task = ServerTask::where('script', ServerScript::SiteNginx->value)->sole();
        $this->assertSame(['--user', 'ow38217'], $task->arguments);
        // The rules are read when the task runs.
        $this->assertSame(
            "header\tStrict-Transport-Security\tmax-age=31536000; includeSubDomains; preload\n"
            ."header\tContent-Security-Policy-Report-Only\tdefault-src 'self'; img-src 'self' data:\n"
            ."path\t/vendor/\npath\t/composer.json\npath\t/support/ticket-errors.log\n",
            $task->call()->stdin,
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.webserver_changed']);

        // The page shows them again as text; saving the same starts nothing.
        $this->actingAs($admin)->get("/sites/{$site->id}/webserver")
            ->assertSee('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload')
            ->assertSee('/support/ticket-errors.log');
        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $this->actingAs($admin)->put("/sites/{$site->id}/webserver", [
            'headers' => WebserverRules::headersText($site),
            'paths' => WebserverRules::pathsText($site),
        ]);
        $this->assertSame(1, ServerTask::count());
    }

    public function test_the_page_shows_blue_green_or_orange(): void
    {
        Queue::fake();
        $site = $this->site();
        $admin = $this->admin();
        $page = fn () => $this->actingAs($admin)->get("/sites/{$site->id}/webserver");

        // No rules yet: blue.
        $page()->assertSee('bg-blue-lt', false)->assertSee(__('sites.webserver.state.none'));

        $site->forceFill(['nginx_blocked_paths' => ['/vendor/']])->save();
        $task = ServerTask::start($site->server, ScriptCall::siteNginx('ow38217', [], []), $admin, $site);
        // While the transfer runs, its badge updates itself.
        $page()->assertDontSee(__('sites.webserver.state.applied'))->assertDontSee(__('sites.webserver.state.none'));

        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $page()->assertSee('bg-green-lt', false)->assertSee(__('sites.webserver.state.applied'));

        $task->forceFill(['status' => TaskStatus::Failed])->save();
        $page()->assertSee('bg-orange-lt', false)->assertSee(__('sites.webserver.state.failed'))
            ->assertSee(route('tasks.show', $task));

        // Without a task (removed after 90 days) the rules count as applied.
        $task->delete();
        $page()->assertSee(__('sites.webserver.state.applied'));
    }

    public function test_empty_lists_remove_all_rules(): void
    {
        Queue::fake();
        $site = $this->site();
        $site->forceFill(['nginx_headers' => [['name' => 'X-Test', 'value' => 'a']], 'nginx_blocked_paths' => ['/vendor/']])->save();

        $this->actingAs($this->admin())->put("/sites/{$site->id}/webserver", ['headers' => '', 'paths' => ''])->assertRedirect();

        $site->refresh();
        $this->assertNull($site->nginx_headers);
        $this->assertNull($site->nginx_blocked_paths);
        $this->assertSame('', ServerTask::where('script', ServerScript::SiteNginx->value)->sole()->call()->stdin);
    }

    public function test_invalid_rules_are_refused_with_their_line(): void
    {
        Queue::fake();
        // More attempts than the route allows per minute.
        $this->withoutMiddleware(ThrottleRequests::class);
        $site = $this->site();
        $admin = $this->admin();

        $cases = [
            ['headers' => 'X-Frame-Options: DENY'],
            ['headers' => 'No colon here'],
            ['headers' => 'X-Test: $host'],
            ['headers' => 'X-Test: a"b'],
            ['headers' => 'X Test: a'],
            ['headers' => "X-Test: a\nx-test: b"],
            ['paths' => 'vendor/'],
            ['paths' => '/'],
            ['paths' => '/.git/'],
            ['paths' => '/a b'],
            ['paths' => '/a;b'],
            ['paths' => "/vendor/\n/vendor/"],
        ];
        foreach ($cases as $input) {
            $this->actingAs($admin)->put("/sites/{$site->id}/webserver", $input)
                ->assertSessionHasErrors(array_keys($input)[0]);
        }
        $this->actingAs($admin)->put("/sites/{$site->id}/webserver", ['headers' => "X-A: 1\nX-Frame-Options: DENY"])
            ->assertSessionHasErrors(['headers' => __('sites.webserver.errors.header_reserved', ['line' => 2, 'name' => 'X-Frame-Options'])]);

        $this->assertSame(0, ServerTask::count());
        $this->assertNull($site->refresh()->nginx_headers);
    }

    public function test_customers_cannot_see_or_change_the_rules(): void
    {
        Queue::fake();
        $site = $this->site();
        $customer = User::factory()->create(['customer_id' => $site->customer_id, 'password_change_required' => false]);

        $this->actingAs($customer)->get("/sites/{$site->id}/webserver")->assertForbidden();
        $this->actingAs($customer)->put("/sites/{$site->id}/webserver", ['paths' => '/vendor/'])->assertForbidden();
        $this->actingAs($customer)->get("/my/sites/{$site->id}")->assertOk()->assertDontSee(__('sites.menu.webserver'));
        $this->assertNull($site->refresh()->nginx_blocked_paths);
        $this->assertSame(0, ServerTask::count());
    }

    public function test_a_busy_or_suspended_website_keeps_its_rules(): void
    {
        Queue::fake();
        $site = $this->site();
        $admin = $this->admin();
        ServerTask::start($site->server, ScriptCall::siteCron('ow38217', []), $admin, $site);

        $this->actingAs($admin)->put("/sites/{$site->id}/webserver", ['paths' => '/vendor/'])
            ->assertSessionHas('error', __('sites.webserver.busy'));
        $this->assertNull($site->refresh()->nginx_blocked_paths);

        ServerTask::query()->delete();
        $site->forceFill(['suspended_at' => now(), 'suspended_by' => 'admin'])->save();
        $this->actingAs($admin)->put("/sites/{$site->id}/webserver", ['paths' => '/vendor/']);
        $this->assertNull($site->refresh()->nginx_blocked_paths);
        $this->assertSame(0, ServerTask::count());
    }

    public function test_a_rebuild_writes_the_rules_after_the_website(): void
    {
        Queue::fake();
        $site = $this->site();
        $site->forceFill(['nginx_blocked_paths' => ['/vendor/']])->save();

        $first = Rebuild::site($site, ScriptCall::LATEST, $this->admin());

        $rules = ServerTask::where('script', ServerScript::SiteNginx->value)->sole();
        $this->assertSame($first->id, $rules->after_task_id);
        $this->assertSame("path\t/vendor/\n", $rules->call()->stdin);
    }

    public function test_the_script_call_refuses_what_the_server_would_refuse(): void
    {
        $this->assertSame("header\tX-Test\ta\npath\t/vendor/\n", ScriptCall::siteNginx('ow38217', [['name' => 'X-Test', 'value' => 'a']], ['/vendor/'])->stdin);

        foreach ([
            fn () => ScriptCall::siteNginx('ow38217', [['name' => 'Set-Cookie', 'value' => 'a']], []),
            fn () => ScriptCall::siteNginx('ow38217', [['name' => 'X-Test', 'value' => "a\nb"]], []),
            fn () => ScriptCall::siteNginx('ow38217', [], ['/../etc/']),
            fn () => ScriptCall::siteNginx('root', [], []),
        ] as $call) {
            try {
                $call();
                $this->fail('accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
