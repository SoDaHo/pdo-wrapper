<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Schema\Schema;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

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

    private function schema(): Schema
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
     * upper case first, never mixed - hasTable(), columns() and constraints() take the name as
     * written.
     */
    public function testTableNamesThatDifferInCase(): void
    {
        if ($this->db->query('SELECT @@lower_case_table_names')->fetchColumn() !== 0) {
            $this->markTestSkipped('table names are folded on this server');
        }
        $this->db->execute('DROP TABLE IF EXISTS Meta_Case');
        $this->db->execute('DROP TABLE IF EXISTS meta_case');
        try {
            $this->db->execute('CREATE TABLE meta_case (id INT PRIMARY KEY, a INT CHECK (a > 0))');
            $this->db->execute('CREATE TABLE Meta_Case (ID INT PRIMARY KEY, b INT UNIQUE)');

            $this->assertSame(['Meta_Case', 'meta_case'], array_values(array_filter($this->schema()->tables(), static fn (string $table): bool => strtolower($table) === 'meta_case')));
            $this->assertFalse($this->schema()->hasTable('META_CASE'));
            $this->assertSame(['id', 'a'], array_column($this->schema()->columns('meta_case'), 'name'));
            $this->assertSame(['ID', 'b'], array_column($this->schema()->columns('Meta_Case'), 'name'));
            $this->assertSame([['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'], ['name' => 'a', 'type' => 'CHECK']], $this->schema()->constraints('meta_case'));
            $this->assertSame([['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'], ['name' => 'b', 'type' => 'UNIQUE']], $this->schema()->constraints('Meta_Case'));
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
            $this->db->execute('CREATE TABLE pdo_wrapper_schema_other.meta_parent (other_id INT PRIMARY KEY, other_col INT, UNIQUE KEY other_unique (other_col), CONSTRAINT other_check CHECK (other_col > 0))');

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

    /**
     * Index and constraint names in the server's order, without case; a key over two columns is one
     * constraint; a column's CHECK and a UNIQUE may share a name, and a foreign key the name of the
     * UNIQUE index it uses - the type orders those.
     */
    public function testIndexAndConstraintNamesOrderWithoutCase(): void
    {
        try {
            $this->db->execute('CREATE TABLE meta_order (id INT PRIMARY KEY, a INT CHECK (a > 0), b INT, pid BIGINT, KEY Zeta (b), KEY idx (a), UNIQUE KEY Beta (a, b), UNIQUE KEY a (a), UNIQUE KEY x (pid), CONSTRAINT x FOREIGN KEY (pid) REFERENCES meta_parent (id), CONSTRAINT Alpha CHECK (b > 0))');

            $this->assertSame(['PRIMARY', 'a', 'Beta', 'idx', 'x', 'Zeta'], array_column($this->schema()->indexes('meta_order'), 'name'));
            $this->assertSame([
                ['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'],
                ['name' => 'a', 'type' => 'CHECK'],
                ['name' => 'a', 'type' => 'UNIQUE'],
                ['name' => 'Alpha', 'type' => 'CHECK'],
                ['name' => 'Beta', 'type' => 'UNIQUE'],
                ['name' => 'x', 'type' => 'FOREIGN KEY'],
                ['name' => 'x', 'type' => 'UNIQUE'],
            ], $this->schema()->constraints('meta_order'));
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS meta_order');
        }
    }

    /**
     * Names that differ in accents or a trailing space only are names of their own (the server
     * takes them, measured): never merged, ordered by their bytes where the server's order has
     * them equal; a UNIQUE named `PRÍMARY` is no primary key.
     */
    public function testNamesThatDifferInAccentsStaySeparate(): void
    {
        try {
            $this->db->execute('CREATE TABLE meta_accent (id INT PRIMARY KEY, a INT, b INT, c INT, pid BIGINT, qid BIGINT, UNIQUE KEY `é` (b), UNIQUE KEY e (a), UNIQUE KEY `PRÍMARY` (c), CONSTRAINT `chk_é` CHECK (a > 0), CONSTRAINT `chk_e ` CHECK (b > 0), CONSTRAINT chk_e CHECK (c > 0), CONSTRAINT `fk_é` FOREIGN KEY (qid) REFERENCES meta_parent (id), CONSTRAINT fk_e FOREIGN KEY (pid) REFERENCES meta_parent (id))');

            $this->assertSame([
                ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true],
                ['name' => 'e', 'columns' => ['a'], 'unique' => true, 'primary' => false],
                ['name' => 'é', 'columns' => ['b'], 'unique' => true, 'primary' => false],
                ['name' => 'fk_e', 'columns' => ['pid'], 'unique' => false, 'primary' => false],
                ['name' => 'fk_é', 'columns' => ['qid'], 'unique' => false, 'primary' => false],
                ['name' => 'PRÍMARY', 'columns' => ['c'], 'unique' => true, 'primary' => false],
            ], $this->schema()->indexes('meta_accent'));
            $this->assertSame([
                ['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'],
                ['name' => 'chk_e', 'type' => 'CHECK'],
                ['name' => 'chk_e ', 'type' => 'CHECK'],
                ['name' => 'chk_é', 'type' => 'CHECK'],
                ['name' => 'e', 'type' => 'UNIQUE'],
                ['name' => 'é', 'type' => 'UNIQUE'],
                ['name' => 'fk_e', 'type' => 'FOREIGN KEY'],
                ['name' => 'fk_é', 'type' => 'FOREIGN KEY'],
                ['name' => 'PRÍMARY', 'type' => 'UNIQUE'],
            ], $this->schema()->constraints('meta_accent'));
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS meta_accent');
        }
    }

    public function testAnUnknownTableThrows(): void
    {
        $this->db->execute('CREATE SEQUENCE meta_sequence');
        try {
            foreach (['columns', 'indexes', 'constraints'] as $method) {
                foreach (['no_such_table', 'meta_view', 'meta_sequence'] as $table) {
                    try {
                        $this->schema()->{$method}($table);
                        $this->fail("Expected QueryException: {$method}({$table})");
                    } catch (QueryException $e) {
                        $this->assertSame(sprintf('%s(): the current database has no table "%s"', $method, $table), $e->getDebugMessage());
                    }
                }
            }
        } finally {
            $this->db->execute('DROP SEQUENCE IF EXISTS meta_sequence');
        }
    }

    /**
     * What a user with fewer privileges sees (measured the same on 10.11, 11.4 and 12.3): with
     * SELECT on the database all of it - TABLE_CONSTRAINTS would show no constraint -; with SELECT
     * on the table no CHECK; with one column only that column and the index and key on it alone -
     * a key over a further column not, also where its first column has the privilege (constraints()
     * reads a key by its first column); with DELETE no column, which columns() reports instead of
     * returning none.
     */
    public function testWhatTheUsersPrivilegesShow(): void
    {
        $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        try {
            $this->db->execute("DROP USER IF EXISTS 'pdo_wrapper_schema'@'%'");
        } catch (QueryException $e) {
            $this->markTestSkipped('the test user may not manage users: ' . $e->getDebugMessage());
        }
        try {
            $everything = $this->schemaAs(sprintf('SELECT ON `%s`.*', $database));
            $this->assertSame($this->schema()->columns('meta_parent'), $everything->columns('meta_parent'));
            $this->assertSame($this->schema()->indexes('meta_parent'), $everything->indexes('meta_parent'));
            $this->assertSame($this->schema()->constraints('meta_parent'), $everything->constraints('meta_parent'));
            $this->assertSame($this->schema()->constraints('meta_child'), $everything->constraints('meta_child'));

            $table = $this->schemaAs(sprintf('SELECT ON `%s`.meta_parent', $database));
            $this->assertSame(['PRIMARY', 'email', 'idx_multi'], array_column($table->indexes('meta_parent'), 'name'));
            $this->assertSame([['name' => 'PRIMARY', 'type' => 'PRIMARY KEY'], ['name' => 'email', 'type' => 'UNIQUE']], $table->constraints('meta_parent'), 'no CHECK');
            $this->assertFalse($table->hasTable('meta_child'));

            $column = $this->schemaAs(sprintf('SELECT (email, n) ON `%s`.meta_parent', $database));
            $this->assertSame(['email', 'n'], array_column($column->columns('meta_parent'), 'name'));
            $this->assertSame(['email'], array_column($column->indexes('meta_parent'), 'name'), 'idx_multi is on n and s');
            $this->assertSame([['name' => 'email', 'type' => 'UNIQUE']], $column->constraints('meta_parent'));

            $this->db->execute('CREATE TABLE meta_keys (id INT PRIMARY KEY, n INT, s INT, UNIQUE KEY u_ns (n, s), CONSTRAINT fk_ns FOREIGN KEY (n, s) REFERENCES meta_keys (n, s))');
            $first = $this->schemaAs(sprintf('SELECT (id, n) ON `%s`.meta_keys', $database));
            $this->assertSame(['id', 'n'], array_column($first->columns('meta_keys'), 'name'));
            $this->assertSame(['PRIMARY'], array_column($first->indexes('meta_keys'), 'name'), 'u_ns is on n and s');
            $this->assertSame([['name' => 'PRIMARY', 'type' => 'PRIMARY KEY']], $first->constraints('meta_keys'), 'u_ns and fk_ns are on n and s');
            $this->assertSame(['PRIMARY', 'fk_ns', 'u_ns'], array_column($this->schema()->constraints('meta_keys'), 'name'), 'all of them, for root');

            $delete = $this->schemaAs(sprintf('DELETE ON `%s`.meta_parent', $database));
            $this->assertSame(['PRIMARY', 'email', 'idx_multi'], array_column($delete->indexes('meta_parent'), 'name'));
            try {
                $delete->columns('meta_parent');
                $this->fail('Expected QueryException was not thrown');
            } catch (QueryException $e) {
                $this->assertSame('columns(): the current database shows no column of table "meta_parent" (no SELECT, INSERT, UPDATE or REFERENCES privilege on one, or the table was dropped meanwhile)', $e->getDebugMessage());
            }
        } finally {
            $this->db->execute("DROP USER IF EXISTS 'pdo_wrapper_schema'@'%'");
            $this->db->execute('DROP TABLE IF EXISTS meta_keys');
        }
    }

    /**
     * schema() on a new connection of a user with just this privilege.
     */
    private function schemaAs(string $grant): Schema
    {
        $this->db->execute("DROP USER IF EXISTS 'pdo_wrapper_schema'@'%'");
        $this->db->execute("CREATE USER 'pdo_wrapper_schema'@'%' IDENTIFIED BY 'schema'");
        $this->db->execute(sprintf("GRANT %s TO 'pdo_wrapper_schema'@'%%'", $grant));

        return Database::mariadb(['username' => 'pdo_wrapper_schema', 'password' => 'schema'] + TestEnvironment::mariadb())->schema();
    }
}
