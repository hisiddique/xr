<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Services\SupplierDebitNotePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierDebitNoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupplierDebitNote $debitNote, public ?string $notes = null) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $companyName = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "Debit Note {$this->debitNote->reference} from {$companyName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier-debit-note',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(SupplierDebitNotePdfService::class)->generate($this->debitNote);

        return [
            Attachment::fromData(
                fn () => $pdf,
                "{$this->debitNote->reference}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
