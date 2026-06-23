<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = ['Petrol', 'Food', 'Drink', 'Parking Fine', 'Office Supplies', 'Software Subscriptions'];

        foreach ($categories as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }
    }
}
