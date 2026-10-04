<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper;

/**
 * What the query builder and the library's own code use of a driver beyond DatabaseInterface:
 * the statements behind QueryBuilder::insertIgnore() and insertWhen(), the batch update, the last
 * insert ID and the UTC time. Every driver of this library has them (AbstractDriver), and they
 * stay public there; the interface is not part of the public API and may change in any release.
 *
 * @internal
 */
interface InternalMethods
{
    /**
     * Get the last inserted ID.
     *
     * @param string|null $name Ignored by MariaDB (PDO's sequence name)
     *
     * @return string|false Last insert ID or false on failure
     */
    public function lastInsertId(?string $name = null): string|false;

    /**
     * Current UTC date and time at statement time, to the second, as a raw SQL expression:
     * `UTC_TIMESTAMP()`. A zoneless value: a TIMESTAMP column would interpret it in the session's
     * time zone; use DATETIME, or a UTC session.
     */
    public function utcNow(): Query\RawExpression;

    /**
     * Insert a row only when a condition holds, in one statement:
     * `INSERT INTO table (...) SELECT ?, ?, ... FROM DUAL WHERE (condition)`.
     *
     * Check and insert see the same snapshot, but two concurrent statements can still both see
     * the condition true and both insert (READ COMMITTED / REPEATABLE READ): an invariant such as
     * "one open code per user" needs a UNIQUE constraint, a row lock (lockForUpdate()) or
     * SERIALIZABLE on top.
     *
     * The condition is trusted developer SQL with ? placeholders, like whereRaw(); it may look at
     * the target table itself (`NOT EXISTS (SELECT 1 FROM codes WHERE user_id = ? AND used_at IS NULL)`).
     * Binding order: the row's values first (in column order), then $bindings. A RawExpression in
     * $data is inlined as in insert() (but inside a SELECT list: `raw('DEFAULT')` is not valid there);
     * in $bindings it is not accepted. A TEMPORARY target table cannot be read by its own
     * condition (error 1137). SECURITY: never build the condition from user input; user input
     * belongs in $bindings. After a return of 0, lastInsertId() is meaningless: it reports an
     * older value or 0.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     *
     * @throws Exception\QueryException When $data or the condition is empty, a binding is a RawExpression, or the query fails
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertWhen(string $table, array $data, string $condition, array $bindings = []): int;

    /**
     * Insert a row unless it collides with an existing one: on a duplicate of ANY unique key or
     * of the primary key of the table the row is not inserted, and no exception is thrown.
     * `INSERT ... ON DUPLICATE KEY UPDATE <first column> = <first column>` (not INSERT IGNORE,
     * which would also swallow other errors); that form locks the existing row until the
     * transaction ends and runs the table's update triggers for it. On a connection opened with
     * the driver's ATTR_FOUND_ROWS option the method throws: the server reports 1 affected row for
     * an existing row as well. Every other failure (NOT NULL, foreign key, unknown column) throws
     * as in insert().
     *
     * Returns the inserted rows, 1 or 0, not an id: after a return of 0, lastInsertId() is
     * meaningless, and the skipped insert may still have used up an auto-increment value. A
     * RawExpression in $data is inlined as in insert().
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs of the row
     *
     * @throws Exception\QueryException When $data is empty, the query fails for another reason than a duplicate, or the connection counts matched rows (ATTR_FOUND_ROWS)
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertIgnore(string $table, array $data): int;

    /**
     * Update multiple rows by their key column.
     *
     * Without an active transaction, the rows are updated in an own transaction
     * with the same outcomes as transaction().
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows Array of rows with key column
     * @param string $keyColumn Column to match rows (default: 'id')
     *
     * @throws Exception\QueryException
     * @throws Exception\TransactionException When the own transaction's commit failed, or a listener ended the own transaction while the batch ran (a CommitFailedException with outcome 'lost'; what is open then is left alone)
     * @throws Exception\CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     *
     * @return int Number of affected rows
     */
    public function updateMultiple(string $table, array $rows, string $keyColumn = 'id'): int;
}
