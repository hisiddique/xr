<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentItem extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentItemFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'details',
        'quantity',
        'price',
        'per',
        'line_value',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'decimal:2',
            'line_value' => 'decimal:2',
        ];
    }
}
