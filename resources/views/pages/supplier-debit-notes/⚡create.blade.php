<?php

use App\Livewire\Concerns\NormalizesUnitCase;
use App\Livewire\Concerns\ValidatesDocumentItems;
use App\Models\LookupUnit;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Services\DocumentTotalsCalculator;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Issue Supplier Debit Note')] class extends Component {
    use NormalizesUnitCase, ValidatesDocumentItems;

    public ?int $supplier_id = null;

    public string $supplierName = '';

    public string $doc_date = '';

    public ?int $supplier_invoice_id = null;

    public string $notes = '';

    public array $items = [];

    public array $units = [];

    public array $invoiceItemSuggestions = [];

    public string $nextReference = '';

    public float $vatRate = 20.0;

    public function mount(): void
    {
        $this->doc_date = now()->format('Y-m-d');
        $this->items = [
            ['id' => null, 'details' => '', 'quantity' => '', 'price' => '', 'per' => '', 'is_note' => false, 'discount_percent' => null, 'vat_applicable' => true],
        ];
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
        $this->nextReference = SupplierDebitNote::nextNumber();
        $this->vatRate = (float) Setting::get('vat_rate', 20);

        if (request()->filled('supplier_id')) {
            $this->supplier_id = (int) request('supplier_id');
            if ($supplier = Supplier::withTrashed()->find($this->supplier_id)) {
                $this->supplierName = $supplier->typeahead_label;
            }
        }
    }

    public function updatedSupplierId(): void
    {
        $this->supplier_invoice_id = null;
        unset($this->supplierInvoices);
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
                - COALESCE(supplier_invoices.discount_amount, 0)
                - (SELECT COALESCE(SUM(allocated_amount), 0) FROM supplier_payout_allocations WHERE supplier_invoice_id = supplier_invoices.id)
                - (SELECT COALESCE(SUM(applied_amount), 0) FROM supplier_invoice_debit_notes WHERE supplier_invoice_id = supplier_invoices.id)
                > 0.001
            ')
            ->orderByDesc('invoice_date')
            ->get(['id', 'supplier_invoice_no', 'supplier_ref_invoice_no'])
            ->toArray();
    }

    public function commitDebitNote(bool $moveToPayout = false): void
    {
        $this->items = array_values(array_filter($this->items, fn ($i) => trim((string) ($i['details'] ?? '')) !== ''
            || (float) ($i['quantity'] ?? 0) > 0
            || (float) ($i['discount_percent'] ?? 0) > 0
        ));

        $this->validate(
            [
                'supplier_id' => 'required|integer|exists:suppliers,id',
                'doc_date' => 'required|date',
                'supplier_invoice_id' => 'nullable|integer|exists:supplier_invoices,id',
                'notes' => 'nullable|string|max:1000',
                'items.*.vat_applicable' => 'boolean',
            ] + $this->documentItemRules(),
            $this->documentItemMessages(),
        );

        $subtotal = 0.0;
        $vatAmount = 0.0;
        $computed = [];

        foreach ($this->items as $idx => $item) {
            $isNote = ! empty($item['is_note']);
            $qty = $isNote ? 0.0 : (float) ($item['quantity'] ?? 0);
            $price = $isNote ? 0.0 : (float) ($item['price'] ?? 0);
            $per = $isNote ? null : ($item['per'] ?: null);
            $discountPercent = $isNote ? 0.0 : (float) ($item['discount_percent'] ?? 0);
            $vatApplicable = ! $isNote && (bool) ($item['vat_applicable'] ?? false);

            $lineValue = $isNote ? 0.0 : round(DocumentTotalsCalculator::lineValue(['quantity' => $qty, 'price' => $price, 'per' => $per]), 2);
            $net = $isNote ? 0.0 : ($discountPercent > 0 ? round($lineValue * ($discountPercent / 100), 2) : $lineValue);
            $itemVat = $vatApplicable ? round($net * $this->vatRate / 100, 2) : 0.0;

            $subtotal += $net;
            $vatAmount += $itemVat;

            $computed[] = [
                'description' => (string) $item['details'],
                'is_note' => $isNote,
                'quantity' => $qty,
                'amount' => $price,
                'per' => $per,
                'discount_percent' => $discountPercent,
                'line_value' => $lineValue,
                'vat_applicable' => $vatApplicable,
                'total' => $net,
                'sort_order' => $idx,
            ];
        }

        $subtotal = round($subtotal, 2);
        $vatAmount = round($vatAmount, 2);
        $total = round($subtotal + $vatAmount, 2);

        $debitNote = DB::transaction(function () use ($computed, $subtotal, $vatAmount, $total) {
            $dn = SupplierDebitNote::create([
                'supplier_id' => $this->supplier_id,
                'doc_date' => $this->doc_date,
                'supplier_invoice_id' => $this->supplier_invoice_id ?: null,
                'notes' => $this->notes ?: null,
                'subtotal' => $subtotal,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'created_by' => auth()->id(),
            ]);

            foreach ($computed as $row) {
                $dn->items()->create($row);
            }

            if ($this->supplier_invoice_id) {
                $dn->appliedInvoices()->attach($this->supplier_invoice_id, [
                    'applied_amount' => $dn->total,
                    'applied_at' => now(),
                ]);
            }

            return $dn;
        });

        Flux::modal('confirm-commit-debit-note')->close();

        if ($moveToPayout) {
            Flux::toast(variant: 'success', text: 'Debit note '.$debitNote->reference.' committed.');
            $this->redirect(route('supplier-payouts.create', ['dn' => $debitNote->id]), navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: 'Debit note '.$debitNote->reference.' committed.', duration: 0);
        $this->resetDebitNoteFields();
        $this->nextReference = SupplierDebitNote::nextNumber();
    }

    public function resetDebitNoteFields(): void
    {
        $this->supplier_id = null;
        $this->supplierName = '';
        $this->doc_date = now()->format('Y-m-d');
        $this->supplier_invoice_id = null;
        $this->notes = '';
        $this->items = [
            ['id' => null, 'details' => '', 'quantity' => '', 'price' => '', 'per' => '', 'is_note' => false, 'discount_percent' => null, 'vat_applicable' => true],
        ];
        unset($this->supplierInvoices);
        $this->dispatch('debit-note-items-reset');
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Issue Supplier Debit Note"
        subtitle="Create a debit note to reduce a supplier liability."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('supplier-debit-notes.index')" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('supplier-debit-notes.index') }}', { line: { discount_percent: null, vat_applicable: true } })"
        x-on:submit.prevent="$wire.set('items', rows, false).then(() => $flux.modal('confirm-commit-debit-note').show())"
        x-on:keydown="handleKey($event)"
        x-on:exit-confirm-discard.window="cancel()"
        x-on:exit-confirm-save.window="$wire.set('items', rows, false).then(() => $wire.commitDebitNote(false))"
        @debit-note-items-reset.window="rows = [{ id: null, details: '', quantity: '', price: '', per: '', is_note: false, discount_percent: null, vat_applicable: true }]"
        class="flex min-w-0 flex-1 flex-col gap-4"
    >

        {{-- Header details --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
            </div>
            <div data-form-grid class="grid gap-4 p-4 md:grid-cols-2">
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
                    <flux:label>{{ __('Debit Note No.') }}</flux:label>
                    <div class="mt-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                        <span class="font-mono text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $nextReference }}</span>
                    </div>
                </div>
                <flux:input wire:model="doc_date" type="date" :label="__('Document Date')" required />
                <div>
                    <flux:label>{{ __('Against Supplier Invoice') }}</flux:label>
                    <flux:select wire:model="supplier_invoice_id" class="mt-1.5" :disabled="! $supplier_id" x-on:focus="$el.showPicker?.()">
                        <flux:select.option value="">— None —</flux:select.option>
                        @foreach($this->supplierInvoices as $inv)
                            <flux:select.option :value="$inv['id']">{{ $inv['supplier_invoice_no'] }}@if(! empty($inv['supplier_ref_invoice_no'])) ({{ $inv['supplier_ref_invoice_no'] }})@endif</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('supplier_invoice_id') <flux:error>{{ $message }}</flux:error> @enderror
                </div>
                <div class="md:col-span-2">
                    <flux:input wire:model="notes" :label="__('Notes')" :placeholder="__('Optional notes for this debit note…')" />
                    @error('notes') <flux:error>{{ $message }}</flux:error> @enderror
                </div>
            </div>
        </div>

        {{-- Line Items --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Items</h2>
                </div>
                <div class="flex items-center gap-2">
                    <flux:button type="button" variant="ghost" icon="chat-bubble-left" size="sm" x-on:click="addNote()">Add Note <x-ui.kbd-hint keys="Shift+↵" /></flux:button>
                    <flux:button type="button" variant="ghost" icon="plus" size="sm" x-on:click="add()">Add Line</flux:button>
                </div>
            </div>

            <div class="overflow-x-auto" data-items-table>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-2 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Details</th>
                            <th class="w-20 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th class="w-28 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount (£)</th>
                            <th class="w-28 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                            <th class="w-20 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT</th>
                            <th class="w-24 px-2 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Disc %</th>
                            <th class="w-28 px-2 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Net Value</th>
                            <th class="w-8 px-2 py-3"></th>
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :data-row-idx="i" :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-2 py-2.5" :colspan="row.is_note ? 7 : 1">
                                    <div class="flex items-center gap-2">
                                        <flux:icon.chat-bubble-left x-show="row.is_note" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <input
                                            type="text"
                                            data-row-details
                                            x-model="row.details"
                                            :placeholder="row.is_note ? '{{ __('Note…') }}' : '{{ __('Description…') }}'"
                                            :class="row.is_note ? 'italic' : ''"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 placeholder:font-normal placeholder:text-zinc-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </div>
                                </td>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <input
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            data-row-qty
                                            x-model.number="row.quantity"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            x-model.number="row.price"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <select
                                            x-model="row.per"
                                            @change.stop
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        >
                                            <option value="">—</option>
                                            @foreach($units as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <select
                                            x-model.boolean="row.vat_applicable"
                                            @change.stop
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        >
                                            <option :value="true">Yes</option>
                                            <option :value="false">No</option>
                                        </select>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <input type="number" min="0" max="100" step="0.01" x-model.number="row.discount_percent"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">
                                        £<span x-text="netValue(row).toFixed(2)">0.00</span>
                                    </td>
                                </template>
                                <td class="px-2 py-2.5">
                                    <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="remove(i)" x-show="rows.length > 1" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            @error('items') <p class="px-6 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('items.*.details') <p class="px-6 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('items.*.quantity') <p class="px-6 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('items.*.price') <p class="px-6 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('items.*.per') <p class="px-6 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        {{-- Debit Totals --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Debit Summary</h2>
            </div>
            <div class="p-4">
                <div class="flex flex-col gap-3">
                    <div class="border-t border-zinc-100 pt-3 dark:border-white/[0.06]">
                        <div class="flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                            <span>Items net subtotal</span>
                            <span class="font-mono tabular-nums" x-text="'£' + rows.filter(r => !r.is_note).reduce((s, r) => s + netValue(r), 0).toFixed(2)"></span>
                        </div>
                        <div class="mt-1 flex justify-between text-sm text-zinc-500 dark:text-zinc-400" x-show="$wire.vatRate > 0 && rows.some(r => !r.is_note && r.vat_applicable)">
                            <span x-text="'VAT (' + $wire.vatRate + '%)'"></span>
                            <span class="font-mono tabular-nums" x-text="'£' + rows.filter(r => !r.is_note && r.vat_applicable).reduce((s, r) => s + netValue(r) * ($wire.vatRate / 100), 0).toFixed(2)"></span>
                        </div>
                        <div class="mt-2 flex justify-between border-t border-zinc-200 pt-2 dark:border-white/10">
                            <span class="font-semibold text-zinc-900 dark:text-white">Total Debit</span>
                            <span class="font-mono tabular-nums font-semibold text-zinc-900 dark:text-white" x-text="'£' + (function() { const sub = rows.filter(r => !r.is_note).reduce((s, r) => s + netValue(r), 0); const vat = rows.filter(r => !r.is_note && r.vat_applicable).reduce((s, r) => s + netValue(r) * ($wire.vatRate / 100), 0); return (sub + vat).toFixed(2); })()"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sticky footer bar --}}
        <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
            <x-ui.back-button :fallback="route('supplier-debit-notes.index')" confirm data-form-nav />
            <flux:button variant="primary" type="submit" data-form-nav>Commit Debit Note</flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

    {{-- Commit modal --}}
    <flux:modal name="confirm-commit-debit-note" class="max-w-sm">
        <div x-data="debitNoteCommitNav" x-on:keydown="handleShortcut($event)" class="flex flex-col gap-4 p-1">
            <div>
                <flux:heading size="lg">Commit this debit note?</flux:heading>
                <flux:subheading class="mt-1">Save it on its own, or save and continue to record a payout against it.</flux:subheading>
            </div>
            <div
                x-ref="choices"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.tab.prevent="move($event.shiftKey ? -1 : 1)"
                class="flex flex-col gap-2 [&_button:focus]:ring-2 [&_button:focus]:ring-indigo-500 [&_button:focus]:ring-offset-2 [&_button:focus]:outline-none dark:[&_button:focus]:ring-offset-zinc-900"
            >
                <flux:button variant="primary" class="justify-start" wire:click="commitDebitNote(false)" data-dn-commit-btn><div><u>S</u>ave debit note</div></flux:button>
                <flux:button class="justify-start" wire:click="commitDebitNote(true)"><div>Save &amp; move to <u>p</u>ayout</div></flux:button>
                <flux:button variant="ghost" class="justify-start" x-on:click="$flux.modal('confirm-commit-debit-note').close()"><div><u>C</u>ancel</div></flux:button>
            </div>
        </div>
    </flux:modal>

</div>
