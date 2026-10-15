<?php

namespace App\Models;

use App\Support\Money;
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
        'quantity',
        'unit_amount',
        'vat_applicable',
        'line_total',
        'gross_override',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_amount' => 'decimal:2',
            'vat_applicable' => 'boolean',
            'line_total' => 'decimal:2',
            'gross_override' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function getLineVatAttribute(): float
    {
        if (! $this->vat_applicable) {
            return 0.0;
        }

        $net = (float) $this->line_total;

        if ($this->gross_override !== null) {
            return round((float) $this->gross_override - $net, 2);
        }

        $rate = (float) Setting::get('vat_rate', 20);

        return Money::roundDown($net * $rate / 100);
    }

    public function getLineGrossAttribute(): float
    {
        return round((float) $this->line_total + $this->line_vat, 2);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
