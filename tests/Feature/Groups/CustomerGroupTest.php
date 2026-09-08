<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can create a customer group', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->set('newGroupName', 'VIP Monthly')
        ->call('addGroup')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('customer_groups', ['name' => 'VIP Monthly']);
});

test('customer group name is required, unique and max 80', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    CustomerGroup::factory()->create(['name' => 'Existing']);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->set('newGroupName', '')
        ->call('addGroup')
        ->assertHasErrors(['newGroupName' => 'required']);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->set('newGroupName', 'Existing')
        ->call('addGroup')
        ->assertHasErrors(['newGroupName' => 'unique']);
});

test('admin can rename a customer group', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $group = CustomerGroup::factory()->create(['name' => 'Old']);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->call('startEdit', $group->id)
        ->set('editingGroupName', 'New Name')
        ->set('editingGroupDescription', 'desc')
        ->call('updateGroup')
        ->assertHasNoErrors();

    expect($group->fresh()->name)->toBe('New Name')
        ->and($group->fresh()->description)->toBe('desc');
});

test('admin can add and remove members via the group page', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $group = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create();

    $second = Customer::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->call('selectGroup', $group->id)
        ->call('addMembers', [(string) $customer->id, (string) $second->id]);

    expect($group->customers()->count())->toBe(2);

    // bulk remove via the confirm flow
    $component->set('selectedMemberIds', [(string) $customer->id, (string) $second->id])
        ->call('startBulkRemove')
        ->call('removeMembers');

    expect($group->fresh()->customers()->count())->toBe(0);
});

test('a single member can be removed through the confirm modal', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $group = CustomerGroup::factory()->create();
    $keep = Customer::factory()->create();
    $drop = Customer::factory()->create();
    $group->customers()->attach([$keep->id, $drop->id]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->call('selectGroup', $group->id)
        ->set('removingMemberIds', [$drop->id])
        ->call('removeMembers');

    expect($group->fresh()->customers()->pluck('customers.id')->all())->toBe([$keep->id]);
});

test('a group can be created with a description from the modal', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->set('newGroupName', 'Wholesale')
        ->set('newGroupDescription', 'Trade accounts on 30-day terms')
        ->call('addGroup')
        ->assertHasNoErrors();

    expect(CustomerGroup::where('name', 'Wholesale')->value('description'))
        ->toBe('Trade accounts on 30-day terms');
});

test('deleting a customer group detaches members but keeps customers', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $group = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create();
    $group->customers()->attach($customer->id);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.customer-groups')
        ->set('deletingGroupId', $group->id)
        ->call('deleteGroup')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('customer_groups', ['id' => $group->id]);
    $this->assertDatabaseMissing('customer_group_members', ['customer_group_id' => $group->id]);
    $this->assertDatabaseHas('customers', ['id' => $customer->id]);
});

test('customer create form assigns groups', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $group = CustomerGroup::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::customers.create')
        ->set('company_name', 'Grouped Co')
        ->set('group_ids', [$group->id])
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::where('company_name', 'Grouped Co')->firstOrFail();
    expect($customer->groups()->pluck('customer_groups.id')->all())->toBe([$group->id]);
});

test('customer edit form syncs groups', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $old = CustomerGroup::factory()->create();
    $new = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create();
    $customer->groups()->attach($old->id);

    Livewire::actingAs($admin)
        ->test('pages::customers.edit', ['customer' => $customer])
        ->set('group_ids', [$new->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->groups()->pluck('customer_groups.id')->all())->toBe([$new->id]);
});
