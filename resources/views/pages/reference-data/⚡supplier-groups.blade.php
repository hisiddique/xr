<?php

use App\Models\Supplier;
use App\Models\SupplierGroup;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supplier Groups')] class extends Component {
    public string $newGroupName = '';

    public string $newGroupDescription = '';

    public ?int $selectedGroupId = null;

    public ?int $editingGroupId = null;

    public string $editingGroupName = '';

    public string $editingGroupDescription = '';

    public ?int $deletingGroupId = null;

    public array $selectedMemberIds = [];

    public array $removingMemberIds = [];

    public function addGroup(): void
    {
        $this->newGroupName = trim($this->newGroupName);

        $this->validate([
            'newGroupName' => 'required|string|max:80|unique:supplier_groups,name',
            'newGroupDescription' => 'nullable|string|max:255',
        ]);

        SupplierGroup::create([
            'name' => $this->newGroupName,
            'description' => $this->newGroupDescription ?: null,
        ]);

        $this->newGroupName = '';
        $this->newGroupDescription = '';

        Flux::modal('create-group')->close();
        Flux::toast(variant: 'success', text: __('Group created.'));
    }

    public function startEdit(int $id): void
    {
        $group = SupplierGroup::findOrFail($id);
        $this->editingGroupId = $group->id;
        $this->editingGroupName = $group->name;
        $this->editingGroupDescription = (string) $group->description;
    }

    public function updateGroup(): void
    {
        if (! $this->editingGroupId) {
            return;
        }

        $this->validate([
            'editingGroupName' => 'required|string|max:80|unique:supplier_groups,name,'.$this->editingGroupId,
            'editingGroupDescription' => 'nullable|string|max:255',
        ]);

        SupplierGroup::findOrFail($this->editingGroupId)->update([
            'name' => trim($this->editingGroupName),
            'description' => $this->editingGroupDescription ?: null,
        ]);

        Flux::modal('edit-group')->close();
        Flux::toast(variant: 'success', text: __('Group updated.'));
    }

    public function deleteGroup(): void
    {
        if (! $this->deletingGroupId) {
            return;
        }

        SupplierGroup::findOrFail($this->deletingGroupId)->delete();

        if ($this->selectedGroupId === $this->deletingGroupId) {
            $this->selectedGroupId = null;
        }

        $this->deletingGroupId = null;
        Flux::modal('delete-group')->close();
        Flux::toast(variant: 'success', text: __('Group deleted.'));
    }

    public function selectGroup(int $id): void
    {
        $this->selectedGroupId = $id;
        $this->selectedMemberIds = [];
    }

    public function addMembers(array $ids): void
    {
        if (! $this->selectedGroupId) {
            return;
        }

        $ids = array_values(array_filter($ids, fn ($id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false));

        if ($ids === []) {
            return;
        }

        SupplierGroup::find($this->selectedGroupId)?->suppliers()->syncWithoutDetaching($ids);

        Flux::modal('add-members')->close();
        Flux::toast(variant: 'success', text: __(':n supplier(s) added to the group.', ['n' => count($ids)]));
    }

    public function startBulkRemove(): void
    {
        $this->removingMemberIds = $this->selectedMemberIds;
    }

    public function removeMembers(): void
    {
        if ($this->removingMemberIds === []) {
            return;
        }

        SupplierGroup::find($this->selectedGroupId)?->suppliers()->detach($this->removingMemberIds);

        $this->selectedMemberIds = array_values(array_diff($this->selectedMemberIds, $this->removingMemberIds));
        $this->removingMemberIds = [];

        Flux::modal('remove-members')->close();
        Flux::toast(variant: 'success', text: __('Removed from group.'));
    }

    #[Computed]
    public function groups()
    {
        return SupplierGroup::withCount('suppliers')->orderBy('name')->get();
    }

    #[Computed]
    public function selectedGroup(): ?SupplierGroup
    {
        return $this->selectedGroupId ? SupplierGroup::find($this->selectedGroupId) : null;
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->selectedGroup?->suppliers()->orderBy('company_name')->get() ?? collect();
    }

    #[Computed]
    public function nonMembersForPicker(): array
    {
        if (! $this->selectedGroup) {
            return [];
        }

        return Supplier::query()
            ->when($this->selectedGroup, fn ($q) => $q->whereNotIn('id', $this->selectedGroup->suppliers()->pluck('suppliers.id')))
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'reference'])
            ->map(fn ($s) => ['id' => (string) $s->id, 'label' => $s->company_name, 'ref' => (string) $s->reference])
            ->all();
    }

    #[Computed]
    public function editingGroup(): ?SupplierGroup
    {
        return $this->editingGroupId ? SupplierGroup::find($this->editingGroupId) : null;
    }

    #[Computed]
    public function deletingGroup(): ?SupplierGroup
    {
        return $this->deletingGroupId ? SupplierGroup::find($this->deletingGroupId) : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Supplier Groups"
        subtitle="Mailing lists of suppliers for statement dispatch"
    />

    <div class="grid gap-8 lg:grid-cols-2">

        {{-- Groups --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-2 border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Supplier Groups</h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Groups used to dispatch statements to suppliers</p>
                </div>
                <flux:button size="sm" variant="primary" icon="plus" x-on:click="$flux.modal('create-group').show()">New group</flux:button>
            </div>
            @if($this->groups->isEmpty())
                <x-ui.empty-state icon="user-group" title="No groups yet" description="Create your first supplier group." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Members</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->groups as $group)
                            <tr @class(['bg-indigo-50 dark:bg-indigo-500/10' => $selectedGroupId === $group->id])>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">
                                    {{ $group->name }}
                                    @if($group->description)
                                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ $group->description }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3">
                                    <flux:badge size="sm">{{ $group->suppliers_count }}</flux:badge>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <div class="flex justify-end gap-1">
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="users"
                                            wire:click="selectGroup({{ $group->id }})"
                                        >Manage members</flux:button>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="pencil-square"
                                            wire:click="startEdit({{ $group->id }})"
                                            x-on:click="$flux.modal('edit-group').show()"
                                        />
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="$set('deletingGroupId', {{ $group->id }})"
                                            x-on:click="$flux.modal('delete-group').show()"
                                            class="text-rose-500 hover:text-rose-600"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Members --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                @if($this->selectedGroup)
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Members — {{ $this->selectedGroup->name }}</h2>
                    @if($this->selectedGroup->description)
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->selectedGroup->description }}</p>
                    @endif
                @else
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Members</h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Select a group to manage its members.</p>
                @endif
            </div>

            @if($this->selectedGroup)
                <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-6 py-3 dark:border-white/[0.06]">
                    <flux:button size="sm" variant="primary" icon="plus" x-on:click="$flux.modal('add-members').show()">Add members</flux:button>
                    @if(count($selectedMemberIds))
                        <flux:button
                            size="sm"
                            variant="danger"
                            icon="trash"
                            wire:click="startBulkRemove"
                            x-on:click="$flux.modal('remove-members').show()"
                        >Remove selected ({{ count($selectedMemberIds) }})</flux:button>
                    @endif
                </div>

                @if($this->members->isEmpty())
                    <div class="px-6 py-4 text-sm text-zinc-500 dark:text-zinc-400">No suppliers in this group yet.</div>
                @else
                    <ul class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->members as $member)
                            <li class="flex items-center gap-3 px-6 py-3">
                                <flux:checkbox wire:model.live="selectedMemberIds" :value="(string) $member->id" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-zinc-900 dark:text-white">{{ $member->company_name }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $member->reference }}</p>
                                </div>
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="x-mark"
                                    wire:click="$set('removingMemberIds', [{{ $member->id }}])"
                                    x-on:click="$flux.modal('remove-members').show()"
                                />
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>

    </div>

    {{-- Modals --}}
    <flux:modal name="create-group" focusable class="max-w-sm" @close="$wire.set('newGroupName', ''); $wire.set('newGroupDescription', '')">
        <form wire:submit="addGroup" class="space-y-4">
            <flux:heading>{{ __('New supplier group') }}</flux:heading>
            <div>
                <flux:input wire:model="newGroupName" :label="__('Name')" maxlength="80" />
                <flux:error name="newGroupName" />
            </div>
            <div>
                <flux:input wire:model="newGroupDescription" :label="__('Description')" maxlength="255" />
                <flux:error name="newGroupDescription" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="edit-group" focusable class="max-w-sm" @close="$wire.set('editingGroupId', null)">
        <form wire:submit="updateGroup" class="space-y-4">
            <flux:heading>
                @if($this->editingGroup)
                    {{ __('Edit ":name"', ['name' => $this->editingGroup->name]) }}
                @endif
            </flux:heading>
            <div>
                <flux:input wire:model="editingGroupName" :label="__('Name')" maxlength="80" />
                <flux:error name="editingGroupName" />
            </div>
            <div>
                <flux:input wire:model="editingGroupDescription" :label="__('Description')" maxlength="255" />
                <flux:error name="editingGroupDescription" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-group" focusable class="max-w-sm" @close="$wire.set('deletingGroupId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingGroup)
                    {{ __('Delete ":name"? Members are detached, suppliers are not deleted.', ['name' => $this->deletingGroup->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteGroup">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="add-members" focusable class="max-w-lg">
        <div
            wire:key="member-picker-{{ $selectedGroupId }}-{{ $this->members->count() }}"
            x-data="groupMemberPicker(@js($this->nonMembersForPicker))"
            class="space-y-4"
        >
            <flux:heading>{{ __('Add suppliers to :group', ['group' => $this->selectedGroup?->name]) }}</flux:heading>

            <flux:input x-model="search" type="search" icon="magnifying-glass" :placeholder="__('Filter by name or reference…')" />

            <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <button type="button" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" x-on:click="toggleAll()" x-text="allFilteredSelected ? '{{ __('Clear visible') }}' : '{{ __('Select visible') }}'"></button>
                <span x-text="`${selected.length} {{ __('selected') }}`"></span>
            </div>

            <div class="max-h-80 divide-y divide-zinc-100 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-white/[0.06] dark:border-white/10">
                <template x-for="row in filtered" :key="row.id">
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                        <input type="checkbox" :value="row.id" x-model="selected" class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-zinc-800" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-zinc-900 dark:text-white" x-text="row.label"></span>
                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400" x-text="row.ref"></span>
                        </span>
                    </label>
                </template>
                <p x-show="filtered.length === 0" class="px-3 py-4 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No matching suppliers.') }}</p>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="button" x-bind:disabled="selected.length === 0" x-on:click="$wire.addMembers(selected)">
                    <span x-text="selected.length ? `{{ __('Apply') }} (${selected.length})` : '{{ __('Apply') }}'"></span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="remove-members" focusable class="max-w-sm" @close="$wire.set('removingMemberIds', [])">
        <div class="space-y-4">
            <flux:heading>{{ __('Remove :n member(s) from ":group"?', ['n' => count($removingMemberIds), 'group' => $this->selectedGroup?->name]) }}</flux:heading>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('The suppliers are not deleted — only their membership.') }}</p>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="removeMembers">Remove</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
