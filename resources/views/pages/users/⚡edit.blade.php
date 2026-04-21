<?php

use App\Models\User;
use App\UserRole;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit User')] class extends Component {
    public User $user;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = 'staff';

    public function mount(): void
    {
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->role = $this->user->role->value;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'in:admin,staff'],
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

        $this->user->update([
            'name' => $this->name,
            'email' => strtolower($this->email),
            'role' => UserRole::from($this->role),
            ...($this->password ? ['password' => Hash::make($this->password)] : []),
        ]);

        $this->password = '';
        $this->password_confirmation = '';

        Flux::toast(variant: 'success', text: __('User updated.'));

        $this->redirect(route('users.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-8">

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

    <form wire:submit="save" class="flex flex-col gap-6 max-w-2xl">

        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Account</p>
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Profile</h2>
            </div>
            <div class="space-y-4 p-6">
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
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Security</p>
                <h2 class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-white">Reset Password</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Leave blank to keep the current password.') }}</p>
            </div>
            <div class="space-y-4 p-6">
                <flux:input wire:model="password" type="password" :label="__('New Password')" viewable />
                <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm New Password')" viewable />
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white px-6 py-4 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-white/10 dark:bg-zinc-900">
            <flux:button variant="ghost" :href="route('users.index')" wire:navigate type="button">Cancel</flux:button>
            <flux:button variant="primary" type="submit">Save Changes</flux:button>
        </div>
    </form>

</div>
