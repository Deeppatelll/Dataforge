<?php

declare(strict_types=1);

namespace Reporting\Api\Core;

use Exception;

final class ApiException extends Exception
{
    private int $httpStatus;
    private string $errorCode;
    private array $details;

    public function __construct(int $httpStatus, string $errorCode, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}
