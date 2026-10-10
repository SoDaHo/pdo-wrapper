<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Feature;

use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * Abstract base class for security tests.
 * Runs identical tests against all database drivers.
 */
class SecurityTest extends ContractTestCase
{
    use SecuritySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSecuritySchema();
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
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin['role']); // Still admin

        $user = $this->db->table('users')->where('name', 'User')->first();
        $this->assertNotNull($user);
        $this->assertSame($maliciousRole, $user['role']); // Literal string, not executed
    }

    public function testSqlInjectionInWhereIn(): void
    {
        $maliciousValues = ['999', '2) OR 1=1; --'];

        try {
            $result = $this->db->table('users')
                ->whereIn('id', $maliciousValues)
                ->get();

            // A database may cast the strings to integers (0 or the numeric prefix)
            // Should NOT return all users
            $this->assertLessThan(2, count($result));
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // or refuse a string that is no integer - this is GOOD security behavior
            $this->assertInvalidInteger($e);
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
            // A database may refuse a string that is no integer - this is GOOD security behavior
            $this->assertInvalidInteger($e);
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

    /**
     * escapeLike() makes %, _ and \ literal on every engine: the builder binds the escape
     * character (`LIKE ? ESCAPE ?`), so no SQL mode or engine default decides what it means.
     */
    public function testEscapeLikeMatchesWildcardsLiterally(): void
    {
        $this->seedLikeNames();

        $this->assertLikeFinds(['100% sure'], '100%');
        $this->assertLikeFinds(['under_score'], 'under_');
        $this->assertLikeFinds(['back\\slash'], 'back\\');
    }

    public function testEscapeLikeHoldsInNotLikeAndInHaving(): void
    {
        $this->seedLikeNames();

        $pattern = Database::escapeLike('100%') . '%';

        $others = $this->db->table('users')->whereNotLike('name', $pattern)->orderBy('id')->get();
        $this->assertSame(
            ['Admin', 'User', '100 percent', 'under_score', 'underXscore', 'back\\slash'],
            array_column($others, 'name')
        );

        [, $params] = $this->db->table('users')->select('name')->groupBy('name')->having('name', 'LIKE', $pattern)->toSql();
        $this->assertSame([$pattern, '\\'], $params, 'the escape character is bound after the pattern');
        $grouped = $this->db->table('users')->select('name')->groupBy('name')->having('name', 'LIKE', $pattern)->get();
        $this->assertSame(['100% sure'], array_column($grouped, 'name'));

        $grouped = $this->db->table('users')->select('name')->groupBy('name')->having('name', 'NOT LIKE', $pattern)->get();
        $this->assertCount(6, $grouped);
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

            // A database may cast "1 OR 1=1" to the integer 1 and return 1 row (id=1)
            // Should NOT return all users
            $this->assertLessThan(2, count($result));
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // or refuse a string that is no integer - this is GOOD security behavior
            $this->assertInvalidInteger($e);
        }
    }

    /**
     * The refusal of a string that is no integer: a failed query whose SQLSTATE is of class 22,
     * data exception (standard SQL, unlike the wording of the message).
     */
    private function assertInvalidInteger(QueryException $e): void
    {
        $this->assertSame('Query failed', $e->getMessage());
        $this->assertStringStartsWith('22', (string) $e->sqlState, 'a data exception');
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
        $this->assertNotNull($user);
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
        $this->assertNotNull($user);
        $this->assertNull($user['role']);
    }

    // =========================================================================
    // SQL INJECTION VIA IDENTIFIERS (Column/Table Names)
    // =========================================================================

    /**
     * The name is one quoted identifier (its SQL: tests/Unit/ContractQueryRenderingTest): no
     * column of that name, and nothing of it is run.
     */
    public function testSqlInjectionInColumnName(): void
    {
        $maliciousColumn = '"; DROP TABLE users; --';
        $query = $this->db->table('users')->where($maliciousColumn, 'test');

        try {
            // A database may read a quoted name that is no column as a string: then nothing matches
            $this->assertSame([], $query->get());
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // or refuse it: no such column
            $this->assertSame('Query failed', $e->getMessage());
        }

        // Critical: Table should still exist with all data
        $users = $this->db->table('users')->get();
        $this->assertCount(2, $users);
    }

    /**
     * The name is one quoted identifier (its SQL: tests/Unit/ContractQueryRenderingTest).
     */
    public function testSqlInjectionInTableName(): void
    {
        $maliciousTable = 'users"; DROP TABLE secrets; --';

        try {
            $this->db->table($maliciousTable)->get();
            $this->fail('Expected exception for non-existent table');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertSame('Query failed', $e->getMessage(), 'no table of that odd name');
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

    /**
     * The name is one quoted identifier (its SQL: tests/Unit/ContractQueryRenderingTest).
     */
    public function testSqlInjectionInOrderByColumn(): void
    {
        $maliciousColumn = 'name; DROP TABLE users; --';
        $query = $this->db->table('users')->orderBy($maliciousColumn);

        try {
            // A database may read a quoted name that is no column as a string: then it orders nothing
            $this->assertCount(2, $query->get());
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            // or refuse it: no such column
            $this->assertSame('Query failed', $e->getMessage());
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
            $this->db->table('users')
                ->select(['name', $maliciousInput])
                ->get();
            $this->fail('The quoted name is one unknown column on every database');
        } catch (QueryException $e) {
            // Quoted as a whole, the subquery is a column name that does not exist
            $this->assertStringContainsStringIgnoringCase('column', (string) $e->getDebugMessage());
            $this->assertStringNotContainsString('TOP SECRET DATA', (string) $e->getDebugMessage());
        }
    }

    // =========================================================================
    // PARAMETERS THAT CANNOT BE BOUND
    // PDO would bind an array as the text "Array" and a resource as "Resource id #n"
    // =========================================================================

    public function testUnbindableParametersAreRejectedBeforeTheStatementRuns(): void
    {
        $errors = [];
        $queries = 0;
        $this->db->on('error', function (array $data) use (&$errors): void {
            $errors[] = $data;
        });
        $this->db->on('query', function () use (&$queries): void {
            $queries++;
        });

        $stream = fopen('php://memory', 'r');
        $this->assertIsResource($stream);
        $cases = [
            'array' => [['a', 'b'], 'array'],
            'object' => [new \stdClass(), 'stdClass'],
            'date' => [new \DateTimeImmutable('2026-01-01'), 'DateTimeImmutable'],
            'resource' => [$stream, 'resource (stream)'],
        ];

        foreach ($cases as $label => [$value, $type]) {
            $expected = sprintf('Cannot bind a value of type %s (parameter #2)', $type);
            try {
                $this->db->insert('users', ['name' => 'Bound', 'email' => $value]);
                $this->fail("A value of type {$label} must not be bound");
            } catch (QueryException $e) {
                $this->assertSame('Query failed', $e->getMessage());
                $this->assertStringContainsString($expected, (string) $e->getDebugMessage());
                $this->assertStringContainsString(' | SQL: ', (string) $e->getDebugMessage(), 'the statement is named');
            }
            $error = array_pop($errors);
            $this->assertIsArray($error);
            $this->assertStringContainsString($expected, (string) $error['error']);
            $this->assertSame(0, $error['code']);
            $this->assertSame([null, null], [$error['sqlState'], $error['driverCode']], 'nothing was sent');
            $this->assertSame(['Bound', $value], $error['params']);
        }
        fclose($stream);

        $this->assertSame(0, $queries, 'no statement was sent');
        $this->assertSame(2, $this->db->table('users')->count());
    }

    public function testUnbindableParameterIsNamedByItsPlaceholder(): void
    {
        try {
            $this->db->query('SELECT * FROM users WHERE name = :name', ['name' => ['Admin']]);
            $this->fail('An array must not be bound');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot bind a value of type array (parameter "name")', (string) $e->getDebugMessage());
        }

        try {
            $this->db->table('users')->where('name', ['Admin'])->get();
            $this->fail('An array must not be bound');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Cannot bind a value of type array (parameter #1)', (string) $e->getDebugMessage());
        }
    }

    public function testStringableObjectsAndScalarsAreBound(): void
    {
        $name = new class () {
            public function __toString(): string
            {
                return 'Admin';
            }
        };

        $rows = $this->db->table('users')->where('name', $name)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('admin@example.com', $rows[0]['email']);

        $this->assertCount(1, $this->db->table('users')->where('id', '>', 1)->where('role', 'user')->get());
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
        // Nothing to compare: every statement below has to run without throwing
        $this->expectNotToPerformAssertions();

        // Test comparison operators on integer column
        $comparisonOperators = ['=', '!=', '<>', '<', '>', '<=', '>='];

        foreach ($comparisonOperators as $operator) {
            $this->db->table('users')
                ->where('id', $operator, 1)
                ->get();
        }

        // Test LIKE operators on string column (LIKE on an integer is the database's own matter)
        $likeOperators = ['LIKE', 'NOT LIKE'];

        foreach ($likeOperators as $operator) {
            $this->db->table('users')
                ->where('name', $operator, '%Admin%')
                ->get();
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
                $this->assertStringContainsString('Invalid operator', $e->getDebugMessage() ?? '');
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
            $this->assertStringContainsString('safety check', $e->getDebugMessage() ?? '');
        }

        // All users should still have original roles
        $admin = $this->db->table('users')->where('name', 'Admin')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin['role']);
    }

    public function testDeleteWithoutWhereThrowsException(): void
    {
        try {
            $this->db->table('users')->delete();
            $this->fail('Delete without WHERE should throw exception');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertStringContainsString('safety check', $e->getDebugMessage() ?? '');
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
            $this->assertStringContainsString('safety check', $e->getDebugMessage() ?? '');
        }
    }

    public function testDirectDeleteWithEmptyWhereThrowsException(): void
    {
        try {
            $this->db->delete('users', []);
            $this->fail('Direct delete with empty WHERE should throw exception');
        } catch (\Sodaho\PdoWrapper\Exception\QueryException $e) {
            $this->assertStringContainsString('safety check', $e->getDebugMessage() ?? '');
        }
    }

    /**
     * A NUL byte inside a value is bound as part of the value, not cut off.
     */
    public function testNullByteInValue(): void
    {
        $nameWithNull = "Null\x00Byte";
        $this->db->insert('users', ['name' => $nameWithNull, 'email' => 'nullbyte@example.com']);

        $user = $this->db->table('users')->where('email', 'nullbyte@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($nameWithNull, $user['name']);
    }
}
