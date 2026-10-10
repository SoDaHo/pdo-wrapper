<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

/**
 * Which SQL statements commit the open transaction implicitly on MariaDB, read from their leading
 * keywords - the list of the MariaDB documentation ("SQL statements that cause an implicit commit"):
 * DDL (ALTER, CREATE, DROP, RENAME, TRUNCATE), LOCK and UNLOCK TABLES, the table maintenance
 * statements (ANALYZE, CHECK, OPTIMIZE, REPAIR TABLE), the account statements (CREATE/DROP/RENAME
 * USER and ROLE, GRANT, REVOKE, SET PASSWORD), CACHE INDEX, LOAD INDEX INTO CACHE, FLUSH, RESET,
 * SHUTDOWN and the replication statements (CHANGE MASTER, START/STOP SLAVE). MariaDB commits before
 * such a statement runs, also when it then fails.
 *
 * Not on the list, although MariaDB commits for them too: the statements that steer transactions
 * themselves (BEGIN, START TRANSACTION, SET autocommit, XA) - raw transaction control is the
 * caller's (decided 2026-10-03, see the README). Not either: CREATE TEMPORARY TABLE and DROP
 * TEMPORARY TABLE, which commit nothing. What a statement runs inside - a stored procedure (CALL), a
 * prepared statement (EXECUTE), a compound statement (BEGIN NOT ATOMIC) - is not seen.
 *
 * The keywords are read past leading whitespace and comments (`#`, `-- `, `/* *\/`); the content of
 * an executable comment (`/*!50100 ...*\/`, `/*M! ...*\/`) counts as SQL, as MariaDB runs it. For
 * `SET STATEMENT ... FOR <statement>` the statement after a FOR decides. Unknown statements and
 * anything that does not start with a keyword commit nothing.
 *
 * @internal The MariaDB driver's answer to AbstractDriver::implicitCommitOf()
 */
final class ImplicitCommit
{
    /** Leading keywords that commit whatever follows them */
    private const ALWAYS = [
        'ALTER', 'CACHE', 'CHANGE', 'CHECK', 'FLUSH', 'GRANT', 'LOCK', 'OPTIMIZE', 'RENAME', 'REPAIR', 'RESET',
        'REVOKE', 'SHUTDOWN', 'TRUNCATE', 'UNLOCK',
    ];

    /** How many keywords are read at most: enough for CREATE OR REPLACE TEMPORARY */
    private const KEYWORDS = 4;

    /**
     * The leading keywords of a statement that commits implicitly ("CREATE", "SET PASSWORD",
     * "START SLAVE"), upper case, or null for one that does not.
     */
    public static function of(string $sql): ?string
    {
        $words = self::leadingKeywords($sql, 0);
        if ($words === []) {
            return null;
        }
        [$first, $second, $third, $fourth] = array_pad(array_map(strtoupper(...), $words), self::KEYWORDS, '');

        $kind = match (true) {
            in_array($first, self::ALWAYS, true) => $first,
            // CREATE TEMPORARY TABLE and DROP TEMPORARY TABLE commit nothing; every other CREATE and DROP does
            $first === 'CREATE' => $second === 'TEMPORARY' || ($second === 'OR' && $third === 'REPLACE' && $fourth === 'TEMPORARY') ? null : 'CREATE',
            $first === 'DROP' => $second === 'TEMPORARY' ? null : 'DROP',
            // ANALYZE TABLE does; ANALYZE SELECT and the other ANALYZE forms that run a statement do not
            $first === 'ANALYZE' => in_array($second, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'FORMAT'], true) ? null : 'ANALYZE',
            $first === 'LOAD' => $second === 'INDEX' ? 'LOAD INDEX' : null,
            ($first === 'START' || $first === 'STOP') && in_array($second, ['SLAVE', 'REPLICA', 'ALL'], true) => $first . ' ' . $second,
            $first === 'SET' && $second === 'PASSWORD' => 'SET PASSWORD',
            $first === 'SET' && $second === 'STATEMENT' => self::statementAfterFor($sql),
            default => null,
        };

        return $kind;
    }

    /**
     * For SET STATEMENT ... FOR <statement>: what the statement after a FOR commits. Every FOR is
     * tried - a value before the real one may hold the word -, so a quoted "for create" among the
     * values refuses as well: fail-closed.
     */
    private static function statementAfterFor(string $sql): ?string
    {
        preg_match_all('/\bFOR\b/i', $sql, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$word, $offset]) {
            $inner = self::of(substr($sql, $offset + strlen($word)));
            if ($inner !== null) {
                return 'SET STATEMENT ... FOR ' . $inner;
            }
        }

        return null;
    }

    /**
     * Up to KEYWORDS words at the start of the statement, from $at on, skipping whitespace and
     * comments before and between them. Reading stops at the first character that is neither:
     * a parenthesis, a quote, an operator - what follows there is no keyword this class decides on.
     *
     * @return list<string>
     */
    private static function leadingKeywords(string $sql, int $at): array
    {
        $words = [];
        $length = strlen($sql);
        while ($at < $length && count($words) < self::KEYWORDS) {
            $skipped = self::skipNoise($sql, $at);
            if ($skipped !== $at) {
                $at = $skipped;
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $match, 0, $at) !== 1) {
                break;
            }
            $words[] = $match[0];
            $at += strlen($match[0]);
        }

        return $words;
    }

    /**
     * Where the statement goes on after whitespace or one comment at $at, or $at when neither
     * starts there. An executable comment's opener alone is skipped: its content is SQL. A comment
     * that is never closed runs to the end.
     */
    private static function skipNoise(string $sql, int $at): int
    {
        // whitespace; "#" and "-- " (MariaDB wants whitespace after the dashes) up to the line end;
        // the opener of an executable comment with its optional version; a plain comment to "*/"
        $patterns = ['/\G\s+/', '/\G(?:#|--(?=\s|$))[^\n]*/', '/\G\/\*M?!\d*/', '/\G\/\*.*?(?:\*\/|$)/s', '/\G\*\//'];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $match, 0, $at) === 1) { // each pattern takes one character at least
                return $at + strlen($match[0]);
            }
        }

        return $at;
    }
}
