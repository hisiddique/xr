<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'vat_rate', 'value' => '20', 'type' => 'float'],
            ['key' => 'company_name', 'value' => 'DeliveryCRM Ltd', 'type' => 'string'],
            ['key' => 'company_address', 'value' => '123 Business Road, London, EC1A 1BB', 'type' => 'string'],
            ['key' => 'company_email', 'value' => 'info@deliverycrm.test', 'type' => 'string'],
            ['key' => 'dn_prefix', 'value' => 'DN', 'type' => 'string'],
            ['key' => 'inv_prefix', 'value' => 'INV', 'type' => 'string'],
            ['key' => 'cn_prefix', 'value' => 'CN', 'type' => 'string'],
            ['key' => 'sup_prefix', 'value' => 'SUP', 'type' => 'string'],
            ['key' => 'supinv_prefix', 'value' => 'SUPINV', 'type' => 'string'],
            ['key' => 'number_padding', 'value' => '4', 'type' => 'integer'],
            ['key' => 'dn_start_number', 'value' => '1', 'type' => 'integer'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
