<?php

namespace App\Jobs;

use App\Databases\QueryClient;
use App\Databases\QueryResponse;
use App\Databases\QueryRunner;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs SQL of the database tool in the query worker (E-079).
 *
 * Only the worker user can read the panel's SSH key; web requests cannot
 * reach a server (docs/SICHERHEIT.md). The web request waits for the
 * answer in QueryClient. The payload holds the SQL and is therefore
 * encrypted in the jobs table; the answer goes encrypted into the cache.
 */
class RunDatabaseQuery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'queries';

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * db-query stops itself after 35 seconds.
     */
    public int $timeout = 50;

    public function __construct(
        public readonly string $queryId,
        public readonly string $databaseId,
        #[\SensitiveParameter] public readonly string $sql,
        public readonly int $maxRows,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(QueryRunner $runner): void
    {
        $database = Database::find($this->databaseId);

        try {
            $response = $database === null
                ? QueryResponse::failed('database_not_ready')
                : $runner->run($database, $this->sql, $this->maxRows);
        } catch (Throwable $e) {
            report($e);
            $response = QueryResponse::failed('query_failed');
        }

        QueryClient::store($this->queryId, $response);
    }
}
