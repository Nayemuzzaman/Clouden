<?php

use App\Jobs\WorkerHeartbeat;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new WorkerHeartbeat)->everyMinute();
Schedule::command('privatecloud:metrics')->everyMinute()->withoutOverlapping(5);
Schedule::command('privatecloud:reconcile')->everyMinute()->withoutOverlapping(5);
Schedule::command('privatecloud:check-domains')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('privatecloud:check-domains --all')->daily();
Schedule::command('privatecloud:scheduled-backups')->everyFiveMinutes()->withoutOverlapping(30);
Schedule::command('privatecloud:metrics --prune')->hourly();
Schedule::command('privatecloud:cleanup')->weeklyOn(0, '04:30');
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('privatecloud:prune-history')->dailyAt('04:10')->withoutOverlapping(60);
