<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupUnit;
use App\Services\DocumentNumberGenerator;
use App\Services\DocumentTotalsCalculator;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New Delivery Note')] class extends Component {
    public ?int $customer_id = null;
    public string $customerName = '';
    public string $doc_date = '';
    public string $order_no = '';
    public bool $show_pricing = false;
    public array $items = [];
    public array $units = [];

    public function mount(): void
    {
        $this->doc_date = now()->format('Y-m-d');
        $this->items = [
            ['details' => '', 'quantity' => '', 'price' => '', 'per' => '', 'is_note' => false],
        ];
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();

        // Pre-select customer from query string
        if (request()->has('customer_id')) {
            $this->customer_id = (int) request('customer_id');
            if ($customer = Customer::find($this->customer_id)) {
                $this->customerName = $customer->company_name;
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'doc_date' => 'required|date',
            'order_no' => 'nullable|string|max:100',
            'show_pricing' => 'boolean',
            'items' => 'required|array|min:1',
            'items.*.details' => 'required|string|max:500',
            'items.*.is_note' => 'boolean',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.price' => 'nullable|numeric|min:0',
        ]);

        foreach ($this->items as $i => $item) {
            if (empty($item['is_note']) && (float) ($item['quantity'] ?? 0) < 0.01) {
                $this->addError("items.{$i}.quantity", __('Quantity is required.'));

                return;
            }
        }

        $generator = new DocumentNumberGenerator();
        $docNumber = $generator->nextFor('DN');

        $customer = Customer::findOrFail($this->customer_id);
        $totals = $this->show_pricing
            ? (new DocumentTotalsCalculator())->calculate(collect($this->items), $customer)
            : ['subtotal' => 0, 'discount' => 0, 'discount_amount' => 0, 'vat' => 0, 'total' => 0];

        $document = Document::create([
            'customer_id' => $this->customer_id,
            'type' => 'DN',
            'doc_number' => $docNumber,
            'doc_date' => $this->doc_date,
            'order_no' => $this->order_no ?: null,
            'subtotal' => $totals['subtotal'],
            'trade_discount' => $totals['discount'],
            'discount_amount' => $totals['discount_amount'],
            'vat_amount' => $totals['vat'],
            'total_value' => $totals['total'],
            'show_pricing' => $this->show_pricing,
            'created_by' => Auth::id(),
        ]);

        foreach ($this->items as $item) {
            $isNote = ! empty($item['is_note']);
            $qty = $isNote ? 0 : (float) ($item['quantity'] ?? 0);
            $price = ($isNote || ! $this->show_pricing) ? 0 : (float) ($item['price'] ?? 0);

            $document->items()->create([
                'details' => $item['details'],
                'is_note' => $isNote,
                'quantity' => $qty,
                'price' => $price,
                'per' => $isNote ? null : ($item['per'] ?: null),
                'line_value' => round($qty * $price, 2),
            ]);
        }

        Flux::toast(variant: 'success', text: __('Delivery note :number created.', ['number' => $docNumber]));

        $this->redirect(route('delivery-notes.show', $document), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="New Delivery Note"
        subtitle="Create a delivery document for a customer."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('delivery-notes.index')" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <form
        x-data="{
            rows: @js($items),
            units: @js($this->units),
            add() { this.rows.push({ details: '', quantity: '', price: '', per: '', is_note: false }); this.$nextTick(() => this.focusLast()); },
            addNote() { this.rows.push({ details: '', quantity: '0', price: '', per: '', is_note: true }); this.$nextTick(() => this.focusLast()); },
            remove(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
            focusLast() {
                const inputs = this.$refs.rowsBody.querySelectorAll('input[data-row-details]');
                if (inputs.length) inputs[inputs.length - 1].focus();
            },
            submit() { $wire.set('items', this.rows, false); $wire.save(); },
        }"
        x-on:submit.prevent="submit()"
        x-on:keydown.enter="
            if ($event.target.tagName === 'INPUT' && $event.target.closest('[data-items-table]')) {
                $event.preventDefault();
                add();
            }
        "
        class="flex flex-col gap-6 max-w-5xl"
    >

        {{-- Header details --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Document</p>
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
            </div>
            <div class="grid gap-4 p-6 md:grid-cols-2">
                <livewire:pages::ui.typeahead
                    wire:model.live="customer_id"
                    model="App\Models\Customer"
                    column="company_name"
                    :label="__('Customer')"
                    :placeholder="__('Search customer (3+ letters)…')"
                    :selected-label="$customerName"
                    error-name="customer_id"
                    required
                />
                <flux:input wire:model="doc_date" type="date" :label="__('Delivery Date')" required />
                <flux:input wire:model="order_no" :label="__('Order Reference')" :placeholder="__('Optional')" />
                <div class="md:col-span-2">
                    <flux:switch
                        wire:model.live="show_pricing"
                        :label="__('Show pricing on this delivery note')"
                        :description="__('Adds Price/Value columns and totals to the document and PDF.')"
                    />
                </div>
            </div>
        </div>

        {{-- Line Items --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Items</h2>
                <div class="flex items-center gap-2">
                    <flux:button type="button" variant="ghost" icon="chat-bubble-left" size="sm" x-on:click="addNote()">
                        Add Note
                    </flux:button>
                    <flux:button type="button" variant="ghost" icon="plus" size="sm" x-on:click="add()">
                        Add Item
                    </flux:button>
                </div>
            </div>

            <div class="overflow-x-auto" data-items-table>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Details</th>
                            <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th x-show="$wire.show_pricing" class="w-28 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Price</th>
                            <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                            <th x-show="$wire.show_pricing" class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Line Value</th>
                            <th class="w-10 px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-4 py-2.5" :colspan="row.is_note ? ($wire.show_pricing ? 5 : 3) : 1">
                                    <div class="flex items-center gap-2">
                                        <flux:icon.chat-bubble-left x-show="row.is_note" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <input
                                            type="text"
                                            data-row-details
                                            x-model="row.details"
                                            :placeholder="row.is_note ? '{{ __('Note…') }}' : '{{ __('Description…') }}'"
                                            :class="row.is_note ? 'italic' : ''"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </div>
                                </td>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5">
                                        <input
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            x-model="row.quantity"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td x-show="$wire.show_pricing" class="px-4 py-2.5">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            x-model.number="row.price"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5">
                                        <select
                                            x-model="row.per"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        >
                                            <option value=""></option>
                                            <template x-for="unit in units" :key="unit">
                                                <option :value="unit" x-text="unit"></option>
                                            </template>
                                        </select>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td x-show="$wire.show_pricing" class="px-4 py-2.5 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">
                                        £<span x-text="(Number(row.quantity || 0) * Number(row.price || 0)).toFixed(2)">0.00</span>
                                    </td>
                                </template>
                                <td class="px-4 py-2.5">
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
        </div>

        {{-- Form actions --}}
        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-6 py-4 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <flux:button variant="ghost" :href="route('delivery-notes.index')" wire:navigate type="button">Cancel</flux:button>
            <flux:button variant="primary" type="submit">Create Delivery Note</flux:button>
        </div>
    </form>

</div>
