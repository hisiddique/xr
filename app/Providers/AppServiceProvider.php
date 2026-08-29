<?php

namespace App\Providers;

use App\Database\Connectors\DblibSqlServerConnector;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerLegacyDblibDriver();
    }

    /**
     * Register a custom 'legacy_dblib' DB driver used only by the `legacy`
     * connection (config/database.php) — lets the legacy-migration feature
     * connect via FreeTDS on hosts (e.g. Hostinger shared hosting) where the
     * native sqlsrv PDO driver is present but unusable without root access
     * to install Microsoft's msodbcsql. Fully isolated from the stock
     * 'sqlsrv' driver/connection, which is untouched.
     */
    protected function registerLegacyDblibDriver(): void
    {
        $this->app->singleton('db.connector.legacy_dblib', fn () => new DblibSqlServerConnector);

        Connection::resolverFor(
            'legacy_dblib',
            fn ($connection, $database, $prefix, $config) => new SqlServerConnection($connection, $database, $prefix, $config)
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureGates(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole('sysadmin') ? true : null;
        });

        foreach (Role::allPermissionKeys() as $key) {
            Gate::define($key, fn (User $user): bool => $user->hasPermission($key));
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
