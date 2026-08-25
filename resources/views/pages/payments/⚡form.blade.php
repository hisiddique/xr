<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentDraw;
use App\PaymentSourceType;
use App\Services\PaymentAllocator;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Payment')] class extends Component {
    use WithFileUploads;
    public ?Payment $payment = null;

    public ?int $customer_id = null;
    public string $customerName = '';
    public string $paymentMethodSelection = '';
    public string $source_type = 'cash';
    public string $amount = '';
    public string $payment_date = '';
    public string $notes = '';
    public string $payment_reference = '';
    public $receipt = null;
    public string $existingReceiptPath = '';
    public array $selectedCreditNoteIds = [];
    public array $selectedOverPaymentIds = [];
    public array $overPaymentExhaustIds = [];

    /**
     * How many oldest-outstanding invoices are currently loaded into the
     * allocation table. Kept small so a customer with tens of thousands of
     * invoices never ships/renders them all at once; scrolling near the
     * bottom auto-loads another chunk.
     */
    public int $loadedLimit = 500;

    /**
     * Document IDs pinned into the allocation table outside the normal
     * oldest-first window — added by invoice search or by auto-allocate
     * landing on an invoice beyond the currently loaded window.
     *
     * @var array<int, int>
     */
    public array $extraDocumentIds = [];

    public bool $hasMoreInvoices = false;

    public function mount(): void
    {
        if ($this->payment) {
            $this->payment->load(['customer', 'paymentMethod', 'creator', 'allocations.document']);
            $this->customer_id = $this->payment->customer_id;
            $this->customerName = $this->payment->customer->typeahead_label;
            $this->amount = number_format((float) $this->payment->amount, 2, '.', '');
            $this->payment_date = $this->payment->payment_date->format('Y-m-d');
            $this->notes = $this->payment->notes ?? '';
            $this->payment_reference = $this->payment->payment_reference ?? '';
            $this->existingReceiptPath = $this->payment->receipt_path ?? '';
            $this->source_type = $this->payment->source_type->value;

            $this->paymentMethodSelection = match ($this->payment->source_type) {
                PaymentSourceType::Cash => $this->payment->payment_method_id ? "lookup:{$this->payment->payment_method_id}" : '',
                PaymentSourceType::CreditNote => 'credit_note',
                PaymentSourceType::OverPayment => 'over_payment',
            };

            if ($this->payment->source_type === PaymentSourceType::CreditNote) {
                $this->selectedCreditNoteIds = $this->payment->creditAllocations()->pluck('credit_note_id')->unique()->values()->toArray();
                // Notes may hold more than this payment currently consumes — seed the
                // ceiling (ex. this payment's own claim), not the consumed figure, so
                // the allocation table lets the user allocate up to what's really there.
                $this->amount = number_format($this->selectedCreditNoteTotal, 2, '.', '');
            }

            if ($this->payment->source_type === PaymentSourceType::OverPayment) {
                $this->selectedOverPaymentIds = $this->payment->drawsReceived()->pluck('source_payment_id')->toArray();
                $this->overPaymentExhaustIds = collect($this->availableOverPaymentSources)
                    ->filter(fn ($src) => $src['is_exhausted'])
                    ->pluck('id')
                    ->toArray();
            }
        } else {
            $this->payment_date = now()->format('Y-m-d');

            if (request()->has('customer_id')) {
                $this->customer_id = (int) request('customer_id');
                if ($customer = Customer::find($this->customer_id)) {
                    $this->customerName = $customer->typeahead_label;
                }
            }
        }
    }

    public function updatedCustomerId(): void
    {
        $this->selectedCreditNoteIds = [];
        $this->selectedOverPaymentIds = [];
        $this->overPaymentExhaustIds = [];
        $this->extraDocumentIds = [];
        $this->loadedLimit = 500;
        unset($this->availableCreditNotes, $this->availableOverPaymentSources, $this->selectedCreditNoteTotal, $this->selectedOverPaymentTotal, $this->invoiceRows);
        $this->dispatch('payment-rows-updated', rows: $this->invoiceRows, hasMore: $this->hasMoreInvoices);
    }

    public function loadMoreInvoices(): void
    {
        $this->loadedLimit += 500;
        unset($this->invoiceRows);
        $this->dispatch('payment-rows-appended', rows: $this->invoiceRows, hasMore: $this->hasMoreInvoices);
    }

    public function searchInvoice(string $term): void
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            Flux::toast(variant: 'warning', text: 'Type at least 2 characters to search.');

            return;
        }

        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        if (! $customerId) {
            return;
        }

        $matches = Document::where('customer_id', $customerId)
            ->where('type', 'INV')
            ->where('doc_number', 'like', "%{$term}%")
            ->limit(50)
            ->pluck('id');

        if ($matches->isEmpty()) {
            Flux::toast(variant: 'warning', text: 'No matching invoice found.');

            return;
        }

        $this->extraDocumentIds = collect($this->extraDocumentIds)
            ->merge($matches)
            ->unique()
            ->values()
            ->all();

        unset($this->invoiceRows);

        $this->dispatch('payment-rows-appended', rows: $this->invoiceRows, hasMore: $this->hasMoreInvoices);
    }

    /**
     * The amount field uses wire:model.blur, so $this->amount can briefly lag
     * behind what the user just typed if they click straight into Auto
     * Allocate. Accept the client's live value explicitly so allocation is
     * never computed against a stale, not-yet-synced amount.
     */
    public function autoAllocate(?string $amount = null): void
    {
        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        if (! $customerId) {
            return;
        }

        $amount = (float) ($amount ?? $this->amount);

        if ($amount <= 0) {
            $this->dispatch('payment-auto-allocated', rows: [], allocations: [], hasMore: $this->hasMoreInvoices);

            return;
        }

        $transient = $this->payment ? clone $this->payment : new Payment(['customer_id' => $customerId]);
        $transient->amount = $amount;

        $allocations = app(PaymentAllocator::class)->autoAllocate($transient);

        if (! empty($allocations)) {
            $this->extraDocumentIds = collect($this->extraDocumentIds)
                ->merge(array_keys($allocations))
                ->unique()
                ->values()
                ->all();

            unset($this->invoiceRows);
        }

        $this->dispatch(
            'payment-auto-allocated',
            rows: $this->invoiceRows,
            allocations: collect($allocations)->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            hasMore: $this->hasMoreInvoices,
        );
    }

    public function updatedPaymentMethodSelection(): void
    {
        $this->source_type = $this->resolveSourceType();

        if ($this->source_type !== 'cash') {
            $this->payment_reference = '';
        }
        if ($this->source_type !== 'credit_note') {
            $this->selectedCreditNoteIds = [];
        }
        if ($this->source_type !== 'over_payment') {
            $this->selectedOverPaymentIds = [];
            $this->overPaymentExhaustIds = [];
        }

        $this->syncAmountForSelection();

        $this->dispatch('payment-method-resolved');
    }

    public function updatedSelectedCreditNoteIds(): void
    {
        unset($this->selectedCreditNoteTotal);
        $this->syncAmountForSelection();
    }

    public function updatedSelectedOverPaymentIds(): void
    {
        unset($this->selectedOverPaymentTotal);
        $this->syncAmountForSelection();
    }

    private function resolveSourceType(): string
    {
        return match (true) {
            str_starts_with($this->paymentMethodSelection, 'lookup:') => PaymentSourceType::Cash->value,
            $this->paymentMethodSelection === 'credit_note' => PaymentSourceType::CreditNote->value,
            $this->paymentMethodSelection === 'over_payment' => PaymentSourceType::OverPayment->value,
            default => PaymentSourceType::Cash->value,
        };
    }

    private function resolvedPaymentMethodId(): ?int
    {
        if (str_starts_with($this->paymentMethodSelection, 'lookup:')) {
            return (int) Str::after($this->paymentMethodSelection, 'lookup:');
        }

        return null;
    }

    private function syncAmountForSelection(): void
    {
        $this->amount = match ($this->source_type) {
            'credit_note' => number_format($this->selectedCreditNoteTotal, 2, '.', ''),
            'over_payment' => number_format($this->selectedOverPaymentTotal, 2, '.', ''),
            default => $this->amount,
        };
    }

    /**
     * @param  array<int, array{id:int, amount:float}>  $rows
     * @return array{0: array<int, float>, 1: int[]}
     */
    private function deriveAllocations(array $rows): array
    {
        $allocations = collect($rows)
            ->mapWithKeys(fn ($r) => [(int) $r['id'] => round((float) ($r['amount'] ?? 0), 2)])
            ->filter(fn ($v) => $v > 0)
            ->toArray();

        $scope = collect($rows)->map(fn ($r) => (int) $r['id'])->filter()->values()->toArray();

        return [$allocations, $scope];
    }

    #[Computed]
    public function paymentMethods(): Collection
    {
        return LookupPaymentMethod::orderBy('name')->get();
    }

    /**
     * @return array<int, array{id:int, doc_number:string, doc_date:string, time:string, remaining:float}>
     */
    #[Computed]
    public function availableCreditNotes(): array
    {
        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        if (! $customerId) {
            return [];
        }

        $paymentId = $this->payment?->id;

        return Document::creditNotes()
            ->where('customer_id', $customerId)
            ->withSum(['creditAllocations' => function ($query) use ($paymentId) {
                if ($paymentId) {
                    $query->where(fn ($q) => $q->whereNull('payment_id')->orWhere('payment_id', '!=', $paymentId));
                }
            }], 'amount')
            ->orderBy('doc_date', 'asc')
            ->get()
            ->map(fn (Document $note) => [
                'id' => $note->id,
                'doc_number' => $note->doc_number,
                'doc_date' => $note->doc_date->format('d M Y'),
                'time' => $note->created_at->format('H:i'),
                'remaining' => round((float) $note->total_value - (float) ($note->credit_allocations_sum_amount ?? 0), 2),
            ])
            ->filter(fn (array $note) => $note['remaining'] > 0.001)
            ->values()
            ->toArray();
    }

    /**
     * @return array<int, array{id:int, reference:string, payment_reference:?string, method_label:string, credit_note_refs:string[], payment_date:string, time:string, remaining:float, is_exhausted:bool}>
     */
    #[Computed]
    public function availableOverPaymentSources(): array
    {
        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        if (! $customerId) {
            return [];
        }

        $ownDrawnIds = $this->payment
            ? $this->payment->drawsReceived()->pluck('source_payment_id')->toArray()
            : [];
        $ownDrawnAmounts = $this->payment
            ? $this->payment->drawsReceived()->pluck('amount', 'source_payment_id')
            : collect();

        return Payment::where('customer_id', $customerId)
            ->where('source_type', '!=', PaymentSourceType::OverPayment)
            ->when($this->payment, fn ($query) => $query->where('id', '!=', $this->payment->id))
            ->with(['paymentMethod', 'creditAllocations.creditNote'])
            ->withSum('allocations', 'allocated_amount')
            ->withSum('drawsMade', 'amount')
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Payment $source) => [$source, round(
                (float) $source->amount
                    - (float) ($source->allocations_sum_allocated_amount ?? 0)
                    - (float) ($source->draws_made_sum_amount ?? 0),
                2
            )])
            ->filter(fn (array $pair) => in_array($pair[0]->id, $ownDrawnIds, true)
                || (! $pair[0]->is_exhausted && $pair[1] > 0.001))
            ->map(function (array $pair) use ($ownDrawnAmounts) {
                [$source, $remaining] = $pair;
                if ($ownDrawnAmounts->has($source->id)) {
                    $remaining += (float) $ownDrawnAmounts->get($source->id);
                }

                return [
                    'id' => $source->id,
                    'reference' => $source->reference,
                    'payment_reference' => $source->payment_reference,
                    'method_label' => $source->paymentMethod?->name ?? $source->source_type->label(),
                    'credit_note_refs' => $source->creditAllocations
                        ->pluck('creditNote.doc_number')
                        ->filter()
                        ->values()
                        ->toArray(),
                    'payment_date' => $source->payment_date->format('d M Y'),
                    'time' => $source->created_at->format('H:i'),
                    'remaining' => round($remaining, 2),
                    'is_exhausted' => (bool) $source->is_exhausted,
                ];
            })
            ->values()
            ->toArray();
    }

    #[Computed]
    public function selectedCreditNoteTotal(): float
    {
        if (empty($this->selectedCreditNoteIds)) {
            return 0.0;
        }

        return (float) collect($this->availableCreditNotes)
            ->whereIn('id', $this->selectedCreditNoteIds)
            ->sum('remaining');
    }

    #[Computed]
    public function selectedOverPaymentTotal(): float
    {
        if (empty($this->selectedOverPaymentIds)) {
            return 0.0;
        }

        return (float) collect($this->availableOverPaymentSources)
            ->whereIn('id', $this->selectedOverPaymentIds)
            ->sum('remaining');
    }

    public function save(array $rows = []): void
    {
        $this->source_type = $this->resolveSourceType();

        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'paymentMethodSelection' => 'required|string',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'payment_reference' => 'nullable|string|max:255',
            'receipt' => 'nullable|file|mimes:pdf,png,jpg,jpeg,webp|max:5120',
        ]);

        if ($this->source_type === 'cash') {
            $this->validate([
                'amount' => 'required|numeric|min:0.01',
                'paymentMethodSelection' => 'required|string',
            ]);

            if (! $this->resolvedPaymentMethodId() || ! LookupPaymentMethod::whereKey($this->resolvedPaymentMethodId())->exists()) {
                Flux::toast(variant: 'danger', text: 'Select a valid payment method.');

                return;
            }
        } elseif ($this->source_type === 'credit_note' && empty($this->selectedCreditNoteIds)) {
            Flux::toast(variant: 'danger', text: 'Select at least one credit note.');

            return;
        } elseif ($this->source_type === 'over_payment' && empty($this->selectedOverPaymentIds)) {
            Flux::toast(variant: 'danger', text: 'Select at least one source payment.');

            return;
        }

        $paymentMethodId = $this->source_type === 'cash' ? $this->resolvedPaymentMethodId() : null;

        if ($this->payment === null) {
            try {
                $payment = DB::transaction(function () use ($paymentMethodId, $rows) {
                    $payment = Payment::create([
                        'customer_id' => $this->customer_id,
                        'payment_method_id' => $paymentMethodId,
                        'source_type' => $this->source_type,
                        'payment_reference' => $this->source_type === 'cash' ? ($this->payment_reference ?: null) : null,
                        'amount' => $this->source_type === 'cash' ? $this->amount : 0,
                        'payment_date' => $this->payment_date,
                        'notes' => $this->notes ?: null,
                        'created_by' => auth()->id(),
                    ]);

                    $this->fundPayment($payment, $rows);

                    [$paymentAllocations, $scope] = $this->deriveAllocations($rows);
                    app(PaymentAllocator::class)->saveAllocations($payment, $paymentAllocations, $scope);

                    return $payment;
                });
            } catch (\InvalidArgumentException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());

                return;
            }

            if ($this->receipt) {
                $ext = $this->receipt->getClientOriginalExtension();
                $receiptPath = $this->receipt->storeAs('payment-receipts', $payment->reference . '.' . $ext, 'public');
                $payment->update(['receipt_path' => $receiptPath]);
            }

            Flux::toast(variant: 'success', text: 'Payment recorded.');
            $this->redirect(route('payments.show', $payment), navigate: true);

            return;
        }

        $updateData = [
            'customer_id' => $this->customer_id,
            'payment_method_id' => $paymentMethodId,
            'source_type' => $this->source_type,
            'payment_reference' => $this->source_type === 'cash' ? ($this->payment_reference ?: null) : null,
            'payment_date' => $this->payment_date,
            'notes' => $this->notes ?: null,
        ];

        if ($this->source_type === 'cash') {
            $updateData['amount'] = $this->amount;
        }

        if ($this->receipt) {
            if ($this->existingReceiptPath && Storage::disk('public')->exists($this->existingReceiptPath)) {
                Storage::disk('public')->delete($this->existingReceiptPath);
            }
            $ext = $this->receipt->getClientOriginalExtension();
            $updateData['receipt_path'] = $this->receipt->storeAs('payment-receipts', $this->payment->reference . '.' . $ext, 'public');
            $this->existingReceiptPath = $updateData['receipt_path'];
        }

        try {
            DB::transaction(function () use ($updateData, $rows) {
                $this->payment->update($updateData);

                $this->fundPayment($this->payment, $rows);

                if (! empty($rows)) {
                    [$paymentAllocations, $scope] = $this->deriveAllocations($rows);
                    app(PaymentAllocator::class)->saveAllocations($this->payment, $paymentAllocations, $scope);
                }
            });

            $this->payment->refresh();
            $this->amount = number_format((float) $this->payment->amount, 2, '.', '');
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: 'Payment updated.');
        $this->redirect(route('payments.show', $this->payment), navigate: true);
    }

    /**
     * Applies this payment's funding source and tears down any stale funding
     * left over from a since-changed source type (e.g. a payment switched
     * from Over Payment back to Cash must release its old draws).
     */
    private function fundPayment(Payment $payment, array $rows = []): void
    {
        if ($this->source_type !== 'credit_note') {
            $payment->creditAllocations()->forceDelete();
        }
        if ($this->source_type !== 'over_payment') {
            PaymentDraw::where('target_payment_id', $payment->id)->forceDelete();
        }

        if ($this->source_type === 'credit_note') {
            [$paymentAllocations] = $this->deriveAllocations($rows);
            // Never shrink below what a later over-payment has already drawn from
            // this payment — only the amount beyond that is free to tighten down
            // to the invoices actually allocated.
            $committedDraws = $payment->exists ? (float) $payment->drawsMade()->sum('amount') : 0.0;
            $amountNeeded = round(array_sum($paymentAllocations) + $committedDraws, 2);
            app(PaymentAllocator::class)->fundFromCreditNotes($payment, $this->selectedCreditNoteIds, $amountNeeded);
        } elseif ($this->source_type === 'over_payment') {
            app(PaymentAllocator::class)->fundFromOverPayments(
                $payment,
                $this->selectedOverPaymentIds,
                $this->overPaymentExhaustIds,
                array_column($this->availableOverPaymentSources, 'id')
            );
        }
    }

    #[Computed]
    public function invoiceRows(): array
    {
        $customerId = $this->payment?->customer_id ?? $this->customer_id;
        if (! $customerId) {
            return [];
        }

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

        // Documents this payment already touches, plus anything pinned in by
        // search/auto-allocate, must stay visible regardless of the loaded
        // window or outstanding balance.
        $pinnedIds = $thisPaymentAllocations->keys()
            ->merge($thisPaymentCredits->keys())
            ->merge($this->extraDocumentIds)
            ->unique()
            ->values();

        $baseQuery = function () use ($customerId) {
            return Document::query()
                ->select(['id', 'doc_number', 'doc_date', 'total_value'])
                ->where('customer_id', $customerId)
                ->where('type', 'INV')
                ->groupBy('documents.id', 'documents.doc_number', 'documents.doc_date', 'documents.total_value')
                ->withSum('paymentAllocations', 'allocated_amount')
                ->withSum('creditAllocationsReceived', 'amount');
        };

        // Oldest-outstanding invoices, capped so a customer with tens of
        // thousands of invoices never gets them all hydrated/serialized at
        // once. "Load more" (loadedLimit) and search/auto-allocate (pinned
        // IDs, fetched separately below) extend what's visible from there.
        $windowed = $baseQuery()
            ->havingRaw('(total_value - COALESCE(payment_allocations_sum_allocated_amount, 0) - COALESCE(credit_allocations_received_sum_amount, 0)) > 0.001')
            ->orderBy('doc_date', 'asc')
            ->limit($this->loadedLimit)
            ->get();

        $this->hasMoreInvoices = $windowed->count() >= $this->loadedLimit;

        $missingPinnedIds = $pinnedIds->diff($windowed->pluck('id'));

        $pinnedRows = $missingPinnedIds->isNotEmpty()
            ? $baseQuery()->whereIn('id', $missingPinnedIds->all())->get()
            : collect();

        $invoices = $windowed->concat($pinnedRows)->sortBy('doc_date')->values();

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
        })->values()->toArray();
    }

}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="{{ $payment ? 'Edit: '.$payment->reference : 'Record Payment' }}"
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
            x-on:payment-method-resolved.window="_advanceFocus($refs.paymentMethodSelect)"
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
                            <flux:select
                                wire:model.live="paymentMethodSelection"
                                class="mt-1.5"
                                x-ref="paymentMethodSelect"
                                data-form-defer-advance
                                x-on:focus="$el.showPicker?.()"
                            >
                                <flux:select.option value="">— Select method —</flux:select.option>
                                @foreach($this->paymentMethods as $method)
                                    <flux:select.option value="lookup:{{ $method->id }}">{{ $method->name }}</flux:select.option>
                                @endforeach
                                <flux:select.option value="credit_note">{{ PaymentSourceType::CreditNote->label() }}</flux:select.option>
                                <flux:select.option value="over_payment">{{ PaymentSourceType::OverPayment->label() }}</flux:select.option>
                            </flux:select>
                            @error('paymentMethodSelection') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                        {{-- Amount --}}
                        @if($source_type === 'cash')
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
                        @else
                            <div>
                                <flux:label>{{ __('Amount') }}</flux:label>
                                <flux:input
                                    :value="number_format((float) $this->amount, 2)"
                                    prefix="£"
                                    readonly
                                    class="mt-1.5"
                                />
                            </div>
                        @endif

                        {{-- Date --}}
                        <flux:input
                            wire:model="payment_date"
                            type="date"
                            :label="__('Payment Date')"
                            required
                        />

                        {{-- Payment Reference (cash only) --}}
                        @if($source_type === 'cash')
                            <div>
                                <flux:input
                                    wire:model="payment_reference"
                                    :label="__('Payment Reference')"
                                    :placeholder="__('Cheque no. / transaction ref…')"
                                />
                            </div>
                        @endif

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

                        {{-- Credit Notes picker --}}
                        @if($source_type === 'credit_note')
                            <div class="md:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700/30 dark:bg-amber-500/5">
                                <h3 class="mb-3 text-sm font-semibold text-amber-800 dark:text-amber-400">Available Credit Notes — Fund This Payment</h3>
                                @if(count($this->availableCreditNotes) > 0)
                                    <div class="flex flex-col gap-2">
                                        @foreach($this->availableCreditNotes as $note)
                                            <label class="flex cursor-pointer items-center gap-3 text-sm">
                                                <flux:checkbox wire:model.live="selectedCreditNoteIds" :value="$note['id']" />
                                                <span class="font-mono font-semibold text-zinc-800 dark:text-zinc-200">{{ $note['doc_number'] }}</span>
                                                <span class="text-zinc-500 dark:text-zinc-400">{{ $note['doc_date'] }} {{ $note['time'] }}</span>
                                                <span class="ml-auto font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                                    £{{ number_format($note['remaining'], 2) }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 flex items-center justify-between border-t border-amber-200/70 pt-3 text-sm dark:border-amber-700/30">
                                        <span class="font-medium text-amber-800 dark:text-amber-400">Selected Total</span>
                                        <span class="font-mono font-semibold text-emerald-600 dark:text-emerald-400">£{{ number_format($this->selectedCreditNoteTotal, 2) }}</span>
                                    </div>
                                @else
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No unconsumed credit notes for this customer.</p>
                                @endif
                            </div>
                        @endif

                        {{-- Over Payments picker --}}
                        @if($source_type === 'over_payment')
                            <div class="md:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700/30 dark:bg-amber-500/5">
                                <h3 class="mb-3 text-sm font-semibold text-amber-800 dark:text-amber-400">Available Over Payments — Fund This Payment</h3>
                                @if(count($this->availableOverPaymentSources) > 0)
                                    <div class="flex flex-col gap-2">
                                        @foreach($this->availableOverPaymentSources as $src)
                                            <div class="flex items-center gap-3 text-sm">
                                                <flux:checkbox wire:model.live="selectedOverPaymentIds" :value="$src['id']" />
                                                <div class="flex flex-1 flex-wrap items-center gap-3">
                                                    <span class="font-mono font-semibold text-zinc-800 dark:text-zinc-200">{{ $src['reference'] }}</span>
                                                    @if($src['payment_reference'])
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $src['payment_reference'] }}</span>
                                                    @endif
                                                    @if(count($src['credit_note_refs']) > 0)
                                                        <span class="text-zinc-500 dark:text-zinc-400" x-data="{ expanded: false }">
                                                            {{ $src['method_label'] }}
                                                            (<span>{{ $src['credit_note_refs'][0] }}</span>@if(count($src['credit_note_refs']) > 1)<span x-show="!expanded" x-cloak>, <button type="button" class="underline hover:text-zinc-700 dark:hover:text-zinc-200" @click="expanded = true">+{{ count($src['credit_note_refs']) - 1 }} more</button></span><span x-show="expanded" x-cloak>, {{ implode(', ', array_slice($src['credit_note_refs'], 1)) }} <button type="button" class="underline hover:text-zinc-700 dark:hover:text-zinc-200" @click="expanded = false">show less</button></span>@endif)
                                                        </span>
                                                    @else
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $src['method_label'] }}</span>
                                                    @endif
                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $src['payment_date'] }} {{ $src['time'] }}</span>
                                                    <span class="ml-auto font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                                        £{{ number_format($src['remaining'], 2) }}
                                                    </span>
                                                </div>
                                                {{-- Mark exhausted temporarily hidden
                                                <label class="flex shrink-0 items-center gap-1.5 border-l border-amber-200/70 pl-3 text-xs text-zinc-500 dark:border-amber-700/30 dark:text-zinc-400">
                                                    <flux:checkbox wire:model.live="overPaymentExhaustIds" :value="$src['id']" />
                                                    Mark exhausted
                                                </label>
                                                --}}
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 flex items-center justify-between border-t border-amber-200/70 pt-3 text-sm dark:border-amber-700/30">
                                        <span class="font-medium text-amber-800 dark:text-amber-400">Selected Total</span>
                                        <span class="font-mono font-semibold text-emerald-600 dark:text-emerald-400">£{{ number_format($this->selectedOverPaymentTotal, 2) }}</span>
                                    </div>
                                @else
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No available over-payment sources for this customer.</p>
                                @endif
                            </div>
                        @endif

                    </div>

                </div>
            </form>

            {{-- Allocation section — visible as soon as a customer is selected --}}
            {{-- wire:ignore prevents Livewire morphs (amount blur, method change) from wiping Alpine rows state --}}
            <div
                wire:ignore
                x-data="paymentAllocator({ rows: @js($this->invoiceRows), hasMore: @js($this->hasMoreInvoices) })"
                x-on:keydown="handleKey($event)"
                @save-payment-form.window="$wire.save(relevantRows)"
                @payment-rows-updated.window="rows = $event.detail.rows.map(r => ({ ...r, amount: r.existing_allocation })); hasMore = $event.detail.hasMore"
                @payment-rows-appended.window="appendRows($event.detail.rows, $event.detail.hasMore)"
                @payment-auto-allocated.window="applyAutoAllocation($event.detail.rows, $event.detail.allocations, $event.detail.hasMore)"
            >
                <div x-show="$wire.customer_id" x-cloak>

                    {{-- Allocation table --}}
                    <div>
                        <x-ui.section-card>
                            <x-slot:header>
                                <div class="flex w-full flex-wrap items-center justify-between gap-3">
                                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Allocations</h2>
                                    <div class="flex items-center gap-2">
                                        {{-- Invoice search temporarily hidden
                                        <flux:input
                                            type="text"
                                            size="sm"
                                            placeholder="{{ __('Find invoice #, press Enter…') }}"
                                            class="w-56"
                                            x-on:keydown.enter.prevent="$wire.searchInvoice($event.target.value); $event.target.value = ''"
                                        />
                                        --}}
                                        <flux:button variant="ghost" size="sm" x-show="isModified" x-cloak @click="resetAllocations()">
                                            Reset
                                        </flux:button>
                                        <flux:button variant="ghost" size="sm" @click="$wire.autoAllocate(paymentAmount)">
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
                                                <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Max Available</th>
                                                <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">This Payment</th>
                                                <th class="pb-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Outstanding After</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in rows" :key="row.id">
                                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                                    <td class="py-3 pr-4 font-mono text-sm text-zinc-900 dark:text-white" x-text="row.doc_number"></td>
                                                    <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-400" x-text="row.doc_date"></td>
                                                    <td class="py-3 pr-4 text-right font-mono text-zinc-900 dark:text-white" x-text="'£' + row.total_value.toFixed(2)"></td>
                                                    <td class="py-3 pr-4 text-right font-mono text-zinc-600 dark:text-zinc-400" x-text="'£' + row.max_allocatable.toFixed(2)"></td>
                                                    <td class="py-3 pr-4 text-right">
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            :max="row.max_allocatable"
                                                            x-model="row.amount"
                                                            data-payment-alloc-input
                                                            @blur="row.amount = Math.min(parseFloat(row.amount)||0, row.max_allocatable)"
                                                            @focus="focusRow(row); $nextTick(() => $el.select())"
                                                            class="w-28 rounded-lg border border-zinc-300 px-2 py-1 text-right font-mono text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                                        />
                                                    </td>
                                                    <td class="py-3 text-right font-mono" :class="(row.max_allocatable - (parseFloat(row.amount)||0)) <= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-white'" x-text="'£' + Math.max(0, row.max_allocatable - (parseFloat(row.amount)||0)).toFixed(2)"></td>
                                                </tr>
                                            </template>
                                            <tr x-show="rows.length === 0">
                                                <td colspan="6" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No invoices found for this customer.</td>
                                            </tr>
                                            <tr x-show="hasMore" x-ref="loadSentinel">
                                                <td colspan="6" class="py-3 text-center text-xs text-zinc-400 dark:text-zinc-500">
                                                    <span x-show="loadingMore">{{ __('Loading more invoices…') }}</span>
                                                </td>
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
                                                <td class="pt-3 text-right" colspan="3">
                                                    <div class="font-mono font-semibold" :class="budgetRemaining < 0 ? 'text-red-600 dark:text-red-400' : budgetRemaining > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'" x-text="'£' + budgetRemaining.toFixed(2)"></div>
                                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Remaining</div>
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
