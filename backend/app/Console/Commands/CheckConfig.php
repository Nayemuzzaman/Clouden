<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Domains\DomainValidator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refuses to start the control plane with an unsafe production configuration,
 * e.g. a development .env (debug mode, plain HTTP, root user, insecure git URLs)
 * copied to a server. Runs from the container entrypoint before anything else.
 *
 * In development (PC_DEV_MODE=true, set only by docker-compose.dev.yml) the same
 * findings are printed as warnings.
 */
class CheckConfig extends Command
{
    protected $signature = 'privatecloud:check-config';

    protected $description = 'Verify that the configuration is safe for production';

    public function handle(): int
    {
        [$errors, $warnings] = self::findings();
        $devMode = (bool) config('privatecloud.security.dev_mode');

        foreach ($errors as $message) {
            $devMode ? $this->warn('[dev] '.$message) : $this->error($message);
        }
        foreach ($warnings as $message) {
            $this->warn($message);
        }

        if ($errors !== [] && ! $devMode) {
            $this->error('PrivateCloud will not start with this configuration. Fix .env (see .env.example) and start again.');

            return self::FAILURE;
        }
        if ($errors === [] && $warnings === []) {
            $this->info('Configuration OK.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: list<string>, 1: list<string>} errors, warnings */
    public static function findings(): array
    {
        $errors = [];
        $warnings = [];

        if (config('app.env') !== 'production') {
            $errors[] = 'APP_ENV is "'.config('app.env').'"; it must be "production" (development settings are only allowed with docker-compose.dev.yml).';
        }
        if (config('app.debug')) {
            $errors[] = 'APP_DEBUG is true: error pages would expose configuration and secrets. Set APP_DEBUG=false.';
        }
        if (strlen((string) config('app.key')) < 32) {
            $errors[] = 'APP_KEY is missing or too short.';
        }
        if (! config('session.secure')) {
            $errors[] = 'SESSION_SECURE_COOKIE is false: session cookies would be sent over plain HTTP. Set SESSION_SECURE_COOKIE=true.';
        }
        if (! config('session.encrypt')) {
            $warnings[] = 'SESSION_ENCRYPT is false; set it to true so stored sessions are encrypted.';
        }
        if (config('privatecloud.deploy.allow_insecure_git')) {
            $errors[] = 'PC_ALLOW_INSECURE_GIT is true: http:// and private-network git URLs would be accepted. Remove it.';
        }
        if (config('privatecloud.caddy.auto_https') === 'off') {
            $errors[] = 'PC_AUTO_HTTPS is off: project domains would be served without HTTPS. Set PC_AUTO_HTTPS=on.';
        }

        $domain = strtolower((string) config('privatecloud.dashboard_domain'));
        if ($domain === '' || ! DomainValidator::isSafeHostname($domain)) {
            $errors[] = 'PC_DASHBOARD_DOMAIN must be the public hostname of the dashboard, e.g. cloud.example.com.';
        } elseif (preg_match('/(^|\.)(example\.(com|org|net)|localhost|local|test|invalid)$/', $domain)) {
            $errors[] = "PC_DASHBOARD_DOMAIN \"{$domain}\" is a placeholder or local name.";
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'APP_URL must start with https://.';
        }

        foreach (['DB_PASSWORD' => config('database.connections.pgsql.password'), 'PC_APPS_DB_ADMIN_PASSWORD' => config('privatecloud.apps_db.admin_password')] as $name => $value) {
            if (strlen((string) $value) < 16) {
                $errors[] = "{$name} must be a random value of at least 16 characters.";
            }
        }
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $errors[] = 'The control plane is running as root. Set PC_UID/PC_GID to an unprivileged user (the installer uses 33).';
        }
        if (! str_starts_with((string) config('privatecloud.data_dir'), '/')) {
            $errors[] = 'PC_DATA_DIR must be an absolute path.';
        }
        if ((string) config('privatecloud.public_ipv4') === '') {
            $warnings[] = 'PC_PUBLIC_IPV4 is empty; domain DNS checks will try to detect the address.';
        }

        try {
            $placeholder = User::query()->get(['email'])->first(fn (User $u) => CreateAdmin::isPlaceholderEmail($u->email));
            if ($placeholder) {
                $errors[] = "The account \"{$placeholder->email}\" uses a development placeholder address. Create the real administrator, then remove it: docker compose run --rm app php artisan privatecloud:admin --delete --email={$placeholder->email}";
            }
        } catch (Throwable) {
            // The database is not migrated yet (first start): nothing to check.
        }

        return [$errors, $warnings];
    }
}
