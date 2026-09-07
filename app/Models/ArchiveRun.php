<?php

namespace App\Models;

use App\ArchiveRunMode;
use App\ArchiveRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchiveRun extends Model
{
    protected $fillable = [
        'status',
        'mode',
        'options',
        'archive_credentials',
        'started_at',
        'finished_at',
        'cancelled_at',
        'error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArchiveRunStatus::class,
            'mode' => ArchiveRunMode::class,
            'options' => 'array',
            // Encrypted at rest (Laravel's APP_KEY) since this holds the archive DB credentials
            // entered/derived per-run — never stored in `options`, and cleared once the run finishes.
            'archive_credentials' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(ArchiveRunTable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
