<?php

namespace App\Models;

use Database\Factories\OverheadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Overhead extends Model
{
    /** @use HasFactory<OverheadFactory> */
    use HasFactory;

    protected $fillable = ['category_id', 'expense_date', 'amount', 'has_vat', 'payment_method'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'has_vat' => 'boolean',
            'amount' => 'decimal:2',
        ];
    }

    public function getVatAmountAttribute(): float
    {
        if (! $this->has_vat) {
            return 0.0;
        }
        $rate = (float) Setting::get('vat_rate', 20);

        return (float) $this->amount * $rate / (100 + $rate);
    }

    public function getNetAmountAttribute(): float
    {
        return (float) $this->amount - $this->vatAmount;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}
