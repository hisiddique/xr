<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\Setting;
use App\Services\DocumentPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $document, public ?string $notes = null) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $typeLabel = $this->document->type->label();
        $docNumber = $this->document->doc_number;
        $companyName = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "{$typeLabel} {$docNumber} from {$companyName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.document',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdfService = app(DocumentPdfService::class);
        $pdfData = $pdfService->generate($this->document);

        return [
            Attachment::fromData(
                fn () => $pdfData,
                "{$this->document->doc_number}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
