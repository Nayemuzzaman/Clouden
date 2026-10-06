<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Created = 'created';     // no successful deployment yet
    case Deploying = 'deploying'; // first deployment in progress
    case Running = 'running';
    case Stopped = 'stopped';     // stopped by the administrator
    case Crashed = 'crashed';     // container exited / restarting unexpectedly
    case Failed = 'failed';       // never deployed successfully, last attempt failed
    case Deleting = 'deleting';
}
