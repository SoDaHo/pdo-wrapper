<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\SqliteDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * The SQL the builder renders per dialect, without executing it: IS / IS NOT, OFFSET without LIMIT, row locks.
 */
class QueryBuilderDialectTest extends TestCase
{
    private function builder(string $dialect, string $quoteChar = '"'): QueryBuilder
    {
        return new QueryBuilder(Database::sqlite(), 'users', $quoteChar, $dialect);
    }

    /**
     * A custom driver that overrides only getQuoteChar() keeps the dialect it had before the builder
     * knew dialects: a backtick means MySQL (no LIKE escape clause, MySQL lock syntax), anything else ANSI.
     */
    public function testACustomDriverGetsItsDialectFromTheQuoteCharacter(): void
    {
        $backtick = new class () extends SqliteDriver {
            protected function getQuoteChar(): string
            {
                return '`';
            }

            protected function getDialect(): string
            {
                return AbstractDriver::getDialect(); // the default, not SQLite's override
            }
        };
        $ansi = new class () extends SqliteDriver {
            protected function getQuoteChar(): string
            {
                return '"';
            }

            protected function getDialect(): string
            {
                return AbstractDriver::getDialect();
            }
        };

        [$sql] = $backtick->table('users')->whereLike('name', 'a%')->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `name` LIKE ? LOCK IN SHARE MODE', $sql);
        [$sql] = $ansi->table('users')->whereLike('name', 'a%')->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM "users" WHERE "name" LIKE ? ESCAPE \'\\\' FOR SHARE', $sql);
    }

    /**
     * delete() with limit(): `DELETE ... [ORDER BY ...] LIMIT n` on MySQL only; the other dialects
     * throw instead of silently deleting every matching row.
     */
    public function testDeleteWithLimitRendersOnMysqlAndThrowsElsewhere(): void
    {
        $db = Database::sqlite();
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $rendered = [];
        $db->on('query', static function (array $context) use (&$rendered): void {
            $rendered[] = (string) $context['sql'];
        });
        $db->on('error', static function (array $context) use (&$rendered): void {
            $rendered[] = (string) $context['sql'];
        });

        // MySQL dialect over SQLite: the statement is rendered; whether SQLite accepts DELETE ... LIMIT depends on its build
        try {
            (new QueryBuilder($db, 'users', '`', QueryBuilder::DIALECT_MYSQL))->where('id', '>', 5)->orderBy('id')->limit(2)->delete();
        } catch (QueryException) {
            // SQLite without SQLITE_ENABLE_UPDATE_DELETE_LIMIT
        }
        $this->assertSame(['DELETE FROM `users` WHERE `id` > ? ORDER BY `id` ASC LIMIT 2'], $rendered);
        $rendered = [];
        try {
            (new QueryBuilder($db, 'users', '`', QueryBuilder::DIALECT_MYSQL))->where('id', '>', 5)->limit(3)->delete();
        } catch (QueryException) {
        }
        $this->assertSame(['DELETE FROM `users` WHERE `id` > ? LIMIT 3'], $rendered, 'orderBy() is optional');

        foreach ([QueryBuilder::DIALECT_SQLITE, QueryBuilder::DIALECT_PGSQL, QueryBuilder::DIALECT_ANSI] as $dialect) {
            $rendered = [];
            try {
                (new QueryBuilder($db, 'users', '"', $dialect))->where('id', '>', 5)->orderBy('id')->limit(2)->delete();
                $this->fail("Expected QueryException for dialect {$dialect}");
            } catch (QueryException $e) {
                $this->assertStringContainsString("only MySQL/MariaDB support (dialect \"{$dialect}\")", $e->getDebugMessage() ?? '');
            }
            $this->assertSame([], $rendered, 'nothing was executed');
        }

        // still unsupported, also on MySQL: orderBy() without limit(), offset(), and limit() on update()
        $mysql = static fn (): QueryBuilder => new QueryBuilder($db, 'users', '`', QueryBuilder::DIALECT_MYSQL);
        foreach ([
            'orderBy() alone' => fn () => $mysql()->where('id', 1)->orderBy('id')->delete(),
            'offset()' => fn () => $mysql()->where('id', 1)->limit(2)->offset(1)->delete(),
            'update()' => fn () => $mysql()->where('id', 1)->limit(2)->update(['name' => 'x']),
        ] as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected QueryException");
            } catch (QueryException $e) {
                $this->assertStringContainsString('does not support', $e->getDebugMessage() ?? '', $name);
            }
        }
        $this->assertSame([], $rendered, 'none of them reached the database');
        $this->assertSame(0, $mysql()->select(['name'])->where('id', 999)->delete(), 'select() is ignored on delete()');
        // a builder locked for a first() may be reused: distinct() and locks cannot change which rows are hit
        $rendered = [];
        $sqlite = static fn (): QueryBuilder => new QueryBuilder($db, 'users', '"', QueryBuilder::DIALECT_SQLITE);
        $this->assertSame(0, $sqlite()->where('id', 999)->lockForUpdate()->update(['name' => 'x']), 'a row lock is ignored on update()');
        $this->assertSame(0, $sqlite()->where('id', 999)->distinct()->sharedLock()->delete(), 'distinct() and a row lock are ignored on delete()');
        $this->assertSame(['UPDATE "users" SET "name" = ? WHERE "id" = ?', 'DELETE FROM "users" WHERE "id" = ?'], $rendered);

        // an orderBy() direction that is not ASC/DESC (case and surrounding whitespace aside) would
        // silently become ASC and delete the wrong rows
        foreach (['DESCENDING', 'down', 'DESC NULLS LAST'] as $direction) {
            $rendered = [];
            try {
                $mysql()->where('id', '>', 0)->orderBy('id', $direction)->limit(1)->delete();
                $this->fail("Expected QueryException for direction '{$direction}'");
            } catch (QueryException $e) {
                $this->assertStringContainsString('needs an explicit ASC or DESC', $e->getDebugMessage() ?? '');
            }
            $this->assertSame([], $rendered);
        }
        $this->assertSame('SELECT * FROM `users` ORDER BY `id` ASC', $mysql()->orderBy('id', 'down')->toSql()[0], 'selects keep the ASC fallback');
        $rendered = [];
        try {
            $mysql()->where('id', '>', 0)->orderBy('id', ' desc')->limit(1)->delete();
        } catch (QueryException) {
        }
        $this->assertSame(['DELETE FROM `users` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1'], $rendered, 'surrounding whitespace and case are tolerated');
    }

    /**
     * A custom driver that does not override now()/utcNow() gets the SQL standard expression.
     */
    public function testAbstractDriverTimestampDefaultsAreCurrentTimestamp(): void
    {
        $driver = new class () extends SqliteDriver {
            public function now(): RawExpression
            {
                return AbstractDriver::now();
            }

            public function utcNow(): RawExpression
            {
                return AbstractDriver::utcNow();
            }
        };

        $this->assertSame('CURRENT_TIMESTAMP', (string) $driver->now());
        $this->assertSame('CURRENT_TIMESTAMP', (string) $driver->utcNow());
        $this->assertSame('SELECT * FROM `users` WHERE `created_at` < CURRENT_TIMESTAMP', $driver->table('users')->where('created_at', '<', $driver->now())->toSql()[0]);
    }

    public function testIsAndIsNotAreNullSafeEqualityPerDialect(): void
    {
        [$sql, $params] = $this->builder(QueryBuilder::DIALECT_SQLITE)->where('name', 'IS', 'x')->where('role', 'IS NOT', 'y')->toSql();
        $this->assertSame('SELECT * FROM "users" WHERE "name" IS ? AND "role" IS NOT ?', $sql);
        $this->assertSame(['x', 'y'], $params);

        [$sql] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')->where('name', 'IS', 'x')->where('role', 'IS NOT', 'y')->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `name` <=> ? AND NOT (`role` <=> ?)', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_PGSQL)->where('name', 'IS', 'x')->where('role', 'IS NOT', 'y')->toSql();
        $this->assertSame('SELECT * FROM "users" WHERE "name" IS NOT DISTINCT FROM ? AND "role" IS DISTINCT FROM ?', $sql);
    }

    /**
     * A raw right side keeps IS / IS NOT verbatim: truth tests like `flag IS TRUE` were valid before.
     */
    public function testIsWithARawValueIsPassedThroughUnchanged(): void
    {
        [$sql, $params] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')
            ->where('flag', 'IS', Database::raw('TRUE'))
            ->where('deleted', 'IS NOT', Database::raw('NULL'))
            ->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `flag` IS TRUE AND `deleted` IS NOT NULL', $sql);
        $this->assertSame([], $params);

        [$sql] = $this->builder(QueryBuilder::DIALECT_PGSQL)
            ->groupBy('flag')
            ->having('flag', 'IS', Database::raw('UNKNOWN'))
            ->toSql();
        $this->assertSame('SELECT * FROM "users" GROUP BY "flag" HAVING "flag" IS UNKNOWN', $sql);
    }

    public function testWhereAcceptsNamedArguments(): void
    {
        [$sql, $params] = $this->builder(QueryBuilder::DIALECT_SQLITE)->where(column: 'id', value: 5)->toSql();
        $this->assertSame('SELECT * FROM "users" WHERE "id" = ?', $sql);
        $this->assertSame([5], $params);

        [$sql, $params] = $this->builder(QueryBuilder::DIALECT_SQLITE)->where(column: 'age', operatorOrValue: '>', value: 18)->toSql();
        $this->assertSame('SELECT * FROM "users" WHERE "age" > ?', $sql);
        $this->assertSame([18], $params);
    }

    /**
     * exists() renders SELECT 1 ... LIMIT 1 and keeps the lock (SQLite cannot run FOR UPDATE, so the
     * SQL is read from the error hook; the lock's effect is proven on MySQL/PostgreSQL with a second connection).
     */
    public function testExistsKeepsTheLockAndLocksRejectGrouping(): void
    {
        $db = Database::sqlite();
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $seen = [];
        $db->on('error', function (array $data) use (&$seen): void {
            $seen[] = $data['sql'];
        });
        $builder = new QueryBuilder($db, 'users', '"', QueryBuilder::DIALECT_PGSQL);

        try {
            $builder->where('id', 7)->lockForUpdate()->exists();
        } catch (QueryException) {
            // SQLite rejects FOR UPDATE; only the rendered SQL matters here
        }
        $this->assertSame(['SELECT 1 FROM "users" WHERE "id" = ? LIMIT 1 FOR UPDATE'], $seen);

        $this->assertTrue((new QueryBuilder($db, 'users', '"', QueryBuilder::DIALECT_SQLITE))->lockForUpdate()->exists() === false);

        $this->expectException(QueryException::class);
        $this->builder(QueryBuilder::DIALECT_MYSQL, '`')->groupBy('role')->lockForUpdate()->toSql();
    }

    /**
     * The lock prohibition holds for both lock kinds, all grouping clauses and every dialect (SQLite too).
     */
    public function testLocksRejectDistinctGroupByAndHavingOnEveryDialect(): void
    {
        $cases = [
            [QueryBuilder::DIALECT_MYSQL, '`', fn (QueryBuilder $q) => $q->distinct()->sharedLock()],
            [QueryBuilder::DIALECT_PGSQL, '"', fn (QueryBuilder $q) => $q->having(Database::raw('COUNT(*)'), '>', 1)->lockForUpdate()],
            [QueryBuilder::DIALECT_SQLITE, '"', fn (QueryBuilder $q) => $q->groupBy('role')->lockForUpdate()],
            [QueryBuilder::DIALECT_ANSI, '"', fn (QueryBuilder $q) => $q->groupBy('role')->sharedLock()],
        ];

        foreach ($cases as [$dialect, $quoteChar, $configure]) {
            try {
                $configure($this->builder($dialect, $quoteChar))->toSql();
                $this->fail("Expected QueryException for dialect {$dialect}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be combined with distinct(), groupBy() or having()', $e->getDebugMessage() ?? '');
            }
        }
    }

    /**
     * exists() must not slip past the lock prohibition through its count() fallback (having without groupBy).
     */
    public function testExistsRejectsALockedHavingInsteadOfDroppingTheLock(): void
    {
        $db = Database::sqlite();
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $executed = [];
        $db->on('query', function (array $data) use (&$executed): void {
            $executed[] = $data['sql'];
        });

        foreach (['lockForUpdate', 'sharedLock'] as $lock) {
            try {
                (new QueryBuilder($db, 'users', '"', QueryBuilder::DIALECT_SQLITE))
                    ->having(Database::raw('COUNT(*)'), '>', Database::raw('0'))
                    ->{$lock}()
                    ->exists();
                $this->fail("Expected QueryException for {$lock}()");
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be combined', $e->getDebugMessage() ?? '');
            }
        }

        $this->assertSame([], $executed, 'nothing may run unlocked');
    }

    public function testIsInJoinAndHavingUsesTheSameRendering(): void
    {
        [$sql, $params] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')
            ->join('profiles', 'users.id', 'IS', 'profiles.user_id')
            ->groupBy('name')
            ->having('name', 'IS NOT', 'x')
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `profiles` ON `users`.`id` <=> `profiles`.`user_id` GROUP BY `name` HAVING NOT (`name` <=> ?)',
            $sql
        );
        $this->assertSame(['x'], $params);
    }

    public function testOffsetWithoutLimitGetsTheDialectsUnlimitedLimit(): void
    {
        [$sql] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')->offset(5)->toSql();
        $this->assertSame('SELECT * FROM `users` LIMIT 18446744073709551615 OFFSET 5', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_SQLITE)->offset(5)->toSql();
        $this->assertSame('SELECT * FROM "users" LIMIT -1 OFFSET 5', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_PGSQL)->offset(5)->toSql();
        $this->assertSame('SELECT * FROM "users" OFFSET 5', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')->limit(10)->offset(5)->toSql();
        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 5', $sql);
    }

    public function testRowLocksPerDialect(): void
    {
        [$sql] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')->where('id', 1)->limit(1)->lockForUpdate()->toSql();
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1 FOR UPDATE', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_MYSQL, '`')->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_PGSQL)->lockForUpdate()->toSql();
        $this->assertSame('SELECT * FROM "users" FOR UPDATE', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_PGSQL)->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM "users" FOR SHARE', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_ANSI)->lockForUpdate()->toSql();
        $this->assertSame('SELECT * FROM "users" FOR UPDATE', $sql);

        [$sql] = $this->builder(QueryBuilder::DIALECT_SQLITE)->lockForUpdate()->toSql();
        $this->assertSame('SELECT * FROM "users"', $sql);
    }

    /**
     * PostgreSQL rejects FOR UPDATE with aggregates, so count() and friends must drop the lock.
     */
    public function testAggregatesDropTheLock(): void
    {
        $db = Database::sqlite();
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $seen = [];
        $db->on('query', function (array $data) use (&$seen): void {
            $seen[] = $data['sql'];
        });
        $builder = new QueryBuilder($db, 'users', '"', QueryBuilder::DIALECT_PGSQL);

        $count = $builder->lockForUpdate()->count();

        $this->assertSame(0, $count);
        $this->assertSame(['SELECT COUNT(*) as aggregate FROM "users"'], $seen);
        $this->assertSame('SELECT * FROM "users" FOR UPDATE', $builder->toSql()[0]);
    }

    public function testDialectDefaultsFromTheQuoteCharacterAndRejectsUnknownOnes(): void
    {
        [$sql] = (new QueryBuilder(Database::sqlite(), 'users', '`'))->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $sql);

        [$sql] = (new QueryBuilder(Database::sqlite(), 'users'))->sharedLock()->toSql();
        $this->assertSame('SELECT * FROM "users" FOR SHARE', $sql);

        $this->expectException(InvalidArgumentException::class);
        new QueryBuilder(Database::sqlite(), 'users', '"', 'oracle');
    }
}
