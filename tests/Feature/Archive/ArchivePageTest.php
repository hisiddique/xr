<?php

use App\ArchiveRunMode;
use App\ArchiveRunStatus;
use App\Jobs\RunArchiveJob;
use App\Models\ArchiveRun;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function seedArchiveConnection(): void
{
    Setting::set('archive_db_host', 'h.example');
    Setting::set('archive_db_port', '3307');
    Setting::set('archive_db_database', 'archive_x');
    Setting::set('archive_db_username', 'arch_user');
    Setting::set('archive_db_password', Crypt::encryptString('secret'));
    Setting::flushCache();
}

test('a user without the settings-archive ability cannot open the page', function () {
    $admin = User::factory()->admin()->create();
    DB::table('role_permission')->where('permission_key', 'settings-archive')->delete();

    $this->actingAs($admin)
        ->get(route('settings.archive'))
        ->assertForbidden();
});

test('an authorised admin can open the page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('settings.archive'))
        ->assertOk()
        ->assertSee('Data Archive');
});

test('startArchive is blocked until the connection is configured', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::settings.archive')
        ->set('selectedGroups', ['documents'])
        ->set('dateFrom', '2020-01-01')
        ->set('dateTo', '2020-12-31')
        ->call('startArchive');

    expect(ArchiveRun::count())->toBe(0);
});

test('startArchive creates a pending archive run once the connection is configured', function () {
    useArchiveDatabase();
    Queue::fake();

    $this->actingAs(User::factory()->admin()->create());

    Setting::set('archive_db_host', 'h');
    Setting::set('archive_db_database', 'd');
    Setting::set('archive_db_username', 'u');
    Setting::flushCache();

    Livewire::test('pages::settings.archive')
        ->set('selectedGroups', ['documents'])
        ->set('dateFrom', '2020-01-01')
        ->set('dateTo', '2020-12-31')
        ->call('startArchive');

    expect(
        ArchiveRun::where('mode', ArchiveRunMode::Archive)
            ->where('status', ArchiveRunStatus::Pending)
            ->count()
    )->toBe(1);

    Queue::assertPushed(RunArchiveJob::class);
});

test('a second run cannot start while one is already active', function () {
    useArchiveDatabase();

    $this->actingAs(User::factory()->admin()->create());

    ArchiveRun::create([
        'status' => ArchiveRunStatus::Running,
        'mode' => ArchiveRunMode::Archive,
    ]);

    Setting::set('archive_db_host', 'h');
    Setting::set('archive_db_database', 'd');
    Setting::set('archive_db_username', 'u');
    Setting::flushCache();

    Livewire::test('pages::settings.archive')
        ->set('selectedGroups', ['documents'])
        ->set('dateFrom', '2020-01-01')
        ->set('dateTo', '2020-12-31')
        ->call('startArchive');

    expect(ArchiveRun::count())->toBe(1);
    expect(ArchiveRun::where('status', ArchiveRunStatus::Pending)->count())->toBe(0);
});

test('the connection form is hidden and a summary is shown once credentials are stored', function () {
    $admin = User::factory()->admin()->create();
    seedArchiveConnection();

    $response = $this->actingAs($admin)
        ->get(route('settings.archive'))
        ->assertOk()
        ->assertSee('h.example')
        ->assertSee('archive_x')
        ->assertSee('arch_user')
        ->assertSee('Active')
        ->assertDontSee('Leave blank to keep the stored password');

    expect($response->getContent())->not->toContain('secret');
});

test('Update credentials reveals the form again', function () {
    $this->actingAs(User::factory()->admin()->create());
    seedArchiveConnection();

    Livewire::test('pages::settings.archive')
        ->assertSet('editingConnection', false)
        ->call('editConnection')
        ->assertSet('editingConnection', true)
        ->assertSee('Leave blank to keep the stored password');
});

test('clearConnection deletes the stored credentials', function () {
    $this->actingAs(User::factory()->admin()->create());
    seedArchiveConnection();

    Livewire::test('pages::settings.archive')
        ->call('clearConnection')
        ->assertSet('editingConnection', true)
        ->assertSet('connDatabase', '')
        ->assertSet('connPort', '3306');

    Setting::flushCache();

    expect(Setting::get('archive_db_host'))->toBeNull();
    expect(Setting::get('archive_db_port'))->toBeNull();
    expect(Setting::get('archive_db_database'))->toBeNull();
    expect(Setting::get('archive_db_username'))->toBeNull();
    expect(Setting::get('archive_db_password'))->toBeNull();
});

test('startCleanup refuses a source that is not a completed archive', function () {
    $this->actingAs(User::factory()->admin()->create());

    $source = ArchiveRun::create([
        'status' => ArchiveRunStatus::Failed,
        'mode' => ArchiveRunMode::Archive,
        'options' => [
            'selected_groups' => ['documents'],
            'date_from' => '2020-01-01',
            'date_to' => '2020-12-31',
        ],
    ]);

    Livewire::test('pages::settings.archive')
        ->call('confirmCleanup', $source->id)
        ->call('startCleanup');

    expect(ArchiveRun::where('mode', ArchiveRunMode::Cleanup)->count())->toBe(0);
});
