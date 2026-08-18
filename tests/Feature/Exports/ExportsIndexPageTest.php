<?php

use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
