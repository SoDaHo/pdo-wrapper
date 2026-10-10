<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Query;

use PDOException;
use RuntimeException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Contract\Transaction\FalseReturningPdo;

/**
 * 'query.before': fires at the start of every query(), with the SQL and the parameters. A listener
 * that throws stops the statement - nothing is sent, neither 'query' nor 'error' fires.
 */
class QueryBeforeTest extends ContractTestCase
{
    /** @var list<string> What was told, in order: 'before: <sql>', 'query: <sql>', 'error: <sql>' */
    private array $told = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->on('query', function (array $data): void {
            $this->told[] = 'query: ' . (string) $data['sql'];
        });
        $this->db->on('error', function (array $data): void {
            $this->told[] = 'error: ' . (string) $data['sql'];
        });
    }

    public function testItFiresBeforeEveryStatementWithItsSqlAndParameters(): void
    {
        $payloads = [];
        $this->db->on('query.before', function (array $data) use (&$payloads): void {
            $this->told[] = 'before: ' . (string) $data['sql'];
            $payloads[] = $data;
        });

        $this->db->query('SELECT ? AS one', [1]);
        $this->db->insert('users', ['name' => 'Max']);

        $this->assertSame([['sql' => 'SELECT ? AS one', 'params' => [1]]], array_slice($payloads, 0, 1), 'the SQL and the parameters, nothing else');
        $this->assertSame(['Max'], $payloads[1]['params']);
        $this->assertSame([
            'before: SELECT ? AS one',
            'query: SELECT ? AS one',
            'before: ' . (string) $payloads[1]['sql'],
            'query: ' . (string) $payloads[1]['sql'],
        ], $this->told);
    }

    /**
     * What the test of a "fails before it runs" contract needs: the listener's exception reaches
     * the caller unchanged, nothing was sent, and no 'query' or 'error' listener heard of it.
     */
    public function testAListenerThatThrowsStopsTheStatement(): void
    {
        $stop = new RuntimeException('stopped before the insert');
        $this->db->on('query.before', static function (array $data) use ($stop): void {
            if (str_starts_with((string) $data['sql'], 'INSERT')) {
                throw $stop;
            }
        });

        try {
            $this->db->insert('users', ['name' => 'Max']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($stop, $e);
        }

        $this->assertSame([], $this->told, "neither 'query' nor 'error'");
        $this->assertSame(0, $this->db->table('users')->count(), 'nothing was sent');
    }

    /**
     * A PDOException of a listener is no failure of the statement: 'Query hook failed', as from a
     * 'query' listener - without the codes, which are the listener's, not the database's.
     */
    public function testAPdoExceptionOfAListenerIsAHookFailure(): void
    {
        $thrown = new PDOException('simulated failure');
        $thrown->errorInfo = ['40001', 1213, 'simulated deadlock'];
        $once = true;
        $this->db->on('query.before', static function (array $data) use ($thrown, &$once): void {
            if ($once && str_starts_with((string) $data['sql'], 'INSERT')) {
                $once = false;

                throw $thrown;
            }
        });

        try {
            $this->db->insert('users', ['name' => 'Max']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query hook failed', $e->getMessage());
            $this->assertSame($thrown, $e->getPrevious());
            $this->assertSame([null, null], [$e->sqlState, $e->driverCode], "the listener's codes are not the database's");
            $this->assertStringStartsWith('Not sent: a query.before listener threw: simulated failure | SQL: INSERT', (string) $e->getDebugMessage());
            $this->assertStringEndsWith(' | Params: ["Max"]', (string) $e->getDebugMessage());
        }

        $this->assertSame([], $this->told);
        $this->assertSame(0, $this->db->table('users')->count());

        $this->db->insert('users', ['id' => 5, 'name' => 'Moritz']);
        $this->assertSame(1, $this->db->table('users')->count(), 'the next statement runs: nothing is held back');
    }

    /**
     * Before the library's own checks: a statement the library then refuses was told as well - a
     * parameter that cannot be bound fires 'error' after it.
     */
    public function testItFiresAlsoForAStatementTheLibraryRefuses(): void
    {
        $this->db->on('query.before', function (array $data): void {
            $this->told[] = 'before: ' . (string) $data['sql'];
        });

        try {
            $this->db->query('SELECT ?', [['an', 'array']]);
            $this->fail('Expected QueryException');
        } catch (QueryException) {
        }

        $this->assertSame(['before: SELECT ?', 'error: SELECT ?'], $this->told);
    }

    /**
     * A listener that inserts (an audit row) runs before the statement it was told about: insert()
     * still returns its own new id.
     */
    public function testAListenerThatInsertsLeavesInsertItsOwnId(): void
    {
        $this->create('audit', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('audit', ['name' => 'first']);
        $this->db->insert('audit', ['name' => 'second']); // the audit ids run ahead of the users ids
        $this->db->on('query.before', function (array $data): void {
            if (str_contains((string) $data['sql'], 'users')) {
                $this->db->insert('audit', ['name' => (string) $data['sql']]);
            }
        });

        $id = $this->db->insert('users', ['name' => 'Max']);

        $this->assertSame(1, $id, "the users row's id, not the audit row's (3)");
        $this->assertSame(3, $this->db->table('audit')->count());
    }

    /**
     * insert() reads its new id in a step of its own statement: a listener that runs the very same
     * insert before it does not take that step. Shown with a PDO class whose first id read fails:
     * the failure is the insert's own, after both rows are written - not the listener's, before
     * the insert is sent.
     */
    public function testAListenerRepeatingTheInsertDoesNotTakeItsIdStep(): void
    {
        $db = $this->connect(['pdoClass' => FalseReturningPdo::class]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(FalseReturningPdo::class, $pdo);
        $repeated = false;
        $db->on('query.before', static function (array $data) use ($db, &$repeated): void {
            if (!$repeated && str_starts_with((string) $data['sql'], 'INSERT')) {
                $repeated = true;
                $db->execute((string) $data['sql'], (array) $data['params']);
            }
        });
        $pdo->fail = 'insert id';
        $pdo->once = true;

        try {
            $db->insert('users', ['name' => 'same']);
            $this->fail('Expected QueryException');
        } catch (QueryException) {
        }

        $this->assertSame(2, $db->table('users')->count(), "both statements ran: the failure is the insert's own");
    }

    /**
     * The payload is told, not taken back: a listener that changes it (by reference) changes
     * nothing of the statement.
     */
    public function testChangingThePayloadChangesNothing(): void
    {
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);
        $this->db->on('query.before', static function (array &$data): void {
            $data['sql'] = 'DELETE FROM users';
            $data['params'] = [];
        });

        $this->assertSame([['name' => 'Max']], $this->db->query('SELECT name FROM users WHERE id = ?', [1])->fetchAll());
        $this->assertSame(1, $this->db->table('users')->count(), 'nothing was deleted');
    }

    /**
     * Parameters that are references (`[&$id]`): the listener is told their values, so that what
     * it writes into the payload reaches neither the binding nor the caller's variable.
     */
    public function testAReferenceAmongTheParametersIsToldAsItsValue(): void
    {
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);
        $this->db->insert('users', ['id' => 2, 'name' => 'Moritz']);
        $this->db->on('query.before', static function (array $data): void {
            $params = is_array($data['params']) ? $data['params'] : [];
            $params[0] = 2;
            $params['spare'] = 'x';
            $data['params'] = $params;
        });
        $id = 1;
        $params = [&$id];

        $this->assertSame([['name' => 'Max']], $this->db->query('SELECT name FROM users WHERE id = ?', $params)->fetchAll());
        $this->assertSame(1, $id, "the caller's variable is unchanged");
    }
}
