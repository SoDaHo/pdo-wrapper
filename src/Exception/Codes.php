<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use PDOException;
use Throwable;

/**
 * The SQLSTATE and driver code an exception stands for: what DatabaseException::$sqlState and
 * $driverCode hold, and what the 'error' hook is told about an exception it reports. One rule for
 * both, in a class of its own so that no exception class grows a method a subclass might clash with.
 *
 * @internal
 */
final class Codes
{
    /**
     * A PDOException's errorInfo, the codes of an exception of this library, nothing for anything
     * else - null for both where no database failure stands behind the exception.
     *
     * @return array{?string, ?int}
     */
    public static function behind(?Throwable $e): array
    {
        if ($e instanceof DatabaseException) {
            // ?? for a subclass whose constructor skipped parent::__construct(): the properties are
            // uninitialized then, and reading them plainly would be an Error
            return [$e->sqlState ?? null, $e->driverCode ?? null];
        }
        if (!$e instanceof PDOException) {
            return [null, null];
        }

        // PDO's errorInfo: [SQLSTATE, driver code, driver message] - on every driver, for a failed
        // statement and for a failed connection alike (measured). getCode() is the SQLSTATE for the
        // one and the driver code for the other, so it is not read here.
        $state = $e->errorInfo[0] ?? null;
        $code = $e->errorInfo[1] ?? null;

        // '00000' is "no error": what PDO holds when it reported a failure it recorded nothing for
        return [is_string($state) && $state !== '' && $state !== '00000' ? $state : null, is_int($code) ? $code : null];
    }
}
