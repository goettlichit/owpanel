<?php

namespace App\Databases;

/**
 * Result of one SQL statement: rows with their columns, or the number of
 * affected rows. Cells are null, int, float, string, or an array for cut
 * text (text, length) and binary data (binary, hex).
 */
final readonly class QueryResult
{
    /**
     * @param  list<array{name: string, type: string}>  $columns
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        public int $statement,
        public array $columns = [],
        public array $rows = [],
        public int $total = 0,
        public ?int $affected = null,
    ) {}

    public function hasRows(): bool
    {
        return $this->affected === null;
    }

    /**
     * Not all rows came back: over the row limit or the size limit.
     */
    public function truncated(): bool
    {
        return $this->total > count($this->rows);
    }

    /**
     * Value of the first cell, e.g. of SELECT COUNT(*).
     */
    public function scalar(): mixed
    {
        return $this->rows[0][0] ?? null;
    }

    /**
     * Rows as arrays keyed by column name.
     *
     * @return list<array<string, mixed>>
     */
    public function assoc(): array
    {
        $names = array_column($this->columns, 'name');

        return array_map(fn (array $row) => array_combine($names, $row), $this->rows);
    }

    /**
     * The same shape as in the answer of db-query, for fromArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->affected !== null
            ? ['statement' => $this->statement, 'affected' => $this->affected]
            : ['statement' => $this->statement, 'columns' => $this->columns, 'rows' => $this->rows, 'total' => $this->total];
    }

    /**
     * Builds a result from the server's answer, or null if anything does not
     * have the expected shape. The server is not trusted blindly.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $statement = $data['statement'] ?? null;
        if (! is_int($statement) || $statement < 1) {
            return null;
        }

        if (array_key_exists('affected', $data)) {
            return is_int($data['affected']) && $data['affected'] >= -1
                ? new self($statement, affected: $data['affected'])
                : null;
        }

        $columns = $data['columns'] ?? null;
        $rows = $data['rows'] ?? null;
        $total = $data['total'] ?? null;
        if (! is_array($columns) || ! array_is_list($columns) || ! is_array($rows) || ! array_is_list($rows)
            || ! is_int($total) || $total < count($rows)) {
            return null;
        }

        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['name'] ?? null) || ! in_array($column['type'] ?? null, ['number', 'text'], true)) {
                return null;
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== count($columns)
                || ! array_all($row, self::validCell(...))) {
                return null;
            }
        }

        return new self(
            $statement,
            array_map(fn (array $column) => ['name' => $column['name'], 'type' => $column['type']], $columns),
            $rows,
            $total,
        );
    }

    private static function validCell(mixed $cell): bool
    {
        if ($cell === null || is_int($cell) || is_float($cell) || is_string($cell)) {
            return true;
        }
        if (! is_array($cell)) {
            return false;
        }

        return (is_int($cell['binary'] ?? null) && is_string($cell['hex'] ?? null) && ctype_xdigit($cell['hex'] ?: '0'))
            || (is_string($cell['text'] ?? null) && is_int($cell['length'] ?? null));
    }
}
