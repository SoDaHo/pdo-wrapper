<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

/**
 * The one place that writes a name or a value into SQL, for the query builder, JSON expressions and
 * the driver's CRUD methods (MariaDB: backticks).
 *
 * A name is quoted part by part at its dots, a backtick inside a part doubled: whatever it holds, it
 * stays one identifier. What a name may mean besides - an alias ("col as x"), a table wildcard
 * ("t.*") - the caller decides: the builder knows both, the CRUD methods neither (there every key
 * is the name of one column, `a as b` included). A value is a placeholder with its binding, or the
 * SQL of a RawExpression with its own bindings at exactly that position among the params.
 *
 * @internal Not part of the public API: a driver of its own overrides AbstractDriver::quoteIdentifier()
 */
final class Sql
{
    /** Identifiers are quoted with backticks (MariaDB) */
    private const QUOTE = '`';

    /**
     * Quote a name ("col", "table.col"), part by part. With $wildcard a part of a dotted name that is
     * "*" stays a wildcard ("users.*" → `users`.*), as the builder's column lists take it; without it,
     * and for a name without a dot, it is the name "*".
     */
    public static function name(string $identifier, bool $wildcard = false): string
    {
        $wildcard = $wildcard && str_contains($identifier, '.');

        return implode('.', array_map(
            static fn (string $part): string => $wildcard && $part === '*'
                ? '*'
                : self::QUOTE . str_replace(self::QUOTE, self::QUOTE . self::QUOTE, $part) . self::QUOTE,
            explode('.', $identifier)
        ));
    }

    /**
     * What stands for a value in the SQL, and its params: a placeholder and the value - or, for a
     * RawExpression, its SQL and its own bindings, at this very position among the params
     * (SECURITY: never pass user input as the SQL of Database::raw()).
     *
     * @param array<int, mixed> $params
     */
    public static function value(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array &$params): string
    {
        if (!$value instanceof RawExpression) {
            $params[] = $value;

            return '?';
        }

        foreach ($value->bindings as $binding) {
            $params[] = $binding;
        }

        return (string) $value;
    }
}
