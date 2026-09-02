<?php

namespace App\Models;

use Database\Factories\SupplierDebitNoteEmailLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierDebitNoteEmailLog extends Model
{
    /** @use HasFactory<SupplierDebitNoteEmailLogFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_debit_note_id',
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
