<?php

use App\Models\User;
use App\UserRole;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            }))
            ->when($this->role, fn ($q) => $q->where('role', $this->role))
            ->latest()
            ->paginate(15);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Users"
        subtitle="Manage admin and staff accounts."
    >
        <x-slot:action>
            @can('role-index')
                <flux:button variant="ghost" icon="shield-check" :href="route('roles.index')" wire:navigate>
                    Roles
                </flux:button>
            @endcan
            <flux:button variant="primary" icon="plus" :href="route('users.create')" wire:navigate>
                New User
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex-1 max-w-sm">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by name or email…')"
                    clearable
                    class="flex-1 max-w-sm"
                />
            </div>

            <div x-data="zoneNav('filters')" data-zone="filters" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex flex-wrap gap-1.5">
                @foreach(['' => 'All', 'admin' => 'Admins', 'staff' => 'Staff'] as $val => $label)
                    <button
                        type="button"
                        wire:click="$set('role', '{{ $val }}')"
                        data-filter-pill
                        @class([
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                            'bg-indigo-600 text-white shadow-sm' => $role === $val,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/15' => $role !== $val,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->users->isEmpty())
            <x-ui.empty-state
                icon="users"
                title="No users found"
                :description="($search || $role) ? 'Try adjusting your search or filter.' : 'Create the first user to get started.'"
            >
                @unless($search || $role)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('users.create')" wire:navigate>
                            New User
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg" x-data="zoneNav('table')" data-zone="table" tabindex="-1">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Email</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Role</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Created</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->users as $user)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-edit-url="{{ route('users.edit', $user) }}"
                                @if($user->id !== auth()->id()) data-delete-modal="delete-user-{{ $user->id }}" @endif
                                class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5"
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$user->name" size="sm" />
                                        <div>
                                            <span class="font-semibold text-zinc-900 dark:text-white"><x-ui.highlight :text="$user->name" :term="$search" /></span>
                                            @if($user->id === auth()->id())
                                                <span class="ml-1.5 inline-flex rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400">You</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400"><x-ui.highlight :text="$user->email" :term="$search" /></td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $user->role->ringColor() }}">
                                            {{ ucfirst($user->role->value) }}
                                        </span>
                                        @unless($user->status === \App\UserStatus::Active)
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $user->status->ringColor() }}">
                                                {{ $user->status->label() }}
                                            </span>
                                        @endunless
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-zinc-500 dark:text-zinc-400">{{ $user->created_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('users.edit', $user)" wire:navigate data-row-action="edit" />
                                        @if($user->id !== auth()->id())
                                            <livewire:pages::users.delete-modal :user="$user" :key="'delete-'.$user->id" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-100 px-4 py-2 dark:border-white/[0.06] outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30" x-data="zoneNav('pagination')" data-zone="pagination" tabindex="-1">
                {{ $this->users->links() }}
            </div>
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

</div>
