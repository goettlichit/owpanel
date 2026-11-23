<?php

namespace Tests\Feature;

use App\Databases\QueryClient;
use App\Databases\QueryResponse;
use App\Databases\Sql;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Jobs\RunDatabaseQuery;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Database;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\LiveAnswers;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * The database tool in the panel (E-079): tables, rows and SQL console,
 * each through db-query as the database user.
 */
class DatabaseToolTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function database(): Database
    {
        $site = Site::factory()->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);

        return Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function answer(array $answer, int $exitCode = 0): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([json_encode(['ok' => $exitCode === 0, 'script' => 'db-query'] + $answer)."\n"], exitCode: $exitCode);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function rows(int $statement, array $columns, array $rows, ?int $total = null): array
    {
        return [
            'statement' => $statement,
            'columns' => array_map(fn ($name) => ['name' => $name, 'type' => $name === 'id' ? 'number' : 'text'], $columns),
            'rows' => $rows,
            'total' => $total ?? count($rows),
        ];
    }

    public function test_the_tables_page_lists_tables_with_size(): void
    {
        $runner = $this->answer(['statements' => 1, 'sql_error' => null, 'results' => [
            $this->rows(1, ['TABLE_NAME', 'TABLE_TYPE', 'ENGINE', 'TABLE_ROWS', 'SIZE', 'TABLE_COLLATION'], [
                ['wp_posts', 'BASE TABLE', 'InnoDB', 1234, 2097152, 'utf8mb4_unicode_ci'],
                ['recent', 'VIEW', null, null, null, null],
            ]),
        ]]);
        $database = $this->database();

        $this->actingAs($this->admin())->get("/databases/{$database->id}/tables")
            ->assertOk()
            ->assertSee('wp_posts')
            ->assertSee('1.234')
            ->assertSee('2,0 MB')
            ->assertSee('Ansicht')
            ->assertSee(route('databases.table', [$database, 'name' => 'wp_posts']), false);

        $call = $runner->calls[0];
        $this->assertSame(ServerScript::DbQuery, $call->script);
        $this->assertSame(['--name', 'ow38217_shop', '--max-rows', '1000'], $call->arguments);
        // Password first, then the SQL; nothing of it on the command line.
        $this->assertSame($database->password."\n".Sql::tables(), $call->stdin);
        $this->assertStringNotContainsString($database->password, $call->commandLine());
        // Browsing is not a server task.
        $this->assertSame(0, ServerTask::count());
    }

    public function test_a_table_page_shows_rows_columns_and_paging(): void
    {
        $runner = $this->answer(['statements' => 3, 'sql_error' => null, 'results' => [
            $this->rows(1, ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_KEY', 'EXTRA'], [
                ['id', 'int(11)', 'NO', 'PRI', 'auto_increment'],
                ['title', 'varchar(200)', 'YES', '', ''],
            ]),
            $this->rows(2, ['COUNT(*)'], [[120]]),
            $this->rows(3, ['id', 'title'], [
                [51, '<b>Hallo</b>'],
                [52, null],
                [53, ['binary' => 2048, 'hex' => '00ff']],
                [54, ['text' => 'sehr lang', 'length' => 70000]],
            ]),
        ]]);
        $database = $this->database();

        $this->actingAs($this->admin())->get("/databases/{$database->id}/table?name=wp_posts&sort=2&dir=desc&page=2")
            ->assertOk()
            ->assertSee('&lt;b&gt;Hallo&lt;/b&gt;', false)
            ->assertDontSee('<b>Hallo</b>', false)
            ->assertSee('NULL')
            ->assertSee('Binärdaten, 2,0 KB')
            ->assertSee('gekürzt, insgesamt 68,4 KB')
            ->assertSee('Zeilen 51 bis 54 von 120')
            ->assertSee('Seite 2 von 3')
            ->assertSee('varchar(200)')
            ->assertSee('auto_increment');

        $this->assertStringContainsString(
            'SELECT * FROM `wp_posts` ORDER BY 2 DESC LIMIT 50 OFFSET 50;',
            (string) $runner->calls[0]->stdin,
        );
    }

    public function test_table_names_are_quoted_and_never_reach_the_sql_unquoted(): void
    {
        $this->assertSame('`a``b`', Sql::identifier('a`b'));
        $this->assertSame("X'612762'", Sql::bytes("a'b"));

        $sql = Sql::page('x`; DROP TABLE y; --', 0, 50, null, false);
        $this->assertStringContainsString('FROM `x``; DROP TABLE y; --`', $sql);
        $this->assertStringContainsString("TABLE_NAME = X'", $sql);
        $this->assertStringNotContainsString("TABLE_NAME = 'x", $sql);
    }

    public function test_the_table_page_validates_its_parameters(): void
    {
        $runner = $this->answer(['statements' => 0, 'sql_error' => null, 'results' => []]);
        $database = $this->database();
        $admin = $this->admin();

        foreach (['sort=1;DROP', 'sort=0', 'dir=sideways', 'page=0', 'name='.str_repeat('x', 65)] as $query) {
            $this->actingAs($admin)->get("/databases/{$database->id}/table?name=t&{$query}")->assertSessionHasErrors();
        }
        $this->actingAs($admin)->get("/databases/{$database->id}/table")->assertSessionHasErrors('name');

        $this->assertSame([], $runner->calls);
    }

    public function test_the_console_runs_sql_and_shows_results_and_errors(): void
    {
        $runner = $this->answer(['statements' => 2, 'results' => [
            ['statement' => 1, 'affected' => 3],
            $this->rows(2, ['id', 'title'], [[1, 'a'], [2, 'b']], total: 5000),
        ], 'sql_error' => ['statement' => 3, 'code' => 1054, 'message' => "Unknown column 'nope' in 'SELECT'"]]);
        $database = $this->database();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/databases/{$database->id}/sql?table=wp_posts")
            ->assertOk()
            ->assertSee('SELECT * FROM `wp_posts` LIMIT 100;');

        $sql = "UPDATE t SET a = 1;\nSELECT * FROM t;\nSELECT nope FROM t;";
        $this->actingAs($admin)->post("/databases/{$database->id}/sql", ['sql' => $sql])
            ->assertOk()
            ->assertSee('3 Zeilen betroffen')
            ->assertSee('2 von 5.000 Zeilen angezeigt')
            ->assertSee('Fehler in Befehl 3 (1054): Unknown column', false)
            ->assertSee('SELECT nope FROM t;');

        $this->assertSame($database->password."\n".$sql, $runner->calls[0]->stdin);

        $entry = AuditLog::where('action', 'database.query')->sole();
        $this->assertSame(['database' => 'ow38217_shop', 'result' => 'sql_error'], $entry->context);
        // The SQL is not in the audit log.
        $this->assertStringNotContainsString('nope', json_encode($entry->toArray()));
    }

    public function test_failures_are_shown_as_messages(): void
    {
        $database = $this->database();
        $admin = $this->admin();

        $this->answer(['error' => 'database_login_failed'], exitCode: 1);
        $this->actingAs($admin)->post("/databases/{$database->id}/sql", ['sql' => 'SELECT 1'])
            ->assertOk()
            ->assertSee('Die Anmeldung an der Datenbank ist fehlgeschlagen');

        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(connectionError: 'ssh_connection_failed'));
        $this->actingAs($admin)->get("/databases/{$database->id}/tables")
            ->assertOk()
            ->assertSee('Der Server ist nicht erreichbar.');

        // A malformed answer is not shown.
        $this->answer(['statements' => 1, 'sql_error' => null, 'results' => [
            ['statement' => 1, 'columns' => [['name' => 'a', 'type' => 'text']], 'rows' => [['a', 'extra']], 'total' => 1],
        ]]);
        $this->actingAs($admin)->get("/databases/{$database->id}/tables")
            ->assertOk()
            ->assertSee('Der Server hat eine unerwartete Antwort geschickt.');
    }

    public function test_a_database_that_is_not_active_is_not_queried(): void
    {
        $runner = $this->answer(['statements' => 0, 'sql_error' => null, 'results' => []]);
        $database = $this->database();
        $database->forceFill(['status' => SiteStatus::Pending])->save();

        $this->actingAs($this->admin())->get("/databases/{$database->id}/tables")
            ->assertOk()
            ->assertSee('Die Datenbank ist gerade nicht bereit.');
        $this->assertSame([], $runner->calls);
    }

    public function test_the_query_call_checks_its_input(): void
    {
        $this->assertSame(['--name', 'ow38217_shop', '--max-rows', '50'], ScriptCall::dbQuery('ow38217_shop', 'pw', 'SELECT 1', 50)->arguments);

        foreach ([
            fn () => ScriptCall::dbQuery('ow38217_x;id', 'pw', 'SELECT 1'),
            fn () => ScriptCall::dbQuery('ow38217_shop', "pw\nx", 'SELECT 1'),
            fn () => ScriptCall::dbQuery('ow38217_shop', 'pw', '  '),
            fn () => ScriptCall::dbQuery('ow38217_shop', 'pw', 'SELECT 1', 1001),
        ] as $call) {
            try {
                $call();
                $this->fail('accepted invalid input');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_answers_with_the_wrong_shape_are_rejected(): void
    {
        foreach ([
            ['results' => 'x', 'statements' => 1],
            ['results' => [['statement' => 0, 'affected' => 1]], 'statements' => 1],
            ['results' => [['statement' => 1, 'columns' => [['name' => 'a', 'type' => 'evil']], 'rows' => [], 'total' => 0]], 'statements' => 1],
            ['results' => [['statement' => 1, 'columns' => [['name' => 'a', 'type' => 'text']], 'rows' => [[['html' => 'x']]], 'total' => 1]], 'statements' => 1],
            ['results' => [], 'statements' => 0, 'sql_error' => ['statement' => 1]],
        ] as $json) {
            $this->assertSame('invalid_result', QueryResponse::fromJson($json)->error, json_encode($json));
        }
    }

    public function test_customers_cannot_use_the_tool_of_any_database(): void
    {
        $runner = $this->answer(['statements' => 0, 'sql_error' => null, 'results' => []]);
        $customer = User::factory()->for(Customer::factory())->create();
        $database = $this->database();
        // Even on their own website, for now (customers come in milestone 7).
        $database->site->forceFill(['customer_id' => $customer->customer_id])->save();

        $this->actingAs($customer)->get("/databases/{$database->id}/tables")->assertForbidden();
        $this->actingAs($customer)->get("/databases/{$database->id}/table?name=t")->assertForbidden();
        $this->actingAs($customer)->get("/databases/{$database->id}/sql")->assertForbidden();
        $this->actingAs($customer)->post("/databases/{$database->id}/sql", ['sql' => 'DROP TABLE t'])->assertForbidden();

        $this->assertSame([], $runner->calls);
    }

    public function test_the_overview_links_to_tables_and_sql(): void
    {
        $this->answer([]);
        $database = $this->database();

        $this->actingAs($this->admin())->get("/databases/{$database->id}")
            ->assertOk()
            ->assertSee(route('databases.tables', $database), false)
            ->assertSee(route('databases.sql', $database), false);
    }

    public function test_web_requests_hand_the_query_to_the_encrypted_query_queue(): void
    {
        // Web requests cannot read the SSH key (docs/SICHERHEIT.md); the
        // query worker runs db-query and the request waits for it.
        Queue::fake();
        config(['owpanel.queries.wait_seconds' => 0.3]);
        $runner = $this->answer(['statements' => 0, 'sql_error' => null, 'results' => []]);
        $database = $this->database();

        $this->actingAs($this->admin())->post("/databases/{$database->id}/sql", ['sql' => 'SELECT 1'])
            ->assertOk()
            ->assertSee('Der Dienst für Abfragen hat nicht geantwortet.');

        Queue::assertPushedOn(RunDatabaseQuery::QUEUE, RunDatabaseQuery::class, fn (RunDatabaseQuery $job) => $job->sql === 'SELECT 1' && $job->databaseId === $database->id);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new RunDatabaseQuery('x', 'y', 'SELECT 1', 10));
        $this->assertSame([], $runner->calls);
    }

    public function test_the_answer_waits_encrypted_in_the_cache_and_is_read_once(): void
    {
        $response = new QueryResponse([], 1, ['statement' => 1, 'code' => 1064, 'message' => 'secret value']);
        QueryClient::store('abc', $response);

        $stored = Cache::get('live-answer:abc');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('secret value', $stored);

        $this->assertEquals($response, QueryResponse::fromArray(LiveAnswers::take('abc')));
        $this->assertNull(LiveAnswers::take('abc'));
    }
}
