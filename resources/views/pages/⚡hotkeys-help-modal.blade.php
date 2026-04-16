<?php

use Livewire\Component;

new class extends Component {
    public bool $isOpen = false;

    public function openModal(): void
    {
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
    }
}; ?>

<div
    x-data="{ open: false }"
    x-on:open-hotkeys-help.window="open = true"
    x-on:keydown.escape.window="open = false"
>
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        style="display: none;"
    >
        <!-- Backdrop -->
        <div
            class="absolute inset-0 bg-black/50"
            x-on:click="open = false"
        ></div>

        <!-- Modal -->
        <div class="relative z-10 w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Keyboard Shortcuts') }}</flux:heading>
                <flux:button variant="ghost" icon="x-mark" size="sm" x-on:click="open = false" />
            </div>

            <div class="space-y-4 max-h-[70vh] overflow-y-auto">
                {{-- Create --}}
                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('Create') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['F1', __('New Customer')],
                            ['F2', __('New Delivery Note')],
                        ] as [$shortcut, $description])
                            <div class="flex items-center justify-between rounded px-2 py-1">
                                <flux:text class="text-sm">{{ $description }}</flux:text>
                                <kbd class="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:border-zinc-600 dark:bg-zinc-800">{{ $shortcut }}</kbd>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Navigate --}}
                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('Navigate') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['F3', __('Dashboard')],
                            ['F4', __('Customers')],
                            ['F5', __('Delivery Notes')],
                            ['F6', __('Invoices')],
                            ['F10', __('Profile Settings')],
                            ['F11', __('CRM Settings (admin)')],
                        ] as [$shortcut, $description])
                            <div class="flex items-center justify-between rounded px-2 py-1">
                                <flux:text class="text-sm">{{ $description }}</flux:text>
                                <kbd class="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:border-zinc-600 dark:bg-zinc-800">{{ $shortcut }}</kbd>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Page Actions --}}
                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('Page Actions') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['F7', __('Convert DN to Invoice (DN page)')],
                            ['F8', __('Email Delivery Note (DN page)')],
                            ['F9', __('Email Invoice (Invoice page)')],
                            ['F12', __('Logout')],
                        ] as [$shortcut, $description])
                            <div class="flex items-center justify-between rounded px-2 py-1">
                                <flux:text class="text-sm">{{ $description }}</flux:text>
                                <kbd class="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:border-zinc-600 dark:bg-zinc-800">{{ $shortcut }}</kbd>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- List Navigation --}}
                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('List Navigation') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['j / ↓', __('Select next row')],
                            ['k / ↑', __('Select previous row')],
                            ['Enter', __('View selected item')],
                            ['e', __('Edit selected item')],
                            ['m', __('Email selected item')],
                            ['c', __('Convert selected DN')],
                            ['d', __('Delete selected item')],
                            ['y / n', __('Confirm / cancel modal')],
                            ['Esc', __('Deselect row')],
                        ] as [$shortcut, $description])
                            <div class="flex items-center justify-between rounded px-2 py-1">
                                <flux:text class="text-sm">{{ $description }}</flux:text>
                                <kbd class="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:border-zinc-600 dark:bg-zinc-800">{{ $shortcut }}</kbd>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Other --}}
                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('Other') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['?', __('Show this help')],
                            ['\\', __('Toggle shortcut labels')],
                        ] as [$shortcut, $description])
                            <div class="flex items-center justify-between rounded px-2 py-1">
                                <flux:text class="text-sm">{{ $description }}</flux:text>
                                <kbd class="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:border-zinc-600 dark:bg-zinc-800">{{ $shortcut }}</kbd>
                            </div>
                        @endforeach
                    </div>
                </div>

                <flux:text class="text-xs text-zinc-400">
                    {{ __('Shortcuts are disabled when typing in form fields.') }}
                </flux:text>
            </div>
        </div>
    </div>
</div>
