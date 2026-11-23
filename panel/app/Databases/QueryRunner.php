<?php

namespace App\Databases;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Models\Database;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;

/**
 * Runs SQL in a database through db-query (E-079). Runs only in the query
 * worker (RunDatabaseQuery); web requests use QueryClient.
 *
 * Not a server task: a query changes nothing that the panel manages, it
 * ends within 35 seconds, and the task list would only fill up with
 * clicks. The SQL itself is neither stored nor logged.
 */
class QueryRunner
{
    /**
     * The script keeps its answer below about 4 MB; anything far above
     * is not a valid answer.
     */
    private const MAX_OUTPUT_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly ScriptRunner $runner) {}

    public function run(Database $database, #[\SensitiveParameter] string $sql, int $maxRows = 1000): QueryResponse
    {
        if ($database->status !== SiteStatus::Active) {
            return QueryResponse::failed('database_not_ready');
        }

        $output = '';
        $tooLarge = false;
        $collect = function (string $chunk) use (&$output, &$tooLarge): void {
            if ($tooLarge || strlen($output) + strlen($chunk) > self::MAX_OUTPUT_BYTES) {
                $tooLarge = true;

                return;
            }
            $output .= $chunk;
        };

        try {
            $result = $this->runner->run(
                $database->server,
                ScriptCall::dbQuery($database->name, $database->password, $sql, $maxRows),
                $collect,
            );
        } catch (ServerConnectionException $e) {
            return QueryResponse::failed($e->errorCode);
        }

        if ($tooLarge) {
            return QueryResponse::failed('result_too_large');
        }

        // The runner keeps only the end of the output; the result line of
        // db-query can be far longer, so it is read from the full output.
        $result = ScriptResult::fromOutput(ServerScript::DbQuery->value, $output, $result->exitCode, $result->timedOut, depth: 16);
        if (! $result->succeeded()) {
            return QueryResponse::failed($result->error() ?? 'invalid_result');
        }

        return QueryResponse::fromJson($result->json ?? []);
    }
}
