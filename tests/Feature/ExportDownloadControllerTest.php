<?php

use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('owner can download a completed export', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    Storage::disk('local')->put('exports/1/customer-outstanding-payments.csv', 'a,b,c');

    $export = ExportJob::create([
        'status' => ExportJobStatus::Completed,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'download_path' => 'exports/1/customer-outstanding-payments.csv',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('exports.download', $export))
        ->assertOk();
});

test('non-owner cannot download the export', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $other = User::factory()->create();
    Storage::disk('local')->put('exports/1/customer-outstanding-payments.csv', 'a,b,c');

    $export = ExportJob::create([
        'status' => ExportJobStatus::Completed,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'download_path' => 'exports/1/customer-outstanding-payments.csv',
        'created_by' => $owner->id,
    ]);

    $this->actingAs($other)
        ->get(route('exports.download', $export))
        ->assertForbidden();
});

test('incomplete export cannot be downloaded', function () {
    $user = User::factory()->create();

    $export = ExportJob::create([
        'status' => ExportJobStatus::Running,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('exports.download', $export))
        ->assertNotFound();
});
