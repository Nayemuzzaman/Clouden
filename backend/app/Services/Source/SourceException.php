<?php

namespace App\Services\Source;

use RuntimeException;
use Throwable;

/**
 * A user-presentable error while talking to GitHub or a git remote. The kind
 * tells callers what went wrong without parsing the message.
 */
class SourceException extends RuntimeException
{
    public const AUTH = 'auth';                 // token rejected (expired, revoked)

    public const FORBIDDEN = 'forbidden';       // token valid but lacks a permission

    public const NOT_FOUND = 'not_found';       // repository, branch or commit not found / not visible

    public const BRANCH_MISSING = 'branch_missing'; // the repository is visible but the branch does not exist

    public const RATE_LIMITED = 'rate_limited';

    public const UNAVAILABLE = 'unavailable';   // network error or GitHub 5xx

    public const UNSUPPORTED = 'unsupported';   // e.g. submodules, Git LFS

    public const INVALID = 'invalid';

    public const OTHER = 'other';

    public function __construct(string $message, public readonly string $kind = self::OTHER, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Whether retrying later can succeed without the administrator changing anything. */
    public function isTransient(): bool
    {
        return in_array($this->kind, [self::RATE_LIMITED, self::UNAVAILABLE], true);
    }
}
