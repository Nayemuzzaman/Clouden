<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $sql
 * @property bool $success
 * @property int|null $duration_ms
 * @property int|null $row_count
 * @property string|null $error
 */
class SqlQuery extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sql_query_history';

    protected $fillable = ['project_database_id', 'user_id', 'sql', 'success', 'duration_ms', 'row_count', 'error'];

    protected function casts(): array
    {
        return ['success' => 'boolean'];
    }
}
