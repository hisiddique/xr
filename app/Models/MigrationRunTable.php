<?php

namespace App\Models;

use App\MigrationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationRunTable extends Model
{
    protected $fillable = [
        'migration_run_id',
        'entity',
        'status',
        'rows_total',
        'rows_processed',
        'added',
        'updated',
        'skipped',
        'failed',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => MigrationRunStatus::class,
            'rows_total' => 'integer',
            'rows_processed' => 'integer',
            'added' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'migration_run_id');
    }
}
