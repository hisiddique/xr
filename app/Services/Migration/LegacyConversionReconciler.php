<?php

namespace App\Services\Migration;

use App\DocumentStatus;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the DN→INV conversion link that DocumentMapper structurally cannot set:
 * `documents.converted_from_id` on the migrated invoice row points at the migrated
 * delivery note row, and that value is a cross-row Laravel id unknown at mapper time.
 * It is deliberately excluded from DocumentMapper::updatableColumns(), so the writes
 * made here survive a re-migration.
 *
 * `invuid` on a delivery-note row is the authoritative link: it points at the linked
 * invoice's legacy uid and is the only conversion column the legacy app actually writes
 * (set when a document is saved from an invoice source). `origdeln` on the invoice row
 * is a cross-check only and may be entirely absent in real data; a disagreement is
 * reported as `signal_mismatches` without changing the outcome.
 *
 * A converted DN is either linked (its `invuid` resolves to a migrated invoice) or
 * orphaned (it does not). Orphaned converted DNs would otherwise ship with status
 * `'converted'` — uneditable and with no conversion banner to explain why — so they are
 * downgraded back to `'active'`.
 *
 * Idempotent: `converted_from_updates` is emitted only where the invoice's current
 * `converted_from_id` differs, the apply step further guards on `whereNull`, and the
 * status updates are no-ops once already in the target state.
 */
class LegacyConversionReconciler
{
    /**
     * @return array{
     *     converted_from_updates: array<int, array{invoice_id: int, dn_id: int}>,
     *     dn_status_updates: array<int, int>,
     *     orphan_downgrades: array<int, int>,
     *     signal_mismatches: int,
     * }
     */
    public function plan(): array
    {
        $localIdByLegacyUid = [];
        foreach (Document::withTrashed()->whereNotNull('legacy_uid')->pluck('id', 'legacy_uid')->all() as $legacyUid => $localId) {
            $localIdByLegacyUid[(int) $legacyUid] = (int) $localId;
        }

        $dnLinks = DB::connection('legacy')->table('Documents')
            ->where('rtype', 'd')
            ->whereNotNull('invuid')
            ->get(['uid', 'invuid']);

        $invOrigdelnMap = [];
        foreach (
            DB::connection('legacy')->table('Documents')
                ->where('rtype', 'i')
                ->whereNotNull('origdeln')
                ->get(['uid', 'origdeln']) as $row
        ) {
            $invOrigdelnMap[(int) $row->uid] = (int) $row->origdeln;
        }

        $candidates = [];
        $linkedDnLocalIds = [];
        $orphanCandidateDnLocalIds = [];
        $signalMismatches = 0;

        foreach ($dnLinks as $row) {
            $dnUid = (int) $row->uid;
            $invUid = (int) $row->invuid;

            $dnLocalId = $localIdByLegacyUid[$dnUid] ?? null;
            if ($dnLocalId === null) {
                continue;
            }

            $invLocalId = $localIdByLegacyUid[$invUid] ?? null;
            if ($invLocalId === null) {
                $orphanCandidateDnLocalIds[] = $dnLocalId;

                continue;
            }

            $candidates[$invLocalId] = $dnLocalId;
            $linkedDnLocalIds[$dnLocalId] = $dnLocalId;

            if (isset($invOrigdelnMap[$invUid]) && $invOrigdelnMap[$invUid] !== $dnUid) {
                $signalMismatches++;
            }
        }

        $currentConvertedFrom = empty($candidates)
            ? []
            : Document::withTrashed()->whereIn('id', array_keys($candidates))->pluck('converted_from_id', 'id')->all();

        $convertedFromUpdates = [];
        foreach ($candidates as $invLocalId => $dnLocalId) {
            if ((int) ($currentConvertedFrom[$invLocalId] ?? 0) !== $dnLocalId) {
                $convertedFromUpdates[] = ['invoice_id' => $invLocalId, 'dn_id' => $dnLocalId];
            }
        }

        $dnStatusUpdates = empty($linkedDnLocalIds)
            ? []
            : Document::withTrashed()
                ->whereIn('id', array_values($linkedDnLocalIds))
                ->where('status', '!=', DocumentStatus::Converted->value)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $orphanIds = array_values(array_diff(array_unique($orphanCandidateDnLocalIds), array_keys($linkedDnLocalIds)));

        $orphanDowngrades = empty($orphanIds)
            ? []
            : Document::withTrashed()
                ->whereIn('id', $orphanIds)
                ->where('status', DocumentStatus::Converted->value)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return [
            'converted_from_updates' => $convertedFromUpdates,
            'dn_status_updates' => $dnStatusUpdates,
            'orphan_downgrades' => $orphanDowngrades,
            'signal_mismatches' => $signalMismatches,
        ];
    }

    /**
     * @param  array{converted_from_updates: array, dn_status_updates: array, orphan_downgrades: array, signal_mismatches: int}  $plan
     */
    public function isEmpty(array $plan): bool
    {
        return empty($plan['converted_from_updates'])
            && empty($plan['dn_status_updates'])
            && empty($plan['orphan_downgrades'])
            && $plan['signal_mismatches'] === 0;
    }

    /**
     * @param  array{converted_from_updates: array<int, array{invoice_id: int, dn_id: int}>, dn_status_updates: array<int, int>, orphan_downgrades: array<int, int>}  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            foreach (array_chunk($plan['converted_from_updates'], 1000) as $chunk) {
                foreach ($chunk as $update) {
                    Document::withTrashed()
                        ->whereKey($update['invoice_id'])
                        ->whereNull('converted_from_id')
                        ->update(['converted_from_id' => $update['dn_id']]);
                }
            }

            foreach (array_chunk($plan['dn_status_updates'], 5000) as $chunk) {
                Document::withTrashed()->whereIn('id', $chunk)->update(['status' => DocumentStatus::Converted->value]);
            }

            foreach (array_chunk($plan['orphan_downgrades'], 5000) as $chunk) {
                Document::withTrashed()->whereIn('id', $chunk)->update(['status' => DocumentStatus::Active->value]);
            }
        });
    }
}
