<?php

use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('vat_rate', '20', 'integer');
});

function convertedInvoiceWithWrongTotals(array $attributes = []): Document
{
    $dn = Document::factory()->deliveryNote()->create();

    return Document::factory()->invoice()
        ->has(DocumentItem::factory()->state(['quantity' => 500, 'price' => 10, 'per' => '100', 'line_value' => 50]), 'items')
        ->create($attributes + [
            'converted_from_id' => $dn->id,
            'trade_discount' => 10,
            'subtotal' => 5000,
            'discount_amount' => 500,
            'vat_amount' => 900,
            'total_value' => 5400,
        ]);
}

it('previews without writing on a dry run', function () {
    $invoice = convertedInvoiceWithWrongTotals();

    $this->artisan('invoices:recalculate-converted-totals', ['--dry-run' => true])->assertSuccessful();

    expect((float) $invoice->fresh()->subtotal)->toBe(5000.0);
});

it('recalculates converted invoices using the item per unit and their own discount and VAT status', function () {
    $invoice = convertedInvoiceWithWrongTotals();

    $this->artisan('invoices:recalculate-converted-totals')
        ->expectsConfirmation('Update 1 invoice(s)?', 'yes')
        ->assertSuccessful();

    $invoice->refresh();

    expect((float) $invoice->subtotal)->toBe(50.0)
        ->and((float) $invoice->discount_amount)->toBe(5.0)
        ->and((float) $invoice->vat_amount)->toBe(9.0)
        ->and((float) $invoice->total_value)->toBe(54.0);
});

it('only considers invoices that have an item with a numeric or lot per', function () {
    $dn = Document::factory()->deliveryNote()->create();
    $plainUnit = Document::factory()->invoice()
        ->has(DocumentItem::factory()->state(['quantity' => 2, 'price' => 10, 'per' => 'each', 'line_value' => 20]), 'items')
        ->create(['converted_from_id' => $dn->id, 'subtotal' => 999, 'total_value' => 999]);
    $lot = convertedInvoiceWithWrongTotals();
    $lot->items()->update(['per' => 'lot', 'quantity' => 5, 'price' => 40, 'line_value' => 40]);

    $this->artisan('invoices:recalculate-converted-totals')
        ->expectsConfirmation('Update 1 invoice(s)?', 'yes')
        ->assertSuccessful();

    expect((float) $plainUnit->fresh()->subtotal)->toBe(999.0)
        ->and((float) $lot->fresh()->subtotal)->toBe(40.0);
});

it('leaves correct and non-converted invoices alone', function () {
    $standalone = Document::factory()->invoice()
        ->has(DocumentItem::factory()->state(['quantity' => 500, 'price' => 10, 'per' => '100', 'line_value' => 50]), 'items')
        ->create(['subtotal' => 5000, 'total_value' => 5000]);

    $this->artisan('invoices:recalculate-converted-totals')
        ->expectsOutput('No converted invoices need recalculating.')
        ->assertSuccessful();

    expect((float) $standalone->fresh()->subtotal)->toBe(5000.0);
});
