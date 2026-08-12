<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $periodLabel,
        public float $totalOutstanding,
        public ?string $notes,
        public string $pdfBinary,
    ) {}

    public function envelope(): Envelope
    {
        $companyName = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "Statement of Account from {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-statement',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, 'customer-statement-'.$this->customer->reference.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
