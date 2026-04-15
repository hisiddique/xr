@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

<div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
    @if($title || isset($header))
        <div class="flex items-center justify-between border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
            @if(isset($header))
                {{ $header }}
            @else
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                    @if($subtitle)
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div @class(['p-6' => $padding])>
        {{ $slot }}
    </div>
</div>
