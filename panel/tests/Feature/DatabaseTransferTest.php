<?php

namespace Tests\Feature;

use App\Databases\Transfers;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Export and import of databases (E-082).
 */
class DatabaseTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/owpanel-transfers-'.bin2hex(random_bytes(4));
        config(['owpanel.transfers.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function database(): Database
    {
        $site = Site::factory()->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);

        return Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);
    }

    private function runner(FakeScriptRunner $runner): FakeScriptRunner
    {
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_an_export_streams_the_dump_into_a_file_for_download(): void
    {
        $dump = gzencode("CREATE TABLE t (id INT);\n");
        $runner = $this->runner(new FakeScriptRunner(
            ["Exporting ow38217_shop\n", '{"ok":true,"script":"db-export","database":"ow38217_shop"}'."\n"],
            stdout: $dump,
        ));
        $database = $this->database();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post("/databases/{$database->id}/export");
        $task = ServerTask::sole();
        $response->assertRedirect("/databases/{$database->id}/transfer");

        $this->assertSame(ServerScript::DbExport, $task->script);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(['--name', 'ow38217_shop'], $task->arguments);

        $call = $runner->calls[0];
        $this->assertSame($database->password."\n", $call->stdin);
        $this->assertStringNotContainsString($database->password, $call->commandLine());
        $this->assertSame(Transfers::path($task->transfer_file), $call->stdoutFile);

        $this->actingAs($admin)->get("/tasks/{$task->id}")->assertOk()->assertSee('Unter Export/Import von ow38217_shop')->assertDontSee(route('tasks.download', $task), false);
        $this->actingAs($admin)->get("/databases/{$database->id}/transfer")->assertOk()->assertSee('Verfügbare Exporte');

        $download = $this->actingAs($admin)->get("/tasks/{$task->id}/download");
        $download->assertOk()->assertDownload('ow38217_shop-'.$task->finished_at->copy()->setTimezone(config('owpanel.timezone'))->format('Y-m-d-His').'.sql.gz');
        $this->assertSame($dump, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->assertSame(1, AuditLog::where('action', 'database.export_downloaded')->count());
    }

    public function test_a_failed_export_leaves_no_file(): void
    {
        $this->runner(new FakeScriptRunner(['{"ok":false,"script":"db-export","error":"export_failed"}'."\n"], exitCode: 1, stdout: 'partial'));
        $database = $this->database();

        $this->actingAs($this->admin())->post("/databases/{$database->id}/export");
        $task = ServerTask::sole();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertFalse(Transfers::exists($task->transfer_file));
        $this->actingAs($this->admin())->get("/tasks/{$task->id}/download")->assertNotFound();
    }

    public function test_an_import_sends_password_and_file_and_removes_the_upload(): void
    {
        $runner = $this->runner(new FakeScriptRunner(['{"ok":true,"script":"db-import","database":"ow38217_shop"}'."\n"]));
        $database = $this->database();

        $file = UploadedFile::fake()->createWithContent('backup.sql.gz', gzencode('INSERT INTO t VALUES (1);'));
        $this->actingAs($this->admin())->post("/databases/{$database->id}/import", ['dump' => $file, 'confirm' => '1'])
            ->assertRedirect();

        $task = ServerTask::sole();
        $this->assertSame(ServerScript::DbImport, $task->script);
        $this->assertSame(['--name', 'ow38217_shop', '--gzip'], $task->arguments);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame($database->password."\n", $runner->calls[0]->stdin);
        $this->assertSame(gzencode('INSERT INTO t VALUES (1);'), $runner->stdinFiles[0]);
        // The upload is gone after the task.
        $this->assertFalse(Transfers::exists($task->transfer_file));
    }

    public function test_plain_sql_is_imported_without_gzip(): void
    {
        $runner = $this->runner(new FakeScriptRunner(['{"ok":true,"script":"db-import","database":"ow38217_shop"}'."\n"]));
        $database = $this->database();

        $file = UploadedFile::fake()->createWithContent('backup.sql', 'SELECT 1;');
        $this->actingAs($this->admin())->post("/databases/{$database->id}/import", ['dump' => $file, 'confirm' => '1']);

        $this->assertSame(['--name', 'ow38217_shop'], ServerTask::sole()->arguments);
        $this->assertSame('SELECT 1;', $runner->stdinFiles[0]);
    }

    public function test_the_import_checks_file_and_confirmation(): void
    {
        $runner = $this->runner(new FakeScriptRunner);
        $database = $this->database();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/databases/{$database->id}/import", ['dump' => UploadedFile::fake()->createWithContent('a.sql', 'x')])
            ->assertSessionHasErrors('confirm');
        $this->actingAs($admin)->post("/databases/{$database->id}/import", ['dump' => UploadedFile::fake()->createWithContent('photo.jpg', 'x'), 'confirm' => '1'])
            ->assertSessionHasErrors('dump');
        config(['owpanel.transfers.max_upload_mb' => 1]);
        $this->actingAs($admin)->post("/databases/{$database->id}/import", ['dump' => UploadedFile::fake()->create('big.sql', 2048), 'confirm' => '1'])
            ->assertSessionHasErrors('dump');

        $this->assertSame(0, ServerTask::count());
        $this->assertSame([], $runner->calls);
    }

    public function test_no_export_or_import_while_the_database_is_busy(): void
    {
        $this->runner(new FakeScriptRunner);
        $database = $this->database();
        ServerTask::factory()->running()->create(['server_id' => $database->server_id, 'database_id' => $database->id, 'script' => ServerScript::DbExport]);

        $this->actingAs($this->admin())->post("/databases/{$database->id}/export")->assertSessionHas('error');
        $this->assertSame(1, ServerTask::count());
    }

    public function test_transfer_files_are_pruned_after_a_day_and_names_are_checked(): void
    {
        Transfers::ensureDirectory();
        $old = Transfers::newName(true);
        $new = Transfers::newName(false);
        file_put_contents(Transfers::path($old), 'x');
        file_put_contents(Transfers::path($new), 'y');
        touch(Transfers::path($old), time() - 25 * 3600);

        $this->assertSame(1, Transfers::prune());
        $this->assertFalse(Transfers::exists($old));
        $this->assertTrue(Transfers::exists($new));

        $this->expectException(\InvalidArgumentException::class);
        Transfers::path('../../.env');
    }

    public function test_customers_cannot_export_import_or_download(): void
    {
        $runner = $this->runner(new FakeScriptRunner(stdout: 'dump'));
        $database = $this->database();
        $customer = User::factory()->create();
        $database->site->forceFill(['customer_id' => $customer->customer_id])->save();

        $this->actingAs($this->admin())->post("/databases/{$database->id}/export");
        $task = ServerTask::sole();

        $this->actingAs($customer)->get("/databases/{$database->id}/transfer")->assertForbidden();
        $this->actingAs($customer)->post("/databases/{$database->id}/export")->assertForbidden();
        $this->actingAs($customer)->post("/databases/{$database->id}/import", ['dump' => UploadedFile::fake()->createWithContent('a.sql', 'x'), 'confirm' => '1'])->assertForbidden();
        $this->actingAs($customer)->get("/tasks/{$task->id}/download")->assertForbidden();

        $this->assertCount(1, $runner->calls);
    }
}
