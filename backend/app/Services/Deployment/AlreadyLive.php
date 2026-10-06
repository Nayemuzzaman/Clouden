<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Services\Source\CommitInfo;
use DomainException;

/** "Deploy Latest" found that production already runs the head of the production branch. */
class AlreadyLive extends DomainException
{
    public function __construct(public readonly Deployment $production, public readonly CommitInfo $commit)
    {
        parent::__construct("Production already runs the latest commit of {$production->branch} ({$commit->shortSha()}). Redeploy it to restart with the current settings, or rebuild it from source.");
    }
}
