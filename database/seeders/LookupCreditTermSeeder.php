<?php

namespace Database\Seeders;

use App\Models\LookupCreditTerm;
use Illuminate\Database\Seeder;

class LookupCreditTermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $terms = ['Net 7 days', 'Net 14 days', 'Net 30 days', 'Net 60 days', 'Cash on Delivery'];

        foreach ($terms as $term) {
            LookupCreditTerm::firstOrCreate(['name' => $term]);
        }
    }
}
