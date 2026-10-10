<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * A statement the implicit-commit check lets through, measured against the server: the ANALYZE
 * that runs a statement and reports on it. Inside a transaction the library began it runs - the
 * report comes back - and the rollback takes everything back: nothing was committed.
 */
class ImplicitCommitMeasuredTest extends ContractTestCase
{
    private const TABLE = 'implicit_commit_measured';

    protected function setUp(): void
    {
        parent::setUp();
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
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
