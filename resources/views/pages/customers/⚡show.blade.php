<?php

use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer Details')] class extends Component {
    public Customer $customer;

    #[Computed]
    public function deliveryNotes()
    {
        return $this->customer->deliveryNotes()
            ->latest()
            ->limit(10)
            ->get();
    }

    #[On('customer-deleted')]
    public function onDeleted(): void
    {
        $this->redirect(route('customers.index'), navigate: true);
    }
}; ?>

<div
    class="flex flex-col gap-8"
    x-data="showPageKeys({
        edit: () => Livewire.navigate('{{ route('customers.edit', $customer) }}'),
        delete: () => $store.hotkeys.openModalWithConfirm('delete-customer-{{ $customer->id }}'),
    })"
>

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" :href="route('customers.index')" wire:navigate size="sm">
            Back to Customers
        </flux:button>
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

        <div class="px-6 pb-6 pt-14">
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
    <div class="grid gap-6 md:grid-cols-2">

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

    {{-- Delivery notes table --}}
    <x-ui.section-card :padding="false">
        <x-slot:header>
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Delivery Notes</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">All deliveries for this customer</p>
            </div>
        </x-slot:header>

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
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->deliveryNotes as $note)
                            <tr class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5">
                                <td class="px-6 py-3.5">
                                    <a href="{{ route('delivery-notes.show', $note) }}" wire:navigate class="font-mono text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                        {{ $note->doc_number }}
                                    </a>
                                </td>
                                <td class="px-6 py-3.5 text-zinc-500 dark:text-zinc-400">{{ $note->doc_date->format('d M Y') }}</td>
                                <td class="px-6 py-3.5">
                                    @php
                                        $statusColor = match($note->status->value) {
                                            'active'    => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
                                            'converted' => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-400',
                                            'emailed'   => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400',
                                            default     => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-500/10 dark:text-zinc-400',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusColor }}">
                                        {{ $note->status->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.section-card>

</div>
