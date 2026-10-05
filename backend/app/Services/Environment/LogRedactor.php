<?php

namespace App\Services\Environment;

/** Replaces known secret values in log output. */
final class LogRedactor
{
    /** @param list<string> $secrets */
    public function __construct(private readonly array $secrets) {}

    public function redact(string $line): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '' && str_contains($line, $secret)) {
                $line = str_replace($secret, '[secret]', $line);
            }
        }

        return $line;
    }
}
