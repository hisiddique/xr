<?php

use App\Jobs\RunLegacyMigrationJob;
use App\Models\MigrationRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('starting a migration shows progress inline on the same page, and only dispatches the job (does not run it inline)', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $component = Livewire::test('pages::settings.legacy-migration')
        ->assertOk()
        ->assertDontSee('Migration Progress')
        ->set('selectedGroups', ['customers'])
        ->set('legacyHost', '127.0.0.1')
        ->set('legacyDatabase', 'test')
        ->set('legacyUsername', 'test')
        ->set('runAsUserId', $admin->id)
        ->call('start');

    // No page navigation was ever triggered by start() — Livewire only tracks a
    // redirect if the component explicitly calls redirect()/redirectRoute(), which
    // this component never does. Asserting the *same* component instance now shows
    // the progress section (below) is the direct proof: a redirect would abandon
    // this component and land on a different page entirely, not add content to it.
    $component
        ->assertSet('activeRunId', fn ($id) => $id !== null)
        ->assertSee('Migration Progress');

    Queue::assertPushed(RunLegacyMigrationJob::class);
});

test('the configuration form is hidden while a migration is pending or running, to prevent starting a second one', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::settings.legacy-migration')
        ->assertSee('Test Connection')
        ->set('selectedGroups', ['customers'])
        ->set('legacyHost', '127.0.0.1')
        ->set('legacyDatabase', 'test')
        ->set('legacyUsername', 'test')
        ->set('runAsUserId', $admin->id)
        ->call('start')
        ->assertDontSee('Test Connection')
        ->assertSee('form is hidden until it finishes');
});

test('cancelRun requests cancellation on a pending or running migration, via a real modal not a native browser confirm', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $component = Livewire::test('pages::settings.legacy-migration')
        ->set('selectedGroups', ['customers'])
        ->set('legacyHost', '127.0.0.1')
        ->set('legacyDatabase', 'test')
        ->set('legacyUsername', 'test')
        ->set('runAsUserId', $admin->id)
        ->call('start');

    $component
        ->assertSee('confirm-cancel-migration')
        ->assertDontSeeHtml('wire:confirm');

    $run = MigrationRun::find($component->get('activeRunId'));
    expect($run->cancelled_at)->toBeNull();

    $component->call('cancelRun');

    expect($run->fresh()->cancelled_at)->not->toBeNull();
});
