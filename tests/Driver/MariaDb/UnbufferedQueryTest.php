<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Driver\MariaDb;

use Sodaho\PdoWrapper\DatabaseInterface;
use Sodaho\PdoWrapper\Exception\QueryException;
use Sodaho\PdoWrapper\Tests\Contract\ContractTestCase;
use Sodaho\PdoWrapper\Tests\Support\ScenarioPdo;

/**
 * Unbuffered queries (ATTR_USE_BUFFERED_QUERY off): a statement sent while the result of another
 * is still open fails on the client with error 2014 - it never reached the server, and the
 * transaction is untouched. The driver neither remembers it nor asks the server about it (the
 * question would fail the same way, and the transaction would be held for gone): close the
 * cursor, send the statement again, commit.
 */
class UnbufferedQueryTest extends ContractTestCase
{
    private const TABLE = 'unbuffered_rows';

    public function testErrorTwoThousandFourteenIsNoEndOfTheTransaction(): void
    {
        $this->create(self::TABLE, ['id' => 'key', 'name' => 'text']);
        $db = $this->connect(['pdoClass' => ScenarioPdo::class, 'options' => [\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false]]);
        $pdo = $db->getPdo();
        $this->assertInstanceOf(ScenarioPdo::class, $pdo);
        $db->insert(self::TABLE, ['id' => 1, 'name' => 'a']);
        $db->insert(self::TABLE, ['id' => 2, 'name' => 'b']);
        $asked = false;
        $ends = [];
        $db->on('transaction.end', static function (array $end) use (&$ends): void {
            $ends[] = $end['outcome'];
        });

        $db->transaction(static function (DatabaseInterface $db) use ($pdo, &$asked): void {
            $open = $db->query('SELECT id FROM ' . self::TABLE . ' ORDER BY id'); // its result is not read yet
            $pdo->duringExec = static function () use (&$asked): void {
                $asked = true; // the driver's question to the server goes through exec()
            };
            try {
                $db->insert(self::TABLE, ['id' => 3, 'name' => 'c']);
                self::fail('Expected QueryException: commands out of sync');
            } catch (QueryException $e) {
                self::assertSame(2014, self::errorInfoOf($e));
            }
            $open->closeCursor();
            $db->insert(self::TABLE, ['id' => 3, 'name' => 'c']); // sent again: goes through
        });
        $pdo->duringExec = null;

        $this->assertFalse($asked, 'no question to the server after the client-side failure');
        $this->assertSame([DatabaseInterface::TRANSACTION_COMMITTED], $ends);
        $this->assertSame(3, $db->table(self::TABLE)->count());
    }

    /** The driver's error code behind a QueryException (its previous PDOException's errorInfo[1]) */
    private static function errorInfoOf(QueryException $e): mixed
    {
        $previous = $e->getPrevious();

        return $previous instanceof \PDOException ? ($previous->errorInfo[1] ?? null) : null;
    }
}
