<?php

use App\Models\LookupTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can add a title', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.titles')
        ->set('newTitle', 'Dr')
        ->call('addTitle')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lookup_titles', ['name' => 'Dr']);
});

test('title name is required and max 20 chars', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.titles')
        ->set('newTitle', '')
        ->call('addTitle')
        ->assertHasErrors(['newTitle' => 'required']);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.titles')
        ->set('newTitle', str_repeat('A', 21))
        ->call('addTitle')
        ->assertHasErrors(['newTitle' => 'max']);
});

test('admin can delete a title', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $title = LookupTitle::factory()->create(['name' => 'Mr']);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.titles')
        ->set('deletingTitleId', $title->id)
        ->call('deleteTitle');

    $this->assertDatabaseMissing('lookup_titles', ['id' => $title->id]);
});

test('admin can add a credit term', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.credit-terms')
        ->set('newCreditTerm', 'Net 30 days')
        ->call('addCreditTerm')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lookup_credit_terms', ['name' => 'Net 30 days']);
});

test('admin can add a credit limit', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::reference-data.credit-limits')
        ->set('newCreditLimit', '5000')
        ->call('addCreditLimit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lookup_credit_limits', ['amount' => 5000]);
});

test('staff cannot access reference data titles page', function () {
    $staff = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($staff)->get(route('reference-data.titles'))->assertRedirect(route('dashboard'));
});
