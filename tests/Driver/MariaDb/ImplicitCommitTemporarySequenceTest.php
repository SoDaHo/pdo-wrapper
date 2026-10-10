<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PHPUnit\Framework\Attributes\DataProvider;
use Sodaho\PdoWrapper\Driver\ImplicitCommit;
use Sodaho\PdoWrapper\Exception\ImplicitCommitException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;

/**
 * Temporary sequences against the server. MariaDB exempts only the temporary table from the
 * implicit commit (stmt_causes_implicit_commit() in sql_parse.cc, the same on 10.11, 11.4 and
 * 12.3): a CREATE [OR REPLACE] TEMPORARY SEQUENCE commits the open transaction, a DROP TEMPORARY
 * SEQUENCE does not. A check that stops at TEMPORARY sends the CREATE; the library decides by the
 * word after TEMPORARY: inside a transaction it refuses the CREATE, and nothing is committed, while
 * the temporary tables and the DROP still run.
 */
class ImplicitCommitTemporarySequenceTest extends TransactionEndTestCase
{
    private const SEQUENCE = 'implicit_commit_sequence';

    private const SCRATCH = 'implicit_commit_scratch';

    protected function tearDown(): void
    {
        $this->db->getPdo()->exec('DROP TEMPORARY SEQUENCE IF EXISTS ' . self::SEQUENCE);
        $this->db->getPdo()->exec('DROP TEMPORARY TABLE IF EXISTS ' . self::SCRATCH);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function temporarySequences(): array
    {
        return [
            'create temporary sequence' => ['CREATE TEMPORARY SEQUENCE ' . self::SEQUENCE, 'CREATE'],
            'create or replace temporary sequence' => ['CREATE OR REPLACE TEMPORARY SEQUENCE ' . self::SEQUENCE, 'CREATE'],
            'a MariaDB comment around SEQUENCE' => ['CREATE TEMPORARY /*M!100100 SEQUENCE */ ' . self::SEQUENCE, ImplicitCommit::UNJUDGED],
        ];
    }

    /**
     * On raw PDO, inside a transaction, each statement commits the transaction before it runs: the
     * row written before it is there for another connection, and no transaction is open any more.
     * The sequence was created, in this session.
     */
    #[DataProvider('temporarySequences')]
    public function testOnRawPdoTheServerCommitsImplicitly(string $sql): void
    {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO ' . self::TABLE . " (id, name) VALUES (1, 'before')");
        $statement = $pdo->query($sql);
        $this->assertNotFalse($statement);
        $statement->closeCursor();

        $this->assertSame(0, (int) $this->db->query('SELECT @@in_transaction')->fetchColumn(), 'committed implicitly');
        $this->assertSame([1], array_column($this->observer->findAll(self::TABLE), 'id'), 'the row before it is committed');
        $this->assertFalse($pdo->inTransaction(), 'PDO knows it as well');
        $this->assertSame(1, (int) $this->db->query('SELECT NEXTVAL(' . self::SEQUENCE . ')')->fetchColumn(), 'the sequence was created');
    }

    /**
     * Inside a transaction: refused before it is sent, the transaction still open on the server,
     * and the rollback after it is confirmed - the row written before is not there, and the
     * session knows no such sequence.
     */
    #[DataProvider('temporarySequences')]
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
        $this->assertSame(1, (int) $this->db->query('SELECT @@in_transaction')->fetchColumn(), 'the transaction is still open');
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
        try {
            $this->db->query('SELECT NEXTVAL(' . self::SEQUENCE . ')');
            $this->fail('Expected QueryException: no such sequence');
        } catch (QueryException $e) {
            $this->assertSame(4091, $e->driverCode, 'Unknown SEQUENCE: nothing was created');
        }
    }

    /**
     * What commits nothing still runs inside the transaction: CREATE [OR REPLACE] TEMPORARY TABLE,
     * DROP TEMPORARY TABLE and DROP TEMPORARY SEQUENCE (the sequence created before the
     * transaction) - the transaction goes on, and its rollback takes the row back.
     */
    public function testTheTemporaryTablesAndTheDropOfATemporarySequenceRun(): void
    {
        $this->db->execute('CREATE TEMPORARY SEQUENCE ' . self::SEQUENCE);
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'rolled back']);
        $this->db->execute('CREATE TEMPORARY TABLE ' . self::SCRATCH . ' (id INT)');
        $this->db->execute('CREATE OR REPLACE TEMPORARY TABLE ' . self::SCRATCH . ' (id INT)');
        $this->db->execute('DROP TEMPORARY TABLE ' . self::SCRATCH);
        $this->db->execute('DROP TEMPORARY SEQUENCE ' . self::SEQUENCE);
        $this->assertSame(1, (int) $this->db->query('SELECT @@in_transaction')->fetchColumn(), 'nothing committed');
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
    }
}
