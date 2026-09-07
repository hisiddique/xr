<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Points the app's `archive` connection at a fresh in-memory SQLite database for the
 * current test and provisions the full app schema onto it, instead of the real
 * ARCHIVE_DB_* MySQL connection from config/database.php.
 */
function useArchiveDatabase(): void
{
    config(['database.connections.archive' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    DB::purge('archive');
    // Hold the PDO open for the rest of the test so the :memory: db persists.
    DB::connection('archive')->getPdo();
    Artisan::call('migrate', ['--database' => 'archive', '--force' => true]);
}

function archiveDb(): Connection
{
    return DB::connection('archive');
}
