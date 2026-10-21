<?php

use App\Actions\CloneInvoice;
use App\DocumentType;
use App\Livewire\Concerns\ValidatesDocumentItems;
use App\Models\Document;
use App\Models\LookupUnit;
use App\Models\Setting;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Clone Invoice')] class extends Component {
    use ValidatesDocumentItems;
    public Document $document;

    public string $doc_date = '';
    public string $order_no = '';
    public ?int $assigned_to = null;
    public string $assigneeName = '';
    public array $items = [];
    public array $units = [];
    public array $users = [];

    public function mount(): void
    {
        abort_unless($this->document->type === DocumentType::Invoice, 404);
        abort_unless(Setting::get('clone_invoices_enabled', true), 403);

        $this->doc_date = now()->format('Y-m-d');
        $this->order_no = $this->document->order_no ?? '';
        $this->assigned_to = $this->document->assigned_to ?? $this->document->created_by;
        $this->assigneeName = $this->document->assignee?->name ?? $this->document->creator?->name ?? '';
        $this->items = $this->document->items->map(fn ($item) => [
            'details' => $item->details,
            'is_note' => (bool) $item->is_note,
            'quantity' => (string) $item->quantity,
            'price' => (string) $item->price,
            'per' => $item->per ?? '',
        ])->values()->toArray();
        $this->units = LookupUnit::orderBy('name')->get(['id', 'name'])->pluck('name')->toArray();
        $this->users = \App\Models\User::orderBy('name')->get(['id', 'name', 'status'])->toArray();
    }

    public function save(): void
    {
        $this->items = array_values(array_filter($this->items, fn ($i) => trim((string) ($i['details'] ?? '')) !== ''
            || (float) ($i['quantity'] ?? 0) > 0
            || (float) ($i['price'] ?? 0) > 0
        ));

        $this->validate(
            [
                'doc_date' => 'required|date',
                'assigned_to' => 'nullable|integer|exists:users,id',
            ] + $this->documentItemRules(),
            $this->documentItemMessages(),
        );

        $invoice = app(CloneInvoice::class)->handle($this->document, [
            'doc_date' => $this->doc_date,
            'order_no' => $this->order_no ?: null,
            'assigned_to' => $this->assigned_to,
        ], $this->items);

        Flux::toast(
            heading: __('Invoice Cloned'),
            text: __('Invoice :number cloned from :source.', ['number' => $invoice->doc_number, 'source' => $this->document->doc_number]),
            variant: 'success',
            duration: 0,
        );

        $this->redirect(route('invoices.show', $invoice), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        :title="'Clone: '.$document->doc_number"
        subtitle="Review the copy, then submit to create a new delivery note and invoice."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('invoices.show', $document)" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

    <form
        x-data="lineItemForm(@js($items), @js($this->units), '{{ route('invoices.show', $document) }}', { line: { quantity: '1', price: '0.00' }, note: { price: '0.00' } })"
        x-on:submit.prevent="submit()"
        x-on:keydown="handleKey($event)"
        x-on:fkey-add-note.window="addNote()"
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
                    <flux:button type="button" variant="ghost" icon="chat-bubble-left" size="sm" x-on:click="addNote()">Add Note <x-ui.kbd-hint keys="F9" /></flux:button>
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

        {{-- Sticky footer bar --}}
        <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
            <x-ui.back-button :fallback="route('invoices.show', $document)" confirm data-form-nav />
            <flux:button variant="primary" type="submit" data-form-nav>Create Delivery Note &amp; Invoice</flux:button>
        </div>
    </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
