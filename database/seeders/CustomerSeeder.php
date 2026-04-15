<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Models\LookupTitle;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@deliverycrm.test')->first();
        $titleIds = LookupTitle::pluck('id')->toArray();
        $termIds = LookupCreditTerm::pluck('id')->toArray();
        $limitIds = LookupCreditLimit::pluck('id')->toArray();

        Customer::factory(5)->create([
            'title_id' => fn () => fake()->randomElement($titleIds) ?? null,
            'credit_term_id' => fn () => fake()->randomElement($termIds) ?? null,
            'credit_limit_id' => fn () => fake()->randomElement($limitIds) ?? null,
            'created_by' => $admin?->id,
        ]);
    }
}
