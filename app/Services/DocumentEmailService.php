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
     * @throws \Throwable on mail failure (after logging)
     */
    public function send(Document $document, string $recipient): DocumentEmailLog
    {
        try {
            Mail::to($recipient)->send(new DocumentMail($document));

            $log = DocumentEmailLog::create([
                'document_id' => $document->id,
                'recipient_email' => $recipient,
                'sent_at' => now(),
                'status' => 'sent',
            ]);

            // Only flip invoice status from Active → Emailed (never overwrite Converted).
            // Delivery notes keep their current status.
            if (
                $document->type === DocumentType::Invoice &&
                $document->status === DocumentStatus::Active
            ) {
                $document->update(['status' => DocumentStatus::Emailed]);
            }

            return $log;
        } catch (\Throwable $e) {
            $log = DocumentEmailLog::create([
                'document_id' => $document->id,
                'recipient_email' => $recipient,
                'sent_at' => null,
                'status' => 'failed',
            ]);

            throw $e;
        }
    }
}
