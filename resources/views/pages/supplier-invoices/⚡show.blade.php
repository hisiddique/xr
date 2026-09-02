<?php

use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supplier Invoice')] class extends Component {
    public SupplierInvoice $supplierInvoice;

    public function mount(): void
    {
        $this->supplierInvoice->load(['supplier', 'items', 'creator', 'debitNotes', 'payoutAllocations']);
    }

    #[On('supplier-invoice-deleted')]
    public function onDeleted(): void
    {
        $this->redirect(route('supplier-invoices.index'), navigate: true);
    }
}; ?>

<div
    class="flex flex-col gap-6"
    x-data="showPageKeys({
        edit: () => Livewire.navigate('{{ route('supplier-invoices.edit', $supplierInvoice) }}'),
        delete: () => $store.hotkeys.openModalWithConfirm('delete-supplier-invoice-{{ $supplierInvoice->id }}'),
    })"
>

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('supplier-invoices.index')" wire:navigate>Back</flux:button>
        <div class="flex items-center gap-2">
            @can('supplierinvoice-edit')
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('supplier-invoices.edit', $supplierInvoice)" wire:navigate>
                Edit
                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">e</kbd>
            </flux:button>
            @endcan
            @can('supplierinvoice-delete')
            <flux:button
                size="sm"
                variant="ghost"
                icon="trash"
                x-on:click="$flux.modal('delete-supplier-invoice-{{ $supplierInvoice->id }}').show()"
                class="text-red-500 hover:text-red-700"
            >
                Delete
            </flux:button>
            @endcan
        </div>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-violet-500 via-purple-500 to-indigo-500"></div>

        {{-- Floating icon badge --}}
        <div class="absolute left-6 top-10 flex h-16 w-16 items-center justify-center rounded-full bg-white ring-4 ring-white dark:bg-zinc-900 dark:ring-zinc-900">
            <flux:icon.receipt-percent class="h-8 w-8 text-violet-600 dark:text-violet-400" />
        </div>

        <div class="px-4 pb-4 pt-14">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $supplierInvoice->supplier_invoice_no }}</h1>
                        <x-ui.payment-status-badge :status="$supplierInvoice->paymentStatus()" />
                    </div>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $supplierInvoice->supplier->company_name }} &middot; {{ $supplierInvoice->invoice_date->format('d M Y') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column details grid --}}
    <div class="grid gap-4 md:grid-cols-2">

        {{-- Invoice Details --}}
        <x-ui.section-card title="Invoice Details">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Supplier</dt>
                    <dd class="text-sm text-right">
                        <a href="{{ route('suppliers.show', $supplierInvoice->supplier) }}" wire:navigate class="text-violet-600 hover:underline dark:text-violet-400">
                            {{ $supplierInvoice->supplier->company_name }}
                        </a>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Invoice No</dt>
                    <dd class="text-sm font-mono text-zinc-900 dark:text-white text-right">{{ $supplierInvoice->supplier_invoice_no }}</dd>
                </div>
                <div class="flex justify-between gap-4 rounded-lg bg-yellow-100 px-2 py-1 -mx-2 dark:bg-yellow-500/20">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Supplier Invoice Number</dt>
                    <dd class="text-sm font-mono text-zinc-900 dark:text-white text-right">{{ $supplierInvoice->supplier_ref_invoice_no ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Date</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $supplierInvoice->invoice_date->format('d M Y') }}</dd>
                </div>
                @if($supplierInvoice->notes)
                    <div class="pt-2 border-t border-zinc-100 dark:border-white/10">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-1">Notes</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white whitespace-pre-wrap">{{ $supplierInvoice->notes }}</dd>
                    </div>
                @else
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Notes</dt>
                        <dd class="text-sm text-zinc-400">—</dd>
                    </div>
                @endif
            </dl>
        </x-ui.section-card>

        {{-- Status & Metadata --}}
        <x-ui.section-card title="Status & Metadata">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Status</dt>
                    <dd>
                        <x-ui.payment-status-badge :status="$supplierInvoice->paymentStatus()" />
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Created By</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $supplierInvoice->creator?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Created At</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $supplierInvoice->created_at->format('d M Y') }}</dd>
                </div>
            </dl>
        </x-ui.section-card>

    </div>

    {{-- Line items table --}}
    <x-ui.section-card title="Line Items">
        <div class="overflow-x-auto -mx-4 sm:-mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-white/10">
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Unit Net (£)</th>
                        <th class="px-4 sm:px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">QTY</th>
                        <th class="px-4 sm:px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">VAT</th>
                        <th class="px-4 sm:px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Line Gross (£)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-white/5">
                    @forelse($supplierInvoice->items as $item)
                        <tr>
                            <td class="px-4 sm:px-6 py-3 text-zinc-700 dark:text-zinc-300">{{ number_format((float) $item->unit_amount, 2) }}</td>
                            <td class="px-4 sm:px-6 py-3 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((float) $item->quantity, 2) }}</td>
                            <td class="px-4 sm:px-6 py-3 text-center">
                                @if($item->vat_applicable)
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400">Yes (20%)</span>
                                @else
                                    <span class="text-xs text-zinc-400">No</span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-6 py-3 text-right font-medium text-zinc-900 dark:text-white">{{ number_format($item->line_gross, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 sm:px-6 py-6 text-center text-sm text-zinc-400">No line items.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Ledger summary --}}
        <div class="border-t border-zinc-200/70 bg-gradient-to-br from-violet-50 to-indigo-50 px-6 py-5 dark:border-white/10 dark:from-violet-500/10 dark:to-indigo-500/10">
            <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-violet-700 dark:text-violet-400">Ledger Statement Summary Balances</p>
            <dl class="space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-sm text-zinc-600 dark:text-zinc-400">Net Accumulated Cost Base Total</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($supplierInvoice->netTotal, 2) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-sm text-zinc-600 dark:text-zinc-400">Calculated VAT Input Pool Element (20%)</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($supplierInvoice->vatTotal, 2) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-t border-violet-200/70 pt-3 dark:border-violet-500/20">
                    <dt class="text-base font-semibold text-zinc-900 dark:text-white">Total Final Payable Gross Sum</dt>
                    <dd class="font-mono text-lg font-bold text-violet-700 dark:text-violet-400">£{{ number_format($supplierInvoice->grossTotal, 2) }}</dd>
                </div>
                @if($supplierInvoice->debitNotes->isNotEmpty())
                    @php $totalDeducted = $supplierInvoice->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount); @endphp
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-sm text-zinc-600 dark:text-zinc-400">Debit Note Deductions</dt>
                        <dd class="font-mono font-medium text-red-600 dark:text-red-400">−£{{ number_format($totalDeducted, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-t border-violet-200/70 pt-3 dark:border-violet-500/20">
                        <dt class="text-base font-semibold text-zinc-900 dark:text-white">Net Payable After Deductions</dt>
                        <dd class="font-mono text-lg font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format(max(0, $supplierInvoice->grossTotal - $totalDeducted), 2) }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Applied debit notes --}}
        @if($supplierInvoice->debitNotes->isNotEmpty())
            <div class="border-t border-zinc-200/70 dark:border-white/10">
                <div class="px-6 py-4">
                    <h3 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Applied Debit Notes</h3>
                    <div class="flex flex-col gap-2">
                        @foreach($supplierInvoice->debitNotes as $dn)
                            <div class="flex items-center justify-between text-sm">
                                <div class="flex items-center gap-3">
                                    <a href="{{ route('supplier-debit-notes.show', $dn) }}" wire:navigate class="font-mono font-semibold text-violet-600 hover:underline dark:text-violet-400">{{ $dn->reference }}</a>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $dn->doc_date->format('d M Y') }}</span>
                                </div>
                                <span class="font-mono font-semibold text-red-600 dark:text-red-400">−£{{ number_format($dn->pivot->applied_amount, 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </x-ui.section-card>

    {{-- Attachments --}}
    <x-ui.section-card title="Attachments">
        @if($supplierInvoice->attachments)
            <ul class="space-y-2">
                @foreach($supplierInvoice->attachments as $att)
                    <li class="flex items-center gap-3">
                        <flux:icon.paper-clip class="h-4 w-4 shrink-0 text-zinc-400" />
                        <a
                            href="{{ Storage::disk('public')->url($att['path']) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm text-violet-600 hover:underline dark:text-violet-400 truncate"
                        >{{ $att['original_name'] }}</a>
                        @if(isset($att['size']))
                            <span class="text-xs text-zinc-400 shrink-0">{{ number_format($att['size'] / 1024, 1) }} KB</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-zinc-400">—</p>
        @endif
    </x-ui.section-card>

    {{-- Delete modal --}}
    <livewire:pages::supplier-invoices.delete-modal :supplierInvoice="$supplierInvoice" :key="'delete-'.$supplierInvoice->id" />

</div>
