<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\CustomerTurnoverReportService;
use App\Services\SupplierPurchasingReportService;
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

test('customer column filters match anywhere but list starts-with matches first', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));

    Customer::factory()->create(['company_name' => 'Ordchard Plumbing']);
    Customer::factory()->create(['company_name' => 'Redcroft Forge']);
    Customer::factory()->create(['company_name' => 'DCS Construction']);
    Customer::factory()->create(['company_name' => 'DC Brickwork']);
    Customer::factory()->create(['company_name' => 'Unrelated']);

    $names = Livewire\Livewire::test('pages::customers.index')
        ->set('filters.company', 'dc')
        ->instance()->customers->pluck('company_name')->all();

    expect(array_slice($names, 0, 2))->toEqualCanonicalizing(['DCS Construction', 'DC Brickwork'])
        ->and($names)->toContain('Ordchard Plumbing', 'Redcroft Forge')
        ->and($names)->not->toContain('Unrelated');
});

test('turnover report search lists starts-with matches first on the default sort and keeps an explicit sort', function () {
    $service = app(CustomerTurnoverReportService::class);

    foreach (['Zed Acme' => 500, 'Acme Zulu' => 100, 'Acme Alpha' => 50] as $name => $total) {
        $customer = Customer::factory()->create(['company_name' => $name]);
        Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => $total, 'doc_date' => now()]);
    }

    $names = fn (array $filters) => $service->customersQuery($filters)->pluck('company_name')->all();

    expect($names(['search' => 'acme', 'sortColumn' => 'total', 'sortDirection' => 'desc']))->toBe(['Acme Zulu', 'Acme Alpha', 'Zed Acme'])
        ->and($names(['search' => 'acme', 'sortColumn' => 'total', 'sortDirection' => 'asc']))->toBe(['Acme Alpha', 'Acme Zulu', 'Zed Acme'])
        ->and($names(['search' => 'acme', 'sortColumn' => 'company_name', 'sortDirection' => 'asc']))->toBe(['Acme Alpha', 'Acme Zulu', 'Zed Acme']);
});

test('supplier purchasing report lists starts-with suppliers and invoices first on the default sort', function () {
    $service = app(SupplierPurchasingReportService::class);

    foreach (['Zed Acme', 'Acme Zulu', 'Acme Alpha'] as $name) {
        $supplier = Supplier::factory()->create(['company_name' => $name]);
        SupplierInvoice::factory()->create(['supplier_id' => $supplier->id, 'status' => 'posted']);
    }

    $names = fn (array $filters) => $service->suppliersQuery($filters)->get()->pluck('company_name')->all();

    expect($names(['search' => 'acme']))->toBe(['Acme Alpha', 'Acme Zulu', 'Zed Acme'])
        ->and($names(['search' => 'acme', 'sortBy' => 'company_name', 'sortDirection' => 'desc']))->toBe(['Zed Acme', 'Acme Zulu', 'Acme Alpha']);
});

test('document search lists details that start with the term first', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    $customer = Customer::factory()->create();
    $document = Document::factory()->invoice()->create(['customer_id' => $customer->id]);

    foreach (['Large Widget', 'Widget Small', 'Widget Large'] as $details) {
        DocumentItem::factory()->create(['document_id' => $document->id, 'details' => $details, 'is_note' => false]);
    }

    $details = Livewire\Livewire::test('pages::document-search.index')
        ->set('search', 'widget')
        ->instance()->items()->pluck('details')->all();

    expect(array_slice($details, 0, 2))->toEqualCanonicalizing(['Widget Small', 'Widget Large'])
        ->and($details[2])->toBe('Large Widget');
});

test('a null or blank search term leaves the query untouched', function () {
    Customer::factory()->count(2)->create();

    foreach ([null, '', '   '] as $term) {
        $query = Customer::query();
        Search::filter($query, $term, ['company_name']);
        Search::rankStartsWithFirst($query, $term, ['company_name', 'title.name']);

        expect($query->count())->toBe(2)
            ->and($query->toSql())->not->toContain('case when');
    }
});

test('clearing a column filter on customers and suppliers does not error', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    Customer::factory()->create();
    Supplier::factory()->create();

    foreach (['customers' => ['reference', 'company', 'email', 'contact'], 'suppliers' => ['reference', 'company']] as $page => $keys) {
        foreach ($keys as $key) {
            Livewire\Livewire::test("pages::{$page}.index")
                ->set("filters.{$key}", 'a')
                ->set("filters.{$key}", null)
                ->assertHasNoErrors();
        }
    }
});

test('typeahead ranks name matches, then reference matches, then contains matches', function () {
    foreach ([
        ['Fairview Lifting', 'FLG'],
        ['Flat Roofing', 'FLAT'],
        ['Flowdrill UK', 'FLOWDRILL'],
        ['City Electrical', 'CITY EFL'],
        ['Masplas Flexible', 'MASPLAS'],
    ] as [$name, $reference]) {
        Supplier::factory()->create(['company_name' => $name, 'reference' => $reference]);
    }

    $labels = Livewire\Livewire::test('pages::ui.typeahead', [
        'model' => Supplier::class,
        'column' => 'company_name',
        'searchColumns' => ['company_name', 'reference'],
        'minChars' => 2,
    ])->set('search', 'fl')->instance()->suggestions;

    expect(collect($labels)->pluck('label')->all())->toBe(['Flat Roofing', 'Flowdrill UK', 'Fairview Lifting', 'City Electrical', 'Masplas Flexible']);
});

test('typeahead with a single search column does not match unrelated rows', function () {
    Customer::factory()->create(['company_name' => 'Acme Ltd']);
    Customer::factory()->create(['company_name' => 'Unrelated']);

    $labels = Livewire\Livewire::test('pages::ui.typeahead', ['model' => Customer::class, 'column' => 'company_name', 'minChars' => 3])
        ->set('search', 'acm')->instance()->suggestions;

    expect(collect($labels)->pluck('label')->all())->toBe(['Acme Ltd']);
});

test('ranking lists exact matches, then starts with, then ends with, then contains', function () {
    foreach (['xdcx', 'adc', 'dca', 'dc'] as $name) {
        Customer::factory()->create(['company_name' => $name]);
    }

    $query = Customer::query();
    Search::filter($query, 'dc', ['company_name']);
    Search::rankStartsWithFirst($query, 'dc', ['company_name']);

    expect($query->orderBy('company_name')->pluck('company_name')->all())->toBe(['dc', 'dca', 'adc', 'xdcx']);
});

test('typeahead lists exact, then starts with, then ends with, then contains', function () {
    foreach (['xdcx', 'adc', 'dca', 'dc'] as $name) {
        Customer::factory()->create(['company_name' => $name]);
    }

    $labels = Livewire\Livewire::test('pages::ui.typeahead', ['model' => Customer::class, 'column' => 'company_name', 'minChars' => 2])
        ->set('search', 'dc')
        ->instance()->suggestions;

    expect(collect($labels)->pluck('label')->all())->toBe(['dc', 'dca', 'adc', 'xdcx']);
});
