@props([
    'icon' => 'inbox',
    'title' => 'Nothing here yet',
    'description' => '',
])

<div class="flex flex-col items-center justify-center py-16 text-center">
    {{-- Illustration circle --}}
    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
        <flux:icon :icon="$icon" class="size-8 text-zinc-400 dark:text-zinc-500" />
    </div>

    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</h3>

    @if($description)
        <p class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400 leading-relaxed">{{ $description }}</p>
    @endif

    @if(isset($action))
        <div class="mt-6">
            {{ $action }}
        </div>
    @endif
</div>
