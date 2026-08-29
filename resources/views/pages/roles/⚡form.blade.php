<?php

use App\Models\Role;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Role')] class extends Component {
    public ?Role $role = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public array $permissions = [];

    public function mount(): void
    {
        $this->authorize($this->role ? 'role-edit' : 'role-create');

        if ($this->role) {
            if ($this->role->slug === 'sysadmin') {
                Flux::toast(variant: 'warning', text: __('The System Administrator role is managed by the system and cannot be edited.'));
                $this->redirectRoute('roles.index', navigate: true);

                return;
            }

            $this->name = $this->role->name;
            $this->slug = $this->role->slug;
            $this->description = (string) $this->role->description;
            $this->permissions = $this->role->permissionKeys()->all();
        }
    }

    public function save(): void
    {
        $this->authorize($this->role ? 'role-edit' : 'role-create');

        $rules = [
            'description' => 'nullable|string|max:255',
            'permissions' => 'array',
        ];

        if (! $this->role?->is_system) {
            $rules['name'] = 'required|string|max:50';
            $rules['slug'] = ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('roles', 'slug')->ignore($this->role?->id)];
        }

        $this->validate($rules);

        $data = ['description' => $this->description ?: null];

        if (! $this->role?->is_system) {
            $data['name'] = $this->name;
            $data['slug'] = $this->slug;
        }

        $role = $this->role
            ? tap($this->role)->update($data)
            : Role::create($data + ['is_system' => false]);

        $role->syncPermissions($this->permissions);

        Flux::toast(variant: 'success', text: __('Role saved.'));
        $this->redirectRoute('roles.index', navigate: true);
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string, modules: array<int, array{module: string, label: string, keys: array<int, string>, actions: array<int, array{key: string, label: string}>}>}>
     */
    #[Computed]
    public function catalog(): array
    {
        return collect(config('permissions'))->map(fn ($group, $key) => [
            'key' => $key,
            'label' => $group['label'],
            'icon' => $group['icon'],
            'modules' => collect($group['functions'])->map(fn ($fn, $module) => [
                'module' => $module,
                'label' => $fn['label'],
                'keys' => array_map(fn ($a) => "{$module}-{$a}", $fn['actions']),
                'actions' => array_map(fn ($a) => [
                    'key' => "{$module}-{$a}",
                    'label' => Str::headline($a),
                ], $fn['actions']),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array{catalog: array<int, mixed>, selected: array<int, string>, locked: bool}
     */
    #[Computed]
    public function matrixConfig(): array
    {
        return [
            'catalog' => $this->catalog,
            'selected' => $this->permissions,
            'locked' => false,
        ];
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        :title="$role ? 'Edit: '.$role->name : 'New Role'"
        subtitle="Name the role and choose the permissions it grants."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('roles.index')" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <form
        wire:submit="save"
        x-data="roleMatrix(@js($this->matrixConfig))"
        x-on:submit="$wire.set('permissions', Array.from(selectedSet), false)"
        class="flex flex-col gap-4"
    >

        {{-- Details --}}
        <div class="max-w-2xl overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Details</h2>
            </div>
            <div class="space-y-4 p-4">
                @if($role?->is_system)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">System role — name and slug are locked.</p>
                @endif
                <flux:input wire:model="name" :label="__('Name')" :readonly="(bool) $role?->is_system" required autofocus />
                <flux:input wire:model="slug" :label="__('Slug')" :readonly="(bool) $role?->is_system" :placeholder="__('e.g. warehouse_manager')" required />
                <flux:textarea wire:model="description" :label="__('Description')" rows="2" />
            </div>
        </div>

        {{-- Permissions --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Permissions</h2>
                <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="count() + ' selected'"></span>
            </div>

            <div class="space-y-3 p-4">
                @foreach($this->catalog as $group)
                    <div class="overflow-hidden rounded-xl border border-zinc-200/70 dark:border-white/10">
                        <label class="flex items-center gap-2 border-b border-zinc-200/70 bg-zinc-50 px-3 py-2.5 dark:border-white/10 dark:bg-zinc-800/40">
                            <input
                                type="checkbox"
                                class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-900"
                                :checked="groupState(@js($group)) === 'all'"
                                x-effect="$el.indeterminate = groupState(@js($group)) === 'some'"
                                @change="toggleGroup(@js($group))"
                            >
                            <flux:icon :name="$group['icon']" variant="micro" class="text-zinc-400" />
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $group['label'] }}</span>
                        </label>

                        <div class="divide-y divide-zinc-200/70 dark:divide-white/10">
                            @foreach($group['modules'] as $module)
                                <div class="px-3 py-2.5">
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-900"
                                            :checked="moduleState(@js($module)) === 'all'"
                                            x-effect="$el.indeterminate = moduleState(@js($module)) === 'some'"
                                            @change="toggleModule(@js($module))"
                                        >
                                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $module['label'] }}</span>
                                    </label>

                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2 pl-6">
                                        @foreach($module['actions'] as $action)
                                            <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                                <input
                                                    type="checkbox"
                                                    class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-900"
                                                    :checked="isChecked(@js($action['key']))"
                                                    @change="toggle(@js($action['key']))"
                                                >
                                                {{ $action['label'] }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <x-ui.back-button :fallback="route('roles.index')" />
            <flux:button variant="primary" type="submit">Save Role</flux:button>
        </div>
    </form>

</div>
