<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('filter matches anywhere and ranking puts starts-with matches first', function () {
    Customer::factory()->create(['company_name' => 'Big Acme']);
    Customer::factory()->create(['company_name' => 'Zed Co', 'reference' => 'CUST-ACME']);
    Customer::factory()->create(['company_name' => 'Acme Two']);
    Customer::factory()->create(['company_name' => 'Unrelated']);

    $query = Customer::query();
    Search::filter($query, 'acme', ['company_name', 'email_1']);
    Search::rankStartsWithFirst($query, 'acme', ['company_name']);

    expect($query->orderBy('company_name')->pluck('company_name')->all())->toBe(['Acme Two', 'Big Acme']);
});

test('ranking and filtering work through a related column', function () {
    $acme = Customer::factory()->create(['company_name' => 'Acme Ltd']);
    $bigAcme = Customer::factory()->create(['company_name' => 'Big Acme']);
    $other = Customer::factory()->create(['company_name' => 'Other']);

    $a = Document::factory()->invoice()->create(['customer_id' => $bigAcme->id, 'doc_number' => 'INV-0001']);
    $b = Document::factory()->invoice()->create(['customer_id' => $acme->id, 'doc_number' => 'INV-0002']);
    Document::factory()->invoice()->create(['customer_id' => $other->id, 'doc_number' => 'INV-0003']);

    $query = Document::query()->invoices();
    Search::filter($query, 'acme', ['doc_number', 'customer.company_name']);
    Search::rankStartsWithFirst($query, 'acme', ['doc_number', 'customer.company_name']);

    expect($query->orderBy('id')->pluck('id')->all())->toBe([$b->id, $a->id]);
});

test('wildcard characters in the term are matched literally', function () {
    Customer::factory()->create(['company_name' => '100% Cotton']);
    Customer::factory()->create(['company_name' => '1000 Things']);

    $query = Customer::query();
    Search::filter($query, '100%', ['company_name']);

    expect($query->pluck('company_name')->all())->toBe(['100% Cotton']);
});

test('customer index lists starts-with matches first and lets an explicit sort win', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));

    Customer::factory()->create(['company_name' => 'Zed Acme']);
    Customer::factory()->create(['company_name' => 'Acme Zulu']);
    Customer::factory()->create(['company_name' => 'Acme Alpha']);
    Customer::factory()->create(['company_name' => 'Other']);

    $names = fn ($component) => $component->instance()->customers->pluck('company_name')->all();

    $ranked = Livewire\Livewire::test('pages::customers.index')->set('search', 'acme');
    expect($names($ranked))->toContain('Zed Acme')->not->toContain('Other')
        ->and(array_slice($names($ranked), 0, 2))->toEqualCanonicalizing(['Acme Zulu', 'Acme Alpha']);

    $sorted = Livewire\Livewire::test('pages::customers.index')->set('search', 'acme')->call('sortBy', 'company_name')->call('sortBy', 'company_name');
    expect($names($sorted))->toBe(['Acme Alpha', 'Acme Zulu', 'Zed Acme']);
});

test('typeahead returns starts-with matches first, then contains matches', function () {
    Customer::factory()->create(['company_name' => 'Big Acme']);
    Customer::factory()->create(['company_name' => 'Acme Ltd']);
    Customer::factory()->create(['company_name' => 'Unrelated']);

    $labels = Livewire\Livewire::test('pages::ui.typeahead', ['model' => Customer::class, 'column' => 'company_name', 'minChars' => 3])
        ->set('search', 'acme')
        ->instance()->suggestions;

    expect(collect($labels)->pluck('label')->all())->toBe(['Acme Ltd', 'Big Acme']);
});
