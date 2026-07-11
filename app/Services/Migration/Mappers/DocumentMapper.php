<?php

namespace App\Services\Migration\Mappers;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\ReportsExcludedRows;
use App\Services\Migration\Support\LegacyDate;
use App\UserRole;
use App\UserStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentMapper implements BulkEntityMapper, ReportsExcludedRows
{
    private ?int $createdBy = null;

    /** @var array<int, int>|null Maps customers.legacy_uid => customers.id */
    private ?array $customerIdByLegacyUid = null;

    /** @var array<string, array<int, int>>|null Maps a colliding ref => ordered list of legacy Uids sharing it */
    private ?array $refOrdinals = null;

    /** @var array<int, int|null> Maps AppCodes.Valueint (Codetype='Salesman') => users.id, once resolved */
    private array $assignedToUserIdBySalesman = [];

    public function setCreatedBy(int $userId): static
    {
        $this->createdBy = $userId;

        return $this;
    }

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Delivery Notes, Invoices & Credit Notes';
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach ($this->baseQuery()->lazyById($chunkSize, 'Documents.Uid', 'uid') as $row) {
            yield (array) $row;
        }
    }

    public function excludedCount(): int
    {
        return DB::connection('legacy')->table('Documents')
            ->whereIn('Rtype', ['d', 'i', 'r'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('CustSupps')
                    ->where('CustSupps.Rtype', 'A')
                    ->whereColumn('CustSupps.Uid', 'Documents.Acctuid');
            })
            ->count();
    }

    public function excludedLabel(): string
    {
        return 'reference a customer that does not exist anywhere in the legacy data';
    }

    private function baseQuery(): Builder
    {
        return DB::connection('legacy')->table('Documents')
            ->join('CustSupps', function ($join) {
                $join->on('Documents.Acctuid', '=', 'CustSupps.Uid')
                    ->where('CustSupps.Rtype', '=', 'A');
            })
            ->whereIn('Documents.Rtype', ['d', 'i', 'r'])
            ->select('Documents.*');
    }

    /** @return array<string, array<int, int>> ref => [legacy uid => ordinal] */
    private function refOrdinals(): array
    {
        if ($this->refOrdinals !== null) {
            return $this->refOrdinals;
        }

        $rows = $this->baseQuery()
            ->select('Documents.uid', 'Documents.ref')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY Documents.ref ORDER BY Documents.uid) as ref_ordinal')
            ->whereIn('Documents.ref', function ($query) {
                $query->select('Documents.ref')
                    ->from('Documents')
                    ->join('CustSupps', function ($join) {
                        $join->on('Documents.Acctuid', '=', 'CustSupps.Uid')
                            ->where('CustSupps.Rtype', '=', 'A');
                    })
                    ->whereIn('Documents.Rtype', ['d', 'i', 'r'])
                    ->groupBy('Documents.ref')
                    ->havingRaw('COUNT(*) > 1');
            })
            ->get();

        $this->refOrdinals = [];

        foreach ($rows as $row) {
            $this->refOrdinals[trim((string) $row->ref)][(int) $row->uid] = (int) $row->ref_ordinal;
        }

        return $this->refOrdinals;
    }

    /**
     * Documents.Salesman is a free-text picklist (AppCodes, Codetype='Salesman'), not a
     * real user-account reference (confirmed against the legacy app's own source — it
     * only ever joins Salesman to AppCodes, never to AppUsers). Since assigned_to is a
     * hard FK to real login-capable users, and there's no reliable way to auto-match a
     * legacy label to an existing staff member's name, each distinct label gets its own
     * placeholder User (status=Migrated, cannot log in — see UserStatus::canLogIn()),
     * created once and reused by legacy Valueint. Valueint=0 ("- No Name -" in the
     * confirmed live data) means no salesperson was set, so it resolves to null.
     */
    private function resolveAssignedTo(mixed $salesman): ?int
    {
        $value = (int) ($salesman ?? 0);

        if ($value === 0) {
            return null;
        }

        if (array_key_exists($value, $this->assignedToUserIdBySalesman)) {
            return $this->assignedToUserIdBySalesman[$value];
        }

        $label = trim((string) (DB::connection('legacy')->table('AppCodes')
            ->where('codetype', 'Salesman')
            ->where('valueint', $value)
            ->value('description') ?? ''));

        if ($label === '') {
            return $this->assignedToUserIdBySalesman[$value] = null;
        }

        $user = User::firstOrCreate(
            ['email' => 'salesman-'.Str::slug($label).'-'.$value.'@migrated.localhost'],
            [
                'name' => $label,
                'password' => Str::password(32),
                'role' => UserRole::Staff,
                'status' => UserStatus::Migrated,
            ]
        );

        return $this->assignedToUserIdBySalesman[$value] = $user->id;
    }

    public function targetModel(): string
    {
        return Document::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'customer_id', 'type', 'order_no', 'doc_date', 'subtotal', 'total_value',
            'vat_amount', 'discount_amount', 'trade_discount', 'show_pricing', 'print_count',
            'status', 'notes', 'doc_number', 'created_by', 'deleted_at', 'assigned_to',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $type = match (strtolower(trim((string) $legacyRow['rtype']))) {
            'd' => DocumentType::DeliveryNote,
            'i' => DocumentType::Invoice,
            'r' => DocumentType::CreditNote,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        if ($this->customerIdByLegacyUid === null) {
            $this->customerIdByLegacyUid = Customer::withTrashed()
                ->whereNotNull('legacy_uid')
                ->pluck('id', 'legacy_uid')
                ->all();
        }

        $customerId = $this->customerIdByLegacyUid[$legacyRow['acctuid']] ?? null;

        if ($customerId === null) {
            return null;
        }

        $goods = (float) ($legacyRow['goods'] ?? 0);
        $value = (float) ($legacyRow['value'] ?? 0);

        $docNumber = trim((string) ($legacyRow['ref'] ?? ''));

        if ($docNumber === '') {
            return null;
        }

        $ordinal = $this->refOrdinals()[$docNumber][(int) $legacyRow['uid']] ?? null;

        if ($ordinal !== null) {
            $docNumber .= '-'.$ordinal;
        }

        $docDate = LegacyDate::parse($legacyRow['date'] ?? null);

        if ($docDate === null) {
            return null;
        }

        return [
            'legacy_uid' => $legacyRow['uid'],
            'customer_id' => $customerId,
            'type' => $type->value,
            'order_no' => $legacyRow['orderno'] ?? null,
            'doc_date' => $docDate,
            'subtotal' => $goods,
            'total_value' => $value,
            'vat_amount' => max(0, $value - $goods),
            'discount_amount' => 0,
            'trade_discount' => 0,
            'show_pricing' => true,
            'print_count' => 0,
            'status' => DocumentStatus::Active->value,
            'notes' => $legacyRow['notes'] ?? null,
            'doc_number' => $docNumber,
            'created_by' => $this->createdBy,
            'deleted_at' => LegacyDate::parse($legacyRow['deleteddate'] ?? null),
            'assigned_to' => $this->resolveAssignedTo($legacyRow['salesman'] ?? null),
        ];
    }

    /**
     * Required by EntityMapper (which BulkEntityMapper extends) but never called by
     * MigrationRunner for bulk mappers — it always uses transform() + upsert() instead.
     * Kept as a correct, working single-row fallback for interface compliance.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = Document::withTrashed()->where('legacy_uid', $legacyRow['uid'])->first();

        if ($existing && $strategy === DuplicateStrategy::SkipExisting) {
            return MapOutcome::Skipped;
        }

        $attributes = $this->transform($legacyRow);

        if ($attributes === null) {
            return MapOutcome::Skipped;
        }

        if ($existing) {
            $existing->update($attributes);

            return MapOutcome::Updated;
        }

        Document::create($attributes);

        return MapOutcome::Added;
    }
}
