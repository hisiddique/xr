<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Services\PaymentAllocator;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Payment')] class extends Component {
    use WithFileUploads;
    public ?Payment $payment = null;

    public ?int $customer_id = null;
    public string $customerName = '';
    public ?int $payment_method_id = null;
    public string $amount = '';
    public string $payment_date = '';
    public string $notes = '';
    public float $creditBalance = 0.0;
    public float $initialCreditUsed = 0.0;
    public $receipt = null;
    public string $existingReceiptPath = '';

    public function mount(): void
    {
        if ($this->payment) {
            $this->payment->load(['customer', 'paymentMethod', 'creator', 'allocations.document']);
            $this->customer_id = $this->payment->customer_id;
            $this->customerName = $this->payment->customer->typeahead_label;
            $this->payment_method_id = $this->payment->payment_method_id;
            $this->amount = (string) $this->payment->amount;
            $this->payment_date = $this->payment->payment_date->format('Y-m-d');
            $this->notes = $this->payment->notes ?? '';
            $this->existingReceiptPath = $this->payment->receipt_path ?? '';
        } else {
            $this->payment_date = now()->format('Y-m-d');

            if (request()->has('customer_id')) {
                $this->customer_id = (int) request('customer_id');
                if ($customer = Customer::find($this->customer_id)) {
                    $this->customerName = $customer->typeahead_label;
                }
            }
        }

        $this->refreshCreditBalance();
        $this->refreshInitialCreditUsed();
    }

    public function updatedCustomerId(): void
    {
        $this->refreshCreditBalance();
        $this->refreshInitialCreditUsed();
        $this->dispatch('payment-rows-updated', rows: $this->invoiceRows);
    }

    private function refreshCreditBalance(): void
    {
        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        $this->creditBalance = $customerId
            ? Document::availableCreditForCustomer($customerId)
            : 0.0;
    }

    private function refreshInitialCreditUsed(): void
    {
        $this->initialCreditUsed = $this->payment
            ? (float) CreditAllocation::where('payment_id', $this->payment->id)->sum('amount')
            : 0.0;
    }

    /**
     * @param  array<int, float>  $invoiceTotals  [invoice_id => total_to_cover], ordered oldest-first
     * @return array<int, array<int, float>>  [credit_note_id => [invoice_id => credit_applied]]
     */
    private function buildCreditAllocations(int $customerId, array $invoiceTotals): array
    {
        $ownCredit = $this->payment
            ? CreditAllocation::where('payment_id', $this->payment->id)
                ->select('credit_note_id')
                ->selectRaw('SUM(amount) as own')
                ->groupBy('credit_note_id')
                ->pluck('own', 'credit_note_id')
            : collect();

        $creditNotes = Document::creditNotes()
            ->where('customer_id', $customerId)
            ->withSum('creditAllocations', 'amount')
            ->orderBy('doc_date', 'asc')
            ->get()
            ->map(fn ($cn) => [
                'id' => $cn->id,
                'remaining' => max(0, (float) $cn->total_value - (float) ($cn->credit_allocations_sum_amount ?? 0) + (float) ($ownCredit->get($cn->id) ?? 0)),
            ])
            ->filter(fn ($cn) => $cn['remaining'] > 0.001)
            ->values()
            ->toArray();

        $result = [];
        $cnIndex = 0;

        foreach ($invoiceTotals as $invoiceId => $total) {
            $needed = round((float) $total, 2);
            $invoiceId = (int) $invoiceId;
            while ($needed > 0.001 && $cnIndex < count($creditNotes)) {
                $draw = round(min($needed, $creditNotes[$cnIndex]['remaining']), 2);
                if ($draw > 0) {
                    $result[$creditNotes[$cnIndex]['id']][$invoiceId] = ($result[$creditNotes[$cnIndex]['id']][$invoiceId] ?? 0) + $draw;
                    $creditNotes[$cnIndex]['remaining'] = round($creditNotes[$cnIndex]['remaining'] - $draw, 2);
                    $needed = round($needed - $draw, 2);
                }
                if ($creditNotes[$cnIndex]['remaining'] <= 0.001) {
                    $cnIndex++;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{id:int, amount:float}>  $rows
     * @return array{0: array<int, float>, 1: array<int, array<int, float>>, 2: int[]}
     */
    private function deriveAllocations(int $customerId, array $rows): array
    {
        $ordered = $this->orderRowsByInvoiceDate($customerId, $rows);

        $invoiceTotals = collect($ordered)
            ->mapWithKeys(fn ($r) => [(int) $r['id'] => round((float) ($r['amount'] ?? 0), 2)])
            ->filter(fn ($v) => $v > 0)
            ->toArray();

        $scope = collect($rows)->map(fn ($r) => (int) $r['id'])->filter()->values()->toArray();

        $creditAllocations = $this->buildCreditAllocations($customerId, $invoiceTotals);

        $paymentAllocations = [];
        foreach ($invoiceTotals as $invoiceId => $total) {
            $creditApplied = collect($creditAllocations)->sum(fn ($inv) => $inv[$invoiceId] ?? 0);
            $cash = round($total - $creditApplied, 2);
            if ($cash > 0) {
                $paymentAllocations[$invoiceId] = $cash;
            }
        }

        return [$paymentAllocations, $creditAllocations, $scope];
    }

    private function orderRowsByInvoiceDate(int $customerId, array $rows): array
    {
        $order = Document::where('customer_id', $customerId)
            ->where('type', 'INV')
            ->orderBy('doc_date', 'asc')
            ->pluck('id')
            ->flip();

        return collect($rows)
            ->sortBy(fn ($r) => $order->get((int) $r['id'], PHP_INT_MAX))
            ->values()
            ->toArray();
    }

    #[Computed]
    public function paymentMethods()
    {
        return LookupPaymentMethod::orderBy('name')->get();
    }

    public function save(array $rows = []): void
    {
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'payment_method_id' => 'required|integer|exists:lookup_payment_methods,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:pdf,png,jpg,jpeg,webp|max:5120',
        ]);

        if ($this->payment === null) {
            $payment = Payment::create([
                'customer_id' => $this->customer_id,
                'payment_method_id' => $this->payment_method_id,
                'amount' => $this->amount,
                'payment_date' => $this->payment_date,
                'notes' => $this->notes ?: null,
                'created_by' => auth()->id(),
            ]);

            if ($this->receipt) {
                $ext = $this->receipt->getClientOriginalExtension();
                $receiptPath = $this->receipt->storeAs('payment-receipts', $payment->reference . '.' . $ext, 'public');
                $payment->update(['receipt_path' => $receiptPath]);
            }

            [$paymentAllocations, $creditAllocations, $scope] = $this->deriveAllocations($this->customer_id, $rows);

            try {
                app(PaymentAllocator::class)->saveWithCredits($payment, $paymentAllocations, $creditAllocations, $scope);
            } catch (\InvalidArgumentException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());

                return;
            }

            Flux::toast(variant: 'success', text: 'Payment recorded.');
            $this->redirect(route('payments.show', $payment), navigate: true);

            return;
        }

        $updateData = [
            'customer_id' => $this->customer_id,
            'payment_method_id' => $this->payment_method_id,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date,
            'notes' => $this->notes ?: null,
        ];

        if ($this->receipt) {
            if ($this->existingReceiptPath && Storage::disk('public')->exists($this->existingReceiptPath)) {
                Storage::disk('public')->delete($this->existingReceiptPath);
            }
            $ext = $this->receipt->getClientOriginalExtension();
            $updateData['receipt_path'] = $this->receipt->storeAs('payment-receipts', $this->payment->reference . '.' . $ext, 'public');
            $this->existingReceiptPath = $updateData['receipt_path'];
        }

        $this->payment->update($updateData);

        if (! empty($rows)) {
            [$paymentAllocations, $creditAllocations, $scope] = $this->deriveAllocations($this->payment->customer_id, $rows);

            try {
                app(PaymentAllocator::class)->saveWithCredits($this->payment, $paymentAllocations, $creditAllocations, $scope);
                $this->refreshCreditBalance();
                $this->refreshInitialCreditUsed();
            } catch (\InvalidArgumentException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());

                return;
            }
        }

        Flux::toast(variant: 'success', text: 'Payment updated.');
        $this->redirect(route('payments.show', $this->payment), navigate: true);
    }

    #[Computed]
    public function invoiceRows(): array
    {
        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        if (! $customerId) {
            return [];
        }

        $invoices = Document::where('customer_id', $customerId)
            ->where('type', 'INV')
            ->orderBy('doc_date', 'asc')
            ->withSum('paymentAllocations', 'allocated_amount')
            ->withSum('creditAllocationsReceived', 'amount')
            ->get();

        $thisPaymentAllocations = $this->payment
            ? $this->payment->allocations->keyBy('document_id')
            : collect();

        $thisPaymentCredits = $this->payment
            ? CreditAllocation::where('payment_id', $this->payment->id)
                ->select('invoice_id')
                ->selectRaw('SUM(amount) as credit_total')
                ->groupBy('invoice_id')
                ->pluck('credit_total', 'invoice_id')
            : collect();

        return $invoices->map(function (Document $invoice) use ($thisPaymentAllocations, $thisPaymentCredits) {
            $existingCash = (float) ($thisPaymentAllocations->get($invoice->id)?->allocated_amount ?? 0);
            $existingCredit = (float) ($thisPaymentCredits->get($invoice->id) ?? 0);
            $totalPayments = (float) ($invoice->payment_allocations_sum_allocated_amount ?? 0);
            $totalCredits = (float) ($invoice->credit_allocations_received_sum_amount ?? 0);
            $maxAllocatable = round((float) $invoice->total_value - $totalPayments - $totalCredits + $existingCash + $existingCredit, 2);

            return [
                'id' => $invoice->id,
                'doc_number' => $invoice->doc_number,
                'doc_date' => $invoice->doc_date->format('d M Y'),
                'total_value' => (float) $invoice->total_value,
                'existing_allocation' => $existingCash + $existingCredit,
                'max_allocatable' => $maxAllocatable,
            ];
        })->filter(fn ($row) => $row['max_allocatable'] > 0.001 || $row['existing_allocation'] > 0.001)
          ->values()
          ->toArray();
    }

    #[Computed]
    public function totalAllocated(): float
    {
        if (! $this->payment) {
            return 0.0;
        }

        return (float) $this->payment->allocations->sum('allocated_amount');
    }

    #[Computed]
    public function unallocatedBalance(): float
    {
        if (! $this->payment) {
            return 0.0;
        }

        return max(0, (float) $this->payment->amount - $this->totalAllocated);
    }

    public function saveAllocations(array $rows): void
    {
        if (! $this->payment) {
            return;
        }

        $scope = collect($rows)->map(fn ($r) => (int) $r['id'])->filter()->values()->toArray();
        $paymentAllocations = collect($rows)
            ->mapWithKeys(fn ($r) => [(int) $r['id'] => max(0, (float) ($r['amount'] ?? 0) - (float) ($r['creditAmount'] ?? 0))])
            ->filter(fn ($v) => $v > 0)
            ->toArray();

        $creditRows = collect($rows)->filter(fn ($r) => (float) ($r['creditAmount'] ?? 0) > 0)->values()->toArray();
        $creditAllocations = $this->buildCreditAllocations($this->payment->customer_id, $creditRows);

        try {
            app(PaymentAllocator::class)->saveWithCredits($this->payment, $paymentAllocations, $creditAllocations, $scope);
            $this->payment->load('allocations');
            unset($this->totalAllocated, $this->unallocatedBalance, $this->invoiceRows);
            $this->refreshCreditBalance();
            Flux::toast(variant: 'success', text: 'Allocations saved.');
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="{{ $payment ? 'Edit Payment' : 'Record Payment' }}"
        subtitle="{{ $payment ? 'Update payment details and manage invoice allocations.' : 'Log a received payment and allocate it to invoices.' }}"
    >
        <x-slot:action>
            <flux:button
                variant="ghost"
                icon="arrow-left"
                :href="$payment ? route('payments.show', $payment) : route('payments.index')"
                wire:navigate
            >
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

        <div
            class="flex min-w-0 flex-1 flex-col gap-4"
            x-data="formNav"
            x-on:keydown="handleKey($event)"
            x-on:exit-confirm-discard.window="window.location.href = '{{ $payment ? route('payments.show', $payment) : route('payments.index') }}'"
            x-on:exit-confirm-save.window="$dispatch('save-payment-form')"
        >

            {{-- Payment Details form card --}}
            <form>
                <div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Payment Details</h2>
                    </div>
                    <div class="grid gap-4 p-4 md:grid-cols-2" data-form-grid>

                        {{-- Customer --}}
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

                        {{-- Payment Method --}}
                        <div>
                            <flux:label>{{ __('Payment Method') }} <span class="text-red-500">*</span></flux:label>
                            <flux:select wire:model="payment_method_id" class="mt-1.5" x-on:focus="$el.showPicker?.()">
                                <flux:select.option value="">— Select method —</flux:select.option>
                                @foreach($this->paymentMethods as $method)
                                    <flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('payment_method_id') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                        {{-- Amount --}}
                        <flux:input
                            wire:model.blur="amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            prefix="£"
                            :label="__('Amount')"
                            required
                        />

                        {{-- Date --}}
                        <flux:input
                            wire:model="payment_date"
                            type="date"
                            :label="__('Payment Date')"
                            required
                        />

                        {{-- Notes --}}
                        <div>
                            <flux:input
                                wire:model="notes"
                                :label="__('Notes')"
                                :placeholder="__('Optional notes…')"
                            />
                        </div>

                        {{-- Receipt upload --}}
                        <div>
                            <flux:label>{{ __('Receipt') }}</flux:label>
                            @if($existingReceiptPath)
                                <div class="mt-1.5 mb-2 flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-zinc-800">
                                    <flux:icon.paper-clip class="size-4 shrink-0 text-zinc-400" />
                                    <span class="min-w-0 flex-1 truncate text-zinc-700 dark:text-zinc-300">{{ basename($existingReceiptPath) }}</span>
                                    <a href="{{ Storage::disk('public')->url($existingReceiptPath) }}" target="_blank" class="shrink-0 text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">View</a>
                                </div>
                            @endif
                            <input
                                type="file"
                                wire:model="receipt"
                                accept=".pdf,.png,.jpg,.jpeg,.webp"
                                class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                            />
                            @if($receipt)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $receipt->getClientOriginalName() }} selected</p>
                            @endif
                            @error('receipt') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                    </div>

                </div>
            </form>

            {{-- Allocation section — visible as soon as a customer is selected --}}
            {{-- wire:ignore prevents Livewire morphs (amount blur, method change) from wiping Alpine rows state --}}
            <div
                wire:ignore
                x-data="paymentAllocator({ rows: @js($this->invoiceRows) })"
                @save-payment-form.window="$wire.save(rows)"
                @payment-rows-updated.window="rows = $event.detail.rows.map(r => ({ ...r, amount: r.existing_allocation, creditAmount: 0 }))"
            >
                <div x-show="$wire.customer_id" x-cloak>

                    {{-- Allocation table --}}
                    <div>
                        <x-ui.section-card>
                            <x-slot:header>
                                <div class="flex w-full items-center justify-between gap-4">
                                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Allocations</h2>
                                    <div class="flex items-center gap-3">
                                        <span
                                            x-show="serverCreditBalance > 0 || initialCreditUsed > 0"
                                            x-text="'Available Credits: £' + availableCreditsAfter.toFixed(2)"
                                            class="text-sm font-semibold text-emerald-600 dark:text-emerald-400"
                                        ></span>
                                        <flux:button variant="ghost" size="sm" @click="autoAllocate()">
                                            Auto Allocate
                                        </flux:button>
                                    </div>
                                </div>
                            </x-slot:header>

                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                                <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoice #</th>
                                                <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Date</th>
                                                <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoice Total</th>
                                                <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">This Payment</th>
                                                <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Max Available</th>
                                                <th class="pb-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Outstanding After</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in rows" :key="row.id">
                                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                                    <td class="py-3 pr-4 font-mono text-sm text-zinc-900 dark:text-white" x-text="row.doc_number"></td>
                                                    <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-400" x-text="row.doc_date"></td>
                                                    <td class="py-3 pr-4 text-right font-mono text-zinc-900 dark:text-white" x-text="'£' + row.total_value.toFixed(2)"></td>
                                                    <td class="py-3 pr-4 text-right">
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            :max="row.max_allocatable"
                                                            x-model="row.amount"
                                                            @input="row.amount = Math.min(parseFloat($event.target.value)||0, row.max_allocatable)"
                                                            class="w-28 rounded-lg border border-zinc-300 px-2 py-1 text-right font-mono text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                                        />
                                                    </td>
                                                    <td class="py-3 pr-4 text-right font-mono text-zinc-600 dark:text-zinc-400" x-text="'£' + row.max_allocatable.toFixed(2)"></td>
                                                    <td class="py-3 text-right font-mono" :class="(row.max_allocatable - (parseFloat(row.amount)||0)) <= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-white'" x-text="'£' + Math.max(0, row.max_allocatable - (parseFloat(row.amount)||0)).toFixed(2)"></td>
                                                </tr>
                                            </template>
                                            <tr x-show="rows.length === 0">
                                                <td colspan="6" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No invoices found for this customer.</td>
                                            </tr>
                                        </tbody>
                                        <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700">
                                            <tr>
                                                <td class="pt-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">Summary</td>
                                                <td class="pt-3 pr-4 text-right">
                                                    <div class="font-mono font-semibold text-zinc-900 dark:text-white" x-text="'£' + paymentAmount.toFixed(2)"></div>
                                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Payment</div>
                                                </td>
                                                <td class="pt-3 pr-4 text-right">
                                                    <div class="font-mono font-semibold text-zinc-900 dark:text-white" x-text="'£' + totalAllocated.toFixed(2)"></div>
                                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Allocated</div>
                                                </td>
                                                <td class="pt-3 pr-4 text-right">
                                                    <div class="font-mono font-semibold text-emerald-600 dark:text-emerald-400" x-text="'£' + creditsUsed.toFixed(2)"></div>
                                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Credits Used</div>
                                                </td>
                                                <td class="pt-3 pr-4 text-right">
                                                    <div class="font-mono font-semibold text-zinc-900 dark:text-white" x-text="'£' + cashUsed.toFixed(2)"></div>
                                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Cash Used</div>
                                                </td>
                                                <td class="pt-3 text-right">
                                                    <div class="font-mono font-semibold" :class="budgetRemaining < 0 ? 'text-red-600 dark:text-red-400' : budgetRemaining > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'" x-text="'£' + budgetRemaining.toFixed(2)"></div>
                                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Remaining Cash</div>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                        </x-ui.section-card>
                    </div>

                </div>

                <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
                    <flux:button
                        variant="ghost"
                        :href="$payment ? route('payments.show', $payment) : route('payments.index')"
                        wire:navigate
                        data-form-nav
                    >
                        Cancel
                    </flux:button>
                    <flux:button variant="primary" type="button" @click="$dispatch('save-payment-form')" data-form-nav data-form-submit>
                        {{ $payment ? 'Save Changes' : 'Save Payment' }}
                    </flux:button>
                </div>
            </div>

        </div>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
