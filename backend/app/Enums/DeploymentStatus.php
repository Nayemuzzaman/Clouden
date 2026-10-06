<?php

namespace App\Enums;

/**
 * Real deployment states. The UI renders these as a progress timeline; a
 * deployment is only ever "success" after the pipeline has routed traffic to a
 * healthy container.
 */
enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Cloning = 'cloning';
    case Building = 'building';
    case Starting = 'starting';
    case HealthChecking = 'health_checking';
    case Routing = 'routing';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Queued, self::Cloning, self::Building, self::Starting, self::HealthChecking, self::Routing];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::active());
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Cloning => 'Fetching source',
            self::Building => 'Building',
            self::Starting => 'Starting',
            self::HealthChecking => 'Health checking',
            self::Routing => 'Routing',
            self::Success => 'Successful',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
