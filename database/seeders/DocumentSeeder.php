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
    public function run(): void
    {
        $admin = User::where('email', 'admin@deliverycrm.test')->first();
        $staff = User::where('email', 'staff@deliverycrm.test')->first();
        $customers = Customer::all();
        $generator = new DocumentNumberGenerator;
        $users = collect([$admin, $staff])->filter();

        Document::factory(200)
            ->deliveryNote()
            ->make()
            ->each(function (Document $document) use ($customers, $users, $generator): void {
                $user = $users->random();
                $document->customer_id = $customers->random()->id;
                $document->created_by = $user?->id;
                $document->assigned_to = $user?->id;
                $document->doc_number = $generator->nextFor('DN');
                $document->save();

                DocumentItem::factory(fake()->numberBetween(2, 6))
                    ->create(['document_id' => $document->id]);
            });

        $invoices = collect();

        Document::factory(80)
            ->invoice()
            ->make()
            ->each(function (Document $document) use ($customers, $users, $generator, &$invoices): void {
                $user = $users->random();
                $document->customer_id = $customers->random()->id;
                $document->created_by = $user?->id;
                $document->assigned_to = $user?->id;
                $document->doc_number = $generator->nextFor('INV');
                $document->save();

                DocumentItem::factory(fake()->numberBetween(2, 6))
                    ->create(['document_id' => $document->id]);

                $invoices->push($document);
            });

        $creditNoteData = [
            [
                'credited_invoice_id' => $invoices->first()?->id,
                'reason' => 'Returned goods',
            ],
            [
                'credited_invoice_id' => null,
                'reason' => 'Goodwill credit',
            ],
        ];

        foreach ($creditNoteData as $extra) {
            $document = Document::factory()->creditNote()->make();
            $user = $users->random();
            $document->customer_id = $customers->random()->id;
            $document->created_by = $user?->id;
            $document->assigned_to = $user?->id;
            $document->doc_number = $generator->nextFor('CN');
            $document->credited_invoice_id = $extra['credited_invoice_id'];
            $document->reason = $extra['reason'];
            $document->save();

            DocumentItem::factory(fake()->numberBetween(2, 4))
                ->create(['document_id' => $document->id]);
        }
    }
}
