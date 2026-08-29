<?php

use App\Models\Role;
use App\Models\User;
use App\UserRole;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit User')] class extends Component {
    public User $user;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = 'staff';

    public array $roleIds = [];

    public function mount(): void
    {
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->role = $this->user->role->value;
        $this->roleIds = $this->user->roles->pluck('id')->all();
    }

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
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'in:admin,staff'],
            'roleIds' => 'array',
            'roleIds.*' => 'integer|exists:roles,id',
        ]);

        $isSelf = $this->user->id === auth()->id();
        $wasAdmin = $this->user->role === UserRole::Admin;
        $becomingStaff = $this->role !== 'admin';

        if ($isSelf && $wasAdmin && $becomingStaff) {
            $this->addError('role', __('You cannot remove admin rights from your own account.'));

            return;
        }

        if ($wasAdmin && $becomingStaff && User::where('role', 'admin')->count() <= 1) {
            $this->addError('role', __('At least one admin must remain.'));

            return;
        }

        $sysadminRole = Role::whereSlug('sysadmin')->first();
        $sysadminId = $sysadminRole?->id;
        $hadSysadmin = $sysadminId !== null && $this->user->roles->contains('id', $sysadminId);
        $keepingSysadmin = $sysadminId !== null && in_array($sysadminId, array_map('intval', $this->roleIds), true);

        if ($isSelf && $hadSysadmin && ! $keepingSysadmin) {
            $this->addError('roleIds', __('You cannot remove your own System Administrator role.'));

            return;
        }

        if ($hadSysadmin && ! $keepingSysadmin && ($sysadminRole?->users()->count() ?? 0) <= 1) {
            $this->addError('roleIds', __('At least one user must remain a System Administrator.'));

            return;
        }

        $this->user->update([
            'name' => $this->name,
            'email' => strtolower($this->email),
            'role' => UserRole::from($this->role),
            ...($this->password ? ['password' => Hash::make($this->password)] : []),
        ]);

        $this->user->roles()->sync($this->roleIds);

        $this->password = '';
        $this->password_confirmation = '';

        Flux::toast(variant: 'success', text: __('User updated.'));

        $this->redirect(route('users.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        :title="'Edit: '.$user->name"
        subtitle="Update account details, role, or reset the password."
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
                <flux:input wire:model="name" :label="__('Full Name')" required />
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
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Reset Password</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Leave blank to keep the current password.') }}</p>
            </div>
            <div class="space-y-4 p-4">
                <flux:input wire:model="password" type="password" :label="__('New Password')" viewable />
                <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm New Password')" viewable />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <x-ui.back-button :fallback="route('users.index')" />
            <flux:button variant="primary" type="submit">Save Changes</flux:button>
        </div>
    </form>

</div>
