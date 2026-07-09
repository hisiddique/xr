<?php

use App\DocumentStatus;
use App\DocumentType;
use App\Livewire\Concerns\ValidatesDocumentItems;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupUnit;
use App\Services\DocumentNumberGenerator;
use App\Services\DocumentTotalsCalculator;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New Credit Note')] class extends Component {
    use ValidatesDocumentItems;
    public ?int $customer_id = null;
    public string $customerName = '';
    public ?int $assigned_to = null;
    public string $assigneeName = '';
    public string $doc_date = '';
    public string $notes = '';
    public ?int $credited_invoice_id = null;
    public array $items = [];
    public array $units = [];
    public array $users = [];
    public bool $vatRegistered = false;
    public float $vatRate = 20.0;
    public float $creditBalance = 0.0;
    public array $invoiceItemSuggestions = [];

    public function mount(): void
    {
        $this->doc_date = now()->format('Y-m-d');
        $this->assigned_to = Auth::id();
        $this->assigneeName = Auth::user()->name;
        $this->vatRate = (float) \App\Models\Setting::get('vat_rate', 20);
        $this->items = [
            ['id' => null, 'details' => '', 'quantity' => '', 'price' => '', 'per' => '', 'is_note' => false, 'discount_percent' => null],
        ];
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
        $this->users = \App\Models\User::orderBy('name')->get(['id', 'name', 'status'])->toArray();

        if (request()->has('customer_id')) {
            $this->customer_id = (int) request('customer_id');
            if ($customer = Customer::find($this->customer_id)) {
                $this->customerName = $customer->typeahead_label;
                $this->vatRegistered = (bool) $customer->vat_registered;
            }
            $this->creditBalance = Document::availableCreditForCustomer($this->customer_id);
        }
    }

    public function updatedCreditedInvoiceId(?int $value): void
    {
        $this->invoiceItemSuggestions = $this->buildInvoiceSuggestions($value);
    }

    private function buildInvoiceSuggestions(?int $invoiceId): array
    {
        if (! $invoiceId) {
            return [];
        }

        $invoice = Document::invoices()->with('items')->find($invoiceId);

        if (! $invoice) {
            return [];
        }

        return $invoice->items
            ->filter(fn ($item) => ! $item->is_note)
            ->map(fn ($item) => [
                'id'               => null,
                'details'          => $item->details,
                'is_note'          => false,
                'quantity'         => (string) $item->quantity,
                'price'            => (string) $item->price,
                'per'              => $item->per ?? '',
                'discount_percent' => null,
            ])
            ->values()
            ->toArray();
    }

    public function updatedCustomerId(): void
    {
        $this->credited_invoice_id = null;
        $this->invoiceItemSuggestions = [];
        if ($this->customer_id) {
            $customer = Customer::find($this->customer_id);
            $this->vatRegistered = (bool) ($customer?->vat_registered ?? false);
        } else {
            $this->vatRegistered = false;
        }
        $this->creditBalance = $this->customer_id
            ? Document::availableCreditForCustomer($this->customer_id)
            : 0.0;
    }

    #[Computed]
    public function customerInvoices(): array
    {
        if (! $this->customer_id) {
            return [];
        }

        return Document::invoices()
            ->where('customer_id', $this->customer_id)
            ->orderByDesc('doc_date')
            ->get(['id', 'doc_number'])
            ->toArray();
    }

    public function save(): void
    {
        $this->items = array_values(array_filter($this->items, fn ($i) =>
            trim((string) ($i['details'] ?? '')) !== ''
            || (float) ($i['quantity'] ?? 0) > 0
            || (float) ($i['discount_percent'] ?? 0) > 0
        ));

        $this->validate(
            [
                'customer_id'         => 'required|integer|exists:customers,id',
                'assigned_to'         => 'nullable|integer|exists:users,id',
                'doc_date'            => 'required|date',
                'notes'               => 'nullable|string|max:1000',
                'credited_invoice_id' => 'nullable|integer|exists:documents,id',
            ] + $this->documentItemRules(),
            $this->documentItemMessages(),
        );

        $document = $this->persistDocument();

        Flux::toast(
            heading: __('Credit Note Created'),
            text: __('Credit note :number was created successfully.', ['number' => $document->doc_number]),
            variant: 'success',
            duration: 0,
        );
        $this->redirect(route('credit-notes.show', $document), navigate: true);
    }

    private function persistDocument(): Document
    {
        $generator = new DocumentNumberGenerator();
        $docNumber = $generator->nextFor('CN');

        $customer = Customer::findOrFail($this->customer_id);
        $totals = DocumentTotalsCalculator::creditNoteTotal(collect($this->items), $customer);

        $document = Document::create([
            'customer_id'         => $this->customer_id,
            'type'                => DocumentType::CreditNote,
            'doc_number'          => $docNumber,
            'doc_date'            => $this->doc_date,
            'notes'               => $this->notes ?: null,
            'credited_invoice_id' => $this->credited_invoice_id ?: null,
            'subtotal'            => $totals['subtotal'],
            'trade_discount'      => 0,
            'discount_amount'     => 0,
            'vat_amount'          => $totals['vat'],
            'total_value'         => $totals['total'],
            'show_pricing'        => true,
            'status'              => DocumentStatus::Active,
            'created_by'          => Auth::id(),
            'assigned_to'         => $this->assigned_to,
        ]);

        foreach ($this->items as $item) {
            $isNote = ! empty($item['is_note']);
            $qty = $isNote ? 0 : (float) ($item['quantity'] ?? 0);
            $price = $isNote ? 0 : (float) ($item['price'] ?? 0);
            $per = $isNote ? null : ($item['per'] ?: null);
            $discountPercent = $isNote ? 0 : (float) ($item['discount_percent'] ?? 0);
            $lineVal = $isNote ? 0 : round(DocumentTotalsCalculator::lineValue(['quantity' => $qty, 'price' => $price, 'per' => $per]), 2);
            $netValue = $isNote ? 0 : ($discountPercent > 0 ? round($lineVal * ($discountPercent / 100), 2) : $lineVal);

            $document->items()->create([
                'details'          => $item['details'],
                'is_note'          => $isNote,
                'quantity'         => $qty,
                'price'            => $price,
                'per'              => $per,
                'line_value'       => $lineVal,
                'discount_percent' => $discountPercent,
                'net_value'        => $netValue,
            ]);
        }

        return $document;
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="New Credit Note"
        subtitle="Create a credit note for a customer."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('credit-notes.index')" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('credit-notes.index') }}', { line: { discount_percent: null } })"
        x-on:submit.prevent="submit()"
        x-on:keydown="handleKey($event)"
        x-on:exit-confirm-discard.window="cancel()"
        x-on:exit-confirm-save.window="submit()"
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
                <div>
                    <flux:label>{{ __('Available Credit Balance') }}</flux:label>
                    <div class="mt-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                        <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">£{{ number_format($creditBalance, 2) }}</span>
                    </div>
                </div>
                <flux:input wire:model="doc_date" type="date" :label="__('Credit Note Date')" required />
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
                <div>
                    <flux:label>{{ __('Against Invoice') }}</flux:label>
                    <flux:select wire:model.live="credited_invoice_id" class="mt-1.5" :disabled="! $customer_id" x-on:focus="$el.showPicker?.()">
                        <flux:select.option value="">— None —</flux:select.option>
                        @foreach($this->customerInvoices as $inv)
                            <flux:select.option :value="$inv['id']">{{ $inv['doc_number'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('credited_invoice_id') <flux:error>{{ $message }}</flux:error> @enderror
                </div>
                <div>
                    <flux:input wire:model="notes" :label="__('Notes')" :placeholder="__('Optional notes for this credit note…')" />
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
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Details</th>
                            <th class="w-36 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th class="w-40 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Price</th>
                            <th class="w-36 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                            <th class="w-28 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Discount %</th>
                            <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Net Value</th>
                            <th class="w-10 px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        <template x-for="(row, i) in rows" :key="i">
                            <tr :data-row-idx="i" :class="row.is_note ? 'bg-amber-50/50 dark:bg-amber-500/5' : ''">
                                <td class="px-4 py-2.5" :colspan="row.is_note ? 6 : 1">
                                    <div class="relative flex items-center gap-2">
                                        <flux:icon.chat-bubble-left x-show="row.is_note" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <div class="relative flex-1">
                                            <input
                                                type="text"
                                                data-row-details
                                                x-model="row.details"
                                                @focus="thFocus(i)"
                                                @input="thInput($event.target.value, i)"
                                                @keydown="thKeydown($event, i)"
                                                @blur="thBlur()"
                                                :placeholder="row.is_note ? '{{ __('Note…') }}' : '{{ __('Description…') }}'"
                                                :class="row.is_note ? 'italic' : ''"
                                                class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 placeholder:font-normal placeholder:text-zinc-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                            />
                                            <div
                                                x-show="thOpen && thRowIdx === i && thGhostSuffix && !row.is_note"
                                                class="pointer-events-none absolute inset-y-0 left-0 right-0 flex items-center px-3 text-sm leading-[1.375rem]"
                                            >
                                                <span class="invisible whitespace-pre font-semibold" x-text="thSearch"></span>
                                                <span class="truncate whitespace-pre text-zinc-400 dark:text-zinc-500" x-text="thGhostSuffix"></span>
                                                <span class="ms-2 text-[10px] font-medium uppercase tracking-wider text-zinc-400 opacity-70">↵</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5">
                                        <input
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            data-row-qty
                                            x-model.number="row.quantity"
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
                                            <template x-for="unit in units" :key="unit">
                                                <option :value="unit" x-text="unit"></option>
                                            </template>
                                        </select>
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5">
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            x-model.number="row.discount_percent"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </template>
                                <template x-if="! row.is_note">
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">
                                        £<span x-text="netValue(row).toFixed(2)">0.00</span>
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

        {{-- Item typeahead dropdown (teleported to body to escape overflow clipping) --}}
        <template x-teleport="body" wire:ignore>
            <div
                x-show="thOpen && thFiltered.length > 0"
                x-cloak
                x-transition.opacity
                :style="`position:fixed;top:${thPosition.top}px;left:${thPosition.left}px;width:${thPosition.width}px;z-index:9999`"
                class="max-h-64 overflow-auto rounded-md border border-zinc-200 bg-white shadow-lg dark:border-white/10 dark:bg-zinc-900"
            >
                <template x-for="(s, si) in thFiltered" :key="si">
                    <button
                        type="button"
                        @mousedown.prevent="thPick(s)"
                        :class="si === thActiveIdx ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' : ''"
                        class="block w-full cursor-pointer px-3 py-2 text-left text-sm text-zinc-900 transition-colors hover:bg-indigo-50 hover:text-indigo-700 dark:text-white dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300"
                        x-text="s.details"
                    ></button>
                </template>
            </div>
        </template>

        {{-- Credit Totals --}}
        <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Credit Summary</h2>
            </div>
            <div class="p-4">
                <div class="flex flex-col gap-3">
                    <div class="border-t border-zinc-100 pt-3 dark:border-white/[0.06]">
                        <div class="flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                            <span>Items net subtotal</span>
                            <span class="font-mono tabular-nums" x-text="'£' + rows.filter(r => !r.is_note).reduce((s, r) => s + netValue(r), 0).toFixed(2)"></span>
                        </div>
                        <div class="mt-1 flex justify-between text-sm text-zinc-500 dark:text-zinc-400" x-show="$wire.vatRegistered && $wire.vatRate > 0">
                            <span x-text="'VAT (' + $wire.vatRate + '%)'"></span>
                            <span class="font-mono tabular-nums" x-text="'£' + (rows.filter(r => !r.is_note).reduce((s, r) => s + netValue(r), 0) * ($wire.vatRate / 100)).toFixed(2)"></span>
                        </div>
                        <div class="mt-2 flex justify-between border-t border-zinc-200 pt-2 dark:border-white/10">
                            <span class="font-semibold text-zinc-900 dark:text-white">Total Credit</span>
                            <span class="font-mono tabular-nums font-semibold text-zinc-900 dark:text-white" x-text="'£' + (function() { const sub = rows.filter(r => !r.is_note).reduce((s, r) => s + netValue(r), 0); const vat = $wire.vatRegistered ? sub * ($wire.vatRate / 100) : 0; return (sub + vat).toFixed(2); })()"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sticky footer bar --}}
        <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
            <x-ui.back-button :fallback="route('credit-notes.index')" confirm data-form-nav />
            <flux:button variant="primary" type="submit" data-form-nav>Create Credit Note</flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
