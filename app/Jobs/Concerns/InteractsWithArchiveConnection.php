<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\DB;

trait InteractsWithArchiveConnection
{
    protected const LOCK_KEY = 'archive:run';

    /** @var array<string, mixed>|null */
    private ?array $originalArchiveConfig = null;

    /**
     * @param  array{host:string,port:int|string,database:string,username:string,password:string}|null  $creds
     */
    protected function applyArchiveCredentials(?array $creds): void
    {
        $this->originalArchiveConfig = config('database.connections.archive');

        if (! $creds) {
            return;
        }

        config(['database.connections.archive' => array_merge(
            $this->originalArchiveConfig ?? [],
            [
                'host' => $creds['host'],
                'port' => (int) ($creds['port'] ?: 3306),
                'database' => $creds['database'],
                'username' => $creds['username'],
                'password' => $creds['password'],
            ],
        )]);

        DB::purge('archive');
    }

    /** Undo applyArchiveCredentials() — the worker process is long-lived. */
    protected function restoreArchiveConnection(): void
    {
        DB::purge('archive');

        if ($this->originalArchiveConfig !== null) {
            config(['database.connections.archive' => $this->originalArchiveConfig]);
            $this->originalArchiveConfig = null;
        }
    }
}
