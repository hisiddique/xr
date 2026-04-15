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

            <div class="space-y-4">
                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('Navigation') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['g then c', __('Go to Customers')],
                            ['g then d', __('Go to Delivery Notes')],
                            ['g then i', __('Go to Invoices')],
                        ] as [$shortcut, $description])
                            <div class="flex items-center justify-between rounded px-2 py-1">
                                <flux:text class="text-sm">{{ $description }}</flux:text>
                                <div class="flex items-center gap-1">
                                    @foreach(explode(' then ', $shortcut) as $key)
                                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:border-zinc-600 dark:bg-zinc-800">{{ $key }}</kbd>
                                        @if(!$loop->last)
                                            <span class="text-xs text-zinc-400">then</span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('Actions') }}</flux:text>
                    <div class="space-y-1">
                        @foreach([
                            ['n', __('New item (context-sensitive)')],
                            ['?', __('Show this help')],
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
