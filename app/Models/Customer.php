<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (blank($customer->reference)) {
                $customer->reference = static::nextReference();
            }
        });
    }

    public static function nextReference(): string
    {
        return DB::transaction(function (): string {
            $highest = static::withTrashed()
                ->where('reference', 'LIKE', 'CUST-%')
                ->lockForUpdate()
                ->pluck('reference')
                ->map(fn (string $reference): int => (int) substr($reference, 5))
                ->max() ?? 0;

            return sprintf('CUST-%s', str_pad((string) ($highest + 1), 5, '0', STR_PAD_LEFT));
        });
    }

    protected $fillable = [
        'company_name',
        'reference',
        'title_id',
        'first_name',
        'last_name',
        'address_1',
        'address_2',
        'town',
        'post_code',
        'email_1',
        'trade_discount',
        'vat_registered',
        'credit_term_id',
        'credit_limit_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trade_discount' => 'decimal:2',
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

    public function getTypeaheadLabelAttribute(): string
    {
        $name = $this->company_name ?: trim($this->first_name.' '.$this->last_name);

        return $this->reference ? "{$name} ({$this->reference})" : $name;
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(Document::class)->where('type', 'DN');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Document::class)->where('type', 'INV');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Document::class)->where('type', 'CN');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
