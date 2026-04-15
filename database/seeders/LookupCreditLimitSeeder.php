<?php

namespace Database\Seeders;

use App\Models\LookupCreditLimit;
use Illuminate\Database\Seeder;

class LookupCreditLimitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $limits = [500, 1000, 2500, 5000, 10000, 25000, 50000];

        foreach ($limits as $limit) {
            LookupCreditLimit::firstOrCreate(['amount' => $limit]);
        }
    }
}
