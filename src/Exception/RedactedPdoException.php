<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use PDOException;

/**
 * Stands in for the PDOException of a failed statement - getPrevious() of the QueryException, the
 * failure the driver remembers and hands on, the 'error' hook's 'error' - when the driver was
 * opened with redactParameters: the database's message quotes values (a duplicate entry, an
 * incorrect value), so it carries only the SQLSTATE and the driver code - as getCode() and in
 * errorInfo, as PDO's own does - and no exception before it.
 */
class RedactedPdoException extends PDOException
{
    /**
     * @param string|null $sqlState The SQLSTATE of the failure it stands for, its code as PDO's own exception has it
     */
    public function __construct(string $message, ?string $sqlState)
    {
        parent::__construct($message);
        if ($sqlState !== null) {
            $this->code = $sqlState;
        }
    }
}
