<?php

use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can create a supplier group', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.supplier-groups')
        ->set('newGroupName', 'Key Suppliers')
        ->call('addGroup')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('supplier_groups', ['name' => 'Key Suppliers']);
});

test('admin can add and remove supplier members', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $group = SupplierGroup::factory()->create();
    $supplier = Supplier::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::reference-data.supplier-groups')
        ->call('selectGroup', $group->id)
        ->call('addMembers', [(string) $supplier->id]);

    expect($group->suppliers()->count())->toBe(1);

    $component->set('selectedMemberIds', [(string) $supplier->id])
        ->call('startBulkRemove')
        ->call('removeMembers');

    expect($group->fresh()->suppliers()->count())->toBe(0);
});

test('supplier form assigns and syncs groups', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $old = SupplierGroup::factory()->create();
    $new = SupplierGroup::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::suppliers.form')
        ->set('company_name', 'Supplier Co')
        ->set('category', 'trading')
        ->set('group_ids', [(string) $old->id])
        ->call('save')
        ->assertHasNoErrors();

    $supplier = Supplier::where('company_name', 'Supplier Co')->firstOrFail();
    expect($supplier->groups()->pluck('supplier_groups.id')->all())->toBe([$old->id]);

    Livewire::actingAs($admin)
        ->test('pages::suppliers.form', ['supplier' => $supplier])
        ->set('group_ids', [(string) $new->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($supplier->fresh()->groups()->pluck('supplier_groups.id')->all())->toBe([$new->id]);
});
