<?php

namespace App\Models;

use Database\Factories\DocumentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentItem extends Model
{
    /** @use HasFactory<DocumentItemFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legacy_uid',
        'document_id',
        'details',
        'is_note',
        'quantity',
        'price',
        'per',
        'line_value',
        'discount_percent',
        'net_value',
    ];

    protected function casts(): array
    {
        return [
            'is_note' => 'boolean',
            'quantity' => 'decimal:2',
            'price' => 'decimal:2',
            'line_value' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'net_value' => 'decimal:2',
        ];
    }
}
