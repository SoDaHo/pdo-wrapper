<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use ArrayObject;
use PDOException;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\NamedLockReentryException;
use Sodaho\PdoWrapper\Exception\NamedLocksHeldException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\RedactedPdoException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Tests\Support\TestEnvironment;
use Sodaho\PdoWrapper\Tests\Support\Untyped;
use Throwable;

/**
 * The option redactParameters: a value a statement binds - here a secret in a duplicate key and in
 * an incorrect integer, both quoted by MariaDB's message - appears in no hook payload, no debug
 * message, no previous exception and no trace argument of the library's exceptions. Without the
 * option the payloads, debug messages and previous exceptions carry it (documented); the trace
 * arguments never do (#[\SensitiveParameter]). Traces are read with zend.exception_ignore_args off.
 */
class RedactParametersTest extends TestCase
{
    private const TABLE = 'redact_rows';

    private const SECRET = 'tok-SECRET-4711';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $setup = Database::mariadb(TestEnvironment::mariadb());
        $setup->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $setup->getPdo()->exec('CREATE TABLE ' . self::TABLE . ' (id BIGINT PRIMARY KEY, email VARCHAR(100) NOT NULL, n INT, UNIQUE KEY uq_email (email))');
        $setup->insert(self::TABLE, ['id' => 1, 'email' => self::SECRET, 'n' => 1]);
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs === false ? '1' : $this->ignoreArgs);
        Database::mariadb(TestEnvironment::mariadb())->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /**
     * A driver with or without the option, and what its hooks are told.
     *
     * @return array{MariaDbDriver, ArrayObject<int, array<mixed>>}
     */
    private function driver(bool $redact): array
    {
        $db = Database::mariadb(TestEnvironment::mariadb() + ['redactParameters' => $redact]);
        /** @var ArrayObject<int, array<mixed>> $payloads */
        $payloads = new ArrayObject();
        foreach (['query.before', 'query', 'error', 'transaction.end'] as $event) {
            /** @param array<string, mixed> $data */
            $listener = static function (array $data) use ($payloads, $event): void {
                $payloads[] = ['event' => $event] + $data;
            };
            $db->on($event, $listener);
        }

        return [$db, $payloads];
    }

    public function testWithTheOptionNoChannelCarriesABoundValue(): void
    {
        [$db, $payloads] = $this->driver(true);

        $duplicate = $this->failWith(static fn () => $db->insert(self::TABLE, ['id' => 2, 'email' => self::SECRET]));
        $this->assertInstanceOf(UniqueViolationException::class, $duplicate);
        $this->assertSame('uq_email', $duplicate->constraint, 'the key is read before the message is redacted');
        $this->assertSame(['23000', 1062], [$duplicate->sqlState, $duplicate->driverCode]);
        $previous = $duplicate->getPrevious();
        $this->assertInstanceOf(RedactedPdoException::class, $previous);
        $this->assertSame(['23000', 1062], [$previous->getCode(), $previous->errorInfo[1] ?? null]);
        $this->assertNull($previous->getPrevious());

        $incorrect = $this->failWith(static fn () => $db->update(self::TABLE, ['n' => self::SECRET], ['id' => 1]));
        $this->assertSame(1366, $incorrect->driverCode);

        // inside a transaction: the callback's exception is the end's error
        $this->failWith(static fn () => $db->transaction(static fn (DatabaseInterface $db): int => $db->insert(self::TABLE, ['id' => 3, 'email' => self::SECRET])));

        foreach ([$duplicate, $incorrect] as $e) {
            $this->assertNoSecretIn($e);
        }
        $this->assertGreaterThan(0, count($payloads));
        foreach ($payloads as $payload) {
            $this->assertNoSecretInValue($payload, (string) $payload['event']);
            foreach (is_array($payload['params'] ?? null) ? $payload['params'] : [] as $value) {
                $this->assertSame('[redacted]', $value, 'every value replaced, the keys kept');
            }
        }

        // a listener's PDOException may quote the values of its own statement: replaced as well
        foreach (['query.before', 'query'] as $event) {
            $armed = true;
            $throwing = static function () use (&$armed): void {
                if ($armed) {
                    $armed = false;
                    throw new PDOException('the listener failed over ' . self::SECRET);
                }
            };
            $db->on($event, $throwing);
            $hook = $this->failWith(static fn () => $db->findOne(self::TABLE, ['email' => self::SECRET]));
            $db->off($event, $throwing);
            $this->assertSame('Query hook failed', $hook->getMessage(), $event);
            $this->assertInstanceOf(RedactedPdoException::class, $hook->getPrevious(), $event);
            $this->assertNoSecretIn($hook);
        }

        // a named lock's name is bound as well
        $this->assertTrue($db->namedLock(self::SECRET));
        $reentry = $this->failWith(static fn () => $db->namedLock(self::SECRET));
        $this->assertInstanceOf(NamedLockReentryException::class, $reentry);
        $this->assertStringNotContainsString(self::SECRET, (string) $reentry->getDebugMessage());
        $this->assertSame(self::SECRET, $reentry->lockName, 'the documented property keeps it');
        try {
            $db->reconnect();
            $this->fail('Expected NamedLocksHeldException');
        } catch (NamedLocksHeldException $e) {
            $this->assertStringNotContainsString(self::SECRET, (string) $e->getDebugMessage());
        }
        $db->releaseNamedLock(self::SECRET);
    }

    /**
     * Without the option the database's message reaches the debug message, the previous exception
     * and the 'error' payload (documented: "Parameters are secrets") - the trace arguments of the
     * library's methods never.
     */
    public function testWithoutTheOptionOnlyTheTraceArgumentsAreClean(): void
    {
        [$db, $payloads] = $this->driver(false);

        $duplicate = $this->failWith(static fn () => $db->insert(self::TABLE, ['id' => 2, 'email' => self::SECRET]));

        $this->assertStringContainsString(self::SECRET, (string) $duplicate->getDebugMessage());
        $previous = $duplicate->getPrevious();
        $this->assertInstanceOf(PDOException::class, $previous);
        $this->assertNotInstanceOf(RedactedPdoException::class, $previous);
        $this->assertStringContainsString(self::SECRET, $previous->getMessage());
        $this->assertContains(self::SECRET, array_merge(...array_map(static fn (array $p): array => is_array($p['params'] ?? null) ? array_values($p['params']) : [], $payloads->getArrayCopy())));
        // the library's own exception: its frames are the library's methods; the PDOException before it
        // has PDO's own frame (PDOStatement::execute) with the values - documented, the option replaces it
        $this->assertNoSecretInTrace($duplicate, withPrevious: false);
    }

    public function testTheOptionMustBeABoolean(): void
    {
        foreach (['yes', 1, 'false', null] as $value) {
            try {
                Untyped::create(MariaDbDriver::class, ['redactParameters' => $value] + TestEnvironment::mariadb());
                $this->fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                $this->assertSame('Invalid config value "redactParameters": expected true or false', $e->getDebugMessage());
            }
        }
    }

    /** @param \Closure(): mixed $call */
    private function failWith(\Closure $call): QueryException
    {
        try {
            $call();
        } catch (QueryException $e) {
            return $e;
        }
        $this->fail('Expected QueryException');
    }

    private function assertNoSecretIn(Throwable $e): void
    {
        $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        if ($e instanceof QueryException) {
            $this->assertStringNotContainsString(self::SECRET, (string) $e->getDebugMessage());
        }
        $this->assertStringNotContainsString(self::SECRET, (string) $e, '(string) $e, the previous exceptions included');
        $this->assertNoSecretInTrace($e);
    }

    /** Every trace argument of the exception and - unless told otherwise - of every exception before it */
    private function assertNoSecretInTrace(Throwable $e, bool $withPrevious = true): void
    {
        for ($current = $e; $current !== null; $current = $withPrevious ? $current->getPrevious() : null) {
            foreach ($current->getTrace() as $frame) {
                $this->assertNoSecretInValue($frame['args'] ?? [], ($frame['class'] ?? '') . '::' . $frame['function']);
            }
        }
    }

    private function assertNoSecretInValue(mixed $value, string $where, int $depth = 0): void
    {
        if (is_string($value)) {
            $this->assertStringNotContainsString(self::SECRET, $value, $where);
        } elseif (is_array($value) && $depth < 4) {
            foreach ($value as $element) {
                $this->assertNoSecretInValue($element, $where, $depth + 1);
            }
        } elseif ($value instanceof Throwable && $depth < 4) {
            $this->assertStringNotContainsString(self::SECRET, $value->getMessage(), $where);
        } elseif (is_object($value) && !$value instanceof SensitiveParameterValue && $depth < 2) {
            $this->assertNoSecretInValue(get_object_vars($value), $where, $depth + 1);
        }
    }
}
