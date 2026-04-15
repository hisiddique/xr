<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@deliverycrm.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        User::factory()->staff()->create([
            'name' => 'Staff User',
            'email' => 'staff@deliverycrm.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->call([
            SettingSeeder::class,
            LookupTitleSeeder::class,
            LookupCreditTermSeeder::class,
            LookupCreditLimitSeeder::class,
            LookupUnitSeeder::class,
            CustomerSeeder::class,
            DocumentSeeder::class,
        ]);
    }
}
