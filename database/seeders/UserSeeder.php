<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            ['name' => 'Admin User',       'email' => 'admin@deliverycrm.test', 'role' => 'admin'],
            ['name' => 'Sarah Mitchell',   'email' => 'sarah.mitchell@deliverycrm.test', 'role' => 'staff'],
            ['name' => 'James Okafor',     'email' => 'james.okafor@deliverycrm.test',   'role' => 'staff'],
            ['name' => 'Claire Watts',     'email' => 'claire.watts@deliverycrm.test',   'role' => 'staff'],
            ['name' => 'Tom Hargreaves',   'email' => 'tom.hargreaves@deliverycrm.test', 'role' => 'staff'],
            ['name' => 'Staff User',       'email' => 'staff@deliverycrm.test',          'role' => 'staff'],
        ];

        foreach ($users as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'role' => $data['role'],
                    'password' => $password,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
