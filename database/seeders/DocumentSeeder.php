<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use Illuminate\Database\Seeder;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@deliverycrm.test')->first();
        $customers = Customer::all();
        $generator = new DocumentNumberGenerator();

        Document::factory(10)
            ->deliveryNote()
            ->make([
                'created_by' => $admin?->id,
            ])
            ->each(function (Document $document) use ($customers, $admin, $generator): void {
                $document->customer_id = $customers->random()->id;
                $document->created_by = $admin?->id;
                $document->doc_number = $generator->nextFor('DN');
                $document->save();

                DocumentItem::factory(fake()->numberBetween(2, 5))
                    ->create(['document_id' => $document->id]);
            });
    }
}
