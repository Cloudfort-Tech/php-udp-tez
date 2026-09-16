<?php

declare(strict_types=1);

namespace Cloudfort\Tez\Exceptions;

use RuntimeException;

final class TezException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}
