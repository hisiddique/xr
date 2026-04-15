<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory, SoftDeletes;

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
        'credit_term_id',
        'credit_limit_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trade_discount' => 'decimal:2',
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
}
