@props([
    'title' => '',
    'subtitle' => '',
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between']) }}>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white md:text-3xl">
            {{ $title }}
        </h1>
        @if($subtitle)
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 leading-relaxed">{{ $subtitle }}</p>
        @endif
    </div>

    @if(isset($action))
        <div class="mt-3 shrink-0 sm:mt-0">
            {{ $action }}
        </div>
    @endif
</div>
