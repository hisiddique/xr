<?php

use App\Livewire\Concerns\NormalizesUnitCase;
use App\Livewire\Concerns\ValidatesDocumentItems;
use App\Models\LookupUnit;
use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayoutAllocation;
use App\Services\DocumentTotalsCalculator;
use App\Services\SupplierInvoiceTotalsCalculator;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Debit Note')] class extends Component {
    use NormalizesUnitCase, ValidatesDocumentItems;

    public SupplierDebitNote $debitNote;

    public string $doc_date = '';

    public ?int $supplier_invoice_id = null;

    public string $notes = '';

    public bool $is_internal = false;

    public array $items = [];

    public array $units = [];

    public array $invoiceItemSuggestions = [];

    public float $vatRate = 20.0;

    public function mount(): void
    {
        $this->debitNote->load(['supplier', 'supplierInvoice', 'items']);
        $this->doc_date = $this->debitNote->doc_date->format('Y-m-d');
        $this->supplier_invoice_id = $this->debitNote->supplier_invoice_id;
        $this->notes = $this->debitNote->notes ?? '';
        $this->is_internal = (bool) $this->debitNote->is_internal;
        $this->vatRate = (float) ($this->debitNote->vat_rate ?? Setting::get('vat_rate', 20));
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
            'net_override' => $item->net_override !== null ? (float) $item->net_override : null,
            'vat_override' => $item->vat_override !== null ? (float) $item->vat_override : null,
        ])->toArray();
    }

    public function updatedIsInternal(): void
    {
        if ($this->is_internal) {
            $this->supplier_invoice_id = null;
        }
    }

    #[Computed]
    public function hasLinkedRecords(): bool
    {
        return $this->debitNote->appliedInvoices()->exists()
            || SupplierPayoutAllocation::where('supplier_debit_note_id', $this->debitNote->id)->exists();
    }

    #[Computed]
    public function supplierInvoices(): array
    {
        return SupplierInvoice::where('supplier_id', $this->debitNote->supplier_id)
            ->where(fn ($q) => $q
                ->where('id', $this->debitNote->supplier_invoice_id)
                ->orWhereRaw('
                    (SELECT COALESCE(SUM(line_total), 0) FROM supplier_invoice_items WHERE supplier_invoice_id = supplier_invoices.id AND deleted_at IS NULL)
                    - COALESCE(supplier_invoices.discount_amount, 0)
                    - (SELECT COALESCE(SUM(allocated_amount), 0) FROM supplier_payout_allocations WHERE supplier_invoice_id = supplier_invoices.id)
                    - (SELECT COALESCE(SUM(applied_amount), 0) FROM supplier_invoice_debit_notes WHERE supplier_invoice_id = supplier_invoices.id)
                    > 0.001
                '))
            ->orderByDesc('invoice_date')
            ->get(['id', 'supplier_invoice_no', 'supplier_ref_invoice_no'])
            ->toArray();
    }

    public function save(): void
    {
        if ($this->hasLinkedRecords) {
            $this->is_internal = (bool) $this->debitNote->is_internal;
        }

        if ($this->is_internal) {
            $this->supplier_invoice_id = null;
        }

        $this->items = array_values(array_filter($this->items, fn ($i) => trim((string) ($i['details'] ?? '')) !== ''
            || (float) ($i['quantity'] ?? 0) > 0
            || (float) ($i['price'] ?? 0) > 0
            || trim((string) ($i['per'] ?? '')) !== ''
        ));

        $rules = [
            'doc_date' => 'required|date',
            'supplier_invoice_id' => ['nullable', 'integer', Rule::exists('supplier_invoices', 'id')->where('supplier_id', $this->debitNote->supplier_id)],
            'notes' => 'nullable|string|max:1000',
            'items.*.vat_applicable' => 'boolean',
            'items.*.net_override' => 'nullable|numeric|min:0',
            'items.*.vat_override' => 'nullable|numeric|min:0',
        ] + $this->documentItemRules();

        $this->validate($rules, $this->documentItemMessages());

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

            $lineValue = $isNote ? 0.0 : Money::roundDown(DocumentTotalsCalculator::lineValue(['quantity' => $qty, 'price' => $price, 'per' => $per]));
            $netOverride = $isNote || ($item['net_override'] ?? '') === '' ? null : (float) $item['net_override'];
            $vatOverride = $isNote || ($item['vat_override'] ?? '') === '' ? null : (float) $item['vat_override'];
            ['net' => $net, 'vat' => $itemVat] = $isNote
                ? ['net' => 0.0, 'vat' => 0.0]
                : SupplierInvoiceTotalsCalculator::debitNoteLine($lineValue, $discountPercent, $vatApplicable, (bool) $this->debitNote->vat_inclusive, $this->vatRate, $netOverride, $vatOverride);

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
                'net_override' => $netOverride,
                'vat_override' => $vatApplicable ? $vatOverride : null,
                'vat_amount' => $itemVat,
                'total' => $net,
                'sort_order' => $idx,
            ];
        }

        $subtotal = round($subtotal, 2);
        $vatAmount = round($vatAmount, 2);

        $newInvoiceId = $this->supplier_invoice_id ?: null;
        $previousInvoiceIds = $this->debitNote->appliedInvoices()->pluck('supplier_invoices.id')->all();
        $invoiceChanged = $newInvoiceId !== $this->debitNote->supplier_invoice_id;

        DB::transaction(function () use ($computed, $subtotal, $vatAmount, $newInvoiceId, $previousInvoiceIds, $invoiceChanged) {
            $this->debitNote->update([
                'doc_date' => $this->doc_date,
                'supplier_invoice_id' => $newInvoiceId,
                'notes' => $this->notes ?: null,
                'is_internal' => $this->is_internal,
                'vat_rate' => $this->vatRate,
                'subtotal' => $subtotal,
                'vat_amount' => $vatAmount,
                'total' => round($subtotal + $vatAmount, 2),
            ]);

            $this->debitNote->items()->delete();
            foreach ($computed as $row) {
                $this->debitNote->items()->create($row);
            }

            if ($invoiceChanged) {
                $this->debitNote->appliedInvoices()->detach();

                if ($newInvoiceId !== null) {
                    $this->debitNote->appliedInvoices()->attach($newInvoiceId, ['applied_amount' => 0, 'applied_at' => now()]);
                }
            }

            SupplierInvoice::whereIn('id', array_filter(array_unique([...$previousInvoiceIds, $newInvoiceId])))
                ->get()
                ->each->resyncDebitNoteDeductions();
        });

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

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('supplier-debit-notes.show', $debitNote) }}', { @if($debitNote->vat_inclusive)vatInclusive: true, @endif netVatOverrides: true, line: { discount_percent: null, vat_applicable: true, net_override: null, vat_override: null } })"
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
                @if(! $is_internal)
                    <div>
                        <flux:label>{{ __('Against Supplier Invoice') }}</flux:label>
                        <flux:select wire:model="supplier_invoice_id" class="mt-1.5" x-on:focus="$el.showPicker?.()">
                            <flux:select.option value="">— None —</flux:select.option>
                            @foreach($this->supplierInvoices as $inv)
                                <flux:select.option :value="$inv['id']">{{ $inv['supplier_invoice_no'] }}@if(! empty($inv['supplier_ref_invoice_no'])) ({{ $inv['supplier_ref_invoice_no'] }})@endif</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('supplier_invoice_id') <flux:error>{{ $message }}</flux:error> @enderror
                    </div>
                @endif
                <div class="md:col-span-2">
                    <flux:input wire:model="notes" :label="__('Notes')" :placeholder="__('Optional notes for this debit note…')" />
                    @error('notes') <flux:error>{{ $message }}</flux:error> @enderror
                </div>
                <div class="md:col-span-2">
                    <flux:checkbox wire:model.live="is_internal" :disabled="$this->hasLinkedRecords" :label="__('Internal (record only)')" />
                </div>
            </div>
        </div>

        {{-- Line Items --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Items</h2>
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
                            <th class="w-28 px-2 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT Val.</th>
                            <th class="w-8 px-2 py-3"></th>
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :data-row-idx="i" :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-2 py-2.5" :colspan="row.is_note ? 8 : 1">
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
                                        <input type="number" min="0.01" step="0.01" data-row-qty x-model.number="row.quantity"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <input type="number" min="0" step="0.01" x-model.number="row.price"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <select x-model="row.per" @change.stop
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white">
                                            <option value="">—</option>
                                            @foreach($units as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <select x-model.boolean="row.vat_applicable" @change.stop="if (! row.vat_applicable) row.vat_override = null"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white">
                                            <option value="true">Yes</option>
                                            <option value="false">No</option>
                                        </select>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5 text-right">
                                        <input type="number" min="0" max="100" step="0.01" x-model.number="row.discount_percent"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <input type="number" min="0" step="0.01"
                                            :value="displayAmount(floorNet(row), $el)"
                                            @input="setNet(row, $event.target.value)"
                                            @blur="$el.value = floorNet(row).toFixed(2)"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-right text-sm font-semibold tabular-nums text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                        <span x-show="netEdited(row)" class="mt-0.5 block text-right text-xs text-amber-600 dark:text-amber-400">edited</span>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-2 py-2.5">
                                        <input type="number" min="0" step="0.01"
                                            :disabled="! row.vat_applicable"
                                            x-effect="if (! row.vat_applicable) $el.value = ''"
                                            :value="row.vat_applicable ? displayAmount(vatForRow(row, $wire.vatRate), $el) : ''"
                                            @input="setVat(row, $event.target.value)"
                                            @blur="$el.value = row.vat_applicable ? vatForRow(row, $wire.vatRate).toFixed(2) : ''"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-right text-sm font-semibold tabular-nums text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none disabled:opacity-50 dark:border-white/10 dark:bg-zinc-800 dark:text-white" />
                                        <span x-show="vatEdited(row)" class="mt-0.5 block text-right text-xs text-amber-600 dark:text-amber-400">edited</span>
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
                            <span class="font-mono tabular-nums" x-text="'£' + rows.filter(r => !r.is_note).reduce((s, r) => s + floorNet(r), 0).toFixed(2)"></span>
                        </div>
                        <div class="mt-1 flex justify-between text-sm text-zinc-500 dark:text-zinc-400" x-show="$wire.vatRate > 0 && rows.some(r => !r.is_note && r.vat_applicable)">
                            <span x-text="'VAT (' + $wire.vatRate + '%)'"></span>
                            <span class="font-mono tabular-nums" x-text="'£' + rows.filter(r => !r.is_note && r.vat_applicable).reduce((s, r) => s + vatForRow(r, $wire.vatRate), 0).toFixed(2)"></span>
                        </div>
                        <div class="mt-2 flex justify-between border-t border-zinc-200 pt-2 dark:border-white/10">
                            <span class="font-semibold text-zinc-900 dark:text-white">Total Debit</span>
                            <span class="font-mono tabular-nums font-semibold text-zinc-900 dark:text-white" x-text="'£' + (function() { const sub = rows.filter(r => !r.is_note).reduce((s, r) => s + floorNet(r), 0); const vat = rows.filter(r => !r.is_note && r.vat_applicable).reduce((s, r) => s + vatForRow(r, $wire.vatRate), 0); return (sub + vat).toFixed(2); })()"></span>
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
