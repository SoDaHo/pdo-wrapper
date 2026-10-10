<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Schema\Schema;
use Throwable;

/**
 * Every parameter that may carry a value - an array, mixed, a number, a RawExpression with its
 * bindings, a named lock's name, a password - is #[\SensitiveParameter] in every method of every
 * type under src/, public, protected and private (found by walking the directory; the table's
 * name is a value Schema binds): with zend.exception_ignore_args off a trace shows a
 * SensitiveParameterValue in its place. Pinned by reflection, so that a new or changed signature
 * cannot drop it unnoticed, and by the traces of inputs the library refuses before anything is
 * sent (the driver's own trace test is tests/Driver/MariaDb/RedactParametersTest).
 */
class SensitiveParametersTest extends TestCase
{
    private const MARKER = 'marker-of-a-secret-value';

    /**
     * Names that carry a value in one class alone: Schema binds the table's name in its statements,
     * elsewhere a table's name is an identifier
     */
    private const BOUND_NAMES = [Schema::class => ['table']];

    /** Parameters of those types that carry names, not values */
    private const NO_VALUES = [
        'raw.value', // the SQL of the expression, developer code; its bindings are values
        '__construct.value',
        'lastInsertId.name', // the name of a sequence (MariaDB's PDO ignores it)
        'validPort.port', // the port, the PDO class and a switch of the configuration, as the caller wrote them: what a failed connection is debugged with
        'validPdoClass.class',
        'validSwitch.value',
        'pinCompletionType.port',
        'refuseAnUnsupportedConnection.port',
        // the private helpers' numbers of transactions, calls and statements, and their places
        'commitOwnTransaction.own', 'rollbackQuietly.own', 'stillTheTransaction.number', 'failIfNoLongerOpen.number',
        'rollbackJustBegunQuietly.number', 'endedSince.ended', 'endedSince.number', 'endFailedCommitIfGone.ended',
        'endFailedCommitIfGone.number', 'endFailedCommitIfGone.sentBefore', 'noteACommitThatMayHaveTakenEffect.ended',
        'noteACommitThatMayHaveTakenEffect.number', 'noteACommitThatMayHaveTakenEffect.sentBefore', 'thrownByThisCommit.call',
        'batchRow.at',
        'dispatchTransactionEnd.at', // [number, depth] of a transaction
        'endLostTransaction.at',
        'runCommitListeners.at',
        'reportQuietly.context', // the hook and the outcome an 'error' payload names
        'reportTransactionEndFailures.failures', // the listeners' exceptions, handed to the 'error' hook as they are
        'columnKey.key', // the key of a column => value array: a column's name
        'quotedNameKey.name', // the builder's names of output columns
        'selectsTheName.name',
        'ofTable.width', // how many columns each part of Schema's statement selects
        'driverName.driver', // the driver's name as configured (mariadb), what a failed connection is debugged with
        // ImplicitCommit reads the SQL text - developer code, not covered (README) - and positions in it
        'classify.tokens', 'word.tokens', 'word.at', 'word.read', 'createsATemporaryTable.tokens', 'createsATemporaryTable.read',
        'analyzesATable.tokens', 'analyzesATable.read', 'replication.tokens', 'replication.read', 'statementAfterFor.tokens', 'quoteEnd.at',
        '__construct.failures', // CommitHookException: the listeners' exceptions, handed on as they are
        '__construct.fallbacks', // JsonExpression: the names of the columns COALESCE falls back to
    ];

    /**
     * Every type under src/ - class, interface, trait, enum; found by walking the directory, so that
     * a new one is read without being listed here (a fixed list misses Sql::value(),
     * FrozenExpression, ConnectionSettings, FloatText::of() and the constructors of the named-lock
     * exceptions) -, named by PSR-4 from its path.
     *
     * @return list<class-string>
     */
    private static function sourceTypes(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $types = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $type = 'Sodaho\\PdoWrapper\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));
            if (!class_exists($type) && !interface_exists($type) && !trait_exists($type) && !enum_exists($type)) {
                throw new \UnexpectedValueException(sprintf('%s declares no type of its path\'s name', $file->getPathname()));
            }
            $types[] = $type;
        }
        sort($types);

        return $types;
    }

    /**
     * Every method of every type under src/ - public, protected and private: an override, a helper
     * and an exception's constructor are frames of the traces as much as the API is -, read where it
     * is declared, and the value parameters among their parameters.
     *
     * @return iterable<string, array{ReflectionParameter}>
     */
    public static function valueParameters(): iterable
    {
        $methods = [];
        foreach (self::sourceTypes() as $type) {
            foreach (new \ReflectionClass($type)->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $type || $method->isInternal()) {
                    continue; // read where it is declared; PHP's own (an enum's from()) carry no attribute of the library's
                }
                $methods[] = $method;
            }
        }

        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $key = $method->getName() . '.' . $parameter->getName();
                $bound = in_array($parameter->getName(), self::BOUND_NAMES[$method->getDeclaringClass()->getName()] ?? [], true);
                if (!$bound && (in_array($key, self::NO_VALUES, true) || !self::mayCarryAValue($parameter->getType(), $parameter->getName()))) {
                    continue;
                }
                yield $method->getDeclaringClass()->getShortName() . '::' . $key => [$parameter];
            }
        }
    }

    /** The walk finds every type the library had when this test was written, those named above among them */
    public function testEveryTypeUnderSrcIsRead(): void
    {
        $types = self::sourceTypes();
        $this->assertGreaterThanOrEqual(30, count($types));
        foreach ([\Sodaho\PdoWrapper\Query\Sql::class, \Sodaho\PdoWrapper\Driver\FrozenExpression::class, \Sodaho\PdoWrapper\Driver\ConnectionSettings::class, \Sodaho\PdoWrapper\Query\FloatText::class, \Sodaho\PdoWrapper\Exception\NamedLockReentryException::class, \Sodaho\PdoWrapper\Exception\NamedLocksHeldException::class, \Sodaho\PdoWrapper\Traits\HasHooks::class] as $type) {
            $this->assertContains($type, $types);
        }
    }

    private static function mayCarryAValue(?ReflectionType $type, string $name): bool
    {
        if (in_array($name, ['value', 'pattern', 'name', 'lockName', 'password'], true)) {
            return true; // a value as a string: escapeLike()'s, a LIKE pattern, a named lock's name, a password
        }
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $one) {
            if ($one instanceof ReflectionNamedType && in_array($one->getName(), ['array', 'mixed', 'int', 'float', RawExpression::class], true)) {
                return !in_array($name, ['limit', 'offset', 'count'], true);
            }
        }

        return false;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('valueParameters')]
    public function testAValueParameterIsMarkedSensitive(ReflectionParameter $parameter): void
    {
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    /**
     * A raw expression whose __toString() throws, with a secret among its bindings: the exception
     * passes through the library's frames - the builder's rendering, the CRUD methods' and
     * Sql::value() - and none of them shows the binding (a test only of refusals the library throws
     * itself never sees such a frame).
     */
    public function testARawExpressionThatThrowsLeavesNoBindingInTheTrace(): void
    {
        $db = new class () extends AbstractDriver {
        };
        $throwing = new class ('?', [self::MARKER]) extends RawExpression {
            public function __toString(): string
            {
                throw new \RuntimeException('render failed');
            }
        };
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([
                'where() rendered' => static fn (): mixed => $db->table('t')->where('a', $throwing)->toSql(),
                'update()' => static fn (): mixed => $db->update('t', ['a' => $throwing], ['id' => 1]),
                'insert()' => static fn (): mixed => $db->insert('t', ['a' => $throwing]),
                'the builder\'s update()' => static fn (): mixed => $db->table('t')->where('id', 1)->update(['a' => $throwing]),
            ] as $how => $call) {
                try {
                    $call();
                    $this->fail('Expected RuntimeException: ' . $how);
                } catch (\RuntimeException $e) {
                    $this->assertSame('render failed', $e->getMessage(), $how);
                    $trace = var_export($e->getTrace(), true);
                    // str_contains(), not assertStringNotContainsString(): a failure would export the whole trace
                    $this->assertTrue(str_contains($trace, 'SensitiveParameterValue'), $how . ': the arguments are in the trace');
                    $this->assertFalse(str_contains($trace, self::MARKER), $how . ': the binding is in a frame of the trace');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '1' : $ignoreArgs);
        }
    }

    /**
     * @return iterable<string, array{\Closure(QueryBuilder, DatabaseInterface): mixed}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'where() with an array and a null value' => [static fn (QueryBuilder $q): mixed => $q->where(['token' => self::MARKER, 'tenant_id' => null])];
        yield 'a raw expression as a binding' => [static fn (QueryBuilder $q): mixed => Database::raw('?', [self::MARKER, Database::raw('NOW()')])];
        yield 'select() with a bound raw expression' => [static fn (QueryBuilder $q): mixed => $q->select([Database::raw('? AS x', [self::MARKER])])];
        yield 'where() with a bound raw column' => [static fn (QueryBuilder $q): mixed => $q->where(Database::raw('LOWER(name) = ?', [self::MARKER]), 1)];
        yield 'whereIn() with a null element' => [static fn (QueryBuilder $q): mixed => $q->whereIn('token', [self::MARKER, null])];
        yield 'having() with a bound raw column' => [static fn (QueryBuilder $q): mixed => $q->having(Database::raw('SUM(?)', [self::MARKER]), '>', 1)];
        yield 'groupBy() with a bound raw expression' => [static fn (QueryBuilder $q): mixed => $q->groupBy(Database::raw('LEFT(token, ?)', [self::MARKER]))];
        yield 'orderBy() with a bound raw expression' => [static fn (QueryBuilder $q): mixed => $q->orderBy(Database::raw('FIELD(token, ?)', [self::MARKER]))];
        $returning = [Database::raw('? AS x', [self::MARKER])];
        yield 'insertWhenReturning() with a bound raw column' => [static fn (QueryBuilder $q, DatabaseInterface $db): mixed => $db->insertWhenReturning('t', ['a' => 1], '1 = 1', [], [], $returning)];
        yield 'upsertReturning() with a bound raw column' => [static fn (QueryBuilder $q, DatabaseInterface $db): mixed => $db->upsertReturning('t', ['a' => 1], ['a' => 2], $returning)];
        yield 'the builder\'s insertWhenReturning() with a bound raw column' => [static fn (QueryBuilder $q): mixed => $q->insertWhenReturning(['a' => 1], '1 = 1', [], [], $returning)];
        yield 'the builder\'s upsertReturning() with a bound raw column' => [static fn (QueryBuilder $q): mixed => $q->upsertReturning(['a' => 1], ['a' => 2], $returning)];
    }

    /**
     * An input the library refuses before anything is sent: the value appears in no frame of the
     * exception's trace (nor of a previous one), with the arguments in the trace.
     *
     * @param \Closure(QueryBuilder, DatabaseInterface): mixed $refused
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedInputs')]
    public function testARefusedInputLeavesNoValueInTheTrace(\Closure $refused): void
    {
        $db = new class () extends AbstractDriver {
        };
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $refused($db->table('t'), $db);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $traces = '';
            for ($cause = $e; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                $traces .= var_export($cause->getTrace(), true);
            }
            // str_contains(), not assertStringNotContainsString(): a failure would export the whole trace
            $this->assertTrue(str_contains($traces, 'SensitiveParameterValue'), 'the arguments are in the trace');
            $this->assertFalse(str_contains($traces, self::MARKER), 'the value is in a frame of the trace');
            $this->assertFalse(str_contains($e->getMessage(), self::MARKER));
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '1' : $ignoreArgs);
        }
    }
}
