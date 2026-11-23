<?php

namespace App\Databases;

/**
 * Builds the few pieces of SQL that the database tool writes itself
 * (E-079). Names come from the user or from the server, so they are always
 * quoted; values are written as hex literals and need no escaping at all.
 */
final class Sql
{
    /**
     * `name`, with backticks doubled.
     */
    public static function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * A string as hex literal, e.g. X'73686f70'. Compared as bytes.
     */
    public static function bytes(string $value): string
    {
        return "X'".bin2hex($value)."'";
    }

    public static function tables(): string
    {
        return 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH, TABLE_COLLATION'
            .' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME';
    }

    /**
     * Columns, row count and one page of a table, in this order. The sort
     * column is its position (ORDER BY 2), so no column name has to be
     * checked first and the whole page needs one call.
     */
    public static function page(string $table, int $offset, int $limit, ?int $sort, bool $descending): string
    {
        $quoted = self::identifier($table);
        $order = $sort === null ? '' : ' ORDER BY '.$sort.($descending ? ' DESC' : ' ASC');

        return 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA'
            .' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '.self::bytes($table)
            .' ORDER BY ORDINAL_POSITION;'
            ."\nSELECT COUNT(*) FROM {$quoted};"
            ."\nSELECT * FROM {$quoted}{$order} LIMIT {$limit} OFFSET {$offset};";
    }
}
