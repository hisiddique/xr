<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            RolePermissionSeeder::class,
            SettingSeeder::class,
            LookupTitleSeeder::class,
            LookupCreditTermSeeder::class,
            LookupCreditLimitSeeder::class,
            LookupUnitSeeder::class,
            ExpenseCategorySeeder::class,
            PaymentMethodSeeder::class,
            CustomerSeeder::class,
            DocumentSeeder::class,
        ]);
    }
}
