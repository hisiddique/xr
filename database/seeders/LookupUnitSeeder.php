<?php

namespace Database\Seeders;

use App\Models\LookupUnit;
use Illuminate\Database\Seeder;

class LookupUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = ['each', 'box', 'case', 'pack', 'pair', 'kg', 'g', 'litre', 'ml', 'metre', 'hour', 'day', 'service'];

        foreach ($units as $unit) {
            LookupUnit::firstOrCreate(['name' => $unit]);
        }
    }
}
