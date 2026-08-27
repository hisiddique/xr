<?php

use App\Models\SupplierPayoutAllocation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public array $sessionPayoutIds = [];

    #[On('payout-confirmed')]
    public function onPayoutConfirmed(int $payoutId): void
    {
        $this->sessionPayoutIds[] = $payoutId;
        unset($this->rows);
    }

    #[Computed]
    public function rows(): \Illuminate\Support\Collection
    {
        if (empty($this->sessionPayoutIds)) {
            return collect();
        }

        return SupplierPayoutAllocation::whereIn('supplier_payout_id', $this->sessionPayoutIds)
            ->with([
                'supplierPayout.supplier',
                'supplierInvoice',
                'supplierDebitNote',
            ])
            ->orderBy('id')
            ->get();
    }
};
?>

<div class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
    <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Payout Report Summary Engine</h2>
    </div>

    @if($this->rows->isEmpty())
        <div class="flex flex-col items-center justify-center gap-2 py-12">
            <flux:icon.banknotes class="size-8 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">No payouts confirmed yet this session.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Payout Ref</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Linked Debit Note</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Target Invoice</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Allocation Share</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach($this->rows as $idx => $allocation)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $idx + 1 }}</td>
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-zinc-900 dark:text-white">
                                {{ $allocation->supplierPayout?->reference ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($allocation->supplierDebitNote)
                                    <flux:badge color="red" size="sm">{{ $allocation->supplierDebitNote->reference }}</flux:badge>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-600">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-700 dark:text-zinc-300">
                                {{ $allocation->supplierInvoice?->supplier_invoice_no ?? '—' }}@if($allocation->supplierInvoice?->supplier_ref_invoice_no) <span class="text-zinc-400 dark:text-zinc-500">({{ $allocation->supplierInvoice->supplier_ref_invoice_no }})</span>@endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                                £{{ number_format($allocation->allocated_amount, 2) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $allocation->created_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
