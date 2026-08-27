<?php

use App\Livewire\Concerns\ValidatesDocumentItems;
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
    use ValidatesDocumentItems;
    public ?int $customer_id = null;
    public string $customerName = '';
    public ?int $assigned_to = null;
    public string $assigneeName = '';
    public string $doc_date = '';
    public string $order_no = '';
    public bool $show_pricing = false;
    public array $items = [];
    public array $units = [];
    public array $users = [];

    public function mount(): void
    {
        $this->doc_date = now()->format('Y-m-d');
        $this->assigned_to = Auth::id();
        $this->assigneeName = Auth::user()->name;
        $this->items = [
            ['details' => '', 'quantity' => '', 'price' => '', 'per' => '', 'is_note' => false],
        ];
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
        $this->users = \App\Models\User::orderBy('name')->get(['id', 'name', 'status'])->toArray();

        // Pre-select customer from query string
        if (request()->has('customer_id')) {
            $this->customer_id = (int) request('customer_id');
            if ($customer = Customer::find($this->customer_id)) {
                $this->customerName = $customer->typeahead_label;
            }
        }
    }

    public function save(): void
    {
        if (! $this->passesValidation()) {
            return;
        }

        $this->dispatch('dn-open-finish');
    }

    public function finalize(string $action, bool $showValues): void
    {
        if ($action === 'reject') {
            $this->redirect(route('delivery-notes.index'), navigate: true);

            return;
        }

        $this->show_pricing = $showValues;

        if (! $this->passesValidation()) {
            return;
        }
        $document = $this->persistDocument();

        Flux::toast(
            heading: __('Delivery Note Created'),
            text: __('Delivery note :number was created successfully.', ['number' => $document->doc_number]),
            variant: 'success',
            duration: 0,
        );

        $target = match ($action) {
            'print', 'email', 'emailprint' => route('delivery-notes.show', ['document' => $document, 'do' => $action]),
            default => route('delivery-notes.index'),
        };

        $this->redirect($target, navigate: true);
    }

    private function passesValidation(): bool
    {
        $this->items = array_values(array_filter($this->items, fn ($i) => trim((string) ($i['details'] ?? '')) !== ''
            || (float) ($i['quantity'] ?? 0) > 0
            || (float) ($i['price'] ?? 0) > 0
        ));

        $this->validate(
            [
                'customer_id' => 'required|integer|exists:customers,id',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'doc_date' => 'required|date',
                'order_no' => 'nullable|string|max:100',
                'show_pricing' => 'boolean',
            ] + $this->documentItemRules(),
            $this->documentItemMessages(),
        );

        return true;
    }

    private function persistDocument(): Document
    {
        $generator = new DocumentNumberGenerator();
        $docNumber = $generator->nextFor('DN');

        $customer = Customer::findOrFail($this->customer_id);
        $totals = (new DocumentTotalsCalculator())->calculate(collect($this->items), $customer);

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
            'assigned_to' => $this->assigned_to,
        ]);

        foreach ($this->items as $item) {
            $isNote = ! empty($item['is_note']);
            $qty = $isNote ? 0 : (float) ($item['quantity'] ?? 0);
            $price = $isNote ? 0 : (float) ($item['price'] ?? 0);
            $per = $isNote ? null : ($item['per'] ?: null);

            $document->items()->create([
                'details' => $item['details'],
                'is_note' => $isNote,
                'quantity' => $qty,
                'price' => $price,
                'per' => $per,
                'line_value' => $isNote ? 0 : round(\App\Services\DocumentTotalsCalculator::lineValue(['quantity' => $qty, 'price' => $price, 'per' => $per]), 2),
            ]);
        }

        return $document;
    }
}; ?>

<div class="flex flex-col gap-4">

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

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('delivery-notes.index') }}')"
        x-on:submit.prevent="submit()"
        x-on:keydown="handleKey($event)"
        x-on:fkey-add-note.window="addNote()"
        x-on:exit-confirm-discard.window="cancel()"
        x-on:exit-confirm-save.window="submit()"
        x-on:dn-open-finish.window="$flux.modal('dn-finish').show()"
        x-on:dn-finalize.window="$wire.finalize($event.detail.action, $event.detail.showValues)"
        class="flex min-w-0 flex-1 flex-col gap-4"
    >

        {{-- Header details --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
            </div>
            <div data-form-grid class="grid gap-4 p-4 md:grid-cols-2">
                <livewire:pages::ui.typeahead
                    :key="'typeahead-customer'"
                    wire:model.live="customer_id"
                    model="App\Models\Customer"
                    column="company_name"
                    :search-columns="['company_name', 'first_name', 'last_name', 'reference']"
                    label-accessor="typeahead_label"
                    :min-chars="2"
                    :label="__('Customer')"
                    :placeholder="__('Search customer (2+ letters)…')"
                    :selected-label="$customerName"
                    error-name="customer_id"
                    required
                />
                <flux:input wire:model="doc_date" type="date" :label="__('Delivery Date')" required />
                <flux:input wire:model="order_no" :label="__('Order Reference')" :placeholder="__('Optional')" />
                <div>
                    <flux:label>{{ __('Sales Person') }}</flux:label>
                    <flux:select wire:model="assigned_to" class="mt-1.5" x-on:focus="$el.showPicker?.()">
                        <flux:select.option value="">— None —</flux:select.option>
                        @foreach($users as $user)
                            <flux:select.option :value="$user['id']">{{ $user['name'] }}@if($user['status'] !== 'active') ({{ ucfirst($user['status']) }})@endif</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('assigned_to') <flux:error>{{ $message }}</flux:error> @enderror
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
                    <flux:button type="button" variant="ghost" icon="chat-bubble-left" size="sm" x-on:click="addNote()">
                        Add Note <x-ui.kbd-hint keys="F9" />
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
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 placeholder:font-normal placeholder:text-zinc-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
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
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
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
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5">
                                        <select
                                            x-model="row.per"
                                                                                        class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        >
                                            <option value="">—</option>
                                            @foreach($units as $unit)
                                                <option value="{{ $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
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
            @error('items.*.per') <p class="px-6 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        {{-- Form actions --}}
        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <x-ui.back-button :fallback="route('delivery-notes.index')" confirm data-form-nav />
            <flux:button variant="primary" type="submit" data-form-nav>Create Delivery Note</flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />
    <x-ui.dn-finish-modal />

</div>
