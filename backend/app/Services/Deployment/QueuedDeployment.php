<?php

namespace App\Services\Deployment;

use App\Models\Deployment;

/** Result of a deployment request: the deployment, and whether an in-progress one for the same commit was reused. */
final readonly class QueuedDeployment
{
    public function __construct(public Deployment $deployment, public bool $reused = false) {}
}
