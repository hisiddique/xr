<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('sysadmin role wildcard passes an ability outside the catalogue', function () {
    $user = User::factory()->noRoles()->create();
    $role = Role::create(['slug' => 'sysadmin', 'name' => 'Sysadmin', 'is_system' => false]);
    $user->roles()->attach($role);

    expect(Gate::forUser($user)->allows('anything-not-in-catalog'))->toBeTrue();
});

test('non-sysadmin denial defers to the individual gate instead of short-circuiting', function () {
    $user = User::factory()->noRoles()->create();
    $role = Role::create(['slug' => 'clerk', 'name' => 'Clerk', 'is_system' => false]);
    $role->syncPermissions(['customer-index']);
    $user->roles()->attach($role);

    expect(Gate::forUser($user)->allows('customer-index'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('customer-delete'))->toBeFalse();
});

test('module wildcard grant covers every action in that module only', function () {
    $user = User::factory()->noRoles()->create();
    $role = Role::create(['slug' => 'customer-manager', 'name' => 'Customer Manager', 'is_system' => false]);
    $user->roles()->attach($role);

    DB::table('role_permission')->insert([
        'role_id' => $role->id,
        'permission_key' => 'customer-*',
    ]);

    expect($user->hasPermission('customer-edit'))->toBeTrue()
        ->and($user->hasPermission('supplier-edit'))->toBeFalse();
});

test('syncPermissions prunes keys that are not in the catalogue', function () {
    $role = Role::create(['slug' => 'partial', 'name' => 'Partial', 'is_system' => false]);

    $role->syncPermissions(['customer-index', 'totally-made-up']);

    expect($role->permissionKeys()->all())->toBe(['customer-index']);
});

test('user permissionKeys is the de-duplicated union across all roles', function () {
    $user = User::factory()->noRoles()->create();

    $roleA = Role::create(['slug' => 'role-a', 'name' => 'Role A', 'is_system' => false]);
    $roleA->syncPermissions(['customer-index']);

    $roleB = Role::create(['slug' => 'role-b', 'name' => 'Role B', 'is_system' => false]);
    $roleB->syncPermissions(['customer-index', 'payment-show']);

    $user->roles()->attach([$roleA->id, $roleB->id]);

    $keys = $user->permissionKeys();

    expect($keys)->toContain('customer-index')
        ->and($keys)->toContain('payment-show')
        ->and(array_count_values($keys)['customer-index'])->toBe(1);
});
