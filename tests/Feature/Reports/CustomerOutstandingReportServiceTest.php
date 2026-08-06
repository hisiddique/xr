<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('report service returns only customers with unsettled outstanding invoices', function () {
    $paymentMethod = LookupPaymentMethod::factory()->create();
    $customer = Customer::factory()->create();

    $unpaidInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $paidInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 50,
        'doc_date' => now(),
    ]);

    $payment = Payment::factory()->create(['customer_id' => $customer->id, 'payment_method_id' => $paymentMethod->id, 'amount' => 50]);
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $paidInvoice->id,
        'allocated_amount' => 50,
    ]);

    $otherCustomer = Customer::factory()->create();
    Document::factory()->invoice()->create([
        'customer_id' => $otherCustomer->id,
        'total_value' => 20,
        'doc_date' => now(),
    ]);
    $otherPayment = Payment::factory()->create(['customer_id' => $otherCustomer->id, 'payment_method_id' => $paymentMethod->id, 'amount' => 20]);
    PaymentAllocation::create([
        'payment_id' => $otherPayment->id,
        'document_id' => Document::where('customer_id', $otherCustomer->id)->first()->id,
        'allocated_amount' => 20,
    ]);

    $service = app(CustomerOutstandingReportService::class);
    $results = $service->buildExportData([]);

    expect($results)->toHaveCount(1);
    expect($results[0]['company_name'])->toBe($customer->company_name);
    expect($results[0]['invoices'])->toHaveCount(1);
    expect($results[0]['invoices'][0]['doc_number'])->toBe($unpaidInvoice->doc_number);
});

test('report service applies outstanding range filter', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 10, 'doc_date' => now()]);

    $service = app(CustomerOutstandingReportService::class);

    expect($service->buildExportData(['osMin' => 50]))->toHaveCount(0);
    expect($service->buildExportData(['osMin' => 5]))->toHaveCount(1);
});

test('report service treats invoices fully settled by a credit note as not outstanding', function () {
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    CreditAllocation::create([
        'credit_note_id' => $creditNote->id,
        'invoice_id' => $invoice->id,
        'amount' => 100,
    ]);

    $service = app(CustomerOutstandingReportService::class);

    expect($service->buildExportData([]))->toHaveCount(0);
});

test('report service showPaid filter reveals manually settled invoices without affecting payment-settled ones', function () {
    $paymentMethod = LookupPaymentMethod::factory()->create();
    $customer = Customer::factory()->create();

    $unsettledInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $manuallySettledInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 75,
        'doc_date' => now(),
        'is_settled' => true,
    ]);

    $paymentSettledInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 50,
        'doc_date' => now(),
    ]);
    $payment = Payment::factory()->create(['customer_id' => $customer->id, 'payment_method_id' => $paymentMethod->id, 'amount' => 50]);
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $paymentSettledInvoice->id,
        'allocated_amount' => 50,
    ]);

    $service = app(CustomerOutstandingReportService::class);

    $defaultResults = $service->buildExportData([]);
    expect($defaultResults)->toHaveCount(1);
    expect($defaultResults[0]['invoices'])->toHaveCount(1);
    expect($defaultResults[0]['invoices'][0]['doc_number'])->toBe($unsettledInvoice->doc_number);

    $showPaidResults = $service->buildExportData(['showPaid' => true]);
    expect($showPaidResults)->toHaveCount(1);
    $docNumbers = array_column($showPaidResults[0]['invoices'], 'doc_number');
    expect($docNumbers)->toContain($unsettledInvoice->doc_number, $manuallySettledInvoice->doc_number);
    expect($docNumbers)->not->toContain($paymentSettledInvoice->doc_number);
});

test('report service search matches customer name and invoice number', function () {
    $customer = Customer::factory()->create(['company_name' => 'Acme Traders']);
    $invoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 10, 'doc_date' => now()]);

    $service = app(CustomerOutstandingReportService::class);

    expect($service->buildExportData(['search' => 'Acme']))->toHaveCount(1);
    expect($service->buildExportData(['search' => $invoice->doc_number]))->toHaveCount(1);
    expect($service->buildExportData(['search' => 'no-match-xyz']))->toHaveCount(0);
});
