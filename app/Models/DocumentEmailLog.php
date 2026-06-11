<?php

namespace App\Models;

use Database\Factories\DocumentEmailLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentEmailLog extends Model
{
    /** @use HasFactory<DocumentEmailLogFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'recipient_email',
        'recipient_emails',
        'sent_at',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'recipient_emails' => 'array',
        ];
    }
}
