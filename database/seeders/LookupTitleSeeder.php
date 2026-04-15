<?php

namespace Database\Seeders;

use App\Models\LookupTitle;
use Illuminate\Database\Seeder;

class LookupTitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $titles = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'];

        foreach ($titles as $title) {
            LookupTitle::firstOrCreate(['name' => $title]);
        }
    }
}
