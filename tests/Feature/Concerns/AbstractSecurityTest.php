<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Feature\Concerns;

use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\DatabaseInterface;

/**
 * Abstract base class for security tests.
 * Runs identical tests against all database drivers.
 */
abstract class AbstractSecurityTest extends TestCase
{
    protected DatabaseInterface $db;

    abstract protected function createDatabase(): DatabaseInterface;
    abstract protected function getCreateUsersTableSql(): string;
    abstract protected function getCreateSecretsTableSql(): string;

    protected function setUp(): void
    {
        $this->db = $this->createDatabase();
        $this->createSchema();
        $this->seedData();
    }

    protected function createSchema(): void
    {
        $this->db->execute($this->getCreateUsersTableSql());
        $this->db->execute($this->getCreateSecretsTableSql());
    }

    protected function seedData(): void
    {
        $this->db->insert('users', ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin']);
        $this->db->insert('users', ['name' => 'User', 'email' => 'user@example.com', 'role' => 'user']);
        $this->db->insert('secrets', ['secret_data' => 'TOP SECRET DATA']);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS secrets');
        $this->db->execute('DROP TABLE IF EXISTS users');
    }

    // =========================================================================
    // SQL INJECTION VIA PARAMETERS (Prepared Statements)
    // =========================================================================

    public function testSqlInjectionInWhereValue(): void
    {
        // Classic SQL injection attempt
        $maliciousInput = "'; DROP TABLE users; --";

        $result = $this->db->table('users')
            ->where('name', $maliciousInput)
            ->get();

        // Should return empty result, NOT drop the table
        $this->assertEmpty($result);

        // Table should still exist with all data
        $users = $this->db->table('users')->get();
        $this->assertCount(2, $users);
    }

    public function testSqlInjectionInWhereValueWithOr(): void
    {
        // Attempt to bypass authentication
        $maliciousInput = "' OR '1'='1";

        $result = $this->db->table('users')
            ->where('name', $maliciousInput)
            ->get();

        // Should NOT return all users
        $this->assertEmpty($result);
    }

    public function testSqlInjectionInInsertValues(): void
    {
        $maliciousName = "Evil'); DROP TABLE secrets; --";
        $maliciousEmail = "evil@example.com', 'admin'); --";

        $this->db->insert('users', [
            'name' => $maliciousName,
            'email' => $maliciousEmail,
        ]);

        // Secrets table should still exist
        $secrets = $this->db->table('secrets')->get();
        $this->assertCount(1, $secrets);

        // User should be inserted with the malicious string as literal value
        $user = $this->db->table('users')->where('name', $maliciousName)->first();
        $this->assertNotNull($user);
        $this->assertSame($maliciousName, $user['name']);
    }

    public function testSqlInjectionInUpdateValues(): void
    {
        $maliciousRole = "admin'; UPDATE users SET role='admin' WHERE '1'='1";

        $this->db->table('users')
            ->where('name', 'User')
            ->update(['role' => $maliciousRole]);

        // Only the target user should be affected
        $admin = $this->db->table('users')->where('name', 'Admin')->first();
        $this->assertSame('admin', $admin['role']); // Still admin

        $user = $this->db->table('users')->where('name', 'User')->first();
        $this->assertSame($maliciousRole, $user['role']); // Literal string, not executed
    }

    public function testSqlInjectionInWhereIn(): void
    {
        $maliciousValues = ['999', '2) OR 1=1; --'];

        try {
            $result = $this->db->table('users')
                ->whereIn('id', $maliciousValues)
                ->get();

            // MySQL casts strings to int (returns 0 or the numeric prefix)
            // Should NOT return all users
            $this->assertLessThan(2, count($result));
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // PostgreSQL throws error on invalid integer - this is GOOD security behavior
            // Check the original PDO exception message
            $originalMessage = $e->getPrevious()?->getMessage() ?? '';
            $this->assertStringContainsString('invalid input syntax', $originalMessage);
        }
    }

    public function testSqlInjectionInWhereBetween(): void
    {
        $maliciousStart = '1; DROP TABLE secrets; --';
        $maliciousEnd = '100';

        try {
            $result = $this->db->table('users')
                ->whereBetween('id', [$maliciousStart, $maliciousEnd])
                ->get();
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // PostgreSQL throws error on invalid integer - this is GOOD security behavior
            $originalMessage = $e->getPrevious()?->getMessage() ?? '';
            $this->assertStringContainsString('invalid input syntax', $originalMessage);
        }

        // Secrets table should still exist regardless of how the DB handled it
        $secrets = $this->db->table('secrets')->get();
        $this->assertCount(1, $secrets);
    }

    public function testSqlInjectionInWhereLike(): void
    {
        $maliciousPattern = "%'; DROP TABLE users; --";

        $result = $this->db->table('users')
            ->whereLike('name', $maliciousPattern)
            ->get();

        // Should return empty (no names match this pattern)
        $this->assertEmpty($result);

        // Table should still exist
        $users = $this->db->table('users')->get();
        $this->assertCount(2, $users);
    }

    public function testSqlInjectionInDirectQuery(): void
    {
        $maliciousId = '1 OR 1=1';

        try {
            // Using parameterized query
            $result = $this->db->query(
                'SELECT * FROM users WHERE id = ?',
                [$maliciousId]
            )->fetchAll(\PDO::FETCH_ASSOC);

            // MySQL casts "1 OR 1=1" to int 1, may return 1 row (id=1)
            // Should NOT return all users
            $this->assertLessThan(2, count($result));
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // PostgreSQL throws error on invalid integer - this is GOOD security behavior
            $originalMessage = $e->getPrevious()?->getMessage() ?? '';
            $this->assertStringContainsString('invalid input syntax', $originalMessage);
        }
    }

    public function testSqlInjectionInDirectQueryWithNamedParams(): void
    {
        $maliciousName = "Admin' OR '1'='1";

        $result = $this->db->query(
            'SELECT * FROM users WHERE name = :name',
            ['name' => $maliciousName]
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Should NOT return all users
        $this->assertEmpty($result);
    }

    // =========================================================================
    // LIMIT / OFFSET INTEGER CASTING
    // =========================================================================

    public function testLimitIntegerCasting(): void
    {
        $result = $this->db->table('users')
            ->limit(1)
            ->get();

        $this->assertCount(1, $result);
    }

    public function testOffsetIntegerCasting(): void
    {
        $result = $this->db->table('users')
            ->limit(10)
            ->offset(1)
            ->get();

        $this->assertCount(1, $result); // Only 1 user left after offset
    }

    // =========================================================================
    // UNION / SUBQUERY INJECTION ATTEMPTS
    // =========================================================================

    public function testUnionInjectionInWhereValue(): void
    {
        $maliciousInput = "' UNION SELECT secret_data, secret_data, secret_data, secret_data FROM secrets --";

        $result = $this->db->table('users')
            ->where('name', $maliciousInput)
            ->get();

        // Should return empty, NOT data from secrets table
        $this->assertEmpty($result);
    }

    // =========================================================================
    // SPECIAL CHARACTERS HANDLING
    // =========================================================================

    public function testSpecialCharactersInValues(): void
    {
        $specialNames = [
            "O'Brien",
            'Quote "Test"',
            'Back\\slash',
            "New\nLine",
            "Tab\tHere",
            "<script>alert('xss')</script>",
            'emoji 🎉 test',
        ];

        foreach ($specialNames as $name) {
            $this->db->insert('users', ['name' => $name, 'email' => 'test@example.com']);

            $user = $this->db->table('users')->where('name', $name)->first();
            $this->assertNotNull($user, 'Failed to find user with name: ' . addslashes($name));
            $this->assertSame($name, $user['name']);
        }
    }

    public function testEmptyStringValue(): void
    {
        $this->db->insert('users', ['name' => '', 'email' => 'empty@example.com']);

        $user = $this->db->table('users')->where('email', 'empty@example.com')->first();
        $this->assertSame('', $user['name']);
    }

    public function testNullValueHandling(): void
    {
        $this->db->insert('users', [
            'name' => 'NullTest',
            'email' => 'null@example.com',
            'role' => null,
        ]);

        $user = $this->db->table('users')->where('name', 'NullTest')->first();
        $this->assertNull($user['role']);
    }

    // =========================================================================
    // SQL INJECTION VIA IDENTIFIERS (Column/Table Names)
    // =========================================================================

    public function testSqlInjectionInColumnName(): void
    {
        $maliciousColumn = '"; DROP TABLE users; --';

        try {
            $this->db->table('users')
                ->where($maliciousColumn, 'test')
                ->get();

            // SQLite may not throw for non-existent quoted column
            // But injection should still be prevented
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // MySQL/PostgreSQL throw "column not found" - this is GOOD security behavior
            // The malicious string was safely quoted, not executed
        }

        // Critical: Table should still exist with all data
        $users = $this->db->table('users')->get();
        $this->assertCount(2, $users);
    }

    public function testSqlInjectionInTableName(): void
    {
        $maliciousTable = 'users"; DROP TABLE secrets; --';

        try {
            $result = $this->db->table($maliciousTable)->get();
            // Should fail (table doesn't exist with that weird name)
            $this->fail('Expected exception for non-existent table');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // Expected - table doesn't exist
        }

        // Secrets table should still exist
        $secrets = $this->db->table('secrets')->get();
        $this->assertCount(1, $secrets);
    }

    public function testSqlInjectionInOperator(): void
    {
        $maliciousOperator = '; DROP TABLE users; --';

        $this->expectException(\Sodaho\PdoWrapper\Exception\QueryException::class);

        // Should throw exception due to invalid operator (whitelist validation)
        $this->db->table('users')
            ->where('id', $maliciousOperator, 1)
            ->get();
    }

    public function testSqlInjectionInJoinOperator(): void
    {
        $maliciousOperator = '; DROP TABLE secrets; --';

        $this->expectException(\Sodaho\PdoWrapper\Exception\QueryException::class);

        // Should throw exception due to invalid operator
        $this->db->table('users')
            ->join('secrets', 'users.id', $maliciousOperator, 'secrets.id')
            ->get();
    }

    public function testSqlInjectionInOrderByColumn(): void
    {
        $maliciousColumn = 'name; DROP TABLE users; --';

        try {
            $this->db->table('users')
                ->orderBy($maliciousColumn)
                ->get();

            // SQLite may not throw for non-existent quoted column
            // But injection should still be prevented
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // MySQL/PostgreSQL throw "column not found" - this is GOOD security behavior
            // The malicious string was safely quoted, not executed
        }

        // Critical: Table should still exist with all data
        $users = $this->db->table('users')->get();
        $this->assertCount(2, $users);
    }

    /**
     * Test that Database::raw() allows aggregate functions.
     * Raw expressions bypass identifier quoting intentionally.
     */
    public function testRawExpressionAllowsAggregates(): void
    {
        $result = $this->db->table('users')
            ->select([\Sodaho\PdoWrapper\Database::raw('COUNT(*) as total')])
            ->get();

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('total', $result[0]);
    }

    /**
     * Test that parentheses in regular strings are now safely quoted.
     * This prevents SQL injection via subqueries.
     */
    public function testParenthesesInStringsAreQuoted(): void
    {
        $maliciousInput = '(SELECT secret_data FROM secrets)';

        try {
            $result = $this->db->table('users')
                ->select(['name', $maliciousInput])
                ->get();

            // If we get here, the column was quoted and treated as literal
            // No secret data should be leaked
            if (!empty($result)) {
                foreach ($result as $row) {
                    foreach ($row as $value) {
                        $this->assertStringNotContainsString(
                            'TOP SECRET DATA',
                            (string) $value,
                            'Secret data should not be leaked'
                        );
                    }
                }
            }
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // Column not found error is expected - the subquery was quoted
            // This is the secure behavior
            $this->assertTrue(true);
        }
    }

    // =========================================================================
    // EDGE CASES & VALIDATION
    // =========================================================================

    public function testWhereWithOneArgumentThrowsException(): void
    {
        $this->expectException(\Sodaho\PdoWrapper\Exception\QueryException::class);

        // Should throw because where() requires at least 2 arguments
        $this->db->table('users')->where('id')->get();
    }

    public function testValidOperatorsWork(): void
    {
        // Test comparison operators on integer column
        $comparisonOperators = ['=', '!=', '<>', '<', '>', '<=', '>='];

        foreach ($comparisonOperators as $operator) {
            // Should not throw
            $result = $this->db->table('users')
                ->where('id', $operator, 1)
                ->get();

            $this->assertIsArray($result);
        }

        // Test LIKE operators on string column (PostgreSQL doesn't support LIKE on integers)
        $likeOperators = ['LIKE', 'NOT LIKE'];

        foreach ($likeOperators as $operator) {
            $result = $this->db->table('users')
                ->where('name', $operator, '%Admin%')
                ->get();

            $this->assertIsArray($result);
        }
    }

    public function testInvalidOperatorThrowsException(): void
    {
        $invalidOperators = ['INVALID', 'DROP', '--', '/*', 'OR', 'AND', 'UNION'];

        foreach ($invalidOperators as $operator) {
            try {
                $this->db->table('users')
                    ->where('id', $operator, 1)
                    ->get();

                $this->fail("Expected exception for invalid operator: {$operator}");
            } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
                $this->assertStringContainsString('Invalid operator', $e->getDebugMessage());
            }
        }
    }

    // =========================================================================
    // MASS UPDATE/DELETE PROTECTION
    // =========================================================================

    public function testUpdateWithoutWhereThrowsException(): void
    {
        try {
            $this->db->table('users')->update(['role' => 'admin']);
            $this->fail('Update without WHERE should throw exception');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertStringContainsString('safety check', $e->getDebugMessage());
        }

        // All users should still have original roles
        $admin = $this->db->table('users')->where('name', 'Admin')->first();
        $this->assertSame('admin', $admin['role']);
    }

    public function testDeleteWithoutWhereThrowsException(): void
    {
        try {
            $this->db->table('users')->delete();
            $this->fail('Delete without WHERE should throw exception');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertStringContainsString('safety check', $e->getDebugMessage());
        }

        // All users should still exist
        $users = $this->db->table('users')->get();
        $this->assertCount(2, $users);
    }

    public function testDirectUpdateWithEmptyWhereThrowsException(): void
    {
        try {
            $this->db->update('users', ['role' => 'admin'], []);
            $this->fail('Direct update with empty WHERE should throw exception');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertStringContainsString('safety check', $e->getDebugMessage());
        }
    }

    public function testDirectDeleteWithEmptyWhereThrowsException(): void
    {
        try {
            $this->db->delete('users', []);
            $this->fail('Direct delete with empty WHERE should throw exception');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertStringContainsString('safety check', $e->getDebugMessage());
        }
    }
}
