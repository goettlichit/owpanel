<?php

namespace App\Http\Controllers;

use App\Databases\QueryClient;
use App\Databases\QueryResponse;
use App\Databases\Sql;
use App\Models\Database;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The database tool in the panel (E-079): tables of a database, the rows of
 * a table page by page, and an SQL console. Every page has the query worker
 * run db-query on the server as the database user and waits for the answer;
 * nothing of the data is stored in the panel.
 */
class DatabaseToolController extends Controller
{
    public const PAGE_SIZE = 50;

    /**
     * The longest SQL the console accepts. Imports of dumps come later as
     * their own function.
     */
    public const MAX_SQL_LENGTH = 65535;

    public function tables(Database $database, QueryClient $queries): View
    {
        Gate::authorize('query', $database);

        $response = $queries->run($database, Sql::tables());
        $tables = $response->ok() ? ($response->result(0)?->rows ?? []) : [];

        return view('databases.tables', [
            ...$this->context($database),
            'response' => $response,
            'tables' => array_map(fn (array $row) => [
                'name' => (string) $row[0],
                'view' => $row[1] === 'VIEW',
                'engine' => $row[2],
                'rows' => is_numeric($row[3]) ? (int) $row[3] : null,
                'size' => is_numeric($row[4]) ? (int) $row[4] : null,
                'collation' => $row[5],
            ], $tables),
        ]);
    }

    public function table(Request $request, Database $database, QueryClient $queries): View
    {
        Gate::authorize('query', $database);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'sort' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $table = $validated['name'];
        $sort = isset($validated['sort']) ? (int) $validated['sort'] : null;
        $descending = ($validated['dir'] ?? 'asc') === 'desc';
        $page = (int) ($validated['page'] ?? 1);

        $response = $queries->run(
            $database,
            Sql::page($table, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, $sort, $descending),
        );

        $columns = $response->result(0)?->assoc() ?? [];
        $count = $response->result(1)?->scalar();
        $count = is_numeric($count) ? (int) $count : null;

        return view('databases.table', [
            ...$this->context($database),
            'response' => $response,
            'table' => $table,
            'columns' => $columns,
            'count' => $count,
            'rows' => $response->result(2),
            'sort' => $sort,
            'descending' => $descending,
            'page' => $page,
            'pages' => $count === null ? 1 : max(1, (int) ceil($count / self::PAGE_SIZE)),
        ]);
    }

    public function console(Request $request, Database $database): View
    {
        Gate::authorize('query', $database);

        $table = $request->query('table');
        $sql = is_string($table) && $table !== '' && strlen($table) <= 64
            ? 'SELECT * FROM '.Sql::identifier($table).' LIMIT 100;'
            : '';

        return view('databases.sql', [...$this->context($database), 'sql' => $sql, 'response' => null]);
    }

    public function run(Request $request, Database $database, QueryClient $queries): View
    {
        Gate::authorize('query', $database);

        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:'.self::MAX_SQL_LENGTH],
        ]);

        $response = $queries->run($database, $validated['sql']);

        // Which database and whether it worked; the SQL itself may contain
        // personal data or passwords and stays out of the log.
        Audit::record('database.query', $database, [
            'database' => $database->name,
            'result' => $response->error ?? ($response->sqlError === null ? 'ok' : 'sql_error'),
        ], $request->user());

        // The outcome is a toast like after any action (E-083); results and
        // SQL errors are content of the page.
        if ($response->ok()) {
            $request->session()->now('status', trans_choice('databases.tool.sql.done', $response->statements, ['count' => $response->statements]));
        }

        return view('databases.sql', [...$this->context($database), 'sql' => $validated['sql'], 'response' => $response]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Database $database): array
    {
        return ['database' => $database, 'site' => $database->site->load('server')];
    }

    /**
     * Message for a failed query, for the views.
     */
    public static function errorMessage(QueryResponse $response): ?string
    {
        if ($response->error === null) {
            return null;
        }

        $key = 'databases.tool.errors.'.$response->error;

        return __(trans()->has($key) ? $key : 'databases.tool.errors.unknown', ['code' => $response->error]);
    }
}
