<?php

namespace App\Models;

use App\StatementRunStatus;
use App\StatementRunTrigger;
use App\StatementSubjectType;
use Database\Factories\StatementDispatchRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatementDispatchRun extends Model
{
    /** @use HasFactory<StatementDispatchRunFactory> */
    use HasFactory;

    protected $fillable = [
        'statement_schedule_id',
        'schedule_name',
        'parent_run_id',
        'model_type',
        'trigger',
        'status',
        'scheduled_for',
        'period_from',
        'period_to',
        'period_label',
        'rules_snapshot',
        'total_count',
        'sent_count',
        'failed_count',
        'skipped_count',
        'error',
        'started_at',
        'finished_at',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'model_type' => StatementSubjectType::class,
            'trigger' => StatementRunTrigger::class,
            'status' => StatementRunStatus::class,
            'scheduled_for' => 'datetime',
            'period_from' => 'date',
            'period_to' => 'date',
            'rules_snapshot' => 'array',
            'total_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(StatementSchedule::class, 'statement_schedule_id');
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StatementDispatchRunItem::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
