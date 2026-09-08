<?php

namespace App\Mail;

use App\Models\StatementDispatchRun;
use App\StatementRunItemStatus;
use App\StatementRunStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StatementDispatchSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    private ?StatementDispatchRun $run = null;

    public function __construct(public int $runId) {}

    public function envelope(): Envelope
    {
        $run = $this->run();

        return new Envelope(
            subject: 'Statement dispatch '.$this->outcomeWord().' — '.$run->schedule_name,
        );
    }

    public function content(): Content
    {
        $run = $this->run();

        return new Content(
            view: 'emails.statement-dispatch-summary',
            with: [
                'run' => $run,
                'failedItems' => $run->items()
                    ->where('status', StatementRunItemStatus::Failed)
                    ->limit(50)
                    ->get(),
            ],
        );
    }

    private function run(): StatementDispatchRun
    {
        return $this->run ??= StatementDispatchRun::find($this->runId);
    }

    private function outcomeWord(): string
    {
        return match ($this->run()->status) {
            StatementRunStatus::Failed => 'failed',
            StatementRunStatus::CompletedWithErrors => 'completed with errors',
            default => 'completed',
        };
    }
}
