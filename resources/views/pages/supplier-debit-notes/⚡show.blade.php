<?php

use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux\Flux;

new #[Title('Supplier Debit Note')] class extends Component {
    public SupplierDebitNote $debitNote;

    public function mount(): void
    {
        $this->debitNote->load(['supplier', 'supplierInvoice', 'items', 'appliedInvoices', 'creator', 'emailLogs']);
    }

    #[On('email-log-updated')]
    public function refreshEmailLogs(): void
    {
        $this->debitNote->load('emailLogs');
    }

    #[Computed]
    public function effectiveVatRate(): float
    {
        $vatApplicableSubtotal = (float) $this->debitNote->items->where('vat_applicable', true)->sum('total');

        if ($vatApplicableSubtotal <= 0) {
            return 0.0;
        }

        return round(((float) $this->debitNote->vat_amount / $vatApplicableSubtotal) * 100, 4);
    }

    public function itemVat(SupplierDebitNoteItem $item): float
    {
        return $item->vat_applicable
            ? round((float) $item->total * $this->effectiveVatRate / 100, 2)
            : 0.0;
    }

    public function itemGross(SupplierDebitNoteItem $item): float
    {
        return (float) $item->total + $this->itemVat($item);
    }

    public function delete(): void
    {
        $ref = $this->debitNote->reference;
        $this->debitNote->delete();
        Flux::toast(variant: 'success', text: 'Debit note '.$ref.' deleted.');
        $this->redirect(route('supplier-debit-notes.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('supplier-debit-notes.index')" wire:navigate>Back</flux:button>
        <div class="flex items-center gap-2">
            @can('supplierdebitnote-edit')
            @if($debitNote->isApplied())
                <flux:tooltip content="Cannot edit applied debit note">
                    <flux:button variant="ghost" icon="pencil" size="sm" disabled>Edit</flux:button>
                </flux:tooltip>
            @else
                <flux:button variant="ghost" icon="pencil" size="sm" :href="route('supplier-debit-notes.edit', $debitNote)" wire:navigate>Edit</flux:button>
            @endif
            @endcan
            <flux:button variant="ghost" icon="arrow-down-tray" size="sm" :href="route('supplier-debit-notes.pdf.download', $debitNote)">
                Download PDF
            </flux:button>
            <flux:button variant="ghost" icon="document" size="sm" :href="route('supplier-debit-notes.pdf', $debitNote)" target="_blank">
                View PDF
            </flux:button>
            <flux:button variant="ghost" icon="printer" size="sm" x-on:click="window.printPdfDocument('{{ route('supplier-debit-notes.pdf', $debitNote) }}')">
                Print
            </flux:button>
            <flux:button
                variant="primary"
                icon="envelope"
                size="sm"
                x-on:click="$flux.modal('email-supplier-debit-note-{{ $debitNote->id }}').show()"
            >
                Send Email
            </flux:button>
            @can('supplierdebitnote-delete')
            <flux:button
                size="sm"
                variant="ghost"
                icon="trash"
                x-on:click="$flux.modal('delete-debit-note').show()"
                class="text-red-500 hover:text-red-700"
            >
                Delete
            </flux:button>
            @endcan
        </div>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-red-500 via-rose-500 to-red-600"></div>

        {{-- Floating icon badge --}}
        <div class="absolute left-6 top-10 flex h-16 w-16 items-center justify-center rounded-full bg-white ring-4 ring-white dark:bg-zinc-900 dark:ring-zinc-900">
            <flux:icon.minus-circle class="h-8 w-8 text-red-600 dark:text-red-400" />
        </div>

        <div class="px-4 pb-4 pt-14">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="font-mono text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $debitNote->reference }}</h1>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $debitNote->status->ringColor() }}">
                            {{ $debitNote->status->label() }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $debitNote->supplier->company_name }} &middot; {{ $debitNote->doc_date->format('d M Y') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column details grid --}}
    <div class="grid gap-4 md:grid-cols-2">

        <x-ui.section-card title="Debit Note Details">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Document Date</dt>
                    <dd class="text-right text-sm text-zinc-900 dark:text-white">{{ $debitNote->doc_date->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Supplier</dt>
                    <dd class="text-right text-sm">
                        <a href="{{ route('suppliers.show', $debitNote->supplier) }}" wire:navigate class="text-red-600 hover:underline dark:text-red-400">
                            {{ $debitNote->supplier->company_name }}
                        </a>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Linked Invoice</dt>
                    <dd class="text-right text-sm">
                        @if($debitNote->supplierInvoice)
                            <a href="{{ route('supplier-invoices.show', $debitNote->supplierInvoice) }}" wire:navigate class="text-red-600 hover:underline dark:text-red-400">
                                {{ $debitNote->supplierInvoice->supplier_invoice_no }}
                            </a>@if($debitNote->supplierInvoice->supplier_ref_invoice_no) <span class="text-zinc-400 dark:text-zinc-500">({{ $debitNote->supplierInvoice->supplier_ref_invoice_no }})</span>@endif
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </dd>
                </div>
                @if($debitNote->notes)
                    <div class="border-t border-zinc-100 pt-2 dark:border-white/10">
                        <dt class="mb-1 text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Notes</dt>
                        <dd class="whitespace-pre-wrap text-sm text-zinc-900 dark:text-white">{{ $debitNote->notes }}</dd>
                    </div>
                @else
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Notes</dt>
                        <dd class="text-sm text-zinc-400">—</dd>
                    </div>
                @endif
            </dl>
        </x-ui.section-card>

        <x-ui.section-card title="Status & Metadata">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $debitNote->status->ringColor() }}">
                            {{ $debitNote->status->label() }}
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Created By</dt>
                    <dd class="text-right text-sm text-zinc-900 dark:text-white">{{ $debitNote->creator?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Created At</dt>
                    <dd class="text-right text-sm text-zinc-900 dark:text-white">{{ $debitNote->created_at->format('d M Y') }}</dd>
                </div>
            </dl>
        </x-ui.section-card>

    </div>

    {{-- Line items table --}}
    <x-ui.section-card title="Line Items">
        <div class="-mx-4 overflow-x-auto sm:-mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-white/10">
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Amount (£)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">VAT</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">VAT Amt (£)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Total (£)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-white/5">
                    @forelse($debitNote->items as $item)
                        <tr>
                            <td class="px-4 py-3 text-zinc-900 sm:px-6 dark:text-white">{{ $item->description ?: '—' }}</td>
                            <td class="px-4 py-3 text-right text-zinc-700 sm:px-6 dark:text-zinc-300">{{ number_format((float) $item->quantity, 2) }}</td>
                            <td class="px-4 py-3 text-right text-zinc-700 sm:px-6 dark:text-zinc-300">{{ number_format((float) $item->amount, 2) }}</td>
                            <td class="px-4 py-3 sm:px-6">
                                @if($item->vat_applicable)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400">Yes</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 ring-1 ring-inset ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-zinc-700 sm:px-6 dark:text-zinc-300">
                                {{ $item->vat_applicable ? number_format($this->itemVat($item), 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-zinc-900 sm:px-6 dark:text-white">{{ number_format($this->itemGross($item), 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-zinc-400 sm:px-6">No line items.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200/70 bg-gradient-to-br from-red-50 to-rose-50 px-6 py-5 dark:border-white/10 dark:from-red-500/10 dark:to-rose-500/10">
            <dl class="space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-sm text-zinc-600 dark:text-zinc-400">Subtotal</dt>
                    <dd class="font-mono text-sm font-medium text-zinc-900 dark:text-white">£{{ number_format((float) $debitNote->subtotal, 2) }}</dd>
                </div>
                @if((float) $debitNote->vat_amount > 0)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-sm text-zinc-600 dark:text-zinc-400">VAT</dt>
                        <dd class="font-mono text-sm font-medium text-zinc-900 dark:text-white">£{{ number_format((float) $debitNote->vat_amount, 2) }}</dd>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-4 border-t border-red-200/70 pt-3 dark:border-red-500/20">
                    <dt class="text-base font-semibold text-zinc-900 dark:text-white">Total</dt>
                    <dd class="font-mono text-lg font-bold text-red-700 dark:text-red-400">£{{ number_format((float) $debitNote->total, 2) }}</dd>
                </div>
            </dl>
        </div>
    </x-ui.section-card>

    {{-- Applied invoices section --}}
    @if($debitNote->isApplied())
        <x-ui.section-card title="Applied To Invoices">
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-300">
                This debit note has been applied to the following invoice(s):
            </div>
            <div class="-mx-4 overflow-x-auto sm:-mx-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/10">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Invoice Ref</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Applied Amount (£)</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-400 sm:px-6 dark:text-zinc-500">Applied At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/5">
                        @foreach($debitNote->appliedInvoices as $invoice)
                            <tr>
                                <td class="px-4 py-3 sm:px-6">
                                    <a href="{{ route('supplier-invoices.show', $invoice) }}" wire:navigate class="font-mono text-red-600 hover:underline dark:text-red-400">
                                        {{ $invoice->supplier_invoice_no }}
                                    </a>@if($invoice->supplier_ref_invoice_no) <span class="font-mono text-zinc-400 dark:text-zinc-500">({{ $invoice->supplier_ref_invoice_no }})</span>@endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-zinc-900 sm:px-6 dark:text-white">{{ number_format((float) $invoice->pivot->applied_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right text-zinc-500 sm:px-6 dark:text-zinc-400">
                                    {{ $invoice->pivot->applied_at ? \Carbon\Carbon::parse($invoice->pivot->applied_at)->format('d M Y') : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.section-card>
    @endif

    {{-- Email History --}}
    <x-ui.section-card title="Email History">
        @if($debitNote->emailLogs->isEmpty())
            <x-ui.empty-state
                icon="envelope"
                title="No emails sent yet"
                description="Send this debit note to the supplier."
            />
        @else
            <ul class="space-y-3">
                @foreach($debitNote->emailLogs()->latest()->get() as $log)
                    <li class="flex items-start gap-3">
                        <div @class([
                            'mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-white dark:ring-zinc-900',
                            'bg-emerald-500' => $log->status === 'sent',
                            'bg-rose-500' => $log->status !== 'sent',
                        ])></div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-zinc-900 dark:text-white">{{ implode(', ', $log->recipient_emails ?? [$log->recipient_email]) }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $log->sent_at?->diffForHumans() ?? $log->created_at?->diffForHumans() ?? 'Unknown' }}</p>
                            @if($log->status !== 'sent' && $log->error_message)
                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $log->error_message }}</p>
                            @endif
                        </div>
                        <span @class([
                            'shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400' => $log->status === 'sent',
                            'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400' => $log->status !== 'sent',
                        ])>{{ ucfirst($log->status) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.section-card>

    {{-- Delete modal --}}
    <flux:modal name="delete-debit-note" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Delete Debit Note?</flux:heading>
                <flux:subheading>{{ $debitNote->reference }} will be soft-deleted. Payout allocation links may be affected.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" @click="$flux.modal('delete-debit-note').close()">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete">Yes, Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <livewire:pages::supplier-debit-notes.email-modal :debitNote="$debitNote" :key="'email-'.$debitNote->id" />

</div>
