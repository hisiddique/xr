<?php

namespace App\Models;

use App\PaymentSourceType;
use App\Services\PaymentNumberGenerator;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'payment_method_id',
        'source_type',
        'reference',
        'payment_reference',
        'amount',
        'is_exhausted',
        'payment_date',
        'notes',
        'receipt_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'source_type' => PaymentSourceType::class,
            'is_exhausted' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->reference)) {
                $payment->reference = app(PaymentNumberGenerator::class)->next();
            }
        });

        static::deleting(function (Payment $payment): void {
            $payment->allocations()->delete();
            $payment->creditAllocations()->delete();
            $payment->drawsMade()->delete();
            $payment->drawsReceived()->delete();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(LookupPaymentMethod::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(CreditAllocation::class);
    }

    public function drawsMade(): HasMany
    {
        return $this->hasMany(PaymentDraw::class, 'source_payment_id');
    }

    public function drawsReceived(): HasMany
    {
        return $this->hasMany(PaymentDraw::class, 'target_payment_id');
    }

    public function remainingBalance(): float
    {
        return (float) $this->amount
            - (float) $this->allocations()->sum('allocated_amount')
            - (float) $this->drawsMade()->sum('amount');
    }
}
