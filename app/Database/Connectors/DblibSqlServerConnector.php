<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\SqlServerConnector;

/**
 * Forces Laravel's SQL Server connector down the FreeTDS (pdo_dblib) DSN path
 * even when pdo_sqlsrv is loaded but non-functional — this is the case on
 * shared hosts (e.g. Hostinger) where sqlsrv/pdo_sqlsrv extensions are present
 * but Microsoft's proprietary msodbcsql driver underneath them cannot be
 * installed without root. FreeTDS is a self-contained driver that doesn't
 * need msodbcsql, so it works on hosts without system-level package access.
 */
class DblibSqlServerConnector extends SqlServerConnector
{
    /**
     * Pretend 'sqlsrv' (and 'odbc') are never available so the parent's
     * getDsn() always falls through to its FreeTDS DSN builder.
     */
    protected function getAvailableDrivers()
    {
        return array_diff(parent::getAvailableDrivers(), ['sqlsrv', 'odbc']);
    }
}
