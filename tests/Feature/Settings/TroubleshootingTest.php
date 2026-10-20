<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can send a test email through the configured mailer', function () {
    Mail::fake();
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::settings.troubleshooting')
        ->set('recipient', 'someone@example.com')
        ->call('sendTestEmail')
        ->assertSet('result.ok', true);
});

test('a mailer failure is shown instead of thrown', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    Mail::shouldReceive('raw')->andThrow(new RuntimeException('boom'));

    Livewire::actingAs($user)
        ->test('pages::settings.troubleshooting')
        ->set('recipient', 'someone@example.com')
        ->call('sendTestEmail')
        ->assertSet('result.ok', false)
        ->assertSet('result.message', 'boom');
});
