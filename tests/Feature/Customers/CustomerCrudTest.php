<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('customer index page is accessible to authenticated users', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('customers.index'))->assertOk();
});

test('customer can be created via livewire component', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::customers.create')
        ->set('company_name', 'ACME Corp')
        ->set('email_1', 'test@acme.com')
        ->set('trade_discount', '10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('customers.index'));

    $this->assertDatabaseHas('customers', [
        'company_name' => 'ACME Corp',
        'email_1' => 'test@acme.com',
        'trade_discount' => 10,
        'created_by' => $user->id,
    ]);
});

test('customer create requires company_name', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::customers.create')
        ->set('company_name', '')
        ->call('save')
        ->assertHasErrors(['company_name' => 'required']);
});

test('customer reference is auto-generated as CUST-#####', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    Customer::factory()->create(['reference' => 'CUST-00004']);

    Livewire::actingAs($user)
        ->test('pages::customers.create')
        ->set('company_name', 'Test Co')
        ->call('save')
        ->assertHasNoErrors();

    expect(Customer::where('company_name', 'Test Co')->value('reference'))->toBe('CUST-00005');
});

test('customer can be updated', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.edit', ['customer' => $customer])
        ->set('company_name', 'Updated Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->company_name)->toBe('Updated Name');
});

test('customer can be soft deleted', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.delete-modal', ['customer' => $customer])
        ->call('deleteCustomer');

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

test('customer list can be searched by company name', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    Customer::factory()->create(['company_name' => 'Alpha Corp']);
    Customer::factory()->create(['company_name' => 'Beta Ltd']);

    $component = Livewire::actingAs($user)
        ->test('pages::customers.index')
        ->set('search', 'Alpha');

    $component->assertSee('Alpha Corp')
        ->assertDontSee('Beta Ltd');
});
