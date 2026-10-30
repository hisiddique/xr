<?php

use App\Models\Document;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
});

test('index pages delete through one shared modal', function (string $page, string $action, string $idProperty, Closure $make) {
    $record = $make();

    Livewire::test($page)
        ->set($idProperty, $record->id)
        ->call($action)
        ->assertSet($idProperty, null);

    $after = $record->fresh();

    expect($after === null || $after->trashed())->toBeTrue();
})->with([
    'suppliers' => ['pages::suppliers.index', 'deleteSupplier', 'deletingId', fn () => Supplier::factory()->create()],
    'users' => ['pages::users.index', 'deleteUser', 'deletingId', fn () => User::factory()->staff()->create()],
    'delivery notes' => ['pages::delivery-notes.index', 'deleteDocument', 'deletingDocumentId', fn () => Document::factory()->deliveryNote()->create()],
    'credit notes' => ['pages::credit-notes.index', 'deleteDocument', 'deletingDocumentId', fn () => Document::factory()->creditNote()->create()],
    'invoices' => ['pages::invoices.index', 'deleteDocument', 'deletingDocumentId', fn () => Document::factory()->invoice()->create()],
]);

test('the users list will not delete the signed in admin', function () {
    $me = auth()->user();

    Livewire::test('pages::users.index')
        ->set('deletingId', $me->id)
        ->call('deleteUser');

    expect($me->fresh())->not->toBeNull();
});
