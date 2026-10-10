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
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Query\QueryBuilder;
use Sodaho\PdoWrapper\Query\RawExpression;
use Throwable;

/**
 * Every public parameter that may carry a value - an array, mixed, a number, a RawExpression with
 * its bindings - is #[\SensitiveParameter] in the API (DatabaseInterface and the driver that
 * implements it, QueryBuilder, Database::raw()/escapeLike(), RawExpression): with
 * zend.exception_ignore_args off a trace shows a SensitiveParameterValue in its place. Pinned by
 * reflection, so that a new or changed signature cannot drop it unnoticed, and by the traces of
 * inputs the library refuses before anything is sent.
 */
class SensitiveParametersTest extends TestCase
{
    private const MARKER = 'marker-of-a-secret-value';

    /** Parameters of those types that carry names, not values */
    private const NO_VALUES = [
        'insertWhenReturning.columns', // the columns to return
        'upsertReturning.columns',
        'raw.value', // the SQL of the expression, developer code; its bindings are values
        '__construct.value',
    ];

    /**
     * @return iterable<string, array{ReflectionParameter}>
     */
    public static function valueParameters(): iterable
    {
        $methods = [];
        foreach ([DatabaseInterface::class, AbstractDriver::class, QueryBuilder::class] as $class) {
            foreach (new \ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($class === AbstractDriver::class && !method_exists(DatabaseInterface::class, $method->getName())) {
                    continue; // the driver's own methods beyond the interface are covered where they belong
                }
                $methods[] = $method;
            }
        }
        $methods[] = new ReflectionMethod(Database::class, 'raw');
        $methods[] = new ReflectionMethod(Database::class, 'escapeLike');
        $methods[] = new ReflectionMethod(RawExpression::class, '__construct');

        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $key = $method->getName() . '.' . $parameter->getName();
                if (in_array($key, self::NO_VALUES, true) || !self::mayCarryAValue($parameter->getType(), $parameter->getName())) {
                    continue;
                }
                yield $method->getDeclaringClass()->getShortName() . '::' . $key => [$parameter];
            }
        }
    }

    private static function mayCarryAValue(?ReflectionType $type, string $name): bool
    {
        if (in_array($name, ['value', 'pattern'], true)) {
            return true;
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
     * @return iterable<string, array{\Closure(QueryBuilder): mixed}>
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
    }

    /**
     * An input the library refuses before anything is sent: the value appears in no frame of the
     * exception's trace (nor of a previous one), with the arguments in the trace.
     *
     * @param \Closure(QueryBuilder): mixed $refused
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedInputs')]
    public function testARefusedInputLeavesNoValueInTheTrace(\Closure $refused): void
    {
        $query = new class () extends AbstractDriver {
        }->table('t');
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $refused($query);
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
