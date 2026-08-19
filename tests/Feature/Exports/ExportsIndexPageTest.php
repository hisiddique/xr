<?php

use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('exports page only lists the current user\'s jobs', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $other = User::factory()->staff()->create(['email_verified_at' => now()]);

    ExportJob::create([
        'status' => ExportJobStatus::Completed,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'created_by' => $user->id,
    ]);

    ExportJob::create([
        'status' => ExportJobStatus::Completed,
        'type' => 'customer_outstanding_payments',
        'format' => 'xlsx',
        'created_by' => $other->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->assertSeeText('csv')
        ->assertDontSeeText('xlsx');
});

test('status badge reflects each export status', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    ExportJob::create([
        'status' => ExportJobStatus::Failed,
        'type' => 'customer_outstanding_payments',
        'format' => 'pdf',
        'error' => 'PDF export exceeds 5000 invoice rows.',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->assertSeeText('Failed')
        ->assertSeeText('exceeds 5000');
});

test('cancel sets cancelled_at on a pending job owned by the user', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->call('cancel', $export->id);

    expect($export->fresh()->cancelled_at)->not->toBeNull();
    expect($export->fresh()->status)->toBe(ExportJobStatus::Pending);
});

test('cancel does not affect another user\'s job', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $other = User::factory()->staff()->create(['email_verified_at' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'created_by' => $other->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->call('cancel', $export->id);

    expect($export->fresh()->cancelled_at)->toBeNull();
});

test('download button is hidden until the export is completed', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Running,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->assertDontSee(route('exports.download', $export));

    $export->update(['status' => ExportJobStatus::Completed, 'download_path' => 'exports/1/x.csv']);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->assertSee(route('exports.download', $export));
});

test('deleting a completed export removes the record and its file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('exports/1/x.csv', 'a,b,c');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $export = ExportJob::create([
        'status' => ExportJobStatus::Completed,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'download_path' => 'exports/1/x.csv',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->call('confirmDelete', $export->id)
        ->call('deleteConfirmed');

    expect(ExportJob::find($export->id))->toBeNull();
    expect(Storage::disk('local')->exists('exports/1/x.csv'))->toBeFalse();
});

test('bulk delete removes only the selected completed exports', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $exportA = ExportJob::create(['status' => ExportJobStatus::Completed, 'type' => 'customer_outstanding_payments', 'format' => 'csv', 'created_by' => $user->id]);
    $exportB = ExportJob::create(['status' => ExportJobStatus::Completed, 'type' => 'customer_outstanding_payments', 'format' => 'xlsx', 'created_by' => $user->id]);
    $exportC = ExportJob::create(['status' => ExportJobStatus::Completed, 'type' => 'customer_outstanding_payments', 'format' => 'pdf', 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->set('selected', [$exportA->id, $exportB->id])
        ->call('confirmDelete')
        ->call('deleteConfirmed');

    expect(ExportJob::find($exportA->id))->toBeNull();
    expect(ExportJob::find($exportB->id))->toBeNull();
    expect(ExportJob::find($exportC->id))->not->toBeNull();
});

test('select all only selects deletable (terminal-status) jobs', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $completed = ExportJob::create(['status' => ExportJobStatus::Completed, 'type' => 'customer_outstanding_payments', 'format' => 'csv', 'created_by' => $user->id]);
    $running = ExportJob::create(['status' => ExportJobStatus::Running, 'type' => 'customer_outstanding_payments', 'format' => 'xlsx', 'created_by' => $user->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->set('selectAll', true);

    expect($component->get('selected'))->toBe([$completed->id]);
    expect($component->get('selected'))->not->toContain($running->id);
});

test('a pending or running export cannot be deleted even if targeted directly', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $export = ExportJob::create(['status' => ExportJobStatus::Running, 'type' => 'customer_outstanding_payments', 'format' => 'csv', 'created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->call('confirmDelete', $export->id)
        ->call('deleteConfirmed');

    expect(ExportJob::find($export->id))->not->toBeNull();
});

test('delete does not affect another user\'s export', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $other = User::factory()->staff()->create(['email_verified_at' => now()]);

    $export = ExportJob::create(['status' => ExportJobStatus::Completed, 'type' => 'customer_outstanding_payments', 'format' => 'csv', 'created_by' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->call('confirmDelete', $export->id)
        ->call('deleteConfirmed');

    expect(ExportJob::find($export->id))->not->toBeNull();
});

test('back button appears when navigated from another page on this site', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->withHeaders(['referer' => url('/reports/customer-outstanding-payments')])
        ->get('/exports')
        ->assertSee('Back', false);
});

test('back button does not appear on a direct url load with no referer', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get('/exports')
        ->assertDontSee('>Back<', false);
});

test('back button does not appear when the referer is the exports page itself', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->withHeaders(['referer' => url('/exports')])
        ->get('/exports')
        ->assertDontSee('>Back<', false);
});

test('polling is only active while a job is pending or running', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Running,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->assertSeeHtml('wire:poll.2s');

    $export->update(['status' => ExportJobStatus::Completed, 'download_path' => 'exports/1/x.csv']);

    Livewire::actingAs($user)
        ->test('pages::exports.index')
        ->assertDontSeeHtml('wire:poll.2s');
});
