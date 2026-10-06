<?php

namespace App\Services\Deployment;

final readonly class BuildFailure
{
    public function __construct(
        public string $summary,
        public ?string $step = null,
        public ?string $excerpt = null,
    ) {}
}
