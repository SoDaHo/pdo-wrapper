<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\Driver;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\PostgresDriver;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Support\ReadsPdoErrorInfo;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

#[Group('postgres')]
class PostgresDriverIntegrationTest extends TestCase
{
    use ReadsPdoErrorInfo;

    private PostgresDriver $driver;

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private static function getConfig(): array
    {
        return TestEnvironment::postgres();
    }

    protected function setUp(): void
    {
        $this->driver = new PostgresDriver(self::getConfig());
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->driver);
    }

    public function testConnectsToPostgres(): void
    {
        $this->assertInstanceOf(PDO::class, $this->driver->getPdo());
    }

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->driver->query('SELECT 1 as test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame(1, $row['test']);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_pg (id SERIAL PRIMARY KEY, name TEXT)');

        $affected = $this->driver->execute('INSERT INTO test_pg (name) VALUES ($1)', ['hello']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_pg2 (id SERIAL PRIMARY KEY, name TEXT)');
        $this->driver->execute('INSERT INTO test_pg2 (name) VALUES ($1)', ['hello']);

        $id = $this->driver->lastInsertId('test_pg2_id_seq');

        $this->assertSame('1', $id);
    }

    public function testLastInsertIdWithInvalidSequenceThrowsQueryException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Failed to get last insert ID');

        $this->driver->lastInsertId('non_existent_sequence_that_does_not_exist');
    }

    public function testConnectionUsesExceptionErrorMode(): void
    {
        $errorMode = $this->driver->getPdo()->getAttribute(PDO::ATTR_ERRMODE);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    public function testConnectionUsesFetchAssoc(): void
    {
        $fetchMode = $this->driver->getPdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);

        $this->assertSame(PDO::FETCH_ASSOC, $fetchMode);
    }

    public function testInvalidHostThrowsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);

        new PostgresDriver([
            'host' => 'invalid-host-that-does-not-exist',
            'database' => 'test',
            'username' => 'test',
            'password' => 'test',
        ]);
    }

    public function testInsertReturnsIdForSerialTable(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_insert_serial (id SERIAL PRIMARY KEY, name TEXT)');

        $id = $this->driver->insert('test_insert_serial', ['name' => 'hello']);

        $this->assertSame(1, $id);
    }

    public function testInsertReturnsZeroForTableWithoutSequence(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_insert_composite (article_id INT, stat_date DATE, views INT, PRIMARY KEY (article_id, stat_date))');

        $id = $this->driver->insert('test_insert_composite', ['article_id' => 1, 'stat_date' => '2026-01-01', 'views' => 42]);

        $this->assertSame(0, $id);
    }

    /**
     * Regression test: the failing currval() probe for a table without {table}_id_seq aborted the
     * surrounding transaction, so the later COMMIT silently became a ROLLBACK and nothing was stored.
     */
    public function testInsertWithoutSequenceInsideTransactionKeepsTheTransaction(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_tx_tokens (token TEXT PRIMARY KEY, name TEXT)');
        $this->driver->execute('CREATE TEMPORARY TABLE test_tx_serial (id SERIAL PRIMARY KEY, name TEXT)');

        $ids = $this->driver->transaction(fn (DatabaseInterface $db): array => [
            $db->insert('test_tx_tokens', ['token' => 'tok', 'name' => 'first']),
            $db->insert('test_tx_serial', ['name' => 'second']),
        ]);

        $this->assertSame([0, 1], $ids);
        $this->assertFalse($this->driver->getPdo()->inTransaction());
        $this->assertSame(1, $this->driver->table('test_tx_tokens')->count());
        $this->assertSame(1, $this->driver->table('test_tx_serial')->count());
    }

    /**
     * currval() is undefined until nextval() ran in this session: an explicit id skips the sequence,
     * the probe fails, and the row must still be committed.
     */
    public function testInsertWithExplicitIdInsideTransactionKeepsTheTransaction(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_tx_explicit (id SERIAL PRIMARY KEY, name TEXT)');

        $id = $this->driver->transaction(
            fn (DatabaseInterface $db): int => $db->insert('test_tx_explicit', ['id' => 1000, 'name' => 'explicit'])
        );

        $this->assertSame(0, $id);
        $this->assertNotNull($this->driver->findOne('test_tx_explicit', ['id' => 1000]));
    }

    /**
     * In a non-exception error mode PDO reports the failed currval() probe as false instead of
     * throwing; the transaction is aborted all the same and must be rescued through the savepoint.
     */
    public function testInsertWithoutSequenceInsideTransactionInSilentErrorMode(): void
    {
        $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]);
        $driver->execute('CREATE TEMPORARY TABLE test_silent_tokens (token TEXT PRIMARY KEY, name TEXT)');

        $id = $driver->transaction(
            fn (DatabaseInterface $db): int => $db->insert('test_silent_tokens', ['token' => 'tok', 'name' => 'silent'])
        );

        $this->assertSame(0, $id);
        $this->assertFalse($driver->getPdo()->inTransaction());
        $this->assertSame(1, $driver->table('test_silent_tokens')->count());
    }

    /**
     * The savepoint itself can fail (here: PDO reports it as false in silent mode); the insert is
     * then reported as failed with the savepoint's reason. The 'query' hook has fired before: the
     * INSERT ran.
     */
    public function testFailingSavepointAroundTheInsertIdProbeIsReported(): void
    {
        $c = self::getConfig();
        $pdo = new class (sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['database']), $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]) extends PDO {
            public function exec(string $statement): int|false
            {
                return str_starts_with($statement, 'SAVEPOINT') ? false : parent::exec($statement);
            }

            /**
             * @return array<int, mixed>
             */
            public function errorInfo(): array
            {
                return ['25P02', 7, 'ERROR:  current transaction is aborted'];
            }
        };
        $driver = new class ($pdo) extends PostgresDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $driver->execute('CREATE TEMPORARY TABLE test_savepoint_users (id SERIAL PRIMARY KEY, name TEXT)');
        $seen = [];
        $driver->on('query', static function (array $data) use (&$seen): void {
            $seen[] = $data['sql'];
        });

        $driver->beginTransaction();
        try {
            $driver->insert('test_savepoint_users', ['name' => 'x']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Insert failed', $e->getMessage());
            $this->assertStringContainsString('Savepoint around the insert ID probe failed: SAVEPOINT pdo_wrapper_insert_id failed: ERROR:  current transaction is aborted', $e->getDebugMessage() ?? '');
            $this->assertSame(['25P02', 7], [$e->sqlState, $e->driverCode], 'what PDO recorded for the failure it reported by returning false');
        } finally {
            $driver->rollback();
        }

        $this->assertSame(['INSERT INTO "test_savepoint_users" ("name") VALUES (?)'], $seen);
        $this->assertFalse($driver->getPdo()->inTransaction());
    }

    /**
     * The id is read before the 'query' hook runs: a listener that breaks the transaction (a
     * failing statement in silent mode aborts it) no longer takes the id with it.
     */
    public function testInsertIdIsReadBeforeAQueryListenerCanBreakTheTransaction(): void
    {
        $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]);
        $driver->execute('CREATE TEMPORARY TABLE test_hook_users (id SERIAL PRIMARY KEY, name TEXT)');
        $driver->on('query', static function () use ($driver): void {
            $driver->getPdo()->exec('SELECT 1/0'); // silent mode: returns false, the transaction is aborted
        });

        $driver->beginTransaction();
        try {
            $this->assertSame(1, $driver->insert('test_hook_users', ['name' => 'x']));
        } finally {
            $driver->rollback();
        }
    }

    /**
     * The sequence is looked up in the table's own schema, quoted like the table: a table of the
     * same name on the search_path must not answer, and a mixed-case name is not folded.
     */
    public function testInsertReadsTheSequenceOfTheSchemaQualifiedTable(): void
    {
        $this->driver->execute('DROP SCHEMA IF EXISTS pdo_wrapper_other CASCADE');
        $this->driver->execute('CREATE SCHEMA pdo_wrapper_other');
        try {
            $this->driver->execute('CREATE TEMPORARY TABLE seq_users (id SERIAL PRIMARY KEY, name TEXT)');
            $this->driver->execute('CREATE TABLE pdo_wrapper_other.seq_users (id SERIAL PRIMARY KEY, name TEXT)');
            $this->driver->execute("SELECT setval('pdo_wrapper_other.seq_users_id_seq', 500)");
            $this->driver->execute('CREATE TABLE pdo_wrapper_other."MixedCase" (id SERIAL PRIMARY KEY, name TEXT)');
            $this->driver->execute('CREATE TABLE pdo_wrapper_other."we""ird" (id SERIAL PRIMARY KEY, name TEXT)');

            // The temporary table comes first on the search_path: its sequence is seq_users_id_seq too
            $this->assertSame(1, $this->driver->insert('seq_users', ['name' => 'temp']));
            $this->assertSame(501, $this->driver->insert('pdo_wrapper_other.seq_users', ['name' => 'other']));
            $this->assertSame(2, $this->driver->insert('seq_users', ['name' => 'temp']));
            $this->assertSame(502, $this->driver->table('pdo_wrapper_other.seq_users')->insert(['name' => 'other']));

            $this->assertSame(1, $this->driver->insert('pdo_wrapper_other.MixedCase', ['name' => 'm']));
            $this->assertSame(1, $this->driver->insert('pdo_wrapper_other.we"ird', ['name' => 'w']));
        } finally {
            $this->driver->execute('DROP SCHEMA IF EXISTS pdo_wrapper_other CASCADE');
        }
    }

    public function testInsertReadsTheIdItselfWhenAnOverriddenQueryBypassesTheDriver(): void
    {
        $driver = new class (self::getConfig()) extends PostgresDriver {
            public function query(string $sql, array $params = []): \PDOStatement
            {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_values($params));

                return $stmt;
            }
        };
        $driver->execute('CREATE TEMPORARY TABLE test_bypass_users (id SERIAL PRIMARY KEY, name TEXT)');

        $this->assertSame(1, $driver->insert('test_bypass_users', ['name' => 'A']));
        $this->assertSame(2, $driver->insert('test_bypass_users', ['name' => 'B']));
    }

    /**
     * A failed statement aborts the transaction; PostgreSQL answers the COMMIT with a ROLLBACK and
     * PDO reports success. commit() asks first and refuses - every time, until rollback().
     */
    public function testCommitOfAnAbortedTransactionIsRefused(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_aborted (id INT PRIMARY KEY)');
        $ends = [];
        $this->driver->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data;
        });
        $queries = [];
        $this->driver->on('query', static function (array $data) use (&$queries): void {
            $queries[] = $data['sql'];
        });

        $this->driver->beginTransaction();
        $this->driver->insert('test_aborted', ['id' => 1]);
        $failure = null;
        try {
            $this->driver->insert('test_aborted', ['id' => 1]);
        } catch (QueryException $e) {
            $failure = $e->getPrevious();
        }
        $this->assertInstanceOf(PDOException::class, $failure);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $this->driver->commit();
                $this->fail('Expected TransactionException');
            } catch (TransactionException $e) {
                $this->assertSame('Failed to commit transaction', $e->getMessage());
                $this->assertSame($failure, $e->getPrevious());
                $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
                $this->assertInstanceOf(CommitFailedException::class, $e);
                $this->assertNull($e->outcome, 'a direct commit(): not ended by the library');
            }
            $this->assertTrue($this->driver->inTransaction(), 'still the caller\'s to end');
            $this->assertSame([], $ends, 'a refused commit tells no end');
        }

        $this->driver->rollback();
        $this->assertSame([['outcome' => 'rolled_back', 'error' => null, 'transaction' => 1, 'depth' => 1]], $ends);
        $this->assertSame(0, $this->driver->table('test_aborted')->count());
        $this->assertNotContains('SELECT 1', $queries, 'the probe is not a statement of the caller');

        // The next transaction starts clean
        $this->driver->transaction(static fn (DatabaseInterface $db): int => $db->insert('test_aborted', ['id' => 2]));
        $this->assertSame(1, $this->driver->table('test_aborted')->count());
    }

    /**
     * The probe before COMMIT can fail for another reason than an aborted transaction: the
     * connection is gone. The commit is refused as well, and the message says which of the two it was.
     */
    public function testCommitAfterAFailedStatementOnALostConnectionIsRefused(): void
    {
        $db = new PostgresDriver(self::getConfig());
        $killer = new PostgresDriver(self::getConfig());
        $pid = (int) $db->query('SELECT pg_backend_pid()')->fetchColumn();

        $db->beginTransaction();
        $killer->query('SELECT pg_terminate_backend(?)', [$pid])->fetchColumn();
        $gone = false;
        for ($i = 0; $i < 100 && !$gone; $i++) {
            $gone = (int) $killer->query('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ?', [$pid])->fetchColumn() === 0;
            if (!$gone) {
                usleep(50_000);
            }
        }
        $this->assertTrue($gone, 'the terminated backend did not disappear within 5 s');
        try {
            $db->execute('SELECT 1');
            $this->fail('Expected QueryException: the connection is gone');
        } catch (QueryException) {
            // swallowed
        }

        try {
            $db->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('Failed to commit transaction', $e->getMessage());
            $this->assertStringContainsString('the connection no longer answers (SQLSTATE ', (string) $e->getDebugMessage());
            $this->assertStringNotContainsString('is aborted', (string) $e->getDebugMessage());
        }
    }

    /**
     * A failing lastInsertId() aborts the transaction like any failed statement, although it is
     * not a query(): the commit is refused as well - in exception mode and when PDO only returns false.
     */
    public function testAFailedLastInsertIdInsideATransactionRefusesTheCommit(): void
    {
        foreach ([PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_SILENT] as $mode) {
            $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => $mode]]);
            $driver->execute('CREATE TEMPORARY TABLE test_last_id (id INT PRIMARY KEY)');

            $driver->beginTransaction();
            $driver->insert('test_last_id', ['id' => 1]);
            try {
                $this->assertFalse($driver->lastInsertId('no_such_sequence_xyz'), 'silent mode reports the failure as false');
            } catch (QueryException $e) {
                $this->assertSame('Failed to get last insert ID', $e->getMessage());
            }

            try {
                $driver->commit();
                $this->fail('Expected TransactionException: the failed lookup aborted the transaction');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            }
            $driver->rollback();
            $this->assertSame(0, $driver->table('test_last_id')->count());
        }
    }

    /**
     * The refusal names the failure that aborted the transaction: not one a savepoint caught
     * earlier, and not the "transaction is aborted" answers that followed it.
     */
    public function testTheRefusalNamesTheFailureThatAbortedTheTransaction(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_cause (id INT PRIMARY KEY)');
        $failures = [];
        $fail = function (string $sql) use (&$failures): void {
            try {
                $this->driver->execute($sql);
            } catch (QueryException $e) {
                $failures[] = $e->getPrevious();
            }
        };

        $this->driver->beginTransaction();
        $this->driver->getPdo()->exec('SAVEPOINT attempt');
        $fail('SELECT 1/0');                          // caught by the savepoint
        $this->driver->getPdo()->exec('ROLLBACK TO SAVEPOINT attempt');
        $fail('SELECT * FROM no_such_table_cause');   // aborts the transaction
        $fail('SELECT 1');                            // 25P02: a consequence
        $this->assertCount(3, $failures);
        $this->assertSame('25P02', $failures[2]?->getCode());

        try {
            $this->driver->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($failures[1], $e->getPrevious());
        } finally {
            $this->driver->rollback();
        }
    }

    /**
     * PDO::ERRMODE_WARNING with an error handler that turns warnings into exceptions (as frameworks
     * install it): the failed statement still counts as a failed query, and the probe before
     * COMMIT still ends in a TransactionException.
     */
    public function testWarningModeWithAThrowingErrorHandlerStillRefusesTheCommit(): void
    {
        $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]]);
        $driver->execute('CREATE TEMPORARY TABLE test_warning_mode (id INT PRIMARY KEY)');
        $errors = [];
        $driver->on('error', static function (array $data) use (&$errors): void {
            $errors[] = $data['error'];
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $driver->beginTransaction();
            $driver->insert('test_warning_mode', ['id' => 1]);
            try {
                $driver->execute('SELECT 1/0');
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Query failed', $e->getMessage());
                $this->assertStringContainsString('division by zero', (string) $e->getDebugMessage());
            }
            $this->assertCount(1, $errors);

            try {
                $driver->commit();
                $this->fail('Expected TransactionException');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            }
            $driver->rollback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $driver->table('test_warning_mode')->count());
    }

    /**
     * A driver whose query() sends a session setting ahead of every statement (row-level security,
     * a tenant) keeps getting the id of the INSERT: the id is read after the statement it belongs to.
     */
    public function testInsertReturnsItsIdWhenAnOverriddenQuerySendsAStatementAhead(): void
    {
        $driver = new class (self::getConfig()) extends PostgresDriver {
            public function query(string $sql, array $params = []): \PDOStatement
            {
                parent::query("SELECT set_config('app.tenant', ?, false)", ['42']);

                return parent::query($sql, $params);
            }
        };
        $driver->execute('CREATE TEMPORARY TABLE test_ahead_users (id SERIAL PRIMARY KEY, name TEXT)');

        $this->assertSame(1, $driver->insert('test_ahead_users', ['name' => 'A']));
        $this->assertSame(2, $driver->insert('test_ahead_users', ['name' => 'B']));
        $this->assertSame(3, $driver->transaction(static fn (DatabaseInterface $db): int => $db->insert('test_ahead_users', ['name' => 'C'])));
    }

    /**
     * A failure on raw PDO is not seen - but its consequence is: the next statement through the
     * library fails with SQLSTATE 25P02, and with nothing else remembered that is reason enough
     * to ask the server before the COMMIT.
     */
    public function testAnAbortThatOnlyShowsAsItsConsequenceStillRefusesTheCommit(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_raw_abort (id INT PRIMARY KEY)');

        $this->driver->beginTransaction();
        $this->driver->insert('test_raw_abort', ['id' => 1]);
        try {
            $this->driver->getPdo()->exec('SELECT * FROM no_such_table_raw_abort');
        } catch (PDOException) {
            // raw PDO: the library does not see this failure
        }
        $consequence = null;
        try {
            $this->driver->execute('SELECT 1');
        } catch (QueryException $e) {
            $consequence = $e->getPrevious();
        }
        $this->assertSame('25P02', $consequence?->getCode());

        try {
            $this->driver->commit();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($consequence, $e->getPrevious());
            $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
        } finally {
            $this->driver->rollback();
        }
        $this->assertSame(0, $this->driver->table('test_raw_abort')->count());
    }

    /**
     * The error handler may throw any exception, not only ErrorException, and the failure may come
     * from lastInsertId() or from the savepoint around the id probe: each still counts as the
     * database failure it is, and the commit is refused.
     */
    public function testWarningModeWithAnyThrowingErrorHandlerIsStillADatabaseFailure(): void
    {
        $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]]);
        $driver->execute('CREATE TEMPORARY TABLE test_any_handler (id SERIAL PRIMARY KEY, name TEXT)');

        // also a PDOException of the handler's own making: it carries no errorInfo, PDO's record does
        foreach ([\RuntimeException::class, PDOException::class] as $thrown) {
            set_error_handler(static function (int $severity, string $message) use ($thrown): never {
                throw new $thrown('handler: ' . $message);
            });
            try {
                $driver->beginTransaction();
                try {
                    $driver->execute('SELECT 1/0');
                    $this->fail('Expected QueryException');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('division by zero', (string) $e->getDebugMessage());
                    $this->assertSame('22012', $this->errorInfoBehind($e, 0), 'the failure PDO recorded');
                    $this->assertInstanceOf($thrown, $e->getPrevious()?->getPrevious(), 'the handler\'s exception is kept');
                }
                $driver->rollback();
            } finally {
                restore_error_handler();
            }
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException('handler: ' . $message);
        });
        try {
            // a failed statement
            $driver->beginTransaction();
            try {
                $driver->execute('SELECT 1/0');
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertStringContainsString('division by zero', (string) $e->getDebugMessage());
            }
            try {
                $driver->commit();
                $this->fail('Expected TransactionException');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            }
            $driver->rollback();

            // a failed lastInsertId()
            $driver->beginTransaction();
            $driver->insert('test_any_handler', ['name' => 'a']);
            try {
                $driver->lastInsertId('no_such_sequence_any_handler');
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Failed to get last insert ID', $e->getMessage());
            }
            try {
                $driver->commit();
                $this->fail('Expected TransactionException');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            }
            $driver->rollback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $driver->table('test_any_handler')->count());
    }

    public function testAnErrorHandlerExceptionThatIsNotAboutPdoPassesThroughLastInsertId(): void
    {
        $driver = new class (self::getConfig()) extends PostgresDriver {
            public function replacePdo(PDO $pdo): void
            {
                $this->pdo = $pdo;
            }
        };
        $c = self::getConfig();
        $driver->replacePdo(new class (sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['database']), $c['username'], $c['password']) extends PDO {
            public function lastInsertId(?string $name = null): string|false
            {
                throw new \LogicException('not a database failure');
            }
        });

        // An earlier failure left its mark on the connection: outside of warning mode that says
        // nothing about an exception thrown later
        try {
            $driver->getPdo()->exec('SELECT 1/0');
            $this->fail('Expected PDOException');
        } catch (PDOException) {
            $this->assertSame('22012', $driver->getPdo()->errorCode());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not a database failure');
        $driver->lastInsertId();
    }

    /**
     * The savepoint statements around the id probe run on raw PDO. In warning mode with a throwing
     * handler their failure arrives as the handler's exception: it is the same 'Insert failed'.
     */
    public function testAFailingSavepointInWarningModeWithAThrowingHandlerIsReported(): void
    {
        $c = self::getConfig();
        $pdo = new class (sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['database']), $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]) extends PDO {
            public bool $breakSavepoints = false;

            public function exec(string $statement): int|false
            {
                if ($this->breakSavepoints && str_starts_with($statement, 'SAVEPOINT')) {
                    return parent::exec('SAVEPOINT'); // a syntax error: PDO warns, the handler throws
                }

                return parent::exec($statement);
            }
        };
        $driver = new class ($pdo) extends PostgresDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        $driver->execute('CREATE TEMPORARY TABLE test_savepoint_warning (id SERIAL PRIMARY KEY, name TEXT)');

        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException('handler: ' . $message);
        });
        try {
            $driver->beginTransaction();
            $pdo->breakSavepoints = true;
            try {
                $driver->insert('test_savepoint_warning', ['name' => 'x']);
                $this->fail('Expected QueryException');
            } catch (QueryException $e) {
                $this->assertSame('Insert failed', $e->getMessage());
                $this->assertStringContainsString('Savepoint around the insert ID probe failed: PDO reported a warning: ', (string) $e->getDebugMessage());
            }
            $pdo->breakSavepoints = false;
            try {
                $driver->commit();
                $this->fail('Expected TransactionException: the failed savepoint statement aborted the transaction');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
            }
            $driver->rollback();
        } finally {
            restore_error_handler();
        }
    }

    public function testCommitAfterASavepointCaughtTheFailureGoesThrough(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_savepoint_caught (id INT PRIMARY KEY)');

        $this->driver->transaction(static function (DatabaseInterface $db): void {
            $db->insert('test_savepoint_caught', ['id' => 1]);
            $db->getPdo()->exec('SAVEPOINT attempt');
            try {
                $db->insert('test_savepoint_caught', ['id' => 1]);
            } catch (QueryException) {
                $db->getPdo()->exec('ROLLBACK TO SAVEPOINT attempt');
            }
            $db->insert('test_savepoint_caught', ['id' => 2]);
        });

        $this->assertSame(2, $this->driver->table('test_savepoint_caught')->count());
    }

    public function testCommitOfAnAbortedTransactionIsRefusedInSilentErrorMode(): void
    {
        $driver = new PostgresDriver(self::getConfig() + ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]);
        $driver->execute('CREATE TEMPORARY TABLE test_aborted_silent (id INT PRIMARY KEY)');

        try {
            $driver->transaction(static function (DatabaseInterface $db): void {
                $db->insert('test_aborted_silent', ['id' => 1]);
                try {
                    $db->execute('SELECT 1/0');
                } catch (QueryException) {
                    // swallowed
                }
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertStringContainsString('The transaction is aborted', (string) $e->getDebugMessage());
        }

        $this->assertFalse($driver->inTransaction());
        $this->assertSame(0, $driver->table('test_aborted_silent')->count());
    }

    /**
     * A statement that failed outside a transaction, or in one that was rolled back, says nothing
     * about the next transaction - also when that one was begun on raw PDO.
     */
    public function testAFailureOutsideTheTransactionDoesNotConcernTheNextCommit(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_unrelated (id INT PRIMARY KEY)');

        try {
            $this->driver->execute('SELECT 1/0');
        } catch (QueryException) {
            // autocommit: nothing to abort
        }
        $this->driver->beginTransaction();
        try {
            $this->driver->execute('SELECT 1/0');
        } catch (QueryException) {
            // aborted, then rolled back
        }
        $this->driver->rollback();

        $this->driver->getPdo()->beginTransaction();
        $this->driver->insert('test_unrelated', ['id' => 1]);
        $this->driver->commit();

        $this->assertSame(1, $this->driver->table('test_unrelated')->count());
    }

    public function testNowAndUtcNowAreUsableAsValues(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_now (id SERIAL PRIMARY KEY, local_at TIMESTAMP, utc_at TIMESTAMP)');

        $id = $this->driver->insert('test_now', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $row = $this->driver->findOne('test_now', ['id' => $id]);

        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['local_at']);
        $this->assertEqualsWithDelta(time(), (int) strtotime((string) $row['utc_at'] . ' UTC'), 5);
        $this->assertSame(1, $this->driver->table('test_now')->where('utc_at', '<=', $this->driver->utcNow())->count());
    }

    /**
     * now() follows the session's time zone, utcNow() does not: with the session two hours ahead
     * of UTC they must differ by exactly that offset (a now() that secretly returns UTC would fail here).
     */
    public function testNowFollowsTheSessionTimeZoneAndUtcNowDoesNot(): void
    {
        // INTERVAL form: ISO sign (east positive). A bare '+02:00' would be read POSIX-style, i.e. west of UTC.
        $this->driver->execute("SET TIME ZONE INTERVAL '+02:00' HOUR TO MINUTE");
        $this->driver->execute('CREATE TEMPORARY TABLE test_tz (id SERIAL PRIMARY KEY, local_at TIMESTAMP, utc_at TIMESTAMP)');

        $id = $this->driver->insert('test_tz', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $row = $this->driver->findOne('test_tz', ['id' => $id]);

        $this->assertNotNull($row);
        $local = (int) strtotime((string) $row['local_at'] . ' UTC');
        $utc = (int) strtotime((string) $row['utc_at'] . ' UTC');
        $this->assertEqualsWithDelta(7200, $local - $utc, 2);
        $this->assertEqualsWithDelta(time(), $utc, 5);
    }

    /**
     * now()/utcNow() are statement time, not transaction time: two statements a second apart in the
     * same open transaction must differ (NOW()/LOCALTIMESTAMP would return the same value twice).
     */
    public function testNowIsStatementTimeNotTransactionTime(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_stmt (id SERIAL PRIMARY KEY, local_at TIMESTAMP, utc_at TIMESTAMP)');
        $this->driver->beginTransaction();
        $first = $this->driver->insert('test_stmt', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        usleep(1100000);
        $second = $this->driver->insert('test_stmt', ['local_at' => $this->driver->now(), 'utc_at' => $this->driver->utcNow()]);
        $rows = $this->driver->findAll('test_stmt');
        $this->driver->rollback();

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertGreaterThan((string) $rows[0]['local_at'], (string) $rows[1]['local_at']);
        $this->assertGreaterThan((string) $rows[0]['utc_at'], (string) $rows[1]['utc_at']);
    }

    /**
     * Raw values in insert() go through the same savepoint probe: both rows must be committed.
     */
    public function testRawInsertValuesInsideTransactionAreCommitted(): void
    {
        $this->driver->execute('CREATE TEMPORARY TABLE test_raw_serial (id SERIAL PRIMARY KEY, seen_at TIMESTAMP)');
        $this->driver->execute('CREATE TEMPORARY TABLE test_raw_tokens (token TEXT PRIMARY KEY, seen_at TIMESTAMP)');

        $ids = $this->driver->transaction(fn (DatabaseInterface $db): array => [
            $db->insert('test_raw_tokens', ['token' => 'tok', 'seen_at' => $db->utcNow()]),
            $db->insert('test_raw_serial', ['seen_at' => $db->now()]),
        ]);

        $this->assertSame([0, 1], $ids);
        $this->assertFalse($this->driver->getPdo()->inTransaction());
        $this->assertSame(1, $this->driver->table('test_raw_tokens')->whereNotNull('seen_at')->count());
        $this->assertSame(1, $this->driver->table('test_raw_serial')->whereNotNull('seen_at')->count());
    }

    /**
     * libpq splits an unquoted connection string on whitespace: "x host=evil" used to redirect the
     * connection. With quoted values it is just a database name that does not exist.
     */
    public function testWhitespaceInTheDatabaseNameCannotRedirectTheConnection(): void
    {
        try {
            new PostgresDriver(['database' => 'x host=evil.invalid'] + self::getConfig());
            $this->fail('Expected ConnectionException was not thrown');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('database "x host=evil.invalid" does not exist', (string) $e->getDebugMessage());
        }
    }

    public function testDatabaseNamesWithSpacesAndApostrophesConnect(): void
    {
        foreach (['pdo wrapper', "pdo'wrapper"] as $name) {
            $exists = $this->driver->query('SELECT 1 FROM pg_database WHERE datname = ?', [$name])->fetchColumn();
            if ($exists === false) {
                $this->driver->execute('CREATE DATABASE "' . str_replace('"', '""', $name) . '"');
            }

            $other = new PostgresDriver(['database' => $name] + self::getConfig());

            $this->assertSame($name, $other->query('SELECT current_database()')->fetchColumn());
        }
    }

    public function testConnectionExceptionHasDebugMessage(): void
    {
        try {
            $config = self::getConfig();
            new PostgresDriver([
                'host' => $config['host'],
                'database' => 'nonexistent_db_that_does_not_exist',
                'username' => $config['username'],
                'password' => $config['password'],
            ]);
        } catch (ConnectionException $e) {
            $this->assertSame('Database connection failed', $e->getMessage());
            $this->assertNotNull($e->getDebugMessage());
            $this->assertStringContainsString('PostgreSQL', $e->getDebugMessage());
            return;
        }

        $this->fail('Expected ConnectionException was not thrown');
    }

    /**
     * A lost connection (the server terminated the backend): the callback's statement fails, no
     * rollback can be confirmed, no 'transaction.rollback' listener runs, and 'transaction.end'
     * reports 'lost' with the statement's exception.
     */
    public function testLostConnectionEndsTheTransactionAsLost(): void
    {
        $db = $this->driver;
        $db->execute('DROP TABLE IF EXISTS end_probe');
        $db->execute('CREATE TABLE end_probe (id SERIAL PRIMARY KEY, name VARCHAR(50))');
        $killer = new PostgresDriver(self::getConfig());
        $pid = (int) $db->query('SELECT pg_backend_pid()')->fetchColumn();
        $events = [];
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.end', static function (array $data) use (&$events): void {
            $events[] = $data;
        });
        $measured = new class () {
            public ?bool $inTransactionAfterError = null;

            public ?bool $inTransactionAfterwards = null;
        };

        try {
            $db->transaction(static function (PostgresDriver $db) use ($killer, $pid, $measured): void {
                $db->insert('end_probe', ['name' => 'inside']);
                $killer->query('SELECT pg_terminate_backend(?)', [$pid])->fetchColumn();
                $gone = false;
                for ($i = 0; $i < 100 && !$gone; $i++) {
                    $gone = (int) $killer->query('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ?', [$pid])->fetchColumn() === 0;
                    if (!$gone) {
                        usleep(50_000);
                    }
                }
                if (!$gone) {
                    throw new \RuntimeException('the terminated backend did not disappear within 5 s');
                }
                try {
                    $db->execute('UPDATE end_probe SET name = ? WHERE id = 1', ['after']);
                } catch (QueryException $e) {
                    $measured->inTransactionAfterError = $db->inTransaction();
                    throw $e;
                }
            });
            $this->fail('Expected the lost connection');
        } catch (QueryException $e) {
            $measured->inTransactionAfterwards = $db->inTransaction();
        }

        $this->assertSame([['outcome' => 'lost', 'error' => $e, 'transaction' => 1, 'depth' => 1]], $events, 'no rollback listener, transaction.end reports lost with the statement exception');
        $this->assertSame(0, $killer->table('end_probe')->count(), 'the server rolled the terminated backend back');
        // measured on PostgreSQL 15 with pdo_pgsql: like mysqlnd, PDO keeps reporting the transaction
        $this->assertTrue($measured->inTransactionAfterError, 'PDO still reports the transaction right after the error');
        $this->assertTrue($measured->inTransactionAfterwards, 'and after the failed rollback');
        $killer->execute('DROP TABLE IF EXISTS end_probe');
    }

    /**
     * The same on the manual path, for a transaction begun through the library and for one begun
     * on raw PDO: the failed COMMIT takes the transaction with it, nothing could end it
     * afterwards, so the failed commit itself tells 'lost' - at once.
     */
    public function testAManualCommitRejectedByADeferredConstraintTellsLostAtOnce(): void
    {
        $db = $this->driver;
        $db->execute('DROP TABLE IF EXISTS end_child');
        $db->execute('DROP TABLE IF EXISTS end_parent');
        $db->execute('CREATE TABLE end_parent (id INT PRIMARY KEY)');
        $db->execute('CREATE TABLE end_child (id SERIAL PRIMARY KEY, parent_id INT REFERENCES end_parent (id) DEFERRABLE INITIALLY DEFERRED)');
        $ends = [];
        $db->on('transaction.end', static function (array $data) use (&$ends): void {
            $ends[] = $data;
        });

        try {
            foreach (['library' => static fn () => $db->beginTransaction(), 'raw PDO' => static fn () => $db->getPdo()->beginTransaction()] as $begunOn => $begin) {
                $ends = [];
                $begin();
                $db->insert('end_child', ['parent_id' => 999]); // checked at COMMIT
                try {
                    $db->commit();
                    $this->fail('Expected CommitFailedException');
                } catch (CommitFailedException $e) {
                    $this->assertSame('23503', $e->getPrevious()?->getCode(), $begunOn);
                    $this->assertFalse($db->inTransaction(), $begunOn);
                    $number = $begunOn === 'library' ? 1 : null; // begun on raw PDO: no begin was told, no number
                    $this->assertSame([['outcome' => 'lost', 'error' => $e, 'transaction' => $number, 'depth' => $number]], $ends, $begunOn);
                    $this->assertSame('lost', $e->outcome, $begunOn);
                }
            }
            $this->assertSame(0, $db->table('end_child')->count());
        } finally {
            $db->execute('DROP TABLE IF EXISTS end_child');
            $db->execute('DROP TABLE IF EXISTS end_parent');
        }
    }

    /**
     * A COMMIT rejected by a deferred constraint: PostgreSQL rolls the transaction back itself and
     * pdo_pgsql no longer reports it, so the library cannot send a rollback: no
     * 'transaction.rollback' listener runs and 'transaction.end' reports 'lost' with the commit's
     * exception (fail-closed, although the server did roll back).
     */
    public function testACommitRejectedByADeferredConstraintEndsAsLost(): void
    {
        $db = $this->driver;
        $db->execute('DROP TABLE IF EXISTS end_child');
        $db->execute('DROP TABLE IF EXISTS end_parent');
        $db->execute('CREATE TABLE end_parent (id INT PRIMARY KEY)');
        $db->execute('CREATE TABLE end_child (id SERIAL PRIMARY KEY, parent_id INT REFERENCES end_parent (id) DEFERRABLE INITIALLY DEFERRED)');
        $events = [];
        $db->on('transaction.rollback', static function () use (&$events): void {
            $events[] = 'rollback';
        });
        $db->on('transaction.end', static function (array $data) use (&$events): void {
            $events[] = $data;
        });

        try {
            $db->transaction(static function (PostgresDriver $db): void {
                $db->insert('end_child', ['parent_id' => 999]); // checked at COMMIT
            });
            $this->fail('Expected the commit to fail');
        } catch (CommitFailedException $e) {
            $this->assertSame('23503', $e->getPrevious()?->getCode(), 'foreign key violation at COMMIT');
            $this->assertSame('lost', $e->outcome, 'fail-closed: PDO reports no transaction, no rollback of this library is confirmed');
        }

        $this->assertFalse($db->inTransaction(), 'pdo_pgsql no longer reports the transaction after the failed COMMIT');
        $this->assertSame([['outcome' => 'lost', 'error' => $e, 'transaction' => 1, 'depth' => 1]], $events, "no rollback listener; lost with the commit's exception");
        $this->assertSame(0, $db->table('end_child')->count(), 'the server rolled back');
        $db->execute('DROP TABLE IF EXISTS end_child');
        $db->execute('DROP TABLE IF EXISTS end_parent');
    }
}
