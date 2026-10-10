<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Statements the implicit-commit check lets through, measured against the server: LOAD DATA LOCAL
 * INFILE and LOAD XML LOCAL INFILE (the START TRANSACTION page of the MariaDB documentation names
 * LOAD DATA among the statements that commit, the list of those statements does not) and the
 * ANALYZE that runs a statement and reports on it. Inside a transaction the library began they run
 * - the rows are loaded, the report comes back - and the rollback takes everything back: nothing
 * was committed. LOAD DATA INFILE without LOCAL reads a file on the server (secure_file_priv, the
 * FILE privilege): not pinned here, measured by hand in a throwaway container.
 */
class ImplicitCommitMeasuredTest extends ContractTestCase
{
    private const TABLE = 'implicit_commit_measured';

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->file = (string) tempnam(sys_get_temp_dir(), 'pdo-wrapper-load-');
        file_put_contents($this->file, "2,loaded-2\n3,loaded-3\n4,loaded-4\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testLoadDataLocalInsideATransactionCommitsNothing(): void
    {
        $this->assertLoadedAndRolledBack(
            static fn (string $file): string => "LOAD DATA LOCAL INFILE {$file} INTO TABLE " . self::TABLE . " FIELDS TERMINATED BY ',' (id, name)",
            3
        );
    }

    public function testLoadXmlLocalInsideATransactionCommitsNothing(): void
    {
        file_put_contents($this->file, '<?xml version="1.0"?><rows><row id="2" name="loaded-2"/><row id="3" name="loaded-3"/></rows>');

        $this->assertLoadedAndRolledBack(
            static fn (string $file): string => "LOAD XML LOCAL INFILE {$file} INTO TABLE " . self::TABLE . " ROWS IDENTIFIED BY '<row>'",
            2
        );
    }

    /**
     * Inside a transaction the library began: a row, then the LOAD statement $load builds for the
     * quoted file - the rows are loaded and the transaction stays open -, then an exception that
     * rolls it back. Nothing is left: the LOAD committed nothing.
     *
     * @param \Closure(string): string $load
     */
    private function assertLoadedAndRolledBack(\Closure $load, int $rows): void
    {
        $db = $this->connect(['options' => [\Pdo\Mysql::ATTR_LOCAL_INFILE => true]]);
        if ((int) $db->query('SELECT @@GLOBAL.local_infile')->fetchColumn() !== 1) {
            $this->markTestSkipped('the server does not allow LOAD ... LOCAL (local_infile off)');
        }
        $seen = [];

        try {
            $db->transaction(function (DatabaseInterface $db) use (&$seen, $load): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
                $seen['loaded'] = $db->execute($load($db->getPdo()->quote($this->file)));
                $seen['inside'] = $db->table(self::TABLE)->count();
                $seen['open'] = (int) $db->query('SELECT @@in_transaction')->fetchColumn();
                throw new RuntimeException('roll it back');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('roll it back', $e->getMessage());
        }

        $this->assertSame(['loaded' => $rows, 'inside' => $rows + 1, 'open' => 1], $seen, 'the rows were loaded, the transaction stayed open');
        $this->assertSame(0, $this->db->table(self::TABLE)->count(), 'the rollback took the loaded rows and the row before them back');
    }

    public function testAnAnalyzeThatRunsAStatementCommitsNothing(): void
    {
        $db = $this->db;
        $reports = [];

        try {
            $db->transaction(static function (DatabaseInterface $db) use (&$reports): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
                foreach (['ANALYZE WITH c AS (SELECT id FROM ' . self::TABLE . ') SELECT * FROM c', 'ANALYZE VALUES (1)', 'ANALYZE (SELECT id FROM ' . self::TABLE . ')'] as $sql) {
                    $reports[] = count($db->query($sql)->fetchAll());
                }
                $reports[] = (int) $db->query('SELECT @@in_transaction')->fetchColumn();
                throw new RuntimeException('roll it back');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('roll it back', $e->getMessage());
        }

        $this->assertSame([1, 1, 1, 1], $reports, 'a report for each, the transaction still open');
        $this->assertSame(0, $this->db->table(self::TABLE)->count(), 'the rollback took the row back');
    }
}
