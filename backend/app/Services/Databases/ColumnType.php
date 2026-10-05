<?php

namespace App\Services\Databases;

/** Allow-list of column types and default expressions offered by the table editor. */
final class ColumnType
{
    private const TYPE_PATTERN = '/^(text|integer|bigint|smallint|serial|bigserial|boolean|real|double precision|date|timestamp|timestamptz|time|uuid|json|jsonb|bytea|inet|varchar\(\d{1,5}\)|numeric(?:\(\d{1,3}(?:,\s?\d{1,3})?\))?)(\[\])?$/';

    public const DEFAULT_EXPRESSIONS = ['now()', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'gen_random_uuid()', 'true', 'false', 'NULL', "'{}'::jsonb", "'[]'::jsonb"];

    public static function isAllowed(string $type): bool
    {
        return (bool) preg_match(self::TYPE_PATTERN, strtolower(trim($type)));
    }

    public static function normalize(string $type): string
    {
        $type = strtolower(trim($type));
        if (! self::isAllowed($type)) {
            throw new DatabaseException("Column type \"{$type}\" is not supported by the table editor. Use the SQL editor for advanced types.");
        }

        return $type;
    }

    /** @return list<string> */
    public static function common(): array
    {
        return ['text', 'varchar(255)', 'integer', 'bigint', 'bigserial', 'boolean', 'numeric', 'double precision', 'date', 'timestamptz', 'uuid', 'jsonb'];
    }
}
