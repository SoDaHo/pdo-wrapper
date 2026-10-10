<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Sodaho\PdoWrapper\Database;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Driver\AbstractDriver;
use Sodaho\PdoWrapper\Driver\MariaDbDriver;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Schema\Schema;
use Throwable;

/**
 * Every public and protected parameter that may carry a value - an array, mixed, a number, a
 * RawExpression with its bindings, a named lock's name - is #[\SensitiveParameter] in the API
 * (DatabaseInterface, AbstractDriver with its protected and private helpers, the MariaDB driver's
 * overrides and helpers, QueryBuilder with its private helpers, Database, RawExpression, Schema with
 * its private helpers - the table's name is a value Schema binds -): with zend.exception_ignore_args
 * off a trace shows a SensitiveParameterValue in its place. Pinned by reflection, so that a new or changed signature
 * cannot drop it unnoticed, and by the traces of inputs the library refuses before anything is
 * sent (the driver's own trace test is tests/Driver/MariaDb/RedactParametersTest).
 */
class SensitiveParametersTest extends TestCase
{
    private const MARKER = 'marker-of-a-secret-value';

    /** The classes whose private methods are read as well: the driver's, the builder's and the schema's helpers */
    private const PRIVATE_HELPERS = [AbstractDriver::class, MariaDbDriver::class, QueryBuilder::class, Schema::class];

    /**
     * Names that carry a value in one class alone: Schema binds the table's name in its statements
     * (Daybreak review of the seventh candidate), elsewhere a table's name is an identifier
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
    ];

    /**
     * Every public and protected method of the API's classes - an override of the MariaDB driver
     * and a protected helper of the driver are frames of the traces as much as the public methods
     * are - and the value parameters among their parameters.
     *
     * @return iterable<string, array{ReflectionParameter}>
     */
    public static function valueParameters(): iterable
    {
        $methods = [];
        foreach ([DatabaseInterface::class, AbstractDriver::class, MariaDbDriver::class, QueryBuilder::class, Database::class, RawExpression::class, Schema::class] as $class) {
            // The private helpers of the driver and the builder too: they are frames of the same traces
            $filter = ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED | (in_array($class, self::PRIVATE_HELPERS, true) ? ReflectionMethod::IS_PRIVATE : 0);
            foreach (new \ReflectionClass($class)->getMethods($filter) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // read where it is declared
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

    private static function mayCarryAValue(?ReflectionType $type, string $name): bool
    {
        if (in_array($name, ['value', 'pattern', 'name'], true)) {
            return true; // a value as a string: escapeLike()'s, a LIKE pattern, a named lock's name
        }
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $one) {
            if ($one instanceof ReflectionNamedType && in_array($one->getName(), ['array', 'mixed', 'int', 'float', RawExpression::class], true)) {
                return !in_array($name, ['timeout', 'limit', 'offset', 'count'], true);
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
