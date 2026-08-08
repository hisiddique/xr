<?php

use App\Models\Supplier;
use App\Models\User;
use App\SupplierCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('supplier category defaults to trading', function () {
    $supplier = Supplier::factory()->create();

    expect($supplier->category)->toBe(SupplierCategory::Trading);
});

test('supplier category casts to enum and persists overhead_expenses', function () {
    $supplier = Supplier::factory()->create(['category' => 'overhead_expenses']);

    expect($supplier->fresh()->category)->toBe(SupplierCategory::OverheadExpenses);
});

test('supplier form saves the selected category', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::suppliers.form')
        ->set('company_name', 'Acme Overheads Ltd')
        ->set('category', 'overhead_expenses')
        ->call('save');

    $supplier = Supplier::where('company_name', 'Acme Overheads Ltd')->sole();

    expect($supplier->category)->toBe(SupplierCategory::OverheadExpenses);
});
