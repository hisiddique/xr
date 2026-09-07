<?php

namespace App\Services\Archive;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Overhead;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayout;

/**
 * Declarative map of the tables the archive feature may act on and how they link.
 *
 * Everything is derived from two source arrays so there is a single source of truth:
 *  - self::SELECTABLE_GROUPS — the parent groups a user can tick
 *  - self::FOREIGN_KEYS — the complete inbound foreign-key edge list among feature tables
 *
 * No database access, config reads, or other side effects live here.
 */
class ArchiveTableRegistry
{
    /**
     * Tables the archive feature must never read, copy, or delete.
     *
     * @var list<string>
     */
    public const NEVER_ARCHIVABLE = [
        'settings',
        'lookup_titles',
        'lookup_credit_terms',
        'lookup_credit_limits',
        'lookup_units',
        'lookup_customer_categories',
        'lookup_payment_methods',
        'lookup_revenue_types',
        'expense_categories',
        'roles',
        'role_permission',
        'role_user',
        'users',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
        'password_reset_tokens',
        'export_jobs',
        'migration_runs',
        'migration_run_tables',
        'archive_runs',
        'archive_run_tables',
    ];

    /**
     * Ordered parent groups the user selects. The archive window is always
     * anchored on the row's created_at column.
     *
     * @var array<string, array{label: string, table: string, model: class-string, softDeletes: bool}>
     */
    private const SELECTABLE_GROUPS = [
        'customers' => [
            'label' => 'Customers',
            'table' => 'customers',
            'model' => Customer::class,
            'softDeletes' => true,
        ],
        'documents' => [
            'label' => 'Delivery Notes, Invoices & Credit Notes',
            'table' => 'documents',
            'model' => Document::class,
            'softDeletes' => true,
        ],
        'payments' => [
            'label' => 'Customer Payments',
            'table' => 'payments',
            'model' => Payment::class,
            'softDeletes' => true,
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'table' => 'suppliers',
            'model' => Supplier::class,
            'softDeletes' => true,
        ],
        'supplier_invoices' => [
            'label' => 'Purchase Invoices',
            'table' => 'supplier_invoices',
            'model' => SupplierInvoice::class,
            'softDeletes' => true,
        ],
        'supplier_debit_notes' => [
            'label' => 'Supplier Debit Notes',
            'table' => 'supplier_debit_notes',
            'model' => SupplierDebitNote::class,
            'softDeletes' => true,
        ],
        'supplier_payouts' => [
            'label' => 'Supplier Payouts',
            'table' => 'supplier_payouts',
            'model' => SupplierPayout::class,
            'softDeletes' => true,
        ],
        'overheads' => [
            'label' => 'Overheads',
            'table' => 'overheads',
            'model' => Overhead::class,
            'softDeletes' => true,
        ],
    ];

    /**
     * Complete inbound foreign-key edge list: [child table, child column, parent table].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const FOREIGN_KEYS = [
        ['document_items', 'document_id', 'documents'],
        ['document_email_logs', 'document_id', 'documents'],
        ['payment_allocations', 'payment_id', 'payments'],
        ['payment_allocations', 'document_id', 'documents'],
        ['credit_allocations', 'payment_id', 'payments'],
        ['credit_allocations', 'invoice_id', 'documents'],
        ['credit_allocations', 'credit_note_id', 'documents'],
        ['payment_draws', 'source_payment_id', 'payments'],
        ['payment_draws', 'target_payment_id', 'payments'],
        ['write_offs', 'document_id', 'documents'],
        ['documents', 'customer_id', 'customers'],
        ['documents', 'converted_from_id', 'documents'],
        ['documents', 'credited_invoice_id', 'documents'],
        ['payments', 'customer_id', 'customers'],
        ['supplier_invoices', 'supplier_id', 'suppliers'],
        ['supplier_invoices', 'overhead_id', 'overheads'],
        ['supplier_invoice_items', 'supplier_invoice_id', 'supplier_invoices'],
        ['supplier_debit_notes', 'supplier_id', 'suppliers'],
        ['supplier_debit_notes', 'supplier_invoice_id', 'supplier_invoices'],
        ['supplier_debit_note_items', 'supplier_debit_note_id', 'supplier_debit_notes'],
        ['supplier_debit_note_email_logs', 'supplier_debit_note_id', 'supplier_debit_notes'],
        ['supplier_invoice_debit_notes', 'supplier_invoice_id', 'supplier_invoices'],
        ['supplier_invoice_debit_notes', 'supplier_debit_note_id', 'supplier_debit_notes'],
        ['supplier_payouts', 'supplier_id', 'suppliers'],
        ['supplier_payout_allocations', 'supplier_payout_id', 'supplier_payouts'],
        ['supplier_payout_allocations', 'supplier_invoice_id', 'supplier_invoices'],
        ['supplier_payout_allocations', 'supplier_debit_note_id', 'supplier_debit_notes'],
    ];

    /**
     * The single owning foreign key for each non-selectable child table. A child
     * row is swept into an archive/cleanup set only via this FK; every other FK it
     * holds stays a pure reference (still feeding inboundReferences()).
     *
     * @var array<string, array{column: string, references: string}>
     */
    public const OWNED_BY = [
        'document_items' => ['column' => 'document_id', 'references' => 'documents'],
        'document_email_logs' => ['column' => 'document_id', 'references' => 'documents'],
        'write_offs' => ['column' => 'document_id', 'references' => 'documents'],
        'payment_allocations' => ['column' => 'payment_id', 'references' => 'payments'],
        'credit_allocations' => ['column' => 'payment_id', 'references' => 'payments'],
        'payment_draws' => ['column' => 'source_payment_id', 'references' => 'payments'],
        'supplier_invoice_items' => ['column' => 'supplier_invoice_id', 'references' => 'supplier_invoices'],
        'supplier_debit_note_items' => ['column' => 'supplier_debit_note_id', 'references' => 'supplier_debit_notes'],
        'supplier_debit_note_email_logs' => ['column' => 'supplier_debit_note_id', 'references' => 'supplier_debit_notes'],
        'supplier_payout_allocations' => ['column' => 'supplier_payout_id', 'references' => 'supplier_payouts'],
    ];

    /**
     * True pivot tables with no single owner: pulled only when BOTH endpoints are
     * already in scope.
     *
     * @var array<string, list<array{column: string, references: string}>>
     */
    public const PIVOT_TABLES = [
        'supplier_invoice_debit_notes' => [
            ['column' => 'supplier_invoice_id', 'references' => 'supplier_invoices'],
            ['column' => 'supplier_debit_note_id', 'references' => 'supplier_debit_notes'],
        ],
    ];

    /**
     * Soft-delete capability per feature table. Any table not listed defaults to false.
     *
     * @var array<string, bool>
     */
    private const TABLE_SOFT_DELETES = [
        'customers' => true,
        'documents' => true,
        'payments' => true,
        'suppliers' => true,
        'supplier_invoices' => true,
        'supplier_debit_notes' => true,
        'supplier_payouts' => true,
        'overheads' => true,
        'document_items' => true,
        'document_email_logs' => false,
        'payment_allocations' => true,
        'credit_allocations' => true,
        'payment_draws' => true,
        'write_offs' => true,
        'supplier_invoice_items' => true,
        'supplier_debit_note_items' => true,
        'supplier_debit_note_email_logs' => false,
        'supplier_invoice_debit_notes' => false,
        'supplier_payout_allocations' => true,
    ];

    /**
     * Ordered parent groups the user can select for archiving.
     *
     * @return array<string, array{label: string, table: string, model: class-string, anchor: string, softDeletes: bool}>
     */
    public function selectableGroups(): array
    {
        $groups = [];

        foreach (self::SELECTABLE_GROUPS as $key => $meta) {
            $groups[$key] = [
                'label' => $meta['label'],
                'table' => $meta['table'],
                'model' => $meta['model'],
                'anchor' => 'created_at',
                'softDeletes' => $meta['softDeletes'],
            ];
        }

        return $groups;
    }

    /**
     * The complete foreign-key edge list among feature tables.
     *
     * @return array<int, array{table: string, column: string, references: string, selfReferential: bool, softDeletes: bool}>
     */
    public function foreignKeys(): array
    {
        $edges = [];

        foreach (self::FOREIGN_KEYS as [$table, $column, $references]) {
            $edges[] = [
                'table' => $table,
                'column' => $column,
                'references' => $references,
                'selfReferential' => $table === $references,
                'softDeletes' => $this->softDeletes($table),
            ];
        }

        return $edges;
    }

    /**
     * Every edge pointing at $table (including self-referential ones).
     *
     * @return array<int, array{table: string, column: string}>
     */
    public function inboundReferences(string $table): array
    {
        $references = [];

        foreach ($this->foreignKeys() as $edge) {
            if ($edge['references'] === $table) {
                $references[] = [
                    'table' => $edge['table'],
                    'column' => $edge['column'],
                ];
            }
        }

        return $references;
    }

    /**
     * The OWNED_BY entry for a table, or null when the table declares no owner.
     *
     * @return array{column: string, references: string}|null
     */
    public function ownedBy(string $table): ?array
    {
        return self::OWNED_BY[$table] ?? null;
    }

    /**
     * The PIVOT_TABLES entry for a table, or null when it is not a declared pivot.
     *
     * @return list<array{column: string, references: string}>|null
     */
    public function pivotEndpoints(string $table): ?array
    {
        return self::PIVOT_TABLES[$table] ?? null;
    }

    /**
     * Child tables reachable from $parentTable by following OWNING edges only
     * (plus pivots whose endpoint is reachable), ordered parents-before-children
     * and excluding $parentTable.
     *
     * @return array<int, array{table: string, softDeletes: bool}>
     */
    public function dependentTables(string $parentTable): array
    {
        $reachable = [$parentTable => true];

        do {
            $added = false;

            foreach (self::OWNED_BY as $table => $edge) {
                if (isset($reachable[$table]) || ! isset($reachable[$edge['references']])) {
                    continue;
                }

                $reachable[$table] = true;
                $added = true;
            }
        } while ($added);

        foreach (self::PIVOT_TABLES as $table => $endpoints) {
            if (isset($reachable[$table])) {
                continue;
            }

            foreach ($endpoints as $endpoint) {
                if (isset($reachable[$endpoint['references']])) {
                    $reachable[$table] = true;

                    break;
                }
            }
        }

        unset($reachable[$parentTable]);

        $position = array_flip($this->copyOrder());
        $tables = array_keys($reachable);

        usort($tables, fn (string $a, string $b): int => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX));

        return array_map(
            fn (string $table): array => [
                'table' => $table,
                'softDeletes' => $this->softDeletes($table),
            ],
            $tables,
        );
    }

    /**
     * Every distinct feature table in a safe INSERT order: a table always appears
     * after every table it references.
     *
     * @return list<string>
     */
    public function copyOrder(): array
    {
        return $this->topologicalOrder();
    }

    /**
     * Every distinct feature table in a safe DELETE order (the reverse of copyOrder).
     *
     * @return list<string>
     */
    public function deleteOrder(): array
    {
        return array_reverse($this->copyOrder());
    }

    public function softDeletes(string $table): bool
    {
        return self::TABLE_SOFT_DELETES[$table] ?? false;
    }

    /**
     * Kahn topological sort of every feature table. Self-referential edges are
     * ignored to keep the graph acyclic; ties are broken by declaration order
     * (selectable groups first, then the order tables first appear in the FK list).
     *
     * @return list<string>
     */
    private function topologicalOrder(): array
    {
        $nodes = [];

        foreach (self::SELECTABLE_GROUPS as $meta) {
            $nodes[$meta['table']] = true;
        }

        foreach (self::FOREIGN_KEYS as [$table, , $references]) {
            $nodes[$table] = true;
            $nodes[$references] = true;
        }

        $nodes = array_keys($nodes);

        $distinctEdges = [];

        foreach (self::FOREIGN_KEYS as [$table, , $references]) {
            if ($table === $references) {
                continue;
            }

            $distinctEdges[$table."\0".$references] = [$table, $references];
        }

        $inDegree = array_fill_keys($nodes, 0);
        $children = array_fill_keys($nodes, []);

        foreach ($distinctEdges as [$child, $parent]) {
            $children[$parent][] = $child;
            $inDegree[$child]++;
        }

        $ordered = [];
        $remaining = $nodes;

        while ($remaining !== []) {
            $picked = null;

            foreach ($remaining as $index => $node) {
                if ($inDegree[$node] === 0) {
                    $picked = $node;
                    unset($remaining[$index]);
                    break;
                }
            }

            if ($picked === null) {
                return array_merge($ordered, array_values($remaining));
            }

            $ordered[] = $picked;

            foreach ($children[$picked] as $child) {
                $inDegree[$child]--;
            }
        }

        return $ordered;
    }
}
