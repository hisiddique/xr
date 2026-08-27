@php
    $groups = [
        [
            'title' => 'Navigation',
            'rows' => [
                ['keys' => ['↑', '↓'],          'desc' => 'Move between fields'],
                ['keys' => ['←', '→'],          'desc' => 'Move cursor / jump field at edge'],
            ],
        ],
        [
            'title' => 'Line items',
            'rows' => [
                ['keys' => ['Insert'],          'desc' => 'Insert line above'],
                ['keys' => ['Ctrl', 'Insert'],  'desc' => 'Insert line below'],
                ['keys' => ['Ctrl', 'Del'],     'desc' => 'Delete current line'],
                ['keys' => ['F9'],             'desc' => 'Add note row'],
            ],
        ],
        [
            'title' => 'Form',
            'rows' => [
                ['keys' => ['Ctrl', '↵'],       'desc' => 'Save & submit'],
                ['keys' => ['Esc'],             'desc' => 'Discard / save prompt'],
            ],
        ],
    ];
@endphp

<aside class="w-full shrink-0 self-start lg:w-64 lg:sticky lg:top-20">
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
        <div class="mb-3 flex items-center gap-2">
            <flux:icon.command-line class="size-4 text-indigo-500" />
            <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">Keyboard Shortcuts</h3>
        </div>

        <div class="space-y-4">
            @foreach($groups as $group)
                <div>
                    <p class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ $group['title'] }}</p>
                    <ul class="space-y-1.5">
                        @foreach($group['rows'] as $row)
                            <li class="flex items-center justify-between gap-2 text-xs">
                                <span class="text-zinc-600 dark:text-zinc-400">{{ $row['desc'] }}</span>
                                <span class="flex shrink-0 items-center gap-0.5">
                                    @foreach($row['keys'] as $i => $k)
                                        @if($i > 0)
                                            <span class="text-zinc-300 dark:text-zinc-600">+</span>
                                        @endif
                                        <kbd class="inline-flex h-5 min-w-5 items-center justify-center rounded border border-zinc-200 bg-zinc-50 px-1 font-mono text-[10px] font-semibold text-zinc-600 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-300">{{ $k }}</kbd>
                                    @endforeach
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</aside>
