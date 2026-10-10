<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\Exception\ImplicitCommitException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;

/**
 * The readings of a statement against the server: MariaDB ignores a MySQL version comment from 5.7
 * on, so `CREATE /*!50700 TEMPORARY *\/ TABLE` creates a table that is not temporary - it commits
 * implicitly, and is refused inside a transaction; SET DEFAULT ROLE commits as well (measured, not on
 * the documented list). Refused, they commit nothing: the row before them is rolled back.
 */
class ImplicitCommitReadingsTest extends TransactionEndTestCase
{
    private const DDL_TABLE = 'implicit_commit_readings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
    }

    protected function tearDown(): void
    {
        $this->db->getPdo()->exec('DROP TABLE IF EXISTS ' . self::DDL_TABLE);
        parent::tearDown();
    }

    /**
     * Outside of a transaction the statement runs - and what it creates is a base table, visible
     * to another connection: the TEMPORARY inside the version comment was not run.
     */
    public function testTheServerIgnoresAMysqlVersionComment(): void
    {
        $this->db->execute('CREATE /*!50700 TEMPORARY */ TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)');

        $this->assertCount(1, $this->observer->query("SHOW FULL TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll(), 'a base table, seen from another connection');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function statements(): array
    {
        return [
            'a version comment around TEMPORARY' => ['CREATE /*!50700 TEMPORARY */ TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)', 'CREATE'],
            'SET DEFAULT ROLE' => ['SET DEFAULT ROLE NONE', 'SET DEFAULT ROLE'],
        ];
    }

    /**
     * Inside a transaction: refused before it is sent, and the rollback after it is confirmed - the
     * row written before is not there, nothing was created.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statements')]
    public function testRefusedInsideATransactionItCommitsNothing(string $sql, string $statement): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
        try {
            $this->db->execute($sql);
            $this->fail('Expected ImplicitCommitException');
        } catch (ImplicitCommitException $e) {
            $this->assertSame($statement, $e->statement);
        }
        $this->assertTrue($this->db->inTransaction(), 'the transaction is still open');
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
        $this->assertSame([], $this->observer->query("SHOW TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll());
    }
}
