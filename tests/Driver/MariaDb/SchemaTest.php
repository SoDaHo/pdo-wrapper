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

        $this->assertContains('meta_parent', $tables);
        $this->assertContains('meta_child', $tables);
        $this->assertNotContains('meta_view', $tables);
        $this->assertSame($tables, array_values(array_unique($tables)));
        $sorted = $tables;
        sort($sorted);
        $this->assertSame($sorted, $tables, 'by name');
        $this->assertTrue($this->schema()->hasTable('meta_parent'));
        $this->assertFalse($this->schema()->hasTable('meta_view'));
        $this->assertFalse($this->schema()->hasTable('no_such_table'));
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
