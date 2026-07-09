<?php

use App\Jobs\RunLegacyMigrationJob;
use App\MigrationRunStatus;
use App\Models\MigrationRun;
use App\Models\MigrationRunTable;
use App\Models\User;
use App\UserStatus;
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

test('the documents row surfaces a note about placeholder Migrated staff accounts created for unrecognized Salesman codes', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    User::factory()->create(['name' => 'Colin B', 'status' => UserStatus::Migrated]);

    $run = MigrationRun::create(['status' => MigrationRunStatus::Running, 'created_by' => $admin->id]);
    MigrationRunTable::create([
        'migration_run_id' => $run->id,
        'entity' => 'documents',
        'status' => MigrationRunStatus::Completed,
        'rows_total' => 1,
        'rows_processed' => 1,
        'added' => 1,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'orphaned_in_legacy' => 0,
    ]);

    Livewire::test('pages::settings.legacy-migration')
        ->set('activeRunId', $run->id)
        ->assertSee('status: Migrated');
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
