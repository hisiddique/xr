<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentAllocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_id',
        'document_id',
        'allocated_amount',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (PaymentAllocation $allocation): void {
            if ($allocation->document && $allocation->document->legacy_confirmed_paid) {
                $allocation->document->update([
                    'legacy_confirmed_paid' => false,
                    'legacy_confirmed_paid_batch' => null,
                ]);
            }
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }
}
