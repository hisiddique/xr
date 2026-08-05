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
    $results = $service->customersForExport([]);

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($customer->id);
    expect($results->first()->invoices)->toHaveCount(1);
    expect($results->first()->invoices->first()->id)->toBe($unpaidInvoice->id);
});

test('report service applies outstanding range filter', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 10, 'doc_date' => now()]);

    $service = app(CustomerOutstandingReportService::class);

    expect($service->customersForExport(['osMin' => 50]))->toHaveCount(0);
    expect($service->customersForExport(['osMin' => 5]))->toHaveCount(1);
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

    expect($service->customersForExport([]))->toHaveCount(0);
});

test('report service search matches customer name and invoice number', function () {
    $customer = Customer::factory()->create(['company_name' => 'Acme Traders']);
    $invoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 10, 'doc_date' => now()]);

    $service = app(CustomerOutstandingReportService::class);

    expect($service->customersForExport(['search' => 'Acme']))->toHaveCount(1);
    expect($service->customersForExport(['search' => $invoice->doc_number]))->toHaveCount(1);
    expect($service->customersForExport(['search' => 'no-match-xyz']))->toHaveCount(0);
});
