<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
});

test('customer with documents or payments cannot be deleted and the message names what to delete first', function () {
    $customer = Customer::factory()->create(['company_name' => 'Acme Ltd']);
    Document::factory()->invoice()->count(2)->create(['customer_id' => $customer->id]);
    Document::factory()->deliveryNote()->create(['customer_id' => $customer->id]);
    Payment::factory()->create(['customer_id' => $customer->id]);

    expect($customer->deletionBlockedMessage('Acme Ltd'))
        ->toContain('Acme Ltd')
        ->toContain('1 delivery note')
        ->toContain('2 invoices')
        ->toContain('1 payment')
        ->toContain('Delete these first');

    Livewire::test('pages::customers.delete-modal', ['customer' => $customer])
        ->call('deleteCustomer')
        ->assertDispatched('toast-show')
        ->assertNotDispatched('customer-deleted');

    $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
});

test('customer without related records is still deleted', function () {
    $customer = Customer::factory()->create();

    Livewire::test('pages::customers.delete-modal', ['customer' => $customer])
        ->call('deleteCustomer')
        ->assertDispatched('customer-deleted');

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

test('supplier with invoices cannot be deleted', function () {
    $supplier = Supplier::factory()->create();
    SupplierInvoice::factory()->create(['supplier_id' => $supplier->id]);

    expect($supplier->deletionBlockedMessage($supplier->company_name))->toContain('1 supplier invoice');

    Livewire::test('pages::suppliers.delete-modal', ['supplier' => $supplier])
        ->call('deleteSupplier')
        ->assertNotDispatched('supplier-deleted');

    $this->assertNotSoftDeleted('suppliers', ['id' => $supplier->id]);
});

test('supplier without related records is still deleted', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::suppliers.delete-modal', ['supplier' => $supplier])
        ->call('deleteSupplier')
        ->assertDispatched('supplier-deleted');

    $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
});

test('delete modal says a customer with no related records can be deleted safely', function () {
    $customer = Customer::factory()->create(['company_name' => 'Acme Ltd']);

    Livewire::test('pages::customers.delete-modal', ['customer' => $customer])
        ->assertDontSee('can be deleted safely')
        ->call('checkRelatedRecords')
        ->assertSee('Acme Ltd has no delivery notes, invoices, credit notes or payments, so it can be deleted safely.');
});

test('delete modal does not claim safe deletion when a supplier has related records', function () {
    $supplier = Supplier::factory()->create();
    SupplierInvoice::factory()->create(['supplier_id' => $supplier->id]);

    Livewire::test('pages::suppliers.delete-modal', ['supplier' => $supplier])
        ->call('checkRelatedRecords')
        ->assertDontSee('can be deleted safely');
});

test('already soft-deleted children do not block deletion', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id])->delete();

    expect($customer->deletionBlockers())->toBe([]);
});
