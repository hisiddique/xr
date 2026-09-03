<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithPermissions(array $keys): User
{
    $role = Role::create(['slug' => 'qn-'.Str::random(10), 'name' => 'Quick Nav Test', 'is_system' => false]);
    $role->syncPermissions($keys);

    $user = User::factory()->noRoles()->create(['role' => UserRole::Staff]);
    $user->roles()->attach($role->id);

    return $user;
}

test('customer show renders the New dropdown links pre-scoped to the customer', function () {
    $customer = Customer::factory()->create();
    $user = userWithPermissions([
        'customer-show', 'deliverynote-create', 'creditnote-create', 'payment-create',
    ]);

    $this->actingAs($user)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee('delivery-notes/create?customer_id='.$customer->id, false)
        ->assertSee('credit-notes/create?customer_id='.$customer->id, false)
        ->assertSee('payments/create?customer_id='.$customer->id, false);
});

test('customer show hides the New Payment link without payment-create', function () {
    $customer = Customer::factory()->create();
    $user = userWithPermissions(['customer-show', 'deliverynote-create']);

    $this->actingAs($user)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee('delivery-notes/create?customer_id='.$customer->id, false)
        ->assertDontSee('payments/create?customer_id='.$customer->id, false);
});

test('supplier show renders the New dropdown links pre-scoped to the supplier', function () {
    $supplier = Supplier::factory()->create();
    $user = userWithPermissions([
        'supplier-show', 'supplierinvoice-create', 'supplierdebitnote-create',
    ]);

    $this->actingAs($user)
        ->get(route('suppliers.show', $supplier))
        ->assertOk()
        ->assertSee('supplier-invoices/create?supplier_id='.$supplier->id, false)
        ->assertSee('supplier-debit-notes/create?supplier_id='.$supplier->id, false);
});

test('supplier invoice form honours the supplier_id query param', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 15]);
    $this->actingAs(User::factory()->create());

    $component = Livewire::withQueryParams(['supplier_id' => $supplier->id])
        ->test('pages::supplier-invoices.form');

    $component->assertSet('supplier_id', $supplier->id);
    expect($component->get('supplierName'))->not->toBe('');
});

test('supplier debit note form honours the supplier_id query param', function () {
    $supplier = Supplier::factory()->create();
    $this->actingAs(User::factory()->create());

    $component = Livewire::withQueryParams(['supplier_id' => $supplier->id])
        ->test('pages::supplier-debit-notes.create');

    $component->assertSet('supplier_id', $supplier->id);
    expect($component->get('supplierName'))->not->toBe('');
});
