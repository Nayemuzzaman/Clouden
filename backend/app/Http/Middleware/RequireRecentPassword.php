<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revealing secrets (env values, database passwords, webhook secrets, backup
 * downloads) requires the administrator to have re-entered their password
 * within the last few minutes.
 */
class RequireRecentPassword
{
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get(self::SESSION_KEY, 0);
        $window = (int) config('privatecloud.security.password_confirmation_minutes') * 60;

        if (time() - $confirmedAt > $window) {
            return response()->json([
                'message' => 'Please confirm your password to continue.',
                'code' => 'password_confirmation_required',
            ], 423);
        }

        return $next($request);
    }
}
