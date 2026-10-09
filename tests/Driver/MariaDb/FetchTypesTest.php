<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * The PHP type each column type arrives as - the library's promise, the same on every supported
 * MariaDB version (this test runs on 10.11, 11.4 and 12.3), for a prepared statement, a query
 * without parameters, the builder and emulated prepares.
 */
class FetchTypesTest extends ContractTestCase
{
    private const EXPECTED = [
        'i' => 'int', 'bi' => 'int', 'ubi' => 'string', 'small' => 'int', 'flag' => 'int', 'bool_flag' => 'int',
        'de' => 'string', 'f' => 'float', 'd' => 'float', 'vc' => 'string', 'txt' => 'string', 'j' => 'string',
        'dt' => 'string', 'ts' => 'string', 'n' => 'null', 'json_field' => 'string', 'total' => 'string', 'mean' => 'string', 'cnt' => 'int',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DROP TABLE IF EXISTS fetch_types');
        $this->db->execute(
            'CREATE TABLE fetch_types (i INT, bi BIGINT, ubi BIGINT UNSIGNED, small SMALLINT, flag TINYINT(1), bool_flag BOOL, '
            . 'de DECIMAL(10,2), f FLOAT, d DOUBLE, vc VARCHAR(10), txt TEXT, j JSON, dt DATETIME, ts TIMESTAMP NULL, n INT NULL)'
        );
        $this->db->execute(
            "INSERT INTO fetch_types VALUES (1, 9007199254740993, 18446744073709551615, 7, 1, TRUE, 12.50, 1.5, 2.25, 'x', 'long', '{\"a\":1}', '2026-10-04 12:00:00', '2026-10-04 12:00:00', NULL)"
        );
    }

    protected function tearDown(): void
    {
        unset($this->db);
        $this->connect()->execute('DROP TABLE IF EXISTS fetch_types');
        parent::tearDown();
    }

    public function testEveryColumnTypeArrivesAsTheSamePhpType(): void
    {
        $emulated = $this->connect(['options' => [PDO::ATTR_EMULATE_PREPARES => true]]);

        foreach (['native prepares' => $this->db, 'emulated prepares' => $emulated] as $how => $db) {
            $this->assertSame(self::EXPECTED, $this->types($db->query($this->select(), [1])->fetch()), "{$how}: a prepared statement");
            $this->assertSame(self::EXPECTED, $this->types($db->query(str_replace('i = ?', 'i = 1', $this->select()))->fetch()), "{$how}: without parameters");
        }

        $this->assertSame(
            ['i' => 'int', 'de' => 'string', 'f' => 'float', 'n' => 'null'],
            $this->types($this->db->table('fetch_types')->select(['i', 'de', 'f', 'n'])->first()),
            'the builder'
        );
        $this->assertSame(1, $this->db->table('fetch_types')->max('i'));
        $this->assertSame('1', $this->db->table('fetch_types')->sum('i'), 'SUM() of integers is DECIMAL');
        $this->assertSame('12.500000', $this->db->table('fetch_types')->avg('de'));
    }

    private function select(): string
    {
        return "SELECT i, bi, ubi, small, flag, bool_flag, de, f, d, vc, txt, j, dt, ts, n, JSON_UNQUOTE(JSON_EXTRACT(j, '$.a')) AS json_field, "
            . 'SUM(i) OVER () AS total, AVG(i) OVER () AS mean, COUNT(*) OVER () AS cnt FROM fetch_types WHERE i = ?';
    }

    /**
     * @return array<array-key, string>
     */
    private function types(mixed $row): array
    {
        $this->assertIsArray($row);

        return array_map(get_debug_type(...), $row);
    }

    /**
     * sum() and avg() hand on what MariaDB delivers - a numeric string, a float or null. Any other
     * type means the connection does not deliver MariaDB's types: thrown, never passed off as "no
     * value". Replayed with a statement class that delivers integers.
     */
    public function testSumAndAvgRefuseATypeMariaDbDoesNotDeliver(): void
    {
        $db = $this->connect(['options' => [PDO::ATTR_STATEMENT_CLASS => [IntegerDeliveringStatement::class]]]);

        foreach (['sum' => static fn (): mixed => $db->table('fetch_types')->sum('i'), 'avg' => static fn (): mixed => $db->table('fetch_types')->where('i', 1)->avg('small')] as $method => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $method);
            } catch (QueryException $e) {
                $this->assertSame('Query failed', $e->getMessage());
                $this->assertSame(sprintf('%s() got int from the connection: MariaDB delivers a numeric string, a float or NULL here. The connection does not deliver the types this library promises (see MariaDbDriver).', $method), $e->getDebugMessage());
            }
        }
        $this->assertNull($db->table('fetch_types')->where('i', 0)->sum('i'), 'null still means no value');

        // A row whose value is false is a value, not "no row": it meets the same check
        $db = $this->connect(['options' => [PDO::ATTR_STATEMENT_CLASS => [FalseValueStatement::class]]]);
        try {
            $db->table('fetch_types')->sum('i');
            $this->fail('Expected QueryException: false');
        } catch (QueryException $e) {
            $this->assertSame('sum() got bool from the connection: MariaDB delivers a numeric string, a float or NULL here. The connection does not deliver the types this library promises (see MariaDbDriver).', $e->getDebugMessage());
        }
    }

    /**
     * count() turns what MariaDB delivers into an int and "no row" (a having() without groupBy()
     * that filtered out the one group) into 0. A value that is no number is thrown, as by sum():
     * never passed off as 0.
     */
    public function testCountRefusesAValueThatIsNoNumber(): void
    {
        $this->assertSame(0, $this->db->table('fetch_types')->having(Database::raw('COUNT(*)'), '>', 1000)->count(), 'no row: none to count');

        $db = $this->connect(['options' => [PDO::ATTR_STATEMENT_CLASS => [FalseValueStatement::class]]]);
        try {
            $db->table('fetch_types')->count();
            $this->fail('Expected QueryException: false');
        } catch (QueryException $e) {
            $this->assertSame('count() got bool from the connection: MariaDB delivers an integer here. The connection does not deliver the types this library promises (see MariaDbDriver).', $e->getDebugMessage());
        }
    }
}
