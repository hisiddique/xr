<?php

use App\Models\Document;
use App\Models\LookupUnit;
use App\Services\DocumentTotalsCalculator;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Invoice')] class extends Component {
    public Document $document;

    public string $doc_date = '';
    public string $order_no = '';
    public ?int $assigned_to = null;
    public string $assigneeName = '';
    public array $items = [];
    public array $units = [];
    public bool $emailAfterSave = false;

    public function mount(): void
    {
        $this->doc_date = $this->document->doc_date->format('Y-m-d');
        $this->order_no = $this->document->order_no ?? '';
        $this->assigned_to = $this->document->assigned_to ?? $this->document->created_by;
        $this->assigneeName = $this->document->assignee?->name ?? $this->document->creator?->name ?? '';
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
            'doc_date' => 'required|date',
            'assigned_to' => 'nullable|integer|exists:users,id',
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

        $customer = $this->document->customer;
        $calculator = new DocumentTotalsCalculator();
        $totals = $calculator->calculate(collect($this->items), $customer);

        $this->document->update([
            'doc_date' => $this->doc_date,
            'order_no' => $this->order_no ?: null,
            'assigned_to' => $this->assigned_to,
            'subtotal' => $totals['subtotal'],
            'trade_discount' => $totals['discount'],
            'discount_amount' => $totals['discount_amount'],
            'vat_amount' => $totals['vat'],
            'total_value' => $totals['total'],
        ]);

        // Sync items: delete old, create new
        $this->document->items()->delete();
        foreach ($this->items as $item) {
            $isNote = ! empty($item['is_note']);
            $qty = $isNote ? 0 : (float) $item['quantity'];
            $price = $isNote ? 0 : (float) $item['price'];
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

        if ($this->emailAfterSave) {
            $this->emailAfterSave = false;
            Flux::toast(variant: 'success', text: __('Invoice updated.'));
            $this->dispatch('open-email-modal');
        } else {
            Flux::toast(variant: 'success', text: __('Invoice updated.'));
            $this->redirect(route('invoices.show', $this->document), navigate: true);
        }
    }

    public function saveAndEmail(): void
    {
        $this->emailAfterSave = true;
        $this->save();
    }
}; ?>

<div
    class="flex flex-col gap-4"
    x-data
    x-on:open-email-modal.window="$flux.modal('email-document-{{ $document->id }}').show()"
>

    <x-ui.page-header
        :title="'Edit: '.$document->doc_number"
        subtitle="Update the invoice details and line items."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('invoices.show', $document)" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    {{-- Converted-from badge --}}
    @if($document->convertedFrom)
        <div class="flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 dark:border-violet-500/20 dark:bg-violet-500/10">
            <flux:icon.arrow-path class="size-4 text-violet-600 dark:text-violet-400" />
            <span class="text-sm text-violet-700 dark:text-violet-300">
                Converted from delivery note
                <a href="{{ route('delivery-notes.show', $document->convertedFrom) }}" wire:navigate class="font-semibold underline hover:no-underline">
                    {{ $document->convertedFrom->doc_number }}
                </a>
            </span>
        </div>
    @endif

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('invoices.show', $document) }}', { line: { quantity: '1', price: '0.00' }, note: { price: '0.00' } })"
        x-on:submit.prevent="submit()"
        x-on:keydown="handleKey($event)"
        x-on:exit-confirm-discard.window="cancel()"
        x-on:exit-confirm-save.window="submit()"
        class="flex min-w-0 flex-1 flex-col gap-4"
    >

        {{-- Header details --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Invoice</p>
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
            </div>
            <div data-form-grid class="grid gap-4 p-4 md:grid-cols-2">
                {{-- Customer read-only --}}
                <div>
                    <flux:label>{{ __('Customer') }}</flux:label>
                    <div
                        tabindex="0"
                        data-form-stop
                        class="mt-1.5 flex items-center gap-2.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-white/10 dark:bg-zinc-800"
                    >
                        <x-ui.avatar :name="$document->customer->company_name" size="xs" />
                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $document->customer->company_name }}</span>
                        <span class="ml-auto text-xs text-zinc-400">Read-only</span>
                    </div>
                </div>
                <flux:input wire:model="doc_date" type="date" :label="__('Invoice Date')" required />
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
                                            x-model.number="row.quantity"
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
                                        <div class="relative">
                                            <input
                                                type="text"
                                                x-model="row.per"
                                                list="units-options"
                                                placeholder="e.g. kg or 1000"
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                            />
                                            <div
                                                x-show="unitGhostSuffix(row.per)"
                                                x-cloak
                                                class="pointer-events-none absolute inset-y-0 left-0 right-0 flex items-center px-3 text-sm leading-[1.375rem]"
                                            >
                                                <span class="invisible whitespace-pre" x-text="row.per"></span>
                                                <span class="text-zinc-400 dark:text-zinc-500 whitespace-pre" x-text="unitGhostSuffix(row.per)"></span>
                                            </div>
                                        </div>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">
                                        £<span x-text="lineValue(row).toFixed(2)">0.00</span>
                                    </td>
                                </template>
                                <td class="px-4 py-2.5">
                                    <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="remove(i)" x-show="rows.length > 1" data-row-remove />
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

        {{-- Sticky footer bar --}}
        <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
            <x-ui.back-button :fallback="route('invoices.show', $document)" confirm data-form-nav />
            <flux:button variant="filled" type="submit" data-form-nav>Save Changes <x-ui.kbd-hint keys="Ctrl+↵" /></flux:button>
            <flux:button variant="primary" type="button" x-on:click.prevent="submitAndEmail()" icon="envelope" data-form-nav>
                Save &amp; Email
            </flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <livewire:pages::documents.email-modal :document="$document" :key="'email-'.$document->id" />

    <x-ui.exit-confirm-modal />

</div>
