<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Schema;

use PDO;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * What the current database holds, read from information_schema - its tables, their columns,
 * indexes and constraints. Read only: nothing here writes DDL. Created with
 * MariaDbDriver::schema(); every method asks the server anew, in one statement.
 *
 * "The current database" is the connection's (DATABASE()); "a table" is a base table or a
 * system-versioned one - not a view, a sequence or a temporary table (a temporary table that
 * shadows a base table in this session leaves the base table described here, measured).
 *
 * information_schema shows what the user has privileges on (measured, the same on 10.11, 11.4 and
 * 12.3); a user with SELECT, INSERT, UPDATE or REFERENCES on the whole database sees everything.
 * With less: a table without any privilege is no table here; columns() shows the columns with
 * SELECT, INSERT, UPDATE or REFERENCES (on the database, the table or the column) and throws when
 * that leaves none; an index, and a key in constraints(), shows with any privilege on the table,
 * else only when every one of its columns has one; a CHECK constraint shows only with a
 * privilege on the database.
 *
 * Names are ordered as the server orders them (utf8mb3_general_ci: without case or accents, `_`
 * after the letters), names equal there by their bytes. Index and constraint names are never
 * compared there: `e` and `é` are two indexes, a UNIQUE named `PRÍMARY` is no primary key
 * (measured); a table's name is looked up as the server looks up tables.
 *
 * It is no snapshot: a table another connection changes at that moment can show either state, or
 * a mix - the table found, its rows from after the change (none after a DROP: columns() throws,
 * indexes() and constraints() return none; a view's columns where a view replaced it).
 */
final class Schema
{
    /** A table of the current database: a base table or a system-versioned one */
    private const TABLE = "TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED')";

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The tables of the current database, in the server's order of names (utf8mb3_general_ci:
     * without case or accents, `_` after the letters; names equal there - names that differ in
     * case need lower_case_table_names=0 - by their bytes, so for ASCII names the upper case
     * first).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $names = [];
        foreach ($this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE ' . self::TABLE . ' ORDER BY TABLE_NAME, BINARY TABLE_NAME', []) as $row) {
            $names[] = (string) $row[0];
        }

        return $names;
    }

    /**
     * Whether the current database has this table, compared as the server compares table names
     * (with case where lower_case_table_names=0, the default on Linux - measured).
     */
    public function hasTable(string $table): bool
    {
        return $this->rows('SELECT 1 FROM information_schema.TABLES WHERE ' . self::TABLE . ' AND TABLE_NAME = ?', [$table]) !== [];
    }

    /**
     * The columns of a table, in their order.
     *
     * - type: the column type as MariaDB writes it (`bigint(20)`, `varchar(100)`, `decimal(10,2)`;
     *   a JSON column is `longtext`, with a CHECK constraint of its name)
     * - nullable: whether it takes NULL
     * - default: the default as SQL, as MariaDB writes it - `NULL`, a string quoted and escaped
     *   (`'it''s'`, `'a\\b'`), a number (`0`, `1.50`), an expression (`current_timestamp()`);
     *   null when the column has no default (MariaDB 12.3 writes a binary default as `x'41'`,
     *   10.11 and 11.4 as `'A'`)
     * - extra: `auto_increment`, `on update current_timestamp()`, `VIRTUAL GENERATED`, ... or ''
     *
     *
     * @throws QueryException When the current database has no such table, or shows none of its
     *                        columns (no privilege on one - a table has at least one column - or
     *                        the table was dropped meanwhile)
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, extra: string}>
     */
    public function columns(string $table): array
    {
        $columns = [];
        foreach ($this->ofTable('columns', $table, 6, '7', 'SELECT 1, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?') as $row) {
            $columns[] = [
                'name' => (string) $row[0],
                'type' => (string) $row[1],
                'nullable' => $row[2] === 'YES',
                'default' => $row[3] === null ? null : (string) $row[3],
                'extra' => (string) $row[4],
            ];
        }
        if ($columns === []) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('columns(): the current database shows no column of table "%s" (no SELECT, INSERT, UPDATE or REFERENCES privilege on one, or the table was dropped meanwhile)', $table)
            );
        }

        return $columns;
    }

    /**
     * The indexes of a table - the primary key first, then by name -, each with
     * its columns in index order. A UNIQUE without a name of its own is named after its first
     * column. Not shown: a prefix length (`KEY (s(10))` is on `s`), a descending part, whether
     * the index is ignored, its type (FULLTEXT, SPATIAL).
     *
     *
     * @throws QueryException When the current database has no such table
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    public function indexes(string $table): array
    {
        /** @var array<string, array{name: string, columns: list<string>, unique: bool, primary: bool}> $indexes */
        $indexes = [];
        foreach ($this->ofTable('indexes', $table, 6, '5, 2, 6, 7', "SELECT 1, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, BINARY INDEX_NAME <> 'PRIMARY', BINARY INDEX_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?") as $row) {
            $name = (string) $row[0];
            $indexes[$name] ??= ['name' => $name, 'columns' => [], 'unique' => $row[2] === 0, 'primary' => $name === 'PRIMARY'];
            $indexes[$name]['columns'][] = (string) $row[1];
        }

        return array_values($indexes);
    }

    /**
     * The constraints of a table - the primary key first, then by name, then by type (a column's
     * CHECK and a UNIQUE on it can share a name) -, with their type: `PRIMARY KEY`, `UNIQUE`,
     * `FOREIGN KEY` or `CHECK` (a JSON column brings a CHECK of its own name).
     *
     * Read from KEY_COLUMN_USAGE and CHECK_CONSTRAINTS, not TABLE_CONSTRAINTS: that view shows
     * nothing to a user with SELECT alone, and on 10.11 nothing with a privilege on the table
     * alone (measured); these two show the same for every privilege on all three versions.
     *
     *
     * @throws QueryException When the current database has no such table
     *
     * @return list<array{name: string, type: string}>
     */
    public function constraints(string $table): array
    {
        // a key by its first column: one row per key, no name compared; a key shows with all its
        // columns or not at all (measured). Only the primary key is named 'PRIMARY' in its bytes
        // (1280 for any other key, measured; `PRÍMARY` is a name of its own)
        $type = "CASE WHEN REFERENCED_TABLE_NAME IS NOT NULL THEN 'FOREIGN KEY' WHEN BINARY CONSTRAINT_NAME = 'PRIMARY' THEN 'PRIMARY KEY' ELSE 'UNIQUE' END";
        $constraints = [];
        foreach ($this->ofTable(
            'constraints',
            $table,
            4,
            '4, 2, 5, 3',
            sprintf("SELECT 1, CONSTRAINT_NAME, %1\$s, %1\$s <> 'PRIMARY KEY', BINARY CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND ORDINAL_POSITION = 1", $type),
            // CHECK_CONSTRAINTS has no TABLE_SCHEMA; CONSTRAINT_SCHEMA narrows its scan (EXPLAIN, measured)
            "SELECT 1, CONSTRAINT_NAME, 'CHECK', 1, BINARY CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        ) as $row) {
            $constraints[] = ['name' => (string) $row[0], 'type' => (string) $row[1]];
        }

        return $constraints;
    }

    /**
     * The rows of information_schema views about one table, read in one statement together with
     * the table itself: a row from TABLES marks that the current database has it as a table, base
     * or system-versioned (a view has columns, but no such row), then the views' rows. Each part
     * looks the name up in its own WHERE, bound: a bound name is compared as the server compares
     * table names (with case where lower_case_table_names=0), a join of two views compares without
     * case and would mix "Users" into "users" (measured).
     *
     * @param int $width How many columns each part selects after its leading 1
     * @param string $order The order after the marker, as positions in a part's columns (its 1 is
     *                      position 1)
     * @param string ...$parts Each a SELECT of 1 and $width columns from a view, with `?` for the
     *                         table's name in its WHERE
     *
     * @throws QueryException When the current database has no such table
     *
     * @return list<list<mixed>> The views' rows without their leading 1
     */
    private function ofTable(string $method, string $table, int $width, string $order, string ...$parts): array
    {
        $rows = $this->rows(
            'SELECT 0 AS part' . str_repeat(', NULL', $width) . ' FROM information_schema.TABLES WHERE ' . self::TABLE . ' AND TABLE_NAME = ?'
            . ' UNION ALL ' . implode(' UNION ALL ', $parts)
            . ' ORDER BY 1, ' . $order,
            array_fill(0, 1 + count($parts), $table)
        );
        if (($rows[0][0] ?? null) !== 0) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s(): the current database has no table "%s"', $method, $table)
            );
        }

        $metadata = [];
        foreach (array_slice($rows, 1) as $row) {
            $metadata[] = array_slice($row, 1);
        }

        return $metadata;
    }

    /**
     * @param list<string> $params
     *
     * @return list<list<mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        /** @var list<list<mixed>> */
        return $this->db->query($sql, $params)->fetchAll(PDO::FETCH_NUM);
    }
}
