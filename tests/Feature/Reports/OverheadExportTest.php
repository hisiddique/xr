<?php

use App\Jobs\ExportOverheadsJob;
use App\Models\ExpenseCategory;
use App\Models\ExportJob;
use App\Models\Overhead;
use App\Models\User;
use App\Services\OverheadExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('report and list routes queue an export job with the matching type', function (string $route, string $type) {
    Queue::fake();

    $user = User::factory()->admin()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route($route, ['format' => 'csv']))
        ->assertRedirect(route('exports.index'));

    expect(ExportJob::query()->where('type', $type)->where('format', 'csv')->exists())->toBeTrue();
    Queue::assertPushed(ExportOverheadsJob::class);
})->with([
    ['reports.overheads.export', 'overhead_report'],
    ['overheads.export', 'overheads'],
]);

test('every format generates a non-empty file for both scopes', function (string $scope, string $format) {
    $category = ExpenseCategory::factory()->create(['name' => 'Fuel']);
    Overhead::factory()->create(['category_id' => $category->id, 'amount' => 120, 'has_vat' => true, 'payment_method' => 'Card']);
    Overhead::factory()->create(['category_id' => $category->id, 'amount' => 50, 'has_vat' => false, 'payment_method' => 'Cash']);

    $path = tempnam(sys_get_temp_dir(), 'ovh').'.'.$format;

    $result = app(OverheadExportService::class)->generateExportFile($format, ['scope' => $scope], $path);

    expect($result['rowCount'])->toBe(2)
        ->and($result['gross'])->toBe(170.0)
        ->and(filesize($path))->toBeGreaterThan(0);

    @unlink($path);
})->with([
    ['report', 'csv'], ['report', 'xlsx'], ['report', 'pdf'],
    ['list', 'csv'], ['list', 'xlsx'], ['list', 'pdf'],
]);

test('search matches anywhere in the category name or payment method', function () {
    $fuel = ExpenseCategory::factory()->create(['name' => 'Fuel']);
    $other = ExpenseCategory::factory()->create(['name' => 'Refuelling']);
    $a = Overhead::factory()->create(['category_id' => $fuel->id, 'payment_method' => 'Cash']);
    $b = Overhead::factory()->create(['category_id' => $other->id, 'payment_method' => 'Cash']);
    Overhead::factory()->create(['category_id' => ExpenseCategory::factory()->create(['name' => 'Rent'])->id, 'payment_method' => 'Cash']);

    $ids = app(OverheadExportService::class)->overheadsQuery(['search' => 'Fue'])->pluck('id')->sort()->values();

    expect($ids->all())->toBe([$a->id, $b->id]);
});
