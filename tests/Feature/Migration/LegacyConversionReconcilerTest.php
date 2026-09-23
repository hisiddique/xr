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
        'emailsent' => null, 'printtime' => null, 'status' => null,
    ], $overrides));
}

test('resolves converted_from_id from a matching DN/invoice ref', function () {
    insertLegacyDoc(['uid' => 10, 'rtype' => 'd', 'ref' => '900001']);
    insertLegacyDoc(['uid' => 20, 'rtype' => 'i', 'ref' => '900001']);

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
        ->and($plan['ambiguous_refs'])->toBe(0);

    $this->reconciler->apply($plan);

    expect($inv->fresh()->converted_from_id)->toBe($dn->id)
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('counts an ambiguous ref when more than one invoice shares the same ref', function () {
    insertLegacyDoc(['uid' => 11, 'rtype' => 'd', 'ref' => '900002']);
    insertLegacyDoc(['uid' => 21, 'rtype' => 'i', 'ref' => '900002']);
    insertLegacyDoc(['uid' => 22, 'rtype' => 'i', 'ref' => '900002']);

    $customer = Customer::factory()->create();
    Document::factory()->deliveryNote()->create([
        'legacy_uid' => 11, 'customer_id' => $customer->id, 'status' => DocumentStatus::Active,
    ]);
    Document::factory()->invoice()->create([
        'legacy_uid' => 21, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);
    Document::factory()->invoice()->create([
        'legacy_uid' => 22, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);

    $plan = $this->reconciler->plan();

    expect($plan['ambiguous_refs'])->toBe(1)
        ->and($plan['converted_from_updates'])->toBe([]);
});

test('downgrades an orphaned converted delivery note whose matching invoice was not migrated', function () {
    insertLegacyDoc(['uid' => 12, 'rtype' => 'd', 'ref' => '900003']);
    insertLegacyDoc(['uid' => 500, 'rtype' => 'i', 'ref' => '900003']);

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
    insertLegacyDoc(['uid' => 10, 'rtype' => 'd', 'ref' => '900001']);
    insertLegacyDoc(['uid' => 20, 'rtype' => 'i', 'ref' => '900001']);

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
    insertLegacyDoc(['uid' => 30, 'rtype' => 'd', 'ref' => '900004']);
    insertLegacyDoc(['uid' => 31, 'rtype' => 'i', 'ref' => '900005']);

    $customer = Customer::factory()->create();
    Document::factory()->deliveryNote()->create([
        'legacy_uid' => 30, 'customer_id' => $customer->id, 'status' => DocumentStatus::Active,
    ]);
    Document::factory()->invoice()->create([
        'legacy_uid' => 31, 'customer_id' => $customer->id, 'converted_from_id' => null,
    ]);

    expect($this->reconciler->isEmpty($this->reconciler->plan()))->toBeTrue();
});
