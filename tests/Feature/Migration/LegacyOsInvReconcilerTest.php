<?php

use App\Models\Document;
use App\Services\Migration\LegacyOsInvReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    Schema::dropIfExists('t_os_inv');
});

test('tableAvailable returns false when t_os_inv does not exist', function () {
    $reconciler = new LegacyOsInvReconciler(null);

    expect($reconciler->tableAvailable())->toBeFalse();
});

test('tableAvailable returns true when t_os_inv exists with an invoice column', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    $reconciler = new LegacyOsInvReconciler(null);

    expect($reconciler->tableAvailable())->toBeTrue();
});

test('an invoice not found in t_os_inv is flagged settled', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    $invoice = Document::factory()->invoice()->create(['doc_number' => 'INV-0001', 'total_value' => 100]);

    $reconciler = new LegacyOsInvReconciler(null);
    $reconciler->apply($reconciler->plan('B1'));

    $invoice->refresh();

    expect($invoice->is_settled)->toBeTrue()
        ->and($invoice->legacy_confirmed_paid)->toBeTrue()
        ->and($invoice->legacy_confirmed_paid_batch)->toBe('B1')
        ->and($invoice->notes)->toBe('Legacy System Confirmed that the invoice has been settled');
});

test('an invoice found in t_os_inv is left untouched', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    DB::table('t_os_inv')->insert(['invoice' => 'INV-0002']);

    $invoice = Document::factory()->invoice()->create(['doc_number' => 'INV-0002', 'total_value' => 100]);

    $reconciler = new LegacyOsInvReconciler(null);
    $reconciler->apply($reconciler->plan('B1'));

    $invoice->refresh();

    expect($invoice->legacy_confirmed_paid)->toBeFalse()
        ->and($invoice->is_settled)->toBeFalse();
});

test('a non-invoice document is never touched regardless of t_os_inv contents', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    $deliveryNote = Document::factory()->create(['doc_number' => 'DN-0001']);

    $reconciler = new LegacyOsInvReconciler(null);
    $reconciler->apply($reconciler->plan('B1'));

    expect($deliveryNote->fresh()->legacy_confirmed_paid)->toBeFalse();
});

test('an invoice already flagged legacy_confirmed_paid is excluded from the plan', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    $invoice = Document::factory()->invoice()->create([
        'doc_number' => 'INV-0003',
        'total_value' => 100,
        'legacy_confirmed_paid' => true,
        'legacy_confirmed_paid_batch' => 'PRIOR',
    ]);

    $reconciler = new LegacyOsInvReconciler(null);
    $plan = $reconciler->plan('B1');

    expect($plan['document_ids'])->not->toContain($invoice->id);

    $reconciler->apply($plan);

    expect($invoice->fresh()->legacy_confirmed_paid_batch)->toBe('PRIOR');
});

test('excludeCustomerId keeps that customer invoices from being flagged', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    $morrInvoice = Document::factory()->invoice()->create(['doc_number' => 'INV-0004', 'total_value' => 100]);
    $normalInvoice = Document::factory()->invoice()->create(['doc_number' => 'INV-0005', 'total_value' => 100]);

    $reconciler = new LegacyOsInvReconciler($morrInvoice->customer_id);
    $reconciler->apply($reconciler->plan('B1'));

    expect($morrInvoice->fresh()->legacy_confirmed_paid)->toBeFalse()
        ->and($normalInvoice->fresh()->legacy_confirmed_paid)->toBeTrue();
});

test('isEmpty reflects whether the plan has documents to flag', function () {
    Schema::create('t_os_inv', function ($table) {
        $table->id();
        $table->string('invoice')->nullable();
    });

    $reconciler = new LegacyOsInvReconciler(null);

    $emptyPlan = $reconciler->plan('B1');
    expect($reconciler->isEmpty($emptyPlan))->toBeTrue();

    Document::factory()->invoice()->create(['doc_number' => 'INV-0006', 'total_value' => 100]);

    $nonEmptyPlan = $reconciler->plan('B2');
    expect($reconciler->isEmpty($nonEmptyPlan))->toBeFalse();
});
