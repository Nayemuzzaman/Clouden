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
        {--reset : Reset the password of an existing administrator}
        {--check-exists : Only report whether an administrator exists (exit code 0 = yes, 1 = no)}
        {--delete : Delete the account with this email (e.g. a development account)}';

    protected $description = 'Create the PrivateCloud administrator account or reset its password';

    /** Addresses that only make sense in development and must never become a production login. */
    private const PLACEHOLDER_EMAIL = '/@(example\.(com|org|net)|localhost|[^@]*\.(test|example|invalid|localhost|local))$/i';

    public function handle(AuditLogger $audit): int
    {
        if ($this->option('check-exists')) {
            return User::query()->where('role', User::ROLE_ADMIN)->exists() ? self::SUCCESS : self::FAILURE;
        }

        $email = $this->option('email') ?: $this->ask('Administrator email');
        if ($this->option('delete')) {
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                $this->error('No account with this email.');

                return self::FAILURE;
            }
            $user->endOtherSessions();
            $user->delete();
            $audit->log('auth.user_deleted_cli', null, metadata: ['email' => $email], label: $email);
            $this->info("Account {$email} deleted.");

            return self::SUCCESS;
        }
        if (self::isPlaceholderEmail((string) $email) && ! config('privatecloud.security.dev_mode') && app()->environment('production')) {
            $this->error("\"{$email}\" is a placeholder address reserved for development. Use a real email address for the production administrator.");

            return self::FAILURE;
        }
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
            $existing->endOtherSessions();
            $audit->log('auth.password_reset_cli', $existing, userId: $existing->id);
            $this->info('Password updated. All existing sessions were signed out.');

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

    public static function isPlaceholderEmail(string $email): bool
    {
        return (bool) preg_match(self::PLACEHOLDER_EMAIL, trim($email));
    }
}
