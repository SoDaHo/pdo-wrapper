<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use Closure;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\Codes;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Exception\CommitHookException;
use Sodaho\PdoWrapper\Exception\ConnectionException;
use Sodaho\PdoWrapper\Exception\ImplicitCommitException;
use Sodaho\PdoWrapper\Exception\ListenerTransactionException;
use Sodaho\PdoWrapper\Exception\NamedLockReentryException;
use Sodaho\PdoWrapper\Exception\NamedLocksHeldException;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Exception\TransactionException;
use Sodaho\PdoWrapper\Exception\TransactionOpenException;
use Sodaho\PdoWrapper\Exception\UniqueViolationException;
use Sodaho\PdoWrapper\InternalMethods;
use Sodaho\PdoWrapper\Query\FloatText;
use Sodaho\PdoWrapper\Query\RawExpression;
use Sodaho\PdoWrapper\Query\Sql;
use Sodaho\PdoWrapper\Schema\Schema;
use Sodaho\PdoWrapper\Traits\HasHooks;
use Stringable;
use Throwable;
use WeakReference;

/**
 * Abstract base driver implementing common database operations.
 *
 * Provides PDO wrapper functionality, CRUD helpers, transactions,
 * and hooks. Extend this class for database-specific drivers.
 */
abstract class AbstractDriver implements DatabaseInterface, InternalMethods
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

    /** True after 'transaction.end' reported 'lost' for a transaction that may still be open: its later commit()/rollback() tells no second end */
    private bool $lostReported = false;

    /**
     * Counts the transactions ended through this driver - by commit(), by rollback(), by the raw
     * rollback after a throwing begin listener, or told as 'lost' -, at the moment they end: before
     * any of their listeners runs. A call that has been inside PDO - and with it, possibly, inside
     * foreign code that used this driver (an error handler for a PDO warning) - compares it to see
     * that the transaction it was about has been ended meanwhile (endedSince()).
     */
    private int $transactionsEnded = 0;

    /** What $transactionsEnded was when the latest transaction was begun through this driver */
    private int $endedAtBegin = 0;

    /**
     * Set by queryThen() and the named-lock methods: the step for exactly that statement - its SQL,
     * at the hook depth it was set at - run once it was executed, before its 'query' hook. Taken
     * away when a statement takes it, if it is marked so (the named-lock methods': their query() is
     * this class's own and takes it before any other code runs).
     *
     * @var array{string, int, Closure, bool}|null
     */
    private ?array $afterExecute = null;

    /**
     * How many 'query.before'/'query'/'error' listeners of query() may run one inside the other: a
     * listener that runs a statement of its own on every statement it is told about would otherwise
     * recurse until PHP runs out of memory - a fatal error nothing catches. Generous: a listener with
     * a statement of its own (an audit row) needs one level.
     */
    private const MAX_HOOK_DEPTH = 32;

    /** How many 'query.before'/'query'/'error' listeners of query() are running right now, one inside the other */
    private int $hookDepth = 0;

    /** The statement failure inside the open transaction that may have ended it on the server (see failureToRemember()); commit() asks before it commits */
    private ?PDOException $suspectFailure = null;

    /**
     * What the driver found right after a statement failure inside the transaction begun through it
     * that does not settle the matter by itself (not transactionIsOver()): asked at once
     * (askWhetherTheTransactionSurvived()), PDO reported no transaction - the server had ended it,
     * rolled back or committed implicitly (a failing DDL statement on MariaDB) -, or the driver could
     * not find out. The failure, whether the answer was known, and the number of that transaction.
     * Held until that transaction is ended here: no later failure replaces it (a deadlock of a
     * statement sent afterwards would claim a rollback of what is committed), query() sends nothing
     * more, commit() refuses without asking again, rollback() tells 'lost'. Counts only for the
     * transaction with that number (goneTransaction()).
     *
     * @var array{PDOException, bool, int}|null
     */
    private ?array $transactionGone = null;

    /** Counts the calls of commit(): the number of the latest one */
    private int $commitCalls = 0;

    /**
     * Counts the statements query() hands to the server - every SQL statement a caller hands to
     * the library goes through it -, at the moment it executes them.
     * One it refuses before is not counted; one that fails while its parameters are bound counts:
     * an answer that may still have been right is then discarded - 'lost', fail-closed. A call into
     * PDO that saw it go up has run foreign code (an error handler) that sent statements through
     * this driver: a SET SESSION completion_type among them, say (see
     * noteACommitThatMayHaveTakenEffect()).
     */
    private int $statementsSent = 0;

    /**
     * The failure the latest commit() that failed has thrown, and the number of that call.
     * commitOwnTransaction() tells the failure of the commit() it called by both - not by the
     * class: a driver hook or an error handler may throw a CommitFailedException that belongs to
     * another commit - of an earlier transaction, or one it issued itself in the middle of this
     * one, for this transaction or for one it began. Held weakly: commitOwnTransaction() holds the
     * failure it caught while it compares, and the driver must not keep it alive after that - its
     * previous exception's trace may hold a statement of a connection reconnect() has discarded
     * meanwhile (an end listener of the refused commit's 'lost' that reconnects).
     *
     * @var array{WeakReference<CommitFailedException>, int}|null
     */
    private ?array $thrownByCommit = null;

    /**
     * While commitOwnTransaction() ends the transaction whose commit just failed: that failure.
     * rollback() or endLostTransaction() takes it away before any listener runs, and writes the
     * outcome it tells into it: written once. Set nowhere else, so an exception a callback or a
     * listener throws is never written to, whatever it is.
     */
    private ?CommitFailedException $settlingCommit = null;

    /**
     * A COMMIT that failed while PDO did not say that the transaction is gone, on a session that may
     * chain transactions (commitMayHaveChained()): the failure, and the counts endedSince()
     * compares. Whatever ends that transaction as rolled back confirms nothing: the transaction
     * PDO reports may be a new one the server opened for a COMMIT that took effect - rollback()
     * tells 'lost' instead. No longer counts once the transaction is ended through this driver.
     * Set while the server is asked, and kept when the answer is NO_CHAIN but an earlier failed COMMIT of the
     * same transaction said otherwise. A transaction begun on raw PDO, ended there and begun there
     * again is not told apart (see endedSince()): its rollback() is 'lost' as well, fail-closed.
     *
     * @var array{Throwable, int, int}|null
     */
    private ?array $unclearCommit = null;

    /**
     * How many transactions begun through this driver have had their 'transaction.begin' told and
     * not yet their 'transaction.end' - every told begin gets exactly one end. The depth of the
     * next one is this count plus one: 1 as a rule - only foreign code inside a PDO call (an error
     * handler for a PDO warning) can begin a transaction while another one's end is still owed
     * (listeners cannot: refused inside a listener).
     */
    private int $unendedBegins = 0;

    /** The depth of the latest transaction begun through this driver: 1 when no other end was owed then */
    private int $depthAtBegin = 0;

    /**
     * While $lostReported: the transaction that 'lost' was told for - its number and depth, both
     * null for one begun on raw PDO -, for the events of its later commit() or rollback()
     *
     * @var array{?int, ?int}|null
     */
    private ?array $lostFor = null;

    /**
     * Opens a connection with the settings the driver was created with: for reconnect(). Null for a
     * driver that sets $pdo itself.
     *
     * @var (Closure(): PDO)|null
     */
    private ?Closure $connector = null;

    /**
     * The named locks this driver holds as far as it knows, by name (without the prefix): see
     * heldNamedLocks().
     *
     * @var array<string, string>
     */
    private array $heldNamedLocks = [];

    /** How many named-lock statements have run and their methods not yet returned, one inside a listener of the other: reconnect() refuses meanwhile */
    private int $lockStatementsRunning = 0;

    /**
     * @param (Closure(): PDO)|null $connect Opens the connection - with the settings the driver was
     *                                       created with, now and for reconnect(). Null for a driver
     *                                       that sets $pdo itself: it cannot reconnect.
     *
     * @throws ConnectionException What $connect throws when the connection fails
     */
    public function __construct(?Closure $connect = null)
    {
        if ($connect !== null) {
            $this->connector = $connect;
            $this->pdo = $connect();
        }
    }

    /**
     * The configured port as a number, or a ConnectionException: the DSN is built with %d, which
     * would turn "abc" into port 0 and "3306;host=other" into 3306 without a word.
     *
     * @throws ConnectionException When the port is not a whole number between 1 and 65535
     */
    protected static function validPort(mixed $port): int
    {
        if (is_string($port) && preg_match('/^[0-9]+\z/', $port) === 1) { // \z: no line break after it
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

    /**
     * The configured class of the PDO object the driver creates, or a ConnectionException: the
     * name is used with new, where anything but a class that can stand in for PDO would end in an
     * Error - or in an object the driver cannot use. The message names the key, never the value.
     *
     * @throws ConnectionException When the value is not the name of a class that is PDO or extends it and can be instantiated
     *
     * @return class-string<PDO>
     */
    protected static function validPdoClass(mixed $class): string
    {
        if (!is_string($class) || !is_a($class, PDO::class, true) || !new ReflectionClass($class)->isInstantiable()) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'Invalid config value "pdoClass": expected the name of a class that extends PDO and can be instantiated'
            );
        }

        return $class;
    }

    // =========================================================================
    // Query Execution
    // =========================================================================

    /**
     * Execute a SQL query and return the statement.
     *
     * Triggers 'query.before' first, then 'query' hook on success, 'error' hook on failure.
     * 'query.before' fires before anything else - before the library's own checks below, so that
     * they see what a listener did -, for every call: also for a statement the library then
     * refuses. A listener that throws stops the statement: nothing is sent, neither 'query' nor
     * 'error' fires, and its exception reaches the caller unchanged - a PDOException as
     * QueryException 'Query hook failed', as from a 'query' listener. A failure that PDO reports by
     * returning false (non-exception error mode) counts as a failure. A PDOException thrown by a
     * 'query' hook is not a failed query: the statement ran, no 'error' hook fires, and it arrives
     * as QueryException with the message 'Query hook failed'; other hook exceptions pass unchanged.
     *
     * A parameter must be null, a scalar or a Stringable object. An array, a resource or any other
     * object is a failure before the statement is sent (PDO would bind an array as the text "Array"
     * and a resource as "Resource id #n"): pass an enum's value, a formatted date, an encoded array.
     * So is a RawExpression: bound, it would arrive as its own text; write it into the SQL. And so
     * is a float INF or NAN: MariaDB has no such number, and as text it would compare as 0.
     *
     * After a failure that ended the open transaction on the server for certain (a MariaDB
     * deadlock or a 1020, see transactionIsOver()) nothing is sent until that transaction is ended here -
     * by rollback(), or by a refused commit() that tells 'lost'; for a transaction begun on raw PDO
     * also once PDO reports none: the statement throws, with that failure as previous, and fires
     * neither 'query' nor 'error' ('query.before' has fired). A ROLLBACK sent as a statement is
     * refused like any other: call rollback(). The same holds, for a transaction begun through this
     * driver, while PDO reports no transaction any more (a DDL statement committed it implicitly,
     * raw PDO ended it), and after any other failure inside it once the driver, asked right after
     * that failure, found the transaction gone or could not find out (see $transactionGone): what
     * would be sent then would run in autocommit and be committed on its own. Inside a transaction
     * begun through this driver, a statement that commits implicitly (implicitCommitOf(): DDL on
     * MariaDB) is refused as well, with an ImplicitCommitException: the transaction stays open and
     * intact. SQL that steers transactions itself (BEGIN, COMMIT, ROLLBACK, SET autocommit, XA) is
     * neither refused nor seen.
     *
     * @param string $sql SQL query with placeholders
     * @param array<int|string, mixed> $params Parameters to bind
     *
     * @throws ImplicitCommitException When the statement would commit the open transaction begun through this driver implicitly (nothing is sent)
     * @throws QueryException On query failure (a UniqueViolationException for a duplicate key), on a parameter that cannot be bound, or when a 'query.before' or 'query' hook threw a PDOException
     * @throws Throwable What a 'query.before', 'query' or 'error' hook throws otherwise, and what an error handler throws that is not about a PDO failure: both pass unchanged
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
            if ($this->afterExecute[3]) {
                $this->afterExecute = null; // this statement's alone: foreign code inside this call that sends the same SQL does not take it
            }
        }

        // First: the checks below see what a listener did (a statement of its own, a reconnect())
        try {
            // The parameters as values: an element that is a reference would let a listener change what is bound
            $this->triggerFromQuery('query.before', ['sql' => $sql, 'params' => array_map(static fn (mixed $value): mixed => $value, $params)]);
        } catch (PDOException $e) {
            throw new QueryException(
                message: 'Query hook failed',
                previous: $e,
                debugMessage: sprintf('Not sent: a query.before listener threw: %s | SQL: %s | Params: %s', $e->getMessage(), $sql, $this->encodeParams($params)),
                listenerFailure: true
            );
        }

        // The server has thrown the open transaction away (a MariaDB deadlock or a 1020): what would be sent
        // now would run outside of it and be committed on its own.
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

        // The transaction this library began is no longer open on the server, or may not be: what would be
        // sent now would run in autocommit and be committed on its own. PDO's report costs no round trip.
        if ($this->transactionBegun) {
            $gone = $this->goneTransaction();
            if ($gone !== null || $this->reportsNoTransaction()) {
                [$why, $wayOut] = match (true) {
                    $gone === null => [
                        'PDO reports no transaction any more, although the one this library began has not been ended here: a DDL statement committed it implicitly, or it was ended on raw PDO.',
                        'Call rollback() - transaction() does so itself; it sends nothing and tells the end as lost -, then run the whole transaction again.',
                    ],
                    $gone[1] => [
                        'the server ended the transaction this library began when an earlier statement failed (the previous exception): rolled back, or committed implicitly (a DDL statement).',
                        'Call rollback() - it tells the end as lost -, then run the whole transaction again.',
                    ],
                    default => [
                        'an earlier statement failed inside the transaction this library began (the previous exception), and the server could not be asked whether the transaction still exists.',
                        'Call rollback() - it tells the end as lost -, then run the whole transaction again.',
                    ],
                };

                throw new QueryException(
                    message: 'Query failed',
                    previous: $gone[0] ?? null,
                    debugMessage: sprintf('Not sent: %s This statement would run outside of it, in autocommit. %s | SQL: %s', $why, $wayOut, $sql)
                );
            }

            // A statement that commits implicitly (DDL on MariaDB) would end this transaction before it runs,
            // also when it then fails, and what follows would run in autocommit: refused, the transaction stays
            $implicitCommit = $this->implicitCommitOf($sql);
            if ($implicitCommit !== null) {
                throw new ImplicitCommitException(
                    debugMessage: sprintf(
                        'Not sent: %s commits the open transaction implicitly - before it runs, also when it then fails -, and what the transaction does afterwards would run in autocommit. '
                        . 'The transaction is still open. Run the statement outside of transactions. | SQL: %s',
                        $implicitCommit,
                        $sql
                    ),
                    statement: $implicitCommit
                );
            }
        }

        $unbindable = $this->unbindableParameter($params);
        if ($unbindable !== null) {
            $this->triggerFromQuery('error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $unbindable,
                'code' => 0,
                'sqlState' => null, // nothing was sent: no database failure stands behind it
                'driverCode' => null,
            ]);

            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s | SQL: %s', $unbindable, $sql)
            );
        }

        $start = microtime(true);
        $stmt = false;

        try {
            $preparedOn = $this->pdo; // what the statement runs on, whatever foreign code inside this call does
            $stmt = $preparedOn->prepare($sql);
            if ($stmt === false) {
                throw $this->silentFailure('PDO::prepare() returned false', $this->pdo->errorInfo());
            }
            $this->statementsSent++;
            if ($this->bindAndExecute($stmt, $params) === false) {
                throw $this->silentFailure('PDOStatement::execute() returned false', $stmt->errorInfo());
            }
        } catch (Throwable $e) {
            unset($preparedOn); // held no longer than the statement holds it: the 'error' listeners may reconnect
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
                $afterExecute($stmt, $preparedOn);
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
                debugMessage: sprintf('%s | SQL: %s | Params: %s', $e->getMessage(), $sql, $this->encodeParams($params)),
                listenerFailure: true
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
        [$sqlState, $driverCode] = Codes::behind($e); // what the exception below will carry
        $this->triggerFromQuery('error', [
            'sql' => $sql,
            'params' => $params,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'sqlState' => $sqlState,
            'driverCode' => $driverCode,
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
     * statement then fails with a UniqueViolationException. MariaDbDriver knows MariaDB's code
     * for it; the default knows none.
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
     * Trigger a 'query.before', 'query' or 'error' hook of query() and keep count of the nesting: what a listener
     * runs is one level deeper than the statement it was told about. Beyond MAX_HOOK_DEPTH levels the
     * statement throws a LogicException instead - a listener that answers every statement with one of
     * its own; it passes through query() unchanged, like any other listener exception that is no
     * PDOException. The transaction.* listeners do not count here.
     *
     * @param array<string, mixed> $data
     *
     * @throws LogicException When MAX_HOOK_DEPTH listeners run one inside the other already
     */
    private function triggerFromQuery(string $event, array $data): void
    {
        if ($this->hookDepth >= self::MAX_HOOK_DEPTH) {
            throw new LogicException(sprintf(
                'Hook recursion: %d query.before, query or error listeners run one inside the other - one of them runs a statement for every statement it is told about. Guard the listener against its own statements.',
                self::MAX_HOOK_DEPTH
            ));
        }
        $this->hookDepth++;

        try {
            $this->trigger($event, $data);
        } finally {
            $this->hookDepth--;
        }
    }

    /**
     * Run query() with a step between the execution and the 'query' hook, handed the executed
     * statement. insert() reads the new id there: a 'query' listener that inserts on the same
     * connection (an audit row) would otherwise replace it before insert() reads it. An exception of
     * the step is thrown after the 'query' hook ran. Goes through query(), so a driver that
     * overrides query() and calls its parent keeps seeing every statement. The step belongs to this
     * statement: it runs when this class's query() is reached with the same SQL outside of any
     * listener this call triggers - not for other SQL an override sends first (the very same SQL
     * sent first runs it as well, and the last one's run counts), not for one a listener runs. An
     * override that changes the SQL or never calls its parent leaves it unrun; insert() then reads
     * the statement afterwards. (The named-lock methods do not go through here: their step is
     * taken by their own statement alone, see lockStatement().)
     *
     * @param array<int|string, mixed> $params
     * @param Closure(PDOStatement=, PDO=): void $afterExecute Handed the statement and the PDO object
     *                                                         it ran on; an override written
     *                                                         against 3.0 may call it without
     *
     * @throws QueryException As query()
     */
    protected function queryThen(string $sql, array $params, Closure $afterExecute): PDOStatement
    {
        // Restored afterwards: a queryThen() nested in a hook of a statement sent ahead must not drop the outer step
        $outer = $this->afterExecute;
        $this->afterExecute = [$sql, $this->hookDepth, $afterExecute, false];

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
            // A failure that does not settle the matter by itself, inside the transaction this library
            // began: asked now, before anything else is sent - a later failure would replace this one
            // here, and what a later statement did would already be done (in autocommit)
            if ($remembered !== null && $this->transactionBegun && !$this->transactionIsOver($remembered) && $this->goneTransaction() === null) {
                $this->askWhetherTheTransactionSurvived($remembered);
            }
        }
    }

    /**
     * Right after a statement failure inside the transaction begun through this driver that may
     * have ended it on the server without PDO knowing - on MariaDB a failing DDL statement commits
     * it implicitly, a lock wait timeout rolls it back under innodb_rollback_on_timeout -, the
     * driver makes PDO know (refreshTransactionState(), one round trip). Gone, or not known: held in
     * $transactionGone - nothing more is sent in what would be autocommit, and its end is 'lost',
     * never 'rolled_back'. Still there: the failure cost only its statement, nothing changes.
     * Nothing is held when foreign code inside the question (an error handler) ended the
     * transaction through this driver: that call has told the end.
     */
    private function askWhetherTheTransactionSurvived(PDOException $failure): void
    {
        $number = $this->transactionsBegun;
        $ended = $this->transactionsEnded;
        $known = $this->refreshTransactionState();
        if ($this->endedSince($ended, $number)) {
            return;
        }
        if (!$known || $this->reportsNoTransaction()) {
            $this->transactionGone = [$failure, $known, $number];
        }
    }

    /**
     * What the driver found about the transaction at hand right after a statement failed in it
     * ($transactionGone), or null: nothing held, or a finding about a transaction that has been
     * ended since.
     *
     * @return array{PDOException, bool, int}|null
     */
    private function goneTransaction(): ?array
    {
        if ($this->transactionGone === null || !$this->transactionBegun || $this->transactionGone[2] !== $this->transactionsBegun) {
            return null;
        }

        return $this->transactionGone;
    }

    /**
     * Why commit() refuses a transaction the driver found gone, or could not find, right after a
     * statement failed in it ($transactionGone): nothing was sent since.
     */
    private static function whyTheTransactionIsGone(bool $known): string
    {
        return $known
            ? 'The server reports no transaction any more: a statement failed inside it (the previous exception) and the transaction ended with it - '
                . 'rolled back by the server, or committed implicitly (a DDL statement). There is nothing left to commit; nothing was sent after the failure.'
            : 'The transaction cannot be committed: a statement failed inside it (the previous exception) and the server could not be asked whether it still exists. '
                . 'Nothing was sent after the failure. Roll back, or discard the connection.';
    }

    /**
     * Which statement failure commit() shall ask transactionEndedBy() about: one that may have
     * ended the open transaction on the server although PDO still reports it. MariaDB rolls it
     * back on a deadlock or a 1020 (and on a lock wait timeout when configured so); the server would then
     * answer COMMIT with success for a transaction that no longer holds the work.
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
     * from the failure alone (no statement is sent to find out): a MariaDB deadlock or a 1020. Until
     * that transaction is ended here (see deadTransactionPending()), query() sends nothing more -
     * every statement throws - and beginTransaction() refuses; neither 'query' nor 'error' fires
     * for the refused statement ('query.before' has; the failure itself was told to 'error').
     * False where the server only undid the statement or may have (a lock wait timeout).
     */
    protected function transactionIsOver(PDOException $failure): bool
    {
        return false;
    }

    /**
     * The leading keywords of a statement that commits the open transaction implicitly ("CREATE"),
     * or null for one that does not: query() refuses such a statement inside a transaction this
     * library began, before it is sent. None by default; MariaDbDriver names MariaDB's (see
     * ImplicitCommit). A driver of its own names its database's statements here.
     */
    protected function implicitCommitOf(string $sql): ?string
    {
        return null;
    }

    /**
     * Called by rollback() before the ROLLBACK is sent, when a statement failed inside the
     * transaction PDO reports - one whose end this driver would tell: begun through it, or begun
     * on raw PDO while no 'lost' is pending - and that failure does not settle the matter by
     * itself (transactionIsOver()): make PDO know whether the transaction still exists, and say whether
     * its report can be relied on now. For a driver whose client learns nothing about the
     * transaction from a failed statement (MariaDB: a statement with an implicit commit
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
     * committed any more, or null when it still can (the server only undid the statement). Where the failure itself does not settle it, ask the server.
     */
    protected function transactionEndedBy(PDOException $failure): ?string
    {
        return null;
    }

    /**
     * Describe the first parameter that must not be bound, or null when all can be: anything but
     * null, a scalar or a Stringable object, a RawExpression (bound, it would arrive as the text
     * of the expression), and a float INF or NAN (MariaDB has no such number; sent as text, it
     * compares as 0 - measured). A driver whose bindAndExecute() binds more (a stream as LOB)
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
            if (is_float($value) && !is_finite($value)) {
                // Sent as 'INF'/'NAN', MariaDB would read 0 in a comparison (with a warning only)
                return sprintf('Cannot bind %s (parameter %s): MariaDB has no such number and would compare it as 0', var_export($value, true), $position);
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
     * value bound as text, a boolean as '1' or '0', a finite float as its exact text (FloatText).
     * PDO alone sends false as '', which MariaDB in strict mode rejects for a numeric column, and a
     * float with PHP's `precision` setting, which cuts after 14 digits (0.1234567890123456 stored as
     * 0.12345678901235, 9007199254740994.0 as 9007199254741000 - measured). Text rather than a typed binding: PARAM_INT makes
     * MariaDB compare a text column numerically, 'abc' = 0 is true (measured on MariaDB 11.4). A
     * driver overrides this when its database needs typed bindings; what query() lets through to
     * it is decided by unbindableParameter().
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
            $bound[$key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_float($value) => FloatText::of($value), // finite: unbindableParameter() refused INF and NAN
                default => $value,
            };
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
     * @param string|null $name Ignored by MariaDB (PDO's sequence name)
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
                // Non-exception error mode: remembered all the same, like the thrown failure below
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

    /**
     * The number of the open transaction begun through this driver - the number its events carry as
     * 'transaction' -, or null when none is open: none was begun, it was committed or rolled back,
     * or its end was told as 'lost'. A transaction begun on raw PDO (getPdo()) has no number.
     */
    public function currentTransaction(): ?int
    {
        return $this->transactionBegun ? $this->transactionsBegun : null;
    }

    // =========================================================================
    // Transactions
    // =========================================================================

    /**
     * Begin a transaction.
     *
     * Triggers 'transaction.begin' hook on success, with the number and depth of the transaction. A
     * throwing hook must not leave the transaction it was told about open: a rollback is attempted on
     * raw PDO (best effort, no 'transaction.rollback' hooks; if it fails, the transaction may still be
     * open), its 'transaction.end' is told ('rolled_back', or 'lost' when the rollback failed) and the
     * hook's exception reaches the caller, a PDOException as TransactionException - unless the
     * transaction was ended meanwhile (a reconnect() of the hook, an error handler inside a PDO
     * call): what is open then is not this call's. A BEGIN that fails in PDO tells nothing: there
     * was no transaction. A hook that ends the transaction it was told about without throwing -
     * reconnect(), raw PDO, an implicit commit by a DDL statement on raw PDO - makes the call fail
     * as well, and no further hook runs: the caller would go on outside of the transaction it
     * asked for; an end behind the driver's back is told as 'lost' before the call fails. A hook
     * cannot begin, commit or roll back through this driver (ListenerTransactionException).
     *
     * A transaction begun through this driver that PDO no longer reports is told as 'lost' first.
     * Called from inside a listener of this driver, it refuses and begins nothing.
     *
     * @throws ListenerTransactionException When called from inside a listener of this driver (nothing is begun)
     * @throws TransactionException On failure, including PDO::beginTransaction() returning false (non-exception error mode), and when a transaction.begin listener ended the transaction
     * @throws Throwable What a transaction.begin listener throws (a PDOException arrives as TransactionException)
     */
    final public function beginTransaction(): void
    {
        $this->refuseInsideAListener('beginTransaction()');

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
        // behind this library's back (a lock wait timeout that ended it, raw PDO). Its end is told now, as
        // 'lost', before the next transaction takes its place. Its end listeners cannot begin one through
        // this driver (refused inside a listener); one they begin on raw PDO makes the BEGIN below fail.
        if ($this->transactionBegun && $this->reportsNoTransaction()) {
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

        // Only reachable with a non-exception error mode (allowed via 'options') or a PDO class
        // of the caller's ('pdoClass') that returns false.
        if ($begun === false) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                previous: $this->silentFailure('PDO::beginTransaction() returned false', $this->pdo->errorInfo()),
                debugMessage: 'PDO::beginTransaction() returned false'
            );
        }

        $this->transactionBegun = true;
        $number = ++$this->transactionsBegun;
        $depth = $this->depthAtBegin = ++$this->unendedBegins; // its begin is told below: from here on its end is owed, whatever happens
        $this->endedAtBegin = $this->transactionsEnded;
        $this->lostReported = false;
        $this->lostFor = null;
        $this->suspectFailure = null;
        $this->transactionGone = null;
        $this->unclearCommit = null; // of a transaction begun on raw PDO and ended there: endedSince() does not see that end

        try {
            // One by one: after a listener that ended the transaction no further one runs - it would
            // write outside of any transaction, or into one somebody began afterwards
            $payload = ['transaction' => $number, 'depth' => $depth]; // a variable, as trigger() passes one: a listener may take it by reference
            foreach ($this->hooks['transaction.begin'] ?? [] as $listener) {
                $this->asListener(static function () use ($listener, &$payload): void {
                    $listener($payload);
                });
                $this->failIfNoLongerOpen($number);
            }
        } catch (PDOException $e) {
            // The begin failed, and with it the listener's codes are the caller's: nothing went
            // through that a retry would run twice
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
     * Transaction control from inside a listener of this driver is refused before anything is done:
     * the listener runs in the middle of the caller's operation, on the caller's connection - a
     * commit() would commit what the caller is still building, a rollback() undo it, a transaction
     * begun there run inside the caller's statement. A listener that needs a transaction uses a
     * connection of its own.
     *
     * @throws ListenerTransactionException When a listener of this driver is running
     */
    private function refuseInsideAListener(string $method): void
    {
        if ($this->listenerRunning()) {
            throw new ListenerTransactionException(
                debugMessage: sprintf(
                    '%s was called from inside a listener of this driver (query.before, query, error or transaction.*): it would steer the transaction of the operation the listener is told about. Nothing was done. Use a connection of its own for a transaction in a listener.',
                    $method
                )
            );
        }
    }

    /**
     * After a 'transaction.begin' listener: the caller is about to work in the transaction it
     * asked for. A listener that ended it would leave that work outside of any transaction, or
     * inside one somebody began afterwards - the call fails instead. Ended through this driver
     * (commit(), rollback()), its end has been told; ended behind the driver's back (an implicit
     * commit by a DDL statement on MariaDB, raw PDO), it is told as 'lost' by the caller's
     * catch, with the exception thrown here. An unreadable state is not "gone".
     *
     * @throws TransactionException When the transaction with that number is no longer open
     */
    private function failIfNoLongerOpen(int $number): void
    {
        // Before PDO is read: after a statement of the listener that failed, PDO may report a
        // transaction the server has ended (on MariaDB a failing DDL statement commits it)
        $known = $this->askAfterAFailedStatement();

        if (!$this->stillTheTransaction($number)) {
            throw new TransactionException(
                message: 'Failed to begin transaction',
                debugMessage: 'A transaction.begin listener ended the transaction that was just begun through this driver (a reconnect() that discarded it, or an error handler inside a PDO call); its end was told then. A transaction that is open now was begun afterwards and is left to whoever began it.'
            );
        }

        if ($this->reportsNoTransaction()) {
            // beginTransaction() tells that end as 'lost' with this exception: the same way as for a
            // listener that threw by itself after it ended the transaction (rollbackJustBegunQuietly())
            throw new TransactionException(
                message: 'Failed to begin transaction',
                previous: $this->suspectFailure, // the listener's failed statement, when that is how it ended
                debugMessage: 'A transaction.begin listener ended the transaction that was just begun outside this driver: PDO reports no transaction any more (an implicit commit by a DDL statement, or raw PDO). What the listener wrote before may be committed.',
                listenerFailure: true
            );
        }

        if ($this->suspectFailure !== null && $this->transactionIsOver($this->suspectFailure)) {
            // The server has thrown the transaction away (a deadlock or a 1020), whatever PDO reports: the
            // caller would begin its work in a dead transaction. Undone by the catch in
            // beginTransaction(), like every begin that fails; nothing of it is committed, and the
            // failure's codes are the caller's.
            throw new TransactionException(
                message: 'Failed to begin transaction',
                previous: $this->suspectFailure,
                debugMessage: 'A statement of a transaction.begin listener failed in a way that ended the transaction that was just begun on the server (the previous exception). Nothing done in it is committed.'
            );
        }

        if (!$known) {
            // Fail-closed: the caller must not go on in what may be autocommit. Cleaned up and told
            // right here, on this answer: asked again, the driver might say something else.
            $failure = new TransactionException(
                message: 'Failed to begin transaction',
                previous: $this->suspectFailure,
                debugMessage: 'A statement failed inside a transaction.begin listener (the previous exception), and the server could not be asked whether the transaction that was just begun still exists.',
                listenerFailure: true
            );
            $this->cleanUpUnconfirmed($failure);

            throw $failure;
        }
    }

    /**
     * Before the state of the transaction that was just begun is read, after a statement of a
     * begin listener failed inside it: the driver gets to make PDO know whether the transaction
     * still exists (refreshTransactionState(), as rollback() asks before a ROLLBACK). False when
     * the driver could not find out. True when it could, and when there is nothing to ask: no
     * failed statement, a failure that settles the matter by itself (transactionIsOver()), no
     * transaction reported anyway. When the driver could not find out right after the failure
     * ($transactionGone), it is not asked again: that answer holds.
     */
    private function askAfterAFailedStatement(): bool
    {
        $gone = $this->goneTransaction();
        if ($gone !== null && !$gone[1]) {
            return false;
        }
        if ($this->suspectFailure === null || $this->transactionIsOver($this->suspectFailure) || $this->reportsNoTransaction()) {
            return true;
        }

        return $this->refreshTransactionState();
    }

    /**
     * After a throwing 'transaction.begin' listener: undo the transaction that was just begun -
     * unless it was ended meanwhile (a reconnect() of the listener, an error handler inside a PDO
     * call): its end has been told, and what is open then was begun afterwards and is not this
     * call's to roll back. Ended behind
     * the driver's back before the listener threw (PDO reports no transaction: an implicit commit
     * by a DDL statement, raw PDO), nothing is left to roll back and what the listener wrote may
     * be committed: that end is told as 'lost', with the exception the caller gets as error. After
     * a failed statement of the listener the driver is asked first, as before every ROLLBACK that
     * follows one; when it cannot find out, the ROLLBACK is sent and the end is 'lost' as well.
     * Otherwise the ROLLBACK is sent on raw PDO, without 'transaction.rollback' listeners: its begin
     * was told, so its end is - 'rolled_back' when the ROLLBACK went through, 'lost' when it did not
     * (the transaction may still be open then: its later rollback() tells no second end).
     */
    private function rollbackJustBegunQuietly(int $number, Throwable $cause): void
    {
        $known = $this->askAfterAFailedStatement(); // the listener's failed statement may have ended it without PDO knowing

        if (!$this->stillTheTransaction($number)) {
            return;
        }
        if ($this->reportsNoTransaction()) {
            $this->endLostTransaction($cause, mayStillBeOpen: false);

            return;
        }
        if (!$known) {
            // Not known whether it still exists (a listener cannot have committed it: refused inside a listener)
            $this->cleanUpUnconfirmed($cause);

            return;
        }

        $ended = $this->transactionsEnded;
        $at = $this->transactionAtHand(); // read before the ROLLBACK clears the mark
        $rolledBack = $this->rollbackRawQuietly();
        if ($this->endedSince($ended, $number)) {
            // Foreign code inside the ROLLBACK (an error handler) ended the transaction through this
            // driver: that call has told the end
            return;
        }
        if (!$rolledBack) {
            $this->endLostTransaction($cause, mayStillBeOpen: true, at: $at);

            return;
        }
        $this->transactionsEnded++;
        $this->reportTransactionEndFailures(
            self::TRANSACTION_ROLLED_BACK,
            $this->dispatchTransactionEnd(self::TRANSACTION_ROLLED_BACK, $cause, $at)
        );
    }

    /**
     * The driver could not find out whether the transaction that was just begun still exists: a
     * ROLLBACK is sent to clean up whatever is there, and the end is told as 'lost' - nothing is
     * confirmed, what the listener wrote may be committed. When that ROLLBACK fails as well, the
     * transaction may still be open: its later rollback() tells no second end. Nothing is told
     * when foreign code inside the ROLLBACK ended the transaction through this driver.
     */
    private function cleanUpUnconfirmed(Throwable $cause): void
    {
        $ended = $this->transactionsEnded;
        $number = $this->transactionsBegun;
        $at = $this->transactionAtHand(); // read before the ROLLBACK clears the mark
        $gone = $this->rollbackRawQuietly();
        if ($this->endedSince($ended, $number)) {
            // The ROLLBACK is a call into PDO, and with it into foreign code (an error handler) that
            // ended the transaction through this driver - a commit(), say, when the ROLLBACK failed:
            // that call has told the end
            return;
        }
        $this->endLostTransaction($cause, mayStillBeOpen: !$gone, at: $at);
    }

    /**
     * Roll back on raw PDO, without 'transaction.rollback' or 'transaction.end' hooks and ignoring
     * failures: the exception that caused this is more important for debugging. Called while PDO
     * does not say that no transaction is open.
     *
     * @return bool Whether the ROLLBACK went through
     */
    private function rollbackRawQuietly(): bool
    {
        $this->suspectFailure = null;
        $number = $this->transactionsBegun;

        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (Throwable) {
            // Rollback failed, but the original exception is more important for debugging
            $rolledBack = false;
        }

        // Ended here, without an event of its own - the caller tells the end: a later cleanup must
        // not report it as lost. Cleared afterwards, not before: foreign code inside the ROLLBACK
        // (an error handler) that ends the transaction through this driver tells its end with its
        // number; one that begins another keeps that one's mark.
        if ($this->transactionsBegun === $number) {
            $this->transactionBegun = false;
        }

        return $rolledBack;
    }

    /**
     * Commit the current transaction.
     *
     * Triggers 'transaction.commit' listeners after a successful commit. They cannot undo
     * the commit, so every listener runs and their failures are reported together - unless
     * a transaction left open by a listener (begun on raw PDO: a listener cannot begin one through
     * this driver) cannot be rolled back (or the connection state cannot be read): the remaining
     * listeners are then skipped and reported as failures. Then 'transaction.end' fires with
     * outcome 'committed' (also after skipped listeners; not when a 'lost' was already reported
     * for this transaction); the end listeners' failures follow the commit listeners' in the same
     * exception, in that order. Called from inside a listener of this driver, it refuses and sends
     * nothing (ListenerTransactionException). A failed commit fires no
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
     * listener is skipped and it is the first failure of the CommitHookException - so is a state that
     * cannot be read at that moment ('Connection state unknown'), fail-closed. A COMMIT that
     * failed - also with what a PDO class of the caller's or an error handler threw besides a
     * PDOException, which passes
     * unchanged - may have taken effect on a session that chains transactions, and PDO then
     * reports the next one: see noteACommitThatMayHaveTakenEffect().
     *
     * @throws ListenerTransactionException When called from inside a listener of this driver (nothing is sent)
     * @throws CommitFailedException When the commit itself failed (it may or may not have taken effect), or was refused because the server had already ended the transaction (nothing of that transaction is committed)
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed, the connection state after a commit listener could not be verified, or the connection is in a new, chained transaction
     */
    final public function commit(): void
    {
        $this->refuseInsideAListener('commit()');
        $call = ++$this->commitCalls;
        $this->deadTransactionPending(); // forgets the failure of a transaction begun on raw PDO that PDO no longer reports
        $ended = $this->transactionsEnded;
        $number = $this->transactionsBegun; // read now: foreign code inside the PDO calls below may begin another

        // What the driver found right after a failure is not asked again: the answer was taken before
        // anything else could be sent, a question now could only be less exact
        $gone = $this->goneTransaction();
        $failure = $gone[0] ?? $this->suspectFailure;
        if ($failure !== null) {
            $reason = $gone !== null ? self::whyTheTransactionIsGone($gone[1]) : $this->transactionEndedBy($failure);
            if ($reason !== null) {
                // The failure is kept: a second commit() must be refused as well; rollback() and beginTransaction() clear it
                $refusal = new CommitFailedException(
                    message: 'Failed to commit transaction',
                    previous: $failure,
                    debugMessage: $reason
                );
                $this->endFailedCommitIfGone($refusal, false, $ended, $number, null, ours: true);
                $this->thrownByCommit = [WeakReference::create($refusal), $call]; // after the listeners: a failed commit() of theirs would stand here instead

                throw $refusal;
            }
            $this->suspectFailure = null;
        }

        // For a transaction begun on raw PDO: was one open when the COMMIT was sent whose end is still
        // owed - not one whose 'lost' has been told while it may still be open: a successful commit tells
        // none then either. Read now, not after the failure: foreign code inside the COMMIT may change
        // the mark. An unreadable state is no "yes".
        try {
            $wasOpen = !$this->lostReported && $this->pdo->inTransaction();
        } catch (Throwable) {
            $wasOpen = false;
        }

        // Read now: foreign code inside the COMMIT (an error handler) may send statements through this driver
        $sentBefore = $this->statementsSent;

        try {
            $committed = $this->pdo->commit();
        } catch (PDOException $e) {
            $failure = new CommitFailedException(
                message: 'Failed to commit transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
            $this->endFailedCommitIfGone($failure, $wasOpen, $ended, $number, $sentBefore, ours: true);
            $this->thrownByCommit = [WeakReference::create($failure), $call];

            throw $failure;
        } catch (Throwable $e) {
            // Not PDO's failure - a PDO class of the caller's, an error handler: it passes unchanged, and
            // what becomes of the transaction is decided as after PDO's own failure
            $this->endFailedCommitIfGone($e, $wasOpen, $ended, $number, $sentBefore, ours: false);

            throw $e;
        }

        // Only reachable with a non-exception error mode (allowed via 'options') or a PDO class
        // of the caller's ('pdoClass') that returns false.
        if ($committed === false) {
            $failure = new CommitFailedException(
                message: 'Failed to commit transaction',
                previous: $this->silentFailure('PDO::commit() returned false', $this->pdo->errorInfo()),
                debugMessage: 'PDO::commit() returned false'
            );
            $this->endFailedCommitIfGone($failure, $wasOpen, $ended, $number, $sentBefore, ours: true);
            $this->thrownByCommit = [WeakReference::create($failure), $call];

            throw $failure;
        }

        // Committed from here on: a listener error must not look like a failed commit.
        $at = $this->transactionAtHand(); // read before the state changes: a commit listener may begin the next one
        $this->transactionBegun = false;
        $this->transactionsEnded++;
        $endOwed = !$this->lostReported; // after a reported 'lost' this transaction's end has already been told
        $this->lostReported = false;
        $this->lostFor = null;
        [$failures, $connectionInTransaction] = $this->runCommitListeners($this->chainedTransaction('COMMIT'), $at);
        if ($endOwed) {
            $failures = [...$failures, ...$this->dispatchTransactionEnd(self::TRANSACTION_COMMITTED, null, $at)];
        }

        if ($failures !== []) {
            throw new CommitHookException($failures[0], $failures, $connectionInTransaction);
        }
    }

    /**
     * A failed or refused commit leaves the transaction to the caller - unless nothing is left:
     * when PDO reports no transaction any more (the server ended it and a later statement or the
     * driver's question told PDO; a COMMIT or DDL statement on raw PDO), no rollback() could end
     * it, and its end would never be told. It ends here as 'lost', with the failure as error;
     * transaction() then finds nothing left to end. The failure is the CommitFailedException
     * commit() built ($ours: the outcome is written into it), or what a PDO class of the caller's or
     * an error handler threw instead - never written to, whatever it is.
     *
     * A COMMIT that was sent and failed ($sentBefore: $statementsSent when it was sent; null for a
     * refused commit): see noteACommitThatMayHaveTakenEffect().
     */
    private function endFailedCommitIfGone(Throwable $failure, bool $wasOpen, int $ended, int $number, ?int $sentBefore, bool $ours): void
    {
        // An end is owed for a transaction begun through this driver, and for one begun on raw PDO that
        // was open when the COMMIT was sent (its successful commit would have told an end too)
        $owed = $this->transactionBegun || $wasOpen;

        if ($sentBefore !== null) {
            $this->noteACommitThatMayHaveTakenEffect($failure, $owed, $ended, $number, $sentBefore);
        }

        if ($this->endedSince($ended, $number)) {
            // While the COMMIT, the driver's question before it or the one after it was under way,
            // foreign code (an error handler) ended the transaction through this driver. That the
            // transaction is gone is that call's doing, and it has told the end.
            return;
        }

        // An unreadable state is not "gone": it may still be open, the caller's rollback decides.
        // The driver's question may have told PDO that it is gone.
        if ($owed && $this->reportsNoTransaction()) {
            if ($ours && $failure instanceof CommitFailedException) {
                $failure->settle(self::TRANSACTION_LOST); // what the end listeners are told below
            }
            $this->endLostTransaction($failure, mayStillBeOpen: false);
        }
    }

    /**
     * A COMMIT failed - PDO's failure, or what a PDO class of the caller's or an error handler threw -
     * while PDO does not say that the transaction is gone (an unreadable state included): on a
     * session that chains transactions the transaction PDO reports may be a new one the server
     * opened for a COMMIT that took effect. The server is asked (commitMayHaveChained()); unless the
     * session does not chain, whatever ends the transaction as rolled back confirms nothing
     * ($unclearCommit), and the end is 'lost', never 'rolled_back'. The transaction stays the
     * caller's to end. The answer tells the session as it is now, not as it was at the COMMIT: it
     * counts only when no statement went through this driver since the COMMIT was sent
     * ($sentBefore) - foreign code inside it (an error handler) may have switched completion_type.
     * What such code sends on raw PDO is not seen.
     */
    private function noteACommitThatMayHaveTakenEffect(Throwable $failure, bool $owed, int $ended, int $number, int $sentBefore): void
    {
        if (!$owed || $this->reportsNoTransaction()) {
            return;
        }
        // Set before the question: until the answer is in, the COMMIT may have taken effect, and a
        // rollback() that foreign code (an error handler) runs inside the question confirms nothing.
        $earlier = $this->unclearCommit;
        $this->unclearCommit = [$failure, $ended, $number];
        if (!$this->commitMayHaveChained() && $this->statementsSent === $sentBefore) {
            $this->unclearCommit = $earlier; // what an earlier failed COMMIT of this transaction left stays
        }
    }

    /**
     * Asked after a COMMIT that failed while PDO did not say that the transaction is gone: may the
     * transaction PDO reports be a new one the session chained to a COMMIT that took effect? The
     * driver sets completion_type to NO_CHAIN when it connects, but a SET SESSION afterwards changes
     * it: under CHAIN the server opens the next transaction as soon as a COMMIT takes effect, and a
     * COMMIT that took effect and was reported as failed (its answer lost, a PDO class that throws)
     * looks like one that failed (measured on MariaDB 10.11, 11.4 and 12.3: the row is written).
     * Asked on raw PDO (no hook sees it); anything but NO_CHAIN - CHAIN, RELEASE, no answer (a
     * server that has no such variable included) - may have chained.
     */
    private function commitMayHaveChained(): bool
    {
        try {
            $answer = $this->pdo->query('SELECT @@completion_type');

            return $answer === false || $answer->fetchColumn() !== 'NO_CHAIN';
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The failure of a COMMIT of the transaction at hand that may have taken effect
     * ($unclearCommit), or null: none, or one of a transaction ended since.
     */
    private function unclearCommitAtHand(): ?Throwable
    {
        return $this->unclearCommit !== null && !$this->endedSince($this->unclearCommit[1], $this->unclearCommit[2]) ? $this->unclearCommit[0] : null;
    }

    /**
     * Whether the transaction that was at hand at an earlier moment - when $ended transactions had
     * been ended and $number begun - has been ended through this driver since: one was ended
     * before anything else was begun. What foreign code began and ended by itself afterwards is
     * not that transaction's end: a transaction begun on raw PDO that vanished (a COMMIT the
     * server answered with a rollback) still owes its 'lost'. For one begun through this driver
     * the answer is exact: nothing is begun through the driver before it is ended through it or
     * told as lost, and the count goes up at that moment - before its rollback, commit or end
     * listeners run, which may begin the next one. For one begun on raw PDO it is exact up to the
     * first transaction begun since; after a second one the count at the latest begin stands in,
     * and one that vanished counts as ended (a documented limit, see Traits\HasHooks).
     */
    private function endedSince(int $ended, int $number): bool
    {
        return ($this->transactionsBegun === $number ? $this->transactionsEnded : $this->endedAtBegin) !== $ended;
    }

    /**
     * Run every 'transaction.end' listener with the outcome and collect their failures in listener
     * order; nothing here throws. The mark of the ended transaction is cleared by the caller before
     * any listener runs. $at names the transaction (transactionAtHand(), read by the caller before
     * the state changed): one begun through this driver no longer owes its end from here on -
     * before any listener runs (a listener cannot begin one through this driver; a transaction an
     * error handler begins later is told the depth it has).
     *
     * @param array{?int, ?int} $at
     *
     * @return list<Throwable>
     */
    private function dispatchTransactionEnd(string $outcome, ?Throwable $error, array $at): array
    {
        $failures = [];
        if ($at[0] !== null) {
            $this->unendedBegins--;
        }

        foreach ($this->hooks['transaction.end'] ?? [] as $listener) {
            try {
                $this->asListener(static function () use ($listener, $outcome, $error, $at): void {
                    $listener(['outcome' => $outcome, 'error' => $error, 'transaction' => $at[0], 'depth' => $at[1]]);
                });
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
     * Hand an exception to the 'error' hook that the caller does not get as the thrown one (at most
     * as getPrevious()): the existing keys (sql '', params [], error, code, sqlState, driverCode),
     * then $context, then the exception itself. A throwing 'error' listener is ignored, and so is
     * an exception whose codes cannot be read (no hook entry then): the exception that ended the
     * transaction is more important.
     *
     * @param array<string, string> $context
     */
    private function reportQuietly(Throwable $e, array $context): void
    {
        try {
            [$sqlState, $driverCode] = Codes::behind($e); // inside the guard: $e is foreign, reading it may fail
            $this->trigger('error', [
                'sql' => '',
                'params' => [],
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'sqlState' => $sqlState,
                'driverCode' => $driverCode,
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
     * Listeners are independent: a failing listener does not stop the next one. A listener cannot
     * begin, commit or roll back through this driver (ListenerTransactionException): a transaction
     * one leaves open was begun on raw PDO, and it is rolled back before the next listener runs
     * (best effort) - directly on PDO, because dispatching transaction.rollback here would tell
     * rollback listeners that the committed transaction was rolled back; it has no end of its own
     * (no begin was told for it). If that rollback fails or leaves the connection in a transaction
     * (completion_type=CHAIN opens the next one), or inTransaction() itself fails, the connection
     * state is unknown: the remaining listeners are skipped and the transaction may still be open.
     * Nothing here throws: the commit has happened.
     *
     * Per listener: its own exception, then a LogicException if it left a transaction open
     * (previous: the rollback error, if any) or if the state could not be read (previous: that
     * error), then one LogicException per skipped listener.
     *
     * The second element is true when the connection is, or may still be, in a transaction
     * afterwards: the rollback failed or did not end the transaction, or the state could not be
     * read (fail-closed).
     *
     * @param TransactionException|null $chained Set when the connection was in a new transaction right after the COMMIT (see chainedTransaction())
     * @param array{?int, ?int} $at The committed transaction, as the listeners are told
     *
     * @return array{list<Throwable>, bool}
     */
    private function runCommitListeners(?TransactionException $chained, array $at): array
    {
        // A chained transaction is open before any listener ran: reported first, every listener skipped
        $failures = $chained !== null ? [$chained] : [];
        $cleanupError = $chained;

        foreach ($this->hooks['transaction.commit'] ?? [] as $listener) {
            if ($cleanupError !== null) {
                $failures[] = new LogicException('listener skipped: connection left in transaction', previous: $cleanupError);
                continue;
            }

            try {
                $this->asListener(static function () use ($listener, $at): void {
                    $listener(['transaction' => $at[0], 'depth' => $at[1]]);
                });
            } catch (Throwable $e) {
                $failures[] = $e;
            }

            try {
                $open = $this->pdo->inTransaction();
            } catch (Throwable $e) {
                $cleanupError = $e;
                $failures[] = new LogicException('connection state unknown after listener', previous: $e);
                continue;
            }

            if (!$open) {
                // PDO confirms no transaction: a failure the listener's statements left behind belonged to
                // a raw transaction the listener ended itself, or to none
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
                    // e.g. completion_type=CHAIN: the rollback opened the next transaction
                    $cleanupError = new TransactionException(
                        message: 'Failed to rollback transaction',
                        debugMessage: 'connection still in a transaction after PDO::rollBack()'
                    );
                }
            } catch (Throwable $e) {
                $cleanupError = $e;
            }

            $failures[] = new LogicException('listener left a transaction open', previous: $cleanupError);
            if ($cleanupError === null) {
                $this->suspectFailure = null; // the connection is clean again
            }
        }

        return [$failures, $cleanupError !== null];
    }

    /**
     * The failure to report when PDO says the connection is in a transaction right after a COMMIT
     * or ROLLBACK went through: the session chains transactions
     * (completion_type=CHAIN), so everything that follows would run in a transaction nobody began
     * and nobody commits. Null when PDO reports none. An unreadable state is reported the same way,
     * fail-closed: the COMMIT or ROLLBACK went through - its outcome is known -, but whether a chained
     * transaction is open now is not (only a PDO class of the caller's throws from inTransaction()).
     */
    private function chainedTransaction(string $statement): ?TransactionException
    {
        try {
            if (!$this->pdo->inTransaction()) {
                return null;
            }
        } catch (Throwable $e) {
            return new TransactionException(
                message: 'Connection state unknown',
                previous: $e,
                debugMessage: sprintf(
                    'The connection state could not be read right after %s went through: the session may be in a new transaction it chained (completion_type=CHAIN), which nobody commits. Discard the connection (reconnect()), or roll back and set completion_type to NO_CHAIN.',
                    $statement
                )
            );
        }

        return new TransactionException(
            message: 'Connection is in a new transaction',
            debugMessage: sprintf(
                'PDO reports a transaction right after %s: the session chains transactions (completion_type=CHAIN), which this library does not support. Roll the new transaction back and set completion_type to NO_CHAIN.',
                $statement
            )
        );
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
     * finds the transaction gone: on MariaDB a statement with an implicit commit that failed
     * has committed it, and the rows written before it are in the database. And so for a
     * transaction this library began that PDO no longer reports without a failure behind it (a
     * DDL statement that went through, raw PDO): nothing is sent - a ROLLBACK would fail for want
     * of a transaction and leave the library holding it, every statement refused -, 'lost' with
     * a TransactionException 'Transaction ended outside this library' as error. When the driver
     * cannot find out, the ROLLBACK is sent all the same, but 'lost' is told and no rollback
     * listener runs: it confirms nothing. So after a commit() of this transaction that failed on a
     * session that may chain transactions ($unclearCommit): 'lost' with the failed commit as
     * error. A transaction PDO reports right after the ROLLBACK is a
     * chained one (see chainedTransaction()): thrown after the listeners ran, or told to the
     * 'error' hook when a rollback listener threw - and so is a state that cannot be read at that
     * moment ('Connection state unknown'), fail-closed.
     *
     * @throws ListenerTransactionException When called from inside a listener of this driver (nothing is sent)
     * @throws TransactionException On failure, when the connection is in a new, chained transaction afterwards, or when a transaction.end listener failed and no rollback listener did (the first failure; all of them reach the 'error' hook)
     * @throws Throwable Re-throws a rollback listener's exception
     */
    final public function rollback(): void
    {
        $this->refuseInsideAListener('rollback()');
        // Set by rollbackQuietly(): this rollback ends a transaction that $cause ended, which reaches the caller instead.
        // Consumed here, so that a rollback() a listener calls for its own transaction is an explicit one.
        $cause = $this->automaticRollbackCause;
        $this->automaticRollbackCause = null;
        $this->deadTransactionPending(); // forgets the failure of a transaction begun on raw PDO that PDO no longer reports
        // A COMMIT of this transaction that may have taken effect: whatever ends it below confirms
        // nothing, and the failed commit is what the end names, ahead of a statement failure since -
        // it is the reason the data may be committed
        $unclear = $this->unclearCommitAtHand();

        // The server threw the transaction away and PDO knows it (a statement on raw PDO told it): there
        // is nothing to send. rollback() stays the way out: it tells the end as 'lost' - what ran on raw
        // PDO meanwhile ran outside the transaction - instead of failing for want of a transaction.
        if ($this->suspectFailure !== null && $this->transactionBegun && $this->deadTransactionPending() && $this->reportsNoTransaction()) {
            $this->endLostTransaction($cause ?? $unclear ?? $this->suspectFailure, mayStillBeOpen: false);

            return;
        }

        // The driver found the transaction gone right after a statement failed in it, or could not find
        // out ($transactionGone): no rollback can confirm anything, the end is 'lost'. Gone and PDO knows
        // it: nothing to send. Otherwise the ROLLBACK below cleans up whatever is there.
        $gone = $this->goneTransaction();
        if ($gone !== null && $this->reportsNoTransaction()) {
            $this->endLostTransaction($cause ?? $unclear ?? $gone[0], mayStillBeOpen: false);

            return;
        }

        // The transaction this library began is no longer reported by PDO, and no failure says why: a DDL
        // statement that went through committed it implicitly, or raw PDO ended it. A ROLLBACK would fail for
        // want of a transaction and leave the library holding one - every statement refused, a named lock that
        // cannot be released. rollback() stays the way out: nothing is sent, the end is 'lost' - what ran
        // before is committed.
        if ($this->transactionBegun && $this->reportsNoTransaction()) {
            $this->endLostTransaction(
                $cause ?? $unclear ?? new TransactionException(
                    message: 'Transaction ended outside this library',
                    previous: $this->suspectFailure,
                    debugMessage: 'PDO reported no transaction any more when rollback() was called: it was committed by a DDL statement or ended on raw PDO. Nothing was rolled back; what ran in it may be committed.'
                ),
                mayStillBeOpen: false
            );

            return;
        }

        // A statement failed inside the transaction PDO reports, and the server may have ended it
        // without the client knowing: a ROLLBACK that "succeeds" then would be taken for the
        // confirmation that nothing is committed. The driver gets to ask (refreshTransactionState());
        // when PDO reports no transaction afterwards, the end is told as 'lost' - no rollback is
        // confirmed, and on MariaDB what a statement with an implicit commit committed on its
        // way to failing is in the database. When PDO reports none already, nothing is asked: the
        // ROLLBACK fails as before.
        $unconfirmed = $gone[0] ?? null;
        if ($gone === null && $this->suspectFailure !== null && $this->reportsATransactionThatOwesItsEnd() && !$this->transactionIsOver($this->suspectFailure)) {
            $failure = $this->suspectFailure;
            $ended = $this->transactionsEnded;
            $number = $this->transactionsBegun;
            $known = $this->refreshTransactionState();
            if ($this->endedSince($ended, $number)) {
                // The question is a call into PDO, and with it into foreign code (an error handler)
                // that ended the transaction through this driver: nothing is left for this call. A
                // statement that failed in there changes nothing.
                return;
            }
            if ($this->reportsNoTransaction()) {
                $this->endLostTransaction($cause ?? $unclear ?? $failure, mayStillBeOpen: false);

                return;
            }
            if (!$known) {
                // The driver could not find out: what PDO reports is as stale as before
                $unconfirmed = $failure;
            }
        }

        // The transaction PDO reports may be a new one the server opened for a COMMIT that took effect:
        // the ROLLBACK cleans up, but confirms nothing
        $unconfirmed = $unclear ?? $unconfirmed;

        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                previous: $e,
                debugMessage: $e->getMessage()
            );
        }

        // Only reachable with a non-exception error mode (allowed via 'options') or a PDO class
        // of the caller's ('pdoClass') that returns false.
        if ($rolledBack === false) {
            throw new TransactionException(
                message: 'Failed to rollback transaction',
                previous: $this->silentFailure('PDO::rollBack() returned false', $this->pdo->errorInfo()),
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
        $at = $this->transactionAtHand(); // read before the state changes: a rollback listener may begin the next one
        $this->transactionBegun = false;
        $this->transactionsEnded++;
        $this->suspectFailure = null;
        $this->transactionGone = null;
        $endOwed = !$this->lostReported; // after a reported 'lost' this transaction's end has already been told
        $this->lostReported = false;
        $this->lostFor = null;
        $chained = $this->chainedTransaction('ROLLBACK'); // read before a listener can begin a transaction of its own
        if ($this->settlingCommit !== null && $cause === $this->settlingCommit) {
            // What the end listeners are told below: the transaction whose commit is settled here is
            // the one transaction() began, and that one owes its end
            $this->settlingCommit->settle(self::TRANSACTION_ROLLED_BACK);
            $this->settlingCommit = null; // written once: a listener that throws this exception again inside a transaction of its own cannot have it rewritten
        }
        $pending = null;
        try {
            $this->trigger('transaction.rollback', ['transaction' => $at[0], 'depth' => $at[1]]);
        } catch (PDOException $e) {
            $pending = new TransactionException(
                message: 'Failed to rollback transaction',
                previous: $e,
                debugMessage: $e->getMessage(),
                listenerFailure: true
            );
        } catch (Throwable $e) {
            $pending = $e;
        }
        // A listener's exception takes precedence; without one the chained transaction is what the caller must
        // know. Whenever it does not reach the caller, the 'error' hook is told.
        $listenerFailed = $pending !== null;
        $pending ??= $chained;

        $failures = $endOwed ? $this->dispatchTransactionEnd(self::TRANSACTION_ROLLED_BACK, $cause, $at) : [];
        if ($cause !== null) {
            // Automatic rollback: $cause reaches the caller. End listener failures only reach the
            // 'error' hook, and so does the chained transaction; a rollback listener's exception is
            // dropped, as it always was.
            $this->reportTransactionEndFailures(self::TRANSACTION_ROLLED_BACK, $failures);
            if ($chained !== null) {
                $this->reportQuietly($chained, ['outcome' => self::TRANSACTION_ROLLED_BACK]);
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
                debugMessage: $failures[0]->getMessage(),
                listenerFailure: true
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
     * - the transaction could not be started (BEGIN failed, a transaction.begin listener threw - a
     *   ListenerTransactionException among others, when it tried to steer the transaction -, or such
     *   a listener ended the transaction it was told about without throwing: reconnect(), raw PDO):
     *   the callback did not run; after a throwing listener a rollback is attempted (best effort);
     *   the exception is re-thrown, a PDOException from the listener as TransactionException;
     * - the callback threw: rollback attempted, the callback's exception is re-thrown
     *   (best effort: if the rollback fails, the transaction may still be open). Measured on
     *   MariaDB 11.4 with mysqlnd: after a deadlock or a 1020 (the server rolled the transaction back) and
     *   after a lock wait timeout (the server rolled back only the statement) PDO still reports the
     *   transaction, so the rollback is sent, the transaction.rollback listeners run and
     *   transaction.end reports 'rolled_back'; after a lost connection the rollback fails, no
     *   rollback listener runs, PDO still reported the transaction, and transaction.end reports 'lost';
     * - the callback swallowed a statement error that ended the transaction on the server
     *   (a deadlock or a 1020, or a lock wait timeout under innodb_rollback_on_timeout): the commit is
     *   refused before it is sent and the CommitFailedException is thrown, instead of a COMMIT the
     *   server answers with success. While PDO still reports the transaction the rollback follows
     *   and transaction.end reports 'rolled_back'; once PDO knows that the transaction is gone - from
     *   a statement on raw PDO after a deadlock or a 1020, or from the question the driver asks right
     *   after any other failure (the lock wait timeout) - nothing is left to roll back and
     *   transaction.end reports 'lost'. Through this library nothing is sent between a deadlock or a
     *   1020 and the rollback, nor after a failure that the question found had ended the transaction
     *   or could not settle, nor while PDO reports no transaction (a DDL statement committed it
     *   implicitly, failing or not): the statement would run in autocommit and be committed on its
     *   own, it throws a QueryException instead;
     * - the commit failed: a rollback is attempted when PDO still reports the transaction, the
     *   CommitFailedException is re-thrown; transaction.end reports 'rolled_back' when that rollback
     *   succeeded (nothing was committed) and 'lost' when it failed too (the commit may or may not
     *   have taken effect), with the commit's exception as error. On a session that may chain
     *   transactions (see commit()) the rollback confirms nothing: 'lost' as well, no rollback listener. When PDO reports no transaction
     *   after the failed commit, transaction.end reports 'lost' as well, fail-closed: that is what
     *   a callback leaves behind that committed itself with a raw COMMIT or a DDL statement (the
     *   data is committed, PDO::commit() then fails with "no active transaction").
     *   In both commit cases the exception's $outcome is the outcome transaction.end reported:
     *   only 'rolled_back' says that nothing is committed;
     * - the callback ended the transaction itself through this driver (commit() or rollback()), or
     *   an error handler inside a PDO call did (a listener cannot: refused inside a listener): this
     *   method ends only the transaction it began. Whatever is open afterwards - begun by the
     *   callback through this driver or on raw PDO, or by a listener on raw PDO - is neither
     *   committed nor rolled back here. A callback that returns gets a CommitFailedException with
     *   outcome 'lost' and no COMMIT is sent; one that throws gets its exception re-thrown;
     * - the callback calls transaction() or beginTransaction() again: there are no nested
     *   transactions (no savepoints) - the inner call throws a TransactionException 'Failed to begin
     *   transaction' ("There is already an active transaction") before its callback runs; left to
     *   escape, it rolls the outer transaction back like any other exception of the callback;
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
            $this->rollbackQuietly($own, $e);
            throw $e;
        }

        $this->commitOwnTransaction($own);

        return $result;
    }

    /**
     * The number of the transaction a beginTransaction() call has just begun, for
     * stillTheTransaction(): beginTransaction() returns only while that transaction is the one
     * at hand.
     */
    private function transactionJustBegun(): int
    {
        return $this->transactionsBegun;
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
    private function stillTheTransaction(int $number): bool
    {
        return $this->transactionBegun && $this->transactionsBegun === $number;
    }

    /**
     * Commit a transaction this driver began, rolling back only if the commit itself failed. That
     * commit's CommitFailedException leaves with an outcome: the one the end listeners were told
     * with it as error - the transaction is this call's, so it still owes its end, and whatever
     * becomes of the rollback, an end is told -, or 'lost' when the callback or a listener had
     * ended the transaction before (nothing is sent then). This is the only place that lets
     * rollback() and endLostTransaction() write an outcome: what a callback or a listener throws
     * - a commit() of its own that failed, an exception of another connection or of an earlier
     * transaction - is never written to.
     *
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     * @throws Throwable Re-throws the commit exception after rollback
     */
    private function commitOwnTransaction(int $own): void
    {
        if (!$this->stillTheTransaction($own)) {
            // Nothing is sent: a COMMIT would commit somebody else's transaction, or fail for want of one
            $refusal = new CommitFailedException(
                message: 'Failed to commit transaction',
                debugMessage: 'Not committed: the transaction this call began has already been ended through this driver (a commit() or rollback() inside the callback or a listener), and its end was told then. A transaction that is open now was begun afterwards and is left to whoever began it.'
            );
            $refusal->settle(self::TRANSACTION_LOST); // no rollback of this call's work is confirmed here

            throw $refusal;
        }

        $call = $this->commitCalls + 1; // the number the commit() below is given

        try {
            $this->commit();
        } catch (CommitHookException $e) {
            // One that commit() built says: committed. The transaction then no longer owes its end
            // (commit() already rolled back, best effort, what a listener left open), and
            // rollbackQuietly() does nothing. One that came through commit() from elsewhere - thrown
            // by the commit() of a caller's PDO class, or by an error handler - says nothing about
            // this transaction: it is ended like after any other failure.
            $this->rollbackQuietly($own, $e);

            throw $e;
        } catch (Throwable $e) {
            // The commit itself failed; the transaction may still be open. While the
            // transaction still owes its end, rollbackQuietly() tells one - and whoever tells it
            // takes the failed commit and writes the outcome into it. When the failed or refused
            // commit found the transaction gone, it has told the end itself (endFailedCommitIfGone()):
            // nothing is left to end, and a transaction PDO reports now is one an end listener began on raw PDO.
            $failed = $this->thrownByThisCommit($e, $call);
            $this->settlingCommit = $failed;
            $this->rollbackQuietly($own, $e);
            $this->settlingCommit = null; // whatever became of it: taken, or left by a rollback that had nothing to end
            if ($failed !== null && $failed->outcome === null) {
                // No end was told with it: the transaction was ended through this driver while the
                // COMMIT or the ROLLBACK after it was under way (an error handler that rolled back,
                // or ran a transaction of its own). Nothing confirms what became of the commit.
                $failed->settle(self::TRANSACTION_LOST);
            }
            throw $e;
        }
    }

    /**
     * $e as the failure the commit() call with the number $call has thrown, or null: nothing else
     * that may arrive from that call - what a driver hook or an error handler throws, the failure
     * of a commit() an error handler issued itself in the middle of it, an earlier commit's - is
     * the failed commit of transaction() or updateMultiple().
     */
    private function thrownByThisCommit(Throwable $e, int $call): ?CommitFailedException
    {
        if ($this->thrownByCommit === null) {
            return null;
        }
        [$failure, $thrownBy] = $this->thrownByCommit;
        $failure = $failure->get(); // null once nothing else holds it: then it is not $e either

        return $e === $failure && $thrownBy === $call ? $failure : null;
    }

    /**
     * End the transaction transaction() or updateMultiple() began - the one with the number $own -
     * after $cause ended its work, ignoring failures: $cause is more important for debugging and
     * reaches the caller unchanged. If PDO still reports the transaction, ROLLBACK is sent; when
     * it succeeds the 'transaction.rollback' listeners run and 'transaction.end' reports
     * 'rolled_back'. When the rollback fails, PDO no longer reports the transaction, or the state
     * cannot be read, 'transaction.end' reports 'lost'. Nothing is sent and nothing is told once
     * that transaction no longer owes its end: every call into PDO may run foreign code (an error
     * handler for a PDO warning) that ends it through this driver, and that call has told the end.
     */
    private function rollbackQuietly(int $own, Throwable $cause): void
    {
        if (!$this->stillTheTransaction($own)) {
            return;
        }

        try {
            if ($this->pdo->inTransaction()) {
                $this->automaticRollbackCause = $cause; // consumed by rollback() on entry
                $this->rollback();
            } else {
                $this->endLostTransaction($cause, mayStillBeOpen: false);
            }
        } catch (Throwable) {
            // $cause is more important for debugging. The rollback itself failed, or the state could
            // not be read: the transaction may still be open - unless it was ended meanwhile.
            if ($this->stillTheTransaction($own)) {
                $this->endLostTransaction($cause, mayStillBeOpen: true);
            }
        }
    }

    /**
     * 'transaction.end' with outcome 'lost': the transaction ended without a commit by this driver
     * and no rollback could be confirmed. When it may in fact still be open (the rollback failed,
     * the state could not be read), its later commit()/rollback() tells no second end. $at names
     * the transaction when the caller has already cleared its mark; otherwise it is read here.
     *
     * @param array{?int, ?int}|null $at
     */
    private function endLostTransaction(Throwable $cause, bool $mayStillBeOpen, ?array $at = null): void
    {
        $at ??= $this->transactionAtHand(); // before the state changes
        $this->transactionBegun = false;
        $this->transactionsEnded++;
        $this->lostReported = $mayStillBeOpen;
        $this->lostFor = $mayStillBeOpen ? $at : null;
        if (!$mayStillBeOpen) {
            $this->suspectFailure = null; // gone with the transaction; one that may still be open keeps its commit refused
        }
        $this->transactionGone = null; // about this transaction alone: kept, it would only hold the failed statement
        if ($this->settlingCommit !== null && $cause === $this->settlingCommit) {
            $this->settlingCommit->settle(self::TRANSACTION_LOST); // what the end listeners are told below
            $this->settlingCommit = null; // written once, as in rollback()
        }
        $this->reportTransactionEndFailures(
            self::TRANSACTION_LOST,
            $this->dispatchTransactionEnd(self::TRANSACTION_LOST, $cause, $at)
        );
    }

    /**
     * The transaction the events of this moment are about, as they name it: the number and depth
     * of the one begun through this driver that owes its end, or of the one a 'lost' was told for
     * while it may still be open; [null, null] for one begun on raw PDO - no begin was told for it.
     * Read before the state changes: a listener may begin the next one.
     *
     * @return array{?int, ?int}
     */
    private function transactionAtHand(): array
    {
        if ($this->transactionBegun) {
            return [$this->transactionsBegun, $this->depthAtBegin];
        }

        return $this->lostReported ? ($this->lostFor ?? [null, null]) : [null, null];
    }

    // =========================================================================
    // Connection
    // =========================================================================

    /**
     * Discard the connection and continue on a new one, opened with the settings the driver was
     * created with (the same DSN, credentials, options and pdoClass). For a connection that cannot
     * be cleaned up any more: a COMMIT that failed, a transaction a listener left open, a state that
     * cannot be read, a session that chains transactions.
     *
     * The new connection is opened first: when that fails, a ConnectionException reaches the caller
     * and nothing has changed - the old connection is still in place. Otherwise a ROLLBACK is sent
     * on the old connection when it reports a transaction (best effort: it frees that transaction's
     * locks even while someone still holds the old PDO object), the driver continues on the new
     * one, and a transaction whose end was still owed ends as 'lost' (its 'transaction.end' tells
     * its number, with a TransactionException as error; no 'transaction.rollback' listener runs).
     * inTransaction() is false afterwards - unless an end listener of that 'lost' began a
     * transaction, or foreign code inside a call into the old PDO object (an error handler)
     * reconnected itself and began one on its connection: those stay -; the numbers of the
     * transactions go on counting.
     *
     * What belonged to the old session is gone: settings made with SQL (SET SESSION ...; give them
     * to the connection as options instead - Pdo\Mysql::ATTR_INIT_COMMAND runs on every connect),
     * temporary tables - and what was done to the old PDO object after the driver created it:
     * attributes set with getPdo()->setAttribute(), a PDO object a subclass put in its place.
     * getPdo() returns the new PDO object; a reference to the old one keeps the old connection
     * open until it is dropped. A persistent connection (PDO::ATTR_PERSISTENT) cannot be discarded: PDO would hand
     * the same one back. The driver keeps nothing of the old connection (but see the error handler
     * below); others may: a PDOStatement of it; an exception whose trace holds one, directly or
     * through another exception, while arguments are kept - a failed statement's exception, the
     * error the end listeners of a refused commit are told, the CommitFailedException its caller
     * gets, and the error of the 'lost' told here when reconnect() is called from where such an
     * exception is an argument (an 'error' listener): an end listener that keeps it keeps the old
     * connection; and the call reconnect() is made from (query() holds its statement while its
     * 'query' and 'error' listeners run - after a failed prepare there is none -, an error handler
     * runs inside the call into PDO). An error
     * handler that reconnects in the middle of a statement and begins a transaction there: the
     * statement's failure may be remembered for the new transaction, and the old statement with
     * it, until that transaction ends (a documented limit).
     *
     * A transaction begun through this driver that is still open (currentTransaction() is not
     * null) would go with the old session - nothing of it committed - and what the caller does next
     * would run in autocommit on the new connection: reconnect() refuses with a
     * TransactionOpenException and nothing changes, unless $dropTransaction gives it up knowingly
     * (its end is then 'lost', as above). Only this driver's bookkeeping decides, never PDO's
     * report: after the end - a chained transaction PDO reports, a state that cannot be read, a
     * 'lost' told while the transaction may still be open, a transaction begun on raw PDO -
     * reconnect() is the way out and needs no option.
     *
     * Named locks taken with namedLock() go with the old session: while this driver holds one
     * (heldNamedLocks()), reconnect() refuses with a NamedLocksHeldException and nothing changes.
     * Release them first, or pass $dropNamedLocks to give them up knowingly - on a connection that
     * is gone, releaseNamedLock() cannot reach the server any more. Locks taken in raw SQL are not
     * seen until namedLock() learns of them. Between a named-lock statement's run and the return
     * of its method - a 'query' listener of it calls reconnect() -, reconnect() refuses as well,
     * $dropNamedLocks or not: the answer and what was recorded belong to the session the
     * statement ran on (before it, in 'query.before', the statement simply runs on the new
     * session; after its failure, in 'error', nothing was recorded). These refusals come first;
     * nothing has changed.
     *
     * @param bool $dropNamedLocks Give up the named locks this driver holds, with the old session
     * @param bool $dropTransaction Give up the open transaction begun through this driver, with the old session: its end is told as 'lost'
     *
     * @throws NamedLocksHeldException When this driver holds named locks and $dropNamedLocks is false (nothing has changed)
     * @throws TransactionOpenException When a transaction begun through this driver is open and $dropTransaction is false (nothing has changed)
     * @throws ConnectionException When called after a named-lock statement ran, before its method returned (from a listener), the new connection cannot be opened (the old one stays), the driver was not created with its connection settings (a custom driver that sets $pdo itself), or the connection is persistent
     */
    public function reconnect(bool $dropNamedLocks = false, bool $dropTransaction = false): void
    {
        if ($this->lockStatementsRunning > 0) {
            // A 'query' listener of a named-lock statement that ran: its answer and what was recorded belong to that session
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'reconnect() after a named-lock statement ran, before its method returned (from a listener): the answer and what was recorded belong to the session it ran on. Reconnect after the call.'
            );
        }

        if ($this->heldNamedLocks !== [] && !$dropNamedLocks) {
            $names = array_values($this->heldNamedLocks);

            throw new NamedLocksHeldException(
                message: 'Database connection failed',
                debugMessage: sprintf(
                    'reconnect() would give up the named locks this driver holds (%s) with the old session. Release them first, or call reconnect(dropNamedLocks: true) to give them up knowingly.',
                    implode(', ', array_map(static fn (string $name): string => '"' . $name . '"', $names))
                ),
                lockNames: $names
            );
        }

        // By the library's bookkeeping alone - a transaction begun through this driver whose end is still
        // owed -, never by PDO's report: after the end (told 'lost' while it may still be open, an unreadable
        // state, a transaction begun on raw PDO) reconnect() is the way out and must stay one
        if ($this->transactionBegun && !$dropTransaction) {
            throw new TransactionOpenException(
                debugMessage: sprintf(
                    'reconnect() would discard transaction %d, begun through this driver and still open: nothing of it would be committed, and what follows would run in autocommit on the new connection. End it first (commit() or rollback()), or call reconnect(dropTransaction: true) to give it up knowingly (its end is then told as lost).',
                    $this->transactionsBegun
                )
            );
        }

        if ($this->connector === null) {
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'reconnect() needs the connection settings the driver was created with; this driver set its PDO object itself (a driver that does not pass a connector to AbstractDriver::__construct())'
            );
        }

        if ($this->pdo->getAttribute(PDO::ATTR_PERSISTENT) === true) {
            // PDO would hand the same connection back for the same settings: nothing would be discarded
            throw new ConnectionException(
                message: 'Database connection failed',
                debugMessage: 'reconnect() cannot discard a persistent connection (PDO::ATTR_PERSISTENT): PDO hands the same connection back for the same settings. Open the driver without that option to use reconnect().'
            );
        }

        $new = ($this->connector)(); // first: when it fails, nothing has changed

        // Every call into the old PDO object may run foreign code (an error handler, a PDO class of the
        // caller's) that uses this driver: the old connection is held here, and only it is rolled back
        $old = $this->pdo;
        // A transaction begun on raw PDO whose end this driver would tell, read before anything is sent
        $rawOwed = !$this->transactionBegun && $this->reportsATransactionThatOwesItsEnd();
        $ended = $this->transactionsEnded;
        try {
            // Before the end listeners run: the old transaction's locks must not outlive it while they
            // work on the new connection
            if ($old->inTransaction()) {
                $old->rollBack();
            }
        } catch (Throwable) {
            // The old connection is discarded either way
        }
        if ($this->pdo !== $old) {
            // Foreign code inside one of those calls reconnected itself: the old connection is discarded
            // already, and what it began on its new one stays. The one opened here is dropped.
            return;
        }
        unset($old); // the old connection closes at the swap below, before any end listener runs - unless someone else holds it
        // So does what the driver keeps of it: a remembered failure holds the failed statement in its
        // trace (with arguments kept), and the statement holds its PDO object. A refused commit the
        // driver holds only weakly ($thrownByCommit).
        $this->suspectFailure = null;
        $this->transactionGone = null;
        $this->unclearCommit = null; // the new connection opened no transaction: none that PDO reports there is the one whose COMMIT failed

        // What is owed is read now, not before: foreign code inside that ROLLBACK (an error handler)
        // may have ended the transaction through this driver - that call told its end - or begun
        // another one on the old connection, which goes with it as well
        if ($this->transactionBegun) {
            $at = $this->transactionAtHand();
        } elseif ($rawOwed && $this->transactionsEnded === $ended) {
            $at = [null, null];
        } else {
            $at = null;
        }

        $this->pdo = $new;
        $this->heldNamedLocks = []; // gone with the old session
        if ($at === null) {
            // Nothing owes its end; a 'lost' told while the transaction might still be open is gone
            // with the old connection
            $this->lostReported = false;
            $this->lostFor = null;

            return;
        }
        $this->endLostTransaction(
            new TransactionException(
                message: 'Transaction discarded with its connection',
                debugMessage: 'reconnect() discarded the connection this transaction was open on. Nothing of it was committed by this library; a ROLLBACK was sent on the old connection (best effort), and the server rolls back what is left when that connection closes.'
            ),
            mayStillBeOpen: false,
            at: $at
        );
    }

    // =========================================================================
    // Named Locks
    // =========================================================================

    /**
     * Take a named lock (GET_LOCK()): a lock on a name, not on rows, held by this connection until
     * releaseNamedLock() or the end of the connection - COMMIT and ROLLBACK do not release it.
     * reconnect() refuses while it is held (see heldNamedLocks()). For "at most one at a time" across
     * requests and processes: `if ($db->namedLock('login:' . $id)) { try { ... } finally {
     * $db->releaseNamedLock('login:' . $id); } }`.
     *
     * The name is prefixed with the configured database and ":" ("app_db:login:7"): the server
     * keeps one namespace for all its databases, a shared server included. That form is part of
     * the contract: another program that asks the server about the lock (IS_USED_LOCK()) uses it.
     * A configured database whose name holds a ":" gets no prefix - the lock "c" of "a:b" and the
     * lock "b:c" of "a" would be one name -, and the named-lock methods throw (the connection
     * itself works). A driver that does not extend MariaDbDriver names its prefix in namedLockPrefix(). On the server it is
     * compared as written - case, accents and spaces count - and may have 192 bytes with the
     * prefix (MariaDB refuses a longer one: error 1059). Measured on 10.11, 11.4 and 12.3.
     *
     * MariaDB lets a connection take a lock it holds a second time and counts the holds - one
     * release would then leave the lock held. This method refuses that instead: taking a lock this
     * connection already holds throws a NamedLockReentryException, a QueryException of its own
     * class (ask isNamedLockHeld() first where that can happen).
     *
     * A persistent connection does not end with the request: a lock a request dies holding stays
     * held until that pooled connection ends, and the next request on it holds it (isNamedLockHeld()
     * true, namedLock() throws the reentry exception). Two connections that wait for each other's lock end
     * in a deadlock: MariaDB fails one GET_LOCK() with 1213 and keeps the transaction (measured),
     * but this library takes 1213 for a deadlock that ended it and refuses further statements
     * until rollback() - fail-closed. Take named locks outside transactions or in one order, and
     * after a deadlock or a 1020 roll back before releaseNamedLock() (refused until then).
     *
     * heldNamedLocks() follows the answers, recorded where the statement ran - before its 'query'
     * listeners, so that what they take or release counts after it: the name counts as held when
     * the server answers that this connection holds it (taken, or held already), and no longer
     * when it answers that another one does; an error (NULL) and a statement that did not run (it
     * failed, a 'query.before' listener threw) change nothing. An answer that cannot be read after
     * the statement ran counts the name - the lock may have been taken - and throws. A 'query'
     * listener that throws after the statement ran does not undo what was recorded. reconnect() is
     * refused from a 'query' listener of the statement (see lockStatement()).
     *
     * @param string $name The lock's name, without the prefix
     * @param int $timeout Seconds to wait while another connection holds it (0: do not wait)
     *
     * @throws NamedLockReentryException When this connection holds the lock already
     * @throws QueryException When the name is empty or holds a NUL byte, the timeout is negative, the driver names no lock prefix, the server answers NULL (an error such as a killed thread), the answer cannot be read, or the connection was replaced while the statement ran
     * @throws Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded: passed on unchanged (the name counts all the same)
     *
     * @return bool True when taken, false when another connection held it beyond the timeout
     */
    public function namedLock(string $name, int $timeout = 0): bool
    {
        $lock = $this->lockName('namedLock', $name);
        if ($timeout < 0) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('namedLock() takes a timeout of 0 or more seconds, not %d (MariaDB answers a negative one with NULL)', $timeout)
            );
        }

        // One statement: whether this connection holds it already, and if not the attempt itself
        $taken = $this->lockStatement('namedLock', 'SELECT CASE WHEN IS_USED_LOCK(?) = CONNECTION_ID() THEN -1 ELSE GET_LOCK(?, ?) END', [$lock, $lock, $timeout], function (mixed $taken, bool $known) use ($name): void {
            if (!$known || $taken === 1 || $taken === -1) {
                $this->heldNamedLocks[$name] = $name;
            } elseif ($taken === 0) {
                unset($this->heldNamedLocks[$name]);
            }
        });

        return match ($taken) {
            1 => true,
            0 => false,
            -1 => throw new NamedLockReentryException(
                message: 'Query failed',
                debugMessage: sprintf('namedLock(): this connection holds "%s" already; MariaDB would count a second hold, and one release would not free it. Release it first, or ask isNamedLockHeld().', $name),
                lockName: $name
            ),
            default => throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('namedLock(): GET_LOCK() answered %s for "%s" - an error on the server, not a busy lock', var_export($taken, true), $name)
            ),
        };
    }

    /**
     * Release a named lock this connection holds (RELEASE_LOCK()).
     *
     * @param string $name The lock's name, without the prefix (see namedLock())
     *
     * @throws QueryException When the name is empty or holds a NUL byte, the driver names no lock prefix, the query fails, its answer cannot be read, or the connection was replaced while the statement ran
     * @throws Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded: passed on unchanged
     *
     * @return bool True when released; false when this connection did not hold it - another one
     *              does, or nobody does (released before, given up with a connection). Either
     *              way - also when the answer cannot be read - the name no longer counts as held
     *              (heldNamedLocks()), recorded where the statement ran; when it did not run, it
     *              still does.
     */
    public function releaseNamedLock(string $name): bool
    {
        return $this->lockStatement('releaseNamedLock', 'SELECT RELEASE_LOCK(?)', [$this->lockName('releaseNamedLock', $name)], function () use ($name): void {
            unset($this->heldNamedLocks[$name]);
        }) === 1;
    }

    /**
     * Whether this connection holds the named lock, asked on the server (IS_USED_LOCK()).
     *
     * @param string $name The lock's name, without the prefix (see namedLock())
     *
     * @throws QueryException When the name is empty or holds a NUL byte, the driver names no lock prefix, the query fails, its answer cannot be read, or the connection was replaced while the statement ran
     * @throws Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded: passed on unchanged
     */
    public function isNamedLockHeld(string $name): bool
    {
        return $this->lockStatement('isNamedLockHeld', 'SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [$this->lockName('isNamedLockHeld', $name)]) === 1;
    }

    /**
     * Which connection holds the named lock, asked on the server (IS_USED_LOCK()): its connection
     * id (CONNECTION_ID() on that connection, the Id of SHOW PROCESSLIST), this connection's own
     * included; null when nobody holds it.
     *
     * @param string $name The lock's name, without the prefix (see namedLock())
     *
     * @throws QueryException When the name is empty or holds a NUL byte, the driver names no lock prefix, the query fails, its answer cannot be read, the server answers something else, or the connection was replaced while the statement ran
     * @throws Throwable What an error handler throws for reading the answer that is not about a failure PDO recorded: passed on unchanged
     */
    public function namedLockHolder(string $name): ?int
    {
        $holder = $this->lockStatement('namedLockHolder', 'SELECT IS_USED_LOCK(?)', [$this->lockName('namedLockHolder', $name)]);
        if ($holder !== null && !is_int($holder)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('namedLockHolder(): IS_USED_LOCK() answered %s for "%s", neither a connection id nor NULL', var_export($holder, true), $name)
            );
        }

        return $holder;
    }

    /**
     * The named locks this driver holds as far as it knows: the names namedLock() was answered
     * this connection holds - or got no readable answer for after its statement ran -, and
     * releaseNamedLock() has not run for since, as passed (without the prefix), in the order taken. Recorded where each statement ran (see namedLock()). Not asked
     * on the server: a lock taken in raw SQL is not in it until namedLock() is answered that this
     * connection holds it, and one the server ended (the connection died, the session was killed)
     * still is - isNamedLockHeld() asks the server. reconnect() refuses while the list is not
     * empty.
     *
     * @return list<string>
     */
    public function heldNamedLocks(): array
    {
        return array_values($this->heldNamedLocks);
    }

    /**
     * The answer of a named-lock statement - asked of the session the lock belongs to. The statement
     * goes through this class's query() itself, not through a query() or queryThen() a driver puts
     * in its place: the answer is read, and $settle told it, right where the statement ran, before
     * its 'query' listeners, so that what they do with locks counts after it (the hooks still see
     * every statement). An answer that cannot be read - the statement ran - is told as unknown,
     * then thrown. From the moment the statement ran until the method returns, reconnect() refuses:
     * the answer and what was recorded speak of that session. Before it ('query.before') a
     * reconnect() is the statement's new session - nothing here holds the old one open -; after a
     * failure ('error') nothing was recorded. A driver that puts another PDO object in place
     * meanwhile ($this->pdo, from a listener) gets an exception instead of an answer that may speak
     * of another session.
     *
     * @param list<mixed> $params
     * @param (Closure(mixed, bool): void)|null $settle Told the answer and whether it is known
     *
     * @throws QueryException When the query fails, its answer cannot be read, or the connection was replaced while it ran
     * @throws Throwable What a listener throws, and what an error handler throws for a failed read that PDO did not record: passed on unchanged (the read is recorded as unknown)
     */
    private function lockStatement(string $method, string $sql, array $params, ?Closure $settle = null): mixed
    {
        $ranOn = null;
        $refusing = false;
        $answer = null;
        $read = function (PDOStatement $stmt, PDO $preparedOn) use (&$ranOn, &$refusing, &$answer, $settle, $method): void {
            $ranOn = $preparedOn; // the session the statement ran on
            $this->lockStatementsRunning++;
            $refusing = true;
            if ($ranOn !== $this->pdo) {
                return; // replaced while it ran (foreign code inside this call): nothing recorded, the method throws
            }
            $unread = null;
            try {
                $answer = $stmt->fetchColumn();
            } catch (Throwable $e) {
                $answer = false;
                $unread = $e;
            }
            if ($answer !== false) {
                if ($settle !== null) {
                    $settle($answer, true);
                }

                return;
            }

            // The statement ran, its answer is unknown: told as such - a lock it may have taken counts
            if ($settle !== null) {
                $settle(null, false);
            }
            $failure = $unread === null
                ? $this->silentFailure('PDOStatement::fetchColumn() returned false', $stmt->errorInfo())
                : $this->failureBehind($unread, $stmt->errorInfo());
            if ($failure === null) {
                throw $unread; // an error handler's own exception, not about the read: unchanged
            }

            throw new QueryException(
                message: 'Query failed',
                previous: $failure,
                debugMessage: sprintf('%s(): the statement ran, but its answer could not be read', $method)
            );
        };

        // This class's query(), with the step set for it - as queryThen() does, without going through
        // a driver's own queryThen() or query() - and taken by it alone: foreign code inside that
        // call (an error handler, a bindAndExecute() of the driver's own) that sends the very same
        // statement runs without it
        $outer = $this->afterExecute;
        $this->afterExecute = [$sql, $this->hookDepth, $read, true];
        try {
            self::query($sql, $params);
        } finally {
            $this->afterExecute = $outer;
            if ($refusing) {
                $this->lockStatementsRunning--;
            }
        }
        if ($this->pdo !== $ranOn) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s(): the connection was replaced while the statement ran; a named lock belongs to its session', $method)
            );
        }

        return $answer;
    }

    /**
     * The name of a named lock on the server: prefixed (namedLockPrefix()).
     *
     * @throws QueryException When the name is empty or holds a NUL byte, or the driver names no prefix
     */
    private function lockName(string $method, string $name): string
    {
        if ($name === '') {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s() needs a name', $method)
            );
        }
        if (str_contains($name, "\0")) {
            // MariaDB keys the lock by the name up to its first NUL: "k\0a" and "k\0b" would be one lock (measured)
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s(): a name with a NUL byte - MariaDB cuts the name there, and different names would be one lock. Encode a binary name (bin2hex()).', $method)
            );
        }

        $prefix = $this->namedLockPrefix();
        if ($prefix === null) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s(): this driver names no prefix for named locks; override namedLockPrefix() (MariaDbDriver uses its configured database and ":", and names none when that database name holds a ":" itself: the lock names of two databases could meet)', $method)
            );
        }

        return $prefix . $name;
    }

    /**
     * What every named lock's name is prefixed with on the server, or null when this driver names
     * none - then the named-lock methods throw. The server keeps one namespace for all its
     * databases: the prefix keeps two applications on one server apart. MariaDbDriver returns its
     * configured database and ":"; a driver of its own that takes named locks overrides this.
     */
    protected function namedLockPrefix(): ?string
    {
        return null;
    }

    // =========================================================================
    // Schema
    // =========================================================================

    /**
     * What the current database holds - tables, columns, indexes, constraints -, read from
     * information_schema (see Schema\Schema). Read only. Works on any driver that extends this class.
     */
    public function schema(): Schema
    {
        return new Schema($this);
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
     * @throws QueryException When $data is empty, the query fails, or the ID the database reports
     *                        is no integer of PHP (the row is inserted then)
     *
     * @return int Last insert ID, 0 when the database generated none
     */
    public function insert(string $table, array $data): int
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

        // Read before the 'query' hook runs: a listener that inserts would replace the id - and, when
        // PDO could not report one, what it recorded for that failure
        $lastId = null;
        $idFailure = null;
        $read = function () use (&$lastId, &$idFailure): void {
            $lastId = $this->lastInsertId();
            if ($lastId === false) {
                $idFailure = $this->silentFailure('PDO::lastInsertId() returned false', $this->pdo->errorInfo());
            }
        };
        $this->queryThen($sql, $params, $read);
        if ($lastId === null) {
            $read(); // an overridden query() that bypasses this class's query() never ran the step
        }

        if ($lastId === false) {
            throw new QueryException(
                message: 'Insert failed',
                previous: $idFailure,
                debugMessage: sprintf('Failed to retrieve last insert ID | SQL: %s | Params: %s', $sql, $this->encodeParams($params))
            );
        }
        if ($lastId === '') {
            return 0; // a PDO class (pdoClass) that reports no ID
        }

        // Not (int): the cast cuts what does not fit. MariaDB reports a BIGINT UNSIGNED ID above
        // PHP_INT_MAX as it is, and a negative ID written into an AUTO_INCREMENT column as a number
        // of that size.
        $id = filter_var($lastId, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new QueryException(
                message: 'Insert ID out of range',
                debugMessage: sprintf(
                    'The row was inserted, but the ID the database reports for it, "%s", is no integer of PHP | SQL: %s | Params: %s',
                    $lastId,
                    $sql,
                    $this->encodeParams($params)
                )
            );
        }

        return $id;
    }

    /**
     * Insert a row only when a condition holds, in one statement (see InternalMethods::insertWhen()).
     *
     * Renders `INSERT INTO table (...) SELECT ?, ?, ... FROM DUAL WHERE (condition)` (MariaDB needs
     * `FROM DUAL` before a WHERE without a table), with $update followed by
     * `ON DUPLICATE KEY UPDATE ...`. Bound in that order: the row's values, the condition's
     * bindings, the update's values.
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When $data or the condition is empty, a binding is a RawExpression, or the query fails
     *
     * @return int Inserted rows, 1 or 0; with $update MariaDB's count (1 inserted, 2 updated, 0 neither)
     */
    public function insertWhen(string $table, array $data, string $condition, array $bindings = [], array $update = []): int
    {
        [$sql, $params] = $this->conditionalInsert('insertWhen', $table, $data, $condition, $bindings, $update);

        return $this->execute($sql, $params);
    }

    /**
     * insertWhen() with `RETURNING` (see InternalMethods::insertWhenReturning()).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $data Column => value pairs of the row
     * @param string $condition Trusted condition SQL with ? placeholders (never built from user input)
     * @param array<array-key, mixed> $bindings Values for the condition's placeholders, in order
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     * @param list<string|RawExpression> $columns What to return: column names, '*', or expressions without bindings
     *
     * @throws QueryException When $data, the condition or $columns is empty, a binding is a RawExpression, a column is an expression with bindings, or the query fails
     *
     * @return array<string, mixed>|null The row, or null when the condition was false
     */
    public function insertWhenReturning(string $table, array $data, string $condition, array $bindings = [], array $update = [], array $columns = ['*']): ?array
    {
        [$sql, $params] = $this->conditionalInsert('insertWhenReturning', $table, $data, $condition, $bindings, $update);

        return $this->returnedRow($sql . $this->returningClause('insertWhenReturning', $columns), $params);
    }

    /**
     * Insert a row, or change the row it collides with (see InternalMethods::upsert()).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     *
     * @throws QueryException When $row or $update is empty, or the query fails
     *
     * @return int 1 inserted, 2 updated, 0 unchanged
     */
    public function upsert(string $table, array $row, array $update): int
    {
        [$sql, $params] = $this->upsertStatement('upsert', $table, $row, $update);

        return $this->execute($sql, $params);
    }

    /**
     * upsert() with `RETURNING` (see InternalMethods::upsertReturning()).
     *
     * @param string $table Table name (supports schema.table format)
     * @param array<string, mixed> $row Column => value pairs of the row
     * @param array<string, mixed> $update Column => value pairs to set on a duplicate, in this order
     * @param list<string|RawExpression> $columns What to return: column names, '*', or expressions without bindings
     *
     * @throws QueryException When $row, $update or $columns is empty, a column is an expression with bindings, the server returns no row, or the query fails
     *
     * @return array<string, mixed> The row
     */
    public function upsertReturning(string $table, array $row, array $update, array $columns = ['*']): array
    {
        [$sql, $params] = $this->upsertStatement('upsertReturning', $table, $row, $update);
        $returned = $this->returnedRow($sql . $this->returningClause('upsertReturning', $columns), $params);
        if ($returned === null) {
            // MariaDB returns the row after every upsert (measured); nothing back is not "no row"
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('upsertReturning() got no row back | SQL: %s', $sql)
            );
        }

        return $returned;
    }

    /**
     * The statement of insertWhen() and insertWhenReturning(), and its params in SQL order.
     *
     * @param array<string, mixed> $data
     * @param array<array-key, mixed> $bindings
     * @param array<string, mixed> $update
     *
     * @throws QueryException When $data or the condition is empty, or a binding is a RawExpression
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function conditionalInsert(string $method, string $table, array $data, string $condition, array $bindings, array $update): array
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
                debugMessage: sprintf('%s() needs a condition', $method)
            );
        }
        foreach ($bindings as $binding) {
            if ($binding instanceof RawExpression) {
                throw new QueryException(
                    message: 'Insert failed',
                    debugMessage: sprintf('%s() binds the condition values; write a raw expression into the condition instead', $method)
                );
            }
        }

        [$columns, $values, $params] = $this->buildInsertParts($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM DUAL WHERE (%s)',
            $this->quoteIdentifier($table),
            $columns,
            $values,
            trim($condition)
        );
        $params = [...$params, ...array_values($bindings)];
        if ($update !== []) {
            [$set, $setParams] = $this->buildSetClause($update);
            $sql .= ' ON DUPLICATE KEY UPDATE ' . $set;
            $params = [...$params, ...$setParams];
        }

        return [$sql, $params];
    }

    /**
     * The statement of upsert() and upsertReturning(), and its params in SQL order.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $update
     *
     * @throws QueryException When $row or $update is empty
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function upsertStatement(string $method, string $table, array $row, array $update): array
    {
        if (empty($row)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: 'Cannot insert empty data'
            );
        }
        if (empty($update)) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf('%s() needs the columns to change on a duplicate ($update); to keep the existing row, use insertIgnore()', $method)
            );
        }

        [$columns, $values, $params] = $this->buildInsertParts($row);
        [$set, $setParams] = $this->buildSetClause($update);

        return [
            sprintf('INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s', $this->quoteIdentifier($table), $columns, $values, $set),
            [...$params, ...$setParams],
        ];
    }

    /**
     * ` RETURNING <columns>`: a name quoted, '*' as it is, an expression without bindings as it is.
     *
     * @param array<array-key, string|RawExpression> $columns
     *
     * @throws QueryException When $columns is empty or holds an expression with bindings
     */
    private function returningClause(string $method, array $columns): string
    {
        if ($columns === []) {
            throw new QueryException(
                message: 'Insert failed',
                debugMessage: sprintf("%s() needs the columns to return: names, '*', or expressions", $method)
            );
        }
        $list = [];
        foreach ($columns as $column) {
            if ($column instanceof RawExpression && $column->bindings !== []) {
                throw new QueryException(
                    message: 'Insert failed',
                    debugMessage: sprintf('%s() returns expressions without bindings only: their values would stand after the statement\'s own', $method)
                );
            }
            $list[] = match (true) {
                $column instanceof RawExpression => (string) $column,
                $column === '*' => '*',
                default => $this->quoteIdentifier($column),
            };
        }

        return ' RETURNING ' . implode(', ', $list);
    }

    /**
     * The one row a RETURNING statement returns, or null when it returns none.
     *
     * @param array<int, mixed> $params
     *
     * @throws QueryException When the query fails
     *
     * @return array<string, mixed>|null
     */
    private function returnedRow(string $sql, array $params): ?array
    {
        /** @var array<string, mixed>|false $returned */
        $returned = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $returned !== false ? $returned : null;
    }

    /**
     * Insert a row unless it collides with an existing one (see InternalMethods::insertIgnore()).
     *
     * `ON DUPLICATE KEY UPDATE col = col` on the row's first column: a no-op whichever key
     * collided, reported as 0 affected rows.
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

        $first = $this->quoteIdentifier((string) array_key_first($data));
        $onDuplicate = sprintf('ON DUPLICATE KEY UPDATE %s = %s', $first, $first);

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
     * @throws TransactionException When the own transaction's commit failed, or the own transaction was ended while the batch ran - a listener's reconnect(), an error handler inside a PDO call (a CommitFailedException with outcome 'lost'; what is open then is left alone) -, and when called from inside a listener of this driver (ListenerTransactionException, nothing is sent)
     * @throws CommitHookException When committed, but a transaction.commit or transaction.end listener failed or the connection state after a commit listener could not be verified
     *
     * @return int Total number of affected rows
     */
    public function updateMultiple(string $table, array $rows, string $keyColumn = 'id'): int
    {
        if (empty($rows)) {
            return 0;
        }

        $own = null; // the number of the transaction begun here; none inside a transaction of the caller
        if (!$this->pdo->inTransaction()) {
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
            if ($own !== null) {
                $this->rollbackQuietly($own, $e);
            }
            throw $e;
        }

        if ($own !== null) {
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
     * - users -> `users`
     * - shop.users -> `shop`.`users`
     *
     * Identifiers are quoted with backticks (MariaDB); a backtick inside a name is doubled. Every
     * key is the name of one column: no alias ("a as b" is that column), no wildcard (the quoting
     * itself is shared with the query builder, see Query\Sql).
     *
     * @param string $identifier Table or column name
     *
     * @return string Quoted identifier
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return Sql::name($identifier);
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
     * @throws QueryException When a key is an integer (see columnKey())
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>} [columns sql, values sql, params]
     */
    protected function buildInsertParts(array $data): array
    {
        $columns = [];
        $values = [];
        $params = [];

        foreach ($data as $column => $value) {
            $columns[] = $this->quoteIdentifier(self::columnKey($column, 'The columns to insert'));
            $values[] = Sql::value($value, $params);
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
     * @throws QueryException When a key is an integer (see columnKey())
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params]
     */
    protected function buildSetClause(array $data): array
    {
        $clauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $clauses[] = $this->quoteIdentifier(self::columnKey($column, 'The columns to set')) . ' = ' . Sql::value($value, $params);
        }

        return [implode(', ', $clauses), $params];
    }

    /**
     * Build WHERE clause from conditions array.
     *
     * A RawExpression value is inlined instead of being bound, its own bindings take its place
     * among the params (SECURITY: never pass user input as the SQL of Database::raw()).
     *
     * @param array<string, mixed> $where Column => value pairs
     *
     * @throws QueryException When a value is null, or a key is an integer (see columnKey())
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
            $clauses[] = $this->quoteIdentifier(self::columnKey($column, 'The WHERE conditions')) . ' = ' . Sql::value($value, $params);
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * A key of a column => value array as the column name it must be. PHP turns the keys of a list
     * and a numeric string key ('42') into integers whatever the declared type says; an integer
     * reaching quoteIdentifier() would be a TypeError instead of a QueryException.
     *
     * @throws QueryException When the key is an integer
     */
    private static function columnKey(int|string $key, string $what): string
    {
        if (is_int($key)) {
            throw new QueryException(
                message: 'Query failed',
                debugMessage: sprintf('%s need column names as keys, got the numeric key %d (a list, or a column named by digits alone). Pass column => value pairs.', $what, $key)
            );
        }

        return $key;
    }

    /**
     * Current date and time in the session's time zone, as a raw SQL expression for
     * insert()/update()/where() values: `NOW()`.
     */
    public function now(): RawExpression
    {
        return new RawExpression('NOW()');
    }

    /**
     * Current UTC date and time, as a raw SQL expression: `UTC_TIMESTAMP()` (a zoneless value).
     */
    public function utcNow(): RawExpression
    {
        return new RawExpression('UTC_TIMESTAMP()');
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
        return new \Sodaho\PdoWrapper\Query\QueryBuilder($this, $table);
    }
}
