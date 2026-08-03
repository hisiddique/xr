<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentDraw extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source_payment_id',
        'target_payment_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function targetPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'target_payment_id');
    }
}
