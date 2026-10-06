<?php

namespace App\Services\Source;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks a fetched source tree before it is used as a Docker build context.
 *
 *  - Git submodules and Git LFS are not supported in V1: PrivateCloud downloads
 *    the exact commit as an archive, which contains neither submodule contents
 *    nor LFS objects. Both are detected here and explained, instead of failing
 *    later in an obscure build step.
 *  - The build context must never contain git metadata or the GitHub token
 *    PrivateCloud used to download it, so it cannot end up in an image layer.
 */
final class SourceInspector
{
    private const LFS_POINTER = 'version https://git-lfs.github.com/spec/v1';

    private const MAX_FILES = 50_000;

    private const MAX_SCAN_BYTES = 256 * 1024 * 1024;

    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    /** @param list<string> $secrets credential values that must not appear in the tree */
    public static function inspect(string $directory, array $secrets = []): void
    {
        self::assertNoSubmodules($directory);
        self::assertNoLfsPointers($directory);
        self::assertNoCredentials($directory, array_values(array_filter($secrets, fn (string $s) => strlen($s) >= 8)));
    }

    private static function assertNoSubmodules(string $directory): void
    {
        $file = $directory.'/.gitmodules';
        if (! is_file($file)) {
            return;
        }
        preg_match_all('/^\s*path\s*=\s*(.+?)\s*$/m', (string) file_get_contents($file), $m);
        foreach ($m[1] as $path) {
            $path = trim($path, '/');
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            $full = $directory.'/'.$path;
            if (! is_dir($full) || (new FilesystemIterator($full))->valid() === false) {
                throw new SourceException(
                    "This repository uses Git submodules (\"{$path}\"), which PrivateCloud does not download. Copy the submodule's code into the repository (or remove the submodule) and push again.",
                    SourceException::UNSUPPORTED,
                );
            }
        }
    }

    private static function assertNoLfsPointers(string $directory): void
    {
        $attributes = $directory.'/.gitattributes';
        if (! is_file($attributes) || ! str_contains((string) file_get_contents($attributes), 'filter=lfs')) {
            return;
        }
        foreach (self::files($directory) as $file) {
            if ($file->getSize() > 1024) {
                continue;
            }
            $head = (string) @file_get_contents($file->getPathname(), false, null, 0, strlen(self::LFS_POINTER));
            if ($head === self::LFS_POINTER) {
                $relative = ltrim(substr($file->getPathname(), strlen($directory)), '/');

                throw new SourceException(
                    "This repository stores files with Git LFS (for example \"{$relative}\"). PrivateCloud does not download LFS objects, so the build would receive small pointer files instead of the real content. Commit those files directly, or download them in your Dockerfile.",
                    SourceException::UNSUPPORTED,
                );
            }
        }
    }

    /** @param list<string> $secrets */
    private static function assertNoCredentials(string $directory, array $secrets): void
    {
        if (file_exists($directory.'/.git')) {
            throw new SourceException('The build context still contains git metadata (.git); the build was stopped.', SourceException::OTHER);
        }
        if ($secrets === []) {
            return;
        }
        $read = 0;
        foreach (self::files($directory) as $file) {
            $size = $file->getSize();
            if ($size === false || $size > self::MAX_FILE_BYTES || $read > self::MAX_SCAN_BYTES) {
                continue;
            }
            $read += $size;
            $content = (string) @file_get_contents($file->getPathname());
            foreach ($secrets as $secret) {
                if (str_contains($content, $secret)) {
                    $relative = ltrim(substr($file->getPathname(), strlen($directory)), '/');

                    throw new SourceException(
                        "The repository contains the GitHub access token that PrivateCloud uses (in \"{$relative}\"). The build was stopped so the token cannot end up in an image. Remove it from the repository and replace the token in Settings.",
                        SourceException::OTHER,
                    );
                }
            }
        }
    }

    /** @return iterable<SplFileInfo> regular files, symlinks not followed, at most MAX_FILES */
    private static function files(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
        );
        $count = 0;
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }
            if (++$count > self::MAX_FILES) {
                return;
            }
            yield $file;
        }
    }
}
