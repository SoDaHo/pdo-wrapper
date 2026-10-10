<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\ImplicitCommit;
use Sodaho\PdoWrapper\Exception\ImplicitCommitException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;

/**
 * Inside a transaction the library began, a statement that commits implicitly (DDL, LOCK TABLES, an
 * account statement) is refused before it is sent: MariaDB would commit the transaction before the
 * statement runs - also when it then fails - and what the transaction does afterwards would run in
 * autocommit. The transaction stays open and intact. Outside of a transaction the statement runs.
 */
class ImplicitCommitTest extends TransactionEndTestCase
{
    private const DDL_TABLE = 'implicit_commit_ddl';

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
     * A CREATE TABLE inside transaction(): refused with only 'query.before' told, the table does not
     * exist, and the transaction goes on - the insert after it and the commit go through, both rows
     * are committed together.
     */
    public function testADdlStatementIsRefusedAndTheTransactionGoesOn(): void
    {
        $told = [];
        foreach (['query.before', 'query', 'error'] as $event) {
            $this->db->on($event, static function (array $data) use ($event, &$told): void {
                if (str_contains((string) $data['sql'], self::DDL_TABLE)) {
                    $told[] = $event;
                }
            });
        }
        $refused = null;

        $this->db->transaction(function (DatabaseInterface $db) use (&$refused): void {
            $db->insert(self::TABLE, ['id' => 1, 'name' => 'before']);
            try {
                $db->execute('CREATE TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)');
                $this->fail('Expected ImplicitCommitException');
            } catch (ImplicitCommitException $e) {
                $refused = $e;
            }
            $this->assertNotNull($db->currentTransaction(), 'the transaction is still open');
            $this->assertTrue($db->inTransaction());
            $db->insert(self::TABLE, ['id' => 2, 'name' => 'after']);
        });

        $this->assertNotNull($refused);
        $this->assertSame('CREATE', $refused->statement);
        $this->assertSame('Query refused: the statement would commit the transaction implicitly', $refused->getMessage());
        $this->assertStringStartsWith('Not sent: CREATE commits the open transaction implicitly', (string) $refused->getDebugMessage());
        $this->assertSame([null, null], [$refused->sqlState, $refused->driverCode], 'no database failure stands behind it');
        $this->assertSame(['query.before'], $told, 'neither query nor error: nothing was sent');
        $this->assertSame([], $this->observer->query("SHOW TABLES LIKE '" . self::DDL_TABLE . "'")->fetchAll(), 'the table was not created');
        $this->assertSame(['commit', 'end'], $this->events);
        $this->assertSame([self::COMMITTED], array_column($this->ends, 'outcome'));
        $this->assertVisible([1, 2]);
    }

    /**
     * A DDL statement that would fail (the table exists) commits the transaction on MariaDB all the
     * same; refused, it commits nothing: the rollback is confirmed, the row before it is not there.
     * So is CREATE TABLE ... SELECT - refused like any CREATE, before it is sent. Sent, such a
     * statement could run into a deadlock of its own after its implicit commit and be told
     * 'rolled_back' over committed rows; this test produces no deadlock (no second connection): it
     * pins the refusal, not that sequence.
     */
    public function testADdlStatementThatWouldFailAndCreateTableSelectCommitNothing(): void
    {
        foreach (['CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)', 'CREATE TABLE ' . self::DDL_TABLE . ' AS SELECT * FROM ' . self::TABLE] as $n => $ddl) {
            $this->db->beginTransaction();
            $this->db->insert(self::TABLE, ['id' => 1 + $n, 'name' => 'before']);
            try {
                $this->db->execute($ddl);
                $this->fail('Expected ImplicitCommitException');
            } catch (ImplicitCommitException $e) {
                $this->assertSame('CREATE', $e->statement);
            }
            if ($this->db->currentTransaction() !== null || $this->db->inTransaction()) {
                $this->db->rollback();
            }
        }

        $this->assertSame([self::ROLLED_BACK, self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertSame(['rollback', 'end', 'rollback', 'end'], $this->events);
        $this->assertVisible([], 'nothing was committed');
    }

    /**
     * Comments before the statement do not hide it - an executable comment there is refused
     * unjudged -, and the other kinds are refused as well: TRUNCATE, LOCK TABLES, an account statement.
     */
    public function testCommentsDoNotHideTheStatementAndTheOtherKindsAreRefused(): void
    {
        $this->db->beginTransaction();
        foreach ([
            '/* a note */ DROP TABLE ' . self::TABLE => 'DROP',
            "-- a note\nTRUNCATE TABLE " . self::TABLE => 'TRUNCATE',
            '/*!50100 ALTER TABLE ' . self::TABLE . ' ADD c INT */' => ImplicitCommit::UNJUDGED,
            'LOCK TABLES ' . self::TABLE . ' WRITE' => 'LOCK',
            "GRANT SELECT ON nothing.* TO 'pdo_wrapper_nobody'" => 'GRANT',
        ] as $sql => $statement) {
            try {
                $this->db->execute($sql);
                $this->fail('Expected ImplicitCommitException: ' . $sql);
            } catch (ImplicitCommitException $e) {
                $this->assertSame($statement, $e->statement, $sql);
            }
        }
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'still in the transaction']);
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([], 'the table was neither dropped nor truncated, and nothing was committed');
    }

    /**
     * A TEMPORARY table commits nothing and is not refused: the transaction it was created in is
     * rolled back as usual.
     */
    public function testTemporaryTablesAreNotRefused(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'rolled back']);
        $this->db->execute('CREATE TEMPORARY TABLE implicit_commit_scratch (id INT)');
        $this->db->execute('DROP TEMPORARY TABLE implicit_commit_scratch');
        $this->db->rollback();

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'));
        $this->assertVisible([]);
    }

    /**
     * A documented limit: SQL that steers transactions itself is neither refused nor seen. START
     * TRANSACTION commits the open transaction implicitly and opens the next one; the library takes
     * that one for its own, and its rollback tells 'rolled_back' - although the row before it is
     * committed.
     */
    public function testTransactionControlSentAsSqlIsNotSeen(): void
    {
        try {
            $this->db->transaction(static function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'committed by START TRANSACTION']);
                $db->execute('START TRANSACTION');
                $db->insert(self::TABLE, ['id' => 2, 'name' => 'rolled back']);
                throw new RuntimeException('the callback fails');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // the callback's
        }

        $this->assertSame([self::ROLLED_BACK], array_column($this->ends, 'outcome'), 'what the library reports');
        $this->assertVisible([1], 'what is in the database');
    }

    /**
     * Outside of a transaction - and in one begun on raw PDO, which the library does not hold - the
     * statement runs as it always did.
     */
    public function testOutsideATransactionTheLibraryBeganTheStatementRuns(): void
    {
        $this->db->execute('CREATE TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)');
        $this->db->execute('DROP TABLE ' . self::DDL_TABLE);

        $this->pdo->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'committed by the DDL statement']);
        $this->db->execute('CREATE TABLE ' . self::DDL_TABLE . ' (id INT PRIMARY KEY)');
        $this->assertFalse($this->db->inTransaction(), 'the DDL statement committed the raw transaction');

        $this->assertVisible([1]);
    }
}
