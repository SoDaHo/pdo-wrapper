<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;

/**
 * Security tests for RawExpression behavior.
 * Verifies that the new explicit raw() approach is secure by default.
 *
 * Only the SQL the builder renders is checked, so no database is needed: the builder is the one
 * the MariaDB driver creates (backticks, MySQL dialect), on a database it never reaches.
 */
class RawExpressionSecurityTest extends TestCase
{
    /** What MariaDbDriver::table('users') builds */
    private function users(): QueryBuilder
    {
        return new QueryBuilder($this->createStub(DatabaseInterface::class), 'users');
    }

    /**
     * TEST 1: SQL Injection in Values (must still be blocked)
     */
    public function testPreventsSqlInjectionInValues(): void
    {
        $maliciousInput = "' OR '1'='1";
        [$sql, $params] = $this->users()->where('username', $maliciousInput)->toSql();

        $this->assertStringContainsString('?', $sql);
        $this->assertContains($maliciousInput, $params);
    }

    /**
     * TEST 2: Identifier Injection (Tables/Columns)
     */
    public function testPreventsSqlInjectionInIdentifiers(): void
    {
        // Attempt to break out of column name quoting
        $maliciousColumn = 'username" --';

        [$sql, ] = $this->users()->select($maliciousColumn)->toSql();

        // Expectation: The attack string is completely quoted as identifier
        // Result: SELECT `username" --` FROM ... (MariaDB quotes with backticks)
        $this->assertStringContainsString('`username" --`', $sql);
    }

    /**
     * TEST 3: The new Raw logic (Most important test for this change!)
     */
    public function testRawExpressionBehavior(): void
    {
        // CASE A: String with parentheses WITHOUT Raw wrapper
        // Before: Was not quoted (insecure/magic)
        // Now: MUST be quoted (secure)
        [$sqlSafe, ] = $this->users()->select('COUNT(*)')->toSql();

        // MariaDB quote: `COUNT(*)`
        $this->assertStringContainsString('`COUNT(*)`', $sqlSafe);
        $this->assertStringNotContainsString('SELECT COUNT(*) ', $sqlSafe); // Must NOT be raw


        // CASE B: String WITH Raw wrapper
        // Expectation: Passed through 1:1
        [$sqlRaw, ] = $this->users()
            ->select([Database::raw('COUNT(*) as total')])
            ->toSql();

        $this->assertStringContainsString('COUNT(*) as total', $sqlRaw);
    }

    /**
     * TEST 4: Warning for developers - Raw Injection
     * This test confirms that Database::raw() is really RAW (Use with caution!)
     */
    public function testDeveloperMustNotPassUserInputToRaw(): void
    {
        $maliciousInput = '0; DROP TABLE users; --';

        // If a developer is foolish enough to put user input in raw():
        [$sql, ] = $this->users()
            ->select([Database::raw($maliciousInput)])
            ->toSql();

        // ... then exactly what was ordered happens (SQL Injection)
        $this->assertStringContainsString($maliciousInput, $sql);
    }

    /**
     * TEST 5: Having Clause with Raw
     */
    public function testHavingSupportsRaw(): void
    {
        [$sql, ] = $this->users()
            ->groupBy('role')
            ->having(Database::raw('COUNT(*)'), '>', 5)
            ->toSql();

        $this->assertStringContainsString('HAVING COUNT(*) > ?', $sql);
    }

    /**
     * TEST 6: RawExpression is a simple value object
     */
    public function testRawExpressionValueObject(): void
    {
        $raw = new RawExpression('COUNT(*)');

        $this->assertSame('COUNT(*)', $raw->value);
        $this->assertSame('COUNT(*)', (string) $raw);
    }

    /**
     * TEST 7: Database::raw() factory creates RawExpression
     */
    public function testDatabaseRawFactory(): void
    {
        $raw = Database::raw('SUM(amount)');

        $this->assertInstanceOf(RawExpression::class, $raw);
        $this->assertSame('SUM(amount)', $raw->value);
    }
}
