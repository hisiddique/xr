<?php

use App\Models\Document;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice')] class extends Component {
    public Document $document;

    public function mount(): void
    {
        $this->document->load(['customer', 'items', 'emailLogs', 'convertedFrom']);
    }

    #[On('email-log-updated')]
    public function refreshEmailLogs(): void
    {
        $this->document->load('emailLogs');
    }
}; ?>

<div class="flex flex-col gap-8" x-data="showPageKeys({ f9: () => Flux.modal('email-document-{{ $document->id }}').show() })">

    {{-- Back link --}}
    <div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('invoices.index')" wire:navigate size="sm">
            Back to Invoices
        </flux:button>
    </div>

    @php
        $statusColor = match($document->status->value) {
            'active'    => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
            'emailed'   => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400',
            default     => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-500/10 dark:text-zinc-400',
        };
    @endphp

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-fuchsia-500"></div>

        {{-- Floating icon badge --}}
        <div class="absolute left-6 top-12 flex h-14 w-14 items-center justify-center rounded-2xl bg-white shadow-[0_1px_2px_rgba(16,24,40,0.12),0_2px_8px_rgba(16,24,40,0.12)] ring-4 ring-white dark:bg-zinc-800 dark:ring-zinc-900">
            <flux:icon.document-text class="size-6 text-indigo-600 dark:text-indigo-400" />
        </div>

        <div class="px-6 pb-6 pt-10">
            {{-- Title row + actions --}}
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex flex-wrap items-center gap-3">
                    <h1 class="font-mono text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $document->doc_number }}</h1>
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $statusColor }}">
                        {{ $document->status->label() }}
                    </span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $document->doc_date->format('d F Y') }}</span>
                    @if($document->convertedFrom)
                        <a
                            href="{{ route('delivery-notes.show', $document->convertedFrom) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700 ring-1 ring-inset ring-violet-600/20 hover:bg-violet-100 transition-colors dark:bg-violet-500/10 dark:text-violet-400"
                        >
                            <flux:icon.arrow-path class="size-3" />
                            From {{ $document->convertedFrom->doc_number }}
                        </a>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button variant="ghost" icon="pencil" size="sm" :href="route('invoices.edit', $document)" wire:navigate>
                        Edit
                    </flux:button>
                    <flux:button variant="ghost" icon="arrow-down-tray" size="sm" :href="route('documents.pdf.download', $document)">
                        Download PDF
                    </flux:button>
                    <flux:button variant="ghost" icon="document" size="sm" :href="route('documents.pdf', $document)" target="_blank">
                        View PDF
                    </flux:button>
                    <flux:button
                        variant="primary"
                        icon="envelope"
                        size="sm"
                        x-on:click="$flux.modal('email-document-{{ $document->id }}').show()"
                    >
                        Send Email
                        <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-indigo-300 bg-indigo-50 px-1 py-0.5 text-[10px] font-mono text-indigo-600 dark:border-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">F9</kbd>
                    </flux:button>
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column body --}}
    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Left: Line items + totals (2/3 width) --}}
        <div class="lg:col-span-2 flex flex-col gap-6">

            {{-- Line Items --}}
            <x-ui.section-card :padding="false">
                <x-slot:header>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Line Items</h2>
                </x-slot:header>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Description</th>
                                <th class="w-24 px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                                <th class="w-28 px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Price</th>
                                <th class="w-20 px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                                <th class="w-28 px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Line Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($document->items as $i => $item)
                                <tr>
                                    <td class="px-6 py-3.5 text-zinc-400 dark:text-zinc-500">{{ $i + 1 }}</td>
                                    <td class="px-6 py-3.5 text-zinc-900 dark:text-white">{{ $item->details }}</td>
                                    <td class="px-6 py-3.5 text-right font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ $item->quantity }}</td>
                                    <td class="px-6 py-3.5 text-right font-mono tabular-nums text-zinc-700 dark:text-zinc-300">£{{ number_format($item->price, 2) }}</td>
                                    <td class="px-6 py-3.5 text-zinc-500 dark:text-zinc-400">{{ $item->per ?? '' }}</td>
                                    <td class="px-6 py-3.5 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">£{{ number_format($item->line_value, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Totals --}}
                <div class="flex justify-end border-t border-zinc-100 p-6 dark:border-white/[0.06]">
                    <div class="w-72 space-y-2.5">
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400">Subtotal</span>
                            <span class="font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($document->subtotal, 2) }}</span>
                        </div>
                        @if($document->discount_amount > 0)
                            <div class="flex justify-between text-sm">
                                <span class="text-zinc-500 dark:text-zinc-400">Discount ({{ $document->trade_discount }}%)</span>
                                <span class="font-mono tabular-nums text-rose-500">- £{{ number_format($document->discount_amount, 2) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400">VAT</span>
                            <span class="font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($document->vat_amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-zinc-200 pt-3 dark:border-white/10">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">Total</span>
                            <span class="text-xl font-semibold tracking-tight tabular-nums text-zinc-900 dark:text-white">£{{ number_format($document->total_value, 2) }}</span>
                        </div>
                    </div>
                </div>
            </x-ui.section-card>

        </div>

        {{-- Right: Customer + Email log + source DN (1/3 width) --}}
        <div class="flex flex-col gap-6">

            {{-- Customer --}}
            <x-ui.section-card title="Customer">
                <div class="flex items-center gap-3">
                    <x-ui.avatar :name="$document->customer->company_name" size="md" />
                    <div>
                        <a href="{{ route('customers.show', $document->customer) }}" wire:navigate class="font-semibold text-zinc-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 transition-colors">
                            {{ $document->customer->company_name }}
                        </a>
                        @if($document->customer->email_1)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $document->customer->email_1 }}</p>
                        @endif
                        @if($document->customer->address_1)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ collect([$document->customer->address_1, $document->customer->town, $document->customer->post_code])->filter()->implode(', ') }}
                            </p>
                        @endif
                    </div>
                </div>
            </x-ui.section-card>

            {{-- Email History --}}
            <x-ui.section-card title="Email History">
                @if($document->emailLogs->isEmpty())
                    <x-ui.empty-state
                        icon="envelope"
                        title="No emails sent yet"
                        description="Send this invoice to the customer."
                    />
                @else
                    <ul class="space-y-3">
                        @foreach($document->emailLogs()->latest()->get() as $log)
                            <li class="flex items-start gap-3">
                                <div @class([
                                    'mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-white dark:ring-zinc-900',
                                    'bg-emerald-500' => $log->status === 'sent',
                                    'bg-rose-500' => $log->status !== 'sent',
                                ])></div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-zinc-900 dark:text-white">{{ $log->recipient_email }}</p>
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $log->sent_at?->diffForHumans() ?? 'Unknown' }}</p>
                                </div>
                                <span @class([
                                    'shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                    'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400' => $log->status === 'sent',
                                    'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400' => $log->status !== 'sent',
                                ])>{{ ucfirst($log->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.section-card>

        </div>
    </div>

    <livewire:pages::documents.email-modal :document="$document" :key="'email-'.$document->id" />

</div>
