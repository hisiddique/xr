<?php

use App\ArchiveRunMode;
use App\ArchiveRunStatus;
use App\Models\ArchiveRun;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Services\Archive\ArchiveRunner;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => useArchiveDatabase());

function makeArchiveRun(): ArchiveRun
{
    return ArchiveRun::create([
        'status' => ArchiveRunStatus::Pending,
        'mode' => ArchiveRunMode::Archive,
    ]);
}

function backdate(string $table, int $id, string $timestamp): void
{
    DB::table($table)->where('id', $id)->update(['created_at' => $timestamp]);
}

it('copies parent + graph children within the date window, skipping out-of-range rows', function () {
    $inRangeCustomerA = Customer::factory()->create();
    $inRangeCustomerB = Customer::factory()->create();
    $outOfRangeCustomer = Customer::factory()->create();

    backdate('customers', $inRangeCustomerA->id, '2020-03-01 12:00:00');
    backdate('customers', $inRangeCustomerB->id, '2020-09-01 12:00:00');
    backdate('customers', $outOfRangeCustomer->id, '2024-03-01 12:00:00');

    $inRangeDocument = Document::factory()->deliveryNote()->for($inRangeCustomerA)->create();
    backdate('documents', $inRangeDocument->id, '2020-06-01 12:00:00');
    DocumentItem::factory()->count(2)->for($inRangeDocument)->create();

    $outOfRangeDocument = Document::factory()->deliveryNote()->for($outOfRangeCustomer)->create();
    backdate('documents', $outOfRangeDocument->id, '2024-04-01 12:00:00');

    $run = makeArchiveRun();

    app(ArchiveRunner::class, ['run' => $run])->run(['customers', 'documents'], '2020-01-01', '2020-12-31');

    expect($run->fresh()->status)->toBe(ArchiveRunStatus::Completed);

    expect(archiveDb()->table('customers')->count())->toBe(2);
    expect(archiveDb()->table('documents')->where('id', $inRangeDocument->id)->exists())->toBeTrue();
    expect(archiveDb()->table('document_items')->where('document_id', $inRangeDocument->id)->count())->toBe(2);
    expect(archiveDb()->table('documents')->where('id', $outOfRangeDocument->id)->exists())->toBeFalse();

    $documentsStat = $run->tables()->where('entity', 'documents')->first();
    expect($documentsStat->rows_copied)->toBe(1);

    expect(Customer::count())->toBe(3);
    expect(Document::count())->toBe(2);
    expect(DocumentItem::count())->toBe(2);
});

it('includes soft-deleted rows', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-04-01 12:00:00');

    $document = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $document->id, '2020-05-01 12:00:00');
    $document->delete();

    $run = makeArchiveRun();

    app(ArchiveRunner::class, ['run' => $run])->run(['documents'], '2020-01-01', '2020-12-31');

    $archivedRow = archiveDb()->table('documents')->where('id', $document->id)->first();

    expect($archivedRow)->not->toBeNull();
    expect($archivedRow->deleted_at)->not->toBeNull();
});

it('is idempotent', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-04-01 12:00:00');

    $document = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $document->id, '2020-05-01 12:00:00');

    app(ArchiveRunner::class, ['run' => makeArchiveRun()])->run(['documents'], '2020-01-01', '2020-12-31');

    $countAfterFirstRun = archiveDb()->table('documents')->count();
    expect($countAfterFirstRun)->toBe(1);

    app(ArchiveRunner::class, ['run' => makeArchiveRun()])->run(['documents'], '2020-01-01', '2020-12-31');

    expect(archiveDb()->table('documents')->count())->toBe($countAfterFirstRun);
});

it('documents graph-closure pulls a linked sibling outside the window', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-04-01 12:00:00');

    $deliveryNote = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $deliveryNote->id, '2024-02-01 12:00:00');

    $invoice = Document::factory()->invoice()->for($customer)->create([
        'converted_from_id' => $deliveryNote->id,
    ]);
    backdate('documents', $invoice->id, '2020-06-01 12:00:00');

    $run = makeArchiveRun();

    app(ArchiveRunner::class, ['run' => $run])->run(['documents'], '2020-01-01', '2020-12-31');

    expect(archiveDb()->table('documents')->where('id', $invoice->id)->exists())->toBeTrue();
    expect(archiveDb()->table('documents')->where('id', $deliveryNote->id)->exists())->toBeTrue();
});

it('cooperatively cancels between chunks', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-04-01 12:00:00');

    $document = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $document->id, '2020-05-01 12:00:00');

    $run = makeArchiveRun();
    $run->update(['cancelled_at' => now()]);

    app(ArchiveRunner::class, ['run' => $run])->run(['customers', 'documents'], '2020-01-01', '2020-12-31');

    $run->refresh();

    expect($run->status)->toBe(ArchiveRunStatus::Cancelled);
    expect($run->finished_at)->not->toBeNull();
});
