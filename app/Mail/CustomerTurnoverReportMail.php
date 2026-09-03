<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerTurnoverReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{data: string, filename: string, mime: string}>  $attachmentsData
     * @param  array<int, array{format: string, url: string}>  $downloadLinks
     */
    public function __construct(
        public int $customerCount,
        public float $totalTurnover,
        public ?string $notes,
        public array $attachmentsData,
        public array $downloadLinks = [],
    ) {}

    public function envelope(): Envelope
    {
        $companyName = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "Customer Turnover Report from {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-turnover-report',
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
