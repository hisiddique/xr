@aware(['perPage' => 25])
<div {{ $attributes->merge(['class' => '']) }} x-data="perPageSelect()">
    <select
        wire:model.live="perPage"
        x-on:change="onChange($event.target.value)"
        class="rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm text-zinc-700 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
    >
        @foreach([25, 50, 100, 250, 500, 1000] as $n)
            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} per page</option>
        @endforeach
    </select>
</div>
