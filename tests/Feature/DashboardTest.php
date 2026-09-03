<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('menu groups with no granted permissions are hidden', function () {
    $this->actingAs(User::factory()->staff()->noRoles()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Customer Operations Management')
        ->assertDontSee('Supplier Operations Management')
        ->assertSee('Identity & Team Management');
});

test('menu groups are shown when the user can access at least one tile', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Customer Operations Management');
});
