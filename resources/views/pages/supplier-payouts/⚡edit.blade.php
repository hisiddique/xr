<?php

use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayout;
use App\Services\SupplierPayoutAllocator;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Payout')] class extends Component {
    public SupplierPayout $payout;

    public string $amount = '';
    public string $payout_date = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->payout->load(['supplier', 'allocations.supplierDebitNote']);
        $this->amount = (string) $this->payout->amount;
        $this->payout_date = $this->payout->payout_date->format('Y-m-d');
        $this->notes = $this->payout->notes ?? '';
    }

    #[Computed]
    public function invoiceRows(): array
    {
        $allocations = $this->payout->allocations;
        $rows = [];

        $invoiceAllocations = $allocations->whereNotNull('supplier_invoice_id');
        if ($invoiceAllocations->isNotEmpty()) {
            $invoiceIds = $invoiceAllocations->pluck('supplier_invoice_id')->toArray();
            $invoices = SupplierInvoice::whereIn('id', $invoiceIds)
                ->orderBy('invoice_date')
                ->with([
                    'items',
                    'payoutAllocations' => fn ($q) => $q->where('supplier_payout_id', '!=', $this->payout->id),
                    'debitNotes',
                ])
                ->get()
                ->keyBy('id');

            foreach ($invoiceAllocations as $allocation) {
                $invoice = $invoices->get($allocation->supplier_invoice_id);
                if (! $invoice) {
                    continue;
                }
                $grossTotal = $invoice->grossTotal;
                $paid = (float) $invoice->payoutAllocations->sum('allocated_amount');
                $deducted = (float) $invoice->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount);
                $effective = max(0, $grossTotal - $paid - $deducted);
                $existing = (float) $allocation->allocated_amount;

                $rows[] = [
                    'id' => $invoice->id,
                    'reference' => $invoice->supplier_invoice_no,
                    'supplier_ref' => $invoice->supplier_ref_invoice_no,
                    'invoice_date' => $invoice->invoice_date->format('d M Y'),
                    'original_amount' => $grossTotal,
                    'debit_note_ref' => $allocation->supplierDebitNote?->reference,
                    'debit_note_id' => $allocation->supplier_debit_note_id,
                    'deductions' => $deducted,
                    'effective_outstanding' => $effective + $existing,
                    'allocated_amount' => $existing,
                ];
            }
        }

        $standaloneAllocations = $allocations->whereNull('supplier_invoice_id')->whereNotNull('supplier_debit_note_id');
        if ($standaloneAllocations->isNotEmpty()) {
            $dnIds = $standaloneAllocations->pluck('supplier_debit_note_id')->toArray();
            $debitNotes = SupplierDebitNote::whereIn('id', $dnIds)->get()->keyBy('id');

            foreach ($standaloneAllocations as $allocation) {
                $dn = $debitNotes->get($allocation->supplier_debit_note_id);
                if (! $dn) {
                    continue;
                }
                $existing = (float) $allocation->allocated_amount;

                $rows[] = [
                    'id' => null,
                    'reference' => null,
                    'supplier_ref' => null,
                    'invoice_date' => null,
                    'original_amount' => (float) $dn->total,
                    'debit_note_ref' => $dn->reference,
                    'debit_note_id' => $dn->id,
                    'deductions' => (float) $dn->total,
                    'effective_outstanding' => $existing,
                    'allocated_amount' => $existing,
                ];
            }
        }

        return $rows;
    }

    public function save(array $rows): void
    {
        $this->validate([
            'amount' => 'required|numeric|min:0.01',
            'payout_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->payout->update([
            'amount' => $this->amount,
            'payout_date' => $this->payout_date,
            'notes' => $this->notes ?: null,
        ]);

        $mappedRows = array_map(fn ($r) => [
            'invoice_id' => $r['id'],
            'debit_note_id' => $r['debit_note_id'] ?? null,
            'deduction_amount' => (float) ($r['deductions'] ?? 0),
            'allocated_amount' => (float) ($r['allocated_amount'] ?? 0),
        ], $rows);

        try {
            app(SupplierPayoutAllocator::class)->save($this->payout, $mappedRows);
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: 'Payout updated.');
        $this->redirect(route('supplier-payouts.show', $this->payout), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Edit Payout: {{ $payout->reference }}"
        subtitle="Update payout details and invoice allocations."
    >
        <x-slot:action>
            <flux:button
                variant="ghost"
                icon="arrow-left"
                :href="route('supplier-payouts.show', $payout)"
                wire:navigate
            >
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div
        wire:ignore
        x-data="supplierPayoutAllocator({ rows: @js($this->invoiceRows) })"
    >
        <div class="flex flex-col gap-4">

            <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
                <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Payout Details</h2>
                </div>
                <div class="grid gap-4 p-4 md:grid-cols-2">

                    <flux:input
                        :value="$payout->supplier->company_name"
                        :label="__('Supplier')"
                        readonly
                    />

                    <flux:input
                        wire:model="payout_date"
                        type="date"
                        :label="__('Payout Date')"
                        required
                    />

                    <flux:input
                        wire:model.blur="amount"
                        @change="autoAllocate(parseFloat($event.target.value))"
                        type="number"
                        step="0.01"
                        min="0.01"
                        placeholder="0.00"
                        prefix="£"
                        :label="__('Amount')"
                        required
                    />

                    <flux:input
                        wire:model="notes"
                        :label="__('Notes')"
                        :placeholder="__('Optional notes…')"
                    />

                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Allocations</h2>
                    <flux:button variant="ghost" size="sm" @click="autoAllocate()">Auto Allocate</flux:button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Invoice Ref</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Original Amt</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Debit Note</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Deductions</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Allocated Amt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            <template x-for="row in rows" :key="row.id ?? ('dn-' + row.debit_note_id)">
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs font-semibold text-zinc-900 dark:text-white">
                                        <template x-if="row.reference"><span><span x-text="row.reference"></span><template x-if="row.supplier_ref"><span class="ml-1 font-normal text-zinc-400 dark:text-zinc-500" x-text="'(' + row.supplier_ref + ')'"></span></template></span></template>
                                        <template x-if="!row.reference"><span class="text-zinc-400 dark:text-zinc-600">—</span></template>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-sm text-zinc-700 dark:text-zinc-300" x-text="'£' + parseFloat(row.original_amount).toFixed(2)"></td>
                                    <td class="px-4 py-3 text-center">
                                        <template x-if="row.debit_note_ref">
                                            <flux:badge color="red" size="sm" x-text="row.debit_note_ref"></flux:badge>
                                        </template>
                                        <template x-if="!row.debit_note_ref">
                                            <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-sm text-red-600 dark:text-red-400" x-text="row.deductions > 0 ? '−£' + parseFloat(row.deductions).toFixed(2) : '—'"></td>
                                    <td class="px-4 py-3 text-right">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :max="row.effective_outstanding"
                                            x-model="row.allocated_amount"
                                            @input="row.allocated_amount = Math.min(parseFloat($event.target.value)||0, parseFloat(row.effective_outstanding)||0)"
                                            class="w-28 rounded-lg border border-zinc-300 px-2 py-1 text-right font-mono text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                        />
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="rows.length === 0">
                                <td colspan="5" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No outstanding invoices for this supplier.</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700">
                            <tr>
                                <td colspan="3" class="px-4 pt-3"></td>
                                <td class="px-4 pt-3 text-right text-sm font-medium text-zinc-600 dark:text-zinc-400">Unallocated:</td>
                                <td class="px-4 pt-3 text-right font-mono font-semibold"
                                    :class="unallocated > 0.001 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'"
                                    x-text="'£' + unallocated.toFixed(2)"
                                ></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
                <flux:button
                    variant="ghost"
                    :href="route('supplier-payouts.show', $payout)"
                    wire:navigate
                >
                    Cancel
                </flux:button>
                <flux:button variant="primary" @click="$wire.save(rows)">Save Changes</flux:button>
            </div>

        </div>
    </div>

</div>
