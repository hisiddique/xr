<?php

namespace App\Models;

use App\SupplierDebitNoteStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class SupplierDebitNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legacy_uid',
        'supplier_id',
        'reference',
        'doc_date',
        'supplier_invoice_id',
        'notes',
        'subtotal',
        'vat_amount',
        'vat_rate',
        'total',
        'status',
        'created_by',
        'deleted_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierDebitNote $note): void {
            if (blank($note->reference)) {
                $note->reference = static::nextNumber();
            }
        });
    }

    public static function nextNumber(): string
    {
        return DB::transaction(function (): string {
            $prefix = strtoupper((string) Setting::get('supdn_prefix', 'SUPDN'));
            $padding = (int) Setting::get('number_padding', 4);
            $highest = static::withTrashed()
                ->where('reference', 'LIKE', $prefix.'-%')
                ->lockForUpdate()
                ->pluck('reference')
                ->map(fn (string $no): int => (int) substr($no, strlen($prefix) + 1))
                ->max() ?? 0;

            return sprintf('%s-%s', $prefix, str_pad((string) ($highest + 1), $padding, '0', STR_PAD_LEFT));
        });
    }

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => SupplierDebitNoteStatus::class,
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierDebitNoteItem::class)->orderBy('sort_order');
    }

    public function payoutAllocations(): HasMany
    {
        return $this->hasMany(SupplierPayoutAllocation::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(SupplierDebitNoteEmailLog::class);
    }

    public function appliedInvoices(): BelongsToMany
    {
        return $this->belongsToMany(SupplierInvoice::class, 'supplier_invoice_debit_notes')
            ->withPivot(['applied_amount', 'applied_at'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function effectiveVatRate(): float
    {
        if ($this->vat_rate !== null) {
            return (float) $this->vat_rate;
        }

        $vatApplicableItems = $this->items->where('vat_applicable', true);
        $vatApplicableSubtotal = (float) $vatApplicableItems->sum('total');

        if ($vatApplicableSubtotal <= 0) {
            return 0.0;
        }

        $configuredRate = (float) Setting::get('vat_rate', 20);
        $flooredVat = $vatApplicableItems->sum(fn ($item) => Money::roundDown((float) $item->total * $configuredRate / 100));

        if (abs($flooredVat - (float) $this->vat_amount) < 0.005) {
            return $configuredRate;
        }

        return round(((float) $this->vat_amount / $vatApplicableSubtotal) * 100, 4);
    }

    public function totalDeductions(): float
    {
        return (float) $this->appliedInvoices->sum(fn ($invoice) => (float) $invoice->pivot->applied_amount);
    }

    public function isApplied(): bool
    {
        return $this->appliedInvoices()->exists();
    }
}
