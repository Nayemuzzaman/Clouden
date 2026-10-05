<?php

namespace Tests\Fakes;

use App\Services\Routing\CaddyReloader;

class FakeCaddyReloader implements CaddyReloader
{
    public int $reloads = 0;

    public bool $fail = false;

    public function reload(): array
    {
        $this->reloads++;

        return $this->fail ? ['success' => false, 'output' => 'Error: adapting config: invalid'] : ['success' => true, 'output' => ''];
    }
}
