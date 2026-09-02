<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Services\CreditTermDueDateCalculator;
use App\Traits\WithPerPage;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customer Details')] class extends Component {
    use WithPagination;
    use WithPerPage;
    use WithSorting;

    protected array $sortable = ['date', 'ref_no', 'type', 'amount'];

    public Customer $customer;

    #[Url(as: 'tab')]
    public string $activeTab = 'transaction-history';

    #[Url(as: 'ledger_search')]
    public string $ledgerSearch = '';

    public int $perPage = 25;

    #[Computed]
    public function availableCredit(): float
    {
        return Document::availableCreditForCustomer($this->customer->id);
    }

    #[Computed]
    public function previousCustomer(): ?Customer
    {
        return Customer::where('id', '<', $this->customer->id)->orderByDesc('id')->first();
    }

    #[Computed]
    public function nextCustomer(): ?Customer
    {
        return Customer::where('id', '>', $this->customer->id)->orderBy('id')->first();
    }

    #[Computed]
    public function stats(): array
    {
        $invoices = $this->customer->invoices();

        $balance = (clone $invoices)
            ->withSum('paymentAllocations as allocated_total', 'allocated_amount')
            ->withSum('creditAllocationsReceived as credited_total', 'amount')
            ->get()
            ->sum(fn (Document $invoice) => $invoice->total_value - ($invoice->allocated_total ?? 0) - ($invoice->credited_total ?? 0));

        $onAccount = $this->customer->payments()
            ->withSum('allocations as allocations_sum_allocated_amount', 'allocated_amount')
            ->withSum('drawsMade as draws_made_sum_amount', 'amount')
            ->get()
            ->sum(fn ($payment) => max(0, $payment->amount - ($payment->allocations_sum_allocated_amount ?? 0) - ($payment->draws_made_sum_amount ?? 0)));

        $now = now();

        $parseDate = fn (?string $date): ?Carbon => $date ? Carbon::parse($date) : null;

        return [
            'balance' => $balance,
            'on_account' => $onAccount,
            'sales_ytd' => (clone $invoices)->whereYear('doc_date', $now->year)->sum('total_value'),
            'sales_period' => (clone $invoices)->whereYear('doc_date', $now->year)->whereMonth('doc_date', $now->month)->sum('total_value'),
            'first_invoice' => $parseDate((clone $invoices)->min('doc_date')),
            'last_invoice' => $parseDate((clone $invoices)->max('doc_date')),
            'last_payment' => $parseDate($this->customer->payments()->max('payment_date')),
            'last_amended' => $this->customer->updated_at,
        ];
    }

    #[Computed]
    public function invoices()
    {
        return $this->customer->invoices()->with('assignee')->orderByDesc('doc_date')->orderByDesc('doc_number')->paginate(100, pageName: 'inv_page');
    }

    #[Computed]
    public function deliveryNotes()
    {
        return $this->customer->deliveryNotes()
            ->with('assignee')
            ->orderByDesc('doc_date')
            ->orderByDesc('doc_number')
            ->paginate(25, pageName: 'dn_page');
    }

    #[Computed]
    public function creditNotes()
    {
        return $this->customer->creditNotes()->with(['assignee', 'creditedInvoice'])->orderByDesc('doc_date')->orderByDesc('doc_number')->paginate(100, pageName: 'cn_page');
    }

    #[Computed]
    public function payments()
    {
        return $this->customer->payments()->with('paymentMethod')->withSum('allocations', 'allocated_amount')->orderByDesc('payment_date')->orderByDesc('reference')->paginate(100, pageName: 'pay_page');
    }

    #[Computed]
    public function ledgerPaymentsMap(): array
    {
        return $this->buildTransactionLedgerRows['payments'];
    }

    #[Computed]
    public function ledgerCreditNotesMap(): array
    {
        return $this->buildTransactionLedgerRows['credit_notes'];
    }

    #[Computed]
    public function ledgerInvoicesMap(): array
    {
        return $this->buildTransactionLedgerRows['invoices'];
    }

    #[Computed]
    public function transactionLedger(): LengthAwarePaginator
    {
        $rows = $this->buildTransactionLedgerRows['rows'];

        if ($this->ledgerSearch !== '') {
            $term = mb_strtolower($this->ledgerSearch);
            $rows = array_values(array_filter($rows, function (array $row) use ($term) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['ref_no'],
                    $row['order_ref'],
                    $row['method_label'] ?? null,
                    match ($row['type']) {
                        'invoice' => 'invoice',
                        'credit_note' => 'credit note',
                        'payment' => 'payment',
                    },
                ])));

                return str_contains($haystack, $term);
            }));
        }

        if ($this->sortColumn !== '' && in_array($this->sortColumn, $this->sortableColumns(), true)) {
            $direction = $this->sortDirection === 'desc' ? -1 : 1;

            usort($rows, function (array $a, array $b) use ($direction) {
                $result = match ($this->sortColumn) {
                    'date' => $a['date'] <=> $b['date'],
                    'ref_no' => strnatcasecmp($a['ref_no'], $b['ref_no']),
                    'type' => strcmp($a['type'], $b['type']),
                    'amount' => $a['amount'] <=> $b['amount'],
                    default => 0,
                };

                return $result * $direction;
            });
        }

        $page = Paginator::resolveCurrentPage('ledger_page');
        $items = Collection::make($rows);

        return new LengthAwarePaginator(
            $items->forPage($page, $this->perPage)->values(),
            $items->count(),
            $this->perPage,
            $page,
            ['pageName' => 'ledger_page'],
        );
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, payments: array<int, array<string, mixed>>, credit_notes: array<int, array<string, mixed>>, invoices: array<int, array<string, mixed>>}
     */
    #[Computed]
    public function buildTransactionLedgerRows(): array
    {
        $rows = [];
        $paymentDetailsByRef = [];
        $creditNoteDetailsByRef = [];
        $invoiceDetailsByRef = [];

        $invoices = $this->customer->invoices()
            ->with(['paymentAllocations.payment.paymentMethod', 'creditAllocationsReceived.creditNote'])
            ->withSum('paymentAllocations as allocated_total', 'allocated_amount')
            ->withSum('creditAllocationsReceived as credited_total', 'amount')
            ->get();

        foreach ($invoices as $invoice) {
            $allocatedTotal = (float) ($invoice->allocated_total ?? 0);
            $creditedTotal = (float) ($invoice->credited_total ?? 0);
            $balance = (float) $invoice->total_value - $allocatedTotal - $creditedTotal;
            $dueDate = CreditTermDueDateCalculator::calculate($this->customer->creditTerm?->name, $invoice->doc_date);

            $isOverdue = $dueDate && $dueDate->isPast() && $balance > 0;
            $isPartial = $balance > 0 && $balance < (float) $invoice->total_value;

            if ($balance <= 0.005) {
                $status = 'paid';
            } elseif ($isOverdue && $isPartial) {
                $status = 'overdue_partial';
            } elseif ($isOverdue) {
                $status = 'overdue';
            } elseif ($isPartial) {
                $status = 'partial';
            } else {
                $status = 'outstanding';
            }

            $allocations = [];

            foreach ($invoice->paymentAllocations as $paymentAllocation) {
                $payment = $paymentAllocation->payment;

                $allocations[] = [
                    'kind' => 'payment',
                    'ref' => $payment->reference,
                    'label' => $payment->paymentMethod?->name ?? 'Payment',
                    'date' => $payment->payment_date,
                    'amount' => (float) $paymentAllocation->allocated_amount,
                    'route' => route('payments.show', $payment),
                    'payment_id' => $payment->id,
                    'credit_note_id' => null,
                ];
            }

            foreach ($invoice->creditAllocationsReceived as $creditAllocation) {
                $creditNote = $creditAllocation->creditNote;

                if ($creditNote === null) {
                    continue;
                }

                $allocations[] = [
                    'kind' => 'credit',
                    'ref' => $creditNote->doc_number,
                    'label' => 'Credit Note',
                    'date' => $creditNote->doc_date,
                    'amount' => (float) $creditAllocation->amount,
                    'route' => route('credit-notes.show', $creditNote),
                    'payment_id' => null,
                    'credit_note_id' => $creditNote->id,
                ];
            }

            usort($allocations, fn (array $a, array $b) => $b['date'] <=> $a['date']);

            $paidDate = $balance <= 0.005 && $allocations !== []
                ? collect($allocations)->pluck('date')->max()
                : null;

            $details = [
                'kind' => 'invoice',
                'total' => (float) $invoice->total_value,
                'paid' => $allocatedTotal + $creditedTotal,
                'outstanding' => $balance,
                'due_date' => $dueDate,
                'paid_date' => $paidDate,
                'allocations' => $allocations,
            ];

            $invoiceDetailsByRef[$invoice->id] = [
                'ref_no' => $invoice->doc_number,
                'date' => $invoice->doc_date,
                'order_ref' => $invoice->order_no,
                'total' => (float) $invoice->total_value,
                'outstanding' => $balance,
                'status' => $status,
                'due_date' => $dueDate,
            ];

            $rows[] = [
                'date' => $invoice->doc_date,
                'type' => 'invoice',
                'ref_no' => $invoice->doc_number,
                'order_ref' => $invoice->order_no,
                'amount' => (float) $invoice->total_value,
                'outstanding' => $balance,
                'route' => route('invoices.show', $invoice),
                'status' => $status,
                'details' => $details,
            ];
        }

        $creditNotes = $this->customer->creditNotes()
            ->with(['assignee', 'creditedInvoice', 'creditAllocations.invoice'])
            ->withSum('creditAllocations as applied_total', 'amount')
            ->get();

        foreach ($creditNotes as $creditNote) {
            $total = (float) $creditNote->total_value;
            $appliedTotal = (float) ($creditNote->applied_total ?? 0);
            $unappliedBalance = $total - $appliedTotal;

            if ($appliedTotal >= $total - 0.005) {
                $status = 'applied';
            } elseif ($appliedTotal <= 0.005) {
                $status = 'unapplied';
            } else {
                $status = 'partial';
            }

            $appliedTo = [];

            foreach ($creditNote->creditAllocations as $creditAllocation) {
                $invoice = $creditAllocation->invoice;

                if ($invoice === null) {
                    continue;
                }

                $appliedTo[] = [
                    'ref' => $invoice->doc_number,
                    'route' => route('invoices.show', $invoice),
                    'date' => $invoice->doc_date,
                    'amount' => (float) $creditAllocation->amount,
                    'invoice_id' => $invoice->id,
                ];
            }

            $details = [
                'kind' => 'credit_note',
                'total' => $total,
                'applied_total' => $appliedTotal,
                'outstanding' => $unappliedBalance,
                'raised_against' => $creditNote->creditedInvoice?->doc_number,
                'raised_against_route' => $creditNote->creditedInvoice
                    ? route('invoices.show', $creditNote->creditedInvoice)
                    : null,
                'raised_against_id' => $creditNote->creditedInvoice?->id,
                'applied_to' => $appliedTo,
            ];

            $creditNoteDetailsByRef[$creditNote->id] = [
                'ref_no' => $creditNote->doc_number,
                'date' => $creditNote->doc_date,
                'total' => $total,
                'applied_total' => $appliedTotal,
                'outstanding' => $unappliedBalance,
                'status' => $status,
            ];

            $rows[] = [
                'date' => $creditNote->doc_date,
                'type' => 'credit_note',
                'ref_no' => $creditNote->doc_number,
                'order_ref' => $creditNote->order_no,
                'amount' => -$total,
                'outstanding' => -$unappliedBalance,
                'route' => route('credit-notes.show', $creditNote),
                'status' => $status,
                'details' => $details,
            ];
        }

        $payments = $this->customer->payments()
            ->with(['paymentMethod', 'allocations.document'])
            ->withSum('allocations as allocations_sum_allocated_amount', 'allocated_amount')
            ->withSum('drawsMade as draws_made_sum_amount', 'amount')
            ->get();

        foreach ($payments as $payment) {
            $details = $this->buildPaymentDetails($payment);
            $paymentDetailsByRef[$payment->id] = $details;

            $unallocated = $details['unallocated'];
            $status = $details['status'];

            $rows[] = [
                'date' => $payment->payment_date,
                'type' => 'payment',
                'ref_no' => $payment->reference,
                'order_ref' => null,
                'amount' => -(float) $payment->amount,
                'outstanding' => -$unallocated,
                'route' => route('payments.show', $payment),
                'status' => $status,
                'details' => $details,
                'method_label' => $payment->paymentMethod?->name ?? 'Payment',
            ];
        }

        $missingPaymentIds = [];

        foreach ($rows as $row) {
            if ($row['type'] !== 'invoice') {
                continue;
            }

            foreach ($row['details']['allocations'] as $allocation) {
                if ($allocation['kind'] !== 'payment' || $allocation['payment_id'] === null) {
                    continue;
                }

                if (! isset($paymentDetailsByRef[$allocation['payment_id']])) {
                    $missingPaymentIds[] = $allocation['payment_id'];
                }
            }
        }

        if ($missingPaymentIds !== []) {
            $backfilledPayments = Payment::withTrashed()
                ->with(['paymentMethod', 'allocations.document'])
                ->withSum('allocations as allocations_sum_allocated_amount', 'allocated_amount')
                ->withSum('drawsMade as draws_made_sum_amount', 'amount')
                ->whereIn('id', array_unique($missingPaymentIds))
                ->get();

            foreach ($backfilledPayments as $payment) {
                $paymentDetailsByRef[$payment->id] = $this->buildPaymentDetails($payment);
            }
        }

        usort($rows, fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return ['rows' => $rows, 'payments' => $paymentDetailsByRef, 'credit_notes' => $creditNoteDetailsByRef, 'invoices' => $invoiceDetailsByRef];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPaymentDetails(Payment $payment): array
    {
        $allocatedSum = (float) ($payment->allocations_sum_allocated_amount ?? 0);
        $drawsSum = (float) ($payment->draws_made_sum_amount ?? 0);
        $unallocated = max(0, (float) $payment->amount - $allocatedSum - $drawsSum);

        if ($unallocated <= 0.005 && $allocatedSum > 0.005) {
            $status = 'applied';
        } elseif ($unallocated <= 0.005) {
            // Fully consumed by draws to other payments, not by invoice allocations.
            $status = 'drawn';
        } elseif ($unallocated >= (float) $payment->amount - 0.005) {
            $status = 'unapplied';
        } else {
            $status = 'partial';
        }

        $allocations = [];

        foreach ($payment->allocations as $allocation) {
            $invoice = $allocation->document;

            $allocations[] = [
                'ref' => $invoice->doc_number,
                'route' => route('invoices.show', $invoice),
                'date' => $invoice->doc_date,
                'amount' => (float) $allocation->allocated_amount,
                'invoice_id' => $invoice->id,
            ];
        }

        return [
            'kind' => 'payment',
            'method' => $payment->paymentMethod?->name ?? 'Payment',
            'total' => (float) $payment->amount,
            'allocated' => $allocatedSum,
            'unallocated' => $unallocated,
            'status' => $status,
            'allocations' => $allocations,
        ];
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage('inv_page');
        $this->resetPage('dn_page');
        $this->resetPage('cn_page');
        $this->resetPage('pay_page');
        $this->resetPage('ledger_page');
        unset($this->invoices, $this->deliveryNotes, $this->creditNotes, $this->payments, $this->transactionLedger);
    }

    public function updatedLedgerSearch(): void
    {
        $this->resetPage('ledger_page');
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortColumn !== $column) {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        } elseif ($this->sortDirection === 'asc') {
            $this->sortDirection = 'desc';
        } else {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }

        $this->resetPage('ledger_page');
    }

    public function updatedPerPage(): void
    {
        $this->resetPage('ledger_page');
    }

    #[On('customer-deleted')]
    public function onDeleted(): void
    {
        $this->redirect(route('customers.index'), navigate: true);
    }
}; ?>

<div
    class="flex flex-col gap-6"
    x-data="showPageKeys({
        edit: () => Livewire.navigate('{{ route('customers.edit', $customer) }}'),
        delete: () => $store.hotkeys.openModalWithConfirm('delete-customer-{{ $customer->id }}'),
    })"
>

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('customers.index')" wire:navigate>Back</flux:button>
        <div class="flex items-center gap-2">
            @can('customer-edit')
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('customers.edit', $customer)" wire:navigate>
                Edit
                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">e</kbd>
            </flux:button>
            @endcan
            @can('customer-delete')
            <livewire:pages::customers.delete-modal :customer="$customer" :key="'delete-'.$customer->id" />
            @endcan
            <livewire:pages::customers.statement-modal :customer="$customer" :key="'statement-'.$customer->id" />

            <div class="flex items-center overflow-hidden rounded-lg border border-zinc-200/70 dark:border-white/10">
                @if($this->previousCustomer)
                    <flux:button variant="ghost" icon="chevron-left" size="sm" class="!rounded-none" :href="route('customers.show', $this->previousCustomer)" wire:navigate title="{{ __('Previous customer: :name', ['name' => $this->previousCustomer->company_name]) }}" />
                @else
                    <flux:button variant="ghost" icon="chevron-left" size="sm" class="!rounded-none" disabled />
                @endif
                <div class="h-5 w-px bg-zinc-200 dark:bg-white/10"></div>
                @if($this->nextCustomer)
                    <flux:button variant="ghost" icon="chevron-right" size="sm" class="!rounded-none" :href="route('customers.show', $this->nextCustomer)" wire:navigate title="{{ __('Next customer: :name', ['name' => $this->nextCustomer->company_name]) }}" />
                @else
                    <flux:button variant="ghost" icon="chevron-right" size="sm" class="!rounded-none" disabled />
                @endif
            </div>
        </div>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-fuchsia-500"></div>

        {{-- Floating avatar --}}
        <div class="absolute left-6 top-10 ring-4 ring-white dark:ring-zinc-900 rounded-full">
            <x-ui.avatar :name="$customer->company_name" size="xl" />
        </div>

        <div class="px-4 pb-4 pt-14">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $customer->company_name }}</h1>
                    @if($customer->reference)
                        <p class="mt-0.5 font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $customer->reference }}</p>
                    @endif
                </div>
                @can('deliverynote-create')
                <flux:button variant="primary" icon="plus" size="sm" :href="route('delivery-notes.create').'?customer_id='.$customer->id" wire:navigate>
                    New Delivery Note
                </flux:button>
                @endcan
            </div>
        </div>
    </div>

    {{-- Details + financial sidebar (legacy col-8 / col-4 split) --}}
    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Left: details (lg:col-span-2) --}}
        <div class="lg:col-span-2">
            <x-ui.section-card>
                <x-slot:header>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Customer Details</h2>
                </x-slot:header>
                <dl class="space-y-4">
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Name</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white text-right">
                            {{ trim(($customer->title?->name ? $customer->title->name.' ' : '').$customer->first_name.' '.$customer->last_name) ?: '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Email</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $customer->email_1 ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Address</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white text-right">
                            {{ collect([$customer->address_1, $customer->address_2, $customer->town, $customer->post_code])->filter()->implode(', ') ?: '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Category</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $customer->category?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Revenue</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $customer->revenueType?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Trade Discount</dt>
                        <dd>
                            @if($customer->trade_discount > 0)
                                <span class="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400">
                                    {{ $customer->trade_discount }}%
                                </span>
                            @else
                                <span class="text-sm text-zinc-400">None</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Credit Terms</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $customer->creditTerm?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Credit Limit</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">
                            {{ $customer->creditLimit ? '£'.number_format($customer->creditLimit->amount, 2) : '—' }}
                        </dd>
                    </div>
                </dl>
            </x-ui.section-card>
        </div>

        {{-- Right: financial sidebar (lg:col-span-1) --}}
        <div class="flex flex-col gap-4 lg:col-span-1">

            <x-ui.section-card>
                <x-slot:header>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Account Summary</h2>
                </x-slot:header>
                <dl class="space-y-4">
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Balance
                            <flux:tooltip content="Total owed across all invoices, after payments and credit notes already applied.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm font-semibold text-rose-600 dark:text-rose-400">£{{ number_format($this->stats['balance'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            On Account
                            <flux:tooltip content="Money the customer has paid that hasn't been applied to an invoice yet.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm font-semibold {{ $this->stats['on_account'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-white' }}">£{{ number_format($this->stats['on_account'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Available Credit
                            <flux:tooltip content="Credit notes issued to this customer that haven't been used against an invoice yet.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm font-semibold {{ $this->availableCredit > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }}">£{{ number_format($this->availableCredit, 2) }}</dd>
                    </div>
                </dl>

                <h3 class="mt-5 mb-2 border-b border-zinc-100 pb-1.5 text-sm font-bold uppercase tracking-wide text-indigo-600 dark:border-white/10 dark:text-indigo-400">Sales</h3>
                <dl class="space-y-4">
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Year to Date
                            <flux:tooltip content="Total value of invoices raised for this customer so far in the current calendar year.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">£{{ number_format($this->stats['sales_ytd'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Period
                            <flux:tooltip content="Total value of invoices raised for this customer so far in the current calendar month.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">£{{ number_format($this->stats['sales_period'], 2) }}</dd>
                    </div>
                </dl>

                <h3 class="mt-5 mb-2 border-b border-zinc-100 pb-1.5 text-sm font-bold uppercase tracking-wide text-indigo-600 dark:border-white/10 dark:text-indigo-400">Movement</h3>
                <dl class="space-y-4">
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            First Invoice
                            <flux:tooltip content="Date of the earliest invoice ever raised for this customer.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->stats['first_invoice']?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Last Invoice
                            <flux:tooltip content="Date of the most recent invoice raised for this customer.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->stats['last_invoice']?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Last Payment
                            <flux:tooltip content="Date of the most recent payment received from this customer.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->stats['last_payment']?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="flex items-center gap-1 text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            Last Amended
                            <flux:tooltip content="Date this customer's record was last updated.">
                                <flux:icon.information-circle class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                            </flux:tooltip>
                        </dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->stats['last_amended']?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-ui.section-card>
        </div>
    </div>

    {{-- Four-tab document panel --}}
    <x-ui.section-card :padding="false">

        {{-- Tab bar --}}
        <div class="flex border-b border-zinc-200 dark:border-white/10">
            {{--
            <button
                wire:click="$set('activeTab', 'invoices')"
                class="flex items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-medium transition-colors focus:outline-none {{ $activeTab === 'invoices' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >
                <flux:icon.document-text class="h-4 w-4" />
                Invoices
            </button>
            <button
                wire:click="$set('activeTab', 'delivery-notes')"
                class="flex items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-medium transition-colors focus:outline-none {{ $activeTab === 'delivery-notes' ? 'border-sky-500 text-sky-600 dark:text-sky-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >
                <flux:icon.truck class="h-4 w-4" />
                Delivery Notes
            </button>
            <button
                wire:click="$set('activeTab', 'credit-notes')"
                class="flex items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-medium transition-colors focus:outline-none {{ $activeTab === 'credit-notes' ? 'border-amber-500 text-amber-600 dark:text-amber-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >
                <flux:icon.receipt-refund class="h-4 w-4" />
                Credit Notes
            </button>
            <button
                wire:click="$set('activeTab', 'payments')"
                class="flex items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-medium transition-colors focus:outline-none {{ $activeTab === 'payments' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >
                <flux:icon.banknotes class="h-4 w-4" />
                Payments
            </button>
            --}}
            <button
                wire:click="$set('activeTab', 'transaction-history')"
                class="flex items-center gap-1.5 border-b-2 px-4 py-3 text-sm font-medium transition-colors focus:outline-none {{ $activeTab === 'transaction-history' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >
                <flux:icon.clock class="h-4 w-4" />
                Transaction History
            </button>
        </div>

        {{-- Transaction History panel --}}
        @if($activeTab === 'transaction-history')
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 p-3 dark:border-white/10">
                <flux:input
                    wire:model.live.debounce.300ms="ledgerSearch"
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by ref, order ref or type…')"
                    clearable
                    class="max-w-sm"
                />
                <x-ui.per-page-select />
            </div>

            @if($this->transactionLedger->isEmpty())
                <x-ui.empty-state
                    icon="clock"
                    title="No transactions yet"
                    :description="$ledgerSearch !== '' ? 'Try adjusting your search.' : 'Invoices, credit notes, and payments for this customer will appear here.'"
                />
            @else
                <div
                    x-data="ledgerTable()"
                    x-on:keydown="onKeydown($event)"
                    tabindex="0"
                    class="overflow-x-auto rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30"
                >
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <x-ui.sortable-header column="date" :state="$this->sortStateFor('date')">Date</x-ui.sortable-header>
                                <x-ui.sortable-header column="type" :state="$this->sortStateFor('type')">Type</x-ui.sortable-header>
                                <x-ui.sortable-header column="ref_no" :state="$this->sortStateFor('ref_no')">Invoice / Ref No.</x-ui.sortable-header>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Order Reference</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                <x-ui.sortable-header column="amount" align="right" :state="$this->sortStateFor('amount')">Amount (incl. VAT)</x-ui.sortable-header>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Outstanding Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($this->transactionLedger as $row)
                                @php
                                    $rowKey = $row['type'].':'.$row['ref_no'];
                                    $statusClasses = match ($row['status']) {
                                        'overdue' => 'text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20',
                                        'overdue_partial' => 'text-orange-600 bg-orange-50 border-orange-200 dark:text-orange-400 dark:bg-orange-500/10 dark:border-orange-500/20',
                                        'paid', 'applied' => 'text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
                                        'partial' => 'text-amber-600 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
                                        'unapplied' => 'text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20',
                                        'drawn' => 'text-sky-600 bg-sky-50 border-sky-200 dark:text-sky-400 dark:bg-sky-500/10 dark:border-sky-500/20',
                                        default => 'text-zinc-600 bg-zinc-100 border-zinc-200 dark:text-zinc-300 dark:bg-zinc-800 dark:border-zinc-700',
                                    };
                                    $statusLabel = match ($row['status']) {
                                        'overdue' => 'Overdue',
                                        'overdue_partial' => 'Overdue & Partial',
                                        'paid' => 'Paid',
                                        'partial' => 'Partial',
                                        'applied' => 'Applied',
                                        'unapplied' => 'Unapplied',
                                        'drawn' => 'Transferred',
                                        default => 'Outstanding',
                                    };
                                    $rowBgClasses = match ($row['status']) {
                                        'overdue' => 'bg-rose-50/40 dark:bg-rose-500/5',
                                        'overdue_partial' => 'bg-orange-50/40 dark:bg-orange-500/5',
                                        'paid', 'applied' => 'bg-emerald-50/40 dark:bg-emerald-500/5',
                                        'partial' => 'bg-amber-50/40 dark:bg-amber-500/5',
                                        'unapplied' => 'bg-rose-50/20 dark:bg-rose-500/[0.03]',
                                        'drawn' => 'bg-sky-50/40 dark:bg-sky-500/5',
                                        default => '',
                                    };
                                @endphp
                                <tr
                                    data-row-index="{{ $loop->index }}"
                                    data-row-key="{{ $rowKey }}"
                                    wire:key="ledger-row-{{ $row['type'] }}-{{ $row['ref_no'] }}"
                                    :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': selectedIndex === {{ $loop->index }} }"
                                    x-on:click="if ($event.target.closest('a,button')) return; selectRow({{ $loop->index }}, '{{ $rowKey }}')"
                                    :aria-expanded="(openKey === '{{ $rowKey }}').toString()"
                                    class="cursor-pointer transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5 {{ $rowBgClasses }}"
                                >
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $row['date']->format('d M Y') }}</td>
                                    <td class="px-4 py-2">
                                        @if($row['type'] === 'payment')
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400">
                                                {{ $row['method_label'] }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                                {{ $row['type'] === 'credit_note' ? 'Credit Note' : 'Invoice' }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        <a href="{{ $row['route'] }}" wire:navigate class="font-mono text-xs font-semibold rounded border px-2 py-0.5 {{ $statusClasses }}">
                                            {{ $row['ref_no'] }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $row['order_ref'] ?: '—' }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ $row['amount'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $row['amount'] < 0 ? '-' : '+' }}£{{ number_format(abs($row['amount']), 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ abs($row['outstanding']) <= 0.005 ? 'text-emerald-600 dark:text-emerald-400' : ($row['outstanding'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white') }}">
                                        £{{ number_format(abs($row['outstanding']), 2) }}
                                    </td>
                                </tr>
                                <tr wire:key="ledger-detail-{{ $row['type'] }}-{{ $row['ref_no'] }}" x-show="openKey === '{{ $rowKey }}'" x-cloak>
                                    <td colspan="7" class="px-4 pb-3">
                                        <div class="border-l-4 border-indigo-400 dark:border-indigo-500 bg-white dark:bg-zinc-900 rounded-r-md shadow-sm p-4">
                                            <div class="mb-3 flex items-center justify-between gap-2">
                                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Transaction Details</h4>
                                                <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openKey = null" />
                                            </div>
                                            <x-ui.ledger-detail :row="$row" :payments="$this->ledgerPaymentsMap" :credit-notes="$this->ledgerCreditNotesMap" :invoices="$this->ledgerInvoicesMap" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:pagination :paginator="$this->transactionLedger" />
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4 px-4 pb-4">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20">Overdue</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Unpaid and past due date</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-orange-600 bg-orange-50 border-orange-200 dark:text-orange-400 dark:bg-orange-500/10 dark:border-orange-500/20">Overdue &amp; Partial</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Partially paid and past due date</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20">Paid</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Fully paid — expand to view payment source</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-amber-600 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20">Partial</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Partially paid — expand for breakdown</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-zinc-600 bg-zinc-100 border-zinc-200 dark:text-zinc-300 dark:bg-zinc-800 dark:border-zinc-700">Outstanding</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Open invoice, not yet due</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20">Applied</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Credit / Payment linked to invoice(s)</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20">Unapplied</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Credit / Payment on account (unallocated)</p>
                    </div>
                </div>
            @endif
        @endif

        {{--
        @if($activeTab === 'invoices')
            @if($this->invoices->isEmpty())
                <x-ui.empty-state
                    icon="document-text"
                    title="No invoices yet"
                    description="Invoices will appear here once delivery notes are converted."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Order Ref</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Sales Person</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($this->invoices as $invoice)
                                <tr class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="font-mono text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                            {{ $invoice->doc_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $invoice->doc_date->format('d M Y') }}</td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $invoice->order_no ?: '—' }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($invoice->total_value, 2) }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $invoice->status->ringColor() }}">
                                            {{ $invoice->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $invoice->assignee?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                        {{ $this->invoices->links() }}
                    </div>
                </div>
            @endif
        @endif

        @if($activeTab === 'delivery-notes')
            @if($this->deliveryNotes->isEmpty())
                <x-ui.empty-state
                    icon="truck"
                    title="No delivery notes yet"
                    description="Create the first delivery note for this customer."
                >
                    <x-slot:action>
                        @can('deliverynote-create')
                        <flux:button variant="primary" size="sm" :href="route('delivery-notes.create').'?customer_id='.$customer->id" wire:navigate>
                            New Delivery Note
                        </flux:button>
                        @endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Order Ref</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Sales Person</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($this->deliveryNotes as $note)
                                <tr class="transition-colors hover:bg-sky-50/40 dark:hover:bg-sky-500/5">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('delivery-notes.show', $note) }}" wire:navigate class="font-mono text-sm font-semibold text-sky-600 hover:underline dark:text-sky-400">
                                            {{ $note->doc_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $note->doc_date->format('d M Y') }}</td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $note->order_no ?: '—' }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($note->total_value, 2) }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $note->status->ringColor() }}">
                                            {{ $note->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $note->assignee?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                        {{ $this->deliveryNotes->links() }}
                    </div>
                </div>
            @endif
        @endif

        @if($activeTab === 'credit-notes')
            @if($this->creditNotes->isEmpty())
                <x-ui.empty-state
                    icon="receipt-refund"
                    title="No credit notes yet"
                    description="Credit notes issued to this customer will appear here."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Order Ref</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Against Invoice</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Sales Person</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($this->creditNotes as $cn)
                                <tr class="transition-colors hover:bg-amber-50/40 dark:hover:bg-amber-500/5">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('credit-notes.show', $cn) }}" wire:navigate class="font-mono text-sm font-semibold text-amber-600 hover:underline dark:text-amber-400">
                                            {{ $cn->doc_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $cn->doc_date->format('d M Y') }}</td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $cn->order_no ?: '—' }}</td>
                                    <td class="px-4 py-2 font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $cn->creditedInvoice?->doc_number ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($cn->total_value, 2) }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $cn->status->ringColor() }}">
                                            {{ $cn->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $cn->assignee?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                        {{ $this->creditNotes->links() }}
                    </div>
                </div>
            @endif
        @endif

        @if($activeTab === 'payments')
            @if($this->payments->isEmpty())
                <x-ui.empty-state
                    icon="banknotes"
                    title="No payments yet"
                    description="Payments received from this customer will appear here."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Reference</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Method</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Allocated</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Unallocated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($this->payments as $payment)
                                <tr class="transition-colors hover:bg-emerald-50/40 dark:hover:bg-emerald-500/5">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('payments.show', $payment) }}" wire:navigate class="font-mono text-sm font-semibold text-emerald-600 hover:underline dark:text-emerald-400">
                                            {{ $payment->reference }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $payment->paymentMethod?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($payment->amount, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-600 dark:text-zinc-400">£{{ number_format($payment->allocations_sum_allocated_amount ?? 0, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ max(0, $payment->amount - ($payment->allocations_sum_allocated_amount ?? 0)) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">£{{ number_format(max(0, $payment->amount - ($payment->allocations_sum_allocated_amount ?? 0)), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-4 py-3 border-t border-zinc-100 dark:border-zinc-800">
                        {{ $this->payments->links() }}
                    </div>
                </div>
            @endif
        @endif
        --}}

    </x-ui.section-card>

</div>
