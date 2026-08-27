<?php

use App\Models\SupplierPayout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux\Flux;

new #[Title('Supplier Payout')] class extends Component {
    public SupplierPayout $payout;

    public function mount(): void
    {
        $this->payout->load([
            'supplier',
            'creator',
            'allocations.supplierInvoice',
            'allocations.supplierDebitNote',
        ]);
    }

    public function delete(): void
    {
        $ref = $this->payout->reference;
        $this->payout->delete();
        Flux::toast(variant: 'success', text: 'Payout '.$ref.' deleted.');
        $this->redirect(route('supplier-payouts.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('supplier-payouts.index')" wire:navigate>Back</flux:button>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('supplier-payouts.edit', $payout)" wire:navigate>
                Edit
            </flux:button>
            <flux:button
                size="sm"
                variant="ghost"
                icon="trash"
                x-on:click="$flux.modal('delete-payout').show()"
                class="text-red-500 hover:text-red-700"
            >
                Delete
            </flux:button>
        </div>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-emerald-500 via-green-500 to-teal-500"></div>

        {{-- Floating icon badge --}}
        <div class="absolute left-6 top-10 flex h-16 w-16 items-center justify-center rounded-full bg-white ring-4 ring-white dark:bg-zinc-900 dark:ring-zinc-900">
            <flux:icon.arrow-up-circle class="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
        </div>

        <div class="px-4 pb-4 pt-14">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="font-mono text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $payout->reference }}</h1>
                    </div>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $payout->supplier->company_name }} &middot; {{ $payout->payout_date->format('d M Y') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column body --}}
    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Left: Allocations table (2/3) --}}
        <div class="lg:col-span-2">
            <x-ui.section-card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Allocations</h2>
                </x-slot:header>

                @if($payout->allocations->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoice Ref</th>
                                    <th class="pb-3 pr-4 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">Debit Note</th>
                                    <th class="pb-3 pr-4 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Deduction</th>
                                    <th class="pb-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">Allocated Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($payout->allocations as $allocation)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-3 pr-4 font-mono text-zinc-900 dark:text-white">{{ $allocation->supplierInvoice?->supplier_invoice_no ?? '—' }}@if($allocation->supplierInvoice?->supplier_ref_invoice_no) <span class="text-zinc-400 dark:text-zinc-500">({{ $allocation->supplierInvoice->supplier_ref_invoice_no }})</span>@endif</td>
                                        <td class="py-3 pr-4">
                                            @if($allocation->supplierDebitNote)
                                                <flux:badge color="red" size="sm">{{ $allocation->supplierDebitNote->reference }}</flux:badge>
                                            @else
                                                <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4 text-right text-red-600 dark:text-red-400">
                                            {{ $allocation->deduction_amount > 0 ? '−£'.number_format($allocation->deduction_amount, 2) : '—' }}
                                        </td>
                                        <td class="py-3 text-right font-mono font-semibold text-zinc-900 dark:text-white">£{{ number_format($allocation->allocated_amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <td colspan="2" class="pt-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">Total Allocated</td>
                                    <td class="pt-3 pr-4 text-right font-mono font-semibold text-red-600 dark:text-red-400">
                                        −£{{ number_format($payout->allocations->sum('deduction_amount'), 2) }}
                                    </td>
                                    <td class="pt-3 text-right font-mono font-semibold text-zinc-900 dark:text-white">
                                        £{{ number_format($payout->allocations->sum('allocated_amount'), 2) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pb-1 pt-1 text-xs text-zinc-400 dark:text-zinc-500">Net Cash Out</td>
                                    <td></td>
                                    <td class="pb-1 pt-1 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                        £{{ number_format($payout->allocations->sum('allocated_amount') - $payout->allocations->sum('deduction_amount'), 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="py-10 text-center">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No allocations recorded.</p>
                        <a href="{{ route('supplier-payouts.edit', $payout) }}" wire:navigate class="mt-2 inline-block text-sm font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">Add allocations →</a>
                    </div>
                @endif
            </x-ui.section-card>
        </div>

        {{-- Right: Payout details (1/3) --}}
        <div>
            <x-ui.section-card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Payout Details</h2>
                </x-slot:header>

                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Supplier</dt>
                        <dd class="mt-1">
                            <a href="{{ route('suppliers.show', $payout->supplier) }}" wire:navigate class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                {{ $payout->supplier->company_name }}
                            </a>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Payout Date</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payout->payout_date->format('d F Y') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Total Amount</dt>
                        <dd class="mt-1 font-mono text-xl font-bold text-zinc-900 dark:text-white">£{{ number_format($payout->amount, 2) }}</dd>
                    </div>

                    @if($payout->notes)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Notes</dt>
                            <dd class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $payout->notes }}</dd>
                        </div>
                    @endif

                    @if($payout->creator)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Created By</dt>
                            <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payout->creator->name }}</dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Created At</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payout->created_at->format('d F Y') }}</dd>
                    </div>
                </dl>
            </x-ui.section-card>
        </div>

    </div>

    {{-- Delete payout confirmation modal --}}
    <flux:modal name="delete-payout" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Delete Payout?</flux:heading>
                <flux:subheading>Deleting will remove all allocation records for <strong class="font-mono">{{ $payout->reference }}</strong>. This action cannot be undone.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" x-on:click="$flux.modal('delete-payout').close()">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete">Delete Payout</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
