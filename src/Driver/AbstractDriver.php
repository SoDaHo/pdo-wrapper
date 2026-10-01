<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use Closure;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Traits\HasHooks;
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

    /** While rollbackQuietly() runs rollback(): the exception that ended the transaction (the end event's error) */
    private ?Throwable $automaticRollbackCause = null;

    /** True after 'transaction.end' reported (or buffered) 'lost' for a transaction that may still be open: its later commit()/rollback() tells no second end */
    private bool $lostReported = false;

    /** Counts the rollbacks rollback() sent successfully: lets rollbackQuietly() tell a listener's exception from a failed rollback */
    private int $rollbacksSent = 0;

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
     * @param string $sql SQL query with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws QueryException On query failure, or when a 'query' hook threw a PDOException
     *
     * @return PDOStatement Executed statement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw $this->silentFailure('PDO::prepare() returned false', $this->pdo->errorInfo());
            }
            if ($this->bindAndExecute($stmt, $params) === false) {
                throw $this->silentFailure('PDOStatement::execute() returned false', $stmt->errorInfo());
            }
        } catch (PDOException $e) {
            $this->trigger('error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw new QueryException(
                message: 'Query failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, json_encode($params))
            );
        }

        $rows = $stmt->rowCount();

        // The statement ran: a hook failure must not look like a failed query, and 'error' must not fire.
        try {
            $this->trigger('query', [
                'sql' => $sql,
                'params' => $params,
                'duration' => microtime(true) - $start,
                'rows' => $rows,
            ]);
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Query hook failed',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, json_encode($params))
            );
        }

        return $stmt;
    }

    /**
     * Bind the parameters and execute the prepared statement: PDOStatement::execute($params), every
     * value bound as text, a boolean as '1' or '0'. PDO alone sends false as '', which MySQL in strict
     * mode and PostgreSQL reject for a numeric or boolean column. Text rather than a typed binding:
     * PARAM_INT makes MySQL compare a text column numerically ('abc' = 0 is true), and PARAM_BOOL
     * reaches PostgreSQL as 't'/'f', which an integer column rejects (measured on MySQL 8.0,
     * MariaDB 11.4 and PostgreSQL 15). A driver overrides this when its database needs typed bindings.
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
    private function silentFailure(string $what, array $errorInfo): PDOException
    {
        $reason = is_string($errorInfo[2] ?? null) ? $errorInfo[2] : 'unknown error';
        $state = is_string($errorInfo[0] ?? null) ? $errorInfo[0] : '';
        $driverCode = is_int($errorInfo[1] ?? null) ? $errorInfo[1] : 0;

        $e = new PDOException(sprintf('%s: %s (SQLSTATE %s)', $what, $reason, $state), $driverCode);
        $e->errorInfo = $errorInfo;

        return $e;
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
            return $this->pdo->lastInsertId($name);
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Failed to get last insert ID',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
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
     * caller, a PDOException as TransactionException.
     *
     * @throws TransactionException On failure, including PDO::beginTransaction() returning false (non-exception error mode)
     */
    public function beginTransaction(): void
    {
        try {
            $begun = $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                code: (int)$e->getCode(),
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
        $this->lostReported = false;

        try {
            $this->trigger('transaction.begin', []);
        } catch (PDOException $e) {
            $this->rollbackRawQuietly();
            throw new TransactionException(
                message: 'Failed to begin transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            $this->rollbackRawQuietly();
            throw $e;
        }
    }

    /**
     * Roll back on raw PDO if a transaction is open, without 'transaction.rollback' or 'transaction.end'
     * hooks and ignoring failures: the exception that caused this is more important for debugging.
     */
    private function rollbackRawQuietly(): void
    {
        // Ended here, without an event: a later cleanup must not report it as lost
        $this->transactionBegun = false;

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
     * 'transaction.end': the transaction is still the caller's to end.
     *
     * @throws TransactionException When the commit itself failed; it may or may not have taken effect
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     */
    public function commit(): void
    {
        try {
            $committed = $this->pdo->commit();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to commit transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options').
        if ($committed === false) {
            throw new TransactionException(
                message: 'Failed to commit transaction',
                debugMessage: 'PDO::commit() returned false'
            );
        }

        // Committed from here on: a listener error must not look like a failed commit.
        $this->transactionBegun = false;
        $endOwed = !$this->lostReported; // after a reported 'lost' this transaction's end has already been told
        $this->lostReported = false;
        [$failures, $connectionInTransaction, $innerEnds] = $this->runCommitListeners();
        $failures = [...$failures, ...$this->dispatchInnerEnds($innerEnds)];
        if ($endOwed) {
            $failures = [...$failures, ...$this->dispatchTransactionEnd(self::TRANSACTION_COMMITTED, null)];
        }

        if ($failures !== []) {
            throw new CommitHookException($failures[0], $failures, $connectionInTransaction);
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
            try {
                $this->trigger('error', [
                    'sql' => '',
                    'params' => [],
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'hook' => 'transaction.end',
                    'outcome' => $outcome,
                    'exception' => $e,
                ]);
            } catch (Throwable) {
                // the exception that ended the transaction is more important
            }
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
     * @return array{list<Throwable>, bool, list<array{string, Throwable}>}
     */
    private function runCommitListeners(): array
    {
        $failures = [];
        $innerEnds = [];
        $cleanupError = null;

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
     * 'transaction.end' that reported 'lost' for this transaction, no second end is told. An
     * override that does not call this method dispatches no event.
     *
     * @throws TransactionException On failure, or when a transaction.end listener failed and no rollback listener did (the first failure; all of them reach the 'error' hook)
     * @throws Throwable Re-throws a rollback listener's exception
     */
    public function rollback(): void
    {
        // Set by rollbackQuietly(): this rollback ends a transaction that $cause ended, which reaches the caller instead.
        // Consumed here, so that a rollback() a listener calls for its own transaction is an explicit one.
        $cause = $this->automaticRollbackCause;
        $this->automaticRollbackCause = null;

        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                code: (int)$e->getCode(),
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

        // Rolled back from here on. A PDOException from a hook keeps arriving as TransactionException (unchanged contract).
        $this->transactionBegun = false;
        $this->rollbacksSent++; // tells rollbackQuietly() that a later exception came from a listener, not from the rollback
        $endOwed = !$this->lostReported; // after a reported 'lost' this transaction's end has already been told
        $this->lostReported = false;
        $pending = null;
        try {
            $this->trigger('transaction.rollback', []);
        } catch (PDOException $e) {
            $pending = new TransactionException(
                message: 'Failed to rollback transaction',
                code: (int)$e->getCode(),
                previous: $e,
                debugMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            $pending = $e;
        }

        $failures = $endOwed ? $this->dispatchTransactionEnd(self::TRANSACTION_ROLLED_BACK, $cause) : [];
        if ($cause !== null) {
            // Automatic rollback: end listener failures only reach the 'error' hook; a rollback listener's
            // exception is re-thrown as before (rollbackQuietly() swallows it, an override sees it)
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            if ($pending !== null) {
                throw $pending;
            }

            return;
        }
        if ($pending !== null) {
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            throw $pending;
        }
        if ($failures !== []) {
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            throw new TransactionException(
                message: 'Transaction rolled back, but a transaction.end listener failed',
                code: (int)$failures[0]->getCode(),
                previous: $failures[0],
                debugMessage: $failures[0]->getMessage()
            );
        }
    }

    /**
     * Execute a callback within a transaction.
     *
     * Auto-commits on success, auto-rollback on exception. Four outcomes on failure:
     * - the transaction could not be started (BEGIN failed, or a transaction.begin listener threw):
     *   the callback did not run; after a throwing listener a rollback is attempted (best effort);
     *   the exception is re-thrown, a PDOException from the listener as TransactionException;
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on MySQL 8.0
     *   and MariaDB 11.4 with mysqlnd: after a deadlock (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent, the transaction.rollback listeners run and
     *   transaction.end reports 'rolled_back'; after a lost connection the rollback fails, no
     *   rollback listener runs, PDO still reported the transaction, and transaction.end reports 'lost';
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   TransactionException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   PostgreSQL leaves behind when COMMIT fails on a deferred constraint (the server rolled back),
     *   but also what a callback leaves behind that committed itself with a raw COMMIT or a MySQL DDL
     *   statement (the data is committed, PDO::commit() then fails with "no active transaction");
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
     * @throws TransactionException When the transaction could not be started (see beginTransaction()) or the commit failed
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     * @throws Throwable Re-throws the callback, begin listener or commit exception after rollback
     *
     * @return mixed Return value of the callback
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->rollbackQuietly($e);
            throw $e;
        }

        $this->commitOwnTransaction();

        return $result;
    }

    /**
     * Commit a transaction this driver began, rolling back only if the commit itself failed.
     *
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     * @throws Throwable Re-throws the commit exception after rollback
     */
    private function commitOwnTransaction(): void
    {
        try {
            $this->commit();
        } catch (CommitHookException $e) {
            // Committed: nothing to roll back. commit() already rolled back (best effort) what a listener left open.
            throw $e;
        } catch (Throwable $e) {
            // The commit itself failed; some drivers (e.g. SQLite) keep the transaction open.
            $this->rollbackQuietly($e);
            throw $e;
        }
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

        $this->query($sql, $params);

        $lastId = $this->lastInsertId();

        if ($lastId === false) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('Failed to retrieve last insert ID | SQL: %s | Params: %s', $sql, json_encode(array_values($data)))
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
     * @throws TransactionException When the own transaction's commit failed
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

        if ($manageTransaction) {
            $this->beginTransaction();
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
            if ($manageTransaction) {
                $this->rollbackQuietly($e);
            }
            throw $e;
        }

        if ($manageTransaction) {
            $this->commitOwnTransaction();
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
     * A RawExpression value is inlined into the VALUES list instead of being bound
     * (SECURITY: never pass user input to Database::raw()).
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
            if ($value instanceof RawExpression) {
                $values[] = (string) $value;
                continue;
            }
            $values[] = '?';
            $params[] = $value;
        }

        return [implode(', ', $columns), implode(', ', $values), $params];
    }

    /**
     * Build the SET clause of an UPDATE and its params.
     *
     * A RawExpression value is inlined instead of being bound (SECURITY: never pass user input to Database::raw()).
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
            if ($value instanceof RawExpression) {
                $clauses[] = $this->quoteIdentifier($column) . ' = ' . $value;
                continue;
            }
            $clauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        return [implode(', ', $clauses), $params];
    }

    /**
     * Build WHERE clause from conditions array.
     *
     * A RawExpression value is inlined instead of being bound (SECURITY: never pass user input to Database::raw()).
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
            if ($value instanceof RawExpression) {
                $clauses[] = $this->quoteIdentifier($column) . ' = ' . $value;
                continue;
            }
            $clauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
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
