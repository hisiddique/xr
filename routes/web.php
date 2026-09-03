<?php

use App\Http\Controllers\ConvertDeliveryNoteController;
use App\Http\Controllers\CustomerOutstandingExportController;
use App\Http\Controllers\CustomerStatementController;
use App\Http\Controllers\CustomerTurnoverExportController;
use App\Http\Controllers\DocumentPdfController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\SupplierDebitNotePdfController;
use App\Http\Controllers\SupplierInvoiceAttachmentController;
use App\Http\Controllers\SupplierPurchasingExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // Customers
    Route::livewire('customers', 'pages::customers.index')->name('customers.index')->middleware('can:customer-index');
    Route::livewire('customers/create', 'pages::customers.create')->name('customers.create')->middleware('can:customer-create');
    Route::livewire('customers/{customer}/edit', 'pages::customers.edit')->name('customers.edit')->middleware('can:customer-edit');
    Route::livewire('customers/{customer}', 'pages::customers.show')->name('customers.show')->middleware('can:customer-show');
    Route::get('customers/{customer}/statement', [CustomerStatementController::class, 'export'])->name('customers.statement.export');

    // Suppliers
    Route::livewire('suppliers', 'pages::suppliers.index')->name('suppliers.index')->middleware('can:supplier-index');
    Route::livewire('suppliers/create', 'pages::suppliers.form')->name('suppliers.create')->middleware('can:supplier-create');
    Route::livewire('suppliers/{supplier}/edit', 'pages::suppliers.form')->name('suppliers.edit')->middleware('can:supplier-edit');
    Route::livewire('suppliers/{supplier}', 'pages::suppliers.show')->name('suppliers.show')->middleware('can:supplier-show');

    // Supplier Invoices
    Route::livewire('supplier-invoices', 'pages::supplier-invoices.index')->name('supplier-invoices.index')->middleware('can:supplierinvoice-index');
    Route::livewire('supplier-invoices/create', 'pages::supplier-invoices.form')->name('supplier-invoices.create')->middleware('can:supplierinvoice-create');
    Route::livewire('supplier-invoices/{supplierInvoice}/edit', 'pages::supplier-invoices.form')->name('supplier-invoices.edit')->middleware('can:supplierinvoice-edit');
    Route::livewire('supplier-invoices/{supplierInvoice}', 'pages::supplier-invoices.show')->name('supplier-invoices.show')->middleware('can:supplierinvoice-show');
    Route::get('supplier-invoices/{supplierInvoice}/attachments/download', [SupplierInvoiceAttachmentController::class, 'download'])->name('supplier-invoices.attachments.download');

    // Delivery Notes
    Route::livewire('delivery-notes', 'pages::delivery-notes.index')->name('delivery-notes.index')->middleware('can:deliverynote-index');
    Route::livewire('delivery-notes/create', 'pages::delivery-notes.create')->name('delivery-notes.create')->middleware('can:deliverynote-create');
    Route::livewire('delivery-notes/{document}/edit', 'pages::delivery-notes.edit')->name('delivery-notes.edit')->middleware('can:deliverynote-edit');
    Route::livewire('delivery-notes/{document}', 'pages::delivery-notes.show')->name('delivery-notes.show')->middleware('can:deliverynote-show');
    Route::post('delivery-notes/{document}/convert', ConvertDeliveryNoteController::class)->name('delivery-notes.convert')->middleware('can:deliverynote-convert');

    // Invoices
    Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index')->middleware('can:invoice-index');
    Route::livewire('invoices/{document}/edit', 'pages::invoices.edit')->name('invoices.edit')->middleware('can:invoice-edit');
    Route::livewire('invoices/{document}', 'pages::invoices.show')->name('invoices.show')->middleware('can:invoice-show');

    // Document Search
    Route::livewire('document-search', 'pages::document-search.index')->name('document-search.index')->middleware('can:documentsearch-index');

    // Credit Notes
    Route::livewire('credit-notes', 'pages::credit-notes.index')->name('credit-notes.index')->middleware('can:creditnote-index');
    Route::livewire('credit-notes/create', 'pages::credit-notes.create')->name('credit-notes.create')->middleware('can:creditnote-create');
    Route::livewire('credit-notes/{document}/edit', 'pages::credit-notes.edit')->name('credit-notes.edit')->middleware('can:creditnote-edit');
    Route::livewire('credit-notes/{document}', 'pages::credit-notes.show')->name('credit-notes.show')->middleware('can:creditnote-show');

    // Payments
    Route::livewire('payments', 'pages::payments.index')->name('payments.index')->middleware('can:payment-index');
    Route::livewire('payments/create', 'pages::payments.form')->name('payments.create')->middleware('can:payment-create');
    Route::livewire('payments/{payment}/edit', 'pages::payments.form')->name('payments.edit')->middleware('can:payment-edit');
    Route::livewire('payments/{payment}', 'pages::payments.show')->name('payments.show')->middleware('can:payment-show');

    // Overheads
    Route::livewire('overheads', 'pages::overheads.index')->name('overheads.index')->middleware('can:overhead-index');
    Route::livewire('overheads/create', 'pages::overheads.form')->name('overheads.create')->middleware('can:overhead-create');
    Route::livewire('overheads/{overhead}/edit', 'pages::overheads.form')->name('overheads.edit')->middleware('can:overhead-edit');
    Route::livewire('overheads/{overhead}', 'pages::overheads.show')->name('overheads.show')->middleware('can:overhead-show');

    // Supplier Debit Notes
    Route::livewire('supplier-debit-notes', 'pages::supplier-debit-notes.index')->name('supplier-debit-notes.index')->middleware('can:supplierdebitnote-index');
    Route::livewire('supplier-debit-notes/create', 'pages::supplier-debit-notes.create')->name('supplier-debit-notes.create')->middleware('can:supplierdebitnote-create');
    Route::livewire('supplier-debit-notes/{debitNote}/edit', 'pages::supplier-debit-notes.edit')->name('supplier-debit-notes.edit')->middleware('can:supplierdebitnote-edit');
    Route::livewire('supplier-debit-notes/{debitNote}', 'pages::supplier-debit-notes.show')->name('supplier-debit-notes.show')->middleware('can:supplierdebitnote-show');
    Route::get('supplier-debit-notes/{debitNote}/pdf', [SupplierDebitNotePdfController::class, 'show'])->name('supplier-debit-notes.pdf')->middleware('can:supplierdebitnote-show');
    Route::get('supplier-debit-notes/{debitNote}/pdf/download', [SupplierDebitNotePdfController::class, 'download'])->name('supplier-debit-notes.pdf.download')->middleware('can:supplierdebitnote-show');

    // Reports
    Route::livewire('reports/overheads', 'pages::reports.overheads')->name('reports.overheads')->middleware('can:report-overheads');
    Route::livewire('reports/supplier-purchasing', 'pages::reports.supplier-purchasing')->name('reports.supplier-purchasing')->middleware('can:report-supplierPurchasing');
    Route::get('reports/supplier-purchasing/export/{format}', [SupplierPurchasingExportController::class, 'export'])
        ->where('format', 'csv|xlsx|pdf')
        ->name('reports.supplier-purchasing.export');
    Route::livewire('reports/customer-outstanding-payments', 'pages::reports.customer-outstanding-payments')->name('reports.customer-outstanding-payments')->middleware('can:report-customerOutstandingPayments');
    Route::get('reports/customer-outstanding-payments/export/{format}', [CustomerOutstandingExportController::class, 'export'])
        ->where('format', 'csv|xlsx|pdf')
        ->name('reports.customer-outstanding-payments.export');
    Route::livewire('reports/customer-turnover', 'pages::reports.customer-turnover')->name('reports.customer-turnover')->middleware('can:report-customerTurnover');
    Route::get('reports/customer-turnover/export/{format}', [CustomerTurnoverExportController::class, 'export'])
        ->where('format', 'csv|xlsx|pdf')
        ->name('reports.customer-turnover.export');

    // Exports
    Route::livewire('exports', 'pages::exports.index')->name('exports.index')->middleware('can:export-index');
    Route::get('exports/{exportJob}/download', [ExportDownloadController::class, 'download'])->name('exports.download');

    // Supplier Payouts
    Route::livewire('supplier-payouts', 'pages::supplier-payouts.index')->name('supplier-payouts.index')->middleware('can:supplierpayout-index');
    Route::livewire('supplier-payouts/{payout}/edit', 'pages::supplier-payouts.edit')->name('supplier-payouts.edit')->middleware('can:supplierpayout-edit');
    Route::livewire('supplier-payouts/{payout}', 'pages::supplier-payouts.show')->name('supplier-payouts.show')->middleware('can:supplierpayout-show');

    // Document PDF
    Route::get('documents/{document}/pdf', [DocumentPdfController::class, 'show'])->name('documents.pdf');
    Route::get('documents/{document}/pdf/download', [DocumentPdfController::class, 'download'])->name('documents.pdf.download');
    Route::post('documents/{document}/record-print', [DocumentPdfController::class, 'recordPrint'])->name('documents.record-print');

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        Route::livewire('settings/crm', 'pages::settings.crm')->name('settings.crm')->middleware('can:settings-crm');
        Route::livewire('settings/legacy-migration', 'pages::settings.legacy-migration')->name('settings.legacy-migration')->middleware('can:settings-legacyMigration');

        // Users
        Route::livewire('users', 'pages::users.index')->name('users.index')->middleware('can:user-index');
        Route::livewire('users/create', 'pages::users.create')->name('users.create')->middleware('can:user-create');
        Route::livewire('users/{user}/edit', 'pages::users.edit')->name('users.edit')->middleware('can:user-edit');

        // Roles
        Route::livewire('roles', 'pages::roles.index')->name('roles.index')->middleware('can:role-index');
        Route::livewire('roles/create', 'pages::roles.form')->name('roles.create')->middleware('can:role-create');
        Route::livewire('roles/{role}/edit', 'pages::roles.form')->name('roles.edit')->middleware('can:role-edit');

        // Reference data
        Route::livewire('reference-data/titles', 'pages::reference-data.titles')->name('reference-data.titles')->middleware('can:referencedata-titles');
        Route::livewire('reference-data/credit-terms', 'pages::reference-data.credit-terms')->name('reference-data.credit-terms')->middleware('can:referencedata-creditTerms');
        Route::livewire('reference-data/credit-limits', 'pages::reference-data.credit-limits')->name('reference-data.credit-limits')->middleware('can:referencedata-creditLimits');
        Route::livewire('reference-data/units', 'pages::reference-data.units')->name('reference-data.units')->middleware('can:referencedata-units');
        Route::livewire('reference-data/payment-methods', 'pages::reference-data.payment-methods')->name('reference-data.payment-methods')->middleware('can:referencedata-paymentMethods');
        Route::livewire('reference-data/expense-categories', 'pages::reference-data.expense-categories')->name('reference-data.expense-categories')->middleware('can:referencedata-expenseCategories');
        Route::livewire('reference-data/customer-categories', 'pages::reference-data.customer-categories')->name('reference-data.customer-categories')->middleware('can:referencedata-customerCategories');
        Route::livewire('reference-data/revenue-types', 'pages::reference-data.revenue-types')->name('reference-data.revenue-types')->middleware('can:referencedata-revenueTypes');
    });
});

require __DIR__.'/settings.php';
