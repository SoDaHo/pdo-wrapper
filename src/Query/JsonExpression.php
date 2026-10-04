<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

use Sodaho\PdoWrapper\Exception\QueryException;

/**
 * A value inside a JSON column, as text: `JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.net'))`.
 * Created with Database::json(); usable in where*(), select() (named with as()), groupBy() and
 * orderBy(). In having(), refer to the select() alias instead: MariaDB takes a column there only
 * from the select list or as a GROUP BY column, and the JSON column inside the expression is
 * neither (error 1054, measured).
 *
 * The path is written into the SQL, not bound: bound, MariaDB rejects a GROUP BY on the
 * expression under ONLY_FULL_GROUP_BY (error 1055, measured on 10.11, 11.4 and 12.3), and the
 * text of the statement would not show which field it reads. It is therefore checked: `$`
 * followed by `.name` steps (a letter or underscore, then letters, digits, underscores) and
 * `[n]` steps (an array index of up to 9 digits: MariaDB reads a larger one modulo 2^32, so that
 * `$[4294967296]` would be element 0 - measured) - nothing else, no quotes, no wildcards.
 *
 * What MariaDB returns (measured on 10.11, 11.4 and 12.3): a string, a number or a boolean of
 * the document as text ('net-a', '5', '1.50', 'true'); SQL NULL for a missing field, a document
 * that is no valid JSON and a NULL column - so a row without the field matches no comparison
 * but IS / IS NOT -; and the text 'null' for a JSON null. It is compared and ordered as text
 * under utf8mb4_bin: '12' < '5', '1.5' <> '1.50', case and accents count. Cast a field that
 * holds numbers (Database::raw('CAST(' . $json . ' AS DECIMAL(20,6))')): text, 'true' and JSON
 * null cast to 0 with a warning only, and the cast rounds to its places.
 *
 * (string) is the expression itself, the same a virtual column can be declared with; see the
 * README on using its index.
 */
final class JsonExpression extends RawExpression
{
    /** A JSON path of `.name` and `[n]` steps after `$` */
    private const PATH = '/^\$(?:\.[A-Za-z_][A-Za-z0-9_]*|\[\d{1,9}\])*$/D';

    /**
     * @param string $column The JSON column ("payload", "events.payload")
     * @param string $path The path inside it ('$.net', '$.items[0].id')
     * @param list<string> $fallbacks Columns COALESCE() falls back to, in order (see orColumn())
     *
     * @throws QueryException When the path is not `$` followed by `.name` and `[n]` steps
     */
    public function __construct(
        private readonly string $column,
        private readonly string $path,
        private readonly array $fallbacks = []
    ) {
        if (preg_match(self::PATH, $path) !== 1) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('Invalid JSON path "%s": write $ followed by .name and [n] steps (a name starts with a letter or an underscore and holds letters, digits and underscores; an index has up to 9 digits)', $path)
            );
        }

        $sql = sprintf("JSON_UNQUOTE(JSON_EXTRACT(%s, '%s'))", self::quote($column), $path);
        if ($fallbacks !== []) {
            $sql = sprintf('COALESCE(%s, %s)', $sql, implode(', ', array_map(self::quote(...), $fallbacks)));
        }

        parent::__construct($sql);
    }

    /**
     * The value, or the column's value where the document has none (SQL NULL: a missing field,
     * no valid JSON, a NULL column): `COALESCE(<the expression>, `column`)`. Called again, the
     * next column comes last.
     */
    public function orColumn(string $column): self
    {
        return new self($this->column, $this->path, [...$this->fallbacks, $column]);
    }

    /**
     * The expression as a select() entry named $alias: `<the expression> AS `alias``.
     *
     * @throws QueryException When the alias is not a name of letters, digits and underscores
     */
    public function as(string $alias): RawExpression
    {
        if (preg_match('/^\w+$/D', $alias) !== 1) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('Invalid alias "%s" for a JSON value: use letters, digits and underscores', $alias)
            );
        }

        return new RawExpression(sprintf('%s AS `%s`', $this->value, $alias));
    }

    /**
     * A column reference quoted like the builder quotes one: each dotted part in backticks, a
     * backtick in a name doubled.
     */
    private static function quote(string $column): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '`' . str_replace('`', '``', $part) . '`',
            explode('.', $column)
        ));
    }
}
