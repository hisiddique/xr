<div x-data x-cloak>
    {{-- Tab cycle reminder — fixed bottom-right corner --}}
    <div
        class="fixed bottom-3 right-3 z-30 flex items-center gap-2 rounded-full border border-zinc-200/70 bg-white/90 px-3 py-1.5 text-[11px] font-medium text-zinc-600 shadow-sm backdrop-blur dark:border-white/10 dark:bg-zinc-900/90 dark:text-zinc-400"
        x-show="$store.hotkeys.currentZone"
    >
        <span><kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">Shift+Tab</kbd> ←</span>
        <span class="font-semibold capitalize text-indigo-600 dark:text-indigo-400" x-text="$store.hotkeys.currentZone"></span>
        <span>→ <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">Tab</kbd></span>
    </div>

    {{-- Pagination key reminder — only when pagination zone is focused --}}
    <div
        class="fixed bottom-14 right-3 z-30 rounded-full border border-zinc-200/70 bg-white/90 px-3 py-1.5 text-[11px] font-medium text-zinc-600 shadow-sm backdrop-blur dark:border-white/10 dark:bg-zinc-900/90 dark:text-zinc-400"
        x-show="$store.hotkeys.currentZone === 'pagination'"
    >
        <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">PgDn</kbd>
        next ·
        <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">PgUp</kbd>
        prev
    </div>

    {{-- Type-to-search reminder — when on search zone --}}
    <div
        class="fixed bottom-14 right-3 z-30 rounded-full border border-zinc-200/70 bg-white/90 px-3 py-1.5 text-[11px] font-medium text-zinc-600 shadow-sm backdrop-blur dark:border-white/10 dark:bg-zinc-900/90 dark:text-zinc-400"
        x-show="$store.hotkeys.currentZone === 'search'"
    >
        Type to filter ·
        <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">↓</kbd>
        to list
    </div>

    {{-- Activate hint — on table zone with a row selected --}}
    <div
        class="fixed bottom-14 right-3 z-30 rounded-full border border-zinc-200/70 bg-white/90 px-3 py-1.5 text-[11px] font-medium text-zinc-600 shadow-sm backdrop-blur dark:border-white/10 dark:bg-zinc-900/90 dark:text-zinc-400"
        x-show="$store.hotkeys.currentZone === 'table' && $store.hotkeys.selectedRow >= 0"
    >
        <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">←/→</kbd>
        action ·
        <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">↵</kbd>
        activate
    </div>

    {{-- Press ? for help — always-visible affordance --}}
    <div class="fixed bottom-3 left-3 z-30 rounded-full border border-zinc-200/70 bg-white/90 px-2.5 py-1 text-[11px] font-medium text-zinc-500 shadow-sm backdrop-blur dark:border-white/10 dark:bg-zinc-900/90 dark:text-zinc-400">
        Press <kbd class="rounded border border-zinc-300 bg-zinc-50 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-700 dark:bg-zinc-800">?</kbd> for shortcuts
    </div>
</div>
