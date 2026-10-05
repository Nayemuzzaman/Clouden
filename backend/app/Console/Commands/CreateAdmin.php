<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * There is no public registration. The administrator account is created (or its
 * password reset) from the server's command line only.
 */
class CreateAdmin extends Command
{
    protected $signature = 'privatecloud:admin
        {--email= : Administrator email}
        {--name= : Display name}
        {--password-stdin : Read the password from standard input instead of prompting}
        {--reset : Reset the password of an existing administrator}';

    protected $description = 'Create the PrivateCloud administrator account or reset its password';

    public function handle(AuditLogger $audit): int
    {
        $email = $this->option('email') ?: $this->ask('Administrator email');
        $existing = User::query()->where('email', $email)->first();

        if (! $existing && User::query()->where('role', User::ROLE_ADMIN)->exists() && ! $this->option('reset')) {
            $this->error('An administrator already exists. Use --reset with the existing email to change its password.');

            return self::FAILURE;
        }
        if ($existing && ! $this->option('reset')) {
            $this->error('This user already exists. Use --reset to change its password.');

            return self::FAILURE;
        }

        $password = $this->option('password-stdin') ? trim((string) stream_get_contents(STDIN)) : $this->secret('Password (min. 12 characters)');
        if (! $this->option('password-stdin')) {
            $confirm = $this->secret('Confirm password');
            if ($confirm !== $password) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($existing) {
            $existing->update(['password' => $password]);
            $audit->log('auth.password_reset_cli', $existing, userId: $existing->id);
            $this->info('Password updated.');

            return self::SUCCESS;
        }

        $user = User::query()->create([
            'name' => $this->option('name') ?: 'Administrator',
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
        ]);
        $audit->log('auth.admin_created', $user, userId: $user->id);
        $this->info("Administrator {$email} created.");

        return self::SUCCESS;
    }
}
