<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier): void {
            if (blank($supplier->reference)) {
                $supplier->reference = static::nextReference();
            }
        });
    }

    public static function nextReference(): string
    {
        return DB::transaction(function (): string {
            $prefix = strtoupper((string) Setting::get('sup_prefix', 'SUP'));
            $padding = (int) Setting::get('number_padding', 4);
            $highest = static::withTrashed()
                ->where('reference', 'LIKE', $prefix.'-%')
                ->lockForUpdate()
                ->pluck('reference')
                ->map(fn (string $reference): int => (int) substr($reference, strlen($prefix) + 1))
                ->max() ?? 0;

            return sprintf('%s-%s', $prefix, str_pad((string) ($highest + 1), $padding, '0', STR_PAD_LEFT));
        });
    }

    protected $fillable = [
        'company_name',
        'reference',
        'title_id',
        'first_name',
        'last_name',
        'trade_discount',
        'vat_applied',
        'credit_limit_id',
        'credit_term_id',
        'supplier_vat_number',
        'email',
        'vat_registered',
        'address_line_1',
        'address_line_2',
        'town_city',
        'post_code',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trade_discount' => 'decimal:2',
            'vat_applied' => 'boolean',
            'vat_registered' => 'boolean',
        ];
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(LookupTitle::class, 'title_id');
    }

    public function creditTerm(): BelongsTo
    {
        return $this->belongsTo(LookupCreditTerm::class, 'credit_term_id');
    }

    public function creditLimit(): BelongsTo
    {
        return $this->belongsTo(LookupCreditLimit::class, 'credit_limit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(SupplierDebitNote::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(SupplierPayout::class);
    }

    public function getTypeaheadLabelAttribute(): string
    {
        return $this->reference
            ? "{$this->company_name} ({$this->reference})"
            : $this->company_name;
    }
}
