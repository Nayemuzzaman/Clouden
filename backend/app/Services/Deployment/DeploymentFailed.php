<?php

namespace App\Services\Deployment;

use RuntimeException;

/** A deployment failure with an explanation suitable for the dashboard. */
class DeploymentFailed extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        string $reason,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($reason);
    }
}
