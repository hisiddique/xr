<?php

use App\Livewire\Concerns\NormalizesUnitCase;
use App\Livewire\Concerns\ValidatesDocumentItems;
use App\Models\LookupUnit;
use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Services\DocumentTotalsCalculator;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Debit Note')] class extends Component {
    use NormalizesUnitCase, ValidatesDocumentItems;

    public SupplierDebitNote $debitNote;

    public string $doc_date = '';

    public string $notes = '';

    public array $items = [];

    public array $units = [];

    public array $invoiceItemSuggestions = [];

    public bool $isApplied = false;

    public float $vatRate = 20.0;

    public function mount(): void
    {
        $this->debitNote->load(['supplier', 'supplierInvoice', 'items']);
        $this->doc_date = $this->debitNote->doc_date->format('Y-m-d');
        $this->notes = $this->debitNote->notes ?? '';
        $this->isApplied = $this->debitNote->isApplied();
        $this->vatRate = (float) Setting::get('vat_rate', 20);
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
        $this->items = $this->debitNote->items->map(fn ($item) => [
            'id' => $item->id,
            'details' => $item->description,
            'is_note' => (bool) $item->is_note,
            'quantity' => (string) $item->quantity,
            'price' => (string) $item->amount,
            'per' => $this->normalizeUnitCase($this->units, $item->per) ?? '',
            'discount_percent' => (float) ($item->discount_percent ?? 0),
            'vat_applicable' => (bool) $item->vat_applicable,
        ])->toArray();
    }

    public function save(): void
    {
        $rules = [
            'doc_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ];

        if (! $this->isApplied) {
            $this->items = array_values(array_filter($this->items, fn ($i) => trim((string) ($i['details'] ?? '')) !== ''
                || (float) ($i['quantity'] ?? 0) > 0
                || (float) ($i['discount_percent'] ?? 0) > 0
            ));

            $rules += ['items.*.vat_applicable' => 'boolean'] + $this->documentItemRules();
        }

        $this->validate($rules, $this->documentItemMessages());

        $this->debitNote->update([
            'doc_date' => $this->doc_date,
            'notes' => $this->notes ?: null,
        ]);

        if (! $this->isApplied) {
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

            $this->debitNote->items()->delete();
            foreach ($computed as $row) {
                $this->debitNote->items()->create($row);
            }

            $this->debitNote->update([
                'subtotal' => round($subtotal, 2),
                'vat_amount' => round($vatAmount, 2),
                'total' => round($subtotal + $vatAmount, 2),
            ]);
        }

        Flux::toast(variant: 'success', text: 'Debit note updated.');
        $this->redirect(route('supplier-debit-notes.show', $this->debitNote), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        :title="'Edit: '.$debitNote->reference"
        subtitle="Update the debit note details and line items."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('supplier-debit-notes.show', $debitNote)" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    @if($isApplied)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700/30 dark:bg-amber-500/5">
            <div class="flex items-center gap-3">
                <flux:icon.exclamation-triangle class="size-5 shrink-0 text-amber-600" />
                <p class="text-sm text-amber-800 dark:text-amber-400">This debit note has been applied to an invoice. Line items cannot be edited.</p>
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('supplier-debit-notes.show', $debitNote) }}', { line: { discount_percent: null, vat_applicable: true } })"
        x-on:submit.prevent="submit()"
        x-on:keydown="handleKey($event)"
        x-on:exit-confirm-discard.window="cancel()"
        x-on:exit-confirm-save.window="submit()"
        class="flex min-w-0 flex-1 flex-col gap-4"
    >

        {{-- Header details --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
            </div>
            <div data-form-grid class="grid gap-4 p-4 md:grid-cols-2">
                <div>
                    <flux:label>{{ __('Debit Note Reference') }}</flux:label>
                    <div class="mt-1.5 flex items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                        <span class="font-mono text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $debitNote->reference }}</span>
                        <span class="ml-auto text-xs text-zinc-400">Read-only</span>
                    </div>
                </div>
                <div>
                    <flux:label>{{ __('Supplier') }}</flux:label>
                    <div class="mt-1.5 flex items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $debitNote->supplier->company_name }}</span>
                        <span class="ml-auto text-xs text-zinc-400">Read-only</span>
                    </div>
                </div>
                <flux:input wire:model="doc_date" type="date" :label="__('Document Date')" required />
                <div>
                    <flux:label>{{ __('Against Supplier Invoice') }}</flux:label>
                    <div class="mt-1.5 flex items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                        @if($debitNote->supplierInvoice)
                            <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $debitNote->supplierInvoice->supplier_invoice_no }}</span>
                        @else
                            <span class="text-sm text-zinc-400">—</span>
                        @endif
                        <span class="ml-auto text-xs text-zinc-400">Read-only</span>
                    </div>
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
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Items</h2>
                @unless($isApplied)
                    <div class="flex items-center gap-2">
                        <flux:button type="button" variant="ghost" icon="chat-bubble-left" size="sm" x-on:click="addNote()">Add Note <x-ui.kbd-hint keys="Shift+↵" /></flux:button>
                        <flux:button type="button" variant="ghost" icon="plus" size="sm" x-on:click="add()">Add Line</flux:button>
                    </div>
                @endunless
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
                            @unless($isApplied)<th class="w-8 px-2 py-3"></th>@endunless
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :data-row-idx="i" :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-2 py-2.5" :colspan="row.is_note ? {{ $isApplied ? 6 : 7 }} : 1">
                                    <div class="flex items-center gap-2">
                                        <flux:icon.chat-bubble-left x-show="row.is_note" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        @if($isApplied)
                                            <span class="block px-1 py-1.5 text-sm font-semibold text-zinc-900 dark:text-white" :class="row.is_note ? 'italic font-normal' : ''" x-text="row.details"></span>
                                        @else
                                            <input
                                                type="text"
                                                data-row-details
                                                x-model="row.details"
                                                :placeholder="row.is_note ? '{{ __('Note…') }}' : '{{ __('Description…') }}'"
                                                :class="row.is_note ? 'italic' : ''"
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 placeholder:font-normal placeholder:text-zinc-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                            />
                                        @endif
                                    </div>
                                </td>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        @if($isApplied)
                                            <span class="font-mono tabular-nums text-zinc-700 dark:text-zinc-300" x-text="row.quantity"></span>
                                        @else
                                            <input type="number" min="0.01" step="0.01" data-row-qty x-model.number="row.quantity"
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                        @endif
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        @if($isApplied)
                                            <span class="font-mono tabular-nums text-zinc-700 dark:text-zinc-300">£<span x-text="Number(row.price).toFixed(2)"></span></span>
                                        @else
                                            <input type="number" min="0" step="0.01" x-model.number="row.price"
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                        @endif
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        @if($isApplied)
                                            <span class="text-zinc-700 dark:text-zinc-300" x-text="row.per || '—'"></span>
                                        @else
                                            <select x-model="row.per" @change.stop
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white">
                                                <option value="">—</option>
                                                @foreach($units as $unit)
                                                    <option value="{{ $unit }}">{{ $unit }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        @if($isApplied)
                                            <span class="text-zinc-700 dark:text-zinc-300" x-text="row.vat_applicable ? 'Yes' : 'No'"></span>
                                        @else
                                            <select x-model.boolean="row.vat_applicable" @change.stop
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white">
                                                <option :value="true">Yes</option>
                                                <option :value="false">No</option>
                                            </select>
                                        @endif
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5 text-right">
                                        @if($isApplied)
                                            <span class="font-mono tabular-nums text-zinc-700 dark:text-zinc-300"><span x-text="Number(row.discount_percent || 0).toFixed(2)"></span>%</span>
                                        @else
                                            <input type="number" min="0" max="100" step="0.01" x-model.number="row.discount_percent"
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                        @endif
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">
                                        £<span x-text="netValue(row).toFixed(2)">0.00</span>
                                    </td>
                                </template>
                                @unless($isApplied)
                                    <td class="px-2 py-2.5">
                                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="remove(i)" x-show="rows.length > 1" />
                                    </td>
                                @endunless
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
            <x-ui.back-button :fallback="route('supplier-debit-notes.show', $debitNote)" confirm data-form-nav />
            <flux:button variant="primary" type="submit" data-form-nav>Save Changes</flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
