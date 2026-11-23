<?php

namespace App\Databases;

/**
 * Answer of db-query: the results of the statements that ran, and the SQL
 * error that stopped the run, if any. $error is set instead when the query
 * could not run at all (no connection, wrong password, timeout).
 */
final readonly class QueryResponse
{
    /**
     * @param  list<QueryResult>  $results
     * @param  array{statement: int, code: int, message: string}|null  $sqlError
     */
    public function __construct(
        public array $results = [],
        public int $statements = 0,
        public ?array $sqlError = null,
        public ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self(error: $error);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->sqlError === null;
    }

    public function result(int $index): ?QueryResult
    {
        return $this->results[$index] ?? null;
    }

    /**
     * For the way from the query worker to the web request; fromArray()
     * reads it back and checks it again.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->error !== null ? ['error' => $this->error] : [
            'results' => array_map(fn (QueryResult $result) => $result->toArray(), $this->results),
            'statements' => $this->statements,
            'sql_error' => $this->sqlError,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (array_key_exists('error', $data)) {
            return self::failed(is_string($data['error']) && preg_match('/^[a-z0-9_]{1,64}$/', $data['error']) ? $data['error'] : 'invalid_result');
        }

        return self::fromJson($data);
    }

    /**
     * @param  array<mixed>  $json
     */
    public static function fromJson(array $json): self
    {
        $results = $json['results'] ?? null;
        $statements = $json['statements'] ?? null;
        if (! is_array($results) || ! array_is_list($results) || ! is_int($statements) || $statements < 0) {
            return self::failed('invalid_result');
        }

        $parsed = [];
        foreach ($results as $result) {
            $result = is_array($result) ? QueryResult::fromArray($result) : null;
            if ($result === null) {
                return self::failed('invalid_result');
            }
            $parsed[] = $result;
        }

        $error = $json['sql_error'] ?? null;
        if ($error !== null) {
            if (! is_array($error) || ! is_int($error['statement'] ?? null) || ! is_int($error['code'] ?? null) || ! is_string($error['message'] ?? null)) {
                return self::failed('invalid_result');
            }
            $error = [
                'statement' => $error['statement'],
                'code' => $error['code'],
                'message' => mb_strimwidth($error['message'], 0, 1000, '…'),
            ];
        }

        return new self($parsed, $statements, $error);
    }
}
