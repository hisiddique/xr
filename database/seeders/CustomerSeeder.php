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
    public function run(): void
    {
        $admin = User::where('email', 'admin@deliverycrm.test')->first();
        $titleIds = LookupTitle::pluck('id')->toArray();
        $termIds = LookupCreditTerm::pluck('id')->toArray();
        $limitIds = LookupCreditLimit::pluck('id')->toArray();

        $named = [
            ['company_name' => 'Apex Building Supplies Ltd',       'first_name' => 'James',    'last_name' => 'Hargreaves', 'address_1' => '14 Industrial Way',       'address_2' => 'Trafford Park',        'town' => 'Manchester',    'post_code' => 'M17 1PH', 'email_1' => 'james.hargreaves@apexbuilding.co.uk',    'trade_discount' => 10],
            ['company_name' => 'Meridian Catering Equipment',       'first_name' => 'Sandra',   'last_name' => 'Okafor',    'address_1' => '7 Commerce Street',       'address_2' => null,                   'town' => 'Birmingham',    'post_code' => 'B7 4AA',  'email_1' => 'accounts@meridiancatering.co.uk',        'trade_discount' => 5],
            ['company_name' => 'Riverside Packaging Co.',           'first_name' => 'Tom',      'last_name' => 'Fletcher',  'address_1' => 'Unit 3 Riverside Estate', 'address_2' => null,                   'town' => 'Leeds',         'post_code' => 'LS10 2RQ', 'email_1' => 'tom.fletcher@riversidepkg.co.uk',        'trade_discount' => 0],
            ['company_name' => 'Northern Lights Interiors',         'first_name' => 'Claire',   'last_name' => 'Munroe',    'address_1' => '22 Design Quarter',       'address_2' => 'Ouseburn Valley',      'town' => 'Newcastle',     'post_code' => 'NE1 6BH', 'email_1' => 'claire@nlinteriors.co.uk',               'trade_discount' => 15],
            ['company_name' => 'Greenfield Agricultural Supplies',  'first_name' => 'David',    'last_name' => 'Whitmore',  'address_1' => 'Farm Lane Business Park', 'address_2' => null,                   'town' => 'York',          'post_code' => 'YO41 5HJ', 'email_1' => 'orders@greenfieldagri.co.uk',            'trade_discount' => 0],
            ['company_name' => 'Hartley & Sons Plumbing',           'first_name' => 'Michael',  'last_name' => 'Hartley',   'address_1' => '58 Brickworks Road',      'address_2' => null,                   'town' => 'Sheffield',     'post_code' => 'S9 3WX',  'email_1' => 'mike@hartleyplumbing.co.uk',             'trade_discount' => 5],
            ['company_name' => 'Coastal Fresh Produce',             'first_name' => 'Natalie',  'last_name' => 'Perkins',   'address_1' => '1 Harbour Road',          'address_2' => null,                   'town' => 'Brighton',      'post_code' => 'BN2 5TF', 'email_1' => 'natalie.perkins@coastalfresh.co.uk',     'trade_discount' => 0],
            ['company_name' => 'Thornton Medical Supplies',         'first_name' => 'Robert',   'last_name' => 'Thornton',  'address_1' => '9 Park View',             'address_2' => 'Solihull',             'town' => 'Birmingham',    'post_code' => 'B91 3HA', 'email_1' => 'r.thornton@thorntonmedical.co.uk',       'trade_discount' => 20],
            ['company_name' => 'Swift Print & Design',              'first_name' => 'Lucy',     'last_name' => 'Adeyemi',   'address_1' => '44 Print Works Lane',     'address_2' => null,                   'town' => 'London',        'post_code' => 'E1 5DQ',  'email_1' => 'lucy@swiftprintdesign.co.uk',            'trade_discount' => 0],
            ['company_name' => 'Lakeside Hotel Group',              'first_name' => 'Anthony',  'last_name' => 'Bassett',   'address_1' => 'Lake Road',               'address_2' => 'Windermere',           'town' => 'Cumbria',       'post_code' => 'LA23 1BJ', 'email_1' => 'purchasing@lakesidehotels.co.uk',        'trade_discount' => 10],
            ['company_name' => 'Fairview School Trust',             'first_name' => 'Helen',    'last_name' => 'Docherty',  'address_1' => '2 Education Drive',       'address_2' => null,                   'town' => 'Edinburgh',     'post_code' => 'EH9 1PX', 'email_1' => 'h.docherty@fairviewtrust.sch.uk',        'trade_discount' => 0],
            ['company_name' => 'Pinnacle Auto Parts',               'first_name' => 'Gary',     'last_name' => 'Simmons',   'address_1' => 'Unit 12 Autopark',        'address_2' => null,                   'town' => 'Coventry',      'post_code' => 'CV6 4LR', 'email_1' => 'gary.simmons@pinnacleauto.co.uk',        'trade_discount' => 5],
            ['company_name' => 'Blue Horizon Logistics',            'first_name' => 'Priya',    'last_name' => 'Sharma',    'address_1' => '300 Distribution Centre', 'address_2' => null,                   'town' => 'Milton Keynes', 'post_code' => 'MK9 2HN', 'email_1' => 'priya.sharma@bluehorizonlogistics.co.uk', 'trade_discount' => 15],
            ['company_name' => 'Redwood Timber Merchants',          'first_name' => 'Patrick',  'last_name' => 'Walsh',     'address_1' => 'Sawmill Road',            'address_2' => null,                   'town' => 'Bristol',       'post_code' => 'BS5 9TG', 'email_1' => 'orders@redwoodtimber.co.uk',             'trade_discount' => 0],
            ['company_name' => 'Quantum IT Solutions',              'first_name' => 'Zoe',      'last_name' => 'Kavanagh',  'address_1' => '15 Tech Park',            'address_2' => 'Kingsway',             'town' => 'Glasgow',       'post_code' => 'G41 1JE', 'email_1' => 'zoe.kavanagh@quantumit.co.uk',           'trade_discount' => 0],
            ['company_name' => 'Midlands Office Supplies',          'first_name' => 'Brian',    'last_name' => 'Nwosu',     'address_1' => '37 Central Boulevard',    'address_2' => null,                   'town' => 'Nottingham',    'post_code' => 'NG1 5GG', 'email_1' => 'brian.nwosu@midlandsoffice.co.uk',       'trade_discount' => 10],
            ['company_name' => 'Harbour View Restaurants',          'first_name' => 'Fiona',    'last_name' => 'Gallagher', 'address_1' => '6 Pier Street',           'address_2' => null,                   'town' => 'Liverpool',     'post_code' => 'L3 4AF',  'email_1' => 'fiona@harbourviewrestaurants.co.uk',     'trade_discount' => 5],
            ['company_name' => 'Sterling Security Systems',         'first_name' => 'Mark',     'last_name' => 'Jennings',  'address_1' => 'Unit 7 Sovereign Way',    'address_2' => null,                   'town' => 'Reading',       'post_code' => 'RG2 0TD', 'email_1' => 'mark.jennings@sterlingsecurity.co.uk',   'trade_discount' => 0],
            ['company_name' => 'Foxfield Garden Centres',           'first_name' => 'Angela',   'last_name' => 'Booth',     'address_1' => 'Nursery Lane',            'address_2' => null,                   'town' => 'Leicester',     'post_code' => 'LE3 2DQ', 'email_1' => 'angela.booth@foxfieldgardens.co.uk',     'trade_discount' => 0],
            ['company_name' => 'Crown Electrical Contractors',      'first_name' => 'Steven',   'last_name' => 'McAllister', 'address_1' => '88 Watt Street',          'address_2' => null,                   'town' => 'Cardiff',       'post_code' => 'CF24 3NR', 'email_1' => 'steven@crownelectrical.co.uk',           'trade_discount' => 10],
        ];

        foreach ($named as $data) {
            Customer::create(array_merge($data, [
                'reference' => $this->generateReference($data['company_name']),
                'title_id' => fake()->randomElement($titleIds) ?? null,
                'credit_term_id' => fake()->randomElement($termIds) ?? null,
                'credit_limit_id' => fake()->randomElement($limitIds) ?? null,
                'created_by' => $admin?->id,
            ]));
        }

        Customer::factory(10)->create([
            'title_id' => fn () => fake()->randomElement($titleIds) ?? null,
            'credit_term_id' => fn () => fake()->randomElement($termIds) ?? null,
            'credit_limit_id' => fn () => fake()->randomElement($limitIds) ?? null,
            'created_by' => $admin?->id,
        ]);
    }

    private function generateReference(string $companyName): string
    {
        $words = preg_split('/\s+/', $companyName);
        $initials = implode('', array_map(fn ($w) => strtoupper($w[0]), array_slice($words, 0, 3)));

        return $initials.'-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
    }
}
