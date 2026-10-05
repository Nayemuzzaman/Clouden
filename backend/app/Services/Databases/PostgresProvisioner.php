<?php

namespace App\Services\Databases;

use PDO;
use PDOException;

/**
 * Creates and removes isolated application databases on the applications
 * PostgreSQL server (separate from the platform's own database).
 *
 * Each application gets its own LOGIN role that owns exactly one database.
 * CONNECT is revoked from PUBLIC on every database, so an application role can
 * only connect to its own database.
 */
class PostgresProvisioner
{
    private ?PDO $admin = null;

    public function ping(): bool
    {
        try {
            $this->admin()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            $this->admin = null;

            return false;
        }
    }

    public function create(string $database, string $username, string $password): void
    {
        $this->assertNames($database, $username);
        $pdo = $this->admin();
        $this->harden();

        try {
            $roleExists = $this->scalar('SELECT 1 FROM pg_roles WHERE rolname = ?', [$username]);
            $pwd = $pdo->quote($password);
            if ($roleExists) {
                $pdo->exec('ALTER ROLE '.Identifier::quote($username)." WITH LOGIN PASSWORD {$pwd}");
            } else {
                $pdo->exec('CREATE ROLE '.Identifier::quote($username)." WITH LOGIN PASSWORD {$pwd} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 100");
            }

            if (! $this->scalar('SELECT 1 FROM pg_database WHERE datname = ?', [$database])) {
                $pdo->exec('CREATE DATABASE '.Identifier::quote($database).' OWNER '.Identifier::quote($username)." ENCODING 'UTF8' TEMPLATE template0");
            }
            $pdo->exec('REVOKE ALL ON DATABASE '.Identifier::quote($database).' FROM PUBLIC');
            $pdo->exec('GRANT CONNECT, TEMPORARY ON DATABASE '.Identifier::quote($database).' TO '.Identifier::quote($username));
        } catch (PDOException $e) {
            throw new DatabaseException('Could not create the database: '.$this->cleanMessage($e));
        }

        // Make the app role own the public schema of its database.
        try {
            $db = $this->connectAsAdmin($database);
            $db->exec('ALTER SCHEMA public OWNER TO '.Identifier::quote($username));
            $db->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
            $db = null;
        } catch (PDOException $e) {
            throw new DatabaseException('The database was created but its permissions could not be configured: '.$this->cleanMessage($e));
        }
    }

    public function drop(string $database, string $username): void
    {
        $this->assertNames($database, $username);
        $pdo = $this->admin();
        try {
            $this->terminateConnections($database);
            $pdo->exec('DROP DATABASE IF EXISTS '.Identifier::quote($database));
            $pdo->exec('DROP ROLE IF EXISTS '.Identifier::quote($username));
        } catch (PDOException $e) {
            throw new DatabaseException('Could not delete the database: '.$this->cleanMessage($e));
        }
    }

    public function resetPassword(string $username, string $password): void
    {
        if (! Identifier::isValidManagedName($username)) {
            throw new DatabaseException('Invalid role name.');
        }
        try {
            $this->admin()->exec('ALTER ROLE '.Identifier::quote($username).' WITH PASSWORD '.$this->admin()->quote($password));
        } catch (PDOException $e) {
            throw new DatabaseException('Could not reset the password: '.$this->cleanMessage($e));
        }
    }

    public function terminateConnections(string $database): void
    {
        $stmt = $this->admin()->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
        $stmt->execute([$database]);
    }

    public function size(string $database): ?int
    {
        try {
            $value = $this->scalar('SELECT pg_database_size(?)', [$database]);

            return $value === null ? null : (int) $value;
        } catch (\Throwable) {
            return null;
        }
    }

    public function exists(string $database): bool
    {
        return (bool) $this->scalar('SELECT 1 FROM pg_database WHERE datname = ?', [$database]);
    }

    public static function generatePassword(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /** Prevent application roles from connecting to the maintenance databases. */
    private function harden(): void
    {
        foreach (['postgres', 'template1'] as $db) {
            try {
                $this->admin()->exec('REVOKE CONNECT ON DATABASE '.Identifier::quote($db).' FROM PUBLIC');
            } catch (PDOException) {
                // Not fatal: the database may not exist on custom installations.
            }
        }
    }

    private function assertNames(string $database, string $username): void
    {
        if (! Identifier::isValidManagedName($database) || ! Identifier::isValidManagedName($username)) {
            throw new DatabaseException('Database and user names must start with a letter and contain only lowercase letters, digits and underscores.');
        }
    }

    /** @param list<mixed> $bindings */
    private function scalar(string $sql, array $bindings): mixed
    {
        $stmt = $this->admin()->prepare($sql);
        $stmt->execute($bindings);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    private function admin(): PDO
    {
        return $this->admin ??= $this->connectAsAdmin((string) config('privatecloud.apps_db.admin_database'));
    }

    private function connectAsAdmin(string $database): PDO
    {
        $config = config('privatecloud.apps_db');
        if (empty($config['admin_password'])) {
            throw new DatabaseException('The application database server is not configured (PC_APPS_DB_ADMIN_PASSWORD is empty).');
        }
        try {
            return new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5', $config['host'], $config['port'], $database),
                (string) $config['admin_username'],
                (string) $config['admin_password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            throw new DatabaseException('Could not connect to the application database server: '.$this->cleanMessage($e));
        }
    }

    private function cleanMessage(PDOException $e): string
    {
        // Strip the SQLSTATE prefix and never echo connection strings.
        $message = preg_replace('/^SQLSTATE\[[^\]]+\]:?\s*(\[\d+\]\s*)?/', '', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(trim(preg_replace('/password=\S+/i', 'password=***', $message) ?? ''), 0, 500);
    }
}
