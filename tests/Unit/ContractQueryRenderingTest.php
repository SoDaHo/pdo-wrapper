<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * The SQL the builder renders for the queries the contract tests (tests/Contract) run: there their
 * bindings and outcome are checked, which every driver shares; here the text, as the builder of the
 * MariaDB driver writes it (backticks, `<=>`). The SQL a run sends to the database is in
 * tests/Driver/MariaDb/Query/SentStatementsTest.
 */
class ContractQueryRenderingTest extends TestCase
{
    /** What a driver's table() builds, on a connection that is never opened */
    private function table(string $table): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
        };

        return $db->table($table);
    }

    // ---- whereRaw() (tests/Contract/Query/WhereRawTest) ------------------------------------------

    public function testRawConditionsAreJoinedWithAndInParentheses(): void
    {
        [$sql, $params] = $this->table('users')
            ->where('status', 'active')
            ->whereRaw('LOWER(email) = ?', ['max@example.com'])
            ->whereRaw('score > ? OR score < ?', [15, 5])
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ? AND (LOWER(email) = ?) AND (score > ? OR score < ?)', $sql);
        $this->assertSame(['active', 'max@example.com', 15, 5], $params);
    }

    public function testRawConditionsKeepTheirPlaceInAGroupedSelect(): void
    {
        [$sql, $params] = $this->table('users')
            ->select(['status', Database::raw('COUNT(*) AS n')])
            ->whereIn('score', [10, Database::raw('20'), 30])
            ->whereRaw('LOWER(email) LIKE ? OR score >= ?', ['%example.com', 30])
            ->groupBy('status')
            ->having(Database::raw('COUNT(*)'), '>=', 1)
            ->toSql();

        $this->assertSame('SELECT `status`, COUNT(*) AS n FROM `users` WHERE `score` IN (?, 20, ?) AND (LOWER(email) LIKE ? OR score >= ?) GROUP BY `status` HAVING COUNT(*) >= ?', $sql);
        $this->assertSame([10, 30, '%example.com', 30, 1], $params);
    }

    // ---- edge cases (tests/Contract/EdgeCases/EdgeCaseTest) --------------------------------------

    /**
     * With two arguments the second one is the value, also when it spells an operator.
     */
    public function testAnOperatorNamedValueIsComparedWithEquals(): void
    {
        [$sql, $params] = $this->table('users')->where('country', 'IS')->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `country` = ?', $sql);
        $this->assertSame(['IS'], $params);
    }

    public function testATableWildcardIsNotQuoted(): void
    {
        [$sql] = $this->table('users')->select(['users.*', 'orders.total'])->join('orders', 'users.id', '=', 'orders.user_id')->toSql();

        $this->assertSame('SELECT `users`.*, `orders`.`total` FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $sql);
    }

    /**
     * IS with a value that may be null is MariaDB's null-safe comparison.
     */
    public function testIsWithANullValueIsTheNullSafeComparison(): void
    {
        [$sql, $params] = $this->table('users')->where('deleted_at', 'IS', null)->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` <=> ?', $sql);
        $this->assertSame([null], $params);
    }

    public function testLikeInAJoinConditionHasItsEscapeClauseBeforeTheWhere(): void
    {
        [$sql, $params] = $this->table('files')->select('files.path')->join('rules', 'files.path', 'LIKE', 'rules.pattern')->where('files.id', '>', 0)->toSql();

        $this->assertSame('SELECT `files`.`path` FROM `files` INNER JOIN `rules` ON `files`.`path` LIKE `rules`.`pattern` ESCAPE ? WHERE `files`.`id` > ?', $sql);
        $this->assertSame(['\\', 0], $params);
    }

    // ---- orderBy() (tests/Contract/Query/OrderByExpressionTest) ----------------------------------

    public function testAnOrderByExpressionIsWrittenAsItIs(): void
    {
        $order = Database::raw("CASE status WHEN 'open' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END");

        $this->assertSame(
            "SELECT * FROM `tasks` ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END ASC, `id` DESC",
            $this->table('tasks')->orderBy($order)->orderBy('id', 'DESC')->toSql()[0]
        );
    }

    /**
     * A string is a column name and nothing else: what a request sends cannot become SQL.
     */
    public function testAnOrderByStringIsOneQuotedColumnName(): void
    {
        [$sql] = $this->table('tasks')->orderBy('status DESC, (SELECT 1)')->toSql();

        $this->assertSame('SELECT * FROM `tasks` ORDER BY `status DESC, (SELECT 1)` ASC', $sql);
    }

    // ---- whereIn() (tests/Contract/Query/QueryBuilderTest) ---------------------------------------

    public function testWhereInWithAnEmptyListIsAFalseCondition(): void
    {
        [$sql, $params] = $this->table('users')->where('id', '>', 1)->whereIn('name', [])->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `id` > ? AND (1 = 0)', $sql);
        $this->assertSame([1], $params);
    }

    // ---- raw values (tests/Contract/Query/RawValueTest) ------------------------------------------

    public function testARawValueInWhereIsInlinedAndTheRestBound(): void
    {
        [$sql, $params] = $this->table('counters')
            ->where('hits', '>', Database::raw('1 + 1'))
            ->where('name', 'b')
            ->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `hits` > 1 + 1 AND `name` = ?', $sql);
        $this->assertSame(['b'], $params);
    }

    public function testRawValuesInWhereInAndWhereBetweenAreInlined(): void
    {
        [$sql, $params] = $this->table('counters')
            ->whereIn('hits', [1, Database::raw('2 + 3'), 9])
            ->whereBetween('hits', [Database::raw('0'), 7])
            ->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `hits` IN (?, 2 + 3, ?) AND `hits` BETWEEN 0 AND ?', $sql);
        $this->assertSame([1, 9, 7], $params);
    }

    public function testMixedRawAndBoundValuesKeepTheOrderOfTheSql(): void
    {
        [$sql, $params] = $this->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->where('hits', '>', Database::raw('0'))
            ->where('name', 'b')
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>=', 5)
            ->toSql();

        $this->assertSame('SELECT `name`, SUM(hits) AS total FROM `counters` WHERE `hits` > 0 AND `name` = ? GROUP BY `name` HAVING SUM(hits) >= ?', $sql);
        $this->assertSame(['b', 5], $params);
    }

    public function testLikeKeepsItsEscapeClauseWithARawPattern(): void
    {
        [$sql, $params] = $this->table('counters')->whereLike('name', '100%')->toSql();
        $this->assertSame('SELECT * FROM `counters` WHERE `name` LIKE ? ESCAPE ?', $sql);
        $this->assertSame(['100%', '\\'], $params);

        [$sql, $params] = $this->table('counters')->where('name', 'LIKE', Database::raw("CONCAT('a', '%')"))->toSql();
        $this->assertSame('SELECT * FROM `counters` WHERE `name` LIKE CONCAT(\'a\', \'%\') ESCAPE ?', $sql);
        $this->assertSame(['\\'], $params);
    }

    /**
     * Consumers rely on raw() in select() lists staying byte-identical.
     */
    public function testRawInSelectIsUnchanged(): void
    {
        [$sql, $params] = $this->table('counters')
            ->select([Database::raw('counters.*'), Database::raw('COUNT(*) AS n')])
            ->toSql();

        $this->assertSame('SELECT counters.*, COUNT(*) AS n FROM `counters`', $sql);
        $this->assertSame([], $params);
    }

    public function testARawValuesBindingsInWhereStandWhereItStands(): void
    {
        [$sql, $params] = $this->table('counters')
            ->where('name', '!=', 'zzz')
            ->where('hits', '>', Database::raw('? + ?', [1, 1]))
            ->whereIn('hits', [1, Database::raw('? + 3', [2]), 9])
            ->whereBetween('hits', [Database::raw('?', [0]), Database::raw('? * 2', [4])])
            ->where('name', 'b')
            ->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `name` != ? AND `hits` > ? + ? AND `hits` IN (?, ? + 3, ?) AND `hits` BETWEEN ? AND ? * 2 AND `name` = ?', $sql);
        $this->assertSame(['zzz', 1, 1, 1, 2, 9, 0, 4, 'b'], $params);
    }

    public function testARawLikePatternWithBindingsComesBeforeTheEscapeCharacter(): void
    {
        [$sql, $params] = $this->table('counters')->where('name', 'LIKE', Database::raw('CONCAT(?, ?)', ['a', '%']))->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `name` LIKE CONCAT(?, ?) ESCAPE ?', $sql);
        $this->assertSame(['a', '%', '\\'], $params);
    }

    public function testARawValuesBindingsInHavingComeAfterTheWhereValues(): void
    {
        [$sql, $params] = $this->table('counters')
            ->select(['name', Database::raw('SUM(hits) AS total')])
            ->where('hits', '>', Database::raw('?', [0]))
            ->groupBy('name')
            ->having(Database::raw('SUM(hits)'), '>', Database::raw('? + ?', [2, 2]))
            ->toSql();

        $this->assertSame('SELECT `name`, SUM(hits) AS total FROM `counters` WHERE `hits` > ? GROUP BY `name` HAVING SUM(hits) > ? + ?', $sql);
        $this->assertSame([0, 2, 2], $params);
    }

    /**
     * A value is rendered through __toString(): a subclass that overrides it keeps its say.
     */
    public function testASubclassThatOverridesToStringIsRenderedThroughIt(): void
    {
        $shouting = new class ('hits + ?', [41]) extends RawExpression {
            public function __toString(): string
            {
                return '(' . $this->value . ')';
            }
        };

        [$sql, $params] = $this->table('counters')->where('hits', $shouting)->toSql();

        $this->assertSame('SELECT * FROM `counters` WHERE `hits` = (hits + ?)', $sql);
        $this->assertSame([41], $params);
    }

    // ---- names with SQL in them (tests/Contract/Feature/SecurityTest) ----------------------------

    /**
     * A name is one quoted identifier, whatever it contains: the quote character around it (and
     * doubled inside, see tests/Driver/MariaDb/SecurityTest).
     */
    public function testAColumnNameWithSqlInItIsOneQuotedIdentifier(): void
    {
        [$sql, $params] = $this->table('users')->where('"; DROP TABLE users; --', 'test')->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `"; DROP TABLE users; --` = ?', $sql);
        $this->assertSame(['test'], $params);
    }

    public function testATableNameWithSqlInItIsOneQuotedIdentifier(): void
    {
        $this->assertSame('SELECT * FROM `users"; DROP TABLE secrets; --`', $this->table('users"; DROP TABLE secrets; --')->toSql()[0]);
    }

    public function testAnOrderByColumnWithSqlInItIsOneQuotedIdentifier(): void
    {
        [$sql] = $this->table('users')->orderBy('name; DROP TABLE users; --')->toSql();

        $this->assertSame('SELECT * FROM `users` ORDER BY `name; DROP TABLE users; --` ASC', $sql);
    }
}
