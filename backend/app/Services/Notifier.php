<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class Notifier
{
    public function notify(string $kind, string $title, string $message, string $level = 'info', ?string $link = null): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isEmpty()) {
            return;
        }
        Notification::send($admins, new PlatformNotification($kind, $title, $message, $level, $link));
    }

    /** Send at most one notification per $key within the cooldown window. */
    public function notifyOnce(string $key, int $cooldownMinutes, string $kind, string $title, string $message, string $level = 'warning', ?string $link = null): void
    {
        if (Cache::add('privatecloud:notified:'.$key, true, now()->addMinutes($cooldownMinutes))) {
            $this->notify($kind, $title, $message, $level, $link);
        }
    }
}
