<?php

namespace App\Services\Databases;

use App\Models\ProjectDatabase;
use PDO;
use PDOException;
use PgSql\Connection;

/**
 * Opens connections to an application database using that database's OWN role
 * (least privilege) — never the server superuser.
 */
class DatabaseConnector
{
    public function pdo(ProjectDatabase $database): PDO
    {
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5;application_name=privatecloud', config('privatecloud.apps_db.host'), $database->port, $database->name),
                $database->username,
                $database->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );
            $pdo->exec('SET statement_timeout = '.(int) config('privatecloud.apps_db.statement_timeout_ms'));

            return $pdo;
        } catch (PDOException $e) {
            throw new DatabaseException('Could not connect to the database: '.self::clean($e));
        }
    }

    public function native(ProjectDatabase $database): Connection
    {
        $dsn = sprintf(
            "host='%s' port=%d dbname='%s' user='%s' password='%s' connect_timeout=5 application_name=privatecloud_sql",
            addcslashes((string) config('privatecloud.apps_db.host'), "'\\"),
            $database->port,
            addcslashes($database->name, "'\\"),
            addcslashes($database->username, "'\\"),
            addcslashes($database->password, "'\\"),
        );
        $conn = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            throw new DatabaseException('Could not connect to the database.');
        }
        pg_query($conn, 'SET statement_timeout = '.(int) config('privatecloud.apps_db.statement_timeout_ms'));

        return $conn;
    }

    public static function clean(\Throwable $e): string
    {
        $message = preg_replace('/^SQLSTATE\[[^\]]+\]:?\s*(\[\d+\]\s*)?/', '', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(trim($message), 0, 1000);
    }
}
