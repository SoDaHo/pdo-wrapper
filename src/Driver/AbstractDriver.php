<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use Closure;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Traits\HasHooks;
use Stringable;
use Throwable;

/**
 * Abstract base driver implementing common database operations.
 *
 * Provides PDO wrapper functionality, CRUD helpers, transactions,
 * and hooks. Extend this class for database-specific drivers.
 */
abstract class AbstractDriver implements DatabaseInterface
{
    use HasHooks;

    protected PDO $pdo;

    /** True while a transaction begun through this driver still owes its 'transaction.end' event */
    private bool $transactionBegun = false;

    /**
     * Counts the transactions begun through beginTransaction() of this class: the number of the
     * latest one. transaction() and updateMultiple() keep the number of the transaction they
     * began and end only that one (see stillTheTransaction()).
     */
    private int $transactionsBegun = 0;

    /** While rollbackQuietly() runs rollback(): the exception that ended the transaction (the end event's error) */
    private ?Throwable $automaticRollbackCause = null;

    /** True after 'transaction.end' reported (or buffered) 'lost' for a transaction that may still be open: its later commit()/rollback() tells no second end */
    private bool $lostReported = false;

    /** Counts the rollbacks rollback() sent successfully: lets rollbackQuietly() tell a listener's exception from a failed rollback */
    private int $rollbacksSent = 0;

    /**
     * Set by queryThen(): the step for exactly that statement - its SQL, at the hook depth
     * queryThen() was called at - run once it was executed, before its 'query' hook.
     *
     * @var array{string, int, Closure}|null
     */
    private ?array $afterExecute = null;

    /** How many 'query'/'error' listeners of query() are running right now, one inside the other */
    private int $hookDepth = 0;

    /** The statement failure inside the open transaction that may have ended it on the server (see failureToRemember()); commit() asks before it commits */
    private ?PDOException $suspectFailure = null;

    /** The failed or refused commit with which commit() has already told a vanished transaction's end as 'lost': commitOwnTransaction() has nothing left to end then */
    private ?CommitFailedException $failedCommitThatToldTheEnd = null;

    /**
     * The failure the latest commit() of this class has thrown. commitOwnTransaction() tells its
     * own failed commit by it - not by the class: an overriding commit(), a driver hook or an error
     * handler may throw a CommitFailedException that belongs to another transaction.
     */
    private ?CommitFailedException $thrownByCommit = null;

    /**
     * While commitOwnTransaction() ends the transaction whose commit just failed: that failure.
     * rollback() or endLostTransaction() takes it away before any listener runs, and writes the
     * outcome it tells into it (rollback() tells none after a 'lost' reported earlier, and then
     * writes none): written once. It is void as soon as a commit goes through commit() of this
     * class - then the transaction it speaks of is committed after all (an overriding rollback()
     * may do such a thing; one that commits on raw PDO and begins again on raw PDO is not seen).
     * Set nowhere else, so an exception a callback or a listener throws is never written to,
     * whatever it is.
     */
    private ?CommitFailedException $settlingCommit = null;

    /**
     * The configured port as a number, or a ConnectionException: the DSN is built with %d, which
     * would turn "abc" into port 0 and "3306;host=other" into 3306 without a word.
     *
     * @throws ConnectionException When the port is not a whole number between 1 and 65535
     */
    protected static function validPort(mixed $port): int
    {
        if (is_string($port) && preg_match('/^[0-9]+$/', $port) === 1) {
            $port = (int) $port;
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'Invalid config value "port": expected a whole number between 1 and 65535'
            );
        }

        return $port;
    }

    // =========================================================================
    // Query Execution
    // =========================================================================

    /**
     * Execute a SQL query and return the statement.
     *
     * Triggers 'query' hook on success, 'error' hook on failure. A failure that PDO reports by
     * returning false (non-exception error mode) counts as a failure. A PDOException thrown by a
     * 'query' hook is not a failed query: the statement ran, no 'error' hook fires, and it arrives
     * as QueryException with the message 'Query hook failed'; other hook exceptions pass unchanged.
     *
     * A parameter must be null, a scalar or a Stringable object. An array, a resource or any other
     * object is a failure before the statement is sent (PDO would bind an array as the text "Array"
     * and a resource as "Resource id #n"): pass an enum's value, a formatted date, an encoded array.
     * So is a RawExpression: bound, it would arrive as its own text; write it into the SQL.
     *
     * After a failure that ended the open transaction on the server for certain (a MySQL/MariaDB
     * deadlock, see transactionIsOver()) nothing is sent until that transaction is ended here -
     * by rollback(), or by a refused commit() that tells 'lost'; for a transaction begun on raw PDO
     * also once PDO reports none: the statement throws, with that failure as previous, and fires
     * no hook. A ROLLBACK sent as a statement is refused like any other: call rollback().
     *
     * @param string $sql SQL query with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws QueryException On query failure (a UniqueViolationException for a duplicate key), on a parameter that cannot be bound, or when a 'query' hook threw a PDOException
     *
     * @return PDOStatement Executed statement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        // Only for the statement it was set for: not for one an overriding query() sends ahead of it
        // (a session setting: other SQL), and not for one a listener runs (deeper in the hooks), even
        // when that listener repeats the very same SQL. queryThen() takes it away again.
        $afterExecute = null;
        if ($this->afterExecute !== null && $this->afterExecute[0] === $sql && $this->afterExecute[1] === $this->hookDepth) {
            $afterExecute = $this->afterExecute[2];
        }

        // The server has thrown the open transaction away (a MySQL/MariaDB deadlock): what would be sent
        // now would run outside of it and be committed on its own. PostgreSQL refuses by itself.
        if ($this->suspectFailure !== null && $this->deadTransactionPending()) {
            throw new QueryException(
                message: 'Query failed',
                previous: $this->suspectFailure,
                debugMessage: sprintf(
                    'Not sent: the server rolled the open transaction back when an earlier statement failed (the previous exception). '
                    . 'This statement would run outside of it. Call rollback(), then run the whole transaction again. | SQL: %s',
                    $sql
                )
            );
        }

        $unbindable = $this->unbindableParameter($params);
        if ($unbindable !== null) {
            $this->triggerFromQuery('error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $unbindable,
                'code' => 0,
            ]);

            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s | SQL: %s', $unbindable, $sql)
            );
        }

        $start = microtime(true);
        $stmt = false;

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw $this->silentFailure('PDO::prepare() returned false', $this->pdo->errorInfo());
            }
            if ($this->bindAndExecute($stmt, $params) === false) {
                throw $this->silentFailure('PDOStatement::execute() returned false', $stmt->errorInfo());
            }
        } catch (Throwable $e) {
            // Not PDO's own exception: PDO::ERRMODE_WARNING with an error handler that throws. PDO reported the
            // failure as a warning, and the handler's exception got here before the false result could be seen
            $failure = $this->failureBehind($e, $stmt instanceof PDOStatement ? $stmt->errorInfo() : $this->pdo->errorInfo());
            if ($failure === null) {
                throw $e; // not a failure PDO knows about
            }

            throw $this->failedQuery($sql, $params, $failure);
        }

        // What must be read before a hook can run further statements on this connection (insert()'s
        // new id). Its failure waits: the statement ran, so the 'query' hook fires first.
        $afterExecuteFailure = null;
        if ($afterExecute !== null) {
            try {
                $afterExecute();
            } catch (Throwable $e) {
                $afterExecuteFailure = $e;
            }
        }

        $rows = $stmt->rowCount();

        // The statement ran: a hook failure must not look like a failed query, and 'error' must not fire.
        try {
            $this->triggerFromQuery('query', [
                'sql' => $sql,
                'params' => $params,
                'duration' => microtime(true) - $start,
                'rows' => $rows,
            ]);
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Query hook failed',
                previous: $e,
                debugMessage: sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, $this->encodeParams($params))
            );
        }

        if ($afterExecuteFailure !== null) {
            throw $afterExecuteFailure;
        }

        return $stmt;
    }

    /**
     * What every failed statement goes through: remembered for commit() when it may have ended the
     * transaction, told to the 'error' hook, and wrapped.
     *
     * @param array<int|string, mixed> $params
     */
    private function failedQuery(string $sql, array $params, PDOException $e): QueryException
    {
        $this->noteStatementFailure($e);
        $this->triggerFromQuery('error', [
            'sql' => $sql,
            'params' => $params,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);

        $debugMessage = sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, $this->encodeParams($params));

        if ($this->isUniqueViolation($e)) {
            return new UniqueViolationException(
                message: 'Query failed',
                previous: $e,
                debugMessage: $debugMessage,
                constraint: $this->violatedConstraint($e)
            );
        }

        return new QueryException(
            message: 'Query failed',
            previous: $e,
            debugMessage: $debugMessage
        );
    }

    /**
     * Whether the failure is a violated UNIQUE constraint or primary key (a duplicate row); the
     * statement then fails with a UniqueViolationException. The drivers of this library know
     * their database's code for it; the default knows none.
     */
    protected function isUniqueViolation(PDOException $failure): bool
    {
        return false;
    }

    /**
     * The name of the key or constraint a unique violation names, or null (see UniqueViolationException).
     */
    protected function violatedConstraint(PDOException $failure): ?string
    {
        return null;
    }

    /**
     * The driver's own message of a failure: errorInfo[2] where PDO filled it in, else the exception's message.
     */
    protected static function driverMessage(PDOException $failure): string
    {
        $message = $failure->errorInfo[2] ?? null;

        return is_string($message) ? $message : $failure->getMessage();
    }

    /**
     * Trigger a 'query' or 'error' hook of query() and keep count of the nesting: what a listener
     * runs is one level deeper than the statement it was told about.
     *
     * @param array<string, mixed> $data
     */
    private function triggerFromQuery(string $event, array $data): void
    {
        $this->hookDepth++;

        try {
            $this->trigger($event, $data);
        } finally {
            $this->hookDepth--;
        }
    }

    /**
     * Run query() with a step between the execution and the 'query' hook. insert() reads the new
     * id there: a 'query' listener that inserts on the same connection (an audit row) would
     * otherwise replace it before insert() reads it. An exception of the step is thrown after the
     * 'query' hook ran. Goes through query(), so a driver that overrides query() and calls its
     * parent keeps seeing every statement. The step belongs to this statement: it runs when this
     * class's query() is reached with the same SQL outside of any listener this call triggers - not
     * for a statement an override sends first, not for one a listener runs. An override that changes
     * the SQL or never calls its parent leaves it unrun.
     *
     * @param array<int|string, mixed> $params
     * @param Closure(): void $afterExecute
     *
     * @throws QueryException As query()
     */
    protected function queryThen(string $sql, array $params, Closure $afterExecute): PDOStatement
    {
        // Restored afterwards: a queryThen() nested in a hook of a statement sent ahead must not drop the outer step
        $outer = $this->afterExecute;
        $this->afterExecute = [$sql, $this->hookDepth, $afterExecute];

        try {
            return $this->query($sql, $params);
        } finally {
            $this->afterExecute = $outer;
        }
    }

    /**
     * Remember a statement failure inside a transaction that, by the driver's word, may have ended
     * the transaction on the server while PDO keeps reporting it. For a driver's own statements
     * that do not go through query().
     */
    protected function noteStatementFailure(PDOException $e): void
    {
        $remembered = $this->failureToRemember($this->suspectFailure, $e);
        if ($remembered === $this->suspectFailure) {
            return;
        }

        // Inside a transaction this library began and has not ended, whatever PDO reports by now;
        // otherwise (a transaction begun on raw PDO) when PDO reports one
        $inTransaction = $this->transactionBegun;
        if (!$inTransaction) {
            try {
                $inTransaction = $this->pdo->inTransaction();
            } catch (Throwable) {
                $inTransaction = true; // unreadable, fail-closed: commit() asks the driver
            }
        }

        if ($inTransaction) {
            $this->suspectFailure = $remembered;
        }
    }

    /**
     * Which statement failure commit() shall ask transactionEndedBy() about: one that may have
     * ended the open transaction on the server although PDO still reports it. PostgreSQL aborts
     * the transaction on every error, MySQL/MariaDB roll it back on a deadlock (and on a lock wait
     * timeout when configured so); the server would then answer COMMIT with success for a
     * transaction that no longer holds the work.
     *
     * @param PDOException|null $remembered What is remembered so far in this transaction
     * @param PDOException $failure The failure that just happened
     *
     * @return PDOException|null $failure to remember it, $remembered to keep things as they are
     *                           (the default: no statement failure ends a transaction)
     */
    protected function failureToRemember(?PDOException $remembered, PDOException $failure): ?PDOException
    {
        return $remembered;
    }

    /**
     * Whether a transaction that the remembered failure ended on the server for certain
     * (transactionIsOver()) is still waiting to be ended here. One this library began is, until
     * its end is told (rollback(), or the refused commit): whatever PDO reports meanwhile - a
     * statement on raw PDO may have told it that the transaction is gone - nothing but that end is
     * accepted. One begun on raw PDO is for as long as PDO reports it (an unreadable state counts
     * as yes); once PDO reports none, it was ended there, and the failure is forgotten.
     */
    private function deadTransactionPending(): bool
    {
        if ($this->suspectFailure === null || !$this->transactionIsOver($this->suspectFailure)) {
            return false;
        }
        if ($this->transactionBegun) {
            return true;
        }

        try {
            if ($this->pdo->inTransaction()) {
                return true;
            }
        } catch (Throwable) {
            return true;
        }

        $this->suspectFailure = null;

        return false;
    }

    /**
     * Whether the remembered failure has ended the transaction on the server for certain, known
     * from the failure alone (no statement is sent to find out): a MySQL/MariaDB deadlock. Until
     * that transaction is ended here (see deadTransactionPending()), query() sends nothing more -
     * every statement throws - and beginTransaction() refuses; no hook fires for the refused
     * statement (the failure itself was told to the 'error' hook).
     * False where the server only undid the statement or may have (a lock wait timeout), and where
     * the server refuses further statements by itself (PostgreSQL).
     */
    protected function transactionIsOver(PDOException $failure): bool
    {
        return false;
    }

    /**
     * Called by rollback() before the ROLLBACK is sent, when a statement failed inside the
     * transaction PDO reports - one whose end this driver would tell: begun through it, or begun
     * on raw PDO while no 'lost' is pending - and that failure does not settle the matter by
     * itself (transactionIsOver()): make PDO know whether the transaction still exists, and say whether
     * its report can be relied on now. For a driver whose client learns nothing about the
     * transaction from a failed statement (MySQL/MariaDB: a statement with an implicit commit
     * commits the open transaction even when it fails, and the server's error carries no status)
     * - on raw PDO, so that no hook sees it. rollback() reads PDO afterwards: no transaction any
     * more means 'lost'. False - the driver could not find out - means 'lost' as well: the
     * ROLLBACK is still sent, but it confirms nothing. True by default: PDO knows.
     */
    protected function refreshTransactionState(): bool
    {
        return true;
    }

    /**
     * Asked by commit() about the failure failureToRemember() kept: why the transaction cannot be
     * committed any more, or null when it still can (a savepoint caught the failure, the server
     * only undid the statement). Where the failure itself does not settle it, ask the server.
     */
    protected function transactionEndedBy(PDOException $failure): ?string
    {
        return null;
    }

    /**
     * Describe the first parameter that must not be bound, or null when all can be: anything but
     * null, a scalar or a Stringable object, and a RawExpression (bound, it would arrive as the
     * text of the expression). A driver whose bindAndExecute() binds more (a stream as LOB)
     * overrides this along with it.
     *
     * @param array<int|string, mixed> $params
     */
    protected function unbindableParameter(array $params): ?string
    {
        foreach ($params as $key => $value) {
            $position = is_int($key) ? '#' . ($key + 1) : '"' . $key . '"';

            if ($value instanceof RawExpression) {
                return sprintf('Cannot bind a raw expression (parameter %s): write it into the SQL instead', $position);
            }
            if ($value === null || is_scalar($value) || $value instanceof Stringable) {
                continue;
            }

            return sprintf(
                'Cannot bind a value of type %s (parameter %s): only null, scalars and Stringable objects can be bound',
                get_debug_type($value),
                $position
            );
        }

        return null;
    }

    /**
     * Parameters as JSON for a debug message. Never fails: bytes that are not UTF-8 (binary values)
     * are substituted instead of turning the whole list into "false".
     *
     * @param array<int|string, mixed> $params
     */
    private function encodeParams(array $params): string
    {
        return (string) json_encode($params, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Bind the parameters and execute the prepared statement: PDOStatement::execute($params), every
     * value bound as text, a boolean as '1' or '0'. PDO alone sends false as '', which MySQL in strict
     * mode and PostgreSQL reject for a numeric or boolean column. Text rather than a typed binding:
     * PARAM_INT makes MySQL compare a text column numerically ('abc' = 0 is true), and PARAM_BOOL
     * reaches PostgreSQL as 't'/'f', which an integer column rejects (measured on MySQL 8.0,
     * MariaDB 11.4 and PostgreSQL 15). A driver overrides this when its database needs typed bindings;
     * what query() lets through to it is decided by unbindableParameter().
     *
     * @param array<int|string, mixed> $params Positional (0-based) or named parameters
     *
     * @return bool False when PDO reports the failure without an exception
     */
    protected function bindAndExecute(PDOStatement $stmt, array $params): bool
    {
        // A new array, not an in-place rewrite: a reference inside $params would otherwise be written through
        $bound = [];
        foreach ($params as $key => $value) {
            $bound[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return $stmt->execute($bound);
    }

    /**
     * The exception for a statement that PDO reported as failed by returning false (non-exception
     * error mode): carries the driver's error code and the full errorInfo, like a thrown PDOException.
     *
     * @param array<int, mixed> $errorInfo PDO::errorInfo() or PDOStatement::errorInfo()
     */
    private function silentFailure(string $what, array $errorInfo, ?Throwable $previous = null): PDOException
    {
        $reason = is_string($errorInfo[2] ?? null) ? $errorInfo[2] : 'unknown error';
        $state = is_string($errorInfo[0] ?? null) ? $errorInfo[0] : '';
        $driverCode = is_int($errorInfo[1] ?? null) ? $errorInfo[1] : 0;

        $e = new PDOException(sprintf('%s: %s (SQLSTATE %s)', $what, $reason, $state), $driverCode, $previous);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /**
     * The database failure an exception out of a PDO operation stands for, or null when it is not
     * one: PDO's own PDOException (it carries errorInfo) as it is; an error handler's exception
     * for a PDO warning - of any class, a PDOException of its own making included - as the failure
     * PDO recorded (see warnedFailure()); any other PDOException as it is.
     *
     * @param array<int, mixed> $errorInfo errorInfo() of the handle the operation ran on
     */
    protected function failureBehind(Throwable $thrown, array $errorInfo): ?PDOException
    {
        if ($thrown instanceof PDOException && $thrown->errorInfo !== null) {
            return $thrown;
        }

        return $this->warnedFailure($thrown, $errorInfo) ?? ($thrown instanceof PDOException ? $thrown : null);
    }

    /**
     * The PDO failure behind an exception that an error handler threw for a PDO warning
     * (PDO::ERRMODE_WARNING), or null when the errorInfo of the operation that just ran shows no
     * failure: then the exception is someone else's and passes unchanged. PDO clears the errorInfo
     * at the start of every operation, so an earlier failure is never mistaken for this one.
     *
     * @param array<int, mixed> $errorInfo errorInfo() of the handle the operation ran on
     */
    private function warnedFailure(Throwable $thrown, array $errorInfo): ?PDOException
    {
        // Only PDO::ERRMODE_WARNING raises warnings: in every other mode the exception is not PDO's doing
        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_WARNING) {
            return null;
        }

        $state = $errorInfo[0] ?? null;
        if (!is_string($state) || $state === '' || $state === '00000') {
            return null;
        }

        return $this->silentFailure('PDO reported a warning', $errorInfo, $thrown);
    }

    /**
     * Execute a SQL statement and return affected rows.
     *
     * @param string $sql SQL statement with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws QueryException On query failure
     *
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Get the last inserted ID.
     *
     * @param string|null $name Sequence name (PostgreSQL) or null
     *
     * @throws QueryException If PDO fails to retrieve the ID
     *
     * @return string|false Last insert ID or false on failure
     */
    public function lastInsertId(?string $name = null): string|false
    {
        try {
            $id = $this->pdo->lastInsertId($name);
            if ($id === false) {
                // Non-exception error mode: a failed sequence lookup aborts a PostgreSQL transaction all the same
                $this->noteStatementFailure($this->silentFailure('PDO::lastInsertId() returned false', $this->pdo->errorInfo()));
            }

            return $id;
        } catch (Throwable $e) {
            // Not PDO's own: an error handler's exception for a PDO warning (PDO::ERRMODE_WARNING), or someone else's
            $failure = $this->failureBehind($e, $this->pdo->errorInfo());
            if ($failure === null) {
                throw $e;
            }
            $this->noteStatementFailure($failure);

            throw new QueryException(
                message: 'Failed to get last insert ID',
                previous: $failure,
                debugMessage: $failure->getMessage()
            );
        }
    }

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Whether the connection is inside a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    // =========================================================================
    // Transactions
    // =========================================================================

    /**
     * Begin a transaction.
     *
     * Triggers 'transaction.begin' hook on success. A throwing hook must not leave the transaction
     * it was told about open: a rollback is attempted on raw PDO (best effort, no 'transaction.rollback'
     * hooks; if it fails, the transaction may still be open) and the hook's exception reaches the
     * caller, a PDOException as TransactionException - unless the hook ended that transaction
     * itself: one it began afterwards is left open, with its end owed. A hook that ends the
     * transaction it was told about makes the call fail as well, and no further hook runs: the
     * caller would go on outside of the transaction it asked for. Ended through this driver
     * (commit(), rollback()) its end was told by that call; ended behind the driver's back (an
     * implicit commit by a DDL statement, raw PDO) it is told as 'lost' before the call fails.
     *
     * A transaction begun through this driver that PDO no longer reports is told as 'lost' first;
     * so is one an end listener of that 'lost' begins and loses the same way. A third in a row is
     * not told here: the call throws and begins nothing.
     *
     * @throws TransactionException On failure, including PDO::beginTransaction() returning false (non-exception error mode), when a transaction.begin listener ended the transaction, and when transaction.end listeners keep leaving behind a transaction that ended outside this driver
     */
    public function beginTransaction(): void
    {
        // Asked again after an end was told below: its listeners may have begun a transaction
        // through this driver that owes its end the same way, and the one begun here must not bury it
        for ($endsTold = 0; ; $endsTold++) {
            // A transaction this library began is dead on the server and has not been ended here: a new one
            // would bury it (its end would never be told) and let the caller's remaining work run in a fresh one
            if ($this->suspectFailure !== null && $this->transactionBegun && $this->deadTransactionPending()) {
                throw new TransactionException(
                    message: 'Failed to begin transaction',
                    previous: $this->suspectFailure,
                    debugMessage: 'The transaction this library began was rolled back by the server when a statement failed (the previous exception) and has not been ended yet. Call rollback() first.'
                );
            }

            // A transaction this library began still owes its end, and PDO no longer reports it: it was ended
            // behind this library's back (an implicit commit by a DDL statement, a lock wait timeout that ended
            // it, raw PDO). Its end is told now, as 'lost', before the next transaction takes its place.
            if (!$this->transactionBegun || !$this->reportsNoTransaction()) {
                break;
            }
            if ($endsTold === 2) {
                // Listeners that answer every such end with another transaction of that kind: no loop.
                // The latest one keeps its mark, the next beginTransaction() tells its end.
                throw new TransactionException(
                    message: 'Failed to begin transaction',
                    debugMessage: 'Not begun: the transaction.end listeners keep leaving behind a transaction that ended outside this library (twice in a row while this one was to begin).'
                );
            }
            $this->endLostTransaction(
                new TransactionException(
                    message: 'Transaction ended outside this library',
                    previous: $this->suspectFailure,
                    debugMessage: 'PDO reported no transaction any more when the next one was begun: it was committed or rolled back by the server or on raw PDO.'
                ),
                mayStillBeOpen: false
            );
        }

        try {
            $begun = $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($begun === false) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                debugMessage: 'PDO::beginTransaction() returned false'
            );
        }

        $this->transactionBegun = true;
        $number = ++$this->transactionsBegun;
        $this->lostReported = false;
        $this->suspectFailure = null;

        try {
            // One by one: after a listener that ended the transaction no further one runs - it would
            // write outside of any transaction, or into one somebody began afterwards
            $payload = []; // a variable, as trigger() passes one: a listener may take it by reference
            foreach ($this->hooks['transaction.begin'] ?? [] as $listener) {
                $listener($payload);
                $this->failIfNoLongerOpen($number);
            }
        } catch (PDOException $e) {
            $failure = new TransactionException(
                message: 'Failed to begin transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
            $this->rollbackJustBegunQuietly($number, $failure);

            throw $failure;
        } catch (Throwable $e) {
            $this->rollbackJustBegunQuietly($number, $e);
            throw $e;
        }
    }

    /**
     * After a 'transaction.begin' listener: the caller is about to work in the transaction it
     * asked for. A listener that ended it would leave that work outside of any transaction, or
     * inside one somebody began afterwards - the call fails instead. Ended through this driver
     * (commit(), rollback()), its end has been told; ended behind the driver's back (an implicit
     * commit by a DDL statement on MySQL/MariaDB, raw PDO), it is told as 'lost' by the caller's
     * catch, with the exception thrown here. An unreadable state is not "gone".
     *
     * @throws TransactionException When the transaction with that number is no longer open
     */
    private function failIfNoLongerOpen(int $number): void
    {
        if (!$this->stillTheTransaction($number)) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                debugMessage: 'A transaction.begin listener ended the transaction that was just begun through this driver (a commit() or rollback(), or an end told as lost when it began another); its end was told then. A transaction that is open now was begun afterwards and is left to whoever began it.'
            );
        }

        if ($this->reportsNoTransaction()) {
            // beginTransaction() tells that end as 'lost' with this exception: the same way as for a
            // listener that threw by itself after it ended the transaction (rollbackJustBegunQuietly())
            throw new TransactionException(
                message: 'Failed to begin transaction',
                debugMessage: 'A transaction.begin listener ended the transaction that was just begun outside this driver: PDO reports no transaction any more (an implicit commit by a DDL statement, or raw PDO). What the listener wrote before may be committed.'
            );
        }
    }

    /**
     * After a throwing 'transaction.begin' listener: undo the transaction that was just begun -
     * unless a listener ended it itself. Ended through this driver, its end has been told, and
     * what is open then was begun afterwards and is not this call's to roll back. Ended behind
     * the driver's back before the listener threw (PDO reports no transaction: an implicit commit
     * by a DDL statement, raw PDO), nothing is left to roll back and what the listener wrote may
     * be committed: that end is told as 'lost', with the exception the caller gets as error.
     */
    private function rollbackJustBegunQuietly(int $number, Throwable $cause): void
    {
        if (!$this->stillTheTransaction($number)) {
            return;
        }
        if ($this->reportsNoTransaction()) {
            $this->endLostTransaction($cause, mayStillBeOpen: false);

            return;
        }
        $this->rollbackRawQuietly();
    }

    /**
     * Roll back on raw PDO if a transaction is open, without 'transaction.rollback' or 'transaction.end'
     * hooks and ignoring failures: the exception that caused this is more important for debugging.
     */
    private function rollbackRawQuietly(): void
    {
        // Ended here, without an event: a later cleanup must not report it as lost
        $this->transactionBegun = false;
        $this->suspectFailure = null;

        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // Rollback failed, but the original exception is more important for debugging
        }
    }

    /**
     * Commit the current transaction.
     *
     * Triggers 'transaction.commit' listeners after a successful commit. They cannot undo
     * the commit, so every listener runs and their failures are reported together - unless
     * a transaction left open by a listener cannot be rolled back (or the connection state
     * cannot be read): the remaining listeners are then skipped and reported as failures.
     * Then the ends of the transactions commit listeners began through this driver and left open are
     * dispatched, then 'transaction.end' fires with outcome 'committed' (also after skipped listeners;
     * not when a 'lost' was already reported for this transaction); the end listeners' failures follow
     * the commit listeners' in the same exception, in that order. A failed commit fires no
     * 'transaction.end': the transaction is still the caller's to end (one exception: a failed or
     * refused commit of a transaction PDO no longer reports, see below).
     *
     * Before COMMIT is sent, the driver is asked about the statement failure of the transaction
     * that may have ended it on the server (failureToRemember(), transactionEndedBy()): the commit
     * is then refused, and stays refused for as long as the driver says so. A transaction begun
     * through this driver that PDO no longer reports after the failed or refused commit is told
     * as 'lost' right there (endFailedCommitIfGone()): nothing could end it afterwards. So is one
     * begun on raw PDO that was open when the COMMIT was sent and is gone after it failed. The
     * CommitFailedException carries that outcome; otherwise its outcome is null - after a commit()
     * the caller issued itself the transaction is the caller's to end, and nothing writes into
     * the exception later (commitOwnTransaction() does, for the commit it runs). After the COMMIT, a
     * transaction PDO reports at once is a chained one (see chainedTransaction()): every commit
     * listener is skipped and it is the first failure of the CommitHookException.
     *
     * @throws CommitFailedException When the commit itself failed (it may or may not have taken effect), or was refused because the server had already ended the transaction (nothing of that transaction is committed)
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed, the connection state after a commit listener could not be verified, or the connection is in a new, chained transaction
     */
    public function commit(): void
    {
        $this->failedCommitThatToldTheEnd = null;
        $this->thrownByCommit = null;
        $this->deadTransactionPending(); // forgets the failure of a transaction begun on raw PDO that PDO no longer reports

        if ($this->suspectFailure !== null) {
            $reason = $this->transactionEndedBy($this->suspectFailure);
            if ($reason !== null) {
                // The failure is kept: a second commit() must be refused as well; rollback() and beginTransaction() clear it
                $refusal = new CommitFailedException(
                    message: 'Failed to commit transaction',
                    previous: $this->suspectFailure,
                    debugMessage: $reason
                );
                $this->endFailedCommitIfGone($refusal, wasOpen: false);
                $this->thrownByCommit = $refusal; // after the listeners, like the mark above

                throw $refusal;
            }
            $this->suspectFailure = null;
        }

        // For a transaction begun on raw PDO: was one open when the COMMIT was sent? An unreadable state is no "yes"
        try {
            $wasOpen = $this->pdo->inTransaction();
        } catch (Throwable) {
            $wasOpen = false;
        }

        try {
            $committed = $this->pdo->commit();
        } catch (PDOException $e) {
            $failure = new CommitFailedException(
                message: 'Failed to commit transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
            $this->endFailedCommitIfGone($failure, $wasOpen);
            $this->thrownByCommit = $failure;

            throw $failure;
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($committed === false) {
            $failure = new CommitFailedException(
                message: 'Failed to commit transaction',
                debugMessage: 'PDO::commit() returned false'
            );
            $this->endFailedCommitIfGone($failure, $wasOpen);
            $this->thrownByCommit = $failure;

            throw $failure;
        }

        // Committed from here on: a listener error must not look like a failed commit.
        $this->settlingCommit = null; // void: an earlier failed commit of this transaction has been overtaken
        $this->transactionBegun = false;
        $endOwed = !$this->lostReported; // after a reported 'lost' this transaction's end has already been told
        $this->lostReported = false;
        [$failures, $connectionInTransaction, $innerEnds] = $this->runCommitListeners($this->chainedTransaction('COMMIT'));
        $failures = [...$failures, ...$this->dispatchInnerEnds($innerEnds)];
        if ($endOwed) {
            $failures = [...$failures, ...$this->dispatchTransactionEnd(self::TRANSACTION_COMMITTED, null)];
        }

        if ($failures !== []) {
            throw new CommitHookException($failures[0], $failures, $connectionInTransaction);
        }
    }

    /**
     * A failed or refused commit leaves the transaction to the caller - unless nothing is left:
     * when PDO reports no transaction any more (the server ended it and a later statement or the
     * driver's question told PDO; a COMMIT the server answered with a rollback, as PostgreSQL does
     * for a deferred constraint; a COMMIT or DDL statement on raw PDO), no rollback() could end it,
     * and its end would never be told. It ends here as 'lost', with the failure as error;
     * transaction() then finds nothing left to end.
     */
    private function endFailedCommitIfGone(CommitFailedException $failure, bool $wasOpen): void
    {
        // An end is owed for a transaction begun through this driver, and for one begun on raw PDO that
        // was open when the COMMIT was sent (its successful commit would have told an end too) - not while
        // a 'lost' told for a transaction that may still be open is pending: a successful commit tells none then either
        $owed = $this->transactionBegun || ($wasOpen && !$this->lostReported);

        // An unreadable state is not "gone": it may still be open, the caller's rollback decides
        if ($owed && $this->reportsNoTransaction()) {
            $failure->outcome = self::TRANSACTION_LOST; // what the end listeners are told below
            $this->endLostTransaction($failure, mayStillBeOpen: false);
            $this->failedCommitThatToldTheEnd = $failure; // after the listeners: a commit() of theirs resets it
        }
    }

    /**
     * Run every 'transaction.end' listener with the outcome and collect their failures in listener
     * order; nothing here throws. The mark of the ended transaction is cleared by the caller before
     * any listener runs, so a transaction a listener begins keeps its own mark.
     *
     * @return list<Throwable>
     */
    private function dispatchTransactionEnd(string $outcome, ?Throwable $error): array
    {
        $failures = [];

        foreach ($this->hooks['transaction.end'] ?? [] as $listener) {
            try {
                $listener(['outcome' => $outcome, 'error' => $error]);
            } catch (Throwable $e) {
                $failures[] = $e;
            }
        }

        return $failures;
    }

    /**
     * Hand 'transaction.end' listener failures to the 'error' hook (existing keys, plus hook, outcome
     * and exception), for the paths on which another exception reaches the caller. A throwing
     * 'error' listener is ignored: the exception that ended the transaction is more important.
     *
     * @param list<Throwable> $failures
     */
    private function reportTransactionEndFailures(string $outcome, array $failures): void
    {
        foreach ($failures as $e) {
            $this->reportQuietly($e, ['hook' => 'transaction.end', 'outcome' => $outcome]);
        }
    }

    /**
     * Hand an exception that will not reach the caller to the 'error' hook: the existing keys (sql
     * '', params []), then $context, then the exception itself. A throwing 'error' listener is
     * ignored: the exception that ended the transaction is more important.
     *
     * @param array<string, string> $context
     */
    private function reportQuietly(Throwable $e, array $context): void
    {
        try {
            $this->trigger('error', [
                'sql' => '',
                'params' => [],
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                ...$context,
                'exception' => $e,
            ]);
        } catch (Throwable) {
            // see above
        }
    }

    /**
     * Run every transaction.commit listener and collect the failures in listener order.
     *
     * Listeners are independent: a failing listener does not stop the next one. A transaction
     * a listener left open is rolled back before the next listener runs (best effort) - directly
     * on PDO, because dispatching transaction.rollback here would tell rollback listeners that
     * the committed transaction was rolled back. If that rollback fails or leaves the connection
     * in a transaction (MySQL completion_type=CHAIN opens the next one), or inTransaction() itself
     * fails, the connection state is unknown: the remaining listeners are skipped and the
     * transaction may still be open. A left-open transaction begun through this driver gets its
     * own 'transaction.end' ('rolled_back', or 'lost' when the cleanup failed or the state could not
     * be read), buffered for after this loop. Nothing here throws: the commit has happened.
     *
     * Per listener: its own exception, then a LogicException if it left a transaction open
     * (previous: the rollback error, if any) or if the state could not be read (previous: that
     * error), then one LogicException per skipped listener.
     *
     * The second element is true when the connection is, or may still be, in a transaction
     * afterwards: the rollback failed or did not end the transaction, or the state could not be
     * read (fail-closed).
     *
     * The third element lists the transactions listeners began through this driver and left open
     * (rolled back raw here), for their own 'transaction.end' after the loop.
     *
     * @param TransactionException|null $chained Set when the connection was in a new transaction right after the COMMIT (see chainedTransaction())
     *
     * @return array{list<Throwable>, bool, list<array{string, Throwable}>}
     */
    private function runCommitListeners(?TransactionException $chained = null): array
    {
        // A chained transaction is open before any listener ran: reported first, every listener skipped
        $failures = $chained !== null ? [$chained] : [];
        $innerEnds = [];
        $cleanupError = $chained;

        foreach ($this->hooks['transaction.commit'] ?? [] as $listener) {
            if ($cleanupError !== null) {
                $failures[] = new LogicException('listener skipped: connection left in transaction', previous: $cleanupError);
                continue;
            }

            try {
                $listener([]);
            } catch (Throwable $e) {
                $failures[] = $e;
            }

            try {
                $open = $this->pdo->inTransaction();
            } catch (Throwable $e) {
                $cleanupError = $e;
                $stateUnknown = new LogicException('connection state unknown after listener', previous: $e);
                $failures[] = $stateUnknown;
                if ($this->transactionBegun) {
                    // A transaction the listener began through this driver: its end cannot be confirmed either
                    $this->transactionBegun = false;
                    $this->lostReported = true;
                    $innerEnds[] = [self::TRANSACTION_LOST, $stateUnknown];
                }
                continue;
            }

            if (!$open) {
                // PDO confirms no transaction: whatever the listener began through this driver and ended raw
                // or implicitly (a DDL statement) is gone, and so is what its transaction() reported as lost
                $this->transactionBegun = false;
                $this->lostReported = false;
                $this->suspectFailure = null;
                continue;
            }

            // Raw cleanup of what the listener left open, without 'transaction.rollback' hooks
            try {
                if ($this->pdo->rollBack() === false) {
                    $cleanupError = new TransactionException(
                        message: 'Failed to rollback transaction',
                        debugMessage: 'PDO::rollBack() returned false'
                    );
                } elseif ($this->pdo->inTransaction()) {
                    // e.g. MySQL completion_type=CHAIN: the rollback opened the next transaction
                    $cleanupError = new TransactionException(
                        message: 'Failed to rollback transaction',
                        debugMessage: 'connection still in a transaction after PDO::rollBack()'
                    );
                }
            } catch (Throwable $e) {
                $cleanupError = $e;
            }

            $leftOpen = new LogicException('listener left a transaction open', previous: $cleanupError);
            $failures[] = $leftOpen;
            if ($cleanupError === null) {
                $this->lostReported = false; // the connection is clean again: whatever a listener's transaction() reported as lost is gone
                $this->suspectFailure = null;
            }

            // A transaction the listener began through this driver gets its own end, before the outer one -
            // dispatched after this loop, so that its end listeners run outside the commit listeners' cleanup.
            // The state marks are set now: a lost transaction may still be open, and whoever ends it before
            // the buffered end is delivered must not tell a second one.
            if ($this->transactionBegun) {
                $this->transactionBegun = false;
                if ($cleanupError === null) {
                    $innerEnds[] = [self::TRANSACTION_ROLLED_BACK, $leftOpen];
                } else {
                    $this->lostReported = true;
                    $innerEnds[] = [self::TRANSACTION_LOST, $leftOpen];
                }
            }
        }

        return [$failures, $cleanupError !== null, $innerEnds];
    }

    /**
     * The failure to report when PDO says the connection is in a transaction right after a COMMIT
     * or ROLLBACK went through: the session chains transactions (MySQL/MariaDB
     * completion_type=CHAIN), so everything that follows would run in a transaction nobody began
     * and nobody commits. Null when PDO reports none; an unreadable state is not judged here.
     */
    private function chainedTransaction(string $statement): ?TransactionException
    {
        try {
            if (!$this->pdo->inTransaction()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return new TransactionException(
            message: 'Connection is in a new transaction',
            debugMessage: sprintf(
                'PDO reports a transaction right after %s: the session chains transactions (MySQL/MariaDB completion_type=CHAIN), which this library does not support. Roll the new transaction back and set completion_type to NO_CHAIN.',
                $statement
            )
        );
    }

    /**
     * 'transaction.end' for the transactions commit listeners began and left open (rolled back raw).
     * Delivery only: the state marks were set when the ends were buffered, so nothing a listener did
     * in between (ending a lost transaction, beginning a new one) is overwritten here. The listeners'
     * failures join the CommitHookException the caller gets anyway.
     *
     * @param list<array{string, Throwable}> $innerEnds outcome and error per transaction, in listener order
     *
     * @return list<Throwable>
     */
    private function dispatchInnerEnds(array $innerEnds): array
    {
        $failures = [];
        foreach ($innerEnds as [$outcome, $error]) {
            $failures = [...$failures, ...$this->dispatchTransactionEnd($outcome, $error)];
        }

        return $failures;
    }

    /**
     * Roll back the current transaction.
     *
     * Triggers the 'transaction.rollback' hook on success, then 'transaction.end' with outcome
     * 'rolled_back' (error null) - also when a rollback listener threw; that exception takes
     * precedence and reaches the caller, the end listeners' failures then only the 'error' hook. A
     * failed rollback fires nothing: the transaction is still the caller's to end. After a
     * 'transaction.end' that reported 'lost' for this transaction, no second end is told. A
     * transaction this library began that the server rolled back for certain (transactionIsOver())
     * and that PDO no longer reports is ended without a ROLLBACK and without rollback listeners:
     * 'transaction.end' reports 'lost' with the remembered failure as error, and its listeners'
     * failures reach only the 'error' hook (as on every 'lost'). The same holds after any other
     * failed statement when the driver, asked before the ROLLBACK (refreshTransactionState()),
     * finds the transaction gone: on MySQL/MariaDB a statement with an implicit commit that failed
     * has committed it, and the rows written before it are in the database. When the driver
     * cannot find out, the ROLLBACK is sent all the same, but 'lost' is told and no rollback
     * listener runs: it confirms nothing. An
     * override that does not call this method dispatches no event. A transaction PDO reports right
     * after the ROLLBACK is a chained one (see chainedTransaction()): thrown after the listeners
     * ran, or told to the 'error' hook when a rollback listener threw.
     *
     * @throws TransactionException On failure, when the connection is in a new, chained transaction afterwards, or when a transaction.end listener failed and no rollback listener did (the first failure; all of them reach the 'error' hook)
     * @throws Throwable Re-throws a rollback listener's exception
     */
    public function rollback(): void
    {
        // Set by rollbackQuietly(): this rollback ends a transaction that $cause ended, which reaches the caller instead.
        // Consumed here, so that a rollback() a listener calls for its own transaction is an explicit one.
        $cause = $this->automaticRollbackCause;
        $this->automaticRollbackCause = null;
        $this->deadTransactionPending(); // forgets the failure of a transaction begun on raw PDO that PDO no longer reports

        // The server threw the transaction away and PDO knows it (a statement on raw PDO told it): there
        // is nothing to send. rollback() stays the way out: it tells the end as 'lost' - what ran on raw
        // PDO meanwhile ran outside the transaction - instead of failing for want of a transaction.
        if ($this->suspectFailure !== null && $this->transactionBegun && $this->deadTransactionPending() && $this->reportsNoTransaction()) {
            $this->endLostTransaction($cause ?? $this->suspectFailure, mayStillBeOpen: false);

            return;
        }

        // A statement failed inside the transaction PDO reports, and the server may have ended it
        // without the client knowing: a ROLLBACK that "succeeds" then would be taken for the
        // confirmation that nothing is committed. The driver gets to ask (refreshTransactionState());
        // when PDO reports no transaction afterwards, the end is told as 'lost' - no rollback is
        // confirmed, and on MySQL/MariaDB what a statement with an implicit commit committed on its
        // way to failing is in the database. When PDO reports none already, nothing is asked: the
        // ROLLBACK fails as before.
        $unconfirmed = null;
        if ($this->suspectFailure !== null && $this->reportsATransactionThatOwesItsEnd() && !$this->transactionIsOver($this->suspectFailure)) {
            $failure = $this->suspectFailure;
            $known = $this->refreshTransactionState();
            if ($this->reportsNoTransaction()) {
                $this->endLostTransaction($cause ?? $failure, mayStillBeOpen: false);

                return;
            }
            if (!$known) {
                // The driver could not find out: what PDO reports is as stale as before
                $unconfirmed = $failure;
            }
        }

        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($rolledBack === false) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                debugMessage: 'PDO::rollBack() returned false'
            );
        }

        if ($unconfirmed !== null) {
            // Sent, to clean up whatever was there. Whether the transaction still existed is not known:
            // no rollback is confirmed, no rollback listener runs, and the end is 'lost'. A chained
            // transaction is told as after every other ROLLBACK: thrown, or to the 'error' hook when
            // another exception reaches the caller.
            $chained = $this->chainedTransaction('ROLLBACK'); // read before a listener can begin a transaction of its own
            $this->endLostTransaction($cause ?? $unconfirmed, mayStillBeOpen: false);
            if ($chained !== null && $cause !== null) {
                $this->reportQuietly($chained, ['outcome' => self::TRANSACTION_LOST]);
            } elseif ($chained !== null) {
                throw $chained;
            }

            return;
        }

        // Rolled back from here on. A PDOException from a hook keeps arriving as TransactionException (unchanged contract).
        $this->transactionBegun = false;
        $this->suspectFailure = null;
        $this->rollbacksSent++; // tells rollbackQuietly() that a later exception came from a listener, not from the rollback
        $endOwed = !$this->lostReported; // after a reported 'lost' this transaction's end has already been told
        $this->lostReported = false;
        $chained = $this->chainedTransaction('ROLLBACK'); // read before a listener can begin a transaction of its own
        if ($this->settlingCommit !== null && $cause === $this->settlingCommit) {
            if ($endOwed) {
                $this->settlingCommit->outcome = self::TRANSACTION_ROLLED_BACK; // what the end listeners are told below
            }
            $this->settlingCommit = null; // written once: a listener that throws this exception again inside a transaction of its own cannot have it rewritten
        }
        $pending = null;
        try {
            $this->trigger('transaction.rollback', []);
        } catch (PDOException $e) {
            $pending = new TransactionException(
                message: 'Failed to rollback transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            $pending = $e;
        }
        // A listener's exception takes precedence; without one the chained transaction is what the caller must
        // know. Whenever it does not reach the caller, the 'error' hook is told.
        $listenerFailed = $pending !== null;
        $pending ??= $chained;

        $failures = $endOwed ? $this->dispatchTransactionEnd(self::TRANSACTION_ROLLED_BACK, $cause) : [];
        if ($cause !== null) {
            // Automatic rollback: end listener failures only reach the 'error' hook; a rollback listener's
            // exception is re-thrown as before (rollbackQuietly() swallows it, an override sees it)
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            if ($chained !== null) {
                // $cause reaches the caller, so the 'error' hook is where the chained transaction is told
                $this->reportQuietly($chained, ['outcome' => self::TRANSACTION_ROLLED_BACK]);
            }
            if ($pending !== null) {
                throw $pending;
            }

            return;
        }
        if ($pending !== null) {
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            if ($chained !== null && $listenerFailed) {
                $this->reportQuietly($chained, ['outcome' => self::TRANSACTION_ROLLED_BACK]);
            }
            throw $pending;
        }
        if ($failures !== []) {
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            throw new TransactionException(
                message: 'Transaction rolled back, but a transaction.end listener failed',
                previous: $failures[0],
                debugMessage: $failures[0]->getMessage()
            );
        }
    }

    /**
     * Whether PDO reports a transaction whose end this driver would tell: one begun through this
     * driver, or one begun on raw PDO (its rollback() or commit() through this driver tells an
     * end as well) - not while a 'lost' told for a transaction that may still be open is pending.
     * An unreadable state is no "yes".
     */
    private function reportsATransactionThatOwesItsEnd(): bool
    {
        try {
            return $this->pdo->inTransaction() && ($this->transactionBegun || !$this->lostReported);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True when PDO positively reports no transaction; an unreadable state is not "none".
     */
    private function reportsNoTransaction(): bool
    {
        try {
            return !$this->pdo->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Execute a callback within a transaction.
     *
     * Auto-commits on success, auto-rollback on exception. What can go wrong:
     * - the transaction could not be started (BEGIN failed, a transaction.begin listener threw, or
     *   such a listener ended the transaction it was told about):
     *   the callback did not run; after a throwing listener a rollback is attempted (best effort);
     *   the exception is re-thrown, a PDOException from the listener as TransactionException;
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on MySQL 8.0
     *   and MariaDB 11.4 with mysqlnd: after a deadlock (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent, the transaction.rollback listeners run and
     *   transaction.end reports 'rolled_back'; after a lost connection the rollback fails, no
     *   rollback listener runs, PDO still reported the transaction, and transaction.end reports 'lost';
     * - the callback swallowed a statement error that ended the transaction on the server
     *   (PostgreSQL: any error no savepoint caught; MySQL/MariaDB: a deadlock, or a lock wait
     *   timeout under innodb_rollback_on_timeout): the commit is refused before it is sent and the
     *   CommitFailedException is thrown, instead of a COMMIT the server answers with success. While
     *   PDO still reports the transaction the rollback follows and transaction.end reports
     *   'rolled_back'; on MySQL/MariaDB, once PDO knows that the transaction is gone - from a
     *   statement on raw PDO after a deadlock, or from the question to the server after another
     *   failure (the lock wait timeout: statements the callback ran after it were committed on
     *   their own) - nothing is left to roll back and transaction.end reports 'lost'. Through
     *   this library nothing is sent between a deadlock and the rollback;
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   CommitFailedException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   PostgreSQL leaves behind when COMMIT fails on a deferred constraint (the server rolled back),
     *   but also what a callback leaves behind that committed itself with a raw COMMIT or a MySQL DDL
     *   statement (the data is committed, PDO::commit() then fails with "no active transaction").
     *   In both commit cases the exception's $outcome is the outcome transaction.end reported:
     *   only 'rolled_back' says that nothing is committed;
     * - the callback ended the transaction itself through this driver (commit() or rollback()), or a
     *   listener did: this method ends only the transaction it began. Whatever is open afterwards -
     *   begun by the callback or by a listener, through this driver or on raw PDO - is neither
     *   committed nor rolled back here. A callback that returns gets a CommitFailedException with
     *   outcome 'lost' and no COMMIT is sent; one that throws gets its exception re-thrown;
     * - a transaction.commit or transaction.end listener failed, or the connection state after a
     *   commit listener could not be verified or cleaned up: committed, the committed transaction is
     *   not rolled back, CommitHookException (getPrevious() is the first failure, which need not be
     *   a listener's own exception).
     * Once the transaction was started, transaction.end fires exactly once: after the commit
     * listeners ('committed'), or after the rollback listeners ('rolled_back'), or as 'lost'; on the
     * rollback and lost paths its listeners' failures reach the 'error' hook, never the caller.
     *
     * @param Closure $callback Receives the driver instance
     *
     * @throws TransactionException When the transaction could not be started (see beginTransaction())
     * @throws CommitFailedException When the commit failed or was refused; for the commit this method runs itself $outcome is 'rolled_back' or 'lost' (a failed commit() the callback called itself and let escape keeps what that commit gave it: null, or 'lost' if it told the end itself)
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     * @throws Throwable Re-throws the callback, begin listener or commit exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();
        $own = $this->transactionJustBegun();

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            if ($this->stillTheTransaction($own)) {
                $this->rollbackQuietly($e);
            }
            throw $e;
        }

        $this->commitOwnTransaction($own);

        return $result;
    }

    /**
     * The number of the transaction a beginTransaction() call has just begun, for
     * stillTheTransaction(): the beginTransaction() of this class returns only while that
     * transaction is the one at hand. Null after an overriding beginTransaction() that bypasses
     * the one of this class: nothing tells that driver's transactions apart, and whoever began
     * one ends whatever is open, as before.
     */
    private function transactionJustBegun(): ?int
    {
        return $this->transactionBegun ? $this->transactionsBegun : null;
    }

    /**
     * Whether the transaction with that number is still the one at hand: it still owes its end,
     * and none was begun through this driver since. False once it was ended through this driver -
     * a commit() or rollback() of the callback or of a listener, or an end told as 'lost': a
     * transaction that is open then was begun afterwards (by an end listener, by the callback;
     * through this driver or on raw PDO) and is not the one transaction() or updateMultiple()
     * began. They leave it to whoever began it. What is ended and begun again on raw PDO alone is
     * not seen.
     */
    private function stillTheTransaction(?int $number): bool
    {
        return $number === null || ($this->transactionBegun && $this->transactionsBegun === $number);
    }

    /**
     * Commit a transaction this driver began, rolling back only if the commit itself failed. That
     * commit's CommitFailedException leaves with an outcome: the one the end listeners were told
     * with it as error, or 'lost' when no end was told with it. This is the only place that lets
     * rollback() and endLostTransaction() write an outcome: what a callback or a listener throws
     * - a commit() of its own that failed, an exception of another connection or of an earlier
     * transaction - is never written to.
     *
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     * @throws Throwable Re-throws the commit exception after rollback
     */
    private function commitOwnTransaction(?int $own): void
    {
        if (!$this->stillTheTransaction($own)) {
            // Nothing is sent: a COMMIT would commit somebody else's transaction, or fail for want of one
            $refusal = new CommitFailedException(
                message: 'Failed to commit transaction',
                debugMessage: 'Not committed: the transaction this call began has already been ended through this driver (a commit() or rollback() inside the callback or a listener), and its end was told then. A transaction that is open now was begun afterwards and is left to whoever began it.'
            );
            $refusal->outcome = self::TRANSACTION_LOST; // no rollback of this call's work is confirmed here

            throw $refusal;
        }

        $this->thrownByCommit = null; // an overriding commit() may never get to the one of this class

        try {
            $this->commit();
        } catch (CommitHookException $e) {
            // Committed: nothing to roll back. commit() already rolled back (best effort) what a listener left open.
            throw $e;
        } catch (Throwable $e) {
            if ($e === $this->failedCommitThatToldTheEnd) {
                // Failed or refused, and the transaction was gone: its end is told. A transaction PDO
                // reports now is one an end listener began, and that one is not this method's to end.
                $this->failedCommitThatToldTheEnd = null;
                throw $e;
            }
            // The commit itself failed; some drivers (e.g. SQLite) keep the transaction open.
            $failedCommit = $this->thrownByThisCommit($e);
            $this->settlingCommit = $failedCommit;
            try {
                $this->rollbackQuietly($e);
            } finally {
                $this->settlingCommit = null; // nothing was left to end: nobody took it
            }
            if ($failedCommit !== null) {
                // No end was told with it - nothing was left to end because the callback had ended the
                // transaction itself, or its end was told as 'lost' before the commit: no rollback of
                // this commit's work is confirmed
                $failedCommit->outcome ??= self::TRANSACTION_LOST;
            }
            throw $e;
        }
    }

    /**
     * $e as the failure the commit() of this class has just thrown, or null: nothing else that may
     * arrive from a commit() call - what an overriding commit(), a driver hook or an error handler
     * throws - is this transaction's failed commit.
     */
    private function thrownByThisCommit(Throwable $e): ?CommitFailedException
    {
        return $this->thrownByCommit !== null && $e === $this->thrownByCommit ? $this->thrownByCommit : null;
    }

    /**
     * End a transaction this driver began after $cause ended its work, ignoring failures: $cause is
     * more important for debugging and reaches the caller unchanged. If PDO still reports the
     * transaction, ROLLBACK is sent; when it succeeds the 'transaction.rollback' listeners run and
     * 'transaction.end' reports 'rolled_back'. When the rollback fails, PDO no longer reports the
     * transaction (while this driver still owed its end), or the state cannot be read,
     * 'transaction.end' reports 'lost'. Nothing fires for a transaction this driver already ended
     * (a callback that called rollback() itself before throwing).
     */
    private function rollbackQuietly(Throwable $cause): void
    {
        $sent = $this->rollbacksSent;

        try {
            if ($this->pdo->inTransaction()) {
                $this->automaticRollbackCause = $cause; // consumed by rollback() on entry
                $this->rollback();
            } elseif ($this->transactionBegun) {
                $this->endLostTransaction($cause, mayStillBeOpen: false);
            }
        } catch (Throwable) {
            // $cause is more important for debugging. Reached when the rollback itself failed (or the
            // state could not be read) - then the transaction may still be open - or when a listener
            // threw after the rollback went through: then the transaction has ended and told its end.
            $this->automaticRollbackCause = null;
            if ($this->rollbacksSent === $sent && $this->transactionBegun) {
                $this->endLostTransaction($cause, mayStillBeOpen: true);
            }
        } finally {
            $this->automaticRollbackCause = null;
        }
    }

    /**
     * 'transaction.end' with outcome 'lost': the transaction ended without a commit by this driver
     * and no rollback could be confirmed. When it may in fact still be open (the rollback failed,
     * the state could not be read), its later commit()/rollback() tells no second end.
     */
    private function endLostTransaction(Throwable $cause, bool $mayStillBeOpen): void
    {
        $this->automaticRollbackCause = null; // a rollback() a listener calls is an explicit one
        $this->transactionBegun = false;
        $this->lostReported = $mayStillBeOpen;
        if (!$mayStillBeOpen) {
            $this->suspectFailure = null; // gone with the transaction; one that may still be open keeps its commit refused
        }
        if ($this->settlingCommit !== null && $cause === $this->settlingCommit) {
            $this->settlingCommit->outcome = self::TRANSACTION_LOST; // what the end listeners are told below
            $this->settlingCommit = null; // written once, as in rollback()
        }
        $this->reportTransactionEndFailures(
            self::TRANSACTION_LOST,
            $this->dispatchTransactionEnd(self::TRANSACTION_LOST, $cause)
        );
    }

    // =========================================================================
    // CRUD Helper
    // =========================================================================

    /**
     * Insert a row and return the last insert ID.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs
     *
     * @throws QueryException When $data is empty or query fails
     *
     * @return int|string Last insert ID
     */
    public function insert(string $table, array $data): int|string
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }

        [$columns, $values, $params] = $this->buildInsertParts($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            $columns,
            $values
        );

        // Read before the 'query' hook runs: a listener that inserts would replace the id
        $lastId = null;
        $this->queryThen($sql, $params, function () use (&$lastId): void {
            $lastId = $this->lastInsertId();
        });
        // An overridden query() that bypasses this class's query() never ran the step
        $lastId ??= $this->lastInsertId();

        if ($lastId === false) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('Failed to retrieve last insert ID | SQL: %s | Params: %s', $sql, $this->encodeParams($params))
            );
        }

        return $lastId;
    }

    /**
     * Insert a row only when a condition holds, in one statement.
     *
     * Renders `INSERT INTO table (...) SELECT ?, ?, ... WHERE (condition)`; MySQL/MariaDB need
     * `FROM DUAL` before a WHERE without a table. The row's values are bound first, then the
     * condition's bindings (see DatabaseInterface::insertWhen()).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     *
     * @throws QueryException When $data or the condition is empty, a binding is a RawExpression, or the query fails
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertWhen(string $table, array $data, string $condition, array $bindings = []): int
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }
        if (trim($condition) === '') {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'insertWhen() needs a condition'
            );
        }
        foreach ($bindings as $binding) {
            if ($binding instanceof RawExpression) {
                throw new QueryException(
                    message: 'Insert failed',
                    debugMessage: 'insertWhen() binds the condition values; write a raw expression into the condition instead'
                );
            }
        }

        [$columns, $values, $params] = $this->buildInsertParts($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) SELECT %s%s WHERE (%s)',
            $this->quoteIdentifier($table),
            $columns,
            $values,
            $this->getDialect() === \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_MYSQL ? ' FROM DUAL' : '',
            trim($condition)
        );

        return $this->execute($sql, [...$params, ...array_values($bindings)]);
    }

    /**
     * Insert a row unless it collides with an existing one (see DatabaseInterface::insertIgnore()).
     *
     * MySQL/MariaDB get `ON DUPLICATE KEY UPDATE col = col` on the row's first column: a no-op
     * whichever key collided, reported as 0 affected rows. Every other dialect gets
     * `ON CONFLICT DO NOTHING`.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs of the row
     *
     * @throws QueryException When $data is empty or the query fails for another reason than a duplicate
     *
     * @return int Inserted rows: 1 or 0
     */
    public function insertIgnore(string $table, array $data): int
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }

        [$columns, $values, $params] = $this->buildInsertParts($data);

        if ($this->getDialect() === \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_MYSQL) {
            $first = $this->quoteIdentifier((string) array_key_first($data));
            $onDuplicate = sprintf('ON DUPLICATE KEY UPDATE %s = %s', $first, $first);
        } else {
            $onDuplicate = 'ON CONFLICT DO NOTHING';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) %s',
            $this->quoteIdentifier($table),
            $columns,
            $values,
            $onDuplicate
        );

        return $this->execute($sql, $params);
    }

    /**
     * Update rows matching WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs to update
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws QueryException When $data or $where is empty (safety)
     *
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            throw new QueryException(
                message: 'Update failed',
                debugMessage: 'Cannot update with empty data'
            );
        }

        if (empty($where)) {
            throw new QueryException(
                message: 'Update failed',
                debugMessage: 'Cannot update without WHERE conditions (safety check)'
            );
        }

        [$setSql, $params] = $this->buildSetClause($data);
        [$whereSql, $whereParams] = $this->buildWhereClause($where);
        $params = array_merge($params, $whereParams);

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            $setSql,
            $whereSql
        );

        return $this->execute($sql, $params);
    }

    /**
     * Delete rows matching WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws QueryException When $where is empty (safety)
     *
     * @return int Number of affected rows
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new QueryException(
                message: 'Delete failed',
                debugMessage: 'Cannot delete without WHERE conditions (safety check)'
            );
        }

        [$whereSql, $params] = $this->buildWhereClause($where);

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $whereSql
        );

        return $this->execute($sql, $params);
    }

    /**
     * Find a single row by WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $where WHERE conditions (column => value)
     *
     * @throws QueryException When $where is empty
     *
     * @return array<string, mixed>|null Row as associative array or null if not found
     */
    public function findOne(string $table, array $where): ?array
    {
        if (empty($where)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: 'findOne requires WHERE conditions. Use findAll() without WHERE to get all rows.'
            );
        }

        [$whereSql, $params] = $this->buildWhereClause($where);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            $this->quoteIdentifier($table),
            $whereSql
        );

        $stmt = $this->query($sql, $params);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * Find all rows matching WHERE conditions.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $where WHERE conditions (optional, empty = all rows)
     *
     * @throws QueryException On query failure
     *
     * @return array<int, array<string, mixed>> Array of rows as associative arrays
     */
    public function findAll(string $table, array $where = []): array
    {
        if (empty($where)) {
            $sql = sprintf('SELECT * FROM %s', $this->quoteIdentifier($table));
            $params = [];
        } else {
            [$whereSql, $params] = $this->buildWhereClause($where);
            $sql = sprintf(
                'SELECT * FROM %s WHERE %s',
                $this->quoteIdentifier($table),
                $whereSql
            );
        }

        $stmt = $this->query($sql, $params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update multiple rows by their key column.
     *
     * Each row must contain the key column for matching. Without an active transaction, the
     * rows are updated in an own transaction with the same outcomes as transaction().
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<int, array<string, mixed>> $rows Array of rows, each with key column
     * @param string $keyColumn Column to match rows (default: 'id')
     *
     * @throws QueryException When a row is missing the key column
     * @throws TransactionException When the own transaction's commit failed, or a listener ended the own transaction while the batch ran (a CommitFailedException with outcome 'lost'; what is open then is left alone)
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     *
     * @return int Total number of affected rows
     */
    public function updateMultiple(string $table, array $rows, string $keyColumn = 'id'): int
    {
        if (empty($rows)) {
            return 0;
        }

        $manageTransaction = !$this->pdo->inTransaction();

        $own = null;
        if ($manageTransaction) {
            $this->beginTransaction();
            $own = $this->transactionJustBegun();
        }

        try {
            $affected = 0;

            foreach ($rows as $row) {
                if (!array_key_exists($keyColumn, $row)) {
                    throw new QueryException(
                        message: 'Update failed',
                        debugMessage: sprintf('Missing key column "%s" in row', $keyColumn)
                    );
                }

                $keyValue = $row[$keyColumn];
                $data = array_diff_key($row, [$keyColumn => null]);

                if (!empty($data)) {
                    $affected += $this->update($table, $data, [$keyColumn => $keyValue]);
                }
            }
        } catch (Throwable $e) {
            if ($manageTransaction && $this->stillTheTransaction($own)) {
                $this->rollbackQuietly($e);
            }
            throw $e;
        }

        if ($manageTransaction) {
            $this->commitOwnTransaction($own);
        }

        return $affected;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Quote an identifier (table/column name).
     *
     * Handles schema.table and table.column format:
     * - "users" -> "users"
     * - "public.users" -> "public"."users"
     *
     * Override in driver for DB-specific quoting (e.g., backticks for MySQL).
     *
     * @param string $identifier Table or column name
     *
     * @return string Quoted identifier
     */
    protected function quoteIdentifier(string $identifier): string
    {
        $quote = $this->getQuoteChar();
        $escape = $quote . $quote;

        // Handle schema.table or table.column format
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map(
                static fn ($part) => $quote . str_replace($quote, $escape, $part) . $quote,
                $parts
            ));
        }

        return $quote . str_replace($quote, $escape, $identifier) . $quote;
    }

    /**
     * Build the column list, the VALUES list and the params of an INSERT.
     *
     * A RawExpression value is inlined into the VALUES list instead of being bound, its own
     * bindings take its place among the params (SECURITY: never pass user input as the SQL of
     * Database::raw()).
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>} [columns sql, values sql, params]
     */
    protected function buildInsertParts(array $data): array
    {
        $columns = [];
        $values = [];
        $params = [];

        foreach ($data as $column => $value) {
            $columns[] = $this->quoteIdentifier($column);
            $values[] = self::valueSql($value, $params);
        }

        return [implode(', ', $columns), implode(', ', $values), $params];
    }

    /**
     * Build the SET clause of an UPDATE and its params: the assignments in the order of $data.
     *
     * A RawExpression value is inlined instead of being bound, its own bindings take its place
     * among the params (SECURITY: never pass user input as the SQL of Database::raw()).
     *
     * @param array<string, mixed> $data Column => value pairs
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params]
     */
    protected function buildSetClause(array $data): array
    {
        $clauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $clauses[] = $this->quoteIdentifier($column) . ' = ' . self::valueSql($value, $params);
        }

        return [implode(', ', $clauses), $params];
    }

    /**
     * What stands for a value in the SQL, and its params: a placeholder and the value - or, for a
     * RawExpression, its SQL and its own bindings, at this very position among the params.
     *
     * @param array<int, mixed> $params
     */
    private static function valueSql(mixed $value, array &$params): string
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

    /**
     * Build WHERE clause from conditions array.
     *
     * A RawExpression value is inlined instead of being bound, its own bindings take its place
     * among the params (SECURITY: never pass user input as the SQL of Database::raw()).
     *
     * @param array<string, mixed> $where Column => value pairs
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params] - SQL string and parameter values
     */
    protected function buildWhereClause(array $where): array
    {
        $clauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            if ($value === null) {
                throw new QueryException(
                    message: 'Query failed',
                    debugMessage: sprintf(
                        'NULL value for column "%s" in WHERE condition. Use whereNull() via the query builder, or a raw query with IS NULL.',
                        $column
                    )
                );
            }
            $clauses[] = $this->quoteIdentifier($column) . ' = ' . self::valueSql($value, $params);
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Current date and time as a raw SQL expression for insert()/update()/where() values.
     *
     * The shipped drivers return their dialect's statement-time expression (MySQL `NOW()`,
     * PostgreSQL `CAST(statement_timestamp() AS TIMESTAMP(0))`, SQLite `datetime('now', 'localtime')`);
     * this default is the SQL standard `CURRENT_TIMESTAMP`. Override in a custom driver.
     */
    public function now(): RawExpression
    {
        return new RawExpression('CURRENT_TIMESTAMP');
    }

    /**
     * Current UTC date and time as a raw SQL expression (a zoneless value).
     *
     * The shipped drivers return their dialect's expression (MySQL `UTC_TIMESTAMP()`, PostgreSQL
     * `CAST(statement_timestamp() AT TIME ZONE 'UTC' AS TIMESTAMP(0))`, SQLite `datetime('now')`);
     * this default is `CURRENT_TIMESTAMP`, which is UTC only when the dialect evaluates it in UTC
     * and the session's time zone is UTC. Override in a custom driver.
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression('CURRENT_TIMESTAMP');
    }

    /**
     * Get the quote character for identifiers.
     *
     * Override in driver for DB-specific quoting.
     * - PostgreSQL: " (double quote)
     * - MySQL, SQLite: ` (backtick; SQLite would read an unknown double-quoted name as a string)
     *
     * @return string Quote character
     */
    protected function getQuoteChar(): string
    {
        return '"';
    }

    /**
     * Get the SQL dialect the query builder renders for (one of QueryBuilder::DIALECT_*).
     *
     * The bundled drivers override it. The default derives it from the quote character, as the
     * builder did before it knew dialects: a backtick means MySQL (a custom driver that only
     * overrides getQuoteChar() keeps MySQL's LIKE escaping and lock syntax), anything else ANSI,
     * which renders FOR UPDATE / FOR SHARE, IS [NOT] DISTINCT FROM and OFFSET without LIMIT as
     * PostgreSQL does.
     */
    protected function getDialect(): string
    {
        return $this->getQuoteChar() === '`'
            ? \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_MYSQL
            : \Sodaho\PdoWrapper\Query\QueryBuilder::DIALECT_ANSI;
    }

    // =========================================================================
    // Query Builder
    // =========================================================================

    /**
     * Create a query builder for the given table.
     *
     * @param string $table Table name (supports schema.table format)
     */
    public function table(string $table): \Sodaho\PdoWrapper\Query\QueryBuilder
    {
        return new \Sodaho\PdoWrapper\Query\QueryBuilder($this, $table, $this->getQuoteChar(), $this->getDialect());
    }
}
