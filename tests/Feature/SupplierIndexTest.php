<?php

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($this->user);
});

test('column filters narrow the supplier list per column', function () {
    Supplier::factory()->create(['company_name' => 'Northwind Trading', 'reference' => 'SUP-1000']);
    Supplier::factory()->create(['company_name' => 'Zenith Supplies', 'reference' => 'SUP-2000']);

    Livewire::test('pages::suppliers.index')
        ->set('filters.company', 'Northwind')
        ->assertSeeText('Northwind Trading')
        ->assertDontSeeText('Zenith Supplies')
        ->set('filters.company', '')
        ->set('filters.reference', 'SUP-2000')
        ->assertSeeText('Zenith Supplies')
        ->assertDontSeeText('Northwind Trading');
});

test('global search is ANDed with column filters, not ORed', function () {
    Supplier::factory()->create(['company_name' => 'Acme Metals', 'reference' => 'SUP-1000']);
    Supplier::factory()->create(['company_name' => 'Zenith Supplies', 'reference' => 'SUP-2000']);

    Livewire::test('pages::suppliers.index')
        ->set('search', 'Acme')
        ->set('filters.reference', 'SUP-2000')
        ->assertDontSeeText('Acme Metals')
        ->assertDontSeeText('Zenith Supplies');
});

test('vat column filter narrows suppliers by applied status', function () {
    Supplier::factory()->create(['company_name' => 'Vat Applied Co', 'vat_applied' => true]);
    Supplier::factory()->create(['company_name' => 'No Vat Co', 'vat_applied' => false]);

    Livewire::test('pages::suppliers.index')
        ->set('filters.vat', '1')
        ->assertSeeText('Vat Applied Co')
        ->assertDontSeeText('No Vat Co')
        ->set('filters.vat', '0')
        ->assertSeeText('No Vat Co')
        ->assertDontSeeText('Vat Applied Co');
});
