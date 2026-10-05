<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $account_login
 * @property string|null $account_name
 * @property string|null $avatar_url
 * @property string $token
 * @property string|null $scopes
 * @property Carbon|null $last_verified_at
 */
class GithubConnection extends Model
{
    protected $fillable = ['user_id', 'account_login', 'account_name', 'avatar_url', 'token_type', 'token', 'scopes', 'last_verified_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_verified_at' => 'datetime'];
    }

    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }
}
