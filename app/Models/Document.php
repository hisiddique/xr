<?php

namespace App\Models;

use App\DocumentStatus;
use App\DocumentType;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'type',
        'doc_number',
        'order_no',
        'doc_date',
        'subtotal',
        'trade_discount',
        'discount_amount',
        'vat_amount',
        'total_value',
        'show_pricing',
        'print_count',
        'status',
        'created_by',
        'assigned_to',
        'converted_from_id',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'subtotal' => 'decimal:2',
            'trade_discount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_value' => 'decimal:2',
            'show_pricing' => 'boolean',
            'print_count' => 'integer',
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(DocumentEmailLog::class);
    }

    /**
     * The delivery note this invoice was converted from.
     */
    public function convertedFrom(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'converted_from_id');
    }

    /**
     * The invoice that was produced by converting this delivery note.
     */
    public function convertedTo(): HasOne
    {
        return $this->hasOne(Document::class, 'converted_from_id');
    }

    public function scopeDeliveryNotes(Builder $query): Builder
    {
        return $query->where('type', DocumentType::DeliveryNote);
    }

    public function scopeInvoices(Builder $query): Builder
    {
        return $query->where('type', DocumentType::Invoice);
    }
}
