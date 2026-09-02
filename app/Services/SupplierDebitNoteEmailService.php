<?php

namespace App\Services;

use App\Mail\SupplierDebitNoteMail;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteEmailLog;
use Illuminate\Support\Facades\Mail;

class SupplierDebitNoteEmailService
{
    /**
     * Send the supplier debit note by email and log the result.
     *
     * @param  array<int, string>  $emails
     *
     * @throws \Throwable on mail failure (after logging)
     */
    public function send(SupplierDebitNote $debitNote, array $emails, ?string $notes = null): SupplierDebitNoteEmailLog
    {
        $emails = array_values(array_unique(array_filter($emails)));
        $primary = $emails[0];

        try {
            Mail::to($emails)->send(new SupplierDebitNoteMail($debitNote, $notes));

            return SupplierDebitNoteEmailLog::create([
                'supplier_debit_note_id' => $debitNote->id,
                'recipient_email' => $primary,
                'recipient_emails' => $emails,
                'sent_at' => now(),
                'status' => 'sent',
            ]);
        } catch (\Throwable $e) {
            SupplierDebitNoteEmailLog::create([
                'supplier_debit_note_id' => $debitNote->id,
                'recipient_email' => $primary,
                'recipient_emails' => $emails,
                'sent_at' => null,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
