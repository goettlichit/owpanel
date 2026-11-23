<?php

namespace App\Databases;

use App\Enums\SiteStatus;
use App\Jobs\RunDatabaseQuery;
use App\Models\Database;
use App\Servers\LiveAnswers;
use Illuminate\Support\Str;

/**
 * The web side of the database tool (E-079): hands the SQL to the query
 * worker and waits for its answer (App\Servers\LiveAnswers).
 */
class QueryClient
{
    public function run(Database $database, #[\SensitiveParameter] string $sql, int $maxRows = 1000): QueryResponse
    {
        if ($database->status !== SiteStatus::Active) {
            return QueryResponse::failed('database_not_ready');
        }

        $id = (string) Str::ulid();
        RunDatabaseQuery::dispatch($id, (string) $database->id, $sql, $maxRows);

        $answer = LiveAnswers::wait($id);

        return $answer === null ? QueryResponse::failed('query_worker_timeout') : QueryResponse::fromArray($answer);
    }

    public static function store(string $id, QueryResponse $response): void
    {
        LiveAnswers::put($id, $response->toArray());
    }
}
