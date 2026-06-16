<?php

use App\Models\CreditAllocation;
use App\Models\Document;
use App\Models\Payment;
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
            ->withSum(['creditAllocationsReceived as credit_for_payment_sum_amount' => function ($q) {
                $q->where('payment_id', $this->payment->id);
            }], 'amount')
            ->get();

        $thisPaymentAllocations = $this->payment->allocations->keyBy('document_id');

        return $invoices->map(function (Document $invoice) use ($thisPaymentAllocations) {
            $paymentAmount = (float) ($thisPaymentAllocations->get($invoice->id)?->allocated_amount ?? 0);
            $creditAmount = (float) ($invoice->credit_for_payment_sum_amount ?? 0);

            return [
                'id' => $invoice->id,
                'doc_number' => $invoice->doc_number,
                'doc_date' => $invoice->doc_date->format('d M Y'),
                'total_value' => (float) $invoice->total_value,
                'existing_allocation' => $paymentAmount,
                'credit_amount' => $creditAmount,
            ];
        })->filter(fn ($row) => $row['existing_allocation'] > 0 || $row['credit_amount'] > 0)->values()->toArray();
    }

    #[Computed]
    public function totalAllocated(): float
    {
        return (float) $this->payment->allocations->sum('allocated_amount');
    }

    #[Computed]
    public function unallocatedBalance(): float
    {
        return max(0, (float) $this->payment->amount - $this->totalAllocated);
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
                    @if($payment->paymentMethod)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400">
                            {{ $payment->paymentMethod->name }}
                        </span>
                    @endif
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
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoice #</th>
                                    <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Date</th>
                                    <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoice Total</th>
                                    <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Credits</th>
                                    <th class="pb-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->invoiceRows as $row)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-3 pr-4 font-mono text-sm text-zinc-900 dark:text-white">{{ $row['doc_number'] }}</td>
                                        <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-400">{{ $row['doc_date'] }}</td>
                                        <td class="py-3 pr-4 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format($row['total_value'], 2) }}</td>
                                        <td class="py-3 pr-4 text-right font-mono {{ $row['credit_amount'] > 0 ? 'font-medium text-violet-600 dark:text-violet-400' : 'text-zinc-400 dark:text-zinc-600' }}">
                                            {{ $row['credit_amount'] > 0 ? '£' . number_format($row['credit_amount'], 2) : '—' }}
                                        </td>
                                        <td class="py-3 text-right font-mono font-medium {{ $row['existing_allocation'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400 dark:text-zinc-600' }}">
                                            {{ $row['existing_allocation'] > 0 ? '£' . number_format($row['existing_allocation'], 2) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <td colspan="2" class="pt-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment amount</td>
                                    <td class="pt-3 pr-4 text-right font-mono font-semibold text-zinc-900 dark:text-white">£{{ number_format($payment->amount, 2) }}</td>
                                    <td class="pt-3 pr-4 text-right font-mono font-semibold text-violet-600 dark:text-violet-400">£{{ number_format(collect($this->invoiceRows)->sum('credit_amount'), 2) }}</td>
                                    <td class="pt-3 text-right font-mono font-semibold text-zinc-900 dark:text-white"></td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pb-1 pt-0.5 text-xs text-zinc-400 dark:text-zinc-500">Total allocated</td>
                                    <td></td>
                                    <td></td>
                                    <td class="pb-1 pt-0.5 text-right font-mono font-semibold text-zinc-900 dark:text-white">£{{ number_format($this->totalAllocated, 2) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pb-1 pt-0.5 text-xs text-zinc-400 dark:text-zinc-500">Unallocated balance</td>
                                    <td></td>
                                    <td></td>
                                    <td class="pb-1 pt-0.5 text-right font-mono font-semibold {{ $this->unallocatedBalance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">£{{ number_format($this->unallocatedBalance, 2) }}</td>
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

                    @if($payment->paymentMethod)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment Method</dt>
                            <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payment->paymentMethod->name }}</dd>
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
