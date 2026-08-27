<?php

use App\Models\Supplier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Supplier Details')] class extends Component {
    use WithPagination;

    public Supplier $supplier;

    #[Url(as: 'tab')] public string $activeTab = 'invoices';

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
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('suppliers.edit', $supplier)" wire:navigate>
                Edit
                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">e</kbd>
            </flux:button>
            <livewire:pages::suppliers.delete-modal :supplier="$supplier" :key="'delete-'.$supplier->id" />
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
    </div>

</div>
