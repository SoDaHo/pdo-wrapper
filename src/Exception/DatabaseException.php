<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

use Exception;
use Throwable;

/**
 * Base exception for all pdo-wrapper exceptions.
 */
class DatabaseException extends Exception
{
    public function __construct(
        string $message = 'Database error',
        int $code = 0,
        ?Throwable $previous = null,
        protected ?string $debugMessage = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get detailed debug message.
     *
     * @return string|null Debug information
     */
    public function getDebugMessage(): ?string
    {
        return $this->debugMessage;
    }
}
