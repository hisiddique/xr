<?php

use App\Models\Supplier;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supplier Details')] class extends Component {
    public Supplier $supplier;

    #[On('supplier-deleted')]
    public function onDeleted(): void
    {
        $this->redirect(route('suppliers.index'), navigate: true);
    }
}; ?>

<div
    class="flex flex-col gap-6"
    x-data="showPageKeys({
        edit: () => Livewire.navigate('{{ route('suppliers.edit', $supplier) }}'),
        delete: () => $store.hotkeys.openModalWithConfirm('delete-supplier-{{ $supplier->id }}'),
    })"
>

    {{-- Back link + actions --}}
    <div class="flex items-center justify-between gap-2">
        <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('suppliers.index')" wire:navigate>Back</flux:button>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="pencil" size="sm" :href="route('suppliers.edit', $supplier)" wire:navigate>
                Edit
                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">e</kbd>
            </flux:button>
            <livewire:pages::suppliers.delete-modal :supplier="$supplier" :key="'delete-'.$supplier->id" />
        </div>
    </div>

    {{-- Hero header card --}}
    <div class="relative rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="h-20 rounded-t-2xl bg-gradient-to-r from-violet-500 via-purple-500 to-indigo-500"></div>

        {{-- Floating avatar --}}
        <div class="absolute left-6 top-10 ring-4 ring-white dark:ring-zinc-900 rounded-full">
            <x-ui.avatar :name="$supplier->company_name" size="xl" />
        </div>

        <div class="px-4 pb-4 pt-14">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $supplier->company_name }}</h1>
                    @if($supplier->reference)
                        <p class="mt-0.5 font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $supplier->reference }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Two-column details --}}
    <div class="grid gap-4 md:grid-cols-2">

        {{-- Basic Information --}}
        <div class="hidden">
            <x-ui.section-card title="Basic Information">
                <dl class="space-y-4">
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Contact</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white text-right">
                            {{ trim(($supplier->title?->name ? $supplier->title->name.' ' : '').$supplier->first_name.' '.$supplier->last_name) ?: '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Reference</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white text-right">{{ $supplier->reference ?? '—' }}</dd>
                    </div>
                </dl>
            </x-ui.section-card>
        </div>

        {{-- Credit & Trading --}}
        <x-ui.section-card title="Credit & Trading">
            <dl class="space-y-4">
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Trade Discount</dt>
                    <dd>
                        @if($supplier->trade_discount > 0)
                            <span class="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400">
                                {{ $supplier->trade_discount }}%
                            </span>
                        @else
                            <span class="text-sm text-zinc-400">None</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">VAT Applied</dt>
                    <dd>
                        @if($supplier->vat_applied)
                            <flux:icon.check-circle class="h-5 w-5 text-emerald-500 dark:text-emerald-400" />
                        @else
                            <flux:icon.x-circle class="h-5 w-5 text-zinc-400 dark:text-zinc-500" />
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.section-card>
    </div>

</div>
