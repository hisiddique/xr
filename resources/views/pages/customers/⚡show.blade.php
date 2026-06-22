<?php

use App\Models\Customer;
use App\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customer Details')] class extends Component {
    use WithPagination;

    public Customer $customer;

    #[Url(as: 'tab')]
    public string $activeTab = 'invoices';

    #[Computed]
    public function availableCredit(): float
    {
        return Document::availableCreditForCustomer($this->customer->id);
    }

    #[Computed]
    public function invoices()
    {
        return $this->customer->invoices()->with('assignee')->latest()->paginate(100, pageName: 'inv_page');
    }

    #[Computed]
    public function deliveryNotes()
    {
        return $this->customer->deliveryNotes()
            ->with('assignee')
            ->latest()
            ->paginate(25, pageName: 'dn_page');
    }

    #[Computed]
    public function creditNotes()
    {
        return $this->customer->creditNotes()->with(['assignee', 'creditedInvoice'])->latest()->paginate(100, pageName: 'cn_page');
    }

    #[Computed]
    public function payments()
    {
        return $this->customer->payments()->with('paymentMethod')->withSum('allocations', 'allocated_amount')->latest()->paginate(100, pageName: 'pay_page');
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage('inv_page');
        $this->resetPage('dn_page');
        $this->resetPage('cn_page');
        $this->resetPage('pay_page');
        unset($this->invoices, $this->deliveryNotes, $this->creditNotes, $this->payments);
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
        <x-ui.back-button :fallback="route('customers.index')" icon="arrow-left" size="sm">Back</x-ui.back-button>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('customers.edit', $customer)" wire:navigate>
                Edit
                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">e</kbd>
            </flux:button>
            <livewire:pages::customers.delete-modal :customer="$customer" :key="'delete-'.$customer->id" />
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
                <flux:button variant="primary" icon="plus" size="sm" :href="route('delivery-notes.create').'?customer_id='.$customer->id" wire:navigate>
                    New Delivery Note
                </flux:button>
            </div>
        </div>
    </div>

    {{-- Two-column details --}}
    <div class="grid gap-4 md:grid-cols-2">

        {{-- Contact Details --}}
        <x-ui.section-card title="Contact Details">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Name</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white text-right">
                        {{ trim(($customer->title?->name ? $customer->title->name.' ' : '').$customer->first_name.' '.$customer->last_name) ?: '—' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Email</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $customer->email_1 ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Address</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white text-right">
                        {{ collect([$customer->address_1, $customer->address_2, $customer->town, $customer->post_code])->filter()->implode(', ') ?: '—' }}
                    </dd>
                </div>
            </dl>
        </x-ui.section-card>

        {{-- Credit & Trading --}}
        <x-ui.section-card title="Credit & Trading">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Trade Discount</dt>
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
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Credit Terms</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white">{{ $customer->creditTerm?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Credit Limit</dt>
                    <dd class="text-sm text-zinc-900 dark:text-white">
                        {{ $customer->creditLimit ? '£'.number_format($customer->creditLimit->amount, 2) : '—' }}
                    </dd>
                </div>
            </dl>
        </x-ui.section-card>
    </div>

    {{-- Available Credit summary card --}}
    <div class="rounded-2xl border border-emerald-200/70 bg-gradient-to-r from-emerald-50 to-teal-50 p-5 shadow-sm dark:border-emerald-500/20 dark:from-emerald-500/10 dark:to-teal-500/10">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-500/20">
                    <flux:icon.banknotes class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-emerald-600/70 dark:text-emerald-400/70">Available Credit</p>
                    <p class="text-2xl font-bold tracking-tight {{ $this->availableCredit > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-zinc-400 dark:text-zinc-500' }}">
                        £{{ number_format($this->availableCredit, 2) }}
                    </p>
                </div>
            </div>
            @if($this->availableCredit > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <flux:icon.check-circle class="h-3.5 w-3.5" />
                    Credit available
                </span>
            @endif
        </div>
    </div>

    {{-- Four-tab document panel --}}
    <x-ui.section-card :padding="false">

        {{-- Tab bar --}}
        <div class="flex border-b border-zinc-200 dark:border-white/10">
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
        </div>

        {{-- Invoices panel --}}
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

        {{-- Delivery Notes panel --}}
        @if($activeTab === 'delivery-notes')
            @if($this->deliveryNotes->isEmpty())
                <x-ui.empty-state
                    icon="truck"
                    title="No delivery notes yet"
                    description="Create the first delivery note for this customer."
                >
                    <x-slot:action>
                        <flux:button variant="primary" size="sm" :href="route('delivery-notes.create').'?customer_id='.$customer->id" wire:navigate>
                            New Delivery Note
                        </flux:button>
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

        {{-- Credit Notes panel --}}
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

        {{-- Payments panel --}}
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

    </x-ui.section-card>

</div>
