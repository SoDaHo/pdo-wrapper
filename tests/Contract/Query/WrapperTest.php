<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * The statement methods every driver carries: query() and execute() with and without parameters,
 * lastInsertId(), the QueryException of a failed statement with its debug message, and the query hook.
 */
class WrapperTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test table
        $this->create('users', ['id' => 'id', 'name' => 'text', 'email' => 'text']);
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->db);
    }

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->db->query('SELECT 1 as test');

        $this->assertInstanceOf(PDOStatement::class, $stmt);
    }

    public function testQueryWithParams(): void
    {
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Max', 'max@example.com']);

        $stmt = $this->db->query('SELECT * FROM users WHERE name = ?', ['Max']);
        $result = $stmt->fetch();

        $this->assertIsArray($result);
        $this->assertSame('Max', $result['name']);
        $this->assertSame('max@example.com', $result['email']);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $affected = $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Max', 'max@example.com']);

        $this->assertSame(1, $affected);
    }

    public function testLastInsertId(): void
    {
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Max', 'max@example.com']);

        $id = $this->db->lastInsertId();

        $this->assertSame('1', $id);
    }

    public function testInvalidQueryThrowsQueryException(): void
    {
        $this->expectException(QueryException::class);

        $this->db->query('SELECT * FROM nonexistent_table');
    }

    public function testQueryExceptionHasDebugMessage(): void
    {
        try {
            $this->db->query('INVALID SQL');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertStringContainsString('INVALID SQL', $e->getDebugMessage() ?? '');
            return;
        }

        $this->fail('Expected QueryException was not thrown');
    }

    public function testQueryHookIsTriggered(): void
    {
        $hookData = null;

        $this->db->on('query', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        $this->db->query('SELECT 1 as test');

        $this->assertNotNull($hookData);
        $this->assertSame('SELECT 1 as test', $hookData['sql']);
        $this->assertArrayHasKey('duration', $hookData);
        $this->assertArrayHasKey('rows', $hookData);
    }

    /**
     * The codes are the engine's (its own numbers are checked in tests/Driver): a SQLSTATE and the
     * engine's number, the same as the exception carries.
     */
    public function testErrorHookIsTriggered(): void
    {
        $hookData = null;
        $thrown = null;

        $this->db->on('error', function (array $data) use (&$hookData) {
            $hookData = $data;
        });

        try {
            $this->db->query('INVALID SQL');
        } catch (QueryException $e) {
            // Expected
            $thrown = $e;
        }

        $this->assertNotNull($hookData);
        $this->assertSame('INVALID SQL', $hookData['sql']);
        $this->assertArrayHasKey('error', $hookData);
        $this->assertSame(['sql', 'params', 'error', 'code', 'sqlState', 'driverCode'], array_keys($hookData));
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', (string) $hookData['sqlState'], "the engine's SQLSTATE");
        $this->assertNotSame('00000', $hookData['sqlState']);
        $this->assertIsInt($hookData['driverCode'], "the engine's number");
        $this->assertNotSame(0, $hookData['driverCode']);
        $this->assertInstanceOf(QueryException::class, $thrown);
        $this->assertSame([$thrown->sqlState, $thrown->driverCode], [$hookData['sqlState'], $hookData['driverCode']], 'the hook carries what the exception carries');
        $previous = $thrown->getPrevious();
        $this->assertInstanceOf(PDOException::class, $previous);
        $this->assertSame([$previous->errorInfo[0] ?? null, $previous->errorInfo[1] ?? null], [$hookData['sqlState'], $hookData['driverCode']], 'read from what PDO recorded');
    }
}
