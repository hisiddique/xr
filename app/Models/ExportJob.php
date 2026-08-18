<?php

namespace App\Models;

use App\ExportJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    protected $fillable = [
        'status',
        'type',
        'format',
        'filters',
        'rows_total',
        'rows_processed',
        'download_path',
        'started_at',
        'finished_at',
        'cancelled_at',
        'error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExportJobStatus::class,
            'filters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
