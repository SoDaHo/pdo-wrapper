<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Tests\Support\Untyped;

/**
 * Column => value arrays whose keys are integers - a list, a column named by digits alone - or
 * qualified names (table.column: a second spelling of a column a deny-list would miss), and a
 * having() column that is an expression: a QueryException before anything is sent, never a
 * TypeError from the quoting and never the server's "unknown column". No database needed: every
 * case throws before the statement exists.
 */
class ColumnKeyGuardTest extends TestCase
{
    private function db(): AbstractDriver
    {
        return new class () extends AbstractDriver {
        };
    }

    private function table(): QueryBuilder
    {
        return $this->db()->table('t');
    }

    /**
     * Lists and digit keys are no array<string, mixed>: called untyped, as code without static analysis does.
     *
     * @return iterable<string, array{\Closure(AbstractDriver): mixed, non-empty-string}>
     */
    public static function numericKeys(): iterable
    {
        yield 'builder update()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->table('t')->where('id', 1)->update(...), [0 => 'x']), 'update() needs column names as keys, got the numeric key 0.'];
        yield 'builder increment() $extra' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->table('t')->where('id', 1)->increment(...), 'n', 1, ['7' => 'x']), 'increment() with $extra needs column names as keys, got the numeric key 7.'];
        yield 'builder decrement() $extra' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->table('t')->where('id', 1)->decrement(...), 'n', 1, ['x']), 'decrement() with $extra needs column names as keys, got the numeric key 0.'];
        yield 'insert()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->insert(...), 't', ['x']), 'The columns to insert need column names as keys, got the numeric key 0'];
        yield 'update() data' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->update(...), 't', ['42' => 'x'], ['id' => 1]), 'The columns to set need column names as keys, got the numeric key 42'];
        yield 'update() where' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->update(...), 't', ['a' => 'x'], [1]), 'The WHERE conditions need column names as keys, got the numeric key 0'];
        yield 'delete()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->delete(...), 't', [5 => 1]), 'The WHERE conditions need column names as keys, got the numeric key 5'];
        yield 'findOne()' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->findOne(...), 't', [0 => 1]), 'The WHERE conditions need column names as keys, got the numeric key 0'];
        yield 'upsert() update' => [static fn (AbstractDriver $db): mixed => Untyped::call($db->upsert(...), 't', ['id' => 1], ['x']), 'The columns to set need column names as keys, got the numeric key 0'];
    }

    /**
     * A key with a dot would be taken apart by the quoting and name the column after it: "users.role"
     * and "db.users.role" set "role" just as "role" does. Refused in every method that takes column
     * => value pairs to write or to match.
     *
     * @return iterable<string, array{\Closure(AbstractDriver): mixed, non-empty-string}>
     */
    public static function qualifiedKeys(): iterable
    {
        yield 'insert()' => [static fn (AbstractDriver $db): mixed => $db->insert('users', ['users.role' => 'admin']), 'The columns to insert need the plain names of columns as keys, got "users.role"'];
        yield 'update() data' => [static fn (AbstractDriver $db): mixed => $db->update('users', ['app.users.role' => 'admin'], ['id' => 1]), 'The columns to set need the plain names of columns as keys, got "app.users.role"'];
        yield 'update() where' => [static fn (AbstractDriver $db): mixed => $db->update('users', ['name' => 'x'], ['users.id' => 1]), 'The WHERE conditions need the plain names of columns as keys, got "users.id"'];
        yield 'delete()' => [static fn (AbstractDriver $db): mixed => $db->delete('users', ['users.id' => 1]), 'The WHERE conditions need the plain names'];
        yield 'findOne()' => [static fn (AbstractDriver $db): mixed => $db->findOne('users', ['users.id' => 1]), 'The WHERE conditions need the plain names'];
        yield 'findAll()' => [static fn (AbstractDriver $db): mixed => $db->findAll('users', ['users.id' => 1]), 'The WHERE conditions need the plain names'];
        yield 'upsert() row' => [static fn (AbstractDriver $db): mixed => $db->upsert('users', ['users.id' => 1], ['name' => 'x']), 'The columns to insert need the plain names'];
        yield 'upsert() update' => [static fn (AbstractDriver $db): mixed => $db->upsert('users', ['id' => 1], ['users.role' => 'admin']), 'The columns to set need the plain names'];
        yield 'insertWhen()' => [static fn (AbstractDriver $db): mixed => $db->insertWhen('users', ['users.role' => 'admin'], '1 = 1'), 'The columns to insert need the plain names'];
        yield 'insertIgnore()' => [static fn (AbstractDriver $db): mixed => $db->insertIgnore('users', ['users.role' => 'admin']), 'The columns to insert need the plain names'];
        yield 'builder update()' => [static fn (AbstractDriver $db): mixed => $db->table('users')->where('id', 1)->update(['users.role' => 'admin']), 'update() needs the plain names of columns as keys, got "users.role"'];
        yield 'builder increment() $extra' => [static fn (AbstractDriver $db): mixed => $db->table('users')->where('id', 1)->increment('n', 1, ['users.role' => 'admin']), 'increment() with $extra needs the plain names of columns as keys, got "users.role"'];
        yield 'builder increment() column' => [static fn (AbstractDriver $db): mixed => $db->table('users')->where('id', 1)->increment('users.attempts'), 'increment() needs the plain name of a column, got "users.attempts"'];
        yield 'builder decrement() column' => [static fn (AbstractDriver $db): mixed => $db->table('users')->where('id', 1)->decrement('users.attempts'), 'decrement() needs the plain name of a column, got "users.attempts"'];
        yield 'updateMultiple() data' => [static fn (AbstractDriver $db): mixed => $db->updateMultiple('users', [['id' => 1, 'users.role' => 'admin']]), 'The columns to set need the plain names'];
        yield 'updateMultiple() key column' => [static fn (AbstractDriver $db): mixed => $db->updateMultiple('users', [['users.id' => 1, 'name' => 'x']], 'users.id'), 'The key column of updateMultiple() need the plain names'];
        yield 'upsertReturning() row' => [static fn (AbstractDriver $db): mixed => $db->upsertReturning('users', ['users.id' => 1], ['name' => 'x']), 'The columns to insert need the plain names'];
        yield 'upsertReturning() update' => [static fn (AbstractDriver $db): mixed => $db->upsertReturning('users', ['id' => 1], ['users.role' => 'admin']), 'The columns to set need the plain names'];
        yield 'insertWhenReturning()' => [static fn (AbstractDriver $db): mixed => $db->insertWhenReturning('users', ['users.role' => 'admin'], '1 = 1'), 'The columns to insert need the plain names'];
    }

    /**
     * @param \Closure(AbstractDriver): mixed $call
     * @param non-empty-string $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('qualifiedKeys')]
    public function testAQualifiedKeyIsAQueryException(\Closure $call, string $message): void
    {
        try {
            $call($this->db());
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage());
            $this->assertStringStartsWith($message, (string) $e->getDebugMessage());
        }
    }

    /**
     * @param \Closure(AbstractDriver): mixed $call
     * @param non-empty-string $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numericKeys')]
    public function testANumericKeyIsAQueryException(\Closure $call, string $message): void
    {
        try {
            $call($this->db());
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringStartsWith($message, (string) $e->getDebugMessage());
        }
    }

    /**
     * A having() string with an expression in it names no column - unless a select() entry carries
     * that name: refused when the query is built, not when having() is called (the select() may come
     * after it).
     */
    public function testAnAggregateAsAHavingStringIsAQueryExceptionWithoutASelectOfThatName(): void
    {
        $query = $this->table()->groupBy('status')->having('COUNT(*)', '>', 1);
        try {
            $query->toSql();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('having() takes a column or an alias as a string, and "COUNT(*)" is an expression no select() entry is named after', (string) $e->getDebugMessage());
        }
    }

    /**
     * select(raw('COUNT(*)')) names its output column "COUNT(*)": having('COUNT(*)') refers to it, as
     * MariaDB resolves the quoted name. Compared without case; an aliased raw
     * entry is named by its alias.
     */
    public function testAHavingStringNamesASelectedExpression(): void
    {
        [$sql] = $this->table()->groupBy('status')->having('COUNT(*)', '>', 1)->select(['status', Database::raw('COUNT(*)')])->toSql();
        $this->assertSame('SELECT `status`, COUNT(*) FROM `t` GROUP BY `status` HAVING `COUNT(*)` > ?', $sql);

        [$sql] = $this->table()->select(['status', Database::raw('count(*)')])->groupBy('status')->having('COUNT(*)', '>', 1)->toSql();
        $this->assertStringEndsWith('HAVING `COUNT(*)` > ?', $sql);

        try {
            $this->table()->select(['status', Database::raw('COUNT(*) AS n')])->groupBy('status')->having('COUNT(*)', '>', 1)->toSql();
            $this->fail('Expected QueryException: the entry is named n');
        } catch (QueryException) {
            // refused
        }
    }

    public function testHavingStillTakesANameAndARawExpression(): void
    {
        [$sql, $params] = $this->table()
            ->select(['status', Database::raw('COUNT(*) AS n')])
            ->groupBy('status')
            ->having('n', '>', 1)
            ->having(Database::raw('SUM(score)'), '>=', 10)
            ->toSql();

        $this->assertSame('SELECT `status`, COUNT(*) AS n FROM `t` GROUP BY `status` HAVING `n` > ? AND SUM(score) >= ?', $sql);
        $this->assertSame([1, 10], $params);
    }
}
