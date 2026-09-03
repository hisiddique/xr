<?php

use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Services\SupplierPayoutAllocator;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public ?int $supplier_id = null;
    public string $supplierName = '';
    public string $amount = '';
    public string $payout_date = '';
    public string $notes = '';
    public string $nextReference = '';
    public array $committedDebitNotes = [];
    public bool $focusAmount = false;

    /** Debit note ids handed over from "Save & move to payout" (?dn=1,2). */
    #[Url(except: '')]
    public string $dn = '';

    private bool $suppressSupplierHook = false;

    public function mount(): void
    {
        $this->payout_date = now()->format('Y-m-d');
        $this->nextReference = SupplierPayout::nextNumber();
        $this->hydrateFromDebitNotes();
    }

    /**
     * Carry the debit notes named in ?dn=1,2 into the payout (same effect the
     * on-page `debit-note-committed` event had before the pages were split).
     * Silently starts empty when the hand-off can't be trusted — a stale ?dn
     * (e.g. those debit notes already sit on a payout) is dropped from the URL
     * by clearing the bound property, no warning.
     */
    private function hydrateFromDebitNotes(): void
    {
        $ids = collect(explode(',', $this->dn))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $debitNotes = SupplierDebitNote::whereIn('id', $ids)->get();

        if ($debitNotes->count() !== $ids->count() || $debitNotes->pluck('supplier_id')->unique()->count() !== 1) {
            $this->dn = '';

            return;
        }

        if (SupplierPayoutAllocation::whereIn('supplier_debit_note_id', $ids)->exists()) {
            $this->dn = '';

            return;
        }

        $this->supplier_id = (int) $debitNotes->first()->supplier_id;
        $this->supplierName = Supplier::withTrashed()->find($this->supplier_id)?->typeahead_label ?? '';
        $this->committedDebitNotes = $debitNotes
            ->map(fn (SupplierDebitNote $dn) => ['id' => $dn->id, 'linked_invoice_id' => $dn->supplier_invoice_id])
            ->all();
        $this->focusAmount = true;

        unset($this->invoiceRows);
    }

    #[On('debit-note-committed')]
    public function onDebitNoteCommitted(int $debitNoteId, int $supplierId, ?int $linkedInvoiceId = null): void
    {
        if ($this->supplier_id && $this->supplier_id !== $supplierId) {
            $this->committedDebitNotes = [];
            $this->amount = '';
        }

        $this->suppressSupplierHook = true;
        $this->supplier_id = $supplierId;
        $this->suppressSupplierHook = false;

        $this->committedDebitNotes[] = ['id' => $debitNoteId, 'linked_invoice_id' => $linkedInvoiceId];

        $supplier = Supplier::withTrashed()->find($supplierId);
        $this->supplierName = $supplier?->typeahead_label ?? '';
        unset($this->invoiceRows);
        $this->dispatch('payout-supplier-updated', rows: $this->invoiceRows);
        $this->dispatch('dn-focus-payout');
    }

    public function updatedSupplierId(): void
    {
        if ($this->suppressSupplierHook) {
            return;
        }

        $this->committedDebitNotes = [];
        $supplier = Supplier::withTrashed()->find($this->supplier_id);
        $this->supplierName = $supplier?->typeahead_label ?? '';
        unset($this->invoiceRows);
        $this->dispatch('payout-supplier-updated', rows: $this->invoiceRows);
    }

    #[Computed]
    public function invoiceRows(): array
    {
        if (! $this->supplier_id) {
            return [];
        }

        if (empty($this->committedDebitNotes)) {
            return $this->buildOutstandingRows();
        }

        $linkedByInvoice = [];
        $floatingDnIds = [];

        foreach ($this->committedDebitNotes as $entry) {
            if ($entry['linked_invoice_id']) {
                $linkedByInvoice[$entry['linked_invoice_id']][] = $entry['id'];
            } else {
                $floatingDnIds[] = $entry['id'];
            }
        }

        $rows = [];

        if (! empty($linkedByInvoice)) {
            SupplierInvoice::whereIn('id', array_keys($linkedByInvoice))
                ->orderBy('invoice_date')
                ->with(['items', 'payoutAllocations', 'debitNotes'])
                ->get()
                ->each(function (SupplierInvoice $invoice) use ($linkedByInvoice, &$rows) {
                    $grossTotal = $invoice->grossTotal;
                    $paid = (float) $invoice->payoutAllocations->sum('allocated_amount');
                    $deducted = (float) $invoice->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount);
                    $effective = max(0, $grossTotal - $paid - $deducted);

                    if ($effective <= 0.001) {
                        return;
                    }

                    $committedDnIds = $linkedByInvoice[$invoice->id] ?? [];
                    $firstDn = $invoice->debitNotes->first(fn ($dn) => in_array($dn->id, $committedDnIds));

                    $rows[] = [
                        'id' => $invoice->id,
                        'reference' => $invoice->supplier_invoice_no,
                        'supplier_ref' => $invoice->supplier_ref_invoice_no,
                        'invoice_date' => $invoice->invoice_date->format('d M Y'),
                        'original_amount' => $grossTotal,
                        'debit_note_ref' => $firstDn?->reference,
                        'debit_note_id' => $firstDn?->id,
                        'deductions' => $deducted,
                        'effective_outstanding' => $effective,
                    ];
                });
        }

        if (! empty($floatingDnIds)) {
            SupplierDebitNote::whereIn('id', $floatingDnIds)
                ->get()
                ->each(function (SupplierDebitNote $dn) use (&$rows) {
                    $rows[] = [
                        'id' => null,
                        'reference' => null,
                        'supplier_ref' => null,
                        'debit_note_ref' => $dn->reference,
                        'debit_note_id' => $dn->id,
                        'original_amount' => (float) $dn->total,
                        'deductions' => (float) $dn->total,
                        'effective_outstanding' => (float) $dn->total,
                    ];
                });
        }

        return $rows;
    }

    private function buildOutstandingRows(): array
    {
        return SupplierInvoice::where('supplier_id', $this->supplier_id)
            ->orderBy('invoice_date')
            ->with(['items', 'payoutAllocations', 'debitNotes'])
            ->get()
            ->map(function (SupplierInvoice $invoice) {
                $grossTotal = $invoice->grossTotal;
                $paid = (float) $invoice->payoutAllocations->sum('allocated_amount');
                $deducted = (float) $invoice->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount);
                $effective = max(0, $grossTotal - $paid - $deducted);
                $debitNote = $invoice->debitNotes->first();

                return [
                    'id' => $invoice->id,
                    'reference' => $invoice->supplier_invoice_no,
                    'supplier_ref' => $invoice->supplier_ref_invoice_no,
                    'invoice_date' => $invoice->invoice_date->format('d M Y'),
                    'original_amount' => $grossTotal,
                    'debit_note_ref' => $debitNote?->reference,
                    'debit_note_id' => $debitNote?->id,
                    'deductions' => $deducted,
                    'effective_outstanding' => $effective,
                ];
            })
            ->filter(fn ($r) => $r['effective_outstanding'] > 0.001)
            ->values()
            ->toArray();
    }

    public function confirmAllocation(array $rows): void
    {
        $this->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'amount' => 'required|numeric|min:0.01',
            'payout_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $total = array_sum(array_column($rows, 'allocated_amount'));
        if ($total > (float) $this->amount + 0.001) {
            Flux::toast(variant: 'danger', text: 'Total allocated exceeds payout amount.');

            return;
        }

        $payout = SupplierPayout::create([
            'supplier_id' => $this->supplier_id,
            'amount' => $this->amount,
            'payout_date' => $this->payout_date,
            'notes' => $this->notes ?: null,
            'created_by' => auth()->id(),
        ]);

        $mappedRows = array_map(fn ($r) => [
            'invoice_id' => $r['id'],
            'debit_note_id' => $r['debit_note_id'] ?? null,
            'deduction_amount' => (float) ($r['deductions'] ?? 0),
            'allocated_amount' => (float) ($r['allocated_amount'] ?? 0),
        ], $rows);

        try {
            app(SupplierPayoutAllocator::class)->save($payout, $mappedRows);
        } catch (\InvalidArgumentException $e) {
            $payout->forceDelete();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal('confirm-allocation')->close();
        Flux::toast(variant: 'success', text: 'Payout '.$payout->reference.' confirmed.');

        $this->dispatch('payout-confirmed', payoutId: $payout->id);
        $this->dispatch('payout-focus-dn');

        $this->supplier_id = null;
        $this->supplierName = '';
        $this->amount = '';
        $this->payout_date = now()->format('Y-m-d');
        $this->notes = '';
        $this->committedDebitNotes = [];
        $this->dn = '';
        $this->nextReference = SupplierPayout::nextNumber();
        unset($this->invoiceRows);
        $this->dispatch('payout-supplier-updated', rows: []);
    }

    public function cancelPayout(): void
    {
        Flux::modal('cancel-payout-confirm')->close();
        $this->supplier_id = null;
        $this->supplierName = '';
        $this->amount = '';
        $this->payout_date = now()->format('Y-m-d');
        $this->notes = '';
        $this->committedDebitNotes = [];
        $this->dn = '';
        unset($this->invoiceRows);
        $this->dispatch('payout-supplier-updated', rows: []);
    }
};
?>

<div>
    <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
    <div class="flex items-center justify-between border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Record Outbound Supplier Payout</h2>
        <flux:badge color="green" size="sm">Cash Outflow Transaction</flux:badge>
    </div>

    <div
        class="p-4"
        data-form-root
        data-f2-handler
        wire:ignore
        x-data="supplierPayoutAllocator({ rows: @js($this->invoiceRows), focusAmount: @js($focusAmount) })"
        @payout-supplier-updated.window="rows = $event.detail.rows.map(r => ({ ...r, allocated_amount: 0 })); autoAllocate(parseFloat($wire.amount) || 0)"
        @keydown.escape="if ($wire.supplier_id) $flux.modal('cancel-payout-confirm').show()"
        @keydown.window="handleKey($event)"
        @do-confirm-allocation.window="$wire.confirmAllocation(rows)"
        @f2-action="$flux.modal('confirm-allocation').show()"
    >
        <div class="flex flex-col gap-4">
            <div class="grid gap-4 md:grid-cols-2">
                <livewire:pages::ui.typeahead
                    :key="'typeahead-payout-supplier'"
                    wire:model.live="supplier_id"
                    model="App\Models\Supplier"
                    column="company_name"
                    :search-columns="['company_name', 'reference']"
                    label-accessor="typeahead_label"
                    :min-chars="2"
                    :label="__('Supplier')"
                    :placeholder="__('Search supplier (2+ letters)…')"
                    :selected-label="$supplierName"
                    error-name="supplier_id"
                    required
                />
                <div>
                    <flux:label>{{ __('Payout No.') }}</flux:label>
                    <div class="mt-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800">
                        <span class="font-mono text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $nextReference }}</span>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model.blur="amount"
                    data-payout-amount
                    @change="autoAllocate(parseFloat($event.target.value))"
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="0.00"
                    prefix="£"
                    :label="__('Amount')"
                    required
                />
                <flux:input wire:model="payout_date" type="date" :label="__('Payout Date')" required />
            </div>

            <flux:input wire:model="notes" :label="__('Notes')" :placeholder="__('Optional notes…')" />

            <div x-show="$wire.supplier_id" x-cloak>
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
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
                                        <template x-if="row.reference">
                                            <span>
                                                <span x-text="row.reference"></span><template x-if="row.supplier_ref"><span class="ml-1 font-normal text-zinc-400 dark:text-zinc-500" x-text="'(' + row.supplier_ref + ')'"></span></template>
                                            </span>
                                        </template>
                                        <template x-if="!row.reference">
                                            <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                        </template>
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
                                            data-payout-alloc-input
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
                                    x-text="'£' + unallocated.toFixed(2)">
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-200/70 pt-4 dark:border-white/10">
                <flux:button variant="ghost" type="button" @click="$flux.modal('cancel-payout-confirm').show()">Cancel</flux:button>
                <flux:button
                    variant="primary"
                    type="button"
                    x-show="$wire.supplier_id && rows.length > 0"
                    x-cloak
                    @click="$flux.modal('confirm-allocation').show()"
                >
                    Confirm Allocation
                </flux:button>
            </div>
        </div>
    </div>
    </div>

    <flux:modal name="confirm-allocation" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Confirm Payout?</flux:heading>
                <flux:subheading>The allocated amounts will be recorded against the listed invoices.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" @click="$flux.modal('confirm-allocation').close()">Cancel</flux:button>
                <flux:button variant="primary" data-payout-confirm-btn @click="$dispatch('do-confirm-allocation'); $flux.modal('confirm-allocation').close()">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="cancel-payout-confirm" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Discard Payout?</flux:heading>
                <flux:subheading>Any entered amounts will be lost. Committed debit notes are already saved and will not be affected.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" @click="$flux.modal('cancel-payout-confirm').close()">Go Back</flux:button>
                <flux:button variant="danger" wire:click="cancelPayout">Yes, Discard</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
