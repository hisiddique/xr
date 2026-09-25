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
 * This legacy install's `Documents` schema has no `invuid`/`origdeln` columns
 * (confirmed via a schema query and a migration run's SQL error — "Invalid column
 * name 'invuid'"). Instead, a `rtype='i'` row is matched to a `rtype='d'` row by
 * identical `ref`: empirically, a converted invoice's `ref` is identical to its
 * source DN's `ref` (verified against production-representative legacy data, 99.6%
 * of invoices). A `ref` that repeats within either the DN group or the invoice
 * group is ambiguous and is skipped rather than guessed, counted in `ambiguous_refs`.
 *
 * A converted DN is either linked (its `ref` resolves to a migrated invoice) or
 * orphaned (it does not). Orphaned converted DNs would otherwise ship with status
 * `'converted'` — uneditable and with no conversion banner to explain why — so they are
 * downgraded back to `'active'`.
 *
 * Idempotent: `converted_from_updates` is emitted only where the invoice's current
 * `converted_from_id` differs, the apply step further guards on `whereNull`, and the
 * status updates are no-ops once already in the target state.
 *
 * `plan()` fetches every migrated document's id/converted_from_id/status once up
 * front and does all lookups against that map, the same way LegacyPaymentReconciler
 * does — not a `whereIn('id', $candidateIds)` per candidate list, which would need
 * one placeholder per row and can exceed MySQL's 65535-per-statement cap on a full
 * production dataset.
 */
class LegacyConversionReconciler
{
    /**
     * @return array{
     *     converted_from_updates: array<int, array{invoice_id: int, dn_id: int}>,
     *     dn_status_updates: array<int, int>,
     *     orphan_downgrades: array<int, int>,
     *     ambiguous_refs: int,
     * }
     */
    public function plan(): array
    {
        $localIdByLegacyUid = [];
        $convertedFromByLocalId = [];
        $statusByLocalId = [];

        foreach (Document::withTrashed()->whereNotNull('legacy_uid')->get(['id', 'legacy_uid', 'converted_from_id', 'status']) as $doc) {
            $localIdByLegacyUid[(int) $doc->legacy_uid] = $doc->id;
            $convertedFromByLocalId[$doc->id] = $doc->converted_from_id;
            $statusByLocalId[$doc->id] = $doc->status;
        }

        $dnRefGroups = [];
        foreach (DB::connection('legacy')->table('Documents')->where('rtype', 'd')->get(['uid', 'ref']) as $row) {
            $dnRefGroups[trim((string) $row->ref)][] = (int) $row->uid;
        }

        $invRefGroups = [];
        foreach (DB::connection('legacy')->table('Documents')->where('rtype', 'i')->get(['uid', 'ref']) as $row) {
            $invRefGroups[trim((string) $row->ref)][] = (int) $row->uid;
        }

        $candidates = [];
        $linkedDnLocalIds = [];
        $orphanCandidateDnLocalIds = [];
        $ambiguousRefs = 0;

        foreach ($invRefGroups as $ref => $invUids) {
            $dnUids = $dnRefGroups[$ref] ?? null;
            if ($dnUids === null) {
                continue;
            }

            if (count($invUids) > 1 || count($dnUids) > 1) {
                $ambiguousRefs++;

                continue;
            }

            $dnUid = $dnUids[0];
            $invUid = $invUids[0];

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
        }

        $convertedFromUpdates = [];
        foreach ($candidates as $invLocalId => $dnLocalId) {
            if ((int) ($convertedFromByLocalId[$invLocalId] ?? 0) !== $dnLocalId) {
                $convertedFromUpdates[] = ['invoice_id' => $invLocalId, 'dn_id' => $dnLocalId];
            }
        }

        $dnStatusUpdates = [];
        foreach (array_values($linkedDnLocalIds) as $dnLocalId) {
            if (($statusByLocalId[$dnLocalId] ?? null) !== DocumentStatus::Converted) {
                $dnStatusUpdates[] = $dnLocalId;
            }
        }

        $orphanIds = array_values(array_diff(array_unique($orphanCandidateDnLocalIds), array_keys($linkedDnLocalIds)));

        $orphanDowngrades = [];
        foreach ($orphanIds as $dnLocalId) {
            if (($statusByLocalId[$dnLocalId] ?? null) === DocumentStatus::Converted) {
                $orphanDowngrades[] = $dnLocalId;
            }
        }

        return [
            'converted_from_updates' => $convertedFromUpdates,
            'dn_status_updates' => $dnStatusUpdates,
            'orphan_downgrades' => $orphanDowngrades,
            'ambiguous_refs' => $ambiguousRefs,
        ];
    }

    /**
     * @param  array{converted_from_updates: array, dn_status_updates: array, orphan_downgrades: array, ambiguous_refs: int}  $plan
     */
    public function isEmpty(array $plan): bool
    {
        return empty($plan['converted_from_updates'])
            && empty($plan['dn_status_updates'])
            && empty($plan['orphan_downgrades'])
            && $plan['ambiguous_refs'] === 0;
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
