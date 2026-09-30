<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * SQLite specifics that let a test pass on SQLite and fail in production: an unknown name in
 * double quotes is read as a string literal, and a number bound as text sorts above every number,
 * so a numeric expression without column affinity (COUNT(*), price * 2) never equals or exceeds it.
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

    /**
     * Regression test: HAVING COUNT(*) > '1' (the integer bound as text) was always false.
     */
    public function testIntegersAreBoundAsIntegers(): void
    {
        $this->assertSame(['DE'], array_column($this->db->table('users')->select(['country'])->groupBy('country')->having(Database::raw('COUNT(*)'), '>', 1)->get(), 'country'));
        $this->assertSame(1, $this->db->table('users')->groupBy('country')->having(Database::raw('COUNT(*)'), '>', 1)->count());
        $this->assertSame(['DE'], $this->db->query('SELECT country FROM users GROUP BY country HAVING COUNT(*) > ?', [1])->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['DE'], $this->db->query('SELECT country FROM users GROUP BY country HAVING COUNT(*) > :n', [':n' => 1])->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['DE'], $this->db->query('SELECT country FROM users GROUP BY country HAVING COUNT(*) > :n', ['n' => 1])->fetchAll(PDO::FETCH_COLUMN), 'named parameter without the colon');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM users WHERE score * 2 > ?', [30])->fetchColumn());
    }

    /**
     * Regression test: false was bound as '' and stored as an empty string, true as '1'.
     */
    public function testBooleansAreBoundAsZeroAndOne(): void
    {
        $this->db->execute('ALTER TABLE users ADD COLUMN active INTEGER');
        $this->db->update('users', ['active' => false], ['country' => 'DE']);
        $this->db->update('users', ['active' => true], ['country' => 'AT']);

        $this->assertSame([['integer', 0], ['integer', 1]], $this->db->query('SELECT typeof(active), active FROM users WHERE country IN (?, ?) GROUP BY active ORDER BY active', ['DE', 'AT'])->fetchAll(PDO::FETCH_NUM));
        $this->assertSame(2, $this->db->table('users')->where('active', false)->count());
        $this->assertSame(1, $this->db->table('users')->where('active', true)->count());
    }

    public function testOtherValuesKeepTheDefaultBinding(): void
    {
        $this->db->insert('users', ['country' => null, 'score' => 5]);

        $this->assertSame(1, $this->db->table('users')->where('country', 'AT')->count());
        $this->assertSame(2, $this->db->table('users')->where('score', '>', 15.5)->count(), 'a float compared with an INTEGER column (affinity applies)');
        $this->assertSame(1, $this->db->table('users')->whereNull('country')->count(), 'null is bound as NULL');
        $this->assertSame('null', $this->db->query('SELECT typeof(country) FROM users WHERE score = ?', [5])->fetchColumn());
    }

    /**
     * The documented migration case: an integer written by 1.x into a column without affinity is
     * TEXT and is found by a string parameter, no longer by an integer one, until the README's
     * conversion ran. That statement converts only values whose integer round trip is lossless.
     */
    public function testIntegersStoredAsTextInUntypedColumnsNeedStringParametersOrTheMigration(): void
    {
        $this->db->execute('CREATE TABLE settings (name TEXT, value COLLATE RTRIM)'); // RTRIM: "=" would ignore trailing spaces
        $rows = [['retries', '5'], ['offset', '-3'], ['max', '9223372036854775807'], ['min', '-9223372036854775808'], ['code', '007'], ['plus', '+5'], ['padded', ' 5'], ['trailing', '5 '], ['negzero', '-0'], ['ratio', '1.5'], ['exp', '1e3'], ['label', 'abc'], ['empty', ''], ['big', '99999999999999999999']];
        foreach ($rows as [$name, $value]) {
            $this->db->execute('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
        }

        $this->assertSame(2, $this->db->table('settings')->where('value', '5')->count(), "a string parameter finds the old text ('5 ' too, under RTRIM)");
        $this->assertSame(0, $this->db->table('settings')->where('value', 5)->count(), 'TEXT and INTEGER are different storage classes without affinity');

        $this->db->execute("UPDATE settings SET value = CAST(value AS INTEGER) WHERE typeof(value) = 'text' AND CAST(CAST(value AS INTEGER) AS TEXT) = value COLLATE BINARY");

        $this->assertSame(1, $this->db->table('settings')->where('value', 5)->count());
        $this->assertSame(1, $this->db->table('settings')->where('value', -3)->count());
        $converted = ['retries', 'offset', 'max', 'min'];
        foreach ($rows as [$name, $value]) {
            [$type, $stored] = $this->db->query('SELECT typeof(value), value FROM settings WHERE name = ?', [$name])->fetch(PDO::FETCH_NUM);
            if (in_array($name, $converted, true)) {
                $this->assertSame('integer', $type, $name);
                $this->assertSame((int) $value, $stored, $name);
            } else {
                $this->assertSame('text', $type, "{$name}: not a lossless integer round trip");
                $this->assertSame($value, $stored, "{$name}: unchanged");
            }
        }
    }

    /**
     * The documented migration case for booleans: 1.x stored false as '' in every column type
     * (INTEGER affinity does not convert an empty string); a new false (0) does not match it.
     */
    public function testFalseStoredAsEmptyStringByEarlierVersionsNeedsTheMigration(): void
    {
        $this->db->execute('ALTER TABLE users ADD COLUMN active INTEGER COLLATE RTRIM'); // RTRIM: "=" would ignore trailing spaces
        $this->db->insert('users', ['country' => 'IS', 'score' => 40]);
        $this->db->execute("UPDATE users SET active = '' WHERE country = 'DE'"); // as 1.x wrote false
        $this->db->execute("UPDATE users SET active = '1' WHERE country = 'AT'"); // as 1.x wrote true
        $this->db->execute("UPDATE users SET active = '  ' WHERE country = 'IS'"); // not a former false

        $this->assertSame(['text', 'integer'], $this->db->query("SELECT typeof(active) FROM users WHERE country IN ('DE', 'AT') GROUP BY typeof(active) ORDER BY typeof(active) DESC")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(0, $this->db->table('users')->where('active', false)->count(), 'old false is not found by a new false');
        $this->assertSame(1, $this->db->table('users')->where('active', true)->count(), 'old true was converted to 1 by the INTEGER affinity');

        $this->db->execute("UPDATE users SET active = 0 WHERE typeof(active) = 'text' AND active = '' COLLATE BINARY");

        $this->assertSame(2, $this->db->table('users')->where('active', false)->count());
        $this->assertSame('  ', $this->db->query("SELECT active FROM users WHERE country = 'IS'")->fetchColumn(), 'blanks are not a former false');
    }

    public function testParameterCountMismatchesFailAsBefore(): void
    {
        try {
            $this->db->query('SELECT ? AS a, ? AS b', [1, 2, 3]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('column index out of range', $e->getDebugMessage() ?? '');
        }
    }
}
