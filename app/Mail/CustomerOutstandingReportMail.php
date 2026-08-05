<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOutstandingReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{data: string, filename: string, mime: string}>  $attachmentsData
     */
    public function __construct(
        public int $customerCount,
        public float $totalOutstanding,
        public ?string $notes,
        public array $attachmentsData,
    ) {}

    public function envelope(): Envelope
    {
        $companyName = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "Customer Outstanding Payments Report from {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-outstanding-report',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $attachment) => Attachment::fromData(fn () => $attachment['data'], $attachment['filename'])
                ->withMime($attachment['mime']),
            $this->attachmentsData,
        );
    }
}
