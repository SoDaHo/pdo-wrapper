<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * A SQLite specific that let a test pass on SQLite and fail in production: an unknown name in
 * double quotes is read as a string literal. Identifiers are quoted with backticks instead.
 */
class SqliteHardeningTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, country TEXT, score INTEGER)');
        foreach ([['DE', 10], ['DE', 20], ['AT', 30]] as [$country, $score]) {
            $this->db->insert('users', ['country' => $country, 'score' => $score]);
        }
    }

    public function testIdentifiersAreQuotedWithBackticks(): void
    {
        [$sql] = $this->db->table('users')->select(['id', 'users.country as c'])->where('score', '>', 1)->orderBy('id')->toSql();

        $this->assertSame('SELECT `id`, `users`.`country` as c FROM `users` WHERE `score` > ? ORDER BY `id` ASC', $sql);
    }

    public function testDriverStatementsUseBackticksAndEscapeThem(): void
    {
        $this->db->execute('CREATE TABLE `we``ird` (`a``b` INTEGER)');
        $this->db->insert('we`ird', ['a`b' => 7]);
        $this->db->update('we`ird', ['a`b' => 8], ['a`b' => 7]);

        $this->assertSame(1, $this->db->table('we`ird')->where('a`b', 8)->count());
        $this->assertSame(1, $this->db->delete('we`ird', ['a`b' => 8]));
    }

    /**
     * Regression test: WHERE "contry" = 'DE' compared the string 'contry' with 'DE' (no rows, no error).
     */
    public function testUnknownColumnsFailInsteadOfBecomingStringLiterals(): void
    {
        $cases = [
            'where' => fn () => $this->db->table('users')->where('contry', 'DE')->get(),
            'select' => fn () => $this->db->table('users')->select(['contry'])->get(),
            'orderBy' => fn () => $this->db->table('users')->orderBy('contry')->get(),
            'groupBy' => fn () => $this->db->table('users')->select([Database::raw('COUNT(*) AS n')])->groupBy('contry')->get(),
            'builder update' => fn () => $this->db->table('users')->where('contry', 'DE')->update(['score' => 0]),
            'driver update' => fn () => $this->db->update('users', ['score' => 0], ['contry' => 'DE']),
            'driver delete' => fn () => $this->db->delete('users', ['contry' => 'DE']),
        ];

        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected a QueryException for the unknown column");
            } catch (QueryException $e) {
                $this->assertStringContainsString('no such column: contry', $e->getDebugMessage() ?? '', $name);
            }
        }

        $this->assertSame([10, 20, 30], array_column($this->db->table('users')->orderBy('id')->get(), 'score'), 'nothing was changed');
    }

}
