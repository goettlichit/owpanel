<?php

/*
 * Helper of server/bin/db-query (E-079). Runs SQL as the database user and
 * prints all results as one JSON line, the result line of db-query.
 *
 * Started only by db-query, as the website's Linux user. Standard input:
 * the password in the first line, then the SQL. Limits keep the answer
 * small enough for the panel: rows per result, bytes per cell, bytes in
 * total, results per run, and seconds per statement.
 */

declare(strict_types=1);

const SCRIPT = 'db-query';
const SOCKET = '/run/mysqld/mysqld.sock';
const MAX_SQL_BYTES = 1048576;
const MAX_CELL_BYTES = 65536;
const MAX_OUTPUT_BYTES = 4194304;
const MAX_RESULTS = 25;
const STATEMENT_SECONDS = 25;
const NUMBER_TYPES = ['TINY', 'SHORT', 'LONG', 'LONGLONG', 'INT24', 'FLOAT', 'DOUBLE', 'NEWDECIMAL', 'DECIMAL', 'YEAR'];

/**
 * @param  array<string, mixed>  $data
 */
function finish(bool $ok, array $data): never
{
    echo json_encode(
        ['ok' => $ok, 'script' => SCRIPT] + $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    ), "\n";
    exit($ok ? 0 : 1);
}

function fail(string $code, string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    finish(false, ['error' => $code]);
}

/**
 * @param  list<string>  $argv
 * @return array{string, int}
 */
function arguments(array $argv): array
{
    $name = '';
    $maxRows = 1000;

    for ($i = 1; $i < count($argv); $i += 2) {
        $value = $argv[$i + 1] ?? fail('invalid_arguments', 'missing value for '.$argv[$i]);
        if ($argv[$i] === '--name') {
            $name = $value;
        } elseif ($argv[$i] === '--max-rows' && preg_match('/^[1-9][0-9]{0,3}$/', $value) && (int) $value <= 1000) {
            $maxRows = (int) $value;
        } else {
            fail('invalid_arguments', 'unknown or invalid argument');
        }
    }

    // Same rule as owp_valid_db_name; db-query has checked it already.
    if (! preg_match('/^ow[1-5][0-9]{4}_[a-z0-9][a-z0-9_]{0,15}$/', $name)) {
        fail('invalid_database_name', 'invalid database name');
    }

    return [$name, $maxRows];
}

/**
 * @return array{string, string}
 */
function input(): array
{
    $input = (string) stream_get_contents(STDIN, MAX_SQL_BYTES + 128);
    if (fread(STDIN, 1) !== '') {
        fail('query_too_large', 'the SQL is larger than '.MAX_SQL_BYTES.' bytes');
    }

    [$password, $sql] = array_pad(explode("\n", $input, 2), 2, '');
    if (! preg_match('/^[A-Za-z0-9]{32,64}$/', $password)) {
        fail('invalid_password', 'no valid password on stdin');
    }
    if (trim($sql) === '') {
        fail('empty_query', 'no SQL on stdin');
    }
    if (! preg_match('//u', $sql)) {
        fail('invalid_query', 'the SQL is not valid UTF-8');
    }

    return [$password, $sql];
}

/**
 * A cell as JSON: null, text, cut text with its full length, or binary data
 * with its length and the first bytes as hex.
 */
function cell(mixed $value): mixed
{
    if ($value === null || is_int($value) || is_float($value)) {
        return $value;
    }

    $value = (string) $value;
    if (! preg_match('//u', $value)) {
        return ['binary' => strlen($value), 'hex' => bin2hex(substr($value, 0, 32))];
    }
    if (strlen($value) <= MAX_CELL_BYTES) {
        return $value;
    }

    // Cut at a character boundary, not inside a UTF-8 sequence.
    $cut = MAX_CELL_BYTES;
    while ($cut > 0 && (ord($value[$cut]) & 0xC0) === 0x80) {
        $cut--;
    }

    return ['text' => substr($value, 0, $cut), 'length' => strlen($value)];
}

/**
 * @return array<string, mixed>
 */
function result(PDOStatement $statement, int $maxRows, int &$budget): array
{
    $columns = [];
    for ($i = 0; $i < $statement->columnCount(); $i++) {
        $meta = $statement->getColumnMeta($i) ?: [];
        $columns[] = [
            'name' => (string) ($meta['name'] ?? ''),
            'type' => in_array($meta['native_type'] ?? '', NUMBER_TYPES, true) ? 'number' : 'text',
        ];
    }

    $rows = [];
    $total = 0;
    while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
        $total++;
        if ($total > $maxRows || $budget <= 0) {
            // Read on to count the rows; MariaDB's time limit still applies.
            continue;
        }
        $row = array_map(cell(...), $row);
        $budget -= strlen((string) json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE));
        $rows[] = $row;
    }

    return ['columns' => $columns, 'rows' => $rows, 'total' => $total];
}

function main(array $argv): never
{
    [$name, $maxRows] = arguments($argv);
    [$password, $sql] = input();

    try {
        $pdo = new PDO('mysql:unix_socket='.SOCKET.';dbname='.$name.';charset=utf8mb4', $name, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => true,
            Pdo\Mysql::ATTR_MULTI_STATEMENTS => true,
            Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false,
        ]);
        $pdo->exec('SET SESSION max_statement_time = '.STATEMENT_SECONDS);
    } catch (PDOException $e) {
        fail('database_login_failed', 'cannot sign in to '.$name.': '.$e->getMessage());
    }

    $results = [];
    $error = null;
    $budget = MAX_OUTPUT_BYTES;
    $current = 1;

    try {
        $statement = $pdo->query($sql);
        do {
            if ($statement->columnCount() > 0) {
                $result = result($statement, $maxRows, $budget);
            } else {
                $result = ['affected' => $statement->rowCount()];
            }
            if (count($results) < MAX_RESULTS) {
                $results[] = ['statement' => $current] + $result;
            }
            $current++;
        } while ($statement->nextRowset());
    } catch (PDOException $e) {
        $error = [
            'statement' => $current,
            'code' => (int) ($e->errorInfo[1] ?? 0),
            'message' => (string) ($e->errorInfo[2] ?? $e->getMessage()),
        ];
    }

    finish(true, [
        'results' => $results,
        'statements' => $current - 1,
        'sql_error' => $error,
    ]);
}

main($argv);
