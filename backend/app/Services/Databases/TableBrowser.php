<?php

namespace App\Services\Databases;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Visual table browser/editor. Every identifier is checked against the live
 * catalog (or a strict pattern for new names) and quoted; every value is bound
 * as a parameter. Nothing typed by the user is ever concatenated into SQL.
 */
class TableBrowser
{
    public const FILTER_OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'contains', 'starts_with', 'is_null', 'not_null'];

    private const MAX_PAGE_SIZE = 500;

    private const EXACT_COUNT_LIMIT = 200_000;

    public function __construct(private readonly PDO $pdo) {}

    /** @return list<string> */
    public function schemas(): array
    {
        return $this->pdo->query(
            "SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' AND nspname <> 'information_schema' ORDER BY nspname = 'public' DESC, nspname"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<array{name: string, row_estimate: int, size_bytes: int}> */
    public function tables(string $schema): array
    {
        $this->assertSchema($schema);
        $stmt = $this->pdo->prepare(
            "SELECT c.relname AS name, GREATEST(c.reltuples, 0)::bigint AS row_estimate, pg_total_relation_size(c.oid) AS size_bytes
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind IN ('r', 'p')
             ORDER BY c.relname"
        );
        $stmt->execute([$schema]);

        return array_map(fn ($r) => [
            'name' => $r['name'],
            'row_estimate' => (int) $r['row_estimate'],
            'size_bytes' => (int) $r['size_bytes'],
        ], $stmt->fetchAll());
    }

    /** @return array<string, mixed> */
    public function describe(string $schema, string $table): array
    {
        $this->assertTable($schema, $table);

        $columns = $this->select(
            'SELECT column_name AS name, data_type, udt_name, is_nullable, column_default, character_maximum_length, is_identity
             FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            [$schema, $table],
        );

        $primaryKey = $this->select(
            "SELECT a.attname AS name FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = (quote_ident(?) || '.' || quote_ident(?))::regclass AND i.indisprimary
             ORDER BY array_position(i.indkey, a.attnum)",
            [$schema, $table],
        );

        $indexes = $this->select(
            'SELECT indexname AS name, indexdef AS definition FROM pg_indexes WHERE schemaname = ? AND tablename = ? ORDER BY indexname',
            [$schema, $table],
        );

        $foreignKeys = $this->select(
            "SELECT conname AS name, pg_get_constraintdef(oid) AS definition FROM pg_constraint
             WHERE contype = 'f' AND conrelid = (quote_ident(?) || '.' || quote_ident(?))::regclass ORDER BY conname",
            [$schema, $table],
        );

        $pk = array_column($primaryKey, 'name');

        return [
            'schema' => $schema,
            'name' => $table,
            'columns' => array_map(fn ($c) => [
                'name' => $c['name'],
                'type' => $this->displayType($c),
                'nullable' => $c['is_nullable'] === 'YES',
                'default' => $c['column_default'],
                'primary' => in_array($c['name'], $pk, true),
                'identity' => $c['is_identity'] === 'YES' || str_starts_with((string) $c['column_default'], 'nextval('),
            ], $columns),
            'primary_key' => $pk,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * @param  list<array{column: string, operator: string, value?: string|null}>  $filters
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int, total_is_estimate: bool, page: int, per_page: int}
     */
    public function rows(string $schema, string $table, int $page = 1, int $perPage = 50, ?string $sort = null, string $direction = 'asc', array $filters = []): array
    {
        $columns = $this->columnNames($schema, $table);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $page = max(1, $page);
        [$where, $bindings] = $this->buildWhere($filters, $columns);

        $orderBy = '';
        if ($sort !== null && $sort !== '') {
            $this->assertColumn($sort, $columns);
            $orderBy = ' ORDER BY '.Identifier::quote($sort).(strtolower($direction) === 'desc' ? ' DESC' : ' ASC').' NULLS LAST';
        } elseif ($pk = $this->describe($schema, $table)['primary_key']) {
            $orderBy = ' ORDER BY '.implode(', ', array_map(Identifier::quote(...), $pk));
        }

        $from = ' FROM '.Identifier::qualified($schema, $table).$where;
        $stmt = $this->pdo->prepare('SELECT *'.$from.$orderBy.' LIMIT '.$perPage.' OFFSET '.(($page - 1) * $perPage));
        $stmt->execute($bindings);
        $rows = $this->fetchRows($stmt);

        // Exact counts are expensive on large tables; fall back to the planner estimate.
        $estimate = $this->estimate($schema, $table);
        $isEstimate = $where === '' && $estimate > self::EXACT_COUNT_LIMIT;
        if ($isEstimate) {
            $total = $estimate;
        } else {
            $count = $this->pdo->prepare('SELECT count(*)'.$from);
            $count->execute($bindings);
            $total = (int) $count->fetchColumn();
        }

        return ['columns' => $columns, 'rows' => $rows, 'total' => $total, 'total_is_estimate' => $isEstimate, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    public function insert(string $schema, string $table, array $values): array
    {
        $columns = $this->columnNames($schema, $table);
        if ($values === []) {
            $sql = 'INSERT INTO '.Identifier::qualified($schema, $table).' DEFAULT VALUES RETURNING *';
            $bindings = [];
        } else {
            foreach (array_keys($values) as $column) {
                $this->assertColumn((string) $column, $columns);
            }
            $sql = 'INSERT INTO '.Identifier::qualified($schema, $table)
                .' ('.implode(', ', array_map(fn ($c) => Identifier::quote((string) $c), array_keys($values))).')'
                .' VALUES ('.implode(', ', array_fill(0, count($values), '?')).') RETURNING *';
            $bindings = array_values($values);
        }

        return $this->writeOne($sql, $bindings);
    }

    /**
     * @param  array<string, string|null>  $primaryKey
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    public function update(string $schema, string $table, array $primaryKey, array $values): array
    {
        $columns = $this->columnNames($schema, $table);
        [$pkWhere, $pkBindings] = $this->primaryKeyWhere($schema, $table, $primaryKey);
        if ($values === []) {
            throw new DatabaseException('Nothing to update.');
        }
        $sets = [];
        foreach (array_keys($values) as $column) {
            $this->assertColumn((string) $column, $columns);
            $sets[] = Identifier::quote((string) $column).' = ?';
        }
        $sql = 'UPDATE '.Identifier::qualified($schema, $table).' SET '.implode(', ', $sets).$pkWhere.' RETURNING *';

        return $this->writeOne($sql, [...array_values($values), ...$pkBindings]);
    }

    /** @param array<string, string|null> $primaryKey */
    public function delete(string $schema, string $table, array $primaryKey): void
    {
        [$pkWhere, $pkBindings] = $this->primaryKeyWhere($schema, $table, $primaryKey);
        $stmt = $this->run('DELETE FROM '.Identifier::qualified($schema, $table).$pkWhere, $pkBindings);
        if ($stmt->rowCount() !== 1) {
            throw new DatabaseException('The row was not found. It may have been changed or deleted.');
        }
    }

    /**
     * @param  list<array{name: string, type: string, nullable?: bool, primary?: bool, default_expression?: ?string, default_value?: ?string}>  $columns
     */
    public function createTable(string $schema, string $table, array $columns): void
    {
        $this->assertSchema($schema);
        $this->assertNewName($table, 'table');
        if ($columns === []) {
            throw new DatabaseException('A table needs at least one column.');
        }
        $definitions = [];
        $primary = [];
        $names = [];
        foreach ($columns as $column) {
            $definitions[] = $this->columnDefinition($column);
            if (in_array(strtolower($column['name']), $names, true)) {
                throw new DatabaseException("Column \"{$column['name']}\" is defined twice.");
            }
            $names[] = strtolower($column['name']);
            if (! empty($column['primary'])) {
                $primary[] = Identifier::quote($column['name']);
            }
        }
        if ($primary !== []) {
            $definitions[] = 'PRIMARY KEY ('.implode(', ', $primary).')';
        }
        $this->run('CREATE TABLE '.Identifier::qualified($schema, $table).' ('.implode(', ', $definitions).')');
    }

    /** @param array{name: string, type: string, nullable?: bool, default_expression?: ?string, default_value?: ?string} $column */
    public function addColumn(string $schema, string $table, array $column): void
    {
        $this->assertTable($schema, $table);
        $this->run('ALTER TABLE '.Identifier::qualified($schema, $table).' ADD COLUMN '.$this->columnDefinition($column));
    }

    /**
     * Safe column edits only: rename, nullability, default. Type changes can
     * rewrite or fail on existing data and are left to the SQL editor.
     *
     * @param  array{rename_to?: ?string, nullable?: ?bool, default_expression?: ?string, default_value?: ?string, drop_default?: bool}  $changes
     */
    public function alterColumn(string $schema, string $table, string $column, array $changes): void
    {
        $columns = $this->columnNames($schema, $table);
        $this->assertColumn($column, $columns);
        $target = Identifier::qualified($schema, $table);
        $col = Identifier::quote($column);

        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('nullable', $changes) && $changes['nullable'] !== null) {
                $this->run("ALTER TABLE {$target} ALTER COLUMN {$col} ".($changes['nullable'] ? 'DROP NOT NULL' : 'SET NOT NULL'));
            }
            if (! empty($changes['drop_default'])) {
                $this->run("ALTER TABLE {$target} ALTER COLUMN {$col} DROP DEFAULT");
            } elseif (($default = $this->defaultClause($changes)) !== null) {
                $this->run("ALTER TABLE {$target} ALTER COLUMN {$col} SET {$default}");
            }
            if (! empty($changes['rename_to']) && $changes['rename_to'] !== $column) {
                $this->assertNewName($changes['rename_to'], 'column');
                $this->run("ALTER TABLE {$target} RENAME COLUMN {$col} TO ".Identifier::quote($changes['rename_to']));
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function dropColumn(string $schema, string $table, string $column): void
    {
        $this->assertColumn($column, $this->columnNames($schema, $table));
        $this->run('ALTER TABLE '.Identifier::qualified($schema, $table).' DROP COLUMN '.Identifier::quote($column));
    }

    public function dropTable(string $schema, string $table): void
    {
        $this->assertTable($schema, $table);
        $this->run('DROP TABLE '.Identifier::qualified($schema, $table));
    }

    // ------------------------------------------------------------ internals

    /** @param array{name: string, type: string, nullable?: bool, default_expression?: ?string, default_value?: ?string} $column */
    private function columnDefinition(array $column): string
    {
        $this->assertNewName($column['name'], 'column');
        $sql = Identifier::quote($column['name']).' '.ColumnType::normalize($column['type']);
        if (array_key_exists('nullable', $column) && $column['nullable'] === false) {
            $sql .= ' NOT NULL';
        }
        if (($default = $this->defaultClause($column)) !== null) {
            $sql .= ' '.$default;
        }

        return $sql;
    }

    /** @param array<string, mixed> $spec */
    private function defaultClause(array $spec): ?string
    {
        if (! empty($spec['default_expression'])) {
            if (! in_array($spec['default_expression'], ColumnType::DEFAULT_EXPRESSIONS, true)) {
                throw new DatabaseException('Unsupported default expression.');
            }

            return 'DEFAULT '.$spec['default_expression'];
        }
        if (array_key_exists('default_value', $spec) && $spec['default_value'] !== null) {
            // DDL cannot take bind parameters; the literal is escaped by the driver.
            return 'DEFAULT '.$this->pdo->quote((string) $spec['default_value']);
        }

        return null;
    }

    /**
     * @param  list<array{column: string, operator: string, value?: string|null}>  $filters
     * @param  list<string>  $columns
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters, array $columns): array
    {
        $clauses = [];
        $bindings = [];
        foreach ($filters as $filter) {
            $column = (string) ($filter['column'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'eq');
            $this->assertColumn($column, $columns);
            if (! in_array($operator, self::FILTER_OPERATORS, true)) {
                throw new DatabaseException('Unsupported filter operator.');
            }
            $col = Identifier::quote($column);
            $value = $filter['value'] ?? null;

            switch ($operator) {
                case 'is_null':
                    $clauses[] = "{$col} IS NULL";
                    break;
                case 'not_null':
                    $clauses[] = "{$col} IS NOT NULL";
                    break;
                case 'contains':
                    $clauses[] = "{$col}::text ILIKE ?";
                    $bindings[] = '%'.addcslashes((string) $value, '%_\\').'%';
                    break;
                case 'starts_with':
                    $clauses[] = "{$col}::text ILIKE ?";
                    $bindings[] = addcslashes((string) $value, '%_\\').'%';
                    break;
                default:
                    $sqlOperator = ['eq' => '=', 'neq' => '<>', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$operator];
                    $clauses[] = "{$col} {$sqlOperator} ?";
                    $bindings[] = $value;
            }
        }

        return [$clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    /**
     * @param  array<string, string|null>  $primaryKey
     * @return array{0: string, 1: list<mixed>}
     */
    private function primaryKeyWhere(string $schema, string $table, array $primaryKey): array
    {
        $pk = $this->describe($schema, $table)['primary_key'];
        if ($pk === []) {
            throw new DatabaseException('This table has no primary key, so rows cannot be edited safely from the table editor. Use the SQL editor instead.');
        }
        $clauses = [];
        $bindings = [];
        foreach ($pk as $column) {
            if (! array_key_exists($column, $primaryKey) || $primaryKey[$column] === null) {
                throw new DatabaseException("Missing primary key value for \"{$column}\".");
            }
            $clauses[] = Identifier::quote($column).' = ?';
            $bindings[] = $primaryKey[$column];
        }

        return [' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array<string, mixed>
     */
    private function writeOne(string $sql, array $bindings): array
    {
        $stmt = $this->run($sql, $bindings);
        $rows = $this->fetchRows($stmt);
        if ($rows === []) {
            throw new DatabaseException('The row was not found. It may have been changed or deleted.');
        }

        return $rows[0];
    }

    /** @param list<mixed> $bindings */
    private function run(string $sql, array $bindings = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($bindings as $i => $value) {
                $stmt->bindValue($i + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
            $stmt->execute();

            return $stmt;
        } catch (PDOException $e) {
            throw new DatabaseException(DatabaseConnector::clean($e));
        }
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $bindings): array
    {
        return $this->fetchRows($this->run($sql, $bindings));
    }

    /** @return list<array<string, mixed>> */
    private function fetchRows(PDOStatement $stmt): array
    {
        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ($row as $key => $value) {
                if (is_resource($value)) {
                    $content = stream_get_contents($value, 64);
                    $row[$key] = '\\x'.bin2hex((string) $content).(strlen((string) $content) === 64 ? '…' : '');
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function estimate(string $schema, string $table): int
    {
        $stmt = $this->pdo->prepare('SELECT GREATEST(c.reltuples, 0)::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?');
        $stmt->execute([$schema, $table]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function columnNames(string $schema, string $table): array
    {
        $this->assertTable($schema, $table);
        $stmt = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
        $stmt->execute([$schema, $table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function assertSchema(string $schema): void
    {
        if (! in_array($schema, $this->schemas(), true)) {
            throw new DatabaseException("Schema \"{$schema}\" does not exist.");
        }
    }

    private function assertTable(string $schema, string $table): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND c.relkind IN ('r', 'p')");
        $stmt->execute([$schema, $table]);
        if (! $stmt->fetchColumn()) {
            throw new DatabaseException("Table \"{$table}\" does not exist.");
        }
    }

    /** @param list<string> $columns */
    private function assertColumn(string $column, array $columns): void
    {
        if (! in_array($column, $columns, true)) {
            throw new DatabaseException("Column \"{$column}\" does not exist.");
        }
    }

    private function assertNewName(string $name, string $kind): void
    {
        if (! Identifier::isValidUserName($name)) {
            throw new DatabaseException("Invalid {$kind} name \"{$name}\". Use letters, digits and underscores, starting with a letter or underscore (max 63 characters).");
        }
    }

    /** @param array<string, mixed> $column */
    private function displayType(array $column): string
    {
        return match ($column['data_type']) {
            'character varying' => $column['character_maximum_length'] ? 'varchar('.$column['character_maximum_length'].')' : 'varchar',
            'timestamp with time zone' => 'timestamptz',
            'timestamp without time zone' => 'timestamp',
            'USER-DEFINED', 'ARRAY' => (string) $column['udt_name'],
            default => (string) $column['data_type'],
        };
    }
}
