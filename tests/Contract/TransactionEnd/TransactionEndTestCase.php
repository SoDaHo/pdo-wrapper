<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Contract\TransactionEnd;

use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\CommitFailedException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\Fetched;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;
use Throwable;

/**
 * The ground of the transaction-end scenarios: a driver on a ScenarioPdo (the library's pdoClass
 * seam: what the driver reports can be simulated), the events it tells, a second connection that
 * sees what was committed, and the checks on both.
 */
abstract class TransactionEndTestCase extends ContractTestCase
{
    protected const TABLE = 'end_scenarios';

    protected const COMMITTED = DatabaseInterface::TRANSACTION_COMMITTED;
    protected const ROLLED_BACK = DatabaseInterface::TRANSACTION_ROLLED_BACK;
    protected const LOST = DatabaseInterface::TRANSACTION_LOST;

    final protected ScenarioPdo $pdo;

    /** A second, independent connection to the same database */
    final protected DatabaseInterface $observer;

    /** @var list<string> */
    protected array $events = [];

    /** @var list<array{outcome: string, error: ?Throwable}> */
    protected array $ends = [];

    /** @var list<?string> What a CommitFailedException handed to the end listener said at that moment; null for every other error */
    protected array $outcomesSeen = [];

    /** @var list<array<string, mixed>> */
    protected array $errors = [];

    protected function setUp(): void
    {
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $this->db = $this->connect(['pdoClass' => ScenarioPdo::class]);
        $pdo = $this->db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $this->pdo = $pdo;
        $this->observer = $this->connect();
        $this->events = [];
        $this->ends = [];
        $this->outcomesSeen = [];
        $this->errors = [];
        $this->db->on('transaction.commit', function (): void {
            $this->events[] = 'commit';
        });
        $this->db->on('transaction.rollback', function (): void {
            $this->events[] = 'rollback';
        });
        $this->db->on('transaction.end', function (array $data): void {
            $this->events[] = 'end';
            $this->ends[] = ['outcome' => (string) $data['outcome'], 'error' => $data['error'] instanceof Throwable ? $data['error'] : null];
            $this->outcomesSeen[] = $data['error'] instanceof CommitFailedException ? $data['error']->outcome : null;
        });
        $this->db->on('error', function (array $data): void {
            $this->errors[] = $data;
        });
    }

    protected function tearDown(): void
    {
        $this->pdo->failRollBackAlways = false;
        $this->pdo->failCommit = false;
        $this->pdo->commitReturnsFalse = false;
        $this->pdo->vanishOnFailedCommit = false;
        $this->pdo->hideTransaction = false;
        $this->pdo->stateUnreadable = false;
        $this->pdo->failExec = false;
        $this->pdo->vanishOnExec = false;
        try {
            if ($this->pdo->reallyInTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // the server rolls back whatever is left when the connection closes below
        }
        parent::tearDown();
    }

    protected function closeConnections(): void
    {
        unset($this->pdo, $this->observer);
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    protected function rows(): array
    {
        return $this->observer->table(self::TABLE)->orderBy('id')->get();
    }

    /**
     * No transaction may be left open, and these ids are what a second connection sees afterwards
     *.
     *
     * @param list<int> $ids
     */
    protected function assertVisible(array $ids, string $message = ''): void
    {
        $this->assertFalse($this->pdo->reallyInTransaction(), 'no transaction may be left open');
        $this->assertSame($ids, array_map(static fn (array $row): int => Fetched::int($row['id']), $this->rows()), $message);
    }

    /**
     * A row written inside a transaction that never committed must not be visible to another
     * connection.
     */
    protected function assertNotVisibleElsewhere(int $id): void
    {
        $this->assertSame(0, $this->observer->table(self::TABLE)->where('id', $id)->count(), 'nothing was committed');
    }
}
