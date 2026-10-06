<?php

namespace App\Services\Source;

final readonly class CommitInfo
{
    public function __construct(
        public string $sha,
        public ?string $message = null,
        public ?string $author = null,
        public ?string $committedAt = null,
    ) {}

    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }

    /** First line of the commit message, trimmed for display. */
    public function title(): ?string
    {
        if ($this->message === null) {
            return null;
        }

        return mb_substr(strtok($this->message, "\n") ?: '', 0, 500);
    }
}
