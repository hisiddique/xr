<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Setting::flushCache();
});

test('admin can upload a company logo', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $file = UploadedFile::fake()->image('logo.png');

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('logo', $file)
        ->call('save')
        ->assertHasNoErrors();

    Setting::flushCache();

    $path = Setting::get('company_logo_path');
    expect($path)->not->toBeNull()->not->toBeEmpty();
    Storage::disk('public')->assertExists($path);
});

test('admin can remove a company logo', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $file = UploadedFile::fake()->image('logo.png');

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('logo', $file)
        ->call('save');

    Setting::flushCache();
    $path = Setting::get('company_logo_path');
    expect($path)->not->toBeEmpty();

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->call('removeLogo')
        ->assertHasNoErrors();

    Setting::flushCache();
    Storage::disk('public')->assertMissing($path);
    expect(Setting::get('company_logo_path'))->toBeEmpty();
});

test('non-image file is rejected', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    Livewire::actingAs($admin)
        ->test('pages::settings.crm')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});
