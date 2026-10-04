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
 * information_schema shows what the user has privileges on: a table without any is no table
 * here, and columns without privileges are left out. It is no snapshot: a table another
 * connection changes at that moment can show either state.
 */
final class Schema
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The tables of the current database, in the server's order of names (utf8mb3_general_ci:
     * without case or accents, `_` after the letters; where two names differ in case only -
     * lower_case_table_names=0 - the upper case first).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $names = [];
        foreach ($this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED') ORDER BY TABLE_NAME, BINARY TABLE_NAME", []) as $row) {
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
        return $this->rows("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED') AND TABLE_NAME = ?", [$table]) !== [];
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
     * @throws QueryException When the current database has no such table
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, extra: string}>
     */
    public function columns(string $table): array
    {
        $columns = [];
        foreach ($this->ofTable('columns', $table, 'COLUMNS', ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA'], ['ORDINAL_POSITION']) as $row) {
            $columns[] = [
                'name' => (string) $row[0],
                'type' => (string) $row[1],
                'nullable' => $row[2] === 'YES',
                'default' => $row[3] === null ? null : (string) $row[3],
                'extra' => (string) $row[4],
            ];
        }

        return $columns;
    }

    /**
     * The indexes of a table - the primary key first, then by name (without case) -, each with
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
        foreach ($this->ofTable('indexes', $table, 'STATISTICS', ['INDEX_NAME', 'COLUMN_NAME', 'NON_UNIQUE'], ["INDEX_NAME <> 'PRIMARY'", 'INDEX_NAME', 'SEQ_IN_INDEX']) as $row) {
            $name = (string) $row[0];
            $indexes[$name] ??= ['name' => $name, 'columns' => [], 'unique' => $row[2] === 0, 'primary' => $name === 'PRIMARY'];
            $indexes[$name]['columns'][] = (string) $row[1];
        }

        return array_values($indexes);
    }

    /**
     * The constraints of a table - the primary key first, then by name (without case) -, with
     * their type: `PRIMARY KEY`, `UNIQUE`,
     * `FOREIGN KEY` or `CHECK` (a JSON column brings a CHECK of its own name).
     *
     *
     * @throws QueryException When the current database has no such table
     *
     * @return list<array{name: string, type: string}>
     */
    public function constraints(string $table): array
    {
        $constraints = [];
        foreach ($this->ofTable('constraints', $table, 'TABLE_CONSTRAINTS', ['CONSTRAINT_NAME', 'CONSTRAINT_TYPE'], ["CONSTRAINT_TYPE <> 'PRIMARY KEY'", 'CONSTRAINT_NAME', 'CONSTRAINT_TYPE']) as $row) {
            $constraints[] = ['name' => (string) $row[0], 'type' => (string) $row[1]];
        }

        return $constraints;
    }

    /**
     * The rows of an information_schema view about one table, read in one statement together with
     * the table itself: a row from TABLES marks that the current database has it as a base table
     * (a view has columns, but no such row), then the view's rows. Each part looks the name up in
     * its own WHERE, bound: a bound name is compared as the server compares table names (with case
     * where lower_case_table_names=0), a join of the two views compares without case and would mix
     * "Users" into "users" (measured).
     *
     * @param list<string> $columns What to read from the view
     * @param list<string> $order The view's order, after the marker
     *
     * @throws QueryException When the current database has no such base table
     *
     * @return list<list<mixed>> The view's rows: $columns in order, then the order keys
     */
    private function ofTable(string $method, string $table, string $view, array $columns, array $order): array
    {
        $keys = [];
        foreach ($order as $i => $expression) {
            $keys[] = sprintf('%s AS k%d', $expression, $i);
        }
        $marker = array_fill(0, count($columns) + count($order), 'NULL');
        $positions = range(2 + count($columns), 1 + count($columns) + count($order));
        $rows = $this->rows(
            sprintf(
                "SELECT 0 AS part, %s FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED') AND TABLE_NAME = ?"
                // TABLE_SCHEMA in every view, also TABLE_CONSTRAINTS: only it narrows the scan to
                // this database (CONSTRAINT_SCHEMA scans one database more - EXPLAIN, measured)
                . ' UNION ALL SELECT 1, %s, %s FROM information_schema.%s WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY 1, %s',
                implode(', ', $marker),
                implode(', ', $columns),
                implode(', ', $keys),
                $view,
                implode(', ', $positions)
            ),
            [$table, $table]
        );
        if (($rows[0][0] ?? null) !== 0) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s(): the current database has no table "%s"', $method, $table)
            );
        }

        $metadata = [];
        foreach (array_slice($rows, 1) as $row) {
            $metadata[] = array_slice($row, 1); // $columns, then the order keys
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
