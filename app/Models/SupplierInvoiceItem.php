<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'product_code',
        'quantity',
        'unit_amount',
        'vat_applicable',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_amount' => 'decimal:2',
            'vat_applicable' => 'boolean',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
