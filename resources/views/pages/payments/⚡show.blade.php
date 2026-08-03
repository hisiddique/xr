<?php

use App\Models\CreditAllocation;
use App\Models\Document;
use App\Models\Payment;
use App\PaymentSourceType;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux\Flux;

new #[Title('Payment')] class extends Component
{
    public Payment $payment;

    public function mount(): void
    {
        $this->payment->load(['customer', 'paymentMethod', 'creator', 'allocations.document']);
    }

    #[Computed]
    public function invoiceRows(): array
    {
        $invoices = Document::where('customer_id', $this->payment->customer_id)
            ->where('type', 'INV')
            ->orderBy('doc_date', 'asc')
            ->withSum('paymentAllocations', 'allocated_amount')
            ->withSum('creditAllocationsReceived', 'amount')
            ->get();

        $thisPaymentAllocations = $this->payment->allocations->keyBy('document_id');

        $thisPaymentCredits = CreditAllocation::where('payment_id', $this->payment->id)
            ->with('creditNote')
            ->get()
            ->groupBy('invoice_id');

        return $invoices->map(function (Document $invoice) use ($thisPaymentAllocations, $thisPaymentCredits) {
            $paymentAmount = (float) ($thisPaymentAllocations->get($invoice->id)?->allocated_amount ?? 0);
            $totalPaidAllTime = (float) ($invoice->payment_allocations_sum_allocated_amount ?? 0);
            $totalCreditedAllTime = (float) ($invoice->credit_allocations_received_sum_amount ?? 0);

            $creditNotes = ($thisPaymentCredits->get($invoice->id) ?? collect())
                ->map(fn (CreditAllocation $allocation) => [
                    'reference' => $allocation->creditNote?->doc_number ?? '—',
                    'amount' => (float) $allocation->amount,
                ])->values()->toArray();

            $creditAmount = array_sum(array_column($creditNotes, 'amount'));
            $outstanding = max(0, (float) $invoice->total_value - $totalPaidAllTime - $totalCreditedAllTime);

            return [
                'id' => $invoice->id,
                'doc_number' => $invoice->doc_number,
                'doc_date' => $invoice->doc_date->format('d M Y'),
                'total_value' => (float) $invoice->total_value,
                'existing_allocation' => $paymentAmount,
                'credit_notes' => $creditNotes,
                'credit_amount' => $creditAmount,
                'outstanding' => $outstanding,
                'is_settled' => $outstanding <= 0.0,
            ];
        })->filter(fn ($row) => $row['existing_allocation'] > 0 || $row['credit_amount'] > 0)->values()->toArray();
    }

    #[Computed]
    public function totalAllocated(): float
    {
        return (float) $this->payment->allocations->sum('allocated_amount');
    }

    #[Computed]
    public function totalCredits(): float
    {
        return collect($this->invoiceRows)->sum('credit_amount');
    }

    #[Computed]
    public function totalAllocatedAll(): float
    {
        return $this->totalAllocated + $this->totalCredits;
    }

    #[Computed]
    public function totalOutstanding(): float
    {
        return collect($this->invoiceRows)->sum('outstanding');
    }

    #[Computed]
    public function remainingToAllocate(): float
    {
        return max(0, (float) $this->payment->amount - $this->totalAllocated);
    }

    /**
     * Reference numbers of whatever funded this payment — the credit notes
     * it consumed, or the prior payments it drew over-payment balance from.
     *
     * @return string[]
     */
    #[Computed]
    public function fundingReferences(): array
    {
        return match ($this->payment->source_type) {
            PaymentSourceType::CreditNote => $this->payment->creditAllocations()
                ->with('creditNote')
                ->get()
                ->pluck('creditNote.doc_number')
                ->filter()
                ->values()
                ->toArray(),
            PaymentSourceType::OverPayment => $this->payment->drawsReceived()
                ->with('sourcePayment')
                ->get()
                ->pluck('sourcePayment.reference')
                ->filter()
                ->values()
                ->toArray(),
            default => [],
        };
    }

    public function deletePayment(): void
    {
        CreditAllocation::where('payment_id', $this->payment->id)->delete();
        $this->payment->allocations()->delete();
        $this->payment->delete();
        $this->redirect(route('payments.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">

    {{-- Back link --}}
    <div>
        <x-ui.back-button :fallback="route('payments.index')" icon="arrow-left" size="sm">Back</x-ui.back-button>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-emerald-500 via-teal-500 to-cyan-500"></div>

        {{-- Floating icon badge --}}
        <div class="absolute left-6 top-12 flex h-14 w-14 items-center justify-center rounded-2xl bg-white shadow-[0_1px_2px_rgba(16,24,40,0.12),0_2px_8px_rgba(16,24,40,0.12)] ring-4 ring-white dark:bg-zinc-800 dark:ring-zinc-900">
            <flux:icon.banknotes class="size-6 text-emerald-600 dark:text-emerald-400" />
        </div>

        <div class="px-4 pb-4 pt-10">
            {{-- Title row + actions --}}
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex flex-wrap items-center gap-3">
                    <h1 class="font-mono text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $payment->reference }}</h1>
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400">
                        {{ $payment->paymentMethod?->name ?? $payment->source_type->label() }}
                    </span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $payment->payment_date->format('d F Y') }}</span>
                    @if($payment->creator)
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">· {{ __('Created by :name', ['name' => $payment->creator->name]) }}</span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button variant="ghost" icon="pencil" size="sm" :href="route('payments.edit', $payment)" wire:navigate>
                        Edit
                    </flux:button>
                    <flux:button variant="ghost" icon="trash" size="sm" x-on:click="$flux.modal('delete-payment').show()" class="text-red-600 hover:text-red-700 dark:text-red-400">
                        Delete
                    </flux:button>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column body --}}
    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Left: Allocation summary (2/3) --}}
        <div class="lg:col-span-2">
            <x-ui.section-card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Allocations</h2>
                </x-slot:header>

                @if(count($this->invoiceRows) > 0)
                    <div class="mb-5 flex flex-wrap gap-3">
                        @if($payment->amount > 0)
                            <div class="min-w-[150px] flex-1 rounded-2xl border border-emerald-200/70 bg-emerald-50 p-3 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                                <p class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Payment Amount</p>
                                <p class="mt-1 font-mono text-lg font-semibold text-emerald-700 dark:text-emerald-300">£{{ number_format($payment->amount, 2) }}</p>
                            </div>
                        @endif
                        @if($this->totalCredits > 0)
                            <div class="min-w-[150px] flex-1 rounded-2xl border border-violet-200/70 bg-violet-50 p-3 dark:border-violet-500/20 dark:bg-violet-500/10">
                                <p class="text-xs font-medium text-violet-600 dark:text-violet-400">Credit Notes Applied</p>
                                <p class="mt-1 font-mono text-lg font-semibold text-violet-700 dark:text-violet-300">£{{ number_format($this->totalCredits, 2) }}</p>
                            </div>
                        @endif
                        @if($this->totalOutstanding > 0)
                            <div class="min-w-[150px] flex-1 rounded-2xl border border-amber-200/70 bg-amber-50 p-3 dark:border-amber-500/20 dark:bg-amber-500/10">
                                <p class="text-xs font-medium text-amber-600 dark:text-amber-400">Total Outstanding</p>
                                <p class="mt-1 font-mono text-lg font-semibold text-amber-700 dark:text-amber-300">£{{ number_format($this->totalOutstanding, 2) }}</p>
                            </div>
                        @endif
                        @if($this->remainingToAllocate > 0)
                            <div class="min-w-[150px] flex-1 rounded-2xl border border-zinc-200/70 bg-zinc-50 p-3 dark:border-white/10 dark:bg-zinc-800/50">
                                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Remaining to Allocate</p>
                                <p class="mt-1 font-mono text-lg font-semibold text-zinc-900 dark:text-white">£{{ number_format($this->remainingToAllocate, 2) }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoice #</th>
                                    <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Date</th>
                                    <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Total</th>
                                    <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment</th>
                                    <th class="pb-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->invoiceRows as $row)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-3 pr-4 font-mono text-sm text-zinc-900 dark:text-white">{{ $row['doc_number'] }}</td>
                                        <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-400">{{ $row['doc_date'] }}</td>
                                        <td class="py-3 pr-4 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format($row['total_value'], 2) }}</td>
                                        <td class="py-3 pr-4 text-right font-mono font-medium {{ $row['existing_allocation'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400 dark:text-zinc-600' }}">
                                            {{ $row['existing_allocation'] > 0 ? '£' . number_format($row['existing_allocation'], 2) : '—' }}
                                        </td>
                                        <td class="py-3 text-right font-mono font-medium {{ $row['is_settled'] ? 'text-zinc-400 dark:text-zinc-600' : 'text-amber-600 dark:text-amber-400' }}">
                                            {{ $row['is_settled'] ? '—' : '£' . number_format($row['outstanding'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <td colspan="3" class="pt-3 text-xs text-zinc-400 dark:text-zinc-500">Total allocated</td>
                                    <td class="pt-3 text-right font-mono font-semibold" colspan="2">
                                        @if($this->totalCredits > 0 && $this->totalAllocated > 0)
                                            <span class="text-zinc-900 dark:text-white">£{{ number_format($this->totalAllocatedAll, 2) }}</span>
                                            <span class="text-xs font-normal text-zinc-400 dark:text-zinc-500">(<span class="text-violet-600 dark:text-violet-400">£{{ number_format($this->totalCredits, 2) }}</span> + <span class="text-emerald-600 dark:text-emerald-400">£{{ number_format($this->totalAllocated, 2) }}</span>)</span>
                                        @elseif($this->totalCredits > 0)
                                            <span class="text-violet-600 dark:text-violet-400">£{{ number_format($this->totalAllocatedAll, 2) }}</span>
                                        @else
                                            <span class="text-emerald-600 dark:text-emerald-400">£{{ number_format($this->totalAllocatedAll, 2) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="py-10 text-center">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No allocations yet.</p>
                        <a href="{{ route('payments.edit', $payment) }}" wire:navigate class="mt-2 inline-block text-sm font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">Allocate now →</a>
                    </div>
                @endif
            </x-ui.section-card>
        </div>

        {{-- Right: Payment details (1/3) --}}
        <div>
            <x-ui.section-card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Payment Details</h2>
                </x-slot:header>

                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Customer</dt>
                        <dd class="mt-1">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $payment->customer->company_name ?: trim($payment->customer->first_name . ' ' . $payment->customer->last_name) }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $payment->customer->reference }}</div>
                            @if($payment->customer->address_1)
                                <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ collect([$payment->customer->address_1, $payment->customer->town, $payment->customer->post_code])->filter()->implode(', ') }}</div>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment Method</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payment->paymentMethod?->name ?? $payment->source_type->label() }}</dd>
                    </div>

                    @if(count($this->fundingReferences) > 0)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                {{ $payment->source_type === PaymentSourceType::CreditNote ? 'Credit Notes Used' : 'Over Payments Used' }}
                            </dt>
                            <dd class="mt-1 font-mono text-sm text-zinc-900 dark:text-white">{{ implode(', ', $this->fundingReferences) }}</dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Amount</dt>
                        <dd class="mt-1 font-mono text-xl font-semibold text-zinc-900 dark:text-white">£{{ number_format($payment->amount, 2) }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment Date</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payment->payment_date->format('d F Y') }}</dd>
                    </div>

                    @if($payment->notes)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Notes</dt>
                            <dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $payment->notes }}</dd>
                        </div>
                    @endif

                    @if($payment->creator)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Created By</dt>
                            <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payment->creator->name }}</dd>
                        </div>
                    @endif

                    @if($payment->receipt_path)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Receipt</dt>
                            <dd class="mt-1">
                                <a
                                    href="{{ Storage::disk('public')->url($payment->receipt_path) }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                >
                                    <flux:icon.paper-clip class="size-4" />
                                    {{ basename($payment->receipt_path) }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-ui.section-card>
        </div>
    </div>

    {{-- Delete payment confirmation modal --}}
    <flux:modal name="delete-payment" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Delete Payment</flux:heading>
                <flux:subheading>This will permanently delete payment <strong class="font-mono">{{ $payment->reference }}</strong> and all its allocations. This action cannot be undone.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" x-on:click="$flux.modal('delete-payment').close()">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deletePayment">Delete Payment</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
