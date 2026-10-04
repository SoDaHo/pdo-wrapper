<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\PdoClass;

use Closure;
use PDO;
use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Throwable;

/**
 * 'pdoClass': the library creates its PDO object as the class it is given. What that is for in a
 * consumer's test - a COMMIT that fails on demand, on the connection the rest of the test uses,
 * through the library's own commit path - is run here on every engine, through the factories a
 * consumer calls. The driver under test is one the library built: no subclass, no raw PDO handed in.
 */
class PdoClassTest extends ContractTestCase
{
    protected const TABLE = 'pdo_class_seam';

    private const ROLLED_BACK = DatabaseInterface::TRANSACTION_ROLLED_BACK;
    private const LOST = DatabaseInterface::TRANSACTION_LOST;


    private ScenarioPdo $pdo;

    /** @var list<string> */
    private array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable}> */
    private array $ends = [];

    /** @var array<string, array{env: mixed, process: string|false}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PORT'] as $key) {
            $this->savedEnvironment[$key] = ['env' => $_ENV[$key] ?? null, 'process' => getenv($key)];
            unset($_ENV[$key]);
            putenv($key);
        }

        $this->db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $this->pdo = $pdo;
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->events = [];
        $this->ends = [];
        $this->db->on('transaction.commit', function (): void {
            $this->events[] = 'commit';
        });
        $this->db->on('transaction.rollback', function (): void {
            $this->events[] = 'rollback';
        });
        $this->db->on('transaction.end', function (array $data): void {
            $this->events[] = 'end';
            $this->ends[] = ['outcome' => (string) $data['outcome'], 'error' => $data['error'] instanceof Throwable ? $data['error'] : null];
        });
    }

    protected function tearDown(): void
    {
        $this->pdo->failRollBackAlways = false;
        $this->pdo->rollBackReturnsFalse = false;
        try {
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
            unset($this->pdo);
            parent::tearDown();
        } finally {
            foreach ($this->savedEnvironment as $key => $saved) {
                unset($_ENV[$key]);
                if ($saved['env'] !== null) {
                    $_ENV[$key] = $saved['env'];
                }
                putenv($saved['process'] === false ? $key : $key . '=' . $saved['process']);
            }
        }
    }

    private function rows(): int
    {
        return $this->db->table(self::TABLE)->count();
    }

    // ---- the class ---------------------------------------------------------------------------

    /**
     * The key counts in the factory of the engine as in connect() and fromEnv(), and the library
     * still builds the connection itself: its defaults stay, 'options' still replace them.
     */
    public function testEveryFactoryCreatesThePdoObjectAsTheClassItIsGiven(): void
    {
        foreach (self::binding()->factories() as $how => $connect) {
            $pdo = $connect(['pdoClass' => ScenarioPdo::class])->getPdo();
            $this->assertSame(ScenarioPdo::class, $pdo::class, $how);
            $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE), "{$how}: the library's defaults stay");
            $this->assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), "{$how}: the library's defaults stay");

            $pdo = $connect(['pdoClass' => ScenarioPdo::class, 'options' => [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM]])->getPdo();
            $this->assertSame(ScenarioPdo::class, $pdo::class, "{$how}: with options");
            $this->assertSame(PDO::FETCH_NUM, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), "{$how}: the options are passed on");

            $this->assertSame(PDO::class, $connect([])->getPdo()::class, "{$how}: PDO itself when no class is named");
            $this->assertSame(PDO::class, $connect(['pdoClass' => null])->getPdo()::class, "{$how}: PDO itself for null");
        }
    }

    /**
     * Since PHP 8.4 PDO has a class per driver; new PDO() does not return it. Named as 'pdoClass',
     * getPdo() is that class, with the driver's own methods.
     */
    public function testTheDriversOwnClassCanBeNamed(): void
    {
        $class = self::binding()->driversOwnPdoClass();

        foreach (self::binding()->factories() as $how => $connect) {
            $db = $connect(['pdoClass' => $class]);
            $this->assertSame($class, $db->getPdo()::class, $how);
            $this->assertSame([['one' => 1]], $db->query('SELECT 1 AS one')->fetchAll(), "{$how}: the connection works");
        }
    }

    // ---- a COMMIT that fails on demand -------------------------------------------------------

    /**
     * What the PDO object throws from commit() that is no PDOException is not the library's to
     * interpret: exactly that object reaches the caller of transaction(), after the callback
     * returned, and the transaction is rolled back like after any other failure.
     */
    public function testWhatCommitThrowsThatIsNoPdoExceptionReachesTheCallerOfTransactionAsItIs(): void
    {
        foreach ([new TransactionException('commit failed (fixture)'), new RuntimeException('commit failed (fixture)')] as $n => $thrown) {
            $this->events = [];
            $this->ends = [];
            $this->pdo->throwFromCommit = $thrown;

            $caught = null;
            try {
                $this->db->transaction(function (DatabaseInterface $db) use ($n): void {
                    $db->insert(self::TABLE, ['id' => 10 + $n, 'name' => 'never committed']);
                    $this->events[] = 'callback returned';
                });
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertSame($thrown, $caught, 'the object itself, not a wrapper');
            $this->assertSame(['callback returned', 'rollback', 'end'], $this->events, 'no commit listener ran');
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $thrown]], $this->ends);
            $this->assertFalse($this->pdo->reallyInTransaction());
            $this->assertSame(0, $this->rows(), 'rolled back');
        }

        $this->assertTheNextTransactionCommits();
    }

    /**
     * A PDOException from commit() is what a COMMIT the server rejected looks like: the library's
     * CommitFailedException, and - the ROLLBACK after it went through - the outcome 'rolled_back'.
     */
    public function testAPdoExceptionFromCommitIsAFailedCommitThatWasRolledBack(): void
    {
        $this->pdo->failCommit = true;

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
                $this->events[] = 'callback returned';
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
            $this->assertSame('commit failed (scenario)', $e->getPrevious()->getMessage());
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
        }

        $this->assertSame(['callback returned', 'rollback', 'end'], $this->events, 'no commit listener ran');
        $this->assertFalse($this->pdo->reallyInTransaction());
        $this->assertSame(0, $this->rows(), 'rolled back');
        $this->assertTheNextTransactionCommits();
    }

    /**
     * When the ROLLBACK after the failed COMMIT fails as well, nothing confirms what became of the
     * transaction: 'lost', no rollback listener. The class only refused to send the ROLLBACK, so
     * the transaction is in fact still open: the test ends it on raw PDO, and the connection is
     * usable again.
     */
    public function testAFailedCommitWhoseRollbackFailsTooIsLost(): void
    {
        $commits = [
            'commit() throws a PDOException' => function (): void {
                $this->pdo->failCommit = true;
            },
            'commit() returns false' => function (): void {
                $this->pdo->commitReturnsFalse = true;
            },
        ];

        foreach ($commits as $commit => $arm) {
            foreach ($this->failingRollBacks() as $rollBack => [$fail, $letThrough]) {
                $what = "{$commit}, {$rollBack}";
                $this->events = [];
                $this->ends = [];
                $arm();
                $fail();

                try {
                    $this->db->transaction(function (DatabaseInterface $db): void {
                        $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
                        $this->events[] = 'callback returned';
                    });
                    $this->fail("Expected CommitFailedException: {$what}");
                } catch (CommitFailedException $e) {
                    $this->assertSame(self::LOST, $e->outcome, $what);
                    $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends, $what);
                }

                $this->assertSame(['callback returned', 'end'], $this->events, "{$what}: neither a commit nor a rollback listener ran");
                $this->assertTrue($this->pdo->reallyInTransaction(), "{$what}: nothing was sent, still open");

                $letThrough();
                $this->assertTrue($this->pdo->rollBack());
                $this->assertSame(0, $this->rows(), $what);
                $this->assertTheNextTransactionCommits();
                $this->db->execute('DELETE FROM ' . self::TABLE);
            }
        }
    }

    /**
     * An exception of the library's own classes that the PDO class throws is not the library's
     * word about this transaction. A CommitHookException means "committed" only when commit()
     * built it: thrown by the class, the transaction is rolled back like after anything else. A
     * CommitFailedException the class built is not the failed commit of this call: it passes
     * unchanged, and no outcome is written into it.
     */
    public function testAnExceptionOfTheLibraryThrownByTheClassIsNotTakenForTheLibrarysOwn(): void
    {
        $first = new RuntimeException('a listener failed (fixture)');
        $thrownByTheClass = [
            'a CommitHookException' => new CommitHookException($first, [$first]),
            'a CommitFailedException' => new CommitFailedException(message: 'Failed to commit transaction (fixture)'),
        ];

        foreach ($thrownByTheClass as $what => $thrown) {
            $this->events = [];
            $this->ends = [];
            $this->pdo->throwFromCommit = $thrown;

            $caught = null;
            try {
                $this->db->transaction(function (DatabaseInterface $db): void {
                    $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
                    $this->events[] = 'callback returned';
                });
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertSame($thrown, $caught, $what);
            $this->assertSame(['callback returned', 'rollback', 'end'], $this->events, "{$what}: rolled back, nothing committed");
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $thrown]], $this->ends, $what);
            $this->assertFalse($this->pdo->reallyInTransaction(), "{$what}: nothing is left open");
            $this->assertSame(0, $this->rows(), $what);
            if ($thrown instanceof CommitFailedException) {
                $this->assertNull($thrown->outcome, 'not the commit this call failed with: nothing is written into it');
            }
        }

        $this->assertTheNextTransactionCommits();
    }

    /**
     * The same with what is no PDOException: the object reaches the caller as it is, the end is 'lost'.
     */
    public function testWhatCommitThrowsIsLostWhenTheRollbackFailsToo(): void
    {
        $first = new RuntimeException('a listener failed (fixture)');
        $thrownByTheClass = [
            'no exception of the library' => static fn (): Throwable => new TransactionException('commit failed (fixture)'),
            'a CommitHookException' => static fn (): Throwable => new CommitHookException($first, [$first]),
            'a CommitFailedException' => static fn (): Throwable => new CommitFailedException(message: 'Failed to commit transaction (fixture)'),
        ];

        foreach ($thrownByTheClass as $object => $make) {
            foreach ($this->failingRollBacks() as $rollBack => [$fail, $letThrough]) {
                $what = "{$object}, {$rollBack}";
                $this->events = [];
                $this->ends = [];
                $thrown = $make();
                $this->pdo->throwFromCommit = $thrown;
                $fail();

                $caught = null;
                try {
                    $this->db->transaction(function (DatabaseInterface $db): void {
                        $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
                    });
                } catch (Throwable $e) {
                    $caught = $e;
                }

                $this->assertSame($thrown, $caught, $what);
                $this->assertSame(['end'], $this->events, $what);
                $this->assertSame([['outcome' => self::LOST, 'error' => $thrown]], $this->ends, $what);
                if ($thrown instanceof CommitFailedException) {
                    $this->assertNull($thrown->outcome, "{$what}: not the commit this call failed with");
                }

                $letThrough();
                $this->assertTrue($this->pdo->rollBack());
                $this->assertTheNextTransactionCommits();
                $this->db->execute('DELETE FROM ' . self::TABLE);
            }
        }
    }

    /**
     * A commit() that returns false - PDO's way to report the failure without exceptions - is a
     * failed commit like one that throws a PDOException.
     */
    public function testACommitThatReturnsFalseIsAFailedCommitThatWasRolledBack(): void
    {
        $this->pdo->commitReturnsFalse = true;

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
                $this->events[] = 'callback returned';
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertInstanceOf(TransactionException::class, $e, 'caught as the TransactionException it has been since 1.1');
            $this->assertSame(self::ROLLED_BACK, $e->outcome);
            $this->assertSame('PDO::commit() returned false', $e->getDebugMessage());
            $this->assertSame([['outcome' => self::ROLLED_BACK, 'error' => $e]], $this->ends);
        }

        $this->assertSame(['callback returned', 'rollback', 'end'], $this->events, 'no commit listener ran');
        $this->assertSame(0, $this->rows(), 'rolled back');
        $this->assertTheNextTransactionCommits();
    }

    /**
     * Without transaction(): commit() hands the failure on and leaves the transaction to the
     * caller, whose rollback() ends it.
     */
    public function testAFailedCommitOfTheCallersOwnTransactionLeavesItOpen(): void
    {
        $thrown = new RuntimeException('commit failed (fixture)');
        $failures = [
            'no PDOException' => function () use ($thrown): void {
                $this->pdo->throwFromCommit = $thrown;
            },
            'a PDOException' => function (): void {
                $this->pdo->failCommit = true;
            },
            'false' => function (): void {
                $this->pdo->commitReturnsFalse = true;
            },
        ];

        foreach ($failures as $what => $arm) {
            $this->events = [];
            $this->ends = [];
            $this->db->beginTransaction();
            $this->db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
            $arm();

            $caught = null;
            try {
                $this->db->commit();
            } catch (Throwable $e) {
                $caught = $e;
            }

            if ($what === 'no PDOException') {
                $this->assertSame($thrown, $caught);
            } else {
                $this->assertInstanceOf(CommitFailedException::class, $caught, $what);
                $this->assertNull($caught->outcome, "{$what}: not told yet, the transaction is still the caller's");
            }
            $this->assertSame([], $this->events, "{$what}: nothing has ended");
            $this->assertTrue($this->pdo->reallyInTransaction(), $what);

            $this->db->rollback();
            $this->assertSame(['rollback', 'end'], $this->events, $what);
            $this->assertSame(self::ROLLED_BACK, $this->ends[0]['outcome'], $what);
            $this->assertSame(0, $this->rows(), $what);
        }
    }

    /**
     * The two ways a rollBack() of the class fails: how to switch each on, and off again.
     *
     * @return array<string, array{Closure(): void, Closure(): void}>
     */
    private function failingRollBacks(): array
    {
        return [
            'rollBack() throws' => [
                function (): void {
                    $this->pdo->failRollBackAlways = true;
                },
                function (): void {
                    $this->pdo->failRollBackAlways = false;
                },
            ],
            'rollBack() returns false' => [
                function (): void {
                    $this->pdo->rollBackReturnsFalse = true;
                },
                function (): void {
                    $this->pdo->rollBackReturnsFalse = false;
                },
            ],
        ];
    }

    private function assertTheNextTransactionCommits(): void
    {
        $this->events = [];
        $this->ends = [];

        $this->db->transaction(static function (DatabaseInterface $db): void {
            $db->insert(self::TABLE, ['id' => 99, 'name' => 'committed']);
        });

        $this->assertSame(['commit', 'end'], $this->events, 'the connection is usable, nothing is told twice');
        $this->assertSame(1, $this->rows());
    }
}
