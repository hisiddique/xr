<?php

namespace App\Models;

use App\StatementRunItemStatus;
use App\StatementSubjectType;
use Database\Factories\StatementDispatchRunItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementDispatchRunItem extends Model
{
    /** @use HasFactory<StatementDispatchRunItemFactory> */
    use HasFactory;

    protected $fillable = [
        'statement_dispatch_run_id',
        'recipient_type',
        'customer_id',
        'supplier_id',
        'recipient_name',
        'recipient_email',
        'status',
        'skip_reason',
        'error_message',
        'outstanding_total',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_type' => StatementSubjectType::class,
            'status' => StatementRunItemStatus::class,
            'outstanding_total' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(StatementDispatchRun::class, 'statement_dispatch_run_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
