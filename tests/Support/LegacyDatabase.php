<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points the app's `legacy` connection at a fresh in-memory SQLite database for the
 * current test, instead of the real sqlsrv/LDB_* connection wired in config/database.php.
 */
function useLegacyDatabase(): void
{
    config(['database.connections.legacy' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    DB::purge('legacy');
}

/**
 * Creates only the legacy tables named in $tables, shaped with the columns each
 * mapper under test actually reads (see app/Services/Migration/Mappers/*.php).
 *
 * @param  array<int, string>  $tables
 */
function createLegacyTables(array $tables): void
{
    $creators = [
        'Units' => function (): void {
            Schema::connection('legacy')->create('Units', function (Blueprint $table): void {
                $table->unsignedBigInteger('uid');
                $table->string('name');
                $table->string('status', 1);
                $table->string('recstate', 1)->nullable();
            });
        },
        'AppCodes' => function (): void {
            Schema::connection('legacy')->create('AppCodes', function (Blueprint $table): void {
                $table->string('codetype');
                $table->integer('valueint')->nullable();
                $table->string('description')->nullable();
            });
        },
        'CustSupps' => function (): void {
            Schema::connection('legacy')->create('CustSupps', function (Blueprint $table): void {
                $table->unsignedBigInteger('uid');
                $table->string('rtype', 1);
                $table->string('name')->nullable();
                $table->string('add1')->nullable();
                $table->string('add2')->nullable();
                $table->string('town')->nullable();
                $table->string('pcode')->nullable();
                $table->string('email')->nullable();
                $table->decimal('disc', 8, 2)->nullable();
                $table->string('vatcode')->nullable();
                $table->decimal('vatdiff', 8, 2)->nullable();
                $table->decimal('crlim', 12, 2)->nullable();
                $table->integer('term')->nullable();
                $table->string('createddate')->nullable();
                $table->string('modifieddate')->nullable();
            });
        },
        'Documents' => function (): void {
            Schema::connection('legacy')->create('Documents', function (Blueprint $table): void {
                $table->unsignedBigInteger('uid');
                $table->string('rtype', 1);
                $table->unsignedBigInteger('acctuid')->nullable();
                $table->string('orderno')->nullable();
                $table->date('date')->nullable();
                $table->decimal('goods', 12, 2)->nullable();
                $table->decimal('value', 12, 2)->nullable();
                $table->text('notes')->nullable();
                $table->string('ref')->nullable();
                $table->unsignedBigInteger('bline')->nullable();
                $table->string('deleteddate')->nullable();
                $table->integer('salesman')->nullable();
                $table->string('srcabbr')->nullable();
                $table->string('srcref')->nullable();
                $table->unsignedBigInteger('invuid')->nullable();
                $table->unsignedBigInteger('origdeln')->nullable();
                $table->dateTime('emailsent')->nullable();
                $table->dateTime('printtime')->nullable();
                $table->unsignedTinyInteger('status')->nullable();
            });
        },
        'DocumentDetails' => function (): void {
            Schema::connection('legacy')->create('DocumentDetails', function (Blueprint $table): void {
                $table->unsignedBigInteger('uid');
                $table->string('rtype', 1);
                $table->unsignedBigInteger('bline')->nullable();
                $table->string('details')->nullable();
                $table->decimal('qty', 12, 2)->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->string('unitdesc')->nullable();
                $table->unsignedBigInteger('unituid')->nullable();
                $table->decimal('value', 12, 2)->nullable();
            });
        },
        'Companies' => function (): void {
            Schema::connection('legacy')->create('Companies', function (Blueprint $table): void {
                $table->string('CompanyName')->nullable();
                $table->string('add1')->nullable();
                $table->string('add2')->nullable();
                $table->string('county')->nullable();
                $table->string('pcode')->nullable();
                $table->string('country')->nullable();
                $table->string('email_sales')->nullable();
                $table->string('email_accounts')->nullable();
            });
        },
        'CompanySettings' => function (): void {
            Schema::connection('legacy')->create('CompanySettings', function (Blueprint $table): void {
                $table->string('name')->nullable();
                $table->string('value')->nullable();
                $table->string('status', 1)->nullable();
            });
        },
        'AccountEntries' => function (): void {
            Schema::connection('legacy')->create('AccountEntries', function (Blueprint $table): void {
                $table->unsignedBigInteger('uid');
                $table->string('rtype', 1);
                $table->unsignedBigInteger('custid')->nullable();
                $table->decimal('value', 12, 2)->nullable();
                $table->decimal('osvalue', 12, 2)->nullable();
                $table->string('txndate')->nullable();
                $table->string('invno')->nullable();
                $table->unsignedBigInteger('posttype')->nullable();
                $table->string('createddate')->nullable();
                $table->string('modifieddate')->nullable();
            });
        },
        'AccountPostTypes' => function (): void {
            Schema::connection('legacy')->create('AccountPostTypes', function (Blueprint $table): void {
                $table->unsignedBigInteger('uid');
                $table->string('rtype', 1)->nullable();
                $table->string('inout', 3)->nullable();
                $table->integer('entryvalue')->nullable();
            });
        },
        'AccountBatchItems' => function (): void {
            Schema::connection('legacy')->create('AccountBatchItems', function (Blueprint $table): void {
                $table->unsignedBigInteger('bhead')->nullable();
                $table->unsignedBigInteger('bline')->nullable();
                $table->unsignedBigInteger('cshuid')->nullable();
                $table->string('txnabbr')->nullable();
                $table->string('txnref')->nullable();
                $table->string('txndetails')->nullable();
                $table->decimal('paymt', 12, 2)->nullable();
                $table->decimal('osvalue', 12, 2)->nullable();
                $table->unsignedBigInteger('posttype')->nullable();
                $table->string('txndate')->nullable();
            });
        },
    ];

    foreach ($tables as $table) {
        $creators[$table]();
    }
}
