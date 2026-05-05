<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('vat_rate', '20', 'float');
    Setting::set('company_name', 'Acme Co', 'string');
});

dataset('staff_pages', function () {
    return [
        'dashboard' => fn () => '/dashboard',
        'customers index' => fn () => '/customers',
        'customers create' => fn () => '/customers/create',
        'delivery notes index' => fn () => '/delivery-notes',
        'delivery notes create' => fn () => '/delivery-notes/create',
        'invoices index' => fn () => '/invoices',
    ];
});

it('renders authenticated staff pages without errors', function (string $url) {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    Customer::factory()->count(2)->create();

    $this->actingAs($user)->get($url)->assertOk();
})->with('staff_pages');

it('renders the customer show / edit pages', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $this->actingAs($user)->get("/customers/{$customer->id}")->assertOk();
    $this->actingAs($user)->get("/customers/{$customer->id}/edit")->assertOk();
});

it('renders the delivery note show / edit pages', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $dn = Document::factory()->deliveryNote()->create();
    DocumentItem::factory()->count(2)->create(['document_id' => $dn->id]);

    $this->actingAs($user)->get("/delivery-notes/{$dn->id}")->assertOk();
    $this->actingAs($user)->get("/delivery-notes/{$dn->id}/edit")->assertOk();
});

it('renders the invoice show / edit pages', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $invoice = Document::factory()->invoice()->create();
    DocumentItem::factory()->count(2)->create(['document_id' => $invoice->id]);

    $this->actingAs($user)->get("/invoices/{$invoice->id}")->assertOk();
    $this->actingAs($user)->get("/invoices/{$invoice->id}/edit")->assertOk();
});

it('renders admin pages for an admin user', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $this->actingAs($admin)->get('/users')->assertOk();
    $this->actingAs($admin)->get('/users/create')->assertOk();
    $this->actingAs($admin)->get('/settings/crm')->assertOk();
    $this->actingAs($admin)->get('/settings/profile')->assertOk();
    $this->actingAs($admin)->get('/reference-data/titles')->assertOk();
    $this->actingAs($admin)->get('/reference-data/credit-terms')->assertOk();
    $this->actingAs($admin)->get('/reference-data/credit-limits')->assertOk();
    $this->actingAs($admin)->get('/reference-data/units')->assertOk();
});
