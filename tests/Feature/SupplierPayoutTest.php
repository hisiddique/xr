<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\Services\SupplierPayoutAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::updateOrCreate(['key' => 'suppo_prefix'], ['key' => 'suppo_prefix', 'value' => 'SUPPO', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'number_padding'], ['key' => 'number_padding', 'value' => '4', 'type' => 'integer']);
    Setting::flushCache();
});

test('nextNumber generates SUPPO prefix padded to 4 digits', function () {
    $number = SupplierPayout::nextNumber();

    expect($number)->toBe('SUPPO-0001');
});

test('creating a payout sets reference automatically', function () {
    $supplier = Supplier::factory()->create();

    $payout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 500,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    expect($payout->reference)->toBe('SUPPO-0001');
});

test('soft-deleted payouts still occupy their number so it is not reused', function () {
    $supplier = Supplier::factory()->create();

    $payout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 500,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);
    $payout->delete();

    expect(SupplierPayout::nextNumber())->toBe('SUPPO-0002');
});

test('soft-deleting a payout deletes its allocations', function () {
    $supplier = Supplier::factory()->create();

    $payout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 200,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'deduction_amount' => 0,
        'allocated_amount' => 200,
    ]);

    expect(SupplierPayoutAllocation::where('supplier_payout_id', $payout->id)->count())->toBe(1);

    $payout->delete();

    expect(SupplierPayoutAllocation::where('supplier_payout_id', $payout->id)->count())->toBe(0);
});

test('SupplierPayoutAllocator save creates allocations for payout', function () {
    $supplier = Supplier::factory()->create();

    $payout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 150,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 150, 'vat_applicable' => false, 'line_total' => 150]), 'items')
        ->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id]);

    $rows = [
        [
            'invoice_id' => $invoice->id,
            'debit_note_id' => null,
            'deduction_amount' => 0,
            'allocated_amount' => 150,
        ],
    ];

    (new SupplierPayoutAllocator)->save($payout, $rows);

    expect(SupplierPayoutAllocation::where('supplier_payout_id', $payout->id)->count())->toBe(1)
        ->and(SupplierPayoutAllocation::where('supplier_payout_id', $payout->id)->value('allocated_amount'))->toBe('150.00');
});
