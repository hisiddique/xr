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

    // Credit Notes
    Route::livewire('credit-notes', 'pages::credit-notes.index')->name('credit-notes.index');
    Route::livewire('credit-notes/create', 'pages::credit-notes.create')->name('credit-notes.create');
    Route::livewire('credit-notes/{document}/edit', 'pages::credit-notes.edit')->name('credit-notes.edit');
    Route::livewire('credit-notes/{document}', 'pages::credit-notes.show')->name('credit-notes.show');

    // Payments
    Route::livewire('payments', 'pages::payments.index')->name('payments.index');
    Route::livewire('payments/create', 'pages::payments.form')->name('payments.create');
    Route::livewire('payments/{payment}/edit', 'pages::payments.form')->name('payments.edit');
    Route::livewire('payments/{payment}', 'pages::payments.show')->name('payments.show');

    // Overheads
    Route::livewire('overheads', 'pages::overheads.index')->name('overheads.index');
    Route::livewire('overheads/create', 'pages::overheads.form')->name('overheads.create');
    Route::livewire('overheads/{overhead}/edit', 'pages::overheads.form')->name('overheads.edit');
    Route::livewire('overheads/{overhead}', 'pages::overheads.show')->name('overheads.show');

    // Document PDF
    Route::get('documents/{document}/pdf', [DocumentPdfController::class, 'show'])->name('documents.pdf');
    Route::get('documents/{document}/pdf/download', [DocumentPdfController::class, 'download'])->name('documents.pdf.download');
    Route::post('documents/{document}/record-print', [DocumentPdfController::class, 'recordPrint'])->name('documents.record-print');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        Route::livewire('settings/crm', 'pages::settings.crm')->name('settings.crm');

        // Users
        Route::livewire('users', 'pages::users.index')->name('users.index');
        Route::livewire('users/create', 'pages::users.create')->name('users.create');
        Route::livewire('users/{user}/edit', 'pages::users.edit')->name('users.edit');

        // Reference data
        Route::livewire('reference-data/titles', 'pages::reference-data.titles')->name('reference-data.titles');
        Route::livewire('reference-data/credit-terms', 'pages::reference-data.credit-terms')->name('reference-data.credit-terms');
        Route::livewire('reference-data/credit-limits', 'pages::reference-data.credit-limits')->name('reference-data.credit-limits');
        Route::livewire('reference-data/units', 'pages::reference-data.units')->name('reference-data.units');
        Route::livewire('reference-data/payment-methods', 'pages::reference-data.payment-methods')->name('reference-data.payment-methods');
        Route::livewire('reference-data/expense-categories', 'pages::reference-data.expense-categories')->name('reference-data.expense-categories');
    });
});

require __DIR__.'/settings.php';
