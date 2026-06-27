<?php

use App\Models\Overhead;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Overhead Report')] class extends Component {

    #[Url(as: 'preset', except: '')]
    public string $preset = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'min', except: '')]
    public string $amountMin = '';

    #[Url(as: 'max', except: '')]
    public string $amountMax = '';

    public function updatedPreset(): void
    {
        [$this->dateFrom, $this->dateTo] = match ($this->preset) {
            'yesterday'  => [Carbon::yesterday()->toDateString(), Carbon::yesterday()->toDateString()],
            'this_week'  => [Carbon::now()->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()],
            'last_week'  => [Carbon::now()->subWeek()->startOfWeek()->toDateString(), Carbon::now()->subWeek()->endOfWeek()->toDateString()],
            'this_month' => [Carbon::now()->startOfMonth()->toDateString(), Carbon::now()->endOfMonth()->toDateString()],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth()->toDateString(), Carbon::now()->subMonth()->endOfMonth()->toDateString()],
            'this_year'  => [Carbon::now()->startOfYear()->toDateString(), Carbon::now()->endOfYear()->toDateString()],
            default      => ['', ''],
        };
    }

    public function updatedDateFrom(): void { $this->preset = ''; }

    public function updatedDateTo(): void { $this->preset = ''; }

    public function updatedAmountMin(): void { $this->preset = ''; }

    public function updatedAmountMax(): void { $this->preset = ''; }

    public function clearFilters(): void
    {
        $this->preset = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->amountMin = '';
        $this->amountMax = '';
    }

    protected function baseQuery()
    {
        return Overhead::query()
            ->when($this->dateFrom, fn ($q) => $q->where('expense_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('expense_date', '<=', $this->dateTo))
            ->when($this->amountMin !== '' && is_numeric($this->amountMin), fn ($q) => $q->where('amount', '>=', (float) $this->amountMin))
            ->when($this->amountMax !== '' && is_numeric($this->amountMax), fn ($q) => $q->where('amount', '<=', (float) $this->amountMax));
    }

    #[Computed]
    public function stats(): array
    {
        $rate = (float) Setting::get('vat_rate', 20);

        $totalGross = (float) $this->baseQuery()->sum('amount');

        $vatItemsGross = (float) $this->baseQuery()->where('has_vat', true)->sum('amount');
        $vatExtracted  = $vatItemsGross * $rate / (100 + $rate);
        $vatItemsNet   = $vatItemsGross - $vatExtracted;

        $nonVatGross = (float) $this->baseQuery()->where('has_vat', false)->sum('amount');

        return compact('totalGross', 'vatItemsGross', 'vatItemsNet', 'vatExtracted', 'nonVatGross');
    }

    #[Computed]
    public function categoryBreakdown(): \Illuminate\Support\Collection
    {
        $rate = (float) Setting::get('vat_rate', 20);

        return $this->baseQuery()
            ->select([
                'category_id',
                DB::raw('SUM(CASE WHEN has_vat = 0 THEN amount ELSE 0 END) as non_vat_spend'),
                DB::raw("SUM(CASE WHEN has_vat = 1 THEN amount * {$rate} / (100 + {$rate}) ELSE 0 END) as vat_portion"),
                DB::raw("SUM(CASE WHEN has_vat = 1 THEN amount * 100 / (100 + {$rate}) ELSE 0 END) as net_cost"),
                DB::raw('SUM(amount) as gross_total'),
            ])
            ->groupBy('category_id')
            ->orderByDesc('gross_total')
            ->with('category')
            ->get();
    }

    #[Computed]
    public function auditLog(): \Illuminate\Support\Collection
    {
        return $this->baseQuery()
            ->with('category')
            ->orderByDesc('expense_date')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Overhead Expense & VAT Report"
        subtitle="Financial breakdown of operational costs separated by VAT status (Assumes UK standard 20% VAT math where applicable)."
    />

    {{-- Filter toolbar --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Period</label>
                <flux:select wire:model.live="preset" size="sm" class="w-36">
                    <flux:select.option value="">All Time</flux:select.option>
                    <flux:select.option value="yesterday">Yesterday</flux:select.option>
                    <flux:select.option value="this_week">This Week</flux:select.option>
                    <flux:select.option value="last_week">Last Week</flux:select.option>
                    <flux:select.option value="this_month">This Month</flux:select.option>
                    <flux:select.option value="last_month">Last Month</flux:select.option>
                    <flux:select.option value="this_year">This Year</flux:select.option>
                </flux:select>
            </div>
            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">From</label>
                <flux:input wire:model.live.debounce.400ms="dateFrom" type="date" size="sm" class="!w-40" />
            </div>
            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">To</label>
                <flux:input wire:model.live.debounce.400ms="dateTo" type="date" size="sm" class="!w-40" />
            </div>
            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Min £</label>
                <flux:input wire:model.live.debounce.400ms="amountMin" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
            </div>
            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Max £</label>
                <flux:input wire:model.live.debounce.400ms="amountMax" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
            </div>
            @if($preset || $dateFrom || $dateTo || $amountMin || $amountMax)
                <flux:button wire:click="clearFilters" size="sm" variant="ghost" icon="x-mark">Clear</flux:button>
            @endif
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card
            label="Total Gross Spend"
            :value="'£' . number_format($this->stats['totalGross'], 2)"
            icon="banknotes"
            color="indigo"
        >
            <x-slot:sublabel>Combined total of all recorded overheads</x-slot:sublabel>
        </x-ui.stat-card>

        <x-ui.stat-card
            label="Total Items with VAT"
            :value="'£' . number_format($this->stats['vatItemsGross'], 2)"
            icon="receipt-percent"
            color="emerald"
        >
            <x-slot:sublabel>
                Net: <span class="text-blue-600 dark:text-blue-400">£{{ number_format($this->stats['vatItemsNet'], 2) }}</span>
                &nbsp;|&nbsp;
                VAT Input: <span class="text-emerald-600 dark:text-emerald-400">£{{ number_format($this->stats['vatExtracted'], 2) }}</span>
            </x-slot:sublabel>
        </x-ui.stat-card>

        <x-ui.stat-card
            label="Items without VAT"
            :value="'£' . number_format($this->stats['nonVatGross'], 2)"
            icon="no-symbol"
            color="amber"
        >
            <x-slot:sublabel>Fines, exempt adjustments, or unregistered vendors</x-slot:sublabel>
        </x-ui.stat-card>
    </div>

    {{-- Table 1: Cost Split Analysis by Category --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Cost Split Analysis by Category</h2>
        </div>
        @if($this->categoryBreakdown->isEmpty())
            <div class="flex flex-col items-center justify-center gap-2 py-10">
                <flux:icon.chart-bar class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No overheads match the current filters.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Net Cost (Ex VAT)</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Reclaimable VAT (20%)</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Non-VAT Spend</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Gross Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach($this->categoryBreakdown as $row)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                {{ $row->category?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-white">
                                £{{ number_format($row->net_cost, 2) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                £{{ number_format($row->vat_portion, 2) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-amber-600 dark:text-amber-400">
                                £{{ number_format($row->non_vat_spend, 2) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono font-semibold text-zinc-900 dark:text-white">
                                £{{ number_format($row->gross_total, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Table 2: Transaction Audit Log --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Transaction Audit Log</h2>
        </div>
        @if($this->auditLog->isEmpty())
            <div class="flex flex-col items-center justify-center gap-2 py-10">
                <flux:icon.document-text class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No transactions match the current filters.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT Status</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Net Amount</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT Element</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Gross Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                    @foreach($this->auditLog as $overhead)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                {{ $overhead->expense_date->format('d M Y') }}
                            </td>
                            <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                {{ $overhead->category?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                @if($overhead->has_vat)
                                    <flux:badge color="green" size="sm">Inc. VAT</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Ex. VAT</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-white">
                                £{{ number_format($overhead->netAmount, 2) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                £{{ number_format($overhead->vatAmount, 2) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono font-semibold text-zinc-900 dark:text-white">
                                £{{ number_format($overhead->amount, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
