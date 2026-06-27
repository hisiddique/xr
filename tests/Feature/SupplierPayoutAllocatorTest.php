<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\Services\SupplierPayoutAllocator;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->supplier = Supplier::factory()->create();
    $this->allocator = new SupplierPayoutAllocator;

    Setting::updateOrCreate(['key' => 'suppo_prefix'], ['key' => 'suppo_prefix', 'value' => 'SUPPO', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'number_padding'], ['key' => 'number_padding', 'value' => '4', 'type' => 'integer']);
    Setting::updateOrCreate(['key' => 'supinv_prefix'], ['key' => 'supinv_prefix', 'value' => 'SUPINV', 'type' => 'string']);
    Setting::flushCache();
});

function makeInvoice(Supplier $supplier, User $user, float $amount, string $date): SupplierInvoice
{
    return SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state([
            'quantity' => 1,
            'unit_amount' => $amount,
            'vat_applicable' => false,
            'line_total' => $amount,
        ]), 'items')
        ->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'invoice_date' => $date,
        ]);
}

test('autoAllocate distributes payout FIFO across invoices', function () {
    $inv1 = makeInvoice($this->supplier, $this->user, 100, '2024-01-01');
    $inv2 = makeInvoice($this->supplier, $this->user, 200, '2024-02-01');
    makeInvoice($this->supplier, $this->user, 150, '2024-03-01');

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 150,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $rows = $this->allocator->autoAllocate($payout);

    expect($rows)->toHaveCount(2);

    $byInvoice = collect($rows)->keyBy('invoice_id');
    expect($byInvoice[$inv1->id]['allocated_amount'])->toBe(100.0);
    expect($byInvoice[$inv2->id]['allocated_amount'])->toBe(50.0);
});

test('autoAllocate accounts for debit note deductions on effective amount', function () {
    $inv1 = makeInvoice($this->supplier, $this->user, 100, '2024-01-01');
    $inv2 = makeInvoice($this->supplier, $this->user, 200, '2024-02-01');

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 30,
        'total' => 30,
        'created_by' => $this->user->id,
    ]);

    $inv1->debitNotes()->attach($debitNote->id, ['applied_amount' => 30, 'applied_at' => now()]);

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 150,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $rows = $this->allocator->autoAllocate($payout);

    $byInvoice = collect($rows)->keyBy('invoice_id');
    expect((float) $byInvoice[$inv1->id]['allocated_amount'])->toBe(70.0);
    expect((float) $byInvoice[$inv2->id]['allocated_amount'])->toBe(80.0);
});

test('save throws InvalidArgumentException when allocated total exceeds payout amount', function () {
    $inv1 = makeInvoice($this->supplier, $this->user, 300, '2024-01-01');

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 100,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $rows = [
        [
            'invoice_id' => $inv1->id,
            'debit_note_id' => null,
            'deduction_amount' => 0,
            'allocated_amount' => 200,
        ],
    ];

    expect(fn () => $this->allocator->save($payout, $rows))
        ->toThrow(InvalidArgumentException::class);
});

test('autoAllocate on edit excludes payout own existing allocations from already paid', function () {
    $inv1 = makeInvoice($this->supplier, $this->user, 100, '2024-01-01');
    $inv2 = makeInvoice($this->supplier, $this->user, 200, '2024-02-01');

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 150,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $inv1->id,
        'deduction_amount' => 0,
        'allocated_amount' => 100,
    ]);

    $rows = $this->allocator->autoAllocate($payout);

    $byInvoice = collect($rows)->keyBy('invoice_id');
    expect((float) $byInvoice[$inv1->id]['allocated_amount'])->toBe(100.0);
    expect((float) $byInvoice[$inv2->id]['allocated_amount'])->toBe(50.0);
});
