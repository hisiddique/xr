<?php

use App\Models\Role;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Roles')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public ?int $deletingRoleId = null;

    public function mount(): void
    {
        $this->authorize('role-index');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->withCount('users')
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('slug', 'like', "%{$this->search}%");
            }))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function permissionCounts(): Collection
    {
        return DB::table('role_permission')
            ->select('role_id', DB::raw('count(*) as c'))
            ->groupBy('role_id')
            ->pluck('c', 'role_id');
    }

    #[Computed]
    public function deletingRole(): ?Role
    {
        return $this->deletingRoleId
            ? Role::find($this->deletingRoleId)
            : null;
    }

    public function deleteRole(): void
    {
        $this->authorize('role-delete');

        $role = Role::findOrFail($this->deletingRoleId);

        if ($role->is_system) {
            Flux::toast(variant: 'danger', text: __('System roles cannot be deleted.'));

            return;
        }

        if ($role->users()->exists()) {
            Flux::toast(variant: 'danger', text: __(':name is still assigned to :count user(s).', [
                'name' => $role->name,
                'count' => $role->users()->count(),
            ]));

            return;
        }

        $role->delete();
        $this->deletingRoleId = null;
        Flux::modal('delete-role')->close();
        Flux::toast(variant: 'success', text: __('Role deleted.'));
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Roles"
        subtitle="Define roles and the permissions each one grants."
    >
        <x-slot:action>
            @can('role-create')
            <flux:button variant="primary" icon="plus" :href="route('roles.create')" wire:navigate>
                New Role
            </flux:button>
            @endcan
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
                    :placeholder="__('Search by name or slug…')"
                    clearable
                    class="flex-1 max-w-sm"
                />
            </div>
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->roles->isEmpty())
            <x-ui.empty-state
                icon="shield-check"
                title="No roles found"
                :description="$search ? 'Try adjusting your search.' : 'Create the first role to get started.'"
            >
                @unless($search)
                    <x-slot:action>
                        @can('role-create')
                        <flux:button variant="primary" :href="route('roles.create')" wire:navigate>
                            New Role
                        </flux:button>
                        @endcan
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg" x-data="zoneNav('table')" data-zone="table" tabindex="-1">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Slug</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Users</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Permissions</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->roles as $role)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                @unless($role->slug === 'sysadmin') @can('role-edit') data-edit-url="{{ route('roles.edit', $role) }}" @endcan @endunless
                                @unless($role->is_system) @can('role-delete') data-delete-modal="delete-role" @endcan @endunless
                                class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5"
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-zinc-900 dark:text-white"><x-ui.highlight :text="$role->name" :term="$search" /></span>
                                        @if($role->is_system)
                                            <flux:badge size="sm" color="zinc">System</flux:badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs text-zinc-500 dark:text-zinc-400"><x-ui.highlight :text="$role->slug" :term="$search" /></td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $role->users_count }}</td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                    @if($role->is_system && ($this->permissionCounts[$role->id] ?? 0) === 0)
                                        All
                                    @else
                                        {{ $this->permissionCounts[$role->id] ?? 0 }}
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('role-edit')
                                        @if($role->slug === 'sysadmin')
                                            <flux:tooltip content="System-managed role — cannot be edited">
                                                <flux:button size="xs" variant="ghost" icon="lock-closed" disabled />
                                            </flux:tooltip>
                                        @else
                                            <flux:button size="xs" variant="ghost" icon="pencil" :href="route('roles.edit', $role)" wire:navigate data-row-action="edit" />
                                        @endif
                                        @endcan
                                        @unless($role->is_system)
                                            @can('role-delete')
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="$set('deletingRoleId', {{ $role->id }})"
                                                x-on:click="$flux.modal('delete-role').show()"
                                                class="text-rose-500 hover:text-rose-600"
                                            />
                                            @endcan
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-100 px-4 py-2 dark:border-white/[0.06] outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30" x-data="zoneNav('pagination')" data-zone="pagination" tabindex="-1">
                {{ $this->roles->links() }}
            </div>
        @endif
    </div>

    {{-- Modals --}}
    <flux:modal name="delete-role" focusable class="max-w-sm" @close="$wire.set('deletingRoleId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingRole)
                    {{ __('Delete ":name"?', ['name' => $this->deletingRole->name]) }}
                @endif
            </flux:heading>
            <flux:text>{{ __('This role and its permission assignments will be permanently removed.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteRole">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

</div>
