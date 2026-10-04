<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Database::json() on MariaDB: what it returns for JSON null, booleans and a document that is no
 * valid JSON, an update over such a document in strict mode, GROUP BY under ONLY_FULL_GROUP_BY
 * (why the path is not bound), and the index of a virtual column declared with the expression.
 */
class JsonTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DROP TABLE IF EXISTS json_docs');
        $this->db->execute('CREATE TABLE json_docs (id INT PRIMARY KEY, payload TEXT, note VARCHAR(10))');
        $this->db->execute(
            "INSERT INTO json_docs VALUES (1, '{\"net\": \"a\", \"flag\": true, \"off\": false, \"nothing\": null}', ''), (2, 'not json', ''), (3, NULL, '')"
        );
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS json_docs');
        parent::tearDown();
    }

    /**
     * JSON null arrives as the text 'null' (not SQL NULL), booleans as 'true'/'false'; a document
     * that is no valid JSON and a NULL column give SQL NULL.
     */
    public function testWhatMariaDbReturns(): void
    {
        $rows = $this->db->table('json_docs')
            ->select(['id', Database::json('payload', '$.nothing')->as('nothing'), Database::json('payload', '$.flag')->as('flag'), Database::json('payload', '$.off')->as('off'), Database::json('payload', '$.net')->as('net')])
            ->orderBy('id')
            ->get();

        $this->assertSame([
            ['id' => 1, 'nothing' => 'null', 'flag' => 'true', 'off' => 'false', 'net' => 'a'],
            ['id' => 2, 'nothing' => null, 'flag' => null, 'off' => null, 'net' => null],
            ['id' => 3, 'nothing' => null, 'flag' => null, 'off' => null, 'net' => null],
        ], $rows);
        $this->assertSame([2, 3], array_column($this->db->table('json_docs')->whereNull(Database::json('payload', '$.net'))->orderBy('id')->get(), 'id'));
        $this->assertSame(0, $this->db->table('json_docs')->where(Database::json('payload', '$.net'), 'zz')->delete(), 'a delete only warns about the invalid document');
    }

    /**
     * In strict mode (MariaDB's default) an UPDATE fails when a row it reads holds no valid JSON:
     * error 4038, loud - a select or a delete only warns.
     */
    public function testAnUpdateOverAnInvalidDocumentFailsInStrictMode(): void
    {
        try {
            $this->db->table('json_docs')->where(Database::json('payload', '$.net'), 'a')->update(['note' => 'x']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame(4038, $e->driverCode);
        }

        $this->db->execute('DELETE FROM json_docs WHERE id = 2');
        $this->assertSame(1, $this->db->table('json_docs')->where(Database::json('payload', '$.net'), 'a')->update(['note' => 'x']));
    }

    /**
     * The path is written into the SQL: under ONLY_FULL_GROUP_BY MariaDB groups by the expression
     * and selects it; with the path bound it would refuse the query (error 1055).
     */
    public function testGroupByUnderOnlyFullGroupBy(): void
    {
        $this->db->execute("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ONLY_FULL_GROUP_BY')");
        $net = Database::json('payload', '$.net');

        $groups = $this->db->table('json_docs')->select([$net->as('net'), Database::raw('COUNT(*) AS n')])->groupBy($net)->orderBy($net)->get();
        $this->assertSame([[null, 2], ['a', 1]], array_map(static fn (array $row): array => [$row['net'], $row['n']], $groups));

        try {
            $this->db->query('SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) AS net, COUNT(*) AS n FROM json_docs GROUP BY JSON_UNQUOTE(JSON_EXTRACT(payload, ?))', ['$.net', '$.net']);
            $this->fail('Expected the bound path to be refused');
        } catch (QueryException $e) {
            $this->assertSame(1055, $e->driverCode);
        }
    }

    /**
     * A virtual column declared with the expression and the collation the JSON functions return
     * (utf8mb4_bin): from MariaDB 11.8 a where() on the expression uses its index; before, only a
     * where() on the column itself does.
     */
    public function testAVirtualColumnIndexServesTheExpressionFrom118(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS json_indexed');
        $this->db->execute("CREATE TABLE json_indexed (id INT PRIMARY KEY AUTO_INCREMENT, payload TEXT, net VARCHAR(64) COLLATE utf8mb4_bin AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.net'))) VIRTUAL, KEY net_idx (net))");
        try {
            for ($i = 0; $i < 200; $i++) {
                $this->db->insert('json_indexed', ['payload' => sprintf('{"net": "n%d"}', $i % 40)]);
            }
            $this->db->query('ANALYZE TABLE json_indexed')->fetchAll();
            $key = function (string $column): mixed {
                [$sql, $params] = $this->db->table('json_indexed')->select(['id'])->where($column === 'net' ? 'net' : Database::json('payload', '$.net'), 'n7')->toSql();
                $plan = $this->db->query('EXPLAIN ' . $sql, $params)->fetch(PDO::FETCH_ASSOC);

                return is_array($plan) ? $plan['key'] : false;
            };

            $version = (string) $this->db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            preg_match('/(\d+\.\d+\.\d+)-MariaDB/', $version, $match);
            $this->assertSame('net_idx', $key('net'), 'the column itself');
            $this->assertSame(version_compare($match[1] ?? '0', '11.8.0', '>=') ? 'net_idx' : null, $key('expression'), $version);
        } finally {
            $this->db->execute('DROP TABLE IF EXISTS json_indexed');
        }
    }
}
