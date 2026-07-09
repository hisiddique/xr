<?php

namespace App\Models;

use App\SupplierDebitNoteStatus;
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
            $highest = static::query()
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

    public function totalDeductions(): float
    {
        return (float) $this->appliedInvoices->sum(fn ($invoice) => (float) $invoice->pivot->applied_amount);
    }

    public function isApplied(): bool
    {
        return $this->appliedInvoices()->exists();
    }
}
