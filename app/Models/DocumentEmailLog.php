<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentEmailLog extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentEmailLogFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'recipient_email',
        'sent_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
