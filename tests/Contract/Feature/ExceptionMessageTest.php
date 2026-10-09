<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\Feature;

use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;

/**
 * getMessage() of the library's exceptions never carries the SQL or a bound value: it is the
 * channel an application shows or logs without thinking. Values reach getDebugMessage(), the
 * hook payloads and the previous exception only (see README, "Parameters are secrets").
 */
class ExceptionMessageTest extends ContractTestCase
{
    private const SECRET = 'tok-very-secret-4711';

    public function testAFailedStatementNamesNeitherTheSqlNorAValue(): void
    {
        $this->assertCleanMessage(fn (): mixed => $this->db->query('SELECT * FROM no_such_table_for_messages WHERE token = ?', [self::SECRET]));
    }

    public function testAUniqueViolationNamesNoValue(): void
    {
        $this->create('message_tokens', ['id' => 'id', 'token' => 'text', 'UNIQUE (token)']);
        $this->db->insert('message_tokens', ['token' => self::SECRET]);

        $e = $this->assertCleanMessage(fn (): mixed => $this->db->insert('message_tokens', ['token' => self::SECRET]));
        $this->assertInstanceOf(UniqueViolationException::class, $e);
        $this->assertStringContainsString(self::SECRET, (string) $e->getPrevious()?->getMessage(), 'the previous exception is the database\'s own: it does carry the value');
    }

    public function testAParameterThatCannotBeBoundNamesNoValue(): void
    {
        $this->assertCleanMessage(fn (): mixed => $this->db->query('SELECT ?', [[self::SECRET]]));
    }

    private function assertCleanMessage(\Closure $call): QueryException
    {
        try {
            $call();
        } catch (QueryException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString('SELECT', $e->getMessage());
            $this->assertStringNotContainsString('INSERT', $e->getMessage());

            return $e;
        }
        $this->fail('Expected QueryException');
    }
}
