<?php

use App\Models\Customer;
use App\Models\User;
use App\Support\TableSorts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('tables fall back to their original default sort', function () {
    expect(TableSorts::for('invoices'))->toBe(['doc_number', 'desc'])
        ->and(TableSorts::for('payments'))->toBe(['payment_date', 'desc']);
});

test('an admin saves a default sort and the list uses it', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));

    Customer::factory()->create(['company_name' => 'Bravo']);
    Customer::factory()->create(['company_name' => 'Alpha']);

    Livewire::test('pages::settings.table-sorts')
        ->set('sorts.customers.column', 'company_name')
        ->set('sorts.customers.direction', 'asc')
        ->call('save')
        ->assertHasNoErrors();

    expect(TableSorts::for('customers'))->toBe(['company_name', 'asc']);

    $names = Livewire::test('pages::customers.index')->instance()->customers->pluck('company_name')->all();
    expect($names)->toBe(['Alpha', 'Bravo']);
});

test('a column that does not belong to the table is rejected', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));

    Livewire::test('pages::settings.table-sorts')
        ->set('sorts.payments.column', 'company_name')
        ->call('save')
        ->assertHasErrors('sorts.payments.column');
});
