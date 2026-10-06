<?php

namespace App\Services\Backups;

/**
 * Where finished backup files live. V1 ships the local implementation; an
 * S3-compatible implementation (S3, Backblaze B2, Cloudflare R2, Vultr Object
 * Storage) only needs to implement this interface and be selected through
 * PC_BACKUP_STORAGE.
 */
interface BackupStorage
{
    public function name(): string;

    /** Local directory where backup tools write files before they are stored. */
    public function stagingPath(string $key): string;

    /** Persist a file written to stagingPath($key). */
    public function store(string $key): void;

    /** Absolute local path of a stored backup, downloading it first if needed. */
    public function localPath(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    public function isOffServer(): bool;
}
