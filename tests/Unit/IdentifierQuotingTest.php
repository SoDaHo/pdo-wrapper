<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use LogicException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Query\QueryBuilder;

/**
 * The SQL that names with special characters render to, byte for byte: in the query builder and in
 * the driver's CRUD methods - the two places that quote names. A backtick inside a name is doubled,
 * a dot separates parts, the builder knows "col as alias" and "table.*", the CRUD methods take every
 * key as one name. A change to the quoting shows here, without a database (the escaping against a
 * real server is in tests/Driver/MariaDb/SecurityTest).
 */
class IdentifierQuotingTest extends TestCase
{
    /** What a driver's table() builds, on a connection that is never opened */
    private function table(string $table): QueryBuilder
    {
        $db = new class () extends AbstractDriver {
        };

        return $db->table($table);
    }

    /**
     * The statement a CRUD method hands to query(), caught there before anything is sent.
     *
     * @param \Closure(AbstractDriver): mixed $call
     *
     * @return array{string, array<int|string, mixed>}
     */
    private function sent(\Closure $call): array
    {
        $db = new class () extends AbstractDriver {
            /** @var array{string, array<int|string, mixed>}|null */
            public ?array $sent = null;

            public function query(string $sql, array $params = []): PDOStatement
            {
                $this->sent = [$sql, $params];

                throw new LogicException('not sent');
            }
        };

        try {
            $call($db);
        } catch (LogicException) {
            // caught where the statement would have been sent
        }
        $this->assertNotNull($db->sent, 'the call reached query()');

        return $db->sent;
    }

    // ---- the query builder ----------------------------------------------------------------------

    public function testABacktickInAColumnNameIsDoubled(): void
    {
        $this->assertSame('SELECT `a``b` FROM `t`', $this->table('t')->select('a`b')->toSql()[0]);
    }

    public function testABacktickInATableNameIsDoubled(): void
    {
        $this->assertSame('SELECT * FROM `t``x`', $this->table('t`x')->toSql()[0]);
    }

    public function testADottedNameIsQuotedPartByPart(): void
    {
        $this->assertSame('SELECT `secrets`.`secret_data` FROM `t`', $this->table('t')->select('secrets.secret_data')->toSql()[0]);
        $this->assertSame('SELECT `s``x`.`c``y` FROM `t`', $this->table('t')->select('s`x.c`y')->toSql()[0]);
    }

    public function testAnAliasIsQuotedApart(): void
    {
        $this->assertSame('SELECT `name` as `x` FROM `t`', $this->table('t')->select('name as x')->toSql()[0]);
        $this->assertSame('SELECT `a``b` as `x` FROM `t`', $this->table('t')->select('a`b as x')->toSql()[0]);
        $this->assertSame('SELECT `t`.`name` as `x` FROM `t`', $this->table('t')->select('t.name as x')->toSql()[0]);
    }

    /**
     * The alias ends the name: a newline after it is no alias but part of one quoted name (the
     * server then names the column it does not know), never dropped. (A comma-separated string
     * passed to select() is trimmed entry by entry first; a list is taken as it is.)
     */
    public function testANewlineAfterAnAliasIsNoAlias(): void
    {
        $this->assertSame("SELECT `email as e\n` FROM `t`", $this->table('t')->select(["email as e\n"])->toSql()[0]);
    }

    public function testAnAliasMayBeAWordInAnyScript(): void
    {
        $this->assertSame('SELECT `x` as `ä` FROM `t`', $this->table('t')->select('x as ä')->toSql()[0]);
        $this->assertSame('SELECT `x` as `größe_1` FROM `t`', $this->table('t')->select('x as größe_1')->toSql()[0]);
    }

    /**
     * A condition declares no alias: "has as col" is the name of one column, in every where*().
     */
    public function testAWhereColumnIsNeverSplitIntoAnAlias(): void
    {
        [$sql, $params] = $this->table('t')->where('has as col', 1)->whereIn('a as b', [2])->whereNull('c as d')->toSql();

        $this->assertSame('SELECT * FROM `t` WHERE `has as col` = ? AND `a as b` IN (?) AND `c as d` IS NULL', $sql);
        $this->assertSame([1, 2], $params);
    }

    public function testATableWildcardKeepsItsStar(): void
    {
        $this->assertSame('SELECT `users`.* FROM `users`', $this->table('users')->select('users.*')->toSql()[0]);
    }

    public function testABacktickInAWhereColumnIsDoubled(): void
    {
        [$sql, $params] = $this->table('t')->where('a`b', 1)->toSql();

        $this->assertSame('SELECT * FROM `t` WHERE `a``b` = ?', $sql);
        $this->assertSame([1], $params);
    }

    public function testABacktickInAnOrderByColumnIsDoubled(): void
    {
        $this->assertSame('SELECT * FROM `t` ORDER BY `a``b` ASC', $this->table('t')->orderBy('a`b')->toSql()[0]);
    }

    public function testABacktickInAnUpdateColumnIsDoubled(): void
    {
        [$sql] = $this->sent(static fn (AbstractDriver $db): int => $db->table('t`x')->where('id', 1)->update(['a`b' => 2]));

        $this->assertSame('UPDATE `t``x` SET `a``b` = ? WHERE `id` = ?', $sql);
    }

    // ---- the CRUD methods of the driver ---------------------------------------------------------

    public function testABacktickInAnInsertNameIsDoubled(): void
    {
        [$sql, $params] = $this->sent(static fn (AbstractDriver $db): int => $db->insert('t`x', ['a`b' => 1]));

        $this->assertSame('INSERT INTO `t``x` (`a``b`) VALUES (?)', $sql);
        $this->assertSame([1], $params);
    }

    public function testABacktickInAnUpdateNameIsDoubled(): void
    {
        [$sql, $params] = $this->sent(static fn (AbstractDriver $db): int => $db->update('t`x', ['a`b' => 1], ['c`d' => 2]));

        $this->assertSame('UPDATE `t``x` SET `a``b` = ? WHERE `c``d` = ?', $sql);
        $this->assertSame([1, 2], $params);
    }

    public function testABacktickInADeleteNameIsDoubled(): void
    {
        [$sql] = $this->sent(static fn (AbstractDriver $db): int => $db->delete('t`x', ['c`d' => 2]));

        $this->assertSame('DELETE FROM `t``x` WHERE `c``d` = ?', $sql);
    }

    public function testADottedCrudTableIsQuotedPartByPart(): void
    {
        [$sql] = $this->sent(static fn (AbstractDriver $db): ?array => $db->findOne('secrets.secret_data', ['s`x' => 1]));

        $this->assertSame('SELECT * FROM `secrets`.`secret_data` WHERE `s``x` = ? LIMIT 1', $sql);
    }

    /**
     * A CRUD key is one name, never an alias or a wildcard: "a as b" is the column of that name,
     * "*" the column named "*". In the builder's update() as well.
     */
    public function testACrudKeyIsNeverAnAliasOrAWildcard(): void
    {
        [$sql] = $this->sent(static fn (AbstractDriver $db): int => $db->update('t', ['a as b' => 1], ['*' => 2]));
        $this->assertSame('UPDATE `t` SET `a as b` = ? WHERE `*` = ?', $sql);

        [$sql] = $this->sent(static fn (AbstractDriver $db): int => $db->table('t')->where('id', 1)->update(['a as b' => 1]));
        $this->assertSame('UPDATE `t` SET `a as b` = ? WHERE `id` = ?', $sql);
    }
}
