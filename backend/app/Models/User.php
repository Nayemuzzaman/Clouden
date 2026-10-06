<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $mfa_enabled_at
 * @property Carbon|null $created_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'last_login_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Sign this user out everywhere except (optionally) the given session:
     * stored sessions are deleted and the "remember me" token is rotated, so
     * neither an old session cookie nor an old remember cookie works again.
     */
    public function endOtherSessions(?string $keepSessionId = null): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $this->id)
                ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }
}
