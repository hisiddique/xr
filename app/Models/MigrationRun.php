<?php

namespace App\Models;

use App\MigrationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationRun extends Model
{
    protected $fillable = [
        'status',
        'options',
        'legacy_credentials',
        'started_at',
        'finished_at',
        'cancelled_at',
        'error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MigrationRunStatus::class,
            'options' => 'array',
            // Encrypted at rest (Laravel's APP_KEY) since this holds the legacy DB credentials
            // entered per-run — never stored in `options`, and cleared once the run finishes.
            'legacy_credentials' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(MigrationRunTable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
