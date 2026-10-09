<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\TransactionEnd\TransactionEndTestCase;
use Sodaho\PdoWrapper\Tests\Support\ReadsPdoErrorInfo;

/**
 * A transaction this library began that the server has ended while PDO did not know - a DDL
 * statement committed it implicitly, failing or not: after it nothing more is sent (it would run
 * in autocommit and be committed on its own), and its end is 'lost', never 'rolled_back'. After a
 * failed statement the driver asks the server right away; what it finds is held until the
 * transaction is ended here.
 */
class TransactionGoneTest extends TransactionEndTestCase
{
    use ReadsPdoErrorInfo;

    /**
     * A failing DDL statement commits the open transaction. Right after the failure the driver
     * asks, and the statement the callback runs next is refused instead of being committed on its
     * own - the one that, in autocommit, could even deadlock and make the end look like a rollback.
     */
    public function testAStatementAfterAFailingDdlStatementIsNotSent(): void
    {
        $failure = null;
        $refused = null;
        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$failure, &$refused): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
                try {
                    $db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)'); // exists: fails, and commits
                } catch (QueryException $e) {
                    $failure = $e->getPrevious();
                }
                try {
                    $db->insert(self::TABLE, ['id' => 2, 'name' => 'after the DDL']);
                    $this->fail('Expected QueryException: not sent');
                } catch (QueryException $e) {
                    $refused = $e;
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
            $this->assertSame($failure, $e->getPrevious());
            $this->assertStringStartsWith('The server reports no transaction any more', (string) $e->getDebugMessage());
            $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $this->ends);
        }

        $this->assertNotNull($refused);
        $this->assertSame($failure, $refused->getPrevious(), 'the failure after which the transaction was found gone');
        $this->assertStringStartsWith('Not sent: the server ended the transaction this library began', (string) $refused->getDebugMessage());
        $this->assertSame(1050, $this->errorInfoBehind($refused, 1));
        $this->assertSame(['end'], $this->events, 'no commit and no rollback listener');
        $this->assertVisible([1], 'the row before the DDL statement is committed, the one after it was never sent');
    }

    /**
     * A row lock read after the failing DDL statement would end with its own statement, in
     * autocommit: it is refused as well, and a manual commit() is refused without a second question.
     */
    public function testALockingReadAfterAFailingDdlStatementIsNotSent(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
        try {
            $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // swallowed
        }
        $sql = [];
        $this->db->on('query', static function (array $data) use (&$sql): void {
            $sql[] = $data['sql'];
        });

        try {
            $this->db->table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $this->fail('Expected QueryException: not sent');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('Not sent:', (string) $e->getDebugMessage());
        }
        $this->pdo->failExec = true; // a second question would fail and say something else
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome, 'PDO knows the transaction is gone: nothing could end it later');
            $this->assertStringStartsWith('The server reports no transaction any more', (string) $e->getDebugMessage());
        }

        $this->assertSame([], $sql, 'nothing was sent after the failure');
        $this->assertSame([DatabaseInterface::TRANSACTION_LOST], array_column($this->ends, 'outcome'));
        $this->assertVisible([1]);
    }

    /**
     * A DDL statement that succeeds commits the transaction just as well, and PDO knows it: no
     * question is needed, and nothing more is sent in what is autocommit now.
     */
    public function testAStatementAfterASuccessfulDdlStatementIsNotSent(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        $refused = [];
        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$refused): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
                $db->execute('CREATE TABLE end_scenarios_ddl (id INT PRIMARY KEY)'); // implicit COMMIT
                foreach ([
                    static fn (): int => $db->insert(self::TABLE, ['id' => 2, 'name' => 'after the DDL']),
                    static fn (): ?array => $db->table(self::TABLE)->where('id', 1)->lockForUpdate()->first(),
                ] as $statement) {
                    try {
                        $statement();
                        $this->fail('Expected QueryException: not sent');
                    } catch (QueryException $e) {
                        $refused[] = $e;
                    }
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS end_scenarios_ddl');
        }

        $this->assertCount(2, $refused);
        foreach ($refused as $e) {
            $this->assertStringStartsWith('Not sent: PDO reports no transaction any more', (string) $e->getDebugMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(['end'], $this->events);
        $this->assertVisible([1]);
    }

    /**
     * With autocommit switched off a statement after the transaction's end would open the next
     * transaction instead of being committed on its own - and the commit would then commit it. The
     * question right after the failure opens none (DO 1, measured): the transaction is found gone
     * before any statement of the caller, nothing more is sent, the end is 'lost'.
     */
    public function testWithAutocommitOffNothingIsSentAfterAFailingDdlStatementEither(): void
    {
        $this->db->execute('SET autocommit = 0');
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
        try {
            $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
        }
        $this->assertFalse($this->db->inTransaction(), 'the question opened no transaction');

        try {
            $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'after the DDL']);
            $this->fail('Expected QueryException: not sent');
        } catch (QueryException $e) {
            $this->assertSame($failure, $e->getPrevious());
        }
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
        }

        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $e]], $this->ends);
        $this->assertVisible([1], 'the row before the DDL statement is committed, the one after it was never sent');
    }

    /**
     * What the driver found is held until the transaction is ended here: a transaction begun on
     * raw PDO afterwards (PDO reports one again) changes nothing. Nothing is sent, the commit is
     * refused for the failure that ended the transaction, and the rollback that cleans up tells
     * 'lost' - a deadlock or any other failure that came later could not turn it into a rollback.
     */
    public function testWhatTheDriverFoundAfterTheFailureIsHeldUntilTheTransactionIsEnded(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'before the DDL']);
        try {
            $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY)');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
        }
        $this->pdo->beginTransaction();
        $this->assertTrue($this->db->inTransaction());

        try {
            $this->db->insert(self::TABLE, ['id' => 2, 'name' => 'after the DDL']);
            $this->fail('Expected QueryException: not sent');
        } catch (QueryException $e) {
            $this->assertSame($failure, $e->getPrevious());
        }
        try {
            $this->db->commit();
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame($failure, $e->getPrevious());
            $this->assertNull($e->outcome, 'PDO reports a transaction: the caller ends it');
        }
        $this->assertSame([], $this->ends);

        $this->db->rollback();

        $this->assertSame(['end'], $this->events, 'no rollback listener: nothing is confirmed');
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $failure]], $this->ends);
        $this->assertVisible([1], 'the ROLLBACK cleaned up the raw transaction');
    }

    /**
     * When the question right after the failure fails, nothing is known: nothing more is sent, the
     * commit is refused, and the rollback that cleans up tells 'lost' - here the statement cost only
     * itself, and still nobody can say so.
     */
    public function testAQuestionRightAfterTheFailureThatFailsSendsNothingMore(): void
    {
        $refused = null;
        $thrown = null;
        try {
            $this->db->transaction(function (DatabaseInterface $db) use (&$refused): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
                $this->pdo->failExec = true;
                try {
                    $db->insert(self::TABLE, ['id' => 2, 'no_such_column' => 'x']);
                } catch (QueryException) {
                    // swallowed
                }
                $this->pdo->failExec = false;
                try {
                    $db->insert(self::TABLE, ['id' => 3, 'name' => 'c']);
                    $this->fail('Expected QueryException: not sent');
                } catch (QueryException $e) {
                    $refused = $e;
                }
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $thrown = $e;
            $this->assertSame(DatabaseInterface::TRANSACTION_LOST, $e->outcome);
            $this->assertStringContainsString('could not be asked whether it still exists', (string) $e->getDebugMessage());
        }

        $this->assertNotNull($refused);
        $this->assertStringStartsWith('Not sent: an earlier statement failed inside the transaction this library began', (string) $refused->getDebugMessage());
        $this->assertSame(['end'], $this->events, 'the ROLLBACK cleaned up, and confirms nothing');
        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_LOST, 'error' => $thrown]], $this->ends);
        $this->assertVisible([]);
    }

    /**
     * The question right after the failure is a call into PDO, and with it into whatever error
     * handler is installed. One that rolls back through the driver meanwhile has ended the
     * transaction and told its end: nothing is held, and what follows runs as after any rollback.
     */
    public function testAnErrorHandlerThatRollsBackWhileTheQuestionAfterTheFailureRunsLeavesOneEnd(): void
    {
        $this->db->beginTransaction();
        $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $db = $this->db;
        $this->pdo->duringExec = static function () use ($db): void {
            $db->rollback();
        };
        try {
            $this->db->query('SELECT * FROM end_scenarios_missing');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // the question ran, and inside it the rollback
        }

        $this->assertSame([['outcome' => DatabaseInterface::TRANSACTION_ROLLED_BACK, 'error' => null]], $this->ends, 'told once, by the rollback that ended it');
        $this->assertNull($this->db->currentTransaction());
        $this->db->transaction(static fn (DatabaseInterface $db): int => $db->insert(self::TABLE, ['id' => 2, 'name' => 'next']));
        $this->assertVisible([2]);
    }
}
