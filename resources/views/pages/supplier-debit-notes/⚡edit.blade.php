<?php

use App\Models\SupplierDebitNote;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Debit Note')] class extends Component {
    public SupplierDebitNote $debitNote;

    public string $doc_date = '';
    public string $notes = '';
    public array $items = [];
    public bool $isApplied = false;

    public function mount(): void
    {
        $this->debitNote->load(['supplier', 'supplierInvoice', 'items']);
        $this->doc_date = $this->debitNote->doc_date->format('Y-m-d');
        $this->notes = $this->debitNote->notes ?? '';
        $this->isApplied = $this->debitNote->isApplied();
        $this->items = $this->debitNote->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity'    => (string) $item->quantity,
            'amount'      => (string) $item->amount,
            'total'       => (float) $item->total,
        ])->toArray();
    }

    public function save(): void
    {
        $rules = [
            'doc_date' => 'required|date',
            'notes'    => 'nullable|string|max:1000',
        ];

        if (! $this->isApplied) {
            $rules = array_merge($rules, [
                'items'               => 'required|array|min:1',
                'items.*.description' => 'required|string|max:500',
                'items.*.quantity'    => 'required|numeric|min:0.01',
                'items.*.amount'      => 'required|numeric|min:0',
            ]);
        }

        $this->validate($rules);

        $this->debitNote->update([
            'doc_date' => $this->doc_date,
            'notes'    => $this->notes ?: null,
        ]);

        if (! $this->isApplied) {
            $items = array_values(array_filter(
                $this->items,
                fn ($i) => trim((string) ($i['description'] ?? '')) !== ''
            ));

            $subtotal = collect($items)->sum(
                fn ($i) => round((float) $i['quantity'] * (float) $i['amount'], 2)
            );

            $this->debitNote->items()->delete();

            foreach (array_values($items) as $idx => $item) {
                $this->debitNote->items()->create([
                    'description' => $item['description'],
                    'quantity'    => (float) $item['quantity'],
                    'amount'      => (float) $item['amount'],
                    'total'       => round((float) $item['quantity'] * (float) $item['amount'], 2),
                    'sort_order'  => $idx,
                ]);
            }

            $this->debitNote->update(['subtotal' => $subtotal, 'total' => $subtotal]);
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

    {{-- Header details --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
            <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Header Details</h2>
        </div>
        <div class="grid gap-4 p-4 md:grid-cols-2">
            {{-- Reference read-only --}}
            <div>
                <flux:label>Debit Note Reference</flux:label>
                <div class="mt-1.5 flex items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                    <span class="font-mono text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $debitNote->reference }}</span>
                    <span class="ml-auto text-xs text-zinc-400">Read-only</span>
                </div>
            </div>
            {{-- Supplier read-only --}}
            <div>
                <flux:label>Supplier</flux:label>
                <div class="mt-1.5 flex items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $debitNote->supplier->company_name }}</span>
                    <span class="ml-auto text-xs text-zinc-400">Read-only</span>
                </div>
            </div>
            <flux:input wire:model="doc_date" type="date" :label="__('Document Date')" required />
            <div>
                <flux:label>Notes</flux:label>
                <flux:textarea wire:model="notes" rows="2" :placeholder="__('Optional notes…')" />
                @error('notes') <flux:error>{{ $message }}</flux:error> @enderror
            </div>
        </div>
    </div>

    {{-- Line Items --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Line Items</h2>
        </div>

        @if(! $isApplied)
            <div wire:ignore x-data="{
                rows: @js($items),
                addRow() { this.rows.push({ description: '', quantity: '1', amount: '', total: 0 }); },
                removeRow(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                rowTotal(r) { return (parseFloat(r.quantity)||0) * (parseFloat(r.amount)||0); },
                get subtotal() { return this.rows.reduce((s, r) => s + this.rowTotal(r), 0); },
            }">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Description</th>
                                <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                                <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount (£)</th>
                                <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total (£)</th>
                                <th class="w-10 px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            <template x-for="(row, i) in rows" :key="i">
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <input
                                            type="text"
                                            x-model="row.description"
                                            placeholder="Description…"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <input
                                            type="number"
                                            x-model="row.quantity"
                                            min="0.01"
                                            step="0.01"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-right text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <input
                                            type="number"
                                            x-model="row.amount"
                                            min="0"
                                            step="0.01"
                                            class="block w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-right text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">
                                        £<span x-text="rowTotal(row).toFixed(2)">0.00</span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" @click="removeRow(i)" x-show="rows.length > 1" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between border-t border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <span class="text-sm font-semibold text-zinc-900 dark:text-white">Subtotal</span>
                    <span class="font-mono text-sm font-bold tabular-nums text-red-600 dark:text-red-400">
                        −£<span x-text="subtotal.toFixed(2)">0.00</span>
                    </span>
                </div>

                @error('items') <p class="px-4 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('items.*.description') <p class="px-4 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('items.*.quantity') <p class="px-4 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('items.*.amount') <p class="px-4 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror

                <div class="flex justify-end gap-3 border-t border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <flux:button variant="ghost" type="button" @click="addRow()">Add Row</flux:button>
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <flux:button variant="ghost" :href="route('supplier-debit-notes.show', $debitNote)" wire:navigate>Cancel</flux:button>
                    <flux:button variant="primary" @click="$wire.set('items', rows.map(r => ({...r, total: (parseFloat(r.quantity)||0)*(parseFloat(r.amount)||0)})), false).then(() => $wire.save())">Save Changes</flux:button>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Description</th>
                            <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount (£)</th>
                            <th class="w-32 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total (£)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($items as $item)
                            <tr>
                                <td class="px-4 py-2.5 text-sm text-zinc-900 dark:text-white">{{ $item['description'] }}</td>
                                <td class="px-4 py-2.5 text-sm text-zinc-700 dark:text-zinc-300">{{ $item['quantity'] }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm tabular-nums text-zinc-700 dark:text-zinc-300">£{{ number_format((float) $item['amount'], 2) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">£{{ number_format((float) $item['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <flux:button variant="ghost" :href="route('supplier-debit-notes.show', $debitNote)" wire:navigate>Cancel</flux:button>
                <flux:button variant="primary" wire:click="save">Save Changes</flux:button>
            </div>
        @endif
    </div>

</div>
