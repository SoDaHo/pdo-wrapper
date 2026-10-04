<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb\Query;

use PDO;
use PDOException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Recorder;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;

/**
 * MariaDB's own codes behind a failed statement, as exception and error hook carry them (the
 * contract tests, tests/Contract/Query/QueryOutcomeTest and WrapperTest, check their form and that
 * all places carry the same). Measured on MariaDB 10.11 and 11.4.
 */
class FailureCodesTest extends ContractTestCase
{
    /** @var Recorder<array<string, mixed>> */
    private Recorder $reported;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->reported = new Recorder(static fn (array $data): array => $data);
        $this->db->on('error', $this->reported);
    }

    /**
     * A syntax error: SQLSTATE 42000 ("syntax error or access violation"), error 1064. In
     * exception mode 'code' is PDO's own code of the exception: the SQLSTATE.
     */
    public function testASyntaxErrorCarries42000And1064(): void
    {
        try {
            $this->db->query('INVALID SQL');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame(['42000', 1064], [$e->sqlState, $e->driverCode]);
        }

        $this->assertCount(1, $this->reported->all());
        $hook = $this->reported->all()[0];
        $this->assertSame(['42000', 1064], [$hook['sqlState'], $hook['driverCode']]);
        $this->assertSame('42000', $hook['code']);
    }

    /**
     * A missing table, reported by returning false: native prepares send the statement to the
     * server in prepare(), which fails with SQLSTATE 42S02 ("base table or view not found"),
     * error 1146.
     */
    public function testAMissingTableReportedByReturningFalseCarries42S02And1146(): void
    {
        $this->db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        try {
            $this->db->query('SELECT * FROM missing_table');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $previous);
            $this->assertSame(
                sprintf("PDO::prepare() returned false: Table '%s.missing_table' doesn't exist (SQLSTATE 42S02)", TestEnvironment::mysql()['database']),
                $previous->getMessage()
            );
            $this->assertSame('42S02', $previous->errorInfo[0] ?? null);
            $this->assertSame(['42S02', 1146], [$e->sqlState, $e->driverCode]);
        }

        $this->assertCount(1, $this->reported->all());
        $hook = $this->reported->all()[0];
        $this->assertSame(['42S02', 1146], [$hook['sqlState'], $hook['driverCode']]);
        $this->assertSame(1146, $hook['code'], 'the driver\'s number: what the stand-in PDOException carries as its code');
    }

    /**
     * A duplicate primary key, reported by returning false from execute(): SQLSTATE 23000,
     * error 1062 ("duplicate entry").
     */
    public function testADuplicateKeyReportedByReturningFalseCarries23000And1062(): void
    {
        $this->db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);

        try {
            $this->db->insert('users', ['id' => 1, 'name' => 'Duplicate']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(PDOException::class, $previous);
            $this->assertSame("PDOStatement::execute() returned false: Duplicate entry '1' for key 'PRIMARY' (SQLSTATE 23000)", $previous->getMessage());
            $this->assertSame(1062, $previous->errorInfo[1] ?? null, 'MariaDB error code for a duplicate entry');
            $this->assertSame(1062, $previous->getCode());
            $this->assertSame(['23000', 1062], [$e->sqlState, $e->driverCode]);
        }

        $this->assertCount(1, $this->reported->all());
        $hook = $this->reported->all()[0];
        $this->assertSame(['23000', 1062], [$hook['sqlState'], $hook['driverCode']]);
        $this->assertSame(1062, $hook['code'], 'the driver\'s number: what the stand-in PDOException carries as its code');
    }
}
