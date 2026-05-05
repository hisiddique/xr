<flux:modal name="hotkeys-help" class="max-w-lg">
    <div class="space-y-5">
        <flux:heading size="lg">{{ __('Keyboard Shortcuts') }}</flux:heading>

        <div class="max-h-[70vh] space-y-5 overflow-y-auto">

            {{-- Navigate the page --}}
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Navigate the page') }}</p>
                <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Tab</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Move to next zone (Search → Filters → Table → Pagination)</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Shift+Tab</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Move to previous zone</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">↑ / ↓</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Move between rows</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">← / →</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Move between action icons (selected row) or filter pills</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Enter</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Activate focused item (row → View; icon → that action)</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Esc</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Step back (drop icon focus → deselect row → close)</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Letter / /</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Jump to search and start filtering</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">PgDn / PgUp</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Next / previous page</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Backspace</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Go back (browser history) when not in an input</span>
                </div>
            </div>

            {{-- Forms --}}
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Forms') }} <span class="normal-case font-normal text-zinc-400">(create / edit pages)</span></p>
                <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Tab</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Move to next field</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Enter</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Add a new line (in line-item table)</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Shift+Enter</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Add a note row</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Ctrl/⌘+Backspace</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Remove the focused line</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Ctrl/⌘+Enter</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Save the form</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">Esc</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Cancel and go back</span>
                </div>
            </div>

            {{-- Quick actions --}}
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Quick actions') }} <span class="normal-case font-normal text-zinc-400">(work everywhere, even inside form fields)</span></p>
                <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F1</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">New Customer</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F2</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">New Delivery Note</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F3</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Dashboard</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F4</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Customers</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F5</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Delivery Notes</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F6</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Invoices</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F7</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Convert DN (on DN show pages)</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F8</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Email DN</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F9</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Email Invoice</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F10</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Profile</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F11</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">CRM Settings <span class="text-zinc-400">(admin)</span></span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">F12</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Log out</span>
                </div>
            </div>

            {{-- Modals --}}
            <div>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Modals') }}</p>
                <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">y</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Confirm</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">n / Esc</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Cancel</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">?</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Show this cheat-sheet</span>

                    <kbd class="self-center whitespace-nowrap rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:border-zinc-600 dark:bg-zinc-800">\</kbd>
                    <span class="self-center text-sm text-zinc-700 dark:text-zinc-300">Toggle key labels in the topbar</span>
                </div>
            </div>

            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">Single-letter keys (e/m/c/d/p) have been retired. Use ←/→ to walk action icons instead.</p>

        </div>

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
