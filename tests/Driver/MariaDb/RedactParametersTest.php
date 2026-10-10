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
use Sodaho\PdoWrapper\Schema\Schema;
use Sodaho\PdoWrapper\Tests\Support\StatementClassPdo;
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
        RevealingInsertIdPdo::reset();
        InsertIdEchoPdo::$echoed = null;
        RevealingColumnStatement::$mode = 'throws';
        RevealingColumnStatement::$quoted = '';
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

    /**
     * The MariaDB driver's own insertIgnore(), upsert() and insertWhen() are frames of every trace
     * that goes through them: their arguments are SensitiveParameterValue objects, with the option
     * or without. A value they refuse to bind leaves the statement unsent; with the option the
     * database's message about a value they sent (an incorrect integer) is replaced as well.
     */
    public function testTheDriversOwnMethodsKeepTheValuesOutOfTheirFrames(): void
    {
        foreach ([false, true] as $redact) {
            [$db] = $this->driver($redact);
            $refused = [
                'insertIgnore' => static fn () => $db->insertIgnore(self::TABLE, ['id' => 5, 'email' => self::SECRET, 'n' => []]),
                'upsert' => static fn () => $db->upsert(self::TABLE, ['id' => 6, 'email' => self::SECRET], ['n' => []]),
                'insertWhen' => static fn () => $db->insertWhen(self::TABLE, ['id' => 7, 'email' => self::SECRET], '1 = ?', [[]]),
            ];
            foreach ($refused as $method => $call) {
                $e = $this->failWith($call);
                $frames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === MariaDbDriver::class && $frame['function'] === $method));
                $this->assertCount(1, $frames, "{$method}: the driver's own frame");
                $this->assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][1] ?? null, "{$method}: its data");
                $this->assertNoSecretInTrace($e);
            }
            if ($redact) {
                foreach ([
                    'insertIgnore' => static fn () => $db->insertIgnore(self::TABLE, ['id' => 8, 'email' => 'x@example.test', 'n' => self::SECRET]),
                    'upsert' => static fn () => $db->upsert(self::TABLE, ['id' => 1, 'email' => 'y@example.test'], ['n' => self::SECRET]),
                ] as $method => $call) {
                    $e = $this->failWith($call);
                    $this->assertSame(1366, $e->driverCode, $method);
                    $this->assertNoSecretIn($e);
                }
            }
        }
        $this->assertSame([1], array_column(Database::mariadb(TestEnvironment::mariadb())->findAll(self::TABLE), 'id'), 'nothing was written');
    }

    /**
     * What is read after a statement ran - the answer of a named-lock statement, the id of an
     * inserted row - can fail with a message that quotes a value (replayed: a statement class and a
     * PDO class whose failures quote the secret). With the option that failure is replaced as well,
     * thrown or reported through errorInfo() after a false, before it is remembered, chained or
     * shown: no previous exception and no debug message carries it, the codes stay.
     */
    public function testWithTheOptionAFailedReadAfterTheStatementCarriesNoValue(): void
    {
        RevealingColumnStatement::$quoted = self::SECRET;
        RevealingInsertIdPdo::$quoted = self::SECRET;
        foreach (['throws', 'false'] as $mode) {
            RevealingColumnStatement::$mode = $mode;
            $db = Database::mariadb(TestEnvironment::mariadb() + StatementClassPdo::config(RevealingColumnStatement::class) + ['redactParameters' => true]);
            $lock = $this->failWith(static fn (): bool => $db->namedLock('job'));
            $this->assertSame('namedLock(): the statement ran, but its answer could not be read', $lock->getDebugMessage(), $mode);
            $this->assertInstanceOf(RedactedPdoException::class, $lock->getPrevious(), "namedLock(), {$mode}");
            $this->assertSame(['HY000', 2027], [$lock->sqlState, $lock->driverCode], "namedLock(), {$mode}: the codes stay");
            $this->assertNoSecretIn($lock);
            unset($db); // the lock it may have taken goes with its connection

            RevealingInsertIdPdo::$mode = $mode;
            $db = Database::mariadb(TestEnvironment::mariadb() + ['pdoClass' => RevealingInsertIdPdo::class, 'redactParameters' => true]);
            $insert = $this->failWith(static fn (): int => $db->insert(self::TABLE, ['id' => 9, 'email' => 'x@example.test']));
            $this->assertSame($mode === 'throws' ? 'Failed to get last insert ID' : 'Insert failed', $insert->getMessage());
            $this->assertInstanceOf(RedactedPdoException::class, $insert->getPrevious(), "insert(), {$mode}");
            $this->assertSame(['HY000', 2027], [$insert->sqlState, $insert->driverCode], "insert(), {$mode}: the codes stay");
            $this->assertNoSecretIn($insert);
            RevealingInsertIdPdo::$mode = null;
            $db->delete(self::TABLE, ['id' => 9]);

            // Inside a transaction the failure is remembered: it is the previous of the end told once
            // the transaction is found ended behind the library's back
            $errors = [];
            $db->on('transaction.end', static function (array $data) use (&$errors): void {
                $errors[] = $data['error'];
            });
            $db->beginTransaction();
            RevealingInsertIdPdo::$mode = $mode;
            $this->failWith(static fn (): int => $db->insert(self::TABLE, ['id' => 10, 'email' => 'y@example.test']));
            RevealingInsertIdPdo::$mode = null;
            $db->getPdo()->rollBack();
            $db->rollback();
            $this->assertCount(1, $errors, "{$mode}: lost");
            $this->assertInstanceOf(Throwable::class, $errors[0]);
            $this->assertInstanceOf(RedactedPdoException::class, $errors[0]->getPrevious(), "{$mode}: the remembered failure");
            $this->assertNoSecretIn($errors[0]);
        }
    }

    /**
     * A named-lock answer no method understands - here the very name the statement bound, delivered
     * by a statement class that echoes its first value - is shown by its type alone with the option:
     * no debug message of the four methods carries it. namedLock() took the lock and counts it, the
     * release runs last and frees it on the server.
     */
    public function testWithTheOptionAnAnswerNotUnderstoodIsShownByItsTypeAlone(): void
    {
        $db = Database::mariadb(TestEnvironment::mariadb() + StatementClassPdo::config(BindingEchoStatement::class) + ['redactParameters' => true]);

        foreach ([
            'namedLock' => static fn (): bool => $db->namedLock(self::SECRET),
            'isNamedLockHeld' => static fn (): bool => $db->isNamedLockHeld(self::SECRET),
            'namedLockHolder' => static fn (): ?int => $db->namedLockHolder(self::SECRET),
            'releaseNamedLock' => static fn (): bool => $db->releaseNamedLock(self::SECRET),
        ] as $method => $call) {
            $e = $this->failWith($call);
            $this->assertStringStartsWith($method . '(): ', (string) $e->getDebugMessage());
            $this->assertStringContainsString(' answered string for the lock (name redacted), ', (string) $e->getDebugMessage(), $method);
            $this->assertNoSecretIn($e);
        }
        $this->assertSame([], $db->heldNamedLocks(), 'the release ran');
    }

    /**
     * A float step that DECIMAL(65,30) cannot hold is refused before anything is sent, and the
     * refusal does not show the float - a bound value: with the option no channel carries it
     * (Daybreak review of the sixth candidate: the debug message showed it, var_export()).
     */
    public function testWithTheOptionARefusedFloatStepIsNotShown(): void
    {
        [$db, $payloads] = $this->driver(true);
        $step = 4.711471147114711e40;
        foreach (['increment', 'decrement'] as $method) {
            $e = $this->failWith(static fn (): mixed => $db->table(self::TABLE)->where('id', 1)->{$method}('n', $step));
            $this->assertSame('Update failed', $e->getMessage(), $method);
            foreach ([(string) $e->getDebugMessage(), (string) $e] as $channel) {
                $this->assertStringNotContainsString('4711', $channel, $method);
                $this->assertStringNotContainsString('E+40', $channel, $method);
            }
            $this->assertStringContainsString('not shown: a bound value', (string) $e->getDebugMessage(), $method);
        }
        $this->assertSame([], $payloads->getArrayCopy(), 'nothing was sent, no hook was told');
    }

    /**
     * insert() throws after the row was inserted when the ID the database reports is no integer of
     * PHP - and that ID may be a bound value: the server reports an explicit id back (a BIGINT
     * UNSIGNED beyond PHP_INT_MAX), a PDO class of the caller's may report anything (replayed: one
     * that reports the first bound value). With the option the debug message shows `[redacted]`
     * for it (Daybreak review of the sixth candidate: it showed the ID next to "Params: 2
     * redacted"); without it the ID as before.
     */
    public function testWithTheOptionAnIdThatEchoesABoundValueIsNotShown(): void
    {
        $echo = self::SECRET . '.echo';
        foreach ([true, false] as $redact) {
            $db = Database::mariadb(TestEnvironment::mariadb() + ['pdoClass' => InsertIdEchoPdo::class, 'redactParameters' => $redact]);
            $e = $this->failWith(static fn (): int => $db->insert(self::TABLE, ['email' => $echo, 'id' => 7]));
            $this->assertSame('Insert ID out of range', $e->getMessage());
            if ($redact) {
                $this->assertNoSecretIn($e);
                $this->assertStringContainsString('the ID the database reports for it, [redacted], is no integer of PHP', (string) $e->getDebugMessage());
                $this->assertStringEndsWith(' | Params: 2 redacted', (string) $e->getDebugMessage());
            } else {
                $this->assertStringContainsString('the ID the database reports for it, "' . $echo . '", is no integer of PHP', (string) $e->getDebugMessage(), 'without the option as before');
            }
            $this->assertSame(1, $db->delete(self::TABLE, ['id' => 7]), 'the row was inserted');
        }
    }

    /**
     * The server itself echoes a bound value: an explicit id beyond PHP_INT_MAX in a BIGINT
     * UNSIGNED column is the ID it reports. With the option the debug message does not show it.
     */
    public function testWithTheOptionAnIdTheServerReportsBackIsNotShown(): void
    {
        $db = Database::mariadb(TestEnvironment::mariadb() + ['redactParameters' => true]);
        $db->getPdo()->exec('DROP TABLE IF EXISTS redact_big');
        $db->getPdo()->exec('CREATE TABLE redact_big (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))');
        try {
            $e = $this->failWith(static fn (): int => $db->insert('redact_big', ['id' => '18446744073709547110', 'name' => 'big']));
            $this->assertSame('Insert ID out of range', $e->getMessage());
            $this->assertStringNotContainsString('18446744073709547110', (string) $e, 'the bound id, reported back');
            $this->assertStringContainsString(', [redacted], is no integer of PHP', (string) $e->getDebugMessage());
        } finally {
            $db->getPdo()->exec('DROP TABLE IF EXISTS redact_big');
        }
    }

    /**
     * schema() binds the table's name in its statements: a value like any other. The debug message
     * of an unknown table names it no more, with the option or without (Daybreak review of the
     * seventh candidate: columns() and the helper behind indexes() and constraints() wrote it
     * there), and it is a SensitiveParameterValue in every frame - hasTable() and the helper that
     * sends the statements as well, seen in the trace of a statement the library refuses (the
     * transaction ended behind its back). With the option no hook payload carries it either.
     */
    public function testATableNameTheSchemaBindsIsShownNowhere(): void
    {
        $db = null;
        try {
            foreach ([true, false] as $redact) {
                [$db, $payloads] = $this->driver($redact);
                foreach (['columns', 'indexes', 'constraints'] as $method) {
                    $e = $this->failWith(static fn (): mixed => $db->schema()->{$method}(self::SECRET));
                    $this->assertSame($method . '(): the current database has no table of the name given (not shown: a bound value)', $e->getDebugMessage());
                    $this->assertNoSecretIn($e);
                    $this->assertSensitiveArgument($e, $method, 0);
                    $this->assertSensitiveArgument($e, 'ofTable', 1);
                }

                $db->beginTransaction();
                $db->getPdo()->exec('CREATE TABLE IF NOT EXISTS redact_ddl (id INT)'); // commits implicitly, behind the library's back
                $refused = $this->failWith(static fn (): bool => $db->schema()->hasTable(self::SECRET));
                $this->assertStringStartsWith('Not sent: ', (string) $refused->getDebugMessage());
                $this->assertNoSecretIn($refused);
                $this->assertSensitiveArgument($refused, 'hasTable', 0);
                $this->assertSensitiveArgument($refused, 'rows', 1);
                $db->rollback();

                if ($redact) {
                    $this->assertGreaterThan(0, count($payloads));
                    foreach ($payloads as $payload) {
                        $this->assertNoSecretInValue($payload, (string) $payload['event']);
                    }
                }
            }
        } finally {
            $db?->getPdo()->exec('DROP TABLE IF EXISTS redact_ddl');
        }
    }

    /**
     * namedLock() binds its timeout as well (Astra review of the seventh candidate): it is a
     * SensitiveParameterValue in the method's frame, with the option or without - here in the trace
     * of an answer not understood (a statement class that echoes the bound name, the lock taken on
     * the server) and of a negative timeout, which the debug message shows only without the option.
     */
    public function testANamedLockTimeoutIsAValueAsWell(): void
    {
        $timeout = 471147;
        foreach ([true, false] as $redact) {
            // A name of its own per pass: the lock the first pass took may live on in its connection
            $name = $redact ? 'redact-timeout-on' : 'redact-timeout-off';
            $echo = Database::mariadb(TestEnvironment::mariadb() + StatementClassPdo::config(BindingEchoStatement::class) + ['redactParameters' => $redact]);
            $taken = $this->failWith(static fn (): bool => $echo->namedLock($name, $timeout));
            $this->assertStringContainsString(', none of 1, 0, -1 or NULL', (string) $taken->getDebugMessage());
            $this->assertTimeoutOnlyAsSensitiveValue($taken, $timeout);

            $db = Database::mariadb(TestEnvironment::mariadb() + ['redactParameters' => $redact]);
            $negative = $this->failWith(static fn (): bool => $db->namedLock($name, -$timeout));
            $this->assertSame(
                sprintf('namedLock() takes a timeout of 0 or more seconds, not %s (MariaDB answers a negative one with NULL)', $redact ? '[redacted]' : '-471147'),
                $negative->getDebugMessage(),
                $redact ? 'with the option without the value' : 'without the option as before'
            );
            $this->assertTimeoutOnlyAsSensitiveValue($negative, -$timeout);
            $this->assertSame([], $db->heldNamedLocks(), 'nothing was sent');
        }
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

    /**
     * The exception's trace has exactly one frame of Schema's $function, and its argument at $at is
     * a SensitiveParameterValue: the check above is not passed by a frame that is missing.
     */
    private function assertSensitiveArgument(Throwable $e, string $function, int $at): void
    {
        $frames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === Schema::class && $frame['function'] === $function));
        $this->assertCount(1, $frames, "Schema::{$function}: its frame");
        $this->assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][$at] ?? null, "Schema::{$function}: its argument {$at}");
    }

    /**
     * The exception's trace has exactly one frame of namedLock(), its timeout argument is a
     * SensitiveParameterValue, and the number appears nowhere in the trace (a number is no string:
     * assertNoSecretInTrace() would not see it).
     */
    private function assertTimeoutOnlyAsSensitiveValue(Throwable $e, int $timeout): void
    {
        $frames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => $frame['function'] === 'namedLock'));
        $this->assertCount(1, $frames, 'namedLock(): its frame');
        $this->assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][1] ?? null, 'namedLock(): its timeout');
        $this->assertStringNotContainsString((string) abs($timeout), $e->getTraceAsString());
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
