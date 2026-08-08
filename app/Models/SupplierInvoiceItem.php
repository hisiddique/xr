<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierInvoiceItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legacy_uid',
        'supplier_invoice_id',
        'product_code',
        'invoice_no',
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

    public function getLineGrossAttribute(): float
    {
        $net = (float) $this->line_total;
        if (! $this->vat_applicable) {
            return $net;
        }
        $rate = (float) Setting::get('vat_rate', 20);

        return round($net + $net * $rate / 100, 2);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
