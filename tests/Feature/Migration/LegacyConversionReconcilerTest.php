<?php

use App\DocumentStatus;
use App\Models\Customer;
use App\Models\Document;
use App\Services\Migration\LegacyConversionReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents']);
    $this->reconciler = new LegacyConversionReconciler;
});

function insertLegacyDoc(array $overrides): void
{
    DB::connection('legacy')->table('Documents')->insert(array_merge([
        'rtype' => 'd', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 10, 'value' => 12, 'notes' => null, 'ref' => (string) $overrides['uid'], 'bline' => 0,
        'invuid' => null, 'origdeln' => null, 'emailsent' => null, 'printtime' => null, 'status' => null,
    ], $overrides));
}

test('resolves converted_from_id from the delivery note invuid link', function () {
    insertLegacyDoc(['uid' => 10, 'rtype' => 'd', 'invuid' => 20]);
    insertLegacyDoc(['uid' => 20, 'rtype' => 'i']);

    $customer = Customer::factory()->create();
    $dn = Document::factory()->deliveryNote()->create([
        'legacy_uid' => 10, 'customer_id' => $customer->id, 'status' => DocumentStatus::Active,
    ]);
    $inv = Document::factory()->invoice()->create([
        'legacy_uid' => 20, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);

    $plan = $this->reconciler->plan();

    expect($plan['converted_from_updates'])->toBe([
        ['invoice_id' => $inv->id, 'dn_id' => $dn->id],
    ])
        ->and($plan['dn_status_updates'])->toContain($dn->id)
        ->and($plan['signal_mismatches'])->toBe(0);

    $this->reconciler->apply($plan);

    expect($inv->fresh()->converted_from_id)->toBe($dn->id)
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('counts a signal mismatch when the invoice origdeln disagrees with the invuid link', function () {
    insertLegacyDoc(['uid' => 11, 'rtype' => 'd', 'invuid' => 21]);
    insertLegacyDoc(['uid' => 21, 'rtype' => 'i', 'origdeln' => 99]);

    $customer = Customer::factory()->create();
    $dn = Document::factory()->deliveryNote()->create([
        'legacy_uid' => 11, 'customer_id' => $customer->id, 'status' => DocumentStatus::Active,
    ]);
    $inv = Document::factory()->invoice()->create([
        'legacy_uid' => 21, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);

    $plan = $this->reconciler->plan();

    expect($plan['signal_mismatches'])->toBe(1)
        ->and($plan['converted_from_updates'])->toBe([
            ['invoice_id' => $inv->id, 'dn_id' => $dn->id],
        ]);

    $this->reconciler->apply($plan);

    expect($inv->fresh()->converted_from_id)->toBe($dn->id);
});

test('downgrades an orphaned converted delivery note whose linked invoice was not migrated', function () {
    insertLegacyDoc(['uid' => 12, 'rtype' => 'd', 'invuid' => 500]);

    $customer = Customer::factory()->create();
    $dn = Document::factory()->deliveryNote()->create([
        'legacy_uid' => 12, 'customer_id' => $customer->id, 'status' => DocumentStatus::Converted,
    ]);

    $plan = $this->reconciler->plan();

    expect($plan['orphan_downgrades'])->toContain($dn->id)
        ->and($plan['converted_from_updates'])->toBe([]);

    $this->reconciler->apply($plan);

    expect($dn->fresh()->status)->toBe(DocumentStatus::Active);
});

test('apply is idempotent', function () {
    insertLegacyDoc(['uid' => 10, 'rtype' => 'd', 'invuid' => 20]);
    insertLegacyDoc(['uid' => 20, 'rtype' => 'i']);

    $customer = Customer::factory()->create();
    $dn = Document::factory()->deliveryNote()->create([
        'legacy_uid' => 10, 'customer_id' => $customer->id, 'status' => DocumentStatus::Active,
    ]);
    $inv = Document::factory()->invoice()->create([
        'legacy_uid' => 20, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);

    $this->reconciler->apply($this->reconciler->plan());

    $plan2 = $this->reconciler->plan();

    expect($this->reconciler->isEmpty($plan2))->toBeTrue();

    $this->reconciler->apply($plan2);

    expect($inv->fresh()->converted_from_id)->toBe($dn->id)
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('isEmpty is true when there is nothing to reconcile', function () {
    insertLegacyDoc(['uid' => 30, 'rtype' => 'd']);
    insertLegacyDoc(['uid' => 31, 'rtype' => 'i']);

    $customer = Customer::factory()->create();
    Document::factory()->deliveryNote()->create([
        'legacy_uid' => 30, 'customer_id' => $customer->id, 'status' => DocumentStatus::Active,
    ]);
    Document::factory()->invoice()->create([
        'legacy_uid' => 31, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);

    expect($this->reconciler->isEmpty($this->reconciler->plan()))->toBeTrue();
});
