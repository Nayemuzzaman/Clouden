<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPassword;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['boolean'],
        ]);

        if (! Auth::guard('web')->attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            $this->audit->log('auth.login_failed', null, 'failure', ['email' => $credentials['email']], $credentials['email']);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $request->session()->regenerate();
        $request->session()->put(RequireRecentPassword::SESSION_KEY, time());
        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->log('auth.login', $user);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:1024']]);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            $this->audit->log('auth.password_confirm_failed', $request->user(), 'failure');

            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        $request->session()->put(RequireRecentPassword::SESSION_KEY, time());

        return response()->json(['confirmed' => true, 'valid_for_minutes' => (int) config('privatecloud.security.password_confirmation_minutes')]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'max:1024', Password::min(12)->letters()->numbers()],
        ]);
        $request->user()->update(['password' => $request->input('password')]);
        Auth::logoutOtherDevices($request->input('password'));
        $request->session()->regenerate();
        $this->audit->log('auth.password_changed', $request->user());

        return response()->json(['message' => 'Password changed.']);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
