<?php

namespace App\Console\Commands;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('documents:fix-migrated-conversions {--dry-run : Preview the changes without writing them}')]
#[Description('Links previously migrated delivery notes to the invoice they were converted into, by matching DN-/INV- document numbers for the same customer. Does not use the legacy database.')]
class FixMigratedDocumentConversions extends Command
{
    /**
     * @var array<int, array{dn_id: int, dn_doc_number: string, invoice_id: int, invoice_doc_number: string, customer_id: int}>
     */
    private array $changes = [];

    private int $ambiguous = 0;

    /**
     * @var array<int, array{customer_id: int, base_ref: string, delivery_notes: string, invoices: string}>
     */
    private array $ambiguousSamples = [];

    public function handle(): int
    {
        $this->changes = [];
        $this->ambiguous = 0;
        $this->ambiguousSamples = [];

        $this->buildChanges();

        if ($this->changes === [] && $this->ambiguous === 0) {
            $this->info('No migrated conversions to link.');

            return self::SUCCESS;
        }

        if ($this->changes !== []) {
            $this->renderPreview();
        }

        if ($this->ambiguous > 0) {
            $this->warn($this->ambiguous.' ambiguous group(s) skipped — multiple delivery notes or invoices share a reference for the same customer.');
            $this->table(['customer_id', 'base ref', 'delivery notes', 'invoices'], $this->ambiguousSamples);
        }

        if ($this->changes === []) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $this->applyChanges();

        $this->info('Done. '.count($this->changes).' conversion(s) linked.');

        return self::SUCCESS;
    }

    /**
     * Reads migrated DN/INV rows in a lazy cursor rather than ->get() — the documents
     * table can hold millions of migrated rows, so it must never be hydrated whole.
     * Only rows with a legacy_uid are considered; natively-created documents never
     * lost their conversion link. Credit notes are ignored.
     */
    private function buildChanges(): void
    {
        /** @var array<string, array<int, \stdClass>> $dnsByKey */
        $dnsByKey = [];
        /** @var array<string, array<int, \stdClass>> $invByKey */
        $invByKey = [];

        $rows = DB::table('documents')
            ->whereNotNull('legacy_uid')
            ->whereIn('type', [DocumentType::DeliveryNote->value, DocumentType::Invoice->value])
            ->orderBy('id')
            ->lazy();

        foreach ($rows as $row) {
            $key = $row->customer_id.'|'.$this->baseRef($row->doc_number);

            if ($row->type === DocumentType::DeliveryNote->value) {
                $dnsByKey[$key][] = $row;
            } else {
                $invByKey[$key][] = $row;
            }
        }

        foreach ($dnsByKey as $key => $deliveryNotes) {
            if (! isset($invByKey[$key])) {
                continue;
            }

            $invoices = $invByKey[$key];

            if (count($deliveryNotes) !== 1 || count($invoices) !== 1) {
                $this->ambiguous++;

                if (count($this->ambiguousSamples) < 25) {
                    [$customerId, $baseRef] = explode('|', $key, 2);

                    $this->ambiguousSamples[] = [
                        'customer_id' => (int) $customerId,
                        'base_ref' => $baseRef,
                        'delivery_notes' => implode(', ', array_map(fn ($d) => $d->doc_number, $deliveryNotes)),
                        'invoices' => implode(', ', array_map(fn ($i) => $i->doc_number, $invoices)),
                    ];
                }

                continue;
            }

            $deliveryNote = $deliveryNotes[0];
            $invoice = $invoices[0];

            $dnAlreadyConverted = $deliveryNote->status === DocumentStatus::Converted->value;
            $invAlreadyLinked = (int) $invoice->converted_from_id === (int) $deliveryNote->id;

            if ($dnAlreadyConverted && $invAlreadyLinked) {
                continue;
            }

            $this->changes[] = [
                'dn_id' => (int) $deliveryNote->id,
                'dn_doc_number' => $deliveryNote->doc_number,
                'invoice_id' => (int) $invoice->id,
                'invoice_doc_number' => $invoice->doc_number,
                'customer_id' => (int) $deliveryNote->customer_id,
            ];
        }
    }

    private function applyChanges(): void
    {
        DB::transaction(function (): void {
            foreach (array_chunk($this->changes, 500) as $chunk) {
                foreach ($chunk as $change) {
                    Document::whereKey($change['invoice_id'])
                        ->whereNull('converted_from_id')
                        ->update(['converted_from_id' => $change['dn_id']]);

                    Document::whereKey($change['dn_id'])
                        ->where('status', '!=', DocumentStatus::Converted->value)
                        ->update(['status' => DocumentStatus::Converted->value]);
                }
            }
        });
    }

    private function baseRef(string $docNumber): string
    {
        $prefixes = implode('|', array_map(fn (DocumentType $type) => preg_quote($type->value, '/'), DocumentType::cases()));

        $ref = preg_replace('/^('.$prefixes.')-/', '', $docNumber);

        return preg_replace('/-\d+$/', '', $ref);
    }

    private function renderPreview(): void
    {
        $total = count($this->changes);
        $rows = new Collection(array_slice($this->changes, 0, 50));

        $this->table(['dn id', 'dn doc_number', 'invoice id', 'invoice doc_number', 'customer id'], $rows);

        if ($total > $rows->count()) {
            $this->comment('... '.($total - $rows->count()).' more not shown.');
        }

        $this->info($total.' conversion(s) will be linked.');
    }
}
