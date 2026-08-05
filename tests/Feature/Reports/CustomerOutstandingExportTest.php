<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('csv export streams outstanding invoice rows', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Export Co']);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 42, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'csv']));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('Export Co')->toContain('42.00');
});

test('xlsx export streams a valid file', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 15, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'xlsx']));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
    expect(strlen($response->streamedContent()))->toBeGreaterThan(0);
});

test('pdf export streams a pdf', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 15, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'pdf']));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('export honors active filters', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $matching = Customer::factory()->create(['company_name' => 'Alpha Ltd']);
    $other = Customer::factory()->create(['company_name' => 'Beta Ltd']);
    Document::factory()->invoice()->create(['customer_id' => $matching->id, 'total_value' => 30, 'doc_date' => now()]);
    Document::factory()->invoice()->create(['customer_id' => $other->id, 'total_value' => 40, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'csv', 'search' => 'Alpha']));

    $content = $response->streamedContent();
    expect($content)->toContain('Alpha Ltd')->not->toContain('Beta Ltd');
});
