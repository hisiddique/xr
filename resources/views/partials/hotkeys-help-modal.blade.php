<flux:modal name="hotkeys-help" class="max-w-md">
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Keyboard Shortcuts') }}</flux:heading>

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

            {{-- List & View Actions --}}
            <div>
                <flux:text class="mb-2 text-xs font-semibold uppercase text-zinc-500">{{ __('List & View Actions') }}</flux:text>
                <div class="space-y-1">
                    @foreach([
                        ['j / ↓', __('Select next row (list)')],
                        ['k / ↑', __('Select previous row (list)')],
                        ['Enter', __('View selected item (list)')],
                        ['e', __('Edit (list row / view page)')],
                        ['m', __('Email selected item (list)')],
                        ['c', __('Convert selected DN (list)')],
                        ['d', __('Delete (list row / view page)')],
                        ['p', __('View PDF (view page)')],
                        ['⇧P', __('Download PDF (view page)')],
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
                        ['Backspace', __('Go back')],
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

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
