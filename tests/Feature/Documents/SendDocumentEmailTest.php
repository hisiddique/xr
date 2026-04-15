<?php

use App\DocumentStatus;
use App\DocumentType;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Models\DocumentEmailLog;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Services\DocumentEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('vat_rate', '20', 'float');
    Setting::set('company_name', 'Test Co', 'string');
});

it('sends DocumentMail to the recipient', function () {
    Mail::fake();

    $document = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Active,
    ]);

    app(DocumentEmailService::class)->send($document, 'customer@example.com');

    Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($document) {
        return $mail->document->id === $document->id
            && $mail->hasTo('customer@example.com');
    });
});

it('creates a log row with sent status', function () {
    Mail::fake();

    $document = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Active,
    ]);

    $log = app(DocumentEmailService::class)->send($document, 'customer@example.com');

    expect($log)->toBeInstanceOf(DocumentEmailLog::class)
        ->and($log->status)->toBe('sent')
        ->and($log->recipient_email)->toBe('customer@example.com')
        ->and($log->document_id)->toBe($document->id);
});

it('flips invoice status from Active to Emailed after successful send', function () {
    Mail::fake();

    $invoice = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Active,
    ]);

    app(DocumentEmailService::class)->send($invoice, 'customer@example.com');

    expect($invoice->fresh()->status)->toBe(DocumentStatus::Emailed);
});

it('does not overwrite Converted status on an invoice', function () {
    Mail::fake();

    $invoice = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Converted,
    ]);

    app(DocumentEmailService::class)->send($invoice, 'customer@example.com');

    expect($invoice->fresh()->status)->toBe(DocumentStatus::Converted);
});

it('does not flip delivery note status to Emailed after sending', function () {
    Mail::fake();

    $dn = Document::factory()->deliveryNote()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Active,
    ]);

    app(DocumentEmailService::class)->send($dn, 'customer@example.com');

    expect($dn->fresh()->status)->toBe(DocumentStatus::Active);
});

it('creates a failed log and re-throws when mail throws', function () {
    Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP error'));

    $document = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Active,
    ]);

    expect(fn () => app(DocumentEmailService::class)->send($document, 'fail@example.com'))
        ->toThrow(\RuntimeException::class, 'SMTP error');

    expect(DocumentEmailLog::where('document_id', $document->id)->where('status', 'failed')->exists())->toBeTrue();
});
