<?php

namespace App\Models;

use App\SupplierInvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class SupplierInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legacy_uid',
        'supplier_invoice_no',
        'supplier_id',
        'invoice_date',
        'due_date',
        'status',
        'notes',
        'attachments',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierInvoice $invoice): void {
            if (blank($invoice->supplier_invoice_no)) {
                $invoice->supplier_invoice_no = static::nextNumber();
            }
        });

        static::deleting(function (SupplierInvoice $invoice): void {
            $invoice->items()->delete();
        });
    }

    public static function nextNumber(): string
    {
        return DB::transaction(function (): string {
            $prefix = strtoupper((string) Setting::get('supinv_prefix', 'SUPINV'));
            $padding = (int) Setting::get('number_padding', 4);
            $highest = static::query()
                ->where('supplier_invoice_no', 'LIKE', $prefix.'-%')
                ->lockForUpdate()
                ->pluck('supplier_invoice_no')
                ->map(fn (string $no): int => (int) substr($no, strlen($prefix) + 1))
                ->max() ?? 0;

            return sprintf('%s-%s', $prefix, str_pad((string) ($highest + 1), $padding, '0', STR_PAD_LEFT));
        });
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'status' => SupplierInvoiceStatus::class,
            'attachments' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class)->orderBy('sort_order');
    }

    public function payoutAllocations(): HasMany
    {
        return $this->hasMany(SupplierPayoutAllocation::class);
    }

    public function debitNotes(): BelongsToMany
    {
        return $this->belongsToMany(SupplierDebitNote::class, 'supplier_invoice_debit_notes')
            ->withPivot(['applied_amount', 'applied_at'])
            ->withTimestamps();
    }

    public function getOutstandingAmountAttribute(): float
    {
        $paid = (float) $this->payoutAllocations->sum('allocated_amount');
        $deducted = (float) $this->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount);

        return max(0, $this->grossTotal - $paid - $deducted);
    }

    public function getNetTotalAttribute(): float
    {
        return (float) $this->items->sum('line_total');
    }

    public function getVatTotalAttribute(): float
    {
        $rate = (float) Setting::get('vat_rate', 20);

        return (float) $this->items
            ->filter(fn ($item) => $item->vat_applicable)
            ->sum(fn ($item) => round((float) $item->line_total * $rate / 100, 2));
    }

    public function getGrossTotalAttribute(): float
    {
        return round($this->netTotal + $this->vatTotal, 2);
    }
}
