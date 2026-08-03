<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierPayoutAllocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_payout_id',
        'supplier_invoice_id',
        'supplier_debit_note_id',
        'deduction_amount',
        'allocated_amount',
    ];

    protected function casts(): array
    {
        return [
            'deduction_amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
        ];
    }

    public function supplierPayout(): BelongsTo
    {
        return $this->belongsTo(SupplierPayout::class);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function supplierDebitNote(): BelongsTo
    {
        return $this->belongsTo(SupplierDebitNote::class)->withTrashed();
    }
}
