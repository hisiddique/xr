<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Supplier;
use App\Models\SupplierPayout;
use App\Traits\WithPerPage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Supplier Details')] class extends Component {
    use WithPagination;
    use WithPerPage;
    use WithSorting;

    protected array $sortable = ['date', 'ref_no', 'type', 'amount'];

    public Supplier $supplier;

    #[Url(as: 'tab')] public string $activeTab = 'transaction-history';

    #[Url(as: 'ledger_search')] public string $ledgerSearch = '';

    public int $perPage = 25;

    #[Computed]
    public function supplierInvoices()
    {
        return $this->supplier
            ->supplierInvoices()
            ->with('items')
            ->orderByDesc('invoice_date')
            ->orderByDesc('supplier_invoice_no')
            ->paginate(10, pageName: 'inv_page');
    }

    #[Computed]
    public function debitNotes()
    {
        return $this->supplier
            ->debitNotes()
            ->with('supplierInvoice')
            ->orderByDesc('doc_date')
            ->orderByDesc('reference')
            ->paginate(10, pageName: 'dn_page');
    }

    #[Computed]
    public function payouts()
    {
        return $this->supplier
            ->payouts()
            ->withSum('allocations', 'allocated_amount')
            ->orderByDesc('payout_date')
            ->orderByDesc('reference')
            ->paginate(10, pageName: 'pay_page');
    }

    #[Computed]
    public function balanceStats(): array
    {
        $invoices = $this->supplier->supplierInvoices()
            ->with(['items', 'payoutAllocations', 'debitNotes'])
            ->get();

        $totalInvoiceAmount = (float) $invoices->sum(fn ($inv) => $inv->grossTotal);
        $totalPaid = (float) $invoices->sum(fn ($inv) => (float) $inv->payoutAllocations->sum('allocated_amount'));
        $outstandingAmount = (float) $invoices->sum(fn ($inv) => $inv->outstandingAmount);
        $invoicesPaidCount = $invoices->filter(fn ($inv) => $inv->outstandingAmount <= 0.001)->count();

        return [
            'balance' => $outstandingAmount,
            'invoices_paid' => $invoicesPaidCount,
            'invoices_outstanding' => $invoices->count() - $invoicesPaidCount,
            'total_invoice_amount' => $totalInvoiceAmount,
            'total_invoice_amount_paid' => $totalPaid,
        ];
    }

    #[Computed]
    public function ledgerInvoicesMap(): array
    {
        return $this->buildSupplierLedgerRows['invoices'];
    }

    #[Computed]
    public function ledgerDebitNotesMap(): array
    {
        return $this->buildSupplierLedgerRows['debit_notes'];
    }

    #[Computed]
    public function ledgerPayoutsMap(): array
    {
        return $this->buildSupplierLedgerRows['payouts'];
    }

    #[Computed]
    public function transactionLedger(): LengthAwarePaginator
    {
        $rows = $this->buildSupplierLedgerRows['rows'];

        if ($this->ledgerSearch !== '') {
            $term = mb_strtolower(trim($this->ledgerSearch));
            $rows = array_values(array_filter($rows, function (array $row) use ($term) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['ref_no'],
                    $row['supplier_ref'],
                    match ($row['type']) {
                        'supplier_invoice' => 'supplier invoice',
                        'debit_note' => 'debit note',
                        'payout' => 'payout',
                    },
                ])));

                return str_contains($haystack, $term);
            }));
        }

        if ($this->sortColumn !== '' && in_array($this->sortColumn, $this->sortableColumns(), true)) {
            $direction = $this->sortDirection === 'desc' ? -1 : 1;
            usort($rows, function (array $a, array $b) use ($direction) {
                return match ($this->sortColumn) {
                    'date' => $a['date'] <=> $b['date'],
                    'ref_no' => strnatcasecmp($a['ref_no'], $b['ref_no']),
                    'type' => strcmp($a['type'], $b['type']),
                    'amount' => $a['amount'] <=> $b['amount'],
                    default => 0,
                } * $direction;
            });
        }

        $page = Paginator::resolveCurrentPage('ledger_page');
        $items = Collection::make($rows);

        return new LengthAwarePaginator(
            $items->forPage($page, $this->perPage)->values(),
            $items->count(),
            $this->perPage,
            $page,
            ['pageName' => 'ledger_page'],
        );
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, invoices: array<int, array<string, mixed>>, debit_notes: array<int, array<string, mixed>>, payouts: array<int, array<string, mixed>>}
     */
    #[Computed]
    public function buildSupplierLedgerRows(): array
    {
        $rows = [];
        $invoiceDetailsById = [];
        $debitNoteDetailsById = [];
        $payoutDetailsById = [];

        $invoices = $this->supplier->supplierInvoices()
            ->with(['items', 'payoutAllocations.supplierPayout', 'payoutAllocations.supplierDebitNote', 'debitNotes'])
            ->get();

        foreach ($invoices as $invoice) {
            $gross = (float) $invoice->grossTotal;
            $paidPayouts = (float) $invoice->payoutAllocations->sum('allocated_amount');
            $paidDebitNotes = (float) $invoice->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount);
            $paid = $paidPayouts + $paidDebitNotes;
            $outstanding = max(0, $gross - $paid);
            $status = match (true) {
                $outstanding <= 0.005 => 'paid',
                $outstanding >= $gross - 0.005 => 'unpaid',
                default => 'partial',
            };

            $allocations = [];

            foreach ($invoice->payoutAllocations as $alloc) {
                $payout = $alloc->supplierPayout;
                if ($payout === null) {
                    continue;
                }

                $allocations[] = [
                    'kind' => 'payout',
                    'ref' => $payout->reference,
                    'label' => 'Payout',
                    'date' => $payout->payout_date,
                    'amount' => (float) $alloc->allocated_amount,
                    'deduction' => (float) $alloc->deduction_amount,
                    'deduction_ref' => $alloc->supplierDebitNote?->reference,
                    'route' => route('supplier-payouts.show', $payout),
                    'payout_id' => $payout->id,
                    'debit_note_id' => null,
                ];
            }

            foreach ($invoice->debitNotes as $dn) {
                $allocations[] = [
                    'kind' => 'debit_note',
                    'ref' => $dn->reference,
                    'label' => 'Debit Note',
                    'date' => $dn->pivot->applied_at ? Carbon::parse($dn->pivot->applied_at) : $dn->doc_date,
                    'amount' => (float) $dn->pivot->applied_amount,
                    'deduction' => 0.0,
                    'deduction_ref' => null,
                    'route' => route('supplier-debit-notes.show', $dn),
                    'payout_id' => null,
                    'debit_note_id' => $dn->id,
                ];
            }

            usort($allocations, fn ($a, $b) => $b['date'] <=> $a['date']);

            $paidDate = $outstanding <= 0.005 && $allocations !== []
                ? collect($allocations)->pluck('date')->max()
                : null;

            $details = [
                'kind' => 'supplier_invoice',
                'total' => $gross,
                'paid' => $paid,
                'outstanding' => $outstanding,
                'paid_date' => $paidDate,
                'allocations' => $allocations,
            ];

            $invoiceDetailsById[$invoice->id] = [
                'ref_no' => $invoice->supplier_invoice_no,
                'date' => $invoice->invoice_date,
                'supplier_ref' => $invoice->supplier_ref_invoice_no,
                'total' => $gross,
                'outstanding' => $outstanding,
                'status' => $status,
            ];

            $rows[] = [
                'date' => $invoice->invoice_date,
                'type' => 'supplier_invoice',
                'ref_no' => $invoice->supplier_invoice_no,
                'supplier_ref' => $invoice->supplier_ref_invoice_no,
                'amount' => $gross,
                'outstanding' => $outstanding,
                'route' => route('supplier-invoices.show', $invoice),
                'status' => $status,
                'details' => $details,
                'id' => $invoice->id,
            ];
        }

        $debitNotes = $this->supplier->debitNotes()
            ->with(['supplierInvoice', 'appliedInvoices'])
            ->get();

        foreach ($debitNotes as $dn) {
            $total = (float) $dn->total;
            $applied = (float) $dn->appliedInvoices->sum(fn ($inv) => (float) $inv->pivot->applied_amount);
            $unapplied = $total - $applied;
            $status = match (true) {
                $applied >= $total - 0.005 => 'applied',
                $applied <= 0.005 => 'unapplied',
                default => 'partial',
            };

            $appliedTo = [];
            foreach ($dn->appliedInvoices as $inv) {
                $appliedTo[] = [
                    'ref' => $inv->supplier_invoice_no,
                    'route' => route('supplier-invoices.show', $inv),
                    'date' => $inv->pivot->applied_at ? Carbon::parse($inv->pivot->applied_at) : $inv->invoice_date,
                    'amount' => (float) $inv->pivot->applied_amount,
                    'supplier_invoice_id' => $inv->id,
                ];
            }

            $details = [
                'kind' => 'debit_note',
                'total' => $total,
                'applied_total' => $applied,
                'outstanding' => $unapplied,
                'linked_invoice' => $dn->supplierInvoice?->supplier_invoice_no,
                'linked_invoice_route' => $dn->supplierInvoice ? route('supplier-invoices.show', $dn->supplierInvoice) : null,
                'linked_invoice_id' => $dn->supplierInvoice?->id,
                'applied_to' => $appliedTo,
            ];

            $debitNoteDetailsById[$dn->id] = [
                'ref_no' => $dn->reference,
                'date' => $dn->doc_date,
                'total' => $total,
                'applied_total' => $applied,
                'outstanding' => $unapplied,
                'status' => $status,
            ];

            $rows[] = [
                'date' => $dn->doc_date,
                'type' => 'debit_note',
                'ref_no' => $dn->reference,
                'supplier_ref' => $dn->supplierInvoice?->supplier_invoice_no,
                'amount' => -$total,
                'outstanding' => -$unapplied,
                'route' => route('supplier-debit-notes.show', $dn),
                'status' => $status,
                'details' => $details,
                'id' => $dn->id,
            ];
        }

        $payouts = $this->supplier->payouts()
            ->with(['allocations.supplierInvoice', 'allocations.supplierDebitNote'])
            ->get();

        foreach ($payouts as $payout) {
            $details = $this->buildPayoutDetails($payout);
            $payoutDetailsById[$payout->id] = $details;

            $rows[] = [
                'date' => $payout->payout_date,
                'type' => 'payout',
                'ref_no' => $payout->reference,
                'supplier_ref' => null,
                'amount' => -(float) $payout->amount,
                'outstanding' => -$details['unallocated'],
                'route' => route('supplier-payouts.show', $payout),
                'status' => $details['status'],
                'details' => $details,
                'id' => $payout->id,
            ];
        }

        $missingPayoutIds = [];
        foreach ($rows as $row) {
            if ($row['type'] !== 'supplier_invoice') {
                continue;
            }

            foreach ($row['details']['allocations'] as $alloc) {
                if ($alloc['kind'] === 'payout' && $alloc['payout_id'] !== null && ! isset($payoutDetailsById[$alloc['payout_id']])) {
                    $missingPayoutIds[] = $alloc['payout_id'];
                }
            }
        }

        if ($missingPayoutIds !== []) {
            $backfilled = SupplierPayout::withTrashed()
                ->with(['allocations.supplierInvoice', 'allocations.supplierDebitNote'])
                ->whereIn('id', array_unique($missingPayoutIds))
                ->get();

            foreach ($backfilled as $payout) {
                $payoutDetailsById[$payout->id] = $this->buildPayoutDetails($payout);
            }
        }

        usort($rows, fn ($a, $b) => $b['date'] <=> $a['date']);

        return [
            'rows' => $rows,
            'invoices' => $invoiceDetailsById,
            'debit_notes' => $debitNoteDetailsById,
            'payouts' => $payoutDetailsById,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayoutDetails(SupplierPayout $payout): array
    {
        $amount = (float) $payout->amount;
        $allocated = (float) $payout->allocations->sum('allocated_amount');
        $unallocated = max(0, $amount - $allocated);
        $status = match (true) {
            $unallocated <= 0.005 && $allocated > 0.005 => 'applied',
            $unallocated >= $amount - 0.005 => 'unapplied',
            default => 'partial',
        };

        $allocations = [];
        foreach ($payout->allocations as $alloc) {
            if ($alloc->supplierInvoice !== null) {
                $target = $alloc->supplierInvoice;
                $allocations[] = [
                    'target_kind' => 'supplier_invoice',
                    'ref' => $target->supplier_invoice_no,
                    'route' => route('supplier-invoices.show', $target),
                    'date' => $target->invoice_date,
                    'amount' => (float) $alloc->allocated_amount,
                    'deduction' => (float) $alloc->deduction_amount,
                    'supplier_invoice_id' => $target->id,
                    'debit_note_id' => null,
                ];
            } elseif ($alloc->supplierDebitNote !== null) {
                $target = $alloc->supplierDebitNote;
                $allocations[] = [
                    'target_kind' => 'debit_note',
                    'ref' => $target->reference,
                    'route' => route('supplier-debit-notes.show', $target),
                    'date' => $target->doc_date,
                    'amount' => (float) $alloc->allocated_amount,
                    'deduction' => (float) $alloc->deduction_amount,
                    'supplier_invoice_id' => null,
                    'debit_note_id' => $target->id,
                ];
            }
        }

        return [
            'kind' => 'payout',
            'total' => $amount,
            'allocated' => $allocated,
            'unallocated' => $unallocated,
            'status' => $status,
            'allocations' => $allocations,
        ];
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage('inv_page');
        $this->resetPage('dn_page');
        $this->resetPage('pay_page');
        $this->resetPage('ledger_page');
        unset($this->supplierInvoices, $this->debitNotes, $this->payouts, $this->transactionLedger);
    }

    public function updatedLedgerSearch(): void
    {
        $this->resetPage('ledger_page');
    }

    public function updatedPerPage(): void
    {
        $this->resetPage('ledger_page');
    }

    // Local override: reset the ledger paginator (not `page`) and start a new column ascending.
    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortColumn !== $column) {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        } elseif ($this->sortDirection === 'asc') {
            $this->sortDirection = 'desc';
        } else {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }

        $this->resetPage('ledger_page');
    }

    #[On('supplier-deleted')]
    public function onDeleted(): void
    {
        $this->redirect(route('suppliers.index'), navigate: true);
    }
}; ?>

<div
    class="flex flex-col gap-6"
    x-data="showPageKeys({
        edit: () => Livewire.navigate('{{ route('suppliers.edit', $supplier) }}'),
        delete: () => $store.hotkeys.openModalWithConfirm('delete-supplier-{{ $supplier->id }}'),
    })"
>

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('suppliers.index')" wire:navigate>Back</flux:button>
        <div class="flex items-center gap-2">
            @can('supplier-edit')
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('suppliers.edit', $supplier)" wire:navigate>
                Edit
                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">e</kbd>
            </flux:button>
            @endcan
            @can('supplier-delete')
            <livewire:pages::suppliers.delete-modal :supplier="$supplier" :key="'delete-'.$supplier->id" />
            @endcan
        </div>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-violet-500 via-purple-500 to-indigo-500"></div>

        {{-- Floating avatar --}}
        <div class="absolute left-6 top-10 ring-4 ring-white dark:ring-zinc-900 rounded-full">
            <x-ui.avatar :name="$supplier->company_name" size="xl" />
        </div>

        <div class="px-4 pb-4 pt-14">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $supplier->company_name }}</h1>
                    @if($supplier->reference)
                        <p class="mt-0.5 font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $supplier->reference }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column details --}}
    <div class="grid gap-4 md:grid-cols-2">

        {{-- Basic Information --}}
        <div>
            <x-ui.section-card title="Basic Information">
                <dl class="space-y-4">
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Reference</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $supplier->reference ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Email</dt>
                        <dd class="text-sm text-right">
                            @if($supplier->email)
                                <a href="mailto:{{ $supplier->email }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $supplier->email }}</a>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">VAT Registered</dt>
                        <dd>
                            @if($supplier->vat_registered)
                                <flux:icon.check-circle class="h-5 w-5 text-emerald-500 dark:text-emerald-400" />
                            @else
                                <flux:icon.x-circle class="h-5 w-5 text-zinc-400 dark:text-zinc-500" />
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.section-card>
        </div>

        {{-- Credit & Trading --}}
        <x-ui.section-card title="Credit & Trading">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Trade Discount</dt>
                    <dd>
                        @if($supplier->trade_discount > 0)
                            <span class="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400">
                                {{ $supplier->trade_discount }}%
                            </span>
                        @else
                            <span class="text-sm text-zinc-400">None</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">VAT Applied</dt>
                    <dd>
                        @if($supplier->vat_applied)
                            <flux:icon.check-circle class="h-5 w-5 text-emerald-500 dark:text-emerald-400" />
                        @else
                            <flux:icon.x-circle class="h-5 w-5 text-zinc-400 dark:text-zinc-500" />
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.section-card>

        {{-- Primary Address --}}
        <div>
            <x-ui.section-card title="Primary Address">
                @php
                    $addressLines = array_filter([
                        $supplier->address_line_1,
                        $supplier->address_line_2,
                        $supplier->town_city,
                        $supplier->post_code,
                    ]);
                @endphp
                @if($addressLines)
                    <address class="not-italic space-y-1 text-sm text-zinc-900 dark:text-white">
                        @foreach($addressLines as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </address>
                @else
                    <span class="text-sm text-zinc-400 dark:text-zinc-500">—</span>
                @endif
            </x-ui.section-card>
        </div>

        {{-- Balance --}}
        <x-ui.section-card title="Balance">
            <div class="flex items-center justify-between gap-4 border-b border-zinc-100 pb-3 dark:border-white/[0.06]">
                <dt class="flex items-center gap-1 text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                    Outstanding Balance
                    <flux:tooltip content="Gross invoice amount minus payouts allocated and debit notes applied, summed across all invoices.">
                        <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                    </flux:tooltip>
                </dt>
                <dd class="text-lg font-bold {{ $this->balanceStats['balance'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                    £{{ number_format($this->balanceStats['balance'], 2) }}
                </dd>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
                    <div class="flex items-center gap-1.5">
                        <flux:icon.check-circle class="size-3.5 text-emerald-500 dark:text-emerald-400" />
                        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">Invoices Paid</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold text-emerald-700 dark:text-emerald-400">{{ $this->balanceStats['invoices_paid'] }}</p>
                </div>
                <div class="rounded-xl bg-amber-50 p-3 dark:bg-amber-500/10">
                    <div class="flex items-center gap-1.5">
                        <flux:icon.clock class="size-3.5 text-amber-500 dark:text-amber-400" />
                        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Outstanding</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold text-amber-700 dark:text-amber-400">{{ $this->balanceStats['invoices_outstanding'] }}</p>
                </div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                    <div class="flex items-center gap-1.5">
                        <flux:icon.receipt-percent class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Invoiced</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold text-zinc-900 dark:text-white">£{{ number_format($this->balanceStats['total_invoice_amount'], 2) }}</p>
                </div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                    <div class="flex items-center gap-1.5">
                        <flux:icon.banknotes class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount Paid</p>
                    </div>
                    <p class="mt-1.5 text-lg font-bold text-zinc-900 dark:text-white">£{{ number_format($this->balanceStats['total_invoice_amount_paid'], 2) }}</p>
                </div>
            </div>
        </x-ui.section-card>
    </div>

    {{-- Tabs --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        {{-- Tab nav --}}
        <div class="flex border-b border-zinc-200/70 px-4 dark:border-white/10">
            <button
                wire:click="$set('activeTab', 'invoices')"
                @class([
                    'flex items-center gap-1.5 border-b-2 px-3 py-3 text-sm font-medium transition-colors',
                    'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' => $activeTab === 'invoices',
                    'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'invoices',
                ])
            >
                <flux:icon.receipt-percent class="h-4 w-4" />
                Invoices
            </button>
            <button
                wire:click="$set('activeTab', 'debit-notes')"
                @class([
                    'flex items-center gap-1.5 border-b-2 px-3 py-3 text-sm font-medium transition-colors',
                    'border-amber-600 text-amber-600 dark:border-amber-400 dark:text-amber-400' => $activeTab === 'debit-notes',
                    'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'debit-notes',
                ])
            >
                <flux:icon.document-minus class="h-4 w-4" />
                Debit Notes
            </button>
            <button
                wire:click="$set('activeTab', 'payouts')"
                @class([
                    'flex items-center gap-1.5 border-b-2 px-3 py-3 text-sm font-medium transition-colors',
                    'border-emerald-600 text-emerald-600 dark:border-emerald-400 dark:text-emerald-400' => $activeTab === 'payouts',
                    'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'payouts',
                ])
            >
                <flux:icon.banknotes class="h-4 w-4" />
                Payouts
            </button>
            <button
                wire:click="$set('activeTab', 'transaction-history')"
                @class([
                    'flex items-center gap-1.5 border-b-2 px-3 py-3 text-sm font-medium transition-colors',
                    'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' => $activeTab === 'transaction-history',
                    'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'transaction-history',
                ])
            >
                <flux:icon.clock class="h-4 w-4" />
                Transaction History
            </button>
        </div>

        {{-- Invoices tab --}}
        @if($activeTab === 'invoices')
            <div class="p-4">
                @if($this->supplierInvoices->isEmpty())
                    <p class="py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">No supplier invoices found.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Invoice No</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Date</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Gross (£)</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Status</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Files</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.03]">
                            @foreach($this->supplierInvoices as $inv)
                                <tr class="group">
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('supplier-invoices.show', $inv) }}" wire:navigate class="font-mono text-indigo-600 hover:underline dark:text-indigo-400">
                                            {{ $inv->supplier_invoice_no }}
                                        </a>@if($inv->supplier_ref_invoice_no) <span class="font-mono text-zinc-400 dark:text-zinc-500">({{ $inv->supplier_ref_invoice_no }})</span>@endif
                                    </td>
                                    <td class="py-2.5 pr-4 text-zinc-600 dark:text-zinc-400">{{ $inv->invoice_date->format('d M Y') }}</td>
                                    <td class="py-2.5 pr-4 font-mono text-zinc-900 dark:text-white">£{{ number_format($inv->grossTotal, 2) }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $inv->status->ringColor() }}">
                                            {{ $inv->status->label() }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 text-right text-zinc-400">
                                        {{ count($inv->attachments ?? []) ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-4">
                        {{ $this->supplierInvoices->links() }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Debit Notes tab --}}
        @if($activeTab === 'debit-notes')
            <div class="p-4">
                @if($this->debitNotes->isEmpty())
                    <p class="py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">No debit notes found.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Reference</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Date</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Invoice Linked</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Total (£)</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.03]">
                            @foreach($this->debitNotes as $note)
                                <tr class="group">
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('supplier-debit-notes.show', $note) }}" wire:navigate class="font-mono text-amber-600 hover:underline dark:text-amber-400">
                                            {{ $note->reference }}
                                        </a>
                                    </td>
                                    <td class="py-2.5 pr-4 text-zinc-600 dark:text-zinc-400">{{ $note->doc_date->format('d M Y') }}</td>
                                    <td class="py-2.5 pr-4 font-mono text-zinc-600 dark:text-zinc-400">
                                        @if($note->supplier_invoice_id && $note->supplierInvoice)
                                            {{ $note->supplierInvoice->supplier_invoice_no }}@if($note->supplierInvoice->supplier_ref_invoice_no) ({{ $note->supplierInvoice->supplier_ref_invoice_no }})@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-4 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format((float) $note->total, 2) }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $note->status->ringColor() }}">
                                            {{ $note->status->label() }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-4">
                        {{ $this->debitNotes->links() }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Payouts tab --}}
        @if($activeTab === 'payouts')
            <div class="p-4">
                @if($this->payouts->isEmpty())
                    <p class="py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">No payouts found.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Reference</th>
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Date</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Amount (£)</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Allocated (£)</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Unallocated (£)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.03]">
                            @foreach($this->payouts as $payout)
                                @php
                                    $allocated = $payout->allocations_sum_allocated_amount ?? 0;
                                    $unallocated = max(0, $payout->amount - $allocated);
                                @endphp
                                <tr class="group">
                                    <td class="py-2.5 pr-4">
                                        <a href="{{ route('supplier-payouts.show', $payout) }}" wire:navigate class="font-mono text-emerald-600 hover:underline dark:text-emerald-400">
                                            {{ $payout->reference }}
                                        </a>
                                    </td>
                                    <td class="py-2.5 pr-4 text-zinc-600 dark:text-zinc-400">{{ $payout->payout_date->format('d M Y') }}</td>
                                    <td class="py-2.5 pr-4 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format($payout->amount, 2) }}</td>
                                    <td class="py-2.5 pr-4 text-right font-mono {{ $allocated >= $payout->amount ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-600 dark:text-zinc-300' }}">£{{ number_format($allocated, 2) }}</td>
                                    <td class="py-2.5 text-right font-mono {{ $unallocated > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">£{{ number_format($unallocated, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-4">
                        {{ $this->payouts->links() }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Transaction History tab --}}
        @if($activeTab === 'transaction-history')
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 p-3 dark:border-white/10">
                <flux:input
                    wire:model.live.debounce.300ms="ledgerSearch"
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by ref, supplier ref or type…')"
                    clearable
                    class="max-w-sm"
                />
                <x-ui.per-page-select />
            </div>

            @if($this->transactionLedger->isEmpty())
                <x-ui.empty-state
                    icon="clock"
                    title="No transactions yet"
                    :description="$ledgerSearch !== '' ? 'Try adjusting your search.' : 'Supplier invoices, debit notes, and payouts will appear here.'"
                />
            @else
                <div
                    x-data="ledgerTable()"
                    x-on:keydown="onKeydown($event)"
                    tabindex="0"
                    class="overflow-x-auto rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30"
                >
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <x-ui.sortable-header column="date" :state="$this->sortStateFor('date')">Date</x-ui.sortable-header>
                                <x-ui.sortable-header column="type" :state="$this->sortStateFor('type')">Type</x-ui.sortable-header>
                                <x-ui.sortable-header column="ref_no" :state="$this->sortStateFor('ref_no')">Ref No.</x-ui.sortable-header>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Supplier Ref</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                <x-ui.sortable-header column="amount" align="right" :state="$this->sortStateFor('amount')">Amount (incl. VAT)</x-ui.sortable-header>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($this->transactionLedger as $row)
                                @php
                                    $rowKey = $row['type'].':'.$row['id'];
                                    $statusClasses = match ($row['status']) {
                                        'paid', 'applied' => 'text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
                                        'partial' => 'text-amber-600 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
                                        'unpaid', 'unapplied' => 'text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20',
                                        default => 'text-zinc-600 bg-zinc-100 border-zinc-200 dark:text-zinc-300 dark:bg-zinc-800 dark:border-zinc-700',
                                    };
                                    $statusLabel = match ($row['status']) {
                                        'paid' => 'Paid',
                                        'partial' => 'Partial',
                                        'unpaid' => 'Unpaid',
                                        'applied' => 'Applied',
                                        'unapplied' => 'Unapplied',
                                        default => 'Open',
                                    };
                                    $rowBgClasses = match ($row['status']) {
                                        'paid', 'applied' => 'bg-emerald-50/40 dark:bg-emerald-500/5',
                                        'partial' => 'bg-amber-50/40 dark:bg-amber-500/5',
                                        'unpaid', 'unapplied' => 'bg-rose-50/20 dark:bg-rose-500/[0.03]',
                                        default => '',
                                    };
                                    $typeLabel = match ($row['type']) {
                                        'payout' => 'Payout',
                                        'debit_note' => 'Debit Note',
                                        default => 'Invoice',
                                    };
                                @endphp
                                <tr
                                    data-row-index="{{ $loop->index }}"
                                    data-row-key="{{ $rowKey }}"
                                    wire:key="ledger-row-{{ $row['type'] }}-{{ $row['id'] }}"
                                    :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': selectedIndex === {{ $loop->index }} }"
                                    x-on:click="if ($event.target.closest('a,button')) return; selectRow({{ $loop->index }}, '{{ $rowKey }}')"
                                    :aria-expanded="(openKey === '{{ $rowKey }}').toString()"
                                    class="cursor-pointer transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5 {{ $rowBgClasses }}"
                                >
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $row['date']->format('d M Y') }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $typeLabel }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <a href="{{ $row['route'] }}" wire:navigate class="font-mono text-xs font-semibold rounded border px-2 py-0.5 {{ $statusClasses }}">
                                            {{ $row['ref_no'] }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $row['supplier_ref'] ?: '—' }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ $row['amount'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $row['amount'] < 0 ? '-' : '+' }}£{{ number_format(abs($row['amount']), 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ abs($row['outstanding']) <= 0.005 ? 'text-emerald-600 dark:text-emerald-400' : ($row['outstanding'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white') }}">
                                        £{{ number_format(abs($row['outstanding']), 2) }}
                                    </td>
                                </tr>
                                <tr wire:key="ledger-detail-{{ $row['type'] }}-{{ $row['id'] }}" x-show="openKey === '{{ $rowKey }}'" x-cloak>
                                    <td colspan="7" class="px-4 pb-3">
                                        <div class="border-l-4 border-indigo-400 dark:border-indigo-500 bg-white dark:bg-zinc-900 rounded-r-md shadow-sm p-4">
                                            <div class="mb-3 flex items-center justify-between gap-2">
                                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Transaction Details</h4>
                                                <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openKey = null" />
                                            </div>
                                            <x-ui.supplier-ledger-detail
                                                :row="$row"
                                                :invoices="$this->ledgerInvoicesMap"
                                                :debit-notes="$this->ledgerDebitNotesMap"
                                                :payouts="$this->ledgerPayoutsMap"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:pagination :paginator="$this->transactionLedger" />
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4 px-4 pb-4">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20">Paid</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Invoice fully settled</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-amber-600 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20">Partial</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Partially settled — expand for breakdown</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20">Unpaid</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Invoice with nothing allocated yet</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20">Applied</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Payout / debit note linked to invoice(s)</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20">Unapplied</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Payout / debit note on account (unallocated)</p>
                    </div>
                </div>
            @endif
        @endif
    </div>

</div>
