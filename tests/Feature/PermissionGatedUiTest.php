<?php

use App\Models\Role;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithPerms(array $keys): User
{
    $role = Role::create(['slug' => 'perm-'.Str::random(10), 'name' => 'Perm Test', 'is_system' => false]);
    $role->syncPermissions($keys);

    $user = User::factory()->noRoles()->create(['role' => UserRole::Staff]);
    $user->roles()->attach($role->id);

    return $user;
}

dataset('index pages with a create button', [
    'customers' => ['customer', 'pages::customers.index', 'customers.create'],
    'suppliers' => ['supplier', 'pages::suppliers.index', 'suppliers.create'],
    'delivery notes' => ['deliverynote', 'pages::delivery-notes.index', 'delivery-notes.create'],
    'credit notes' => ['creditnote', 'pages::credit-notes.index', 'credit-notes.create'],
    'payments' => ['payment', 'pages::payments.index', 'payments.create'],
    'overheads' => ['overhead', 'pages::overheads.index', 'overheads.create'],
    'supplier invoices' => ['supplierinvoice', 'pages::supplier-invoices.index', 'supplier-invoices.create'],
    'supplier debit notes' => ['supplierdebitnote', 'pages::supplier-debit-notes.index', 'supplier-debit-notes.create'],
    'users' => ['user', 'pages::users.index', 'users.create'],
    'roles' => ['role', 'pages::roles.index', 'roles.create'],
]);

test('the create button is hidden without the create permission', function (string $module, string $component, string $createRoute) {
    Livewire::actingAs(userWithPerms(["{$module}-index"]))
        ->test($component)
        ->assertDontSeeHtml(route($createRoute));
})->with('index pages with a create button');

test('the create button shows with the create permission', function (string $module, string $component, string $createRoute) {
    Livewire::actingAs(userWithPerms(["{$module}-index", "{$module}-create"]))
        ->test($component)
        ->assertSeeHtml(route($createRoute));
})->with('index pages with a create button');
