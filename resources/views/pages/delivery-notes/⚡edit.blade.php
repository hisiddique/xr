<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupUnit;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Delivery Note')] class extends Component {
    public Document $document;

    public int $customer_id = 0;
    public string $doc_date = '';
    public array $items = [];
    public array $units = [];

    public function mount(): void
    {
        $this->customer_id = $this->document->customer_id;
        $this->doc_date = $this->document->doc_date->format('Y-m-d');
        $this->items = $this->document->items->map(fn ($item) => [
            'id' => $item->id,
            'details' => $item->details,
            'is_note' => (bool) $item->is_note,
            'quantity' => (string) $item->quantity,
            'per' => $item->per ?? '',
        ])->toArray();
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
    }

    public function save(): void
    {
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'doc_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.details' => 'required|string|max:500',
            'items.*.is_note' => 'boolean',
            'items.*.quantity' => 'nullable|numeric|min:0',
        ]);

        foreach ($this->items as $i => $item) {
            if (empty($item['is_note']) && (float) ($item['quantity'] ?? 0) < 0.01) {
                $this->addError("items.{$i}.quantity", __('Quantity is required.'));

                return;
            }
        }

        $this->document->update([
            'customer_id' => $this->customer_id,
            'doc_date' => $this->doc_date,
            'subtotal' => 0,
            'trade_discount' => 0,
            'discount_amount' => 0,
            'vat_amount' => 0,
            'total_value' => 0,
        ]);

        // Sync items: delete old, create new
        $this->document->items()->delete();
        foreach ($this->items as $item) {
            $isNote = ! empty($item['is_note']);

            $this->document->items()->create([
                'details' => $item['details'],
                'is_note' => $isNote,
                'quantity' => $isNote ? 0 : $item['quantity'],
                'price' => 0,
                'per' => $isNote ? null : ($item['per'] ?: null),
                'line_value' => 0,
            ]);
        }

        Flux::toast(variant: 'success', text: __('Delivery note updated.'));

        $this->redirect(route('delivery-notes.show', $this->document), navigate: true);
    }

    #[Computed]
    public function customers()
    {
        return Customer::orderBy('company_name')->get(['id', 'company_name']);
    }
}; ?>

<div class="flex flex-col gap-8">

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

    <form
        x-data="{
            rows: @js($items),
            units: @js($this->units),
            add() { this.rows.push({ details: '', quantity: '1', per: '', is_note: false }); this.$nextTick(() => this.focusLast()); },
            addNote() { this.rows.push({ details: '', quantity: '0', per: '', is_note: true }); this.$nextTick(() => this.focusLast()); },
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
                <div>
                    <flux:label>{{ __('Customer') }} <span class="text-rose-500">*</span></flux:label>
                    <flux:select wire:model="customer_id">
                        <flux:select.option value="">{{ __('— Select customer —') }}</flux:select.option>
                        @foreach($this->customers as $c)
                            <flux:select.option :value="$c->id">{{ $c->company_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="customer_id" />
                </div>
                <flux:input wire:model="doc_date" type="date" :label="__('Delivery Date')" required />
            </div>
        </div>

        {{-- Line Items --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Items</h2>
                <div class="flex items-center gap-2">
                    <flux:button type="button" variant="ghost" icon="chat-bubble-left" size="sm" x-on:click="addNote()">Add Note</flux:button>
                    <flux:button type="button" variant="ghost" icon="plus" size="sm" x-on:click="add()">Add Item</flux:button>
                </div>
            </div>

            <div class="overflow-x-auto" data-items-table>
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Details</th>
                            <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th class="w-28 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                            <th class="w-10 px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-4 py-2.5" :colspan="row.is_note ? 3 : 1">
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
        </div>

        {{-- Form actions --}}
        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-6 py-4 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <flux:button variant="ghost" :href="route('delivery-notes.show', $document)" wire:navigate type="button">Cancel</flux:button>
            <flux:button variant="primary" type="submit">Save Changes</flux:button>
        </div>
    </form>

</div>
