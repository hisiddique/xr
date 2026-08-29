<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionSeeder extends Seeder
{
    /**
     * Seed the database with the minimum required to run in production:
     * one admin user, app settings, and reference data. No sample
     * customers, delivery notes, or invoices.
     *
     * Usage: php artisan db:seed --class=ProductionSeeder --force
     *
     * Credentials can be overridden via env vars:
     *   ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            $this->command?->warn('ADMIN_PASSWORD not set — generating a random one. Save it now:');
            $password = bin2hex(random_bytes(8));
            $this->command?->warn("  Password: {$password}");
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => Hash::make($password),
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            RolePermissionSeeder::class,
            SettingSeeder::class,
            LookupTitleSeeder::class,
            LookupCreditTermSeeder::class,
            LookupCreditLimitSeeder::class,
            LookupUnitSeeder::class,
        ]);
    }
}
