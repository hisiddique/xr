<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function adminWith(array $permissions): User
{
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator', 'is_system' => true]);
    $role->syncPermissions($permissions);

    return User::factory()->admin()->create();
}

test('roles index denies a user without the role-index permission', function () {
    $this->actingAs(User::factory()->noRoles()->create());

    Livewire::test('pages::roles.index')->assertForbidden();
});

test('roles index renders for an admin holding the role-index permission', function () {
    $this->actingAs(adminWith(['role-index']));

    Livewire::test('pages::roles.index')
        ->assertSee('Define roles and the permissions each one grants.');
});

test('an admin can create a custom role with a specific permission set', function () {
    $this->actingAs(adminWith(['role-create']));

    Livewire::test('pages::roles.form')
        ->set('name', 'Warehouse')
        ->set('slug', 'warehouse')
        ->set('permissions', ['customer-index', 'deliverynote-create'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('roles.index'));

    $role = Role::where('slug', 'warehouse')->first();

    expect($role)->not->toBeNull();
    expect($role->is_system)->toBeFalse();
    expect($role->permissionKeys()->sort()->values()->all())
        ->toBe(['customer-index', 'deliverynote-create']);
});

test('the slug field rejects spaces and capitals', function () {
    $this->actingAs(adminWith(['role-create']));

    Livewire::test('pages::roles.form')
        ->set('name', 'Warehouse Manager')
        ->set('slug', 'Warehouse Manager')
        ->call('save')
        ->assertHasErrors('slug');
});

test('a system role keeps its name and slug but accepts new permissions', function () {
    $this->actingAs(adminWith(['role-edit']));

    $role = Role::create(['slug' => 'staff', 'name' => 'Staff', 'is_system' => true]);
    $role->syncPermissions(['customer-index', 'customer-show', 'invoice-index']);

    Livewire::test('pages::roles.form', ['role' => $role])
        ->set('name', 'Hacked')
        ->set('slug', 'hacked')
        ->set('permissions', ['customer-index'])
        ->call('save')
        ->assertHasNoErrors();

    $role->refresh();

    expect($role->name)->toBe('Staff');
    expect($role->slug)->toBe('staff');
    expect($role->permissionKeys()->all())->toBe(['customer-index']);
});

test('editing the sysadmin role redirects away from the form', function () {
    $this->actingAs(adminWith(['role-edit']));

    $role = Role::create(['slug' => 'sysadmin', 'name' => 'System Administrator', 'is_system' => true]);

    Livewire::test('pages::roles.form', ['role' => $role])
        ->assertRedirect(route('roles.index'));
});

test('the roles index gates the edit control for the sysadmin role', function () {
    $this->actingAs(adminWith(['role-index']));

    Role::create(['slug' => 'sysadmin', 'name' => 'System Administrator', 'is_system' => true]);

    Livewire::test('pages::roles.index')
        ->assertSee('System-managed role — cannot be edited')
        ->assertDontSeeHtml('data-edit-url="'.route('roles.edit', Role::where('slug', 'sysadmin')->first()));
});

test('the users index links to roles for a user holding the role-index permission', function () {
    $this->actingAs(adminWith(['role-index']));

    Livewire::test('pages::users.index')
        ->assertSeeHtml('href="'.route('roles.index').'"');
});

test('the users index hides the roles link without the role-index permission', function () {
    $this->actingAs(User::factory()->noRoles()->create());

    Livewire::test('pages::users.index')
        ->assertDontSeeHtml('href="'.route('roles.index').'"');
});

test('a system role cannot be deleted', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(User::factory()->admin()->create());

    $staff = Role::where('slug', 'staff')->first();

    Livewire::test('pages::roles.index')
        ->set('deletingRoleId', $staff->id)
        ->call('deleteRole');

    expect(Role::find($staff->id))->not->toBeNull();
});

test('a role that still has users cannot be deleted', function () {
    $this->actingAs(adminWith(['role-index', 'role-delete']));

    $role = Role::create(['slug' => 'warehouse', 'name' => 'Warehouse', 'is_system' => false]);
    User::factory()->create()->roles()->attach($role->id);

    Livewire::test('pages::roles.index')
        ->set('deletingRoleId', $role->id)
        ->call('deleteRole');

    expect(Role::find($role->id))->not->toBeNull();
});

test('an unused custom role can be deleted', function () {
    $this->actingAs(adminWith(['role-index', 'role-delete']));

    $role = Role::create(['slug' => 'warehouse', 'name' => 'Warehouse', 'is_system' => false]);

    Livewire::test('pages::roles.index')
        ->set('deletingRoleId', $role->id)
        ->call('deleteRole');

    expect(Role::find($role->id))->toBeNull();
});

test('a user cannot remove their own sysadmin role', function () {
    $sysadmin = Role::create(['slug' => 'sysadmin', 'name' => 'System Administrator', 'is_system' => true]);

    $user = User::factory()->create(['role' => 'staff']);
    $user->roles()->attach($sysadmin->id);

    $this->actingAs($user);

    Livewire::test('pages::users.edit', ['user' => $user])
        ->set('roleIds', [])
        ->call('save')
        ->assertHasErrors('roleIds');

    expect($user->fresh()->roles->pluck('slug'))->toContain('sysadmin');
});

test('the last sysadmin cannot be demoted by another admin', function () {
    $sysadmin = Role::create(['slug' => 'sysadmin', 'name' => 'System Administrator', 'is_system' => true]);

    $onlySysadmin = User::factory()->create(['role' => 'staff']);
    $onlySysadmin->roles()->attach($sysadmin->id);

    $this->actingAs(adminWith(['user-edit']));

    Livewire::test('pages::users.edit', ['user' => $onlySysadmin])
        ->set('roleIds', [])
        ->call('save')
        ->assertHasErrors('roleIds');

    expect($onlySysadmin->fresh()->roles->pluck('slug'))->toContain('sysadmin');
});
