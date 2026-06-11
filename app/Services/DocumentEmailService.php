<?php

namespace App\Services;

use App\DocumentStatus;
use App\DocumentType;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Models\DocumentEmailLog;
use Illuminate\Support\Facades\Mail;

class DocumentEmailService
{
    /**
     * Send the document by email and log the result.
     *
     * @param  array<int, string>  $emails
     *
     * @throws \Throwable on mail failure (after logging)
     */
    public function send(Document $document, array $emails, ?string $notes = null): DocumentEmailLog
    {
        $emails = array_values(array_unique(array_filter($emails)));
        $primary = $emails[0];

        try {
            Mail::to($emails)->send(new DocumentMail($document, $notes));

            $log = DocumentEmailLog::create([
                'document_id' => $document->id,
                'recipient_email' => $primary,
                'recipient_emails' => $emails,
                'sent_at' => now(),
                'status' => 'sent',
            ]);

            if (
                $document->type === DocumentType::Invoice &&
                $document->status === DocumentStatus::Active
            ) {
                $document->update(['status' => DocumentStatus::Emailed]);
            }

            return $log;
        } catch (\Throwable $e) {
            DocumentEmailLog::create([
                'document_id' => $document->id,
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
