<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use PDO;
use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Recorder;

/**
 * Outcomes of query(): a statement that ran is never reported as a failed query, and a failure that
 * PDO reports by returning false (non-exception error mode) is a failure.
 *
 * The codes of a failure are the engine's: here they are checked for their form and for being the
 * same in exception, stand-in PDOException and error hook; the engine's own numbers are checked in
 * tests/Driver.
 */
class QueryOutcomeTest extends ContractTestCase
{
    /** @var list<string> SQL of every 'error' hook call */
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text']);
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
        $failedOn = null;
        $this->db->on('query', static function (array $data) use ($hookError, &$failedOn): void {
            if ($failedOn === null) { // the first statement told: the insert
                $failedOn = (string) $data['sql'];

                throw $hookError;
            }
        });

        try {
            $this->db->insert('users', ['name' => 'Max']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Query hook failed', $e->getMessage());
            $this->assertSame($hookError, $e->getPrevious());
            $this->assertNotNull($failedOn);
            $this->assertSame(
                'log table missing | SQL: ' . $failedOn . ' | Params: ["Max"]',
                $e->getDebugMessage(),
                'the statement as the hook was told it (its text: tests/Driver)'
            );
        }

        $this->assertSame([], $this->errors);
        $this->assertSame(1, $this->db->table('users')->count());
    }

    public function testOtherQueryHookExceptionsReachTheCallerUnchanged(): void
    {
        $thrown = false;
        $this->db->on('query', static function () use (&$thrown): void {
            if (!$thrown) { // the first statement told: the insert
                $thrown = true;

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
            $this->assertStringStartsWith('PDO::prepare() returned false: ', $previous->getMessage());
            $this->assertStringContainsString('missing_table', $previous->getMessage(), "the engine's message names the table");
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', (string) ($previous->errorInfo[0] ?? ''), "the engine's SQLSTATE");
            $this->assertNotSame('00000', $previous->errorInfo[0] ?? null);
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
            $this->assertStringStartsWith('PDOStatement::execute() returned false: ', $previous->getMessage());
            $this->assertMatchesRegularExpression('/^23[0-9A-Z]{3}$/', (string) $e->sqlState, 'SQLSTATE class 23: integrity constraint violation');
            $this->assertIsInt($e->driverCode, "the engine's number for the violation");
            $this->assertNotSame(0, $e->driverCode);
            $this->assertSame([$e->sqlState, $e->driverCode], [$previous->errorInfo[0] ?? null, $previous->errorInfo[1] ?? null], 'read from what PDO recorded');
            $this->assertSame($e->driverCode, $previous->getCode());
            $this->assertCount(1, $reported->all());
            $hook = $reported->all()[0];
            $this->assertSame([$e->sqlState, $e->driverCode], [$hook['sqlState'], $hook['driverCode']], 'the hook carries what the exception carries');
            $this->assertSame($e->driverCode, $hook['code'], 'the driver\'s number: what the stand-in PDOException carries as its code');
        }

        $this->assertCount(1, $this->queries, 'only the first insert ran');
        $this->assertSame($this->queries, $this->errors, 'the failed insert is told with the same statement (its text: tests/Driver)');
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
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', (string) $e->sqlState, "the engine's SQLSTATE");
            $this->assertNotSame('00000', $e->sqlState);
            $this->assertIsInt($e->driverCode, "the engine's number");
            $this->assertNotSame(0, $e->driverCode);
            $previous = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $previous);
            $this->assertSame([$e->sqlState, $e->driverCode], [$previous->errorInfo[0] ?? null, $previous->errorInfo[1] ?? null], 'read from errorInfo()');
            $this->assertCount(1, $reported->all());
            $hook = $reported->all()[0];
            $this->assertSame([$e->sqlState, $e->driverCode], [$hook['sqlState'], $hook['driverCode']]);
            $this->assertSame($e->driverCode, $hook['code'], 'the driver\'s number: what the stand-in PDOException carries as its code');
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
        $db = $this->connect(['pdoClass' => UnrecordedFailurePdo::class]);
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
