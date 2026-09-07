<?php

namespace App\Services\Archive;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchiveConnectionValidator
{
    /**
     * Validate a set of archive-DB credentials without persisting anything.
     *
     * @param  array{host:string,port:int|string,database:string,username:string,password:string}  $credentials
     */
    public function check(array $credentials): ArchiveConnectionCheck
    {
        $host = trim((string) ($credentials['host'] ?? ''));
        $database = trim((string) ($credentials['database'] ?? ''));
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        $port = (int) ($credentials['port'] ?? 3306) ?: 3306;

        if ($host === '' || $database === '' || $username === '') {
            return new ArchiveConnectionCheck(false, 'Enter host, database and username.');
        }

        $mainConnection = config('database.default');
        $mainDatabase = config('database.connections.'.$mainConnection.'.database');
        $mainHost = config('database.connections.'.$mainConnection.'.host');
        $mainPort = (int) config('database.connections.'.$mainConnection.'.port');

        if ($database === $mainDatabase && $host === $mainHost && $port === $mainPort) {
            return new ArchiveConnectionCheck(false, 'The archive database must be different from the main application database.');
        }

        config(['database.connections.archive' => array_merge(
            config('database.connections.archive', []),
            [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
            ],
        )]);

        DB::purge('archive');

        try {
            DB::connection('archive')->getPdo();
        } catch (\Throwable $e) {
            return new ArchiveConnectionCheck(false, __('Connection failed: :message', [
                'message' => str($e->getMessage())->limit(200),
            ]));
        }

        if (DB::connection('archive')->getDriverName() !== 'mysql') {
            return new ArchiveConnectionCheck(false, 'The archive database must be MySQL.');
        }

        try {
            $archiveIdentity = DB::connection('archive')->selectOne('SELECT @@server_uuid AS server_uuid, DATABASE() AS db_name');

            if (in_array(DB::connection($mainConnection)->getDriverName(), ['mysql', 'mariadb'], true)) {
                $mainIdentity = DB::connection($mainConnection)->selectOne('SELECT @@server_uuid AS server_uuid, DATABASE() AS db_name');

                if ($archiveIdentity
                    && $mainIdentity
                    && $archiveIdentity->server_uuid === $mainIdentity->server_uuid
                    && strtolower((string) $archiveIdentity->db_name) === strtolower((string) $mainIdentity->db_name)
                ) {
                    return new ArchiveConnectionCheck(false, 'The archive database resolves to the same server and schema as the main application database. Choose a different database.');
                }
            }
        } catch (\Throwable $e) {
            return new ArchiveConnectionCheck(false, __('Could not verify the archive database identity: :message', [
                'message' => str($e->getMessage())->limit(200),
            ]));
        }

        $schemaReady = false;

        try {
            $schemaReady = Schema::connection('archive')->hasTable('documents');
        } catch (\Throwable $e) {
            $schemaReady = false;
        }

        return new ArchiveConnectionCheck(
            true,
            'Connected to the archive database.'.($schemaReady ? '' : ' Schema not yet provisioned.'),
            $schemaReady,
        );
    }
}
