<?php

use App\Http\Controllers\ConvertDeliveryNoteController;
use App\Http\Controllers\DocumentPdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // Customers
    Route::livewire('customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('customers/create', 'pages::customers.create')->name('customers.create');
    Route::livewire('customers/{customer}/edit', 'pages::customers.edit')->name('customers.edit');
    Route::livewire('customers/{customer}', 'pages::customers.show')->name('customers.show');

    // Delivery Notes
    Route::livewire('delivery-notes', 'pages::delivery-notes.index')->name('delivery-notes.index');
    Route::livewire('delivery-notes/create', 'pages::delivery-notes.create')->name('delivery-notes.create');
    Route::livewire('delivery-notes/{document}/edit', 'pages::delivery-notes.edit')->name('delivery-notes.edit');
    Route::livewire('delivery-notes/{document}', 'pages::delivery-notes.show')->name('delivery-notes.show');
    Route::post('delivery-notes/{document}/convert', ConvertDeliveryNoteController::class)->name('delivery-notes.convert');

    // Invoices
    Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
    Route::livewire('invoices/{document}/edit', 'pages::invoices.edit')->name('invoices.edit');
    Route::livewire('invoices/{document}', 'pages::invoices.show')->name('invoices.show');

    // Document PDF
    Route::get('documents/{document}/pdf', [DocumentPdfController::class, 'show'])->name('documents.pdf');
    Route::get('documents/{document}/pdf/download', [DocumentPdfController::class, 'download'])->name('documents.pdf.download');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        Route::livewire('settings/crm', 'pages::settings.crm')->name('settings.crm');
        Route::livewire('reference-data/titles', 'pages::reference-data.titles')->name('reference-data.titles');
        Route::livewire('reference-data/credit-terms', 'pages::reference-data.credit-terms')->name('reference-data.credit-terms');
        Route::livewire('reference-data/credit-limits', 'pages::reference-data.credit-limits')->name('reference-data.credit-limits');
        Route::livewire('reference-data/units', 'pages::reference-data.units')->name('reference-data.units');
    });
});

require __DIR__.'/settings.php';
