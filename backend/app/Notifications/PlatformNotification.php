<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-dashboard notification. Additional channels (mail, Slack) can be added by
 * extending via() without changing call sites.
 */
class PlatformNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly string $level = 'info',
        public readonly ?string $link = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'level' => $this->level,
            'link' => $this->link,
        ];
    }
}
