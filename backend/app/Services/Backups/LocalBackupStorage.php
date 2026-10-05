<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/** Stores backups on this server's disk. Does NOT protect against losing the server. */
class LocalBackupStorage implements BackupStorage
{
    public function __construct(private readonly string $root) {}

    public function name(): string
    {
        return 'local';
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    public function stagingPath(string $key): string
    {
        $path = $this->resolve($key);
        File::ensureDirectoryExists(dirname($path), 0750);

        return $path;
    }

    public function store(string $key): void
    {
        // Already in place.
    }

    public function localPath(string $key): string
    {
        return $this->resolve($key);
    }

    public function exists(string $key): bool
    {
        return is_file($this->resolve($key));
    }

    public function delete(string $key): void
    {
        File::delete($this->resolve($key));
    }

    public function isOffServer(): bool
    {
        return false;
    }

    /** Map a storage key to a path, refusing anything that escapes the backup root. */
    private function resolve(string $key): string
    {
        if ($key === '' || str_contains($key, "\0") || str_starts_with($key, '/') || preg_match('#(^|/)\.\.(/|$)#', $key)
            || ! preg_match('#^[A-Za-z0-9._/-]+$#', $key)) {
            throw new InvalidArgumentException('Invalid backup path.');
        }

        return $this->root().'/'.$key;
    }
}
