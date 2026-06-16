<?php

namespace Database\Seeders;

use App\Models\LookupPaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = ['Bank Transfer', 'Cheque', 'Cash', 'Card'];

        foreach ($methods as $method) {
            LookupPaymentMethod::firstOrCreate(['name' => $method]);
        }
    }
}
