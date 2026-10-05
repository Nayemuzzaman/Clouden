<?php

namespace App\Services\Docker;

use RuntimeException;

class DockerException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
