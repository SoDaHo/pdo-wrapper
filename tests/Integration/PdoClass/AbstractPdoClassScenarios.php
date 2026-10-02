<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration\PdoClass;

use Closure;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Tests\Integration\TransactionEnd\ScenarioPdo;
use Throwable;

/**
 * 'pdoClass': the library creates its PDO object as the class it is given. What that is for in a
 * consumer's test - a COMMIT that fails on demand, on the connection the rest of the test uses,
 * through the library's own commit path - is run here on every engine, through the factories a
 * consumer calls. The driver under test is one the library built: no subclass, no raw PDO handed in.
 */
abstract class AbstractPdoClassScenarios extends TestCase
{
    protected const TABLE = 'pdo_class_seam';

    private const ROLLED_BACK = DatabaseInterface::TRANSACTION_ROLLED_BACK;
    private const LOST = DatabaseInterface::TRANSACTION_LOST;

    private DatabaseInterface $db;

    private ScenarioPdo $pdo;

    /** @var list<string> */
    private array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable}> */
    private array $ends = [];

    /** @var array<string, array{env: mixed, process: string|false}> */
    private array $savedEnvironment = [];

    /**
     * Every way to a connection of this engine. Each takes the keys 'pdoClass' and 'options' as
     * the caller would pass them; a key that is missing is not passed.
     *
     * @return array<string, Closure(array{pdoClass?: class-string<PDO>|null, options?: array<int, mixed>}): DatabaseInterface>
     */
    abstract protected function factories(): array;

    /**
     * The class PDO itself offers for this engine (Pdo\Mysql, Pdo\Pgsql, Pdo\Sqlite).
     *
     * @return class-string<PDO>
     */
    abstract protected function driversOwnPdoClass(): string;

    abstract protected function createTableSql(): string;

    protected function setUp(): void
    {
        foreach (['DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PORT'] as $key) {
            $this->savedEnvironment[$key] = ['env' => $_ENV[$key] ?? null, 'process' => getenv($key)];
            unset($_ENV[$key]);
            putenv($key);
        }

        $this->db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $this->pdo = $pdo;
        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute($this->createTableSql());
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
        try {
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
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

    /**
     * The first factory: the one named after the engine (Database::mysql(), postgres(), sqlite()).
     *
     * @param array{pdoClass?: class-string<PDO>|null, options?: array<int, mixed>} $extra
     */
    private function connect(array $extra): DatabaseInterface
    {
        foreach ($this->factories() as $connect) {
            return $connect($extra);
        }

        throw new LogicException('No factory to connect with');
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
        foreach ($this->factories() as $how => $connect) {
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
        $class = $this->driversOwnPdoClass();

        foreach ($this->factories() as $how => $connect) {
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
        $this->pdo->failCommit = true;
        $this->pdo->failRollBackAlways = true;

        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
                $this->events[] = 'callback returned';
            });
            $this->fail('Expected CommitFailedException');
        } catch (CommitFailedException $e) {
            $this->assertSame(self::LOST, $e->outcome);
            $this->assertSame([['outcome' => self::LOST, 'error' => $e]], $this->ends);
        }

        $this->assertSame(['callback returned', 'end'], $this->events, 'neither a commit nor a rollback listener ran');
        $this->assertTrue($this->pdo->reallyInTransaction(), 'nothing was sent: still open');

        $this->pdo->failRollBackAlways = false;
        $this->assertTrue($this->pdo->rollBack());
        $this->assertSame(0, $this->rows());
        $this->assertTheNextTransactionCommits();
    }

    /**
     * The same with what is no PDOException: the object reaches the caller as it is, the end is 'lost'.
     */
    public function testWhatCommitThrowsIsLostWhenTheRollbackFailsToo(): void
    {
        $thrown = new TransactionException('commit failed (fixture)');
        $this->pdo->throwFromCommit = $thrown;
        $this->pdo->failRollBackAlways = true;

        $caught = null;
        try {
            $this->db->transaction(function (DatabaseInterface $db): void {
                $db->insert(self::TABLE, ['id' => 1, 'name' => 'never committed']);
            });
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertSame($thrown, $caught);
        $this->assertSame(['end'], $this->events);
        $this->assertSame([['outcome' => self::LOST, 'error' => $thrown]], $this->ends);

        $this->pdo->failRollBackAlways = false;
        $this->assertTrue($this->pdo->rollBack());
        $this->assertTheNextTransactionCommits();
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

            if ($what === 'a PDOException') {
                $this->assertInstanceOf(CommitFailedException::class, $caught);
                $this->assertNull($caught->outcome, 'not told yet: the transaction is still the caller\'s');
            } else {
                $this->assertSame($thrown, $caught);
            }
            $this->assertSame([], $this->events, "{$what}: nothing has ended");
            $this->assertTrue($this->pdo->reallyInTransaction(), $what);

            $this->db->rollback();
            $this->assertSame(['rollback', 'end'], $this->events, $what);
            $this->assertSame(self::ROLLED_BACK, $this->ends[0]['outcome'], $what);
            $this->assertSame(0, $this->rows(), $what);
        }
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
