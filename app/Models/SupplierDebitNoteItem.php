<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierDebitNoteItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legacy_uid',
        'supplier_debit_note_id',
        'description',
        'quantity',
        'amount',
        'per',
        'is_note',
        'discount_percent',
        'line_value',
        'vat_applicable',
        'total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_note' => 'boolean',
            'discount_percent' => 'decimal:2',
            'line_value' => 'decimal:2',
            'vat_applicable' => 'boolean',
            'total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function supplierDebitNote(): BelongsTo
    {
        return $this->belongsTo(SupplierDebitNote::class);
    }
}
