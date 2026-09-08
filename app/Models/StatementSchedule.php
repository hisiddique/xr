<?php

namespace App\Models;

use App\StatementFrequency;
use App\StatementScheduleStatus;
use App\StatementSubjectType;
use Database\Factories\StatementScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StatementSchedule extends Model
{
    /** @use HasFactory<StatementScheduleFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'model_type',
        'status',
        'frequency',
        'customer_group_id',
        'supplier_group_id',
        'exclude_customer_group_id',
        'exclude_supplier_group_id',
        'run_time',
        'day_of_month',
        'day_of_week',
        'anchor_date',
        'rules',
        'notify_enabled',
        'notify_emails',
        'notes',
        'next_run_at',
        'last_run_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'model_type' => StatementSubjectType::class,
            'status' => StatementScheduleStatus::class,
            'frequency' => StatementFrequency::class,
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
            'anchor_date' => 'date',
            'rules' => 'array',
            'notify_enabled' => 'boolean',
            'notify_emails' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<StatementSchedule>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', StatementScheduleStatus::Active)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    public function isCustomer(): bool
    {
        return $this->model_type === StatementSubjectType::Customer;
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function supplierGroup(): BelongsTo
    {
        return $this->belongsTo(SupplierGroup::class, 'supplier_group_id');
    }

    public function excludeCustomerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'exclude_customer_group_id');
    }

    public function excludeSupplierGroup(): BelongsTo
    {
        return $this->belongsTo(SupplierGroup::class, 'exclude_supplier_group_id');
    }

    public function targetGroup(): CustomerGroup|SupplierGroup|null
    {
        return $this->isCustomer() ? $this->customerGroup : $this->supplierGroup;
    }

    public function exclusionGroup(): CustomerGroup|SupplierGroup|null
    {
        return $this->isCustomer() ? $this->excludeCustomerGroup : $this->excludeSupplierGroup;
    }

    public function runs(): HasMany
    {
        return $this->hasMany(StatementDispatchRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
