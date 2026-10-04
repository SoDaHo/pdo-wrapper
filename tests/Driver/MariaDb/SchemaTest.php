<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * schema(): the tables of the current database, their columns, indexes and constraints, as
 * information_schema reports them on MariaDB (the same on 10.11, 11.4 and 12.3 - measured).
 */
class SchemaTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAll();
        $this->db->execute("CREATE TABLE meta_parent (id BIGINT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL UNIQUE, n INT NOT NULL DEFAULT 0, s VARCHAR(10) DEFAULT 'it''s', z VARCHAR(10) DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, j JSON, v VARCHAR(100) AS (LOWER(email)) VIRTUAL, CONSTRAINT n_positive CHECK (n >= 0), KEY idx_multi (n, s))");
        $this->db->execute('CREATE TABLE meta_child (id INT PRIMARY KEY, parent_id BIGINT NOT NULL, CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES meta_parent(id))');
        $this->db->execute('CREATE VIEW meta_view AS SELECT id FROM meta_parent');
    }

    protected function tearDown(): void
    {
        $this->dropAll();
        parent::tearDown();
    }

    private function dropAll(): void
    {
        $this->db->execute('DROP VIEW IF EXISTS meta_view');
        $this->db->execute('DROP TABLE IF EXISTS meta_child');
        $this->db->execute('DROP TABLE IF EXISTS meta_parent');
    }

    private function schema(): \Sodaho\PdoWrapper\Schema\Schema
    {
        $this->assertInstanceOf(MariaDbDriver::class, $this->db);

        return $this->db->schema();
    }

    public function testTablesWithoutViews(): void
    {
        $tables = $this->schema()->tables();

        $this->assertSame(['meta_child', 'meta_parent'], array_values(array_filter($tables, static fn (string $table): bool => str_starts_with($table, 'meta_'))), 'by name, no view');
        $this->assertSame($tables, array_values(array_unique($tables)));
        $this->assertTrue($this->schema()->hasTable('meta_parent'));
        $this->assertFalse($this->schema()->hasTable('meta_view'));
        $this->assertFalse($this->schema()->hasTable('no_such_table'));
    }

    /**
     * Table names that differ in case only (lower_case_table_names=0, Linux): two tables, listed
     * upper case first, never mixed - hasTable() and columns() take the name as written.
     */
    public function testTableNamesThatDifferInCase(): void
    {
        if ($this->db->query('SELECT @@lower_case_table_names')->fetchColumn() !== 0) {
            $this->markTestSkipped('table names are folded on this server');
        }
        $this->db->execute('DROP TABLE IF EXISTS Meta_Case');
        $this->db->execute('DROP TABLE IF EXISTS meta_case');
        try {
            $this->db->execute('CREATE TABLE meta_case (id INT PRIMARY KEY, a INT)');
            $this->db->execute('CREATE TABLE Meta_Case (ID INT PRIMARY KEY, b INT)');

            $this->assertSame(['Meta_Case', 'meta_case'], array_values(array_filter($this->schema()->tables(), static fn (string $table): bool => strtolower($table) === 'meta_case')));
            $this->assertFalse($this->schema()->hasTable('META_CASE'));
            $this->assertSame(['id', 'a'], array_column($this->schema()->columns('meta_case'), 'name'));
            $this->assertSame(['ID', 'b'], array_column($this->schema()->columns('Meta_Case'), 'name'));
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS Meta_Case');
            $this->db->execute('DROP TABLE IF EXISTS meta_case');
        }
    }

    /**
     * A system-versioned table is a table (TABLE_TYPE 'SYSTEM VERSIONED'); a sequence is not.
     * The order is the server's: `_` after the letters.
     */
    public function testSystemVersionedTablesCountSequencesDoNot(): void
    {
        try {
            $this->db->execute('CREATE TABLE meta_versioned (id INT PRIMARY KEY, u INT UNIQUE) WITH SYSTEM VERSIONING');
            $this->db->execute('CREATE TABLE metaversions (id INT PRIMARY KEY)');
            $this->db->execute('CREATE SEQUENCE meta_sequence');

            $tables = array_values(array_filter($this->schema()->tables(), static fn (string $table): bool => str_starts_with($table, 'meta')));
            $this->assertSame(['metaversions', 'meta_child', 'meta_parent', 'meta_versioned'], $tables, '`_` after the letters');
            $this->assertTrue($this->schema()->hasTable('meta_versioned'));
            $this->assertFalse($this->schema()->hasTable('meta_sequence'));
            $this->assertSame(['id', 'u'], array_column($this->schema()->columns('meta_versioned'), 'name'));
            $this->assertSame([['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true], ['name' => 'u', 'columns' => ['u'], 'unique' => true, 'primary' => false]], $this->schema()->indexes('meta_versioned'));
            $this->assertSame([['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'], ['name' => 'u', 'type' => 'UNIQUE']], $this->schema()->constraints('meta_versioned'));
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS meta_versioned');
            $this->db->execute('DROP TABLE IF EXISTS metaversions');
            $this->db->execute('DROP SEQUENCE IF EXISTS meta_sequence');
        }
    }

    /**
     * A table of the same name in another database stays out: only the current database counts.
     */
    public function testATableOfTheSameNameInAnotherDatabaseStaysOut(): void
    {
        $this->db->execute('CREATE DATABASE IF NOT EXISTS pdo_wrapper_schema_other');
        try {
            $this->db->execute('CREATE TABLE pdo_wrapper_schema_other.meta_parent (other_id INT PRIMARY KEY, other_col INT, UNIQUE KEY other_unique (other_col))');

            $this->assertSame(['id', 'email', 'n', 's', 'z', 'created_at', 'j', 'v'], array_column($this->schema()->columns('meta_parent'), 'name'));
            $this->assertSame(['PRIMARY', 'email', 'idx_multi'], array_column($this->schema()->indexes('meta_parent'), 'name'));
            $this->assertSame(['PRIMARY', 'email', 'j', 'n_positive'], array_column($this->schema()->constraints('meta_parent'), 'name'));
        } finally {
            $this->db->execute('DROP DATABASE IF EXISTS pdo_wrapper_schema_other');
        }
    }

    /**
     * A temporary table that shadows a base table in this session: the base table is described.
     */
    public function testATemporaryTableLeavesTheBaseTableDescribed(): void
    {
        $this->db->execute('CREATE TEMPORARY TABLE meta_child (tid INT, temp_col INT, UNIQUE KEY tu (temp_col))');
        try {
            $this->assertSame(['id', 'parent_id'], array_column($this->schema()->columns('meta_child'), 'name'));
            $this->assertSame(['PRIMARY', 'fk_parent'], array_column($this->schema()->indexes('meta_child'), 'name'));
            $this->assertSame(['PRIMARY', 'fk_parent'], array_column($this->schema()->constraints('meta_child'), 'name'));
        } finally {
            $this->db->execute('DROP TEMPORARY TABLE meta_child');
        }
    }

    public function testColumnsAsMariaDbWritesThem(): void
    {
        $this->assertSame([
            ['name' => 'id', 'type' => 'bigint(20)', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment'],
            ['name' => 'email', 'type' => 'varchar(100)', 'nullable' => false, 'default' => null, 'extra' => ''],
            ['name' => 'n', 'type' => 'int(11)', 'nullable' => false, 'default' => '0', 'extra' => ''],
            ['name' => 's', 'type' => 'varchar(10)', 'nullable' => true, 'default' => "'it''s'", 'extra' => ''],
            ['name' => 'z', 'type' => 'varchar(10)', 'nullable' => true, 'default' => 'NULL', 'extra' => ''],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp()', 'extra' => 'on update current_timestamp()'],
            ['name' => 'j', 'type' => 'longtext', 'nullable' => true, 'default' => 'NULL', 'extra' => ''],
            ['name' => 'v', 'type' => 'varchar(100)', 'nullable' => true, 'default' => 'NULL', 'extra' => 'VIRTUAL GENERATED'],
        ], $this->schema()->columns('meta_parent'));
    }

    public function testIndexesPrimaryFirst(): void
    {
        $this->assertSame([
            ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'email', 'columns' => ['email'], 'unique' => true, 'primary' => false],
            ['name' => 'idx_multi', 'columns' => ['n', 's'], 'unique' => false, 'primary' => false],
        ], $this->schema()->indexes('meta_parent'));
        $this->assertSame('PRIMARY', $this->schema()->indexes('meta_child')[0]['name']);
    }

    public function testConstraints(): void
    {
        $this->assertSame([
            ['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'],
            ['name' => 'email', 'type' => 'UNIQUE'],
            ['name' => 'j', 'type' => 'CHECK'],
            ['name' => 'n_positive', 'type' => 'CHECK'],
        ], $this->schema()->constraints('meta_parent'));
        $this->assertSame([
            ['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'],
            ['name' => 'fk_parent', 'type' => 'FOREIGN KEY'],
        ], $this->schema()->constraints('meta_child'));
    }

    public function testAnUnknownTableThrows(): void
    {
        foreach (['columns', 'indexes', 'constraints'] as $method) {
            foreach (['no_such_table', 'meta_view'] as $table) {
                try {
                    $this->schema()->{$method}($table);
                    $this->fail("Expected QueryException: {$method}({$table})");
                } catch (QueryException $e) {
                    $this->assertSame(sprintf('%s(): the current database has no table "%s"', $method, $table), $e->getDebugMessage());
                }
            }
        }
    }
}
