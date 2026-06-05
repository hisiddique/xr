<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('vat_rate', '20', 'float');
    Setting::set('company_name', 'Test Company');
    Setting::set('company_address', '123 Test St');
    Setting::set('company_email', 'test@test.com');
    Setting::set('dn_prefix', 'DN');
    Setting::set('inv_prefix', 'INV');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('admin can update VAT rate', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('vat_rate', '15')
        ->call('save')
        ->assertHasNoErrors();

    Setting::flushCache();
    expect(Setting::get('vat_rate'))->toBe(15.0);
});

test('admin can update company name', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('company_name', 'New Company Ltd')
        ->call('save')
        ->assertHasNoErrors();

    Setting::flushCache();
    expect(Setting::get('company_name'))->toBe('New Company Ltd');
});

test('VAT rate must be numeric between 0 and 100', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('vat_rate', '150')
        ->call('save')
        ->assertHasErrors(['vat_rate']);
});

test('admin can update interface font size', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('app_font_size', '20')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('font-size-saved');

    Setting::flushCache();
    expect(Setting::get('app_font_size'))->toBe(20);
});

test('interface font size must be within 14 and 22', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('app_font_size', '40')
        ->call('save')
        ->assertHasErrors(['app_font_size']);
});

test('staff cannot access settings page', function () {
    $staff = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($staff)->get(route('settings.crm'))->assertRedirect(route('dashboard'));
});

test('settings are loaded from database on mount', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $component = Livewire::actingAs($admin)
        ->test('pages::settings.crm');

    $component->assertSet('vat_rate', '20')
        ->assertSet('company_name', 'Test Company');
});
