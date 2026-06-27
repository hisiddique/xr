<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class SupplierPayout extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'reference',
        'amount',
        'payout_date',
        'processing_date',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierPayout $payout): void {
            if (blank($payout->reference)) {
                $payout->reference = static::nextNumber();
            }
        });

        static::deleting(function (SupplierPayout $payout): void {
            $payout->allocations()->delete();
        });
    }

    public static function nextNumber(): string
    {
        return DB::transaction(function (): string {
            $prefix = strtoupper((string) Setting::get('suppo_prefix', 'SUPPO'));
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
            'amount' => 'decimal:2',
            'payout_date' => 'date',
            'processing_date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPayoutAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
