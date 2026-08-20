<?php

namespace App\Services\Migration;

use App\Models\Document;
use App\Models\WriteOff;
use App\Services\Migration\Support\LegacyDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Migrates legacy write-offs (`AccountEntries.posttype=94`) into `write_offs` rows.
 *
 * Unlike invoices/payments, a write-off's own `AccountEntries.invno` is not a usable
 * reference — legacy stores the free-text label "W'Off"/"W'off" there, not a ref — so
 * the invoice it applies to can only be resolved the same way a payment's is: via
 * `AccountBatchItems` (`cshuid` = this write-off's own `AccountEntries.uid`, `txnref`
 * = the invoice's `Documents.ref`, `paymt` scaled by `AccountPostTypes.entryvalue` the
 * amount actually applied — see `LegacyPaymentReconciler`'s docblock for why).
 *
 * Legacy never recorded a reason for a write-off, so the migrated reason is a fixed,
 * clearly-labelled placeholder rather than a guess at one.
 *
 * Where a write-off's `AccountBatchItems` row is missing, ambiguous, or doesn't
 * resolve to a migrated invoice, this reports the gap rather than guessing.
 */
class LegacyWriteOffReconciler
{
    private const int REPORT_SAMPLE_LIMIT = 100;

    public function __construct(private readonly ?int $createdBy = null) {}

    /**
     * @return array{
     *     write_off_rows: array<int, array{legacy_uid: int, document_id: int, amount: float, reason: string, written_off_at: string, written_off_by: ?int, created_at: Carbon, updated_at: Carbon}>,
     *     ambiguous_refs: array<string, int>,
     *     unresolved_write_offs: array{count: int, sample: array<int, array{legacy_uid: int, amount: float, reason: string}>},
     * }
     */
    public function plan(): array
    {
        $documentsByRef = DB::connection('legacy')->table('Documents')
            ->where('rtype', 'i')
            ->select(['uid', 'ref'])
            ->get()
            ->groupBy(fn ($row) => trim((string) $row->ref));

        $invoiceLocalIdByLegacyUid = Document::query()->invoices()->whereNotNull('legacy_uid')->pluck('id', 'legacy_uid')->all();
        $entryvalueByPosttype = DB::connection('legacy')->table('AccountPostTypes')->pluck('entryvalue', 'uid')->all();

        // A write-off entry can split across more than one invoice (mirrors payments),
        // so idempotency is keyed per (legacy_uid, document_id) pair, not legacy_uid alone.
        $alreadyMigrated = WriteOff::query()->whereNotNull('legacy_uid')->get(['legacy_uid', 'document_id']);
        $alreadyMigratedPairs = $alreadyMigrated->map(fn ($w) => $w->legacy_uid.'-'.$w->document_id)->all();
        $alreadyMigratedLegacyUids = $alreadyMigrated->pluck('legacy_uid')->all();

        $writeOffEntries = DB::connection('legacy')->table('AccountEntries')
            ->where('rtype', 'a')
            ->where('posttype', 94)
            ->select(['uid', 'txndate'])
            ->get()
            ->keyBy('uid');

        $batchItems = DB::connection('legacy')->table('AccountBatchItems')
            ->whereIn('cshuid', $writeOffEntries->keys())
            ->select(['cshuid', 'txnabbr', 'txnref', 'txndetails', 'paymt', 'posttype'])
            ->get();

        $writeOffRows = [];
        $ambiguousRefs = [];
        $unresolved = ['count' => 0, 'sample' => []];
        $resolvedLegacyUids = [];

        foreach ($batchItems as $item) {
            // CRN- rows are credit-note postings, not write-offs applying to an invoice;
            // every other tag is let through since txnref resolution below is what
            // actually decides if a row applies to a migrated invoice.
            if (trim((string) $item->txnabbr) === 'CRN-') {
                continue;
            }

            $entry = $writeOffEntries->get($item->cshuid);

            if ($entry === null) {
                continue;
            }

            // Single-invoice write-offs carry the invoice ref directly in txnref. Bulk
            // batch-labeled entries put a batch label in txnref instead and move the
            // real invoice ref into txndetails — see LegacyPaymentReconciler's docblock.
            $ref = trim((string) $item->txnref);
            $matches = $documentsByRef->get($ref);

            if ($matches === null || $matches->count() !== 1) {
                $details = trim((string) $item->txndetails);
                $detailsMatches = $details !== '' ? $documentsByRef->get($details) : null;

                if ($detailsMatches !== null && $detailsMatches->count() === 1) {
                    $ref = $details;
                    $matches = $detailsMatches;
                } else {
                    $ambiguousRefs[$ref] = ($ambiguousRefs[$ref] ?? 0) + ($matches?->count() ?? 0);

                    continue;
                }
            }

            $documentId = $invoiceLocalIdByLegacyUid[$matches->first()->uid] ?? null;

            if ($documentId === null) {
                continue;
            }

            if (in_array($item->cshuid.'-'.$documentId, $alreadyMigratedPairs, true)) {
                $resolvedLegacyUids[$item->cshuid] = true;

                continue;
            }

            $multiplier = 1.0;
            if ($item->posttype !== null && (float) ($entryvalueByPosttype[$item->posttype] ?? 0) !== 0.0) {
                $multiplier = (float) $entryvalueByPosttype[$item->posttype];
            }

            $amount = round((float) $item->paymt * $multiplier, 2);

            if ($amount <= 0) {
                continue;
            }

            $writtenOffAt = LegacyDate::parse($entry->txndate);

            if ($writtenOffAt === null) {
                continue;
            }

            // Two AccountBatchItems rows can carry the same (cshuid, invoice) pairing
            // (e.g. two lines posted in the same batch) — the target table's unique
            // constraint means those must collapse into one summed row.
            $key = $item->cshuid.'-'.$documentId;

            if (isset($writeOffRows[$key])) {
                $writeOffRows[$key]['amount'] = round($writeOffRows[$key]['amount'] + $amount, 2);
            } else {
                $writeOffRows[$key] = [
                    'legacy_uid' => $item->cshuid,
                    'document_id' => $documentId,
                    'amount' => $amount,
                    'reason' => 'Migrated from legacy — no reason was recorded there.',
                    'written_off_at' => $writtenOffAt,
                    'written_off_by' => $this->createdBy,
                    'created_at' => $writtenOffAt,
                    'updated_at' => $writtenOffAt,
                ];
            }

            $resolvedLegacyUids[$item->cshuid] = true;
        }

        $writeOffRows = array_values($writeOffRows);

        foreach ($writeOffEntries as $legacyUid => $entry) {
            if (isset($resolvedLegacyUids[$legacyUid]) || in_array($legacyUid, $alreadyMigratedLegacyUids, true)) {
                continue;
            }

            $unresolved['count']++;
            if (count($unresolved['sample']) < self::REPORT_SAMPLE_LIMIT) {
                $unresolved['sample'][] = ['legacy_uid' => $legacyUid];
            }
        }

        return [
            'write_off_rows' => $writeOffRows,
            'ambiguous_refs' => $ambiguousRefs,
            'unresolved_write_offs' => $unresolved,
        ];
    }

    public function isEmpty(array $plan): bool
    {
        return empty($plan['write_off_rows']) && empty($plan['ambiguous_refs']);
    }

    /**
     * @param  array{write_off_rows: array}  $plan
     */
    public function apply(array $plan): void
    {
        foreach (array_chunk($plan['write_off_rows'], 1000) as $chunk) {
            WriteOff::insert($chunk);
        }
    }
}
