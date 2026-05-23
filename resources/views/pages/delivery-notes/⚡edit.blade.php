<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupUnit;
use App\Services\DocumentTotalsCalculator;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Delivery Note')] class extends Component {
    public Document $document;

    public ?int $customer_id = null;
    public string $customerName = '';
    public ?int $assigned_to = null;
    public string $assigneeName = '';
    public string $doc_date = '';
    public string $due_by = '';
    public string $order_no = '';
    public bool $show_pricing = false;
    public array $items = [];
    public array $units = [];

    public function mount(): void
    {
        $this->customer_id = $this->document->customer_id;
        $this->customerName = $this->document->customer?->company_name ?? '';
        $this->assigned_to = $this->document->assigned_to ?? $this->document->created_by;
        $this->assigneeName = $this->document->assignee?->name ?? $this->document->creator?->name ?? '';
        $this->doc_date = $this->document->doc_date->format('Y-m-d');
        $this->due_by = $this->document->due_by?->format('Y-m-d') ?? '';
        $this->order_no = $this->document->order_no ?? '';
        $this->show_pricing = (bool) $this->document->show_pricing;
        $this->items = $this->document->items->map(fn ($item) => [
            'id' => $item->id,
            'details' => $item->details,
            'is_note' => (bool) $item->is_note,
            'quantity' => (string) $item->quantity,
            'price' => (string) $item->price,
            'per' => $item->per ?? '',
        ])->toArray();
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
    }

    public function save(): void
    {
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'doc_date' => 'required|date',
            'due_by' => 'nullable|date',
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

        $customer = Customer::findOrFail($this->customer_id);
        $totals = (new DocumentTotalsCalculator())->calculate(collect($this->items), $customer);

        $this->document->update([
            'customer_id' => $this->customer_id,
            'doc_date' => $this->doc_date,
            'due_by' => $this->due_by ?: null,
            'order_no' => $this->order_no ?: null,
            'subtotal' => $totals['subtotal'],
            'trade_discount' => $totals['discount'],
            'discount_amount' => $totals['discount_amount'],
            'vat_amount' => $totals['vat'],
            'total_value' => $totals['total'],
            'show_pricing' => $this->show_pricing,
            'assigned_to' => $this->assigned_to,
        ]);

        // Sync items: delete old, create new
        $this->document->items()->delete();
        foreach ($this->items as $item) {
            $isNote = ! empty($item['is_note']);
            $qty = $isNote ? 0 : (float) ($item['quantity'] ?? 0);
            $price = $isNote ? 0 : (float) ($item['price'] ?? 0);
            $per = $isNote ? null : ($item['per'] ?: null);

            $this->document->items()->create([
                'details' => $item['details'],
                'is_note' => $isNote,
                'quantity' => $qty,
                'price' => $price,
                'per' => $per,
                'line_value' => $isNote ? 0 : round(\App\Services\DocumentTotalsCalculator::lineValue(['quantity' => $qty, 'price' => $price, 'per' => $per]), 2),
            ]);
        }

        Flux::toast(variant: 'success', text: __('Delivery note updated.'));

        $this->redirect(route('delivery-notes.show', $this->document), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        :title="'Edit: '.$document->doc_number"
        subtitle="Update the delivery note details and line items."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('delivery-notes.show', $document)" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('delivery-notes.show', $document) }}')"
        x-on:submit.prevent="submit()"
        x-on:keydown="handleKey($event)"
        x-on:exit-confirm-discard.window="cancel()"
        x-on:exit-confirm-save.window="submit()"
        class="flex min-w-0 flex-1 flex-col gap-4"
    >

        {{-- Header details --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Document</p>
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
            </div>
            <div data-form-grid class="grid gap-4 p-4 md:grid-cols-2">
                <livewire:pages::ui.typeahead
                    :key="'typeahead-customer'"
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
                <flux:input wire:model="due_by" type="date" :label="__('Due By')" />
                <flux:input wire:model="order_no" :label="__('Order Reference')" :placeholder="__('Optional')" />
                <livewire:pages::ui.typeahead
                    :key="'typeahead-assignee'"
                    wire:model.live="assigned_to"
                    model="App\Models\User"
                    column="name"
                    :label="__('Assigned To')"
                    :placeholder="__('Search user (3+ letters)…')"
                    :selected-label="$assigneeName"
                    error-name="assigned_to"
                />
                <label class="flex items-center gap-3 pt-7">
                    <flux:switch wire:model.live="show_pricing" />
                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Show prices on PDF / email') }}</span>
                </label>
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
                    <flux:button type="button" variant="ghost" icon="plus" size="sm" x-on:click="add()">Add Item</flux:button>
                </div>
            </div>

            <datalist id="units-options">
                <template x-for="unit in units" :key="unit">
                    <option :value="unit"></option>
                </template>
            </datalist>
            <div class="overflow-x-auto" data-items-table>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Details</th>
                            <th class="w-36 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th class="w-40 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Price</th>
                            <th class="w-36 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                            <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Line Value</th>
                            <th class="w-10 px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :data-row-idx="i" :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-4 py-2.5" :colspan="row.is_note ? 5 : 1">
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
                                    <td class="px-4 py-2.5">
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
                                        <input
                                            type="text"
                                            x-model="row.per"
                                            list="units-options"
                                            placeholder="e.g. kg or 1000"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">
                                        £<span x-text="lineValue(row).toFixed(2)">0.00</span>
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
        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <x-ui.back-button :fallback="route('delivery-notes.show', $document)" />
            <flux:button variant="primary" type="submit">Save Changes <x-ui.kbd-hint keys="Ctrl+↵" /></flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
