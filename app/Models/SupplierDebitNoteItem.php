<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDebitNoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_uid',
        'supplier_debit_note_id',
        'description',
        'quantity',
        'amount',
        'total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'amount' => 'decimal:2',
            'total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function supplierDebitNote(): BelongsTo
    {
        return $this->belongsTo(SupplierDebitNote::class);
    }
}
