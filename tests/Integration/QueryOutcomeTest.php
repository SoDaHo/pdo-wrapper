<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

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
        $this->db = Database::sqlite();
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
                'log table missing | SQL: INSERT INTO "users" ("name") VALUES (?) | Params: ["Max"]',
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
        }

        $this->assertSame(['INSERT INTO "users" ("id", "name") VALUES (?, ?)'], $this->errors);
        $this->assertSame(['INSERT INTO "users" ("id", "name") VALUES (?, ?)'], $this->queries, 'only the first insert ran');
        $this->assertSame(1, $this->db->table('users')->count());
    }
}
