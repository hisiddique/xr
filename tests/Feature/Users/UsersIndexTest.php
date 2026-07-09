<?php

use App\Models\User;
use App\UserStatus;

test('users index renders and shows a status badge for a non-active user', function () {
    $admin = User::factory()->admin()->create();
    $migrated = User::factory()->create(['name' => 'Colin B', 'status' => UserStatus::Migrated]);

    $response = $this->actingAs($admin)->get(route('users.index'));

    $response->assertOk()
        ->assertSee('Colin B')
        ->assertSee(UserStatus::Migrated->label());
});
