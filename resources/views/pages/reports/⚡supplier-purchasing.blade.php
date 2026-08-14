<?php

use App\Models\SupplierInvoice;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supplier Purchasing Report')] class extends Component {

    #[Computed]
    public function outstandingBalance(): float
    {
        return SupplierInvoice::with(['items', 'payoutAllocations', 'debitNotes'])
            ->get()
            ->sum(fn ($inv) => $inv->outstandingAmount);
    }

    #[Computed]
    public function reclaimableVat(): float
    {
        return SupplierInvoice::with('items')
            ->get()
            ->sum(fn ($inv) => $inv->vatTotal);
    }

    #[Computed]
    public function invoiceAuditTrail(): \Illuminate\Database\Eloquent\Collection
    {
        return SupplierInvoice::with(['supplier', 'items'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('supplier_invoice_no')
            ->get();
    }

    #[Computed]
    public function supplierAgingMatrix(): \Illuminate\Support\Collection
    {
        $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
        $endOfMonth   = Carbon::now()->endOfMonth()->toDateString();

        return SupplierInvoice::with(['supplier', 'items', 'payoutAllocations', 'debitNotes'])
            ->whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy('supplier_id')
            ->map(fn ($invoices) => [
                'supplier' => $invoices->first()->supplier,
                'balance'  => $invoices->sum(fn ($inv) => $inv->outstandingAmount),
            ])
            ->sortByDesc('balance')
            ->values();
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Supplier & Purchasing Reports"
        subtitle="Dig down into structural expenses, aging open balances, and transaction pipelines."
    />

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.stat-card
            label="Total Outstanding Ledger Balance"
            :value="'£' . number_format($this->outstandingBalance, 2)"
            icon="banknotes"
            color="violet"
        />
        <x-ui.stat-card
            label="Calculated Reclaimable VAT"
            :value="'£' . number_format($this->reclaimableVat, 2)"
            icon="receipt-percent"
            color="emerald"
        />
    </div>

    {{-- Table 1: Comprehensive Invoice Audit Trail --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Comprehensive Invoice Audit Trail</h2>
        </div>
        @if($this->invoiceAuditTrail->isEmpty())
            <div class="flex flex-col items-center justify-center gap-2 py-10">
                <flux:icon.receipt-percent class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No supplier invoices recorded yet.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Invoice #</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Supplier</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Invoice Date</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Gross Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach($this->invoiceAuditTrail as $invoice)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2">
                                <a href="{{ route('supplier-invoices.show', $invoice) }}" wire:navigate class="font-mono text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                                    {{ $invoice->supplier_invoice_no }}
                                </a>
                            </td>
                            <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                {{ $invoice->supplier?->company_name ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                {{ $invoice->invoice_date->format('d M Y') }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono font-semibold text-zinc-900 dark:text-white">
                                £{{ number_format($invoice->grossTotal, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Table 2: Supplier Accounts Aging — Current Month --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">
                Supplier Accounts Aging — Current Month
                <span class="ml-2 text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ now()->format('F Y') }}</span>
            </h2>
        </div>
        @if($this->supplierAgingMatrix->isEmpty())
            <div class="flex flex-col items-center justify-center gap-2 py-10">
                <flux:icon.building-office class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No invoices issued this month.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Registered Supplier Name</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Current Month Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach($this->supplierAgingMatrix as $row)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                {{ $row['supplier']?->company_name ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono font-semibold text-zinc-900 dark:text-white">
                                £{{ number_format($row['balance'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
