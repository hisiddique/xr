<?php

namespace App\Console\Commands;

use App\DocumentType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('documents:fix-migrated-numbers {--dry-run : Preview the changes without writing them}')]
#[Description('Repairs doc_number for previously migrated documents: adds the correct DN-/INV-/CN- prefix and only keeps a numeric suffix where two documents of the same type genuinely share a legacy reference.')]
class FixMigratedDocumentNumbers extends Command
{
    public function handle(): int
    {
        // MySQL/MariaDB (production) does the whole repair set-based via REGEXP_REPLACE +
        // ROW_NUMBER() OVER, so it scales to millions of rows without hydrating PHP models.
        // SQLite (local/test) lacks REGEXP_REPLACE and multi-table UPDATE...JOIN, so it
        // falls back to a plain PHP pass — fine there since that dataset is always tiny.
        return DB::connection()->getDriverName() === 'mysql'
            ? $this->runSetBased()
            : $this->runPhpFallback();
    }

    private function runSetBased(): int
    {
        $calcSql = $this->calcSql();

        $diff = fn () => DB::table(DB::raw("({$calcSql}) as calc"))
            ->join('documents', 'documents.id', '=', 'calc.id')
            ->where(function ($query) {
                $query->whereNull('documents.doc_number')
                    ->orWhereColumn('documents.doc_number', '<>', 'calc.new_doc_number');
            });

        $total = (clone $diff())->count();

        if ($total === 0) {
            $this->info('All migrated document numbers are already correct.');

            return self::SUCCESS;
        }

        $sample = (clone $diff())
            ->orderBy('documents.legacy_uid')
            ->limit(50)
            ->get(['documents.id', 'documents.legacy_uid', 'documents.doc_number as old_doc_number', 'calc.new_doc_number']);

        $this->renderPreview($sample->map(fn ($row) => (array) $row), $total);

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $updated = DB::update("
            UPDATE documents
            JOIN ({$calcSql}) as calc ON calc.id = documents.id
            SET documents.doc_number = calc.new_doc_number
            WHERE documents.doc_number IS NULL OR documents.doc_number <> calc.new_doc_number
        ");

        $this->info("Done. {$updated} row(s) updated.");

        return self::SUCCESS;
    }

    /**
     * Set-based recalculation of doc_number, run entirely in MySQL/MariaDB via window
     * functions rather than hydrating rows in PHP — this table can hold millions of
     * migrated documents, and REGEXP_REPLACE/ROW_NUMBER() OVER let the database do the
     * grouping and ordinal assignment in one pass instead of a PHP grouping map.
     */
    private function calcSql(): string
    {
        $prefixes = implode('|', array_map(fn (DocumentType $type) => $type->value, DocumentType::cases()));

        $baseRef = "REGEXP_REPLACE(REGEXP_REPLACE(doc_number, '^($prefixes)-', ''), '-[0-9]+$', '')";

        return "
            SELECT id, CONCAT(
                type, '-', base_ref,
                IF(cnt > 1, CONCAT('-', ROW_NUMBER() OVER (PARTITION BY type, base_ref ORDER BY legacy_uid)), '')
            ) AS new_doc_number
            FROM (
                SELECT id, legacy_uid, type, {$baseRef} AS base_ref,
                       COUNT(*) OVER (PARTITION BY type, {$baseRef}) AS cnt
                FROM documents
                WHERE legacy_uid IS NOT NULL
            ) AS base
        ";
    }

    private function runPhpFallback(): int
    {
        $documents = DB::table('documents')
            ->whereNotNull('legacy_uid')
            ->orderBy('legacy_uid')
            ->get(['id', 'legacy_uid', 'type', 'doc_number']);

        if ($documents->isEmpty()) {
            $this->info('No migrated documents found.');

            return self::SUCCESS;
        }

        $groups = $documents->groupBy(fn ($document) => $document->type.'|'.$this->baseRef($document->doc_number));

        $changes = [];

        foreach ($groups as $group) {
            $ordered = $group->values();
            $suffixNeeded = $ordered->count() > 1;

            foreach ($ordered as $index => $document) {
                $newDocNumber = $document->type.'-'.$this->baseRef($document->doc_number);

                if ($suffixNeeded) {
                    $newDocNumber .= '-'.($index + 1);
                }

                if ($newDocNumber !== $document->doc_number) {
                    $changes[] = [
                        'id' => $document->id,
                        'legacy_uid' => $document->legacy_uid,
                        'old_doc_number' => $document->doc_number,
                        'new_doc_number' => $newDocNumber,
                    ];
                }
            }
        }

        if (empty($changes)) {
            $this->info('All migrated document numbers are already correct.');

            return self::SUCCESS;
        }

        $this->renderPreview(collect($changes), count($changes));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            DB::table('documents')->where('id', $change['id'])->update(['doc_number' => $change['new_doc_number']]);
        }

        $this->info(count($changes).' row(s) updated.');

        return self::SUCCESS;
    }

    private function baseRef(string $docNumber): string
    {
        $prefixes = implode('|', array_map(fn (DocumentType $type) => preg_quote($type->value, '/'), DocumentType::cases()));

        $ref = preg_replace('/^('.$prefixes.')-/', '', $docNumber);

        return preg_replace('/-\d+$/', '', $ref);
    }

    private function renderPreview(Collection $rows, int $total): void
    {
        $this->table(['id', 'legacy_uid', 'old doc_number', 'new doc_number'], $rows);

        if ($total > $rows->count()) {
            $this->comment('... '.($total - $rows->count()).' more not shown.');
        }

        $this->info($total.' document(s) will be updated.');
    }
}
