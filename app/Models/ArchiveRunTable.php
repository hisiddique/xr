<?php

namespace App\Models;

use App\ArchiveRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveRunTable extends Model
{
    protected $fillable = [
        'archive_run_id',
        'entity',
        'status',
        'rows_total',
        'rows_copied',
        'rows_deleted',
        'rows_skipped',
        'rows_failed',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArchiveRunStatus::class,
            'rows_total' => 'integer',
            'rows_copied' => 'integer',
            'rows_deleted' => 'integer',
            'rows_skipped' => 'integer',
            'rows_failed' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ArchiveRun::class, 'archive_run_id');
    }
}
