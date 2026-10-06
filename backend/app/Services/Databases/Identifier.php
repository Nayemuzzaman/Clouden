<?php

namespace App\Services\Databases;

/**
 * SQL identifier helpers. Identifiers can never be bound as parameters, so they
 * are (1) validated against a strict pattern or the live catalog and (2) always
 * double-quoted with embedded quotes escaped.
 */
final class Identifier
{
    /** Names PrivateCloud itself creates (databases, roles). */
    public const MANAGED_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /** Names the administrator may give to new tables/columns. */
    public const USER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    private const RESERVED = ['postgres', 'template0', 'template1', 'public', 'privatecloud', 'admin', 'root', 'pg_monitor', 'pg_read_all_data', 'pg_write_all_data'];

    public static function isValidManagedName(string $name): bool
    {
        return (bool) preg_match(self::MANAGED_PATTERN, $name)
            && ! in_array($name, self::RESERVED, true)
            && ! str_starts_with($name, 'pg_');
    }

    public static function isValidUserName(string $name): bool
    {
        return (bool) preg_match(self::USER_PATTERN, $name) && ! str_starts_with(strtolower($name), 'pg_');
    }

    public static function quote(string $identifier): string
    {
        if (str_contains($identifier, "\0")) {
            throw new DatabaseException('Invalid identifier.');
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public static function qualified(string $schema, string $table): string
    {
        return self::quote($schema).'.'.self::quote($table);
    }

    /** Derive a valid database/role name from a project slug. */
    public static function fromSlug(string $slug): string
    {
        $name = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $slug) ?? '');
        $name = trim(preg_replace('/_+/', '_', $name) ?? '', '_');
        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'db_'.$name;
        }
        $name = substr($name, 0, 50);
        if (! self::isValidManagedName($name)) {
            $name = 'app_'.$name;
        }

        return substr($name, 0, 63);
    }
}
