<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('staff user is redirected from settings.crm with error flash', function () {
    $staff = User::factory()->staff()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($staff)->get(route('settings.crm'));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error');
});

test('staff user is redirected from reference data with error flash', function () {
    $staff = User::factory()->staff()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($staff)->get(route('reference-data.titles'));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error');
});

test('admin user can access settings.crm', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($admin)->get(route('settings.crm'));

    $response->assertOk();
});

test('admin user can access reference data titles', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($admin)->get(route('reference-data.titles'));

    $response->assertOk();
});

test('unauthenticated user is redirected to login', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('user isAdmin returns true only for admin role', function () {
    $admin = User::factory()->admin()->make();
    $staff = User::factory()->staff()->make();

    expect($admin->isAdmin())->toBeTrue()
        ->and($staff->isAdmin())->toBeFalse()
        ->and($staff->isStaff())->toBeTrue();
});
