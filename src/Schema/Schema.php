<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Schema;

use PDO;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * What the current database holds, read from information_schema - its tables, their columns,
 * indexes and constraints. Read only: nothing here writes DDL. Created with
 * MariaDbDriver::schema(); every method asks the server anew.
 *
 * "The current database" is the connection's (DATABASE()). Views are no tables here.
 */
final class Schema
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The tables of the current database, by name.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $names = [];
        foreach ($this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", []) as $row) {
            $names[] = (string) $row[0];
        }

        return $names;
    }

    /**
     * Whether the current database has this table (compared as the server compares table names).
     */
    public function hasTable(string $table): bool
    {
        return $this->rows("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ?", [$table]) !== [];
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
        $this->assertTable('columns', $table);
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]) as $row) {
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
     * column.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    public function indexes(string $table): array
    {
        $this->assertTable('indexes', $table);
        /** @var array<string, array{name: string, columns: list<string>, unique: bool, primary: bool}> $indexes */
        $indexes = [];
        foreach ($this->rows("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME <> 'PRIMARY', INDEX_NAME, SEQ_IN_INDEX", [$table]) as $row) {
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
        $this->assertTable('constraints', $table);
        $constraints = [];
        foreach ($this->rows("SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_TYPE <> 'PRIMARY KEY', CONSTRAINT_NAME, CONSTRAINT_TYPE", [$table]) as $row) {
            $constraints[] = ['name' => (string) $row[0], 'type' => (string) $row[1]];
        }

        return $constraints;
    }

    /**
     * @throws QueryException When the current database has no such table
     */
    private function assertTable(string $method, string $table): void
    {
        if (!$this->hasTable($table)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s(): the current database has no table "%s"', $method, $table)
            );
        }
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
