<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb\Query;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Contract\CrudTest;
use Sodaho\PdoWrapper\Tests\Contract\EdgeCases\InsertIdPdo;
use Sodaho\PdoWrapper\Tests\Support\ReportingPdo;

/**
 * The SQL text of statements the contract tests (tests/Contract) run, as MariaDB's driver sends
 * it, tells its listeners and writes into its debug messages: there the outcome is checked, which
 * every driver shares, here the text (backticks). What the builder renders without running
 * anything is in tests/Unit/ContractQueryRenderingTest.
 */
class SentStatementsTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        InsertIdPdo::reset();
    }

    protected function tearDown(): void
    {
        InsertIdPdo::reset();
        ReportingPdo::$reported = null;
        parent::tearDown();
    }

    /**
     * The statement the query hook sees: SQL and params as sent.
     *
     * @return array{string, array<mixed>}
     */
    private function sent(callable $run): array
    {
        $seen = [];
        $this->db->on('query', static function (array $data) use (&$seen): void {
            $seen[] = [(string) $data['sql'], (array) $data['params']];
        });
        $run();

        return $seen[0];
    }

    // ---- the builder after exists() and count() --------------------------------------------------

    /**
     * tests/Contract/BuilderExecutionTest::testExistsWithGroupingDistinctAndHaving
     */
    public function testExistsLeavesTheGroupedSelectAsItWas(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->insert('users', ['name' => 'Max']);
        $grouped = $this->db->table('users')->select(['name'])->groupBy('name');

        $this->assertTrue($grouped->exists());
        $this->assertSame('SELECT `name` FROM `users` GROUP BY `name`', $grouped->toSql()[0], 'exists() must not change the builder');
    }

    /**
     * tests/Contract/Query/AggregateTest::testAggregatesLeaveTheBuilderUntouched
     */
    public function testCountLeavesTheBuilderAsItWas(): void
    {
        $this->create('users', ['id' => 'id', 'country' => 'text']);
        $this->db->insert('users', ['country' => 'DE']);
        $builder = $this->db->table('users')->select('country')->distinct()->orderBy('country')->limit(2);
        $builder->count();

        $this->assertSame('SELECT DISTINCT `country` FROM `users` ORDER BY `country` ASC LIMIT 2', $builder->toSql()[0]);
    }

    /**
     * An alias quoted with backticks counts as an alias: a grouped count and exists() keep the
     * entry for having() (tests/Contract/Query/AggregateTest::testCountWithGroupByKeepsAliasedSelectEntries).
     */
    public function testABacktickQuotedAliasCountsAsAnAlias(): void
    {
        $this->create('users', ['id' => 'id', 'country' => 'text']);
        foreach (['IS', 'DE', 'DE', 'AT', 'AT'] as $country) {
            $this->db->insert('users', ['country' => $country]);
        }

        $this->assertSame(2, $this->db->table('users')->select([Database::raw('COUNT(*) AS `n`')])->groupBy('country')->having('n', '>', Database::raw('1'))->count(), 'a quoted alias counts as an alias');
        $this->assertTrue($this->db->table('users')->select([Database::raw('COUNT(*) AS `n`')])->groupBy('country')->having('n', '>', Database::raw('1'))->exists());
    }

    // ---- what the hooks and the debug messages are told ------------------------------------------

    /**
     * tests/Contract/CrudTest::testInsertThrowsForAnIdThatIsNoIntegerOfPhp
     */
    #[DataProviderExternal(CrudTest::class, 'idsThatAreNoIntegerOfPhp')]
    public function testTheDebugMessageOfAnIdOutOfRangeNamesTheInsert(string $reported): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        ReportingPdo::$reported = $reported;
        $driver = $this->connect(['pdoClass' => ReportingPdo::class]);

        try {
            $driver->insert('users', ['name' => 'A']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('SQL: INSERT INTO `users` (`name`) VALUES (?) | Params: ["A"]', $e->getDebugMessage() ?? '');
        }
    }

    /**
     * tests/Contract/Query/QueryOutcomeTest::testQueryHookPdoExceptionIsReportedAsHookFailureAndKeepsTheRow
     */
    public function testAFailingQueryHookNamesTheInsertInTheDebugMessage(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $this->db->on('query', static function (): void {
            throw new PDOException('log table missing');
        });

        try {
            $this->db->insert('users', ['name' => 'Max']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame(
                'log table missing | SQL: INSERT INTO `users` (`name`) VALUES (?) | Params: ["Max"]',
                $e->getDebugMessage()
            );
        }
    }

    /**
     * tests/Contract/Query/QueryOutcomeTest::testAnExecuteFailureReportedWithoutAnExceptionIsAQueryException
     */
    public function testAnExecuteFailureReportedByReturningFalseIsToldWithItsSql(): void
    {
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $errors = [];
        $queries = [];
        $this->db->on('error', static function (array $data) use (&$errors): void {
            $errors[] = (string) $data['sql'];
        });
        $this->db->getPdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->db->on('query', static function (array $data) use (&$queries): void {
            $queries[] = (string) $data['sql'];
        });
        $this->db->insert('users', ['id' => 1, 'name' => 'Max']);

        try {
            $this->db->insert('users', ['id' => 1, 'name' => 'Duplicate']);
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
        }

        $this->assertSame(['INSERT INTO `users` (`id`, `name`) VALUES (?, ?)'], $errors);
        $this->assertSame(['INSERT INTO `users` (`id`, `name`) VALUES (?, ?)'], $queries, 'only the first insert ran');
    }

    /**
     * tests/Contract/EdgeCases/EdgeCaseTest::testAFailingInsertIdReadStillFiresTheQueryHookFirst
     */
    public function testTheInsertWhoseIdReadFailsIsToldToTheQueryHook(): void
    {
        InsertIdPdo::$throwingReads = 1;
        $driver = $this->connect(['pdoClass' => InsertIdPdo::class]);
        $this->create('users', ['id' => 'id', 'name' => 'text']);
        $seen = [];
        $driver->on('query', static function (array $data) use (&$seen): void {
            $seen[] = (string) $data['sql'];
        });

        try {
            $driver->insert('users', ['name' => 'Test']);
            $this->fail('The failed id read must reach the caller');
        } catch (QueryException $e) {
            $this->assertSame('Failed to get last insert ID', $e->getMessage());
        }

        $this->assertSame(['INSERT INTO `users` (`name`) VALUES (?)'], $seen, 'the statement ran, so its hook fired');
    }

    // ---- raw values with bindings of their own (tests/Contract/Query/RawValueTest) ---------------

    public function testBuilderUpdateWritesTheAssignmentsInTheOrderOfTheArray(): void
    {
        $this->createCounters();

        [$sql, $params] = $this->sent(fn () => $this->db->table('counters')->where('id', 1)->where('hits', '<', 100)->update([
            'name' => 'x',
            'hits' => Database::raw('hits + ? * ?', [10, 2]),
            'seen_at' => 'now',
        ]));

        $this->assertSame('UPDATE `counters` SET `name` = ?, `hits` = hits + ? * ?, `seen_at` = ? WHERE `id` = ? AND `hits` < ?', $sql, 'the assignments in the order of the array');
        $this->assertSame(['x', 10, 2, 'now', 1, 100], $params);
    }

    public function testDriverHelpersWriteARawValueWhereItStands(): void
    {
        $this->createCounters();

        [$sql, $params] = $this->sent(fn () => $this->db->insert('counters', ['name' => Database::raw('UPPER(?)', ['c']), 'hits' => 3, 'seen_at' => Database::raw('CONCAT(?, ?)', ['2026', '-01'])]));
        $this->assertSame('INSERT INTO `counters` (`name`, `hits`, `seen_at`) VALUES (UPPER(?), ?, CONCAT(?, ?))', $sql);
        $this->assertSame(['c', 3, '2026', '-01'], $params);

        [$sql, $params] = $this->sent(fn () => $this->db->update(
            'counters',
            ['hits' => Database::raw('hits + ?', [2]), 'seen_at' => 'then', 'name' => Database::raw('LOWER(?)', ['D'])],
            ['name' => Database::raw('UPPER(?)', ['c']), 'hits' => 3]
        ));
        $this->assertSame('UPDATE `counters` SET `hits` = hits + ?, `seen_at` = ?, `name` = LOWER(?) WHERE `name` = UPPER(?) AND `hits` = ?', $sql, 'the assignments in the order of the array');
        $this->assertSame([2, 'then', 'D', 'c', 3], $params);
    }

    /**
     * A value is sent as its __toString() renders it: a subclass that overrides it keeps its say.
     */
    public function testASubclassThatOverridesToStringIsSentThroughIt(): void
    {
        $this->createCounters();
        $shouting = new class ('hits + ?', [41]) extends RawExpression {
            public function __toString(): string
            {
                return '(' . $this->value . ')';
            }
        };

        [$sql, $params] = $this->sent(fn () => $this->db->update('counters', ['hits' => $shouting], ['hits' => new class ('1') extends RawExpression {
            public function __toString(): string
            {
                return '0 + ' . $this->value;
            }
        }]));

        $this->assertSame('UPDATE `counters` SET `hits` = (hits + ?) WHERE `hits` = 0 + 1', $sql);
        $this->assertSame([41], $params);
    }

    private function createCounters(): void
    {
        $this->create('counters', ['id' => 'id', 'name' => 'text', 'hits' => 'int NOT NULL DEFAULT 0', 'seen_at' => 'text']);
        $this->db->insert('counters', ['name' => 'a', 'hits' => 1]);
        $this->db->insert('counters', ['name' => 'b', 'hits' => 5]);
    }
}
