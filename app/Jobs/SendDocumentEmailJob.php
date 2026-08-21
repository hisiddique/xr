<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDocumentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $emails
     */
    public function __construct(
        private readonly int $documentId,
        private readonly array $emails,
        private readonly ?string $notes = null,
    ) {
        $this->onQueue('emails');
    }

    public function handle(DocumentEmailService $service): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        $service->send($document, $this->emails, $this->notes);
    }
}
