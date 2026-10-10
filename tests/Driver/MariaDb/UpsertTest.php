<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use PDO;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Recorder;
use Sodaho\PdoWrapper\Tests\Support\StatementClassPdo;

/**
 * upsert() and insertWhen() with an update on MariaDB: the statements and their params in SQL
 * order, MariaDB's counts (1 inserted, 2 updated, 0 unchanged - and 0 for a false condition),
 * and the refusal on a connection that counts matched rows (ATTR_FOUND_ROWS).
 */
class UpsertTest extends ContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create('counters', ['id' => 'id', 'name' => 'text UNIQUE NOT NULL', 'n' => 'int NOT NULL']);
        $this->db->insert('counters', ['name' => 'a', 'n' => 1]);
    }

    public function testTheStatementsAndTheirParams(): void
    {
        $sent = new Recorder(static fn (array $context): array => [(string) $context['sql'], $context['params']]);
        $this->db->on('query', $sent);

        $this->db->table('counters')->upsert(['name' => 'a', 'n' => 5], ['n' => Database::raw('n + ?', [3]), 'name' => Database::value('name')]);
        $this->db->table('counters')->upsertReturning(['name' => 'b', 'n' => 5], ['n' => 6], ['id', Database::raw('n * 2 AS twice')]);
        $this->db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '? = 1', [1], ['n' => Database::raw('n + ?', [2])]);
        $this->db->table('counters')->insertWhenReturning(['name' => 'd', 'n' => 1], '? = 1', [0]);

        $this->assertSame([
            ['INSERT INTO `counters` (`name`, `n`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `n` = n + ?, `name` = VALUE(`name`)', ['a', 5, 3]],
            ['INSERT INTO `counters` (`name`, `n`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `n` = ? RETURNING `id`, n * 2 AS twice', ['b', 5, 6]],
            ['INSERT INTO `counters` (`name`, `n`) SELECT ?, ? FROM DUAL WHERE (? = 1) ON DUPLICATE KEY UPDATE `n` = n + ?', ['c', 1, 1, 2]],
            ['INSERT INTO `counters` (`name`, `n`) SELECT ?, ? FROM DUAL WHERE (? = 1) RETURNING *', ['d', 1, 0]],
        ], $sent->all());
    }

    /**
     * MariaDB's count: 1 inserted, 2 updated, 0 the row already held those values; insertWhen()
     * with an update also 0 for a false condition.
     */
    public function testTheCounts(): void
    {
        $this->assertSame(1, $this->db->table('counters')->upsert(['name' => 'b', 'n' => 1], ['n' => 1]));
        $this->assertSame(2, $this->db->table('counters')->upsert(['name' => 'b', 'n' => 1], ['n' => 2]));
        $this->assertSame(0, $this->db->table('counters')->upsert(['name' => 'b', 'n' => 1], ['n' => 2]));

        $this->assertSame(0, $this->db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '? = 1', [0], ['n' => 1]), 'condition false');
        $this->assertSame(1, $this->db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '? = 1', [1], ['n' => 1]));
        $this->assertSame(2, $this->db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '? = 1', [1], ['n' => 2]));
        $this->assertSame(0, $this->db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '? = 1', [1], ['n' => 2]), 'unchanged');

        $this->expectException(UniqueViolationException::class); // without an update a duplicate fails as an insert would
        $this->db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '? = 1', [1]);
    }

    /**
     * On a connection that counts matched rows, an unchanged row reports 1 like an insert:
     * upsert() and insertWhen() with an update are refused before anything is sent; the
     * RETURNING forms and insertWhen() without an update work.
     */
    public function testACountOfMatchedRowsIsRefused(): void
    {
        $db = $this->connect(['options' => [\Pdo\Mysql::ATTR_FOUND_ROWS => true]]);
        $sent = new Recorder(static fn (array $context): string => (string) $context['sql']);
        $db->on('query', $sent);

        foreach ([
            'upsert()' => static fn (): int => $db->table('counters')->upsert(['name' => 'a', 'n' => 1], ['n' => 1]),
            'insertWhen() with $update' => static fn (): int => $db->table('counters')->insertWhen(['name' => 'a', 'n' => 1], '1 = 1', [], ['n' => 1]),
        ] as $what => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $what);
            } catch (QueryException $e) {
                $this->assertSame('Insert failed', $e->getMessage());
                $this->assertSame($what . ' cannot tell an inserted row from an unchanged existing one on a connection opened with ATTR_FOUND_ROWS: the server reports 1 affected row for both. Use the RETURNING form instead.', $e->getDebugMessage());
            }
        }
        $this->assertSame([], $sent->all(), 'nothing was sent');

        $this->assertSame('a', $db->table('counters')->upsertReturning(['name' => 'a', 'n' => 1], ['n' => 1], ['name'])['name']);
        $this->assertSame(['name' => 'b'], $db->table('counters')->insertWhenReturning(['name' => 'b', 'n' => 1], '1 = 1', [], ['n' => 1], ['name']));
        $this->assertSame(1, $db->table('counters')->insertWhen(['name' => 'c', 'n' => 1], '1 = 1'));
    }

    /**
     * On a persistent connection PDO may hand back a connection an earlier request opened with
     * ATTR_FOUND_ROWS - its pool ignores the options (measured) -, so the counting methods are
     * refused there as well; the RETURNING forms work.
     */
    public function testAPersistentConnectionIsRefusedForCounts(): void
    {
        $db = $this->connect(['options' => [PDO::ATTR_PERSISTENT => 'pdo-wrapper-upsert-test']]);
        $why = 'on a persistent connection: PDO may hand back one an earlier request opened with ATTR_FOUND_ROWS, and the server then reports 1 affected row for both';

        foreach ([
            'upsert() cannot tell an inserted row from an unchanged existing one ' . $why . '. Use the RETURNING form instead.' => static fn (): int => $db->table('counters')->upsert(['name' => 'a', 'n' => 1], ['n' => 1]),
            'insertWhen() with $update cannot tell an inserted row from an unchanged existing one ' . $why . '. Use the RETURNING form instead.' => static fn (): int => $db->table('counters')->insertWhen(['name' => 'a', 'n' => 1], '1 = 1', [], ['n' => 1]),
            'insertIgnore() cannot tell an inserted row from an existing one ' . $why . '. Use insert() and catch UniqueViolationException instead.' => static fn (): int => $db->table('counters')->insertIgnore(['name' => 'a', 'n' => 1]),
        ] as $message => $call) {
            try {
                $call();
                $this->fail('Expected QueryException: ' . $message);
            } catch (QueryException $e) {
                $this->assertSame($message, $e->getDebugMessage());
            }
        }
        $this->assertSame('a', $db->table('counters')->upsertReturning(['name' => 'a', 'n' => 1], ['n' => 1], ['name'])['name']);
        $this->assertSame(1, $db->table('counters')->insertWhen(['name' => 'b', 'n' => 1], '1 = 1'));
    }

    /**
     * MariaDB returns the row after every upsert; a statement that returned none is reported, not
     * passed off as a row. Replayed with a statement class that finds no row.
     */
    public function testUpsertReturningWithoutARowBackThrows(): void
    {
        $db = $this->connect(StatementClassPdo::config(NoRowStatement::class));

        try {
            $db->table('counters')->upsertReturning(['name' => 'a', 'n' => 1], ['n' => 2], ['id']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Insert failed', $e->getMessage());
            $this->assertSame('upsertReturning() got no row back | SQL: INSERT INTO `counters` (`name`, `n`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `n` = ?', $e->getDebugMessage());
        }
    }
}
