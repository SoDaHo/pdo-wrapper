<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Support\Recorder;

/**
 * Outcomes of query(): a statement that ran is never reported as a failed query, and a failure that
 * PDO reports by returning false (non-exception error mode) is a failure.
 */
class QueryOutcomeTest extends TestCase
{
    private DatabaseInterface $db;

    /** @var list<string> SQL of every 'error' hook call */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->db = Database::sqlite(':memory:');
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->on('error', function (array $data): void {
            $this->errors[] = (string) $data['sql'];
        });
    }

    /**
     * Regression test: a PDOException from a query hook was wrapped as "Query failed" and fired the
     * error hook, although the INSERT had run.
     */
    public function testQueryHookPdoExceptionIsReportedAsHookFailureAndKeepsTheRow(): void
    {
        $hookError = new PDOException('log table missing');
        $this->db->on('query', static function (array $data) use ($hookError): void {
            if (str_starts_with((string) $data['sql'], 'INSERT')) {
                throw $hookError;
            }
        });

        try {
            $this->db->insert('users', ['name' => 'Max']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Query hook failed', $e->getMessage());
            $this->assertSame($hookError, $e->getPrevious());
            $this->assertSame(
                'log table missing | SQL: INSERT INTO `users` (`name`) VALUES (?) | Params: ["Max"]',
                $e->getDebugMessage()
            );
        }

        $this->assertSame([], $this->errors);
        $this->assertSame(1, $this->db->table('users')->count());
    }

    public function testOtherQueryHookExceptionsReachTheCallerUnchanged(): void
    {
        $this->db->on('query', static function (array $data): void {
            if (str_starts_with((string) $data['sql'], 'INSERT')) {
                throw new RuntimeException('audit failed');
            }
        });

        try {
            $this->db->insert('users', ['name' => 'Max']);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('audit failed', $e->getMessage());
        }

        $this->assertSame([], $this->errors);
        $this->assertSame(1, $this->db->table('users')->count());
    }

    /** @var list<string> SQL of every 'query' hook call */
    private array $queries = [];

    private function useSilentErrorMode(): void
    {
        $this->db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->db->on('query', function (array $data): void {
            $this->queries[] = (string) $data['sql'];
        });
    }

    public function testAPrepareFailureReportedWithoutAnExceptionIsAQueryException(): void
    {
        $this->useSilentErrorMode();

        try {
            $this->db->query('SELECT * FROM missing_table');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $previous = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $previous);
            $this->assertStringContainsString('PDO::prepare() returned false: no such table: missing_table', $previous->getMessage());
            $this->assertSame('HY000', $previous->errorInfo[0] ?? null);
            $this->assertStringContainsString('SQL: SELECT * FROM missing_table', $e->getDebugMessage() ?? '');
        }

        $this->assertSame(['SELECT * FROM missing_table'], $this->errors);
        $this->assertSame([], $this->queries, 'no query hook for a statement that did not run');
    }

    public function testAnExecuteFailureReportedWithoutAnExceptionIsAQueryException(): void
    {
        $this->useSilentErrorMode();
        /** @var Recorder<array<string, mixed>> $reported */
        $reported = new Recorder(static fn (array $data): array => $data);
        $this->db->on('error', $reported);
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);

        try {
            $this->db->insert('users', ['id' => 1, 'name' => 'Duplicate']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $previous = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $previous);
            $this->assertStringContainsString('PDOStatement::execute() returned false: UNIQUE constraint failed', $previous->getMessage());
            $this->assertSame(19, $previous->errorInfo[1] ?? null, 'SQLite driver code for a constraint violation');
            $this->assertSame(19, $previous->getCode());
            $this->assertSame(['23000', 19], [$e->sqlState, $e->driverCode]);
            $this->assertCount(1, $reported->all());
            $hook = $reported->all()[0];
            $this->assertSame([$e->sqlState, $e->driverCode], [$hook['sqlState'], $hook['driverCode']], 'the hook carries what the exception carries');
            $this->assertSame(19, $hook['code'], 'the driver\'s number: what the stand-in PDOException carries as its code');
        }

        $this->assertSame(['INSERT INTO `users` (`id`, `name`) VALUES (?, ?)'], $this->errors);
        $this->assertSame(['INSERT INTO `users` (`id`, `name`) VALUES (?, ?)'], $this->queries, 'only the first insert ran');
        $this->assertSame(1, $this->db->table('users')->count());
    }

    /**
     * What the error hook is told in a non-exception error mode: the same codes as the exception,
     * read from errorInfo(); 'code' is the driver's number there, as the stand-in PDOException
     * carries it.
     */
    public function testTheErrorHookCarriesTheCodesOfAFailureReportedByReturningFalse(): void
    {
        $this->useSilentErrorMode();
        /** @var Recorder<array<string, mixed>> $reported */
        $reported = new Recorder(static fn (array $data): array => $data);
        $this->db->on('error', $reported);

        try {
            $this->db->query('SELECT * FROM missing_table');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame(['HY000', 1], [$e->sqlState, $e->driverCode]);
            $this->assertCount(1, $reported->all());
            $hook = $reported->all()[0];
            $this->assertSame([$e->sqlState, $e->driverCode], [$hook['sqlState'], $hook['driverCode']]);
            $this->assertSame(1, $hook['code'], 'the driver\'s number: what the stand-in PDOException carries as its code');
            $this->assertSame('SELECT * FROM missing_table', $hook['sql']);
        }
    }

    /**
     * A failure PDO reports by returning false without recording anything: errorInfo() says
     * '00000', "no error". That is no code - hook and exception carry null, as they would for a
     * thrown PDOException with the same errorInfo.
     */
    public function testAFailureWithoutARecordedErrorCarriesNoCodesToTheHook(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            /**
             * @param array<int, mixed> $options
             */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return false;
            }

            /**
             * @return array<int, mixed>
             */
            public function errorInfo(): array
            {
                return ['00000', null, null];
            }
        };
        $db = new class ($pdo) extends SqliteDriver {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };
        /** @var Recorder<array<string, mixed>> $reported */
        $reported = new Recorder(static fn (array $data): array => $data);
        $db->on('error', $reported);

        try {
            $db->query('SELECT 1');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame([null, null], [$e->sqlState, $e->driverCode]);
            $this->assertCount(1, $reported->all());
            $hook = $reported->all()[0];
            $this->assertSame([null, null], [$hook['sqlState'], $hook['driverCode']]);
            $this->assertSame(0, $hook['code']);
            $this->assertSame('PDO::prepare() returned false: unknown error (SQLSTATE 00000)', $hook['error']);
        }
    }
}
