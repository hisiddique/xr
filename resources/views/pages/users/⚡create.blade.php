<?php

use App\Models\Role;
use App\Models\User;
use App\UserRole;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New User')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = 'staff';

    public array $roleIds = [];

    #[Computed]
    public function assignableRoles()
    {
        return Role::orderByDesc('is_system')->orderBy('name')->get()
            ->reject(fn ($r) => $r->slug === 'sysadmin' && ! auth()->user()->hasRole('sysadmin'))
            ->values();
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'in:admin,staff'],
            'roleIds' => 'array',
            'roleIds.*' => 'integer|exists:roles,id',
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => strtolower($this->email),
            'password' => Hash::make($this->password),
            'role' => UserRole::from($this->role),
        ]);

        $user->roles()->sync($this->roleIds);

        Flux::toast(variant: 'success', text: __('User :name created.', ['name' => $this->name]));

        $this->redirect(route('users.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="New User"
        subtitle="Invite a new admin or staff member."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('users.index')" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <form wire:submit="save" x-data="formNav" x-on:keydown="handleKey($event)" class="flex flex-col gap-4 max-w-2xl">

        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Profile</h2>
            </div>
            <div class="space-y-4 p-4">
                <flux:input wire:model="name" :label="__('Full Name')" required autofocus />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />

                <div>
                    <flux:label>{{ __('Role') }} <span class="text-rose-500">*</span></flux:label>
                    <flux:select wire:model="role">
                        <flux:select.option value="staff">{{ __('Staff') }}</flux:select.option>
                        <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
                    </flux:select>
                    <flux:error name="role" />
                </div>

                <div>
                    <flux:label>{{ __('Assigned Roles') }}</flux:label>
                    <div class="mt-1.5 flex flex-col gap-1.5 rounded-lg border border-zinc-200/70 p-3 dark:border-white/10">
                        @foreach($this->assignableRoles as $assignable)
                            <label class="flex items-center gap-2 text-sm">
                                <flux:checkbox wire:model="roleIds" value="{{ $assignable->id }}" />
                                <span class="text-zinc-800 dark:text-zinc-200">{{ $assignable->name }}</span>
                                @if($assignable->is_system)
                                    <flux:badge size="sm" color="zinc">System</flux:badge>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    <flux:error name="roleIds" />
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Password</h2>
            </div>
            <div class="space-y-4 p-4">
                <flux:input wire:model="password" type="password" :label="__('Password')" required viewable />
                <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm Password')" required viewable />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <x-ui.back-button :fallback="route('users.index')" />
            <flux:button variant="primary" type="submit">Create User</flux:button>
        </div>
    </form>

</div>
