<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use App\Models\SupplierInvoice;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Issue Supplier Debit Note')] class extends Component {
    public ?int $supplier_id = null;
    public string $supplierName = '';
    public string $doc_date = '';
    public ?int $supplier_invoice_id = null;
    public string $notes = '';
    public array $items = [];
    public string $nextReference = '';
    public float $vatRate = 20.0;

    public function mount(): void
    {
        $this->doc_date = now()->format('Y-m-d');
        $this->items = [
            ['description' => '', 'quantity' => '', 'amount' => '', 'total' => 0],
        ];
        $this->nextReference = SupplierDebitNote::nextNumber();
        $this->vatRate = (float) Setting::get('vat_rate', 20);
    }

    public function updatedSupplierId(): void
    {
        $this->supplier_invoice_id = null;
        unset($this->supplierInvoices);
        $this->dispatch('debit-note-vat-changed', hasVat: $this->supplierHasVat);
    }

    #[Computed]
    public function supplierHasVat(): bool
    {
        if (! $this->supplier_id) {
            return false;
        }

        return (bool) Supplier::withTrashed()->find($this->supplier_id)?->vat_applied;
    }

    #[Computed]
    public function supplierInvoices(): array
    {
        if (! $this->supplier_id) {
            return [];
        }

        return SupplierInvoice::where('supplier_id', $this->supplier_id)
            ->whereRaw('
                (SELECT COALESCE(SUM(line_total), 0) FROM supplier_invoice_items WHERE supplier_invoice_id = supplier_invoices.id)
                - (SELECT COALESCE(SUM(allocated_amount), 0) FROM supplier_payout_allocations WHERE supplier_invoice_id = supplier_invoices.id)
                - (SELECT COALESCE(SUM(applied_amount), 0) FROM supplier_invoice_debit_notes WHERE supplier_invoice_id = supplier_invoices.id)
                > 0.001
            ')
            ->orderByDesc('invoice_date')
            ->get(['id', 'supplier_invoice_no'])
            ->toArray();
    }

    public function commitDebitNote(): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            fn ($i) => trim((string) ($i['description'] ?? '')) !== ''
        ));

        $this->validate([
            'supplier_id'         => 'required|integer|exists:suppliers,id',
            'doc_date'            => 'required|date',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.amount'      => 'required|numeric|min:0',
        ]);

        $items = $this->items;

        $subtotal = collect($items)->sum(
            fn ($i) => round((float) $i['quantity'] * (float) $i['amount'], 2)
        );

        $vatAmount = $this->supplierHasVat ? round($subtotal * $this->vatRate / 100, 2) : 0.0;
        $total = round($subtotal + $vatAmount, 2);

        $debitNote = DB::transaction(function () use ($items, $subtotal, $vatAmount, $total) {
            $dn = SupplierDebitNote::create([
                'supplier_id'         => $this->supplier_id,
                'doc_date'            => $this->doc_date,
                'supplier_invoice_id' => $this->supplier_invoice_id ?: null,
                'notes'               => $this->notes ?: null,
                'subtotal'            => $subtotal,
                'vat_amount'          => $vatAmount,
                'total'               => $total,
                'created_by'          => auth()->id(),
            ]);

            foreach (array_values($items) as $idx => $item) {
                SupplierDebitNoteItem::create([
                    'supplier_debit_note_id' => $dn->id,
                    'description'            => $item['description'],
                    'quantity'               => (float) $item['quantity'],
                    'amount'                 => (float) $item['amount'],
                    'total'                  => round((float) $item['quantity'] * (float) $item['amount'], 2),
                    'sort_order'             => $idx,
                ]);
            }

            if ($this->supplier_invoice_id) {
                $dn->appliedInvoices()->attach($this->supplier_invoice_id, [
                    'applied_amount' => $dn->total,
                    'applied_at'     => now(),
                ]);
            }

            return $dn;
        });

        Flux::modal('confirm-commit-debit-note')->close();
        Flux::toast(variant: 'success', text: 'Debit note ' . $debitNote->reference . ' committed.', duration: 0);

        $supplierId = $this->supplier_id;
        $linkedInvoiceId = $this->supplier_invoice_id;
        $this->resetDebitNoteFields();
        $this->nextReference = SupplierDebitNote::nextNumber();

        $this->dispatch('debit-note-committed', debitNoteId: $debitNote->id, supplierId: $supplierId, linkedInvoiceId: $linkedInvoiceId);
    }

    public function resetDebitNoteFields(): void
    {
        $this->supplier_id = null;
        $this->supplierName = '';
        $this->doc_date = now()->format('Y-m-d');
        $this->supplier_invoice_id = null;
        $this->notes = '';
        $this->items = [
            ['description' => '', 'quantity' => '', 'amount' => '', 'total' => 0],
        ];
        unset($this->supplierInvoices);
        $this->dispatch('debit-note-items-reset');
        $this->dispatch('debit-note-vat-changed', hasVat: false);
    }

    public function resetForm(): void
    {
        Flux::modal('confirm-reset-debit-note')->close();
        $this->resetDebitNoteFields();
        $this->nextReference = SupplierDebitNote::nextNumber();
    }
}; ?>

<div class="flex flex-col gap-6">

    <x-ui.page-header
        title="Issue Supplier Debit Note"
        subtitle="Create a debit note to reduce a supplier liability."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('supplier-debit-notes.index')" wire:navigate>
                Back
            </flux:button>
            <flux:button variant="primary" icon="check" :href="route('supplier-debit-notes.index')" wire:navigate>
                Done
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-2">

        {{-- LEFT: Debit Note Form --}}
        <div class="flex flex-col gap-4">

            <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">

                <div class="flex items-center justify-between border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Issue Supplier Debit Note (−)</h2>
                    <flux:badge color="red" size="sm">Reduces Liability Ledger</flux:badge>
                </div>

                <div class="flex flex-col gap-4 p-4" data-form-root data-f2-handler x-data="supplierDebitNoteForm" @keydown.window="handleKey($event)" @f2-action="window.dispatchEvent(new CustomEvent('dn-pre-commit'))">

                    {{-- Row 1: Supplier + Debit Note No. --}}
                    <div class="grid grid-cols-2 gap-4">
                        <livewire:pages::ui.typeahead
                            :key="'typeahead-supplier-dn'"
                            wire:model.live="supplier_id"
                            model="App\Models\Supplier"
                            column="company_name"
                            :search-columns="['company_name', 'reference']"
                            label-accessor="typeahead_label"
                            :min-chars="2"
                            :label="__('Supplier')"
                            :placeholder="__('Search supplier (2+ letters)…')"
                            :selected-label="$supplierName"
                            error-name="supplier_id"
                            required
                        />
                        <div>
                            <flux:label>Debit Note No.</flux:label>
                            <div class="mt-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                                <span class="font-mono text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $nextReference }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- Row 2: Date + Invoice --}}
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="doc_date" type="date" :label="__('Document Date')" required />
                        <div>
                            <flux:label>Against Supplier Invoice</flux:label>
                            <flux:select wire:model="supplier_invoice_id" class="mt-1.5" :disabled="! $supplier_id" x-on:focus="$el.showPicker?.()">
                                <flux:select.option value="">— None —</flux:select.option>
                                @foreach($this->supplierInvoices as $inv)
                                    <flux:select.option :value="$inv['id']">{{ $inv['supplier_invoice_no'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('supplier_invoice_id') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>
                    </div>

                    {{-- Row 3: Notes --}}
                    <div>
                        <flux:input wire:model="notes" :label="__('Notes')" :placeholder="__('Optional notes…')" />
                        @error('notes') <flux:error>{{ $message }}</flux:error> @enderror
                    </div>

                    {{-- Items table (Alpine-managed, wire:ignore) --}}
                    <div class="col-span-full" wire:ignore
                        x-data="supplierDebitNoteItems(@js($items), {{ $vatRate }}, @js($this->supplierHasVat))"
                        @debit-note-items-reset.window="rows = [{ description: '', quantity: '', amount: '', total: 0 }]"
                        @debit-note-vat-changed.window="hasVat = $event.detail.hasVat"
                        @dn-pre-commit.window="preCommit()"
                        @dn-focus-first-item.window="$nextTick(() => { const f = $el.querySelector('[data-dn-row-desc]'); if (f) { f.focus(); f.scrollIntoView({ block: 'center', behavior: 'smooth' }); } })"
                        @keydown.window="handleKey($event)">

                        <div class="overflow-hidden rounded-xl border border-zinc-200/70 dark:border-white/10" data-dn-items-table>
                            <div class="flex items-center justify-between border-b border-zinc-200/70 px-4 py-2.5 dark:border-white/10">
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Line Items</span>
                                <flux:button type="button" variant="ghost" icon="plus" size="sm" @click="addRow()">Add Line</flux:button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Description</th>
                                            <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                                            <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount (£)</th>
                                            <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total (£)</th>
                                            <th class="w-10 px-4 py-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                                        <template x-for="(row, i) in rows" :key="i">
                                            <tr :data-row-idx="i">
                                                <td class="px-4 py-2.5">
                                                    <input
                                                        type="text"
                                                        x-model="row.description"
                                                        data-dn-row-desc
                                                        placeholder="Description…"
                                                        class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                                    />
                                                </td>
                                                <td class="px-4 py-2.5">
                                                    <input
                                                        type="number"
                                                        x-model="row.quantity"
                                                        min="0.01"
                                                        step="0.01"
                                                        class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-right text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                                    />
                                                </td>
                                                <td class="px-4 py-2.5">
                                                    <input
                                                        type="number"
                                                        x-model="row.amount"
                                                        min="0"
                                                        step="0.01"
                                                        class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-right text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                                    />
                                                </td>
                                                <td class="px-4 py-2.5 text-right font-mono text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">
                                                    £<span x-text="rowTotal(row).toFixed(2)">0.00</span>
                                                </td>
                                                <td class="px-4 py-2.5">
                                                    <flux:button size="xs" variant="ghost" icon="x-mark" type="button" @click="removeRow(i)" x-show="rows.length > 1" />
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex flex-col gap-1.5 border-t border-zinc-200/70 px-4 py-3 dark:border-white/10">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">Subtotal</span>
                                    <span class="font-mono text-sm font-medium tabular-nums text-zinc-900 dark:text-white">
                                        −£<span x-text="subtotal.toFixed(2)">0.00</span>
                                    </span>
                                </div>
                                <template x-if="hasVat">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">VAT (<span x-text="vatRate"></span>%)</span>
                                        <span class="font-mono text-sm font-medium tabular-nums text-zinc-900 dark:text-white">
                                            −£<span x-text="vatTotal.toFixed(2)">0.00</span>
                                        </span>
                                    </div>
                                </template>
                                <div class="flex items-center justify-between pt-1">
                                    <span class="text-sm font-semibold text-zinc-900 dark:text-white">Total</span>
                                    <span class="font-mono text-sm font-bold tabular-nums text-red-600 dark:text-red-400">
                                        −£<span x-text="grossTotal.toFixed(2)">0.00</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        @error('items') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @error('items.*.description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @error('items.*.quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @error('items.*.amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

                        <div class="flex justify-end gap-3 pt-4">
                            <flux:button
                                variant="ghost"
                                type="button"
                                @click="$flux.modal('confirm-reset-debit-note').show()"
                            >
                                Reset
                            </flux:button>
                            <flux:button
                                variant="danger"
                                type="button"
                                @click="window.dispatchEvent(new CustomEvent('dn-pre-commit'))"
                            >
                                Commit Debit Note (−)
                            </flux:button>
                        </div>

                    </div>

                </div>
            </div>

        </div>

        {{-- RIGHT: Supplier Payout Panel --}}
        <div class="flex flex-col gap-4">
            <livewire:pages::supplier-payouts.create-panel :key="'payout-panel'" />
        </div>

    </div>

    {{-- BOTTOM: Report Summary --}}
    <livewire:pages::supplier-payouts.report-summary :key="'report-summary'" />

    {{-- Modals --}}
    <flux:modal name="confirm-commit-debit-note" class="max-w-sm">
        <div class="flex flex-col gap-4 p-1">
            <div>
                <flux:heading size="lg">Commit this debit note?</flux:heading>
                <flux:subheading class="mt-1">This action is permanent and cannot be undone.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" @click="$flux.modal('confirm-commit-debit-note').close()">Cancel</flux:button>
                <flux:button variant="danger" wire:click="commitDebitNote" data-dn-commit-btn>Commit</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-reset-debit-note" class="max-w-sm">
        <div class="flex flex-col gap-4 p-1">
            <div>
                <flux:heading size="lg">Clear all debit note entries?</flux:heading>
                <flux:subheading class="mt-1">All unsaved fields and line items will be cleared.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" @click="$flux.modal('confirm-reset-debit-note').close()">Go Back</flux:button>
                <flux:button variant="danger" wire:click="resetForm">Clear</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
