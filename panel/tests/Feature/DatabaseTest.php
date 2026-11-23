<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Database;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function runner(?FakeScriptRunner $runner = null): FakeScriptRunner
    {
        $runner ??= new FakeScriptRunner(['database and user are ready'."\n", '{"ok":true,"script":"db-create"}'."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function failing(string $error): FakeScriptRunner
    {
        return $this->runner(new FakeScriptRunner(['{"ok":false,"script":"db-create","error":"'.$error.'"}'."\n"], exitCode: 1));
    }

    private function site(): Site
    {
        return Site::factory()->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);
    }

    public function test_creating_a_database_sends_the_password_on_stdin_only(): void
    {
        $runner = $this->runner();
        $admin = $this->admin();
        $site = $this->site();

        $this->actingAs($admin)->get("/sites/{$site->id}/databases")->assertOk()->assertSee('ow38217_');

        $response = $this->actingAs($admin)->post("/sites/{$site->id}/databases", ['suffix' => 'shop']);

        $database = Database::sole();
        $task = ServerTask::sole();
        // The page stays where it is and waits for the task (E-152).
        $response->assertRedirect("/sites/{$database->site_id}/databases")->assertSessionMissing('status');

        $this->assertSame('ow38217_shop', $database->name);
        $this->assertSame($site->server_id, $database->server_id);
        $this->assertSame(SiteStatus::Active, $database->status);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $database->password);

        // The password reaches the script on stdin, nowhere else.
        $this->assertSame(ServerScript::DbCreate, $runner->calls[0]->script);
        $this->assertSame(['--name', 'ow38217_shop'], $runner->calls[0]->arguments);
        $this->assertSame($database->password."\n", $runner->calls[0]->stdin);
        $this->assertStringNotContainsString($database->password, $runner->calls[0]->commandLine());

        $this->assertSame($database->id, $task->database_id);
        $this->assertSame($site->id, $task->site_id);
        $this->assertStringNotContainsString($database->password, json_encode($task->getAttributes()));
        $this->assertStringNotContainsString($database->password, AuditLog::pluck('context')->toJson());

        // Stored encrypted, not in plain text.
        $raw = DB::table('databases')->value('password');
        $this->assertStringNotContainsString($database->password, $raw);
        $this->assertStringNotContainsString($database->password, json_encode($database->toArray()));

        $this->assertSame(['database.created', 'server.task_started'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_the_detail_page_hides_the_password_until_confirmed(): void
    {
        $admin = $this->admin();
        $database = Database::factory()->create(['site_id' => $this->site()->id, 'name' => 'ow38217_shop']);

        $this->actingAs($admin)->get("/databases/{$database->id}")
            ->assertOk()
            ->assertSee('ow38217_shop')
            ->assertSee('localhost')
            ->assertSee('3306')
            ->assertDontSee($database->password)
            ->assertSee(route('databases.reveal', $database), false);

        $this->assertSame(0, AuditLog::where('action', 'database.password_revealed')->count());

        $this->actingAs($admin)
            ->get("/databases/{$database->id}/password")
            ->assertOk()
            ->assertSee($database->password);
        $this->assertSame(1, AuditLog::where('action', 'database.password_revealed')->count());
    }

    public function test_a_new_password_replaces_the_old_one_only_after_success(): void
    {
        $admin = $this->admin();
        $database = Database::factory()->create(['site_id' => $this->site()->id, 'name' => 'ow38217_shop']);
        $old = $database->password;

        $this->failing('database_create_failed');
        $this->actingAs($admin)->post("/databases/{$database->id}/password");

        $database->refresh();
        $this->assertSame($old, $database->password);
        $this->assertNull($database->new_password);
        $this->assertSame(SiteStatus::Active, $database->status);

        $runner = $this->runner();
        $this->actingAs($admin)->post("/databases/{$database->id}/password")
            ->assertRedirect("/databases/{$database->id}");

        $database->refresh();
        $this->assertNotSame($old, $database->password);
        $this->assertSame($database->password."\n", $runner->calls[0]->stdin);
        $this->assertNull($database->new_password);
        $this->assertSame(2, AuditLog::where('action', 'database.password_changed')->count());
    }

    public function test_a_failed_creation_can_be_retried_with_the_same_password(): void
    {
        $admin = $this->admin();
        $site = $this->site();
        $this->failing('mariadb_unavailable');

        $this->actingAs($admin)->post("/sites/{$site->id}/databases", ['suffix' => 'shop']);

        $database = Database::sole();
        $this->assertSame(SiteStatus::Failed, $database->status);
        $this->actingAs($admin)->get("/databases/{$database->id}")
            ->assertSee('Das Anlegen ist fehlgeschlagen: MariaDB ist auf dem Server nicht installiert oder läuft nicht.')
            ->assertSee('Erneut anlegen');

        $runner = $this->runner();
        $this->actingAs($admin)->post("/databases/{$database->id}/retry");

        $this->assertSame($database->password."\n", $runner->calls[0]->stdin);
        $this->assertSame(SiteStatus::Active, $database->fresh()->status);
    }

    public function test_deleting_needs_the_name_and_removes_the_database(): void
    {
        $admin = $this->admin();
        $database = Database::factory()->create(['site_id' => $this->site()->id, 'name' => 'ow38217_shop']);
        $runner = $this->runner(new FakeScriptRunner(['{"ok":true,"script":"db-delete"}'."\n"]));

        $this->actingAs($admin)->delete("/databases/{$database->id}", ['confirm_name' => 'ow38217_blog'])
            ->assertSessionHasErrors(['confirm_name' => 'Der Name stimmt nicht mit der Datenbank überein.']);
        $this->assertSame([], $runner->calls);

        $this->actingAs($admin)->delete("/databases/{$database->id}", ['confirm_name' => 'ow38217_shop']);

        $this->assertSame(ServerScript::DbDelete, $runner->calls[0]->script);
        $this->assertSame(['--name', 'ow38217_shop'], $runner->calls[0]->arguments);
        $this->assertNull($runner->calls[0]->stdin);
        $this->assertNull(Database::find($database->id));
        $this->assertSame(TaskStatus::Succeeded, ServerTask::sole()->status);
        $this->assertSame('ow38217_shop', AuditLog::where('action', 'database.deleted')->sole()->context['database']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSuffixes(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Shop'],
            'dash' => ['my-shop'],
            'leading underscore' => ['_shop'],
            'too long' => ['abcdefghijklmnopq'],
            'quote' => ["shop'"],
            'backtick' => ['shop`'],
            'space' => ['my shop'],
        ];
    }

    #[DataProvider('invalidSuffixes')]
    public function test_invalid_names_are_rejected(string $suffix): void
    {
        $runner = $this->runner();
        $site = $this->site();

        $this->actingAs($this->admin())->post("/sites/{$site->id}/databases", ['suffix' => $suffix])
            ->assertSessionHasErrors('suffix');

        $this->assertSame(0, Database::count());
        $this->assertSame([], $runner->calls);
    }

    public function test_names_are_unique_and_need_an_active_website(): void
    {
        $this->runner();
        $admin = $this->admin();
        $site = $this->site();
        Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);

        $this->actingAs($admin)->post("/sites/{$site->id}/databases", ['suffix' => 'shop'])
            ->assertSessionHasErrors(['suffix' => 'Diese Webseite hat schon eine Datenbank mit diesem Namen.']);

        $pending = Site::factory()->pending()->create();
        $this->actingAs($admin)->post("/sites/{$pending->id}/databases", ['suffix' => 'shop'])
            ->assertSessionHas('error');

        $this->assertSame(1, Database::count());
    }

    public function test_names_follow_the_rules_of_the_server_script(): void
    {
        foreach (['ow38217_shop', 'ow10001_a', 'ow59999_db_2', 'ow38217_abcdefghijklmnop'] as $name) {
            $this->assertTrue(Database::isValidName($name), $name);
        }
        foreach (['ow10000_panel', 'ow60000_db', 'ow38217_', 'ow38217__x', 'ow38217_Shop', 'owpanel', 'ow38217_abcdefghijklmnopq'] as $name) {
            $this->assertFalse(Database::isValidName($name), $name);
        }

        $this->expectException(InvalidArgumentException::class);
        ScriptCall::dbDelete('ow10000_panel');
    }

    public function test_deleting_a_website_mentions_and_removes_its_databases(): void
    {
        $admin = $this->admin();
        $site = $this->site();
        Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);

        $this->actingAs($admin)->get("/sites/{$site->id}")
            ->assertSee('1 Datenbank');

        $this->runner(new FakeScriptRunner(['{"ok":true,"script":"site-delete","user":"ow38217"}'."\n"]));
        $this->actingAs($admin)->delete("/sites/{$site->id}", ['confirm_domain' => 'shop.example.de']);

        $this->assertSame(0, Site::count());
        $this->assertSame(0, Database::count());
    }

    public function test_customers_cannot_reach_databases(): void
    {
        Queue::fake();
        $customer = User::factory()->for(Customer::factory())->create();
        $site = $this->site();
        $site->forceFill(['customer_id' => $customer->customer_id])->save();
        $database = Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);

        $this->actingAs($customer)->get("/sites/{$site->id}/databases")->assertForbidden();
        $this->actingAs($customer)->post("/sites/{$site->id}/databases", ['suffix' => 'x'])->assertForbidden();
        $this->actingAs($customer)->get("/databases/{$database->id}")->assertForbidden();
        $this->actingAs($customer)->withSession(['auth.password_confirmed_at' => time()])
            ->get("/databases/{$database->id}/password")->assertForbidden();
        $this->actingAs($customer)->post("/databases/{$database->id}/password")->assertForbidden();
        $this->actingAs($customer)->post("/databases/{$database->id}/retry")->assertForbidden();
        $this->actingAs($customer)->delete("/databases/{$database->id}", ['confirm_name' => 'ow38217_shop'])->assertForbidden();

        $this->assertSame(0, ServerTask::count());
        $this->assertSame(1, Database::count());
    }
}
